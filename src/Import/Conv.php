<?php
declare(strict_types=1);

namespace Ascs\Import;

/** Conversions des valeurs texte d'un export WinDev vers des valeurs SQL propres. */
final class Conv
{
    /** @var array<string,int> compteur de valeurs rejetées, par type de conversion */
    public static array $rejects = [];

    public static function str(string $v): ?string
    {
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /** Texte multi-lignes : on garde les retours à la ligne (normalisés en \n). */
    public static function text(string $v): ?string
    {
        $v = trim(str_replace("\r\n", "\n", $v));
        return $v === '' ? null : $v;
    }

    public static function int(string $v): ?int
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $v)) {
            return self::reject('int');
        }
        return (int) $v;
    }

    /** Clé étrangère facultative : 0 (convention WinDev pour « aucun ») devient NULL. */
    public static function fk(string $v): ?int
    {
        $i = self::int($v);
        return ($i === null || $i === 0) ? null : $i;
    }

    public static function bool(string $v): int
    {
        return trim($v) === '1' ? 1 : 0;
    }

    /** Décimal -> chaîne normalisée avec point ; null si vide. */
    public static function dec(string $v): ?string
    {
        $v = str_replace(',', '.', trim($v));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return self::reject('dec');
        }
        return $v;
    }

    public static function dec0(string $v): string
    {
        return self::dec($v) ?? '0';
    }

    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return self::reject('date');
    }

    public static function datetime(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4}) (\d{2}):(\d{2})(?::(\d{2}))?$~', $v, $m)
            && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? '00');
        }
        return self::reject('datetime');
    }

    public static function time(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('~^(\d{2}):(\d{2})(?::(\d{2}))?$~', $v, $m)) {
            return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
        }
        return self::reject('time');
    }

    private static function reject(string $kind): null
    {
        self::$rejects[$kind] = (self::$rejects[$kind] ?? 0) + 1;
        return null;
    }
}
