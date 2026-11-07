<?php
namespace Grav\Plugin\Mediaorganizer\Medienseite;

use Grav\Common\Grav;
use Grav\Common\Uri;

/**
 * Anmeldung per E-Mail-Code und Selbstregistrierung für die Medienseite
 * (übernommen aus dem Heftarchiv-Plugin von schwand-baeregg.ch).
 *
 * - Bekannte E-Mail-Adresse: 6-stelliger Code per E-Mail -> Anmeldung.
 * - Unbekannte Adresse: Angaben + ausdrückliche Datenschutz-Einwilligung -> Code -> Konto wird angelegt.
 *   Modus «approval» (Standard): Konto bleibt gesperrt, bis der Vorstand es per Klick freigibt.
 *   Modus «open»: Konto ist nach der Code-Bestätigung sofort aktiv.
 * - Konten mit Admin-/API-Rechten sind von der Code-Anmeldung ausgeschlossen (Passwort-Anmeldung).
 * Zustand (Codes, Freigabe-Links, Limiten) liegt in einem eigenen Datenordner (daten_dir, Standard user/data/medienseite, per .htaccess gesperrt).
 */
class Zugang
{
    private const CODE_TTL = 600;        // Sekunden
    private const MAX_TRIES = 5;
    private const RESEND_DELAY = 45;     // Sekunden zwischen zwei Codes für dieselbe Adresse
    private const MAX_PER_EMAIL = 6;     // Codes pro Adresse und Stunde
    private const MAX_PER_IP = 15;       // Anfragen pro IP und Stunde
    private const APPROVE_TTL = 2592000; // 30 Tage: danach werden nicht freigeschaltete Konten gelöscht
    private const MAX_CODES_PER_DAY = 150;    // Codes insgesamt pro Tag (Schutz vor Mail-Flut, unabhängig von der IP)
    private const MAX_REG_PER_DAY = 30;       // Neue Registrierungen insgesamt pro Tag
    private const MAX_PENDING = 50;           // Maximal gleichzeitig wartende Konten

    private Grav $grav;
    private string $dir;
    private array $cfg;

    private string $seite;

