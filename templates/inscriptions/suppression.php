<h1>Supprimer l'inscription ?</h1>
<p>L'inscription de <strong><?= e(trim(($i['nom'] ?? '') . ' ' . ($i['prenom'] ?? ''))) ?></strong> à <strong><?= e($i['section']) ?></strong> (<?= e($i['saison']) ?>)
   sera supprimée<?= $nbReglements ? ', ainsi que ses <strong>' . (int) $nbReglements . ' règlement' . ($nbReglements > 1 ? 's' : '') . '</strong> (' . e(money($i['regle'])) . ')' : '' ?>. Cette action est définitive.</p>
<form method="post" action="<?= e(url('/inscriptions/' . $i['id'] . '/supprimer')) ?>" class="actions">
    <?= csrf_field() ?><input type="hidden" name="confirme" value="1">
    <button type="submit" class="btn btn-danger">Oui, supprimer</button>
    <a class="btn btn-principal" href="<?= e(url('/inscriptions/' . $i['id'])) ?>">Non, garder</a>
</form>
