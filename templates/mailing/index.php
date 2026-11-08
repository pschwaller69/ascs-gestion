<?php use Ascs\Domain\Mailing;
$statuts = ['brouillon' => ['à valider', 'pastille-reste'], 'validee' => ['validé, à envoyer', 'pastille-reste'], 'en_cours' => ['en cours', 'pastille-reste'], 'terminee' => ['envoyé', 'pastille-ok']]; ?>
<div class="titre-page">
    <h1>Mailings et SMS</h1>
    <div class="actions-titre">
        <a class="btn btn-principal" href="<?= e(url('/mailing/nouveau')) ?>"><?= icon('mail') ?> Nouveau mail</a>
        <a class="btn" href="<?= e(url('/mailing/nouveau', ['canal' => 'sms'])) ?>"><?= icon('mail') ?> Nouveau SMS</a>
    </div>
</div>
<p class="contexte">Chaque envoi est d'abord un brouillon : vous vérifiez les destinataires, puis vous validez. Les personnes qui ont refusé les mails ou les SMS ne sont jamais incluses.</p>
<?php if (!$campagnes): ?><p class="vide">Aucun envoi pour l'instant.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Canal</th><th>Objet / texte</th><th>Destinataires</th><th class="num">Envoyés</th><th>État</th></tr></thead>
    <tbody>
    <?php foreach ($campagnes as $c): [$lib, $cls] = $statuts[$c['statut']] ?? [$c['statut'], '']; ?>
        <tr>
            <td><?= e(dfr($c['cree_le'])) ?></td>
            <td><?= $c['canal'] === 'sms' ? 'SMS' : 'Mail' ?></td>
            <td><a href="<?= e(url('/mailing/' . $c['id'])) ?>"><?= e($c['objet'] ?: mb_strimwidth((string) $c['texte'], 0, 60, '…')) ?></a></td>
            <td><small><?= e(Mailing::CIBLES[$c['cible']] ?? $c['cible']) ?></small></td>
            <td class="num"><?= (int) $c['envoyes'] ?> / <?= (int) $c['total'] - (int) $c['retires'] ?><?= $c['echecs'] ? ' <span class="texte-erreur">(' . (int) $c['echecs'] . ' échec)</span>' : '' ?></td>
            <td><span class="pastille <?= $cls ?>"><?= e($lib) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
