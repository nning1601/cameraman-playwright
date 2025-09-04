<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn,'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_name($s){ return preg_replace('/[^a-zA-Z0-9_\-\.]/','_', (string)$s); }
function ensureUploadsDir(){ if(!is_dir('uploads')) mkdir('uploads',0777,true); }
function getImagePath($nameOrPath){
  $default = 'images/default_user.png';
  if(!$nameOrPath) return $default;
  $p = (strpos($nameOrPath,'/')===false) ? 'uploads/'.basename($nameOrPath) : $nameOrPath;
  return file_exists($p) ? $p : $default;
}

/* ===== กำหนดเงื่อนไขรูปภาพ ===== */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;                   // ไม่เกิน 2MB
const MIN_IMG_W = 100; const MIN_IMG_H = 100;              // อย่างน้อย 100x100 px
const MAX_IMG_W = 2000; const MAX_IMG_H = 2000;            // ไม่เกิน 2000x2000 px
$ALLOWED_EXT  = ['jpg','jpeg','png','gif','webp'];         // นามสกุลไฟล์
$ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp']; // MIME จริง

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

if (!isset($_GET['id'])) { die('ไม่พบรหัสช่างภาพ'); }
$id = (int)$_GET['id'];

/* ---------- ดึงข้อมูลช่างภาพ ---------- */
$stmt = $conn->prepare("SELECT * FROM photographer WHERE photographer_id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$photographer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$photographer){ die('ไม่พบช่างภาพที่ต้องการแก้ไข'); }

/* ---------- ประเภทช่างภาพ (เก็บเป็นสตริงในคอลัมน์ types) ---------- */
$all_types = ['Portrait','Wedding','Event','Landscape','Commercial'];
$types_selected = [];
if (!empty($photographer['types'])) $types_selected = explode(',', $photographer['types']);

/* ---------- ดึงหมวดความเชี่ยวชาญ ---------- */
$expertise = []; // [id => name]
if ($res = $conn->query("SELECT expertise_id, expertise_name FROM expertise ORDER BY expertise_name ASC")){
  while($r=$res->fetch_assoc()){ $expertise[(int)$r['expertise_id']]=$r['expertise_name']; }
  $res->close();
}

$profile_to_show = getImagePath($photographer['profile_image'] ?? ($photographer['profile_image_path'] ?? ''));
$info=''; $error='';

/* ---------- ฟิลเตอร์/เพจรูปตัวอย่าง ---------- */
$exp_filter = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
$page = max(1,(int)($_GET['page'] ?? 1));
$per  = 12;

/* =========================
   จัดการ POST
   ========================= */
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $form = $_POST['form'] ?? 'profile';

  /* --- บันทึกโปรไฟล์ --- */
  if ($form==='profile'){
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $types      = $_POST['types'] ?? [];
    $types_str  = implode(',', $types);

    // อัปโหลดรูปโปรไฟล์ (เก็บเฉพาะ "ชื่อไฟล์" ลงคอลัมน์ profile_image)
    $profile_image = !empty($photographer['profile_image']) ? $photographer['profile_image'] : '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error']!==UPLOAD_ERR_NO_FILE){
      $pf = $_FILES['profile_image'];
      if ($pf['error'] !== UPLOAD_ERR_OK){
        $error = "อัปโหลดรูปโปรไฟล์ล้มเหลว (รหัส: {$pf['error']})";
      } else {
        if ($pf['size'] > MAX_IMAGE_BYTES){
          $error = "ไฟล์รูปโปรไฟล์ต้องไม่เกิน 2MB";
        }
        $ext = strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION));
        if (!$error && !in_array($ext,$ALLOWED_EXT,true)){
          $error = "รูปโปรไฟล์ต้องเป็นไฟล์: ".implode(', ',$ALLOWED_EXT);
        }
        if (!$error){
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime = $finfo->file($pf['tmp_name']) ?: '';
          if (!in_array($mime,$ALLOWED_MIME,true)){
            $error = "ชนิดไฟล์รูปโปรไฟล์ไม่ถูกต้อง (MIME: $mime)";
          }
        }
        if (!$error){
          $wh = @getimagesize($pf['tmp_name']);
          if (!$wh){ $error = "ไม่สามารถอ่านไฟล์รูปโปรไฟล์ได้"; }
          else{
            [$w,$h] = $wh;
            if ($w<MIN_IMG_W || $h<MIN_IMG_H){
              $error = "ขนาดรูปโปรไฟล์เล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)";
            } elseif ($w>MAX_IMG_W || $h>MAX_IMG_H){
              $error = "ขนาดรูปโปรไฟล์ใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)";
            }
          }
        }
        if (!$error){
          ensureUploadsDir();
          $base = safe_name(pathinfo($pf['name'], PATHINFO_FILENAME));
          $name = 'pf_'.$id.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'_'.$base.'.'.$ext;
          $dest = 'uploads/'.$name;
          if (!move_uploaded_file($pf['tmp_name'],$dest)){ $error="บันทึกไฟล์รูปโปรไฟล์ไม่สำเร็จ"; }
          else { $profile_image = $name; }
        }
      }
    }

    if (!$error){
      $up = $conn->prepare("UPDATE photographer SET first_name=?, last_name=?, email=?, phone=?, profile_image=?, types=? WHERE photographer_id=?");
      $up->bind_param("ssssssi", $first_name,$last_name,$email,$phone,$profile_image,$types_str,$id);
      if ($up->execute()) { $info="บันทึกโปรไฟล์สำเร็จ"; }
      else { $error="เกิดข้อผิดพลาด: ".$conn->error; }
      $up->close();
      $profile_to_show = getImagePath($profile_image);
    }
  }

  /* --- จัดการรูปตัวอย่าง --- */
  if ($form==='photos'){
    $action = $_POST['photo_action'] ?? '';

    // เพิ่ม
    if ($action==='add'){
      $expertise_id = (int)($_POST['expertise_id'] ?? 0);
      if (!$expertise_id || !isset($expertise[$expertise_id])) { $error="กรุณาเลือกหมวดความเชี่ยวชาญ"; }
      elseif (!isset($_FILES['image']) || $_FILES['image']['error']===UPLOAD_ERR_NO_FILE) { $error="กรุณาเลือกไฟล์รูป"; }
      elseif ($_FILES['image']['error']!==UPLOAD_ERR_OK) { $error="อัปโหลดไฟล์ล้มเหลว"; }
      else{
        $img = $_FILES['image'];
        if ($img['size'] > MAX_IMAGE_BYTES){
          $error = "ไฟล์รูปต้องไม่เกิน 2MB";
        }
        $ext = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION));
        if (!$error && !in_array($ext,$ALLOWED_EXT,true)){ $error="ชนิดไฟล์ไม่ถูกต้อง (อนุญาต: ".implode(', ',$ALLOWED_EXT).")"; }
        if (!$error){
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime  = $finfo->file($img['tmp_name']) ?: '';
          if (!in_array($mime,$ALLOWED_MIME,true)){ $error="ชนิดไฟล์จริงไม่ถูกต้อง (MIME: $mime)"; }
        }
        if (!$error){
          $wh = @getimagesize($img['tmp_name']);
          if(!$wh){ $error="ไม่สามารถอ่านไฟล์รูปได้"; }
          else{
            [$w,$h] = $wh;
            if ($w<MIN_IMG_W || $h<MIN_IMG_H){ $error="ขนาดรูปเล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)"; }
            elseif ($w>MAX_IMG_W || $h>MAX_IMG_H){ $error="ขนาดรูปใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)"; }
          }
        }
        if (!$error){
          ensureUploadsDir();
          $base = safe_name(pathinfo($img['name'], PATHINFO_FILENAME));
          $name = 'photo_'.$id.'_exp'.$expertise_id.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(2)).'_'.$base.'.'.$ext; // เก็บ "ชื่อไฟล์" เท่านั้น
          $dest = 'uploads/'.$name;
          if (!move_uploaded_file($img['tmp_name'],$dest)) { $error="บันทึกไฟล์ไม่สำเร็จ"; }
          else{
            $ins = $conn->prepare("INSERT INTO photo (image_name, photographer_id, expertise_id, upload_date) VALUES (?,?,?,NOW())");
            $ins->bind_param("sii", $name,$id,$expertise_id);
            $ins->execute(); $ins->close();
            $info="เพิ่มรูปตัวอย่างสำเร็จ";
          }
        }
      }
    }

    // แก้ไข (เปลี่ยนหมวด/อัปโหลดไฟล์ใหม่)
    if ($action==='edit'){
      $photo_id     = (int)($_POST['photo_id'] ?? 0);
      $expertise_id = (int)($_POST['expertise_id'] ?? 0);
      if (!$photo_id) { $error="ไม่พบรูปภาพ"; }
      elseif (!$expertise_id || !isset($expertise[$expertise_id])) { $error="หมวดไม่ถูกต้อง"; }
      else{
        $sel = $conn->prepare("SELECT image_name FROM photo WHERE photo_id=? AND photographer_id=?");
        $sel->bind_param("ii", $photo_id,$id);
        $sel->execute(); $cur = $sel->get_result()->fetch_assoc(); $sel->close();
        if (!$cur) { $error="ไม่พบรูปภาพ"; }
        else{
          $newName = $cur['image_name']; // ชื่อไฟล์เดิม
          if (isset($_FILES['image']) && $_FILES['image']['error']!==UPLOAD_ERR_NO_FILE){
            if ($_FILES['image']['error']!==UPLOAD_ERR_OK){ $error="อัปโหลดไฟล์ล้มเหลว"; }
            else{
              $img = $_FILES['image'];
              if ($img['size'] > MAX_IMAGE_BYTES){ $error = "ไฟล์รูปต้องไม่เกิน 2MB"; }
              $ext = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION));
              if(!$error && !in_array($ext,$ALLOWED_EXT,true)){ $error="ชนิดไฟล์ไม่ถูกต้อง (อนุญาต: ".implode(', ',$ALLOWED_EXT).")"; }
              if(!$error){
                $finfo=new finfo(FILEINFO_MIME_TYPE);
                $mime=$finfo->file($img['tmp_name']) ?: '';
                if(!in_array($mime,$ALLOWED_MIME,true)){ $error="ชนิดไฟล์จริงไม่ถูกต้อง (MIME: $mime)"; }
              }
              if(!$error){
                $wh=@getimagesize($img['tmp_name']);
                if(!$wh){ $error="ไม่สามารถอ่านไฟล์รูปได้"; }
                else{
                  [$w,$h]=$wh;
                  if($w<MIN_IMG_W || $h<MIN_IMG_H){ $error="ขนาดรูปเล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)"; }
                  elseif($w>MAX_IMG_W || $h>MAX_IMG_H){ $error="ขนาดรูปใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)"; }
                }
              }
              if(!$error){
                ensureUploadsDir();
                $base=safe_name(pathinfo($img['name'], PATHINFO_FILENAME));
                $name='photo_'.$id.'_exp'.$expertise_id.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(2)).'_'.$base.'.'.$ext;
                $dest='uploads/'.$name;
                if(!move_uploaded_file($img['tmp_name'],$dest)){ $error="บันทึกไฟล์ไม่สำเร็จ"; }
                else{
                  $old='uploads/'.$cur['image_name'];
                  if(is_file($old)) @unlink($old);
                  $newName=$name;
                }
              }
            }
          }
          if (!$error){
            $up = $conn->prepare("UPDATE photo SET expertise_id=?, image_name=? WHERE photo_id=? AND photographer_id=?");
            $up->bind_param("isii", $expertise_id,$newName,$photo_id,$id);
            $up->execute(); $up->close();
            $info="แก้ไขรูปตัวอย่างสำเร็จ";
          }
        }
      }
    }

    // ลบ
    if ($action==='delete'){
      $photo_id = (int)($_POST['photo_id'] ?? 0);
      if ($photo_id){
        $sel = $conn->prepare("SELECT image_name FROM photo WHERE photo_id=? AND photographer_id=?");
        $sel->bind_param("ii", $photo_id,$id);
        $sel->execute(); $cur = $sel->get_result()->fetch_assoc(); $sel->close();
        if ($cur){
          $del = $conn->prepare("DELETE FROM photo WHERE photo_id=? AND photographer_id=?");
          $del->bind_param("ii", $photo_id,$id); $del->execute(); $del->close();
          $old = 'uploads/'.$cur['image_name'];
          if (is_file($old)) @unlink($old);
          $info="ลบรูปตัวอย่างสำเร็จ";
        } else { $error="ไม่พบรูปภาพ"; }
      } else { $error="ไม่พบรูปภาพ"; }
    }
  }

  // reload page กันส่งซ้ำ
  $qs = http_build_query(['id'=>$id,'exp'=>$exp_filter,'page'=>$page,'info'=>$info,'error'=>$error]);
  header("Location: ".$_SERVER['PHP_SELF']."?".$qs);
  exit;
}

