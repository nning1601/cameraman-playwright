<?php
@require_once 'db.php';
if (!isset($conn) || !$conn) {
    $conn = @mysqli_connect('localhost', 'root', '', 'cameraman');
    if (!$conn) { die('เชื่อมต่อฐานข้อมูลล้มเหลว: ' . mysqli_connect_error()); }
}
mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c, string $table, string $col): bool {
    $q = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    if(!$q){ return false; }
    $q->bind_param('ss',$table,$col); $q->execute();
    $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
function img_src($raw, $fallback='images/default_user.png'){
  global $BASE_URL;
  $p = trim(str_replace('\\','/',$raw ?: $fallback));
  if (preg_match('#^https?://#i', $p)) return $p;
  if (!preg_match('#^(uploads|images)/#i', $p)) $p = 'uploads/' . ltrim($p,'/');
  $abs = __DIR__ . '/' . ltrim($p,'/');
  if (!is_file($abs)) { $p = $fallback; }
  return $BASE_URL . ltrim($p,'/');
}

$limit = 24;
$photos = [];

if (has_col($conn,'photo','photo_id')) {
    $idStmt = $conn->prepare("SELECT ph.photo_id FROM photo ph WHERE ph.image_name IS NOT NULL AND ph.image_name<>'' ORDER BY RAND() LIMIT ?");
    $idStmt->bind_param("i", $limit);
    $idStmt->execute();
    $idRes = $idStmt->get_result();
    $ids = [];
    while ($r = $idRes->fetch_assoc()) { $ids[] = (int)$r['photo_id']; }
    $idStmt->close();

    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT ph.image_name, ph.image_type, ph.photographer_id,
                       CONCAT(TRIM(COALESCE(p.first_name,'')),' ',TRIM(COALESCE(p.last_name,''))) AS photographer_name
                FROM photo ph
                LEFT JOIN photographer p ON ph.photographer_id = p.photographer_id
                WHERE ph.photo_id IN ($in)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $sql = "SELECT ph.image_name, ph.image_type, ph.photographer_id,
                   CONCAT(TRIM(COALESCE(p.first_name,'')),' ',TRIM(COALESCE(p.last_name,''))) AS photographer_name
            FROM photo ph
            LEFT JOIN photographer p ON ph.photographer_id = p.photographer_id
            WHERE ph.image_name IS NOT NULL AND ph.image_name<>''
            ORDER BY RAND()
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $photos = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>หน้าแรก - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="ค้นหาและจ้างช่างภาพมืออาชีพในสุรินทร์และทั่วไทย — ดูผลงานจริงและติดต่อได้ทันที">
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; connect-src 'self'">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff;letter-spacing:.2px}
.nav-links{list-style:none;display:flex;gap:18px}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:15px;color:#fff;padding:10px 14px;border-radius:10px;transition:transform .15s ease,background .15s ease}
.nav-links a:hover{background:#22d3ee;color:#0f172a;transform:translateY(-1px)}
.dropdown-menu{position:absolute;top:100%;left:0;background:#ffffff;border:1px solid rgba(2,6,23,.08);border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);min-width:180px;display:none;flex-direction:column;overflow:hidden}
.dropdown-menu a{display:block;color:#0f172a;padding:12px 14px;border-radius:0}
.dropdown-menu a:hover{background:#0ea5e9;color:#fff}
.dropdown:hover .dropdown-menu{display:flex}
.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer}
.hamburger span{width:26px;height:3px;background:#fff;border-radius:2px}
@media (max-width:768px){
  .nav-links{position:fixed;top:58px;right:-100%;background:#0f172a;width:68vw;max-width:320px;height:calc(100vh - 58px);padding:18px;flex-direction:column;gap:8px;border-left:1px solid rgba(255,255,255,.08);transition:right .25s ease}
  .nav-links.show{right:0}
  .dropdown-menu{position:static;border:none;box-shadow:none;background:transparent}
  .dropdown-menu a{color:#e2e8f0;padding:10px 12px}
  .dropdown-menu a:hover{background:#22d3ee;color:#0f172a}
  .hamburger{display:flex}
}
.welcome{text-align:center;margin-top:120px;color:#0f172a;padding:0 16px}
.welcome h1{font-size:40px;margin-bottom:10px;font-weight:700;text-shadow:0 1px 0 rgba(255,255,255,.4)}
.welcome p{font-size:20px;font-weight:500;opacity:.9}
.cta-buttons{text-align:center;margin-top:36px}
.cta-buttons a{display:inline-block;margin:10px 12px;padding:12px 24px;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border-radius:12px;text-decoration:none;font-weight:700;box-shadow:0 10px 20px rgba(2,6,23,.25);transition:transform .15s ease,opacity .15s ease}
.cta-buttons a:hover{opacity:.95;transform:translateY(-1px)}
.card{width:min(1200px,96%);background:#ffffff;border-radius:20px;padding:20px;margin:36px auto 28px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06)}
.card h2{color:#0f172a;margin:2px 0 12px 6px;font-size:22px}
.carousel{overflow:hidden;position:relative;border-radius:16px}
.track{display:flex;gap:16px;padding:10px;animation:scroll 45s linear infinite}
.carousel:hover .track{animation-play-state:paused}
@keyframes scroll{from{transform:translateX(0)}to{transform:translateX(-100%)}}
.slide{flex:0 0 auto;width:clamp(160px,28vw,280px);aspect-ratio:3/2;border-radius:14px;overflow:hidden;box-shadow:0 10px 24px rgba(2,6,23,.2);position:relative;background:#0b1220}
.slide img{width:100%;height:100%;object-fit:cover;display:block}
.caption{position:absolute;left:0;right:0;bottom:0;background:linear-gradient(180deg,transparent,rgba(0,0,0,.75));color:#fff;font-size:14px;padding:10px 12px}
.caption b{font-weight:700}
@media (max-width:640px){.slide{width:92vw;height:58vw}}
</style>
</head>
<body>
<nav class="navbar" role="navigation" aria-label="หลัก">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown">
      <a href="#" aria-haspopup="true" aria-expanded="false">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu" role="menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="#" aria-haspopup="true" aria-expanded="false">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu" role="menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" id="burger" aria-label="สลับเมนู" role="button" tabindex="0"><span></span><span></span><span></span></div>
</nav>

<div class="welcome">
  <h1>ยินดีต้อนรับสู่ Cameraman</h1>
  <p>แพลตฟอร์มสำหรับค้นหาและจ้างช่างภาพมืออาชีพ</p>
</div>

<div class="cta-buttons">
  <a href="view1_photographers.php">ดูรายชื่อช่างภาพ</a>
  <a href="about.php">เกี่ยวกับเรา</a>
</div>

<div class="card" aria-label="ผลงานจากช่างภาพ">
  <h2>ผลงานจากช่างภาพของเรา</h2>
  <div class="carousel">
    <div class="track">
      <?php if (!empty($photos)): ?>
        <?php $loops=2; for($r=0;$r<$loops;$r++): foreach($photos as $ph): $name=trim($ph['photographer_name'] ?? '') ?: 'ช่างภาพ'; $type=$ph['image_type']??''; $img=img_src($ph['image_name']); $alt=trim(($type?$type.' - ':'').$name); $link='photographer_detail.php?id='.(int)($ph['photographer_id']??0); ?>
          <div class="slide">
            <a href="<?= h($link) ?>" style="display:block;width:100%;height:100%;position:relative">
              <img src="<?= h($img) ?>" alt="<?= h($alt) ?>" loading="lazy" decoding="async" fetchpriority="low" onerror="this.onerror=null;this.src='<?= h($BASE_URL) ?>images/default_user.png'">
              <div class="caption"><b><?= h($name) ?></b><?php if($type): ?> · <?= h($type) ?><?php endif; ?></div>
            </a>
          </div>
        <?php endforeach; endfor; ?>
      <?php else: ?>
        <?php for($i=0;$i<8;$i++): ?>
          <div class="slide">
            <img src="<?= h($BASE_URL) ?>images/default_user.png" alt="ไม่มีรูป" loading="lazy" decoding="async" fetchpriority="low">
            <div class="caption"><b>ยังไม่มีผลงาน</b> · อัปโหลดเร็ว ๆ นี้</div>
          </div>
        <?php endfor; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const nav = document.getElementById('navLinks');
const burger = document.getElementById('burger');
burger.addEventListener('click', ()=> nav.classList.toggle('show'));
burger.addEventListener('keyup', (e)=>{ if(e.key==='Enter' || e.key===' '){ nav.classList.toggle('show'); }});
</script>
</body>
</html>
