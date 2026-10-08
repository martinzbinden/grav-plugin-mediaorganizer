<?php

declare(strict_types=1);

namespace Grav\Plugin\Mediaorganizer;

use Grav\Common\Cache;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\MediaorganizerPlugin;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Admin2-Schnittstellen des Media Organizers.
 *
 * Zwei Ablagen (Parameter quelle): «medien» = Mediathek user://media,
 * «seiten» = Dateien in den Seitenordnern user://pages (nur Medientypen).
 * Rechte: Lesen api.media.read (Seiten zusaetzlich api.pages.read), Aendern
 * api.media.write (Seiten zusaetzlich api.pages.write).
 *
 * Jede Aenderung laeuft als Vorgang im Verlauf (classes/Journal.php): die
 * betroffenen Dateien und angepassten Seiten werden vorher gesichert und lassen
 * sich zurueckholen. Bei neuen Dateinamen (Umwandeln, Umbenennen) werden
 * Verweise in Seiten und Konfiguration nachgefuehrt (classes/Verweise.php).
 */
class ApiController extends AbstractApiController
{
    private const BILD = MediaorganizerPlugin::BILD;
    private const META_FELDER = ['title', 'alt', 'caption'];
    private const MAX_PIXEL = 25000000;

    /** Ablage dieses Requests: medien oder seiten */
    private string $q = 'medien';
    private string $benutzer = 'Admin';

    private function plugin(): MediaorganizerPlugin
    {
        return MediaorganizerPlugin::instance();
    }

    /** Ablage bestimmen und Rechte pruefen */
    private function start(ServerRequestInterface $request, bool $schreiben): void
    {
        $q = $request->getQueryParams()['quelle'] ?? null;
        if ($q === null && $request->getMethod() === 'POST') {
            $q = $this->getRequestBody($request)['quelle'] ?? null;
        }
        $this->q = MediaorganizerPlugin::quelle(is_string($q) ? $q : null);
        $this->requirePermission($request, $schreiben ? 'api.media.write' : 'api.media.read');
        if ($this->q === 'seiten') {
            $this->requirePermission($request, $schreiben ? 'api.pages.write' : 'api.pages.read');
        }
        try {
            $this->benutzer = (string) ($this->getUser($request)->username ?? 'Admin');
        } catch (\Throwable) {
        }
    }

    private function vorgang(string $aktion, string $text): Journal
    {
        return $this->plugin()->neuerVorgang($aktion, $text, $this->q, $this->benutzer);
    }

    private function root(): string
    {
        return $this->plugin()->root($this->q);
    }

    /** Gepruefter Pfad einer Datei (relativ zur Mediathek) -> absolut. */
    private function dateiPfad(string $rel): string
    {
        $abs = $this->plugin()->datei($rel, $this->q);
        if (!$abs) {
            throw new NotFoundException("Datei nicht gefunden: $rel");
        }

        return $abs;
    }

    /** Wie dateiPfad(), aber nur Rasterbilder (EXIF, Optimieren) */
    private function bild(string $rel): string
    {
        $abs = $this->dateiPfad($rel);
        if (!preg_match(self::BILD, $abs)) {
            throw new ValidationException("Kein Bild: $rel");
        }

        return $abs;
    }

    private function rel(string $abs): string
    {
        return ltrim(substr($abs, strlen($this->root())), '/');
    }

    private function pfade(ServerRequestInterface $request): array
    {
        $body = $this->getRequestBody($request);
        $pfade = array_values(array_filter((array) ($body['pfade'] ?? []), 'is_string'));
        if (!$pfade) {
            throw new ValidationException('Keine Dateien ausgewählt.');
        }
        if (count($pfade) > 2000) {
            throw new ValidationException('Zu viele Dateien auf einmal.');
        }

        return $pfade;
    }

    /* ---------------- Lesen ---------------- */

