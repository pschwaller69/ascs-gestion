<?php /** @var array $erreurs */ ?>
<div class="titre-page"><h1>Journal des erreurs</h1></div>
<p class="contexte">Les erreurs inattendues des 2 derniers mégaoctets de journal, la plus récente d'abord. Pour m'en signaler une, copiez sa référence et son message.</p>
<?php if (!$erreurs): ?>
<p class="vide">Aucune erreur enregistrée.</p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Quand</th><th>Page</th><th>Explication</th><th>Détail technique</th></tr></thead>
    <tbody>
    <?php foreach ($erreurs as $f): ?>
        <tr>
            <td><?= e(dfr((string) $f['quand'])) ?> <?= e(substr((string) $f['quand'], 11, 5)) ?><br><small class="aide"><?= e((string) $f['ref']) ?></small></td>
            <td><code><?= e((string) $f['page']) ?></code></td>
            <td><?= e((string) $f['explication']) ?></td>
            <td><details><summary><?= e(mb_strimwidth((string) $f['message'], 0, 90, '…')) ?></summary>
                <p><code><?= e((string) $f['message']) ?></code></p>
                <p class="aide"><?= e((string) $f['type']) ?> · <code><?= e((string) $f['ou']) ?></code></p>
                <?php if (!empty($f['pile'])): ?><pre class="pile"><?= e(implode("\n", (array) $f['pile'])) ?></pre><?php endif; ?>
            </details></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
