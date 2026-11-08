<?php /** @var array $l @var ?array $proposition @var ?string $err @var ?array $saisie @var array $sections @var array $types */
$neg = (float) $l['valeur'] < 0;
$lignes = $saisie ?? array_map(static fn ($d) => ['section_principale_id' => $d['section_principale_id'], 'type_compte_id' => $d['type_compte_id'],
    'montant' => number_format(abs((float) $d['valeur']), 2, ',', ''), 'observation' => $d['observation']], $l['details']);
if (!$lignes && $proposition) {
    foreach (Ascs\Domain\Banque::repartir($proposition, (float) $l['valeur']) as $k => $m) {
        $pl = $proposition['lignes'][$k];
        $lignes[] = ['section_principale_id' => $pl['section_principale_id'], 'type_compte_id' => $pl['type_compte_id'], 'montant' => number_format(abs($m), 2, ',', ''), 'observation' => $pl['observation']];
    }
}
$lignes = array_pad($lignes, max(3, count($lignes) + 1), ['section_principale_id' => '', 'type_compte_id' => '', 'montant' => '', 'observation' => '']);
$lecture = $app->transition();
?>
<div class="titre-page"><h1><?= $neg ? 'Dépense' : 'Recette' ?> du <?= e(dfr($l['date_operation'])) ?></h1><a class="btn" href="<?= e(url('/banque', ['compte' => $l['numero_compte']])) ?>">Compte <?= e($l['numero_compte']) ?></a></div>
<p class="contexte"><strong><?= e(money($l['valeur'])) ?></strong> · <?= e($l['libelle']) ?></p>
<?php if ($err): ?><p class="flash flash-erreur" role="alert"><?= e($err) ?></p><?php endif; ?>
<?php if ($proposition && !$l['details']): ?><p class="flash flash-info">Ventilation proposée d'après les opérations précédentes au même libellé : vérifiez puis enregistrez.</p><?php endif; ?>
<?php if ($lecture): ?><p class="contexte">Mode transition : la ventilation se modifie dans WinDev. Affichage seulement.</p><?php endif; ?>
<form class="fiche" method="post" action="<?= e(url('/banque/ligne/' . $l['id'])) ?>" id="form-ventilation" data-total="<?= e(number_format(abs((float) $l['valeur']), 2, '.', '')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="retour_filtre" value="<?= e((string) ($_GET['filtre'] ?? '')) ?>">
    <table class="table-ventilation">
        <thead><tr><th>Section principale</th><th>Catégorie</th><th class="num">Montant</th><th>Observation</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $k => $v): ?>
            <tr>
                <td><label class="visuellement-cache" for="v<?= $k ?>s">Section, ligne <?= $k + 1 ?></label><select id="v<?= $k ?>s" name="v[<?= $k ?>][section_principale_id]"<?= $lecture ? ' disabled' : '' ?>><option value="">—</option><?php foreach ($sections as $id => $t): ?><option value="<?= (int) $id ?>"<?= attr((string) $id === (string) $v['section_principale_id'], 'selected') ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
                <td><label class="visuellement-cache" for="v<?= $k ?>t">Catégorie, ligne <?= $k + 1 ?></label><select id="v<?= $k ?>t" name="v[<?= $k ?>][type_compte_id]"<?= $lecture ? ' disabled' : '' ?>><option value="">—</option><?php foreach ($types as $id => $t): ?><option value="<?= (int) $id ?>"<?= attr((string) $id === (string) $v['type_compte_id'], 'selected') ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
                <td class="num"><label class="visuellement-cache" for="v<?= $k ?>m">Montant, ligne <?= $k + 1 ?></label><input id="v<?= $k ?>m" name="v[<?= $k ?>][montant]" value="<?= e((string) $v['montant']) ?>" inputmode="decimal" size="9" data-montant-ventilation<?= $lecture ? ' disabled' : '' ?>></td>
                <td><label class="visuellement-cache" for="v<?= $k ?>o">Observation, ligne <?= $k + 1 ?></label><input id="v<?= $k ?>o" name="v[<?= $k ?>][observation]" value="<?= e((string) $v['observation']) ?>" maxlength="255"<?= $lecture ? ' disabled' : '' ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="aide" id="reste-ventilation" aria-live="polite">Montants sans signe : <?= $neg ? 'une dépense' : 'une recette' ?> de <?= e(money(abs((float) $l['valeur']))) ?> à répartir.</p>
    <?= champ('Observation de l\'opération', 'observation', (string) $l['observation'], ['maxlength' => 255, 'readonly' => $lecture]) ?>
    <?php if ($lecture): ?><p><?= $l['virement_interne'] ? 'Virement interne (ni recette ni dépense).' : '' ?></p><?php else: ?><?= coche('Virement interne (entre deux comptes de l\'association : ni recette ni dépense)', 'virement_interne', (bool) $l['virement_interne']) ?><?php endif; ?>
    <?php if (!$lecture): ?><div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer la ventilation</button></div><?php endif; ?>
</form>
<?php if ($l['ligne_import']): ?><p class="aide">Ligne du relevé : <code><?= e($l['ligne_import']) ?></code></p><?php endif; ?>
