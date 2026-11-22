<?php
declare(strict_types=1);

/**
 * Tâche planifiée (cron toutes les 5 minutes) : envoie le lot suivant des mailings et SMS validés par le bureau.
 *   php bin/envois.php
 * Un lot à la fois (40 mails ou 20 SMS) pour respecter la limite horaire du serveur mail.
 * Relève ensuite la boîte des mailings (avis de non-remise, désinscriptions par mail) : voir Ascs\Mail\Retours.
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

// Retours des mailings (boîte contact@m.ascs.fr) : une relève toutes les 15 minutes au plus
try {
    $pdo->query('SELECT 1 FROM mail_retour LIMIT 1');
    $der = strtotime((string) Ascs\Parametres::get($pdo, 'retours_releve_le')) ?: 0;
    if (Ascs\Mail\Retours::config() !== null && time() - $der >= 14 * 60) {
        $rr = (new Ascs\Mail\Retours($pdo))->relever();
        if ($rr['lus'] || $rr['erreur']) {
            echo date('Y-m-d H:i:s'), ' ', $rr['erreur'] ?? Ascs\Mail\Retours::bilan($rr), "\n";
        }
    }
} catch (PDOException) {
    // table pas encore créée
}
