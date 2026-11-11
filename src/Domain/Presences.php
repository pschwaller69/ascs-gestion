<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Inscrits par section, feuille d'émargement et liste de présence des animateurs.
 * L'animateur ouvre sa liste par un lien secret (jeton de la section), sans mot de passe ;
 * il ne voit que les prénoms, noms, âges et téléphones des inscrits de sa section pour la saison en cours.
 */
final class Presences
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> sections ayant des inscrits cette saison (ou des créneaux), avec leurs effectifs */
    public function sections(int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT s.id, s.titre, s.essai, s.mail_animateur1, s.presence_token, sp.titre AS principale,
                (SELECT COUNT(*) FROM inscription i WHERE i.section_id = s.id AND i.saison_id = ?) AS inscrits,
                (SELECT COUNT(DISTINCT p.date_seance) FROM presence p WHERE p.section_id = s.id AND p.saison_id = ?) AS seances
            FROM section s LEFT JOIN section_principale sp ON sp.id = s.section_principale_id ORDER BY s.essai, s.titre');
        $st->execute([$saisonId, $saisonId]);
        return array_values(array_filter($st->fetchAll(), static fn ($s) => (int) $s['inscrits'] > 0));
    }

    public function section(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT s.*, sp.titre AS principale FROM section s LEFT JOIN section_principale sp ON sp.id = s.section_principale_id WHERE s.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function parJeton(string $jeton): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $jeton)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM section WHERE presence_token = ?');
        $st->execute([strtolower($jeton)]);
        return $st->fetch() ?: null;
    }

    public function nouveauJeton(int $sectionId): string
    {
        $j = Referentiel::uuid();
        $this->pdo->prepare('UPDATE section SET presence_token = ? WHERE id = ?')->execute([$j, $sectionId]);
        return $j;
    }

    /** @return list<array<string,mixed>> inscrits de la section pour la saison, avec contact et état du paiement */
    public function inscrits(int $sectionId, int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT i.id AS inscription_id, i.montant, a.id, a.nom, a.prenom, a.date_naissance, a.genre, a.telephone1, a.telephone2, a.mail,
                a.nom_parent, a.prenom_parent,
                (SELECT COALESCE(SUM(r.montant),0) FROM reglement r WHERE r.inscription_id = i.id) AS regle
            FROM inscription i JOIN adherent a ON a.id = i.adherent_id WHERE i.section_id = ? AND i.saison_id = ? ORDER BY a.nom, a.prenom');
        $st->execute([$sectionId, $saisonId]);
        return $st->fetchAll();
    }

    /** @return list<string> libellés des créneaux (« Mardi 18:30 ») */
    public function creneaux(int $sectionId): array
    {
        return array_map(static fn ($c) => (Referentiel::JOURS[(int) $c['jour']] ?? '?') . ($c['heure_debut'] ? ' ' . substr((string) $c['heure_debut'], 0, 5) : ''),
            (new Referentiel($this->pdo))->creneaux($sectionId));
    }

    /**
     * Dates des prochaines séances d'après les créneaux (jours de la semaine), à partir d'une date.
     * Sans créneau : liste vide (colonnes laissées blanches sur la feuille).
     * @return list<string>
     */
    public function prochainesSeances(int $sectionId, string $depuis, int $nombre): array
    {
        $jours = array_unique(array_map(static fn ($c) => (int) $c['jour'], (new Referentiel($this->pdo))->creneaux($sectionId)));
        if (!$jours) {
            return [];
        }
        $out = [];
        $t = strtotime($depuis);
        for ($i = 0; $i < 400 && count($out) < $nombre; $i++, $t = strtotime('+1 day', $t)) {
            if (in_array((int) date('N', $t), $jours, true)) {
                $out[] = date('Y-m-d', $t);
            }
        }
        return $out;
    }

    /** @return array<int,bool> adhérent => présent, pour une séance */
    public function seance(int $sectionId, int $saisonId, string $date): array
    {
        $st = $this->pdo->prepare('SELECT adherent_id, present FROM presence WHERE section_id = ? AND saison_id = ? AND date_seance = ?');
        $st->execute([$sectionId, $saisonId, $date]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['adherent_id']] = (bool) $r['present'];
        }
        return $out;
    }

    /** @return list<array{date_seance:string,presents:int,total:int}> séances déjà notées, la plus récente d'abord */
    public function seances(int $sectionId, int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT date_seance, SUM(present) AS presents, COUNT(*) AS total FROM presence WHERE section_id = ? AND saison_id = ? GROUP BY date_seance ORDER BY date_seance DESC');
        $st->execute([$sectionId, $saisonId]);
        return $st->fetchAll();
    }

    /** @return array<int,int> adhérent => nombre de présences sur la saison */
    public function assiduite(int $sectionId, int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT adherent_id, SUM(present) FROM presence WHERE section_id = ? AND saison_id = ? GROUP BY adherent_id');
        $st->execute([$sectionId, $saisonId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Enregistre une séance : chaque inscrit est noté présent ou absent (les non-inscrits envoyés sont ignorés).
     * @param list<int|string> $presents identifiants des adhérents cochés
     */
    public function enregistrer(int $sectionId, int $saisonId, string $date, array $presents, string $par): int
    {
        $ids = array_map(static fn ($r) => (int) $r['id'], $this->inscrits($sectionId, $saisonId));
        $presents = array_map('intval', $presents);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM presence WHERE section_id = ? AND saison_id = ? AND date_seance = ?')->execute([$sectionId, $saisonId, $date]);
        $ins = $this->pdo->prepare('INSERT INTO presence (section_id, saison_id, date_seance, adherent_id, present, saisi_le, saisi_par) VALUES (?,?,?,?,?,?,?)');
        $n = 0;
        foreach ($ids as $id) {
            $p = in_array($id, $presents, true);
            $n += (int) $p;
            $ins->execute([$sectionId, $saisonId, $date, $id, $p ? 1 : 0, date('Y-m-d H:i:s'), mb_substr($par, 0, 150)]);
        }
        $this->pdo->commit();
        return $n;
    }

    /** Saison en cours d'après la date du jour (sinon la plus récente). */
    public function saisonCourante(): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM saison WHERE date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?) ORDER BY date_debut DESC LIMIT 1');
        $st->execute([date('Y-m-d'), date('Y-m-d')]);
        return $st->fetch() ?: ($this->pdo->query('SELECT * FROM saison ORDER BY date_debut DESC LIMIT 1')->fetch() ?: null);
    }
}
