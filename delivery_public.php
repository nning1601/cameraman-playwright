<?php
session_start();
require_once 'db.php';

$code = $_GET['code'] ?? '';
if ($code === '') {
    die("❌ ไม่พบรหัสลิงก์");
}

/* ================== ค้นหางานจาก token ================== */
$stmt = $conn->prepare("SELECT booking_id FROM delivery_share WHERE token=?");
$stmt->bind_param("s", $code);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    die("❌ ลิงก์นี้ไม่ถูกต้องหรือหมดอายุ");
}

$job_id = (int)$row['booking_id'];

/* ================== โหลดลิงก์ที่ส่งมา ================== */
$links = [];
$q=$conn->prepare("SELECT * FROM delivery_links WHERE booking_id=? ORDER BY id DESC");
$q->bind_param("i",$job_id);
$q->execute();
$links=$q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

/* ================== โหลดประวัติการสนทนา ================== */
$feedbacks = [];
$f=$conn->prepare("SELECT * FROM delivery_feedback WHERE booking_id=? ORDER BY id ASC");
$f->bind_param("i",$job_id);
$f->execute();
$feedbacks=$f->get_result()->fetch_all(MYSQLI_ASSOC);
$f->close();

/* ================== รับคอมเมนต์ลูกค้า (POST) ================== */
$errors=[]; $notices=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='customer_comment') {
    $name = trim($_POST['customer_name'] ?? '');
    $contact = trim($_POST['customer_contact'] ?? '');
    $comment = trim($_POST['comment'] ?? '');

    if ($comment==='') {
        $errors[]="กรุณากรอกข้อความ";
    } else {
        $ins=$conn->prepare("INSERT INTO delivery_feedback (booking_id,author_type,customer_name,customer_contact,comment,created_at) VALUES(?, 'customer', ?, ?, ?, NOW())");
        $ins->bind_param("isss",$job_id,$name,$contact,$comment);
        $ins->execute(); $ins->close();
        $notices[]="ส่งคอมเมนต์เรียบร้อยแล้ว";
        header("Location: delivery_public.php?code=".urlencode($code));
        exit;
    }
}

/* ================== Helper ================== */
function thai_datetime($d){ return $d?date('d/m/Y H:i',strtotime($d)):""; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ลิงก์งานของคุณ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{margin:0;font-family:'Prompt',sans-serif;background:#f9fafb;color:#1f2937}
.wrapper{max-width:900px;margin:30px auto;padding:20px}
.card{background:#fff;padding:20px;margin-bottom:20px;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,.08)}
h2,h3{margin:0 0 12px;color:#111827}
.table{width:100%;border-collapse:collapse;font-size:14px}
.table th,.table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
.table th{background:#f3f4f6}
.chat-box{border:1px solid #ddd;border-radius:10px;padding:12px;background:#fafafa;max-height:300px;overflow-y:auto}
.msg{margin-bottom:10px;padding:10px;border-radius:8px;max-width:70%}
.msg.customer{background:#e0f2fe;color:#075985;margin-right:auto}
.msg.photographer{background:#ede9fe;color:#4c1d95;margin-left:auto;text-align:right}
input,textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;font-family:inherit}
textarea{min-height:80px}
.btn{padding:10px 16px;border-radius:8px;border:none;cursor:pointer;font-weight:600}
.btn.primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff}
.alert{padding:12px;border-radius:8px;margin-bottom:10px}
.alert.ok{background:#dcfce7;color:#166534}
.alert.err{background:#fee2e2;color:#991b1b}
.small{font-size:12px;color:#6b7280}

/* Modal */
#linkModal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;
background:rgba(0,0,0,.6);justify-content:center;align-items:center;z-index:999}
#linkModal .modal-content{background:#fff;padding:20px;border-radius:12px;max-width:400px;width:90%;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.3)}
#linkModal h3{margin-top:0}
</style>
</head>
<body>
<div class="wrapper">

  <div class="card">
    <h2>📂 ลิงก์งานที่คุณได้รับ</h2>
    <?php if(!$links): ?>
      <p class="small">ยังไม่มีลิงก์จากช่างภาพ</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>ชื่อเรื่อง</th><th>หมายเหตุ</th><th>เวลาส่ง</th><th>โดย</th><th>จัดการ</th></tr></thead>
        <tbody>
          <?php foreach($links as $l): ?>
            <tr>
              <td><?=htmlspecialchars($l['title']?:'—')?></td>
              <td><?=htmlspecialchars($l['note']?:'—')?></td>
              <td><?=thai_datetime($l['delivered_at'])?></td>
              <td><?=htmlspecialchars($l['delivered_by_name']?:'—')?></td>
              <td>
                <button class="btn primary" 
                        onclick="openModal('<?=htmlspecialchars($l['title']?:'—')?>',
                                           '<?=htmlspecialchars($l['note']?:'—')?>',
                                           '<?=htmlspecialchars($l['url'])?>')">
                  ดูรายละเอียด
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>💬 การสนทนา</h3>
    <div class="chat-box">
      <?php if(!$feedbacks): ?><p class="small">ยังไม่มีการสนทนา</p>
      <?php else: foreach($feedbacks as $fb): ?>
        <div class="msg <?=$fb['author_type']?>">
          <b><?=$fb['author_type']==='customer'?'คุณ':'ช่างภาพ'?>:</b>
          <?=nl2br(htmlspecialchars($fb['comment']))?>
          <div class="small"><?=thai_datetime($fb['created_at'])?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <form method="post" style="margin-top:12px;display:grid;gap:10px">
      <input type="hidden" name="action" value="customer_comment">
      <input type="text" name="customer_name" placeholder="ชื่อของคุณ (ถ้ามี)">
      <input type="text" name="customer_contact" placeholder="อีเมลหรือเบอร์โทร (ไม่บังคับ)">
      <textarea name="comment" required placeholder="พิมพ์ข้อความตอบกลับ..."></textarea>
      <button type="submit" class="btn primary">ส่งคอมเมนต์</button>
    </form>
  </div>

</div>

<!-- Modal -->
<div id="linkModal">
  <div class="modal-content">
    <h3 id="mTitle"></h3>
    <p id="mNote" class="small"></p>
    <div style="margin-top:16px;display:flex;justify-content:center;gap:12px">
      <a id="mUrl" href="#" target="_blank" class="btn primary">เปิดลิงก์</a>
      <button onclick="closeModal()" class="btn">ปิด</button>
    </div>
  </div>
</div>

<script>
function openModal(title, note, url){
  document.getElementById('mTitle').innerText = title || "ไม่มีชื่อเรื่อง";
  document.getElementById('mNote').innerText  = note || "—";
  document.getElementById('mUrl').href = url;
  document.getElementById('linkModal').style.display = "flex";
}
function closeModal(){
  document.getElementById('linkModal').style.display = "none";
}
</script>

</body>
</html>
<?php $conn->close(); ?>
