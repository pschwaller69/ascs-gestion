<?php /** @var array $saisons @var array $lignes */
$tot = array_fill_keys(array_map(static fn ($s) => (int) $s['id'], $saisons), 0);
$der = $saisons ? (int) end($saisons)['id'] : 0; $pre = count($saisons) > 1 ? (int) $saisons[count($saisons) - 2]['id'] : 0;
$famille = null;
?>
<div class="titre-page"><h1>Adhérents par section</h1></div>
<p class="contexte">Inscriptions par section sur trois saisons (jusqu'à la saison choisie en haut de page). Cours d'essai exclus.</p>
<?php if (!$lignes): ?><p class="vide">Aucune inscription.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Section</th><?php foreach ($saisons as $s): ?><th class="num"><?= e($s['titre']) ?></th><?php endforeach; ?><?php if ($pre): ?><th class="num">Écart</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <?php if ($l['famille'] !== $famille): $famille = $l['famille']; ?><tr class="ligne-groupe"><th colspan="<?= count($saisons) + 2 ?>"><?= e($famille ?? 'Sans famille') ?></th></tr><?php endif; ?>
        <tr><td><?= e($l['titre']) ?></td>
            <?php foreach ($l['n'] as $sid => $n): $tot[$sid] += $n; ?><td class="num"><?= $n ?: '' ?></td><?php endforeach; ?>
            <?php if ($pre): $d = $l['n'][$der] - $l['n'][$pre]; ?><td class="num"><?= $d === 0 ? '' : ($d > 0 ? '+' . $d : '<span class="texte-erreur">−' . -$d . '</span>') ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total</th><?php foreach ($tot as $n): ?><th class="num"><?= $n ?></th><?php endforeach; ?><?php if ($pre): ?><th class="num"><?= ($tot[$der] - $tot[$pre]) >= 0 ? '+' : '−' ?><?= abs($tot[$der] - $tot[$pre]) ?></th><?php endif; ?></tr></tfoot>
</table></div>
<?php endif; ?>
