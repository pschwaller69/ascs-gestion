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
        <li><a href="<?= e(url('/adherents/' . $t['id'])) ?>"><?= e(trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? ''))) ?></a> <?= e(dfr($f['date_naissance'])) ?> — <?= e(DuplicateFinder::NIVEAUX[$t['niveau']]) ?> (<?= e(implode(', ', $t['raisons'])) ?>)</li>
    <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<nav class="sous-onglets" aria-label="Sections de la fiche">
    <?php foreach ($tabs as $k => $lib): ?>
        <a href="<?= e(url('/adherents/' . $a['id'], ['onglet' => $k])) ?>"<?= $k === $onglet_fiche ? ' aria-current="page"' : '' ?>><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($onglet_fiche === 'adherent'): ?>
    <?php $action = url('/adherents/' . $a['id']); $id = (int) $a['id']; require __DIR__ . '/_form.php'; ?>
<?php elseif ($onglet_fiche === 'inscriptions'): ?>
    <?php if (!$inscriptions): ?><p class="vide">Aucune inscription.</p><?php else: ?>
    <div class="table-cadre"><table>
        <thead><tr><th>Saison</th><th>Section</th><th>Date</th><th class="num">Montant</th><th class="num">Réglé</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($inscriptions as $i): ?>
            <tr>
                <td><?= e($i['saison']) ?></td>
                <td><a href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['section']) ?></a></td>
                <td><?= e(dfr($i['date_inscription'])) ?></td>
                <td class="num"><?= e(money($i['montant'])) ?></td>
                <td class="num"><?= e(money($i['regle'])) ?></td>
                <td><?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
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
