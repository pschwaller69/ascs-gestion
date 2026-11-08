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
        $toutes = isset($_GET['toutes']);
        return $this->app->render('banque/index', [
            'comptes' => $comptes, 'numero' => $numero, 'filtre' => $filtre, 'q' => $q, 'toutes' => $toutes,
            'lignes' => $numero !== '' ? $this->b->lignes($numero, $toutes ? null : $this->app->saisonId(), $filtre, $q) : [],
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
        if ($this->app->transition()) {
            $this->app->flash('erreur', 'Mode transition : la ventilation se fait dans WinDev (elle serait écrasée au prochain import).');
            $this->app->redirect('/banque/ligne/' . $id);
        }
        $lignes = array_values(array_filter((array) ($_POST['v'] ?? []), 'is_array'));
        $err = $this->b->ventiler($id, $lignes, !empty($_POST['virement_interne']), (string) ($_POST['observation'] ?? ''));
        if ($err) {
            $l = $this->b->ligne($id);
            return $this->app->render('banque/ligne', ['l' => $l, 'proposition' => null, 'err' => $err, 'saisie' => $lignes] + $this->options(), 'Ligne bancaire', 'financier', 422);
        }
        $this->app->flash('ok', 'Ventilation enregistrée.');
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
        if ($this->app->transition()) {
            $this->app->flash('erreur', 'Mode transition : les relevés s\'importent dans WinDev (des lignes ajoutées ici seraient supprimées au prochain import WinDev). L\'aperçu sert à vérifier l\'import avant la bascule.');
            $this->app->redirect('/banque/import');
        }
        $r = $this->b->importer((string) $i['numero'], (array) $i['lignes']);
        $this->app->flash('ok', sprintf('%d ligne(s) ajoutée(s), dont %d ventilée(s) automatiquement et %d remise(s) de chèques rapprochée(s) ; %d déjà présente(s).',
            $r['ajoutees'], $r['auto'], $r['remises'], $r['deja']) . ($r['sans_saison'] ? sprintf(' %d ligne(s) hors saison ignorée(s) : créez la saison.', $r['sans_saison']) : ''));
        $this->app->redirect('/banque', ['compte' => $i['numero'], 'filtre' => 'a_ventiler']);
    }

    public function controle(array $p): string
    {
        return $this->app->render('banque/controle', $this->b->controles($this->app->saisonId()), 'Contrôle des comptes', 'financier');
    }

    /** Export pour Excel : une ligne par ventilation (ou par opération non ventilée), saison choisie. */
    public function export(array $p): string
    {
        $st = $this->app->pdo->prepare('SELECT c.numero_compte, c.date_operation, c.libelle, c.valeur AS montant_ligne, c.observation AS obs_ligne, c.virement_interne,
                sp.titre AS section, t.libelle AS categorie, d.valeur, d.observation
            FROM compte c LEFT JOIN detail_compte d ON d.compte_id = c.id LEFT JOIN section_principale sp ON sp.id = d.section_principale_id LEFT JOIN type_compte t ON t.id = d.type_compte_id
            WHERE c.saison_id = ? ORDER BY c.numero_compte, c.date_operation, c.id, d.id');
        $st->execute([$this->app->saisonId()]);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $nb = static fn ($v) => $v === null ? '' : number_format((float) $v, 2, ',', '');
        fputcsv($out, ['Compte', 'Date', 'Libellé', 'Montant opération', 'Section principale', 'Catégorie', 'Montant ventilé', 'Recette', 'Dépense', 'Observation', 'Virement interne'], ';', '"', '');
        foreach ($st->fetchAll() as $r) {
            $v = $r['valeur'] ?? null;
            fputcsv($out, [$r['numero_compte'], date('d/m/Y', strtotime((string) $r['date_operation'])), $r['libelle'], $nb($r['montant_ligne']), $r['section'], $r['categorie'], $nb($v),
                $v !== null && $v > 0 ? $nb($v) : '', $v !== null && $v < 0 ? $nb(-$v) : '', $r['observation'] ?? $r['obs_ligne'], $r['virement_interne'] ? 'oui' : ''], ';', '"', '');
        }
        rewind($out);
        $s = $this->app->pdo->prepare('SELECT titre FROM saison WHERE id = ?');
        $s->execute([$this->app->saisonId()]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="comptes-ascs-' . preg_replace('/[^0-9-]/', '', (string) $s->fetchColumn()) . '.csv"');
        return (string) stream_get_contents($out);
    }
}
