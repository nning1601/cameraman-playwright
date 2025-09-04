<?php
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_GET['id'])) { die("ไม่พบช่างภาพที่ต้องการดู"); }
$photographer_id = (int)($_GET['id'] ?? 0);
if ($photographer_id <= 0) { die("ไม่พบช่างภาพที่ต้องการดู"); }

$sql = "SELECT p.*, e.expertise_name FROM photographer p JOIN expertise e ON p.expertise_id = e.expertise_id WHERE p.photographer_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) { die("ไม่พบช่างภาพนี้"); }
$photographer = $result->fetch_assoc();
$stmt->close();

$jobTypes = [
    'wedding'     => 'งานแต่ง',
    'pre-wedding' => 'พรีเวดดิ้ง',
    'portrait'    => 'แฟชั่น',
    'product'     => 'รีวิวสินค้า',
    'event'       => 'อีเวนต์'
];

$photosByType = [];
foreach ($jobTypes as $typeCode => $typeName) {
    $sql = "SELECT image_name FROM photo WHERE photographer_id = ? AND image_type = ? LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $photographer_id, $typeCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $photosByType[$typeName] = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT AVG(score) AS avg_rating, COUNT(*) AS total_reviews FROM photographerrating WHERE photographer_id = ?");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();
$avg = (float)($summary['avg_rating'] ?? 0);
$cnt = (int)($summary['total_reviews'] ?? 0);

