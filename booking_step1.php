<?php
/* booking_step1.php */
session_start();
require 'db.php';

mysqli_set_charset($conn, 'utf8mb4');

/* ===== ต้องล็อกอินเป็น "ผู้ใช้" ก่อน ===== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

/* ===== รับพารามิเตอร์ ===== */
$photographer_id = (int)($_GET['photographer_id'] ?? $_GET['id'] ?? 0);
if ($photographer_id <= 0) { die('ไม่พบรหัสช่างภาพ'); }

/* ===== ดึงข้อมูลช่างภาพ ===== */
$sql = "SELECT p.*, e.expertise_name
        FROM photographer p
        LEFT JOIN expertise e ON e.expertise_id = p.expertise_id
        WHERE p.photographer_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$photographer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$photographer) { die('ไม่พบช่างภาพ'); }

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
function img_src($raw, $fallback='images/default_user.png'){
  global $BASE_URL;
  if(!$raw) $raw = $fallback;
  $p = trim(str_replace('\\','/',$raw));
  if (preg_match('#^https?://#i', $p)) return $p;
  if (!preg_match('#^(uploads|images)/#i', $p)) $p = 'uploads/' . ltrim($p,'/');
  return $BASE_URL . ltrim($p,'/');
}

/* เลือกรูปโปรไฟล์ที่มีอยู่ */
$profileImg = $photographer['profile_image_path'] ?: ($photographer['profile_image'] ?? '');
if (!$profileImg) $profileImg = 'images/default_user.png';
$profileImg = img_src($profileImg);

/* ===== ตรวจว่าตาราง job มีคอลัมน์เวลาไหม ===== */
function has_col(mysqli $conn, string $table, string $column): bool {
  $q = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = ?
                         AND COLUMN_NAME = ?
                       LIMIT 1");
  $q->bind_param("ss", $table, $column);
  $q->execute();
  $ok = (bool)$q->get_result()->fetch_row();
  $q->close();
  return $ok;
}
$job_has_time = has_col($conn, 'job', 'job_time');

/* ===== ข้อมูลพรีฟิลจาก GET (เช่นมาจากปฏิทิน) ===== */
$today = date('Y-m-d');
$prefill_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_date)) $prefill_date = $today;
$prefill_time = $_GET['time'] ?? ''; // อาจว่างได้
$errMsg = trim($_GET['err'] ?? '');   // error ที่ save_booking.php ส่งกลับมา

