<?php
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn,'utf8mb4');

date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['admin_id'])) { header('Location: login_admin.php'); exit; }
$ADMIN_ID   = (int)($_SESSION['admin_id'] ?? 0);
$ADMIN_NAME = $_SESSION['admin_name'] ?? 'Admin';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c,string $t,string $col):bool{
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $q->bind_param('ss',$t,$col); $q->execute(); $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

/* ===== ตรวจ schema ที่ต้องใช้ ===== */
$job_has_user_id = has_col($conn,'job','user_id');
$job_has_status  = has_col($conn,'job','status');
$job_has_note    = has_col($conn,'job','note');

$STATUS_CHOICES  = ['รอดำเนินการ','ยืนยันแล้ว','ยกเลิก','เสร็จสิ้น'];

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_job'])) { $_SESSION['csrf_job'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_job'];
function csrf_ok($t){ return hash_equals($_SESSION['csrf_job'] ?? '', $t ?? ''); }

/* ===== Helper: ตรวจ conflict เวลาจอง ===== */
function job_conflict(mysqli $conn, int $photographer_id, string $date, string $time, ?int $exclude_id=null, bool $has_status=false): bool{
  if ($has_status) {
    $sql = "SELECT job_id FROM job WHERE photographer_id=? AND job_date=? AND job_time=? AND status <> 'ยกเลิก'".
           ($exclude_id?" AND job_id<>?":"")." LIMIT 1";
  } else {
    $sql = "SELECT job_id FROM job WHERE photographer_id=? AND job_date=? AND job_time=?".
           ($exclude_id?" AND job_id<>?":"")." LIMIT 1";
  }
  if ($exclude_id){ $st=$conn->prepare($sql); $st->bind_param('issi',$photographer_id,$date,$time,$exclude_id); }
  else { $st=$conn->prepare($sql); $st->bind_param('iss',$photographer_id,$date,$time); }
  $st->execute(); $res=$st->get_result(); $found=(bool)$res->fetch_row(); $st->close(); return $found;
}

/* ===== โหลดรายชื่อช่างภาพ/ผู้ใช้ ===== */
$photographers = [];
if ($rs = $conn->query("SELECT photographer_id, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS name FROM photographer ORDER BY first_name, last_name")){
  while($row = $rs->fetch_assoc()) $photographers[(int)$row['photographer_id']] = trim($row['name']);
  $rs->close();
}
$users = [];
if ($job_has_user_id){
  if ($rs = $conn->query("SELECT user_id, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS name FROM users ORDER BY first_name, last_name LIMIT 500")){
    while($row = $rs->fetch_assoc()) $users[(int)$row['user_id']] = trim($row['name']);
    $rs->close();
  }
}

