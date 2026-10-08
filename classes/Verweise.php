<?php

declare(strict_types=1);

namespace Grav\Plugin\Mediaorganizer;

/**
 * Verweise auf Mediendateien finden und nachfuehren.
 *
 * Durchsucht werden Seiten (user/pages/**\/*.md, ohne .rev) und die
 * Konfiguration (user/config/**\/*.yaml). Gesucht wird je nach Ablage:
 *
 * - Mediathek (user/media/R):  media://R, user://media/R, user/media/R
 * - Seitenmedien (user/pages/D/N):
 *     im selben Seitenordner der blosse Dateiname N (Markdown, Kopfbereich wie
 *     hero.image oder media_order),
 *     ueberall user/pages/D/N und die Seitenroute /<route(D)>/N
 *
 * Dateinamen werden auch URL-kodiert gesucht (Leerzeichen als %20). Ein
 * Treffer braucht Grenzen (Anfang, Leerzeichen, Klammer, Anfuehrungszeichen,
 * Schraegstrich, = : , | ? #), damit «foto.jpg» nicht in «altfoto.jpg» passt.
 *
 * Hinweis: Twig-Vorlagen und Sammlungen (z.B. page.media|first) werden nicht
 * erkannt - «kein Verweis» heisst deshalb nur «kein direkter Verweis».
 */
class Verweise
{
    private string $user;
    /** @var array<string,string>|null */
    private ?array $texte = null;

    public function __construct(string $userDir)
    {
        $this->user = rtrim($userDir, '/');
    }

    /** Alle durchsuchten Dateien mit Inhalt (rel zu user/) */
    private function texte(): array
    {
        if ($this->texte !== null) {
            return $this->texte;
        }
        $this->texte = [];
        foreach (['pages' => '/\.md$/i', 'config' => '/\.ya?ml$/i'] as $sub => $muster) {
            $dir = $this->user . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match($muster, $f->getFilename()) && $f->getSize() < 2000000) {
                    $this->texte["$sub/" . substr($f->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($f->getPathname());
                }
            }
        }

        return $this->texte;
    }

    /** Route eines Seitenordners: Ziffern-Praefixe weg (02.growth/01.years -> /growth/years) */
    public static function route(string $dirRel): string
    {
        $teile = array_filter(explode('/', trim($dirRel, '/')), 'strlen');

        return '/' . implode('/', array_map(fn ($t) => preg_replace('/^\d+\./', '', $t), $teile));
    }

    /**
     * Suchmuster fuer eine Datei: [[datei-filter (null = alle), nadel], …]
     * $quelle 'medien' (rel zu user/media) oder 'seiten' (rel zu user/pages).
     */
    private function nadeln(string $quelle, string $rel): array
    {
        $rel = trim($rel, '/');
        // immer beide Varianten (auch wenn gleich), damit alt/neu paarweise passen
        $varianten = fn (string $s) => [$s, str_replace('%2F', '/', rawurlencode($s))];
        $n = [];
        if ($quelle === 'medien') {
            foreach ($varianten($rel) as $r) {
                foreach (["media://$r", "user://media/$r", "user/media/$r"] as $x) {
                    $n[] = [null, $x];
                }
            }

            return $n;
        }
        $dir = dirname($rel) === '.' ? '' : dirname($rel);
        $name = basename($rel);
        foreach ($varianten($name) as $nm) {
            // blosser Name nur in den .md-Dateien desselben Seitenordners
            $n[] = ['pages/' . ($dir === '' ? '' : "$dir/"), $nm];
        }
        foreach ($varianten($rel) as $r) {
            $n[] = [null, "user/pages/$r"];
        }
        $route = self::route($dir);
        if ($route !== '/') {
            foreach ($varianten($name) as $nm) {
                $n[] = [null, "$route/$nm"];
            }
        }

        return $n;
    }

    private static function regex(string $nadel): string
    {
        return '~(?<=^|[\s(\["\'/=:,|>])' . preg_quote($nadel, '~') . '(?=$|[\s)\]"\'?#,|<])~m';
    }

    private static function passtFilter(?string $filter, string $datei): bool
    {
        if ($filter === null) {
            return true;
        }
        // nur .md direkt im Seitenordner (nicht in Unterseiten)
        return str_starts_with($datei, $filter) && !str_contains(substr($datei, strlen($filter)), '/') && str_ends_with($datei, '.md');
    }

    /** Fundstellen: [['datei' => rel zu user/, 'zeile' => n, 'text' => Ausschnitt], …] */
    public function finde(string $quelle, string $rel): array
    {
        $treffer = [];
        foreach ($this->nadeln($quelle, $rel) as [$filter, $nadel]) {
            foreach ($this->texte() as $datei => $inhalt) {
                if (!self::passtFilter($filter, $datei) || !str_contains($inhalt, $nadel)) {
                    continue;
                }
                if (!preg_match_all(self::regex($nadel), $inhalt, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($m[0] as [, $pos]) {
                    $zeile = substr_count($inhalt, "\n", 0, $pos) + 1;
                    $key = "$datei:$zeile";
                    if (isset($treffer[$key])) {
                        continue;
                    }
                    $start = strrpos(substr($inhalt, 0, $pos), "\n");
                    $start = $start === false ? 0 : $start + 1;
                    $ende = strpos($inhalt, "\n", $pos);
                    $text = trim(substr($inhalt, $start, ($ende === false ? strlen($inhalt) : $ende) - $start));
                    $treffer[$key] = ['datei' => $datei, 'zeile' => $zeile, 'text' => mb_substr($text, 0, 160)];
                }
            }
        }

        return array_values($treffer);
    }

    /** Anzahl Fundstellen (fuer Listen) */
    public function anzahl(string $quelle, string $rel): int
    {
        return count($this->finde($quelle, $rel));
    }

    /**
     * Verweise von $altRel auf $neuRel umstellen (gleiche Ablage). Jede geaenderte
     * Datei wird vorher im Journal gesichert. Liefert Anzahl ersetzter Stellen.
     */
    public function ersetze(string $quelle, string $altRel, string $neuRel, ?Journal $journal): int
    {
        $alt = $this->nadeln($quelle, $altRel);
        $neu = $this->nadeln($quelle, $neuRel);
        $summe = 0;
        $neueInhalte = [];
        foreach ($alt as $i => [$filter, $nadel]) {
            $ersatz = $neu[$i][1] ?? null;
            if ($ersatz === null) {
                continue;
            }
            foreach ($this->texte() as $datei => $inhalt) {
                $inhalt = $neueInhalte[$datei] ?? $inhalt;
                if (!self::passtFilter($filter, $datei) || !str_contains($inhalt, $nadel)) {
                    continue;
                }
                $n = 0;
                $ergebnis = preg_replace(self::regex($nadel), $ersatz, $inhalt, -1, $n);
                if ($n > 0 && is_string($ergebnis)) {
                    $neueInhalte[$datei] = $ergebnis;
                    $summe += $n;
                }
            }
        }
        foreach ($neueInhalte as $datei => $inhalt) {
            $abs = $this->user . '/' . $datei;
            if ($journal) {
                $journal->sichern($abs);
            }
            file_put_contents($abs, $inhalt);
            $this->texte[$datei] = $inhalt;
        }

        return $summe;
    }
}
