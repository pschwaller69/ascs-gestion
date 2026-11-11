<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Documents\Reglages;
use Ascs\Domain\Presences;
use Ascs\Mail\Envoi;
use Ascs\Web\App;

/** Inscrits par section, feuille d'émargement, lien de la liste de présence des animateurs. */
final class SectionController
{
    private Presences $pr;

    public function __construct(private readonly App $app)
    {
        $this->pr = new Presences($app->pdo);
    }

    public function index(array $p): string
    {
        return $this->app->render('sections/index', ['sections' => $this->pr->sections($this->app->saisonId()),
            'animateurs' => (new \Ascs\Domain\Animateurs($this->app->pdo))->liste((int) ($this->pr->saisonCourante()['id'] ?? $this->app->saisonId())),
            'changements' => (new \Ascs\Domain\Changements($this->app->pdo))->liste($this->app->saisonId()),
            'aRelancer' => (new \Ascs\Domain\Participants($this->app->pdo))->compter($this->app->saisonId())], 'Inscriptions par section');
    }

    private function section(array $p): array
    {
        $s = $this->pr->section((int) $p['id']);
        if (!$s) {
            $this->app->flash('erreur', 'Section introuvable.');
            $this->app->redirect('/sections');
        }
        return $s;
    }

    public function fiche(array $p): string
    {
        $s = $this->section($p);
        $sid = $this->app->saisonId();
        return $this->app->render('sections/fiche', [
            's' => $s, 'inscrits' => $this->pr->inscrits((int) $s['id'], $sid), 'creneaux' => $this->pr->creneaux((int) $s['id']),
            'seances' => $this->pr->seances((int) $s['id'], $sid), 'assiduite' => $this->pr->assiduite((int) $s['id'], $sid),
            'lien' => App::urlAbsolue('/presence/' . $s['presence_token']),
            'lienAnimateur' => $s['mail_animateur1'] ? App::urlAbsolue('/animateur/' . (new \Ascs\Domain\Animateurs($this->app->pdo))->jeton((string) $s['mail_animateur1']), ['s' => $s['id']]) : null,
            'saisonCourante' => $sid === (int) ($this->pr->saisonCourante()['id'] ?? 0),
        ], $s['titre']);
    }

    public function emargement(array $p): string
    {
        $s = $this->section($p);
        $sid = $this->app->saisonId();
        $nb = max(4, min(16, (int) ($_GET['colonnes'] ?? 10)));
        $depuis = \Ascs\Domain\Input::date($_GET, 'depuis') ?? date('Y-m-d');
        $dates = $this->pr->prochainesSeances((int) $s['id'], $depuis, $nb);
        $saison = $this->app->pdo->prepare('SELECT titre FROM saison WHERE id = ?');
        $saison->execute([$sid]);
        return $this->app->renderPublic('sections/emargement', [
            's' => $s, 'inscrits' => $this->pr->inscrits((int) $s['id'], $sid), 'creneaux' => $this->pr->creneaux((int) $s['id']),
            'dates' => $dates, 'colonnes' => $nb, 'depuis' => $depuis, 'saison' => (string) $saison->fetchColumn(),
        ], 'Émargement ' . $s['titre']);
    }

    public function nouveauLien(array $p): void
    {
        $s = $this->section($p);
        $this->pr->nouveauJeton((int) $s['id']);
        $this->app->flash('ok', 'Nouveau lien créé : l\'ancien ne fonctionne plus. Envoyez le nouveau à l\'animateur.');
        $this->app->redirect('/sections/' . $s['id']);
    }

    public function envoyerLien(array $p): void
    {
        $s = $this->section($p);
        $this->app->flash(...$this->envoyer($s));
        $this->app->redirect('/sections/' . $s['id']);
    }

