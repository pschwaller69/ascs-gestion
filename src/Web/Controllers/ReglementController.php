<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Inscriptions;
use Ascs\Domain\Input;
use Ascs\Domain\Reglements;
use Ascs\Web\App;

final class ReglementController
{
    private Reglements $reg;
    private Inscriptions $insc;

    public function __construct(private readonly App $app)
    {
        $this->reg = new Reglements($app->pdo);
        $this->insc = new Inscriptions($app->pdo);
    }

    private function introuvable(): string
    {
        return $this->app->render('erreur', ['titre' => 'Introuvable', 'message' => 'Ce règlement ou cette inscription n\'existe pas ou a été supprimé.'], 'Introuvable', 'accueil', 404);
    }

    public function index(array $p): string
    {
        $mode = (int) ($_GET['mode'] ?? 0) ?: null;
        $du = Input::date($_GET, 'du');
        $au = Input::date($_GET, 'au');
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $r = $this->reg->lister($this->app->saisonId(), $mode, $du, $au, $q, $page);
        return $this->app->render('reglements/index', [
            'mode' => $mode, 'du' => $du, 'au' => $au, 'q' => $q, 'page' => $page, 'parPage' => Reglements::PAR_PAGE,
            'lignes' => $r['lignes'], 'total' => $r['total'], 'somme' => $r['somme'], 'modes' => $this->reg->modes(),
        ], 'Règlements');
    }

    public function nouveau(array $p): string
    {
        $i = $this->insc->trouver((int) ($_GET['inscription'] ?? 0));
        if (!$i) {
            $this->app->flash('info', 'Ouvrez d\'abord l\'inscription concernée, puis choisissez « Nouveau règlement ».');
            $this->app->redirect('/inscriptions');
        }
        $reste = max(0.0, round((float) $i['montant'] - (float) $i['regle'], 2));
        $d = ['mode_reglement_id' => '', 'montant' => $reste > 0 ? number_format($reste, 2, ',', '') : '', 'date_reglement' => date('Y-m-d'), 'identifiant' => '', 'remarques' => ''];
        return $this->app->render('reglements/form', $this->vars($i, null, $d, []), 'Nouveau règlement');
    }

    public function creer(array $p): string
    {
        $i = $this->insc->trouver((int) ($_POST['inscription_id'] ?? 0));
        if (!$i) {
            return $this->introuvable();
        }
        [$d, $err] = $this->reg->nettoyer($_POST, (int) $i['id']);
        if ($err) {
            return $this->app->render('reglements/form', $this->vars($i, null, $this->brut($_POST), $err), 'Nouveau règlement', 'accueil', 422);
        }
        $this->reg->creer($d);
        $this->apresEnregistrement((int) $i['id'], 'Règlement enregistré.');
        $suite = $_POST['suite'] ?? '';
        $this->app->redirect($suite === 'autre' ? '/reglements/nouveau' : '/inscriptions/' . $i['id'], $suite === 'autre' ? ['inscription' => $i['id']] : []);
    }

    public function fiche(array $p): string
    {
        $r = $this->reg->trouver((int) $p['id']);
        $i = $r ? $this->insc->trouver((int) $r['inscription_id']) : null;
        if (!$r || !$i) {
            return $this->introuvable();
        }
        $d = [
            'mode_reglement_id' => (string) $r['mode_reglement_id'], 'montant' => number_format((float) $r['montant'], 2, ',', ''),
            'date_reglement' => substr((string) $r['date_reglement'], 0, 10), 'identifiant' => (string) $r['identifiant'], 'remarques' => (string) $r['remarques'],
        ];
        return $this->app->render('reglements/form', $this->vars($i, $r, $d, []), 'Règlement');
    }

    public function modifier(array $p): string
    {
        $r = $this->reg->trouver((int) $p['id']);
        $i = $r ? $this->insc->trouver((int) $r['inscription_id']) : null;
        if (!$r || !$i) {
            return $this->introuvable();
        }
        [$d, $err] = $this->reg->nettoyer($_POST, (int) $i['id']);
        if ($err) {
            return $this->app->render('reglements/form', $this->vars($i, $r, $this->brut($_POST), $err), 'Règlement', 'accueil', 422);
        }
        $this->reg->modifier((int) $r['id'], $d);
        $this->apresEnregistrement((int) $i['id'], 'Règlement enregistré.');
        $this->app->redirect('/inscriptions/' . $i['id']);
    }

    public function supprimer(array $p): void
    {
        $r = $this->reg->trouver((int) $p['id']);
        if ($r) {
            $this->reg->supprimer((int) $r['id']);
            $this->app->flash('ok', 'Règlement supprimé.');
            $this->app->redirect('/inscriptions/' . $r['inscription_id']);
        }
        $this->app->redirect('/reglements');
    }

    private function apresEnregistrement(int $inscriptionId, string $message): void
    {
        $this->app->flash('ok', $message);
        $s = $this->reg->solde($inscriptionId);
        if (round($s['regle'] - $s['montant'], 2) > 0) {
            $this->app->flash('avert', 'Attention : le total réglé (' . money($s['regle']) . ') dépasse le montant de l\'inscription (' . money($s['montant']) . ').');
        }
    }

    /** @param array<string,mixed> $post @return array<string,string> */
    private function brut(array $post): array
    {
        $o = [];
        foreach (['mode_reglement_id', 'montant', 'date_reglement', 'identifiant', 'remarques'] as $k) {
            $o[$k] = is_string($post[$k] ?? null) ? $post[$k] : '';
        }
        return $o;
    }

    /** @return array<string,mixed> */
    private function vars(array $i, ?array $r, array $d, array $err): array
    {
        return ['i' => $i, 'r' => $r, 'd' => $d, 'err' => $err, 'modes' => $this->reg->modes(), 'autres' => $this->reg->deInscription((int) $i['id']), 'modeCheque' => Reglements::MODE_CHEQUE,
            'aides' => \Ascs\Domain\Aides::pour($this->app->pdo, (int) $i['id']), 'parMode' => \Ascs\Domain\Aides::parMode($this->app->pdo, (int) $i['id'])];
    }
}
