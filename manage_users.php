<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login_admin.php");
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $c, string $t): bool {
    $stmt = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
    $stmt->bind_param("s", $t);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}
function col_exists(mysqli $c, string $t, string $col): bool {
    $stmt = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $stmt->bind_param("ss", $t, $col);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$unread_user = 0; $unread_ph = 0;

if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages WHERE is_read=0")) {
    $row=$res->fetch_assoc(); $unread_user=(int)($row['c']??0); $res->close();
}
if ($res = $conn->query("SELECT COUNT(*) c FROM admin_messages_photographer WHERE is_read=0")) {
    $row=$res->fetch_assoc(); $unread_ph=(int)($row['c']??0); $res->close();
}
$unread_total = $unread_user + $unread_ph;

/* ---------- AJAX delete (with CSRF + transaction) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        echo "error:csrf";
        exit;
    }

    $user_id = (int)$_POST['ajax_delete'];

    try {
        $conn->begin_transaction();

        // เก็บ path รูปเพื่อไปลบไฟล์หลัง commit
        $profile_path = '';
        $stmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id=?");
        $stmt->bind_param("i",$user_id);
        $stmt->execute();
        $rs = $stmt->get_result();
        if ($row = $rs->fetch_assoc()) {
            $profile_path = trim($row['profile_image'] ?? '');
        }
        $stmt->close();

        // ลบประวัติ login ของสมาชิก (ถ้ามีคอลัมน์ user_id)
        if (table_exists($conn,'login_history') && col_exists($conn,'login_history','user_id')) {
            $stmt = $conn->prepare("DELETE FROM login_history WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }

        // ลบกล่องข้อความถึงแอดมินของสมาชิก (ลบ reply ก่อน)
        if (table_exists($conn,'admin_messages') && col_exists($conn,'admin_messages','user_id')) {
            // ดึง id ข้อความ
            $msg_ids = [];
            $stmt = $conn->prepare("SELECT id FROM admin_messages WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($m = $res->fetch_assoc()) { $msg_ids[] = (int)$m['id']; }
            $stmt->close();

            if (table_exists($conn,'admin_messages_reply') && col_exists($conn,'admin_messages_reply','message_id') && $msg_ids) {
                $stmt = $conn->prepare("DELETE FROM admin_messages_reply WHERE message_id=?");
                foreach ($msg_ids as $mid) {
                    $stmt->bind_param("i",$mid);
                    $stmt->execute();
                }
                $stmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM admin_messages WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }

        // ลบรีวิวที่สมาชิกคนนี้เคยเขียน (ถ้ามีคอลัมน์ user_id)
        if (table_exists($conn,'photographerrating') && col_exists($conn,'photographerrating','user_id')) {
            $stmt = $conn->prepare("DELETE FROM photographerrating WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }

        // ลบแชทที่สมาชิกคนนี้เคยคุย (ถ้ามีคอลัมน์ user_id)
        if (table_exists($conn,'chat_messages') && col_exists($conn,'chat_messages','user_id')) {
            $stmt = $conn->prepare("DELETE FROM chat_messages WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }

        // ลบการจอง/งานของสมาชิก (รองรับทั้ง booking และ job ถ้ามี)
        if (table_exists($conn,'booking') && col_exists($conn,'booking','user_id')) {
            $stmt = $conn->prepare("DELETE FROM booking WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }
        if (table_exists($conn,'job') && col_exists($conn,'job','user_id')) {
            $stmt = $conn->prepare("DELETE FROM job WHERE user_id=?");
            $stmt->bind_param("i",$user_id);
            $stmt->execute(); $stmt->close();
        }

        // ลบสมาชิก
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param("i",$user_id);
        $stmt->execute(); $stmt->close();

        $conn->commit();

        // ลบไฟล์รูปโปรไฟล์หลัง commit (เฉพาะที่อยู่ใน uploads/)
        if ($profile_path !== '' && strpos($profile_path, 'uploads/') === 0) {
            $abs = __DIR__ . '/' . $profile_path;
            if (is_file($abs)) { @unlink($abs); }
        }

        echo "success";
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo "error";
    }
    exit;
}

/* ---------- ดึงรายการสมาชิก ---------- */
$sql = "SELECT user_id, first_name, last_name, email, phone, profile_image FROM users ORDER BY user_id DESC";
$result = $conn->query($sql);
$rows = [];
if ($result && $result->num_rows > 0) {
    while($u=$result->fetch_assoc()){ $rows[]=$u; }
    $result->close();
}
$total_users = count($rows);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>จัดการสมาชิก - Cameraman Admin</title>
<meta name="csrf-token" content="<?= h($csrf_token) ?>">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:'Prompt',sans-serif;
  color:#0f172a;
  background:linear-gradient(135deg,#ffe9d6 0%,#ffccb3 50%,#ffb29b 100%);
  display:flex;flex-direction:column
}
.navbar{
  width:100%;
  position:fixed;top:0;left:0;
  background:linear-gradient(90deg,#0b1220,#111827);
  display:flex;align-items:center;
  padding:14px 0;
  box-shadow:0 4px 20px rgba(0,0,0,.25);
  z-index:100
}
.nav-inner{width:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px}
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
.burger{display:none}
@media (max-width:900px){
  .menu{display:none}
  .burger{display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.12)}
  .drawer{position:fixed;top:60px;right:12px;left:12px;background:#ffffff;border-radius:16px;padding:12px;display:none;flex-direction:column;gap:8px;z-index:120;box-shadow:0 10px 30px rgba(0,0,0,.2)}
  .drawer a,.drawer .badge{color:#0b1220;background:#eef2ff}
  .drawer a.active{background:#60a5fa;color:#fff}
}
.wrapper{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:120px 16px 48px}
.container{width:100%;max-width:1200px;display:flex;flex-direction:column;gap:16px}
.card{width:100%;background:#ffffff;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:18px;border:1px solid #e5e7eb}
.card h2{margin:0 0 10px;color:#0b1220;font-weight:900}
.header-bar{display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap}
.search{display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:8px 10px}
.search input{border:0;outline:0;background:transparent;min-width:240px;font-size:14px}
.select{border:1px solid #e5e7eb;border-radius:12px;padding:8px 10px;background:#fff}
.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.12)}
.btn-secondary{background:linear-gradient(90deg,#6366f1,#4f46e5)}
.btn-danger{background:linear-gradient(90deg,#ef4444,#dc2626)}
.btn-sm{padding:6px 10px;border-radius:10px;font-weight:800}
.table-wrap{overflow:auto;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
table{width:100%;border-collapse:collapse;min-width:860px;background:#fff}
thead th{background:#eef2ff;color:#0b1220;text-align:left;padding:12px;font-weight:900;position:sticky;top:0;z-index:1}
tbody td{padding:12px;border-top:1px solid #eee;vertical-align:middle}
tbody tr:hover{background:#faf5ff}
.profile{width:50px;height:50px;border-radius:50%;object-fit:cover;box-shadow:0 4px 10px rgba(0,0,0,.12)}
.pager{display:flex;gap:6px;justify-content:flex-end;align-items:center;margin-top:10px;flex-wrap:wrap}
.pager button,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer}
.pager .active{background:linear-gradient(90deg,#6a11cb,#2575fc);color:#fff;border-color:transparent}
.toast{position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:12px 14px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.2);opacity:0;transform:translateY(10px);pointer-events:none;transition:.25s}
.toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
</style>
</head>
<body>

<div class="navbar">
  <div class="nav-inner">
    <div class="logo">📸 <span>Cameraman Admin</span></div>
    <div class="menu" id="topMenu">
      <span class="badge">👋 สวัสดี, <?= h($admin_name) ?></span>
      <a href="admin_dashboard.php">หน้าแรก</a>
      <a href="manage_users.php" class="active">สมาชิก</a>
      <a href="manage_photographers.php">ช่างภาพ</a>
      <a href="manage_bookings.php">การจอง</a>
      <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
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
  <a href="manage_users.php" class="active">สมาชิก</a>
  <a href="manage_photographers.php">ช่างภาพ</a>
  <a href="manage_bookings.php">การจอง</a>
  <span class="badge">ยังไม่อ่าน: <?= (int)$unread_total ?></span>
  <a href="admin_inbox_photographer.php">ข้อความช่างภาพ</a>
  <a href="admin_inbox_user.php">ข้อความลูกค้า</a>
  <a href="admin_photographer_pages.php">หน้าเว็บ</a>
  <a href="login_history.php">ประวัติ Login</a>
  <a href="logout.php" class="logout">ออกจากระบบ</a>
</div>

<div class="wrapper">
  <div class="container">
    <section class="card">
      <div class="header-bar">
        <h2>รายการสมาชิก <span style="font-size:14px;color:#6b7280;">(ทั้งหมด <?= (int)$total_users ?>)</span></h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div class="search">🔍 <input id="searchInput" type="text" placeholder="ค้นหา: ชื่อ / อีเมล / เบอร์โทร"></div>
          <select id="perPage" class="select">
            <option value="10">แสดง 10</option>
            <option value="20" selected>แสดง 20</option>
            <option value="50">แสดง 50</option>
            <option value="100">แสดง 100</option>
          </select>
          <a class="btn" href="users_register.php">+ เพิ่มสมาชิก</a>
        </div>
      </div>

      <div class="table-wrap" style="margin-top:12px;">
        <table id="userTable">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th>ชื่อ</th>
              <th>อีเมล</th>
              <th>เบอร์โทร</th>
              <th style="width:90px">โปรไฟล์</th>
              <th style="width:170px">การจัดการ</th>
            </tr>
          </thead>
          <tbody id="userTbody">
            <?php if ($total_users > 0): ?>
              <?php foreach($rows as $u):
                $uid = (int)$u['user_id'];
                $firstname = trim($u['first_name'] ?? '');
                $lastname  = trim($u['last_name'] ?? '');
                $fullname = trim($firstname.' '.$lastname);
                $email = $u['email'] ?? '';
                $phone = $u['phone'] ?? '';
                $img = trim($u['profile_image'] ?? '');
                if ($img === '') $img = 'images/default_user.png';
              ?>
              <tr id="row-<?= $uid ?>">
                <td data-col="id"><?= $uid ?></td>
                <td data-col="name"><?= h($fullname !== '' ? $fullname : '-') ?></td>
                <td data-col="email"><?= h($email !== '' ? $email : '-') ?></td>
                <td data-col="phone"><?= h($phone !== '' ? $phone : '-') ?></td>
                <td><img src="<?= h($img) ?>" class="profile" alt="profile" onerror="this.src='images/default_user.png'"></td>
                <td>
                  <a class="btn btn-secondary btn-sm" href="edit_user.php?id=<?= $uid ?>">✏️ แก้ไข</a>
                  <button class="btn btn-danger btn-sm" onclick="deleteUser(<?= $uid ?>)">🗑️ ลบ</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6" style="text-align:center;color:#9ca3af;">ยังไม่มีข้อมูลสมาชิก</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pager" id="pager"></div>
    </section>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const burgerBtn=document.getElementById('burgerBtn');const drawer=document.getElementById('drawerMenu');let drawerOpen=false;burgerBtn?.addEventListener('click',()=>{drawerOpen=!drawerOpen;drawer.style.display=drawerOpen?'flex':'none';});
function showToast(msg,ok=true){const t=document.getElementById('toast');if(!t)return;t.textContent=msg;t.style.background=ok?'#065f46':'#7f1d1d';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);}

const searchInput=document.getElementById('searchInput');
const perPageSel=document.getElementById('perPage');
const tbody=document.getElementById('userTbody');
const pager=document.getElementById('pager');
let master=Array.from(tbody?.querySelectorAll('tr')||[]);
let filtered=master.slice();
let page=1;

function applyFilter(){
  const q=(searchInput.value||'').toLowerCase();
  filtered=master.filter(tr=>{
    const name=(tr.querySelector('[data-col="name"]')?.textContent||'').toLowerCase();
    const email=(tr.querySelector('[data-col="email"]')?.textContent||'').toLowerCase();
    const phone=(tr.querySelector('[data-col="phone"]')?.textContent||'').toLowerCase();
    const id=(tr.querySelector('[data-col="id"]')?.textContent||'').toLowerCase();
    return name.includes(q)||email.includes(q)||phone.includes(q)||id.includes(q);
  });
  page=1;render();
}
function render(){
  const per=parseInt(perPageSel.value||'20',10);
  const total=filtered.length;
  const pages=Math.max(1,Math.ceil(total/per));
  if(page>pages)page=pages;
  tbody.innerHTML='';
  const start=(page-1)*per;const end=Math.min(start+per,total);
  for(let i=start;i<end;i++){tbody.appendChild(filtered[i]);}
  pager.innerHTML='';
  const makeBtn=(label,p,active=false,disabled=false)=>{
    const el=document.createElement(disabled?'span':'button');
    el.textContent=label;
    if(active)el.classList.add('active');
    if(!disabled)el.onclick=()=>{page=p;render();};
    pager.appendChild(el);
  };
  makeBtn('«',1,false,page===1);
  makeBtn('‹',Math.max(1,page-1),false,page===1);
  for(let p=1;p<=pages;p++){
    if(p===1||p===pages||Math.abs(p-page)<=2){makeBtn(String(p),p,p===page);}
    else if(Math.abs(p-page)===3){const span=document.createElement('span');span.textContent='…';pager.appendChild(span);}
  }
  makeBtn('›',Math.min(pages,page+1),false,page===pages);
  makeBtn('»',pages,false,page===pages);
}
searchInput?.addEventListener('input',applyFilter);
perPageSel?.addEventListener('change',()=>{page=1;render();});
applyFilter();

function deleteUser(userId){
  if(!confirm("คุณแน่ใจหรือไม่ที่จะลบสมาชิกคนนี้?"))return;
  const xhr=new XMLHttpRequest();
  xhr.open("POST","manage_users.php",true);
  xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
  xhr.setRequestHeader("X-Requested-With","XMLHttpRequest");
  const token=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
  xhr.onload=function(){
    if(xhr.status===200 && xhr.responseText.trim()==="success"){
      const tr=document.getElementById("row-"+userId);
      if(tr){
        master=master.filter(r=>r!==tr);
        filtered=filtered.filter(r=>r!==tr);
        render();
      }
      showToast("✅ ลบสมาชิกเรียบร้อยแล้ว",true);
    }else if(xhr.responseText.trim()==="error:csrf"){
      showToast("❌ เซสชันหมดอายุ กรุณารีเฟรชหน้า",false);
    }else{
      showToast("❌ เกิดข้อผิดพลาดในการลบสมาชิก",false);
    }
  };
  xhr.onerror=function(){showToast("❌ ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้",false);};
  xhr.send("ajax_delete="+encodeURIComponent(userId)+"&csrf_token="+encodeURIComponent(token));
}
</script>
</body>
</html>
<?php
mysqli_close($conn);
