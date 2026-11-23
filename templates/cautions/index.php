<?php $aujourdhui = date('Y-m-d'); ?>
<div class="titre-page">
    <h1>Chèques de caution</h1>
    <?php if ($dus): ?>
    <form method="post" action="<?= e(url('/cautions/envoyer')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-principal"><?= icon('mail') ?> Envoyer les <?= count($dus) ?> rappel<?= count($dus) > 1 ? 's' : '' ?> du jour</button>
    </form>
    <?php endif; ?>
</div>
<p class="contexte">Chèques laissés en caution par les personnes qui paient en espèces : règlement « Chèque » avec le mot « caution » dans les remarques, à la date où il sera déposé en banque.
    Quand la personne apporte les espèces, passez le règlement en « Espèces » : le chèque sort de cette liste et ne sera pas relancé.</p>

<p class="flash <?= $reglages['actif'] ? 'flash-info' : 'flash-erreur' ?>">
    <?= $reglages['actif']
        ? 'Rappel automatique activé : un mail part ' . (int) $reglages['jours'] . ' jours avant la date de pose (chaque jour à partir de 9 h, par la tâche automatique).'
        : 'Rappel automatique désactivé : activez-le en bas de la page.' ?>
</p>

<h2>En attente des espèces</h2>
<?php if (!$liste): ?>
    <p class="vide">Aucun chèque de caution en attente.</p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date de pose</th><th>Adhérent</th><th>Section</th><th>N° chèque</th><th class="num">Montant</th><th>Rappel</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $c): $r = $c['rappel']; ?>
        <tr>
            <td><?= e(dfr($c['date_reglement'])) ?></td>
            <td><a href="<?= e(url('/adherents/' . $c['adherent_id'], ['onglet' => 'reglements'])) ?>"><?= e(trim($c['nom'] . ' ' . $c['prenom'])) ?></a>
                <?php if (trim($c['nom_parent'] . $c['prenom_parent']) !== ''): ?><br><small class="aide"><?= e(trim($c['nom_parent'] . ' ' . $c['prenom_parent'])) ?></small><?php endif; ?></td>
            <td><?= e($c['section']) ?></td>
            <td><?= e($c['identifiant']) ?></td>
            <td class="num"><?= e(money($c['montant'])) ?></td>
            <td>
            <?php if ($r && (int) $r['ok'] === 1): ?>
                <span class="pastille pastille-ok">Envoyé le <?= e(dfr($r['envoye_le'])) ?></span><br><small class="aide"><?= e($r['destinataire']) ?></small>
            <?php elseif ($r): ?>
                <span class="pastille pastille-excedent">Échec le <?= e(dfr($r['envoye_le'])) ?></span><br><small class="aide"><?= e($r['erreur']) ?></small>
            <?php elseif ($c['adresse'] === null): ?>
                <span class="pastille pastille-reste">Pas de mail valide</span><?php if ($c['telephone1']): ?><br><small class="aide">Appeler le <?= e($c['telephone1']) ?></small><?php endif; ?>
            <?php elseif ($c['date_reglement'] < $aujourdhui): ?>
                <span class="aide">Date de pose passée</span>
            <?php else: ?>
                <span class="aide"><?= $reglages['actif'] ? 'Prévu le ' : 'Serait envoyé le ' ?><?= e(dfr(max($aujourdhui, $c['rappel_prevu']))) ?></span>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<?php if ($exemple): ?>
<details class="fiche">
    <summary>Aperçu du premier rappel du jour (à <?= e($dus[0]['adresse']) ?>)</summary>
    <p><strong><?= e($exemple[0]) ?></strong></p>
    <pre><?= e($exemple[1]) ?></pre>
</details>
<?php endif; ?>

<h2>Réglages du rappel</h2>
<form class="fiche" method="post" action="<?= e(url('/cautions')) ?>">
    <?= csrf_field() ?>
    <?= coche('Envoyer le rappel automatiquement', 'actif', $reglages['actif']) ?>
    <?= champ('Jours avant la date de pose', 'jours', $reglages['jours'], ['type' => 'number', 'min' => '1', 'max' => '60', 'required' => true,
        'hint' => 'Un chèque saisi moins de jours avant sa date de pose est rappelé dès le lendemain matin.']) ?>
    <?= champ('Objet du mail', 'objet', $reglages['objet'], ['maxlength' => 200]) ?>
    <?= champ('Texte du mail', 'texte', $reglages['texte'], ['type' => 'textarea', 'rows' => 12,
        'hint' => 'Remplacés à l\'envoi : {noms} (adhérents), {cheques} (une ligne par chèque : numéro, montant, section), {date} (date de pose), {total}, {association} ; et pour accorder au pluriel : {un_cheque} (un chèque / 2 chèques), {ce_cheque} (ce chèque sera / ces chèques seront), {s}, {votre_cheque}. Un seul mail par adresse et par date, même pour plusieurs enfants.']) ?>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer</button></div>
</form>
