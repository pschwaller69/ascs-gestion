<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Résultat par section principale pour le comptable, comme le classeur que générait WinDev :
 * pour chaque section, les opérations de banque ventilées sur elle, les règlements des inscriptions de la saison
 * (les espèces, encaissées par le fonctionnement, lui reviennent), et les frais ASCS retenus sur chaque inscription
 * (adhésion + frais de dossier, selon que l'adhérent habite Rillieux ou non), reversés au fonctionnement.
 */
final class ResultatSections
{
    public const CLE_FRAIS = 'frais_ascs';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Frais retenus par inscription pour une saison : réglage enregistré, sinon 10 € + 20/30 € à partir de 2026-2027, 10 € + 15/25 € avant.
     * @return array{adhesion:float,rillieux:float,exterieur:float,regle:bool}
     */
    public function frais(int $saisonId): array
    {
        $tous = json_decode((string) Parametres::get($this->pdo, self::CLE_FRAIS), true) ?: [];
        if (isset($tous[$saisonId])) {
            $f = $tous[$saisonId];
            return ['adhesion' => (float) $f['adhesion'], 'rillieux' => (float) $f['rillieux'], 'exterieur' => (float) $f['exterieur'], 'regle' => true];
        }
        $st = $this->pdo->prepare('SELECT titre FROM saison WHERE id = ?');
        $st->execute([$saisonId]);
        $nouveau = strcmp((string) $st->fetchColumn(), '2026-2027') >= 0;
        return ['adhesion' => 10.0, 'rillieux' => $nouveau ? 20.0 : 15.0, 'exterieur' => $nouveau ? 30.0 : 25.0, 'regle' => false];
    }

    public function reglerFrais(int $saisonId, float $adhesion, float $rillieux, float $exterieur): void
    {
        $tous = json_decode((string) Parametres::get($this->pdo, self::CLE_FRAIS), true) ?: [];
        $tous[$saisonId] = ['adhesion' => round($adhesion, 2), 'rillieux' => round($rillieux, 2), 'exterieur' => round($exterieur, 2)];
        Parametres::set($this->pdo, self::CLE_FRAIS, json_encode($tous));
    }

    /**
     * @param array{du:string,au:string} $periode dates des opérations de banque
     * @return array{saison_id:int,frais:array,sections:list<array>,non_affecte:array{depenses:float,recettes:float},fonctionnement:?int}
     */
    public function calculer(int $saisonId, array $periode): array
    {
        $frais = $this->frais($saisonId);
        $sp = $this->pdo->query('SELECT id, titre, code FROM section_principale ORDER BY titre')->fetchAll();
        $par = [];
        $fonct = null;
        foreach ($sp as $s) {
            $par[(int) $s['id']] = ['id' => (int) $s['id'], 'titre' => (string) $s['titre'], 'code' => (string) ($s['code'] ?: $s['titre']),
                'banque' => [], 'reglements' => [], 'frais' => [], 'inscrits' => 0];
            if (strtoupper((string) $s['code']) === 'BENE' || str_contains(mb_strtolower((string) $s['titre']), 'fonctionnement')) {
                $fonct ??= (int) $s['id'];
            }
        }
        $nonAffecte = ['depenses' => 0.0, 'recettes' => 0.0];

        // Banque : détails de ventilation des opérations de la période (hors virements entre comptes)
        $st = $this->pdo->prepare('SELECT c.date_operation, c.libelle, d.libelle AS detail, d.observation, d.valeur, d.section_principale_id
            FROM detail_compte d JOIN compte c ON c.id = d.compte_id
            WHERE c.date_operation >= ? AND c.date_operation <= ? AND c.virement_interne = 0 ORDER BY c.date_operation, c.id, d.id');
        $st->execute([$periode['du'], $periode['au'] . ' 23:59:59']);
        foreach ($st->fetchAll() as $r) {
            $v = round((float) $r['valeur'], 2);
            $id = (int) $r['section_principale_id'];
            if (!isset($par[$id])) {
                $v < 0 ? $nonAffecte['depenses'] += -$v : $nonAffecte['recettes'] += $v;
                continue;
            }
            $par[$id]['banque'][] = ['date' => substr((string) $r['date_operation'], 0, 10), 'libelle' => (string) $r['libelle'],
                'detail' => trim((string) ($r['detail'] ?: $r['observation'] ?: $r['libelle'])), 'debit' => $v < 0 ? $v : 0.0, 'credit' => $v > 0 ? $v : 0.0];
        }

        // Règlements des inscriptions de la saison
        $st = $this->pdo->prepare('SELECT s.section_principale_id AS sp, r.date_reglement, a.nom, a.prenom, r.montant, m.libelle AS mode
            FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN section s ON s.id = i.section_id JOIN adherent a ON a.id = i.adherent_id
            LEFT JOIN mode_reglement m ON m.id = r.mode_reglement_id WHERE i.saison_id = ? ORDER BY r.date_reglement, a.nom, a.prenom, r.id');
        $st->execute([$saisonId]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($par[(int) $r['sp']])) {
                continue;
            }
            $esp = self::especes((string) $r['mode']);
            $par[(int) $r['sp']]['reglements'][] = ['date' => $r['date_reglement'] ? substr((string) $r['date_reglement'], 0, 10) : null, 'nom' => (string) $r['nom'], 'prenom' => (string) $r['prenom'],
                'montant' => (float) $r['montant'], 'mode' => (string) $r['mode'], 'especes' => $esp ? (float) $r['montant'] : null];
        }

        // Frais ASCS : adhésion + frais de dossier sur chaque inscription payante (hors cours d'essai)
        $st = $this->pdo->prepare('SELECT s.section_principale_id AS sp, a.nom, a.prenom, a.ville, a.code_postal FROM inscription i JOIN section s ON s.id = i.section_id
            JOIN adherent a ON a.id = i.adherent_id WHERE i.saison_id = ? AND s.essai = 0 AND i.montant > 0 ORDER BY a.nom, a.prenom, i.id');
        $st->execute([$saisonId]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($par[(int) $r['sp']])) {
                continue;
            }
            $ril = Tarifs::habitantRillieux($r['ville'], $r['code_postal']);
            $par[(int) $r['sp']]['frais'][] = ['nom' => (string) $r['nom'], 'prenom' => (string) $r['prenom'], 'rillieux' => $ril,
                'adhesion' => $frais['adhesion'], 'dossier' => $ril ? $frais['rillieux'] : $frais['exterieur']];
            $par[(int) $r['sp']]['inscrits']++;
        }

        // Totaux : la section garde ses règlements en espèces et rend adhésions + frais ; le fonctionnement fait l'inverse
        $sections = [];
        $tot = ['especes' => 0.0, 'adhesion' => 0.0, 'dossier' => 0.0];
        foreach ($par as $s) {
            $s['banque_debit'] = round(array_sum(array_column($s['banque'], 'debit')), 2);
            $s['banque_credit'] = round(array_sum(array_column($s['banque'], 'credit')), 2);
            $s['inscriptions'] = round(array_sum(array_column($s['reglements'], 'montant')), 2);
            $s['especes'] = round(array_sum(array_map(static fn ($r) => (float) $r['especes'], $s['reglements'])), 2);
            $s['adhesion'] = round(array_sum(array_column($s['frais'], 'adhesion')), 2);
            $s['dossier'] = round(array_sum(array_column($s['frais'], 'dossier')), 2);
            foreach ($tot as $k => $_) {
                $tot[$k] += $s[$k];
            }
            $sections[$s['id']] = $s;
        }
        foreach ($sections as $id => &$s) {
            $s['fonctionnement'] = $id === $fonct;
            $s['depenses'] = round(-$s['banque_debit'] + $s['adhesion'] + $s['dossier'] + ($s['fonctionnement'] ? $tot['especes'] : 0), 2);
            $s['recettes'] = round($s['banque_credit'] + $s['especes'] + ($s['fonctionnement'] ? $tot['adhesion'] + $tot['dossier'] : 0), 2);
            $s['resultat'] = round($s['recettes'] - $s['depenses'], 2);
            $s['vide'] = !$s['banque'] && !$s['reglements'] && !$s['frais'];
        }
        unset($s);
        return ['saison_id' => $saisonId, 'frais' => $frais, 'fonctionnement' => $fonct, 'non_affecte' => array_map(static fn ($v) => round($v, 2), $nonAffecte),
            'sections' => array_values(array_filter($sections, static fn ($s) => !$s['vide'] || $s['fonctionnement']))];
    }

    public static function especes(string $mode): bool
    {
        return str_starts_with(DuplicateFinder::norm($mode), 'espece');
    }
}
