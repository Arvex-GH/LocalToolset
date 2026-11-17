<?php
/**
 * bridge_lib — shared plumbing for site_bridge: config, MySQL, tokens, logging.
 *
 * Used by bridge.php (the endpoint the AI fetches), index.php (the admin panel)
 * and setup.php (one-time installer). PHP 7.4+; no extensions beyond mysqli and
 * json, because that is what shared hosting reliably provides.
 */

declare(strict_types=1);

const SB_VERSION = '1.0';
const SB_TOKEN_PREFIX = 'sb_';
const SB_MAX_SUMMARY = 240;

/** Where the credentials live: above the web root first, then beside the code. */
function sb_secrets_path(): string
{
    $outside = dirname(__DIR__) . '/bridge.secrets.php';
    if (is_file($outside)) {
        return $outside;
    }
    return __DIR__ . '/bridge.secrets.php';
}

/**
 * Load bridge.secrets.php. Returns the array as written; an empty array means
 * "not configured yet", which setup.php in particular has to handle.
 */
function sb_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = sb_secrets_path();
    if (!is_file($path)) {
        $cache = array();
        return $cache;
    }
    $config = include $path;
    if (!is_array($config)) {
        throw new RuntimeException('bridge.secrets.php must return an array');
    }
    $cache = $config;
    return $cache;
}

function sb_config_ready(): bool
{
    try {
        $config = sb_config();
    } catch (Throwable $exc) {
        return false;
    }
    return isset($config['db']) && is_array($config['db'])
        && !empty($config['db']['name']) && !empty($config['db']['user']);
}

function sb_setting(string $name, $default = null)
{
    $config = sb_config();
    if (array_key_exists($name, $config)) {
        return $config[$name];
    }
    return $default;
}

