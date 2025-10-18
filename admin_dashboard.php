<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

/* ===== Counters (รวมทั้งระบบ) ===== */
$total_users = 0;
$total_photographers = 0;
$total_admins = 0;
$total_jobs = 0;

if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM users")) { $total_users = (int)($res->fetch_assoc()['cnt'] ?? 0); $res->close(); }
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM photographer")) { $total_photographers = (int)($res->fetch_assoc()['cnt'] ?? 0); $res->close(); }
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM admin")) { $total_admins = (int)($res->fetch_assoc()['cnt'] ?? 0); $res->close(); }
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM booking")) { $total_jobs = (int)($res->fetch_assoc()['cnt'] ?? 0); $res->close(); }

/* ===== สถานะงานทั้งหมด (สำหรับกราฟวงกลมซ้าย) ===== */
$status_raw = [];
if ($res = $conn->query("SELECT TRIM(LOWER(COALESCE(status,''))) AS s, COUNT(*) AS c FROM booking GROUP BY s")) {
    while($r=$res->fetch_assoc()){ $status_raw[$r['s']] = (int)$r['c']; }
    $res->close();
}
/* จัดกลุ่มสถานะแบบ QoL */
$map_done    = ['completed','เสร็จสิ้น','done','finish','finished'];
$map_cancel  = ['cancelled','canceled','ยกเลิก'];
$map_pending = ['','pending','รอดำเนินการ','processing','process','in progress'];

$stat_counts = ['เสร็จสิ้น'=>0,'ยกเลิก'=>0,'รอดำเนินการ'=>0,'อื่นๆ'=>0];
foreach($status_raw as $k=>$cnt){
    if (in_array($k, $map_done, true))         $stat_counts['เสร็จสิ้น'] += $cnt;
    elseif (in_array($k, $map_cancel, true))   $stat_counts['ยกเลิก'] += $cnt;
    elseif (in_array($k, $map_pending, true))  $stat_counts['รอดำเนินการ'] += $cnt;
    else                                       $stat_counts['อื่นๆ'] += $cnt;
}
$stat_labels = array_keys($stat_counts);
$stat_values = array_values($stat_counts);

/* ===== สัดส่วนสมาชิกทั้งหมด (กราฟขวา) ===== */
$member_labels = ['ผู้ใช้','ช่างภาพ','แอดมิน'];
$member_counts = [$total_users, $total_photographers, $total_admins];

/* ===== งานทั้งหมด + Pagination ===== */
$per_page = 20;
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page-1)*$per_page;

/* นับจำนวนงานทั้งหมด (ใช้ $total_jobs แล้วได้เลย) */
$total_pages = max(1, (int)ceil($total_jobs / $per_page));

/* ดึงรายการงานทั้งหมด พร้อมชื่อ “ลูกค้า–ช่างภาพ” */
$sql_all = "
    SELECT 
        b.booking_id,
        COALESCE(
            NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)), ''),
            NULLIF(TRIM(u.username), ''),
            CONCAT('ผู้ใช้ #', u.user_id)
        ) AS customer_name,
        COALESCE(
            NULLIF(TRIM(p.photographer_name), ''),
            NULLIF(TRIM(CONCAT(p.first_name,' ',p.last_name)), ''),
            CONCAT('ช่างภาพ #', p.photographer_id)
        ) AS photographer_name,
        COALESCE(NULLIF(TRIM(b.status),''),'รอดำเนินการ') AS status,
        b.booking_date
    FROM booking b
    JOIN users u ON b.user_id = u.user_id
    JOIN photographer p ON b.photographer_id = p.photographer_id
    ORDER BY b.booking_date DESC, b.booking_id DESC
    LIMIT ? OFFSET ?
