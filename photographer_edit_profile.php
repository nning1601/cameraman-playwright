<?php
session_start();
require_once 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit();
}
$photographer_id = (int)$_SESSION['photographer_id'];

/* ---------- เงื่อนไขรูปโปรไฟล์ ---------- */
const PF_MAX_BYTES = 5 * 1024 * 1024;        // ≤ 5MB
const PF_MIN_W = 300;  const PF_MIN_H = 300; // อย่างน้อย 300×300 px
const PF_MAX_W = 2000; const PF_MAX_H = 2000;// ไม่เกิน 2000×2000 px
$PF_ALLOWED_EXT  = ['jpg','jpeg','png','gif','webp'];
$PF_ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp'];
$EXT_BY_MIME = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

$update_error = '';
$update_success = '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function file_exists_in_uploads($filename){ return !empty($filename) && is_file(__DIR__.'/uploads/'.$filename); }
function safe_filename($n){ return preg_replace('/[^A-Za-z0-9_\.-]/','_', (string)$n); }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

function check_csrf(){
    global $update_error;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $update_error = 'คำขอไม่ถูกต้อง (CSRF)';
            return false;
        }
    }
    return true;
}

/* ===== โหลดอีเมลปัจจุบันไว้ใช้เช็คตอนอัปเดต ===== */
$stmtCur = $conn->prepare("SELECT email FROM photographer WHERE photographer_id=?");
$stmtCur->bind_param("i", $GLOBALS['photographer_id']);
$stmtCur->execute();
$curRes = $stmtCur->get_result();
$curRow = $curRes->fetch_assoc();
$current_email = trim($curRow['email'] ?? '');
$stmtCur->close();

