<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn, 'utf8mb4');
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$admins = [];
if ($rs = $conn->query("SELECT admin_id, first_name, last_name FROM admin")) {
    while ($row = $rs->fetch_assoc()) { $admins[(int)$row['admin_id']] = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')); }
    $rs->close();
}
$months = [];
$loginCounts = [];
if ($rs = $conn->query("SELECT admin_id, DATE_FORMAT(login_time,'%Y-%m') AS month, COUNT(*) AS total FROM login_history WHERE admin_id IS NOT NULL GROUP BY admin_id, month ORDER BY month ASC")){
    while($row = $rs->fetch_assoc()){
        $months[] = $row['month'];
        $aid = (int)$row['admin_id'];
        $loginCounts[$aid][$row['month']] = (int)$row['total'];
    }
    $rs->close();
}
$months = array_values(array_unique($months));
$chartDatasets = [];
$colors = ["#007bff","#28a745","#dc3545","#ffc107","#17a2b8","#6f42c1","#fd7e14","#20c997","#6610f2","#e83e8c"];
$i = 0;
foreach($admins as $aid => $name){
    $data = [];
    foreach($months as $m){ $data[] = $loginCounts[$aid][$m] ?? 0; }
    $chartDatasets[] = [ 'label' => $name !== '' ? $name : "Admin #$aid", 'data' => $data, 'backgroundColor' => $colors[$i % count($colors)] ];
    $i++;
}
$adminLogs = $conn->query("SELECT lh.history_id, lh.admin_id, lh.login_time, lh.logout_time, COALESCE(a.first_name,'ไม่ทราบชื่อ') AS first_name, COALESCE(a.last_name,'') AS last_name FROM login_history lh LEFT JOIN admin a ON lh.admin_id = a.admin_id ORDER BY lh.login_time DESC");
$unread_user = 0; $unread_ph = 0;
if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages WHERE is_read=0")) { $row = $res->fetch_assoc(); $unread_user = (int)($row['c'] ?? 0); $res->close(); }
if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages_photographer WHERE is_read=0")) { $row = $res->fetch_assoc(); $unread_ph = (int)($row['c'] ?? 0); $res->close(); }
$unread_total = $unread_user + $unread_ph;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ประวัติ Login - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
.navbar{width:100%;position:fixed;top:0;left:0;background:linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.burger{display:none}
@media (max-width:900px){.menu{display:none}.burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}.drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}.drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}.drawer a.active{background:#60a5fa;color:#fff}}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.section-card{width:100%;background:#fff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px}
.section-card h2{margin:0 0 12px;color:#0b122a;font-weight:900}
.card{background:#fff;border-radius:16px;border:1px solid #e5e7eb;box-shadow:0 6px 16px rgba(0,0,0,.06);padding:14px}
.small{font-size:12px;color:#6b7280}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid #e5e7eb}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left}
th{background:#eef2ff;position:sticky;top:0;z-index:1}
.chart-container{position:relative;width:100%;height:360px}
</style>
</head>
<body>
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php" class="active">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="open menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge" style="align-self:flex-start;margin-bottom:4px;">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">หน้าแรก</a>
  <a href="manage_users.php">สมาชิก</a>
  <a href="manage_photographers.php">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php" class="active">ประวัติ Login</a>
  <a href="logout.php" class="logout" style="color:#fff;">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="section-card">
      <h2>📝 ประวัติการเข้าสู่ระบบของ Admin</h2>
      <div class="card" style="margin-bottom:12px;">
        <div class="chart-container"><canvas id="adminChart"></canvas></div>
        <div class="small" style="margin-top:8px">แสดงจำนวนครั้งที่ผู้ดูแลระบบเข้าสู่ระบบ แยกตามบุคคลและเดือน</div>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:80px;">ID</th>
                <th>Admin</th>
                <th>Login</th>
                <th>Logout</th>
              </tr>
            </thead>
            <tbody>
              <?php while($l = $adminLogs->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$l['history_id'] ?></td>
                <td><?= h(trim(($l['first_name'] ?? '').' '.($l['last_name'] ?? ''))) ?></td>
                <td><?= h($l['login_time'] ?? '') ?></td>
                <td><?= h($l['logout_time'] ?: '-') ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <div class="small" style="margin-top:6px;">ตารางนี้แสดงประวัติการเข้าสู่ระบบของแอดมินทั้งหมด เรียงตามเวลาเข้าใช้งานล่าสุด</div>
      </div>
    </section>
  </div>
</div>

<script>
const burgerBtn=document.getElementById('burgerBtn');
const drawer=document.getElementById('drawerMenu');
let drawerOpen=false;
burgerBtn&&burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});
new Chart(document.getElementById('adminChart').getContext('2d'),{
  type:'bar',
  data:{labels:<?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>,datasets:<?= json_encode($chartDatasets, JSON_UNESCAPED_UNICODE) ?>},
  options:{
    responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,position:'bottom'},title:{display:true,text:'จำนวนครั้งที่ Admin เข้าระบบ (รายเดือน/รายคน)'}},
    scales:{y:{beginAtZero:true,ticks:{precision:0}}}
  }
});
</script>
</body>
</html>
