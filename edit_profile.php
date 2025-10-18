<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) { die("กรุณาเข้าสู่ระบบก่อน"); }
$user_id = (int)$_SESSION['user_id'];
$uploadDir = 'uploads/';

// ดึงข้อมูลผู้ใช้ปัจจุบัน
$sql = "SELECT first_name, last_name, email, phone, profile_image FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
if (!$user) { die("ไม่พบผู้ใช้"); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $phone      = trim($_POST['phone']);

    // ตรวจสอบ email ซ้ำ
    $checkSql  = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("si", $email, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows > 0) {
        echo "<script>alert('อีเมลนี้ถูกใช้แล้ว กรุณาใช้อีเมลอื่น'); window.history.back();</script>";
        exit;
    }

    $profile_image = $user['profile_image'];

    // อัปโหลดรูปโปรไฟล์ใหม่ (ถ้ามี)
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo "<script>alert('เกิดข้อผิดพลาดในการอัปโหลดรูป (error code: ".(int)$_FILES['profile_image']['error'].")'); window.history.back();</script>";
            exit;
        }

        $fileTmpPath = $_FILES['profile_image']['tmp_name'];
        $fileSize    = (int)$_FILES['profile_image']['size'];

        // ✅ จำกัดขนาดไฟล์: ไม่เกิน 5MB
        if ($fileSize > 5 * 1024 * 1024) {
            echo "<script>alert('ไฟล์รูปต้องไม่เกิน 5MB'); window.history.back();</script>";
            exit;
        }

        // ✅ ตรวจสอบเป็นรูปภาพ และดึงขนาด/ชนิด mime
        $imageInfo = @getimagesize($fileTmpPath);
        if ($imageInfo === false) {
            echo "<script>alert('ไฟล์นี้ไม่ใช่รูปภาพ'); window.history.back();</script>";
            exit;
        }
        $width = (int)$imageInfo[0];
        $height = (int)$imageInfo[1];
        $mime = $imageInfo['mime'] ?? '';

        // ✅ จำกัดมิติภาพ: ไม่เกิน 1200x1200 px
        if ($width > 1200 || $height > 1200) {
            echo "<script>alert('ขนาดรูปต้องไม่เกิน 1200×1200 พิกเซล'); window.history.back();</script>";
            exit;
        }

        // ✅ อนุญาตเฉพาะชนิดรูป
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        if (!isset($allowedMimes[$mime])) {
            echo "<script>alert('อนุญาตเฉพาะไฟล์รูป .jpg .jpeg .png .gif เท่านั้น'); window.history.back();</script>";
            exit;
        }
        $ext = $allowedMimes[$mime];

        // เตรียมโฟลเดอร์อัปโหลด
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

        // ตั้งชื่อไฟล์ใหม่ให้ไม่ชนกัน
        $newFileName = 'profile_'.$user_id.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
        $destPath    = $uploadDir . $newFileName;

        if (!move_uploaded_file($fileTmpPath, $destPath)) {
            echo "<script>alert('เกิดข้อผิดพลาดในการบันทึกรูป'); window.history.back();</script>";
            exit;
        }

        // ลบไฟล์เดิม (ถ้าเป็นไฟล์ใน uploads/ และมีอยู่จริง)
        if (!empty($profile_image) && strpos($profile_image, $uploadDir) === 0 && is_file($profile_image)) {
            @unlink($profile_image);
        }

        $profile_image = $destPath;
    }

    // อัปเดตฐานข้อมูล
    $updateSql = "UPDATE users SET first_name=?, last_name=?, email=?, phone=?, profile_image=? WHERE user_id=?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("sssssi", $first_name, $last_name, $email, $phone, $profile_image, $user_id);
    if ($stmt->execute()) {
        echo "<script>alert('อัปเดตข้อมูลสำเร็จ'); window.location.href='index1.php';</script>";
        exit;
    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); window.history.back();</script>";
        exit;
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไขโปรไฟล์ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@300;500;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center;color:#0f172a}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:100px}
.container{width:100%;max-width:700px;background:#fff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:28px 22px;margin:0 12px 40px}
h2{text-align:center;color:#4a148c;margin-bottom:18px;font-weight:700}
.profile-preview{display:block;margin:0 auto 12px auto;width:140px;height:140px;border-radius:50%;object-fit:cover;border:4px solid transparent;background-image:linear-gradient(#fff,#fff),linear-gradient(135deg,#6a11cb,#2575fc);background-origin:border-box;background-clip:content-box,border-box;box-shadow:0 6px 15px rgba(0,0,0,.15)}
.file-info{text-align:center;margin-bottom:18px;font-size:14px;color:#444;background:#f7f3ef;padding:6px 14px;display:inline-block;border-radius:8px}
label{font-weight:600;margin-top:12px;display:block;color:#444}
input[type="text"],input[type="email"],input[type="tel"],input[type="file"]{width:100%;padding:12px;border:1px solid #e5e7eb;border-radius:10px;margin-top:6px;transition:.2s}
input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15);outline:none}
.help{font-size:12px;color:#6b7280;margin-top:4px}
.btn-submit{width:100%;margin-top:20px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border:none;padding:14px;border-radius:10px;font-size:16px;cursor:pointer;font-weight:800;letter-spacing:.2px;transition:.2s}
.btn-submit:hover{filter:brightness(1.05)}
.btn-back{text-align:center;margin-top:14px}
.btn-back a{background:#6c757d;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;display:inline-block}
.btn-back a:hover{filter:brightness(1.05)}
@media(max-width:640px){.logo{font-size:18px}.menu a{font-size:13px;padding:7px 9px}.header-spacer{height:92px}}
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
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="photographer_deposits.php">ดูข้อมูลมัดจำ</a>
    <a href="edit_profile.php" class="active" aria-current="page">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff;">ออกจากระบบ</a>
  </div>
</div>

<div class="header-spacer"></div>

<div class="container">
  <h2>✏️ แก้ไขโปรไฟล์</h2>
  <?php
    $imgPath = $user['profile_image'] ?? '';
    $exists  = $imgPath && is_file($imgPath);
    if ($exists) {
        $fileName = basename($imgPath);
        $fileSize = filesize($imgPath);
        $fileSizeText = ($fileSize >= 1048576) ? round($fileSize/1048576,2)." MB" : round($fileSize/1024,2)." KB";
        echo "<img src='".h($imgPath)."' class='profile-preview' alt='โปรไฟล์'>";
        echo "<p class='file-info'>".h($fileName)." (".h($fileSizeText).")</p>";
    } else {
        echo "<img src='images/default_user.png' class='profile-preview' alt='โปรไฟล์'>";
        echo "<p class='file-info'>ไม่มีรูปโปรไฟล์</p>";
    }
  ?>

  <form method="POST" enctype="multipart/form-data" autocomplete="off">
    <label>ชื่อ:</label>
    <input type="text" name="first_name" required value="<?= h($user['first_name'] ?? '') ?>">

    <label>นามสกุล:</label>
    <input type="text" name="last_name" required value="<?= h($user['last_name'] ?? '') ?>">

    <label>อีเมล:</label>
    <input type="email" name="email" required value="<?= h($user['email'] ?? '') ?>">

    <label>เบอร์โทรศัพท์:</label>
    <input type="tel" name="phone" required value="<?= h($user['phone'] ?? '') ?>">

    <label>อัปโหลดรูปโปรไฟล์ใหม่:</label>
    <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.gif">
    <div class="help">อนุญาต .jpg .jpeg .png .gif • ขนาดไฟล์ไม่เกิน 5MB • ขนาดรูปไม่เกิน 1200×1200 พิกเซล</div>

    <button type="submit" class="btn-submit">💾 บันทึกการเปลี่ยนแปลง</button>
  </form>

  <div class="btn-back">
    <a href="index1.php">← กลับไปหน้าแรก</a>
  </div>
</div>
</body>
</html>
