<p class="ne-pas-imprimer actions"><button type="button" class="btn btn-principal" data-imprimer>Imprimer</button></p>
<section class="bordereau">
    <h1>Remise de chèques du <?= e(dfr($r['date_pose'])) ?><?= count($r['soeurs']) > 1 ? ' — remise ' . (array_search((int) $r['id'], $r['soeurs'], true) + 1) . ' sur ' . count($r['soeurs']) : '' ?></h1>
    <p>Bordereau n° <?= e($r['numero'] ?: '………………') ?> · Compte <?= e($r['numero_compte'] ?: '—') ?> · Déposée le <?= e(dfr($r['date_depot']) ?: '…… / …… / ……') ?></p>
    <table class="table-bordereau">
        <colgroup><col class="c-no"><col><col><col class="c-ch"><col class="c-sec"><col class="c-mt"></colgroup>
        <thead><tr><th>N°</th><th>Adhérent</th><th>Parent</th><th>N° chèque</th><th>Section</th><th class="num">Montant</th></tr></thead>
        <tbody>
        <?php foreach ($r['cheques'] as $i => $c): ?>
            <tr><td><?= $i + 1 ?></td><td><?= e(trim($c['nom'] . ' ' . $c['prenom'])) ?></td><td><?= e(trim($c['nom_parent'] . ' ' . $c['prenom_parent'])) ?></td>
                <td><?= e($c['numero_cheque']) ?></td><td><?= e($c['section']) ?></td><td class="num"><?= e(money($c['montant'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5"><?= (int) $r['n'] ?> chèque<?= $r['n'] > 1 ? 's' : '' ?></th><th class="num"><?= e(money($r['total'])) ?></th></tr></tfoot>
    </table>
    <h2>Ventilation</h2>
    <table class="table-bordereau ventilation">
        <?php foreach ($ventilation as $v): ?><tr><td><?= e($v['titre']) ?></td><td class="num"><?= (int) $v['n'] ?></td><td class="num"><?= e(money($v['total'])) ?></td></tr><?php endforeach; ?>
    </table>
</section>
