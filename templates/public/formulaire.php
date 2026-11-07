<?php
/** @var string $type @var array $s @var array $err @var array $sections @var array $canaux */
$essai = $type === 'essai';
$libelles = ['sections' => 'Activité', 'nom' => 'Nom', 'prenom' => 'Prénom', 'genre' => 'Genre', 'date_naissance' => 'Date de naissance',
    'mail' => 'Mail', 'telephone1' => 'Téléphone', 'adresse' => 'Adresse', 'code_postal' => 'Code postal', 'ville' => 'Ville',
    'nom_parent' => 'Nom du parent', 'prenom_parent' => 'Prénom du parent', 'date_essai' => 'Date souhaitée',
    'droit_image' => 'Droit à l\'image', 'consentement' => 'Accord'];
$cibles = ['sections' => $essai ? 'sec-0' : 'sec-0', 'genre' => 'genre-0', 'droit_image' => 'di-1', 'consentement' => 'f_consentement'];
?>
<h1><?= $essai ? 'Demande de cours d\'essai' : 'Inscription' ?> <small>saison <?= e($saison) ?></small></h1>
<p class="intro"><?= $essai
    ? 'Le cours d\'essai est gratuit. Remplissez ce formulaire : le bureau vous recontacte pour confirmer la date.'
    : 'Remplissez ce formulaire pour vous inscrire, ou inscrire votre enfant. Le bureau valide l\'inscription et vous indique comment régler si ce n\'est pas déjà fait.' ?></p>
<?php if ($message): ?><p class="flash flash-info"><?= nl2br(e($message)) ?></p><?php endif; ?>

<?php if ($err): ?>
<div class="flash flash-erreur resume-erreurs" role="alert" tabindex="-1" id="resume-erreurs">
    <?php if (isset($err['_'])): ?><p><?= e($err['_']) ?></p><?php else: ?>
    <p><strong>Le formulaire n'a pas été envoyé.</strong> Corrigez <?= count($err) > 1 ? 'les ' . count($err) . ' points suivants' : 'le point suivant' ?> :</p>
    <ul><?php foreach ($err as $k => $m): ?><li><a href="#<?= e($cibles[$k] ?? 'f_' . $k) ?>"><?= e(($libelles[$k] ?? $k) . ' : ' . $m) ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$sections): ?>
    <p class="vide">Aucune activité n'est ouverte en ligne pour le moment. Adressez-vous au bureau de l'association.</p>
