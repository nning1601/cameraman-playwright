<?php
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

$error = "";
$popup_success = false;

function split_name($full) {
    $full = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === "") return ["", ""];
    $parts = explode(' ', $full, 2);
    if (count($parts) === 1) return [$parts[0], ""];
    return [$parts[0], $parts[1]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm'] ?? '');

    if ($name !== '' && $email !== '' && $password !== '' && $confirm !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "รูปแบบอีเมลไม่ถูกต้อง";
        } elseif (strlen($password) < 6) {
            $error = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";
        } elseif ($password !== $confirm) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $error = "อีเมลนี้ถูกใช้งานแล้ว";
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    [$first_name, $last_name] = split_name($name);
                    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssss", $first_name, $last_name, $email, $hashedPassword);
                        if ($stmt->execute()) {
                            $_SESSION['user_id']   = $stmt->insert_id;
                            $_SESSION['user_name'] = $name;
                            $popup_success = true;
                        } else {
                            $error = "เกิดข้อผิดพลาดในการสมัครสมาชิก";
                        }
                    } else {
                        $error = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง (INSERT)";
                    }
                }
            } else {
                $error = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง (SELECT)";
            }
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
<title>สมัครสมาชิก - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff}
.nav-links{list-style:none;display:flex;gap:18px}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:15px;color:#fff;padding:10px 14px;border-radius:10px;transition:transform .15s ease,background .15s ease}
.nav-links a:hover{background:#22d3ee;color:#0f172a;transform:translateY(-1px)}
.dropdown-menu{position:absolute;top:100%;left:0;background:#ffffff;border:1px solid rgba(2,6,23,.08);border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);min-width:180px;display:none;flex-direction:column;overflow:hidden}
.dropdown-menu a{display:block;color:#0f172a;padding:12px 14px}
.dropdown-menu a:hover{background:#0ea5e9;color:#fff}
.dropdown:hover .dropdown-menu{display:flex}
.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer}
.hamburger span{width:26px;height:3px;background:#fff;border-radius:2px}
@media (max-width:768px){
  .nav-links{position:fixed;top:58px;right:-100%;background:#0f172a;width:68vw;max-width:320px;height:calc(100vh - 58px);padding:18px;flex-direction:column;gap:8px;border-left:1px solid rgba(255,255,255,.08);transition:right .25s ease}
  .nav-links.show{right:0}
  .dropdown-menu{position:static;border:none;box-shadow:none;background:transparent}
  .dropdown-menu a{color:#e2e8f0;padding:10px 12px}
  .dropdown-menu a:hover{background:#22d3ee;color:#0f172a}
  .hamburger{display:flex}
}
.page-space{height:88px}
.register-wrap{width:min(520px,94%);background:#ffffff;border-radius:20px;padding:26px 20px 22px;margin:24px auto 40px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06);text-align:center}
.register-wrap h2{color:#0f172a;font-size:26px;margin-bottom:6px}
.register-sub{color:#6b7280;font-size:14px;margin-bottom:14px}
.register-wrap input{width:92%;padding:12px 14px;margin:8px 0;border:1px solid #d1d5db;border-radius:10px;font-size:15px;outline:none;transition:box-shadow .2s,border-color .2s}
.register-wrap input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.register-wrap button{margin-top:6px;display:inline-block;width:95%;padding:12px 16px;border:none;border-radius:10px;cursor:pointer;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;font-weight:700;font-size:16px;box-shadow:0 10px 20px rgba(2,6,23,.25);transition:transform .15s ease,opacity .15s ease}
.register-wrap button:hover{opacity:.95;transform:translateY(-1px)}
.error{color:#e11d48;background:#fff1f2;border:1px solid #fecdd3;padding:10px 12px;border-radius:10px;font-size:14px;margin:10px auto 0;width:92%}
.login-link{margin-top:14px;font-size:14px;color:#374151}
.login-link a{color:#2563eb;text-decoration:none;font-weight:700}
.login-link a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown">
      <a href="#">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="#">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" onclick="document.getElementById('navLinks').classList.toggle('show')"><span></span><span></span><span></span></div>
</div>

<div class="page-space"></div>

<div class="register-wrap" aria-label="สมัครสมาชิกผู้ใช้">
  <h2>สมัครสมาชิกผู้ใช้</h2>
  <div class="register-sub">กรอกข้อมูลเพื่อเริ่มใช้งานแพลตฟอร์ม</div>
  <?php if (!empty($error)) : ?>
    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <form method="POST" action="">
    <input type="text" name="name" placeholder="ชื่อ-นามสกุล" required>
    <input type="email" name="email" placeholder="อีเมล" required>
    <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)" required>
    <input type="password" name="confirm" placeholder="ยืนยันรหัสผ่าน" required>
    <button type="submit">สมัครสมาชิก</button>
  </form>
  <div class="login-link">มีบัญชีแล้ว? <a href="login_user.php">เข้าสู่ระบบ</a></div>
</div>

<?php if ($popup_success): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'สมัครสมาชิกเรียบร้อยแล้ว',
  text: 'ยินดีต้อนรับเข้าสู่ระบบ',
  confirmButtonText: 'ตกลง'
}).then(() => {
  window.location.href = 'login_user.php';
});
</script>
<?php endif; ?>

</body>
</html>
