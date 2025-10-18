<?php
/* delivery_job_select.php — เลือกงานของฉัน (ผู้ใช้)
 * แสดง "ทุกงาน" ของผู้ใช้คนนี้จากตาราง job โดยตรง
 * คอลัมน์หลัก: job_id, job_date, job_time, location, photographer (ชื่อ)
 * เสริม: จำนวนลิงก์ส่งงาน (จาก delivery_links), เวลาส่งล่าสุด
 * ธีม/เมนูให้เหมือน user_dashboard.php
 */
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login_user.php"); exit; }

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Bangkok');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* สร้างสถานะเวลาแบบคร่าว ๆ จาก job_date + job_time */
function job_time_status(?string $d, ?string $t): array {
  if (!$d) return ['-', 'pill-muted'];
  $dtStr = $d . ' ' . ($t ?: '00:00:00');
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dtStr) ?: DateTime::createFromFormat('Y-m-d H:i', $dtStr);
  if (!$dt) return ['-', 'pill-muted'];

  $now = new DateTime('now');
  $today = (new DateTime('today'))->format('Y-m-d');
  $day = $dt->format('Y-m-d');
  if ($day > $today) return ['กำลังจะถึง', 'pill-upcoming'];
  if ($day < $today) return ['ผ่านมาแล้ว', 'pill-past'];
  // เท่ากับวันนี้
  if ($dt >= $now) return ['วันนี้ (ยังไม่ถึงเวลา)', 'pill-today'];
  return ['วันนี้ (ผ่านมาแล้ว)', 'pill-today'];
}

$viewer_id = (int)($_SESSION['user_id'] ?? 0);
$rows = [];

