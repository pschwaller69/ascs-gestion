<?php use Ascs\Domain\DuplicateFinder; ?>
<h1>Cet adhérent existe peut-être déjà</h1>
<p>Vous allez créer <strong><?= e(trim(($d['nom'] ?? '') . ' ' . ($d['prenom'] ?? ''))) ?></strong><?= $d['date_naissance'] ? ', né(e) le ' . e(dfr($d['date_naissance'])) : '' ?>. Ces fiches lui ressemblent :</p>
<div class="table-cadre"><table>
    <thead><tr><th>Fiche</th><th>Naissance</th><th>Mail</th><th>Téléphone</th><th>Pourquoi</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($trouves as $t): $f = $t['fiche']; ?>
        <tr>
            <td><a href="<?= e(url('/adherents/' . $t['id'])) ?>"><?= e(trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? ''))) ?></a></td>
            <td><?= e(dfr($f['date_naissance'])) ?></td>
            <td><?= e($f['mail']) ?></td>
            <td><?= e($f['telephone1']) ?></td>
            <td><span class="pastille pastille-niv-<?= e($t['niveau']) ?>"><?= e(DuplicateFinder::NIVEAUX[$t['niveau']]) ?></span> <?= e(implode(', ', $t['raisons'])) ?></td>
            <td><a class="btn btn-petit" href="<?= e(url('/inscriptions/nouveau', ['adherent' => $t['id']])) ?>">Utiliser cette fiche</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<form method="post" action="<?= e(url('/adherents')) ?>" class="actions">
    <?= csrf_field() ?><?= hidden_fields($post) ?>
    <input type="hidden" name="confirme_doublon" value="1">
    <button type="submit" class="btn btn-danger">Créer quand même</button>
    <a class="btn btn-principal" href="<?= e(url('/adherents')) ?>">Annuler, ne rien créer</a>
</form>
<p class="aide">Plusieurs enfants peuvent partager le mail d'un parent : « Créer quand même » est normal si ce sont bien deux personnes.</p>
