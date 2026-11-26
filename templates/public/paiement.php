<?php
/** @var string $etat inconnu | a_payer | paye | verification | indisponible */
if ($etat === 'inconnu'): ?>
<h1>Paiement en ligne</h1>
<p class="flash flash-erreur" role="alert">Ce lien de paiement n'est pas valable. Vérifiez qu'il est complet, ou adressez-vous au bureau.</p>
<?php return; endif; ?>
<h1>Paiement en ligne <small>inscription <?= e($d['reference']) ?></small></h1>
<p>Inscription de <strong><?= e(trim($d['prenom'] . ' ' . $d['nom'])) ?></strong>.</p>

<?php if ($etat === 'paye'): ?>
    <p class="flash flash-ok" role="status">Merci, le paiement de <?= e(money($paye['montant'])) ?><?= (int) $paye['fois'] > 1 ? ' (en ' . (int) $paye['fois'] . ' fois)' : '' ?> est bien reçu.</p>
    <p>HelloAsso vous envoie le reçu par mail. Le bureau valide l'inscription ; vous n'avez plus rien à régler pour les activités demandées.</p>
<?php elseif ($etat === 'verification'): ?>
    <p class="flash flash-info" role="status">Votre paiement est en cours de vérification par HelloAsso.</p>
    <p>Vous recevrez le reçu de HelloAsso par mail dès qu'il est accepté. Si aucune somme n'a été prélevée, vous pouvez <a href="<?= e(url('/paiement/' . $jeton)) ?>">réessayer</a>.</p>
<?php elseif ($etat === 'indisponible'): ?>
    <p class="flash flash-info">Le paiement en ligne n'est pas proposé pour cette inscription. Réglez auprès du bureau (chèque, espèces, chèques vacances…).</p>
<?php else: ?>
    <?php if ($erreur): ?><p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
    <p class="recap-total"><span>Reste à régler</span><strong><?= e(money($montant)) ?></strong></p>
    <p>Paiement par carte bancaire sur la page sécurisée de HelloAsso, la plateforme de paiement de l'association.</p>
<?php if (!empty($garanties)): ?>
    <p class="flash flash-info garantie-aide"><?php foreach ($garanties as $g): ?>Garantie de votre <?= e(mb_strtolower($g['libelle'])) ?> : <?= e(money($g['montant'])) ?> seront programmés sur la même carte le <?= e(dfr($g['date'])) ?>, en plus. Ce prélèvement est annulé dès que l'aide est confirmée par la mairie ; il n'est fait que si elle est refusée.<br><?php endforeach; ?></p>
<?php endif; ?>

    <ul class="liens-paiement">
        <li><a class="btn btn-principal" href="<?= e(url('/paiement/' . $jeton . '/payer', ['fois' => 1])) ?>" rel="nofollow">Payer <?= e(money($montant)) ?> en 1 fois</a></li>
        <?php if ($fois > 1): $c = (int) round($montant * 100); $part = intdiv($c, $fois); ?>
        <li><a class="btn" href="<?= e(url('/paiement/' . $jeton . '/payer', ['fois' => $fois])) ?>" rel="nofollow">Payer en <?= $fois ?> fois (<?= e(money(($c - ($fois - 1) * $part) / 100)) ?> aujourd'hui, puis <?= $fois - 1 ?> × <?= e(money($part / 100)) ?> par mois)</a></li>
        <?php endif; ?>
    </ul>
    <p class="aide">HelloAsso propose à la fin du paiement une contribution volontaire à son fonctionnement : elle est facultative, vous pouvez la mettre à 0. En plusieurs fois, les échéances suivantes sont prélevées sur la même carte, à un mois d'intervalle, la dernière au plus tard le 31 décembre.</p>
<?php endif; ?>
