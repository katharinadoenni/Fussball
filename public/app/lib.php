<?php
declare(strict_types=1);

// Konditionstraining: gemeinsame Funktionen (Datenbank, Zugang, Auswertung, Kurven, Versand, Seitengerüst).
// Eigene Tabellen (kd_…) und eigene Zugänge:
//   – Spielerinnen: persönlicher Link (kein Konto, kein Passwort)
//   – Admins (Trainerteam): E-Mail und Passwort, beliebig viele

use PHPMailer\PHPMailer\PHPMailer;

const KD_SCHEMA_VERSION = 3;
const KD_COOKIE = 'kond';
const KD_PLAYER_DAYS = 180;        // Spielerinnen bleiben ein halbes Jahr angemeldet
const KD_ADMIN_IDLE = 28800;       // Admins: 8 Stunden Inaktivität
const KD_INVITE_DAYS = 7;          // Einladungslink für neue Admins
const KD_DISTANCES = [400, 1000, 2400, 3000, 5000];
/** Rückmeldungen, die das Trainerteam pro Lauf mit einem Klick geben kann: Schlüssel => [Zeichen, Text]. */
const KD_FEEDBACK = [
    'daumen' => ['👍', 'Daumen hoch'],
    'bravo' => ['👏', 'Bravo!'],
    'weiter' => ['💪', 'Weiter so!'],
    'stolz' => ['⭐', 'Ich bin stolz auf dich!'],
    'besser' => ['🎯', 'Das geht noch ein bisschen besser'],
];

function kd_base(): string
{
    return rtrim((string) config('kondition_base', '/app/fussballtraining'), '/');
}

function kd_url(string $path = ''): string
{
    return kd_base() . '/' . ltrim($path, '/');
}

function kd_abs_url(string $path = ''): string
{
    return rtrim(base_url(), '/') . kd_url($path);
}

function kd_redirect(string $path): never
{
    header('Location: ' . kd_url($path));
    exit;
}

function kd_team(): string
{
    return setting('kd_team_name') ?: 'Konditionstraining';
}

// ---- Datenbank -----------------------------------------------------------

function kd_migrate(): void
{
    if ((int) setting('kd_schema_version') >= KD_SCHEMA_VERSION) {
        return;
    }
    $mysql = db_driver() === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $mysql ? 'MEDIUMTEXT NULL' : 'TEXT NULL';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        'kd_settings' => "name VARCHAR(100) NOT NULL PRIMARY KEY, value $text",
        'kd_mail_log' => "id $id, to_email VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(30) NOT NULL, error VARCHAR(500) NULL, created_at VARCHAR(19) NOT NULL",
        'kd_admins' => "id $id, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, phone VARCHAR(40) NULL,
            password_hash VARCHAR(255) NULL, invite_hash VARCHAR(64) NULL, invite_expires VARCHAR(19) NULL, active INT NOT NULL DEFAULT 1,
            last_login_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL",
        'kd_players' => "id $id, first_name VARCHAR(60) NOT NULL, last_name VARCHAR(60) NOT NULL, email VARCHAR(190) NULL, phone VARCHAR(40) NULL,
            birthday VARCHAR(10) NULL, access_token VARCHAR(64) NOT NULL, emergency_name VARCHAR(120) NULL, emergency_relation VARCHAR(60) NULL,
            emergency_phone VARCHAR(40) NULL, emergency_email VARCHAR(190) NULL, pause_from VARCHAR(10) NULL, pause_to VARCHAR(10) NULL,
            pause_reason VARCHAR(200) NULL, admin_note VARCHAR(1000) NULL, infos_seen_at VARCHAR(19) NULL, active INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL",
        'kd_runs' => "id $id, player_id INT NOT NULL, run_date VARCHAR(10) NOT NULL, distance_m INT NOT NULL, seconds INT NOT NULL,
            note VARCHAR(500) NULL, coach_note VARCHAR(500) NULL, feedback VARCHAR(20) NULL, created_at VARCHAR(19) NOT NULL",
        'kd_infos' => "id $id, title VARCHAR(150) NOT NULL, body $text, pinned INT NOT NULL DEFAULT 0, admin_id INT NULL,
            created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL",
        'kd_sessions' => "id $id, token_hash VARCHAR(64) NOT NULL, kind VARCHAR(10) NOT NULL, ref_id INT NOT NULL, created_at VARCHAR(19) NOT NULL, last_seen_at VARCHAR(19) NOT NULL",
        'kd_attempts' => "id $id, kind VARCHAR(20) NOT NULL, ip VARCHAR(45) NOT NULL, created_at VARCHAR(19) NOT NULL",
    ];
    foreach ($tables as $table => $cols) {
        db()->exec("CREATE TABLE IF NOT EXISTS $table ($cols)$suffix");
    }
    try {
        db()->exec('ALTER TABLE kd_players ADD COLUMN infos_seen_at VARCHAR(19) NULL'); // ab Version 2: gelesene Infos
    } catch (PDOException) {
        // existiert bereits
    }
    foreach (['kd_runs' => 'player_id', 'kd_sessions' => 'token_hash', 'kd_attempts' => 'ip', 'kd_players' => 'access_token'] as $table => $col) {
        try {
            db()->exec("CREATE INDEX idx_{$table}_{$col} ON $table ($col)");
        } catch (PDOException) {
            // existiert bereits
        }
    }
    if (setting('kd_secret') === '') {
        setting_set('kd_secret', bin2hex(random_bytes(32)));
    }
    setting_set('kd_schema_version', (string) KD_SCHEMA_VERSION);
}

