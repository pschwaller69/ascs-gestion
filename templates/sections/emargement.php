<?php /** @var array $s @var array $inscrits @var array $creneaux @var list<string> $dates @var int $colonnes @var string $depuis @var string $saison @var array $grille */
$cols = $dates ?: array_fill(0, $colonnes, '');
?>
<div class="feuille-emargement">
<form class="ne-pas-imprimer filtres" method="get">
    <div class="champ"><label for="depuis">Première séance à partir du</label><input type="date" id="depuis" name="depuis" value="<?= e($depuis) ?>"></div>
    <div class="champ"><label for="colonnes">Colonnes</label><input type="number" id="colonnes" name="colonnes" min="4" max="16" value="<?= (int) $colonnes ?>"></div>
    <button class="btn" type="submit">Mettre à jour</button>
    <button class="btn btn-principal" type="button" data-imprimer><?= icon('list') ?> Imprimer</button>
</form>
<h1><?= e($s['titre']) ?> <small>— émargement <?= e($saison) ?></small></h1>
<p class="sous-titre"><?= $creneaux ? e(implode(', ', $creneaux)) . ' · ' : '' ?><?= count($inscrits) ?> inscrit<?= count($inscrits) > 1 ? 's' : '' ?><?= $dates ? '' : ' · dates à compléter (aucun créneau renseigné pour cette section)' ?></p>
<table class="table-emargement">
    <thead><tr><th class="e-nom">Nom</th><th class="e-tel">Téléphone</th><?php foreach ($cols as $d): ?><th class="e-date"><?= $d ? e(substr(dfr($d), 0, 5)) : '&nbsp;' ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($inscrits as $i): ?>
        <tr><td class="e-nom"><?= e(mb_strtoupper((string) $i['nom']) . ' ' . $i['prenom']) ?></td><td class="e-tel"><?= e($i['telephone1'] ?? '') ?></td><?php foreach ($cols as $d): $v = $d ? ($grille['p'][(int) $i['id']][$d] ?? null) : null; ?><td class="e-date<?= $v === null ? '' : ($v ? ' e-oui' : ' e-non') ?>"><?= $v === null ? '' : ($v ? '✓' : 'abs') ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php for ($k = 0; $k < 3; $k++): ?><tr class="e-libre"><td class="e-nom">&nbsp;</td><td></td><?php foreach ($cols as $_): ?><td></td><?php endforeach; ?></tr><?php endfor; ?>
    </tbody>
</table>
<p class="aide">Trois lignes libres pour les cours d'essai ou les nouveaux inscrits.<?= array_intersect($cols, $grille['dates']) ? ' Les séances déjà notées par l\'animateur sont pré-remplies (✓ présent, abs absent).' : '' ?></p>
</div>
