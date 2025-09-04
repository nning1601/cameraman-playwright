<?php
/* admin_photographer_pages.php — จัดการหน้าเว็บสาธารณะของ "ทุกช่างภาพ" (Admin only)
 * ฟังก์ชัน: เข้าชม/เพิ่ม(สร้างครั้งแรก)/แก้ไข/ซ่อน/แสดง + อัปโหลดรูปปก (Hero)
 */
session_start();
require_once 'db.php';
mysqli_set_charset($conn,'utf8mb4');

/* ===== ตรวจสิทธิ์แอดมิน ===== */
if (!isset($_SESSION['admin_id'])) {
  header("Location: login_admin.php"); exit;
}
$ADMIN_ID   = (int)($_SESSION['admin_id'] ?? 0);
$ADMIN_NAME = $_SESSION['admin_name'] ?? 'Admin';

/* ===== กำหนดเงื่อนไขรูปภาพ Hero ===== */
const HERO_MAX_BYTES = 2 * 1024 * 1024;                       // ขนาดไฟล์ ≤ 2MB
const HERO_MIN_W = 600;  const HERO_MIN_H = 300;              // ขั้นต่ำ 600×300 px
const HERO_MAX_W = 3000; const HERO_MAX_H = 3000;             // ไม่เกิน 3000×3000 px
$HERO_ALLOWED_EXT  = ['jpg','jpeg','png','webp'];             // นามสกุลไฟล์ที่อนุญาต
$HERO_ALLOWED_MIME = ['image/jpeg','image/png','image/webp']; // MIME จริงที่อนุญาต

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function file_exists_in_uploads($p){ return !empty($p) && is_file(__DIR__ . '/uploads/' . ltrim($p,'/')); }

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_admin_page'])) { $_SESSION['csrf_admin_page'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_admin_page'];
function csrf_ok($t){ return hash_equals($_SESSION['csrf_admin_page'] ?? '', $t ?? ''); }

/* ===== ตารางหน้าเว็บของช่างภาพ (เผื่อยังไม่มี) ===== */
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

/* ===== พารามิเตอร์เลือกช่างภาพ ===== */
$q       = trim((string)($_GET['q'] ?? ''));       // ค้นหาชื่อ
$sel_pid = isset($_GET['photographer_id']) ? (int)$_GET['photographer_id'] : 0;

/* ===== ดึงรายชื่อช่างภาพ (สำหรับ dropdown + ตาราง) ===== */
$photographers = [];
$sqlList = "SELECT photographer_id, first_name, last_name FROM photographer";
if ($q !== '') {
  $qLike = '%'.$conn->real_escape_string($q).'%';
  $sqlList .= " WHERE CONCAT(first_name,' ',last_name) LIKE '{$qLike}'";
}
$sqlList .= " ORDER BY first_name,last_name";
if ($rs=$conn->query($sqlList)) {
  while($row=$rs->fetch_assoc()){
    $photographers[(int)$row['photographer_id']] = trim(($row['first_name']??'').' '.($row['last_name']??''));
  }
  $rs->free();
}
if ($sel_pid===0 && !empty($photographers)) {
  $sel_pid = array_key_first($photographers);
}

/* ===== โหลด/สร้างข้อมูลหน้าเว็บของช่างภาพที่เลือก ===== */
$page = [
  'page_id'=>0, 'title'=>'', 'subtitle'=>'', 'about'=>'',
  'cta_label'=>'', 'cta_url'=>'', 'hero_image_path'=>'', 'is_visible'=>1
];
$sel_name = $photographers[$sel_pid] ?? '';
if ($sel_pid > 0) {
  if ($stmt=$conn->prepare("SELECT page_id,title,subtitle,about,cta_label,cta_url,hero_image_path,is_visible FROM photographer_page WHERE photographer_id=?")) {
    $stmt->bind_param("i",$sel_pid); $stmt->execute();
    $res=$stmt->get_result();
    if($row=$res->fetch_assoc()){
      $page=$row;
    } else {
      // ยังไม่มี — เตรียม default row แล้วสร้างทันที
      $page['title'] = $sel_name ?: 'My Portfolio';
      $page['subtitle'] = 'ยินดีต้อนรับสู่ผลงานของฉัน';
      $page['about'] = 'ปรับแต่งข้อความแนะนำตัวและรายละเอียดบริการได้ที่นี่';
      $ins=$conn->prepare("INSERT IGNORE INTO photographer_page(photographer_id,title,subtitle,about,is_visible) VALUES(?,?,?,?,1)");
      $ins->bind_param("isss",$sel_pid,$page['title'],$page['subtitle'],$page['about']);
      $ins->execute(); $ins->close();

      // โหลดกลับ
      $stmt2=$conn->prepare("SELECT page_id,title,subtitle,about,cta_label,cta_url,hero_image_path,is_visible FROM photographer_page WHERE photographer_id=?");
      $stmt2->bind_param("i",$sel_pid); $stmt2->execute();
      $page=$stmt2->get_result()->fetch_assoc() ?: $page;
      $stmt2->close();
    }
    $stmt->close();
  }
}

