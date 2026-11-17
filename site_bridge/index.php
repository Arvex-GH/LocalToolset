<?php
/**
 * index.php — the admin panel.
 *
 * Password-protected (hash in sb_settings), session-based, CSRF-checked. From
 * here the user issues a token per AI session, gives it an expiry, revokes it,
 * and watches what that token is doing: every bridge request is logged to
 * sb_activity and streamed into the feed below.
 *
 * Same host as bridge.php, so this file must be treated as part of the attack
 * surface: it is the only place a token can be minted.
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
session_name('sitebridge');
session_start();

/* ------------------------------------------------------------------- setup */

$dbError = '';
try {
    $link = sb_db();
} catch (Throwable $exc) {
    $link = null;
    $dbError = $exc->getMessage();
}

if ($link !== null && !sb_installed($link)) {
    header('Location: setup.php');
    exit;
}

function flash(string $kind, string $text): void
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = array();
    }
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

function recent_login_failures(mysqli $link): int
{
    $ip = sb_ip();
    $statement = $link->prepare(
        'SELECT COUNT(*) AS n FROM sb_activity WHERE op = \'login\' AND status = \'failed\' '
        . 'AND ip = ? AND ts > (NOW() - INTERVAL 10 MINUTE)'
    );
    if (!$statement) {
        return 0;
    }
    $statement->bind_param('s', $ip);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $statement->close();
    return $row ? (int) $row['n'] : 0;
}

/* -------------------------------------------------------------- JSON feeds */

$api = isset($_GET['api']) ? (string) $_GET['api'] : '';
if ($api !== '') {
    if (!logged_in() || $link === null) {
        sb_json(array('error' => 'unauthorized'), 401);
    }
    if ($api === 'activity') {
        $after = isset($_GET['after']) ? (int) $_GET['after'] : 0;
        $op = isset($_GET['op']) && $_GET['op'] !== 'all' ? (string) $_GET['op'] : '';
        $sql = 'SELECT a.id, a.token_id, a.ts, a.op, a.summary, a.status, a.ms, a.bytes, a.ip, '
            . 't.label AS token_label FROM sb_activity a LEFT JOIN sb_tokens t ON t.id = a.token_id '
            . 'WHERE a.id > ?';
        if ($op !== '') {
            $sql .= ' AND a.op = ?';
        }
        $sql .= ' ORDER BY a.id DESC LIMIT 60';
        $statement = $link->prepare($sql);
        $rows = array();
        if ($statement) {
            if ($op !== '') {
                $statement->bind_param('is', $after, $op);
            } else {
                $statement->bind_param('i', $after);
            }
            $statement->execute();
            $result = $statement->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $rows[] = array(
                    'id' => (int) $row['id'],
                    'ts' => $row['ts'],
                    'age' => max(0, time() - strtotime((string) $row['ts'])),
                    'op' => $row['op'],
                    'summary' => $row['summary'],
                    'status' => $row['status'],
                    'ms' => (int) $row['ms'],
                    'bytes' => (int) $row['bytes'],
                    'ip' => $row['ip'],
                    'token' => $row['token_label'] !== null ? $row['token_label'] : '—',
                );
            }
            $statement->close();
        }
        sb_json(array('rows' => $rows, 'server_time' => sb_now()));
    }
    if ($api === 'stats') {
        $tokens = 0;
        $result = $link->query('SELECT COUNT(*) AS n FROM sb_tokens WHERE revoked_at IS NULL '
            . 'AND (expires_at IS NULL OR expires_at > NOW())');
        if ($result) {
            $tokens = (int) $result->fetch_assoc()['n'];
            $result->free();
        }
        $today = 0;
        $result = $link->query('SELECT COUNT(*) AS n FROM sb_activity WHERE ts >= CURDATE()');
        if ($result) {
            $today = (int) $result->fetch_assoc()['n'];
            $result->free();
        }
        sb_json(array('active_tokens' => $tokens, 'requests_today' => $today));
    }
    sb_json(array('error' => 'unknown api'), 404);
}

