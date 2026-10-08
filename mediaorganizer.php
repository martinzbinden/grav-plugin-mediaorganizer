<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Page\Medium\GlobalMedia;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Media Organizer: Datei-Browser fuer die Mediathek (user://media) als eigene
 * Seite im Admin2 - Ordnerbaum, Galerie und Liste, Vorschau, Herunterladen
 * (einzeln, Ordner oder Auswahl als ZIP), Texte und EXIF bearbeiten, Bilder
 * optimieren (Original in _original/) und wiederherstellen.
 *
 * Alle Dateitypen werden gelistet und lassen sich herunterladen; Vorschau,
 * Vollbild, EXIF (WebP/JPEG) und Optimieren gibt es fuer Bilder.
 *
 * Konvertierung (optional, plugins.mediaorganizer.konvertierung): Bilder, die
 * im Admin in die Mediathek hochgeladen werden oder in einem geoeffneten
 * Ordner liegen, werden ins Zielformat umgewandelt (Standard WebP), siehe
 * classes/Konverter.php.
 *
 * Rechte: lesen/herunterladen api.media.read, aendern api.media.write.
 * Schnittstellen: classes/ApiController.php (/api/v1/mediaorganizer/...).
 * Entstanden aus dem Medienarchiv des Plugins riedackerhof-templates.
 */
class MediaorganizerPlugin extends Plugin
{
    private static ?self $instance = null;

    /** Rasterbilder (Vorschau ueber Grav, EXIF, Optimieren) */
    public const BILD = '/\.(webp|jpe?g|png|gif|avif)$/i';
    /** Dateien, die nie gelistet oder ausgeliefert werden */
    private const VERBORGEN = '/(^\.|\.meta\.yaml$|^media_order\.yaml$)/i';
    /** Seitenmedien: nur diese Dateitypen (keine Seiten, Vorlagen, Skripte) */
    public const SEITEN_MEDIEN = '/\.(jpe?g|png|webp|gif|avif|svg|pdf|docx?|xlsx?|pptx?|odt|ods|odp|csv|zip|mp3|m4a|wav|ogg|mp4|webm|mov)$/i';
    public const QUELLEN = ['medien' => 'user://media', 'seiten' => 'user://pages'];

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function __construct($name, $grav, $config = null)
    {
        parent::__construct($name, $grav, $config);
        self::$instance = $this;
        // Die API speichert ihre Routen zwischen; die Controller-Klasse muss deshalb
        // auch ohne onApiRegisterRoutes ladbar sein.
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\Mediaorganizer\\';
            if (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Konvertierung: Uploads (POST /api/v1/media) und Ordneransicht (GET)
            'onRequestHandlerInit' => ['onRequestHandlerInit', 100000],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    /* ---------------- Konfiguration ---------------- */

    public function cfg(string $key, $default = null)
    {
        return $this->config->get('plugins.mediaorganizer.' . $key, $default);
    }

    public function titel(): string
    {
        return (string) ($this->cfg('titel') ?: 'Medienarchiv');
    }

    /** Ordnernamen, die im Baum und in Listen fehlen (z.B. Sicherungen) */
    public function ausgeblendet(string $name): bool
    {
        return $name === '_original' || in_array($name, (array) $this->cfg('ausblenden', []), true);
    }

    /** Praefix fuer ZIP-Dateinamen, Standard: Hostname ohne www und Punkte */
    public function zipPraefix(): string
    {
        $p = (string) $this->cfg('zip_praefix', '');
        if ($p === '') {
            $host = (string) ($this->grav['uri']->host() ?? 'medien');
            $p = preg_replace('/^www\./', '', $host);
        }

        return trim((string) preg_replace('/[^a-z0-9-]+/i', '-', $p), '-') ?: 'medien';
    }

    /* ---------------- Konvertierung ---------------- */

    public function konvertierungAktiv(): bool
    {
        return (bool) $this->cfg('konvertierung.aktiv', false);
    }

    public function konverter(): \Grav\Plugin\Mediaorganizer\Konverter
    {
        $log = $this->grav['log'] ?? null;

        return new \Grav\Plugin\Mediaorganizer\Konverter(
            (array) $this->cfg('konvertierung', []),
            $log ? static function (string $level, string $text) use ($log): void { $log->{$level}($text); } : null
        );
    }

    /** Fuer die Admin-Seite: Einstellungen der Konvertierung */
    public function konvertierungInfo(): array
    {
        $k = $this->konverter();

        return ['aktiv' => $this->konvertierungAktiv(), 'ziel' => $k->ziel(),
            'verfuegbar' => \Grav\Plugin\Mediaorganizer\Konverter::verfuegbar($k->ziel()),
            'quellen' => $k->quellen()];
    }

    /** Unterordner der Mediathek, der nach diesem Request umgewandelt wird. */
    private ?string $konvertierenNach = null;

    public function onRequestHandlerInit($event): void
    {
        if (!$this->konvertierungAktiv()) {
            return;
        }
        $path = rtrim($event->getRoute()->getRoute(), '/');
        $api = '/' . trim((string) $this->config->get('plugins.api.route', '/api'), '/') . '/' . trim((string) $this->config->get('plugins.api.version_prefix', 'v1'), '/');
        if ($path !== $api . '/media') {
            return;
        }
        // Nur Admin-Anfragen (Token im Header oder als ?token=); die Berechtigung prueft danach das API-Plugin
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            && empty($_SERVER['HTTP_X_API_TOKEN']) && empty($_GET['token'])) {
            return;
        }
        $sub = (string) ($_GET['path'] ?? '');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $this->ordnerKonvertieren($sub);          // vor der Auflistung: Admin sieht schon das Zielformat
        } elseif ($method === 'POST') {
            $this->konvertierenNach = $sub;           // nach dem Upload
            $this->enable(['onShutdown' => ['onShutdown', 0]]);
        }
    }

    public function onShutdown(): void
    {
        if ($this->konvertierenNach !== null) {
            $this->ordnerKonvertieren($this->konvertierenNach);
        }
    }

    /** Alle betroffenen Bilder eines Mediathek-Ordners umwandeln (mit Verlauf und Verweisen) */
    public function ordnerKonvertieren(string $sub): int
    {
        $dir = $this->pfad($sub);
        if (!$dir || !is_dir($dir)) {
            return 0;
        }
        $k = $this->konverter();
        $dateien = array_filter(scandir($dir) ?: [], fn ($f) => $f[0] !== '.' && is_file("$dir/$f") && $k->betrifft($f));
        if (!$dateien || !\Grav\Plugin\Mediaorganizer\Konverter::verfuegbar($k->ziel())) {
            return 0;
        }
        $user = $this->grav['user'] ?? null;
        $j = $this->neuerVorgang('automatisch', 'Automatisch umgewandelt in ' . strtoupper($k->ziel()) . ': ' . ($sub ?: 'Mediathek'), 'medien',
            ($user && $user->authenticated) ? (string) $user->username : 'Admin');
        $n = 0;
        foreach ($dateien as $f) {
            try {
                if ($this->konvertiere("$dir/$f", 'medien', $j, $k)['status'] === 'umgewandelt') {
                    $n++;
                }
            } catch (\Throwable $e) {
                $this->grav['log']->warning("Mediathek: $f: " . $e->getMessage());
            }
        }
        $j->abschliessen();

        return $n;
    }

    /** Eine Datei umwandeln und Verweise in Seiten/Konfiguration nachfuehren */
    public function konvertiere(string $abs, string $quelle, \Grav\Plugin\Mediaorganizer\Journal $j, ?\Grav\Plugin\Mediaorganizer\Konverter $k = null): array
    {
        $alt = $this->relVon($abs, $quelle);
        $r = ($k ?? $this->konverter())->datei($abs, $j);
        $r['verweise'] = 0;
        if ($r['status'] === 'umgewandelt' && $alt !== null) {
            $neu = $this->relVon($r['datei'], $quelle);
            if ($neu !== null) {
                $r['verweise'] = $this->verweise()->ersetze($quelle, $alt, $neu, $j);
            }
        }

        return $r;
    }

    /* ---------------- Verlauf und Verweise ---------------- */

    public function verlaufBasis(): string
    {
        \Grav\Plugin\Mediaorganizer\Journal::wurzeln([
            'user' => (string) $this->grav['locator']->findResource('user://', true),
            'medien' => $this->root('medien'),
        ]);
        $data = (string) $this->grav['locator']->findResource('user://data', true, true);

        return \Grav\Plugin\Mediaorganizer\Journal::verzeichnis($data . '/mediaorganizer/verlauf');
    }

    public function neuerVorgang(string $aktion, string $text, string $quelle, string $benutzer): \Grav\Plugin\Mediaorganizer\Journal
    {
        $basis = $this->verlaufBasis();
        \Grav\Plugin\Mediaorganizer\Journal::aufraeumen($basis, (int) $this->cfg('verlauf.tage', 60), (int) $this->cfg('verlauf.max_mb', 1000) * 1048576);

        return \Grav\Plugin\Mediaorganizer\Journal::neu($basis, $aktion, $text, $benutzer, $quelle);
    }

    private ?\Grav\Plugin\Mediaorganizer\Verweise $verweise = null;

    public function verweise(): \Grav\Plugin\Mediaorganizer\Verweise
    {
        return $this->verweise ??= new \Grav\Plugin\Mediaorganizer\Verweise((string) $this->grav['locator']->findResource('user://', true));
    }

    /** Pfad relativ zur Ablage (auch fuer nicht mehr existierende Dateien) */
    public function relVon(string $abs, string $quelle): ?string
    {
        $root = $this->root($quelle);
        $dir = realpath(dirname($abs));
        $voll = $dir ? $dir . '/' . basename($abs) : $abs;

        return ($root !== '' && str_starts_with($voll, $root . '/')) ? substr($voll, strlen($root) + 1) : null;
    }

    /* ---------------- Dateien ---------------- */

    public static function quelle(?string $q): string
    {
        return $q === 'seiten' ? 'seiten' : 'medien';
    }

    public function root(string $quelle = 'medien'): string
    {
        return (string) realpath((string) $this->grav['locator']->findResource(self::QUELLEN[self::quelle($quelle)], true));
    }

    /** Absoluter, gepruefter Pfad in der Ablage (null bei ungueltigem Pfad). */
    public function pfad(string $rel, string $quelle = 'medien'): ?string
    {
        $root = $this->root($quelle);
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($root === '' || str_contains($rel, '..')) {
            return null;
        }
        $abs = realpath($root . ($rel !== '' ? '/' . $rel : ''));

        return ($abs && ($abs === $root || str_starts_with($abs, $root . '/'))) ? $abs : null;
    }

    public function istSichtbar(string $name, string $quelle = 'medien'): bool
    {
        if (preg_match(self::VERBORGEN, $name)) {
            return false;
        }

        return $quelle !== 'seiten' || (bool) preg_match(self::SEITEN_MEDIEN, $name);
    }

    /** Gepruefter absoluter Pfad einer Datei (nicht verborgen), sonst null. */
    public function datei(string $rel, string $quelle = 'medien'): ?string
    {
        $abs = $this->pfad($rel, $quelle);

        return ($abs && is_file($abs) && $this->istSichtbar(basename($abs), $quelle)) ? $abs : null;
    }

    /** Typ fuer die Anzeige: bild, svg, pdf oder datei */
    public static function typ(string $name): string
    {
        if (preg_match(self::BILD, $name)) {
            return 'bild';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $ext === 'svg' ? 'svg' : ($ext === 'pdf' ? 'pdf' : 'datei');
    }

    /** Grav-Medium eines Rasterbilds (fuer verkleinerte Vorschauen), sonst null. */
    public function medium(string $rel, string $quelle = 'medien')
    {
        $rel = trim($rel, '/');
        if (!$this->datei($rel, $quelle) || !preg_match(self::BILD, $rel)) {
            return null;
        }
        $medium = GlobalMedia::getInstance()[self::QUELLEN[self::quelle($quelle)] . '/' . $rel] ?? null;

        return ($medium && $medium->get('type') === 'image') ? $medium : null;
    }

    /** ZIP eines Ordners (rekursiv, alle sichtbaren Dateien) als temporaere Datei, sonst null. */
    public function ordnerZip(string $rel, string $quelle = 'medien'): ?string
    {
        $abs = $this->pfad($rel, $quelle);
        if (!$abs || !is_dir($abs) || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $dateien = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                fn ($f) => $f->isDir() ? ($f->getFilename()[0] !== '.' && !$this->ausgeblendet($f->getFilename())) : $this->istSichtbar($f->getFilename(), $quelle)
            )
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $dateien[] = $file->getPathname();
            }
        }

        return $this->zipAus($dateien, $abs);
    }

    /** ZIP aus absoluten Pfaden; Namen relativ zu $basis. */
    public function zipAus(array $dateien, string $basis): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mo');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($dateien as $f) {
            $name = ltrim(substr($f, strlen(rtrim($basis, '/'))), '/');
            $zip->addFile($f, $name);
            if (preg_match('/\.(webp|jpe?g|png|gif|avif|zip|pdf)$/i', $name)) {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE); // schon komprimiert
            }
        }
        $zip->close();

        return $tmp;
    }

    /* ---------------- Admin2-Integration ---------------- */

    public function onApiRegisterRoutes(Event $event): void
    {
        $c = \Grav\Plugin\Mediaorganizer\ApiController::class;
        $routes = $event['routes'];
        $routes->get('/mediaorganizer/baum', [$c, 'baum']);
        $routes->get('/mediaorganizer/dateien', [$c, 'dateien']);
        $routes->get('/mediaorganizer/vorschau', [$c, 'vorschau']);
        $routes->get('/mediaorganizer/exif', [$c, 'exif']);
        $routes->get('/mediaorganizer/datei', [$c, 'datei']);
        $routes->get('/mediaorganizer/zip', [$c, 'zip']);
        $routes->post('/mediaorganizer/zip', [$c, 'zip']);
        $routes->post('/mediaorganizer/meta', [$c, 'meta']);
        $routes->post('/mediaorganizer/optimieren', [$c, 'optimieren']);
        $routes->post('/mediaorganizer/konvertieren', [$c, 'konvertieren']);
        $routes->get('/mediaorganizer/verweise', [$c, 'verweise']);
        $routes->post('/mediaorganizer/umbenennen', [$c, 'umbenennen']);
        $routes->post('/mediaorganizer/papierkorb', [$c, 'papierkorb']);
        $routes->post('/mediaorganizer/kopieren', [$c, 'kopieren']);
        $routes->get('/mediaorganizer/verlauf', [$c, 'verlauf']);
        $routes->post('/mediaorganizer/rueckgaengig', [$c, 'rueckgaengig']);
        $routes->post('/mediaorganizer/wiederherstellen', [$c, 'wiederherstellen']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => 'mediaorganizer',
            'plugin' => 'mediaorganizer',
            'label' => $this->titel(),
            'icon' => (string) $this->cfg('icon', 'fa-images'),
            'route' => '/plugin/mediaorganizer',
            'priority' => (int) $this->cfg('prioritaet', 5),
            'authorize' => 'api.media.read',
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'mediaorganizer') {
            return;
        }
        $event['definition'] = [
            'id' => 'mediaorganizer',
            'plugin' => 'mediaorganizer',
            'title' => $this->titel(),
            'icon' => (string) $this->cfg('icon', 'fa-images'),
            'page_type' => 'component',
        ];
    }
}
