<?php
declare(strict_types=1);

namespace Ascs\Domain;

/**
 * Tarifs : le tarif enregistré sur la section est celui des extérieurs.
 * Les habitants de Rillieux-la-Pape ont 10 € de réduction, sauf pour le jogging et la marche.
 */
final class Tarifs
{
    public const REDUCTION_RILLIEUX = 10.0;

    /** Habitant de Rillieux-la-Pape : code postal 69140 ou ville « Rillieux… » (Crépieux fait partie de la commune). */
    public static function habitantRillieux(?string $ville, ?string $codePostal): bool
    {
        $cp = preg_replace('/\D/', '', (string) $codePostal);
        $v = DuplicateFinder::norm((string) $ville);
        return $cp === '69140' || str_contains($v, 'rillieux') || str_contains($v, 'crepieux');
    }

    /** Sections sans réduction Rillieux : jogging et marche. */
    public static function sansReduction(string $titreSection): bool
    {
        $t = DuplicateFinder::norm($titreSection);
        return str_contains($t, 'jogging') || str_contains($t, 'marche');
    }

    public static function montant(float $tarifSection, string $titreSection, bool $rillieux): float
    {
        if (!$rillieux || $tarifSection <= 0 || self::sansReduction($titreSection)) {
            return $tarifSection;
        }
        return max(0.0, $tarifSection - self::REDUCTION_RILLIEUX);
    }
}
