<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login_user.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$sql = "SELECT first_name, last_name, email, profile_image FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$firstName = $user['first_name'] ?? 'ลูกค้า';
$lastName = $user['last_name'] ?? '';
$email = $user['email'] ?? 'guest@example.com';
$profile_image_db = $user['profile_image'] ?? 'images/default_user.png';
$default_image_web = 'images/default_user.png';
$profileImage = $default_image_web;
if ($profile_image_db !== '') {
    $full_path = __DIR__ . '/' . $profile_image_db;
    if (file_exists($full_path)) {
        $profileImage = $profile_image_db;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แดชบอร์ดผู้ใช้ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
:root{--nav-h:72px;--grad:linear-gradient(90deg,#7c3aed,#2563eb)}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{position:fixed;top:0;left:0;right:0;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;background:#0f172a;padding:10px 20px;z-index:1000}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:13px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:var(--grad);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:var(--nav-h)}
.container{max-width:1000px;width:100%;padding:0 20px 40px;display:flex;flex-direction:column;gap:18px;align-items:center}
.card{background:#fff;border-radius:20px;padding:32px 24px;width:100%;box-shadow:0 6px 20px rgba(0,0,0,.12);transition:transform .2s,box-shadow .2s}
.card:hover{transform:translateY(-3px);box-shadow:0 10px 25px rgba(0,0,0,.2)}
.profile{text-align:center}
.profile-img{width:126px;height:126px;object-fit:cover;border-radius:50%;border:4px solid #6a11cb;margin-bottom:16px}
h2{font-size:24px;color:#4a148c;margin-bottom:6px;text-align:center}
p{margin:6px 0;color:#444;font-size:15px;font-weight:500;text-align:center}
.button-group{margin-top:22px;display:flex;flex-wrap:wrap;gap:12px;justify-content:center}
.button{padding:12px 16px;background:var(--grad);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;transition:.18s;min-width:170px;text-align:center}
.button:hover{opacity:.95;transform:translateY(-1px)}
.button.ghost{background:#fff;color:#111;border:1px solid #e5e7eb}
.button.ghost:hover{background:#f6f7fb}
@media(max-width:640px){.header-spacer{height:var(--nav-h)}}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php" class="active" aria-current="page">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff">ออกจากระบบ</a>
  </div>
</div>
<div class="header-spacer"></div>
<div class="container">
  <div class="card profile">
    <img src="<?= htmlspecialchars($profileImage) ?>" alt="รูปโปรไฟล์" class="profile-img" onerror="this.onerror=null;this.src='<?= $default_image_web ?>'">
    <h2><?= htmlspecialchars($firstName.' '.$lastName) ?></h2>
    <p><strong>อีเมล:</strong> <?= htmlspecialchars($email) ?></p>
    <p>ยินดีต้อนรับสู่ระบบจองช่างภาพ</p>
    <div class="button-group">
      <a href="view_photographers.php" class="button">📸 ค้นหาช่างภาพ</a>
      <a href="photographer_popularity.php" class="button ghost">⭐ ดูคะแนนช่างภาพ</a>
      <a href="locations_recommend.php" class="button">📍 สถานที่แนะนำ</a>
      <a href="upload_payment_proof.php" class="button">💸 อัปโหลดหลักฐานโอนเงิน</a>
      <a href="contact_admin.php" class="button ghost">✉️ ติดต่อผู้ดูแลระบบ</a>
      <a href="delivery_job_select.php" class="button ghost">✉️ รับงาน </a>
      <a href="edit_profile.php" class="button">✏️ แก้ไขข้อมูล</a>
    </div>
  </div>
</div>
</body>
</html>
