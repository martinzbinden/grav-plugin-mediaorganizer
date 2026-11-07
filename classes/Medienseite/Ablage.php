<?php
namespace Grav\Plugin\Mediaorganizer\Medienseite;

use Grav\Common\Yaml;

/**
 * Dateiablage der Medienseite: <root>/<bereich>/<datei> plus <datei>.meta.yaml
 * (Titel, Beschreibung, Copyright). Der Ordner kann zugleich Grav's Medien-
 * ordner sein (user/media als Verknuepfung darauf): dann erscheinen die
 * Bereiche im Admin unter "Medien". Direkter Abruf per Webserver wird je
 * Bereich ueber eine verwaltete .htaccess geregelt (syncZugriff): oeffentliche
 * Bereiche frei, gesperrte nur ueber das Plugin nach der Zugriffspruefung.
 */
class Ablage
{
    public const ERLAUBT = [
        'jpg' => 'bild', 'jpeg' => 'bild', 'png' => 'bild', 'webp' => 'bild', 'tif' => 'bild', 'tiff' => 'bild',
        'svg' => 'svg', 'pdf' => 'pdf', 'eps' => 'datei', 'ai' => 'datei', 'zip' => 'datei',
        'docx' => 'datei', 'dotx' => 'datei', 'pptx' => 'datei', 'potx' => 'datei', 'xlsx' => 'datei',
    ];

    private string $root;
    private array $bereiche;
    private string $vorschauDir;

