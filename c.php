<?php
session_start();
require 'db.php'; // เชื่อมต่อฐานข้อมูล

$error = '';
$success = '';

if (isset($_POST['register_admin'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($first_name && $last_name && $email && $password) {
        // ตรวจสอบว่าอีเมลยังไม่มี
        $stmt_check = $conn->prepare("SELECT admin_id FROM admin WHERE email=?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows === 0) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // เพิ่มข้อมูลลง admin
            $stmt_insert = $conn->prepare("INSERT INTO admin (first_name, last_name, email, phone, password_hash) VALUES (?, ?, ?, ?, ?)");
            $stmt_insert->bind_param("sssss", $first_name, $last_name, $email, $phone, $password_hash);

            if ($stmt_insert->execute()) {
                $admin_id = $stmt_insert->insert_id;

                // เพิ่มข้อมูลลง login
                $type_id = 1; // admin
                $stmt_login = $conn->prepare("INSERT INTO login (username, password_hash, type_id, admin_id) VALUES (?, ?, ?, ?)");
                $stmt_login->bind_param("ssii", $email, $password_hash, $type_id, $admin_id);
                $stmt_login->execute();

                $success = "สมัครสมาชิกผู้ดูแลระบบเรียบร้อยแล้ว!";
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt_insert->error;
            }
        } else {
            $error = "อีเมลนี้ถูกใช้งานแล้ว";
        }
    } else {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สมัครสมาชิกผู้ดูแลระบบ</title>
    <style>
        body { font-family: Arial; background: #f0f0f0; }
        .box { width: 400px; margin: 50px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 0 10px #aaa; }
        h2 { text-align: center; margin-bottom: 20px; }
        input { width: 100%; padding: 10px; margin-bottom: 10px; }
        button { width: 100%; padding: 10px; background: #28a745; color: #fff; border: none; cursor: pointer; }
        .error { color: red; text-align: center; margin-bottom: 10px; }
        .success { color: green; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="box">
    <h2>สมัครสมาชิกผู้ดูแลระบบ</h2>

    <?php if ($error) echo "<div class='error'>$error</div>"; ?>
    <?php if ($success) echo "<div class='success'>$success</div>"; ?>

    <form method="post">
        <input type="text" name="first_name" placeholder="ชื่อ" required>
        <input type="text" name="last_name" placeholder="นามสกุล" required>
        <input type="email" name="email" placeholder="อีเมล" required>
        <input type="text" name="phone" placeholder="เบอร์โทร">
        <input type="password" name="password" placeholder="รหัสผ่าน" required>
        <button type="submit" name="register_admin">สมัครสมาชิก</button>
    </form>
</div>
</body>
</html>
