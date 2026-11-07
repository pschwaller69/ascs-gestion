<?php
declare(strict_types=1);

// Gestion des comptes du bureau.
//   php bin/utilisateur.php creer <email> "<Nom>"     (le mot de passe est demandé)
//   php bin/utilisateur.php lister
//   php bin/utilisateur.php mdp <email>
//   php bin/utilisateur.php desactiver <email>   |   activer <email>

require __DIR__ . '/../src/autoload.php';

use Ascs\Db;

$pdo = Db::connect();
$cmd = $argv[1] ?? '';
$email = isset($argv[2]) ? mb_strtolower(trim($argv[2])) : '';

function saisirMotDePasse(): string
{
    $mdp = getenv('ASCS_MDP') ?: '';
    if ($mdp === '') {
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            system('stty -echo');
        }
        fwrite(STDERR, 'Mot de passe (12 caractères minimum) : ');
        $mdp = rtrim((string) fgets(STDIN), "\r\n");
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            system('stty echo');
            fwrite(STDERR, "\n");
        }
    }
    if (mb_strlen($mdp) < 12) {
        fwrite(STDERR, "Mot de passe trop court (12 caractères minimum).\n");
        exit(2);
    }
    return $mdp;
}

switch ($cmd) {
    case 'creer':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDERR, "Usage : php bin/utilisateur.php creer <email> \"<Nom>\"\n");
            exit(2);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM utilisateur WHERE email = ?');
        $st->execute([$email]);
        if ((int) $st->fetchColumn() > 0) {
            fwrite(STDERR, "Cet utilisateur existe déjà (utilisez « mdp » pour changer son mot de passe).\n");
            exit(2);
        }
        $pdo->prepare('INSERT INTO utilisateur (email, nom, mot_de_passe, actif, cree_le) VALUES (?,?,?,1,?)')
            ->execute([$email, $argv[3] ?? '', password_hash(saisirMotDePasse(), PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        echo "Utilisateur créé : $email\n";
        break;
    case 'mdp':
        $st = $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE email = ?');
        $st->execute([password_hash(saisirMotDePasse(), PASSWORD_DEFAULT), $email]);
        echo $st->rowCount() ? "Mot de passe modifié : $email\n" : "Utilisateur inconnu : $email\n";
        break;
    case 'desactiver':
    case 'activer':
        $st = $pdo->prepare('UPDATE utilisateur SET actif = ? WHERE email = ?');
        $st->execute([$cmd === 'activer' ? 1 : 0, $email]);
        echo $st->rowCount() ? "OK : $email " . ($cmd === 'activer' ? 'activé' : 'désactivé') . "\n" : "Utilisateur inconnu : $email\n";
        break;
    case 'lister':
        foreach ($pdo->query('SELECT email, nom, actif, derniere_connexion FROM utilisateur ORDER BY email') as $u) {
            printf("%-35s %-25s %-9s %s\n", $u['email'], $u['nom'], $u['actif'] ? 'actif' : 'désactivé', $u['derniere_connexion'] ?? 'jamais connecté');
        }
        break;
    default:
        fwrite(STDERR, "Commandes : creer, lister, mdp, desactiver, activer\n");
        exit(2);
}
