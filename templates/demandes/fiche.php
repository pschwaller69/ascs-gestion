<?php
$essai = $d['type'] === 'essai';
$creer = $post ? array_map('strval', (array) ($post['creer'] ?? [])) : array_map(static fn ($s) => (string) $s['id'], $d['liste_sections']);
$montants = (array) ($post['montant'] ?? []);
$rillieuxDemande = Ascs\Domain\Tarifs::habitantRillieux($d['ville'] ?? null, $d['code_postal'] ?? null);
?>
<div class="titre-page">
    <h1><?= $essai ? 'Demande de cours d\'essai' : 'Demande d\'inscription' ?> <small>réf. <?= e($d['reference']) ?></small></h1>
    <a class="btn" href="<?= e(url('/demandes')) ?>">Retour à la liste</a>
</div>
<div class="deux-colonnes">
<section class="fiche">
    <h2>Saisi par la personne</h2>
    <table class="infos">
        <tr><th>Reçue le</th><td><?= e(dfr($d['cree_le'])) ?> à <?= e(substr((string) $d['cree_le'], 11, 5)) ?></td></tr>
        <tr><th>Nom</th><td><strong><?= e(trim($d['nom'] . ' ' . $d['prenom'])) ?></strong> (<?= (int) $d['genre'] === 0 ? 'femme' : 'homme' ?>)</td></tr>
        <tr><th>Date de naissance</th><td><?= e(dfr($d['date_naissance'])) ?> (<?= (int) age_from($d['date_naissance']) ?> ans)</td></tr>
        <?php if ($d['nom_parent']): ?><tr><th>Parent</th><td><?= e(trim($d['nom_parent'] . ' ' . $d['prenom_parent'])) ?></td></tr><?php endif; ?>
        <tr><th>Mail / téléphone</th><td><?= e($d['mail']) ?><br><?= e($d['telephone']) ?></td></tr>
        <tr><th>Adresse</th><td><?= e(trim($d['adresse'] . ' ' . $d['code_postal'] . ' ' . $d['ville'])) ?></td></tr>
        <tr><th><?= $essai ? 'Activité' : 'Activités' ?></th><td><?= e(implode(', ', array_column($d['liste_sections'], 'titre'))) ?></td></tr>
        <?php if ($d['date_essai']): ?><tr><th>Date souhaitée</th><td><?= e(dfr($d['date_essai'])) ?></td></tr><?php endif; ?>
        <tr><th>Saison</th><td><?= e($d['saison']) ?></td></tr>
        <?php if ($d['droit_image'] !== null): ?><tr><th>Droit à l'image</th><td><?= (int) $d['droit_image'] === 1 ? 'oui' : '<strong>non</strong>' ?></td></tr><?php endif; ?>
        <?php if ($d['canal']): ?><tr><th>A connu l'ASCS par</th><td><?= e($d['canal']) ?></td></tr><?php endif; ?>
        <?php if ($d['remarques']): ?><tr><th>Remarque</th><td><?= nl2br(e($d['remarques'])) ?></td></tr><?php endif; ?>
        <?php if ($c = $d['complement'] ?? null): ?>
            <?php if ($c['telephone2']): ?><tr><th>Tél. domicile</th><td><?= e($c['telephone2']) ?></td></tr><?php endif; ?>
            <tr><th>Attestation CE</th><td><?= $c['attestation_ce'] ? '<strong>oui</strong>' : 'non' ?></td></tr>
            <?php if ($c['canal_autre']): ?><tr><th>Connu aussi par</th><td><?= e($c['canal_autre']) ?></td></tr><?php endif; ?>
            <tr><th>Assurance</th><td><?= $c['assurance'] === 'oui' ? '<strong>souscrit</strong>' . ($c['assurance_option'] ? ', option ' . e($c['assurance_option']) : '') : 'ne souscrit pas' ?></td></tr>
            <tr><th>Règlement prévu</th><td><?= e(preg_replace('/^Règlement prévu : | ; assurance.*$/u', '', Ascs\Domain\Bulletin::resumeReglement($c))) ?>
                <?php $r = $c['recap']; ?><br><small>Total <?= e(money($r['total'])) ?><?= $r['aides'] ? ', aides ' . e(money(array_sum(array_column($r['aides'], 'montant')))) : '' ?><?= $r['frais'] ? ', frais ANCV ' . e(money($r['frais'])) : '' ?> : reste annoncé <strong><?= e(money($r['reste'])) ?></strong></small></td></tr>
            <tr><th>Signature</th><td>« Je soussigné(e) <?= e($c['soussigne']) ?> reconnais avoir pris connaissance du règlement intérieur » — fait à <?= e($c['fait_a'] ?: '?') ?> le <?= e(dfr($c['signe_le'])) ?></td></tr>
        <?php endif; ?>
    </table>
</section>

<section class="fiche">
<?php if ($d['statut'] === 'refuse'): ?>
    <h2>Demande refusée</h2>
    <p>Refusée le <?= e(dfr($d['traite_le'])) ?> par <?= e($d['traite_par']) ?>.</p>
    <form method="post" action="<?= e(url('/demandes/' . $d['id'] . '/retablir')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Remettre à traiter</button></form>
<?php elseif ($d['statut'] === 'valide'): ?>
    <h2>Demande validée</h2>
    <p>Validée le <?= e(dfr($d['traite_le'])) ?> par <?= e($d['traite_par']) ?>.</p>
    <p><a class="btn btn-principal" href="<?= e(url('/adherents/' . $d['adherent_id'], ['onglet' => 'inscriptions'])) ?>">Voir la fiche</a></p>
    <ul><?php foreach ($inscriptions as $i): ?><li><a href="<?= e(url('/inscriptions/' . $i['id'])) ?>"><?= e($i['titre']) ?></a></li><?php endforeach; ?></ul>
