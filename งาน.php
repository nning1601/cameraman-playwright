<?php
require 'db.php';

$sql = "SELECT first_name, last_name, profile_image FROM users ORDER BY user_id ASC";
$result = $conn->query($sql);

if (!$result) {
    die("เกิดข้อผิดพลาดในการดึงข้อมูล: " . $conn->error);
}

// โฟลเดอร์ที่เก็บรูปภาพจริงบนเซิร์ฟเวอร์ (ปรับให้ตรงกับโปรเจกต์คุณ)
$upload_dir_server = __DIR__ . '/uploads/';  

// โฟลเดอร์สำหรับ URL รูปภาพบนเว็บ (ปรับตามโครงสร้างเว็บ)
$upload_dir_web = 'uploads/'; 

$default_image = 'images/default_user.png';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แสดงรูปโปรไฟล์ผู้ใช้ทั้งหมด</title>
    <style>
        /* ... CSS เหมือนเดิม ... */
    </style>
</head>
<body>

<h1>รายชื่อผู้ใช้และรูปโปรไฟล์</h1>
<div class="user-list">
    <?php while ($user = $result->fetch_assoc()): 
        $img_path = $user['profile_image'];
        if (empty($img_path) || !file_exists($upload_dir_server . basename($img_path))) {
            $img_path = $default_image;
            $img_url = $default_image;
        } else {
            $img_url = $upload_dir_web . basename($img_path);
        }
    ?>
        <div class="user-card">
            <img src="<?= htmlspecialchars($img_url) ?>" alt="รูปโปรไฟล์ของ <?= htmlspecialchars($user['first_name']) ?>" class="profile-img" 
                onerror="this.onerror=null;this.src='<?= $default_image ?>';" />
            <div class="name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        </div>
    <?php endwhile; ?>
</div>

</body>
</html>
