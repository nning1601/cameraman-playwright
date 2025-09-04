<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login_user.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($firstName, $lastName);
$stmt->fetch();
$stmt->close();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$conn->query("CREATE TABLE IF NOT EXISTS admin_messages (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,subject VARCHAR(255) NOT NULL,message TEXT NOT NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$col = $conn->query("SHOW COLUMNS FROM admin_messages LIKE 'is_read'");
if ($col && $col->num_rows === 0) { $conn->query("ALTER TABLE admin_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0"); }
$conn->query("CREATE TABLE IF NOT EXISTS admin_messages_reply (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,message_id INT NOT NULL,admin_id INT NULL,reply_text TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_msg_user_reply FOREIGN KEY (message_id) REFERENCES admin_messages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$ok = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($subject === '' || $message === '') {
        $err = 'กรุณากรอกหัวข้อและข้อความให้ครบถ้วน';
    } else {
        if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);
        $ins = $conn->prepare("INSERT INTO admin_messages (user_id, subject, message) VALUES (?, ?, ?)");
        if ($ins) {
            $ins->bind_param("iss", $user_id, $subject, $message);
            if ($ins->execute()) { $ok = '✅ ส่งข้อความถึงผู้ดูแลระบบเรียบร้อยแล้ว'; $_POST['subject'] = $_POST['message'] = ''; }
            else { $err = 'เกิดข้อผิดพลาดในการส่งข้อความ: ' . $ins->error; }
            $ins->close();
        } else { $err = 'ไม่สามารถเตรียมคำสั่งบันทึกได้: ' . $conn->error; }
    }
}
$per  = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$off  = ($page-1)*$per;
$count = 0;
$cq = $conn->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id=?");
$cq->bind_param("i", $user_id);
$cq->execute(); $cq->bind_result($count); $cq->fetch(); $cq->close();
$pages = max(1, (int)ceil($count/$per));
$sql = "SELECT id, subject, message, is_read, created_at FROM admin_messages WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stm = $conn->prepare($sql);
$stm->bind_param("iii", $user_id, $per, $off);
$stm->execute();
$res = $stm->get_result();
$threads = $res->fetch_all(MYSQLI_ASSOC);
$stm->close();
$repliesByMsg = [];
if ($threads) {
    $ids = array_column($threads, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $q = $conn->prepare("SELECT message_id, reply_text, created_at FROM admin_messages_reply WHERE message_id IN ($in) ORDER BY created_at ASC");
    $bind = [$types];
    foreach ($ids as $k => $v) { $bind[] = &$ids[$k]; }
    call_user_func_array([$q, 'bind_param'], $bind);
    $q->execute(); $rr = $q->get_result();
    while ($row = $rr->fetch_assoc()) { $repliesByMsg[$row['message_id']][] = $row; }
    $q->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ติดต่อผู้ดูแลระบบ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{box-sizing:border-box}html,body{margin:0;padding:0;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center;color:#0f172a}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:100px}
.page{padding:24px 16px;display:grid;gap:16px;justify-content:center;width:100%}
.wrap{width:min(1100px,96vw);display:grid;gap:16px}
.card{background:#fff;border:1px solid #eef2ff;border-radius:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:22px}
.card h2{margin:0 0 10px;color:#4a148c}
.row{display:grid;gap:14px}
.label{font-weight:800}
.input,.textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #e5e7eb}
.textarea{min-height:140px;resize:vertical}
.actions{display:flex;gap:10px;justify-content:flex-end}
.btn{appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:800;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.btn-ghost{background:#f8fafc;border:1px solid #e5e7eb}
.alert{padding:10px 12px;border-radius:12px;font-weight:700}
.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.35);color:#065f46}
.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#7f1d1d}
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
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#111;background:#fff}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
@media(max-width:640px){.logo{font-size:18px}.menu a{font-size:13px;padding:7px 9px}}
</style>
</head>
<body>

<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a class="active" aria-current="page" href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff;">ออกจากระบบ</a>
  </div>
</div>

<div class="header-spacer"></div>

<main class="page">
  <div class="wrap">

    <div class="card">
      <h2>✉️ ติดต่อผู้ดูแลระบบ</h2>
      <?php if ($ok): ?><div class="alert ok"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
      <form method="post" class="row" novalidate>
        <input type="hidden" name="action" value="send">
        <label class="label">หัวข้อ</label>
        <input class="input" type="text" name="subject" placeholder="เช่น ปัญหาการใช้งาน, แจ้งบั๊ก, สอบถามการจอง" required value="<?= h($_POST['subject'] ?? '') ?>">
        <label class="label">ข้อความ</label>
        <textarea class="textarea" name="message" placeholder="อธิบายรายละเอียดที่ต้องการติดต่อ..." required><?= h($_POST['message'] ?? '') ?></textarea>
        <div class="actions">
          <button class="btn btn-ghost" type="reset">ล้างข้อมูล</button>
          <button class="btn btn-primary" type="submit">ส่งข้อความ</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>💬 ประวัติการติดต่อ</h2>
      <?php if(!$threads): ?>
        <div class="alert" style="border:1px dashed #e5e7eb;background:#fff;color:#6b7280">ยังไม่มีการติดต่อ</div>
      <?php else: ?>
        <div class="row">
          <?php foreach($threads as $t): ?>
            <div class="thread">
              <div class="thread-head">
                <span class="tag <?= $t['is_read'] ? '' : 'unread' ?>"><?= $t['is_read'] ? 'แอดมินอ่านแล้ว' : 'แอดมินยังไม่อ่าน' ?></span>
                <div class="thread-sub"><?= h($t['subject']) ?></div>
                <div class="thread-time"><?= h(date('d/m/Y H:i', strtotime($t['created_at']))) ?></div>
              </div>
              <div class="thread-body"><?= nl2br(h($t['message'])) ?></div>
              <?php if(!empty($repliesByMsg[$t['id']])): ?>
                <div class="thread-replies">
                  <div class="small">คำตอบจากผู้ดูแลระบบ</div>
                  <?php foreach($repliesByMsg[$t['id']] as $rp): ?>
                    <div class="reply">
                      <div class="small"><?= h(date('d/m/Y H:i', strtotime($rp['created_at']))) ?></div>
                      <div><?= nl2br(h($rp['reply_text'])) ?></div>
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
              <?php if($i==$page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</main>

</body>
</html>
