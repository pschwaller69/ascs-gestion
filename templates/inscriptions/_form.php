<?php /** @var array $d @var array $err @var array $sections @var array $saisons @var string $action @var int $adherentId @var bool $edition */ ?>
<form class="fiche" method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="adherent_id" value="<?= (int) $adherentId ?>">
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Le formulaire contient des erreurs : corrigez les champs signalés.</p><?php endif; ?>
    <div class="grille">
        <div class="champ<?= isset($err['section_id']) ? ' en-erreur' : '' ?>">
            <label for="f_section_id">Section <span class="oblig" title="obligatoire">*</span></label>
            <select id="f_section_id" name="section_id" required data-prix-section<?= isset($err['section_id']) ? ' aria-invalid="true" aria-describedby="f_section_id-err"' : '' ?>>
                <option value="">Choisir…</option>
                <?php foreach ($sections as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" data-montant="<?= e(number_format((float) $s['montant'], 2, ',', '')) ?>"<?= attr((string) $d['section_id'] === (string) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($err['section_id'])): ?><p class="msg-erreur" id="f_section_id-err"><?= e($err['section_id']) ?></p><?php endif; ?>
        </div>
        <div class="champ<?= isset($err['saison_id']) ? ' en-erreur' : '' ?>">
            <label for="f_saison_id">Saison <span class="oblig" title="obligatoire">*</span></label>
            <select id="f_saison_id" name="saison_id" required>
                <?php foreach ($saisons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr((string) $d['saison_id'] === (string) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
            </select>
            <?php if (isset($err['saison_id'])): ?><p class="msg-erreur"><?= e($err['saison_id']) ?></p><?php endif; ?>
        </div>
        <?= champ('Montant (tarif complet)', 'montant', $d['montant'], ['required' => true, 'inputmode' => 'decimal', 'error' => $err['montant'] ?? null, 'hint' => 'Le prix complet de la section, même payé en plusieurs fois.']) ?>
        <?= champ('Date d\'inscription', 'date_inscription', substr((string) $d['date_inscription'], 0, 10), ['type' => 'date', 'error' => $err['date_inscription'] ?? null]) ?>
        <?= champ('Fait à', 'fait_a', $d['fait_a'], ['maxlength' => 60]) ?>
    </div>
    <?= champ('Remarques', 'remarques', $d['remarques'], ['type' => 'textarea', 'rows' => 3]) ?>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Valider</button>
        <button type="submit" name="suite" value="reglement" class="btn">Valider + création règlement</button>
        <a class="btn" href="<?= e(url('/adherents/' . $adherentId)) ?>">Annuler</a>
    </div>
</form>
