<?php
session_start();
require 'db.php';

// ตรวจสอบ admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

// ---- ฟังก์ชันลบผู้ใช้ ----
if (isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);

    // ลบ login_history ก่อน
    $stmt = $conn->prepare("DELETE FROM login_history WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    // ลบผู้ใช้
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['msg'] = "ลบผู้ใช้เรียบร้อยแล้ว";
    header("Location: users_list.php");
    exit;
}

// ---- ดึงรายชื่อผู้ใช้ทั้งหมด ----
$result = $conn->query("SELECT user_id, username, email, first_name, last_name FROM users");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ใช้</title>
    <style>
        table { border-collapse: collapse; width: 80%; margin: 20px auto; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #f2f2f2; }
        .btn-delete { background: red; color: white; padding: 5px 10px; border: none; cursor: pointer; }
        .btn-delete:hover { background: darkred; }
    </style>
    <script>
        function confirmDelete(userId) {
            if (confirm("คุณแน่ใจว่าต้องการลบผู้ใช้นี้?")) {
                document.getElementById("deleteForm-" + userId).submit();
            }
        }
    </script>
</head>
<body>
    <h2 style="text-align:center;">รายชื่อผู้ใช้</h2>

    <?php if (isset($_SESSION['msg'])): ?>
        <p style="color: green; text-align:center;">
            <?= htmlspecialchars($_SESSION['msg']); unset($_SESSION['msg']); ?>
        </p>
    <?php endif; ?>

    <table>
        <tr>
            <th>ID</th>
            <th>ชื่อผู้ใช้</th>
            <th>อีเมล</th>
            <th>ชื่อจริง</th>
            <th>นามสกุล</th>
            <th>การจัดการ</th>
        </tr>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['user_id']); ?></td>
            <td><?= htmlspecialchars($row['username']); ?></td>
            <td><?= htmlspecialchars($row['email']); ?></td>
            <td><?= htmlspecialchars($row['first_name']); ?></td>
            <td><?= htmlspecialchars($row['last_name']); ?></td>
            <td>
                <form id="deleteForm-<?= $row['user_id']; ?>" method="post" style="display:inline;">
                    <input type="hidden" name="delete_user_id" value="<?= $row['user_id']; ?>">
                    <button type="button" class="btn-delete" onclick="confirmDelete(<?= $row['user_id']; ?>)">ลบ</button>
                </form>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</body>
</html>