/* =========================
   ดึงรายการรูปตัวอย่าง (พร้อมกรอง/เพจ)
   ========================= */
$where   = "WHERE p.photographer_id=?";
$params  = [$id];
$typestr = "i";
if ($exp_filter && isset($expertise[$exp_filter])){
  $where   .= " AND p.expertise_id=?";
  $params[] = $exp_filter;
  $typestr .= "i";
}

// นับจำนวน
$cs = $conn->prepare("SELECT COUNT(*) c FROM photo p $where");
$cs->bind_param($typestr, ...$params);
$cs->execute(); $cr = $cs->get_result();
$count = (int)($cr->fetch_assoc()['c'] ?? 0);
$cs->close();

$pages = max(1, (int)ceil($count/$per));
$page  = min($page,$pages);
$off   = ($page-1)*$per;

// ดึงรายการ (ใช้ image_name และ upload_date)
$sqlList = "
  SELECT p.photo_id, p.expertise_id, p.image_name, p.upload_date AS created_at, e.expertise_name
  FROM photo p
  LEFT JOIN expertise e ON e.expertise_id = p.expertise_id
  $where
  ORDER BY p.upload_date DESC, p.photo_id DESC
  LIMIT ? OFFSET ?
";
$params2   = $params;
$typestr2  = $typestr."ii";
$params2[] = $per; $params2[] = $off;

