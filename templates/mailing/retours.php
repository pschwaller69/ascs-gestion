<?php use Ascs\Mail\Retours;
/** @var array $lignes @var string $type @var array $etat */ ?>
<div class="titre-page">
    <h1>Retours des mailings</h1>
    <div class="actions-titre">
        <?php if ($etat['configure']): ?>
            <form method="post" action="<?= e(url('/mailing/retours')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-principal">Relever la boîte maintenant</button></form>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/mailing')) ?>">Mailings et SMS</a>
    </div>
</div>
<?php if (!$etat['configure']): ?>
    <p class="flash flash-erreur">Boîte des mailings non configurée : renseignez SMTP_HOST et SMTP_MAILING_USER / SMTP_MAILING_PASS dans le fichier .env du serveur (la même boîte est relevée en IMAP).</p>
<?php else: ?>
    <p class="contexte">La boîte <strong><?= e($etat['boite']) ?></strong> reçoit les avis de non-remise des mailings et les désinscriptions faites depuis la messagerie (Gmail, Outlook…).
        Elle est relevée toutes les 15 minutes par la tâche automatique<?= $etat['releve_le'] ? ' ; dernière relève le ' . e(dfr($etat['releve_le'])) . ' à ' . e(substr($etat['releve_le'], 11, 5)) : '' ?>.
        Les messages restent dans la boîte.</p>
    <?php if ($etat['erreur']): ?><p class="flash flash-erreur"><?= e($etat['erreur']) ?></p><?php endif; ?>
<?php endif; ?>
<p class="aide">Adresse inconnue : le mail est marqué invalide sur les fiches (il ne recevra plus de mailing ; à corriger sur la fiche, puis « Remettre valide » dans Validation des mails).
    Rejeté : refus pour une autre raison (boîte pleine, filtrage anti-spam…), l'adresse est gardée ; à regarder si cela se répète. Retardé : le serveur réessaie tout seul.</p>
<p class="filtres">
    <a class="btn btn-petit<?= $type === '' ? ' btn-principal' : '' ?>" href="<?= e(url('/mailing/retours')) ?>">Tous</a>
    <?php foreach (Retours::TYPES as $k => $lib): ?>
        <a class="btn btn-petit<?= $type === $k ? ' btn-principal' : '' ?>" href="<?= e(url('/mailing/retours', ['type' => $k])) ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</p>
<?php if (!$lignes): ?><p class="vide">Aucun retour pour l'instant.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Reçu le</th><th>Type</th><th>Adresse / expéditeur</th><th>Détail</th><th>Mailing</th><th>Fait</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><?= e(dfr($l['recu_le'])) ?> <small><?= e(substr((string) $l['recu_le'], 11, 5)) ?></small></td>
            <td><span class="pastille <?= in_array($l['type'], ['adresse_inconnue', 'rejet'], true) ? 'pastille-reste' : 'pastille-ok' ?>"><?= e(Retours::TYPES[$l['type']] ?? $l['type']) ?></span></td>
            <td><?= e($l['adresse'] ?: $l['de']) ?></td>
            <td><small><?= $l['statut_smtp'] ? '<strong>' . e($l['statut_smtp']) . '</strong> ' : '' ?><?= e(mb_strimwidth((string) ($l['diagnostic'] ?: $l['objet']), 0, 220, '…')) ?></small></td>
            <td><?= $l['campagne_id'] ? '<a href="' . e(url('/mailing/' . $l['campagne_id'])) . '">' . e($l['campagne_objet'] ?: '#' . $l['campagne_id']) . '</a>' : '' ?></td>
            <td><small><?= e((string) $l['action']) ?></small></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
