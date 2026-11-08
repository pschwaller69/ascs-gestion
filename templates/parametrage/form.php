<?php
/** @var string $cle @var array $def @var ?array $l @var array $v @var array $err @var array $options @var array $creneaux @var array $usage */
use Ascs\Domain\Referentiel;
$action = $l ? '/parametrage/' . $cle . '/' . $l['id'] : '/parametrage/' . $cle;
$val = static fn (string $c) => $v[$c] ?? '';
?>
<div class="titre-page">
    <h1><?= $l ? e(ucfirst($def['un']) . ' « ' . ($l['titre'] ?? '') . ' »') : 'Nouvelle ' . e($def['un']) ?></h1>
    <a class="btn" href="<?= e(url('/parametrage/' . $cle)) ?>"><?= e($def['titre']) ?></a>
</div>
<form class="fiche" method="post" action="<?= e(url($action)) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Corrigez les champs signalés.</p><?php endif; ?>
    <div class="grille-champs">
    <?php foreach ($def['champs'] as $c => $f): $o = ['required' => !empty($f['requis']), 'error' => $err[$c] ?? null, 'hint' => $f['aide'] ?? null]; ?>
        <?php if ($f['type'] === 'bool'): ?>
            <div class="champ"><span class="label-vide" aria-hidden="true">&nbsp;</span><?= coche($f['libelle'], $c, (bool) $val($c)) ?></div>
        <?php elseif ($f['type'] === 'select'): $id = 'f_' . $c; ?>
            <div class="champ<?= isset($err[$c]) ? ' en-erreur' : '' ?>">
                <label for="<?= $id ?>"><?= e($f['libelle']) ?><?= !empty($f['requis']) ? ' <span class="oblig" title="obligatoire">*</span>' : '' ?></label>
                <select id="<?= $id ?>" name="<?= e($c) ?>"<?= isset($err[$c]) ? ' aria-invalid="true" aria-describedby="' . $id . '-err"' : '' ?>>
                    <option value="">—</option>
                    <?php foreach ($options[$c] as $oid => $olib): ?><option value="<?= (int) $oid ?>"<?= attr((string) $oid === (string) $val($c), 'selected') ?>><?= e($olib) ?></option><?php endforeach; ?>
                </select>
                <?php if (isset($err[$c])): ?><p class="msg-erreur" id="<?= $id ?>-err"><?= e($err[$c]) ?></p><?php endif; ?>
            </div>
        <?php else: ?>
            <?php $vv = $val($c); if ($f['type'] === 'number' && is_numeric($vv)) { $vv = number_format((float) $vv, 2, ',', ''); } ?>
            <?= champ($f['libelle'], $c, $vv, $o + ['type' => $f['type'] === 'number' ? 'text' : $f['type'], 'maxlength' => $f['max'] ?? null] + ($f['type'] === 'number' ? ['inputmode' => 'decimal'] : [])) ?>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>

    <?php if ($def['table'] === 'section'): ?>
        <h2>Créneaux</h2>
        <p class="aide">Jours et heures de cours (affichés sur les listes de présence). Laissez le jour vide pour retirer une ligne.</p>
        <div class="creneaux">
        <?php $lignes = array_pad($creneaux, max(4, count($creneaux) + 1), ['jour' => '', 'heure' => '']); foreach ($lignes as $k => $cr): ?>
            <div class="creneau">
                <label for="cr_j<?= $k ?>" class="visuellement-cache">Jour <?= $k + 1 ?></label>
                <select id="cr_j<?= $k ?>" name="creneaux[<?= $k ?>][jour]">
                    <option value="">—</option>
                    <?php foreach (Referentiel::JOURS as $j => $lib): ?><option value="<?= $j ?>"<?= attr((string) $j === (string) $cr['jour'], 'selected') ?>><?= e($lib) ?></option><?php endforeach; ?>
                </select>
                <label for="cr_h<?= $k ?>" class="visuellement-cache">Heure <?= $k + 1 ?></label>
                <input type="time" id="cr_h<?= $k ?>" name="creneaux[<?= $k ?>][heure]" value="<?= e($cr['heure']) ?>">
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer</button>
        <a class="btn" href="<?= e(url('/parametrage/' . $cle)) ?>">Annuler</a>
    </div>
</form>
<?php if ($l): ?>
    <?php if ($usage): ?>
        <p class="aide">Utilisée par <?= e(implode(', ', $usage)) ?> : elle ne peut pas être supprimée.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url($action . '/supprimer')) ?>" class="zone-danger" data-confirmer="Supprimer cette <?= e($def['un']) ?> ?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">Supprimer</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
