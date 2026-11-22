<?php
declare(strict_types=1);

// Import bestehender Spielerdaten aus Excel (.xlsx) oder CSV.
// Ablauf: Datei hochladen → Vorschau (neu / wird ergänzt / Fehler) → «Jetzt importieren».
// Bestehende Spielerinnen (gleicher Vor- und Nachname) werden ergänzt: leere Zellen überschreiben nichts.

const KD_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const KD_IMPORT_MAX_ROWS = 500;

/** Spalten der Vorlage: Feld => Überschrift. */
function kd_import_columns(): array
{
    return ['first_name' => 'Vorname', 'last_name' => 'Name', 'email' => 'E-Mail', 'phone' => 'Handy', 'birthday' => 'Geburtstag',
        'emergency_name' => 'Notfallkontakt', 'emergency_relation' => 'Beziehung', 'emergency_phone' => 'Notfall-Telefon', 'emergency_email' => 'Notfall-E-Mail'];
}

/** Erkennt Spaltenüberschriften, auch in anderer Schreibweise (z.B. «Nachname», «Natel», «Geburtsdatum», «Mutter»). */
function kd_import_field(string $header): ?string
{
    $h = str_replace(['ä', 'ö', 'ü'], ['ae', 'oe', 'ue'], mb_strtolower(trim($header)));
    $h = (string) preg_replace('/[^a-z]/', '', $h);
    $map = [
        'first_name' => ['vorname', 'firstname', 'rufname'],
        'last_name' => ['name', 'nachname', 'familienname', 'lastname', 'surname'],
        'email' => ['email', 'mail', 'emailadresse', 'emailspielerin'],
        'phone' => ['handy', 'telefon', 'tel', 'mobile', 'mobil', 'natel', 'handynummer', 'telefonnummer', 'handyspielerin'],
        'birthday' => ['geburtstag', 'geburtsdatum', 'geboren', 'birthday', 'gebdatum'],
        'emergency_name' => ['notfallkontakt', 'notfall', 'notfallname', 'eltern', 'elternteil', 'erziehungsberechtigte', 'kontaktperson', 'mutter', 'vater'],
        'emergency_relation' => ['beziehung', 'verhaeltnis', 'rolle', 'notfallbeziehung'],
        'emergency_phone' => ['notfalltelefon', 'notfallhandy', 'telefoneltern', 'handyeltern', 'notfallnummer', 'notfalltel'],
        'emergency_email' => ['notfallemail', 'emaileltern', 'mailEltern', 'notfallmail', 'elternemail'],
    ];
    foreach ($map as $field => $names) {
        if (in_array($h, array_map('strtolower', $names), true)) {
            return $field;
        }
    }
    return null;
}

// ---- Dateien lesen -------------------------------------------------------

/** Liest die erste Tabelle einer .xlsx-Datei als Liste von Zeilen (ohne Zusatzbibliothek). */
function kd_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Die Excel-Datei lässt sich nicht öffnen.');
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sx->si ?? [] as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }
    }
    // Erste Tabelle über workbook.xml + Verweise finden (Fallback: sheet1.xml)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $first = $wbx?->xpath('//*[local-name()="sheet"]')[0] ?? null;
        $rid = $first ? (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '') : '';
        foreach (simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET)?->xpath('//*[local-name()="Relationship"]') ?: [] as $r) {
            if ((string) $r['Id'] === $rid) {
                $target = ltrim((string) $r['Target'], '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('In der Excel-Datei wurde keine Tabelle gefunden.');
    }
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($sx?->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $cells = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
            preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $type = (string) $c['t'];
            $v = (string) ($c->xpath('./*[local-name()="v"]')[0] ?? '');
            $value = match ($type) {
                's' => $shared[(int) $v] ?? '',
                'inlineStr' => implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]') ?: [])),
                default => $v,
            };
            $cells[$col - 1] = $value;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
        }
        if (count($rows) > KD_IMPORT_MAX_ROWS + 1) {
            break;
        }
    }
    return $rows;
}

/** Liest eine CSV-Datei (Trennzeichen ; oder , – auch aus Excel mit Windows-Umlauten). */
function kd_read_csv(string $path): array
{
    $raw = (string) file_get_contents($path);
    $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($raw, "\n") ?: '';
    $delim = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false && count($rows) <= KD_IMPORT_MAX_ROWS + 1) {
        $rows[] = array_map(fn($v) => (string) $v, $r);
    }
    fclose($fh);
    return $rows;
}

/** Datum aus Excel: Seriennummer (z.B. 40210), «14.03.2010», «14.3.10» oder «2010-03-14». */
function kd_import_date(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{4,5}(\.0+)?$/', $v)) {
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $v . ' days')->format('Y-m-d');
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y > (int) date('y') ? 1900 : 2000;
        }
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : 'ungültig';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : 'ungültig';
    }
    return 'ungültig';
}

