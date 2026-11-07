<?php
$annule = in_array($it['etat'], Ascs\HelloAsso\Sync::ANNULE, true);
$paye = array_sum(array_map(static fn ($p) => in_array($p['etat'], Ascs\HelloAsso\Sync::PAYE, true) ? (float) $p['montant'] : 0, $it['paiements']));
?>
<div class="titre-page">
    <h1>Adhésion HelloAsso <small>commande <?= (int) $it['commande_id'] ?></small></h1>
    <a class="btn" href="<?= e(url('/helloasso')) ?>">Retour à la liste</a>
</div>
<?php if ($annule): ?>
    <p class="flash flash-erreur" role="alert">Cette adhésion est <?= $it['etat'] === 'Refunded' ? 'remboursée' : 'annulée' ?> sur HelloAsso (état « <?= e($it['etat']) ?> »). En principe, il faut l'ignorer.</p>
<?php endif; ?>

<div class="deux-colonnes">
<section class="fiche">
    <h2>Reçu de HelloAsso</h2>
    <table class="infos">
        <tr><th>Adhérent</th><td><strong><?= e(trim($it['nom'] . ' ' . $it['prenom'])) ?></strong></td></tr>
        <tr><th>Date de naissance</th><td><?= e(dfr($it['date_naissance'])) ?: '<span class="aide">non fournie</span>' ?></td></tr>
        <tr><th>Payeur</th><td><?= e(trim($it['payeur_nom'] . ' ' . $it['payeur_prenom'])) ?> <span class="aide"><?= e($it['payeur_mail']) ?></span></td></tr>
        <tr><th>Mail / téléphone</th><td><?= e($it['mail']) ?> <?= e($it['telephone']) ?></td></tr>
        <tr><th>Adresse</th><td><?= e(trim($it['adresse'] . ' ' . $it['code_postal'] . ' ' . $it['ville'])) ?></td></tr>
        <tr><th>Formulaire</th><td><?= e($it['formulaire']) ?></td></tr>
        <tr><th>Tarif</th><td><?= e($it['tarif']) ?> : <?= e(money($it['montant'])) ?><?= (float) $it['remise'] > 0 ? ' (remise ' . e(money($it['remise'])) . ' déduite)' : '' ?></td></tr>
        <?php if ($it['detail_options']): ?><tr><th>Options</th><td><?= e($it['detail_options']) ?></td></tr><?php endif; ?>
        <tr><th>Date</th><td><?= e(dfr($it['date_commande'])) ?> à <?= e(substr((string) $it['date_commande'], 11, 5)) ?></td></tr>
        <?php foreach ($it['champs_liste'] as $q => $r): ?>
            <tr><th><?= e($q) ?></th><td><?= e($r) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <h3>Paiements</h3>
    <?php if (!$it['paiements']): ?><p class="aide">Aucun paiement.</p><?php else: ?>
    <table class="infos">
        <?php foreach ($it['paiements'] as $p): ?>
            <tr><th><?= e(dfr($p['date_paiement'])) ?><?= $p['echeance'] ? ' — échéance ' . (int) $p['echeance'] : '' ?></th>
                <td><?= e(money($p['montant'])) ?> <span class="pastille <?= in_array($p['etat'], Ascs\HelloAsso\Sync::PAYE, true) ? 'pastille-ok' : 'pastille-reste' ?>"><?= e($p['etat'] === 'Authorized' ? 'payé' : $p['etat']) ?></span>
                <?= $p['reglement_id'] ? ' <a href="' . e(url('/reglements/' . $p['reglement_id'])) . '">règlement n° ' . (int) $p['reglement_id'] . '</a>' : '' ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</section>

<section class="fiche">
<?php if ($it['statut'] === 'ignore'): ?>
    <h2>Adhésion ignorée</h2>
    <p>Ignorée le <?= e(dfr($it['traite_le'])) ?> par <?= e($it['traite_par']) ?>.</p>
    <form method="post" action="<?= e(url('/helloasso/' . $it['id'] . '/retablir')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Remettre à valider</button></form>
<?php elseif ($it['statut'] !== 'a_traiter'): ?>
    <h2><?= $it['statut'] === 'deja_present' ? 'Déjà saisie dans la base' : 'Validée' ?></h2>
    <p><?= $it['statut'] === 'deja_present' ? 'Un règlement porte déjà la référence de cette commande.' : 'Validée le ' . e(dfr($it['traite_le'])) . ' par ' . e($it['traite_par']) . '.' ?></p>
    <p><a class="btn btn-principal" href="<?= e(url('/inscriptions/' . $it['inscription_id'])) ?>">Voir l'inscription</a></p>
