<?php
session_start();
require 'db.php';

$error = '';
$success = '';

// รีเซ็ตรหัสผ่าน admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $email_admin = trim($_POST['email_admin'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    if ($email_admin && $new_password) {
        $stmt_check = $conn->prepare("SELECT login_id FROM login WHERE username = ? AND type_id = 1");
        $stmt_check->bind_param("s", $email_admin);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 1) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt_update = $conn->prepare("UPDATE login SET password_hash=? WHERE username=? AND type_id=1");
            $stmt_update->bind_param("ss", $password_hash, $email_admin);
            if ($stmt_update->execute()) {
                $success = "รีเซ็ตรหัสผ่านเรียบร้อยแล้ว คุณสามารถล็อกอินได้ทันที";
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt_update->error;
            }
        } else {
            $error = "ไม่พบบัญชีผู้ใช้";
        }
    } else {
        $error = "กรุณากรอกอีเมลและรหัสผ่านใหม่ให้ครบ";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รีเซ็ตรหัสผ่านผู้ดูแลระบบ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f0f2f5; font-family: "Segoe UI", Arial, sans-serif; }
.navbar { background:#007bff; padding:10px 20px; display:flex; justify-content:space-between; align-items:center; color:white; }
.navbar .logo { font-size:20px; font-weight:bold; }
.navbar .menu label { margin-right:5px; color:white; }
.navbar .menu select { margin-right:15px; padding:5px; border-radius:5px; border:none; }
.reset-box { max-width:500px; margin:50px auto; padding:30px; background:#fff; border-radius:12px; box-shadow:0 0 20px rgba(0,0,0,0.15); }
h2 { text-align:center; margin-bottom:25px; }
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <div class="logo">📷 Cameraman</div>
    <div class="menu">
        <label for="signup">สมัครสมาชิก:</label>
        <select id="signup" onchange="if(this.value) location.href=this.value;">
            <option selected disabled>-- เลือกประเภท --</option>
            <option value="register.php">สมาชิก</option>
            <option value="register_photographer.php">ช่างภาพ</option>
        </select>

        <label for="login">เข้าสู่ระบบ:</label>
        <select id="login" onchange="if(this.value) location.href=this.value;">
            <option selected disabled>-- เลือกประเภท --</option>
            <option value="login_user.php">สมาชิก</option>
            <option value="login_photographer.php">ช่างภาพ</option>
            <option value="login_admin.php">ผู้ดูแลระบบ</option>
        </select>
    </div>
</div>

<div class="reset-box">
    <h2>ลืมรหัสผ่าน / รีเซ็ตรหัสผ่าน</h2>
    <?php if ($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
    <?php if ($success) echo "<div class='alert alert-success'>$success</div>"; ?>

    <form method="post">
        <input type="email" class="form-control mb-2" name="email_admin" placeholder="อีเมล" required>
        <input type="password" class="form-control mb-2" name="new_password" placeholder="รหัสผ่านใหม่" required>
        <button type="submit" name="reset_password" class="btn btn-warning w-100">รีเซ็ตรหัสผ่าน</button>
    </form>

    <div class="text-center mt-3">
        <a href="login_admin.php">กลับไปหน้าล็อกอิน</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