/* ===== ฟังก์ชันอัปโหลด ===== */
function ensure_dir($path){ if(!is_dir($path)){ @mkdir($path,0777,true); } }

/* ===== ทำงานกับแบบฟอร์ม (Save/Toggle/Remove hero) ===== */
$msg_ok=''; $msg_err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { $msg_err='คำขอไม่ถูกต้อง (CSRF)'; }
  else {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['photographer_id'] ?? 0);
    if ($pid<=0) { $msg_err='ไม่พบช่างภาพที่เลือก'; }
    else {
      if ($action==='save_content') {
        $title     = trim((string)($_POST['title'] ?? ''));
        $subtitle  = trim((string)($_POST['subtitle'] ?? ''));
        $about     = trim((string)($_POST['about'] ?? ''));
        $cta_label = trim((string)($_POST['cta_label'] ?? ''));
        $cta_url   = trim((string)($_POST['cta_url'] ?? ''));

        // ค่าปัจจุบันของ hero
        $currentHero = '';
        if ($st=$conn->prepare("SELECT hero_image_path FROM photographer_page WHERE photographer_id=?")){
          $st->bind_param("i",$pid); $st->execute(); $st->bind_result($currentHero); $st->fetch(); $st->close();
        }

        $newHeroPath = null; // 'hero/xxxx.ext' เมื่ออัปโหลดใหม่สำเร็จ
        if (!empty($_FILES['hero_image']['name']) && is_uploaded_file($_FILES['hero_image']['tmp_name'])) {
          $f = $_FILES['hero_image'];

          // 1) ตรวจขนาดไฟล์
          if ($f['size'] > HERO_MAX_BYTES) {
            $msg_err = 'ไฟล์รูปปกต้องไม่เกิน 2MB';
          }

          // 2) ตรวจนามสกุลไฟล์
          $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
          if ($msg_err==='' && !in_array($ext, $HERO_ALLOWED_EXT, true)) {
            $msg_err = 'นามสกุลไฟล์ไม่ถูกต้อง (อนุญาต: '.implode(', ', $HERO_ALLOWED_EXT).')';
          }

          // 3) ตรวจ MIME จริง
          if ($msg_err==='') {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']) ?: '';
            if (!in_array($mime, $HERO_ALLOWED_MIME, true)) {
              $msg_err = 'ชนิดไฟล์ไม่ถูกต้อง (MIME: '.$mime.')';
            }
          }

          // 4) ตรวจขนาดพิกเซล
          if ($msg_err==='') {
            $wh = @getimagesize($f['tmp_name']);
            if (!$wh) {
              $msg_err = 'ไม่สามารถอ่านไฟล์รูปได้';
            } else {
              [$w,$h] = $wh;
              if ($w < HERO_MIN_W || $h < HERO_MIN_H) {
                $msg_err = 'ขนาดภาพเล็กเกินไป (อย่างน้อย '.HERO_MIN_W.'×'.HERO_MIN_H.' พิกเซล)';
              } elseif ($w > HERO_MAX_W || $h > HERO_MAX_H) {
                $msg_err = 'ขนาดภาพใหญ่เกินไป (ไม่เกิน '.HERO_MAX_W.'×'.HERO_MAX_H.' พิกเซล)';
              }
            }
          }

          // 5) ย้ายไฟล์เมื่อผ่านทุกเงื่อนไข
          if ($msg_err==='') {
            ensure_dir(__DIR__.'/uploads/hero');
            $safeBase = preg_replace('/[^A-Za-z0-9_\.-]/','_', pathinfo($f['name'], PATHINFO_FILENAME));
            $newName  = 'hero_'.$pid.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(2)).'_'.$safeBase.'.'.$ext;
            $targetRel = 'hero/'.$newName;
            $targetAbs = __DIR__.'/uploads/'.$targetRel;

            if (!move_uploaded_file($f['tmp_name'], $targetAbs)) {
              $msg_err = 'อัปโหลดไฟล์ไม่สำเร็จ';
            } else {
              $newHeroPath = $targetRel; // เก็บ path ใหม่ไว้ก่อน
            }
          }
        }

        if ($msg_err==='') {
          // hero_image_path ใช้ค่าใหม่ถ้ามี ไม่งั้นคงค่าเดิม
          $hero_to_save = $newHeroPath ?: $currentHero;

          $stmt=$conn->prepare("UPDATE photographer_page SET title=?,subtitle=?,about=?,cta_label=?,cta_url=?,hero_image_path=? WHERE photographer_id=?");
          $stmt->bind_param("ssssssi",$title,$subtitle,$about,$cta_label,$cta_url,$hero_to_save,$pid);
          if($stmt->execute()){ 
            $msg_ok='บันทึกข้อมูลแล้ว';
            // ลบไฟล์เก่าถ้าอัปโหลดไฟล์ใหม่สำเร็จ
            if ($newHeroPath && $currentHero && file_exists_in_uploads($currentHero)) {
              @unlink(__DIR__.'/uploads/'.$currentHero);
            }
          } else { 
            $msg_err='บันทึกไม่สำเร็จ';
            // ย้อนลบไฟล์ใหม่ออกเพื่อไม่ให้ค้าง
            if ($newHeroPath && is_file(__DIR__.'/uploads/'.$newHeroPath)) {
              @unlink(__DIR__.'/uploads/'.$newHeroPath);
            }
          }
          $stmt->close();

          // reload หน้าเลือก
          header('Location: '.$_SERVER['PHP_SELF'].'?photographer_id='.$pid.'&q='.urlencode($q).'&msg='.urlencode($msg_ok)); exit;
        }
      }
      elseif ($action==='toggle_visibility') {
        $to = (int)($_POST['to'] ?? 1);
        $stmt=$conn->prepare("UPDATE photographer_page SET is_visible=? WHERE photographer_id=?");
        $stmt->bind_param("ii",$to,$pid);
        if($stmt->execute()){ $msg_ok = $to? 'หน้าเว็บถูก “แสดง” แล้ว':'หน้าเว็บถูก “ซ่อน” แล้ว'; }
        else { $msg_err='เปลี่ยนสถานะไม่สำเร็จ'; }
        $stmt->close();
        header('Location: '.$_SERVER['PHP_SELF'].'?photographer_id='.$pid.'&q='.urlencode($q).'&msg='.urlencode($msg_ok)); exit;
      }
      elseif ($action==='remove_hero') {
        // ลบไฟล์จริง
        $old='';
        if ($st=$conn->prepare("SELECT hero_image_path FROM photographer_page WHERE photographer_id=?")){
          $st->bind_param("i",$pid); $st->execute(); $st->bind_result($old); $st->fetch(); $st->close();
        }
        if ($old && is_file(__DIR__.'/uploads/'.$old)) @unlink(__DIR__.'/uploads/'.$old);
        $stmt=$conn->prepare("UPDATE photographer_page SET hero_image_path=NULL WHERE photographer_id=?");
        $stmt->bind_param("i",$pid);
        if($stmt->execute()){ $msg_ok='ลบรูปปกแล้ว'; } else { $msg_err='ลบรูปไม่สำเร็จ'; }
        $stmt->close();
        header('Location: '.$_SERVER['PHP_SELF'].'?photographer_id='.$pid.'&q='.urlencode($q).'&msg='.urlencode($msg_ok)); exit;
      }
    }
  }
}

