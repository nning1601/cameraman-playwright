<?php
session_start();
require 'db.php';

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];

    // อัปเดต logout_time ของ login ล่าสุดที่ยังว่าง
    $stmt_logout = $conn->prepare("UPDATE login_history 
                                   SET logout_time = NOW() 
                                   WHERE admin_id = ? AND logout_time IS NULL 
                                   ORDER BY login_time DESC 
                                   LIMIT 1");
    $stmt_logout->bind_param("i", $admin_id);
    $stmt_logout->execute();

    session_destroy();
}
header("Location: login_admin.php");
exit;
?>
