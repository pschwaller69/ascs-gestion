<?php
declare(strict_types=1);

namespace Ascs\Domain;

/** Lecture et nettoyage des champs de formulaire. */
final class Input
{
    public static function str(array $in, string $key, int $max = 255): ?string
    {
        $v = $in[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], "\n", $v)) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Texte multiligne : conserve les retours à la ligne. */
    public static function text(array $in, string $key, int $max = 5000): ?string
    {
        $v = $in[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim(str_replace(["\r\n", "\r"], "\n", $v));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function bool(array $in, string $key): int
    {
        return !empty($in[$key]) ? 1 : 0;
    }

    public static function int(array $in, string $key): ?int
    {
        $v = $in[$key] ?? null;
        return (is_string($v) || is_int($v)) && preg_match('/^\d+$/', (string) $v) ? (int) $v : null;
    }

    /** Montant saisi à la française ("12,50", "1 200") ou à l'anglaise -> "12.50", sinon null. */
    public static function montant(array $in, string $key): ?string
    {
        $v = $in[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim($v));
        $v = str_replace(',', '.', $v);
        if (!preg_match('/^-?\d{1,8}(\.\d{1,2})?$/', $v)) {
            return null;
        }
        return number_format((float) $v, 2, '.', '');
    }

    /** Date AAAA-MM-JJ valide ou null. */
    public static function date(array $in, string $key): ?string
    {
        $v = $in[$key] ?? null;
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($v) : null;
    }

    /**
     * Motif LIKE « contient » échappé avec « ! » (à utiliser avec ESCAPE '!').
     * Le caractère d'échappement \ n'est pas portable : MariaDB le lit comme un échappement dans la chaîne SQL elle-même.
     */
    public static function like(string $mot): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $mot) . '%';
    }
}
