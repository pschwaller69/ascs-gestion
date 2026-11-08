<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Aides au paiement : montants proposés à la saisie d'un règlement (toujours modifiables).
 * Mêmes types et références que dans WinDev, pour rester comparables à l'historique :
 * les deux coupons de la mairie sont du type « Coupon Mairie », distingués par la référence ;
 * le Pass Senior est un « Pass Région » de référence « PASS REGION SENIOR ».
 */
final class Aides
{
    public const COUPON_MAIRIE = 6;
    public const PASS_SPORT = 7;
    public const PASS_REGION = 8;

    /**
     * Date d'encaissement prévue, proposée à la saisie : coupons de la mairie au 1er janvier suivant,
     * Pass Sport un mois plus tard, Pass Région et Pass Senior 15 jours plus tard.
     */
    public static function datePrevue(string $cle, ?string $aujourdhui = null): string
    {
        $t = strtotime($aujourdhui ?? date('Y-m-d'));
        return match ($cle) {
            'coupon_sport', 'coupon_culture' => ((int) date('Y', $t) + 1) . '-01-01',
            // un mois plus tard, ramené au dernier jour du mois si besoin (31 janvier -> 28 ou 29 février)
            'pass_sport' => Reglements::moisSuivant(date('Y-m-d', $t)),
            default => date('Y-m-d', strtotime('+15 days', $t)),
        };
    }

    /** @return list<array{cle:string,libelle:string,mode:int,montant:float,reference:string,date:string}> */
    public static function liste(): array
    {
        return array_map(static fn ($a) => $a + ['date' => self::datePrevue($a['cle'])], [
            ['cle' => 'coupon_sport', 'libelle' => 'Coupon sport mairie', 'mode' => self::COUPON_MAIRIE, 'montant' => 48.0, 'reference' => 'COUPON SPORT'],
            ['cle' => 'coupon_culture', 'libelle' => 'Coupon culture mairie', 'mode' => self::COUPON_MAIRIE, 'montant' => 90.0, 'reference' => 'COUPON CULTURE'],
            ['cle' => 'pass_sport', 'libelle' => 'Pass Sport', 'mode' => self::PASS_SPORT, 'montant' => 50.0, 'reference' => ''],
            ['cle' => 'pass_region', 'libelle' => 'Pass Région', 'mode' => self::PASS_REGION, 'montant' => 30.0, 'reference' => ''],
            ['cle' => 'pass_senior', 'libelle' => 'Pass Senior', 'mode' => self::PASS_REGION, 'montant' => 15.0, 'reference' => 'PASS REGION SENIOR'],
        ]);
    }

    /** Famille de la section de l'inscription (Sport, Culture, Musique, Bien-être…). */
    public static function famille(PDO $pdo, int $inscriptionId): ?string
    {
        $st = $pdo->prepare('SELECT f.titre FROM inscription i JOIN section s ON s.id = i.section_id
            LEFT JOIN section_principale sp ON sp.id = s.section_principale_id LEFT JOIN famille_section f ON f.id = sp.famille_section_id
            WHERE i.id = ?');
        $st->execute([$inscriptionId]);
        $f = $st->fetchColumn();
        return $f === false ? null : $f;
    }

    /** Coupon de la mairie qui correspond à l'activité : culture pour les familles Culture et Musique, sport sinon. */
    public static function couponPour(?string $famille): string
    {
        $f = DuplicateFinder::norm($famille);
        return in_array($f, ['culture', 'musique'], true) ? 'coupon_culture' : 'coupon_sport';
    }

    /** Aides à afficher pour une inscription : le coupon qui correspond à l'activité en premier. */
    public static function pour(PDO $pdo, int $inscriptionId): array
    {
        $coupon = self::couponPour(self::famille($pdo, $inscriptionId));
        $aides = self::liste();
        usort($aides, static fn ($a, $b) => ($b['cle'] === $coupon) <=> ($a['cle'] === $coupon));
        foreach ($aides as &$a) {
            $a['conseille'] = $a['cle'] === $coupon;
        }
        return $aides;
    }

    /** Montant et référence proposés quand on choisit le type dans la liste. @return array<int,array{montant:float,reference:string,date:string}> */
    public static function parMode(PDO $pdo, int $inscriptionId): array
    {
        $coupon = self::couponPour(self::famille($pdo, $inscriptionId));
        $out = [];
        foreach (self::liste() as $a) {
            if ($a['cle'] === 'pass_senior' || ($a['mode'] === self::COUPON_MAIRIE && $a['cle'] !== $coupon)) {
                continue;
            }
            $out[$a['mode']] = ['montant' => $a['montant'], 'reference' => $a['reference'], 'date' => $a['date']];
        }
        return $out;
    }
}
