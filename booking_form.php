<?php
session_start();
require 'db.php';
mysqli_set_charset($conn,'utf8mb4');

/* ========== Helpers ========== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_col(mysqli $conn, string $table, string $col): bool {
  $q = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $q->bind_param("ss",$table,$col);
  $q->execute();
  $ok = (bool)$q->get_result()->fetch_row();
  $q->close();
  return $ok;
}
function has_table(mysqli $conn, string $table): bool {
  $q = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $q->bind_param("s",$table);
  $q->execute();
  $ok = (bool)$q->get_result()->fetch_row();
  $q->close();
  return $ok;
}
function table_cols(mysqli $conn, string $table): array {
  $cols = [];
  $q = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $q->bind_param("s",$table);
  $q->execute();
  $r = $q->get_result();
  while($row = $r->fetch_assoc()){ $cols[] = $row['COLUMN_NAME']; }
  $q->close();
  return $cols;
}
function time_to_mysql($t){
  if (!$t) return '';
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/',$t)) return $t;
  if (preg_match('/^\d{2}:\d{2}$/',$t)) return $t.':00';
  return '';
}
function full_name($first,$last){
  $n = trim(($first??'').' '.($last??''));
  return $n !== '' ? $n : '';
}

/* ========== โปรไฟล์ผู้ใช้ที่ล็อกอิน (สำหรับผู้จอง) ========== */
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

function fetch_loggedin_profile(mysqli $conn, int $user_id): array {
  $out = ['name'=>'', 'first_name'=>'', 'last_name'=>'', 'phone'=>''];

  if ($user_id <= 0) {
    $out['first_name'] = $_SESSION['first_name'] ?? '';
    $out['last_name']  = $_SESSION['last_name'] ?? '';
    $out['name']       = $_SESSION['user_name'] ?? full_name($out['first_name'],$out['last_name']);
    $out['phone']      = $_SESSION['phone'] ?? '';
    return $out;
  }

  foreach (['user','users'] as $tbl) {
    if (!has_table($conn,$tbl)) continue;
    $cols = table_cols($conn,$tbl);

    $want = [];
    foreach (['first_name','last_name','name','full_name','phone','phone_number','telephone','mobile','username'] as $c) {
      if (in_array($c,$cols,true)) $want[] = $c;
    }
    if (!$want) continue;

    $id_col = null;
    foreach (['user_id','id'] as $cand) {
      if (in_array($cand,$cols,true)) { $id_col = $cand; break; }
    }
    if (!$id_col) continue;

    $sql = "SELECT ".implode(',', array_map(fn($c)=>"`$c`", $want))." FROM `$tbl` WHERE `$id_col` = ? LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("i",$user_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();

    if ($res) {
      $first = $res['first_name'] ?? '';
      $last  = $res['last_name']  ?? '';
      $name  = $res['name']       ?? ($res['full_name'] ?? ($res['username'] ?? ''));
      if ($name==='') $name = full_name($first,$last);

      $phone = '';
      foreach (['phone','phone_number','telephone','mobile'] as $pc) {
        if (!empty($res[$pc])) { $phone = (string)$res[$pc]; break; }
      }

      return ['name'=>$name,'first_name'=>$first,'last_name'=>$last,'phone'=>$phone];
    }
  }

  $out['first_name'] = $_SESSION['first_name'] ?? '';
  $out['last_name']  = $_SESSION['last_name'] ?? '';
  $out['name']       = $_SESSION['user_name'] ?? full_name($out['first_name'],$out['last_name']);
  $out['phone']      = $_SESSION['phone'] ?? '';
  return $out;
}

$loginProfile = fetch_loggedin_profile($conn, $user_id);
$bookerName   = $loginProfile['name'] ?: full_name($loginProfile['first_name'],$loginProfile['last_name']);
$bookerPhone  = $loginProfile['phone'] ?? '';

/* ========== ตรวจสอบช่างภาพปลายทาง ========== */
if (!isset($_GET['photographer_id'])) { die("ไม่พบช่างภาพที่ต้องการจอง"); }
$photographer_id = (int)$_GET['photographer_id'];

