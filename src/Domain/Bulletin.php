<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Bulletin d'inscription en ligne (repris du bulletin papier 2026/2027) : règlement intérieur à accepter,
 * attestation CE, canaux, assurance « groupements sportifs », règlement prévu (moyens et aides) avec récapitulatif
 * du montant, signature (« Je soussigné(e) … », fait à, le). Stocké dans demande_complement (JSON).
 */
final class Bulletin
{
    public const REGLEMENT_DEFAUT = <<<'TXT'
Tout adhérent doit avoir remis son dossier complet avant la reprise des cours et avoir l'accord de l'intervenant, en fonction de sa capacité et de son niveau, pour pratiquer l'activité.
Toute inscription ou tout renouvellement d'inscription est ferme et définitif.
Aucune cotisation annuelle n'est remboursée, sauf sur avis médical d'un spécialiste interdisant l'activité suite à une blessure grave survenue pendant l'activité. Montant remboursé = (cotisation − adhésion et frais de dossier) ÷ 12 × nombre de mois d'interdiction.
Tout adhérent, majeur ou mineur, ne respectant pas le règlement intérieur pourra être exclu définitivement sans remboursement. Il en va de même en cas de paiement refusé en cours d'année.
Il est interdit de fumer dans tous les locaux.
Les adhérents ne doivent apporter aucun objet susceptible, même involontairement, d'endommager le matériel ou les locaux. Tout matériel ou objet extérieur doit être accepté par l'ASCS.
Ne venez avec aucun objet de valeur : l'ASCS décline toute responsabilité en cas de vol ou de perte dans ses locaux.
Les parents accompagnent et récupèrent les jeunes enfants devant la salle aux heures prévues ; l'ASCS décline toute responsabilité une fois le cours terminé. Les parents ne sont pas admis pendant les cours.
Toute absence est signalée aux parents afin de dégager la responsabilité de l'association.
Une tenue adaptée est exigée selon l'activité. Un complément de règlement peut être demandé selon les activités pratiquées.
Un certificat médical est demandé pour les activités sportives (danse, Pilates et musculation inclus).
L'adhésion autorise l'ASCS à utiliser les photos prises pendant les cours et manifestations pour tous ses besoins.
Une seule séance d'essai est autorisée pour les non-adhérents, contre une contribution de 10 €, déduite de l'adhésion en cas d'inscription à l'activité.
Tir à l'arc – pas de tir extérieur : toute personne souhaitant l'utiliser doit être accompagnée par un encadrant ou un licencié FFTA validé par le conseil d'administration et son président.
TXT;

    /** Moyens de paiement proposés (cadre « réservé à l'ASCS » du bulletin). */
    public const MOYENS = ['cheque' => 'Chèque', 'especes' => 'Espèces', 'ancv' => 'Chèques ANCV', 'cb' => 'Carte bancaire'];

    /** Frais de gestion par dossier payé en chèques ANCV / ANCV Connect. */
    public const FRAIS_ANCV = 5.0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reglementInterieur(): string
    {
        $t = trim((string) (Parametres::get($this->pdo, 'reglement_interieur') ?? ''));
        return $t !== '' ? $t : self::REGLEMENT_DEFAUT;
    }

    /** @return list<array{cle:string,libelle:string,montant:float,condition:string}> aides du bulletin, montants de Paramétrage des aides */
    public static function aides(): array
    {
        $m = [];
        foreach (Aides::liste() as $a) {
            $m[$a['cle']] = (float) $a['montant'];
        }
        return [
            ['cle' => 'coupon_sport', 'libelle' => 'Coupon sport (mairie)', 'montant' => $m['coupon_sport'] ?? 48.0,
                'condition' => 'Rilliards de 6 à 16 ans bénéficiant de l\'allocation de rentrée scolaire. Aide déduite à l\'inscription, contre un chèque de caution du même montant, rendu au versement de l\'aide par la mairie.'],
            ['cle' => 'coupon_culture', 'libelle' => 'Coupon culture (mairie)', 'montant' => $m['coupon_culture'] ?? 90.0,
                'condition' => 'Mêmes conditions que le coupon sport, pour une activité culturelle.'],
            ['cle' => 'pass_region', 'libelle' => 'Carte Pass\'Région', 'montant' => $m['pass_region'] ?? 30.0,
                'condition' => 'Aide de la Région sur la cotisation annuelle, versée une fois l\'inscription entièrement réglée. Copie de la carte à fournir.'],
            ['cle' => 'pass_sport', 'libelle' => 'Pass\'Sport', 'montant' => $m['pass_sport'] ?? 50.0,
                'condition' => '6-17 ans avec allocation de rentrée scolaire, 6-20 ans avec AEEH, 16-30 ans avec AAH, étudiants boursiers jusqu\'à 28 ans. Indiquez le code Pass\'Sport.'],
        ];
    }

