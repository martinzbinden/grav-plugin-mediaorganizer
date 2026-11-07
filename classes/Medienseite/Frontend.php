<?php
namespace Grav\Plugin\Mediaorganizer\Medienseite;

use Grav\Common\Grav;
use Grav\Common\Utils;

/**
 * Downloadseite (frueher Plugin "medienseite"): Downloads in Bereichen, je
 * Bereich oeffentlich oder nur fuer angemeldete Konten. Konfiguration unter
 * plugins.mediaorganizer.medienseite, aktiv nur mit medienseite.aktiv: true.
 * Routen (mit oder ohne Sprachpraefix):
 *   /mediendaten/datei/<bereich>/<datei>     Datei (?dl=1 = Download)
 *   /mediendaten/vorschau/<bereich>/<datei>  Vorschaubild
 *   /mediendaten/auth/start|verify           Anmeldung per E-Mail-Code (JSON)
 *   /mediendaten/auth/freigeben?token=…      Freigabe einer Registrierung
 *   /mediendaten/verwalten                   Hochladen/Bearbeiten/Loeschen (POST)
 * Die Seite selbst ist eine normale Grav-Seite mit template: medienseite.
 * Ablage: standardmaessig die Mediathek (user://media), die Bereiche sind dann
 * Ordner im Medienarchiv des Admin2.
 */
class Frontend
{
    private Grav $grav;
    private array $cfg;
    private $config;

    public function __construct(Grav $grav, array $cfg)
    {
        $this->grav = $grav;
        $this->cfg = $cfg;
        $this->config = $grav['config'];
    }

    // ---------- Konfiguration und Rechte ----------

    private function cfg(string $k, $d = null)
    {
        return Utils::getDotNotation($this->cfg, $k, $d);
    }

    /** Eigener Ablageordner (media_dir) statt der Mediathek? */
    private function eigeneAblage(): bool
    {
        return trim((string) $this->cfg('media_dir', '')) !== '';
    }

    /** Ablage: media_dir (relativ zum Grav-Ordner) oder, wenn leer, die Mediathek user://media */
    public function mediaRoot(): string
    {
        if ($this->eigeneAblage()) {
            return rtrim(GRAV_ROOT, '/') . '/' . trim((string) $this->cfg('media_dir'), '/');
        }
        $media = (string) $this->grav['locator']->findResource('user://media', true, true);

        return rtrim((string) (realpath($media) ?: $media), '/');
    }

    /**
     * Regel fuer die oberste Ebene der Ablage: true = frei, false = gesperrt,
     * null = nicht anfassen. Ohne Angabe bleibt die Mediathek unangetastet
     * (dort eingebundene Bilder muessen erreichbar bleiben); ein eigener
     * Ablageordner wird wie bisher gesperrt.
     */
    private function wurzelRegel(): ?bool
    {
        $w = $this->cfg('wurzel_oeffentlich');
        if ($w === null || $w === '') {
            return $this->eigeneAblage() ? false : null;
        }

        return (bool) $w;
    }

    /** Bereiche als [schluessel => [titel, oeffentlich]] - im Admin eine Liste, aeltere Konfigurationen eine Zuordnung */
    public function bereiche(): array
    {
        $out = [];
        foreach ((array) $this->cfg('bereiche', []) as $k => $b) {
            if (!is_array($b)) {
                continue;
            }
            $key = is_string($k) ? $k : (string) ($b['schluessel'] ?? '');
            if (!preg_match('/^[a-z0-9_-]+$/', $key) || empty($b['titel'])) {
                continue;
            }
            $out[$key] = ['titel' => (string) $b['titel'], 'oeffentlich' => !empty($b['oeffentlich'])];
        }
        return $out;
    }

    private function seitenname(): string
    {
        return (string) ($this->cfg('seitenname') ?: $this->config->get('site.title', ''));
    }

    private function akzent(): string
    {
        $f = (string) $this->cfg('akzentfarbe', '#2f6b62');
        return preg_match('/^#[0-9a-fA-F]{3,6}$/', $f) ? $f : '#2f6b62';
    }

    private function ablage(): Ablage
    {
        return new Ablage($this->mediaRoot(), $this->bereiche(), rtrim(GRAV_ROOT, '/') . '/cache/medienseite-vorschau', $this->wurzelRegel() !== null);
    }

    private function benutzer()
    {
        $u = $this->grav['user'] ?? null;
        return $u && $u->authenticated ? $u : null;
    }

