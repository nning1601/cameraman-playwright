<?php
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');
@$conn->query("SET SESSION group_concat_max_len = 100000");
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_price = isset($_GET['sort_price']) ? $_GET['sort_price'] : '';
$expertise_id = isset($_GET['expertise_id']) ? $_GET['expertise_id'] : '';
if (!in_array($sort_price, ['asc','desc',''], true)) { $sort_price = ''; }
function norm_img($p, $fallback = 'images/default_user.png'){
  $p = (string)$p;
  if ($p === '' || $p === null) return $fallback;
  $p = str_replace('\\','/',$p);
  if (preg_match('#^https?://#i', $p)) return $p;
  if (!preg_match('#^(uploads|images)/#', $p)) $p = 'uploads/'.$p;
  return $p;
}
$sql = "
SELECT
  p.photographer_id,
  p.first_name, p.last_name, p.phone, p.email,
  p.profile_image, p.profile_image_path, p.price_rate,
  e.expertise_name,
  (
    SELECT GROUP_CONCAT(x.image_name ORDER BY x.photo_id DESC SEPARATOR '|')
    FROM (
      SELECT ph.image_name, ph.photo_id
      FROM photo ph
      WHERE ph.photographer_id = p.photographer_id
      ORDER BY ph.photo_id DESC
      LIMIT 5
    ) AS x
  ) AS sample_photos
