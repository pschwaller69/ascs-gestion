<?php
declare(strict_types=1);

namespace Ascs\Web;

/**
 * Erreurs inattendues : explication en clair, détail technique et journal (storage/logs/erreurs.log)
 * consultable dans Paramétrage > Journal des erreurs. Chaque erreur a une référence pour la retrouver.
 */
final class Erreurs
{
    private const MAX_OCTETS = 1_000_000;

    public static function fichier(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/erreurs.log';
    }

    /** Explication en français courant d'un message technique (base de données, réseau, fichiers). */
    public static function expliquer(string $m): string
    {
        $regles = [
            '/22007|Incorrect (date|datetime)|incorrect datetime value/i' => 'Une date vide ou mal écrite a été refusée par la base de données.',
            '/22001|Data too long/i' => 'Un texte est trop long pour la case prévue dans la base de données.',
            '/22003|Out of range/i' => 'Un nombre est trop grand pour la case prévue dans la base de données.',
            '/23000.*Duplicate|UNIQUE constraint/i' => 'Cet enregistrement existe déjà (doublon refusé par la base de données).',
            '/23000.*foreign key|FOREIGN KEY constraint/i' => 'Cet enregistrement est lié à un autre qui n\'existe pas ou plus (fiche, section, saison…).',
            '/23000|cannot be null|NOT NULL constraint/i' => 'Une information obligatoire manque pour enregistrer.',
            '/42S22|Unknown column|no such column/i' => 'La base de données n\'est pas à jour par rapport au programme (colonne manquante).',
            '/42S02|doesn\'t exist|no such table/i' => 'La base de données n\'est pas à jour par rapport au programme (table manquante).',
            '/HY000.*\[2002\]|\[2002\]|Connection refused|gone away/i' => 'La base de données ne répond pas. Réessayez dans un instant.',
            '/1205|Lock wait timeout|database is locked/i' => 'La base de données était occupée par une autre opération. Réessayez.',
            '/cURL|Could not resolve|timed out|Operation timed out|SSL/i' => 'Le service extérieur (HelloAsso, envoi de mails ou de SMS) n\'a pas répondu.',
            '/Permission denied|failed to open stream|Read-only/i' => 'Le serveur n\'a pas pu lire ou écrire un fichier (droits du dossier storage ?).',
            '/Allowed memory size/i' => 'Le traitement demande trop de mémoire au serveur.',
            '/Maximum execution time/i' => 'Le traitement a pris trop de temps et a été arrêté par le serveur.',
        ];
        foreach ($regles as $re => $txt) {
            if (preg_match($re, $m)) {
                return $txt;
            }
        }
        return 'Une erreur imprévue dans le programme.';
    }

    /**
     * Enregistre l'erreur dans le journal et le journal PHP ; renvoie la fiche affichable.
     * @return array{ref:string,quand:string,page:string,explication:string,message:string,ou:string,type:string,utilisateur:?int}
     */
    public static function consigner(\Throwable $e): array
    {
        $racine = dirname(__DIR__, 2) . '/';
        $f = [
            'ref' => 'E' . date('ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4),
            'quand' => date('Y-m-d H:i:s'),
            'page' => ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            'explication' => self::expliquer($e->getMessage()),
            'message' => mb_substr($e->getMessage(), 0, 1000),
            'ou' => str_replace($racine, '', $e->getFile()) . ':' . $e->getLine(),
            'type' => $e::class,
            'utilisateur' => isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null,
        ];
        // Les 6 premiers appels situés dans le programme (sans les valeurs des arguments, qui peuvent contenir des données personnelles)
        $pile = [];
        foreach ($e->getTrace() as $t) {
            if (isset($t['file']) && !str_contains($t['file'], '/Vendor/')) {
                $pile[] = str_replace($racine, '', $t['file']) . ':' . ($t['line'] ?? '?') . ' ' . ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? '');
            }
            if (count($pile) >= 6) {
                break;
            }
        }
        error_log('ASCS erreur ' . $f['ref'] . ' : ' . $f['message'] . ' @ ' . $f['ou']);
        $fichier = self::fichier();
        if (!is_dir(dirname($fichier))) {
            @mkdir(dirname($fichier), 0700, true);
        }
        if (is_file($fichier) && filesize($fichier) > self::MAX_OCTETS) {
            @rename($fichier, $fichier . '.1');
        }
        @file_put_contents($fichier, json_encode($f + ['pile' => $pile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        return $f;
    }

    /** Dernières erreurs, la plus récente d'abord. @return list<array<string,mixed>> */
    public static function recentes(int $n = 100): array
    {
        $out = [];
        foreach ([self::fichier() . '.1', self::fichier()] as $f) {
            if (is_file($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                    $d = json_decode($l, true);
                    if (is_array($d)) {
                        $out[] = $d;
                    }
                }
            }
        }
        return array_slice(array_reverse($out), 0, $n);
    }
}