$stmt = $conn->prepare("SELECT r.score, r.comment, r.created_at, u.first_name, u.last_name FROM photographerrating r LEFT JOIN users u ON r.user_id = u.user_id WHERE r.photographer_id = ? ORDER BY r.created_at DESC LIMIT 3");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$latestReviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function file_exists_in_uploads($filename) { return !empty($filename) && file_exists(__DIR__ . "/uploads/" . $filename); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายละเอียดช่างภาพ <?= h(($photographer['first_name'] ?? '').' '.($photographer['last_name'] ?? '')) ?> - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff}
.nav-links{list-style:none;display:flex;gap:14px;align-items:center}
.nav-links>li{position:relative}
.nav-links a{text-decoration:none;font-size:14px;color:#fff;padding:8px 10px;border-radius:10px;transition:.18s}
.nav-links a:hover{background:#22d3ee;color:#0f172a}
.dropdown-menu{position:absolute;top:calc(100% + 8px);left:0;min-width:180px;padding:8px;background:#fff;border-radius:12px;box-shadow:0 12px 28px rgba(2,6,23,.18);display:none}
.dropdown-menu a{display:block;color:#0f172a;padding:10px 12px;border-radius:8px}
.dropdown-menu a:hover{background:#eef2ff}
.dropdown:hover .dropdown-menu{display:block}
.hamburger{width:28px;height:22px;display:none;flex-direction:column;justify-content:space-between;cursor:pointer}
.hamburger span{display:block;height:3px;background:#fff;border-radius:3px}
.header-spacer{height:96px}
.container{width:100%;max-width:1100px;padding:0 20px 40px}
.card{background:#fff;border-radius:20px;padding:26px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06)}
.profile{display:flex;align-items:center;flex-wrap:wrap;gap:22px;margin-bottom:6px}
.profile img{width:180px;height:180px;object-fit:cover;border-radius:50%;border:4px solid #7c3aed;box-shadow:0 6px 18px rgba(2,6,23,.25)}
.profile-info h2{color:#0f172a;margin-bottom:8px;font-size:28px}
.profile-info p{margin:6px 0;font-size:15px;color:#334155}
.stats{margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.badge{background:#fff7ed;border:1px solid #fed7aa;padding:6px 10px;border-radius:10px;font-weight:700}
.badge.muted{background:#eef2ff;border-color:#c7d2fe}
.btn-group{margin-top:14px;display:flex;flex-wrap:wrap;gap:10px}
.btn{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;transition:.18s;display:inline-block}
.btn:hover{opacity:.95;transform:translateY(-1px)}
h3{color:#0f172a;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin:24px 0 12px}
.gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.gallery a{display:block;height:180px;border-radius:12px;overflow:hidden;box-shadow:0 8px 18px rgba(2,6,23,.18);background:#0b1220}
.gallery a img{width:100%;height:100%;object-fit:cover;transition:transform .25s}
.gallery a:hover img{transform:scale(1.05)}
.no-photos{color:#475569;background:#fff;border:1px solid #e2e8f0;padding:10px 14px;border-radius:10px;display:inline-block}
.review-list{margin-top:6px;display:grid;gap:10px}
.review-item{background:#fafafa;border:1px solid #e5e7eb;padding:10px 12px;border-radius:10px}
.review-head{display:flex;gap:8px;align-items:center;font-weight:700;color:#0f172a}
.review-date{color:#64748b;font-weight:400;font-size:13px;margin-left:auto}
.review-comment{margin-top:6px;color:#334155}
.stars{color:#f59e0b}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.9);display:none;align-items:center;justify-content:center;z-index:2000;flex-direction:column}
.lightbox.active{display:flex}
.lightbox img{max-width:92%;max-height:80vh;border-radius:10px}
.lightbox-controls{margin-top:12px;display:flex;gap:12px}
.lightbox-controls button{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:15px}
@media (max-width:900px){
  .hamburger{display:flex}
  .nav-links{position:fixed;right:0;top:64px;bottom:0;gap:6px;padding:14px;flex-direction:column;background:#0f172a;width:240px;transform:translateX(110%);transition:transform .25s}
  .nav-links.active{transform:translateX(0)}
  .dropdown:hover .dropdown-menu{display:none}
  .dropdown-menu{position:static;background:#111827}
  .dropdown-menu a{color:#e5e7eb}
}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown">
      <a href="javascript:void(0)">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="javascript:void(0)">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" onclick="toggleMenu()"><span></span><span></span><span></span></div>
</div>

<div class="header-spacer"></div>

<div class="container">
  <div class="card">
    <div class="profile">
      <?php 
        $profileImg = (!empty($photographer['profile_image_path']) && file_exists_in_uploads($photographer['profile_image_path'])) ? "uploads/" . h($photographer['profile_image_path']) : "images/default_user.png";
      ?>
      <img src="<?= $profileImg ?>" alt="รูปโปรไฟล์" onerror="this.onerror=null;this.src='images/default_user.png'">
      <div class="profile-info">
        <h2><?= h(($photographer['first_name'] ?? '').' '.($photographer['last_name'] ?? '')) ?></h2>
        <div class="stats">
          <span class="badge">⭐ <?= number_format($avg,1) ?>/5</span>
          <span class="badge muted">💬 <?= $cnt ?> รีวิว</span>
        </div>
        <p><strong>อีเมล:</strong> <?= h($photographer['email'] ?? '-') ?></p>
        <p><strong>เบอร์โทร:</strong> <?= h($photographer['phone'] ?? '-') ?></p>
        <p><strong>ความถนัด:</strong> <?= h($photographer['expertise_name'] ?? '-') ?></p>
        <p><strong>ราคาเริ่มต้น:</strong> <?= isset($photographer['price_rate']) ? number_format((float)$photographer['price_rate'], 2) : '-' ?> บาท</p>
        <div class="btn-group">
          <a class="btn" href="login_user.php?photographer_id=<?= $photographer_id ?>">📅 จองช่างภาพนี้</a>
          <a class="btn" href="photographer2_schedule.php?photographer_id=<?= $photographer_id ?>">📖 ดูตารางงาน</a>
          <a class="btn" href="photographer2_reviews.php?photographer_id=<?= $photographer_id ?>">⭐ ดูรีวิวของช่างภาพนี้</a>
          <a class="btn" href="locations_recommend2.php">📍สถานที่แนะนำ</a>
        </div>
      </div>
    </div>

    <?php foreach ($photosByType as $typeName => $photos): ?>
      <h3>📂 ผลงานประเภท <?= h($typeName) ?></h3>
      <?php if (count($photos) > 0): ?>
        <div class="gallery" data-type="<?= h($typeName) ?>">
          <?php foreach ($photos as $index => $photo): ?>
            <?php 
              $imgPath = file_exists_in_uploads($photo['image_name'] ?? '') ? ("uploads/" . $photo['image_name']) : "images/default_user.png";
            ?>
            <a href="#" class="gallery-item" data-type="<?= h($typeName) ?>" data-index="<?= (int)$index ?>" data-src="<?= h($imgPath) ?>">
              <img src="<?= h($imgPath) ?>" alt="<?= h($typeName) ?>" loading="lazy" onerror="this.onerror=null;this.src='images/default_user.png'">
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="no-photos">ไม่มีผลงานประเภทนี้</p>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!empty($latestReviews)): ?>
      <h3>📝 รีวิวล่าสุด</h3>
      <div class="review-list">
        <?php foreach($latestReviews as $rv): ?>
          <?php
            $name = trim(($rv['first_name'] ?? 'ผู้ใช้').' '.($rv['last_name'] ?? ''));
            $dateStr = !empty($rv['created_at']) ? date('d/m/Y H:i', strtotime($rv['created_at'])) : '–';
          ?>
          <div class="review-item">
            <div class="review-head">
              <div><?= h($name) ?></div>
              <div class="stars">⭐ <?= (int)($rv['score'] ?? 0) ?>/5</div>
              <div class="review-date"><?= h($dateStr) ?></div>
            </div>
            <?php if (!empty($rv['comment'])): ?>
              <div class="review-comment"><?= nl2br(h($rv['comment'])) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<div id="lightbox" class="lightbox" aria-hidden="true">
  <img id="lightbox-img" src="" alt="Photo">
  <div class="lightbox-controls">
    <button id="prev-btn" type="button">« ก่อนหน้า</button>
    <button id="next-btn" type="button">ถัดไป »</button>
    <button id="close-btn" type="button">ปิด ✕</button>
  </div>
</div>

<script>
function toggleMenu(){document.getElementById('navLinks').classList.toggle('active')}
document.querySelectorAll('#navLinks a').forEach(a=>{a.addEventListener('click',()=>{const n=document.getElementById('navLinks');if(n.classList.contains('active'))n.classList.remove('active')})});
(()=>{const l=document.getElementById('lightbox');const i=document.getElementById('lightbox-img');const p=document.getElementById('prev-btn');const n=document.getElementById('next-btn');const c=document.getElementById('close-btn');const g={};document.querySelectorAll('.gallery').forEach(G=>{const t=G.getAttribute('data-type');g[t]=[];G.querySelectorAll('.gallery-item').forEach(it=>{g[t].push(it.getAttribute('data-src'))})});let T=null;let k=0;function show(t,x){if(!g[t]||!g[t].length)return;T=t;k=x;i.src=g[t][x];l.classList.add('active');l.setAttribute('aria-hidden','false')}function hide(){l.classList.remove('active');i.src='';l.setAttribute('aria-hidden','true')}function next(){if(!T||!g[T].length)return;k=(k+1)%g[T].length;i.src=g[T][k]}function prev(){if(!T||!g[T].length)return;k=(k-1+g[T].length)%g[T].length;i.src=g[T][k]}document.querySelectorAll('.gallery-item').forEach(it=>{it.addEventListener('click',e=>{e.preventDefault();const t=it.getAttribute('data-type');const x=parseInt(it.getAttribute('data-index'))||0;show(t,x)})});c.addEventListener('click',hide);n.addEventListener('click',next);p.addEventListener('click',prev);window.addEventListener('keydown',e=>{if(e.key==='Escape')hide();if(e.key==='ArrowRight')next();if(e.key==='ArrowLeft')prev()});l.addEventListener('click',e=>{if(e.target===l)hide()})})();
</script>
</body>
</html>
