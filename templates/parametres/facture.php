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
        <?php if ($k === 'expediteur') { continue; } ?>
        <?= champ($lib, $k, $v[$k], ['maxlength' => 255]) ?>
    <?php endforeach; ?>
    </div>
    <h2>Envoi par mail</h2>
    <?= champ(Reglages::CHAMPS['expediteur'][0], 'expediteur', $v['expediteur'], ['type' => 'email', 'hint' => 'Une adresse existante de votre domaine chez o2switch (par exemple contact@ascs.fr), sinon les mails risquent de finir en spam. Les réponses des adhérents y arrivent.']) ?>
    <button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer</button>
</form>
