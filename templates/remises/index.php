<?php
$etats = ['attente' => ['En attente de la banque', 'pastille-reste'], 'ventilee' => ['Ventilée', 'pastille-ok'], 'deja_ventilee' => ['Déjà ventilée', 'pastille-ok'], 'ecart' => ['Montant différent', 'pastille-excedent']];
?>
<div class="titre-page">
    <h1>Remises de chèques</h1>
    <a class="btn btn-principal" href="<?= e(url('/remises/nouvelle')) ?>"><?= icon('cheque') ?> Nouvelle remise</a>
</div>

<h2>Chèques à remettre</h2>
<?php if (!$dates): ?>
    <p class="vide">Aucun chèque en attente depuis le <?= e(dfr($depuis)) ?>.</p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date de pose</th><th class="num">Chèques</th><th class="num">Total</th><th class="num">Remises nécessaires</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($dates as $d): $k = count(Ascs\Domain\Remises::decoupage((int) $d['n'])); ?>
        <tr<?= $d['date_reglement'] > date('Y-m-d') ? ' class="a-venir"' : '' ?>>
            <td><?= e(dfr($d['date_reglement'])) ?><?= $d['date_reglement'] > date('Y-m-d') ? ' <small class="aide">à venir</small>' : '' ?></td>
            <td class="num"><?= (int) $d['n'] ?></td>
            <td class="num"><?= e(money($d['total'])) ?></td>
            <td class="num"><?= $k ?></td>
            <td><a class="btn btn-petit" href="<?= e(url('/remises/nouvelle', ['date' => $d['date_reglement']])) ?>">Préparer</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<details class="fiche">
    <summary>Chercher les chèques depuis une autre date (actuellement le <?= e(dfr($depuis)) ?>)</summary>
    <form method="post" action="<?= e(url('/remises/depuis')) ?>" class="filtres">
        <?= csrf_field() ?>
        <?= champ('Depuis le', 'depuis', $depuis, ['type' => 'date', 'hint' => 'Les chèques plus anciens sont considérés comme déjà remis (remises faites avant l\'application).']) ?>
        <button type="submit" class="btn">Appliquer</button>
    </form>
</details>

<h2>Remises</h2>
<?php if (!$liste): ?><p class="vide">Aucune remise pour l'instant.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date de pose</th><th>Bordereau</th><th>Déposée le</th><th class="num">Chèques</th><th class="num">Total</th><th>Banque</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $r): [$lib, $cls] = $etats[$r['etat_banque']] ?? [$r['etat_banque'], '']; ?>
        <tr>
            <td><a href="<?= e(url('/remises/' . $r['id'])) ?>"><?= e(dfr($r['date_pose'])) ?></a></td>
            <td><?= $r['numero'] ? 'N° ' . e($r['numero']) : '<span class="aide">à renseigner</span>' ?></td>
            <td><?= e(dfr($r['date_depot'])) ?></td>
            <td class="num"><?= (int) $r['n'] ?></td>
            <td class="num"><?= e(money($r['total'])) ?></td>
            <td><span class="pastille <?= e($cls) ?>"><?= e($lib) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