/* ------------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link !== null) {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'login') {
        require_csrf();
        $failures = recent_login_failures($link);
        if ($failures >= 10) {
            flash('error', 'Too many failed attempts from this address. Wait 10 minutes.');
        } else {
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $hash = sb_installed($link) ? sb_setting_get($link, 'admin_hash') : '';
            if ($hash !== '' && password_verify($password, $hash)) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
                sb_log($link, null, 'login', 'panel sign-in', 'ok', 0, 0);
                header('Location: index.php');
                exit;
            }
            sb_log($link, null, 'login', 'wrong password', 'failed', 0, 0);
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
        $label = isset($_POST['label']) ? sb_clip(trim((string) $_POST['label']), 64) : '';
        $hours = isset($_POST['hours']) ? (int) $_POST['hours'] : 24;
        $token = sb_new_token();
        $hash = sb_token_hash($token);
        $hint = sb_token_hint($token);
        $expires = sb_expiry_date($hours);
        $now = sb_now();
        $statement = $link->prepare(
            'INSERT INTO sb_tokens (token_hash, hint, label, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        if ($statement) {
            $statement->bind_param('sssss', $hash, $hint, $label, $now, $expires);
            $statement->execute();
            $statement->close();
            $_SESSION['new_token'] = array(
                'token' => $token,
                'label' => $label,
                'expires' => $expires,
            );
            flash('ok', 'Token created. Copy it now — it is shown once and never again.');
        } else {
            flash('error', 'Could not create the token: ' . $link->error);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'revoke' || $action === 'restore' || $action === 'delete') {
        require_csrf();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            if ($action === 'delete') {
                $statement = $link->prepare('DELETE FROM sb_tokens WHERE id = ?');
                $statement->bind_param('i', $id);
                $statement->execute();
                $statement->close();
                flash('ok', 'Token deleted. Its old log lines stay for the record.');
            } else {
                $value = $action === 'revoke' ? sb_now() : null;
                $statement = $link->prepare('UPDATE sb_tokens SET revoked_at = ? WHERE id = ?');
                $statement->bind_param('si', $value, $id);
                $statement->execute();
                $statement->close();
                flash('ok', $action === 'revoke' ? 'Token revoked — it now answers 401.' : 'Token restored.');
            }
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'password') {
        require_csrf();
        $current = isset($_POST['current']) ? (string) $_POST['current'] : '';
        $next = isset($_POST['next']) ? (string) $_POST['next'] : '';
        $again = isset($_POST['again']) ? (string) $_POST['again'] : '';
        $hash = sb_setting_get($link, 'admin_hash');
        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is wrong.');
        } elseif (strlen($next) < 10) {
            flash('error', 'Use at least 10 characters.');
        } elseif ($next !== $again) {
            flash('error', 'The new passwords do not match.');
        } else {
            sb_setting_put($link, 'admin_hash', password_hash($next, PASSWORD_DEFAULT));
            sb_log($link, null, 'panel', 'admin password changed', 'ok', 0, 0);
            flash('ok', 'Password changed.');
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'clear_log') {
        require_csrf();
        $link->query('DELETE FROM sb_activity');
        flash('ok', 'Activity log cleared.');
        header('Location: index.php');
        exit;
    }
}

/* --------------------------------------------------------------- view model */

