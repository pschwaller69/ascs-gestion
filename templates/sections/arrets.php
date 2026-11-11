<?php /** @var array $arrets @var array $principales @var array $sections @var int $saisonId */
$parPrincipale = [];
foreach ($sections as $s) {
    $parPrincipale[(int) $s['section_principale_id']][] = $s;
}
?>
<div class="titre-page"><h1>Activités arrêtées</h1></div>
<p class="contexte">Une activité arrêtée n'est plus proposée : nouvelle inscription, inscription en ligne, HelloAsso, changement de cours, cartes. Tout son historique reste : inscriptions et règlements des saisons passées, résultats, reporting, fiches des adhérents, mailings aux anciens. On peut la reprendre à tout moment.</p>

<div class="table-cadre"><table>
    <thead><tr><th>Activité (section principale)</th><th class="num">Inscrits saison précédente</th><th class="num">Cette saison</th><th>État</th><th><span class="visuellement-cache">Action</span></th></tr></thead>
    <tbody>
    <?php foreach ($principales as $sp): $ar = $arrets['principale'][(int) $sp['id']] ?? null; ?>
        <tr<?= $ar ? ' class="ligne-arretee"' : '' ?>>
            <td><strong><?= e($sp['titre']) ?></strong><br><small class="aide"><?= e(implode(' · ', array_map(static fn ($s) => $s['titre'], $parPrincipale[(int) $sp['id']] ?? []))) ?></small></td>
            <td class="num"><?= (int) $sp['n_prec'] ?></td>
            <td class="num"><?= (int) $sp['n'] ?><?php if (!$ar && (int) $sp['n'] === 0 && (int) $sp['n_prec'] > 0): ?><br><span class="pastille pastille-reste">plus personne</span><?php endif; ?></td>
            <td><?= $ar ? '<span class="pastille pastille-erreur">arrêtée</span><br><small class="aide">depuis ' . e((string) ($ar['saison'] ?? '')) . '</small>' : '<span class="pastille pastille-ok">proposée</span>' ?></td>
            <td>
                <form method="post" action="<?= e(url('/activites-arretees')) ?>"<?= $ar ? '' : ' data-confirmer="Arrêter ' . e($sp['titre']) . ' ? Elle ne sera plus proposée (historique gardé)."' ?>>
                    <?= csrf_field() ?><input type="hidden" name="type" value="principale"><input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                    <button class="btn btn-petit" type="submit" name="action" value="<?= $ar ? 'reprendre' : 'arreter' ?>"><?= $ar ? 'Reprendre' : 'Arrêter' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<section class="fiche">
    <h2>Arrêter une seule section</h2>
    <p class="aide">Pour un cours précis qui s'arrête alors que l'activité continue (un créneau supprimé, un stage passé).</p>
    <?php foreach ($arrets['section'] as $id => $ar): $s = array_values(array_filter($sections, static fn ($x) => (int) $x['id'] === $id))[0] ?? null; if (!$s) { continue; } ?>
        <form method="post" action="<?= e(url('/activites-arretees')) ?>" class="ligne-reglage">
            <?= csrf_field() ?><input type="hidden" name="type" value="section"><input type="hidden" name="id" value="<?= (int) $id ?>">
            <strong><?= e($s['titre']) ?> <span class="pastille pastille-erreur">arrêtée</span> <small class="aide">depuis <?= e((string) ($ar['saison'] ?? '')) ?></small></strong>
            <button class="btn btn-petit" type="submit" name="action" value="reprendre">Reprendre</button>
        </form>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('/activites-arretees')) ?>" class="ligne-reglage">
        <?= csrf_field() ?><input type="hidden" name="type" value="section">
        <label>Section
            <select name="id" required>
                <option value="">— choisir —</option>
                <?php foreach ($sections as $s): if (isset($arrets['section'][(int) $s['id']]) || isset($arrets['principale'][(int) $s['section_principale_id']])) { continue; } ?><option value="<?= (int) $s['id'] ?>"><?= e($s['titre']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-petit" type="submit" name="action" value="arreter">Arrêter cette section</button>
    </form>
</section>
