<?php
// users_list.php
session_start();
require 'db.php'; // ต้องเชื่อมต่อฐานข้อมูลเป็น $pdo หรือ $conn

// -----------------------------
// 1) ตรวจสอบสิทธิ์ admin
// -----------------------------
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

// -----------------------------
// 2) ฟังก์ชันช่วยเหลือ
// -----------------------------
function is_pdo($db) { return class_exists('PDO') && $db instanceof PDO; }
function is_mysqli($db) { return $db instanceof mysqli; }

function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// เลือกตัวแปรเชื่อมต่อ
$db = null;
if (isset($pdo) && is_pdo($pdo)) { $db = $pdo; }
elseif (isset($conn) && is_mysqli($conn)) { $db = $conn; }
else {
    die("ไม่พบการเชื่อมต่อฐานข้อมูล (ต้องเป็น \$pdo หรือ \$conn)");
}

// -----------------------------
// 3) ดึงข้อมูลผู้ใช้ทั้งหมด
// -----------------------------
if (is_pdo($db)) {
    $stmt = $db->query("SELECT user_id, username, first_name, profile_image FROM users ORDER BY user_id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (is_mysqli($db)) {
    $sql = "SELECT user_id, username, first_name, profile_image FROM users ORDER BY user_id DESC";
    $res = $db->query($sql);
    $users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $users = [];
}

// -----------------------------
// 4) ข้อความสถานะ (เช่น ลบสำเร็จ)
// -----------------------------
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
$deleted_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>รายชื่อผู้ใช้ | Admin</title>
<style>
    body { font-family: system-ui, Tahoma, Arial, sans-serif; background:#f6f7fb; margin:0; padding:24px; }
    .container { max-width:960px; margin:0 auto; }
    .card {
        background:#fff; border-radius:16px; box-shadow:0 10px 20px rgba(0,0,0,0.07);
        padding:24px;
    }
    h1 { font-size:22px; margin:0 0 16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px; border-bottom:1px solid #eee; text-align:left; font-size:14px; }
    th { background:#f9fafb; }
    .avatar { width:48px; height:48px; border-radius:8px; object-fit:cover; border:1px solid #ddd; }
    .btn {
        padding:6px 12px; border-radius:8px; border:0; font-size:13px; cursor:pointer; text-decoration:none; font-weight:600;
    }
    .btn-edit { background:#3b82f6; color:#fff; }
    .btn-delete { background:#ef4444; color:#fff; }
    .btn-add { background:#22c55e; color:#fff; padding:8px 14px; margin-bottom:16px; display:inline-block; }
    .msg { margin-bottom:16px; padding:12px; border-radius:10px; font-size:14px; }
    .msg-ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .msg-warn { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>รายชื่อผู้ใช้</h1>

        <?php if ($msg === 'deleted' && $deleted_id): ?>
            <div class="msg msg-ok">✅ ลบผู้ใช้ ID <?php echo (int)$deleted_id; ?> เรียบร้อยแล้ว</div>
        <?php elseif ($msg === 'cancelled'): ?>
            <div class="msg msg-warn">❎ ยกเลิกการลบ</div>
        <?php endif; ?>

        <a href="add_user.php" class="btn btn-add">+ เพิ่มผู้ใช้ใหม่</a>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>รูป</th>
                    <th>Username</th>
                    <th>ชื่อ</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5">ยังไม่มีผู้ใช้</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $imgFile = !empty($u['profile_image']) ? 'uploads/' . e($u['profile_image']) : '';
                        $hasImg = $imgFile && is_file($imgFile);
                        ?>
                        <tr>
                            <td><?php echo (int)$u['user_id']; ?></td>
                            <td>
                                <?php if ($hasImg): ?>
                                    <img class="avatar" src="<?php echo e($imgFile); ?>" alt="">
                                <?php else: ?>
                                    <img class="avatar" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='48' height='48'><rect width='48' height='48' fill='%23e9ecef'/></svg>" alt="">
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($u['username']); ?></td>
                            <td><?php echo e($u['first_name']); ?></td>
                            <td>
                                <a href="edit_user.php?id=<?php echo (int)$u['user_id']; ?>" class="btn btn-edit">แก้ไข</a>
                                <a href="delete_user.php?id=<?php echo (int)$u['user_id']; ?>" class="btn btn-delete">ลบ</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
