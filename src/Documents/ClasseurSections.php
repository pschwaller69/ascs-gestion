<?php
declare(strict_types=1);

namespace Ascs\Documents;

/**
 * Classeur « résultat par section » pour le comptable, sur le modèle de l'export WinDev :
 * une feuille Synthèse, puis une feuille par section principale avec trois blocs côte à côte
 * (banque | règlements des inscriptions | frais ASCS par inscription) et des totaux en formules.
 */
final class ClasseurSections
{
    private const FIN = 3000;

    /** @param array $r résultat de ResultatSections::calculer  @param ?array $prec même calcul pour la saison précédente */
    public static function construire(array $r, string $saison, array $periode, ?array $prec = null, string $saisonPrec = ''): Xlsx
    {
        $x = new Xlsx();
        $eu = static fn ($v) => ['v' => (float) $v, 't' => 'euro'];
        $g = static fn ($v) => ['v' => $v, 't' => 'gras'];
        $dt = static fn ($v) => $v ? ['v' => $v, 't' => 'date'] : null;
        $f = $r['frais'];
        $noms = [];
        foreach ($r['sections'] as $s) {
            $n = mb_substr(preg_replace('/[\\\\\/?*\[\]:\']/', ' ', $s['code']) ?: 'S' . $s['id'], 0, 31);
            while (in_array($n, $noms, true)) {
                $n = mb_substr($n, 0, 28) . '_' . $s['id'];
            }
            $noms[$s['id']] = $n;
        }
        $ref = static fn (int $id, string $c) => "'" . $noms[$id] . "'!" . $c;
        $somme = static fn (string $c) => '(' . implode('+', array_map(static fn ($id) => $ref($id, $c), array_keys($noms))) . ')';
        $tot = ['especes' => 0.0, 'adhesion' => 0.0, 'dossier' => 0.0];
        foreach ($r['sections'] as $s) {
            foreach ($tot as $k => $_) {
                $tot[$k] += $s[$k];
            }
        }

        // ---- Synthèse
        $precPar = [];
        foreach ($prec['sections'] ?? [] as $p) {
            $precPar[$p['id']] = $p['resultat'];
        }
        $l = [[$g('ASCS — résultat par section'), $g('Saison ' . $saison)],
            ['Opérations de banque du', $dt($periode['du']), 'au', $dt($periode['au'])],
            ['Frais ASCS retenus sur chaque inscription', 'adhésion ' . self::m($f['adhesion']) . ', frais de dossier ' . self::m($f['rillieux']) . ' (Rillieux) ou ' . self::m($f['exterieur']) . ' (extérieurs)'],
            ['Édité le', $dt(date('Y-m-d'))], [],
            [$g('Section'), $g('Feuille'), $g('Inscriptions'), $g('Dépenses'), $g('Recettes'), $g('Résultat'), $g('dont espèces'), $g('dont adhésions'), $g('dont frais de dossier'), $g($prec ? 'Résultat ' . $saisonPrec : '')]];
        $debut = count($l) + 1;
        foreach ($r['sections'] as $s) {
            $id = $s['id'];
            $l[] = [$s['titre'] . ($s['fonctionnement'] ? ' (reçoit adhésions et frais, rend les espèces)' : ''), $noms[$id], count($s['frais']),
                ['v' => -$s['depenses'], 't' => 'euro', 'f' => $ref($id, 'D2')], ['v' => $s['recettes'], 't' => 'euro', 'f' => $ref($id, 'E2')],
                ['v' => $s['resultat'], 't' => 'euro', 'f' => $ref($id, 'F2')], ['v' => $s['especes'], 't' => 'euro', 'f' => $ref($id, 'E3')],
                ['v' => -$s['adhesion'], 't' => 'euro', 'f' => $ref($id, 'D4')], ['v' => -$s['dossier'], 't' => 'euro', 'f' => $ref($id, 'D5')],
                isset($precPar[$id]) ? $eu($precPar[$id]) : null];
        }
        $fin = count($l);
        $tr = array_sum(array_column($r['sections'], 'resultat'));
        $sum = static fn (string $c, float $v) => ['v' => $v, 't' => 'euro_gras', 'f' => "SUM({$c}{$debut}:{$c}{$fin})"];
        $l[] = [$g('Total des sections'), '', ['v' => array_sum(array_map(static fn ($s) => count($s['frais']), $r['sections'])), 't' => 'gras', 'f' => "SUM(C{$debut}:C{$fin})"],
            $sum('D', -array_sum(array_column($r['sections'], 'depenses'))), $sum('E', array_sum(array_column($r['sections'], 'recettes'))), $sum('F', $tr),
            $sum('G', $tot['especes']), $sum('H', -$tot['adhesion']), $sum('I', -$tot['dossier']), $prec ? $sum('J', array_sum($precPar)) : null];
        $na = $r['non_affecte'];
        $l[] = ['Opérations sans section (catégorie seule)', '', '', $eu(-$na['depenses']), $eu($na['recettes']), ['v' => $na['recettes'] - $na['depenses'], 't' => 'euro', 'f' => 'E' . ($fin + 2) . '+D' . ($fin + 2)]];
        $l[] = [$g('Résultat de la saison'), '', '', '', '', ['v' => $tr + $na['recettes'] - $na['depenses'], 't' => 'euro_gras', 'f' => 'F' . ($fin + 1) . '+F' . ($fin + 2)]];
        $l[] = [];
        $l[] = ['Chaque section : opérations de banque ventilées sur elle + règlements en espèces (encaissés par le fonctionnement) − adhésion et frais de dossier de chaque inscription (reversés au fonctionnement).'];
        $l[] = ['Les virements entre comptes de l\'association sont exclus. Les cours d\'essai et les inscriptions à 0 € n\'ont pas de frais.'];
        $x->feuille('Synthèse', $l, [52, 10, 12, 15, 15, 15, 14, 15, 18, 16], false);

        // ---- Une feuille par section
        foreach ($r['sections'] as $s) {
            $fo = $s['fonctionnement'];
            $l = [
                ['', $g($s['titre']), '', $g('Dépenses'), $g('Recettes'), $g('Résultat')],
                ['', '', '', ['v' => -$s['depenses'], 't' => 'euro_gras', 'f' => 'SUM(D8:D' . self::FIN . ')+SUM(D3:D5)'], ['v' => $s['recettes'], 't' => 'euro_gras', 'f' => 'SUM(E8:E' . self::FIN . ')+SUM(E3:E5)'],
                    ['v' => $s['resultat'], 't' => 'euro_gras', 'f' => 'E2+D2']],
                ['', '', 'Inscriptions en espèces', $fo ? ['v' => -$tot['especes'], 't' => 'euro', 'f' => '-' . $somme('E3')] : null, ['v' => $s['especes'], 't' => 'euro', 'f' => 'SUM(L8:L' . self::FIN . ')']],
                ['', '', 'Adhésions', ['v' => -$s['adhesion'], 't' => 'euro', 'f' => '-SUM(P8:P' . self::FIN . ')'], $fo ? ['v' => $tot['adhesion'], 't' => 'euro', 'f' => '-' . $somme('D4')] : null],
                ['', '', 'Frais de dossier', ['v' => -$s['dossier'], 't' => 'euro', 'f' => '-SUM(Q8:Q' . self::FIN . ')'], $fo ? ['v' => $tot['dossier'], 't' => 'euro', 'f' => '-' . $somme('D5')] : null],
                ['', '', '', '', '', '', $g('Règlements des inscriptions'), '', '', ['v' => $s['inscriptions'], 't' => 'euro_gras', 'f' => 'SUM(J8:J' . self::FIN . ')'], '', '', '', $g('Frais ASCS par inscription')],
                array_map($g, ['Date', 'Libellé', 'Libellé détail', 'Débit', 'Crédit', '', 'Date', 'Nom', 'Prénom', 'Montant', 'Mode', 'Espèces', '', 'Nom', 'Prénom', 'Adhésion', 'Frais de dossier', 'Rillieux']),
            ];
            $n = max(count($s['banque']), count($s['reglements']), count($s['frais']));
            for ($i = 0; $i < $n; $i++) {
                $b = $s['banque'][$i] ?? null;
                $rg = $s['reglements'][$i] ?? null;
                $fr = $s['frais'][$i] ?? null;
                $l[] = [
                    $b ? $dt($b['date']) : null, $b['libelle'] ?? null, $b['detail'] ?? null, $b ? $eu($b['debit']) : null, $b ? $eu($b['credit']) : null, null,
                    $rg ? $dt($rg['date']) : null, $rg['nom'] ?? null, $rg['prenom'] ?? null, $rg ? $eu($rg['montant']) : null, $rg['mode'] ?? null, $rg && $rg['especes'] !== null ? $eu($rg['especes']) : null, null,
                    $fr['nom'] ?? null, $fr['prenom'] ?? null, $fr ? $eu($fr['adhesion']) : null, $fr ? $eu($fr['dossier']) : null, $fr ? ($fr['rillieux'] ? 'oui' : 'non') : null,
                ];
            }
            $x->feuille($noms[$s['id']], $l, [11, 45, 45, 13, 13, 3, 11, 18, 16, 12, 16, 12, 3, 18, 16, 11, 15, 9], false);
        }
        return $x;
    }

    private static function m(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' €';
    }
}
