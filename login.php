<?php
// login.php
session_start();

/*
  Requires connect.php in same folder that creates a mysqli $conn:
    $conn = new mysqli('127.0.0.1','dbuser','dbpass','dbname');
*/
include __DIR__ . '/connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection missing. Make sure connect.php defines \$conn as a mysqli instance.");
}

// create table if not exists
$create = "CREATE TABLE IF NOT EXISTS `user` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($create)) {
    die("Failed to create/confirm user table: " . $conn->error);
}

// If logged in already -> admin
if (isset($_SESSION['user_id'])) {
    header('Location: admin.php');
    exit;
}

// Helper: send JSON and exit
function send_json($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// Detect AJAX JSON login call: either ?action=login (fetch) or JSON content-type POST
$isAjaxLogin = (isset($_GET['action']) && $_GET['action'] === 'login') ||
               (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false && $_SERVER['REQUEST_METHOD'] === 'POST');

// Demo user creation endpoint (POST JSON, local only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'create_demo') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/localhost|127\.0\.0\.1|::1/', $host)) {
        send_json(['success' => false, 'message' => 'Demo creation allowed only on local/dev.']);
    }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $u = isset($data['username']) ? trim($data['username']) : 'demo';
    $p = isset($data['password']) ? $data['password'] : 'Demo@1234';
    if ($u === '' || strlen($p) < 6) send_json(['success'=>false,'message'=>'Invalid demo username/password.']);
    // check existing
    $stmt = $conn->prepare("SELECT id FROM `user` WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $u);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) send_json(['success'=>false,'message'=>'User already exists.']);
    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO `user` (username,password) VALUES (?,?)");
    $stmt->bind_param('ss', $u, $hash);
    if ($stmt->execute()) send_json(['success'=>true,'message'=>"Demo user created: $u / $p"]);
    else send_json(['success'=>false,'message'=>'Insert failed: '.$stmt->error]);
}

// Function: attempt login with username & password. Handles migration from plaintext to hashed on success.
// Returns array: ['ok'=>bool, 'message'=>string, 'user'=>array|null]
function attempt_login(mysqli $conn, $username, $password) {
    // fetch user
    $stmt = $conn->prepare("SELECT id, username, password FROM `user` WHERE username = ? LIMIT 1");
    if (!$stmt) return ['ok'=>false,'message'=>'DB prepare failed: '.$conn->error];
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return ['ok'=>false,'message'=>'Invalid username or password.'];

    $stored = $user['password'];

    // If stored value looks like a modern hash, use password_verify
    $looks_hashed = (strpos($stored, '$2y$') === 0 || strpos($stored, '$2b$') === 0 ||
                     strpos($stored, '$argon2') === 0 || strpos($stored, '$argon2i') === 0 ||
                     strpos($stored, '$argon2id') === 0);

    if ($looks_hashed) {
        if (password_verify($password, $stored)) {
            return ['ok'=>true,'message'=>'Login successful','user'=>$user];
        } else {
            return ['ok'=>false,'message'=>'Invalid username or password.'];
        }
    } else {
        // stored value likely plaintext (legacy). If it matches, migrate to hashed password.
        if ($password === $stored) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE `user` SET password = ? WHERE id = ?");
            if ($up) {
                $up->bind_param('si', $newHash, $user['id']);
                $up->execute();
                $up->close();
                // proceed as logged in
                return ['ok'=>true,'message'=>'Login successful (password migrated)','user'=>$user];
            } else {
                // migration failed but allow login? we'll still allow and warn
                return ['ok'=>true,'message'=>'Login successful (migration failed: '.$conn->error.')','user'=>$user];
            }
        } else {
            return ['ok'=>false,'message'=>'Invalid username or password.'];
        }
    }
}

// Handle AJAX login
if ($isAjaxLogin) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // fallback: if not JSON, try POST fields (for some clients)
    if (empty($data) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = $_POST;
    }
    $username = isset($data['username']) ? trim($data['username']) : '';
    $password = isset($data['password']) ? $data['password'] : '';
    if ($username === '' || $password === '') send_json(['success'=>false,'message'=>'Username and password required.']);
    $res = attempt_login($conn, $username, $password);
    if ($res['ok']) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$res['user']['id'];
        $_SESSION['username'] = $res['user']['username'];
        send_json(['success'=>true,'message'=>$res['message']]);
    } else {
        send_json(['success'=>false,'message'=>$res['message']]);
    }
    // exit in send_json
}

