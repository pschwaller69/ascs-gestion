<section class="carte-connexion">
    <h1>Connexion</h1>
    <p class="aide">Réservé au bureau de l'association.</p>
    <?php if ($erreur): ?>
        <p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/connexion')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="retour" value="<?= e($retour) ?>">
        <?= champ('Adresse mail', 'email', $email, ['type' => 'email', 'required' => true, 'autocomplete' => 'username']) ?>
        <?= champ('Mot de passe', 'mot_de_passe', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <button type="submit" class="btn btn-principal">Se connecter</button>
    </form>
</section>
