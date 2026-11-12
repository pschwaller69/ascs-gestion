<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Banque;
use Ascs\Web\App;

/** Financier : comptes bancaires, ventilation, import des relevés, contrôles, export pour Excel. */
final class BanqueController
{
    private Banque $b;

    public function __construct(private readonly App $app)
    {
        $this->b = new Banque($app->pdo);
    }

    private function options(): array
    {
        $pdo = $this->app->pdo;
        return [
            'sections' => $pdo->query('SELECT id, titre FROM section_principale ORDER BY titre')->fetchAll(\PDO::FETCH_KEY_PAIR),
            'types' => $pdo->query('SELECT id, libelle FROM type_compte ORDER BY libelle')->fetchAll(\PDO::FETCH_KEY_PAIR),
        ];
    }

    public function index(array $p): string
    {
        $comptes = $this->b->comptes();
        $numero = (string) ($_GET['compte'] ?? ($comptes[0]['numero'] ?? ''));
        $filtre = in_array($_GET['filtre'] ?? '', ['a_ventiler', 'credit', 'debit'], true) ? $_GET['filtre'] : '';
        $q = trim((string) ($_GET['q'] ?? ''));
        $cle = (string) ($_GET['p'] ?? (isset($_GET['toutes']) ? 'toutes' : ''));
        $periodes = $this->b->periodes();
        $note = null;
        if ($cle === 'toutes') {
            $periode = null;
        } else {
            $periode = $cle !== '' ? $this->b->periode($cle) : null;
            if (!$periode) {
                // Par défaut : la saison choisie en haut de page ; si le compte n'y a aucune opération, la dernière saison qui en a.
                $periode = $this->b->periode('s' . $this->app->saisonId());
                if ($periode && $numero !== '' && !$this->b->nbOperations($numero, $periode)) {
                    foreach ($periodes as $pp) {
                        if ($pp['cle'][0] === 's' && $this->b->nbOperations($numero, $pp)) {
                            $note = 'Aucune opération sur ce compte en ' . mb_strtolower($periode['libelle']) . ' : affichage de la ' . mb_strtolower($pp['libelle']) . '.';
                            $periode = $pp;
                            break;
                        }
                    }
                }
            }
        }
        return $this->app->render('banque/index', [
            'comptes' => $comptes, 'numero' => $numero, 'filtre' => $filtre, 'q' => $q, 'periodes' => $periodes, 'periode' => $periode, 'note' => $note,
            'lignes' => $numero !== '' ? $this->b->lignes($numero, $periode, $filtre, $q) : [],
        ], 'Compte bancaire', 'financier');
    }

    public function nommer(array $p): void
    {
        $this->b->nommer((string) ($_POST['numero'] ?? ''), (string) ($_POST['nom'] ?? ''));
        $this->app->redirect('/banque', ['compte' => (string) ($_POST['numero'] ?? '')]);
    }

    public function ligne(array $p): string
    {
        $l = $this->b->ligne((int) $p['id']);
        if (!$l) {
            $this->app->flash('erreur', 'Ligne introuvable.');
            $this->app->redirect('/banque');
        }
        return $this->app->render('banque/ligne', ['l' => $l, 'proposition' => $l['details'] ? null : $this->b->proposition((string) $l['libelle'], (float) $l['valeur']),
            'err' => null, 'saisie' => null] + $this->options(), 'Ligne bancaire', 'financier');
    }

    public function ventiler(array $p): ?string
    {
        $id = (int) $p['id'];
        $lignes = array_values(array_filter((array) ($_POST['v'] ?? []), 'is_array'));
        $err = $this->b->ventiler($id, $lignes, !empty($_POST['virement_interne']), (string) ($_POST['observation'] ?? ''));
        if ($err) {
            $l = $this->b->ligne($id);
            return $this->app->render('banque/ligne', ['l' => $l, 'proposition' => null, 'err' => $err, 'saisie' => $lignes] + $this->options(), 'Ligne bancaire', 'financier', 422);
        }
        $this->app->flash('ok', 'Ventilation enregistrée.' . ($this->app->transition() ? ' Mode essai : le prochain import WinDev remettra la ventilation de WinDev.' : ''));
        $l = $this->b->ligne($id);
        $this->app->redirect('/banque', ['compte' => $l['numero_compte'], 'filtre' => $_POST['retour_filtre'] ?? null]);
        return null;
    }

    /* Import des relevés */

    public function import(array $p): string
    {
        return $this->app->render('banque/import', ['comptes' => $this->b->comptes(), 'apercu' => null, 'numero' => '', 'format' => '', 'erreurs' => []], 'Import d\'un relevé', 'financier');
    }

