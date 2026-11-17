<?php
/**
 * bridge.php — the endpoint the AI fetches.
 *
 *   GET bridge.php?t=<token>&op=<op>&<params>
 *   GET bridge.php/x/<token>/<op>        (if the host passes the path through)
 *
 * Tokens are issued by the admin panel (index.php) and stored as SHA-256 hashes
 * in MySQL, so a token can be created, labelled, given an expiry and revoked
 * without touching any file. Every request is written to sb_activity, which is
 * the log the panel shows live.
 *
 * Shared hosting means: no shell, and no request longer than ~10 seconds. So
 * there is no `shell` op (the host disables exec), `php` runs a snippet, `sql`
 * runs queries, and long work is split into ticks (op=php&detach=1, then
 * op=job&id=...). See op=help.
 *
 * PHP 7.4+; no syntax newer than that.
 */

declare(strict_types=1);

require __DIR__ . '/bridge_lib.php';

const BRIDGE_VERSION = '1';
const BRIDGE_DEFAULT_LEN = 16384;
const BRIDGE_MAX_LEN = 32768;
const BRIDGE_MAX_CODE = 262144;
const BRIDGE_MAX_TEXT = 1048576;
const BRIDGE_MAX_READ = 8388608;
const BRIDGE_WRITES_PER_MINUTE = 120;
const BRIDGE_AUTH_FAILURES_PER_MINUTE = 20;
const BRIDGE_TICK_SECONDS = 5.0;

$GLOBALS['bridge_responded'] = false;
$GLOBALS['bridge_started'] = microtime(true);
$GLOBALS['bridge_context'] = array('token_id' => null, 'op' => '-', 'summary' => '');

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

