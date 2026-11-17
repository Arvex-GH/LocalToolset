<?php
/**
 * bridge.php — the endpoint the AI fetches.
 *
 *   GET bridge.php?t=<token>&op=<op>&<params>
 *   GET bridge.php/x/<token>/<op>
 *
 * No database. Passwords, tokens and the activity log live in bridge_state.json,
 * which setup.php writes on first run. Bridge requests append their activity
 * there, which is what the panel (index.php) shows live.
 *
 * Shared hosting means: no shell, and no request longer than ~10 seconds, so
 * there is no `shell` op (the host disables exec), `php` runs a snippet, and long
 * work is split into ticks (op=php&detach=1, then op=job&id=...). See op=help.
 *
 * PHP 7.4+; self-contained on purpose, so a broken upload can never take two
 * files down at once.
 */

declare(strict_types=1);

const BRIDGE_VERSION = '1';
const BRIDGE_DEFAULT_LEN = 16384;
const BRIDGE_MAX_LEN = 32768;
const BRIDGE_MAX_CODE = 262144;
const BRIDGE_MAX_TEXT = 1048576;
const BRIDGE_MAX_READ = 8388608;
const BRIDGE_WRITES_PER_MINUTE = 120;
const BRIDGE_AUTH_FAILURES_PER_MINUTE = 20;
const BRIDGE_TICK_SECONDS = 5.0;
const BRIDGE_ACTIVITY_MAX = 400;

$GLOBALS['bridge_responded'] = false;
$GLOBALS['bridge_started'] = microtime(true);
$GLOBALS['bridge_context'] = array('token' => '', 'op' => '-', 'summary' => '');
$GLOBALS['bridge_state'] = null;

/* ------------------------------------------------------------------- state */

/** bridge_state.json above the web root when possible, beside the files otherwise. */
function bridge_state_path(): string
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

function bridge_state_load(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $empty = array('password_hash' => '', 'password_plain' => '',
                   'settings' => array(), 'tokens' => array(), 'activity' => array());
    $path = bridge_state_path();
    if (!is_file($path)) {
        return $cache = $empty;
    }
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        return $cache = $empty + array('corrupt' => true);
    }
    $data += $empty;
    if (!is_array($data['settings'])) {
        $data['settings'] = array();
    }
    if (!is_array($data['tokens'])) {
        $data['tokens'] = array();
    }
    if (!is_array($data['activity'])) {
        $data['activity'] = array();
    }
    return $cache = $data;
}

