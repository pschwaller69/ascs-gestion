<?php /** @var array $periodes @var array $comptes @var string $defaut @var bool $xlsx @var ?array $apercu */
$choisis = $choisis ?? [];
$du = $du ?? '';
$au = $au ?? '';
?>
<div class="titre-page"><h1>Export pour le comptable</h1></div>
<p class="contexte"><strong>Résultat par section</strong> : le classeur que générait WinDev (une feuille par section : banque, règlements des inscriptions, adhésion et frais de dossier de chaque inscription), avec une synthèse et la comparaison avec la saison précédente.</p>
<p class="contexte">Un classeur Excel par période, prêt à transmettre : synthèse avec les soldes d'ouverture et de clôture de chaque compte, journal de banque, ventilation détaillée, totaux par catégorie et par section, et la liste des opérations encore à justifier. Les périodes se calculent sur la date des opérations.</p>

<form method="get" action="<?= e(url('/banque/export')) ?>" class="export-compta">
    <fieldset>
        <legend>Période</legend>
        <div class="filtres">
            <div class="champ"><label for="periode">Exercice</label><select id="periode" name="periode">
                <?php foreach ($periodes as $pp): ?><option value="<?= e($pp['cle']) ?>"<?= attr($pp['cle'] === $defaut, 'selected') ?>><?= e($pp['libelle']) ?> (<?= e(dfr($pp['du'])) ?> – <?= e(dfr($pp['au'])) ?>)</option><?php endforeach; ?>
                <option value="dates"<?= attr($defaut === 'dates', 'selected') ?>>Autres dates…</option>
            </select></div>
            <div class="champ"><label for="du">Du (autres dates)</label><input id="du" name="du" value="<?= e($du) ?>" placeholder="01/09/2025" inputmode="numeric"></div>
            <div class="champ"><label for="au">Au</label><input id="au" name="au" value="<?= e($au) ?>" placeholder="31/08/2026" inputmode="numeric"></div>
        </div>
    </fieldset>
    <fieldset>
        <legend>Comptes</legend>
        <?php foreach ($comptes as $c): ?>
            <label class="choix-case"><input type="checkbox" name="comptes[]" value="<?= e($c['numero']) ?>"<?= attr(!$choisis || in_array($c['numero'], $choisis, true), 'checked') ?>> <?= e($c['nom'] ?: 'Compte ' . $c['numero']) ?> <small class="aide"><?= e($c['numero']) ?></small></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="actions">
        <?php if ($xlsx): ?><button class="btn btn-principal" type="submit" name="format" value="xlsx"><?= icon('download') ?> Télécharger le classeur Excel</button>
        <button class="btn btn-principal" type="submit" name="format" value="sections"><?= icon('download') ?> Résultat par section (comme WinDev)</button><?php endif; ?>
        <button class="btn" type="submit" name="format" value="apercu"><?= icon('search') ?> Voir la synthèse</button>
        <button class="btn" type="submit" name="format" value="csv"><?= icon('download') ?> Ventilation seule (CSV)</button>
    </div>
</form>