$tokens = array();
$activity = array();
$stats = array('active' => 0, 'requests_today' => 0, 'last_seen' => '');
if ($link !== null && logged_in()) {
    $result = $link->query(
        'SELECT id, hint, label, created_at, expires_at, revoked_at, last_used_at, uses '
        . 'FROM sb_tokens ORDER BY id DESC LIMIT 50'
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $tokens[] = $row;
    }
    if ($result) {
        $result->free();
    }
    $result = $link->query('SELECT id, token_id, ts, op, summary, status, ms, bytes, ip '
        . 'FROM sb_activity ORDER BY id DESC LIMIT 40');
    while ($result && ($row = $result->fetch_assoc())) {
        $activity[] = $row;
    }
    if ($result) {
        $result->free();
    }
    $result = $link->query('SELECT COUNT(*) AS n FROM sb_tokens WHERE revoked_at IS NULL '
        . 'AND (expires_at IS NULL OR expires_at > NOW())');
    if ($result) {
        $stats['active'] = (int) $result->fetch_assoc()['n'];
        $result->free();
    }
    $result = $link->query('SELECT COUNT(*) AS n FROM sb_activity WHERE ts >= CURDATE()');
    if ($result) {
        $stats['requests_today'] = (int) $result->fetch_assoc()['n'];
        $result->free();
    }
    $result = $link->query('SELECT MAX(ts) AS t FROM sb_activity');
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['last_seen'] = $row && $row['t'] ? (string) $row['t'] : '';
        $result->free();
    }
}

