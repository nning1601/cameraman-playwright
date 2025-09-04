<?php
session_start();
require 'db.php';
if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit;
}
$photographer_id = (int)$_SESSION['photographer_id'];
mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$firstName = $lastName = '';
if ($stmt = $conn->prepare("SELECT first_name, last_name FROM photographer WHERE photographer_id = ?")) {
    $stmt->bind_param("i", $photographer_id);
    $stmt->execute();
    $stmt->bind_result($firstName, $lastName);
    $stmt->fetch();
    $stmt->close();
}

$conn->query("
    CREATE TABLE IF NOT EXISTS admin_messages_photographer (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        photographer_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (photographer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_messages_photographer_reply (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        admin_id INT NULL,
        reply_text TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_msg_reply_msg
          FOREIGN KEY (message_id) REFERENCES admin_messages_photographer(id)
          ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
function valid_csrf(): bool {
    return $_SERVER['REQUEST_METHOD'] !== 'POST'
        || (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? ''));
}

$ok = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!valid_csrf()) {
        $err = 'คำขอไม่ถูกต้อง (CSRF)';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if ($subject === '' || $message === '') {
            $err = 'กรุณากรอกหัวข้อและข้อความให้ครบถ้วน';
        } else {
            if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);
            $ins = $conn->prepare("INSERT INTO admin_messages_photographer (photographer_id, subject, message) VALUES (?, ?, ?)");
            if ($ins) {
                $ins->bind_param("iss", $photographer_id, $subject, $message);
                if ($ins->execute()) {
                    $ok = '✅ ส่งข้อความเรียบร้อยแล้ว';
                    $_POST['subject'] = $_POST['message'] = '';
                } else {
                    $err = 'เกิดข้อผิดพลาดในการส่งข้อความ: '.h($ins->error);
                }
                $ins->close();
            } else {
                $err = 'ไม่สามารถเตรียมคำสั่งบันทึกได้: '.h($conn->error);
            }
        }
    }
}

$per  = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$off  = ($page-1)*$per;

$count = 0;
if ($cq = $conn->prepare("SELECT COUNT(*) FROM admin_messages_photographer WHERE photographer_id=?")) {
    $cq->bind_param("i", $photographer_id);
    $cq->execute();
    $cq->bind_result($count);
    $cq->fetch();
    $cq->close();
}
$pages = max(1, (int)ceil($count/$per));

