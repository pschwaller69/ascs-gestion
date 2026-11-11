<?php /** @var string $titre @var string $message @var ?array $fiche @var bool $detail */
$fiche = $fiche ?? null;
$detail = $detail ?? false;
?>
<section class="carte-connexion carte-erreur">
    <h1><?= e($titre) ?></h1>
    <?php if ($fiche): ?><p><strong><?= e($fiche['explication']) ?></strong></p><?php endif; ?>
    <p><?= e($message) ?></p>
    <?php if ($fiche): ?>
        <?php if ($detail): ?>
        <dl class="detail-erreur">
            <dt>Référence</dt><dd><code><?= e($fiche['ref']) ?></code></dd>
            <dt>Page</dt><dd><code><?= e($fiche['page']) ?></code></dd>
            <dt>Message technique</dt><dd><code><?= e($fiche['message']) ?></code></dd>
            <dt>Emplacement</dt><dd><code><?= e($fiche['ou']) ?></code></dd>
            <dt>Heure</dt><dd><?= e(dfr($fiche['quand'])) ?> <?= e(substr($fiche['quand'], 11, 8)) ?></dd>
        </dl>
        <p class="aide">Ce détail est aussi enregistré dans Paramétrage &gt; Journal des erreurs.</p>
        <?php else: ?>
        <p class="aide">Référence de l'erreur : <code><?= e($fiche['ref']) ?></code></p>
        <?php endif; ?>
    <?php endif; ?>
    <p><a class="btn" href="<?= e(url('/')) ?>">Retour à l'accueil</a><?php if ($detail): ?> <a class="btn" href="<?= e(url('/journal-erreurs')) ?>">Journal des erreurs</a><?php endif; ?></p>
</section>
