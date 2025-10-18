<?php
session_start();
require 'db.php';
if(!isset($_SESSION['admin_id'])){ header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn,'utf8mb4');

if(!isset($_GET['id'])){ die('ไม่พบผู้ใช้'); }
$id=(int)$_GET['id'];

if(!function_exists('h')){ function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); } }
function ensureUploads(){ if(!is_dir('uploads')) mkdir('uploads',0755,true); }
function img_path($p){
  $p=trim((string)$p); if($p==='') return 'images/default_user.png';
  $p=str_replace('\\','/',$p);
  if(!preg_match('#^(uploads|images)/#i',$p) && !preg_match('#^https?://#i',$p)) $p='uploads/'.$p;
  return $p;
}

/* ===== กำหนดขนาด/ชนิดรูปภาพ ===== */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2MB
const MIN_IMG_W = 100;
const MIN_IMG_H = 100;
const MAX_IMG_W = 1024;
const MAX_IMG_H = 1024;
$ALLOWED_EXT = ['jpg','jpeg','png','gif','webp'];
$ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp'];

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

$stmt=$conn->prepare("SELECT * FROM users WHERE user_id=?");
$stmt->bind_param("i",$id);
$stmt->execute();
$user=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$user){ die('ไม่พบผู้ใช้'); }

$error=''; $success='';
$first_name=$user['first_name']??''; $last_name=$user['last_name']??''; $email=$user['email']??''; $phone=$user['phone']??'';
$current_profile = img_path($user['profile_image'] ?? '');

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_user'])){
  $first_name=trim($_POST['first_name']??'');
  $last_name =trim($_POST['last_name']??'');
  $email     =trim($_POST['email']??'');
  $phone     =trim($_POST['phone']??'');

  if(!$first_name||!$last_name||!$email){ $error='กรุณากรอกข้อมูลให้ครบถ้วน'; }
  elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){ $error='อีเมลไม่ถูกต้อง'; }
  else{
    $dup=$conn->prepare("SELECT user_id FROM users WHERE email=? AND user_id<>? LIMIT 1");
    $dup->bind_param("si",$email,$id);
    $dup->execute(); $dup->store_result();
    if($dup->num_rows>0){ $error='อีเมลนี้มีผู้ใช้งานแล้ว'; }
    $dup->close();
  }

  $set="first_name=?, last_name=?, email=?, phone=?";
  $types="ssss";
  $params=[ $first_name, $last_name, $email, $phone ];

  if(!$error && isset($_POST['password']) && $_POST['password']!==''){
    $pwd = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $set.=", password_hash=?";
    $types.="s";
    $params[]=$pwd;
  }

  /* ===== ตรวจสอบ & อัปโหลดรูปโปรไฟล์ (ถ้ามี) ===== */
  if(!$error && isset($_FILES['profile_image']) && $_FILES['profile_image']['error']!==UPLOAD_ERR_NO_FILE){
    $f = $_FILES['profile_image'];
    if($f['error'] !== UPLOAD_ERR_OK){
      $error = "อัปโหลดไฟล์ไม่สำเร็จ (รหัส: {$f['error']})";
    } else {
      // ขนาดไฟล์
      if($f['size'] > MAX_IMAGE_BYTES){
        $error = "ไฟล์รูปต้องไม่เกิน 2MB";
      }
      // นามสกุลไฟล์
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      if(!$error && !in_array($ext, $ALLOWED_EXT, true)){
        $error = "อนุญาตเฉพาะไฟล์: ".implode(', ',$ALLOWED_EXT);
      }
      // MIME Type
      if(!$error){
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($f['tmp_name']) ?: '';
        if(!in_array($mime, $ALLOWED_MIME, true)){
          $error = "ชนิดไฟล์ไม่ถูกต้อง (MIME: $mime)";
        }
      }
      // ขนาดพิกเซล
      if(!$error){
        $img = @getimagesize($f['tmp_name']);
        if(!$img){ $error = "ไม่สามารถอ่านไฟล์รูปภาพได้"; }
        else{
          [$w,$h] = $img;
          if($w<MIN_IMG_W || $h<MIN_IMG_H){
            $error = "ขนาดรูปเล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)";
          } elseif($w>MAX_IMG_W || $h>MAX_IMG_H){
            $error = "ขนาดรูปใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)";
          }
        }
      }
      // บันทึกไฟล์
      if(!$error){
        ensureUploads();
        $new_name='uploads/'.('user_'.time().'_'.bin2hex(random_bytes(4))).'.'.$ext;
        if(move_uploaded_file($f['tmp_name'],$new_name)){
          $set.=", profile_image=?";
          $types.="s";
          $params[]=$new_name;
          // อัปเดตรูปปัจจุบันสำหรับแสดงผลต่อทันที
          $current_profile = img_path($new_name);
        } else {
          $error='ย้ายไฟล์อัปโหลดไม่สำเร็จ';
        }
      }
    }
  }

  if(!$error){
    $types.="i";
    $params[]=$id;
    $sql="UPDATE users SET $set WHERE user_id=?";
    $up=$conn->prepare($sql);
    $up->bind_param($types, ...$params);
    $up->execute();
    $up->close();
    header("Location: manage_users.php");
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แก้ไขผู้ใช้ - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
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
.container{width:100%;max-width:680px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900;text-align:center}
.label{font-weight:800;margin-bottom:6px;display:block}
.input{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.file{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.row2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (max-width:700px){.row2{grid-template-columns:1fr}}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-outline{background:#ffffff;color:#0f172a;border:1px solid #d1d5db}
.actions{display:flex;justify-content:space-between;gap:12px;margin-top:14px}
.alert{padding:12px 14px;border-radius:12px;font-weight:700}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d}
.preview{display:flex;gap:12px;align-items:center;margin-top:8px}
.preview img{width:100px;height:100px;border-radius:50%;object-fit:cover;border:1px solid #e5e7eb;box-shadow:0 6px 16px rgba(0,0,0,.12)}
.hint{font-size:12px;color:#475569;margin-top:6px}
.hint.bad{color:#b91c1c}
</style>
</head>
<body>
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php" class="active">ผู้ใช้</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
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
  <a href="manage_users.php" class="active">สมาชิก</a>
  <a href="manage_photographers.php">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="admin_photographer_pages.php">หน้าเว็บ</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h2>✏️ แก้ไขผู้ใช้</h2>
      <?php if($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <div class="row2">
          <div>
            <label class="label">ชื่อ</label>
            <input class="input" type="text" name="first_name" value="<?= h($first_name) ?>" required>
          </div>
          <div>
            <label class="label">นามสกุล</label>
            <input class="input" type="text" name="last_name" value="<?= h($last_name) ?>" required>
          </div>
          <div>
            <label class="label">อีเมล</label>
            <input class="input" type="email" name="email" value="<?= h($email) ?>" required>
          </div>
          <div>
            <label class="label">เบอร์โทร</label>
            <input class="input" type="text" name="phone" value="<?= h($phone) ?>">
          </div>
        </div>
        <div style="margin-top:12px">
          <label class="label">รหัสผ่านใหม่ (ถ้าเปลี่ยน)</label>
          <input class="input" type="password" name="password" placeholder="เว้นว่างหากไม่เปลี่ยน">
        </div>
        <div style="margin-top:12px">
          <label class="label">รูปโปรไฟล์</label>
          <input class="file" type="file" name="profile_image" id="profile_input" accept=".jpg,.jpeg,.png,.gif,.webp,image/*">
          <div class="hint">
            อนุญาต: jpg, jpeg, png, gif, webp • ขนาดไฟล์ ≤ 2MB • ขนาดภาพ <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> ถึง <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล
          </div>
          <div class="hint bad" id="fileError" style="display:none"></div>
          <div class="preview">
            <img id="preview" src="<?= h($current_profile) ?>" alt="profile">
          </div>
        </div>
        <div class="actions">
          <a href="manage_users.php" class="btn btn-outline">ย้อนกลับ</a>
          <button type="submit" name="edit_user" class="btn">บันทึก</button>
        </div>
      </form>
    </section>
  </div>
</div>

<script>
/* เมนูมือถือ */
const burgerBtn=document.getElementById('burgerBtn');
const drawer=document.getElementById('drawerMenu');
let drawerOpen=false;
burgerBtn&&burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});

/* พรีวิว + ตรวจไฟล์เบื้องต้น */
const input=document.getElementById('profile_input');
const preview=document.getElementById('preview');
const fileErr=document.getElementById('fileError');

const MAX_BYTES = <?= (int)MAX_IMAGE_BYTES ?>;
const MIN_W = <?= (int)MIN_IMG_W ?>;
const MIN_H = <?= (int)MIN_IMG_H ?>;
const MAX_W = <?= (int)MAX_IMG_W ?>;
const MAX_H = <?= (int)MAX_IMG_H ?>;
const ALLOWED_EXT = <?= json_encode($ALLOWED_EXT, JSON_UNESCAPED_UNICODE) ?>;

function showErr(msg){
  fileErr.textContent = msg;
  fileErr.style.display = 'block';
}
function clearErr(){
  fileErr.textContent = '';
  fileErr.style.display = 'none';
}

input&&input.addEventListener('change',e=>{
  clearErr();
  const f=e.target.files&&e.target.files[0];
  if(!f) return;

  // ขนาดไฟล์
  if(f.size > MAX_BYTES){
    showErr('ไฟล์รูปต้องไม่เกิน 2MB');
    input.value='';
    return;
  }
  // นามสกุลไฟล์
  const ext=(f.name.split('.').pop()||'').toLowerCase();
  if(!ALLOWED_EXT.includes(ext)){
    showErr('อนุญาตเฉพาะ: '+ALLOWED_EXT.join(', '));
    input.value='';
    return;
  }

  const url = URL.createObjectURL(f);
  const img = new Image();
  img.onload = ()=>{
    const w = img.naturalWidth, h = img.naturalHeight;
    if(w<MIN_W || h<MIN_H){
      showErr(`ขนาดรูปเล็กเกินไป (อย่างน้อย ${MIN_W}×${MIN_H} พิกเซล)`);
      input.value=''; URL.revokeObjectURL(url); return;
    }
    if(w>MAX_W || h>MAX_H){
      showErr(`ขนาดรูปใหญ่เกินไป (ไม่เกิน ${MAX_W}×${MAX_H} พิกเซล)`);
      input.value=''; URL.revokeObjectURL(url); return;
    }
    // ผ่าน -> แสดงพรีวิว
    preview.src = url;
  };
  img.onerror = ()=>{
    showErr('ไม่สามารถอ่านไฟล์รูปได้');
    input.value='';
    URL.revokeObjectURL(url);
  };
  img.src = url;
});
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
