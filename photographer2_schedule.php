<?php
require 'db.php';
mysqli_set_charset($conn, 'utf8mb4');

function thai_month($m){static $map=[1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];return $map[$m]??'';}
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$month = isset($_GET['month']) ? max(1,min(12,(int)$_GET['month'])) : (int)date('m');
$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$photographer_id = isset($_GET['photographer_id']) ? (int)$_GET['photographer_id'] : 0;

function renderDay($year,$month,$day,$jobs_by_date){
  $date_key = sprintf("%04d-%02d-%02d",$year,$month,$day);
  $is_today = ($date_key===date('Y-m-d'));
  $h = "<td>";
  $h.= "<span class='date".($is_today?' today':'')."'>$day</span>";
  if (!empty($jobs_by_date[$date_key])) {
    $h.= "<div class='jobs'>";
    foreach ($jobs_by_date[$date_key] as $job) {
      $status = trim((string)($job['status']??''));
      $cls = 'badge neutral';
      if (preg_match('/(ยืนยัน|เสร็จ|confirm)/iu',$status)) $cls='badge ok';
      elseif (preg_match('/(รอดำเนินการ|pending)/iu',$status)) $cls='badge pending';
      elseif (preg_match('/(ยกเลิก|cancel)/iu',$status)) $cls='badge cancelled';
      $timeTxt = e(substr((string)($job['job_time']??''),0,5)); if($timeTxt==='') $timeTxt='—';
      $locTxt  = e((string)($job['location']??''));
      $title   = trim($timeTxt.' '.$locTxt.' '.$status);
      $h.= "<div class='job' title='".e($title)."'>";
      $h.=   "<div class='job-row'><span class='dot'></span><strong>$timeTxt</strong><span class='muted'> น.</span></div>";
      if ($locTxt!=='') $h.= "<div class='job-row place'>📍 <span class='ellipsis'>$locTxt</span></div>";
      $h.=   "<span class='$cls'>".e($status)."</span>";
      $h.= "</div>";
    }
    $h.= "</div>";
  }
  $h.= "</td>";
  return $h;
}

$photographers=[];
$res=$conn->query("SELECT photographer_id, CONCAT(first_name,' ',last_name) AS name FROM photographer");
if($res instanceof mysqli_result){while($row=$res->fetch_assoc()){$photographers[(int)$row['photographer_id']]=$row['name'];}$res->free();}

