<?php /** @var list<array{titre:string,points:list<int>,courante:bool,debut:string}> $series */
// Couleurs de données validées (daltonisme) : saison choisie, précédente, N-2
$couleurs = ['viz-1', 'viz-2', 'viz-3'];
$series = array_reverse($series); // la saison choisie d'abord
$max = max(1, ...array_map(static fn ($s) => max($s['points'] ?: [0]), $series));
$pas = $max > 400 ? 100 : ($max > 150 ? 50 : ($max > 60 ? 20 : 10));
$haut = (int) (ceil($max / $pas) * $pas);
$W = 760; $H = 340; $g = 44; $d = 150; $hh = 16; $b = 32;
$x = static fn (int $i) => $g + $i * ($W - $g - $d) / 51;
$y = static fn (int $v) => $H - $b - $v * ($H - $b - $hh) / $haut;
// Semaine en cours pour la saison choisie : la courbe s'arrête à aujourd'hui
$semaineCourante = $series ? (int) floor((time() - strtotime($series[0]['debut'])) / 604800) : 51;
$mois = ['sept.', 'oct.', 'nov.', 'déc.', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août'];
?>
<div class="titre-page"><h1>Courbe d'inscriptions</h1></div>
<p class="contexte">Nombre cumulé d'inscriptions semaine après semaine depuis le début de chaque saison (cours d'essai exclus). Les inscriptions prises avant le début de saison comptent dès la première semaine.</p>
<?php if (!$series): ?><p class="vide">Aucune saison.</p><?php else: ?>
<figure class="graphe">
<svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-labelledby="courbe-titre" class="courbe">
    <title id="courbe-titre">Inscriptions cumulées par semaine : <?= e(implode(', ', array_map(static fn ($s) => $s['titre'] . ' ' . max($s['points']), $series))) ?></title>
    <?php for ($v = 0; $v <= $haut; $v += $pas): ?>
        <line class="grille" x1="<?= $g ?>" x2="<?= $W - $d ?>" y1="<?= $y($v) ?>" y2="<?= $y($v) ?>"/>
        <text class="axe" x="<?= $g - 6 ?>" y="<?= $y($v) + 4 ?>" text-anchor="end"><?= $v ?></text>
    <?php endfor; ?>
    <?php foreach ($mois as $k => $m): $xi = $x((int) round($k * 52 / 12)); ?>
        <text class="axe" x="<?= $xi ?>" y="<?= $H - 10 ?>" text-anchor="start"><?= e($m) ?></text>
    <?php endforeach; ?>
    <?php $etiquettes = []; foreach (array_reverse($series, true) as $k => $s):
        $pts = $s['courante'] ? array_slice($s['points'], 0, max(1, min(52, $semaineCourante + 1))) : $s['points'];
        $chemin = implode(' ', array_map(static fn ($v, $i) => round($x($i), 1) . ',' . round($y($v), 1), $pts, array_keys($pts)));
        $fin = count($pts) - 1; ?>
        <polyline class="ligne <?= $couleurs[$k] ?><?= $k === 0 ? ' forte' : '' ?>" points="<?= $chemin ?>"/>
        <?php foreach ($pts as $i => $v): ?><circle class="cible" cx="<?= round($x($i), 1) ?>" cy="<?= round($y($v), 1) ?>" r="6"><title><?= e($s['titre'] . ', semaine ' . ($i + 1) . ' : ' . $v . ' inscriptions') ?></title></circle><?php endforeach; ?>
        <circle class="point <?= $couleurs[$k] ?>" cx="<?= round($x($fin), 1) ?>" cy="<?= round($y($pts[$fin]), 1) ?>" r="4"/>
        <?php $etiquettes[$k] = ['x' => round($x($fin), 1) + 8, 'y' => round($y($pts[$fin]), 1) + 4, 't' => $s['titre'] . ' · ' . (int) $pts[$fin]]; ?>
    <?php endforeach; ?>
    <?php // Étiquettes en bout de courbe, écartées de 15 px au moins pour ne pas se chevaucher
    uasort($etiquettes, static fn ($a, $b) => $a['y'] <=> $b['y']);
    $prec = -INF;
    foreach ($etiquettes as $k => $et): $yy = max($et['y'], $prec + 15); $prec = $yy; ?>
        <text class="etiquette" x="<?= $et['x'] ?>" y="<?= $yy ?>"><?= e($et['t']) ?></text>
    <?php endforeach; ?>
</svg>
<figcaption>
    <ul class="legende">
        <?php foreach ($series as $k => $s): ?><li><span class="pastille-couleur <?= $couleurs[$k] ?>" aria-hidden="true"></span> <?= e($s['titre']) ?><?= $s['courante'] ? ' (saison choisie, à ce jour)' : '' ?></li><?php endforeach; ?>
    </ul>
</figcaption>
</figure>
<details><summary>Voir les chiffres</summary>
<div class="table-cadre"><table>
    <thead><tr><th>Fin du mois</th><?php foreach ($series as $s): ?><th class="num"><?= e($s['titre']) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($mois as $k => $m): $i = min(51, (int) round(($k + 1) * 52 / 12) - 1); ?><tr><td><?= e($m) ?></td><?php foreach ($series as $s): ?><td class="num"><?= $s['courante'] && $i > $semaineCourante ? '' : (int) $s['points'][$i] ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table></div>
</details>
<?php endif; ?>
