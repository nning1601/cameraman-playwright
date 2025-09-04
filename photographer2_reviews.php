<?php
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_GET['photographer_id'])) { die("ไม่พบช่างภาพ"); }
$photographer_id = (int)$_GET['photographer_id'];
$current_user_id = $_SESSION['user_id'] ?? null;

$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
function img_src($raw, $fallback='images/default_user.png'){
  global $BASE_URL;
  $p = trim((string)$raw);
  if ($p === '' || $p === null) $p = $fallback;
  $p = str_replace('\\','/',$p);
  if (preg_match('#^https?://#i',$p)) return $p;
  if (!preg_match('#^(uploads|images)/#i',$p)) $p = 'uploads/'.ltrim($p,'/');
  return $BASE_URL . ltrim($p,'/');
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$sql = "SELECT p.*, e.expertise_name FROM photographer p LEFT JOIN expertise e ON p.expertise_id=e.expertise_id WHERE p.photographer_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$photographer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$photographer) die("ไม่พบช่างภาพ");

$photoImg = $photographer['profile_image'] ?? ($photographer['profile_image_path'] ?? '');
if (!$photoImg) $photoImg = 'images/default_user.png';

if($_SERVER['REQUEST_METHOD']==='POST' && $current_user_id){
  $score = (int)($_POST['score']??0);
  $comment = trim($_POST['comment']??'');
  if($score<1 || $score>5){
    $_SESSION['flash'] = "กรุณาให้คะแนน 1-5";
  } else {
    $sql="INSERT INTO photographerrating(photographer_id,user_id,score,comment,created_at) VALUES(?,?,?,?,NOW())";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iiis",$photographer_id,$current_user_id,$score,$comment);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash'] = "✅ ขอบคุณสำหรับรีวิว";
  }
  header("Location: ".$_SERVER['PHP_SELF']."?photographer_id=".$photographer_id);
  exit;
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$stmt=$conn->prepare("SELECT AVG(score) AS avg_rating,COUNT(*) AS total_reviews FROM photographerrating WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$summary=$stmt->get_result()->fetch_assoc();
$stmt->close();
$avg=(float)($summary['avg_rating']??0);
$cnt=(int)($summary['total_reviews']??0);

$perpage=8;
$page=max(1,(int)($_GET['page']??1));
$offset=($page-1)*$perpage;

$stmt=$conn->prepare("SELECT r.score,r.comment,r.created_at,u.first_name,u.last_name FROM photographerrating r LEFT JOIN users u ON r.user_id=u.user_id WHERE r.photographer_id=? ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii",$photographer_id,$perpage,$offset);
$stmt->execute();
$reviews=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) AS c FROM photographerrating WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$total=$stmt->get_result()->fetch_assoc()['c']??0;
$stmt->close();
$pages=max(1,(int)ceil($total/$perpage));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รีวิวของ <?= h(($photographer['first_name'] ?? '').' '.($photographer['last_name'] ?? '')) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
:root{--nav-h:72px;--grad:linear-gradient(90deg,#0ea5e9,#22d3ee)}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center;color:#0f172a}
.navbar{position:fixed;top:0;left:0;right:0;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;background:#0f172a;padding:10px 20px;z-index:1000}
.logo{font-size:20px;font-weight:800;color:#fff}
.nav-links{list-style:none;display:flex;gap:10px;align-items:center}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:13px;color:#fff;padding:8px 10px;border-radius:10px;transition:.18s}
.nav-links a:hover{background:#22d3ee;color:#0f172a}
.dropdown-menu{position:absolute;top:calc(100% + 6px);left:0;min-width:190px;padding:8px;background:#fff;border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);display:none}
.dropdown-menu a{display:block;color:#0f172a;padding:8px 10px;border-radius:8px;font-size:13px}
.dropdown-menu a:hover{background:#e2e8f0}
.dropdown:hover .dropdown-menu{display:block}
.hamburger{display:none;width:28px;height:20px;flex-direction:column;justify-content:space-between;cursor:pointer}
.hamburger span{display:block;height:2.5px;background:#fff;border-radius:3px}
.header-spacer{height:var(--nav-h)}
.container{width:100%;max-width:1100px;padding:0 20px 40px}
.card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 16px 32px rgba(2,6,23,.08)}
.profile{display:flex;align-items:center;flex-wrap:wrap;gap:18px;margin-bottom:12px}
.profile img{width:110px;height:110px;object-fit:cover;border-radius:50%;border:3px solid #22d3ee;box-shadow:0 6px 18px rgba(2,6,23,.12)}
.profile-info h2{color:#0f172a;margin-bottom:4px;font-size:20px}
.profile-info p{margin:3px 0;font-size:14px;color:#334155}
.stats{margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.badge{background:#fff7ed;border:1px solid #fed7aa;padding:5px 8px;border-radius:10px;font-weight:700;font-size:12.5px}
.badge.muted{background:#eef2ff;border-color:#c7d2fe}
.btn-group{margin-top:8px;display:flex;flex-wrap:wrap;gap:8px}
.btn-group a{background:var(--grad);color:#0f172a;padding:8px 12px;border-radius:10px;text-decoration:none;font-weight:800;font-size:13px;transition:.18s}
.btn-group a:hover{opacity:.95;transform:translateY(-1px)}
.block{margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
.block h3{color:#0f172a;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin:0 0 10px;font-size:16px}
.flash{margin-bottom:8px;background:#ecfdf5;border:1px solid #34d399;color:#065f46;padding:8px;border-radius:10px;font-size:13px}
.review-list{display:grid;gap:8px}
.review-item{background:#fafafa;border:1px solid #e5e7eb;padding:8px 10px;border-radius:10px}
.review-head{display:flex;gap:8px;align-items:center;font-weight:700;color:#0f172a;font-size:14px}
.review-date{color:#64748b;font-weight:400;font-size:12px;margin-left:auto}
.review-comment{margin-top:6px;color:#334155;white-space:pre-wrap;line-height:1.6;font-size:14px}
.stars{color:#eab308}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.pager a,.pager span{padding:6px 9px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#374151;background:#fff;font-size:13px}
.pager .active{background:var(--grad);border-color:transparent;color:#0f172a;font-weight:800}
form.review-form{display:grid;gap:8px;margin-top:8px}
textarea{width:100%;min-height:100px;padding:10px;border-radius:10px;background:#fff;color:#0f172a;border:1px solid #e5e7eb;font-size:14px}
select{padding:8px 10px;border-radius:10px;background:#fff;color:#0f172a;border:1px solid #e5e7eb;font-size:14px}
button{padding:9px 12px;border-radius:12px;background:var(--grad);color:#0f172a;border:none;font-weight:800;cursor:pointer;font-size:14px}
button:hover{filter:brightness(1.05)}
@media(max-width:980px){
  .hamburger{display:flex}
  .nav-links{position:fixed;right:0;top:var(--nav-h);bottom:0;width:240px;background:#0f172a;flex-direction:column;padding:12px;gap:8px;transform:translateX(110%);transition:transform .25s}
  .nav-links.open{transform:translateX(0)}
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
      <a href="javascript:void(0)" class="drop-toggle">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="javascript:void(0)" class="drop-toggle">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" id="hamburger"><span></span><span></span><span></span></div>
</div>

<div class="header-spacer"></div>

<div class="container">
  <div class="card">
    <div class="profile">
      <img src="<?= h(img_src($photoImg)) ?>" alt="รูปโปรไฟล์" onerror="this.onerror=null;this.src='<?= h($BASE_URL) ?>images/default_user.png'">
      <div class="profile-info">
        <h2>รีวิวของ <?= h(($photographer['first_name'] ?? '').' '.($photographer['last_name'] ?? '')) ?></h2>
        <div class="stats">
          <span class="badge">⭐ <?= number_format($avg,1) ?>/5</span>
          <span class="badge muted">💬 <?= $cnt ?> รีวิว</span>
        </div>
        <div class="btn-group">
          <a href="<?= h($BASE_URL) ?>photographer2_detail.php?id=<?= $photographer_id ?>">⬅️ กลับหน้าช่างภาพ</a>
          <a href="<?= h($BASE_URL) ?>photographer2_schedule.php?photographer_id=<?= $photographer_id ?>">📖 ดูตารางงาน</a>
          <a href="<?= h($BASE_URL) ?>login_user.php?photographer_id=<?= $photographer_id ?>">📅 จองช่างภาพนี้</a>
        </div>
      </div>
    </div>

    <?php if($flash): ?>
      <div class="flash"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="block">
      <h3>📝 รีวิวทั้งหมด</h3>
      <?php if(!$reviews): ?>
        <p style="color:#64748b">ยังไม่มีรีวิว</p>
      <?php else: ?>
        <div class="review-list">
          <?php foreach($reviews as $rv): ?>
            <?php
              $name = trim(($rv['first_name']??'ผู้ใช้').' '.($rv['last_name']??''));
              $dateStr = !empty($rv['created_at']) ? date('d/m/Y H:i', strtotime($rv['created_at'])) : '–';
            ?>
            <div class="review-item">
              <div class="review-head">
                <div><?= h($name) ?></div>
                <div class="stars">⭐ <?= (int)($rv['score'] ?? 0) ?>/5</div>
                <div class="review-date"><?= h($dateStr) ?></div>
              </div>
              <?php if(!empty($rv['comment'])): ?>
                <div class="review-comment"><?= nl2br(h($rv['comment'])) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="pager">
        <?php for($i=1;$i<=$pages;$i++): ?>
          <?php if($i==$page): ?>
            <span class="active"><?= $i ?></span>
          <?php else: ?>
            <a href="?photographer_id=<?= $photographer_id ?>&page=<?= $i ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>

    
</div>

<script>
const nav=document.getElementById('navLinks');const burger=document.getElementById('hamburger');burger&&burger.addEventListener('click',()=>nav.classList.toggle('open'));nav&&nav.querySelectorAll('a').forEach(a=>{a.addEventListener('click',()=>nav.classList.remove('open'))});nav&&nav.querySelectorAll('.drop-toggle').forEach(btn=>{btn.addEventListener('click',e=>{if(matchMedia('(max-width: 980px)').matches){e.preventDefault();const li=btn.closest('li');li.classList.toggle('open')}})});
</script>
</body>
</html>