    private function darfVerwalten(): bool
    {
        $u = $this->benutzer();
        return $u && $u->authorize((string) $this->cfg('verwalten_permission', 'admin.login'));
    }

    private function angemeldet(): bool
    {
        $u = $this->benutzer();
        return $u && ($u->authorize('site.login') || $this->darfVerwalten());
    }

    private function hatZugang(string $bereich): bool
    {
        return !empty($this->bereiche()[$bereich]['oeffentlich']) || $this->angemeldet();
    }

    private function seite(): string
    {
        return '/' . trim((string) $this->cfg('seite', '/medien'), '/');
    }

    private function zugang(): Zugang
    {
        return new Zugang($this->grav, $this->datenDir(), (array) $this->cfg('auth', []) + ['seitenname' => $this->seitenname()], $this->seite());
    }

    /**
     * Anmeldedaten (Codes, Freigaben) - bewusst NICHT im Medienordner, der
     * auch Grav's Medienordner sein kann. Fruehere Ablage <media>/_zugang
     * wird einmalig hierher verschoben.
     */
    private function datenDir(): string
    {
        $locator = $this->grav['locator'];
        $cfg = (string) $this->cfg('daten_dir', 'user://data/medienseite');
        $dir = str_contains($cfg, '://') ? (string) $locator->findResource($cfg, true, true) : rtrim(GRAV_ROOT, '/') . '/' . trim($cfg, '/');
        $alt = $this->mediaRoot() . '/_zugang';
        if (is_dir($alt) && !is_dir($dir)) {
            @mkdir(dirname($dir), 0750, true);
            @rename($alt, $dir);
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($alt)) {
            // Verschieben ging nicht (Rechte): Dateien kopieren, Rest sperren -
            // der Medienordner kann oeffentlich abrufbar sein
            foreach (glob($alt . '/*') ?: [] as $f) {
                $ziel = $dir . '/' . basename($f);
                if (is_file($f) && !is_file($ziel) && @copy($f, $ziel)) {
                    @chmod($ziel, 0600);
                    @unlink($f);
                }
            }
            @unlink($alt . '/.htaccess');
            @rmdir($alt);
            if (is_dir($alt)) {
                @file_put_contents($alt . '/.htaccess', "Require all denied\n");
            }
        }
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    /** Webserver-Regeln der Ablage nachfuehren, sobald sich Bereiche oder Einstellungen aendern */
    public function zugriffAbgleichen(): void
    {
        $root = $this->mediaRoot();
        $wurzel = $this->wurzelRegel();
        $stand = md5(json_encode([$this->bereiche(), $wurzel, is_dir($root . '/_zugang'), 4]));
        $merker = $root . '/.medienseite-stand';
        if (is_file($merker) && trim((string) @file_get_contents($merker)) === $stand) {
            return;
        }
        $this->ablage()->syncZugriff($wurzel);
        $this->datenDir();
        @file_put_contents($merker, $stand . "\n");
    }

    // ---------- Routen ----------

    public function onPagesInitialized(): void
    {
        $pfad = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if (!preg_match('#^(?:/[a-z]{2})?/mediendaten/(.+)$#', $pfad, $m)) {
            return;
        }
        $rest = $m[1];
        try {
            $this->zugang()->dailyMaintenance();
        } catch (\Throwable $e) {
        }
        if (str_starts_with($rest, 'auth/')) {
            $this->auth(substr($rest, 5));
        }
        if ($rest === 'verwalten') {
            $this->verwalten();
        }
        if (preg_match('#^(datei|vorschau)/([a-z0-9_-]+)/([^/]+)$#', $rest, $d)) {
            $this->ausliefern($d[1], $d[2], rawurldecode($d[3]));
        }
        http_response_code(404);
        exit;
    }

    private function ausliefern(string $art, string $bereich, string $name): void
    {
        $ablage = $this->ablage();
        if (!$ablage->istBereich($bereich)) {
            http_response_code(404);
            exit;
        }
        if (!$this->hatZugang($bereich)) {
            http_response_code(403);
            header('X-Robots-Tag: noindex, nofollow');
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>Anmeldung erforderlich</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1.2rem;color:#1f2d2a">'
                    . '<h1 style="color:' . $this->akzent() . '">Anmeldung erforderlich</h1>'
                    . '<p>Diese Datei steht registrierten Nutzerinnen und Nutzern der Medienseite zur Verfügung.</p>'
                    . '<p><a href="' . htmlspecialchars($this->seite()) . '" style="color:' . $this->akzent() . '">Zur Medienseite und Anmeldung</a></p>';
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Bitte zuerst anmelden.\n";
            }
            exit;
        }
        $datei = $art === 'datei' ? $ablage->datei($bereich, $name) : $ablage->vorschau($bereich, $name);
        if (!$datei || !is_file($datei)) {
            http_response_code(404);
            exit;
        }
        $ext = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
        $mime = $ext === 'svg' ? 'image/svg+xml'
            : (function_exists('mime_content_type') ? (mime_content_type($datei) ?: 'application/octet-stream') : 'application/octet-stream');
        $ansehbar = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'pdf'], true);
        $size = filesize($datei);

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        if ($ext === 'svg') {
            // Eingebettete Skripte einer SVG nie ausfuehren, auch wenn sie direkt geoeffnet wird
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        }
        if ($art === 'datei') {
            $disp = (!empty($_GET['dl']) || !$ansehbar) ? 'attachment' : 'inline';
            header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', basename($datei)) . '"');
        }
        $start = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) {
            if ($r[1] !== '') $start = (int) $r[1];
            if ($r[2] !== '') $end = min($end, (int) $r[2]);
            if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int) $r[2]); $end = $size - 1; }
            if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        while (ob_get_level()) {
            ob_end_clean();
        }
        $fh = fopen($datei, 'rb');
        fseek($fh, $start);
        $rest = $end - $start + 1;
        while ($rest > 0 && !feof($fh)) {
            $chunk = fread($fh, min(8192, $rest));
            echo $chunk;
            $rest -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }

    /** Anmeldung per E-Mail-Code (POST start|verify, JSON) und Freigabe-Link */
    private function auth(string $aktion): void
    {
        $cfg = (array) $this->cfg('auth', []);
        $json = function (array $d, int $code = 200): void {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($d, JSON_UNESCAPED_UNICODE);
            exit;
        };
        $zugang = $this->zugang();
        $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($aktion === 'freigeben') {
            $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
            $akzent = $this->akzent();
            $seite = function (string $h, string $body) use ($akzent): void {
                header('Content-Type: text/html; charset=utf-8');
                header('X-Robots-Tag: noindex');
                header('Cache-Control: no-store');
                echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
                    . '<title>' . $h . '</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:12vh auto;padding:0 1.2rem;color:#1f2d2a">'
                    . '<h1 style="color:' . $akzent . '">' . $h . '</h1>' . $body;
                exit;
            };
            $info = $zugang->approvalInfo($token);
            if (!$info) {
                http_response_code(404);
                $seite('Link ungültig', '<p>Dieser Freigabe-Link ist ungültig, abgelaufen oder wurde bereits verwendet.</p>');
            }
            if ($methode === 'POST') {
                $zugang->approve($token);
                $seite('Zugang freigeschaltet', '<p>' . htmlspecialchars((string) $info['name']) . ' (' . htmlspecialchars((string) $info['email'])
                    . ') hat jetzt Zugang zur Medienseite und wurde per E-Mail informiert.</p>');
            }
            $seite('Zugang freischalten?', '<p><strong>' . htmlspecialchars((string) $info['name']) . '</strong><br>' . htmlspecialchars((string) $info['email']) . '</p>'
                . '<p>Die E-Mail-Adresse wurde per Code bestätigt. Mit der Freischaltung erhält die Person Zugang zu allen nicht öffentlichen Bereichen der Medienseite.</p>'
                . '<form method="post"><input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
                . '<button style="padding:.8rem 1.6rem;background:' . $akzent . ';color:#fff;border:0;border-radius:99px;font:inherit;font-weight:700;cursor:pointer">Zugang freischalten</button></form>');
        }

        if ($methode !== 'POST' || !in_array($aktion, ['start', 'verify'], true)) {
            $json(['ok' => false, 'message' => 'Nicht gefunden'], 404);
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            $json(['ok' => false, 'message' => 'Nicht erlaubt'], 403);
        }
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) {
            $json(['ok' => false, 'step' => 'email', 'message' => 'Ungültige Anfrage.'], 400);
        }
        try {
            $res = $aktion === 'start' ? $zugang->start($in) : $zugang->verify($in);
        } catch (\Throwable $e) {
            $this->grav['log']->error('Medienseite-Anmeldung: ' . $e->getMessage());
            $res = ['ok' => false, 'step' => 'email', 'message' => 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.'];
        }
        $json($res);
    }

    /** Hochladen, Beschreibung bearbeiten, Loeschen - nur mit Verwaltungsrecht */
    private function verwalten(): void
    {
        $zurueck = (string) ($_POST['zurueck'] ?? '');
        if (!str_starts_with($zurueck, '/') || str_starts_with($zurueck, '//')) {
            $zurueck = $this->seite();
        }
        $weiter = function (bool $ok, array $zeilen) use ($zurueck): void {
            $this->grav['session']->medienseite_meldung = ['ok' => $ok, 'zeilen' => $zeilen];
            header('Location: ' . $zurueck, true, 303);
            exit;
        };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->darfVerwalten()) {
            http_response_code(403);
            exit;
        }
        if (!Utils::verifyNonce((string) ($_POST['nonce'] ?? ''), 'medienseite-verwalten')) {
            $weiter(false, ['Sitzung abgelaufen – bitte Seite neu laden und erneut versuchen.']);
        }
        $ablage = $this->ablage();
        $bereich = (string) ($_POST['bereich'] ?? '');
        if (!$ablage->istBereich($bereich)) {
            $weiter(false, ['Unbekannter Bereich.']);
        }
        $aktion = (string) ($_POST['aktion'] ?? '');
        if ($aktion === 'hochladen') {
            $files = $_FILES['dateien'] ?? null;
            if (!$files || !is_array($files['name'] ?? null)) {
                $weiter(false, ['Keine Datei ausgewählt.']);
            }
            $zeilen = [];
            $alleOk = true;
            $max = (int) $this->cfg('max_upload_mb', 50) * 1024 * 1024;
            foreach (array_keys($files['name']) as $i) {
                $f = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                [$ok, $msg] = $ablage->hochladen($bereich, $f, $max);
                $zeilen[] = $msg;
                $alleOk = $alleOk && $ok;
            }
            $weiter($alleOk, $zeilen);
        }
        $name = (string) ($_POST['name'] ?? '');
        if ($aktion === 'meta') {
            $ok = $ablage->speichereMeta($bereich, $name, [
                'titel' => $_POST['titel'] ?? '', 'beschreibung' => $_POST['beschreibung'] ?? '', 'copyright' => $_POST['copyright'] ?? '',
            ]);
            $weiter($ok, [$ok ? 'Angaben zu ' . $name . ' gespeichert.' : 'Speichern fehlgeschlagen.']);
        }
        if ($aktion === 'loeschen') {
            $ok = $ablage->loesche($bereich, $name);
            $weiter($ok, [$ok ? $name . ' gelöscht.' : 'Löschen fehlgeschlagen.']);
        }
        $weiter(false, ['Unbekannte Aktion.']);
    }

    // ---------- Seite ----------

    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'] ?? null;
        if (!$page || $page->template() !== 'medienseite') {
            return;
        }
        $ablage = $this->ablage();
        $verwalten = $this->darfVerwalten();
        $bereiche = [];
        $gesperrt = false;
        foreach ($this->bereiche() as $key => $b) {
            $zugang = $this->hatZugang($key) || $verwalten;
            $items = $ablage->liste($key);
            $gesperrt = $gesperrt || !$zugang;
            $bereiche[$key] = [
                'titel' => $b['titel'],
                'oeffentlich' => !empty($b['oeffentlich']),
                'zugang' => $zugang,
                'anzahl' => count($items),
                'items' => $zugang ? $items : [],
            ];
        }
        $session = $this->grav['session'];
        $meldung = $session->medienseite_meldung ?? null;
        $session->medienseite_meldung = null;
        $u = $this->benutzer();
        $this->grav['twig']->twig_vars['medienseite'] = [
            'bereiche' => $bereiche,
            'gesperrt' => $gesperrt,
            'angemeldet' => (bool) $u,
            'name' => $u ? ($u->get('fullname') ?: $u->get('username')) : '',
            'verwalten' => $verwalten,
            'nonce' => $verwalten ? Utils::getNonce('medienseite-verwalten') : '',
            'meldung' => $meldung,
            'max_mb' => (int) $this->cfg('max_upload_mb', 50),
            'endpunkt' => '/mediendaten',
            'seitenname' => $this->seitenname(),
            'erlaubt' => implode(',', array_map(fn($e) => '.' . $e, array_keys(Ablage::ERLAUBT))),
            'datenschutz_seite' => (string) $this->cfg('datenschutz_seite', '/datenschutz'),
        ];
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://mediaorganizer/assets/medienseite.css');
        $assets->addJs('plugin://mediaorganizer/assets/medienseite.js', ['group' => 'bottom', 'loading' => 'defer']);
        // Inhalt haengt vom Konto ab: nicht in geteilten Caches ablegen
        header('Cache-Control: private, no-store');
    }
}
