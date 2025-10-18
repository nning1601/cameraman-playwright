<?php
session_start();
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

/* ===== page self (สำหรับ redirect และลิงก์ภายใน) ===== */
$self = basename($_SERVER['PHP_SELF'] ?? 'photographer2_schedule2.php');

/* ===== Helpers ===== */
function thai_month($m){ static $map=[1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม']; return $map[$m] ?? ''; }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ymd($s){ return preg_replace('/[^0-9\-: ]/','',$s ?? ''); }
function has_col(mysqli $c,string $t,string $col):bool{ $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1"); $q->bind_param('ss',$t,$col); $q->execute(); $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok; }
function has_index(mysqli $c,string $t,string $idx):bool{ $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1"); $q->bind_param('ss',$t,$idx); $q->execute(); $ok=(bool)$q->get_result()->fetch_row(); $q->close(); return $ok; }
function make_csrf(){ if (empty($_SESSION['csrf_sched'])) { $_SESSION['csrf_sched']=bin2hex(random_bytes(32)); } return $_SESSION['csrf_sched']; }
function check_csrf($t){ return hash_equals($_SESSION['csrf_sched'] ?? '', $t ?? ''); }

/* ===== Login / Greeting ===== */
$firstName=''; $lastName='';
$session_photographer_id = isset($_SESSION['photographer_id']) ? (int)$_SESSION['photographer_id'] : 0;
if ($session_photographer_id > 0) {
  if ($stmt=$conn->prepare("SELECT first_name,last_name FROM photographer WHERE photographer_id=?")) {
    $stmt->bind_param("i",$session_photographer_id); $stmt->execute(); $stmt->bind_result($firstName,$lastName); $stmt->fetch(); $stmt->close();
  }
}

/* ===== Filters ===== */
$month = isset($_GET['month']) ? max(1, min(12, (int)$_GET['month'])) : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year'] : (int)date('Y');
$photographer_id = isset($_GET['photographer_id']) ? (int)$_GET['photographer_id'] : 0;
if ($photographer_id<=0 && $session_photographer_id>0) $photographer_id = $session_photographer_id;

$is_admin = !empty($_SESSION['admin_id']);
$can_edit = $is_admin || ($session_photographer_id>0 && $photographer_id==$session_photographer_id);

/* ===== Ensure job schema ===== */
$conn->query("CREATE TABLE IF NOT EXISTS job (
  job_id INT AUTO_INCREMENT PRIMARY KEY,
  photographer_id INT NOT NULL,
  user_id INT NULL,
  job_date DATE NOT NULL,
  job_time TIME NULL,
  location VARCHAR(255) NULL,
  status VARCHAR(50) NULL,
  note TEXT NULL,
  INDEX (photographer_id, job_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!has_col($conn,'job','status'))   { $conn->query("ALTER TABLE job ADD COLUMN status VARCHAR(50) NULL"); }
if (!has_col($conn,'job','note'))     { $conn->query("ALTER TABLE job ADD COLUMN note TEXT NULL"); }
if (!has_col($conn,'job','location')) { $conn->query("ALTER TABLE job ADD COLUMN location VARCHAR(255) NULL"); }
if (!has_col($conn,'job','job_time')) { $conn->query("ALTER TABLE job ADD COLUMN job_time TIME NULL"); }

/* ===== Deduplicate existing rows then create UNIQUE INDEX ===== */
$uniq_idx_name = 'uniq_job_ph_date_time';
if (!has_index($conn, 'job', $uniq_idx_name)) {
  $sql_dedup = "
    DELETE j1 FROM job j1
    JOIN job j2
      ON j1.photographer_id = j2.photographer_id
     AND j1.job_date        = j2.job_date
     AND (
           (j1.job_time = j2.job_time) OR
           (j1.job_time IS NULL AND j2.job_time IS NULL)
         )
     AND j1.job_id > j2.job_id
  ";
  try { $conn->query($sql_dedup); } catch (\Throwable $e) {}
  try { $conn->query("CREATE UNIQUE INDEX $uniq_idx_name ON job (photographer_id, job_date, job_time)"); }
  catch (\Throwable $e) {}
}

/* ===== App-level duplicate checker ===== */
function job_exists_duplicate(mysqli $conn, int $photographer_id, string $date, ?string $time, int $exclude_id=0): bool {
  if ($time === null || $time === '') {
    $sql = "SELECT 1 FROM job WHERE photographer_id=? AND job_date=? AND job_time IS NULL";
    if ($exclude_id > 0) { $sql .= " AND job_id<>?"; }
    $stmt = $conn->prepare($sql);
    if ($exclude_id > 0) { $stmt->bind_param("isi", $photographer_id, $date, $exclude_id); }
    else { $stmt->bind_param("is", $photographer_id, $date); }
  } else {
    $sql = "SELECT 1 FROM job WHERE photographer_id=? AND job_date=? AND job_time=?";
    if ($exclude_id > 0) { $sql .= " AND job_id<>?"; }
    $stmt = $conn->prepare($sql);
    if ($exclude_id > 0) { $stmt->bind_param("issi", $photographer_id, $date, $time, $exclude_id); }
    else { $stmt->bind_param("iss", $photographer_id, $date, $time); }
  }
  $stmt->execute();
  $dup = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $dup;
}

/* ===== POST (create/update/delete) ===== */
$errors=[]; $success=''; $csrf = make_csrf();
if ($_SERVER['REQUEST_METHOD']==='POST' && $can_edit) {
  $action = $_POST['action'] ?? '';
  if (!check_csrf($_POST['csrf'] ?? '')) { $errors[]='คำขอไม่ถูกต้อง (CSRF)'; $action=''; }

  if ($action==='create') {
    $d=ymd($_POST['job_date']??'');
    $t_raw=ymd($_POST['job_time']??'');
    $t = ($t_raw==='') ? null : substr($t_raw,0,5).':00';
    $loc=trim((string)($_POST['location']??'')); $st=trim((string)($_POST['status']??'')); $note=trim((string)($_POST['note']??''));
    if ($d==='') $errors[]='กรุณาเลือกวันที่';

    if (!$errors && job_exists_duplicate($conn, $photographer_id, $d, $t, 0)) {
      $qs=http_build_query([
        'month'=>$month,'year'=>$year,'photographer_id'=>$photographer_id,
        'popup'=>'มีงานในวัน/เวลาเดียวกันแล้ว',
        'add_date'=>$d, 'err'=>1
      ]);
      header("Location: {$self}?".$qs); exit;
    }

    if (!$errors) {
      $stmt=$conn->prepare("INSERT INTO job(photographer_id,job_date,job_time,location,status,note) VALUES(?,?,?,?,?,?)");
      $stmt->bind_param("isssss",$photographer_id,$d,$t,$loc,$st,$note);
      if($stmt->execute()){
        $success='บันทึกสำเร็จ! เพิ่มงานเรียบร้อย';
      } else {
        if ($conn->errno == 1062) {
          $qs=http_build_query([
            'month'=>$month,'year'=>$year,'photographer_id'=>$photographer_id,
            'popup'=>'มีงานในวัน/เวลาเดียวกันแล้ว',
            'add_date'=>$d,'err'=>1
          ]);
          header("Location: {$self}?".$qs); exit;
        } else {
          $errors[]='เพิ่มงานไม่สำเร็จ';
        }
      }
      $stmt->close();
    }

  } elseif ($action==='update') {
    $id=(int)($_POST['job_id']??0);
    $allow=$is_admin;
    if(!$allow){
      $q=$conn->prepare("SELECT 1 FROM job WHERE job_id=? AND photographer_id=?");
      $q->bind_param("ii",$id,$session_photographer_id); $q->execute();
      $allow=(bool)$q->get_result()->fetch_row(); $q->close();
    }
    if(!$allow){ $errors[]='ไม่มีสิทธิ์แก้ไขงานนี้'; }

    $d=ymd($_POST['job_date']??'');
    $t_raw=ymd($_POST['job_time']??'');
    $t = ($t_raw==='') ? null : substr($t_raw,0,5).':00';
    $loc=trim((string)($_POST['location']??'')); $st=trim((string)($_POST['status']??'')); $note=trim((string)($_POST['note']??''));

    if(!$errors && job_exists_duplicate($conn, $photographer_id, $d, $t, $id)) {
      $qs=http_build_query([
        'month'=>$month,'year'=>$year,'photographer_id'=>$photographer_id,
        'popup'=>'มีงานในวัน/เวลาเดียวกันแล้ว',
        'edit_id'=>$id,'err'=>1
      ]);
      header("Location: {$self}?".$qs); exit;
    }

    if(!$errors){
      $stmt=$conn->prepare("UPDATE job SET job_date=?,job_time=?,location=?,status=?,note=? WHERE job_id=?");
      $stmt->bind_param("sssssi",$d,$t,$loc,$st,$note,$id);
      if($stmt->execute()){
        $success='บันทึกการแก้ไขสำเร็จ';
      } else {
        if ($conn->errno == 1062) {
          $qs=http_build_query([
            'month'=>$month,'year'=>$year,'photographer_id'=>$photographer_id,
            'popup'=>'มีงานในวัน/เวลาเดียวกันแล้ว',
            'edit_id'=>$id,'err'=>1
          ]);
          header("Location: {$self}?".$qs); exit;
        } else {
          $errors[]='แก้ไขไม่สำเร็จ';
        }
      }
      $stmt->close();
    }

  } elseif ($action==='delete') {
    $id=(int)($_POST['job_id']??0);
    $allow=$is_admin;
    if(!$allow){
      $q=$conn->prepare("SELECT 1 FROM job WHERE job_id=? AND photographer_id=?");
      $q->bind_param("ii",$id,$session_photographer_id); $q->execute();
      $allow=(bool)$q->get_result()->fetch_row(); $q->close();
    }
    if(!$allow){ $errors[]='ไม่มีสิทธิ์ลบงานนี้'; }
    if(!$errors){
      $stmt=$conn->prepare("DELETE FROM job WHERE job_id=?");
      $stmt->bind_param("i",$id);
      if($stmt->execute()){ $success='ลบงานเรียบร้อย'; }
      else { $errors[]='ลบไม่สำเร็จ'; }
      $stmt->close();
    }
  }

  /* ===== Redirect กลับมา + เด้งป๊อปอัพสวยๆกลางจอ ทั้งสำเร็จ/ผิดพลาด ===== */
  $params = [
    'month'=>$month,'year'=>$year,'photographer_id'=>$photographer_id,
    'msg'=>$success,'err'=>$errors?1:0
  ];
  if ($errors) { $params['popup'] = implode("\n", $errors); }
  $qs = http_build_query($params);
  header("Location: {$self}?".$qs); exit;
}

/* ===== Messages & popup ===== */
$msg_ok = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$has_err = isset($_GET['err']) && $_GET['err']=='1';
$popup  = isset($_GET['popup']) ? trim($_GET['popup']) : '';

/* เตรียมข้อความ/ชนิดสำหรับ Modal */
$popup_text = '';
$popup_kind = ''; // success | error | info
if ($popup !== '') {
  $popup_text = $popup;
  $popup_kind = ($has_err ? 'error' : 'info');
} elseif ($msg_ok !== '') {
  $popup_text = $msg_ok;
  $popup_kind = 'success';
}

/* ===== Photographers list ===== */
$photographers=[];
if($res=$conn->query("SELECT photographer_id, CONCAT(first_name,' ',last_name) AS name FROM photographer")){
  while($row=$res->fetch_assoc()){ $photographers[(int)$row['photographer_id']]=$row['name']; }
  $res->free();
}

/* ===== Load jobs for month ===== */
$jobs_by_date=[]; $jobs_raw_by_id=[];
if ($photographer_id > 0) {
  $start_date=sprintf('%04d-%02d-01',$year,$month); $end_date=date('Y-m-t',strtotime($start_date));
  $sql="SELECT j.*, CONCAT(p.first_name,' ',p.last_name) AS photographer_name
        FROM job j JOIN photographer p ON p.photographer_id=j.photographer_id
        WHERE j.photographer_id=? AND ( (j.job_date BETWEEN ? AND ?) OR (j.job_date BETWEEN DATE_ADD(?, INTERVAL 543 YEAR) AND DATE_ADD(?, INTERVAL 543 YEAR)) )
        ORDER BY j.job_date,j.job_time";
  if($stmt=$conn->prepare($sql)){
    $stmt->bind_param("issss",$photographer_id,$start_date,$end_date,$start_date,$end_date);
    $stmt->execute(); $result=$stmt->get_result();
    while($row=$result->fetch_assoc()){
      $date=$row['job_date']; $y=(int)substr($date,0,4);
      if($y>2200) $date=date('Y-m-d',strtotime($date.' -543 years'));
      $jobs_by_date[$date][]=$row; $jobs_raw_by_id[(int)$row['job_id']]=$row;
    }
    $result->free(); $stmt->close();
  }
}

/* ===== Form mode ===== */
$status_opts=['รอดำเนินการ','ยืนยันแล้ว','ยกเลิก','เสร็จสิ้น'];
$form_mode=''; $edit_job_id=0; $prefill=['job_date'=>'','job_time'=>'','location'=>'','status'=>'','note'=>''];
if ($can_edit) {
  if (isset($_GET['add_date'])) { $d=ymd($_GET['add_date']); if($d!==''){ $form_mode='create'; $prefill['job_date']=$d; } }
  if (isset($_GET['edit_id'])) {
    $id=(int)$_GET['edit_id'];
    if(!empty($jobs_raw_by_id[$id])){
      $j=$jobs_raw_by_id[$id]; $form_mode='update'; $edit_job_id=$id;
      $prefill['job_date']= (strlen($j['job_date'])?(((int)substr($j['job_date'],0,4)>2200)?date('Y-m-d',strtotime($j['job_date'].' -543 years')):$j['job_date']):'');
      $prefill['job_time']= substr((string)($j['job_time']??''),0,5);
      $prefill['location']= (string)($j['location']??'');
      $prefill['status']=   (string)($j['status']??'');
      $prefill['note']=     (string)($j['note']??'');
    }
  }
}

/* ===== Day renderer ===== */
function renderDay($year,$month,$day,$jobs_by_date,$can_edit,$photographer_id,$csrf){
  global $self;
  $date_key=sprintf("%04d-%02d-%02d",$year,$month,$day); $is_today=($date_key===date('Y-m-d'));
  $h="<td>";
  $h.="<div class='cell-head'><span class='date".($is_today?' today':'')."'>$day</span>";
  if($can_edit && $photographer_id>0){
    $h.="<a class='mini-add' href='{$self}?photographer_id=".$photographer_id."&month=".$month."&year=".$year."&add_date=".$date_key."'>＋เพิ่มงาน</a>";
  }
  $h.="</div>";
  if(!empty($jobs_by_date[$date_key])){
    $h.="<div class='jobs'>";
    foreach($jobs_by_date[$date_key] as $job){
      $status=trim((string)($job['status']??'')); $statusSafe=e($status);
      $cls='badge neutral';
      if(preg_match('/(ยืนยัน|เสร็จ|confirm)/iu',$status)) $cls='badge ok';
      elseif(preg_match('/(รอดดำเนินการ|รอดำเนินการ|pending)/iu',$status)) $cls='badge pending';
      elseif(preg_match('/(ยกเลิก|cancel)/iu',$status)) $cls='badge cancelled';

      $rawTime = substr((string)($job['job_time']??''),0,5);
      $timeTxt = e($rawTime); if($timeTxt==='') $timeTxt='—';
      $locRaw  = (string)($job['location']??'');
      $locTxt  = e($locRaw);
      $titleTip=trim(($rawTime?:'—').' '.$locRaw.' '.$status);

      $h.="<div class='job' title='".e($titleTip)."'>
              <div class='job-row'><span class='dot'></span><strong>$timeTxt</strong><span class='muted'> น.</span></div>";
      if($locTxt!=='') $h.="<div class='job-row place'>📍 <span class='ellipsis'>$locTxt</span></div>";
      $h.="<span class='$cls'>$statusSafe</span>";
      if($can_edit){
        $h.="<div class='job-actions'>
               <a class='mini-btn' href='{$self}?photographer_id=".$photographer_id."&month=".$month."&year=".$year."&edit_id=".$job['job_id']."'>แก้ไข</a>
               <form class='js-del-form' method='post' style='display:inline'>
                 <input type='hidden' name='csrf' value='".$csrf."'>
                 <input type='hidden' name='action' value='delete'>
                 <input type='hidden' name='job_id' value='".(int)$job['job_id']."'>
                 <button
                   class='mini-btn danger js-del-btn'
                   type='button'
                   data-date='".e($date_key)."'
                   data-time='".e($rawTime)."'
                   data-loc='".e($locRaw)."'
                 >ลบ</button>
               </form>
             </div>";
      }
      $h.="</div>";
    }
    $h.="</div>";
  }
  $h.="</td>";
  return $h;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ปฏิทินงานช่างภาพ - Cameraman</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box} html,body{height:100%}
body{ margin:0; font-family:'Prompt',sans-serif; color:#0f172a;
  background: linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%); display:flex; flex-direction:column;}
/* ===== Navbar / Layout ===== */
.navbar{width:100%; position:fixed; top:0; left:0; background: linear-gradient(90deg,#0b1220,#111827); display:flex; align-items:center; padding:14px 0; box-shadow:0 4px 20px rgba(0,0,0,.25); z-index:100;}
.nav-inner{ width:100%; display:flex; align-items:center; justify-content:space-between; padding:0 12px }
.logo{font-size:24px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px}
.menu{display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto}
.menu a,.menu .badge{ color:#fff; text-decoration:none; padding:8px 12px; border-radius:999px; transition:.25s ease; font-weight:700; font-size:14px }
.menu .badge{background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent; background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff; color:#0f172a}
.menu a.active{background:#60a5fa; color:#ffffff; border-color:#3b82f6; box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626; color:#fff}
.wrapper{ flex:1; display:flex; justify-content:center; align-items:flex-start; padding:120px 16px 48px }
.container{ width:100%; max-width:1200px; display:flex; flex-direction:column; gap:20px }
.card{ width:100%; background:#ffffff; border-radius:20px; box-shadow:0 12px 28px rgba(17,24,39,.18); padding:22px; border:1px solid #e5e7eb }
.page-title{display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px}
.page-title h1{margin:0; font-size:28px; font-weight:800; color:#0b1220}
.filters{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
.filters select{padding:8px 10px; border:1px solid #e5e7eb; border-radius:10px; background:#fff; font-size:14px}
.btn{background:linear-gradient(90deg,#0ea5e9,#6366f1); color:#fff !important; padding:8px 12px; border-radius:12px; text-decoration:none; font-weight:700; font-size:14px}
.btn.ghost{background:#f3f4f6; color:#0f172a !important}
.table-title{text-align:center;font-size:18px;font-weight:800;color:#0f172a;margin:8px 0}
.table-wrap{width:100%}
table.calendar{width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed}
thead th{background:linear-gradient(180deg,#0ea5e9,#6366f1);color:#fff;font-weight:800;font-size:13px;padding:12px 8px;text-align:center;border-radius:12px}
tbody td{background:#f9fafb;border-radius:14px;vertical-align:top;padding:10px;height:160px;border:1px solid #eef0f3;position:relative}
tbody td:hover{background:#f3f4f6;box-shadow:0 6px 16px rgba(0,0,0,.06)}
tbody td.empty{background:#f1f5f9;border:1px dashed #e2e8f0;color:#94a3b8}
.cell-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:6px}
.date{font-weight:800;color:#0f172a;font-size:14px}
.date.today{background:linear-gradient(90deg,#0ea5e9,#6366f1);color:#fff;padding:3px 10px;border-radius:999px;font-size:13px}
.mini-add{font-size:11px;background:#e0e7ff;border:1px solid #c7d2fe;padding:2px 8px;border-radius:999px;text-decoration:none;color:#3730a3}
.mini-add:hover{background:#c7d2fe}
.jobs{max-height:calc(100% - 30px);overflow:auto}
.job{background:#eef2ff;border:1px solid #e9eafe;color:#1d4ed8;padding:6px 8px;margin-bottom:6px;border-radius:10px;font-size:12px}
.job-row{display:flex;align-items:center;gap:6px}
.job .dot{width:7px;height:7px;border-radius:50%;background:#6366f1}
.badge{display:inline-block;margin-top:6px;font-weight:800;font-size:11.5px;padding:3px 8px;border-radius:999px}
.badge.ok{background:#dcfce7;color:#166534}
.badge.pending{background:#fef9c3;color:#92400e}
.badge.cancelled{background:#fee2e2;color:#991b1b}
.badge.neutral{background:#f1f5f9;color:#475569}
.job-actions{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}
.mini-btn{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#111}
.mini-btn:hover{background:#f3f4f6}
.mini-btn.danger{border-color:#fecaca;color:#991b1b}
.alert{margin:8px 0;padding:10px 12px;border-radius:12px;font-size:14px}
.alert.ok{background:#ecfeff;border:1px solid #a5f3fc;color:#164e63}
.alert.err{background:#fff1f2;border:1px solid #fecdd3;color:#7f1d1d}
.form-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 6px 18px rgba(0,0,0,.04);margin-bottom:8px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.form-grid .full{grid-column:1/-1}
.input,.select,.textarea{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;font-size:14px;background:#fff}
.actions{display:flex;gap:8px;margin-top:10px}

/* ===== Center Modal (แจ้งเตือน + ยืนยันลบ) ===== */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(2,6,23,.55);
  backdrop-filter:saturate(160%) blur(2px);
  display:none; align-items:center; justify-content:center; z-index:10000;
}
.modal{
  width:min(520px,92vw); background:#fff; border:1px solid #eef0f3;
  border-radius:18px; box-shadow:0 30px 60px rgba(2,6,23,.3);
  padding:18px 18px 16px; transform:translateY(12px) scale(.98); opacity:0;
  transition:.22s cubic-bezier(.2,.8,.2,1);
}
.modal.show{ transform:translateY(0) scale(1); opacity:1; }
.modal-icon{
  width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:26px; margin-right:10px;
}
.modal-header{ display:flex; align-items:center; gap:10px; margin-bottom:6px }
.modal-title{ font-size:18px; font-weight:800; color:#0b1220 }
.modal-text{ color:#374151; font-size:14px; line-height:1.6; margin:8px 0 2px }
.modal-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px }
.btn-md{ padding:10px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#fff; font-weight:700; cursor:pointer }
.btn-md:hover{ background:#f8fafc }

/* Theme */
.modal.success .modal-icon{ background:#dcfce7; color:#166534 }
.modal.success .modal-title{ color:#166534 }
.modal.error .modal-icon{ background:#fee2e2; color:#991b1b }
.modal.error .modal-title{ color:#991b1b }
.modal.info .modal-icon{ background:#e0e7ff; color:#3730a3 }
.modal.info .modal-title{ color:#3730a3 }

.modal-backdrop.show{ display:flex; }
@media (max-width:480px){
  .modal{ padding:16px 14px 14px; }
}
</style>
</head>
<body>

<!-- ===== Pretty Center Modal (แจ้งเตือนทั่วไป: success/error/info) ===== -->
<div id="appModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div id="appModalBox" class="modal" tabindex="-1">
    <div class="modal-header">
      <div id="appModalIcon" class="modal-icon">ℹ️</div>
      <div class="modal-title" id="appModalTitle">แจ้งเตือน</div>
    </div>
    <div class="modal-text" id="appModalText">ข้อความแจ้งเตือน</div>
    <div class="modal-actions">
      <button type="button" class="btn-md" id="appModalOk">ตกลง</button>
    </div>
  </div>
</div>

<!-- ===== Confirm Delete Modal (ยืนยันการลบ) ===== -->
<div id="confirmModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div id="confirmBox" class="modal error" tabindex="-1">
    <div class="modal-header">
      <div class="modal-icon">⛔</div>
      <div class="modal-title">ยืนยันการลบ</div>
    </div>
    <div class="modal-text" id="confirmText">ลบงานนี้ใช่ไหม?</div>
    <div class="modal-actions">
      <button type="button" class="btn-md" id="confirmCancel">ยกเลิก</button>
      <button type="button" class="btn-md" id="confirmOk" style="background:#ef4444;color:#fff;border-color:#ef4444">ลบงาน</button>
    </div>
  </div>
</div>

<script>
/* ===== Modal แจ้งเตือนทั่วไป ===== */
(function(){
  const text   = <?= json_encode($popup_text, JSON_UNESCAPED_UNICODE) ?>;
  const kind   = <?= json_encode($popup_kind, JSON_UNESCAPED_UNICODE) ?>; // success | error | info | ''
  if(!text) return;

  const backdrop = document.getElementById('appModal');
  const box      = document.getElementById('appModalBox');
  const icon     = document.getElementById('appModalIcon');
  const title    = document.getElementById('appModalTitle');
  const body     = document.getElementById('appModalText');
  const okBtn    = document.getElementById('appModalOk');

  const setKind = (k)=>{
    box.classList.remove('success','error','info');
    if(k==='success'){ box.classList.add('success'); icon.textContent='✅'; title.textContent='สำเร็จ'; }
    else if(k==='error'){ box.classList.add('error'); icon.textContent='⛔'; title.textContent='ไม่สามารถทำรายการ'; }
    else { box.classList.add('info'); icon.textContent='ℹ️'; title.textContent='แจ้งเตือน'; }
  };

  const open = ()=>{
    setKind(kind || 'info');
    body.textContent = text;
    backdrop.classList.add('show');
    setTimeout(()=> box.classList.add('show'), 10);
    box.focus();
  };

  const close = ()=>{
    box.classList.remove('show');
    setTimeout(()=> backdrop.classList.remove('show'), 180);
  };

  okBtn.addEventListener('click', close);
  backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) close(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

  open();
})();

/* ===== Confirm Delete: จับปุ่มลบ แล้วเด้ง modal สวยๆ ===== */
(function(){
  const backdrop = document.getElementById('confirmModal');
  const box      = document.getElementById('confirmBox');
  const textEl   = document.getElementById('confirmText');
  const cancelBt = document.getElementById('confirmCancel');
  const okBt     = document.getElementById('confirmOk');

  let pendingForm = null;

  const open = (msg, form)=>{
    pendingForm = form;
    textEl.textContent = msg || 'ลบงานนี้ใช่ไหม?';
    backdrop.classList.add('show');
    setTimeout(()=> box.classList.add('show'), 10);
    box.focus();
  };
  const close = ()=>{
    box.classList.remove('show');
    setTimeout(()=> { backdrop.classList.remove('show'); pendingForm=null; }, 180);
  };

  cancelBt.addEventListener('click', close);
  backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) close(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

  okBt.addEventListener('click', ()=>{
    if(pendingForm){ pendingForm.submit(); }
    close();
  });

  document.addEventListener('click', function(e){
    const btn = e.target.closest('.js-del-btn');
    if(!btn) return;

    const form = btn.closest('form');
    const d = btn.dataset.date || '';
    const t = btn.dataset.time || '';
    const l = btn.dataset.loc  || '';
    let msg = 'ลบงานนี้ใช่ไหม?';
    const when = [d, t].filter(Boolean).join(' ');
    if(when || l){
      msg = `ลบงานนี้ใช่ไหม?\n\nวันที่/เวลา: ${when || '-'}\nสถานที่: ${l || '-'}`;
    }
    open(msg, form);
  });
})();
</script>

<!-- ===== Navbar ===== -->
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman</span></div>
    <div class="menu">
      <?php if ($firstName!==''): ?><span class="badge">👋 สวัสดี, <?= h($firstName) ?></span><?php endif; ?>
      <a href="photographer_bookings.php">หน้าแรก</a>
      <a href="photographer_dashboard.php">ข้อมูลทั่วไป</a>
      <a href="photographer_delivery_links.php">ส่งงานลูกค้า</a>
      <a href="contact_admin_photographer.php">ติดต่อผู้ดูแลระบบ</a>
      <a href="photographer_bookings_crud.php">การจองคิว</a>
      <a href="locations_add.php">เพิ่มสถานที่แนะนำ</a>
      <a href="photographer_deposits.php">การมัดจำ</a>
      <a href="photographer_edit_profile.php">แก้ไขข้อมูล</a>
      <a href="<?= h($self) ?>" class="active">ปฏิทินงาน</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
  </div>
</div>

<div class="wrapper">
  <div class="container">

    <div class="page-title">
      <h1>🗓️ ปฏิทินงานช่างภาพ</h1>
      <form method="get" class="filters" action="<?= h($self) ?>">
        <input type="hidden" name="photographer_id" value="<?= (int)$photographer_id ?>">
        <label>เดือน
          <select name="month" onchange="this.form.submit()">
            <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?= $m ?>" <?= $m==$month?'selected':'' ?>><?= e(thai_month($m)) ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label>ปี
          <select name="year" onchange="this.form.submit()">
            <?php for($y=date('Y')-2;$y<=date('Y')+2;$y++): ?>
              <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y+543 ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </form>
    </div>

    <?php if($msg_ok!==''): ?><div class="alert ok"><?= h($msg_ok) ?></div><?php endif; ?>
    <?php if($has_err): ?><div class="alert err">เกิดข้อผิดพลาดในการดำเนินการ</div><?php endif; ?>

    <?php if ($can_edit && ($form_mode==='create' || $form_mode==='update')): ?>
      <div class="form-card card">
        <form method="post" action="<?= h($self) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="<?= $form_mode==='create'?'create':'update' ?>">
          <?php if ($form_mode==='update'): ?><input type="hidden" name="job_id" value="<?= (int)$edit_job_id ?>"><?php endif; ?>
          <div class="form-grid">
            <div>
              <label>วันที่</label>
              <input class="input" type="date" name="job_date" value="<?= h($prefill['job_date']) ?>" required>
            </div>
            <div>
              <label>เวลา</label>
              <input class="input" type="time" name="job_time" value="<?= h($prefill['job_time']) ?>">
            </div>
            <div class="full">
              <label>สถานที่</label>
              <input class="input" type="text" name="location" value="<?= h($prefill['location']) ?>" placeholder="เช่น วัดพระแก้ว, สตูดิโอ A">
            </div>
            <div>
              <label>สถานะ</label>
              <select class="select" name="status">
                <option value="">— เลือกสถานะ —</option>
                <?php foreach($status_opts as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= ($prefill['status']===$opt)?'selected':'' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="full">
              <label>หมายเหตุ</label>
              <textarea class="textarea" name="note" rows="3" placeholder="รายละเอียดเพิ่มเติม..."><?= h($prefill['note']) ?></textarea>
            </div>
          </div>
          <div class="actions">
            <button class="btn" type="submit"><?= $form_mode==='create'?'บันทึกงาน':'บันทึกการแก้ไข' ?></button>
            <a class="btn ghost" href="<?= h($self) ?>?photographer_id=<?= (int)$photographer_id ?>&month=<?= (int)$month ?>&year=<?= (int)$year ?>">ยกเลิก</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="table-title">
        ตารางงาน <?= e(thai_month($month).' '.($year+543)) ?>
        <?php if ($photographer_id>0): ?> — <?= e($photographers[$photographer_id] ?? '') ?> <?php endif; ?>
      </div>

      <div class="table-wrap">
        <table class="calendar">
          <thead>
            <tr>
              <th>อาทิตย์</th><th>จันทร์</th><th>อังคาร</th><th>พุธ</th>
              <th>พฤหัสบดี</th><th>ศุกร์</th><th>เสาร์</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $first=strtotime(sprintf('%04d-%02d-01',$year,$month));
            $days=(int)date('t',$first);
            $start_w=(int)date('w',$first);
            $weeks=6; $day=1-$start_w;
            for($r=0;$r<$weeks;$r++){
              echo '<tr>';
              for($c=0;$c<7;$c++,$day++){
                if($day<1 || $day>$days){ echo '<td class="empty"></td>'; }
                else{ echo renderDay($year,$month,$day,$jobs_by_date,$can_edit,$photographer_id,$csrf); }
              }
              echo '</tr>';
            }
            ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
