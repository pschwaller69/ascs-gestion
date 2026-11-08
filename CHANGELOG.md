# Journal des versions

Chaque livraison porte un numéro (fichier `VERSION`, nom du zip `ascs-X.Y.Z.zip`, rappel dans le rapport d'import).
`X` = bascule majeure, `Y` = nouvelle fonctionnalité, `Z` = correction ou ajustement.

## 0.3.11 — 08/11/2026
- **Fusion de deux fiches en double** (toujours à la main) : sur une fiche, dans « fiches ressemblantes », bouton « Comparer et fusionner ».
  - Écran de comparaison côte à côte : pour chaque champ différent, on coche la valeur à garder ; bouton pour inverser la fiche gardée.
  - La fiche gardée reçoit toutes les inscriptions et règlements de l'autre, ses canaux, ses remarques (mises bout à bout) et les cases cochées (membre du CA, attestation CE, refus de mail / SMS / WhatsApp), puis l'autre fiche est supprimée.
  - Inscription en double (même activité, même saison) : signalée avant la fusion ; l'inscription de la fiche gardée reste et reçoit les règlements de l'autre.
  - Les adhésions HelloAsso et demandes en ligne liées à l'ancienne fiche suivent.
  - Mode transition : chaque fusion est enregistrée et refaite automatiquement après un import WinDev (rapport d'import), tant qu'elle n'a pas été faite aussi dans WinDev.

## 0.3.10 — 08/11/2026
- **Tarif Rillieux** : le tarif enregistré sur la section est celui des extérieurs ; les habitants de Rillieux-la-Pape (code postal 69140 ou ville Rillieux / Crépieux) ont 10 € de réduction, sauf pour le jogging et la marche.
  - Nouvelle inscription et fiche inscription : le montant proposé tient compte de l'adresse de l'adhérent (rappel sous le champ Montant).
  - Validation d'une demande en ligne : même règle, d'après l'adresse saisie dans la demande.
  - Formulaire public : les deux tarifs sont affichés (« 245,00 € · 235,00 € pour les habitants de Rillieux »).
  - HelloAsso : inchangé, le montant est celui payé sur HelloAsso.

## 0.3.9 — 08/11/2026
- **Chèques en plusieurs fois : la série continue.** Sur « Nouveau règlement » d'une inscription qui a déjà des chèques et n'est pas soldée, le chèque suivant est proposé un mois après le dernier, le même jour de pose (15/11 → 15/12 → 15/01), pour le montant du chèque précédent sans dépasser le reste à régler. Bouton « Suite de la série » à côté de « Prochain 1er / Prochain 15 ». Fonctionne aussi avec « Valider + autre règlement ».

## 0.3.8 — 08/11/2026
- **Règlements par type** (Accueil > Par type) : pour la saison, nombre et montant par moyen de paiement, avec la part encaissable et la part à venir ; coupons culture / sport et Pass Senior distingués. Chaque ligne ouvre la liste des règlements correspondants. Rappel du total des inscriptions et du reste à régler.
- **Facture PDF** (bouton « Facture PDF » sur l'inscription), modèle WinDev : logo, n° d'inscription, adresse, inscription, règlements (à venir signalés), mention « Réglée le … » (ou ce qui reste à encaisser), attestation signée, pied de page de l'association.
- **Envoi de la facture par mail** depuis l'inscription, PDF joint, message modifiable ; chaque envoi est tracé sur la fiche.
- **Paramétrage > Facture** : coordonnées de l'association, signataire, logo, signature et tampon (PNG ou JPEG), adresse d'envoi des mails.
- **Aides au paiement : date d'encaissement proposée** — coupons mairie au 1er janvier suivant, Pass Sport un mois plus tard, Pass Région et Pass Senior 15 jours plus tard (toujours modifiable).
- Bibliothèque FPDF 1.9 (licence libre) incluse pour les PDF.

## 0.3.7 — 08/11/2026
- **Règlements à venir** : tout ce qui est sûr est enregistré, même pas encore encaissé.
  - HelloAsso en plusieurs fois : chaque échéance prévue devient un règlement daté du prélèvement, avec la mention « (prélèvement prévu) ». Une fois prélevée, la date réelle remplace la date prévue et la mention disparaît ; refusée ou annulée, le règlement est retiré (signalé dans le résumé de synchronisation).
  - Chèques à poser, coupons et pass à recevoir : à saisir avec leur date prévue (rappel sous le champ Date).
  - Dans les listes de règlements, une pastille « à venir » marque ceux dont la date n'est pas encore passée.

## 0.3.6 — 08/11/2026
- **Validation automatique des adhésions HelloAsso sans doute possible** (activée par défaut, réglage sur la page HelloAsso) :
  - réinscription : une seule fiche « très probable », même nom, prénom et date de naissance ;
  - nouvelle personne : aucune fiche ressemblante, genre donné par une question du formulaire (« Genre », « Sexe », « Civilité ») ;
  - dans les deux cas : section déjà retenue pour ce formulaire et ce tarif, saison reconnue, paiement encaissé.
- Les autres restent « À valider », avec la raison affichée (homonyme, plusieurs fiches, nouveau formulaire, genre manquant, annulée…). Les validations automatiques portent la marque « auto ».
- À la réactivation du réglage, les adhésions en attente qui remplissent les conditions sont validées tout de suite.

## 0.3.5 — 08/11/2026
- **Application installable** sur téléphone et ordinateur : icône ASCS, ouverture en plein écran (Android : « Installer l'application » ; iPhone : Partager > « Sur l'écran d'accueil »).
  - Aucune page ni donnée n'est gardée sur l'appareil : seuls les fichiers d'interface sont en cache. Sans réseau, une page « Pas de connexion » s'affiche.
- **Version et heure de la dernière mise à jour affichées en haut de chaque page** (et en pied des formulaires publics), pour vérifier d'un coup d'œil qu'on est sur la dernière version.
- Déploiement automatique : o2switch récupère chaque version publiée sur GitHub toutes les 5 minutes (`bin/deployer.sh`).

## 0.3.4 — 07/11/2026
- **Aides au paiement** sur la fiche Règlement : boutons Coupon sport mairie (48 €), Coupon culture mairie (90 €), Pass Sport (50 €), Pass Région (30 €), Pass Senior (15 €). Ils remplissent le moyen de paiement, le montant (toujours modifiable) et la référence.
  - Le coupon qui correspond à l'activité est proposé en premier (culture pour les familles Culture et Musique, sport sinon) ; choisir « Coupon Mairie » dans la liste prend le bon montant.
  - Mêmes types que WinDev : les deux coupons restent du type « Coupon Mairie » (référence COUPON SPORT / COUPON CULTURE) et le Pass Senior est un « Pass Région » de référence PASS REGION SENIOR, comme dans vos données.
  - Un montant tapé à la main n'est jamais remplacé.
- **Mise à jour automatique de la base** : après le dépôt d'une nouvelle version, les tables qui manquent sont créées à la première page ouverte (plus besoin du Terminal).
- **Corrections pour MariaDB** (o2switch) :
  - les enregistrements de HelloAsso, des réglages en ligne et des correspondances de sections passaient leurs valeurs d'une façon que MariaDB refuse (SQLite l'acceptait) : corrigé à la source pour toute l'application ;
  - la recherche par nom (adhérents, inscriptions, règlements) utilisait un caractère d'échappement invalide en MariaDB : corrigé.

## 0.3.3 — 07/11/2026
- **Remise de chèques** (Accueil > Remise de chèques).
  - Chèques à remettre regroupés par date de pose (1er ou 15 du mois), avec le nombre de remises nécessaires.
  - **30 chèques au plus par remise** : au-delà, répartition automatique sur plusieurs remises de tailles égales (50 chèques → 25 + 25), un bordereau chacune. Le décompte se met à jour en direct quand on décoche un chèque.
  - Contrôle imprimable par remise : adhérent, parent, n° de chèque, section, montant, total, ventilation par section.
  - Retirer un chèque ou supprimer une remise tant qu'elle n'est pas passée en banque ; les chèques redeviennent « à remettre ».
- **Ventilation automatique dans les comptes** : la ligne de la banque est retrouvée par le n° du bordereau (« REM CHQ N… ») ou, à défaut (relevés d'avant septembre 2025), par le montant exact dans les 3 semaines après le dépôt. Elle est ventilée par section principale en type « Inscription », comme dans WinDev. Ligne déjà ventilée : rien n'est touché ; montant différent : signalé, rien n'est écrit.
- Mode transition : une ventilation effacée par un import WinDev est réécrite juste après l'import.
- Bordereau à 30 chèques au plus. Les anciens chèques (avant la date « depuis », 20 jours par défaut) sont considérés comme déjà remis.

## 0.3.2 — 07/11/2026
- QR codes simplifiés : un seul pour les inscriptions et un seul pour les cours d'essai ; la personne choisit son activité dans le formulaire. Bouton « Imprimer les deux affiches » (une affiche A4 par page, seulement pour ce qui est ouvert). Les QR par activité et la planche à découper sont retirés.

## 0.3.1 — 07/11/2026
- **Inscriptions et cours d'essai en ligne**, remplis par les adhérents eux-mêmes (pages `/inscription` et `/essai`, sans compte, adaptées au téléphone).
  - Le bureau choisit les activités ouvertes, leur texte (horaires, public) et, s'il le souhaite, un lien de paiement HelloAsso affiché après l'envoi (Accueil > QR codes).
  - Mineur : nom du parent obligatoire. Droit à l'image, « comment avez-vous connu l'ASCS », consentement à l'enregistrement des données.
  - Protection contre les robots (champ piège, délai minimal) et 10 envois par heure au plus depuis une même connexion.
  - Chaque envoi donne une référence à la personne et arrive dans Accueil > Demandes : fiches ressemblantes, nouvelle fiche pré-remplie, une inscription par activité au tarif de la section. Un cours d'essai devient une inscription gratuite dans la section « essai », comme dans WinDev.
  - Rien n'est créé sans validation du bureau ; bouton « Refuser ».
- **QR codes** : formulaire général et un QR par activité (l'activité est alors pré-cochée), en planche à découper ou en affiches A4 imprimables. QR générés dans le navigateur (bibliothèque libre intégrée, aucun service extérieur), vérifiés par lecture automatique.
- Mode transition : juste après un import WinDev, les adhésions HelloAsso et les demandes en ligne validées dans l'application sont refaites automatiquement (le rapport d'import le dit), sauf si elles ont été saisies entre-temps dans WinDev.
- Heure de Paris aussi en ligne de commande (noms des rapports d'import).

## 0.3.0 — 07/11/2026
- **HelloAsso par l'API** (remplace l'import CSV prévu) : les adhésions sont lues directement chez HelloAsso, quel que soit le formulaire (plus de problème de colonnes différentes).
  - Synchronisation : bouton « Synchroniser maintenant », tâche cron `php bin/helloasso.php` (toutes les 15 min) et, en option, notification instantanée de HelloAsso.
  - Accueil > HelloAsso : liste des adhésions à valider. Pour chacune, fiches ressemblantes (détection de doublons), nouvelle fiche pré-remplie (le payeur devient le parent), section et saison proposées, montant = tarif + options − remise.
  - La section choisie pour un formulaire + tarif est retenue pour les suivantes.
  - Validation : inscription + un règlement HelloAsso par paiement encaissé (part de chaque enfant quand une commande en regroupe plusieurs). Les échéances suivantes d'un paiement en 3 fois deviennent des règlements à chaque synchronisation.
  - Commandes déjà saisies dans WinDev reconnues par leur référence (identifiant du règlement) : classées « déjà saisie », rien n'est créé en double.
  - Mode transition : une adhésion validée ici puis effacée par un import WinDev est revalidée automatiquement à la synchronisation suivante.
  - Adhésions annulées ou remboursées signalées ; bouton « Ignorer ».
- Accueil : rappel du nombre d'adhésions HelloAsso à valider.

## 0.2.1 — 04/11/2026
- Page **/installation** : diagnostic de la connexion à la base (message exact et conseil), création des tables et du premier compte, sans Terminal. Ouverte par le code `SETUP_CODE` du fichier `.env`, fermée dès qu'un compte existe.
- Page **Paramétrage > Import WinDev** : envoi des 10 fichiers XML (reconnus d'après leur nom, envoi possible en plusieurs fois), simulation avec rapport, puis import. Bascule définitive et retour au mode transition depuis la même page.
- Page **Paramétrage > Comptes du bureau** : créer un compte, changer un mot de passe, désactiver / réactiver.
- Base vide : l'application renvoie vers /installation au lieu d'une erreur.

## 0.2.0 — 04/11/2026
- Application web : connexion du bureau (mot de passe haché, 5 essais par mail / 20 par IP sur 15 min, jeton anti-CSRF, en-têtes de sécurité), ruban à cinq onglets (les boutons des versions futures sont grisés avec leur numéro).
- Fiches Adhérent (onglets Adhérent / Inscriptions / Règlements), Inscription et Règlement, avec listes filtrables et paginées ; saison choisie en haut de page.
- Inscription : tarif de la section pré-rempli, une seule inscription par adhérent, section et saison. Règlement : avertissement si le total dépasse le montant, boutons « prochain 1er / 15 » pour la date de pose des chèques.
- Doublons d'adhérents : fiches ressemblantes affichées pendant la saisie, et page d'avertissement avec « Créer quand même » à l'enregistrement. Aucune fusion automatique.
- Bandeau « mode transition » tant que la bascule n'a pas été faite (`bin/bascule.php`).
- `bin/utilisateur.php` : créer / lister / changer le mot de passe / désactiver un compte.
- Import : code postal « 0 » de WinDev enregistré comme vide (90 fiches).

## 0.1.1 — 04/11/2026
- Créneaux : `creneau.heure` renommée `heure_debut` (début du 1er / 2e cours de la journée, confirmé).
- `Genre` : 0 = femme, 1 = homme (déduit des prénoms, documenté dans le README).
- Numéro de version affiché par `migrate.php` et dans le rapport d'import.

## 0.1.0 — 04/11/2026
- Schéma de base (15 tables) et import XML WinDev rejouable avec fusion des inscriptions en double.
- Contrôles indépendants (`tests/verify.php`) : totaux, intégrité, idempotence.

## À venir (plan révisé le 04/11/2026 : MonClub et Sportyneo n'existent plus, HelloAsso est la seule source d'import)
- 0.4.0 : tableau de bord, émargement et listes de présence (animateurs, accès par jeton).
- 0.5.0 : communication — mailings (saison, section, anciens, N-1 non réinscrits), validation des mails, SMS par section avec validation avant envoi.
- 0.6.0 : financier (compte bancaire, ventilation, résultat par section), reporting mairie et courbe d'inscriptions.
- Abandonnés : scan de chèques, Copilot, imports MonClub et Sportyneo.
