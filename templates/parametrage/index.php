<?php
/** @var string $cle @var array $def @var array $lignes @var array $options @var array $extra */
$cachees = ['nom_campagne_helloasso', 'montant_rentabilite_annuel', 'mail_animateur1', 'code'];
$cols = array_filter($def['champs'], static fn ($f, $c) => !in_array($c, $cachees, true), ARRAY_FILTER_USE_BOTH);
$affiche = static function (array $f, string $c, mixed $val) use ($options): string {
    return match ($f['type']) {
        'bool' => $val ? 'oui' : '',
        'number' => $val === null ? '' : money($val),
        'date' => dfr($val),
        'select' => (string) ($options[$c][(int) $val] ?? ''),
        default => (string) $val,
    };
};
?>
<div class="titre-page">
    <h1><?= e($def['titre']) ?></h1>
    <a class="btn btn-principal" href="<?= e(url('/parametrage/' . $cle . '/nouveau')) ?>"><?= icon('file-plus') ?> Nouvelle <?= e($def['un']) ?></a>
</div>
<?php if ($app->transition()): ?>
    <p class="contexte">Mode transition : un import WinDev remplace ce paramétrage par celui de WinDev. Faites aussi la modification dans WinDev, ou attendez la bascule.</p>
<?php endif; ?>
<?php if (!$lignes): ?><p class="vide">Rien pour l'instant.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr>
        <?php foreach ($cols as $c => $f): ?><th<?= $f['type'] === 'number' ? ' class="num"' : '' ?>><?= e($f['court'] ?? preg_replace('/ \(€\)$/', '', $f['libelle'])) ?></th><?php endforeach; ?>
        <?php if ($def['table'] === 'section'): ?><th>Créneaux</th><th class="num">Inscrits</th><?php endif; ?>
        <th>Utilisé par</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
        <tr>
            <?php $premier = true; foreach ($cols as $c => $f): ?>
                <td<?= $f['type'] === 'number' ? ' class="num"' : '' ?>><?php if ($premier): ?><a href="<?= e(url('/parametrage/' . $cle . '/' . $l['id'])) ?>"><?= e($affiche($f, $c, $l[$c]) ?: '(sans titre)') ?></a><?php else: ?><?= e($affiche($f, $c, $l[$c])) ?><?php endif; ?></td>
            <?php $premier = false; endforeach; ?>
            <?php if ($def['table'] === 'section'): ?>
                <td><small><?= e(implode(', ', $extra['creneaux'][(int) $l['id']] ?? [])) ?></small></td>
                <td class="num"><?= (int) ($extra['inscrits'][(int) $l['id']] ?? 0) ?: '' ?></td>
            <?php endif; ?>
            <td><small class="aide"><?= e(implode(', ', $l['_usage'])) ?></small></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($def['table'] === 'section'): ?><p class="aide">Inscrits : saison choisie en haut de page.</p><?php endif; ?>
<?php endif; ?>
