<?php
/** @var string $etat @var string $version */
$conseil = static function (?string $err): string {
    if ($err === null) {
        return '';
    }
    return match (true) {
        str_contains($err, 'Access denied') => 'L\'utilisateur ou le mot de passe ne correspond pas. Dans cPanel > Bases de données MySQL, utilisez « Changer de mot de passe » sur l\'utilisateur, recopiez-le dans DB_PASS, et vérifiez que l\'utilisateur est bien ajouté à la base avec tous les privilèges.',
        str_contains($err, 'Unknown database') => 'La base indiquée dans DB_NAME n\'existe pas : vérifiez son nom exact (avec le préfixe du compte, par exemple moncompte_gestion).',
        str_contains($err, 'could not find driver') => 'PHP n\'a pas l\'extension MySQL. Dans cPanel > Sélectionner une version de PHP, choisissez 8.3 et cochez pdo_mysql.',
        str_contains($err, 'getaddrinfo'), str_contains($err, 'Connection refused'), str_contains($err, 'No such file') => 'Le serveur de base ne répond pas à l\'adresse indiquée dans DB_HOST. Sur o2switch, utilisez localhost ; si c\'est déjà le cas, essayez 127.0.0.1.',
        default => 'Vérifiez les valeurs DB_… du fichier .env.',
    };
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation – ASCS</title>
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e($version) ?>">
</head>
<body>
<main id="contenu" class="installation">
    <h1>Installation d'ASCS <small>v<?= e($version) ?></small></h1>

<?php if ($etat === 'fermee'): ?>
    <p>L'installation est terminée : un compte du bureau existe déjà. Cette page est désormais fermée.</p>
    <p><a class="btn btn-principal" href="<?= e(url('/connexion')) ?>">Se connecter</a></p>
    <p class="aide">Les comptes se gèrent ensuite dans l'application (onglet Paramétrage &gt; Comptes du bureau).</p>

<?php elseif ($etat === 'sans_code'): ?>
    <p>Pour ouvrir cette page, ajoutez une ligne dans le fichier <code>.env</code> (à la racine du projet, à côté du dossier <code>public</code>) :</p>
    <pre>SETUP_CODE=un-code-secret-d-au-moins-12-caracteres</pre>
    <p>Rechargez ensuite cette page et saisissez ce code. Il ne sert qu'à l'installation : vous pourrez retirer la ligne une fois le premier compte créé.</p>

<?php elseif ($etat === 'code'): ?>
    <?php if (!empty($erreur)): ?><p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('/installation')) ?>" class="fiche">
        <?= csrf_field() ?><input type="hidden" name="action" value="code">
        <?= champ('Code d\'installation (SETUP_CODE du fichier .env)', 'code', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'off']) ?>
        <button type="submit" class="btn btn-principal">Continuer</button>
    </form>

<?php else: ?>
    <?php if (!empty($msg)): ?><p class="flash flash-ok" role="status"><?= e($msg) ?></p><?php endif; ?>
    <?php if (!empty($erreur)): ?><p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>

    <section class="fiche etape">
        <h2>1. Connexion à la base</h2>
        <table class="infos">
            <tr><th>Fichier .env</th><td><?= $envFichier ? 'trouvé' : '<strong>introuvable</strong> (il doit être à la racine du projet, à côté du dossier public)' ?></td></tr>
            <tr><th>Type</th><td><?= e($cfg['driver']) ?></td></tr>
            <tr><th>Serveur (DB_HOST)</th><td><?= e($cfg['host']) ?></td></tr>
            <tr><th>Base (DB_NAME)</th><td><?= e($cfg['name'] ?: '(vide)') ?></td></tr>
            <tr><th>Utilisateur (DB_USER)</th><td><?= e($cfg['user'] ?: '(vide)') ?></td></tr>
            <tr><th>Mot de passe (DB_PASS)</th><td><?= $cfg['pass'] === '' ? '<strong>vide</strong>' : 'renseigné (' . mb_strlen($cfg['pass']) . ' caractères)' ?></td></tr>
            <tr><th>PHP</th><td><?= e($php) ?><?= version_compare($php, '8.3', '<') ? ' <strong>— PHP 8.3 requis</strong>' : '' ?>, extension MySQL <?= $pdoMysql ? 'présente' : '<strong>absente</strong>' ?></td></tr>
            <tr><th>Taille d'envoi max.</th><td><?= e($uploadMax) ?> par fichier, <?= e($postMax) ?> au total</td></tr>
        </table>
        <?php if ($erreurConnexion): ?>
            <p class="flash flash-erreur" role="alert"><strong>Connexion impossible.</strong> <?= e($erreurConnexion) ?></p>
            <p><strong>Que faire :</strong> <?= e($conseil($erreurConnexion)) ?> Rechargez ensuite cette page.</p>
        <?php else: ?>
            <p class="flash flash-ok">Connexion réussie.</p>
        <?php endif; ?>
    </section>

    <?php if (!$erreurConnexion): ?>
    <section class="fiche etape">
        <h2>2. Tables</h2>
        <p><?= (int) $tables ?> table(s) sur <?= (int) $totalTables ?> présente(s).</p>
        <form method="post" action="<?= e(url('/installation')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="tables">
            <button type="submit" class="btn<?= $tables < $totalTables ? ' btn-principal' : '' ?>"><?= $tables < $totalTables ? 'Créer les tables' : 'Mettre à jour les tables' ?></button>
        </form>
    </section>

    <?php if ($tables === $totalTables): ?>
    <section class="fiche etape">
        <h2>3. Premier compte du bureau</h2>
        <form method="post" action="<?= e(url('/installation')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="compte">
            <div class="grille">
                <?= champ('Adresse mail', 'email', $_POST['email'] ?? '', ['type' => 'email', 'required' => true, 'autocomplete' => 'username']) ?>
                <?= champ('Nom affiché', 'nom', $_POST['nom'] ?? '', ['maxlength' => 100]) ?>
                <?= champ('Mot de passe', 'mot_de_passe', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => '12 caractères minimum.']) ?>
                <?= champ('Confirmation', 'confirmation', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
            </div>
            <button type="submit" class="btn btn-principal">Créer le compte et terminer</button>
        </form>
        <p class="aide">Ensuite, l'import des données WinDev se fait dans l'application : onglet Paramétrage &gt; Import WinDev.</p>
    </section>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</main>
<footer class="pied">ASCS v<?= e($version) ?></footer>
</body>
</html>
