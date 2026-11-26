<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\EnLigne;
use Ascs\Domain\Input;
use Ascs\Parametres;
use Ascs\Web\App;

/** Inscriptions en ligne : QR codes et affiches (Accueil), réglages (Paramétrage > En ligne). */
final class EnLigneController
{
    private EnLigne $el;

    public function __construct(private readonly App $app)
    {
        $this->el = new EnLigne($app->pdo);
    }

    /** Accueil > QR codes : état, QR codes et affiches (les réglages sont dans Paramétrage). */
    public function index(array $p): string
    {
        return $this->app->render('enligne/index', [
            'inscriptionOuverte' => $this->el->ouvert('inscription'), 'essaiOuvert' => $this->el->ouvert('essai'),
            'saisonTitre' => $this->el->saisonTitre(),
            'urlInscription' => App::urlAbsolue('/inscription'), 'urlEssai' => App::urlAbsolue('/essai'),
        ], 'Inscriptions en ligne', 'accueil');
    }

    // ------------------------------------------------------------------ Paramétrage > En ligne

    public function general(array $p, array $err = []): string
    {
        $pdo = $this->app->pdo;
        return $this->app->render('enligne/general', [
            'err' => $err, 'inscriptionOuverte' => $this->el->ouvert('inscription'), 'essaiOuvert' => $this->el->ouvert('essai'),
            'essaiAuto' => (new \Ascs\Domain\Demandes($pdo))->essaisAuto(),
            'saisonId' => $this->el->saisonId(), 'message' => (string) $this->el->message(),
            'horaires' => (string) Parametres::get($pdo, 'horaires_bureau'),
            'whatsapp' => (string) ($_POST['whatsapp_lien'] ?? Parametres::get($pdo, 'whatsapp_communaute')),
            'reglement' => (new \Ascs\Domain\Bulletin($pdo))->reglementInterieur(),
            'saisons' => $pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll(),
        ], 'Inscriptions en ligne', 'parametrage');
    }

    public function enregistrerGeneral(array $p): string
    {
        $pdo = $this->app->pdo;
        $saison = Input::int($_POST, 'saison_id');
        if ($saison === null || (int) $pdo->query('SELECT COUNT(*) FROM saison WHERE id = ' . $saison)->fetchColumn() === 0) {
            $this->app->flash('erreur', 'Réglages non enregistrés : choisissez la saison.');
            return $this->general($p, ['saison_id' => 'Choisissez la saison.']);
        }
        $wa = trim((string) ($_POST['whatsapp_lien'] ?? ''));
        if ($wa !== '' && !EnLigne::lienWhatsappValide($wa)) {
            $this->app->flash('erreur', 'Réglages non enregistrés : lien WhatsApp invalide.');
            return $this->general($p, ['whatsapp_lien' => 'Lien invalide : collez le lien d\'invitation (https://chat.whatsapp.com/…).']);
        }
        $pdo->beginTransaction();
        Parametres::set($pdo, 'inscription_ouverte', !empty($_POST['inscription_ouverte']) ? '1' : null);
        Parametres::set($pdo, 'essai_ouvert', !empty($_POST['essai_ouvert']) ? '1' : null);
        Parametres::set($pdo, 'essai_auto', !empty($_POST['essai_auto']) ? '1' : '0');
        Parametres::set($pdo, 'en_ligne_saison', (string) $saison);
        Parametres::set($pdo, 'en_ligne_message', Input::text($_POST, 'message', 1000));
        Parametres::set($pdo, 'horaires_bureau', Input::text($_POST, 'horaires_bureau', 120));
        Parametres::set($pdo, 'whatsapp_communaute', $wa !== '' ? mb_substr($wa, 0, 255) : null);
        $ri = trim(str_replace("\r\n", "\n", (string) ($_POST['reglement_interieur'] ?? '')));
        Parametres::set($pdo, 'reglement_interieur', $ri === trim(\Ascs\Domain\Bulletin::REGLEMENT_DEFAUT) ? '' : mb_substr($ri, 0, 8000));
        $pdo->commit();
        $this->app->flash('ok', 'Réglages enregistrés.');
        $this->app->redirect('/parametres/en-ligne');
    }

    public function activites(array $p, array $err = []): string
    {
        return $this->app->render('enligne/activites', [
            'err' => $err, 'sections' => $this->el->toutesSections(), 'saisonTitre' => $this->el->saisonTitre(),
        ], 'Activités en ligne', 'parametrage');
    }

    public function enregistrerActivites(array $p): string
    {
        $pdo = $this->app->pdo;
        $err = [];
        $sections = (array) ($_POST['s'] ?? []);
        foreach ($sections as $sid => $r) {
            foreach (is_array($r) ? EnLigne::erreursSection($r) : [] as $k => $m) {
                $err[$k . '_' . (int) $sid] = $m;
            }
        }
        if ($err) {
            $this->app->flash('erreur', 'Réglages non enregistrés : ' . implode(' ', array_unique($err)));
            return $this->activites($p, $err);
        }
        $pdo->beginTransaction();
        $existe = $pdo->prepare('SELECT COUNT(*) FROM section WHERE id = ?');
        foreach ($sections as $sid => $r) {
            $existe->execute([(int) $sid]);
            if ((int) $existe->fetchColumn() > 0 && is_array($r)) {
                $this->el->enregistrerSection((int) $sid, $r);
            }
        }
        $pdo->commit();
        $this->app->flash('ok', 'Activités enregistrées.');
        $this->app->redirect('/parametres/activites-en-ligne');
    }

    public function aides(array $p): string
    {
        $b = new \Ascs\Domain\Bulletin($this->app->pdo);
        return $this->app->render('enligne/aides', [
            'aidesDates' => array_map(static fn ($a) => ['cle' => $a['cle'], 'libelle' => $a['libelle'], 'limite' => $b->reglageAide($a['cle'], 'limite'),
                'garantie' => $b->reglageAide($a['cle'], 'garantie'), 'droit' => $b->droitAide($a['cle']),
                'droit_defaut' => \Ascs\Domain\Bulletin::DROITS_DEFAUT[$a['cle']] ?? ''], \Ascs\Domain\Bulletin::aides()),
        ], 'Aides financières', 'parametrage');
    }

    public function enregistrerAides(array $p): void
    {
        $pdo = $this->app->pdo;
        $pdo->beginTransaction();
        foreach (\Ascs\Domain\Bulletin::aides() as $a) {
            foreach (['limite', 'garantie'] as $t) {
                $v = trim((string) ($_POST['aide_' . $t][$a['cle']] ?? ''));
                // jj/mm valide, ou vide (pas de limite / pas de garantie)
                Parametres::set($pdo, 'aide_' . $a['cle'] . '_' . $t, $v === '' || \Ascs\Domain\Bulletin::dateDansSaison($v, 2026) !== null ? $v : '');
            }
            // Texte « qui y a droit » : identique au texte par défaut ou vide -> texte par défaut (suit les mises à jour)
            $droit = trim(str_replace(["\r\n", "\n"], ' ', (string) ($_POST['aide_droit'][$a['cle']] ?? '')));
            Parametres::set($pdo, 'aide_' . $a['cle'] . '_droit', $droit === (\Ascs\Domain\Bulletin::DROITS_DEFAUT[$a['cle']] ?? '') ? '' : mb_substr($droit, 0, 600));
        }
        $pdo->commit();
        $this->app->flash('ok', 'Aides enregistrées.');
        $this->app->redirect('/parametres/aides');
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
