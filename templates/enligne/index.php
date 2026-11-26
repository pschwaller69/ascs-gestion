<script src="<?= e(url('/assets/qrcode.js')) ?>"></script>
<div class="titre-page">
    <h1>Inscriptions en ligne</h1>
    <a class="btn" href="<?= e(url('/demandes')) ?>">Voir les demandes reçues</a>
</div>
<p class="actions"><a class="btn btn-principal" href="<?= e(url('/en-ligne/affiches')) ?>" target="_blank" rel="noopener">Imprimer les deux affiches</a></p>
<p class="contexte">Un seul QR code pour les inscriptions, un seul pour les essais : la personne choisit ensuite son activité dans le formulaire. Les adhérents remplissent eux-mêmes leur inscription ou leur demande de cours d'essai, depuis un QR code ou un lien. Chaque demande attend votre validation dans « Demandes » : rien n'est créé sans vous.</p>

<div class="deux-colonnes qr-apercus">
    <section class="fiche">
        <h2>Inscription</h2>
        <div class="qr" data-qr="<?= e($urlInscription) ?>" role="img" aria-label="QR code vers le formulaire d'inscription"></div>
        <p class="url-qr"><a href="<?= e($urlInscription) ?>" target="_blank" rel="noopener"><?= e($urlInscription) ?></a></p>
        <p class="actions"><a class="btn" href="<?= e(url('/en-ligne/affiches', ['type' => 'inscription'])) ?>" target="_blank" rel="noopener">Affiche A4 à imprimer</a></p>
    </section>
    <section class="fiche">
        <h2>Cours d'essai</h2>
        <div class="qr" data-qr="<?= e($urlEssai) ?>" role="img" aria-label="QR code vers le formulaire de cours d'essai"></div>
        <p class="url-qr"><a href="<?= e($urlEssai) ?>" target="_blank" rel="noopener"><?= e($urlEssai) ?></a></p>
        <p class="actions"><a class="btn" href="<?= e(url('/en-ligne/affiches', ['type' => 'essai'])) ?>" target="_blank" rel="noopener">Affiche A4 à imprimer</a></p>
    </section>
</div>

