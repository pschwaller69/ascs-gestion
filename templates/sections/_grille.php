<?php /** @var array $inscrits @var array{dates:list<string>,p:array} $grille @var array $participants @var array $grilleParticipants */
$dates = $grille['dates'];
$nbD = count($dates);
$parDate = array_fill_keys($dates, 0);
$ligne = static function (array $marques) use ($dates, &$parDate): array {
    $n = 0;
    $cases = [];
    foreach ($dates as $d) {
        $v = $marques[$d] ?? null;
        if ($v) {
            $n++;
            $parDate[$d]++;
        }
        $cases[] = $v === null ? '<td class="g-vide" title="Non noté"></td>' : ($v ? '<td class="g-oui" title="Présent le ' . e(dfr($d)) . '">✓</td>' : '<td class="g-non" title="Absent le ' . e(dfr($d)) . '">–</td>');
    }
    return [$n, implode('', $cases)];
};
?>
<section id="presences">
<h2>Présences de la saison</h2>
<?php if (!$nbD): ?>
    <p class="vide">Aucune séance notée pour l'instant : l'animateur coche les présents depuis son lien.</p>
<?php else: ?>
<p class="aide"><?= $nbD ?> séance(s) notée(s), de la plus ancienne à la plus récente. ✓ présent · – absent · case vide : pas encore inscrit à cette date ou séance non notée.</p>
<div class="table-cadre" data-defiler-fin><table class="grille-presence">
    <thead><tr><th class="g-nom">Nom</th><th class="g-total">Venues</th><?php foreach ($dates as $d): ?><th class="g-date"><?= e(substr(dfr($d), 0, 5)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($inscrits as $i): [$n, $cases] = $ligne($grille['p'][(int) $i['id']] ?? []); $taux = $nbD ? (int) round(100 * $n / $nbD) : 0; ?>
        <tr><th class="g-nom" scope="row"><a href="<?= e(url('/adherents/' . $i['id'])) ?>"><?= e(mb_strtoupper((string) $i['nom']) . ' ' . $i['prenom']) ?></a></th>
            <td class="g-total"><span class="pastille <?= $taux >= 75 ? 'pastille-ok' : ($taux >= 40 ? 'pastille-reste' : 'pastille-erreur') ?>"><?= $n ?>/<?= $nbD ?></span></td><?= $cases ?></tr>
    <?php endforeach; ?>
    <?php foreach ($participants as $pa): [$n, $cases] = $ligne($grilleParticipants[(int) $pa['id']] ?? []); ?>
        <tr class="g-noninscrit"><th class="g-nom" scope="row"><?= e(mb_strtoupper((string) $pa['nom']) . ' ' . $pa['prenom']) ?> <small>(non inscrit)</small></th>
            <td class="g-total"><?= $n ?>/<?= $nbD ?></td><?= $cases ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th class="g-nom" scope="row">Présents</th><td class="g-total"><?= $nbD ? e(number_format(array_sum($parDate) / $nbD, 1, ',', '')) : '' ?> <small>moy.</small></td><?php foreach ($dates as $d): ?><td><?= (int) $parDate[$d] ?></td><?php endforeach; ?></tr></tfoot>
</table></div>
<?php endif; ?>
</section>
