<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Web\App;
use Ascs\Web\Auth;
use Ascs\Web\DoubleAuth;

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
        if ($r['ok'] && ($r['etape'] ?? null) !== null) {
            $_SESSION['2fa_attente']['retour'] = $retour;
            $this->app->redirect('/connexion/' . $r['etape']);
        }
        if ($r['ok']) {
            $this->app->redirect($retour);
        }
        return $this->app->render('login', ['erreur' => $r['erreur'], 'email' => $email, 'retour' => $retour], 'Connexion', 'accueil', 401);
    }

    // ------------------------------------------------------------------ double authentification

    private function attente(string $etape): array
    {
        if ($this->app->user) {
            $this->app->redirect('/');
        }
        $a = Auth::attente();
        if ($a === null || $a['etape'] !== $etape) {
            $this->app->flash('erreur', 'Connexion expirée : saisissez de nouveau votre mot de passe.');
            $this->app->redirect('/connexion');
        }
        return $a;
    }

    public function code(array $p): string
    {
        $this->attente('code');
        return $this->app->render('login_code', ['erreur' => null], 'Code de connexion');
    }

    public function verifierCode(array $p): string
    {
        $a = $this->attente('code');
        $auth = new Auth($this->app->pdo);
        if ($auth->bloque($a['email'], Auth::ip())) {
            unset($_SESSION['2fa_attente']);
            $this->app->flash('erreur', 'Trop de tentatives. Patientez ' . Auth::FENETRE_MIN . ' minutes avant de réessayer.');
            $this->app->redirect('/connexion');
        }
        $da = new DoubleAuth($this->app->pdo);
        $type = $da->verifier((int) $a['uid'], (string) ($_POST['code'] ?? ''));
        if ($type === null) {
            $auth->codeFaux($a);
            if (Auth::attente() === null) {
                $this->app->flash('erreur', 'Trop de codes faux : saisissez de nouveau votre mot de passe.');
                $this->app->redirect('/connexion');
            }
            return $this->app->render('login_code', ['erreur' => 'Code incorrect. Vérifiez l\'heure du téléphone, et attendez le code suivant si celui-ci vient de servir.'], 'Code de connexion', 'accueil', 401);
        }
        $retour = $this->retourSur($a['retour'] ?? '/');
        $auth->ouvrirSession((int) $a['uid'], true);
        if (($_POST['souvenir'] ?? '') === '1') {
            $auth->retenirAppareil((int) $a['uid']);
        }
        $this->app->pdo->prepare('DELETE FROM tentative_connexion WHERE email = ?')->execute([$a['email']]);
        if ($type === 'secours') {
            $reste = count($da->etat((int) $a['uid'])['secours']);
            $this->app->flash($reste <= 3 ? 'erreur' : 'ok', "Connexion avec un code de secours : il ne servira plus. Il vous en reste $reste."
                . ($reste <= 3 ? ' Faites réinitialiser votre double authentification par un autre membre du bureau pour en obtenir de nouveaux.' : ''));
        }
        $this->app->redirect($retour);
    }

    public function activation(array $p): string
    {
        $a = $this->attente('activation');
        $_SESSION['2fa_nouveau'] ??= DoubleAuth::nouveauSecret();
        return $this->pageActivation($a, null);
    }

    private function pageActivation(array $a, ?string $erreur): string
    {
        $secret = (string) $_SESSION['2fa_nouveau'];
        return $this->app->render('login_activation', [
            'erreur' => $erreur, 'secret' => $secret, 'lien' => DoubleAuth::lienApplication($secret, (string) $a['email']),
        ], 'Double authentification', 'accueil', $erreur ? 422 : 200);
    }

    public function activer(array $p): string
    {
        $a = $this->attente('activation');
        $secret = (string) ($_SESSION['2fa_nouveau'] ?? '');
        if ($secret === '') {
            $this->app->redirect('/connexion/activation');
        }
        $pas = DoubleAuth::verifierCode($secret, (string) ($_POST['code'] ?? ''));
        if ($pas === null) {
            (new Auth($this->app->pdo))->codeFaux($a);
            if (Auth::attente() === null) {
                $this->app->flash('erreur', 'Trop de codes faux : saisissez de nouveau votre mot de passe.');
                $this->app->redirect('/connexion');
            }
            return $this->pageActivation($a, 'Code incorrect. Vérifiez que l\'heure du téléphone est à l\'heure automatique, puis saisissez le code affiché.');
        }
        $codes = (new DoubleAuth($this->app->pdo))->activer((int) $a['uid'], $secret, $pas, (string) $a['email']);
        $retour = $this->retourSur($a['retour'] ?? '/');
        $auth = new Auth($this->app->pdo);
        $auth->ouvrirSession((int) $a['uid'], true);
        if (($_POST['souvenir'] ?? '') === '1') {
            $auth->retenirAppareil((int) $a['uid']);
        }
        return $this->app->render('login_secours', ['codes' => $codes, 'retour' => $retour], 'Codes de secours');
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
