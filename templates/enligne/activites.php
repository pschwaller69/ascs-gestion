<div class="titre-page"><h1>Activités en ligne</h1></div>
<?php $ici = '/parametres/activites-en-ligne'; ?>
<?php $sous = [['/parametres/en-ligne', 'Ouverture et textes'], ['/parametres/activites-en-ligne', 'Activités en ligne'], ['/parametres/aides', 'Aides']]; ?>
<nav class="sous-onglets" aria-label="Réglages des inscriptions en ligne">
    <?php foreach ($sous as [$u, $l]): ?><a href="<?= e(url($u)) ?>"<?= $u === $ici ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
    <a href="<?= e(url('/en-ligne')) ?>">QR codes et affiches ↗</a>
</nav>
<form method="post" action="<?= e(url('/parametres/activites-en-ligne')) ?>" class="fiche">
    <?= csrf_field() ?>
    <p class="aide">Pour chaque activité : proposée dans le formulaire d'inscription, et/ou dans le formulaire de cours d'essai. Les sections « Z ESSAI » ne servent plus : un cours d'essai validé est noté sur l'activité elle-même (listes de présence des animateurs, fiche de l'adhérent), sans inscription. Dates pour la saison des inscriptions en ligne (<?= e($saisonTitre) ?>) : la reprise est affichée aux adhérents ; pas de cours d'essai avant « Essais à partir du » (vide : la date de reprise).</p>
    <p class="aide activites-filtre"><?= count(array_filter($sections, static fn ($x) => $x['ouvert'] || $x['essai_ouvert'])) ?> activité(s) en ligne sur <?= count($sections) ?></p>
    <ul class="activites-reglages">
    <?php foreach ($sections as $s): $sid = (int) $s['id']; ?>
        <li class="activite-reglage">
            <div class="ar-tete">
                <span class="ar-titre"><?= e($s['titre']) ?></span>
                <span class="ar-tarif"><?= e(money($s['montant'])) ?><?php if ($s['places'] !== null): ?> · <?= (int) $s['inscrits'] ?> inscrit<?= $s['inscrits'] > 1 ? 's' : '' ?><?= $s['attente'] ? ' + ' . (int) $s['attente'] . ' demande' . ($s['attente'] > 1 ? 's' : '') . ' en ligne' : '' ?> / <?= (int) $s['places'] ?> places<?= $s['complet'] ? ' · <strong class="complet">complet</strong>' : '' ?><?php endif; ?></span>
            </div>
            <div class="ar-bascules">
                <label class="bascule"><input type="checkbox" name="s[<?= $sid ?>][ouvert]" value="1"<?= attr((bool) $s['ouvert'], 'checked') ?>><span><?= icon('check') ?> Inscription en ligne</span></label>
                <label class="bascule bascule-essai"><input type="checkbox" name="s[<?= $sid ?>][essai]" value="1"<?= attr((bool) $s['essai_ouvert'], 'checked') ?>><span><?= icon('check') ?> Cours d'essai</span></label>
            </div>
            <div class="ar-dates">
                <label>Reprise des cours<input type="date" name="s[<?= $sid ?>][reprise]" value="<?= e($s['reprise']) ?>"<?= isset($err['reprise_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
                <label>Essais à partir du<input type="date" name="s[<?= $sid ?>][essai_des]" value="<?= e($s['essai_des']) ?>" title="Vide : la date de reprise"<?= isset($err['essai_des_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
            </div>
            <div class="ar-textes">
                <label>Places par cours (vide : pas de limite)<input type="text" inputmode="numeric" name="s[<?= $sid ?>][places]" value="<?= e((string) ($s['places'] ?? '')) ?>" maxlength="3"<?= isset($err['places_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
                <label>Texte affiché (horaires, public…)<input type="text" name="s[<?= $sid ?>][description]" value="<?= e($s['description']) ?>" maxlength="500" placeholder="Ex. : lundi 18 h 30 – 20 h, dès 8 ans"></label>
                <label>Adresse du cours<input type="text" name="s[<?= $sid ?>][adresse]" value="<?= e($s['adresse']) ?>" maxlength="255" placeholder="Salle, rue, ville"></label>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer les réglages</button></div>
</form>
