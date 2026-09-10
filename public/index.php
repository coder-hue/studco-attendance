<?php
declare(strict_types=1);
require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';
require APP_CODE_ROOT . '/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_member') {
    verify_csrf();
    $memberId = (int)($_POST['member_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ? AND active = 1'); $stmt->execute([$memberId]);
    $selectedMember = $stmt->fetch();
    if (!$selectedMember) { audit('invalid_member_selection','member',$memberId?:null); flash('error','That member could not be found.'); redirect('/'); }
    audit('member_selected','member',$memberId,['member_name'=>trim((string)$selectedMember['first_name'].' '.(string)$selectedMember['last_name'])]);
    setcookie('member_id',(string)$memberId,['expires'=>time()+31536000,'path'=>'/','secure'=>(getenv('APP_ENV') ?: 'production') === 'production','httponly'=>true,'samesite'=>'Lax']);
    $next=(string)($_POST['next'] ?? '/'); $safeNext=str_starts_with($next,'/')&&!str_starts_with($next,'//')?$next:'/';
    ?><!doctype html><meta charset="utf-8"><script>localStorage.setItem('member_id',<?= json_encode((string)$memberId) ?>);location.replace(<?= json_encode($safeNext) ?>);</script><?php exit;
}

page_start('Attendance', 'checkin-confirmation'); ?>
<section class="card hero-card member-shell center"><div class="success-icon">→</div><h1>Ready to check in?</h1><p class="muted">Scan the QR code shown at your meeting.</p></section>
<?php page_end(); ?>
