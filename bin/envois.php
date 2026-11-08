<?php
declare(strict_types=1);

/**
 * Tâche planifiée (cron toutes les 5 minutes) : envoie le lot suivant des mailings et SMS validés par le bureau.
 *   php bin/envois.php
 * Un lot à la fois (40 mails ou 20 SMS) pour respecter la limite horaire du serveur mail.
 */
require dirname(__DIR__) . '/src/autoload.php';

$pdo = Ascs\Db::connect();
try {
    $pdo->query('SELECT 1 FROM campagne LIMIT 1');
} catch (PDOException) {
    exit(0); // tables pas encore créées (la mise à jour se fait à la première page ouverte)
}
$lock = fopen(dirname(__DIR__) . '/storage/envois.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$r = (new Ascs\Domain\Mailing($pdo))->envoyerLot();
if ($r['envoyes'] || $r['echecs']) {
    echo date('Y-m-d H:i:s'), sprintf(" envoyés %d, échecs %d, restants %d\n", $r['envoyes'], $r['echecs'], $r['restants']);
}