FROM photographer p
JOIN expertise e ON p.expertise_id = e.expertise_id
WHERE 1
";
if ($search !== '') {
  $s = $conn->real_escape_string($search);
  $sql .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%')";
}
if ($expertise_id !== '') {
  $eid = $conn->real_escape_string($expertise_id);
  $sql .= " AND p.expertise_id = '$eid'";
}
if ($sort_price === 'asc') {
  $sql .= " ORDER BY p.price_rate ASC";
} elseif ($sort_price === 'desc') {
  $sql .= " ORDER BY p.price_rate DESC";
}
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ดูรายชื่อช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
html,body{height:100%}
body{min-height:100vh;height:100vh;overflow:hidden;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.page{width:100%;max-width:1000px;display:flex;flex-direction:column;padding:0 20px}
.header-spacer{height:100px;flex:0 0 auto}
.header-area{flex:0 0 auto}
h1{text-align:center;font-size:26px;color:#0f172a;margin-bottom:14px;font-weight:800}
.filter-form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;justify-content:center}
.filter-form input,.filter-form select,.filter-form button{padding:9px;border-radius:8px;border:1px solid #ccc;font-weight:600;font-size:14px}
.filter-form input{flex:2;min-width:200px}
.filter-form select,.filter-form button{flex:1;min-width:140px;cursor:pointer}
.filter-form button{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border:none;transition:.18s}
.filter-form button:hover{opacity:.95;transform:translateY(-1px)}
.scroll-area{flex:1 1 auto;min-height:0;overflow:auto;padding-bottom:16px}
.photographer-card{background:#fff;border-radius:15px;box-shadow:0 10px 24px rgba(2,6,23,.08);padding:20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:inherit;transition:.18s;gap:16px}
.photographer-card:hover{transform:translateY(-2px);box-shadow:0 16px 32px rgba(2,6,23,.12);background:#f8fafc}
.left{display:flex;align-items:center;gap:18px;min-width:0;flex:1}
.left img.profile{width:110px;height:110px;object-fit:cover;border-radius:12px;border:2px solid #e2e8f0;flex:0 0 auto}
.info{flex:1;min-width:0}
.info h3{margin:0 0 8px 0;font-size:21px;color:#0f172a;font-weight:800}
.info p{margin:4px 0;color:#334155;font-weight:600;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.preview.slider{position:relative;width:260px;height:140px;border-radius:12px;overflow:hidden;border:2px solid #e5e7eb}
.preview.slider img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity 1s ease}
.preview.slider img.active{opacity:1}
.no-data{text-align:center;color:#64748b;font-size:16px;margin-top:16px}
.page,.scroll-area{overflow-x:hidden}
@media(max-width:640px){
  .navbar{padding:10px 16px}
  .menu{gap:8px}
  .menu a{font-size:13px;padding:6px 8px}
  .header-spacer{height:100px}
  h1{font-size:22px}
  .photographer-card{flex-direction:column;align-items:stretch}
  .left{gap:12px}
  .left img.profile{width:96px;height:96px}
  .info h3{font-size:20px}
  .info p{font-size:14px}
  .preview.slider{width:100%;height:180px}
}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php" class="active" aria-current="page">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff">ออกจากระบบ</a>
  </div>
</div>
<div class="page">
  <div class="header-spacer"></div>
  <div class="header-area">
    <h1>📷 รายชื่อช่างภาพทั้งหมด</h1>
    <form method="get" class="filter-form">
      <input type="text" name="search" placeholder="ค้นหาชื่อช่างภาพ..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
      <select name="sort_price">
        <option value="">เรียงตามราคา</option>
        <option value="asc" <?= $sort_price==='asc' ? 'selected' : '' ?>>ราคาต่ำ → สูง</option>
        <option value="desc" <?= $sort_price==='desc' ? 'selected' : '' ?>>ราคาสูง → ต่ำ</option>
      </select>
      <select name="expertise_id">
        <option value="">ประเภทงาน</option>
        <?php
        $expertise_result = $conn->query("SELECT * FROM expertise");
        if ($expertise_result) {
          while ($exp = $expertise_result->fetch_assoc()) {
            $selected = ($expertise_id !== '' && $expertise_id == $exp['expertise_id']) ? 'selected' : '';
            echo "<option value='".htmlspecialchars($exp['expertise_id'],ENT_QUOTES,'UTF-8')."' $selected>".
                  htmlspecialchars($exp['expertise_name'],ENT_QUOTES,'UTF-8')."</option>";
          }
        }
        ?>
      </select>
      <button type="submit">ค้นหา</button>
    </form>
  </div>
  <div class="scroll-area" aria-label="รายชื่อช่างภาพ เลื่อนดูในกรอบนี้">
    <?php if($result && $result->num_rows>0): ?>
      <?php while($row=$result->fetch_assoc()): ?>
        <?php
          $profile = norm_img($row['profile_image_path'] ?: $row['profile_image'], 'images/default_user.png');
          $photos = [];
          if (!empty($row['sample_photos'])) {
            foreach (explode('|', $row['sample_photos']) as $p) { $photos[] = norm_img($p, 'images/default_work.png'); }
          }
          if (empty($photos)) { $photos = ['images/default_work.png']; }
        ?>
        <a class="photographer-card" href="photographer_detail.php?id=<?= (int)$row['photographer_id'] ?>">
          <div class="left">
            <img class="profile" src="<?= htmlspecialchars($profile,ENT_QUOTES,'UTF-8') ?>" alt="โปรไฟล์" loading="lazy" onerror="this.onerror=null;this.src='images/default_user.png'">
            <div class="info">
              <h3><?= htmlspecialchars($row['first_name'].' '.$row['last_name'],ENT_QUOTES,'UTF-8') ?></h3>
              <p><strong>อีเมล:</strong> <?= htmlspecialchars($row['email'],ENT_QUOTES,'UTF-8') ?></p>
              <p><strong>เบอร์โทร:</strong> <?= htmlspecialchars($row['phone'],ENT_QUOTES,'UTF-8') ?></p>
              <p><strong>ความถนัด:</strong> <?= htmlspecialchars($row['expertise_name'],ENT_QUOTES,'UTF-8') ?></p>
              <p><strong>ราคาเริ่มต้น:</strong> <?= number_format((float)$row['price_rate'],2) ?> บาท</p>
            </div>
          </div>
          <div class="preview slider" data-interval="3000">
            <?php foreach($photos as $i => $src): ?>
              <img src="<?= htmlspecialchars($src,ENT_QUOTES,'UTF-8') ?>" alt="ผลงานตัวอย่าง <?= $i+1 ?>" loading="lazy" onerror="this.onerror=null;this.src='images/default_work.png'">
            <?php endforeach; ?>
          </div>
        </a>
      <?php endwhile; ?>
    <?php else: ?>
      <p class="no-data">ไม่พบข้อมูลช่างภาพ</p>
    <?php endif; ?>
  </div>
</div>
<script>
document.addEventListener("DOMContentLoaded",()=>{document.querySelectorAll('.preview.slider').forEach(slider=>{const imgs=slider.querySelectorAll('img');if(imgs.length===0)return;let idx=0;imgs[idx].classList.add('active');if(imgs.length===1)return;const interval=parseInt(slider.dataset.interval||"3000",10);setInterval(()=>{imgs[idx].classList.remove('active');idx=(idx+1)%imgs.length;imgs[idx].classList.add('active')},interval)})});
</script>
</body>
</html>
