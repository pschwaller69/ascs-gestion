<?php
declare(strict_types=1);

// Crée les tables (idempotent : CREATE TABLE IF NOT EXISTS).
//   php bin/migrate.php            -> base définie dans .env
//   php bin/migrate.php --print    -> affiche seulement le SQL MariaDB (pour phpMyAdmin)
// Même chose depuis le navigateur : page /installation.

require __DIR__ . '/../src/autoload.php';

use Ascs\Db;
use Ascs\Migrator;
use Ascs\Schema;

if (in_array('--print', $argv, true)) {
    echo implode(";\n\n", Schema::ddl('mysql')) . ";\n";
    exit(0);
}

echo Migrator::run(Db::connect()) . "\n";
