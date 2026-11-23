<?php
declare(strict_types=1);

// Fussball-Konditionstraining (Repository katharinadoenni/Fussball) – alle Seiten laufen über diesen Router.
// Spielerinnen: persönlicher Link → Lauf eintragen, Meine Läufe, Infos. Admins (Trainerteam): /admin/…

if (PHP_SAPI === 'cli-server') {
    // Lokaler Testserver: gleiche Regeln wie die .htaccess auf dem Server
    // (öffentliche Adresse /app/fussballtraining/…, CSS ausliefern, Code-Ordner sperren)
    $uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $rel = (string) preg_replace('#^/app/fussballtraining#', '', $uri);
    if (preg_match('#^/assets/([A-Za-z0-9._-]+\.(css|png|svg))$#', $rel, $m) && is_file(__DIR__ . '/assets/' . $m[1])) {
        header('Content-Type: ' . ['css' => 'text/css; charset=utf-8', 'png' => 'image/png', 'svg' => 'image/svg+xml'][$m[2]]);
        readfile(__DIR__ . '/assets/' . $m[1]);
        exit;
    }
    if (preg_match('#/(app|data|config\.php)(/|$)|/\.#', $rel)) {
        http_response_code(403);
        exit;
    }
}

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/pages.php';
require __DIR__ . '/app/admin.php';
require __DIR__ . '/app/import.php';
require __DIR__ . '/app/backup.php';

if (!app_installed()) {
    http_response_code(503);
    exit('Noch nicht eingerichtet.');
}
kd_migrate();
// Tägliche Sicherung beim ersten Aufruf des Tages (Fehler dürfen die App nie blockieren)
if (kd_ready()) {
    try {
        kd_backup_daily();
    } catch (Throwable $ex) {
        app_log('error', 'Tägliche Sicherung: ' . $ex->getMessage());
    }
}

// Sicherheits-Header: keine fremden Skripte, kein Einbetten in fremde Seiten, keine Suchmaschinen
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = kd_base();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

// Vor der Einrichtung führt alles zur Einrichtung
if (!kd_ready() && $path !== '/einrichten' && !str_starts_with($path, '/admin/einladung/')) {
    kd_redirect('einrichten');
}

$admin = ['/admin' => 'kd_page_admin_overview', '/admin/spielerinnen' => 'kd_page_admin_players', '/admin/laeufe' => 'kd_page_admin_runs',
    '/admin/nachrichten' => 'kd_page_admin_messages', '/admin/infos' => 'kd_page_admin_infos', '/admin/admins' => 'kd_page_admin_admins',
    '/admin/einstellungen' => 'kd_page_admin_settings', '/admin/export.csv' => 'kd_admin_export', '/admin/sicherung.json' => 'kd_admin_backup',
    '/admin/sicherungen' => 'kd_page_admin_backups', '/admin/sicherungen/datei' => 'kd_admin_backup_download',
    '/admin/import' => 'kd_page_admin_import', '/admin/import/vorlage.csv' => 'kd_admin_import_template'];

switch (true) {
    case $path === '/' || $path === '/index.php':
        is_post() ? kd_page_entry() : kd_page_start();
    case (bool) preg_match('#^/zugang/([A-Za-z0-9]+)$#', $path, $m):
        kd_page_access($m[1]);
    case $path === '/zugang' && is_post():
        kd_page_access_paste();
    case $path === '/manifest.webmanifest':
        kd_manifest();
    case $path === '/anleitung':
        kd_page_guide();
    case $path === '/meine':
        kd_page_mine();
    case $path === '/infos':
        kd_page_infos();
    case $path === '/infos/gelesen':
        kd_infos_mark_read();
    case $path === '/cron':
        kd_cron();
    case $path === '/einrichten':
        kd_page_setup();
    case $path === '/admin/anmelden':
        kd_page_admin_login();
    case (bool) preg_match('#^/admin/einladung/([a-f0-9]{48})$#', $path, $m):
        kd_page_admin_invite($m[1]);
    case $path === '/admin/abmelden' && is_post():
        kd_csrf_check();
        kd_logout();
        kd_flash('ok', 'Abgemeldet.');
        kd_redirect('admin/anmelden');
    case isset($admin[$path]):
        $admin[$path]();
    case $path === '/admin/spielerin/neu':
        kd_page_admin_player(null);
    case (bool) preg_match('#^/admin/spielerin/(\d+)$#', $path, $m):
        kd_page_admin_player((int) $m[1]);
    case (bool) preg_match('#^/admin/lauf/(\d+)$#', $path, $m):
        kd_admin_run((int) $m[1]);
}

kd_page('Seite nicht gefunden', '<section class="card narrow"><p class="eyebrow">Fehler 404</p><h1>Seite nicht gefunden</h1>'
    . '<p><a class="btn" href="' . e(kd_url()) . '">Zur Startseite</a></p></section>', 404);
