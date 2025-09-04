<?php
session_start();
require 'db.php'; // เชื่อมต่อฐานข้อมูล

// ตรวจสอบว่าผู้ใช้ล็อกอินหรือยัง
if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// ดึงข้อมูลผู้ใช้จากฐานข้อมูล
$sql = "SELECT first_name, username, profile_image FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// กำหนดชื่อและอีเมล
$firstName = $user['first_name'] ?? "ลูกค้า";
$username = $user['username'] ?? "guest@example.com";

// ตรวจสอบและตั้งค่ารูปโปรไฟล์
$uploadDir = __DIR__ . '/uploads/';     // Path จริงในเครื่อง server
$webUploadPath = 'uploads/';            // Path สำหรับ browser
$fileName = $user['profile_image'] ?? '';
$filePath = $uploadDir . $fileName;
$fileWebPath = $webUploadPath . $fileName;

if (!empty($fileName) && file_exists($filePath)) {
    $profileImage = $fileWebPath;
} else {
    $profileImage = 'images/default_user.png';
}

// *** DEBUG (เปิดดูตอนทดสอบเท่านั้น) ***
// echo "ไฟล์ภาพที่ได้จากฐานข้อมูล: $fileName<br>";
// echo "ตำแหน่งจริงในเครื่อง: $filePath<br>";
// echo "ตำแหน่งที่ใช้ในหน้าเว็บ: $profileImage<br>";
// echo "ตรวจสอบว่าไฟล์มีอยู่: " . (file_exists($filePath) ? '✅ พบไฟล์' : '❌ ไม่พบไฟล์') . "<br>";
// exit;

?>
