<?php
session_start();
require 'db.php';

// ตรวจสอบ admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

// ตรวจสอบว่ามี ID ส่งมาหรือไม่
if(!isset($_GET['id'])){
    header("Location: manage_admin.php");
    exit;
}

$admin_id = (int)$_GET['id'];

// ดึงข้อมูล Admin
$stmt = $conn->prepare("SELECT * FROM admin WHERE admin_id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if(!$admin){
    die("ไม่พบ Admin ที่ต้องการแก้ไข");
}

// อัปเดตข้อมูล
if(isset($_POST['update_admin'])){
    $first_name = $_POST['first_name'];
    $last_name  = $_POST['last_name'];
    $username   = $_POST['username'];
    $password   = $_POST['password'];

    if(!empty($password)){
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admin SET first_name=?, last_name=?, username=?, password=? WHERE admin_id=?");
        $stmt->bind_param("ssssi", $first_name, $last_name, $username, $password_hash, $admin_id);
    } else {
        $stmt = $conn->prepare("UPDATE admin SET first_name=?, last_name=?, username=? WHERE admin_id=?");
        $stmt->bind_param("sssi", $first_name, $last_name, $username, $admin_id);
    }
    $stmt->execute();
    $stmt->close();
    header("Location: manage_admin.php");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไข Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: Arial; background:#f7f8fa; margin:0; }
.navbar { background:#007bff; padding:10px 20px; display:flex; gap:10px;}
.navbar a { color:white; text-decoration:none; padding:8px 14px; border-radius:5px; background:#0056b3;}
.navbar a:hover { background:#003d7a; }
.container { max-width:600px; margin:50px auto; background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
h2 { text-align:center; color:#333; margin-bottom:20px; }
</style>
</head>
<body>

<div class="navbar">
    <a href="admin_dashboard.php">หน้าแรก</a>
    <a href="manage_admin.php">Admin</a>
    <a href="logout.php">ออกจากระบบ</a>
</div>

<div class="container">
    <h2>แก้ไข Admin</h2>
    <form method="post" class="row g-3">
        <div class="col-md-6">
            <label>ชื่อ</label>
            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($admin['first_name']) ?>" required>
        </div>
        <div class="col-md-6">
            <label>นามสกุล</label>
            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($admin['last_name']) ?>" required>
        </div>
        <div class="col-md-12">
            <label>Username ปัจจุบัน: <strong><?= htmlspecialchars($admin['username']) ?></strong></label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($admin['username']) ?>" required>
        </div>
        <div class="col-md-12">
            <label>รหัสผ่านใหม่ (ถ้าไม่เปลี่ยนให้เว้นว่าง)</label>
            <input type="password" name="password" class="form-control" placeholder="********">
        </div>
        <div class="col-12 text-center">
            <button type="submit" name="update_admin" class="btn btn-primary">อัปเดต Admin</button>
            <a href="manage_admin.php" class="btn btn-secondary">ยกเลิก</a>
        </div>
    </form>
</div>

</body>
</html>
