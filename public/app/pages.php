<?php
declare(strict_types=1);

// Seiten für Spielerinnen: persönlicher Link, Lauf eintragen, eigene Läufe mit Kurve, allgemeine Infos.
// Eine Spielerin sieht nur ihre eigenen Läufe und die Infos des Trainerteams.

function kd_page_start(): never
{
    if (kd_admin()) {
        kd_redirect('admin');
    }
    if (kd_player()) {
        kd_page_entry();
    }
    kd_page('Willkommen', '<section class="card narrow"><p class="eyebrow">Willkommen</p><h1>Bitte deinen persönlichen Link öffnen</h1>'
        . '<p class="lead">Jede Spielerin bekommt vom Trainerteam einen eigenen Link per E-Mail oder WhatsApp. Öffne ihn auf deinem Handy – danach bleibst du angemeldet.</p>'
        . '<form method="post" action="' . e(kd_url('zugang')) . '" class="stack mt"><div class="field"><label for="link">Oder den Link hier einfügen</label>'
        . '<input id="link" name="link" type="url" inputmode="url" autocomplete="off" placeholder="https://ki-smartwork.ch/app/fussballtraining/zugang/…" required></div>'
        . '<button class="btn" type="submit">Anmelden</button></form>'
        . '<p class="hint mt"><a href="' . e(kd_url('anleitung')) . '">App auf dem Handy speichern – so geht’s</a> · Trainerteam: <a href="' . e(kd_url('admin/anmelden')) . '">zur Anmeldung</a></p></section>');
}

/** Persönlichen Link einfügen (z.B. in der Handy-App ohne Anmeldung). */
function kd_page_access_paste(): never
{
    $raw = trim((string) ($_POST['link'] ?? ''));
    $token = preg_match('#(?:zugang/)?([a-f0-9]{48})\b#', $raw, $m) ? $m[1] : 'x';
    kd_page_access($token);
}

/** Anleitung: App auf dem Startbildschirm speichern (iPhone und Android). Öffentlich, ohne Anmeldung. */
function kd_guide_html(bool $short = false): string
{
    $iphone = '<ol class="steps"><li>Link antippen. Öffnet er sich in einer anderen App (z.B. Gmail), auf <strong>«In Safari öffnen»</strong> tippen.</li>'
        . '<li>Unten auf das <strong>Teilen-Symbol</strong> tippen (Quadrat mit Pfeil nach oben; auf neueren iPhones evtl. hinter <strong>«…»</strong>).</li>'
        . '<li>Nach unten wischen und <strong>«Zum Home-Bildschirm»</strong> wählen.</li>'
        . '<li>Namen lassen oder ändern und oben rechts auf <strong>«Hinzufügen»</strong> tippen.</li></ol>';
    $android = '<ol class="steps"><li>Link antippen – er öffnet sich in Chrome.</li>'
        . '<li>Oben rechts auf die <strong>drei Punkte ⋮</strong> tippen.</li>'
        . '<li><strong>«Zum Startbildschirm hinzufügen»</strong> oder <strong>«App installieren»</strong> wählen.</li>'
        . '<li>Auf <strong>«Hinzufügen»</strong> bzw. <strong>«Installieren»</strong> tippen.</li></ol>'
        . '<p class="hint">Samsung Internet: unten rechts <strong>Menü ☰ → «Seite hinzufügen zu» → «Startbildschirm»</strong>.</p>';
    return '<div class="guide"><section class="card"><h2><span class="os">iPhone</span> Safari</h2>' . $iphone . '</section>'
        . '<section class="card"><h2><span class="os">Android</span> Chrome</h2>' . $android . '</section></div>'
        . ($short ? '' : '<section class="card"><h2>Gut zu wissen</h2><ul class="tips">'
        . '<li><strong>Spielerinnen:</strong> zuerst den persönlichen Link aus E-Mail oder WhatsApp öffnen, dann speichern. Das Symbol öffnet danach direkt deinen Bereich.</li>'
        . '<li><strong>Trainerteam:</strong> die Anmeldeseite öffnen, anmelden und dann speichern.</li>'
        . '<li><strong>Abgemeldet?</strong> Den persönlichen Link nochmals öffnen oder auf der Startseite einfügen.</li>'
        . '<li><strong>Handy verloren?</strong> Dem Trainerteam Bescheid geben – es erstellt einen neuen Link, der alte funktioniert dann nicht mehr.</li>'
        . '<li><strong>Link nicht weitergeben</strong> – er ist nur für dich.</li></ul></section>');
}

