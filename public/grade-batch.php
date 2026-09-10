<?php
declare(strict_types=1);

require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
allow_schoology_extension();
if (!current_user()) {
    json_response(['error' => 'Sign in required.'], 401);
}
require_staff('officer', 'advisor');

$eventId = (int)($_GET['event_id'] ?? 0);
$eventStmt = db()->prepare('SELECT id, title, event_date, max_points FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();
if (!$event) {
    http_response_code(404);
    exit('Attendance event not found.');
}

$students = db()->prepare("SELECT CONCAT(m.first_name, ' ', m.last_name) AS name, m.email, m.schoology_user_id, m.schoology_username,
    CASE WHEN a.status = 'present' THEN a.points ELSE NULL END AS score,
    CASE WHEN a.status = 'present' THEN 'present' ELSE 'no_submission' END AS status
    FROM members m
    LEFT JOIN attendance a ON a.member_id = m.id AND a.event_id = ?
    WHERE m.active = 1
    ORDER BY m.last_name, m.first_name");
$students->execute([$eventId]);

$batch = [
    'format' => 'stuco-attendance-grade-batch-v1',
    'event' => ['id' => (int)$event['id'], 'title' => $event['title'], 'date' => $event['event_date'], 'max_points' => (float)$event['max_points']],
    'students' => $students->fetchAll(),
];
audit('grade_batch_requested','event',$eventId,['source'=>'schoology_extension','event_title'=>$event['title'],'present_count'=>count(array_filter($batch['students'],static fn(array $student):bool=>$student['status']==='present'))]);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance-grade-batch-' . $eventId . '.json"');
echo json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
