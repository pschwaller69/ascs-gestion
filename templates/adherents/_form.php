<?php /** @var array $d @var array $err @var string $action @var ?int $id */ ?>
<div class="zone-doublons" id="zone-doublons" data-url="<?= e(url('/adherents/doublons')) ?>" data-sauf="<?= (int) ($id ?? 0) ?>" aria-live="polite" hidden></div>
<form class="fiche" method="post" action="<?= e($action) ?>" id="form-adherent">
    <?= csrf_field() ?>
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Le formulaire contient des erreurs : corrigez les champs signalés.</p><?php endif; ?>
    <fieldset>
        <legend>Identité</legend>
        <div class="grille">
            <?= champ('Nom', 'nom', $d['nom'], ['required' => true, 'maxlength' => 100, 'error' => $err['nom'] ?? null, 'autocomplete' => 'off']) ?>
            <?= champ('Prénom', 'prenom', $d['prenom'], ['maxlength' => 100, 'error' => $err['prenom'] ?? null, 'autocomplete' => 'off']) ?>
            <div class="champ<?= isset($err['genre']) ? ' en-erreur' : '' ?>">
                <label for="f_genre">Genre <span class="oblig" title="obligatoire">*</span></label>
                <select id="f_genre" name="genre" required<?= isset($err['genre']) ? ' aria-invalid="true" aria-describedby="f_genre-err"' : '' ?>>
                    <option value="">Choisir…</option>
                    <option value="0"<?= attr((string) $d['genre'] === '0', 'selected') ?>>Femme</option>
                    <option value="1"<?= attr((string) $d['genre'] === '1', 'selected') ?>>Homme</option>
                </select>
                <?php if (isset($err['genre'])): ?><p class="msg-erreur" id="f_genre-err"><?= e($err['genre']) ?></p><?php endif; ?>
            </div>
            <?= champ('Date de naissance', 'date_naissance', substr((string) $d['date_naissance'], 0, 10), ['type' => 'date', 'error' => $err['date_naissance'] ?? null, 'min' => '1900-01-01', 'max' => date('Y-m-d')]) ?>
        </div>
    </fieldset>
    <fieldset>
        <legend>Contact</legend>
        <div class="grille">
            <?= champ('Mail', 'mail', $d['mail'], ['type' => 'email', 'maxlength' => 150, 'error' => $err['mail'] ?? null, 'hint' => 'Un mail de parent peut être partagé par plusieurs enfants.']) ?>
            <?= champ('Téléphone 1', 'telephone1', $d['telephone1'], ['type' => 'tel', 'maxlength' => 30, 'error' => $err['telephone1'] ?? null]) ?>
            <?= champ('Téléphone 2', 'telephone2', $d['telephone2'], ['type' => 'tel', 'maxlength' => 30, 'error' => $err['telephone2'] ?? null]) ?>
        </div>
        <div class="grille">
            <?= champ('Adresse', 'adresse', $d['adresse'], ['maxlength' => 255, 'class' => 'large', 'error' => $err['adresse'] ?? null]) ?>
            <?= champ('Code postal', 'code_postal', $d['code_postal'], ['maxlength' => 5, 'inputmode' => 'numeric', 'error' => $err['code_postal'] ?? null]) ?>
            <?= champ('Ville', 'ville', $d['ville'], ['maxlength' => 100]) ?>
        </div>
    </fieldset>
    <fieldset>
        <legend>Parent <button type="button" class="btn btn-petit" id="copie-parent" title="Copier le nom et le prénom de l'adhérent">Copie</button></legend>
        <div class="grille">
            <?= champ('Nom du parent', 'nom_parent', $d['nom_parent'], ['maxlength' => 100]) ?>
            <?= champ('Prénom du parent', 'prenom_parent', $d['prenom_parent'], ['maxlength' => 100]) ?>
        </div>
    </fieldset>
    <fieldset>
        <legend>Association</legend>
        <div class="coches">
            <?= coche('Membre du CA', 'membre_ca', (bool) $d['membre_ca']) ?>
            <?= coche('Attestation CE', 'attestation_ce', (bool) $d['attestation_ce']) ?>
            <?= coche('Refuse les mails', 'refus_mail', (bool) $d['refus_mail']) ?>
            <?= coche('Refuse les SMS', 'refus_sms', (bool) $d['refus_sms']) ?>
            <?= coche('Refuse WhatsApp', 'refus_whatsapp', (bool) $d['refus_whatsapp']) ?>
            <?= coche('Adresse mail invalide', 'adresse_mail_invalide', (bool) $d['adresse_mail_invalide']) ?>
        </div>
        <?= champ('Remarques', 'remarques', $d['remarques'], ['type' => 'textarea', 'rows' => 3]) ?>
    </fieldset>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Valider</button>
        <button type="submit" name="suite" value="inscription" class="btn">Valider + création inscription</button>
        <a class="btn" href="<?= e(url($id ? '/adherents/' . $id : '/adherents')) ?>">Annuler</a>
    </div>
</form>
