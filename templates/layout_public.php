<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title !== '' ? $title . ' – ASCS Les Semailles' : 'ASCS Les Semailles') ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e($version) ?>">
<script src="<?= e(url('/assets/app.js')) ?>?v=<?= e($version) ?>" defer></script>
</head>
<body class="public">
<header class="entete-public">
    <p class="marque">ASCS <span>Association Sportive et Culturelle des Semailles</span></p>
</header>
<main id="contenu">
    <?= $content ?>
</main>
<footer class="pied">ASCS – Rillieux-la-Pape · v<?= e($version) ?></footer>
</body>
</html>
