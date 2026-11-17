<?php
/**
 * setup.php — run once, then delete.
 *
 * Creates the three tables and stores the admin password hash. Refuses to do
 * anything once a password exists, so leaving it uploaded is not a way back in.
 */

declare(strict_types=1);

require __DIR__ . '/bridge_lib.php';

$secure = sb_is_https();
session_set_cookie_params(array(
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Lax',
));
session_name('sitebridge_setup');
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$messages = array();
$fatal = '';
$done = false;
$link = null;

if (!sb_config_ready()) {
    $fatal = 'bridge.secrets.php is missing or has no db section.';
} else {
    try {
        $link = sb_db();
    } catch (Throwable $exc) {
        $fatal = $exc->getMessage();
    }
}

$installed = false;
if ($link !== null) {
    try {
        $installed = sb_installed($link);
    } catch (Throwable $exc) {
        $installed = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link !== null && !$installed) {
    $sent = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!hash_equals((string) $_SESSION['csrf'], $sent)) {
        $messages[] = array('error', 'The form token expired. Reload and try again.');
    } else {
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $again = isset($_POST['again']) ? (string) $_POST['again'] : '';
        if (strlen($password) < 10) {
            $messages[] = array('error', 'Use at least 10 characters for the admin password.');
        } elseif ($password !== $again) {
            $messages[] = array('error', 'The two passwords do not match.');
        } else {
            try {
                sb_install_schema($link);
                sb_setting_put($link, 'admin_hash', password_hash($password, PASSWORD_DEFAULT));
                sb_setting_put($link, 'installed_at', sb_now());
                sb_setting_put($link, 'instance_name', sb_clip((string) ($_SERVER['HTTP_HOST'] ?? 'site'), 64));
                sb_log($link, null, 'panel', 'installed site_bridge ' . SB_VERSION, 'ok', 0, 0);
                $guard = __DIR__ . '/.htaccess';
                $rule = "\n<Files \"bridge.secrets.php\">\n"
                    . "  Require all denied\n"
                    . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
                    . "</Files>\n";
                $existing = is_file($guard) ? (string) @file_get_contents($guard) : '';
                if (strpos($existing, 'bridge.secrets.php') === false) {
                    @file_put_contents($guard, $existing . $rule);
                }
                $done = true;
                $installed = true;
            } catch (Throwable $exc) {
                $messages[] = array('error', $exc->getMessage());
            }
        }
    }
}