    public function lire(array $p): string
    {
        $numero = preg_replace('/\D/', '', (string) ($_POST['numero'] ?? '')) ?: preg_replace('/\D/', '', (string) ($_POST['numero_autre'] ?? ''));
        $f = $_FILES['releve'] ?? null;
        if ($numero === '' || !$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || (int) $f['size'] > 5 * 1024 * 1024) {
            $this->app->flash('erreur', 'Choisissez le compte et le fichier du relevé (CSV ou OFX, 5 Mo au plus).');
            $this->app->redirect('/banque/import');
        }
        $lu = Banque::lire((string) file_get_contents((string) $f['tmp_name']));
        $apercu = $this->b->preparer($numero, $lu['lignes']);
        $_SESSION['import_banque'] = ['numero' => $numero, 'lignes' => $lu['lignes'], 'quand' => time()];
        return $this->app->render('banque/import', ['comptes' => $this->b->comptes(), 'apercu' => $apercu, 'numero' => $numero, 'format' => $lu['format'], 'erreurs' => $lu['erreurs']] + $this->options(),
            'Import d\'un relevé', 'financier');
    }

    public function confirmer(array $p): void
    {
        $i = $_SESSION['import_banque'] ?? null;
        unset($_SESSION['import_banque']);
        if (!$i || time() - (int) $i['quand'] > 3600) {
            $this->app->flash('erreur', 'Import expiré : choisissez de nouveau le fichier.');
            $this->app->redirect('/banque/import');
        }
        $r = $this->b->importer((string) $i['numero'], (array) $i['lignes']);
        $this->app->flash('ok', sprintf('%d ligne(s) ajoutée(s), dont %d ventilée(s) automatiquement et %d remise(s) de chèques rapprochée(s) ; %d déjà présente(s).',
            $r['ajoutees'], $r['auto'], $r['remises'], $r['deja']) . ($this->app->transition() ? ' Mode essai : ces lignes disparaîtront au prochain import WinDev.' : '') . ($r['sans_saison'] ? sprintf(' %d ligne(s) hors saison ignorée(s) : créez la saison.', $r['sans_saison']) : ''));
        $this->app->redirect('/banque', ['compte' => $i['numero'], 'filtre' => 'a_ventiler']);
    }

    public function controle(array $p): string
    {
        return $this->app->render('banque/controle', $this->b->controles($this->app->saisonId()), 'Contrôle des comptes', 'financier');
    }