    public function __construct(Grav $grav, string $datenDir, array $cfg, string $seite)
    {
        $this->grav = $grav;
        $this->dir = rtrim($datenDir, '/');
        $this->seite = $seite;
        $this->cfg = [
            'mode' => $cfg['modus'] ?? 'approval',
            'notify_to' => ($cfg['benachrichtigung'] ?? '') ?: (string) $grav['config']->get('plugins.email.to', ''),
            'registration' => $cfg['registrierung'] ?? true,
            'site' => (string) ($cfg['seitenname'] ?? ''),
        ];
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    // ---------- Öffentliche Schritte ----------

    /** Schritt 1: Adresse (und ggf. Registrierungsangaben) entgegennehmen, Code versenden */
    public function start(array $in): array
    {
        if (!empty($in['website'])) {                                  // Honigtopf
            return $this->r(true, 'code', 'Wir haben Ihnen einen Code geschickt.');
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            return $this->r(false, 'email', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        }
        if (!$this->hit('ip-' . Uri::ip(), self::MAX_PER_IP)) {
            return $this->r(false, 'email', 'Zu viele Versuche. Bitte versuchen Sie es in einer Stunde erneut.');
        }

        $user = $this->findUser($email);
        $reg = null;
        if ($user) {
            if ($this->privileged($user)) {
                return $this->r(false, 'password', 'Für dieses Konto melden Sie sich bitte mit Benutzername und Passwort an (weiter unten).');
            }
            if (($user->get('state') ?? 'enabled') !== 'enabled') {
                return $this->r(false, 'pending', $user->get('pending')
                    ? 'Ihr Konto wartet noch auf die Freigabe. Sie erhalten eine E-Mail, sobald es freigeschaltet ist.'
                    : 'Dieses Konto ist deaktiviert. Bitte melden Sie sich bei ' . $this->cfg['notify_to']);
            }
        } else {
            if (empty($this->cfg['registration'])) {
                return $this->r(false, 'email', 'Diese Adresse ist nicht bekannt. Bitte wenden Sie sich an ' . $this->cfg['notify_to']);
            }
            $prev = !empty($in['resend']) ? ($this->load($email)['reg'] ?? null) : null;   // «Neuen Code senden» nach der Registrierung
            $v = $this->clean($in['vorname'] ?? '', 60);
            $n = $this->clean($in['nachname'] ?? '', 60);
            if ($prev) {
                $reg = $prev;
            } elseif ($v === '' || $n === '') {
                return $this->r(true, 'register', 'Diese Adresse ist noch nicht registriert. Bitte ergänzen Sie kurz Ihre Angaben.') + $this->challenge();
            } elseif (empty($in['consent'])) {
                return $this->r(false, 'register', 'Bitte bestätigen Sie die Datenschutzerklärung, um sich zu registrieren.') + $this->challenge();
            } elseif (!$this->checkChallenge((string) ($in['ctoken'] ?? ''), (string) ($in['answer'] ?? ''))) {
                return $this->r(false, 'register', 'Die Rechenaufgabe wurde nicht richtig gelöst. Bitte versuchen Sie es mit der neuen Aufgabe erneut.') + $this->challenge();
            }
            $reg = $reg ?? [
                'vorname' => $v, 'nachname' => $n,
                'organisation' => $this->clean($in['organisation'] ?? '', 120),
                'telefon' => $this->clean($in['telefon'] ?? '', 40),
                'consent_at' => date('c'),
            ];
            if ($prev) $reg = $prev;
        }

        if ($reg && $this->pendingCount() >= self::MAX_PENDING) {
            return $this->r(false, 'email', 'Zurzeit sind keine weiteren Registrierungen möglich. Bitte wenden Sie sich an ' . $this->cfg['notify_to']);
        }
        if (!$this->hit('codes-global', self::MAX_CODES_PER_DAY, 86400) || ($reg && !$this->hit('reg-global', self::MAX_REG_PER_DAY, 86400))) {
            return $this->r(false, 'email', 'Zurzeit sind sehr viele Anfragen eingegangen. Bitte versuchen Sie es später erneut.');
        }

        $st = $this->load($email) ?? ['email' => $email, 'starts' => []];
        $now = time();
        $st['starts'] = array_values(array_filter($st['starts'] ?? [], fn($t) => $t > $now - 3600));
        if (!empty($st['sent_at']) && $now - $st['sent_at'] < self::RESEND_DELAY) {
            return $this->r(false, 'code', 'Der Code wurde soeben verschickt. Bitte warten Sie einen Moment, bevor Sie einen neuen anfordern.');
        }
        if (count($st['starts']) >= self::MAX_PER_EMAIL) {
            return $this->r(false, 'email', 'Für diese Adresse wurden zu viele Codes angefordert. Bitte versuchen Sie es später erneut.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $st['code'] = $this->hash($code);
        $st['expires'] = $now + self::CODE_TTL;
        $st['tries'] = 0;
        $st['sent_at'] = $now;
        $st['starts'][] = $now;
        $st['reg'] = $reg;
        $this->save($email, $st);

        $name = $user ? (string) $user->get('fullname') : $reg['vorname'] . ' ' . $reg['nachname'];
        try {
            $this->mail($email, 'Ihr Anmeldecode: ' . $code, $this->codeMail($name, $code, (bool) $reg));
        } catch (\Throwable $e) {
            $this->delete($email);
            return $this->r(false, 'email', 'Die E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.');
        }
        return $this->r(true, 'code', 'Wir haben Ihnen einen 6-stelligen Code an ' . $email . ' geschickt. Er ist 10 Minuten gültig.');
    }

    /** Schritt 2: Code prüfen; Konto anlegen (bei Registrierung) und anmelden bzw. auf Freigabe warten */
    public function verify(array $in): array
    {
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $code = preg_replace('/\D+/', '', (string) ($in['code'] ?? ''));
        $st = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->load($email) : null;
        if (!$st || empty($st['code']) || ($st['expires'] ?? 0) < time()) {
            return $this->r(false, 'email', 'Der Code ist abgelaufen. Bitte fordern Sie einen neuen an.');
        }
        if (($st['tries'] ?? 0) >= self::MAX_TRIES) {
            $this->delete($email);
            return $this->r(false, 'email', 'Zu viele Fehlversuche. Bitte fordern Sie einen neuen Code an.');
        }
        $st['tries'] = ($st['tries'] ?? 0) + 1;
        if (strlen($code) !== 6 || !hash_equals($st['code'], $this->hash($code))) {
            $this->save($email, $st);
            $left = self::MAX_TRIES - $st['tries'];
            return $this->r(false, 'code', 'Der Code stimmt nicht.' . ($left > 0 ? ' Noch ' . $left . ' Versuch' . ($left === 1 ? '' : 'e') . '.' : ''));
        }
        $reg = $st['reg'] ?? null;
        $this->delete($email);                                         // Code ist einmalig

        if ($reg) {
            return $this->register($email, $reg, (string) ($in['context'] ?? ''));
        }
        $user = $this->findUser($email);
        if (!$user || $this->privileged($user) || ($user->get('state') ?? 'enabled') !== 'enabled') {
            return $this->r(false, 'email', 'Anmeldung nicht möglich. Bitte wenden Sie sich an ' . $this->cfg['notify_to']);
        }
        return $this->login($user, (string) ($in['context'] ?? ''));
    }

    /** Freigabe-Link des Vorstands: prüft das Token (GET zeigt nur eine Bestätigungsseite) */
    public function approvalInfo(string $token): ?array
    {
        $a = $this->readJson($this->dir . '/approve-' . $this->safeToken($token) . '.json');
        if (!$a || ($a['created'] ?? 0) < time() - self::APPROVE_TTL) return null;
        return $a;
    }

    public function approve(string $token): ?array
    {
        $a = $this->approvalInfo($token);
        if (!$a) return null;
        $user = $this->grav['accounts']->load($a['username']);
        if (!$user->exists()) return null;
        $user->set('state', 'enabled');
        $user->set('pending', false);
        $user->set('approved_at', date('c'));
        $user->save();
        @unlink($this->dir . '/approve-' . $this->safeToken($token) . '.json');
        try {
            $this->mail((string) $user->get('email'), 'Ihr Zugang zur Medienseite von ' . $this->cfg['site'] . ' ist freigeschaltet', $this->welcomeMail((string) $user->get('fullname')));
        } catch (\Throwable $e) {
        }
        return $a;
    }

    // ---------- intern ----------

    private function register(string $email, array $reg, string $context): array
    {
        if ($this->findUser($email)) {                                 // Race: inzwischen vorhanden
            return $this->r(false, 'email', 'Diese Adresse ist bereits registriert. Bitte fordern Sie einen Anmeldecode an.');
        }
        $accounts = $this->grav['accounts'];
        $base = preg_replace('/[^a-z0-9]+/', '.', strtolower(strtok($email, '@')));
        $base = trim($base, '.') ?: 'medien';
        $username = substr($base, 0, 30);
        for ($i = 2; $accounts->load($username)->exists(); $i++) {
            $username = substr($base, 0, 28) . $i;
        }
        $open = ($this->cfg['mode'] ?? 'approval') === 'open';
        $user = $accounts->load($username);
        $user->update([
            'email' => $email,
            'fullname' => $reg['vorname'] . ' ' . $reg['nachname'],
            'language' => 'de',
            'state' => $open ? 'enabled' : 'disabled',
            'pending' => !$open,
            'password' => 'Ha1' . bin2hex(random_bytes(12)),           // wird nie verwendet (Anmeldung per Code)
            'access' => ['site' => ['login' => true]],
            'organisation' => $reg['organisation'],
            'telefon' => $reg['telefon'],
            'selbstregistrierung' => ['am' => date('c'), 'datenschutz_eingewilligt' => $reg['consent_at']],
        ]);
        $user->save();

        if ($open) {
            return $this->login($user, $context);
        }
        $token = bin2hex(random_bytes(20));
        file_put_contents($this->dir . '/approve-' . $token . '.json', json_encode(['username' => $username, 'created' => time(), 'name' => $user->get('fullname'), 'email' => $email]));
        $link = rtrim($this->grav['uri']->rootUrl(true), '/') . '/mediendaten/auth/freigeben?token=' . $token;
        try {
            $this->mail($this->cfg['notify_to'], 'Neue Registrierung Medienseite: ' . $user->get('fullname'), $this->approvalMail($user, $reg, $link));
        } catch (\Throwable $e) {
        }
        return $this->r(true, 'pending', 'Vielen Dank, Ihre E-Mail-Adresse ist bestätigt. Wir schalten Ihren Zugang in Kürze frei. Sie erhalten dazu eine E-Mail an ' . $email . '.');
    }

    private function login($user, string $context): array
    {
        $u = $this->grav['login']->login(['username' => $user->get('username')], ['after_registration' => true, 'remember_me' => false]);
        if (!$u || !$u->authenticated) {
            return $this->r(false, 'email', 'Die Anmeldung ist fehlgeschlagen. Bitte wenden Sie sich an ' . $this->cfg['notify_to']);
        }
        $redirect = null;
        if ($context === 'login') {
            $redirect = $this->grav['session']->redirect_after_login ?: $this->seite;
        }
        return $this->r(true, 'done', 'Willkommen, ' . ($user->get('fullname') ?: '') . '!') + ['redirect' => $redirect];
    }

    private function findUser(string $email)
    {
        $u = $this->grav['accounts']->find($email, ['email']);
        return ($u && $u->exists() && strtolower((string) $u->get('email')) === $email) ? $u : null;
    }

    /** Konten mit Admin-/API-Rechten oder Gruppen melden sich nur mit Passwort an */
    private function privileged($user): bool
    {
        $acc = (array) $user->get('access');
        $any = function ($a) { return is_array($a) && (bool) array_filter($a); };
        return $any($acc['admin'] ?? null) || $any($acc['api'] ?? null) || (bool) array_filter((array) $user->get('groups'));
    }

    private function mail(string $to, string $subject, string $body): void
    {
        $email = $this->grav['Email'];
        $msg = $email->buildMessage([
            'to' => $to, 'subject' => $subject, 'body' => $body, 'content_type' => 'text/plain',
            'cc' => [], 'bcc' => [], 'process_markdown' => false,
        ]);
        if ($email->send($msg) < 1) {
            throw new \RuntimeException('Mailversand fehlgeschlagen');
        }
    }

    private function codeMail(string $name, string $code, bool $new): string
    {
        return "Guten Tag " . ($name ?: '') . "\n\n"
            . "Ihr Code für " . ($new ? 'die Registrierung auf' : 'die Anmeldung auf') . " der Medienseite von " . $this->cfg['site'] . ":\n\n"
            . "    " . $code . "\n\n"
            . "Der Code ist 10 Minuten gültig und kann nur einmal verwendet werden. Falls Sie ihn nicht angefordert haben, können Sie diese E-Mail ignorieren.\n\n"
            . "Freundliche Grüsse\n" . $this->cfg['site'] . "\n";
    }

    private function welcomeMail(string $name): string
    {
        $url = rtrim($this->grav['uri']->rootUrl(true), '/') . $this->seite;
        return "Guten Tag " . $name . "\n\nIhr Zugang zur Medienseite von " . $this->cfg['site'] . " ist freigeschaltet.\n\n"
            . "Sie melden sich jeweils mit Ihrer E-Mail-Adresse an: Sie erhalten einen Code per E-Mail, ein Passwort ist nicht nötig.\n\n"
            . $url . "\n\nFreundliche Grüsse\n" . $this->cfg['site'] . "\n";
    }

    private function approvalMail($user, array $reg, string $link): string
    {
        return "Neue Registrierung für die Medienseite (E-Mail-Adresse per Code bestätigt):\n\n"
            . "Name: " . $user->get('fullname') . "\nE-Mail: " . $user->get('email') . "\n"
            . "Organisation/Medium: " . ($reg['organisation'] ?: '–') . "\nTelefon: " . ($reg['telefon'] ?: '–') . "\n\n"
            . "Zugang freischalten (30 Tage gültig):\n" . $link . "\n\n"
            . "Andernfalls ignorieren Sie diese E-Mail; das Konto wird nach 30 Tagen automatisch gelöscht.\n";
    }

    /** Rechenfrage (zustandslos, signiert, 30 Minuten gültig, einmal verwendbar) */
    private function challenge(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $plus = (bool) random_int(0, 1);
        if (!$plus && $b > $a) [$a, $b] = [$b, $a];
        $res = $plus ? $a + $b : $a - $b;
        $exp = time() + 1800;
        $nonce = bin2hex(random_bytes(6));
        $payload = $res . '.' . $exp . '.' . $nonce;
        return ['challenge' => ['question' => 'Was ergibt ' . $a . ($plus ? ' + ' : ' − ') . $b . '?', 'token' => $payload . '.' . $this->hash('c' . $payload)]];
    }

    private function checkChallenge(string $token, string $answer): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4 || !preg_match('/^-?\d{1,3}$/', trim($answer))) return false;
        [$res, $exp, $nonce, $sig] = $parts;
        if (!hash_equals($this->hash('c' . $res . '.' . $exp . '.' . $nonce), $sig) || (int) $exp < time()) return false;
        if ((int) trim($answer) !== (int) $res) return false;
        $used = $this->dir . '/used-' . preg_replace('/[^a-f0-9]/', '', $nonce) . '.json';
        if (is_file($used)) return false;                          // jede Aufgabe nur einmal
        file_put_contents($used, '1');
        return true;
    }

    private function r(bool $ok, string $step, string $msg): array
    {
        return ['ok' => $ok, 'step' => $step, 'message' => $msg];
    }

    private function clean($s, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) $s)), 0, $max);
    }

    private function secret(): string
    {
        $f = $this->dir . '/secret';
        if (!is_file($f)) {
            file_put_contents($f, bin2hex(random_bytes(32)));
            @chmod($f, 0600);
        }
        return (string) file_get_contents($f);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret());
    }

    private function safeToken(string $t): string
    {
        return preg_replace('/[^a-f0-9]/', '', $t);
    }

    private function path(string $email): string
    {
        return $this->dir . '/code-' . hash('sha256', $email) . '.json';
    }

    private function load(string $email): ?array
    {
        return $this->readJson($this->path($email));
    }

    private function save(string $email, array $st): void
    {
        file_put_contents($this->path($email), json_encode($st), LOCK_EX);
    }

    private function delete(string $email): void
    {
        @unlink($this->path($email));
    }

    private function readJson(string $f): ?array
    {
        if (!is_file($f)) return null;
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    /** Einfache Limite pro Schlüssel und Stunde; false = Limit erreicht */
    private function hit(string $key, int $max, int $window = 3600): bool
    {
        $f = $this->dir . '/rl-' . hash('sha256', $key) . '.json';
        $now = time();
        $l = array_values(array_filter($this->readJson($f) ?? [], fn($t) => $t > $now - $window));
        if (count($l) >= $max) return false;
        $l[] = $now;
        file_put_contents($f, json_encode($l), LOCK_EX);
        return true;
    }

    /** Konten, die auf Freigabe warten */
    private function pendingAccounts(): array
    {
        $out = [];
        $dir = rtrim(GRAV_ROOT, '/') . '/user/accounts';
        foreach (glob($dir . '/*.yaml') ?: [] as $f) {
            $d = \Grav\Common\Yaml::parse((string) file_get_contents($f));
            if (is_array($d) && !empty($d['pending']) && ($d['state'] ?? '') !== 'enabled') {
                $out[$f] = $d;
            }
        }
        return $out;
    }

    private function pendingCount(): int
    {
        return count($this->pendingAccounts());
    }

    /**
     * Täglich: abgelaufene Codes/Freigabe-Links/Limiten löschen und Konten, die nicht innert 30 Tagen
     * freigeschaltet wurden, samt Freigabe-Link entfernen.
     */
    public function dailyMaintenance(): void
    {
        $marker = $this->dir . '/last-gc';
        if (is_file($marker) && filemtime($marker) > time() - 86400) return;
        touch($marker);
        $limit = time() - self::APPROVE_TTL;
        foreach ($this->pendingAccounts() as $f => $d) {
            $since = strtotime((string) ($d['selbstregistrierung']['am'] ?? '')) ?: filemtime($f);
            if ($since < $limit) @unlink($f);
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $age = filemtime($f);
            if (str_contains(basename($f), 'approve-') ? $age < $limit : $age < time() - 86400 * 2) @unlink($f);
        }
    }
}