<?php else: ?>
    <h2>Validation</h2>
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Corrigez les points signalés.<?= isset($err['creer']) ? ' ' . e($err['creer']) : '' ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('/demandes/' . $d['id'])) ?>">
        <?= csrf_field() ?>
        <fieldset>
            <legend>Adhérent</legend>
            <?php if (isset($err['adherent'])): ?><p class="msg-erreur"><?= e($err['adherent']) ?></p><?php endif; ?>
            <?php foreach ($candidats as $c): $f = $c['fiche']; ?>
                <label class="choix">
                    <input type="radio" name="adherent" value="<?= (int) $c['id'] ?>"<?= attr($choix === (string) $c['id'], 'checked') ?> data-choix-adherent>
                    <span><a href="<?= e(url('/adherents/' . $c['id'])) ?>" target="_blank" rel="noopener"><?= e(trim($f['nom'] . ' ' . $f['prenom'])) ?></a>
                        <?= $f['date_naissance'] ? '— ' . e(dfr($f['date_naissance'])) : '' ?>
                        <span class="pastille pastille-niv-<?= e($c['niveau']) ?>"><?= e($niveaux[$c['niveau']]) ?></span>
                        <small class="aide"><?= e(implode(', ', $c['raisons'])) ?></small></span>
                </label>
            <?php endforeach; ?>
            <label class="choix"><input type="radio" name="adherent" value="nouveau"<?= attr($choix === 'nouveau', 'checked') ?> data-choix-adherent> <span><strong>Nouvelle fiche</strong> (personne absente de la base)</span></label>
            <?php if ($candidats): ?><?= coche('Compléter la fiche choisie avec ces informations (seulement les champs vides)', 'completer', true) ?><?php endif; ?>
        </fieldset>
        <fieldset id="nouvelle-fiche"<?= $choix === 'nouveau' ? '' : ' hidden' ?>>
            <legend>Nouvelle fiche</legend>
            <?php foreach ($fiche as $k => $v): if (in_array($k, ['membre_ca', 'attestation_ce', 'refus_mail', 'refus_sms', 'refus_whatsapp', 'adresse_mail_invalide', 'remarques'], true)) { continue; } ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <p>La fiche sera créée avec les informations saisies par la personne.</p>
            <?php foreach (['nom', 'prenom', 'genre', 'date_naissance', 'mail', 'telephone1', 'code_postal'] as $k): ?>
                <?php if (isset($err[$k])): ?><p class="msg-erreur"><?= e($err[$k]) ?> (vous pourrez corriger la fiche après validation)</p><?php endif; ?>
            <?php endforeach; ?>
        </fieldset>
        <fieldset>
            <legend><?= $essai ? 'Cours d\'essai' : 'Inscriptions à créer' ?></legend>
            <div class="champ">
                <label for="f_saison_id">Saison</label>
                <select id="f_saison_id" name="saison_id">
                    <?php foreach ($saisons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr((string) ($post['saison_id'] ?? $d['saison_id']) === (string) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($d['liste_sections'] as $s): $sid = (int) $s['id']; $m = $montants[$sid] ?? number_format($essai ? 0 : Ascs\Domain\Tarifs::montant((float) $s['montant'], (string) $s['titre'], $rillieuxDemande), 2, ',', ''); ?>
                <div class="ligne-insc<?= isset($err['montant_' . $sid]) ? ' en-erreur' : '' ?>">
                    <label class="coche" for="cr-<?= $sid ?>"><input type="checkbox" id="cr-<?= $sid ?>" name="creer[]" value="<?= $sid ?>"<?= attr(in_array((string) $sid, $creer, true), 'checked') ?>> <span><?= e($s['titre']) ?></span></label>
                    <label class="visuellement-cache" for="m-<?= $sid ?>">Montant pour <?= e($s['titre']) ?></label>
                    <input type="text" id="m-<?= $sid ?>" name="montant[<?= $sid ?>]" value="<?= e($m) ?>" inputmode="decimal" class="montant-court"> €
                    <?php if (isset($err['montant_' . $sid])): ?><p class="msg-erreur"><?= e($err['montant_' . $sid]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p class="aide"><?= $essai ? 'Le cours d\'essai est enregistré comme une inscription gratuite dans la section « essai ».' : 'Montant = tarif complet de la section' . ($rillieuxDemande ? ', avec la réduction Rillieux de 10 € (sauf jogging et marche)' : ', tarif extérieur (adresse hors Rillieux)') . '. Les règlements se saisissent ensuite sur chaque inscription (ou arrivent par HelloAsso).' ?></p>
        </fieldset>
        <div class="actions">
            <button type="submit" class="btn btn-principal" name="suite" value="suivante"><?= icon('check') ?> Valider et passer à la suivante</button>
            <button type="submit" class="btn">Valider et voir la fiche</button>
        </div>
    </form>
    <form method="post" action="<?= e(url('/demandes/' . $d['id'] . '/refuser')) ?>" class="zone-danger">
        <?= csrf_field() ?><button type="submit" class="btn btn-danger">Refuser cette demande</button>
        <span class="aide">Demande fantaisiste, en double ou déjà traitée autrement.</span>
    </form>
<?php endif; ?>
</section>
</div>
