#!/usr/bin/env bash
# Automatischer Rundgang durch das Fussball-Konditionstraining (läuft in GitHub Actions vor dem Hochladen).
# Startet einen lokalen PHP-Server mit SQLite-Testdatenbank und prüft Spielerinnen- und Admin-Bereich.
# Mit KD_VORSCHAU=<ordner> werden die Seiten zusätzlich als HTML gespeichert (Vorschau mit Beispieldaten).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT/public"
WORK="$(mktemp -d)"
PORT=8091
URL="http://127.0.0.1:$PORT/app/fussballtraining"
AJAR="$WORK/admin.txt"
SNAP="${KD_VORSCHAU:-}"
FAILS=0

if [ -f "$APP/config.php" ]; then
  echo "public/config.php existiert bereits – Test nur in sauberer Umgebung (CI) ausführen." >&2
  exit 1
fi
cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -f "$APP/config.php"
  rm -rf "$APP/data/backups" "$APP/data/logs"
}
trap cleanup EXIT

ok()   { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAILS=$((FAILS + 1)); }
expect() { # expect <beschreibung> <datei> <text>
  if grep -q -- "$3" "$2"; then ok "$1"; else fail "$1 (erwartet: «$3»)"; echo "----- Antwort -----"; head -c 1500 "$2"; echo; fi
}
refuse() { # refuse <beschreibung> <datei> <text>
  if grep -q -- "$3" "$2"; then fail "$1 (unerwartet: «$3»)"; else ok "$1"; fi
}
clean() { # keine PHP-Meldungen in der Seite
  if grep -q "Fehler aufgetreten\|Fatal\|Warning\|Notice\|Deprecated\|Stack trace" "$2"; then fail "$1 (PHP-Meldung)"; head -c 2000 "$2"; echo; else ok "$1"; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
csrf() { grep -o 'name="_csrf" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"$//'; }
q() { php -r "require '$APP/app/bootstrap.php'; echo db_val(\"$1\");"; }
d() { date -d "$1" +%Y-%m-%d; }
snap() { # snap <name> <datei> – speichert die Seite für die Vorschau, Links zeigen auf die anderen Vorschau-Seiten
  if [ -n "$SNAP" ]; then mkdir -p "$SNAP"; sed -E \
    -e 's#/app/fussballtraining/assets/kondition.css\?v=[0-9]*#kondition.css#' \
    -e 's#action="/app/fussballtraining/admin/anmelden"#action="06-admin-uebersicht.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/(anmelden|abmelden)"#\1="05-admin-anmelden.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/import[^"]*"#\1="15-admin-import.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/sicherungen[^"]*"#\1="16-admin-sicherungen.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/spielerin/[^"]*"#\1="08-admin-spielerin.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/spielerinnen"#\1="07-admin-spielerinnen.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/(laeufe|lauf/[0-9]+|export\.csv)[^"]*"#\1="09-admin-laeufe.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/nachrichten[^"]*"#\1="10-admin-nachrichten.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/infos[^"]*"#\1="11-admin-infos.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/admins"#\1="12-admin-admins.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin/(einstellungen|sicherung\.json)"#\1="13-admin-einstellungen.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/admin(\?[^"]*)?"#\1="06-admin-uebersicht.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/meine"#\1="03-meine-laeufe.html"#g' \
    -e 's#(href|action)="/app/fussballtraining/anleitung"#\1="17-anleitung.html"#g' \
    -e 's#="/app/fussballtraining/assets/(icon[^"]*)"#="\1"#g' \
    -e 's#(href|action)="/app/fussballtraining/infos"#\1="04-infos.html"#g' \
    -e 's#action="/app/fussballtraining/"#action="03-meine-laeufe.html"#g' \
    -e 's#href="/app/fussballtraining/"#href="02-eintragen.html"#g' \
    -e 's#method="post"#method="get"#g' \
    -e 's#<main class="wrap main">#<main class="wrap main"><p class="notice">Vorschau mit Beispieldaten: Speichern, Senden und Löschen werden hier nur angedeutet.</p>#' \
    "$2" > "$SNAP/$1.html"; fi
}

echo "Testdatenbank anlegen"
cat > "$APP/config.php" <<EOF
<?php return ['db' => ['driver' => 'sqlite', 'path' => '$WORK/app.sqlite'], 'base_url' => 'http://127.0.0.1:$PORT', 'debug' => true];
EOF

php -S 127.0.0.1:$PORT -t "$APP" "$APP/index.php" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
sleep 1

echo "1. Vor der Einrichtung"
curl -s -o /dev/null -w '%{redirect_url}' "$URL/" > "$WORK/s.txt"
expect "Startseite leitet zur Einrichtung" "$WORK/s.txt" "einrichten"
curl -s "$URL/einrichten" > "$WORK/r.html"
expect "Einrichtung verlangt Login der Hauptseite" "$WORK/r.html" "Bitte zuerst anmelden"
code "$URL/app/lib.php" > "$WORK/s.txt"; expect "Programmcode gesperrt" "$WORK/s.txt" "403"
code "$URL/assets/kondition.css" > "$WORK/s.txt"; expect "Design-Datei unter neuer Adresse" "$WORK/s.txt" "200"
curl -s -D "$WORK/h.txt" -o /dev/null "$URL/einrichten"
expect "Sicherheits-Header (CSP)" "$WORK/h.txt" "Content-Security-Policy: default-src 'self'"
expect "Nicht in fremde Seiten einbettbar" "$WORK/h.txt" "X-Frame-Options: DENY"
expect "Für Suchmaschinen gesperrt" "$WORK/h.txt" "X-Robots-Tag: noindex"
code "$URL/config.php" > "$WORK/s.txt"; expect "Zugangsdaten gesperrt" "$WORK/s.txt" "403"
curl -s "$URL/einrichten" > "$WORK/r.html"
expect "Version in der Fusszeile" "$WORK/r.html" "Version $(cat "$ROOT/VERSION")"

echo "2. Einrichten (direkt in der Datenbank) und Admin-Anmeldung"
php -r "require '$APP/app/bootstrap.php'; require '$APP/app/lib.php'; kd_migrate();
  db()->exec('CREATE TABLE settings (name VARCHAR(100) PRIMARY KEY, value TEXT)'); db_insert('settings', ['name' => 'kd_team_name', 'value' => 'FC Test Girls']);
  setting_set('kd_schema_version', '3');
  db_insert('kd_admins', ['name' => 'Sandra Trainerin', 'email' => 'sandra@test.ch', 'phone' => '079 555 66 77', 'password_hash' => password_hash('trainerpasswort', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now()]);"
curl -s "$URL/" > "$WORK/r.html"
expect "Start ohne Link: Hinweis auf persönlichen Link" "$WORK/r.html" "Bitte deinen persönlichen Link öffnen"
expect "Mannschaftsname aus früherer Version übernommen" "$WORK/r.html" "<strong>FC Test Girls</strong>"
snap "01-start" "$WORK/r.html"
code "$URL/meine" > "$WORK/s.txt"; expect "Ohne Link keine Läufe" "$WORK/s.txt" "302"
code "$URL/admin" > "$WORK/s.txt"; expect "Ohne Login kein Admin" "$WORK/s.txt" "302"
curl -s "$URL/zugang/0123456789abcdef0123456789abcdef0123456789abcdef" > "$WORK/r.html"
expect "Falscher Link abgelehnt" "$WORK/r.html" "Dieser Link funktioniert nicht mehr"
curl -s -c "$AJAR" -b "$AJAR" -d "email=sandra@test.ch&password=falsch" "$URL/admin/anmelden" > "$WORK/r.html"
expect "Falsches Passwort abgelehnt" "$WORK/r.html" "E-Mail oder Passwort stimmt nicht"
snap "05-admin-anmelden" "$WORK/r.html"
curl -s -c "$AJAR" -b "$AJAR" -L -d "email=Sandra@Test.ch&password=trainerpasswort" "$URL/admin/anmelden" > "$WORK/r.html"
expect "Angemeldet: Übersicht" "$WORK/r.html" "Spielerpause"
clean "Leere Übersicht ohne Fehler" "$WORK/r.html"
T=$(csrf "$WORK/r.html")

echo "3. Spielerinnen anlegen"
add_player() { # add_player <felder…>
  curl -s -c "$AJAR" -b "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=save" "$@" "$URL/admin/spielerin/neu" > "$WORK/r.html"
}
add_player --data-urlencode "first_name=Lea" --data-urlencode "last_name="
expect "Name fehlt → Fehlermeldung" "$WORK/r.html" "Bitte Vorname und Name angeben"
add_player --data-urlencode "first_name=Lea" --data-urlencode "last_name=Muster" --data-urlencode "email=kein-mail"
expect "Ungültige E-Mail abgelehnt" "$WORK/r.html" "E-Mail-Adresse der Spielerin ist ungültig"
add_player --data-urlencode "first_name=Lea" --data-urlencode "last_name=Muster" --data-urlencode "pause_from=$(d '+5 days')" --data-urlencode "pause_to=$(d '+1 days')"
expect "Pause: Ende vor Beginn abgelehnt" "$WORK/r.html" "Ende der Pause liegt vor dem Beginn"
add_player --data-urlencode "first_name=Lea" --data-urlencode "last_name=Muster" --data-urlencode "email=lea@test.ch" --data-urlencode "phone=079 123 45 67" \
  --data-urlencode "birthday=$(d '-16 years')" --data-urlencode "emergency_name=Petra Muster" --data-urlencode "emergency_relation=Mutter" \
  --data-urlencode "emergency_phone=078 222 33 44" --data-urlencode "emergency_email=petra@test.ch"
expect "Lea angelegt" "$WORK/r.html" "Lea ist angelegt"
clean "Spielerin-Seite ohne Fehler" "$WORK/r.html"
add_player --data-urlencode "first_name=Nina" --data-urlencode "last_name=Beispiel" --data-urlencode "phone=+41 76 111 22 33" \
  --data-urlencode "birthday=$(d '-15 years +5 days')" --data-urlencode "emergency_name=Marco Beispiel" --data-urlencode "emergency_relation=Vater" --data-urlencode "emergency_email=marco@test.ch"
add_player --data-urlencode "first_name=Mia" --data-urlencode "last_name=Probe" --data-urlencode "email=mia@test.ch" --data-urlencode "birthday=$(d '-17 years -40 days')" \
  --data-urlencode "pause_from=$(d '-2 days')" --data-urlencode "pause_to=$(d '+10 days')" --data-urlencode "pause_reason=Verletzung (Knöchel)"
add_player --data-urlencode "first_name=Sara" --data-urlencode "last_name=Kick" --data-urlencode "email=sara@test.ch" -d "birthday_d=31&birthday_m=2&birthday_y=2010"
expect "Ungültiges Datum (31. Februar) abgelehnt" "$WORK/r.html" "gültiges Geburtsdatum"
add_player --data-urlencode "first_name=Sara" --data-urlencode "last_name=Kick" --data-urlencode "email=sara@test.ch" -d "birthday_d=$(date -d '-16 years -100 days' +%-d)&birthday_m=$(date -d '-16 years -100 days' +%-m)&birthday_y=$(date -d '-16 years -100 days' +%Y)"
expect "Geburtstag aus Tag/Monat/Jahr gespeichert" "$WORK/r.html" "$(date -d '-16 years -100 days' +%d.%m.%Y)"
LEA=$(q "SELECT id FROM kd_players WHERE first_name = 'Lea'"); NINA=$(q "SELECT id FROM kd_players WHERE first_name = 'Nina'")
MIA=$(q "SELECT id FROM kd_players WHERE first_name = 'Mia'"); SARA=$(q "SELECT id FROM kd_players WHERE first_name = 'Sara'")
LEA_T=$(q "SELECT access_token FROM kd_players WHERE id = $LEA")
[ ${#LEA_T} -eq 48 ] && ok "Persönlicher Link mit langem Zufallsschlüssel" || fail "Link-Schlüssel zu kurz (${#LEA_T})"

echo "4. Spielerin mit persönlichem Link"
play() { # play <jar> <token>
  curl -s -c "$1" -b "$1" -L "$URL/zugang/$2" > "$WORK/r.html"
  P=$(csrf "$WORK/r.html")
}
run() { # run <jar> <datum> <meter> <min> <sek> [bemerkung]
  curl -s -c "$1" -b "$1" -L --data-urlencode "_csrf=$P" --data-urlencode "run_date=$2" --data-urlencode "distance_m=$3" \
    --data-urlencode "minutes=$4" --data-urlencode "seconds=$5" --data-urlencode "note=${6:-}" "$URL/" > "$WORK/r.html"
}
L="$WORK/lea.txt"
play "$L" "$LEA_T"
expect "Link öffnet Erfassung mit Namen" "$WORK/r.html" "Hallo Lea"
expect "Geburtstagsgruss am Geburtstag" "$WORK/r.html" "Alles Gute zum 16. Geburtstag, Lea"
refuse "Kein Namensfeld mehr nötig" "$WORK/r.html" 'name="first_name"'
expect "Länge frei wählbar, in Metern" "$WORK/r.html" "Frei wählbar, immer in Metern"
expect "Datum mit separatem Tag-Feld" "$WORK/r.html" 'name="run_date_d"'
expect "Datum mit Monatsauswahl" "$WORK/r.html" '<option value="12">Dezember</option>'
expect "Datum mit separatem Jahr-Feld" "$WORK/r.html" 'name="run_date_y"'
expect "Heute vorausgefüllt (Tag)" "$WORK/r.html" "name=\"run_date_d\" type=\"number\" inputmode=\"numeric\" min=\"1\" max=\"31\" step=\"1\" placeholder=\"Tag\" aria-label=\"Datum: Tag\" value=\"$(date +%-d)\""
clean "Erfassung ohne Fehler" "$WORK/r.html"
snap "02-eintragen" "$WORK/r.html"
run "$L" "$(d '-28 days')" 3000 15 10
expect "Lauf gespeichert" "$WORK/r.html" "Gespeichert: 3’000 m in 15:10"
run "$L" "$(d '-21 days')" 3000 14 30
run "$L" "$(d '-14 days')" 3000 14 40
run "$L" "$(d '-7 days')" 3000 13 50 "Gegenwind"
run "$L" "$(d '-0 days')" 3000 14 05
run "$L" "$(d '-3 days')" 400 1 25
run "$L" "$(d '-1 days')" 3200 15 0
expect "Beliebige Länge (3200 m)" "$WORK/r.html" "Gespeichert: 3’200 m in 15:00"
curl -s -c "$L" -b "$L" -L --data-urlencode "_csrf=$P" -d "run_date_d=$(date -d '-2 days' +%-d)&run_date_m=$(date -d '-2 days' +%-m)&run_date_y=$(date -d '-2 days' +%Y)&distance_m=1000&minutes=4&seconds=10" "$URL/" > "$WORK/r.html"
expect "Lauf mit Tag/Monat/Jahr gespeichert" "$WORK/r.html" "Gespeichert: 1’000 m in 4:10"
curl -s -c "$L" -b "$L" -L --data-urlencode "_csrf=$P" -d "run_date_d=30&run_date_m=2&run_date_y=$(date +%Y)&distance_m=1000&minutes=4&seconds=10" "$URL/" > "$WORK/r.html"
expect "30. Februar abgelehnt" "$WORK/r.html" "Bitte ein gültiges Datum wählen"
curl -s -c "$L" -b "$L" -L --data-urlencode "_csrf=$P" -d "run_date_d=&run_date_m=$(date +%-m)&run_date_y=$(date +%Y)&distance_m=1000&minutes=4&seconds=10" "$URL/" > "$WORK/r.html"
expect "Unvollständiges Datum abgelehnt" "$WORK/r.html" "Bitte ein gültiges Datum wählen"
run "$L" "$(d '-0 days')" 3 14 0
expect "Kilometer statt Meter erkannt" "$WORK/r.html" "nicht in Kilometern: 3 km = 3’000 m"
run "$L" "$(d '-0 days')" 3.5 14 0
expect "Kommazahl abgelehnt" "$WORK/r.html" "als ganze Zahl in Metern"
run "$L" "$(d '-0 days')" 400 0 5
expect "Unmögliche Zeit abgelehnt" "$WORK/r.html" "Laufzeit angeben\|mehr als 40 km/h"
run "$L" "$(d '+5 days')" 3000 13 0
expect "Datum in der Zukunft abgelehnt" "$WORK/r.html" "Zukunft"
run "$L" "$(d '-0 days')" 3000 13 75
expect "Sekunden über 59 abgelehnt" "$WORK/r.html" "höchstens 59"
curl -s -c "$L" -b "$L" -L -d "run_date=$(d '-0 days')&distance_m=3000&minutes=13&seconds=0" "$URL/" > "$WORK/r.html"
expect "Ohne CSRF abgelehnt" "$WORK/r.html" "Sitzung abgelaufen"
N="$WORK/nina.txt"; play "$N" "$(q "SELECT access_token FROM kd_players WHERE id = $NINA")"
run "$N" "$(d '-21 days')" 3000 13 20; run "$N" "$(d '-10 days')" 3000 12 55; run "$N" "$(d '-0 days')" 3000 12 40; run "$N" "$(d '-6 days')" 400 1 22
M="$WORK/mia.txt"; play "$M" "$(q "SELECT access_token FROM kd_players WHERE id = $MIA")"
run "$M" "$(d '-27 days')" 3000 16 50; run "$M" "$(d '-20 days')" 3000 16 20 "=HYPERLINK(\"x\")"
S="$WORK/sara.txt"; play "$S" "$(q "SELECT access_token FROM kd_players WHERE id = $SARA")"
run "$S" "$(d '-24 days')" 3000 15 40; run "$S" "$(d '-12 days')" 3000 15 5; run "$S" "$(d '-2 days')" 3000 14 35

echo "5. Meine Läufe (nur eigener Bereich)"
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
clean "Meine Läufe ohne Fehler" "$WORK/r.html"
expect "Entwicklungskurve vorhanden" "$WORK/r.html" '<svg class="chart"'
expect "Kurve mit Linie pro Strecke" "$WORK/r.html" '<span class="swatch" style="background:var(--c1)"></span>400 m'
expect "Geschwindigkeit berechnet (3000 m in 13:50 = 13.0 km/h)" "$WORK/r.html" "13.0 km/h"
expect "Meter pro Sekunde (3000 m ÷ 830 s = 3.61 m/s)" "$WORK/r.html" "3.61 m/s"
expect "Zeit pro 1000 m (13:50 ÷ 3 = 4:37)" "$WORK/r.html" "4:37 pro 1000 m"
expect "Trend schneller" "$WORK/r.html" "0:50 schneller"
expect "Trend langsamer" "$WORK/r.html" "0:15 langsamer"
expect "Abstand zur Mannschaftsbesten (13:50 vs 12:40)" "$WORK/r.html" "1:10 (8.4 %)"
expect "Bestzeit über 400 m bei Nina, Lea mit Abstand" "$WORK/r.html" "0:03 bis zur Mannschaftsbesten"
refuse "Keine fremden Namen" "$WORK/r.html" "Nina"
refuse "Keine fremden Läufe" "$WORK/r.html" "12:40</strong>"
code -b "$L" "$URL/admin" > "$WORK/s.txt"; expect "Spielerin kommt nicht in den Admin" "$WORK/s.txt" "302"
code -b "$L" "$URL/admin/spielerin/$NINA" > "$WORK/s.txt"; expect "Spielerin sieht keine anderen Spielerinnen" "$WORK/s.txt" "302"
code -b "$L" "$URL/admin/export.csv" > "$WORK/s.txt"; expect "Spielerin kann nichts exportieren" "$WORK/s.txt" "302"

echo "5b. App aufs Handy (Symbol, Manifest, Anleitung, Link einfügen)"
curl -s "$URL/anleitung" > "$WORK/r.html"
clean "Anleitung ohne Anmeldung erreichbar" "$WORK/r.html"
expect "Anleitung iPhone" "$WORK/r.html" "Zum Home-Bildschirm"
expect "Anleitung Android" "$WORK/r.html" "Zum Startbildschirm hinzufügen"
snap "17-anleitung" "$WORK/r.html"
for f in icon-180.png icon-192.png icon-512.png icon-32.png icon.svg; do code "$URL/assets/$f" > "$WORK/s.txt"; expect "Symbol $f vorhanden" "$WORK/s.txt" "200"; done
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
expect "Symbol für iPhone verlinkt" "$WORK/r.html" 'rel="apple-touch-icon"'
expect "Manifest verlinkt (mit Anmeldung)" "$WORK/r.html" 'rel="manifest" href="/app/fussballtraining/manifest.webmanifest" crossorigin="use-credentials"'
expect "Hinweis «App speichern» vorbereitet" "$WORK/r.html" 'id="install-hint"'
curl -s "$URL/manifest.webmanifest" > "$WORK/m.json"
expect "Manifest ohne Anmeldung: Startseite" "$WORK/m.json" '"start_url":"/app/fussballtraining/"'
expect "Manifest: eigenständige App" "$WORK/m.json" '"display":"standalone"'
curl -s -b "$L" "$URL/manifest.webmanifest" > "$WORK/m.json"
expect "Manifest Spielerin: startet mit persönlichem Link" "$WORK/m.json" "zugang/$LEA_T"
curl -s -b "$AJAR" "$URL/manifest.webmanifest" > "$WORK/m.json"
expect "Manifest Trainerteam: startet im Admin" "$WORK/m.json" '"start_url":"/app/fussballtraining/admin"'
P2="$WORK/paste.txt"
curl -s -c "$P2" -b "$P2" -L --data-urlencode "link=https://ki-smartwork.ch/app/fussballtraining/zugang/$LEA_T" "$URL/zugang" > "$WORK/r.html"
expect "Anmelden mit eingefügtem Link" "$WORK/r.html" "Hallo Lea"
curl -s -c "$P2" -b "$P2" -L --data-urlencode "link=https://ki-smartwork.ch/app/fussballtraining/zugang/falsch" "$URL/zugang" > "$WORK/r.html"
expect "Falscher eingefügter Link abgelehnt" "$WORK/r.html" "Dieser Link funktioniert nicht mehr"
curl -s "$URL/" > "$WORK/r.html"
expect "Startseite: Feld zum Link einfügen" "$WORK/r.html" "Oder den Link hier einfügen"

echo "6. Allgemeine Infos"
curl -s -b "$L" "$URL/infos" > "$WORK/r.html"
expect "Noch keine Infos" "$WORK/r.html" "keine Infos"
sleep 1 # neue Infos müssen später als das letzte Lesen gespeichert sein
curl -s -b "$AJAR" "$URL/admin/infos" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" --data-urlencode "title=Trainingszeiten Winter" \
  --data-urlencode "body=Dienstag und Donnerstag, 18.30–20.00 Uhr in der Halle Mattenhof. Bitte Hallenschuhe mitbringen." -d "pinned=1" "$URL/admin/infos" > "$WORK/r.html"
expect "Info gespeichert" "$WORK/r.html" "Info gespeichert"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" --data-urlencode "title=Cooper-Test am Samstag" \
  --data-urlencode "body=Am Samstag laufen wir den 12-Minuten-Test. Tragt eure Strecke danach in Metern ein." "$URL/admin/infos" > "$WORK/r.html"
snap "11-admin-infos" "$WORK/r.html"
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
expect "Rote Zahl: 2 neue Infos" "$WORK/r.html" '<span class="badge" aria-label="2 neue Infos">2</span>'
expect "Infos-Popup vorhanden" "$WORK/r.html" '<dialog id="infos-dialog"'
expect "Popup öffnet sich bei neuen Infos" "$WORK/r.html" 'data-auto-open="1"'
expect "Neue Info markiert" "$WORK/r.html" '<span class="new-tag">Neu</span>'
snap "14-infos-popup" "$WORK/r.html"
LC=$(grep -o 'var csrf="[^"]*"' "$WORK/r.html" | head -1 | sed 's/var csrf="//;s/"$//')
code -b "$L" -X POST -d "_csrf=$LC" "$URL/infos/gelesen" > "$WORK/s.txt"; expect "Popup gelesen gemeldet" "$WORK/s.txt" "204"
code -b "$AJAR" -X POST -d "_csrf=x" "$URL/infos/gelesen" > "$WORK/s.txt"; expect "Gelesen-Meldung nur für Spielerinnen" "$WORK/s.txt" "403"
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
refuse "Rote Zahl nach dem Lesen weg" "$WORK/r.html" 'class="badge"'
refuse "Popup öffnet sich nicht mehr von selbst" "$WORK/r.html" 'data-auto-open'
curl -s -b "$AJAR" "$URL/admin/infos" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
IID=$(q "SELECT id FROM kd_infos WHERE title = 'Cooper-Test am Samstag'")
sleep 1
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "id=$IID" --data-urlencode "title=Cooper-Test am Samstag (verschoben)" --data-urlencode "body=Neu um 10 Uhr." "$URL/admin/infos" > /dev/null
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
expect "Geänderte Info zählt wieder als neu" "$WORK/r.html" '<span class="badge" aria-label="1 neue Infos">1</span>'
curl -s -b "$L" "$URL/infos" > "$WORK/r.html"
expect "Spielerin sieht Info" "$WORK/r.html" "Trainingszeiten Winter"
expect "Wichtige Info markiert" "$WORK/r.html" "Wichtig"
clean "Infos ohne Fehler" "$WORK/r.html"
snap "04-infos" "$WORK/r.html"
code -b "$L" -X POST "$URL/admin/infos" > "$WORK/s.txt"; expect "Spielerin kann keine Infos schreiben" "$WORK/s.txt" "302"

echo "7. Admin: Übersicht, Geburtstage, Pause"
curl -s -b "$AJAR" "$URL/admin" > "$WORK/r.html"
clean "Übersicht ohne Fehler" "$WORK/r.html"
expect "Geburtstag heute (Lea)" "$WORK/r.html" '<span class="pill best">heute</span>'
expect "Gratulieren per WhatsApp" "$WORK/r.html" "Gratulieren per WhatsApp"
expect "Nächster Geburtstag (Nina in 5 Tagen)" "$WORK/r.html" "in 5 Tagen"
expect "Spielerpause (Mia)" "$WORK/r.html" "Verletzung (Knöchel)"
expect "Mannschaftskurve" "$WORK/r.html" "Entwicklung der Mannschaft"
expect "Kurve mit Linie pro Spielerin" "$WORK/r.html" "</span>Sara Kick</li>"
expect "Potenzial gross für Mia (16:20 vs 12:40)" "$WORK/r.html" "pill high"
expect "Kennzahl Spielerinnen = 4" "$WORK/r.html" "Spielerinnen</span><strong>4<"
snap "06-admin-uebersicht" "$WORK/r.html"
curl -s -b "$AJAR" "$URL/admin?strecke=400" > "$WORK/r.html"
expect "Kurve für andere Strecke" "$WORK/r.html" "Geschwindigkeit über 400 m"
curl -s -b "$AJAR" "$URL/admin/spielerinnen" > "$WORK/r.html"
clean "Spielerinnen-Liste ohne Fehler" "$WORK/r.html"
expect "Status Pause in Liste" "$WORK/r.html" '<span class="pill mid">Pause</span>'
expect "Notfallkontakt in Liste" "$WORK/r.html" "Petra Muster"
snap "07-admin-spielerinnen" "$WORK/r.html"

echo "8. Admin: Spielerin bearbeiten, Link senden, gratulieren"
curl -s -b "$AJAR" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
clean "Spielerin-Seite ohne Fehler" "$WORK/r.html"
expect "Persönlicher Link sichtbar" "$WORK/r.html" "zugang/$LEA_T"
expect "WhatsApp mit Schweizer Nummer" "$WORK/r.html" "wa.me/41791234567"
expect "WhatsApp an Notfallkontakt" "$WORK/r.html" "wa.me/41782223344"
expect "Kurve der Spielerin" "$WORK/r.html" '<svg class="chart"'
T=$(csrf "$WORK/r.html")
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=link_mail" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
expect "Link per E-Mail gesendet" "$WORK/r.html" "Link per E-Mail an lea@test.ch gesendet"
[ "$(q "SELECT COUNT(*) FROM kd_mail_log WHERE to_email = 'lea@test.ch' AND subject LIKE '%Link%'")" = "1" ] && ok "E-Mail im Versandprotokoll" || fail "E-Mail fehlt im Versandprotokoll"
expect "E-Mail mit Link enthält Anleitung" "$APP/data/logs/mail.log" "/app/fussballtraining/anleitung"
expect "WhatsApp-Text enthält Anleitung" "$WORK/r.html" "%2Fanleitung"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=gratulieren" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
expect "Geburtstagsgruss per E-Mail" "$WORK/r.html" "Geburtstagsgruss an Lea gesendet"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=save" --data-urlencode "first_name=Lea" --data-urlencode "last_name=Muster-Meier" \
  --data-urlencode "email=lea@test.ch" --data-urlencode "phone=079 123 45 67" --data-urlencode "birthday=$(d '-16 years')" --data-urlencode "emergency_name=Petra Muster" \
  --data-urlencode "emergency_relation=Mutter" --data-urlencode "emergency_phone=078 222 33 44" --data-urlencode "emergency_email=petra@test.ch" \
  --data-urlencode "admin_note=Stark im Sprint" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
expect "Name bearbeitet" "$WORK/r.html" "Lea Muster-Meier"
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
expect "Spielerin sieht neuen Namen" "$WORK/r.html" "Lea Muster-Meier"
refuse "Interne Notiz für Spielerin unsichtbar" "$WORK/r.html" "Stark im Sprint"

echo "9. Läufe: Feedback und Notiz"
RUN_A=$(q "SELECT id FROM kd_runs WHERE player_id = $LEA AND seconds = 830"); RUN_B=$(q "SELECT id FROM kd_runs WHERE player_id = $LEA AND seconds = 845")
RUN_N=$(q "SELECT id FROM kd_runs WHERE player_id = $NINA AND seconds = 760")
curl -s -b "$AJAR" "$URL/admin/laeufe" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
clean "Läufe-Seite ohne Fehler" "$WORK/r.html"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "feedback=stolz&coach_note=" "$URL/admin/lauf/$RUN_A" > "$WORK/r.html"
expect "Feedback gegeben" "$WORK/r.html" "Feedback «Ich bin stolz auf dich!» an Lea Muster-Meier gegeben"
expect "Feedback-Knopf als gewählt markiert" "$WORK/r.html" 'class="fb-btn on" aria-pressed="true"'
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "feedback=besser" --data-urlencode "coach_note=Beim nächsten Mal ruhiger starten." "$URL/admin/lauf/$RUN_B" > /dev/null
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "feedback=bravo" --data-urlencode "coach_note=Super Tempo!" "$URL/admin/lauf/$RUN_N" > /dev/null
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "feedback=bravo" --data-urlencode "coach_note=Super Tempo!" "$URL/admin/lauf/$RUN_N" > "$WORK/r.html"
expect "Zweiter Klick nimmt Feedback weg" "$WORK/r.html" "Feedback für Nina entfernt"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "feedback=weiter" --data-urlencode "coach_note=Super Tempo!" "$URL/admin/lauf/$RUN_N" > "$WORK/r.html"
snap "09-admin-laeufe" "$WORK/r.html"
curl -s -b "$L" "$URL/meine" > "$WORK/r.html"
expect "Spielerin sieht Feedback «stolz»" "$WORK/r.html" "Ich bin stolz auf dich!"
expect "Spielerin sieht Feedback «besser»" "$WORK/r.html" "Das geht noch ein bisschen besser"
expect "Spielerin sieht Notiz" "$WORK/r.html" "Beim nächsten Mal ruhiger starten."
snap "03-meine-laeufe" "$WORK/r.html"
curl -s -b "$AJAR" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
snap "08-admin-spielerin" "$WORK/r.html"
curl -s -b "$AJAR" "$URL/admin/laeufe?von_d=$(date -d '-1 days' +%-d)&von_m=$(date -d '-1 days' +%-m)&von_y=$(date -d '-1 days' +%Y)&bis_d=&bis_m=&bis_y=" > "$WORK/r.html"
clean "Filter mit Tag/Monat/Jahr ohne Fehler" "$WORK/r.html"
refuse "Filter ab gestern blendet ältere Läufe aus" "$WORK/r.html" "15:10</strong>"
curl -s -b "$AJAR" "$URL/admin/laeufe?spielerin=$NINA" > "$WORK/r.html"
refuse "Filter nach Spielerin" "$WORK/r.html" "Sara Kick</a>"
curl -s -b "$AJAR" "$URL/admin/export.csv" > "$WORK/x.csv"
expect "CSV-Export mit Kopfzeile" "$WORK/x.csv" "Datum;Vorname;Name"
expect "CSV mit km/h und m/s" "$WORK/x.csv" "km/h;m/s;"
expect "CSV enthält Läufe mit m/s" "$WORK/x.csv" "Nina;Beispiel;3000;12:40;760;14.2;3.95"
expect "CSV enthält Feedback" "$WORK/x.csv" "Ich bin stolz auf dich!"
expect "CSV schützt vor Excel-Formeln" "$WORK/x.csv" "'=HYPERLINK"

echo "10. Nachrichten an Spielerinnen und Eltern"
curl -s -b "$AJAR" "$URL/admin/nachrichten?an=eltern&spielerin=$LEA" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
clean "Nachrichten-Seite ohne Fehler" "$WORK/r.html"
curl -s -b "$AJAR" -c "$AJAR" --data-urlencode "_csrf=$T" -d "action=mail&to_parents=1&ids[]=$LEA&ids[]=$NINA" \
  --data-urlencode "subject=Info zum Training" --data-urlencode "text=Liebe Eltern von {vorname}, das Training am Samstag beginnt um 10 Uhr." "$URL/admin/nachrichten" > "$WORK/r.html"
expect "E-Mail an Eltern gesendet" "$WORK/r.html" "2 E-Mail(s) gesendet"
expect "WhatsApp-Knopf für Eltern mit Platzhalter ersetzt" "$WORK/r.html" "Liebe%20Eltern%20von%20Lea"
[ "$(q "SELECT COUNT(*) FROM kd_mail_log WHERE to_email IN ('petra@test.ch','marco@test.ch')")" = "2" ] && ok "Eltern-E-Mails im Protokoll" || fail "Eltern-E-Mails fehlen"
snap "10-admin-nachrichten" "$WORK/r.html"
curl -s -b "$AJAR" -c "$AJAR" --data-urlencode "_csrf=$T" -d "action=whatsapp&to_players=1&ids[]=$LEA" --data-urlencode "text=Hallo {vorname}!" "$URL/admin/nachrichten" > "$WORK/r.html"
expect "Nur WhatsApp-Knöpfe" "$WORK/r.html" "Per WhatsApp senden"
curl -s -b "$AJAR" -c "$AJAR" --data-urlencode "_csrf=$T" -d "action=mail&ids[]=$LEA" --data-urlencode "subject=x" --data-urlencode "text=y" "$URL/admin/nachrichten" > "$WORK/r.html"
expect "Ohne Empfängergruppe → Hinweis" "$WORK/r.html" "an Spielerinnen und/oder Eltern"

echo "11. Mehrere Admins"
curl -s -b "$AJAR" "$URL/admin/admins" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
curl -s -b "$AJAR" -c "$AJAR" --data-urlencode "_csrf=$T" -d "action=invite&send_mail=1" --data-urlencode "name=Tom Assistent" \
  --data-urlencode "email=tom@test.ch" --data-urlencode "phone=079 999 88 77" -d "role=trainer" "$URL/admin/admins" > "$WORK/r.html"
expect "Rolle Trainer in der Team-Liste" "$WORK/r.html" '<span class="pill pb">Trainer</span>'
expect "Rollen erklärt" "$WORK/r.html" "verwaltet die Spielerinnen"
clean "Admins-Seite ohne Fehler" "$WORK/r.html"
expect "Einladungslink angezeigt" "$WORK/r.html" "Einladungslink für Tom Assistent"
snap "12-admin-admins" "$WORK/r.html"
INV=$(grep -o 'admin/einladung/[a-f0-9]\{48\}' "$WORK/r.html" | head -1)
[ "$(q "SELECT COUNT(*) FROM kd_mail_log WHERE to_email = 'tom@test.ch'")" = "1" ] && ok "Einladung per E-Mail" || fail "Einladungs-E-Mail fehlt"
curl -s -b "$AJAR" -c "$AJAR" --data-urlencode "_csrf=$T" -d "action=invite" --data-urlencode "name=Doppelt" --data-urlencode "email=tom@test.ch" -L "$URL/admin/admins" > "$WORK/r.html"
expect "Doppelte E-Mail abgelehnt" "$WORK/r.html" "gibt es schon jemanden im Team"
TJAR="$WORK/tom.txt"
curl -s -c "$TJAR" -b "$TJAR" -d "password=kurz&password2=kurz" "$URL/$INV" > "$WORK/r.html"
expect "Zu kurzes Passwort abgelehnt" "$WORK/r.html" "10 Zeichen"
curl -s -c "$TJAR" -b "$TJAR" -L -d "password=tompasswort123&password2=tompasswort123" "$URL/$INV" > "$WORK/r.html"
expect "Neuer Trainer angemeldet" "$WORK/r.html" "Willkommen im Trainerteam, Tom Assistent"
expect "Kopfzeile zeigt Rolle Trainer" "$WORK/r.html" "Trainer · Konditionstraining"
refuse "Trainer sieht keinen Menüpunkt Team" "$WORK/r.html" '>Team</a>'
refuse "Trainer sieht keinen Menüpunkt Sicherungen" "$WORK/r.html" '>Sicherungen</a>'
code -b "$TJAR" "$URL/admin/spielerinnen" > "$WORK/s.txt"; expect "Trainer verwaltet Spielerinnen" "$WORK/s.txt" "200"
code -b "$TJAR" "$URL/admin/import" > "$WORK/s.txt"; expect "Trainer darf Excel importieren" "$WORK/s.txt" "200"
code -b "$TJAR" "$URL/admin/laeufe" > "$WORK/s.txt"; expect "Trainer sieht Läufe" "$WORK/s.txt" "200"
code -b "$TJAR" "$URL/admin/admins" > "$WORK/s.txt"; expect "Trainer kann kein Team verwalten" "$WORK/s.txt" "403"
code -b "$TJAR" "$URL/admin/sicherungen" > "$WORK/s.txt"; expect "Trainer kann keine Sicherungen öffnen" "$WORK/s.txt" "403"
code -b "$TJAR" "$URL/admin/sicherung.json" > "$WORK/s.txt"; expect "Trainer kann keinen Datenexport laden" "$WORK/s.txt" "403"
curl -s -b "$TJAR" "$URL/admin/einstellungen" > "$WORK/r.html"
expect "Trainer hat «Mein Konto»" "$WORK/r.html" "Mein Konto"
refuse "Trainer kann Mannschaftsname nicht ändern" "$WORK/r.html" 'name="team"'
curl -s -b "$TJAR" "$URL/admin/spielerin/neu" > "$WORK/r.html"; TT=$(csrf "$WORK/r.html")
curl -s -b "$TJAR" -c "$TJAR" -L --data-urlencode "_csrf=$TT" -d "action=save" --data-urlencode "first_name=Zoe" --data-urlencode "last_name=Trainerin-Test" "$URL/admin/spielerin/neu" > "$WORK/r.html"
expect "Trainer legt Spielerin an" "$WORK/r.html" "Zoe ist angelegt"
curl -s -b "$TJAR" -c "$TJAR" -L --data-urlencode "_csrf=$TT" -d "action=team" --data-urlencode "team=Gehackt" "$URL/admin/einstellungen" > "$WORK/r.html"
expect "Trainer: Mannschaftsname geschützt" "$WORK/r.html" "Das kann nur ein Admin"
[ "$(q "SELECT value FROM kd_settings WHERE name = 'kd_team_name'")" = "FC Test Girls" ] && ok "Mannschaftsname unverändert" || fail "Trainer konnte Mannschaftsname ändern"
curl -s "$URL/$INV" > "$WORK/r.html"
expect "Einladungslink nur einmal gültig" "$WORK/r.html" "nicht mehr gültig"
TOM=$(q "SELECT id FROM kd_admins WHERE email = 'tom@test.ch'"); ME=$(q "SELECT id FROM kd_admins WHERE email = 'sandra@test.ch'")
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=delete&id=$ME" "$URL/admin/admins" > "$WORK/r.html"
expect "Sich selbst entfernen verhindert" "$WORK/r.html" "nicht selbst entfernen"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=make_trainer&id=$ME" "$URL/admin/admins" > "$WORK/r.html"
expect "Eigene Rolle nicht änderbar" "$WORK/r.html" "eigene Rolle nicht ändern"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=make_admin&id=$TOM" "$URL/admin/admins" > "$WORK/r.html"
expect "Trainer zum Admin gemacht" "$WORK/r.html" "Tom Assistent ist jetzt Admin"
code -b "$TJAR" "$URL/admin/sicherungen" > "$WORK/s.txt"; expect "Neue Rolle wirkt sofort" "$WORK/s.txt" "200"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=make_trainer&id=$TOM" "$URL/admin/admins" > "$WORK/r.html"
expect "Zurück zum Trainer" "$WORK/r.html" "Tom Assistent ist jetzt Trainer"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=deactivate&id=$TOM" "$URL/admin/admins" > "$WORK/r.html"
expect "Admin deaktiviert" "$WORK/r.html" "Tom Assistent ist deaktiviert"
code -b "$TJAR" "$URL/admin" > "$WORK/s.txt"; expect "Deaktivierter Admin sofort abgemeldet" "$WORK/s.txt" "302"

echo "12. Einstellungen und Sicherung"
curl -s -b "$AJAR" "$URL/admin/einstellungen" > "$WORK/r.html"
clean "Einstellungen ohne Fehler" "$WORK/r.html"
snap "13-admin-einstellungen" "$WORK/r.html"
curl -s -b "$AJAR" "$URL/admin/sicherung.json" > "$WORK/b.json"
expect "Sicherung enthält Spielerinnen" "$WORK/b.json" '"first_name": "Nina"'
expect "Sicherung enthält Läufe" "$WORK/b.json" '"kd_runs"'
refuse "Sicherung ohne Passwörter" "$WORK/b.json" "password_hash"
code -b "$L" "$URL/admin/sicherung.json" > "$WORK/s.txt"; expect "Spielerin kann keine Sicherung laden" "$WORK/s.txt" "302"

echo "12b. Import aus Excel und CSV"
curl -s -b "$AJAR" "$URL/admin/import" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
clean "Import-Seite ohne Fehler" "$WORK/r.html"
curl -s -b "$AJAR" "$URL/admin/import/vorlage.csv" > "$WORK/v.csv"
expect "Vorlage mit Überschriften" "$WORK/v.csv" "Vorname;Name;E-Mail;Handy;Geburtstag"
# CSV wie aus Excel: Windows-Umlaute, Semikolon, andere Überschriften, Datum 14.03.2011, Handy ohne führende 0
printf 'Vorname;Nachname;Natel;Geburtsdatum;Mutter;Notfalltelefon\r\nJana;Fl\xfcckiger;791112233;14.03.2011;Ruth Fl\xfcckiger;0442223344\r\nNina;Beispiel;;;;\r\n;;;;;\r\nX;;;31.02.2011;;\r\n' > "$WORK/spielerinnen.csv"
curl -s -b "$AJAR" -c "$AJAR" -F "_csrf=$T" -F "action=preview" -F "datei=@$WORK/spielerinnen.csv" "$URL/admin/import" > "$WORK/r.html"
clean "CSV-Vorschau ohne Fehler" "$WORK/r.html"
expect "CSV: Umlaute richtig" "$WORK/r.html" "Jana Flückiger"
expect "CSV: Zählung neu/ergänzt/Fehler" "$WORK/r.html" "1 neu · 1 werden ergänzt · 1 mit Fehler"
expect "CSV: Handy mit führender 0" "$WORK/r.html" "0791112233"
expect "CSV: Geburtstag erkannt" "$WORK/r.html" "14.03.2011"
PAY=$(grep -o 'name="payload" value="[^"]*"' "$WORK/r.html" | sed 's/.*value="//;s/"$//'); SIG=$(grep -o 'name="sig" value="[^"]*"' "$WORK/r.html" | sed 's/.*value="//;s/"$//')
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=import" --data-urlencode "payload=$PAY" --data-urlencode "sig=x$SIG" "$URL/admin/import" > "$WORK/r.html"
expect "Manipulierte Vorschau abgelehnt" "$WORK/r.html" "Vorschau ist abgelaufen"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=import" --data-urlencode "payload=$PAY" --data-urlencode "sig=$SIG" "$URL/admin/import" > "$WORK/r.html"
expect "CSV importiert" "$WORK/r.html" "Import fertig: 1 neue Spielerinnen, 1 ergänzt"
[ "$(q "SELECT emergency_phone FROM kd_players WHERE first_name = 'Jana'")" = "0442223344" ] && ok "Notfalltelefon übernommen" || fail "Notfalltelefon fehlt"
[ "$(q "SELECT birthday FROM kd_players WHERE first_name = 'Nina'")" != "" ] && ok "Bestehende Angaben nicht überschrieben" || fail "Bestehender Geburtstag gelöscht"
# Excel-Datei (.xlsx) mit Datum als Excel-Zahl und Text in gemeinsamer Liste
cat > "$WORK/mkxlsx.php" <<'PHPX'
<?php
$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$z = new ZipArchive();
$z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Kader" sheetId="1" r:id="rId1"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="' . $ns . '"><si><t>Vorname</t></si><si><t>Name</t></si><si><t>E-Mail</t></si><si><t>Geburtstag</t></si><si><t>Elena</t></si><si><t>Rossi</t></si><si><t>elena@test.ch</t></si></sst>');
$z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" t="s"><v>6</v></c><c r="D2"><v>40617</v></c></row>'
    . '</sheetData></worksheet>');
$z->close();
PHPX
php "$WORK/mkxlsx.php" "$WORK/kader.xlsx"
curl -s -b "$AJAR" -c "$AJAR" -F "_csrf=$T" -F "action=preview" -F "datei=@$WORK/kader.xlsx" "$URL/admin/import" > "$WORK/r.html"
clean "Excel-Vorschau ohne Fehler" "$WORK/r.html"
expect "Excel: Name gelesen" "$WORK/r.html" "Elena Rossi"
expect "Excel: Datum aus Excel-Zahl (40617 = 15.03.2011)" "$WORK/r.html" "15.03.2011"
snap "15-admin-import" "$WORK/r.html"
printf 'nur Text' > "$WORK/x.xls"
curl -s -b "$AJAR" -c "$AJAR" -F "_csrf=$T" -F "action=preview" -F "datei=@$WORK/x.xls" "$URL/admin/import" > "$WORK/r.html"
expect "Alte .xls-Datei: Hinweis" "$WORK/r.html" "als «Excel-Arbeitsmappe (.xlsx)»"

echo "12c. Sicherungen (täglich, von Hand, wiederherstellen)"
ls "$APP"/data/backups/sicherung-*-taeglich.zip > /dev/null 2>&1 && ok "Tägliche Sicherung automatisch erstellt" || fail "Keine tägliche Sicherung"
[ "$(ls "$APP"/data/backups/sicherung-*-taeglich.zip | wc -l | tr -d ' ')" = "1" ] && ok "Nur eine tägliche Sicherung pro Tag" || fail "Mehrere tägliche Sicherungen"
code "$URL/data/backups/$(basename "$(ls "$APP"/data/backups/sicherung-*-taeglich.zip | head -1)")" > "$WORK/s.txt"; expect "Sicherungen vom Web aus gesperrt" "$WORK/s.txt" "403"
curl -s -b "$AJAR" "$URL/admin/sicherungen" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
clean "Sicherungen-Seite ohne Fehler" "$WORK/r.html"
expect "Tägliche Sicherung in der Liste" "$WORK/r.html" "täglich (automatisch)"
RUNS=$(q "SELECT COUNT(*) FROM kd_runs"); PLAYERS=$(q "SELECT COUNT(*) FROM kd_players")
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=create" "$URL/admin/sicherungen" > "$WORK/r.html"
expect "Sicherung von Hand erstellt" "$WORK/r.html" "Sicherung erstellt: sicherung-"
snap "16-admin-sicherungen" "$WORK/r.html"
BK=$(grep -o 'sicherung-[0-9]\{8\}-[0-9]\{6\}-manuell\.zip' "$WORK/r.html" | head -1)
curl -s -b "$AJAR" "$URL/admin/sicherungen/datei?name=$BK" > "$WORK/bk.zip"
unzip -p "$WORK/bk.zip" database.json > "$WORK/bk.json"
expect "Download enthält alle Läufe" "$WORK/bk.json" '"kd_runs"'
expect "Download enthält Einstellungen" "$WORK/bk.json" '"kd_team_name"'
expect "Download enthält Admins (Fullbackup)" "$WORK/bk.json" '"sandra@test.ch"'
code -b "$L" "$URL/admin/sicherungen/datei?name=$BK" > "$WORK/s.txt"; expect "Spielerin kann keine Sicherung laden" "$WORK/s.txt" "302"
code -b "$AJAR" "$URL/admin/sicherungen/datei?name=../config.php" > "$WORK/s.txt"; expect "Kein Zugriff auf andere Dateien" "$WORK/s.txt" "302"
# Daten ändern, dann wiederherstellen
php -r "require '$APP/app/bootstrap.php'; db_query('DELETE FROM kd_runs'); db_query(\"UPDATE kd_settings SET value = 'Falscher Name' WHERE name = 'kd_team_name'\");"
[ "$(q "SELECT COUNT(*) FROM kd_runs")" = "0" ] && ok "Läufe testweise gelöscht" || fail "Löschen hat nicht geklappt"
sleep 1
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=restore" --data-urlencode "datei=$BK" "$URL/admin/sicherungen" > "$WORK/r.html"
expect "Wiederhergestellt" "$WORK/r.html" "wiederhergestellt ($PLAYERS Spielerinnen, $RUNS Läufe)"
expect "Danach neu anmelden" "$WORK/r.html" "Bitte neu anmelden"
[ "$(q "SELECT COUNT(*) FROM kd_runs")" = "$RUNS" ] && ok "Alle Läufe wieder da" || fail "Läufe nicht wiederhergestellt"
[ "$(q "SELECT value FROM kd_settings WHERE name = 'kd_team_name'")" = "FC Test Girls" ] && ok "Einstellungen wieder da" || fail "Einstellungen nicht wiederhergestellt"
ls "$APP"/data/backups/sicherung-*-vorwiederherstellung.zip > /dev/null 2>&1 && ok "Vorheriger Stand automatisch gesichert" || fail "Keine Sicherheitskopie vor dem Wiederherstellen"
code -b "$AJAR" "$URL/admin" > "$WORK/s.txt"; expect "Nach Wiederherstellen alle abgemeldet" "$WORK/s.txt" "302"
curl -s -c "$AJAR" -b "$AJAR" -L -d "email=sandra@test.ch&password=trainerpasswort" "$URL/admin/anmelden" > "$WORK/r.html"
expect "Admin-Passwort aus Sicherung funktioniert" "$WORK/r.html" "Spielerpause"
T=$(csrf "$WORK/r.html")
# Hochladen einer Sicherung
curl -s -b "$AJAR" -c "$AJAR" -L -F "_csrf=$T" -F "action=upload" -F "datei=@$WORK/bk.zip" "$URL/admin/sicherungen" > "$WORK/r.html"
expect "Hochgeladene Sicherung wiederhergestellt" "$WORK/r.html" "wiederhergestellt"
curl -s -c "$AJAR" -b "$AJAR" -L -d "email=sandra@test.ch&password=trainerpasswort" "$URL/admin/anmelden" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
printf 'kein zip' > "$WORK/falsch.zip"
curl -s -b "$AJAR" -c "$AJAR" -L -F "_csrf=$T" -F "action=upload" -F "datei=@$WORK/falsch.zip" "$URL/admin/sicherungen" > "$WORK/r.html"
expect "Falsche Datei abgelehnt" "$WORK/r.html" "keine gültige Sicherung"
code -b "$AJAR" "$URL/admin" > "$WORK/s.txt"; expect "Falsche Datei ändert nichts (weiter angemeldet)" "$WORK/s.txt" "200"
# Cron
code "$URL/cron?schluessel=falsch" > "$WORK/s.txt"; expect "Cron mit falschem Schlüssel gesperrt" "$WORK/s.txt" "403"
CK=$(q "SELECT value FROM kd_settings WHERE name = 'kd_cron_key'")
curl -s "$URL/cron?schluessel=$CK" > "$WORK/s.txt"; expect "Cron mit richtigem Schlüssel" "$WORK/s.txt" "Heute bereits gesichert"
# Spielerinnen müssen sich nach dem Wiederherstellen über ihren Link neu anmelden
play "$L" "$(q "SELECT access_token FROM kd_players WHERE first_name = 'Lea'")"

echo "13. Link erneuern, deaktivieren, löschen"
curl -s -b "$AJAR" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"; T=$(csrf "$WORK/r.html")
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=link_new" "$URL/admin/spielerin/$LEA" > "$WORK/r.html"
expect "Neuer Link erstellt" "$WORK/r.html" "Neuer Link erstellt"
curl -s -b "$L" "$URL/" > "$WORK/r.html"
expect "Altes Gerät abgemeldet" "$WORK/r.html" "Bitte deinen persönlichen Link öffnen"
curl -s "$URL/zugang/$LEA_T" > "$WORK/r.html"
expect "Alter Link ungültig" "$WORK/r.html" "Dieser Link funktioniert nicht mehr"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=deactivate" "$URL/admin/spielerin/$SARA" > "$WORK/r.html"
expect "Spielerin deaktiviert" "$WORK/r.html" "Sara ist deaktiviert"
code -b "$S" "$URL/meine" > "$WORK/s.txt"; expect "Deaktivierte Spielerin ausgesperrt" "$WORK/s.txt" "302"
curl -s -b "$AJAR" -c "$AJAR" -L --data-urlencode "_csrf=$T" -d "action=delete" "$URL/admin/spielerin/$MIA" > "$WORK/r.html"
expect "Spielerin gelöscht" "$WORK/r.html" "Mia Probe und alle Läufe wurden gelöscht"
[ "$(q "SELECT COUNT(*) FROM kd_runs WHERE player_id = $MIA")" = "0" ] && ok "Läufe der gelöschten Spielerin entfernt" || fail "Läufe nicht entfernt"

echo "14. Abmelden"
curl -s -b "$AJAR" -c "$AJAR" -L -d "_csrf=$T" "$URL/admin/abmelden" > "$WORK/r.html"
expect "Admin abgemeldet" "$WORK/r.html" "Abgemeldet"
code -b "$AJAR" "$URL/admin" > "$WORK/s.txt"; expect "Nach Abmelden kein Zugang" "$WORK/s.txt" "302"

if [ -n "$SNAP" ]; then cp "$APP/assets/kondition.css" "$APP"/assets/icon* "$SNAP/"; fi
if grep -qi "error\|warning" "$WORK/server.log"; then echo "----- Server-Protokoll -----"; grep -i "error\|warning" "$WORK/server.log" | head -20; fi

echo
if [ "$FAILS" -gt 0 ]; then echo "$FAILS Prüfung(en) fehlgeschlagen."; exit 1; fi
echo "Alle Prüfungen bestanden."
