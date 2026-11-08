<?php
/** @var array $garde @var array $autre @var array $champs @var array $inscriptions_garde @var array $inscriptions_autre @var array $doubles @var bool $enTransition */
$nom = static fn (array $f) => trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? '')) ?: 'Fiche sans nom';
$gid = (int) $garde['id'];
$aid = (int) $autre['id'];
$differents = array_filter($champs, static fn ($c) => $c['differe']);
?>
<div class="titre-page">
    <h1>Fusionner deux fiches</h1>
    <a class="btn" href="<?= e(url('/adherents/' . $aid . '/fusion/' . $gid)) ?>">Garder plutôt la fiche n° <?= $aid ?></a>
</div>
<p class="contexte">La fiche <strong>n° <?= $gid ?></strong> est gardée. La fiche <strong>n° <?= $aid ?></strong> lui transmet ses inscriptions, ses règlements et les valeurs que vous choisissez, puis elle est supprimée.</p>
<?php if ($enTransition): ?>
    <p class="flash flash-info">Mode transition : fusionnez aussi ces deux fiches dans WinDev. Sinon, chaque import WinDev les fera revenir et l'application refera cette fusion juste après.</p>
<?php endif; ?>

<form class="fiche" method="post" action="<?= e(url('/adherents/' . $gid . '/fusion/' . $aid)) ?>" data-confirmer="Fusionner ces deux fiches ? La fiche n° <?= $aid ?> sera supprimée.">
    <?= csrf_field() ?>
    <div class="table-cadre"><table class="comparaison">
        <thead><tr><th scope="col">Champ</th><th scope="col"><a href="<?= e(url('/adherents/' . $gid)) ?>">Fiche gardée n° <?= $gid ?></a></th><th scope="col"><a href="<?= e(url('/adherents/' . $aid)) ?>">Fiche n° <?= $aid ?></a></th></tr></thead>
        <tbody>
        <?php foreach ($champs as $c): $n = 'choix[' . $c['champ'] . ']'; ?>
            <tr<?= $c['differe'] ? ' class="differe"' : '' ?>>
                <th scope="row"><?= e($c['libelle']) ?></th>
                <?php if ($c['differe']): ?>
                    <?php foreach (['garde', 'autre'] as $cote): $idc = 'ch-' . $c['champ'] . '-' . $cote; ?>
                        <td><label class="choix-valeur" for="<?= $idc ?>"><input type="radio" id="<?= $idc ?>" name="<?= e($n) ?>" value="<?= $cote ?>"<?= attr($c['defaut'] === $cote, 'checked') ?>> <span><?= $c[$cote] !== '' ? e($c[$cote]) : '<em class="aide">vide</em>' ?></span></label></td>
                    <?php endforeach; ?>
                <?php else: ?>
                    <td colspan="2"><?= $c['garde'] !== '' ? e($c['garde']) : '<em class="aide">vide</em>' ?> <small class="aide">identique</small></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="aide"><?= $differents ? count($differents) . ' champ(s) différent(s) : cochez la valeur à garder.' : 'Les deux fiches sont identiques sur ces champs.' ?>
        Les cases « membre du CA », « attestation CE » et les refus de mail, SMS ou WhatsApp cochées sur l'une ou l'autre fiche sont gardées ; les remarques sont mises bout à bout.</p>

    <h2>Inscriptions</h2>
    <div class="deux-colonnes">
        <?php foreach ([['Fiche gardée n° ' . $gid, $inscriptions_garde], ['Fiche n° ' . $aid . ' (reprises sur la fiche gardée)', $inscriptions_autre]] as [$titre, $liste]): ?>
        <div>
            <h3><?= e($titre) ?></h3>
            <?php if (!$liste): ?><p class="aide">Aucune inscription.</p><?php else: ?>
            <ul class="liste-simple">
                <?php foreach ($liste as $i): ?><li><?= e($i['saison']) ?> — <?= e($i['section']) ?> — <?= e(money($i['montant'])) ?> <small class="aide">(réglé <?= e(money($i['regle'])) ?>)</small></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($doubles): ?>
        <div class="flash flash-erreur" role="alert">
            <p><strong>Attention : inscription en double.</strong> Les deux fiches sont inscrites à la même activité la même saison :</p>
            <ul><?php foreach ($doubles as $d): ?><li><?= e($d['section']) ?>, <?= e($d['saison']) ?> (inscriptions n° <?= (int) $d['garde'] ?> et n° <?= (int) $d['autre'] ?>)</li><?php endforeach; ?></ul>
            <p>En fusionnant, l'inscription de la fiche gardée reste (avec son montant) et reçoit les règlements de l'autre, qui est supprimée. Vérifiez ensuite le montant et les règlements.</p>
        </div>
    <?php endif; ?>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Fusionner dans la fiche n° <?= $gid ?></button>
        <a class="btn" href="<?= e(url('/adherents/' . $gid)) ?>">Annuler</a>
    </div>
</form>
