<?php
declare(strict_types=1);

namespace Ascs\Import;

use Ascs\Db;
use Ascs\Schema;
use PDO;
use RuntimeException;

/**
 * Import complet et rejouable des 10 tables WinDev/HFSQL (exports XML) vers la base web.
 *
 * - Les identifiants d'origine sont conservés : un nouvel import met à jour les lignes existantes,
 *   ajoute les nouvelles et supprime celles qui ont disparu de WinDev (la source reste la référence
 *   tant que WinDev est utilisé). Rien n'est écrit si une vérification échoue (transaction unique).
 * - Les inscriptions saisies en double (même adhérent, section, saison) sont fusionnées à l'import ;
 *   les règlements sont rattachés à l'inscription conservée.
 */
final class Importer
{
    public const FILES = [
        'saison', 'famille_section', 'section_principale', 'section', 'adherent',
        'inscription', 'reglement', 'type_compte', 'compte', 'detail_compte',
    ];

    private const DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    private const CANAUX = ['CInternet' => 1, 'CAncien' => 2, 'CSiteASCS' => 3, 'CVille' => 4, 'CConnaissances' => 5];

    /** Libellés acceptés dans TypeTexte pour chaque Type de règlement (contrôle de cohérence uniquement). */
    private const TYPE_TEXTES = [
        1 => ['chèque'], 2 => ['espèce', 'espèces'], 3 => ['chèques vacances'], 4 => ['sportyneo'],
        5 => ['helloasso'], 6 => ['coupon mairie'], 7 => ['pass sport'], 8 => ['pass région'],
        9 => ['virement'], 10 => ['cb'],
    ];

    /** Ordre de dépendance (parents d'abord). */
    private const ORDER = [
        'saison', 'famille_section', 'section_principale', 'section', 'adherent', 'type_compte',
        'inscription', 'reglement', 'compte', 'detail_compte',
    ];

    /** @var array<string,list<string>> */
    private array $report = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $data = [];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{summary:array<string,mixed>,report:string} */
    public function run(string $dir, bool $dryRun = false): array
    {
        Conv::$rejects = [];
        $this->report = [];
        $this->data = [];

        foreach (self::FILES as $f) {
            $this->load($f, rtrim($dir, '/') . "/$f.xml");
        }
        $this->buildDerived();
        $this->mergeInscriptions();
        $this->checkAccounting();
        $this->checkPersonal();

        $stats = $this->write($dryRun);

        foreach (Conv::$rejects as $kind => $n) {
            $this->note('Valeurs illisibles', "$n valeur(s) de type « $kind » ignorées (mises à NULL)");
        }
        $summary = ['dry_run' => $dryRun, 'tables' => $stats];
        return ['summary' => $summary, 'report' => $this->formatReport($summary)];
    }

    // ------------------------------------------------------------------ lecture

