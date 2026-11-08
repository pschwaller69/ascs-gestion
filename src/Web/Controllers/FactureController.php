<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Documents\Facture;
use Ascs\Documents\Reglages;
use Ascs\Mail\Envoi;
use Ascs\Web\App;

/** Facture PDF d'une inscription (affichage, envoi par mail) et réglages des documents. */
final class FactureController
{
    public function __construct(private readonly App $app)
    {
    }

    public function pdf(array $p): string
    {
        $f = (new Facture($this->app->pdo))->generer((int) $p['id']);
        if (!$f) {
            return $this->app->render('erreur', ['titre' => 'Inscription introuvable', 'message' => 'Cette inscription n\'existe pas ou a été supprimée.'], 'Introuvable', 'accueil', 404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['telecharger']) ? 'attachment' : 'inline') . '; filename="' . $f['nom'] . '"');
        header('Cache-Control: private, no-store');
        return $f['pdf'];
    }

    /** Texte proposé pour le mail (modifiable avant l'envoi). */
    public static function message(array $i, array $r): string
    {
        return "Bonjour,\n\nVeuillez trouver ci-joint la facture de l'inscription de " . trim($i['prenom'] . ' ' . $i['nom'])
            . ' (' . $i['section'] . ', saison ' . $i['saison'] . ").\n\nCordialement,\n" . ($r['association'] ?: 'ASCS');
    }

    public function envoyer(array $p): void
    {
        $id = (int) $p['id'];
        $f = (new Facture($this->app->pdo))->generer($id);
        if (!$f) {
            $this->app->flash('erreur', 'Inscription introuvable.');
            $this->app->redirect('/inscriptions');
        }
        $r = (new Reglages($this->app->pdo))->tous();
        $a = trim((string) ($_POST['destinataire'] ?? ''));
        $texte = trim((string) ($_POST['message'] ?? '')) ?: self::message($f['inscription'], $r);
        $erreur = (new Envoi($this->app->pdo))->envoyer(
            $r['expediteur'], $r['association'] ?: 'ASCS', $a,
            'Facture ' . ($r['association'] ?: 'ASCS') . ' – ' . trim($f['inscription']['prenom'] . ' ' . $f['inscription']['nom']) . ' – ' . $f['inscription']['section'],
            $texte, [['nom' => $f['nom'], 'contenu' => $f['pdf'], 'type' => 'application/pdf']], $id,
            (string) ($this->app->user['email'] ?? '')
        );
        $this->app->flash($erreur ? 'erreur' : 'ok', $erreur ? 'Facture non envoyée : ' . $erreur : 'Facture envoyée à ' . $a . '.');
        $this->app->redirect('/inscriptions/' . $id);
    }

    public function reglages(array $p): string
    {
        return $this->app->render('parametres/facture', [
            'v' => (new Reglages($this->app->pdo))->tous(),
            'images' => array_combine(array_keys(Reglages::IMAGES), array_map(static fn ($n) => Reglages::image($n) !== null, array_keys(Reglages::IMAGES))),
            'exemple' => (int) $this->app->pdo->query('SELECT MAX(id) FROM inscription')->fetchColumn(),
        ], 'Facture', 'parametrage');
    }

    public function enregistrer(array $p): void
    {
        (new Reglages($this->app->pdo))->enregistrer($_POST);
        $erreurs = [];
        foreach (array_keys(Reglages::IMAGES) as $n) {
            if (!empty($_POST['retirer_' . $n])) {
                Reglages::supprimer($n);
            }
            if (isset($_FILES[$n]) && ($_FILES[$n]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $e = Reglages::deposer($n, $_FILES[$n]);
                if ($e) {
                    $erreurs[] = Reglages::IMAGES[$n] . ' : ' . $e;
                }
            }
        }
        foreach (['expediteur' => 'Adresse d\'envoi des mails invalide.', 'reponse' => 'Adresse de réponse invalide.'] as $k => $msg) {
            $val = trim((string) ($_POST[$k] ?? ''));
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $erreurs[] = $msg;
            }
        }
        $this->app->flash($erreurs ? 'erreur' : 'ok', $erreurs ? implode(' ', $erreurs) : 'Réglages de la facture enregistrés.');
        $this->app->redirect('/parametres/facture');
    }

    /** Mail d'essai envoyé au compte connecté, pour vérifier les réglages d'envoi. */
    public function essai(array $p): void
    {
        $r = (new Reglages($this->app->pdo))->tous();
        $a = (string) ($this->app->user['email'] ?? '');
        $e = (new Envoi($this->app->pdo))->envoyer($r['expediteur'], $r['association'] ?: 'ASCS', $a, 'Mail d\'essai – ' . ($r['association'] ?: 'ASCS'),
            "Bonjour,\n\nCe mail d'essai confirme que l'application de gestion peut envoyer des mails.\nExpéditeur : " . $r['expediteur']
            . "\nRéponses vers : " . ($r['reponse'] ?: $r['expediteur']) . "\n\nRépondez à ce mail pour vérifier que la réponse arrive au bon endroit.", [], null, $a);
        $this->app->flash($e ? 'erreur' : 'ok', $e ? 'Mail d\'essai non envoyé : ' . $e : 'Mail d\'essai envoyé à ' . $a . '. Vérifiez qu\'il arrive (et pas dans les indésirables).');
        $this->app->redirect('/parametres/facture');
    }

    public function image(array $p): string
    {
        $f = Reglages::image((string) $p['nom']);
        if (!$f) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: image/png');
        header('Cache-Control: private, no-cache');
        return (string) file_get_contents($f);
    }
}