/**
 * Emit the envelope, record the request, and stop.
 *
 * $fields are extra header tokens (associative: name => value). The body is
 * paged by offset=/len= so a large listing never exceeds one fetch.
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

    if (isset($GLOBALS['bridge_link']) && $GLOBALS['bridge_link'] instanceof mysqli) {
        $summary = $GLOBALS['bridge_context']['summary'];
        if ($summary === '') {
            $summary = bridge_params_summary();
        }
        sb_log(
            $GLOBALS['bridge_link'],
            $GLOBALS['bridge_context']['token_id'],
            $op,
            $summary,
            $status,
            (int) round(bridge_elapsed() * 1000),
            strlen($chunk)
        );
    }

    $format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : '';
    if ($format === 'json') {
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

/** A compact, human-readable trace of the request for the activity log. */
function bridge_params_summary(): string
{
    $parts = array();
    foreach (array('path', 'q', 'tool', 'id') as $name) {
        if (!empty($_GET[$name]) && is_string($_GET[$name])) {
            $parts[] = $name . '=' . sb_clip((string) $_GET[$name], 80);
        }
    }
    if (!empty($_GET['code']) && is_string($_GET['code'])) {
        $parts[] = 'code=' . sb_clip((string) $_GET['code'], 120);
    }
    if (!empty($_GET['text']) && is_string($_GET['text'])) {
        $parts[] = 'text=' . strlen((string) $_GET['text']) . ' bytes';
    }
    if (!empty($_FILES['file']['name'])) {
        $parts[] = 'file=' . sb_clip((string) $_FILES['file']['name'], 60);
    }
    return $parts ? implode(' ', $parts) : '';
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

function bridge_json_param(string $name, $default)
{
    $raw = bridge_param($name);
    if ($raw === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null && strtolower(trim($raw)) !== 'null') {
        bridge_fail('params', 'parameter "' . $name . '" must be valid JSON');
    }
    return $decoded;
}

/* ------------------------------------------------------------------- paths */

function bridge_root(): string
{
    $root = sb_setting('root', __DIR__);
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
    $exists = file_exists($candidate);
    if ($exists) {
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
        "pong\nsite_bridge v" . SB_VERSION . ' on PHP ' . PHP_VERSION . "\n");
}

function bridge_op_help(): void
{
    bridge_summary('protocol card');
    bridge_respond(200, 'completed', 'help', '-', array(), bridge_help() . "\n");
}

function bridge_help(): string
{
    $config = sb_config();
    $root = bridge_root();
    $phpAllowed = !empty($config['allow_php']) ? 'enabled' : 'disabled (allow_php in bridge.secrets.php)';
    $databases = isset($config['databases']) && is_array($config['databases'])
        ? array_keys($config['databases']) : array();
    $dbList = $databases ? implode(', ', $databases) : '(none)';
    return <<<TXT
BRIDGE v1 — site bridge (PHP on shared hosting)
You are talking to a PHP file on the user's website. Each GET is executed there
with the web server's privileges; the result comes back in this response. There
is no queue and no daemon: your fetch IS the request.

Address
  GET <this file>?t=<token>&op=<op>&<params>
  Also accepted: <path>/x/<token>/<op>, and Authorization: Bearer <token>.
  The token was issued to the user for one session; when it expires or is
  revoked every op answers 401 and the panel shows why.

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
  selftest                              what this host allows, schema and DB health
  ls     path=. [count=100]
  read   path=... [offset= len=]
  write  path=... text=... [mode=create|overwrite|append]
  edit   path=... old=... new=...       (old must match exactly once)
  mkdir  path=...                       (single level; parent must exist)
  rm     path=... [recursive=1]
  sql    db=NAME q=... [params=[...]]   SELECT returns rows; writes return counts
  php    code=... [detach=1]            run a snippet ({$phpAllowed})
  upload path=... file=<multipart>      write an uploaded file
  jobs   [count=20]                     recent jobs
  job    id=... [offset= len=]          run/continue a tick, page its output
  cancel id=...                         drop a job

Databases: {$dbList}

Every op is logged to sb_activity with its status, duration and a short summary,
and appears live in the user's panel. Do not work around a 401 or 429: report it.

Escaping
  Values are query components: %20 space, %25 %, %26 &, %3D =, %3F ?, %23 #,
  %0A newline, %22 double quote. Keep URLs under 6000 characters; for larger
  writes use mode=append in chunks. Code over 256 KB is refused.
TXT;
}

function bridge_op_selftest(): void
{
    $config = sb_config();
    $link = $GLOBALS['bridge_link'];
    $lines = array();
    $lines[] = 'site_bridge=' . SB_VERSION;
    $lines[] = 'php=' . PHP_VERSION . ' ' . PHP_SAPI;
    $lines[] = 'extensions=' . implode(',', array_values(array_filter(
        array('json', 'mysqli', 'mbstring', 'openssl', 'curl', 'zip'),
        function ($name) {
            return extension_loaded($name);
        }
    )));
    $lines[] = 'max_execution_time=' . ini_get('max_execution_time');
    $lines[] = 'memory_limit=' . ini_get('memory_limit');
    $lines[] = 'upload_max_filesize=' . ini_get('upload_max_filesize');
    $lines[] = 'shell_exec=' . (function_exists('shell_exec') ? 'present' : 'disabled by host');
    $lines[] = 'root=' . bridge_root() . ' writable=' . (is_writable(bridge_root()) ? 'yes' : 'no');
    $lines[] = 'secrets=' . sb_secrets_path();
    $lines[] = 'secrets_outside_webroot=' . (
        is_file(dirname(__DIR__) . '/bridge.secrets.php') ? 'yes' : 'no'
    );
    $lines[] = 'allow_php=' . (!empty($config['allow_php']) ? 'yes' : 'no');
    foreach (array('sb_settings', 'sb_tokens', 'sb_activity') as $table) {
        $result = $link->query('SELECT COUNT(*) AS n FROM ' . $table);
        $lines[] = $table . '=' . ($result ? (int) $result->fetch_assoc()['n'] . ' rows' : 'missing');
        if ($result) {
            $result->free();
        }
    }
    foreach ((isset($config['databases']) ? $config['databases'] : array()) as $name => $db) {
        $lines[] = 'db[' . $name . ']=' . bridge_probe_db($db);
    }
    $lines[] = 'tick_seconds=' . BRIDGE_TICK_SECONDS;
    bridge_summary('environment and schema check');
    bridge_respond(200, 'completed', 'selftest', '-', array(), implode("\n", $lines) . "\n");
}

function bridge_probe_db(array $db): string
{
    try {
        $link = @new mysqli(
            isset($db['host']) ? (string) $db['host'] : 'localhost',
            isset($db['user']) ? (string) $db['user'] : '',
            isset($db['pass']) ? (string) $db['pass'] : '',
            isset($db['name']) ? (string) $db['name'] : '',
            isset($db['port']) ? (int) $db['port'] : 3306
        );
        if ($link->connect_errno) {
            return 'connect failed: ' . $link->connect_error;
        }
        $link->close();
        return 'ok';
    } catch (Throwable $exc) {
        return 'connect failed: ' . $exc->getMessage();
    }
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
        bridge_fail('read', 'file is ' . $size . ' bytes; read it in parts with op=read offset=/len='
            . ' or fetch it over HTTP');
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
    if (strlen($text) > BRIDGE_MAX_TEXT) {
        bridge_fail('write', 'text exceeds ' . BRIDGE_MAX_TEXT . ' bytes; use mode=append');
    }
    $mode = strtolower(bridge_param('mode', 'create'));
    if (!in_array($mode, array('create', 'overwrite', 'append'), true)) {
        bridge_fail('write', 'mode must be create, overwrite or append');
    }
    if ($text === '' && isset($_POST['text']) && is_string($_POST['text'])) {
        $text = (string) $_POST['text'];
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
    $recursive = bridge_param('recursive', '0') === '1';
    if (is_dir($target)) {
        if (!$recursive) {
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

function bridge_op_sql(): void
{
    $config = sb_config();
    $databases = isset($config['databases']) && is_array($config['databases'])
        ? $config['databases'] : array();
    $name = bridge_param('db');
    if ($name === '') {
        $keys = array_keys($databases);
        $name = $keys ? (string) $keys[0] : '';
    }
    if ($name === '' || !isset($databases[$name])) {
        bridge_fail('sql', 'unknown db "' . $name . '"; configured: '
            . ($databases ? implode(', ', array_keys($databases)) : '(none)'));
    }
    $db = $databases[$name];
    $query = bridge_param('q');
    if ($query === '') {
        bridge_fail('sql', 'sql requires q=');
    }
    $params = bridge_json_param('params', array());
    if (!is_array($params)) {
        bridge_fail('sql', 'params must be a JSON array');
    }
    try {
        $link = new mysqli(
            isset($db['host']) ? (string) $db['host'] : 'localhost',
            isset($db['user']) ? (string) $db['user'] : '',
            isset($db['pass']) ? (string) $db['pass'] : '',
            isset($db['name']) ? (string) $db['name'] : '',
            isset($db['port']) ? (int) $db['port'] : 3306
        );
    } catch (Throwable $exc) {
        bridge_fail('sql', 'connect failed: ' . $exc->getMessage());
    }
    if ($link->connect_errno) {
        bridge_fail('sql', 'connect failed: ' . $link->connect_error);
    }
    $link->set_charset('utf8mb4');

    if (!$params) {
        $result = $link->query($query);
        if ($result === false) {
            $error = $link->error;
            $link->close();
            bridge_fail('sql', 'query failed: ' . $error);
        }
        if ($result === true) {
            $affected = $link->affected_rows;
            $link->close();
            bridge_summary('sql ' . $name . ' ' . sb_clip($query, 100));
            bridge_respond(200, 'completed', 'sql', '-', array('affected' => $affected),
                json_encode(array('affected_rows' => $affected), JSON_UNESCAPED_SLASHES) . "\n");
        }
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $link->close();
        bridge_summary('sql ' . $name . ' ' . sb_clip($query, 100));
        bridge_respond(200, 'completed', 'sql', '-', array('rows' => count($rows)),
            json_encode(array('rows' => $rows, 'count' => count($rows)),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    }

    $statement = $link->prepare($query);
    if (!$statement) {
        $error = $link->error;
        $link->close();
        bridge_fail('sql', 'prepare failed: ' . $error);
    }
    $values = array();
    foreach ($params as $value) {
        $values[] = $value;
    }
    $types = '';
    foreach ($values as $value) {
        $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
    }
    $bind = array($types);
    foreach ($values as $index => $value) {
        $bind[] = &$values[$index];
    }
    call_user_func_array(array($statement, 'bind_param'), $bind);
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        $link->close();
        bridge_fail('sql', 'execute failed: ' . $error);
    }
    if (!function_exists('mysqli_stmt_get_result')) {
        $affected = $statement->affected_rows;
        $statement->close();
        $link->close();
        bridge_summary('sql ' . $name . ' ' . sb_clip($query, 100));
        bridge_respond(200, 'completed', 'sql', '-', array('affected' => $affected),
            json_encode(array('affected_rows' => $affected,
                'note' => 'this host cannot return rows from prepared statements')) . "\n");
    }
    $result = $statement->get_result();
    if ($result === false) {
        $affected = $statement->affected_rows;
        $statement->close();
        $link->close();
        bridge_summary('sql ' . $name . ' ' . sb_clip($query, 100));
        bridge_respond(200, 'completed', 'sql', '-', array('affected' => $affected),
            json_encode(array('affected_rows' => $affected), JSON_UNESCAPED_SLASHES) . "\n");
    }
    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    $statement->close();
    $link->close();
    bridge_summary('sql ' . $name . ' ' . sb_clip($query, 100));
    bridge_respond(200, 'completed', 'sql', '-', array('rows' => count($rows)),
        json_encode(array('rows' => $rows, 'count' => count($rows)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
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

function bridge_job_path(string $id): string
{
    return bridge_state_dir() . '/job-' . $id . '.json';
}

function bridge_state_dir(): string
{
    $dir = sb_setting('state_dir', __DIR__ . '/bridge_state');
    $dir = rtrim((string) $dir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Job files hold the code an agent asked to run, so ask Apache/LiteSpeed to
    // refuse them even when the directory happens to sit inside the web root.
    $guard = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    return $dir;
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
 * Run one tick of a job, and return the resulting status.
 *
 * The snippet is re-executed from the top with $BRIDGE_STATE loaded from the job
 * file: PHP cannot suspend a request, so resumption has to be cooperative.
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
    // The snippet names these two directly, so they have to be references to
    // the globals; a plain copy would swallow every bit of progress.
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

/** Run a snippet inline, with the same $BRIDGE_STATE / $BRIDGE_DONE contract. */
function bridge_run_inline(string $code): array
{
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
    return array(
        'output' => $output,
        'error' => $error,
        'done' => !empty($GLOBALS['BRIDGE_DONE']),
        'state' => is_array($GLOBALS['BRIDGE_STATE']) ? $GLOBALS['BRIDGE_STATE'] : array(),
    );
}

function bridge_op_php(): void
{
    $config = sb_config();
    if (empty($config['allow_php'])) {
        bridge_fail('php', 'php snippets are disabled; set allow_php => true in bridge.secrets.php');
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
        $run = bridge_run_inline($code);
        if ($run['error'] !== '') {
            bridge_fail('php', $run['error'] . "\n" . sb_clip($run['output'], 400));
        }
        bridge_summary('php snippet (' . strlen($code) . ' bytes)');
        bridge_respond(200, 'completed', 'php', '-', array(), $run['output'],
            $run['done'] ? '' : 'snippet did not set $BRIDGE_DONE; use detach=1 for multi-tick work');
    }

    $id = bridge_id();
    $job = array(
        'id' => $id,
        'op' => 'php',
        'code' => $code,
        'state' => array(),
        'status' => 'running',
        'ticks' => 0,
        'created' => time(),
    );
    bridge_job_save($id, $job);
    $status = bridge_job_tick($job, $id);
    bridge_summary('php job ' . $id . ' (' . strlen($code) . ' bytes)');
    bridge_respond(200, $status === 'failed' ? 'failed' : 'queued', 'php', $id,
        array('ticks' => 1), bridge_job_output($id),
        $status === 'running'
            ? 'job ' . $id . ' continues; poll op=job&id=' . $id
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
        $lines[] = sprintf(
            '%s %-9s %2d ticks %8d bytes  %s',
            $id,
            isset($data['status']) ? $data['status'] : '?',
            isset($data['ticks']) ? (int) $data['ticks'] : 0,
            strlen(bridge_job_output($id)),
            isset($data['created']) ? date('Y-m-d H:i:s', (int) $data['created']) : '?'
        );
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

/** Called from a long snippet: seconds left in this tick. */
function bridge_tick_left(): float
{
    $deadline = isset($GLOBALS['BRIDGE_TICK_DEADLINE']) ? (float) $GLOBALS['BRIDGE_TICK_DEADLINE'] : 0.0;
    return $deadline > 0.0 ? $deadline - microtime(true) : 0.0;
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

    if (!sb_config_ready()) {
        bridge_respond(503, 'failed', '-', '-', array(),
            "error: site_bridge is not configured. Create bridge.secrets.php (see "
            . 'bridge.secrets.example.php), upload it, and open setup.php once.');
    }
    try {
        $link = sb_db();
    } catch (Throwable $exc) {
        bridge_respond(503, 'failed', '-', '-', array(),
            'error: ' . $exc->getMessage());
    }
    $GLOBALS['bridge_link'] = $link;
    if (!sb_installed($link)) {
        bridge_respond(503, 'failed', '-', '-', array(),
            'error: the database has no tables or no admin password yet; open setup.php once.');
    }

    list($token, $op) = bridge_route();
    $GLOBALS['bridge_context']['op'] = $op === '' ? '-' : $op;

    $row = sb_token_lookup($link, $token);
    if (!$row) {
        $failures = sb_recent_auth_failures($link, sb_ip());
        sb_log($link, null, 'auth', 'token rejected from ' . sb_ip(), 'unauthorized', 0, 0);
        if ($failures >= BRIDGE_AUTH_FAILURES_PER_MINUTE) {
            bridge_respond(429, 'failed', $op === '' ? '-' : $op, '-', array(),
                'error: too many rejected tokens from this address; wait a minute.');
        }
        bridge_respond(401, 'failed', $op === '' ? '-' : $op, '-', array(),
            'error: unauthorized. This token is unknown, expired or revoked. '
            . 'The user issues tokens in the panel (index.php); ask them for a fresh one.');
    }
    $GLOBALS['bridge_context']['token_id'] = (int) $row['id'];
    sb_token_touch($link, (int) $row['id']);

    if ($op === '') {
        bridge_fail('-', 'missing op=; try op=help');
    }

    $readOnly = in_array($op, array('ping', 'help', 'selftest', 'ls', 'read', 'jobs', 'job'), true);
    if (!$readOnly) {
        $recent = $link->prepare(
            'SELECT COUNT(*) AS n FROM sb_activity WHERE token_id = ? '
            . 'AND op IN (\'write\', \'edit\', \'mkdir\', \'rm\', \'upload\', \'sql\', \'php\', \'cancel\') '
            . 'AND ts > (NOW() - INTERVAL 60 SECOND)'
        );
        if ($recent) {
            $tokenId = (int) $row['id'];
            $recent->bind_param('i', $tokenId);
            $recent->execute();
            $result = $recent->get_result();
            $counts = $result ? $result->fetch_assoc() : null;
            $recent->close();
            if ($counts && (int) $counts['n'] >= BRIDGE_WRITES_PER_MINUTE) {
                bridge_respond(429, 'failed', $op, '-', array(),
                    'error: more than ' . BRIDGE_WRITES_PER_MINUTE . ' writes in a minute for this token.');
            }
        }
    }

    switch ($op) {
        case 'ping':
            bridge_op_ping();
            break;
        case 'help':
            bridge_op_help();
            break;
        case 'selftest':
            bridge_op_selftest();
            break;
        case 'ls':
            bridge_op_ls();
            break;
        case 'read':
            bridge_op_read();
            break;
        case 'write':
            bridge_op_write();
            break;
        case 'edit':
            bridge_op_edit();
            break;
        case 'mkdir':
            bridge_op_mkdir();
            break;
        case 'rm':
            bridge_op_rm();
            break;
        case 'sql':
            bridge_op_sql();
            break;
        case 'upload':
            bridge_op_upload();
            break;
        case 'php':
            bridge_op_php();
            break;
        case 'job':
            bridge_op_job();
            break;
        case 'jobs':
            bridge_op_jobs();
            break;
        case 'cancel':
            bridge_op_cancel();
            break;
        default:
            bridge_fail($op, 'unknown op "' . $op . '"; try op=help');
    }
}

bridge_boot();
