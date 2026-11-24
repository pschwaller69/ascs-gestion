<section class="carte-connexion">
    <h1>Code de connexion</h1>
    <p class="aide">Double authentification : ouvrez votre application d'authentification (ASCS) et saisissez le code à 6 chiffres.</p>
    <?php if ($erreur): ?>
        <p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/connexion/code')) ?>">
        <?= csrf_field() ?>
        <?= champ('Code', 'code', '', ['required' => true, 'autocomplete' => 'one-time-code', 'maxlength' => 11, 'hint' => 'Téléphone perdu : saisissez un de vos codes de secours (ex. ABCDE-FGHJK).']) ?>
        <label class="coche" for="f_souvenir"><input type="checkbox" id="f_souvenir" name="souvenir" value="1"> <span>Se souvenir de cet ordinateur pendant 30 jours (le mot de passe reste demandé). Pas sur un ordinateur partagé.</span></label>
        <button type="submit" class="btn btn-principal">Valider</button>
    </form>
    <p><a href="<?= e(url('/connexion')) ?>">Recommencer la connexion</a></p>
</section>
