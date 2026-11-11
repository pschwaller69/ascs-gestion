<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Changement de cours d'un adhérent par l'animateur (erreur de saisie, passage dans un groupe plus fort…) :
 * l'inscription passe dans une autre section de la même activité (même section principale), même saison.
 * Le montant de l'inscription ne change pas ; le bureau voit chaque changement et peut l'annuler.
 * En mode transition, les changements sont refaits après chaque import WinDev tant qu'ils n'y sont pas reportés.
 */
final class Changements
{
    public const MOTIFS = ['erreur' => 'Erreur d\'inscription', 'niveau' => 'Change de niveau (groupe plus fort ou plus adapté)', 'horaire' => 'Change d\'horaire', 'autre' => 'Autre raison'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Cours vers lesquels un inscrit de cette section peut passer : même section principale, hors cours d'essai. @return list<array<string,mixed>> */
    public function cibles(int $sectionId, int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT s.id, s.titre, s.montant, (SELECT COUNT(*) FROM inscription i WHERE i.section_id = s.id AND i.saison_id = ?) AS inscrits
            FROM section s JOIN section src ON src.id = ? WHERE s.section_principale_id = src.section_principale_id AND src.section_principale_id IS NOT NULL
            AND s.id <> src.id AND s.essai = 0 AND src.essai = 0 ORDER BY s.titre');
        $st->execute([$saisonId, $sectionId]);
        $pr = new Presences($this->pdo);
        $cartes = array_keys((new Cartes($this->pdo))->reglages());
        return array_values(array_map(static fn ($s) => $s + ['creneaux' => $pr->creneaux((int) $s['id'])],
            array_filter($st->fetchAll(), static fn ($s) => !in_array((int) $s['id'], $cartes, true))));
    }

    /**
     * Passe l'inscription dans un autre cours. Renvoie un message d'erreur, ou null si c'est fait.
     */
    public function changer(int $inscriptionId, int $depuisSectionId, int $versSectionId, int $saisonId, string $motif, string $precision, string $par): ?string
    {
        $st = $this->pdo->prepare('SELECT i.*, a.nom, a.prenom FROM inscription i JOIN adherent a ON a.id = i.adherent_id WHERE i.id = ? AND i.section_id = ? AND i.saison_id = ?');
        $st->execute([$inscriptionId, $depuisSectionId, $saisonId]);
        $ins = $st->fetch();
        if (!$ins) {
            return 'Cet adhérent n\'est plus inscrit dans ce cours (déjà changé ?).';
        }
        if (!in_array($versSectionId, array_map(static fn ($c) => (int) $c['id'], $this->cibles($depuisSectionId, $saisonId)), true)) {
            return 'Choisissez un des cours proposés.';
        }
        if (!isset(self::MOTIFS[$motif])) {
            return 'Indiquez la raison du changement.';
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM inscription WHERE adherent_id = ? AND section_id = ? AND saison_id = ?');
        $st->execute([$ins['adherent_id'], $versSectionId, $saisonId]);
        if ((int) $st->fetchColumn() > 0) {
            return $ins['prenom'] . ' ' . $ins['nom'] . ' est déjà inscrit(e) dans ce cours : le bureau doit retirer l\'inscription en double.';
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE inscription SET section_id = ? WHERE id = ?')->execute([$versSectionId, $inscriptionId]);
            $this->pdo->prepare('INSERT INTO changement_cours (date_changement, inscription_id, adherent_id, saison_id, de_section_id, vers_section_id, motif, precision_motif, par, vu, annule)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
                ->execute([date('Y-m-d H:i:s'), $inscriptionId, $ins['adherent_id'], $saisonId, $depuisSectionId, $versSectionId, $motif, mb_substr(trim($precision), 0, 255) ?: null, mb_substr($par, 0, 150)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return null;
    }

    /** Changements de la saison, les plus récents d'abord, avec noms et tarifs. @return list<array<string,mixed>> */
    public function liste(int $saisonId, bool $nonVusSeulement = false): array
    {
        $st = $this->pdo->prepare('SELECT c.*, a.nom, a.prenom, d.titre AS de_titre, v.titre AS vers_titre, d.montant AS de_montant, v.montant AS vers_montant
            FROM changement_cours c LEFT JOIN adherent a ON a.id = c.adherent_id LEFT JOIN section d ON d.id = c.de_section_id LEFT JOIN section v ON v.id = c.vers_section_id
            WHERE c.saison_id = ?' . ($nonVusSeulement ? ' AND c.vu = 0 AND c.annule = 0' : '') . ' ORDER BY c.id DESC');
        $st->execute([$saisonId]);
        return $st->fetchAll();
    }

    public function marquerVu(int $id): void
    {
        $this->pdo->prepare('UPDATE changement_cours SET vu = 1 WHERE id = ?')->execute([$id]);
    }

    /** Remet l'adhérent dans son cours d'origine. Renvoie un message d'erreur ou null. */
    public function annuler(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT * FROM changement_cours WHERE id = ? AND annule = 0');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return 'Changement introuvable ou déjà annulé.';
        }
        $adh = $this->adherentActuel((int) $c['adherent_id']);
        $st = $this->pdo->prepare('SELECT id FROM inscription WHERE adherent_id = ? AND section_id = ? AND saison_id = ?');
        $st->execute([$adh, $c['vers_section_id'], $c['saison_id']]);
        $ins = $st->fetchColumn();
        $st->execute([$adh, $c['de_section_id'], $c['saison_id']]);
        if ($st->fetchColumn()) {
            $ins = false;
        }
        $this->pdo->beginTransaction();
        if ($ins) {
            $this->pdo->prepare('UPDATE inscription SET section_id = ? WHERE id = ?')->execute([$c['de_section_id'], $ins]);
        }
        $this->pdo->prepare('UPDATE changement_cours SET annule = 1, vu = 1 WHERE id = ?')->execute([$id]);
        $this->pdo->commit();
        return $ins ? null : 'L\'inscription a été modifiée depuis : le changement est annulé, mais vérifiez le cours de l\'adhérent.';
    }

    /**
     * Mode transition : refait les changements après un import WinDev (qui remet les inscriptions de WinDev).
     * @return array{refaits:int,deja:int,perdus:int}
     */
    public function rejouerApresImport(): array
    {
        $r = ['refaits' => 0, 'deja' => 0, 'perdus' => 0];
        $trouve = $this->pdo->prepare('SELECT id FROM inscription WHERE adherent_id = ? AND section_id = ? AND saison_id = ?');
        $maj = $this->pdo->prepare('UPDATE inscription SET section_id = ? WHERE id = ?');
        $lien = $this->pdo->prepare('UPDATE changement_cours SET inscription_id = ? WHERE id = ?');
        foreach ($this->pdo->query('SELECT * FROM changement_cours WHERE annule = 0 ORDER BY id')->fetchAll() as $c) {
            $adh = $this->adherentActuel((int) $c['adherent_id']);
            $trouve->execute([$adh, $c['vers_section_id'], $c['saison_id']]);
            if ($id = $trouve->fetchColumn()) {
                $r['deja']++;
                $lien->execute([$id, $c['id']]);
                continue;
            }
            $trouve->execute([$adh, $c['de_section_id'], $c['saison_id']]);
            if ($id = $trouve->fetchColumn()) {
                $maj->execute([$c['vers_section_id'], $id]);
                $lien->execute([$id, $c['id']]);
                $r['refaits']++;
            } else {
                $r['perdus']++;
            }
        }
        return $r;
    }

    /** Fiche à utiliser si l'adhérent a été fusionné dans une autre depuis. */
    private function adherentActuel(int $id): int
    {
        $st = $this->pdo->prepare('SELECT garde_id FROM fusion WHERE fusionne_id = ? ORDER BY id DESC LIMIT 1');
        for ($i = 0; $i < 5; $i++) {
            $st->execute([$id]);
            $g = $st->fetchColumn();
            if (!$g) {
                break;
            }
            $id = (int) $g;
        }
        return $id;
    }
}
