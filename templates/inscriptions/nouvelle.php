<h1>Nouvelle inscription</h1>
<p class="contexte">Adhérent : <a href="<?= e(url('/adherents/' . $a['id'])) ?>"><strong><?= e(trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? ''))) ?></strong></a>
    <?= $a['date_naissance'] ? ' · né(e) le ' . e(dfr($a['date_naissance'])) : '' ?></p>
<?php $action = url('/inscriptions'); $adherentId = (int) $a['id']; $edition = false; require __DIR__ . '/_form.php'; ?>