function bridge_state_save(array $state): bool
{
    $path = bridge_state_path();
    $trimmed = $state;
    if (!isset($trimmed['settings']) || !is_array($trimmed['settings'])) {
        $trimmed['settings'] = array();
    }
    if (count($trimmed['activity']) > BRIDGE_ACTIVITY_MAX) {
        $trimmed['activity'] = array_slice($trimmed['activity'], -BRIDGE_ACTIVITY_MAX);
    }
    $encoded = json_encode($trimmed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($encoded === false) {
        return false;
    }
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
    $GLOBALS['bridge_state'] = $trimmed;
    return $ok;
}

function bridge_installed(): bool
{
    $state = bridge_state_load();
    return $state['password_hash'] !== '' || $state['password_plain'] !== '';
}

function bridge_new_token(): string
{
    return 'sb_' . bin2hex(random_bytes(24));
}

function bridge_ip(): string
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

function bridge_clip(string $text, int $limit): string
{
    $text = str_replace(array("\r", "\n", "\t"), ' ', $text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return substr($text, 0, $limit);
}

/**
 * Settings live in the same JSON as everything else, so the panel can flip
 * allow_php or narrow the file root without anyone editing a file.
 */
function bridge_setting(string $name, $default = null)
{
    $state = $GLOBALS['bridge_state'] !== null ? $GLOBALS['bridge_state'] : bridge_state_load();
    $settings = isset($state['settings']) && is_array($state['settings']) ? $state['settings'] : array();
    if (!array_key_exists($name, $settings)) {
        return $default;
    }
    $value = $settings[$name];
    if ($value === '' || $value === null) {
        return $default;
    }
    return $value;
}

/* ------------------------------------------------------------------ output */

function bridge_elapsed(): float
{
    return round(microtime(true) - $GLOBALS['bridge_started'], 3);
}

function bridge_summary(string $text): void
{
    $GLOBALS['bridge_context']['summary'] = $text;
}

function bridge_headers(string $type): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function bridge_params_summary(): string
{
    $parts = array();
    foreach (array('path', 'id', 'tool') as $name) {
        if (!empty($_GET[$name]) && is_string($_GET[$name])) {
            $parts[] = $name . '=' . bridge_clip((string) $_GET[$name], 80);
        }
    }
    if (!empty($_GET['code']) && is_string($_GET['code'])) {
        $parts[] = 'code=' . bridge_clip((string) $_GET['code'], 120);
    }
    if (!empty($_GET['text']) && is_string($_GET['text'])) {
        $parts[] = 'text=' . strlen((string) $_GET['text']) . ' bytes';
    }
    if (!empty($_FILES['file']['name'])) {
        $parts[] = 'file=' . bridge_clip((string) $_FILES['file']['name'], 60);
    }
    return $parts ? implode(' ', $parts) : '';
}

/** Append one activity row to the state file; keeps the newest BRIDGE_ACTIVITY_MAX. */
function bridge_record(string $op, string $status, int $bytes, string $summary = ''): void
{
    $state = $GLOBALS['bridge_state'] !== null ? $GLOBALS['bridge_state'] : bridge_state_load();
    $activity = $state['activity'];
    $lastId = 0;
    if ($activity) {
        $tail = $activity[count($activity) - 1];
        $lastId = isset($tail['id']) ? (int) $tail['id'] : 0;
    }
    $activity[] = array(
        'id' => $lastId + 1,
        'ts' => date('Y-m-d H:i:s'),
        'op' => $op,
        'summary' => $summary === '' ? bridge_params_summary() : $summary,
        'status' => $status,
        'ms' => (int) round(bridge_elapsed() * 1000),
        'bytes' => $bytes,
        'ip' => bridge_ip(),
        'token' => $GLOBALS['bridge_context']['token'],
    );
    $state['activity'] = $activity;
    bridge_state_save($state);
}

/**
 * Emit the envelope, record the request, and stop.
 *
 * $fields are extra header tokens (name => value). The body is paged by
 * offset=/len= so a big listing never needs more than one fetch.
 */
function bridge_respond(
    int $code,
    string $status,
    string $op,
    string $id,
    array $fields,
    string $body,
    string $note = ''
): void {
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    $len = isset($_GET['len']) ? (int) $_GET['len'] : BRIDGE_DEFAULT_LEN;
    $len = max(1, min($len, BRIDGE_MAX_LEN));

    $raw = (string) $body;
    $total = strlen($raw);
    if ($offset > $total) {
        $offset = $total;
    }
    $chunk = substr($raw, $offset, $len);
    $hasMore = ($offset + strlen($chunk)) < $total;
    $next = $hasMore ? $offset + strlen($chunk) : '-';

    $header = array('BRIDGE v' . BRIDGE_VERSION, 'status=' . $status, 'id=' . $id, 'op=' . $op);
    foreach ($fields as $name => $value) {
        $header[] = $name . '=' . $value;
    }
    $header[] = 'secs=' . bridge_elapsed();
    $header[] = 'offset=' . $offset;
    $header[] = 'total=' . $total;
    $header[] = 'shown=' . strlen($chunk);
    $header[] = 'has_more=' . ($hasMore ? '1' : '0');
    $header[] = 'next_offset=' . $next;

    $lines = array(implode(' ', $header));
    if ($hasMore) {
        $lines[] = 'note: more output is available; refetch offset=' . $next;
    }
    if ($note !== '') {
        $lines[] = 'note: ' . $note;
    }

    bridge_record($op, $status, strlen($chunk));

    if (isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json') {
        bridge_headers('application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode(array(
            'version' => BRIDGE_VERSION,
            'status' => $status,
            'id' => $id,
            'op' => $op,
            'fields' => $fields,
            'body' => $chunk,
            'offset' => $offset,
            'total_bytes' => $total,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $next : null,
            'note' => $note,
            'secs' => bridge_elapsed(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } else {
        bridge_headers('text/plain; charset=utf-8');
        http_response_code($code);
        echo implode("\n", $lines) . "\n\n" . $chunk;
    }
    $GLOBALS['bridge_responded'] = true;
    exit;
}

function bridge_fail(string $op, string $message, int $code = 200, string $id = '-'): void
{
    bridge_respond($code, 'failed', $op, $id, array(), 'error: ' . $message);
}

function bridge_id(): string
{
    return bin2hex(random_bytes(6));
}

/* -------------------------------------------------------------- parameters */

function bridge_route(): array
{
    $token = '';
    $op = '';
    if (isset($_GET['t']) && is_string($_GET['t'])) {
        $token = (string) $_GET['t'];
    } elseif (isset($_GET['token']) && is_string($_GET['token'])) {
        $token = (string) $_GET['token'];
    }
    if (isset($_GET['op']) && is_string($_GET['op'])) {
        $op = (string) $_GET['op'];
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($uri, PHP_URL_PATH);
    if (is_string($path) && preg_match('#/x/([A-Za-z0-9._~-]{16,200})/([a-z]+)#', $path, $match)) {
        if ($token === '') {
            $token = $match[1];
        }
        if ($op === '') {
            $op = $match[2];
        }
    }
    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $auth = (string) $headers['Authorization'];
        }
    }
    if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $match)) {
        $token = $match[1];
    }
    return array($token, strtolower($op));
}

function bridge_param(string $name, string $default = ''): string
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    if (!is_string($_GET[$name])) {
        bridge_fail('params', 'parameter "' . $name . '" must be a single value');
    }
    return (string) $_GET[$name];
}

