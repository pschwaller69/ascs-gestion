<?php /** @var array $s @var array $saison @var string $base @var array $q @var array $ins @var array $cibles @var array $creneaux @var ?string $erreur */
$nom = $ins['prenom'] . ' ' . mb_strtoupper((string) $ins['nom']);
?>
<p><a href="<?= e(url($base, $q)) ?>">‹ Retour à la liste de présence</a></p>
<h1>Changer <?= e($nom) ?> de cours</h1>
<p class="contexte">Actuellement dans <strong><?= e($s['titre']) ?></strong><?= $creneaux ? ' (' . e(implode(', ', $creneaux)) . ')' : '' ?>, saison <?= e($saison['titre']) ?>.</p>
<?php if ($erreur): ?><p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?php if (!$cibles): ?>
    <p class="vide">Aucun autre cours de la même activité : demandez au bureau.</p>
<?php else: ?>
<form method="post" action="<?= e(url($base . '/changer/' . (int) $ins['inscription_id'])) ?>" class="changement-cours">
    <?= csrf_field() ?>
    <?php foreach ($q as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <input type="hidden" name="nom" value="<?= e($nom) ?>">
    <fieldset>
        <legend>Nouveau cours</legend>
        <?php foreach ($cibles as $k => $c): ?>
            <label class="choix-cours" for="vers<?= (int) $c['id'] ?>">
                <input type="radio" id="vers<?= (int) $c['id'] ?>" name="vers" value="<?= (int) $c['id'] ?>" required>
                <span><strong><?= e($c['titre']) ?></strong><br><small class="aide"><?= $c['creneaux'] ? e(implode(', ', $c['creneaux'])) . ' · ' : '' ?><?= (int) $c['inscrits'] ?> inscrit(s)</small></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <fieldset>
        <legend>Raison</legend>
        <?php foreach (Ascs\Domain\Changements::MOTIFS as $k => $lib): ?>
            <label class="choix-case" for="motif-<?= e($k) ?>"><input type="radio" id="motif-<?= e($k) ?>" name="motif" value="<?= e($k) ?>" required> <?= e($lib) ?></label>
        <?php endforeach; ?>
        <div class="champ"><label for="precision">Précision pour le bureau (facultatif)</label><input type="text" id="precision" name="precision" maxlength="255"></div>
    </fieldset>
    <p class="aide">Le changement est immédiat et le bureau en est informé. Le tarif payé ne change pas.</p>
    <button type="submit" class="btn btn-principal">Changer de cours</button>
</form>
<?php endif; ?>