function kd_ready(): bool
{
    return (int) db_val('SELECT COUNT(*) FROM kd_admins WHERE password_hash IS NOT NULL') > 0;
}

// ---- Zugang --------------------------------------------------------------

/** Aktuelle Sitzung: ['kind' => 'player'|'admin', 'ref_id' => …, 'who' => Datensatz] oder null. */
function kd_session(): ?array
{
    static $session = false;
    if ($session !== false) {
        return $session;
    }
    $session = null;
    $token = $_COOKIE[KD_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) < 20) {
        return null;
    }
    $row = db_one('SELECT * FROM kd_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$row) {
        return null;
    }
    $idle = time() - strtotime($row['last_seen_at']);
    $limit = $row['kind'] === 'admin' ? KD_ADMIN_IDLE : KD_PLAYER_DAYS * 86400;
    $who = $row['kind'] === 'admin'
        ? db_one('SELECT * FROM kd_admins WHERE id = ? AND active = 1', [$row['ref_id']])
        : db_one('SELECT * FROM kd_players WHERE id = ? AND active = 1', [$row['ref_id']]);
    if ($idle > $limit || !$who) {
        db_query('DELETE FROM kd_sessions WHERE id = ?', [$row['id']]);
        return null;
    }
    if ($idle > 300) {
        db_update('kd_sessions', ['last_seen_at' => now()], 'id = ?', [$row['id']]);
    }
    $row['token'] = $token;
    $row['who'] = $who;
    return $session = $row;
}

function kd_login(string $kind, int $refId): void
{
    $old = kd_session();
    if ($old) {
        db_query('DELETE FROM kd_sessions WHERE id = ?', [$old['id']]);
    }
    db_query('DELETE FROM kd_sessions WHERE last_seen_at < ?', [date('Y-m-d H:i:s', time() - KD_PLAYER_DAYS * 86400)]);
    $token = token(32);
    db_insert('kd_sessions', ['token_hash' => hash('sha256', $token), 'kind' => $kind, 'ref_id' => $refId, 'created_at' => now(), 'last_seen_at' => now()]);
    $expires = $kind === 'admin' ? 0 : time() + KD_PLAYER_DAYS * 86400;
    kd_cookie(KD_COOKIE, $token, $expires);
    if ($kind === 'admin') {
        db_update('kd_admins', ['last_login_at' => now()], 'id = ?', [$refId]);
    }
}

function kd_logout(): void
{
    $s = kd_session();
    if ($s) {
        db_query('DELETE FROM kd_sessions WHERE id = ?', [$s['id']]);
    }
    kd_cookie(KD_COOKIE, '', time() - 3600);
}

function kd_cookie(string $name, string $value, int $expires = 0): void
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie($name, $value, ['expires' => $expires, 'path' => kd_url(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

function kd_admin(): ?array
{
    $s = kd_session();
    return ($s['kind'] ?? '') === 'admin' ? $s['who'] : null;
}

function kd_player(): ?array
{
    $s = kd_session();
    return ($s['kind'] ?? '') === 'player' ? $s['who'] : null;
}

function kd_require_player(): array
{
    return kd_player() ?? kd_redirect('');
}

function kd_require_admin(): array
{
    return kd_admin() ?? kd_redirect('admin/anmelden');
}

function kd_csrf(): string
{
    $s = kd_session();
    return $s ? hash_hmac('sha256', 'csrf|' . $s['token'], setting('kd_secret')) : '';
}

function kd_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(kd_csrf()) . '">';
}

function kd_csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || kd_csrf() === '' || !hash_equals(kd_csrf(), $sent)) {
        kd_page('Sitzung abgelaufen', '<section class="card narrow"><h1>Sitzung abgelaufen</h1><p>Bitte die Seite neu laden und es nochmals versuchen.</p>'
            . '<p><a class="btn" href="' . e(kd_url()) . '">Zur Startseite</a></p></section>', 419);
    }
}

