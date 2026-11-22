# Fussball-Konditionstraining

Web-App für das Konditionstraining der Juniorinnen des FC Wädenswil – für Spielerinnen (Läufe eintragen, eigene Entwicklung sehen) und das Trainerteam (Spielerverwaltung, Feedback, Nachrichten).

- **Live:** https://ki-smartwork.ch/app/fussballtraining/
- **Erste Einrichtung (einmalig):** https://ki-smartwork.ch/app/fussballtraining/einrichten – nur möglich, wenn man im Admin von ki-smartwork.ch angemeldet ist
- **Admin-Bereich:** https://ki-smartwork.ch/app/fussballtraining/admin
- **Dokumentation:** [docs/SCHRITTE.md](docs/SCHRITTE.md)

## Aufbau

```
public/            ← kommt auf den Server nach /sites/ki-smartwork.ch/fussball/
  index.php        Router (alle Seiten)
  app/             Programmcode (vom Web aus gesperrt)
    bootstrap.php  Konfiguration, Datenbank, Hilfsfunktionen
    lib.php        Zugang, Auswertung, Kurven, E-Mail, Seitengerüst
    pages.php      Seiten der Spielerinnen
    admin.php      Admin-Bereich
    import.php     Import aus Excel/CSV
  assets/          Design (Farben FC Wädenswil)
tests/fussball.sh  Automatischer Rundgang (über 130 Prüfungen)
VERSION            Versionsnummer (wird mit dem Commit in der App angezeigt)
```

## Server

- Die öffentliche Adresse `/app/fussballtraining` leitet die Website ki-smartwork.ch (Repository `ki-smartwork.ch`, Datei `site/.htaccess`) auf den Ordner `/sites/ki-smartwork.ch/fussball/` um. Der übrige Ordner `/app/` der Website bleibt gesperrt.
- **Konfiguration:** Ohne eigene `config.php` nutzt die App die Datenbank und das Postfach der Website auf demselben Server. Optional kann auf dem Server `fussball/config.php` angelegt werden (`db`, `smtp`, `base_url`) – sie liegt nie im Repository.
- **Sicherung:** Die Tabellen `kd_…` liegen in der Datenbank der Website und sind in deren täglicher Sicherung enthalten. Zusätzlich gibt es im Admin-Bereich einen Download.

## Abläufe (GitHub Actions)

- **Test** – bei jedem Push auf einen Arbeits-Branch.
- **Deploy auf Infomaniak** – bei jedem Push auf `main`: testen, Version und Commit eintragen, hochladen. Braucht die Repository-Secrets `FTP_USERNAME` und `FTP_PASSWORD`.
- Testprotokoll und Vorschau-Seiten mit Beispieldaten stehen nach jedem Lauf im Branch `testprotokoll`.
