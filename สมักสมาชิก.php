<?php
session_start();
require_once 'db.php'; // ต้องมี $conn = new mysqli(...);

// ---------- สร้างตารางถ้ายังไม่มี ----------
$conn->query("
CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(50) NOT NULL,
  last_name  VARCHAR(50) NOT NULL,
  address    VARCHAR(255) NOT NULL,
  phone      VARCHAR(20) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---------- CSRF Token ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- ค่าจากฟอร์ม (sticky) ----------
$first_name = '';
$last_name  = '';
$address    = '';
$phone      = '';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจ CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'ไม่สามารถยืนยันความถูกต้องของแบบฟอร์ม (CSRF)';
    }

    // รับค่า & trim
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $address    = trim($_POST['address']    ?? '');
    $phone      = trim($_POST['phone']      ?? '');

    // ---------- ตรวจสอบข้อมูล ----------
    if ($first_name === '' || mb_strlen($first_name) > 50) {
        $errors[] = 'กรุณากรอกชื่อ (ไม่เกิน 50 ตัวอักษร)';
    }
    if ($last_name === '' || mb_strlen($last_name) > 50) {
        $errors[] = 'กรุณากรอกนามสกุล (ไม่เกิน 50 ตัวอักษร)';
    }
    if ($address === '' || mb_strlen($address) > 255) {
        $errors[] = 'กรุณากรอกที่อยู่ (ไม่เกิน 255 ตัวอักษร)';
    }

    // เบอร์โทร: อนุญาตเฉพาะตัวเลขและ + - วงเล็บ เว้นวรรค / ความยาวรวมไม่เกิน 20
    // และต้องมีตัวเลขอย่างน้อย 9-15 หลัก
    $digitsOnly = preg_replace('/\D+/', '', $phone); // เอาเฉพาะตัวเลข
    if ($phone === '' || mb_strlen($phone) > 20 || strlen($digitsOnly) < 9 || strlen($digitsOnly) > 15) {
        $errors[] = 'กรุณากรอกเบอร์โทรให้ถูกต้อง (มีตัวเลข 9–15 หลัก ความยาวรวมไม่เกิน 20)';
    }

    // ---------- ถ้าไม่ error -> ตรวจซ้ำเบอร์โทร & บันทึก ----------
    if (!$errors) {
        // ตรวจซ้ำเบอร์โทร
        $chk = $conn->prepare("SELECT id FROM members WHERE phone = ?");
        $chk->bind_param("s", $phone);
        $chk->execute();
        $chkRes = $chk->get_result();
        if ($chkRes->num_rows > 0) {
            $errors[] = 'เบอร์โทรนี้ถูกใช้สมัครแล้ว';
        }
        $chk->close();
    }

    if (!$errors) {
        $stmt = $conn->prepare("INSERT INTO members (first_name, last_name, address, phone) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $first_name, $last_name, $address, $phone);

        if ($stmt->execute()) {
            $success = true;
            // ล้างค่าเพื่อไม่ให้ค้างในฟอร์ม
            $first_name = $last_name = $address = $phone = '';
            // รีเฟรช CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf_token = $_SESSION['csrf_token'];
        } else {
            // จัดการ error จาก DB (เช่น unique phone)
            if ($conn->errno === 1062) {
                $errors[] = 'เบอร์โทรนี้ถูกใช้สมัครแล้ว';
            } else {
                $errors[] = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>สมัครสมาชิก</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root { --bg:#f6f7fb; --card:#ffffff; --primary:#6C63FF; --text:#222; --muted:#666; --danger:#d93025; --success:#0f9d58; }
*{box-sizing:border-box;font-family:'Prompt',sans-serif}
body{margin:0;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:var(--card);width:100%;max-width:680px;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:28px}
h1{margin:0 0 8px;font-size:28px}
p.subtitle{margin:0 0 22px;color:var(--muted)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-row.stretch{grid-template-columns:1fr}
label{display:block;font-weight:600;margin:10px 0 6px}
input[type="text"], textarea{width:100%;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#fafafa;outline:none}
input[type="text"]:focus, textarea:focus{border-color:var(--primary);background:#fff;box-shadow:0 0 0 4px rgba(108,99,255,.12)}
textarea{min-height:90px;resize:vertical}
.actions{display:flex;gap:10px;margin-top:18px;align-items:center}
button{border:0;border-radius:12px;padding:12px 18px;font-weight:700;cursor:pointer}
button.primary{background:var(--primary);color:#fff}
.small{font-size:13px;color:var(--muted)}
.alert{padding:12px 14px;border-radius:12px;margin-bottom:14px}
.alert.error{background:#fde8e6;color:var(--danger);border:1px solid #f5b5ae}
.alert.success{background:#e6f4ea;color:var(--success);border:1px solid #b7e1c1}
.required{color:var(--danger)}
.note{font-size:12px;color:#888;margin-top:6px}
</style>
</head>
<body>
  <div class="card" role="region" aria-labelledby="heading">
    <h1 id="heading">สมัครสมาชิก</h1>
    <p class="subtitle">กรอกข้อมูลให้ครบถ้วนตามจริง เพื่อให้ติดต่อกลับได้ง่าย</p>

    <?php if ($success): ?>
      <div class="alert success">สมัครสมาชิกสำเร็จแล้ว ✔</div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert error">
        <?php foreach ($errors as $e): ?>
          • <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

      <div class="form-row">
        <div>
          <label for="first_name">ชื่อ <span class="required">*</span></label>
          <input type="text" id="first_name" name="first_name" maxlength="50" required
                 placeholder="เช่น ไตรรัตน์"
                 value="<?= htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label for="last_name">นามสกุล <span class="required">*</span></label>
          <input type="text" id="last_name" name="last_name" maxlength="50" required
                 placeholder="เช่น จุไร"
                 value="<?= htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div class="form-row stretch">
        <div>
          <label for="address">ที่อยู่ <span class="required">*</span></label>
          <textarea id="address" name="address" maxlength="255" required placeholder="เลขที่, หมู่บ้าน/ถนน, ตำบล/แขวง, อำเภอ/เขต, จังหวัด, รหัสไปรษณีย์"><?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="note">สูงสุด 255 ตัวอักษร</div>
        </div>
      </div>

      <div class="form-row stretch">
        <div>
          <label for="phone">เบอร์โทร <span class="required">*</span></label>
          <input type="text" id="phone" name="phone" maxlength="20" required
                 placeholder="เช่น 0812345678"
                 value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>">
          <div class="note">รองรับเฉพาะตัวเลข (9–15 หลัก) และอักขระ + - วงเล็บ เว้นวรรค</div>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="primary">สมัครสมาชิก</button>
        <span class="small">ข้อมูลของคุณจะถูกจัดเก็บอย่างปลอดภัย</span>
      </div>
    </form>
  </div>

<script>
// จำกัดเบอร์ให้เป็นตัวเลข/สัญลักษณ์พื้นฐาน และกันเว้นวรรคเกิน
document.getElementById('phone').addEventListener('input', function(e){
  // อนุญาตตัวเลข เว้นวรรค + - ( )
  this.value = this.value.replace(/[^0-9+\-\s()]/g, '').slice(0, 20);
});
</script>
</body>
</html>

