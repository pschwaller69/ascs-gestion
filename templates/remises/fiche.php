<?php
$num = array_search((int) $r['id'], $r['soeurs'], true) + 1;
$modifiable = !in_array($r['etat_banque'], ['ventilee', 'deja_ventilee'], true);
?>
<div class="titre-page">
    <h1>Remise du <?= e(dfr($r['date_pose'])) ?><?= count($r['soeurs']) > 1 ? ' <small>' . $num . ' sur ' . count($r['soeurs']) . '</small>' : '' ?></h1>
    <a class="btn btn-principal" href="<?= e(url('/remises/' . $r['id'] . '/imprimer')) ?>" target="_blank" rel="noopener">Imprimer le contrôle</a>
</div>
<?php if (count($r['soeurs']) > 1): ?>
<nav class="sous-onglets" aria-label="Remises du même jour">
    <?php foreach ($r['soeurs'] as $i => $sid): ?><a href="<?= e(url('/remises/' . $sid)) ?>"<?= $sid === (int) $r['id'] ? ' aria-current="page"' : '' ?>>Remise <?= $i + 1 ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<p class="contexte"><strong><?= (int) $r['n'] ?> chèque<?= $r['n'] > 1 ? 's' : '' ?>, <?= e(money($r['total'])) ?></strong> · préparée le <?= e(dfr($r['cree_le'])) ?> par <?= e($r['cree_par']) ?></p>

<div class="deux-colonnes">
<section class="fiche">
    <h2>Banque</h2>
    <form method="post" action="<?= e(url('/remises/' . $r['id'])) ?>">
        <?= csrf_field() ?>
        <div class="grille">
            <?= champ('N° du bordereau de la banque', 'numero', $r['numero'], ['inputmode' => 'numeric', 'maxlength' => 12, 'readonly' => !$modifiable, 'hint' => 'Facultatif mais conseillé : le numéro imprimé sur le bordereau, repris par la banque sur le relevé (« REM CHQ N… »).']) ?>
            <?= champ('Déposée le', 'date_depot', $r['date_depot'], ['type' => 'date']) ?>
            <div class="champ"><label for="f_numero_compte">Compte bancaire</label>
                <select id="f_numero_compte" name="numero_compte">
                    <?php foreach ($comptes as $nc): ?><option value="<?= e($nc) ?>"<?= attr($nc === $r['numero_compte'], 'selected') ?>><?= e($nc) ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <button type="submit" class="btn">Enregistrer</button>
    </form>
    <h3>Rapprochement</h3>
    <?php if ($r['etat_banque'] === 'attente'): ?>
        <p>La ligne de la banque n'est pas encore dans les comptes. Elle sera reconnue <?= $r['numero'] ? 'par son numéro (« REM CHQ N' . e($r['numero']) . ' ») ou ' : '' ?>par son montant (<?= e(money($r['total'])) ?>) dans les trois semaines qui suivent le dépôt, puis ventilée automatiquement.</p>
    <?php elseif ($r['etat_banque'] === 'ecart'): ?>
        <p class="flash flash-erreur">Ligne de la banque trouvée (<?= e($r['ligne_banque']['libelle'] ?? '') ?>, <?= e(money($r['ligne_banque']['credit'] ?? 0)) ?>), mais son montant est différent du total de la remise (<?= e(money($r['total'])) ?>). Un chèque a peut-être été retiré ou ajouté : corrigez la remise. Rien n'a été ventilé.</p>
    <?php else: ?>
        <p class="flash flash-ok">Ligne de la banque du <?= e(dfr($r['ligne_banque']['date_operation'] ?? '')) ?> : <?= e($r['ligne_banque']['libelle'] ?? '') ?>, <?= e(money($r['ligne_banque']['credit'] ?? 0)) ?>.
            <?= $r['etat_banque'] === 'ventilee' ? 'Ventilée automatiquement par section principale.' : 'Elle était déjà ventilée : rien n\'a été modifié.' ?></p>
    <?php endif; ?>
</section>
<section class="fiche">
    <h2>Ventilation par section</h2>
    <table class="infos">
        <?php foreach ($ventilation as $v): ?><tr><th><?= e($v['titre']) ?> <small class="aide">(<?= (int) $v['n'] ?>)</small></th><td class="num"><?= e(money($v['total'])) ?></td></tr><?php endforeach; ?>
        <tr><th><strong>Total</strong></th><td class="num"><strong><?= e(money($r['total'])) ?></strong></td></tr>
    </table>
    <p class="aide">Écrite dans les comptes en type « Inscription », une ligne par section principale.</p>
</section>
</div>

<h2>Chèques</h2>
<div class="table-cadre"><table>
    <thead><tr><th>Adhérent</th><th>Parent</th><th>Section</th><th>N° chèque</th><th>Date</th><th class="num">Montant</th><?php if ($modifiable): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($r['cheques'] as $c): ?>
        <tr>
            <td><?= e(trim($c['nom'] . ' ' . $c['prenom'])) ?></td>
            <td><?= e(trim($c['nom_parent'] . ' ' . $c['prenom_parent'])) ?></td>
            <td><?= e($c['section']) ?></td>
            <td><?= e($c['numero_cheque']) ?></td>
            <td><?= e(dfr($c['date_reglement'])) ?></td>
            <td class="num"><?= e(money($c['montant'])) ?></td>
            <?php if ($modifiable): ?><td>
                <form method="post" action="<?= e(url('/remises/' . $r['id'] . '/retirer')) ?>"><?= csrf_field() ?><input type="hidden" name="cheque" value="<?= (int) $c['id'] ?>"><button type="submit" class="btn btn-petit">Retirer</button></form>
            </td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="5"><?= (int) $r['n'] ?> chèque<?= $r['n'] > 1 ? 's' : '' ?></th><th class="num"><?= e(money($r['total'])) ?></th><?php if ($modifiable): ?><th></th><?php endif; ?></tr></tfoot>
</table></div>
<?php if ($modifiable): ?>
<form method="post" action="<?= e(url('/remises/' . $r['id'] . '/supprimer')) ?>" class="zone-danger" data-confirmer="Supprimer cette remise ? Ses chèques redeviendront « à remettre ».">
    <?= csrf_field() ?><button type="submit" class="btn btn-danger">Supprimer la remise</button>
</form>
<?php endif; ?>
