<?php

declare(strict_types=1);

namespace Grav\Plugin\Mediaorganizer;

/**
 * Verlauf mit Sicherung und Rueckgaengig.
 *
 * Jede Aenderung (Umwandeln, Optimieren, Umbenennen, Papierkorb, Texte/EXIF,
 * Verweise in Seiten …) laeuft als ein Vorgang: vor dem Aendern wird jede
 * betroffene Datei mit sichern() festgehalten - existiert sie, wird sie kopiert,
 * sonst als «neu» vermerkt. abschliessen() merkt sich den Zustand danach
 * (Pruefsumme). Rueckgaengig stellt den Zustand davor wieder her, aber nur,
 * wenn die Dateien seither nicht erneut geaendert wurden (sonst Konflikt,
 * ausser erzwungen). Rueckgaengig ist selbst ein Vorgang und laesst sich
 * wiederum rueckgaengig machen.
 *
 * Ablage: <verzeichnis>/<id>/vorgang.json + dateien/<n>. Pfade werden als
 * «wurzel:relativer/pfad» gespeichert (Wurzeln: user, medien - die Mediathek
 * kann per Verknuepfung ausserhalb von user/ liegen); andere Pfade sind tabu.
 */
class Journal
{
    /** @var array<string,string> Name => realer absoluter Pfad */
    private static array $wurzeln = [];

    public static function wurzeln(array $w): void
    {
        $out = [];
        foreach ($w as $name => $pfad) {
            $real = realpath((string) $pfad);
            if ($real && preg_match('/^[a-z]+$/', (string) $name)) {
                $out[$name] = rtrim($real, '/');
            }
        }
        // laengste zuerst (falls eine Wurzel in einer anderen liegt)
        uasort($out, fn ($a, $b) => strlen($b) <=> strlen($a));
        self::$wurzeln = $out;
    }

    private static function alleWurzeln(): array
    {
        return self::$wurzeln ?: ['user' => rtrim((string) realpath(GRAV_ROOT . '/user'), '/')];
    }

    private string $basis;
    private string $id;
    private array $vorgang;
    private array $index = [];
    private bool $offen = true;

    private function __construct(string $basis, array $vorgang)
    {
        $this->basis = $basis;
        $this->id = $vorgang['id'];
        $this->vorgang = $vorgang;
        foreach ($vorgang['eintraege'] as $n => $e) {
            $this->index[$e['datei']] = $n;
        }
    }

    public static function verzeichnis(string $basis): string
    {
        if (!is_dir($basis)) {
            @mkdir($basis, 0700, true);
        }
        if (is_dir($basis) && !is_file("$basis/.htaccess")) {
            @file_put_contents("$basis/.htaccess", "Require all denied\n");
        }

        return $basis;
    }

