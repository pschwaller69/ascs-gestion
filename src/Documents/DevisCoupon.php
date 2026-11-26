<?php
declare(strict_types=1);

namespace Ascs\Documents;

use Ascs\Domain\Adherents;
use Ascs\Domain\Inscriptions;
use Ascs\Mail\Envoi;
use Ascs\Parametres;
use PDO;

require_once dirname(__DIR__) . '/Vendor/fpdf/fpdf.php';

/**
 * Devis d'inscription « coupon sport et/ou coupon culture » de la ville de Rillieux-la-Pape (modèle 2026-2027),
 * pré-rempli : partie famille (identité, représentant légal, coordonnées ; date et signature laissées à la famille)
 * et partie association (activité, cotisation, date, signature et tampon). Envoyé par mail avec la procédure de la ville.
 */
final class DevisCoupon
{
    public const NOM_ASSOCIATION = 'ASSOCIATION SPORTIVE ET CULTURELLE DES SEMAILLES';
    public const PIED = "Dossier à déposer en ligne sur le site de la ville demarches.rillieuxlapape.fr ou bien à la Maison de la Vie Locale et du Bénévolat, place de Verdun, avec une copie du justificatif de versement de l'ARS pour {annee} et la partie « à remplir par la famille », avant le 9 novembre {annee}.\nPour plus d'informations : 04 37 85 00 00";
    public const OBJET = 'Devis coupon sport / culture – {prenom} {nom}';
    public const TEXTE = "Bonjour,\n\nVous avez demandé le coupon sport ou culture de la mairie de Rillieux-la-Pape pour l'inscription de {prenom} ({activites}).\n\nVous trouverez ci-joint :\n- le devis d'inscription déjà rempli par l'association : vérifiez la partie « à remplir par la famille », datez-la et signez-la ;\n- la procédure de la ville.\n\nDéposez le devis avec l'attestation de la CAF (allocation de rentrée scolaire {annee}) sur demarches.rillieuxlapape.fr, ou à la Maison de la Vie Locale et du Bénévolat, place de Verdun, avant le 9 novembre {annee}.\n\nCordialement,\n{association}";

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ réglages

    public function reglage(string $cle): string
    {
        $defaut = ['nom' => self::NOM_ASSOCIATION, 'pied' => self::PIED, 'objet' => self::OBJET, 'texte' => self::TEXTE][$cle] ?? '';
        $v = Parametres::get($this->pdo, 'coupon_' . $cle);
        return $v !== null && trim($v) !== '' ? $v : $defaut;
    }

    public function enregistrer(array $in): void
    {
        foreach (['nom' => 150, 'pied' => 1500, 'objet' => 200, 'texte' => 4000] as $k => $max) {
            $v = trim(str_replace("\r\n", "\n", (string) ($in['coupon_' . $k] ?? '')));
            Parametres::set($this->pdo, 'coupon_' . $k, mb_substr($v, 0, $max));
        }
    }

    public static function fichierProcedure(): string
    {
        return Reglages::dossier() . '/procedure_coupon.pdf';
    }

    public static function procedure(): ?string
    {
        return is_file(self::fichierProcedure()) ? self::fichierProcedure() : null;
    }

