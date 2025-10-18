<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit();
}
$photographer_id = (int)$_SESSION['photographer_id'];
mysqli_set_charset($conn, 'utf8mb4');

$stmt = $conn->prepare("
    SELECT p.first_name, p.last_name, p.phone, p.email, p.profile_image_path, p.price_rate, e.expertise_name
    FROM photographer p
    LEFT JOIN expertise e ON p.expertise_id = e.expertise_id
    WHERE p.photographer_id = ?
");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows !== 1) {
    echo "ไม่พบข้อมูลช่างภาพ";
    exit();
}
$photographer = $result->fetch_assoc();
$firstName = $photographer['first_name'] ?? '';
$lastName  = $photographer['last_name'] ?? '';
$stmt->close();

$categories = ['wedding', 'pre-wedding', 'portrait', 'product', 'event'];
$photos_by_category = [];
$stmt_photo = $conn->prepare("
    SELECT photo_id, image_name, image_type 
    FROM photo 
    WHERE photographer_id = ? AND image_type = ? 
    ORDER BY photo_id DESC 
    LIMIT 10
");
foreach ($categories as $cat) {
    $stmt_photo->bind_param("is", $photographer_id, $cat);
    $stmt_photo->execute();
    $result_photo = $stmt_photo->get_result();
    $photos_by_category[$cat] = $result_photo ? $result_photo->fetch_all(MYSQLI_ASSOC) : [];
}
$stmt_photo->close();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function file_exists_in_uploads($filename) {
    return !empty($filename) && is_file(__DIR__ . "/uploads/" . $filename);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แดชบอร์ดช่างภาพ - Cameraman</title>
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
  width:100%;max-width:1100px;display:flex;flex-direction:column;align-items:center;gap:28px;text-align:center
}
.page-title h1{margin:0;font-size:32px;font-weight:800;color:#0b1220}
.card{
  width:100%;background:#ffffff;border-radius:20px;box-shadow:0 12px 28px rgba(17,24,39,.18);padding:22px;border:1px solid #e5e7eb
}
.profile-card{width:100%;display:flex;flex-direction:column;align-items:center;gap:16px}
.profile-top{display:flex;flex-direction:column;align-items:center;gap:14px}
.profile-card img{
  width:160px;height:160px;object-fit:cover;border-radius:16px;border:4px solid #ffffff;box-shadow:0 10px 28px rgba(0,0,0,.25);background:#f3f4f6
}
.name{font-size:22px;font-weight:800;color:#0b1220}
.badges{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
.badge{
  padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;
  background:linear-gradient(90deg,#22d3ee,#3b82f6);color:#00111a;border:none
}
.info-grid{width:100%;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:6px}
.info-item{background:#0b1220;color:#f8fafc;border:1px solid #111827;border-radius:14px;padding:12px}
.info-item .label{font-size:12px;color:#cbd5e1}
.info-item .value{margin-top:6px;font-weight:700;color:#ffffff}
.section h2{margin:0 0 10px 0;font-size:20px;color:#0b1220;font-weight:900}
.photos-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;justify-items:center}
.photo-card{width:100%;max-width:220px;background:#0b1220;border-radius:16px;overflow:hidden;border:1px solid #111827;box-shadow:0 10px 24px rgba(17,24,39,.35);cursor:pointer;transition:transform .2s,box-shadow .2s}
.photo-card:hover{transform:translateY(-4px);box-shadow:0 18px 36px rgba(17,24,39,.45)}
.photo-card img{width:100%;height:140px;object-fit:cover;display:block;filter:saturate(1.05) contrast(1.05)}
.photo-desc{padding:10px;font-size:13px;color:#e5e7eb}
#lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);display:none;align-items:center;justify-content:center;z-index:9999;flex-direction:column;padding:20px}
#lightbox.active{display:flex}
#lightbox img{max-width:92vw;max-height:78vh;border-radius:14px;box-shadow:0 0 32px rgba(255,255,255,.45);background:#111}
#lightbox-controls{margin-top:16px;display:flex;gap:14px;flex-wrap:wrap;justify-content:center}
#lightbox-controls button{
  background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-weight:900;transition:.25s ease;min-width:110px
}
#lightbox-controls button:hover{filter:brightness(1.05)}
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .profile-card img{width:140px;height:140px}
  .photo-card img{height:120px}
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
      <a href="photographer_dashboard.php" class="active">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="photographer_bookings_crud.php">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">การมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">
    <div class="page-title">
      <h1>แดชบอร์ดช่างภาพ</h1>
    </div>

    <div class="profile-card card">
      <?php 
        $profileImg = (file_exists_in_uploads($photographer['profile_image_path'] ?? ''))
          ? "uploads/".h($photographer['profile_image_path'])
          : (is_file(__DIR__.'/uploads/default-profile.png') ? "uploads/default-profile.png" : "images/default_user.png");
      ?>
      <div class="profile-top">
        <img src="<?= $profileImg ?>" alt="รูปโปรไฟล์">
        <div class="name"><?= h(($photographer['first_name'] ?? '').' '.($photographer['last_name'] ?? '')) ?></div>
        <div class="badges">
          <span class="badge"><?= !empty($photographer['expertise_name']) ? h($photographer['expertise_name']) : 'ยังไม่ระบุความเชี่ยวชาญ' ?></span>
          <span class="badge"><?= isset($photographer['price_rate']) ? number_format((float)$photographer['price_rate'], 2) : '0.00' ?> บาท/วัน</span>
        </div>
      </div>
      <div class="info-grid">
        <div class="info-item">
          <div class="label">เบอร์โทร</div>
          <div class="value"><?= h($photographer['phone'] ?? '-') ?></div>
        </div>
        <div class="info-item">
          <div class="label">อีเมล</div>
          <div class="value"><?= h($photographer['email'] ?? '-') ?></div>
        </div>
      </div>
    </div>

    <?php foreach ($categories as $category): ?>
      <section class="section card">
        <h2><?= h(ucfirst(str_replace('-', ' ', $category))) ?></h2>
        <?php if(!empty($photos_by_category[$category])): ?>
          <div class="photos-grid" data-category="<?= h($category) ?>">
            <?php foreach($photos_by_category[$category] as $index=>$photo):
              $imgPath = "uploads/".h($photo['image_name']);
            ?>
              <div class="photo-card" tabindex="0" data-category="<?= h($category) ?>" data-index="<?= (int)$index ?>" data-src="<?= $imgPath ?>">
                <img src="<?= $imgPath ?>" alt="<?= h($photo['image_type']) ?>">
                <div class="photo-desc"><?= h($photo['image_type']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p style="color:#334155;margin:0;font-weight:600">ยังไม่มีรูปภาพในหมวดนี้</p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

  </div>
</div>

<div id="lightbox" aria-hidden="true">
  <img id="lightbox-img" src="" alt="รูปภาพขยาย">
  <div id="lightbox-controls">
    <button id="prev-btn" type="button" aria-label="ก่อนหน้า">ก่อนหน้า</button>
    <button id="next-btn" type="button" aria-label="ถัดไป">ถัดไป</button>
    <button id="close-btn" type="button" aria-label="ปิด">ปิด</button>
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
  document.querySelectorAll('.photos-grid').forEach(grid=>{
      const category = grid.getAttribute('data-category');
      galleries[category] = [];
      grid.querySelectorAll('.photo-card').forEach(card=>{
          galleries[category].push(card.getAttribute('data-src'));
      });
  });
  let currentCategory = null;
  let currentIndex = 0;
  function showLightbox(category,index){
      currentCategory=category;
      currentIndex=index;
      lightboxImg.src=galleries[category][index];
      lightbox.classList.add('active');
      document.body.style.overflow='hidden';
      lightbox.setAttribute('aria-hidden','false');
      lightboxImg.focus();
  }
  function hideLightbox(){
      lightbox.classList.remove('active');
      lightboxImg.src='';
      document.body.style.overflow='';
      lightbox.setAttribute('aria-hidden','true');
  }
  function showNext(){
      if(!galleries[currentCategory] || galleries[currentCategory].length===0) return;
      currentIndex=(currentIndex+1)%galleries[currentCategory].length;
      lightboxImg.src=galleries[currentCategory][currentIndex];
  }
  function showPrev(){
      if(!galleries[currentCategory] || galleries[currentCategory].length===0) return;
      currentIndex=(currentIndex-1+galleries[currentCategory].length)%galleries[currentCategory].length;
      lightboxImg.src=galleries[currentCategory][currentIndex];
  }
  document.querySelectorAll('.photo-card').forEach(card=>{
      card.addEventListener('click',()=>{ showLightbox(card.dataset.category,parseInt(card.dataset.index)); });
      card.addEventListener('keydown',e=>{
          if(e.key==='Enter'||e.key===' '){
              e.preventDefault();
              showLightbox(card.dataset.category,parseInt(card.dataset.index));
          }
      });
  });
  closeBtn.addEventListener('click',hideLightbox);
  nextBtn.addEventListener('click',showNext);
  prevBtn.addEventListener('click',showPrev);
  window.addEventListener('keydown',e=>{
      if(!lightbox.classList.contains('active')) return;
      if(e.key==='Escape') hideLightbox();
      else if(e.key==='ArrowRight') showNext();
      else if(e.key==='ArrowLeft') showPrev();
  });
  lightbox.addEventListener('click',e=>{ if(e.target===lightbox) hideLightbox(); });
})();
</script>

</body>
</html>
<?php $conn->close(); ?>
