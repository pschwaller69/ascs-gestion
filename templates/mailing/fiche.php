<?php use Ascs\Domain\Mailing; use Ascs\Mail\Sms;
/** @var array $c @var array $dest @var array $sectionsNoms @var string $apercu @var array $reglages @var bool $smsPret */
$sms = $c['canal'] === 'sms';
$brouillon = $c['statut'] === 'brouillon';
$actifs = array_values(array_filter($dest, static fn ($d) => $d['statut'] !== 'retire'));
$aEnvoyer = array_values(array_filter($dest, static fn ($d) => $d['statut'] === 'a_envoyer'));
$etat = ['brouillon' => 'Brouillon : à vérifier puis valider', 'validee' => 'Validé, à envoyer', 'en_cours' => 'Envoi en cours', 'terminee' => 'Envoi terminé'][$c['statut']] ?? $c['statut'];
?>
<div class="titre-page">
    <h1><?= $sms ? 'SMS' : 'Mail' ?> <?= $c['objet'] ? '« ' . e($c['objet']) . ' »' : '' ?></h1>
    <a class="btn" href="<?= e(url('/mailing')) ?>">Mailings et SMS</a>
</div>
<div class="compteur-envoi" role="status">
    <span class="val"><?= count($actifs) ?></span>
    <span class="lib"><?= $sms ? 'numéro' : 'adresse' ?><?= count($actifs) > 1 ? 's' : '' ?> <?= $brouillon ? 'recevront ce ' . ($sms ? 'SMS' : 'mail') : 'pour cet envoi' ?><?= count($dest) - count($actifs) ? ' · ' . (count($dest) - count($actifs)) . ' retiré(s)' : '' ?><?= $c['envoyes'] ? ' · ' . (int) $c['envoyes'] . ' déjà envoyé(s)' : '' ?></span>
</div>
<p class="contexte"><strong><?= e($etat) ?></strong> · <?= e(Mailing::CIBLES[$c['cible']] ?? $c['cible']) ?><?= $sectionsNoms ? ' : ' . e(implode(', ', $sectionsNoms)) : '' ?> ·
    <?= count($actifs) ?> destinataire(s)<?= $c['envoyes'] ? ' · ' . (int) $c['envoyes'] . ' envoyé(s)' : '' ?><?= $c['echecs'] ? ' · <span class="texte-erreur">' . (int) $c['echecs'] . ' échec(s)</span>' : '' ?>
    <?= $c['valide_par'] ? ' · validé par ' . e($c['valide_par']) . ' le ' . e(dfr($c['valide_le'])) : '' ?></p>

<?php if ($brouillon): ?>
<form class="fiche" method="post" action="<?= e(url('/mailing/' . $c['id'] . '/modifier')) ?>">
    <?= csrf_field() ?>
    <?php if (!$sms): ?><?= champ('Objet', 'objet', $c['objet'], ['required' => true, 'maxlength' => 200]) ?><?php endif; ?>
    <?= champ('Message', 'texte', $c['texte'], ['type' => 'textarea', 'rows' => $sms ? 4 : 10, 'required' => true, 'hint' => '{prenom} et {nom} sont remplacés pour chaque destinataire.']) ?>
    <?php if ($sms): ?><p class="aide" id="compteur-sms" aria-live="polite"></p><?php endif; ?>
    <button type="submit" class="btn">Enregistrer le message</button>
</form>
<?php endif; ?>

<section class="fiche">
    <h2>Aperçu<?= $actifs ? ' pour ' . e(trim($actifs[0]['prenom'] . ' ' . $actifs[0]['nom'])) : '' ?></h2>
    <?php if (!$sms): ?><p><strong>Objet :</strong> <?= e($c['objet']) ?><br><strong>De :</strong> <?= e(($reglages['association'] ?: 'ASCS') . ' <' . (Ascs\Documents\Reglages::compteSmtp('mailing') ?? ($reglages['expediteur'] ?: 'adresse d\'envoi à renseigner')) . '>') ?></p><?php endif; ?>
    <pre class="apercu-message"><?= e($apercu) ?></pre>
    <?php if ($sms): ?><p class="aide"><?= mb_strlen($apercu) ?> caractères, <?= Sms::parties($apercu) ?> SMS par destinataire.</p><?php endif; ?>
</section>

<section class="fiche" id="test">
    <h2>Envoyer un test</h2>
    <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/test')) ?>" class="ligne-test">
        <?= csrf_field() ?>
        <?= champ($sms ? 'Numéros de test' : 'Adresses de test', 'test', $test, ['maxlength' => 400, 'hint' => ($sms ? 'Portables' : 'Mails') . ' séparés par des virgules (5 au plus), gardés pour les prochains tests. Le message part tel quel, préfixé [TEST], personnalisé avec le premier destinataire ; les destinataires de l\'envoi ne reçoivent rien.']) ?>
        <button type="submit" class="btn">Envoyer le test</button>
    </form>
    <?php foreach ($liensTest as $lt): ?><p><a class="btn btn-principal" href="<?= e($lt) ?>">Ouvrir Messages pour le SMS de test (<?= e(substr($lt, 4, strpos($lt, '?') - 4)) ?>)</a></p><?php endforeach; ?>
</section>