    /** @return ?string message d'erreur */
    public static function deposerProcedure(array $fichier): ?string
    {
        if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $fichier['tmp_name'])) {
            return 'Fichier non reçu.';
        }
        if ((int) $fichier['size'] > 8 * 1024 * 1024) {
            return 'PDF trop lourd (8 Mo au plus).';
        }
        $data = (string) file_get_contents((string) $fichier['tmp_name']);
        if (!str_starts_with($data, '%PDF')) {
            return 'Choisissez un fichier PDF.';
        }
        if (!is_dir(Reglages::dossier())) {
            mkdir(Reglages::dossier(), 0700, true);
        }
        file_put_contents(self::fichierProcedure(), $data);
        return null;
    }

    /** Année des dates du devis : celle où commence la saison de l'inscription. */
    private static function annee(array $i): string
    {
        return preg_match('/^(\d{4})/', (string) ($i['saison'] ?? ''), $m) ? $m[1] : date('Y');
    }

    private function remplacer(string $t, array $i, array $a, array $activites): string
    {
        $r = (new Reglages($this->pdo))->tous();
        return strtr($t, [
            '{prenom}' => trim((string) ($a['prenom'] ?? '')), '{nom}' => trim((string) ($a['nom'] ?? '')),
            '{activites}' => implode(', ', $activites), '{annee}' => self::annee($i), '{association}' => $r['association'] ?: 'ASCS',
        ]);
    }

    // ------------------------------------------------------------------ PDF

    /**
     * Un devis par inscription, réunis dans un seul PDF (une page chacun).
     * @param list<int> $inscriptionIds
     * @return ?array{pdf:string,nom:string,adherent:array,inscriptions:list<array>}
     */
    public function generer(array $inscriptionIds): ?array
    {
        $ins = [];
        foreach ($inscriptionIds as $id) {
            $i = (new Inscriptions($this->pdo))->trouver((int) $id);
            if ($i) {
                $ins[] = $i;
            }
        }
        if (!$ins) {
            return null;
        }
        $a = (new Adherents($this->pdo))->trouver((int) $ins[0]['adherent_id']) ?? [];
        $r = (new Reglages($this->pdo))->tous();
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(18, 15, 18);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Devis coupon sport / culture', true);
        $pdf->SetAuthor($r['association'], true);
        foreach ($ins as $i) {
            $this->page($pdf, $i, $a);
        }
        $nom = 'Devis_coupon_' . preg_replace('/[^A-Za-z0-9]+/', '_', self::ascii(trim(($a['nom'] ?? '') . '_' . ($a['prenom'] ?? '')))) . '.pdf';
        return ['pdf' => $pdf->Output('S'), 'nom' => $nom, 'adherent' => $a, 'inscriptions' => $ins];
    }

    private function page(\FPDF $pdf, array $i, array $a): void
    {
        $pdf->AddPage();
        $annee = self::annee($i);
        $nom = mb_strtoupper(trim((string) ($a['nom'] ?? '')));
        $prenom = trim((string) ($a['prenom'] ?? ''));
        $parent = trim(mb_strtoupper((string) ($a['nom_parent'] ?? '')) . ' ' . (string) ($a['prenom_parent'] ?? ''));
        $majeur = ($age = self::age((string) ($a['date_naissance'] ?? ''))) !== null && $age >= 18;
        $representant = $parent !== '' ? $parent : ($majeur ? trim($nom . ' ' . $prenom) : '');
        $adresse = trim(trim((string) ($a['adresse'] ?? '')) . ' ' . trim(((string) ($a['code_postal'] ?? '') === '0' ? '' : (string) ($a['code_postal'] ?? '')) . ' ' . (string) ($a['ville'] ?? '')));
        $tel = trim((string) ($a['telephone1'] ?? '')) ?: trim((string) ($a['telephone2'] ?? ''));
        $activite = trim((string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $i['section']));

        // ---- Partie famille
        $this->titre($pdf, 18);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetXY(18, 32);
        $pdf->Cell(174, 7, self::t('PARTIE À REMPLIR PAR LA FAMILLE'), 0, 1);
        $pdf->Ln(2);
        $this->ligne2($pdf, "Nom de l'enfant", $nom, "Prénom de l'enfant", $prenom);
        $y = $pdf->GetY();
        $this->champ($pdf, 'Date de naissance', self::date((string) ($a['date_naissance'] ?? '')), 18, 84);
        $pdf->SetXY(108, $y);
        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->Cell(16, 8, 'Sexe :');
        $genre = (string) ($a['genre'] ?? '');
        $this->case($pdf, 'F', $genre === '0');
        $this->case($pdf, 'M', $genre === '1');
        $pdf->SetXY(18, $y + 10);
        $this->champ($pdf, 'NOM – Prénom du représentant légal', $representant, 18, 174);
        $this->champ($pdf, 'Adresse', $adresse, 18, 174);
        $y = $pdf->GetY();
        $this->champ($pdf, 'Adresse mail', (string) ($a['mail'] ?? ''), 18, 104);
        $pdf->SetXY(126, $y);
        $this->champ($pdf, 'Téléphone', $tel, 126, 66);
        $pdf->SetFont('Helvetica', 'I', 8.5);
        $pdf->SetX(18);
        $pdf->MultiCell(174, 4.5, self::t('(Mail et/ou téléphone portable conseillés pour information des suites réservées à votre demande).'));
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->Cell(100, 8, self::t('Fait à Rillieux-la-Pape, le ____ / ____ / ' . $annee));
        $pdf->Cell(74, 8, self::t('Signature du représentant légal'), 0, 1);
        $pdf->Rect(118, $pdf->GetY(), 74, 22);
        $pdf->SetY($pdf->GetY() + 24);
        $pdf->SetFont('Helvetica', 'I', 8.5);
        $pdf->MultiCell(174, 4.5, self::t('Les informations de cette partie viennent de votre fiche à l\'association : vérifiez-les et corrigez-les à la main si besoin, puis datez et signez.'));

        // Séparation
        $y = $pdf->GetY() + 5;
        $pdf->SetDrawColor(120, 120, 120);
        for ($x = 18; $x < 192; $x += 3) {
            $pdf->Line($x, $y, min($x + 1.5, 192), $y);
        }
        $pdf->SetDrawColor(0, 0, 0);

        // ---- Partie association
        $this->titre($pdf, $y + 5);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetXY(18, $y + 19);
        $pdf->Cell(174, 7, self::t('PARTIE À REMPLIR PAR L\'ASSOCIATION'), 0, 1);
        $pdf->Ln(2);
        $this->champ($pdf, 'NOM – Prénom de l\'enfant', trim($nom . ' ' . $prenom), 18, 174);
        $this->champ($pdf, 'Nom de l\'association', $this->reglage('nom'), 18, 174);
        $this->champ($pdf, 'Activité pratiquée', $activite, 18, 174);
        $this->champ($pdf, 'Montant de la cotisation annuelle', self::euros((float) $i['montant']), 18, 174);
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->Cell(100, 8, self::t('Fait à Rillieux-la-Pape, le ' . date('d/m/Y')));
        $pdf->Cell(74, 8, self::t('Signature et cachet de l\'association'), 0, 1);
        $sig = Reglages::image('signature');
        $ySig = $pdf->GetY();
        if ($sig) {
            [$w, $h] = self::cadre($sig, 70, 28);
            $pdf->Image($sig, 118, $ySig, $w, $h);
        } else {
            $r = (new Reglages($this->pdo))->tous();
            $pdf->SetXY(118, $ySig + 2);
            $pdf->SetFont('Helvetica', 'I', 10);
            $pdf->Cell(74, 6, self::t($r['signataire'] . ', ' . $r['qualite']));
        }

        // ---- Pied : dépôt du dossier
        $pdf->SetXY(18, 250);
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pied = strtr($this->reglage('pied'), ['{annee}' => $annee]);
        $pdf->MultiCell(174, 4.8, self::t($pied), 0, 'C');
    }

    private function titre(\FPDF $pdf, float $y): void
    {
        $pdf->SetXY(18, $y);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(174, 9, self::t('Devis d\'inscription coupon sport et/ou coupon culture'), 0, 1, 'C');
    }

    private function ligne2(\FPDF $pdf, string $l1, string $v1, string $l2, string $v2): void
    {
        $y = $pdf->GetY();
        $this->champ($pdf, $l1, $v1, 18, 84);
        $pdf->SetXY(108, $y);
        $this->champ($pdf, $l2, $v2, 108, 84);
    }

    /** Libellé suivi de la valeur soulignée (comme les pointillés du modèle). */
    private function champ(\FPDF $pdf, string $libelle, string $valeur, float $x, float $largeur): void
    {
        $pdf->SetX($x);
        $pdf->SetFont('Helvetica', '', 10.5);
        $l = self::t($libelle . ' : ');
        $wl = $pdf->GetStringWidth($l) + 1;
        $pdf->Cell($wl, 8, $l);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $v = self::t($valeur);
        while ($v !== '' && $pdf->GetStringWidth($v) > $largeur - $wl - 1) {
            $v = substr($v, 0, -1);
        }
        $pdf->Cell($largeur - $wl, 8, $v, 'B', 1);
        $pdf->Ln(1.5);
    }

    private function case(\FPDF $pdf, string $lettre, bool $cochee): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY() + 2;
        $pdf->Rect($x, $y, 4, 4);
        if ($cochee) {
            $pdf->Line($x + 0.6, $y + 2.2, $x + 1.6, $y + 3.4);
            $pdf->Line($x + 1.6, $y + 3.4, $x + 3.5, $y + 0.6);
        }
        $pdf->SetX($x + 5.5);
        $pdf->Cell(12, 8, $lettre);
    }

    // ------------------------------------------------------------------ envoi

    /**
     * Envoie le devis (une page par inscription) et la procédure de la ville par mail.
     * @param list<int> $inscriptionIds
     * @return ?string message d'erreur, null si envoyé
     */
    public function envoyer(array $inscriptionIds, string $destinataire, string $par, ?string $texte = null): ?string
    {
        if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse mail invalide.';
        }
        $d = $this->generer($inscriptionIds);
        if (!$d) {
            return 'Inscription introuvable.';
        }
        $r = (new Reglages($this->pdo))->tous();
        $activites = array_values(array_unique(array_map(static fn ($i) => trim((string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $i['section'])), $d['inscriptions'])));
        $pieces = [['nom' => $d['nom'], 'contenu' => $d['pdf'], 'type' => 'application/pdf']];
        if ($proc = self::procedure()) {
            $pieces[] = ['nom' => 'Procedure_coupon_sport_culture.pdf', 'contenu' => (string) file_get_contents($proc), 'type' => 'application/pdf'];
        }
        $i0 = $d['inscriptions'][0];
        return (new Envoi($this->pdo))->envoyer(
            $r['expediteur'], $r['association'] ?: 'ASCS', $destinataire,
            $this->remplacer($this->reglage('objet'), $i0, $d['adherent'], $activites),
            $texte !== null && trim($texte) !== '' ? $texte : $this->remplacer($this->reglage('texte'), $i0, $d['adherent'], $activites),
            $pieces, (int) $i0['id'], $par
        );
    }

    /** Texte du mail proposé (modifiable avant l'envoi depuis la fiche de l'inscription). */
    public function texteMail(array $inscriptionIds): string
    {
        $d = $this->generer($inscriptionIds);
        if (!$d) {
            return '';
        }
        $activites = array_values(array_unique(array_map(static fn ($i) => trim((string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $i['section'])), $d['inscriptions'])));
        return $this->remplacer($this->reglage('texte'), $d['inscriptions'][0], $d['adherent'], $activites);
    }

    // ------------------------------------------------------------------ outils

    private static function age(string $iso): ?int
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) ? (new \DateTimeImmutable($iso))->diff(new \DateTimeImmutable('today'))->y : null;
    }

    private static function date(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : '';
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }

    private static function t(string $s): string
    {
        $s = strtr($s, ['’' => "'", '–' => '-', '—' => '-', "\u{202F}" => ' ', "\u{00A0}" => ' ']);
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    }

    private static function ascii(string $s): string
    {
        return (string) iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    }

    /** @return array{0:float,1:float} */
    private static function cadre(string $f, float $maxW, float $maxH): array
    {
        [$w, $h] = getimagesize($f) ?: [1, 1];
        $k = min($maxW / $w, $maxH / $h);
        return [$w * $k, $h * $k];
    }
}
