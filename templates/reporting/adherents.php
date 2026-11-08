<?php /** @var array $r @var ?array $avant */
use Ascs\Domain\Statistiques;
$pc = static fn (int $n, int $t) => $t ? round($n / $t * 100) . ' %' : '';
$evol = static function (string $k) use ($r, $avant): string {
    if (!$avant || !($avant['total'] ?? 0)) { return ''; }
    $d = $r[$k] - $avant[$k];
    return '<small class="aide">' . ($d >= 0 ? '+' : '−') . abs($d) . '</small>';
};
?>
<div class="titre-page"><h1>Adhérents <?= e($r['saison']['titre'] ?? '') ?></h1></div>
<p class="contexte">Personnes inscrites à au moins une activité (cours d'essai exclus)<?= $avant ? ', écart avec ' . e($avant['saison']['titre']) : '' ?>. Âge au 1er janvier de l'année de début de saison.</p>
<?php if (!($r['total'] ?? 0)): ?><p class="vide">Aucun inscrit pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th></th><th class="num">Nombre</th><th class="num">Part</th><?php if ($avant): ?><th class="num">Écart</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach (['total' => 'Adhérents', 'femmes' => 'Femmes', 'hommes' => 'Hommes', 'rillieux' => 'Habitants de Rillieux', 'exterieurs' => 'Extérieurs',
        'reinscrits' => 'Réinscrits (inscrits la saison précédente)', 'retour' => 'De retour (inscrits avant, pas la saison précédente)', 'nouveaux' => 'Nouveaux'] as $k => $lib): ?>
        <tr<?= $k === 'total' ? ' class="ligne-total"' : '' ?>><th scope="row"><?= e($lib) ?></th><td class="num"><?= (int) $r[$k] ?></td><td class="num"><?= $k === 'total' ? '' : $pc((int) $r[$k], (int) $r['total']) ?></td><?php if ($avant): ?><td class="num"><?= $evol($k) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<h2>Par tranche d'âge</h2>
<div class="table-cadre"><table>
    <thead><tr><th>Tranche</th><th class="num">Femmes</th><th class="num">Hommes</th><th class="num">Total</th><th class="num">dont Rillieux</th></tr></thead>
    <tbody>
    <?php foreach (Statistiques::TRANCHES as $t): $x = $r['tranches'][$t]; if ($t === 'Âge inconnu' && !($x['f'] + $x['h'])) { continue; } ?>
        <tr><th scope="row"><?= e($t) ?></th><td class="num"><?= (int) $x['f'] ?></td><td class="num"><?= (int) $x['h'] ?></td><td class="num"><strong><?= (int) ($x['f'] + $x['h']) ?></strong></td><td class="num"><?= (int) $x['rillieux'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
