<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Web\App;
use Ascs\Web\Auth;

final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    /** Ne renvoie que des chemins internes (pas de redirection vers un autre site). */
    private function retourSur(mixed $r): string
    {
        $r = is_string($r) ? $r : '';
        return ($r !== '' && $r[0] === '/' && !str_starts_with($r, '//') && !str_contains($r, '\\')) ? $r : '/';
    }

    public function form(array $p): string
    {
        if ($this->app->user) {
            $this->app->redirect('/');
        }
        return $this->app->render('login', ['erreur' => null, 'email' => '', 'retour' => $this->retourSur($_GET['retour'] ?? '/')], 'Connexion');
    }

    public function connexion(array $p): string
    {
        $email = (string) ($_POST['email'] ?? '');
        $retour = $this->retourSur($_POST['retour'] ?? '/');
        $r = (new Auth($this->app->pdo))->connecter($email, (string) ($_POST['mot_de_passe'] ?? ''));
        if ($r['ok']) {
            $this->app->redirect($retour);
        }
        return $this->app->render('login', ['erreur' => $r['erreur'], 'email' => $email, 'retour' => $retour], 'Connexion', 'accueil', 401);
    }

    public function deconnexion(array $p): void
    {
        Auth::deconnecter();
        $this->app->redirect('/connexion');
    }

    public function saison(array $p): void
    {
        $id = (int) ($_POST['saison'] ?? 0);
        $st = $this->app->pdo->prepare('SELECT COUNT(*) FROM saison WHERE id = ?');
        $st->execute([$id]);
        if ($id > 0 && (int) $st->fetchColumn() > 0) {
            $_SESSION['saison'] = $id;
        }
        $this->app->redirect($this->retourSur($_POST['retour'] ?? '/'));
    }
}
