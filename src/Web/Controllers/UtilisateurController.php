<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Web\App;

/** Comptes du bureau : liste, création, mot de passe, activation. */
final class UtilisateurController
{
    public function __construct(private readonly App $app)
    {
    }

    private function liste(): array
    {
        return $this->app->pdo->query('SELECT id, email, nom, actif, derniere_connexion FROM utilisateur ORDER BY nom, email')->fetchAll();
    }

    public function index(array $p, array $err = [], array $saisie = []): string
    {
        return $this->app->render('utilisateurs/index', ['liste' => $this->liste(), 'tests' => \Ascs\Web\ModeTest::comptes($this->app->pdo), 'mailTest' => (string) (\Ascs\Parametres::get($this->app->pdo, \Ascs\Web\ModeTest::CLE_MAIL) ?? \Ascs\Web\ModeTest::MAIL_DEFAUT), 'err' => $err, 'saisie' => $saisie + ['email' => '', 'nom' => '']], 'Comptes du bureau', 'parametrage', $err ? 422 : 200);
    }

    public function creer(array $p): string
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $nom = mb_substr(trim((string) ($_POST['nom'] ?? '')), 0, 100);
        $mdp = (string) ($_POST['mot_de_passe'] ?? '');
        $err = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err['email'] = 'Adresse mail invalide.';
        } else {
            $st = $this->app->pdo->prepare('SELECT COUNT(*) FROM utilisateur WHERE email = ?');
            $st->execute([$email]);
            if ((int) $st->fetchColumn() > 0) {
                $err['email'] = 'Un compte existe déjà avec cette adresse.';
            }
        }
        if (mb_strlen($mdp) < 12) {
            $err['mot_de_passe'] = '12 caractères minimum.';
        } elseif ($mdp !== (string) ($_POST['confirmation'] ?? '')) {
            $err['confirmation'] = 'Les deux mots de passe ne correspondent pas.';
        }
        if ($err) {
            return $this->index($p, $err, ['email' => $email, 'nom' => $nom]);
        }
        $this->app->pdo->prepare('INSERT INTO utilisateur (email, nom, mot_de_passe, actif, cree_le) VALUES (?,?,?,1,?)')
            ->execute([$email, $nom, password_hash($mdp, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        if (!empty($_POST['test'])) {
            \Ascs\Web\ModeTest::regler($this->app->pdo, (int) $this->app->pdo->lastInsertId(), true);
        }
        $this->app->flash('ok', "Compte créé pour $email. Communiquez-lui son mot de passe de vive voix, pas par mail.");
        $this->app->redirect('/utilisateurs');
    }

    public function motDePasse(array $p): void
    {
        $id = (int) $p['id'];
        $mdp = (string) ($_POST['mot_de_passe'] ?? '');
        if (mb_strlen($mdp) < 12) {
            $this->app->flash('erreur', 'Mot de passe non modifié : 12 caractères minimum.');
        } else {
            $this->app->pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?')->execute([password_hash($mdp, PASSWORD_DEFAULT), $id]);
            $this->app->flash('ok', 'Mot de passe modifié.');
        }
        $this->app->redirect('/utilisateurs');
    }

    public function activer(array $p): void
    {
        $id = (int) $p['id'];
        $actif = ($_POST['actif'] ?? '') === '1' ? 1 : 0;
        if ($id === (int) $this->app->user['id'] && $actif === 0) {
            $this->app->flash('erreur', 'Vous ne pouvez pas désactiver votre propre compte.');
        } else {
            $this->app->pdo->prepare('UPDATE utilisateur SET actif = ? WHERE id = ?')->execute([$actif, $id]);
            $this->app->flash('ok', $actif ? 'Compte réactivé.' : 'Compte désactivé : cette personne ne peut plus se connecter.');
        }
        $this->app->redirect('/utilisateurs');
    }

    /** Passe un compte en compte de test (aucun mail, actions sensibles désactivées), ou le remet en compte normal. */
    public function test(array $p): void
    {
        $id = (int) $p['id'];
        $test = ($_POST['test'] ?? '') === '1';
        if ($id === (int) $this->app->user['id'] && $test) {
            $this->app->flash('erreur', 'Vous ne pouvez pas passer votre propre compte en compte de test.');
        } else {
            \Ascs\Web\ModeTest::regler($this->app->pdo, $id, $test);
            $this->app->flash('ok', $test ? 'Compte de test : aucun mail ne part et les actions sensibles sont désactivées.' : 'Compte remis en compte normal.');
        }
        $this->app->redirect('/utilisateurs');
    }

    public function mailTest(array $p): void
    {
        $a = mb_strtolower(trim((string) ($_POST['adresse'] ?? '')));
        if ($a !== '' && !filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $this->app->flash('erreur', 'Adresse invalide.');
        } else {
            \Ascs\Web\ModeTest::reglerAdresse($this->app->pdo, $a);
            $this->app->flash('ok', $a !== '' ? "Les mails des comptes de test arrivent à $a." : 'Aucun mail ne part pour les comptes de test.');
        }
        $this->app->redirect('/utilisateurs');
    }

    public function journal(array $p): string
    {
        return $this->app->render('utilisateurs/journal', ['erreurs' => \Ascs\Web\Erreurs::recentes()], 'Journal des erreurs', 'parametrage');
    }
}