<?php if ($apercu): $d = $apercu; ?>
<h2>Synthèse · <?= e($d['periode']['libelle']) ?> <small class="aide">du <?= e(dfr($d['periode']['du'])) ?> au <?= e(dfr($d['periode']['au'])) ?></small></h2>
<div class="table-cadre"><table>
    <thead><tr><th>Compte</th><th class="num">Ouverture</th><th class="num">Recettes</th><th class="num">Dépenses</th><th class="num">Clôture</th><th class="num">Opérations</th></tr></thead>
    <tbody>
    <?php foreach ($d['comptes'] as $c): ?>
        <tr><td><?= e($c['nom']) ?><?php if ($c['ecart'] !== null): ?><br><small class="texte-erreur">écart de <?= e(money($c['ecart'])) ?> avec le dernier solde relevé : une opération manque probablement</small><?php endif; ?></td>
            <td class="num"><?= $c['ouverture'] !== null ? e(money($c['ouverture'])) : '—' ?></td><td class="num"><?= e(money($c['recettes'])) ?></td><td class="num"><?= e(money($c['depenses'])) ?></td>
            <td class="num"><strong><?= $c['cloture'] !== null ? e(money($c['cloture'])) : '—' ?></strong></td><td class="num"><?= (int) $c['nb'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php $tr = array_sum(array_column($d['categories'], 'recettes')); $td = array_sum(array_column($d['categories'], 'depenses')); ?>
<h3>Résultat par catégorie <small class="aide">hors virements entre comptes</small></h3>
<div class="table-cadre"><table>
    <thead><tr><th>Catégorie</th><th class="num">Recettes</th><th class="num">Dépenses</th><th class="num">Solde</th></tr></thead>
    <tbody>
    <?php foreach ($d['categories'] as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= $v['recettes'] ? e(money($v['recettes'])) : '' ?></td><td class="num"><?= $v['depenses'] ? e(money($v['depenses'])) : '' ?></td><td class="num"><?= e(money($v['recettes'] - $v['depenses'])) ?></td></tr><?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Résultat</th><th class="num"><?= e(money($tr)) ?></th><th class="num"><?= e(money($td)) ?></th><th class="num"><?= e(money($tr - $td)) ?></th></tr></tfoot>
</table></div>
<?php if ($d['a_justifier']): ?>
<h3>À justifier avant l'envoi <small class="aide">(<?= count($d['a_justifier']) ?>)</small></h3>
<div class="table-cadre"><table><thead><tr><th>Date</th><th>Libellé</th><th class="num">Montant</th><th class="num">Reste à ventiler</th></tr></thead><tbody>
<?php foreach ($d['a_justifier'] as $r): ?><tr><td><?= e(dfr($r['date'])) ?></td><td><a href="<?= e(url('/banque/ligne/' . $r['id'])) ?>"><?= e(mb_strimwidth($r['libelle'], 0, 70, '…')) ?></a></td><td class="num"><?= e(money($r['valeur'])) ?></td><td class="num"><?= e(money($r['reste'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?><p class="aide">Toutes les opérations de la période sont ventilées.</p><?php endif; ?>

<?php if (!empty($sections)): [$rs, $saisonTitre] = $sections; $fr = $rs['frais']; ?>
<section id="sections" class="fiche">
<h2>Résultat par section · saison <?= e($saisonTitre) ?></h2>
<form method="post" action="<?= e(url('/banque/export/frais')) ?>" class="filtres">
    <?= csrf_field() ?>
    <input type="hidden" name="saison_id" value="<?= (int) $rs['saison_id'] ?>"><input type="hidden" name="periode" value="<?= e($d['periode']['cle']) ?>">
    <div class="champ"><label for="fa">Adhésion</label><input id="fa" name="adhesion" inputmode="decimal" value="<?= e(number_format($fr['adhesion'], 2, ',', '')) ?>"></div>
    <div class="champ"><label for="fr">Frais de dossier Rillieux</label><input id="fr" name="rillieux" inputmode="decimal" value="<?= e(number_format($fr['rillieux'], 2, ',', '')) ?>"></div>
    <div class="champ"><label for="fe">Frais de dossier extérieurs</label><input id="fe" name="exterieur" inputmode="decimal" value="<?= e(number_format($fr['exterieur'], 2, ',', '')) ?>"></div>
    <button class="btn" type="submit">Enregistrer pour <?= e($saisonTitre) ?></button>
</form>
<p class="aide">Retenus sur chaque inscription payante (hors cours d'essai et inscriptions à 0 €) et reversés au fonctionnement<?= $fr['regle'] ? '' : ' — valeurs par défaut, enregistrez-les pour les confirmer' ?>.</p>
<div class="table-cadre"><table>
    <thead><tr><th>Section</th><th class="num">Inscriptions</th><th class="num">Dépenses</th><th class="num">Recettes</th><th class="num">dont espèces</th><th class="num">Adhésions + frais</th><th class="num">Résultat</th></tr></thead>
    <tbody>
    <?php foreach ($rs['sections'] as $s): ?>
        <tr><td><?= e($s['titre']) ?></td><td class="num"><?= count($s['frais']) ?></td><td class="num"><?= e(money($s['depenses'])) ?></td><td class="num"><?= e(money($s['recettes'])) ?></td>
            <td class="num"><?= $s['especes'] ? e(money($s['especes'])) : '' ?></td><td class="num"><?= e(money(($s['fonctionnement'] ? 1 : -1) * ($s['fonctionnement'] ? array_sum(array_column($rs['sections'], 'adhesion')) + array_sum(array_column($rs['sections'], 'dossier')) : $s['adhesion'] + $s['dossier']))) ?></td>
            <td class="num"><strong class="<?= $s['resultat'] < 0 ? 'texte-erreur' : '' ?>"><?= e(money($s['resultat'])) ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
    <?php $na = $rs['non_affecte']; ?>
    <tfoot>
        <tr><td>Sans section (catégorie seule)</td><td></td><td class="num"><?= e(money($na['depenses'])) ?></td><td class="num"><?= e(money($na['recettes'])) ?></td><td></td><td></td><td class="num"><?= e(money($na['recettes'] - $na['depenses'])) ?></td></tr>
        <tr><th>Résultat de la saison</th><th></th><th></th><th></th><th></th><th></th><th class="num"><?= e(money(array_sum(array_column($rs['sections'], 'resultat')) + $na['recettes'] - $na['depenses'])) ?></th></tr>
    </tfoot>
</table></div>
</section>
<?php endif; ?>
<?php endif; ?>
