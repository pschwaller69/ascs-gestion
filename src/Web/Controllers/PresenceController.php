<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Input;
use Ascs\Domain\Presences;
use Ascs\Web\App;

/** Liste de présence des animateurs : page publique ouverte par le lien secret de la section. */
final class PresenceController
{
    private Presences $pr;

    public function __construct(private readonly App $app)
    {
        $this->pr = new Presences($app->pdo);
    }

    private function contexte(array $p): ?array
    {
        $s = $this->pr->parJeton((string) $p['jeton']);
        $saison = $this->pr->saisonCourante();
        return $s && $saison ? [$s, $saison] : null;
    }

    public function liste(array $p): string
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        $c = $this->contexte($p);
        if (!$c) {
            return $this->app->renderPublic('presence/inconnu', [], 'Lien inconnu', 404);
        }
        [$s, $saison] = $c;
        $date = Input::date($_GET, 'date') ?? date('Y-m-d');
        if ($date < (string) $saison['date_debut'] || ($saison['date_fin'] && $date > (string) $saison['date_fin'])) {
            $date = date('Y-m-d');
        }
        $sid = (int) $s['id'];
        $said = (int) $saison['id'];
        return $this->app->renderPublic('presence/liste', [
            's' => $s, 'saison' => $saison, 'date' => $date, 'jeton' => $p['jeton'],
            'inscrits' => $this->pr->inscrits($sid, $said), 'creneaux' => $this->pr->creneaux($sid),
            'notee' => $this->pr->seance($sid, $said, $date), 'seances' => array_slice($this->pr->seances($sid, $said), 0, 12),
            'enregistre' => isset($_GET['ok']),
        ], 'Présences ' . $s['titre']);
    }

    public function enregistrer(array $p): void
    {
        $c = $this->contexte($p);
        if (!$c) {
            http_response_code(404);
            exit;
        }
        [$s, $saison] = $c;
        $date = Input::date($_POST, 'date') ?? date('Y-m-d');
        if ($date < (string) $saison['date_debut'] || ($saison['date_fin'] && $date > (string) $saison['date_fin']) || $date > date('Y-m-d', strtotime('+1 day'))) {
            $date = date('Y-m-d');
        }
        $this->pr->enregistrer((int) $s['id'], (int) $saison['id'], $date, (array) ($_POST['present'] ?? []), 'animateur');
        $this->app->redirect('/presence/' . $p['jeton'], ['date' => $date, 'ok' => 1]);
    }
}