    /** @return array<string,array{0:string,1:string}> tag XML => [colonne, convertisseur] */
    private function spec(string $table): array
    {
        $dates = ['DateCreation' => ['date_creation', 'datetime'], 'DateModification' => ['date_modification', 'datetime']];
        return match ($table) {
            'saison' => [
                'IDSaison' => ['id', 'id'], 'Titre' => ['titre', 'req'],
                'DateDebut' => ['date_debut', 'date'], 'DateFin' => ['date_fin', 'date'],
            ],
            'famille_section' => ['IDFamille_Section' => ['id', 'id'], 'Titre' => ['titre', 'req']],
            'section_principale' => [
                'IDSection_Principale' => ['id', 'id'], 'Titrre' => ['titre', 'req'],
                'IDFamille_Section' => ['famille_section_id', 'fk'], 'Code' => ['code', 'str'],
                'Montant_Rentabilité_Annuel' => ['montant_rentabilite_annuel', 'dec'],
            ],
            'section' => [
                'IDSection' => ['id', 'id'], 'Titre' => ['titre', 'req'], 'Montant' => ['montant', 'dec0'],
                'IDSection_Principale' => ['section_principale_id', 'fk'], 'Essai' => ['essai', 'bool'],
                'NomProduitSportyneo' => ['nom_produit_sportyneo', 'str'],
                'NomCampagneHelloAsso' => ['nom_campagne_helloasso', 'str'],
                'NomSaisonMonClub' => ['nom_saison_monclub', 'str'], 'MailAnimateur1' => ['mail_animateur1', 'str'],
                'Montant_Rentabilité_Annuel' => ['montant_rentabilite_annuel', 'dec'],
                'LienAnimateurs' => ['lien_animateurs', 'str'],
            ],
            'adherent' => [
                'IDAdherent' => ['id', 'id'], 'Mail' => ['mail', 'str'], 'NomAdherent' => ['nom', 'str'],
                'PrenomAdherent' => ['prenom', 'str'], 'NomParent' => ['nom_parent', 'str'],
                'PrenomParent' => ['prenom_parent', 'str'], 'Adresse' => ['adresse', 'str'],
                'CodePostale' => ['code_postal', 'str'], 'Ville' => ['ville', 'str'],
                'DateDeNaissance' => ['date_naissance', 'date'], 'Genre' => ['genre', 'int0'],
                'Telephone1' => ['telephone1', 'str'], 'Telephone2' => ['telephone2', 'str'],
                'AttestationCE' => ['attestation_ce', 'bool'], 'MembreCA' => ['membre_ca', 'bool'],
                'CAutre' => ['canal_autre', 'str'], 'Remarques' => ['remarques', 'text'],
                'AdresseMailNOk' => ['adresse_mail_invalide', 'bool'], 'NeVeuxPlusMail' => ['refus_mail', 'bool'],
                'NeVeuxPlusSMS' => ['refus_sms', 'bool'], 'NeVeuxPlusWhatapp' => ['refus_whatsapp', 'bool'],
                'NumMonClub' => ['num_monclub', 'fk'], 'RecheSportyneo' => ['reche_sportyneo', 'str'],
                'Licence' => ['licence', 'str'], 'Distance' => ['distance', 'dec'],
            ] + $dates,
            'inscription' => [
                'IDInscription' => ['id', 'id'], 'IDAdherent' => ['adherent_id', 'int'],
                'IDSection' => ['section_id', 'int'], 'IDSaison' => ['saison_id', 'int'],
                'DateInscription' => ['date_inscription', 'date'], 'FaitA' => ['fait_a', 'str'],
                'Montant' => ['montant', 'dec0'], 'Remarques' => ['remarques', 'text'],
                'Identifiant' => ['identifiant', 'str'],
            ] + $dates,
            'reglement' => [
                'IDReglement' => ['id', 'id'], 'IDInscription' => ['inscription_id', 'int'],
                'Type' => ['mode_reglement_id', 'int'], 'Montant' => ['montant', 'dec0'],
                'Identifiant' => ['identifiant', 'str'], 'Remarques' => ['remarques', 'text'],
                'DateReglement' => ['date_reglement', 'date'], 'DateSaisie' => ['date_saisie', 'datetime'],
                'DateModification' => ['date_modification', 'datetime'],
            ],
            'type_compte' => ['IDType_Compte' => ['id', 'id'], 'Type' => ['libelle', 'req']],
            'compte' => [
                'IDCompte' => ['id', 'id'], 'IDSaison' => ['saison_id', 'int'], 'Date' => ['date_operation', 'date'],
                'Date_De_Valeur' => ['date_valeur', 'date'], 'Debit' => ['debit', 'dec0'], 'Credit' => ['credit', 'dec0'],
                'Valeur' => ['valeur', 'dec0'], 'Solde' => ['solde', 'dec'], 'compte' => ['numero_compte', 'req'],
                'Libellé' => ['libelle', 'req'], 'Observation' => ['observation', 'str'],
                'LigneImport' => ['ligne_import', 'str'], 'Lien' => ['lien', 'str'],
                'VirementInterne' => ['virement_interne', 'bool'],
            ] + $dates,
            'detail_compte' => [
                'IDDétail_Compte' => ['id', 'id'], 'IDCompte' => ['compte_id', 'int'],
                'IDSection_Principale' => ['section_principale_id', 'fk'], 'IDType_Compte' => ['type_compte_id', 'fk'],
                'Credit' => ['credit', 'dec0'], 'Debit' => ['debit', 'dec0'], 'Valeur' => ['valeur', 'dec0'],
                'Libellé' => ['libelle', 'req'], 'Observation' => ['observation', 'str'],
                'TraiteAuto' => ['traite_auto', 'bool'], 'Lien' => ['lien', 'str'],
            ] + $dates,
        };
    }