<?php else: ?>
<form method="post" action="<?= e(url($essai ? '/essai' : '/inscription')) ?>" class="form-public" novalidate>
    <?= csrf_field() ?>
    <div class="piege" aria-hidden="true"><label for="site_web">Ne pas remplir</label><input type="text" id="site_web" name="site_web" tabindex="-1" autocomplete="off"></div>

    <fieldset class="<?= isset($err['sections']) ? 'en-erreur' : '' ?>">
        <legend><?= $essai ? 'Activité à essayer' : 'Activités' ?> <span class="oblig">*</span></legend>
        <?php if (!$essai): ?><p class="aide">Vous pouvez en cocher plusieurs.</p><?php endif; ?>
        <div class="cartes-activites">
        <?php foreach ($sections as $i => $sec): $id = 'sec-' . $i; ?>
            <label class="carte-activite" for="<?= $id ?>">
                <?php if ($essai): ?>
                    <input type="radio" id="<?= $id ?>" name="section" value="<?= (int) $sec['id'] ?>"<?= attr((string) $s['section'] === (string) $sec['id'], 'checked') ?>>
                <?php else: ?>
                    <input type="checkbox" id="<?= $id ?>" name="sections[]" value="<?= (int) $sec['id'] ?>"<?= attr(in_array((string) $sec['id'], (array) $s['sections'], true), 'checked') ?>>
                <?php endif; ?>
                <span class="carte-texte">
                    <strong><?= e(preg_replace('/^Z\s+ESSAI\s+/i', '', $sec['titre'])) ?></strong>
                    <?php if (!$essai && (float) $sec['montant'] > 0): ?><span class="prix"><?= e(money($sec['montant'])) ?></span><?php endif; ?>
                    <?php if ($sec['description']): ?><span class="desc"><?= nl2br(e($sec['description'])) ?></span><?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
        </div>
        <?php if (isset($err['sections'])): ?><p class="msg-erreur"><?= e($err['sections']) ?></p><?php endif; ?>
        <?php if ($essai): ?>
            <?= champ('Date souhaitée (facultatif)', 'date_essai', $s['date_essai'], ['type' => 'date', 'min' => date('Y-m-d'), 'error' => $err['date_essai'] ?? null]) ?>
        <?php endif; ?>
    </fieldset>

    <fieldset>
        <legend><?= $essai ? 'Personne qui vient essayer' : 'Adhérent' ?></legend>
        <div class="grille">
            <?= champ('Nom', 'nom', $s['nom'], ['required' => true, 'maxlength' => 100, 'autocomplete' => 'family-name', 'error' => $err['nom'] ?? null]) ?>
            <?= champ('Prénom', 'prenom', $s['prenom'], ['required' => true, 'maxlength' => 100, 'autocomplete' => 'given-name', 'error' => $err['prenom'] ?? null]) ?>
            <?= champ('Date de naissance', 'date_naissance', $s['date_naissance'], ['type' => 'date', 'required' => true, 'max' => date('Y-m-d'), 'error' => $err['date_naissance'] ?? null]) ?>
            <fieldset class="champ groupe-radio<?= isset($err['genre']) ? ' en-erreur' : '' ?>">
                <legend>Genre <span class="oblig">*</span></legend>
                <label for="genre-0"><input type="radio" id="genre-0" name="genre" value="0"<?= attr($s['genre'] === '0', 'checked') ?>> Femme</label>
                <label for="genre-1"><input type="radio" id="genre-1" name="genre" value="1"<?= attr($s['genre'] === '1', 'checked') ?>> Homme</label>
                <?php if (isset($err['genre'])): ?><p class="msg-erreur"><?= e($err['genre']) ?></p><?php endif; ?>
            </fieldset>
        </div>
    </fieldset>

    <fieldset id="bloc-parent" data-bloc-parent>
        <legend>Parent ou responsable légal</legend>
        <p class="aide">Obligatoire si <?= $essai ? 'la personne' : 'l\'adhérent' ?> a moins de 18 ans. Le mail et le téléphone ci-dessous sont alors ceux du parent.</p>
        <div class="grille">
            <?= champ('Nom du parent', 'nom_parent', $s['nom_parent'], ['maxlength' => 100, 'error' => $err['nom_parent'] ?? null]) ?>
            <?= champ('Prénom du parent', 'prenom_parent', $s['prenom_parent'], ['maxlength' => 100, 'error' => $err['prenom_parent'] ?? null]) ?>
        </div>
    </fieldset>

    <fieldset>
        <legend>Contact</legend>
        <div class="grille">
            <?= champ('Mail', 'mail', $s['mail'], ['type' => 'email', 'required' => true, 'maxlength' => 150, 'autocomplete' => 'email', 'error' => $err['mail'] ?? null]) ?>
            <?= champ('Téléphone', 'telephone1', $s['telephone1'], ['type' => 'tel', 'required' => true, 'maxlength' => 30, 'autocomplete' => 'tel', 'error' => $err['telephone1'] ?? null]) ?>
        </div>
        <div class="grille">
            <?= champ('Adresse', 'adresse', $s['adresse'], ['required' => !$essai, 'maxlength' => 255, 'autocomplete' => 'street-address', 'error' => $err['adresse'] ?? null]) ?>
            <?= champ('Code postal', 'code_postal', $s['code_postal'], ['required' => !$essai, 'maxlength' => 5, 'inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'error' => $err['code_postal'] ?? null]) ?>
            <?= champ('Ville', 'ville', $s['ville'], ['required' => !$essai, 'maxlength' => 100, 'autocomplete' => 'address-level2', 'error' => $err['ville'] ?? null]) ?>
        </div>
    </fieldset>

    <fieldset>
        <legend>Pour finir</legend>
        <div class="champ">
            <label for="f_canal_id">Comment avez-vous connu l'ASCS ? (facultatif)</label>
            <select id="f_canal_id" name="canal_id">
                <option value="">—</option>
                <?php foreach ($canaux as $c): ?><option value="<?= (int) $c['id'] ?>"<?= attr((string) $s['canal_id'] === (string) $c['id'], 'selected') ?>><?= e($c['libelle']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if (!$essai): ?>
        <fieldset class="champ groupe-radio<?= isset($err['droit_image']) ? ' en-erreur' : '' ?>">
            <legend>Droit à l'image : l'ASCS peut-elle publier des photos prises pendant les activités ? <span class="oblig">*</span></legend>
            <label for="di-1"><input type="radio" id="di-1" name="droit_image" value="1"<?= attr($s['droit_image'] === '1', 'checked') ?>> Oui</label>
            <label for="di-0"><input type="radio" id="di-0" name="droit_image" value="0"<?= attr($s['droit_image'] === '0', 'checked') ?>> Non</label>
            <?php if (isset($err['droit_image'])): ?><p class="msg-erreur"><?= e($err['droit_image']) ?></p><?php endif; ?>
        </fieldset>
        <?php endif; ?>
        <?= champ('Remarque (facultatif)', 'remarques', $s['remarques'], ['type' => 'textarea', 'rows' => 3, 'maxlength' => 1000, 'hint' => $essai ? 'Par exemple : jour ou horaire qui vous arrange.' : 'Par exemple : horaire choisi, paiement prévu (chèque, Pass Sport…).']) ?>
        <div class="champ<?= isset($err['consentement']) ? ' en-erreur' : '' ?>">
            <label class="coche" for="f_consentement"><input type="checkbox" id="f_consentement" name="consentement" value="1"<?= attr($s['consentement'] === '1', 'checked') ?>>
                <span>J'accepte que l'ASCS enregistre ces informations pour gérer <?= $essai ? 'ce cours d\'essai' : 'l\'adhésion' ?> et me contacter. Elles ne sont pas transmises à des tiers. <span class="oblig">*</span></span></label>
            <?php if (isset($err['consentement'])): ?><p class="msg-erreur"><?= e($err['consentement']) ?></p><?php endif; ?>
        </div>
    </fieldset>
    <button type="submit" class="btn btn-principal btn-grand"><?= $essai ? 'Envoyer ma demande d\'essai' : 'Envoyer mon inscription' ?></button>
    <p class="aide"><?= $essai ? 'Vous voulez plutôt vous inscrire ? ' : 'Vous voulez d\'abord essayer ? ' ?><a href="<?= e(url($essai ? '/inscription' : '/essai')) ?>"><?= $essai ? 'Formulaire d\'inscription' : 'Demander un cours d\'essai gratuit' ?></a></p>
</form>
<?php endif; ?>
