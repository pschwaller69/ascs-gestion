<div class="titre-page"><h1>Inscriptions en ligne</h1></div>
<?php $ici = '/parametres/en-ligne'; ?>
<?php $sous = [['/parametres/en-ligne', 'Ouverture et textes'], ['/parametres/activites-en-ligne', 'Activités en ligne'], ['/parametres/aides', 'Aides']]; ?>
<nav class="sous-onglets" aria-label="Réglages des inscriptions en ligne">
    <?php foreach ($sous as [$u, $l]): ?><a href="<?= e(url($u)) ?>"<?= $u === $ici ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
    <a href="<?= e(url('/en-ligne')) ?>">QR codes et affiches ↗</a>
</nav>
<form method="post" action="<?= e(url('/parametres/en-ligne')) ?>" class="fiche">
    <?= csrf_field() ?>
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
    <?= champ('Horaires du bureau (dépôt des chèques)', 'horaires_bureau', $horaires, ['maxlength' => 120, 'hint' => 'Affiché sur le formulaire, la page de fin et le mail de confirmation : « Le bureau est ouvert … ». Vide : ' . Ascs\Domain\Bulletin::HORAIRES_DEFAUT . '.']) ?>
    <?= champ('Communauté WhatsApp de l\'association', 'whatsapp_lien', $whatsapp, ['type' => 'url', 'maxlength' => 255, 'error' => $err['whatsapp_lien'] ?? null, 'hint' => 'Lien d\'invitation (https://chat.whatsapp.com/…). Proposé sur la page de fin et dans le mail de confirmation : « Vous ne trouvez pas le cours ? Rejoignez la communauté WhatsApp ». Même lien que dans Mail (invitations). Vide : pas de lien.']) ?>
    <?= champ('Message affiché sur les formulaires (facultatif)', 'message', $message, ['type' => 'textarea', 'rows' => 2, 'hint' => 'Par exemple : chèques à l\'ordre de l\'ASCS, certificat médical, dates du forum…']) ?>
    <?= champ('Règlement intérieur (accepté et signé en ligne à l\'inscription)', 'reglement_interieur', $reglement, ['type' => 'textarea', 'rows' => 12, 'hint' => 'Un article par ligne, comme sur le bulletin papier. Vide : texte du bulletin 2026/2027.']) ?>

    <div class="actions"><button type="submit" class="btn btn-principal"><?= icon('check') ?> Enregistrer les réglages</button></div>
</form>