<form method="post" action="<?= e(url('/en-ligne')) ?>" class="fiche">
    <?= csrf_field() ?>
    <h2>Réglages</h2>
    <div class="coches">
        <?= coche('Inscriptions en ligne ouvertes', 'inscription_ouverte', $inscriptionOuverte) ?>
        <?= coche('Demandes de cours d\'essai ouvertes', 'essai_ouvert', $essaiOuvert) ?>
        <?= coche('Valider seuls les cours d\'essai (fiche trouvée sans doute possible, ou nouvelle fiche ; sinon la demande attend dans Demandes)', 'essai_auto', $essaiAuto) ?>
    </div>
    <div class="grille">
        <div class="champ<?= isset($err['saison_id']) ? ' en-erreur' : '' ?>">
            <label for="f_saison_id">Saison des inscriptions en ligne</label>
            <select id="f_saison_id" name="saison_id">
                <?php foreach ($saisons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr((int) $s['id'] === $saisonId, 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <h3>Aides : dates limites et garantie par carte</h3>
    <p class="aide">Dates au format jj/mm, rapportées à la saison (septembre à décembre : année de début ; janvier à août : année suivante). Date limite dépassée : l'aide n'est plus proposée dans le formulaire. Garantie : quand la personne paie par carte sur HelloAsso, le montant de l'aide est programmé à cette date et annulé dès que vous confirmez l'aide (Règlement &gt; Garanties par carte). Vide : pas de limite, pas de garantie.</p>
    <div class="table-cadre"><table class="table-reglages">
        <thead><tr><th>Aide</th><th>Demande possible jusqu'au</th><th>Garantie par carte le</th></tr></thead>
        <tbody>
        <?php foreach ($aidesDates as $ad): ?>
            <tr><td><?= e($ad['libelle']) ?></td>
                <td><input type="text" name="aide_limite[<?= e($ad['cle']) ?>]" value="<?= e($ad['limite']) ?>" maxlength="5" placeholder="jj/mm" inputmode="numeric" pattern="\d{1,2}/\d{1,2}" aria-label="Date limite pour <?= e($ad['libelle']) ?>"></td>
                <td><input type="text" name="aide_garantie[<?= e($ad['cle']) ?>]" value="<?= e($ad['garantie']) ?>" maxlength="5" placeholder="jj/mm" inputmode="numeric" pattern="\d{1,2}/\d{1,2}" aria-label="Date de garantie pour <?= e($ad['libelle']) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= champ('Horaires du bureau (dépôt des chèques)', 'horaires_bureau', $horaires, ['maxlength' => 120, 'hint' => 'Affiché sur le formulaire, la page de fin et le mail de confirmation : « Le bureau est ouvert … ». Vide : ' . Ascs\Domain\Bulletin::HORAIRES_DEFAUT . '.']) ?>
    <?= champ('Message affiché sur les formulaires (facultatif)', 'message', $message, ['type' => 'textarea', 'rows' => 2, 'hint' => 'Par exemple : chèques à l\'ordre de l\'ASCS, certificat médical, dates du forum…']) ?>
    <?= champ('Règlement intérieur (accepté et signé en ligne à l\'inscription)', 'reglement_interieur', $reglement, ['type' => 'textarea', 'rows' => 12, 'hint' => 'Un article par ligne, comme sur le bulletin papier. Vide : texte du bulletin 2026/2027.']) ?>

    <h3>Activités en ligne</h3>
    <p class="aide">Pour chaque activité : proposée dans le formulaire d'inscription, et/ou dans le formulaire de cours d'essai. Les sections « Z ESSAI » ne servent plus : un cours d'essai validé est noté sur l'activité elle-même (listes de présence des animateurs, fiche de l'adhérent), sans inscription. Dates pour la saison choisie plus haut : la reprise est affichée aux adhérents ; pas de cours d'essai avant « Essais à partir du » (vide : la date de reprise).</p>
    <p class="aide activites-filtre"><?= count(array_filter($sections, static fn ($x) => $x['ouvert'] || $x['essai_ouvert'])) ?> activité(s) en ligne sur <?= count($sections) ?></p>
    <ul class="activites-reglages">
    <?php foreach ($sections as $s): $sid = (int) $s['id']; ?>
        <li class="activite-reglage">
            <div class="ar-tete">
                <span class="ar-titre"><?= e($s['titre']) ?></span>
                <span class="ar-tarif"><?= e(money($s['montant'])) ?></span>
            </div>
            <div class="ar-bascules">
                <label class="bascule"><input type="checkbox" name="s[<?= $sid ?>][ouvert]" value="1"<?= attr((bool) $s['ouvert'], 'checked') ?>><span><?= icon('check') ?> Inscription en ligne</span></label>
                <label class="bascule bascule-essai"><input type="checkbox" name="s[<?= $sid ?>][essai]" value="1"<?= attr((bool) $s['essai_ouvert'], 'checked') ?>><span><?= icon('check') ?> Cours d'essai</span></label>
            </div>
            <div class="ar-dates">
                <label>Reprise des cours<input type="date" name="s[<?= $sid ?>][reprise]" value="<?= e($s['reprise']) ?>"<?= isset($err['reprise_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
                <label>Essais à partir du<input type="date" name="s[<?= $sid ?>][essai_des]" value="<?= e($s['essai_des']) ?>" title="Vide : la date de reprise"<?= isset($err['essai_des_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
            </div>
            <div class="ar-textes">
                <label>Texte affiché (horaires, public…)<input type="text" name="s[<?= $sid ?>][description]" value="<?= e($s['description']) ?>" maxlength="500" placeholder="Ex. : lundi 18 h 30 – 20 h, dès 8 ans"></label>
                <label>Lien de paiement HelloAsso<input type="url" name="s[<?= $sid ?>][lien_paiement]" value="<?= e($s['lien_paiement']) ?>" maxlength="255" placeholder="https://www.helloasso.com/…"<?= isset($err['lien_' . $sid]) ? ' aria-invalid="true"' : '' ?>></label>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer les réglages</button></div>
</form>
