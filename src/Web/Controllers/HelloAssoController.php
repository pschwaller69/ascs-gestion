<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Config;
use Ascs\Domain\Adherents;
use Ascs\Domain\DuplicateFinder;
use Ascs\Domain\Inscriptions;
use Ascs\HelloAsso\Client;
use Ascs\HelloAsso\Sync;
use Ascs\HelloAsso\Validation;
use Ascs\Parametres;
use Ascs\Web\App;

final class HelloAssoController
{
    private Validation $val;

    public function __construct(private readonly App $app)
    {
        $this->val = new Validation($app->pdo);
    }

    private function par(): string
    {
        return (string) ($this->app->user['email'] ?? 'HelloAsso');
    }

    public function index(array $p): string
    {
        $vue = in_array($_GET['vue'] ?? '', ['traites', 'ignores'], true) ? $_GET['vue'] : 'a_traiter';
        $statuts = ['a_traiter' => ['a_traiter'], 'traites' => ['importe', 'deja_present'], 'ignores' => ['ignore']][$vue];
        $cfg = Config::load();
        $derniere = null;
        try {
            $derniere = $this->app->pdo->query('SELECT * FROM helloasso_sync ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        } catch (\PDOException) {
        }
        return $this->app->render('helloasso/index', [
            'vue' => $vue, 'liste' => $this->val->liste($statuts), 'compteurs' => $this->val->compteurs(), 'derniere' => $derniere,
            'configure' => $cfg['ha_client_id'] !== '' && $cfg['ha_client_secret'] !== '' && $cfg['ha_organisation'] !== '',
            'organisation' => $cfg['ha_organisation'], 'webhook' => strlen($cfg['ha_webhook_token']) >= 16,
            'depuis' => (new Sync($this->app->pdo))->depuisParDefaut(),
            'auto' => (new Sync($this->app->pdo))->autoActive(),
            'raisons' => $vue === 'a_traiter' ? $this->raisons($this->val->liste(['a_traiter'])) : [],
        ], 'HelloAsso', 'accueil');
    }

    /** Pourquoi chaque adhésion restante attend une vérification (affiché dans la liste). */
    private function raisons(array $liste): array
    {
        $r = [];
        foreach ($liste as $l) {
            if ($l['choix']) {
                continue;
            }
            $r[(int) $l['id']] = $this->val->propositionAuto($l)['raison'];
        }
        return $r;
    }

    /** Active ou coupe la validation automatique ; à l'activation, traite tout de suite ce qui attend. */
    public function automatique(array $p): void
    {
        $actif = ($_POST['actif'] ?? '') === '1';
        \Ascs\Parametres::set($this->app->pdo, 'helloasso_auto', $actif ? '1' : '0');
        if (!$actif) {
            $this->app->flash('ok', 'Validation automatique coupée : toutes les nouvelles adhésions attendront votre validation.');
            $this->app->redirect('/helloasso');
        }
        $pdo = $this->app->pdo;
        $sync = new Sync($pdo);
        $pdo->beginTransaction();
        try {
            $sync->rapprocher();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $n = $sync->compteur()['auto'];
        $this->app->flash('ok', 'Validation automatique activée' . ($n ? sprintf(' : %d adhésion(s) validée(s) tout de suite.', $n) : '.'));
        $this->app->redirect('/helloasso');
    }

    public function synchroniser(array $p): void
    {
        $depuis = \Ascs\Domain\Input::date($_POST, 'depuis');
        try {
            $client = Client::depuisConfig($this->app->pdo, Config::load());
            @set_time_limit(300);
            $r = (new Sync($this->app->pdo))->executer($client, 'manuel', $depuis);
            $this->app->flash('ok', 'Synchronisation terminée : ' . $r['resume'] . '.');
        } catch (\Throwable $e) {
            $this->app->flash('erreur', 'Synchronisation impossible : ' . $e->getMessage());
        }
        $this->app->redirect('/helloasso');
    }

    public function fiche(array $p, array $err = []): string
    {
        $it = $this->val->article((int) $p['id']);
        if (!$it) {
            return $this->app->render('erreur', ['titre' => 'Adhésion introuvable', 'message' => 'Cette adhésion HelloAsso n\'existe pas.'], 'Introuvable', 'accueil', 404);
        }
        $section = $this->val->sectionProposee($it);
        $candidats = $it['statut'] === 'a_traiter' ? $this->val->candidats($it) : [];
        // Pré-sélection : la seule fiche « très probable », sinon rien (le bureau choisit).
        $niveauA = array_values(array_filter($candidats, static fn ($c) => $c['niveau'] === 'A'));
        $post = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
        $choix = $post['adherent'] ?? (count($niveauA) === 1 ? (string) $niveauA[0]['id'] : ($candidats ? '' : 'nouveau'));
        $fiche = $post ? array_merge(Validation::ficheDepuis($it), array_intersect_key($post, Adherents::vide())) : Validation::ficheDepuis($it);
        return $this->app->render('helloasso/fiche', [
            'it' => $it, 'candidats' => $candidats, 'choix' => $choix, 'fiche' => $fiche, 'err' => $err,
            'sectionId' => (string) ($post['section_id'] ?? $section['id'] ?? ''), 'sectionSource' => $section['source'],
            'saisonId' => (string) ($post['saison_id'] ?? $this->val->saisonProposee($it) ?? ''),
            'montant' => (string) ($post['montant'] ?? number_format((float) Validation::montantInscription($it), 2, ',', '')),
            'sections' => (new Inscriptions($this->app->pdo))->sections(),
            'saisons' => $this->app->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll(),
            'niveaux' => DuplicateFinder::NIVEAUX,
        ], 'Adhésion HelloAsso', 'accueil', $err ? 422 : 200);
    }

    public function valider(array $p): string
    {
        $id = (int) $p['id'];
        $r = $this->val->valider($id, $_POST, $this->par());
        if (!$r['ok']) {
            if (isset($r['erreurs']['_'])) {
                $this->app->flash('erreur', $r['erreurs']['_']);
                $this->app->redirect('/helloasso/' . $id);
            }
            return $this->fiche($p, $r['erreurs']);
        }
        $this->app->flash('ok', $r['message']);
        // Enchaîner sur la suivante à traiter
        $suivante = $this->app->pdo->query("SELECT id FROM helloasso_item WHERE statut = 'a_traiter' ORDER BY date_commande LIMIT 1")->fetchColumn();
        if (($_POST['suite'] ?? '') === 'suivante' && $suivante !== false) {
            $this->app->redirect('/helloasso/' . $suivante);
        }
        $this->app->redirect('/inscriptions/' . $r['inscription_id']);
    }

    public function ignorer(array $p): void
    {
        $ok = $this->val->changerStatut((int) $p['id'], 'a_traiter', 'ignore', $this->par());
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Adhésion ignorée : elle ne sera pas importée.' : 'Cette adhésion n\'est plus à traiter.');
        $this->app->redirect('/helloasso');
    }

    public function retablir(array $p): void
    {
        $ok = $this->val->changerStatut((int) $p['id'], 'ignore', 'a_traiter', $this->par());
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Adhésion remise à traiter.' : 'Action impossible.');
        $this->app->redirect('/helloasso/' . (int) $p['id']);
    }

    /**
     * Notification HelloAsso (« Mon compte > Intégrations et API »).
     * L'URL contient un jeton secret ; le contenu reçu n'est pas utilisé : on relit les données sur l'API.
     */
    public function notification(array $p): void
    {
        $cfg = Config::load();
        $jeton = (string) ($p['jeton'] ?? '');
        if (strlen($cfg['ha_webhook_token']) < 16 || !hash_equals($cfg['ha_webhook_token'], $jeton)) {
            http_response_code(404);
            exit;
        }
        http_response_code(200);
        header('Content-Type: text/plain');
        echo 'ok';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        // HelloAsso envoie plusieurs notifications par commande, à quelques secondes d'intervalle.
        $derniere = Parametres::get($this->app->pdo, 'helloasso_derniere_sync');
        if ($derniere && strtotime($derniere) > time() - 10) {
            exit;
        }
        try {
            @set_time_limit(120);
            (new Sync($this->app->pdo))->executer(Client::depuisConfig($this->app->pdo, $cfg), 'notification');
        } catch (\Throwable $e) {
            error_log('ASCS HelloAsso notification : ' . $e->getMessage());
        }
        exit;
    }
}