<?php else: ?>
    <h2>Validation</h2>
    <?php if ($err): ?><p class="flash flash-erreur" role="alert">Corrigez les points signalés.</p><?php endif; ?>
    <form method="post" action="<?= e(url('/helloasso/' . $it['id'])) ?>" id="form-validation">
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
            <?php if ($candidats): ?><?= coche('Compléter la fiche choisie avec les informations HelloAsso (seulement les champs vides)', 'completer', true) ?><?php endif; ?>
        </fieldset>

        <fieldset id="nouvelle-fiche"<?= $choix === 'nouveau' ? '' : ' hidden' ?>>
            <legend>Nouvelle fiche</legend>
            <div class="grille">
                <?= champ('Nom', 'nom', $fiche['nom'], ['error' => $err['nom'] ?? null]) ?>
                <?= champ('Prénom', 'prenom', $fiche['prenom']) ?>
                <div class="champ<?= isset($err['genre']) ? ' en-erreur' : '' ?>">
                    <label for="f_genre">Genre <span class="oblig">*</span></label>
                    <select id="f_genre" name="genre">
                        <option value="">Choisir…</option>
                        <option value="0"<?= attr((string) ($fiche['genre'] ?? '') === '0', 'selected') ?>>Femme</option>
                        <option value="1"<?= attr((string) ($fiche['genre'] ?? '') === '1', 'selected') ?>>Homme</option>
                    </select>
                    <?php if (isset($err['genre'])): ?><p class="msg-erreur"><?= e($err['genre']) ?></p><?php endif; ?>
                </div>
                <?= champ('Date de naissance', 'date_naissance', $fiche['date_naissance'], ['type' => 'date', 'error' => $err['date_naissance'] ?? null]) ?>
                <?= champ('Mail', 'mail', $fiche['mail'], ['type' => 'email', 'error' => $err['mail'] ?? null]) ?>
                <?= champ('Téléphone', 'telephone1', $fiche['telephone1'], ['error' => $err['telephone1'] ?? null]) ?>
                <?= champ('Adresse', 'adresse', $fiche['adresse']) ?>
                <?= champ('Code postal', 'code_postal', $fiche['code_postal'], ['error' => $err['code_postal'] ?? null]) ?>
                <?= champ('Ville', 'ville', $fiche['ville']) ?>
                <?= champ('Nom du parent', 'nom_parent', $fiche['nom_parent']) ?>
                <?= champ('Prénom du parent', 'prenom_parent', $fiche['prenom_parent']) ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Inscription</legend>
            <div class="grille">
                <div class="champ<?= isset($err['section_id']) ? ' en-erreur' : '' ?>">
                    <label for="f_section_id">Section <span class="oblig">*</span></label>
                    <select id="f_section_id" name="section_id" required>
                        <option value="">Choisir…</option>
                        <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr($sectionId === (string) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($sectionSource === 'retenue'): ?><p class="aide">Retenue lors d'une validation précédente pour ce formulaire et ce tarif.</p>
                    <?php elseif ($sectionSource === 'devinee'): ?><p class="aide">Proposée d'après le nom du formulaire : vérifiez.</p><?php endif; ?>
                    <?php if (isset($err['section_id'])): ?><p class="msg-erreur"><?= e($err['section_id']) ?></p><?php endif; ?>
                </div>
                <div class="champ<?= isset($err['saison_id']) ? ' en-erreur' : '' ?>">
                    <label for="f_saison_id">Saison <span class="oblig">*</span></label>
                    <select id="f_saison_id" name="saison_id" required>
                        <?php foreach ($saisons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= attr($saisonId === (string) $s['id'], 'selected') ?>><?= e($s['titre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?= champ('Montant de l\'inscription', 'montant', $montant, ['required' => true, 'inputmode' => 'decimal', 'error' => $err['montant'] ?? null, 'hint' => 'Tarif + options, remise déduite. Déjà payé : ' . money($paye) . '.']) ?>
            </div>
        </fieldset>
        <div class="actions">
            <button type="submit" class="btn btn-principal" name="suite" value="suivante"><?= icon('check') ?> Valider et passer à la suivante</button>
            <button type="submit" class="btn">Valider et voir l'inscription</button>
        </div>
    </form>
    <form method="post" action="<?= e(url('/helloasso/' . $it['id'] . '/ignorer')) ?>" class="zone-danger">
        <?= csrf_field() ?><button type="submit" class="btn btn-danger">Ignorer cette adhésion</button>
        <span class="aide">Pour un essai, un doublon de commande ou une adhésion remboursée.</span>
    </form>
<?php endif; ?>
</section>
</div>
