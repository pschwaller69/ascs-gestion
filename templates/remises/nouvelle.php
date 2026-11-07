<?php $total = array_sum(array_map(static fn ($c) => (float) $c['montant'], $cheques)); ?>
<h1>Nouvelle remise de chèques</h1>
<form method="get" action="<?= e(url('/remises/nouvelle')) ?>" class="filtres">
    <div class="champ"><label for="date">Date de pose</label>
        <select id="date" name="date" data-autosubmit>
            <?php if (!$dates): ?><option value="">Aucun chèque en attente</option><?php endif; ?>
            <?php foreach ($dates as $d): ?>
                <option value="<?= e($d['date_reglement']) ?>"<?= attr($d['date_reglement'] === $date, 'selected') ?>><?= e(dfr($d['date_reglement'])) ?> — <?= (int) $d['n'] ?> chèque<?= $d['n'] > 1 ? 's' : '' ?>, <?= e(money($d['total'])) ?></option>
            <?php endforeach; ?>
        </select></div>
    <noscript><button type="submit" class="btn">Afficher</button></noscript>
</form>

<?php if (!$cheques): ?>
    <p class="vide">Aucun chèque à remettre<?= $date ? ' pour le ' . e(dfr($date)) : '' ?>.</p>
<?php else: ?>
<form method="post" action="<?= e(url('/remises')) ?>" id="form-remise" data-max="<?= Ascs\Domain\Remises::MAX_CHEQUES ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <p class="flash flash-info" id="resume-remise" aria-live="polite">
        <strong><?= count($cheques) ?> chèques, <?= e(money($total)) ?></strong>.
        <?= count($decoupage) > 1 ? 'La banque limite une remise à 30 chèques : ils seront répartis sur <strong>' . count($decoupage) . ' remises</strong> (' . e(implode(' + ', $decoupage)) . ' chèques), avec un bordereau chacune.' : 'Une seule remise suffit.' ?>
    </p>
    <div class="table-cadre"><table>
        <thead><tr><th><input type="checkbox" id="tout" checked aria-label="Tout cocher" data-tout></th><th>Adhérent</th><th>Parent</th><th>Section</th><th>N° chèque</th><th class="num">Montant</th></tr></thead>
        <tbody>
        <?php foreach ($cheques as $c): ?>
            <tr>
                <td><input type="checkbox" name="cheques[]" value="<?= (int) $c['id'] ?>" checked data-montant="<?= e($c['montant']) ?>" aria-label="Remettre le chèque de <?= e(trim($c['nom'] . ' ' . $c['prenom'])) ?>"></td>
                <td><a href="<?= e(url('/adherents/' . $c['adherent_id'], ['onglet' => 'reglements'])) ?>"><?= e(trim($c['nom'] . ' ' . $c['prenom'])) ?></a></td>
                <td><?= e(trim($c['nom_parent'] . ' ' . $c['prenom_parent'])) ?></td>
                <td><?= e($c['section']) ?></td>
                <td><?= e($c['identifiant']) ?: '<span class="pastille pastille-reste">non renseigné</span>' ?></td>
                <td class="num"><?= e(money($c['montant'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="grille">
        <div class="champ"><label for="f_numero_compte">Compte bancaire</label>
            <select id="f_numero_compte" name="numero_compte">
                <?php foreach ($comptes as $nc): ?><option value="<?= e($nc) ?>"><?= e($nc) ?></option><?php endforeach; ?>
                <?php if (!$comptes): ?><option value="">—</option><?php endif; ?>
            </select></div>
    </div>
    <p class="aide">Décochez un chèque absent du paquet : il restera « à remettre ».</p>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Créer la remise</button></div>
</form>
<?php endif; ?>