/* ------------------------------------------------------------------- paths */

function bridge_root(): string
{
    $root = bridge_setting('root', __DIR__);
    $real = realpath((string) $root);
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('configured root does not exist: ' . $root);
    }
    return rtrim($real, DIRECTORY_SEPARATOR);
}

function bridge_resolve(string $relative, bool $mustExist = false): string
{
    $root = bridge_root();
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '') {
        $relative = '.';
    }
    if ($relative[0] === '/') {
        bridge_fail('path', 'paths are relative to the root: ' . $root);
    }
    $candidate = $root . '/' . $relative;
    if (file_exists($candidate)) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            bridge_fail('path', 'cannot resolve: ' . $relative);
        }
    } else {
        if ($mustExist) {
            bridge_fail('path', 'no such file or directory: ' . $relative);
        }
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            bridge_fail('path', 'parent directory does not exist: ' . dirname($relative));
        }
        $resolved = $parent . '/' . basename($candidate);
    }
    $prefix = $root . DIRECTORY_SEPARATOR;
    if ($resolved !== $root && strpos($resolved . DIRECTORY_SEPARATOR, $prefix) !== 0) {
        bridge_fail('path', 'path escapes the root: ' . $relative);
    }
    return $resolved;
}

/* ---------------------------------------------------------------------- ops */

function bridge_op_ping(): void
{
    bridge_summary('liveness check');
    bridge_respond(200, 'completed', 'ping', '-', array('php' => PHP_VERSION),
        "pong\nsite_bridge v" . BRIDGE_VERSION . ' on PHP ' . PHP_VERSION . "\n");
}

function bridge_op_help(): void
{
    bridge_summary('protocol card');
    bridge_respond(200, 'completed', 'help', '-', array(), bridge_help() . "\n");
}

function bridge_help(): string
{
    $root = bridge_root();
    $phpAllowed = bridge_setting('allow_php', false) ? 'enabled' : 'disabled (toggle it in the panel)';
    return <<<TXT
BRIDGE v1 — site bridge (PHP on shared hosting)
You are talking to a PHP file on the user's website. Each GET runs there, with
the web server's privileges; the result comes back in this response. There is no
queue and no daemon: your fetch IS the request.

Address
  GET <this file>?t=<token>&op=<op>&<params>
  Also accepted: <path>/x/<token>/<op>, and Authorization: Bearer <token>.
  The token is one the user issued in the panel; when it expires or is revoked
  every op answers 401 and the panel shows why.
  format=json returns this same envelope as one JSON object instead of text.

One request is short
  The host kills a request after about 10 seconds, with no cron and no background
  process. Split slower work into ticks:
    op=php&detach=1&code=...   stores a job and runs the first ~5 s tick
    op=job&id=JOBID            runs the next tick, returns output so far
  Your snippet restarts from the top on every tick, so keep progress in
  \$BRIDGE_STATE and finish each tick quickly:

    \$BRIDGE_STATE['done'] = (int) (\$BRIDGE_STATE['done'] ?? 0);
    while (\$BRIDGE_STATE['done'] < 200 && bridge_tick_left() > 0.5) {
        \$BRIDGE_STATE['done']++;
        echo "item {\$BRIDGE_STATE['done']}\n";
    }
    \$BRIDGE_DONE = \$BRIDGE_STATE['done'] >= 200;

File root
  {$root}
  Paths are relative to it and must resolve inside it.

Ops
  ping                                  liveness, PHP version
  help                                  this card
  selftest                              what this host allows, and state file health
  ls     path=. [count=100]
  read   path=... [offset= len=]
  write  path=... text=... [mode=create|overwrite|append]
  edit   path=... old=... new=...       (old must match exactly once)
  mkdir  path=...                       (single level; parent must exist)
  rm     path=... [recursive=1]
  php    code=... [detach=1]            run a snippet ({$phpAllowed})
  upload path=... file=<multipart>      write an uploaded file
  jobs   [count=20]                     recent jobs
  job    id=... [offset= len=]          run/continue a tick, page its output
  cancel id=...                         drop a job

There is no sql op: this bridge has no database of its own. If the site needs
queries, run them through op=php with mysqli and credentials you put in a file
the agent can read.

Every op is recorded in bridge_state.json and appears live in the user's panel.
Do not work around a 401 or 429: report it.

Escaping
  Values are query components: %20 space, %25 %, %26 &, %3D =, %3F ?, %23 #,
  %0A newline, %22 double quote. Keep URLs under 6000 characters; for larger
  writes use mode=append in chunks. Code over 256 KB is refused.
TXT;
}

