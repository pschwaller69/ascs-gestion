<h1>Accueil</h1>
<?php if ($stats['helloasso'] > 0): ?>
    <p class="flash flash-info"><a href="<?= e(url('/helloasso')) ?>"><?= (int) $stats['helloasso'] ?> adhésion<?= $stats['helloasso'] > 1 ? 's' : '' ?> HelloAsso à valider</a></p>
<?php endif; ?>
<?php if ($stats['demandes'] > 0): ?>
    <p class="flash flash-info"><a href="<?= e(url('/demandes')) ?>"><?= (int) $stats['demandes'] ?> demande<?= $stats['demandes'] > 1 ? 's' : '' ?> d'inscription ou d'essai en ligne à traiter</a></p>
<?php endif; ?>
<section class="chiffres" aria-label="Chiffres de la saison">
    <div><span class="val"><?= (int) $stats['adherents'] ?></span><span class="lib">adhérents inscrits</span></div>
    <div><span class="val"><?= (int) $stats['inscriptions'] ?></span><span class="lib">inscriptions</span></div>
    <div><span class="val"><?= e(money($stats['montant'])) ?></span><span class="lib">à encaisser</span></div>
    <div><span class="val"><?= e(money($stats['regle'])) ?></span><span class="lib">réglé</span></div>
    <div class="<?= $stats['reste'] > 0.005 ? 'alerte' : '' ?>"><span class="val"><?= e(money($stats['reste'])) ?></span><span class="lib">reste dû</span></div>
</section>
<p><a class="btn" href="<?= e(url('/inscriptions', ['statut' => 'impayees'])) ?>">Voir les inscriptions non payées</a></p>