";
$all_jobs = null;
if ($st=$conn->prepare($sql_all)) {
    $st->bind_param('ii', $per_page, $offset);
    $st->execute();
    $all_jobs = $st->get_result();
    // ไม่ปิด $st ยังได้ เพราะจะปิดท้ายไฟล์; ปิดตอนท้ายเพื่อความเรียบร้อย
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แอดมินแดชบอร์ด - Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#0f172a;
  background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{
  width:100%;
  position:fixed;top:0;left:0;
  background:linear-gradient(90deg,#0b1220,#111827);
  display:flex;align-items:center;
  padding:14px 0;
  box-shadow:0 4px 20px rgba(0,0,0,.25);
  z-index:100
}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{
  color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px
}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.burger{display:none}
@media (max-width:900px){
  .menu{display:none}
  .burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}
  .drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}
  .drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}
  .drawer a.active{background:#60a5fa;color:#fff}
}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 10px;color:#0b1220;font-weight:900}
.grid{display:grid;gap:16px}
.grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
@media (max-width:1024px){.grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.grid-4{grid-template-columns:1fr}}
.stat{
  background:linear-gradient(180deg,#ffffff,#faf5ff);
  border-radius:18px;padding:18px;border:1px solid #f3e8ff;
  box-shadow:0 6px 18px rgba(0,0,0,.08);
  display:flex;flex-direction:column;gap:6px;min-height:110px
}
.stat .t{font-weight:800;color:#6b21a8}
.stat .v{font-size:30px;font-weight:900;color:#0ea5e9}
.table-wrap{overflow:auto;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
table{width:100%;border-collapse:collapse;min-width:820px;background:#fff}
thead th{background:#eef2ff;color:#0b1220;text-align:left;padding:12px;font-weight:900}
tbody td{padding:12px;border-top:1px solid #eee}
.badge{
  display:inline-block;padding:6px 10px;border-radius:999px;font-weight:800;font-size:12px
}
.badge.success{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
.badge.danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}

/* ===== Chart area (2 pies side-by-side) ===== */
.chart-card{padding:18px}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:center}
@media (max-width:900px){.chart-grid{grid-template-columns:1fr}}
.chart-box{background:#fff;border:1px solid #eef0f3;border-radius:16px;padding:16px;box-shadow:0 6px 16px rgba(0,0,0,.08)}
.chart-title{margin:0 0 10px;font-weight:900;color:#0b1220}

/* ===== Pagination ===== */
.pagination{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px}
.pagination a,.pagination span{
  padding:6px 10px;border-radius:10px;border:1px solid #e5e7eb;text-decoration:none;color:#0f172a;font-weight:700
}
.pagination .current{background:#60a5fa;color:#fff;border-color:#3b82f6}
.pagination a:hover{background:#f3f4f6}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php" class="active">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php" class="active">หน้าแรก</a>
  <a href="manage_users.php">ผู้ใช้</a>
  <a href="manage_photographers.php">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">



    <!-- ===== Pie Charts Section ===== -->
    <section class="card chart-card">
      <h2>สรุปแบบสัดส่วน</h2>
      <div class="chart-grid">
        <div class="chart-box">
          <h3 class="chart-title">สถานะของงานทั้งหมด</h3>
          <canvas id="statusPie"></canvas>
        </div>
        <div class="chart-box">
          <h3 class="chart-title">สัดส่วนสมาชิกทั้งหมด</h3>
          <canvas id="membersPie"></canvas>
        </div>
      </div>
    </section>

    <!-- ===== ตารางงานทั้งหมด (มีลำดับ + เพจ) ===== -->
    <section class="card">
      <h2>งานทั้งหมด</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ลำดับ</th>
              <th>รหัสงาน</th>
              <th>ลูกค้า</th>
              <th>ช่างภาพ</th>
              <th>สถานะ</th>
              <th>วันที่</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $rownum = $offset;
          if ($all_jobs && $all_jobs->num_rows > 0):
            while($row = $all_jobs->fetch_assoc()):
              $rownum++;
              $status_raw = trim($row['status'] ?? 'รอดำเนินการ');
              $status_lc  = mb_strtolower($status_raw);
              $badge_html = '';
              if (in_array($status_lc, ['completed','เสร็จสิ้น','done','finish','finished'])) {
                $badge_html = '<span class="badge success">เสร็จสิ้น</span>';
              } elseif (in_array($status_lc, ['cancelled','canceled','ยกเลิก'])) {
                $badge_html = '<span class="badge danger">ยกเลิก</span>';
              } else {
                $badge_html = '<span class="badge warn">'.h($status_raw ?: 'รอดำเนินการ').'</span>';
              }
          ?>
            <tr>
              <td><?= (int)$rownum ?></td>
              <td><?= (int)($row['booking_id'] ?? 0) ?></td>
              <td><?= h($row['customer_name'] ?? '-') ?></td>
              <td><?= h($row['photographer_name'] ?? '-') ?></td>
              <td><?= $badge_html ?></td>
              <td><?= h($row['booking_date'] ?? '-') ?></td>
            </tr>
          <?php
            endwhile;
          else:
          ?>
            <tr><td colspan="6" style="text-align:center;color:#9ca3af;">ไม่มีข้อมูล</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination">
        <?php
          $q = $_GET; // เก็บ query อื่นๆ
          $q['page'] = 1;
          $first_url = '?'.http_build_query($q);
          $q['page'] = max(1, $page-1);
          $prev_url  = '?'.http_build_query($q);
          $q['page'] = min($total_pages, $page+1);
          $next_url  = '?'.http_build_query($q);
          $q['page'] = $total_pages;
          $last_url  = '?'.http_build_query($q);
        ?>
        <?php if ($page>1): ?>
          <a href="<?= h($first_url) ?>">« แรกสุด</a>
          <a href="<?= h($prev_url) ?>">‹ ก่อนหน้า</a>
        <?php else: ?>
          <span>« แรกสุด</span>
          <span>‹ ก่อนหน้า</span>
        <?php endif; ?>

        <span class="current">หน้า <?= (int)$page ?> / <?= (int)$total_pages ?></span>

        <?php if ($page<$total_pages): ?>
          <a href="<?= h($next_url) ?>">ถัดไป ›</a>
          <a href="<?= h($last_url) ?>">ท้ายสุด »</a>
        <?php else: ?>
          <span>ถัดไป ›</span>
          <span>ท้ายสุด »</span>
        <?php endif; ?>
      </div>
    </section>

  </div>
</div>

<script>
/* ===== Drawer ===== */
const burgerBtn = document.getElementById('burgerBtn');
const drawer = document.getElementById('drawerMenu');
let drawerOpen = false;
burgerBtn?.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});

/* ===== Chart Data from PHP ===== */
const statusLabels = <?= json_encode($stat_labels, JSON_UNESCAPED_UNICODE) ?>;
const statusValues = <?= json_encode($stat_values, JSON_UNESCAPED_UNICODE) ?>;

const memberLabels = <?= json_encode($member_labels, JSON_UNESCAPED_UNICODE) ?>;
const memberCounts = <?= json_encode($member_counts, JSON_UNESCAPED_UNICODE) ?>;

/* ===== Helper: generate pleasant HSL colors ===== */
function genColors(n, baseHue=210){
  const arr = [];
  for(let i=0;i<n;i++){
    const hue = (baseHue + i*(360/Math.max(n,1))) % 360;
    arr.push(`hsl(${hue} 70% 60%)`);
  }
  return arr;
}

const colorsStatus  = genColors(statusLabels.length, 20);
const colorsMembers = genColors(memberLabels.length, 120);

/* ===== Pie: งานทั้งหมดตามสถานะ ===== */
new Chart(document.getElementById('statusPie'), {
  type:'pie',
  data:{
    labels: statusLabels,
    datasets:[{ data: statusValues, backgroundColor: colorsStatus, borderColor: '#ffffff', borderWidth: 2 }]
  },
  options:{
    responsive:true,
    plugins:{
      legend:{ position:'right' },
      tooltip:{
        callbacks:{
          label: (ctx)=>{
            const label = ctx.label || '';
            const val = ctx.parsed || 0;
            const total = (ctx.dataset.data||[]).reduce((a,b)=>a+(+b||0),0) || 0;
            const pct = total ? ((val/total)*100).toFixed(1) : 0;
            return `${label}: ${val} งาน (${pct}%)`;
          }
        }
      }
    }
  }
});

/* ===== Pie: สัดส่วนสมาชิกทั้งหมด ===== */
new Chart(document.getElementById('membersPie'), {
  type:'pie',
  data:{
    labels: memberLabels,
    datasets:[{ data: memberCounts, backgroundColor: colorsMembers, borderColor: '#ffffff', borderWidth: 2 }]
  },
  options:{
    responsive:true,
    plugins:{
      legend:{ position:'right' },
      tooltip:{
        callbacks:{
          label: (ctx)=>{
            const label = ctx.label || '';
            const val = ctx.parsed || 0;
            const total = (ctx.dataset.data||[]).reduce((a,b)=>a+(+b||0),0) || 0;
            const pct = total ? ((val/total)*100).toFixed(1) : 0;
            return `${label}: ${val} คน (${pct}%)`;
          }
        }
      }
    }
  }
});
</script>
</body>
</html>
<?php
if (isset($all_jobs) && $all_jobs instanceof mysqli_result) { $all_jobs->close(); }
mysqli_close($conn);