$jobs_by_date=[];
if($photographer_id>0){
  $start_date=sprintf('%04d-%02d-01',$year,$month);
  $end_date=date('Y-m-t',strtotime($start_date));
  $sql="SELECT j.*, CONCAT(p.first_name,' ',p.last_name) AS photographer_name
        FROM job j
        JOIN photographer p ON p.photographer_id=j.photographer_id
        WHERE j.photographer_id=?
          AND ((j.job_date BETWEEN ? AND ?) OR (j.job_date BETWEEN DATE_ADD(?,INTERVAL 543 YEAR) AND DATE_ADD(?,INTERVAL 543 YEAR)))
        ORDER BY j.job_date, j.job_time";
  if($stmt=$conn->prepare($sql)){
    $stmt->bind_param("issss",$photographer_id,$start_date,$end_date,$start_date,$end_date);
    $stmt->execute();$result=$stmt->get_result();
    if($result instanceof mysqli_result){
      while($row=$result->fetch_assoc()){
        $date=$row['job_date'];$y=(int)substr($date,0,4);
        if($y>2200)$date=date('Y-m-d',strtotime($date.' -543 years'));
        $jobs_by_date[$date][]=$row;
      }
      $result->free();
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ปฏิทินงานช่างภาพ</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
:root{--nav-h:72px;--grad:linear-gradient(90deg,#7c3aed,#2563eb)}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);padding-top:var(--nav-h);display:flex;flex-direction:column;align-items:center}
.navbar{position:fixed;top:0;left:0;right:0;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;background:#0f172a;padding:10px 20px;z-index:1000}
.logo{font-size:20px;font-weight:800;color:#fff}
.nav-links{list-style:none;display:flex;gap:10px;align-items:center}
.nav-links li{position:relative}
.nav-links a{text-decoration:none;font-size:13px;color:#fff;padding:8px 10px;border-radius:10px;transition:.18s}
.nav-links a:hover{background:#22d3ee;color:#0f172a}
.dropdown-menu{position:absolute;top:calc(100% + 6px);left:0;min-width:190px;padding:8px;background:#fff;border-radius:12px;box-shadow:0 10px 24px rgba(2,6,23,.18);display:none}
.dropdown-menu a{display:block;color:#0f172a;padding:8px 10px;border-radius:8px;font-size:13px}
.dropdown-menu a:hover{background:#eef2ff}
.dropdown:hover .dropdown-menu{display:block}
.hamburger{display:none;width:28px;height:20px;flex-direction:column;justify-content:space-between;cursor:pointer}
.hamburger span{display:block;height:2.5px;background:#fff;border-radius:3px}
.stage{width:100%;display:flex;justify-content:center;align-items:center;padding:16px 12px 28px}
.calendar-card{width:min(1280px,96vw);background:#fff;border-radius:22px;box-shadow:0 22px 60px rgba(14,17,22,.12);padding:18px;display:flex;flex-direction:column;gap:14px}
.header{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.title{display:inline-flex;align-items:center;gap:10px;background:var(--grad);color:#fff;padding:8px 12px;border-radius:14px;font-weight:700;font-size:14px}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filters select{padding:8px 10px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;font-size:14px;outline:none}
.btn{background:var(--grad);color:#fff;text-decoration:none;padding:8px 12px;border-radius:12px;font-weight:700;font-size:14px}
.table-title{text-align:center;font-size:17px;font-weight:700;color:#1f2937;margin:2px 0 8px}
.table-wrap{width:100%}
table.calendar{width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed}
thead th{background:linear-gradient(180deg,#7c3aed,#2563eb);color:#fff;font-weight:700;font-size:12.5px;padding:10px 8px;text-align:center;border-radius:12px}
tbody td{background:#f9fafb;border-radius:14px;vertical-align:top;padding:10px;height:140px;border:1px solid #eef0f3;position:relative}
tbody td:hover{background:#f3f4f6;box-shadow:0 6px 16px rgba(0,0,0,.06)}
tbody td.empty{background:#f1f5f9;border:1px dashed #e2e8f0;color:#94a3b8}
.date{font-weight:700;color:#1f2937;font-size:13px;display:inline-block;margin-bottom:6px}
.date.today{background:var(--grad);color:#fff;padding:3px 10px;border-radius:999px;font-size:12px}
.jobs{max-height:calc(100% - 26px);overflow:auto}
.job{background:#faf5ff;border:1px solid #e9d5ff;color:#4c1d95;padding:6px 8px;margin-bottom:6px;border-radius:12px;font-size:12px}
.job-row{display:flex;align-items:center;gap:6px}
.job .dot{width:7px;height:7px;border-radius:50%;background:#7c3aed}
.badge{display:inline-block;margin-top:6px;font-weight:700;font-size:11.5px;padding:3px 8px;border-radius:999px}
.badge.ok{background:#dcfce7;color:#166534}
.badge.pending{background:#fef9c3;color:#92400e}
.badge.cancelled{background:#fee2e2;color:#991b1b}
.badge.neutral{background:#f1f5f9;color:#475569}
@media(max-width:980px){
  .hamburger{display:flex}
  .nav-links{position:fixed;right:0;top:var(--nav-h);bottom:0;width:240px;background:#0f172a;flex-direction:column;padding:12px;gap:8px;transform:translateX(110%);transition:transform .25s}
  .nav-links.active{transform:translateX(0)}
  .dropdown:hover .dropdown-menu{display:none}
  .dropdown-menu{position:static;background:#111827}
  .dropdown-menu a{color:#e5e7eb}
}
</style>
</head>
<body>
<div class="navbar">
  <div class="logo">📷 Cameraman</div>
  <ul class="nav-links" id="navLinks">
    <li><a href="index.php">หน้าแรก</a></li>
    <li class="dropdown">
      <a href="javascript:void(0)">สมัครสมาชิก ▾</a>
      <ul class="dropdown-menu">
        <li><a href="register_user.php">สมาชิก</a></li>
        <li><a href="register_photographer.php">ช่างภาพ</a></li>
      </ul>
    </li>
    <li class="dropdown">
      <a href="javascript:void(0)">เข้าสู่ระบบ ▾</a>
      <ul class="dropdown-menu">
        <li><a href="login_user.php">สมาชิก</a></li>
        <li><a href="login_photographer.php">ช่างภาพ</a></li>
        <li><a href="login_admin.php">ผู้ดูแลระบบ</a></li>
      </ul>
    </li>
  </ul>
  <div class="hamburger" id="hamburger"><span></span><span></span><span></span></div>
</div>

<main class="stage">
  <div class="calendar-card">
    <div class="header">
      <div class="title">🗓️ ปฏิทินงานช่างภาพ</div>
      <form method="get" class="filters">
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
        <?php if ($photographer_id>0): ?>
          <a class="btn" href="login_user.php?photographer_id=<?= (int)$photographer_id ?>">📅 ไปจอง</a>
          <a class="btn" href="photographer2_detail.php?id=<?= (int)$photographer_id ?>">🔍 รายละเอียด</a>
          <a class="btn" href="photographer2_reviews.php?photographer_id=<?= (int)$photographer_id ?>">⭐ รีวิว</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-title">
      ตารางงาน <?= e(thai_month($month).' '.($year+543)) ?><?php if ($photographer_id>0): ?> — <?= e($photographers[$photographer_id] ?? '') ?><?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="calendar">
        <thead>
          <tr>
            <th>อาทิตย์</th><th>จันทร์</th><th>อังคาร</th><th>พุธ</th><th>พฤหัสบดี</th><th>ศุกร์</th><th>เสาร์</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $first=strtotime(sprintf('%04d-%02d-01',$year,$month));
          $days=(int)date('t',$first);
          $start_w=(int)date('w',$first);
          $weeks=6;$day=1-$start_w;
          for($r=0;$r<$weeks;$r++){
            echo '<tr>';
            for($c=0;$c<7;$c++,$day++){
              if($day<1||$day>$days) echo '<td class="empty"></td>';
              else echo renderDay($year,$month,$day,$jobs_by_date);
            }
            echo '</tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
const nav=document.getElementById('navLinks');
const burger=document.getElementById('hamburger');
burger&&burger.addEventListener('click',()=>nav.classList.toggle('active'));
nav&&nav.querySelectorAll('a').forEach(a=>{a.addEventListener('click',()=>nav.classList.remove('active'))});
nav&&nav.querySelectorAll(':scope > li.dropdown > a').forEach(btn=>{
  btn.addEventListener('click',e=>{
    if(matchMedia('(max-width: 980px)').matches){
      e.preventDefault();
      btn.parentElement.classList.toggle('open');
      const dd=btn.nextElementSibling;
      if(dd){dd.style.display=dd.style.display==='block'?'none':'block'}
    }
  });
});
</script>
</body>
</html>
