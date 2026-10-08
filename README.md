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
`POST optimieren {pfade, max, qualitaet}`, `POST konvertieren {pfade}`,
`POST wiederherstellen {pfade}`, `verweise?pfad=`,
`POST umbenennen {pfad, name}`, `POST kopieren {pfade, ziel}`,
`POST papierkorb {pfade}`, `verlauf`, `POST rueckgaengig {id, erzwingen}`.
Alle Dateiaufrufe nehmen `quelle=medien|seiten` (Standard `medien`).

## Lizenz

MIT
