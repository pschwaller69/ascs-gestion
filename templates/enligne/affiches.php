<script src="<?= e(url('/assets/qrcode.js')) ?>"></script>
<?php if (!$affiches): ?>
    <p class="vide">Ni les inscriptions ni les cours d'essai ne sont ouverts en ligne : ouvrez-les dans les réglages avant d'imprimer.</p>
<?php else: ?>
<p class="ne-pas-imprimer actions"><button type="button" class="btn btn-principal" data-imprimer>Imprimer</button>
    <span class="aide">Une affiche A4 par page. Testez le QR code avec votre téléphone avant d'imprimer.</span></p>
<div class="affiches affiches-grande">
<?php foreach ($affiches as $a): ?>
    <section class="affiche">
        <p class="affiche-asso">ASCS – Les Semailles</p>
        <h2><?= e($a['titre']) ?></h2>
        <p class="affiche-sous"><?= e($a['sous']) ?></p>
        <div class="qr" data-qr="<?= e($a['url']) ?>" role="img" aria-label="QR code : <?= e($a['titre']) ?>"></div>
        <p class="affiche-aide">Scannez avec l'appareil photo de votre téléphone</p>
        <p class="affiche-url"><?= e(preg_replace('#^https?://#', '', $a['url'])) ?></p>
    </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
