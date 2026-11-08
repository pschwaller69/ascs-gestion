<?php /** @var array $comptes @var string $numero @var string $filtre @var string $q @var bool $toutes @var array $lignes */
use Ascs\Domain\Banque;
$courant = null;
foreach ($comptes as $c) { if ($c['numero'] === $numero) { $courant = $c; } }
$totC = 0.0; $totD = 0.0; $nbV = 0;
foreach ($lignes as $l) { (float) $l['valeur'] > 0 ? $totC += (float) $l['valeur'] : $totD += (float) $l['valeur']; $nbV += (int) Banque::aVentiler($l); }
?>
<div class="titre-page">
    <h1>Compte bancaire</h1>
    <div class="actions-titre">
        <a class="btn btn-principal" href="<?= e(url('/banque/import')) ?>"><?= icon('upload') ?> Importer un relevé</a>
        <a class="btn" href="<?= e(url('/banque/export')) ?>"><?= icon('download') ?> Export Excel</a>
    </div>
</div>
<?php if ($app->transition()): ?><p class="contexte">Mode transition : les relevés et la ventilation restent dans WinDev ; cette page montre les comptes importés de WinDev. L'import d'un relevé peut être essayé ici (aperçu sans enregistrement).</p><?php endif; ?>

<nav class="sous-onglets" aria-label="Comptes">
    <?php foreach ($comptes as $c): ?>
        <a href="<?= e(url('/banque', ['compte' => $c['numero']])) ?>"<?= $c['numero'] === $numero ? ' aria-current="page"' : '' ?>><?= e($c['nom'] ?: 'Compte ' . $c['numero']) ?> <small><?= $c['solde'] !== null ? e(money($c['solde'])) : '' ?></small></a>
    <?php endforeach; ?>
</nav>
<?php if ($courant): ?>
<details class="nom-compte"><summary>Nommer ce compte</summary>
    <form method="post" action="<?= e(url('/banque/nom')) ?>" class="filtres">
        <?= csrf_field() ?><input type="hidden" name="numero" value="<?= e($numero) ?>">
        <div class="champ"><label for="nom-compte">Nom du compte <?= e($numero) ?></label><input id="nom-compte" name="nom" value="<?= e($courant['nom']) ?>" maxlength="60" placeholder="Compte courant, Livret A…"></div>
        <button class="btn" type="submit">Enregistrer</button>
    </form>
</details>
<p class="contexte">Solde au <?= e(dfr($courant['derniere'])) ?> : <strong><?= $courant['solde'] !== null ? e(money($courant['solde'])) : '—' ?></strong> · <?= (int) $courant['lignes'] ?> opérations en tout.</p>
<?php endif; ?>

<form class="filtres" method="get" action="<?= e(url('/banque')) ?>">
    <input type="hidden" name="compte" value="<?= e($numero) ?>">
    <div class="champ"><label for="q">Rechercher</label><input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Libellé ou observation"></div>
    <div class="champ"><label for="filtre">Afficher</label><select id="filtre" name="filtre">
        <?php foreach (['' => 'Toutes les opérations', 'a_ventiler' => 'À ventiler', 'credit' => 'Recettes', 'debit' => 'Dépenses'] as $k => $lib): ?><option value="<?= e($k) ?>"<?= attr($filtre === $k, 'selected') ?>><?= e($lib) ?></option><?php endforeach; ?>
    </select></div>
    <label class="choix-case"><input type="checkbox" name="toutes" value="1"<?= attr($toutes, 'checked') ?>> Toutes les saisons</label>
    <button class="btn" type="submit"><?= icon('search') ?> Filtrer</button>
</form>
<p class="aide"><?= count($lignes) ?> opération(s)<?= $toutes ? '' : ' de la saison choisie en haut de page' ?> · recettes <?= e(money($totC)) ?> · dépenses <?= e(money(-$totD)) ?><?= $nbV ? ' · <strong>' . $nbV . ' à ventiler</strong>' : '' ?></p>
<?php if (!$lignes): ?><p class="vide">Aucune opération.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Libellé</th><th class="num">Montant</th><th class="num">Solde</th><th>Ventilation</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): $av = Banque::aVentiler($l); ?>
        <tr>
            <td><?= e(dfr($l['date_operation'])) ?></td>
            <td><a href="<?= e(url('/banque/ligne/' . $l['id'])) ?>"><?= e(mb_strimwidth((string) $l['libelle'], 0, 70, '…')) ?></a><?= $l['observation'] ? '<br><small class="aide">' . e($l['observation']) . '</small>' : '' ?></td>
            <td class="num<?= (float) $l['valeur'] < 0 ? '' : ' texte-ok' ?>"><?= e(money($l['valeur'])) ?></td>
            <td class="num"><small><?= $l['solde'] !== null ? e(money($l['solde'])) : '' ?></small></td>
            <td><?php if ((int) $l['virement_interne']): ?><span class="aide">virement interne</span><?php elseif ($av): ?><span class="pastille pastille-reste"><?= (int) $l['nb_details'] ? 'écart ' . e(money((float) $l['valeur'] - (float) $l['ventile'])) : 'à ventiler' ?></span><?php else: ?><span class="pastille pastille-ok"><?= (int) $l['nb_details'] ?> ligne<?= $l['nb_details'] > 1 ? 's' : '' ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
