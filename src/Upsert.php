<?php
declare(strict_types=1);

namespace Ascs;

use PDOStatement;

/**
 * Requête « insérer ou mettre à jour » préparée avec des paramètres nommés (:colonne).
 * Accepte les valeurs par nom (['id' => 3, …]) ou dans l'ordre des colonnes ([3, …]) :
 * MariaDB refuse un tableau numéroté pour des paramètres nommés, SQLite l'accepte. On convertit toujours.
 */
final class Upsert
{
    /** @param list<string> $columns */
    public function __construct(private readonly PDOStatement $stmt, private readonly array $columns)
    {
    }

    public function execute(array $values): bool
    {
        if (array_is_list($values)) {
            if (count($values) !== count($this->columns)) {
                throw new \InvalidArgumentException('Upsert : ' . count($values) . ' valeur(s) pour ' . count($this->columns) . ' colonne(s).');
            }
            $values = array_combine($this->columns, $values);
        }
        $named = [];
        foreach ($this->columns as $c) {
            if (!array_key_exists($c, $values)) {
                throw new \InvalidArgumentException("Upsert : valeur manquante pour « $c ».");
            }
            $named[':' . $c] = $values[$c];
        }
        return $this->stmt->execute($named);
    }
}
