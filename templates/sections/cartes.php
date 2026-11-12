<?php /** @var array $reglages @var array $sections @var array $porteurs @var array $recharges @var string $filtre */
$titres = array_column($sections, 'titre', 'id');
$liste = array_values(array_filter($porteurs, static fn ($p) => $filtre === 'terminees' ? $p['restantes'] <= 0 : ($filtre === 'bientot' ? $p['restantes'] <= 2 : true)));
$numeros = [];
foreach ($porteurs as $p) {
    if ($p['restantes'] <= 0 && ($m = Ascs\Mail\Sms::mobile((string) ($p['telephone1'] ?: $p['telephone2'])))) {
        $numeros[$m] = true;
    }
}
$numeros = array_keys($numeros);
$nbFin = count(array_filter($porteurs, static fn ($p) => $p['restantes'] <= 0));
$nbBientot = count(array_filter($porteurs, static fn ($p) => $p['restantes'] > 0 && $p['restantes'] <= 2));
?>
<div class="titre-page"><h1>Cartes de séances</h1></div>
<p class="contexte">Abonnements à la carte : chaque présence cochée par l'animateur consomme une séance. Les porteurs de carte apparaissent dans la liste de présence du cours où la carte s'utilise, avec les séances restantes. Saison choisie en haut de page.</p>

<p class="filtres">
    <a class="btn btn-petit<?= $filtre === '' ? ' actif' : '' ?>" href="<?= e(url('/sections/cartes')) ?>">Toutes (<?= count($porteurs) ?>)</a>
    <a class="btn btn-petit<?= $filtre === 'terminees' ? ' actif' : '' ?>" href="<?= e(url('/sections/cartes', ['filtre' => 'terminees'])) ?>">Terminées ou dépassées (<?= $nbFin ?>)</a>
    <a class="btn btn-petit<?= $filtre === 'bientot' ? ' actif' : '' ?>" href="<?= e(url('/sections/cartes', ['filtre' => 'bientot'])) ?>">2 séances ou moins (<?= $nbFin + $nbBientot ?>)</a>
    <?php if ($numeros): ?><a class="btn btn-petit" data-sms-numeros="<?= e(implode(',', $numeros)) ?>" data-sms-texte="Bonjour, votre carte de séances est terminée. " href="sms:<?= e(implode(',', $numeros)) ?>?body=<?= e(rawurlencode('Bonjour, votre carte de séances est terminée. ')) ?>"><?= icon('sms') ?> SMS aux <?= count($numeros) ?> cartes terminées</a><?php endif; ?>
</p>
<?php if ($numeros) { require __DIR__ . '/../_sms_groupe.php'; } ?>

<?php if (!$reglages): ?>
    <p class="vide">Aucune section n'est gérée à la carte : choisissez-les ci-dessous.</p>
<?php elseif (!$liste): ?>
    <p class="vide">Aucune carte <?= $filtre ? 'dans ce filtre' : 'cette saison' ?>.</p>
