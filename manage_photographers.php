<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn,'utf8mb4');

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function img_path($p){ $p=trim((string)$p); if($p==='') return 'images/default_user.png'; $p=str_replace('\\','/',$p); if(!preg_match('#^(uploads|images)/#i',$p) && !preg_match('#^https?://#i',$p)) $p='uploads/'.$p; return $p; }

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

function delete_photographer_by_id(mysqli $conn, int $pid){
  if ($stmt = $conn->prepare("DELETE FROM booking WHERE photographer_id=?")) { $stmt->bind_param("i",$pid); $stmt->execute(); $stmt->close(); }
  if ($r = $conn->query("SHOW TABLES LIKE 'photo'")) { if ($r->num_rows>0) { if ($stmt = $conn->prepare("DELETE FROM photo WHERE photographer_id=?")) { $stmt->bind_param("i",$pid); $stmt->execute(); $stmt->close(); } } $r->close(); }
  if ($r = $conn->query("SHOW TABLES LIKE 'photographerrating'")) { if ($r->num_rows>0) { if ($stmt = $conn->prepare("DELETE FROM photographerrating WHERE photographer_id=?")) { $stmt->bind_param("i",$pid); $stmt->execute(); $stmt->close(); } } $r->close(); }
  if ($stmt = $conn->prepare("DELETE FROM photographer WHERE photographer_id=?")) { $stmt->bind_param("i",$pid); $stmt->execute(); $stmt->close(); }
}

if (isset($_POST['ajax_delete'])) {
  $pid = (int)$_POST['ajax_delete'];
  delete_photographer_by_id($conn, $pid);
  echo "success";
  exit;
}

$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'id_desc';
$page = max(1,(int)($_GET['page'] ?? 1));
$per  = max(10,min(50,(int)($_GET['per'] ?? 12)));

$order = 'p.photographer_id DESC';
if($sort==='name_asc') $order='p.first_name ASC, p.last_name ASC';
if($sort==='name_desc') $order='p.first_name DESC, p.last_name DESC';
if($sort==='email_asc') $order='p.email ASC';
if($sort==='email_desc') $order='p.email DESC';

$where = 'WHERE 1=1';
$params=[]; $types='';
if($q!==''){
  $where.=" AND (p.first_name LIKE CONCAT('%',?,'%') OR p.last_name LIKE CONCAT('%',?,'%') OR p.email LIKE CONCAT('%',?,'%') OR p.phone LIKE CONCAT('%',?,'%'))";
  $params = array_fill(0,4,$q);
  $types  = 'ssss';
}

$cs = $conn->prepare("SELECT COUNT(*) c FROM photographer p $where");
if($types!==''){ $cs->bind_param($types,...$params); }
$cs->execute(); $count = (int)($cs->get_result()->fetch_assoc()['c'] ?? 0); $cs->close();

$pages = max(1,(int)ceil($count/$per)); if($page>$pages) $page=$pages; $off = ($page-1)*$per;