function bridge_op_selftest(): void
{
    $state = bridge_state_load();
    $path = bridge_state_path();
    $lines = array();
    $lines[] = 'site_bridge=' . BRIDGE_VERSION;
    $lines[] = 'php=' . PHP_VERSION . ' ' . PHP_SAPI;
    $lines[] = 'extensions=' . implode(',', array_values(array_filter(
        array('json', 'mbstring', 'openssl', 'curl', 'zip'),
        function ($name) {
            return extension_loaded($name);
        }
    )));
    $lines[] = 'max_execution_time=' . ini_get('max_execution_time');
    $lines[] = 'memory_limit=' . ini_get('memory_limit');
    $lines[] = 'upload_max_filesize=' . ini_get('upload_max_filesize');
    $lines[] = 'shell_exec=' . (function_exists('shell_exec') ? 'present' : 'disabled by host');
    $lines[] = 'root=' . bridge_root() . ' writable=' . (is_writable(bridge_root()) ? 'yes' : 'no');
    $lines[] = 'state_file=' . $path;
    $lines[] = 'state_outside_webroot=' . (
        dirname($path) !== __DIR__ ? 'yes' : 'no (beside bridge.php; .htaccess must protect it)'
    );
    $lines[] = 'state_writable=' . (is_writable(dirname($path)) || is_writable($path) ? 'yes' : 'no');
    $lines[] = 'setup_done=' . (bridge_installed() ? 'yes' : 'no — open setup.php');
    $lines[] = 'tokens=' . count($state['tokens']);
    $lines[] = 'activity_rows=' . count($state['activity']);
    $lines[] = 'allow_php=' . (bridge_setting('allow_php', false) ? 'yes' : 'no');
    $lines[] = 'tick_seconds=' . BRIDGE_TICK_SECONDS;
    bridge_respond(200, 'completed', 'selftest', '-', array(), implode("\n", $lines) . "\n");
}

function bridge_op_ls(): void
{
    $path = bridge_param('path', '.');
    $count = max(1, min((int) bridge_param('count', '100'), 500));
    $target = bridge_resolve($path, true);
    if (!is_dir($target)) {
        bridge_fail('ls', 'not a directory: ' . $path);
    }
    $names = @scandir($target);
    if ($names === false) {
        bridge_fail('ls', 'cannot read directory: ' . $path);
    }
    $entries = array();
    $total = 0;
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $total++;
        if (count($entries) >= $count) {
            continue;
        }
        $full = $target . '/' . $name;
        $kind = is_dir($full) ? 'dir ' : (is_link($full) ? 'link' : 'file');
        $size = is_dir($full) ? '' : sprintf('%10d', (int) @filesize($full));
        $entries[] = sprintf('%-4s %s  %s', $kind, $size, $name);
    }
    $body = implode("\n", $entries);
    if ($total > count($entries)) {
        $body .= "\n... " . ($total - count($entries)) . ' more entries (raise count= up to 500)';
    }
    if (trim($body) === '') {
        $body = '(empty directory)';
    }
    bridge_summary('ls ' . $path . ' (' . $total . ' entries)');
    bridge_respond(200, 'completed', 'ls', '-', array(), $body . "\n");
}

function bridge_op_read(): void
{
    $path = bridge_param('path');
    if ($path === '') {
        bridge_fail('read', 'read requires path=');
    }
    $target = bridge_resolve($path, true);
    if (is_dir($target)) {
        bridge_fail('read', 'is a directory; use op=ls: ' . $path);
    }
    $size = (int) @filesize($target);
    if ($size > BRIDGE_MAX_READ) {
        bridge_fail('read', 'file is ' . $size . ' bytes; read it in parts with offset=/len=');
    }
    $data = @file_get_contents($target);
    if ($data === false) {
        bridge_fail('read', 'cannot open: ' . $path);
    }
    bridge_summary('read ' . $path . ' (' . $size . ' bytes)');
    bridge_respond(200, 'completed', 'read', '-', array('file_bytes' => $size), $data);
}

