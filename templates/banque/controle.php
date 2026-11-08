<?php /** @var array $a_ventiler @var array $soldes @var array $remises */ ?>
<div class="titre-page"><h1>Contrôle des comptes</h1></div>
<p class="contexte">Saison choisie en haut de page. Trois vérifications : opérations non ventilées (ou dont la ventilation ne fait pas le montant), soldes qui ne s'enchaînent pas (une opération manque probablement dans un import), remises de chèques déposées sans ligne bancaire trouvée.</p>

<h2>Opérations à ventiler <small class="aide">(<?= count($a_ventiler) ?>)</small></h2>
<?php if (!$a_ventiler): ?><p class="vide">Tout est ventilé.</p><?php else: ?>
<div class="table-cadre"><table><thead><tr><th>Compte</th><th>Date</th><th>Libellé</th><th class="num">Montant</th><th class="num">Ventilé</th></tr></thead><tbody>
<?php foreach ($a_ventiler as $l): ?><tr><td><?= e($l['numero_compte']) ?></td><td><?= e(dfr($l['date_operation'])) ?></td><td><a href="<?= e(url('/banque/ligne/' . $l['id'])) ?>"><?= e(mb_strimwidth((string) $l['libelle'], 0, 70, '…')) ?></a></td><td class="num"><?= e(money($l['valeur'])) ?></td><td class="num"><?= e(money($l['ventile'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<h2>Soldes qui ne s'enchaînent pas <small class="aide">(<?= count($soldes) ?>)</small></h2>
<?php if (!$soldes): ?><p class="vide">Les soldes se suivent : aucune opération ne semble manquer.</p><?php else: ?>
<div class="table-cadre"><table><thead><tr><th>Compte</th><th>Date</th><th>Libellé</th><th class="num">Solde attendu</th><th class="num">Solde du relevé</th><th class="num">Écart</th></tr></thead><tbody>
<?php foreach ($soldes as $l): ?><tr><td><?= e($l['numero_compte']) ?></td><td><?= e(dfr($l['date_operation'])) ?> <small class="aide">(précédente <?= e(dfr($l['precedent'])) ?>)</small></td><td><a href="<?= e(url('/banque/ligne/' . $l['id'])) ?>"><?= e(mb_strimwidth((string) $l['libelle'], 0, 60, '…')) ?></a></td><td class="num"><?= e(money($l['attendu'])) ?></td><td class="num"><?= e(money($l['solde'])) ?></td><td class="num"><?= e(money((float) $l['solde'] - (float) $l['attendu'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="aide">Un écart peut aussi venir de plusieurs opérations le même jour dans un ordre différent de celui de la banque : il se compense alors à la ligne suivante.</p>
<?php endif; ?>

<h2>Remises de chèques sans ligne bancaire <small class="aide">(<?= count($remises) ?>)</small></h2>
<?php if (!$remises): ?><p class="vide">Toutes les remises déposées sont retrouvées sur le compte.</p><?php else: ?>
<ul class="liste-simple"><?php foreach ($remises as $r): ?><li><a href="<?= e(url('/remises/' . $r['id'])) ?>">Remise <?= e($r['numero'] ?: '#' . $r['id']) ?></a> déposée le <?= e(dfr($r['date_depot'])) ?> : <?= e(money($r['total'])) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