// Handle normal POST (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $errors = [];
    if ($username === '' || $password === '') {
        $errors[] = 'Please enter username and password.';
    } else {
        $res = attempt_login($conn, $username, $password);
        if ($res['ok']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$res['user']['id'];
            $_SESSION['username'] = $res['user']['username'];
            header('Location: admin.php');
            exit;
        } else {
            $errors[] = $res['message'];
        }
    }
}

// Render HTML login page (JS-enabled AJAX form)
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — Company</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#f3f6fb;--card:#fff;--accent:#0d6efd;--muted:#6c757d;--glass:rgba(13,110,253,0.06)}
*{box-sizing:border-box;font-family:Inter,system-ui,Segoe UI,Roboto,Arial}
body{margin:0;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px}
.header{position:fixed;left:18px;top:12px;display:flex;align-items:center;gap:10px}
.logo{width:40px;height:40px;border-radius:8px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.brand{font-weight:700;color:var(--accent)}
.container{width:100%;max-width:980px}
.layout{display:grid;grid-template-columns:1fr 420px;gap:28px;align-items:center}
@media (max-width:900px){ .layout{grid-template-columns:1fr} .header{position:static;margin-bottom:12px} }
.hero{padding:32px;border-radius:12px;background:linear-gradient(180deg,#fff,#f8fbff);box-shadow:0 8px 30px rgba(11,22,40,0.05)}
.hero h1{margin:0 0 8px;font-size:28px}
.hero p{margin:0;color:var(--muted)}
.card{background:var(--card);padding:22px;border-radius:12px;box-shadow:0 10px 30px rgba(11,22,40,0.06)}
.form-row{margin-bottom:12px}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
input[type="text"], input[type="password"]{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #e6eef9;outline:none}
input:focus{box-shadow:0 6px 18px rgba(13,110,253,0.08);border-color:rgba(13,110,253,0.3)}
.row{display:flex;gap:8px;align-items:center}
.pw-toggle{margin-left:auto;font-size:13px;color:var(--muted);cursor:pointer;user-select:none}
.btn{background:linear-gradient(90deg,var(--accent),#6f42c1);border:0;padding:10px 14px;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.btn[disabled]{opacity:0.65;cursor:not-allowed}
.small{font-size:13px;color:var(--muted)}
.msg{padding:10px;border-radius:8px;margin-bottom:12px}
.msg.error{background:#fff0f0;color:#8b0000}
.msg.success{background:#e9f9ef;color:#0b6f3a}
.footer{margin-top:12px;font-size:13px;color:var(--muted);text-align:center}
.toast{position:fixed;right:18px;bottom:18px;background:#071326;padding:12px 14px;border-radius:10px;color:#fff;display:none}
</style>
</head>
<body>

<header class="header" aria-label="Company">
  <div class="logo">C</div>
  <div class="brand">Company</div>
</header>

<main class="container">
  <div class="layout">
    <section class="hero" aria-labelledby="h1">
      <h1 id="h1">Welcome back</h1>
      <p>Sign in to manage products — add, edit and remove items from your store.</p>
      <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap">
        <div style="padding:10px;border-radius:8px;background:var(--glass);min-width:160px">
          <div style="font-weight:600">Quick</div><div class="small">Fast admin workflows</div>
        </div>
        <div style="padding:10px;border-radius:8px;background:var(--glass);min-width:160px">
          <div style="font-weight:600">Secure</div><div class="small">Passwords hashed & migrated</div>
        </div>
      </div>
    </section>

    <aside class="card" aria-label="Login form">
      <div id="serverMsg">
        <?php if (!empty($errors) && isset($errors)): ?>
          <div class="msg error" role="alert"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
        <?php endif; ?>
      </div>

      <form id="loginForm" method="post" action="">
        <div class="form-row">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" autocomplete="username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
        </div>

        <div class="form-row">
          <label for="password">Password</label>
          <div class="row">
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <div id="togglePw" class="pw-toggle" role="button" tabindex="0" aria-pressed="false">Show</div>
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
          <label class="small"><input type="checkbox" id="remember" name="remember"> Remember</label>
          <button class="btn" id="submitBtn" type="button"><span id="btnText">Sign in</span><span id="spinner" style="display:none">⏳</span></button>
        </div>

        <div style="margin-top:10px" id="clientMsg" aria-live="polite"></div>

        <div class="footer">
          <p class="small">Forgot password? Contact admin.</p>
        </div>
      </form>

      <div style="margin-top:12px">
        <button id="createDemo" class="small" style="background:transparent;border:0;color:var(--muted);cursor:pointer">Create demo user (local only)</button>
      </div>
    </aside>
  </div>
</main>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
(function(){
  const username = document.getElementById('username');
  const password = document.getElementById('password');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = document.getElementById('btnText');
  const spinner = document.getElementById('spinner');
  const togglePw = document.getElementById('togglePw');
  const clientMsg = document.getElementById('clientMsg');
  const serverMsg = document.getElementById('serverMsg');
  const createDemo = document.getElementById('createDemo');
  const toast = document.getElementById('toast');

  function showToast(msg, ok=true) {
    toast.textContent = msg;
    toast.style.display = 'block';
    toast.style.background = ok ? '#063' : '#600';
    setTimeout(()=> toast.style.display = 'none', 3500);
  }

  function setLoading(on) {
    submitBtn.disabled = on;
    spinner.style.display = on ? 'inline' : 'none';
    btnText.style.opacity = on ? '0.4' : '1';
  }

  // validate basic
  function validate() {
    const u = username.value.trim();
    const p = password.value;
    submitBtn.disabled = !(u.length >= 2 && p.length >= 1);
  }
  username.addEventListener('input', validate);
  password.addEventListener('input', validate);

  // toggle pw
  togglePw.addEventListener('click', () => {
    const isPw = password.type === 'password';
    password.type = isPw ? 'text' : 'password';
    togglePw.textContent = isPw ? 'Hide' : 'Show';
    togglePw.setAttribute('aria-pressed', String(isPw));
  });
  togglePw.addEventListener('keydown', (e)=> { if (e.key === 'Enter' || e.key === ' ') togglePw.click(); });

  // AJAX submit function
  async function doLogin() {
    clientMsg.textContent = '';
    setLoading(true);
    try {
      const resp = await fetch('?action=login', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({ username: username.value.trim(), password: password.value })
      });
      const data = await resp.json();
      if (data.success) {
        showToast(data.message || 'Signed in', true);
        // redirect to admin
        setTimeout(()=> location.href = 'admin.php', 600);
      } else {
        clientMsg.innerHTML = '<div class="msg error">' + (data.message || 'Sign in failed') + '</div>';
        showToast(data.message || 'Sign in failed', false);
        setLoading(false);
      }
    } catch(err) {
      console.error(err);
      clientMsg.innerHTML = '<div class="msg error">Network/server error.</div>';
      showToast('Network error', false);
      setLoading(false);
    }
  }

  submitBtn.addEventListener('click', doLogin);

  // allow Enter in inputs
  document.getElementById('loginForm').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (!submitBtn.disabled) doLogin();
    }
  });

  // create demo user (local only)
  createDemo.addEventListener('click', async (e) => {
    e.preventDefault();
    const u = prompt('Demo username?', 'demo') || 'demo';
    const p = prompt('Demo password?', 'Demo@1234') || 'Demo@1234';
    if (!confirm('Create demo user in local DB?')) return;
    try {
      const r = await fetch('?action=create_demo', {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({ username: u, password: p })
      });
      const d = await r.json();
      if (d.success) {
        showToast(d.message || 'Demo created', true);
      } else {
        showToast(d.message || 'Could not create demo', false);
      }
    } catch(err) {
      console.error(err);
      showToast('Network error', false);
    }
  });

  // initial validate
  validate();
})();
</script>
</body>
</html>
