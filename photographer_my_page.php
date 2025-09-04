<?php
/* photographer_my_page.php — จัดการหน้าเว็บสาธารณะของช่างภาพ (เข้าชม/เพิ่ม/แก้ไข/ซ่อน/แสดง) */
session_start();
require_once 'db.php';
mysqli_set_charset($conn,'utf8mb4');

if (!isset($_SESSION['photographer_id'])) { header("Location: login_photographer.php"); exit; }
$PHOTOGRAPHER_ID = (int)$_SESSION['photographer_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function file_exists_in_uploads($p){ return !empty($p) && is_file(__DIR__ . '/uploads/' . ltrim($p,'/')); }

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_page'])) { $_SESSION['csrf_page'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_page'];
function csrf_ok($t){ return hash_equals($_SESSION['csrf_page'] ?? '', $t ?? ''); }

/* ===== สร้างตารางสำหรับหน้าเว็บของช่างภาพ ===== */
$conn->query("
CREATE TABLE IF NOT EXISTS photographer_page (
  page_id INT AUTO_INCREMENT PRIMARY KEY,
  photographer_id INT NOT NULL UNIQUE,
  title VARCHAR(150) NULL,
  subtitle VARCHAR(255) NULL,
  about TEXT NULL,
  cta_label VARCHAR(60) NULL,
  cta_url VARCHAR(255) NULL,
  hero_image_path VARCHAR(255) NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pp_photographer FOREIGN KEY (photographer_id) REFERENCES photographer(photographer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ===== ดึงชื่อไว้ทักทาย และลิงก์เข้าชมหน้าโปรไฟล์ปัจจุบัน ===== */
$firstName=''; $lastName='';
if ($stmt=$conn->prepare("SELECT first_name,last_name FROM photographer WHERE photographer_id=?")) {
  $stmt->bind_param("i",$PHOTOGRAPHER_ID); $stmt->execute(); $stmt->bind_result($firstName,$lastName); $stmt->fetch(); $stmt->close();
}
$public_url = "photographer_detail.php?id=".$PHOTOGRAPHER_ID; // ใช้หน้า public เดิมของคุณ

/* ===== โหลดหรือสร้าง row เริ่มต้น ===== */
$page = [
  'page_id'=>0, 'title'=>'', 'subtitle'=>'', 'about'=>'',
  'cta_label'=>'', 'cta_url'=>'', 'hero_image_path'=>'', 'is_visible'=>1
];
if ($stmt=$conn->prepare("SELECT page_id, title, subtitle, about, cta_label, cta_url, hero_image_path, is_visible FROM photographer_page WHERE photographer_id=?")) {
  $stmt->bind_param("i",$PHOTOGRAPHER_ID); $stmt->execute();
  $res=$stmt->get_result();
  if($row=$res->fetch_assoc()){
    $page = $row;
  } else {
    // สร้างข้อมูลเริ่มต้น
    $ins=$conn->prepare("INSERT INTO photographer_page(photographer_id,title,subtitle,about,is_visible) VALUES(?,?,?,?,1)");
    $defaultTitle = trim(($firstName.' '.$lastName)) ?: 'My Portfolio';
    $defaultSubtitle = 'ยินดีต้อนรับสู่ผลงานของฉัน';
    $defaultAbout = 'ปรับแต่งข้อความแนะนำตัวและรายละเอียดบริการของคุณได้ที่นี่';
    $ins->bind_param("isss",$PHOTOGRAPHER_ID,$defaultTitle,$defaultSubtitle,$defaultAbout);
    $ins->execute(); $ins->close();
    // โหลดกลับ
    $stmt2=$conn->prepare("SELECT page_id, title, subtitle, about, cta_label, cta_url, hero_image_path, is_visible FROM photographer_page WHERE photographer_id=?");
    $stmt2->bind_param("i",$PHOTOGRAPHER_ID); $stmt2->execute();
    $page=$stmt2->get_result()->fetch_assoc() ?: $page;
    $stmt2->close();
  }
  $stmt->close();
}

/* ===== อัปโหลดรูป (hero) ===== */
function ensure_dir($path){
  if(!is_dir($path)){ @mkdir($path,0777,true); }
}

/* ===== การทำงานของฟอร์ม ===== */
$msg_ok = ''; $msg_err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { $msg_err='คำขอไม่ถูกต้อง (CSRF)'; }
  else {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_content') {
      $title = trim((string)($_POST['title'] ?? ''));
      $subtitle = trim((string)($_POST['subtitle'] ?? ''));
      $about = trim((string)($_POST['about'] ?? ''));
      $cta_label = trim((string)($_POST['cta_label'] ?? ''));
      $cta_url = trim((string)($_POST['cta_url'] ?? ''));

      // อัปโหลดรูป hero (ถ้ามี)
      $hero_path = $page['hero_image_path'] ?? '';
      if (!empty($_FILES['hero_image']['name']) && is_uploaded_file($_FILES['hero_image']['tmp_name'])) {
        $safeName = preg_replace('/[^A-Za-z0-9_\.-]/','_', $_FILES['hero_image']['name']);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','webp'])) {
          $msg_err = 'อัปโหลดเฉพาะไฟล์รูป JPG, PNG, WEBP เท่านั้น';
        } else {
          ensure_dir(__DIR__.'/uploads/hero');
          $newName = 'hero_'.$PHOTOGRAPHER_ID.'_'.time().'.'.$ext;
          $targetRel = 'hero/'.$newName; // เก็บ path ใต้ uploads/
          $targetAbs = __DIR__.'/uploads/'.$targetRel;
          if (move_uploaded_file($_FILES['hero_image']['tmp_name'], $targetAbs)) {
            $hero_path = $targetRel;
          } else {
            $msg_err = 'อัปโหลดไฟล์ไม่สำเร็จ';
          }
        }
      }

      if ($msg_err==='') {
        $stmt=$conn->prepare("UPDATE photographer_page SET title=?,subtitle=?,about=?,cta_label=?,cta_url=?,hero_image_path=? WHERE page_id=? AND photographer_id=?");
        $stmt->bind_param("ssssssii",$title,$subtitle,$about,$cta_label,$cta_url,$hero_path,$page['page_id'],$PHOTOGRAPHER_ID);
        if($stmt->execute()){ $msg_ok='บันทึกข้อมูลแล้ว'; 
          $page['title']=$title; $page['subtitle']=$subtitle; $page['about']=$about; $page['cta_label']=$cta_label; $page['cta_url']=$cta_url; $page['hero_image_path']=$hero_path;
        } else { $msg_err='บันทึกไม่สำเร็จ'; }
        $stmt->close();
      }
    }
    elseif ($action === 'toggle_visibility') {
      $newVisible = (int)($_POST['to'] ?? 1);
      $stmt=$conn->prepare("UPDATE photographer_page SET is_visible=? WHERE page_id=? AND photographer_id=?");
      $stmt->bind_param("iii",$newVisible,$page['page_id'],$PHOTOGRAPHER_ID);
      if($stmt->execute()){ 
        $page['is_visible']=$newVisible; 
        $msg_ok = $newVisible ? 'หน้าเว็บถูก “แสดง” แล้ว' : 'หน้าเว็บถูก “ซ่อน” แล้ว';
      } else { $msg_err='เปลี่ยนสถานะไม่สำเร็จ'; }
      $stmt->close();
    }
    elseif ($action === 'remove_hero') {
      // ลบไฟล์จริงถ้ามี
      if (!empty($page['hero_image_path']) && is_file(__DIR__.'/uploads/'.$page['hero_image_path'])) {
        @unlink(__DIR__.'/uploads/'.$page['hero_image_path']);
      }
      $stmt=$conn->prepare("UPDATE photographer_page SET hero_image_path=NULL WHERE page_id=? AND photographer_id=?");
      $stmt->bind_param("ii",$page['page_id'],$PHOTOGRAPHER_ID);
      if($stmt->execute()){ $page['hero_image_path']=''; $msg_ok='ลบรูปปกแล้ว'; } else { $msg_err='ลบรูปไม่สำเร็จ'; }
      $stmt->close();
    }
  }
}

