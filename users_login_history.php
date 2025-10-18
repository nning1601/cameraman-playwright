<?php
session_start();
require 'db.php';

// ตรวจสอบ admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

// ดึงรายชื่อ Users
$users = [];
$userResult = $conn->query("SELECT user_id, first_name, last_name FROM users");
while($row = $userResult->fetch_assoc()){
    $users[$row['user_id']] = $row['first_name'].' '.$row['last_name'];
}

// ดึงข้อมูล login history ของ Users แยกตามเดือน
$loginData = $conn->query("
    SELECT user_id, DATE_FORMAT(login_time,'%Y-%m') AS month, COUNT(*) AS total
    FROM login_history
    WHERE user_id IS NOT NULL
    GROUP BY user_id, month
    ORDER BY month ASC
");

// เตรียม labels ของเดือน
$months = [];
$loginCounts = [];
while($row = $loginData->fetch_assoc()){
    $months[] = $row['month'];
    $loginCounts[$row['user_id']][$row['month']] = (int)$row['total'];
}
$months = array_values(array_unique($months));

// Dataset สำหรับ Chart.js
$chartDatasets = [];
$colors = ["#007bff","#28a745","#dc3545","#ffc107","#17a2b8","#6f42c1","#fd7e14"];
$i = 0;
foreach($users as $user_id => $name){
    $data = [];
    if(!isset($loginCounts[$user_id])) $loginCounts[$user_id] = [];
    foreach($months as $m){
        $data[] = $loginCounts[$user_id][$m] ?? 0;
    }
    $chartDatasets[] = [
        'label' => $name,
        'data' => $data,
        'backgroundColor' => $colors[$i % count($colors)]
    ];
    $i++;
}

// ดึงข้อมูลประวัติ login ของ Users
$userLogs = $conn->query("
    SELECT lh.history_id, lh.user_id, lh.login_time, lh.logout_time,
           COALESCE(u.first_name,'ไม่ทราบชื่อ') AS first_name,
           COALESCE(u.last_name,'') AS last_name
    FROM login_history lh
    LEFT JOIN users u ON lh.user_id = u.user_id
    WHERE lh.user_id IS NOT NULL
    ORDER BY lh.login_time DESC
");
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สถิติผู้ใช้</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f0f2f5; margin:0; }
.navbar { background:#0d6efd; }
.navbar .nav-link { color:white; margin-right:10px; transition:0.3s; }
.navbar .nav-link:hover { background-color: rgba(255,255,255,0.2); border-radius:5px; }
.navbar .nav-link.active { font-weight:bold; text-decoration:underline; }
.navbar-brand { font-weight:bold; display:flex; align-items:center; }
.navbar-brand span { margin-left:10px; font-size:1.2em; }
.container { max-width:1200px; margin:20px auto; }
h2 { text-align:center; color:#333; margin-bottom:30px; }
.card { background:white; border-radius:15px; padding:20px; box-shadow:0 4px 15px rgba(0,0,0,0.1); margin-bottom:25px; }
.chart-container { position: relative; width:100%; height:400px; }
.small-text { font-size:0.9em; color:#555; margin-top:10px; text-align:center; }
.table-container { overflow-y:auto; max-height:450px; border-radius:10px; box-shadow:0 4px 10px rgba(0,0,0,0.05); background:white; padding:15px; margin-top:20px; }
table { width:100%; border-collapse: collapse; }
th, td { padding:12px; text-align:center; border-bottom:1px solid #dee2e6; }
th { background:#0d6efd; color:white; position: sticky; top:0; }
tr:hover { background-color:#f1f3f5; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow">
  <div class="container-fluid">
    <a class="navbar-brand" href="admin_dashboard.php">
        📷 <span>Cameraman</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="admin_dashboard.php">🏠 หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_users.php">👥 ผู้ใช้</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_photographers.php">📸 ช่างภาพ</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_bookings.php">📅 การจอง</a></li>
        <li class="nav-item"><a class="nav-link active" href="login_history.php">🔑 Admin Login History</a></li>
        <li class="nav-item"><a class="nav-link" href="users_login_history.php">👤 ผู้ใช้ Login History</a></li>
        <li class="nav-item"><a class="nav-link" href="logout_admin.php">🚪 ออกจากระบบ</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container">
    <h2>📊 สถิติและประวัติการเข้าสู่ระบบของผู้ใช้</h2>
    
    <!-- กราฟ -->
    <div class="card">
        <div class="chart-container">
            <canvas id="userChart"></canvas>
        </div>
        <div class="small-text mt-3">
            ประวัติการเข้าสู่ระบบของผู้ใช้<br>
            ตารางด้านล่างแสดงรายละเอียด Login และ Logout ของแต่ละผู้ใช้
        </div>
    </div>

    <!-- ตารางประวัติ -->
    <div class="table-container">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ผู้ใช้</th>
                    <th>Login</th>
                    <th>Logout</th>
                </tr>
            </thead>
            <tbody>
                <?php while($l = $userLogs->fetch_assoc()): ?>
                <tr>
                    <td><?= $l['history_id'] ?></td>
                    <td><?= $l['first_name'].' '.$l['last_name'] ?></td>
                    <td><?= $l['login_time'] ?></td>
                    <td><?= $l['logout_time'] ?: '-' ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <div class="small-text mt-2">
            ตารางนี้แสดง <strong>ประวัติการเข้าสู่ระบบของผู้ใช้</strong>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ctxUser = document.getElementById('userChart').getContext('2d');
new Chart(ctxUser, {
    type: 'bar',
    data: {
        labels: <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>,
        datasets: <?= json_encode($chartDatasets, JSON_UNESCAPED_UNICODE) ?>
    },
    options: {
        responsive:true,
        maintainAspectRatio:false,
        plugins:{
            legend:{display:true, position:'bottom'},
            title:{display:true, text:'จำนวนครั้งที่ผู้ใช้เข้าสู่ระบบ แยกตามคน'}
        },
        scales:{
            y:{beginAtZero:true, ticks:{precision:0}},
            x:{ stacked: false }
        }
    }
});
</script>

</body>
</html>
