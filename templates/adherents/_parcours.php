<?php /** @var array $inscriptions @var array $venues @var bool $reprendre (boutons pour choisir un cours d'une saison passée) */
$reprendre = $reprendre ?? false;
// Parcours : inscriptions de la saison choisie en haut de page, puis une ligne par saison passée
$saisonCour = $app->saisonId();
$parSaison = [];
foreach ($inscriptions as $i) {
    $parSaison[(string) $i['saison']][] = $i;
}
$cetteSaison = array_values(array_filter($inscriptions, static fn ($i) => (int) $i['saison_id'] === $saisonCour));
$passees = array_filter($parSaison, static fn ($l) => (int) $l[0]['saison_id'] !== $saisonCour);
?>
<section class="parcours" aria-label="Inscriptions">
    <div class="parcours-ligne">
        <span class="parcours-saison">Cette saison</span>
        <?php if (!$cetteSaison): ?><span class="aide">pas inscrit<?= $venues ? ' · venu(e) sans inscription : ' . e(implode(', ', array_column($venues, 'cours'))) : '' ?></span><?php endif; ?>
        <?php foreach ($cetteSaison as $i): $reste = (float) $i['montant'] - (float) $i['regle']; ?>
            <a class="puce-insc<?= $reste > 0.005 ? ' puce-due' : '' ?>" href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['section']) ?>
                <small><?= $reste > 0.005 ? 'reste ' . e(money($reste)) : 'payé' ?><?= $i['carte'] ? ' · carte ' . (int) $i['carte']['restantes'] . '/' . (int) $i['carte']['achetees'] : ($i['presences'] ? ' · ' . (int) $i['presences'] . ' présence' . ($i['presences'] > 1 ? 's' : '') : '') ?></small></a>
        <?php endforeach; ?>
    </div>
    <?php if ($reprendre && $passees):
        $deja = array_map(static fn ($i) => (int) $i['section_id'], $cetteSaison);
        $arretees = (new Ascs\Domain\Arrets($app->pdo))->sections();
        $proposes = [];
        foreach ($passees as $l) {
            foreach ($l as $i) {
                $sid = (int) $i['section_id'];
                if (!isset($proposes[$sid]) && !in_array($sid, $deja, true) && !in_array($sid, $arretees, true) && !$i['essai']) {
                    $proposes[$sid] = $i;
                }
            }
        }
        if ($proposes): ?>
    <div class="parcours-ligne">
        <span class="parcours-saison">Reprendre</span>
        <?php foreach ($proposes as $sid => $i): ?>
            <a class="puce-insc puce-reprendre" href="<?= e(url('/inscriptions/nouveau', ['adherent' => $i['adherent_id'] ?? ($adherentId ?? 0), 'section' => $sid])) ?>" data-choisir-section="<?= $sid ?>"><?= e($i['section']) ?> <small>en <?= e($i['saison']) ?></small></a>
        <?php endforeach; ?>
    </div>
    <?php endif; endif; ?>
    <?php if ($passees): ?>
    <details class="parcours-passe"<?= !$cetteSaison || $reprendre ? ' open' : '' ?>>
        <summary><?= count($passees) ?> saison<?= count($passees) > 1 ? 's' : '' ?> précédente<?= count($passees) > 1 ? 's' : '' ?> : <?= e(implode(' · ', array_map(static fn ($s, $l) => $s . ' (' . count($l) . ')', array_keys($passees), $passees))) ?></summary>
        <?php foreach ($passees as $s => $l): ?>
            <div class="parcours-ligne">
                <span class="parcours-saison"><?= e($s) ?></span>
                <?php foreach ($l as $i): $reste = (float) $i['montant'] - (float) $i['regle']; ?>
                    <a class="puce-insc<?= $reste > 0.005 ? ' puce-due' : '' ?>" href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['section']) ?><?= $reste > 0.005 ? ' <small>reste ' . e(money($reste)) . '</small>' : '' ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>
</section>
