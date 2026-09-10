<?php
declare(strict_types=1);
require __DIR__ . '/../app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
require APP_CODE_ROOT . '/layout.php';

// QR landing request: validate the event/token pair, then remove it from the address bar.
if (isset($_GET['event'], $_GET['token'])) {
    $eventId = ctype_digit((string)$_GET['event']) ? (int)$_GET['event'] : 0;
    $token = trim((string)$_GET['token']);
    $lookup = db()->prepare("SELECT id,attendance_mode,CASE WHEN early_public_token=? THEN 'early' ELSE 'full' END AS checkin_lane FROM events WHERE id=? AND (public_token=? OR early_public_token=?)");
    $lookup->execute([$token, $eventId, $token, $token]);
    $event = $lookup->fetch();
    if (!$event) {
        audit('invalid_qr_scan', 'event', $eventId > 0 ? $eventId : null, ['reason' => 'event_or_token_mismatch']);
        http_response_code(404);
        page_start('Invalid check-in link');
        echo '<section class="card hero-card member-shell center" style="margin-inline:auto"><h1>Invalid check-in link</h1><p class="muted">Please scan the QR code shown at the meeting.</p></section>';
        page_end();
        exit;
    }
    audit('qr_scanned', 'event', (int)$event['id'], ['source' => 'qr_code', 'lane' => $event['checkin_lane']]);
    $_SESSION['checkin_event_id'] = (int)$event['id'];
    $_SESSION['checkin_lane'] = (string)$event['checkin_lane'];
    redirect('/checkin');
}

$eventId = (int)($_SESSION['checkin_event_id'] ?? 0);
if (!$eventId) {
    http_response_code(404);
    page_start('Scan the QR code', 'checkin-confirmation');
    echo '<section class="card hero-card member-shell center"><div class="success-icon">□</div><h1>Scan the meeting QR code</h1><p class="muted">It opens the attendance page for the current event.</p></section>';
    page_end();
    exit;
}

$eventStmt = db()->prepare('SELECT * FROM events WHERE id = ?'); $eventStmt->execute([$eventId]); $event = $eventStmt->fetch();
if (!$event) { unset($_SESSION['checkin_event_id']); redirect('/checkin'); }
$deviceHash = checkin_device_hash();

$memberId = member_id_from_cookie();
if (!$memberId && isset($_GET['member_id']) && ctype_digit((string)$_GET['member_id'])) {
    $memberId = (int)$_GET['member_id'];
    setcookie('member_id',(string)$memberId,['expires'=>time()+31536000,'path'=>'/','secure'=>(getenv('APP_ENV') ?: 'production') === 'production','httponly'=>true,'samesite'=>'Lax']);
}
$checkinLane = ($event['attendance_mode'] ?? 'standard') === 'decorations' && ($_SESSION['checkin_lane'] ?? '') === 'early' ? 'early' : 'full';
$checkinPoints = event_lane_points($event, $checkinLane);
$isAvailable = event_lane_is_open($event, $checkinLane);

if (!$memberId) {
    page_start('Who are you?', 'checkin-confirmation'); ?>
    <section class="card hero-card member-shell" style="margin-inline:auto"><p class="eyebrow">First time on this phone</p><h1>Who are you?</h1><p class="muted">Start typing your name, then tap it once.</p><label>Your name<input data-member-search autocomplete="off" autofocus placeholder="Start typing your name..."></label><div class="search-results" data-member-results></div><form data-member-select-form action="/index.php" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="select_member"><input type="hidden" name="member_id"><input type="hidden" name="next" value="/checkin"></form></section>
    <script>const saved=localStorage.getItem('member_id');if(saved)location.replace('/checkin?member_id='+encodeURIComponent(saved));</script>
    <?php page_end('/assets/member.js'); exit;
}