<?php else: ?>
<ul class="liste-cartes">
    <?php foreach ($liste as $p): $iid = (int) $p['inscription_id']; $r = $reglages[(int) $p['section_id']]; $tel = (string) ($p['telephone1'] ?: $p['telephone2']); ?>
        <li id="i<?= $iid ?>">
            <div class="ca-tete">
                <span><a href="<?= e(url('/adherents/' . (int) $p['adherent_id'])) ?>"><strong><?= e(mb_strtoupper((string) $p['nom']) . ' ' . $p['prenom']) ?></strong></a>
                    <?php if ($tel !== ''): ?> · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $tel)) ?>"><?= e($tel) ?></a><?php endif; ?></span>
                <span class="pastille <?= $p['restantes'] <= 0 ? 'pastille-erreur' : ($p['restantes'] <= 2 ? 'pastille-reste' : 'pastille-ok') ?>"><?= (int) $p['restantes'] ?> restante<?= abs((int) $p['restantes']) > 1 ? 's' : '' ?></span>
            </div>
            <p class="aide"><?= e((string) $p['section']) ?><?= $r['cours_id'] !== $r['section_id'] ? ' · au cours ' . e($titres[$r['cours_id']] ?? '?') : '' ?>
                · <?= (int) $p['utilisees'] ?> séance(s) utilisée(s) sur <?= (int) $p['achetees'] ?> · <?= $p['derniere'] ? 'dernière venue le ' . e(dfr($p['derniere'])) : 'pas encore venu' ?></p>
            <p class="ca-cartes"><small>1<sup>re</sup> carte : l'inscription (<?= (int) $r['seances'] ?> séances)</small>
            <?php foreach ($recharges[$iid] ?? [] as $k => $re): ?>
                <br><small><?= $k + 2 ?><sup>e</sup> carte le <?= e(dfr((string) $re['date_achat'])) ?> : <?= (int) $re['seances'] ?> séances<?= $re['montant'] !== null ? ', ' . e(money($re['montant'])) : '' ?><?= $re['note'] ? ' (' . e($re['note']) . ')' : '' ?></small>
                <form class="suppr-carte" method="post" action="<?= e(url('/sections/cartes/recharge/' . (int) $re['id'] . '/supprimer')) ?>" data-confirmer="Supprimer cette carte ?"><?= csrf_field() ?><button class="lien-bouton" type="submit">supprimer</button></form>
            <?php endforeach; ?></p>
            <details class="recharge">
                <summary>Ajouter une carte</summary>
                <form method="post" action="<?= e(url('/sections/cartes/' . $iid . '/recharger')) ?>" class="form-recharge">
                    <?= csrf_field() ?>
                    <label>Séances <input type="number" name="seances" min="1" max="100" value="<?= (int) $r['seances'] ?>" required></label>
                    <label>Montant <input type="text" name="montant" inputmode="decimal" value="<?= e(number_format((float) $p['prix'], 2, ',', '')) ?>"></label>
                    <label>Date <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>"></label>
                    <label>Note <input type="text" name="note" maxlength="255" placeholder="chèque, espèces…"></label>
                    <button class="btn btn-principal btn-petit" type="submit">Ajouter la carte</button>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
</ul>
<p class="aide">Une carte ajoutée ici compte les séances ; son paiement s'enregistre comme d'habitude dans les règlements de l'inscription.</p>
<?php endif; ?>

<section id="reglages" class="fiche">
    <h2>Sections à la carte</h2>
    <p class="aide">Nombre de séances par carte, et le cours où la carte s'utilise : les porteurs apparaissent alors dans la liste de présence de ce cours (l'onglet de la section à la carte disparaît chez l'animateur). « La section elle-même » si la carte a son propre cours.</p>
    <?php foreach ($reglages as $r): $s = array_values(array_filter($sections, static fn ($x) => (int) $x['id'] === $r['section_id']))[0] ?? null; if (!$s) { continue; } ?>
        <form method="post" action="<?= e(url('/sections/cartes/reglage')) ?>" class="ligne-reglage">
            <?= csrf_field() ?>
            <input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">
            <strong><?= e($s['titre']) ?></strong>
            <label>Séances par carte <input type="number" name="seances" min="1" max="100" value="<?= (int) $r['seances'] ?>"></label>
            <label>S'utilise au cours
                <select name="cours_id">
                    <option value="0"<?= attr($r['cours_id'] === $r['section_id'], 'selected') ?>>la section elle-même</option>
                    <?php foreach ($sections as $c): if ((int) $c['id'] === (int) $s['id'] || $c['essai'] || isset($reglages[(int) $c['id']]) || ($s['section_principale_id'] && $c['section_principale_id'] !== $s['section_principale_id'])) { continue; } ?>
                        <option value="<?= (int) $c['id'] ?>"<?= attr($r['cours_id'] === (int) $c['id'], 'selected') ?>><?= e($c['titre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-petit" type="submit" name="action" value="enregistrer">Enregistrer</button>
            <button class="btn btn-petit" type="submit" name="action" value="retirer">Retirer</button>
        </form>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('/sections/cartes/reglage')) ?>" class="ligne-reglage">
        <?= csrf_field() ?>
        <label>Ajouter une section à la carte
            <select name="section_id" required>
                <option value="">— choisir —</option>
                <?php foreach ($sections as $c): if ($c['essai'] || isset($reglages[(int) $c['id']])) { continue; } ?><option value="<?= (int) $c['id'] ?>"><?= e($c['titre']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Séances par carte <input type="number" name="seances" min="1" max="100" value="10"></label>
        <input type="hidden" name="cours_id" value="0">
        <button class="btn btn-petit" type="submit" name="action" value="enregistrer">Ajouter</button>
    </form>
</section>
