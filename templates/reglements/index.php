<div class="titre-page"><h1>Règlements <small><?= (int) $total ?> · <?= e(money($somme)) ?></small></h1></div>
<form class="filtres" method="get" action="<?= e(url('/reglements')) ?>" role="search">
    <div class="champ"><label for="q">Nom, prénom ou référence</label><input type="search" id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="champ"><label for="mode">Moyen</label>
        <select id="mode" name="mode"><option value="">Tous</option>
        <?php foreach ($modes as $m): ?><option value="<?= (int) $m['id'] ?>"<?= attr($mode === (int) $m['id'], 'selected') ?>><?= e($m['libelle']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="champ"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= e($du) ?>"></div>
    <div class="champ"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= e($au) ?>"></div>
    <button class="btn" type="submit"><?= icon('search') ?> Filtrer</button>
</form>
<?php if (!$lignes): ?><p class="vide">Aucun règlement ne correspond pour la saison choisie.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Adhérent</th><th>Section</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <td><a href="<?= e(url('/reglements/' . $l['id'])) ?>"><?= e(dfr($l['date_reglement']) ?: '—') ?></a></td>
            <td><a href="<?= e(url('/adherents/' . $l['adherent_id'])) ?>"><?= e(trim(($l['nom'] ?? '') . ' ' . ($l['prenom'] ?? ''))) ?></a></td>
            <td><?= e($l['section']) ?></td><td><?= e($l['mode']) ?></td><td><?= e($l['identifiant']) ?></td>
            <td class="num"><?= e(money($l['montant'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?= pagination('/reglements', ['q' => $q, 'mode' => $mode, 'du' => $du, 'au' => $au], $page, $total, $parPage) ?>
<?php endif; ?>
