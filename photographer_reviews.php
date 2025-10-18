<?php
session_start();
require 'db.php';
mysqli_set_charset($conn,'utf8mb4');

if(!isset($_GET['photographer_id'])){die("ไม่พบช่างภาพ");}
$photographer_id=(int)$_GET['photographer_id'];
$current_user_id=$_SESSION['user_id']??null;

$BASE_URL=rtrim(dirname($_SERVER['PHP_SELF']),'/\\').'/';
function img_src($raw,$fallback='images/default_user.png'){global $BASE_URL;$p=trim(str_replace('\\','/',$raw?:$fallback));if(preg_match('#^https?://#i',$p))return $p;if(!preg_match('#^(uploads|images)/#i',$p))$p='uploads/'.ltrim($p,'/');return $BASE_URL.ltrim($p,'/');}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$sql="SELECT p.*,e.expertise_name FROM photographer p LEFT JOIN expertise e ON p.expertise_id=e.expertise_id WHERE p.photographer_id=?";
$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$photographer=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$photographer)die("ไม่พบช่างภาพ");

$photoImg=$photographer['profile_image']??($photographer['profile_image_path']??'');
if(!$photoImg)$photoImg='images/default_user.png';

if($_SERVER['REQUEST_METHOD']==='POST'&&$current_user_id){
  $score=(int)($_POST['score']??0);
  $comment=trim($_POST['comment']??'');
  if($score<1||$score>5){$_SESSION['flash']="กรุณาให้คะแนน 1-5";}
  else{
    $sql="INSERT INTO photographerrating(photographer_id,user_id,score,comment,created_at) VALUES(?,?,?,?,NOW())";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iiis",$photographer_id,$current_user_id,$score,$comment);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash']="✅ ขอบคุณสำหรับรีวิว";
  }
  header("Location: ".$_SERVER['PHP_SELF']."?photographer_id=".$photographer_id);
  exit;
}
$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);

