<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Adherents;
use Ascs\Domain\Demandes;
use Ascs\Domain\DuplicateFinder;
use Ascs\Web\App;

/** File des demandes reçues en ligne (inscriptions, cours d'essai), validées par le bureau. */
final class DemandeController
{
    private Demandes $dem;

    public function __construct(private readonly App $app)
    {
        $this->dem = new Demandes($app->pdo);
    }

    private function par(): string
    {
        return (string) ($this->app->user['email'] ?? '');
    }

    public function index(array $p): string
    {
        $vue = in_array($_GET['vue'] ?? '', ['valide', 'refuse'], true) ? $_GET['vue'] : 'a_traiter';
        return $this->app->render('demandes/index', ['vue' => $vue, 'liste' => $this->dem->liste($vue), 'compteurs' => $this->dem->compteurs()], 'Demandes en ligne');
    }

    public function fiche(array $p, array $err = []): string
    {
        $d = $this->dem->trouver((int) $p['id']);
        if (!$d) {
            return $this->app->render('erreur', ['titre' => 'Demande introuvable', 'message' => 'Cette demande n\'existe pas.'], 'Introuvable', 'accueil', 404);
        }
        $candidats = $d['statut'] === 'a_traiter' ? $this->dem->candidats($d) : [];
        $niveauA = array_values(array_filter($candidats, static fn ($c) => $c['niveau'] === 'A'));
        $post = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
        $choix = (string) ($post['adherent'] ?? (count($niveauA) === 1 ? $niveauA[0]['id'] : ($candidats ? '' : 'nouveau')));
        $fiche = $post ? array_merge(Demandes::ficheDepuis($d), array_intersect_key($post, Adherents::vide())) : Demandes::ficheDepuis($d);
        $inscriptions = [];
        if ($d['inscriptions']) {
            $ids = array_map('intval', json_decode((string) $d['inscriptions'], true) ?: []);
            if ($ids) {
                $inscriptions = $this->app->pdo->query('SELECT i.id, s.titre FROM inscription i JOIN section s ON s.id = i.section_id WHERE i.id IN (' . implode(',', $ids) . ')')->fetchAll();
            }
        }
        return $this->app->render('demandes/fiche', [
            'd' => $d, 'candidats' => $candidats, 'choix' => $choix, 'fiche' => $fiche, 'err' => $err, 'post' => $post,
            'saisons' => $this->app->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll(),
            'niveaux' => DuplicateFinder::NIVEAUX, 'inscriptions' => $inscriptions,
        ], 'Demande ' . $d['reference'], 'accueil', $err ? 422 : 200);
    }

    public function valider(array $p): string
    {
        $id = (int) $p['id'];
        $r = $this->dem->valider($id, $_POST, $this->par());
        if (!$r['ok']) {
            if (isset($r['erreurs']['_'])) {
                $this->app->flash('erreur', $r['erreurs']['_']);
                $this->app->redirect('/demandes/' . $id);
            }
            return $this->fiche($p, $r['erreurs']);
        }
        $n = count($r['inscriptions']);
        $this->app->flash('ok', $n > 1 ? "$n inscriptions créées." : 'Inscription créée.');
        $suivante = $this->app->pdo->query("SELECT id FROM demande WHERE statut = 'a_traiter' ORDER BY cree_le LIMIT 1")->fetchColumn();
        if (($_POST['suite'] ?? '') === 'suivante' && $suivante !== false) {
            $this->app->redirect('/demandes/' . $suivante);
        }
        $adh = $this->app->pdo->query('SELECT adherent_id FROM demande WHERE id = ' . $id)->fetchColumn();
        $this->app->redirect('/adherents/' . $adh, ['onglet' => 'inscriptions']);
    }

    public function refuser(array $p): void
    {
        $ok = $this->dem->refuser((int) $p['id'], $this->par());
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Demande refusée : rien n\'a été créé.' : 'Cette demande n\'est plus à traiter.');
        $this->app->redirect('/demandes');
    }

    public function retablir(array $p): void
    {
        $ok = $this->dem->retablir((int) $p['id']);
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Demande remise à traiter.' : 'Action impossible.');
        $this->app->redirect('/demandes/' . (int) $p['id']);
    }
}