    /**
     * Lit et contrôle la partie « bulletin » du formulaire d'inscription.
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public function nettoyer(array $in, array $d): array
    {
        $err = [];
        $c = [];
        $tel2 = trim((string) ($in['telephone2'] ?? ''));
        $c['telephone2'] = $tel2 !== '' ? mb_substr($tel2, 0, 30) : null;
        if ($tel2 !== '' && strlen(preg_replace('/\D/', '', $tel2)) < 9) {
            $err['telephone2'] = 'Numéro incomplet.';
        }
        $c['attestation_ce'] = ($in['attestation_ce'] ?? '') === '1' ? 1 : 0;
        $canaux = (int) $this->pdo->query('SELECT COUNT(*) FROM canal')->fetchColumn() ? $this->pdo->query('SELECT id FROM canal')->fetchAll(PDO::FETCH_COLUMN) : [];
        $c['canaux'] = array_values(array_intersect(array_map('intval', (array) ($in['canaux'] ?? [])), array_map('intval', $canaux)));
        $c['canal_autre'] = mb_substr(trim((string) ($in['canal_autre'] ?? '')), 0, 100) ?: null;

        $ass = (string) ($in['assurance'] ?? '');
        if (!in_array($ass, ['oui', 'non'], true)) {
            $err['assurance'] = 'Indiquez si vous souscrivez l\'assurance « groupements sportifs ».';
        }
        $c['assurance'] = $ass ?: null;
        $c['assurance_option'] = $ass === 'oui' ? mb_substr(trim((string) ($in['assurance_option'] ?? '')), 0, 10) : null;

        $c['moyens'] = array_values(array_intersect(array_keys(self::MOYENS), (array) ($in['moyens'] ?? [])));
        $c['nb_cheques'] = in_array('cheque', $c['moyens'], true) ? max(1, min(10, (int) ($in['nb_cheques'] ?? 1))) : null;
        $c['aides'] = array_values(array_intersect(array_column(self::aides(), 'cle'), (array) ($in['aides'] ?? [])));
        $c['code_pass_sport'] = in_array('pass_sport', $c['aides'], true) ? mb_substr(trim((string) ($in['code_pass_sport'] ?? '')), 0, 30) : null;
        if (in_array('pass_sport', $c['aides'], true) && !$c['code_pass_sport']) {
            $err['code_pass_sport'] = 'Indiquez le code Pass\'Sport (ou présentez le QR code au bureau et décochez l\'aide).';
        }
        if (!$c['moyens'] && !$c['aides']) {
            $err['moyens'] = 'Indiquez comment vous comptez régler.';
        }

        $c['soussigne'] = mb_substr(trim((string) ($in['soussigne'] ?? '')), 0, 120);
        if ($c['soussigne'] === '') {
            $err['soussigne'] = 'Écrivez votre nom et prénom pour signer.';
        }
        if (empty($in['reglement_lu'])) {
            $err['reglement_lu'] = 'Cochez la case pour confirmer avoir lu le règlement intérieur.';
        }
        $c['fait_a'] = mb_substr(trim((string) ($in['fait_a'] ?? '')), 0, 60) ?: ($d['ville'] ?? null);
        $c['signe_le'] = date('Y-m-d H:i:s');
        $c['recap'] = $this->recap($d, $c);
        return [$c, $err];
    }

    /**
     * Récapitulatif du montant : tarifs des activités (réduction Rillieux), aides déduites, frais ANCV, reste à régler.
     * @return array{lignes:list<array{libelle:string,montant:float}>,total:float,aides:list<array{libelle:string,montant:float}>,frais:float,reste:float,rillieux:bool}
     */
    public function recap(array $d, array $c): array
    {
        $ids = array_map('intval', json_decode((string) ($d['sections'] ?? '[]'), true) ?: []);
        $rill = Tarifs::habitantRillieux($d['ville'] ?? null, $d['code_postal'] ?? null);
        $lignes = [];
        if ($ids) {
            $st = $this->pdo->query('SELECT id, titre, montant FROM section WHERE id IN (' . implode(',', $ids) . ') ORDER BY titre');
            foreach ($st->fetchAll() as $s) {
                $lignes[] = ['libelle' => (string) $s['titre'], 'montant' => Tarifs::montant((float) $s['montant'], (string) $s['titre'], $rill)];
            }
        }
        $total = array_sum(array_column($lignes, 'montant'));
        $aides = [];
        foreach (self::aides() as $a) {
            if (in_array($a['cle'], $c['aides'] ?? [], true)) {
                $aides[] = ['libelle' => $a['libelle'], 'montant' => $a['montant']];
            }
        }
        $frais = in_array('ancv', $c['moyens'] ?? [], true) ? self::FRAIS_ANCV : 0.0;
        $reste = max(0.0, $total - array_sum(array_column($aides, 'montant'))) + $frais;
        return ['lignes' => $lignes, 'total' => $total, 'aides' => $aides, 'frais' => $frais, 'reste' => round($reste, 2), 'rillieux' => $rill];
    }

