<?php $tot = ['inscrits' => 0, 'montant' => 0.0, 'regle' => 0.0]; ?>
<div class="titre-page"><h1>Résultat par section</h1></div>
<p class="contexte">Inscriptions de la saison choisie en haut de page, comparées au seuil de rentabilité annuel de la section (Paramétrage &gt; Sections). Cours d'essai exclus.</p>
<?php if (!$lignes): ?><p class="vide">Aucune inscription pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Section</th><th>Section principale</th><th class="num">Inscrits</th><th class="num">Inscriptions</th><th class="num">Réglé</th><th class="num">Seuil</th><th class="num">Écart au seuil</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): foreach ($tot as $k => $_) { $tot[$k] += $l[$k]; } $ecart = $l['seuil'] !== null && (float) $l['seuil'] > 0 ? (float) $l['montant'] - (float) $l['seuil'] : null; ?>
        <tr>
            <td><a href="<?= e(url('/parametrage/sections/' . $l['id'])) ?>"><?= e($l['titre']) ?></a></td>
            <td><?= e($l['principale']) ?></td>
            <td class="num"><?= (int) $l['inscrits'] ?></td>
            <td class="num"><?= e(money($l['montant'])) ?></td>
            <td class="num"><?= e(money($l['regle'])) ?></td>
            <td class="num"><?= $ecart === null ? '—' : e(money($l['seuil'])) ?></td>
            <td class="num"><?= $ecart === null ? '' : ($ecart < 0 ? '<span class="texte-erreur">−' . e(money(-$ecart)) . '</span>' : '+' . e(money($ecart))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="2">Total</th><th class="num"><?= (int) $tot['inscrits'] ?></th><th class="num"><?= e(money($tot['montant'])) ?></th><th class="num"><?= e(money($tot['regle'])) ?></th><th></th><th></th></tr></tfoot>
</table></div>
<?php endif; ?>
