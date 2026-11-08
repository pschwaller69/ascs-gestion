<?php
declare(strict_types=1);

namespace Ascs\Documents;

use Ascs\Domain\Adherents;
use Ascs\Domain\Inscriptions;
use Ascs\Domain\Reglements;
use PDO;

require_once dirname(__DIR__) . '/Vendor/fpdf/fpdf.php';

/** Facture d'une inscription (modèle repris de WinDev), en PDF, avec les règlements et la mention « Réglée le … ». */
final class Facture
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return ?array{pdf:string,nom:string,inscription:array,adherent:array} */
    public function generer(int $inscriptionId): ?array
    {
        $i = (new Inscriptions($this->pdo))->trouver($inscriptionId);
        if (!$i) {
            return null;
        }
        $a = (new Adherents($this->pdo))->trouver((int) $i['adherent_id']) ?? [];
        $regs = (new Reglements($this->pdo))->deInscription($inscriptionId);
        $r = (new Reglages($this->pdo))->tous();

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(18, 15, 18);
        $pdf->SetAutoPageBreak(true, 32);
        $pdf->SetTitle('Facture ' . $inscriptionId, true);
        $pdf->SetAuthor($r['association'], true);
        $pdf->AliasNbPages();
        $pied = $this->pied($r);
        $pdf->AddPage();

        // En-tête : logo ou nom, titre et numéro
        $logo = Reglages::image('logo');
        if ($logo) {
            [$w, $h] = $this->cadre($logo, 50, 28);
            $pdf->Image($logo, 18, 14, $w, $h);
        } else {
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->SetXY(18, 18);
            $pdf->Cell(90, 8, self::t($r['association']));
        }
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetXY(110, 16);
        $pdf->Cell(82, 10, 'Facture', 0, 2, 'R');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(82, 5.5, self::t('N° ' . $inscriptionId), 0, 2, 'R');
        $pdf->Cell(82, 5.5, self::t('Date : ' . date('d/m/Y')), 0, 2, 'R');

        // Destinataire
        $parent = trim((string) ($a['nom_parent'] ?? '') . ' ' . (string) ($a['prenom_parent'] ?? ''));
        $dest = $parent !== '' ? $parent : trim((string) $i['nom'] . ' ' . (string) $i['prenom']);
        $pdf->SetXY(110, 48);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(82, 5.5, self::t($dest));
        $pdf->SetFont('Helvetica', '', 11);
        foreach ([(string) ($a['adresse'] ?? ''), trim((string) ($a['code_postal'] ?? '') . ' ' . (string) ($a['ville'] ?? ''))] as $l) {
            if (trim($l) !== '') {
                $pdf->SetX(110);
                $pdf->MultiCell(82, 5.5, self::t($l));
            }
        }

        // Inscription
        $pdf->SetXY(18, 88);
        $lignes = [
            'Inscription de' => trim((string) $i['nom'] . ' ' . (string) $i['prenom']),
            'Formule' => (string) $i['section'],
            'Saison' => (string) $i['saison'],
            "Date d'inscription" => self::date((string) $i['date_inscription']),
            'Montant' => self::euros((float) $i['montant']),
        ];
        $pdf->SetDrawColor(190, 200, 200);
        foreach ($lignes as $lib => $val) {
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->Cell(52, 9, self::t($lib), 'B');
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(122, 9, self::t($val), 'B', 1);
        }

        // Règlements
        $auj = date('Y-m-d');
        $encaisse = 0.0;
        $prevu = 0.0;
        $derniere = null;
        if ($regs) {
            $pdf->Ln(7);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(174, 7, self::t('Règlements'), 0, 1);
            $pdf->SetFont('Helvetica', '', 10);
            foreach ($regs as $g) {
                $d = substr((string) $g['date_reglement'], 0, 10);
                $venir = $d > $auj;
                $venir ? $prevu += (float) $g['montant'] : $encaisse += (float) $g['montant'];
                $derniere = max($derniere ?? '', $d);
                $moyen = (string) $g['mode'] . (in_array((int) $g['mode_reglement_id'], [6, 8], true) && Reglements::detailAide((int) $g['mode_reglement_id'], (string) $g['identifiant']) !== ''
                    ? ' (' . Reglements::detailAide((int) $g['mode_reglement_id'], (string) $g['identifiant']) . ')' : '');
                $pdf->Cell(30, 6.5, self::t(self::date($d)), 'B');
                $pdf->Cell(94, 6.5, self::t($moyen . ($venir ? ' - à venir' : '')), 'B');
                $pdf->Cell(50, 6.5, self::t(self::euros((float) $g['montant'])), 'B', 1, 'R');
            }
        }
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 11);
        $montant = (float) $i['montant'];
        if ($montant > 0 && $encaisse >= $montant - 0.005) {
            $etat = 'Réglée le ' . self::date((string) $derniere) . '.';
        } elseif ($montant > 0 && $encaisse + $prevu >= $montant - 0.005) {
            $etat = 'Réglée en totalité : ' . self::euros($encaisse) . ' encaissés, ' . self::euros($prevu) . ' à encaisser (dernière échéance le ' . self::date((string) $derniere) . ').';
        } elseif ($montant <= 0) {
            $etat = 'Inscription gratuite.';
        } else {
            $etat = 'Réglé à ce jour : ' . self::euros($encaisse + $prevu) . '. Reste à régler : ' . self::euros($montant - $encaisse - $prevu) . '.';
        }
        $pdf->MultiCell(174, 6, self::t($etat));

        // Attestation et signature
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', '', 11);
        $de = preg_match('/^[aeiouyh]/i', $r['association']) ? "de l'" : 'de ';
        $pdf->MultiCell(174, 6, self::t('Je soussigné ' . $r['signataire'] . ', ' . $r['qualite'] . ' ' . $de . $r['association']
            . ', atteste que les informations ci-dessus sont exactes.'));
        $ville = trim((string) preg_replace('/^\d{5}\s*/', '', $r['code_postal_ville']));
        $pdf->Ln(3);
        $pdf->Cell(174, 6, self::t(($ville !== '' ? 'Fait à ' . $ville . ', le ' : 'Le ') . date('d/m/Y')), 0, 1);
        $sig = Reglages::image('signature');
        if ($sig) {
            [$w, $h] = $this->cadre($sig, 65, 32);
            if ($pdf->GetY() + $h > 297 - 32) {
                $pdf->AddPage();
            }
            $pdf->Image($sig, 110, $pdf->GetY() + 2, $w, $h);
        } else {
            $pdf->SetX(110);
            $pdf->SetFont('Helvetica', 'I', 10);
            $pdf->Cell(82, 6, self::t($r['signataire'] . ', ' . $r['qualite']), 0, 1);
        }

        // Pied de page
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(90, 100, 100);
        $pdf->SetAutoPageBreak(false);
        $y = 297 - 12 - 4 * count($pied);
        foreach ($pied as $k => $l) {
            $pdf->SetXY(18, $y + 4 * $k);
            $pdf->Cell(174, 4, self::t($l), 0, 0, 'C');
        }

        $nom = 'Facture-' . $inscriptionId . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', (string) $i['nom'] . ' ' . (string) $i['prenom']) ?: '') . '.pdf';
        return ['pdf' => $pdf->Output('S'), 'nom' => str_replace('--', '-', $nom), 'inscription' => $i, 'adherent' => $a];
    }

    /** @return list<string> */
    private function pied(array $r): array
    {
        $joindre = static fn (array $x) => implode(' - ', array_filter(array_map('trim', $x), static fn ($v) => $v !== ''));
        return array_values(array_filter([
            $joindre([$r['association'], $r['adresse'], $r['code_postal_ville']]),
            $joindre([$r['telephone'] !== '' ? 'Tél. ' . $r['telephone'] : '', $r['mail'], $r['site']]),
            $joindre([$r['agrement'] !== '' ? 'Agrément Jeunesse et Sports ' . $r['agrement'] : '', $r['siret'] !== '' ? 'SIRET ' . $r['siret'] : '',
                $r['forme'], $r['naf'] !== '' ? 'NAF ' . $r['naf'] : '']),
        ], static fn ($l) => $l !== ''));
    }

    /** Taille d'une image tenant dans le cadre (mm), proportions gardées. @return array{float,float} */
    private function cadre(string $f, float $maxW, float $maxH): array
    {
        [$w, $h] = getimagesize($f) ?: [1, 1];
        $k = min($maxW / $w, $maxH / $h);
        return [$w * $k, $h * $k];
    }

    /** Texte UTF-8 vers l'encodage des polices PDF standard (Windows-1252, « € » compris). */
    private static function t(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}", '’'], [' ', ' ', "'"], $s);
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }

    private static function date(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d\d)-(\d\d)/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : '';
    }
}
