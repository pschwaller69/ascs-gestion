<?php $aucunCours = $aucunCours ?? false; ?>
<?php if ($aucunCours): ?>
<h1>Aucun cours cette saison</h1>
<p>Aucun cours ne vous est attribué pour la saison en cours. Si c'est une erreur, contactez le bureau de l'association.</p>
<?php else: ?>
<h1>Lien inconnu</h1>
<p>Ce lien de liste de présence n'existe pas ou a été changé. Demandez le nouveau lien au bureau de l'association.</p>
<?php endif; ?>
