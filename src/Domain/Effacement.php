<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Db;
use Ascs\Schema;
use PDO;

/**
 * Remise à zéro avant la bascule : efface toutes les données (adhérents, inscriptions, règlements, comptes,
 * HelloAsso, demandes en ligne, mailings, présences, remises, fusions…) pour repartir d'un import WinDev propre.
 * Garde les comptes du bureau, les réglages et les tables de référence fixes. Une sauvegarde complète
 * (fichier SQL compressé) est écrite dans storage/sauvegardes juste avant.
 */
final class Effacement
{
    /** Tables conservées : comptes du bureau, réglages, listes fixes, historique des imports. */
    public const GARDEES = ['utilisateur', 'tentative_connexion', 'parametre', 'canal', 'mode_reglement', 'import_run', 'section_en_ligne', 'helloasso_section',
        // Référentiel (saisons, sections, créneaux…) : remplacé par l'import WinDev, gardé pour que les liens des animateurs restent les mêmes
        'saison', 'famille_section', 'section_principale', 'section', 'creneau', 'type_compte', 'animateur', 'section_carte', 'controleur'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function dossier(): string
    {
        return dirname(__DIR__, 2) . '/storage/sauvegardes';
    }

    /** @return list<string> tables effacées, enfants avant parents */
    public static function tables(): array
    {
        return array_values(array_diff(array_reverse(array_keys(Schema::tables())), self::GARDEES));
    }

    /** Nombre de lignes de chaque table qui sera effacée. @return array<string,int> */
    public function comptes(): array
    {
        $out = [];
        foreach (self::tables() as $t) {
            $out[$t] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q($t))->fetchColumn();
        }
        return $out;
    }

    /** Sauvegarde de toute la base (y compris les tables gardées) en INSERT SQL compressés. Renvoie le nom du fichier. */
    public function sauvegarder(): string
    {
        $dir = self::dossier();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Impossible de créer storage/sauvegardes (droits d\'écriture ?).');
        }
        $nom = 'avant-effacement-' . date('Y-m-d-His') . '.sql.gz';
        $gz = gzopen($dir . '/' . $nom, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Impossible d\'écrire la sauvegarde.');
        }
        gzwrite($gz, '-- Sauvegarde ASCS du ' . date('d/m/Y H:i:s') . " avant effacement des données\n-- Restauration : importer ce fichier dans phpMyAdmin (base vidée au préalable).\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach (array_keys(Schema::tables()) as $t) {
            gzwrite($gz, "\n-- $t\n");
            $st = $this->pdo->query('SELECT * FROM ' . $this->q($t));
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $this->pdo->quote((string) $v)), $r);
                gzwrite($gz, 'INSERT INTO `' . $t . '` (`' . implode('`, `', array_keys($r)) . '`) VALUES (' . implode(', ', $vals) . ");\n");
            }
        }
        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        return $nom;
    }

    /** Efface tout (dans une transaction). @return array<string,int> lignes effacées par table */
    public function effacer(): array
    {
        $mysql = Db::driver($this->pdo) === 'mysql';
        $avant = $this->comptes();
        if ($mysql) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        $this->pdo->beginTransaction();
        try {
            foreach (self::tables() as $t) {
                $this->pdo->exec('DELETE FROM ' . $this->q($t));
            }
            $this->pdo->prepare('DELETE FROM parametre WHERE cle IN (?, ?)')->execute(['helloasso_derniere_sync', 'banque_noms']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            if ($mysql) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        foreach (['sms', 'import'] as $d) {
            self::viderDossier(dirname(__DIR__, 2) . '/storage/' . $d);
        }
        return array_filter($avant);
    }

    /** Sauvegardes présentes, la plus récente d'abord. @return list<array{nom:string,taille:int,date:int}> */
    public static function sauvegardes(): array
    {
        $out = [];
        foreach (glob(self::dossier() . '/*.sql.gz') ?: [] as $f) {
            $out[] = ['nom' => basename($f), 'taille' => (int) filesize($f), 'date' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['date'] <=> $a['date']);
        return $out;
    }

    private static function viderDossier(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    private function q(string $t): string
    {
        return Db::driver($this->pdo) === 'mysql' ? '`' . $t . '`' : '"' . $t . '"';
    }
}
