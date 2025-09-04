<?php
session_start();
require 'db.php';
mysqli_set_charset($conn,'utf8mb4');

if (!isset($_SESSION['user_id'])) { header("Location: login_user.php"); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c,string $t,string $col):bool{
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $q->bind_param("ss",$t,$col); $q->execute();
  $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

$USER_ID = (int)$_SESSION['user_id'];

$hasPayStat = has_col($conn,'job','payment_status');
$hasPayProof= has_col($conn,'job','payment_proof');

$ok=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['job_id'])) {
  $job_id=(int)$_POST['job_id'];

  if(!$hasPayStat || !$hasPayProof){
    $err="ตาราง job ไม่มีคอลัมน์ payment_status/payment_proof";
  } elseif(!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error']!==UPLOAD_ERR_OK){
    $err="กรุณาเลือกไฟล์หลักฐานการโอน";
  } else {
    // ✅ ต้องเป็นงานของผู้ใช้คนนี้ และสถานะ "ยืนยันแล้ว"
    $chk=$conn->prepare("
      SELECT job_id
      FROM job
      WHERE job_id=? AND user_id=? AND (status='confirmed' OR status='ยืนยันแล้ว')
      LIMIT 1
    ");
    $chk->bind_param("ii",$job_id,$USER_ID);
    $chk->execute();
    $row=$chk->get_result()->fetch_assoc(); $chk->close();

    if(!$row){
      $err="ไม่พบงานนี้ในบัญชีของคุณ หรือยังไม่ได้อยู่ในสถานะ 'ยืนยันแล้ว'";
    } else {
      $file=$_FILES['payment_proof'];
      if($file['size']>10*1024*1024){
        $err="ไฟล์ใหญ่เกินไป (เกิน 10MB)";
      } else {
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $allow=['jpg','jpeg','png','gif','webp'];
        if(!in_array($ext,$allow)){
          $err="อนุญาตเฉพาะไฟล์รูปภาพ (jpg, jpeg, png, gif, webp)";
        } else {
          $dir="uploads/payment_proof"; if(!is_dir($dir)) @mkdir($dir,0777,true);
          try{$rand=bin2hex(random_bytes(3));}catch(Throwable $e){$rand=uniqid();}
          $name="proof_job{$job_id}_".date('Ymd_His')."_{$rand}.{$ext}";
          $path="$dir/$name";
          if(move_uploaded_file($file['tmp_name'],$path)){
            // ✅ อัปเดตเฉพาะงานของผู้ใช้นี้
            $up=$conn->prepare("UPDATE job SET payment_proof=?, payment_status='waiting' WHERE job_id=? AND user_id=?");
            $up->bind_param("sii",$path,$job_id,$USER_ID);
            if($up->execute()){
              header("Location: upload_payment_proof.php?ok=1"); exit;
            } else {
              $err="บันทึกไม่สำเร็จ: ".$up->error; @unlink($path);
            }
            $up->close();
          } else {
            $err="อัปโหลดไฟล์ไม่สำเร็จ";
          }
        }
      }
    }
  }
}

if(isset($_GET['ok']) && $_GET['ok']=='1'){ $ok="✅ อัปโหลดหลักฐานสำเร็จ กรุณารอช่างภาพอนุมัติ"; }

/* ✅ ดึงเฉพาะงานของผู้ใช้ที่ล็อกอิน และสถานะ 'ยืนยันแล้ว' */
$jobs=[];
$stmt=$conn->prepare("
  SELECT j.job_id,
         j.job_date AS booking_date,
         j.job_time AS booking_time,
         j.location,
         j.status,
         j.payment_status,
         j.payment_proof,
         p.first_name, p.last_name
  FROM job j
  LEFT JOIN photographer p ON p.photographer_id=j.photographer_id
  WHERE j.user_id=? AND (j.status='confirmed' OR j.status='ยืนยันแล้ว')
  ORDER BY j.job_date DESC, j.job_time DESC
");
$stmt->bind_param("i",$USER_ID);
$stmt->execute();
$res=$stmt->get_result();
if($res){ $jobs=$res->fetch_all(MYSQLI_ASSOC); }
$stmt->close();

function payLabel($s){
  return match($s){ 'waiting'=>'รออนุมัติ','approved'=>'อนุมัติแล้ว','rejected'=>'ปฏิเสธแล้ว', default=>'—' };
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>อัปโหลดหลักฐานการโอนเงิน | Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center;color:#0f172a}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:100px}
.main{width:100%;max-width:700px;margin:0 12px 40px;background:#fff;border-radius:18px;box-shadow:0 8px 32px rgba(106,17,203,.08);padding:28px 22px}
h3{margin:0 0 12px 0;text-align:center;font-size:22px;color:#4a148c}
.note{padding:12px;border-radius:12px;margin-bottom:10px;font-weight:600}
.note.success{background:#ecfdf5;color:#065f46;border:1px solid #bbf7d0}
.note.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.note.warn{background:#fff7ed;color:#92400e;border:1px solid #fed7aa}
label{display:block;font-weight:700;margin-top:10px;margin-bottom:6px}
select,input[type=file]{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#0f172a}
.btn{width:100%;padding:12px 14px;border:none;border-radius:12px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;font-weight:800;cursor:pointer;margin-top:12px}
.btn:disabled{opacity:.6;cursor:not-allowed}
.small-muted{color:#6b7280;font-size:13px}
@media(max-width:640px){.logo{font-size:18px}.menu a{font-size:13px;padding:7px 9px}}
</style>
</head>
<body>

<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php" class="active" aria-current="page">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="photographer_deposits.php">ดูข้อมูลมัดจำ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff;">ออกจากระบบ</a>
  </div>
</div>

<div class="header-spacer"></div>

<div class="main">
  <h3>💸 อัปโหลดหลักฐานการโอนเงิน</h3>

  <?php if($ok): ?><div class="note success"><?= h($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="note error"><?= h($err) ?></div><?php endif; ?>

  <?php if(!$hasPayStat || !$hasPayProof): ?>
    <div class="note error">ตาราง job ไม่มีคอลัมน์ payment_status/payment_proof</div>
  <?php endif; ?>

  <?php if(!$jobs): ?>
    <div class="note warn">ไม่มีงานของคุณที่อยู่ในสถานะ “ยืนยันแล้ว”</div>
  <?php else: ?>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <label>เลือกรายการงานของคุณ (สถานะ “ยืนยันแล้ว”)</label>
      <select name="job_id" required>
        <option value="">-- เลือกรายการ --</option>
        <?php foreach($jobs as $j): ?>
          <option value="<?= (int)$j['job_id'] ?>">
            <?= h(($j['first_name']??'').' '.($j['last_name']??'')) ?> | <?= h($j['booking_date']) ?> <?= $j['booking_time']?'เวลา '.h(substr($j['booking_time'],0,5)):'' ?> | ที่: <?= h($j['location'] ?? '-') ?> | สถานะ: <?= h($j['status'] ?? '-') ?> | ชำระเงิน: <?= h(payLabel($j['payment_status'])) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>แนบหลักฐานการโอน (รูปภาพ)</label>
      <input type="file" name="payment_proof" accept="image/*" required>
      <div class="small-muted">รองรับ .jpg .jpeg .png .gif .webp ขนาดไม่เกิน 10MB</div>

      <button type="submit" class="btn" <?= (!$hasPayStat||!$hasPayProof)?'disabled':'' ?>>อัปโหลดหลักฐาน</button>
    </form>
  <?php endif; ?>
</div>

</body>
</html>
