<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Statistiques;
use Ascs\Web\App;

/** Reporting : adhérents, adhérents par section, courbe d'inscriptions, reporting mairie (avec export CSV). */
final class ReportingController
{
    private Statistiques $st;

    public function __construct(private readonly App $app)
    {
        $this->st = new Statistiques($app->pdo);
    }

    public function adherents(array $p): string
    {
        $sid = $this->app->saisonId();
        $prec = $this->app->pdo->prepare('SELECT id FROM saison WHERE date_debut < (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 1');
        $prec->execute([$sid]);
        $p = $prec->fetchColumn();
        return $this->app->render('reporting/adherents', ['r' => $this->st->adherents($sid), 'avant' => $p ? $this->st->adherents((int) $p) : null], 'Reporting adhérents', 'reporting');
    }

    public function sections(array $p): string
    {
        return $this->app->render('reporting/sections', $this->st->parSectionTroisSaisons($this->app->saisonId()), 'Adhérents par section', 'reporting');
    }

    public function courbe(array $p): string
    {
        return $this->app->render('reporting/courbe', ['series' => $this->st->courbe($this->app->saisonId())], 'Courbe d\'inscriptions', 'reporting');
    }

    public function mairie(array $p): string
    {
        $sid = $this->app->saisonId();
        $lignes = $this->st->mairie($sid);
        $r = $this->st->adherents($sid);
        if (isset($_GET['csv'])) {
            $out = fopen('php://temp', 'r+');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Activité', 'Famille', 'Adhérents', 'Habitants de Rillieux', 'Extérieurs', 'Mineurs', 'dont mineurs de Rillieux', 'Majeurs', 'Femmes', 'Hommes'], ';', '"', '');
            foreach ($lignes as $l) {
                fputcsv($out, csv_sur([$l['activite'], $l['famille'], $l['total'], $l['rillieux'], $l['exterieurs'], $l['mineurs'], $l['mineurs_rillieux'], $l['majeurs'], $l['femmes'], $l['hommes']]), ';', '"', '');
            }
            fputcsv($out, csv_sur(['Total (personnes distinctes)', '', $r['total'] ?? 0, $r['rillieux'] ?? 0, $r['exterieurs'] ?? 0, '', '', '', $r['femmes'] ?? 0, $r['hommes'] ?? 0]), ';', '"', '');
            rewind($out);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reporting-mairie-' . preg_replace('/[^0-9-]/', '', (string) ($r['saison']['titre'] ?? '')) . '.csv"');
            return (string) stream_get_contents($out);
        }
        return $this->app->render('reporting/mairie', ['lignes' => $lignes, 'r' => $r], 'Reporting mairie', 'reporting');
    }
}
