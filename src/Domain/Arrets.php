<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Activités arrêtées : une section principale (toutes ses sections) ou une section seule n'est plus proposée
 * (nouvelle inscription, inscription en ligne, HelloAsso, changement de cours, cartes) mais reste dans l'historique
 * (inscriptions passées, résultats, reporting, mailings aux anciens).
 */
final class Arrets
{
    /** @var array<string,list<int>>|null */
    private static ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{principale:array<int,array>,section:array<int,array>} arrêts par type, avec la saison de départ */
    public function tous(): array
    {
        $out = ['principale' => [], 'section' => []];
        foreach ($this->pdo->query('SELECT a.*, s.titre AS saison FROM section_arret a LEFT JOIN saison s ON s.id = a.depuis_saison_id')->fetchAll() as $r) {
            $out[$r['type']][(int) $r['cible_id']] = $r;
        }
        return $out;
    }

    /** Sections qui ne sont plus proposées (arrêtées elles-mêmes ou par leur section principale). @return list<int> */
    public function sections(): array
    {
        if (self::$cache !== null) {
            return self::$cache['sections'];
        }
        $t = $this->tous();
        $ids = array_keys($t['section']);
        if ($t['principale']) {
            $ids = array_merge($ids, array_map('intval', $this->pdo->query('SELECT id FROM section WHERE section_principale_id IN (' . implode(',', array_keys($t['principale'])) . ')')->fetchAll(PDO::FETCH_COLUMN)));
        }
        self::$cache = ['sections' => array_values(array_unique(array_map('intval', $ids)))];
        return self::$cache['sections'];
    }

    public function estArretee(int $sectionId): bool
    {
        return in_array($sectionId, $this->sections(), true);
    }

    public function arreter(string $type, int $id, ?int $depuisSaisonId): void
    {
        if (!in_array($type, ['principale', 'section'], true)) {
            return;
        }
        self::$cache = null;
        $this->pdo->prepare('DELETE FROM section_arret WHERE type = ? AND cible_id = ?')->execute([$type, $id]);
        $this->pdo->prepare('INSERT INTO section_arret (type, cible_id, depuis_saison_id, arrete_le) VALUES (?, ?, ?, ?)')->execute([$type, $id, $depuisSaisonId, date('Y-m-d H:i:s')]);
        // Plus proposée en ligne non plus
        $ids = $type === 'section' ? [$id] : array_map('intval', $this->pdo->query('SELECT id FROM section WHERE section_principale_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $this->pdo->exec('UPDATE section_en_ligne SET ouvert = 0 WHERE section_id IN (' . implode(',', $ids) . ')');
        }
    }

    public function reprendre(string $type, int $id): void
    {
        self::$cache = null;
        $this->pdo->prepare('DELETE FROM section_arret WHERE type = ? AND cible_id = ?')->execute([$type, $id]);
    }

    /**
     * Sections principales avec le nombre d'inscrits de la saison et de la précédente (pour repérer celles qui n'ont plus personne).
     * @return list<array<string,mixed>>
     */
    public function principales(int $saisonId): array
    {
        $prec = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut < (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 1');
        $prec->execute([$saisonId]);
        $p = (int) $prec->fetchColumn();
        $st = $this->pdo->prepare('SELECT sp.id, sp.titre,
                (SELECT COUNT(*) FROM inscription i JOIN section s ON s.id = i.section_id WHERE s.section_principale_id = sp.id AND i.saison_id = ?) AS n,
                (SELECT COUNT(*) FROM inscription i JOIN section s ON s.id = i.section_id WHERE s.section_principale_id = sp.id AND i.saison_id = ?) AS n_prec
            FROM section_principale sp ORDER BY sp.titre');
        $st->execute([$saisonId, $p]);
        return $st->fetchAll();
    }
}
