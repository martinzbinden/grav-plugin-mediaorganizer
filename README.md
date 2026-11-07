# Grav-Plugin «Media Organizer»

Datei-Browser für die Mediathek (`user/media`) als eigene Seite im **Admin2**
(Grav 2): Ordnerbaum, Galerie und sortierbare Liste, Vorschau, Herunterladen
einzeln, als Ordner-ZIP oder als ZIP einer Auswahl. Dazu Texte (Titel,
Alt-Text, Beschriftung in `<datei>.meta.yaml`) und – bei Bildern – EXIF
bearbeiten (WebP und JPEG, verlustfrei), Bilder optimieren (verkleinern/neu
komprimieren, Original in `_original/`), wiederherstellen und in ein
Standardformat umwandeln. Auch die Bilder und Dateien in den **Seitenordnern**
lassen sich zentral sichten, umwandeln, umbenennen und aufräumen – mit
**Verlauf und Rückgängig**.

- **Alle Dateitypen** werden gelistet und lassen sich herunterladen
  (PDF, SVG, Office, ZIP …). SVG mit Vorschau, PDF und SVG auch im Browser
  öffnen. Vollbild mit Zoom, EXIF und Optimieren gibt es für Rasterbilder.
- **Auswahl** wie im Dateimanager: Klick, Strg/⌘-Klick, Umschalt-Klick,
  Pfeiltasten, Strg/⌘+A; Massenbearbeitung von Texten und EXIF.
- **Geschützt:** alle Zugriffe über die Admin-API mit Anmeldung
  (`api.media.read` zum Ansehen und Herunterladen, `api.media.write` zum
  Ändern). Dateien müssen nicht öffentlich erreichbar sein.
- Ausgeblendet: Dateien mit Punkt am Anfang, `*.meta.yaml`,
  `media_order.yaml`, Ordner `_original` und konfigurierbare Ordner.

Entstanden aus dem Medienarchiv des Plugins `riedackerhof-templates`
(riedackerhof.ch), verallgemeinert für beliebige Dateitypen.

Optional zusätzlich eine **öffentliche Downloadseite** (siehe unten, früher
eigenes Plugin `medienseite`).

## Layout und Mobilgeräte

- **Arbeitsfokus umschalten:** Knöpfe «Ordner» und «Details» (Tasten **B** und **D**)
  blenden Ordnerbaum und Detailspalte ein oder aus, z. B. «nur Galerie». Die Wahl
  bleibt im Browser gespeichert.
- **Schmal** (Plugin-Bereich bis 900 px: Handy, Tablet hochkant, schmales Fenster):
  Galerie in voller Breite, Ordner und Details als einschiebbare Seitenleisten.
  Antippen einer Datei öffnet die Details; «Auswählen» schaltet auf Mehrfachauswahl
  per Antippen. Die Liste zeigt nur Name, Aufnahme und Grösse.
- **Vollbild per Touch:** Zwei-Finger-Zoom, Wischen zum nächsten/vorherigen Bild,
  Doppeltippen zum Zoomen bzw. Einpassen.

## Konvertierung

Optional (`konvertierung.aktiv`): Bilder, die im Admin in die Mediathek
hochgeladen werden oder in einem geöffneten Ordner liegen, werden ins
Zielformat umgewandelt – Standard **WebP**, wahlweise **JPEG** oder **AVIF**
(nur wenn GD es kann). Einstellbar sind die umzuwandelnden Quellformate, die
Qualität und verlustfreies WebP für PNG. JPEGs werden nach der EXIF-Ausrichtung
gedreht, EXIF-Daten (bei WebP/JPEG als Ziel), `.meta.yaml` und
`media_order.yaml` gehen mit. Transparente Bilder werden nicht zu JPEG.
Im Medienarchiv lassen sich Bilder auch von Hand umwandeln.

```yaml
konvertierung:
  aktiv: true
  zielformat: webp        # webp | jpg | avif
  quellformate: [jpg, png]
  qualitaet: 90
  png_verlustfrei: true
```

## Seitenmedien

Reiter «Seitenmedien»: alle Seitenordner mit Dateien (mit Seitentitel), dieselbe
Galerie/Liste wie in der Mediathek. Zusätzlich:

- **Verweise:** zu jeder Datei «Verwendet in» (Seiten-Markdown, Kopfbereich
  wie `hero.image`/`media_order`, Konfiguration); Kachel-Hinweis «kein
  Verweis». Erkannt werden der blosse Dateiname im eigenen Seitenordner,
  `user/pages/…`, die Seitenroute und bei der Mediathek `media://…`,
  `user://media/…`, `user/media/…` (auch URL-kodiert). Twig-Vorlagen und
  Sammlungen werden nicht erkannt.
- **Umwandeln/Optimieren/Umbenennen:** ändert sich der Dateiname, werden die
  Verweise in den Seiten mitgeführt.
- **In die Mediathek kopieren**, **In den Papierkorb**.
- Rechte: Ansehen `api.pages.read`, Ändern `api.pages.write`.

## Verlauf und Rückgängig

Jede Änderung (Texte/EXIF, Umwandeln, Optimieren, Umbenennen, Kopieren,
Papierkorb, angepasste Seiten) ist ein Vorgang im Reiter «Verlauf». Vorher
werden alle betroffenen Dateien gesichert (`user/data/mediaorganizer/verlauf`,
per `.htaccess` gesperrt). «Rückgängig» stellt den Zustand davor wieder her,
auch direkt aus der Meldung nach einer Aktion. Wurde eine Datei seither erneut
geändert, wird nachgefragt (die spätere Fassung wird ebenfalls gesichert).
Rückgängig ist selbst ein Vorgang und lässt sich wieder aufheben.
Aufräumen nach Alter und Gesamtgrösse:

```yaml
verlauf:
  tage: 60        # aeltere Vorgaenge entfernen
  max_mb: 1000    # danach die aeltesten, bis die Sicherungen darunter liegen
```

## Downloadseite

Abschaltbar, Standard aus (`medienseite.aktiv: false`). Eine Seite mit der
Vorlage `medienseite` zeigt Downloads in Bereichen (z.B. Pressebilder,
Berichte, Logos). Jeder Bereich ist öffentlich oder nur für registrierte
Personen zugänglich.

- **Anmeldung per E-Mail-Code**, ohne Passwort. Neue Personen registrieren sich
  (Name, Organisation/Medium, Einwilligung, Rechenfrage), bestätigen die Adresse
  per Code und werden per Freigabe-Link freigeschaltet (oder sofort, Modus
  `open`). Konten mit Admin-Rechten melden sich mit Passwort an.
- **Ablage:** standardmässig die Mediathek. Je Bereich ein Ordner, der im
  Medienarchiv mit 🌐 (öffentlich) oder 🔒 (nach Anmeldung) markiert ist; was
  dort hochgeladen wird, steht auch auf der Downloadseite und umgekehrt.
  Das Plugin schreibt je Bereich eine `.htaccess`: öffentliche Bereiche sind
  direkt abrufbar, gesperrte nur über `/mediendaten/…` nach der Prüfung. Die
  oberste Ebene der Mediathek bleibt unangetastet, ausser
  `wurzel_oeffentlich` ist gesetzt. Bilder in den Bereichen werden nicht
  automatisch umgewandelt (`konvertieren: false`).
- **Verwaltung direkt auf der Seite** (Recht `verwalten_permission`, Standard
  `admin.login`): Hochladen, Titel/Beschreibung/Copyright, Löschen. Erlaubt:
  Bilder, SVG (mit Sandbox-Header), PDF, ZIP, Office, EPS/AI.
- **Vorschaubilder** für Bilder (GD) sowie TIFF/PDF (Imagick, PDF mit Ghostscript).
- Anmeldedaten (Codes, Freigaben) liegen getrennt in `daten_dir`
  (Standard `user/data/medienseite`); die Konten sind normale Grav-Konten mit
  `site.login`.

Braucht zusätzlich die Plugins `login` und `email` (Mailversand eingerichtet).
Die Anmeldung per E-Mail-Code stammt aus dem Heftarchiv-Plugin von
schwand-baeregg.ch.

Einrichten: Seite anlegen, z.B. `user/pages/07.medien/medienseite.md`:

```yaml
---
title: Medien
visible: false
cache_enable: false
---
Einleitungstext …
```