function kd_page_guide(): never
{
    kd_page('App aufs Handy', '<section class="card"><p class="eyebrow">Anleitung</p><h1>App auf dem Handy speichern</h1>'
        . '<p class="lead">So erscheint das Konditionstraining als Symbol auf deinem Startbildschirm – wie eine richtige App.</p>'
        . '<div class="app-icon-row"><img src="' . e(kd_url('assets/icon-180.png')) . '" alt="" width="64" height="64"><span>So sieht das Symbol aus.</span></div></section>'
        . kd_guide_html());
}

/** Web-App-Manifest: Symbol, Farben und Startadresse (Spielerin → ihr persönlicher Link, Trainerteam → Admin). */
function kd_manifest(): never
{
    $start = kd_url('');
    if ($p = kd_player()) {
        $start = kd_url('zugang/' . $p['access_token']);
    } elseif (kd_admin()) {
        $start = kd_url('admin');
    }
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['name' => 'Konditionstraining · ' . kd_team(), 'short_name' => 'Kondition', 'lang' => 'de-CH',
        'start_url' => $start, 'scope' => kd_url(''), 'display' => 'standalone', 'background_color' => '#f2f5f8', 'theme_color' => '#1d394a',
        'icons' => [['src' => kd_url('assets/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => kd_url('assets/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => kd_url('assets/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kd_page_access(string $token): never
{
    if (kd_too_many('link', 20)) {
        kd_page('Zu viele Versuche', '<section class="card narrow"><h1>Zu viele Versuche</h1><p>Bitte in 15 Minuten nochmals probieren.</p></section>', 429);
    }
    $player = preg_match('/^[a-f0-9]{32,64}$/', $token) ? db_one('SELECT * FROM kd_players WHERE access_token = ? AND active = 1', [$token]) : null;
    if (!$player) {
        kd_attempt('link');
        kd_page('Link ungültig', '<section class="card narrow"><p class="eyebrow">Hoppla</p><h1>Dieser Link funktioniert nicht mehr</h1>'
            . '<p class="lead">Bitte das Trainerteam um einen neuen persönlichen Link.</p></section>', 404);
    }
    kd_login('player', (int) $player['id']);
    kd_redirect('');
}

function kd_birthday_banner(array $player): string
{
    $next = kd_next_birthday($player['birthday']);
    if (!$next || $next['days'] !== 0) {
        return '';
    }
    return '<section class="birthday"><span class="cake" aria-hidden="true">🎂</span><div><strong>Alles Gute zum ' . $next['age'] . '. Geburtstag, '
        . e($player['first_name']) . '!</strong><span>Das ganze Trainerteam gratuliert dir.</span></div></section>';
}

function kd_page_entry(): never
{
    $player = kd_require_player();
    $values = ['run_date' => date('Y-m-d'), 'distance_m' => '', 'minutes' => '', 'seconds' => '', 'note' => ''];
    $error = '';
    if (is_post()) {
        kd_csrf_check();
        kd_collect_dates($_POST, ['run_date']);
        $values = array_merge($values, array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, $values)));
        [$run, $error] = kd_validate_run($values);
        if ($error === null) {
            $run['player_id'] = (int) $player['id'];
            $run['created_at'] = now();
            db_insert('kd_runs', $run);
            kd_flash('ok', 'Gespeichert: ' . kd_distance($run['distance_m']) . ' in ' . kd_time($run['seconds']) . ' (' . kd_kmh($run['distance_m'] / $run['seconds'] * 3.6) . ').');
            kd_redirect('meine');
        }
    }
    $chips = '';
    foreach (KD_DISTANCES as $d) {
        $chips .= '<button type="button" class="chip" data-distance="' . $d . '">' . e(kd_distance($d)) . '</button>';
    }
    $body = kd_birthday_banner($player)
        . '<section class="card"><p class="eyebrow">Hallo ' . e($player['first_name']) . '</p><h1>Lauf eintragen</h1>'
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '')
        . '<form method="post" action="' . e(kd_url()) . '" id="run-form" class="stack">' . kd_csrf_field()
        . kd_date_field('run_date', 'Datum', kd_valid_ymd($values['run_date']) ? $values['run_date'] : null, true)
        . '<div class="field"><label for="distance_m">Länge der Strecke in Metern</label>'
        . '<div class="unit"><input id="distance_m" name="distance_m" type="number" inputmode="numeric" min="50" max="50000" step="1" required value="' . e((string) $values['distance_m']) . '" placeholder="z.B. 3200" aria-describedby="distance_hint"><span>m</span></div>'
        . '<span class="hint" id="distance_hint">Frei wählbar, immer in Metern (3 km = 3000 m). Abkürzungen:</span>'
        . '<div class="chips" role="group" aria-label="Häufige Strecken">' . $chips . '</div></div>'
        . '<fieldset class="field"><legend>Laufzeit</legend><div class="time-row">'
        . '<label class="unit"><input id="minutes" name="minutes" type="number" inputmode="numeric" min="0" max="360" step="1" value="' . e((string) $values['minutes']) . '" placeholder="0" aria-label="Minuten"><span>Min.</span></label>'
        . '<label class="unit"><input id="seconds" name="seconds" type="number" inputmode="numeric" min="0" max="59" step="1" value="' . e((string) $values['seconds']) . '" placeholder="0" aria-label="Sekunden"><span>Sek.</span></label>'
        . '</div></fieldset>'
        . '<div class="speed" id="speed" aria-live="polite"><div><span class="label">Geschwindigkeit</span><strong id="kmh">–</strong><span class="sub" id="ms"></span></div>'
        . '<div><span class="label">Zeit pro 1000 m</span><strong id="pace">–</strong></div></div>'
        . '<div class="field"><label for="note">Bemerkung <span class="muted">(freiwillig)</span></label><textarea id="note" name="note" maxlength="500" rows="3" placeholder="z.B. Gegenwind, müde Beine, Intervall">' . e((string) $values['note']) . '</textarea></div>'
        . '<button class="btn" type="submit">Lauf speichern</button></form></section>'
        . '<script>' . kd_entry_script() . '</script>';
    kd_page('Lauf eintragen', $body, $error ? 422 : 200);
}

function kd_entry_script(): string
{
    return <<<'JS'
(function () {
  var d = document.getElementById('distance_m'), m = document.getElementById('minutes'), s = document.getElementById('seconds');
  var kmh = document.getElementById('kmh'), ms = document.getElementById('ms'), pace = document.getElementById('pace');
  function fmt(sec) { sec = Math.round(sec); var h = Math.floor(sec / 3600), mm = Math.floor(sec % 3600 / 60), ss = sec % 60;
    return (h ? h + ':' + String(mm).padStart(2, '0') : mm) + ':' + String(ss).padStart(2, '0'); }
  function update() {
    var dist = parseInt(d.value, 10) || 0, t = (parseInt(m.value, 10) || 0) * 60 + (parseInt(s.value, 10) || 0);
    // Geschwindigkeit = Meter ÷ Sekunden (m/s); × 3.6 ergibt km/h
    if (dist >= 50 && t > 0) { kmh.textContent = (dist / t * 3.6).toFixed(1) + ' km/h'; ms.textContent = (dist / t).toFixed(2) + ' m/s'; pace.textContent = fmt(t / (dist / 1000)); }
    else { kmh.textContent = '–'; ms.textContent = dist > 0 && dist < 50 ? 'Bitte in Metern eingeben' : ''; pace.textContent = '–'; }
    document.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.distance === d.value ? 'true' : 'false'); });
  }
  document.querySelectorAll('.chip').forEach(function (c) { c.addEventListener('click', function () { d.value = c.dataset.distance; update(); m.focus(); }); });
  [d, m, s].forEach(function (el) { el.addEventListener('input', update); });
  update();
})();
JS;
}

