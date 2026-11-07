<div class="titre-page">
    <h1>Demandes en ligne</h1>
    <a class="btn" href="<?= e(url('/en-ligne')) ?>">Réglages et QR codes</a>
</div>
<nav class="sous-onglets" aria-label="Demandes">
    <a href="<?= e(url('/demandes')) ?>"<?= $vue === 'a_traiter' ? ' aria-current="page"' : '' ?>>À traiter (<?= (int) $compteurs['a_traiter'] ?>)</a>
    <a href="<?= e(url('/demandes', ['vue' => 'valide'])) ?>"<?= $vue === 'valide' ? ' aria-current="page"' : '' ?>>Validées (<?= (int) $compteurs['valide'] ?>)</a>
    <a href="<?= e(url('/demandes', ['vue' => 'refuse'])) ?>"<?= $vue === 'refuse' ? ' aria-current="page"' : '' ?>>Refusées (<?= (int) $compteurs['refuse'] ?>)</a>
</nav>
<?php if (!$liste): ?>
    <p class="vide"><?= $vue === 'a_traiter' ? 'Aucune demande à traiter.' : 'Rien ici pour l\'instant.' ?></p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Reçue le</th><th>Type</th><th>Personne</th><th>Activités</th><th>Référence</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $d): ?>
        <tr>
            <td><?= e(dfr($d['cree_le'])) ?> <small class="aide"><?= e(substr((string) $d['cree_le'], 11, 5)) ?></small></td>
            <td><?= $d['type'] === 'essai' ? '<span class="pastille pastille-niv-C">essai</span>' : 'inscription' ?></td>
            <td><?= e(trim($d['nom'] . ' ' . $d['prenom'])) ?><br><small class="aide"><?= e(dfr($d['date_naissance'])) ?><?= $d['nom_parent'] ? ' — parent : ' . e(trim($d['nom_parent'] . ' ' . $d['prenom_parent'])) : '' ?></small></td>
            <td><?= e($d['titres']) ?><?= $d['date_essai'] ? '<br><small class="aide">le ' . e(dfr($d['date_essai'])) . '</small>' : '' ?></td>
            <td><code><?= e($d['reference']) ?></code></td>
            <td><a class="btn btn-petit<?= $vue === 'a_traiter' ? ' btn-principal' : '' ?>" href="<?= e(url('/demandes/' . $d['id'])) ?>"><?= $vue === 'a_traiter' ? 'Traiter' : 'Voir' ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
