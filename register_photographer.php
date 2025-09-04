<?php
session_start();
require_once 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

$error = "";
$popup_success = false;
$welcome_name  = "";

function detect_mime($tmpPath) {
    if (function_exists('mime_content_type')) {
        return @mime_content_type($tmpPath);
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $type = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            return $type;
        }
    }
    return null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $raw_password = $_POST['password'] ?? '';
    $price_rate   = (float)($_POST['price_rate'] ?? 0);
    $expertise_id = (int)($_POST['expertise_id'] ?? 0);

    if ($first_name === '' || $last_name === '' || $phone === '' || $email === '' || $raw_password === '' || $price_rate <= 0 || $expertise_id <= 0) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } elseif (strlen($raw_password) < 6) {
        $error = "รหัสผ่านต้องยาวอย่างน้อย 6 ตัวอักษร";
    }

    if ($error === "") {
        $check = $conn->prepare("SELECT 1 FROM photographer WHERE phone = ? OR email = ?");
        if ($check) {
            $check->bind_param("ss", $phone, $email);
            $check->execute();
            $dup = $check->get_result();
            if ($dup && $dup->num_rows > 0) {
                $error = "เบอร์โทรหรืออีเมลนี้ถูกใช้แล้ว";
            }
            $check->close();
        } else {
            $error = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูลซ้ำ";
        }
    }

    $profile_image_path = "";
    if ($error === "" && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $error = "อัปโหลดรูปไม่สำเร็จ (รหัสข้อผิดพลาด: " . (int)$_FILES['profile_image']['error'] . ")";
        } else {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $tmpPath = $_FILES['profile_image']['tmp_name'];
            $mime = detect_mime($tmpPath);

            if ($mime === null || !in_array($mime, $allowed_types, true)) {
                $error = "อนุญาตเฉพาะไฟล์รูปภาพ JPG/PNG/GIF เท่านั้น";
            }

            if ($error === "" && ($_FILES['profile_image']['size'] > 5 * 1024 * 1024)) {
                $error = "ไฟล์รูปต้องไม่เกิน 5MB";
            }

            if ($error === "") {
                $info = @getimagesize($tmpPath);
                if ($info === false) {
                    $error = "ไฟล์นี้ไม่ใช่รูปภาพ";
                } else {
                    $w = (int)$info[0];
                    $h = (int)$info[1];
                    if ($w > 1200 || $h > 1200) {
                        $error = "ขนาดรูปต้องไม่เกิน 1200×1200 พิกเซล";
                    }
                }
            }

            if ($error === "") {
                $upload_dir = __DIR__ . "/uploads/";
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }
                $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
                $filename = "pf_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . ($safeExt ? "." . strtolower($safeExt) : "");
                $target_file = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                    $profile_image_path = $filename;
                } else {
                    $error = "เกิดข้อผิดพลาดในการย้ายไฟล์ที่อัปโหลด";
                }
            }
        }
    }

    if ($error === "") {
        $password_hash = password_hash($raw_password, PASSWORD_DEFAULT);
        $photographer_name = trim($first_name . ' ' . $last_name);

        $stmt1 = $conn->prepare("INSERT INTO photographer (first_name, last_name, photographer_name, phone, email, profile_image_path, price_rate, expertise_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt1) {
            $error = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง (INSERT photographer)";
        } else {
            $stmt1->bind_param("ssssssdi", $first_name, $last_name, $photographer_name, $phone, $email, $profile_image_path, $price_rate, $expertise_id);
            if ($stmt1->execute()) {
                $photographer_id = $stmt1->insert_id;
                $stmt1->close();

                $stmt2 = $conn->prepare("INSERT INTO login (username, password_hash, type_id, photographer_id) VALUES (?, ?, 2, ?)");
                if (!$stmt2) {
                    $error = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง (INSERT login)";
                } else {
                    $stmt2->bind_param("ssi", $email, $password_hash, $photographer_id);
                    if ($stmt2->execute()) {
                        $stmt2->close();
                        $popup_success = true;
                        $welcome_name  = $photographer_name !== "" ? $photographer_name : $email;
                    } else {
                        $stmt2->close();
                        $error = "บันทึกบัญชีเข้าใช้งานไม่สำเร็จ";
                    }
                }
            } else {
                $stmt1->close();
                if ($conn->errno == 1062) {
                    $error = "เบอร์โทรหรืออีเมลนี้ถูกใช้แล้ว";
                } else {
                    $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูลช่างภาพ";
                }
            }
        }
    }
}