function bridge_op_write(): void
{
    $path = bridge_param('path');
    if ($path === '') {
        bridge_fail('write', 'write requires path=');
    }
    $text = bridge_param('text');
    if ($text === '' && isset($_POST['text']) && is_string($_POST['text'])) {
        $text = (string) $_POST['text'];
    }
    if (strlen($text) > BRIDGE_MAX_TEXT) {
        bridge_fail('write', 'text exceeds ' . BRIDGE_MAX_TEXT . ' bytes; use mode=append');
    }
    $mode = strtolower(bridge_param('mode', 'create'));
    if (!in_array($mode, array('create', 'overwrite', 'append'), true)) {
        bridge_fail('write', 'mode must be create, overwrite or append');
    }
    $target = bridge_resolve($path, false);
    $exists = file_exists($target);
    if ($mode === 'create' && $exists) {
        bridge_fail('write', 'file already exists; use mode=overwrite: ' . $path);
    }
    if ($exists && is_dir($target)) {
        bridge_fail('write', 'is a directory: ' . $path);
    }
    $written = @file_put_contents($target, $text, $mode === 'append' ? FILE_APPEND : 0);
    if ($written === false) {
        bridge_fail('write', 'cannot write: ' . $path);
    }
    bridge_summary('write ' . $path . ' mode=' . $mode);
    bridge_respond(200, 'completed', 'write', '-', array('file_bytes' => (int) @filesize($target)),
        'wrote ' . $written . ' bytes to ' . $path . ' (mode=' . $mode . ")\n");
}

function bridge_op_edit(): void
{
    $path = bridge_param('path');
    $old = bridge_param('old');
    $new = bridge_param('new');
    if ($path === '' || $old === '') {
        bridge_fail('edit', 'edit requires path= and old=');
    }
    $target = bridge_resolve($path, true);
    $content = @file_get_contents($target);
    if ($content === false) {
        bridge_fail('edit', 'cannot read: ' . $path);
    }
    $occurrences = substr_count($content, $old);
    if ($occurrences !== 1) {
        bridge_fail('edit', 'old must match exactly once; found ' . $occurrences);
    }
    if (@file_put_contents($target, str_replace($old, $new, $content)) === false) {
        bridge_fail('edit', 'cannot write: ' . $path);
    }
    bridge_summary('edit ' . $path);
    bridge_respond(200, 'completed', 'edit', '-', array(), 'replaced 1 occurrence in ' . $path . "\n");
}

function bridge_op_mkdir(): void
{
    $path = bridge_param('path');
    if ($path === '') {
        bridge_fail('mkdir', 'mkdir requires path=');
    }
    $target = bridge_resolve($path, false);
    if (file_exists($target)) {
        bridge_fail('mkdir', 'already exists: ' . $path);
    }
    if (!@mkdir($target, 0755)) {
        bridge_fail('mkdir', 'cannot create: ' . $path);
    }
    bridge_summary('mkdir ' . $path);
    bridge_respond(200, 'completed', 'mkdir', '-', array(), 'created ' . $path . "\n");
}

function bridge_op_rm(): void
{
    $path = bridge_param('path');
    if ($path === '') {
        bridge_fail('rm', 'rm requires path=');
    }
    if (trim(str_replace('\\', '/', $path), './') === '') {
        bridge_fail('rm', 'refusing to delete the root');
    }
    $target = bridge_resolve($path, true);
    if (is_dir($target)) {
        if (bridge_param('recursive', '0') !== '1') {
            bridge_fail('rm', 'is a directory; add recursive=1: ' . $path);
        }
        $ok = bridge_rm_tree($target);
        bridge_summary('rm -r ' . $path);
        bridge_respond(200, $ok ? 'completed' : 'failed', 'rm', '-', array(),
            $ok ? 'removed ' . $path . "\n" : 'error: could not remove everything under ' . $path . "\n");
    }
    if (!@unlink($target)) {
        bridge_fail('rm', 'cannot delete: ' . $path);
    }
    bridge_summary('rm ' . $path);
    bridge_respond(200, 'completed', 'rm', '-', array(), 'removed ' . $path . "\n");
}

function bridge_rm_tree(string $dir): bool
{
    $ok = true;
    $names = @scandir($dir);
    if ($names === false) {
        return false;
    }
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . '/' . $name;
        if (is_dir($full) && !is_link($full)) {
            $ok = bridge_rm_tree($full) && $ok;
        } else {
            $ok = @unlink($full) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
}

function bridge_op_upload(): void
{
    $path = bridge_param('path');
    if ($path === '') {
        bridge_fail('upload', 'upload requires path=');
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        bridge_fail('upload', 'upload requires a multipart field named "file"');
    }
    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        bridge_fail('upload', 'upload error code ' . $file['error']);
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        bridge_fail('upload', 'no uploaded file arrived');
    }
    $target = bridge_resolve($path, false);
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        bridge_fail('upload', 'cannot write: ' . $path);
    }
    bridge_summary('upload ' . $path . ' (' . (int) $file['size'] . ' bytes)');
    bridge_respond(200, 'completed', 'upload', '-', array(),
        'wrote ' . (int) $file['size'] . ' bytes to ' . $path . "\n");
}