    /** Pièces à apporter selon les activités et les aides choisies. @return list<string> */
    public function pieces(array $d, array $c): array
    {
        $ids = array_map('intval', json_decode((string) ($d['sections'] ?? '[]'), true) ?: []);
        $p = [];
        if ($ids) {
            $sport = (int) $this->pdo->query('SELECT COUNT(*) FROM section s JOIN section_principale sp ON sp.id = s.section_principale_id
                JOIN famille_section f ON f.id = sp.famille_section_id WHERE s.id IN (' . implode(',', $ids) . ") AND (LOWER(f.titre) LIKE '%sport%' OR LOWER(f.titre) LIKE '%bien%')")->fetchColumn();
            $danse = (int) $this->pdo->query('SELECT COUNT(*) FROM section WHERE id IN (' . implode(',', $ids) . ") AND (UPPER(titre) LIKE '%DANSE%' OR UPPER(titre) LIKE '%PILATES%' OR UPPER(titre) LIKE '%MUSCU%')")->fetchColumn();
            if ($sport || $danse) {
                $p[] = 'Un certificat médical (activité sportive, danse, Pilates et musculation compris)';
            }
        }
        if (array_intersect(['coupon_sport', 'coupon_culture'], $c['aides'] ?? [])) {
            $p[] = 'Le coupon de la mairie et un chèque de caution du même montant';
        }
        if (in_array('pass_region', $c['aides'] ?? [], true)) {
            $p[] = 'Une copie de la carte Pass\'Région';
        }
        if (in_array('pass_sport', $c['aides'] ?? [], true)) {
            $p[] = 'Le QR code ou le courrier Pass\'Sport';
        }
        if (in_array('cheque', $c['moyens'] ?? [], true)) {
            $n = (int) ($c['nb_cheques'] ?? 1);
            $p[] = $n > 1 ? $n . ' chèques à l\'ordre de l\'ASCS, encaissés le 1er ou le 15 de chaque mois' : 'Le chèque à l\'ordre de l\'ASCS';
        }
        return $p;
    }

    public function enregistrer(int $demandeId, array $c): void
    {
        $this->pdo->prepare('DELETE FROM demande_complement WHERE demande_id = ?')->execute([$demandeId]);
        $this->pdo->prepare('INSERT INTO demande_complement (demande_id, donnees) VALUES (?,?)')->execute([$demandeId, json_encode($c, JSON_UNESCAPED_UNICODE)]);
    }

    public function lire(int $demandeId): ?array
    {
        $st = $this->pdo->prepare('SELECT donnees FROM demande_complement WHERE demande_id = ?');
        $st->execute([$demandeId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (json_decode((string) $v, true) ?: null);
    }

    /** Texte « règlement prévu » repris dans les remarques de l'inscription. */
    public static function resumeReglement(array $c): string
    {
        $m = array_map(static fn ($k) => $k === 'cheque' && ($c['nb_cheques'] ?? 1) > 1 ? $c['nb_cheques'] . ' chèques' : self::MOYENS[$k], $c['moyens'] ?? []);
        $a = [];
        foreach (self::aides() as $x) {
            if (in_array($x['cle'], $c['aides'] ?? [], true)) {
                $a[] = $x['libelle'] . ($x['cle'] === 'pass_sport' && !empty($c['code_pass_sport']) ? ' (code ' . $c['code_pass_sport'] . ')' : '');
            }
        }
        $r = $c['recap'] ?? null;
        return 'Règlement prévu : ' . implode(', ', array_merge($m, $a)) . ($r ? ' ; reste à régler annoncé ' . number_format((float) $r['reste'], 2, ',', ' ') . ' €' : '')
            . ' ; assurance groupements sportifs : ' . (($c['assurance'] ?? '') === 'oui' ? 'oui' . (!empty($c['assurance_option']) ? ' (option ' . $c['assurance_option'] . ')' : '') : 'non');
    }
}
