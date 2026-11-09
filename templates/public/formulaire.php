<?php
/** @var string $type @var array $s @var array $err @var array $sections @var array $canaux */
$essai = $type === 'essai';
$libelles = ['sections' => 'Activité', 'nom' => 'Nom', 'prenom' => 'Prénom', 'genre' => 'Genre', 'date_naissance' => 'Date de naissance',
    'mail' => 'Mail', 'telephone1' => 'Téléphone', 'adresse' => 'Adresse', 'code_postal' => 'Code postal', 'ville' => 'Ville',
    'nom_parent' => 'Nom du parent', 'prenom_parent' => 'Prénom du parent', 'date_essai' => 'Date souhaitée',
    'droit_image' => 'Droit à l\'image', 'consentement' => 'Accord', 'telephone2' => 'Tél. domicile', 'assurance' => 'Assurance',
    'moyens' => 'Règlement', 'code_pass_sport' => 'Code Pass\'Sport', 'soussigne' => 'Signature', 'reglement_lu' => 'Règlement intérieur'];
$cibles = ['sections' => 'sec-0', 'genre' => 'genre-0', 'droit_image' => 'di-1', 'consentement' => 'f_consentement', 'assurance' => 'ass-oui',
    'moyens' => 'moy-cheque', 'reglement_lu' => 'f_reglement_lu'];
