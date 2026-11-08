<?php $edition = $r !== null; $reste = round((float) $i['montant'] - (float) $i['regle'], 2); ?>
<h1><?= $edition ? 'Règlement' : 'Nouveau règlement' ?><?= $edition ? ' <small>n° ' . (int) $r['id'] . '</small>' : '' ?></h1>
<p class="contexte">
    <a href="<?= e(url('/adherents/' . $i['adherent_id'])) ?>"><strong><?= e(trim(($i['nom'] ?? '') . ' ' . ($i['prenom'] ?? ''))) ?></strong></a>
    · <a href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['section']) ?> (<?= e($i['saison']) ?>)</a>
    · inscription <?= e(money($i['montant'])) ?>, réglé <?= e(money($i['regle'])) ?> · <?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?>
</p>
<form class="fiche" method="post" action="<?= e($edition ? url('/reglements/' . $r['id']) : url('/reglements')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="inscription_id" value="<?= (int) $i['id'] ?>">
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Le formulaire contient des erreurs : corrigez les champs signalés.</p><?php endif; ?>
    <div class="aides" role="group" aria-labelledby="aides-titre">
        <span id="aides-titre" class="aide">Aide :</span>
        <?php foreach ($aides as $a): ?>
            <button type="button" class="btn btn-petit<?= $a['conseille'] ? ' conseille' : '' ?>" data-aide data-mode="<?= (int) $a['mode'] ?>" data-montant="<?= e(number_format($a['montant'], 2, ',', '')) ?>" data-reference="<?= e($a['reference']) ?>"<?= $a['conseille'] ? ' title="Correspond à la famille de l\'activité"' : '' ?>><?= e($a['libelle']) ?> <?= e(money($a['montant'])) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="grille">
        <div class="champ<?= isset($err['mode_reglement_id']) ? ' en-erreur' : '' ?>">
            <label for="f_mode_reglement_id">Moyen de paiement <span class="oblig" title="obligatoire">*</span></label>
            <select id="f_mode_reglement_id" name="mode_reglement_id" required data-mode-cheque="<?= (int) $modeCheque ?>"<?= isset($err['mode_reglement_id']) ? ' aria-invalid="true" aria-describedby="f_mode-err"' : '' ?>>
                <option value="">Choisir…</option>
                <?php foreach ($modes as $m): $def = $edition ? null : ($parMode[(int) $m['id']] ?? null); ?><option value="<?= (int) $m['id'] ?>"<?= $def ? ' data-montant="' . e(number_format($def['montant'], 2, ',', '')) . '" data-reference="' . e($def['reference']) . '"' : '' ?><?= attr((string) $d['mode_reglement_id'] === (string) $m['id'], 'selected') ?>><?= e($m['libelle']) ?></option><?php endforeach; ?>
            </select>
            <?php if (isset($err['mode_reglement_id'])): ?><p class="msg-erreur" id="f_mode-err"><?= e($err['mode_reglement_id']) ?></p><?php endif; ?>
        </div>
        <?= champ('Montant', 'montant', $d['montant'], ['required' => true, 'inputmode' => 'decimal', 'error' => $err['montant'] ?? null]) ?>
        <div class="champ<?= isset($err['date_reglement']) ? ' en-erreur' : '' ?>">
            <label for="f_date_reglement">Date <span class="oblig" title="obligatoire">*</span></label>
            <input type="date" id="f_date_reglement" name="date_reglement" value="<?= e($d['date_reglement']) ?>" required<?= isset($err['date_reglement']) ? ' aria-invalid="true" aria-describedby="f_date-err"' : '' ?>>
            <div class="rapide" id="dates-cheque" hidden>
                <span class="aide">Chèque, date de pose :</span>
                <button type="button" class="btn btn-petit" data-pose="1">Prochain 1er</button>
                <button type="button" class="btn btn-petit" data-pose="15">Prochain 15</button>
            </div>
            <p class="aide">Saisissez aussi les règlements à venir dès qu'ils sont sûrs (chèque à poser, coupon ou pass à recevoir) : datés plus tard, ils apparaissent « à venir ».</p>
            <?php if (isset($err['date_reglement'])): ?><p class="msg-erreur" id="f_date-err"><?= e($err['date_reglement']) ?></p><?php endif; ?>
        </div>
        <?= champ('Référence (n° de chèque, de commande…)', 'identifiant', $d['identifiant'], ['maxlength' => 60]) ?>
    </div>
    <?= champ('Remarques', 'remarques', $d['remarques'], ['type' => 'textarea', 'rows' => 2]) ?>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Valider</button>
        <?php if (!$edition): ?><button type="submit" name="suite" value="autre" class="btn">Valider + autre règlement</button><?php endif; ?>
        <a class="btn" href="<?= e(url('/inscriptions/' . $i['id'])) ?>">Annuler</a>
    </div>
</form>
<?php if ($edition): ?>
<form method="post" action="<?= e(url('/reglements/' . $r['id'] . '/supprimer')) ?>" class="zone-danger" data-confirmer="Supprimer ce règlement ?">
    <?= csrf_field() ?><button type="submit" class="btn btn-danger">Supprimer ce règlement</button>
</form>
<?php endif; ?>
<?php if ($autres): ?>
<h2>Autres règlements de cette inscription</h2>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr></thead>
    <tbody>
    <?php foreach ($autres as $x): ?>
        <tr<?= $edition && (int) $x['id'] === (int) $r['id'] ? ' class="courant"' : '' ?>>
            <td><a href="<?= e(url('/reglements/' . $x['id'])) ?>"><?= date_reglement($x['date_reglement']) ?></a></td>
            <td><?= e($x['mode']) ?></td><td><?= e($x['identifiant']) ?></td><td class="num"><?= e(money($x['montant'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
