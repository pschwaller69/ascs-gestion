<?php
declare(strict_types=1);

// Synchronisation des contacts Outlook (Microsoft 365), à lancer par une tâche cron toutes les heures.
// Ne fait rien tant que les lignes M365_ du .env ne sont pas remplies. N'affiche rien quand tout va bien.

require __DIR__ . '/../src/autoload.php';

use Ascs\Config;
use Ascs\Contacts\SyncContacts;
use Ascs\Db;

date_default_timezone_set('Europe/Paris');
if (!SyncContacts::configure()) {
    exit(0);
}
$s = new SyncContacts(Db::connect(Config::load()));
$r = $s->executer(null, 1500);
if ($r['erreurs']) {
    fwrite(STDERR, date('d/m/Y H:i') . ' — ' . $s->derniere()['resume'] . "\n");
    exit(1);
}
