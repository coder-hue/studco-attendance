<?php
declare(strict_types=1);
require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
require APP_CODE_ROOT . '/layout.php';

if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/admin.php')) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    redirect('/admin' . ($query !== '' ? '?' . $query : ''));
}

$view = (string)($_GET['view'] ?? 'dashboard');
if ($view === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1'); $stmt->execute([$email]); $account = $stmt->fetch();
        if ($account && password_verify((string)($_POST['password'] ?? ''), $account['password_hash'])) {
            session_regenerate_id(true); $_SESSION['user_id'] = (int)$account['id']; audit('login','user',(int)$account['id']); redirect('/admin.php');
        }
        audit('login_failed','user',null,['username'=>$email]);
        flash('error','Email or password is incorrect.'); redirect('/admin.php?view=login');
    }
    page_start('Staff sign in', 'checkin-confirmation'); ?>
    <section class="card hero-card member-shell center"><div class="success-icon">S</div><h1>Sign in</h1><p class="muted">StuCo attendance staff</p><form method="post" class="stack" style="margin:30px auto 0;max-width:420px;text-align:left"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>User<input type="text" name="email" required autofocus autocomplete="username"></label><label>Password<input type="password" name="password" required></label><button class="wide">Sign in</button></form></section>
    <?php page_end(); exit;
}