    /** Envoie à chaque animateur son lien personnel (tous ses cours sur une page). */
    public function envoyerTous(array $p): void
    {
        $ok = 0;
        $ko = [];
        foreach ((new \Ascs\Domain\Animateurs($this->app->pdo))->liste((int) ($this->pr->saisonCourante()['id'] ?? $this->app->saisonId())) as $a) {
            [$type, $msg] = $this->envoyerAnimateur($a);
            $type === 'ok' ? $ok++ : $ko[] = $a['mail'] . ' (' . $msg . ')';
        }
        $this->app->flash($ko ? 'erreur' : 'ok', sprintf('%d lien(s) envoyé(s) aux animateurs.', $ok) . ($ko ? ' Échecs : ' . implode(' ; ', $ko) : ''));
        $this->app->redirect('/sections');
    }

    public function envoyerLienAnimateur(array $p): void
    {
        $a = $this->animateurPoste();
        $this->app->flash(...$this->envoyerAnimateur($a));
        $this->app->redirect('/sections#animateurs');
    }

    public function nouveauLienAnimateur(array $p): void
    {
        $a = $this->animateurPoste();
        (new \Ascs\Domain\Animateurs($this->app->pdo))->nouveauJeton($a['mail']);
        $this->app->flash('ok', 'Nouveau lien créé pour ' . $a['mail'] . ' : l\'ancien ne fonctionne plus. Envoyez-lui le nouveau.');
        $this->app->redirect('/sections#animateurs');
    }

    private function animateurPoste(): array
    {
        $mail = mb_strtolower(trim((string) ($_POST['mail'] ?? '')));
        foreach ((new \Ascs\Domain\Animateurs($this->app->pdo))->liste((int) ($this->pr->saisonCourante()['id'] ?? $this->app->saisonId())) as $a) {
            if ($a['mail'] === $mail) {
                return $a;
            }
        }
        $this->app->flash('erreur', 'Animateur introuvable.');
        $this->app->redirect('/sections#animateurs');
    }

    /** @return array{0:string,1:string} */
    private function envoyerAnimateur(array $a): array
    {
        $r = (new Reglages($this->app->pdo))->tous();
        $lien = App::urlAbsolue('/animateur/' . $a['jeton']);
        $cours = implode("\n", array_map(static fn ($s) => '  - ' . $s['titre'], $a['sections']));
        $texte = "Bonjour,\n\nVoici votre lien pour la liste de présence de vos cours :\n" . $cours . "\n\n" . $lien
            . "\n\nIl s'ouvre sur téléphone, sans mot de passe. Un onglet par cours : cochez les présents à chaque séance puis enregistrez."
            . " Vous pouvez aussi appeler ou envoyer un SMS à un adhérent, prévenir tout le cours par SMS (un retard, une annulation) et changer un adhérent de cours."
            . "\nCe lien est personnel, merci de ne pas le transmettre.\n\nMerci,\n" . ($r['association'] ?: 'ASCS');
        $e = (new Envoi($this->app->pdo))->envoyer($r['expediteur'], $r['association'] ?: 'ASCS', $a['mail'],
            'Vos listes de présence', $texte, [], null, (string) ($this->app->user['email'] ?? ''));
        return $e ? ['erreur', $e] : ['ok', 'Lien envoyé à ' . $a['mail'] . '.'];
    }

    /** @return array{0:string,1:string} type et message */
    private function envoyer(array $s): array
    {
        if (!$s['mail_animateur1']) {
            return ['erreur', 'Pas de mail d\'animateur pour cette section (Paramétrage > Sections).'];
        }
        $r = (new Reglages($this->app->pdo))->tous();
        $lien = App::urlAbsolue('/presence/' . $s['presence_token']);
        $texte = "Bonjour,\n\nVoici le lien de la liste de présence de la section " . $s['titre'] . " :\n" . $lien
            . "\n\nIl s'ouvre sur téléphone, sans mot de passe : cochez les présents à chaque séance puis enregistrez.\nCe lien est personnel, merci de ne pas le transmettre.\n\nMerci,\n" . ($r['association'] ?: 'ASCS');
        $e = (new Envoi($this->app->pdo))->envoyer($r['expediteur'], $r['association'] ?: 'ASCS', (string) $s['mail_animateur1'],
            'Liste de présence – ' . $s['titre'], $texte, [], null, (string) ($this->app->user['email'] ?? ''));
        return $e ? ['erreur', $e] : ['ok', 'Lien envoyé à ' . $s['mail_animateur1'] . '.'];
    }