    /** @param bool $wurzelVerwalten false = .htaccess der obersten Ebene nie anfassen (Mediathek) */
    public function __construct(string $root, array $bereiche, string $vorschauDir, bool $wurzelVerwalten = true)
    {
        $this->root = rtrim($root, '/');
        $this->bereiche = $bereiche;
        $this->vorschauDir = rtrim($vorschauDir, '/');
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0755, true);
        }
        // Sichere Voreinstellung, bis syncZugriff() die Regeln setzt
        if ($wurzelVerwalten && is_dir($this->root) && !is_file($this->root . '/.htaccess')) {
            @file_put_contents($this->root . '/.htaccess', self::MARKE . "\nRequire all denied\n");
        }
    }

    public const MARKE = '# medienseite: automatisch verwaltet - Aenderungen werden ueberschrieben';

    /**
     * Webserver-Regeln setzen: Wurzel (Dateien ausserhalb der Bereiche, null =
     * nicht anfassen) und je Bereich frei oder gesperrt. Legt fehlende Bereichsordner an, damit sie
     * auch im Admin unter "Medien" erscheinen. Fremde .htaccess-Dateien (ohne
     * Marke) bleiben unangetastet.
     */
    public function syncZugriff(?bool $wurzelOeffentlich): void
    {
        if ($wurzelOeffentlich !== null) {
            $this->lesbar($this->root);
            $this->schreibeRegel($this->root, $wurzelOeffentlich);
        }
        foreach ($this->bereiche as $key => $b) {
            $dir = $this->root . '/' . $key;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $this->lesbar($dir);
            $this->schreibeRegel($dir, !empty($b['oeffentlich']));
        }
    }

    /**
     * Der Webserver muss Ordner und Dateien lesen koennen, damit er sie direkt
     * ausliefert - der Schutz gesperrter Bereiche liegt in der .htaccess.
     */
    private function lesbar(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0755);
        foreach (scandir($dir) ?: [] as $name) {
            $f = $dir . '/' . $name;
            if ($name !== '.' && $name !== '..' && is_file($f) && (fileperms($f) & 0004) === 0) {
                @chmod($f, 0644);
            }
        }
    }

    private function schreibeRegel(string $dir, bool $frei): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $f = $dir . '/.htaccess';
        $soll = self::MARKE . "\n" . ($frei ? 'Require all granted' : 'Require all denied') . "\n";
        $ist = is_file($f) ? (string) @file_get_contents($f) : null;
        // Ohne Marke nur die fruehere Voreinstellung des Plugins ersetzen
        if ($ist !== null && !str_contains($ist, self::MARKE) && trim($ist) !== 'Require all denied') {
            return;
        }
        if ($ist !== $soll) {
            @file_put_contents($f, $soll);
        }
    }

    public function istBereich(string $b): bool
    {
        return isset($this->bereiche[$b]);
    }

    /** Dateien eines Bereichs, neueste zuerst */
    public function liste(string $bereich): array
    {
        $dir = $this->bereichDir($bereich);
        if (!$dir || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            $pfad = $dir . '/' . $name;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name[0] === '.' || !is_file($pfad) || !isset(self::ERLAUBT[$ext])) {
                continue;
            }
            $meta = $this->meta($bereich, $name);
            $out[] = [
                'name' => $name,
                // titel/beschreibung (Medienseite) oder title/description (Grav-Admin)
                'titel' => $meta['titel'] ?? $meta['title'] ?? $this->titelAusName($name),
                'beschreibung' => $meta['beschreibung'] ?? $meta['description'] ?? '',
                'copyright' => $meta['copyright'] ?? '',
                'typ' => self::ERLAUBT[$ext],
                'ext' => strtoupper($ext),
                'groesse' => filesize($pfad),
                'mtime' => filemtime($pfad),
            ];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** Validierter absoluter Pfad einer Datei oder null */
    public function datei(string $bereich, string $name): ?string
    {
        $dir = $this->bereichDir($bereich);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!$dir || $name === '' || $name[0] === '.' || basename($name) !== $name || !isset(self::ERLAUBT[$ext])) {
            return null;
        }
        $pfad = realpath($dir . '/' . $name);
        $basis = realpath($dir);
        if (!$pfad || !$basis || !str_starts_with($pfad, $basis . '/') || !is_file($pfad)) {
            return null;
        }
        return $pfad;
    }

    /** Vorschau: verkleinertes WebP (Bilder, PDF falls moeglich) oder die SVG selbst; null = Symbol zeigen */
    public function vorschau(string $bereich, string $name): ?string
    {
        $datei = $this->datei($bereich, $name);
        if (!$datei) {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return $datei;
        }
        $typ = self::ERLAUBT[$ext];
        if ($typ !== 'bild' && $typ !== 'pdf') {
            return null;
        }
        $ziel = $this->vorschauDir . '/' . $bereich . '/' . sha1($name . '|' . filemtime($datei)) . '.webp';
        if (is_file($ziel)) {
            return $ziel;
        }
        @mkdir(dirname($ziel), 0750, true);
        try {
            $ok = $typ === 'pdf' || in_array($ext, ['tif', 'tiff'], true)
                ? $this->vorschauImagick($datei, $ziel, $typ === 'pdf')
                : $this->vorschauGd($datei, $ziel, $ext);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok && is_file($ziel) ? $ziel : null;
    }

    public function meta(string $bereich, string $name): array
    {
        $f = $this->metaPfad($bereich, $name);
        if (!$f || !is_file($f)) {
            return [];
        }
        try {
            $d = Yaml::parse((string) file_get_contents($f));
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($d) ? $d : [];
    }

    public function speichereMeta(string $bereich, string $name, array $felder): bool
    {
        if (!$this->datei($bereich, $name)) {
            return false;
        }
        $meta = $this->meta($bereich, $name);
        // Titel und Beschreibung zusaetzlich unter den Schluesseln, die der
        // Grav-Admin (Medien) verwendet
        $spiegel = ['titel' => 'title', 'beschreibung' => 'description'];
        foreach (['titel' => 160, 'beschreibung' => 1000, 'copyright' => 160] as $k => $max) {
            if (array_key_exists($k, $felder)) {
                $v = $this->sauber((string) $felder[$k], $max);
                foreach (array_filter([$k, $spiegel[$k] ?? null]) as $schluessel) {
                    if ($v === '') {
                        unset($meta[$schluessel]);
                    } else {
                        $meta[$schluessel] = $v;
                    }
                }
            }
        }
        return file_put_contents($this->metaPfad($bereich, $name), Yaml::dump($meta)) !== false;
    }

    /** Upload einer Datei aus $_FILES; Rueckgabe [ok, meldung, name] */
    public function hochladen(string $bereich, array $f, int $maxBytes): array
    {
        $orig = (string) ($f['name'] ?? '');
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            return [false, $orig . ': Hochladen fehlgeschlagen.', null];
        }
        if (($f['size'] ?? 0) > $maxBytes) {
            return [false, $orig . ': Datei ist zu gross.', null];
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset(self::ERLAUBT[$ext])) {
            return [false, $orig . ': Dateityp ' . strtoupper($ext) . ' ist nicht erlaubt.', null];
        }
        $dir = $this->bereichDir($bereich);
        if (!$dir) {
            return [false, 'Unbekannter Bereich.', null];
        }
        @mkdir($dir, 0755, true);
        $basis = $this->dateiname(pathinfo($orig, PATHINFO_FILENAME));
        $name = $basis . '.' . $ext;
        for ($i = 2; file_exists($dir . '/' . $name); $i++) {
            $name = $basis . '-' . $i . '.' . $ext;
        }
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            return [false, $orig . ': Speichern fehlgeschlagen.', null];
        }
        @chmod($dir . '/' . $name, 0644);
        // Anfangstitel aus dem Originalnamen (der gespeicherte Dateiname ist ohne Umlaute)
        $this->speichereMeta($bereich, $name, ['titel' => $this->titelAusName($orig)]);
        return [true, $orig . ' hochgeladen.', $name];
    }

    public function loesche(string $bereich, string $name): bool
    {
        $datei = $this->datei($bereich, $name);
        if (!$datei) {
            return false;
        }
        $meta = $this->metaPfad($bereich, $name);
        if ($meta && is_file($meta)) {
            @unlink($meta);
        }
        return @unlink($datei);
    }

    // ---------- intern ----------

    private function bereichDir(string $b): ?string
    {
        return $this->istBereich($b) && preg_match('/^[a-z0-9_-]+$/', $b) ? $this->root . '/' . $b : null;
    }

    private function metaPfad(string $bereich, string $name): ?string
    {
        $dir = $this->bereichDir($bereich);
        return $dir && basename($name) === $name ? $dir . '/' . $name . '.meta.yaml' : null;
    }

    private function titelAusName(string $name): string
    {
        $t = str_replace(['_', '-'], ' ', pathinfo($name, PATHINFO_FILENAME));
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    private function dateiname(string $s): string
    {
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c']);
        $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s);
        $s = trim(preg_replace('/-+/', '-', $s), '-._');
        return substr($s !== '' ? $s : 'datei', 0, 80);
    }

    private function sauber(string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F<>]+/u', ' ', $s)), 0, $max);
    }

    private function vorschauGd(string $datei, string $ziel, string $ext): bool
    {
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($datei),
            'png' => @imagecreatefrompng($datei),
            'webp' => @imagecreatefromwebp($datei),
            default => false,
        };
        if (!$src) {
            return false;
        }
        $w = imagesx($src); $h = imagesy($src);
        $f = min(1, 600 / max($w, $h));
        $nw = max(1, (int) round($w * $f)); $nh = max(1, (int) round($h * $f));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagewebp($dst, $ziel, 80);
        imagedestroy($src); imagedestroy($dst);
        return $ok;
    }

    private function vorschauImagick(string $datei, string $ziel, bool $pdf): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        if ($pdf && !\Imagick::queryFormats('PDF')) {
            return false;
        }
        $im = new \Imagick();
        if ($pdf) {
            $im->setResolution(72, 72);
        }
        $im->readImage($datei . ($pdf ? '[0]' : ''));
        $im->setImageBackgroundColor('white');
        $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $im->thumbnailImage(600, 600, true);
        $im->setImageFormat('webp');
        $ok = $im->writeImage($ziel);
        $im->clear();
        return $ok;
    }
}
