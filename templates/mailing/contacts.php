<?php /** @var bool $configure @var int $saisons @var array $derniere @var int $nbVoulus @var int $nbSynchro @var string $boite */ ?>
<div class="titre-page"><h1>Contacts Outlook</h1></div>
<p class="contexte">Les adhérents sont recopiés dans un dossier de contacts « ASCS » de la boîte Microsoft 365 de l'association, et tenus à jour automatiquement (nouvel adhérent, numéro modifié, personne qui n'est plus inscrite). Outlook sur PC, Outlook web et l'application Outlook du téléphone le voient. Les autres contacts de la boîte ne sont jamais touchés.</p>

<?php if (!$configure): ?>
<section class="fiche">
    <h2>À configurer une fois</h2>
    <p>La synchronisation n'est pas encore branchée : il manque les lignes <code>M365_</code> dans le fichier <code>.env</code> du serveur (voir le README, partie « Contacts Outlook »). En attendant, l'export manuel reste disponible ci-dessous.</p>
</section>
<?php else: ?>
<section class="fiche">
    <h2>Synchronisation</h2>
    <p>Boîte : <strong><?= e($boite) ?></strong>, dossier de contacts « ASCS ».<br>
        <?= (int) $nbSynchro ?> contact(s) synchronisé(s) · <?= (int) $nbVoulus ?> adhérent(s) à avoir dans Outlook.<br>
        Dernière synchronisation : <?= $derniere['quand'] ? e(dfr((string) $derniere['quand'])) . ' à ' . e(substr((string) $derniere['quand'], 11, 5)) . ' — ' . ($derniere['ok'] ? '' : '<strong class="texte-erreur">') . e((string) $derniere['resume']) . ($derniere['ok'] ? '' : '</strong>') : 'jamais' ?></p>
    <p class="aide">Une tâche planifiée la refait chaque soir à 19 h. Le premier passage peut demander deux ou trois clics (400 contacts par passage).</p>
    <form method="post" action="<?= e(url('/contacts')) ?>"><?= csrf_field() ?>
        <button class="btn btn-principal" type="submit" name="action" value="synchroniser"><?= icon('download') ?> Synchroniser maintenant</button>
    </form>
</section>
<?php endif; ?>

<section class="fiche">
    <h2>Qui mettre dans Outlook</h2>
    <form method="post" action="<?= e(url('/contacts')) ?>" class="ligne-reglage"><?= csrf_field() ?>
        <label class="choix-radio"><input type="radio" name="saisons" value="1"<?= attr($saisons === 1, 'checked') ?>> Les adhérents de la saison en cours</label>
        <label class="choix-radio"><input type="radio" name="saisons" value="2"<?= attr($saisons === 2, 'checked') ?>> Saison en cours et précédente (pour garder les anciens à relancer)</label>
        <button class="btn btn-petit" type="submit" name="action" value="regler">Enregistrer</button>
    </form>
    <p class="aide">Chaque contact : nom, prénom, mail, portable et autre téléphone, adresse ; société « ASCS », service = ses sections, catégories = « ASCS » et ses sections ; notes = n° d'adhérent et parent pour un mineur. Une modification faite dans Outlook est remplacée à la synchronisation suivante : corrigez plutôt la fiche ici.</p>
</section>

<section class="fiche">
    <h2>Sur le téléphone</h2>
    <p>Dans l'application <strong>Outlook</strong> : photo du compte &gt; Réglages (⚙) &gt; le compte de l'association &gt; activer <strong>Synchroniser les contacts</strong>. Les adhérents apparaissent alors dans les Contacts d'Android : un appel ou un SMS d'un adhérent affiche son nom et sa section.</p>
</section>

<section class="fiche">
    <h2>Export manuel</h2>
    <p><a class="btn" href="<?= e(url('/contacts/outlook')) ?>"><?= icon('download') ?> Fichier CSV pour Outlook</a> <span class="aide">(Fichier &gt; Ouvrir et exporter &gt; Importer, « remplacer les doublons »)</span></p>
</section>
