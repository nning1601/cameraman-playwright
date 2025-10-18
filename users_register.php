<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

/* ===== กำหนดขนาดไฟล์/รูปภาพ ===== */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2 MB
const MIN_IMG_W = 100;
const MIN_IMG_H = 100;
const MAX_IMG_W = 1024;
const MAX_IMG_H = 1024;

$error = '';
$success = '';
$first_name = '';
$last_name  = '';
$email      = '';
$phone      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$first_name || !$last_name || !$email || !$phone || !$password || !$confirm_password) {
        $error = "กรุณากรอกข้อมูลให้ครบทุกช่อง";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } elseif ($password !== $confirm_password) {
        $error = "รหัสผ่านไม่ตรงกัน";
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "อีเมลนี้มีสมาชิกใช้งานแล้ว";
        } else {
            $profile_image = null;

            /* ===== ตรวจสอบไฟล์โปรไฟล์ (ถ้ามีอัปโหลด) ===== */
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $f = $_FILES['profile_image'];

                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $error = "อัปโหลดไฟล์ไม่สำเร็จ (รหัสข้อผิดพลาด: {$f['error']})";
                } else {
                    // 1) เช็คขนาดไฟล์ (Bytes)
                    if ($f['size'] > MAX_IMAGE_BYTES) {
                        $error = "ไฟล์รูปต้องไม่เกิน 2MB";
                    }

                    // 2) เช็คสกุลไฟล์ (นามสกุลไฟล์)
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg','jpeg','png','gif','webp'];
                    if (!$error && !in_array($ext, $allowed_ext, true)) {
                        $error = "โปรไฟล์ต้องเป็นไฟล์ jpg, jpeg, png, gif หรือ webp เท่านั้น";
                    }

                    // 3) เช็ค MIME type ให้ตรงกับไฟล์จริง
                    if (!$error) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($f['tmp_name']) ?: '';
                        $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
                        if (!in_array($mime, $allowed_mimes, true)) {
                            $error = "ไฟล์รูปภาพไม่ถูกต้อง หรือชนิดไฟล์ไม่อนุญาต";
                        }
                    }

                    // 4) เช็คขนาดพิกเซล
                    if (!$error) {
                        $imgInfo = @getimagesize($f['tmp_name']);
                        if (!$imgInfo) {
                            $error = "ไม่สามารถอ่านขนาดรูปภาพได้";
                        } else {
                            [$w,$h] = $imgInfo;
                            if ($w < MIN_IMG_W || $h < MIN_IMG_H) {
                                $error = "ขนาดรูปเล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)";
                            } elseif ($w > MAX_IMG_W || $h > MAX_IMG_H) {
                                $error = "ขนาดรูปใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)";
                            }
                        }
                    }

                    // 5) บันทึกไฟล์
                    if (!$error) {
                        if (!is_dir('uploads')) { @mkdir('uploads', 0755, true); }
                        $new_name = 'uploads/'.('user_'.time().'_'.bin2hex(random_bytes(4))).'.'.$ext;
                        if (!move_uploaded_file($f['tmp_name'], $new_name)) {
                            $error = "ย้ายไฟล์อัปโหลดไม่สำเร็จ";
                        } else {
                            $profile_image = $new_name;
                        }
                    }
                }
            }

            if (!$error) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt2 = $conn->prepare("
                    INSERT INTO users (first_name, last_name, email, phone, password_hash, profile_image)
                    VALUES (?,?,?,?,?,?)
                ");
                $stmt2->bind_param("ssssss", $first_name, $last_name, $email, $phone, $password_hash, $profile_image);
                if ($stmt2->execute()) {
                    $success = "เพิ่มสมาชิกเรียบร้อยแล้ว";
                    // ล้างค่า input
                    $first_name = $last_name = $email = $phone = '';
                } else {
                    $error = "เกิดข้อผิดพลาดในการเพิ่มสมาชิก";
                }
                $stmt2->close();
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>เพิ่มสมาชิก - Cameraman Admin</title>
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
.container{width:100%;max-width:640px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:22px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900;text-align:center}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-outline{background:#ffffff;color:#0f172a;border:1px solid #d1d5db}
.label{font-weight:800;margin-bottom:6px;display:block}
.input{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.actions{display:flex;justify-content:space-between;gap:12px;margin-top:16px}
.alert{padding:12px 14px;border-radius:12px;font-weight:700}
.alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#065f46}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d}
.preview{display:none;margin-top:6px}
.preview img{width:84px;height:84px;object-fit:cover;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.12);border:1px solid #e5e7eb}
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
      <a href="manage_users.php" class="active">สมาชิก</a>
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
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h2>➕ เพิ่มสมาชิกใหม่</h2>
      <?php if($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <?php if($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <div>
          <label class="label">ชื่อ</label>
          <input class="input" type="text" name="first_name" value="<?= h($first_name) ?>" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">นามสกุล</label>
          <input class="input" type="text" name="last_name" value="<?= h($last_name) ?>" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">อีเมล</label>
          <input class="input" type="email" name="email" value="<?= h($email) ?>" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">เบอร์โทร</label>
          <input class="input" type="text" name="phone" value="<?= h($phone) ?>" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">รหัสผ่าน</label>
          <input class="input" type="password" name="password" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">ยืนยันรหัสผ่าน</label>
          <input class="input" type="password" name="confirm_password" required>
        </div>
        <div style="margin-top:10px">
          <label class="label">รูปโปรไฟล์ (ถ้ามี)</label>
          <input class="input" type="file" name="profile_image" id="profile_input" accept=".jpg,.jpeg,.png,.gif,.webp,image/*">
          <div class="hint">อนุญาต: jpg, jpeg, png, gif, webp • ขนาดไฟล์ ≤ 2MB • ขนาดภาพ <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> ถึง <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล</div>
          <div class="preview" id="previewWrap"><img id="previewImg" alt="preview"></div>
          <div class="hint bad" id="fileError" style="display:none"></div>
        </div>
        <div class="actions">
          <a href="manage_users.php" class="btn btn-outline">ย้อนกลับ</a>
          <button type="submit" class="btn">บันทึกสมาชิก</button>
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

/* พรีวิว + เช็คไฟล์เบื้องต้น */
const input=document.getElementById('profile_input');
const wrap=document.getElementById('previewWrap');
const img=document.getElementById('previewImg');
const fileErr=document.getElementById('fileError');

const MAX_BYTES = <?= (int)MAX_IMAGE_BYTES ?>;
const MIN_W = <?= (int)MIN_IMG_W ?>;
const MIN_H = <?= (int)MIN_IMG_H ?>;
const MAX_W = <?= (int)MAX_IMG_W ?>;
const MAX_H = <?= (int)MAX_IMG_H ?>;
const ALLOWED_EXT = ['jpg','jpeg','png','gif','webp'];

function resetPreview(msg=''){
  wrap.style.display='none';
  img.src='';
  if(msg){ fileErr.textContent=msg; fileErr.style.display='block'; } else { fileErr.style.display='none'; }
}

input&&input.addEventListener('change',e=>{
  const f=e.target.files&&e.target.files[0];
  if(!f){ resetPreview(); return; }

  // เช็คขนาดไฟล์
  if(f.size>MAX_BYTES){
    resetPreview('ไฟล์รูปต้องไม่เกิน 2MB');
    input.value='';
    return;
  }

  // เช็คนามสกุลไฟล์
  const ext = (f.name.split('.').pop()||'').toLowerCase();
  if(!ALLOWED_EXT.includes(ext)){
    resetPreview('อนุญาตเฉพาะ: ' + ALLOWED_EXT.join(', '));
    input.value='';
    return;
  }

  const url = URL.createObjectURL(f);
  const tmp = new Image();
  tmp.onload = ()=>{
    const w = tmp.naturalWidth, h = tmp.naturalHeight;
    if(w<MIN_W || h<MIN_H){
      resetPreview(`ขนาดรูปเล็กเกินไป (อย่างน้อย ${MIN_W}×${MIN_H} พิกเซล)`);
      input.value='';
      URL.revokeObjectURL(url);
      return;
    }
    if(w>MAX_W || h>MAX_H){
      resetPreview(`ขนาดรูปใหญ่เกินไป (ไม่เกิน ${MAX_W}×${MAX_H} พิกเซล)`);
      input.value='';
      URL.revokeObjectURL(url);
      return;
    }
    // แสดงพรีวิว
    img.src = url;
    wrap.style.display='block';
    fileErr.style.display='none';
  };
  tmp.onerror = ()=>{
    resetPreview('ไม่สามารถอ่านไฟล์รูปได้');
    input.value='';
    URL.revokeObjectURL(url);
  };
  tmp.src = url;
});
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
