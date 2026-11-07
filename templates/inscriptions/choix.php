<h1>Nouvelle inscription</h1>
<p>Cherchez l'adhérent à inscrire. S'il n'existe pas encore, créez sa fiche.</p>
<form class="filtres" method="get" action="<?= e(url('/inscriptions/nouveau')) ?>" role="search">
    <div class="champ"><label for="q">Nom, prénom, mail ou téléphone</label><input type="search" id="q" name="q" value="<?= e($q) ?>" autofocus></div>
    <button class="btn" type="submit"><?= icon('search') ?> Chercher</button>
    <a class="btn" href="<?= e(url('/adherents/nouveau')) ?>"><?= icon('user-plus') ?> Créer une fiche</a>
</form>
<?php if ($q !== '' && !$lignes): ?><p class="vide">Aucun adhérent trouvé pour « <?= e($q) ?> ».</p><?php elseif ($lignes): ?>
<div class="table-cadre"><table>
    <thead><tr><th>Adhérent</th><th>Naissance</th><th>Mail</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><?= e(trim(($l['nom'] ?? '') . ' ' . ($l['prenom'] ?? ''))) ?></td>
            <td><?= e(dfr($l['date_naissance'])) ?></td>
            <td><?= e($l['mail']) ?></td>
            <td><a class="btn btn-petit" href="<?= e(url('/inscriptions/nouveau', ['adherent' => $l['id']])) ?>">Inscrire</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
