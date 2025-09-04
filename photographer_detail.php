<?php
require 'db.php';
if (!isset($_GET['id'])) { die("ไม่พบช่างภาพที่ต้องการดู"); }
$photographer_id = (int)$_GET['id'];
$sql = "SELECT p.*, e.expertise_name FROM photographer p JOIN expertise e ON p.expertise_id = e.expertise_id WHERE p.photographer_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) { die("ไม่พบช่างภาพนี้"); }
$photographer = $result->fetch_assoc();
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
function file_exists_in_uploads($filename) {
    return !empty($filename) && file_exists(__DIR__ . "/uploads/" . $filename);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายละเอียดช่างภาพ <?= htmlspecialchars($photographer['first_name'] . ' ' . $photographer['last_name']) ?> - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.menu a.logout{background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff}
.header-spacer{height:100px}
.container{width:100%;max-width:1100px;padding:0 20px 40px 20px}
.card{background:#fff;border-radius:20px;padding:30px;box-shadow:0 8px 20px rgba(0,0,0,0.15)}
.profile{display:flex;align-items:center;flex-wrap:wrap;gap:25px;margin-bottom:10px}
.profile img{width:180px;height:180px;object-fit:cover;border-radius:50%;border:4px solid #6a11cb;box-shadow:0 4px 12px rgba(0,0,0,0.2)}
.profile-info h2{color:#6a11cb;margin-bottom:8px;font-size:28px}
.profile-info p{margin:6px 0;font-size:15px;color:#444}
.stats{margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.badge{background:#fef3c7;padding:6px 10px;border-radius:10px;font-weight:700}
.badge.muted{background:#eef2ff}
.btn-group{margin-top:15px;display:flex;flex-wrap:wrap;gap:10px}
.btn{background:linear-gradient(to right,#6a11cb,#2575fc);color:#fff;padding:10px 20px;border-radius:10px;text-decoration:none;transition:.3s}
.btn:hover{transform:scale(1.05);opacity:.9}
h3{color:#6a11cb;border-bottom:2px solid #6a11cb;padding-bottom:5px;margin:28px 0 14px}
.gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px}
.gallery a{display:block;height:180px;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.15);background:#fff}
.gallery a img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
.gallery a:hover img{transform:scale(1.05)}
.no-photos{color:#555;font-style:italic;background:#fff;padding:10px 14px;border-radius:10px;display:inline-block}
.review-list{margin-top:6px;display:grid;gap:10px}
.review-item{background:#fafafa;border:1px solid #eee;padding:10px 12px;border-radius:10px}
.review-head{display:flex;gap:8px;align-items:center;font-weight:700;color:#333}
.review-date{color:#777;font-weight:400;font-size:13px;margin-left:auto}
.review-comment{margin-top:6px;color:#333}
.stars{color:#f59e0b}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:2000;flex-direction:column}
.lightbox.active{display:flex}
.lightbox img{max-width:90%;max-height:80vh;border-radius:10px}
.lightbox-controls{margin-top:12px;display:flex;gap:15px}
.lightbox-controls button{background:linear-gradient(to right,#6a11cb,#2575fc);color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:1rem}
@media(max-width:640px){.logo{font-size:18px}.menu a{font-size:13px;padding:7px 9px}.header-spacer{height:92px}.profile img{width:150px;height:150px}}
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
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" class="logout">ออกจากระบบ</a>
  </div>
</div>
<div class="header-spacer"></div>
<div class="container">
  <div class="card">
    <div class="profile">
      <?php 
        $profileImg = (!empty($photographer['profile_image_path']) && file_exists_in_uploads($photographer['profile_image_path'])) ? "uploads/" . htmlspecialchars($photographer['profile_image_path']) : "images/default_user.png";
      ?>
      <img src="<?= $profileImg ?>" alt="รูปโปรไฟล์" onerror="this.onerror=null;this.src='images/default_user.png'">
      <div class="profile-info">
        <h2><?= htmlspecialchars($photographer['first_name'] . ' ' . $photographer['last_name']) ?></h2>
        <div class="stats">
          <span class="badge">⭐ <?= number_format($avg,1) ?>/5</span>
          <span class="badge muted">💬 <?= $cnt ?> รีวิว</span>
        </div>
        <p><strong>อีเมล:</strong> <?= htmlspecialchars($photographer['email']) ?></p>
        <p><strong>เบอร์โทร:</strong> <?= htmlspecialchars($photographer['phone']) ?></p>
        <p><strong>ความถนัด:</strong> <?= htmlspecialchars($photographer['expertise_name']) ?></p>
        <p><strong>ราคาเริ่มต้น:</strong> <?= number_format($photographer['price_rate'], 2) ?> บาท</p>
        <div class="btn-group">
          <a class="btn" href="booking_form.php?photographer_id=<?= $photographer_id ?>">📅 จองช่างภาพนี้</a>
          <a class="btn" href="photographer_schedule.php?photographer_id=<?= $photographer_id ?>">📖 ดูตารางงาน</a>
          <a class="btn" href="photographer_reviews.php?photographer_id=<?= $photographer_id ?>">⭐ ดูรีวิวของช่างภาพนี้</a>
          <a class="btn" href="locations_recommend.php">📍สถานที่แนะนำ</a>
        </div>
      </div>
    </div>
    <?php foreach ($photosByType as $typeName => $photos): ?>
      <h3>📂 ผลงานประเภท <?= htmlspecialchars($typeName) ?></h3>
      <?php if (count($photos) > 0): ?>
        <div class="gallery" data-type="<?= htmlspecialchars($typeName) ?>">
          <?php foreach ($photos as $index => $photo): ?>
            <?php 
              $imgPath = "uploads/" . $photo['image_name'];
              if (!file_exists_in_uploads($photo['image_name'])) { $imgPath = "images/default_user.png"; }
            ?>
            <a href="#" class="gallery-item" data-type="<?= htmlspecialchars($typeName) ?>" data-index="<?= $index ?>" data-src="<?= htmlspecialchars($imgPath) ?>">
              <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($typeName) ?>" loading="lazy" onerror="this.onerror=null;this.src='images/default_user.png'">
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
          <div class="review-item">
            <div class="review-head">
              <div><?= htmlspecialchars(($rv['first_name'] ?? 'ผู้ใช้').' '.($rv['last_name'] ?? '')) ?></div>
              <div class="stars">⭐ <?= (int)$rv['score'] ?>/5</div>
              <div class="review-date"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($rv['created_at'] ?? 'now'))) ?></div>
            </div>
            <?php if (!empty($rv['comment'])): ?>
              <div class="review-comment"><?= nl2br(htmlspecialchars($rv['comment'])) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<div id="lightbox" class="lightbox">
  <img id="lightbox-img" src="" alt="Photo">
  <div class="lightbox-controls">
    <button id="prev-btn">&laquo; ก่อนหน้า</button>
    <button id="next-btn">ถัดไป &raquo;</button>
    <button id="close-btn">ปิด ✕</button>
  </div>
</div>
<script>
(() => {
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  const prevBtn = document.getElementById('prev-btn');
  const nextBtn = document.getElementById('next-btn');
  const closeBtn = document.getElementById('close-btn');
  const galleries = {};
  document.querySelectorAll('.gallery').forEach(gallery => {
    const type = gallery.getAttribute('data-type');
    galleries[type] = [];
    gallery.querySelectorAll('.gallery-item').forEach(item => {
      galleries[type].push(item.getAttribute('data-src'));
    });
  });
  let currentType = null;
  let currentIndex = 0;
  function showLightbox(type, index) {
    currentType = type;
    currentIndex = index;
    lightboxImg.src = galleries[type][index];
    lightbox.classList.add('active');
  }
  function hideLightbox() { lightbox.classList.remove('active'); lightboxImg.src=''; }
  function showNext() { if (!currentType) return; currentIndex = (currentIndex + 1) % galleries[currentType].length; lightboxImg.src = galleries[currentType][currentIndex]; }
  function showPrev() { if (!currentType) return; currentIndex = (currentIndex - 1 + galleries[currentType].length) % galleries[currentType].length; lightboxImg.src = galleries[currentType][currentIndex]; }
  document.querySelectorAll('.gallery-item').forEach(item => {
    item.addEventListener('click', e => {
      e.preventDefault();
      const type = item.getAttribute('data-type');
      const index = parseInt(item.getAttribute('data-index'));
      showLightbox(type, index);
    });
  });
  closeBtn.addEventListener('click', hideLightbox);
  nextBtn.addEventListener('click', showNext);
  prevBtn.addEventListener('click', showPrev);
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') hideLightbox();
    if (e.key === 'ArrowRight') showNext();
    if (e.key === 'ArrowLeft') showPrev();
  });
  lightbox.addEventListener('click', e => { if (e.target === lightbox) hideLightbox(); });
})();
</script>
</body>
</html>
