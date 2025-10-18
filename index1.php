<?php
@include_once 'db.php';
if (!isset($conn) || !$conn) {
    $conn = mysqli_connect('localhost', 'root', '', 'cameraman');
    if (!$conn) { die('เชื่อมต่อฐานข้อมูลล้มเหลว: ' . mysqli_connect_error()); }
}
mysqli_set_charset($conn, 'utf8mb4');
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
$limit = 24;
$sql = "
  SELECT ph.image_name, ph.image_type, ph.photographer_id,
         CONCAT(p.first_name, ' ', p.last_name) AS photographer_name
  FROM photo ph
  LEFT JOIN photographer p ON ph.photographer_id = p.photographer_id
  WHERE ph.image_name IS NOT NULL AND ph.image_name <> ''
  ORDER BY RAND()
  LIMIT ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $limit);
$stmt->execute();
$res = $stmt->get_result();
$photos = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>หน้าแรก - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:100px}
.welcome{text-align:center;color:#0f172a;margin-top:10px}
.welcome h1{font-size:42px;margin-bottom:12px;font-weight:800}
.welcome p{font-size:20px;font-weight:600;color:#1f2937}
.cta-buttons{text-align:center;margin-top:26px}
.cta-buttons a{display:inline-block;margin:8px 10px;padding:12px 26px;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border-radius:10px;text-decoration:none;font-weight:800;box-shadow:0 6px 18px rgba(2,6,23,.18);transition:.18s}
.cta-buttons a:hover{opacity:.95;transform:translateY(-1px)}
.card{width:min(1440px,98%);background:#fff;border-radius:20px;padding:24px;margin:28px auto 40px;box-shadow:0 12px 32px rgba(2,6,23,.12)}
.card h2{color:#0f172a;margin:0 0 14px 6px;font-size:22px}
.carousel{overflow:hidden;position:relative;border-radius:16px}
.track{display:flex;gap:20px;padding:10px;animation:scroll 50s linear infinite}
.carousel:hover .track{animation-play-state:paused}
@keyframes scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.slide{flex:0 0 auto;width:320px;height:260px;border-radius:14px;overflow:hidden;box-shadow:0 6px 16px rgba(0,0,0,.14);position:relative;background:#f3f4f6}
.slide img{width:100%;height:100%;object-fit:cover;display:block}
.caption{position:absolute;left:0;right:0;bottom:0;background:linear-gradient(180deg,transparent,rgba(0,0,0,.65));color:#fff;font-size:15px;padding:12px 14px}
.caption b{font-weight:800}
@media(max-width:640px){
  .welcome h1{font-size:34px}
  .welcome p{font-size:18px}
  .slide{width:96vw;height:60vw}
  .header-spacer{height:100px}
}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php" class="active" aria-current="page">หน้าแรก</a>
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
<div class="welcome">
  <h1>ยินดีต้อนรับ</h1>
  <p>แพลตฟอร์มสำหรับค้นหาและจ้างช่างภาพมืออาชีพ</p>
</div>
<div class="cta-buttons">
  <a href="view_photographers.php">ดูรายชื่อช่างภาพ</a>
  <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
</div>
<div class="card" aria-label="ผลงานจากช่างภาพ">
  <h2>ผลงานจากช่างภาพของเรา</h2>
  <div class="carousel">
    <div class="track">
      <?php if (!empty($photos)): ?>
        <?php for ($r=0; $r<2; $r++): foreach ($photos as $ph):
          $name  = $ph['photographer_name'] ?? 'ช่างภาพ';
          $type  = $ph['image_type'] ?? '';
          $img   = img_src($ph['image_name']);
          $alt   = trim(($type ? $type.' - ' : '').$name);
          $pid   = (int)($ph['photographer_id'] ?? 0);
          $href  = $pid ? ('photographer2_detail.php?id='.$pid) : '#';
        ?>
          <a class="slide" href="<?= h($href) ?>" title="<?= h($name) ?>">
            <img src="<?= h($img) ?>" alt="<?= h($alt) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($BASE_URL) ?>images/default_user.png'">
            <div class="caption"><b><?= h($name) ?></b><?php if($type): ?> · <?= h($type) ?><?php endif; ?></div>
          </a>
        <?php endforeach; endfor; ?>
      <?php else: ?>
        <?php for ($i=0; $i<8; $i++): ?>
          <div class="slide">
            <img src="<?= h($BASE_URL) ?>images/default_user.png" alt="ยังไม่มีผลงาน" loading="lazy">
            <div class="caption"><b>ยังไม่มีผลงาน</b> · อัปโหลดเร็ว ๆ นี้</div>
          </div>
        <?php endfor; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
