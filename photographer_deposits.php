<?php
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn,'utf8mb4');

if (!isset($_SESSION['photographer_id'])) {
  header("Location: login_photographer.php"); exit;
}
$PHOTOGRAPHER_ID = (int)$_SESSION['photographer_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c,string $t,string $col):bool{
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $q->bind_param("ss",$t,$col); $q->execute();
  $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

$hasPayStat  = has_col($conn,'job','payment_status');
$hasPayProof = has_col($conn,'job','payment_proof');
$hasPayNote  = has_col($conn,'job','payment_note');
$hasPayAt    = has_col($conn,'job','payment_checked_at');

if (empty($_SESSION['csrf_pay'])) { $_SESSION['csrf_pay'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_pay'];
function csrf_ok($t){ return hash_equals($_SESSION['csrf_pay'] ?? '', $t ?? ''); }

$status = $_GET['status'] ?? 'waiting';
$q      = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));
$per    = 12;
$err=''; $ok='';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && isset($_POST['job_id'])) {
  if (!csrf_ok($_POST['csrf'] ?? '')) {
    $err = 'คำขอไม่ถูกต้อง (CSRF)';
  } elseif (!$hasPayStat) {
    $err = 'ไม่พบคอลัมน์ payment_status ในตาราง job';
  } else {
    $job_id = (int)$_POST['job_id'];
    $note   = trim($_POST['note'] ?? '');
    $act    = $_POST['action'];

    $chk = $conn->prepare("SELECT job_id, payment_status, payment_proof FROM job WHERE job_id=? AND photographer_id=? LIMIT 1");
    $chk->bind_param("ii",$job_id,$PHOTOGRAPHER_ID);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
      $err = 'ไม่พบงานนี้ในบัญชีช่างภาพของคุณ';
    } elseif (empty($row['payment_proof'])) {
      $err = 'งานนี้ยังไม่มีหลักฐานการโอน';
    } elseif ($row['payment_status'] !== 'waiting') {
      $err = 'สถานะปัจจุบันไม่ได้อยู่ใน waiting';
    } else {
      if ($act==='approve' || $act==='reject') {
        $newStatus = ($act==='approve') ? 'approved' : 'rejected';
        $sql = "UPDATE job SET payment_status=?";
        $types = "s";
        $vals  = [$newStatus];
        if ($hasPayNote) { $sql .= ", payment_note=?"; $types.="s"; $vals[]=$note; }
        if ($hasPayAt)   { $sql .= ", payment_checked_at=NOW()"; }
        $sql .= " WHERE job_id=? AND photographer_id=?";
        $types .= "ii";
        $vals[] = $job_id; $vals[] = $PHOTOGRAPHER_ID;
        $up = $conn->prepare($sql);
        $bind = [$types];
        foreach ($vals as $k=>$v){ $bind[] = &$vals[$k]; }
        call_user_func_array([$up,'bind_param'],$bind);
        if ($up->execute()) {
          $ok = ($newStatus==='approved' ? '✅ อนุมัติแล้ว' : '⛔ ปฏิเสธแล้ว');
        } else {
          $err = 'อัปเดตไม่สำเร็จ: '.$up->error;
        }
        $up->close();
      } else {
        $err = 'Action ไม่ถูกต้อง';
      }
    }
  }
}

$where = " j.photographer_id=? ";
$params = [$PHOTOGRAPHER_ID];
$types  = "i";

if ($status && $status!=='all' && in_array($status, ['waiting','approved','rejected'], true) && $hasPayStat) {
  $where .= " AND j.payment_status=? ";
  $params[] = $status; $types .= "s";
}
if ($q!=='') {
  $where .= " AND ( j.location LIKE CONCAT('%',?,'%') OR u.first_name LIKE CONCAT('%',?,'%') OR u.last_name LIKE CONCAT('%',?,'%') ) ";
  $params[]=$q; $params[]=$q; $params[]=$q; $types.="sss";
}

$count_sql = "SELECT COUNT(*) AS c FROM job j LEFT JOIN users u ON u.user_id = j.user_id WHERE $where";
$stmt = $conn->prepare($count_sql);
$bind = [$types];
foreach ($params as $k=>$v){ $bind[] = &$params[$k]; }
call_user_func_array([$stmt,'bind_param'],$bind);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$pages = max(1, (int)ceil($total/$per));
$off   = ($page-1)*$per;

