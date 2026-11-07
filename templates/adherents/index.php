<div class="titre-page">
    <h1>Adhérents <small><?= (int) $total ?> fiche<?= $total > 1 ? 's' : '' ?></small></h1>
    <a class="btn btn-principal" href="<?= e(url('/adherents/nouveau')) ?>"><?= icon('user-plus') ?> Nouvel adhérent</a>
</div>
<form class="filtres" method="get" action="<?= e(url('/adherents')) ?>" role="search">
    <div class="champ"><label for="q">Nom, prénom, mail ou téléphone</label><input type="search" id="q" name="q" value="<?= e($q) ?>" autofocus></div>
    <div class="champ"><label for="filtre">Afficher</label>
        <select id="filtre" name="filtre">
            <option value="saison"<?= attr($saisonSeule, 'selected') ?>>Inscrits à la saison choisie</option>
            <option value="tous"<?= attr(!$saisonSeule, 'selected') ?>>Toutes les fiches</option>
        </select></div>
    <button class="btn" type="submit"><?= icon('search') ?> Chercher</button>
</form>
<?php if (!$lignes): ?>
    <p class="vide">Aucun adhérent ne correspond.<?= $saisonSeule ? ' Essayez « Toutes les fiches ».' : '' ?></p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Nom</th><th>Prénom</th><th>Naissance</th><th>Mail</th><th>Téléphone</th><th>Ville</th><th class="num">Inscr. saison</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><a href="<?= e(url('/adherents/' . $l['id'])) ?>"><?= e($l['nom'] ?: ($l['prenom'] ? '' : '(sans nom)')) ?></a></td>
            <td><?= e($l['prenom']) ?></td>
            <td><?= e(dfr($l['date_naissance'])) ?></td>
            <td><?= e($l['mail']) ?></td>
            <td><?= e($l['telephone1']) ?></td>
            <td><?= e($l['ville']) ?></td>
            <td class="num"><?= (int) $l['nb_saison'] ?: '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?= pagination('/adherents', ['q' => $q, 'filtre' => $saisonSeule ? 'saison' : 'tous'], $page, $total, $parPage) ?>
<?php endif; ?>
