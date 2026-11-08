<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

final class Reglements
{
    public const PAR_PAGE = 50;
    /** Moyens de paiement qui se posent en banque à une date convenue (le 1er ou le 15). */
    public const MODE_CHEQUE = 1;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function modes(): array
    {
        return $this->pdo->query('SELECT id, libelle FROM mode_reglement ORDER BY id')->fetchAll();
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM reglement WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function nettoyer(array $in, int $inscriptionId): array
    {
        $err = [];
        $d = [
            'inscription_id' => $inscriptionId,
            'mode_reglement_id' => Input::int($in, 'mode_reglement_id'),
            'montant' => Input::montant($in, 'montant'),
            'date_reglement' => Input::date($in, 'date_reglement'),
            'identifiant' => Input::str($in, 'identifiant', 60),
            'remarques' => Input::text($in, 'remarques'),
        ];
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM mode_reglement WHERE id = ?');
        $st->execute([$d['mode_reglement_id'] ?? 0]);
        if (!$d['mode_reglement_id'] || (int) $st->fetchColumn() === 0) {
            $err['mode_reglement_id'] = 'Choisissez le moyen de paiement.';
        }
        if ($d['montant'] === null) {
            $err['montant'] = 'Montant invalide (exemple : 60 ou 60,50).';
        } elseif ((float) $d['montant'] == 0.0) {
            $err['montant'] = 'Le montant ne peut pas être nul (utilisez un montant négatif pour un remboursement).';
        }
        if ($d['date_reglement'] === null) {
            $err['date_reglement'] = Input::str($in, 'date_reglement', 10) === null ? 'Indiquez la date du règlement.' : 'Date invalide.';
        }
        return [$d, $err];
    }

    public function creer(array $d): int
    {
        $d['date_saisie'] = date('Y-m-d H:i:s');
        $cols = array_keys($d);
        $this->pdo->prepare('INSERT INTO reglement (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function modifier(int $id, array $d): void
    {
        unset($d['inscription_id']);
        $d['date_modification'] = date('Y-m-d H:i:s');
        $set = implode(',', array_map(static fn ($c) => "$c = ?", array_keys($d)));
        $this->pdo->prepare("UPDATE reglement SET $set WHERE id = ?")->execute([...array_values($d), $id]);
    }

    public function supprimer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM reglement WHERE id = ?')->execute([$id]);
    }

    /** Total réglé et montant dû d'une inscription. @return array{montant:float,regle:float} */
    public function solde(int $inscriptionId): array
    {
        $st = $this->pdo->prepare('SELECT montant, (SELECT COALESCE(SUM(montant),0) FROM reglement WHERE inscription_id = inscription.id) FROM inscription WHERE id = ?');
        $st->execute([$inscriptionId]);
        $r = $st->fetch(\PDO::FETCH_NUM) ?: [0, 0];
        return ['montant' => (float) $r[0], 'regle' => (float) $r[1]];
    }

    /** @return list<array<string,mixed>> */
    public function deInscription(int $inscriptionId): array
    {
        $st = $this->pdo->prepare('SELECT r.*, m.libelle AS mode FROM reglement r JOIN mode_reglement m ON m.id = r.mode_reglement_id WHERE r.inscription_id = ? ORDER BY r.date_reglement, r.id');
        $st->execute([$inscriptionId]);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function deAdherent(int $adherentId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.id, r.inscription_id, r.date_reglement, r.montant, r.identifiant, m.libelle AS mode, s.titre AS section, sa.titre AS saison
             FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN mode_reglement m ON m.id = r.mode_reglement_id
             JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id
             WHERE i.adherent_id = ? ORDER BY r.date_reglement DESC, r.id DESC'
        );
        $st->execute([$adherentId]);
        return $st->fetchAll();
    }

    /** @return array{lignes:list<array<string,mixed>>,total:int,somme:float} */
    public function lister(int $saisonId, ?int $modeId, ?string $du, ?string $au, string $q, int $page): array
    {
        $where = ['i.saison_id = ?'];
        $args = [$saisonId];
        if ($modeId) {
            $where[] = 'r.mode_reglement_id = ?';
            $args[] = $modeId;
        }
        if ($du) {
            $where[] = 'r.date_reglement >= ?';
            $args[] = $du;
        }
        if ($au) {
            $where[] = 'r.date_reglement <= ?';
            $args[] = $au;
        }
        foreach ($q === '' ? [] : (preg_split('/\s+/', $q) ?: []) as $mot) {
            $like = Input::like($mot);
            $where[] = "(a.nom LIKE ? ESCAPE '!' OR a.prenom LIKE ? ESCAPE '!' OR r.identifiant LIKE ? ESCAPE '!')";
            array_push($args, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $from = 'FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN adherent a ON a.id = i.adherent_id
                 JOIN section s ON s.id = i.section_id JOIN mode_reglement m ON m.id = r.mode_reglement_id';
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(r.montant),0) $from WHERE $w");
        $st->execute($args);
        [$total, $somme] = $st->fetch(\PDO::FETCH_NUM);
        $st = $this->pdo->prepare(
            "SELECT r.id, r.inscription_id, r.date_reglement, r.montant, r.identifiant, m.libelle AS mode, a.id AS adherent_id, a.nom, a.prenom, s.titre AS section
             $from WHERE $w ORDER BY r.date_reglement DESC, r.id DESC LIMIT " . self::PAR_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PAR_PAGE
        );
        $st->execute($args);
        return ['lignes' => $st->fetchAll(), 'total' => (int) $total, 'somme' => (float) $somme];
    }

    /**
     * Règlements de la saison regroupés par moyen (et, pour les coupons et pass, par aide d'après la référence),
     * avec la part déjà encaissable (date passée) et la part à venir.
     *
     * @return array{lignes:list<array<string,mixed>>,total:array<string,float|int>,reste:float,inscrit:float}
     */
    public function parType(int $saisonId, ?string $du, ?string $au): array
    {
        $where = ['i.saison_id = ?'];
        $args = [$saisonId];
        if ($du) {
            $where[] = 'r.date_reglement >= ?';
            $args[] = $du;
        }
        if ($au) {
            $where[] = 'r.date_reglement <= ?';
            $args[] = $au;
        }
        $st = $this->pdo->prepare('SELECT r.mode_reglement_id, m.libelle, r.identifiant, r.montant, r.date_reglement
            FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN mode_reglement m ON m.id = r.mode_reglement_id
            WHERE ' . implode(' AND ', $where));
        $st->execute($args);
        $auj = date('Y-m-d');
        $lignes = [];
        $total = ['nombre' => 0, 'montant' => 0.0, 'encaisse' => 0.0, 'a_venir' => 0.0];
        foreach ($st->fetchAll() as $r) {
            $mode = (int) $r['mode_reglement_id'];
            $cle = $mode . '|' . self::detailAide($mode, (string) $r['identifiant']);
            $lignes[$cle] ??= ['mode_id' => $mode, 'mode' => $r['libelle'], 'detail' => self::detailAide($mode, (string) $r['identifiant']),
                'nombre' => 0, 'montant' => 0.0, 'encaisse' => 0.0, 'a_venir' => 0.0];
            $venir = $r['date_reglement'] !== null && substr((string) $r['date_reglement'], 0, 10) > $auj;
            foreach ([&$lignes[$cle], &$total] as &$l) {
                $l['nombre']++;
                $l['montant'] += (float) $r['montant'];
                $l[$venir ? 'a_venir' : 'encaisse'] += (float) $r['montant'];
            }
            unset($l);
        }
        uasort($lignes, static fn ($a, $b) => [$a['mode_id'], $a['detail']] <=> [$b['mode_id'], $b['detail']]);
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(montant),0) FROM inscription WHERE saison_id = ?');
        $st->execute([$saisonId]);
        $inscrit = (float) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(r.montant),0) FROM reglement r JOIN inscription i ON i.id = r.inscription_id WHERE i.saison_id = ?');
        $st->execute([$saisonId]);
        return ['lignes' => array_values($lignes), 'total' => $total, 'inscrit' => $inscrit, 'reste' => $inscrit - (float) $st->fetchColumn()];
    }

    /** Coupons mairie et pass Région : l'aide se lit dans la référence (COUPON SPORT, COUPON CULTURE, PASS REGION SENIOR). */
    public static function detailAide(int $mode, string $identifiant): string
    {
        $r = mb_strtoupper(trim($identifiant));
        return match (true) {
            $mode === 6 && str_contains($r, 'CULTURE') => 'Coupon culture',
            $mode === 6 && str_contains($r, 'SPORT') => 'Coupon sport',
            $mode === 8 && str_contains($r, 'SENIOR') => 'Pass Senior',
            default => '',
        };
    }

    /** Même jour le mois suivant, ramené au dernier jour du mois si besoin (31/01 -> 28/02). */
    public static function moisSuivant(string $iso): string
    {
        $t = strtotime(substr($iso, 0, 10));
        $suivant = strtotime('first day of next month', $t);
        return date('Y-m-', $suivant) . sprintf('%02d', min((int) date('j', $t), (int) date('t', $suivant)));
    }

    /**
     * Chèques en plusieurs fois : le chèque suivant de la série est proposé un mois après le dernier (même jour de pose),
     * pour le montant du dernier chèque, sans dépasser le reste à régler.
     *
     * @return ?array{date:string,montant:float,rang:int,precedent:string}
     */
    public function chequeSuivant(int $inscriptionId, float $reste): ?array
    {
        $st = $this->pdo->prepare('SELECT date_reglement, montant FROM reglement WHERE inscription_id = ? AND mode_reglement_id = ? AND date_reglement IS NOT NULL ORDER BY date_reglement DESC, id DESC');
        $st->execute([$inscriptionId, self::MODE_CHEQUE]);
        $cheques = $st->fetchAll();
        if (!$cheques) {
            return null;
        }
        $dernier = $cheques[0];
        $montant = (float) $dernier['montant'];
        if ($reste > 0 && $reste < $montant) {
            $montant = $reste;
        }
        return ['date' => self::moisSuivant((string) $dernier['date_reglement']), 'montant' => round($montant, 2),
            'rang' => count($cheques) + 1, 'precedent' => substr((string) $dernier['date_reglement'], 0, 10)];
    }
}