$stmt = $conn->prepare("SELECT photographer_id, first_name, last_name, price_rate, profile_image_path, profile_image FROM photographer WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$photographer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$photographer) { die("ไม่พบช่างภาพ"); }

/* ========== ภาพโปรไฟล์ช่างภาพ ========== */
$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
function img_src($raw,$fallback='images/default_user.png'){
  global $BASE_URL;
  if(!$raw) $raw=$fallback;
  $p=str_replace('\\','/',trim($raw));
  if(preg_match('#^https?://#i',$p)) return $p;
  if(!preg_match('#^(uploads|images)/#i',$p)) $p='uploads/'.ltrim($p,'/');
  return $BASE_URL.ltrim($p,'/');
}
$profileImg = $photographer['profile_image_path'] ?: ($photographer['profile_image'] ?? '');
$profileImg = img_src($profileImg);

/* ========== ตรวจ schema job ========== */
$has_time        = has_col($conn,'job','job_time');
$has_location    = has_col($conn,'job','location');
$has_job_type    = has_col($conn,'job','job_type');
$has_status      = has_col($conn,'job','status');
$has_user_id     = has_col($conn,'job','user_id');          // เดิมมีอยู่แล้ว
$has_client_name = has_col($conn,'job','client_name');
$has_client_tel  = has_col($conn,'job','client_phone');
$details_col     = has_col($conn,'job','details') ? 'details' : (has_col($conn,'job','notes') ? 'notes' : '');

/* เพิ่ม: รองรับคอลัมน์ id ลูกค้าหลายชื่อ */
$customer_id_cols = [];
foreach (['customer_id','client_id','booker_id','booked_by_user_id','booked_by','created_by_user_id','created_by'] as $cname) {
  if (has_col($conn,'job',$cname)) $customer_id_cols[] = $cname;
}

/* ========== จอง ========== */
$okMsg=''; $errMsg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name        = $bookerName;                                   // ใช้ชื่อจากโปรไฟล์
  $phone       = $bookerPhone ?: trim($_POST['phone'] ?? '');   // phone จากโปรไฟล์ก่อน

  $date        = trim($_POST['date']        ?? '');
  $time_raw    = trim($_POST['time']        ?? '');
  $location    = trim($_POST['location']    ?? '');
  $job_type    = trim($_POST['job_type']    ?? '');
  $job_type_other = trim($_POST['job_type_other'] ?? '');
  $requirements= trim($_POST['requirements']?? '');

  if ($job_type === 'อื่นๆ' && $job_type_other !== '') $job_type = $job_type_other;

  if ($name==='')            { $errMsg = 'ระบบไม่พบชื่อในโปรไฟล์ผู้ใช้ กรุณาอัปเดตโปรไฟล์ก่อนทำการจอง'; }
  elseif ($phone==='')       { $errMsg = 'กรุณาระบุเบอร์โทรติดต่อ'; }
  elseif ($date==='')        { $errMsg = 'กรุณาเลือกวันที่จอง'; }
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { $errMsg = 'รูปแบบวันที่ไม่ถูกต้อง'; }
  elseif (strtotime($date) < strtotime(date('Y-m-d'))) { $errMsg = 'ไม่สามารถจองวันที่ผ่านมาแล้ว'; }
  elseif ($has_time && $time_raw==='') { $errMsg = 'กรุณาเลือกเวลา'; }
  else {
    $time_mysql = $has_time ? time_to_mysql($time_raw) : '';
    if ($has_time && $time_mysql==='') { $errMsg = 'รูปแบบเวลาไม่ถูกต้อง'; }
    else {
      // กันชน
      if ($has_time) {
        $sqlChk = "SELECT COUNT(*) FROM job WHERE photographer_id=? AND job_date=? AND job_time=? ".($has_status ? "AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')" : "");
        $st = $conn->prepare($sqlChk);
        $st->bind_param("iss",$photographer_id,$date,$time_mysql);
      } else {
        $sqlChk = "SELECT COUNT(*) FROM job WHERE photographer_id=? AND job_date=? ".($has_status ? "AND (status IS NULL OR status NOT LIKE 'ยกเลิก%')" : "");
        $st = $conn->prepare($sqlChk);
        $st->bind_param("is",$photographer_id,$date);
      }
      $st->execute(); $st->bind_result($dup); $st->fetch(); $st->close();

      if ((int)$dup > 0) {
        $errMsg = $has_time ? 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น' : 'วันที่นี้ถูกจองแล้ว กรุณาเลือกวันอื่น';
      } else {
        $combined = "สถานที่: ".($location ?: 'ไม่ระบุ')." | ประเภทงาน: ".($job_type ?: 'ไม่ระบุ')." | ความต้องการ: ".($requirements ?: 'ไม่ระบุ');

        $cols = ['photographer_id','job_date'];
        $vals = [$photographer_id,$date];
        $types= 'is';

        if ($has_time)        { $cols[]='job_time';      $vals[]=$time_mysql;              $types.='s'; }
        if ($has_location)    { $cols[]='location';      $vals[]=$location;                $types.='s'; }
        if ($has_job_type)    { $cols[]='job_type';      $vals[]=$job_type;                $types.='s'; }
        if ($has_status)      { $cols[]='status';        $vals[]='รอดำเนินการ';           $types.='s'; }

        /* เดิม: ถ้ามี user_id ให้บันทึกด้วย */
        if ($has_user_id && $user_id>0) { $cols[]='user_id'; $vals[]=$user_id; $types.='i'; }

        /* ใหม่: ถ้าตารางมีคอลัมน์ customer_id/client_id/... ใส่ user_id ลงไปทุกคอลัมน์ที่พบ */
        if ($user_id > 0 && !empty($customer_id_cols)) {
          foreach ($customer_id_cols as $cname) {
            $cols[] = $cname;  $vals[] = $user_id;  $types .= 'i';
          }
        }

        if ($has_client_name) { $cols[]='client_name';   $vals[]=$name;                    $types.='s'; }
        if ($has_client_tel)  { $cols[]='client_phone';  $vals[]=$phone;                   $types.='s'; }
        if ($details_col)     { $cols[]=$details_col;    $vals[]=$requirements ?: $combined; $types.='s'; }

        $place = implode(',', array_fill(0,count($cols),'?'));
        $sqlIn = "INSERT INTO job (".implode(',',$cols).") VALUES ($place)";
        $stmt  = $conn->prepare($sqlIn);
        $bind  = []; $bind[] = &$types; foreach ($vals as $k=>$v){ $bind[]=&$vals[$k]; }
        call_user_func_array([$stmt,'bind_param'],$bind);

        if ($stmt->execute()) {
          $okMsg = '✅ บันทึกการจองเรียบร้อยแล้ว';
          $_POST = [];
        } else {
          $errMsg = 'เกิดข้อผิดพลาดในการบันทึก: '.$stmt->error;
        }
        $stmt->close();
      }
    }
  }
}

