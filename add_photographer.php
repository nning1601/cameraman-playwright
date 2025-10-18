<?php
session_start();
require 'db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: login_admin.php"); exit; }
mysqli_set_charset($conn,'utf8mb4');

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function safe_name($s){ return preg_replace('/[^a-zA-Z0-9_\-\.]/','_', (string)$s); }
function ensureUploads(){ if(!is_dir('uploads')) mkdir('uploads',0775,true); }

/* ===== กำหนดเงื่อนไขรูปภาพ ===== */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2MB
const MIN_IMG_W = 100;
const MIN_IMG_H = 100;
const MAX_IMG_W = 2000;
const MAX_IMG_H = 2000;
$ALLOWED_EXT  = ['jpg','jpeg','png','gif','webp'];                 // นามสกุลไฟล์ (นามสกุลไฟล์)
$ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp']; // ชนิดไฟล์จริง (MIME)

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

$error=''; $success='';
$first_name=''; $last_name=''; $email=''; $phone=''; $price_rate='';
$types_sel=[]; $expertise_id=0;
$expertise=[];
if($rs=$conn->query("SELECT expertise_id, expertise_name FROM expertise ORDER BY expertise_name")){
  while($r=$rs->fetch_assoc()){ $expertise[(int)$r['expertise_id']]=$r['expertise_name']; }
  $rs->close();
}
$all_types=['wedding','pre-wedding','portrait','product','event'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  $first_name=trim($_POST['first_name']??'');
  $last_name =trim($_POST['last_name']??'');
  $email     =trim($_POST['email']??'');
  $phone     =trim($_POST['phone']??'');
  $price_rate=trim($_POST['price_rate']??'');
  $expertise_id=(int)($_POST['expertise_id']??0);
  $types_sel = $_POST['types']??[];
  $types_str = implode(',', array_map('strval',$types_sel));

  if(!$first_name||!$last_name||!$email||!$phone){
    $error='กรอกข้อมูลที่จำเป็นให้ครบ';
  } elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
    $error='อีเมลไม่ถูกต้อง';
  } elseif($expertise_id && !isset($expertise[$expertise_id])){
    $error='หมวดความเชี่ยวชาญไม่ถูกต้อง';
  } else {
    $dup=$conn->prepare('SELECT photographer_id FROM photographer WHERE email=? LIMIT 1');
    $dup->bind_param('s',$email); $dup->execute(); $dup->store_result();
    if($dup->num_rows>0){ $error='อีเมลนี้ถูกใช้แล้ว'; }
    $dup->close();
  }

  /* ===== ตรวจรูปโปรไฟล์ (ถ้ามี) ===== */
  $profile_image=null;
  if(!$error && isset($_FILES['profile_image']) && $_FILES['profile_image']['error']!==UPLOAD_ERR_NO_FILE){
    $pf = $_FILES['profile_image'];
    if($pf['error'] !== UPLOAD_ERR_OK){
      $error = "อัปโหลดรูปโปรไฟล์ไม่สำเร็จ (รหัส: {$pf['error']})";
    } else {
      if($pf['size'] > MAX_IMAGE_BYTES){
        $error = "ไฟล์รูปโปรไฟล์ต้องไม่เกิน 2MB";
      }
      $ext = strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION));
      if(!$error && !in_array($ext, $ALLOWED_EXT, true)){
        $error = "รูปโปรไฟล์ต้องเป็นไฟล์: ".implode(', ', $ALLOWED_EXT);
      }
      if(!$error){
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($pf['tmp_name']) ?: '';
        if(!in_array($mime, $ALLOWED_MIME, true)){
          $error = "ชนิดไฟล์รูปโปรไฟล์ไม่ถูกต้อง (MIME: $mime)";
        }
      }
      if(!$error){
        $wh = @getimagesize($pf['tmp_name']);
        if(!$wh){ $error = "ไม่สามารถอ่านไฟล์รูปโปรไฟล์ได้"; }
        else{
          [$w,$h] = $wh;
          if($w<MIN_IMG_W || $h<MIN_IMG_H){
            $error = "ขนาดรูปโปรไฟล์เล็กเกินไป (อย่างน้อย ".MIN_IMG_W."×".MIN_IMG_H." พิกเซล)";
          } elseif($w>MAX_IMG_W || $h>MAX_IMG_H){
            $error = "ขนาดรูปโปรไฟล์ใหญ่เกินไป (ไม่เกิน ".MAX_IMG_W."×".MAX_IMG_H." พิกเซล)";
          }
        }
      }
      if(!$error){
        ensureUploads();
        $base = safe_name(pathinfo($pf['name'], PATHINFO_FILENAME));
        $name = 'pf_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'_'.$base.'.'.$ext;
        $dest = 'uploads/'.$name;
        if(move_uploaded_file($pf['tmp_name'],$dest)){
          // เก็บเฉพาะชื่อไฟล์ (ให้ตรงกับโค้ดเดิมของคุณ)
          $profile_image = $name;
        } else {
          $error = "ย้ายไฟล์รูปโปรไฟล์ไม่สำเร็จ";
        }
      }
    }
  }

  /* ===== ตรวจ Portfolio (หลายไฟล์) ===== */
  $portfolio_files=[];
  if(!$error && isset($_FILES['portfolio_images']) && is_array($_FILES['portfolio_images']['name'])){
    $count = count($_FILES['portfolio_images']['name']);
    ensureUploads();
    for($i=0;$i<$count;$i++){
      // ข้ามไฟล์ที่ไม่ได้เลือกจริง
      if($_FILES['portfolio_images']['error'][$i]===UPLOAD_ERR_NO_FILE) continue;

      if($_FILES['portfolio_images']['error'][$i]!==UPLOAD_ERR_OK){
        // ไม่ stop ทั้งชุด แค่ข้ามไฟล์ที่ผิด
        continue;
      }
      // ขนาดไฟล์
      if($_FILES['portfolio_images']['size'][$i] > MAX_IMAGE_BYTES){ continue; }

      $ext = strtolower(pathinfo($_FILES['portfolio_images']['name'][$i], PATHINFO_EXTENSION));
      if(!in_array($ext, $ALLOWED_EXT, true)){ continue; }

      // ตรวจ MIME
      $tmp  = $_FILES['portfolio_images']['tmp_name'][$i];
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($tmp) ?: '';
      if(!in_array($mime, $ALLOWED_MIME, true)){ continue; }

      // ตรวจขนาดพิกเซล
      $wh = @getimagesize($tmp);
      if(!$wh){ continue; }
      [$w,$h] = $wh;
      if($w<MIN_IMG_W || $h<MIN_IMG_H || $w>MAX_IMG_W || $h>MAX_IMG_H){ continue; }

      // ผ่าน -> บันทึก
      $base = safe_name(pathinfo($_FILES['portfolio_images']['name'][$i], PATHINFO_FILENAME));
      $name = 'port_'.date('Ymd_His')."_{$i}_".bin2hex(random_bytes(2))."_".$base.'.'.$ext;
      $dest = 'uploads/'.$name;
      if(move_uploaded_file($tmp,$dest)){
        // เก็บเฉพาะชื่อไฟล์ให้ตรงกับรูปแบบเดิม
        $portfolio_files[] = $name;
      }
    }
  }

  /* ===== บันทึก DB ===== */
  if(!$error){
    $ins=$conn->prepare('INSERT INTO photographer (first_name,last_name,email,phone,price_rate,profile_image,types,expertise_id,portfolio_images) VALUES (?,?,?,?,?,?,?,?,?)');
    $ports = $portfolio_files ? implode(',',$portfolio_files) : null;
    $ins->bind_param('sssssssis',$first_name,$last_name,$email,$phone,$price_rate,$profile_image,$types_str,$expertise_id,$ports);
    if($ins->execute()){
      $success='เพิ่มช่างภาพเรียบร้อยแล้ว';
      $first_name=$last_name=$email=$phone=$price_rate='';
      $types_sel=[]; $expertise_id=0;
    } else {
      $error='บันทึกข้อมูลไม่สำเร็จ';
    }
    $ins->close();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>เพิ่มช่างภาพ - Cameraman Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;font-family:'Prompt',sans-serif;color:#0f172a;background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);display:flex;flex-direction:column}
.navbar{width:100%;position:fixed;top:0;left:0;background:linear-gradient(90deg,#0b1220,#111827);display:flex;align-items:center;padding:14px 0;box-shadow:0 4px 20px rgba(0,0,0,.25);z-index:100}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
.logo{font-size:24px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.menu{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.menu a,.menu .badge{color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;transition:.25s ease;font-weight:700;font-size:14px}
.menu .badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}
.menu a{border:1px solid transparent;background:rgba(255,255,255,.06)}
.menu a:hover{background:#fff;color:#0f172a}
.menu a.active{background:#60a5fa;color:#ffffff;border-color:#3b82f6;box-shadow:0 6px 16px rgba(59,130,246,.35)}
.menu .logout{background:#ef4444}
.menu .logout:hover{background:#dc2626;color:#fff}
.burger{display:none}
@media (max-width:900px){.menu{display:none}.burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}.drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}.drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}.drawer a.active{background:#60a5fa;color:#fff}}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:800px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 12px;color:#0b1220;font-weight:900;text-align:center}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-outline{background:#ffffff;color:#0f172a;border:1px solid #d1d5db}
.label{font-weight:800;margin-bottom:6px;display:block}
.input,.select,.file{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (max-width:700px){.grid{grid-template-columns:1fr}}
.checks{display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-block;background:#eef2ff;border:1px solid #dbe2ff;border-radius:999px;padding:4px 8px;font-weight:800}
.preview{display:none;margin-top:6px}
.preview img{width:84px;height:84px;object-fit:cover;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.12);border:1px solid #e5e7eb}
.alert{padding:12px 14px;border-radius:12px;font-weight:700}
.alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#065f46}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d}
.actions{display:flex;justify-content:space-between;gap:12px;margin-top:10px}
.hint{font-size:12px;color:#475569;margin-top:6px}
.hint.bad{color:#b91c1c}
</style>
</head>
<body>
<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php">สมาชิก</a>
      <a href="manage_photographers.php" class="active">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
      <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
      <a href="admin_photographer_pages.php">หน้าเว็บ</a>
      <a href="login_history.php">ประวัติ Login</a>
      <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>
    <button class="burger" id="burgerBtn" aria-label="menu">☰</button>
  </div>
</div>

<div class="drawer" id="drawerMenu">
  <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
  <a href="admin_dashboard.php">หน้าแรก</a>
  <a href="manage_users.php">สมาชิก</a>
  <a href="manage_photographers.php" class="active">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <h2>เพิ่มช่างภาพใหม่</h2>
      <?php if($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <?php if($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <div class="grid">
          <div><label class="label">ชื่อ</label><input class="input" type="text" name="first_name" value="<?= h($first_name) ?>" required></div>
          <div><label class="label">นามสกุล</label><input class="input" type="text" name="last_name" value="<?= h($last_name) ?>" required></div>
          <div><label class="label">อีเมล</label><input class="input" type="email" name="email" value="<?= h($email) ?>" required></div>
          <div><label class="label">เบอร์โทร</label><input class="input" type="text" name="phone" value="<?= h($phone) ?>" required></div>
          <div><label class="label">อัตราค่าบริการ</label><input class="input" type="text" name="price_rate" value="<?= h($price_rate) ?>"></div>
          <div>
            <label class="label">หมวดความเชี่ยวชาญ</label>
            <select class="select" name="expertise_id">
              <option value="0">ไม่ระบุ</option>
              <?php foreach($expertise as $eid=>$ename): ?>
                <option value="<?= (int)$eid ?>" <?= $expertise_id===$eid?'selected':'' ?>><?= h($ename) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="margin-top:10px">
          <label class="label">ประเภทงาน</label>
          <div class="checks">
            <?php foreach($all_types as $t): ?>
              <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="types[]" value="<?= h($t) ?>" <?= in_array($t,$types_sel)?'checked':'' ?>><span class="badge"><?= h($t) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-top:10px">
          <label class="label">รูปโปรไฟล์</label>
          <input
            class="file" type="file" name="profile_image" id="profile_input"
            accept=".jpg,.jpeg,.png,.gif,.webp,image/*">
          <div class="hint">
            อนุญาต: jpg, jpeg, png, gif, webp • ขนาดไฟล์ ≤ 2MB • ขนาดภาพ <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> ถึง <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล
          </div>
          <div class="hint bad" id="pfError" style="display:none"></div>
          <div class="preview" id="previewWrap"><img id="previewImg" alt="preview"></div>
        </div>

        <div style="margin-top:10px">
          <label class="label">Portfolio (อัปโหลดได้หลายไฟล์)</label>
          <input
            class="file" type="file" name="portfolio_images[]"
            id="portfolio_input"
            accept=".jpg,.jpeg,.png,.gif,.webp" multiple>
          <div class="hint">
            อนุญาต: jpg, jpeg, png, gif, webp • ขนาดไฟล์ ≤ 2MB/ไฟล์ • ขนาดภาพ <?= MIN_IMG_W ?>×<?= MIN_IMG_H ?> ถึง <?= MAX_IMG_W ?>×<?= MAX_IMG_H ?> พิกเซล
          </div>
          <div class="hint bad" id="portError" style="display:none"></div>
        </div>

        <div class="actions">
          <a href="manage_photographers.php" class="btn btn-outline">ย้อนกลับ</a>
          <button class="btn btn-secondary" type="submit">บันทึกช่างภาพ</button>
        </div>
      </form>
    </section>
  </div>
</div>

<script>
/* เมนูมือถือ */
const burgerBtn=document.getElementById('burgerBtn');
const drawer=document.getElementById('drawerMenu');
let drawerOpen=false;
burgerBtn&&burgerBtn.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});

/* ตรวจไฟล์ฝั่งเบราว์เซอร์เบื้องต้น */
const MAX_BYTES = <?= (int)MAX_IMAGE_BYTES ?>;
const MIN_W = <?= (int)MIN_IMG_W ?>, MIN_H = <?= (int)MIN_IMG_H ?>;
const MAX_W = <?= (int)MAX_IMG_W ?>, MAX_H = <?= (int)MAX_IMG_H ?>;
const ALLOWED_EXT = <?= json_encode($ALLOWED_EXT, JSON_UNESCAPED_UNICODE) ?>;

/* รูปโปรไฟล์ */
const pfInput = document.getElementById('profile_input');
const pfErr   = document.getElementById('pfError');
const wrap    = document.getElementById('previewWrap');
const prevImg = document.getElementById('previewImg');

function showErr(el,msg){ el.textContent=msg; el.style.display='block'; }
function clearErr(el){ el.textContent=''; el.style.display='none'; }

pfInput?.addEventListener('change', e=>{
  clearErr(pfErr);
  const f = e.target.files && e.target.files[0];
  if(!f){ wrap.style.display='none'; prevImg.src=''; return; }

  if(f.size > MAX_BYTES){ showErr(pfErr, 'ไฟล์รูปต้องไม่เกิน 2MB'); pfInput.value=''; return; }
  const ext = (f.name.split('.').pop()||'').toLowerCase();
  if(!ALLOWED_EXT.includes(ext)){ showErr(pfErr, 'อนุญาตเฉพาะ: '+ALLOWED_EXT.join(', ')); pfInput.value=''; return; }

  const url = URL.createObjectURL(f);
  const img = new Image();
  img.onload = ()=>{
    if(img.naturalWidth<MIN_W || img.naturalHeight<MIN_H){
      showErr(pfErr, `ขนาดรูปเล็กเกินไป (อย่างน้อย ${MIN_W}×${MIN_H} พิกเซล)`); pfInput.value=''; URL.revokeObjectURL(url); return;
    }
    if(img.naturalWidth>MAX_W || img.naturalHeight>MAX_H){
      showErr(pfErr, `ขนาดรูปใหญ่เกินไป (ไม่เกิน ${MAX_W}×${MAX_H} พิกเซล)`); pfInput.value=''; URL.revokeObjectURL(url); return;
    }
    prevImg.src = url; wrap.style.display='block';
  };
  img.onerror = ()=>{ showErr(pfErr, 'ไม่สามารถอ่านไฟล์รูปได้'); pfInput.value=''; URL.revokeObjectURL(url); };
  img.src = url;
});

/* พอร์ตหลายไฟล์ */
const portInput = document.getElementById('portfolio_input');
const portErr   = document.getElementById('portError');

portInput?.addEventListener('change', e=>{
  clearErr(portErr);
  const files = Array.from(e.target.files||[]);
  if(!files.length) return;

  // ตรวจทุกไฟล์; ถ้าเจอผิด ให้ขึ้นรายการผิดแล้วล้าง input
  const bads = [];
  let pending = files.length, blocked = false;

  files.forEach((f,idx)=>{
    if(f.size > MAX_BYTES){ bads.push(`${f.name}: ไฟล์เกิน 2MB`); done(); return; }
    const ext = (f.name.split('.').pop()||'').toLowerCase();
    if(!ALLOWED_EXT.includes(ext)){ bads.push(`${f.name}: นามสกุลไฟล์ไม่ถูกต้อง`); done(); return; }

    const url = URL.createObjectURL(f);
    const img = new Image();
    img.onload = ()=>{
      if(img.naturalWidth<MIN_W || img.naturalHeight<MIN_H){
        bads.push(`${f.name}: เล็กเกินไป (${img.naturalWidth}×${img.naturalHeight})`);
      } else if(img.naturalWidth>MAX_W || img.naturalHeight>MAX_H){
        bads.push(`${f.name}: ใหญ่เกินไป (${img.naturalWidth}×${img.naturalHeight})`);
      }
      URL.revokeObjectURL(url);
      done();
    };
    img.onerror = ()=>{ bads.push(`${f.name}: เปิดดูรูปไม่ได้`); URL.revokeObjectURL(url); done(); };
    img.src = url;
  });

  function done(){
    pending--;
    if(pending===0){
      if(bads.length){
        showErr(portErr, 'ไฟล์ต่อไปนี้ไม่ผ่านเงื่อนไข:\n- '+bads.join('\n- '));
        portInput.value=''; // ล้างรายการเพื่อไม่ให้ส่งไฟล์ผิด
        blocked = true;
      }
    }
  }
});
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
