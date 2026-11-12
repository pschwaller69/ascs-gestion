<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Personnes qui viennent au cours sans être inscrites cette saison, ajoutées par l'animateur :
 * un ancien adhérent de l'activité (recherche assistée) ou une nouvelle personne (saisie libre).
 * Elles apparaissent dans la liste de présence et le bureau les retrouve « à relancer » jusqu'à leur inscription.
 */
final class Participants
{
    public const STATUTS = ['a_relancer' => 'À relancer', 'relance' => 'Relancé', 'inscrit' => 'Inscrit', 'retire' => 'Retiré'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Non-inscrits à afficher dans la liste de présence du cours (ni inscrits depuis, ni retirés). @return list<array<string,mixed>> */
    public function duCours(int $sectionId, int $saisonId): array
    {
        $this->detecterInscrits($saisonId);
        $st = $this->pdo->prepare("SELECT p.*, a.telephone1, a.telephone2, a.date_naissance FROM participant p LEFT JOIN adherent a ON a.id = p.adherent_id
            WHERE p.section_id = ? AND p.saison_id = ? AND p.statut IN ('a_relancer','relance') ORDER BY p.nom, p.prenom");
        $st->execute([$sectionId, $saisonId]);
        return $st->fetchAll();
    }

    /**
     * Anciens adhérents de l'activité (même section principale, saisons précédentes) pas encore inscrits à ce cours cette saison.
     * Seuls le nom, le prénom et le dernier cours sont montrés à l'animateur.
     * @return list<array{id:int,nom:string,prenom:string,dernier:string}>
     */
    public function anciens(int $sectionId, int $saisonId, string $q, int $max = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $mots = array_slice(preg_split('/\s+/', $q) ?: [], 0, 3);
        $w = [];
        $args = [$sectionId, $saisonId, $sectionId, $saisonId];
        foreach ($mots as $m) {
            $w[] = "(a.nom LIKE ? ESCAPE '!' OR a.prenom LIKE ? ESCAPE '!')";
            $l = '%' . Input::like($m) . '%';
            array_push($args, $l, $l);
        }
        $st = $this->pdo->prepare("SELECT a.id, a.nom, a.prenom, MAX(sa.date_debut) AS derniere
            FROM adherent a JOIN inscription i ON i.adherent_id = a.id JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id
            JOIN section src ON src.id = ?
            WHERE i.saison_id <> ? AND (s.section_principale_id = src.section_principale_id OR s.id = src.id)
              AND NOT EXISTS (SELECT 1 FROM inscription j WHERE j.adherent_id = a.id AND j.section_id = ? AND j.saison_id = ?)
              AND " . implode(' AND ', $w) . '
            GROUP BY a.id, a.nom, a.prenom ORDER BY a.nom, a.prenom LIMIT ' . $max);
        $st->execute($args);
        $out = [];
        $der = $this->pdo->prepare('SELECT s.titre, sa.titre AS saison FROM inscription i JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id
            WHERE i.adherent_id = ? ORDER BY sa.date_debut DESC LIMIT 1');
        foreach ($st->fetchAll() as $r) {
            $der->execute([$r['id']]);
            $d = $der->fetch();
            $out[] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'prenom' => (string) $r['prenom'], 'dernier' => $d ? $d['titre'] . ' ' . $d['saison'] : ''];
        }
        return $out;
    }

    /** Ajoute un ancien adhérent trouvé par la recherche. Renvoie l'identifiant du participant, ou un message d'erreur. */
    public function ajouterAncien(int $sectionId, int $saisonId, int $adherentId, string $par): int|string
    {
        $st = $this->pdo->prepare('SELECT nom, prenom FROM adherent WHERE id = ?');
        $st->execute([$adherentId]);
        $a = $st->fetch();
        if (!$a || !in_array($adherentId, array_column($this->anciens($sectionId, $saisonId, (string) $a['nom'], 200), 'id'), true)) {
            return 'Cette personne ne fait pas partie des anciens adhérents de ce cours, ou y est déjà inscrite.';
        }
        $st = $this->pdo->prepare("SELECT id FROM participant WHERE adherent_id = ? AND section_id = ? AND saison_id = ? AND statut IN ('a_relancer','relance')");
        $st->execute([$adherentId, $sectionId, $saisonId]);
        if ($id = $st->fetchColumn()) {
            return (int) $id;
        }
        return $this->inserer($sectionId, $saisonId, $adherentId, (string) $a['nom'], (string) $a['prenom'], null, $par);
    }

    /** Ajoute une nouvelle personne (saisie libre). */
    public function ajouterNouveau(int $sectionId, int $saisonId, string $nom, string $prenom, string $tel, string $par): int|string
    {
        $nom = mb_substr(trim(preg_replace('/\s+/', ' ', $nom) ?? ''), 0, 100);
        $prenom = mb_substr(trim(preg_replace('/\s+/', ' ', $prenom) ?? ''), 0, 100);
        $tel = mb_substr(trim($tel), 0, 30);
        if ($nom === '' || $prenom === '') {
            return 'Indiquez le nom et le prénom.';
        }
        if ($tel !== '' && !preg_match('/^[\d\s.+()-]{6,}$/', $tel)) {
            return 'Le téléphone n\'est pas valide.';
        }
        $st = $this->pdo->prepare("SELECT id FROM participant WHERE adherent_id IS NULL AND section_id = ? AND saison_id = ? AND LOWER(nom) = LOWER(?) AND LOWER(prenom) = LOWER(?) AND statut IN ('a_relancer','relance')");
        $st->execute([$sectionId, $saisonId, $nom, $prenom]);
        if ($id = $st->fetchColumn()) {
            return (int) $id;
        }
        return $this->inserer($sectionId, $saisonId, null, mb_strtoupper($nom), $prenom, $tel ?: null, $par);
    }

    private function inserer(int $sectionId, int $saisonId, ?int $adherentId, string $nom, string $prenom, ?string $tel, string $par): int
    {
        $this->pdo->prepare("INSERT INTO participant (section_id, saison_id, adherent_id, nom, prenom, telephone, ajoute_le, ajoute_par, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'a_relancer')")->execute([$sectionId, $saisonId, $adherentId, $nom, $prenom, $tel, date('Y-m-d H:i:s'), mb_substr($par, 0, 150)]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Cours où l'adhérent est venu sans être inscrit cette saison (noté par un animateur ou un contrôleur). @return list<array{cours:string}> */
    public function venuesDe(int $adherentId, int $saisonId): array
    {
        $st = $this->pdo->prepare("SELECT s.titre AS cours FROM participant p JOIN section s ON s.id = p.section_id WHERE p.adherent_id = ? AND p.saison_id = ? AND p.statut IN ('a_relancer','relance')");
        $st->execute([$adherentId, $saisonId]);
        return $st->fetchAll();
    }

    /** @return array<int,bool> présence des non-inscrits du cours à une séance */
    public function seance(int $sectionId, int $saisonId, string $date): array
    {
        $st = $this->pdo->prepare('SELECT pp.participant_id, pp.present FROM presence_participant pp JOIN participant p ON p.id = pp.participant_id
            WHERE p.section_id = ? AND p.saison_id = ? AND pp.date_seance = ?');
        $st->execute([$sectionId, $saisonId, $date]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['participant_id']] = (bool) (int) $r['present'];
        }
        return $out;
    }

    /** @return array<int,array<string,bool>> participant => date => présent, sur la saison */
    public function grille(int $sectionId, int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT pp.participant_id, pp.date_seance, pp.present FROM presence_participant pp JOIN participant p ON p.id = pp.participant_id
            WHERE p.section_id = ? AND p.saison_id = ?');
        $st->execute([$sectionId, $saisonId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['participant_id']][(string) $r['date_seance']] = (bool) (int) $r['present'];
        }
        return $out;
    }

    /** Enregistre la séance pour les non-inscrits affichés (cochés = présents). @param list<mixed> $presents */
    public function enregistrer(int $sectionId, int $saisonId, string $date, array $presents): void
    {
        $presents = array_map('intval', $presents);
        $sup = $this->pdo->prepare('DELETE FROM presence_participant WHERE participant_id = ? AND date_seance = ?');
        $ins = $this->pdo->prepare('INSERT INTO presence_participant (participant_id, date_seance, present, saisi_le) VALUES (?, ?, ?, ?)');
        foreach ($this->duCours($sectionId, $saisonId) as $p) {
            $id = (int) $p['id'];
            $sup->execute([$id, $date]);
            $ins->execute([$id, $date, in_array($id, $presents, true) ? 1 : 0, date('Y-m-d H:i:s')]);
        }
    }

    /** Marque présent à une date (juste après l'ajout). */
    public function marquerPresent(int $participantId, string $date): void
    {
        $this->pdo->prepare('DELETE FROM presence_participant WHERE participant_id = ? AND date_seance = ?')->execute([$participantId, $date]);
        $this->pdo->prepare('INSERT INTO presence_participant (participant_id, date_seance, present, saisi_le) VALUES (?, ?, 1, ?)')->execute([$participantId, $date, date('Y-m-d H:i:s')]);
    }

    /**
     * Liste du bureau pour la saison : coordonnées, cours, nombre de venues et dates.
     * @return list<array<string,mixed>>
     */
    public function aRelancer(int $saisonId, bool $tous = false): array
    {
        $this->detecterInscrits($saisonId);
        $st = $this->pdo->prepare('SELECT p.*, s.titre AS cours, a.telephone1, a.telephone2, a.mail, a.nom_parent, a.prenom_parent,
                (SELECT COUNT(*) FROM presence_participant pp WHERE pp.participant_id = p.id AND pp.present = 1) AS venues,
                (SELECT MIN(pp.date_seance) FROM presence_participant pp WHERE pp.participant_id = p.id AND pp.present = 1) AS premiere,
                (SELECT MAX(pp.date_seance) FROM presence_participant pp WHERE pp.participant_id = p.id AND pp.present = 1) AS derniere
            FROM participant p JOIN section s ON s.id = p.section_id LEFT JOIN adherent a ON a.id = p.adherent_id
            WHERE p.saison_id = ?' . ($tous ? '' : " AND p.statut IN ('a_relancer','relance')") . ' ORDER BY p.statut, p.nom, p.prenom');
        $st->execute([$saisonId]);
        return $st->fetchAll();
    }

    public function compter(int $saisonId): int
    {
        $this->detecterInscrits($saisonId);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM participant WHERE saison_id = ? AND statut = 'a_relancer'");
        $st->execute([$saisonId]);
        return (int) $st->fetchColumn();
    }

    public function changerStatut(int $id, string $statut): void
    {
        if (!isset(self::STATUTS[$statut])) {
            return;
        }
        $this->pdo->prepare('UPDATE participant SET statut = ?, relance_le = ' . ($statut === 'relance' ? '?' : 'relance_le') . ' WHERE id = ?')
            ->execute($statut === 'relance' ? [$statut, date('Y-m-d H:i:s'), $id] : [$statut, $id]);
    }

    /**
     * Passe en « inscrit » ceux qui ont maintenant une inscription cette saison : par leur fiche,
     * ou, pour une nouvelle personne, par une fiche au même nom et prénom.
     */
    private function detecterInscrits(int $saisonId): void
    {
        $this->pdo->prepare("UPDATE participant SET statut = 'inscrit' WHERE saison_id = ? AND statut IN ('a_relancer','relance') AND (
                (adherent_id IS NOT NULL AND EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = participant.adherent_id AND i.saison_id = participant.saison_id))
             OR (adherent_id IS NULL AND EXISTS (SELECT 1 FROM inscription i JOIN adherent a ON a.id = i.adherent_id
                    WHERE i.saison_id = participant.saison_id AND LOWER(a.nom) = LOWER(participant.nom) AND LOWER(a.prenom) = LOWER(participant.prenom))))")
            ->execute([$saisonId]);
    }
}