$msg_ok = $msg_ok ?: (isset($_GET['msg']) ? trim($_GET['msg']) : '');

/* ===== โหลดข้อมูลหน้า + สถานะ (ล่าสุดหลัง action) ===== */
if ($sel_pid>0) {
  if ($st=$conn->prepare("SELECT page_id,title,subtitle,about,cta_label,cta_url,hero_image_path,is_visible FROM photographer_page WHERE photographer_id=?")){
    $st->bind_param("i",$sel_pid); $st->execute();
    $r=$st->get_result(); if($row=$r->fetch_assoc()) $page=$row; $st->close();
  }
}
$public_url = $sel_pid>0 ? "photographer_detail.php?id=".$sel_pid : '';

/* ===== ตารางสรุปสถานะทุกคน (สำหรับ admin overview) ===== */
$overview = [];
$qr = $conn->query("
  SELECT p.photographer_id, CONCAT(p.first_name,' ',p.last_name) AS name,
         pp.is_visible, pp.updated_at
  FROM photographer p
  LEFT JOIN photographer_page pp ON pp.photographer_id = p.photographer_id
  ORDER BY name
");
if ($qr){
  while($rw=$qr->fetch_assoc()){
    $overview[] = $rw;
  }
  $qr->free();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการหน้าเว็บช่างภาพ (แอดมิน) - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box} html,body{height:100%}
body{
  margin:0; font-family:'Prompt',sans-serif; color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex; flex-direction:column;
}
/* Navbar — ธีมเดียวกับที่ใช้ */
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
.container{ width:100%; max-width:1200px; display:flex; flex-direction:column; gap:18px }
.card{ width:100%; background:#ffffff; border-radius:20px; box-shadow:0 12px 28px rgba(17,24,39,.18); padding:22px; border:1px solid #e5e7eb }

/* Header & controls */
.header{display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px}
.title{margin:0; font-size:28px; font-weight:800; color:#0b1220}
.controls{display:flex; gap:8px; flex-wrap:wrap; align-items:center}
.input{padding:8px 12px; border:1px solid #e5e7eb; border-radius:12px; font-size:14px; background:#fff}
.select{padding:8px 12px; border:1px solid #e5e7eb; border-radius:12px; font-size:14px; background:#fff}
.btn{background:linear-gradient(90deg,#0ea5e9,#6366f1); color:#fff !important; padding:8px 12px; border-radius:12px; text-decoration:none; font-weight:700; font-size:14px; display:inline-block}
.btn.ghost{background:#f3f4f6; color:#0f172a !important}
.badge{display:inline-block; font-size:12px; padding:6px 10px; border-radius:999px; font-weight:800}
.badge.ok{background:#dcfce7; color:#166534}
.badge.off{background:#fee2e2; color:#991b1b}
.small{font-size:12px; color:#64748b}
.alert{margin:8px 0; padding:10px 12px; border-radius:12px; font-size:14px}
.alert.ok{background:#ecfeff; border:1px solid #a5f3fc; color:#164e63}
.alert.err{background:#fff1f2; border:1px solid #fecdd3; color:#7f1d1d}

/* Form */
.form-grid{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px}
.form-grid .full{grid-column:1/-1}
.textarea{min-height:120px}
label{font-size:13px; font-weight:700; color:#0b122a; display:block; margin-bottom:6px}
.hero{display:flex; gap:14px; align-items:center; flex-wrap:wrap}
.hero img{max-width:260px; max-height:150px; border-radius:14px; border:1px solid #e5e7eb; background:#f8fafc; object-fit:cover}

/* Table */
.table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:14px; border:1px solid #e5e7eb}
.table th,.table td{padding:10px 12px; font-size:14px; border-bottom:1px solid #eef0f3; text-align:left}
.table thead th{background:#0f172a; color:#fff}
.table tbody tr:nth-child(odd){background:#fafafa}
.table .actions a{margin-right:6px}
@media (max-width:640px){ .wrapper{padding:110px 12px 32px} .menu a,.menu .badge{font-size:13px;padding:7px 10px} }
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($ADMIN_NAME) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php" class="active">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">

    <div class="card">
      <div class="header">
        <h1 class="title">จัดการหน้าเว็บช่างภาพ (Admin)</h1>
        <form method="get" class="controls">
          <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อช่างภาพ...">
          <select class="select" name="photographer_id" onchange="this.form.submit()">
            <?php foreach($photographers as $pid=>$name): ?>
              <option value="<?= (int)$pid ?>" <?= $pid===$sel_pid?'selected':'' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn" type="submit">ค้นหา</button>
          <?php if($sel_pid>0): ?>
            <a class="btn" target="_blank" href="photographer_detail.php?id=<?= (int)$sel_pid ?>">👀 เข้าชมหน้า</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if($msg_ok!==''): ?><div class="alert ok"><?= h($msg_ok) ?></div><?php endif; ?>
      <?php if($msg_err!==''): ?><div class="alert err"><?= h($msg_err) ?></div><?php endif; ?>

      <?php if ($sel_pid>0): ?>
      <!-- ฟอร์มแก้ไขของช่างภาพที่เลือก -->
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_content">
        <input type="hidden" name="photographer_id" value="<?= (int)$sel_pid ?>">
        <div class="form-grid">
          <div>
            <label>หัวข้อบนหน้า (Title)</label>
            <input class="input" type="text" name="title" value="<?= h((string)$page['title']) ?>" placeholder="เช่น <?= h($sel_name) ?> | Portfolio">
          </div>
          <div>
            <label>บรรทัดรอง (Subtitle)</label>
            <input class="input" type="text" name="subtitle" value="<?= h((string)$page['subtitle']) ?>" placeholder="สรุปบริการสั้น ๆ">
          </div>
          <div class="full">
            <label>เกี่ยวกับ (About)</label>
            <textarea class="input textarea" name="about" rows="6" placeholder="แนะนำตัว ประสบการณ์ แนวถนัด ฯลฯ"><?= h((string)$page['about']) ?></textarea>
          </div>
          <div>
            <label>CTA Label</label>
            <input class="input" type="text" name="cta_label" value="<?= h((string)$page['cta_label']) ?>" placeholder="เช่น ติดต่อจองคิว">
          </div>
          <div>
            <label>CTA URL</label>
            <input class="input" type="url" name="cta_url" value="<?= h((string)$page['cta_url']) ?>" placeholder="https://..., tel:, mailto:">
          </div>

          <div class="full">
            <label>รูปปก (Hero) <span class="small">รองรับ .jpg .jpeg .png .webp | ขนาดไฟล์ ≤ 2MB | อย่างน้อย <?= HERO_MIN_W ?>×<?= HERO_MIN_H ?> และไม่เกิน <?= HERO_MAX_W ?>×<?= HERO_MAX_H ?> พิกเซล</span></label>
            <div class="hero">
              <?php
                $heroRel = (string)($page['hero_image_path'] ?? '');
                $heroSrc = $heroRel && file_exists_in_uploads($heroRel) ? 'uploads/'.h($heroRel) : '';
              ?>
              <?php if ($heroSrc): ?>
                <img src="<?= $heroSrc ?>" alt="Hero">
              <?php endif; ?>
              <input class="input" type="file" name="hero_image" accept=".jpg,.jpeg,.png,.webp">
              <?php if ($heroSrc): ?>
                <button class="btn ghost" type="submit" name="action" value="remove_hero" formaction="<?= h($_SERVER['PHP_SELF']) ?>">
                  ลบรูปปก
                </button>
                <input type="hidden" name="photographer_id" value="<?= (int)$sel_pid ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="controls" style="margin-top:10px">
          <button class="btn" type="submit">💾 บันทึกการเปลี่ยนแปลง</button>
          <?php if ((int)$page['is_visible']===1): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle_visibility">
              <input type="hidden" name="photographer_id" value="<?= (int)$sel_pid ?>">
              <input type="hidden" name="to" value="0">
              <button class="btn ghost" type="submit">🙈 ซ่อนหน้าเว็บ</button>
            </form>
          <?php else: ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle_visibility">
              <input type="hidden" name="photographer_id" value="<?= (int)$sel_pid ?>">
              <input type="hidden" name="to" value="1">
              <button class="btn" type="submit">🙌 แสดงหน้าเว็บ</button>
            </form>
          <?php endif; ?>
          <span class="small">
            สถานะปัจจุบัน :
            <?php if ((int)$page['is_visible']===1): ?>
              <span class="badge ok">กำลังแสดง</span>
            <?php else: ?>
              <span class="badge off">ถูกซ่อน</span>
            <?php endif; ?>
          </span>
        </div>
      </form>
      <?php endif; ?>
    </div>

    <!-- ตารางภาพรวมสถานะทุกช่างภาพ -->
    <div class="card">
      <div class="header">
        <h2 class="title" style="font-size:22px">ภาพรวมสถานะหน้าเว็บของช่างภาพทั้งหมด</h2>
        <span class="small">คลิก “แก้ไข” เพื่อไปยังแบบฟอร์มด้านบน</span>
      </div>
      <div style="overflow:auto">
        <table class="table">
          <thead>
            <tr>
              <th style="width:70px">ID</th>
              <th>ชื่อช่างภาพ</th>
              <th style="width:140px">สถานะ</th>
              <th style="width:180px">แก้ไขล่าสุด</th>
              <th style="width:220px">การทำงาน</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($overview)): ?>
              <tr><td colspan="5">ไม่มีข้อมูล</td></tr>
            <?php else: ?>
              <?php foreach($overview as $row): ?>
                <tr>
                  <td><?= (int)$row['photographer_id'] ?></td>
                  <td><?= h($row['name'] ?? '') ?></td>
                  <td>
                    <?php if ((int)($row['is_visible'] ?? 0)===1): ?>
                      <span class="badge ok">กำลังแสดง</span>
                    <?php else: ?>
                      <span class="badge off">ถูกซ่อน</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($row['updated_at'] ?? '-') ?></td>
                  <td class="actions">
                    <a class="btn ghost" href="<?= h($_SERVER['PHP_SELF']) ?>?photographer_id=<?= (int)$row['photographer_id'] ?>&q=<?= urlencode($q) ?>">แก้ไข</a>
                    <a class="btn" target="_blank" href="photographer_detail.php?id=<?= (int)$row['photographer_id'] ?>">เข้าชม</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
