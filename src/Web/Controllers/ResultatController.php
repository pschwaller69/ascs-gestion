<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Statistiques;
use Ascs\Web\App;

/** Résultats de la saison par section et par section principale. */
final class ResultatController
{
    public function __construct(private readonly App $app)
    {
    }

    public function sections(array $p): string
    {
        return $this->app->render('resultats/sections', ['lignes' => (new Statistiques($this->app->pdo))->resultatSections($this->app->saisonId())], 'Résultat par section', 'parametrage');
    }

    public function principales(array $p): string
    {
        return $this->app->render('resultats/principales', ['lignes' => (new Statistiques($this->app->pdo))->resultatSectionsPrincipales($this->app->saisonId())], 'Résultat par section principale', 'parametrage');
    }
}
