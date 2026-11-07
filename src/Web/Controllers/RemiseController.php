<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Input;
use Ascs\Domain\Remises;
use Ascs\Web\App;

final class RemiseController
{
    private Remises $rem;

    public function __construct(private readonly App $app)
    {
        $this->rem = new Remises($app->pdo);
    }

    public function index(array $p): string
    {
        $this->rem->rapprocher();
        return $this->app->render('remises/index', [
            'liste' => $this->rem->liste(), 'dates' => $this->rem->datesEnAttente(), 'depuis' => $this->rem->depuis(),
        ], 'Remises de chèques');
    }

    public function depuis(array $p): void
    {
        $d = Input::date($_POST, 'depuis');
        if ($d) {
            $this->rem->changerDepuis($d);
        }
        $this->app->redirect('/remises');
    }

    public function nouvelle(array $p): string
    {
        $date = Input::date($_GET, 'date') ?? $this->rem->dateProposee();
        $cheques = $date ? $this->rem->chequesAPoser($date) : [];
        return $this->app->render('remises/nouvelle', [
            'date' => $date, 'cheques' => $cheques, 'dates' => $this->rem->datesEnAttente(),
            'decoupage' => Remises::decoupage(count($cheques)), 'comptes' => $this->rem->comptesBancaires(),
        ], 'Nouvelle remise de chèques');
    }

    public function creer(array $p): void
    {
        $date = Input::date($_POST, 'date');
        $compte = Input::str($_POST, 'numero_compte', 20);
        try {
            if (!$date) {
                throw new \RuntimeException('Date de pose manquante.');
            }
            $ids = $this->rem->creer($date, array_filter((array) ($_POST['cheques'] ?? []), 'is_string'), (string) $this->app->user['email'], $compte);
        } catch (\RuntimeException $e) {
            $this->app->flash('erreur', $e->getMessage());
            $this->app->redirect('/remises/nouvelle', ['date' => $date]);
        }
        $this->app->flash('ok', count($ids) > 1
            ? count($ids) . ' remises créées (30 chèques au plus par remise). Remplissez un bordereau de la banque pour chacune.'
            : 'Remise créée.');
        $this->app->redirect('/remises/' . $ids[0]);
    }

    public function fiche(array $p): string
    {
        $this->rem->rapprocher();
        $r = $this->rem->trouver((int) $p['id']);
        if (!$r) {
            return $this->app->render('erreur', ['titre' => 'Remise introuvable', 'message' => 'Cette remise n\'existe pas ou a été supprimée.'], 'Introuvable', 'accueil', 404);
        }
        return $this->app->render('remises/fiche', [
            'r' => $r, 'ventilation' => $this->rem->ventilation((int) $r['id']), 'comptes' => $this->rem->comptesBancaires(),
        ], 'Remise de chèques');
    }

    public function enregistrer(array $p): void
    {
        $id = (int) $p['id'];
        try {
            $this->rem->enregistrerInfos($id, Input::str($_POST, 'numero', 20), Input::date($_POST, 'date_depot'), Input::str($_POST, 'numero_compte', 20));
            $res = $this->rem->rapprocher();
            $r = $this->rem->trouver($id);
            $this->app->flash('ok', match ($r['etat_banque'] ?? '') {
                'ventilee' => 'Enregistré. La ligne de la banque a été trouvée et ventilée par section.',
                'ecart' => 'Enregistré, mais le montant de la ligne de la banque est différent du total de la remise : vérifiez.',
                'deja_ventilee' => 'Enregistré. La ligne de la banque était déjà ventilée : rien n\'a été modifié.',
                default => 'Enregistré. La ventilation se fera dès que la ligne de la banque apparaîtra dans les comptes.',
            });
        } catch (\RuntimeException $e) {
            $this->app->flash('erreur', $e->getMessage());
        }
        $this->app->redirect('/remises/' . $id);
    }

    public function retirer(array $p): void
    {
        $id = (int) $p['id'];
        try {
            $this->rem->retirerCheque($id, (int) ($_POST['cheque'] ?? 0));
            $this->app->flash('ok', 'Chèque retiré de la remise : il redevient « à remettre ».');
        } catch (\RuntimeException $e) {
            $this->app->flash('erreur', $e->getMessage());
        }
        $this->app->redirect($this->rem->trouver($id) ? '/remises/' . $id : '/remises');
    }

    public function supprimer(array $p): void
    {
        try {
            $this->rem->supprimer((int) $p['id']);
            $this->app->flash('ok', 'Remise supprimée : ses chèques redeviennent « à remettre ».');
            $this->app->redirect('/remises');
        } catch (\RuntimeException $e) {
            $this->app->flash('erreur', $e->getMessage());
            $this->app->redirect('/remises/' . (int) $p['id']);
        }
    }

    public function imprimer(array $p): string
    {
        $r = $this->rem->trouver((int) $p['id']);
        if (!$r) {
            $this->app->redirect('/remises');
        }
        return $this->app->renderPublic('remises/bordereau', ['r' => $r, 'ventilation' => $this->rem->ventilation((int) $r['id'])], 'Remise de chèques');
    }
}