if ($viewer_id > 0) {
  /* ดึง "ทุกงาน" ของผู้ใช้คนนี้ */
  $sql = "
    SELECT
      j.job_id,
      j.job_date,
      j.job_time,
      j.location,
      j.photographer_id,
      p.first_name AS p_first,
      p.last_name  AS p_last,
      /* เมตริกจาก delivery_links */
      (SELECT COUNT(*) FROM delivery_links dl WHERE dl.booking_id = j.job_id) AS link_count,
      (SELECT MAX(dl2.delivered_at) FROM delivery_links dl2 WHERE dl2.booking_id = j.job_id) AS last_delivered_at
    FROM job j
    LEFT JOIN photographer p ON p.photographer_id = j.photographer_id
    WHERE j.user_id = ?
    /* เรียง: วันนี้/อนาคต/อดีต โดยอิงวันที่ แล้วเวลา แล้ว ID */
    ORDER BY
      (j.job_date IS NULL) ASC,
      j.job_date DESC,
      j.job_time DESC,
      j.job_id DESC
  ";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('i', $viewer_id);
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เลือกงานของฉัน - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
:root{--nav-h:72px;--grad:linear-gradient(90deg,#7c3aed,#2563eb)}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{position:fixed;top:0;left:0;right:0;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;background:#0f172a;padding:10px 20px;z-index:1000}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:13px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:var(--grad);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:var(--nav-h)}
.container{max-width:1000px;width:100%;padding:0 20px 40px;display:flex;flex-direction:column;gap:18px;align-items:center}
.card{background:#fff;border-radius:20px;padding:24px;width:100%;box-shadow:0 6px 20px rgba(0,0,0,.12);transition:transform .2s,box-shadow .2s}
.card:hover{transform:translateY(-3px);box-shadow:0 10px 25px rgba(0,0,0,.2)}
h1{font-size:22px;color:#4a148c;margin-bottom:6px;text-align:left}
.subtitle{color:#444;font-size:14px;margin-bottom:14px}
.table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px}
table{width:100%;border-collapse:separate;border-spacing:0}
th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:10px 12px;vertical-align:top;font-size:14px}
th{background:#f8fafc;position:sticky;top:0;z-index:1}
tr:last-child td{border-bottom:none}
.muted{color:#64748b}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.pill{display:inline-block;padding:2px 8px;border:1px solid #c7d2fe;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px}
.pill-muted{background:#f1f5f9;border-color:#e2e8f0;color:#475569}
.pill-today{background:#dcfce7;border-color:#86efac;color:#166534}
.pill-upcoming{background:#e0f2fe;border-color:#bae6fd;color:#075985}
.pill-past{background:#fee2e2;border-color:#fecaca;color:#991b1b}
a.btn{display:inline-block;padding:8px 12px;border-radius:10px;background:var(--grad);color:#fff;text-decoration:none;font-weight:700}
a.btn:hover{opacity:.95;transform:translateY(-1px)}
.btn-secondary{background:#0ea5e9}
.btn-disabled{pointer-events:none;opacity:.5}
.notice{padding:12px 14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}
.rowlink{cursor:pointer}
.rowlink:hover{background:#f8fafc}
.badge-links{display:inline-flex;gap:6px;align-items:center}
</style>
<script>
function goTo(jobId){
  if(!jobId) return;
  window.location.href = 'delivery_links_view.php?booking_id=' + encodeURIComponent(jobId);
}
</script>
</head>
<body>
  <!-- Navbar แบบเดียวกับ user_dashboard.php -->
  <div class="navbar">
    <div class="logo">📸 Cameraman</div>
    <div class="menu">
      <a href="index1.php">หน้าแรก</a>
      <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
      <a href="view_photographers.php">ค้นหาช่างภาพ</a>
      <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
      <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
      <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
      <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff">ออกจากระบบ</a>
    </div>
  </div>

  <div class="header-spacer"></div>

  <div class="container">
    <div class="card">
      <h1>งานทั้งหมดของฉัน</h1>
      <div class="subtitle muted">
        แสดงทุกงานของผู้ใช้นี้จากตาราง <b>job</b> (มี/ไม่มีลิงก์ก็แสดง) — คลิกแถวเพื่อเปิดหน้าลิงก์ของงานนั้น
      </div>

      <?php if ($viewer_id <= 0): ?>
        <div class="notice">กรุณาล็อกอินก่อนจึงจะสามารถดูรายการงานได้</div>
      <?php elseif (!$rows): ?>
        <div class="notice">ยังไม่พบงานในระบบสำหรับผู้ใช้นี้</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px">ID งาน</th>
                <th style="width:220px">วัน/เวลา</th>
                <th>สถานที่</th>
                <th style="width:220px">ช่างภาพ</th>
                <th style="width:160px">สถานะวัน–เวลา</th>
                <th class="mono" style="width:220px">ส่งล่าสุด</th>
                <th style="width:150px">ลิงก์ที่ส่ง</th>
                <th style="width:140px"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r):
                $photographer = trim(($r['p_first'] ?? '').' '.($r['p_last'] ?? ''));
                [$statusText, $statusClass] = job_time_status($r['job_date'] ?? null, $r['job_time'] ?? null);
                $linkCount = (int)($r['link_count'] ?? 0);
                $hasLinks  = $linkCount > 0;
              ?>
                <tr class="rowlink" onclick="goTo(<?php echo (int)$r['job_id']; ?>)">
                  <td><?php echo (int)$r['job_id']; ?></td>
                  <td>
                    <?php echo h($r['job_date'] ?: '-'); ?>
                    <?php if (!empty($r['job_time'])): ?> <?php echo h($r['job_time']); ?><?php endif; ?>
                  </td>
                  <td><?php echo h($r['location'] ?: '-'); ?></td>
                  <td><?php echo h($photographer ?: '-'); ?></td>
                  <td><span class="pill <?php echo $statusClass; ?>"><?php echo h($statusText); ?></span></td>
                  <td class="mono"><?php echo h($r['last_delivered_at'] ?: '-'); ?></td>
                  <td>
                    <span class="badge-links pill"><?php echo $linkCount; ?> ลิงก์</span>
                  </td>
                  <td>
                    <a class="btn <?php echo $hasLinks ? '' : 'btn-secondary'; ?>"
                       href="delivery_links_view.php?booking_id=<?php echo (int)$r['job_id']; ?>"
                       onclick="event.stopPropagation();">
                       <?php echo $hasLinks ? 'เปิดงานนี้' : 'เปิด (ยังไม่มีลิงก์)'; ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
