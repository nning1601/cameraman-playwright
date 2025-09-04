<?php
/*************************************************
 * booking_add.php (ธีม Cameraman)
 * - Navbar โปร่งใส + blur, gradient background
 * - เมนู responsive (desktop + mobile hamburger/drawer)
 * - ฟอร์มเพิ่มการจอง (ผู้ใช้ / ช่างภาพ / ประเภทงาน / วันที่-เวลา)
 *************************************************/
session_start();
require 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

/* ===== Helper ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

/* ===== Badge: นับยังไม่อ่านรวม (ลูกค้า + ช่างภาพ) ===== */
$unread_user = 0; $unread_ph = 0;
if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages WHERE is_read=0")) {
  $row = $res->fetch_assoc(); $unread_user = (int)($row['c'] ?? 0); $res->close();
}
if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages_photographer WHERE is_read=0")) {
  $row = $res->fetch_assoc(); $unread_ph = (int)($row['c'] ?? 0); $res->close();
}
$unread_total = $unread_user + $unread_ph;

/* ===== ดึงข้อมูล dropdown ===== */
$photographers = $conn->query("SELECT photographer_id, first_name, last_name FROM photographer ORDER BY first_name, last_name");
$jobTypes      = $conn->query("SELECT job_type_id, job_type_name FROM jobtype ORDER BY job_type_name");
$customers     = $conn->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name, last_name");

