<div class="titre-page">
    <h1>Inscription <small>n° <?= (int) $i['id'] ?></small></h1>
    <a class="btn btn-principal" href="<?= e(url('/reglements/nouveau', ['inscription' => $i['id']])) ?>"><?= icon('wallet') ?> Nouveau règlement</a>
</div>
<p class="contexte">Adhérent : <a href="<?= e(url('/adherents/' . $i['adherent_id'])) ?>"><strong><?= e(trim(($i['nom'] ?? '') . ' ' . ($i['prenom'] ?? ''))) ?></strong></a>
    · <?= e($i['section']) ?> · saison <?= e($i['saison']) ?> · <?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?></p>
<?php $action = url('/inscriptions/' . $i['id']); $adherentId = (int) $i['adherent_id']; $edition = true; require __DIR__ . '/_form.php'; ?>
<h2>Règlements</h2>
<?php if (!$reglements): ?><p class="vide">Aucun règlement enregistré.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr></thead>
    <tbody>
    <?php foreach ($reglements as $r): ?>
        <tr>
            <td><a href="<?= e(url('/reglements/' . $r['id'])) ?>"><?= e(dfr($r['date_reglement']) ?: '—') ?></a></td>
            <td><?= e($r['mode']) ?></td><td><?= e($r['identifiant']) ?></td><td class="num"><?= e(money($r['montant'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="3">Total réglé</th><th class="num"><?= e(money($i['regle'])) ?></th></tr></tfoot>
</table></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/inscriptions/' . $i['id'] . '/supprimer')) ?>" class="zone-danger">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger">Supprimer l'inscription…</button>
</form>
