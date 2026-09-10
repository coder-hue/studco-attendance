<?php
declare(strict_types=1);

require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
allow_schoology_extension();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!current_user()) {
    json_response(['error' => 'Sign in required.'], 401);
}
$user = require_staff('officer', 'advisor');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'csrf' => csrf_token()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST required.'], 405);
}
verify_csrf();

$payload = json_decode((string) file_get_contents('php://input'), true);
$eventDate = is_array($payload) ? trim((string) ($payload['event_date'] ?? '')) : '';
$schoologyAssignment = is_array($payload) ? trim((string) ($payload['schoology_assignment_id'] ?? '')) : '';
$requestedTitle = is_array($payload) ? mb_substr(trim((string) ($payload['title'] ?? '')), 0, 160) : '';
$requestedEventId = is_array($payload) ? filter_var($payload['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($schoologyAssignment !== '' && !ctype_digit($schoologyAssignment)) {
    json_response(['error' => 'Invalid Schoology assignment.'], 422);
}

if ($requestedEventId) {
    if ($schoologyAssignment === '') {
        json_response(['error' => 'A Schoology assignment is required to link attendance.'], 422);
    }
    $event = db()->prepare('SELECT id, public_token FROM events WHERE id = ?');
    $event->execute([$requestedEventId]);
    $event = $event->fetch();
    if (!$event) {
        json_response(['error' => 'Attendance event not found.'], 404);
    }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE events SET schoology_assignment = NULL WHERE schoology_assignment = ? AND id <> ?')->execute([$schoologyAssignment, $requestedEventId]);
        db()->prepare('UPDATE events SET schoology_assignment = ? WHERE id = ?')->execute([$schoologyAssignment, $requestedEventId]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        json_response(['error' => 'The attendance event could not be linked.'], 500);
    }
    audit('schoology_linked', 'event', (int) $requestedEventId, ['source' => 'schoology_extension', 'schoology_assignment_id' => $schoologyAssignment]);
    json_response(['ok' => true, 'created' => false, 'event' => ['id' => (int) $event['id'], 'checkin_url' => base_url('/checkin?event='.(int) $event['id'].'&token='.$event['public_token'])]]);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    json_response(['error' => 'Choose a valid attendance date.'], 422);
}

if ($schoologyAssignment !== '') {
    $existing = db()->prepare('SELECT id, public_token FROM events WHERE schoology_assignment = ? ORDER BY id DESC LIMIT 1');
    $existing->execute([$schoologyAssignment]);
    if ($event = $existing->fetch()) {
        json_response(['ok' => true, 'created' => false, 'event' => ['id' => (int) $event['id'], 'checkin_url' => base_url('/checkin?event='.(int) $event['id'].'&token='.$event['public_token'])]]);
    }
}

$title = $requestedTitle !== '' ? $requestedTitle : 'Attendance — ' . date('M j, Y', strtotime($eventDate));
$openTime = '07:35:00';
$closeTime = '07:50:00';
$stmt = db()->prepare("INSERT INTO events (public_token,title,description,location,term,event_date,start_time,end_time,checkin_opens,checkin_closes,max_points,required,question,answer_hash,schoology_assignment,is_open,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$stmt->execute([bin2hex(random_bytes(16)), $title, null, null, 'Attendance', $eventDate, $openTime, $closeTime, $eventDate.' '.$openTime, $eventDate.' '.$closeTime, 2, 1, '', password_hash('', PASSWORD_DEFAULT), $schoologyAssignment ?: null, 2, $user['id']]);
$id = (int) db()->lastInsertId();
audit('created', 'event', $id, ['source' => 'schoology_extension', 'schoology_assignment_id' => $schoologyAssignment ?: null]);
$event = db()->prepare('SELECT public_token FROM events WHERE id = ?');
$event->execute([$id]);
$token = (string) $event->fetchColumn();
json_response(['ok' => true, 'created' => true, 'event' => ['id' => $id, 'checkin_url' => base_url('/checkin?event='.$id.'&token='.$token)]]);