$threads = [];
if ($stm = $conn->prepare("
    SELECT id, subject, message, is_read, created_at
    FROM admin_messages_photographer
    WHERE photographer_id=?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
")) {
    $stm->bind_param("iii", $photographer_id, $per, $off);
    $stm->execute();
    $res = $stm->get_result();
    $threads = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stm->close();
}

$repliesByMsg = [];
if ($threads) {
    $ids = array_column($threads, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sqlRp = "SELECT message_id, reply_text, created_at
              FROM admin_messages_photographer_reply
              WHERE message_id IN ($in)
              ORDER BY created_at ASC";
    if ($q = $conn->prepare($sqlRp)) {
        $bind = [$types];
        foreach ($ids as $k => $v) { $bind[] = &$ids[$k]; }
        call_user_func_array([$q, 'bind_param'], $bind);
        $q->execute();
        $rr = $q->get_result();
        while ($row = $rr->fetch_assoc()) {
            $repliesByMsg[$row['message_id']][] = $row;
        }
        $q->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ติดต่อผู้ดูแลระบบ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{
  width:100%;
  position:fixed;top:0;left:0;
  background: linear-gradient(90deg,#0b1220,#111827);
  display:flex;align-items:center;
  padding:14px 0;
  box-shadow:0 4px 20px rgba(0,0,0,.25);
  z-index:100
}
.nav-inner{
  width:100%;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 12px
}
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
.wrapper{
  flex:1;display:flex;justify-content:center;align-items:flex-start;
  padding:120px 16px 48px
}
.container{
  width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px
}
.card{
  width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb
}
.section-title{margin:0 0 10px;font-size:22px;font-weight:900;color:#0b1220}
.label{font-weight:800;color:#0b1220}
.input,.textarea{
  width:100%;padding:12px 14px;border-radius:12px;border:1px solid #dbeafe;background:#fff
}
.textarea{min-height:140px;resize:vertical}
.actions{display:flex;gap:10px;justify-content:flex-end}
.btn{appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:900;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.btn-ghost{background:#f8fafc;border:1px solid #e5e7eb}
.alert{padding:10px 12px;border-radius:12px;font-weight:800}
.ok{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.row{display:grid;gap:14px}
.thread{border:1px solid #eef2ff;border-radius:14px;padding:12px;background:#fafbff}
.thread-head{display:flex;gap:8px;align-items:center}
.tag{font-size:12px;border-radius:999px;padding:4px 8px;border:1px solid #e5e7eb;background:#fff}
.tag.unread{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.thread-sub{font-weight:800}
.thread-time{margin-left:auto;color:#6b7280;font-size:12px}
.thread-body{color:#111;margin-top:6px;white-space:pre-wrap}
.thread-replies{margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:8px}
.reply{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;margin-top:8px;color:#111}
.small{font-size:12px;color:#6b7280}
.pager{display:flex;gap:6px;justify-content:center;margin-top:10px}
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;background:#fff;color:#111}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .wrapper{padding:110px 12px 32px}
}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($firstName) ?></span>
      <a href="photographer_bookings.php">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php" class="active">ติดต่อผู้ดูแลระบบ</a>
      <a href="photographer_bookings_crud.php">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">ดูข้อมูลการมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h2 class="section-title">ส่งข้อความถึงผู้ดูแลระบบ</h2>
      <?php if($ok): ?><div class="alert ok"><?= h($ok) ?></div><?php endif; ?>
      <?php if($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
      <form method="post" class="row" novalidate>
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <label class="label">หัวข้อ</label>
        <input class="input" type="text" name="subject" placeholder="เช่น ปัญหาการใช้งาน, แจ้งบั๊ก, สอบถามงาน" required value="<?= h($_POST['subject'] ?? '') ?>">
        <label class="label">ข้อความ</label>
        <textarea class="textarea" name="message" placeholder="อธิบายรายละเอียดที่ต้องการติดต่อ..." required><?= h($_POST['message'] ?? '') ?></textarea>
        <div class="actions">
          <button class="btn btn-ghost" type="reset">ล้างข้อมูล</button>
          <button class="btn btn-primary" type="submit">ส่งข้อความ</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2 class="section-title">ประวัติการติดต่อ</h2>
      <?php if(!$threads): ?>
        <div class="alert" style="border:1px dashed #e5e7eb;background:#fff;color:#6b7280">ยังไม่มีการติดต่อ</div>
      <?php else: ?>
        <div class="row">
          <?php foreach($threads as $t): ?>
            <div class="thread">
              <div class="thread-head">
                <span class="tag <?= $t['is_read'] ? '' : 'unread' ?>"><?= $t['is_read'] ? 'อ่านแล้ว' : 'ยังไม่อ่าน' ?></span>
                <div class="thread-sub"><?= h($t['subject']) ?></div>
                <div class="thread-time"><?= h(date('d/m/Y H:i', strtotime($t['created_at']))) ?></div>
              </div>
              <div class="thread-body">
                <span style="color:#6a11cb;font-weight:bold;">[ฉัน]</span>
                <?= nl2br(h($t['message'])) ?>
              </div>
              <?php if(!empty($repliesByMsg[$t['id']])): ?>
                <div class="thread-replies">
                  <div class="small">คำตอบจากผู้ดูแลระบบ</div>
                  <?php foreach($repliesByMsg[$t['id']] as $rp): ?>
                    <div class="reply">
                      <div class="small"><?= h(date('d/m/Y H:i', strtotime($rp['created_at']))) ?></div>
                      <div><span style="font-weight:bold;">[แอดมิน]</span> <?= nl2br(h($rp['reply_text'])) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if($pages>1): ?>
          <div class="pager">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if($i==$page): ?>
                <span class="active"><?= $i ?></span>
              <?php else: ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
