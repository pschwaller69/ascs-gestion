<?php use Ascs\Domain\Mailing;
$statuts = ['brouillon' => ['à valider', 'pastille-reste'], 'validee' => ['validé, à envoyer', 'pastille-reste'], 'en_cours' => ['en cours', 'pastille-reste'], 'terminee' => ['envoyé', 'pastille-ok']]; ?>
<div class="titre-page">
    <h1>Mailings et SMS</h1>
    <div class="actions-titre">
        <a class="btn btn-principal" href="<?= e(url('/mailing/nouveau')) ?>"><?= icon('mail') ?> Nouveau mail</a>
        <a class="btn" href="<?= e(url('/mailing/nouveau', ['canal' => 'sms'])) ?>"><?= icon('mail') ?> Nouveau SMS</a>
    </div>
</div>
<section class="fiche bloc-whatsapp">
    <h2>Communauté WhatsApp</h2>
    <?php if ($whatsapp !== ''): ?>
        <p>Invitez tous les adhérents de la saison à rejoindre la communauté. Le message est prêt, avec le lien ; vous le relisez, voyez le nombre de destinataires, pouvez faire un envoi de test, puis validez.</p>
        <p class="actions">
            <a class="btn btn-principal" href="<?= e(url('/mailing/nouveau', ['modele' => 'whatsapp'])) ?>"><?= icon('mail') ?> Inviter par mail</a>
            <a class="btn" href="<?= e(url('/mailing/nouveau', ['modele' => 'whatsapp', 'canal' => 'sms'])) ?>"><?= icon('sms') ?> Inviter par SMS</a>
            <a class="btn" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener noreferrer">Ouvrir le lien</a>
        </p>
    <?php else: ?>
        <p>Enregistrez le lien d'invitation de la communauté (dans WhatsApp : Communauté > Inviter des membres > Copier le lien) pour pouvoir l'envoyer à tous les adhérents.</p>
    <?php endif; ?>
    <details<?= $whatsapp === '' ? ' open' : '' ?>><summary>Lien d'invitation</summary>
        <form method="post" action="<?= e(url('/mailing/whatsapp')) ?>" class="ligne-reglage">
            <?= csrf_field() ?>
            <label>Lien <input type="url" name="lien" value="<?= e($whatsapp) ?>" placeholder="https://chat.whatsapp.com/…" size="45"></label>
            <button class="btn btn-petit" type="submit">Enregistrer</button>
        </form>
        <p class="aide">Si le lien est réinitialisé dans WhatsApp, l'ancien ne marche plus : collez le nouveau ici.</p>
    </details>
</section>
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
