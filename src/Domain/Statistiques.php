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
}
