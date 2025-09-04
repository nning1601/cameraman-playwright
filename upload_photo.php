<?php
session_start();
require_once 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit();
}

$photographer_id = (int)$_SESSION['photographer_id'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== ดึงชื่อช่างภาพ ===== */
$firstName = '';
$photographer_name = 'ไม่ทราบชื่อ';
$stmt = $conn->prepare("SELECT first_name, last_name FROM photographer WHERE photographer_id=?");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
    $row = $res->fetch_assoc();
    $firstName = $row['first_name'] ?? '';
    $photographer_name = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
}
$stmt->close();

/* ===== Config ===== */
$max_per_type   = 10;
$allowed_types  = ['wedding','pre-wedding','portrait','product','event'];
$allowed_ext    = ['jpg','jpeg','png','gif','webp'];
$allowed_mimes  = ['image/jpeg','image/png','image/gif','image/webp'];

/* กำหนด “ขนาดรูป” */
$min_w = 600;   $min_h = 600;     // เล็กกว่านี้ไม่รับ
$max_w = 1920;  $max_h = 1920;    // ใหญ่กว่านี้จะย่ออัตโนมัติ (ยกเว้น GIF)

/* ===== Get type ===== */
$selected_type = $_GET['image_type'] ?? '';
if (!in_array($selected_type, $allowed_types, true)) { die('❌ ประเภทของรูปภาพไม่ถูกต้อง'); }

