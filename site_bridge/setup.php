<?php
/**
 * setup.php — run this once.
 *
 * It is the only page that can write the administrator password, and it stops
 * working the moment a password exists. Upload it next to bridge.php and
 * index.php, open it in a browser, type a password, then delete it.
 */

declare(strict_types=1);

const SETUP_VERSION = '1';

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'httponly' => true,
                                'secure' => $secure, 'samesite' => 'Lax'));
session_name('sitebridge');
session_start();

function setup_state_path(): string
{
    $outside = dirname(__DIR__) . '/bridge_state.json';
    $inside = __DIR__ . '/bridge_state.json';
    foreach (array($outside, $inside) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    $parent = dirname(__DIR__);
    if (is_dir($parent) && is_writable($parent) && $parent !== __DIR__) {
        return $outside;
    }
    return $inside;
}

function setup_state_load(): array
{
    $empty = array('password_hash' => '', 'password_plain' => '',
                   'settings' => array(), 'tokens' => array(), 'activity' => array());
    $raw = @file_get_contents(setup_state_path());
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        return $empty;
    }
    $data += $empty;
    foreach (array('settings', 'tokens', 'activity') as $key) {
        if (!is_array($data[$key])) {
            $data[$key] = array();
        }
    }
    return $data;
}

function setup_state_save(array $state): bool
{
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($encoded === false) {
        return false;
    }
    $path = setup_state_path();
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }
    $ok = false;
    if (flock($handle, LOCK_EX)) {
        ftruncate($handle, 0);
        rewind($handle);
        $ok = fwrite($handle, $encoded) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    @chmod($path, 0600);
    return $ok;
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * If the state file ends up inside the web root, drop a deny-all rule beside it
 * so nobody can fetch the password hash and the tokens over HTTP.
 */
function setup_guard_state_file(string $path): string
{
    if (dirname($path) !== __DIR__) {
        return 'the state file sits above the web root — already unreachable over HTTP';
    }
    $htaccess = __DIR__ . '/.htaccess';
    $rule = "# site_bridge: keep state and job files private\n"
        . "<FilesMatch \"^(bridge_state|bridge_jobs|job-.*)\">\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n";
    $existing = is_file($htaccess) ? (string) @file_get_contents($htaccess) : '';
    if (strpos($existing, 'site_bridge: keep state') !== false) {
        return 'a deny rule for the state file is already in .htaccess';
    }
    $written = @file_put_contents($htaccess, rtrim($existing) . "\n\n" . $rule,
        FILE_APPEND | LOCK_EX) !== false;
    return $written
        ? 'wrote a deny rule for the state file into .htaccess'
        : 'could not write .htaccess — make sure bridge_state.json cannot be fetched';
}

$state = setup_state_load();
$installed = $state['password_hash'] !== '' || $state['password_plain'] !== '';
$problems = array();
$done = false;
$guardNote = '';

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $again = isset($_POST['again']) ? (string) $_POST['again'] : '';
    if (strlen($password) < 8) {
        $problems[] = 'Use at least 8 characters — this password is the whole lock on a public URL.';
    }
    if ($password !== $again) {
        $problems[] = 'The two passwords do not match.';
    }
    if (!$problems) {
        $state['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $state['password_plain'] = '';
        if (!isset($state['settings']) || !is_array($state['settings'])) {
            $state['settings'] = array();
        }
        if (!array_key_exists('allow_php', $state['settings'])) {
            $state['settings']['allow_php'] = false;
        }
        $lastId = 0;
        if ($state['activity']) {
            $tail = $state['activity'][count($state['activity']) - 1];
            $lastId = isset($tail['id']) ? (int) $tail['id'] : 0;
        }
        $state['activity'][] = array(
            'id' => $lastId + 1,
            'ts' => date('Y-m-d H:i:s'),
            'op' => 'panel',
            'summary' => 'administrator password set by setup.php',
            'status' => 'ok',
            'ms' => 0,
            'bytes' => 0,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'token' => '',
        );
        if (setup_state_save($state)) {
            $installed = true;
            $done = true;
            $guardNote = setup_guard_state_file(setup_state_path());
        } else {
            $problems[] = 'Could not write ' . setup_state_path()
                . '. Give that folder write permission (755) and reload.';
        }
    }
}

$statePath = setup_state_path();
$folder = dirname($statePath);
$folderWritable = is_writable($folder) || (is_file($statePath) && is_writable($statePath));
$sameFolder = $folder === __DIR__;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>site_bridge — first run</title>
<style>
:root { --bg:#0a0f1c; --panel:#111a2e; --line:#23304d; --text:#e6edf7; --muted:#8ea0bd;
  --accent:#5eead4; --ok:#34d399; --err:#f87171; --warn:#fbbf24;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
* { box-sizing: border-box; }
body { margin:0; min-height:100vh; color:var(--text);
  background: radial-gradient(1100px 560px at 20% -12%, #16233d 0%, var(--bg) 58%);
  font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.wrap { max-width: 560px; margin: 0 auto; padding: 9vh 20px 60px; }
.brand { display:flex; align-items:center; gap:10px; justify-content:center;
  font-weight:650; margin-bottom:20px; letter-spacing:.2px; }
.dot { width:9px; height:9px; border-radius:50%; background:var(--ok); }
.card { background: linear-gradient(180deg, #111a2e 0%, #16223a 100%); border:1px solid var(--line);
  border-radius:16px; padding:22px; box-shadow: 0 20px 44px -30px rgba(0,0,0,.9); }
h1 { font-size:20px; margin:0 0 6px; }
p.lead { color:var(--muted); margin:0 0 18px; font-size:14px; }
label { display:block; font-size:12px; color:var(--muted); margin:12px 0 5px; }
input[type=password] { width:100%; padding:11px 13px; border-radius:10px; background:#0c1526;
  border:1px solid var(--line); color:var(--text); font:inherit; }
input[type=password]:focus { outline:2px solid rgba(94,234,212,.35); border-color:var(--accent); }
button { font:inherit; font-weight:600; padding:11px 18px; border-radius:10px; border:0;
  background:var(--accent); color:#062016; cursor:pointer; margin-top:18px; }
button.ghost { background:transparent; color:var(--text); border:1px solid var(--line); }
.flash { border-radius:11px; padding:11px 13px; margin-bottom:12px; font-size:14px; border:1px solid; }
.flash.error { background:rgba(248,113,113,.1); border-color:rgba(248,113,113,.4); }
.flash.ok { background:rgba(52,211,153,.09); border-color:rgba(52,211,153,.35); }
.flash.warn { background:rgba(251,191,36,.09); border-color:rgba(251,191,36,.35); }
.kv { display:flex; justify-content:space-between; gap:14px; padding:8px 0;
  border-top:1px solid rgba(35,48,77,.6); font-size:13px; }
.kv span:first-child { color:var(--muted); }
.kv span:last-child { font-family:var(--mono); font-size:12.5px; text-align:right; word-break:break-all; }
code { font-family:var(--mono); font-size:12.5px; background:#0a1220; padding:1px 5px;
  border-radius:5px; border:1px solid var(--line); }
ol { padding-left:20px; color:var(--muted); font-size:14px; }
ol li { margin:6px 0; }
ol b { color:var(--text); }
a { color:var(--accent); }
.foot { color:var(--muted); font-size:12px; text-align:center; margin-top:22px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><span class="dot"></span> site_bridge setup</div>

<?php if ($done): ?>
  <div class="card">
    <h1>You are set up</h1>
    <p class="lead">The password is written. Nothing here needs a database.</p>
    <div class="flash ok"><b>Delete setup.php now</b> — it refuses to run again while a password
      exists, but leaving it on the server has no upside.</div>
    <div class="kv"><span>state file</span><span><?= h($statePath) ?></span></div>
    <div class="kv"><span>web root</span><span><?= $sameFolder ? 'state file is inside it' : 'state file is above it' ?></span></div>
    <div class="kv"><span>state protection</span><span><?= h($guardNote) ?></span></div>
    <ol style="margin-top:16px">
      <li>Delete <code>setup.php</code> from the server.</li>
      <li>Open <a href="index.php">index.php</a> and sign in.</li>
      <li>Create a token there and give its URL to your agent.</li>
    </ol>
    <div style="margin-top:16px"><a href="index.php"><button type="button">Open the panel</button></a></div>
  </div>

<?php elseif ($installed): ?>
  <div class="card">
    <h1>Already installed</h1>
    <p class="lead">A password exists, so this page will not overwrite it.</p>
    <div class="kv"><span>state file</span><span><?= h($statePath) ?></span></div>
    <div class="kv"><span>installed</span><span><?= $state['password_hash'] !== '' ? 'hashed' : 'plain (will be hashed on first sign-in)' ?></span></div>
    <ol style="margin-top:16px">
      <li><b>Forgot the password?</b> Fetch <code>bridge_state.json</code> over FTP, set
        <code>"password_hash"</code> and <code>"password_plain"</code> to empty strings, upload it
        back, then reload this page and pick a new one.</li>
      <li><b>Not your install?</b> Then someone else got here first: clear the state file and
        redo setup immediately.</li>
      <li>Otherwise <a href="index.php">open the panel</a> and delete <code>setup.php</code>.</li>
    </ol>
    <div class="flash warn">Only edit the JSON while the panel is closed, so both sides do not
      write at the same moment.</div>
  </div>

<?php else: ?>
  <div class="card">
    <h1>Pick an administrator password</h1>
    <p class="lead">This is the first run. The password unlocks the panel where you create
      tokens for the agent; it is stored as a hash in the state file, not in a database.</p>

    <?php foreach ($problems as $problem): ?>
      <div class="flash error"><?= h($problem) ?></div>
    <?php endforeach; ?>
    <?php if (!$folderWritable): ?>
      <div class="flash error">The folder <code><?= h($folder) ?></code> is not writable, so the
        password cannot be saved. Set it to 755 (or 775) and reload.</div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <label for="password">Password (8+ characters)</label>
      <input type="password" id="password" name="password" required minlength="8" autofocus>
      <label for="again">Repeat it</label>
      <input type="password" id="again" name="again" required minlength="8">
      <button type="submit" <?= $folderWritable ? '' : 'disabled' ?>>Save and finish</button>
    </form>

    <div style="margin-top:18px">
      <div class="kv"><span>state file</span><span><?= h($statePath) ?></span></div>
      <div class="kv"><span>kept</span><span>password hash · tokens · settings · activity log</span></div>
      <div class="kv"><span>factory reset</span><span>delete bridge_state.json and reload</span></div>
    </div>
    <p class="foot">No MySQL, no tables, no cron. Three files total.</p>
  </div>
<?php endif; ?>
</div>
</body>
</html>