/** Telefonnummer aus Excel: verlorene führende 0 ergänzen (791234567 → 0791234567). */
function kd_import_phone(string $v): string
{
    $v = trim((string) preg_replace('/\.0+$/', '', trim($v)));
    return ctype_digit($v) && strlen($v) === 9 ? '0' . $v : $v;
}

/** Zeilen → geprüfte Datensätze mit Status (neu / ergänzen / Fehler). */
function kd_import_rows(array $rows): array
{
    $header = array_shift($rows) ?? [];
    $fields = array_map(fn($h) => kd_import_field((string) $h), $header);
    if (!in_array('first_name', $fields, true) && !in_array('last_name', $fields, true)) {
        throw new RuntimeException('In der ersten Zeile fehlen die Überschriften «Vorname» und «Name». Am einfachsten die Vorlage verwenden.');
    }
    $existing = [];
    foreach (kd_players() as $p) {
        $existing[mb_strtolower(kd_player_name($p))] = $p;
    }
    $out = [];
    foreach ($rows as $i => $row) {
        $data = [];
        foreach ($fields as $col => $field) {
            if ($field !== null && !isset($data[$field])) {
                $data[$field] = trim((string) ($row[$col] ?? ''));
            }
        }
        if (implode('', $data) === '') {
            continue; // leere Zeile
        }
        // Nur eine Spalte «Name» mit «Vorname Nachname»
        if (($data['first_name'] ?? '') === '' && str_contains($data['last_name'] ?? '', ' ')) {
            [$data['first_name'], $data['last_name']] = explode(' ', $data['last_name'], 2);
        }
        $data['birthday'] = kd_import_date($data['birthday'] ?? '');
        $data['phone'] = kd_import_phone($data['phone'] ?? '');
        $data['emergency_phone'] = kd_import_phone($data['emergency_phone'] ?? '');
        [$clean, $error] = kd_validate_player($data);
        $key = mb_strtolower($clean['first_name'] . ' ' . $clean['last_name']);
        $out[] = ['line' => $i + 2, 'data' => $clean, 'error' => $error, 'existing_id' => isset($existing[$key]) ? (int) $existing[$key]['id'] : null];
    }
    if (count($out) > KD_IMPORT_MAX_ROWS) {
        throw new RuntimeException('Höchstens ' . KD_IMPORT_MAX_ROWS . ' Spielerinnen pro Import.');
    }
    return $out;
}

// ---- Seiten --------------------------------------------------------------

function kd_import_sign(string $payload): string
{
    return hash_hmac('sha256', 'import|' . kd_csrf() . '|' . $payload, setting('kd_secret'));
}

