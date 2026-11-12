<?php
declare(strict_types=1);

namespace Ascs\Documents;

/**
 * Classeur Excel (.xlsx) minimal, sans bibliothèque : plusieurs feuilles, en-tête en gras figé avec filtre,
 * dates et montants en vrais nombres (les totaux et tableaux croisés marchent dans Excel).
 * Une cellule est une valeur simple, ou ['v' => valeur, 't' => 'date'|'euro'|'gras'|'euro_gras'], avec 'f' => formule (sans « = ») :
 * la valeur 'v' sert alors de résultat déjà calculé, Excel recalcule à l'ouverture.
 */
final class Xlsx
{
    /** @var list<array{nom:string,lignes:list<list<mixed>>,largeurs:list<int>,entete:bool}> */
    private array $feuilles = [];

    public static function disponible(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /** @param list<list<mixed>> $lignes  @param list<int> $largeurs largeur des colonnes en caractères */
    public function feuille(string $nom, array $lignes, array $largeurs = [], bool $entete = true): void
    {
        $nom = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $nom), 0, 31);
        $this->feuilles[] = ['nom' => $nom, 'lignes' => $lignes, 'largeurs' => $largeurs, 'entete' => $entete];
    }

    public function contenu(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $n = count($this->feuilles);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '';
        $rels = '';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::x($this->feuilles[$i - 1]['nom']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Styles : 0 normal, 1 gras, 2 date, 3 euro, 4 euro gras
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;;\-#,##0.00\ &quot;€&quot;"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>');
        foreach ($this->feuilles as $i => $f) {
            $z->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->feuilleXml($f));
        }
        $z->close();
        $s = (string) file_get_contents($tmp);
        unlink($tmp);
        return $s;
    }

    private function feuilleXml(array $f): string
    {
        $nbCol = 1;
        foreach ($f['lignes'] as $l) {
            $nbCol = max($nbCol, count($l));
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($f['entete'] && $f['lignes']) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($f['largeurs']) {
            $x .= '<cols>';
            foreach ($f['largeurs'] as $c => $w) {
                $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($f['lignes'] as $r => $ligne) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($ligne) as $c => $cel) {
                $x .= $this->cellule(self::col($c) . ($r + 1), $cel, $f['entete'] && $r === 0);
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($f['entete'] && count($f['lignes']) > 1) {
            $x .= '<autoFilter ref="A1:' . self::col($nbCol - 1) . count($f['lignes']) . '"/>';
        }
        return $x . '</worksheet>';
    }

    private function cellule(string $ref, mixed $cel, bool $entete): string
    {
        $t = $entete ? 'gras' : null;
        $f = null;
        if (is_array($cel)) {
            $t = $cel['t'] ?? $t;
            $f = $cel['f'] ?? null;
            $cel = $cel['v'] ?? null;
        }
        if ($f !== null) {
            $s = ['gras' => 1, 'date' => 2, 'euro' => 3, 'euro_gras' => 4][$t ?? ''] ?? 0;
            return '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . '><f>' . self::x(ltrim((string) $f, '=')) . '</f>'
                . (is_int($cel) || is_float($cel) ? '<v>' . round((float) $cel, 2) . '</v>' : '') . '</c>';
        }
        if ($cel === null || $cel === '') {
            return '';
        }
        $s = ['gras' => 1, 'date' => 2, 'euro' => 3, 'euro_gras' => 4][$t ?? ''] ?? 0;
        $st = $s ? ' s="' . $s . '"' : '';
        if ($t === 'date' && is_string($cel) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $cel, $m)) {
            $j = (int) ((gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
            return '<c r="' . $ref . '"' . $st . '><v>' . $j . '</v></c>';
        }
        if (is_int($cel) || is_float($cel)) {
            return '<c r="' . $ref . '"' . $st . '><v>' . ($t === 'euro' || $t === 'euro_gras' ? round((float) $cel, 2) : $cel) . '</v></c>';
        }
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $cel);
        return '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
