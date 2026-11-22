<?php
declare(strict_types=1);

// Fussball-Konditionstraining – eigenständiger Einstiegspunkt (Konfiguration, Datenbank, Hilfsfunktionen).
//
// Konfiguration (in dieser Reihenfolge):
//   1. config.php in diesem App-Ordner (nur auf dem Server, nie im Repository)
//   2. sonst die Konfiguration der Website auf demselben Server (../app/config.php):
//      gleiche Datenbank, gleiches Postfach (SMTP-Angaben aus der Website-Datenbank).

const KD_APP_DIR = __DIR__;
const KD_ROOT = __DIR__ . '/..';
const KD_DATA_DIR = KD_ROOT . '/data';

date_default_timezone_set('Europe/Zurich');
mb_internal_encoding('UTF-8');

if (is_file(KD_APP_DIR . '/vendor/autoload.php')) {
    require KD_APP_DIR . '/vendor/autoload.php';
}

set_exception_handler(function (Throwable $e): void {
    app_log('error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo config('debug') ? '<pre>' . e((string) $e) . '</pre>' : '<h1>Es ist ein Fehler aufgetreten</h1><p>Bitte später nochmals versuchen.</p>';
});

// ---- Konfiguration -------------------------------------------------------

/** Pfad der Website-Konfiguration auf demselben Server (Fallback). */
function kd_website_config_file(): string
{
    return KD_ROOT . '/../app/config.php';
}

function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $own = KD_ROOT . '/config.php';
        $config = is_file($own) ? (array) require $own : [];
        $config['_source'] = is_file($own) ? 'app' : 'none';
        if (!isset($config['db']) && is_file(kd_website_config_file())) {
            $site = (array) require kd_website_config_file();
            $config['db'] = $site['db'] ?? null;
            $config['base_url'] ??= $site['base_url'] ?? null;
            $config['_source'] = 'website';
        }
    }
    return $key === null ? $config : ($config[$key] ?? $default);
}

function app_installed(): bool
{
    return is_array(config('db'));
}

function base_url(): string
{
    if ($u = config('base_url')) {
        return rtrim((string) $u, '/');
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ---- Datenbank (MySQL auf dem Server, SQLite im Test) --------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = config('db');
    if (($c['driver'] ?? '') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db_driver(): string
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_val(string $sql, array $params = []): mixed
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    db_query("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($data));
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $params = []): int
{
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    return db_query("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $params))->rowCount();
}

// ---- Einstellungen (eigene Tabelle kd_settings) --------------------------

function setting(string $name): string
{
    static $cache = null;
    if ($name === '__reset') {
        $cache = null;
        return '';
    }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT name, value FROM kd_settings') as $row) {
                $cache[$row['name']] = (string) $row['value'];
            }
        } catch (PDOException) {
            // Tabelle wird beim ersten Aufruf angelegt
        }
    }
    return $cache[$name] ?? '';
}

function setting_set(string $name, string $value): void
{
    if (db_val('SELECT COUNT(*) FROM kd_settings WHERE name = ?', [$name])) {
        db_update('kd_settings', ['value' => $value], 'name = ?', [$name]);
    } else {
        db_insert('kd_settings', ['name' => $name, 'value' => $value]);
    }
    setting('__reset');
}

// ---- Allgemeine Hilfen ---------------------------------------------------

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190;
}

/** Echte IP hinter dem Proxy von Infomaniak (X-Real-IP / letzter Eintrag in X-Forwarded-For). */
function client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        trim((string) array_slice(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), -1)[0]),
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function password_problem(string $password): ?string
{
    return mb_strlen($password) < 10 ? 'Das Passwort muss mindestens 10 Zeichen lang sein.' : null;
}

function app_log(string $channel, string $message): void
{
    $dir = KD_DATA_DIR . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/' . preg_replace('/[^a-z]/', '', $channel) . '.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

/** Einfaches E-Mail-Layout. */
function mail_html(string $text): string
{
    $body = nl2br(e($text));
    $body = (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $body);
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f2f5f8;font-family:Helvetica,Arial,sans-serif;color:#13212b">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;border-top:4px solid #f5cf1a;padding:24px;font-size:15px;line-height:1.55">'
        . $body . '</div></body></html>';
}

/** SMTP-Angaben: aus config.php der App oder aus den Einstellungen der Website (gleiche Datenbank). */
function kd_mail_config(): array
{
    if (is_array($smtp = config('smtp'))) {
        return $smtp;
    }
    try {
        $rows = db_all("SELECT name, value FROM settings WHERE name IN ('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'mail_from')");
    } catch (PDOException) {
        return [];
    }
    $s = array_column($rows, 'value', 'name');
    return ['host' => $s['smtp_host'] ?? '', 'port' => (int) ($s['smtp_port'] ?? 587), 'user' => $s['smtp_user'] ?? '', 'pass' => $s['smtp_pass'] ?? '', 'from' => $s['mail_from'] ?? ''];
}

// ---- Anmeldung der Website (nur für die erste Einrichtung) ---------------

/**
 * Ist im selben Browser eine Administratorin der Website ki-smartwork.ch angemeldet (Recht «settings»)?
 * Liest dafür nur die Sitzung der Website (Cookie «kisw») und deren Benutzertabelle.
 */
function kd_website_admin(): ?array
{
    if (!isset($_COOKIE['kisw']) || !preg_match('/^[A-Za-z0-9,-]{20,128}$/', (string) $_COOKIE['kisw'])) {
        return null;
    }
    try {
        session_name('kisw');
        session_start(['read_and_close' => true]);
        $id = $_SESSION['user_id'] ?? null;
        $fresh = time() - (int) ($_SESSION['last_seen'] ?? 0) <= 7200;
        if (!$id || !$fresh) {
            return null;
        }
        $row = db_one('SELECT u.name, u.email, r.permissions FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.active = 1', [$id]);
        $perms = $row ? (json_decode((string) $row['permissions'], true) ?: []) : [];
        return $row && in_array('settings', $perms, true) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

// ---- Version -------------------------------------------------------------

/** Version, Commit und Datum (beim Hochladen von GitHub geschrieben, siehe .github/workflows/deploy.yml). */
function kd_version(): array
{
    $file = KD_APP_DIR . '/version.php';
    $v = is_file($file) ? (array) require $file : [];
    return ['version' => (string) ($v['version'] ?? trim((string) @file_get_contents(KD_ROOT . '/../VERSION')) ?: 'Entwicklung'),
        'commit' => (string) ($v['commit'] ?? ''), 'date' => (string) ($v['date'] ?? '')];
}
