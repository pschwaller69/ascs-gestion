<div class="titre-page"><h1>Aides financières</h1></div>
<?php $ici = '/parametres/aides'; ?>
<?php $sous = [['/parametres/en-ligne', 'Ouverture et textes'], ['/parametres/activites-en-ligne', 'Activités en ligne'], ['/parametres/aides', 'Aides']]; ?>
<nav class="sous-onglets" aria-label="Réglages des inscriptions en ligne">
    <?php foreach ($sous as [$u, $l]): ?><a href="<?= e(url($u)) ?>"<?= $u === $ici ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
    <a href="<?= e(url('/en-ligne')) ?>">QR codes et affiches ↗</a>
</nav>
<form method="post" action="<?= e(url('/parametres/aides')) ?>" class="fiche">
    <?= csrf_field() ?>
    <p class="aide">Dates au format jj/mm, rapportées à la saison (septembre à décembre : année de début ; janvier à août : année suivante). Date limite dépassée : l'aide n'est plus proposée dans le formulaire. Garantie : quand la personne paie par carte sur HelloAsso, le montant de l'aide est programmé à cette date et annulé dès que vous confirmez l'aide (Règlement &gt; Garanties par carte). Vide : pas de limite, pas de garantie. « Qui y a droit » : à revoir à chaque rentrée (vide : texte par défaut, Pass'Sport selon les règles 2026-2027).</p>
    <div class="table-cadre"><table class="table-reglages">
        <thead><tr><th>Aide</th><th>Qui y a droit (affiché dans le formulaire)</th><th>Demande possible jusqu'au</th><th>Garantie par carte le</th></tr></thead>
        <tbody>
        <?php foreach ($aidesDates as $ad): ?>
            <tr><td><?= e($ad['libelle']) ?></td>
                <td><textarea name="aide_droit[<?= e($ad['cle']) ?>]" rows="3" maxlength="600" class="aide-droit-saisie" aria-label="Qui a droit à <?= e($ad['libelle']) ?>" placeholder="<?= e($ad['droit_defaut']) ?>"><?= e($ad['droit']) ?></textarea></td>
                <td><input type="text" name="aide_limite[<?= e($ad['cle']) ?>]" value="<?= e($ad['limite']) ?>" maxlength="5" placeholder="jj/mm" inputmode="numeric" pattern="\d{1,2}/\d{1,2}" aria-label="Date limite pour <?= e($ad['libelle']) ?>"></td>
                <td><input type="text" name="aide_garantie[<?= e($ad['cle']) ?>]" value="<?= e($ad['garantie']) ?>" maxlength="5" placeholder="jj/mm" inputmode="numeric" pattern="\d{1,2}/\d{1,2}" aria-label="Date de garantie pour <?= e($ad['libelle']) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer les réglages</button></div>
</form>