/* ========== UI ========== */
$photographerFull = trim(($photographer['first_name']??'').' '.($photographer['last_name']??''));
$profileImgUrl    = $profileImg;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จองช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:20px;font-weight:800;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-left:auto}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:600;padding:8px 10px;border-radius:10px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.menu a.logout{background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff}
.header-spacer{height:100px}
.container{max-width:900px;width:100%;padding:0 20px 40px}
.card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.header{display:flex;gap:14px;align-items:center;margin-bottom:16px}
.header img{width:80px;height:80px;border-radius:12px;object-fit:cover;border:1px solid #e5e7eb}
.header h2{margin:0;color:#6a11cb}
.photographer-info{background:#f3f4f6;border:1px solid #e5e7eb;padding:12px;border-radius:10px;line-height:1.8;margin:10px 0 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:700px){.grid{grid-template-columns:1fr}}
label{display:block;font-weight:800;color:#111;margin-top:6px}
input,textarea,select{width:100%;padding:12px;border:1px solid #ced4da;border-radius:10px;margin-top:6px;font-size:15px;background:#fff}
input:focus,textarea:focus,select:focus{outline:none;border-color:#6a11cb;box-shadow:0 0 0 4px rgba(106,17,203,.12)}
input[readonly]{background:#f9fafb}
button{margin-top:18px;width:100%;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;padding:12px;border:none;border-radius:10px;font-size:16px;cursor:pointer;font-weight:900;letter-spacing:.2px;transition:.18s}
button:hover{filter:brightness(1.05)}
.alert{margin:10px 0;padding:10px 12px;border-radius:10px;font-weight:700}
.alert.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.35);color:#065f46}
.alert.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.35);color:#7f1d1d}
.hint{font-size:12px;color:#6b7280;margin-top:4px}
.small{font-size:12px;color:#6b7280}
@media(max-width:640px){.logo{font-size:18px}.menu a{font-size:13px;padding:7px 9px}.header-spacer{height:92px}}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="index1.php">หน้าแรก</a>
    <a href="user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="photographer_popularity.php">ดูคะแนนช่างภาพ ⭐</a>
    <a href="locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="edit_profile.php">แก้ไขข้อมูล</a>
    <a href="logout.php" class="logout">ออกจากระบบ</a>
  </div>
</div>
<div class="header-spacer"></div>

<div class="container">
  <div class="card">
    <div class="header">
      <img src="<?= h($profileImg) ?>" alt="avatar" onerror="this.onerror=null;this.src='images/default_user.png'">
      <div>
        <h2>📅 จองช่างภาพ</h2>
        <div style="color:#374151">ช่างภาพ: <b><?= h($photographer['first_name'].' '.$photographer['last_name']) ?></b></div>
      </div>
    </div>

    <div class="photographer-info">
      <strong>ชื่อช่างภาพ:</strong> <?= h($photographer['first_name'].' '.$photographer['last_name']) ?><br>
      <strong>ราคา:</strong> <?= number_format((float)$photographer['price_rate'],2) ?> บาท/งาน
    </div>

    <?php if($okMsg): ?><div class="alert ok"><?= h($okMsg) ?></div><?php endif; ?>
    <?php if($errMsg): ?><div class="alert err"><?= h($errMsg) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="grid">
        <div>
          <label for="name">ชื่อผู้จอง (ตามผู้ใช้ที่ล็อกอิน)</label>
          <input type="text" id="name" name="name" required value="<?= h($bookerName) ?>" readonly>
          <div class="hint">แก้ไขชื่อผู้จองได้ที่หน้า “แก้ไขข้อมูล”</div>
        </div>
        <div>
          <label for="phone">เบอร์โทร</label>
          <input type="text" id="phone" name="phone" required
                 value="<?= h($bookerPhone ?: ($_POST['phone'] ?? '')) ?>"
                 <?= $bookerPhone ? 'readonly' : '' ?>>
          <?php if(!$bookerPhone): ?>
            <div class="hint">กรุณากรอกเบอร์โทรติดต่อ (หรือบันทึกในโปรไฟล์เพื่อให้ระบบดึงอัตโนมัติ)</div>
          <?php else: ?>
            <div class="hint">แก้ไขเบอร์ได้ที่หน้า “แก้ไขข้อมูล”</div>
          <?php endif; ?>
        </div>

        <div>
          <label for="date">วันที่จอง</label>
          <input type="date" id="date" name="date" required min="<?= h(date('Y-m-d')) ?>" value="<?= h($_POST['date'] ?? '') ?>">
        </div>
        <div>
          <label for="time">เวลา<?= $has_time ? '' : ' (ตารางไม่บังคับเวลา)' ?></label>
          <input type="time" id="time" name="time" <?= $has_time ? 'required' : '' ?> step="900" value="<?= h($_POST['time'] ?? '') ?>">
          <?php if($has_time): ?><div class="hint">เลือกเวลาเริ่มถ่าย (เช่น 09:00)</div><?php else: ?><div class="hint">ฐานข้อมูลไม่ได้บังคับเวลา สามารถเว้นว่างได้</div><?php endif; ?>
        </div>
        <div>
          <label for="location">สถานที่</label>
          <input type="text" id="location" name="location" placeholder="เช่น สวนรถไฟ / สยาม" value="<?= h($_POST['location'] ?? '') ?>">
        </div>
        <div>
          <label for="job_type">ประเภทงาน</label>
          <select id="job_type" name="job_type">
            <?php
            $types = ['งานแต่ง','พรีเวดดิ้ง','แฟชั่น/พอร์ตเทรต','รีวิวสินค้า','อีเวนต์','อื่นๆ'];
            $sel = $_POST['job_type'] ?? '';
            foreach($types as $t){
              $s = ($sel===$t)?'selected':'';
              echo "<option $s>".h($t)."</option>";
            }
            ?>
          </select>
          <input type="text" id="job_type_other" name="job_type_other" placeholder="ระบุประเภทงาน (ถ้าเลือก 'อื่นๆ')" style="margin-top:6px;display:<?= (($_POST['job_type'] ?? '')==='อื่นๆ')?'block':'none' ?>;" value="<?= h($_POST['job_type_other'] ?? '') ?>">
        </div>
      </div>

      <label for="requirements" style="margin-top:10px">ความต้องการเพิ่มเติม</label>
      <textarea id="requirements" name="requirements" rows="4" placeholder="เช่น โทนภาพฟิล์ม/เน้นภาพแคนดิด ฯลฯ"><?= h($_POST['requirements'] ?? '') ?></textarea>

      <button type="submit">💾 ยืนยันการจอง</button>
    </form>

    <div class="small" style="margin-top:10px">
      หมายเหตุ: ระบบจะกันชนวัน–เวลาเดียวกันกับงานที่ยังไม่ถูกยกเลิกอัตโนมัติ •
      หากชื่อ/เบอร์ไม่ถูกต้อง โปรดแก้ไขที่ <a href="edit_profile.php">หน้าโปรไฟล์</a>
    </div>
  </div>
</div>

<script>
const sel=document.getElementById('job_type');
const other=document.getElementById('job_type_other');
sel.addEventListener('change',()=>{if(sel.value==='อื่นๆ'){other.style.display='block'}else{other.style.display='none';other.value=''}});
</script>
</body>
</html>
