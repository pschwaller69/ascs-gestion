<?php $etats = ['prevue' => ['prévue', 'pastille-reste'], 'a_annuler' => ['annulation programmée', 'pastille-reste'], 'annulee' => ['annulée', 'pastille-ok'], 'prelevee' => ['prélevée', 'pastille-erreur'], 'remboursee' => ['remboursée', 'pastille-ok']]; ?>
<div class="titre-page">
    <h1>Garanties par carte</h1>
    <a class="btn" href="<?= e(url('/garanties', $toutes ? [] : ['toutes' => 1])) ?>"><?= $toutes ? 'Seulement à suivre' : 'Voir aussi les annulées' ?></a>
</div>
<p class="contexte">Quand une personne paie par carte sur HelloAsso en demandant une aide (coupon de la mairie…), le montant de l'aide est programmé en garantie à une date fixée (Inscriptions en ligne &gt; Réglages), au lieu d'un chèque de caution. Dès que la mairie confirme l'aide, cliquez sur « Aide confirmée » : le prélèvement est annulé. Sans confirmation, il est fait à la date prévue et devient un règlement de l'inscription.</p>
<?php if (!$liste): ?>
    <p class="vide">Aucune garantie à suivre.</p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Personne</th><th>Aide</th><th class="num">Montant</th><th>Prélèvement le</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $g): [$lib, $cls] = $etats[$g['statut']] ?? [$g['statut'], '']; ?>
        <tr>
            <td><a href="<?= e(url('/demandes/' . $g['demande_id'])) ?>"><?= e(mb_strtoupper((string) $g['nom']) . ' ' . $g['prenom']) ?></a><br><small class="aide">demande <?= e($g['reference']) ?></small></td>
            <td><?= e($g['libelle']) ?></td>
            <td class="num"><?= e(money($g['montant'])) ?></td>
            <td><?= e(dfr((string) $g['date_prevue'])) ?></td>
            <td><span class="pastille <?= e($cls) ?>"><?= e($lib) ?></span><?= $g['note'] ? '<br><small class="aide">' . e($g['note']) . '</small>' : '' ?></td>
            <td>
                <?php if (in_array($g['statut'], ['prevue', 'a_annuler'], true)): ?>
                    <form method="post" action="<?= e(url('/garanties/' . $g['id'] . '/confirmer')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-petit"><?= $g['statut'] === 'prevue' ? 'Aide confirmée : annuler le prélèvement' : 'Réessayer l\'annulation' ?></button></form>
                <?php elseif ($g['statut'] === 'prelevee'): ?>
                    <form method="post" action="<?= e(url('/garanties/' . $g['id'] . '/rembourser')) ?>"><?= csrf_field() ?>
                        <label class="coche"><input type="checkbox" name="confirme" value="1" required> <span>Aide confirmée après coup : rembourser <?= e(money($g['montant'])) ?></span></label>
                        <button type="submit" class="btn btn-petit btn-danger">Rembourser</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
