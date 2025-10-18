<?php
session_start();
require_once 'db.php';

$error = "";
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    if ($email === '' || $new_password === '') {
        $error = "กรุณากรอกทั้งอีเมลและรหัสผ่านใหม่ให้ครบถ้วน";
    } else {
        // ตรวจสอบอีเมลในฐานข้อมูล photographer
        $stmt = $conn->prepare("SELECT photographer_id FROM photographer WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $photographer_id = $row['photographer_id'];

            // เข้ารหัสรหัสผ่านใหม่
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // อัปเดตรหัสผ่านในตาราง login
            $stmt_update = $conn->prepare("UPDATE login SET password_hash = ? WHERE photographer_id = ? AND type_id = 2");
            $stmt_update->bind_param("si", $new_password_hash, $photographer_id);

            if ($stmt_update->execute()) {
                $message = "เปลี่ยนรหัสผ่านสำเร็จแล้ว คุณสามารถใช้รหัสผ่านใหม่เข้าสู่ระบบได้ทันที";
            } else {
                $error = "เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน กรุณาลองใหม่อีกครั้ง";
            }
            $stmt_update->close();
        } else {
            $error = "ไม่พบอีเมลนี้ในระบบ";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <title>รีเซ็ตรหัสผ่าน ช่างภาพ</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt&display=swap');
        * { box-sizing: border-box; }
        body {
            font-family: 'Prompt', sans-serif;
            background: linear-gradient(to right, #f2f4f8, #e8eaf6);
            margin: 0;
            padding-top: 70px;
        }
        .navbar {
            background-color: #3f51b5;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 16px;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 999;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .navbar .logo {
            font-size: 1.4em;
            font-weight: bold;
        }
        .box {
            background: white;
            max-width: 400px;
            margin: 30px auto;
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.15);
        }
        h2 {
            text-align: center;
            color: #3f51b5;
            margin-bottom: 25px;
        }
        label {
            font-weight: 600;
            display: block;
            margin-top: 15px;
        }
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-top: 6px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 1em;
        }
        button {
            width: 100%;
            margin-top: 25px;
            padding: 12px;
            background-color: #3f51b5;
            border: none;
            color: white;
            font-size: 1em;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        button:hover {
            background-color: #2c387e;
        }
        .error, .message {
            margin-top: 15px;
            text-align: center;
            font-weight: bold;
        }
        .error { color: red; }
        .message { color: green; }
        .link-center {
            margin-top: 18px;
            text-align: center;
        }
        .link-center a {
            color: #3f51b5;
            text-decoration: none;
        }
        .link-center a:hover {
            text-decoration: underline;
        }
        @media screen and (max-width: 600px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="navbar">
    <div class="logo">📷 Cameraman</div>
</div>

<div class="box">
    <h2>รีเซ็ตรหัสผ่าน ช่างภาพ</h2>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <label for="email">อีเมลที่ลงทะเบียน:</label>
        <input type="email" id="email" name="email" required>

        <label for="new_password">รหัสผ่านใหม่:</label>
        <input type="password" id="new_password" name="new_password" required>

        <button type="submit">เปลี่ยนรหัสผ่าน</button>
    </form>

    <div class="link-center">
        <a href="login_photographer.php">กลับสู่หน้าเข้าสู่ระบบ</a>
    </div>
</div>
</body>
</html>
