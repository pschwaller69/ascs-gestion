<div class="titre-page">
    <h1><?= $statut === 'impayees' ? 'Inscriptions non payées' : 'Inscriptions' ?> <small><?= (int) $total ?></small></h1>
    <a class="btn btn-principal" href="<?= e(url('/inscriptions/nouveau')) ?>"><?= icon('file-plus') ?> Nouvelle inscription</a>
</div>
<form class="filtres" method="get" action="<?= e(url('/inscriptions')) ?>" role="search">
    <div class="champ"><label for="q">Nom ou prénom</label><input type="search" id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="champ"><label for="section">Section</label>
        <select id="section" name="section"><option value="">Toutes</option>
        <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr($section === (int) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="champ"><label for="statut">État</label>
        <select id="statut" name="statut">
            <option value="toutes"<?= attr($statut === 'toutes', 'selected') ?>>Toutes</option>
            <option value="impayees"<?= attr($statut === 'impayees', 'selected') ?>>Non soldées</option>
            <option value="soldees"<?= attr($statut === 'soldees', 'selected') ?>>Soldées</option>
        </select></div>
    <button class="btn" type="submit"><?= icon('search') ?> Filtrer</button>
</form>
<?php if (!$lignes): ?><p class="vide">Aucune inscription ne correspond à ces filtres pour la saison choisie.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Adhérent</th><th>Section</th><th>Date</th><th class="num">Montant</th><th class="num">Réglé</th><th>État</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><a href="<?= e(url('/inscriptions/' . $l['id'])) ?>"><?= e(trim(($l['nom'] ?? '') . ' ' . ($l['prenom'] ?? ''))) ?></a></td>
            <td><?= e($l['section']) ?></td>
            <td><?= e(dfr($l['date_inscription'])) ?></td>
            <td class="num"><?= e(money($l['montant'])) ?></td>
            <td class="num"><?= e(money($l['regle'])) ?></td>
            <td><?= pastille_solde((float) $l['montant'], (float) $l['regle']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($statut === 'impayees'): ?><tfoot><tr><th colspan="5">Total restant dû (toutes pages)</th><th class="num"><?= e(money($reste)) ?></th></tr></tfoot><?php endif; ?>
</table></div>
<?= pagination('/inscriptions', ['q' => $q, 'section' => $section, 'statut' => $statut], $page, $total, $parPage) ?>
<?php endif; ?>
