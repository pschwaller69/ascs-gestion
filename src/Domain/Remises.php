<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;
use RuntimeException;

/**
 * Remises de chèques.
 *  - Les chèques se posent le 1er ou le 15 du mois : la date de règlement d'un chèque est sa date de pose.
 *  - 30 chèques au plus par remise : au-delà, les chèques sont répartis sur plusieurs bordereaux.
 *  - Une fois la remise passée en banque, la ligne « REM CHQ N<numéro> » du relevé est retrouvée par le numéro
 *    du bordereau et ventilée par section principale (type « Inscription »), comme dans WinDev.
 */
final class Remises
{
    public const MAX_CHEQUES = 30;
    public const MODE_CHEQUE = 1;
    public const TYPE_INSCRIPTION = 8;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Date à partir de laquelle on cherche des chèques non remis (les anciens chèques ont été remis avant l'application). */
    public function depuis(): string
    {
        return Parametres::get($this->pdo, 'remises_depuis') ?: date('Y-m-d', strtotime('-20 days'));
    }

    public function changerDepuis(string $date): void
    {
        Parametres::set($this->pdo, 'remises_depuis', $date);
    }

    private const NON_REMIS = 'r.mode_reglement_id = 1 AND NOT EXISTS (SELECT 1 FROM remise_cheque rc WHERE rc.reglement_id = r.id)';

    /** Dates de pose ayant des chèques non remis. @return list<array{date_reglement:string,n:int,total:float}> */
    public function datesEnAttente(): array
    {
        $st = $this->pdo->prepare('SELECT r.date_reglement, COUNT(*) AS n, SUM(r.montant) AS total FROM reglement r
            WHERE ' . self::NON_REMIS . ' AND r.date_reglement >= ? GROUP BY r.date_reglement ORDER BY r.date_reglement');
        $st->execute([$this->depuis()]);
        return $st->fetchAll();
    }

    /** Date proposée : la date de pose passée la plus récente qui a des chèques (sinon la prochaine). */
    public function dateProposee(): ?string
    {
        $dates = array_column($this->datesEnAttente(), 'date_reglement');
        $passees = array_values(array_filter($dates, static fn ($d) => $d <= date('Y-m-d')));
        return $passees ? end($passees) : ($dates[0] ?? null);
    }

    /** @return list<array<string,mixed>> */
    public function chequesAPoser(string $date): array
    {
        $st = $this->pdo->prepare('SELECT r.id, r.montant, r.identifiant, r.date_reglement, a.id AS adherent_id, a.nom, a.prenom, a.nom_parent, a.prenom_parent,
                s.titre AS section, s.section_principale_id, sp.titre AS section_principale
            FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN adherent a ON a.id = i.adherent_id
            JOIN section s ON s.id = i.section_id LEFT JOIN section_principale sp ON sp.id = s.section_principale_id
            WHERE ' . self::NON_REMIS . ' AND r.date_reglement = ? ORDER BY a.nom, a.prenom, r.id');
        $st->execute([$date]);
        return $st->fetchAll();
    }

    /** Répartit n chèques sur le moins de bordereaux possible, de tailles égales (51 → 26 + 25). @return list<int> */
    public static function decoupage(int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        $k = (int) ceil($n / self::MAX_CHEQUES);
        $base = intdiv($n, $k);
        $reste = $n % $k;
        return array_map(static fn ($i) => $base + ($i < $reste ? 1 : 0), range(0, $k - 1));
    }

    /**
     * Crée la ou les remises pour les chèques choisis d'une date de pose.
     *
     * @param list<int> $ids règlements cochés
     * @return list<int> identifiants des remises créées
     */
    public function creer(string $date, array $ids, string $par, ?string $numeroCompte): array
    {
        $dispo = [];
        foreach ($this->chequesAPoser($date) as $c) {
            $dispo[(int) $c['id']] = $c;
        }
        $choisis = array_values(array_filter(array_map('intval', $ids), static fn ($i) => isset($dispo[$i])));
        if (!$choisis) {
            throw new RuntimeException('Aucun chèque à remettre : cochez au moins un chèque.');
        }
        // Ordre du bordereau : nom de l'adhérent (celui de la liste affichée)
        $ordre = array_values(array_filter(array_keys($dispo), static fn ($i) => in_array($i, $choisis, true)));
        $remises = [];
        $this->pdo->beginTransaction();
        try {
            $pos = 0;
            $insR = $this->pdo->prepare('INSERT INTO remise (date_pose, numero_compte, cree_le, cree_par, etat_banque) VALUES (?,?,?,?,?)');
            $insC = $this->pdo->prepare('INSERT INTO remise_cheque (remise_id, reglement_id, section_principale_id, section, nom, prenom, nom_parent, prenom_parent, numero_cheque, montant, date_reglement) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach (self::decoupage(count($ordre)) as $taille) {
                $insR->execute([$date, $numeroCompte, date('Y-m-d H:i:s'), $par, 'attente']);
                $rid = (int) $this->pdo->lastInsertId();
                foreach (array_slice($ordre, $pos, $taille) as $regId) {
                    $c = $dispo[$regId];
                    $insC->execute([$rid, $regId, $c['section_principale_id'], $c['section'], $c['nom'], $c['prenom'], $c['nom_parent'], $c['prenom_parent'],
                        $c['identifiant'], $c['montant'], $c['date_reglement']]);
                }
                $pos += $taille;
                $remises[] = $rid;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $remises;
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM remise_cheque c WHERE c.remise_id = r.id) AS n,
                (SELECT COALESCE(SUM(c.montant),0) FROM remise_cheque c WHERE c.remise_id = r.id) AS total
            FROM remise r WHERE r.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM remise_cheque WHERE remise_id = ? ORDER BY nom, prenom, id');
        $st->execute([$id]);
        $r['cheques'] = $st->fetchAll();
        // Bordereaux du même jour de pose (« remise 1 sur 2 »)
        $st = $this->pdo->prepare('SELECT id FROM remise WHERE date_pose = ? ORDER BY id');
        $st->execute([$r['date_pose']]);
        $r['soeurs'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($r['compte_id']) {
            $st = $this->pdo->prepare('SELECT id, date_operation, libelle, credit FROM compte WHERE id = ?');
            $st->execute([$r['compte_id']]);
            $r['ligne_banque'] = $st->fetch() ?: null;
        } else {
            $r['ligne_banque'] = null;
        }
        return $r;
    }

    /** @return list<array<string,mixed>> */
    public function liste(int $limite = 200): array
    {
        return $this->pdo->query('SELECT r.*, (SELECT COUNT(*) FROM remise_cheque c WHERE c.remise_id = r.id) AS n,
                (SELECT COALESCE(SUM(c.montant),0) FROM remise_cheque c WHERE c.remise_id = r.id) AS total
            FROM remise r ORDER BY r.date_pose DESC, r.id DESC LIMIT ' . $limite)->fetchAll();
    }

    /** Montants par section principale. @return list<array{section_principale_id:?int,titre:string,n:int,total:float}> */
    public function ventilation(int $id): array
    {
        $st = $this->pdo->prepare('SELECT c.section_principale_id, COALESCE(sp.titre, \'(sans section principale)\') AS titre, COUNT(*) AS n, SUM(c.montant) AS total
            FROM remise_cheque c LEFT JOIN section_principale sp ON sp.id = c.section_principale_id
            WHERE c.remise_id = ? GROUP BY c.section_principale_id, sp.titre ORDER BY titre');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** @return list<string> comptes bancaires connus, le plus utilisé pour les remises en premier */
    public function comptesBancaires(): array
    {
        return $this->pdo->query("SELECT numero_compte FROM compte GROUP BY numero_compte
            ORDER BY SUM(CASE WHEN libelle LIKE 'REM CHQ%' THEN 1 ELSE 0 END) DESC, COUNT(*) DESC")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function modifiable(array $r): void
    {
        if (in_array($r['etat_banque'], ['ventilee', 'deja_ventilee'], true)) {
            throw new RuntimeException('Cette remise est déjà passée en banque et ventilée : elle ne peut plus être modifiée.');
        }
    }

    public function enregistrerInfos(int $id, ?string $numero, ?string $dateDepot, ?string $numeroCompte): void
    {
        $r = $this->trouver($id) ?? throw new RuntimeException('Remise introuvable.');
        if ($numero !== null && !preg_match('/^\d{4,12}$/', $numero)) {
            throw new RuntimeException('Le numéro de bordereau ne contient que des chiffres (celui imprimé sur le bordereau de la banque).');
        }
        if ($numero !== null) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM remise WHERE numero = ? AND id <> ?');
            $st->execute([$numero, $id]);
            if ((int) $st->fetchColumn() > 0) {
                throw new RuntimeException("Le bordereau n° $numero est déjà utilisé par une autre remise.");
            }
        }
        if ($numero !== $r['numero']) {
            $this->modifiable($r);
        }
        $this->pdo->prepare('UPDATE remise SET numero = ?, date_depot = ?, numero_compte = ? WHERE id = ?')->execute([$numero, $dateDepot, $numeroCompte, $id]);
    }

    public function retirerCheque(int $remiseId, int $chequeId): void
    {
        $r = $this->trouver($remiseId) ?? throw new RuntimeException('Remise introuvable.');
        $this->modifiable($r);
        $this->pdo->prepare('DELETE FROM remise_cheque WHERE id = ? AND remise_id = ?')->execute([$chequeId, $remiseId]);
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM remise_cheque WHERE remise_id = ' . $remiseId)->fetchColumn() === 0) {
            $this->pdo->prepare('DELETE FROM remise WHERE id = ?')->execute([$remiseId]);
        }
    }

    public function supprimer(int $id): void
    {
        $r = $this->trouver($id) ?? throw new RuntimeException('Remise introuvable.');
        $this->modifiable($r);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM remise_cheque WHERE remise_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM remise WHERE id = ?')->execute([$id]);
        $this->pdo->commit();
    }

    // ------------------------------------------------------------------ rapprochement bancaire et ventilation

    private static function marque(int $remiseId): string
    {
        return 'Remise de chèques n° ' . $remiseId . ' (application)';
    }

    /**
     * Retrouve la ligne de la banque de chaque remise et la ventile par section principale.
     *  1. par le numéro du bordereau (« REM CHQ N<numéro> », format de la banque depuis septembre 2025) ;
     *  2. sinon par le montant exact, sur une ligne « REM CHQ » datée de 0 à 21 jours après le dépôt
     *     (ou la date de pose), et seulement si une seule ligne correspond.
     * Rejouable : après un import WinDev qui aurait effacé la ventilation, elle est recréée.
     *
     * @return array{ventilees:int,ecarts:int}
     */
    public function rapprocher(): array
    {
        $res = ['ventilees' => 0, 'ecarts' => 0];
        $remises = $this->pdo->query("SELECT r.*, (SELECT COALESCE(SUM(c.montant),0) FROM remise_cheque c WHERE c.remise_id = r.id) AS total
            FROM remise r ORDER BY r.id")->fetchAll();
        $parNumero = $this->pdo->prepare("SELECT id, credit, numero_compte FROM compte WHERE libelle LIKE ? ORDER BY date_operation DESC");
        $parMontant = $this->pdo->prepare("SELECT id, credit, numero_compte FROM compte WHERE libelle LIKE 'REM CHQ%' AND ABS(credit - ?) < 0.005 AND date_operation BETWEEN ? AND ?");
        $nbDetails = $this->pdo->prepare('SELECT COUNT(*) FROM detail_compte WHERE compte_id = ?');
        $nosDetails = $this->pdo->prepare('SELECT COUNT(*) FROM detail_compte WHERE compte_id = ? AND observation = ?');
        $maj = $this->pdo->prepare('UPDATE remise SET compte_id = ?, etat_banque = ?, ventilee_le = ? WHERE id = ?');
        $pris = [];
        // Les remises déjà liées gardent leur ligne en priorité
        usort($remises, static fn ($a, $b) => [$a['compte_id'] ? 0 : 1, $a['id']] <=> [$b['compte_id'] ? 0 : 1, $b['id']]);
        foreach ($remises as $r) {
            $bonCompte = static fn ($l) => !$r['numero_compte'] || $l['numero_compte'] === $r['numero_compte'];
            $l = null;
            if ($r['numero']) {
                $parNumero->execute(['REM CHQ%N' . $r['numero'] . '%']);
                $l = array_values(array_filter($parNumero->fetchAll(), $bonCompte))[0] ?? null;
            }
            if ($l === null && (float) $r['total'] > 0) {
                $depuis = $r['date_depot'] ?: $r['date_pose'];
                $parMontant->execute([$r['total'], $depuis, date('Y-m-d', strtotime($depuis . ' +21 days'))]);
                $cands = array_values(array_filter($parMontant->fetchAll(), static fn ($c) => $bonCompte($c) && !isset($pris[(int) $c['id']])));
                $l = count($cands) === 1 ? $cands[0] : null;
            }
            if ($l === null) {
                if ($r['etat_banque'] !== 'attente' || $r['compte_id']) {
                    $maj->execute([null, 'attente', null, $r['id']]);
                }
                continue;
            }
            $pris[(int) $l['id']] = true;
            if (abs((float) $l['credit'] - (float) $r['total']) > 0.005) {
                $maj->execute([$l['id'], 'ecart', null, $r['id']]);
                $res['ecarts']++;
                continue;
            }
            $nbDetails->execute([$l['id']]);
            $total = (int) $nbDetails->fetchColumn();
            $nosDetails->execute([$l['id'], self::marque((int) $r['id'])]);
            $nous = (int) $nosDetails->fetchColumn();
            if ($total > 0 && $nous === 0) {
                // Déjà ventilée à la main (dans WinDev ou dans l'application) : on ne touche à rien.
                $maj->execute([$l['id'], 'deja_ventilee', $r['ventilee_le'], $r['id']]);
                continue;
            }
            if ($nous === 0) {
                $this->ecrireVentilation((int) $r['id'], (int) $l['id']);
                $res['ventilees']++;
            }
            $maj->execute([$l['id'], 'ventilee', $r['ventilee_le'] ?: date('Y-m-d H:i:s'), $r['id']]);
        }
        return $res;
    }

    private function ecrireVentilation(int $remiseId, int $compteId): void
    {
        $st = $this->pdo->prepare('SELECT libelle FROM compte WHERE id = ?');
        $st->execute([$compteId]);
        $libelle = (string) $st->fetchColumn();
        // detail_compte garde les identifiants WinDev (pas d'auto-incrément) : on prend le suivant.
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM detail_compte')->fetchColumn();
        $ins = $this->pdo->prepare('INSERT INTO detail_compte (id, compte_id, section_principale_id, type_compte_id, debit, credit, valeur, libelle, observation, traite_auto, date_creation)
            VALUES (?,?,?,?,0,?,?,?,?,1,?)');
        foreach ($this->ventilation($remiseId) as $v) {
            $ins->execute([++$id, $compteId, $v['section_principale_id'], self::TYPE_INSCRIPTION, $v['total'], $v['total'], mb_substr($libelle, 0, 255),
                self::marque($remiseId), date('Y-m-d H:i:s')]);
        }
    }
}
