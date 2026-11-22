<?php
declare(strict_types=1);

// Admin-Bereich (Trainerteam): Einrichtung, Anmeldung, Einladung weiterer Admins, Übersicht mit Kurven,
// Spielerinnen-Verwaltung (Kontakt, Geburtstag, Pause, Notfallkontakt, persönlicher Link), Läufe mit Feedback,
// Nachrichten per E-Mail/WhatsApp, allgemeine Infos, Admins, Einstellungen und Sicherung.

// ---- Hilfen --------------------------------------------------------------

function kd_new_token(): string
{
    return bin2hex(random_bytes(24));
}

function kd_valid_date(string $ymd): bool
{
    return kd_valid_ymd($ymd);
}

function kd_head(string $eyebrow, string $title, string $right = ''): string
{
    return '<section class="head"><div><p class="eyebrow">' . e($eyebrow) . '</p><h1>' . e($title) . '</h1></div>' . $right . '</section>';
}

/** Copy-Feld mit Knopf «Kopieren». */
function kd_copy_field(string $id, string $value, string $label): string
{
    return '<div class="share"><input id="' . e($id) . '" type="text" readonly value="' . e($value) . '" aria-label="' . e($label) . '">'
        . '<button class="btn btn-ghost copy" type="button" data-copy="' . e($id) . '">Kopieren</button></div>';
}

function kd_copy_script(): string
{
    return '<script>document.querySelectorAll(".copy").forEach(function(b){b.addEventListener("click",function(){var i=document.getElementById(b.dataset.copy);'
        . 'function done(){b.textContent="Kopiert";setTimeout(function(){b.textContent="Kopieren"},2000)}'
        . 'if(navigator.clipboard){navigator.clipboard.writeText(i.value).then(done,function(){i.select()})}else{i.select();done()}})});</script>';
}

function kd_fill(string $text, array $player, array $admin): string
{
    $next = kd_next_birthday($player['birthday'] ?? null);
    return strtr($text, ['{vorname}' => $player['first_name'] ?? '', '{name}' => $player['last_name'] ?? '', '{team}' => kd_team(),
        '{admin}' => $admin['name'], '{alter}' => (string) ($next['age'] ?? ''), '{link}' => isset($player['access_token']) ? kd_player_link($player) : '']);
}

function kd_link_text(): string
{
    return "Hallo {vorname}\n\nHier ist dein persönlicher Link für das Konditionstraining von {team}:\n{link}\n\n"
        . "Öffne ihn auf deinem Handy. Danach kannst du deine Läufe eintragen, deine Entwicklung sehen und die Infos vom Trainerteam lesen. "
        . "Bitte gib den Link nicht weiter – er ist nur für dich.\n\nSportliche Grüsse\n{admin}";
}

function kd_birthday_text(): string
{
    return "Alles Gute zum {alter}. Geburtstag, {vorname}! 🎉\n\nWir wünschen dir einen tollen Tag und freuen uns aufs nächste Training.\n\n{admin} und das Trainerteam von {team}";
}

// ---- Einrichtung, Anmeldung, Einladung -----------------------------------

