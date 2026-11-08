<?php /** @var bool $ok @var bool $fait @var string $jeton */ ?>
<h1>Désinscription</h1>
<?php if (!$ok): ?>
    <p>Ce lien n'est pas valable. Pour ne plus recevoir nos mails, répondez simplement à l'un d'eux en le demandant.</p>
<?php elseif ($fait): ?>
    <p class="flash flash-ok" role="status">C'est fait : vous ne recevrez plus les mails d'information de l'association.</p>
    <p>Les messages liés à votre inscription (facture, informations pratiques demandées) peuvent encore vous être envoyés.</p>
<?php else: ?>
    <p>Vous ne souhaitez plus recevoir les mails d'information de l'association ?</p>
    <form method="post" action="<?= e(url('/desinscription/' . $jeton)) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-principal">Confirmer la désinscription</button>
    </form>
<?php endif; ?>
