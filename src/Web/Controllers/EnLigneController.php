<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\EnLigne;
use Ascs\Domain\Input;
use Ascs\Parametres;
use Ascs\Web\App;

/** Réglages des inscriptions en ligne et affiches QR code (bureau). */
final class EnLigneController
{
    private EnLigne $el;

    public function __construct(private readonly App $app)
    {
        $this->el = new EnLigne($app->pdo);
    }

    public function index(array $p, array $err = []): string
    {
        return $this->app->render('enligne/index', [
            'sections' => $this->el->toutesSections(), 'err' => $err,
            'inscriptionOuverte' => $this->el->ouvert('inscription'), 'essaiOuvert' => $this->el->ouvert('essai'),
            'saisonId' => $this->el->saisonId(), 'message' => (string) $this->el->message(),
            'saisons' => $this->app->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll(),
            'urlInscription' => App::urlAbsolue('/inscription'), 'urlEssai' => App::urlAbsolue('/essai'),
        ], 'Inscriptions en ligne', 'accueil');
    }

    public function enregistrer(array $p): string
    {
        $pdo = $this->app->pdo;
        $err = [];
        $saison = Input::int($_POST, 'saison_id');
        if ($saison === null || (int) $pdo->query('SELECT COUNT(*) FROM saison WHERE id = ' . $saison)->fetchColumn() === 0) {
            $err['saison_id'] = 'Choisissez la saison.';
        }
        $sections = (array) ($_POST['s'] ?? []);
        foreach ($sections as $sid => $r) {
            $lien = trim((string) ($r['lien_paiement'] ?? ''));
            if ($lien !== '' && !preg_match('#^https://[^\s"<>]+$#i', $lien)) {
                $err['lien_' . (int) $sid] = 'Lien invalide (il doit commencer par https://).';
            }
        }
        if ($err) {
            $this->app->flash('erreur', 'Réglages non enregistrés : ' . implode(' ', array_unique($err)));
            return $this->index($p, $err);
        }
        $pdo->beginTransaction();
        Parametres::set($pdo, 'inscription_ouverte', !empty($_POST['inscription_ouverte']) ? '1' : null);
        Parametres::set($pdo, 'essai_ouvert', !empty($_POST['essai_ouvert']) ? '1' : null);
        Parametres::set($pdo, 'en_ligne_saison', (string) $saison);
        Parametres::set($pdo, 'en_ligne_message', Input::text($_POST, 'message', 1000));
        $up = \Ascs\Db::upsertStatement($pdo, 'section_en_ligne', ['section_id', 'ouvert', 'description', 'lien_paiement'], ['section_id']);
        $existe = $pdo->prepare('SELECT COUNT(*) FROM section WHERE id = ?');
        foreach ($sections as $sid => $r) {
            $existe->execute([(int) $sid]);
            if ((int) $existe->fetchColumn() === 0 || !is_array($r)) {
                continue;
            }
            $up->execute([(int) $sid, !empty($r['ouvert']) ? 1 : 0, Input::text($r, 'description', 500), Input::str($r, 'lien_paiement', 255)]);
        }
        $pdo->commit();
        $this->app->flash('ok', 'Réglages enregistrés.');
        $this->app->redirect('/en-ligne');
    }

    /** Deux affiches A4 : une pour les inscriptions, une pour les cours d'essai (seulement celles ouvertes). */
    public function affiches(array $p): string
    {
        $affiches = [];
        $seul = $_GET['type'] ?? null;
        if ($seul !== 'essai' && $this->el->ouvert('inscription')) {
            $affiches[] = ['titre' => 'Inscription', 'sous' => 'Saison ' . $this->el->saisonTitre(), 'url' => App::urlAbsolue('/inscription')];
        }
        if ($seul !== 'inscription' && $this->el->ouvert('essai')) {
            $affiches[] = ['titre' => 'Cours d\'essai gratuit', 'sous' => 'Venez essayer une activité', 'url' => App::urlAbsolue('/essai')];
        }
        return $this->app->renderPublic('enligne/affiches', ['affiches' => $affiches], 'Affiches QR code');
    }
}
