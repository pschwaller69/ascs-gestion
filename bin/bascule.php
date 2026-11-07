<?php
declare(strict_types=1);

// Bascule définitive vers l'application web : après cette commande, l'import WinDev est refusé
// (il écraserait les saisies faites dans l'application web).
//   php bin/bascule.php --oui        enregistre la date de bascule
//   php bin/bascule.php --annuler    revient en mode transition (import WinDev à nouveau possible)
//   php bin/bascule.php              affiche l'état

require __DIR__ . '/../src/autoload.php';

use Ascs\Db;
use Ascs\Parametres;

$pdo = Db::connect();
$etat = Parametres::get($pdo, 'bascule');
if (in_array('--oui', $argv, true)) {
    Parametres::set($pdo, 'bascule', date('Y-m-d H:i:s'));
    echo "Bascule enregistrée. L'import WinDev est désormais désactivé.\n";
} elseif (in_array('--annuler', $argv, true)) {
    Parametres::set($pdo, 'bascule', null);
    echo "Retour au mode transition : l'import WinDev est de nouveau possible.\n";
} else {
    echo $etat ? "Bascule effectuée le $etat (import WinDev désactivé).\n" : "Mode transition : WinDev reste la référence, les saisies web seront écrasées au prochain import.\n";
}
