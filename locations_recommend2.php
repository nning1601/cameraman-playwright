<?php
session_start();
@include_once 'db.php';
if (!isset($conn) || !$conn) {
    $conn = @mysqli_connect('localhost','cameraman_ro','readonly_password','cameraman');
    if(!$conn){ die('เชื่อมต่อฐานข้อมูลล้มเหลว'); }
}
mysqli_set_charset($conn,'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): bool { $bind = [$types]; foreach ($values as $k=>$v) { $bind[] = &$values[$k]; } return call_user_func_array([$stmt,'bind_param'],$bind); }
$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
$DOCROOT  = rtrim(__DIR__, '/\\');

$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name_asc';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 9;
$orderBy = ($sort==='name_desc') ? 'location_name DESC' : 'location_name ASC';

$whereSql = ""; $params=[]; $types="";
if ($q !== "") { $whereSql = "WHERE (location_name LIKE ? OR address LIKE ? OR description LIKE ?)"; $qLike = "%{$q}%"; $params = [$qLike,$qLike,$qLike]; $types="sss"; }

$countSql = "SELECT COUNT(*) AS c FROM locations ".$whereSql;
$stmt = $conn->prepare($countSql); if($whereSql) stmt_bind_params($stmt,$types,$params);
$stmt->execute(); $res=$stmt->get_result(); $total=(int)($res->fetch_assoc()['c'] ?? 0); $stmt->close();

$pages = max(1, (int)ceil($total/$per));
$offset = ($page-1)*$per;

$sql = "SELECT location_id, location_name, description, address, image_path FROM locations $whereSql ORDER BY $orderBy LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($whereSql) { $types2=$types.'ii'; $params2=array_merge($params,[$per,$offset]); stmt_bind_params($stmt,$types2,$params2); }
else { stmt_bind_params($stmt,'ii',[$per,$offset]); }
$stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

function placeholder_svg(string $title): string {
    $title = h($title ?: 'Location');
    $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0%" stop-color="#e9d5ff"/><stop offset="100%" stop-color="#bfdbfe"/></linearGradient></defs><rect width="800" height="450" fill="url(#g)"/><g fill="#1f2937" font-family="sans-serif" text-anchor="middle"><text x="400" y="220" font-size="28" font-weight="700">No Image</text><text x="400" y="260" font-size="20">'.$title.'</text></g></svg>');
    return "data:image/svg+xml;charset=UTF-8,{$svg}";
}
function resolve_image(array $row, string $DOCROOT, string $BASE_URL): array {
    $raw = trim((string)($row['image_path'] ?? ''));
    $name = trim((string)($row['location_name'] ?? ''));
    if ($raw === '') return ['web'=>placeholder_svg($name), 'exists'=>false];
    if (preg_match('#^https?://#i', $raw)) return ['web'=>$raw, 'exists'=>true];
    $webRel = ltrim(str_replace('\\','/',$raw), '/');
    $fsAbs  = $DOCROOT . '/' . $webRel;
    $exists = is_file($fsAbs);
    $webUrl = $BASE_URL . $webRel;
    return ['web'=> $exists ? $webUrl : placeholder_svg($name), 'exists'=>$exists];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สถานที่ถ่ายภาพแนะนำ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);color:#0f172a;display:flex;flex-direction:column;align-items:center}

/* ===== NAVBAR (ปรับปรุง: dropdown + hamburger) ===== */
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.nav-links{list-style:none;display:flex;gap:14px;align-items:center;margin:0;padding:0}
.nav-links > li > a{color:#fff;text-decoration:none;font-weight:600;padding:8px 10px;border-radius:10px;display:inline-block}
.nav-links > li > a:hover{background:#22d3ee;color:#0f172a}
.nav-links a.active,[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
/* Dropdown */
.dropdown{position:relative}
.dropdown > a{cursor:pointer}
.dropdown-menu{position:absolute;top:110%;left:0;min-width:190px;background:#0b1220;border:1px solid #1f2937;border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.35);padding:8px;display:none}
.dropdown-menu li{list-style:none}
.dropdown-menu a{display:block;color:#e5e7eb;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:14px}
.dropdown-menu a:hover{background:#111827;color:#22d3ee}
.dropdown:focus-within .dropdown-menu,.dropdown:hover .dropdown-menu{display:block}
/* Hamburger */
.hamburger{display:none;width:38px;height:34px;gap:5px;flex-direction:column;justify-content:center;align-items:center;cursor:pointer;margin-left:12px}
.hamburger span{display:block;width:26px;height:3px;background:#fff;border-radius:2px}
/* Mobile */
@media(max-width:900px){
  .nav-links{position:fixed;right:12px;top:60px;background:#0b1220;border:1px solid #1f2937;border-radius:14px;padding:10px;flex-direction:column;align-items:stretch;gap:6px;display:none;max-width:85vw}
  .nav-links.show{display:flex}
  .dropdown-menu{position:relative;top:auto;left:auto;border:none;box-shadow:none;background:transparent;padding:0;margin-left:8px}
  .dropdown-menu a{padding:6px 10px}
  .hamburger{display:flex}
}

.header-spacer{height:100px}

/* ===== CONTENT ===== */
.container{width:100%;max-width:1100px;background:#fff;border-radius:20px;padding:20px 20px 26px;box-shadow:0 8px 20px rgba(0,0,0,.15);margin:0 12px 40px}
.tools{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.tools input,.tools select{padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
.btn{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border:none;border-radius:10px;padding:10px 14px;text-decoration:none;display:inline-block}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:14px}
@media(max-width:960px){.grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
.card{border:1px solid #eee;border-radius:14px;background:#fff;box-shadow:0 4px 10px rgba(0,0,0,.06);overflow:hidden}
.thumb{width:100%;aspect-ratio:16/9;display:block;object-fit:cover;background:#f3f4f6}
.card-body{padding:14px}
.addr{color:#334155;font-size:.95rem;margin-bottom:8px}
.desc{color:#475569;white-space:pre-wrap}
.pager{display:flex;gap:6px;justify-content:center;margin-top:16px}
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#374151;background:#fff}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.empty{text-align:center;color:#475569;padding:18px 0}
</style>
</head>
<body>

<div class="navbar">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown" tabindex="0">
      <a href="javascript:void(0)" aria-haspopup="true" aria-expanded="false">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu" role="menu">
        <li><a href="register_user.php" role="menuitem">สมาชิก</a></li>
        <li><a href="register_photographer.php" role="menuitem">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown" tabindex="0">
      <a href="javascript:void(0)" aria-haspopup="true" aria-expanded="false">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu" role="menu">
        <li><a href="login_user.php" role="menuitem">สมาชิก</a></li>
        <li><a href="login_photographer.php" role="menuitem">ช่างภาพ</a></li>
        <li><a href="login_admin.php" role="menuitem">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" onclick="toggleMenu()" aria-label="toggle navigation" aria-controls="navLinks" aria-expanded="false"><span></span><span></span><span></span></div>
</div>

<div class="header-spacer"></div>

<div class="container">
  <form method="get" class="tools" style="width:100%">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อ/ที่อยู่/คำอธิบาย…">
    <select name="sort">
      <option value="name_asc"  <?= $sort==='name_asc' ? 'selected':'' ?>>A → Z</option>
      <option value="name_desc" <?= $sort==='name_desc' ? 'selected':'' ?>>Z → A</option>
    </select>
    <button class="btn" type="submit">ค้นหา</button>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">ยังไม่มีข้อมูล</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($rows as $r):
        $resolved = resolve_image($r, $DOCROOT, $BASE_URL);
        $name = $r['location_name'] ?? '';
        $addr = $r['address'] ?? '';
        $desc = $r['description'] ?? '';
        $mapQ = ($addr ? ($name.' '.$addr) : $name);
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($mapQ);
      ?>
      <div class="card">
        <img class="thumb" src="<?= h($resolved['web']) ?>" alt="<?= h($name ?: 'Location') ?>" onerror="this.onerror=null;this.src='<?= h(placeholder_svg($name)) ?>';">
        <div class="card-body">
          <h3><?= h($name) ?></h3>
          <?php if($addr): ?><div class="addr">📍 <?= h($addr) ?></div><?php endif; ?>
          <?php if($desc): ?><div class="desc"><?= nl2br(h($desc)) ?></div><?php endif; ?>
          <div style="margin-top:10px"><a class="btn" href="<?= h($mapUrl) ?>" target="_blank" rel="noopener noreferrer">🗺️ เปิดใน Google Maps</a></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php for($i=1;$i<=$pages;$i++):
          $qs = ['q'=>$q,'sort'=>$sort,'page'=>$i]; ?>
          <?php if ($i==$page): ?>
            <span class="active"><?= $i ?></span>
          <?php else: ?>
            <a href="?<?= h(http_build_query($qs)) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function toggleMenu(){
  const nav = document.getElementById('navLinks');
  const burger = document.querySelector('.hamburger');
  const open = nav.classList.toggle('show');
  burger.setAttribute('aria-expanded', open ? 'true':'false');
}
// ปิดเมนูเมื่อคลิกข้างนอก
document.addEventListener('click', (e)=>{
  const nav = document.getElementById('navLinks');
  const burger = document.querySelector('.hamburger');
  if(!nav) return;
  if(!nav.contains(e.target) && !burger.contains(e.target)){
    nav.classList.remove('show');
    burger.setAttribute('aria-expanded','false');
  }
});
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
