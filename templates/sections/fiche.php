<?php /** @var array $s @var array $inscrits @var array $creneaux @var array $seances @var array $assiduite @var string $lien @var ?string $lienAnimateur @var bool $saisonCourante */ ?>
<div class="titre-page">
    <h1><?= e($s['titre']) ?></h1>
    <div class="actions-titre">
        <a class="btn btn-principal" href="<?= e($lienAnimateur ?? $lien) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> Voir la page de l'animateur</a>
        <a class="btn" href="<?= e(url('/sections/' . $s['id'] . '/emargement')) ?>" target="_blank" rel="noopener"><?= icon('list') ?> Feuille d'émargement</a>
        <a class="btn" href="<?= e(url('/parametrage/sections/' . $s['id'])) ?>">Paramétrage</a>
    </div>
</div>
<p class="contexte"><?= e($s['principale'] ?? '') ?><?= $creneaux ? ' · ' . e(implode(', ', $creneaux)) : '' ?> · <?= count($inscrits) ?> inscrit<?= count($inscrits) > 1 ? 's' : '' ?><?= $seances ? ' · ' . count($seances) . ' séance(s) notée(s)' : '' ?></p>

<details class="fiche lien-animateur">
    <summary>Lien de ce cours seul</summary>
    <?php if ($lienAnimateur): ?><p class="aide">L'animateur (<?= e($s['mail_animateur1']) ?>) a de préférence <strong>un lien unique pour tous ses cours</strong>, dans Par section &gt; Liens des animateurs. Ce lien-ci n'ouvre que ce cours (utile pour un remplaçant).</p><?php endif; ?>
    <div class="lien-qr">
        <div class="qr" data-qr="<?= e($lien) ?>" role="img" aria-label="QR code de la liste de présence"></div>
        <div>
            <p>L'animateur ouvre ce lien sur son téléphone, sans mot de passe, et coche les présents à chaque séance (saison en cours).</p>
            <p><input type="text" class="lien-copie" value="<?= e($lien) ?>" readonly aria-label="Lien de la liste de présence"></p>
            <div class="actions">
                <form method="post" action="<?= e(url('/sections/' . $s['id'] . '/envoyer-lien')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-principal"<?= $s['mail_animateur1'] ? '' : ' disabled' ?>><?= icon('mail') ?> Envoyer à <?= e($s['mail_animateur1'] ?: 'l\'animateur (mail à renseigner)') ?></button>
                </form>
                <form method="post" action="<?= e(url('/sections/' . $s['id'] . '/nouveau-lien')) ?>" data-confirmer="Créer un nouveau lien ? L'ancien ne fonctionnera plus.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn">Changer le lien</button>
                </form>
            </div>
        </div>
    </div>
</details>

<?php if (!$inscrits): ?><p class="vide">Aucun inscrit pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Nom</th><th class="num">Âge</th><th>Téléphone</th><th>Mail</th><th>Parent</th><th>Paiement</th><?php if ($seances): ?><th class="num">Présences</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($inscrits as $i): ?>
        <tr>
            <td><a href="<?= e(url('/adherents/' . $i['id'])) ?>"><?= e(trim($i['nom'] . ' ' . $i['prenom'])) ?></a></td>
            <td class="num"><?= e((string) (age_from($i['date_naissance']) ?? '')) ?></td>
            <td><?= e($i['telephone1'] ?? '') ?></td>
            <td><small><?= e($i['mail'] ?? '') ?></small></td>
            <td><small><?= e(trim(($i['nom_parent'] ?? '') . ' ' . ($i['prenom_parent'] ?? '')) !== trim($i['nom'] . ' ' . $i['prenom']) ? trim(($i['nom_parent'] ?? '') . ' ' . ($i['prenom_parent'] ?? '')) : '') ?></small></td>
            <td><a href="<?= e(url('/inscriptions/' . $i['inscription_id'])) ?>"><?= pastille_solde((float) $i['montant'], (float) $i['regle']) ?></a></td>
            <?php if ($seances): ?><td class="num"><?= (int) ($assiduite[(int) $i['id']] ?? 0) ?> / <?= count($seances) ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php if ($seances): ?>
    <h2>Séances notées</h2>
    <ul class="liste-simple"><?php foreach ($seances as $se): ?><li><?= e(dfr($se['date_seance'])) ?> : <?= (int) $se['presents'] ?> présent(s) sur <?= (int) $se['total'] ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<script src="<?= e(url('/assets/qrcode.js')) ?>?v=<?= e(Ascs\Web\Ribbon::version()) ?>"></script>