$stmt=$conn->prepare("SELECT AVG(score) AS avg_rating,COUNT(*) AS total_reviews FROM photographerrating WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$summary=$stmt->get_result()->fetch_assoc();
$stmt->close();
$avg=(float)($summary['avg_rating']??0);
$cnt=(int)($summary['total_reviews']??0);

$perpage=8;
$page=max(1,(int)($_GET['page']??1));
$offset=($page-1)*$perpage;

$stmt=$conn->prepare("SELECT r.score,r.comment,r.created_at,u.first_name,u.last_name FROM photographerrating r LEFT JOIN users u ON r.user_id=u.user_id WHERE r.photographer_id=? ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii",$photographer_id,$perpage,$offset);
$stmt->execute();
$reviews=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) AS c FROM photographerrating WHERE photographer_id=?");
$stmt->bind_param("i",$photographer_id);
$stmt->execute();
$total=$stmt->get_result()->fetch_assoc()['c']??0;
$stmt->close();
$pages=max(1,(int)ceil($total/$perpage));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รีวิวของ <?=h(($photographer['first_name']??'').' '.($photographer['last_name']??''))?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#ffecd2,#fcb69f);color:#111;display:flex;flex-direction:column;align-items:center}
.navbar{width:100%;position:fixed;top:0;left:0;background:#0f172a;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;box-shadow:0 8px 24px rgba(2,6,23,.25);z-index:100}
.logo{font-size:24px;font-weight:bold;color:#fff}
.menu{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none}
.menu::-webkit-scrollbar{display:none}
.menu a{text-decoration:none;font-size:14px;color:#fff;font-weight:500;padding:6px 8px;border-radius:8px;transition:.18s}
.menu a:hover{background:#22d3ee;color:#0f172a}
.menu a.active,.menu a[aria-current="page"]{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.18)}
.menu a.logout{background:linear-gradient(90deg,#f43f5e,#f59e42);color:#fff}
.header-spacer{height:100px}
.container{max-width:1100px;width:100%;padding:0 20px 40px}
.card{background:#fff;border-radius:20px;padding:30px;box-shadow:0 8px 20px rgba(0,0,0,.15)}
.profile{display:flex;align-items:center;flex-wrap:wrap;gap:20px;margin-bottom:16px}
.profile img{width:120px;height:120px;object-fit:cover;border-radius:50%;border:4px solid #7c3aed;box-shadow:0 6px 16px rgba(0,0,0,.18)}
.profile-info h2{color:#6a11cb;margin-bottom:6px;font-size:22px}
.profile-info p{margin:4px 0;font-size:14.5px;color:#334155}
.stats{margin-top:6px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.badge{background:#fef3c7;padding:6px 10px;border-radius:10px;font-weight:800}
.badge.muted{background:#eef2ff}
.btn-group{margin-top:10px;display:flex;flex-wrap:wrap;gap:10px}
.btn-group a{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;padding:9px 16px;border-radius:10px;text-decoration:none;transition:.18s}
.btn-group a:hover{transform:translateY(-1px);filter:brightness(1.05)}
.block{margin-top:16px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
.block h3{color:#6a11cb;border-bottom:2px solid #e9d5ff;padding-bottom:6px;margin:0 0 12px}
.flash{margin-bottom:10px;background:#ecfdf5;border:1px solid #34d399;color:#065f46;padding:10px;border-radius:10px}
.review-list{display:grid;gap:10px}
.review-item{background:#fafafa;border:1px solid #eee;padding:10px 12px;border-radius:10px}
.review-head{display:flex;gap:8px;align-items:center;font-weight:800;color:#111}
.review-date{color:#64748b;font-weight:400;font-size:12.5px;margin-left:auto}
.review-comment{margin-top:6px;color:#111;white-space:pre-wrap;line-height:1.7}
.stars{color:#f59e0b}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.pager a,.pager span{padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#111;background:#fff}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);border-color:transparent;color:#fff}
form.review-form{display:grid;gap:10px;margin-top:10px}
textarea{width:100%;min-height:110px;padding:10px;border-radius:10px;background:#fff;color:#111;border:1px solid #e5e7eb}
select{padding:10px;border-radius:10px;background:#fff;color:#111;border:1px solid #e5e7eb}
button{padding:10px 14px;border-radius:12px;background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border:none;font-weight:800;cursor:pointer}
button:hover{filter:brightness(1.05)}
@media(max-width:640px){.menu a{font-size:13px;padding:5px 6px}.header-spacer{height:92px}}
</style>
</head>
<body>

<div class="navbar">
  <div class="logo">📸 Cameraman</div>
  <div class="menu">
    <a href="<?=h($BASE_URL)?>index1.php">หน้าแรก</a>
    <a href="<?=h($BASE_URL)?>user_dashboard.php">ข้อมูลส่วนตัว</a>
    <a href="<?=h($BASE_URL)?>view_photographers.php">ค้นหาช่างภาพ</a>
    <a href="<?=h($BASE_URL)?>photographer_popularity.php" class="active" aria-current="page">ดูคะแนนช่างภาพ ⭐</a>
    <a href="<?=h($BASE_URL)?>locations_recommend.php">สถานที่แนะนำ 📍</a>
    <a href="<?=h($BASE_URL)?>upload_payment_proof.php">อัปโหลดหลักฐานโอนเงิน</a>
    <a href="<?=h($BASE_URL)?>contact_admin.php">ติดต่อผู้ดูแลระบบ</a>
    <a href="<?=h($BASE_URL)?>edit_profile.php">แก้ไขข้อมูล</a>
    <a href="<?=h($BASE_URL)?>logout.php" class="logout">ออกจากระบบ</a>
  </div>
</div>

<div class="header-spacer"></div>

<div class="container">
  <div class="card">
    <div class="profile">
      <img src="<?=h(img_src($photoImg))?>" alt="รูปโปรไฟล์" onerror="this.onerror=null;this.src='<?=h($BASE_URL)?>images/default_user.png'">
      <div class="profile-info">
        <h2>รีวิวของ <?=h(($photographer['first_name']??'').' '.($photographer['last_name']??''))?></h2>
        <div class="stats">
          <span class="badge">⭐ <?=number_format($avg,1)?>/5</span>
          <span class="badge muted">💬 <?=$cnt?> รีวิว</span>
        </div>
        <div class="btn-group">
          <a href="<?=h($BASE_URL)?>photographer_detail.php?id=<?=$photographer_id?>">⬅️ กลับหน้าช่างภาพ</a>
          <a href="<?=h($BASE_URL)?>photographer_schedule.php?photographer_id=<?=$photographer_id?>">📖 ดูตารางงาน</a>
          <a href="<?=h($BASE_URL)?>booking_form.php?photographer_id=<?=$photographer_id?>">📅 จองช่างภาพนี้</a>
        </div>
      </div>
    </div>

    <?php if($flash):?><div class="flash"><?=h($flash)?></div><?php endif;?>

    <div class="block">
      <h3>📝 รีวิวย้อนหลัง</h3>
      <?php if(!$reviews):?>
        <p style="color:#64748b">ยังไม่มีรีวิว</p>
      <?php else:?>
        <div class="review-list">
          <?php foreach($reviews as $rv):?>
            <div class="review-item">
              <div class="review-head">
                <div><?=h(($rv['first_name']??'ผู้ใช้').' '.($rv['last_name']??''))?></div>
                <div class="stars">⭐ <?= (int)$rv['score']?>/5</div>
                <div class="review-date"><?=h(date('d/m/Y H:i',strtotime($rv['created_at'])))?></div>
              </div>
              <?php if(!empty($rv['comment'])):?>
                <div class="review-comment"><?=nl2br(h($rv['comment']))?></div>
              <?php endif;?>
            </div>
          <?php endforeach;?>
        </div>
      <?php endif;?>
      <div class="pager">
        <?php for($i=1;$i<=$pages;$i++):?>
          <?php if($i==$page):?><span class="active"><?=$i?></span>
          <?php else:?><a href="?photographer_id=<?=$photographer_id?>&page=<?=$i?>"><?=$i?></a><?php endif;?>
        <?php endfor;?>
      </div>
    </div>

  </div>
</div>

</body>
</html>
