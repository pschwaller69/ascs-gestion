<?php
declare(strict_types=1);

namespace Ascs\Web;

use PDO;

/** Connexion des membres du bureau, avec limitation des essais. */
final class Auth
{
    public const MAX_PAR_EMAIL = 5;
    public const MAX_PAR_IP = 20;
    public const FENETRE_MIN = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 45);
    }

    public function bloque(string $email, string $ip): bool
    {
        $depuis = date('Y-m-d H:i:s', time() - self::FENETRE_MIN * 60);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM tentative_connexion WHERE email = ? AND date_tentative >= ?');
        $st->execute([$email, $depuis]);
        if ((int) $st->fetchColumn() >= self::MAX_PAR_EMAIL) {
            return true;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM tentative_connexion WHERE ip = ? AND date_tentative >= ?');
        $st->execute([$ip, $depuis]);
        return (int) $st->fetchColumn() >= self::MAX_PAR_IP;
    }

    /** @return array{ok:bool,erreur:?string,etape?:?string} etape = 'code' | 'activation' quand la double authentification est exigée */
    public function connecter(string $email, string $motDePasse): array
    {
        $email = mb_strtolower(trim($email));
        $ip = self::ip();
        if ($this->bloque($email, $ip)) {
            return ['ok' => false, 'erreur' => 'Trop de tentatives. Patientez ' . self::FENETRE_MIN . ' minutes avant de réessayer.'];
        }
        $st = $this->pdo->prepare('SELECT id, mot_de_passe FROM utilisateur WHERE email = ? AND actif = 1');
        $st->execute([$email]);
        $u = $st->fetch();
        // Vérification même si l'utilisateur n'existe pas, pour ne pas révéler les adresses connues par le temps de réponse.
        $hash = $u['mot_de_passe'] ?? '$2y$10$usesomesillystringforsalt.invalidhashinvalidhashinvalidhashin';
        $valide = password_verify($motDePasse, $hash) && $u;
        if (!$valide) {
            $this->pdo->prepare('INSERT INTO tentative_connexion (email, ip, date_tentative) VALUES (?,?,?)')
                ->execute([$email, $ip, date('Y-m-d H:i:s')]);
            return ['ok' => false, 'erreur' => 'Adresse mail ou mot de passe incorrect.'];
        }
        $this->pdo->prepare('DELETE FROM tentative_connexion WHERE email = ?')->execute([$email]);
        $this->pdo->prepare('DELETE FROM tentative_connexion WHERE date_tentative < ?')
            ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $u['id']]);
        }
        // Double authentification exigée pour ce compte : la session n'est ouverte qu'après le code
        $double = new DoubleAuth($this->pdo);
        $da = $double->etat((int) $u['id']);
        // Ordinateur de confiance (« se souvenir ») : le mot de passe suffit sur cet appareil
        if ($da['exigee'] && $da['active'] && $double->appareilReconnu((int) $u['id'], (string) ($_COOKIE[DoubleAuth::COOKIE] ?? ''))) {
            $this->ouvrirSession((int) $u['id'], true);
            return ['ok' => true, 'erreur' => null, 'etape' => null];
        }
        if ($da['exigee']) {
            session_regenerate_id(true);
            $_SESSION = ['2fa_attente' => ['uid' => (int) $u['id'], 'email' => $email, 'depuis' => time(), 'essais' => 0,
                'etape' => $da['active'] ? 'code' : 'activation']];
            return ['ok' => true, 'erreur' => null, 'etape' => $_SESSION['2fa_attente']['etape']];
        }
        $this->ouvrirSession((int) $u['id'], false);
        return ['ok' => true, 'erreur' => null, 'etape' => null];
    }

    public function ouvrirSession(int $uid, bool $doubleAuth): void
    {
        $this->pdo->prepare('UPDATE utilisateur SET derniere_connexion = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $uid]);
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['uid'] = $uid;
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if ($doubleAuth) {
            $_SESSION['2fa_ok'] = true;
            $_SESSION['2fa_le'] = (new DoubleAuth($this->pdo))->etat($uid)['active_le'];
        }
    }

    /** Retient cet appareil pour le compte (cookie HttpOnly, 30 jours). */
    public function retenirAppareil(int $uid): void
    {
        $jeton = (new DoubleAuth($this->pdo))->retenirAppareil($uid, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), self::ip());
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
        setcookie(DoubleAuth::COOKIE, $jeton, ['expires' => time() + DoubleAuth::CONFIANCE_JOURS * 86400, 'path' => '/',
            'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** Connexion en attente du code (mot de passe déjà vérifié), null si absente ou périmée. */
    public static function attente(): ?array
    {
        $a = $_SESSION['2fa_attente'] ?? null;
        if (!is_array($a) || time() - (int) $a['depuis'] > DoubleAuth::ATTENTE_MAX || (int) $a['essais'] >= DoubleAuth::ESSAIS_MAX) {
            unset($_SESSION['2fa_attente'], $_SESSION['2fa_nouveau']);
            return null;
        }
        return $a;
    }

    /** Code faux : compté pour la limite d'essais de l'attente et pour celle de l'adresse (comme un mauvais mot de passe). */
    public function codeFaux(array $a): void
    {
        $_SESSION['2fa_attente']['essais'] = (int) $a['essais'] + 1;
        $this->pdo->prepare('INSERT INTO tentative_connexion (email, ip, date_tentative) VALUES (?,?,?)')
            ->execute([$a['email'], self::ip(), date('Y-m-d H:i:s')]);
    }

    public static function deconnecter(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