und in `user/config/plugins/mediaorganizer.yaml` (oder im Admin, Abschnitt
«Downloadseite»):

```yaml
medienseite:
  aktiv: true
  seitenname: 'example.ch'      # leer = Titel der Website
  seite: '/medien'
  datenschutz_seite: '/datenschutz'
  media_dir: ''                 # leer = Mediathek; sonst relativ zum Grav-Ordner
  wurzel_oeffentlich: null      # true/false = .htaccess der obersten Ebene verwalten
  auth:
    modus: approval             # oder open
    benachrichtigung: 'webmaster@example.ch'
  bereiche:
    - { schluessel: pressebilder, titel: 'Pressebilder', oeffentlich: false }
    - { schluessel: logos, titel: 'Logos', oeffentlich: true }
```

Alle Optionen mit Kommentaren: `mediaorganizer.yaml`.

| Route | Zweck |
|---|---|
| `/mediendaten/datei/<bereich>/<datei>` | Datei (`?dl=1` = Download) |
| `/mediendaten/vorschau/<bereich>/<datei>` | Vorschaubild |
| `/mediendaten/auth/start`, `/verify` | Anmeldung (JSON, POST) |
| `/mediendaten/auth/freigeben?token=…` | Freigabe einer Registrierung |
| `/mediendaten/verwalten` | Hochladen/Bearbeiten/Löschen (POST, Nonce) |

### Umstieg vom Plugin `medienseite`

Seiten mit der Vorlage `medienseite`, Routen, Konten und Anmeldedaten bleiben,
wie sie sind. Je Website:

1. mediaorganizer ab 1.4.0 installieren.
2. Den Inhalt von `user/config/plugins/medienseite.yaml` (ohne `enabled`)
   eingerückt unter `medienseite:` in `user/config/plugins/mediaorganizer.yaml`
   übernehmen und `aktiv: true` ergänzen.
3. **`media_dir` mitnehmen**, wenn es gesetzt war (früherer Standard
   `../medienseite-dateien`), sonst gilt neu die Mediathek. Ist `user/media`
   schon eine Verknüpfung auf diesen Ordner, kann `media_dir` auch leer bleiben.
4. Das Plugin `medienseite` deaktivieren und entfernen. Solange es aktiv ist,
   bleibt die Downloadseite von mediaorganizer aus (Hinweis im Log).
5. Cache leeren, Seite und eine gesperrte Datei prüfen.

Eigene Vorlagen im Theme, die `config.plugins.medienseite.…` lesen, auf die
Twig-Variable `medienseite` umstellen (z.B. `medienseite.datenschutz_seite`).

## Voraussetzungen

Grav 2 mit den Plugins `api` und `admin2`; PHP mit GD (Optimieren) und
ZipArchive (ZIP-Downloads). Für die Downloadseite zusätzlich `login` und `email`.

## Einrichten

1. Plugin nach `user/plugins/mediaorganizer` kopieren.
2. Im Admin erscheint in der Seitenleiste «Medienarchiv»
   (Route `/admin/plugin/mediaorganizer`).
3. Optionen in `user/config/plugins/mediaorganizer.yaml` (oder im Admin):

   ```yaml
   titel: 'Medienarchiv'   # Name in der Seitenleiste
   icon: 'fa-images'
   ausblenden: []          # weitere Ordner ausblenden
   zip_praefix: ''         # leer = Hostname
   ```

## Schnittstellen

`/api/v1/mediaorganizer/…`: `baum`, `dateien?ordner=&rekursiv=1`,
`vorschau?pfad=&w=&crop=1`, `exif?pfad=`, `datei?pfad=`,
`zip?ordner=` bzw. `POST zip {pfade}`, `POST meta {pfade, felder}`,
`POST optimieren {pfade, max, qualitaet}`, `POST konvertieren {pfade}`,
`POST wiederherstellen {pfade}`, `verweise?pfad=`,
`POST umbenennen {pfad, name}`, `POST kopieren {pfade, ziel}`,
`POST papierkorb {pfade}`, `verlauf`, `POST rueckgaengig {id, erzwingen}`.
Alle Dateiaufrufe nehmen `quelle=medien|seiten` (Standard `medien`).

## Lizenz

MIT
