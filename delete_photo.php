<?php
require 'db.php';

if(!isset($_GET['photo_id']) || !isset($_GET['id'])){
    die("ไม่พบรูปหรือรหัสช่างภาพ");
}

$photo_id = intval($_GET['photo_id']);
$photographer_id = intval($_GET['id']);

// ดึงชื่อไฟล์ก่อนลบ
$stmt = $conn->prepare("SELECT image_name FROM photo WHERE photo_id=?");
$stmt->bind_param("i",$photo_id);
$stmt->execute();
$res = $stmt->get_result();
if($row = $res->fetch_assoc()){
    $file = 'uploads/'.$row['image_name'];
    if(file_exists($file)) unlink($file);
}

// ลบจากฐานข้อมูล
$stmt2 = $conn->prepare("DELETE FROM photo WHERE photo_id=?");
$stmt2->bind_param("i",$photo_id);
$stmt2->execute();

// กลับไปหน้าแก้ไขช่างภาพ
header("Location: edit_photographer.php?id=$photographer_id");
exit;
?>

