<?php
declare(strict_types=1);
require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';

$action = $_GET['action'] ?? '';
if ($action === 'members') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_response(['members' => []]);
    }
    $like = '%' . $q . '%';
    $stmt = db()->prepare("SELECT id, CONCAT(COALESCE(NULLIF(nickname, ''), first_name), ' ', last_name) name, grade
        FROM members WHERE active = 1 AND (first_name LIKE ? OR nickname LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR CONCAT(COALESCE(NULLIF(nickname, ''), first_name), ' ', last_name) LIKE ?)
        ORDER BY last_name, first_name LIMIT 8");
    $stmt->execute([$like, $like, $like, $like, $like]);
    json_response(['members' => $stmt->fetchAll()]);
}

if ($action === 'live') {
    require_staff('officer', 'advisor');
    $eventId = (int) ($_GET['event_id'] ?? 0);
    $stmt = db()->prepare("SELECT m.id, CONCAT(COALESCE(NULLIF(m.nickname, ''), m.first_name), ' ', m.last_name) name,
        CASE WHEN a.status = 'present' THEN 'present' ELSE 'missing' END status, a.points, a.check_in_time
        FROM members m LEFT JOIN attendance a ON a.member_id = m.id AND a.event_id = ?
        WHERE m.active = 1 ORDER BY FIELD(COALESCE(a.status, 'missing'), 'missing','late','present','excused','exempt','absent'), m.last_name, m.first_name");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    $counts = ['members' => count($rows), 'present' => 0, 'missing' => 0];
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) $counts[$row['status']]++;
    }
    json_response(['counts' => $counts, 'attendance' => $rows]);
}

json_response(['error' => 'Not found'], 404);
