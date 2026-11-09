<?php /** @var array $periodes @var array $comptes @var string $defaut @var bool $xlsx @var ?array $apercu */
$choisis = $choisis ?? [];
$du = $du ?? '';
$au = $au ?? '';
?>
<div class="titre-page"><h1>Export pour le comptable</h1></div>
<p class="contexte">Un classeur Excel par période, prêt à transmettre : synthèse avec les soldes d'ouverture et de clôture de chaque compte, journal de banque, ventilation détaillée, totaux par catégorie et par section, et la liste des opérations encore à justifier. Les périodes se calculent sur la date des opérations.</p>

<form method="get" action="<?= e(url('/banque/export')) ?>" class="export-compta">
    <fieldset>
        <legend>Période</legend>
        <div class="filtres">
            <div class="champ"><label for="periode">Exercice</label><select id="periode" name="periode">
                <?php foreach ($periodes as $pp): ?><option value="<?= e($pp['cle']) ?>"<?= attr($pp['cle'] === $defaut, 'selected') ?>><?= e($pp['libelle']) ?> (<?= e(dfr($pp['du'])) ?> – <?= e(dfr($pp['au'])) ?>)</option><?php endforeach; ?>
                <option value="dates"<?= attr($defaut === 'dates', 'selected') ?>>Autres dates…</option>
            </select></div>
            <div class="champ"><label for="du">Du (autres dates)</label><input id="du" name="du" value="<?= e($du) ?>" placeholder="01/09/2025" inputmode="numeric"></div>
            <div class="champ"><label for="au">Au</label><input id="au" name="au" value="<?= e($au) ?>" placeholder="31/08/2026" inputmode="numeric"></div>
        </div>
    </fieldset>
    <fieldset>
        <legend>Comptes</legend>
        <?php foreach ($comptes as $c): ?>
            <label class="choix-case"><input type="checkbox" name="comptes[]" value="<?= e($c['numero']) ?>"<?= attr(!$choisis || in_array($c['numero'], $choisis, true), 'checked') ?>> <?= e($c['nom'] ?: 'Compte ' . $c['numero']) ?> <small class="aide"><?= e($c['numero']) ?></small></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="actions">
        <?php if ($xlsx): ?><button class="btn btn-principal" type="submit" name="format" value="xlsx"><?= icon('download') ?> Télécharger le classeur Excel</button><?php endif; ?>
        <button class="btn" type="submit" name="format" value="apercu"><?= icon('search') ?> Voir la synthèse</button>
        <button class="btn" type="submit" name="format" value="csv"><?= icon('download') ?> Ventilation seule (CSV)</button>
    </div>
</form>

<?php if ($apercu): $d = $apercu; ?>
<h2>Synthèse · <?= e($d['periode']['libelle']) ?> <small class="aide">du <?= e(dfr($d['periode']['du'])) ?> au <?= e(dfr($d['periode']['au'])) ?></small></h2>
<div class="table-cadre"><table>
    <thead><tr><th>Compte</th><th class="num">Ouverture</th><th class="num">Recettes</th><th class="num">Dépenses</th><th class="num">Clôture</th><th class="num">Opérations</th></tr></thead>
    <tbody>
    <?php foreach ($d['comptes'] as $c): ?>
        <tr><td><?= e($c['nom']) ?><?php if ($c['ecart'] !== null): ?><br><small class="texte-erreur">écart de <?= e(money($c['ecart'])) ?> avec le dernier solde relevé : une opération manque probablement</small><?php endif; ?></td>
            <td class="num"><?= $c['ouverture'] !== null ? e(money($c['ouverture'])) : '—' ?></td><td class="num"><?= e(money($c['recettes'])) ?></td><td class="num"><?= e(money($c['depenses'])) ?></td>
            <td class="num"><strong><?= $c['cloture'] !== null ? e(money($c['cloture'])) : '—' ?></strong></td><td class="num"><?= (int) $c['nb'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php $tr = array_sum(array_column($d['categories'], 'recettes')); $td = array_sum(array_column($d['categories'], 'depenses')); ?>
<h3>Résultat par catégorie <small class="aide">hors virements entre comptes</small></h3>
<div class="table-cadre"><table>
    <thead><tr><th>Catégorie</th><th class="num">Recettes</th><th class="num">Dépenses</th><th class="num">Solde</th></tr></thead>
    <tbody>
    <?php foreach ($d['categories'] as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= $v['recettes'] ? e(money($v['recettes'])) : '' ?></td><td class="num"><?= $v['depenses'] ? e(money($v['depenses'])) : '' ?></td><td class="num"><?= e(money($v['recettes'] - $v['depenses'])) ?></td></tr><?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Résultat</th><th class="num"><?= e(money($tr)) ?></th><th class="num"><?= e(money($td)) ?></th><th class="num"><?= e(money($tr - $td)) ?></th></tr></tfoot>
</table></div>
<?php if ($d['a_justifier']): ?>
<h3>À justifier avant l'envoi <small class="aide">(<?= count($d['a_justifier']) ?>)</small></h3>
<div class="table-cadre"><table><thead><tr><th>Date</th><th>Libellé</th><th class="num">Montant</th><th class="num">Reste à ventiler</th></tr></thead><tbody>
<?php foreach ($d['a_justifier'] as $r): ?><tr><td><?= e(dfr($r['date'])) ?></td><td><a href="<?= e(url('/banque/ligne/' . $r['id'])) ?>"><?= e(mb_strimwidth($r['libelle'], 0, 70, '…')) ?></a></td><td class="num"><?= e(money($r['valeur'])) ?></td><td class="num"><?= e(money($r['reste'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?><p class="aide">Toutes les opérations de la période sont ventilées.</p><?php endif; ?>
<?php endif; ?>