    private function load(string $table, string $path): void
    {
        $spec = $this->spec($table);
        $cols = Schema::tables()[$table]['cols'];
        $rows = [];
        $extra = [];
        foreach (XmlTable::rows($path) as $n => $raw) {
            $row = [];
            foreach ($spec as $tag => [$col, $conv]) {
                $v = $raw[$tag] ?? '';
                $row[$col] = match ($conv) {
                    'id', 'int' => Conv::int($v),
                    'int0' => Conv::int($v) ?? 0,
                    'fk' => Conv::fk($v),
                    'str' => Conv::str($v),
                    'req' => Conv::str($v) ?? '',
                    'text' => Conv::text($v),
                    'bool' => Conv::bool($v),
                    'dec' => Conv::dec($v),
                    'dec0' => Conv::dec0($v),
                    'date' => Conv::date($v),
                    'datetime' => Conv::datetime($v),
                };
            }
            foreach ($cols as $col => $type) {
                if (!str_ends_with($type, '?') && $type !== 'auto' && array_key_exists($col, $row) && $row[$col] === null) {
                    throw new RuntimeException("$table: ligne " . ($n + 1) . " (id " . ($row['id'] ?? '?') . ") — valeur obligatoire manquante ou illisible pour « $col »");
                }
            }
            $id = $row['id'];
            if ($id === null) {
                throw new RuntimeException("$table: ligne " . ($n + 1) . " sans identifiant");
            }
            if (isset($rows[$id])) {
                throw new RuntimeException("$table: identifiant $id présent deux fois dans $path");
            }
            $rows[$id] = $row;
            $extra[$id] = $raw; // valeurs brutes, pour les contrôles et tables dérivées
        }
        $this->data[$table] = $rows;
        $this->data["_raw_$table"] = $extra;
    }

    // ------------------------------------------------------------------ tables dérivées