/* -------------------------------------------------------------------- jobs */

function bridge_state_dir(): string
{
    $dir = rtrim((string) bridge_setting('state_dir', dirname(bridge_state_path()) . '/bridge_jobs'), '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $guard = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

function bridge_job_path(string $id): string
{
    return bridge_state_dir() . '/job-' . $id . '.json';
}

function bridge_job_load(string $id): array
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        bridge_fail('job', 'malformed job id');
    }
    $path = bridge_job_path($id);
    if (!is_file($path)) {
        bridge_fail('job', 'no such job: ' . $id, 200, $id);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        bridge_fail('job', 'job file is unreadable: ' . $id, 200, $id);
    }
    return $data;
}

function bridge_job_save(string $id, array $job): void
{
    $job['updated'] = time();
    @file_put_contents(bridge_job_path($id), json_encode($job));
}

function bridge_job_output(string $id): string
{
    $path = bridge_state_dir() . '/job-' . $id . '.out';
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function bridge_job_append(string $id, string $text): void
{
    if ($text !== '') {
        @file_put_contents(bridge_state_dir() . '/job-' . $id . '.out', $text, FILE_APPEND);
    }
}

/**
 * Run one tick of a job. The snippet is re-executed from the top with
 * $BRIDGE_STATE loaded from the job file: PHP cannot suspend a request, so
 * resumption has to be cooperative.
 */
function bridge_job_tick(array $job, string $id): string
{
    $GLOBALS['BRIDGE_STATE'] = isset($job['state']) && is_array($job['state']) ? $job['state'] : array();
    $GLOBALS['BRIDGE_DONE'] = false;
    $GLOBALS['BRIDGE_TICK_DEADLINE'] = microtime(true) + BRIDGE_TICK_SECONDS;
    $output = '';
    $error = '';
    ob_start();
    $level = ob_get_level();
    // The snippet names these two directly, so they must be references to the
    // globals; a local copy would swallow every bit of progress.
    $BRIDGE_STATE = &$GLOBALS['BRIDGE_STATE'];
    $BRIDGE_DONE = &$GLOBALS['BRIDGE_DONE'];
    try {
        eval((string) (isset($job['code']) ? $job['code'] : ''));
    } catch (Throwable $exc) {
        $error = get_class($exc) . ': ' . $exc->getMessage();
    }
    while (ob_get_level() > $level) {
        $output .= (string) ob_get_clean();
    }
    if ($error !== '') {
        $status = 'failed';
        $output .= 'error: ' . $error . "\n";
    } elseif (!empty($GLOBALS['BRIDGE_DONE'])) {
        $status = 'completed';
    } else {
        $status = 'running';
    }
    $job['state'] = is_array($GLOBALS['BRIDGE_STATE']) ? $GLOBALS['BRIDGE_STATE'] : array();
    $job['ticks'] = (int) (isset($job['ticks']) ? $job['ticks'] : 0) + 1;
    $job['status'] = $status;
    bridge_job_append($id, $output);
    bridge_job_save($id, $job);
    return $status;
}

function bridge_op_php(): void
{
    if (!bridge_setting('allow_php', false)) {
        bridge_fail('php', 'php snippets are disabled; the user can enable them in the panel');
    }
    $code = bridge_param('code');
    if ($code === '') {
        $file = bridge_param('code_file');
        if ($file === '') {
            bridge_fail('php', 'php requires code= or code_file=');
        }
        $contents = @file_get_contents(bridge_resolve($file, true));
        if ($contents === false) {
            bridge_fail('php', 'cannot read code_file: ' . $file);
        }
        $code = $contents;
    }
    if (strlen($code) > BRIDGE_MAX_CODE) {
        bridge_fail('php', 'code exceeds ' . BRIDGE_MAX_CODE . ' bytes');
    }

    if (bridge_param('detach', '0') !== '1') {
        $GLOBALS['BRIDGE_STATE'] = array();
        $GLOBALS['BRIDGE_DONE'] = false;
        $GLOBALS['BRIDGE_TICK_DEADLINE'] = microtime(true) + BRIDGE_TICK_SECONDS + 2.0;
        $output = '';
        $error = '';
        ob_start();
        $level = ob_get_level();
        $BRIDGE_STATE = &$GLOBALS['BRIDGE_STATE'];
        $BRIDGE_DONE = &$GLOBALS['BRIDGE_DONE'];
        try {
            eval($code);
        } catch (Throwable $exc) {
            $error = get_class($exc) . ': ' . $exc->getMessage();
        }
        while (ob_get_level() > $level) {
            $output .= (string) ob_get_clean();
        }
        if ($error !== '') {
            bridge_summary('php snippet failed');
            bridge_respond(200, 'failed', 'php', '-', array(),
                $output . 'error: ' . $error . "\n");
        }
        bridge_summary('php snippet (' . strlen($code) . ' bytes)');
        bridge_respond(200, 'completed', 'php', '-', array(), $output,
            empty($GLOBALS['BRIDGE_DONE']) ? 'snippet did not set $BRIDGE_DONE; use detach=1 for multi-tick work' : '');
    }

    $id = bridge_id();
    $job = array('id' => $id, 'op' => 'php', 'code' => $code, 'state' => array(),
                 'status' => 'running', 'ticks' => 0, 'created' => time());
    bridge_job_save($id, $job);
    $status = bridge_job_tick($job, $id);
    bridge_summary('php job ' . $id . ' (' . strlen($code) . ' bytes)');
    bridge_respond(200, $status === 'failed' ? 'failed' : 'queued', 'php', $id,
        array('ticks' => 1), bridge_job_output($id),
        $status === 'running' ? 'job ' . $id . ' continues; poll op=job&id=' . $id
                              : 'job finished in one tick');
}

function bridge_op_job(): void
{
    $id = bridge_param('id');
    if ($id === '') {
        bridge_fail('job', 'job requires id=');
    }
    $job = bridge_job_load($id);
    $status = isset($job['status']) ? (string) $job['status'] : 'running';
    $note = '';
    if ($status === 'running') {
        $lockPath = bridge_state_dir() . '/job-' . $id . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock !== false) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $job = bridge_job_load($id);
                if (isset($job['status']) && $job['status'] === 'running') {
                    $status = bridge_job_tick($job, $id);
                    $job = bridge_job_load($id);
                    $note = $status === 'running'
                        ? 'tick done; poll again with offset=' . strlen(bridge_job_output($id))
                        : 'job ' . $status;
                }
                flock($lock, LOCK_UN);
            } else {
                $note = 'another request is already running this tick';
            }
            fclose($lock);
        }
    }
    bridge_summary('job ' . $id . ' tick');
    bridge_respond(200, $status, 'job', $id,
        array('ticks' => (int) (isset($job['ticks']) ? $job['ticks'] : 0)),
        bridge_job_output($id), $note);
}