/** Bremse gegen Durchprobieren von Links und Passwörtern. */
function kd_too_many(string $kind, int $max = 10, int $minutes = 15): bool
{
    db_query('DELETE FROM kd_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    return (int) db_val('SELECT COUNT(*) FROM kd_attempts WHERE kind = ? AND ip = ? AND created_at >= ?', [$kind, client_ip(), $since]) >= $max;
}

function kd_attempt(string $kind): void
{
    db_insert('kd_attempts', ['kind' => $kind, 'ip' => client_ip(), 'created_at' => now()]);
}

// ---- Meldungen -----------------------------------------------------------

function kd_flash(string $type, string $message): void
{
    kd_cookie('kond_flash', (string) json_encode([$type, $message], JSON_UNESCAPED_UNICODE));
}

function kd_take_flash(): string
{
    $raw = $_COOKIE['kond_flash'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    kd_cookie('kond_flash', '', time() - 3600);
    $data = json_decode($raw, true);
    if (!is_array($data) || count($data) !== 2) {
        return '';
    }
    $class = $data[0] === 'error' ? 'notice error' : 'notice';
    return '<p class="' . $class . '" role="status">' . e((string) $data[1]) . '</p>';
}

// ---- Spielerinnen --------------------------------------------------------

function kd_clean_name(string $name): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $name));
}

function kd_player_name(array $p): string
{
    return $p['first_name'] . ' ' . $p['last_name'];
}

function kd_player_link(array $p): string
{
    return kd_abs_url('zugang/' . $p['access_token']);
}

/** Alle Spielerinnen, sortiert nach Vorname. */
function kd_players(bool $activeOnly = false): array
{
    return db_all('SELECT * FROM kd_players' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY first_name, last_name');
}

/** Ist die Spielerin heute in einer Pause (verletzt, Ferien …)? */
function kd_on_pause(array $p, ?string $day = null): bool
{
    $day ??= date('Y-m-d');
    return $p['pause_from'] && $p['pause_from'] <= $day && (!$p['pause_to'] || $p['pause_to'] >= $day);
}

/** Tage bis zum nächsten Geburtstag (0 = heute), das neue Alter und das Datum. */
function kd_next_birthday(?string $birthday): ?array
{
    if (!$birthday || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m)) {
        return null;
    }
    $today = new DateTimeImmutable('today');
    $year = (int) $today->format('Y');
    $md = fn(int $y) => ($m[2] === '02' && $m[3] === '29' && !checkdate(2, 29, $y)) ? '02-28' : $m[2] . '-' . $m[3];
    $next = new DateTimeImmutable($year . '-' . $md($year));
    if ($next < $today) {
        $year++;
        $next = new DateTimeImmutable($year . '-' . $md($year));
    }
    return ['days' => (int) $today->diff($next)->days, 'age' => $year - (int) $m[1], 'date' => $next->format('Y-m-d')];
}

function kd_age(?string $birthday): ?int
{
    if (!$birthday) {
        return null;
    }
    return (int) (new DateTimeImmutable($birthday))->diff(new DateTimeImmutable('today'))->y;
}

// ---- Datum in drei Feldern (Tag / Monat / Jahr) ----------------------------

function kd_valid_ymd(?string $ymd): bool
{
    $d = $ymd ? DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) : false;
    return $d && $d->format('Y-m-d') === $ymd;
}

const KD_MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

/** Datumseingabe mit separaten Feldern für Tag, Monat (Auswahl) und Jahr. Felder: {name}_d, {name}_m, {name}_y. */
function kd_date_field(string $name, string $label, ?string $value, bool $required = false, string $hint = ''): string
{
    [$y, $m, $d] = $value && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $p) ? [$p[1], (int) $p[2], (string) (int) $p[3]] : ['', 0, ''];
    $req = $required ? ' required' : '';
    $opts = '<option value="">Monat</option>';
    foreach (KD_MONTHS as $n => $month) {
        $opts .= '<option value="' . $n . '"' . ($n === $m ? ' selected' : '') . '>' . $month . '</option>';
    }
    $id = e($name);
    return '<fieldset class="field date-field"><legend>' . e($label) . '</legend><div class="date-row">'
        . '<input id="' . $id . '_d" name="' . $id . '_d" type="number" inputmode="numeric" min="1" max="31" step="1" placeholder="Tag" aria-label="' . e($label) . ': Tag" value="' . e($d) . '"' . $req . '>'
        . '<select id="' . $id . '_m" name="' . $id . '_m" aria-label="' . e($label) . ': Monat"' . $req . '>' . $opts . '</select>'
        . '<input id="' . $id . '_y" name="' . $id . '_y" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="Jahr" aria-label="' . e($label) . ': Jahr" value="' . e($y) . '"' . $req . '>'
        . '</div>' . ($hint !== '' ? '<span class="hint">' . e($hint) . '</span>' : '') . '</fieldset>';
}

