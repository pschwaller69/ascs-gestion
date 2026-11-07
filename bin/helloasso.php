<?php
declare(strict_types=1);

// Synchronisation des adhésions HelloAsso (à lancer par une tâche cron toutes les 15 minutes).
//   php bin/helloasso.php                    -> 12 derniers mois (ou la date réglée dans l'application)
//   php bin/helloasso.php --depuis=2024-06-01
// Les adhésions arrivent dans Accueil > HelloAsso, où le bureau les valide.

require __DIR__ . '/../src/autoload.php';

use Ascs\Config;
use Ascs\Db;
use Ascs\HelloAsso\Client;
use Ascs\HelloAsso\Sync;

date_default_timezone_set('Europe/Paris');
$depuis = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--depuis=') && preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($a, 9))) {
        $depuis = substr($a, 9);
    }
}

$cfg = Config::load();
$pdo = Db::connect($cfg);
try {
    $r = (new Sync($pdo))->executer(Client::depuisConfig($pdo, $cfg), 'cron', $depuis);
    echo date('d/m/Y H:i') . ' — ' . $r['resume'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('d/m/Y H:i') . ' — ÉCHEC : ' . $e->getMessage() . "\n");
    exit(1);
}
