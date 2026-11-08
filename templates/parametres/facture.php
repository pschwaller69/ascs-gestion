<?php use Ascs\Documents\Reglages; ?>
<div class="titre-page">
    <h1>Facture</h1>
    <?php if ($exemple): ?><a class="btn" href="<?= e(url('/inscriptions/' . $exemple . '/facture')) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Voir une facture d'exemple</a><?php endif; ?>
</div>
<p class="contexte">Ces informations s'impriment sur chaque facture (en-tête, attestation et pied de page). Les champs vides ne s'affichent pas.</p>
<form class="fiche" method="post" action="<?= e(url('/parametres/facture')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h2>Images</h2>
    <div class="grille-images">
    <?php foreach (Reglages::IMAGES as $n => $lib): ?>
        <div class="champ">
            <label for="f_<?= e($n) ?>"><?= e($lib) ?></label>
            <?php if ($images[$n]): ?>
                <img class="apercu-image" src="<?= e(url('/parametres/facture/image/' . $n)) ?>?t=<?= time() ?>" alt="<?= e($lib) ?> actuel">
                <?= coche('Retirer l\'image', 'retirer_' . $n, false) ?>
            <?php else: ?>
                <p class="aide">Aucune image : <?= $n === 'logo' ? 'le nom de l\'association s\'affiche à la place' : 'le nom du signataire s\'affiche à la place' ?>.</p>
            <?php endif; ?>
            <input type="file" id="f_<?= e($n) ?>" name="<?= e($n) ?>" accept="image/png,image/jpeg,image/webp,image/gif">
            <p class="aide"><?= $n === 'logo' ? 'PNG ou JPEG, de préférence sur fond transparent.' : 'Photo ou scan de la signature avec le tampon, sur fond blanc ou transparent.' ?></p>
        </div>
    <?php endforeach; ?>
    </div>
    <h2>Association</h2>
    <div class="grille-champs">
    <?php foreach (Reglages::CHAMPS as $k => [$lib]): ?>
        <?php if ($k === 'expediteur' || $k === 'reponse') { continue; } ?>
        <?= champ($lib, $k, $v[$k], ['maxlength' => 255]) ?>
    <?php endforeach; ?>
    </div>
    <h2>Envoi par mail</h2>
    <?php if ($smtp = Reglages::compteSmtp()): ?>
        <p>Les mails partent du compte <strong><?= e($smtp) ?></strong> (SMTP o2switch, réglé dans le fichier .env du serveur).</p>
        <input type="hidden" name="expediteur" value="<?= e($smtp) ?>">
    <?php else: ?>
        <?= champ(Reglages::CHAMPS['expediteur'][0], 'expediteur', $v['expediteur'], ['type' => 'email', 'hint' => 'Une adresse existante de votre domaine chez o2switch, sinon les mails risquent de finir en spam.']) ?>
    <?php endif; ?>
    <?= champ(Reglages::CHAMPS['reponse'][0], 'reponse', $v['reponse'], ['type' => 'email', 'hint' => 'Quand un adhérent répond à un mail, sa réponse arrive ici (par exemple contact@ascs.fr). Vide : à l\'adresse d\'envoi.']) ?>
    <button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer</button>
</form>
<form method="post" action="<?= e(url('/parametres/facture/essai')) ?>" class="actions">
    <?= csrf_field() ?>
    <button type="submit" class="btn"><?= icon('mail') ?> M'envoyer un mail d'essai</button>
    <span class="aide">Envoyé à l'adresse de votre compte, avec les réglages enregistrés.</span>
</form>