function kd_page_mine(): never
{
    $player = kd_require_player();
    $all = kd_all_runs();
    $mine = array_values(array_filter($all, fn($r) => (int) $r['player_id'] === (int) $player['id']));
    usort($mine, fn($a, $b) => [$b['run_date'], (int) $b['id']] <=> [$a['run_date'], (int) $a['id']]);
    $mine = kd_enrich($mine, $all);
    $head = '<section class="card"><p class="eyebrow">Auswertung</p><h1>Meine Läufe</h1><p class="lead">' . e(kd_player_name($player)) . '</p></section>';
    if (!$mine) {
        kd_page('Meine Läufe', $head . '<section class="card"><p class="empty">Noch kein Lauf eingetragen. <a href="' . e(kd_url()) . '">Ersten Lauf eintragen</a></p></section>');
    }
    $body = kd_birthday_banner($player) . $head . kd_best_tiles($mine)
        . '<section class="card"><h2>Meine Entwicklung</h2>' . kd_player_chart($mine) . '</section>'
        . '<section class="card"><h2>Alle Läufe</h2>' . kd_runs_table($mine, false) . '</section>';
    kd_page('Meine Läufe', $body);
}

function kd_page_infos(): never
{
    $player = kd_require_player();
    $seen = kd_infos_seen_at((int) $player['id']);
    kd_mark_infos_read((int) $player['id']);
    $infos = db_all('SELECT * FROM kd_infos ORDER BY pinned DESC, updated_at DESC');
    $body = '<section class="card"><p class="eyebrow">Vom Trainerteam</p><h1>Infos</h1></section>';
    if (!$infos) {
        $body .= '<section class="card"><p class="empty">Zurzeit gibt es keine Infos.</p></section>';
    }
    foreach ($infos as $i) {
        $body .= kd_info_card($i, '', $i['updated_at'] > $seen);
    }
    $body .= kd_guide_card();
    kd_page('Infos', $body);
}

