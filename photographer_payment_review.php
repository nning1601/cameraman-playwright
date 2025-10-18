<?php
/* ============================================================
 * photographer_payment_review.php  — โชว์งานทั้งหมด + อนุมัติ/ปฏิเสธ
 * ถ้า payment_proof ว่าง จะ fallback เป็น uploads/payment_proof/placeholder.png
 * ============================================================ */
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['photographer_id'])) {
  header('Location: login_photographer.php'); exit;
}
$photographer_id = (int)$_SESSION['photographer_id'];

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $c,string $t,string $col):bool{
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $q->bind_param("ss",$t,$col); $q->execute();
  $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}
function url_from_path($raw){
  if(!$raw) return '';
  $p=str_replace('\\','/',$raw);
  if(preg_match('#^https?://#',$p)) return $p;
  if(!preg_match('#^(uploads|images)/#',$p)) $p='uploads/'.ltrim($p,'/');
  $base=rtrim(dirname($_SERVER['PHP_SELF']),'/\\').'/';
  return $base.ltrim($p,'/');
}
function payLabel($s){
  return match($s){
    'waiting'  => 'รออนุมัติ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ปฏิเสธแล้ว',
    default    => '—',
  };
}
function payBadgeClass($s){
  return match($s){
    'approved' => 'b-ok',
    'rejected' => 'b-rej',
    default    => 'b-wait',
  };
}

/* ---------- ตรวจคอลัมน์ ---------- */
$hasPayStat  = has_col($conn,'job','payment_status');
$hasPayProof = has_col($conn,'job','payment_proof');

/* ---------- ดึงชื่อช่างภาพ ---------- */
$st=$conn->prepare("SELECT first_name,last_name FROM photographer WHERE photographer_id=?");
$st->bind_param("i",$photographer_id); $st->execute();
$st->bind_result($pf,$pl); $st->fetch(); $st->close();

$ok=''; $err='';

/* ---------- POST: อนุมัติ/ปฏิเสธ ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'], $_POST['job_id'])) {
  $job_id = (int)$_POST['job_id'];
  $action = $_POST['action']; // approve|reject

  if (!$hasPayStat || !$hasPayProof) {
    $err = "ตาราง job ไม่มี payment_status/payment_proof";
  } elseif (!in_array($action, ['approve','reject'], true)) {
    $err = "คำสั่งไม่ถูกต้อง";
  } else {
    // อนุญาตเฉพาะรายการของช่างภาพนี้ ที่มีไฟล์ และยัง waiting
    $chk = $conn->prepare("
      SELECT job_id FROM job
      WHERE job_id=? AND photographer_id=?
        AND payment_proof IS NOT NULL AND payment_proof <> ''
        AND (payment_status='waiting' OR payment_status IS NULL)
      LIMIT 1
    ");
    $chk->bind_param("ii",$job_id,$photographer_id);
    $chk->execute(); $row=$chk->get_result()->fetch_assoc(); $chk->close();

    if (!$row) {
      $err = "ไม่พบรายการ หรือไม่อยู่ในสถานะรออนุมัติ";
    } else {
      $new = ($action==='approve') ? 'approved' : 'rejected';
      $up  = $conn->prepare("UPDATE job SET payment_status=? WHERE job_id=?");
      $up->bind_param("si",$new,$job_id);
      if ($up->execute()) {
        $ok = ($new==='approved') ? "✅ อนุมัติเรียบร้อย" : "❌ ปฏิเสธแล้ว";
      } else {
        $err = "อัปเดตไม่สำเร็จ: ".$up->error;
      }
      $up->close();
    }
  }
}

/* ---------- ดึง “ทั้งหมด” ของช่างภาพคนนี้ ---------- */
$per  = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$off  = ($page-1) * $per;

$sql = "
  SELECT j.job_id, j.job_date, j.job_time, j.location, j.job_type_id, j.status,
         ".($hasPayStat ? "j.payment_status" : "NULL AS payment_status").",
         ".($hasPayProof ? "j.payment_proof"  : "NULL AS payment_proof").",
         p.first_name, p.last_name
  FROM job j
  LEFT JOIN photographer p ON p.photographer_id=j.photographer_id
  WHERE j.photographer_id=?
  ORDER BY
    CASE WHEN j.payment_status='waiting' OR j.payment_status IS NULL THEN 0 ELSE 1 END,
    j.job_date DESC, j.job_time DESC
  LIMIT ? OFFSET ?
";
$st=$conn->prepare($sql);
$st->bind_param("iii", $photographer_id, $per, $off);
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM job WHERE photographer_id=?");
$cnt->bind_param("i",$photographer_id); $cnt->execute();
$total = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
$cnt->close();
$pages = max(1, (int)ceil($total/$per));

