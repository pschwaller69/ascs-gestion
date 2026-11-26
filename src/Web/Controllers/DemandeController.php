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
        // Demande venue d'un lien de réinscription : sa fiche en tête, choisie d'office
        $ri = new \Ascs\Domain\Reinscription($this->app->pdo);
        $lien = $ri->ficheDeLaDemande((int) $d['id']);
        $changements = $lien ? \Ascs\Domain\Reinscription::changements($lien, $d) : [];
        if ($lien && $d['statut'] === 'a_traiter') {
            $candidats = array_values(array_filter($candidats, static fn ($c) => (int) $c['id'] !== (int) $lien['id']));
            array_unshift($candidats, ['id' => (int) $lien['id'], 'niveau' => 'A', 'raisons' => ['lien de réinscription de cette fiche'], 'fiche' => $lien, 'lien' => true]);
        }
        $niveauA = array_values(array_filter($candidats, static fn ($c) => $c['niveau'] === 'A'));
        $post = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
        $choix = (string) ($post['adherent'] ?? ($lien ? $lien['id'] : (count($niveauA) === 1 ? $niveauA[0]['id'] : ($candidats ? '' : 'nouveau'))));
        $fiche = $post ? array_merge(Demandes::ficheDepuis($d), array_intersect_key($post, Adherents::vide())) : Demandes::ficheDepuis($d);
        $inscriptions = [];
        if ($d['inscriptions']) {
            $ids = array_map('intval', json_decode((string) $d['inscriptions'], true) ?: []);
            if ($ids) {
                $inscriptions = $this->app->pdo->query('SELECT i.id, s.titre FROM inscription i JOIN section s ON s.id = i.section_id WHERE i.id IN (' . implode(',', $ids) . ')')->fetchAll();
            }
        }
        return $this->app->render('demandes/fiche', [
            'paiements' => (new \Ascs\HelloAsso\PaiementEnLigne($this->app->pdo))->historique((int) $d['id']),
            'encaisse' => (new \Ascs\HelloAsso\PaiementEnLigne($this->app->pdo))->encaisse((int) $d['id']),
            'd' => $d, 'candidats' => $candidats, 'choix' => $choix, 'fiche' => $fiche, 'err' => $err, 'post' => $post,
            'saisons' => $this->app->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll(),
            'niveaux' => DuplicateFinder::NIVEAUX, 'inscriptions' => $inscriptions, 'lien' => $lien, 'changements' => $changements,
            'carte' => (new \Ascs\Domain\CartesPass($this->app->pdo))->lire((int) $d['id']),
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
        $nr = (int) ($r['reglements'] ?? 0);
        // Coupon sport / culture demandé : devis pré-rempli et procédure envoyés à la personne
        $devis = '';
        if (!empty($_POST['envoyer_devis']) && $r['inscriptions']) {
            $dm = $this->dem->trouver($id);
            $payantes = array_values(array_filter(array_map('intval', $r['inscriptions']), function (int $iid): bool {
                $st = $this->app->pdo->prepare('SELECT montant FROM inscription WHERE id = ?');
                $st->execute([$iid]);
                return (float) $st->fetchColumn() > 0;
            }));
            if ($payantes && $dm && $dm['mail']) {
                $e = (new \Ascs\Documents\DevisCoupon($this->app->pdo))->envoyer($payantes, (string) $dm['mail'], $this->par());
                $devis = $e ? ' Devis coupon NON envoyé : ' . $e : ' Devis coupon et procédure envoyés à ' . $dm['mail'] . '.';
            }
        }
        $this->app->flash($devis !== '' && str_contains($devis, 'NON') ? 'erreur' : 'ok', (!empty($r['essais']) ? 'Cours d\'essai enregistré : il apparaît sur la liste de présence de l\'animateur.' : ($n > 1 ? "$n inscriptions créées." : 'Inscription créée.')) . ($nr ? " $nr règlement(s) HelloAsso (paiement en ligne) enregistré(s)." : '') . $devis);
        $suivante = $this->app->pdo->query("SELECT id FROM demande WHERE statut = 'a_traiter' ORDER BY cree_le LIMIT 1")->fetchColumn();
        if (($_POST['suite'] ?? '') === 'suivante' && $suivante !== false) {
            $this->app->redirect('/demandes/' . $suivante);
        }
        $adh = $this->app->pdo->query('SELECT adherent_id FROM demande WHERE id = ' . $id)->fetchColumn();
        $this->app->redirect('/adherents/' . $adh, ['onglet' => 'inscriptions']);
    }

    /** Photo de la carte PASS'Région jointe à la demande (bureau connecté seulement). */
    public function carte(array $p): string
    {
        $c = (new \Ascs\Domain\CartesPass($this->app->pdo))->lire((int) $p['id']);
        if (!$c || !$c['fichier']) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: ' . $c['mime']);
        header('Content-Disposition: inline; filename="carte-pass-region-' . (int) $p['id'] . '.' . pathinfo($c['fichier'], PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        return (string) file_get_contents($c['fichier']);
    }

    public function refuser(array $p): void
    {
        $ok = $this->dem->refuser((int) $p['id'], $this->par());
        if ($ok) {
            // Demande refusée : la photo et le code de la carte ne sont pas gardés
            (new \Ascs\Domain\CartesPass($this->app->pdo))->supprimer((int) $p['id']);
        }
        $encaisse = (new \Ascs\HelloAsso\PaiementEnLigne($this->app->pdo))->encaisse((int) $p['id']);
        if ($ok && $encaisse > 0) {
            $this->app->flash('erreur', 'Demande refusée, mais elle a été payée en ligne (' . money($encaisse) . ') : la personne n\'est pas remboursée automatiquement. Utilisez « Rembourser sur HelloAsso » ci-dessous.');
            $this->app->redirect('/demandes/' . (int) $p['id']);
        }
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Demande refusée : rien n\'a été créé.' : 'Cette demande n\'est plus à traiter.');
        $this->app->redirect('/demandes');
    }

    /** Remboursement HelloAsso d'une demande payée en ligne et non validée (confirmation obligatoire). */
    public function rembourser(array $p): void
    {
        $id = (int) $p['id'];
        $pel = new \Ascs\HelloAsso\PaiementEnLigne($this->app->pdo);
        $montant = $pel->encaisse($id);
        if (($_POST['confirme'] ?? '') !== number_format($montant, 2, '.', '') || $montant <= 0) {
            $this->app->flash('erreur', 'Remboursement non fait : cochez la confirmation du montant (le montant a peut-être changé, vérifiez).');
            $this->app->redirect('/demandes/' . $id);
        }
        $r = $pel->rembourser($id, $this->par());
        if ($r['erreurs']) {
            $this->app->flash('erreur', ($r['rembourse'] > 0 ? money($r['rembourse']) . ' remboursé(s), mais ' : '') . 'HelloAsso a refusé : ' . implode(' ; ', $r['erreurs'])
                . ' (solde insuffisant si l\'argent a déjà été versé sur le compte : remboursez alors depuis l\'espace HelloAsso).');
        } else {
            $this->app->flash('ok', money($r['rembourse']) . ' remboursé(s) sur HelloAsso, échéances à venir annulées. La personne reçoit un mail de HelloAsso.');
        }
        $this->app->redirect('/demandes/' . $id);
    }

    public function retablir(array $p): void
    {
        $ok = $this->dem->retablir((int) $p['id']);
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Demande remise à traiter.' : 'Action impossible.');
        $this->app->redirect('/demandes/' . (int) $p['id']);
    }
}
