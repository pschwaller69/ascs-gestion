<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Animateurs;
use Ascs\Domain\Changements;
use Ascs\Domain\Input;
use Ascs\Domain\Presences;
use Ascs\Web\App;

/**
 * Liste de présence des animateurs, pages publiques ouvertes par un lien secret, sans mot de passe :
 * - /presence/{jeton} : un cours (lien de la section) ;
 * - /animateur/{jeton} : tous les cours d'un animateur, en onglets (?s=section).
 */
final class PresenceController
{
    private Presences $pr;

    public function __construct(private readonly App $app)
    {
        $this->pr = new Presences($app->pdo);
    }

    /* ---------- Lien d'une section ---------- */

    public function liste(array $p): string
    {
        return $this->page($this->resoudre($p, 'section'));
    }

    public function enregistrer(array $p): void
    {
        $this->sauver($this->resoudre($p, 'section'));
    }

    public function changer(array $p): string
    {
        return $this->pageChanger($this->resoudre($p, 'section'), (int) $p['inscription']);
    }

    public function enregistrerChangement(array $p): void
    {
        $this->sauverChangement($this->resoudre($p, 'section'), (int) $p['inscription']);
    }

    /* ---------- Lien d'un animateur (tous ses cours) ---------- */

    public function animateur(array $p): string
    {
        return $this->page($this->resoudre($p, 'animateur'));
    }

    public function animateurEnregistrer(array $p): void
    {
        $this->sauver($this->resoudre($p, 'animateur'));
    }

    public function animateurChanger(array $p): string
    {
        return $this->pageChanger($this->resoudre($p, 'animateur'), (int) $p['inscription']);
    }

    public function animateurEnregistrerChangement(array $p): void
    {
        $this->sauverChangement($this->resoudre($p, 'animateur'), (int) $p['inscription']);
    }

    /* ---------- Commun ---------- */

    /**
     * Cours affiché, saison, adresse de la page et onglets.
     * @return ?array{s:array,saison:array,base:string,q:array,onglets:list<array>,par:string}
     */
    private function resoudre(array $p, string $type): ?array
    {
        $saison = $this->pr->saisonCourante();
        if (!$saison) {
            return null;
        }
        if ($type === 'section') {
            $s = $this->pr->parJeton((string) $p['jeton']);
            return $s ? ['s' => $s, 'saison' => $saison, 'base' => '/presence/' . $p['jeton'], 'q' => [], 'onglets' => [], 'par' => 'Animateur ' . $s['titre']] : null;
        }
        $an = new Animateurs($this->app->pdo);
        $mail = $an->parJeton((string) $p['jeton']);
        if ($mail === null) {
            return null;
        }
        $onglets = $an->sections($mail, (int) $saison['id']);
        if (!$onglets) {
            return ['s' => null, 'saison' => $saison, 'base' => '/animateur/' . $p['jeton'], 'q' => [], 'onglets' => [], 'par' => $mail];
        }
        $ids = array_map(static fn ($o) => (int) $o['id'], $onglets);
        $voulu = (int) ($_POST['s'] ?? $_GET['s'] ?? 0);
        if (!in_array($voulu, $ids, true)) {
            // Par défaut : le cours qui a lieu aujourd'hui, sinon le premier
            $st = $this->app->pdo->prepare('SELECT section_id FROM creneau WHERE jour = ? AND section_id IN (' . implode(',', $ids) . ') ORDER BY heure_debut LIMIT 1');
            $st->execute([(int) date('N')]);
            $voulu = (int) ($st->fetchColumn() ?: $ids[0]);
        }
        return ['s' => $this->pr->section($voulu), 'saison' => $saison, 'base' => '/animateur/' . $p['jeton'], 'q' => ['s' => $voulu], 'onglets' => $onglets, 'par' => $mail];
    }

    private static function entetes(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }

    private function page(?array $c): string
    {
        self::entetes();
        if (!$c) {
            return $this->app->renderPublic('presence/inconnu', [], 'Lien inconnu', 404);
        }
        if (!$c['s']) {
            return $this->app->renderPublic('presence/inconnu', ['aucunCours' => true], 'Aucun cours', 200);
        }
        $s = $c['s'];
        $saison = $c['saison'];
        $date = Input::date($_GET, 'date') ?? date('Y-m-d');
        if ($date < (string) $saison['date_debut'] || ($saison['date_fin'] && $date > (string) $saison['date_fin'])) {
            $date = date('Y-m-d');
        }
        $sid = (int) $s['id'];
        $said = (int) $saison['id'];
        return $this->app->renderPublic('presence/liste', $c + [
            'date' => $date, 'inscrits' => $this->pr->inscrits($sid, $said), 'creneaux' => $this->pr->creneaux($sid),
            'changeFait' => self::prendre('changement_fait'), 'peutChanger' => (bool) (new Changements($this->app->pdo))->cibles($sid, $said),
            'notee' => $this->pr->seance($sid, $said, $date), 'seances' => array_slice($this->pr->seances($sid, $said), 0, 12),
            'enregistre' => isset($_GET['ok']),
        ], 'Présences ' . $s['titre']);
    }

    private function sauver(?array $c): void
    {
        if (!$c || !$c['s']) {
            http_response_code(404);
            exit;
        }
        $saison = $c['saison'];
        $date = Input::date($_POST, 'date') ?? date('Y-m-d');
        if ($date < (string) $saison['date_debut'] || ($saison['date_fin'] && $date > (string) $saison['date_fin']) || $date > date('Y-m-d', strtotime('+1 day'))) {
            $date = date('Y-m-d');
        }
        $this->pr->enregistrer((int) $c['s']['id'], (int) $saison['id'], $date, (array) ($_POST['present'] ?? []), 'animateur');
        $this->app->redirect($c['base'], $c['q'] + ['date' => $date, 'ok' => 1]);
    }

    private function pageChanger(?array $c, int $inscriptionId): string
    {
        self::entetes();
        if (!$c || !$c['s']) {
            return $this->app->renderPublic('presence/inconnu', [], 'Lien inconnu', 404);
        }
        $ins = null;
        foreach ($this->pr->inscrits((int) $c['s']['id'], (int) $c['saison']['id']) as $i) {
            if ((int) $i['inscription_id'] === $inscriptionId) {
                $ins = $i;
            }
        }
        if (!$ins) {
            $this->app->redirect($c['base'], $c['q']);
        }
        return $this->app->renderPublic('presence/changer', $c + [
            'ins' => $ins, 'erreur' => self::prendre('erreur_changement'),
            'cibles' => (new Changements($this->app->pdo))->cibles((int) $c['s']['id'], (int) $c['saison']['id']), 'creneaux' => $this->pr->creneaux((int) $c['s']['id']),
        ], 'Changer de cours');
    }

    private function sauverChangement(?array $c, int $inscriptionId): void
    {
        if (!$c || !$c['s']) {
            http_response_code(404);
            exit;
        }
        $vers = (int) ($_POST['vers'] ?? 0);
        $err = (new Changements($this->app->pdo))->changer($inscriptionId, (int) $c['s']['id'], $vers, (int) $c['saison']['id'],
            (string) ($_POST['motif'] ?? ''), (string) ($_POST['precision'] ?? ''), $c['par']);
        if ($err) {
            $_SESSION['erreur_changement'] = $err;
            $this->app->redirect($c['base'] . '/changer/' . $inscriptionId, $c['q']);
        }
        $st = $this->app->pdo->prepare('SELECT titre FROM section WHERE id = ?');
        $st->execute([$vers]);
        $_SESSION['changement_fait'] = mb_substr((string) ($_POST['nom'] ?? 'L\'adhérent'), 0, 120) . ' est passé(e) dans le cours ' . $st->fetchColumn() . '. Le bureau en est informé.';
        $this->app->redirect($c['base'], $c['q']);
    }

    private static function prendre(string $k): ?string
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }
}
