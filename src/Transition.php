<?php
declare(strict_types=1);

namespace Ascs;

use Ascs\Domain\Demandes;
use Ascs\HelloAsso\Sync;
use PDO;

/**
 * Mode transition : WinDev reste la référence et chaque import complet efface les saisies web.
 * Juste après un import, on refait automatiquement ce que le bureau avait validé dans l'application
 * (adhésions HelloAsso et demandes en ligne), sauf si la même chose a été saisie entre-temps dans WinDev.
 */
final class Transition
{
    public static function apresImport(PDO $pdo): string
    {
        $lignes = [];
        try {
            $pdo->beginTransaction();
            $sync = new Sync($pdo);
            $sync->rapprocher();
            $pdo->commit();
            $c = $sync->compteur();
            if ($c['rejouees'] || $c['liens_perdus'] > 0 || $c['deja_present']) {
                $lignes[] = sprintf('HelloAsso : %d adhésion(s) revalidée(s), %d reconnue(s) comme saisie(s) dans WinDev, %d à revalider à la main.',
                    $c['rejouees'], $c['deja_present'], max(0, $c['liens_perdus']));
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $lignes[] = 'HelloAsso : rapprochement impossible (' . $e->getMessage() . ').';
        }
        try {
            $r = (new Demandes($pdo))->rejouerApresImport();
            if ($r['rejouees'] || $r['a_revoir']) {
                $lignes[] = sprintf('Demandes en ligne : %d revalidée(s), %d à revalider à la main.', $r['rejouees'], $r['a_revoir']);
            }
        } catch (\Throwable $e) {
            $lignes[] = 'Demandes en ligne : revalidation impossible (' . $e->getMessage() . ').';
        }
        try {
            $n = (new \Ascs\Domain\Marques($pdo))->rejouerApresImport();
            if ($n) {
                $lignes[] = sprintf('Fiches : %d désinscription(s) ou mail(s) invalide(s) réappliqué(s).', $n);
            }
        } catch (\Throwable $e) {
            $lignes[] = 'Désinscriptions : impossible de les réappliquer (' . $e->getMessage() . ').';
        }
        try {
            $r = (new \Ascs\Domain\Fusion($pdo))->rejouerApresImport();
            if ($r['refaites'] || $r['deja']) {
                $lignes[] = sprintf('Fiches fusionnées : %d fusion(s) refaite(s), %d déjà faite(s) aussi dans WinDev.', $r['refaites'], $r['deja']);
            }
        } catch (\Throwable $e) {
            $lignes[] = 'Fiches fusionnées : fusion impossible à refaire (' . $e->getMessage() . ').';
        }
        try {
            $r = (new \Ascs\Domain\Remises($pdo))->rapprocher();
            if ($r['ventilees'] || $r['ecarts']) {
                $lignes[] = sprintf('Remises de chèques : %d ventilation(s) écrite(s) dans les comptes, %d remise(s) avec un montant différent de la banque.', $r['ventilees'], $r['ecarts']);
            }
        } catch (\Throwable $e) {
            $lignes[] = 'Remises de chèques : rapprochement impossible (' . $e->getMessage() . ').';
        }
        return $lignes ? "\n## Saisies de l'application refaites après l'import\n  - " . implode("\n  - ", $lignes) . "\n" : '';
    }
}
