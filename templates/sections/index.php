<div class="titre-page">
    <h1>Inscriptions par section</h1>
    <form method="post" action="<?= e(url('/sections/envoyer-liens')) ?>" data-confirmer="Envoyer à chaque animateur le lien de sa liste de présence ?">
        <?= csrf_field() ?>
        <button type="submit" class="btn"><?= icon('mail') ?> Envoyer leur lien aux animateurs</button>
    </form>
</div>
<p class="contexte">Saison choisie en haut de page. Chaque section a une liste de présence que l'animateur ouvre sur son téléphone, sans mot de passe, et une feuille d'émargement à imprimer.</p>
<?php if (!$sections): ?><p class="vide">Aucune inscription pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Section</th><th>Section principale</th><th class="num">Inscrits</th><th class="num">Séances notées</th><th>Animateur</th><th><span class="visuellement-cache">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($sections as $s): ?>
        <tr>
            <td><a href="<?= e(url('/sections/' . $s['id'])) ?>"><?= e($s['titre']) ?></a><?= $s['essai'] ? ' <small class="aide">cours d\'essai</small>' : '' ?></td>
            <td><?= e($s['principale']) ?></td>
            <td class="num"><?= (int) $s['inscrits'] ?></td>
            <td class="num"><?= (int) $s['seances'] ?: '' ?></td>
            <td><small><?= e($s['mail_animateur1'] ?? '') ?></small></td>
            <td><a class="btn btn-petit" href="<?= e(url('/sections/' . $s['id'] . '/emargement')) ?>" target="_blank" rel="noopener">Émargement</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