    public static function neu(string $basis, string $aktion, string $text, string $benutzer, string $quelle = ''): self
    {
        self::verzeichnis($basis);
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        return new self($basis, [
            'id' => $id, 'zeit' => time(), 'aktion' => $aktion, 'text' => $text, 'benutzer' => $benutzer,
            'quelle' => $quelle, 'status' => 'aktiv', 'eintraege' => [], 'groesse' => 0,
        ]);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** «wurzel:pfad» fuer einen absoluten Pfad, sonst null */
    private static function rel(string $abs): ?string
    {
        $dir = realpath(dirname($abs));
        if (!$dir) {
            return null;
        }
        $voll = $dir . '/' . basename($abs);
        foreach (self::alleWurzeln() as $name => $root) {
            if (str_starts_with($voll, $root . '/')) {
                return $name . ':' . substr($voll, strlen($root) + 1);
            }
        }

        return null;
    }

    private static function abs(string $rel): ?string
    {
        if (str_starts_with($rel, 'user/')) {
            $rel = 'user:' . substr($rel, 5); // Eintraege aus 1.2.0-dev
        }
        if (str_contains($rel, '..') || !preg_match('/^([a-z]+):(.+)$/', $rel, $m)) {
            return null;
        }
        $root = self::alleWurzeln()[$m[1]] ?? null;

        return $root ? $root . '/' . $m[2] : null;
    }

    /** Zustand einer Datei vor dem Aendern festhalten (mehrfach aufrufbar). */
    public function sichern(string $abs): void
    {
        if (!$this->offen) {
            throw new \RuntimeException('Vorgang bereits abgeschlossen');
        }
        $rel = self::rel($abs);
        if ($rel === null) {
            throw new \RuntimeException('Pfad ausserhalb der Ablagen: ' . $abs);
        }
        if (isset($this->index[$rel])) {
            return;
        }
        $n = count($this->vorgang['eintraege']);
        $vorher = null;
        if (is_file($abs)) {
            $ziel = $this->basis . '/' . $this->id . '/dateien/' . $n;
            @mkdir(dirname($ziel), 0700, true);
            if (!copy($abs, $ziel)) {
                throw new \RuntimeException('Sicherung fehlgeschlagen: ' . basename($abs));
            }
            $vorher = 'dateien/' . $n;
            $this->vorgang['groesse'] += filesize($abs);
        }
        $this->vorgang['eintraege'][] = ['datei' => $rel, 'vorher' => $vorher, 'vorher_md5' => $vorher ? md5_file($abs) : null, 'nachher' => null];
        $this->index[$rel] = $n;
    }

    /** Zustand danach festhalten und speichern; ohne Aenderung wird nichts gespeichert. */
    public function abschliessen(): ?string
    {
        if (!$this->offen) {
            return $this->id;
        }
        $this->offen = false;
        $geaendert = false;
        foreach ($this->vorgang['eintraege'] as &$e) {
            $abs = self::abs($e['datei']);
            $e['nachher'] = ($abs && is_file($abs)) ? md5_file($abs) : null;
            if ($e['nachher'] !== $e['vorher_md5']) {
                $geaendert = true;
            }
        }
        unset($e);
        if (!$geaendert) {
            $this->verwerfen();

            return null;
        }
        $dir = $this->basis . '/' . $this->id;
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/vorgang.json", json_encode($this->vorgang, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->id;
    }

    public function verwerfen(): void
    {
        $this->offen = false;
        self::loeschen($this->basis . '/' . $this->id);
    }

    /* ---------------- Verlauf ---------------- */

    public static function liste(string $basis, int $max = 200): array
    {
        $out = [];
        foreach (glob(rtrim($basis, '/') . '/*/vorgang.json') ?: [] as $f) {
            $v = json_decode((string) file_get_contents($f), true);
            if (!is_array($v)) {
                continue;
            }
            $v['anzahl'] = count($v['eintraege'] ?? []);
            $v['dateien'] = array_map(fn ($e) => ['datei' => $e['datei'], 'art' => $e['vorher'] === null ? 'neu' : ($e['nachher'] === null ? 'entfernt' : 'geaendert')], $v['eintraege'] ?? []);
            unset($v['eintraege']);
            $out[] = $v;
        }
        usort($out, fn ($a, $b) => $b['zeit'] <=> $a['zeit'] ?: strcmp($b['id'], $a['id']));

        return array_slice($out, 0, $max);
    }

    private static function laden(string $basis, string $id): array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
            throw new \RuntimeException('Ungueltiger Vorgang');
        }
        $f = "$basis/$id/vorgang.json";
        $v = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($v)) {
            throw new \RuntimeException('Vorgang nicht gefunden');
        }

        return $v;
    }

    /** Dateien, die seit dem Vorgang erneut geaendert wurden (Konflikte beim Rueckgaengigmachen). */
    public static function konflikte(string $basis, string $id): array
    {
        $v = self::laden($basis, $id);
        $k = [];
        foreach ($v['eintraege'] as $e) {
            $abs = self::abs($e['datei']);
            $jetzt = ($abs && is_file($abs)) ? md5_file($abs) : null;
            if ($jetzt !== $e['nachher']) {
                $k[] = $e['datei'];
            }
        }

        return $k;
    }

    /**
     * Vorgang rueckgaengig machen; legt dafuer selbst einen Vorgang an
     * (laesst sich also wiederum rueckgaengig machen). Liefert die neue Id.
     */
    public static function rueckgaengig(string $basis, string $id, string $benutzer, bool $erzwingen = false): array
    {
        $v = self::laden($basis, $id);
        if (($v['status'] ?? '') === 'rueckgaengig') {
            throw new \RuntimeException('Bereits rueckgaengig gemacht');
        }
        $konflikte = self::konflikte($basis, $id);
        if ($konflikte && !$erzwingen) {
            return ['ok' => false, 'konflikte' => $konflikte];
        }
        $j = self::neu($basis, 'rueckgaengig', 'Rückgängig: ' . $v['text'], $benutzer, $v['quelle'] ?? '');
        foreach ($v['eintraege'] as $e) {
            $abs = self::abs($e['datei']);
            if ($abs) {
                $j->sichern($abs);
            }
        }
        foreach (array_reverse($v['eintraege']) as $e) {
            $abs = self::abs($e['datei']);
            if (!$abs) {
                continue;
            }
            if ($e['vorher'] !== null) {
                @mkdir(dirname($abs), 0755, true);
                if (!copy("$basis/$id/" . $e['vorher'], $abs)) {
                    throw new \RuntimeException('Wiederherstellen fehlgeschlagen: ' . $e['datei']);
                }
                @chmod($abs, 0644);
            } elseif (is_file($abs)) {
                unlink($abs);
                self::leereOrdnerEntfernen(dirname($abs));
            }
        }
        $neu = $j->abschliessen();
        $v['status'] = 'rueckgaengig';
        $v['rueckgaengig_zeit'] = time();
        $v['rueckgaengig_durch'] = $neu;
        file_put_contents("$basis/$id/vorgang.json", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['ok' => true, 'id' => $neu, 'konflikte' => $konflikte];
    }

    /** Durch den Vorgang angelegte, nun leere Ordner entfernen (nie eine Wurzel selbst). */
    private static function leereOrdnerEntfernen(string $dir): void
    {
        $wurzeln = array_values(self::alleWurzeln());
        while (($real = realpath($dir)) && !in_array($real, $wurzeln, true)) {
            $innerhalb = false;
            foreach ($wurzeln as $w) {
                $innerhalb = $innerhalb || str_starts_with($real, $w . '/');
            }
            if (!$innerhalb || (scandir($real) ?: []) !== ['.', '..'] || !@rmdir($real)) {
                return;
            }
            $dir = dirname($real);
        }
    }

    /** Alte Vorgaenge entfernen: aelter als $tage, dann die aeltesten bis unter $maxBytes. */
    public static function aufraeumen(string $basis, int $tage, int $maxBytes): void
    {
        $alle = [];
        foreach (glob(rtrim($basis, '/') . '/*/vorgang.json') ?: [] as $f) {
            $v = json_decode((string) file_get_contents($f), true);
            $alle[] = ['dir' => dirname($f), 'zeit' => (int) ($v['zeit'] ?? filemtime($f)), 'groesse' => (int) ($v['groesse'] ?? 0)];
        }
        usort($alle, fn ($a, $b) => $a['zeit'] <=> $b['zeit']);
        $grenze = time() - $tage * 86400;
        $summe = array_sum(array_column($alle, 'groesse'));
        foreach ($alle as $a) {
            if ($a['zeit'] < $grenze || ($maxBytes > 0 && $summe > $maxBytes)) {
                self::loeschen($a['dir']);
                $summe -= $a['groesse'];
            }
        }
    }

    private static function loeschen(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
