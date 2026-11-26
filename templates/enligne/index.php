<script src="<?= e(url('/assets/qrcode.js')) ?>"></script>
<div class="titre-page">
    <h1>Inscriptions en ligne</h1>
    <a class="btn" href="<?= e(url('/demandes')) ?>">Voir les demandes reçues</a>
</div>
<p class="actions"><a class="btn btn-principal" href="<?= e(url('/en-ligne/affiches')) ?>" target="_blank" rel="noopener">Imprimer les deux affiches</a></p>
<p class="contexte">Un seul QR code pour les inscriptions, un seul pour les essais : la personne choisit ensuite son activité dans le formulaire. Les adhérents remplissent eux-mêmes leur inscription ou leur demande de cours d'essai, depuis un QR code ou un lien. Les inscriptions attendent votre validation dans « Demandes » ; les cours d'essai sans doute sur la fiche sont validés seuls.</p>
<p class="etat-en-ligne">
    <span class="pastille <?= $inscriptionOuverte ? 'pastille-ok' : 'pastille-erreur' ?>">Inscriptions <?= $inscriptionOuverte ? 'ouvertes' : 'fermées' ?></span>
    <span class="pastille <?= $essaiOuvert ? 'pastille-ok' : 'pastille-erreur' ?>">Cours d'essai <?= $essaiOuvert ? 'ouverts' : 'fermés' ?></span>
    <span>Saison <?= e($saisonTitre) ?></span>
    <a class="btn" href="<?= e(url('/parametres/en-ligne')) ?>"><?= icon('gear') ?> Réglages (Paramétrage)</a>
</p>

<div class="deux-colonnes qr-apercus">
    <section class="fiche">
        <h2>Inscription</h2>
        <div class="qr" data-qr="<?= e($urlInscription) ?>" role="img" aria-label="QR code vers le formulaire d'inscription"></div>
        <p class="url-qr"><a href="<?= e($urlInscription) ?>" target="_blank" rel="noopener"><?= e($urlInscription) ?></a></p>
        <p class="actions"><a class="btn" href="<?= e(url('/en-ligne/affiches', ['type' => 'inscription'])) ?>" target="_blank" rel="noopener">Affiche A4 à imprimer</a></p>
    </section>
    <section class="fiche">
        <h2>Cours d'essai</h2>
        <div class="qr" data-qr="<?= e($urlEssai) ?>" role="img" aria-label="QR code vers le formulaire de cours d'essai"></div>
        <p class="url-qr"><a href="<?= e($urlEssai) ?>" target="_blank" rel="noopener"><?= e($urlEssai) ?></a></p>
        <p class="actions"><a class="btn" href="<?= e(url('/en-ligne/affiches', ['type' => 'essai'])) ?>" target="_blank" rel="noopener">Affiche A4 à imprimer</a></p>
    </section>
</div>

