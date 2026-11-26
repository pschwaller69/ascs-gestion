<div class="titre-page">
    <h1>Coupons sport et culture (mairie)</h1>
    <?php if ($exemple): ?><a class="btn" href="<?= e(url('/inscriptions/' . $exemple . '/devis-coupon')) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Voir un devis d'exemple</a><?php endif; ?>
</div>
<p class="contexte">Le devis d'inscription de la ville est rempli par l'application (partie famille avec la fiche, partie association avec l'inscription, la signature et le tampon de Paramétrage &gt; Facture). Il part par mail avec la procédure de la ville quand une demande en ligne avec coupon est validée, ou depuis la fiche d'une inscription. À mettre à jour chaque saison (dates limites).</p>
<form class="fiche" method="post" action="<?= e(url('/parametres/coupons')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h2>Procédure de la ville (PDF joint au mail)</h2>
    <?php if ($procedure): ?>
        <p><a href="<?= e(url('/parametres/coupons/procedure')) ?>" target="_blank" rel="noopener">Voir la procédure actuelle</a></p>
        <?= coche('Retirer la procédure', 'retirer_procedure', false) ?>
    <?php else: ?>
        <p class="aide">Aucune procédure : le devis part seul.</p>
    <?php endif; ?>
    <div class="champ"><label for="f_procedure">Nouvelle procédure (PDF)</label><input type="file" id="f_procedure" name="procedure" accept="application/pdf"></div>
    <h2>Devis</h2>
    <?= champ('Nom de l\'association sur le devis', 'coupon_nom', $nom, ['maxlength' => 150]) ?>
    <?= champ('Texte en bas du devis (dépôt du dossier)', 'coupon_pied', $pied, ['type' => 'textarea', 'rows' => 4, 'hint' => '{annee} = année de début de la saison.']) ?>
    <h2>Mail</h2>
    <?= champ('Objet', 'coupon_objet', $objet, ['maxlength' => 200]) ?>
    <?= champ('Texte', 'coupon_texte', $texte, ['type' => 'textarea', 'rows' => 14, 'hint' => '{prenom}, {nom}, {activites}, {annee} et {association} sont remplacés.']) ?>
    <button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer</button>
</form>
