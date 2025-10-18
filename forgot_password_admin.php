<?php
session_start();
require 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($email === '') {
        $error = 'กรุณากรอกอีเมล';
    } elseif ($new_password === '' || $confirm_password === '') {
        $error = 'กรุณากรอกรหัสผ่านใหม่และยืนยันรหัสผ่าน';
    } elseif ($new_password !== $confirm_password) {
        $error = 'รหัสผ่านใหม่กับยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        $sql = "SELECT admin_id FROM admin WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $admin_id = $row['admin_id'];

            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE admin SET password_hash = ? WHERE admin_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $password_hash, $admin_id);
            if ($update_stmt->execute()) {
                $success = 'เปลี่ยนรหัสผ่านสำเร็จ สามารถเข้าสู่ระบบได้เลย';
            } else {
                $error = 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน';
            }
            $update_stmt->close();
        } else {
            $error = 'ไม่พบอีเมลผู้ดูแลระบบนี้ในระบบ';
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ลืมรหัสผ่านผู้ดูแล | Cameraman</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Kanit&display=swap');

  body {
    font-family: 'Kanit', sans-serif;
    background: linear-gradient(135deg, #667eea, #764ba2);
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  /* Navbar */
  .navbar {
    background-color: rgba(33, 37, 41, 0.85);
    color: white;
    padding: 14px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
  }
  .navbar a {
    color: #f8f9fa;
    text-decoration: none;
    font-weight: 600;
    font-size: 16px;
    padding: 8px 16px;
    border-radius: 8px;
    background: linear-gradient(90deg, #7b72f6, #8e54e9);
    box-shadow: 0 4px 6px rgba(142, 84, 233, 0.5);
    transition: background 0.3s ease, box-shadow 0.3s ease;
  }
  .navbar a:hover {
    background: linear-gradient(90deg, #8e54e9, #7b72f6);
    box-shadow: 0 6px 12px rgba(142, 84, 233, 0.8);
  }

  /* Container */
  .container {
    background: #ffffffdd;
    max-width: 420px;
    margin: 70px auto 60px;
    padding: 50px 35px 45px;
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    text-align: center;
    backdrop-filter: blur(15px);
  }
  h2 {
    margin-bottom: 30px;
    color: #4b4b4b;
    font-weight: 700;
    font-size: 30px;
    letter-spacing: 1.2px;
  }
  input[type="email"], input[type="password"] {
    width: 100%;
    padding: 16px 18px;
    margin-bottom: 22px;
    border: 2px solid #ddd;
    border-radius: 12px;
    font-size: 17px;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
  }
  input[type="email"]:focus, input[type="password"]:focus {
    outline: none;
    border-color: #7b72f6;
    box-shadow: 0 0 12px #7b72f6aa;
  }
  button {
    width: 100%;
    background-color: #7b72f6;
    color: white;
    font-size: 20px;
    padding: 16px 0;
    border: none;
    border-radius: 14px;
    cursor: pointer;
    font-weight: 700;
    box-shadow: 0 6px 15px rgba(123, 114, 246, 0.6);
    transition: background-color 0.3s ease, box-shadow 0.3s ease;
  }
  button:hover {
    background-color: #6a63e4;
    box-shadow: 0 8px 20px rgba(106, 99, 228, 0.8);
  }
  .message {
    font-weight: 600;
    margin-bottom: 25px;
    font-size: 16px;
  }
  .error {
    color: #e74c3c;
  }
  .success {
    color: #27ae60;
  }

  /* Responsive */
  @media (max-width: 480px) {
    .container {
      margin: 30px 20px 40px;
      padding: 40px 25px 35px;
      max-width: 90vw;
    }
    h2 {
      font-size: 24px;
    }
    button {
      font-size: 18px;
      padding: 14px 0;
    }
  }
</style>
</head>
<body>

<div class="navbar">
  <a href="index.php">หน้าหลัก</a>
  <a href="login_admin.php">เข้าสู่ระบบผู้ดูแล</a>
</div>

<div class="container">
  <h2>ลืมรหัสผ่านผู้ดูแล</h2>

  <?php if ($error): ?>
    <div class="message error"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($success): ?>
    <div class="message success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="email" name="email" placeholder="อีเมลผู้ดูแล" required autocomplete="email" />
    <input type="password" name="new_password" placeholder="รหัสผ่านใหม่" required autocomplete="new-password" />
    <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่านใหม่" required autocomplete="new-password" />
    <button type="submit">รีเซ็ตรหัสผ่าน</button>
  </form>
</div>

</body>
</html>
