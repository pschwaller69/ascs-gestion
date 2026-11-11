<div class="titre-page">
    <h1>Inscriptions par section</h1>
    <form method="post" action="<?= e(url('/sections/envoyer-liens')) ?>" data-confirmer="Envoyer à chaque animateur son lien (tous ses cours) ?">
        <?= csrf_field() ?>
        <button type="submit" class="btn"><?= icon('mail') ?> Envoyer leur lien aux animateurs</button>
    </form>
</div>
<p class="contexte">Saison choisie en haut de page. Chaque section a une liste de présence que l'animateur ouvre sur son téléphone, sans mot de passe, et une feuille d'émargement à imprimer.</p>
<?php if ($animateurs): ?>
<section id="animateurs" class="fiche">
    <h2>Liens des animateurs</h2>
    <p class="aide">Un lien par animateur (d'après le mail d'animateur des sections), avec tous ses cours en onglets, sans mot de passe. « Voir » ouvre exactement sa page. Le lien ne change pas aux imports WinDev.</p>
    <div class="table-cadre"><table>
        <thead><tr><th>Animateur</th><th>Cours</th><th><span class="visuellement-cache">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($animateurs as $a): $lienA = Ascs\Web\App::urlAbsolue('/animateur/' . $a['jeton']); ?>
            <tr>
                <td><?= e($a['mail']) ?></td>
                <td><?= e(implode(', ', array_map(static fn ($x) => $x['titre'], $a['sections']))) ?></td>
                <td class="actions-ligne">
                    <a class="btn btn-petit" href="<?= e($lienA) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> Voir</a>
                    <button type="button" class="btn btn-petit" data-copier="<?= e($lienA) ?>">Copier le lien</button>
                    <form method="post" action="<?= e(url('/sections/animateur/envoyer')) ?>"><?= csrf_field() ?><input type="hidden" name="mail" value="<?= e($a['mail']) ?>"><button class="btn btn-petit" type="submit"><?= icon('mail') ?> Envoyer</button></form>
                    <form method="post" action="<?= e(url('/sections/animateur/nouveau-lien')) ?>" data-confirmer="Créer un nouveau lien pour <?= e($a['mail']) ?> ? L'ancien ne fonctionnera plus."><?= csrf_field() ?><input type="hidden" name="mail" value="<?= e($a['mail']) ?>"><button class="btn btn-petit" type="submit">Changer le lien</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php $aVoir = array_filter($changements, static fn ($c) => !$c['vu'] && !$c['annule']); ?>
<?php if ($changements): ?>
<section id="changements" class="fiche">
    <h2>Changements de cours<?= $aVoir ? ' · <span class="pastille pastille-reste">' . count($aVoir) . ' à voir</span>' : '' ?></h2>
    <p class="aide">Faits par les animateurs depuis leur liste de présence. Le tarif de l'inscription n'a pas changé.<?= $app->transition() ? ' Mode transition : à reporter dans WinDev ; en attendant, ils sont refaits après chaque import.' : '' ?></p>
    <div class="table-cadre"><table>
        <thead><tr><th>Date</th><th>Adhérent</th><th>Ancien cours</th><th>Nouveau cours</th><th>Raison</th><th><span class="visuellement-cache">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($changements, 0, 40) as $c): ?>
            <tr<?= $c['annule'] ? ' class="ligne-annulee"' : '' ?>>
                <td><?= e(dfr((string) $c['date_changement'])) ?></td>
                <td><a href="<?= e(url('/adherents/' . (int) $c['adherent_id'])) ?>"><?= e(mb_strtoupper((string) $c['nom']) . ' ' . $c['prenom']) ?></a></td>
                <td><?= e((string) $c['de_titre']) ?></td>
                <td><?= e((string) $c['vers_titre']) ?><?php if ((float) $c['de_montant'] !== (float) $c['vers_montant']): ?><br><small class="texte-erreur">tarif <?= e(money($c['de_montant'])) ?> → <?= e(money($c['vers_montant'])) ?></small><?php endif; ?></td>
                <td><?= e(Ascs\Domain\Changements::MOTIFS[$c['motif']] ?? $c['motif']) ?><?= $c['precision_motif'] ? '<br><small>« ' . e($c['precision_motif']) . ' »</small>' : '' ?><br><small class="aide"><?= e((string) $c['par']) ?></small></td>
                <td class="actions-ligne">
                    <?php if ($c['annule']): ?><span class="aide">annulé</span>
                    <?php else: ?>
                        <?php if (!$c['vu']): ?><form method="post" action="<?= e(url('/sections/changements/' . (int) $c['id'] . '/vu')) ?>"><?= csrf_field() ?><button class="btn btn-petit" type="submit">Vu</button></form><?php endif; ?>
                        <form method="post" action="<?= e(url('/sections/changements/' . (int) $c['id'] . '/annuler')) ?>" data-confirmer="Remettre l'adhérent dans son ancien cours ?"><?= csrf_field() ?><button class="btn btn-petit" type="submit">Annuler</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php if (!$sections): ?><p class="vide">Aucune inscription pour cette saison.</p><?php else: ?>
<div class="table-cadre"><table>
    <thead><tr><th>Section</th><th>Section principale</th><th class="num">Inscrits</th><th class="num">Séances notées</th><th>Animateur</th><th><span class="visuellement-cache">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($sections as $s): ?>
        <tr>
            <td><a href="<?= e(url('/sections/' . $s['id'])) ?>"><?= e($s['titre']) ?></a><?= $s['essai'] ? ' <small class="aide">cours d\'essai</small>' : '' ?></td>
            <td><?= e($s['principale']) ?></td>
            <td class="num"><?= (int) $s['inscrits'] ?></td>
            <td class="num"><?= (int) $s['seances'] ?: '' ?></td>
            <td><small><?= e($s['mail_animateur1'] ?? '') ?></small></td>
            <td class="actions-ligne"><a class="btn btn-petit" href="<?= e(Ascs\Web\App::urlAbsolue('/presence/' . $s['presence_token'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> Voir</a> <a class="btn btn-petit" href="<?= e(url('/sections/' . $s['id'] . '/emargement')) ?>" target="_blank" rel="noopener">Émargement</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
