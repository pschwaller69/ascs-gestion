<?php /** @var array $regles @var array $comptes @var array $nonVentilees @var ?array $edit @var ?string $err @var ?array $saisie @var array $sections @var array $types */
$aAppliquer = array_values(array_filter($nonVentilees, static fn ($c) => $c['regle']));
$f = $saisie ?? ($edit ? ['id' => $edit['id'], 'motif' => $edit['motif'], 'mode' => $edit['mode'], 'montant' => $edit['montant'] !== null ? number_format((float) $edit['montant'], 2, ',', '') : '',
    'libelle' => $edit['libelle'], 'actif' => (int) $edit['actif'], 'l' => $edit['lignes']] : ['id' => '', 'motif' => '', 'mode' => 'debut', 'montant' => '', 'libelle' => '', 'actif' => 1, 'l' => [['sp' => '', 'tc' => '', 'part' => 100]]]);
$lignesF = array_pad(array_values((array) $f['l']), 3, ['sp' => '', 'tc' => '', 'part' => '', 'libelle' => '']);
?>
<div class="titre-page"><h1>Règles d'affectation</h1><a class="btn" href="<?= e(url('/banque/import')) ?>"><?= icon('upload') ?> Importer un relevé</a></div>
<p class="contexte">Comme le traitement automatique de WinDev : à l'import d'un relevé, une opération dont le libellé correspond à une règle est ventilée toute seule (sections, catégories, parts). Les règles passent avant la ventilation apprise des opérations précédentes ; la première qui correspond s'applique. Les remises de chèques sont ventilées à part, d'après les remises faites dans l'application.</p>

<?php if ($aAppliquer): ?>
<section class="fiche">
    <h2>À ventiler par les règles · <?= count($aAppliquer) ?> opération(s)</h2>
    <p class="aide">Opérations de la saison choisie en haut de page, encore non ventilées, qu'une règle sait ventiler.</p>
    <ul class="liste-simple">
        <?php foreach (array_slice($aAppliquer, 0, 12) as $c): ?><li><?= e(dfr($c['date'])) ?> · <a href="<?= e(url('/banque/ligne/' . $c['id'])) ?>"><?= e(mb_strimwidth($c['libelle'], 0, 70, '…')) ?></a> · <?= e(money($c['valeur'])) ?> → <strong><?= e($c['regle']['observation']) ?></strong></li><?php endforeach; ?>
        <?php if (count($aAppliquer) > 12): ?><li>… et <?= count($aAppliquer) - 12 ?> autre(s)</li><?php endif; ?>
    </ul>
    <form method="post" action="<?= e(url('/banque/regles/appliquer')) ?>"><?= csrf_field() ?><button class="btn btn-principal" type="submit"><?= icon('check') ?> Ventiler ces <?= count($aAppliquer) ?> opération(s)</button></form>
</section>
<?php endif; ?>

