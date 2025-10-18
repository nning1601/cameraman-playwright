<?php
session_start();
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn,'utf8mb4');
date_default_timezone_set('Asia/Bangkok');

/* ===== Auth: เฉพาะช่างภาพ ===== */
if (!isset($_SESSION['photographer_id'])) {
    header("Location: login_photographer.php");
    exit;
}
$photographer_id = (int)$_SESSION['photographer_id'];

/* ===== Utils ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function thai_status_class($status){
    $s = mb_strtolower($status ?? '');
    if (mb_strpos($s,'ยกเลิก') !== false) return 'cancel';
    if (mb_strpos($s,'รอดำเนินการ') !== false) return 'pending';
    if (mb_strpos($s,'เสร็จ') !== false) return 'done';
    if (mb_strpos($s,'ยืนยัน') !== false) return 'ok';
    return 'muted';
}
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): bool {
    $bind = [$types];
    foreach ($values as $k => $v) { $bind[] = &$values[$k]; }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_photo_jobs'])) { $_SESSION['csrf_photo_jobs'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_photo_jobs'];
function csrf_ok($t){ return hash_equals($_SESSION['csrf_photo_jobs'] ?? '', $t ?? ''); }

/* ===== โหลดชื่อช่างภาพ (โชว์ในเมนู) ===== */
$stmt = $conn->prepare("SELECT first_name, last_name FROM photographer WHERE photographer_id = ?");
$stmt->bind_param("i", $photographer_id);
$stmt->execute();
$stmt->bind_result($firstName, $lastName);
$stmt->fetch();
$stmt->close();
$firstName = $firstName ?: 'Photographer';

/* ===== ตัวเลือกสถานะที่อนุญาตให้ช่างภาพเปลี่ยนได้ ===== */
$allowedStatuses = ['รอดำเนินการ','ยืนยันแล้ว','ยกเลิก','เสร็จสิ้น'];

$flash_ok  = isset($_GET['ok']) ? 'อัปเดตสถานะเรียบร้อย' : '';
$flash_err = '';

/* ===== อัปเดตสถานะ (เฉพาะงานของตัวเอง) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!csrf_ok($_POST['csrf'] ?? '')) {
        $flash_err = 'คำขอไม่ถูกต้อง (CSRF)';
    } else {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $new    = trim($_POST['new_status'] ?? '');
        if (!in_array($new, $allowedStatuses, true)) {
            $flash_err = 'สถานะไม่ถูกต้อง';
        } else {
            $chk = $conn->prepare("SELECT 1 FROM job WHERE job_id=? AND photographer_id=?");
            $chk->bind_param("ii", $job_id, $photographer_id);
            $chk->execute();
            $has = $chk->get_result()->fetch_row();
            $chk->close();

            if (!$has) {
                $flash_err = 'ไม่พบงานนี้ หรือไม่มีสิทธิ์แก้ไข';
            } else {
                $up = $conn->prepare("UPDATE job SET status=? WHERE job_id=?");
                $up->bind_param("si", $new, $job_id);
                if ($up->execute()) {
                    $qs = $_GET; $qs['ok'] = 1;
                    header("Location: ".$_SERVER['PHP_SELF']."?".http_build_query($qs));
                    exit;
                } else {
                    $flash_err = 'อัปเดตล้มเหลว: '.$up->error;
                }
                $up->close();
            }
        }
    }
}

/* ===== ค้นหา/กรอง & แบ่งหน้า (เฉพาะงานของช่างภาพนี้) ===== */
$q        = trim($_GET['q'] ?? '');
$status_f = $_GET['status'] ?? 'all';
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to']   ?? '');
$per      = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$off      = ($page-1)*$per;

$where  = " WHERE job.photographer_id = ? ";
$params = [$photographer_id];
$types  = "i";

if ($status_f !== 'all' && $status_f !== '') { $where .= " AND job.status = ? "; $params[] = $status_f; $types .= "s"; }
if ($from !== '') { $where .= " AND job.job_date >= ? "; $params[] = $from; $types .= "s"; }
if ($to   !== '') { $where .= " AND job.job_date <= ? "; $params[] = $to;   $types .= "s"; }
if ($q    !== '') { $where .= " AND (job.location LIKE ? ) "; $params[] = "%{$q}%"; $types .= "s"; }