/**
 * Setzt aus {name}_d/_m/_y das Feld {name} als JJJJ-MM-TT ('' wenn leer, 'ungültig' wenn unvollständig oder falsch).
 * Fehlen alle drei Teile in der Anfrage, bleibt ein direkt gesendetes {name} unverändert.
 */
function kd_collect_dates(array &$src, array $names): void
{
    foreach ($names as $n) {
        if (!isset($src[$n . '_d']) && !isset($src[$n . '_m']) && !isset($src[$n . '_y'])) {
            continue;
        }
        $parts = array_map(fn($k) => trim(is_string($src[$n . $k] ?? '') ? (string) ($src[$n . $k] ?? '') : ''), ['_d', '_m', '_y']);
        if ($parts === ['', '', '']) {
            $src[$n] = '';
            continue;
        }
        [$d, $m, $y] = $parts;
        $src[$n] = ctype_digit($d) && ctype_digit($m) && ctype_digit($y) && strlen($y) === 4 && checkdate((int) $m, (int) $d, (int) $y)
            ? sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d) : 'ungültig';
    }
}

// ---- Kontakt: E-Mail und WhatsApp ----------------------------------------

/** Telefonnummer für WhatsApp (nur Ziffern, Schweizer Nummern mit 41). */
function kd_wa_number(?string $phone): string
{
    $phone = trim((string) $phone);
    $digits = (string) preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '+')) {
        return $digits;
    }
    if (str_starts_with($digits, '00')) {
        return substr($digits, 2);
    }
    if (str_starts_with($digits, '0')) {
        return '41' . substr($digits, 1);
    }
    return $digits;
}

/** WhatsApp-Knopf: öffnet WhatsApp mit vorbereiteter Nachricht (Senden tippt man selbst). */
function kd_wa_button(?string $phone, string $text, string $label = 'WhatsApp'): string
{
    $n = kd_wa_number($phone);
    if (strlen($n) < 8) {
        return '';
    }
    return '<a class="btn btn-small btn-wa" target="_blank" rel="noopener" href="https://wa.me/' . e($n) . '?text=' . e(rawurlencode($text)) . '">' . e($label) . '</a>';
}

