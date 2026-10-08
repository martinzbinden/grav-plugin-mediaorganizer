# Grav-Plugin «Media Organizer»

Datei-Browser für die Mediathek (`user/media`) als eigene Seite im **Admin2**
(Grav 2): Ordnerbaum, Galerie und sortierbare Liste, Vorschau, Herunterladen
einzeln, als Ordner-ZIP oder als ZIP einer Auswahl. Dazu Texte (Titel,
Alt-Text, Beschriftung in `<datei>.meta.yaml`) und – bei Bildern – EXIF
bearbeiten, Bilder optimieren (verkleinern/neu komprimieren, Original in
`_original/`) und wiederherstellen.

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

## Voraussetzungen

Grav 2 mit den Plugins `api` und `admin2`; PHP mit GD (Optimieren) und
ZipArchive (ZIP-Downloads).

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
`POST optimieren {pfade, max, qualitaet}`, `POST wiederherstellen {pfade}`.

## Lizenz

MIT
