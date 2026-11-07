<h1>Import WinDev : vérification</h1>
<div class="table-cadre"><table>
    <thead><tr><th>Table</th><th>Fichier reçu</th></tr></thead>
    <tbody>
    <?php foreach ($recus as $t => $taille): ?>
        <tr><td><?= e($t) ?></td><td><?= $taille === null ? '<span class="pastille pastille-excedent">manquant</span>' : '<span class="pastille pastille-ok">reçu</span> ' . e(number_format($taille / 1024, 0, ',', ' ')) . ' Ko' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($ignores): ?><p class="flash flash-avert">Fichiers ignorés : <?= e(implode(' ; ', $ignores)) ?></p><?php endif; ?>

<?php if (!$complet): ?>
    <p>Il manque des fichiers. Envoyez ceux qui manquent : les fichiers déjà reçus sont conservés.</p>
    <form class="fiche" method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="champ"><label for="fichiers">Fichiers XML manquants</label><input type="file" id="fichiers" name="fichiers[]" accept=".xml" multiple required></div>
        <button type="submit" class="btn btn-principal"><?= icon('upload') ?> Envoyer</button>
    </form>
<?php elseif ($erreur): ?>
    <p class="flash flash-erreur" role="alert">La simulation a échoué, rien n'a été modifié : <?= e($erreur) ?></p>
<?php else: ?>
    <p class="flash flash-info">Simulation terminée : rien n'a encore été modifié. Relisez le rapport, puis lancez l'import.</p>
    <pre class="rapport"><?= e($rapport) ?></pre>
    <form method="post" action="<?= e(url('/import/lancer')) ?>" class="actions" data-confirmer="Remplacer les données de l'application par celles de WinDev ?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-principal">Lancer l'import</button>
    </form>
<?php endif; ?>
<form method="post" action="<?= e(url('/import/annuler')) ?>" class="actions">
    <?= csrf_field() ?><button type="submit" class="btn">Abandonner et effacer les fichiers envoyés</button>
</form>
