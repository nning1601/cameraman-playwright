<?php
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$expertise_id = isset($_GET['expertise_id']) ? intval($_GET['expertise_id']) : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'popular';

$ratingCol = null;
$candidates = ['rating','score','stars','star','rate','point','points','value','rating_value','rating_score'];
$placeholders = implode(',', array_fill(0, count($candidates), '?'));
$sqlCol = "
  SELECT COLUMN_NAME 
  FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = ?
    AND COLUMN_NAME IN ($placeholders)
  LIMIT 1
";
$stmtCol = $conn->prepare($sqlCol);
$params = array_merge(['photographerrating'], $candidates);
$types = str_repeat('s', count($params));
$bind = [];
$bind[] = &$types;
foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
call_user_func_array([$stmtCol, 'bind_param'], $bind);
$stmtCol->execute();
$resCol = $stmtCol->get_result();
if ($row = $resCol->fetch_assoc()) $ratingCol = $row['COLUMN_NAME'];
$stmtCol->close();

$expertises = [];
$exq = $conn->query("SELECT expertise_id, expertise_name FROM expertise ORDER BY expertise_name");
if ($exq) while ($r = $exq->fetch_assoc()) $expertises[] = $r;

$ratingSub = $ratingCol
? "SELECT photographer_id, AVG($ratingCol) AS avg_rating, COUNT(*) AS total_reviews FROM photographerrating GROUP BY photographer_id"
: "SELECT photographer_id, NULL AS avg_rating, COUNT(*) AS total_reviews FROM photographerrating GROUP BY photographer_id";

$sql = "
SELECT 
  p.photographer_id,
  p.first_name,
  p.last_name,
  COALESCE(NULLIF(p.profile_image_path,''), NULLIF(p.profile_image,''), 'images/default_user.png') AS raw_profile_img,
  e.expertise_name,
  p.price_rate,
  COALESCE(r.avg_rating, 0)    AS avg_rating,
  COALESCE(r.total_reviews, 0) AS total_reviews
FROM photographer p
LEFT JOIN expertise e ON e.expertise_id = p.expertise_id
LEFT JOIN ( $ratingSub ) r ON r.photographer_id = p.photographer_id
WHERE 1=1
";
$params = []; $types = '';
if ($expertise_id > 0) { $sql .= " AND p.expertise_id = ? "; $types .= 'i'; $params[] = $expertise_id; }
if ($q !== '') { $sql .= " AND (p.first_name LIKE CONCAT('%', ?, '%') OR p.last_name LIKE CONCAT('%', ?, '%')) "; $types .= 'ss'; $params[]=$q; $params[]=$q; }
switch ($sort) {
  case 'reviews':    $sql .= " ORDER BY total_reviews DESC, avg_rating DESC, p.first_name ASC "; break;
  case 'price_low':  $sql .= " ORDER BY p.price_rate ASC,  avg_rating DESC "; break;
  case 'price_high': $sql .= " ORDER BY p.price_rate DESC, avg_rating DESC "; break;
  default:           $sql .= " ORDER BY avg_rating DESC, total_reviews DESC, p.first_name ASC "; break;
}
$stmt = $conn->prepare($sql);
if ($types !== '') {
  $bind = []; $bind[] = &$types;
  for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
  call_user_func_array([$stmt,'bind_param'],$bind);
}
$stmt->execute();
$result = $stmt->get_result();

