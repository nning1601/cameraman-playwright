<?php
session_start();
$isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['photographer_id']) || !empty($_SESSION['admin_id']);
if (!$isLoggedIn) { header("Location: login_user.php"); exit; }
@include_once 'db.php';
if (!isset($conn) || !$conn) {
  $conn = mysqli_connect('localhost','root','', 'cameraman');
  if (!$conn) { die('เชื่อมต่อฐานข้อมูลล้มเหลว: '.mysqli_connect_error()); }
}
mysqli_set_charset($conn,'utf8mb4');

/* ==== กำหนดเงื่อนไขไฟล์รูปสถานที่ ==== */
const LOC_MAX_BYTES = 3 * 1024 * 1024;                  // ≤ 3MB
const LOC_MIN_W = 600;  const LOC_MIN_H = 400;          // ขั้นต่ำ 600×400 px
const LOC_MAX_W = 3000; const LOC_MAX_H = 3000;         // ไม่เกิน 3000×3000 px
$LOC_ALLOWED_EXT  = ['jpg','jpeg','png','webp'];        // นามสกุลไฟล์ที่อนุญาต
$LOC_ALLOWED_MIME = ['image/jpeg','image/png','image/webp']; // MIME ที่อนุญาต
$EXT_BY_MIME = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

auto_schema($conn);

