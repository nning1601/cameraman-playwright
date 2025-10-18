<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit();
}

$photographer_id = $_SESSION['photographer_id'];

if (!isset($_GET['job_id'])) {
    header("Location: photographer_jobs.php");
    exit();
}

$job_id = intval($_GET['job_id']);

// ดึงข้อมูลงาน
$stmt = $conn->prepare("
    SELECT job_id, job_type_id, job_date, status
    FROM job
    WHERE job_id = ? AND photographer_id = ?
");
$stmt->bind_param("ii", $job_id, $photographer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<p>ไม่พบงานนี้หรือไม่มีสิทธิ์แก้ไข</p>";
    echo '<p><a href="photographer_jobs.php">กลับไปหน้าตารางงาน</a></p>';
    exit();
}

$job = $result->fetch_assoc();

// ดึง expertise ทั้งหมด (ประเภทงาน)
$types_result = $conn->query("SELECT expertise_id, expertise_name FROM expertise");

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_type_id = intval($_POST['job_type_id']);
    $job_date = $_POST['job_date'];
    $status = $_POST['status'];

    $valid_statuses = ['Pending', 'In Progress', 'Completed'];

    if (!in_array($status, $valid_statuses, true)) {
        $error = "สถานะงานไม่ถูกต้อง";
    } else {
        // ตรวจสอบว่า expertise_id มีอยู่จริงไหม
        $check_stmt = $conn->prepare("SELECT expertise_id FROM expertise WHERE expertise_id = ?");
        $check_stmt->bind_param("i", $job_type_id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows === 0) {
            $error = "ประเภทงานที่เลือกไม่ถูกต้อง";
        } else {
            // อัพเดตข้อมูล
            $update_stmt = $conn->prepare("
                UPDATE job SET job_type_id = ?, job_date = ?, status = ? WHERE job_id = ? AND photographer_id = ?
            ");
            $update_stmt->bind_param("issii", $job_type_id, $job_date, $status, $job_id, $photographer_id);

            if ($update_stmt->execute()) {
                header("Location: photographer_jobs.php?msg=update_success");
                exit();
            } else {
                $error = "ไม่สามารถอัปเดตข้อมูลได้: " . $conn->error;
            }

            $update_stmt->close();
        }
        $check_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <title>แก้ไขงาน #<?= htmlspecialchars($job['job_id']) ?></title>
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            background: #f9f9f9;
            padding: 30px;
            color: #333;
        }
        .container {
            max-width: 500px;
            margin: auto;
            background: #fff;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4a148c;
            text-align: center;
            margin-bottom: 25px;
        }
        label {
            display: block;
            margin-bottom: 15px;
            font-weight: 600;
        }
        select, input[type="date"], input[type="text"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
        }
        button {
            background-color: #4a148c;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        button:hover {
            background-color: #6a1b9a;
        }
        a.back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #4a148c;
            text-decoration: none;
        }
        a.back-link:hover {
            text-decoration: underline;
        }
        .error {
            color: red;
            margin-bottom: 15px;
            font-weight: 700;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>แก้ไขงาน #<?= htmlspecialchars($job['job_id']) ?></h1>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <label for="job_type_id">ประเภทงาน:</label>
        <select name="job_type_id" id="job_type_id" required>
            <?php
            $types_result->data_seek(0);
            while ($type = $types_result->fetch_assoc()):
            ?>
                <option value="<?= $type['expertise_id'] ?>" <?= ($type['expertise_id'] == $job['job_type_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type['expertise_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label for="job_date">วันที่งาน:</label>
        <input type="date" name="job_date" id="job_date" value="<?= htmlspecialchars($job['job_date']) ?>" required />

        <label for="status">สถานะ:</label>
        <select name="status" id="status" required>
            <?php 
            $statuses = [
                'Pending' => 'รอดำเนินการ',
                'In Progress' => 'กำลังดำเนินการ',
                'Completed' => 'เสร็จสมบูรณ์',
            ];
            foreach ($statuses as $key => $label): 
            ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= ($job['status'] === $key) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">บันทึกการแก้ไข</button>
    </form>

    <a href="photographer_jobs.php" class="back-link">กลับไปหน้าตารางงาน</a>
</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