<h2><?= count($regles) ?> règle(s)</h2>
<div class="table-cadre"><table>
    <thead><tr><th>Libellé de la banque</th><th>Ventilation</th><th class="num">Opérations cette saison</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($regles as $i => $r): $n = $comptes[(int) $r['id']] ?? ['toutes' => 0, 'a_ventiler' => 0]; ?>
        <tr id="r<?= (int) $r['id'] ?>"<?= $r['actif'] ? '' : ' class="attenue"' ?>>
            <td><small><?= e(Ascs\Domain\RegleBanque::MODES[$r['mode']] ?? $r['mode']) ?></small> <strong><?= e($r['motif']) ?></strong><?= $r['montant'] !== null ? ' <small>· montant ' . e(money($r['montant'])) . '</small>' : '' ?><?= $r['actif'] ? '' : ' <small>(inactive)</small>' ?></td>
            <td><?= e($r['libelle']) ?><br><small class="aide"><?= e(implode(' + ', array_map(static fn ($l) => ($sections[$l['sp']] ?? '?') . ' · ' . ($types[$l['tc']] ?? '?') . ((float) $l['part'] < 100 ? ' ' . rtrim(rtrim(number_format((float) $l['part'], 2, ',', ''), '0'), ',') . ' %' : ''), $r['lignes']))) ?></small></td>
            <td class="num"><?= (int) $n['toutes'] ?><?= $n['a_ventiler'] ? ' <small>(' . (int) $n['a_ventiler'] . ' à ventiler)</small>' : '' ?></td>
            <td class="actions-ligne">
                <a class="btn btn-petit" href="<?= e(url('/banque/regles', ['modifier' => $r['id']])) ?>#formulaire">Modifier</a>
                <form method="post" action="<?= e(url('/banque/regles/' . (int) $r['id'])) ?>"><?= csrf_field() ?>
                    <?php if ($i > 0): ?><button class="btn btn-petit" name="action" value="monter" type="submit" aria-label="Monter">↑</button><?php endif; ?>
                    <?php if ($i < count($regles) - 1): ?><button class="btn btn-petit" name="action" value="descendre" type="submit" aria-label="Descendre">↓</button><?php endif; ?>
                </form>
                <form method="post" action="<?= e(url('/banque/regles/' . (int) $r['id'])) ?>" data-confirmer="Supprimer la règle « <?= e($r['motif']) ?> » ?"><?= csrf_field() ?><button class="btn btn-petit" name="action" value="supprimer" type="submit">Supprimer</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<form method="post" action="<?= e(url('/banque/regles')) ?>" class="fiche" id="formulaire">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) $f['id']) ?>">
    <h2><?= $f['id'] ? 'Modifier la règle' : 'Nouvelle règle' ?></h2>
    <?php if ($err): ?><p class="flash flash-erreur" role="alert"><?= e($err) ?></p><?php endif; ?>
    <div class="filtres">
        <div class="champ"><label for="rm">Le libellé de la banque</label><select id="rm" name="mode"><?php foreach (Ascs\Domain\RegleBanque::MODES as $k => $v): ?><option value="<?= e($k) ?>"<?= attr($f['mode'] === $k, 'selected') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="champ champ-large"><label for="rmo">Texte</label><input type="text" id="rmo" name="motif" value="<?= e((string) $f['motif']) ?>" maxlength="150" placeholder="VIR BESSON FACTURE" required></div>
        <div class="champ"><label for="rmt">Montant exact (facultatif)</label><input type="text" id="rmt" name="montant" value="<?= e((string) $f['montant']) ?>" inputmode="decimal" placeholder="21,24"></div>
    </div>
    <div class="filtres">
        <div class="champ champ-large"><label for="rl">Libellé de la ventilation</label><input type="text" id="rl" name="libelle" value="<?= e((string) $f['libelle']) ?>" maxlength="255" placeholder="Facture Sophrologie" required></div>
        <label class="choix-case"><input type="checkbox" name="actif" value="1"<?= attr((bool) $f['actif'], 'checked') ?>> Active</label>
    </div>
    <div class="table-cadre"><table class="table-reglages">
        <thead><tr><th>Section</th><th>Catégorie</th><th class="num">Part %</th><th>Libellé de la ligne (si partagé)</th></tr></thead>
        <tbody>
        <?php foreach ($lignesF as $k => $l): ?>
            <tr>
                <td><select name="l[<?= $k ?>][sp]" aria-label="Section ligne <?= $k + 1 ?>"><option value="">—</option><?php foreach ($sections as $id => $t): ?><option value="<?= (int) $id ?>"<?= attr((string) ($l['sp'] ?? '') === (string) $id, 'selected') ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
                <td><select name="l[<?= $k ?>][tc]" aria-label="Catégorie ligne <?= $k + 1 ?>"><option value="">—</option><?php foreach ($types as $id => $t): ?><option value="<?= (int) $id ?>"<?= attr((string) ($l['tc'] ?? '') === (string) $id, 'selected') ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
                <td class="num"><input type="text" name="l[<?= $k ?>][part]" value="<?= e((string) ($l['part'] ?? '')) ?>" inputmode="decimal" size="5" aria-label="Part ligne <?= $k + 1 ?>"></td>
                <td><input type="text" name="l[<?= $k ?>][libelle]" value="<?= e((string) ($l['libelle'] ?? '')) ?>" maxlength="255" aria-label="Libellé ligne <?= $k + 1 ?>"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="aide">Une seule ligne à 100 % le plus souvent ; plusieurs lignes pour partager (50 / 50 entre la batterie et le taekwondo pour l'URSSAF, par exemple).</p>
    <div class="actions"><button class="btn btn-principal" type="submit"><?= icon('check') ?> Enregistrer la règle</button><?php if ($f['id']): ?> <a class="btn" href="<?= e(url('/banque/regles')) ?>">Annuler</a><?php endif; ?></div>
</form>