/* ---------- path รูป fallback (ให้โชว์ทุกงาน) ---------- */
const PLACEHOLDER = 'uploads/payment_proof/placeholder.png';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ตรวจหลักฐานการชำระเงิน | Cameraman</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--brand1:#6a11cb;--brand2:#2575fc;--ok:#16a34a;--err:#ef4444;--ink:#0f172a;--line:#e5e7eb;--card:#fff;--bg:#f6f7fb}
*{box-sizing:border-box}html,body{margin:0;padding:0;font-family:'Prompt',sans-serif;background:var(--bg);color:var(--ink)}
.navbar{padding:14px 24px;background:linear-gradient(90deg,var(--brand1),var(--brand2));color:#fff;display:flex;align-items:center;justify-content:space-between}
.navbar a{color:#fff;text-decoration:none;margin-left:10px;font-weight:800}
.page{max-width:1100px;margin:18px auto;padding:0 12px;display:grid;gap:12px}
h1{margin:6px 0;font-size:22px}
.notice{padding:10px 12px;border-radius:10px;border:1px solid #bbf7d0;background:#ecfdf5;color:#065f46;font-weight:800}
.error{padding:10px 12px;border-radius:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-weight:800}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px dashed #e5e7eb;text-align:left;vertical-align:top}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid var(--line);font-size:12px;font-weight:800}
.b-wait{background:#fff7ed;color:#92400e;border-color:#fed7aa}
.b-ok{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
.b-rej{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.thumb{max-width:120px;max-height:120px;border:1px solid #e5e7eb;border-radius:10px;display:block}
.btn{appearance:none;border:none;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:900}
.btn.approve{background:linear-gradient(90deg,#16a34a,#22c55e);color:#fff}
.btn.reject{background:#fff;color:#991b1b;border:1px solid #fecaca}
.pager{display:flex;gap:6px;justify-content:center;margin-top:10px}
.pager a,.pager span{padding:8px 12px;border:1px solid var(--line);border-radius:10px;text-decoration:none;background:#fff;color:#111}
.pager .active{background:linear-gradient(90deg,var(--brand1),var(--brand2));color:#fff;border-color:transparent}
</style>
</head>
<body>
<div class="navbar">
  <div>📸 Cameraman — ตรวจหลักฐานการชำระเงิน</div>
  <div>
    <span>สวัสดี, <?= h($pf.' '.$pl) ?></span>
    <a href="photographer_bookings.php">งานของฉัน</a>
    <a href="logout.php">ออกจากระบบ</a>
  </div>
</div>

<div class="page">
  <h1>รายการงานทั้งหมดของคุณ</h1>

  <?php if($ok): ?><div class="notice"><?= h($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="error"><?= h($err) ?></div><?php endif; ?>

  <?php if(!$hasPayStat || !$hasPayProof): ?>
    <div class="error">
      ตาราง <b>job</b> ไม่มี <code>payment_status/payment_proof</code><br>
      เพิ่มคอลัมน์และใส่ placeholder ตามคำแนะนำในหน้านี้ก่อน
    </div>
  <?php endif; ?>

  <div class="card">
    <?php if(!$rows): ?>
      <div style="text-align:center;color:#6b7280">ยังไม่มีงาน</div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="table">
          <thead>
            <tr>
              <th>วันที่/เวลา</th>
              <th>รายละเอียด</th>
              <th>สถานะงาน</th>
              <th>สถานะชำระเงิน</th>
              <th>หลักฐาน</th>
              <th>การดำเนินการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r):
              // ถ้า DB ว่าง ให้ fallback เป็น placeholder เสมอ
              $path = $r['payment_proof'] ?: PLACEHOLDER;
              $proof = url_from_path($path);
              $badge = payBadgeClass($r['payment_status']);
              $label = payLabel($r['payment_status']);
              $canAct = ($r['payment_status']==='waiting' || $r['payment_status']==='' || is_null($r['payment_status']))
                        && !empty($r['payment_proof']); // กดปุ่มได้เมื่อมีไฟล์จริงและ waiting
            ?>
            <tr>
              <td>
                <?= h(date('d/m/Y', strtotime($r['job_date']))) ?>
                <?php if($r['job_time']): ?><div style="color:#6b7280"><?= h(substr($r['job_time'],0,5)) ?></div><?php endif; ?>
              </td>
              <td style="min-width:220px">
                <div><b>สถานที่:</b> <?= h($r['location'] ?? '—') ?></div>
                <div><b>ประเภทงาน (id):</b> <?= h($r['job_type_id'] ?? '—') ?></div>
                <div style="color:#6b7280"><?= h(($r['first_name']??'').' '.($r['last_name']??'')) ?></div>
              </td>
              <td><span class="badge"><?= h($r['status'] ?: '—') ?></span></td>
              <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
              <td style="min-width:160px">
                <a href="<?= h($proof) ?>" target="_blank" rel="noopener">
                  <img src="<?= h($proof) ?>" class="thumb" alt="proof">
                </a>
                <div><a href="<?= h($proof) ?>" download>ดาวน์โหลด</a></div>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('ยืนยันการดำเนินการ?');" style="display:flex;gap:6px;flex-wrap:wrap">
                  <input type="hidden" name="job_id" value="<?= (int)$r['job_id'] ?>">
                  <button class="btn approve" name="action" value="approve" <?= $canAct?'':'disabled' ?>>อนุมัติ</button>
                  <button class="btn reject"  name="action" value="reject"  <?= $canAct?'':'disabled' ?>>ปฏิเสธ</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if($pages>1): ?>
        <div class="pager" style="margin-top:10px">
          <?php for($i=1;$i<=$pages;$i++):
            $qs=$_GET; $qs['page']=$i; $link=$_SERVER['PHP_SELF'].'?'.http_build_query($qs);
            if($i==$page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="<?= h($link) ?>"><?= $i ?></a><?php endif;
          endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
