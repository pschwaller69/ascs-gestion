<?php /** @var array $lignes @var array $r */
$t = ['total' => 0, 'rillieux' => 0, 'exterieurs' => 0, 'mineurs' => 0, 'mineurs_rillieux' => 0, 'majeurs' => 0, 'femmes' => 0, 'hommes' => 0]; ?>
<div class="titre-page"><h1>Reporting mairie <?= e($r['saison']['titre'] ?? '') ?></h1>
    <a class="btn" href="<?= e(url('/reporting/mairie', ['csv' => 1])) ?>"><?= icon('download') ?> Export Excel</a></div>
<p class="contexte">Adhérents par activité (section principale), cours d'essai exclus. Rillieux = code postal 69140 ou ville Rillieux / Crépieux. Mineur = moins de 18 ans au 1er janvier de l'année de début de saison (âge inconnu compté avec les majeurs).
    Une personne inscrite à deux activités compte dans chacune : le total général compte chaque personne une fois.</p>
<?php if (!$lignes): ?><p class="vide">Aucun inscrit pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Activité</th><th class="num">Adhérents</th><th class="num">Rillieux</th><th class="num">Extérieurs</th><th class="num">Mineurs</th><th class="num">dont Rillieux</th><th class="num">Majeurs</th><th class="num">Femmes</th><th class="num">Hommes</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): foreach ($t as $k => $_) { $t[$k] += $l[$k]; } ?>
        <tr><td><?= e($l['activite']) ?> <small class="aide"><?= e($l['famille'] ?? '') ?></small></td><?php foreach (array_keys($t) as $k): ?><td class="num"><?= (int) $l[$k] ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><th>Somme des activités</th><?php foreach ($t as $n): ?><th class="num"><?= $n ?></th><?php endforeach; ?></tr>
        <tr><th>Personnes distinctes</th><th class="num"><?= (int) $r['total'] ?></th><th class="num"><?= (int) $r['rillieux'] ?></th><th class="num"><?= (int) $r['exterieurs'] ?></th><th></th><th></th><th></th><th class="num"><?= (int) $r['femmes'] ?></th><th class="num"><?= (int) $r['hommes'] ?></th></tr>
    </tfoot>
</table></div>
<?php endif; ?>
