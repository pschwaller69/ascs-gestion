<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Adherents;
use Ascs\Domain\DuplicateFinder;
use Ascs\Domain\Inscriptions;
use Ascs\Domain\Reglements;
use Ascs\Web\App;

final class AdherentController
{
    private Adherents $adherents;

    public function __construct(private readonly App $app)
    {
        $this->adherents = new Adherents($app->pdo);
    }

    public function index(array $p): string
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $saisonSeule = ($_GET['filtre'] ?? 'saison') !== 'tous';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $sid = $this->app->saisonId();
        $r = $this->adherents->lister($q, $saisonSeule, $sid, $page);
        return $this->app->render('adherents/index', [
            'q' => $q, 'saisonSeule' => $saisonSeule, 'page' => $page, 'lignes' => $r['lignes'], 'total' => $r['total'],
            'parPage' => Adherents::PAR_PAGE,
        ], 'Adhérents');
    }

    public function nouveau(array $p): string
    {
        $d = Adherents::vide();
        foreach (['nom', 'prenom'] as $k) {
            if (isset($_GET[$k]) && is_string($_GET[$k])) {
                $d[$k] = mb_substr($_GET[$k], 0, 100);
            }
        }
        return $this->app->render('adherents/nouveau', ['d' => $d, 'err' => []], 'Nouvel adhérent');
    }

    public function creer(array $p): string
    {
        [$d, $err] = $this->adherents->nettoyer($_POST);
        if ($err) {
            return $this->app->render('adherents/nouveau', ['d' => $this->pourForm($d, $_POST), 'err' => $err], 'Nouvel adhérent', 'accueil', 422);
        }
        $trouves = (new DuplicateFinder($this->app->pdo))->chercher($d);
        if ($trouves && empty($_POST['confirme_doublon'])) {
            return $this->app->render('adherents/doublon', ['d' => $d, 'trouves' => $trouves, 'post' => $_POST], 'Fiche peut-être déjà existante');
        }
        $id = $this->adherents->creer($d);
        $this->app->flash('ok', 'Adhérent créé.');
        $suite = $_POST['suite'] ?? '';
        $this->app->redirect($suite === 'inscription' ? '/inscriptions/nouveau' : '/adherents/' . $id, $suite === 'inscription' ? ['adherent' => $id] : []);
    }

    public function fiche(array $p): string
    {
        $id = (int) $p['id'];
        $a = $this->adherents->trouver($id);
        if (!$a) {
            return $this->app->render('erreur', ['titre' => 'Adhérent introuvable', 'message' => 'Cette fiche n\'existe pas ou a été supprimée.'], 'Adhérent introuvable', 'accueil', 404);
        }
        return $this->afficher($a, $a, []);
    }

    public function modifier(array $p): string
    {
        $id = (int) $p['id'];
        $a = $this->adherents->trouver($id);
        if (!$a) {
            $this->app->redirect('/adherents');
        }
        [$d, $err] = $this->adherents->nettoyer($_POST);
        if ($err) {
            return $this->afficher($a, $this->pourForm($d, $_POST), $err, 422);
        }
        $this->adherents->modifier($id, $d);
        $this->app->flash('ok', 'Fiche enregistrée.');
        $suite = $_POST['suite'] ?? '';
        if ($suite === 'inscription') {
            $this->app->redirect('/inscriptions/nouveau', ['adherent' => $id]);
        }
        $this->app->redirect('/adherents/' . $id);
    }

    /** Contrôle en direct pendant la saisie (JSON). */
    public function doublons(array $p): void
    {
        $sauf = (int) ($_GET['sauf'] ?? 0) ?: null;
        $c = [];
        foreach (['nom', 'prenom', 'mail', 'telephone1', 'telephone2', 'date_naissance'] as $k) {
            $c[$k] = isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : null;
        }
        $out = [];
        foreach (array_slice((new DuplicateFinder($this->app->pdo))->chercher($c, $sauf), 0, 8) as $t) {
            $f = $t['fiche'];
            $out[] = [
                'id' => $t['id'], 'niveau' => $t['niveau'], 'libelle' => DuplicateFinder::NIVEAUX[$t['niveau']],
                'nom' => trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? '')),
                'naissance' => dfr($f['date_naissance'] ?? null), 'raisons' => $t['raisons'],
                'url' => url('/adherents/' . $t['id']),
            ];
        }
        $this->app->json($out);
    }

    /** @param array<string,mixed> $d @param array<string,mixed> $post */
    private function pourForm(array $d, array $post): array
    {
        // Après une erreur, on réaffiche ce que la personne a tapé, tel quel.
        foreach (['nom', 'prenom', 'mail', 'nom_parent', 'prenom_parent', 'adresse', 'code_postal', 'ville', 'date_naissance', 'genre', 'telephone1', 'telephone2', 'remarques'] as $k) {
            $d[$k] = is_string($post[$k] ?? null) ? $post[$k] : '';
        }
        return $d;
    }

    /** @param array<string,mixed> $a fiche en base @param array<string,mixed> $d valeurs du formulaire */
    private function afficher(array $a, array $d, array $err, int $status = 200): string
    {
        $id = (int) $a['id'];
        $onglet = in_array($_GET['onglet'] ?? '', ['inscriptions', 'reglements'], true) ? $_GET['onglet'] : 'adherent';
        $proches = (new DuplicateFinder($this->app->pdo))->chercher($a, $id);
        return $this->app->render('adherents/fiche', [
            'a' => $a, 'd' => $d, 'err' => $err, 'onglet_fiche' => $onglet, 'proches' => $proches,
            'inscriptions' => (new Inscriptions($this->app->pdo))->deAdherent($id),
            'reglements' => (new Reglements($this->app->pdo))->deAdherent($id),
        ], trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? '')) ?: 'Fiche sans nom', 'accueil', $status);
    }
}