function bridge_op_jobs(): void
{
    $paths = glob(bridge_state_dir() . '/job-*.json');
    if ($paths === false) {
        $paths = array();
    }
    usort($paths, function ($a, $b) {
        return @filemtime($b) <=> @filemtime($a);
    });
    $count = max(1, min((int) bridge_param('count', '20'), 100));
    $lines = array();
    foreach (array_slice($paths, 0, $count) as $path) {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            continue;
        }
        $id = isset($data['id']) ? (string) $data['id'] : '';
        $lines[] = sprintf('%s %-9s %2d ticks %8d bytes  %s', $id,
            isset($data['status']) ? $data['status'] : '?',
            isset($data['ticks']) ? (int) $data['ticks'] : 0,
            strlen(bridge_job_output($id)),
            isset($data['created']) ? date('Y-m-d H:i:s', (int) $data['created']) : '?');
    }
    bridge_summary('listed jobs');
    bridge_respond(200, 'completed', 'jobs', '-', array(),
        ($lines ? implode("\n", $lines) . "\n" : "no jobs yet\n"));
}

function bridge_op_cancel(): void
{
    $id = bridge_param('id');
    if ($id === '') {
        bridge_fail('cancel', 'cancel requires id=');
    }
    $job = bridge_job_load($id);
    $job['status'] = 'cancelled';
    bridge_job_save($id, $job);
    bridge_summary('cancelled job ' . $id);
    bridge_respond(200, 'completed', 'cancel', $id, array(), 'cancelled ' . $id . "\n");
}

/* -------------------------------------------------------------------- boot */

/** Call from a long snippet: seconds left in this tick. */
function bridge_tick_left(): float
{
    $deadline = isset($GLOBALS['BRIDGE_TICK_DEADLINE']) ? (float) $GLOBALS['BRIDGE_TICK_DEADLINE'] : 0.0;
    return $deadline > 0.0 ? $deadline - microtime(true) : 0.0;
}

function bridge_recent_failures(array $state): int
{
    $ip = bridge_ip();
    $cutoff = time() - 60;
    $count = 0;
    foreach ($state['activity'] as $row) {
        if (($row['status'] ?? '') !== 'unauthorized') {
            continue;
        }
        if (($row['ip'] ?? '') !== $ip) {
            continue;
        }
        $stamp = isset($row['ts']) ? strtotime((string) $row['ts']) : 0;
        if ($stamp >= $cutoff) {
            $count++;
        }
    }
    return $count;
}