/* สรุปจำนวน */
$sumSql = "
  SELECT
    SUM(CASE WHEN job.status LIKE 'รอดำเนินการ%' THEN 1 ELSE 0 END) AS pending_cnt,
    SUM(CASE WHEN job.status LIKE 'ยืนยัน%'   THEN 1 ELSE 0 END) AS ok_cnt,
    SUM(CASE WHEN job.status LIKE 'ยกเลิก%'   THEN 1 ELSE 0 END) AS cancel_cnt,
    SUM(CASE WHEN job.status LIKE 'เสร็จ%'    THEN 1 ELSE 0 END) AS done_cnt,
    COUNT(*) AS total_cnt
  FROM job
  $where
";
$stm = $conn->prepare($sumSql);
stmt_bind_params($stm, $types, $params);
$stm->execute();
$sum = $stm->get_result()->fetch_assoc() ?: ['pending_cnt'=>0,'ok_cnt'=>0,'cancel_cnt'=>0,'done_cnt'=>0,'total_cnt'=>0];
$stm->close();

/* นับเพื่อแบ่งหน้า */
$cntSql = "SELECT COUNT(*) AS c FROM job $where";
$sc = $conn->prepare($cntSql);
stmt_bind_params($sc, $types, $params);
$sc->execute();
$total = (int)($sc->get_result()->fetch_assoc()['c'] ?? 0);
$sc->close();
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) { $page = $pages; $off = ($page-1)*$per; }

/* รายการ */
$listSql = "
  SELECT job.job_id, job.job_date, job.job_time, job.location, job.status
  FROM job
  $where
  ORDER BY job.job_date ASC, job.job_time ASC
  LIMIT ? OFFSET ?
