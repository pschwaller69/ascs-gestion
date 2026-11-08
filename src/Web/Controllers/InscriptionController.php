<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Adherents;
use Ascs\Domain\Inscriptions;
use Ascs\Domain\Reglements;
use Ascs\Web\App;

final class InscriptionController
{
    private Inscriptions $insc;
    private Adherents $adh;

    public function __construct(private readonly App $app)
    {
        $this->insc = new Inscriptions($app->pdo);
        $this->adh = new Adherents($app->pdo);
    }

    private function introuvable(): string
    {
        return $this->app->render('erreur', ['titre' => 'Inscription introuvable', 'message' => 'Cette inscription n\'existe pas ou a été supprimée.'], 'Introuvable', 'accueil', 404);
    }

    public function index(array $p): string
    {
        $statut = in_array($_GET['statut'] ?? '', ['impayees', 'soldees'], true) ? $_GET['statut'] : 'toutes';
        $section = (int) ($_GET['section'] ?? 0) ?: null;
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $r = $this->insc->lister($this->app->saisonId(), $statut, $section, $q, $page);
        return $this->app->render('inscriptions/index', [
            'statut' => $statut, 'section' => $section, 'q' => $q, 'page' => $page, 'parPage' => Inscriptions::PAR_PAGE,
            'lignes' => $r['lignes'], 'total' => $r['total'], 'reste' => $r['reste'], 'sections' => $this->insc->sections(),
        ], $statut === 'impayees' ? 'Inscriptions non payées' : 'Inscriptions');
    }

    /** Sans adhérent choisi : recherche de l'adhérent à inscrire. */
    public function nouvelle(array $p): string
    {
        $aid = (int) ($_GET['adherent'] ?? 0);
        $a = $aid ? $this->adh->trouver($aid) : null;
        if (!$a) {
            $q = trim((string) ($_GET['q'] ?? ''));
            $r = $q === '' ? ['lignes' => []] : $this->adh->lister($q, false, $this->app->saisonId(), 1);
            return $this->app->render('inscriptions/choix', ['q' => $q, 'lignes' => $r['lignes']], 'Nouvelle inscription');
        }
        $d = [
            'section_id' => (string) ($_GET['section'] ?? ''), 'saison_id' => (string) $this->app->saisonId(),
            'date_inscription' => date('Y-m-d'), 'fait_a' => 'Rillieux-la-Pape', 'montant' => '', 'remarques' => '',
        ];
        return $this->app->render('inscriptions/nouvelle', ['a' => $a, 'd' => $d, 'err' => [], 'sections' => $this->insc->sections(), 'saisons' => $this->saisons()], 'Nouvelle inscription');
    }

    public function creer(array $p): string
    {
        $aid = (int) ($_POST['adherent_id'] ?? 0);
        $a = $this->adh->trouver($aid);
        if (!$a) {
            $this->app->redirect('/inscriptions/nouveau');
        }
        [$d, $err] = $this->insc->nettoyer($_POST, $aid);
        if ($err) {
            return $this->app->render('inscriptions/nouvelle', ['a' => $a, 'd' => $this->brut($_POST), 'err' => $err, 'sections' => $this->insc->sections(), 'saisons' => $this->saisons()], 'Nouvelle inscription', 'accueil', 422);
        }
        $id = $this->insc->creer($d);
        $this->app->flash('ok', 'Inscription créée.');
        if (($_POST['suite'] ?? '') === 'reglement') {
            $this->app->redirect('/reglements/nouveau', ['inscription' => $id]);
        }
        $this->app->redirect('/inscriptions/' . $id);
    }

    public function fiche(array $p): string
    {
        $i = $this->insc->trouver((int) $p['id']);
        if (!$i) {
            return $this->introuvable();
        }
        $d = $i;
        $d['montant'] = number_format((float) $i['montant'], 2, ',', '');
        return $this->afficher($i, $d, []);
    }

    public function modifier(array $p): string
    {
        $id = (int) $p['id'];
        $i = $this->insc->trouver($id);
        if (!$i) {
            return $this->introuvable();
        }
        [$d, $err] = $this->insc->nettoyer($_POST, (int) $i['adherent_id'], $id);
        if ($err) {
            return $this->afficher($i, $this->brut($_POST), $err, 422);
        }
        $this->insc->modifier($id, $d);
        $this->app->flash('ok', 'Inscription enregistrée.');
        if (($_POST['suite'] ?? '') === 'reglement') {
            $this->app->redirect('/reglements/nouveau', ['inscription' => $id]);
        }
        $this->app->redirect('/inscriptions/' . $id);
    }

    public function supprimer(array $p): string
    {
        $id = (int) $p['id'];
        $i = $this->insc->trouver($id);
        if (!$i) {
            return $this->introuvable();
        }
        if (empty($_POST['confirme'])) {
            $nb = count((new Reglements($this->app->pdo))->deInscription($id));
            return $this->app->render('inscriptions/suppression', ['i' => $i, 'nbReglements' => $nb], 'Supprimer l\'inscription');
        }
        $this->insc->supprimer($id);
        $this->app->flash('ok', 'Inscription supprimée.');
        $this->app->redirect('/adherents/' . $i['adherent_id'], ['onglet' => 'inscriptions']);
    }

    /** @return list<array<string,mixed>> */
    private function saisons(): array
    {
        return $this->app->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll();
    }

    /** @param array<string,mixed> $post @return array<string,string> */
    private function brut(array $post): array
    {
        $o = [];
        foreach (['section_id', 'saison_id', 'date_inscription', 'fait_a', 'montant', 'remarques'] as $k) {
            $o[$k] = is_string($post[$k] ?? null) ? $post[$k] : '';
        }
        return $o;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $d @param array<string,string> $err */
    private function afficher(array $i, array $d, array $err, int $status = 200): string
    {
        $r = new Reglements($this->app->pdo);
        return $this->app->render('inscriptions/fiche', [
            'i' => $i, 'd' => $d, 'err' => $err, 'sections' => $this->insc->sections(), 'saisons' => $this->saisons(),
            'reglements' => $r->deInscription((int) $i['id']),
            'envois' => (new \Ascs\Mail\Envoi($this->app->pdo))->historique((int) $i['id']),
            'mailFacture' => (string) ($this->adh->trouver((int) $i['adherent_id'])['mail'] ?? ''),
            'messageFacture' => \Ascs\Web\Controllers\FactureController::message($i, $reg = (new \Ascs\Documents\Reglages($this->app->pdo))->tous()),
            'expediteur' => $reg['expediteur'],
        ], 'Inscription ' . trim($i['nom'] . ' ' . $i['prenom']), 'accueil', $status);
    }
}
