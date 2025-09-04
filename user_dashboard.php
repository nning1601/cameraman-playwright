<?php
session_start();

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'user') {
    header("Location: index_user.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แดชบอร์ดลูกค้า</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt&display=swap');

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Prompt', sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #fce4ec, #f3e5f5);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        /* แถบด้านบน */
        .top-bar {
            width: 100%;
            max-width: 800px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 10px 20px;
            background-color: #6a1b9a;
            color: white;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .top-bar .logo {
            font-size: 24px;
            font-weight: bold;
        }

        .top-bar .logout a {
            text-decoration: none;
            color: white;
            background-color: #e53935;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: bold;
            transition: background-color 0.3s;
        }

        .top-bar .logout a:hover {
            background-color: #c62828;
        }

        /* กล่องแดชบอร์ด */
        .dashboard {
            background-color: white;
            width: 100%;
            max-width: 600px;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        h1 {
            color: #6a1b9a;
            margin-bottom: 10px;
        }

        p {
            font-size: 18px;
            color: #444;
        }

        /* ปุ่มดูรายชื่อ */
        .btn {
            margin-top: 30px;
        }

        .btn a {
            text-decoration: none;
            background-color: #6a1b9a;
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: bold;
            transition: background-color 0.3s;
        }

        .btn a:hover {
            background-color: #4a148c;
        }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="logo">📷 Cameraman</div>
    <div class="logout">
        <a href="logout.php">ออกจากระบบ</a>
    </div>
</div>

<div class="dashboard">
    <h1>ยินดีต้อนรับคุณ <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
    <p>คุณได้เข้าสู่ระบบในฐานะ <strong>ลูกค้า</strong></p>

    <div class="btn">
        <a href="view_photographers.php">ดูรายชื่อช่างภาพ</a>
    </div>
</div>

</body>
</html>
