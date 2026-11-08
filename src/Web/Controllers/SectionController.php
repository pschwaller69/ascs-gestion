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
        return $this->app->render('sections/index', ['sections' => $this->pr->sections($this->app->saisonId())], 'Inscriptions par section');
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

    /** Envoie le lien à l'animateur de chaque section qui a des inscrits et un mail d'animateur. */
    public function envoyerTous(array $p): void
    {
        $ok = 0;
        $ko = [];
        foreach ($this->pr->sections($this->app->saisonId()) as $l) {
            if (!$l['mail_animateur1'] || $l['essai']) {
                continue;
            }
            [$type, $msg] = $this->envoyer($this->pr->section((int) $l['id']));
            $type === 'ok' ? $ok++ : $ko[] = $l['titre'] . ' (' . $msg . ')';
        }
        $this->app->flash($ko ? 'erreur' : 'ok', sprintf('%d lien(s) envoyé(s).', $ok) . ($ko ? ' Échecs : ' . implode(' ; ', $ko) : ''));
        $this->app->redirect('/sections');
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
}
