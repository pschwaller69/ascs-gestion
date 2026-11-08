<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/** Chiffres du tableau de bord et des résultats par section (inscriptions et comptes de la saison). */
final class Statistiques
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private function col(string $sql, array $args = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    private function lignes(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** La saison et les deux précédentes. @return list<array{id:int,titre:string,inscrits:int}> */
    public function inscritsTroisSaisons(int $saisonId): array
    {
        $saisons = $this->lignes('SELECT id, titre FROM saison WHERE date_debut <= (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 3', [$saisonId]);
        foreach ($saisons as &$s) {
            $s['inscrits'] = (int) $this->col('SELECT COUNT(DISTINCT i.adherent_id) FROM inscription i JOIN section s ON s.id = i.section_id WHERE i.saison_id = ? AND s.essai = 0', [$s['id']]);
        }
        return $saisons;
    }

    /** Adhérents inscrits par famille (une personne inscrite dans deux familles compte dans chacune). @return list<array{titre:string,n:int}> */
    public function parFamille(int $saisonId): array
    {
        return $this->lignes('SELECT COALESCE(f.titre, \'Sans famille\') AS titre, COUNT(DISTINCT i.adherent_id) AS n
            FROM inscription i JOIN section s ON s.id = i.section_id
            LEFT JOIN section_principale sp ON sp.id = s.section_principale_id LEFT JOIN famille_section f ON f.id = sp.famille_section_id
            WHERE i.saison_id = ? AND s.essai = 0 GROUP BY f.titre ORDER BY n DESC', [$saisonId]);
    }

    /** @return array{femmes:int,hommes:int} adhérents distincts de la saison */
    public function genre(int $saisonId): array
    {
        $r = $this->lignes('SELECT a.genre, COUNT(DISTINCT a.id) AS n FROM inscription i JOIN adherent a ON a.id = i.adherent_id JOIN section s ON s.id = i.section_id
            WHERE i.saison_id = ? AND s.essai = 0 GROUP BY a.genre', [$saisonId]);
        $g = ['femmes' => 0, 'hommes' => 0];
        foreach ($r as $l) {
            $g[(int) $l['genre'] === 0 ? 'femmes' : 'hommes'] += (int) $l['n'];
        }
        return $g;
    }

    public function nonPayees(int $saisonId): int
    {
        return (int) $this->col('SELECT COUNT(*) FROM inscription i WHERE i.saison_id = ? AND i.montant > 0
            AND i.montant - (SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id) > 0.005', [$saisonId]);
    }

    /** Recettes et dépenses de la saison d'après les lignes de compte (virements internes exclus). @return array{recettes:float,depenses:float,resultat:float,lignes:int} */
    public function comptes(int $saisonId): array
    {
        $r = $this->lignes('SELECT COALESCE(SUM(credit),0) AS c, COALESCE(SUM(debit),0) AS d, COUNT(*) AS n FROM compte WHERE saison_id = ? AND virement_interne = 0', [$saisonId])[0];
        return ['recettes' => (float) $r['c'], 'depenses' => -(float) $r['d'], 'resultat' => (float) $r['c'] + (float) $r['d'], 'lignes' => (int) $r['n']];
    }

    /** Montants ventilés par catégorie (type de compte). @return array{recettes:list<array{titre:string,montant:float}>,depenses:list<array{titre:string,montant:float}>} */
    public function parCategorie(int $saisonId): array
    {
        $r = $this->lignes('SELECT COALESCE(t.libelle, \'Non ventilé\') AS titre, SUM(d.credit) AS c, SUM(d.debit) AS dd
            FROM detail_compte d JOIN compte c ON c.id = d.compte_id LEFT JOIN type_compte t ON t.id = d.type_compte_id
            WHERE c.saison_id = ? AND c.virement_interne = 0 GROUP BY t.libelle', [$saisonId]);
        $out = ['recettes' => [], 'depenses' => []];
        foreach ($r as $l) {
            if ((float) $l['c'] > 0.005) {
                $out['recettes'][] = ['titre' => $l['titre'], 'montant' => (float) $l['c']];
            }
            if ((float) $l['dd'] < -0.005) {
                $out['depenses'][] = ['titre' => $l['titre'], 'montant' => -(float) $l['dd']];
            }
        }
        foreach ($out as &$liste) {
            usort($liste, static fn ($a, $b) => $b['montant'] <=> $a['montant']);
        }
        return $out;
    }

    /** Résultat par section principale : inscriptions de la saison, recettes et dépenses ventilées, seuil de rentabilité. */
    public function resultatSectionsPrincipales(int $saisonId): array
    {
        $sp = $this->lignes('SELECT sp.id, sp.titre, sp.montant_rentabilite_annuel AS seuil, f.titre AS famille FROM section_principale sp LEFT JOIN famille_section f ON f.id = sp.famille_section_id ORDER BY sp.titre');
        $insc = [];
        foreach ($this->lignes('SELECT s.section_principale_id AS id, COUNT(*) AS n, SUM(i.montant) AS m FROM inscription i JOIN section s ON s.id = i.section_id WHERE i.saison_id = ? AND s.essai = 0 GROUP BY s.section_principale_id', [$saisonId]) as $l) {
            $insc[(int) $l['id']] = $l;
        }
        $cpt = [];
        foreach ($this->lignes('SELECT d.section_principale_id AS id, SUM(d.credit) AS c, SUM(d.debit) AS d FROM detail_compte d JOIN compte c ON c.id = d.compte_id WHERE c.saison_id = ? AND c.virement_interne = 0 GROUP BY d.section_principale_id', [$saisonId]) as $l) {
            $cpt[(int) $l['id']] = $l;
        }
        $out = [];
        foreach ($sp as $s) {
            $id = (int) $s['id'];
            $rec = (float) ($cpt[$id]['c'] ?? 0);
            $dep = -(float) ($cpt[$id]['d'] ?? 0);
            if (!isset($insc[$id]) && !isset($cpt[$id])) {
                continue;
            }
            $out[] = $s + ['inscriptions' => (int) ($insc[$id]['n'] ?? 0), 'montant_inscriptions' => (float) ($insc[$id]['m'] ?? 0),
                'recettes' => $rec, 'depenses' => $dep, 'resultat' => $rec - $dep];
        }
        return $out;
    }

    /** Résultat par section : inscrits, montant des inscriptions, réglé, seuil de rentabilité. */
    public function resultatSections(int $saisonId): array
    {
        return $this->lignes('SELECT s.id, s.titre, s.montant AS tarif, s.montant_rentabilite_annuel AS seuil, sp.titre AS principale,
                COUNT(i.id) AS inscrits, COALESCE(SUM(i.montant),0) AS montant,
                COALESCE(SUM((SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id)),0) AS regle
            FROM section s JOIN inscription i ON i.section_id = s.id AND i.saison_id = ?
            LEFT JOIN section_principale sp ON sp.id = s.section_principale_id
            WHERE s.essai = 0 GROUP BY s.id, s.titre, s.montant, s.montant_rentabilite_annuel, sp.titre ORDER BY s.titre', [$saisonId]);
    }

    /** Tranche d'âge au 1er janvier de l'année de début de saison (habitude des dossiers de subvention). */
    public static function tranche(?string $naissance, string $debutSaison): string
    {
        if (!$naissance) {
            return 'Âge inconnu';
        }
        $age = (int) substr($debutSaison, 0, 4) - (int) substr($naissance, 0, 4);
        return match (true) {
            $age < 0 || $age > 110 => 'Âge inconnu',
            $age < 12 => 'Moins de 12 ans',
            $age < 18 => '12 à 17 ans',
            $age < 26 => '18 à 25 ans',
            $age < 60 => '26 à 59 ans',
            default => '60 ans et plus',
        };
    }

    public const TRANCHES = ['Moins de 12 ans', '12 à 17 ans', '18 à 25 ans', '26 à 59 ans', '60 ans et plus', 'Âge inconnu'];

    /**
     * Adhérents distincts de la saison (cours d'essai exclus) : genre, tranche d'âge, Rillieux / extérieurs, nouveaux / réinscrits.
     * @return array<string,mixed>
     */
    public function adherents(int $saisonId): array
    {
        $s = $this->lignes('SELECT * FROM saison WHERE id = ?', [$saisonId])[0] ?? null;
        if (!$s) {
            return [];
        }
        $prec = $this->col('SELECT id FROM saison WHERE date_debut < ? ORDER BY date_debut DESC LIMIT 1', [$s['date_debut']]);
        $rows = $this->lignes('SELECT DISTINCT a.id, a.genre, a.date_naissance, a.ville, a.code_postal FROM inscription i JOIN adherent a ON a.id = i.adherent_id
            JOIN section se ON se.id = i.section_id WHERE i.saison_id = ? AND se.essai = 0', [$saisonId]);
        $avant = $prec ? array_flip(array_map('intval', array_column($this->lignes('SELECT DISTINCT adherent_id FROM inscription WHERE saison_id = ?', [$prec]), 'adherent_id'))) : [];
        $deja = array_flip(array_map('intval', array_column($this->lignes('SELECT DISTINCT i.adherent_id FROM inscription i JOIN saison s ON s.id = i.saison_id WHERE s.date_debut < ?', [$s['date_debut']]), 'adherent_id')));
        $r = ['saison' => $s, 'total' => count($rows), 'femmes' => 0, 'hommes' => 0, 'rillieux' => 0, 'exterieurs' => 0, 'nouveaux' => 0, 'reinscrits' => 0, 'retour' => 0,
            'tranches' => array_fill_keys(self::TRANCHES, ['f' => 0, 'h' => 0, 'rillieux' => 0])];
        foreach ($rows as $a) {
            $g = (int) $a['genre'] === 0 ? 'f' : 'h';
            $r[$g === 'f' ? 'femmes' : 'hommes']++;
            $rill = Tarifs::habitantRillieux($a['ville'], $a['code_postal']);
            $r[$rill ? 'rillieux' : 'exterieurs']++;
            $t = self::tranche($a['date_naissance'], (string) $s['date_debut']);
            $r['tranches'][$t][$g]++;
            $r['tranches'][$t]['rillieux'] += (int) $rill;
            $id = (int) $a['id'];
            if (isset($avant[$id])) {
                $r['reinscrits']++;
            } elseif (isset($deja[$id])) {
                $r['retour']++;
            } else {
                $r['nouveaux']++;
            }
        }
        return $r;
    }

    /** Inscriptions par section sur les trois dernières saisons. @return array{saisons:list<array>,lignes:list<array>} */
    public function parSectionTroisSaisons(int $saisonId): array
    {
        $saisons = array_reverse($this->lignes('SELECT id, titre FROM saison WHERE date_debut <= (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 3', [$saisonId]));
        $ids = array_map(static fn ($x) => (int) $x['id'], $saisons);
        if (!$ids) {
            return ['saisons' => [], 'lignes' => []];
        }
        $rows = $this->lignes('SELECT s.id, s.titre, sp.titre AS principale, f.titre AS famille, i.saison_id, COUNT(*) AS n FROM inscription i JOIN section s ON s.id = i.section_id
            LEFT JOIN section_principale sp ON sp.id = s.section_principale_id LEFT JOIN famille_section f ON f.id = sp.famille_section_id
            WHERE s.essai = 0 AND i.saison_id IN (' . implode(',', $ids) . ') GROUP BY s.id, s.titre, sp.titre, f.titre, i.saison_id');
        $l = [];
        foreach ($rows as $r) {
            $l[(int) $r['id']] ??= ['id' => (int) $r['id'], 'titre' => $r['titre'], 'principale' => $r['principale'], 'famille' => $r['famille'], 'n' => array_fill_keys($ids, 0)];
            $l[(int) $r['id']]['n'][(int) $r['saison_id']] = (int) $r['n'];
        }
        $l = array_values($l);
        usort($l, static fn ($a, $b) => [$a['famille'], $a['titre']] <=> [$b['famille'], $b['titre']]);
        return ['saisons' => $saisons, 'lignes' => $l];
    }

    /**
     * Courbe d'inscriptions : nombre cumulé d'inscriptions par semaine depuis le début de chaque saison (3 saisons).
     * @return list<array{titre:string,points:list<int>,courante:bool}>
     */
    public function courbe(int $saisonId, int $semaines = 52): array
    {
        $saisons = array_reverse($this->lignes('SELECT id, titre, date_debut FROM saison WHERE date_debut <= (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 3', [$saisonId]));
        $out = [];
        foreach ($saisons as $s) {
            // Les inscriptions prises avant le début officiel (préinscriptions de juin…) comptent en semaine 0
            $debut = strtotime((string) $s['date_debut']);
            $pts = array_fill(0, $semaines, 0);
            foreach ($this->lignes('SELECT COALESCE(i.date_inscription, i.date_creation) AS d FROM inscription i JOIN section s ON s.id = i.section_id WHERE i.saison_id = ? AND s.essai = 0', [$s['id']]) as $r) {
                $t = $r['d'] ? strtotime(substr((string) $r['d'], 0, 10)) : $debut;
                $w = max(0, min($semaines - 1, intdiv((int) (($t - $debut) / 86400), 7)));
                $pts[$w]++;
            }
            $cumul = 0;
            foreach ($pts as $k => $n) {
                $cumul += $n;
                $pts[$k] = $cumul;
            }
            $out[] = ['titre' => $s['titre'], 'points' => $pts, 'courante' => (int) $s['id'] === $saisonId, 'debut' => $s['date_debut']];
        }
        return $out;
    }

    /**
     * Reporting mairie : adhérents de la saison par activité (section principale), Rillieux / extérieurs, mineurs / majeurs, femmes / hommes.
     * @return list<array<string,mixed>>
     */
    public function mairie(int $saisonId): array
    {
        $s = $this->lignes('SELECT date_debut FROM saison WHERE id = ?', [$saisonId])[0] ?? null;
        if (!$s) {
            return [];
        }
        $rows = $this->lignes('SELECT DISTINCT sp.id AS sp, sp.titre AS activite, f.titre AS famille, a.id, a.genre, a.date_naissance, a.ville, a.code_postal
            FROM inscription i JOIN adherent a ON a.id = i.adherent_id JOIN section s ON s.id = i.section_id
            LEFT JOIN section_principale sp ON sp.id = s.section_principale_id LEFT JOIN famille_section f ON f.id = sp.famille_section_id
            WHERE i.saison_id = ? AND s.essai = 0', [$saisonId]);
        $out = [];
        $an = (int) substr((string) $s['date_debut'], 0, 4);
        foreach ($rows as $r) {
            $k = (int) $r['sp'];
            $out[$k] ??= ['activite' => $r['activite'] ?? 'Sans section principale', 'famille' => $r['famille'], 'total' => 0, 'rillieux' => 0, 'exterieurs' => 0,
                'mineurs' => 0, 'majeurs' => 0, 'femmes' => 0, 'hommes' => 0, 'mineurs_rillieux' => 0];
            $o = &$out[$k];
            $o['total']++;
            $rill = Tarifs::habitantRillieux($r['ville'], $r['code_postal']);
            $o[$rill ? 'rillieux' : 'exterieurs']++;
            $mineur = $r['date_naissance'] && $an - (int) substr((string) $r['date_naissance'], 0, 4) < 18;
            $o[$mineur ? 'mineurs' : 'majeurs']++;
            $o['mineurs_rillieux'] += (int) ($mineur && $rill);
            $o[(int) $r['genre'] === 0 ? 'femmes' : 'hommes']++;
            unset($o);
        }
        $out = array_values($out);
        usort($out, static fn ($a, $b) => [$a['famille'], $a['activite']] <=> [$b['famille'], $b['activite']]);
        return $out;
    }
}
