<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit();
}

$photographer_id = $_SESSION['photographer_id'];

$stmt = $conn->prepare("
    SELECT j.job_id, e.expertise_name AS job_type_name, j.job_date, j.status
    FROM job j
    LEFT JOIN expertise e ON j.job_type_id = e.expertise_id
    WHERE j.photographer_id = ?
    ORDER BY j.job_date DESC
");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตารางงานของฉัน</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Prompt',sans-serif; }

body {
    min-height:100vh;
    background: linear-gradient(135deg, #ffecd2, #fcb69f);
    display:flex;
    justify-content:center;
    align-items:center;
    flex-direction:column;
    margin:0;
}

.container {
    max-width:700px;
    width:90%;
}


/* Navbar */
.navbar{
    width:100%;
    position:fixed;
    top:0; left:0;
    background: rgba(0,0,0,0.15);
    backdrop-filter: blur(12px);
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:14px 40px;
    box-shadow:0 4px 20px rgba(0,0,0,0.1);
    z-index:100;
}
.logo { font-size:24px; font-weight:bold; color:#fff; text-decoration:none; }
.menu { display:flex; gap:25px; }
.menu a { text-decoration:none; font-size:15px; color:#fff; font-weight:500; padding:8px 12px; border-radius:8px; transition:0.3s; }
.menu a.logout { background:#d32f2f; }
.menu a.logout:hover { background:#b71c1c; }
.menu a:hover { background: rgba(255,255,255,0.25); color:#000; }

/* Container */
.container { 
    max-width:700px; 
    width:90%; 
    margin: 0 auto; /* กึ่งกลางแนวนอน */
    padding:40px 20px; 
    display:flex;
    flex-direction:column;
    align-items:center; /* กึ่งกลางแนวนอนของเนื้อหา */
}

/* Header */
h1 { text-align:center; color:#4a148c; margin-bottom:25px; font-size:28px; }

/* Table Card */
.table-card {
    width:100%;
    background:#fff;
    border-radius:15px;
    box-shadow:0 8px 20px rgba(0,0,0,0.15);
    padding:25px;
    overflow-x:auto;
    transition:0.3s;
}
.table-card:hover { box-shadow:0 12px 30px rgba(0,0,0,0.2); }

table { width:100%; border-collapse:collapse; font-size:15px; }
th, td { padding:12px 15px; text-align:left; border-bottom:1px solid #eee; }
th { background-color:#4a148c; color:white; font-weight:500; text-transform:uppercase; }
tr:nth-child(even){ background:#f4f4f4; }
tr:hover { background: rgba(255, 182, 193, 0.2); }

/* Buttons */
a.back-btn,a.edit-btn{
    display:inline-block;
    margin-top:15px;
    padding:10px 16px;
    background:#4a148c;
    color:white;
    border-radius:10px;
    text-decoration:none;
    font-weight:bold;
    transition:0.3s;
}
a.back-btn:hover,a.edit-btn:hover{ background:#6a1b9a; }

/* Status Labels */
.status{ padding:6px 10px; border-radius:8px; font-weight:bold; color:white; text-transform:capitalize; display:inline-block; }
.status-pending{background:#ff9800;}
.status-completed{background:#4caf50;}
.status-cancelled{background:#f44336;}

@media(max-width:768px){
    .container{padding:100px 15px 30px;}
    table{font-size:14px;}
    th, td{padding:10px;}
    a.back-btn,a.edit-btn{padding:8px 12px; font-size:14px;}
}
@media(max-width:480px){
    h1{font-size:24px;}
    table{font-size:13px;}
    th, td{padding:8px;}
}
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <a href="photographer_dashboard.php" class="logo">📸 Cameraman</a>
    <div class="menu">
        <a href="photographer_dashboard.php">หน้าแรก</a>
        <a href="photographer_edit_profile.php">โปรไฟล์</a>
        <a href="logout.php" class="logout" onclick="return confirm('ต้องการออกจากระบบหรือไม่?')">ออกจากระบบ</a>
    </div>
</div>

<div class="container">
    <h1>ตารางงานของฉัน</h1>

    <div class="table-card">
    <?php if($result->num_rows>0): ?>
    <table>
        <thead>
            <tr>
                <th>รหัสงาน</th>
                <th>ประเภทงาน</th>
                <th>วันที่งาน</th>
                <th>สถานะ</th>
                <th>จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php while($job = $result->fetch_assoc()): 
            $status = strtolower($job['status'] ?? '');
            $status_class = $status==='completed'?'status-completed':($status==='pending'?'status-pending':'status-cancelled');
        ?>
            <tr>
                <td><?= htmlspecialchars((string)($job['job_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($job['job_type_name'] ?? 'ไม่ระบุ'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($job['job_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="status <?= $status_class ?>"><?= htmlspecialchars($job['status'] ?? '') ?></span></td>
                <td><a class="edit-btn" href="edit_job.php?job_id=<?= urlencode($job['job_id']) ?>">แก้ไข</a></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p style="text-align:center; padding:20px;">ยังไม่มีงานที่ได้รับมอบหมาย</p>
    <?php endif; ?>
    </div>

    <a href="photographer_dashboard.php" class="back-btn">กลับไปหน้าแดชบอร์ด</a>
</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
