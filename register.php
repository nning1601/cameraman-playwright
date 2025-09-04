<?php
session_start();

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";    
$password = "";        
$dbname = "cameraman";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$first_name) $errors[] = "กรุณากรอกชื่อ";
    if (!$last_name) $errors[] = "กรุณากรอกนามสกุล";
    if (!$email) $errors[] = "กรุณากรอกอีเมล";
    if (!$phone) $errors[] = "กรุณากรอกเบอร์โทร";
    if (!$password) $errors[] = "กรุณากรอกรหัสผ่าน";
    if ($password !== $confirm_password) $errors[] = "รหัสผ่านไม่ตรงกัน";

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ?");
        $stmt->bind_param("ss", $email, $phone);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "อีเมล หรือ เบอร์โทรนี้ถูกใช้งานแล้ว";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $profile_image_path = 'images/default_user.png'; 

        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/images/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $file_tmp = $_FILES['profile_image']['tmp_name'];
            $file_name = basename($_FILES['profile_image']['name']);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_ext)) {
                $errors[] = "รองรับเฉพาะไฟล์ภาพนามสกุล jpg, jpeg, png, gif เท่านั้น";
            } else {
                $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                $dest_path = $upload_dir . $new_file_name;

                if (move_uploaded_file($file_tmp, $dest_path)) {
                    $profile_image_path = 'images/' . $new_file_name;
                } else {
                    $errors[] = "อัปโหลดรูปภาพล้มเหลว";
                }
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, password_hash, role, profile_image) VALUES (?, ?, ?, ?, ?, 'customer', ?)");
            $stmt->bind_param("ssssss", $first_name, $last_name, $email, $phone, $password_hash, $profile_image_path);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ";
                $stmt->close();
                $conn->close();
                header("Location: login_user.php");
                exit;
            } else {
                $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>สมัครสมาชิก - Cameraman</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt&display=swap');
body { margin:0; font-family:'Prompt', Arial,sans-serif; background:#f0f2f5; }

/* Navbar */
.navbar { background: rgba(33,150,243,0.85); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 8px 24px rgba(33,150,243,0.4); padding:15px 30px; border-radius:0 0 20px 20px; margin-bottom:30px; position: sticky; top:0; z-index:1000; }
.navbar-container { max-width:1100px; margin:auto; padding:0 20px; display:flex; align-items:center; justify-content:space-between; gap:15px; flex-wrap:wrap; }
.navbar-logo a { color:white; font-size:24px; font-weight:900; text-decoration:none; user-select:none; transition:color 0.3s ease; }
.navbar-logo a:hover { color:#81d4fa; text-shadow:0 0 8px #81d4fa; }
.navbar-menu { list-style:none; margin:0; padding:0; display:flex; gap:22px; font-weight:600; }
.navbar-menu li a { color:white; text-decoration:none; padding:8px 16px; border-radius:8px; transition: background-color 0.3s ease, box-shadow 0.3s ease; box-shadow: inset 0 0 0 0 transparent; }
.navbar-menu li a:hover, .navbar-menu li a[aria-current="page"] { background-color: rgba(129,212,250,0.9); color:#333; box-shadow:0 4px 12px rgba(129,212,250,0.6); }
@media (max-width:600px){ .navbar-container{ flex-direction:column; gap:12px; align-items:center; } .navbar-menu{ flex-wrap:wrap; justify-content:center; gap:12px; } }

/* Register form container */
.register-container { background:#fff; max-width:450px; margin:60px auto; padding:30px 35px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.15); box-sizing:border-box; }
.register-container h2 { text-align:center; margin-bottom:25px; color:#0288d1; }
.register-container label { display:block; margin-top:15px; font-weight:600; color:#333; }
.register-container input { width:100%; padding:10px; margin-top:6px; border:1px solid #ccc; border-radius:6px; font-size:1em; box-sizing:border-box; transition:border-color 0.3s ease; }
.register-container input:focus { outline:none; border-color:#0288d1; box-shadow:0 0 5px rgba(2,136,209,0.5); }
.register-container input[type="file"] { padding:3px; border:none; background:none; font-size:1em; }
#profilePreview { display:none; margin-top:10px; width:120px; height:120px; object-fit:cover; border-radius:50%; border:2px solid #0288d1; }
.register-container button { width:100%; padding:12px; margin-top:25px; background-color:#0288d1; color:white; border:none; border-radius:6px; font-size:1.1em; cursor:pointer; transition:background-color 0.3s ease; }
.register-container button:hover { background-color:#03a9f4; }
.error-message { background:#bbdefb; color:#0d47a1; padding:10px; margin-bottom:15px; border-radius:6px; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar" role="navigation" aria-label="เมนูหลัก">
    <div class="navbar-container">
        <div class="navbar-logo"><a href="index.php">📸 Cameraman</a></div>
        <ul class="navbar-menu">
            <li><a href="index.php">หน้าหลัก</a></li>
            <li><a href="login_user.php">เข้าสู่ระบบผู้ใช้</a></li>
            <li><a href="register_user.php" aria-current="page" style="font-weight:bold; text-decoration: underline;">สมัครสมาชิก</a></li>
            <li><a href="contact.php">ติดต่อเรา</a></li>
        </ul>
    </div>
</nav>

<div class="register-container" role="main">
    <h2>สมัครสมาชิก</h2>

    <?php if (!empty($errors)) : ?>
        <div class="error-message" role="alert">
            <ul>
                <?php foreach ($errors as $e) : ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm();" novalidate>
        <label for="first_name">ชื่อ</label>
        <input type="text" name="first_name" id="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="กรอกชื่อจริง">

        <label for="last_name">นามสกุล</label>
        <input type="text" name="last_name" id="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" placeholder="กรอกนามสกุล">

        <label for="email">อีเมล</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="example@email.com">

        <label for="phone">เบอร์โทร</label>
        <input type="tel" name="phone" id="phone" required pattern="[0-9]{9,10}" title="กรุณากรอกเบอร์โทร 9-10 ตัวเลข" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="0812345678">

        <label for="password">รหัสผ่าน</label>
        <input type="password" name="password" id="password" required minlength="6" placeholder="อย่างน้อย 6 ตัวอักษร">

        <label for="confirm_password">ยืนยันรหัสผ่าน</label>
        <input type="password" name="confirm_password" id="confirm_password" required minlength="6" placeholder="กรุณากรอกอีกครั้ง">

        <label for="profile_image">รูปโปรไฟล์</label>
        <input type="file" name="profile_image" id="profile_image" accept="image/*" onchange="previewProfileImage(event)">
        <img id="profilePreview" alt="รูปโปรไฟล์ตัวอย่าง">

        <button type="submit">สมัครสมาชิก</button>
    </form>
</div>

<script>
function previewProfileImage(event) {
    const input = event.target;
    const preview = document.getElementById('profilePreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '';
        preview.style.display = 'none';
    }
}

function validateForm() {
    const pw = document.getElementById('password').value;
    const cpw = document.getElementById('confirm_password').value;
    if (pw !== cpw) {
        alert('รหัสผ่านกับยืนยันรหัสผ่านไม่ตรงกัน');
        return false;
    }
    return true;
}
</script>

</body>
</html>
