<?php
declare(strict_types=1);

namespace Ascs;

use PDO;

/** Création des tables (idempotente : CREATE TABLE IF NOT EXISTS). Utilisé par bin/migrate.php et la page d'installation. */
final class Migrator
{
    public static function run(PDO $pdo): string
    {
        $driver = Db::driver($pdo);
        foreach (Schema::ddl($driver) as $sql) {
            $pdo->exec($sql);
        }
        // Valeurs de référence (moyens de paiement, canaux) : présentes même avant le premier import WinDev
        $seeds = Schema::seeds();
        $up = Db::upsertStatement($pdo, 'mode_reglement', ['id', 'libelle'], ['id']);
        foreach ($seeds['mode_reglement'] as [$id, $libelle]) {
            $up->execute([$id, $libelle]);
        }
        $up = Db::upsertStatement($pdo, 'canal', ['id', 'code', 'libelle'], ['id']);
        foreach ($seeds['canal'] as [$id, $code, $libelle]) {
            $up->execute([$id, $code, $libelle]);
        }
        return 'ASCS v' . Import\Importer::version() . ' — schéma à jour (' . count(Schema::tables()) . " tables, $driver).";
    }

    /** Nombre de tables du schéma déjà présentes dans la base. */
    public static function tablesPresentes(PDO $pdo): int
    {
        $n = 0;
        foreach (array_keys(Schema::tables()) as $t) {
            try {
                $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
                $n++;
            } catch (\PDOException) {
                // table absente
            }
        }
        return $n;
    }
}