/* ===== ดึง slot ที่ถูกจองแล้วล่วงหน้า (30 วัน) ===== */
$booked = []; // ['Y-m-d' => ['HH:MM:SS', ...]] หรือ ['Y-m-d'=>['(ทั้งวัน)']]
if ($job_has_time) {
  $q = $conn->prepare("SELECT job_date, job_time, status
                       FROM job
                       WHERE photographer_id = ?
                         AND job_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                         AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')
                       ORDER BY job_date, job_time");
  $q->bind_param("i", $photographer_id);
  $q->execute();
  $res = $q->get_result();
  while($r = $res->fetch_assoc()){
    $d = $r['job_date'];
    $t = $r['job_time'] ?? '';
    if (!isset($booked[$d])) $booked[$d] = [];
    $booked[$d][] = $t ?: '(ทั้งวัน)';
  }
  $q->close();
} else {
  $q = $conn->prepare("SELECT job_date
                       FROM job
                       WHERE photographer_id = ?
                         AND job_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                         AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')
                       ORDER BY job_date");
  $q->bind_param("i", $photographer_id);
  $q->execute();
  $res = $q->get_result();
  while($r = $res->fetch_assoc()){
    $d = $r['job_date'];
    if (!isset($booked[$d])) $booked[$d] = [];
    $booked[$d][] = '(ทั้งวัน)';
  }
  $q->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จองช่างภาพ - <?= h($photographer['first_name'].' '.$photographer['last_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --brand1:#6a11cb; --brand2:#2575fc; --ink:#0f172a; --muted:#6b7280; --ok:#22c55e; --err:#ef4444;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:'Prompt',sans-serif}

/* พื้นหลังไล่สี */
body{
  min-height:100vh;
  background:
    radial-gradient(1200px 600px at 10% -10%, #ffe3f3 0%, transparent 60%),
    radial-gradient(900px 600px at 100% 0%, #dbeafe 0%, transparent 60%),
    linear-gradient(135deg,#ffecd2,#fcb69f);
  color:#111;
}

/* Navbar */
.navbar{
  width:100%; position:fixed; top:0; left:0; z-index:100;
  display:flex; justify-content:space-between; align-items:center;
  padding:14px 32px; background:rgba(0,0,0,.18); backdrop-filter:blur(12px);
  box-shadow:0 6px 24px rgba(0,0,0,.15);
}
.logo{ color:#fff; font-size:24px; font-weight:800 }
.menu{ display:flex; gap:10px; flex-wrap:wrap; align-items:center }
.menu a{
  color:#fff; text-decoration:none; font-weight:600; font-size:15px;
  padding:8px 12px; border-radius:10px; transition:.2s;
}
.menu a:hover{ background:rgba(255,255,255,.25); color:#000 }
.menu a.cta{ background:linear-gradient(90deg,var(--brand1),var(--brand2)); color:#fff }

/* Wrapper */
.page{ padding: calc(72px + 16px) 16px 24px; display:grid; place-items:center }
.wrap{ width:min(1100px, 96vw); display:grid; gap:14px }

/* Card layout */
.header-card, .form-card, .info-card{
  background:#fff; border:1px solid #eef2ff; border-radius:20px; padding:16px;
  box-shadow:0 10px 30px rgba(16,24,40,.10), 0 2px 10px rgba(16,24,40,.05);
}
.header{
  display:flex; gap:14px; align-items:center; flex-wrap:wrap;
}
.avatar{
  width:84px; height:84px; border-radius:16px; object-fit:cover; border:1px solid #e5e7eb;
}
.htexts{ min-width:200px }
.hname{ font-size:20px; font-weight:900; color:var(--brand1) }
.hmeta{ color:#374151; margin-top:4px }
.badges{ display:flex; gap:8px; flex-wrap:wrap; margin-top:6px }
.badge{ background:#eef2ff; color:#1d4ed8; border:1px solid #c7d2fe; padding:6px 10px; border-radius:999px; font-weight:800; font-size:12px }

.grid{
  display:grid; grid-template-columns:1fr .9fr; gap:14px;
}
@media (max-width:980px){ .grid{ grid-template-columns:1fr } }

/* Form */
.form{ display:grid; gap:10px }
.label{ font-weight:800 }
.input, .select, .textarea{
  width:100%; padding:12px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#fff;
}
.actions{ display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; margin-top:6px }
.btn{ appearance:none; border:none; border-radius:12px; padding:12px 16px; font-weight:800; cursor:pointer }
.btn-primary{ background:linear-gradient(90deg,var(--brand1),var(--brand2)); color:#fff }
.btn-ghost{ background:#f8fafc; border:1px solid #e5e7eb }

/* Alerts */
.alert{ padding:10px 12px; border-radius:12px; font-weight:700 }
.alert.err{ background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35); color:#7f1d1d }
.help{ color:var(--muted); font-size:13px }

/* Booked list */
.slots{ display:grid; gap:10px }
.slot-day{
  border:1px solid #e5e7eb; border-radius:14px; padding:10px 12px; background:#fafbff;
}
.slot-head{ display:flex; gap:8px; align-items:center; font-weight:900; color:#0f172a }
.slot-timewrap{ display:flex; gap:6px; flex-wrap:wrap; margin-top:6px }
.timechip{
  padding:6px 10px; border-radius:999px; font-weight:800; font-size:12px;
  background:#fee2e2; color:#991b1b; border:1px solid #fecaca;
}
.empty{ padding:12px; border:1px dashed #e5e7eb; border-radius:14px; color:#6b7280; background:#fff; }

/* Quick links */
.quick{ display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:6px }
.quick a{ color:#4a148c; text-decoration:none; font-weight:700 }
.quick a:hover{ text-decoration:underline }
</style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a class="cta" href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="logout.php">ออกจากระบบ</a>
  </div>
</div>

<main class="page">
  <div class="wrap">

    <!-- Header: Photographer summary -->
    <section class="header-card">
      <div class="header">
        <img class="avatar" src="<?= h($profileImg) ?>" alt="รูปช่างภาพ"
             onerror="this.onerror=null;this.src='images/default_user.png'">
        <div class="htexts">
          <div class="hname"><?= h($photographer['first_name'].' '.$photographer['last_name']) ?></div>
          <div class="hmeta">
            ความถนัด: <b><?= h($photographer['expertise_name'] ?? 'ไม่ระบุ') ?></b>
            <?php if(isset($photographer['price_rate']) && $photographer['price_rate']!==''): ?>
              · ราคาเริ่มต้น: <b><?= number_format((float)$photographer['price_rate']) ?> บาท</b>
            <?php endif; ?>
          </div>
          <div class="badges">
            <span class="badge">พร้อมรับงาน</span>
            <a class="badge" href="photographer_schedule.php?photographer_id=<?= (int)$photographer_id ?>">ดูตารางงาน</a>
            <a class="badge" href="photographer_detail.php?id=<?= (int)$photographer_id ?>">ดูโปรไฟล์</a>
          </div>
        </div>
      </div>
    </section>

    <section class="grid">

      <!-- Booking form -->
      <div class="form-card">
        <h2 style="margin:0 0 8px;color:#4a148c">📅 จองช่างภาพ</h2>

        <?php if($errMsg): ?>
          <div class="alert err"><?= h($errMsg) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="save_booking.php" novalidate>
          <input type="hidden" name="photographer_id" value="<?= (int)$photographer_id ?>">

          <label class="label" for="job_date">วันที่ต้องการจอง</label>
          <input class="input" type="date" id="job_date" name="job_date"
                 value="<?= h($prefill_date) ?>" min="<?= h($today) ?>" required>

          <?php if ($job_has_time): ?>
            <label class="label" for="job_time">เวลา (ถ้ามี)</label>
            <input class="input" type="time" id="job_time" name="job_time"
                   value="<?= h($prefill_time) ?>">
            <div class="help">* หากไม่เลือกเวลา ระบบจะถือว่าจอง “ตามวัน”</div>
          <?php else: ?>
            <div class="help">* ระบบนี้รองรับการจอง “รายวัน” (ไม่มีฟิลด์เวลา)</div>
          <?php endif; ?>

          <label class="label" for="location">สถานที่ถ่าย</label>
          <input class="input" type="text" id="location" name="location" placeholder="เช่น Central World, สวนรถไฟ ฯลฯ" required>

          <div class="actions">
            <a class="btn btn-ghost" href="photographer_schedule.php?photographer_id=<?= (int)$photographer_id ?>">ดูวันที่ว่าง</a>
            <a class="btn btn-ghost" href="locations_recommend.php" title="ดูสถานที่แนะนำ">ดูสถานที่แนะนำ</a>
            <button class="btn btn-primary" type="submit">ส่งคำขอจอง</button>
          </div>
        </form>

        <div class="quick">
          <a href="photographer_detail.php?id=<?= (int)$photographer_id ?>">← กลับหน้าช่างภาพ</a>
        </div>
      </div>

      <!-- Upcoming booked slots -->
      <div class="info-card">
        <h2 style="margin:0 0 8px;color:#4a148c">⏱️ ช่วงเวลาที่ถูกจองแล้ว (30 วันถัดไป)</h2>

        <?php if (!$booked): ?>
          <div class="empty">ยังไม่มีการจองในช่วง 30 วันข้างหน้า — โอกาสดีในการเลือกวัน/เวลา!</div>
        <?php else: ?>
          <div class="slots">
            <?php foreach($booked as $d => $times): ?>
              <div class="slot-day">
                <div class="slot-head"><?= h(date('d/m/Y', strtotime($d))) ?></div>
                <div class="slot-timewrap">
                  <?php foreach($times as $t): ?>
                    <?php
                      $label = $t;
                      if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
                        $label = substr($t,0,5);
                      }
                    ?>
                    <span class="timechip"><?= h($label) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </section>

  </div>
</main>

</body>
</html>
