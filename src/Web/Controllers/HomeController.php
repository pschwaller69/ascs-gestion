<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Web\App;

final class HomeController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(array $p): string
    {
        $pdo = $this->app->pdo;
        $sid = $this->app->saisonId();
        $one = static function (\PDO $pdo, string $sql, array $args): mixed {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchColumn();
        };
        $stats = [
            'adherents' => (int) $one($pdo, 'SELECT COUNT(DISTINCT adherent_id) FROM inscription WHERE saison_id = ?', [$sid]),
            'inscriptions' => (int) $one($pdo, 'SELECT COUNT(*) FROM inscription WHERE saison_id = ?', [$sid]),
            'montant' => (float) $one($pdo, 'SELECT COALESCE(SUM(montant),0) FROM inscription WHERE saison_id = ?', [$sid]),
            'regle' => (float) $one($pdo, 'SELECT COALESCE(SUM(r.montant),0) FROM reglement r JOIN inscription i ON i.id = r.inscription_id WHERE i.saison_id = ?', [$sid]),
        ];
        $stats['reste'] = $stats['montant'] - $stats['regle'];
        try {
            $stats['helloasso'] = (int) $pdo->query("SELECT COUNT(*) FROM helloasso_item WHERE statut = 'a_traiter'")->fetchColumn();
        } catch (\PDOException) {
            $stats['helloasso'] = 0;
        }
        try {
            $stats['demandes'] = (int) $pdo->query("SELECT COUNT(*) FROM demande WHERE statut = 'a_traiter'")->fetchColumn();
        } catch (\PDOException) {
            $stats['demandes'] = 0;
        }
        return $this->app->render('home', ['stats' => $stats], 'Accueil');
    }
}