    /** GET …/baum — Ordnerbaum mit Bildzahlen (direkt und inkl. Unterordner). */
    public function baum(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $root = $this->root();
        $walk = function (string $abs, string $rel) use (&$walk): array {
            $kinder = [];
            $direkt = 0;
            foreach (scandir($abs) ?: [] as $f) {
                if ($f[0] === '.') {
                    continue;
                }
                if (is_dir("$abs/$f")) {
                    if (!$this->plugin()->ausgeblendet($f)) {
                        $kinder[] = $walk("$abs/$f", ltrim("$rel/$f", '/'));
                    }
                } elseif ($this->plugin()->istSichtbar($f, $this->q)) {
                    $direkt++;
                }
            }
            usort($kinder, function ($a, $b) {
                if ($this->q === 'seiten') {
                    return strnatcasecmp($a['ordner'], $b['ordner']);
                }
                $da = (bool) preg_match('/^\d{4}/', $a['name']);
                $db = (bool) preg_match('/^\d{4}/', $b['name']);

                return $da !== $db ? ($da ? -1 : 1) : ($da ? strnatcasecmp($b['name'], $a['name']) : strnatcasecmp($a['name'], $b['name']));
            });

            if ($this->q === 'seiten') {
                $kinder = array_values(array_filter($kinder, fn ($k) => $k['total'] > 0));
            }
            $name = $rel === '' ? ($this->q === 'seiten' ? 'Seiten' : $this->plugin()->titel()) : basename($rel);
            $titel = ($this->q === 'seiten' && $rel !== '') ? $this->seitentitel($abs) : null;

            return ['name' => $titel ?: $name, 'ordner' => basename($rel), 'path' => $rel, 'direkt' => $direkt,
                'total' => $direkt + array_sum(array_column($kinder, 'total')), 'kinder' => $kinder];
        };
        $baum = $walk($root, '');
        $baum['quelle'] = $this->q;
        $baum['konvertierung'] = $this->plugin()->konvertierungInfo();

        return ApiResponse::create($baum);
    }

    /** GET …/dateien?ordner=…&rekursiv=1 — alle Dateien mit Eigenschaften (Bilder mit EXIF-Kurzfassung). */
    public function dateien(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $q = $request->getQueryParams();
        $abs = $this->plugin()->pfad((string) ($q['ordner'] ?? ''), $this->q);
        if (!$abs || !is_dir($abs)) {
            throw new NotFoundException('Ordner nicht gefunden.');
        }
        $rekursiv = !empty($q['rekursiv']);
        $dateien = [];
        $sammle = function (string $dir) use (&$sammle, &$dateien, $rekursiv): void {
            foreach (scandir($dir) ?: [] as $f) {
                if ($f[0] === '.') {
                    continue;
                }
                if (is_dir("$dir/$f")) {
                    if ($rekursiv && !$this->plugin()->ausgeblendet($f)) {
                        $sammle("$dir/$f");
                    }
                } elseif ($this->plugin()->istSichtbar($f, $this->q)) {
                    $dateien[] = "$dir/$f";
                }
            }
        };
        $sammle($abs);
        $out = [];
        $mitVerweisen = !empty($q['verweise']);
        foreach ($dateien as $f) {
            $out[] = $this->eintrag($f, $mitVerweisen);
        }

        return ApiResponse::create($out);
    }

    private function eintrag(string $f, bool $mitVerweisen = false): array
    {
        $typ = MediaorganizerPlugin::typ($f);
        $info = $typ === 'bild' ? (@getimagesize($f) ?: [0, 0]) : [0, 0];
        $meta = $this->sidecar($f);
        // titel/beschreibung (Medienseite) als Ersatz fuer title/caption
        $meta['title'] = $meta['title'] ?? $meta['titel'] ?? null;
        $meta['caption'] = $meta['caption'] ?? $meta['beschreibung'] ?? $meta['description'] ?? null;
        $exif = $typ === 'bild' ? Exif::zusammenfassung(Exif::zerlegen(Exif::tiffAusDatei($f))) : (object) [];
        $rel = $this->rel($f);
        $backup = dirname($f) . '/_original/' . basename($f);
        $verweise = $mitVerweisen ? $this->plugin()->verweise()->anzahl($this->q, $rel) : null;

        return [
            'path' => $rel, 'ordner' => dirname($rel) === '.' ? '' : dirname($rel), 'file' => basename($f), 'typ' => $typ,
            'ext' => strtoupper(pathinfo($f, PATHINFO_EXTENSION)),
            'size' => filesize($f), 'mtime' => filemtime($f), 'width' => $info[0], 'height' => $info[1],
            'title' => $meta['title'] ?? null, 'alt' => $meta['alt'] ?? null, 'caption' => $meta['caption'] ?? null,
            'exif' => $exif, 'backup' => is_file($backup), 'backupSize' => is_file($backup) ? filesize($backup) : null,
            'verweise' => $verweise, 'quelle' => $this->q,
        ];
    }