/** One shared mysqli handle; throws with a readable message on failure. */
function sb_db(): mysqli
{
    static $link = null;
    if ($link instanceof mysqli) {
        return $link;
    }
    $config = sb_config();
    if (!isset($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('bridge.secrets.php has no db section; run setup.php');
    }
    $db = $config['db'];
    mysqli_report(MYSQLI_REPORT_OFF);
    $link = @new mysqli(
        isset($db['host']) ? (string) $db['host'] : 'localhost',
        isset($db['user']) ? (string) $db['user'] : '',
        isset($db['pass']) ? (string) $db['pass'] : '',
        isset($db['name']) ? (string) $db['name'] : '',
        isset($db['port']) ? (int) $db['port'] : 3306
    );
    if ($link->connect_errno) {
        throw new RuntimeException('database connection failed: ' . $link->connect_error);
    }
    $link->set_charset('utf8mb4');
    return $link;
}

/** The whole schema in one place; setup.php runs it, nothing else needs SQL DDL. */
function sb_schema(): array
{
    return array(
        'CREATE TABLE IF NOT EXISTS sb_settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS sb_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            hint VARCHAR(24) NOT NULL DEFAULT \'\',
            label VARCHAR(64) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            last_used_at DATETIME NULL DEFAULT NULL,
            uses INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS sb_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_id INT UNSIGNED NULL DEFAULT NULL,
            ts DATETIME NOT NULL,
            op VARCHAR(16) NOT NULL DEFAULT \'\',
            summary VARCHAR(255) NOT NULL DEFAULT \'\',
            status VARCHAR(16) NOT NULL DEFAULT \'\',
            ms INT UNSIGNED NOT NULL DEFAULT 0,
            bytes INT UNSIGNED NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            KEY idx_ts (ts),
            KEY idx_token (token_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    );
}

/** Create any missing tables. Safe to call repeatedly. */
function sb_install_schema(mysqli $link): void
{
    foreach (sb_schema() as $statement) {
        if (!$link->query($statement)) {
            throw new RuntimeException('schema failed: ' . $link->error . ' in: '
                . substr($statement, 0, 60));
        }
    }
}

function sb_setting_get(mysqli $link, string $name, string $default = ''): string
{
    $statement = $link->prepare('SELECT value FROM sb_settings WHERE name = ?');
    if (!$statement) {
        return $default;
    }
    $statement->bind_param('s', $name);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $statement->close();
    return $row ? (string) $row['value'] : $default;
}

function sb_setting_put(mysqli $link, string $name, string $value): void
{
    $statement = $link->prepare(
        'INSERT INTO sb_settings (name, value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    if (!$statement) {
        throw new RuntimeException('cannot write setting: ' . $link->error);
    }
    $statement->bind_param('ss', $name, $value);
    $statement->execute();
    $statement->close();
}

function sb_installed(mysqli $link): bool
{
    try {
        return sb_setting_get($link, 'admin_hash', '') !== '';
    } catch (Throwable $exc) {
        return false;
    }
}

/** `sb_` + 48 hex characters: URL-safe, no escaping needed, 192 bits of entropy. */
function sb_new_token(): string
{
    return SB_TOKEN_PREFIX . bin2hex(random_bytes(24));
}

function sb_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Short, non-secret fragment the panel can show to identify a token. */
function sb_token_hint(string $token): string
{
    return substr($token, 0, 11) . '…' . substr($token, -4);
}

/**
 * Resolve a bearer token to a live row, or null.
 *
 * Only the SHA-256 hash is stored, so a database leak does not hand over working
 * tokens. Expired and revoked rows stay in the table for the audit trail.
 */
function sb_token_lookup(mysqli $link, string $token)
{
    if ($token === '' || strlen($token) > 200) {
        return null;
    }
    $statement = $link->prepare(
        'SELECT id, label, created_at, expires_at, revoked_at, uses '
        . 'FROM sb_tokens WHERE token_hash = ? LIMIT 1'
    );
    if (!$statement) {
        return null;
    }
    $hash = sb_token_hash($token);
    $statement->bind_param('s', $hash);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $statement->close();
    if (!$row) {
        return null;
    }
    if (!empty($row['revoked_at'])) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) {
        return null;
    }
    return $row;
}

function sb_token_touch(mysqli $link, int $id): void
{
    $statement = $link->prepare(
        'UPDATE sb_tokens SET uses = uses + 1, last_used_at = ? WHERE id = ?'
    );
    if (!$statement) {
        return;
    }
    $now = sb_now();
    $statement->bind_param('si', $now, $id);
    $statement->execute();
    $statement->close();
}

function sb_log(mysqli $link, $tokenId, string $op, string $summary, string $status, int $ms, int $bytes): void
{
    $statement = $link->prepare(
        'INSERT INTO sb_activity (token_id, ts, op, summary, status, ms, bytes, ip) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        return;
    }
    $now = sb_now();
    $ip = sb_ip();
    $summary = sb_clip($summary, SB_MAX_SUMMARY);
    $tokenValue = $tokenId === null ? null : (int) $tokenId;
    $statement->bind_param('issssiis', $tokenValue, $now, $op, $summary, $status, $ms, $bytes, $ip);
    $statement->execute();
    $statement->close();
}

/** Wrong-token pressure from one address, last minute. */
function sb_recent_auth_failures(mysqli $link, string $ip): int
{
    $statement = $link->prepare(
        'SELECT COUNT(*) AS n FROM sb_activity '
        . 'WHERE op = \'auth\' AND status = \'unauthorized\' AND ip = ? '
        . 'AND ts > (NOW() - INTERVAL 60 SECOND)'
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

function sb_now(): string
{
    return date('Y-m-d H:i:s');
}

function sb_ip(): string
{
    $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
    foreach ($candidates as $key) {
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

function sb_clip(string $text, int $limit): string
{
    $text = str_replace(array("\r", "\n", "\t"), ' ', $text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return substr($text, 0, $limit);
}

function sb_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Is the current request HTTPS, directly or behind a proxy? */
function sb_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

function sb_base_url(): string
{
    $scheme = sb_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $path = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $scheme . '://' . $host . $dir;
}

function sb_json(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        http_response_code($code);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Human wording for an expiry in hours from now; null means "never". */
function sb_expiry_date($hours)
{
    if ($hours === null || $hours === '' || (int) $hours === 0) {
        return null;
    }
    $hours = max(1, min((int) $hours, 24 * 365));
    return date('Y-m-d H:i:s', time() + $hours * 3600);
}
