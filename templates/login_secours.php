<section class="carte-connexion">
    <h1>Codes de secours</h1>
    <p class="flash flash-ok" role="status">Double authentification activée.</p>
    <p><strong>Notez ces codes maintenant et rangez-les en lieu sûr</strong> (papier, gestionnaire de mots de passe) : ils ne seront plus jamais affichés. Chacun permet une seule connexion si vous n'avez plus votre téléphone.</p>
    <ul class="codes-secours">
        <?php foreach ($codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?>
    </ul>
    <p class="aide">Plus de codes ou téléphone changé : un autre membre du bureau réinitialise votre double authentification dans Comptes du bureau, et vous l'enregistrez de nouveau à la connexion suivante.</p>
    <p><a class="btn btn-principal" href="<?= e(url($retour)) ?>">J'ai noté mes codes, continuer</a></p>
</section>
