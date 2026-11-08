<?php
use Ascs\Web\Ribbon;

/** @var Ascs\Web\App $app */
$retour = $_SERVER['REQUEST_URI'] ?? '/';
$base = Ascs\Web\App::$base;
if ($base !== '' && str_starts_with($retour, $base)) {
    $retour = substr($retour, strlen($base));
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title !== '' ? $title . ' – ASCS' : 'ASCS') ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e($version) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#064c48">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('/assets/icones/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('/assets/icones/apple-touch-icon.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ASCS">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<script src="<?= e(url('/assets/app.js')) ?>?v=<?= e($version) ?>" defer data-sw="<?= e(url('/sw.js')) ?>?v=<?= e($version) ?>"></script>
</head>
<body>
<a class="saut" href="#contenu">Aller au contenu</a>
<?php if ($app->user): ?>
<header class="entete">
    <div class="entete-haut">
        <a class="marque" href="<?= e(url('/')) ?>">ASCS <span>Les Semailles</span></a>
        <span class="version-dev" title="Version de l'application et heure de sa dernière mise à jour sur le serveur">v<?= e($version) ?><?php $maj = Ascs\Web\Ribbon::miseAJour(); ?><?= $maj ? ' · ' . e($maj) : '' ?></span>
        <form class="saison" method="post" action="<?= e(url('/saison')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="retour" value="<?= e($retour) ?>">
            <label for="saison-select">Saison</label>
            <select id="saison-select" name="saison" data-autosubmit>
                <?php foreach ($saisons as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= attr((int) $s['id'] === $saisonId, 'selected') ?>><?= e($s['titre']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit">Changer</button></noscript>
        </form>
        <form class="quitter" method="post" action="<?= e(url('/deconnexion')) ?>">
            <?= csrf_field() ?>
            <span class="qui"><?= e($app->user['nom'] ?: $app->user['email']) ?></span>
            <button type="submit" class="btn-lien"><?= icon('logout') ?> Se déconnecter</button>
        </form>
    </div>
    <nav class="ruban" aria-label="Navigation principale">
        <div class="onglets" role="tablist">
            <?php foreach (Ribbon::onglets() as $cle => $o): ?>
                <button type="button" role="tab" id="tab-<?= e($cle) ?>" aria-controls="panneau-<?= e($cle) ?>" aria-selected="<?= $cle === $onglet ? 'true' : 'false' ?>" data-onglet="<?= e($cle) ?>"><?= e($o['titre']) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach (Ribbon::onglets() as $cle => $o): ?>
            <div class="panneau" role="tabpanel" id="panneau-<?= e($cle) ?>" aria-labelledby="tab-<?= e($cle) ?>"<?= $cle === $onglet ? '' : ' hidden' ?>>
                <?php foreach ($o['groupes'] as $nomGroupe => $items): ?>
                    <div class="groupe">
                        <ul>
                            <?php foreach ($items as [$libelle, $ico, $lien, $depuis]): ?>
                                <li>
                                    <?php if ($lien): ?>
                                        <a class="bouton-ruban" href="<?= e(url($lien)) ?>"><?= icon($ico) ?><span><?= e($libelle) ?></span></a>
                                    <?php else: ?>
                                        <span class="bouton-ruban inactif" title="Disponible en version <?= e($depuis) ?>" aria-disabled="true"><?= icon($ico) ?><span><?= e($libelle) ?></span><small>v<?= e($depuis) ?></small></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="nom-groupe"><?= e($nomGroupe) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <?php if ($transition): ?>
        <p class="bandeau-transition" role="note">Mode transition : WinDev reste la référence. Chaque import complet écrase les saisies faites ici.</p>
    <?php endif; ?>
</header>
<?php endif; ?>
<main id="contenu" tabindex="-1">
    <?php foreach ($flashes as $f): ?>
        <p class="flash flash-<?= e($f['type']) ?>" role="<?= $f['type'] === 'erreur' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></p>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="pied">ASCS v<?= e($version) ?></footer>
</body>
</html>