$flashes = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
$_SESSION['flash'] = array();
$newToken = isset($_SESSION['new_token']) ? $_SESSION['new_token'] : null;
unset($_SESSION['new_token']);
$allowPhp = !empty(sb_setting('allow_php', false));
$bridgeUrl = sb_base_url() . '/bridge.php';
$lastId = $activity ? (int) $activity[0]['id'] : 0;
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
  --ok: #34d399; --warn: #fbbf24; --err: #f87171; --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
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
.dot {
  width: 10px; height: 10px; border-radius: 50%; background: var(--ok);
  box-shadow: 0 0 0 0 rgba(52, 211, 153, .55); animation: pulse 2.4s infinite;
}
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, .5); }
  70% { box-shadow: 0 0 0 10px rgba(52, 211, 153, 0); }
  100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
}
.spacer { flex: 1; }
.pill {
  font-size: 12px; padding: 5px 11px; border-radius: 999px;
  border: 1px solid var(--line); background: var(--panel); color: var(--muted);
}
.pill b { color: var(--text); font-weight: 600; }
.grid { display: grid; gap: 18px; grid-template-columns: 1.05fr .95fr; align-items: start; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
.card {
  background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
  border: 1px solid var(--line); border-radius: 16px; padding: 18px 18px 16px;
  box-shadow: 0 18px 40px -28px rgba(0, 0, 0, .9);
}
.card + .card { margin-top: 18px; }
h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); margin: 0 0 14px; font-weight: 650; }
label { display: block; font-size: 12px; color: var(--muted); margin: 10px 0 5px; }
input[type=text], input[type=password], input[type=number], select {
  width: 100%; padding: 10px 12px; border-radius: 10px; background: #0c1526;
  border: 1px solid var(--line); color: var(--text); font: inherit; font-size: 14px;
}
input:focus, select:focus { outline: 2px solid rgba(94, 234, 212, .35); outline-offset: 1px; border-color: var(--accent); }
button {
  font: inherit; font-size: 14px; font-weight: 600; padding: 10px 16px; border-radius: 10px;
  border: 1px solid transparent; background: var(--accent); color: #062016; cursor: pointer;
}
button:hover { filter: brightness(1.08); }
button.ghost { background: transparent; color: var(--text); border-color: var(--line); }
button.danger { background: transparent; color: var(--err); border-color: rgba(248, 113, 113, .45); }
button.small { padding: 6px 10px; font-size: 12px; }
.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
.row > * { flex: 1; min-width: 120px; }
.row > .fixed { flex: 0 0 auto; min-width: 0; }
.flash { border-radius: 12px; padding: 11px 14px; margin-bottom: 14px; font-size: 14px; border: 1px solid; }
.flash.ok { background: rgba(52, 211, 153, .09); border-color: rgba(52, 211, 153, .35); }
.flash.error { background: rgba(248, 113, 113, .1); border-color: rgba(248, 113, 113, .4); }
.token-reveal { border: 1px dashed rgba(94, 234, 212, .55); border-radius: 12px; padding: 14px; background: rgba(94, 234, 212, .05); }
.token-value {
  font-family: var(--mono); font-size: 13px; word-break: break-all; background: #0a1220;
  border: 1px solid var(--line); border-radius: 9px; padding: 10px; margin: 9px 0; color: var(--accent);
}
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); padding: 0 8px 8px; font-weight: 650; }
td { padding: 9px 8px; border-top: 1px solid rgba(35, 48, 77, .7); vertical-align: top; }
td.mono, .mono { font-family: var(--mono); font-size: 12.5px; }
.badge { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid; white-space: nowrap; }
.badge.ok, .b-completed { color: var(--ok); border-color: rgba(52, 211, 153, .4); background: rgba(52, 211, 153, .08); }
.badge.warn, .b-queued, .b-running { color: var(--warn); border-color: rgba(251, 191, 36, .4); background: rgba(251, 191, 36, .08); }
.badge.err, .b-failed, .b-unauthorized { color: var(--err); border-color: rgba(248, 113, 113, .4); background: rgba(248, 113, 113, .08); }
.badge.mute, .b-cancelled, .b-auth, .b-login, .b-panel { color: var(--muted); border-color: var(--line); background: rgba(142, 160, 189, .06); }
.feed { font-family: var(--mono); font-size: 12.5px; max-height: 430px; overflow: auto; }
.feed-row { display: grid; grid-template-columns: 62px 74px 1fr 78px; gap: 8px; padding: 7px 4px; border-top: 1px solid rgba(35, 48, 77, .55); align-items: baseline; }
.feed-row:first-child { border-top: 0; }
.feed-row.new { animation: flashin 1.1s ease-out; }
@keyframes flashin { from { background: rgba(94, 234, 212, .16); } to { background: transparent; } }
.feed-time { color: var(--muted); font-size: 11.5px; }
.feed-op { color: var(--accent-2); }
.feed-sum { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.feed-status { text-align: right; font-size: 11.5px; }
.hint { color: var(--muted); font-size: 12.5px; margin: 8px 0 0; }
.kv { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-top: 1px solid rgba(35, 48, 77, .55); font-size: 13px; }
.kv:first-of-type { border-top: 0; }
.kv span:first-child { color: var(--muted); }
.kv span:last-child { font-family: var(--mono); font-size: 12.5px; text-align: right; word-break: break-all; }
.login-wrap { max-width: 420px; margin: 12vh auto 0; }
.center { text-align: center; }
.foot { color: var(--muted); font-size: 12px; margin-top: 26px; text-align: center; }
.foot code { color: var(--text); }
details summary { cursor: pointer; color: var(--muted); font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!logged_in()): ?>
  <div class="login-wrap">
    <div class="brand" style="justify-content:center;margin-bottom:18px;">
      <span class="dot"></span> site_bridge
    </div>
    <div class="card">
      <h2>Sign in</h2>
      <?php foreach ($flashes as $item): ?>
        <div class="flash <?= sb_escape($item['kind']) ?>"><?= sb_escape($item['text']) ?></div>
      <?php endforeach; ?>
      <?php if ($dbError !== ''): ?>
        <div class="flash error"><?= sb_escape($dbError) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
        <label for="password">Administrator password</label>
        <input type="password" id="password" name="password" required autofocus>
        <div style="margin-top:14px"><button type="submit">Unlock panel</button></div>
      </form>
      <p class="hint">Only the hash is stored, in <code>sb_settings</code>. Five wrong
      attempts a minute are tolerated, then this address is blocked for ten minutes.</p>
    </div>
    <p class="foot">PHP <?= sb_escape(PHP_VERSION) ?> · site_bridge v<?= sb_escape(SB_VERSION) ?></p>
  </div>
<?php else: ?>
  <header class="top">
    <div class="brand"><span class="dot"></span> site_bridge</div>
    <span class="pill">tokens <b><?= (int) $stats['active'] ?></b> active</span>
    <span class="pill">requests today <b><?= (int) $stats['requests_today'] ?></b></span>
    <span class="pill">last seen <b><?= $stats['last_seen'] ? sb_escape($stats['last_seen']) : 'never' ?></b></span>
    <div class="spacer"></div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
      <button class="ghost small" type="submit">Sign out</button>
    </form>
  </header>

  <?php foreach ($flashes as $item): ?>
    <div class="flash <?= sb_escape($item['kind']) ?>"><?= sb_escape($item['text']) ?></div>
  <?php endforeach; ?>

  <?php if ($newToken): ?>
    <div class="card" style="border-color:rgba(94,234,212,.45)">
      <h2>New token — copy it now</h2>
      <div class="token-reveal">
        <div class="token-value" id="new-token"><?= sb_escape($newToken['token']) ?></div>
        <div class="row" style="align-items:center">
          <div class="fixed"><button class="small" data-copy="#new-token" type="button">Copy token</button></div>
          <div class="fixed"><button class="ghost small" data-copy="#new-url" type="button">Copy test URL</button></div>
          <div class="hint" style="margin:0">
            <?= $newToken['label'] !== '' ? sb_escape($newToken['label']) . ' · ' : '' ?>
            <?= $newToken['expires'] ? 'expires ' . sb_escape($newToken['expires']) : 'never expires' ?>
          </div>
        </div>
        <div class="token-value" id="new-url" style="color:var(--accent-2)"><?=
          sb_escape($bridgeUrl . '?t=' . $newToken['token'] . '&op=help') ?></div>
      </div>
      <p class="hint">Send the agent a line like:
        <code>fetch &lt;that URL&gt; and follow it</code> — the token is the password,
        the URL is not secret on its own.</p>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div>
      <div class="card">
        <h2>Issue a token</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
          <div class="row">
            <div>
              <label for="label">Label (who or what it is for)</label>
              <input type="text" id="label" name="label" maxlength="64" placeholder="e.g. claude — session 3">
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
        <p class="hint">One token per session, revoked when the session ends. The
        token value is shown once; only its SHA-256 hash is stored.</p>
      </div>

      <div class="card">
        <h2>Tokens</h2>
        <?php if (!$tokens): ?>
          <p class="hint">No tokens yet. Create one above, then hand it to the agent.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Token</th><th>Label</th><th>State</th><th>Last use</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $token):
              $revoked = !empty($token['revoked_at']);
              $expired = !empty($token['expires_at']) && strtotime((string) $token['expires_at']) <= time();
              $state = $revoked ? 'revoked' : ($expired ? 'expired' : 'active');
              $stateClass = $state === 'active' ? 'ok' : ($state === 'expired' ? 'warn' : 'err');
          ?>
            <tr>
              <td class="mono"><?= sb_escape((string) $token['hint']) ?></td>
              <td><?= $token['label'] !== '' ? sb_escape((string) $token['label']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td>
                <span class="badge <?= $stateClass ?>"><?= $state ?></span>
                <div class="feed-time">
                  <?= $token['expires_at'] ? 'until ' . sb_escape((string) $token['expires_at']) : 'no expiry' ?>
                </div>
              </td>
              <td class="mono">
                <?= $token['last_used_at'] ? sb_escape((string) $token['last_used_at']) : '—' ?>
                <div class="feed-time"><?= (int) $token['uses'] ?> uses</div>
              </td>
              <td style="text-align:right;white-space:nowrap">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $token['id'] ?>">
                  <input type="hidden" name="action" value="<?= $revoked ? 'restore' : 'revoke' ?>">
                  <button class="ghost small" type="submit"><?= $revoked ? 'restore' : 'revoke' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this token row?')">
                  <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $token['id'] ?>">
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
        <h2>Bridge</h2>
        <div class="kv"><span>endpoint</span><span><?= sb_escape($bridgeUrl) ?></span></div>
        <div class="kv"><span>file root the agent may touch</span><span><?= sb_escape((string) sb_setting('root', __DIR__)) ?></span></div>
        <div class="kv"><span>PHP snippets</span><span><?= $allowPhp ? 'enabled' : 'disabled' ?></span></div>
        <div class="kv"><span>protocol card</span><span><a href="bridge.php?op=help">needs a token</a></span></div>
        <details style="margin-top:12px">
          <summary>Change the admin password</summary>
          <form method="post" autocomplete="off" style="margin-top:10px">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
            <label for="current">Current password</label>
            <input type="password" id="current" name="current" required>
            <label for="next">New password (10+ characters)</label>
            <input type="password" id="next" name="next" required minlength="10">
            <label for="again">Repeat new password</label>
            <input type="password" id="again" name="again" required minlength="10">
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
              <option value="ls">ls</option>
              <option value="read">read</option>
              <option value="write">write</option>
              <option value="edit">edit</option>
              <option value="rm">rm</option>
              <option value="sql">sql</option>
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
            <p class="hint" id="feed-empty">Nothing yet. The first request an agent makes shows up here within a couple of seconds.</p>
          <?php endif; ?>
          <?php foreach ($activity as $row): ?>
            <div class="feed-row">
              <div class="feed-time"><?= sb_escape(substr((string) $row['ts'], 11)) ?></div>
              <div class="feed-op"><?= sb_escape((string) $row['op']) ?></div>
              <div class="feed-sum" title="<?= sb_escape((string) $row['summary']) ?>"><?= sb_escape((string) $row['summary']) ?></div>
              <div class="feed-status"><span class="badge b-<?= sb_escape((string) $row['status']) ?>"><?= sb_escape((string) $row['status']) ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="hint">Polls every 2.5 s and stops while the tab is hidden.
          <a href="#" id="clear-log">clear log</a> keeps the table, not the history.</p>
        <form method="post" id="clear-form" style="display:none">
          <input type="hidden" name="action" value="clear_log">
          <input type="hidden" name="csrf" value="<?= sb_escape(csrf()) ?>">
        </form>
      </div>

      <div class="card">
        <h2>What the agent can do</h2>
        <div class="kv"><span>files</span><span>ls read write edit mkdir rm upload</span></div>
        <div class="kv"><span>database</span><span>sql (SELECT and writes)</span></div>
        <div class="kv"><span>code</span><span>php <?= $allowPhp ? 'snippets + ticked jobs' : 'disabled' ?></span></div>
        <div class="kv"><span>shell</span><span>not available on shared hosting</span></div>
        <p class="hint">Each fetch is one request, capped near 10 s by the host.
        Longer work runs as ticks driven by the agent's own polls.</p>
      </div>
    </div>
  </div>
  <p class="foot">site_bridge v<?= sb_escape(SB_VERSION) ?> · PHP <?= sb_escape(PHP_VERSION) ?> ·
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

  function rowHtml(r) {
    var age = r.age < 60 ? r.age + 's' : (r.age < 3600 ? Math.floor(r.age / 60) + 'm' : Math.floor(r.age / 3600) + 'h');
    return '<div class="feed-time">' + age + '</div>'
      + '<div class="feed-op">' + esc(r.op) + '</div>'
      + '<div class="feed-sum" title="' + esc(r.summary) + '">' + esc(r.summary || '') + '</div>'
      + '<div class="feed-status"><span class="badge b-' + esc(r.status) + '">' + esc(r.status) + '</span></div>';
  }
  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
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
      .catch(function () {
        pill.style.color = 'var(--err)';
      });
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
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.querySelector(button.getAttribute('data-copy'));
      if (!target) { return; }
      var text = target.textContent;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      } else {
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
      }
      var was = button.textContent;
      button.textContent = 'copied';
      setTimeout(function () { button.textContent = was; }, 1400);
    });
  });
})();
</script>
<?php endif; ?>
</div>
</body>
</html>
