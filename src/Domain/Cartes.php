<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Abonnements à la carte (10 séances…) : une section « à la carte » donne un nombre de séances par carte,
 * utilisables dans un cours (le même, ou le cours normal du même créneau). Chaque présence notée par l'animateur
 * consomme une séance ; le bureau ajoute une carte quand l'adhérent en rachète une.
 */
final class Cartes
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Sections à la carte et leur réglage. @return array<int,array{section_id:int,seances:int,cours_id:int}> */
    public function reglages(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT section_id, seances, cours_id FROM section_carte')->fetchAll() as $r) {
            $out[(int) $r['section_id']] = ['section_id' => (int) $r['section_id'], 'seances' => (int) $r['seances'], 'cours_id' => (int) ($r['cours_id'] ?: $r['section_id'])];
        }
        return $out;
    }

    /**
     * Première utilisation : repère les sections à la carte d'après leur titre (« À LA CARTE », « 10 COURS », « 10 SÉANCES »)
     * et le cours où elles s'utilisent (même activité, même jour, hors essai et hors carte).
     */
    public function detecter(): int
    {
        // Modelage : toujours à la carte (sa propre liste de présence), ajouté une fois même si la détection a déjà eu lieu
        if (\Ascs\Parametres::get($this->pdo, 'cartes_modelage') === null) {
            \Ascs\Parametres::set($this->pdo, 'cartes_modelage', date('Y-m-d'));
            foreach ($this->pdo->query("SELECT id FROM section WHERE essai = 0 AND UPPER(titre) LIKE 'MODELAGE%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!isset($this->reglages()[(int) $id])) {
                    $this->enregistrer((int) $id, 10, null);
                }
            }
        }
        // Une seule fois : ensuite le bureau règle lui-même la liste (même s'il la vide)
        if (\Ascs\Parametres::get($this->pdo, 'cartes_detectees') !== null || (int) $this->pdo->query('SELECT COUNT(*) FROM section_carte WHERE cours_id IS NOT NULL')->fetchColumn() > 0) {
            return 0;
        }
        \Ascs\Parametres::set($this->pdo, 'cartes_detectees', date('Y-m-d'));
        $n = 0;
        $sections = $this->pdo->query('SELECT id, titre, section_principale_id, essai FROM section')->fetchAll();
        $estCarte = static fn ($t) => (bool) preg_match('/\bA LA CARTE\b|\b\d+\s*(COURS|S[EÉ]ANCES)\b/iu', (string) $t);
        foreach ($sections as $s) {
            if ($s['essai'] || !$estCarte($s['titre'])) {
                continue;
            }
            $nb = preg_match('/\b(\d+)\s*(COURS|S[EÉ]ANCES)\b/iu', (string) $s['titre'], $m) ? (int) $m[1] : 10;
            $this->enregistrer((int) $s['id'], $nb, $this->coursProbable((int) $s['id'], $sections, $estCarte));
            $n++;
        }
        return $n;
    }

    private function coursProbable(int $id, array $sections, callable $estCarte): ?int
    {
        $jours = fn (int $sid) => array_map(static fn ($c) => (int) $c['jour'], (new Referentiel($this->pdo))->creneaux($sid));
        $moi = array_values(array_filter($sections, static fn ($s) => (int) $s['id'] === $id))[0];
        $j = $jours($id);
        $candidats = array_values(array_filter($sections, static fn ($s) => (int) $s['id'] !== $id && !$s['essai'] && !$estCarte($s['titre'])
            && $s['section_principale_id'] !== null && $s['section_principale_id'] === $moi['section_principale_id']));
        foreach ($candidats as $c) {
            if ($j && array_intersect($j, $jours((int) $c['id']))) {
                return (int) $c['id'];
            }
        }
        return count($candidats) === 1 ? (int) $candidats[0]['id'] : null;
    }

    public function enregistrer(int $sectionId, int $seances, ?int $coursId): void
    {
        $seances = max(1, min(100, $seances));
        $coursId = $coursId && $coursId !== $sectionId ? $coursId : null;
        $this->pdo->prepare('DELETE FROM section_carte WHERE section_id = ?')->execute([$sectionId]);
        $this->pdo->prepare('INSERT INTO section_carte (section_id, seances, cours_id) VALUES (?, ?, ?)')->execute([$sectionId, $seances, $coursId]);
    }

    public function retirer(int $sectionId): void
    {
        $this->pdo->prepare('DELETE FROM section_carte WHERE section_id = ?')->execute([$sectionId]);
    }

    /** Sections à la carte utilisées dans ce cours (y compris lui-même s'il est à la carte). @return list<int> */
    public function sectionsDuCours(int $coursId): array
    {
        $out = [];
        foreach ($this->reglages() as $r) {
            if ($r['cours_id'] === $coursId) {
                $out[] = $r['section_id'];
            }
        }
        return $out;
    }

    /** Sections à la carte qui s'utilisent dans un autre cours (leur liste est montrée dans ce cours-là). @return list<int> */
    public function rattachees(): array
    {
        return array_values(array_map(static fn ($r) => $r['section_id'], array_filter($this->reglages(), static fn ($r) => $r['cours_id'] !== $r['section_id'])));
    }

    /**
     * État des cartes : séances achetées (cartes), utilisées (présences dans le cours ou la section), restantes.
     * @param list<int> $inscriptionIds
     * @return array<int,array{achetees:int,utilisees:int,restantes:int,cartes:int,derniere:?string}> par inscription
     */
    public function etats(array $inscriptionIds): array
    {
        $inscriptionIds = array_values(array_unique(array_map('intval', $inscriptionIds)));
        if (!$inscriptionIds) {
            return [];
        }
        $reg = $this->reglages();
        $in = implode(',', $inscriptionIds);
        $rech = [];
        foreach ($this->pdo->query("SELECT inscription_id, SUM(seances) AS s, COUNT(*) AS n FROM carte_recharge WHERE inscription_id IN ($in) GROUP BY inscription_id")->fetchAll() as $r) {
            $rech[(int) $r['inscription_id']] = [(int) $r['s'], (int) $r['n']];
        }
        $pres = $this->pdo->prepare('SELECT COUNT(*) AS n, MAX(date_seance) AS d FROM presence WHERE adherent_id = ? AND saison_id = ? AND present = 1 AND section_id IN (?, ?)');
        $out = [];
        foreach ($this->pdo->query("SELECT id, adherent_id, section_id, saison_id FROM inscription WHERE id IN ($in)")->fetchAll() as $i) {
            $r = $reg[(int) $i['section_id']] ?? null;
            if (!$r) {
                continue;
            }
            [$sRech, $nRech] = $rech[(int) $i['id']] ?? [0, 0];
            $pres->execute([$i['adherent_id'], $i['saison_id'], $r['section_id'], $r['cours_id']]);
            $p = $pres->fetch();
            $achetees = $r['seances'] + $sRech;
            $out[(int) $i['id']] = ['achetees' => $achetees, 'utilisees' => (int) $p['n'], 'restantes' => $achetees - (int) $p['n'], 'cartes' => 1 + $nRech, 'derniere' => $p['d'] ?: null];
        }
        return $out;
    }

    /** Porteurs de carte de la saison, par section à la carte. @return list<array<string,mixed>> */
    public function porteurs(int $saisonId): array
    {
        $reg = $this->reglages();
        if (!$reg) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT i.id AS inscription_id, i.montant, i.section_id, s.titre AS section, s.montant AS prix, a.id AS adherent_id, a.nom, a.prenom, a.telephone1, a.telephone2, a.mail
            FROM inscription i JOIN adherent a ON a.id = i.adherent_id JOIN section s ON s.id = i.section_id
            WHERE i.saison_id = ? AND i.section_id IN (' . implode(',', array_keys($reg)) . ') ORDER BY s.titre, a.nom, a.prenom');
        $st->execute([$saisonId]);
        $l = $st->fetchAll();
        $etats = $this->etats(array_column($l, 'inscription_id'));
        return array_map(static fn ($r) => $r + ($etats[(int) $r['inscription_id']] ?? ['achetees' => 0, 'utilisees' => 0, 'restantes' => 0, 'cartes' => 1, 'derniere' => null]), $l);
    }

    /** @return list<array<string,mixed>> */
    public function recharges(int $inscriptionId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM carte_recharge WHERE inscription_id = ? ORDER BY date_achat, id');
        $st->execute([$inscriptionId]);
        return $st->fetchAll();
    }

    /** Ajoute une carte (rachat). Renvoie un message d'erreur ou null. */
    public function recharger(int $inscriptionId, int $seances, ?float $montant, string $date, string $note, string $par): ?string
    {
        $st = $this->pdo->prepare('SELECT section_id FROM inscription WHERE id = ?');
        $st->execute([$inscriptionId]);
        $sid = $st->fetchColumn();
        if ($sid === false || !isset($this->reglages()[(int) $sid])) {
            return 'Cette inscription n\'est pas une inscription à la carte.';
        }
        if ($seances < 1 || $seances > 100) {
            return 'Nombre de séances invalide.';
        }
        $this->pdo->prepare('INSERT INTO carte_recharge (inscription_id, seances, montant, date_achat, note, par, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$inscriptionId, $seances, $montant, $date, mb_substr(trim($note), 0, 255) ?: null, mb_substr($par, 0, 150), date('Y-m-d H:i:s')]);
        return null;
    }

    public function supprimerRecharge(int $id): void
    {
        $this->pdo->prepare('DELETE FROM carte_recharge WHERE id = ?')->execute([$id]);
    }
}
