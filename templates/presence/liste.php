<?php /** @var array $s @var array $saison @var string $date @var string $base @var array $q @var array $onglets @var array $inscrits @var array $creneaux @var array $notee @var array $seances @var bool $enregistre @var ?string $changeFait @var bool $peutChanger */
$deja = $notee !== [];
$numeros = [];
foreach ($inscrits as $i) {
    $m = Ascs\Mail\Sms::mobile((string) ($i['telephone1'] ?? '')) ?? Ascs\Mail\Sms::mobile((string) ($i['telephone2'] ?? ''));
    if ($m !== null) {
        $numeros[$m] = true;
    }
}
$numeros = array_keys($numeros);
$texteSms = 'Bonjour, information pour le cours ' . $s['titre'] . ' : ';
?>
<?php if (count($onglets) > 1): ?>
<nav class="onglets-cours" aria-label="Mes cours">
    <?php foreach ($onglets as $o): ?>
        <a href="<?= e(url($base, ['s' => (int) $o['id']])) ?>"<?= (int) $o['id'] === (int) $s['id'] ? ' aria-current="page"' : '' ?>><?= e($o['titre']) ?> <span class="nb"><?= (int) $o['inscrits'] ?></span></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<h1><?= e($s['titre']) ?></h1>
<p class="contexte">Liste de présence <?= e($saison['titre']) ?><?= $creneaux ? ' · ' . e(implode(', ', $creneaux)) : '' ?> · <?= count($inscrits) ?> inscrit(s)</p>
<?php if ($changeFait): ?><p class="flash flash-ok" role="status"><?= e($changeFait) ?></p><?php endif; ?>
<?php if ($enregistre): ?><p class="flash flash-ok" role="status">Présences du <?= e(dfr($date)) ?> enregistrées.</p><?php endif; ?>

<?php if ($numeros): ?>
<p class="sms-cours">
    <a class="btn" data-sms-numeros="<?= e(implode(',', $numeros)) ?>" data-sms-texte="<?= e($texteSms) ?>" href="sms:<?= e(implode(',', $numeros)) ?>?body=<?= e(rawurlencode($texteSms)) ?>"><?= icon('sms') ?> SMS à tout le cours (<?= count($numeros) ?>)</a>
    <small class="aide">Pour prévenir d'un retard ou d'une annulation : ouvre la messagerie du téléphone avec les numéros déjà remplis.</small>
</p>
<?php endif; ?>

<form method="get" action="<?= e(url($base)) ?>" class="filtres">
    <?php foreach ($q as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <div class="champ"><label for="date">Séance du</label><input type="date" id="date" name="date" value="<?= e($date) ?>" min="<?= e($saison['date_debut']) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" data-autosubmit></div>
    <?php if (count($inscrits) > 8): ?><div class="champ"><label for="cherche-presence">Rechercher</label><input type="search" id="cherche-presence" placeholder="Nom ou prénom" autocomplete="off" data-filtre-liste="liste-presence"></div><?php endif; ?>
    <noscript><button class="btn" type="submit">Afficher</button></noscript>
</form>
<?php if ($deja): ?><p class="aide">Séance déjà notée : vous pouvez corriger puis enregistrer de nouveau.</p><?php endif; ?>

<?php if (!$inscrits): ?>
    <p class="vide">Aucun inscrit dans ce cours pour la saison <?= e($saison['titre']) ?>.</p>
<?php else: ?>
<form method="post" action="<?= e(url($base)) ?>" id="form-presence">
    <?= csrf_field() ?>
    <?php foreach ($q as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <ul class="presence-liste" id="liste-presence">
    <?php foreach ($inscrits as $i): $id = (int) $i['id']; $age = age_from($i['date_naissance']); $tel = (string) ($i['telephone1'] ?: ($i['telephone2'] ?? '')); $mob = Ascs\Mail\Sms::mobile($tel); $num = preg_replace('/[^\d+]/', '', $tel); ?>
        <li data-nom="<?= e(mb_strtolower($i['nom'] . ' ' . $i['prenom'])) ?>">
            <label for="p<?= $id ?>">
                <input type="checkbox" id="p<?= $id ?>" name="present[]" value="<?= $id ?>"<?= attr($notee[$id] ?? false, 'checked') ?>>
                <span class="p-nom"><?= e(mb_strtoupper((string) $i['nom'])) ?> <?= e($i['prenom']) ?><?php if ($age !== null && $age < 18): ?> <small class="aide"><?= e($age . ' ans') ?></small><?php endif; ?>
                    <?php if ($tel !== ''): ?><br><small class="p-tel"><?= e($tel) ?></small><?php endif; ?></span>
            </label>
            <span class="p-actions">
                <?php if ($mob): ?><a class="p-rond" href="sms:<?= e($mob) ?>" aria-label="SMS à <?= e($i['prenom'] . ' ' . $i['nom']) ?>"><?= icon('sms') ?></a><?php endif; ?>
                <?php if ($num !== ''): ?><a class="p-rond" href="tel:<?= e($num) ?>" aria-label="Appeler <?= e($i['prenom'] . ' ' . $i['nom']) ?>"><?= icon('phone') ?></a><?php endif; ?>
                <?php if ($peutChanger): ?><a class="p-changer" href="<?= e(url($base . '/changer/' . (int) $i['inscription_id'], $q)) ?>" aria-label="Changer <?= e($i['prenom'] . ' ' . $i['nom']) ?> de cours">Changer<br>de cours</a><?php endif; ?>
            </span>
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
        <?php foreach ($seances as $se): ?><li><a href="<?= e(url($base, $q + ['date' => $se['date_seance']])) ?>"><?= e(dfr($se['date_seance'])) ?></a> : <?= (int) $se['presents'] ?> présent(s) sur <?= (int) $se['total'] ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>
<p class="aide">Ce lien est personnel : merci de ne pas le transmettre. En cas de souci, contactez le bureau de l'association.</p>
