<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Nombre de places d'une activité (capacité de la salle). Limite les inscriptions en ligne : une activité complète
 * n'est plus proposée. Depuis le bureau, rien n'est bloqué : un message signale le dépassement.
 * Occupées = inscriptions de la saison + demandes en ligne encore à traiter qui la demandent.
 */
final class Places
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,int> places par section (sections sans limite absentes) */
    public function capacites(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT section_id, places FROM section_capacite WHERE places IS NOT NULL AND places > 0')->fetchAll() as $r) {
            $out[(int) $r['section_id']] = (int) $r['places'];
        }
        return $out;
    }

    public function capacite(int $sectionId): ?int
    {
        return $this->capacites()[$sectionId] ?? null;
    }

    public function enregistrer(int $sectionId, ?int $places): void
    {
        \Ascs\Db::upsertStatement($this->pdo, 'section_capacite', ['section_id', 'places'], ['section_id'])
            ->execute([$sectionId, $places !== null && $places > 0 ? $places : null]);
    }

    /** @return array<int,int> inscriptions de la saison par section */
    public function inscrits(int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT section_id, COUNT(*) FROM inscription WHERE saison_id = ? GROUP BY section_id');
        $st->execute([$saisonId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<int,int> demandes d'inscription en ligne à traiter par section */
    public function enAttente(int $saisonId, ?int $saufDemande = null): array
    {
        $st = $this->pdo->prepare("SELECT id, sections FROM demande WHERE type = 'inscription' AND statut = 'a_traiter' AND saison_id = ?");
        $st->execute([$saisonId]);
        $out = [];
        foreach ($st->fetchAll() as $d) {
            if ((int) $d['id'] === $saufDemande) {
                continue;
            }
            foreach (json_decode((string) $d['sections'], true) ?: [] as $sid) {
                $out[(int) $sid] = ($out[(int) $sid] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * État de chaque activité limitée. @return array<int,array{places:int,inscrits:int,attente:int,libres:int}>
     */
    public function etat(int $saisonId, ?int $saufDemande = null): array
    {
        $ins = $this->inscrits($saisonId);
        $att = $this->enAttente($saisonId, $saufDemande);
        $out = [];
        foreach ($this->capacites() as $sid => $p) {
            $i = $ins[$sid] ?? 0;
            $a = $att[$sid] ?? 0;
            $out[$sid] = ['places' => $p, 'inscrits' => $i, 'attente' => $a, 'libres' => $p - $i - $a];
        }
        return $out;
    }

    /** Activités complètes pour les inscriptions en ligne. @return list<int> */
    public function completes(int $saisonId): array
    {
        return array_keys(array_filter($this->etat($saisonId), static fn ($e) => $e['libres'] <= 0));
    }

    /**
     * Message pour le bureau quand une inscription dépasse (ou atteint) la capacité ; null sinon.
     * Compte les inscriptions déjà enregistrées (celle qu'on vient de créer comprise).
     */
    public function alerte(int $sectionId, int $saisonId): ?string
    {
        $p = $this->capacite($sectionId);
        if ($p === null) {
            return null;
        }
        $i = $this->inscrits($saisonId)[$sectionId] ?? 0;
        $titre = (string) $this->pdo->query('SELECT titre FROM section WHERE id = ' . $sectionId)->fetchColumn();
        if ($i > $p) {
            return $titre . ' : ' . $i . ' inscrits pour ' . $p . ' places, capacité de la salle dépassée de ' . ($i - $p) . '.';
        }
        return $i === $p ? $titre . ' : ' . $i . ' inscrits pour ' . $p . ' places, l\'activité est maintenant complète.' : null;
    }
}