/** E-Mail im Namen der Mannschaft verschicken (Antworten gehen an den Admin, der sendet). */
function kd_send_mail(string $to, string $subject, string $text, ?array $admin = null): bool
{
    $status = 'gesendet';
    $error = null;
    $smtp = kd_mail_config();
    if (($smtp['pass'] ?? '') === '' || ($smtp['host'] ?? '') === '') {
        $status = 'nur protokolliert';
        app_log('mail', "AN: $to | BETREFF: $subject\n$text\n" . str_repeat('-', 60));
    } else {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = (int) $smtp['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['user'];
            $mail->Password = $smtp['pass'];
            $mail->SMTPSecure = (int) $smtp['port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $domain = substr(strrchr($smtp['from'], '@') ?: '@ki-smartwork.ch', 1);
            $mail->Hostname = $domain;
            $mail->Helo = $domain;
            $mail->XMailer = ' ';
            $mail->setFrom($smtp['from'], kd_team());
            $mail->Sender = $smtp['from'];
            if ($admin) {
                $mail->addReplyTo($admin['email'], $admin['name']);
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = mail_html($text);
            $mail->AltBody = $text;
            $mail->send();
        } catch (Throwable $e) {
            $status = 'fehler';
            $error = $e->getMessage();
            app_log('mail', "FEHLER an $to: $error");
        }
    }
    db_insert('kd_mail_log', ['to_email' => $to, 'subject' => mb_substr($subject, 0, 255), 'status' => $status, 'error' => $error, 'created_at' => now()]);
    return $status !== 'fehler';
}

// ---- Läufe und Auswertung ------------------------------------------------

/** Prüft die Angaben eines Laufs. Gibt [daten, fehler] zurück. */
function kd_validate_run(array $in): array
{
    $date = (string) ($in['run_date'] ?? '');
    $distanceRaw = trim((string) ($in['distance_m'] ?? ''));
    $distance = ctype_digit($distanceRaw) ? (int) $distanceRaw : 0;
    $min = (string) ($in['minutes'] ?? '');
    $sec = (string) ($in['seconds'] ?? '');
    $note = trim((string) ($in['note'] ?? ''));
    $seconds = (int) $min * 60 + (int) $sec;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $error = match (true) {
        !$d || $d->format('Y-m-d') !== $date => 'Bitte ein gültiges Datum wählen.',
        $date > date('Y-m-d', time() + 86400) => 'Das Datum liegt in der Zukunft.',
        $date < date('Y-m-d', strtotime('-2 years')) => 'Das Datum liegt mehr als zwei Jahre zurück.',
        $distanceRaw !== '' && !ctype_digit($distanceRaw) => 'Bitte die Länge der Strecke als ganze Zahl in Metern angeben, z.B. 3000 für 3 km.',
        $distance > 0 && $distance < 50 => 'Bitte die Länge in Metern angeben, nicht in Kilometern: ' . $distance . ' km = ' . number_format($distance * 1000, 0, '.', '’') . ' m.',
        $distance < 50 || $distance > 50000 => 'Bitte die Länge der Strecke in Metern angeben (zwischen 50 und 50’000 m).',
        !ctype_digit($min === '' ? '0' : $min) || !ctype_digit($sec === '' ? '0' : $sec) => 'Bitte die Laufzeit in ganzen Minuten und Sekunden angeben.',
        (int) $sec > 59 => 'Bei den Sekunden sind höchstens 59 möglich.',
        $seconds < 10 => 'Bitte die Laufzeit angeben.',
        $seconds > 6 * 3600 => 'Die Laufzeit ist zu lang (höchstens 6 Stunden).',
        $distance / $seconds * 3.6 > 40 => 'Das ergibt mehr als 40 km/h – bitte Strecke und Zeit prüfen.',
        mb_strlen($note) > 500 => 'Die Bemerkung darf höchstens 500 Zeichen lang sein.',
        default => null,
    };
    return [['run_date' => $date, 'distance_m' => $distance, 'seconds' => $seconds, 'note' => $note === '' ? null : $note], $error];
}

/** Alle Läufe mit Namen der Spielerin. */
function kd_all_runs(): array
{
    return db_all('SELECT r.*, p.first_name, p.last_name FROM kd_runs r JOIN kd_players p ON p.id = r.player_id');
}

/**
 * Ergänzt Läufe um Geschwindigkeit, Pace, Trend und Verbesserungspotenzial.
 * Verglichen wird immer auf derselben Strecke: mit der eigenen Bestzeit und mit der Bestzeit der Mannschaft.
 */
function kd_enrich(array $runs, array $allRuns): array
{
    $teamBest = [];
    $personalBest = [];
    $history = [];
    usort($allRuns, fn($a, $b) => [$a['run_date'], (int) $a['id']] <=> [$b['run_date'], (int) $b['id']]);
    foreach ($allRuns as $r) {
        $d = (int) $r['distance_m'];
        $p = $r['player_id'] . '|' . $d;
        $teamBest[$d] = min($teamBest[$d] ?? PHP_INT_MAX, (int) $r['seconds']);
        $personalBest[$p] = min($personalBest[$p] ?? PHP_INT_MAX, (int) $r['seconds']);
        $history[$p][] = $r;
    }
    foreach ($runs as &$r) {
        $d = (int) $r['distance_m'];
        $s = (int) $r['seconds'];
        $p = $r['player_id'] . '|' . $d;
        // Geschwindigkeit immer frisch aus Metern und Sekunden: m/s = Meter ÷ Sekunden, km/h = m/s × 3.6
        $r['kmh'] = $d / $s * 3.6;
        $r['pace'] = $s / ($d / 1000);
        $r['pb'] = $personalBest[$p];
        $r['team_best'] = $teamBest[$d];
        $r['to_pb'] = $s - $personalBest[$p];
        $r['to_team'] = $s - $teamBest[$d];
        $r['to_team_pct'] = ($s - $teamBest[$d]) / $s * 100;
        $r['prev'] = null;
        foreach ($history[$p] as $h) {
            if ([$h['run_date'], (int) $h['id']] < [$r['run_date'], (int) $r['id']]) {
                $r['prev'] = (int) $h['seconds'];
            }
        }
    }
    return $runs;
}

function kd_time(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function kd_distance(int $m): string
{
    return number_format($m, 0, '.', '’') . ' m';
}

function kd_date(?string $ymd): string
{
    return $ymd ? date('d.m.Y', strtotime($ymd)) : '';
}

function kd_kmh(float $kmh): string
{
    return number_format($kmh, 1, '.', '’') . ' km/h';
}

/** Zeit pro 1000 Meter. */
function kd_pace(float $pace): string
{
    return kd_time((int) round($pace)) . ' pro 1000 m';
}

/** Geschwindigkeit in Metern pro Sekunde. */
function kd_ms(float $kmh): string
{
    return number_format($kmh / 3.6, 2, '.', '’') . ' m/s';
}

/** Trend gegenüber dem letzten Lauf auf derselben Strecke. */
function kd_trend_html(array $r): string
{
    if ($r['prev'] === null) {
        return '<span class="muted">erster Lauf</span>';
    }
    $diff = (int) $r['seconds'] - $r['prev'];
    if ($diff === 0) {
        return '<span class="trend same">gleich</span>';
    }
    return $diff < 0
        ? '<span class="trend up">' . kd_time(-$diff) . ' schneller</span>'
        : '<span class="trend down">' . kd_time($diff) . ' langsamer</span>';
}

/** Verbesserungspotenzial: Abstand zur eigenen Bestzeit und zur Bestzeit der Mannschaft. */
function kd_potential_html(array $r): string
{
    if ($r['to_team'] === 0) {
        return '<span class="pill best">Bestzeit Mannschaft</span>';
    }
    $parts = [];
    $parts[] = $r['to_pb'] === 0
        ? '<span class="pill pb">eigene Bestzeit</span>'
        : '<span class="pot">' . kd_time($r['to_pb']) . '</span> <span class="muted">zur eigenen Bestzeit</span>';
    $parts[] = '<span class="pot">' . kd_time($r['to_team']) . ' (' . number_format($r['to_team_pct'], 1, '.', '') . ' %)</span> <span class="muted">zur Mannschaftsbesten</span>';
    return implode('<br>', $parts);
}

/** Rückmeldung des Trainerteams als Abzeichen (leer, wenn keine). */
function kd_feedback_html(?string $key): string
{
    if (!$key || !isset(KD_FEEDBACK[$key])) {
        return '';
    }
    [$icon, $text] = KD_FEEDBACK[$key];
    return '<div class="feedback fb-' . e($key) . '"><span class="fb-icon" aria-hidden="true">' . $icon . '</span>'
        . '<span><strong>' . e($text) . '</strong><small>vom Trainerteam</small></span></div>';
}

/** Einschätzung nach Abstand zur Mannschaftsbesten. */
function kd_potential_level(float $pct): array
{
    return match (true) {
        $pct <= 0.0 => ['best', 'Spitze'],
        $pct < 5 => ['low', 'klein'],
        $pct < 12 => ['mid', 'mittel'],
        default => ['high', 'gross'],
    };
}

// ---- Entwicklungskurve ---------------------------------------------------

/**
 * Liniendiagramm der Geschwindigkeit (km/h) über die Zeit, als SVG direkt in der Seite.
 * $series: Liste von ['label' => …, 'points' => [['date' => 'Y-m-d', 'kmh' => float, 'title' => …], …]]
 * Höher = schneller. Jede Linie bekommt eine Farbe aus der Palette (--c1 … --c8).
 */
function kd_chart(array $series, string $caption): string
{
    $series = array_values(array_filter($series, fn($s) => count($s['points']) > 0));
    if (!$series) {
        return '<p class="empty">Noch keine Läufe für eine Kurve.</p>';
    }
    $all = array_merge(...array_map(fn($s) => $s['points'], $series));
    $times = array_map(fn($p) => (int) strtotime($p['date']), $all);
    $speeds = array_map(fn($p) => (float) $p['kmh'], $all);
    [$t0, $t1] = [min($times), max($times)];
    if ($t1 - $t0 < 86400 * 6) { // mindestens eine Woche breit
        $t0 -= 86400 * 3;
        $t1 += 86400 * 3;
    }
    $step = max($speeds) - min($speeds) > 8 ? 2.0 : 1.0;
    $lo = floor((min($speeds) - 0.5) / $step) * $step;
    $hi = ceil((max($speeds) + 0.5) / $step) * $step;
    [$w, $h, $l, $r, $t, $b] = [720, 300, 48, 16, 22, 34];
    $x = fn(int $ts): float => $l + ($ts - $t0) / ($t1 - $t0) * ($w - $l - $r);
    $y = fn(float $v): float => $t + ($hi - $v) / ($hi - $lo) * ($h - $t - $b);
    $f = fn(float $n): string => number_format($n, 1, '.', '');
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($caption) . '">';
    for ($v = $lo; $v <= $hi + 0.001; $v += $step) {
        $svg .= '<line class="grid" x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . $f($y($v)) . '" y2="' . $f($y($v)) . '"/>'
            . '<text class="tick" x="' . ($l - 8) . '" y="' . $f($y($v) + 4) . '" text-anchor="end">' . (int) $v . '</text>';
    }
    $svg .= '<text class="tick" x="' . ($l - 8) . '" y="10" text-anchor="end">km/h</text>';
    $ticks = min(5, max(2, intdiv($t1 - $t0, 86400) + 1));
    for ($i = 0; $i < $ticks; $i++) {
        $ts = (int) ($t0 + ($t1 - $t0) * $i / ($ticks - 1));
        $anchor = $i === 0 ? 'start' : ($i === $ticks - 1 ? 'end' : 'middle');
        $svg .= '<text class="tick" x="' . $f($x($ts)) . '" y="' . ($h - 10) . '" text-anchor="' . $anchor . '">' . date('d.m.', $ts) . '</text>';
    }
    $legend = '';
    foreach ($series as $i => $s) {
        $c = 'var(--c' . ($i % 8 + 1) . ')';
        $pts = $s['points'];
        usort($pts, fn($a, $b) => strcmp($a['date'], $b['date']));
        $path = '';
        foreach ($pts as $j => $p) {
            $path .= ($j ? ' L' : 'M') . $f($x((int) strtotime($p['date']))) . ' ' . $f($y((float) $p['kmh']));
        }
        $svg .= '<path class="line" d="' . $path . '" style="stroke:' . $c . '"/>';
        foreach ($pts as $j => $p) {
            $last = $j === count($pts) - 1;
            $svg .= '<circle class="dot" cx="' . $f($x((int) strtotime($p['date']))) . '" cy="' . $f($y((float) $p['kmh'])) . '" r="' . ($last ? '5' : '3.5') . '" style="fill:' . $c . '">'
                . '<title>' . e($p['title']) . '</title></circle>';
        }
        $legend .= '<li><span class="swatch" style="background:' . $c . '"></span>' . e($s['label']) . '</li>';
    }
    $svg .= '</svg>';
    return '<figure class="chart-box"><div class="chart-scroll">' . $svg . '</div><figcaption><ul class="legend">' . $legend . '</ul><span class="hint">' . e($caption) . '</span></figcaption></figure>';
}

/** Punkte für die Kurve aus Läufen (ergänzt um kmh). */
function kd_chart_points(array $runs, bool $withName = false): array
{
    return array_map(fn($r) => ['date' => $r['run_date'], 'kmh' => (float) $r['kmh'],
        'title' => ($withName ? kd_player_name($r) . ': ' : '') . kd_date($r['run_date']) . ' · ' . kd_distance((int) $r['distance_m'])
            . ' in ' . kd_time((int) $r['seconds']) . ' · ' . kd_kmh((float) $r['kmh'])], $runs);
}

/** Kurve einer Spielerin: eine Linie pro Strecke. */
function kd_player_chart(array $runs): string
{
    $by = [];
    foreach ($runs as $r) {
        $by[(int) $r['distance_m']][] = $r;
    }
    ksort($by);
    $series = [];
    foreach ($by as $d => $list) {
        $series[] = ['label' => kd_distance($d), 'points' => kd_chart_points($list)];
    }
    return kd_chart($series, 'Geschwindigkeit pro Lauf, eine Linie pro Strecke. Höher heisst schneller.');
}

// ---- Infos: gelesen / ungelesen ------------------------------------------

/** Zeitpunkt, bis zu dem die Spielerin die Infos gelesen hat (frisch aus der Datenbank). */
function kd_infos_seen_at(int $playerId): string
{
    return (string) db_val('SELECT infos_seen_at FROM kd_players WHERE id = ?', [$playerId]);
}

/** Anzahl neuer oder geänderter Infos seit dem letzten Lesen. */
function kd_unread_infos(int $playerId): int
{
    return (int) db_val('SELECT COUNT(*) FROM kd_infos WHERE updated_at > ?', [kd_infos_seen_at($playerId)]);
}

function kd_mark_infos_read(int $playerId): void
{
    db_update('kd_players', ['infos_seen_at' => now()], 'id = ?', [$playerId]);
}

/** Popup-Fenster mit allen Infos für die Spielerin (öffnet sich automatisch, wenn es neue gibt). */
function kd_infos_dialog(array $player, int $unread, bool $autoOpen): string
{
    $seen = kd_infos_seen_at((int) $player['id']);
    $cards = '';
    foreach (db_all('SELECT * FROM kd_infos ORDER BY pinned DESC, updated_at DESC') as $i) {
        $cards .= kd_info_card($i, '', $i['updated_at'] > $seen);
    }
    $cards = $cards ?: '<p class="empty">Zurzeit gibt es keine Infos.</p>';
    return '<dialog id="infos-dialog" class="dialog" aria-labelledby="infos-title"' . ($autoOpen && $unread > 0 ? ' data-auto-open="1"' : '') . '>'
        . '<div class="dialog-head"><h2 id="infos-title">Infos vom Trainerteam</h2>'
        . '<form method="dialog"><button class="btn btn-ghost btn-small" type="submit" aria-label="Infos schliessen">Schliessen</button></form></div>'
        . '<div class="dialog-body">' . $cards . '</div></dialog>'
        . '<script>(function(){var d=document.getElementById("infos-dialog");if(!d||!d.showModal)return;'
        . 'var csrf=' . json_encode(kd_csrf()) . ',url=' . json_encode(kd_url('infos/gelesen')) . ',sent=false;'
        . 'function open(){d.showModal();if(sent)return;sent=true;'
        . 'var f=new FormData();f.append("_csrf",csrf);fetch(url,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){if(r.ok){document.querySelectorAll(".nav .badge").forEach(function(b){b.remove()})}}).catch(function(){});}'
        . 'document.querySelectorAll("[data-infos-open]").forEach(function(a){a.addEventListener("click",function(e){e.preventDefault();open()})});'
        . 'd.addEventListener("click",function(e){if(e.target===d)d.close()});'
        . 'if(d.dataset.autoOpen)open();})();</script>';
}

/** Version und Commit dezent in der Fusszeile. */
function kd_version_html(): string
{
    $v = kd_version();
    $parts = ['Version ' . $v['version']];
    if ($v['commit'] !== '') {
        $parts[] = 'Commit ' . $v['commit'];
    }
    if ($v['date'] !== '') {
        $parts[] = kd_date($v['date']);
    }
    return '<span class="version" title="Stand der App">' . e(implode(' · ', $parts)) . '</span>';
}

// ---- Seitengerüst --------------------------------------------------------

function kd_page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $nav = '';
    $link = fn(string $path, string $label) => '<a href="' . e(kd_url($path)) . '">' . e($label) . '</a>';
    if (kd_admin()) {
        $nav = $link('admin', 'Übersicht') . $link('admin/spielerinnen', 'Spielerinnen') . $link('admin/laeufe', 'Läufe')
            . $link('admin/nachrichten', 'Nachrichten') . $link('admin/infos', 'Infos') . $link('admin/admins', 'Admins') . $link('admin/einstellungen', 'Einstellungen');
    } elseif ($player = kd_player()) {
        $unread = kd_unread_infos((int) $player['id']);
        $badge = $unread > 0 ? '<span class="badge" aria-label="' . $unread . ' neue Infos">' . ($unread > 9 ? '9+' : $unread) . '</span>' : '';
        $nav = $link('', 'Lauf eintragen') . $link('meine', 'Meine Läufe')
            . '<a href="' . e(kd_url('infos')) . '" data-infos-open>Infos' . $badge . '</a>';
        $onInfosPage = str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/infos');
        $dialog = $onInfosPage ? '' : kd_infos_dialog($player, $unread, true);
    }
    $css = e(kd_url('assets/kondition.css')) . '?v=' . (int) @filemtime(__DIR__ . '/../assets/kondition.css');
    echo '<!doctype html><html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' · ' . e(kd_team()) . '</title>'
        . '<link rel="stylesheet" href="' . $css . '"></head><body>'
        . '<header class="top"><div class="wrap top-in"><a class="brand" href="' . e(kd_url(kd_admin() ? 'admin' : '')) . '"><span class="ball" aria-hidden="true"></span>'
        . '<span><strong>' . e(kd_team()) . '</strong><small>' . (kd_admin() ? 'Admin · Konditionstraining' : 'Konditionstraining') . '</small></span></a>'
        . ($nav !== '' ? '<nav class="nav">' . $nav . '</nav>' : '') . '</div></header>'
        . '<main class="wrap main">' . kd_take_flash() . $body . '</main>' . ($dialog ?? '')
        . '<footer class="wrap foot"><span>Die Angaben sind nur für die jeweilige Spielerin und das Trainerteam sichtbar.</span>' . kd_version_html() . '</footer>'
        . '</body></html>';
    exit;
}
