<h1>Import WinDev</h1>
<?php if ($ok): ?>
    <p class="flash flash-ok" role="status">Import terminé.</p>
<?php else: ?>
    <p class="flash flash-erreur" role="alert">Import annulé, aucune donnée modifiée.</p>
<?php endif; ?>
<pre class="rapport"><?= e($rapport) ?></pre>
<p><a class="btn" href="<?= e(url('/')) ?>">Retour à l'accueil</a></p>
