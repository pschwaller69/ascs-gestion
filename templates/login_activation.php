<script src="<?= e(url('/assets/qrcode.js')) ?>?v=<?= e(Ascs\Web\Ribbon::version()) ?>"></script>
<section class="carte-connexion">
    <h1>Double authentification</h1>
    <p>Le bureau a activé la double authentification pour votre compte. À chaque connexion, en plus du mot de passe, vous saisirez un code à 6 chiffres donné par une application de votre téléphone.</p>
    <?php if ($erreur): ?>
        <p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
    <ol>
        <li>Installez une application d'authentification si vous n'en avez pas : Google Authenticator, Microsoft Authenticator, FreeOTP…</li>
        <li>Dans l'application, ajoutez un compte en scannant ce QR code :
            <div class="qr" data-qr="<?= e($lien) ?>" role="img" aria-label="QR code à scanner avec l'application d'authentification"></div>
            <small class="aide">Vous êtes sur le téléphone ? <a href="<?= e($lien) ?>">Ouvrir dans l'application</a>, ou saisir la clé à la main : <code><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></small>
        </li>
        <li>Saisissez le code affiché par l'application :</li>
    </ol>
    <form method="post" action="<?= e(url('/connexion/activation')) ?>">
        <?= csrf_field() ?>
        <?= champ('Code', 'code', '', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 7]) ?>
        <label class="coche" for="f_souvenir"><input type="checkbox" id="f_souvenir" name="souvenir" value="1"> <span>Se souvenir de cet ordinateur pendant 30 jours (le mot de passe reste demandé). Pas sur un ordinateur partagé.</span></label>
        <button type="submit" class="btn btn-principal">Activer</button>
    </form>
</section>