/* ===== จัดการคำสั่งเพิ่ม/แก้ไข/ลบ ===== */
$flash_success = '';
$flash_error   = '';

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!csrf_ok($_POST['csrf'] ?? '')){ $flash_error='คำขอไม่ถูกต้อง (CSRF)'; }
  else {
    $action = $_POST['action'] ?? '';

    if ($action==='create' || $action==='update'){
      $job_id          = (int)($_POST['job_id'] ?? 0);
      $photographer_id = (int)($_POST['photographer_id'] ?? 0);
      $user_id         = $job_has_user_id ? (int)($_POST['user_id'] ?? 0) : null;
      $job_date        = trim($_POST['job_date'] ?? '');   // YYYY-MM-DD
      $job_time        = trim($_POST['job_time'] ?? '');   // HH:MM
      $location        = trim($_POST['location'] ?? '');
      $note            = $job_has_note ? trim($_POST['note'] ?? '') : '';
      $status          = $job_has_status ? trim($_POST['status'] ?? '') : '';

      if (!$photographer_id || $job_date==='' || $job_time==='' || $location===''){
        $flash_error = 'กรุณากรอกข้อมูลให้ครบ: ช่างภาพ / วันที่ / เวลา / สถานที่';
      } elseif (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$job_date) || !preg_match('/^\\d{2}:\\d{2}$/',$job_time)){
        $flash_error = 'รูปแบบวันที่/เวลาไม่ถูกต้อง';
      } elseif ($job_has_status && $status!=='' && !in_array($status,$STATUS_CHOICES,true)){
        $flash_error = 'สถานะไม่ถูกต้อง';
      } elseif (job_conflict($conn,$photographer_id,$job_date,$job_time, $action==='update'?$job_id:null, $job_has_status)){
        $flash_error = 'เวลา/วันที่นี้มีการจองของช่างภาพคนนี้อยู่แล้ว';
      } else {
        if ($action==='create'){
          $cols = ['photographer_id','job_date','job_time','location'];
          $vals = ['i','s','s','s'];
          $data = [$photographer_id,$job_date,$job_time,$location];
          if ($job_has_user_id){ $cols[]='user_id'; $vals[]='i'; $data[]=$user_id ?: null; }
          if ($job_has_status){ $cols[]='status';  $vals[]='s'; $data[]=$status ?: 'รอดำเนินการ'; }
          if ($job_has_note){   $cols[]='note';    $vals[]='s'; $data[]=$note; }
          $sql = 'INSERT INTO job ('.implode(',',$cols).') VALUES ('.implode(',', array_fill(0,count($cols),'?')).')';
          $st  = $conn->prepare($sql);
          $st->bind_param(implode('',$vals), ...$data);
          if($st->execute()){
            $flash_success = 'เพิ่มการจองสำเร็จ (#'.(int)$st->insert_id.')';
          } else {
            $flash_error = 'เพิ่มไม่สำเร็จ: '.$st->error;
          }
          $st->close();
        } else {
          if ($job_id<=0){ $flash_error='ไม่พบรหัสงาน'; }
          else {
            $sets = ['photographer_id=?','job_date=?','job_time=?','location=?'];
            $vals = ['i','s','s','s'];
            $data = [$photographer_id,$job_date,$job_time,$location];
            if ($job_has_user_id){ $sets[]='user_id=?'; $vals[]='i'; $data[]=$user_id ?: null; }
            if ($job_has_status){ $sets[]='status=?';  $vals[]='s'; $data[]=$status ?: null; }
            if ($job_has_note){   $sets[]='note=?';    $vals[]='s'; $data[]=$note; }
            $vals[]='i'; $data[]=$job_id;
            $sql = 'UPDATE job SET '.implode(',',$sets).' WHERE job_id=?';
            $st  = $conn->prepare($sql);
            $st->bind_param(implode('',$vals), ...$data);
            if($st->execute()){
              $flash_success = 'บันทึกการแก้ไขแล้ว (#'.$job_id.')';
            } else { $flash_error = 'แก้ไขไม่สำเร็จ: '.$st->error; }
            $st->close();
          }
        }
      }
    }
    elseif ($action==='delete'){
      $job_id = (int)($_POST['job_id'] ?? 0);
      if ($job_id<=0){ $flash_error='ไม่พบรหัสงานที่จะลบ'; }
      else {
        $st = $conn->prepare('DELETE FROM job WHERE job_id=? LIMIT 1');
        $st->bind_param('i',$job_id);
        if($st->execute()){ $flash_success='ลบสำเร็จ (#'.$job_id.')'; }
        else { $flash_error='ลบไม่สำเร็จ: '.$st->error; }
        $st->close();
      }
    }
  }
}

