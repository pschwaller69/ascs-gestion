<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

final class Inscriptions
{
    public const PAR_PAGE = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Sections proposées au choix : sans les activités arrêtées, sauf $garder (la section actuelle d'une inscription qu'on modifie).
     * $toutes = true pour un filtre de recherche dans l'historique.
     * @return list<array<string,mixed>>
     */
    public function sections(?int $garder = null, bool $toutes = false): array
    {
        $l = $this->pdo->query('SELECT id, titre, montant FROM section ORDER BY titre')->fetchAll();
        if ($toutes) {
            return $l;
        }
        $arretees = (new Arrets($this->pdo))->sections();
        return array_values(array_filter($l, static fn ($s) => (int) $s['id'] === $garder || !in_array((int) $s['id'], $arretees, true)));
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT i.*, s.titre AS section, sa.titre AS saison, a.nom, a.prenom,
                    (SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id) AS regle
             FROM inscription i
             JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id JOIN adherent a ON a.id = i.adherent_id
             WHERE i.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Inscription déjà existante pour ce trio (adhérent, section, saison). */
    public function existante(int $adherent, int $section, int $saison, ?int $sauf = null): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM inscription WHERE adherent_id = ? AND section_id = ? AND saison_id = ?' . ($sauf ? ' AND id <> ?' : ''));
        $st->execute($sauf ? [$adherent, $section, $saison, $sauf] : [$adherent, $section, $saison]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function nettoyer(array $in, int $adherentId, ?int $idCourant = null): array
    {
        $err = [];
        $d = [
            'adherent_id' => $adherentId,
            'section_id' => Input::int($in, 'section_id'),
            'saison_id' => Input::int($in, 'saison_id'),
            'date_inscription' => Input::date($in, 'date_inscription'),
            'fait_a' => Input::str($in, 'fait_a', 60),
            'montant' => Input::montant($in, 'montant'),
            'remarques' => Input::text($in, 'remarques'),
        ];
        if (Input::str($in, 'date_inscription', 10) !== null && $d['date_inscription'] === null) {
            $err['date_inscription'] = 'Date invalide.';
        }
        $ok = fn (string $t, ?int $id) => $id !== null && (int) $this->pdo->query("SELECT COUNT(*) FROM $t WHERE id = " . (int) $id)->fetchColumn() > 0;
        if (!$ok('section', $d['section_id'])) {
            $err['section_id'] = 'Choisissez une section.';
        }
        if (!$ok('saison', $d['saison_id'])) {
            $err['saison_id'] = 'Choisissez une saison.';
        }
        if ($d['montant'] === null) {
            $err['montant'] = 'Montant invalide (exemple : 120 ou 120,50).';
        } elseif ((float) $d['montant'] < 0) {
            $err['montant'] = 'Le montant ne peut pas être négatif.';
        }
        if (!isset($err['section_id']) && $d['section_id'] !== null && (new Arrets($this->pdo))->estArretee((int) $d['section_id'])) {
            $avant = null;
            if ($idCourant !== null) {
                $st = $this->pdo->prepare('SELECT section_id FROM inscription WHERE id = ?');
                $st->execute([$idCourant]);
                $avant = (int) $st->fetchColumn();
            }
            if ($avant !== (int) $d['section_id']) {
                $err['section_id'] = 'Cette activité est arrêtée : elle n\'est plus proposée (Paramétrage > Activités arrêtées).';
            }
        }
        if (!$err && ($autre = $this->existante($adherentId, (int) $d['section_id'], (int) $d['saison_id'], $idCourant))) {
            $err['section_id'] = 'Cet adhérent est déjà inscrit à cette section pour cette saison (inscription n° ' . $autre . ').';
        }
        return [$d, $err];
    }

    public function creer(array $d): int
    {
        $d['date_creation'] = date('Y-m-d H:i:s');
        $cols = array_keys($d);
        $this->pdo->prepare('INSERT INTO inscription (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function modifier(int $id, array $d): void
    {
        unset($d['adherent_id']);
        $d['date_modification'] = date('Y-m-d H:i:s');
        $set = implode(',', array_map(static fn ($c) => "$c = ?", array_keys($d)));
        $this->pdo->prepare("UPDATE inscription SET $set WHERE id = ?")->execute([...array_values($d), $id]);
    }

    /** Supprime l'inscription et ses règlements. */
    public function supprimer(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM reglement WHERE inscription_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM inscription WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function deAdherent(int $adherentId): array
    {
        $st = $this->pdo->prepare(
            'SELECT i.id, i.date_inscription, i.montant, s.titre AS section, sa.titre AS saison, sa.date_debut,
                    (SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id) AS regle
             FROM inscription i JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id
             WHERE i.adherent_id = ? ORDER BY sa.date_debut DESC, sa.id DESC, s.titre'
        );
        $st->execute([$adherentId]);
        return $st->fetchAll();
    }

    /**
     * @param string $statut 'toutes' | 'impayees' | 'soldees'
     * @return array{lignes:list<array<string,mixed>>,total:int,reste:float}
     */
    public function lister(int $saisonId, string $statut, ?int $sectionId, string $q, int $page): array
    {
        $where = ['i.saison_id = ?'];
        $args = [$saisonId];
        if ($sectionId) {
            $where[] = 'i.section_id = ?';
            $args[] = $sectionId;
        }
        foreach ($q === '' ? [] : (preg_split('/\s+/', $q) ?: []) as $mot) {
            $like = Input::like($mot);
            $where[] = "(a.nom LIKE ? ESCAPE '!' OR a.prenom LIKE ? ESCAPE '!')";
            array_push($args, $like, $like);
        }
        $regle = '(SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id)';
        $having = match ($statut) {
            'impayees' => "AND i.montant - $regle > 0.004",
            'soldees' => "AND i.montant - $regle <= 0.004",
            default => '',
        };
        $w = implode(' AND ', $where) . ' ' . $having;
        $from = 'FROM inscription i JOIN adherent a ON a.id = i.adherent_id JOIN section s ON s.id = i.section_id';

        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(i.montant - $regle),0) $from WHERE $w");
        $st->execute($args);
        [$total, $reste] = $st->fetch(\PDO::FETCH_NUM);

        $st = $this->pdo->prepare(
            "SELECT i.id, i.adherent_id, i.date_inscription, i.montant, a.nom, a.prenom, s.titre AS section, $regle AS regle
             $from WHERE $w ORDER BY a.nom, a.prenom, s.titre LIMIT " . self::PAR_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PAR_PAGE
        );
        $st->execute($args);
        return ['lignes' => $st->fetchAll(), 'total' => (int) $total, 'reste' => (float) $reste];
    }
}
