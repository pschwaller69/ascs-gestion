<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Referentiel;
use Ascs\Web\App;

/** Paramétrage : saisons, familles, sections principales, sections (avec leurs créneaux). */
final class ParametrageController
{
    private Referentiel $ref;

    public function __construct(private readonly App $app)
    {
        $this->ref = new Referentiel($app->pdo);
    }

    private function def(array $p): array
    {
        $d = Referentiel::def((string) $p['cle']);
        if (!$d) {
            $this->app->flash('erreur', 'Page de paramétrage inconnue.');
            $this->app->redirect('/');
        }
        return $d;
    }

    private function options(array $def): array
    {
        $o = [];
        foreach ($def['champs'] as $c => $f) {
            if ($f['type'] === 'select') {
                $o[$c] = $this->ref->options($f['source']);
            }
        }
        return $o;
    }

    public function index(array $p): string
    {
        $def = $this->def($p);
        $lignes = $this->ref->liste($def);
        $extra = [];
        if ($def['table'] === 'section') {
            $st = $this->app->pdo->prepare('SELECT section_id, COUNT(*) FROM inscription WHERE saison_id = ? GROUP BY section_id');
            $st->execute([$this->app->saisonId()]);
            $extra['inscrits'] = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
            $extra['creneaux'] = [];
            foreach ($this->app->pdo->query('SELECT section_id, jour, heure_debut FROM creneau ORDER BY jour, ordre')->fetchAll() as $c) {
                $extra['creneaux'][(int) $c['section_id']][] = mb_substr(Referentiel::JOURS[(int) $c['jour']] ?? '?', 0, 3) . ($c['heure_debut'] ? ' ' . substr((string) $c['heure_debut'], 0, 5) : '');
            }
        }
        return $this->app->render('parametrage/index', ['cle' => $p['cle'], 'def' => $def, 'lignes' => $lignes, 'options' => $this->options($def), 'extra' => $extra], $def['titre'], 'parametrage');
    }

    public function nouveau(array $p): string
    {
        $def = $this->def($p);
        $v = array_map(static fn ($f) => $f['type'] === 'bool' ? 0 : '', $def['champs']);
        if ($def['table'] === 'saison') {
            $der = $this->app->pdo->query('SELECT date_debut, date_fin FROM saison ORDER BY date_debut DESC LIMIT 1')->fetch();
            if ($der && $der['date_debut']) {
                $a = (int) substr((string) $der['date_debut'], 0, 4) + 1;
                $v = ['titre' => $a . '-' . ($a + 1), 'date_debut' => $a . '-09-01', 'date_fin' => ($a + 1) . '-08-31'];
            }
        }
        return $this->form($p, $def, null, $v, [], []);
    }

    public function fiche(array $p): string
    {
        $def = $this->def($p);
        $l = $this->ref->trouver($def, (int) $p['id']);
        if (!$l) {
            $this->app->flash('erreur', 'Élément introuvable.');
            $this->app->redirect('/parametrage/' . $p['cle']);
        }
        $cr = $def['table'] === 'section' ? array_map(static fn ($c) => ['jour' => (int) $c['jour'], 'heure' => $c['heure_debut'] ? substr((string) $c['heure_debut'], 0, 5) : ''], $this->ref->creneaux((int) $l['id'])) : [];
        return $this->form($p, $def, $l, $l, [], $cr);
    }

    private function form(array $p, array $def, ?array $l, array $v, array $err, array $creneaux, int $status = 200): string
    {
        return $this->app->render('parametrage/form', [
            'cle' => $p['cle'], 'def' => $def, 'l' => $l, 'v' => $v, 'err' => $err, 'options' => $this->options($def),
            'creneaux' => $creneaux, 'usage' => $l ? $this->ref->usage($def, (int) $l['id']) : [],
        ], $l ? $def['un'] . ' ' . ($l['titre'] ?? '') : 'Nouvelle ' . $def['un'], 'parametrage', $status);
    }

    public function enregistrer(array $p): ?string
    {
        $def = $this->def($p);
        $id = isset($p['id']) ? (int) $p['id'] : null;
        if ($id !== null && !$this->ref->trouver($def, $id)) {
            $this->app->redirect('/parametrage/' . $p['cle']);
        }
        [$v, $err] = $this->ref->nettoyer($def, $_POST, $id);
        $creneaux = array_values(array_filter((array) ($_POST['creneaux'] ?? []), static fn ($c) => is_array($c) && ($c['jour'] ?? '') !== ''));
        if ($err) {
            return $this->form($p, $def, $id ? $this->ref->trouver($def, $id) : null, $_POST, $err, $creneaux, 422);
        }
        $pdo = $this->app->pdo;
        $pdo->beginTransaction();
        $id = $this->ref->enregistrer($def, $v, $id);
        if ($def['table'] === 'section') {
            $this->ref->enregistrerCreneaux($id, $creneaux);
        }
        $pdo->commit();
        $this->app->flash('ok', ucfirst($def['un']) . ' « ' . ($v['titre'] ?? '') . ' » enregistrée.');
        $this->app->redirect('/parametrage/' . $p['cle']);
        return null;
    }

    public function supprimer(array $p): void
    {
        $def = $this->def($p);
        $e = $this->ref->supprimer($def, (int) $p['id']);
        $this->app->flash($e ? 'erreur' : 'ok', $e ?? ucfirst($def['un']) . ' supprimée.');
        $this->app->redirect('/parametrage/' . $p['cle'] . ($e ? '/' . (int) $p['id'] : ''));
    }
}
