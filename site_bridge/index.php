<?php
/**
 * index.php — the admin panel.
 *
 * No database. Everything lives in bridge_state.json, which setup.php creates:
 * the password hash, the tokens, the settings and the activity log. This page
 * reads and writes that one file, so what the agent does is visible the moment
 * it happens.
 */

declare(strict_types=1);

const PANEL_VERSION = '1';
const PANEL_ACTIVITY_MAX = 400;

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'httponly' => true,
                                'secure' => $secure, 'samesite' => 'Lax'));
session_name('sitebridge');
session_start();

/* ------------------------------------------------------------------- state */

function state_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $outside = dirname(__DIR__) . '/bridge_state.json';
    $inside = __DIR__ . '/bridge_state.json';
    foreach (array($outside, $inside) as $candidate) {
        if (is_file($candidate)) {
            return $path = $candidate;
        }
    }
    if (is_dir(dirname(__DIR__)) && is_writable(dirname(__DIR__))) {
        return $path = $outside;
    }
    return $path = $inside;
}

function state_load(): array
{
    $empty = array('password_hash' => '', 'password_plain' => '',
                   'settings' => array(), 'tokens' => array(), 'activity' => array());
    $path = state_path();
    if (!is_file($path)) {
        return $empty;
    }
    $raw = @file_get_contents($path);
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

function state_save(array $state): bool
{
    if (count($state['activity']) > PANEL_ACTIVITY_MAX) {
        $state['activity'] = array_slice($state['activity'], -PANEL_ACTIVITY_MAX);
    }
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($encoded === false) {
        return false;
    }
    $path = state_path();
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

function settings_value(array $state, string $name, $default = null)
{
    if (!array_key_exists($name, $state['settings'])) {
        return $default;
    }
    $value = $state['settings'][$name];
    return ($value === '' || $value === null) ? $default : $value;
}

function escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clip(string $text, int $limit): string
{
    $text = str_replace(array("\r", "\n", "\t"), ' ', $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8')
                                        : substr($text, 0, $limit);
}

function client_ip(): string
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $value = (string) $_SERVER[$key];
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim($parts[0]);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }
    return '0.0.0.0';
}

/** Append to the same log the bridge writes, so the feed shows panel actions too. */
function log_event(array $state, string $op, string $summary, string $status): array
{
    $lastId = 0;
    if ($state['activity']) {
        $tail = $state['activity'][count($state['activity']) - 1];
        $lastId = isset($tail['id']) ? (int) $tail['id'] : 0;
    }
    $state['activity'][] = array(
        'id' => $lastId + 1,
        'ts' => date('Y-m-d H:i:s'),
        'op' => $op,
        'summary' => clip($summary, 240),
        'status' => $status,
        'ms' => 0,
        'bytes' => 0,
        'ip' => client_ip(),
        'token' => '',
    );
    return $state;
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

function flash(string $kind, string $text): void
{
    $_SESSION['flash'][] = array('kind' => $kind, 'text' => $text);
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $sent = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Bad request: the form token did not match. Reload the panel and try again.');
    }
}

function logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function verify_password(array $state, string $password): bool
{
    if ($state['password_hash'] !== '' && password_verify($password, $state['password_hash'])) {
        return true;
    }
    // Hand-edited escapes hatch: "password_plain" is accepted once and then
    // replaced by a hash, so a plain value never lingers in the file.
    return $state['password_plain'] !== '' && hash_equals($state['password_plain'], $password);
}

/* -------------------------------------------------------------- boot checks */

$state = state_load();
if ($state['password_hash'] === '' && $state['password_plain'] === '') {
    header('Location: setup.php');
    exit;
}
$stateReady = is_writable(state_path()) || is_writable(dirname(state_path()));
$bridgeUrl = base_url() . '/bridge.php';
$outsideWebroot = dirname(state_path()) !== __DIR__;

/* -------------------------------------------------------------- JSON feeds */

$api = isset($_GET['api']) ? (string) $_GET['api'] : '';
if ($api !== '') {
    if (!logged_in()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(array('error' => 'unauthorized'));
        exit;
    }
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($api === 'activity') {
        $state = state_load();
        $after = isset($_GET['after']) ? (int) $_GET['after'] : 0;
        $op = isset($_GET['op']) && $_GET['op'] !== 'all' ? (string) $_GET['op'] : '';
        $rows = array();
        foreach ($state['activity'] as $row) {
            if ((int) $row['id'] <= $after) {
                continue;
            }
            if ($op !== '' && (string) $row['op'] !== $op) {
                continue;
            }
            $rows[] = array(
                'id' => (int) $row['id'],
                'ts' => (string) $row['ts'],
                'age' => max(0, time() - (int) strtotime((string) $row['ts'])),
                'op' => (string) $row['op'],
                'summary' => (string) $row['summary'],
                'status' => (string) $row['status'],
                'ms' => (int) $row['ms'],
                'bytes' => (int) $row['bytes'],
                'ip' => (string) $row['ip'],
                'token' => (string) $row['token'],
            );
        }
        $rows = array_reverse($rows);
        echo json_encode(array('rows' => array_slice($rows, 0, 60), 'server_time' => date('H:i:s')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    http_response_code(404);
    echo json_encode(array('error' => 'unknown api'));
    exit;
}

/* ------------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'login') {
        require_csrf();
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $state = state_load();

        $recent = 0;
        foreach ($state['activity'] as $row) {
            if ($row['op'] === 'login' && $row['status'] === 'failed' && $row['ip'] === client_ip()
                    && (int) strtotime((string) $row['ts']) > time() - 600) {
                $recent++;
            }
        }
        if ($recent >= 10) {
            flash('error', 'Ten failed attempts from this address in ten minutes. Wait it out.');
        } elseif (verify_password($state, $password)) {
            if ($state['password_plain'] !== '') {
                $state['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $state['password_plain'] = '';
                state_save($state);
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            $state = log_event($state, 'login', 'panel sign-in', 'ok');
            state_save($state);
            header('Location: index.php');
            exit;
        } else {
            $state = log_event($state, 'login', 'wrong password', 'failed');
            state_save($state);
            flash('error', 'Wrong password.');
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'logout') {
        require_csrf();
        $_SESSION = array();
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if (!logged_in()) {
        http_response_code(401);
        exit('Sign in first.');
    }

    if ($action === 'create') {
        require_csrf();
        $state = state_load();
        $label = clip(trim((string) ($_POST['label'] ?? '')), 64);
        $hours = (int) ($_POST['hours'] ?? 24);
        $token = 'sb_' . bin2hex(random_bytes(24));
        $state['tokens'][] = array(
            'token' => $token,
            'label' => $label,
            'created' => time(),
            'expires' => $hours > 0 ? time() + max(1, min($hours, 24 * 365)) * 3600 : null,
            'revoked' => null,
            'uses' => 0,
            'last_used' => null,
        );
        $state = log_event($state, 'panel', 'issued token "' . $label . '"', 'ok');
        if (state_save($state)) {
            $_SESSION['new_token'] = $token;
            flash('ok', 'Token created. It stays visible in the table below.');
        } else {
            flash('error', 'Could not write ' . state_path() . ' — check its permissions.');
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'revoke' || $action === 'restore' || $action === 'delete') {
        require_csrf();
        $index = (int) ($_POST['index'] ?? -1);
        $state = state_load();
        if (isset($state['tokens'][$index])) {
            if ($action === 'delete') {
                array_splice($state['tokens'], $index, 1);
                $state = log_event($state, 'panel', 'deleted a token', 'ok');
            } else {
                $state['tokens'][$index]['revoked'] = $action === 'revoke' ? time() : null;
                $state = log_event($state, 'panel',
                    ($action === 'revoke' ? 'revoked' : 'restored') . ' a token', 'ok');
            }
            state_save($state);
            flash('ok', 'Token ' . ($action === 'delete' ? 'deleted' : $action . 'd') . '.');
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'settings') {
        require_csrf();
        $state = state_load();
        $root = trim((string) ($_POST['root'] ?? ''));
        $state['settings']['root'] = $root;
        $state['settings']['allow_php'] = !empty($_POST['allow_php']);
        $state = log_event($state, 'panel',
            'settings: allow_php=' . ($state['settings']['allow_php'] ? 'on' : 'off')
            . ($root !== '' ? ', root=' . $root : ''), 'ok');
        state_save($state);
        flash('ok', 'Settings saved. The bridge reads them on its next request.');
        header('Location: index.php');
        exit;
    }

    if ($action === 'password') {
        require_csrf();
        $state = state_load();
        $current = (string) ($_POST['current'] ?? '');
        $next = (string) ($_POST['next'] ?? '');
        $again = (string) ($_POST['again'] ?? '');
        if (!verify_password($state, $current)) {
            flash('error', 'Current password is wrong.');
        } elseif (strlen($next) < 8) {
            flash('error', 'Use at least 8 characters.');
        } elseif ($next !== $again) {
            flash('error', 'The new passwords do not match.');
        } else {
            $state['password_hash'] = password_hash($next, PASSWORD_DEFAULT);
            $state['password_plain'] = '';
            $state = log_event($state, 'panel', 'admin password changed', 'ok');
            state_save($state);
            flash('ok', 'Password changed.');
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'clear_log') {
        require_csrf();
        $state = state_load();
        $state['activity'] = array();
        state_save($state);
        flash('ok', 'Activity log cleared.');
        header('Location: index.php');
        exit;
    }
}

/* --------------------------------------------------------------- view model */

$state = state_load();
$tokens = $state['tokens'];
$flashes = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
$_SESSION['flash'] = array();
$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);
$allowPhp = (bool) settings_value($state, 'allow_php', false);
$rootSetting = (string) settings_value($state, 'root', '');
$activity = array_reverse($state['activity']);
$lastId = $activity ? (int) $activity[0]['id'] : 0;
$activeTokens = 0;
foreach ($tokens as $token) {
    $expired = !empty($token['expires']) && (int) $token['expires'] <= time();
    if (empty($token['revoked']) && !$expired) {
        $activeTokens++;
    }
}
$requestsToday = 0;
foreach ($state['activity'] as $row) {
    if (substr((string) $row['ts'], 0, 10) === date('Y-m-d')) {
        $requestsToday++;
    }
}
$lastSeen = $activity ? (string) $activity[0]['ts'] : '';
$tokenUrl = function (string $token): string {
    return base_url() . '/bridge.php?t=' . $token . '&op=help';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>site_bridge — panel</title>
<style>
:root {
  --bg: #0a0f1c; --panel: #111a2e; --panel-2: #16223a; --line: #23304d;
  --text: #e6edf7; --muted: #8ea0bd; --accent: #5eead4; --accent-2: #a78bfa;
  --ok: #34d399; --warn: #fbbf24; --err: #f87171;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
* { box-sizing: border-box; }
body {
  margin: 0; background: radial-gradient(1200px 600px at 15% -10%, #16233d 0%, var(--bg) 55%);
  color: var(--text); font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  min-height: 100vh;
}
a { color: var(--accent); }
.wrap { max-width: 1180px; margin: 0 auto; padding: 26px 20px 60px; }
header.top { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 22px; }
.brand { display: flex; align-items: center; gap: 11px; font-weight: 650; letter-spacing: .2px; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--ok);
  box-shadow: 0 0 0 0 rgba(52,211,153,.55); animation: pulse 2.4s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(52,211,153,.5); }
  70% { box-shadow: 0 0 0 10px rgba(52,211,153,0); }
  100% { box-shadow: 0 0 0 0 rgba(52,211,153,0); } }
.spacer { flex: 1; }
.pill { font-size: 12px; padding: 5px 11px; border-radius: 999px; border: 1px solid var(--line);
  background: var(--panel); color: var(--muted); }
.pill b { color: var(--text); font-weight: 600; }
.grid { display: grid; gap: 18px; grid-template-columns: 1.05fr .95fr; align-items: start; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
.card { background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
  border: 1px solid var(--line); border-radius: 16px; padding: 18px;
  box-shadow: 0 18px 40px -28px rgba(0,0,0,.9); }
.card + .card { margin-top: 18px; }
h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .12em; color: var(--muted);
  margin: 0 0 14px; font-weight: 650; }
label { display: block; font-size: 12px; color: var(--muted); margin: 10px 0 5px; }
input[type=text], input[type=password], select {
  width: 100%; padding: 10px 12px; border-radius: 10px; background: #0c1526;
  border: 1px solid var(--line); color: var(--text); font: inherit; font-size: 14px; }
input:focus, select:focus { outline: 2px solid rgba(94,234,212,.35); border-color: var(--accent); }
button { font: inherit; font-size: 14px; font-weight: 600; padding: 10px 16px; border-radius: 10px;
  border: 1px solid transparent; background: var(--accent); color: #062016; cursor: pointer; }
button:hover { filter: brightness(1.08); }
button.ghost { background: transparent; color: var(--text); border-color: var(--line); }
button.danger { background: transparent; color: var(--err); border-color: rgba(248,113,113,.45); }
button.small { padding: 6px 10px; font-size: 12px; }
.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
.row > * { flex: 1; min-width: 120px; }
.row > .fixed { flex: 0 0 auto; min-width: 0; }
.flash { border-radius: 12px; padding: 11px 14px; margin-bottom: 14px; font-size: 14px; border: 1px solid; }
.flash.ok { background: rgba(52,211,153,.09); border-color: rgba(52,211,153,.35); }
.flash.error { background: rgba(248,113,113,.1); border-color: rgba(248,113,113,.4); }
.token-value { font-family: var(--mono); font-size: 12.5px; word-break: break-all;
  background: #0a1220; border: 1px solid var(--line); border-radius: 9px; padding: 9px 10px;
  color: var(--accent); }
.token-reveal { border: 1px dashed rgba(94,234,212,.55); border-radius: 12px; padding: 14px;
  background: rgba(94,234,212,.05); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .09em;
  color: var(--muted); padding: 0 8px 8px; font-weight: 650; }
td { padding: 9px 8px; border-top: 1px solid rgba(35,48,77,.7); vertical-align: top; }
.mono, td.mono { font-family: var(--mono); font-size: 12.5px; }
.badge { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
  border: 1px solid; white-space: nowrap; }
.badge.ok, .b-completed { color: var(--ok); border-color: rgba(52,211,153,.4); background: rgba(52,211,153,.08); }
.badge.warn, .b-queued, .b-running { color: var(--warn); border-color: rgba(251,191,36,.4); background: rgba(251,191,36,.08); }
.badge.err, .b-failed, .b-unauthorized { color: var(--err); border-color: rgba(248,113,113,.4); background: rgba(248,113,113,.08); }
.badge.mute, .b-cancelled, .b-auth, .b-login, .b-panel { color: var(--muted); border-color: var(--line); background: rgba(142,160,189,.06); }
.feed { font-family: var(--mono); font-size: 12.5px; max-height: 440px; overflow: auto; }
.feed-row { display: grid; grid-template-columns: 58px 66px 1fr 74px; gap: 8px; padding: 7px 4px;
  border-top: 1px solid rgba(35,48,77,.55); align-items: baseline; }
.feed-row:first-child { border-top: 0; }
.feed-time { color: var(--muted); font-size: 11.5px; }
.feed-op { color: var(--accent-2); }
.feed-sum { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.feed-status { text-align: right; font-size: 11.5px; }
.hint { color: var(--muted); font-size: 12.5px; margin: 8px 0 0; }
.kv { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0;
  border-top: 1px solid rgba(35,48,77,.55); font-size: 13px; }
.kv:first-of-type { border-top: 0; }
.kv span:first-child { color: var(--muted); }
.kv span:last-child { font-family: var(--mono); font-size: 12.5px; text-align: right; word-break: break-all; }
.login-wrap { max-width: 420px; margin: 12vh auto 0; }
.foot { color: var(--muted); font-size: 12px; margin-top: 26px; text-align: center; }
details summary { cursor: pointer; color: var(--muted); font-size: 13px; }
.copy { background: transparent; color: var(--accent); border: 1px solid rgba(94,234,212,.35);
  padding: 4px 9px; font-size: 11.5px; border-radius: 8px; }
.switch { display: flex; align-items: center; gap: 9px; margin-top: 12px; font-size: 13px; }
.switch input { width: 16px; height: 16px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!logged_in()): ?>
  <div class="login-wrap">
    <div class="brand" style="justify-content:center;margin-bottom:18px"><span class="dot"></span> site_bridge</div>
    <div class="card">
      <h2>Sign in</h2>
      <?php foreach ($flashes as $item): ?>
        <div class="flash <?= escape($item['kind']) ?>"><?= escape($item['text']) ?></div>
      <?php endforeach; ?>
      <?php if (!$stateReady): ?>
        <div class="flash error">Cannot write <?= escape(state_path()) ?>. Give the folder write permission (755/775).</div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
        <label for="password">Administrator password</label>
        <input type="password" id="password" name="password" required autofocus>
        <div style="margin-top:14px"><button type="submit">Unlock panel</button></div>
      </form>
      <p class="hint">Stored as a hash in <code><?= escape(basename(state_path())) ?></code>.
        Ten wrong attempts in ten minutes block this address.</p>
    </div>
    <p class="foot">site_bridge v<?= escape(PANEL_VERSION) ?> · PHP <?= escape(PHP_VERSION) ?></p>
  </div>
<?php else: ?>
  <header class="top">
    <div class="brand"><span class="dot"></span> site_bridge</div>
    <span class="pill">tokens <b><?= $activeTokens ?></b> active</span>
    <span class="pill">today <b><?= $requestsToday ?></b></span>
    <span class="pill">last <b><?= $lastSeen !== '' ? escape(substr($lastSeen, 11)) : 'never' ?></b></span>
    <div class="spacer"></div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
      <button class="ghost small" type="submit">Sign out</button>
    </form>
  </header>

  <?php foreach ($flashes as $item): ?>
    <div class="flash <?= escape($item['kind']) ?>"><?= escape($item['text']) ?></div>
  <?php endforeach; ?>

  <?php if ($newToken !== null): ?>
    <div class="card" style="border-color:rgba(94,234,212,.45)">
      <h2>New token</h2>
      <div class="token-reveal">
        <div class="token-value" id="new-token"><?= escape($newToken) ?></div>
        <div class="row" style="align-items:center;margin-top:10px">
          <div class="fixed"><button class="small" data-copy="#new-token" type="button">Copy token</button></div>
          <div class="fixed"><button class="ghost small" data-copy="#new-url" type="button">Copy test URL</button></div>
        </div>
        <div class="token-value" id="new-url" style="color:var(--accent-2);margin-top:10px"><?=
          escape($tokenUrl($newToken)) ?></div>
      </div>
      <p class="hint">Give the agent that URL. It is also kept in the table, so you can read it again later.</p>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div>
      <div class="card">
        <h2>Issue a token</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
          <div class="row">
            <div>
              <label for="label">Label</label>
              <input type="text" id="label" name="label" maxlength="64" placeholder="claude — session 3">
            </div>
            <div class="fixed" style="min-width:150px">
              <label for="hours">Expires</label>
              <select id="hours" name="hours">
                <option value="1">in 1 hour</option>
                <option value="8">in 8 hours</option>
                <option value="24" selected>in 24 hours</option>
                <option value="168">in 7 days</option>
                <option value="720">in 30 days</option>
                <option value="0">never</option>
              </select>
            </div>
            <div class="fixed"><button type="submit">Create</button></div>
          </div>
        </form>
      </div>

      <div class="card">
        <h2>Tokens</h2>
        <?php if (!$tokens): ?>
          <p class="hint">None yet. Create one above and hand the agent its URL.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Token</th><th>State</th><th>Use</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $index => $token):
              $revoked = !empty($token['revoked']);
              $expired = !empty($token['expires']) && (int) $token['expires'] <= time();
              $stateName = $revoked ? 'revoked' : ($expired ? 'expired' : 'active');
              $stateClass = $stateName === 'active' ? 'ok' : ($stateName === 'expired' ? 'warn' : 'err');
              $id = 'token-' . (int) $index;
          ?>
            <tr>
              <td>
                <div class="mono" id="<?= $id ?>"><?= escape((string) $token['token']) ?></div>
                <div class="feed-time">
                  <?= $token['label'] !== '' ? escape((string) $token['label']) . ' · ' : '' ?>
                  created <?= escape(date('Y-m-d H:i', (int) $token['created'])) ?>
                </div>
                <button class="copy" type="button" data-copy="#<?= $id ?>">copy</button>
                <button class="copy" type="button" data-copy-url="<?= escape($tokenUrl((string) $token['token'])) ?>">copy URL</button>
              </td>
              <td>
                <span class="badge <?= $stateClass ?>"><?= $stateName ?></span>
                <div class="feed-time">
                  <?= !empty($token['expires']) ? 'until ' . escape(date('m-d H:i', (int) $token['expires'])) : 'no expiry' ?>
                </div>
              </td>
              <td class="mono">
                <?= (int) $token['uses'] ?>×
                <div class="feed-time">
                  <?= !empty($token['last_used']) ? escape(date('m-d H:i', (int) $token['last_used'])) : 'never' ?>
                </div>
              </td>
              <td style="text-align:right;white-space:nowrap">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
                  <input type="hidden" name="index" value="<?= (int) $index ?>">
                  <input type="hidden" name="action" value="<?= $revoked ? 'restore' : 'revoke' ?>">
                  <button class="ghost small" type="submit"><?= $revoked ? 'restore' : 'revoke' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this token?')">
                  <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
                  <input type="hidden" name="index" value="<?= (int) $index ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="danger small" type="submit">delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Settings</h2>
        <form method="post">
          <input type="hidden" name="action" value="settings">
          <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
          <label for="root">Folder the agent may touch (empty = the folder holding these files)</label>
          <input type="text" id="root" name="root" value="<?= escape($rootSetting) ?>" placeholder="/home/you/htdocs/site">
          <label class="switch">
            <input type="checkbox" name="allow_php" value="1" <?= $allowPhp ? 'checked' : '' ?>>
            Allow <code>op=php</code> — the agent can run PHP on this account
          </label>
          <div style="margin-top:12px"><button type="submit">Save</button></div>
        </form>
        <div class="kv"><span>state file</span><span><?= escape(state_path()) ?></span></div>
        <div class="kv"><span>outside web root</span><span><?= $outsideWebroot ? 'yes' : 'no — .htaccess protects it' ?></span></div>
        <div class="kv"><span>endpoint</span><span><?= escape($bridgeUrl) ?></span></div>
        <details style="margin-top:12px">
          <summary>Change the admin password</summary>
          <form method="post" style="margin-top:10px" autocomplete="off">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
            <label for="current">Current</label>
            <input type="password" id="current" name="current" required>
            <label for="next">New (8+ characters)</label>
            <input type="password" id="next" name="next" required minlength="8">
            <label for="again">Repeat</label>
            <input type="password" id="again" name="again" required minlength="8">
            <div style="margin-top:12px"><button type="submit">Change password</button></div>
          </form>
        </details>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Live activity</h2>
        <div class="row" style="align-items:center;margin-bottom:10px">
          <div class="fixed">
            <select id="filter" style="padding:7px 10px;font-size:13px">
              <option value="all">all ops</option>
              <option value="auth">auth</option>
              <option value="login">login</option>
              <option value="panel">panel</option>
              <option value="ls">ls</option>
              <option value="read">read</option>
              <option value="write">write</option>
              <option value="edit">edit</option>
              <option value="rm">rm</option>
              <option value="php">php</option>
              <option value="job">job</option>
              <option value="help">help</option>
            </select>
          </div>
          <div class="fixed"><span class="pill" id="live-pill"><span class="dot" style="display:inline-block;width:7px;height:7px;margin-right:6px"></span>live</span></div>
          <div class="fixed"><button class="ghost small" id="pause" type="button">pause</button></div>
          <div class="spacer"></div>
          <div class="fixed"><span class="pill" id="feed-count">0</span></div>
        </div>
        <div class="feed" id="feed">
          <?php if (!$activity): ?>
            <p class="hint" id="feed-empty">Nothing yet. The first fetch the agent makes lands here within a couple of seconds.</p>
          <?php endif; ?>
          <?php foreach ($activity as $row): ?>
            <div class="feed-row">
              <div class="feed-time"><?= escape(substr((string) $row['ts'], 11)) ?></div>
              <div class="feed-op"><?= escape((string) $row['op']) ?></div>
              <div class="feed-sum" title="<?= escape((string) $row['summary']) ?>"><?= escape((string) $row['summary']) ?></div>
              <div class="feed-status"><span class="badge b-<?= escape((string) $row['status']) ?>"><?= escape((string) $row['status']) ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="hint">Polls every 2.5 s, pauses when the tab is hidden.
          <a href="#" id="clear-log">clear log</a></p>
        <form method="post" id="clear-form" style="display:none">
          <input type="hidden" name="action" value="clear_log">
          <input type="hidden" name="csrf" value="<?= escape(csrf()) ?>">
        </form>
      </div>

      <div class="card">
        <h2>What the agent can do</h2>
        <div class="kv"><span>files</span><span>ls read write edit mkdir rm upload</span></div>
        <div class="kv"><span>code</span><span>php <?= $allowPhp ? 'enabled' : 'disabled' ?></span></div>
        <div class="kv"><span>shell / sql</span><span>not available (no shell; no database here)</span></div>
        <p class="hint">Each fetch is one request, capped near 10 s by the host. Longer
          work runs as ticks driven by the agent's own polls.</p>
      </div>
    </div>
  </div>
  <p class="foot">site_bridge v<?= escape(PANEL_VERSION) ?> · PHP <?= escape(PHP_VERSION) ?> ·
    <a href="bridge.php">bridge.php</a> is the only file the agent talks to</p>

<script>
(function () {
  var feed = document.getElementById('feed');
  if (!feed) { return; }
  var lastId = <?= (int) $lastId ?>;
  var paused = false;
  var filter = document.getElementById('filter');
  var counter = document.getElementById('feed-count');
  var pill = document.getElementById('live-pill');
  var pauseBtn = document.getElementById('pause');
  var seen = 0;

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function rowHtml(r) {
    var age = r.age < 60 ? r.age + 's' : (r.age < 3600 ? Math.floor(r.age / 60) + 'm' : Math.floor(r.age / 3600) + 'h');
    return '<div class="feed-time">' + age + '</div>'
      + '<div class="feed-op">' + esc(r.op) + '</div>'
      + '<div class="feed-sum" title="' + esc(r.summary) + '">' + esc(r.summary || '') + '</div>'
      + '<div class="feed-status"><span class="badge b-' + esc(r.status) + '">' + esc(r.status) + '</span></div>';
  }
  function poll() {
    if (paused || document.hidden) { return; }
    fetch('index.php?api=activity&after=' + lastId + '&op=' + encodeURIComponent(filter.value))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.rows || !data.rows.length) { return; }
        var empty = document.getElementById('feed-empty');
        if (empty) { empty.remove(); }
        data.rows.forEach(function (row) {
          var wrap = document.createElement('div');
          wrap.className = 'feed-row';
          wrap.innerHTML = rowHtml(row);
          feed.insertBefore(wrap, feed.firstChild);
          lastId = Math.max(lastId, row.id);
          seen++;
        });
        while (feed.children.length > 120) { feed.removeChild(feed.lastChild); }
        counter.textContent = '+' + seen;
      })
      .catch(function () { pill.style.color = 'var(--err)'; });
  }
  setInterval(poll, 2500);
  pauseBtn.addEventListener('click', function () {
    paused = !paused;
    pauseBtn.textContent = paused ? 'resume' : 'pause';
    pill.style.opacity = paused ? '.45' : '1';
  });
  filter.addEventListener('change', function () { lastId = 0; seen = 0; counter.textContent = '0'; });
  var clear = document.getElementById('clear-log');
  if (clear) {
    clear.addEventListener('click', function (event) {
      event.preventDefault();
      if (confirm('Clear the activity log?')) { document.getElementById('clear-form').submit(); }
    });
  }
  function copyText(text, button, restore) {
    function done() {
      button.textContent = 'copied';
      setTimeout(function () { button.textContent = restore; }, 1400);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      var area = document.createElement('textarea');
      area.value = text;
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      area.remove();
      done();
    }
  }
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.querySelector(button.getAttribute('data-copy'));
      if (target) { copyText(target.textContent, button, button.textContent); }
    });
  });
  document.querySelectorAll('[data-copy-url]').forEach(function (button) {
    button.addEventListener('click', function () {
      copyText(button.getAttribute('data-copy-url'), button, button.textContent);
    });
  });
})();
</script>
<?php endif; ?>
</div>
</body>
</html>