";
$typesList  = $types . "ii";
$paramsList = array_merge($params, [$per, $off]);
$ls = $conn->prepare($listSql);
stmt_bind_params($ls, $typesList, $paramsList);
$ls->execute();
$rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>งานของฉัน (ช่างภาพ)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{
  width:100%;
  position:fixed;top:0;left:0;
  background: linear-gradient(90deg,#0b1220,#111827);
  display:flex;align-items:center;
  padding:14px 0;
  box-shadow:0 4px 20px rgba(156,142,142,.25);
  z-index:100
}
.nav-inner{
  width:100%;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 12px
}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{
  color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px
}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.wrapper{
  flex:1;display:flex;justify-content:center;align-items:flex-start;
  padding:120px 16px 48px
}
.container{
  width:100%;max-width:1100px;display:flex;flex-direction:column;gap:16px
}
.page-title{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.page-title h1{margin:0;font-size:28px;font-weight:900;color:#0b1220}
.card{
  width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb
}
.notice{padding:10px 12px;border-radius:12px;border:1px solid #d1fae5;background:#ecfdf5;color:#065f46;font-weight:800}
.error{padding:10px 12px;border-radius:12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-weight:800}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.sum{background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;display:grid;gap:6px;text-align:center}
.sum .num{font-size:20px;font-weight:900}
.s-pending{border-left:5px solid #0bbef5}
.s-ok{border-left:5px solid #10b981}
.s-cancel{border-left:5px solid #ef4444}
.s-done{border-left:5px solid #22c55e}
.s-total{border-left:5px solid #3b82f6}
@media (max-width:900px){ .summary{grid-template-columns:repeat(2,1fr)} }
@media (max-width:520px){ .summary{grid-template-columns:1fr} }
.filters{display:grid;gap:10px}
.filters .row{display:grid;grid-template-columns:1fr 160px 160px 170px auto;gap:8px}
.filters input,.filters select{padding:10px;border:1px solid #dbeafe;border-radius:10px;background:#fff}
.filters .btn{appearance:none;border:none;border-radius:10px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;padding:10px 16px;font-weight:900;cursor:pointer}
.filters .reset{color:#6b7280;text-decoration:none;align-self:center}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px dashed #e5e7eb;text-align:left}
.table th{color:#374151;font-weight:800}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:800;font-size:12px;border:1px solid #e5e7eb}
.badge.pending{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.badge.ok{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
.badge.cancel{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.badge.done{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.badge.muted{background:#f3f4f6;color:#374151}
.inline-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
.select-mini{padding:6px 8px;border:1px solid #dbeafe;border-radius:8px;background:#fff}
.btn-mini{appearance:none;border:1px solid #dbeafe;border-radius:8px;background:#f8fafc;color:#2575fc;padding:6px 10px;font-weight:800;cursor:pointer}
.btn-mini.primary{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.row-actions{display:flex;gap:6px;flex-wrap:wrap}
.pager{display:flex;gap:6px;justify-content:center;margin-top:10px}
.pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;background:#fff;color:#111}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
@media (max-width:640px){
  .menu a,.menu .badge{font-size:13px;padding:7px 10px}
  .wrapper{padding:110px 12px 32px}
}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <span class="badge">👋 สวัสดี, <?= h($firstName) ?></span>
      <a href="photographer_jobs.php" class="active">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั้วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">ดูข้อมูลการมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">
    <div class="page-title">
      <h1>งานของฉัน</h1>
    </div>

    <?php if($flash_ok): ?><div class="card notice"><?= h($flash_ok) ?></div><?php endif; ?>
    <?php if($flash_err): ?><div class="card error"><?= h($flash_err) ?></div><?php endif; ?>

    <section class="card">
      <div class="summary">
        <div class="sum s-total"><div>ทั้งหมด</div><div class="num"><?= (int)$sum['total_cnt'] ?></div></div>
        <div class="sum s-pending"><div>รอดำเนินการ</div><div class="num"><?= (int)$sum['pending_cnt'] ?></div></div>
        <div class="sum s-ok"><div>ยืนยันแล้ว</div><div class="num"><?= (int)$sum['ok_cnt'] ?></div></div>
        <div class="sum s-cancel"><div>ยกเลิก</div><div class="num"><?= (int)$sum['cancel_cnt'] ?></div></div>
        <div class="sum s-done"><div>เสร็จสิ้น</div><div class="num"><?= (int)$sum['done_cnt'] ?></div></div>
      </div>
    </section>

    <form class="card filters" method="get">
      <div class="row">
        <input type="text" name="q" placeholder="ค้นหาสถานที่..." value="<?= h($q) ?>">
        <input type="date" name="from" value="<?= h($from) ?>">
        <input type="date" name="to" value="<?= h($to) ?>">
        <select name="status">
          <?php
            $opts = ['all'=>'— ทุกสถานะ —','รอดำเนินการ'=>'รอดำเนินการ','ยืนยันแล้ว'=>'ยืนยันแล้ว','ยกเลิก'=>'ยกเลิก','เสร็จสิ้น'=>'เสร็จสิ้น'];
            foreach($opts as $val=>$label){
              $sel = ($status_f===$val) ? 'selected' : '';
              echo '<option value="'.h($val).'" '.$sel.'>'.h($label).'</option>';
            }
          ?>
        </select>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="btn" type="submit">ค้นหา</button>
          <a class="reset" href="<?= h(basename(__FILE__)) ?>">ล้างค่า</a>
        </div>
      </div>
    </form>

    <section class="card">
      <?php if ($rows): ?>
        <div style="overflow-x:auto">
          <table class="table">
            <thead>
              <tr>
                <th style="min-width:110px">วันที่</th>
                <th style="min-width:90px">เวลา</th>
                <th style="min-width:220px">สถานที่</th>
                <th style="min-width:220px">สถานะ / แก้ไข</th>
                <th style="min-width:180px">ลิงก์</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r):
                $cls = thai_status_class($r['status']);
                $dateParam = date('Y-m-d', strtotime($r['job_date']));
              ?>
              <tr>
                <td><?= h(date('d/m/Y', strtotime($r['job_date']))) ?></td>
                <td><?= h(substr($r['job_time'],0,5)) ?></td>
                <td><?= h($r['location']) ?></td>
                <td>
                  <span class="badge <?= h($cls) ?>"><?= h($r['status'] ?: '—') ?></span>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="job_id" value="<?= (int)$r['job_id'] ?>">
                    <select name="new_status" class="select-mini">
                      <?php foreach($allowedStatuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $s===$r['status']?'selected':'' ?>><?= h($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn-mini primary" type="submit">บันทึก</button>
                  </form>
                </td>
                <td class="row-actions">
                  <a class="btn-mini" href="photographer2_schedule2.php?photographer_id=<?= (int)$photographer_id ?>&date=<?= h($dateParam) ?>">ดูในปฏิทิน</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
          <div class="pager">
            <?php for($i=1;$i<=$pages;$i++):
              $qs = $_GET; $qs['page'] = $i;
              $link = $_SERVER['PHP_SELF'].'?'.http_build_query($qs);
              if ($i==$page): ?>
                <span class="active"><?= $i ?></span>
              <?php else: ?>
                <a href="<?= h($link) ?>"><?= $i ?></a>
              <?php endif; endfor; ?>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <div style="text-align:center;color:#6b7280">ไม่พบงานตามเงื่อนไขที่เลือก</div>
      <?php endif; ?>
    </section>

  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
