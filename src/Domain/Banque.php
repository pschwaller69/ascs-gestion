<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Comptes bancaires : lignes de relevé, ventilation par section principale et catégorie, import des relevés, contrôles.
 * Import : CSV de la banque (« date ; date valeur ; débit ; crédit ; libellé ; solde » comme dans WinDev, ou CSV avec en-têtes)
 * et OFX. Les lignes déjà présentes sont reconnues ; les opérations récurrentes sont ventilées comme la dernière fois.
 */
final class Banque
{
    private ?RegleBanque $regles = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{numero:string,lignes:int,solde:?float,derniere:?string,nom:string}> */
    public function comptes(): array
    {
        $noms = json_decode((string) (\Ascs\Parametres::get($this->pdo, 'banque_noms') ?? ''), true) ?: [];
        $out = [];
        foreach ($this->pdo->query('SELECT numero_compte, COUNT(*) AS n, MAX(date_operation) AS d FROM compte GROUP BY numero_compte ORDER BY MAX(date_operation) DESC')->fetchAll() as $r) {
            $st = $this->pdo->prepare('SELECT solde FROM compte WHERE numero_compte = ? ORDER BY date_operation DESC, id DESC LIMIT 1');
            $st->execute([$r['numero_compte']]);
            $s = $st->fetchColumn();
            $out[] = ['numero' => (string) $r['numero_compte'], 'lignes' => (int) $r['n'], 'derniere' => $r['d'], 'solde' => $s === false || $s === null ? null : (float) $s,
                'nom' => (string) ($noms[$r['numero_compte']] ?? '')];
        }
        return $out;
    }

    public function nommer(string $numero, string $nom): void
    {
        $noms = json_decode((string) (\Ascs\Parametres::get($this->pdo, 'banque_noms') ?? ''), true) ?: [];
        $nom = trim($nom);
        if ($nom === '') {
            unset($noms[$numero]);
        } else {
            $noms[$numero] = mb_substr($nom, 0, 60);
        }
        \Ascs\Parametres::set($this->pdo, 'banque_noms', json_encode($noms, JSON_UNESCAPED_UNICODE));
    }

    /* ---------- Périodes (par dates d'opération, comme le comptable) ---------- */

    /** Saisons puis années civiles ayant des opérations. @return list<array{cle:string,libelle:string,du:string,au:string}> */
    public function periodes(): array
    {
        $b = $this->pdo->query('SELECT MIN(date_operation) AS mi, MAX(date_operation) AS ma FROM compte')->fetch();
        if (!$b || !$b['mi']) {
            return [];
        }
        $mi = substr((string) $b['mi'], 0, 10);
        $ma = substr((string) $b['ma'], 0, 10);
        $out = [];
        foreach ($this->pdo->query('SELECT id, titre, date_debut, date_fin FROM saison ORDER BY date_debut DESC')->fetchAll() as $s) {
            $du = substr((string) $s['date_debut'], 0, 10);
            $au = substr((string) ($s['date_fin'] ?: date('Y-m-d', strtotime($du . ' +1 year -1 day'))), 0, 10);
            if ($au >= $mi && $du <= max($ma, date('Y-m-d'))) {
                $out[] = ['cle' => 's' . $s['id'], 'libelle' => 'Saison ' . $s['titre'], 'du' => $du, 'au' => $au];
            }
        }
        for ($a = (int) substr($ma, 0, 4); $a >= (int) substr($mi, 0, 4); $a--) {
            $out[] = ['cle' => 'a' . $a, 'libelle' => 'Année ' . $a, 'du' => $a . '-01-01', 'au' => $a . '-12-31'];
        }
        return $out;
    }