$memberStmt = db()->prepare('SELECT * FROM members WHERE id = ? AND active = 1'); $memberStmt->execute([$memberId]); $member = $memberStmt->fetch();
if (!$member) { setcookie('member_id','',time()-3600,'/'); redirect('/checkin'); }
$attendanceStmt = db()->prepare('SELECT * FROM attendance WHERE member_id = ? AND event_id = ?');
$attendanceStmt->execute([$memberId,$event['id']]);
$attendance = $attendanceStmt->fetch();
$listFirstName = trim((string)($member['nickname'] ?? '')) ?: (string)$member['first_name'];
$confirmationFirstName = trim((string)($member['confirmation_nickname'] ?? '')) ?: $listFirstName;
$confirmationName = trim($confirmationFirstName . ' ' . (string)$member['last_name']);
$legalName = trim((string)$member['first_name'] . ' ' . (string)$member['last_name']);
$correctionCutoff = date('Y-m-d H:i:s',time()-120);
$danceShift = null;
$danceWindowState = null;
if (($event['attendance_mode'] ?? 'standard') === 'dance_shifts') {
    $shiftStmt=db()->prepare('SELECT shift_time FROM event_shifts WHERE event_id=? AND member_id=?');
    $shiftStmt->execute([$eventId,$memberId]);
    $shiftTime=$shiftStmt->fetchColumn();
    if ($shiftTime === false) {
        $isAvailable=false;
        $danceWindowState='unassigned';
    } else {
        $danceShift=new DateTimeImmutable($event['event_date'].' '.$shiftTime);
        $windowStart=$danceShift->modify('-10 minutes');
        $windowEnd=$danceShift->modify('+10 minutes');
        $now=new DateTimeImmutable();
        if ($now < $windowStart) {
            $isAvailable=false;
            $danceWindowState='early';
        } elseif ($now > $windowEnd) {
            $isAvailable=false;
            $danceWindowState='late';
        } elseif (!$isAvailable) {
            $danceWindowState='event_closed';
        } else {
            $danceWindowState='open';
        }
    }
}

// A later Full time scan upgrades an existing 6-point departure without creating a second record.
if ($isAvailable && $attendance && (float)$attendance['points'] < $checkinPoints) {
    $upgradedAt = date('Y-m-d H:i:s');
    db()->prepare("UPDATE attendance SET status='present',points=?,check_in_time=?,checkin_device_hash=? WHERE id=?")
        ->execute([$checkinPoints,$upgradedAt,$deviceHash,(int)$attendance['id']]);
    audit('attendance_upgraded','attendance',(int)$attendance['id'],[
        'event_id'=>$eventId,'event_title'=>$event['title'],'member_id'=>$memberId,'member_name'=>$legalName,
        'from_points'=>(float)$attendance['points'],'to_points'=>$checkinPoints,'lane'=>$checkinLane,'source'=>'student_qr'
    ]);
    $attendanceStmt->execute([$memberId,$eventId]);
    $attendance = $attendanceStmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'correct_identity') {
    verify_csrf();
    db()->beginTransaction();
    try {
        $correction = db()->prepare("SELECT a.id,a.check_in_time FROM attendance a JOIN checkin_device_events d ON d.device_hash=? AND d.event_id=a.event_id WHERE a.member_id=? AND a.event_id=? AND a.checkin_device_hash=? AND d.member_id=? AND d.correction_used=0 AND a.check_in_time>=? FOR UPDATE");
        $correction->execute([$deviceHash,$memberId,$eventId,$deviceHash,$memberId,$correctionCutoff]);
        $correctableAttendance = $isAvailable ? $correction->fetch() : false;
        if (!$correctableAttendance) {
            db()->rollBack();
            audit('identity_correction_denied','attendance',null,['event_id'=>$eventId,'member_id'=>$memberId,'member_name'=>$legalName,'reason'=>'expired_closed_or_not_this_device']);
            redirect('/checkin?correction=unavailable');
        }
        db()->prepare('DELETE FROM attendance WHERE id=? AND checkin_device_hash=?')->execute([(int)$correctableAttendance['id'],$deviceHash]);
        db()->prepare('UPDATE checkin_device_events SET member_id=NULL,correction_used=1 WHERE device_hash=? AND event_id=?')->execute([$deviceHash,$eventId]);
        db()->commit();
        audit('identity_corrected','attendance',(int)$correctableAttendance['id'],['event_id'=>$eventId,'previous_member_id'=>$memberId,'member_name'=>$legalName,'previous_check_in_time'=>$correctableAttendance['check_in_time']]);
        setcookie('member_id','',['expires'=>time()-3600,'path'=>'/','secure'=>(getenv('APP_ENV') ?: 'production') === 'production','httponly'=>true,'samesite'=>'Lax']);
        ?><!doctype html><meta charset="utf-8"><script>localStorage.removeItem('member_id');location.replace('/checkin?corrected=1');</script><?php
        exit;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        audit('identity_correction_failed','attendance',null,['event_id'=>$eventId,'member_id'=>$memberId,'member_name'=>$legalName]);
        redirect('/checkin?correction=unavailable');
    }
}

