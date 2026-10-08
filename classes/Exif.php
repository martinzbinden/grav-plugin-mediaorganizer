<?php

declare(strict_types=1);

namespace Grav\Plugin\Mediaorganizer;

/**
 * Minimaler EXIF-Leser/-Schreiber für WebP und JPEG.
 *
 * PHPs exif_read_data() kennt kein WebP. Diese Klasse liest den TIFF-Block aus dem
 * EXIF-Chunk, zerlegt IFD0/Exif/GPS, ändert einzelne Text-Tags und schreibt den Block
 * verlustfrei zurück (die Bilddaten bleiben unverändert). Vorschaubild (IFD1) und
 * Interop-IFD werden beim Schreiben weggelassen.
 */
class Exif
{
    private const SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

    /** Tags, die der Editor setzen darf: Feld => [IFD, Tag] */
    public const EDITIERBAR = [
        'artist' => ['ifd0', 0x013B],
        'copyright' => ['ifd0', 0x8298],
        'description' => ['ifd0', 0x010E],
        'datetime' => ['exif', 0x9003],
    ];

    public const NAMEN = [
        'ifd0' => [0x0100 => 'Bildbreite', 0x0101 => 'Bildhöhe', 0x0102 => 'Bits pro Kanal', 0x0106 => 'Farbmodell', 0x0115 => 'Kanäle', 0x0213 => 'YCbCr-Lage', 0x010E => 'Beschreibung', 0x010F => 'Hersteller', 0x0110 => 'Kamera', 0x0112 => 'Ausrichtung',
            0x011A => 'X-Auflösung', 0x011B => 'Y-Auflösung', 0x0128 => 'Auflösungseinheit', 0x0131 => 'Software',
            0x0132 => 'Geändert', 0x013B => 'Autor', 0x8298 => 'Copyright'],
        'exif' => [0x829A => 'Belichtungszeit', 0x829D => 'Blende', 0x8822 => 'Belichtungsprogramm', 0x8827 => 'ISO',
            0x9000 => 'EXIF-Version', 0x9003 => 'Aufnahmedatum', 0x9004 => 'Digitalisiert', 0x9010 => 'Zeitzone',
            0x9201 => 'Verschlusszeit (APEX)', 0x9202 => 'Blende (APEX)', 0x9204 => 'Belichtungskorrektur',
            0x9207 => 'Messmethode', 0x9209 => 'Blitz', 0x920A => 'Brennweite', 0xA001 => 'Farbraum',
            0xA002 => 'Breite', 0xA003 => 'Höhe', 0xA402 => 'Belichtungsmodus', 0xA403 => 'Weissabgleich',
            0xA405 => 'Brennweite (KB)', 0xA406 => 'Szenentyp', 0xA432 => 'Objektiv-Daten', 0xA433 => 'Objektiv-Hersteller',
            0xA434 => 'Objektiv'],
        'gps' => [0x0001 => 'Breite Ref', 0x0002 => 'Breite', 0x0003 => 'Länge Ref', 0x0004 => 'Länge',
            0x0005 => 'Höhe Ref', 0x0006 => 'Höhe', 0x0007 => 'Zeit (UTC)', 0x001D => 'Datum (UTC)'],
    ];

    /* ---------------- Lesen ---------------- */

