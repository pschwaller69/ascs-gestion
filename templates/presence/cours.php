<?php /** @var array $controleur @var array $cours @var array $saison @var string $base */
use Ascs\Domain\Referentiel;
$auj = (int) date('N');
$parJour = [];
foreach ($cours as $c) {
    if (!$c['creneaux']) {
        $parJour[8][] = $c + ['heure' => ''];
    }
    foreach ($c['creneaux'] as $cr) {
        $parJour[$cr['jour']][] = $c + ['heure' => $cr['heure']];
    }
}
foreach ($parJour as &$l) {
    usort($l, static fn ($a, $b) => [$a['heure'], $a['titre']] <=> [$b['heure'], $b['titre']]);
}
unset($l);
// Aujourd'hui d'abord, puis les jours suivants de la semaine
$ordre = [];
for ($k = 0; $k < 7; $k++) {
    $ordre[] = (($auj - 1 + $k) % 7) + 1;
}
$ordre[] = 8;
?>
<h1>Contrôle des inscriptions</h1>
<p class="contexte"><?= e($controleur['nom']) ?> · saison <?= e($saison['titre']) ?> · <?= count($cours) ?> cours. Ouvrez un cours pour voir qui est inscrit ; une personne présente qui n'est pas dans la liste s'ajoute avec « Ajouter une personne non inscrite », le bureau la relancera.</p>
<div class="champ"><label for="cherche-cours">Rechercher un cours</label><input type="search" id="cherche-cours" placeholder="Pilates, danse…" autocomplete="off" data-filtre-liste="liste-cours"></div>
<div id="liste-cours" class="liste-cours">
<?php foreach ($ordre as $j): if (empty($parJour[$j])) { continue; } ?>
    <section data-nom="<?= e(mb_strtolower(implode(' ', array_column($parJour[$j], 'titre')))) ?>">
        <h2><?= $j === 8 ? 'Sans horaire' : e(Referentiel::JOURS[$j]) . ($j === $auj ? ' (aujourd\'hui)' : '') ?></h2>
        <ul class="presence-liste">
        <?php foreach ($parJour[$j] as $c): ?>
            <li data-nom="<?= e(mb_strtolower($c['titre'])) ?>"><a class="lien-cours" href="<?= e(url($base, ['s' => $c['id']])) ?>">
                <span class="p-nom"><?= $c['heure'] !== '' ? '<span class="heure">' . e($c['heure']) . '</span> ' : '' ?><?= e($c['titre']) ?><?= $c['essai'] ? ' <small class="aide">cours d\'essai</small>' : '' ?></span>
                <span class="aide"><?= (int) $c['inscrits'] ?> inscrit(s) ›</span>
            </a></li>
        <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
<p class="aide">Ce lien est personnel : merci de ne pas le transmettre.</p>
