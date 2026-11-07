<h1>Comptes du bureau</h1>
<div class="table-cadre"><table>
    <thead><tr><th>Nom</th><th>Mail</th><th>Dernière connexion</th><th>État</th><th>Nouveau mot de passe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $u): ?>
        <tr>
            <td><?= e($u['nom']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= $u['derniere_connexion'] ? e(dfr($u['derniere_connexion'])) : 'jamais' ?></td>
            <td><?= $u['actif'] ? '<span class="pastille pastille-ok">actif</span>' : '<span class="pastille pastille-niv-D">désactivé</span>' ?></td>
            <td>
                <form method="post" action="<?= e(url('/utilisateurs/' . $u['id'] . '/mot-de-passe')) ?>" class="en-ligne">
                    <?= csrf_field() ?>
                    <label class="visuellement-cache" for="mdp-<?= (int) $u['id'] ?>">Nouveau mot de passe pour <?= e($u['email']) ?></label>
                    <input type="password" id="mdp-<?= (int) $u['id'] ?>" name="mot_de_passe" minlength="12" required autocomplete="new-password">
                    <button type="submit" class="btn btn-petit">Changer</button>
                </form>
            </td>
            <td>
                <?php if ((int) $u['id'] !== (int) $app->user['id']): ?>
                <form method="post" action="<?= e(url('/utilisateurs/' . $u['id'] . '/activer')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="actif" value="<?= $u['actif'] ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-petit<?= $u['actif'] ? ' btn-danger' : '' ?>"><?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
                </form>
                <?php else: ?><span class="aide">vous</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<h2>Nouveau compte</h2>
<form class="fiche" method="post" action="<?= e(url('/utilisateurs')) ?>">
    <?= csrf_field() ?>
    <div class="grille">
        <?= champ('Adresse mail', 'email', $saisie['email'], ['type' => 'email', 'required' => true, 'error' => $err['email'] ?? null, 'autocomplete' => 'off']) ?>
        <?= champ('Nom affiché', 'nom', $saisie['nom'], ['maxlength' => 100]) ?>
        <?= champ('Mot de passe', 'mot_de_passe', '', ['type' => 'password', 'required' => true, 'error' => $err['mot_de_passe'] ?? null, 'autocomplete' => 'new-password', 'hint' => '12 caractères minimum.']) ?>
        <?= champ('Confirmation', 'confirmation', '', ['type' => 'password', 'required' => true, 'error' => $err['confirmation'] ?? null, 'autocomplete' => 'new-password']) ?>
    </div>
    <button type="submit" class="btn btn-principal"><?= icon('user-plus') ?> Créer le compte</button>
</form>
