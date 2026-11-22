<?php
declare(strict_types=1);

// Sicherungen (Fullbackup) der App: täglich automatisch, auf dem Server im gesperrten Ordner data/backups/.
// Inhalt: alle Tabellen der App als JSON in einer ZIP-Datei. Wiederherstellen im Admin-Bereich.

const KD_BACKUP_TABLES = ['kd_settings', 'kd_admins', 'kd_players', 'kd_runs', 'kd_infos', 'kd_mail_log'];
const KD_BACKUP_KEEP = 30;                       // so viele Sicherungen bleiben auf dem Server
const KD_BACKUP_MAX_UPLOAD = 20 * 1024 * 1024;   // hochgeladene Sicherung höchstens 20 MB
const KD_BACKUP_NAME = '/^sicherung-\d{8}-\d{6}-[a-z]+\.zip$/';
const KD_BACKUP_KINDS = ['taeglich' => 'täglich (automatisch)', 'manuell' => 'von Hand', 'vorwiederherstellung' => 'vor Wiederherstellung', 'cron' => 'täglich (Cron)'];

function kd_backup_dir(): string
{
    $dir = KD_DATA_DIR . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_file(KD_DATA_DIR . '/.htaccess')) {
        @file_put_contents(KD_DATA_DIR . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/** Sicherungen, neueste zuerst. */
function kd_backup_list(): array
{
    $files = glob(kd_backup_dir() . '/sicherung-*.zip') ?: [];
    rsort($files);
    return array_map(function ($f) {
        preg_match('/^sicherung-(\d{8})-(\d{6})-([a-z]+)\.zip$/', basename($f), $m);
        return ['name' => basename($f), 'size' => (int) filesize($f), 'time' => DateTimeImmutable::createFromFormat('YmdHis', ($m[1] ?? '') . ($m[2] ?? ''))?->format('Y-m-d H:i:s') ?? '',
            'kind' => KD_BACKUP_KINDS[$m[3] ?? ''] ?? ($m[3] ?? '')];
    }, array_values(array_filter($files, fn($f) => preg_match(KD_BACKUP_NAME, basename($f)))));
}

/** Erstellt eine vollständige Sicherung und gibt den Dateinamen zurück. */
function kd_backup_create(string $kind = 'manuell'): string
{
    $tables = [];
    foreach (KD_BACKUP_TABLES as $t) {
        $tables[$t] = db_all("SELECT * FROM $t");
    }
    $name = 'sicherung-' . date('Ymd-His') . '-' . preg_replace('/[^a-z]/', '', $kind) . '.zip';
    $path = kd_backup_dir() . '/' . $name;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Die Sicherung konnte nicht geschrieben werden.');
    }
    $zip->addFromString('database.json', (string) json_encode(['app' => 'fussball', 'version' => kd_version()['version'], 'commit' => kd_version()['commit'],
        'created_at' => now(), 'kind' => $kind, 'tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->addFromString('LIESMICH.txt', "Sicherung des Fussball-Konditionstrainings vom " . date('d.m.Y H:i') . ".\n"
        . "Wiederherstellen: Admin-Bereich → Sicherungen → «Sicherung hochladen und wiederherstellen».\n");
    $zip->close();
    @chmod($path, 0600);
    kd_backup_prune();
    return $name;
}

function kd_backup_prune(): void
{
    foreach (array_slice(kd_backup_list(), KD_BACKUP_KEEP) as $old) {
        @unlink(kd_backup_dir() . '/' . $old['name']);
    }
}

/** Einmal pro Tag automatisch sichern (beim ersten Aufruf des Tages oder per Cron). Gibt den Namen zurück oder null. */
function kd_backup_daily(string $kind = 'taeglich'): ?string
{
    $dir = kd_backup_dir();
    if (kd_backup_today($dir)) {
        return null;
    }
    $lock = @fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return null; // läuft gerade in einem anderen Aufruf
    }
    try {
        if (kd_backup_today($dir)) {
            return null;
        }
        return kd_backup_create($kind);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function kd_backup_today(string $dir): bool
{
    $prefix = $dir . '/sicherung-' . date('Ymd') . '-';
    return (bool) (glob($prefix . '*-taeglich.zip') ?: glob($prefix . '*-cron.zip'));
}

/** Spalten, die eine Tabelle heute hat (nur diese werden beim Wiederherstellen übernommen). */
function kd_table_columns(string $table): array
{
    return db_driver() === 'sqlite'
        ? array_column(db_all("PRAGMA table_info($table)"), 'name')
        : array_column(db_all("SHOW COLUMNS FROM $table"), 'Field');
}

/** Stellt eine Sicherung wieder her. Vorher wird automatisch der aktuelle Stand gesichert. */
function kd_backup_restore(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Die Datei ist keine gültige Sicherung (ZIP).');
    }
    $json = $zip->getFromName('database.json');
    $zip->close();
    $data = $json !== false ? json_decode($json, true) : null;
    if (!is_array($data) || ($data['app'] ?? '') !== 'fussball' || !is_array($data['tables'] ?? null)) {
        throw new RuntimeException('Diese Datei ist keine Sicherung des Fussball-Konditionstrainings.');
    }
    $safety = kd_backup_create('vorwiederherstellung');
    $counts = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (KD_BACKUP_TABLES as $t) {
            if (!isset($data['tables'][$t]) || !is_array($data['tables'][$t])) {
                continue;
            }
            $cols = array_flip(kd_table_columns($t));
            $pdo->exec("DELETE FROM $t");
            foreach ($data['tables'][$t] as $row) {
                if (is_array($row) && ($clean = array_intersect_key($row, $cols))) {
                    db_insert($t, array_map(fn($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $clean));
                }
            }
            $counts[$t] = count($data['tables'][$t]);
        }
        $pdo->exec('DELETE FROM kd_sessions'); // alle neu anmelden
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Wiederherstellen fehlgeschlagen, es wurde nichts geändert: ' . $e->getMessage());
    }
    setting('__reset');
    return ['safety' => $safety, 'counts' => $counts, 'created_at' => (string) ($data['created_at'] ?? '')];
}

function kd_backup_size(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, '.', '') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

function kd_cron_key(): string
{
    if (setting('kd_cron_key') === '') {
        setting_set('kd_cron_key', bin2hex(random_bytes(16)));
    }
    return setting('kd_cron_key');
}

// ---- Seiten --------------------------------------------------------------

function kd_page_admin_backups(): never
{
    kd_require_main_admin();
    if (is_post()) {
        kd_csrf_check();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                kd_flash('ok', 'Sicherung erstellt: ' . kd_backup_create('manuell'));
            } elseif ($action === 'restore' || $action === 'upload') {
                if ($action === 'restore') {
                    $name = (string) ($_POST['datei'] ?? '');
                    if (!preg_match(KD_BACKUP_NAME, $name) || !is_file(kd_backup_dir() . '/' . $name)) {
                        throw new RuntimeException('Diese Sicherung gibt es nicht.');
                    }
                    $path = kd_backup_dir() . '/' . $name;
                } else {
                    $f = $_FILES['datei'] ?? null;
                    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                        throw new RuntimeException('Bitte eine Sicherungsdatei (.zip) auswählen.');
                    }
                    if ((int) $f['size'] > KD_BACKUP_MAX_UPLOAD) {
                        throw new RuntimeException('Die Datei ist zu gross (höchstens 20 MB).');
                    }
                    $path = (string) $f['tmp_name'];
                }
                $r = kd_backup_restore($path);
                kd_flash('ok', 'Sicherung vom ' . kd_date(substr($r['created_at'], 0, 10)) . ' wiederhergestellt ('
                    . (int) ($r['counts']['kd_players'] ?? 0) . ' Spielerinnen, ' . (int) ($r['counts']['kd_runs'] ?? 0) . ' Läufe). '
                    . 'Der vorherige Stand ist gesichert als ' . $r['safety'] . '. Bitte neu anmelden.');
                kd_redirect('admin/anmelden');
            }
        } catch (RuntimeException $ex) {
            kd_flash('error', $ex->getMessage());
        }
        kd_redirect('admin/sicherungen');
    }
    $rows = '';
    foreach (kd_backup_list() as $b) {
        $rows .= '<tr><td class="nowrap"><strong>' . e(kd_date(substr($b['time'], 0, 10))) . '</strong> <span class="muted">' . e(substr($b['time'], 11, 5)) . '</span></td>'
            . '<td>' . e($b['kind']) . '</td><td class="num">' . e(kd_backup_size($b['size'])) . '</td>'
            . '<td><div class="row-actions"><a class="btn btn-small btn-ghost" href="' . e(kd_url('admin/sicherungen/datei?name=' . rawurlencode($b['name']))) . '">Herunterladen</a>'
            . '<form method="post" class="inline">' . kd_csrf_field() . '<input type="hidden" name="datei" value="' . e($b['name']) . '">'
            . '<button class="btn btn-small btn-danger" name="action" value="restore" type="submit" onclick="return confirm(\'Diesen Stand wiederherstellen? Alle Daten werden durch die Sicherung ersetzt. Der jetzige Stand wird vorher automatisch gesichert.\')">Wiederherstellen</button></form>'
            . '</div></td></tr>';
    }
    $list = kd_backup_list();
    $last = $list[0] ?? null;
    $body = kd_head('Admin', 'Sicherungen', '<form method="post">' . kd_csrf_field() . '<button class="btn" name="action" value="create" type="submit">Jetzt sichern</button></form>')
        . '<section class="card"><p>Die App sichert <strong>jeden Tag automatisch alle Daten</strong> (Spielerinnen, Läufe, Feedback, Infos, Admins, Einstellungen) auf dem Server. '
        . 'Die letzten ' . KD_BACKUP_KEEP . ' Sicherungen bleiben erhalten. Vor jedem Wiederherstellen wird der aktuelle Stand zusätzlich gesichert.</p>'
        . '<p class="hint">' . ($last ? 'Letzte Sicherung: ' . e(kd_date(substr($last['time'], 0, 10))) . ' um ' . e(substr($last['time'], 11, 5)) . ' Uhr.' : 'Noch keine Sicherung vorhanden.') . '</p></section>'
        . '<section class="card"><h2>Gespeicherte Sicherungen</h2>'
        . ($rows ? '<div class="table-wrap"><table><thead><tr><th>Datum</th><th>Art</th><th class="num">Grösse</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<p class="empty">Noch keine Sicherung. Mit «Jetzt sichern» erstellst du die erste.</p>') . '</section>'
        . '<section class="card"><h2>Sicherung hochladen und wiederherstellen</h2>'
        . '<p>Zum Beispiel eine früher heruntergeladene Sicherung. Alle Daten werden durch die Sicherung ersetzt; danach melden sich alle neu an.</p>'
        . '<form method="post" enctype="multipart/form-data" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="upload">'
        . '<div class="field"><label for="datei">Sicherungsdatei (.zip)</label><input id="datei" name="datei" type="file" accept=".zip,application/zip" required></div>'
        . '<button class="btn btn-danger" type="submit" onclick="return confirm(\'Diese Sicherung wiederherstellen? Alle Daten werden ersetzt.\')">Hochladen und wiederherstellen</button></form></section>'
        . '<section class="card"><h2>Zusätzlich per Cron (freiwillig)</h2>'
        . '<p class="hint">Die tägliche Sicherung startet beim ersten Aufruf der App am Tag. Damit sie auch an Tagen ohne Nutzung läuft, kann im Infomaniak Manager unter «Cron-Aufgaben» täglich diese Adresse aufgerufen werden (geheim halten):</p>'
        . kd_copy_field('cron_url', kd_abs_url('cron?schluessel=' . kd_cron_key()), 'Cron-Adresse') . '</section>'
        . kd_copy_script();
    kd_page('Sicherungen', $body);
}

function kd_admin_backup_download(): never
{
    kd_require_main_admin();
    $name = (string) ($_GET['name'] ?? '');
    $path = kd_backup_dir() . '/' . $name;
    if (!preg_match(KD_BACKUP_NAME, $name) || !is_file($path)) {
        kd_flash('error', 'Diese Sicherung gibt es nicht.');
        kd_redirect('admin/sicherungen');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="fussball-' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** Cron-Aufruf: /cron?schluessel=… → tägliche Sicherung. */
function kd_cron(): never
{
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string) ($_GET['schluessel'] ?? '');
    if (setting('kd_cron_key') === '' || !hash_equals(setting('kd_cron_key'), $key)) {
        http_response_code(403);
        exit("Nicht erlaubt\n");
    }
    $name = kd_backup_daily('cron');
    exit(($name ? 'Sicherung erstellt: ' . $name : 'Heute bereits gesichert') . "\n");
}
