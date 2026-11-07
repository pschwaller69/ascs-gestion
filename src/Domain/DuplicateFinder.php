<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Détection des fiches adhérent en double. Ne fusionne jamais : elle propose.
 *
 * Niveaux (du plus au moins sûr) :
 *  A  très probable : nom/prénom identiques (ou inversés, ou à une lettre près) ET même date de naissance
 *  B  probable      : nom/prénom identiques ou inversés, pas de date contradictoire, ET même mail ou même téléphone
 *  C  à vérifier    : nom/prénom proches ou identiques sans autre élément pour confirmer
 *  D  homonyme      : nom/prénom identiques mais dates de naissance différentes
 * Le mail seul n'est jamais un critère : un mail de parent est partagé par plusieurs enfants.
 */
final class DuplicateFinder
{
    public const NIVEAUX = [
        'A' => 'Très probable',
        'B' => 'Probable',
        'C' => 'À vérifier',
        'D' => 'Homonyme (date de naissance différente)',
    ];

    /** @var array<int,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Minuscules, sans accents, lettres et chiffres uniquement. */
    public static function norm(?string $s): string
    {
        if ($s === null) {
            return '';
        }
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    public static function tel(?string $s): string
    {
        $d = preg_replace('/\D+/', '', (string) $s) ?? '';
        if (str_starts_with($d, '33') && strlen($d) === 11) {
            $d = '0' . substr($d, 2);
        }
        return strlen($d) >= 6 ? $d : '';
    }

    /** Faux si les deux chaînes diffèrent de plus d'une lettre (et si la plus courte a au plus 4 lettres, exige l'égalité). */
    private static function proche(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (min(strlen($a), strlen($b)) <= 4) {
            return false;
        }
        return abs(strlen($a) - strlen($b)) <= 1 && levenshtein($a, $b) <= 1;
    }

    /** @return array<int,array<string,mixed>> */
    private function tous(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            $q = $this->pdo->query('SELECT id, nom, prenom, mail, telephone1, telephone2, date_naissance FROM adherent');
            foreach ($q as $r) {
                $r['_n'] = self::norm($r['nom']);
                $r['_p'] = self::norm($r['prenom']);
                $r['_m'] = mb_strtolower(trim((string) $r['mail']));
                $r['_t'] = array_values(array_filter([self::tel($r['telephone1']), self::tel($r['telephone2'])]));
                $this->cache[(int) $r['id']] = $r;
            }
        }
        return $this->cache;
    }

    /**
     * Fiches ressemblant à la saisie.
     *
     * @param array{nom?:?string,prenom?:?string,mail?:?string,telephone1?:?string,telephone2?:?string,date_naissance?:?string} $c
     * @return list<array{id:int,niveau:string,raisons:list<string>,fiche:array<string,mixed>}>
     */
    public function chercher(array $c, ?int $sauf = null): array
    {
        $n = self::norm($c['nom'] ?? null);
        $p = self::norm($c['prenom'] ?? null);
        if ($n === '' && $p === '') {
            return [];
        }
        $mail = mb_strtolower(trim((string) ($c['mail'] ?? '')));
        $tels = array_values(array_filter([self::tel($c['telephone1'] ?? null), self::tel($c['telephone2'] ?? null)]));
        $dn = $c['date_naissance'] ?? null;

        $res = [];
        foreach ($this->tous() as $id => $r) {
            if ($id === $sauf) {
                continue;
            }
            $raisons = [];
            $identique = $n !== '' && $p !== '' && $n === $r['_n'] && $p === $r['_p'];
            $inverse = !$identique && $n !== '' && $p !== '' && $n === $r['_p'] && $p === $r['_n'];
            $proche = false;
            if (!$identique && !$inverse && $n !== '' && $p !== '' && $r['_n'] !== '' && $r['_p'] !== '') {
                if ($n === $r['_n'] && self::proche($p, $r['_p'])) {
                    $proche = true;
                    $raisons[] = 'même nom, prénom à une lettre près';
                } elseif ($p === $r['_p'] && self::proche($n, $r['_n'])) {
                    $proche = true;
                    $raisons[] = 'même prénom, nom à une lettre près';
                }
            }
            if (!$identique && !$inverse && !$proche) {
                continue;
            }
            if ($identique) {
                $raisons[] = 'même nom et même prénom';
            } elseif ($inverse) {
                $raisons[] = 'nom et prénom inversés';
            }

            $dnFiche = $r['date_naissance'] ? substr((string) $r['date_naissance'], 0, 10) : null;
            $memeDn = $dn && $dnFiche && $dn === $dnFiche;
            $dnDiff = $dn && $dnFiche && $dn !== $dnFiche;
            $memeMail = $mail !== '' && $mail === $r['_m'];
            $memeTel = $tels && array_intersect($tels, $r['_t']);
            if ($memeDn) {
                $raisons[] = 'même date de naissance';
            }
            if ($memeMail) {
                $raisons[] = 'même mail';
            }
            if ($memeTel) {
                $raisons[] = 'même téléphone';
            }

            if ($memeDn) {
                $niveau = 'A';
            } elseif (($identique || $inverse) && !$dnDiff && ($memeMail || $memeTel)) {
                $niveau = 'B';
            } elseif ($identique && $dnDiff) {
                $niveau = 'D';
                $raisons[] = 'date de naissance différente';
            } else {
                $niveau = 'C';
            }
            $res[] = ['id' => $id, 'niveau' => $niveau, 'raisons' => $raisons, 'fiche' => $r];
        }
        usort($res, static fn ($a, $b) => [$a['niveau'], $a['fiche']['_n'], $a['fiche']['_p']] <=> [$b['niveau'], $b['fiche']['_n'], $b['fiche']['_p']]);
        return $res;
    }

    /** Le plus sûr des niveaux trouvés, ou null. */
    public static function pire(array $trouves): ?string
    {
        $niv = array_column($trouves, 'niveau');
        foreach (['A', 'B', 'C', 'D'] as $n) {
            if (in_array($n, $niv, true)) {
                return $n;
            }
        }
        return null;
    }
}