$list_sql = "
  SELECT
    j.job_id, j.job_date, j.job_time, j.location, j.status,
    ".($hasPayStat?"j.payment_status":"NULL AS payment_status").",
    ".($hasPayProof?"j.payment_proof":"NULL AS payment_proof").",
    ".($hasPayNote?"j.payment_note":"NULL AS payment_note").",
    ".($hasPayAt  ?"j.payment_checked_at":"NULL AS payment_checked_at").",
    u.first_name, u.last_name, u.email
  FROM job j
  LEFT JOIN users u ON u.user_id = j.user_id
  WHERE $where
  ORDER BY (j.payment_status IS NULL), j.payment_status ASC, j.job_date DESC, j.job_time DESC, j.job_id DESC
  LIMIT ?, ?
";
$params2 = $params; $types2 = $types."ii"; $params2[]=$off; $params2[]=$per;

$stmt = $conn->prepare($list_sql);
$bind2 = [$types2];
foreach ($params2 as $k=>$v){ $bind2[] = &$params2[$k]; }
call_user_func_array([$stmt,'bind_param'],$bind2);
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

function payBadge($s){
  return match($s){
    'waiting'  => '<span class="pill pill-wait">รอตรวจ</span>',
    'approved' => '<span class="pill pill-ok">อนุมัติแล้ว</span>',
    'rejected' => '<span class="pill pill-no">ปฏิเสธ</span>',
    default    => '<span class="pill">—</span>'
  };
}

$stmtP = $conn->prepare("SELECT first_name FROM photographer WHERE photographer_id=? LIMIT 1");
$stmtP->bind_param("i",$PHOTOGRAPHER_ID);
$stmtP->execute();
$firstName = $stmtP->get_result()->fetch_assoc()['first_name'] ?? '';
$stmtP->close();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>มัดจำ/ชำระเงินของลูกค้า | ช่างภาพ</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%)}

/* ==== NAVBAR: ทำให้เมนูอยู่ "บรรทัดเดียว" เท่านั้น (ส่วนอื่นไม่เปลี่ยน) ==== */
.navbar{
  width:100%;
  position:sticky; top:0; left:0;
  background:linear-gradient(90deg,#0b1220,#111827);
  box-shadow:0 4px 20px rgba(0,0,0,.25);
  z-index:100;
}
.nav-inner{
  max-width:1280px;
  margin:0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between; /* โลโก้ซ้าย เมนูขวา */
  padding:16px 36px;
}
.logo{
  font-size:24px;font-weight:800;color:#fff;
  display:flex;align-items:center;gap:12px;
  margin:0;
}
/* <<< เปลี่ยนเฉพาะเมนูให้ไม่ตัดบรรทัด >>> */
.menu{
  display:flex;align-items:center;justify-content:flex-end;
  gap:14px;
  flex-wrap:nowrap;        /* บังคับบรรทัดเดียว */
  white-space:nowrap;      /* กันข้อความภายในตัดบรรทัด */
  max-width:100%;
}
.menu a,.menu .badge{
  color:#fff;text-decoration:none;padding:9px 14px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px
}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}

