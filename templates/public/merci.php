<h1><?= $m['type'] === 'essai' ? 'Demande d\'essai envoyée' : 'Inscription envoyée' ?></h1>
<p class="flash flash-ok" role="status">Merci <?= e($m['prenom']) ?>, votre demande a bien été reçue.</p>
<p>Votre référence : <strong class="reference"><?= e($m['ref']) ?></strong>. Notez-la, elle permet au bureau de retrouver votre demande.</p>
<p><?= $m['type'] === 'essai'
    ? 'Le bureau vous recontacte pour confirmer la date de votre cours d\'essai.'
    : 'Le bureau valide votre inscription. Elle sera définitive une fois le règlement reçu.' ?></p>
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
<?php if (!empty($m['pieces'])): ?>
    <h2>À apporter au premier cours</h2>
    <ul class="pieces"><?php foreach ($m['pieces'] as $pc): ?><li><?= e($pc) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($paiements): ?>
    <h2>Régler en ligne</h2>
    <p>Vous pouvez régler par carte bancaire sur HelloAsso :</p>
    <ul class="liens-paiement">
    <?php foreach ($paiements as $s): ?>
        <li><a class="btn btn-principal" href="<?= e($s['lien_paiement']) ?>" rel="noopener"><?= e($s['titre']) ?></a></li>
    <?php endforeach; ?>
    </ul>
    <p class="aide">Vous pouvez aussi régler par chèque, espèces, chèques vacances ou Pass Sport auprès du bureau.</p>
<?php endif; ?>
<?php if ($message): ?><p class="flash flash-info"><?= nl2br(e($message)) ?></p><?php endif; ?>
