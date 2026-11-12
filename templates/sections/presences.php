<?php /** @var array $sections @var array $resume */
$ordre = $sections;
usort($ordre, static fn ($a, $b) => [!isset($resume[(int) $a['id']]), (int) $a['essai'], $a['titre']] <=> [!isset($resume[(int) $b['id']]), (int) $b['essai'], $b['titre']]);
$sansSeance = count(array_filter($sections, static fn ($s) => !isset($resume[(int) $s['id']])));
?>
<div class="titre-page"><h1>Présences aux cours</h1></div>
<p class="contexte">Saison choisie en haut de page. Les cours déjà pointés d'abord. Ouvrez un cours pour voir la grille des présences de chaque adhérent, séance par séance.<?= $sansSeance ? ' ' . $sansSeance . ' cours n\'ont encore aucune séance notée.' : '' ?></p>
<div class="table-cadre"><table class="table-presences">
    <thead><tr><th>Cours</th><th class="num">Inscrits</th><th class="num">Séances notées</th><th>Dernière séance</th><th class="num">Présents en moyenne</th><th class="num">Taux</th></tr></thead>
    <tbody>
    <?php foreach ($ordre as $s): $r = $resume[(int) $s['id']] ?? null; $moy = $r && $r['seances'] ? $r['presents'] / $r['seances'] : null; $taux = $r && $r['notes'] ? (int) round(100 * $r['presents'] / $r['notes']) : null; ?>
        <tr<?= $r ? '' : ' class="attenue"' ?>>
            <td><a href="<?= e(url('/sections/' . $s['id'])) ?>#presences"><?= e($s['titre']) ?></a></td>
            <td class="num"><?= (int) $s['inscrits'] ?></td>
            <td class="num"><?= $r ? $r['seances'] : '–' ?></td>
            <td><?= $r ? e(dfr((string) $r['derniere'])) : 'aucune' ?></td>
            <td class="num"><?= $moy !== null ? e(number_format($moy, 1, ',', '')) : '' ?></td>
            <td class="num"><?php if ($taux !== null): ?><span class="pastille <?= $taux >= 75 ? 'pastille-ok' : ($taux >= 40 ? 'pastille-reste' : 'pastille-erreur') ?>"><?= $taux ?> %</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<p class="aide">Taux : présences cochées sur l'ensemble des cases notées (inscrits × séances). Les personnes venues sans inscription ne sont pas comptées ici.</p>
