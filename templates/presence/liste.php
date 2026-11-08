<?php /** @var array $s @var array $saison @var string $date @var string $jeton @var array $inscrits @var array $creneaux @var array $notee @var array $seances @var bool $enregistre */
$deja = $notee !== [];
$base = '/presence/' . $jeton;
?>
<h1><?= e($s['titre']) ?></h1>
<p class="contexte">Liste de présence <?= e($saison['titre']) ?><?= $creneaux ? ' · ' . e(implode(', ', $creneaux)) : '' ?></p>
<?php if ($enregistre): ?><p class="flash flash-ok" role="status">Présences du <?= e(dfr($date)) ?> enregistrées.</p><?php endif; ?>

<form method="get" action="<?= e(url($base)) ?>" class="filtres">
    <div class="champ"><label for="date">Séance du</label><input type="date" id="date" name="date" value="<?= e($date) ?>" min="<?= e($saison['date_debut']) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" data-autosubmit></div>
    <noscript><button class="btn" type="submit">Afficher</button></noscript>
</form>
<?php if ($deja): ?><p class="aide">Séance déjà notée : vous pouvez corriger puis enregistrer de nouveau.</p><?php endif; ?>

<?php if (!$inscrits): ?>
    <p class="vide">Aucun inscrit dans cette section pour la saison <?= e($saison['titre']) ?>.</p>
<?php else: ?>
<form method="post" action="<?= e(url($base)) ?>" id="form-presence">
    <?= csrf_field() ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <ul class="presence-liste">
    <?php foreach ($inscrits as $i): $id = (int) $i['id']; $age = age_from($i['date_naissance']); $tel = (string) ($i['telephone1'] ?? ''); ?>
        <li>
            <label for="p<?= $id ?>">
                <input type="checkbox" id="p<?= $id ?>" name="present[]" value="<?= $id ?>"<?= attr($notee[$id] ?? false, 'checked') ?>>
                <span class="p-nom"><?= e($i['prenom'] . ' ' . mb_strtoupper((string) $i['nom'])) ?></span>
                <span class="p-info"><?= $age !== null && $age < 18 ? e($age . ' ans') . '<br>' : '' ?><?php if ($tel !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $tel)) ?>"><?= e($tel) ?></a><?php endif; ?></span>
            </label>
        </li>
    <?php endforeach; ?>
    </ul>
    <div class="presence-compte">
        <span id="compte-presents" aria-live="polite"><?= count(array_filter($notee)) ?> présent(s) sur <?= count($inscrits) ?></span>
        <button type="submit" class="btn btn-principal">Enregistrer les présences</button>
    </div>
</form>
<?php endif; ?>
<?php if ($seances): ?>
    <h2>Séances notées</h2>
    <ul class="liste-simple">
        <?php foreach ($seances as $se): ?><li><a href="<?= e(url($base, ['date' => $se['date_seance']])) ?>"><?= e(dfr($se['date_seance'])) ?></a> : <?= (int) $se['presents'] ?> présent(s) sur <?= (int) $se['total'] ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>
<p class="aide">Ce lien est personnel : merci de ne pas le transmettre. En cas de souci, contactez le bureau de l'association.</p>