    /** GET …/vorschau?pfad=…&w=…&crop=1 — URL einer verkleinerten Kopie (wird bei Bedarf erzeugt). */
    public function vorschau(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $q = $request->getQueryParams();
        $this->bild((string) ($q['pfad'] ?? ''));
        $medium = $this->plugin()->medium((string) $q['pfad'], $this->q);
        if (!$medium) {
            throw new NotFoundException('Keine Vorschau fuer diese Datei.');
        }
        $w = max(64, min(2400, (int) ($q['w'] ?? 400)));
        if (!empty($q['crop'])) {
            $medium->cropZoom($w, (int) round($w * 0.75));
        } elseif ((int) $medium->get('width') > $w || (int) $medium->get('height') > $w) {
            $medium->cropResize($w, $w);
        }

        return ApiResponse::create(['url' => $medium->format('webp')->url()]);
    }

    /** GET …/exif?pfad=… — alle EXIF-Tags + Mediathek-Texte. */
    public function exif(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $abs = $this->bild((string) ($request->getQueryParams()['pfad'] ?? ''));
        $p = Exif::zerlegen(Exif::tiffAusDatei($abs));

        return ApiResponse::create(['eintrag' => $this->eintrag($abs), 'tags' => Exif::alle($p)]);
    }

    /** GET …/datei?pfad=… */
    public function datei(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $abs = $this->dateiPfad((string) ($request->getQueryParams()['pfad'] ?? ''));
        $mime = mime_content_type($abs) ?: 'application/octet-stream';
        // SVG (oft als text/plain erkannt) fuer die Vorschau per Blob korrekt auszeichnen
        if (preg_match('/\.svg$/i', $abs)) {
            $mime = 'image/svg+xml';
        }

        return $this->stream($abs, basename($abs), $mime);
    }

    /** GET …/zip?ordner=… (ganzer Ordner) oder POST …/zip {pfade: […]} (Auswahl) */
    public function zip(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        if ($request->getMethod() === 'POST') {
            $dateien = array_map(fn ($p) => $this->dateiPfad($p), $this->pfade($request));
            $tmp = $this->plugin()->zipAus($dateien, $this->root());
            if (!$tmp) {
                throw new ValidationException('ZIP ist auf diesem Server nicht verfuegbar.');
            }
            $label = 'auswahl-' . date('Ymd-His');
        } else {
            $ordner = (string) ($request->getQueryParams()['ordner'] ?? '');
            $tmp = $this->plugin()->ordnerZip($ordner, $this->q);
            if (!$tmp) {
                throw new NotFoundException('Ordner nicht gefunden.');
            }
            $label = trim(str_replace('/', '_', trim($ordner, '/'))) ?: 'alle';
        }
        $resp = $this->stream($tmp, $this->plugin()->zipPraefix() . "-$label.zip", 'application/zip');
        register_shutdown_function(static fn () => @unlink($tmp));

        return $resp;
    }

    /* ---------------- Ändern ---------------- */

