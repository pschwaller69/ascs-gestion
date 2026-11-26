<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\HelloAsso\PaiementEnLigne;
use Ascs\Web\App;

/** Garanties par carte des aides (coupons mairie…) : suivi, confirmation de l'aide (annulation du prélèvement), remboursement. */
final class GarantieController
{
    private PaiementEnLigne $pel;

    public function __construct(private readonly App $app)
    {
        $this->pel = new PaiementEnLigne($app->pdo);
    }

    private function par(): string
    {
        return (string) ($this->app->user['email'] ?? '');
    }

    public function index(array $p): string
    {
        $toutes = isset($_GET['toutes']);
        return $this->app->render('garanties/index', ['liste' => $this->pel->listeGaranties(null, $toutes), 'toutes' => $toutes], 'Garanties par carte', 'accueil');
    }

    public function confirmer(array $p): void
    {
        $m = $this->pel->confirmerAide((int) $p['id'], $this->par());
        $this->app->flash(str_starts_with($m, 'Prélèvement') || str_starts_with($m, 'Annulation programmée') ? 'ok' : 'erreur', $m);
        $this->app->redirect((string) ($_POST['retour'] ?? '') === 'demande' && !empty($_POST['demande']) ? '/demandes/' . (int) $_POST['demande'] : '/garanties');
    }

    public function rembourser(array $p): void
    {
        if (empty($_POST['confirme'])) {
            $this->app->flash('erreur', 'Cochez la confirmation du remboursement.');
            $this->app->redirect('/garanties');
        }
        $e = $this->pel->rembourserGarantie((int) $p['id'], $this->par());
        $this->app->flash($e ? 'erreur' : 'ok', $e ? 'Remboursement non fait : ' . $e : 'Garantie remboursée sur HelloAsso.');
        $this->app->redirect('/garanties');
    }
}