    public function changementVu(array $p): void
    {
        (new \Ascs\Domain\Changements($this->app->pdo))->marquerVu((int) $p['id']);
        $this->app->redirect('/sections#changements');
    }

    public function changementAnnuler(array $p): void
    {
        $err = (new \Ascs\Domain\Changements($this->app->pdo))->annuler((int) $p['id']);
        $this->app->flash($err ? 'erreur' : 'ok', $err ?? 'Changement annulé : l\'adhérent est revenu dans son cours d\'origine.');
        $this->app->redirect('/sections#changements');
    }

    /** Personnes venues aux cours sans être inscrites (ajoutées par les animateurs) : à relancer. */
    public function aRelancer(array $p): string
    {
        $tous = isset($_GET['tous']);
        return $this->app->render('sections/a_relancer', [
            'participants' => (new \Ascs\Domain\Participants($this->app->pdo))->aRelancer($this->app->saisonId(), $tous), 'tous' => $tous,
        ], 'Venus sans inscription');
    }

    public function statutParticipant(array $p): void
    {
        (new \Ascs\Domain\Participants($this->app->pdo))->changerStatut((int) $p['id'], (string) ($_POST['statut'] ?? ''));
        $this->app->redirect('/sections/a-relancer', isset($_POST['tous']) ? ['tous' => 1] : []);
    }

    /* ---------- Cartes de séances ---------- */

    public function cartes(array $p): string
    {
        $c = new \Ascs\Domain\Cartes($this->app->pdo);
        $c->detecter();
        $sections = $this->app->pdo->query('SELECT s.id, s.titre, s.montant, s.section_principale_id, s.essai FROM section s ORDER BY s.titre')->fetchAll();
        $porteurs = $c->porteurs($this->app->saisonId());
        $recharges = [];
        foreach ($porteurs as $po) {
            $recharges[(int) $po['inscription_id']] = $c->recharges((int) $po['inscription_id']);
        }
        return $this->app->render('sections/cartes', [
            'reglages' => $c->reglages(), 'sections' => $sections, 'porteurs' => $porteurs, 'recharges' => $recharges,
            'filtre' => (string) ($_GET['filtre'] ?? ''),
        ], 'Cartes de séances');
    }

    public function cartesReglage(array $p): void
    {
        $c = new \Ascs\Domain\Cartes($this->app->pdo);
        $sid = (int) ($_POST['section_id'] ?? 0);
        if (($_POST['action'] ?? '') === 'retirer') {
            $c->retirer($sid);
            $this->app->flash('ok', 'La section n\'est plus gérée à la carte.');
        } elseif ($sid > 0) {
            $c->enregistrer($sid, (int) ($_POST['seances'] ?? 10), (int) ($_POST['cours_id'] ?? 0) ?: null);
            $this->app->flash('ok', 'Réglage de la carte enregistré.');
        }
        $this->app->redirect('/sections/cartes#reglages');
    }

    public function cartesRecharger(array $p): void
    {
        $montant = trim((string) ($_POST['montant'] ?? ''));
        $date = \Ascs\Domain\Input::date($_POST, 'date') ?? date('Y-m-d');
        $err = (new \Ascs\Domain\Cartes($this->app->pdo))->recharger((int) $p['inscription'], (int) ($_POST['seances'] ?? 0),
            $montant === '' ? null : (float) str_replace([' ', ','], ['', '.'], $montant), $date, (string) ($_POST['note'] ?? ''), (string) ($this->app->user['email'] ?? ''));
        $this->app->flash($err ? 'erreur' : 'ok', $err ?? 'Carte ajoutée. Pensez à enregistrer son règlement sur l\'inscription.');
        $this->app->redirect('/sections/cartes#i' . (int) $p['inscription']);
    }

    public function cartesSupprimerRecharge(array $p): void
    {
        (new \Ascs\Domain\Cartes($this->app->pdo))->supprimerRecharge((int) $p['id']);
        $this->app->flash('ok', 'Carte supprimée.');
        $this->app->redirect('/sections/cartes');
    }
}
