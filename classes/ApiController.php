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
 * Admin2-Schnittstellen des Media Organizers (Mediathek user://media).
 * Lesen und Herunterladen: api.media.read · Aendern (Texte, EXIF, Optimieren,
 * Wiederherstellen): api.media.write. Alle Dateitypen werden gelistet und
 * ausgeliefert; Vorschau, EXIF und Optimieren betreffen Rasterbilder.
 */
class ApiController extends AbstractApiController
{
    private const BILD = MediaorganizerPlugin::BILD;
    private const META_FELDER = ['title', 'alt', 'caption'];
    private const MAX_PIXEL = 25000000;

    private function plugin(): MediaorganizerPlugin
    {
        return MediaorganizerPlugin::instance();
    }

    private function root(): string
    {
        return $this->plugin()->root();
    }

    /** Gepruefter Pfad einer Datei (relativ zur Mediathek) -> absolut. */
    private function dateiPfad(string $rel): string
    {
        $abs = $this->plugin()->datei($rel);
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
        $this->requirePermission($request, 'api.media.read');
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
                } elseif ($this->plugin()->istSichtbar($f)) {
                    $direkt++;
                }
            }
            usort($kinder, function ($a, $b) {
                $da = (bool) preg_match('/^\d{4}/', $a['name']);
                $db = (bool) preg_match('/^\d{4}/', $b['name']);

                return $da !== $db ? ($da ? -1 : 1) : ($da ? strnatcasecmp($b['name'], $a['name']) : strnatcasecmp($a['name'], $b['name']));
            });

            return ['name' => $rel === '' ? $this->plugin()->titel() : basename($rel), 'path' => $rel, 'direkt' => $direkt,
                'total' => $direkt + array_sum(array_column($kinder, 'total')), 'kinder' => $kinder];
        };

        return ApiResponse::create($walk($root, ''));
    }

    /** GET …/dateien?ordner=…&rekursiv=1 — alle Dateien mit Eigenschaften (Bilder mit EXIF-Kurzfassung). */
    public function dateien(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');
        $q = $request->getQueryParams();
        $abs = $this->plugin()->pfad((string) ($q['ordner'] ?? ''));
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
                } elseif ($this->plugin()->istSichtbar($f)) {
                    $dateien[] = "$dir/$f";
                }
            }
        };
        $sammle($abs);
        $out = [];
        foreach ($dateien as $f) {
            $out[] = $this->eintrag($f);
        }

        return ApiResponse::create($out);
    }

    private function eintrag(string $f): array
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

        return [
            'path' => $rel, 'ordner' => dirname($rel) === '.' ? '' : dirname($rel), 'file' => basename($f), 'typ' => $typ,
            'ext' => strtoupper(pathinfo($f, PATHINFO_EXTENSION)),
            'size' => filesize($f), 'mtime' => filemtime($f), 'width' => $info[0], 'height' => $info[1],
            'title' => $meta['title'] ?? null, 'alt' => $meta['alt'] ?? null, 'caption' => $meta['caption'] ?? null,
            'exif' => $exif, 'backup' => is_file($backup), 'backupSize' => is_file($backup) ? filesize($backup) : null,
        ];
    }

    /** GET …/vorschau?pfad=…&w=…&crop=1 — URL einer verkleinerten Kopie (wird bei Bedarf erzeugt). */
    public function vorschau(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');
        $q = $request->getQueryParams();
        $this->bild((string) ($q['pfad'] ?? ''));
        $medium = $this->plugin()->medium((string) $q['pfad']);
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
        $this->requirePermission($request, 'api.media.read');
        $abs = $this->bild((string) ($request->getQueryParams()['pfad'] ?? ''));
        $p = Exif::zerlegen(Exif::tiffAusDatei($abs));

        return ApiResponse::create(['eintrag' => $this->eintrag($abs), 'tags' => Exif::alle($p)]);
    }

    /** GET …/datei?pfad=… */
    public function datei(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');
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
        $this->requirePermission($request, 'api.media.read');
        if ($request->getMethod() === 'POST') {
            $dateien = array_map(fn ($p) => $this->dateiPfad($p), $this->pfade($request));
            $tmp = $this->plugin()->zipAus($dateien, $this->root());
            if (!$tmp) {
                throw new ValidationException('ZIP ist auf diesem Server nicht verfuegbar.');
            }
            $label = 'auswahl-' . date('Ymd-His');
        } else {
            $ordner = (string) ($request->getQueryParams()['ordner'] ?? '');
            $tmp = $this->plugin()->ordnerZip($ordner);
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
        $this->requirePermission($request, 'api.media.write');
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
        foreach ($pfade as $rel) {
            try {
                $abs = $this->dateiPfad($rel);
                if ($exifFelder && !preg_match(self::BILD, $abs)) {
                    throw new \RuntimeException('EXIF nur bei Bildern');
                }
                if ($exifFelder) {
                    if (!preg_match('/\.webp$/i', $abs)) {
                        throw new \RuntimeException('EXIF nur bei WebP bearbeitbar');
                    }
                    $p = Exif::zerlegen(Exif::tiffAusDatei($abs));
                    foreach ($exifFelder as $k => $v) {
                        [$ifd, $tag] = Exif::EDITIERBAR[$k];
                        Exif::setzeText($p, $ifd, $tag, mb_substr((string) $v, 0, 500));
                    }
                    if (!Exif::inWebp($abs, Exif::bauen($p))) {
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

        return ApiResponse::create(['geaendert' => $ok, 'fehler' => $fehler]);
    }

    /**
     * POST …/optimieren {pfade: […], max: 3000, qualitaet: 85}
     * Verkleinert auf max px (längste Kante) und komprimiert neu. Original -> _original/ (falls noch nicht da).
     * EXIF bleibt erhalten. Ohne Ersparnis bleibt die Datei unverändert.
     */
    public function optimieren(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');
        $pfade = $this->pfade($request);
        $body = $this->getRequestBody($request);
        $max = max(800, min(8000, (int) ($body['max'] ?? 3000)));
        $q = max(50, min(100, (int) ($body['qualitaet'] ?? 85)));
        @set_time_limit(300);
        $ergebnis = [];
        foreach ($pfade as $rel) {
            try {
                $ergebnis[] = ['path' => $rel] + $this->optimiereEines($this->bild($rel), $max, $q);
            } catch (\Throwable $e) {
                $ergebnis[] = ['path' => $rel, 'status' => 'fehler', 'meldung' => $e->getMessage()];
            }
        }
        $this->cacheUngueltig();

        return ApiResponse::create($ergebnis);
    }

    private function optimiereEines(string $abs, int $max, int $q): array
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
        $ziel = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $abs);
        if (!imagewebp($img, $tmp, $q)) {
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
            Exif::inWebp($tmp, Exif::bauen($p));
            $neu = filesize($tmp);
        }
        // Backup des Originals (nur einmal: das erste Original bleibt)
        $bdir = dirname($abs) . '/_original';
        if (!is_dir($bdir)) {
            mkdir($bdir, 0755);
        }
        $backup = $bdir . '/' . basename($abs);
        if (!is_file($backup)) {
            copy($abs, $backup);
            @chmod($backup, 0644);
        }
        @chmod($tmp, 0644);
        rename($tmp, $ziel);
        if ($ziel !== $abs) {
            unlink($abs);
        }
        $this->kopienLoeschen($ziel);

        return ['status' => 'optimiert', 'vorher' => $alt, 'nachher' => $neu,
            'breite' => imagesx($img), 'hoehe' => imagesy($img)];
    }

    /** POST …/wiederherstellen {pfade: […]} — Original aus _original/ zurückholen. */
    public function wiederherstellen(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');
        $ergebnis = [];
        foreach ($this->pfade($request) as $rel) {
            try {
                $abs = $this->bild($rel);
                $backup = dirname($abs) . '/_original/' . basename($abs);
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
        $this->cacheUngueltig();

        return ApiResponse::create($ergebnis);
    }

    /* ---------------- Hilfen ---------------- */

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