    /**
     * POST …/meta {pfade: […], felder: {artist, copyright, description, datetime, title, alt, caption}}
     * Nur mitgeschickte Felder werden geändert; '' löscht das Feld.
     */
    public function meta(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $pfade = $this->pfade($request);
        $felder = (array) ($this->getRequestBody($request)['felder'] ?? []);
        $exifFelder = array_intersect_key($felder, Exif::EDITIERBAR);
        $metaFelder = array_intersect_key($felder, array_flip(self::META_FELDER));
        if (isset($exifFelder['datetime']) && $exifFelder['datetime'] !== ''
            && !preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', (string) $exifFelder['datetime'])) {
            throw new ValidationException('Aufnahmedatum im Format JJJJ:MM:TT hh:mm:ss angeben.');
        }
        $ok = 0;
        $fehler = [];
        $namen = implode(', ', array_keys($felder));
        $j = $this->vorgang('texte', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Dateien') . ": $namen geändert");
        foreach ($pfade as $rel) {
            try {
                $abs = $this->dateiPfad($rel);
                if ($exifFelder) {
                    $j->sichern($abs);
                }
                if ($metaFelder) {
                    $j->sichern("$abs.meta.yaml");
                }
                if ($exifFelder && !preg_match(self::BILD, $abs)) {
                    throw new \RuntimeException('EXIF nur bei Bildern');
                }
                if ($exifFelder) {
                    if (!Exif::schreibbar($abs)) {
                        throw new \RuntimeException('EXIF nur bei WebP und JPEG bearbeitbar');
                    }
                    $p = Exif::zerlegen(Exif::tiffAusDatei($abs));
                    foreach ($exifFelder as $k => $v) {
                        [$ifd, $tag] = Exif::EDITIERBAR[$k];
                        Exif::setzeText($p, $ifd, $tag, mb_substr((string) $v, 0, 500));
                    }
                    if (!Exif::schreiben($abs, Exif::bauen($p))) {
                        throw new \RuntimeException('EXIF konnte nicht geschrieben werden');
                    }
                }
                if ($metaFelder) {
                    $meta = $this->sidecar($abs);
                    foreach ($metaFelder as $k => $v) {
                        $v = mb_substr(trim((string) $v), 0, 1000);
                        if ($v === '') {
                            unset($meta[$k]);
                        } else {
                            $meta[$k] = $v;
                        }
                    }
                    $this->schreibeSidecar($abs, $meta);
                }
                $ok++;
            } catch (\Throwable $e) {
                $fehler[] = "$rel: " . $e->getMessage();
            }
        }

        $id = $j->abschliessen();

        return ApiResponse::create(['geaendert' => $ok, 'fehler' => $fehler, 'vorgang' => $id]);
    }

    /**
     * POST …/optimieren {pfade: […], max: 3000, qualitaet: 85}
     * Verkleinert auf max px (längste Kante) und komprimiert neu. Original -> _original/ (falls noch nicht da).
     * EXIF bleibt erhalten. Ohne Ersparnis bleibt die Datei unverändert.
     */
    public function optimieren(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $pfade = $this->pfade($request);
        $body = $this->getRequestBody($request);
        $max = max(800, min(8000, (int) ($body['max'] ?? 3000)));
        $q = max(50, min(100, (int) ($body['qualitaet'] ?? 85)));
        @set_time_limit(300);
        $ergebnis = [];
        $j = $this->vorgang('optimieren', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Bilder') . " optimiert (max. $max px, Qualität $q)");
        foreach ($pfade as $rel) {
            try {
                $ergebnis[] = ['path' => $rel] + $this->optimiereEines($this->bild($rel), $max, $q, $j);
            } catch (\Throwable $e) {
                $ergebnis[] = ['path' => $rel, 'status' => 'fehler', 'meldung' => $e->getMessage()];
            }
        }
        $id = $j->abschliessen();
        $this->cacheUngueltig();

        return ApiResponse::create(array_map(fn ($e) => $e + ['vorgang' => $id], $ergebnis));
    }

    private function optimiereEines(string $abs, int $max, int $q, Journal $j): array
    {
        $info = @getimagesize($abs);
        if (!$info) {
            throw new \RuntimeException('kein lesbares Bild');
        }
        [$w, $h] = $info;
        $limit = $this->bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $w * $h * 10 > $limit - memory_get_usage()) {
            throw new \RuntimeException('zu gross für den Arbeitsspeicher des Servers');
        }
        $img = match ($info[2]) {
            IMAGETYPE_WEBP => imagecreatefromwebp($abs),
            IMAGETYPE_JPEG => imagecreatefromjpeg($abs),
            IMAGETYPE_PNG => imagecreatefrompng($abs),
            default => false,
        };
        if (!$img) {
            throw new \RuntimeException('Format nicht unterstützt');
        }
        $f = min(1, $max / max($w, $h));
        if ($f < 1) {
            $img = imagescale($img, (int) round($w * $f), (int) round($h * $f), IMG_BICUBIC);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $tmp = $abs . '.tmp-opt';
        // Zielformat: bei aktiver Konvertierung das eingestellte, sonst das bisherige
        $quelle = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $format = $this->plugin()->konvertierungAktiv() ? $this->plugin()->konverter()->ziel()
            : ($quelle === 'jpeg' ? 'jpg' : $quelle);
        if ($format === 'gif' || !Konverter::verfuegbar($format === 'png' ? 'webp' : $format) && $format !== 'png') {
            $format = 'webp';
        }
        $ziel = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $format, $abs);
        $ok = match ($format) {
            'jpg' => imagejpeg($img, $tmp, $q),
            'png' => imagepng($img, $tmp, 9),
            'avif' => imageavif($img, $tmp, $q),
            default => imagewebp($img, $tmp, $q),
        };
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('Speichern fehlgeschlagen');
        }
        $alt = filesize($abs);
        $neu = filesize($tmp);
        // Ohne Verkleinern nur speichern, wenn es sich lohnt (jedes Neukomprimieren kostet etwas Qualität)
        if ($f >= 1 && $neu >= $alt * 0.9) {
            @unlink($tmp);

            return ['status' => 'unveraendert', 'vorher' => $alt, 'nachher' => $alt, 'meldung' => 'weniger als 10 % Ersparnis'];
        }
        // EXIF übernehmen (Pixelmasse anpassen)
        $p = Exif::zerlegen(Exif::tiffAusDatei($abs));
        if ($p['ifd0'] || $p['exif'] || $p['gps']) {
            $nw = imagesx($img);
            $nh = imagesy($img);
            $p['exif'][0xA002] = [4, 1, pack($p['le'] ? 'V' : 'N', $nw)];
            $p['exif'][0xA003] = [4, 1, pack($p['le'] ? 'V' : 'N', $nh)];
            if (Exif::schreibbar($ziel)) {
                Exif::schreiben($tmp, Exif::bauen($p));
            }
            $neu = filesize($tmp);
        }
        $j->sichern($abs);
        $j->sichern($ziel);
        // Mediathek: Original zusaetzlich unter _original (nur einmal). Seitenordner:
        // nur Verlauf (ein _original-Ordner wuerde dort als Unterseite erscheinen).
        if ($this->q === 'medien') {
            $bdir = dirname($abs) . '/_original';
            if (!is_dir($bdir)) {
                mkdir($bdir, 0755);
            }
            $backup = $bdir . '/' . basename($abs);
            if (!is_file($backup)) {
                $j->sichern($backup);
                copy($abs, $backup);
                @chmod($backup, 0644);
            }
        }
        @chmod($tmp, 0644);
        rename($tmp, $ziel);
        $verweise = 0;
        if ($ziel !== $abs) {
            if (is_file("$abs.meta.yaml")) {
                $j->sichern("$abs.meta.yaml");
                $j->sichern("$ziel.meta.yaml");
                rename("$abs.meta.yaml", "$ziel.meta.yaml");
            }
            unlink($abs);
            $verweise = $this->plugin()->verweise()->ersetze($this->q, $this->rel($abs), $this->rel($ziel), $j);
        }
        $this->kopienLoeschen($ziel);

        return ['status' => 'optimiert', 'vorher' => $alt, 'nachher' => $neu,
            'breite' => imagesx($img), 'hoehe' => imagesy($img), 'verweise' => $verweise];
    }