$secretsPath = sb_secrets_path();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>site_bridge — setup</title>
<style>
:root {
  --bg: #0a0f1c; --panel: #111a2e; --panel-2: #16223a; --line: #23304d;
  --text: #e6edf7; --muted: #8ea0bd; --accent: #5eead4; --err: #f87171; --ok: #34d399;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
* { box-sizing: border-box; }
body {
  margin: 0; background: radial-gradient(1200px 600px at 15% -10%, #16233d 0%, var(--bg) 55%);
  color: var(--text); font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  min-height: 100vh; padding: 40px 20px;
}
.wrap { max-width: 680px; margin: 0 auto; }
.card {
  background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
  border: 1px solid var(--line); border-radius: 16px; padding: 22px; margin-bottom: 18px;
}
h1 { font-size: 20px; margin: 0 0 6px; }
h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); margin: 22px 0 10px; }
p { margin: 8px 0; }
a { color: var(--accent); }
code, pre { font-family: var(--mono); font-size: 12.5px; }
pre { background: #0a1220; border: 1px solid var(--line); border-radius: 10px; padding: 12px; overflow: auto; }
label { display: block; font-size: 12px; color: var(--muted); margin: 12px 0 5px; }
input[type=password] {
  width: 100%; padding: 11px 12px; border-radius: 10px; background: #0c1526;
  border: 1px solid var(--line); color: var(--text); font: inherit;
}
button {
  font: inherit; font-weight: 600; padding: 11px 18px; border-radius: 10px; border: 0;
  background: var(--accent); color: #062016; cursor: pointer; margin-top: 16px;
}
.flash { border-radius: 12px; padding: 11px 14px; margin: 10px 0; border: 1px solid; font-size: 14px; }
.flash.error { background: rgba(248,113,113,.1); border-color: rgba(248,113,113,.4); color: #fecaca; }
.flash.ok { background: rgba(52,211,153,.1); border-color: rgba(52,211,153,.4); color: #bbf7d0; }
.muted { color: var(--muted); font-size: 13px; }
.step { display: flex; gap: 12px; margin: 14px 0; }
.num {
  flex: 0 0 26px; height: 26px; border-radius: 50%; background: rgba(94,234,212,.12);
  color: var(--accent); border: 1px solid rgba(94,234,212,.35); display: flex;
  align-items: center; justify-content: center; font-size: 13px; font-weight: 650;
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>site_bridge — setup</h1>
    <p class="muted">One-time installation: tables, then the admin password.
    Delete this file when it says it is done.</p>

    <?php foreach ($messages as $item): ?>
      <div class="flash <?= sb_escape($item[0]) ?>"><?= sb_escape($item[1]) ?></div>
    <?php endforeach; ?>

    <?php if ($done): ?>
      <div class="flash ok">Installed. The panel is ready.</div>
      <p><a href="index.php">Open the panel →</a></p>
      <h2>Now delete setup.php</h2>
      <p>The tables and password are stored; this file can only install again if the
      password were removed, and leaving it online is an unnecessary door.</p>
    <?php elseif ($installed): ?>
      <div class="flash ok">Already installed.</div>
      <p><a href="index.php">Open the panel →</a> Sign in with the password you chose.</p>
      <h2>Delete setup.php</h2>
      <p>You are seeing this because the file is still on the server. Remove it.</p>
    <?php elseif ($fatal !== ''): ?>
      <div class="flash error"><?= sb_escape($fatal) ?></div>
      <h2>Create the credentials file</h2>
      <p>In VistaPanel (or your host's control panel) open <b>MySQL Databases</b> and
      note the host, database name and user. Then create this file — ideally one
      level <b>above</b> your web root, so it cannot be downloaded:</p>
      <pre>// <?= sb_escape($secretsPath) ?>

&lt;?php
return [
    'db' =&gt; [
        'host' =&gt; 'sqlXXX.infinityfree.com',   // from the control panel
        'user' =&gt; 'if0_XXXXXXXX',
        'pass' =&gt; 'your database password',
        'name' =&gt; 'if0_XXXXXXXX_bridge',
        'port' =&gt; 3306,
    ],
    'root' =&gt; __DIR__ . '/htdocs',   // the folder the agent may touch
    'allow_php' =&gt; false,            // set true to allow op=php
    'databases' =&gt; [                // optional: extra DBs for op=sql
        'main' =&gt; [
            'host' =&gt; 'sqlXXX.infinityfree.com',
            'user' =&gt; 'if0_XXXXXXXX',
            'pass' =&gt; 'your database password',
            'name' =&gt; 'if0_XXXXXXXX_bridge',
        ],
    ],
];</pre>
      <p class="muted">Then reload this page. <code>bridge.secrets.example.php</code>
      in the repository is the same file with comments.</p>
    <?php else: ?>
      <h2>Choose the admin password</h2>
      <p class="muted">Stored as a bcrypt hash in <code>sb_settings</code>; it is the
      only way into the panel. Nothing else is needed — the tables are created below.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= sb_escape((string) $_SESSION['csrf']) ?>">
        <label for="password">Password (10+ characters)</label>
        <input type="password" id="password" name="password" required minlength="10" autofocus>
        <label for="again">Repeat it</label>
        <input type="password" id="again" name="again" required minlength="10">
        <button type="submit">Create tables and set password</button>
      </form>

      <h2>Before you continue</h2>
      <div class="step"><div class="num">1</div><div>
        <b>Credentials</b><br><span class="muted">using <code><?= sb_escape($secretsPath) ?></code></span>
      </div></div>
      <div class="step"><div class="num">2</div><div>
        <b>PHP snippets</b><br><span class="muted">currently
        <?= !empty(sb_setting('allow_php', false)) ? 'enabled' : 'disabled' ?>
        (allow_php in bridge.secrets.php)</span>
      </div></div>
      <div class="step"><div class="num">3</div><div>
        <b>The AI gets a token, not this password</b><br>
        <span class="muted">Tokens are issued in the panel, expire on their own, and
        can be revoked without touching anything else.</span>
      </div></div>
    <?php endif; ?>
  </div>
  <p class="muted" style="text-align:center">site_bridge v<?= sb_escape(SB_VERSION) ?> ·
    PHP <?= sb_escape(PHP_VERSION) ?></p>
</div>
</body>
</html>