    private function buildDerived(): void
    {
        // Créneaux horaires : colonnes Lundi..Dimanche + LundiH1/H2… -> lignes (section, jour, ordre, heure_debut)
        $creneaux = [];
        $joursSansHeure = $heuresSansJour = 0;
        foreach ($this->data['_raw_section'] as $id => $raw) {
            foreach (self::DAYS as $num => $day) {
                $flag = Conv::bool($raw[$day] ?? '') === 1;
                $h1 = Conv::time($raw[$day . 'H1'] ?? '');
                $h2 = Conv::time($raw[$day . 'H2'] ?? '');
                if ($flag && $h1 === null && $h2 === null) {
                    $joursSansHeure++;
                    $creneaux[] = ['section_id' => $id, 'jour' => $num, 'ordre' => 1, 'heure_debut' => null];
                    continue;
                }
                if (!$flag && ($h1 !== null || $h2 !== null)) {
                    $heuresSansJour++;
                }
                if ($h1 !== null) {
                    $creneaux[] = ['section_id' => $id, 'jour' => $num, 'ordre' => 1, 'heure_debut' => $h1];
                }
                if ($h2 !== null) {
                    $creneaux[] = ['section_id' => $id, 'jour' => $num, 'ordre' => 2, 'heure_debut' => $h2];
                }
            }
        }
        $this->data['creneau'] = $creneaux;
        if ($joursSansHeure) {
            $this->note('Sections', "$joursSansHeure jour(s) coché(s) sans horaire (créneau créé sans heure)");
        }
        if ($heuresSansJour) {
            $this->note('Sections', "$heuresSansJour jour(s) avec horaire mais case jour décochée (horaire conservé)");
        }

        // Canaux de connaissance : colonnes C… (booléens) -> lignes (adhérent, canal)
        $links = [];
        foreach ($this->data['_raw_adherent'] as $id => $raw) {
            foreach (self::CANAUX as $tag => $canalId) {
                if (Conv::bool($raw[$tag] ?? '') === 1) {
                    $links[] = ['adherent_id' => $id, 'canal_id' => $canalId];
                }
            }
        }
        $this->data['adherent_canal'] = $links;

        // Lieu de signature : normalise les variantes de « Rillieux-la-Pape »
        foreach ($this->data['inscription'] as &$row) {
            $v = $row['fait_a'];
            if ($v !== null && str_replace([' ', '-'], '', mb_strtolower($v)) === 'rillieuxlapape') {
                $row['fait_a'] = 'Rillieux-la-Pape';
            }
        }
        unset($row);

        // WinDev stocke « 0 » quand le code postal n'est pas renseigné
        $cp0 = 0;
        foreach ($this->data['adherent'] as &$a) {
            if ($a['code_postal'] === '0') {
                $a['code_postal'] = null;
                $cp0++;
            }
        }
        unset($a);
        if ($cp0) {
            $this->note('Adhérents', "$cp0 code(s) postal(aux) « 0 » (non renseigné dans WinDev) enregistré(s) comme vide(s)");
        }
    }

    // ------------------------------------------------------------------ fusion des doublons d'inscription

    private function mergeInscriptions(): void
    {
        $reglByInsc = [];
        foreach ($this->data['reglement'] as $id => $r) {
            $reglByInsc[$r['inscription_id']][] = $id;
        }
        $groups = [];
        foreach ($this->data['inscription'] as $id => $r) {
            $groups[$r['adherent_id'] . '|' . $r['section_id'] . '|' . $r['saison_id']][] = $id;
        }
        $oldest = fn (int $a, int $b): int =>
            [$this->data['inscription'][$a]['date_inscription'] ?? '9999', $a] <=> [$this->data['inscription'][$b]['date_inscription'] ?? '9999', $b];

        $remap = [];
        $cat = ['aucun_reglement' => 0, 'un_seul_paye' => 0, 'plusieurs_payes' => 0];
        $removed = 0;
        $multi = [];
        $single = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $paid = array_values(array_filter($ids, fn ($i) => !empty($reglByInsc[$i])));
            usort($ids, $oldest);
            usort($paid, $oldest);
            if (count($paid) >= 2) {
                $kept = $paid[0];
                $cat['plusieurs_payes']++;
                $sum = 0.0;
                $rem = [];
                foreach ($paid as $i) {
                    $sum += (float) $this->data['inscription'][$i]['montant'];
                    if (($t = $this->data['inscription'][$i]['remarques']) !== null) {
                        $rem[$t] = true;
                    }
                }
                $this->data['inscription'][$kept]['montant'] = number_format($sum, 2, '.', '');
                $this->data['inscription'][$kept]['remarques'] = $rem ? implode("\n", array_keys($rem)) : null;
                $multi[] = $kept;
            } elseif (count($paid) === 1) {
                $kept = $paid[0];
                $cat['un_seul_paye']++;
                $single[] = $kept;
            } else {
                $kept = end($ids); // le plus récent
                $cat['aucun_reglement']++;
            }
            foreach ($ids as $i) {
                if ($i !== $kept) {
                    $remap[$i] = $kept;
                    unset($this->data['inscription'][$i]);
                    $removed++;
                }
            }
        }
        $moved = 0;
        foreach ($this->data['reglement'] as &$r) {
            if (isset($remap[$r['inscription_id']])) {
                $r['inscription_id'] = $remap[$r['inscription_id']];
                $moved++;
            }
        }
        unset($r);