$user = require_staff('officer','advisor');
if ($view === 'logout') { audit('logout','user',(int)$user['id']); $_SESSION=[]; session_destroy(); redirect('/admin.php?view=login'); }
if ($view === 'dashboard' || $view === 'new_event') { redirect('/admin.php?view=events'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_event') {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        if ($title === '') { flash('error','Enter an event name.'); redirect('/admin.php?view=events'); }
        if (trim((string)($_POST['event_date'] ?? '')) === '') { flash('error','Choose an attendance date.'); redirect('/admin.php?view=events'); }
        $eventDate = (string)$_POST['event_date'];
        $requestedMode = (string)($_POST['attendance_mode'] ?? 'standard');
        $attendanceMode = in_array($requestedMode,['standard','decorations','dance_shifts'],true) ? $requestedMode : 'standard';
        $maxPoints = $attendanceMode === 'decorations' ? 8 : 2;
        $initialOpenState = $attendanceMode === 'dance_shifts' ? 1 : 2;
        $initialEarlyState = $attendanceMode === 'decorations' ? 2 : 0;
        $openTime = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['checkin_open_time'] ?? '')) ? $_POST['checkin_open_time'] . ':00' : '07:35:00';
        $closeTime = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['checkin_close_time'] ?? '')) ? $_POST['checkin_close_time'] . ':00' : '07:50:00';
        if ($closeTime <= $openTime) { flash('error','The closing time must be after the opening time.'); redirect('/admin.php?view=events'); }
        $stmt = db()->prepare("INSERT INTO events (public_token,early_public_token,attendance_mode,title,description,location,term,event_date,start_time,end_time,checkin_opens,checkin_closes,max_points,required,question,answer_hash,schoology_assignment,is_open,early_is_open,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([bin2hex(random_bytes(16)),$attendanceMode==='decorations'?bin2hex(random_bytes(16)):null,$attendanceMode,$title,null,null,'Attendance',$eventDate,$openTime,$closeTime,$eventDate.' '.$openTime,$eventDate.' '.$closeTime,$maxPoints,1,'',password_hash('',PASSWORD_DEFAULT),null,$initialOpenState,$initialEarlyState,$user['id']]);
        $id=(int)db()->lastInsertId(); audit('created','event',$id,['title'=>$title,'event_date'=>$eventDate,'attendance_mode'=>$attendanceMode]); flash('success','Event created.'); redirect('/admin.php?view=event&id='.$id);
    }
    if ($action === 'set_event_open') {
        $id=(int)$_POST['event_id'];
        $state=(int)($_POST['is_open'] ?? 0);
        $lane=($_POST['lane']??'full')==='early'?'early':'full';
        if (!in_array($state,[0,1,2],true)) { $state=0; }
        $column=$lane==='early'?'early_is_open':'is_open';
        db()->prepare("UPDATE events SET {$column}=? WHERE id=? AND locked_at IS NULL AND finalized_at IS NULL")->execute([$state,$id]);
        audit($state===2 ? 'scheduled_checkin' : ($state===1 ? 'opened_checkin' : 'closed_checkin'),'event',$id,['lane'=>$lane]);
        redirect('/admin.php?view=event&id='.$id);
    }
    if ($action === 'update_event') {
        $id=(int)$_POST['event_id'];
        $title=mb_substr(trim((string)($_POST['title']??'')),0,160);
        $eventDate=trim((string)($_POST['event_date']??''));
        $openTime=trim((string)($_POST['checkin_open_time']??''));
        $closeTime=trim((string)($_POST['checkin_close_time']??''));
        if($title===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$eventDate)||!preg_match('/^\d{2}:\d{2}$/',$openTime)||!preg_match('/^\d{2}:\d{2}$/',$closeTime)||$closeTime<=$openTime){flash('error','Enter a name and choose a valid date and check-in window.');redirect('/admin.php?view=event&id='.$id);}
        db()->prepare('UPDATE events SET title=?,event_date=?,start_time=?,end_time=?,checkin_opens=?,checkin_closes=? WHERE id=?')->execute([$title,$eventDate,$openTime.':00',$closeTime.':00',$eventDate.' '.$openTime.':00',$eventDate.' '.$closeTime.':00',$id]);
        audit('updated','event',$id,['title'=>$title,'event_date'=>$eventDate,'open_time'=>$openTime,'close_time'=>$closeTime]);flash('success','Attendance updated.');redirect('/admin.php?view=event&id='.$id);
    }
    if ($action === 'delete_event') {
        $id=(int)$_POST['event_id'];
        audit('deleted','event',$id);
        db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
        flash('success','Attendance deleted.');redirect('/admin.php?view=events');
    }
    if ($action === 'finalize_event') {
        $id=(int)$_POST['event_id']; db()->beginTransaction();
        try { db()->prepare('UPDATE events SET is_open=0,finalized_at=NOW() WHERE id=?')->execute([$id]); db()->commit(); audit('finalized','event',$id); flash('success','Event finalized. Members without a check-in remain not submitted.'); } catch(Throwable $error) { db()->rollBack(); flash('error','Could not finalize the event.'); }
        redirect('/admin.php?view=event&id='.$id);
    }
    if ($action === 'set_attendance') {
        $eventId=(int)$_POST['event_id'];$memberId=(int)$_POST['member_id'];$status=(string)$_POST['status'];
        if (!in_array($status,['present','2','6','8','missing'],true)) exit('Invalid status');
        if ($status === 'missing') { db()->prepare('DELETE FROM attendance WHERE member_id=? AND event_id=?')->execute([$memberId,$eventId]); }
        else { $points=$status==='present'?2:(int)$status;$stmt=db()->prepare("INSERT INTO attendance(member_id,event_id,status,points,check_in_time,updated_by) VALUES(?,?, 'present',?,NOW(),?) ON DUPLICATE KEY UPDATE status='present',points=VALUES(points),updated_by=VALUES(updated_by)");$stmt->execute([$memberId,$eventId,$points,$user['id']]); }
        audit('attendance_updated','attendance',null,compact('eventId','memberId','status')); redirect('/admin.php?view=event&id='.$eventId);
    }
    if ($action === 'save_dance_shifts') {
        $eventId=(int)($_POST['event_id']??0);
        $eventStmt=db()->prepare("SELECT id FROM events WHERE id=? AND attendance_mode='dance_shifts'");$eventStmt->execute([$eventId]);
        if(!$eventStmt->fetchColumn()){flash('error','Dance shifts event not found.');redirect('/admin.php?view=events');}
        $times=is_array($_POST['shift_time']??null)?$_POST['shift_time']:[];
        db()->beginTransaction();
        try{
            db()->prepare('DELETE FROM event_shifts WHERE event_id=?')->execute([$eventId]);
            $insert=db()->prepare('INSERT INTO event_shifts(event_id,member_id,shift_time) VALUES(?,?,?)');$count=0;
            foreach($times as $memberId=>$time){$memberId=(int)$memberId;$time=trim((string)$time);if(!$memberId||$time==='')continue;if(!preg_match('/^\d{2}:\d{2}$/',$time))continue;$insert->execute([$eventId,$memberId,$time.':00']);$count++;}
            db()->commit();audit('dance_shifts_saved','event',$eventId,['count'=>$count]);flash('success',"Saved {$count} dance shifts.");
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();flash('error','Could not save the dance shifts.');}
        redirect('/admin.php?view=event&id='.$eventId);
    }
    if ($action === 'import_dance_shifts') {
        $eventId=(int)($_POST['event_id']??0);$raw=trim((string)($_POST['shift_list']??''));
        $eventStmt=db()->prepare("SELECT id FROM events WHERE id=? AND attendance_mode='dance_shifts'");$eventStmt->execute([$eventId]);
        if(!$eventStmt->fetchColumn()){flash('error','Dance shifts event not found.');redirect('/admin.php?view=events');}
        $members=db()->query("SELECT id,first_name,last_name,nickname FROM members WHERE active=1")->fetchAll();$memberMap=[];
        $normalize=static fn(string $name):string=>trim((string)preg_replace('/[^\pL\pN]+/u',' ',mb_strtolower($name)));
        foreach($members as $member){$names=[trim($member['first_name'].' '.$member['last_name'])];if(trim((string)$member['nickname'])!=='')$names[]=trim($member['nickname'].' '.$member['last_name']);foreach($names as $name)$memberMap[$normalize($name)]=(int)$member['id'];}
        $parsed=[];$unmatched=[];
        foreach(preg_split('/\R/',$raw)?:[] as $line){$line=trim($line);if($line==='')continue;$match=[];if(!preg_match('/^(.+?)(?:\s*[,\t]\s*|\s+-\s+)(\d{1,2}:\d{2}\s*(?:AM|PM)?)$/i',$line,$match)&&!preg_match('/^(.+?)\s+(\d{1,2}:\d{2}\s*(?:AM|PM))$/i',$line,$match)){$unmatched[]=$line;continue;}$memberId=$memberMap[$normalize($match[1])]??0;$stamp=strtotime($match[2]);if(!$memberId||$stamp===false){$unmatched[]=$line;continue;}$parsed[$memberId]=date('H:i:s',$stamp);}
        if(!$parsed){flash('error','No shifts were imported. Use one line per person, like Ella Adamson, 6:30 PM.');redirect('/admin.php?view=event&id='.$eventId);}
        db()->beginTransaction();
        try{db()->prepare('DELETE FROM event_shifts WHERE event_id=?')->execute([$eventId]);$insert=db()->prepare('INSERT INTO event_shifts(event_id,member_id,shift_time) VALUES(?,?,?)');foreach($parsed as $memberId=>$time)$insert->execute([$eventId,$memberId,$time]);db()->commit();audit('dance_shifts_imported','event',$eventId,['count'=>count($parsed),'unmatched'=>$unmatched]);$message='Imported '.count($parsed).' dance shifts.';if($unmatched)$message.=' Could not match '.count($unmatched).' line'.(count($unmatched)===1?'':'s').'.';flash($unmatched?'error':'success',$message);}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();flash('error','Could not import the dance shifts.');}
        redirect('/admin.php?view=event&id='.$eventId);
    }
    if ($action === 'add_member') { $first=trim((string)$_POST['first_name']);$nickname=mb_substr(trim((string)($_POST['nickname']??'')),0,80);$confirmationNickname=mb_substr(trim((string)($_POST['confirmation_nickname']??'')),0,80);$last=trim((string)$_POST['last_name']);if($first===''||$last===''){flash('error','First and last name are required.');redirect('/admin.php?view=members');}$stmt=db()->prepare('INSERT INTO members(first_name,nickname,confirmation_nickname,last_name,email,grade,graduation_year) VALUES(?,?,?,?,?,?,?)');$stmt->execute([$first,$nickname?:null,$confirmationNickname?:null,$last,trim((string)$_POST['email'])?:null,null,null]);audit('created','member',(int)db()->lastInsertId());flash('success','Member added.');redirect('/admin.php?view=members'); }
    if ($action === 'update_member') { $id=(int)($_POST['member_id']??0);$first=mb_substr(trim((string)($_POST['first_name']??'')),0,80);$nickname=mb_substr(trim((string)($_POST['nickname']??'')),0,80);$confirmationNickname=mb_substr(trim((string)($_POST['confirmation_nickname']??'')),0,80);$last=mb_substr(trim((string)($_POST['last_name']??'')),0,80);$email=mb_substr(trim((string)($_POST['email']??'')),0,190);if(!$id||$first===''||$last===''){flash('error','First and last name are required.');redirect('/admin.php?view=members');}db()->prepare('UPDATE members SET first_name=?,nickname=?,confirmation_nickname=?,last_name=?,email=? WHERE id=? AND active=1')->execute([$first,$nickname?:null,$confirmationNickname?:null,$last,$email?:null,$id]);audit('updated','member',$id,['nickname'=>$nickname?:null,'confirmation_nickname'=>$confirmationNickname?:null]);flash('success','Member updated.');redirect('/admin.php?view=members'); }
    if ($action === 'archive_member') { $id=(int)$_POST['member_id'];db()->prepare('UPDATE members SET active=0 WHERE id=?')->execute([$id]);audit('archived','member',$id);flash('success','Member removed from the active roster.');redirect('/admin.php?view=members'); }
    if ($action === 'import_members') {
        if(!isset($_FILES['csv'])||$_FILES['csv']['error']!==UPLOAD_ERR_OK){flash('error','Choose a CSV file.');redirect('/admin.php?view=members');}
        $handle=fopen($_FILES['csv']['tmp_name'],'rb');$header=fgetcsv($handle);$map=array_flip(array_map(fn($value)=>mb_strtolower(trim((string)$value)),$header?:[]));if(!isset($map['first name'],$map['last name'])){fclose($handle);flash('error','CSV needs First Name and Last Name columns.');redirect('/admin.php?view=members');}
        $insert=db()->prepare('INSERT INTO members(first_name,nickname,confirmation_nickname,last_name,email) VALUES(?,?,?,?,?)');$count=0;while(($row=fgetcsv($handle))!==false){$first=trim((string)($row[$map['first name']]??''));$last=trim((string)($row[$map['last name']]??''));if($first===''||$last==='')continue;$nicknameKey=$map['list nickname']??$map['nickname']??null;$confirmationKey=$map['confirmation nickname']??null;$nickname=$nicknameKey!==null?trim((string)($row[$nicknameKey]??'')):'';$confirmationNickname=$confirmationKey!==null?trim((string)($row[$confirmationKey]??'')):'';$email=isset($map['email'])?trim((string)($row[$map['email']]??'')):'';$insert->execute([$first,$nickname?:null,$confirmationNickname?:null,$last,$email?:null]);$count++;}fclose($handle);audit('csv_import','member',null,['count'=>$count]);flash('success',"Imported {$count} members.");redirect('/admin.php?view=members');
    }
    if ($action === 'save_settings') {
        $secretary = mb_substr(trim((string)($_POST['secretary_name'] ?? '')), 0, 120);
        $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['secretary_name', $secretary]);
        audit('settings_updated', 'app_settings');
        flash('success', 'Settings saved.');
        redirect('/admin.php?view=settings');
    }
}

