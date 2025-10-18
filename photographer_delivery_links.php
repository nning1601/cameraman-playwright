<?php
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit;
}
$PHOTOGRAPHER_ID = (int)$_SESSION['photographer_id'];
$MAX_LINKS_PER_JOB = 5;

/* helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(){ return $_SERVER['REMOTE_ADDR'] ?? null; }
function client_ua(){ return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function csrf_ok($token){ return hash_equals($_SESSION['csrf_token'] ?? '', $token ?? ''); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_SESSION['add_link_nonce'])) { $_SESSION['add_link_nonce'] = bin2hex(random_bytes(16)); }

/* check url */
function is_valid_url($url){
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url); if (!$parts) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http','https'], true)) return false;
    if (preg_match('#^(javascript|data|vbscript):#i', $url)) return false;
    return true;
}
/* info_schema helper สำหรับเช็คคอลัมน์ */
function has_col(mysqli $c, string $table, string $col): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st=$c->prepare($sql); $st->bind_param('ss',$table,$col); $st->execute();
    $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/* schema */
$conn->query("CREATE TABLE IF NOT EXISTS delivery_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT DEFAULT NULL,
  photographer_id INT NOT NULL,
  url TEXT NOT NULL,
  title VARCHAR(200) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  delivered_by_name VARCHAR(100) DEFAULT NULL,
  delivered_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (booking_id),
  INDEX (photographer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS delivery_share (
  booking_id INT NOT NULL PRIMARY KEY,
  token VARCHAR(64) NOT NULL UNIQUE,
  usage_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS delivery_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  photographer_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  item_type VARCHAR(50) NOT NULL,
  item_id INT DEFAULT NULL,
  details JSON DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (booking_id),
  INDEX (photographer_id),
  INDEX (action),
  INDEX (item_type),
  INDEX (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
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
  INDEX (job_id),
  INDEX (user_id),
  INDEX (photographer_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* เพิ่มคอลัมน์ seq_no หากยังไม่มี (ใช้เก็บ “ลำดับการส่งงาน”) */
if (!has_col($conn,'delivery_links','seq_no')) {
    $conn->query("ALTER TABLE delivery_links ADD COLUMN seq_no INT NOT NULL DEFAULT 0 AFTER id");
}

function job_belongs_to_photographer(mysqli $conn, int $job_id, int $photographer_id): bool {
    $sql = "SELECT 1 FROM job WHERE job_id=? AND photographer_id=? LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ii', $job_id, $photographer_id);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
function ensure_share_token(mysqli $conn, int $job_id, int $photographer_id){
    $st = $conn->prepare("SELECT token FROM delivery_share WHERE booking_id=?");
    $st->bind_param('i', $job_id);
    $st->execute();
    $rs = $st->get_result();
    if ($row = $rs->fetch_assoc()){ $st->close(); return $row['token']; }
    $st->close();
    $token = bin2hex(random_bytes(16));
    $st = $conn->prepare("INSERT INTO delivery_share(booking_id, token, usage_count) VALUES(?, ?, 0)");
    $st->bind_param('is', $job_id, $token);
    $st->execute(); $st->close();
    $details = json_encode(['reason'=>'auto-create','token_preview'=>substr($token,0,8).'…'], JSON_UNESCAPED_UNICODE);
    $st = $conn->prepare("INSERT INTO delivery_audit(booking_id, photographer_id, action, item_type, item_id, details, ip, user_agent)
                          VALUES(?, ?, 'CREATE_SHARE', 'share', NULL, ?, ?, ?)");
    $ip = client_ip(); $ua = client_ua();
    $st->bind_param('iisss', $job_id, $photographer_id, $details, $ip, $ua);
    $st->execute(); $st->close();
    return $token;
}

/* โหลดข้อมูลช่างภาพ/ลูกค้า */
$firstName = '';
$photographer_name = 'ไม่ทราบชื่อ';
$st = $conn->prepare("SELECT first_name, last_name FROM photographer WHERE photographer_id=?");
$st->bind_param('i', $PHOTOGRAPHER_ID);
$st->execute(); $rs = $st->get_result();
if ($r = $rs->fetch_assoc()) {
    $firstName = $r['first_name'] ?? '';
    $photographer_name = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
}
$st->close();

/* job ปัจจุบัน */
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
if ($job_id <= 0) {
    $st = $conn->prepare("SELECT job_id FROM job WHERE photographer_id=? ORDER BY job_date DESC, job_id DESC LIMIT 1");
    $st->bind_param('i', $PHOTOGRAPHER_ID);
    $st->execute();
    $rs = $st->get_result();
    if ($row = $rs->fetch_assoc()) { $job_id = (int)$row['job_id']; }
    $st->close();
}

/* ลูกค้าของงาน */
$customer = null;
if ($job_id > 0 && job_belongs_to_photographer($conn, $job_id, $PHOTOGRAPHER_ID)) {
    $st = $conn->prepare("SELECT j.user_id,
             COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name), ' '), u.first_name, u.last_name, CONCAT('ผู้ใช้ #', u.user_id)) AS cname
          FROM job j JOIN users u ON u.user_id = j.user_id
          WHERE j.job_id=? AND j.photographer_id=? LIMIT 1");
    $st->bind_param('ii', $job_id, $PHOTOGRAPHER_ID);
    $st->execute();
    $rs = $st->get_result();
    if ($row = $rs->fetch_assoc()){ $customer = ['user_id'=>(int)$row['user_id'], 'name'=>(string)$row['cname']]; }
    $st->close();
}

/* flash -> ใช้เปิด modal หลัง redirect */
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$popup_text = '';
$popup_kind = ''; // success | error
if ($flash !== '') {
  $popup_text = $flash;
  // เดาง่ายๆ: ถ้ามีคำลักษณะผิดพลาดให้เป็น error
  $popup_kind = preg_match('/(ไม่สำเร็จ|ไม่พบ|ไม่ถูกต้อง|โควตา|ไม่ได้|ผิดพลาด|error|fail)/u', $flash) ? 'error' : 'success';
}

/* POST */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['csrf'] ?? '';
    $redir  = $_SERVER['PHP_SELF'].'?job_id='.(int)($job_id ?: ($_POST['job_id'] ?? 0));
    if (!csrf_ok($csrf)) { $_SESSION['flash'] = 'CSRF token ไม่ถูกต้อง'; header("Location: $redir"); exit; }

    if ($action === 'add_link') {
        $confirm = ($_POST['confirm_add'] ?? '') === '1';
        $nonce_ok = hash_equals($_SESSION['add_link_nonce'] ?? '', $_POST['add_link_nonce'] ?? '');
        unset($_SESSION['add_link_nonce']);
        if (!$confirm || !$nonce_ok) { $_SESSION['flash'] = 'การส่ง URL ต้องกดปุ่มบันทึกลิงก์'; header("Location: $redir"); exit; }

        $p_job_id = (int)$_POST['job_id'];
        $url   = trim((string)($_POST['url'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $note  = trim((string)($_POST['note'] ?? ''));
        $by    = trim((string)($_POST['delivered_by_name'] ?? ''));

        if ($p_job_id <= 0 || !job_belongs_to_photographer($conn, $p_job_id, $PHOTOGRAPHER_ID)) {
            $_SESSION['flash'] = 'ไม่พบงานของคุณ'; header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit;
        }

        $st = $conn->prepare("SELECT COUNT(*) AS c FROM delivery_links WHERE booking_id=? AND photographer_id=?");
        $st->bind_param('ii', $p_job_id, $PHOTOGRAPHER_ID);
        $st->execute();
        $rc = $st->get_result()->fetch_assoc();
        $current_count = (int)($rc['c'] ?? 0);
        $st->close();

        if ($current_count >= $MAX_LINKS_PER_JOB) { $_SESSION['flash'] = 'ส่งลิงก์ได้ไม่เกิน '.$MAX_LINKS_PER_JOB.' รายการต่อหนึ่งงาน'; header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit; }
        if (!is_valid_url($url)) { $_SESSION['flash'] = 'URL ไม่ถูกต้อง (อนุญาตเฉพาะ http/https)'; header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit; }

        ensure_share_token($conn, $p_job_id, $PHOTOGRAPHER_ID);

        /* หา seq ถัดไป */
        $st = $conn->prepare("SELECT COALESCE(MAX(seq_no),0)+1 AS next_seq FROM delivery_links WHERE booking_id=? AND photographer_id=?");
        $st->bind_param('ii', $p_job_id, $PHOTOGRAPHER_ID);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $next_seq = (int)($row['next_seq'] ?? 1);
        $st->close();

        $st = $conn->prepare("INSERT INTO delivery_links
            (booking_id, photographer_id, url, title, note, delivered_by_name, delivered_at, created_at, seq_no)
            VALUES(?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
        $st->bind_param('iissssi', $p_job_id, $PHOTOGRAPHER_ID, $url, $title, $note, $by, $next_seq);
        $ok = $st->execute(); $new_id = $st->insert_id; $st->close();

        if ($ok) {
            $details = json_encode(['url'=>$url,'title'=>$title,'note'=>$note,'seq_no'=>$next_seq], JSON_UNESCAPED_UNICODE);
            $st = $conn->prepare("INSERT INTO delivery_audit(booking_id, photographer_id, action, item_type, item_id, details, ip, user_agent)
                                  VALUES(?, ?, 'send_link', 'delivery_links', ?, ?, ?, ?)");
            $ip = client_ip(); $ua = client_ua();
            $st->bind_param('iiisss', $p_job_id, $PHOTOGRAPHER_ID, $new_id, $details, $ip, $ua);
            $st->execute(); $st->close();
            $_SESSION['flash'] = 'เพิ่มลิงก์เรียบร้อย (ลำดับที่ '.$next_seq.') ('.($current_count+1).'/'.$MAX_LINKS_PER_JOB.')';
        } else {
            $_SESSION['flash'] = 'เพิ่มลิงก์ไม่สำเร็จ';
        }
        header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit;

    } elseif ($action === 'delete_link') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        $st = $conn->prepare("SELECT id, booking_id, photographer_id, url, title, note, delivered_at, seq_no
                              FROM delivery_links WHERE id=? AND photographer_id=? LIMIT 1");
        $st->bind_param('ii', $link_id, $PHOTOGRAPHER_ID);
        $st->execute();
        $rs = $st->get_result();
        if ($row = $rs->fetch_assoc()){
            $p_job_id = (int)$row['booking_id'];
            $details = json_encode([
                'by'=>$_SESSION['photographer_name'] ?? '',
                'url'=>$row['url'],
                'note'=>$row['note'],
                'title'=>$row['title'],
                'delivered_at'=>$row['delivered_at'],
                'seq_no'=>$row['seq_no']], JSON_UNESCAPED_UNICODE);
            $st2 = $conn->prepare("DELETE FROM delivery_links WHERE id=? AND photographer_id=?");
            $st2->bind_param('ii', $link_id, $PHOTOGRAPHER_ID);
            $ok = $st2->execute(); $st2->close();
            $st3 = $conn->prepare("INSERT INTO delivery_audit(booking_id, photographer_id, action, item_type, item_id, details, ip, user_agent)
                                   VALUES(?, ?, 'delete_link', 'delivery_links', ?, ?, ?, ?)");
            $ip = client_ip(); $ua = client_ua();
            $st3->bind_param('iiisss', $p_job_id, $PHOTOGRAPHER_ID, $link_id, $details, $ip, $ua);
            $st3->execute(); $st3->close();
            $_SESSION['flash'] = $ok ? 'ลบลิงก์เรียบร้อย' : 'ลบลิงก์ไม่สำเร็จ';
            header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit;
        } else {
            $_SESSION['flash'] = 'ไม่พบลิงก์ของคุณ';
            header("Location: $redir"); exit;
        }

    } elseif ($action === 'send_msg') {
        $p_job_id = (int)($_POST['job_id'] ?? 0);
        $msg = trim((string)($_POST['message'] ?? ''));
        if ($p_job_id <= 0 || !job_belongs_to_photographer($conn, $p_job_id, $PHOTOGRAPHER_ID)) { $_SESSION['flash'] = 'ไม่พบงานของคุณ'; header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit; }
        if ($msg === '') { $_SESSION['flash'] = 'กรุณาพิมพ์ข้อความ'; header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit; }
        $st = $conn->prepare("SELECT user_id FROM job WHERE job_id=? AND photographer_id=? LIMIT 1");
        $st->bind_param('ii', $p_job_id, $PHOTOGRAPHER_ID);
        $st->execute(); $uid = 0; $rs = $st->get_result();
        if ($r = $rs->fetch_assoc()) { $uid = (int)$r['user_id']; }
        $st->close();
        if ($uid > 0) {
            $st = $conn->prepare("INSERT INTO chat_messages(job_id, booking_id, user_id, photographer_id, sender, message, is_read_by_user, is_read_by_photographer, created_at)
                                   VALUES(?, ?, ?, ?, 'photographer', ?, 0, 1, NOW())");
            $st->bind_param('iiiis', $p_job_id, $p_job_id, $uid, $PHOTOGRAPHER_ID, $msg);
            $ok = $st->execute(); $new_chat_id = $st->insert_id; $st->close();
            $preview = mb_strimwidth($msg, 0, 120, '…', 'UTF-8');
            $details = json_encode(['message_preview'=>$preview], JSON_UNESCAPED_UNICODE);
            $st = $conn->prepare("INSERT INTO delivery_audit(booking_id, photographer_id, action, item_type, item_id, details, ip, user_agent)
                                  VALUES(?, ?, 'send_msg', 'chat_messages', ?, ?, ?, ?)");
            $ip = client_ip(); $ua = client_ua();
            $st->bind_param('iiisss', $p_job_id, $PHOTOGRAPHER_ID, $new_chat_id, $details, $ip, $ua);
            $st->execute(); $st->close();
            $_SESSION['flash'] = $ok ? 'ส่งข้อความแล้ว' : 'ส่งข้อความไม่สำเร็จ';
        } else {
            $_SESSION['flash'] = 'ไม่พบลูกค้าของงานนี้';
        }
        header("Location: ".$_SERVER['PHP_SELF'].'?job_id='.$p_job_id); exit;

    } else {
        $_SESSION['flash'] = 'ไม่รู้จักคำสั่ง';
        header("Location: $redir"); exit;
    }
}

/* รายชื่องาน */
$jobs = [];
$st = $conn->prepare("SELECT job_id, job_date, job_time, location, status FROM job WHERE photographer_id=? ORDER BY job_date DESC, job_id DESC");
$st->bind_param('i', $PHOTOGRAPHER_ID);
$st->execute(); $rs = $st->get_result();
while($r=$rs->fetch_assoc()){ $jobs[]=$r; }
$st->close();

/* โหลดลิงก์/แชท */
$token = ''; $links = []; $messages = [];
$link_count = 0; $can_add_link = true;
if ($job_id > 0 && job_belongs_to_photographer($conn, $job_id, $PHOTOGRAPHER_ID)) {
    $token = ensure_share_token($conn, $job_id, $PHOTOGRAPHER_ID);

    $st = $conn->prepare("SELECT id, url, title, note, delivered_by_name, delivered_at, created_at, seq_no
                          FROM delivery_links
                          WHERE booking_id=? AND photographer_id=?
                          ORDER BY seq_no ASC, id ASC");
    $st->bind_param('ii', $job_id, $PHOTOGRAPHER_ID);
    $st->execute(); $rs = $st->get_result();
    while($r=$rs->fetch_assoc()){ $links[]=$r; }
    $st->close();

    $link_count = count($links);
    $can_add_link = ($link_count < $MAX_LINKS_PER_JOB);

    if ($customer) {
        $uid = (int)$customer['user_id'];
        $st = $conn->prepare("SELECT id, sender, message, created_at
                               FROM chat_messages
                               WHERE job_id=? AND photographer_id=? AND user_id=?
                               ORDER BY id ASC LIMIT 200");
        $st->bind_param('iii', $job_id, $PHOTOGRAPHER_ID, $uid);
        $st->execute(); $rs = $st->get_result();
        while($r=$rs->fetch_assoc()){ $messages[]=$r; }
        $st->close();
    }
}

/* public url */
function absolute_url($path){
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    return $scheme.'://'.$host.$base.'/'.$path;
}
$public_url = $token ? absolute_url('delivery_open.php?token='.urlencode($token)) : '';

/* nonce */
$_SESSION['add_link_nonce'] = bin2hex(random_bytes(16));
$ADD_LINK_NONCE = $_SESSION['add_link_nonce'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ส่งลิงก์งาน + แชท | Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;font-family:'Prompt',sans-serif;color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{width:100%;position:fixed;top:0;left:0;background: linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
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
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.grid{display:grid;grid-template-columns:320px 1fr;gap:16px}
@media (max-width:960px){.grid{grid-template-columns:1fr}}
.section-title{font-size:20px;font-weight:900;color:#0b1220;margin:0 0 10px}
.jobs{max-height:72vh;overflow:auto;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc}
.job-item{padding:12px 14px;border-bottom:1px solid #e5e7eb;text-decoration:none;color:#0f172a;display:block}
.job-item:hover{background:#eef2ff}
.job-item.active{background:#e0e7ff;font-weight:800}
.muted{color:#6b7280;font-size:13px}
.input,textarea,select{width:100%;padding:10px 12px;border:1px solid #dbeafe;border-radius:10px;background:#fff;font-size:14px}
textarea{min-height:72px;resize:vertical}
.btn{appearance:none;border:none;border-radius:10px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;padding:10px 14px;font-weight:900;cursor:pointer}
.btn.secondary{background:linear-gradient(90deg,#22d3ee,#3b82f6)}
.btn.danger{background:#ef4444}
.btn:disabled{opacity:.6;cursor:not-allowed}
.table{width:100%;border-collapse:collapse;margin-top:10px}
.table th,.table td{padding:10px;border-bottom:1px dashed #e5e7eb;text-align:left;vertical-align:top}
.flash{background:#ecfeff;border:1px solid #67e8f9;color:#155e75;padding:10px 12px;border-radius:12px}

/* Center Modal (สวยๆ กลางจอ) */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(2,6,23,.55);
  backdrop-filter:saturate(160%) blur(2px);
  display:none; align-items:center; justify-content:center; z-index:10000;
}
.modal{
  width:min(520px,92vw); background:#fff; border:1px solid #eef0f3;
  border-radius:18px; box-shadow:0 30px 60px rgba(2,6,23,.3);
  padding:18px 18px 16px; transform:translateY(12px) scale(.98); opacity:0;
  transition:.22s cubic-bezier(.2,.8,.2,1);
}
.modal.show{ transform:translateY(0) scale(1); opacity:1; }
.modal-icon{
  width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:26px; margin-right:10px;
}
.modal-header{ display:flex; align-items:center; gap:10px; margin-bottom:6px }
.modal-title{ font-size:18px; font-weight:800; color:#0b1220 }
.modal-text{ color:#374151; font-size:14px; line-height:1.6; margin:8px 0 2px }
.modal-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px }
.btn-md{ padding:10px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#fff; font-weight:700; cursor:pointer }
.btn-md:hover{ background:#f8fafc }
.modal.success .modal-icon{ background:#dcfce7; color:#166534 }
.modal.success .modal-title{ color:#166534 }
.modal.error .modal-icon{ background:#fee2e2; color:#991b1b }
.modal.error .modal-title{ color:#991b1b }
.modal.info .modal-icon{ background:#e0e7ff; color:#3730a3 }
.modal.info .modal-title{ color:#3730a3 }
.modal-backdrop.show{ display:flex; }

.limit-note{margin-top:6px;font-size:13px;color:#0f172a;background:#f8fafc;border:1px dashed #cbd5e1;padding:8px 10px;border-radius:8px}
.share-box{display:grid;grid-template-columns:1fr auto;gap:8px}
.chat{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden}
.chat-head{padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
.chat-body{height:320px;overflow:auto;padding:14px;background:#fcfcff}
.bubble{max-width:72%;margin:8px 0;padding:10px 12px;border-radius:12px;position:relative;word-wrap:break-word;white-space:pre-wrap}
.me{margin-left:auto;background:#e0e7ff}
.other{margin-right:auto;background:#f1f5f9}
.time{display:block;font-size:11px;color:#666;margin-top:6px}
.chat-send{display:grid;grid-template-columns:1fr auto;gap:8px;padding:12px;background:#fff;border-top:1px solid #e5e7eb}
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .wrapper{padding:110px 12px 32px}
}
</style>
</head>
<body>

<!-- ===== Pretty Center Modal สำหรับผลลัพธ์ ===== -->
<div id="appModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div id="appModalBox" class="modal" tabindex="-1">
    <div class="modal-header">
      <div id="appModalIcon" class="modal-icon">ℹ️</div>
      <div class="modal-title" id="appModalTitle">แจ้งเตือน</div>
    </div>
    <div class="modal-text" id="appModalText">ข้อความแจ้งเตือน</div>
    <div class="modal-actions">
      <button type="button" class="btn-md" id="appModalOk">ตกลง</button>
    </div>
  </div>
</div>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($firstName ?: 'ช่างภาพ') ?></span>
      <a href="photographer_bookings.php">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php" class="active">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
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
    <h1 class="section-title">ส่งลิงก์งาน + คุยกับลูกค้า</h1>

    <?php if($flash): ?>
      <!-- ซ่อน banner เมื่อมี modal -->
      <div class="card flash" style="<?= $popup_text ? 'display:none' : '' ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="card">
        <div class="section-title">งานของฉัน</div>
        <div class="jobs">
          <?php foreach($jobs as $j):
            $href = '?job_id='.(int)$j['job_id'];
            $is = ((int)$j['job_id']===$job_id);
          ?>
            <a class="job-item <?= $is?'active':'' ?>" href="<?= h($href) ?>">
              <div><strong>#<?= (int)$j['job_id'] ?></strong> — <?= h($j['location'] ?: '-') ?></div>
              <div class="muted"><?= h(($j['job_date'] ?? '').' '.($j['job_time'] ?? '').' | '.($j['status'] ?? '')) ?></div>
            </a>
          <?php endforeach; if(!$jobs){ ?>
            <div class="job-item">ยังไม่มีงาน</div>
          <?php } ?>
        </div>
      </div>

      <div class="card">
        <?php if ($job_id>0 && $token): ?>
          <div class="section-title">
            ลิงก์แชร์ให้ลูกค้า <?= $customer ? ('— <b>'.h($customer['name']).'</b> ') : '' ?>งาน #<?= (int)$job_id ?>
          </div>
          <div class="share-box">
            <input class="input" type="text" id="shareLink" readonly value="<?= h($public_url) ?>">
            <button class="btn secondary" type="button" onclick="copyShare()">คัดลอก</button>
          </div>

          <div class="section-title" style="margin-top:16px">
            เพิ่มลิงก์ส่งงาน <span class="muted">(<?= (int)$link_count ?>/<?= (int)$MAX_LINKS_PER_JOB ?>)</span>
            <?= $customer ? '<span class="muted"> • ลูกค้า: '.h($customer['name']).'</span>' : '' ?>
          </div>

          <?php if ($can_add_link): ?>
            <form id="formAddLink" method="post" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="add_link">
              <input type="hidden" name="job_id" value="<?= (int)$job_id ?>">
              <input type="hidden" name="add_link_nonce" value="<?= h($ADD_LINK_NONCE) ?>">
              <div style="display:grid;grid-template-columns:1fr;gap:10px">
                <input class="input" type="text" name="url" placeholder="เช่น https://drive.google.com/..." required>
                <input class="input" type="text" name="title" placeholder="หัวเรื่อง (เช่น ส่งงานไฟนอล)">
                <textarea class="input" name="note" placeholder="โน้ตเพิ่มเติม (ถ้ามี)"></textarea>
                <input class="input" type="text" name="delivered_by_name" placeholder="ผู้ส่ง (ชื่อช่างภาพ)">
              </div>
              <div class="limit-note">เพิ่มได้อีก <?= (int)max(0, $MAX_LINKS_PER_JOB - $link_count) ?> ลิงก์</div>
              <div style="margin-top:10px">
                <button class="btn" type="submit" name="confirm_add" value="1">บันทึกลิงก์</button>
              </div>
            </form>
          <?php else: ?>
            <div class="limit-note"><strong>ครบโควตาแล้ว:</strong> ส่งลิงก์ได้ไม่เกิน <?= (int)$MAX_LINKS_PER_JOB ?> รายการต่อหนึ่งงาน</div>
          <?php endif; ?>

          <div class="section-title" style="margin-top:18px">ลิงก์ที่ส่งแล้ว</div>
          <table class="table">
            <thead>
              <tr>
                <th style="width:80px">ลำดับ</th>
                <th>หัวเรื่อง</th>
                <th>ลิงก์</th>
                <th style="width:170px">เวลา</th>
                <th style="width:90px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($links):
              $auto_i = 0;
              foreach($links as $row):
                $seq = (int)($row['seq_no'] ?? 0);
                if ($seq <= 0) { $seq = ++$auto_i; } else { $auto_i = max($auto_i, $seq); }
            ?>
              <tr>
                <td><strong>#<?= (int)$seq ?></strong></td>
                <td>
                  <?= h($row['title'] ?: '-') ?>
                  <?php if(!empty($row['note'])): ?>
                    <div class="muted"><?= h($row['note']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="<?= h($row['url']) ?>" target="_blank" rel="noopener"><?= h($row['url']) ?></a>
                  <div class="muted">โดย: <?= h($row['delivered_by_name'] ?: '-') ?></div>
                </td>
                <td class="muted"><?= h($row['delivered_at']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('ลบลิงก์นี้?');">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete_link">
                    <input type="hidden" name="link_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn danger" type="submit">ลบ</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="muted">ยังไม่มีลิงก์</td></tr>
            <?php endif; ?>
            </tbody>
          </table>

          <div class="section-title" style="margin-top:18px">
            คุยกับลูกค้า <?= $customer? ('— '.h($customer['name'])) : '' ?>
          </div>
          <div class="chat">
            <div class="chat-head">
              <div class="muted">ข้อความล่าสุด 200 รายการ</div>
            </div>
            <div class="chat-body" id="chatBody">
              <div id="chatList">
                <?php if ($messages): foreach($messages as $m): $isMe = ($m['sender']==='photographer'); ?>
                  <div class="bubble <?= $isMe ? 'me':'other' ?>">
                    <div><?= nl2br(h($m['message'])) ?></div>
                    <span class="time"><?= h($m['created_at']) ?> • <?= $isMe ? 'ฉัน' : 'ลูกค้า' ?></span>
                  </div>
                <?php endforeach; else: ?>
                  <div class="muted">ยังไม่มีบทสนทนา เริ่มพิมพ์ด้านล่างเพื่อส่งหาลูกค้า</div>
                <?php endif; ?>
              </div>
            </div>
            <form class="chat-send" method="post" onsubmit="return onSendChat(this);">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="send_msg">
              <input type="hidden" name="job_id" value="<?= (int)$job_id ?>">
              <textarea class="input" name="message" placeholder="พิมพ์ข้อความถึงลูกค้า..." maxlength="4000" required></textarea>
              <button class="btn" type="submit">ส่ง</button>
            </form>
          </div>
        <?php else: ?>
          <div class="muted">โปรดเลือกงานทางฝั่งซ้าย</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// ===== Modal helper =====
(function(){
  const backdrop = document.getElementById('appModal');
  const box      = document.getElementById('appModalBox');
  const icon     = document.getElementById('appModalIcon');
  const title    = document.getElementById('appModalTitle');
  const body     = document.getElementById('appModalText');
  const okBtn    = document.getElementById('appModalOk');

  function setKind(k){
    box.classList.remove('success','error','info');
    if(k==='success'){ box.classList.add('success'); icon.textContent='✅'; title.textContent='สำเร็จ'; }
    else if(k==='error'){ box.classList.add('error'); icon.textContent='⛔'; title.textContent='ไม่สามารถทำรายการ'; }
    else { box.classList.add('info'); icon.textContent='ℹ️'; title.textContent='แจ้งเตือน'; }
  }
  function open(kind, html){
    setKind(kind||'info');
    body.innerHTML = html;
    backdrop.classList.add('show');
    setTimeout(()=> box.classList.add('show'), 10);
    box.focus();
  }
  function close(){
    box.classList.remove('show');
    setTimeout(()=> backdrop.classList.remove('show'), 180);
  }
  okBtn.addEventListener('click', close);
  backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) close(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

  // ส่งออกให้ฟังก์ชันอื่นใช้
  window.showModal = open;

  // เปิดจาก PHP flash (หลัง redirect)
  const initialText = <?= json_encode($popup_text, JSON_UNESCAPED_UNICODE) ?>;
  const initialKind = <?= json_encode($popup_kind, JSON_UNESCAPED_UNICODE) ?>;
  if(initialText){ open(initialKind || 'info', initialText); }
})();

// คัดลอกลิงก์ -> เด้งป๊อปอัพ
function copyShare(){
  const el = document.getElementById('shareLink');
  el.select(); el.setSelectionRange(0, 99999);
  const ok = document.execCommand('copy');
  if(!ok && navigator.clipboard){ navigator.clipboard.writeText(el.value); }
  showModal('success','คัดลอกลิงก์แล้ว');
}

function scrollChatBottom(){
  const body = document.getElementById('chatBody');
  if(body){ body.scrollTop = body.scrollHeight; }
}
function onSendChat(form){
  const btn = form.querySelector('button[type="submit"]');
  btn.disabled = true; setTimeout(()=>btn.disabled=false, 1500);
  return true;
}
document.addEventListener('DOMContentLoaded', () => {
  const f = document.getElementById('formAddLink');
  if (f){
    f.addEventListener('keydown', (e) => {
      const tag = (e.target.tagName || '').toUpperCase();
      if (e.key === 'Enter' && (tag === 'INPUT' || tag === 'TEXTAREA')) {
        e.preventDefault();
        return false;
      }
    });
  }
  scrollChatBottom();
});
</script>
</body>
</html>
<?php $conn->close(); ?>
