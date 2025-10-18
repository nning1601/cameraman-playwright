<?php
session_start();
require 'db.php';

// ตรวจสอบ admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

// เพิ่ม Admin
if(isset($_POST['add_admin'])){
    $first_name = $_POST['first_name'];
    $last_name  = $_POST['last_name'];
    $username   = $_POST['username'];
    $password   = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admin (first_name, last_name, username, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $first_name, $last_name, $username, $password);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_admin.php");
}

// ดึงข้อมูล Admin ทั้งหมด
$admins = $conn->query("SELECT * FROM admin ORDER BY admin_id ASC");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการ Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: Arial; background:#f7f8fa; margin:0; }

/* Navbar */
.navbar {
    background:#007bff; 
    padding:10px 20px; 
    display:flex; 
    align-items:center;
}
.navbar .logo {
    font-weight:bold; 
    font-size:20px; 
    color:#fff; 
    margin-right:20px;
}
.navbar .menu {
    display:flex;
    gap:0;
}
.navbar .menu a {
    color:white; 
    text-decoration:none; 
    padding:8px 14px; 
    border-radius:5px; 
    background:#0056b3; 
    margin-left:5px;
}
.navbar .menu a:hover { background:#003d7a; }

.container { max-width:1200px; margin:20px auto; }
h2 { text-align:center; color:#333; margin-bottom:20px; }

.table-container, .form-container { 
    background:white; 
    padding:20px; 
    border-radius:10px; 
    box-shadow:0 2px 10px rgba(0,0,0,0.1); 
    margin-bottom:25px; 
}

table { width:100%; border-collapse: collapse; }
th, td { padding:12px; border-bottom:1px solid #ddd; text-align:center; }
th { background:#007bff; color:white; }
tr:hover { background-color:#f1f1f1; }
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <div class="logo">Cameraman</div>
    <div class="menu">
        <a href="admin_dashboard.php">🏠 หน้าแรก</a>
        <a href="manage_users.php">👥 ผู้ใช้</a>
        <a href="manage_photographers.php">📸 ช่างภาพ</a>
        <a href="manage_bookings.php">📅 การจอง</a>
        <a href="admin_inbox_photographer.php">📥 ข้อความช่างภาพ</a>
        <a href="admin_inbox_user.php">📥 ข้อความลูกค้า</a>
        <a href="login_history.php">📝 ประวัติ login</a>
        <a href="manage_admin.php" class="active">🛠 Admin</a>
        <a href="logout.php">🚪 ออกจากระบบ</a>
    </div>
</div>

<div class="container">
    <h2>👤 จัดการ Admin</h2>

    <div class="form-container">
        <h4>เพิ่ม Admin ใหม่</h4>
        <form method="post" class="row g-3">
            <div class="col-md-3">
                <input type="text" name="first_name" class="form-control" placeholder="ชื่อ" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="last_name" class="form-control" placeholder="นามสกุล" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="username" class="form-control" placeholder="Username" required>
            </div>
            <div class="col-md-3">
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <div class="col-12">
                <button type="submit" name="add_admin" class="btn btn-success">➕ เพิ่ม Admin</button>
            </div>
        </form>
    </div>

    <div class="table-container">
        <table class="table table-striped">
            <tr>
                <th>ID</th>
                <th>ชื่อ</th>
                <th>นามสกุล</th>
                <th>Username</th>
                <th>จัดการ</th>
            </tr>
            <?php while($a = $admins->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($a['admin_id']) ?></td>
                <td><?= htmlspecialchars($a['first_name']) ?></td>
                <td><?= htmlspecialchars($a['last_name']) ?></td>
                <td><?= isset($a['username']) ? htmlspecialchars($a['username']) : '-' ?></td>
                <td>
                    <a href="edit_admin.php?id=<?= $a['admin_id'] ?>" class="btn btn-primary btn-sm">✏️ แก้ไข</a>
                    <a href="delete_admin.php?id=<?= $a['admin_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('คุณแน่ใจว่าจะลบ Admin นี้?')">🗑️ ลบ</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

</body>
</html>