/* ==== PAGE LAYOUT (เดิม) ==== */
.container{max-width:1100px;margin:24px auto;padding:0 14px}
.card{background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
h1{margin:0 0 12px 0;color:#0b1220;font-weight:900}
.filter{display:grid;grid-template-columns:1fr 220px 120px;gap:10px;margin-bottom:12px}
input[type=text],select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.btn{padding:10px 14px;border:none;border-radius:12px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;font-weight:900;cursor:pointer}
.btn-small{padding:7px 10px;border-radius:10px;font-weight:800}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.item{border:1px solid #e5e7eb;border-radius:16px;padding:12px;background:#fff;box-shadow:0 8px 18px rgba(0,0,0,.06)}
.row{display:flex;gap:10px;flex-wrap:wrap;font-size:14px}
.k{color:#64748b;min-width:110px}
.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e5e7eb;font-weight:700;font-size:12px}
.pill-wait{background:#fffbeb;border:1px solid #fde68a}
.pill-ok{background:#ecfdf5;border:1px solid #bbf7d0}
.pill-no{background:#fef2f2;border:1px solid #fecaca}
.badge2{display:inline-block;padding:2px 6px;border-radius:8px;background:#eef2ff;color:#3730a3;font-weight:700;font-size:12px}
.note{padding:10px 12px;border-radius:12px;margin-bottom:10px;font-weight:800}
.note.success{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
.note.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.imgproof{max-width:100%;height:auto;border-radius:12px;border:1px solid #e5e7eb}
.pagination{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:12px}
.page{padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;text-decoration:none;color:#0f172a}
.page.active{background:#0f172a;color:#fff}
.small{font-size:12px;color:#64748b}

/* mobile (เดิม) */
@media(max-width:900px){
  .grid{grid-template-columns:1fr}
  .filter{grid-template-columns:1fr 160px 100px}
}
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
      <a href="photographer_bookings_crud.php">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php" class="active">การมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="container">
  <div class="card">
    <h1>มัดจำ/ชำระเงินของลูกค้า</h1>

    <?php if($ok): ?><div class="note success"><?= h($ok) ?></div><?php endif; ?>
    <?php if($err): ?><div class="note error"><?= h($err) ?></div><?php endif; ?>
    <?php if(!$hasPayStat || !$hasPayProof): ?>
      <div class="note error">ตาราง job ยังไม่มีคอลัมน์ที่จำเป็น (payment_status / payment_proof)</div>
    <?php endif; ?>

    <form class="filter" method="get" action="">
      <input type="text" name="q" placeholder="ค้นหาสถานที่/ชื่อลูกค้า..." value="<?= h($q) ?>">
      <select name="status">
        <option value="all"     <?= $status==='all'?'selected':'' ?>>สถานะทั้งหมด</option>
        <option value="waiting" <?= $status==='waiting'?'selected':'' ?>>รอตรวจ</option>
        <option value="approved"<?= $status==='approved'?'selected':'' ?>>อนุมัติ</option>
        <option value="rejected"<?= $status==='rejected'?'selected':'' ?>>ปฏิเสธ</option>
      </select>
      <button class="btn" type="submit">ค้นหา</button>
    </form>

    <?php if(!$rows): ?>
      <div class="note">ไม่พบบันทึกรายการตามเงื่อนไข</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach($rows as $r):
          $dt = trim(($r['job_date'] ?? '').' '.($r['job_time'] ?? ''));
          $cust = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
          $proof = $r['payment_proof'] ?? '';
          $canAct = $hasPayStat && $hasPayProof && ($r['payment_status']==='waiting') && !empty($proof);
        ?>
        <div class="item">
          <div class="row"><span class="k">งาน #</span><b><?= (int)$r['job_id'] ?></b></div>
          <div class="row"><span class="k">ลูกค้า</span><span><?= h($cust) ?></span> <?= $r['email']?'<span class="badge2">'.h($r['email']).'</span>':'' ?></div>
          <div class="row"><span class="k">วัน/เวลา</span><span><?= h($dt ?: '-') ?></span></div>
          <div class="row"><span class="k">สถานที่</span><span><?= h($r['location'] ?? '-') ?></span></div>
          <div class="row"><span class="k">สถานะงาน</span><span><?= h($r['status'] ?? '-') ?></span></div>
          <div class="row"><span class="k">ชำระเงิน</span><span><?= payBadge($r['payment_status'] ?? null) ?></span></div>
          <?php if(!empty($proof)): ?>
            <div class="row"><span class="k">หลักฐาน</span>
              <a class="btn-small" href="<?= h($proof) ?>" target="_blank" rel="noopener" style="background:#f1f5f9;border:1px solid #e2e8f0">เปิดดู</a>
              <a class="btn-small" href="<?= h($proof) ?>" download style="background:#f1f5f9;border:1px solid #e2e8f0">ดาวน์โหลด</a>
            </div>
            <div style="margin-top:8px">
              <img class="imgproof" src="<?= h($proof) ?>" alt="payment proof">
            </div>
          <?php else: ?>
            <div class="row"><span class="k">หลักฐาน</span><span>—</span></div>
          <?php endif; ?>
          <?php if(!empty($r['payment_note'])): ?>
            <div class="row"><span class="k">หมายเหตุ</span><span><?= h($r['payment_note']) ?></span></div>
          <?php endif; ?>
          <?php if(!empty($r['payment_checked_at'])): ?>
            <div class="row"><span class="k">ตรวจเมื่อ</span><span><?= h($r['payment_checked_at']) ?></span></div>
          <?php endif; ?>
          <div style="margin-top:8px">
            <?php if($canAct): ?>
              <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('ยืนยันการดำเนินการ?');">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="job_id" value="<?= (int)$r['job_id'] ?>">
                <input type="text" name="note" placeholder="หมายเหตุ (ถ้ามี)" maxlength="255" style="flex:1;min-width:180px;padding:8px;border:1px solid #e5e7eb;border-radius:12px">
                <button class="btn-small" name="action" value="approve" style="background:#16a34a;color:#fff;border:none">อนุมัติ</button>
                <button class="btn-small" name="action" value="reject"  style="background:#ef4444;color:#fff;border:none">ปฏิเสธ</button>
              </form>
            <?php else: ?>
              <div class="small">ต้องมีหลักฐานและสถานะรอตรวจจึงจะดำเนินการได้</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if($pages>1): ?>
      <div class="pagination">
        <?php for($i=1;$i<=$pages;$i++):
          $qs = http_build_query(['q'=>$q,'status'=>$status,'page'=>$i]);
        ?>
          <a class="page <?= $i===$page?'active':'' ?>" href="?<?= h($qs) ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

</body>
</html>
