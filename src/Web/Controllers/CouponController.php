<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Documents\DevisCoupon;
use Ascs\Web\App;

/** Devis « coupon sport / culture » de la mairie : PDF pré-rempli, envoi par mail avec la procédure, réglages. */
final class CouponController
{
    private DevisCoupon $dc;

    public function __construct(private readonly App $app)
    {
        $this->dc = new DevisCoupon($app->pdo);
    }

    public function pdf(array $p): string
    {
        $d = $this->dc->generer([(int) $p['id']]);
        if (!$d) {
            return $this->app->render('erreur', ['titre' => 'Inscription introuvable', 'message' => 'Cette inscription n\'existe pas.'], 'Introuvable', 'accueil', 404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $d['nom'] . '"');
        header('Cache-Control: private, no-store');
        return $d['pdf'];
    }

    public function envoyer(array $p): void
    {
        $id = (int) $p['id'];
        $e = $this->dc->envoyer([$id], trim((string) ($_POST['destinataire'] ?? '')), (string) ($this->app->user['email'] ?? ''), (string) ($_POST['message'] ?? ''));
        $this->app->flash($e ? 'erreur' : 'ok', $e ? 'Devis non envoyé : ' . $e : 'Devis coupon et procédure envoyés à ' . trim((string) $_POST['destinataire']) . '.');
        $this->app->redirect('/inscriptions/' . $id);
    }

    public function reglages(array $p): string
    {
        return $this->app->render('parametres/coupons', [
            'nom' => $this->dc->reglage('nom'), 'pied' => $this->dc->reglage('pied'), 'objet' => $this->dc->reglage('objet'), 'texte' => $this->dc->reglage('texte'),
            'procedure' => DevisCoupon::procedure() !== null,
            'exemple' => (int) $this->app->pdo->query('SELECT MAX(id) FROM inscription WHERE montant > 0')->fetchColumn(),
        ], 'Coupons mairie', 'parametrage');
    }

    public function enregistrer(array $p): void
    {
        $this->dc->enregistrer($_POST);
        $err = null;
        if (!empty($_POST['retirer_procedure']) && DevisCoupon::procedure()) {
            unlink(DevisCoupon::fichierProcedure());
        }
        if (isset($_FILES['procedure']) && ($_FILES['procedure']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = DevisCoupon::deposerProcedure($_FILES['procedure']);
        }
        $this->app->flash($err ? 'erreur' : 'ok', $err ? 'Procédure : ' . $err : 'Réglages des coupons enregistrés.');
        $this->app->redirect('/parametres/coupons');
    }

    public function procedure(array $p): string
    {
        $f = DevisCoupon::procedure();
        if (!$f) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Procedure_coupon_sport_culture.pdf"');
        header('Cache-Control: private, no-store');
        return (string) file_get_contents($f);
    }
}
