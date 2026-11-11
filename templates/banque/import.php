<?php /** @var array $comptes @var ?array $apercu @var string $numero @var string $format @var array $erreurs */
$nouvelles = $apercu ? array_filter($apercu, static fn ($l) => !$l['deja']) : [];
$auto = array_filter($nouvelles, static fn ($l) => $l['proposition']);
?>
<div class="titre-page"><h1>Importer un relevé</h1><a class="btn" href="<?= e(url('/banque')) ?>">Compte bancaire</a></div>
<p class="contexte">Fichier exporté depuis la banque : CSV (comme pour WinDev : date ; date de valeur ; débit ; crédit ; libellé ; solde, ou CSV avec une ligne de titres) ou OFX.
    Les opérations déjà présentes sont reconnues et ignorées ; un relevé peut donc être importé plusieurs fois sans doublon.</p>
<form class="fiche" method="post" action="<?= e(url('/banque/import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="grille-champs">
        <div class="champ"><label for="numero">Compte</label><select id="numero" name="numero">
            <?php foreach ($comptes as $c): ?><option value="<?= e($c['numero']) ?>"<?= attr($c['numero'] === $numero, 'selected') ?>><?= e(($c['nom'] ? $c['nom'] . ' – ' : '') . $c['numero']) ?></option><?php endforeach; ?>
            <option value="">Autre compte…</option>
        </select></div>
        <?= champ('Numéro, si autre compte', 'numero_autre', '', ['inputmode' => 'numeric', 'maxlength' => 20]) ?>
        <div class="champ"><label for="releve">Fichier du relevé</label><input type="file" id="releve" name="releve" accept=".csv,.txt,.ofx,text/csv" required></div>
    </div>
    <button type="submit" class="btn btn-principal"><?= icon('upload') ?> Lire le fichier</button>
    <span class="aide">Rien n'est enregistré à cette étape.</span>
</form>

<?php if ($apercu !== null): ?>
<h2>Aperçu : compte <?= e($numero) ?></h2>
<p><?= count($apercu) ?> opération(s) lue(s) (<?= e($format) ?>) · <strong><?= count($nouvelles) ?> nouvelle(s)</strong>, dont <?= count($auto) ?> ventilée(s) automatiquement · <?= count($apercu) - count($nouvelles) ?> déjà présente(s).</p>
<?php foreach ($erreurs as $er): ?><p class="aide"><?= e($er) ?></p><?php endforeach; ?>
<?php if ($nouvelles): ?>
<form method="post" action="<?= e(url('/banque/import/confirmer')) ?>" class="actions">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-principal"><?= icon('check') ?> Importer les <?= count($nouvelles) ?> opération(s)</button>
    <?php if ($app->transition()): ?><span class="aide">Mode essai : les lignes importées servent à tester et disparaîtront au prochain import WinDev.</span><?php endif; ?>
</form>
<?php endif; ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Libellé</th><th class="num">Montant</th><th class="num">Solde</th><th>Ventilation proposée</th></tr></thead>
    <tbody>
    <?php foreach ($apercu as $l): ?>
        <tr<?= $l['deja'] ? ' class="a-venir"' : '' ?>>
            <td><?= e(dfr($l['date'])) ?></td>
            <td><?= e(mb_strimwidth((string) $l['libelle'], 0, 70, '…')) ?></td>
            <td class="num"><?= e(money($l['valeur'])) ?></td>
            <td class="num"><small><?= $l['solde'] !== null ? e(money($l['solde'])) : '' ?></small></td>
            <td><?php if ($l['deja']): ?><span class="aide">déjà présente</span>
                <?php elseif ($l['saison_id'] === null): ?><span class="texte-erreur">aucune saison pour cette date</span>
                <?php elseif ($l['proposition']): $mt = Ascs\Domain\Banque::repartir($l['proposition'], (float) $l['valeur']); ?><?= implode('<br>', array_map(static fn ($pl, $k) => e(($sections[$pl['section_principale_id']] ?? '?') . ' · ' . ($types[$pl['type_compte_id']] ?? '?') . (count($mt) > 1 ? ' · ' . money($mt[$k]) : '')), $l['proposition']['lignes'], array_keys($l['proposition']['lignes']))) ?><?= $l['proposition']['observation'] ? ' <small class="aide">' . e($l['proposition']['observation']) . '</small>' : '' ?>
                <?php elseif (str_starts_with(mb_strtoupper((string) $l['libelle']), 'REM CHQ')): ?><span class="aide">remise de chèques : rapprochée automatiquement</span>
                <?php else: ?><span class="aide">à ventiler</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
