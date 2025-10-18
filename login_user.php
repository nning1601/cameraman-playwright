<?php
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

$error = '';
$popup_success = false;
$displayName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email !== '' && $password !== '') {
        $stmt = $conn->prepare("
            SELECT user_id, username, first_name, last_name, email, password_hash
            FROM users
            WHERE email = ? AND role = 'customer'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password_hash'])) {
                    $_SESSION['user_id'] = (int)$row['user_id'];
                    $displayName = $row['username'];
                    if (!$displayName || trim($displayName) === '') {
                        $fn = trim($row['first_name'] ?? '');
                        $ln = trim($row['last_name'] ?? '');
                        $displayName = trim($fn . ' ' . $ln);
                        if ($displayName === '') $displayName = $row['email'];
                    }
                    $_SESSION['user_name'] = $displayName;
                    $popup_success = true; // ใช้แสดงป๊อปอัพแทน redirect ตรงๆ
                } else {
                    $error = "รหัสผ่านไม่ถูกต้อง";
                }
            } else {
                $error = "ไม่พบผู้ใช้งานนี้";
            }
            $stmt->close();
        } else {
            $error = "เกิดข้อผิดพลาดในการเข้าสู่ระบบ";
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เข้าสู่ระบบ - Cameraman</title>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff;letter-spacing:.2px}
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
.page-space{height:100px}
.login-box{background:#fff;padding:26px 20px 22px;border-radius:20px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06);width:min(480px,94%);text-align:center;margin:28px auto 40px;transition:transform .2s}
.login-box:hover{transform:translateY(-3px)}
.login-box h2{margin-bottom:6px;color:#0f172a;font-size:26px}
.subtext{color:#6b7280;font-size:14px;margin-bottom:14px}
.login-box input{width:92%;padding:12px 14px;margin:8px 0;border:1px solid #d1d5db;border-radius:10px;font-size:15px;outline:none;transition:box-shadow .2s,border-color .2s}
.login-box input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.login-box button{margin-top:6px;width:95%;padding:12px 16px;border:none;border-radius:10px;cursor:pointer;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;font-weight:700;font-size:16px;box-shadow:0 10px 20px rgba(2,6,23,.25);transition:transform .15s ease,opacity .15s ease}
.login-box button:hover{transform:translateY(-1px);opacity:.95}
.error{color:#e11d48;background:#fff1f2;border:1px solid #fecdd3;padding:10px 12px;border-radius:10px;font-size:14px;margin:10px auto 0;width:92%}
.register-link{margin-top:14px;font-size:14px;color:#374151}
.register-link a{color:#2563eb;text-decoration:none;font-weight:700}
.register-link a:hover{text-decoration:underline}
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

<div class="login-box" aria-label="เข้าสู่ระบบสมาชิก">
  <h2>เข้าสู่ระบบสมาชิก</h2>
  <div class="subtext">กรอกอีเมลและรหัสผ่านเพื่อเข้าสู่ระบบ</div>
  <?php if (!empty($error)): ?>
    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <form method="POST" action="">
    <input type="email" name="email" placeholder="อีเมล" required>
    <input type="password" name="password" placeholder="รหัสผ่าน" required>
    <button type="submit">เข้าสู่ระบบ</button>
  </form>
  <div class="register-link">ยังไม่มีบัญชี? <a href="register_user.php">สมัครสมาชิก</a></div>
</div>

<?php if ($popup_success): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'ยินดีต้อนรับเข้าสู่ระบบ',
  text: 'สวัสดี <?php echo htmlspecialchars($displayName, ENT_QUOTES, "UTF-8"); ?>',
  confirmButtonText: 'ไปที่ Dashboard'
}).then(() => {
  window.location.href = 'user_dashboard.php';
});
</script>
<?php endif; ?>

</body>
</html>