function kd_page_admin_import(): never
{
    kd_require_admin();
    $error = '';
    $preview = '';
    if (is_post()) {
        kd_csrf_check();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'import') {
            $payload = (string) ($_POST['payload'] ?? '');
            if (!hash_equals(kd_import_sign($payload), (string) ($_POST['sig'] ?? ''))) {
                kd_flash('error', 'Die Vorschau ist abgelaufen. Bitte die Datei nochmals hochladen.');
                kd_redirect('admin/import');
            }
            $items = json_decode((string) base64_decode($payload, true), true) ?: [];
            [$new, $updated] = [0, 0];
            foreach ($items as $item) {
                $d = array_intersect_key((array) $item['data'], array_flip(kd_player_fields()));
                if ($item['existing_id']) {
                    $fill = array_filter($d, fn($v) => $v !== null && $v !== '');
                    unset($fill['first_name'], $fill['last_name']);
                    if ($fill) {
                        db_update('kd_players', $fill, 'id = ?', [(int) $item['existing_id']]);
                    }
                    $updated++;
                } else {
                    db_insert('kd_players', $d + ['access_token' => kd_new_token(), 'active' => 1, 'created_at' => now()]);
                    $new++;
                }
            }
            kd_flash('ok', "Import fertig: $new neue Spielerinnen, $updated ergänzt. Jetzt die persönlichen Links verschicken.");
            kd_redirect('admin/spielerinnen');
        }
        $file = $_FILES['datei'] ?? null;
        try {
            if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException('Bitte eine Excel- oder CSV-Datei auswählen.');
            }
            if ((int) $file['size'] > KD_IMPORT_MAX_BYTES) {
                throw new RuntimeException('Die Datei ist zu gross (höchstens 2 MB).');
            }
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $rows = match ($ext) {
                'xlsx' => kd_read_xlsx((string) $file['tmp_name']),
                'csv', 'txt' => kd_read_csv((string) $file['tmp_name']),
                'xls' => throw new RuntimeException('Alte Excel-Dateien (.xls) bitte in Excel als «Excel-Arbeitsmappe (.xlsx)» oder «CSV» speichern.'),
                default => throw new RuntimeException('Bitte eine Datei im Format .xlsx oder .csv wählen.'),
            };
            $items = kd_import_rows($rows);
            if (!$items) {
                throw new RuntimeException('In der Datei wurden keine Spielerinnen gefunden.');
            }
            $ok = array_values(array_filter($items, fn($i) => $i['error'] === null));
            $tr = '';
            foreach ($items as $i) {
                $d = $i['data'];
                $state = $i['error'] !== null ? '<span class="pill high">Fehler</span><div class="sub">' . e($i['error']) . '</div>'
                    : ($i['existing_id'] ? '<span class="pill mid">wird ergänzt</span>' : '<span class="pill pb">neu</span>');
                $tr .= '<tr><td class="num">' . (int) $i['line'] . '</td><td><strong>' . e(trim($d['first_name'] . ' ' . $d['last_name'])) . '</strong></td>'
                    . '<td>' . e((string) $d['email']) . '<div class="sub">' . e((string) $d['phone']) . '</div></td>'
                    . '<td class="nowrap">' . e(kd_valid_ymd((string) $d['birthday']) ? kd_date($d['birthday']) : '') . '</td>'
                    . '<td>' . e((string) $d['emergency_name']) . '<div class="sub">' . e(trim($d['emergency_relation'] . ' ' . $d['emergency_phone'])) . '</div></td>'
                    . '<td>' . $state . '</td></tr>';
            }
            $payload = base64_encode((string) json_encode($ok, JSON_UNESCAPED_UNICODE));
            $preview = '<section class="card"><h2>Vorschau: ' . count($items) . ' Zeilen</h2>'
                . '<p>' . count(array_filter($ok, fn($i) => !$i['existing_id'])) . ' neu · ' . count(array_filter($ok, fn($i) => $i['existing_id'])) . ' werden ergänzt · '
                . (count($items) - count($ok)) . ' mit Fehler (werden übersprungen)</p>'
                . '<div class="table-wrap"><table><thead><tr><th class="num">Zeile</th><th>Name</th><th>Kontakt</th><th>Geburtstag</th><th>Notfallkontakt</th><th>Status</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
                . ($ok ? '<form method="post" action="' . e(kd_url('admin/import')) . '" class="mt">' . kd_csrf_field()
                    . '<input type="hidden" name="action" value="import"><input type="hidden" name="payload" value="' . e($payload) . '"><input type="hidden" name="sig" value="' . e(kd_import_sign($payload)) . '">'
                    . '<button class="btn" type="submit">Jetzt ' . count($ok) . ' Spielerinnen importieren</button></form>' : '')
                . '</section>';
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
    $cols = implode(', ', array_map(fn($c) => '«' . $c . '»', kd_import_columns()));
    $body = kd_head('Spielerinnen', 'Aus Excel importieren')
        . ($error ? '<p class="notice error">' . e($error) . '</p>' : '') . $preview
        . '<section class="card"><h2>1. Datei vorbereiten</h2>'
        . '<p>Erste Zeile mit Überschriften, danach eine Spielerin pro Zeile. Erkannt werden ' . e($cols) . ' – auch Varianten wie «Nachname», «Natel» oder «Geburtsdatum». Nur Vorname und Name sind Pflicht.</p>'
        . '<p><a class="btn btn-ghost" href="' . e(kd_url('admin/import/vorlage.csv')) . '">Vorlage herunterladen (für Excel)</a></p>'
        . '<p class="hint">Gibt es eine Spielerin schon (gleicher Vor- und Nachname), werden nur fehlende Angaben ergänzt. Leere Zellen überschreiben nichts.</p></section>'
        . '<section class="card"><h2>2. Datei hochladen</h2>'
        . '<form method="post" action="' . e(kd_url('admin/import')) . '" enctype="multipart/form-data" class="stack">' . kd_csrf_field()
        . '<input type="hidden" name="action" value="preview">'
        . '<div class="field"><label for="datei">Excel- oder CSV-Datei (.xlsx, .csv, höchstens 2 MB)</label>'
        . '<input id="datei" name="datei" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required></div>'
        . '<button class="btn" type="submit">Vorschau anzeigen</button></form>'
        . '<p class="hint mt">Es wird noch nichts gespeichert – zuerst siehst du eine Vorschau.</p></section>';
    kd_page('Import', $body, $error ? 422 : 200);
}

function kd_admin_import_template(): never
{
    kd_require_admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="spielerinnen-vorlage.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values(kd_import_columns()), ';', '"', '');
    fputcsv($out, ['Lea', 'Muster', 'lea@example.ch', '079 123 45 67', '14.03.2010', 'Petra Muster', 'Mutter', '078 222 33 44', 'petra@example.ch'], ';', '"', '');
    fclose($out);
    exit;
}
