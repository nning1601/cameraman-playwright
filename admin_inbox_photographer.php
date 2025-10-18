<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$conn->query("CREATE TABLE IF NOT EXISTS admin_messages_photographer (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    photographer_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (photographer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$conn->query("CREATE TABLE IF NOT EXISTS admin_messages_photographer_reply (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    admin_id INT NULL,
    reply_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_reply_msg FOREIGN KEY (message_id) REFERENCES admin_messages_photographer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (isset($_GET['mark_read'])) {
    $mid = (int)$_GET['mark_read'];
    if ($mid > 0) {
        if ($s=$conn->prepare("UPDATE admin_messages_photographer SET is_read=1 WHERE id=?")) { $s->bind_param("i",$mid); $s->execute(); $s->close(); }
    }
    header("Location: admin_inbox_photographer.php?view=".$mid);
    exit;
}

$ok = '';
$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='reply') {
    $mid  = (int)($_POST['message_id'] ?? 0);
    $text = trim($_POST['reply_text'] ?? '');
    if ($mid<=0 || $text==='') {
        $err = 'กรุณากรอกข้อความตอบกลับ';
    } else {
        if ($ins=$conn->prepare("INSERT INTO admin_messages_photographer_reply (message_id, admin_id, reply_text) VALUES (?, ?, ?)")) {
            $ins->bind_param("iis", $mid, $admin_id, $text);
            if ($ins->execute()) {
                $ok = '✅ ส่งคำตอบเรียบร้อย';
                if ($u=$conn->prepare("UPDATE admin_messages_photographer SET is_read=1 WHERE id=?")) { $u->bind_param("i",$mid); $u->execute(); $u->close(); }
            } else { $err='บันทึกคำตอบล้มเหลว: '.$ins->error; }
            $ins->close();
        } else { $err='เตรียมคำสั่งล้มเหลว: '.$conn->error; }
    }
}

$viewId = (int)($_GET['view'] ?? 0);
$viewMsg = null;
$viewReplies = [];
if ($viewId>0) {
    if ($q=$conn->prepare("SELECT m.id, m.subject, m.message, m.is_read, m.created_at, p.first_name, p.last_name, p.photographer_id FROM admin_messages_photographer m JOIN photographer p ON p.photographer_id = m.photographer_id WHERE m.id=?")) {
        $q->bind_param("i",$viewId);
        $q->execute();
        $res=$q->get_result();
        $viewMsg = $res? $res->fetch_assoc():null;
        $q->close();
        if ($viewMsg) {
            if ($r=$conn->prepare("SELECT reply_text, created_at, admin_id FROM admin_messages_photographer_reply WHERE message_id=? ORDER BY created_at ASC")) {
                $r->bind_param("i",$viewId);
                $r->execute();
                $rs=$r->get_result();
                $viewReplies = $rs? $rs->fetch_all(MYSQLI_ASSOC):[];
                $r->close();
            }
        }
    }
}

$unread = 0;
if ($rs=$conn->query("SELECT COUNT(*) c FROM admin_messages_photographer WHERE is_read=0")) { $row=$rs->fetch_assoc(); $unread=(int)($row['c']??0); $rs->close(); }
$per=12; $page=max(1,(int)($_GET['page'] ?? 1)); $off=($page-1)*$per;
$total=0; if ($rs=$conn->query("SELECT COUNT(*) c FROM admin_messages_photographer")){ $total=(int)($rs->fetch_assoc()['c']??0); $rs->close(); }
$pages=max(1,(int)ceil($total/$per));
$list=[];
if ($rs=$conn->query("SELECT m.id, m.subject, m.is_read, m.created_at, CONCAT(p.first_name,' ',p.last_name) AS name FROM admin_messages_photographer m JOIN photographer p ON p.photographer_id = m.photographer_id ORDER BY m.created_at DESC LIMIT $per OFFSET $off")) {
    $list=$rs->fetch_all(MYSQLI_ASSOC); $rs->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>กล่องข้อความช่างภาพ - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
.section{width:100%;display:grid;grid-template-columns:1fr 1.2fr;gap:16px}
@media (max-width:980px){.section{grid-template-columns:1fr}}
.card{background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b122a;font-weight:900}
.list{display:grid;gap:10px}
.item{border:1px solid #eef2ff;border-radius:12px;padding:10px;background:#fafbff;display:grid;gap:6px}
.item .top{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.item .time{color:#6b7280;font-size:12px}
.tag{font-size:12px;border-radius:999px;padding:2px 8px;border:1px solid #e5e7eb;background:#fff}
.tag.unread{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.alert{padding:10px 12px;border-radius:12px;font-weight:700;border:1px dashed #e5e7eb;background:#fff;color:#6b7280}
.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.35);color:#065f46;padding:10px 12px;border-radius:12px;margin-top:8px}
.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#7f1d1d;padding:10px 12px;border-radius:12px;margin-top:8px}
.small{font-size:12px;color:#6b7280}
.label{font-weight:800;margin-top:8px}
.textarea{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;min-height:140px}
.actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
.btn{appearance:none;border:none;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#6366f1,#4f46e5);color:#fff}
.btn-light{background:#f8fafc;border:1px solid #e5e7eb}
.pager{display:flex;gap:6px;justify-content:center;margin-top:10px}
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;background:#fff}
.pager .active{background:linear-gradient(90deg,#6366f1,#4f46e5);color:#fff;border-color:transparent}
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
      <span class="badge">ยังไม่อ่าน: <?= (int)$unread ?></span>
      <a href="admin_inbox_photographer.php" class="active">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">หน้าแรก</a>
  <a href="manage_users.php">ผู้ใช้</a>
  <a href="manage_photographers.php">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <span class="badge">ยังไม่อ่าน: <?= (int)$unread ?></span>
  <a href="admin_inbox_photographer.php" class="active">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout" style="color:#fff;">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <div class="section">
      <div class="card">
        <h2>รายการข้อความ</h2>
        <div class="list">
          <?php if(!$list): ?>
            <div class="alert">ยังไม่มีข้อความ</div>
          <?php else: foreach($list as $it): ?>
            <div class="item">
              <div class="top">
                <span class="tag <?= $it['is_read'] ? '' : 'unread' ?>"><?= $it['is_read'] ? 'อ่านแล้ว' : 'ยังไม่อ่าน' ?></span>
                <a href="?view=<?= (int)$it['id'] ?>"><b><?= h($it['subject']) ?></b></a>
                <span class="time"><?= h(date('d/m/Y H:i', strtotime($it['created_at']))) ?></span>
              </div>
              <div class="small">จาก: <?= h($it['name']) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <?php if($pages>1): ?>
          <div class="pager">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if($i==$page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <?php if(!$viewMsg): ?>
          <div class="alert">เลือกข้อความทางซ้ายเพื่ออ่านและตอบกลับ</div>
        <?php else: ?>
          <h2>หัวข้อ: <?= h($viewMsg['subject']) ?></h2>
          <div class="small">จาก <?= h($viewMsg['first_name'].' '.$viewMsg['last_name']) ?> • เวลา <?= h(date('d/m/Y H:i', strtotime($viewMsg['created_at']))) ?></div>
          <div style="margin-top:8px;white-space:pre-wrap"><?= nl2br(h($viewMsg['message'])) ?></div>
          <div style="margin:12px 0">
            <?php if(!(int)$viewMsg['is_read']): ?>
              <a class="btn btn-light" href="?mark_read=<?= (int)$viewMsg['id'] ?>&view=<?= (int)$viewMsg['id'] ?>">ทำเครื่องหมายว่าอ่านแล้ว</a>
            <?php endif; ?>
          </div>
          <?php if($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>
          <?php if($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
          <?php if($viewReplies): ?>
            <div class="small" style="margin-top:8px">ประวัติการตอบกลับ</div>
            <?php foreach($viewReplies as $rp): ?>
              <div class="item" style="margin-top:8px">
                <div class="small"><?= h(date('d/m/Y H:i', strtotime($rp['created_at']))) ?></div>
                <div style="white-space:pre-wrap"><?= nl2br(h($rp['reply_text'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <form method="post" class="row" style="margin-top:10px">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="message_id" value="<?= (int)$viewMsg['id'] ?>">
            <div class="label">ตอบกลับ</div>
            <textarea class="textarea" name="reply_text" placeholder="พิมพ์ข้อความตอบกลับ..." required><?= h($_POST['reply_text'] ?? '') ?></textarea>
            <div class="actions">
              <button class="btn btn-light" type="reset">ล้าง</button>
              <button class="btn btn-primary" type="submit">ส่งคำตอบ</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
const burgerBtn=document.getElementById('burgerBtn');
const drawer=document.getElementById('drawerMenu');
let drawerOpen=false;
burgerBtn&&burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});
</script>
</body>
</html>
<?php
mysqli_close($conn);
?>