/** Popup geöffnet: Infos als gelesen markieren (rote Zahl verschwindet). */
function kd_infos_mark_read(): never
{
    $player = kd_player();
    if (!$player || !is_post()) {
        http_response_code(403);
        exit;
    }
    kd_csrf_check();
    kd_mark_infos_read((int) $player['id']);
    http_response_code(204);
    exit;
}

/** Kleine Karte mit Link zur Anleitung «App aufs Handy». */
function kd_guide_card(): string
{
    return '<article class="card info guide-card"><p class="eyebrow">Tipp</p><h2>App auf dem Handy speichern</h2>'
        . '<p>Mit einem Symbol auf dem Startbildschirm bist du mit einem Tipp in deinem Bereich.</p>'
        . '<p><a class="btn btn-ghost btn-small" href="' . e(kd_url('anleitung')) . '">Anleitung für iPhone und Android</a></p></article>';
}

function kd_info_card(array $i, string $actions = '', bool $new = false): string
{
    return '<article class="card info' . ($i['pinned'] ? ' pinned' : '') . ($new ? ' is-new' : '') . '">'
        . '<p class="eyebrow">' . ($new ? '<span class="new-tag">Neu</span> ' : '') . ($i['pinned'] ? 'Wichtig · ' : '') . e(kd_date(substr($i['updated_at'], 0, 10))) . '</p>'
        . '<h2>' . e($i['title']) . '</h2><div class="info-body">' . nl2br(e((string) $i['body'])) . '</div>' . $actions . '</article>';
}

/** Bestzeit je Strecke als Kacheln. */
function kd_best_tiles(array $runs): string
{
    $best = [];
    foreach ($runs as $r) {
        $d = (int) $r['distance_m'];
        if (!isset($best[$d]) || $r['seconds'] < $best[$d]['seconds']) {
            $best[$d] = $r;
        }
    }
    ksort($best);
    $html = '<section class="tiles" aria-label="Bestzeiten je Strecke">';
    foreach ($best as $d => $r) {
        $html .= '<div class="tile"><span class="label">' . e(kd_distance($d)) . '</span><strong>' . e(kd_time((int) $r['seconds'])) . '</strong>'
            . '<span class="muted">' . e(kd_kmh($r['kmh'])) . ' · ' . e(kd_date($r['run_date'])) . '</span>'
            . ($r['to_team'] > 0 ? '<span class="gap">' . e(kd_time($r['to_team'])) . ' bis zur Mannschaftsbesten</span>' : '<span class="gap best">Mannschaftsbestzeit</span>')
            . '</div>';
    }
    return $html . '</section>';
}

/** Tabelle der Läufe. Für Admins mit Namen, Feedback, Notiz und Löschen. */
function kd_runs_table(array $runs, bool $admin): string
{
    $rows = '';
    foreach ($runs as $r) {
        $notes = '';
        if ($r['note']) {
            $notes .= '<div class="note"><span class="label">Bemerkung</span> ' . e($r['note']) . '</div>';
        }
        if (!$admin) {
            $notes .= kd_feedback_html($r['feedback'] ?? null);
            if ($r['coach_note']) {
                $notes .= '<div class="note coach"><span class="label">Trainerteam</span> ' . e($r['coach_note']) . '</div>';
            }
        }
        $rows .= '<tr>'
            . '<td class="nowrap">' . e(kd_date($r['run_date'])) . '</td>'
            . ($admin ? '<td><a class="strong" href="' . e(kd_url('admin/spielerin/' . (int) $r['player_id'])) . '">' . e(kd_player_name($r)) . '</a></td>' : '')
            . '<td class="num">' . e(kd_distance((int) $r['distance_m'])) . '</td>'
            . '<td class="num"><strong>' . e(kd_time((int) $r['seconds'])) . '</strong></td>'
            . '<td class="num">' . e(kd_kmh($r['kmh'])) . '<div class="sub">' . e(kd_ms($r['kmh'])) . '<br>' . e(kd_pace($r['pace'])) . '</div></td>'
            . '<td>' . kd_trend_html($r) . '</td>'
            . '<td>' . kd_potential_html($r) . $notes . '</td>'
            . ($admin ? '<td>' . kd_admin_run_actions($r) . '</td>' : '')
            . '</tr>';
    }
    return '<div class="table-wrap"><table><thead><tr><th>Datum</th>' . ($admin ? '<th>Spielerin</th>' : '')
        . '<th class="num">Länge</th><th class="num">Laufzeit</th><th class="num">Geschwindigkeit</th><th>Trend</th><th>Verbesserungspotenzial</th>'
        . ($admin ? '<th>Feedback &amp; Notiz</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
}
