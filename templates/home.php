<?php
/** @var array $stats @var array $tb */
$barres = static function (array $lignes, string $cle, bool $euros, string $libelle): string {
    if (!$lignes) {
        return '<p class="aide">Aucune donnée pour cette saison.</p>';
    }
    $max = max(array_map(static fn ($l) => (float) $l[$cle], $lignes)) ?: 1;
    $h = '<ul class="barres" aria-label="' . e($libelle) . '">';
    foreach ($lignes as $l) {
        $v = (float) $l[$cle];
        $txt = $euros ? money($v) : (string) (int) $v;
        $h .= '<li title="' . e($l['titre'] . ' : ' . $txt) . '"><span class="barre-lib">' . e($l['titre']) . '</span>'
            . '<svg class="barre-piste" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="barre" width="' . max(0.5, round($v / $max * 100, 1)) . '" height="10"/></svg>'
            . '<span class="barre-val">' . e($txt) . '</span></li>';
    }
    return $h . '</ul>';
};
$g = $tb['genre'];
$totalG = $g['femmes'] + $g['hommes'];
$c = $tb['comptes'];
?>
<h1>Tableau de bord <small class="aide"><?= e($tb['saisons'][0]['titre'] ?? '') ?></small></h1>
<?php if ($stats['helloasso'] > 0): ?>
    <p class="flash flash-info"><a href="<?= e(url('/helloasso')) ?>"><?= (int) $stats['helloasso'] ?> adhésion<?= $stats['helloasso'] > 1 ? 's' : '' ?> HelloAsso à valider</a></p>
<?php endif; ?>
<?php if ($stats['demandes'] > 0): ?>
    <p class="flash flash-info"><a href="<?= e(url('/demandes')) ?>"><?= (int) $stats['demandes'] ?> demande<?= $stats['demandes'] > 1 ? 's' : '' ?> d'inscription ou d'essai en ligne à traiter</a></p>
<?php endif; ?>

<section class="tuiles" aria-label="Inscrits">
    <?php foreach ($tb['saisons'] as $k => $s): ?>
        <div class="tuile<?= $k === 0 ? ' tuile-forte' : '' ?>">
            <span class="val"><?= (int) $s['inscrits'] ?></span>
            <span class="lib">inscrits <?= e($s['titre']) ?></span>
            <?php if ($k === 0 && isset($tb['saisons'][1]) && $tb['saisons'][1]['inscrits'] > 0): $d = $s['inscrits'] - $tb['saisons'][1]['inscrits']; ?>
                <span class="evol"><?= $d >= 0 ? '+' : '−' ?><?= abs($d) ?> par rapport à <?= e($tb['saisons'][1]['titre']) ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <a class="tuile<?= $tb['non_payees'] > 0 ? ' tuile-alerte' : '' ?>" href="<?= e(url('/inscriptions', ['statut' => 'impayees'])) ?>">
        <span class="val"><?= (int) $tb['non_payees'] ?></span><span class="lib">inscriptions non payées</span>
        <span class="evol">reste dû <?= e(money($stats['reste'])) ?></span>
    </a>
</section>

<section class="tuiles" aria-label="Argent de la saison">
    <div class="tuile"><span class="val"><?= e(money($stats['montant'])) ?></span><span class="lib">inscriptions</span><span class="evol">réglé <?= e(money($stats['regle'])) ?></span></div>
    <?php if ($c['lignes'] > 0): ?>
        <div class="tuile"><span class="val"><?= e(money($c['recettes'])) ?></span><span class="lib">recettes (banque)</span></div>
        <div class="tuile"><span class="val"><?= e(money($c['depenses'])) ?></span><span class="lib">dépenses (banque)</span></div>
        <div class="tuile<?= $c['resultat'] < 0 ? ' tuile-alerte' : '' ?>"><span class="val"><?= $c['resultat'] < 0 ? '−' : '' ?><?= e(money(abs($c['resultat']))) ?></span><span class="lib">résultat</span></div>
    <?php else: ?>
        <div class="tuile tuile-vide"><span class="lib">Recettes et dépenses : aucun relevé bancaire pour cette saison.</span></div>
    <?php endif; ?>
</section>

<div class="panneaux-tb">
    <section class="panneau-tb">
        <h2>Inscrits par famille</h2>
        <?= $barres($tb['familles'], 'n', false, 'Adhérents inscrits par famille') ?>
        <p class="aide">Une personne inscrite dans deux familles compte dans chacune. Cours d'essai exclus.</p>
    </section>
    <section class="panneau-tb">
        <h2>Genre</h2>
        <?php if ($totalG): $pf = round($g['femmes'] / $totalG * 100); ?>
            <svg class="repartition" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="<?= (int) $g['femmes'] ?> femmes et <?= (int) $g['hommes'] ?> hommes">
                <rect class="part-1" x="0" width="<?= max(0, $pf - 0.4) ?>" height="10"/><rect class="part-2" x="<?= $pf + 0.4 ?>" width="<?= max(0, 100 - $pf - 0.4) ?>" height="10"/>
            </svg>
            <ul class="legende">
                <li><span class="pastille-couleur part-1" aria-hidden="true"></span> Femmes <strong><?= (int) $g['femmes'] ?></strong> (<?= $pf ?> %)</li>
                <li><span class="pastille-couleur part-2" aria-hidden="true"></span> Hommes <strong><?= (int) $g['hommes'] ?></strong> (<?= 100 - $pf ?> %)</li>
            </ul>
        <?php else: ?><p class="aide">Aucun inscrit pour cette saison.</p><?php endif; ?>
    </section>
    <section class="panneau-tb">
        <h2>Recettes par catégorie</h2>
        <?= $barres($tb['categories']['recettes'], 'montant', true, 'Recettes par catégorie') ?>
    </section>
    <section class="panneau-tb">
        <h2>Dépenses par catégorie</h2>
        <?= $barres($tb['categories']['depenses'], 'montant', true, 'Dépenses par catégorie') ?>
    </section>
</div>
