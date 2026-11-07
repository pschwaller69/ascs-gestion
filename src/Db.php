<?php
declare(strict_types=1);

namespace Ascs;

use PDO;

final class Db
{
    public static function connect(?array $cfg = null): PDO
    {
        $cfg ??= Config::load();
        if ($cfg['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $cfg['sqlite_path']);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['name']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass']);
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    public static function driver(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'sqlite' : 'mysql';
    }

    /**
     * Prépare un INSERT ... ON DUPLICATE KEY UPDATE (MariaDB) / ON CONFLICT DO UPDATE (SQLite).
     * Les colonnes de $noUpdate sont écrites à l'insertion mais jamais écrasées ensuite.
     *
     * @param list<string> $columns
     * @param list<string> $keys
     * @param list<string> $noUpdate
     */
    public static function upsertStatement(PDO $pdo, string $table, array $columns, array $keys, array $noUpdate = []): Upsert
    {
        $q = static fn (string $c): string => '`' . $c . '`';
        $cols = implode(',', array_map($q, $columns));
        $marks = implode(',', array_map(static fn ($c) => ':' . $c, $columns));
        $upd = array_values(array_diff($columns, $keys, $noUpdate));

        if (self::driver($pdo) === 'sqlite') {
            $set = implode(',', array_map(static fn ($c) => $q($c) . '=excluded.' . $q($c), $upd));
            $conflict = implode(',', array_map($q, $keys));
            $sql = "INSERT INTO {$q($table)} ($cols) VALUES ($marks) ON CONFLICT ($conflict) DO UPDATE SET $set";
        } else {
            $set = implode(',', array_map(static fn ($c) => $q($c) . '=VALUES(' . $q($c) . ')', $upd));
            $sql = "INSERT INTO {$q($table)} ($cols) VALUES ($marks) ON DUPLICATE KEY UPDATE $set";
        }
        return new Upsert($pdo->prepare($sql), array_values($columns));
    }
}