function admin_nav(string $view): void { ?><nav class="side-nav"><a class="<?= in_array($view,['events','event'],true)?'active':'' ?>" href="/admin.php?view=events">Attendance</a><a class="<?= $view==='members'?'active':'' ?>" href="/admin.php?view=members">Manage members</a><a class="<?= $view==='settings'?'active':'' ?>" href="/admin.php?view=settings">Settings</a><a href="/admin.php?view=logout">Sign out</a></nav><?php }
page_start(ucwords(str_replace('_',' ',$view)), 'admin-page'); ?><div class="admin-layout"><?php admin_nav($view); ?><div class="stack">
<style>
.admin-page label:has(>input[name="required"]){display:none!important}
.decorations-board{border-top:4px solid var(--royal);padding-top:22px}.decorations-board>header{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:22px}.decorations-board>header h2,.decorations-board>header p{margin:0}.decoration-lanes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border:1px solid var(--line);border-radius:18px;overflow:hidden}.decoration-lane{padding:26px;background:#fff}.decoration-lane+.decoration-lane{border-left:1px solid var(--line)}.decoration-lane-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.decoration-lane h3{font-size:1.45rem;margin:0 0 4px}.lane-points{color:var(--royal);font-weight:900}.lane-state{display:inline-flex;border-radius:999px;padding:6px 10px;background:#eef2f8;color:#526077;font-size:.76rem;font-weight:900}.lane-state.open{background:#e9f9ef;color:#167044}.lane-controls{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}.decoration-lane .qr-box{max-width:260px;margin:0 auto 16px}.decoration-lane .qr-tools{display:flex;gap:8px;flex-wrap:wrap}.decorations-event-hidden{display:none!important}.decorations-attendance-wide{grid-column:1/-1!important}.point-legend{font-size:.86rem;color:var(--muted)}
.dance-shifts-board{border-top:4px solid var(--royal);padding-top:22px}.dance-shifts-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:18px}.dance-shifts-heading h2,.dance-shifts-heading p{margin:0}.shift-import{border-block:1px solid var(--line);padding:18px 0;margin-bottom:12px}.shift-import summary{cursor:pointer;color:var(--royal);font-weight:850}.shift-import textarea{min-height:150px;margin-top:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.shift-roster{display:grid}.shift-row{display:grid;grid-template-columns:minmax(190px,1fr) 170px;align-items:center;gap:18px;padding:12px 0;border-bottom:1px solid var(--line)}.shift-row label{margin:0}.shift-row input{padding-block:10px}.shift-name{font-weight:800}.shift-summary{color:var(--royal);font-size:.85rem;font-weight:850}
.members-toolbar{display:flex;gap:12px;align-items:center;margin-top:20px}.members-toolbar input{max-width:460px}.member-count{color:#667085;font-size:.86rem;font-weight:700;white-space:nowrap}
.member-roster{border-top:1px solid var(--line)}.member-record{border-bottom:1px solid var(--line)}.member-record[hidden]{display:none}.member-record summary{display:grid;grid-template-columns:minmax(180px,1.3fr) minmax(160px,1fr) auto;gap:20px;align-items:center;padding:18px 4px;cursor:pointer;list-style:none}.member-record summary::-webkit-details-marker{display:none}.member-record summary::after{content:'Edit';color:var(--royal);font-size:.82rem;font-weight:800}.member-record[open] summary::after{content:'Close'}.member-primary{font-size:1rem;font-weight:800;color:var(--ink)}.member-legal,.member-email{color:#667085;font-size:.82rem;margin-top:3px}.member-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;padding:4px 4px 22px}.member-form .full{grid-column:1/-1}.member-form .field-note{margin:0;color:#667085;font-size:.78rem;font-weight:500}.member-form-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;grid-column:1/-1;padding-top:4px}.member-form-actions .save-group{display:flex;gap:10px;align-items:center}.add-member-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.add-member-form .full{grid-column:1/-1}
@media(max-width:720px){.members-toolbar{align-items:stretch;flex-direction:column}.members-toolbar input{max-width:none}.member-record summary{grid-template-columns:1fr auto;gap:8px}.member-email-column{display:none}.member-form,.add-member-form{grid-template-columns:1fr}.member-form .full,.add-member-form .full{grid-column:auto}.member-form-actions{align-items:stretch;flex-direction:column-reverse}.member-form-actions .save-group{width:100%}.member-form-actions button{flex:1}.decorations-board>header{align-items:flex-start;flex-direction:column}.decoration-lanes{grid-template-columns:1fr}.decoration-lane+.decoration-lane{border-left:0;border-top:1px solid var(--line)}.decoration-lane{padding:20px 16px}.decoration-lane .qr-box{max-width:220px}.dance-shifts-heading{align-items:flex-start;flex-direction:column}.shift-row{grid-template-columns:1fr 135px;gap:10px}.dance-shifts-board{padding-top:18px}}
</style>
<?php if($view==='events'):
    $events=db()->query("SELECT e.*,(SELECT COUNT(*) FROM attendance a WHERE a.event_id=e.id) checked_count FROM events e ORDER BY event_date DESC,start_time DESC")->fetchAll(); ?>
    <?php $currentEvents=array_values(array_filter($events,fn($event)=>!event_is_archived($event)));$archivedEvents=array_values(array_filter($events,fn($event)=>event_is_archived($event)));$renderEvents=static function(array $items):void{foreach($items as $event){$effectiveOpen=event_checkin_is_open($event);?><a class="list-row" href="/admin.php?view=event&id=<?= (int)$event['id'] ?>"><div><h3><?= e($event['title']) ?></h3><p class="muted"><?= e(date('M j, Y',strtotime($event['event_date']))) ?> · <?= (int)$event['checked_count'] ?> submitted</p></div><?= $effectiveOpen?'<span class="badge status-present">Open</span>':'<span class="badge">Closed</span>' ?></a><?php }};?><div><p class="eyebrow">Staff</p><h1>Attendance</h1></div><section class="card"><h2>Create attendance</h2><p class="muted">Create meetings and events independently, even when they happen on the same date.</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_event"><label>Event name<input name="title" placeholder="General Meeting" required></label><label>Date<input type="date" name="event_date" value="<?= e(date('Y-m-d')) ?>" required></label><details class="time-override"><summary>Check-in time</summary><div class="form-grid"><label>Open time<input type="time" name="checkin_open_time" value="07:35"></label><label>Close time<input type="time" name="checkin_close_time" value="07:50"></label></div><p class="hint">Central Time</p></details><div class="full"><button>Create attendance</button></div></form></section><section class="card"><h2>Attendance events</h2><div class="list"><?php if(!$currentEvents):?><div class="empty">No current attendance events.</div><?php endif;$renderEvents($currentEvents);?></div></section><?php if($archivedEvents):?><section class="card"><details><summary><strong>Archived attendance</strong> · <?= count($archivedEvents) ?></summary><div class="list" style="margin-top:16px"><?php $renderEvents($archivedEvents);?></div></details></section><?php endif;?>
    <script>(()=>{const form=document.querySelector('input[name="action"][value="create_event"]')?.closest('form');const date=form?.querySelector('input[name="event_date"]')?.closest('label');if(!form||!date||form.querySelector('[name="attendance_mode"]'))return;const label=document.createElement('label');label.innerHTML='Attendance type<select name="attendance_mode"><option value="standard">Regular attendance · 2 points</option><option value="decorations">Decorations Day · 6 or 8 points</option><option value="dance_shifts">Dance shifts · 2 points</option></select>';date.after(label);})();</script>
<?php elseif($view==='event'):
    $id=(int)($_GET['id']??0);$stmt=db()->prepare('SELECT * FROM events WHERE id=?');$stmt->execute([$id]);$event=$stmt->fetch();if(!$event){echo '<div class="empty">Event not found.</div>';}else{$url=base_url('/checkin?event='.$event['id'].'&token='.$event['public_token']); ?>
    <?php if (($event['attendance_mode'] ?? 'standard') === 'decorations'):
        $earlyUrl=base_url('/checkin?event='.$event['id'].'&token='.$event['early_public_token']);
        $earlyOpen=event_lane_is_open($event,'early');
        $fullOpen=event_lane_is_open($event,'full'); ?>
    <section class="decorations-board" data-decorations-board>
      <header><div><p class="eyebrow">Decorations Day · <?= e(date('l, M j, Y',strtotime($event['event_date']))) ?></p><h1><?= e($event['title']) ?></h1><p class="muted">Two departure check-ins, controlled separately.</p></div><p class="point-legend">An 8-point scan upgrades an earlier 6-point scan. It never downgrades.</p></header>
      <div class="decoration-lanes">
        <?php foreach ([['early','Left early',6,$earlyUrl,$earlyOpen,(int)$event['early_is_open']],['full','Full time',8,$url,$fullOpen,(int)$event['is_open']]] as [$lane,$label,$points,$laneUrl,$laneOpen,$laneMode]): ?>
        <article class="decoration-lane" data-qr-panel>
          <div class="decoration-lane-head"><div><h3><?= e($label) ?></h3><span class="lane-points"><?= $points ?> points</span></div><span class="lane-state <?= $laneOpen?'open':'' ?>"><?= $laneOpen?'Open':'Closed' ?><?= $laneMode===2?' · scheduled':'' ?></span></div>
          <?php if(!$event['finalized_at']):?><div class="lane-controls"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_event_open"><input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="lane" value="<?= e($lane) ?>"><input type="hidden" name="is_open" value="<?= $laneOpen?'0':'1' ?>"><button class="<?= $laneOpen?'danger':'' ?>"><?= $laneOpen?'Close':'Open' ?> <?= e(strtolower($label)) ?></button></form><?php if($laneMode!==2):?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_event_open"><input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="lane" value="<?= e($lane) ?>"><input type="hidden" name="is_open" value="2"><button class="secondary">Use schedule</button></form><?php endif;?></div><?php endif;?>
          <div class="qr-box"><img data-qr data-url="<?= e($laneUrl) ?>" data-event-id="<?= $id ?>-<?= e($lane) ?>" alt="<?= e($label) ?> check-in QR code"></div>
          <div class="qr-options" role="group" aria-label="<?= e($label) ?> QR colors"><button class="secondary small active" type="button" data-qr-style="black">Black PNG</button><button class="secondary small" type="button" data-qr-style="white">White PNG</button></div>
          <div class="qr-tools"><button class="secondary small" type="button" data-copy-qr>Copy PNG</button><button class="secondary small" type="button" data-download-qr>Download PNG</button><button class="secondary small" type="button" data-copy-link>Copy link</button></div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <script>document.addEventListener('DOMContentLoaded',()=>{const board=document.querySelector('[data-decorations-board]');const header=board?.nextElementSibling;header?.classList.add('decorations-event-hidden');const grid=header?.nextElementSibling;grid?.querySelector('aside')?.classList.add('decorations-event-hidden');grid?.querySelector('section')?.classList.add('decorations-attendance-wide');const select=document.querySelector('template[data-attendance-form] select[name="status"]');if(select)select.innerHTML='<option value="8">Full time · 8 points</option><option value="6">Left early · 6 points</option><option value="missing">No submission</option>';document.querySelectorAll('section h2').forEach(heading=>{if(heading.textContent.trim()==='Finalize & grade batch'){const copy=heading.nextElementSibling;if(copy)copy.textContent='Recorded check-ins export as 6 or 8 points. No submissions stay unchanged in Schoology.';}});});</script>
    <?php endif; ?>
    <?php if (($event['attendance_mode'] ?? 'standard') === 'dance_shifts'):
        $shiftStmt=db()->prepare("SELECT m.id,CONCAT(COALESCE(NULLIF(m.nickname,''),m.first_name),' ',m.last_name) name,s.shift_time FROM members m LEFT JOIN event_shifts s ON s.member_id=m.id AND s.event_id=? WHERE m.active=1 ORDER BY CASE WHEN s.shift_time IS NULL THEN 1 ELSE 0 END,s.shift_time,m.last_name,m.first_name");
        $shiftStmt->execute([$id]);$danceRoster=$shiftStmt->fetchAll();$assignedCount=count(array_filter($danceRoster,static fn(array $row):bool=>$row['shift_time']!==null)); ?>
    <section class="dance-shifts-board" data-dance-shifts-board>
      <div class="dance-shifts-heading"><div><p class="eyebrow">Dance shifts</p><h2>Shift roster</h2><p class="muted">Each person can check in from 10 minutes before through 10 minutes after their assigned time.</p></div><span class="shift-summary"><?= $assignedCount ?> of <?= count($danceRoster) ?> assigned</span></div>
      <details class="shift-import"><summary>Paste the shift list</summary><form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_dance_shifts"><input type="hidden" name="event_id" value="<?= $id ?>"><label>Names and times<textarea name="shift_list" placeholder="Ella Adamson, 6:30 PM&#10;Lincoln Aguilar, 7:00 PM" required></textarea></label><p class="hint">One person per line. Importing replaces the current shift list; names must match the member roster.</p><button>Import shift list</button></form></details>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_dance_shifts"><input type="hidden" name="event_id" value="<?= $id ?>"><div class="shift-roster"><?php foreach($danceRoster as $row):?><div class="shift-row"><span class="shift-name"><?= e($row['name']) ?></span><label><span class="visually-hidden">Shift time for <?= e($row['name']) ?></span><input type="time" name="shift_time[<?= (int)$row['id'] ?>]" value="<?= e($row['shift_time']?substr((string)$row['shift_time'],0,5):'') ?>"></label></div><?php endforeach;?></div><div style="margin-top:18px"><button>Save shift times</button></div></form>
    </section>
    <script>document.addEventListener('DOMContentLoaded',()=>{const board=document.querySelector('[data-dance-shifts-board]');const header=board?.nextElementSibling;const grid=header?.nextElementSibling;if(grid)grid.after(board);});</script>
    <?php endif; ?>
    <?php $effectiveOpen=event_checkin_is_open($event);$checkinMode=event_checkin_mode($event);?><div class="list-row"><div><p class="eyebrow"><?= e(date('l, M j, Y',strtotime($event['event_date']))) ?></p><h1><?= e($event['title']) ?></h1><p class="muted"><span class="live-dot"></span><?= $effectiveOpen?'Check-in is open':'Check-in is closed' ?><?= $checkinMode==='scheduled'?' · Scheduled':'' ?></p></div><?php if(!$event['finalized_at']):?><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_event_open"><input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="is_open" value="<?= $effectiveOpen?'0':'1' ?>"><button class="<?= $effectiveOpen?'danger':'' ?>"><?= $effectiveOpen?'Close check-in':'Open check-in' ?></button></form><?php if($checkinMode!=='scheduled'):?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_event_open"><input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="is_open" value="2"><button class="secondary">Use schedule</button></form><?php endif;?></div><?php endif;?></div><div class="grid"><section class="card span-8"><div class="kpi-grid" data-live-counts><div class="stat"><strong data-count="members">—</strong><span>Members</span></div><div class="stat"><strong data-count="present">—</strong><span>Submitted</span></div><div class="stat"><strong data-count="missing">—</strong><span>Not submitted</span></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Status</th><th>Time</th><th>Update</th></tr></thead><tbody data-live-table></tbody></table></div></section><aside class="card span-4"><h2>Event QR</h2><div class="qr-box"><img data-qr data-url="<?= e($url) ?>" data-event-id="<?= $id ?>" alt="Attendance check-in QR code"></div><div class="qr-options" role="group" aria-label="QR colors"><button class="secondary small active" type="button" data-qr-style="black">Black PNG</button><button class="secondary small" type="button" data-qr-style="white">White PNG</button></div><p class="hint">1080 × 1080 PNG</p><div class="actions"><button class="secondary small" type="button" data-copy-qr>Copy PNG</button><button class="secondary small" type="button" data-download-qr>Download PNG</button></div><p class="hint" style="overflow-wrap:anywhere"><?= e($url) ?></p><button class="secondary small" type="button" onclick="navigator.clipboard.writeText(<?= e(json_encode($url)) ?>)">Copy link</button></aside></div><section class="card"><h2>Manage attendance</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_event"><input type="hidden" name="event_id" value="<?= $id ?>"><label>Event name<input name="title" value="<?= e($event['title']) ?>" required></label><label>Date<input type="date" name="event_date" value="<?= e($event['event_date']) ?>" required></label><label>Open time<input type="time" name="checkin_open_time" value="<?= e(substr((string)$event['start_time'],0,5)) ?>" required></label><label>Close time<input type="time" name="checkin_close_time" value="<?= e(substr((string)$event['end_time'],0,5)) ?>" required></label><label class="full"><input type="checkbox" name="required" value="1" <?= $event['required']?'checked':'' ?>> Required event</label><div class="full"><button>Save changes</button></div></form><form method="post" style="margin-top:24px" onsubmit="return confirm('Delete this attendance and every recorded check-in? This cannot be undone.')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="<?= $id ?>"><button class="danger">Delete attendance</button></form></section><section class="card"><h2>Finalize & grade batch</h2><p class="muted">Present check-ins export as 2 points. No submissions are not sent to Schoology.</p><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="finalize_event"><input type="hidden" name="event_id" value="<?= $id ?>"><button>Finalize attendance</button></form><a class="button secondary" href="/grade-batch.php?event_id=<?= $id ?>">Download grade batch</a></div></section><template data-attendance-form><form method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_attendance"><input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="member_id"><select name="status" style="padding:7px;width:auto"><option>present</option><option>missing</option></select><button class="small">Save</button></form></template><script>window.LIVE_EVENT_ID=<?= $id ?>;</script><?php }
elseif($view==='members'):
    $members=db()->query('SELECT * FROM members WHERE active=1 ORDER BY last_name,first_name')->fetchAll(); ?>
    <div><p class="eyebrow">Roster</p><h1>Members</h1><p class="muted">Search the roster, then open only the person you need to edit.</p></div>
    <div class="members-toolbar"><input type="search" data-member-filter placeholder="Search names or email…" autocomplete="off"><span class="member-count" data-member-count><?= count($members) ?> members</span></div>
    <section class="member-roster" data-member-roster>
        <?php foreach($members as $member): $listFirst=trim((string)$member['nickname'])?:$member['first_name']; $listName=trim($listFirst.' '.$member['last_name']); ?>
            <details class="member-record" data-member-record data-search="<?= e(mb_strtolower(implode(' ',array_filter([$member['first_name'],$member['nickname'],$member['confirmation_nickname'],$member['last_name'],$member['email']])))) ?>">
                <summary><div><div class="member-primary"><?= e($listName) ?></div><?php if($listName!==trim($member['first_name'].' '.$member['last_name'])):?><div class="member-legal">Legal name: <?= e($member['first_name'].' '.$member['last_name']) ?></div><?php endif;?></div><div class="member-email-column"><div class="member-email"><?= e($member['email']?:'No email') ?></div></div></summary>
                <form method="post" class="member-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_member"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                    <label>First name<input name="first_name" value="<?= e($member['first_name']) ?>" required></label><label>Last name<input name="last_name" value="<?= e($member['last_name']) ?>" required></label>
                    <label>List name<input name="nickname" value="<?= e($member['nickname']) ?>" placeholder="Optional"><span class="field-note">Shown while choosing a name and in attendance lists.</span></label>
                    <label>Confirmation name<input name="confirmation_nickname" value="<?= e($member['confirmation_nickname']) ?>" placeholder="Uses list name if blank"><span class="field-note">Shown only after attendance is recorded.</span></label>
                    <label class="full">Email<input type="email" name="email" value="<?= e($member['email']) ?>" placeholder="Optional"></label>
                    <div class="member-form-actions"><button class="danger small" type="submit" name="action" value="archive_member" onclick="return confirm('Remove this member from the active roster?')">Remove member</button><div class="save-group"><button class="secondary small" type="button" onclick="this.closest('details').removeAttribute('open')">Cancel</button><button class="small">Save member</button></div></div>
                </form>
            </details>
        <?php endforeach; ?>
    </section>
    <section class="card"><h2>Add member</h2><form method="post" class="add-member-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_member"><label>First name<input name="first_name" required></label><label>Last name<input name="last_name" required></label><label>List name<input name="nickname" placeholder="Optional"><span class="field-note">Shown when choosing a name.</span></label><label>Confirmation name<input name="confirmation_nickname" placeholder="Uses list name if blank"><span class="field-note">Shown after check-in.</span></label><label class="full">Email<input type="email" name="email" placeholder="Optional"></label><div class="full"><button>Add member</button></div></form></section>
    <section class="card"><h2>Import CSV</h2><p class="hint">Headers: First Name, Last Name, List Nickname, Confirmation Nickname, Email</p><form method="post" enctype="multipart/form-data" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_members"><input type="file" name="csv" accept=".csv,text/csv" required><button class="secondary">Import members</button></form></section>
    <script>(()=>{const input=document.querySelector('[data-member-filter]'),rows=[...document.querySelectorAll('[data-member-record]')],count=document.querySelector('[data-member-count]');if(!input)return;input.addEventListener('input',()=>{const query=input.value.trim().toLowerCase();let visible=0;rows.forEach(row=>{const show=!query||row.dataset.search.includes(query);row.hidden=!show;if(show)visible++;});count.textContent=`${visible} member${visible===1?'':'s'}`;});})();</script>
<?php elseif($view==='settings'): ?>
    <div><p class="eyebrow">Admin</p><h1>Settings</h1></div><section class="card" style="max-width:560px"><h2>Secretary</h2><p class="muted">This name appears on the closed-attendance page.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings"><label>Secretary's name<input name="secretary_name" value="<?= e(app_setting('secretary_name')) ?>" placeholder="Jane Smith"></label><button>Save settings</button></form></section>
<?php else: ?><div class="empty">Page not found.</div><?php endif; ?></div></div><?php page_end($view==='event'?'/assets/admin.js':''); ?>
