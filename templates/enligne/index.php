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
    </div>
    <div class="grille">
        <div class="champ<?= isset($err['saison_id']) ? ' en-erreur' : '' ?>">
            <label for="f_saison_id">Saison des inscriptions en ligne</label>
            <select id="f_saison_id" name="saison_id">
                <?php foreach ($saisons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr((int) $s['id'] === $saisonId, 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <?= champ('Message affiché sur les formulaires (facultatif)', 'message', $message, ['type' => 'textarea', 'rows' => 2, 'hint' => 'Par exemple : chèques à l\'ordre de l\'ASCS, certificat médical, dates du forum…']) ?>

    <?php foreach ([0 => 'Activités proposées à l\'inscription', 1 => 'Activités proposées en cours d\'essai (sections « essai »)'] as $essai => $titre): ?>
    <h3><?= e($titre) ?></h3>
    <div class="table-cadre"><table class="table-reglages">
        <thead><tr><th>Ouverte</th><th>Section</th><?php if (!$essai): ?><th class="num">Tarif</th><?php endif; ?><th>Texte affiché (horaires, public…)</th><?php if (!$essai): ?><th>Lien de paiement HelloAsso</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($sections as $s): if ((int) $s['essai'] !== $essai) { continue; } $sid = (int) $s['id']; ?>
            <tr>
                <td><input type="checkbox" id="ouv-<?= $sid ?>" name="s[<?= $sid ?>][ouvert]" value="1"<?= attr((bool) $s['ouvert'], 'checked') ?> aria-label="Ouvrir <?= e($s['titre']) ?> en ligne"></td>
                <td><label for="ouv-<?= $sid ?>"><?= e($s['titre']) ?></label></td>
                <?php if (!$essai): ?><td class="num"><?= e(money($s['montant'])) ?></td><?php endif; ?>
                <td><input type="text" name="s[<?= $sid ?>][description]" value="<?= e($s['description']) ?>" maxlength="500" aria-label="Texte affiché pour <?= e($s['titre']) ?>"></td>
                <?php if (!$essai): ?><td><input type="url" name="s[<?= $sid ?>][lien_paiement]" value="<?= e($s['lien_paiement']) ?>" maxlength="255" placeholder="https://www.helloasso.com/…" aria-label="Lien de paiement pour <?= e($s['titre']) ?>"<?= isset($err['lien_' . $sid]) ? ' aria-invalid="true"' : '' ?>></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endforeach; ?>
    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer les réglages</button></div>
</form>