$ps = $conn->prepare($sqlList);
$ps->bind_param($typestr2, ...$params2);
$ps->execute(); $rs = $ps->get_result();
$photos = [];
while($r=$rs->fetch_assoc()){ $photos[] = $r; }
$ps->close();

// flash via GET
if(isset($_GET['info']))  $info  = (string)$_GET['info'];
if(isset($_GET['error'])) $error = (string)$_GET['error'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แก้ไขช่างภาพ - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#0f172a;
  background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{width:100%;position:fixed;top:0;left:0;background:linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
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
.burger{display:none}
@media (max-width:900px){
  .menu{display:none}
  .burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}
  .drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}
  .drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}
  .drawer a.active{background:#60a5fa;color:#fff}
}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-danger{background:linear-gradient(90deg,#ef4444,#dc2626)}
.btn-outline{background:#ffffff;color:#0f172a;border:1px solid #d1d5db}
.btn-sm{padding:6px 10px;border-radius:10px;font-size:13px}
.input, select, .file{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.label{font-weight:700;margin-bottom:6px;display:block}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
@media (max-width:992px){.grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:768px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:520px){.grid{grid-template-columns:1fr}}
.card-photo{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
.card-photo img{width:100%;aspect-ratio:4/3;object-fit:cover}
.badge-soft{background:#f1f5f9;color:#0f172a;border:1px solid #e2e8f0;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}
.profile-img{width:120px;height:120px;border-radius:50%;object-fit:cover;margin-bottom:10px;border:2px solid #ccc;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.table-wrap{overflow:auto;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
.alert{padding:12px 14px;border-radius:12px;font-weight:700}
.alert-info{background:#e0f2fe;border:1px solid #bae6fd}
.alert-danger{background:#fee2e2;border:1px solid #fecaca}
.form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (max-width:700px){.form-row{grid-template-columns:1fr}}
.flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.flex-between{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.portfolio-mini{display:flex;gap:6px;flex-wrap:wrap}
.portfolio-mini img{width:60px;height:60px;border-radius:8px;border:1px solid #e5e7eb;object-fit:cover}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:12px}
.pagination a{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#0f172a;font-weight:700}
.pagination a.active{background:#60a5fa;color:#fff;border-color:#3b82f6}
.hint{font-size:12px;color:#475569;margin-top:6px}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">ผู้ใช้</a>
      <a href="manage_photographers.php" class="active">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">หน้าแรก</a>
  <a href="manage_users.php">ผู้ใช้</a>
  <a href="manage_photographers.php" class="active">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">

    <section class="card">
      <div class="flex-between">
        <h2>✏️ แก้ไขช่างภาพ</h2>
        <a href="manage_photographers.php" class="btn btn-secondary">ย้อนกลับ</a>
      </div>

      <?php if($info): ?><div class="alert alert-info" style="margin-top:10px;"><?= h($info) ?></div><?php endif; ?>
      <?php if($error): ?><div class="alert alert-danger" style="margin-top:10px;"><?= h($error) ?></div><?php endif; ?>

      <!-- ฟอร์มโปรไฟล์ -->
      <form method="POST" enctype="multipart/form-data" style="margin-top:12px">
        <input type="hidden" name="form" value="profile">
        <div class="form-row">
          <div>
            <label class="label">ชื่อ</label>
            <input type="text" name="first_name" class="input" value="<?= h($photographer['first_name']) ?>" required>
          </div>
          <div>
            <label class="label">นามสกุล</label>
            <input type="text" name="last_name" class="input" value="<?= h($photographer['last_name']) ?>" required>
          </div>
          <div>
            <label class="label">อีเมล</label>
            <input type="email" name="email" class="input" value="<?= h($photographer['email']) ?>">
          </div>
          <div>
            <label class="label">เบอร์โทร</label>
            <input type="text" name="phone" class="input" value="<?= h($photographer['phone']) ?>">
          </div>
        </div>

        <div style="margin-top:12px">
          <label class="label">ประเภทช่างภาพ</label>
          <div class="flex">
          <?php foreach($all_types as $atype): ?>
            <label class="flex" style="gap:8px"><input type="checkbox" name="types[]" value="<?= h($atype) ?>" <?= in_array($atype,$types_selected)?'checked':'' ?>> <span style="font-weight:700;"><?= h($atype) ?></span></label>
          <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-top:12px">
          <label class="label">รูปโปรไฟล์</label>
          <img src="<?= h($profile_to_show) ?>" class="profile-img" alt="Profile Image"><br>
          <input type="file" name="profile_image" class="file" accept=".jpg,.jpeg,.png,.gif,.webp">
          <div class="hint">อนุญาต: jpg, jpeg, png, gif, webp • ขนาดไฟล์ ≤ 2MB • ขนาดภาพอย่างน้อย <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> และไม่เกิน <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล</div>
        </div>

        <?php if(!empty($photographer['portfolio_images'])):
          $portfolio_imgs = explode(',', $photographer['portfolio_images']); ?>
          <div style="margin-top:12px">
            <label class="label">Portfolio Images</label>
            <div class="portfolio-mini">
              <?php foreach($portfolio_imgs as $img): $img_path = getImagePath(trim($img)); ?>
                <img src="<?= h($img_path) ?>" width="60" height="60" alt="Portfolio">
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div style="margin-top:14px" class="flex">
          <button type="submit" class="btn">บันทึกการแก้ไข</button>
          <a href="manage_photographers.php" class="btn btn-outline">ย้อนกลับ</a>
        </div>
      </form>
    </section>

    <!-- จัดการรูปตัวอย่าง -->
    <section class="card">
      <div class="flex-between">
        <h2>📷 รูปตัวอย่างของช่างภาพ</h2>
        <form method="get" class="flex">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <label class="label" style="margin:0">กรองหมวด:</label>
          <select name="exp" class="input" style="width:auto" onchange="this.form.submit()">
            <option value="0">ทั้งหมด</option>
            <?php foreach($expertise as $eid=>$ename): ?>
              <option value="<?= (int)$eid ?>" <?= $exp_filter===$eid?'selected':'' ?>><?= h($ename) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <!-- เพิ่มรูป -->
      <form method="post" enctype="multipart/form-data" class="form-row" style="margin-top:12px">
        <input type="hidden" name="form" value="photos">
        <input type="hidden" name="photo_action" value="add">
        <div>
          <select name="expertise_id" class="input" required>
            <?php foreach($expertise as $eid=>$ename): ?>
              <option value="<?= (int)$eid ?>"><?= h($ename) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <input type="file" name="image" class="file" accept=".jpg,.jpeg,.png,.gif,.webp" required>
          <div class="hint">อนุญาต: jpg, jpeg, png, gif, webp • ไฟล์ ≤ 2MB • ขนาดภาพอย่างน้อย <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> และไม่เกิน <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล</div>
        </div>
        <div>
          <button class="btn btn-secondary" type="submit">➕ เพิ่มรูป</button>
        </div>
      </form>

      <!-- รายการรูป -->
      <div class="grid" style="margin-top:12px">
        <?php if(!$photos): ?>
          <div class="alert alert-info" style="grid-column:1/-1">ยังไม่มีรูปตัวอย่าง</div>
        <?php else: foreach($photos as $p):
          $img_src = getImagePath($p['image_name']);
          $ename   = $p['expertise_name'] ?? ($expertise[$p['expertise_id']] ?? 'ไม่ระบุ');
        ?>
          <div class="card-photo">
            <a href="<?= h($img_src) ?>" target="_blank" rel="noopener"><img src="<?= h($img_src) ?>" alt=""></a>
            <div class="flex-between" style="padding:8px 10px">
              <span class="badge-soft"><?= h($ename) ?></span>
              <small style="color:#64748b;font-weight:700;"><?= h(date('d/m/Y H:i', strtotime($p['created_at']))) ?></small>
            </div>
            <div style="padding:8px 10px;border-top:1px solid #e5e7eb">
              <!-- แก้ไข -->
              <form method="post" enctype="multipart/form-data" class="form-row">
                <input type="hidden" name="form" value="photos">
                <input type="hidden" name="photo_action" value="edit">
                <input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>">
                <div>
                  <select name="expertise_id" class="input">
                    <?php foreach($expertise as $eid=>$ename2): ?>
                      <option value="<?= (int)$eid ?>" <?= ((int)$p['expertise_id']===$eid)?'selected':'' ?>><?= h($ename2) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <input type="file" name="image" class="file" accept=".jpg,.jpeg,.png,.gif,.webp">
                  <div class="hint">หากไม่เลือกไฟล์ จะเปลี่ยนเฉพาะหมวด • เงื่อนไขไฟล์เหมือนตอนเพิ่ม</div>
                </div>
                <div>
                  <button class="btn btn-outline btn-sm" type="submit">บันทึก</button>
                </div>
              </form>
              <!-- ลบ -->
              <form method="post" style="margin-top:8px" onsubmit="return confirm('ลบรูปนี้ใช่หรือไม่?')">
                <input type="hidden" name="form" value="photos">
                <input type="hidden" name="photo_action" value="delete">
                <input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">🗑️ ลบ</button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- เพจ -->
      <?php if($pages>1): ?>
        <div class="pagination">
          <?php for($i=1;$i<=$pages;$i++): ?>
            <a class="<?= $i===$page?'active':'' ?>" href="?id=<?= (int)$id ?>&exp=<?= (int)$exp_filter ?>&page=<?= $i ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>
</div>

<script>
const burgerBtn=document.getElementById('burgerBtn');
const drawer=document.getElementById('drawerMenu');
let drawerOpen=false;
if(burgerBtn){burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});}
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
