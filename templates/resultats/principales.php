<?php $tot = ['inscriptions' => 0, 'montant_inscriptions' => 0.0, 'recettes' => 0.0, 'depenses' => 0.0, 'resultat' => 0.0]; ?>
<div class="titre-page"><h1>Résultat par section principale</h1></div>
<p class="contexte">Saison choisie en haut de page. Recettes et dépenses : lignes de compte ventilées sur la section principale (virements internes exclus).</p>
<?php if (!$lignes): ?><p class="vide">Aucune donnée pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Section principale</th><th>Famille</th><th class="num">Inscriptions</th><th class="num">Montant inscriptions</th><th class="num">Recettes</th><th class="num">Dépenses</th><th class="num">Résultat</th><th class="num">Seuil</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): foreach ($tot as $k => $_) { $tot[$k] += $l[$k]; } ?>
        <tr>
            <td><a href="<?= e(url('/parametrage/sections-principales/' . $l['id'])) ?>"><?= e($l['titre']) ?></a></td>
            <td><?= e($l['famille']) ?></td>
            <td class="num"><?= (int) $l['inscriptions'] ?: '' ?></td>
            <td class="num"><?= $l['montant_inscriptions'] ? e(money($l['montant_inscriptions'])) : '' ?></td>
            <td class="num"><?= e(money($l['recettes'])) ?></td>
            <td class="num"><?= e(money($l['depenses'])) ?></td>
            <td class="num"><?= $l['resultat'] < 0 ? '<span class="texte-erreur">−' . e(money(-$l['resultat'])) . '</span>' : e(money($l['resultat'])) ?></td>
            <td class="num"><?= $l['seuil'] ? e(money($l['seuil'])) : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="2">Total</th><th class="num"><?= (int) $tot['inscriptions'] ?></th><th class="num"><?= e(money($tot['montant_inscriptions'])) ?></th><th class="num"><?= e(money($tot['recettes'])) ?></th><th class="num"><?= e(money($tot['depenses'])) ?></th><th class="num"><?= $tot['resultat'] < 0 ? '−' . e(money(-$tot['resultat'])) : e(money($tot['resultat'])) ?></th><th></th></tr></tfoot>
</table></div>
<?php endif; ?>
