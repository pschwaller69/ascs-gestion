<?php
declare(strict_types=1);

namespace Ascs\Web;

use PDO;

/**
 * Double authentification des comptes du bureau : code à 6 chiffres d'une application d'authentification
 * (Google Authenticator, Microsoft Authenticator, FreeOTP…), norme TOTP (RFC 6238 : SHA-1, 30 secondes).
 * Exigée compte par compte depuis Comptes du bureau. Codes de secours à usage unique (10, stockés hachés).
 */
final class DoubleAuth
{
    public const PERIODE = 30;
    public const CHIFFRES = 6;
    public const NB_SECOURS = 10;
    /** Étape en attente (mot de passe donné, code pas encore) : durée et nombre d'essais. */
    public const ATTENTE_MAX = 600;
    public const ESSAIS_MAX = 5;
    /** « Se souvenir de cet ordinateur » : durée et nom du cookie. */
    public const CONFIANCE_JOURS = 30;
    public const COOKIE = 'ascs_appareil';
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ TOTP

    public static function nouveauSecret(): string
    {
        $s = '';
        foreach (str_split(random_bytes(32)) as $o) {
            $s .= self::B32[ord($o) & 31];
        }
        return $s; // 32 caractères base32 = 160 bits (taille recommandée par la RFC 4226)
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $out .= chr(bindec($octet));
            }
        }
        return $out;
    }

    public static function code(string $secret, int $pas): string
    {
        $h = hash_hmac('sha1', pack('J', $pas), self::base32Decode($secret), true);
        $o = ord($h[19]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % (10 ** self::CHIFFRES)), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    /** Pas de temps accepté (décalage d'horloge de ±30 s toléré), null si le code est faux. */
    public static function verifierCode(string $secret, string $saisi, ?int $maintenant = null): ?int
    {
        $saisi = preg_replace('/\s+/', '', $saisi);
        if (!preg_match('/^\d{6}$/', $saisi)) {
            return null;
        }
        $pas = intdiv($maintenant ?? time(), self::PERIODE);
        foreach ([0, -1, 1] as $d) {
            if (hash_equals(self::code($secret, $pas + $d), $saisi)) {
                return $pas + $d;
            }
        }
        return null;
    }

    public static function lienApplication(string $secret, string $compte): string
    {
        return 'otpauth://totp/' . rawurlencode('ASCS:' . $compte) . '?secret=' . $secret . '&issuer=ASCS&digits=6&period=30';
    }

    // ------------------------------------------------------------------ état des comptes

    /** @return array{exigee:bool,active:bool,secret:?string,dernier_pas:?int,secours:list<string>,active_le:?string} */
    public function etat(int $uid): array
    {
        $st = $this->pdo->prepare('SELECT * FROM utilisateur_2fa WHERE utilisateur_id = ?');
        $st->execute([$uid]);
        $r = $st->fetch() ?: [];
        return [
            'exigee' => (bool) ($r['exigee'] ?? false), 'active' => !empty($r['secret']), 'secret' => $r['secret'] ?? null,
            'dernier_pas' => isset($r['dernier_pas']) ? (int) $r['dernier_pas'] : null,
            'secours' => json_decode((string) ($r['codes_secours'] ?? '[]'), true) ?: [], 'active_le' => $r['active_le'] ?? null,
        ];
    }

    /** @return array<int,array{exigee:bool,active:bool,secours:int}> */
    public function etats(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT utilisateur_id, exigee, secret, codes_secours FROM utilisateur_2fa') as $r) {
            $out[(int) $r['utilisateur_id']] = ['exigee' => (bool) $r['exigee'], 'active' => !empty($r['secret']),
                'secours' => count(json_decode((string) ($r['codes_secours'] ?? '[]'), true) ?: [])];
        }
        return $out;
    }

    private function ecrire(int $uid, array $champs, string $par): void
    {
        $e = $this->etat($uid);
        $ligne = [
            'utilisateur_id' => $uid, 'exigee' => (int) ($champs['exigee'] ?? $e['exigee']),
            'secret' => array_key_exists('secret', $champs) ? $champs['secret'] : $e['secret'],
            'dernier_pas' => array_key_exists('dernier_pas', $champs) ? $champs['dernier_pas'] : $e['dernier_pas'],
            'codes_secours' => json_encode(array_key_exists('secours', $champs) ? $champs['secours'] : $e['secours']),
            'active_le' => array_key_exists('active_le', $champs) ? $champs['active_le'] : $e['active_le'],
            'modifie_le' => date('Y-m-d H:i:s'), 'modifie_par' => mb_substr($par, 0, 150),
        ];
        \Ascs\Db::upsertStatement($this->pdo, 'utilisateur_2fa', array_keys($ligne), ['utilisateur_id'])->execute(array_values($ligne));
    }

    /** Exige (ou non) la double authentification pour un compte. Ne plus l'exiger efface aussi l'application enregistrée. */
    public function exiger(int $uid, bool $oui, string $par): void
    {
        $this->ecrire($uid, $oui ? ['exigee' => 1] : ['exigee' => 0, 'secret' => null, 'dernier_pas' => null, 'secours' => [], 'active_le' => null], $par);
        if (!$oui) {
            $this->oublierAppareils($uid);
        }
    }

    /** Téléphone perdu ou changé : l'application est à enregistrer de nouveau à la prochaine connexion. */
    public function reinitialiser(int $uid, string $par): void
    {
        $this->ecrire($uid, ['secret' => null, 'dernier_pas' => null, 'secours' => [], 'active_le' => null], $par);
        $this->oublierAppareils($uid);
    }

    /** Enregistre l'application (après un premier code juste) ; rend les codes de secours en clair, à montrer une seule fois. */
    public function activer(int $uid, string $secret, int $pas, string $par): array
    {
        $clairs = [];
        $haches = [];
        for ($i = 0; $i < self::NB_SECOURS; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
            }
            $clairs[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $haches[] = password_hash($c, PASSWORD_DEFAULT);
        }
        $this->ecrire($uid, ['secret' => $secret, 'dernier_pas' => $pas, 'secours' => $haches, 'active_le' => date('Y-m-d H:i:s')], $par);
        return $clairs;
    }

    /**
     * Vérifie un code de l'application (refusé s'il a déjà servi) ou un code de secours (consommé).
     * @return string|null 'appli' | 'secours' | null
     */
    public function verifier(int $uid, string $saisi): ?string
    {
        $e = $this->etat($uid);
        if (!$e['active']) {
            return null;
        }
        $pas = self::verifierCode((string) $e['secret'], $saisi);
        if ($pas !== null) {
            if ($e['dernier_pas'] !== null && $pas <= $e['dernier_pas']) {
                return null; // même code rejoué
            }
            $this->pdo->prepare('UPDATE utilisateur_2fa SET dernier_pas = ? WHERE utilisateur_id = ?')->execute([$pas, $uid]);
            return 'appli';
        }
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $saisi));
        if (strlen($norm) !== 10) {
            return null;
        }
        foreach ($e['secours'] as $i => $h) {
            if (password_verify($norm, $h)) {
                unset($e['secours'][$i]);
                $this->pdo->prepare('UPDATE utilisateur_2fa SET codes_secours = ? WHERE utilisateur_id = ?')
                    ->execute([json_encode(array_values($e['secours'])), $uid]);
                return 'secours';
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ appareils de confiance

    /** Enregistre l'appareil et rend le jeton à mettre dans le cookie. */
    public function retenirAppareil(int $uid, string $agent, string $ip): string
    {
        $jeton = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO appareil_confiance (utilisateur_id, jeton_hash, libelle, ip, cree_le, expire_le) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, hash('sha256', $jeton), self::libelleAppareil($agent), mb_substr($ip, 0, 45), date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', time() + self::CONFIANCE_JOURS * 86400)]);
        $this->pdo->prepare('DELETE FROM appareil_confiance WHERE expire_le < ?')->execute([date('Y-m-d H:i:s')]);
        return $jeton;
    }

    /** L'appareil porte un jeton valable pour ce compte (non expiré, enregistré après l'activation en cours). */
    public function appareilReconnu(int $uid, string $jeton): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $jeton)) {
            return false;
        }
        $e = $this->etat($uid);
        if (!$e['active']) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT id, cree_le FROM appareil_confiance WHERE utilisateur_id = ? AND jeton_hash = ? AND expire_le >= ?');
        $st->execute([$uid, hash('sha256', $jeton), date('Y-m-d H:i:s')]);
        $r = $st->fetch();
        if (!$r || ($e['active_le'] !== null && $r['cree_le'] < $e['active_le'])) {
            return false;
        }
        $this->pdo->prepare('UPDATE appareil_confiance SET utilise_le = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $r['id']]);
        return true;
    }

    public function oublierAppareils(int $uid): void
    {
        $this->pdo->prepare('DELETE FROM appareil_confiance WHERE utilisateur_id = ?')->execute([$uid]);
    }

    /** @return array<int,int> nombre d'appareils de confiance valables par compte */
    public function appareilsParCompte(): array
    {
        $st = $this->pdo->prepare('SELECT utilisateur_id, COUNT(*) n FROM appareil_confiance WHERE expire_le >= ? GROUP BY utilisateur_id');
        $st->execute([date('Y-m-d H:i:s')]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['utilisateur_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** « Windows · Edge », « iPhone · Safari »… pour reconnaître l'appareil dans la liste. */
    public static function libelleAppareil(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Appareil',
        };
        $nav = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari', default => '',
        };
        return $nav !== '' ? "$os · $nav" : $os;
    }
}
