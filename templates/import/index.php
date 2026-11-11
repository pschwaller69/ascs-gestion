<h1>Import WinDev</h1>
<?php if ($bascule): ?>
    <p class="flash flash-info">Bascule effectuée le <?= e(dfr($bascule)) ?> : l'application web est la référence, l'import WinDev est désactivé.</p>
<?php else: ?>
    <p>Envoyez les 10 exports XML de WinDev. L'import remplace tout le contenu de l'application par celui de WinDev, y compris ce qui a été saisi ici depuis le dernier import. Vous verrez d'abord une simulation, sans rien modifier.</p>
    <form class="fiche" method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="champ">
            <label for="fichiers">Fichiers XML (vous pouvez tous les sélectionner d'un coup)</label>
            <input type="file" id="fichiers" name="fichiers[]" accept=".xml" multiple required aria-describedby="fichiers-aide">
            <p class="aide" id="fichiers-aide">Fichiers attendus : <?= e(implode(', ', $tables)) ?>. Le nom de chaque fichier doit contenir le nom de sa table (par exemple « adherent.xml »). Taille maximale : <?= e($uploadMax) ?> par fichier, <?= e($postMax) ?> au total.</p>
        </div>
        <button type="submit" class="btn btn-principal"><?= icon('upload') ?> Envoyer et simuler</button>
    </form>
<?php endif; ?>

<?php if ($dernier): ?>
    <h2>Dernier import</h2>
    <p><?= e(dfr($dernier['date_import'] ?? $dernier['debut'] ?? '')) ?> <?= e(substr((string) ($dernier['date_import'] ?? $dernier['debut'] ?? ''), 11, 5)) ?></p>
<?php endif; ?>

<h2>Bascule définitive</h2>
<?php if ($bascule): ?>
    <form method="post" action="<?= e(url('/import/bascule')) ?>" class="fiche">
        <?= csrf_field() ?><input type="hidden" name="etat" value="non">
        <p>Revenir au mode transition réactive l'import WinDev. Le prochain import écrasera toutes les saisies faites ici.</p>
        <button type="submit" class="btn btn-danger">Revenir au mode transition</button>
    </form>
<?php else: ?>
    <form method="post" action="<?= e(url('/import/bascule')) ?>" class="fiche">
        <?= csrf_field() ?><input type="hidden" name="etat" value="oui">
        <p>Le jour où vous arrêtez WinDev, basculez : l'import sera désactivé pour ne plus écraser les saisies web.</p>
        <?= champ('Tapez BASCULER pour confirmer', 'confirme', '', ['autocomplete' => 'off']) ?>
        <button type="submit" class="btn btn-danger">Basculer définitivement</button>
    </form>
<?php endif; ?>

<?php if (!$bascule): ?>
<h2 id="effacer">Effacer toutes les données</h2>
<form method="post" action="<?= e(url('/import/effacer')) ?>" class="fiche zone-danger">
    <?= csrf_field() ?>
    <p>Pour repartir de zéro avant la bascule : tout ce qui a été saisi ou testé ici disparaît, ainsi que les données venues de WinDev. Ensuite, importez les fichiers WinDev : l'application contiendra exactement WinDev, sans aucun essai.</p>
    <?php $total = array_sum($aEffacer); ?>
    <details><summary><?= number_format($total, 0, ',', ' ') ?> ligne(s) seront effacées</summary>
        <ul class="liste-effacement"><?php foreach (array_filter($aEffacer) as $t => $n): ?><li><?= e(str_replace('_', ' ', $t)) ?> : <?= (int) $n ?></li><?php endforeach; ?></ul>
    </details>
    <p><strong>Conservés :</strong> les comptes du bureau, les réglages (facture, mails, HelloAsso, inscription en ligne), les saisons et les sections (remplacées par l'import WinDev), et les liens des animateurs, qui restent les mêmes.</p>
    <p class="aide">Une sauvegarde complète de la base est faite juste avant et reste téléchargeable ci-dessous.</p>
    <?= champ('Tapez EFFACER pour confirmer', 'confirme', '', ['autocomplete' => 'off']) ?>
    <button type="submit" class="btn btn-danger"><?= icon('x') ?> Effacer toutes les données</button>
</form>
<?php endif; ?>
<?php if ($sauvegardes): ?>
<h3>Sauvegardes avant effacement</h3>
<ul><?php foreach (array_slice($sauvegardes, 0, 5) as $s): ?><li><a href="<?= e(url('/import/sauvegarde', ['nom' => $s['nom']])) ?>"><?= e($s['nom']) ?></a> <span class="aide">(<?= e(number_format($s['taille'] / 1024, 0, ',', ' ')) ?> Ko)</span></li><?php endforeach; ?></ul>
<?php endif; ?>