    /** Période par sa clé (s<id de saison> ou a<année>), ou dates libres jj/mm/aaaa ou aaaa-mm-jj. */
    public function periode(string $cle, string $du = '', string $au = ''): ?array
    {
        if ($cle === 'dates') {
            $d1 = self::date($du);
            $d2 = self::date($au);
            return $d1 && $d2 && $d1 <= $d2 ? ['cle' => 'dates', 'libelle' => 'Du ' . self::fr($d1) . ' au ' . self::fr($d2), 'du' => $d1, 'au' => $d2] : null;
        }
        foreach ($this->periodes() as $p) {
            if ($p['cle'] === $cle) {
                return $p;
            }
        }
        if (preg_match('/^s(\d+)$/', $cle, $m)) {
            $st = $this->pdo->prepare('SELECT id, titre, date_debut, date_fin FROM saison WHERE id = ?');
            $st->execute([(int) $m[1]]);
            if ($s = $st->fetch()) {
                return ['cle' => $cle, 'libelle' => 'Saison ' . $s['titre'], 'du' => substr((string) $s['date_debut'], 0, 10), 'au' => substr((string) $s['date_fin'], 0, 10)];
            }
        }
        return null;
    }

    /** Nombre d'opérations d'un compte sur une période. */
    public function nbOperations(string $numero, array $p): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM compte WHERE numero_compte = ? AND date_operation >= ? AND date_operation <= ?');
        $st->execute([$numero, $p['du'], $p['au'] . ' 23:59:59']);
        return (int) $st->fetchColumn();
    }

    private static function fr(string $iso): string
    {
        return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
    }

    /**
     * Lignes d'un compte sur une période (dates d'opération), avec le total ventilé.
     * @param ?array{du:string,au:string} $periode null = tout
     * @param string $filtre '' | 'a_ventiler' | 'credit' | 'debit'
     * @return list<array<string,mixed>>
     */
    public function lignes(string $numero, ?array $periode, string $filtre = '', string $q = ''): array
    {
        $w = ['c.numero_compte = ?'];
        $a = [$numero];
        if ($periode) {
            $w[] = 'c.date_operation >= ? AND c.date_operation <= ?';
            $a[] = $periode['du'];
            $a[] = $periode['au'] . ' 23:59:59';
        }
        if ($filtre === 'credit') {
            $w[] = 'c.valeur > 0';
        } elseif ($filtre === 'debit') {
            $w[] = 'c.valeur < 0';
        }
        if ($q !== '') {
            $w[] = "(c.libelle LIKE ? ESCAPE '!' OR c.observation LIKE ? ESCAPE '!')";
            $a[] = Input::like($q);
            $a[] = Input::like($q);
        }
        $st = $this->pdo->prepare('SELECT c.*, (SELECT COALESCE(SUM(d.valeur),0) FROM detail_compte d WHERE d.compte_id = c.id) AS ventile,
                (SELECT COUNT(*) FROM detail_compte d WHERE d.compte_id = c.id) AS nb_details
            FROM compte c WHERE ' . implode(' AND ', $w) . ' ORDER BY c.date_operation DESC, c.id DESC');
        $st->execute($a);
        $lignes = $st->fetchAll();
        if ($filtre === 'a_ventiler') {
            $lignes = array_values(array_filter($lignes, static fn ($l) => self::aVentiler($l)));
        }
        return $lignes;
    }

    public static function aVentiler(array $l): bool
    {
        return !(int) $l['virement_interne'] && abs((float) $l['valeur'] - (float) $l['ventile']) > 0.005;
    }

    public function ligne(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM compte WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT d.*, sp.titre AS section, t.libelle AS categorie FROM detail_compte d
            LEFT JOIN section_principale sp ON sp.id = d.section_principale_id LEFT JOIN type_compte t ON t.id = d.type_compte_id WHERE d.compte_id = ? ORDER BY d.id');
        $st->execute([$id]);
        $c['details'] = $st->fetchAll();
        return $c;
    }

    /**
     * Ventilation d'une ligne : remplace les lignes de détail. Le total doit égaler le montant de la ligne (sauf virement interne).
     * @param list<array{section_principale_id:mixed,type_compte_id:mixed,montant:mixed,observation:mixed}> $lignes
     * @return ?string erreur
     */
    public function ventiler(int $compteId, array $lignes, bool $virementInterne, string $observation): ?string
    {
        $c = $this->ligne($compteId);
        if (!$c) {
            return 'Ligne introuvable.';
        }
        $propres = [];
        $total = 0.0;
        foreach ($lignes as $l) {
            $brut = Input::montant(['m' => (string) ($l['montant'] ?? '')], 'm');
            $m = $brut === null ? null : (float) $brut;
            if ($m === null || abs($m) < 0.005) {
                continue;
            }
            // Le signe suit la ligne bancaire : une dépense se ventile en montants négatifs
            $m = (float) $c['valeur'] < 0 ? -abs($m) : abs($m);
            $sp = (int) ($l['section_principale_id'] ?? 0) ?: null;
            $tc = (int) ($l['type_compte_id'] ?? 0) ?: null;
            if ($sp === null || $tc === null) {
                return 'Chaque ligne de ventilation doit avoir une section et une catégorie.';
            }
            $propres[] = [$sp, $tc, $m, mb_substr(trim((string) ($l['observation'] ?? '')), 0, 255)];
            $total += $m;
        }
        if (!$virementInterne && $propres && abs($total - (float) $c['valeur']) > 0.005) {
            return sprintf('La ventilation totalise %s pour une ligne de %s : corrigez les montants.', number_format($total, 2, ',', ' '), number_format((float) $c['valeur'], 2, ',', ' '));
        }
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE compte SET virement_interne = ?, observation = ?, date_modification = ? WHERE id = ?')
            ->execute([$virementInterne ? 1 : 0, mb_substr(trim($observation), 0, 255) ?: null, date('Y-m-d H:i:s'), $compteId]);
        $this->pdo->prepare('DELETE FROM detail_compte WHERE compte_id = ?')->execute([$compteId]);
        foreach ($propres as [$sp, $tc, $m, $obs]) {
            $this->insererDetail($compteId, $sp, $tc, $m, (string) $c['libelle'], $obs, false);
        }
        $this->pdo->commit();
        return null;
    }

    public function insererDetail(int $compteId, int $sp, int $tc, float $m, string $libelle, string $obs, bool $auto): void
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM detail_compte')->fetchColumn();
        $this->pdo->prepare('INSERT INTO detail_compte (id, compte_id, section_principale_id, type_compte_id, debit, credit, valeur, libelle, observation, traite_auto, date_creation)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $compteId, $sp, $tc, $m < 0 ? $m : 0, $m > 0 ? $m : 0, $m, mb_substr($libelle, 0, 255), $obs ?: null, $auto ? 1 : 0, date('Y-m-d H:i:s')]);
    }

    /* ---------- Import des relevés ---------- */

    /**
     * Lit un relevé (CSV ou OFX). @return array{lignes:list<array{date:string,date_valeur:?string,valeur:float,libelle:string,solde:?float,brut:string}>,erreurs:list<string>,format:string}
     */
    public static function lire(string $contenu): array
    {
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = (string) mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        if (stripos($contenu, '<OFX') !== false || stripos($contenu, '<STMTTRN>') !== false) {
            return self::lireOfx($contenu);
        }
        $lignes = preg_split('/\r\n|\r|\n/', $contenu);
        $sep = substr_count($contenu, ';') >= substr_count($contenu, ',') ? ';' : ',';
        $col = null;
        $out = [];
        $err = [];
        foreach ($lignes as $n => $brut) {
            if (trim($brut) === '') {
                continue;
            }
            $f = array_map(static fn ($v) => trim((string) $v, " \t\"="), str_getcsv($brut, $sep, '"', ''));
            if ($col === null && self::estEntete($f)) {
                $col = self::colonnes($f);
                continue;
            }
            $date = self::date($f[0] ?? '');
            if ($date === null) {
                continue; // lignes d'en-tête de la banque (numéro de compte, solde…)
            }
            if ($col === null) {
                // Format WinDev / banque : date ; date valeur ; débit ; crédit ; libellé ; solde
                $deb = self::nombre($f[2] ?? '');
                $cre = self::nombre($f[3] ?? '');
                $valeur = ($cre ?? 0.0) - abs($deb ?? 0.0);
                $ligne = ['date' => $date, 'date_valeur' => self::date($f[1] ?? ''), 'valeur' => round($valeur, 2), 'libelle' => trim($f[4] ?? ''),
                    'solde' => self::nombre($f[5] ?? ''), 'brut' => $brut];
            } else {
                $v = isset($col['montant']) ? self::nombre($f[$col['montant']] ?? '') : null;
                if ($v === null) {
                    $deb = isset($col['debit']) ? self::nombre($f[$col['debit']] ?? '') : null;
                    $cre = isset($col['credit']) ? self::nombre($f[$col['credit']] ?? '') : null;
                    $v = ($cre ?? 0.0) - abs($deb ?? 0.0);
                }
                $lib = trim(implode(' ', array_filter(array_map(static fn ($i) => $f[$i] ?? '', $col['libelle']), static fn ($x) => $x !== '')));
                $ligne = ['date' => $date, 'date_valeur' => isset($col['date_valeur']) ? self::date($f[$col['date_valeur']] ?? '') : null, 'valeur' => round($v, 2),
                    'libelle' => preg_replace('/\s+/', ' ', $lib), 'solde' => isset($col['solde']) ? self::nombre($f[$col['solde']] ?? '') : null, 'brut' => $brut];
            }
            if (abs($ligne['valeur']) < 0.005) {
                $err[] = 'Ligne ' . ($n + 1) . ' ignorée (montant nul ou illisible).';
                continue;
            }
            $out[] = $ligne;
        }
        return ['lignes' => $out, 'erreurs' => $err, 'format' => $col === null ? 'CSV (date ; date valeur ; débit ; crédit ; libellé ; solde)' : 'CSV avec en-têtes'];
    }

    private static function estEntete(array $f): bool
    {
        $t = DuplicateFinder::norm(implode(' ', $f));
        return str_contains($t, 'date') && (str_contains($t, 'montant') || str_contains($t, 'debit') || str_contains($t, 'credit'));
    }

    /** @return array<string,mixed> */
    private static function colonnes(array $f): array
    {
        $c = ['libelle' => []];
        foreach ($f as $i => $nom) {
            $n = DuplicateFinder::norm($nom);
            if (str_contains($n, 'datevaleur') || str_contains($n, 'datedevaleur') || $n === 'valeur') {
                $c['date_valeur'] = $i;
            } elseif (str_contains($n, 'date') && !isset($c['date'])) {
                $c['date'] = $i;
            } elseif (str_contains($n, 'debit')) {
                $c['debit'] = $i;
            } elseif (str_contains($n, 'credit')) {
                $c['credit'] = $i;
            } elseif (str_contains($n, 'montant')) {
                $c['montant'] = $i;
            } elseif (str_contains($n, 'solde')) {
                $c['solde'] = $i;
            } elseif (str_contains($n, 'libelle') || str_contains($n, 'detail') || str_contains($n, 'operation') || str_contains($n, 'intitule')) {
                $c['libelle'][] = $i;
            }
        }
        return $c;
    }

    private static function lireOfx(string $c): array
    {
        $out = [];
        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/is', $c, $m);
        foreach ($m[1] as $bloc) {
            $v = static fn (string $t) => preg_match('/<' . $t . '>([^<\r\n]*)/i', $bloc, $x) ? trim($x[1]) : '';
            $d = $v('DTPOSTED');
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $d, $x)) {
                continue;
            }
            $lib = trim($v('NAME') . ' ' . ($v('MEMO') !== $v('NAME') ? $v('MEMO') : ''));
            $out[] = ['date' => "$x[1]-$x[2]-$x[3]", 'date_valeur' => null, 'valeur' => round((float) str_replace(',', '.', $v('TRNAMT')), 2),
                'libelle' => html_entity_decode($lib), 'solde' => null, 'brut' => 'OFX ' . ($v('FITID') ?: md5($bloc))];
        }
        return ['lignes' => $out, 'erreurs' => [], 'format' => 'OFX'];
    }

    private static function date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $s;
        }
        return null;
    }

    private static function nombre(string $s): ?float
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', '€', 'EUR'], '', trim($s));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** Clé d'une opération récurrente : premiers mots du libellé sans chiffres (« PRLV SEPA URSSAF RHONE ALPES »). */
    public static function cle(string $libelle): string
    {
        $mots = array_filter(preg_split('/[\s:\-\/,]+/', mb_strtoupper($libelle)), static fn ($m) => $m !== '' && !preg_match('/\d/', $m) && mb_strlen($m) > 1);
        return implode(' ', array_slice(array_values($mots), 0, 5));
    }

    /**
     * Ventilation proposée d'après l'historique : les deux dernières opérations de même clé et de même sens,
     * ventilées de la même façon (mêmes sections, catégories et parts, par exemple 50 / 50 entre deux sections).
     * @return ?array{lignes:list<array{section_principale_id:int,type_compte_id:int,part:float,observation:string}>,observation:string}
     */
    public function proposition(string $libelle, float $valeur): ?array
    {
        $this->regles ??= new RegleBanque($this->pdo);
        if ($r = $this->regles->proposition($libelle, $valeur)) {
            return $r;
        }
        $cle = self::cle($libelle);
        if (mb_strlen($cle) < 6 || str_starts_with($cle, 'REM CHQ') || $cle === 'VIR' || $cle === 'CHEQUE') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT c.id, c.libelle, c.valeur, c.observation FROM compte c WHERE c.libelle LIKE ? ESCAPE \'!\' AND ' . ($valeur < 0 ? 'c.valeur < 0' : 'c.valeur > 0')
            . ' AND c.virement_interne = 0 ORDER BY c.date_operation DESC, c.id DESC LIMIT 10');
        $st->execute([implode('%', array_map(static fn ($m) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $m), explode(' ', $cle))) . '%']);
        $modeles = [];
        foreach ($st->fetchAll() as $c) {
            if (self::cle((string) $c['libelle']) !== $cle || abs((float) $c['valeur']) < 0.005) {
                continue;
            }
            $d = $this->pdo->prepare('SELECT section_principale_id, type_compte_id, valeur, observation FROM detail_compte WHERE compte_id = ? ORDER BY id');
            $d->execute([$c['id']]);
            $lignes = [];
            foreach ($d->fetchAll() as $x) {
                if (!$x['section_principale_id'] || !$x['type_compte_id']) {
                    return null;
                }
                $lignes[] = ['section_principale_id' => (int) $x['section_principale_id'], 'type_compte_id' => (int) $x['type_compte_id'],
                    'part' => round((float) $x['valeur'] / (float) $c['valeur'], 4), 'observation' => self::sansAuto((string) $x['observation'])];
            }
            if (!$lignes || abs(array_sum(array_column($lignes, 'part')) - 1) > 0.001) {
                return null; // dernière opération pas (ou mal) ventilée : pas de modèle fiable
            }
            $modeles[] = ['lignes' => $lignes, 'observation' => self::sansAuto((string) $c['observation'])];
            if (count($modeles) === 2) {
                break;
            }
        }
        if (count($modeles) < 2) {
            return null;
        }
        $empreinte = static fn ($m) => json_encode(array_map(static fn ($l) => [$l['section_principale_id'], $l['type_compte_id'], round($l['part'], 2)], $m['lignes']));
        return $empreinte($modeles[0]) === $empreinte($modeles[1]) ? $modeles[0] : null;
    }

    private static function sansAuto(string $obs): string
    {
        return trim((string) preg_replace('/\s*-?\s*Traitement Automatique fait le [\d\/]+\s*$/u', '', $obs));
    }

    /** Montants d'une proposition pour une valeur : parts arrondies au centime, le reste sur la dernière ligne. @return list<float> */
    public static function repartir(array $proposition, float $valeur): array
    {
        $m = [];
        $reste = $valeur;
        foreach ($proposition['lignes'] as $i => $l) {
            $v = $i === count($proposition['lignes']) - 1 ? round($reste, 2) : round($valeur * $l['part'], 2);
            $reste -= $v;
            $m[] = $v;
        }
        return $m;
    }

    /**
     * Prépare un import : marque les lignes déjà présentes, propose une ventilation.
     * @param list<array<string,mixed>> $lignes
     * @return list<array<string,mixed>>
     */
    public function preparer(string $numero, array $lignes): array
    {
        $existe = $this->pdo->prepare('SELECT COUNT(*) FROM compte WHERE numero_compte = ? AND (ligne_import = ? OR (date_operation = ? AND ABS(valeur - ?) < 0.005 AND libelle = ?))');
        $vus = [];
        foreach ($lignes as &$l) {
            $existe->execute([$numero, $l['brut'], $l['date'], $l['valeur'], mb_substr($l['libelle'], 0, 255)]);
            $cleDoublon = $l['date'] . '|' . $l['valeur'] . '|' . $l['libelle'] . '|' . $l['solde'];
            $l['deja'] = (int) $existe->fetchColumn() > 0 || isset($vus[$cleDoublon]);
            $vus[$cleDoublon] = true;
            $l['proposition'] = $l['deja'] ? null : $this->proposition($l['libelle'], (float) $l['valeur']);
            $l['saison_id'] = $this->saisonDe($l['date']);
        }
        return $lignes;
    }

    public function saisonDe(string $date): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut <= ? ORDER BY date_debut DESC LIMIT 1');
        $st->execute([$date]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Enregistre les nouvelles lignes, ventile automatiquement celles qui ont une proposition, puis rapproche les remises de chèques.
     * @return array{ajoutees:int,deja:int,auto:int,remises:int,sans_saison:int}
     */
    public function importer(string $numero, array $lignes): array
    {
        $r = ['ajoutees' => 0, 'deja' => 0, 'auto' => 0, 'remises' => 0, 'sans_saison' => 0];
        $this->pdo->beginTransaction();
        foreach ($this->preparer($numero, $lignes) as $l) {
            if ($l['deja']) {
                $r['deja']++;
                continue;
            }
            if ($l['saison_id'] === null) {
                $r['sans_saison']++;
                continue;
            }
            $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM compte')->fetchColumn();
            $v = (float) $l['valeur'];
            $auto = ' - Traitement Automatique fait le ' . date('d/m/Y');
            $obs = $l['proposition'] ? trim($l['proposition']['observation'] . $auto, ' -') : null;
            $this->pdo->prepare('INSERT INTO compte (id, saison_id, date_operation, date_valeur, debit, credit, valeur, solde, numero_compte, libelle, observation, ligne_import, virement_interne, date_creation)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)')->execute([$id, $l['saison_id'], $l['date'], $l['date_valeur'], $v < 0 ? $v : 0, $v > 0 ? $v : 0, $v, $l['solde'],
                $numero, mb_substr($l['libelle'], 0, 255), $obs ? mb_substr($obs, 0, 255) : null, mb_substr($l['brut'], 0, 500), date('Y-m-d H:i:s')]);
            if ($l['proposition']) {
                foreach (self::repartir($l['proposition'], $v) as $k => $m) {
                    $pl = $l['proposition']['lignes'][$k];
                    $this->insererDetail($id, $pl['section_principale_id'], $pl['type_compte_id'], $m, $pl['libelle'] ?? $l['libelle'], trim($pl['observation'] . $auto, ' -'), true);
                }
                $r['auto']++;
            }
            $r['ajoutees']++;
        }
        $this->pdo->commit();
        if ($r['ajoutees']) {
            $rem = (new Remises($this->pdo))->rapprocher();
            $r['remises'] = (int) ($rem['ventilees'] ?? 0);
        }
        return $r;
    }

    /* ---------- Export pour le comptable ---------- */

    /**
     * Données de l'export comptable sur une période : synthèse par compte (soldes d'ouverture et de clôture),
     * journal de banque, ventilation, totaux par catégorie et par section, opérations à justifier.
     * @param list<string> $numeros comptes retenus (vide = tous)
     */
    public function comptable(array $p, array $numeros = []): array
    {
        $noms = [];
        foreach ($this->comptes() as $c) {
            $noms[$c['numero']] = $c['nom'];
        }
        $tous = array_map('strval', array_keys($noms));
        $numeros = array_values(array_intersect(array_map('strval', $numeros) ?: $tous, $tous));
        sort($numeros);
        $fin = $p['au'] . ' 23:59:59';
        $out = ['periode' => $p, 'comptes' => [], 'journal' => [], 'ventilation' => [], 'categories' => [], 'sections' => [], 'a_justifier' => [], 'internes' => 0.0];
        $det = $this->pdo->prepare('SELECT d.valeur, d.observation, d.libelle, sp.titre AS section, t.libelle AS categorie FROM detail_compte d
            LEFT JOIN section_principale sp ON sp.id = d.section_principale_id LEFT JOIN type_compte t ON t.id = d.type_compte_id WHERE d.compte_id = ? ORDER BY d.id');
        foreach ($numeros as $n) {
            $st = $this->pdo->prepare('SELECT solde FROM compte WHERE numero_compte = ? AND date_operation < ? AND solde IS NOT NULL ORDER BY date_operation DESC, id DESC LIMIT 1');
            $st->execute([$n, $p['du']]);
            $ouv = $st->fetchColumn();
            $st = $this->pdo->prepare('SELECT * FROM compte WHERE numero_compte = ? AND date_operation >= ? AND date_operation <= ? ORDER BY date_operation, id');
            $st->execute([$n, $p['du'], $fin]);
            $ops = $st->fetchAll();
            if ($ouv === false && $ops && $ops[0]['solde'] !== null) {
                $ouv = (float) $ops[0]['solde'] - (float) $ops[0]['valeur'];
            }
            $ouv = $ouv === false || $ouv === null ? null : round((float) $ouv, 2);
            $rec = 0.0;
            $dep = 0.0;
            $soldeBanque = null;
            foreach ($ops as $o) {
                $v = (float) $o['valeur'];
                $v > 0 ? $rec += $v : $dep += -$v;
                if ($o['solde'] !== null) {
                    $soldeBanque = (float) $o['solde'];
                }
                $det->execute([$o['id']]);
                $details = $det->fetchAll();
                $interne = (bool) (int) $o['virement_interne'];
                $ventile = array_sum(array_map(static fn ($d) => (float) $d['valeur'], $details));
                $reste = $interne ? 0.0 : round($v - $ventile, 2);
                $resume = $interne ? 'Virement interne' : implode(' + ', array_map(static fn ($d) => trim(($d['categorie'] ?? '?') . ' ' . ($d['section'] ? '(' . $d['section'] . ')' : '')), $details));
                $out['journal'][] = ['compte' => $n, 'date' => substr((string) $o['date_operation'], 0, 10), 'date_valeur' => $o['date_valeur'] ? substr((string) $o['date_valeur'], 0, 10) : null,
                    'libelle' => (string) $o['libelle'], 'debit' => $v < 0 ? -$v : null, 'credit' => $v > 0 ? $v : null, 'solde' => $o['solde'] !== null ? (float) $o['solde'] : null,
                    'ventilation' => $resume, 'observation' => (string) $o['observation'], 'reste' => abs($reste) > 0.005 ? $reste : null];
                if ($interne) {
                    $out['internes'] += $v;
                    $out['ventilation'][] = ['compte' => $n, 'date' => substr((string) $o['date_operation'], 0, 10), 'libelle' => (string) $o['libelle'], 'section' => '', 'categorie' => 'Virement interne',
                        'valeur' => $v, 'observation' => (string) $o['observation']];
                    continue;
                }
                foreach ($details as $d) {
                    $dv = (float) $d['valeur'];
                    $cat = $d['categorie'] ?? 'Sans catégorie';
                    $sec = $d['section'] ?? 'Sans section';
                    $out['ventilation'][] = ['compte' => $n, 'date' => substr((string) $o['date_operation'], 0, 10), 'libelle' => (string) $o['libelle'], 'section' => $sec, 'categorie' => $cat,
                        'valeur' => $dv, 'observation' => (string) ($d['observation'] ?: $o['observation'])];
                    self::cumul($out['categories'], $cat, $dv);
                    self::cumul($out['sections'], $sec, $dv);
                }
                if (abs($reste) > 0.005) {
                    $out['ventilation'][] = ['compte' => $n, 'date' => substr((string) $o['date_operation'], 0, 10), 'libelle' => (string) $o['libelle'], 'section' => '', 'categorie' => 'À ventiler',
                        'valeur' => $reste, 'observation' => (string) $o['observation']];
                    self::cumul($out['categories'], 'À ventiler', $reste);
                    self::cumul($out['sections'], 'À ventiler', $reste);
                    $out['a_justifier'][] = ['compte' => $n, 'date' => substr((string) $o['date_operation'], 0, 10), 'libelle' => (string) $o['libelle'], 'valeur' => $v, 'reste' => $reste,
                        'id' => (int) $o['id']];
                }
            }
            $clo = $ouv === null ? null : round($ouv + $rec - $dep, 2);
            $out['comptes'][] = ['numero' => $n, 'nom' => $noms[$n] ?: 'Compte ' . $n, 'ouverture' => $ouv, 'recettes' => round($rec, 2), 'depenses' => round($dep, 2), 'cloture' => $clo,
                'solde_banque' => $soldeBanque, 'nb' => count($ops), 'ecart' => $clo !== null && $soldeBanque !== null && abs($clo - $soldeBanque) > 0.005 ? round($soldeBanque - $clo, 2) : null];
        }
        ksort($out['categories'], SORT_NATURAL | SORT_FLAG_CASE);
        ksort($out['sections'], SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private static function cumul(array &$t, string $k, float $v): void
    {
        $t[$k] ??= ['recettes' => 0.0, 'depenses' => 0.0];
        $v > 0 ? $t[$k]['recettes'] += $v : $t[$k]['depenses'] += -$v;
    }

    /* ---------- Contrôles ---------- */

    /** @return array{a_ventiler:list<array>,soldes:list<array>,remises:list<array>} */
    public function controles(?int $saisonId): array
    {
        $aV = [];
        foreach ($this->comptes() as $c) {
            foreach ($this->lignes($c['numero'], $saisonId ? $this->periode('s' . $saisonId) : null, 'a_ventiler') as $l) {
                $aV[] = $l;
            }
        }
        // Continuité des soldes : solde précédent + opération = solde (repère les lignes manquantes à l'import)
        $soldes = [];
        foreach ($this->comptes() as $c) {
            $st = $this->pdo->prepare('SELECT id, date_operation, valeur, solde, libelle FROM compte WHERE numero_compte = ? AND solde IS NOT NULL ORDER BY date_operation, id');
            $st->execute([$c['numero']]);
            $prec = null;
            foreach ($st->fetchAll() as $l) {
                if ($prec !== null && abs((float) $prec['solde'] + (float) $l['valeur'] - (float) $l['solde']) > 0.005
                    && (!$saisonId || $this->saisonDe((string) $l['date_operation']) === $saisonId)) {
                    $soldes[] = $l + ['numero_compte' => $c['numero'], 'attendu' => (float) $prec['solde'] + (float) $l['valeur'], 'precedent' => $prec['date_operation']];
                }
                $prec = $l;
            }
        }
        $st = $this->pdo->prepare('SELECT r.*, (SELECT COALESCE(SUM(c.montant),0) FROM remise_cheque c WHERE c.remise_id = r.id) AS total FROM remise r WHERE r.compte_id IS NULL AND r.date_depot IS NOT NULL ORDER BY r.date_depot DESC');
        try {
            $st->execute();
            $rem = $st->fetchAll();
        } catch (\PDOException) {
            $rem = [];
        }
        return ['a_ventiler' => $aV, 'soldes' => $soldes, 'remises' => $rem];
    }
}
