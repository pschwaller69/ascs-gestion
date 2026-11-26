<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\HelloAsso\PaiementEnLigne;
use Ascs\Web\App;

/**
 * Pages publiques du paiement en ligne d'une inscription (lien signé reçu à la fin du formulaire et par mail).
 * Les boutons de paiement sont de simples liens : la politique de sécurité (form-action 'self')
 * interdirait la redirection vers HelloAsso après l'envoi d'un formulaire.
 */
final class PaiementController
{
    private PaiementEnLigne $pel;

    public function __construct(private readonly App $app)
    {
        $this->pel = new PaiementEnLigne($app->pdo);
    }

    private function demande(array $p): array
    {
        $d = $this->pel->demandeDuJeton((string) ($p['jeton'] ?? ''));
        if (!$d) {
            http_response_code(404);
            echo $this->app->renderPublic('public/paiement', ['etat' => 'inconnu'], 'Paiement', 404);
            exit;
        }
        return $d;
    }

    private function page(array $d, string $etat, ?string $erreur = null, int $status = 200): string
    {
        $montant = PaiementEnLigne::montantDu($d);
        return $this->app->renderPublic('public/paiement', [
            'etat' => $etat, 'd' => $d, 'montant' => $montant, 'erreur' => $erreur, 'paye' => $this->pel->paye((int) $d['id']),
            'jeton' => PaiementEnLigne::jeton((string) $d['reference']),
            'fois' => $montant !== null ? $this->pel->foisPossible($d) : 1,
        ], 'Paiement en ligne', $status);
    }

    public function afficher(array $p): string
    {
        $d = $this->demande($p);
        if ($this->pel->paye((int) $d['id'])) {
            return $this->page($d, 'paye');
        }
        if (!$this->pel->actif() || PaiementEnLigne::montantDu($d) === null) {
            return $this->page($d, 'indisponible');
        }
        return $this->page($d, 'a_payer');
    }

    public function payer(array $p): string
    {
        $d = $this->demande($p);
        if ($this->pel->paye((int) $d['id'])) {
            return $this->page($d, 'paye');
        }
        if (!$this->pel->actif() || PaiementEnLigne::montantDu($d) === null) {
            return $this->page($d, 'indisponible');
        }
        $st = $this->app->pdo->prepare('SELECT COUNT(*) FROM paiement_en_ligne WHERE demande_id = ? AND cree_le >= ?');
        $st->execute([(int) $d['id'], date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $st->fetchColumn() >= 10) {
            return $this->page($d, 'a_payer', 'Trop de tentatives de paiement en une heure. Réessayez plus tard, ou adressez-vous au bureau.', 429);
        }
        $jeton = PaiementEnLigne::jeton((string) $d['reference']);
        try {
            $url = $this->pel->ouvrir($d, (int) ($_GET['fois'] ?? 1), [
                'back' => App::urlAbsolue('/paiement/' . $jeton),
                'retour' => App::urlAbsolue('/paiement/' . $jeton . '/retour'),
                'erreur' => App::urlAbsolue('/paiement/' . $jeton . '/erreur'),
            ]);
        } catch (\Throwable $e) {
            error_log('ASCS paiement en ligne ' . $d['reference'] . ' : ' . $e->getMessage());
            return $this->page($d, 'a_payer', 'Le paiement en ligne n\'a pas pu être ouvert. Réessayez dans quelques minutes, ou réglez auprès du bureau.', 502);
        }
        if (!preg_match('#^https://#i', $url)) {
            return $this->page($d, 'a_payer', 'Le paiement en ligne n\'a pas pu être ouvert. Réessayez plus tard.', 502);
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** Retour de HelloAsso après le paiement : la commande est relue sur l'API (l'adresse seule ne prouve rien). */
    public function retour(array $p): string
    {
        $d = $this->demande($p);
        $id = isset($_GET['checkoutIntentId']) && ctype_digit((string) $_GET['checkoutIntentId']) ? (int) $_GET['checkoutIntentId'] : null;
        try {
            $etat = $this->pel->verifierDemande($d, $id);
        } catch (\Throwable $e) {
            error_log('ASCS paiement en ligne, retour ' . $d['reference'] . ' : ' . $e->getMessage());
            $etat = 'cree';
        }
        return $this->page($d, $etat === 'paye' ? 'paye' : 'verification');
    }

    public function erreur(array $p): string
    {
        $d = $this->demande($p);
        if ($this->pel->paye((int) $d['id'])) {
            return $this->page($d, 'paye');
        }
        return $this->page($d, 'a_payer', 'Le paiement n\'a pas abouti : aucune somme n\'a été prélevée. Vous pouvez réessayer.');
    }
}