$sql = "SELECT p.photographer_id, p.first_name, p.last_name, p.email, p.phone, p.profile_image, p.profile_image_path, p.portfolio_images FROM photographer p $where ORDER BY $order LIMIT ? OFFSET ?";
$types2 = $types.'ii'; $params2 = $params; $params2[]=$per; $params2[]=$off;
$stmt = $conn->prepare($sql);
if($types!==''){ $stmt->bind_param($types2,...$params2); } else { $stmt->bind_param('ii',$per,$off); }
$stmt->execute(); $result=$stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>จัดการช่างภาพ - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
.navbar{width:100%;position:fixed;top:0;left:0;background:linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.burger{display:none}
@media (max-width:900px){.menu{display:none}.burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}.drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}.drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}.drawer a.active{background:#60a5fa;color:#fff}}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-danger{background:linear-gradient(90deg,#ef4444,#dc2626)}
.btn-sm{padding:6px 10px;border-radius:10px}
.table-wrap{overflow:auto;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
table{width:100%;border-collapse:collapse;min-width:980px;background:#fff}
thead th{background:#eef2ff;color:#0b1220;text-align:left;padding:12px;font-weight:900}
tbody td{padding:12px;border-top:1px solid #eee;vertical-align:middle}
.profile{width:60px;height:60px;border-radius:50%;object-fit:cover;box-shadow:0 4px 10px rgba(0,0,0,.12)}
.portfolio{display:flex;gap:6px;flex-wrap:wrap}
.portfolio img{width:58px;height:58px;object-fit:cover;border-radius:10px;border:1px solid #eee;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.input{padding:9px 12px;border:1px solid #e5e7eb;border-radius:12px}
.select{padding:9px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.pagination{display:flex;gap:6px;justify-content:center}
.pagination a{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#0f172a;font-weight:700}
.pagination a.active{background:#60a5fa;color:#fff;border-color:#3b82f6}
.count{font-weight:800;color:#0b1220}
</style>
</head>
<body>
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php" class="active">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">หน้าแรก</a>
  <a href="manage_users.php">สมาชิก</a>
  <a href="manage_photographers.php" class="active">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h2>รายการช่างภาพ</h2>
        <div class="toolbar">
          <form method="get" class="toolbar" action="manage_photographers.php">
            <input class="input" type="text" name="q" placeholder="ค้นหาชื่อ อีเมล โทร" value="<?= h($q) ?>">
            <select class="select" name="sort">
              <option value="id_desc" <?= $sort==='id_desc'?'selected':'' ?>>ล่าสุด</option>
              <option value="name_asc" <?= $sort==='name_asc'?'selected':'' ?>>ชื่อ ก-ฮ</option>
              <option value="name_desc" <?= $sort==='name_desc'?'selected':'' ?>>ชื่อ ฮ-ก</option>
              <option value="email_asc" <?= $sort==='email_asc'?'selected':'' ?>>อีเมล A-Z</option>
              <option value="email_desc" <?= $sort==='email_desc'?'selected':'' ?>>อีเมล Z-A</option>
            </select>
            <select class="select" name="per">
              <?php foreach([10,12,20,30] as $n): ?>
                <option value="<?= $n ?>" <?= $per==$n?'selected':'' ?>><?= $n ?>/หน้า</option>
              <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">ค้นหา</button>
          </form>
          <a class="btn btn-secondary" href="add_photographer.php">+ เพิ่มช่างภาพ</a>
        </div>
      </div>
      <div style="margin-top:6px" class="count">ทั้งหมด <?= (int)$count ?> รายการ</div>
      <div class="table-wrap" style="margin-top:12px">
        <table>
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th style="width:90px">โปรไฟล์</th>
              <th>ชื่อ-สกุล</th>
              <th>อีเมล</th>
              <th>เบอร์โทร</th>
              <th>Portfolio</th>
              <th style="width:220px">การจัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($result && $result->num_rows > 0): ?>
            <?php while($p = $result->fetch_assoc()):
              $pid = (int)$p['photographer_id'];
              $firstname = trim($p['first_name'] ?? '');
              $lastname  = trim($p['last_name'] ?? '');
              $fullname = trim($firstname.' '.$lastname);
              $email = $p['email'] ?? '';
              $phone = $p['phone'] ?? '';
              $profile = trim((string)($p['profile_image'] ?? ''));
              if ($profile==='') { $profile = trim((string)($p['profile_image_path'] ?? '')); }
              $ppath = img_path($profile);
              $portfolioThumbs = [];
              if (!empty($p['portfolio_images'])) { foreach (explode(',', $p['portfolio_images']) as $img) { $img = trim($img); if ($img==='') continue; $portfolioThumbs[] = img_path($img); } }
            ?>
              <tr id="row-<?= $pid ?>">
                <td><?= $pid ?></td>
                <td><img src="<?= h($ppath) ?>" class="profile" alt="profile"></td>
                <td><?= h($fullname !== '' ? $fullname : '-') ?></td>
                <td><?= h($email !== '' ? $email : '-') ?></td>
                <td><?= h($phone !== '' ? $phone : '-') ?></td>
                <td>
                  <?php if (!empty($portfolioThumbs)): ?>
                    <div class="portfolio">
                      <?php foreach($portfolioThumbs as $img): ?>
                        <img src="<?= h($img) ?>" alt="portfolio">
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span style="color:#9ca3af">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn btn-secondary btn-sm" href="edit_photographer.php?id=<?= $pid ?>">แก้ไข</a>
                  <button class="btn btn-danger btn-sm" onclick="deletePhotographer(<?= $pid ?>)">ลบ</button>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7" style="text-align:center;color:#9ca3af">ยังไม่มีข้อมูลช่างภาพ</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($pages>1): ?>
      <div class="pagination" style="margin-top:12px">
        <?php for($i=1;$i<=$pages;$i++): $qstr=http_build_query(['q'=>$q,'sort'=>$sort,'per'=>$per,'page'=>$i]); ?>
          <a class="<?= $i===$page?'active':'' ?>" href="?<?= h($qstr) ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<script>
const burgerBtn=document.getElementById('burgerBtn');const drawer=document.getElementById('drawerMenu');let drawerOpen=false;burgerBtn&&burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});
function deletePhotographer(pid){if(!confirm('ยืนยันลบช่างภาพนี้?'))return;const xhr=new XMLHttpRequest();xhr.open('POST','manage_photographers.php',true);xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');xhr.onload=function(){if(xhr.status===200&&xhr.responseText.trim()==='success'){const row=document.getElementById('row-'+pid);if(row)row.remove();alert('ลบช่างภาพเรียบร้อยแล้ว');}else{alert('เกิดข้อผิดพลาดในการลบช่างภาพ');}};xhr.onerror=function(){alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');};xhr.send('ajax_delete='+encodeURIComponent(pid));}
</script>
</body>
</html>
<?php
if (isset($result) && $result instanceof mysqli_result) { $result->free(); }
if (isset($stmt) && $stmt instanceof mysqli_stmt) { $stmt->close(); }
mysqli_close($conn);
