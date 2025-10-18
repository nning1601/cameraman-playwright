<?php
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');
@$conn->query("SET SESSION group_concat_max_len = 100000");

$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_price   = isset($_GET['sort_price']) ? $_GET['sort_price'] : '';
$expertise_id = isset($_GET['expertise_id']) ? $_GET['expertise_id'] : '';
if (!in_array($sort_price, ['asc','desc',''], true)) { $sort_price = ''; }

$current_path = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

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
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
html,body{min-height:100%;height:auto}
body{min-height:100vh;overflow:auto;background:linear-gradient(135deg,#ffecd2,#fcb69f)}
:root{--nav-h:64px}
.cm-nav{position:fixed;top:0;left:0;right:0;z-index:1000;background:#0f172a;box-shadow:0 6px 18px rgba(2,6,23,.25)}
.cm-wrap{width:100%;margin:0;padding:10px 16px;display:flex;align-items:center;gap:12px}
.cm-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.cm-logo{font-size:22px}
.cm-title{font-weight:700;color:#fff;font-size:18px;letter-spacing:.3px}
.cm-spacer{flex:1 1 auto}
.cm-links{list-style:none;display:flex;align-items:center;gap:8px;margin:0}
.cm-links>li>a,.cm-dropbtn{color:#fff;text-decoration:none;font-weight:600;font-size:14px;padding:9px 12px;border-radius:10px;cursor:pointer;transition:.18s;background:transparent;border:1px solid transparent}
.cm-links>li>a:hover,.cm-dropbtn:hover{background:#22d3ee;color:#0f172a}
.cm-links>li>a.active{background:linear-gradient(90deg,#7c3aed,#06b6d4);box-shadow:0 2px 8px rgba(124,58,237,.25);color:#fff}
.cm-has-dropdown{position:relative}
.cm-dropdown{position:absolute;top:calc(100% + 8px);right:0;min-width:220px;list-style:none;padding:8px;margin:0;border-radius:12px;background:#fff;box-shadow:0 12px 28px rgba(2,6,23,.18);display:none}
.cm-dropdown a{display:block;padding:10px 12px;border-radius:10px;color:#0f172a;text-decoration:none;font-weight:600}
.cm-dropdown a:hover,.cm-dropdown a:focus-visible{background:#eef2ff}
@media (hover:hover){.cm-has-dropdown:hover>.cm-dropdown{display:block}}
.cm-has-dropdown:focus-within>.cm-dropdown{display:block}
.cm-hamburger{display:none;width:42px;height:36px;background:transparent;border:none;cursor:pointer}
.cm-hamburger span{display:block;height:2px;margin:7px 0;background:#fff;transition:.25s}
.page{width:100%;max-width:1000px;margin:0 auto;padding:0 20px}
.header-spacer{height:calc(var(--nav-h) + 16px)}
h1{text-align:center;font-size:26px;color:#0f172a;margin-bottom:14px;font-weight:700}
.filter-form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;justify-content:center}
.filter-form input,.filter-form select,.filter-form button{padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;font-weight:600;font-size:14px}
.filter-form input{flex:2;min-width:200px}
.filter-form select,.filter-form button{flex:1;min-width:140px;cursor:pointer}
.filter-form button{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border:none;transition:.2s}
.filter-form button:hover{opacity:.95;transform:translateY(-1px)}
.scroll-area{overflow:visible}
.photographer-card{background:#fff;border-radius:16px;box-shadow:0 10px 24px rgba(2,6,23,.12);padding:20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:inherit;transition:.2s;gap:16px;border:1px solid rgba(2,6,23,.06)}
.photographer-card:hover{transform:translateY(-3px);box-shadow:0 16px 36px rgba(2,6,23,.18);background:#fafafc}
.left{display:flex;align-items:center;gap:18px;min-width:0;flex:1}
.left img.profile{width:110px;height:110px;object-fit:cover;border-radius:12px;border:2px solid #e2e8f0;flex:0 0 auto;background:#f1f5f9}
.info{flex:1;min-width:0}
.info h3{margin:0 0 8px;font-size:20px;color:#0f172a;font-weight:700}
.info p{margin:4px 0;color:#334155;font-weight:500;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.preview.slider{position:relative;width:260px;height:140px;border-radius:12px;overflow:hidden;border:2px solid #e2e8f0;background:#0b1220}
.preview.slider img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity 600ms ease}
.preview.slider img.active{opacity:1}
.no-data{text-align:center;color:#475569;font-size:16px;margin-top:16px}
@media (prefers-reduced-motion:reduce){.preview.slider img{transition:none}.photographer-card{transition:none}}
@media (max-width:900px){
  .cm-hamburger{display:block}
  .cm-links{position:fixed;left:0;right:0;top:var(--nav-h);display:block;background:#0f172a;border-radius:0;padding:10px;box-shadow:0 14px 28px rgba(2,6,23,.28);max-height:0;overflow:hidden;transition:max-height .28s}
  .cm-links.open{max-height:70vh;overflow:auto}
  .cm-links>li{margin:6px 0}
  .cm-links>li>a,.cm-dropbtn{width:100%;justify-content:space-between;color:#fff;background:transparent;border:1px solid rgba(255,255,255,.12)}
  .cm-dropdown{position:static;display:none;background:#111827;border:none;box-shadow:none;margin-top:6px}
  .cm-dropdown a{color:#e5e7eb}
  .cm-has-dropdown.open>.cm-dropdown{display:block}
}
@media(max-width:640px){
  .header-spacer{height:calc(var(--nav-h) + 12px)}
  h1{font-size:22px}
  .photographer-card{flex-direction:column;align-items:stretch}
  .left{gap:12px}
  .left img.profile{width:96px;height:96px}
  .info h3{font-size:19px}
  .info p{font-size:14px}
  .preview.slider{width:100%;height:180px}
}
</style>
</head>
<body>
<nav class="cm-nav" aria-label="หลัก">
  <div class="cm-wrap">
    <a class="cm-brand" href="index.php" aria-label="หน้าแรก Cameraman">
      <span class="cm-logo">📷</span>
      <span class="cm-title">Cameraman</span>
    </a>
    <div class="cm-spacer" aria-hidden="true"></div>
    <ul id="cmMenu" class="cm-links" role="menubar">
      <li role="none">
        <a role="menuitem" href="index.php" class="<?= $current_path==='index.php' || $current_path==='' ? 'active' : '' ?>">หน้าแรก</a>
      </li>
      <li class="cm-has-dropdown" role="none">
        <button class="cm-dropbtn" role="menuitem" aria-haspopup="true" aria-expanded="false">สมัครสมาชิก ▾</button>
        <ul class="cm-dropdown" role="menu" aria-label="สมัครสมาชิก">
          <li role="none"><a role="menuitem" href="register_user.php" class="<?= $current_path==='register_user.php' ? 'active' : '' ?>">สมาชิก</a></li>
          <li role="none"><a role="menuitem" href="register_photographer.php" class="<?= $current_path==='register_photographer.php' ? 'active' : '' ?>">ช่างภาพ</a></li>
        </ul>
      </li>
      <li class="cm-has-dropdown" role="none">
        <button class="cm-dropbtn" role="menuitem" aria-haspopup="true" aria-expanded="false">เข้าสู่ระบบ ▾</button>
        <ul class="cm-dropdown" role="menu" aria-label="เข้าสู่ระบบ">
          <li role="none"><a role="menuitem" href="login_user.php" class="<?= $current_path==='login_user.php' ? 'active' : '' ?>">สมาชิก</a></li>
          <li role="none"><a role="menuitem" href="login_photographer.php" class="<?= $current_path==='login_photographer.php' ? 'active' : '' ?>">ช่างภาพ</a></li>
          <li role="none"><a role="menuitem" href="login_admin.php" class="<?= $current_path==='login_admin.php' ? 'active' : '' ?>">ผู้ดูแลระบบ</a></li>
        </ul>
      </li>
    </ul>
    <button class="cm-hamburger" aria-label="เปิดเมนู" aria-expanded="false" aria-controls="cmMenu">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<div class="page">
  <div class="header-spacer"></div>

  <h1>📷 รายชื่อช่างภาพทั้งหมด</h1>

  <form method="get" class="filter-form">
    <input type="text" name="search" placeholder="ค้นหาชื่อช่างภาพ..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
    <select name="sort_price">
      <option value="">เรียงตามราคา</option>
      <option value="asc"  <?= $sort_price==='asc' ? 'selected' : '' ?>>ราคาต่ำ → สูง</option>
      <option value="desc" <?= $sort_price==='desc' ? 'selected' : '' ?>>ราคาสูง → ต่ำ</option>
    </select>
    <select name="expertise_id">
      <option value="">ประเภทงาน</option>
      <?php
      if ($expres = $conn->query("SELECT * FROM expertise")){
        while($exp = $expres->fetch_assoc()){
          $sel = ($expertise_id==$exp['expertise_id']) ? 'selected' : '';
          echo "<option value='".htmlspecialchars($exp['expertise_id'],ENT_QUOTES,'UTF-8')."' $sel>".
               htmlspecialchars($exp['expertise_name'],ENT_QUOTES,'UTF-8').
               "</option>";
        }
      }
      ?>
    </select>
    <button type="submit">ค้นหา</button>
  </form>

  <div class="scroll-area" aria-label="รายชื่อช่างภาพ">
    <?php if($result && $result->num_rows>0): while($row=$result->fetch_assoc()):
      $profile = norm_img($row['profile_image_path'] ?: $row['profile_image']);
      $photos = [];
      if(!empty($row['sample_photos'])){
        foreach(explode('|',$row['sample_photos']) as $p){ $photos[] = norm_img($p,'images/default_work.png'); }
      }
      if(empty($photos)) $photos = ['images/default_work.png'];
    ?>
    <a class="photographer-card" href="photographer2_detail.php?id=<?= (int)$row['photographer_id'] ?>">
      <div class="left">
        <img class="profile" src="<?= htmlspecialchars($profile,ENT_QUOTES,'UTF-8') ?>" alt="รูปโปรไฟล์"
             loading="lazy" onerror="this.onerror=null;this.src='images/default_user.png'">
        <div class="info">
          <h3><?= htmlspecialchars($row['first_name'].' '.$row['last_name'],ENT_QUOTES,'UTF-8') ?></h3>
          <p><strong>อีเมล:</strong> <?= htmlspecialchars($row['email'],ENT_QUOTES,'UTF-8') ?></p>
          <p><strong>เบอร์โทร:</strong> <?= htmlspecialchars($row['phone'],ENT_QUOTES,'UTF-8') ?></p>
          <p><strong>ความถนัด:</strong> <?= htmlspecialchars($row['expertise_name'],ENT_QUOTES,'UTF-8') ?></p>
          <p><strong>ราคาเริ่มต้น:</strong> <?= number_format((float)$row['price_rate'],2) ?> บาท</p>
        </div>
      </div>
      <div class="preview slider" data-interval="3000">
        <?php foreach($photos as $i=>$src): ?>
          <img src="<?= htmlspecialchars($src,ENT_QUOTES,'UTF-8') ?>" alt="ผลงานตัวอย่าง <?= $i+1 ?>" loading="lazy"
               onerror="this.onerror=null;this.src='images/default_work.png'">
        <?php endforeach; ?>
      </div>
    </a>
    <?php endwhile; else: ?>
      <p class="no-data">ไม่พบข้อมูลช่างภาพ</p>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const nav = document.querySelector('.cm-nav');
  const setNavH = () => {
    if (!nav) return;
    const h = Math.round(nav.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--nav-h', h + 'px');
  };
  setNavH();
  window.addEventListener('resize', setNavH);

  const hamburger = document.querySelector('.cm-hamburger');
  const menu = document.getElementById('cmMenu');
  const dropBtns = document.querySelectorAll('.cm-dropbtn');

  if (hamburger && menu){
    hamburger.addEventListener('click', () => {
      const open = menu.classList.toggle('open');
      hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
      setNavH();
    });
  }
  dropBtns.forEach(btn => {
    const parent = btn.closest('.cm-has-dropdown');
    btn.addEventListener('click', (e) => {
      if (window.matchMedia('(max-width: 900px)').matches){
        e.preventDefault();
        parent.classList.toggle('open');
        btn.setAttribute('aria-expanded', parent.classList.contains('open') ? 'true' : 'false');
        setNavH();
      }
    });
  });
  document.addEventListener('click', (e) => {
    const inside = e.target.closest('.cm-nav');
    if (!inside && menu && menu.classList.contains('open')){
      menu.classList.remove('open');
    }
  });
  document.querySelectorAll('.preview.slider').forEach(slider => {
    const imgs = slider.querySelectorAll('img');
    if (imgs.length === 0) return;
    let idx = 0;
    imgs[idx].classList.add('active');
    if (imgs.length === 1) return;
    const interval = parseInt(slider.dataset.interval || "3000", 10);
    setInterval(() => {
      imgs[idx].classList.remove('active');
      idx = (idx + 1) % imgs.length;
      imgs[idx].classList.add('active');
    }, interval);
  });
})();
</script>
</body>
</html>
