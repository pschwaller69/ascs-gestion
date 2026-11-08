<?php /** @var array $lignes @var bool $tout @var bool $dns */ ?>
<div class="titre-page">
    <h1>Validation des mails</h1>
    <a class="btn" href="<?= e(url('/mails/verification', $tout ? [] : ['toutes' => 1])) ?>"><?= $tout ? 'Inscrits de la saison seulement' : 'Toutes les fiches' ?></a>
</div>
<p class="contexte">Adresses mal formées, fautes de frappe connues (gmial.com, hotmail.fe…)<?= $dns ? ' et domaines qui ne reçoivent pas de mail' : '' ?>, et adresses marquées invalides. Une adresse invalide est écartée des mailings.
    <?= $tout ? 'Toutes les fiches.' : 'Inscrits de la saison choisie en haut de page.' ?></p>
<?php if (!$dns): ?><p class="aide">Vérification des domaines indisponible sur ce serveur : seules la forme et les fautes de frappe sont contrôlées.</p><?php endif; ?>
<?php if (!$lignes): ?><p class="vide">Aucun problème trouvé.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Nom</th><th>Mail</th><th>Problème</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><a href="<?= e(url('/adherents/' . $l['id'])) ?>"><?= e(trim($l['nom'] . ' ' . $l['prenom'])) ?></a></td>
            <td><?= e($l['mail']) ?><?= $l['adresse_mail_invalide'] ? ' <span class="pastille pastille-reste">invalide</span>' : '' ?></td>
            <td><?= e($l['probleme']) ?><?= $l['suggestion'] ? '<br><small>Proposition : <strong>' . e($l['suggestion']) . '</strong></small>' : '' ?></td>
            <td class="actions-ligne">
                <?php if ($l['suggestion']): ?>
                    <form method="post" action="<?= e(url('/mails/verification/' . $l['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="corriger"><input type="hidden" name="mail" value="<?= e($l['suggestion']) ?>"><?= $tout ? '<input type="hidden" name="toutes" value="1">' : '' ?><button class="btn btn-petit btn-principal" type="submit">Corriger</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/mails/verification/' . $l['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $l['adresse_mail_invalide'] ? 'valide' : 'invalide' ?>"><?= $tout ? '<input type="hidden" name="toutes" value="1">' : '' ?><button class="btn btn-petit" type="submit"><?= $l['adresse_mail_invalide'] ? 'Remettre valide' : 'Marquer invalide' ?></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