function normalize_web_image($rawPath) {
  $fallback = 'images/default_user.png';
  $p = str_replace('\\', '/', (string)$rawPath);
  $p = trim($p);
  if ($p === '') return $fallback;
  $qpos = strpos($p, '?');
  $hpos = strpos($p, '#');
  $cutPos = false;
  if ($qpos !== false && $hpos !== false) $cutPos = min($qpos, $hpos);
  elseif ($qpos !== false) $cutPos = $qpos;
  elseif ($hpos !== false) $cutPos = $hpos;
  if ($cutPos !== false) $p = substr($p, 0, $cutPos);
  $startsWith = function($str, $prefix) { return strncmp($str, $prefix, strlen($prefix)) === 0; };
  $pLower = strtolower($p);
  if ($startsWith($pLower, 'http://') || $startsWith($pLower, 'https://')) return $p;
  $isWinAbs  = (strlen($p) >= 3 && ctype_alpha($p[0]) && $p[1] === ':' && $p[2] === '/');
  $isUnixAbs = (strlen($p) >= 1 && $p[0] === '/');
  if ($isWinAbs || $isUnixAbs) {
    $base = basename($p);
    if ($base === '') return $fallback;
    if (is_file(__DIR__ . "/uploads/$base")) return "uploads/$base";
    if (is_file(__DIR__ . "/images/$base"))  return "images/$base";
    return "uploads/$base";
  }
  if (strpos($p, '/') !== false) {
    if ($startsWith($pLower, 'uploads/') || $startsWith($pLower, 'images/')) return $p;
    if (is_file(__DIR__ . "/$p")) return $p;
    $base = basename($p);
    if ($base !== '') {
      if (is_file(__DIR__ . "/uploads/$base")) return "uploads/$base";
      if (is_file(__DIR__ . "/images/$base"))  return "images/$base";
    }
    return $p;
  }
  if (is_file(__DIR__ . "/uploads/$p")) return "uploads/$p";
  if (is_file(__DIR__ . "/images/$p"))  return "images/$p";
  return "uploads/$p";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คะแนนความนิยมช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.header-spacer{height:100px}
.wrap{width:100%;max-width:1200px;padding:0 12px 28px}
.filters{position:sticky;top:100px;z-index:50;display:grid;grid-template-columns:1.1fr 200px 160px auto;gap:10px;background:#fff;padding:12px;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 2px 12px rgba(106,17,203,.06)}
.filters input,.filters select{width:100%;padding:10px 12px;background:#f8fafc;color:#111827;border:1px solid #dbeafe;border-radius:10px;outline:none;font-size:15px}
.filters button{width:100%;padding:10px 0;border:none;border-radius:10px;cursor:pointer;font-weight:700;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;letter-spacing:.2px;font-size:15px;box-shadow:0 4px 16px rgba(106,17,203,.1)}
.filters .reset{margin-left:8px;color:#475569;text-decoration:none;font-size:14px}
@media(max-width:900px){.filters{grid-template-columns:1fr 1fr;top:100px}}
@media(max-width:600px){.filters{grid-template-columns:1fr;top:100px}}
.container{max-width:1200px;margin:18px auto 40px;padding:0 12px}
.grid{margin-top:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:720px){.grid{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 14px 12px;display:flex;gap:12px;align-items:flex-start;transition:transform .12s,box-shadow .12s,border-color .12s;position:relative;overflow:hidden;box-shadow:0 2px 10px rgba(106,17,203,.04)}
.card:hover{transform:translateY(-2px);border-color:#2575fc;box-shadow:0 8px 24px rgba(106,17,203,.1)}
.avatar{width:70px;height:70px;border-radius:12px;object-fit:cover;background:#e0e7ef;flex-shrink:0;border:1px solid #dbeafe}
.info{flex:1;min-width:0}
.name{font-weight:900;margin-bottom:2px;letter-spacing:.2px;font-size:17px;color:#6a11cb}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 8px}
.chip{font-size:12px;color:#2575fc;border:1px solid #dbeafe;background:#f1f5fd;padding:3px 8px;border-radius:999px}
.topline{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#fff;background:linear-gradient(45deg,#fcd34d,#f59e0b);padding:3px 8px;border-radius:8px;font-weight:800}
.reviews{font-size:12px;color:#64748b;padding:3px 8px;border-radius:8px;border:1px dashed #dbeafe;background:#f8fafc}
.price{margin-left:auto;font-weight:900;background:#e0f7e9;color:#059669;padding:5px 10px;border-radius:9px;border:1px solid #bbf7d0;font-size:14px}
.stars{display:flex;gap:2px;font-size:15px;line-height:1}
.star{color:#e5e7eb}
.star.full{color:#f59e0b}
.score{font-weight:800;color:#2575fc}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.btn{text-decoration:none;padding:8px 12px;border-radius:9px;font-weight:700;border:1px solid #dbeafe;color:#2575fc;background:#f8fafc;font-size:15px}
.btn.primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.btn:hover{border-color:#2575fc;background:#e0e7ff;color:#2575fc}
.empty{text-align:center;padding:40px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-top:18px;color:#64748b}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php" class="active" aria-current="page">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="photographer_deposits.php">ดูข้อมูลมัดจำ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" style="background:linear-gradient(90deg,#f43f5e,#f59e0b);color:#fff;">ออกจากระบบ</a>
  </div>
</div>

<div class="header-spacer"></div>

<div class="wrap">
  <form class="filters" method="get" action="">
    <input type="text" name="q" placeholder="🔎 ค้นหาชื่อช่างภาพ..." value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
    <select name="expertise_id">
      <option value="0">— ทุกความเชี่ยวชาญ —</option>
      <?php foreach($expertises as $ex): ?>
        <option value="<?= (int)$ex['expertise_id'] ?>" <?= $expertise_id==(int)$ex['expertise_id']?'selected':'' ?>>
          <?= htmlspecialchars($ex['expertise_name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="sort">
      <option value="popular"    <?= $sort==='popular'?'selected':'' ?>>🔥 ความนิยม (คะแนนสูง → ต่ำ)</option>
      <option value="reviews"    <?= $sort==='reviews'?'selected':'' ?>>💬 รีวิวมาก → น้อย</option>
      <option value="price_low"  <?= $sort==='price_low'?'selected':'' ?>>💸 ราคา ต่ำ → สูง</option>
      <option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>💎 ราคา สูง → ต่ำ</option>
    </select>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <button type="submit">ค้นหา</button>
      <a class="reset" href="photographer_popularity.php">ล้างค่า</a>
    </div>
  </form>
</div>

<main class="container">
<?php if ($result && $result->num_rows > 0): ?>
  <div class="grid">
    <?php while($row = $result->fetch_assoc()):
      $avg = is_null($row['avg_rating']) ? 0.0 : (float)$row['avg_rating'];
      $count = (int)$row['total_reviews'];
      $full = floor($avg);
      $half = ($avg-$full) >= 0.5 ? 1 : 0;
      $empty = 5 - $full - $half;
      $priceText = is_null($row['price_rate']) ? '-' : number_format((float)$row['price_rate']);
      $imgWeb = normalize_web_image($row['raw_profile_img']);
    ?>
    <article class="card" aria-label="การ์ดช่างภาพ">
      <img class="avatar" src="<?= htmlspecialchars($imgWeb, ENT_QUOTES, 'UTF-8') ?>" alt="รูปช่างภาพ" loading="lazy" onerror="this.onerror=null;this.src='images/default_user.png';">
      <div class="info">
        <div class="topline">
          <div class="name"><?= htmlspecialchars($row['first_name'].' '.$row['last_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <span class="badge">⭐ <span class="score"><?= number_format($avg,1) ?></span>/5</span>
          <span class="reviews">💬 <?= $count ?> รีวิว</span>
          <span class="price"><?= $priceText==='-'?'ราคาไม่ระบุ':$priceText.' บาท' ?></span>
        </div>
        <?php if(!empty($row['expertise_name'])): ?>
          <div class="chips"><span class="chip">🎯 <?= htmlspecialchars($row['expertise_name'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <?php endif; ?>
        <div class="stars">
          <?php for($i=0;$i<$full;$i++): ?><span class="star full">★</span><?php endfor; ?>
          <?php if($half): ?><span class="star full" style="clip-path:inset(0 50% 0 0)">★</span><?php endif; ?>
          <?php for($i=0;$i<$empty;$i++): ?><span class="star">★</span><?php endfor; ?>
        </div>
        <div class="actions">
          <a class="btn" href="photographer_detail.php?id=<?= (int)$row['photographer_id'] ?>">ดูโปรไฟล์</a>
          <a class="btn primary" href="booking_step1.php?photographer_id=<?= (int)$row['photographer_id'] ?>">จองช่างภาพ</a>
        </div>
      </div>
    </article>
    <?php endwhile; ?>
  </div>
<?php else: ?>
  <div class="empty">
    <div style="font-weight:900;font-size:18px">ไม่พบช่างภาพตามเงื่อนไขที่ค้นหา</div>
    <div style="margin-top:6px">ลองล้างค่าการค้นหาหรือเลือกตัวกรองอื่นดูนะ</div>
  </div>
<?php endif; ?>
</main>
</body>
</html>
