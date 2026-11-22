# Arbeitsschritte – Fussball-Konditionstraining

Jeder Schritt wird hier dokumentiert und einzeln committet. Die Versionsnummer steht in `VERSION` und wird in der App unten mit dem Commit angezeigt.

## Schritt 1 – Erste Version (im Repository ki-smartwork.ch entstanden, 22.11.2026)

Web-App unter **https://ki-smartwork.ch/app/fussballtraining/** (veröffentlicht am 22.11.2026) für das Konditionstraining einer Frauen-Fussballmannschaft. Läuft auf Handy und Computer, ohne App-Store. Klickbare Vorschau mit Beispieldaten (privates Claude-Artefakt): https://claude.ai/artifact/6ggJm8wTpY5YLcgzsde5iY

**Spielerinnen** – jede bekommt einen **persönlichen Link** (per E-Mail oder WhatsApp), kein Konto und kein Passwort. Sie sieht nur ihren eigenen Bereich und die allgemeinen Infos.
- *Lauf eintragen*: Datum, Länge der Strecke **frei wählbar in Metern** (Abkürzungen 400 / 1000 / 2400 / 3000 / 5000 m), Laufzeit in Minuten und Sekunden, freiwillige Bemerkung. Wer «3» statt «3000» eintippt, bekommt den Hinweis «3 km = 3’000 m».
- *Geschwindigkeit* wird immer frisch aus Metern und Sekunden berechnet: Meter ÷ Sekunden = m/s, × 3.6 = km/h; dazu die Zeit pro 1000 m. Wird schon beim Eintippen angezeigt.
- *Meine Läufe*: Bestzeit je Strecke, **Entwicklungskurve** (Geschwindigkeit über die Zeit, eine Linie pro Strecke), Trend, Verbesserungspotenzial (Abstand zur eigenen Bestzeit und zur Mannschaftsbesten), Feedback und Notizen vom Trainerteam.
- *Infos*: allgemeine Infos vom Trainerteam (wichtige oben).
- Am Geburtstag erscheint ein Gruss.

**Admin-Bereich (Trainerteam)** unter …/app/fussballtraining/admin, mit E-Mail und Passwort, **mehrere Admins** möglich.
- *Übersicht*: Kennzahlen, Geburtstage der nächsten 30 Tage (am Tag selbst «Gratulieren per WhatsApp / E-Mail»), Spielerinnen in Pause, **Mannschaftskurve** pro Strecke (eine Linie pro Spielerin), Verbesserungspotenzial je Spielerin.
- *Spielerinnen* (Spielerverwaltung): Name bearbeiten, E-Mail, Handy, Geburtstag, **Notfallkontakt / Eltern** (Name, Beziehung, Telefon, E-Mail), **Spielerpause** (von–bis, Grund), interne Notiz. Persönlichen Link kopieren, per E-Mail oder WhatsApp senden, neu erstellen (alter Link wird ungültig), Spielerin deaktivieren oder löschen. Eigene Kurve und Läufe pro Spielerin.
- *Läufe*: Filter, **Feedback pro Lauf** (👍 Daumen hoch, 👏 Bravo!, 💪 Weiter so!, ⭐ Ich bin stolz auf dich!, 🎯 Das geht noch ein bisschen besser), Notiz, Löschen, Export als CSV (Excel).
- *Nachrichten*: an Spielerinnen und/oder Eltern/Notfallkontakte, alle oder ausgewählte. Per E-Mail direkt vom Server (Absender: Name der Mannschaft, Antworten gehen an den sendenden Admin) oder per WhatsApp-Knopf pro Person (öffnet WhatsApp mit fertigem Text; senden tippt man selbst). Platzhalter {vorname}, {name}, {team}, {admin}.
- *Infos*: allgemeine Infos für alle Spielerinnen schreiben, bearbeiten, löschen.
- *Admins*: weitere Admins per Einladungslink (7 Tage gültig, nur einmal verwendbar) einladen, deaktivieren, entfernen. Man kann sich nicht selbst entfernen; es bleibt immer mindestens ein Admin.
- *Einstellungen*: Name der Mannschaft, eigenes Profil, Passwort ändern, Sicherung herunterladen (JSON, ohne Passwörter), alle Spielerinnen abmelden.

**Design in den Vereinsfarben des FC Wädenswil** (geprüft auf fcwaedenswil.ch, Farben aus dem Vereinswappen ausgelesen): Blau #006DAD für Knöpfe und Links, Gelb #F5CF1A als Akzent (Linie unter dem Kopf, Ball-Symbol, gewählte Strecke, Bestzeit-Kacheln, Unterstreichung der Titel), Dunkelblau #1D394A für den Kopfbalken. Eigene Farben für den Mädchenfussball gibt es auf der Vereinsseite keine; es gelten die Vereinsfarben. Hell und dunkel angepasst. Das Vereinslogo selbst ist nicht eingebaut (nur mit Erlaubnis des Vereins).

**Einrichtung**: einmalig unter …/app/fussballtraining/einrichten – nur möglich, solange man im Admin von ki-smartwork.ch angemeldet ist. Dort Mannschaftsname, Name, E-Mail und Passwort des ersten Admins festlegen.

