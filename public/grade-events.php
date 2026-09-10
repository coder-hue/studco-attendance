<?php
declare(strict_types=1);

require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
allow_schoology_extension();
if (!current_user()) {
    json_response(['error' => 'Sign in required.'], 401);
}
require_staff('officer', 'advisor');

$events = db()->query("SELECT id, title, event_date, schoology_assignment
    FROM events
    ORDER BY event_date DESC, id DESC")->fetchAll();

audit('attendance_links_opened','event',null,['source'=>'schoology_extension','event_count'=>count($events)]);

json_response(['events' => $events]);