/* ===== ลบรูปภาพ (จากตาราง photo) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo_id'])) {
    if (check_csrf()) {
        $delete_photo_id = (int)$_POST['delete_photo_id'];
        $stmt_check = $conn->prepare("SELECT image_name FROM photo WHERE photo_id=? AND photographer_id=? LIMIT 1");
        if (!$stmt_check) {
            $update_error = 'ไม่สามารถเตรียมคำสั่งลบรูปภาพได้';
        } else {
            $stmt_check->bind_param("ii", $delete_photo_id, $photographer_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check && $result_check->num_rows === 1) {
                $photo = $result_check->fetch_assoc();
                $file_path = __DIR__ . '/uploads/' . $photo['image_name'];

                $stmt_del = $conn->prepare("DELETE FROM photo WHERE photo_id=? AND photographer_id=?");
                if ($stmt_del) {
                    $stmt_del->bind_param("ii", $delete_photo_id, $photographer_id);
                    if ($stmt_del->execute()) {
                        if (is_file($file_path)) { @unlink($file_path); }
                        $update_success = "ลบรูปภาพเรียบร้อยแล้ว";
                    } else {
                        $update_error = "ลบรูปภาพไม่สำเร็จ";
                    }
                    $stmt_del->close();
                } else {
                    $update_error = "ไม่สามารถเตรียมคำสั่งลบได้";
                }
            } else {
                $update_error = "ไม่พบรูปภาพหรือไม่มีสิทธิ์ลบ";
            }
            $stmt_check->close();
        }
    }
}

/* ===== บันทึกแก้ไขโปรไฟล์ ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_photo_id'])) {
    if (check_csrf()) {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email_in   = trim($_POST['email'] ?? '');
        $email      = ($email_in === '') ? $current_email : $email_in;
        $price_rate = $_POST['price_rate'] === '' ? null : (float)$_POST['price_rate'];

        // ตรวจสอบข้อมูลพื้นฐาน
        if ($first_name === '' || $last_name === '' || $email === '') {
            $update_error = "กรุณากรอกชื่อ, นามสกุล, และอีเมลให้ครบถ้วน";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $update_error = "อีเมลไม่ถูกต้อง";
        } elseif (!is_null($price_rate) && $price_rate < 0) {
            $update_error = "ราคาเรทต้องไม่ติดลบ";
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            $update_error = "รูปแบบเบอร์โทรไม่ถูกต้อง";
        }

        // อีเมลซ้ำ
        if (!$update_error) {
            $chk = $conn->prepare("SELECT 1 FROM photographer WHERE email=? AND photographer_id<>?");
            $chk->bind_param("si", $email, $photographer_id);
            $chk->execute();
            $dup = $chk->get_result();
            if ($dup && $dup->num_rows > 0) { $update_error = "อีเมลนี้ถูกใช้แล้ว"; }
            $chk->close();
        }

        // อัปโหลดภาพโปรไฟล์ (กำหนดขนาดรูป + ตรวจนามสกุลไฟล์)
        $profile_image_path = null;
        if (!$update_error && !empty($_FILES['profile_image']) && ($_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE)) {
            if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $size = (int)$_FILES['profile_image']['size'];
                if ($size > PF_MAX_BYTES) {
                    $update_error = "ไฟล์มีขนาดเกิน 5MB";
                } else {
                    // ตรวจนามสกุลจากชื่อไฟล์
                    $extFromName = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    global $PF_ALLOWED_EXT, $PF_ALLOWED_MIME, $EXT_BY_MIME;
                    if (!in_array($extFromName, $PF_ALLOWED_EXT, true)) {
                        $update_error = "นามสกุลไฟล์ไม่ถูกต้อง (อนุญาต: ".implode(', ', $PF_ALLOWED_EXT).")";
                    } else {
                        // ตรวจ MIME จริงของไฟล์
                        $mime = '';
                        if (class_exists('finfo')) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime  = $finfo->file($_FILES['profile_image']['tmp_name']) ?: '';
                        }
                        if (!$mime) {
                            // fallback จากนามสกุล
                            $map=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                            $mime = $map[$extFromName] ?? '';
                        }
                        if (!in_array($mime, $PF_ALLOWED_MIME, true)) {
                            $update_error = "ชนิดไฟล์ไม่รองรับ (อนุญาต jpg, png, gif, webp)";
                        } else {
                            // ตรวจว่า extension ตรงกับ MIME
                            $extByMime = $EXT_BY_MIME[$mime] ?? null;
                            if ($extByMime && !in_array($extFromName, [$extByMime, 'jpeg'], true)) {
                                $update_error = "นามสกุลไฟล์ไม่ตรงกับชนิดไฟล์";
                            } else {
                                // ตรวจขนาดพิกเซล
                                $wh = @getimagesize($_FILES['profile_image']['tmp_name']);
                                if (!$wh) {
                                    $update_error = "ไม่สามารถอ่านไฟล์รูปได้";
                                } else {
                                    [$w,$h] = $wh;
                                    if ($w < PF_MIN_W || $h < PF_MIN_H) {
                                        $update_error = "ขนาดภาพเล็กเกินไป (อย่างน้อย ".PF_MIN_W."×".PF_MIN_H." พิกเซล)";
                                    } elseif ($w > PF_MAX_W || $h > PF_MAX_H) {
                                        $update_error = "ขนาดภาพใหญ่เกินไป (ไม่เกิน ".PF_MAX_W."×".PF_MAX_H." พิกเซล)";
                                    } else {
                                        // ผ่านทุกเงื่อนไข -> ย้ายไฟล์
                                        $upload_dir = __DIR__ . '/uploads/';
                                        if(!is_dir($upload_dir)) { @mkdir($upload_dir,0755,true); }
                                        $base = safe_filename(pathinfo($_FILES['profile_image']['name'], PATHINFO_FILENAME));
                                        $new_filename = 'profile_' . $photographer_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . ($extByMime ?: $extFromName);
                                        $target_path = $upload_dir . $new_filename;

                                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
                                            @chmod($target_path, 0644);
                                            $profile_image_path = $new_filename;

                                            // ลบไฟล์เก่า (ถ้ามีและไม่ใช่ default)
                                            $stmt_old = $conn->prepare("SELECT profile_image_path FROM photographer WHERE photographer_id=?");
                                            if ($stmt_old) {
                                                $stmt_old->bind_param("i",$photographer_id);
                                                $stmt_old->execute();
                                                $result_old = $stmt_old->get_result();
                                                if ($result_old && $result_old->num_rows === 1) {
                                                    $old_data = $result_old->fetch_assoc();
                                                    $old_file = $old_data['profile_image_path'] ?? '';
                                                    if ($old_file && $old_file !== 'images/default_user.png') {
                                                        $old_path = $upload_dir . $old_file;
                                                        if (is_file($old_path)) { @unlink($old_path); }
                                                    }
                                                }
                                                $stmt_old->close();
                                            }
                                        } else {
                                            $update_error = "ไม่สามารถอัปโหลดรูปโปรไฟล์ได้";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $update_error = "อัปโหลดไฟล์ผิดพลาด (รหัส: ".$_FILES['profile_image']['error'].")";
            }
        }

        // เขียนฐานข้อมูล
        if (!$update_error) {
            $conn->begin_transaction();
            try {
                if ($profile_image_path) {
                    $stmt = $conn->prepare("UPDATE photographer SET first_name=?, last_name=?, phone=?, email=?, price_rate=?, profile_image_path=? WHERE photographer_id=?");
                    // types: s s s s d s i
                    $stmt->bind_param("ssssdsi", $first_name, $last_name, $phone, $email, $price_rate, $profile_image_path, $photographer_id);
                } else {
                    $stmt = $conn->prepare("UPDATE photographer SET first_name=?, last_name=?, phone=?, email=?, price_rate=? WHERE photographer_id=?");
                    // types: s s s s d i
                    $stmt->bind_param("ssssdi", $first_name, $last_name, $phone, $email, $price_rate, $photographer_id);
                }

                if (!$stmt->execute()) { throw new Exception($stmt->error); }
                $stmt->close();

                // ถ้าเปลี่ยนอีเมล ให้ sync ตาราง login (type_id=2)
                if ($email !== $current_email) {
                    $stmtL = $conn->prepare("UPDATE login SET username=? WHERE photographer_id=? AND type_id=2");
                    $stmtL->bind_param("si", $email, $photographer_id);
                    if (!$stmtL->execute()) { throw new Exception($stmtL->error); }
                    $stmtL->close();
                }

                $conn->commit();
                $update_success = "แก้ไขข้อมูลโปรไฟล์เรียบร้อยแล้ว";
                $current_email = $email;
            } catch (Throwable $e) {
                $conn->rollback();
                $update_error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: ".h($e->getMessage());
            }
        }
    }
}

/* ===== โหลดข้อมูลแสดงผล ===== */
$stmt=$conn->prepare("SELECT first_name,last_name,phone,email,price_rate,profile_image_path FROM photographer WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows!==1){ echo "ไม่พบข้อมูลช่างภาพ"; exit(); }
$photographer=$result->fetch_assoc();
$stmt->close();

$firstName = $photographer['first_name'] ?? '';
$lastName  = $photographer['last_name']  ?? '';

$categories=['wedding','pre-wedding','portrait','product','event'];
$photos_by_category=[];

$stmt_photo=$conn->prepare("SELECT photo_id,image_name,image_type FROM photo WHERE photographer_id=? AND image_type=? ORDER BY photo_id DESC LIMIT 10");
if ($stmt_photo) {
    foreach($categories as $cat){
        $stmt_photo->bind_param("is",$photographer_id,$cat);
        $stmt_photo->execute();
        $result_photo=$stmt_photo->get_result();
        $photos_by_category[$cat]=$result_photo ? $result_photo->fetch_all(MYSQLI_ASSOC) : [];
    }
    $stmt_photo->close();
} else {
    foreach($categories as $cat){ $photos_by_category[$cat]=[]; }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แก้ไขโปรไฟล์ช่างภาพ - Cameraman</title>
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
.container{width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px}
.card{
  width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb
}
.card h1,.card h2{margin:0 0 12px;color:#0b1220;font-weight:900}
label{display:block;margin-bottom:6px;font-weight:800;color:#0b1220}
.input,.file,.number,.email{
  width:100%;padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;margin-bottom:12px
}
.btn{appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:900;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.btn-ghost{background:#f8fafc;border:1px solid #e5e7eb}
.message{padding:10px 12px;border-radius:12px;font-weight:800;margin-bottom:10px}
.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.success{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}
.profile-image{
  display:block;margin:0 auto 14px;width:150px;height:150px;object-fit:cover;border-radius:16px;border:4px solid rgba(255,255,255,.6);box-shadow:0 6px 18px rgba(0,0,0,.15);background:#f3f4f6
}
.notice{font-size:12px;color:#475569;font-weight:700;margin:-6px 0 8px}
.photos-section{margin-top:10px}
.photos-section h2{border-left:6px solid #0b1220;padding-left:12px;margin-bottom:10px;text-transform:capitalize}
.photos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}
.photo-card{
  background:#fff;border-radius:16px;overflow:hidden;border:1px solid #eef2f7;
  box-shadow:0 8px 18px rgba(0,0,0,.06);position:relative;transition:transform .2s,box-shadow .2s
}
.photo-card:hover{transform:translateY(-4px);box-shadow:0 14px 28px rgba(0,0,0,.1)}
.photo-card img{width:100%;height:130px;object-fit:cover;display:block}
.photo-desc{padding:8px;font-size:13px;color:#374151;text-align:center;text-transform:capitalize}
.photo-actions{position:absolute;top:6px;right:6px;display:flex;gap:6px}
.photo-actions form{margin:0}
.photo-actions button,.photo-actions a{
  background:rgba(255,255,255,0.9);border:1px solid #e5e7eb;color:#0b1220;font-weight:800;border-radius:8px;
  padding:6px 8px;cursor:pointer;text-decoration:none;font-size:12px
}
.photo-actions button:hover,.photo-actions a:hover{background:#0b1220;color:#fff;border-color:transparent}
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .wrapper{padding:110px 12px 32px}
  .photos-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}
  .photo-card img{height:110px}
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
      <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="photographer_bookings_crud.php">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">การมัดจำ</a>
      <a href="photographer_edit_profile.php" class="active">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h1>แก้ไขโปรไฟล์ช่างภาพ</h1>
      <?php if($update_error): ?>
        <div class="message error"><?= h($update_error) ?></div>
      <?php elseif($update_success): ?>
        <div class="message success"><?= h($update_success) ?></div>
      <?php endif; ?>

      <?php if(file_exists_in_uploads($photographer['profile_image_path'])): ?>
        <img src="uploads<?= (substr($photographer['profile_image_path'],0,1)==='/'?'':'/') . h($photographer['profile_image_path']) ?>" alt="รูปโปรไฟล์" class="profile-image">
      <?php endif; ?>

      <form action="" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <label>ชื่อ</label>
        <input class="input" type="text" name="first_name" required value="<?= h($photographer['first_name']) ?>">

        <label>นามสกุล</label>
        <input class="input" type="text" name="last_name" required value="<?= h($photographer['last_name']) ?>">

        <label>เบอร์โทร</label>
        <input class="input" type="text" name="phone" value="<?= h($photographer['phone']) ?>">

        <label>อีเมล</label>
        <input class="email" type="email" name="email" required value="<?= h($photographer['email']) ?>">

        <label>ราคาเรท (บาท/วัน)</label>
        <input class="number" type="number" step="0.01" min="0" name="price_rate" value="<?= h($photographer['price_rate']) ?>">

        <label>เปลี่ยนรูปโปรไฟล์</label>
        <div class="notice">
          รองรับ JPG/PNG/GIF/WEBP, ≤ 5MB, ขนาดอย่างน้อย <?= PF_MIN_W ?>×<?= PF_MIN_H ?> และไม่เกิน <?= PF_MAX_W ?>×<?= PF_MAX_H ?> พิกเซล
        </div>
        <input class="file" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">

        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:8px">
          <button type="reset" class="btn btn-ghost">ล้างข้อมูล</button>
          <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
        </div>
      </form>
    </section>

    <?php foreach($categories as $category): ?>
      <section class="card photos-section">
        <h2><a href="upload_photo.php?image_type=<?= urlencode($category) ?>" style="text-decoration:none;color:inherit"><?= str_replace('-', ' ', h($category)) ?></a></h2>
        <?php if(!empty($photos_by_category[$category])): ?>
          <div class="photos-grid">
            <?php foreach($photos_by_category[$category] as $photo): ?>
              <div class="photo-card">
                <img src="uploads/<?= h($photo['image_name']) ?>" alt="<?= h($photo['image_type']) ?>">
                <div class="photo-desc"><?= h($photo['image_type']) ?></div>
                <div class="photo-actions">
                  <a href="edit_photo.php?photo_id=<?= (int)$photo['photo_id'] ?>">แก้ไข</a>
                  <form action="" method="post" onsubmit="return confirm('ยืนยันการลบรูปภาพนี้?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="delete_photo_id" value="<?= (int)$photo['photo_id'] ?>">
                    <button type="submit">ลบ</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p style="color:#6b7280;margin:0">ยังไม่มีรูปภาพในหมวดนี้</p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