/* ===== Counters ===== */
$upload_error   = '';
$upload_success = '';

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM photo WHERE photographer_id=? AND image_type=?");
$count_stmt->bind_param("is", $photographer_id, $selected_type);
$count_stmt->execute();
$count_result  = $count_stmt->get_result();
$current_count = (int)($count_result->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

/* ===== Helpers: รูปภาพ (GD) ===== */
function img_open($path, $mime){
  switch ($mime) {
    case 'image/jpeg': return imagecreatefromjpeg($path);
    case 'image/png':  return imagecreatefrompng($path);
    case 'image/webp': return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null;
    // GIF: ไม่เปิดเพื่อย่อ (กันเสียแอนิเมชัน) คืน null ให้ข้ามการย่อ
    default: return null;
  }
}
function img_save($im, $mime, $path, $quality=85){
  if(!$im) return false;
  switch ($mime) {
    case 'image/jpeg':
      if(function_exists('imageinterlace')) imageinterlace($im, true);
      return imagejpeg($im, $path, $quality);
    case 'image/png':
      // quality PNG: 0 (ดีที่สุด) - 9 (ย่อมากสุด) เลือก 6 พอเหมาะ
      imagesavealpha($im, true);
      return imagepng($im, $path, 6);
    case 'image/webp':
      return function_exists('imagewebp') ? imagewebp($im, $path, $quality) : false;
    default:
      return false;
  }
}
function img_autorotate_jpeg($im, $path){
  if(!function_exists('exif_read_data')) return $im;
  $exif = @exif_read_data($path);
  if(!$exif || !isset($exif['Orientation'])) return $im;
  $o = (int)$exif['Orientation'];
  if($o === 3)      { $im = imagerotate($im, 180, 0); }
  elseif($o === 6)  { $im = imagerotate($im, -90, 0); }
  elseif($o === 8)  { $im = imagerotate($im, 90, 0); }
  return $im;
}
function fit_box($w,$h,$max_w,$max_h){
  $rw = $max_w / $w; $rh = $max_h / $h; $r = min(1, $rw, $rh);
  $nw = (int)round($w * $r); $nh = (int)round($h * $r);
  return [$nw,$nh];
}

/* ===== Upload ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image_type = $_POST['image_type'] ?? '';
    if (!in_array($image_type, $allowed_types, true)) {
        $upload_error = "ประเภทของรูปภาพไม่ถูกต้อง";
    } elseif (empty($_FILES['photo_image']['name'][0])) {
        $upload_error = "กรุณาเลือกไฟล์รูปภาพ";
    } else {
        $uploaded_count = count($_FILES['photo_image']['name']);
        if ($current_count + $uploaded_count > $max_per_type) {
            $upload_error = "ประเภทนี้สามารถอัปโหลดได้ไม่เกิน {$max_per_type} รูป (ปัจจุบันมี {$current_count} รูป)";
        } else {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) { @mkdir($upload_dir,0755,true); }

            $success_count = 0;
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($_FILES['photo_image']['name'] as $key => $filename) {
                if ($_FILES['photo_image']['error'][$key] !== UPLOAD_ERR_OK) {
                    $upload_error .= "เกิดข้อผิดพลาดกับไฟล์ " . h($filename) . "<br>";
                    continue;
                }

                $tmp  = $_FILES['photo_image']['tmp_name'][$key];
                $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mime = $finfo->file($tmp) ?: '';

                if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mimes, true)) {
                    $upload_error .= h($filename) . " ไม่ใช่ไฟล์รูปภาพที่อนุญาต<br>";
                    continue;
                }

                $info = @getimagesize($tmp);
                if ($info === false) {
                    $upload_error .= h($filename) . " ไม่ใช่ไฟล์รูปภาพที่ถูกต้อง<br>";
                    continue;
                }
                [$w,$h] = $info;

                // ตรวจขนาดขั้นต่ำ
                if ($w < $min_w || $h < $min_h) {
                    $upload_error .= h($filename) . " เล็กเกินไป (ขั้นต่ำ {$min_w}x{$min_h}px) — ขนาดจริง {$w}x{$h}px<br>";
                    continue;
                }

                $new_filename = 'photo_' . $photographer_id . '_' . time() . '_' . $key . '.' . $ext;
                $target_path  = $upload_dir . $new_filename;

                // กรณีเกินขนาดสูงสุด -> ย่ออัตโนมัติ (ยกเว้น GIF)
                $need_resize = ($w > $max_w || $h > $max_h);

                if ($need_resize) {
                    if ($mime === 'image/gif') {
                        // ไม่ย่อ GIF เพื่อคงแอนิเมชัน
                        $upload_error .= h($filename) . " ใหญ่เกินกำหนด (สูงสุด {$max_w}x{$max_h}px) และไม่รองรับการย่ออัตโนมัติสำหรับ GIF<br>";
                        continue;
                    }
                    $src = img_open($tmp, $mime);
                    if (!$src) {
                        $upload_error .= "ไม่สามารถเปิดไฟล์ " . h($filename) . " เพื่อย่อขนาดได้<br>";
                        continue;
                    }

                    // EXIF autorotate สำหรับ JPEG
                    if ($mime === 'image/jpeg') {
                        $src = img_autorotate_jpeg($src, $tmp);
                        // อัปเดตขนาดอีกครั้งเผื่อหมุน
                        $w = imagesx($src); $h = imagesy($src);
                    }

                    [$nw,$nh] = fit_box($w,$h,$max_w,$max_h);
                    $dst = imagecreatetruecolor($nw,$nh);

                    // preserve alpha สำหรับ PNG/WebP
                    if ($mime === 'image/png' || $mime === 'image/webp') {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                    }

                    imagecopyresampled($dst, $src, 0,0, 0,0, $nw,$nh, $w,$h);
                    imagedestroy($src);

                    if (!img_save($dst, $mime, $target_path, 85)) {
                        imagedestroy($dst);
                        $upload_error .= "บันทึกไฟล์ที่ย่อแล้วของ " . h($filename) . " ไม่สำเร็จ<br>";
                        continue;
                    }
                    imagedestroy($dst);
                } else {
                    // ขนาดไม่เกิน: move ธรรมดา (และ autorotate เฉพาะตอนแสดงผล ไม่จำเป็นต้องหมุนตอนเซฟ)
                    if (!move_uploaded_file($tmp, $target_path)) {
                        $upload_error .= "อัปโหลดไฟล์ " . h($filename) . " ไม่สำเร็จ<br>";
                        continue;
                    }
                }

                // บันทึก DB
                $stmt = $conn->prepare("INSERT INTO photo (photographer_id, image_name, image_type) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $photographer_id, $new_filename, $image_type);
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $upload_error .= "บันทึกฐานข้อมูลของ " . h($filename) . " ไม่สำเร็จ<br>";
                    @unlink($target_path);
                }
                $stmt->close();
            }

            if ($success_count > 0) {
                $upload_success = "✅ อัปโหลดสำเร็จ {$success_count} รูป";
            }
        }
    }
}

/* ===== Modal data (สำหรับเด้งป๊อปอัพผลลัพธ์) ===== */
$popup_text = '';
$popup_kind = ''; // success | error
if ($upload_success !== '') {
  $popup_text = $upload_success;
  $popup_kind = 'success';
} elseif ($upload_error !== '') {
  $popup_text = $upload_error;
  $popup_kind = 'error';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>อัปโหลดรูปภาพ - Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;font-family:'Prompt',sans-serif;color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
/* ===== Navbar ===== */
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

/* ===== Layout ===== */
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:800px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h1{margin:0 0 6px;color:#0b1220;font-weight:900;text-align:center}
.subtitle{text-align:center;color:#475569;font-size:14px;margin-bottom:8px}
.message{padding:10px 12px;border-radius:12px;font-weight:800;text-align:center}
.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.success{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
label{display:block;margin-top:10px;font-weight:800;color:#0b1220}
.input-file{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;margin-top:6px}
.hint{font-size:12px;color:#475569;margin-top:6px}
.btn{appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:900;cursor:pointer;width:100%;margin-top:14px}
.btn-primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.back{display:block;text-align:center;margin-top:12px;color:#0b1220;text-decoration:none;font-weight:800}
.back:hover{text-decoration:underline}

/* ===== Pretty Center Modals ===== */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(2,6,23,.55);
  backdrop-filter:saturate(160%) blur(2px);
  display:none; align-items:center; justify-content:center; z-index:10000;
}
.modal{
  width:min(520px,92vw); background:#fff; border:1px solid #eef0f3;
  border-radius:18px; box-shadow:0 30px 60px rgba(2,6,23,.3);
  padding:18px 18px 16px; transform:translateY(12px) scale(.98); opacity:0;
  transition:.22s cubic-bezier(.2,.8,.2,1);
}
.modal.show{ transform:translateY(0) scale(1); opacity:1; }
.modal-icon{
  width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:26px; margin-right:10px;
}
.modal-header{ display:flex; align-items:center; gap:10px; margin-bottom:6px }
.modal-title{ font-size:18px; font-weight:800; color:#0b1220 }
.modal-text{ color:#374151; font-size:14px; line-height:1.6; margin:8px 0 2px }
.modal-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px }
.btn-md{ padding:10px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#fff; font-weight:700; cursor:pointer }
.btn-md:hover{ background:#f8fafc }

.modal.success .modal-icon{ background:#dcfce7; color:#166534 }
.modal.success .modal-title{ color:#166534 }
.modal.error .modal-icon{ background:#fee2e2; color:#991b1b }
.modal.error .modal-title{ color:#991b1b }
.modal.info .modal-icon{ background:#e0e7ff; color:#3730a3 }
.modal.info .modal-title{ color:#3730a3 }

.modal-backdrop.show{ display:flex; }

/* ===== Responsive ===== */
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .wrapper{padding:110px 12px 32px}
}
</style>
</head>
<body>

<!-- ===== Result Modal (หลังอัปโหลด) ===== -->
<div id="resultModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div id="resultBox" class="modal" tabindex="-1">
    <div class="modal-header">
      <div id="resultIcon" class="modal-icon">ℹ️</div>
      <div class="modal-title" id="resultTitle">แจ้งเตือน</div>
    </div>
    <div class="modal-text" id="resultText">ข้อความแจ้งเตือน</div>
    <div class="modal-actions">
      <button type="button" class="btn-md" id="resultOk">ตกลง</button>
    </div>
  </div>
</div>

<!-- ===== Confirm Upload Modal (ก่อนอัปโหลด) ===== -->
<div id="confirmModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div id="confirmBox" class="modal info" tabindex="-1">
    <div class="modal-header">
      <div class="modal-icon">ℹ️</div>
      <div class="modal-title">ยืนยันการอัปโหลด</div>
    </div>
    <div class="modal-text" id="confirmText">ต้องการอัปโหลดรูปภาพใช่ไหม?</div>
    <div class="modal-actions">
      <button type="button" class="btn-md" id="confirmCancel">ยกเลิก</button>
      <button type="button" class="btn-md" id="confirmOk" style="background:#2563eb;color:#fff;border-color:#2563eb">ยืนยันอัปโหลด</button>
    </div>
  </div>
</div>

<!-- ===== Navbar ===== -->
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($firstName) ?></span>
      <a href="photographer_bookings.php">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_edit_profile.php" class="active">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h1>อัปโหลดรูปภาพ — <?= h(ucfirst(str_replace('-', ' ', $selected_type))) ?></h1>
      <div class="subtitle">เจ้าของรูป: <?= h($photographer_name) ?> • มีอยู่แล้ว: <?= (int)$current_count ?>/<?= (int)$max_per_type ?></div>

      <?php if($upload_error): ?>
        <div class="message error"><?= $upload_error ?></div>
      <?php elseif($upload_success): ?>
        <div class="message success"><?= h($upload_success) ?></div>
      <?php endif; ?>

      <form id="uploadForm" action="upload_photo.php?image_type=<?= h(urlencode($selected_type)) ?>" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="image_type" value="<?= h($selected_type) ?>">
        <label>เลือกรูปภาพ (jpg, jpeg, png, gif, webp) — เลือกได้หลายรูป</label>
        <input id="fileInput" class="input-file" type="file" name="photo_image[]" accept="image/*" multiple required>
        <div class="hint">กำหนดขนาดรูป: ขั้นต่ำ <b><?= (int)$min_w ?>×<?= (int)$min_h ?></b> px • ระบบจะย่ออัตโนมัติไม่เกิน <b><?= (int)$max_w ?>×<?= (int)$max_h ?></b> px (GIF ไม่ย่อ)</div>
        <button id="uploadBtn" type="button" class="btn btn-primary">อัปโหลด</button>
      </form>

      <a href="photographer_edit_profile.php" class="back">← กลับไปหน้าแก้ไขโปรไฟล์</a>
    </section>
  </div>
</div>

<script>
/* ===== Result Modal (แสดงหลัง POST) ===== */
(function(){
  const text  = <?= json_encode($popup_text, JSON_UNESCAPED_UNICODE) ?>;
  const kind  = <?= json_encode($popup_kind, JSON_UNESCAPED_UNICODE) ?>; // success | error | ''

  if(!text) return;

  const backdrop = document.getElementById('resultModal');
  const box      = document.getElementById('resultBox');
  const icon     = document.getElementById('resultIcon');
  const title    = document.getElementById('resultTitle');
  const body     = document.getElementById('resultText');
  const okBtn    = document.getElementById('resultOk');

  box.classList.remove('success','error','info');
  if(kind==='success'){ box.classList.add('success'); icon.textContent='✅'; title.textContent='สำเร็จ'; }
  else { box.classList.add('error'); icon.textContent='⛔'; title.textContent='ไม่สามารถทำรายการ'; }

  // อนุญาต HTML line break ที่ฝั่ง PHP ใส่มา
  body.innerHTML = text;

  const open = ()=>{
    backdrop.classList.add('show');
    setTimeout(()=> box.classList.add('show'), 10);
    box.focus();
  };
  const close = ()=>{
    box.classList.remove('show');
    setTimeout(()=> backdrop.classList.remove('show'), 180);
  };
  okBtn.addEventListener('click', close);
  backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) close(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

  open();
})();

/* ===== Confirm Upload (ก่อน submit) ===== */
(function(){
  const form     = document.getElementById('uploadForm');
  const btn      = document.getElementById('uploadBtn');
  const input    = document.getElementById('fileInput');

  const backdrop = document.getElementById('confirmModal');
  const box      = document.getElementById('confirmBox');
  const textEl   = document.getElementById('confirmText');
  const cancelBt = document.getElementById('confirmCancel');
  const okBt     = document.getElementById('confirmOk');

  const currentCount = <?= (int)$current_count ?>;
  const maxPerType   = <?= (int)$max_per_type ?>;

  const open = (msg)=>{
    textEl.innerHTML = msg.replace(/\n/g,'<br>');
    backdrop.classList.add('show');
    setTimeout(()=> box.classList.add('show'), 10);
    box.focus();
  };
  const close = ()=>{
    box.classList.remove('show');
    setTimeout(()=> backdrop.classList.remove('show'), 180);
  };
  cancelBt.addEventListener('click', close);
  backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) close(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

  btn.addEventListener('click', ()=>{
    const files = input.files;
    if(!files || files.length===0){
      open('กรุณาเลือกรูปภาพอย่างน้อย 1 รูป');
      return;
    }
    const total = currentCount + files.length;
    if(total > maxPerType){
      open(`อัปโหลดไม่ได้<br>ประเภทนี้จำกัด ${maxPerType} รูป<br>ปัจจุบันมี ${currentCount} รูป และคุณเลือกเพิ่มอีก ${files.length} รูป`);
      return;
    }

    // แสดงรายการยืนยันแบบสั้นๆ
    const nameList = Array.from(files).slice(0,5).map(f=>`• ${f.name}`).join('<br>');
    const more     = files.length>5 ? `<br>...และอีก ${files.length-5} ไฟล์` : '';
    open(`ยืนยันการอัปโหลดรูปภาพจำนวน <b>${files.length}</b> รูป ใช่ไหม?<br><br>${nameList}${more}`);
    okBt.onclick = ()=>{ close(); form.submit(); };
  });
})();
</script>

</body>
</html>
<?php $conn->close(); ?>