function bridge_recent_writes(array $state, string $token): int
{
    $cutoff = time() - 60;
    $writes = array('write', 'edit', 'mkdir', 'rm', 'upload', 'php', 'cancel');
    $count = 0;
    foreach ($state['activity'] as $row) {
        if (!in_array($row['op'] ?? '', $writes, true)) {
            continue;
        }
        if (($row['token'] ?? '') !== $token) {
            continue;
        }
        $stamp = isset($row['ts']) ? strtotime((string) $row['ts']) : 0;
        if ($stamp >= $cutoff) {
            $count++;
        }
    }
    return $count;
}

function bridge_boot(): void
{
    register_shutdown_function(function () {
        if ($GLOBALS['bridge_responded']) {
            return;
        }
        $error = error_get_last();
        if (!$error || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            return;
        }
        bridge_headers('text/plain; charset=utf-8');
        echo 'BRIDGE v' . BRIDGE_VERSION . " status=failed id=- op=-\n\n"
            . 'error: fatal: ' . $error['message'] . ' in ' . basename($error['file'])
            . ':' . $error['line'] . "\n"
            . "note: the request died on the server; check the panel's activity log\n";
    });

    @set_time_limit(20);

    if (is_file(__DIR__ . '/bridge.disabled')) {
        bridge_respond(503, 'failed', '-', '-', array(),
            'error: disabled; delete bridge.disabled to re-enable this bridge');
    }

    $state = bridge_state_load();
    $GLOBALS['bridge_state'] = $state;
    if (!bridge_installed()) {
        bridge_respond(503, 'failed', '-', '-', array(),
            'error: not set up yet. Open setup.php, choose the admin password, then retry.');
    }

    list($token, $op) = bridge_route();
    $GLOBALS['bridge_context']['op'] = $op === '' ? '-' : $op;

    $record = null;
    foreach ($state['tokens'] as $candidate) {
        if (isset($candidate['token']) && is_string($candidate['token'])
                && hash_equals($candidate['token'], $token)) {
            $record = $candidate;
            break;
        }
    }
    $usable = false;
    if ($record !== null) {
        $expired = !empty($record['expires']) && (int) $record['expires'] <= time();
        $usable = empty($record['revoked']) && !$expired;
    }
    if (!$usable) {
        $failures = bridge_recent_failures($state);
        bridge_record('auth', 'unauthorized', 0, 'token rejected from ' . bridge_ip());
        if ($failures >= BRIDGE_AUTH_FAILURES_PER_MINUTE) {
            bridge_respond(429, 'failed', $op === '' ? '-' : $op, '-', array(),
                'error: too many rejected tokens from this address; wait a minute.');
        }
        bridge_respond(401, 'failed', $op === '' ? '-' : $op, '-', array(),
            'error: unauthorized. This token is unknown, expired or revoked. '
            . 'The user issues tokens in the panel (index.php); ask them for a fresh one.');
    }

    $label = isset($record['label']) ? (string) $record['label'] : '';
    $GLOBALS['bridge_context']['token'] = $label !== '' ? $label : substr((string) $record['token'], 0, 11);

    if ($op === '') {
        bridge_fail('-', 'missing op=; try op=help');
    }

    $readOnly = in_array($op, array('ping', 'help', 'selftest', 'ls', 'read', 'jobs', 'job'), true);
    if (!$readOnly && bridge_recent_writes($state, $GLOBALS['bridge_context']['token']) >= BRIDGE_WRITES_PER_MINUTE) {
        bridge_respond(429, 'failed', $op, '-', array(),
            'error: more than ' . BRIDGE_WRITES_PER_MINUTE . ' writes in a minute for this token.');
    }

    switch ($op) {
        case 'ping':   bridge_op_ping();   break;
        case 'help':   bridge_op_help();   break;
        case 'selftest': bridge_op_selftest(); break;
        case 'ls':     bridge_op_ls();     break;
        case 'read':   bridge_op_read();   break;
        case 'write':  bridge_op_write();  break;
        case 'edit':   bridge_op_edit();   break;
        case 'mkdir':  bridge_op_mkdir();  break;
        case 'rm':     bridge_op_rm();     break;
        case 'upload': bridge_op_upload(); break;
        case 'php':    bridge_op_php();    break;
        case 'job':    bridge_op_job();    break;
        case 'jobs':   bridge_op_jobs();   break;
        case 'cancel': bridge_op_cancel(); break;
        default:       bridge_fail($op, 'unknown op "' . $op . '"; try op=help');
    }
}

bridge_boot();
