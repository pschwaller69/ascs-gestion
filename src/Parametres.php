<?php
declare(strict_types=1);

namespace Ascs;

use PDO;

/** Petits réglages stockés en base (ex. date de bascule définitive vers l'application web). */
final class Parametres
{
    public static function get(PDO $pdo, string $cle): ?string
    {
        $st = $pdo->prepare('SELECT valeur FROM `parametre` WHERE cle = ?');
        $st->execute([$cle]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function set(PDO $pdo, string $cle, ?string $valeur): void
    {
        if ($valeur === null) {
            $pdo->prepare('DELETE FROM `parametre` WHERE cle = ?')->execute([$cle]);
            return;
        }
        Db::upsertStatement($pdo, 'parametre', ['cle', 'valeur'], ['cle'])->execute(['cle' => $cle, 'valeur' => $valeur]);
    }
}
