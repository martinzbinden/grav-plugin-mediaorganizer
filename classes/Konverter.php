<?php

declare(strict_types=1);

namespace Grav\Plugin\Mediaorganizer;

use Symfony\Component\Yaml\Yaml;

/**
 * Bilder der Mediathek in ein Standardformat umwandeln (Standard WebP).
 *
 * - Quellformate und Zielformat (webp, jpg, avif) einstellbar; Dateien im
 *   Zielformat bleiben unveraendert.
 * - JPEG wird nach der EXIF-Ausrichtung gedreht; EXIF bleibt erhalten, wenn das
 *   Ziel WebP oder JPEG ist (Ausrichtung danach auf «normal»).
 * - PNG/WebP mit Transparenz werden nicht in JPEG umgewandelt.
 * - Bilder ueber max_pixel (Standard 25 MP, Grenze der Grav-Bildverarbeitung):
 *   Original im Zielformat unter _original/, die Arbeitsdatei verkleinert.
 * - .meta.yaml und media_order.yaml werden auf den neuen Namen umgestellt.
 *
 * Entstanden aus der WebP-Umwandlung von riedackerhof-templates.
 */
class Konverter
{
    public const ZIELE = ['webp', 'jpg', 'avif'];
    private const ENDUNG = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'avif' => 'avif'];

    private string $ziel;
    private array $quellen;
    private int $qualitaet;
    private bool $pngVerlustfrei;
    private int $maxPixel;
    /** @var callable|null */
    private $log;

    public function __construct(array $cfg, ?callable $log = null)
    {
        $ziel = strtolower((string) ($cfg['zielformat'] ?? 'webp'));
        $this->ziel = in_array($ziel, self::ZIELE, true) ? $ziel : 'webp';
        // Liste [jpg, png] oder Admin-Checkboxen {jpg: true, png: false}
        $q = (array) ($cfg['quellformate'] ?? ['jpg', 'png']);
        if ($q && array_keys($q) !== range(0, count($q) - 1)) {
            $q = array_keys(array_filter($q));
        }
        $this->quellen = array_values(array_filter(array_unique(array_map(
            fn ($x) => self::ENDUNG[strtolower((string) $x)] ?? '',
            $q
        ))));
        $this->qualitaet = max(40, min(100, (int) ($cfg['qualitaet'] ?? 90)));
        $this->pngVerlustfrei = (bool) ($cfg['png_verlustfrei'] ?? true);
        $this->maxPixel = max(1000000, (int) ($cfg['max_pixel'] ?? 25000000));
        $this->log = $log;
    }

    public static function verfuegbar(string $ziel): bool
    {
        return match ($ziel) {
            'webp' => function_exists('imagewebp'),
            'jpg' => function_exists('imagejpeg'),
            'avif' => function_exists('imageavif') && (bool) (gd_info()['AVIF Support'] ?? false),
            default => false,
        };
    }

    public function ziel(): string
    {
        return $this->ziel;
    }

    public function quellen(): array
    {
        return $this->quellen;
    }

    /** Wuerde diese Datei umgewandelt? */
    public function betrifft(string $datei): bool
    {
        $ext = self::ENDUNG[strtolower(pathinfo($datei, PATHINFO_EXTENSION))] ?? '';

        return $ext !== '' && $ext !== $this->ziel && in_array($ext, $this->quellen, true);
    }

    /** Alle betroffenen Bilder eines Ordners (nicht rekursiv) umwandeln. Liefert Anzahl. */
    public function ordner(string $dir): int
    {
        if (!is_dir($dir) || !self::verfuegbar($this->ziel)) {
            return 0;
        }
        $n = 0;
        foreach (scandir($dir) ?: [] as $file) {
            if ($file[0] !== '.' && is_file("$dir/$file") && $this->betrifft($file)) {
                try {
                    if ($this->datei("$dir/$file")['status'] === 'umgewandelt') {
                        $n++;
                    }
                } catch (\Throwable $e) {
                    $this->log('warning', "$file: " . $e->getMessage());
                }
            }
        }

        return $n;
    }

    /**
     * Eine Datei umwandeln. Ergebnis: ['status' => umgewandelt|unveraendert|fehler,
     * 'datei' => neuer absoluter Pfad, 'meldung' => …]
     */
    public function datei(string $src, ?Journal $journal = null): array
    {
        $dir = dirname($src);
        $file = basename($src);
        if (!$this->betrifft($file)) {
            return ['status' => 'unveraendert', 'datei' => $src, 'meldung' => 'schon im Zielformat oder Format nicht ausgewaehlt'];
        }
        if (!self::verfuegbar($this->ziel)) {
            return ['status' => 'fehler', 'datei' => $src, 'meldung' => strtoupper($this->ziel) . ' wird vom Server nicht unterstuetzt'];
        }
        $info = @getimagesize($src);
        if (!$info) {
            return ['status' => 'fehler', 'datei' => $src, 'meldung' => 'kein lesbares Bild'];
        }
        [$w, $h] = $info;
        // Speicher: GD braucht ca. 5 Byte pro Pixel, Drehen/Verkleinern doppelt
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $w * $h * 10 > $limit - memory_get_usage()) {
            return ['status' => 'fehler', 'datei' => $src, 'meldung' => 'zu gross fuer den Arbeitsspeicher des Servers'];
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($src),
            IMAGETYPE_PNG => imagecreatefrompng($src),
            IMAGETYPE_WEBP => imagecreatefromwebp($src),
            IMAGETYPE_GIF => imagecreatefromgif($src),
            default => false,
        };
        if (!$img) {
            return ['status' => 'fehler', 'datei' => $src, 'meldung' => 'Format nicht unterstuetzt'];
        }
        $istJpeg = $info[2] === IMAGETYPE_JPEG;
        if ($istJpeg && function_exists('exif_read_data')) {
            $o = (int) ((@exif_read_data($src))['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) {
                $img = imagerotate($img, $rot, 0);
            }
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $transparent = !$istJpeg && $this->hatTransparenz($img);
        if ($this->ziel === 'jpg' && $transparent) {
            return ['status' => 'unveraendert', 'datei' => $src, 'meldung' => 'transparentes Bild bleibt (JPEG kennt keine Transparenz)'];
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $istPng = $info[2] === IMAGETYPE_PNG;

        $base = pathinfo($file, PATHINFO_FILENAME);
        $endung = $this->ziel;
        $name = "$base.$endung";
        for ($i = 2; file_exists("$dir/$name"); $i++) {
            $name = "$base-$i.$endung";
        }
        if ($journal) {
            foreach ([$src, "$src.meta.yaml", "$dir/media_order.yaml", "$dir/$name", "$dir/$name.meta.yaml"] as $f) {
                $journal->sichern($f);
            }
        }
        $px = imagesx($img) * imagesy($img);
        if ($px > $this->maxPixel) {
            @mkdir("$dir/_original", 0755);
            if ($journal) {
                $journal->sichern("$dir/_original/$name");
            }
            $this->speichern($img, "$dir/_original/$name", $istPng);
            @chmod("$dir/_original/$name", 0644);
            $f = sqrt($this->maxPixel / $px);
            $img = imagescale($img, (int) (imagesx($img) * $f), (int) (imagesy($img) * $f), IMG_BICUBIC);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $tmp = "$dir/$name.tmp";
        if (!$this->speichern($img, $tmp, $istPng)) {
            @unlink($tmp);

            return ['status' => 'fehler', 'datei' => $src, 'meldung' => 'Speichern fehlgeschlagen'];
        }
        // EXIF aus JPEG/WebP uebernehmen, wenn das Ziel es aufnehmen kann
        if ($this->ziel !== 'avif') {
            $tiff = Exif::tiffAusDatei($src);
            if ($tiff) {
                $p = Exif::zerlegen($tiff);
                $p['ifd0'][0x0112] = [3, 1, pack($p['le'] ? 'v' : 'n', 1)]; // Bild ist bereits gedreht
                $p['exif'][0xA002] = [4, 1, pack($p['le'] ? 'V' : 'N', imagesx($img))];
                $p['exif'][0xA003] = [4, 1, pack($p['le'] ? 'V' : 'N', imagesy($img))];
                Exif::schreiben($tmp, Exif::bauen($p));
            }
        }
        rename($tmp, "$dir/$name");
        @chmod("$dir/$name", 0644);
        unlink($src);
        if (is_file("$src.meta.yaml")) {
            rename("$src.meta.yaml", "$dir/$name.meta.yaml");
        }
        $order = "$dir/media_order.yaml";
        if (is_file($order)) {
            $data = Yaml::parse((string) file_get_contents($order)) ?: [];
            if (isset($data['media_order']) && is_array($data['media_order'])) {
                $data['media_order'] = array_map(static fn ($n) => $n === $file ? $name : $n, $data['media_order']);
                file_put_contents($order, Yaml::dump($data, 99, 2));
            }
        }
        $this->log('info', "$file -> $name");

        return ['status' => 'umgewandelt', 'datei' => "$dir/$name", 'meldung' => "$file -> $name"];
    }

    private function speichern($img, string $ziel, bool $ausPng): bool
    {
        return match ($this->ziel) {
            'webp' => imagewebp($img, $ziel, ($ausPng && $this->pngVerlustfrei && defined('IMG_WEBP_LOSSLESS')) ? IMG_WEBP_LOSSLESS : $this->qualitaet),
            'jpg' => imagejpeg($img, $ziel, $this->qualitaet),
            'avif' => imageavif($img, $ziel, $this->qualitaet),
            default => false,
        };
    }

    /** Hat das Bild (truecolor) mindestens einen nicht ganz deckenden Pixel? Stichprobe. */
    private function hatTransparenz($img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $schritt = max(1, (int) floor(sqrt($w * $h / 40000)));
        for ($y = 0; $y < $h; $y += $schritt) {
            for ($x = 0; $x < $w; $x += $schritt) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function log(string $level, string $text): void
    {
        if ($this->log) {
            ($this->log)($level, 'Mediathek ' . strtoupper($this->ziel) . ': ' . $text);
        }
    }

    public static function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }
}