    /** TIFF-Block (ohne «Exif\0\0») aus einer WebP- oder JPEG-Datei, sonst null. */
    public static function tiffAusDatei(string $file): ?string
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return null;
        }
        $head = fread($fh, 12);
        try {
            if (strlen($head) === 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
                while (!feof($fh)) {
                    $ch = fread($fh, 8);
                    if (strlen($ch) < 8) {
                        break;
                    }
                    $size = unpack('V', substr($ch, 4, 4))[1];
                    if (substr($ch, 0, 4) === 'EXIF') {
                        return self::ohnePrefix((string) fread($fh, $size));
                    }
                    fseek($fh, $size + ($size & 1), SEEK_CUR);
                }

                return null;
            }
            if (substr($head, 0, 2) === "\xFF\xD8") {            // JPEG: APP1-Segment suchen
                fseek($fh, 2);
                while (!feof($fh)) {
                    $m = fread($fh, 4);
                    if (strlen($m) < 4 || $m[0] !== "\xFF" || $m[1] === "\xDA") {
                        break;
                    }
                    $len = unpack('n', substr($m, 2, 2))[1];
                    $data = (string) fread($fh, $len - 2);
                    if ($m[1] === "\xE1" && str_starts_with($data, "Exif\0\0")) {
                        return substr($data, 6);
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    private static function ohnePrefix(string $d): string
    {
        return str_starts_with($d, "Exif\0\0") ? substr($d, 6) : $d;
    }

    /** TIFF zerlegen: ['le' => bool, 'ifd0' => [tag => [type, count, raw]], 'exif' => …, 'gps' => …] */
    public static function zerlegen(?string $tiff): array
    {
        $r = ['le' => true, 'ifd0' => [], 'exif' => [], 'gps' => []];
        if ($tiff === null || strlen($tiff) < 8) {
            return $r;
        }
        $le = substr($tiff, 0, 2) === 'II';
        if (!$le && substr($tiff, 0, 2) !== 'MM') {
            return $r;
        }
        $r['le'] = $le;
        $u16 = fn (int $o) => unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1];
        $u32 = fn (int $o) => unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1];
        $ifd = function (int $off) use ($tiff, $u16, $u32): array {
            $out = [];
            if ($off <= 0 || $off + 2 > strlen($tiff)) {
                return $out;
            }
            $n = $u16($off);
            for ($i = 0; $i < $n && $off + 2 + 12 * ($i + 1) <= strlen($tiff); $i++) {
                $e = $off + 2 + 12 * $i;
                $tag = $u16($e);
                $type = $u16($e + 2);
                $count = $u32($e + 4);
                $len = (self::SIZES[$type] ?? 1) * $count;
                if ($len > 1 << 20) {
                    continue;
                }
                $raw = $len <= 4 ? substr($tiff, $e + 8, $len) : substr($tiff, $u32($e + 8), $len);
                if (strlen($raw) === $len) {
                    $out[$tag] = [$type, $count, $raw];
                }
            }

            return $out;
        };
        $r['ifd0'] = $ifd($u32(4));
        if (isset($r['ifd0'][0x8769])) {
            $r['exif'] = $ifd(unpack($le ? 'V' : 'N', $r['ifd0'][0x8769][2])[1]);
        }
        if (isset($r['ifd0'][0x8825])) {
            $r['gps'] = $ifd(unpack($le ? 'V' : 'N', $r['ifd0'][0x8825][2])[1]);
        }
        unset($r['ifd0'][0x8769], $r['ifd0'][0x8825], $r['exif'][0xA005]);

        return $r;
    }

    /** Wert eines Eintrags lesbar machen. */
    public static function wert(array $e, bool $le)
    {
        [$type, $count, $raw] = $e;
        $s = self::SIZES[$type] ?? 1;
        $vals = [];
        for ($i = 0; $i < $count && $i < 64; $i++) {
            $b = substr($raw, $i * $s, $s);
            $vals[] = match ($type) {
                3 => unpack($le ? 'v' : 'n', $b)[1],
                4 => unpack($le ? 'V' : 'N', $b)[1],
                8 => (unpack($le ? 'v' : 'n', $b)[1] + 32768) % 65536 - 32768,
                9 => (int) unpack('l', $le ? $b : strrev($b))[1],
                5, 10 => (function () use ($b, $le, $type) {
                    $n = unpack($le ? 'V' : 'N', substr($b, 0, 4))[1];
                    $d = unpack($le ? 'V' : 'N', substr($b, 4, 4))[1];
                    if ($type === 10) {
                        $n = $n > 0x7FFFFFFF ? $n - 0x100000000 : $n;
                        $d = $d > 0x7FFFFFFF ? $d - 0x100000000 : $d;
                    }

                    return $d ? $n / $d : 0;
                })(),
                default => null,
            };
        }
        if ($type === 2) {
            return rtrim($raw, "\0 ");
        }
        if ($type === 7 || $type === 1) {
            return ctype_print($raw) ? rtrim($raw, "\0") : strlen($raw) . ' Bytes';
        }

        return count($vals) === 1 ? $vals[0] : $vals;
    }

    /** Lesbare Liste aller Tags: [['gruppe','tag','name','wert'], …] */
    public static function alle(array $p): array
    {
        $out = [];
        foreach (['ifd0', 'exif', 'gps'] as $g) {
            foreach ($p[$g] as $tag => $e) {
                $v = self::wert($e, $p['le']);
                if (is_float($v)) {
                    $v = round($v, 6);
                }
                $out[] = ['gruppe' => $g, 'tag' => sprintf('0x%04X', $tag), 'name' => self::NAMEN[$g][$tag] ?? null,
                    'wert' => is_array($v) ? implode(', ', array_map(fn ($x) => is_float($x) ? round($x, 4) : $x, $v)) : $v];
            }
        }

        return $out;
    }

    /** Kurzfassung für Liste und Detailansicht. */
    public static function zusammenfassung(array $p): array
    {
        $g = fn (string $ifd, int $tag) => isset($p[$ifd][$tag]) ? self::wert($p[$ifd][$tag], $p['le']) : null;
        $kamera = trim(implode(' ', array_filter([$g('ifd0', 0x010F), $g('ifd0', 0x0110)])));
        $model = (string) $g('ifd0', 0x0110);
        $make = (string) $g('ifd0', 0x010F);
        if ($make !== '' && str_starts_with(strtolower($model), strtolower($make))) {
            $kamera = $model;
        }
        $exp = $g('exif', 0x829A);
        $gps = null;
        $lat = $g('gps', 0x0002);
        $lon = $g('gps', 0x0004);
        if (is_array($lat) && is_array($lon) && count($lat) === 3 && count($lon) === 3) {
            $la = $lat[0] + $lat[1] / 60 + $lat[2] / 3600;
            $lo = $lon[0] + $lon[1] / 60 + $lon[2] / 3600;
            if ($g('gps', 0x0001) === 'S') {
                $la = -$la;
            }
            if ($g('gps', 0x0003) === 'W') {
                $lo = -$lo;
            }
            $gps = [round($la, 6), round($lo, 6)];
        }

        return [
            'datetime' => $g('exif', 0x9003) ?: $g('ifd0', 0x0132),
            'artist' => $g('ifd0', 0x013B),
            'copyright' => $g('ifd0', 0x8298),
            'description' => $g('ifd0', 0x010E),
            'kamera' => $kamera ?: null,
            'objektiv' => $g('exif', 0xA434),
            'belichtung' => is_float($exp) || is_int($exp) ? ($exp > 0 && $exp < 1 ? '1/' . round(1 / $exp) : $exp) . ' s' : null,
            'blende' => ($f = $g('exif', 0x829D)) ? 'f/' . round((float) $f, 1) : null,
            'iso' => $g('exif', 0x8827),
            'brennweite' => ($b = $g('exif', 0x920A)) ? round((float) $b, 1) . ' mm' : null,
            'gps' => $gps,
        ];
    }

    /* ---------------- Schreiben ---------------- */

    /** Text-Tag setzen (null/'' = entfernen). */
    public static function setzeText(array &$p, string $ifd, int $tag, ?string $wert): void
    {
        if ($wert === null || trim($wert) === '') {
            unset($p[$ifd][$tag]);

            return;
        }
        $raw = $wert . "\0";
        $p[$ifd][$tag] = [2, strlen($raw), $raw];
    }

    /** TIFF aus zerlegter Struktur bauen (Endianness wie gelesen). */
    public static function bauen(array $p): string
    {
        $le = $p['le'];
        $p16 = fn (int $v) => pack($le ? 'v' : 'n', $v);
        $p32 = fn (int $v) => pack($le ? 'V' : 'N', $v);
        $groesse = function (array $entries): int {
            $n = 2 + 12 * count($entries) + 4;
            foreach ($entries as $e) {
                $l = strlen($e[2]);
                if ($l > 4) {
                    $n += $l + ($l & 1);
                }
            }

            return $n;
        };
        $ifd0 = $p['ifd0'];
        $exif = $p['exif'];
        $gps = $p['gps'];
        // Platzhalter für Zeiger, damit die Grösse stimmt
        if ($exif) {
            $ifd0[0x8769] = [4, 1, "\0\0\0\0"];
        }
        if ($gps) {
            $ifd0[0x8825] = [4, 1, "\0\0\0\0"];
        }
        $o0 = 8;
        $oE = $o0 + $groesse($ifd0);
        $oG = $oE + ($exif ? $groesse($exif) : 0);
        if ($exif) {
            $ifd0[0x8769] = [4, 1, $p32($oE)];
        }
        if ($gps) {
            $ifd0[0x8825] = [4, 1, $p32($oG)];
        }
        $schreibe = function (array $entries, int $off) use ($p16, $p32, $groesse): string {
            ksort($entries);
            $data = '';
            $dataOff = $off + 2 + 12 * count($entries) + 4;
            $out = $p16(count($entries));
            foreach ($entries as $tag => [$type, $count, $raw]) {
                $out .= $p16($tag) . $p16($type) . $p32($count);
                if (strlen($raw) <= 4) {
                    $out .= str_pad($raw, 4, "\0");
                } else {
                    $out .= $p32($dataOff + strlen($data));
                    $data .= $raw . (strlen($raw) & 1 ? "\0" : '');
                }
            }

            return $out . $p32(0) . $data;
        };
        $tiff = ($le ? 'II' : 'MM') . $p16(42) . $p32(8);
        $tiff .= $schreibe($ifd0, $o0);
        if ($exif) {
            $tiff .= $schreibe($exif, $oE);
        }
        if ($gps) {
            $tiff .= $schreibe($gps, $oG);
        }

        return $tiff;
    }

    /** EXIF-Block in eine WebP- oder JPEG-Datei schreiben (nach Endung bzw. Signatur). */
    public static function schreiben(string $file, string $tiff): bool
    {
        $fh = @fopen($file, 'rb');
        $kopf = $fh ? (string) fread($fh, 12) : '';
        if ($fh) {
            fclose($fh);
        }
        if (substr($kopf, 0, 4) === 'RIFF' && substr($kopf, 8, 4) === 'WEBP') {
            return self::inWebp($file, $tiff);
        }
        if (substr($kopf, 0, 2) === "\xFF\xD8") {
            return self::inJpeg($file, $tiff);
        }

        return false;
    }

    /** Kann in diese Datei geschrieben werden? (WebP oder JPEG) */
    public static function schreibbar(string $file): bool
    {
        return (bool) preg_match('/\.(webp|jpe?g)$/i', $file);
    }

    /**
     * EXIF-Block (TIFF) in eine JPEG-Datei schreiben, verlustfrei: bestehendes
     * APP1-Exif ersetzen bzw. nach SOI/APP0 einfuegen; Bilddaten bleiben gleich.
     */
    public static function inJpeg(string $file, string $tiff): bool
    {
        $d = (string) file_get_contents($file);
        if (strlen($d) < 4 || substr($d, 0, 2) !== "\xFF\xD8") {
            return false;
        }
        $nutz = "Exif\0\0" . $tiff;
        if (strlen($nutz) + 2 > 0xFFFF) {
            return false; // passt nicht in ein Segment
        }
        $app1 = "\xFF\xE1" . pack('n', strlen($nutz) + 2) . $nutz;
        $pos = 2;
        $vorne = '';          // APP0 (JFIF) bleibt vor dem Exif-Segment
        $rest = '';
        $eingefuegt = false;
        while ($pos + 4 <= strlen($d)) {
            if ($d[$pos] !== "\xFF") {
                return false;
            }
            $marker = $d[$pos + 1];
            if ($marker === "\xDA" || $marker === "\xD9") {   // Bilddaten: Rest unveraendert
                $rest .= substr($d, $pos);
                $pos = strlen($d);
                break;
            }
            $len = unpack('n', substr($d, $pos + 2, 2))[1];
            $seg = substr($d, $pos, $len + 2);
            $pos += $len + 2;
            $istExif = $marker === "\xE1" && substr($seg, 4, 6) === "Exif\0\0";
            if ($istExif) {
                continue;     // altes Exif weglassen
            }
            if ($marker === "\xE0" && !$eingefuegt && $rest === '') {
                $vorne .= $seg;
                continue;
            }
            $rest .= $seg;
        }
        if ($pos < strlen($d)) {
            $rest .= substr($d, $pos);
        }
        $tmp = $file . '.tmp-exif';
        if (file_put_contents($tmp, "\xFF\xD8" . $vorne . $app1 . $rest) === false) {
            return false;
        }
        @chmod($tmp, 0644);

        return rename($tmp, $file);
    }

    /** EXIF-Block (TIFF) in eine WebP-Datei schreiben, verlustfrei (atomar über Temp-Datei). */
    public static function inWebp(string $file, string $tiff): bool
    {
        $d = (string) file_get_contents($file);
        if (strlen($d) < 20 || substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') {
            return false;
        }
        $chunks = [];
        $pos = 12;
        while ($pos + 8 <= strlen($d)) {
            $id = substr($d, $pos, 4);
            $size = unpack('V', substr($d, $pos + 4, 4))[1];
            $chunks[] = [$id, substr($d, $pos + 8, $size)];
            $pos += 8 + $size + ($size & 1);
        }
        $chunks = array_values(array_filter($chunks, fn ($c) => $c[0] !== 'EXIF'));
        $hasX = $chunks && $chunks[0][0] === 'VP8X';
        if (!$hasX) {
            $info = @getimagesize($file);
            if (!$info) {
                return false;
            }
            $alpha = false;
            foreach ($chunks as $c) {
                if ($c[0] === 'ALPH' || ($c[0] === 'VP8L' && strlen($c[1]) >= 5 && (ord($c[1][4]) & 0x10))) {
                    $alpha = true;
                }
            }
            $w = $info[0] - 1;
            $h = $info[1] - 1;
            $vp8x = chr($alpha ? 0x10 : 0) . "\0\0\0" . substr(pack('V', $w), 0, 3) . substr(pack('V', $h), 0, 3);
            array_unshift($chunks, ['VP8X', $vp8x]);
        }
        $chunks[0][1][0] = chr(ord($chunks[0][1][0]) | 0x08);   // EXIF-Flag
        $chunks[] = ['EXIF', $tiff];
        $body = 'WEBP';
        foreach ($chunks as [$id, $data]) {
            $body .= $id . pack('V', strlen($data)) . $data . (strlen($data) & 1 ? "\0" : '');
        }
        $tmp = $file . '.tmp-exif';
        if (file_put_contents($tmp, 'RIFF' . pack('V', strlen($body)) . $body) === false) {
            return false;
        }
        @chmod($tmp, 0644);

        return rename($tmp, $file);
    }
}
