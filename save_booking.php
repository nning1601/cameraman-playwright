<?php
// save_booking.php
session_start();
require 'db.php';

mysqli_set_charset($conn, 'utf8mb4');

// ===== อนุญาตเฉพาะ POST =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method Not Allowed');
}

// ===== ต้องล็อกอินเป็นผู้ใช้ก่อน =====
if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// ===== Helper =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): bool {
    $bind = [$types];
    foreach ($values as $k => $v) { $bind[] = &$values[$k]; } // bind by reference
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

// ===== รับค่าจากฟอร์ม =====
$photographer_id = (int)($_POST['photographer_id'] ?? 0);
$job_date        = trim($_POST['job_date'] ?? '');
$job_time        = trim($_POST['job_time'] ?? '');
$location        = trim($_POST['location'] ?? '');

// ===== ตรวจสอบค่าขั้นต้น =====
$errors = [];
if ($photographer_id <= 0) $errors[] = 'ไม่พบรหัสช่างภาพ';
if ($job_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $job_date)) $errors[] = 'รูปแบบวันที่ไม่ถูกต้อง (YYYY-MM-DD)';
if ($job_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $job_time)) $errors[] = 'รูปแบบเวลาไม่ถูกต้อง (HH:MM)';
if ($location === '') $errors[] = 'กรุณากรอกสถานที่';

if ($errors) {
    // ส่งกลับฟอร์มเดิม (หรือจะโชว์หน้า error ก็ได้)
    $qs = http_build_query([
        'photographer_id' => $photographer_id,
        'err' => implode(' | ', $errors)
    ]);
    header("Location: booking_step1.php?".$qs);
    exit;
}

// ===== ตรวจสอบว่ามีช่างภาพคนนี้จริงไหม =====
$ck = $conn->prepare("SELECT 1 FROM photographer WHERE photographer_id = ?");
$ck->bind_param("i", $photographer_id);
$ck->execute();
$exists = (bool)$ck->get_result()->fetch_row();
$ck->close();

if (!$exists) {
    $qs = http_build_query([
        'photographer_id' => $photographer_id,
        'err' => 'ไม่พบช่างภาพ'
    ]);
    header("Location: booking_step1.php?".$qs);
    exit;
}

// ===== ตรวจซ้ำเวลาจอง (กันจองชน) =====
// ถ้ามีคอลัมน์ job_time และมีค่าเวลา -> ตรวจวัน + เวลา
// ถ้าไม่มีเวลา -> ตรวจเฉพาะวัน
$job_has_time = has_col($conn, 'job', 'job_time');

if ($job_has_time && $job_time !== '') {
    $sqlDup = "SELECT COUNT(*) AS c FROM job WHERE photographer_id=? AND job_date=? AND job_time=? AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')";
    $stDup = $conn->prepare($sqlDup);
    $stDup->bind_param("iss", $photographer_id, $job_date, $job_time);
} else {
    $sqlDup = "SELECT COUNT(*) AS c FROM job WHERE photographer_id=? AND job_date=? AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')";
    $stDup = $conn->prepare($sqlDup);
    $stDup->bind_param("is", $photographer_id, $job_date);
}
$stDup->execute();
$dup = (int)($stDup->get_result()->fetch_assoc()['c'] ?? 0);
$stDup->close();

if ($dup > 0) {
    $qs = http_build_query([
        'photographer_id' => $photographer_id,
        'err' => 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น'
    ]);
    header("Location: booking_step1.php?".$qs);
    exit;
}

// ===== เตรียม Insert แบบยืดหยุ่นตามคอลัมน์ที่มีจริง =====
$cols = ['photographer_id', 'job_date', 'location', 'status'];
$vals = [$photographer_id, $job_date, $location, 'รอดำเนินการ'];
$types = 'isss';

// ถ้ามี user_id
if (has_col($conn, 'job', 'user_id')) {
    $cols[] = 'user_id';
    $vals[] = $user_id;
    $types .= 'i';
}

// ถ้ามี job_time และส่งเวลามา
if ($job_has_time) {
    $cols[] = 'job_time';
    $vals[] = ($job_time !== '' ? $job_time.':00' : null); // แปลง HH:MM -> HH:MM:SS (ถ้าจำเป็น)
    $types .= 's';
}

// ถ้ามี created_at และไม่มี default ก็ใส่ NOW() (แบบ expression)
$use_created_expr = false;
if (has_col($conn, 'job', 'created_at')) {
    // ใส่เป็น expression NOW() จะไม่ใช้ bind
    $use_created_expr = true;
}

// สร้าง SQL
$colList = implode(',', array_map(fn($c)=>"`$c`", $cols));
$placeholders = implode(',', array_fill(0, count($cols), '?'));
if ($use_created_expr) {
    $sqlIns = "INSERT INTO job ($colList, `created_at`) VALUES ($placeholders, NOW())";
} else {
    $sqlIns = "INSERT INTO job ($colList) VALUES ($placeholders)";
}

$ins = $conn->prepare($sqlIns);
stmt_bind_params($ins, $types, $vals);

if (!$ins->execute()) {
    $qs = http_build_query([
        'photographer_id' => $photographer_id,
        'err' => 'บันทึกไม่สำเร็จ: '.$ins->error
    ]);
    $ins->close();
    header("Location: booking_step1.php?".$qs);
    exit;
}

$newJobId = $ins->insert_id;
$ins->close();

// ===== สำเร็จ: PRG Redirect ไปหน้าสำเร็จหรือปฏิทิน/รายละเอียด =====
// ถ้าคุณมีหน้า success โดยเฉพาะ ให้ชี้ไปที่ booking_success.php
if (is_file(__DIR__.'/booking_success.php')) {
    header("Location: booking_success.php?job_id=".$newJobId);
} else {
    // fallback: กลับไปหน้าตารางงานของช่างภาพ
    header("Location: photographer_schedule.php?photographer_id=".$photographer_id."&ok=1");
}
exit;