    /**
     * Export pour le comptable. Sans période choisie : la page de choix. Sinon un classeur Excel
     * (Synthèse, Journal de banque, Ventilation, Par catégorie, Par section, À justifier), ou le CSV de ventilation.
     */
    public function export(array $p): string
    {
        $periodes = $this->b->periodes();
        $comptes = $this->b->comptes();
        if (!isset($_GET['periode'])) {
            $defaut = 's' . $this->app->saisonId();
            // Saison précédente proposée d'abord quand la saison en cours vient de commencer : c'est elle que le comptable clôture.
            $cour = $this->b->periode($defaut);
            if ($cour && $cour['du'] > date('Y-m-d', strtotime('-4 months'))) {
                foreach ($periodes as $i => $pp) {
                    if ($pp['cle'] === $defaut && isset($periodes[$i + 1]) && $periodes[$i + 1]['cle'][0] === 's') {
                        $defaut = $periodes[$i + 1]['cle'];
                        break;
                    }
                }
            }
            return $this->app->render('banque/export', ['periodes' => $periodes, 'comptes' => $comptes, 'defaut' => $defaut, 'xlsx' => \Ascs\Documents\Xlsx::disponible(), 'apercu' => null],
                'Export pour le comptable', 'financier');
        }
        $per = $this->b->periode((string) $_GET['periode'], (string) ($_GET['du'] ?? ''), (string) ($_GET['au'] ?? ''));
        if (!$per) {
            $this->app->flash('erreur', 'Période invalide : choisissez une saison, une année, ou deux dates (jj/mm/aaaa).');
            $this->app->redirect('/banque/export');
        }
        $numeros = array_values(array_filter(array_map('strval', (array) ($_GET['comptes'] ?? [])), static fn ($n) => preg_match('/^\d+$/', $n)));
        if (($_GET['format'] ?? '') === 'sections' && \Ascs\Documents\Xlsx::disponible()) {
            [$rs, $saison, $prec, $saisonPrec] = $this->resultatSections($per, true);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="resultat-sections-ascs-' . $saison . '.xlsx"');
            return \Ascs\Documents\ClasseurSections::construire($rs, $saison, $per, $prec, $saisonPrec)->contenu();
        }
        $d = $this->b->comptable($per, $numeros);
        if (($_GET['format'] ?? '') === 'apercu') {
            return $this->app->render('banque/export', ['periodes' => $periodes, 'comptes' => $comptes, 'defaut' => $per['cle'], 'xlsx' => \Ascs\Documents\Xlsx::disponible(), 'apercu' => $d,
                'sections' => $this->resultatSections($per, false), 'choisis' => $numeros, 'du' => $_GET['du'] ?? '', 'au' => $_GET['au'] ?? ''], 'Export pour le comptable', 'financier');
        }
        $nom = 'comptes-ascs-' . $per['du'] . '-au-' . $per['au'];
        if (($_GET['format'] ?? '') === 'csv' || !\Ascs\Documents\Xlsx::disponible()) {
            $out = fopen('php://temp', 'r+');
            fwrite($out, "\xEF\xBB\xBF");
            $nb = static fn ($v) => $v === null ? '' : number_format((float) $v, 2, ',', '');
            fputcsv($out, ['Compte', 'Date', 'Libellé', 'Section principale', 'Catégorie', 'Recette', 'Dépense', 'Observation'], ';', '"', '');
            foreach ($d['ventilation'] as $r) {
                fputcsv($out, csv_sur([$r['compte'], date('d/m/Y', strtotime($r['date'])), $r['libelle'], $r['section'], $r['categorie'],
                    $r['valeur'] > 0 ? $nb($r['valeur']) : '', $r['valeur'] < 0 ? $nb(-$r['valeur']) : '', $r['observation']]), ';', '"', '');
            }
            rewind($out);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nom . '.csv"');
            return (string) stream_get_contents($out);
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nom . '.xlsx"');
        return self::classeur($d)->contenu();
    }

    /**
     * Résultat par section de la saison de la période (et de la saison précédente pour comparer).
     * @return array{0:array,1:string,2:?array,3:string}
     */
    private function resultatSections(array $per, bool $avecPrecedente): array
    {
        $rs = new \Ascs\Domain\ResultatSections($this->app->pdo);
        $sid = preg_match('/^s(\d+)$/', $per['cle'], $m) ? (int) $m[1] : ($this->b->saisonDe($per['du']) ?? $this->app->saisonId());
        $st = $this->app->pdo->prepare('SELECT id, titre FROM saison WHERE date_debut < (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 1');
        $st->execute([$sid]);
        $p = $st->fetch();
        $titre = $this->app->pdo->prepare('SELECT titre FROM saison WHERE id = ?');
        $titre->execute([$sid]);
        $prec = null;
        if ($avecPrecedente && $p && ($pp = $this->b->periode('s' . $p['id']))) {
            $prec = $rs->calculer((int) $p['id'], $pp);
        }
        return [$rs->calculer($sid, $per), (string) $titre->fetchColumn(), $prec, $p ? (string) $p['titre'] : ''];
    }

    /** Frais ASCS retenus par inscription (adhésion, frais de dossier Rillieux / extérieurs) pour une saison. */
    public function fraisAscs(array $p): void
    {
        $sid = (int) ($_POST['saison_id'] ?? 0);
        $n = static fn (string $k) => (float) str_replace([',', ' ', '€'], ['.', '', ''], (string) ($_POST[$k] ?? ''));
        $v = [$n('adhesion'), $n('rillieux'), $n('exterieur')];
        if ($sid <= 0 || min($v) < 0 || max($v) > 500) {
            $this->app->flash('erreur', 'Montants invalides : indiquez des montants entre 0 et 500 €.');
        } else {
            (new \Ascs\Domain\ResultatSections($this->app->pdo))->reglerFrais($sid, ...$v);
            $this->app->flash('ok', 'Frais ASCS enregistrés pour cette saison.');
        }
        $this->app->redirect('/banque/export?' . http_build_query(['periode' => (string) ($_POST['periode'] ?? 's' . $sid), 'format' => 'apercu']) . '#sections');
    }

    private static function classeur(array $d): \Ascs\Documents\Xlsx
    {
        $x = new \Ascs\Documents\Xlsx();
        $eu = static fn ($v) => $v === null ? null : ['v' => (float) $v, 't' => 'euro'];
        $eg = static fn ($v) => $v === null ? null : ['v' => (float) $v, 't' => 'euro_gras'];
        $g = static fn ($v) => ['v' => $v, 't' => 'gras'];
        $dt = static fn ($v) => $v ? ['v' => $v, 't' => 'date'] : null;
        $per = $d['periode'];

        $l = [[$g('ASCS — comptes bancaires'), $g($per['libelle'])], ['Période', $dt($per['du']), $dt($per['au'])], ['Édité le', $dt(date('Y-m-d'))], [],
            [$g('Compte'), $g('Numéro'), $g('Solde d\'ouverture'), $g('Recettes'), $g('Dépenses'), $g('Solde de clôture'), $g('Dernier solde relevé'), $g('Écart'), $g('Opérations')]];
        $t = ['ouverture' => 0.0, 'recettes' => 0.0, 'depenses' => 0.0, 'cloture' => 0.0];
        foreach ($d['comptes'] as $c) {
            $l[] = [$c['nom'], $c['numero'], $eu($c['ouverture']), $eu($c['recettes']), $eu($c['depenses']), $eu($c['cloture']), $eu($c['solde_banque']), $eu($c['ecart']), $c['nb']];
            foreach ($t as $k => $_) {
                $t[$k] += (float) $c[$k];
            }
        }
        $l[] = [$g('Total'), '', $eg($t['ouverture']), $eg($t['recettes']), $eg($t['depenses']), $eg($t['cloture'])];
        $l[] = [];
        $l[] = ['Les recettes et dépenses incluent les virements entre comptes de l\'association (' . number_format($d['internes'], 2, ',', ' ') . ' € au net) ; ils sont exclus des totaux par catégorie.'];
        $l[] = [$g('Résultat par catégorie (hors virements internes)')];
        $l[] = [$g('Catégorie'), $g('Recettes'), $g('Dépenses'), $g('Solde')];
        $tr = $td = 0.0;
        foreach ($d['categories'] as $k => $v) {
            $l[] = [$k, $eu($v['recettes']), $eu($v['depenses']), $eu($v['recettes'] - $v['depenses'])];
            $tr += $v['recettes'];
            $td += $v['depenses'];
        }
        $l[] = [$g('Résultat'), $eg($tr), $eg($td), $eg($tr - $td)];
        if ($d['a_justifier']) {
            $l[] = [];
            $l[] = [count($d['a_justifier']) . ' opération(s) non ventilée(s) : voir la feuille « À justifier ».'];
        }
        $x->feuille('Synthèse', $l, [34, 14, 16, 16, 16, 16, 18, 12, 11], false);

        $l = [['Compte', 'Date', 'Date valeur', 'Libellé', 'Débit', 'Crédit', 'Solde', 'Ventilation', 'Observation', 'Reste à ventiler']];
        foreach ($d['journal'] as $r) {
            $l[] = [$r['compte'], $dt($r['date']), $dt($r['date_valeur']), $r['libelle'], $eu($r['debit']), $eu($r['credit']), $eu($r['solde']), $r['ventilation'], $r['observation'], $eu($r['reste'])];
        }
        $x->feuille('Journal de banque', $l, [11, 11, 11, 50, 13, 13, 14, 40, 40, 14]);

        $l = [['Compte', 'Date', 'Libellé', 'Section principale', 'Catégorie', 'Recette', 'Dépense', 'Montant', 'Observation']];
        foreach ($d['ventilation'] as $r) {
            $l[] = [$r['compte'], $dt($r['date']), $r['libelle'], $r['section'], $r['categorie'], $eu($r['valeur'] > 0 ? $r['valeur'] : null), $eu($r['valeur'] < 0 ? -$r['valeur'] : null), $eu($r['valeur']), $r['observation']];
        }
        $x->feuille('Ventilation', $l, [11, 11, 50, 22, 20, 13, 13, 13, 40]);

        foreach (['categories' => ['Par catégorie', 'Catégorie'], 'sections' => ['Par section', 'Section principale']] as $k => [$titre, $col]) {
            $l = [[$col, 'Recettes', 'Dépenses', 'Solde']];
            foreach ($d[$k] as $n => $v) {
                $l[] = [$n, $eu($v['recettes']), $eu($v['depenses']), $eu($v['recettes'] - $v['depenses'])];
            }
            $x->feuille($titre, $l, [28, 15, 15, 15]);
        }

        $l = [['Compte', 'Date', 'Libellé', 'Montant', 'Reste à ventiler']];
        foreach ($d['a_justifier'] as $r) {
            $l[] = [$r['compte'], $dt($r['date']), $r['libelle'], $eu($r['valeur']), $eu($r['reste'])];
        }
        $x->feuille('À justifier', $l, [11, 11, 60, 14, 16]);
        return $x;
    }
}
