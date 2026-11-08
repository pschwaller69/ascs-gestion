<?php $lien = static fn (array $l) => url('/reglements', array_filter(['mode' => $l['mode_id'], 'q' => match ($l['detail']) { 'Coupon culture' => 'CULTURE', 'Coupon sport' => 'SPORT', 'Pass Senior' => 'SENIOR', default => null }, 'du' => $du, 'au' => $au])); ?>
<div class="titre-page"><h1>Règlements par type</h1></div>
<form class="filtres" method="get" action="<?= e(url('/reglements/par-type')) ?>">
    <div class="champ"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= e($du) ?>"></div>
    <div class="champ"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= e($au) ?>"></div>
    <button class="btn" type="submit"><?= icon('search') ?> Filtrer</button>
    <?php if ($du || $au): ?><a class="btn" href="<?= e(url('/reglements/par-type')) ?>">Toute la saison</a><?php endif; ?>
</form>
<p class="contexte">Tous les règlements enregistrés pour la saison, y compris ceux à venir (chèques à poser, échéances HelloAsso prévues, aides à recevoir). « À venir » = date pas encore passée.</p>
<?php if (!$lignes): ?><p class="vide">Aucun règlement pour cette période.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Moyen</th><th class="num">Nombre</th><th class="num">Encaissable</th><th class="num">À venir</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><a href="<?= e($lien($l)) ?>"><?= e($l['mode']) ?></a><?= $l['detail'] !== '' ? ' <small class="aide">' . e($l['detail']) . '</small>' : '' ?></td>
            <td class="num"><?= (int) $l['nombre'] ?></td>
            <td class="num"><?= e(money($l['encaisse'])) ?></td>
            <td class="num"><?= $l['a_venir'] != 0 ? e(money($l['a_venir'])) : '—' ?></td>
            <td class="num"><strong><?= e(money($l['montant'])) ?></strong></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total</th><th class="num"><?= (int) $total['nombre'] ?></th><th class="num"><?= e(money($total['encaisse'])) ?></th><th class="num"><?= e(money($total['a_venir'])) ?></th><th class="num"><?= e(money($total['montant'])) ?></th></tr></tfoot>
</table></div>
<?php endif; ?>
<?php if (!$du && !$au): ?>
<p class="contexte">Inscriptions de la saison : <?= e(money($inscrit)) ?>.
    <?php if (abs($reste) < 0.005): ?>Tout est réglé.<?php elseif ($reste > 0): ?>Reste à régler : <a href="<?= e(url('/inscriptions', ['statut' => 'impayees'])) ?>"><strong><?= e(money($reste)) ?></strong></a>.<?php else: ?>Trop-perçu : <strong><?= e(money(-$reste)) ?></strong>.<?php endif; ?></p>
<?php endif; ?>
