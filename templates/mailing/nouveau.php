<?php use Ascs\Domain\Mailing;
/** @var array $d @var array $err @var array $sections @var array $apercu @var array $reglages @var bool $smsPret */
$sms = $d['canal'] === 'sms';
$exclus = ['refus' => $sms ? 'ont refusé les SMS' : 'ont refusé les mails', 'sans' => $sms ? 'sans numéro de portable' : 'sans mail', 'invalide' => 'mail invalide', 'doublon' => $sms ? 'numéro partagé (un seul SMS)' : 'mail partagé (un seul mail)'];
?>
<div class="titre-page"><h1><?= $sms ? 'Nouveau SMS' : 'Nouveau mail' ?></h1><a class="btn" href="<?= e(url('/mailing')) ?>">Mailings et SMS</a></div>
<?php if (!$sms && $reglages['expediteur'] === ''): ?><p class="flash flash-erreur">L'adresse d'envoi des mails n'est pas renseignée : <a href="<?= e(url('/parametres/facture')) ?>">Paramétrage &gt; Facture</a>.</p><?php endif; ?>
<form class="fiche" method="post" action="<?= e(url('/mailing')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="canal" value="<?= e($d['canal']) ?>">
    <fieldset>
        <legend>Destinataires</legend>
        <?php foreach (Mailing::CIBLES as $k => $lib): ?>
            <label class="choix-radio"><input type="radio" name="cible" value="<?= e($k) ?>"<?= attr($d['cible'] === $k, 'checked') ?>> <?= e($lib) ?></label>
        <?php endforeach; ?>
        <div class="sections-choix<?= isset($err['sections']) ? ' en-erreur' : '' ?>"<?= $d['cible'] === 'section' ? '' : ' hidden' ?> id="choix-sections">
            <?php if (isset($err['sections'])): ?><p class="msg-erreur"><?= e($err['sections']) ?></p><?php endif; ?>
            <?php foreach ($sections as $s): ?>
                <label class="choix-case"><input type="checkbox" name="sections[]" value="<?= (int) $s['id'] ?>"<?= attr(in_array((int) $s['id'], $d['sections'], true), 'checked') ?>> <?= e($s['titre']) ?> <small class="aide">(<?= (int) $s['n'] ?>)</small></label>
            <?php endforeach; ?>
        </div>
        <p class="apercu-dest"><strong><?= count($apercu['retenus']) ?> destinataire<?= count($apercu['retenus']) > 1 ? 's' : '' ?></strong> pour la saison choisie en haut de page<?php
            $ex = array_map(static fn ($k, $n) => $n . ' ' . $exclus[$k], array_keys($apercu['exclus']), $apercu['exclus']);
            echo $ex ? ' · exclus : ' . e(implode(', ', $ex)) : ''; ?>.
            <button type="submit" name="actualiser" value="1" class="btn btn-petit">Recompter</button></p>
    </fieldset>
    <?php if (!$sms): ?><?= champ('Objet', 'objet', $d['objet'], ['required' => true, 'maxlength' => 200, 'error' => $err['objet'] ?? null]) ?><?php endif; ?>
    <?= champ('Message', 'texte', $d['texte'], ['type' => 'textarea', 'rows' => $sms ? 4 : 12, 'required' => true, 'error' => $err['texte'] ?? null,
        'hint' => '{prenom} et {nom} sont remplacés pour chaque destinataire.' . ($sms ? '' : ' Un lien de désinscription est ajouté en bas de chaque mail.')]) ?>
    <?php if ($sms): ?><p class="aide" id="compteur-sms" aria-live="polite"></p><?php endif; ?>
    <div class="actions">
        <button type="submit" class="btn btn-principal"><?= icon('check') ?> Préparer l'envoi</button>
        <a class="btn" href="<?= e(url('/mailing')) ?>">Annuler</a>
    </div>
    <p class="aide">Rien ne part à cette étape : vous verrez la liste des destinataires et validerez ensuite.</p>
</form>
