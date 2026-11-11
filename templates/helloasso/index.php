<?php
$etats = ['a_traiter' => 'À valider', 'importe' => 'Validée', 'deja_present' => 'Déjà saisie', 'ignore' => 'Ignorée'];
$annule = Ascs\HelloAsso\Sync::ANNULE;
?>
<div class="titre-page">
    <h1>Adhésions HelloAsso</h1>
    <?php if ($configure): ?>
    <form method="post" action="<?= e(url('/helloasso/synchroniser')) ?>" class="en-ligne">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-principal"><?= icon('download') ?> Synchroniser maintenant</button>
    </form>
    <?php endif; ?>
</div>

<?php if (!$configure): ?>
    <section class="fiche">
        <h2>Connexion à HelloAsso à configurer</h2>
        <ol class="etapes-liste">
            <li>Sur HelloAsso, dans « Mon compte &gt; Intégrations et API », créez vos identifiants API.</li>
            <li>Dans le fichier <code>.env</code> sur o2switch, renseignez <code>HELLOASSO_CLIENT_ID</code>, <code>HELLOASSO_CLIENT_SECRET</code> et <code>HELLOASSO_ORGANISATION</code> (le nom de l'association dans l'adresse helloasso.com/associations/…).</li>
            <li>Revenez sur cette page et cliquez sur « Synchroniser maintenant ».</li>
        </ol>
    </section>
<?php else: ?>
    <p class="contexte">
        Association « <?= e($organisation) ?> ».
        <?php if ($derniere): ?>
            Dernière synchronisation : <?= e(dfr($derniere['debut'])) ?> à <?= e(substr((string) $derniere['debut'], 11, 5)) ?> (<?= e($derniere['origine']) ?>)
            <?= $derniere['ok'] ? '— ' . e($derniere['resume']) : '— <strong class="texte-erreur">échec : ' . e(\Ascs\Web\Erreurs::expliquer((string) $derniere['resume'])) . '</strong> <small class="aide">(détail : ' . e($derniere['resume']) . ')</small>' ?>
        <?php else: ?>Aucune synchronisation pour l'instant.<?php endif; ?>
        <?= $webhook ? '' : '<br>Notifications instantanées non activées : les adhésions arrivent à chaque passage de la tâche planifiée.' ?>
    </p>
<?php endif; ?>

<form method="post" action="<?= e(url('/helloasso/automatique')) ?>" class="reglage-auto">
    <?= csrf_field() ?>
    <input type="hidden" name="actif" value="<?= $auto ? '0' : '1' ?>">
    <p>
        <strong>Validation automatique <?= $auto ? 'activée' : 'coupée' ?>.</strong>
        <?= $auto
            ? 'Les adhésions sans aucun doute sont validées seules : réinscription d\'une fiche connue (même nom, prénom et date de naissance) ou nouvelle personne sans fiche ressemblante, dans une section déjà retenue pour ce formulaire et ce tarif. Les autres attendent ici.'
            : 'Chaque adhésion attend votre validation.' ?>
    </p>
    <button type="submit" class="btn btn-petit"><?= $auto ? 'Couper la validation automatique' : 'Activer la validation automatique' ?></button>
</form>

<nav class="sous-onglets" aria-label="Adhésions HelloAsso">
    <a href="<?= e(url('/helloasso')) ?>"<?= $vue === 'a_traiter' ? ' aria-current="page"' : '' ?>>À valider (<?= (int) $compteurs['a_traiter'] ?>)</a>
    <a href="<?= e(url('/helloasso', ['vue' => 'traites'])) ?>"<?= $vue === 'traites' ? ' aria-current="page"' : '' ?>>Traitées (<?= (int) ($compteurs['importe'] + $compteurs['deja_present']) ?>)</a>
    <a href="<?= e(url('/helloasso', ['vue' => 'ignores'])) ?>"<?= $vue === 'ignores' ? ' aria-current="page"' : '' ?>>Ignorées (<?= (int) $compteurs['ignore'] ?>)</a>
</nav>

<?php if (!$liste): ?>
    <p class="vide"><?= $vue === 'a_traiter' ? 'Aucune adhésion à valider.' : 'Rien ici pour l\'instant.' ?></p>
<?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Date</th><th>Adhérent</th><th>Formulaire / tarif</th><th class="num">Montant</th><th class="num">Payé</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $l): ?>
        <tr>
            <td><?= e(dfr($l['date_commande'])) ?></td>
            <td><?= e(trim($l['nom'] . ' ' . $l['prenom'])) ?><?= $l['date_naissance'] ? '<br><small class="aide">' . e(dfr($l['date_naissance'])) . '</small>' : '' ?></td>
            <td><?= e($l['formulaire']) ?><br><small class="aide"><?= e($l['tarif']) ?></small></td>
            <td class="num"><?= e(money((float) $l['montant'] + (float) $l['options'])) ?></td>
            <td class="num"><?= e(money($l['paye'])) ?></td>
            <td>
                <?= e($etats[$l['statut']] ?? $l['statut']) ?>
                <?php if (in_array($l['etat'], $annule, true)): ?><br><span class="pastille pastille-excedent"><?= e($l['etat'] === 'Refunded' ? 'remboursée' : 'annulée') ?> sur HelloAsso</span><?php endif; ?>
                <?php if ($l['statut'] === 'importe' && $l['traite_par'] === Ascs\HelloAsso\Sync::PAR_AUTO): ?> <span class="pastille pastille-ok" title="Validée automatiquement">auto</span><?php endif; ?>
                <?php if ($l['section']): ?><br><small class="aide"><?= e($l['section']) ?></small><?php endif; ?>
                <?php if (!empty($raisons[(int) $l['id']])): ?><br><small class="aide">À vérifier : <?= e($raisons[(int) $l['id']]) ?></small><?php endif; ?>
            </td>
            <td>
                <?php if ($l['inscription_id'] && $l['statut'] !== 'a_traiter'): ?>
                    <a class="btn btn-petit" href="<?= e(url('/inscriptions/' . $l['inscription_id'])) ?>">Inscription</a>
                <?php else: ?>
                    <a class="btn btn-petit<?= $l['statut'] === 'a_traiter' ? ' btn-principal' : '' ?>" href="<?= e(url('/helloasso/' . $l['id'])) ?>"><?= $l['statut'] === 'a_traiter' ? 'Valider' : 'Voir' ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<?php if ($configure): ?>
<details class="fiche">
    <summary>Récupérer des adhésions plus anciennes</summary>
    <form method="post" action="<?= e(url('/helloasso/synchroniser')) ?>" class="filtres">
        <?= csrf_field() ?>
        <?= champ('Depuis le', 'depuis', $depuis, ['type' => 'date', 'hint' => 'Par défaut, chaque synchronisation relit les 12 derniers mois. Les commandes déjà saisies dans WinDev sont reconnues par leur référence et classées « déjà saisie ».']) ?>
        <button type="submit" class="btn">Synchroniser depuis cette date</button>
    </form>
</details>
<?php endif; ?>