$expertise_result = $conn->query("SELECT expertise_id, expertise_name FROM expertise ORDER BY expertise_id ASC");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สมัครช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:14px 28px;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:1000}
.logo{font-size:22px;font-weight:700;color:#fff;letter-spacing:.2px}
.nav-links{list-style:none;display:flex;gap:18px}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:15px;color:#fff;padding:10px 14px;border-radius:10px;transition:transform .15s ease,background .15s ease}
.nav-links a:hover{background:#22d3ee;color:#0f172a;transform:translateY(-1px)}
.dropdown-menu{position:absolute;top:100%;left:0;background:#ffffff;border:1px solid rgba(2,6,23,.08);border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);min-width:180px;display:none;flex-direction:column;overflow:hidden}
.dropdown-menu a{display:block;color:#0f172a;padding:12px 14px;border-radius:0}
.dropdown-menu a:hover{background:#0ea5e9;color:#fff}
.dropdown:hover .dropdown-menu{display:flex}
.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer}
.hamburger span{width:26px;height:3px;background:#fff;border-radius:2px}
@media (max-width:768px){
  .nav-links{position:fixed;top:58px;right:-100%;background:#0f172a;width:68vw;max-width:320px;height:calc(100vh - 58px);padding:18px;flex-direction:column;gap:8px;border-left:1px solid rgba(255,255,255,.08);transition:right .25s ease}
  .nav-links.show{right:0}
  .dropdown-menu{position:static;border:none;box-shadow:none;background:transparent}
  .dropdown-menu a{color:#e2e8f0;padding:10px 12px}
  .dropdown-menu a:hover{background:#22d3ee;color:#0f172a}
  .hamburger{display:flex}
}
.page-space{height:88px}
.register-box{background:#ffffff;padding:26px 20px 22px;border-radius:20px;box-shadow:0 20px 40px rgba(2,6,23,.18);border:1px solid rgba(2,6,23,.06);width:min(520px,94%);text-align:center;margin:24px auto 40px;transition:transform .2s}
.register-box:hover{transform:translateY(-3px)}
.register-box h2{color:#0f172a;font-size:26px;margin-bottom:6px}
.subtext{color:#6b7280;font-size:14px;margin-bottom:14px}
.register-box input,.register-box select{width:92%;padding:12px 14px;margin:8px 0;border:1px solid #d1d5db;border-radius:10px;font-size:15px;outline:none;transition:box-shadow .2s,border-color .2s}
.register-box input:focus,.register-box select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.register-box button{margin-top:6px;display:inline-block;width:95%;padding:12px 16px;border:none;border-radius:10px;cursor:pointer;background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;font-weight:700;font-size:16px;box-shadow:0 10px 20px rgba(2,6,23,.25);transition:transform .15s ease,opacity .15s ease}
.register-box button:hover{opacity:.95;transform:translateY(-1px)}
.error{color:#e11d48;background:#fff1f2;border:1px solid #fecdd3;padding:10px 12px;border-radius:10px;font-size:14px;margin:10px auto 0;width:92%}
.back-link{margin-top:14px;font-size:14px;color:#374151}
.back-link a{color:#2563eb;text-decoration:none;font-weight:700}
.back-link a:hover{text-decoration:underline}
.help{color:#475569;font-size:12px;margin-top:2px}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown">
      <a href="#">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="#">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" onclick="document.getElementById('navLinks').classList.toggle('show')"><span></span><span></span><span></span></div>
</div>

<div class="page-space"></div>

<div class="register-box" aria-label="สมัครช่างภาพ">
  <h2>สมัครช่างภาพ</h2>
  <div class="subtext">กรอกข้อมูลเพื่อเข้าร่วมเป็นช่างภาพบนแพลตฟอร์มของเรา</div>
  <?php if (!empty($error)): ?>
    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="text" name="first_name" placeholder="ชื่อ" required>
    <input type="text" name="last_name" placeholder="นามสกุล" required>
    <input type="text" name="phone" placeholder="เบอร์โทรศัพท์" required>
    <input type="email" name="email" placeholder="อีเมล" required>
    <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)" required>
    <input type="file" name="profile_image" accept="image/*" required>
    <div class="help">รูปโปรไฟล์ต้องเป็น JPG/PNG/GIF • ขนาดไฟล์ไม่เกิน 5MB • ขนาดรูปไม่เกิน 1200×1200 พิกเซล</div>
    <input type="number" step="0.01" min="0" name="price_rate" placeholder="เรทราคา (บาท)" required>
    <select name="expertise_id" required>
      <option value="">-- เลือกความถนัด --</option>
      <?php if ($expertise_result && $expertise_result->num_rows > 0): ?>
        <?php while ($row = $expertise_result->fetch_assoc()): ?>
          <option value="<?= (int)$row['expertise_id'] ?>"><?= htmlspecialchars($row['expertise_name'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endwhile; ?>
      <?php endif; ?>
    </select>
    <button type="submit">สมัครช่างภาพ</button>
  </form>
  <div class="back-link">มีบัญชีแล้ว? <a href="login_photographer.php">เข้าสู่ระบบ</a></div>
</div>

<?php if ($popup_success): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'สมัครสมาชิกเรียบร้อยแล้ว',
  text: 'ยินดีต้อนรับเข้าสู่ระบบ <?= htmlspecialchars($welcome_name, ENT_QUOTES, 'UTF-8'); ?>',
  confirmButtonText: 'ไปที่หน้าเข้าสู่ระบบ'
}).then(() => {
  window.location.href = 'login_photographer.php';
});
</script>
<?php endif; ?>

</body>
</html>
