<?php
require 'db.php';

// ดึงข้อมูลรูปทั้งหมดจากตาราง photographer
$sql = "SELECT profile_image, profile_image_path, portfolio_images FROM photographer";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รูปช่างภาพทั้งหมด</title>
<style>
body { font-family:sans-serif; background:#f5f5f5; padding:20px; }
.photographer { margin-bottom:30px; background:white; padding:15px; border-radius:10px; box-shadow:0 3px 6px rgba(0,0,0,0.1); }
.profile-img { width:120px; height:120px; object-fit:cover; border-radius:10px; border:2px solid #ccc; margin-bottom:10px; }
.portfolio { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
.portfolio img { width:100px; height:100px; object-fit:cover; border-radius:8px; border:1px solid #aaa; }
</style>
</head>
<body>

<h1>รูปช่างภาพทั้งหมด</h1>

<?php if($result && $result->num_rows>0): ?>
    <?php while($row = $result->fetch_assoc()): ?>
        <div class="photographer">
            <?php
            // รูปโปรไฟล์
            $profile = $row['profile_image_path'] ?? $row['profile_image'];
            if(!empty($profile)){
                if(strpos($profile,'uploads/')===false) $profile='uploads/'.$profile;
            } else {
                $profile='default-avatar.png';
            }
            ?>
            <img class="profile-img" src="<?= htmlspecialchars($profile) ?>" alt="โปรไฟล์">

            <?php
            // รูป portfolio
            if(!empty($row['portfolio_images'])):
                $images = explode(',', $row['portfolio_images']);
            ?>
                <div class="portfolio">
                <?php foreach($images as $img):
                    $img = trim($img);
                    if($img != ''){
                        if(strpos($img,'uploads/')===false) $img='uploads/'.$img;
                ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="portfolio">
                <?php } endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <p>ไม่พบรูปช่างภาพ</p>
<?php endif; ?>

</body>
</html>
