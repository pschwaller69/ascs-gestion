<div class="titre-page">
    <h1>Inscription <small>n° <?= (int) $i['id'] ?></small></h1>
    <div class="actions-titre">
        <a class="btn" href="<?= e(url('/inscriptions/' . $i['id'] . '/facture')) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Facture PDF</a>
        <a class="btn btn-principal" href="<?= e(url('/reglements/nouveau', ['inscription' => $i['id']])) ?>"><?= icon('wallet') ?> Nouveau règlement</a>
    </div>
</div>
<p class="contexte">Adhérent : <a href="<?= e(url('/adherents/' . $i['adherent_id'])) ?>"><strong><?= e(trim(($i['nom'] ?? '') . ' ' . ($i['prenom'] ?? ''))) ?></strong></a>
    · <?= e($i['section']) ?> · saison <?= e($i['saison']) ?> · <?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?></p>
<?php $action = url('/inscriptions/' . $i['id']); $adherentId = (int) $i['adherent_id']; $edition = true; require __DIR__ . '/_form.php'; ?>
<h2>Règlements</h2>
<?php if (!$reglements): ?><p class="vide">Aucun règlement enregistré.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr></thead>
    <tbody>
    <?php foreach ($reglements as $r): ?>
        <tr>
            <td><a href="<?= e(url('/reglements/' . $r['id'])) ?>"><?= date_reglement($r['date_reglement']) ?></a></td>
            <td><?= e($r['mode']) ?></td><td><?= e($r['identifiant']) ?></td><td class="num"><?= e(money($r['montant'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="3">Total réglé</th><th class="num"><?= e(money($i['regle'])) ?></th></tr></tfoot>
</table></div>
<?php endif; ?>
<details class="fiche" id="envoi-facture">
    <summary>Envoyer la facture par mail<?= $envois ? ' <small class="aide">(dernier envoi le ' . e(dfr($envois[0]['date_envoi'])) . ' à ' . e($envois[0]['destinataire']) . ($envois[0]['ok'] ? '' : ', échec') . ')</small>' : '' ?></summary>
    <form method="post" action="<?= e(url('/inscriptions/' . $i['id'] . '/facture/envoyer')) ?>">
        <?= csrf_field() ?>
        <?= champ('Destinataire', 'destinataire', $mailFacture, ['type' => 'email', 'required' => true, 'hint' => 'Mail de la fiche adhérent, modifiable pour cet envoi.']) ?>
        <?= champ('Message', 'message', $messageFacture, ['type' => 'textarea', 'rows' => 7]) ?>
        <p class="aide">La facture est jointe en PDF<?= $expediteur === '' ? '. <strong>Renseignez d\'abord l\'adresse d\'envoi dans Paramétrage &gt; Facture.</strong>' : ', envoyée depuis ' . e($expediteur) . '.' ?></p>
        <button type="submit" class="btn btn-principal"><?= icon('mail') ?> Envoyer</button>
    </form>
    <?php if (count($envois) > 0): ?>
        <ul class="liste-envois"><?php foreach ($envois as $en): ?><li><?= e(dfr($en['date_envoi'])) ?> <?= e(substr((string) $en['date_envoi'], 11, 5)) ?> — <?= e($en['destinataire']) ?> — <?= $en['ok'] ? 'envoyée' : '<span class="texte-erreur">échec : ' . e($en['erreur']) . '</span>' ?><?= $en['par'] ? ' (' . e($en['par']) . ')' : '' ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</details>
<form method="post" action="<?= e(url('/inscriptions/' . $i['id'] . '/supprimer')) ?>" class="zone-danger">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Supprimer l'inscription…</button>
</form>
