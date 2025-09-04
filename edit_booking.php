<?php
require 'db.php';

// ตรวจสอบว่ามี booking_id
if (!isset($_GET['id'])) {
    die("ไม่พบการจองที่ต้องการแก้ไข");
}
$booking_id = (int)$_GET['id'];

// ดึงข้อมูลการจอง
$booking_result = $conn->query("SELECT * FROM booking WHERE booking_id = $booking_id");
if ($booking_result->num_rows == 0) {
    die("ไม่พบข้อมูลการจอง");
}
$booking = $booking_result->fetch_assoc();

// ดึงรายชื่อผู้ใช้
$users = $conn->query("SELECT user_id, username FROM users");

// ดึงรายชื่อช่างภาพ
$photographers = $conn->query("SELECT photographer_id, CONCAT(first_name, ' ', last_name) AS full_name FROM photographer");

// อัปเดตข้อมูลเมื่อ submit form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $photographer_id = $_POST['photographer_id'];
    $booking_date = $_POST['booking_date'];
    $booking_time = $_POST['booking_time'];
    $status = $_POST['status'];

    $update_sql = "UPDATE booking SET 
                    user_id = ?, 
                    photographer_id = ?, 
                    booking_date = ?, 
                    booking_time = ?, 
                    status = ?
                   WHERE booking_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("iisssi", $user_id, $photographer_id, $booking_date, $booking_time, $status, $booking_id);

    if ($stmt->execute()) {
        echo "<script>alert('แก้ไขการจองเรียบร้อยแล้ว'); window.location='manage_bookings.php';</script>";
        exit;
    } else {
        echo "เกิดข้อผิดพลาด: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขการจอง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 70px; /* กัน navbar ทับ */
        }
        .navbar-custom {
            background-color: #343a40;
        }
        .navbar-custom .nav-link, 
        .navbar-custom .navbar-brand {
            color: #fff;
        }
        .navbar-custom .nav-link:hover {
            color: #ffc107;
        }
    </style>
</head>
<body class="bg-light">

<!-- ✅ เมนูด้านบน -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom fixed-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Cameraman</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="admin_dashboard.php">🏠 หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_users.php">👥 ผู้ใช้</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_photographers.php">📸 ช่างภาพ</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_bookings.php">📅 การจอง</a></li>
        <li class="nav-item"><a class="nav-link" href="login_history.php">📝 ประวัติ login</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_admin.php">🛠 Admin</a></li>
        <li class="nav-item"><a class="nav-link text-danger" href="logout.php">🚪 ออกจากระบบ</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- ✅ ฟอร์มแก้ไขการจอง -->
<div class="container mt-5">
    <h2 class="mb-4">✏️ แก้ไขการจอง</h2>
    <form method="post" class="bg-white p-4 shadow-sm rounded">

        <label>ลูกค้า:</label>
        <select name="user_id" class="form-select mb-3" required>
            <?php while ($u = $users->fetch_assoc()): ?>
                <option value="<?= $u['user_id'] ?>" <?= ($u['user_id'] == $booking['user_id']) ? 'selected' : '' ?>>
                    <?= $u['username'] ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>ช่างภาพ:</label>
        <select name="photographer_id" class="form-select mb-3" required>
            <?php while ($p = $photographers->fetch_assoc()): ?>
                <option value="<?= $p['photographer_id'] ?>" <?= ($p['photographer_id'] == $booking['photographer_id']) ? 'selected' : '' ?>>
                    <?= $p['full_name'] ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>วันที่:</label>
        <input type="date" name="booking_date" class="form-control mb-3" 
               value="<?= isset($booking['booking_date']) ? $booking['booking_date'] : '' ?>" required>

        <label>เวลา:</label>
        <input type="time" name="booking_time" class="form-control mb-3" 
               value="<?= isset($booking['booking_time']) ? $booking['booking_time'] : '' ?>" required>

        <label>สถานะ:</label>
        <select name="status" class="form-select mb-3" required>
            <option value="pending" <?= ($booking['status'] == 'pending') ? 'selected' : '' ?>>รอดำเนินการ</option>
            <option value="confirmed" <?= ($booking['status'] == 'confirmed') ? 'selected' : '' ?>>ยืนยันแล้ว</option>
            <option value="cancelled" <?= ($booking['status'] == 'cancelled') ? 'selected' : '' ?>>ยกเลิก</option>
        </select>

        <button type="submit" class="btn btn-success">💾 บันทึกการเปลี่ยนแปลง</button>
        <a href="manage_bookings.php" class="btn btn-secondary">↩️ ย้อนกลับ</a>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