<?php if ($brouillon): ?>
<div class="actions">
    <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/valider')) ?>" data-confirmer="<?= $sms ? 'Valider ce SMS pour ' . count($actifs) . ' destinataire(s) ?' : 'Envoyer ce mail à ' . count($actifs) . ' destinataire(s) ?' ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-principal"<?= $actifs ? '' : ' disabled' ?>><?= icon('check') ?> <?= $sms ? 'Valider le SMS pour ' . count($actifs) . ' numéro(s)' : 'Valider et envoyer à ' . count($actifs) . ' adresse(s)' ?></button>
    </form>
    <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/supprimer')) ?>" data-confirmer="Supprimer ce brouillon ?">
        <?= csrf_field() ?><button type="submit" class="btn btn-danger">Supprimer le brouillon</button>
    </form>
</div>
<?php elseif ($aEnvoyer && (!$sms || $smsPret)): ?>
<form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/lot')) ?>" class="actions">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-principal">Envoyer le lot suivant (<?= min(count($aEnvoyer), $sms ? Mailing::LOT_SMS : Mailing::LOT_MAIL) ?> sur <?= count($aEnvoyer) ?> restants)</button>
    <span class="aide">Les envois partent par lots pour respecter les limites du serveur ; la tâche automatique s'en charge aussi toutes les 5 minutes.</span>
</form>
<?php elseif ($aEnvoyer && $sms): ?>
<section class="fiche" id="a-envoyer">
    <h2>Envoyer depuis le téléphone de l'association</h2>
    <p>Ouvrez cette page sur le téléphone. Chaque bouton ouvre l'application Messages avec les numéros et le texte ; appuyez sur Envoyer, revenez ici et cochez « envoyé ».
        <strong>Désactivez les messages de groupe (MMS / RCS) dans les réglages de Messages</strong> : sinon les destinataires d'un paquet verraient les numéros des autres. Dans le doute, envoyez un par un.</p>
    <?php foreach (array_chunk($aEnvoyer, 10) as $k => $paquet): $nums = array_map(static fn ($d) => $d['adresse'], $paquet); ?>
        <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/marquer')) ?>" class="paquet-sms">
            <?= csrf_field() ?>
            <?php foreach ($paquet as $d): ?><input type="hidden" name="ids[]" value="<?= (int) $d['id'] ?>"><?php endforeach; ?>
            <a class="btn btn-principal" data-sms-numeros="<?= e(implode(',', $nums)) ?>" data-sms-texte="<?= e((string) $c['texte']) ?>" href="sms:<?= e(implode(',', $nums)) ?>?body=<?= e(rawurlencode(str_replace(" ,", ",", strtr((string) $c['texte'], ['{prenom}' => '', '{nom}' => ''])))) ?>">Ouvrir Messages (<?= $k * 10 + 1 ?> à <?= $k * 10 + count($paquet) ?>)</a>
            <button type="submit" class="btn">Paquet envoyé</button>
            <small class="aide"><?= e(implode(', ', array_map(static fn ($d) => trim($d['prenom'] . ' ' . $d['nom']), $paquet))) ?></small>
        </form>
    <?php endforeach; ?>
    <?php if (str_contains((string) $c['texte'], '{prenom}') || str_contains((string) $c['texte'], '{nom}')): ?>
        <p class="aide">Envoi par paquet : un même texte pour tous, {prenom} et {nom} sont retirés. Pour un texte personnalisé, utilisez l'envoi un par un ci-dessous.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<h2>Destinataires <small class="aide"><?= count($actifs) ?> sur <?= count($dest) ?></small></h2>
<div class="table-cadre"><table class="table-dest">
    <thead><tr><th class="num">N°</th><th>Nom</th><th><?= $sms ? 'Portable' : 'Mail' ?></th><th>État</th><th><span class="visuellement-cache">Action</span></th></tr></thead>
    <tbody>
    <?php $rang = 0; foreach ($dest as $d): $txt = Mailing::personnaliser((string) $c['texte'], $d, 'sms'); ?>
        <tr id="d<?= (int) $d['id'] ?>"<?= $d['statut'] === 'retire' ? ' class="retire"' : '' ?>>
            <td class="num"><small><?= $d['statut'] === 'retire' ? '—' : ++$rang ?></small></td>
            <td><?= $d['adherent_id'] ? '<a href="' . e(url('/adherents/' . $d['adherent_id'])) . '">' . e(trim($d['nom'] . ' ' . $d['prenom'])) . '</a>' : e(trim($d['nom'] . ' ' . $d['prenom'])) ?></td>
            <td><?= e($d['adresse']) ?></td>
            <td><?= match ($d['statut']) {
                'envoye' => '<span class="pastille pastille-ok">envoyé</span>',
                'echec' => '<span class="pastille pastille-reste">échec</span> <small class="texte-erreur">' . e((string) $d['erreur']) . '</small>',
                'retire' => '<span class="aide">retiré</span>',
                default => '<span class="aide">à envoyer</span>',
            } ?></td>
            <td>
                <?php if ($brouillon): ?>
                    <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/destinataire/' . $d['id'])) ?>"><?= csrf_field() ?><button class="btn btn-petit" type="submit"><?= $d['statut'] === 'retire' ? 'Remettre' : 'Retirer' ?></button></form>
                <?php elseif ($sms && !$smsPret && $d['statut'] === 'a_envoyer'): ?>
                    <form method="post" action="<?= e(url('/mailing/' . $c['id'] . '/marquer')) ?>" class="envoi-un">
                        <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= (int) $d['id'] ?>">
                        <a class="btn btn-petit" href="sms:<?= e($d['adresse']) ?>?&amp;body=<?= e(rawurlencode($txt)) ?>">Ouvrir Messages</a>
                        <button class="btn btn-petit" type="submit">Envoyé</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