$blockedMemberName = '';
if ($isAvailable && !$attendance) {
    db()->beginTransaction();
    try {
        $bindingStmt = db()->prepare('SELECT * FROM checkin_device_events WHERE device_hash=? AND event_id=? FOR UPDATE');
        $bindingStmt->execute([$deviceHash,$eventId]);
        $binding = $bindingStmt->fetch();
        if (!$binding) {
            db()->prepare('INSERT INTO checkin_device_events(device_hash,event_id,member_id) VALUES(?,?,?)')->execute([$deviceHash,$eventId,$memberId]);
        } elseif ($binding['member_id'] === null && (int)$binding['correction_used'] === 1) {
            db()->prepare('UPDATE checkin_device_events SET member_id=? WHERE device_hash=? AND event_id=?')->execute([$memberId,$deviceHash,$eventId]);
        } elseif ((int)$binding['member_id'] !== $memberId) {
            $boundMember = db()->prepare("SELECT CONCAT(COALESCE(NULLIF(nickname,''),first_name),' ',last_name) FROM members WHERE id=?");
            $boundMember->execute([(int)$binding['member_id']]);
            $blockedMemberName = (string)($boundMember->fetchColumn() ?: 'another member');
        }
        if ($blockedMemberName === '') {
            $save = db()->prepare("INSERT IGNORE INTO attendance (member_id,event_id,status,points,check_in_time,checkin_device_hash) VALUES (?,?,'present',?,?,?)");
            $save->execute([$memberId,$eventId,$checkinPoints,date('Y-m-d H:i:s'),$deviceHash]);
            $wasRecorded = $save->rowCount() > 0;
        } else {
            $wasRecorded = false;
        }
        db()->commit();
        if ($blockedMemberName !== '') {
            audit('device_checkin_blocked','attendance',null,['event_id'=>$eventId,'attempted_member_id'=>$memberId,'member_name'=>$legalName,'already_bound_to'=>$blockedMemberName]);
        } elseif ($wasRecorded) {
            audit('attendance_recorded','attendance',null,['event_id'=>$eventId,'event_title'=>$event['title'],'member_id'=>$memberId,'member_name'=>$legalName,'status'=>'pressent','points'=>$checkinPoints,'lane'=>$checkinLane,'source'=>'student_qr']);
        }
        $attendanceStmt->execute([$memberId,$eventId]);
        $attendance = $attendanceStmt->fetch();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        audit('attendance_recording_failed','attendance',null,['event_id'=>$eventId,'member_id'=>$memberId,'member_name'=>$legalName]);
        http_response_code(500);
        page_start('Could not record attendance','checkin-confirmation');
        echo '<section class="card hero-card member-shell center"><div class="success-icon">×</div><h1>Could not record attendance</h1><p class="muted">Please talk to the secretary.</p></section>';
        page_end();
        exit;
    }
}