/* ===== ค้นหา/กรอง ===== */
$q       = trim($_GET['q'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$phf     = (int)($_GET['photographer_id'] ?? 0);
$statusf = $job_has_status ? trim($_GET['status'] ?? '') : '';

$where = [];
$argsT = '';
$argsV = [];

if ($q!==''){
  $where[] = '(location LIKE CONCAT("%",? ,"%"))'; $argsT.='s'; $argsV[]=$q;
}
if ($from!==''){ $where[]='job_date >= ?'; $argsT.='s'; $argsV[]=$from; }
if ($to!==''){   $where[]='job_date <= ?'; $argsT.='s'; $argsV[]=$to; }
if ($phf>0){     $where[]='photographer_id = ?'; $argsT.='i'; $argsV[]=$phf; }
if ($job_has_status && $statusf!==''){ $where[]='status = ?'; $argsT.='s'; $argsV[]=$statusf; }

$sqlWhere = $where? ('WHERE '.implode(' AND ',$where)) : '';

/* ===== หน้า/แบ่งหน้า ===== */
$page = max(1,(int)($_GET['page'] ?? 1));
$per  = 12;
$off  = ($page-1)*$per;

$sqlCount = "SELECT COUNT(*) FROM job $sqlWhere";
$st = $conn->prepare($sqlCount);
if ($argsT!==''){ $st->bind_param($argsT, ...$argsV); }
$st->execute(); $st->bind_result($total_rows); $st->fetch(); $st->close();
$total_pages = max(1,(int)ceil($total_rows/$per));

$sqlList = "SELECT j.job_id, j.job_date, j.job_time, j.location".
           ($job_has_status?", j.status":"").
           ($job_has_user_id?", j.user_id":"").
           ", j.photographer_id,
              CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,'')) AS p_name".
           ($job_has_user_id?", CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS u_name":"").
           ($job_has_note?", j.note":"").
           " FROM job j
              LEFT JOIN photographer p ON j.photographer_id = p.photographer_id".
           ($job_has_user_id?" LEFT JOIN users u ON j.user_id = u.user_id":"").
           " $sqlWhere
              ORDER BY j.job_date DESC, j.job_time DESC, j.job_id DESC
              LIMIT $per OFFSET $off";

$st = $conn->prepare($sqlList);
if ($argsT!==''){ $st->bind_param($argsT, ...$argsV); }
$st->execute(); $res = $st->get_result();
$rows = [];
while($row = $res->fetch_assoc()) $rows[]=$row;
$st->close();

