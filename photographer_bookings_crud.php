<?php
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn,'utf8mb4');
date_default_timezone_set('Asia/Bangkok');
if (!isset($_SESSION['photographer_id'])) { header("Location: login_photographer.php"); exit; }
$PHOTOGRAPHER_ID = (int)$_SESSION['photographer_id'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c,string $t,string $col):bool{ $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1"); $q->bind_param("ss",$t,$col); $q->execute(); $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok; }
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): bool { $bind = [$types]; foreach ($values as $k=>$v) { $bind[] = &$values[$k]; } return call_user_func_array([$stmt,'bind_param'],$bind); }
$job_has_user_id = has_col($conn,'job','user_id');
$job_has_status  = has_col($conn,'job','status');
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];
function csrf_ok(): bool { if ($_SERVER['REQUEST_METHOD'] === 'POST') { $t = $_POST['csrf_token'] ?? ''; if (!hash_equals($_SESSION['csrf_token'] ?? '', $t)) return false; } return true; }
function flash_pop(){ if (!empty($_SESSION['flash_msg'])) { $m = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); return $m; } return null; }
function flash_set($m){ $_SESSION['flash_msg'] = $m; }
$STATUS_OPT = ['รอดำเนินการ','ยืนยันแล้ว','ยกเลิก','เสร็จสิ้น'];
function norm_status($s, $opts){ return in_array($s,$opts,true) ? $s : $opts[0]; }
$stmt = $conn->prepare("SELECT first_name, last_name FROM photographer WHERE photographer_id=?");
$stmt->bind_param("i",$PHOTOGRAPHER_ID);
$stmt->execute();
$stmt->bind_result($firstName,$lastName);
$stmt->fetch();
$stmt->close();
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['act'])) {
  if (!csrf_ok()) { flash_set('คำขอไม่ถูกต้อง'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
  $act = $_POST['act'];
  if ($act === 'create') {
    $job_date = trim($_POST['job_date'] ?? '');
    $job_time = trim($_POST['job_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $user_id  = (int)($_POST['user_id'] ?? 0);
    $status   = $job_has_status ? norm_status(trim($_POST['status'] ?? ''), $STATUS_OPT) : null;
    if ($job_date==='' || $job_time==='' || $location==='') { flash_set('กรอกข้อมูลให้ครบ'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
    $cols = ['job_date','job_time','location','photographer_id'];
    $vals = [$job_date,$job_time,$location,$PHOTOGRAPHER_ID];
    $types = 'sssi';
    if ($job_has_user_id && $user_id>0) { $cols[]='user_id'; $vals[]=$user_id; $types.='i'; }
    if ($job_has_status) { $cols[]='status'; $vals[]=$status; $types.='s'; }
    $sql = "INSERT INTO job (".implode(',',$cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")";
    if ($stmt=$conn->prepare($sql)) { stmt_bind_params($stmt,$types,$vals); if ($stmt->execute()) { flash_set('เพิ่มงานสำเร็จ #'.$stmt->insert_id); } else { flash_set('เพิ่มงานล้มเหลว'); } $stmt->close(); } else { flash_set('เพิ่มงานล้มเหลว'); }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }
  if ($act === 'update') {
    $job_id   = (int)($_POST['job_id'] ?? 0);
    $job_date = trim($_POST['job_date'] ?? '');
    $job_time = trim($_POST['job_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $user_id  = (int)($_POST['user_id'] ?? 0);
    $status   = $job_has_status ? norm_status(trim($_POST['status'] ?? ''), $STATUS_OPT) : null;
    if ($job_id<=0 || $job_date==='' || $job_time==='' || $location==='') { flash_set('ข้อมูลไม่ครบ'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
    $set = ['job_date=?','job_time=?','location=?'];
    $vals = [$job_date,$job_time,$location];
    $types = 'sss';
    if ($job_has_user_id) { $set[]='user_id=?'; $vals[] = ($user_id>0?$user_id:null); $types.='i'; }
    if ($job_has_status)  { $set[]='status=?';  $vals[] = $status; $types.='s'; }
    $vals[] = $job_id; $types.='i';
    $vals[] = $PHOTOGRAPHER_ID; $types.='i';
    $sql = "UPDATE job SET ".implode(',', $set)." WHERE job_id=? AND photographer_id=? LIMIT 1";
    if ($stmt=$conn->prepare($sql)) { stmt_bind_params($stmt,$types,$vals); if ($stmt->execute()) { flash_set('บันทึกแล้ว'); } else { flash_set('บันทึกไม่สำเร็จ'); } $stmt->close(); } else { flash_set('บันทึกไม่สำเร็จ'); }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }
  if ($act === 'delete') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    if ($job_id<=0) { flash_set('ลบไม่สำเร็จ'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
    $sql = "DELETE FROM job WHERE job_id=? AND photographer_id=? LIMIT 1";
    if ($stmt=$conn->prepare($sql)) { $stmt->bind_param("ii",$job_id,$PHOTOGRAPHER_ID); if ($stmt->execute() && $stmt->affected_rows>0) { flash_set('ลบงานแล้ว'); } else { flash_set('ลบไม่สำเร็จ'); } $stmt->close(); } else { flash_set('ลบไม่สำเร็จ'); }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }
}
$edit_mode = false; $edit_row = null;
if (($_GET['action'] ?? '') === 'edit') {
  $eid = (int)($_GET['id'] ?? 0);
  if ($eid>0) {
    $selectCols = "job_id,job_date,job_time,location";
    if ($job_has_user_id) $selectCols .= ",user_id";
    if ($job_has_status)  $selectCols .= ",status";
    $sql = "SELECT $selectCols FROM job WHERE job_id=? AND photographer_id=? LIMIT 1";
    if ($stmt=$conn->prepare($sql)) { $stmt->bind_param("ii",$eid,$PHOTOGRAPHER_ID); $stmt->execute(); $rs = $stmt->get_result(); if ($rs && $rs->num_rows===1) { $edit_mode=true; $edit_row=$rs->fetch_assoc(); } $stmt->close(); }
  }
}
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$fstat = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 10;
$w = ["photographer_id=?"]; $params=[ $PHOTOGRAPHER_ID ]; $pt='i';
if ($from !== '') { $w[]="job_date>=?"; $params[]=$from; $pt.='s'; }
if ($to   !== '') { $w[]="job_date<=?"; $params[]=$to;   $pt.='s'; }
if ($q    !== '') { $w[]="location LIKE ?"; $params[]=('%'.$q.'%'); $pt.='s'; }
if ($job_has_status && $fstat!=='') { $w[]="status=?"; $params[]=$fstat; $pt.='s'; }
$where = 'WHERE '.implode(' AND ',$w);
$total = 0;
if ($stmt=$conn->prepare("SELECT COUNT(*) AS c FROM job $where")) { stmt_bind_params($stmt,$pt,$params); $stmt->execute(); $r=$stmt->get_result(); $total=(int)($r->fetch_assoc()['c'] ?? 0); $stmt->close(); }
$pages = max(1, (int)ceil($total/$per));
$offset = ($page-1)*$per;
$selectCols = "job_id,job_date,job_time,location";
if ($job_has_user_id) $selectCols .= ",user_id";
if ($job_has_status)  $selectCols .= ",status";
$sqlList = "SELECT $selectCols FROM job $where ORDER BY job_date ASC, job_time ASC, job_id ASC LIMIT ? OFFSET ?";
$params2 = $params; $pt2=$pt.'ii'; $params2[]=$per; $params2[]=$offset;
$rows = [];
if ($stmt=$conn->prepare($sqlList)) { stmt_bind_params($stmt,$pt2,$params2); $stmt->execute(); $rs=$stmt->get_result(); while($rs && ($row=$rs->fetch_assoc())) { $rows[]=$row; } $stmt->close(); }
$sumSql = "SELECT
  SUM(CASE WHEN ".($job_has_status?"status":"''")." LIKE 'รอดำเนินการ%' THEN 1 ELSE 0 END) AS pending_cnt,
  SUM(CASE WHEN ".($job_has_status?"status":"''")." LIKE 'ยืนยัน%' THEN 1 ELSE 0 END) AS ok_cnt,
  SUM(CASE WHEN ".($job_has_status?"status":"''")." LIKE 'ยกเลิก%' THEN 1 ELSE 0 END) AS cancel_cnt,
  SUM(CASE WHEN ".($job_has_status?"status":"''")." LIKE 'เสร็จ%' THEN 1 ELSE 0 END) AS done_cnt,
  COUNT(*) AS total_cnt
  FROM job $where";
$stm = $conn->prepare($sumSql); stmt_bind_params($stm,$pt,$params); $stm->execute(); $sum = $stm->get_result()->fetch_assoc() ?: ['pending_cnt'=>0,'ok_cnt'=>0,'cancel_cnt'=>0,'done_cnt'=>0,'total_cnt'=>0]; $stm->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>การจองคิว (ช่างภาพ)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
.navbar{width:100%;position:fixed;top:0;left:0;background:linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(156,142,142,.25);z-index:100}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px}
.page-title{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.page-title h1{margin:0;font-size:28px;font-weight:900;color:#0b1220}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.notice{padding:10px 12px;border-radius:12px;border:1px solid #d1fae5;background:#ecfdf5;color:#065f46;font-weight:800}
.error{padding:10px 12px;border-radius:12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-weight:800}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.sum{background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;display:grid;gap:6px;text-align:center}
.sum .num{font-size:20px;font-weight:900}
.s-pending{border-left:5px solid #0bbef5}
.s-ok{border-left:5px solid #10b981}
.s-cancel{border-left:5px solid #ef4444}
.s-done{border-left:5px solid #22c55e}
.s-total{border-left:5px solid #3b82f6}
@media (max-width:900px){ .summary{grid-template-columns:repeat(2,1fr)} }
@media (max-width:520px){ .summary{grid-template-columns:1fr} }
.filters{display:grid;gap:10px}
.filters .row{display:grid;grid-template-columns:1fr 160px 160px 170px auto;gap:8px}
.filters input,.filters select{padding:10px;border:1px solid #dbeafe;border-radius:10px;background:#fff}
.filters .btn{appearance:none;border:none;border-radius:10px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;padding:10px 16px;font-weight:900;cursor:pointer}
.filters .reset{color:#6b7280;text-decoration:none;align-self:center}
.grid{display:grid;gap:10px}
.grid.cols-3{grid-template-columns:repeat(3,1fr)}
@media (max-width:900px){ .grid.cols-3{grid-template-columns:1fr} }
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px dashed #e5e7eb;text-align:left}
.table th{color:#374151;font-weight:800}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:800;font-size:12px;border:1px solid #e5e7eb}
.badge.pending{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.badge.ok{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
.badge.cancel{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.badge.done{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.badge.muted{background:#f3f4f6;color:#374151}
.inline-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
.select-mini{padding:6px 8px;border:1px solid #dbeafe;border-radius:8px;background:#fff}
.btn-mini{appearance:none;border:1px solid #dbeafe;border-radius:8px;background:#f8fafc;color:#2575fc;padding:6px 10px;font-weight:800;cursor:pointer}
.btn-mini.primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.row-actions{display:flex;gap:6px;flex-wrap:wrap}
.pager{display:flex;gap:6px;justify-content:center;margin-top:10px}
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;background:#fff;color:#111}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.form-title{font-weight:900;margin-bottom:10px}
.input,select{width:100%}
</style>
</head>
<body>
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($firstName) ?></span>
      <a href="photographer_bookings.php">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั่วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="photographer_bookings_crud.php" class="active">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">การมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>
<div class="wrapper">
  <div class="container">
    <div class="page-title"><h1>การจองคิว</h1></div>
    <?php if ($m = flash_pop()): ?><div class="card notice"><?=h($m)?></div><?php endif; ?>
    <section class="card">
      <div class="summary">
        <div class="sum s-total"><div>ทั้งหมด</div><div class="num"><?= (int)$sum['total_cnt'] ?></div></div>
        <div class="sum s-pending"><div>รอดำเนินการ</div><div class="num"><?= (int)$sum['pending_cnt'] ?></div></div>
        <div class="sum s-ok"><div>ยืนยันแล้ว</div><div class="num"><?= (int)$sum['ok_cnt'] ?></div></div>
        <div class="sum s-cancel"><div>ยกเลิก</div><div class="num"><?= (int)$sum['cancel_cnt'] ?></div></div>
        <div class="sum s-done"><div>เสร็จสิ้น</div><div class="num"><?= (int)$sum['done_cnt'] ?></div></div>
      </div>
    </section>
    <form class="card filters" method="get">
      <div class="row">
        <input type="text" name="q" placeholder="ค้นหาสถานที่..." value="<?= h($q) ?>">
        <input type="date" name="from" value="<?= h($from) ?>">
        <input type="date" name="to" value="<?= h($to) ?>">
        <select name="status">
          <option value="">— ทุกสถานะ —</option>
          <?php if ($job_has_status): foreach($STATUS_OPT as $st): $sel = ($fstat===$st)?'selected':''; ?>
          <option value="<?=h($st)?>" <?=$sel?>><?=h($st)?></option>
          <?php endforeach; endif; ?>
        </select>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="btn" type="submit">ค้นหา</button>
          <a class="reset" href="<?=h($_SERVER['PHP_SELF'])?>">ล้างค่า</a>
        </div>
      </div>
    </form>
    <section class="card">
      <div class="form-title"><?= $edit_mode? 'แก้ไขงาน #'.h($edit_row['job_id']) : 'เพิ่มงานใหม่' ?></div>
      <form method="post" class="grid cols-3">
        <input type="hidden" name="csrf_token" value="<?=h($csrf_token)?>">
        <?php if ($edit_mode): ?>
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="job_id" value="<?= (int)$edit_row['job_id'] ?>">
        <?php else: ?>
          <input type="hidden" name="act" value="create">
        <?php endif; ?>
        <div><input class="input" type="date" name="job_date" value="<?=h($edit_mode ? $edit_row['job_date'] : date('Y-m-d'))?>" required></div>
        <div><input class="input" type="time" name="job_time" value="<?=h($edit_mode ? substr((string)$edit_row['job_time'],0,5) : '09:00')?>" required></div>
        <div><input class="input" type="text" name="location" value="<?=h($edit_mode ? ($edit_row['location'] ?? '') : '')?>" placeholder="สถานที่ถ่ายทำ" required></div>
        <?php if ($job_has_user_id): ?>
        <div><input class="input" type="number" name="user_id" value="<?=h($edit_mode ? (int)($edit_row['user_id'] ?? 0) : '')?>" min="1" placeholder="user_id (เว้นว่างได้)"></div>
        <?php endif; ?>
        <?php if ($job_has_status): ?>
        <div>
          <select name="status" class="input">
            <?php $cur = $edit_mode ? ($edit_row['status'] ?? $STATUS_OPT[0]) : $STATUS_OPT[0]; foreach($STATUS_OPT as $st){ $sel = ($cur===$st)?'selected':''; echo '<option value="'.h($st).'" '.$sel.'>'.h($st).'</option>'; } ?>
          </select>
        </div>
        <?php endif; ?>
        <div><button class="btn" type="submit"><?= $edit_mode? 'บันทึกการแก้ไข' : 'เพิ่มงาน' ?></button> <?php if ($edit_mode): ?><a class="reset" href="<?=h($_SERVER['PHP_SELF'])?>">ยกเลิก</a><?php endif; ?></div>
      </form>
    </section>
    <section class="card">
      <?php if ($rows): ?>
        <div style="overflow-x:auto">
          <table class="table">
            <thead>
              <tr>
                <th style="min-width:80px">ID</th>
                <th style="min-width:110px">วันที่</th>
                <th style="min-width:90px">เวลา</th>
                <th style="min-width:220px">สถานที่</th>
                <?php if ($job_has_user_id): ?><th style="min-width:100px">user_id</th><?php endif; ?>
                <?php if ($job_has_status): ?><th style="min-width:140px">สถานะ</th><?php endif; ?>
                <th style="min-width:200px">การทำงาน</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r):
                $st = (string)($r['status'] ?? '');
                $cls = 'muted';
                if (strpos($st,'รอดำเนินการ')===0) $cls='pending';
                elseif (strpos($st,'ยืนยัน')===0) $cls='ok';
                elseif (strpos($st,'ยกเลิก')===0) $cls='cancel';
                elseif (strpos($st,'เสร็จ')===0) $cls='done';
              ?>
              <tr>
                <td>#<?= (int)$r['job_id'] ?></td>
                <td><?= h(date('d/m/Y', strtotime($r['job_date']))) ?></td>
                <td><?= h(substr((string)$r['job_time'],0,5)) ?></td>
                <td><?= h($r['location']) ?></td>
                <?php if ($job_has_user_id): ?><td><?= h((string)($r['user_id'] ?? '')) ?></td><?php endif; ?>
                <?php if ($job_has_status): ?><td><span class="badge <?=$cls?>"><?= h($st ?: '-') ?></span></td><?php endif; ?>
                <td class="row-actions">
                  <a class="btn-mini" href="<?=h($_SERVER['PHP_SELF'])?>?action=edit&id=<?= (int)$r['job_id'] ?>">แก้ไข</a>
                  <form method="post" onsubmit="return confirm('ลบงานนี้?');" style="display:inline-flex;gap:6px;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?=h($csrf_token)?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="job_id" value="<?= (int)$r['job_id'] ?>">
                    <button class="btn-mini" type="submit">ลบ</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($pages > 1): ?>
          <div class="pager">
            <?php for($i=1;$i<=$pages;$i++): $qs = $_GET; $qs['page'] = $i; $link = $_SERVER['PHP_SELF'].'?'.http_build_query($qs); if ($i==$page): ?>
              <span class="active"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= h($link) ?>"><?= $i ?></a>
            <?php endif; endfor; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div style="text-align:center;color:#6b7280">ไม่มีข้อมูล</div>
      <?php endif; ?>
    </section>
  </div>
</div>
</body>
</html>
