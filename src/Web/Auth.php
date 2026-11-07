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

    /** @return array{ok:bool,erreur:?string} */
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
        $this->pdo->prepare('UPDATE utilisateur SET derniere_connexion = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return ['ok' => true, 'erreur' => null];
    }

    public static function deconnecter(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
