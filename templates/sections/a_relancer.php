<?php /** @var array $participants @var bool $tous */
use Ascs\Domain\Participants;
$numeros = [];
foreach ($participants as $p) {
    if ($p['statut'] === 'a_relancer' && ($m = Ascs\Mail\Sms::mobile((string) ($p['telephone1'] ?: ($p['telephone2'] ?: $p['telephone']))))) {
        $numeros[$m] = true;
    }
}
$numeros = array_keys($numeros);
?>
<div class="titre-page"><h1>Venus sans inscription</h1></div>
<p class="contexte">Personnes ajoutées par les animateurs parce qu'elles viennent au cours sans être inscrites cette saison (saison choisie en haut de page). Elles passent en « Inscrit » toutes seules dès qu'une inscription existe pour leur fiche (ou pour une fiche à leur nom).</p>
<p class="filtres">
    <a class="btn btn-petit<?= $tous ? '' : ' actif' ?>" href="<?= e(url('/sections/a-relancer')) ?>">À traiter</a>
    <a class="btn btn-petit<?= $tous ? ' actif' : '' ?>" href="<?= e(url('/sections/a-relancer', ['tous' => 1])) ?>">Tous, y compris inscrits et retirés</a>
    <?php if ($numeros): ?><a class="btn btn-petit" data-sms-numeros="<?= e(implode(',', $numeros)) ?>" data-sms-texte="Bonjour, " href="sms:<?= e(implode(',', $numeros)) ?>?body=<?= e(rawurlencode('Bonjour, ')) ?>"><?= icon('sms') ?> SMS aux <?= count($numeros) ?> à relancer</a><?php endif; ?>
</p>
<?php if (!$participants): ?>
<p class="vide">Personne à relancer.</p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Personne</th><th>Cours</th><th>Contact</th><th class="num">Venues</th><th>Ajouté par</th><th>État</th><th><span class="visuellement-cache">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($participants as $p): $tel = (string) ($p['telephone1'] ?: ($p['telephone2'] ?: $p['telephone'])); ?>
        <tr>
            <td><?php if ($p['adherent_id']): ?><a href="<?= e(url('/adherents/' . (int) $p['adherent_id'])) ?>"><?= e(mb_strtoupper((string) $p['nom']) . ' ' . $p['prenom']) ?></a><br><small class="aide">ancien adhérent</small>
                <?php else: ?><?= e(mb_strtoupper((string) $p['nom']) . ' ' . $p['prenom']) ?><br><small class="aide">nouvelle personne · <a href="<?= e(url('/adherents/nouveau', ['nom' => $p['nom'], 'prenom' => $p['prenom'], 'telephone1' => $p['telephone']])) ?>">créer la fiche</a></small><?php endif; ?></td>
            <td><?= e((string) $p['cours']) ?></td>
            <td><?php if ($tel !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $tel)) ?>"><?= e($tel) ?></a><?php endif; ?><?= $p['mail'] ? '<br><small><a href="mailto:' . e($p['mail']) . '">' . e($p['mail']) . '</a></small>' : '' ?><?= $p['nom_parent'] ? '<br><small class="aide">parent : ' . e($p['prenom_parent'] . ' ' . $p['nom_parent']) . '</small>' : '' ?></td>
            <td class="num"><?= (int) $p['venues'] ?><?php if ($p['premiere']): ?><br><small class="aide"><?= e(dfr($p['premiere'])) ?><?= $p['derniere'] !== $p['premiere'] ? ' → ' . e(dfr($p['derniere'])) : '' ?></small><?php endif; ?></td>
            <td><small><?= e((string) $p['ajoute_par']) ?><br><?= e(dfr((string) $p['ajoute_le'])) ?></small></td>
            <td><span class="pastille<?= $p['statut'] === 'a_relancer' ? ' pastille-reste' : '' ?>"><?= e(Participants::STATUTS[$p['statut']] ?? $p['statut']) ?></span><?= $p['relance_le'] ? '<br><small class="aide">le ' . e(dfr((string) $p['relance_le'])) . '</small>' : '' ?></td>
            <td class="actions-ligne">
                <?php foreach (['relance' => 'Relancé', 'inscrit' => 'Inscrit', 'retire' => 'Retirer'] as $st => $lib): if ($p['statut'] === $st) { continue; } ?>
                    <form method="post" action="<?= e(url('/sections/a-relancer/' . (int) $p['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="statut" value="<?= e($st) ?>"><?php if ($tous): ?><input type="hidden" name="tous" value="1"><?php endif; ?><button class="btn btn-petit" type="submit"><?= e($lib) ?></button></form>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