$coche = static fn (string $k, string $v) => in_array($v, (array) ($s[$k] ?? []), true);
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
                    <input type="checkbox" id="<?= $id ?>" name="sections[]" value="<?= (int) $sec['id'] ?>"<?= attr(in_array((string) $sec['id'], (array) $s['sections'], true), 'checked') ?> data-titre="<?= e($sec['titre']) ?>" data-tarif="<?= e(number_format((float) $sec['montant'], 2, '.', '')) ?>" data-tarif-rillieux="<?= e(number_format(Ascs\Domain\Tarifs::montant((float) $sec['montant'], (string) $sec['titre'], true), 2, '.', '')) ?>">
                <?php endif; ?>
                <span class="carte-texte">
                    <strong><?= e(preg_replace('/^Z\s+ESSAI\s+/i', '', $sec['titre'])) ?></strong>
                    <?php if (!$essai && (float) $sec['montant'] > 0): $rill = Ascs\Domain\Tarifs::montant((float) $sec['montant'], (string) $sec['titre'], true); ?><span class="prix"><?= e(money($sec['montant'])) ?><?= $rill < (float) $sec['montant'] ? ' · ' . e(money($rill)) . ' pour les habitants de Rillieux' : '' ?></span><?php endif; ?>
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
            <?= champ($essai ? 'Téléphone' : 'Tél. portable', 'telephone1', $s['telephone1'], ['type' => 'tel', 'required' => true, 'maxlength' => 30, 'autocomplete' => 'tel', 'error' => $err['telephone1'] ?? null]) ?>
            <?php if (!$essai): ?><?= champ('Tél. domicile (facultatif)', 'telephone2', $s['telephone2'], ['type' => 'tel', 'maxlength' => 30, 'error' => $err['telephone2'] ?? null]) ?><?php endif; ?>
        </div>
        <div class="grille">
            <?= champ('Adresse', 'adresse', $s['adresse'], ['required' => !$essai, 'maxlength' => 255, 'autocomplete' => 'street-address', 'error' => $err['adresse'] ?? null]) ?>
            <?= champ('Code postal', 'code_postal', $s['code_postal'], ['required' => !$essai, 'maxlength' => 5, 'inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'error' => $err['code_postal'] ?? null]) ?>
            <?= champ('Ville', 'ville', $s['ville'], ['required' => !$essai, 'maxlength' => 100, 'autocomplete' => 'address-level2', 'error' => $err['ville'] ?? null]) ?>
        </div>
    </fieldset>

    <?php if ($essai): ?>
    <fieldset>
        <legend>Pour finir</legend>
        <div class="champ">
            <label for="f_canal_id">Comment avez-vous connu l'ASCS ? (facultatif)</label>
            <select id="f_canal_id" name="canal_id">
                <option value="">—</option>
                <?php foreach ($canaux as $c): ?><option value="<?= (int) $c['id'] ?>"<?= attr((string) $s['canal_id'] === (string) $c['id'], 'selected') ?>><?= e($c['libelle']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?= champ('Remarque (facultatif)', 'remarques', $s['remarques'], ['type' => 'textarea', 'rows' => 3, 'maxlength' => 1000, 'hint' => 'Par exemple : jour ou horaire qui vous arrange.']) ?>
        <div class="champ<?= isset($err['consentement']) ? ' en-erreur' : '' ?>">
            <label class="coche" for="f_consentement"><input type="checkbox" id="f_consentement" name="consentement" value="1"<?= attr($s['consentement'] === '1', 'checked') ?>>
                <span>J'accepte que l'ASCS enregistre ces informations pour gérer ce cours d'essai et me contacter. Elles ne sont pas transmises à des tiers. <span class="oblig">*</span></span></label>
            <?php if (isset($err['consentement'])): ?><p class="msg-erreur"><?= e($err['consentement']) ?></p><?php endif; ?>
        </div>
    </fieldset>
    <?php else: ?>
    <fieldset>
        <legend>Quelques questions</legend>
        <fieldset class="champ groupe-radio">
            <legend>Souhaitez-vous une attestation pour votre comité d'entreprise ?</legend>
            <label for="ce-1"><input type="radio" id="ce-1" name="attestation_ce" value="1"<?= attr($s['attestation_ce'] === '1', 'checked') ?>> Oui</label>
            <label for="ce-0"><input type="radio" id="ce-0" name="attestation_ce" value="0"<?= attr($s['attestation_ce'] !== '1', 'checked') ?>> Non</label>
        </fieldset>
        <fieldset class="champ">
            <legend>Où avez-vous connu l'ASCS ? (facultatif)</legend>
            <div class="cases-canaux">
                <?php foreach ($canaux as $c): ?><label for="can-<?= (int) $c['id'] ?>"><input type="checkbox" id="can-<?= (int) $c['id'] ?>" name="canaux[]" value="<?= (int) $c['id'] ?>"<?= attr($coche('canaux', (string) $c['id']), 'checked') ?>> <?= e($c['libelle']) ?></label><?php endforeach; ?>
            </div>
            <?= champ('Autre', 'canal_autre', $s['canal_autre'], ['maxlength' => 100]) ?>
        </fieldset>
        <fieldset class="champ groupe-radio<?= isset($err['droit_image']) ? ' en-erreur' : '' ?>">
            <legend>Droit à l'image : l'ASCS peut-elle publier des photos prises pendant les activités ? <span class="oblig">*</span></legend>
            <label for="di-1"><input type="radio" id="di-1" name="droit_image" value="1"<?= attr($s['droit_image'] === '1', 'checked') ?>> Oui</label>
            <label for="di-0"><input type="radio" id="di-0" name="droit_image" value="0"<?= attr($s['droit_image'] === '0', 'checked') ?>> Non</label>
            <?php if (isset($err['droit_image'])): ?><p class="msg-erreur"><?= e($err['droit_image']) ?></p><?php endif; ?>
        </fieldset>
    </fieldset>

    <fieldset id="bloc-reglement" class="<?= isset($err['moyens']) || isset($err['code_pass_sport']) ? 'en-erreur' : '' ?>">
        <legend>Règlement <span class="oblig">*</span></legend>
        <p class="aide">Comment comptez-vous régler ? Vous pouvez combiner plusieurs moyens et aides. Le paiement se fait auprès du bureau<?= '' ?> (ou en ligne si un lien vous est proposé après l'envoi).</p>
        <div class="cases-moyens">
            <?php foreach (Ascs\Domain\Bulletin::MOYENS as $k => $lib): ?>
                <label for="moy-<?= $k ?>"><input type="checkbox" id="moy-<?= $k ?>" name="moyens[]" value="<?= $k ?>"<?= attr($coche('moyens', $k), 'checked') ?> data-moyen="<?= $k ?>"> <?= e($lib) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="champ" id="bloc-nb-cheques"<?= $coche('moyens', 'cheque') ? '' : ' hidden' ?>>
            <label for="f_nb_cheques">Nombre de chèques</label>
            <p class="aide">En plusieurs fois, les chèques sont encaissés le 1er ou le 15 de chaque mois.</p>
            <select id="f_nb_cheques" name="nb_cheques"><?php for ($n = 1; $n <= 3; $n++): ?><option value="<?= $n ?>"<?= attr((string) $s['nb_cheques'] === (string) $n, 'selected') ?>><?= $n === 1 ? '1 chèque' : $n . ' chèques' ?></option><?php endfor; ?></select>
        </div>
        <p class="aide" id="note-ancv"<?= $coche('moyens', 'ancv') ? '' : ' hidden' ?>>Chèques ANCV et ANCV Connect : frais de gestion de <?= e(money(Ascs\Domain\Bulletin::FRAIS_ANCV)) ?> par dossier.</p>
        <h3 class="sous-legende">Aides financières <small>(sous réserve de reconduction)</small></h3>
        <?php foreach ($aides as $a): ?>
            <div class="aide-choix">
                <label for="aide-<?= e($a['cle']) ?>"><input type="checkbox" id="aide-<?= e($a['cle']) ?>" name="aides[]" value="<?= e($a['cle']) ?>"<?= attr($coche('aides', $a['cle']), 'checked') ?> data-aide-montant="<?= e(number_format($a['montant'], 2, '.', '')) ?>" data-aide-libelle="<?= e($a['libelle']) ?>"> <strong><?= e($a['libelle']) ?></strong> · <?= e(money($a['montant'])) ?></label>
                <p class="aide"><?= e($a['condition']) ?></p>
                <?php if ($a['cle'] === 'pass_sport'): ?><div id="bloc-code-pass"<?= $coche('aides', 'pass_sport') ? '' : ' hidden' ?>><?= champ('Code Pass\'Sport', 'code_pass_sport', $s['code_pass_sport'], ['maxlength' => 30, 'error' => $err['code_pass_sport'] ?? null]) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (isset($err['moyens'])): ?><p class="msg-erreur"><?= e($err['moyens']) ?></p><?php endif; ?>
        <div class="recap" id="recap" aria-live="polite">
            <p class="aide">Cochez vos activités pour voir le montant.</p>
        </div>
    </fieldset>

    <fieldset class="<?= isset($err['assurance']) ? 'en-erreur' : '' ?>">
        <legend>Assurance <span class="oblig">*</span></legend>
        <fieldset class="champ groupe-radio">
            <legend class="visuellement-cache">Assurance « groupements sportifs »</legend>
            <label for="ass-oui"><input type="radio" id="ass-oui" name="assurance" value="oui"<?= attr($s['assurance'] === 'oui', 'checked') ?>> Je souhaite souscrire à l'assurance « groupements sportifs »</label>
            <div id="bloc-option"<?= $s['assurance'] === 'oui' ? '' : ' hidden' ?>><?= champ('Option n°', 'assurance_option', $s['assurance_option'], ['maxlength' => 10, 'inputmode' => 'numeric']) ?></div>
            <label for="ass-non"><input type="radio" id="ass-non" name="assurance" value="non"<?= attr($s['assurance'] === 'non', 'checked') ?>> Je ne souhaite pas souscrire à l'assurance « groupements sportifs » proposée</label>
            <?php if (isset($err['assurance'])): ?><p class="msg-erreur"><?= e($err['assurance']) ?></p><?php endif; ?>
        </fieldset>
    </fieldset>

    <fieldset class="<?= isset($err['reglement_lu']) || isset($err['soussigne']) ? 'en-erreur' : '' ?>">
        <legend>Règlement intérieur et signature <span class="oblig">*</span></legend>
        <details class="reglement-interieur" open>
            <summary>Règlement intérieur <?= e($saison) ?></summary>
            <ul><?php foreach (preg_split('/\R+/', trim($reglement)) as $ligne): ?><li><?= e($ligne) ?></li><?php endforeach; ?></ul>
        </details>
        <?= champ('Je soussigné(e)', 'soussigne', $s['soussigne'], ['required' => true, 'maxlength' => 120, 'autocomplete' => 'name', 'error' => $err['soussigne'] ?? null, 'hint' => 'Nom et prénom de l\'adhérent, ou du parent pour un mineur.']) ?>
        <div class="champ<?= isset($err['reglement_lu']) ? ' en-erreur' : '' ?>">
            <label class="coche" for="f_reglement_lu"><input type="checkbox" id="f_reglement_lu" name="reglement_lu" value="1"<?= attr($s['reglement_lu'] === '1', 'checked') ?>>
                <span>reconnais avoir pris connaissance du règlement intérieur ci-dessus et l'accepte. <span class="oblig">*</span></span></label>
            <?php if (isset($err['reglement_lu'])): ?><p class="msg-erreur"><?= e($err['reglement_lu']) ?></p><?php endif; ?>
        </div>
        <div class="grille">
            <?= champ('Fait à', 'fait_a', $s['fait_a'], ['maxlength' => 60, 'hint' => 'Votre ville, si vide.']) ?>
            <p class="valeur-fixe">Le <?= e(date('d/m/Y')) ?></p>
        </div>
        <div class="champ<?= isset($err['consentement']) ? ' en-erreur' : '' ?>">
            <label class="coche" for="f_consentement"><input type="checkbox" id="f_consentement" name="consentement" value="1"<?= attr($s['consentement'] === '1', 'checked') ?>>
                <span>J'accepte que l'ASCS enregistre ces informations pour gérer l'adhésion et me contacter. Elles ne sont pas transmises à des tiers. <span class="oblig">*</span></span></label>
            <?php if (isset($err['consentement'])): ?><p class="msg-erreur"><?= e($err['consentement']) ?></p><?php endif; ?>
        </div>
        <?= champ('Remarque (facultatif)', 'remarques', $s['remarques'], ['type' => 'textarea', 'rows' => 2, 'maxlength' => 1000, 'hint' => 'Par exemple : jour ou horaire choisi.']) ?>
    </fieldset>
    <?php endif; ?>
    <button type="submit" class="btn btn-principal btn-grand"><?= $essai ? 'Envoyer ma demande d\'essai' : 'Envoyer mon inscription' ?></button>
    <p class="aide"><?= $essai ? 'Vous voulez plutôt vous inscrire ? ' : 'Vous voulez d\'abord essayer ? ' ?><a href="<?= e(url($essai ? '/inscription' : '/essai')) ?>"><?= $essai ? 'Formulaire d\'inscription' : 'Demander un cours d\'essai gratuit' ?></a></p>
</form>
<?php endif; ?>
