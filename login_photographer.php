<?php
session_start();
require_once 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

$error = "";
$popup_success = false;        // ให้ส่วน HTML รู้ว่าต้องแสดงป๊อปอัพ
$welcome_name   = "";          // ชื่อที่จะโชว์ในป๊อปอัพ

// สร้างตารางประวัติการเข้าสู่ระบบ (ถ้ายังไม่มี)
$conn->query("
CREATE TABLE IF NOT EXISTS login_history_photographer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  photographer_id INT NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (photographer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "กรุณากรอกอีเมลและรหัสผ่านให้ครบถ้วน";
    } else {
        $stmt = $conn->prepare("
            SELECT login_id, password_hash, photographer_id
            FROM login
            WHERE username = ? AND type_id = 2
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password_hash'])) {
                    // ตั้ง session
                    session_regenerate_id(true);
                    $_SESSION['photographer_id'] = (int)$row['photographer_id'];
                    $_SESSION['username']        = $username;
                    $_SESSION['user_type']       = 'photographer';

                    // ดึงชื่อช่างภาพ เพื่อแสดงในป๊อปอัพ
                    $welcome_name = $username;
                    if ($row['photographer_id']) {
                        if ($s2 = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(photographer_name),''), CONCAT(TRIM(COALESCE(first_name,'')),' ',TRIM(COALESCE(last_name,'')))) AS name FROM photographer WHERE photographer_id = ? LIMIT 1")) {
                            $pid = (int)$row['photographer_id'];
                            $s2->bind_param("i", $pid);
                            $s2->execute();
                            $rs2 = $s2->get_result();
                            if ($r2 = $rs2->fetch_assoc()) {
                                $nm = trim($r2['name'] ?? '');
                                if ($nm !== '') $welcome_name = $nm;
                            }
                            $s2->close();
                        }
                    }

                    // บันทึกประวัติการเข้าสู่ระบบ
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    if ($stmt_hist = $conn->prepare("
                        INSERT INTO login_history_photographer (photographer_id, ip_address, user_agent)
                        VALUES (?, ?, ?)
                    ")) {
                        $stmt_hist->bind_param("iss", $row['photographer_id'], $ip, $ua);
                        try { $stmt_hist->execute(); } catch (\Throwable $e) {}
                        $stmt_hist->close();
                    }

                    // ใช้ป๊อปอัพแทนการ header redirect
                    $popup_success = true;
                } else {
                    $error = "รหัสผ่านไม่ถูกต้อง";
                }
            } else {
                $error = "ไม่พบชื่อผู้ใช้ หรือไม่ใช่บัญชีช่างภาพ";
            }
            $stmt->close();
        } else {
            $error = "เกิดข้อผิดพลาดในการเข้าสู่ระบบ";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เข้าสู่ระบบช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff;letter-spacing:.2px}
.nav-links{list-style:none;display:flex;gap:18px}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:15px;color:#fff;padding:10px 14px;border-radius:10px;transition:transform .15s,background .15s}
.nav-links a:hover{background:#22d3ee;color:#0f172a;transform:translateY(-1px)}
.dropdown-menu{position:absolute;top:100%;left:0;background:#ffffff;border:1px solid rgba(2,6,23,.08);border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);min-width:180px;display:none;flex-direction:column;overflow:hidden}
.dropdown-menu a{display:block;color:#0f172a;padding:12px 14px}
.dropdown-menu a:hover{background:#0ea5e9;color:#fff}
.dropdown:hover .dropdown-menu{display:flex}
.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer}
.hamburger span{width:26px;height:3px;background:#fff;border-radius:2px}
@media (max-width:768px){
  .nav-links{position:fixed;top:58px;right:-100%;background:#0f172a;width:68vw;max-width:320px;height:calc(100vh - 58px);padding:18px;flex-direction:column;gap:8px;border-left:1px solid rgba(255,255,255,.08);transition:right .25s}
  .nav-links.show{right:0}
  .dropdown-menu{position:static;border:none;box-shadow:none;background:transparent}
  .dropdown-menu a{color:#e2e8f0;padding:10px 12px}
  .dropdown-menu a:hover{background:#22d3ee;color:#0f172a}
  .hamburger{display:flex}
}
.header-spacer{height:96px}
.wrap{width:100%;max-width:440px;padding:0 20px 40px}
.card{background:#fff;border-radius:16px;padding:26px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06);transition:transform .2s}
.card:hover{transform:translateY(-3px)}
.card h2{text-align:center;color:#0f172a;margin-bottom:10px;font-size:26px}
.subtext{text-align:center;color:#6b7280;font-size:14px;margin-bottom:4px}
.form{display:grid;gap:10px;margin-top:10px}
.input{width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;outline:none;transition:box-shadow .2s,border-color .2s}
.input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.button{margin-top:6px;width:100%;padding:12px 16px;border:none;border-radius:10px;cursor:pointer;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;font-weight:700;font-size:16px;box-shadow:0 10px 20px rgba(2,6,23,.25);transition:transform .15s,opacity .15s}
.button:hover{opacity:.95;transform:translateY(-1px)}
.error{background:#fff1f2;border:1px solid #fecdd3;color:#e11d48;padding:10px 12px;border-radius:10px;font-size:14px;margin:10px 0;text-align:center}
.helper{margin-top:12px;text-align:center;font-size:14px;color:#374151}
.helper a{color:#2563eb;text-decoration:none;font-weight:700}
.helper a:hover{text-decoration:underline}
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

<div class="header-spacer"></div>

<div class="wrap">
  <div class="card">
    <h2>เข้าสู่ระบบช่างภาพ</h2>
    <p class="subtext">กรอกอีเมลและรหัสผ่านเพื่อเข้าแดชบอร์ดช่างภาพ</p>
    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" class="form" action="">
      <input class="input" type="email" name="username" placeholder="อีเมล" required autofocus>
      <input class="input" type="password" name="password" placeholder="รหัสผ่าน" required>
      <button class="button" type="submit">เข้าสู่ระบบ</button>
    </form>
    <div class="helper">ยังไม่มีบัญชี? <a href="register_photographer.php">สมัครสมาชิกช่างภาพ</a></div>
  </div>
</div>

<?php if ($popup_success): ?>
<script>
// ใช้ JSON เพื่อความปลอดภัยในการฝังชื่อใน JS
const welcomeName = <?= json_encode($welcome_name, JSON_UNESCAPED_UNICODE) ?>;
Swal.fire({
  icon: 'success',
  title: 'ยินดีต้อนรับเข้าสู่ระบบ',
  text: 'สวัสดี ' + welcomeName,
  confirmButtonText: 'ไปที่แดชบอร์ด'
}).then(() => {
  window.location.href = 'photographer_dashboard.php';
});
</script>
<?php endif; ?>

</body>
</html>
