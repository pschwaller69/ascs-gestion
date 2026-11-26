<h1><?= $m['type'] === 'essai' ? 'Demande d\'essai envoyée' : 'Inscription envoyée' ?></h1>
<p class="flash flash-ok" role="status">Merci <?= e($m['prenom']) ?>, votre demande a bien été reçue.</p>
<p>Votre référence : <strong class="reference"><?= e($m['ref']) ?></strong>. Notez-la, elle permet au bureau de retrouver votre demande.</p>
<p><?= $m['type'] === 'essai'
    ? (!empty($m['essai_valide']) ? 'Votre cours d\'essai est enregistré : l\'animateur a votre nom sur sa liste. Présentez-vous à l\'heure du cours.' : 'Le bureau vous recontacte pour confirmer la date de votre cours d\'essai.')
    : 'Le bureau valide votre inscription. Elle sera définitive une fois le règlement reçu.' ?></p>
<?php if (!empty($m['dates'])): ?>
    <section class="recap a-retenir"><h2>À retenir</h2>
    <?php foreach ($m['dates'] as $l): if (!is_array($l)) { continue; } ?>
        <div class="lieu-cours">
            <p><strong><?= e($l['titre']) ?></strong><?= $l['quand'] !== '' ? ' : ' . e($l['quand']) : '' ?></p>
            <?php if ($l['adresse']): ?><p><?= e($l['adresse']) ?> · <a href="<?= e(Ascs\Domain\EnLigne::lienPlan($l['adresse'])) ?>" target="_blank" rel="noopener">Voir le plan</a></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php if (!empty($m['whatsapp'])): ?>
    <p class="whatsapp-communaute">Vous ne trouvez pas le cours, une question ? <a class="btn btn-whatsapp" href="<?= e($m['whatsapp']) ?>" target="_blank" rel="noopener">Rejoindre la communauté WhatsApp</a></p>
<?php endif; ?>
<?php if (!empty($m['recap'])): $r = $m['recap']; ?>
    <section class="recap">
        <h2>Récapitulatif</h2>
        <ul>
            <?php foreach ($r['lignes'] as $l): ?><li><span><?= e($l['libelle']) ?></span><span><?= e(money($l['montant'])) ?></span></li><?php endforeach; ?>
            <?php foreach ($r['aides'] as $a): ?><li class="recap-aide"><span><?= e($a['libelle']) ?></span><span>− <?= e(money($a['montant'])) ?></span></li><?php endforeach; ?>
            <?php if ($r['frais'] > 0): ?><li><span>Frais de gestion ANCV</span><span><?= e(money($r['frais'])) ?></span></li><?php endif; ?>
        </ul>
        <p class="recap-total"><span>Reste à régler</span><strong><?= e(money($r['reste'])) ?></strong></p>
        <p class="aide">Montant indicatif, confirmé par le bureau.</p>
    </section>
<?php endif; ?>
<?php if (!empty($m['bureau'])): ?>
    <h2>À déposer au bureau</h2>
    <p>Le bureau de l'association est ouvert <strong><?= e($m['horaires'] ?? Ascs\Domain\Bulletin::HORAIRES_DEFAUT) ?></strong>.</p>
    <ul class="pieces"><?php foreach ($m['bureau'] as $pc): ?><li><?= e($pc) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($m['pieces'])): ?>
    <h2>À apporter au premier cours</h2>
    <ul class="pieces"><?php foreach ($m['pieces'] as $pc): ?><li><?= e($pc) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($enLigne)): $c = (int) round($enLigne['montant'] * 100); $part = intdiv($c, max(1, $enLigne['fois'])); ?>
    <h2>Payer maintenant</h2>
    <p>Réglez le reste, <strong><?= e(money($enLigne['montant'])) ?></strong>, par carte bancaire sur la page sécurisée de HelloAsso :</p>
    <div class="note-contribution" role="note"><strong>Pas de frais à payer à HelloAsso.</strong> Au moment de payer, HelloAsso ajoute une « contribution volontaire » à son fonctionnement, souvent cochée d'office. Elle n'est pas obligatoire et ne va pas à l'association : modifiez-la et mettez 0 € pour ne payer que la cotisation.</div>
    <ul class="liens-paiement">
        <li><a class="btn btn-principal" href="<?= e(url('/paiement/' . $enLigne['jeton'] . '/payer', ['fois' => 1])) ?>" rel="nofollow">Payer <?= e(money($enLigne['montant'])) ?></a></li>
        <?php if ($enLigne['fois'] > 1): $f = (int) $enLigne['fois']; ?>
        <li><a class="btn" href="<?= e(url('/paiement/' . $enLigne['jeton'] . '/payer', ['fois' => $f])) ?>" rel="nofollow">Payer en <?= $f ?> fois (<?= e(money(($c - ($f - 1) * $part) / 100)) ?> aujourd'hui, puis <?= $f - 1 ?> × <?= e(money($part / 100)) ?> par mois)</a></li>
        <?php endif; ?>
    </ul>
<?php if (!empty($enLigne['garanties'])): ?>
    <p class="flash flash-info garantie-aide"><?php foreach ($enLigne['garanties'] as $g): ?>Garantie de votre <?= e(mb_strtolower($g['libelle'])) ?> : <?= e(money($g['montant'])) ?> seront programmés sur la même carte le <?= e(dfr($g['date'])) ?>, en plus. Ce prélèvement est annulé dès que l'aide est confirmée par la mairie ; il n'est fait que si elle est refusée.<br><?php endforeach; ?></p>
<?php endif; ?>
    <p class="aide">Vous pouvez aussi payer plus tard avec le lien reçu par mail.</p>
<?php endif; ?>
<?php if ($message): ?><p class="flash flash-info"><?= nl2br(e($message)) ?></p><?php endif; ?>