/* ===== เพิ่มการจอง ===== */
$error = '';
if (isset($_POST['add_booking'])) {
    $user_id         = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $photographer_id = isset($_POST['photographer_id']) ? intval($_POST['photographer_id']) : 0;
    $job_type_id     = isset($_POST['job_type_id']) ? intval($_POST['job_type_id']) : 0;
    $booking_date    = trim($_POST['booking_date'] ?? '');
    $booking_time    = trim($_POST['booking_time'] ?? '');

    if ($user_id == 0 || $photographer_id == 0 || $job_type_id == 0 || $booking_date === '' || $booking_time === '') {
        $error = "⚠️ กรุณากรอกข้อมูลให้ครบถ้วน";
    } else {
        $stmt = $conn->prepare("INSERT INTO booking (user_id, photographer_id, job_type_id, booking_date, booking_time) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iiiss", $user_id, $photographer_id, $job_type_id, $booking_date, $booking_time);
            if ($stmt->execute()) {
                header("Location: manage_bookings.php?msg=added");
                exit;
            } else {
                $error = "❌ ไม่สามารถบันทึกได้: ".h($conn->error);
            }
            $stmt->close();
        } else {
            $error = "❌ เตรียมคำสั่งไม่สำเร็จ: ".h($conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>เพิ่มการจอง - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{ box-sizing:border-box; }
html,body{ height:100%; }
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#1f2937;
  background: linear-gradient(135deg, #ffecd2, #fcb69f);
  display:flex; flex-direction:column;
}

/* ===== Navbar (โปร่งใส + blur) ===== */
.navbar{
  width:100%;
  position:fixed; top:0; left:0;
  background: rgba(0,0,0,0.15);
  backdrop-filter: blur(12px);
  display:flex; align-items:center;
  padding:12px 0;
  box-shadow:0 4px 20px rgba(0,0,0,0.1);
  z-index:100;
}
.nav-inner{
  width:100%;
  display:flex; align-items:center; justify-content:space-between;
  gap:10px; padding:0 16px;
}
.logo{
  font-size:22px; font-weight:800; color:#fff;
  display:flex; align-items:center; gap:8px;
}
.menu{
  display:flex; align-items:center; gap:8px; margin-left:auto; white-space:nowrap;
}
.menu a,.menu .badge{
  color:#fff; text-decoration:none; padding:8px 12px; border-radius:999px; transition:.2s;
  background: rgba(255,255,255,0.15);
  display:inline-flex; align-items:center; gap:6px; font-size:14px; line-height:1.1;
}
.menu a:hover{ background: rgba(255,255,255,0.25); color:#000; }
.menu a.active{ background:#fff; color:#4a148c; box-shadow:0 4px 12px rgba(0,0,0,.12); }
.menu .logout{ background:#ef4444; }
.menu .logout:hover{ background:#dc2626; color:#fff; }

/* ===== Mobile hamburger & drawer ===== */
.burger{ display:none; }
@media (max-width: 900px){
  .menu{ display:none; }
  .burger{ display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:22px; padding:8px 12px; border-radius:12px; background: rgba(255,255,255,0.15); }
  .drawer{
    position:fixed; top:64px; right:12px; left:12px; background:rgba(255,255,255,0.95);
    border-radius:16px; padding:12px; display:none; flex-direction:column; gap:8px; z-index:120;
    box-shadow:0 10px 30px rgba(0,0,0,0.2);
  }
  .drawer a,.drawer .badge{ color:#4a148c; background:#f3e8ff; }
  .drawer a.active{ background:#4a148c; color:#fff; }
}

/* ===== Layout ===== */
.wrapper{
  flex:1; display:flex; justify-content:center; align-items:flex-start;
  padding:118px 16px 48px;
}
.container{ width:100%; max-width:720px; display:flex; flex-direction:column; gap:16px; }
.card{
  width:100%; background:#fff; border-radius:20px;
  box-shadow:0 8px 20px rgba(0,0,0,0.15);
  padding:22px;
}
.card h2{ margin:0 0 16px; color:#4a148c; text-align:center; }

/* ===== Form ===== */
.row{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
@media (max-width: 640px){ .row{ grid-template-columns:1fr; } }
.form-group{ display:flex; flex-direction:column; gap:6px; }
label{ font-weight:800; }
select,input{ padding:10px 12px; border:1px solid #e5e7eb; border-radius:12px; font-family:inherit; }
.actions{ display:flex; justify-content:space-between; gap:12px; margin-top:16px; }
.btn{ appearance:none; border:0; border-radius:12px; padding:10px 16px; font-weight:800; cursor:pointer; }
.btn-primary{ background:linear-gradient(90deg,#6a11cb,#2575fc); color:#fff; }
.btn-primary:hover{ opacity:.9; }
.btn-light{ background:#f8fafc; border:1px solid #e5e7eb; }

/* ===== Alert ===== */
.alert{ padding:10px 12px; border-radius:12px; margin-bottom:12px; }
.alert-danger{ background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.35); color:#7f1d1d; }
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>

    <!-- Desktop menu -->
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">🏠 หน้าแรก</a>
      <a href="manage_users.php">👥 ผู้ใช้</a>
      <a href="manage_photographers.php">📸 ช่างภาพ</a>
      <a href="manage_bookings.php" class="active">📅 การจอง</a>
      <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
      <a href="admin_inbox_photographer.php">📥 ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">📥 ข้อความลูกค้า</a>
      <a href="login_history.php">📝 ประวัติ Login</a>
      <a href="logout.php" class="logout">🚪 ออกจากระบบ</a>
    </div>

    <!-- Mobile burger -->
    <button class="burger" id="burgerBtn" aria-label="open menu">☰</button>
  </div>
</div>

<!-- Mobile drawer -->
<div class="drawer" id="drawerMenu">
  <span class="badge" style="align-self:flex-start; margin-bottom:4px;">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">🏠 หน้าแรก</a>
  <a href="manage_users.php">👥 ผู้ใช้</a>
  <a href="manage_photographers.php">📸 ช่างภาพ</a>
  <a href="manage_bookings.php" class="active">📅 การจอง</a>
  <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
  <a href="admin_inbox_photographer.php">📥 ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">📥 ข้อความลูกค้า</a>
  <a href="login_history.php">📝 ประวัติ Login</a>
  <a href="logout.php" class="logout" style="color:#fff;">🚪 ออกจากระบบ</a>
</div>

<!-- Content -->
<div class="wrapper">
  <div class="container">
    <div class="card">
      <h2>➕ เพิ่มการจอง</h2>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="form-group">
          <label>ลูกค้า</label>
          <select name="user_id" required>
            <option value="">-- เลือกลูกค้า --</option>
            <?php while($c = $customers->fetch_assoc()): ?>
              <option value="<?= (int)$c['user_id'] ?>" <?= (isset($_POST['user_id']) && (int)$_POST['user_id']===(int)$c['user_id']) ? 'selected' : '' ?>>
                <?= h(trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''))) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label>ช่างภาพ</label>
          <select name="photographer_id" required>
            <option value="">-- เลือกช่างภาพ --</option>
            <?php while($p = $photographers->fetch_assoc()): ?>
              <option value="<?= (int)$p['photographer_id'] ?>" <?= (isset($_POST['photographer_id']) && (int)$_POST['photographer_id']===(int)$p['photographer_id']) ? 'selected' : '' ?>>
                <?= h(trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''))) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label>ประเภทงาน</label>
          <select name="job_type_id" required>
            <option value="">-- เลือกประเภทงาน --</option>
            <?php while($j = $jobTypes->fetch_assoc()): ?>
              <option value="<?= (int)$j['job_type_id'] ?>" <?= (isset($_POST['job_type_id']) && (int)$_POST['job_type_id']===(int)$j['job_type_id']) ? 'selected' : '' ?>>
                <?= h($j['job_type_name'] ?? '') ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="row" style="margin-top:10px;">
          <div class="form-group">
            <label>วันที่</label>
            <input type="date" name="booking_date" value="<?= h($_POST['booking_date'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>เวลา</label>
            <input type="time" name="booking_time" value="<?= h($_POST['booking_time'] ?? '') ?>" required>
          </div>
        </div>

        <div class="actions">
          <a href="manage_bookings.php" class="btn btn-light">⬅️ ย้อนกลับ</a>
          <button type="submit" name="add_booking" class="btn btn-primary">บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ===== Mobile drawer toggle ===== */
const burgerBtn = document.getElementById('burgerBtn');
const drawer    = document.getElementById('drawerMenu');
let drawerOpen  = false;
burgerBtn?.addEventListener('click', () => {
  drawerOpen = !drawerOpen;
  if (drawer) drawer.style.display = drawerOpen ? 'flex' : 'none';
});
</script>
</body>
</html>
