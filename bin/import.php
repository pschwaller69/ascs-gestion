<?php
declare(strict_types=1);

// Import complet des exports XML WinDev.
//   php bin/import.php <dossier_xml> [--dry-run] [--rapport=fichier.txt]
// Le dossier contient saison.xml, famille_section.xml, section_principale.xml, section.xml, adherent.xml,
// inscription.xml, reglement.xml, type_compte.xml, compte.xml, detail_compte.xml.

require __DIR__ . '/../src/autoload.php';

use Ascs\Db;
use Ascs\Import\Importer;

$dir = null;
$dry = false;
$force = false;
$reportFile = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') {
        $dry = true;
    } elseif ($a === '--force') {
        $force = true;
    } elseif (str_starts_with($a, '--rapport=')) {
        $reportFile = substr($a, 10);
    } else {
        $dir = $a;
    }
}
if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "Usage : php bin/import.php <dossier_xml> [--dry-run] [--force] [--rapport=fichier.txt]\n");
    exit(2);
}

$pdo = Db::connect();
$bascule = \Ascs\Parametres::get($pdo, 'bascule');
if ($bascule && !$dry && !$force) {
    fwrite(STDERR, "IMPORT REFUSÉ : la bascule vers l'application web a eu lieu le $bascule. Un import écraserait les saisies web.\n(--dry-run pour simuler, --force pour passer outre, php bin/bascule.php --annuler pour revenir en mode transition.)\n");
    exit(3);
}
$importer = new Importer($pdo);
try {
    $result = $importer->run($dir, $dry);
} catch (Throwable $e) {
    fwrite(STDERR, "IMPORT ANNULÉ (aucune donnée modifiée) : " . $e->getMessage() . "\n");
    exit(1);
}
if (!$dry) {
    $result['report'] .= \Ascs\Transition::apresImport($pdo);
}
$reportFile ??= dirname(__DIR__) . '/storage/rapport_import_' . date('Ymd_His') . ($dry ? '_simulation' : '') . '.txt';
file_put_contents($reportFile, $result['report']);
echo $result['report'];
echo "\nRapport enregistré : $reportFile\n";