$canCorrect = false;
if ($isAvailable && $attendance) {
    $canCorrectStmt = db()->prepare('SELECT COUNT(*) FROM attendance a JOIN checkin_device_events d ON d.device_hash=a.checkin_device_hash AND d.event_id=a.event_id WHERE a.member_id=? AND a.event_id=? AND a.checkin_device_hash=? AND a.check_in_time>=? AND d.member_id=? AND d.correction_used=0');
    $canCorrectStmt->execute([$memberId,$eventId,$deviceHash,$correctionCutoff,$memberId]);
    $canCorrect = (int)$canCorrectStmt->fetchColumn() === 1;
}
$recordedTime = !empty($attendance['check_in_time']) ? date('g:i A',strtotime((string)$attendance['check_in_time'])) : '';
page_start($event['title'], 'checkin-confirmation'); ?>
<div class="member-shell" style="margin-inline:auto">
<?php if ($blockedMemberName !== ''): ?>
    <section class="card hero-card center"><div class="success-icon">×</div><h1>This phone already checked in</h1><p class="muted">This phone recorded attendance for <?= e($blockedMemberName) ?>. If that is wrong, please talk to <?= e(app_setting('secretary_name','the secretary')) ?>.</p></section>
<?php elseif (!$isAvailable && $attendance): ?>
    <section class="card hero-card center"><div class="success-icon">✓</div><h1>Attendance is now closed</h1><p class="muted">Don’t worry, <?= e($confirmationFirstName) ?>. You were marked pressent<?= $recordedTime!==''?' at '.e($recordedTime):'' ?>.</p><div class="checkin-details"><strong><?= e($confirmationName) ?></strong><strong><?= e((string)(int)$attendance['points']) ?> points</strong><strong><?= e(date('l, F j, Y',strtotime($event['event_date']))) ?></strong></div></section>
<?php elseif ($danceWindowState==='unassigned'): ?>
    <section class="card hero-card center"><div class="success-icon">×</div><h1>No dance shift assigned</h1><p class="muted"><?= e($confirmationFirstName) ?>, your name is not on this dance shift list. Please talk to <?= e(app_setting('secretary_name','the secretary')) ?>.</p></section>
<?php elseif ($danceWindowState==='early' && $danceShift): ?>
    <section class="card hero-card center"><div class="success-icon">○</div><h1>Your shift has not opened yet</h1><p class="muted"><?= e($confirmationFirstName) ?>, your shift is at <?= e($danceShift->format('g:i A')) ?>. You can check in starting at <?= e($danceShift->modify('-10 minutes')->format('g:i A')) ?>.</p></section>
<?php elseif ($danceWindowState==='late' && $danceShift): ?>
    <section class="card hero-card center"><div class="success-icon">×</div><h1>Your check-in window has closed</h1><p class="muted">Your <?= e($danceShift->format('g:i A')) ?> shift could be checked in through <?= e($danceShift->modify('+10 minutes')->format('g:i A')) ?>. Please talk to <?= e(app_setting('secretary_name','the secretary')) ?>.</p></section>
<?php elseif (!$isAvailable): ?>
    <section class="card hero-card center"><div class="success-icon">×</div><h1>Attendance is closed</h1><p class="muted">If you need your attendance recorded, please come talk to <?= e(app_setting('secretary_name', 'the secretary')) ?>.</p></section>
<?php else: ?>
    <section class="card hero-card center"><div class="success-icon">✓</div><h1>Thank you, <?= e($confirmationFirstName) ?>!</h1><p class="muted">You have been checked into today’s event</p><div class="checkin-details"><strong><?= e($confirmationName) ?></strong><?php if($danceShift):?><strong>Shift: <?= e($danceShift->format('g:i A')) ?></strong><?php endif;?><strong><?= e((string)(int)$attendance['points']) ?> points</strong><strong><?= e(date('l, F j, Y',strtotime($event['event_date']))) ?></strong><?php if($recordedTime!==''):?><strong><?= e($recordedTime) ?></strong><?php endif;?></div><?php if($canCorrect):?><form method="post" style="margin-top:26px" onsubmit="return confirm('Are you sure this is not you? You only get one correction. Your current attendance will be removed, and you must choose the correct name to be marked pressent.')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="correct_identity"><button class="secondary small">Not you?</button><p class="hint">You can correct this once for the next two minutes.</p></form><?php endif;?></section>
<?php endif; ?>
</div>
<?php page_end(); ?>
