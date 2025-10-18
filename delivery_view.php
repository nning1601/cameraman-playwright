<?php
/* delivery_links_view.php — แสดงลิงก์ของผู้ใช้ที่ล็อกอิน + ตอบข้อความกลับหาช่างภาพได้ */
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_safe_url($url){
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $p = parse_url($url);
  $sch = strtolower($p['scheme'] ?? '');
  return in_array($sch, ['http','https'], true);
}

/* ===== CSRF token สำหรับส่งข้อความ ===== */
if (empty($_SESSION['csrf_msg'])) {
  $_SESSION['csrf_msg'] = bin2hex(random_bytes(32));
}
function csrf_ok($t){ return hash_equals($_SESSION['csrf_msg'] ?? '', $t ?? ''); }

/* ===== ผู้ใช้ที่ล็อกอิน ===== */
$viewer_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/* ===== DDL: กล่องข้อความ (สร้างอัตโนมัติถ้ายังไม่มี) ===== */
$conn->query("
  CREATE TABLE IF NOT EXISTS delivery_messages (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    photographer_id INT NOT NULL,
    sender ENUM('user','photographer') NOT NULL DEFAULT 'user',
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (booking_id),
    INDEX (user_id),
    INDEX (photographer_id),
    CONSTRAINT fk_dm_job          FOREIGN KEY (booking_id)      REFERENCES job(job_id)                  ON DELETE CASCADE,
    CONSTRAINT fk_dm_user         FOREIGN KEY (user_id)         REFERENCES users(user_id)               ON DELETE CASCADE,
    CONSTRAINT fk_dm_photographer FOREIGN KEY (photographer_id) REFERENCES photographer(photographer_id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ===== รับโพสต์ข้อความตอบกลับ ===== */
$flash = '';
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
    /* ตรวจสิทธิ์: งานนี้ต้องเป็นของผู้ใช้ที่ล็อกอิน */
    if ($st = $conn->prepare("SELECT 1 FROM job WHERE job_id=? AND user_id=? LIMIT 1")) {
      $st->bind_param('ii', $bid, $viewer_id);
      $st->execute();
      $okOwner = (bool)$st->get_result()->fetch_row();
      $st->close();

      if (!$okOwner) {
        $flash = 'คุณไม่มีสิทธิ์ตอบข้อความในงานนี้';
      } else {
        /* บันทึกข้อความ — แก้ลำดับค่าถูกต้อง: sender = 'user', message = ข้อความ */
        if ($st2 = $conn->prepare("
          INSERT INTO delivery_messages(booking_id,user_id,photographer_id,sender,message)
          VALUES(?,?,?,?,?)
        ")) {
          $msg2k  = mb_substr($msg, 0, 2000, 'UTF-8');  // จำกัดความยาวกันสแปม
          $sender = 'user';
          $st2->bind_param('iiiss', $bid, $viewer_id, $pid, $sender, $msg2k);
          if ($st2->execute()) {
            $flash = 'ส่งข้อความเรียบร้อย';
          } else {
            $flash = 'ส่งข้อความไม่สำเร็จ';
          }
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

/* ===== ดึงลิงก์ของผู้ใช้ที่ล็อกอิน ===== */
$rows = [];
if ($viewer_id > 0) {
  $sql = "
    SELECT
      dl.id,
      dl.booking_id,
      dl.url,
      dl.delivered_at,
      dl.photographer_id AS p_id,
      p.first_name AS p_first, p.last_name AS p_last,
      COALESCE(u1.first_name, u2.first_name) AS u_first,
      COALESCE(u1.last_name,  u2.last_name)  AS u_last
    FROM delivery_links dl
    JOIN job j               ON j.job_id = dl.booking_id
    LEFT JOIN photographer p ON p.photographer_id = dl.photographer_id
    LEFT JOIN users u1       ON u1.user_id = dl.users_id      /* ลูกค้าตามแถวลิงก์ (ถ้ามี) */
    LEFT JOIN users u2       ON u2.user_id = j.user_id        /* Fallback ลูกค้าตามงาน   */
    WHERE (dl.users_id = ? OR (dl.users_id IS NULL AND j.user_id = ?))
    ORDER BY dl.delivered_at DESC, dl.id DESC
  ";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('ii', $viewer_id, $viewer_id);
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
  }
}

/* ===== โหลดข้อความล่าสุดต่อ booking_id ===== */
function load_messages_for_booking(mysqli $conn, int $booking_id): array {
  if ($booking_id <= 0) return [];
  if ($st = $conn->prepare("
      SELECT sender, message, created_at
      FROM delivery_messages
      WHERE booking_id=?
      ORDER BY created_at DESC
      LIMIT 5
    ")) {
    $st->bind_param('i', $booking_id);
    $st->execute();
    $rs = $st->get_result();
    $list = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    /* แปลงให้เก่าสุดอยู่บน (ASC) */
    return array_reverse($list);
  }
  return [];
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ลิงก์ส่งงานของฉัน (ตอบกลับได้)</title>
<style>
  :root{ --bg:#0f172a; --card:#ffffff; --ink:#0b1220; --muted:#64748b; --bd:#e2e8f0;
         --okbg:#ecfeff; --okbd:#67e8f9; --ok:#155e75; --errbg:#fee2e2; --errbd:#fecaca; --err:#7f1d1d; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);font-family:system-ui,Segoe UI,Roboto,Arial;color:var(--ink)}
  .wrap{max-width:1000px;margin:24px auto;padding:0 16px}
  .card{background:var(--card);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:16px}
  h1{margin:4px 0 12px;font-size:20px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{border-bottom:1px solid var(--bd);text-align:left;padding:10px 12px;vertical-align:top;font-size:14px}
  th{background:#f8fafc;position:sticky;top:0;z-index:1}
  tr:last-child td{border-bottom:none}
  .muted{color:var(--muted)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-all}
  a.link{color:#0369a1;text-decoration:none}
  a.link:hover{text-decoration:underline}
  .topbar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .alert{padding:10px 12px;border:1px solid var(--errbd);background:var(--errbg);color:var(--err);border-radius:10px}
  .flash-ok{padding:10px 12px;border:1px solid var(--okbd);background:var(--okbg);color:var(--ok);border-radius:10px;margin-bottom:10px}
  .msgbox{border:1px solid var(--bd);border-radius:12px;padding:10px;margin-top:10px;background:#fafafa}
  .msg{border-bottom:1px dashed #e5e7eb;padding:6px 0}
  .msg:last-child{border-bottom:none}
  .msg .who{font-weight:700}
  .msg .time{font-size:12px;color:#64748b}
  .reply{margin-top:8px;display:grid;grid-template-columns:1fr auto;gap:8px;align-items:start}
  textarea{width:100%;min-height:70px;resize:vertical;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:14px}
  button.btn{padding:10px 14px;border-radius:10px;border:1px solid #0ea5e9;background:#0ea5e9;color:#fff;font-weight:700;cursor:pointer}
  button.btn:hover{filter:brightness(0.95)}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="topbar">
        <h1>ลิงก์ส่งงานของฉัน</h1>
        <?php if ($viewer_id > 0): ?>
          <div class="muted">ทั้งหมด: <?php echo number_format(count($rows)); ?> แถว</div>
        <?php endif; ?>
      </div>

      <?php if ($flash): ?>
        <div class="flash-ok"><?php echo h($flash); ?></div>
      <?php endif; ?>

      <?php if ($viewer_id <= 0): ?>
        <div class="alert">กรุณาล็อกอินก่อน จึงจะแสดงลิงก์งานของคุณได้</div>
      <?php elseif (!$rows): ?>
        <div class="muted">ยังไม่มีลิงก์สำหรับผู้ใช้นี้</div>
      <?php else: ?>
        <div style="overflow:auto;border:1px solid var(--bd);border-radius:12px">
          <table>
            <thead>
              <tr>
                <th>ช่างภาพ</th>
                <th>เวลาส่ง</th>
                <th>ลูกค้า</th>
                <th>ID งาน</th>
                <th>URL</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $seenBooking = []; // กันซ้ำเวลาขึ้นกล่องสนทนาต่อ "งาน"
                foreach($rows as $r):
                  $photographer = trim(($r['p_first'] ?? '').' '.($r['p_last'] ?? ''));
                  $customer     = trim(($r['u_first'] ?? '').' '.($r['u_last'] ?? ''));
                  $bid          = (int)$r['booking_id'];
                  $pid          = (int)$r['p_id'];
              ?>
                <tr>
                  <td><?php echo h($photographer ?: '-'); ?></td>
                  <td class="mono"><?php echo h($r['delivered_at'] ?: ''); ?></td>
                  <td><?php echo h($customer ?: '-'); ?></td>
                  <td><?php echo $bid; ?></td>
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

                <?php
                  /* กล่องสนทนา-ตอบกลับ ต่อ "งาน" โผล่ครั้งเดียวพอ */
                  if (!isset($seenBooking[$bid])):
                    $seenBooking[$bid] = true;
                    $msgs = load_messages_for_booking($conn, $bid);
                ?>
                  <tr>
                    <td colspan="5">
                      <div class="msgbox">
                        <div class="muted" style="margin-bottom:6px">สนทนาสำหรับงาน #<?php echo $bid; ?></div>
                        <?php if ($msgs): ?>
                          <?php foreach($msgs as $m): ?>
                            <div class="msg">
                              <div class="who"><?php echo $m['sender']==='user' ? 'คุณ' : 'ช่างภาพ'; ?></div>
                              <div><?php echo nl2br(h($m['message'])); ?></div>
                              <div class="time"><?php echo h($m['created_at']); ?></div>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <div class="muted">ยังไม่มีข้อความสนทนา</div>
                        <?php endif; ?>

                        <!-- ฟอร์มตอบกลับ -->
                        <form method="post" class="reply">
                          <input type="hidden" name="action" value="reply">
                          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf_msg']); ?>">
                          <input type="hidden" name="booking_id" value="<?php echo $bid; ?>">
                          <input type="hidden" name="photographer_id" value="<?php echo $pid; ?>">
                          <textarea name="message" placeholder="พิมพ์ข้อความถึงช่างภาพ..."></textarea>
                          <button class="btn" type="submit">ส่งข้อความ</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endif; // seenBooking ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