**Sicherheit**: persönliche Links und Einladungen mit 48-stelligem Zufallsschlüssel (widerrufbar), Passwörter nur als Hash, CSRF-Schutz bei allen Formularen, Bremse gegen Durchprobieren von Links und Passwörtern, Cookies nur für /kondition (HttpOnly, SameSite), Sicherheits-Header (Content-Security-Policy, kein Einbetten in fremde Seiten, noindex), Programmcode gesperrt, Rollen strikt getrennt (Spielerin sieht nur sich selbst), CSV-Export gegen Excel-Formeln geschützt. Deaktivierte Spielerinnen und Admins sind sofort abgemeldet.

**Sicherung (Backup)**: Die tägliche automatische Website-Sicherung (`backup_create`, Cron) enthält jetzt auch die Tabellen `kd_admins`, `kd_players`, `kd_runs`, `kd_infos`; die Wiederherstellung im Admin der Hauptseite stellt sie mit her. Zusätzlich Download im Admin-Bereich des Konditionstrainings.

**Technik**: Code in `site/kondition/` (index.php, app/lib.php, app/pages.php, app/admin.php, assets/kondition.css), Tabellen `kd_admins`, `kd_players`, `kd_runs`, `kd_infos`, `kd_sessions`, `kd_attempts` (werden automatisch angelegt), Einstellungen `kd_…` in `settings`. Kurven als SVG direkt aus PHP, keine fremden Dienste. Automatischer Test `tests/kondition.sh` (über 100 Prüfungen) läuft im Test- und im Deploy-Workflow; auf Arbeits-Branches speichert der Workflow «Vorschau Konditionstraining» die Seiten mit Beispieldaten im Ordner `vorschau-kondition/` (wird vor dem Veröffentlichen entfernt). Arbeits-Branch: `konditionstraining`.

**Veröffentlichung und Adresse (22.11.2026)**: Die App ist live unter https://ki-smartwork.ch/app/fussballtraining/. Der Code liegt weiterhin im Ordner `site/kondition/`, weil `site/app/` der gesperrte Programmcode der Website ist. Darum zwei gezielte Regeln:
- `site/.htaccess`: nur `/app/fussballtraining/…` wird intern auf `kondition/index.php` (bzw. `kondition/assets/…`) umgeleitet.
- `site/app/.htaccess`: die Sperre gilt weiter für alles unter `/app/`, mit einer einzigen Ausnahme für `/app/fussballtraining`.
- Die alte Adresse `/kondition/…` leitet dauerhaft (301) auf die neue um.
- Live geprüft: App antwortet (Weiterleitung zur Einrichtung), `/app/config.php`, `/app/bootstrap.php`, `/app/lib/…` weiterhin gesperrt (403), Hauptseite und Bewerbungsportal unverändert erreichbar, Sicherheits-Header aktiv. Die Datenbanktabellen wurden beim ersten Aufruf automatisch angelegt.
- Nächster Schritt (Inhaberin): im Admin von ki-smartwork.ch anmelden, dann https://ki-smartwork.ch/app/fussballtraining/einrichten öffnen und Mannschaftsname, Name, E-Mail und Passwort des ersten Admins festlegen.

## Schritt 2 – Eigenes Repository «Fussball», Excel-Import, Version 1.0.0 (22.11.2026)

**Umzug**: Die App liegt jetzt im eigenen Repository https://github.com/katharinadoenni/Fussball (vorher Teil von `ki-smartwork.ch`, Ordner `site/kondition/`, Schritt 30 dort). Sie ist eigenständig:
- eigener Einstiegspunkt `public/app/bootstrap.php` (Datenbank, Einstellungen in Tabelle `kd_settings`, E-Mail-Protokoll in `kd_mail_log`), eigene PHP-Bibliothek PHPMailer (composer),
- nutzt auf dem Server weiterhin die Datenbank und das Postfach der Website (ohne eigene `config.php`),
- die erste Einrichtung prüft die Anmeldung im Admin von ki-smartwork.ch über deren Sitzung,
- eigener Test (`tests/fussball.sh`) und eigenes Hochladen nach `/sites/ki-smartwork.ch/fussball/`.

**Neu**
- *Excel-Import* (Admin → Spielerinnen → «Aus Excel importieren»): .xlsx oder .csv, Vorlage zum Herunterladen, erkennt auch andere Überschriften (z.B. «Nachname», «Natel», «Geburtsdatum», «Mutter»), Excel-Datumszahlen, Umlaute aus Windows-Excel und verlorene führende Nullen bei Handynummern. Zuerst Vorschau (neu / wird ergänzt / Fehler), dann «Jetzt importieren». Bestehende Spielerinnen (gleicher Vor- und Nachname) werden nur ergänzt, leere Zellen überschreiben nichts. Höchstens 2 MB und 500 Zeilen; die Vorschau ist gegen Manipulation signiert.
- *Version und Commit* unten in jeder Seite (z.B. «Version 1.0.0 · Commit abc1234 · 22.11.2026»), beim Hochladen automatisch gesetzt.
- *Gleich hohe Eingabefelder* (auch E-Mail und Telefon), *Datum als Tag / Monat / Jahr* (Laufdatum, Geburtstag, Pause, Filter), *Infos als Popup* mit *roter Zahl* für ungelesene Infos, grössere Knöpfe auf Touch-Geräten (Handy zuerst).
- *Testprotokoll*: nach jedem Lauf im Branch `testprotokoll` (inkl. Vorschau-Seiten mit Beispieldaten).

**Sicherheit**: `config.php`, `version.php`, `app/` und `data/` sind vom Web aus gesperrt; Import nur für Admins, mit CSRF-Schutz und signierter Vorschau; XML wird ohne Netzwerkzugriff gelesen.