function auto_schema(mysqli $c){
  $c->query("CREATE TABLE IF NOT EXISTS locations (
    location_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_location_name (location_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  $cols = [];
  if ($rs = $c->query("SHOW COLUMNS FROM locations")) { while($r=$rs->fetch_assoc()){ $cols[strtolower($r['Field'])]=true; } $rs->close(); }
  if (!isset($cols['image_path'])) { $c->query("ALTER TABLE locations ADD COLUMN image_path VARCHAR(255) NULL AFTER description"); }
  if (!isset($cols['created_by_user_id'])) { $c->query("ALTER TABLE locations ADD COLUMN created_by_user_id INT NULL AFTER image_path"); }
  if (!isset($cols['created_at'])) { $c->query("ALTER TABLE locations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function v($s){ return trim((string)$s); }
function current_first_name(mysqli $c){
  $first = 'ผู้ใช้งาน';
  if (!empty($_SESSION['user_id'])) {
    $st=$c->prepare("SELECT first_name FROM users WHERE user_id=?"); $st->bind_param('i',$_SESSION['user_id']); $st->execute(); $st->bind_result($first); $st->fetch(); $st->close();
  } elseif (!empty($_SESSION['photographer_id'])) {
    $st=$c->prepare("SELECT first_name FROM photographer WHERE photographer_id=?"); $st->bind_param('i',$_SESSION['photographer_id']); $st->execute(); $st->bind_result($first); $st->fetch(); $st->close();
  } elseif (!empty($_SESSION['admin_id'])) {
    $st=$c->prepare("SELECT first_name FROM admin WHERE admin_id=?"); $st->bind_param('i',$_SESSION['admin_id']); $st->execute(); $st->bind_result($first); $st->fetch(); $st->close();
  }
  return $first ?: 'ผู้ใช้งาน';
}
function uploads_dir(){ $d = __DIR__.'/uploads/locations'; if(!is_dir($d)) @mkdir($d,0775,true); return $d; }
function unlink_if_local($rel){
  if(!$rel) return; $p = realpath(__DIR__.'/'.$rel); $base = realpath(uploads_dir());
  if ($p && $base && str_starts_with($p, $base)) @unlink($p);
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$firstName = current_first_name($conn);

$mode_edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = null;
if ($mode_edit_id > 0) {
  $st = $conn->prepare("SELECT location_id, location_name, address, description, image_path FROM locations WHERE location_id=?");
  $st->bind_param('i',$mode_edit_id); $st->execute(); $edit_row = $st->get_result()->fetch_assoc(); $st->close();
  if (!$edit_row) $mode_edit_id = 0;
}

$name = $edit_row['location_name'] ?? '';
$addr = $edit_row['address'] ?? '';
$desc = $edit_row['description'] ?? '';
$preview_rel = $edit_row['image_path'] ?? '';
$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { $errors[]='โทเคนไม่ถูกต้อง'; }
  $action = $_POST['action'] ?? '';

  if ($action === 'delete') {
    $del_id = (int)($_POST['location_id'] ?? 0);
    if ($del_id>0 && !$errors){
      $st = $conn->prepare("SELECT image_path FROM locations WHERE location_id=?"); $st->bind_param('i',$del_id); $st->execute(); $st->bind_result($img); $st->fetch(); $st->close();
      $st = $conn->prepare("DELETE FROM locations WHERE location_id=?"); $st->bind_param('i',$del_id);
      if ($st->execute()) { unlink_if_local($img); $success='ลบรายการเรียบร้อย'; }
      else { $errors[]='ลบไม่สำเร็จ: '.$st->error; }
      $st->close();
    }
  } else {
    $loc_id = (int)($_POST['location_id'] ?? 0);
    $name = v($_POST['location_name'] ?? '');
    $addr = v($_POST['address'] ?? '');
    $desc = v($_POST['description'] ?? '');
    if ($name==='') $errors[] = 'กรุณากรอกชื่อสถานที่';
    if (mb_strlen($name)>150) $errors[]='ชื่อสถานที่ต้องไม่เกิน 150 ตัวอักษร';
    if (mb_strlen($addr)>255) $errors[]='ที่อยู่ต้องไม่เกิน 255 ตัวอักษร';

    $imgRel = null; $replaceImage = false;
    if (!empty($_FILES['image']['name'])){
      $err = $_FILES['image']['error'];
      if ($err===UPLOAD_ERR_OK){
        $tmp  = $_FILES['image']['tmp_name'];
        $size = (int)$_FILES['image']['size'];

        // 1) ขนาดไฟล์
        if ($size > LOC_MAX_BYTES) {
          $errors[]='ไฟล์ใหญ่เกิน 3MB';
        }

        // 2) นามสกุลไฟล์จากชื่อไฟล์
        $extFromName = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($extFromName, $LOC_ALLOWED_EXT, true)) {
          $errors[] = 'นามสกุลไฟล์ไม่ถูกต้อง (อนุญาต: '.implode(', ', $LOC_ALLOWED_EXT).')';
        }

        // 3) MIME จริงของไฟล์
        $mime = null;
        if (class_exists('finfo')){
          $fi=new finfo(FILEINFO_MIME_TYPE);
          $mime=$fi->file($tmp) ?: '';
        }
        if (!$mime) { // fallback แบบเดิม
          $map=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
          $mime=$map[$extFromName]??'';
        }
        if ($mime && !in_array($mime, $LOC_ALLOWED_MIME, true)) {
          $errors[]='ชนิดไฟล์ไม่ถูกต้อง (รองรับ: JPG, PNG, WEBP)';
        }

        // 4) ตรวจให้ extension สอดคล้องกับ MIME (เคร่งครัด)
        $extByMime = $EXT_BY_MIME[$mime] ?? null;
        if ($mime && $extByMime && !in_array($extFromName, [$extByMime, 'jpeg'], true)) {
          $errors[]='นามสกุลไฟล์ไม่ตรงกับชนิดไฟล์';
        }

        // 5) ตรวจขนาดพิกเซล
        if (!$errors){
          $wh = @getimagesize($tmp);
          if (!$wh) {
            $errors[]='ไม่สามารถอ่านไฟล์รูปได้';
          } else {
            [$w,$h] = $wh;
            if ($w < LOC_MIN_W || $h < LOC_MIN_H) {
              $errors[]='ขนาดภาพเล็กเกินไป (อย่างน้อย '.LOC_MIN_W.'×'.LOC_MIN_H.' พิกเซล)';
            } elseif ($w > LOC_MAX_W || $h > LOC_MAX_H) {
              $errors[]='ขนาดภาพใหญ่เกินไป (ไม่เกิน '.LOC_MAX_W.'×'.LOC_MAX_H.' พิกเซล)';
            }
          }
        }

        // 6) ย้ายไฟล์เมื่อผ่านทุกเงื่อนไข
        if (!$errors){
          $ext = $extByMime ?: $extFromName;
          $dir = uploads_dir();
          $fname='loc_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
          $abs=$dir.'/'.$fname; $rel='uploads/locations/'.$fname;
          if (move_uploaded_file($tmp,$abs)){
            @chmod($abs,0644);
            $imgRel=$rel; $replaceImage=true; $preview_rel=$rel;
          } else {
            $errors[]='ย้ายไฟล์ไม่สำเร็จ';
          }
        }
      } else {
        $errors[]='อัปโหลดไฟล์ล้มเหลว (code '.$err.')';
      }
    }

    if (!$errors){
      if ($action==='create'){
        $created_by = (int)($_SESSION['user_id'] ?? ($_SESSION['photographer_id'] ?? ($_SESSION['admin_id'] ?? 0)));
        $st = $conn->prepare("INSERT INTO locations (location_name,address,description,image_path,created_by_user_id) VALUES (?,?,?,?,?)");
        if(!$st){ $errors[]='เตรียมคำสั่งล้มเหลว: '.$conn->error; }
        else {
          $st->bind_param('ssssi',$name,$addr,$desc,$imgRel,$created_by);
          if ($st->execute()){
            $success='เพิ่มสถานที่เรียบร้อย';
            $name=$addr=$desc=''; $preview_rel='';
          } else {
            $errors[]='บันทึกล้มเหลว: '.$st->error;
            if($imgRel) unlink_if_local($imgRel);
          }
          $st->close();
        }
      } elseif ($action==='update' && $loc_id>0){
        $st = $conn->prepare("SELECT image_path FROM locations WHERE location_id=?"); $st->bind_param('i',$loc_id); $st->execute(); $st->bind_result($oldImg); $st->fetch(); $st->close();
        if ($replaceImage){
          $st = $conn->prepare("UPDATE locations SET location_name=?, address=?, description=?, image_path=? WHERE location_id=?");
          $st->bind_param('ssssi',$name,$addr,$desc,$imgRel,$loc_id);
        } else {
          $st = $conn->prepare("UPDATE locations SET location_name=?, address=?, description=? WHERE location_id=?");
          $st->bind_param('sssi',$name,$addr,$desc,$loc_id);
        }
        if ($st->execute()){
          $success='แก้ไขรายการเรียบร้อย';
          if ($replaceImage && $oldImg) unlink_if_local($oldImg);
        } else {
          $errors[]='แก้ไขล้มเหลว: '.$st->error;
          if($replaceImage && $imgRel) unlink_if_local($imgRel);
        }
        $st->close();
      }
      // rotate CSRF
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $csrf = $_SESSION['csrf_token'];
    }
  }
}

$q = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$per = 9; $off = ($page-1)*$per;
$where = '1=1'; $binds = []; $types='';
if ($q !== ''){ $where = '(location_name LIKE CONCAT("%", ?, "%") OR address LIKE CONCAT("%", ?, "%"))'; $types='ss'; $binds=[ $q, $q ]; }

$total = 0;
if ($types==='') { $rs=$conn->query("SELECT COUNT(*) c FROM locations WHERE $where"); $total=(int)($rs? $rs->fetch_assoc()['c'] : 0); if($rs) $rs->close(); }
else { $st=$conn->prepare("SELECT COUNT(*) c FROM locations WHERE $where"); $st->bind_param($types, ...$binds); $st->execute(); $r=$st->get_result()->fetch_assoc(); $total=(int)($r['c'] ?? 0); $st->close(); }
$pages = max(1, (int)ceil($total/$per)); if ($page>$pages) $page=$pages; $off = ($page-1)*$per;

$list = [];
if ($types==='') { $sql="SELECT location_id, location_name, address, image_path, created_at FROM locations WHERE $where ORDER BY created_at DESC, location_id DESC LIMIT $per OFFSET $off"; $rs=$conn->query($sql); if($rs){ while($row=$rs->fetch_assoc()) $list[]=$row; $rs->close(); } }
else { $st=$conn->prepare("SELECT location_id, location_name, address, image_path, created_at FROM locations WHERE $where ORDER BY created_at DESC, location_id DESC LIMIT $per OFFSET $off"); $st->bind_param($types, ...$binds); $st->execute(); $rs=$st->get_result(); while($row=$rs->fetch_assoc()) $list[]=$row; $st->close(); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>เพิ่ม/แก้ไข/ลบ สถานที่แนะนำ - Cameraman</title>
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
.menu .logout{background:#ef4444}.menu .logout:hover{background:#dc2626;color:#fff}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h1{margin:0 0 12px;color:#0b1220;font-weight:900}
label{display:block;margin-top:12px;font-weight:800;color:#0b1220}
.input,.textarea,.file{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;margin-top:6px}
.textarea{min-height:110px;resize:vertical}
.btn{appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:900;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.btn-ghost{background:#f8fafc;border:1px solid #e5e7eb}
.message{padding:10px 12px;border-radius:12px;font-weight:800;margin-bottom:10px}
.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.success{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
.thumb{max-width:260px;max-height:170px;margin-top:10px;border-radius:12px;display:block;box-shadow:0 6px 18px rgba(0,0,0,.1);object-fit:cover}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.card2{border:1px solid #e5e7eb;border-radius:16px;padding:12px;box-shadow:0 6px 14px rgba(0,0,0,.08);background:#fff}
.item-img{width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:12px;border:1px solid #f1f5f9}
.item-title{font-weight:900;margin:8px 0 4px}
.item-addr{font-weight:600;color:#334155;font-size:14px}
.item-actions{display:flex;gap:8px;margin-top:10px}
.searchbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pagination{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px}
.pagebtn{padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#0f172a}
.pagebtn.active{background:#0ea5e9;color:#fff;border-color:#0284c7}
@media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:640px){.menu a,.menu .badge{font-size:13px;padding:7px 10px}.wrapper{padding:110px 12px 32px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="navbar"><div class="nav-inner"><div class="logo">📸 <span>Cameraman</span></div><div class="menu">
  <span class="badge">👋 สวัสดี, <?= h($firstName) ?></span>
  <a href="photographer_bookings.php">หน้าแรก</a>
  <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
  <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
  <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
  <a href="photographer_bookings_crud.php">การจองคิว</a>
  <a href="locations_add.php" class="active">สถานที่แนะนำ</a>
  <a href="photographer_deposits.php">การมัดจำ</a>
  <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div></div></div>

<div class="wrapper"><div class="container">
  <section class="card">
    <h1><?= $mode_edit_id? 'แก้ไขสถานที่ถ่ายภาพ' : 'เพิ่มสถานที่ถ่ายภาพแนะนำ' ?></h1>
    <?php if($success): ?><div class="message success">✅ <?= h($success) ?></div><?php endif; ?>
    <?php if($errors): ?><div class="message error"><ul style="margin:0 0 0 18px"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="location_id" value="<?= (int)$mode_edit_id ?>">
      <label>ชื่อสถานที่* (ไม่เกิน 150)</label>
      <input class="input" type="text" name="location_name" maxlength="150" value="<?= h($name) ?>" required>
      <label>ที่อยู่ (ไม่เกิน 255)</label>
      <input class="input" type="text" name="address" maxlength="255" value="<?= h($addr) ?>">
      <label>คำอธิบาย</label>
      <textarea class="textarea" name="description"><?= h($desc) ?></textarea>

      <label>
        รูปตัวอย่าง
        <span style="font-weight:700; color:#334155">
          (JPG/PNG/WEBP, ≤ 3MB, ขนาดอย่างน้อย <?= LOC_MIN_W ?>×<?= LOC_MIN_H ?> และไม่เกิน <?= LOC_MAX_W ?>×<?= LOC_MAX_H ?> พิกเซล)
        </span>
      </label>
      <input class="file" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
      <?php if($preview_rel): ?><img src="<?= h($preview_rel) ?>" class="thumb" alt="ตัวอย่างรูป"><?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:14px">
        <?php if($mode_edit_id): ?><a class="btn btn-ghost" href="locations_add.php">ยกเลิกแก้ไข</a><?php else: ?><button class="btn btn-ghost" type="reset">ล้างค่า</button><?php endif; ?>
        <button class="btn btn-primary" type="submit" name="action" value="<?= $mode_edit_id? 'update' : 'create' ?>"><?= $mode_edit_id? 'บันทึกการแก้ไข' : 'บันทึก' ?></button>
      </div>
    </form>
  </section>

  <section class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
      <h1 style="margin:0">รายการสถานที่ทั้งหมด</h1>
      <form class="searchbar" method="get" action="">
        <input type="text" class="input" style="min-width:220px" name="q" placeholder="ค้นหาชื่อ/ที่อยู่" value="<?= h($q) ?>">
        <button class="btn btn-ghost" type="submit">ค้นหา</button>
      </form>
    </div>

    <?php if(!$list): ?>
      <div class="message" style="background:#fff;border:1px dashed #cbd5e1;color:#334155">ยังไม่มีรายการ</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach($list as $it): ?>
          <div class="card2">
            <?php if(!empty($it['image_path'])): ?>
              <img class="item-img" src="<?= h($it['image_path']) ?>" alt="<?= h($it['location_name']) ?>">
            <?php else: ?>
              <div class="item-img" style="display:flex;align-items:center;justify-content:center;font-weight:800;opacity:.5">ไม่มีรูป</div>
            <?php endif; ?>
            <div class="item-title"><?= h($it['location_name']) ?></div>
            <div class="item-addr"><?= h($it['address'] ?: '-') ?></div>
            <div class="item-actions">
              <a class="btn btn-ghost" href="locations_add.php?edit=<?= (int)$it['location_id'] ?>">แก้ไข</a>
              <form method="post" onsubmit="return confirm('ยืนยันลบสถานที่นี้หรือไม่?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="location_id" value="<?= (int)$it['location_id'] ?>">
                <button class="btn" style="background:#fecaca;border:1px solid #fca5a5" type="submit" name="action" value="delete">ลบ</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="pagination">
        <?php for($i=1;$i<=$pages;$i++): $u='?'.http_build_query(['q'=>$q,'page'=>$i]); ?>
          <a class="pagebtn <?= $i==$page? 'active':'' ?>" href="<?= h($u) ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </section>
</div></div>

<script>
  // ป้องกัน double submit
  (function(){
    const forms = document.querySelectorAll('form[novalidate], form');
    forms.forEach(f=>{
      f.addEventListener('submit', function(e){
        if (this.__submitting) { e.preventDefault(); return false; }
        this.__submitting = true;
        setTimeout(()=>{ this.__submitting=false; }, 2000);
      });
    });
  })();
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