        $this->note('Fusion des doublons d\'inscription', sprintf(
            '%d groupe(s) fusionné(s), %d inscription(s) supprimée(s), %d règlement(s) rattaché(s) à l\'inscription conservée',
            array_sum($cat), $removed, $moved
        ));
        $this->note('Fusion des doublons d\'inscription', sprintf(
            'dont %d sans règlement (plus récente gardée), %d avec un seul payé (celle-ci gardée), %d avec plusieurs payés (plus ancienne gardée, montants additionnés)',
            $cat['aucun_reglement'], $cat['un_seul_paye'], $cat['plusieurs_payes']
        ));

        // Écarts montant d'inscription / total des règlements, après fusion
        $paidSum = [];
        foreach ($this->data['reglement'] as $r) {
            $paidSum[$r['inscription_id']] = ($paidSum[$r['inscription_id']] ?? 0.0) + (float) $r['montant'];
        }
        $gap = static fn (array $row, array $ps): bool => abs(($ps[$row['id']] ?? 0.0) - (float) $row['montant']) > 0.005;
        foreach ([['plusieurs payés (à vérifier)', $multi], ['un seul payé (à vérifier)', $single]] as [$label, $list]) {
            $bad = [];
            foreach ($list as $i) {
                $row = $this->data['inscription'][$i];
                if ($gap($row, $paidSum)) {
                    $bad[] = sprintf('inscription %d : montant %s, règlements %s', $i, $row['montant'], number_format($paidSum[$i] ?? 0, 2, '.', ''));
                }
            }
            foreach ($bad as $b) {
                $this->note("Écarts après fusion — $label", $b);
            }
        }
        $all = 0;
        foreach ($this->data['inscription'] as $row) {
            $all += $gap($row, $paidSum) ? 1 : 0;
        }
        $this->note('Inscriptions', "$all inscription(s) dont le montant diffère du total des règlements (non bloquant, conservées telles quelles)");
        $sansReglement = count(array_diff_key($this->data['inscription'], $paidSum));
        $this->note('Inscriptions', "$sansReglement inscription(s) sans aucun règlement");
    }

    // ------------------------------------------------------------------ contrôles

    private function checkAccounting(): void
    {
        $sums = [];
        $withDetail = [];
        foreach ($this->data['detail_compte'] as $d) {
            $withDetail[$d['compte_id']] = true;
            $sums[$d['compte_id']] = ($sums[$d['compte_id']] ?? 0.0) + (float) $d['credit'] + (float) $d['debit'];
        }
        $sans = array_keys(array_diff_key($this->data['compte'], $withDetail));
        if ($sans) {
            $this->note('Comptabilité', count($sans) . ' ligne(s) bancaire(s) sans ventilation (compte ' . implode(', ', $sans) . ')');
        }
        $bad = [];
        foreach ($this->data['compte'] as $id => $c) {
            if (isset($sums[$id]) && abs($sums[$id] - (float) $c['valeur']) > 0.005) {
                $bad[] = $id;
            }
        }
        if ($bad) {
            $this->note('Comptabilité', count($bad) . ' ligne(s) bancaire(s) dont la ventilation ne totalise pas la valeur (compte ' . implode(', ', $bad) . ')');
        }
        $nosec = $notype = 0;
        foreach ($this->data['_raw_detail_compte'] as $raw) {
            $nosec += Conv::fk($raw['IDSection_Principale'] ?? '') === null ? 1 : 0;
            $notype += Conv::fk($raw['IDType_Compte'] ?? '') === null ? 1 : 0;
        }
        $this->note('Comptabilité', "$nosec ventilation(s) sans section et $notype sans type (valeur 0 de WinDev convertie en NULL)");

        $conf = [];
        foreach ($this->data['_raw_reglement'] as $id => $raw) {
            $t = mb_strtolower(trim($raw['TypeTexte'] ?? ''));
            $type = (int) ($raw['Type'] ?? 0);
            if ($t !== '' && isset(self::TYPE_TEXTES[$type]) && !in_array($t, self::TYPE_TEXTES[$type], true)) {
                $conf[] = $id;
            }
        }
        if ($conf) {
            $this->note('Règlements', count($conf) . " règlement(s) dont le Type (numérique, retenu) contredit le libellé TypeTexte (règlement " . implode(', ', array_slice($conf, 0, 40)) . (count($conf) > 40 ? ', …' : '') . ')');
        }
    }

    private function checkPersonal(): void
    {
        $cp4 = $tel9 = 0;
        foreach ($this->data['adherent'] as $a) {
            if ($a['code_postal'] !== null && preg_match('/^\d{4}$/', $a['code_postal'])) {
                $cp4++;
            }
            if ($a['telephone1'] !== null && preg_match('/^[1-9]\d{8}$/', $a['telephone1'])) {
                $tel9++;
            }
        }
        if ($cp4) {
            $this->note('Adhérents', "$cp4 code(s) postal(aux) à 4 chiffres (zéro initial probablement perdu dans WinDev), non corrigés");
        }
        if ($tel9) {
            $this->note('Adhérents', "$tel9 téléphone(s) à 9 chiffres sans zéro initial, non corrigés");
        }
    }

    // ------------------------------------------------------------------ écriture

    /** @return array<string,array{inserted:int,updated:int,deleted:int}> */
    private function write(bool $dryRun): array
    {
        $pdo = $this->pdo;
        $driver = Db::driver($pdo);
        $stats = [];

        // Les clés étrangères sont vérifiées explicitement avant validation : cela permet de supprimer
        // et d'insérer dans n'importe quel ordre (par ex. une inscription remplacée par sa jumelle).
        if ($driver === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $pdo->beginTransaction();
        try {
            $this->seedReferences();

            $pdo->exec('DELETE FROM `adherent_canal`');

            // Créneaux : mis à jour par (section, jour, ordre) pour garder des identifiants stables.
            $slotKey = static fn (array $r): string => $r['section_id'] . '|' . $r['jour'] . '|' . $r['ordre'];
            $wanted = [];
            foreach ($this->data['creneau'] as $row) {
                $wanted[$slotKey($row)] = $row;
            }
            $slotStats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
            $present = [];
            foreach ($pdo->query('SELECT id, section_id, jour, ordre FROM `creneau`') as $r) {
                $k = $slotKey($r);
                if (isset($wanted[$k])) {
                    $present[$k] = true;
                } else {
                    $pdo->prepare('DELETE FROM `creneau` WHERE id = ?')->execute([$r['id']]);
                    $slotStats['deleted']++;
                }
            }
            if ($wanted) {
                $stmt = Db::upsertStatement($pdo, 'creneau', ['section_id', 'jour', 'ordre', 'heure_debut'], ['section_id', 'jour', 'ordre']);
                foreach ($wanted as $k => $row) {
                    $stmt->execute($row);
                    isset($present[$k]) ? $slotStats['updated']++ : $slotStats['inserted']++;
                }
            }

            $existing = [];
            foreach (array_reverse(self::ORDER) as $t) {
                $existing[$t] = array_map('intval', $pdo->query("SELECT id FROM `$t`")->fetchAll(PDO::FETCH_COLUMN));
                $stale = array_values(array_diff($existing[$t], array_keys($this->data[$t])));
                foreach (array_chunk($stale, 500) as $chunk) {
                    $pdo->prepare("DELETE FROM `$t` WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
                }
                $stats[$t] = ['inserted' => 0, 'updated' => 0, 'deleted' => count($stale)];
            }

            $tokens = [];
            foreach ($pdo->query('SELECT id, presence_token FROM `section`') as $r) {
                $tokens[(int) $r['id']] = $r['presence_token'];
            }

            foreach (self::ORDER as $t) {
                $rows = $this->data[$t];
                if (!$rows) {
                    continue;
                }
                $cols = array_keys(reset($rows));
                $noUpdate = [];
                if ($t === 'section') {
                    $cols[] = 'presence_token';
                    $noUpdate = ['presence_token'];
                }
                $stmt = Db::upsertStatement($pdo, $t, $cols, ['id'], $noUpdate);
                $known = array_flip($existing[$t]);
                foreach ($rows as $id => $row) {
                    if ($t === 'section') {
                        $row['presence_token'] = $tokens[$id] ?? self::uuid();
                    }
                    $stmt->execute($row);
                    isset($known[$id]) ? $stats[$t]['updated']++ : $stats[$t]['inserted']++;
                }
            }
            $stats['creneau'] = $slotStats;
            // Simple table de liaison : reconstruite à chaque import (aucune donnée propre à l'application web).
            $stmt = $pdo->prepare('INSERT INTO `adherent_canal` (`adherent_id`, `canal_id`) VALUES (:adherent_id, :canal_id)');
            foreach ($this->data['adherent_canal'] as $row) {
                $stmt->execute($row);
            }
            $stats['adherent_canal'] = ['inserted' => count($this->data['adherent_canal']), 'updated' => 0, 'deleted' => 0];

            $this->assertForeignKeys();

            $summary = json_encode($stats, JSON_UNESCAPED_UNICODE);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO `import_run` (`debut`, `fin`, `resume`) VALUES (?, ?, ?)')
                ->execute([$now, $now, ($dryRun ? '[simulation] ' : '') . $summary]);

            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($driver === 'mysql') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        return $stats;
    }

    private function seedReferences(): void
    {
        $seeds = Schema::seeds();
        $stmt = Db::upsertStatement($this->pdo, 'mode_reglement', ['id', 'libelle'], ['id']);
        foreach ($seeds['mode_reglement'] as [$id, $label]) {
            $stmt->execute(['id' => $id, 'libelle' => $label]);
        }
        $stmt = Db::upsertStatement($this->pdo, 'canal', ['id', 'code', 'libelle'], ['id']);
        foreach ($seeds['canal'] as [$id, $code, $label]) {
            $stmt->execute(['id' => $id, 'code' => $code, 'libelle' => $label]);
        }
    }

    private function assertForeignKeys(): void
    {
        $errors = [];
        foreach (Schema::tables() as $table => $t) {
            foreach ($t['fk'] ?? [] as $col => $parent) {
                $n = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM `$table` c LEFT JOIN `$parent` p ON c.`$col` = p.id WHERE c.`$col` IS NOT NULL AND p.id IS NULL"
                )->fetchColumn();
                if ($n > 0) {
                    $errors[] = "$table.$col -> $parent : $n ligne(s) sans parent";
                }
            }
        }
        if ($errors) {
            throw new RuntimeException("Import annulé, références cassées dans l'export WinDev :\n  " . implode("\n  ", $errors));
        }
    }

    public static function version(): string
    {
        $f = dirname(__DIR__, 2) . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : '?';
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ------------------------------------------------------------------ rapport

    private function note(string $section, string $line): void
    {
        $this->report[$section][] = $line;
    }

    /** @param array<string,mixed> $summary */
    private function formatReport(array $summary): string
    {
        $out = ['RAPPORT D\'IMPORT WINDEV (application v' . self::version() . ') — ' . date('d/m/Y H:i:s') . ($summary['dry_run'] ? '  (SIMULATION : rien n\'a été écrit)' : ''), ''];
        $out[] = 'Lignes par table (ajoutées / mises à jour / supprimées) :';
        foreach ($summary['tables'] as $t => $s) {
            $out[] = sprintf('  %-20s +%-5d ~%-5d -%d', $t, $s['inserted'], $s['updated'], $s['deleted']);
        }
        foreach ($this->report as $section => $lines) {
            $out[] = '';
            $out[] = "## $section";
            foreach ($lines as $l) {
                $out[] = "  - $l";
            }
        }
        return implode("\n", $out) . "\n";
    }
}