/* ===== UI ===== */
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>หน้าเว็บของฉัน - Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box} html,body{height:100%}
body{
  margin:0; font-family:'Prompt',sans-serif; color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex; flex-direction:column;
}
/* Navbar — ตามธีมที่ให้จำ */
.navbar{
  width:100%; position:fixed; top:0; left:0;
  background: linear-gradient(90deg,#0b1220,#111827);
  display:flex; align-items:center; padding:14px 0;
  box-shadow:0 4px 20px rgba(0,0,0,.25); z-index:100;
}
.nav-inner{ width:100%; display:flex; align-items:center; justify-content:space-between; padding:0 12px }
.logo{font-size:24px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px}
.menu{display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto}
.menu a,.menu .badge{
  color:#fff; text-decoration:none; padding:8px 12px; border-radius:999px; transition:.25s ease; font-weight:700; font-size:14px
}
.menu .badge{background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent; background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff; color:#0f172a}
.menu a.active{background:#60a5fa; color:#ffffff; border-color:#3b82f6; box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626; color:#fff}

/* Layout */
.wrapper{ flex:1; display:flex; justify-content:center; align-items:flex-start; padding:120px 16px 48px }
.container{ width:100%; max-width:1100px; display:flex; flex-direction:column; gap:18px }
.card{ width:100%; background:#ffffff; border-radius:20px; box-shadow:0 12px 28px rgba(17,24,39,.18); padding:22px; border:1px solid #e5e7eb }

/* Components */
.header{display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px}
.title{margin:0; font-size:28px; font-weight:800; color:#0b1220}
.actions{display:flex; gap:8px; flex-wrap:wrap}
.btn{background:linear-gradient(90deg,#0ea5e9,#6366f1); color:#fff !important; padding:8px 12px; border-radius:12px; text-decoration:none; font-weight:700; font-size:14px; display:inline-block}
.btn.ghost{background:#f3f4f6; color:#0f172a !important}
.badge{display:inline-block; font-size:12px; padding:6px 10px; border-radius:999px; font-weight:800}
.badge.ok{background:#dcfce7; color:#166534}
.badge.off{background:#fee2e2; color:#991b1b}

.form-grid{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px}
.form-grid .full{grid-column:1/-1}
.input,.textarea{width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:12px; font-size:14px}
label{font-size:13px; font-weight:700; color:#0b122a; display:block; margin-bottom:6px}
.hero{display:flex; gap:14px; align-items:center; flex-wrap:wrap}
.hero img{max-width:260px; max-height:150px; border-radius:14px; border:1px solid #e5e7eb; background:#f8fafc; object-fit:cover}
.alert{margin:6px 0 0 0; padding:10px 12px; border-radius:12px; font-size:14px}
.alert.ok{background:#ecfeff; border:1px solid #a5f3fc; color:#164e63}
.alert.err{background:#fff1f2; border:1px solid #fecdd3; color:#7f1d1d}

.section-title{font-weight:900; font-size:18px; margin:0 0 10px 0; color:#0b1220}
.small{font-size:12px; color:#64748b}
hr.sep{border:none; border-top:1px solid #e5e7eb; margin:12px 0}
@media (max-width:640px){ .wrapper{padding:110px 12px 32px} .menu a,.menu .badge{font-size:13px;padding:7px 10px} }
</style>
</head>
<body>

<!-- Navbar -->
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
      <a href="photographer_deposits.php">การมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="photographer_schedule.php">ปฏิทินงาน</a>
      <a href="photographer_my_page.php" class="active">หน้าเว็บของฉัน</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">

    <div class="card">
      <div class="header">
        <h1 class="title">หน้าเว็บของฉัน</h1>
        <div class="actions">
          <a class="btn" href="<?= h($public_url) ?>" target="_blank">👀 เข้าชมหน้า (เปิดแท็บใหม่)</a>
          <?php if ((int)$page['is_visible']===1): ?>
            <span class="badge ok">กำลังแสดงต่อสาธารณะ</span>
          <?php else: ?>
            <span class="badge off">ซ่อนจากสาธารณะ</span>
          <?php endif; ?>
        </div>
      </div>

      <?php if($msg_ok!==''): ?><div class="alert ok"><?= h($msg_ok) ?></div><?php endif; ?>
      <?php if($msg_err!==''): ?><div class="alert err"><?= h($msg_err) ?></div><?php endif; ?>

      <h2 class="section-title">ตั้งค่าแสดง/ซ่อน</h2>
      <form method="post" class="actions" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="toggle_visibility">
        <?php if ((int)$page['is_visible']===1): ?>
          <input type="hidden" name="to" value="0">
          <button class="btn ghost" type="submit">🙈 ซ่อนหน้าเว็บ</button>
        <?php else: ?>
          <input type="hidden" name="to" value="1">
          <button class="btn" type="submit">🙌 แสดงหน้าเว็บ</button>
        <?php endif; ?>
      </form>

      <hr class="sep">

      <h2 class="section-title">แก้ไขเนื้อหา</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_content">
        <div class="form-grid">
          <div>
            <label>หัวข้อบนหน้า (Title)</label>
            <input class="input" type="text" name="title" value="<?= h((string)$page['title']) ?>" placeholder="เช่น <?= h($firstName.' '.$lastName) ?> | Portfolio">
          </div>
          <div>
            <label>บรรทัดรอง (Subtitle)</label>
            <input class="input" type="text" name="subtitle" value="<?= h((string)$page['subtitle']) ?>" placeholder="สรุปบริการสั้น ๆ ของคุณ">
          </div>
          <div class="full">
            <label>เกี่ยวกับฉัน (About)</label>
            <textarea class="textarea" name="about" rows="5" placeholder="แนะนำตัว ประสบการณ์ แนวถนัด ฯลฯ"><?= h((string)$page['about']) ?></textarea>
          </div>
          <div>
            <label>ปุ่ม Call-to-Action (ข้อความบนปุ่ม)</label>
            <input class="input" type="text" name="cta_label" value="<?= h((string)$page['cta_label']) ?>" placeholder="เช่น ติดต่อจองคิว">
          </div>
          <div>
            <label>ลิงก์ของปุ่ม (URL)</label>
            <input class="input" type="url" name="cta_url" value="<?= h((string)$page['cta_url']) ?>" placeholder="เช่น https://line.me/..., tel:, mailto:">
          </div>

          <div class="full">
            <label>รูปปก (Hero Image) <span class="small">รองรับ .jpg .jpeg .png .webp</span></label>
            <div class="hero">
              <?php
                $heroRel = (string)($page['hero_image_path'] ?? '');
                $heroSrc = $heroRel && file_exists_in_uploads($heroRel) ? 'uploads/'.h($heroRel) : '';
              ?>
              <?php if ($heroSrc): ?>
                <img src="<?= $heroSrc ?>" alt="Hero">
                <button type="submit" name="action" value="remove_hero" class="btn ghost">ลบรูป</button>
              <?php endif; ?>
              <input class="input" type="file" name="hero_image" accept=".jpg,.jpeg,.png,.webp">
            </div>
          </div>
        </div>
        <div class="actions" style="margin-top:10px">
          <button class="btn" type="submit">💾 บันทึกการเปลี่ยนแปลง</button>
          <a class="btn ghost" href="<?= h($_SERVER['PHP_SELF']) ?>">ยกเลิก</a>
        </div>
      </form>

      <hr class="sep">

      <h2 class="section-title">คำแนะนำ</h2>
      <div class="small">
        • ปุ่ม “แสดง/ซ่อน” จะกำหนดสถานะสาธารณะของหน้า — หน้ารายละเอียดช่างภาพของคุณ (<?= h($public_url) ?>) ยังใช้งานได้ปกติในระบบ<br>
        • คุณสามารถใช้ CTA เป็นลิงก์ติดต่อ (เช่น <code>tel:0812345678</code> หรือ <code>mailto:you@email.com</code>) หรือไปหน้า “จองคิว” ของคุณได้
      </div>
    </div>

  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
