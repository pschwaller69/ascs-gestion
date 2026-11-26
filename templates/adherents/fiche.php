<?php use Ascs\Domain\DuplicateFinder;
$titreFiche = trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? '')) ?: '(fiche sans nom)';
$age = age_from($a['date_naissance']);
$tabs = ['adherent' => 'Adhérent', 'inscriptions' => 'Inscriptions (' . count($inscriptions) . ')', 'reglements' => 'Règlements (' . count($reglements) . ')'];
?>
<div class="titre-page">
    <h1><?= e($titreFiche) ?> <small>n° <?= (int) $a['id'] ?><?= $age !== null ? ' · ' . $age . ' ans' : '' ?></small></h1>
    <a class="btn btn-principal" href="<?= e(url('/inscriptions/nouveau', ['adherent' => $a['id']])) ?>"><?= icon('file-plus') ?> Nouvelle inscription</a>
</div>
<?php if ($proches): ?>
<details class="proches"><summary><?= count($proches) ?> fiche<?= count($proches) > 1 ? 's' : '' ?> ressemblante<?= count($proches) > 1 ? 's' : '' ?></summary>
    <ul>
    <?php foreach ($proches as $t): $f = $t['fiche']; ?>
        <li><a href="<?= e(url('/adherents/' . $t['id'])) ?>"><?= e(trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? ''))) ?></a> <?= e(dfr($f['date_naissance'])) ?> — <?= e(DuplicateFinder::NIVEAUX[$t['niveau']]) ?> (<?= e(implode(', ', $t['raisons'])) ?>)
            <a class="btn btn-petit" href="<?= e(url('/adherents/' . $a['id'] . '/fusion/' . $t['id'])) ?>">Comparer et fusionner</a></li>
    <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<?php if (!empty($reinscription['lien'])): ?>
<details class="lien-reinscription"><summary>Lien de réinscription<?= $reinscription['coupe'] ? ' (coupé)' : '' ?></summary>
    <?php if (!$reinscription['coupe']): ?>
        <p>Formulaire d'inscription déjà rempli avec cette fiche, pour la saison des inscriptions en ligne ; la demande arrive rattachée à la fiche. À envoyer par SMS ou par mail (dans un mailing : <code>{lien_reinscription}</code>).</p>
        <p><input type="text" readonly value="<?= e($reinscription['lien']) ?>" aria-label="Lien de réinscription" class="lien-copie"></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/adherents/' . $a['id'] . '/reinscription')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $reinscription['coupe'] ? 'retablir' : 'couper' ?>">
        <button type="submit" class="btn btn-petit"><?= $reinscription['coupe'] ? 'Rétablir le lien' : 'Couper le lien' ?></button>
    </form>
</details>
<?php endif; ?>
<?php require __DIR__ . '/_parcours.php'; ?>
<nav class="sous-onglets" aria-label="Sections de la fiche">
    <?php foreach ($tabs as $k => $lib): ?>
        <a href="<?= e(url('/adherents/' . $a['id'], ['onglet' => $k])) ?>"<?= $k === $onglet_fiche ? ' aria-current="page"' : '' ?>><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($onglet_fiche === 'adherent'): ?>
    <?php $action = url('/adherents/' . $a['id']); $id = (int) $a['id']; require __DIR__ . '/_form.php'; ?>
<?php elseif ($onglet_fiche === 'inscriptions'): ?>
    <?php if (!$inscriptions): ?><p class="vide">Aucune inscription.</p><?php else: ?>
    <?php foreach ($parSaison as $s => $l): $tm = array_sum(array_column($l, 'montant')); $tr = array_sum(array_column($l, 'regle')); ?>
    <h2 class="titre-saison"><?= e($s) ?> <small class="aide"><?= count($l) ?> inscription<?= count($l) > 1 ? 's' : '' ?> · <?= e(money($tm)) ?><?= $tm - $tr > 0.005 ? ' · reste ' . e(money($tm - $tr)) : '' ?></small></h2>
    <div class="table-cadre"><table>
        <thead><tr><th>Section</th><th>Date</th><th class="num">Montant</th><th class="num">Réglé</th><th>État</th><th class="num">Présences</th></tr></thead>
        <tbody>
        <?php foreach ($l as $i): ?>
            <tr>
                <td><a href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['section']) ?></a><?= $i['essai'] ? ' <small class="aide">essai</small>' : '' ?></td>
                <td><?= e(dfr($i['date_inscription'])) ?></td>
                <td class="num"><?= e(money($i['montant'])) ?></td>
                <td class="num"><?= e(money($i['regle'])) ?></td>
                <td><?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?></td>
                <td class="num"><?= $i['carte'] ? 'carte : ' . (int) $i['carte']['utilisees'] . '/' . (int) $i['carte']['achetees'] : ((int) $i['presences'] ?: '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endforeach; ?>
    <?php endif; ?>
<?php else: ?>
    <?php if (!$reglements): ?><p class="vide">Aucun règlement.</p><?php else: ?>
    <div class="table-cadre"><table>
        <thead><tr><th>Date</th><th>Saison</th><th>Section</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr></thead>
        <tbody>
        <?php foreach ($reglements as $r): ?>
            <tr>
                <td><a href="<?= e(url('/reglements/' . $r['id'])) ?>"><?= e(dfr($r['date_reglement']) ?: '—') ?></a></td>
                <td><?= e($r['saison']) ?></td>
                <td><?= e($r['section']) ?></td>
                <td><?= e($r['mode']) ?></td>
                <td><?= e($r['identifiant']) ?></td>
                <td class="num"><?= e(money($r['montant'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
<?php endif; ?>
