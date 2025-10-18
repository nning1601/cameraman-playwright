<?php
/* ==========================================================
 * delivery_links_view.php
 * แสดงลิงก์ส่งงานของผู้ใช้ที่ล็อกอิน และคุยกับช่างภาพได้ + เขียนรีวิวแบบ 1 งาน = 1 รีวิว
 * ========================================================== */
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Bangkok');

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_safe_url($url){
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $p = parse_url($url);
  $sch = strtolower($p['scheme'] ?? '');
  return in_array($sch, ['http','https'], true);
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  if (!$st = $conn->prepare("
    SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ")) return false;
  $st->bind_param('ss', $table, $col);
  $st->execute();
  $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
  return $c > 0;
}
function index_exists(mysqli $conn, string $table, string $index): bool {
  if (!$st = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1
  ")) return false;
  $st->bind_param('ss', $table, $index);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}
function has_dupes_user_booking(mysqli $conn): bool {
  $sql = "SELECT 1 FROM photographerrating
          GROUP BY user_id, booking_id HAVING COUNT(*)>1 LIMIT 1";
  if ($res = $conn->query($sql)) {
    $row = $res->fetch_row();
    return (bool)$row;
  }
  return false;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_msg'])) { $_SESSION['csrf_msg'] = bin2hex(random_bytes(32)); }
function csrf_ok($t){ return hash_equals($_SESSION['csrf_msg'] ?? '', $t ?? ''); }

/* ---------- ผู้ใช้ที่ล็อกอิน ---------- */
$viewer_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$booking_idQ = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

/* ---------- DDL แชท (ใช้ร่วมกัน) ---------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT DEFAULT NULL,
    booking_id INT DEFAULT NULL,
    user_id INT NOT NULL,
    photographer_id INT NOT NULL,
    sender ENUM('user','photographer','system') NOT NULL,
    message TEXT NOT NULL,
    is_read_by_user TINYINT(1) NOT NULL DEFAULT 0,
    is_read_by_photographer TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (job_id), INDEX (booking_id), INDEX (user_id),
    INDEX (photographer_id), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- DDL รีวิว (สร้าง + migrate: 1 งาน = 1 รีวิว) ---------- */
/* สร้างตารางมาตรฐาน (ถ้าไม่มี) */
$conn->query("
  CREATE TABLE IF NOT EXISTS photographerrating (
    id INT AUTO_INCREMENT PRIMARY KEY,
    photographer_id INT NOT NULL,
    user_id INT NOT NULL,
    booking_id INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (photographer_id), INDEX (user_id), INDEX (booking_id), INDEX (rating)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ถ้าตารางเดิมยังไม่มี booking_id -> เพิ่มคอลัมน์ */
if (!column_exists($conn, 'photographerrating', 'booking_id')) {
  $conn->query("ALTER TABLE photographerrating ADD COLUMN booking_id INT NOT NULL AFTER user_id");
}
/* สร้าง index ธรรมดา (ถ้ายังไม่มี) */
if (!index_exists($conn, 'photographerrating', 'idx_photographerrating_booking')) {
  try { $conn->query("CREATE INDEX idx_photographerrating_booking ON photographerrating (booking_id)"); }
  catch (Throwable $e) {}
}
/* สร้าง UNIQUE กันรีวิวซ้ำต่อหนึ่งงาน (ถ้ายังไม่มี และไม่มีข้อมูลซ้ำ) */
if (!index_exists($conn, 'photographerrating', 'uniq_photographerrating_user_booking')) {
  if (!has_dupes_user_booking($conn)) {
    try { $conn->query("CREATE UNIQUE INDEX uniq_photographerrating_user_booking ON photographerrating (user_id, booking_id)"); }
    catch (Throwable $e) {}
  }
}

/* ---------- FLASH ---------- */
$flash = '';

/* ---------- รับโพสต์ข้อความตอบกลับ (แชท) ---------- */
if ($viewer_id > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reply') {
  $csrf = $_POST['csrf'] ?? '';
  $bid  = (int)($_POST['booking_id'] ?? 0);
  $pid  = (int)($_POST['photographer_id'] ?? 0);
  $msg  = trim((string)($_POST['message'] ?? ''));

  if (!csrf_ok($csrf)) {
    $flash = 'ไม่สามารถยืนยันแบบฟอร์มได้ (CSRF)';
  } elseif ($bid <= 0 || $pid <= 0) {
    $flash = 'ข้อมูลงานไม่ครบถ้วน';
  } elseif ($msg === '') {
    $flash = 'กรุณาพิมพ์ข้อความ';
  } else {
    if ($st = $conn->prepare("SELECT 1 FROM job WHERE job_id=? AND user_id=? LIMIT 1")) {
      $st->bind_param('ii', $bid, $viewer_id);
      $st->execute();
      $okOwner = (bool)$st->get_result()->fetch_row();
      $st->close();

      if (!$okOwner) {
        $flash = 'คุณไม่มีสิทธิ์ตอบข้อความในงานนี้';
      } else {
        if ($st2 = $conn->prepare("
          INSERT INTO chat_messages
            (job_id, booking_id, user_id, photographer_id, sender, message, is_read_by_user, is_read_by_photographer, created_at)
          VALUES
            (?, ?, ?, ?, 'user', ?, 1, 0, NOW())
        ")) {
          $msg2k = mb_substr($msg, 0, 2000, 'UTF-8');
          $st2->bind_param('iiiis', $bid, $bid, $viewer_id, $pid, $msg2k);
          $flash = $st2->execute() ? 'ส่งข้อความเรียบร้อย' : 'ส่งข้อความไม่สำเร็จ';
          $st2->close();
        } else {
          $flash = 'ไม่สามารถบันทึกข้อความได้';
        }
      }
    } else {
      $flash = 'ไม่สามารถตรวจสอบสิทธิ์งานได้';
    }
  }
}

/* ---------- รีวิว: 1 งาน = 1 รีวิว ---------- */
function user_already_rated_booking(mysqli $conn, int $user_id, int $booking_id): bool {
  if ($user_id<=0 || $booking_id<=0) return false;
  if (!$st = $conn->prepare("SELECT 1 FROM photographerrating WHERE user_id=? AND booking_id=? LIMIT 1")) return false;
  $st->bind_param('ii', $user_id, $booking_id);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}
function rating_column_name(mysqli $conn): ?string {
  foreach (['rating','score','rating_score','stars'] as $c) {
    if (column_exists($conn, 'photographerrating', $c)) return $c;
  }
  return 'rating';
}

if ($viewer_id > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'review') {
  $csrf = $_POST['csrf'] ?? '';
  $bid  = (int)($_POST['booking_id'] ?? 0);
  $pid  = (int)($_POST['photographer_id'] ?? 0);
  $score= (int)($_POST['score'] ?? 0);
  $comment = trim((string)($_POST['comment'] ?? ''));

  if (!csrf_ok($csrf)) {
    $flash = 'ไม่สามารถยืนยันแบบฟอร์มได้ (CSRF)';
  } elseif ($bid<=0 || $pid<=0) {
    $flash = 'ข้อมูลงานไม่ครบถ้วน';
  } elseif ($score < 1 || $score > 5) {
    $flash = 'คะแนนไม่ถูกต้อง (1–5 ดาว)';
  } else {
    /* ยืนยันว่าเป็นเจ้าของงาน และช่างภาพตรงกับงาน */
    if ($st = $conn->prepare("SELECT photographer_id FROM job WHERE job_id=? AND user_id=? LIMIT 1")) {
      $st->bind_param('ii', $bid, $viewer_id);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();

      if (!$row) {
        $flash = 'คุณไม่มีสิทธิ์รีวิวงานนี้';
      } elseif ((int)$row['photographer_id'] !== $pid) {
        $flash = 'ข้อมูลช่างภาพไม่ตรงกับงาน';
      } elseif (user_already_rated_booking($conn, $viewer_id, $bid)) {
        $flash = 'คุณได้รีวิวงานนี้แล้ว ขอบคุณมากค่ะ/ครับ';
      } else {
        /* บันทึกรีวิว (ผูกกับ booking_id) */
        $col = rating_column_name($conn); // 'rating' | 'score' | 'rating_score' | 'stars'
        $comment = mb_substr($comment, 0, 2000, 'UTF-8');

        $sql = "INSERT INTO photographerrating (photographer_id, user_id, booking_id, `$col`, comment, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        if ($st2 = $conn->prepare($sql)) {
          $st2->bind_param('iiiis', $pid, $viewer_id, $bid, $score, $comment);
          $ok = $st2->execute();
          $st2->close();
          $flash = $ok ? 'ส่งรีวิวเรียบร้อย ขอบคุณมากค่ะ/ครับ' : 'ส่งรีวิวไม่สำเร็จ';
        } else {
          $flash = 'ไม่สามารถบันทึกรีวิวได้';
        }
      }
    } else {
      $flash = 'ไม่สามารถตรวจสอบสิทธิ์งานได้';
    }
  }
}

/* ---------- ดึงข้อมูลลิงก์ ---------- */
$rows = [];
$singleTitle = '';
$singlePhotographer = '';

if ($viewer_id > 0) {
  if ($booking_idQ > 0) {
    $isMine = false;
    if ($chk = $conn->prepare("SELECT 1 FROM job WHERE job_id=? AND user_id=? LIMIT 1")) {
      $chk->bind_param('ii', $booking_idQ, $viewer_id);
      $chk->execute();
      $isMine = (bool)$chk->get_result()->fetch_row();
      $chk->close();
    }

    if ($isMine) {
      $sql = "
        SELECT
          dl.id, dl.booking_id, dl.url, dl.delivered_at,
          dl.photographer_id AS p_id,
          p.first_name AS p_first, p.last_name AS p_last,
          j.job_date AS j_date, j.job_time AS j_time, j.location AS j_loc
        FROM delivery_links dl
        JOIN job j               ON j.job_id = dl.booking_id
        LEFT JOIN photographer p ON p.photographer_id = dl.photographer_id
        WHERE j.job_id = ? AND j.user_id = ?
        ORDER BY dl.delivered_at DESC, dl.id DESC
      ";
      if ($st = $conn->prepare($sql)) {
        $st->bind_param('ii', $booking_idQ, $viewer_id);
        $st->execute();
        $res = $st->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
      }

      if ($ih = $conn->prepare("
        SELECT j.job_id, j.job_date, j.job_time, j.location,
               p.first_name AS p_first, p.last_name AS p_last
        FROM job j
        LEFT JOIN photographer p ON p.photographer_id = j.photographer_id
        WHERE j.job_id=? AND j.user_id=? LIMIT 1
      ")) {
        $ih->bind_param('ii', $booking_idQ, $viewer_id);
        $ih->execute();
        $head = $ih->get_result()->fetch_assoc();
        if ($head) {
          $singleTitle = "งาน #{$head['job_id']} • ".trim(($head['job_date'] ?? '').' '.($head['job_time'] ?? ''));
          $singlePhotographer = trim(($head['p_first'] ?? '').' '.(($head['p_last'] ?? '')));
        }
        $ih->close();
      }
    } else {
      $rows = [];
    }
  } else {
    $sql = "
      SELECT
        dl.id, dl.booking_id, dl.url, dl.delivered_at,
        dl.photographer_id AS p_id,
        p.first_name AS p_first, p.last_name AS p_last,
        j.job_date AS j_date, j.job_time AS j_time, j.location AS j_loc
      FROM delivery_links dl
      JOIN job j               ON j.job_id = dl.booking_id
      LEFT JOIN photographer p ON p.photographer_id = dl.photographer_id
      WHERE j.user_id = ?
      ORDER BY dl.delivered_at DESC, dl.id DESC
    ";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('i', $viewer_id);
      $st->execute();
      $res = $st->get_result();
      $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
      $st->close();
    }
  }
}

/* ---------- โหลดข้อความล่าสุดของแต่ละ booking ---------- */
function load_messages_for_booking(mysqli $conn, int $booking_id, int $photographer_id, int $user_id): array {
  if ($booking_id <= 0 || $photographer_id <= 0 || $user_id <= 0) return [];
  if ($st = $conn->prepare("
      SELECT sender, message, created_at
      FROM chat_messages
      WHERE job_id=? AND booking_id=? AND photographer_id=? AND user_id=?
      ORDER BY created_at DESC
      LIMIT 5
    ")) {
    $st->bind_param('iiii', $booking_id, $booking_id, $photographer_id, $user_id);
    $st->execute();
    $rs = $st->get_result();
    $list = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return array_reverse($list);
  }
  return [];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title><?php echo $booking_idQ>0 ? h($singleTitle ?: 'ลิงก์ส่งงานของฉัน') : 'ลิงก์ส่งงานของฉัน'; ?></title>
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
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-all}
a.btn{display:inline-block;padding:8px 12px;border-radius:10px;background:var(--grad);color:#fff;text-decoration:none;font-weight:700}
a.btn:hover{opacity:.95;transform:translateY(-1px)}
.btn-secondary{background:#0ea5e9}
.flash-ok{padding:10px 12px;border-radius:10px;border:1px solid #67e8f9;background:#ecfeff;color:#155e75;margin-bottom:10px}
.msgbox{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-top:12px;background:#fafafa}
.msg{border-bottom:1px dashed #e5e7eb;padding:6px 0}
.msg:last-child{border-bottom:none}
.msg .who{font-weight:700}
.msg .time{font-size:12px;color:#64748b}
.reply{margin-top:8px;display:grid;grid-template-columns:1fr auto;gap:8px;align-items:start}
textarea{width:100%;min-height:70px;resize:vertical;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:14px}
button.btn{padding:10px 14px;border-radius:10px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}
button.btn:hover{filter:brightness(0.95)}
a.link{color:#0369a1;text-decoration:none}
a.link:hover{text-decoration:underline}
.header-row{display:flex;justify-content:space-between;align-items:center;gap:10px}
.back{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#0b1220;margin-bottom:8px;margin-right:10px}
.back:hover{background:#f8fafc}
/* รีวิว */
.block{border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:12px;margin-top:12px}
.block h3{font-size:16px;margin-bottom:8px}
.review-form{display:grid;gap:10px}
.review-form label{display:block;font-size:14px;color:#0b1220}
.review-form select,.review-form textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:10px}
.review-form button{justify-self:start}
</style>
</head>
<body>
  <!-- Navbar -->
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
      <div class="header-row">
        <div>
          <a class="back" href="delivery_job_select.php<?php echo $booking_idQ>0 ? ('?booking_id='.(int)$booking_idQ) : ''; ?>">← ปูมกิจกรรม</a>

          <h1>
            <?php echo $booking_idQ>0 ? h($singleTitle ?: 'ลิงก์ส่งงานของฉัน') : 'ลิงก์ส่งงานของฉัน'; ?>
            <?php if ($booking_idQ>0 && $singlePhotographer): ?>
              <span class="muted">• ช่างภาพ: <?php echo h($singlePhotographer); ?></span>
            <?php endif; ?>
          </h1>

          <div class="subtitle muted">
            <?php if ($booking_idQ>0): ?>
              รายการลิงก์สำหรับงานที่เลือก •
              <a class="link" href="delivery_job_select.php?booking_id=<?php echo (int)$booking_idQ; ?>">ปูมกิจกรรม</a>
            <?php else: ?>
              แสดงทุกลิงก์ของผู้ใช้จากตาราง <b>delivery_links</b> (อ้างอิงงานจากตาราง <b>job</b>)
              • <a class="link" href="delivery_job_select.php">ปูมกิจกรรม</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="muted">ทั้งหมด: <?php echo number_format(count($rows)); ?> แถว</div>
      </div>

      <?php if ($flash): ?>
        <div class="flash-ok"><?php echo h($flash); ?></div>
      <?php endif; ?>

      <?php if ($viewer_id <= 0): ?>
        <div class="subtitle" style="color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;padding:10px;border-radius:12px">
          กรุณาล็อกอินก่อน
        </div>
      <?php elseif ($booking_idQ>0 && !$rows): ?>
        <div class="subtitle" style="color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;padding:10px;border-radius:12px">
          ไม่พบลิงก์สำหรับงานนี้หรือคุณไม่ใช่เจ้าของงาน
        </div>
      <?php elseif (!$rows): ?>
        <div class="subtitle muted">ยังไม่มีลิงก์สำหรับผู้ใช้นี้</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px">ID งาน</th>
                <th style="width:200px">วัน/เวลา</th>
                <th>สถานที่</th>
                <th style="width:220px">ช่างภาพ</th>
                <th>URL</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $seenBooking = [];
              foreach ($rows as $r):
                $bid          = (int)$r['booking_id'];
                $pid          = (int)$r['p_id'];
                $photographer = trim(($r['p_first'] ?? '').' '.($r['p_last'] ?? ''));
                $dtText       = trim(($r['j_date'] ?? '').' '.($r['j_time'] ?? ''));
                $locText      = (string)($r['j_loc'] ?? '');
            ?>
              <tr>
                <td><?php echo $bid; ?></td>
                <td class="mono"><?php echo h($dtText ?: '-'); ?></td>
                <td><?php echo h($locText ?: '-'); ?></td>
                <td><?php echo h($photographer ?: '-'); ?></td>
                <td class="mono">
                  <?php if (is_safe_url($r['url'])): ?>
                    <a class="link" href="<?php echo h($r['url']); ?>" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">
                      <?php echo h($r['url']); ?>
                    </a>
                  <?php else: ?>
                    <?php echo h($r['url']); ?>
                  <?php endif; ?>
                </td>
              </tr>

              <?php if (!isset($seenBooking[$bid])):
                $seenBooking[$bid] = true;
                $msgs = load_messages_for_booking($conn, $bid, $pid, $viewer_id);
                $already = user_already_rated_booking($conn, $viewer_id, $bid);  // ตรวจซ้ำต่อ "งาน"
              ?>
                <tr>
                  <td colspan="5">
                    <div class="msgbox">
                      <div class="muted" style="margin-bottom:6px">สนทนาสำหรับงาน #<?php echo $bid; ?></div>
                      <?php if ($msgs): foreach ($msgs as $m): ?>
                        <div class="msg">
                          <div class="who"><?php echo $m['sender']==='user' ? 'คุณ' : 'ช่างภาพ'; ?></div>
                          <div><?php echo nl2br(h($m['message'])); ?></div>
                          <div class="time"><?php echo h($m['created_at']); ?></div>
                        </div>
                      <?php endforeach; else: ?>
                        <div class="muted">ยังไม่มีข้อความสนทนา</div>
                      <?php endif; ?>

                      <form method="post" class="reply" style="margin-bottom:10px">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf_msg']); ?>">
                        <input type="hidden" name="booking_id" value="<?php echo $bid; ?>">
                        <input type="hidden" name="photographer_id" value="<?php echo $pid; ?>">
                        <textarea name="message" placeholder="พิมพ์ข้อความถึงช่างภาพ..."></textarea>
                        <button class="btn" type="submit">ส่งข้อความ</button>
                      </form>

                      <!-- ✍️ บล็อกเขียนรีวิว -->
                      <div class="block">
                        <h3>✍️ เขียนรีวิว</h3>
                        <?php if ($viewer_id): ?>
                          <?php if ($already): ?>
                            <div class="muted">คุณได้รีวิวงานนี้แล้ว ขอบคุณมากค่ะ/ครับ</div>
                          <?php else: ?>
                            <form class="review-form" method="post">
                              <input type="hidden" name="action" value="review">
                              <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf_msg']); ?>">
                              <input type="hidden" name="booking_id" value="<?php echo $bid; ?>">
                              <input type="hidden" name="photographer_id" value="<?php echo $pid; ?>">

                              <label>ให้คะแนน:
                                <select name="score" required>
                                  <option value="">-- เลือก --</option>
                                  <?php for($i=5;$i>=1;$i--): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> ดาว</option>
                                  <?php endfor; ?>
                                </select>
                              </label>

                              <label>ความคิดเห็น:
                                <textarea name="comment" maxlength="2000" placeholder="บอกความประทับใจหรือข้อเสนอแนะ..."></textarea>
                              </label>

                              <button class="btn" type="submit">ส่งรีวิว</button>
                            </form>
                          <?php endif; ?>
                        <?php else: ?>
                          <p>กรุณา <a href="login_user.php" style="color:#6a11cb;font-weight:800">เข้าสู่ระบบ</a> เพื่อเขียนรีวิว</p>
                        <?php endif; ?>
                      </div>
                      <!-- จบบล็อกรีวิว -->

                    </div>
                  </td>
                </tr>
              <?php endif; endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