    /** POST …/konvertieren {pfade: […]} — ins eingestellte Zielformat umwandeln (auch wenn die automatische Konvertierung aus ist). */
    public function konvertieren(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $konverter = $this->plugin()->konverter();
        @set_time_limit(300);
        $ergebnis = [];
        $pfade = $this->pfade($request);
        $j = $this->vorgang('konvertieren', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Bilder') . ' in ' . strtoupper($konverter->ziel()) . ' umgewandelt');
        foreach ($pfade as $rel) {
            try {
                $r = $this->plugin()->konvertiere($this->bild($rel), $this->q, $j, $konverter);
                $ergebnis[] = ['path' => $rel, 'status' => $r['status'], 'meldung' => $r['meldung'], 'neu' => $this->rel($r['datei']), 'verweise' => $r['verweise']];
            } catch (\Throwable $e) {
                $ergebnis[] = ['path' => $rel, 'status' => 'fehler', 'meldung' => $e->getMessage()];
            }
        }
        $id = $j->abschliessen();
        $this->cacheUngueltig();

        return ApiResponse::create(array_map(fn ($e) => $e + ['vorgang' => $id], $ergebnis));
    }

    /** POST …/wiederherstellen {pfade: […]} — Original aus _original/ zurückholen. */
    public function wiederherstellen(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $ergebnis = [];
        $pfade = $this->pfade($request);
        $j = $this->vorgang('wiederherstellen', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Bilder') . ': Original aus _original zurückgeholt');
        foreach ($pfade as $rel) {
            try {
                $abs = $this->bild($rel);
                $backup = dirname($abs) . '/_original/' . basename($abs);
                $j->sichern($abs);
                $j->sichern($backup);
                if (!is_file($backup)) {
                    throw new \RuntimeException('kein Original gesichert');
                }
                $info = @getimagesize($backup);
                if ($info && $info[0] * $info[1] > self::MAX_PIXEL) {
                    throw new \RuntimeException('Original über 25 MP, kann nicht angezeigt werden (bleibt in _original)');
                }
                rename($backup, $abs);
                $this->kopienLoeschen($abs);
                $ergebnis[] = ['path' => $rel, 'status' => 'wiederhergestellt'];
            } catch (\Throwable $e) {
                $ergebnis[] = ['path' => $rel, 'status' => 'fehler', 'meldung' => $e->getMessage()];
            }
        }
        $id = $j->abschliessen();
        $this->cacheUngueltig();

        return ApiResponse::create(array_map(fn ($e) => $e + ['vorgang' => $id], $ergebnis));
    }

    /* ---------------- Organisieren ---------------- */

    /** GET …/verweise?pfad=… — wo wird die Datei verwendet (Seiten, Konfiguration) */
    public function verweise(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);
        $rel = (string) ($request->getQueryParams()['pfad'] ?? '');
        $this->dateiPfad($rel);

        return ApiResponse::create($this->plugin()->verweise()->finde($this->q, $rel));
    }

    /** POST …/umbenennen {pfad, name} — neuer Dateiname (gleicher Ordner), Verweise werden nachgefuehrt */
    public function umbenennen(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $body = $this->getRequestBody($request);
        $abs = $this->dateiPfad((string) ($body['pfad'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $alteEndung = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($name === '' || $name !== basename($name) || $name[0] === '.' || preg_match('~[\\\\/:*?"<>|\x00-\x1f]~', $name)) {
            throw new ValidationException('Ungültiger Dateiname.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $alteEndung) {
            throw new ValidationException('Die Endung bleibt gleich (.' . $alteEndung . '). Für ein anderes Format «Umwandeln» verwenden.');
        }
        $ziel = dirname($abs) . '/' . $name;
        if ($ziel === $abs) {
            return ApiResponse::create(['neu' => $this->rel($abs), 'verweise' => 0, 'vorgang' => null]);
        }
        if (file_exists($ziel)) {
            throw new ValidationException("«$name» gibt es in diesem Ordner schon.");
        }
        $j = $this->vorgang('umbenennen', basename($abs) . " → $name");
        foreach ([$abs, $ziel, "$abs.meta.yaml", "$ziel.meta.yaml", dirname($abs) . '/media_order.yaml'] as $f) {
            $j->sichern($f);
        }
        rename($abs, $ziel);
        if (is_file("$abs.meta.yaml")) {
            rename("$abs.meta.yaml", "$ziel.meta.yaml");
        }
        $order = dirname($abs) . '/media_order.yaml';
        if (is_file($order)) {
            $d = Yaml::parse((string) file_get_contents($order)) ?: [];
            if (isset($d['media_order']) && is_array($d['media_order'])) {
                $d['media_order'] = array_map(fn ($n) => $n === basename($abs) ? $name : $n, $d['media_order']);
                file_put_contents($order, Yaml::dump($d, 99, 2));
            }
        }
        $verweise = $this->plugin()->verweise()->ersetze($this->q, $this->rel($abs), $this->rel($ziel), $j);
        $this->kopienLoeschen($abs);
        $id = $j->abschliessen();
        $this->cacheUngueltig();

        return ApiResponse::create(['neu' => $this->rel($ziel), 'verweise' => $verweise, 'vorgang' => $id]);
    }

    /** POST …/papierkorb {pfade} — Dateien entfernen (gesichert, im Verlauf rueckgaengig zu machen) */
    public function papierkorb(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $pfade = $this->pfade($request);
        $j = $this->vorgang('papierkorb', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Dateien') . ' in den Papierkorb gelegt');
        $ok = 0;
        $fehler = [];
        foreach ($pfade as $rel) {
            try {
                $abs = $this->dateiPfad($rel);
                $j->sichern($abs);
                $j->sichern("$abs.meta.yaml");
                unlink($abs);
                @unlink("$abs.meta.yaml");
                $this->kopienLoeschen($abs);
                $ok++;
            } catch (\Throwable $e) {
                $fehler[] = "$rel: " . $e->getMessage();
            }
        }
        $id = $j->abschliessen();
        $this->cacheUngueltig();

        return ApiResponse::create(['entfernt' => $ok, 'fehler' => $fehler, 'vorgang' => $id]);
    }

    /** POST …/kopieren {pfade, ziel} — Seitenmedien in einen Mediathek-Ordner kopieren (Originale bleiben) */
    public function kopieren(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $this->requirePermission($request, 'api.media.write');
        $body = $this->getRequestBody($request);
        $zielRel = trim(str_replace('\\', '/', (string) ($body['ziel'] ?? '')), '/');
        if ($zielRel === '' || str_contains($zielRel, '..') || preg_match('/(^|\/)\./', $zielRel)) {
            throw new ValidationException('Bitte einen Zielordner in der Mediathek angeben.');
        }
        $medienRoot = $this->plugin()->root('medien');
        $zielDir = $medienRoot . '/' . $zielRel;
        $pfade = $this->pfade($request);
        $j = $this->vorgang('kopieren', (count($pfade) === 1 ? basename($pfade[0]) : count($pfade) . ' Dateien') . " in die Mediathek kopiert ($zielRel)");
        if (!is_dir($zielDir)) {
            mkdir($zielDir, 0755, true);
        }
        $ok = 0;
        $fehler = [];
        foreach ($pfade as $rel) {
            try {
                $abs = $this->dateiPfad($rel);
                $base = pathinfo($abs, PATHINFO_FILENAME);
                $ext = pathinfo($abs, PATHINFO_EXTENSION);
                $name = basename($abs);
                for ($i = 2; file_exists("$zielDir/$name"); $i++) {
                    $name = "$base-$i.$ext";
                }
                $j->sichern("$zielDir/$name");
                copy($abs, "$zielDir/$name");
                @chmod("$zielDir/$name", 0644);
                if (is_file("$abs.meta.yaml")) {
                    $j->sichern("$zielDir/$name.meta.yaml");
                    copy("$abs.meta.yaml", "$zielDir/$name.meta.yaml");
                }
                $ok++;
            } catch (\Throwable $e) {
                $fehler[] = "$rel: " . $e->getMessage();
            }
        }
        $id = $j->abschliessen();

        return ApiResponse::create(['kopiert' => $ok, 'fehler' => $fehler, 'vorgang' => $id, 'ziel' => $zielRel]);
    }

    /* ---------------- Verlauf ---------------- */

    /** GET …/verlauf — alle Vorgaenge, neueste zuerst */
    public function verlauf(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, false);

        return ApiResponse::create(Journal::liste($this->plugin()->verlaufBasis()));
    }

    /** POST …/rueckgaengig {id, erzwingen} — Vorgang rueckgaengig machen */
    public function rueckgaengig(ServerRequestInterface $request): ResponseInterface
    {
        $this->start($request, true);
        $body = $this->getRequestBody($request);
        $id = (string) ($body['id'] ?? '');
        // Vorgaenge in Seitenordnern brauchen Seiten-Schreibrechte
        foreach (Journal::liste($this->plugin()->verlaufBasis(), 100000) as $v) {
            if ($v['id'] === $id && ($v['quelle'] ?? '') === 'seiten') {
                $this->requirePermission($request, 'api.pages.write');
            }
        }
        try {
            $r = Journal::rueckgaengig($this->plugin()->verlaufBasis(), $id, $this->benutzer, !empty($body['erzwingen']));
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        }
        $this->cacheUngueltig();

        return ApiResponse::create($r);
    }

    /* ---------------- Hilfen ---------------- */

    /** Titel einer Seite aus dem Kopfbereich ihrer .md-Datei (erste gefundene Sprache) */
    private function seitentitel(string $dir): ?string
    {
        $mds = glob($dir . '/*.md') ?: [];
        usort($mds, fn ($a, $b) => (str_contains($a, '.de.md') ? 0 : 1) <=> (str_contains($b, '.de.md') ? 0 : 1));
        foreach ($mds as $md) {
            $t = (string) file_get_contents($md, false, null, 0, 4000);
            if (preg_match('/^---\s*\n(.*?)\n---/s', $t, $m)) {
                try {
                    $kopf = Yaml::parse($m[1]);
                } catch (\Throwable) {
                    $kopf = null;
                }
                if (is_array($kopf) && !empty($kopf['title']) && is_string($kopf['title'])) {
                    return $kopf['title'];
                }
            }
        }

        return null;
    }

    private function sidecar(string $abs): array
    {
        $f = "$abs.meta.yaml";

        return is_file($f) ? ((array) (Yaml::parse((string) file_get_contents($f)) ?: [])) : [];
    }

    private function schreibeSidecar(string $abs, array $meta): void
    {
        $f = "$abs.meta.yaml";
        if (!$meta) {
            @unlink($f);

            return;
        }
        file_put_contents($f, Yaml::dump($meta, 4, 2));
        @chmod($f, 0644);
    }

    /** Verkleinerte Kopien eines Bildes im Bild-Cache löschen (Grav erzeugt sie neu). */
    private function kopienLoeschen(string $abs): void
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(pathinfo($abs, PATHINFO_FILENAME)));
        $dir = (string) $this->grav['locator']->findResource('cache://images', true);
        if ($slug === '' || !is_dir($dir)) {
            return;
        }
        $re = '/^[0-9a-f]{40}-' . preg_quote($slug, '/') . '(\d+w)?\.(webp|jpe?g|png|gif)$/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && preg_match($re, $file->getFilename())) {
                @unlink($file->getPathname());
            }
        }
    }

    private function cacheUngueltig(): void
    {
        try {
            Cache::clearCache('invalidate');
        } catch (\Throwable) {
        }
    }

    private function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    private function stream(string $abs, string $name, string $mime): ResponseInterface
    {
        return new Response(200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($abs),
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $name) . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], Stream::create(fopen($abs, 'rb')));
    }
}