/* ===== HTML ===== */
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>งาน/จองคิวช่างภาพ (แอดมิน)</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
.navbar{width:100%;position:fixed;top:0;left:0;background: linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
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
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900}
.header-actions{display:flex;align-items:center;gap:8px}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-danger{background:linear-gradient(90deg,#ef4444,#dc2626)}
.btn-ghost{background:#f8fafc;color:#0f172a;border:1px solid #e5e7eb}
.btn-sm{padding:6px 10px;border-radius:10px}
.table-wrap{overflow:auto;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
table{width:100%;border-collapse:collapse;min-width:980px;background:#fff}
thead th{background:#eef2ff;color:#0b1220;text-align:left;padding:12px;font-weight:900}
tbody td{padding:12px;border-top:1px solid #eee;vertical-align:middle}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:800;font-size:12px;border:1px solid #e5e7eb}
.badge.wait{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.badge.ok{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
.badge.cancel{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.badge.muted{background:#f3f4f6;color:#374151}
.grid{display:grid;gap:12px}
.grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
label{font-size:12px;color:#6b7280;display:block;margin-bottom:4px}
input,select,textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;color:#0f172a}
textarea{min-height:70px;resize:vertical}
.note{color:#64748b;font-size:13px}
.pagination a{margin:0 4px;padding:6px 10px;border-radius:10px;border:1px solid #e5e7eb;color:#0b122a;text-decoration:none}
.pagination .current{background:#3b82f6;border-color:#3b82f6;color:#fff;font-weight:900}
@media (max-width:900px){.grid-3{grid-template-columns:1fr}.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($ADMIN_NAME) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
      <a href="#" class="active">การจองคิว</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">

    <section class="card" style="margin-bottom:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <h2>➕ เพิ่มการจองใหม่</h2>
      </div>
      <?php if($flash_success): ?><div class="note" style="border:1px solid #bbf7d0;background:#ecfdf5;color:#065f46;border-radius:12px;padding:10px 12px;margin-top:8px"><?php echo h($flash_success); ?></div><?php endif; ?>
      <?php if($flash_error): ?><div class="note" style="border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:12px;padding:10px 12px;margin-top:8px"><?php echo h($flash_error); ?></div><?php endif; ?>

      <form method="post" class="grid grid-3" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="create">
        <div>
          <label>ช่างภาพ</label>
          <select name="photographer_id" required>
            <option value="">— เลือกช่างภาพ —</option>
            <?php foreach($photographers as $pid=>$pname): ?>
              <option value="<?php echo (int)$pid; ?>"><?php echo h($pname.' (#'.$pid.')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($job_has_user_id): ?>
        <div>
          <label>ผู้จ้าง (ผู้ใช้)</label>
          <select name="user_id">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach($users as $uid=>$uname): ?>
              <option value="<?php echo (int)$uid; ?>"><?php echo h($uname.' (#'.$uid.')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div>
          <label>วันที่</label>
          <input type="date" name="job_date" required>
        </div>
        <div>
          <label>เวลา</label>
          <input type="time" name="job_time" required>
        </div>
        <div>
          <label>สถานที่</label>
          <input type="text" name="location" placeholder="เช่น CentralWorld, กรุงเทพฯ" required>
        </div>
        <?php if($job_has_status): ?>
        <div>
          <label>สถานะ</label>
          <select name="status">
            <?php foreach($STATUS_CHOICES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo $st==='รอดำเนินการ'?'selected':''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if($job_has_note): ?>
        <div style="grid-column:1/-1">
          <label>บันทึก/หมายเหตุ</label>
          <textarea name="note" placeholder="รายละเอียดเพิ่มเติม"></textarea>
        </div>
        <?php endif; ?>
        <div style="grid-column:1/-1; display:flex; gap:8px; justify-content:flex-end; margin-top:4px">
          <button class="btn btn-ghost" type="reset">ล้างค่า</button>
          <button class="btn" type="submit">เพิ่มการจอง</button>
        </div>
      </form>
    </section>

    <section class="card" style="margin-bottom:8px">
      <h2>🔎 ค้นหา/กรองรายการ</h2>
      <form method="get" class="grid grid-3" style="margin-top:12px">
        <div>
          <label>คำค้น (สถานที่)</label>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="เช่น Bangkok, Studio A">
        </div>
        <div>
          <label>จากวันที่</label>
          <input type="date" name="from" value="<?php echo h($from); ?>">
        </div>
        <div>
          <label>ถึงวันที่</label>
          <input type="date" name="to" value="<?php echo h($to); ?>">
        </div>
        <div>
          <label>ช่างภาพ</label>
          <select name="photographer_id">
            <option value="0">— ทั้งหมด —</option>
            <?php foreach($photographers as $pid=>$pname): ?>
              <option value="<?php echo (int)$pid; ?>" <?php echo $phf===$pid?'selected':''; ?>><?php echo h($pname); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($job_has_status): ?>
        <div>
          <label>สถานะ</label>
          <select name="status">
            <option value="">— ทั้งหมด —</option>
            <?php foreach($STATUS_CHOICES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo $statusf===$st?'selected':''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div style="display:flex; gap:8px; align-items:flex-end;">
          <button class="btn" type="submit">กรอง</button>
          <a class="btn btn-ghost" href="<?php echo h(basename(__FILE__)); ?>">ล้างตัวกรอง</a>
        </div>
      </form>
    </section>

    <section class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h2>📋 รายการจองทั้งหมด</h2>
        <div class="note">ผลลัพธ์ทั้งหมด: <?php echo (int)$total_rows; ?> รายการ · หน้า <?php echo (int)$page; ?>/<?php echo (int)$total_pages; ?></div>
      </div>
      <div class="table-wrap" style="margin-top:12px">
        <table>
          <thead>
            <tr>
              <th style="width:80px">#</th>
              <th style="width:130px">วันที่</th>
              <th style="width:90px">เวลา</th>
              <th>สถานที่</th>
              <th style="width:220px">ช่างภาพ</th>
              <?php if($job_has_user_id): ?><th style="width:220px">ผู้ใช้</th><?php endif; ?>
              <?php if($job_has_status): ?><th style="width:160px">สถานะ</th><?php endif; ?>
              <?php if($job_has_note): ?><th>หมายเหตุ</th><?php endif; ?>
              <th style="width:220px">การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$rows): ?>
              <tr><td colspan="9" style="text-align:center;color:#9ca3af">— ไม่มีข้อมูล —</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['job_id']; ?></td>
                <td><?php echo h($r['job_date']); ?></td>
                <td><?php echo h(substr($r['job_time'],0,5)); ?></td>
                <td><?php echo h($r['location']); ?></td>
                <td><?php echo h(($r['p_name'] ?: 'ไม่พบชื่อ').' (#'.(int)$r['photographer_id'].')'); ?></td>
                <?php if($job_has_user_id): ?>
                  <td><?php echo h(($r['u_name'] ?: 'ไม่ระบุ').($r['user_id']? ' (#'.(int)$r['user_id'].')':'')); ?></td>
                <?php endif; ?>
                <?php if($job_has_status): ?>
                  <td>
                    <?php $st = (string)($r['status'] ?? '');
                          $cls = ($st==='ยืนยันแล้ว')?'ok':(($st==='ยกเลิก')?'cancel':'wait'); ?>
                    <span class="badge <?php echo $cls; ?>"><?php echo h($st ?: '—'); ?></span>
                  </td>
                <?php endif; ?>
                <?php if($job_has_note): ?>
                  <td><?php echo h($r['note'] ?? ''); ?></td>
                <?php endif; ?>
                <td>
                  <details>
                    <summary class="btn btn-ghost" style="display:inline-block">แก้ไข</summary>
                    <form method="post" class="grid grid-2" style="margin-top:10px; min-width:300px">
                      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="job_id" value="<?php echo (int)$r['job_id']; ?>">
                      <div>
                        <label>ช่างภาพ</label>
                        <select name="photographer_id" required>
                          <?php foreach($photographers as $pid=>$pname): ?>
                            <option value="<?php echo (int)$pid; ?>" <?php echo ((int)$r['photographer_id']===$pid)?'selected':''; ?>><?php echo h($pname); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php if($job_has_user_id): ?>
                      <div>
                        <label>ผู้ใช้</label>
                        <select name="user_id">
                          <option value="">— ไม่ระบุ —</option>
                          <?php foreach($users as $uid=>$uname): ?>
                            <option value="<?php echo (int)$uid; ?>" <?php echo ((int)($r['user_id'] ?? 0)===$uid)?'selected':''; ?>><?php echo h($uname); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php endif; ?>
                      <div>
                        <label>วันที่</label>
                        <input type="date" name="job_date" value="<?php echo h($r['job_date']); ?>" required>
                      </div>
                      <div>
                        <label>เวลา</label>
                        <input type="time" name="job_time" value="<?php echo h(substr($r['job_time'],0,5)); ?>" required>
                      </div>
                      <div style="grid-column:1/-1">
                        <label>สถานที่</label>
                        <input type="text" name="location" value="<?php echo h($r['location']); ?>" required>
                      </div>
                      <?php if($job_has_status): ?>
                      <div>
                        <label>สถานะ</label>
                        <select name="status">
                          <option value="">— ไม่ระบุ —</option>
                          <?php foreach($STATUS_CHOICES as $stc): ?>
                            <option value="<?php echo h($stc); ?>" <?php echo ($r['status'] ?? '')===$stc?'selected':''; ?>><?php echo h($stc); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php endif; ?>
                      <?php if($job_has_note): ?>
                      <div style="grid-column:1/-1">
                        <label>หมายเหตุ</label>
                        <textarea name="note"><?php echo h($r['note'] ?? ''); ?></textarea>
                      </div>
                      <?php endif; ?>
                      <div class="" style="grid-column:1/-1; display:flex; justify-content:flex-end; gap:8px">
                        <button class="btn btn-ghost" type="reset">คืนค่า</button>
                        <button class="btn" type="submit">บันทึก</button>
                      </div>
                    </form>
                    <form method="post" onsubmit="return confirm('ลบรายการนี้หรือไม่?');" style="margin-top:8px">
                      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="job_id" value="<?php echo (int)$r['job_id']; ?>">
                      <button class="btn btn-danger" type="submit">ลบ</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination" style="margin-top:10px;display:flex;flex-wrap:wrap;align-items:center;gap:6px">
        <?php
          // คงตัวกรองในลิงก์แบ่งหน้า
          $params = $_GET; unset($params['page']);
          for($p=1;$p<=$total_pages;$p++){
            $params['page']=$p; $url='?'.http_build_query($params);
            if($p==$page) echo '<span class="current">'.$p.'</span>';
            else echo '<a href="'.h($url).'">'.$p.'</a>';
          }
        ?>
      </div>
    </section>

  </div>
</div>

</body>
</html>
