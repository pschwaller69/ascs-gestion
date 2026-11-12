<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Règles d'affectation automatique des opérations de banque, comme le « traitement automatique » de WinDev :
 * un libellé qui commence par (ou contient) un texte, éventuellement un montant exact, est ventilé sur une ou
 * plusieurs sections principales (parts en %, par exemple 50 / 50 entre la batterie et le taekwondo).
 * Les règles passent avant la proposition tirée de l'historique ; la première règle qui correspond s'applique.
 */
final class RegleBanque
{
    public const MODES = ['debut' => 'commence par', 'contient' => 'contient'];

    /** @var list<array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> règles, dans l'ordre d'application */
    public function liste(bool $actives = false): array
    {
        $this->initialiser();
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM regle_banque' . ($actives ? ' WHERE actif = 1' : '') . ' ORDER BY ordre, id')->fetchAll() as $r) {
            $r['lignes'] = json_decode((string) $r['lignes'], true) ?: [];
            $out[] = $r;
        }
        return $out;
    }

    public function trouverParId(int $id): ?array
    {
        foreach ($this->liste() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public static function correspond(array $r, string $libelle, float $valeur): bool
    {
        $lib = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $libelle) ?? ''));
        $motif = mb_strtoupper(trim((string) $r['motif']));
        if ($motif === '') {
            return false;
        }
        $ok = $r['mode'] === 'contient' ? str_contains($lib, $motif) : str_starts_with($lib, $motif);
        return $ok && ($r['montant'] === null || abs(abs($valeur) - abs((float) $r['montant'])) < 0.005);
    }

    /**
     * Ventilation donnée par la première règle active qui correspond, au format des propositions de Banque.
     * @return ?array{lignes:list<array{section_principale_id:int,type_compte_id:int,part:float,observation:string,libelle:string}>,observation:string,regle:int}
     */
    public function proposition(string $libelle, float $valeur): ?array
    {
        $this->cache ??= $this->liste(true);
        foreach ($this->cache as $r) {
            if (!self::correspond($r, $libelle, $valeur) || !$r['lignes']) {
                continue;
            }
            return ['regle' => (int) $r['id'], 'observation' => (string) $r['libelle'], 'lignes' => array_map(static fn ($l) => [
                'section_principale_id' => (int) $l['sp'], 'type_compte_id' => (int) $l['tc'], 'part' => (float) $l['part'] / 100,
                'observation' => (string) $r['libelle'], 'libelle' => (string) (($l['libelle'] ?? '') ?: $r['libelle']),
            ], $r['lignes'])];
        }
        return null;
    }

    /**
     * Contrôle et enregistre une règle. Renvoie un message d'erreur ou null.
     * @param list<array{sp:mixed,tc:mixed,part:mixed,libelle?:mixed}> $lignes
     */
    public function enregistrer(?int $id, string $motif, string $mode, ?float $montant, string $libelle, array $lignes, bool $actif): ?string
    {
        $motif = trim(preg_replace('/\s+/', ' ', $motif) ?? '');
        $libelle = mb_substr(trim($libelle), 0, 255);
        if (mb_strlen($motif) < 3) {
            return 'Indiquez au moins 3 caractères du libellé de la banque.';
        }
        if (!isset(self::MODES[$mode])) {
            return 'Choisissez « commence par » ou « contient ».';
        }
        if ($libelle === '') {
            return 'Indiquez le libellé à mettre sur la ventilation (par exemple « Facture Sophrologie »).';
        }
        $sp = array_map('intval', $this->pdo->query('SELECT id FROM section_principale')->fetchAll(PDO::FETCH_COLUMN));
        $tc = array_map('intval', $this->pdo->query('SELECT id FROM type_compte')->fetchAll(PDO::FETCH_COLUMN));
        $propres = [];
        foreach ($lignes as $l) {
            if ((int) ($l['sp'] ?? 0) === 0 && (int) ($l['tc'] ?? 0) === 0) {
                continue;
            }
            if (!in_array((int) $l['sp'], $sp, true) || !in_array((int) $l['tc'], $tc, true)) {
                return 'Chaque ligne de ventilation doit avoir une section et une catégorie.';
            }
            $part = (float) str_replace([',', '%', ' '], ['.', '', ''], (string) ($l['part'] ?? ''));
            if ($part <= 0 || $part > 100) {
                return 'Les parts sont en % (entre 0 et 100).';
            }
            $propres[] = ['sp' => (int) $l['sp'], 'tc' => (int) $l['tc'], 'part' => round($part, 2), 'libelle' => mb_substr(trim((string) ($l['libelle'] ?? '')), 0, 255)];
        }
        if (!$propres) {
            return 'Indiquez au moins une section et une catégorie.';
        }
        if (abs(array_sum(array_column($propres, 'part')) - 100) > 0.01) {
            return 'Le total des parts doit faire 100 %.';
        }
        $json = json_encode($propres, JSON_UNESCAPED_UNICODE);
        if ($id) {
            $this->pdo->prepare('UPDATE regle_banque SET motif = ?, mode = ?, montant = ?, libelle = ?, lignes = ?, actif = ? WHERE id = ?')
                ->execute([mb_substr($motif, 0, 150), $mode, $montant, $libelle, $json, $actif ? 1 : 0, $id]);
        } else {
            $ordre = (int) $this->pdo->query('SELECT COALESCE(MAX(ordre), 0) + 10 FROM regle_banque')->fetchColumn();
            $this->pdo->prepare('INSERT INTO regle_banque (ordre, motif, mode, montant, libelle, lignes, actif, cree_le) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$ordre, mb_substr($motif, 0, 150), $mode, $montant, $libelle, $json, $actif ? 1 : 0, date('Y-m-d H:i:s')]);
        }
        $this->cache = null;
        return null;
    }

    public function supprimer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM regle_banque WHERE id = ?')->execute([$id]);
        $this->cache = null;
    }

    /** Monte ou descend une règle d'un cran. */
    public function deplacer(int $id, int $sens): void
    {
        $ids = array_map(static fn ($r) => (int) $r['id'], $this->liste());
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : $i + ($sens < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $st = $this->pdo->prepare('UPDATE regle_banque SET ordre = ? WHERE id = ?');
        foreach ($ids as $k => $x) {
            $st->execute([($k + 1) * 10, $x]);
        }
        $this->cache = null;
    }

    /**
     * Opérations de la saison, non ventilées (hors virements internes et remises de chèques), avec la règle qui s'y applique.
     * @return list<array{id:int,date:string,libelle:string,valeur:float,regle:?array}>
     */
    public function nonVentilees(?int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT c.id, c.date_operation, c.libelle, c.valeur FROM compte c WHERE c.virement_interne = 0' . ($saisonId ? ' AND c.saison_id = ?' : '') . '
            AND NOT EXISTS (SELECT 1 FROM detail_compte d WHERE d.compte_id = c.id) ORDER BY c.date_operation, c.id');
        $st->execute($saisonId ? [$saisonId] : []);
        $out = [];
        foreach ($st->fetchAll() as $c) {
            $out[] = ['id' => (int) $c['id'], 'date' => substr((string) $c['date_operation'], 0, 10), 'libelle' => (string) $c['libelle'], 'valeur' => (float) $c['valeur'],
                'regle' => $this->proposition((string) $c['libelle'], (float) $c['valeur'])];
        }
        return $out;
    }

    /** Ventile par les règles les opérations non ventilées de la saison. Renvoie le nombre d'opérations ventilées. */
    public function appliquer(?int $saisonId): int
    {
        $b = new Banque($this->pdo);
        $n = 0;
        $obs = $this->pdo->prepare('UPDATE compte SET observation = ? WHERE id = ?');
        $this->pdo->beginTransaction();
        foreach ($this->nonVentilees($saisonId) as $c) {
            if (!$c['regle']) {
                continue;
            }
            $auto = ' - Traitement Automatique fait le ' . date('d/m/Y');
            foreach (Banque::repartir($c['regle'], $c['valeur']) as $k => $m) {
                $pl = $c['regle']['lignes'][$k];
                $b->insererDetail($c['id'], $pl['section_principale_id'], $pl['type_compte_id'], $m, $pl['libelle'], trim($pl['observation'] . $auto, ' -'), true);
            }
            $obs->execute([mb_substr(trim($c['regle']['observation'] . $auto, ' -'), 0, 255), $c['id']]);
            $n++;
        }
        $this->pdo->commit();
        return $n;
    }

    /** Nombre d'opérations de la saison qui correspondent à chaque règle (toutes / pas encore ventilées). @return array<int,array{toutes:int,a_ventiler:int}> */
    public function compter(?int $saisonId): array
    {
        $st = $this->pdo->prepare('SELECT c.libelle, c.valeur, (SELECT COUNT(*) FROM detail_compte d WHERE d.compte_id = c.id) AS nd FROM compte c WHERE c.virement_interne = 0' . ($saisonId ? ' AND c.saison_id = ?' : ''));
        $st->execute($saisonId ? [$saisonId] : []);
        $ops = $st->fetchAll();
        $out = [];
        foreach ($this->liste() as $r) {
            $out[(int) $r['id']] = ['toutes' => 0, 'a_ventiler' => 0];
        }
        $regles = $this->liste();
        foreach ($ops as $o) {
            foreach ($regles as $r) {
                if (self::correspond($r, (string) $o['libelle'], (float) $o['valeur'])) {
                    $out[(int) $r['id']]['toutes']++;
                    if ((int) $o['nd'] === 0) {
                        $out[(int) $r['id']]['a_ventiler']++;
                    }
                    break;
                }
            }
        }
        return $out;
    }

    /** Une seule fois : reprend les règles du traitement automatique de WinDev (identifiants de sections et de catégories de WinDev). */
    private function initialiser(): void
    {
        if (Parametres::get($this->pdo, 'regles_banque_init') !== null) {
            return;
        }
        Parametres::set($this->pdo, 'regles_banque_init', date('Y-m-d'));
        $sp = array_map('intval', $this->pdo->query('SELECT id FROM section_principale')->fetchAll(PDO::FETCH_COLUMN));
        $tc = array_map('intval', $this->pdo->query('SELECT id FROM type_compte')->fetchAll(PDO::FETCH_COLUMN));
        $ins = $this->pdo->prepare('INSERT INTO regle_banque (ordre, motif, mode, montant, libelle, lignes, actif, cree_le) VALUES (?,?,?,?,?,?,1,?)');
        $ordre = 0;
        foreach (self::WINDEV as [$motif, $mode, $montant, $libelle, $lignes]) {
            $l = array_map(static fn ($x) => ['sp' => $x[0], 'tc' => $x[1], 'part' => $x[2], 'libelle' => $x[3] ?? ''], $lignes);
            foreach ($l as $x) {
                if (!in_array($x['sp'], $sp, true) || !in_array($x['tc'], $tc, true)) {
                    continue 2;
                }
            }
            $ins->execute([$ordre += 10, $motif, $mode, $montant, $libelle, json_encode($l, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        }
    }

    /** Règles du traitement automatique de WinDev : [motif, mode, montant, libellé, [[section principale, catégorie, part %, libellé de ligne]]]. */
    private const WINDEV = [
        ['TLR SEPA DGFIP', 'contient', null, 'DGFIP - FUERIS Maximilien', [[16, 17, 100]]],
        ['VIR SGC CALUIRE', 'debut', null, 'Subvention', [[15, 20, 100]]],
        ['VIR LHOSTE', 'debut', null, 'Facture Danse/Pilate', [[8, 10, 50, 'Facture Danse'], [7, 10, 50, 'Facture Pilate']]],
        ['PRLV SEPA KOESIO AURA', 'debut', null, 'KOESIO Photocopieur', [[15, 13, 100]]],
        ['VIR BESSON FACTURE', 'debut', null, 'Facture Sophrologie', [[3, 10, 100]]],
        ['VIR BRAEMER FACTURE', 'debut', null, 'Facture Dessin BRAEMER', [[2, 10, 100]]],
        ['VIR HERBEAU FACT', 'debut', null, 'Facture Poterie HERBEAU', [[2, 10, 100]]],
        ['VIR LEFEBVRE FACT', 'debut', null, 'Facture Dessin LEFEBVRE', [[2, 10, 100]]],
        ['VIR BEAUFRERE', 'debut', null, 'Facture Dessin BEAUFRERE', [[6, 10, 100]]],
        ['VIR ASSISTCLIC FACTURE', 'debut', null, 'Facture Secrétariat', [[15, 10, 100]]],
        ["ASSIST'CLIC", 'contient', null, 'Facture Secrétariat', [[15, 10, 100]]],
        ['VIR MUNOZ FACTURE', 'debut', null, "Facture Tir a l'arc", [[9, 10, 100]]],
        ['VIR MUNOZ HERVE FACT', 'debut', null, "Facture Tir a l'arc", [[9, 10, 100]]],
        ['VIR AFZALI SALAIRE', 'debut', null, 'Salaire Taekwondo', [[4, 17, 100]]],
        ['VIR AFZALI FRAIS KM TKD', 'debut', null, 'Frais déplacement Taekwondo', [[4, 3, 100]]],
        ['VIR FUERIS SALAIRE', 'debut', null, 'Salaire Batterie', [[16, 17, 100]]],
        ['PRLV SEPA SFR', 'debut', null, 'RED BY SFR', [[15, 19, 100]]],
        ['PRLV SEPA FREE TELECOM', 'debut', null, 'Freebox Internet', [[15, 9, 100]]],
        ['PRLV SEPA VEAMA GROUPE', 'debut', null, 'Facture Comptable', [[15, 4, 100]]],
        ['PRLV SEPA PAYPAL EUROPE', 'debut', 21.24, 'Spotify', [[15, 5, 100]]],
        ['INTERETS', 'debut', null, 'Intérêt Livret', [[15, 21, 100]]],
        ['PRLV SEPA CEP TRESO SANTE PREV', 'debut', null, 'Prévoyance', [[16, 17, 50, 'Prévoyance - BATTERIE - FUERIS'], [4, 17, 50, 'Prévoyance - TKD - AFZALI']]],
        ['VIR LCT PAIE', 'debut', null, 'Facture LCT PAIE', [[16, 17, 50, 'Facture LCT PAIE - BATTERIE - FUERIS'], [4, 17, 50, 'Facture LCT PAIE - TKD - AFZALI']]],
        ['PRLV SEPA APICIL GESTION', 'debut', null, 'APICIL - RETRAITE', [[16, 17, 50, 'APICIL - RETRAITE - FUERIS - BATTERIE'], [4, 17, 50, 'APICIL - RETRAITE - AFZALI - TKD']]],
        ['PRLV SEPA URSSAF', 'debut', null, 'URSSAF', [[16, 17, 50, 'URSSAF - FUERIS - BATTERIE'], [4, 17, 50, 'URSSAF - AFZALI - TKD']]],
        ['FACT SGT', 'debut', null, 'Facture Banque', [[15, 2, 100]]],
        ['SOUTIEN ASSO SPORTIVE/CULTURELL', 'debut', null, 'Facture Banque', [[15, 2, 100]]],
        ['PRLV SEPA MACIF', 'debut', null, 'Assurance', [[15, 1, 100]]],
        ['PRLV SEPA THEMIS PRODUCTION', 'debut', null, 'Assistance Juridique', [[15, 1, 100]]],
        ['LYON LE PROGRES', 'contient', null, 'LE PROGRES', [[15, 22, 100]]],
        ['FFTA', 'contient', null, 'FFTA', [[9, 6, 100]]],
    ];
}