function kd_page_setup(): never
{
    if (kd_ready()) {
        kd_redirect('admin/anmelden');
    }
    // Erste Einrichtung nur für eingeloggte Administratorinnen der Hauptseite ki-smartwork.ch.
    $mainUser = kd_website_admin();
    if (!$mainUser) {
        kd_page('Einrichtung', '<section class="card narrow"><p class="eyebrow">Einrichtung</p><h1>Bitte zuerst anmelden</h1>'
            . '<p class="lead">Die erste Einrichtung ist nur möglich, wenn du im Admin-Bereich von ki-smartwork.ch angemeldet bist.</p>'
            . '<p><a class="btn" href="/admin/">Zum Admin von ki-smartwork.ch</a></p></section>');
    }
    $values = ['team' => '', 'name' => (string) $mainUser['name'], 'email' => (string) $mainUser['email']];
    $error = '';
    if (is_post()) {
        $values = ['team' => trim(input('team')), 'name' => trim(input('name')), 'email' => mb_strtolower(trim(input('email')))];
        $password = (string) ($_POST['password'] ?? '');
        $error = match (true) {
            $values['team'] === '' || mb_strlen($values['team']) > 80 => 'Bitte den Namen der Mannschaft angeben (höchstens 80 Zeichen).',
            $values['name'] === '' || mb_strlen($values['name']) > 120 => 'Bitte deinen Namen angeben.',
            !valid_email($values['email']) => 'Bitte eine gültige E-Mail-Adresse angeben.',
            ($p = password_problem($password)) !== null => $p,
            $password !== (string) ($_POST['password2'] ?? '') => 'Die beiden Passwörter stimmen nicht überein.',
            default => '',
        };
        if ($error === '') {
            setting_set('kd_team_name', $values['team']);
            $id = db_insert('kd_admins', ['name' => $values['name'], 'email' => $values['email'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'active' => 1, 'created_at' => now()]);
            kd_login('admin', $id);
            kd_flash('ok', 'Eingerichtet. Lege jetzt die Spielerinnen an.');
            kd_redirect('admin/spielerinnen');
        }
    }
    kd_page('Einrichtung', '<section class="card narrow"><p class="eyebrow">Einrichtung</p><h1>Konditionstraining einrichten</h1>'
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<form method="post" class="stack">'
        . '<div class="field"><label for="team">Mannschaft</label><input id="team" name="team" type="text" required maxlength="80" placeholder="z.B. FC Muster Girls" value="' . e($values['team']) . '"></div>'
        . '<div class="field"><label for="name">Dein Name</label><input id="name" name="name" type="text" required maxlength="120" value="' . e($values['name']) . '"></div>'
        . '<div class="field"><label for="email">Deine E-Mail (Anmeldung)</label><input id="email" name="email" type="email" required value="' . e($values['email']) . '"></div>'
        . '<div class="field"><label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required><span class="hint">Mindestens 10 Zeichen.</span></div>'
        . '<div class="field"><label for="password2">Passwort wiederholen</label><input id="password2" name="password2" type="password" autocomplete="new-password" minlength="10" required></div>'
        . '<button class="btn" type="submit">Einrichten</button></form></section>', $error ? 422 : 200);
}

function kd_page_admin_login(): never
{
    if (kd_admin()) {
        kd_redirect('admin');
    }
    $error = '';
    $email = '';
    if (is_post()) {
        $email = mb_strtolower(trim(input('email')));
        $admin = db_one('SELECT * FROM kd_admins WHERE email = ? AND active = 1 AND password_hash IS NOT NULL', [$email]);
        if (kd_too_many('password', 8)) {
            $error = 'Zu viele Versuche. Bitte in 15 Minuten nochmals probieren.';
        } elseif ($admin && password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'])) {
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                db_update('kd_admins', ['password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT)], 'id = ?', [$admin['id']]);
            }
            kd_login('admin', (int) $admin['id']);
            kd_redirect('admin');
        } else {
            kd_attempt('password');
            $error = 'E-Mail oder Passwort stimmt nicht.';
        }
    }
    kd_page('Admin-Anmeldung', '<section class="card narrow"><p class="eyebrow">Trainerteam</p><h1>Anmelden</h1>'
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<form method="post" action="' . e(kd_url('admin/anmelden')) . '" class="stack">'
        . '<div class="field"><label for="email">E-Mail</label><input id="email" name="email" type="email" autocomplete="username" required value="' . e($email) . '"></div>'
        . '<div class="field"><label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>'
        . '<button class="btn" type="submit">Anmelden</button></form>'
        . '<p class="hint mt">Passwort vergessen? Ein anderer Admin kann dir unter «Admins» einen neuen Einladungslink schicken.</p></section>', $error ? 401 : 200);
}

function kd_page_admin_invite(string $token): never
{
    $admin = db_one('SELECT * FROM kd_admins WHERE invite_hash = ? AND active = 1', [hash('sha256', $token)]);
    if (!$admin || $admin['invite_expires'] < now()) {
        kd_attempt('link');
        kd_page('Einladung ungültig', '<section class="card narrow"><h1>Diese Einladung ist nicht mehr gültig</h1>'
            . '<p class="lead">Bitte einen anderen Admin um einen neuen Einladungslink.</p></section>', 404);
    }
    $error = '';
    if (is_post()) {
        $password = (string) ($_POST['password'] ?? '');
        $error = password_problem($password) ?? ($password !== (string) ($_POST['password2'] ?? '') ? 'Die beiden Passwörter stimmen nicht überein.' : '');
        if ($error === '') {
            db_update('kd_admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'invite_hash' => null, 'invite_expires' => null], 'id = ?', [$admin['id']]);
            db_query("DELETE FROM kd_sessions WHERE kind = 'admin' AND ref_id = ?", [$admin['id']]);
            kd_login('admin', (int) $admin['id']);
            kd_flash('ok', 'Willkommen im Trainerteam, ' . $admin['name'] . '!');
            kd_redirect('admin');
        }
    }
    kd_page('Passwort festlegen', '<section class="card narrow"><p class="eyebrow">Einladung</p><h1>Hallo ' . e($admin['name']) . '</h1>'
        . '<p class="lead">Lege dein Passwort für den Admin-Bereich von ' . e(kd_team()) . ' fest. Anmelden kannst du dich danach mit ' . e($admin['email']) . '.</p>'
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<form method="post" class="stack">'
        . '<div class="field"><label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required><span class="hint">Mindestens 10 Zeichen.</span></div>'
        . '<div class="field"><label for="password2">Passwort wiederholen</label><input id="password2" name="password2" type="password" autocomplete="new-password" minlength="10" required></div>'
        . '<button class="btn" type="submit">Passwort speichern</button></form></section>', $error ? 422 : 200);
}

// ---- Übersicht -----------------------------------------------------------

function kd_page_admin_overview(): never
{
    $admin = kd_require_admin();
    $players = kd_players(true);
    $all = kd_all_runs();
    $enriched = kd_enrich($all, $all);
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $paused = array_filter($players, fn($p) => kd_on_pause($p));

    $body = kd_head('Admin', 'Übersicht', '<form method="post" action="' . e(kd_url('admin/abmelden')) . '">' . kd_csrf_field()
            . '<button class="btn btn-ghost btn-small" type="submit">Abmelden</button></form>')
        . '<section class="stats">'
        . '<div class="stat"><span class="label">Spielerinnen</span><strong>' . count($players) . '</strong></div>'
        . '<div class="stat"><span class="label">Läufe total</span><strong>' . count($all) . '</strong></div>'
        . '<div class="stat"><span class="label">Läufe letzte 7 Tage</span><strong>' . count(array_filter($all, fn($r) => $r['run_date'] >= $weekAgo)) . '</strong></div>'
        . '<div class="stat"><span class="label">In Pause</span><strong>' . count($paused) . '</strong></div></section>';

    // Geburtstage der nächsten 30 Tage
    $birthdays = [];
    foreach ($players as $p) {
        $n = kd_next_birthday($p['birthday']);
        if ($n && $n['days'] <= 30) {
            $birthdays[] = [$p, $n];
        }
    }
    usort($birthdays, fn($a, $b) => $a[1]['days'] <=> $b[1]['days']);
    $bd = '';
    foreach ($birthdays as [$p, $n]) {
        $when = $n['days'] === 0 ? '<span class="pill best">heute</span>' : ($n['days'] === 1 ? 'morgen' : 'in ' . $n['days'] . ' Tagen');
        $bd .= '<li class="row"><div><strong>' . e(kd_player_name($p)) . '</strong><span class="muted"> · ' . e(kd_date($n['date'])) . ' · wird ' . $n['age'] . '</span></div>'
            . '<div class="row-actions">' . $when . ($n['days'] === 0 ? kd_congratulate_buttons($p, $admin) : '') . '</div></li>';
    }
    $pz = '';
    foreach ($paused as $p) {
        $pz .= '<li class="row"><div><a class="strong" href="' . e(kd_url('admin/spielerin/' . (int) $p['id'])) . '">' . e(kd_player_name($p)) . '</a>'
            . '<span class="muted"> · ' . e($p['pause_reason'] ?: 'Pause') . '</span></div><span class="muted">'
            . ($p['pause_to'] ? 'bis ' . e(kd_date($p['pause_to'])) : 'offen') . '</span></li>';
    }
    $body .= '<div class="cols"><section class="card"><h2>Geburtstage</h2>'
        . ($bd ? '<ul class="list">' . $bd . '</ul>' : '<p class="muted">In den nächsten 30 Tagen hat niemand Geburtstag.</p>') . '</section>'
        . '<section class="card"><h2>Spielerpause</h2>' . ($pz ? '<ul class="list">' . $pz . '</ul>' : '<p class="muted">Zurzeit ist niemand in einer Pause.</p>') . '</section></div>';

    if (!$all) {
        $body .= '<section class="card"><h2>Noch keine Läufe</h2><p>Sobald die Spielerinnen über ihren persönlichen Link Läufe eintragen, erscheinen hier Kurven und Auswertungen. '
            . '<a href="' . e(kd_url('admin/spielerinnen')) . '">Zu den Spielerinnen</a></p></section>';
        kd_page('Übersicht', $body);
    }

    // Mannschaftskurve für eine Strecke (Standard: die häufigste)
    $count = array_count_values(array_map(fn($r) => (int) $r['distance_m'], $all));
    arsort($count);
    $distance = (int) ($_GET['strecke'] ?? array_key_first($count));
    if (!isset($count[$distance])) {
        $distance = (int) array_key_first($count);
    }
    $by = [];
    foreach ($enriched as $r) {
        if ((int) $r['distance_m'] === $distance) {
            $by[(int) $r['player_id']][] = $r;
        }
    }
    $series = [];
    foreach ($by as $runs) {
        $series[] = ['label' => kd_player_name($runs[0]), 'points' => kd_chart_points($runs, true)];
    }
    usort($series, fn($a, $b) => strcmp($a['label'], $b['label']));
    $opts = '';
    ksort($count);
    foreach (array_keys($count) as $d) {
        $opts .= '<option value="' . $d . '"' . ($d === $distance ? ' selected' : '') . '>' . e(kd_distance($d)) . '</option>';
    }
    $body .= '<section class="card"><div class="card-head"><h2>Entwicklung der Mannschaft</h2>'
        . '<form method="get" action="' . e(kd_url('admin')) . '" class="inline"><label for="strecke" class="sr-only">Strecke</label>'
        . '<select id="strecke" name="strecke" onchange="this.form.submit()">' . $opts . '</select><noscript><button class="btn btn-small">Zeigen</button></noscript></form></div>'
        . kd_chart($series, 'Geschwindigkeit über ' . kd_distance($distance) . ', eine Linie pro Spielerin. Höher heisst schneller.') . '</section>'
        . '<section class="card"><h2>Verbesserungspotenzial je Spielerin</h2>'
        . '<p class="hint">Letzter Lauf je Strecke, verglichen mit der Bestzeit der Mannschaft auf derselben Strecke. Sortiert nach grösstem Potenzial.</p>'
        . kd_potential_board($enriched) . '</section>';
    kd_page('Übersicht', $body);
}

function kd_congratulate_buttons(array $p, array $admin): string
{
    $text = kd_fill(kd_birthday_text(), $p, $admin);
    $html = kd_wa_button($p['phone'], $text, 'Gratulieren per WhatsApp');
    if ($p['email']) {
        $html .= '<form method="post" action="' . e(kd_url('admin/spielerin/' . (int) $p['id'])) . '" class="inline">' . kd_csrf_field()
            . '<button class="btn btn-small" name="action" value="gratulieren" type="submit">Gratulieren per E-Mail</button></form>';
    }
    return $html;
}

/** Pro Spielerin und Strecke der letzte Lauf mit Abstand zur Mannschaftsbesten. */
function kd_potential_board(array $enriched): string
{
    $latest = [];
    foreach ($enriched as $r) {
        $k = $r['player_id'] . '|' . $r['distance_m'];
        if (!isset($latest[$k]) || [$r['run_date'], (int) $r['id']] > [$latest[$k]['run_date'], (int) $latest[$k]['id']]) {
            $latest[$k] = $r;
        }
    }
    usort($latest, fn($a, $b) => $b['to_team_pct'] <=> $a['to_team_pct']);
    $rows = '';
    foreach ($latest as $r) {
        [$cls, $label] = kd_potential_level($r['to_team_pct']);
        $bar = max(2, min(100, $r['to_team_pct'] * 4));
        $rows .= '<tr><td><a class="strong" href="' . e(kd_url('admin/spielerin/' . (int) $r['player_id'])) . '">' . e(kd_player_name($r)) . '</a></td>'
            . '<td class="num">' . e(kd_distance((int) $r['distance_m'])) . '</td>'
            . '<td class="num">' . e(kd_time((int) $r['seconds'])) . '<div class="sub">' . e(kd_date($r['run_date'])) . '</div></td>'
            . '<td class="num">' . e(kd_time((int) $r['pb'])) . '</td>'
            . '<td>' . kd_trend_html($r) . '</td>'
            . '<td><div class="meter ' . $cls . '"><span style="width:' . number_format($bar, 1, '.', '') . '%"></span></div>'
            . '<span class="pill ' . $cls . '">' . e($label) . '</span> '
            . ($r['to_team'] > 0 ? '<span class="muted">' . e(kd_time($r['to_team'])) . ' · ' . number_format($r['to_team_pct'], 1, '.', '') . ' %</span>' : '')
            . '</td></tr>';
    }
    return '<div class="table-wrap"><table><thead><tr><th>Spielerin</th><th class="num">Strecke</th><th class="num">Letzter Lauf</th>'
        . '<th class="num">Eigene Bestzeit</th><th>Trend</th><th>Potenzial zur Mannschaftsbesten</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

// ---- Spielerinnen --------------------------------------------------------

function kd_page_admin_players(): never
{
    kd_require_admin();
    $players = kd_players();
    $counts = [];
    foreach (db_all('SELECT player_id, COUNT(*) AS n, MAX(run_date) AS last FROM kd_runs GROUP BY player_id') as $c) {
        $counts[(int) $c['player_id']] = $c;
    }
    $rows = '';
    foreach ($players as $p) {
        $n = kd_next_birthday($p['birthday']);
        $status = !$p['active'] ? '<span class="pill off">inaktiv</span>' : (kd_on_pause($p) ? '<span class="pill mid">Pause</span>' : '<span class="pill pb">aktiv</span>');
        $c = $counts[(int) $p['id']] ?? null;
        $rows .= '<tr><td><a class="strong" href="' . e(kd_url('admin/spielerin/' . (int) $p['id'])) . '">' . e(kd_player_name($p)) . '</a></td>'
            . '<td>' . $status . '</td>'
            . '<td class="nowrap">' . ($p['birthday'] ? e(kd_date($p['birthday'])) . '<div class="sub">' . kd_age($p['birthday']) . ' Jahre' . ($n && $n['days'] <= 14 ? ' · Geburtstag ' . ($n['days'] === 0 ? 'heute' : 'in ' . $n['days'] . ' T.') : '') . '</div>' : '<span class="muted">–</span>') . '</td>'
            . '<td>' . ($p['email'] ? e($p['email']) : '<span class="muted">keine E-Mail</span>') . '<div class="sub">' . e((string) $p['phone']) . '</div></td>'
            . '<td>' . ($p['emergency_name'] ? e($p['emergency_name']) . '<div class="sub">' . e((string) $p['emergency_relation']) . ' · ' . e((string) $p['emergency_phone']) . '</div>' : '<span class="muted">fehlt</span>') . '</td>'
            . '<td class="num">' . (int) ($c['n'] ?? 0) . ($c ? '<div class="sub">zuletzt ' . e(kd_date($c['last'])) . '</div>' : '') . '</td></tr>';
    }
    $body = kd_head('Admin', 'Spielerinnen', '<div class="row-actions"><a class="btn btn-ghost" href="' . e(kd_url('admin/import')) . '">Aus Excel importieren</a>'
            . '<a class="btn" href="' . e(kd_url('admin/spielerin/neu')) . '">Spielerin hinzufügen</a></div>')
        . '<section class="card">' . ($rows ? '<div class="table-wrap"><table><thead><tr><th>Name</th><th>Status</th><th>Geburtstag</th><th>Kontakt</th><th>Notfallkontakt</th><th class="num">Läufe</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>' : '<p class="empty">Noch keine Spielerinnen. Füge die erste hinzu – sie bekommt dann ihren persönlichen Link.</p>') . '</section>';
    kd_page('Spielerinnen', $body);
}

function kd_player_fields(): array
{
    return ['first_name', 'last_name', 'email', 'phone', 'birthday', 'emergency_name', 'emergency_relation', 'emergency_phone', 'emergency_email',
        'pause_from', 'pause_to', 'pause_reason', 'admin_note'];
}

function kd_validate_player(array $in): array
{
    $v = [];
    foreach (kd_player_fields() as $f) {
        $v[$f] = trim((string) ($in[$f] ?? ''));
    }
    $v['first_name'] = kd_clean_name($v['first_name']);
    $v['last_name'] = kd_clean_name($v['last_name']);
    $v['email'] = mb_strtolower($v['email']);
    $v['emergency_email'] = mb_strtolower($v['emergency_email']);
    $error = match (true) {
        $v['first_name'] === '' || $v['last_name'] === '' => 'Bitte Vorname und Name angeben.',
        mb_strlen($v['first_name']) > 60 || mb_strlen($v['last_name']) > 60 => 'Vorname und Name dürfen höchstens 60 Zeichen lang sein.',
        $v['email'] !== '' && !valid_email($v['email']) => 'Die E-Mail-Adresse der Spielerin ist ungültig.',
        $v['emergency_email'] !== '' && !valid_email($v['emergency_email']) => 'Die E-Mail-Adresse des Notfallkontakts ist ungültig.',
        $v['birthday'] !== '' && (!kd_valid_date($v['birthday']) || $v['birthday'] > date('Y-m-d')) => 'Bitte ein gültiges Geburtsdatum angeben.',
        $v['pause_from'] !== '' && !kd_valid_date($v['pause_from']) => 'Bitte ein gültiges Datum für den Beginn der Pause angeben.',
        $v['pause_to'] !== '' && (!kd_valid_date($v['pause_to']) || $v['pause_from'] === '') => 'Für das Ende der Pause braucht es auch einen Beginn.',
        $v['pause_to'] !== '' && $v['pause_to'] < $v['pause_from'] => 'Das Ende der Pause liegt vor dem Beginn.',
        mb_strlen($v['phone']) > 40 || mb_strlen($v['emergency_phone']) > 40 => 'Telefonnummern dürfen höchstens 40 Zeichen lang sein.',
        mb_strlen($v['emergency_name']) > 120 || mb_strlen($v['emergency_relation']) > 60 || mb_strlen($v['pause_reason']) > 200 => 'Ein Eintrag ist zu lang.',
        mb_strlen($v['admin_note']) > 1000 => 'Die interne Notiz darf höchstens 1000 Zeichen lang sein.',
        default => null,
    };
    foreach ($v as $k => $val) {
        if ($val === '' && !in_array($k, ['first_name', 'last_name'], true)) {
            $v[$k] = null;
        }
    }
    return [$v, $error];
}

function kd_page_admin_player(?int $id): never
{
    $admin = kd_require_admin();
    $player = $id ? db_one('SELECT * FROM kd_players WHERE id = ?', [$id]) : null;
    if ($id && !$player) {
        kd_flash('error', 'Diese Spielerin gibt es nicht mehr.');
        kd_redirect('admin/spielerinnen');
    }
    $error = '';
    $values = $player ?? array_fill_keys(kd_player_fields(), '');
    if (is_post()) {
        kd_csrf_check();
        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'save') {
            kd_collect_dates($_POST, ['birthday', 'pause_from', 'pause_to']);
            [$values, $error] = kd_validate_player($_POST);
            if ($error === null) {
                if ($player) {
                    db_update('kd_players', $values, 'id = ?', [$player['id']]);
                    kd_flash('ok', 'Angaben von ' . $values['first_name'] . ' gespeichert.');
                    kd_redirect('admin/spielerin/' . (int) $player['id']);
                }
                $newId = db_insert('kd_players', $values + ['access_token' => kd_new_token(), 'active' => 1, 'created_at' => now()]);
                kd_flash('ok', $values['first_name'] . ' ist angelegt. Schicke ihr jetzt den persönlichen Link.');
                kd_redirect('admin/spielerin/' . $newId);
            }
            $values += ['id' => $player['id'] ?? null];
        } elseif ($player) {
            kd_player_action($player, $action, $admin);
        }
    }
    $f = fn(string $name, string $label, string $type = 'text', string $extra = '') => '<div class="field"><label for="' . $name . '">' . $label . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . e((string) ($values[$name] ?? '')) . '"' . $extra . '></div>';
    $form = '<form method="post" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="save">'
        . '<h2>Person</h2><div class="grid-2">' . $f('first_name', 'Vorname', 'text', ' required maxlength="60"') . $f('last_name', 'Name', 'text', ' required maxlength="60"') . '</div>'
        . '<div class="grid-2">' . $f('email', 'E-Mail', 'email') . $f('phone', 'Handy (für WhatsApp)', 'tel', ' maxlength="40" placeholder="079 123 45 67"') . '</div>'
        . '<div class="date-narrow">' . kd_date_field('birthday', 'Geburtstag', $values['birthday'] ?? null) . '</div>'
        . '<h2 class="mt-lg">Notfallkontakt / Eltern</h2><div class="grid-2">' . $f('emergency_name', 'Name', 'text', ' maxlength="120"') . $f('emergency_relation', 'Beziehung', 'text', ' maxlength="60" placeholder="z.B. Mutter"') . '</div>'
        . '<div class="grid-2">' . $f('emergency_phone', 'Telefon', 'tel', ' maxlength="40"') . $f('emergency_email', 'E-Mail', 'email') . '</div>'
        . '<h2 class="mt-lg">Spielerpause</h2><p class="hint">z.B. Verletzung, Ferien, Schule. Leer lassen, wenn sie normal trainiert.</p>'
        . '<div class="grid-2">' . kd_date_field('pause_from', 'Von', $values['pause_from'] ?? null) . kd_date_field('pause_to', 'Bis (leer = offen)', $values['pause_to'] ?? null) . '</div>'
        . $f('pause_reason', 'Grund', 'text', ' maxlength="200"')
        . '<div class="field"><label for="admin_note">Interne Notiz <span class="muted">(sieht nur das Trainerteam)</span></label><textarea id="admin_note" name="admin_note" rows="3" maxlength="1000">' . e((string) ($values['admin_note'] ?? '')) . '</textarea></div>'
        . '<button class="btn" type="submit">' . ($player ? 'Speichern' : 'Spielerin anlegen') . '</button></form>';
    if (!$player) {
        kd_page('Neue Spielerin', kd_head('Spielerinnen', 'Neue Spielerin') . ($error ? '<p class="notice error">' . e($error) . '</p>' : '') . '<section class="card">' . $form . '</section>', $error ? 422 : 200);
    }

    $name = kd_player_name($player);
    $status = !$player['active'] ? '<span class="pill off">inaktiv</span>' : (kd_on_pause($player) ? '<span class="pill mid">in Pause</span>' : '<span class="pill pb">aktiv</span>');
    $post = fn(string $action, string $label, string $class = 'btn btn-small', string $confirm = '') => '<form method="post" class="inline">' . kd_csrf_field()
        . '<button class="' . $class . '" name="action" value="' . $action . '" type="submit"' . ($confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . e($label) . '</button></form>';

    // Persönlicher Link
    $link = '<section class="card"><h2>Persönlicher Link</h2><p>Mit diesem Link trägt ' . e($player['first_name']) . ' ihre Läufe ein und sieht nur ihren eigenen Bereich und die Infos.</p>'
        . kd_copy_field('player_link', kd_player_link($player), 'Persönlicher Link')
        . '<div class="row-actions mt">' . ($player['email'] ? $post('link_mail', 'Per E-Mail senden') : '')
        . kd_wa_button($player['phone'], kd_fill(kd_link_text(), $player, $admin), 'Per WhatsApp senden')
        . $post('link_new', 'Neuen Link erstellen', 'btn btn-small btn-ghost', 'Neuen Link erstellen? Der alte Link funktioniert danach nicht mehr.') . '</div>'
        . (!$player['email'] && !$player['phone'] ? '<p class="hint">Tipp: E-Mail oder Handynummer eintragen, dann kannst du den Link direkt senden.</p>' : '') . '</section>';

    // Geburtstag und Notfallkontakt
    $n = kd_next_birthday($player['birthday']);
    $side = '<section class="card"><h2>Geburtstag</h2>' . ($n ? '<p><strong>' . e(kd_date($player['birthday'])) . '</strong> · ' . kd_age($player['birthday']) . ' Jahre<br><span class="muted">'
            . ($n['days'] === 0 ? 'Heute ist ihr Geburtstag!' : 'Nächster Geburtstag in ' . $n['days'] . ' Tagen (' . e(kd_date($n['date'])) . ')') . '</span></p>'
            . '<div class="row-actions">' . kd_congratulate_buttons($player, $admin) . '</div>' : '<p class="muted">Kein Geburtstag eingetragen.</p>') . '</section>'
        . '<section class="card"><h2>Notfallkontakt</h2>' . ($player['emergency_name'] ? '<p><strong>' . e($player['emergency_name']) . '</strong>'
            . ($player['emergency_relation'] ? ' · ' . e($player['emergency_relation']) : '') . '<br>' . e((string) $player['emergency_phone'])
            . ($player['emergency_email'] ? '<br>' . e($player['emergency_email']) : '') . '</p>'
            . '<div class="row-actions">' . kd_wa_button($player['emergency_phone'], 'Hallo ' . $player['emergency_name'] . ', hier ist ' . $admin['name'] . ' vom Trainerteam ' . kd_team() . '. ', 'WhatsApp')
            . '<a class="btn btn-small" href="' . e(kd_url('admin/nachrichten?an=eltern&spielerin=' . (int) $player['id'])) . '">Info an Eltern</a></div>'
            : '<p class="muted">Kein Notfallkontakt eingetragen.</p>') . '</section>';

    $all = kd_all_runs();
    $mine = array_values(array_filter($all, fn($r) => (int) $r['player_id'] === (int) $player['id']));
    usort($mine, fn($a, $b) => [$b['run_date'], (int) $b['id']] <=> [$a['run_date'], (int) $a['id']]);
    $mine = kd_enrich($mine, $all);

    $body = kd_head('Spielerin', $name, '<div class="row-actions">' . $status . '</div>')
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<div class="cols">' . $link . '<div class="stack-gap">' . $side . '</div></div>'
        . '<section class="card"><h2>Entwicklung</h2>' . ($mine ? kd_player_chart($mine) : '<p class="muted">Noch keine Läufe.</p>') . '</section>'
        . ($mine ? '<section class="card"><h2>Läufe</h2>' . kd_runs_table($mine, true) . '</section>' : '')
        . '<section class="card"><h2>Angaben bearbeiten</h2>' . $form . '</section>'
        . '<section class="card danger-zone"><h2>Konto</h2><div class="row-actions">'
        . ($player['active'] ? $post('deactivate', 'Deaktivieren (Link sperren)', 'btn btn-small btn-ghost', 'Spielerin deaktivieren? Ihr Link funktioniert dann nicht mehr; die Läufe bleiben erhalten.')
            : $post('activate', 'Wieder aktivieren', 'btn btn-small'))
        . $post('delete', 'Spielerin und alle Läufe löschen', 'btn btn-small btn-danger', 'Wirklich löschen? Alle Läufe von ' . $name . ' werden ebenfalls gelöscht.')
        . '</div></section>' . kd_copy_script();
    kd_page($name, $body, $error ? 422 : 200);
}

function kd_player_action(array $player, string $action, array $admin): never
{
    $id = (int) $player['id'];
    switch ($action) {
        case 'link_mail':
            $ok = $player['email'] && kd_send_mail($player['email'], 'Dein Link für das Konditionstraining', kd_fill(kd_link_text(), $player, $admin), $admin);
            kd_flash($ok ? 'ok' : 'error', $ok ? 'Link per E-Mail an ' . $player['email'] . ' gesendet.' : 'Die E-Mail konnte nicht gesendet werden.');
            break;
        case 'link_new':
            db_update('kd_players', ['access_token' => kd_new_token()], 'id = ?', [$id]);
            db_query("DELETE FROM kd_sessions WHERE kind = 'player' AND ref_id = ?", [$id]);
            kd_flash('ok', 'Neuer Link erstellt. Der alte Link funktioniert nicht mehr – bitte den neuen senden.');
            break;
        case 'gratulieren':
            $ok = $player['email'] && kd_send_mail($player['email'], 'Alles Gute zum Geburtstag!', kd_fill(kd_birthday_text(), $player, $admin), $admin);
            kd_flash($ok ? 'ok' : 'error', $ok ? 'Geburtstagsgruss an ' . $player['first_name'] . ' gesendet.' : 'Die E-Mail konnte nicht gesendet werden.');
            break;
        case 'deactivate':
            db_update('kd_players', ['active' => 0], 'id = ?', [$id]);
            db_query("DELETE FROM kd_sessions WHERE kind = 'player' AND ref_id = ?", [$id]);
            kd_flash('ok', $player['first_name'] . ' ist deaktiviert. Ihr Link funktioniert nicht mehr.');
            break;
        case 'activate':
            db_update('kd_players', ['active' => 1], 'id = ?', [$id]);
            kd_flash('ok', $player['first_name'] . ' ist wieder aktiv.');
            break;
        case 'delete':
            db_query('DELETE FROM kd_runs WHERE player_id = ?', [$id]);
            db_query("DELETE FROM kd_sessions WHERE kind = 'player' AND ref_id = ?", [$id]);
            db_query('DELETE FROM kd_players WHERE id = ?', [$id]);
            kd_flash('ok', kd_player_name($player) . ' und alle Läufe wurden gelöscht.');
            kd_redirect('admin/spielerinnen');
    }
    $back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    kd_redirect($back === kd_url('admin') ? 'admin' : 'admin/spielerin/' . $id);
}

// ---- Läufe ---------------------------------------------------------------

function kd_filtered_runs(array $all): array
{
    kd_collect_dates($_GET, ['von', 'bis']);
    foreach (['von', 'bis'] as $k) {
        if (isset($_GET[$k]) && !kd_valid_ymd((string) $_GET[$k])) {
            $_GET[$k] = '';
        }
    }
    $player = (int) ($_GET['spielerin'] ?? 0);
    $distance = (int) ($_GET['strecke'] ?? 0);
    $from = (string) ($_GET['von'] ?? '');
    $to = (string) ($_GET['bis'] ?? '');
    $runs = array_filter($all, fn($r) => ($player === 0 || (int) $r['player_id'] === $player)
        && ($distance === 0 || (int) $r['distance_m'] === $distance)
        && ($from === '' || $r['run_date'] >= $from) && ($to === '' || $r['run_date'] <= $to));
    usort($runs, fn($a, $b) => [$b['run_date'], (int) $b['id']] <=> [$a['run_date'], (int) $a['id']]);
    return kd_enrich($runs, $all);
}

function kd_page_admin_runs(): never
{
    kd_require_admin();
    $all = kd_all_runs();
    $runs = kd_filtered_runs($all);
    $sel = fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
    $pOpts = '<option value="0">Alle Spielerinnen</option>';
    foreach (kd_players() as $p) {
        $pOpts .= '<option value="' . (int) $p['id'] . '"' . $sel($p['id'], $_GET['spielerin'] ?? '') . '>' . e(kd_player_name($p)) . '</option>';
    }
    $distances = array_unique(array_map(fn($r) => (int) $r['distance_m'], $all));
    sort($distances);
    $dOpts = '<option value="0">Alle Strecken</option>';
    foreach ($distances as $d) {
        $dOpts .= '<option value="' . $d . '"' . $sel($d, $_GET['strecke'] ?? '') . '>' . e(kd_distance($d)) . '</option>';
    }
    $query = http_build_query(array_intersect_key($_GET, array_flip(['spielerin', 'strecke', 'von', 'bis'])));
    $filter = '<form method="get" class="filters" action="' . e(kd_url('admin/laeufe')) . '">'
        . '<div class="field"><label for="f_p">Spielerin</label><select id="f_p" name="spielerin">' . $pOpts . '</select></div>'
        . '<div class="field"><label for="f_d">Strecke</label><select id="f_d" name="strecke">' . $dOpts . '</select></div>'
        . kd_date_field('von', 'Von', (string) ($_GET['von'] ?? '') ?: null)
        . kd_date_field('bis', 'Bis', (string) ($_GET['bis'] ?? '') ?: null)
        . '<div class="filter-actions"><button class="btn" type="submit">Filtern</button>'
        . '<a class="btn btn-ghost" href="' . e(kd_url('admin/export.csv') . ($query !== '' ? '?' . $query : '')) . '">Als CSV (Excel)</a></div></form>';
    kd_page('Läufe', kd_head('Admin', 'Läufe') . '<section class="card">' . $filter
        . ($runs ? kd_runs_table($runs, true) : '<p class="empty">Keine Läufe für diesen Filter.</p>') . '</section>');
}

function kd_admin_run_actions(array $r): string
{
    $id = (int) $r['id'];
    $buttons = '';
    foreach (KD_FEEDBACK as $key => [$icon, $text]) {
        $on = ($r['feedback'] ?? '') === $key;
        $buttons .= '<button type="submit" name="feedback" value="' . e($key) . '" class="fb-btn' . ($on ? ' on' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '" title="' . e($text) . '">'
            . '<span aria-hidden="true">' . $icon . '</span> ' . e($text) . '</button>';
    }
    return '<form method="post" action="' . e(kd_url('admin/lauf/' . $id)) . '" class="note-form">' . kd_csrf_field()
        . '<div class="fb-choices" role="group" aria-label="Feedback für die Spielerin">' . $buttons . '</div>'
        . '<label class="sr-only" for="cn' . $id . '">Notiz für die Spielerin</label>'
        . '<textarea id="cn' . $id . '" name="coach_note" rows="2" maxlength="500" placeholder="Notiz für die Spielerin">' . e((string) $r['coach_note']) . '</textarea>'
        . '<div class="row-actions"><button class="btn btn-small" name="action" value="note" type="submit">Notiz speichern</button>'
        . '<button class="btn btn-small btn-danger" name="action" value="delete" type="submit" formnovalidate '
        . 'onclick="return confirm(\'Diesen Lauf wirklich löschen?\')">Löschen</button></div></form>';
}

function kd_admin_run(int $id): never
{
    kd_require_admin();
    if (!is_post()) {
        kd_redirect('admin/laeufe');
    }
    kd_csrf_check();
    $run = db_one('SELECT r.*, p.first_name, p.last_name FROM kd_runs r JOIN kd_players p ON p.id = r.player_id WHERE r.id = ?', [$id]);
    if (!$run) {
        kd_flash('error', 'Dieser Lauf existiert nicht mehr.');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db_query('DELETE FROM kd_runs WHERE id = ?', [$id]);
        kd_flash('ok', 'Lauf von ' . kd_player_name($run) . ' vom ' . kd_date($run['run_date']) . ' gelöscht.');
    } else {
        $note = trim((string) ($_POST['coach_note'] ?? ''));
        $data = ['coach_note' => $note === '' ? null : mb_substr($note, 0, 500)];
        $feedback = (string) ($_POST['feedback'] ?? '');
        if (isset(KD_FEEDBACK[$feedback])) {
            // Nochmals auf dasselbe Feedback klicken nimmt es wieder weg
            $data['feedback'] = $run['feedback'] === $feedback ? null : $feedback;
            kd_flash('ok', $data['feedback'] === null ? 'Feedback für ' . $run['first_name'] . ' entfernt.'
                : 'Feedback «' . KD_FEEDBACK[$feedback][1] . '» an ' . kd_player_name($run) . ' gegeben.');
        } else {
            kd_flash('ok', 'Notiz gespeichert.');
        }
        db_update('kd_runs', $data, 'id = ?', [$id]);
    }
    // Zurück zur Seite, von der man kam (nur eigene Admin-Seiten)
    $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string) parse_url($back, PHP_URL_PATH);
    $query = (string) parse_url($back, PHP_URL_QUERY);
    if (preg_match('#^' . preg_quote(kd_url('admin/'), '#') . '(laeufe|spielerin/\d+)$#', $path, $m)) {
        kd_redirect('admin/' . $m[1] . ($query !== '' ? '?' . $query : ''));
    }
    kd_redirect('admin/laeufe');
}

function kd_admin_export(): never
{
    kd_require_admin();
    $runs = kd_filtered_runs(kd_all_runs());
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="konditionstraining-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // damit Excel die Umlaute richtig liest
    fputcsv($out, ['Datum', 'Vorname', 'Name', 'Länge (m)', 'Laufzeit', 'Sekunden', 'km/h', 'm/s', 'Zeit pro 1000 m', 'Eigene Bestzeit', 'Mannschaftsbestzeit',
        'Abstand zur eigenen Bestzeit (s)', 'Abstand zur Mannschaftsbesten (s)', 'Abstand zur Mannschaftsbesten (%)', 'Bemerkung', 'Feedback', 'Notiz Trainerteam'], ';', '"', '');
    foreach ($runs as $r) {
        fputcsv($out, [kd_date($r['run_date']), kd_csv_safe($r['first_name']), kd_csv_safe($r['last_name']), $r['distance_m'], kd_time((int) $r['seconds']), $r['seconds'],
            number_format($r['kmh'], 1, '.', ''), number_format($r['kmh'] / 3.6, 2, '.', ''), kd_time((int) round($r['pace'])), kd_time((int) $r['pb']), kd_time((int) $r['team_best']),
            $r['to_pb'], $r['to_team'], number_format($r['to_team_pct'], 1, '.', ''), kd_csv_safe((string) $r['note']),
            KD_FEEDBACK[$r['feedback'] ?? '-'][1] ?? '', kd_csv_safe((string) $r['coach_note'])], ';', '"', '');
    }
    fclose($out);
    exit;
}

/** Verhindert, dass Excel Texte wie «=…» als Formel ausführt. */
function kd_csv_safe(string $text): string
{
    return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
}

// ---- Nachrichten (E-Mail / WhatsApp) -------------------------------------

/** Empfänger je Spielerin: [Spielerin, Art, Name, E-Mail, Telefon]. */
function kd_recipients(array $playerIds, bool $players, bool $parents): array
{
    $list = [];
    foreach (kd_players(true) as $p) {
        if (!in_array((int) $p['id'], $playerIds, true)) {
            continue;
        }
        if ($players) {
            $list[] = ['player' => $p, 'kind' => 'Spielerin', 'name' => kd_player_name($p), 'email' => $p['email'], 'phone' => $p['phone']];
        }
        if ($parents && ($p['emergency_email'] || $p['emergency_phone'])) {
            $list[] = ['player' => $p, 'kind' => 'Eltern/Notfall von ' . $p['first_name'], 'name' => (string) $p['emergency_name'], 'email' => $p['emergency_email'], 'phone' => $p['emergency_phone']];
        }
    }
    return $list;
}

function kd_page_admin_messages(): never
{
    $admin = kd_require_admin();
    $players = kd_players(true);
    $toParents = ($_GET['an'] ?? '') === 'eltern';
    $preselect = (int) ($_GET['spielerin'] ?? 0);
    $v = ['to_players' => !$toParents, 'to_parents' => $toParents, 'ids' => $preselect ? [$preselect] : array_map(fn($p) => (int) $p['id'], $players),
        'subject' => '', 'text' => ''];
    $result = '';
    $error = '';
    if (is_post()) {
        kd_csrf_check();
        $v = ['to_players' => isset($_POST['to_players']), 'to_parents' => isset($_POST['to_parents']),
            'ids' => array_map('intval', array_filter((array) ($_POST['ids'] ?? []), 'is_string')),
            'subject' => trim(input('subject')), 'text' => trim((string) ($_POST['text'] ?? ''))];
        $recipients = kd_recipients($v['ids'], $v['to_players'], $v['to_parents']);
        $error = match (true) {
            !$v['to_players'] && !$v['to_parents'] => 'Bitte wählen, ob die Nachricht an Spielerinnen und/oder Eltern geht.',
            !$recipients => 'Für die Auswahl gibt es keine Empfängerinnen oder Empfänger mit Kontaktangaben.',
            $v['text'] === '' || mb_strlen($v['text']) > 5000 => 'Bitte einen Text schreiben (höchstens 5000 Zeichen).',
            ($_POST['action'] ?? '') === 'mail' && ($v['subject'] === '' || mb_strlen($v['subject']) > 150) => 'Bitte einen Betreff angeben (höchstens 150 Zeichen).',
            default => '',
        };
        if ($error === '') {
            $sent = 0;
            $failed = 0;
            $rows = '';
            foreach ($recipients as $r) {
                $text = kd_fill($v['text'], $r['player'], $admin);
                $status = '';
                if (($_POST['action'] ?? '') === 'mail') {
                    if ($r['email']) {
                        $ok = kd_send_mail($r['email'], kd_fill($v['subject'], $r['player'], $admin), $text, $admin);
                        $ok ? $sent++ : $failed++;
                        $status = $ok ? '<span class="pill pb">E-Mail gesendet</span>' : '<span class="pill high">E-Mail fehlgeschlagen</span>';
                    } else {
                        $status = '<span class="pill mid">keine E-Mail</span>';
                    }
                }
                $rows .= '<li class="row"><div><strong>' . e($r['name'] ?: $r['kind']) . '</strong><span class="muted"> · ' . e($r['kind']) . '</span></div>'
                    . '<div class="row-actions">' . $status . (kd_wa_button($r['phone'], $text, 'WhatsApp öffnen') ?: '<span class="muted">kein Handy</span>') . '</div></li>';
            }
            $result = '<section class="card"><h2>' . (($_POST['action'] ?? '') === 'mail' ? $sent . ' E-Mail(s) gesendet' . ($failed ? ', ' . $failed . ' fehlgeschlagen' : '') : 'Per WhatsApp senden') . '</h2>'
                . '<p class="hint">WhatsApp lässt sich nicht automatisch verschicken: Pro Person öffnet der Knopf WhatsApp mit dem fertigen Text – dort nur noch auf Senden tippen.</p>'
                . '<ul class="list">' . $rows . '</ul></section>';
        }
    }
    $checks = '';
    foreach ($players as $p) {
        $checks .= '<label class="check"><input type="checkbox" name="ids[]" value="' . (int) $p['id'] . '"' . (in_array((int) $p['id'], $v['ids'], true) ? ' checked' : '') . '> '
            . e(kd_player_name($p)) . (kd_on_pause($p) ? ' <span class="pill mid">Pause</span>' : '') . '</label>';
    }
    $body = kd_head('Admin', 'Nachrichten') . ($error ? '<p class="notice error">' . e($error) . '</p>' : '') . $result
        . '<section class="card"><form method="post" action="' . e(kd_url('admin/nachrichten')) . '" class="stack">' . kd_csrf_field()
        . '<div class="field"><span class="label-text">An</span><div class="row-actions">'
        . '<label class="check"><input type="checkbox" name="to_players"' . ($v['to_players'] ? ' checked' : '') . '> Spielerinnen</label>'
        . '<label class="check"><input type="checkbox" name="to_parents"' . ($v['to_parents'] ? ' checked' : '') . '> Eltern / Notfallkontakte</label></div></div>'
        . '<details class="field"' . ($preselect ? ' open' : '') . '><summary>Spielerinnen auswählen (' . count($v['ids']) . ' von ' . count($players) . ')</summary><div class="checklist">' . $checks . '</div></details>'
        . '<div class="field"><label for="subject">Betreff (für E-Mail)</label><input id="subject" name="subject" type="text" maxlength="150" value="' . e($v['subject']) . '" placeholder="z.B. Training am Samstag fällt aus"></div>'
        . '<div class="field"><label for="text">Nachricht</label><textarea id="text" name="text" rows="7" maxlength="5000" required>' . e($v['text']) . '</textarea>'
        . '<span class="hint">Platzhalter: {vorname} und {name} der Spielerin, {team}, {admin}. Antworten auf E-Mails gehen an ' . e($admin['email']) . '.</span></div>'
        . '<div class="row-actions"><button class="btn" name="action" value="mail" type="submit">Per E-Mail senden</button>'
        . '<button class="btn btn-ghost" name="action" value="whatsapp" type="submit">WhatsApp-Knöpfe anzeigen</button></div></form></section>';
    kd_page('Nachrichten', $body, $error ? 422 : 200);
}

// ---- Infos ---------------------------------------------------------------

function kd_page_admin_infos(): never
{
    $admin = kd_require_admin();
    $edit = isset($_GET['bearbeiten']) ? db_one('SELECT * FROM kd_infos WHERE id = ?', [(int) $_GET['bearbeiten']]) : null;
    $error = '';
    $v = ['title' => $edit['title'] ?? '', 'body' => $edit['body'] ?? '', 'pinned' => (int) ($edit['pinned'] ?? 0)];
    if (is_post()) {
        kd_csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        if (($_POST['action'] ?? '') === 'delete') {
            db_query('DELETE FROM kd_infos WHERE id = ?', [$id]);
            kd_flash('ok', 'Info gelöscht.');
            kd_redirect('admin/infos');
        }
        $v = ['title' => trim(input('title')), 'body' => trim((string) ($_POST['body'] ?? '')), 'pinned' => isset($_POST['pinned']) ? 1 : 0];
        $error = match (true) {
            $v['title'] === '' || mb_strlen($v['title']) > 150 => 'Bitte einen Titel angeben (höchstens 150 Zeichen).',
            mb_strlen($v['body']) > 10000 => 'Der Text ist zu lang.',
            default => '',
        };
        if ($error === '') {
            if ($id) {
                db_update('kd_infos', $v + ['updated_at' => now()], 'id = ?', [$id]);
            } else {
                db_insert('kd_infos', $v + ['admin_id' => (int) $admin['id'], 'created_at' => now(), 'updated_at' => now()]);
            }
            kd_flash('ok', 'Info gespeichert. Alle Spielerinnen sehen sie unter «Infos».');
            kd_redirect('admin/infos');
        }
    }
    $form = '<section class="card"><h2>' . ($edit ? 'Info bearbeiten' : 'Neue Info für alle Spielerinnen') . '</h2>'
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<form method="post" action="' . e(kd_url('admin/infos')) . '" class="stack">' . kd_csrf_field() . '<input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="field"><label for="title">Titel</label><input id="title" name="title" type="text" maxlength="150" required value="' . e($v['title']) . '" placeholder="z.B. Trainingszeiten Winter"></div>'
        . '<div class="field"><label for="body">Text</label><textarea id="body" name="body" rows="6" maxlength="10000">' . e($v['body']) . '</textarea></div>'
        . '<label class="check"><input type="checkbox" name="pinned"' . ($v['pinned'] ? ' checked' : '') . '> Als «Wichtig» oben anzeigen</label>'
        . '<div class="row-actions"><button class="btn" type="submit">Speichern</button>' . ($edit ? '<a class="btn btn-ghost" href="' . e(kd_url('admin/infos')) . '">Abbrechen</a>' : '') . '</div></form></section>';
    $list = '';
    foreach (db_all('SELECT * FROM kd_infos ORDER BY pinned DESC, created_at DESC') as $i) {
        $list .= kd_info_card($i, '<div class="row-actions mt"><a class="btn btn-small btn-ghost" href="' . e(kd_url('admin/infos?bearbeiten=' . (int) $i['id'])) . '">Bearbeiten</a>'
            . '<form method="post" action="' . e(kd_url('admin/infos')) . '" class="inline">' . kd_csrf_field() . '<input type="hidden" name="id" value="' . (int) $i['id'] . '">'
            . '<button class="btn btn-small btn-danger" name="action" value="delete" type="submit" onclick="return confirm(\'Diese Info löschen?\')">Löschen</button></form></div>');
    }
    kd_page('Infos', kd_head('Admin', 'Allgemeine Infos') . '<p class="hint">Diese Infos sehen alle Spielerinnen in ihrem Bereich unter «Infos».</p>'
        . $form . ($list ?: '<section class="card"><p class="empty">Noch keine Infos.</p></section>'), $error ? 422 : 200);
}

// ---- Admins --------------------------------------------------------------

function kd_page_admin_admins(): never
{
    $me = kd_require_admin();
    $inviteLink = '';
    $invited = null;
    if (is_post()) {
        kd_csrf_check();
        $action = (string) ($_POST['action'] ?? '');
        $target = db_one('SELECT * FROM kd_admins WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($action === 'invite') {
            $name = trim(input('name'));
            $email = mb_strtolower(trim(input('email')));
            $phone = mb_substr(trim(input('phone')), 0, 40);
            if ($name === '' || mb_strlen($name) > 120 || !valid_email($email)) {
                kd_flash('error', 'Bitte Name und eine gültige E-Mail-Adresse angeben.');
                kd_redirect('admin/admins');
            }
            if (db_val('SELECT COUNT(*) FROM kd_admins WHERE email = ?', [$email])) {
                kd_flash('error', 'Mit dieser E-Mail gibt es schon einen Admin.');
                kd_redirect('admin/admins');
            }
            $newId = db_insert('kd_admins', ['name' => $name, 'email' => $email, 'phone' => $phone ?: null, 'active' => 1, 'created_at' => now()]);
            $target = db_one('SELECT * FROM kd_admins WHERE id = ?', [$newId]);
            $action = 'reinvite';
        }
        if ($target && $action === 'reinvite') {
            $token = kd_new_token();
            db_update('kd_admins', ['invite_hash' => hash('sha256', $token), 'invite_expires' => date('Y-m-d H:i:s', time() + KD_INVITE_DAYS * 86400)], 'id = ?', [$target['id']]);
            $inviteLink = kd_abs_url('admin/einladung/' . $token);
            $invited = $target;
            if (isset($_POST['send_mail'])) {
                $ok = kd_send_mail($target['email'], 'Einladung: Admin für ' . kd_team(), "Hallo {$target['name']}\n\n{$me['name']} hat dich als Admin für das Konditionstraining von "
                    . kd_team() . " eingeladen.\n\nLege hier dein Passwort fest (Link " . KD_INVITE_DAYS . " Tage gültig):\n$inviteLink\n\nSportliche Grüsse\n{$me['name']}", $me);
                kd_flash($ok ? 'ok' : 'error', $ok ? 'Einladung an ' . $target['email'] . ' gesendet.' : 'Die E-Mail konnte nicht gesendet werden – bitte den Link unten selbst weitergeben.');
            }
        } elseif ($target && in_array($action, ['deactivate', 'delete'], true)) {
            $others = (int) db_val('SELECT COUNT(*) FROM kd_admins WHERE active = 1 AND password_hash IS NOT NULL AND id <> ?', [$target['id']]);
            if ((int) $target['id'] === (int) $me['id']) {
                kd_flash('error', 'Du kannst dich nicht selbst entfernen.');
            } elseif ($others < 1) {
                kd_flash('error', 'Es muss mindestens ein Admin mit Passwort bleiben.');
            } else {
                db_query("DELETE FROM kd_sessions WHERE kind = 'admin' AND ref_id = ?", [$target['id']]);
                $action === 'delete' ? db_query('DELETE FROM kd_admins WHERE id = ?', [$target['id']])
                    : db_update('kd_admins', ['active' => 0, 'invite_hash' => null], 'id = ?', [$target['id']]);
                kd_flash('ok', $target['name'] . ($action === 'delete' ? ' wurde entfernt.' : ' ist deaktiviert.'));
            }
            kd_redirect('admin/admins');
        } elseif ($target && $action === 'activate') {
            db_update('kd_admins', ['active' => 1], 'id = ?', [$target['id']]);
            kd_flash('ok', $target['name'] . ' ist wieder aktiv.');
            kd_redirect('admin/admins');
        }
    }
    $rows = '';
    foreach (db_all('SELECT * FROM kd_admins ORDER BY name') as $a) {
        $state = !$a['active'] ? '<span class="pill off">inaktiv</span>' : (!$a['password_hash'] ? '<span class="pill mid">eingeladen</span>' : '<span class="pill pb">aktiv</span>');
        $btn = fn(string $action, string $label, string $class, string $confirm = '') => '<form method="post" class="inline">' . kd_csrf_field() . '<input type="hidden" name="id" value="' . (int) $a['id'] . '">'
            . ($action === 'reinvite' ? '<input type="hidden" name="send_mail" value="1">' : '')
            . '<button class="' . $class . '" name="action" value="' . $action . '" type="submit"' . ($confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . e($label) . '</button></form>';
        $actions = (int) $a['id'] === (int) $me['id'] ? '<span class="muted">das bist du</span>'
            : $btn('reinvite', $a['password_hash'] ? 'Neues Passwort per Link' : 'Einladung erneut senden', 'btn btn-small btn-ghost')
                . ($a['active'] ? $btn('deactivate', 'Deaktivieren', 'btn btn-small btn-ghost', $a['name'] . ' deaktivieren?') : $btn('activate', 'Aktivieren', 'btn btn-small'))
                . $btn('delete', 'Entfernen', 'btn btn-small btn-danger', $a['name'] . ' als Admin entfernen?');
        $rows .= '<tr><td><strong>' . e($a['name']) . '</strong><div class="sub">' . e($a['email']) . ($a['phone'] ? ' · ' . e($a['phone']) : '') . '</div></td><td>' . $state . '</td>'
            . '<td class="nowrap">' . ($a['last_login_at'] ? e(kd_date(substr($a['last_login_at'], 0, 10))) : '<span class="muted">–</span>') . '</td><td><div class="row-actions">' . $actions . '</div></td></tr>';
    }
    $inviteBox = $invited ? '<section class="card highlight"><h2>Einladungslink für ' . e($invited['name']) . '</h2>'
        . '<p>Gültig ' . KD_INVITE_DAYS . ' Tage. Damit legt ' . e($invited['name']) . ' das eigene Passwort fest.</p>' . kd_copy_field('invite_link', $inviteLink, 'Einladungslink')
        . '<div class="row-actions mt">' . kd_wa_button($invited['phone'], 'Hallo ' . $invited['name'] . ', du bist jetzt Admin für ' . kd_team() . '. Lege hier dein Passwort fest: ' . $inviteLink, 'Per WhatsApp senden') . '</div></section>' : '';
    $body = kd_head('Admin', 'Admins') . $inviteBox
        . '<section class="card"><div class="table-wrap"><table><thead><tr><th>Admin</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
        . '<section class="card"><h2>Admin einladen</h2><p class="hint">Admins sehen alle Spielerinnen, Läufe und Kontaktdaten. Lade nur Personen aus dem Trainerteam ein.</p>'
        . '<form method="post" action="' . e(kd_url('admin/admins')) . '" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="invite">'
        . '<div class="grid-3"><div class="field"><label for="a_name">Name</label><input id="a_name" name="name" type="text" maxlength="120" required></div>'
        . '<div class="field"><label for="a_email">E-Mail</label><input id="a_email" name="email" type="email" required></div>'
        . '<div class="field"><label for="a_phone">Handy <span class="muted">(freiwillig)</span></label><input id="a_phone" name="phone" type="tel" maxlength="40"></div></div>'
        . '<label class="check"><input type="checkbox" name="send_mail" checked> Einladung gleich per E-Mail senden</label>'
        . '<button class="btn" type="submit">Einladen</button></form></section>' . kd_copy_script();
    kd_page('Admins', $body);
}

// ---- Einstellungen und Sicherung -----------------------------------------

function kd_page_admin_settings(): never
{
    $me = kd_require_admin();
    if (is_post()) {
        kd_csrf_check();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'team') {
            $team = trim(input('team'));
            if ($team === '' || mb_strlen($team) > 80) {
                kd_flash('error', 'Bitte den Namen der Mannschaft angeben (höchstens 80 Zeichen).');
            } else {
                setting_set('kd_team_name', $team);
                kd_flash('ok', 'Name der Mannschaft gespeichert.');
            }
        } elseif ($action === 'profile') {
            $name = trim(input('name'));
            if ($name === '' || mb_strlen($name) > 120) {
                kd_flash('error', 'Bitte deinen Namen angeben.');
            } else {
                db_update('kd_admins', ['name' => $name, 'phone' => mb_substr(trim(input('phone')), 0, 40) ?: null], 'id = ?', [$me['id']]);
                kd_flash('ok', 'Profil gespeichert.');
            }
        } elseif ($action === 'password') {
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['old_password'] ?? ''), (string) $me['password_hash'])) {
                kd_flash('error', 'Das bisherige Passwort stimmt nicht.');
            } elseif (($p = password_problem($new)) !== null) {
                kd_flash('error', $p);
            } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
                kd_flash('error', 'Die beiden neuen Passwörter stimmen nicht überein.');
            } else {
                db_update('kd_admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$me['id']]);
                db_query("DELETE FROM kd_sessions WHERE kind = 'admin' AND ref_id = ?", [$me['id']]);
                kd_login('admin', (int) $me['id']);
                kd_flash('ok', 'Passwort geändert. Deine anderen Anmeldungen wurden abgemeldet.');
            }
        } elseif ($action === 'logout_players') {
            db_query("DELETE FROM kd_sessions WHERE kind = 'player'");
            kd_flash('ok', 'Alle Spielerinnen wurden auf ihren Geräten abgemeldet. Ihre Links funktionieren weiterhin.');
        }
        kd_redirect('admin/einstellungen');
    }
    $body = kd_head('Admin', 'Einstellungen')
        . '<section class="card"><h2>Mannschaft</h2><form method="post" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="team">'
        . '<div class="field"><label for="team">Name der Mannschaft</label><input id="team" name="team" type="text" maxlength="80" required value="' . e(setting('kd_team_name')) . '"></div>'
        . '<button class="btn" type="submit">Speichern</button></form></section>'
        . '<section class="card"><h2>Mein Profil</h2><form method="post" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="profile">'
        . '<div class="grid-2"><div class="field"><label for="p_name">Name</label><input id="p_name" name="name" type="text" maxlength="120" required value="' . e($me['name']) . '"></div>'
        . '<div class="field"><label for="p_phone">Handy</label><input id="p_phone" name="phone" type="tel" maxlength="40" value="' . e((string) $me['phone']) . '"></div></div>'
        . '<p class="hint">Anmelde-E-Mail: ' . e($me['email']) . '</p><button class="btn" type="submit">Speichern</button></form></section>'
        . '<section class="card"><h2>Passwort ändern</h2><form method="post" class="stack">' . kd_csrf_field() . '<input type="hidden" name="action" value="password">'
        . '<div class="field"><label for="old_password">Bisheriges Passwort</label><input id="old_password" name="old_password" type="password" autocomplete="current-password" required></div>'
        . '<div class="grid-2"><div class="field"><label for="new_password">Neues Passwort</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="10" required></div>'
        . '<div class="field"><label for="new_password2">Wiederholen</label><input id="new_password2" name="new_password2" type="password" autocomplete="new-password" minlength="10" required></div></div>'
        . '<button class="btn" type="submit">Passwort ändern</button></form></section>'
        . '<section class="card"><h2>Sicherheit &amp; Sicherung</h2>'
        . '<p>Alle Daten (Spielerinnen, Läufe, Infos, Admins) sind in der täglichen automatischen Sicherung von ki-smartwork.ch enthalten. Zusätzlich kannst du hier jederzeit eine Sicherung herunterladen.</p>'
        . '<div class="row-actions"><a class="btn btn-ghost" href="' . e(kd_url('admin/sicherung.json')) . '">Sicherung herunterladen (JSON)</a>'
        . '<form method="post" class="inline">' . kd_csrf_field() . '<button class="btn btn-ghost" name="action" value="logout_players" type="submit" onclick="return confirm(\'Alle Spielerinnen abmelden?\')">Alle Spielerinnen abmelden</button></form></div>'
        . '<p class="hint mt">Wenn ein Handy verloren geht: bei der Spielerin «Neuen Link erstellen». Wenn ein Admin das Team verlässt: unter «Admins» deaktivieren.</p></section>';
    kd_page('Einstellungen', $body);
}

function kd_admin_backup(): never
{
    $me = kd_require_admin();
    $data = ['created_at' => now(), 'created_by' => $me['email'], 'team' => kd_team(), 'tables' => []];
    foreach (['kd_players', 'kd_runs', 'kd_infos'] as $t) {
        $data['tables'][$t] = db_all("SELECT * FROM $t");
    }
    $data['tables']['kd_admins'] = db_all('SELECT id, name, email, phone, active, created_at FROM kd_admins'); // ohne Passwörter
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="konditionstraining-sicherung-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
