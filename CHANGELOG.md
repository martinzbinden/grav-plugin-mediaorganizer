# Changelog

## 1.4.0 – 2026-11-07

- **Downloadseite** aus dem bisherigen Plugin `medienseite` übernommen
  (`medienseite.aktiv`, Standard aus): Seite mit Vorlage `medienseite`,
  Downloads in Bereichen, je Bereich öffentlich oder nur für Registrierte,
  Anmeldung per E-Mail-Code mit Registrierung und Freigabe-Link, Verwaltung
  direkt auf der Seite. Routen `/mediendaten/…` unverändert.
- Ablage standardmässig die Mediathek (`user/media`): die Bereiche sind Ordner
  im Medienarchiv und dort mit 🌐 (öffentlich) bzw. 🔒 (nach Anmeldung) markiert.
  Ein eigener Ablageordner (`medienseite.media_dir`) bleibt möglich.
- Die `.htaccess` der obersten Ablage-Ebene wird in der Mediathek nur mit
  ausdrücklichem `medienseite.wurzel_oeffentlich` geschrieben.
- Bereiche der Downloadseite werden nicht automatisch umgewandelt
  (`medienseite.konvertieren`, Standard aus).
- Läuft das frühere Plugin `medienseite` noch, bleibt die Downloadseite aus.

## 1.3.0 – 2026-10-11

- Flexibles Layout und Mobilansicht.

## 1.2.1 – 2026-10-08

- Verlauf: nur tatsächlich betroffene Dateien zeigen, Grösse als Sicherung.

## 1.2.0 – 2026-10-08

- Seitenmedien, Verweise, Verlauf mit Rückgängig.

## 1.1.0 – 2026-10-08

- Konvertierung (WebP/JPEG/AVIF) und EXIF für JPEG.

## 1.0.0 – 2026-10-08

- Datei-Browser für die Mediathek im Admin2.
