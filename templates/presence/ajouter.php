<?php /** @var array $s @var array $saison @var string $base @var array $q @var string $recherche @var string $date @var array $anciens @var ?string $erreur */ ?>
<p><a href="<?= e(url($base, $q + ['date' => $date])) ?>">‹ Retour à la liste de présence</a></p>
<h1>Ajouter une personne non inscrite</h1>
<p class="contexte"><?= e($s['titre']) ?> · séance du <?= e(dfr($date)) ?>. La personne est notée présente et le bureau la relancera pour son inscription.</p>
<?php if ($erreur): ?><p class="flash flash-erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>

<section class="fiche">
    <h2>Un ancien adhérent</h2>
    <form method="get" action="<?= e(url($base . '/ajouter')) ?>" class="filtres">
        <?php foreach ($q + ['date' => $date] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <div class="champ"><label for="q">Nom ou prénom</label><input type="search" id="q" name="q" value="<?= e($recherche) ?>" minlength="2" autocomplete="off" autofocus></div>
        <button class="btn" type="submit"><?= icon('search') ?> Chercher</button>
    </form>
    <?php if ($recherche !== '' && mb_strlen($recherche) < 2): ?>
        <p class="aide">Tapez au moins 2 lettres.</p>
    <?php elseif ($recherche !== '' && !$anciens): ?>
        <p class="aide">Personne de ce nom parmi les anciens adhérents de cette activité (ou déjà inscrit à ce cours). Ajoutez-le comme nouvelle personne ci-dessous.</p>
    <?php elseif ($anciens): ?>
        <ul class="presence-liste">
        <?php foreach ($anciens as $a): ?>
            <li>
                <span class="p-ancien"><strong><?= e(mb_strtoupper($a['nom'])) ?> <?= e($a['prenom']) ?></strong><br><small class="aide">dernier cours : <?= e($a['dernier']) ?></small></span>
                <form method="post" action="<?= e(url($base . '/ajouter')) ?>">
                    <?= csrf_field() ?>
                    <?php foreach ($q + ['date' => $date] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
                    <input type="hidden" name="type" value="ancien"><input type="hidden" name="adherent" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-principal btn-petit" type="submit">Ajouter</button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="aide">Les anciens adhérents de cette activité qui ne sont pas encore inscrits cette saison.</p>
    <?php endif; ?>
</section>

<section class="fiche">
    <h2>Une nouvelle personne</h2>
    <form method="post" action="<?= e(url($base . '/ajouter')) ?>">
        <?= csrf_field() ?>
        <?php foreach ($q + ['date' => $date] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <input type="hidden" name="type" value="nouveau">
        <div class="champ"><label for="nom">Nom</label><input type="text" id="nom" name="nom" required maxlength="100" autocomplete="off"></div>
        <div class="champ"><label for="prenom">Prénom</label><input type="text" id="prenom" name="prenom" required maxlength="100" autocomplete="off"></div>
        <div class="champ"><label for="telephone">Téléphone (pour la relancer)</label><input id="telephone" name="telephone" type="tel" inputmode="tel" maxlength="30" autocomplete="off"></div>
        <button class="btn btn-principal" type="submit"><?= icon('user-plus') ?> Ajouter</button>
    </form>
</section>
