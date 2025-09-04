<?php
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $_POST['first_name'];
    $last_name  = $_POST['last_name'];
    $email      = $_POST['email'];
    $phone      = $_POST['phone'];
    $username   = $_POST['username'];  // สำหรับ login
    $password   = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role       = 'customer'; // ค่า default
    $type_id    = 1; // สำหรับตาราง login (1 = user)

    // ตรวจสอบอีเมลซ้ำ
    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ?");
    $check->bind_param("ss", $email, $phone);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "มีอีเมลหรือเบอร์โทรนี้ในระบบแล้ว กรุณาลองใหม่";
        exit();
    }
    $check->close();

    // บันทึกลงตาราง users
    $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $username, $first_name, $last_name, $email, $phone, $password, $role);

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id; // เอา ID ล่าสุดที่เพิ่ม

        // เพิ่มข้อมูลในตาราง login ด้วย
        $login_stmt = $conn->prepare("INSERT INTO login (username, password_hash, type_id, user_id) VALUES (?, ?, ?, ?)");
        $login_stmt->bind_param("ssii", $email, $password, $type_id, $user_id);
        $login_stmt->execute();
        $login_stmt->close();

        echo "<script>alert('สมัครสมาชิกเรียบร้อยแล้ว'); window.location.href='login_user.php';</script>";
    } else {
        echo "เกิดข้อผิดพลาด: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
} else {
    echo "ไม่สามารถเข้าถึงหน้านี้ได้โดยตรง";
}
?>
