<h1>Comptes du bureau</h1>
<div class="table-cadre"><table>
    <thead><tr><th>Nom</th><th>Mail</th><th>Dernière connexion</th><th>État</th><th>Double authentification</th><th>Nouveau mot de passe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $u): ?>
        <tr>
            <td><?= e($u['nom']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= $u['derniere_connexion'] ? e(dfr($u['derniere_connexion'])) : 'jamais' ?></td>
            <td><?= $u['actif'] ? '<span class="pastille pastille-ok">actif</span>' : '<span class="pastille pastille-niv-D">désactivé</span>' ?>
                <?php if (in_array((int) $u['id'], $tests, true)): ?> <span class="pastille pastille-reste">compte de test</span><?php endif; ?></td>
            <td><?php $d = $da[(int) $u['id']] ?? ['exigee' => false, 'active' => false, 'secours' => 0]; $f = e(url('/utilisateurs/' . $u['id'] . '/double-auth')); ?>
                <?php if (!$d['exigee']): ?>
                    <span class="pastille">non</span>
                    <form method="post" action="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="exiger"><button type="submit" class="btn btn-petit">Exiger</button></form>
                <?php else: ?>
                    <?= $d['active'] ? '<span class="pastille pastille-ok">active</span> <small class="aide">' . (int) $d['secours'] . ' code(s) de secours</small>' : '<span class="pastille pastille-reste">à enregistrer</span> <small class="aide">à la prochaine connexion</small>' ?>
                    <?php if ($n = (int) ($appareils[(int) $u['id']] ?? 0)): ?><br><small class="aide"><?= $n ?> ordinateur(s) de confiance</small>
                    <form method="post" action="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="oublier"><button type="submit" class="btn btn-petit">Oublier les ordinateurs</button></form><?php endif; ?>
                    <form method="post" action="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reinitialiser"><button type="submit" class="btn btn-petit" title="Téléphone perdu ou changé, plus de codes de secours">Réinitialiser</button></form>
                    <form method="post" action="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="retirer"><button type="submit" class="btn btn-petit btn-danger">Ne plus exiger</button></form>
                <?php endif; ?>
            </td>
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
                <form method="post" action="<?= e(url('/utilisateurs/' . $u['id'] . '/test')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="test" value="<?= in_array((int) $u['id'], $tests, true) ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-petit"><?= in_array((int) $u['id'], $tests, true) ? 'Compte normal' : 'Compte de test' ?></button>
                </form>
                <?php else: ?><span class="aide">vous</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<p class="aide">Double authentification : à chaque connexion, en plus du mot de passe, un code à 6 chiffres donné par une application du téléphone (Google Authenticator, Microsoft Authenticator…). « Exiger » : la personne enregistre son application à sa prochaine connexion et reçoit 10 codes de secours ; sa session en cours est fermée. « Réinitialiser » si elle a perdu ou changé de téléphone. « Se souvenir de cet ordinateur » évite le code pendant 30 jours sur un appareil (le mot de passe reste demandé) ; « Oublier les ordinateurs » l'annule, par exemple après un vol.</p>
<h2>Comptes de test</h2>
<form class="fiche" method="post" action="<?= e(url('/utilisateurs/mail-test')) ?>">
    <?= csrf_field() ?>
    <p class="aide">Avec un compte de test, chaque mail (facture, lien d'animateur, test de mailing…) part vers cette adresse, jamais au vrai destinataire, avec « [TEST pour …] » dans l'objet. Vide : aucun mail ne part. Les SMS ne partent jamais ; les mailings ne peuvent pas être validés.</p>
    <?= champ('Adresse qui reçoit les mails des comptes de test', 'adresse', $mailTest, ['type' => 'email']) ?>
    <button type="submit" class="btn">Enregistrer</button>
</form>
<h2>Nouveau compte</h2>
<form class="fiche" method="post" action="<?= e(url('/utilisateurs')) ?>">
    <?= csrf_field() ?>
    <div class="grille">
        <?= champ('Adresse mail', 'email', $saisie['email'], ['type' => 'email', 'required' => true, 'error' => $err['email'] ?? null, 'autocomplete' => 'off']) ?>
        <?= champ('Nom affiché', 'nom', $saisie['nom'], ['maxlength' => 100]) ?>
        <?= champ('Mot de passe', 'mot_de_passe', '', ['type' => 'password', 'required' => true, 'error' => $err['mot_de_passe'] ?? null, 'autocomplete' => 'new-password', 'hint' => '12 caractères minimum.']) ?>
        <?= champ('Confirmation', 'confirmation', '', ['type' => 'password', 'required' => true, 'error' => $err['confirmation'] ?? null, 'autocomplete' => 'new-password']) ?>
    </div>
    <label class="coche" for="f_test"><input type="checkbox" id="f_test" name="test" value="1"> <span><strong>Compte de test</strong> (stagiaire, formation) : les mails partent vers l'adresse de test ci-dessus ; réglages, imports, fusions, validations, envois et remises sont désactivés.</span></label>
    <button type="submit" class="btn btn-principal"><?= icon('user-plus') ?> Créer le compte</button>
</form>
