# Journal des versions

Chaque livraison porte un numéro (fichier `VERSION`, nom du zip `ascs-X.Y.Z.zip`, rappel dans le rapport d'import).
`X` = bascule majeure, `Y` = nouvelle fonctionnalité, `Z` = correction ou ajustement.

## 0.9.0

- Contacts Outlook (Mail > Contacts Outlook) : les adhérents de la saison, ou de la saison et de la précédente, sont recopiés dans un dossier de contacts « ASCS » de la boîte Microsoft 365 de l'association, et tenus à jour toutes les heures (bin/contacts.php) ou par « Synchroniser maintenant » : nouvel adhérent, numéro ou adresse modifiés, retrait quand la personne n'est plus inscrite, recréation d'un contact ou du dossier supprimés à la main. Chaque contact : nom, prénom, mail, portable, autre téléphone, adresse, société « ASCS », service et catégories = ses sections, notes = n° d'adhérent et parent. Les autres contacts de la boîte ne sont jamais touchés. Remplace l'ancienne synchronisation de WinDev ; l'export CSV reste disponible.
- Configuration : application Microsoft Entra avec le droit Graph Contacts.ReadWrite (application), lignes M365_ du .env (README, « Contacts Outlook »).

## 0.8.12

- Rappel du réglage « SMS individuels » sous chaque bouton d'SMS groupé (SMS à tout le cours, relances des non-inscrits, cartes terminées, envoi depuis le téléphone de l'association) : comment désactiver les messages de groupe sur Android (Messages de Google), Samsung et iPhone, pour que chacun reçoive un SMS séparé sans voir les numéros des autres.

## 0.8.11

- Communauté WhatsApp (Mail > Mailings et SMS) : le lien d'invitation s'enregistre une fois (vérifié : https://chat.whatsapp.com/…), puis « Inviter par mail » ou « Inviter par SMS » prépare l'envoi à tous les adhérents de la saison avec un message prêt, personnalisé au prénom. Il suit le circuit habituel : nombre de destinataires, envoi de test, validation ; les personnes qui ont refusé les mails ou les SMS sont exclues. Le lien n'est pas écrit dans le programme (dépôt public), seulement dans les réglages.

## 0.8.10

- Nouvelle inscription : le parcours de l'adhérent s'affiche au-dessus du formulaire, avec une ligne « Reprendre » qui propose les cours des saisons passées (sans les cours d'essai, les activités arrêtées et ceux déjà pris cette saison). Un clic choisit la section et met le tarif à jour : plus besoin de chercher le jour (Pilates samedi ou mercredi…).
- Recherche de l'adhérent à inscrire : chaque résultat indique ses cours de la dernière saison où il était inscrit.

## 0.8.9

- Fiche adhérent : un encadré « parcours » en haut de la fiche, visible quel que soit l'onglet. Inscriptions de la saison (payé ou reste dû, présences notées, séances restantes de la carte), cours où la personne est venue sans être inscrite, et ses saisons précédentes (dépliables, une ligne par saison avec ses sections). L'onglet Inscriptions est regroupé par saison, avec le total, le reste dû et les présences de chaque inscription.

## 0.8.8

- Activités arrêtées (Paramétrage > Section > Activités arrêtées) : arrêter une activité entière (section principale, par exemple Atelier Batterie / musique) ou une seule section. Elle n'est plus proposée : nouvelle inscription (refusée même si on force la saisie), inscription en ligne (fermée automatiquement), HelloAsso, changement de cours. L'historique reste : inscriptions et règlements passés (modifiables, la section reste dans leur choix), filtre de la liste des inscriptions, résultats, reporting, mailings aux anciens. La page signale les activités qui n'ont plus personne cette saison ; « Reprendre » les propose de nouveau.

## 0.8.7

- Contrôleurs : bénévoles qui vérifient que tout le monde est inscrit. Chacun a un lien personnel sans mot de passe (Par section > Contrôleurs : ajouter, voir, copier, changer le lien, supprimer). Le lien ouvre la liste de tous les cours de la saison, rangés par jour en commençant par aujourd'hui, avec l'heure et une recherche ; chaque cours s'ouvre comme chez l'animateur (inscrits, cartes, non-inscrits). Le contrôleur peut noter une personne non inscrite (retrouvée par le bureau dans « Venus sans inscription », avec son nom) mais ne peut pas changer un adhérent de cours.

## 0.8.6

- Plusieurs animateurs pour un cours : le mail d'animateur d'une section peut contenir plusieurs adresses séparées par un point-virgule (comme dans WinDev). Chacun a son propre lien avec ce cours, et « Envoyer » écrit à chaque adresse séparément (avant : « erreur de mail » avec deux adresses). Le Paramétrage des sections accepte et vérifie plusieurs adresses.

## 0.8.5

- Ruban : nouveau groupe « Cours » dans l'onglet Accueil (Par section, Cartes de séances, Venus sans inscription), au lieu de l'onglet Mail. Liens vers les cartes et les non-inscrits en haut de la page Par section.

## 0.8.4

- Correction : après le déploiement de la 0.8.2, les tables des cartes de séances n'avaient pas été créées (« Table section_carte doesn't exist » sur la page de l'animateur). Pendant le déploiement, le serveur pouvait encore utiliser l'ancien programme en cache tout en lisant le nouveau numéro de version : la mise à jour était marquée faite sans les nouvelles tables. La mise à jour se repère maintenant à l'empreinte du schéma connu par le programme, et une table manquante déclenche la mise à jour puis recharge la page.

## 0.8.3

- Cartes de séances : Modelage est réglé d'office à la carte (10 séances, sa propre liste de présence), même si la détection des cartes a déjà eu lieu. Pilates et Arts martiaux artistiques et acrobatiques gardent les deux formules : à l'année et à la carte (porteurs de carte affichés dans le cours à l'année du même jour).

## 0.8.2

- Cartes de séances (Par section > Cartes de séances) : une section « à la carte » donne un nombre de séances par carte (10 par défaut), utilisables dans un cours. Détection automatique au premier affichage (« À LA CARTE », « 10 COURS ») avec le cours du même jour : Pilates à la carte mercredi → Pilates mercredi, etc. ; Modelage ou toute autre section s'ajoute à la main.
- Chez l'animateur : les porteurs de carte apparaissent dans la liste du cours normal avec les séances restantes (verte, orange à 2 ou moins, rouge si terminée ou dépassée) ; l'onglet de la section à la carte disparaît. Chaque présence cochée consomme une séance.
- Bureau : séances utilisées / achetées / restantes, dernière venue, filtres « terminées » et « 2 ou moins », SMS groupé aux cartes terminées, « Ajouter une carte » quand l'adhérent en rachète une (séances, montant, date, note).
- Toute la batterie de tests repasse (19 suites).

## 0.8.1

- L'animateur peut ajouter une personne qui vient au cours sans être inscrite : un ancien adhérent de l'activité (recherche par nom, seulement parmi les anciens de cette activité) ou une nouvelle personne (nom, prénom, téléphone). Elle est notée présente, apparaît ensuite dans la liste « Pas encore inscrits » avec SMS et appel, et ses présences sont suivies.
- Bureau : Par section > Venus sans inscription. Coordonnées, cours, nombre de venues et dates, qui l'a ajoutée ; « Relancé » (daté), « Inscrit », « Retirer » ; SMS groupé aux personnes à relancer ; « créer la fiche » préremplie pour une nouvelle personne. Une personne passe en « Inscrit » toute seule dès qu'une inscription existe pour sa fiche (ou une fiche à son nom).
- Correction d'affichage : la liste des liens des animateurs débordait sur téléphone (mail coupé). Elle s'affiche maintenant en une ligne par animateur, boutons côte à côte. Plus de défilement horizontal parasite de la page Par section.

## 0.8.0

- Page de l'animateur : un lien unique par animateur (repéré par le mail d'animateur des sections), sans mot de passe, avec tous ses cours en onglets ; le cours du jour s'ouvre en premier. Recherche par nom, bouton SMS et bouton d'appel pour chaque adhérent, « SMS à tout le cours » pour prévenir d'un retard ou d'une annulation (messagerie du téléphone, numéros préremplis).
- Changement de cours par l'animateur (erreur d'inscription, changement de niveau ou d'horaire) vers un autre cours de la même activité ; le tarif ne change pas. Le bureau voit chaque changement dans Par section (« à voir », tarif différent signalé) et peut l'annuler. En mode transition, les changements sont refaits après chaque import WinDev.
- Bureau : Par section > Liens des animateurs (voir exactement leur page, copier, envoyer, changer le lien) ; bouton « Voir » sur chaque cours et « Voir la page de l'animateur » sur la fiche d'un cours. « Envoyer leur lien aux animateurs » envoie un seul mail par animateur.
- Feuille d'émargement lisible sur téléphone (défilement horizontal, dates lisibles, noms figés à gauche).
- Effacement des données : saisons, sections et liens des animateurs sont gardés, pour que les liens restent valables après la bascule.

## 0.7.6

- Correction : la synchronisation HelloAsso échouait (« SQLSTATE[22007] … datetime value: '' ») et la validation d'une réinscription donnait « Une erreur est survenue », dès qu'une fiche existante était complétée avec la date de naissance. MariaDB (o2switch, mode strict) refuse de comparer une date à un texte vide ; SQLite, utilisé pour les tests, l'acceptait. Même correction pour les demandes en ligne.
- Messages d'erreur détaillés : explication en clair (date refusée, doublon, base inaccessible, service extérieur muet…), puis pour le bureau la référence, la page, le message technique et l'emplacement dans le programme. Chaque erreur est enregistrée dans Paramétrage > Journal des erreurs. L'échec d'une synchronisation HelloAsso est expliqué de la même façon.
- Tests : l'application est désormais aussi testée sur MariaDB en mode strict, comme chez o2switch.

## 0.7.5

- Mode transition = mode essai pour le financier : la ventilation d'une ligne bancaire et l'import d'un relevé sont permis pour tester. Le prochain import WinDev remet la ventilation de WinDev et efface les lignes ajoutées (bandeau « Mode essai » sur les pages concernées).
- Ventilation sur téléphone : une carte par ligne (section, catégorie, montant, observation avec leurs étiquettes) au lieu d'un tableau tronqué.
- Paramétrage > Import WinDev : bouton « Effacer toutes les données » (mode transition seulement, confirmation en tapant EFFACER). Efface adhérents, inscriptions, règlements, comptes, HelloAsso, demandes, mailings, présences, remises, fusions ; garde les comptes du bureau et les réglages. Sauvegarde complète de la base (SQL compressé, rechargeable dans phpMyAdmin) faite juste avant et téléchargeable depuis la même page.

## 0.7.4

Corrections issues d'une relecture complète du code.

- Mailings : un refus (désinscription) vaut pour l'adresse ou le numéro, plus seulement pour la fiche. Une famille qui partage un mail et se désinscrit ne reçoit plus rien par les autres fiches.
- Mailings : un seul envoi de lot à la fois (tâche automatique et bouton ne peuvent plus servir deux fois le même destinataire) ; chaque destinataire est réservé avant l'envoi. Un envoi coupé en route apparaît « envoi interrompu ».
- Mails : nom de l'expéditeur mis entre guillemets s'il contient une virgule ou un point ; connexion SMTP rouverte après une coupure au lieu de faire échouer tout le reste du lot.
- Exports CSV : un texte commençant par = ou @ n'est plus lu comme une formule par Excel.

## 0.7.3

- Compte bancaire : choix de la période (saison ou année civile, sur la date des opérations). Si le compte n'a aucune opération dans la saison choisie en haut de page, la dernière saison qui en a est affichée, avec un message. Sur téléphone, les onglets des comptes passent à la ligne.
- Export pour le comptable (Financier > Export comptable) : classeur Excel par saison, année civile ou dates libres, comptes au choix. Feuilles Synthèse (soldes d'ouverture et de clôture par compte, écart avec le dernier solde relevé, résultat par catégorie hors virements internes), Journal de banque, Ventilation, Par catégorie, Par section, À justifier. Synthèse visible à l'écran avant téléchargement ; ventilation seule en CSV.

## 0.7.2 — 09/11/2026
- **Mailings et SMS : envoi de test** vers quelques adresses ou numéros (5 au plus, gardés pour la fois suivante) : message préfixé [TEST], personnalisé avec le premier destinataire ; les destinataires de l'envoi ne reçoivent rien. SMS sans passerelle : bouton « Ouvrir Messages » pour le test.
- **Nombre de destinataires bien visible** : compteur en grand, recalculé dès qu'on change la cible ou les sections (avec la liste des noms et les exclus) ; sur l'envoi, compteur « N adresses recevront ce mail », destinataires numérotés, « N sur M » quand on en retire, nombre rappelé sur le bouton de validation.

## 0.7.1 — 09/11/2026
- Inscription en ligne : plus de question « droit à l'image » (l'adhésion l'accorde, comme le dit le règlement intérieur).
- Règlement intérieur par défaut : la séance d'essai est gratuite (le bulletin papier indiquait 10 €).

## 0.7.0 — 09/11/2026
- **Bulletin d'inscription en ligne**, repris du bulletin papier 2026/2027, pour une saisie sur téléphone :
  - tél. portable et tél. domicile, attestation pour le comité d'entreprise, « Où avez-vous connu l'ASCS ? » (plusieurs choix, dont Le Progrès, Plaquette, Boîte aux lettres, et « Autre ») ;
  - **règlement** : moyens prévus (chèque — en 1 à 3 fois —, espèces, chèques ANCV avec 5 € de frais, carte bancaire) et aides du bulletin (coupons sport / culture de la mairie, Pass'Région, Pass'Sport avec son code), conditions affichées ; **récapitulatif en direct** : tarif de chaque activité (réduction Rillieux d'après l'adresse saisie), aides déduites, frais, reste à régler ;
  - assurance « groupements sportifs » : souscrite (option n°) ou non ;
  - **règlement intérieur** affiché, case « reconnais avoir pris connaissance du règlement intérieur », « Je soussigné(e) » proposé (le parent pour un mineur), fait à, date du jour.
- Après l'envoi : récapitulatif et **pièces à apporter** (certificat médical pour les activités sportives, coupon et chèque de caution, copie Pass'Région, Pass'Sport, chèques), aussi dans le mail de confirmation.
- Côté bureau : tout apparaît sur la demande ; à la validation, tél. domicile, attestation CE et canaux vont sur la fiche, le règlement prévu, l'assurance et la signature dans les remarques de l'inscription.
- Règlement intérieur modifiable dans Accueil > QR codes (texte du bulletin 2026/2027 par défaut).

## 0.6.0 — 08/11/2026
- **Compte bancaire** (onglet Financier) : comptes avec leur solde (nommables : « Compte courant », « Livret »…), opérations par saison ou toutes saisons, recherche, filtres recettes / dépenses / à ventiler, état de la ventilation de chaque ligne.
- **Ventilation d'une opération** : plusieurs lignes (section principale, catégorie, montant, observation), total contrôlé, virement interne. Ventilation proposée d'après l'historique.
- **Import des relevés bancaires** : CSV de la banque (format WinDev « date ; date valeur ; débit ; crédit ; libellé ; solde », ou CSV avec titres de colonnes) ou OFX. Aperçu avant d'enregistrer ; opérations déjà présentes reconnues (un relevé peut être réimporté sans doublon) ; saison déduite de la date.
  - **Ventilation automatique** des opérations récurrentes (URSSAF, retraite, assurance, banque…) : reprise de la ventilation des deux dernières opérations au même libellé, y compris les partages (50 / 50 entre deux sections), avec la mention « Traitement Automatique fait le … » comme dans WinDev. Sur la saison 2025-2026, la proposition est juste dans 97 % des cas où elle est faite.
  - Remises de chèques rapprochées et ventilées juste après l'import.
- **Contrôle** : opérations non ventilées ou mal ventilées, soldes qui ne s'enchaînent pas (opération manquante), remises de chèques déposées sans ligne bancaire.
- **Export Excel** (CSV) des comptes de la saison, une ligne par ventilation.
- **Reporting** : adhérents (femmes / hommes, Rillieux / extérieurs, nouveaux / réinscrits / de retour, tranches d'âge, écart avec la saison précédente), adhérents par section sur trois saisons, courbe d'inscriptions cumulées sur trois saisons, reporting mairie par activité (Rillieux, mineurs, femmes / hommes) avec export Excel.
- Mode transition : les comptes restent tenus dans WinDev ; l'import d'un relevé fonctionne en aperçu seulement et la ventilation est en lecture. Tout s'active après la bascule.

## 0.5.2 — 08/11/2026
- **Deux comptes d'envoi** : les mailings en nombre partent d'un compte SMTP à part (SMTP_MAILING_USER / SMTP_MAILING_PASS, par exemple contact@m.ascs.fr), les factures, confirmations et liens des animateurs du compte principal (SMTP_USER, par exemple contact@a.ascs.fr). Un mailing mal reçu n'abîme pas la réputation des envois individuels. Sans second compte, le principal sert à tout.

## 0.5.1 — 08/11/2026
- **Envoi des mails par SMTP authentifié** (compte mail o2switch, par exemple contact@m.ascs.fr) : réglé dans `.env` (SMTP_HOST, SMTP_PORT, SMTP_SECURITE, SMTP_USER, SMTP_PASS). L'expéditeur est alors ce compte ; une connexion par lot d'envois.
- **Adresse de réponse** (Paramétrage > Facture) : les réponses des adhérents arrivent à cette adresse (par exemple contact@ascs.fr).
- Bouton **« M'envoyer un mail d'essai »** dans Paramétrage > Facture.
- Un lot de mailing s'arrête dès la première erreur d'identifiant ou de connexion SMTP (au lieu d'échouer 40 fois).

## 0.5.0 — 08/11/2026
- **Mailings** (onglet Mail) : destinataires = inscrits de la saison, de sections choisies, inscrits N-1 pas encore réinscrits, ou anciens adhérents. Nombre de destinataires et exclus affichés avant de préparer ; {prenom} et {nom} personnalisés.
  - Toujours un brouillon d'abord : aperçu, liste des destinataires (on peut en retirer), puis validation par le bureau.
  - Exclus d'office : refus de mail, adresse marquée invalide ou mal formée ; une adresse partagée par une famille ne reçoit qu'un mail.
  - Envoi par lots de 40 (limite horaire du serveur mail) : premier lot à la validation, les suivants par bouton ou par la tâche planifiée `bin/envois.php`.
  - Lien de désinscription en bas de chaque mail (page de confirmation, puis « refus de mail » coché sur la fiche).
- **SMS** : mêmes destinataires (portables seulement, refus de SMS exclus, un SMS par numéro), compteur de caractères et de SMS.
  - Depuis le téléphone de l'association : la page de l'envoi ouvre l'application Messages avec numéros et texte, par paquets de 10 ou un par un, puis « envoyé ».
  - En option, envoi automatique par la passerelle « SMS Gateway for Android » (SMS_USER / SMS_PASS dans .env).
- **Validation des mails** : adresses mal formées, fautes de frappe connues (gmial.com, hotmail.fe…) avec correction en un clic, domaines qui ne reçoivent pas de mail ; marquer / retirer « invalide ».
- **Ajout Outlook** : export CSV des inscrits de la saison au format d'import des contacts Outlook (catégories = sections).
- **Demandes en ligne** : mail de confirmation à la personne (référence et activités demandées).
- Mode transition : désinscriptions et adresses marquées invalides sont réappliquées après un import WinDev (sauf si le mail a changé).

## 0.4.1 — 08/11/2026
- **Inscriptions par section** (Accueil > Par section) : effectifs de la saison, séances notées, mail de l'animateur ; fiche de section avec les inscrits (âge, téléphone, mail, parent, état du paiement, nombre de présences).
- **Feuille d'émargement** à imprimer (A4 paysage) : inscrits, téléphone, une colonne par séance datée d'après les créneaux de la section (première séance et nombre de colonnes réglables), trois lignes libres pour les essais.
- **Liste de présence des animateurs** : page pour téléphone ouverte par un lien secret propre à chaque section, sans mot de passe (QR code et lien sur la fiche de section). L'animateur coche les présents de la séance du jour ou d'une séance passée de la saison et enregistre ; il ne voit que prénom, nom, âge des mineurs et téléphone.
- Envoi du lien par mail à l'animateur d'une section, ou à tous les animateurs d'un coup ; « Changer le lien » coupe l'ancien.
- La fusion de fiches reprend aussi les présences.

## 0.4.0 — 08/11/2026
- **Tableau de bord** (page d'accueil) pour la saison choisie : inscrits sur trois saisons (avec l'écart à la précédente), inscriptions non payées et reste dû, montant des inscriptions et réglé, recettes / dépenses / résultat d'après les relevés bancaires, inscrits par famille, répartition femmes / hommes, recettes et dépenses par catégorie. Cours d'essai exclus des effectifs.
- **Paramétrage** : saisons, familles, sections principales et sections (tarif extérieurs, section principale, cours d'essai, mail de l'animateur, campagne HelloAsso, seuil de rentabilité, créneaux jour + heure). Création, modification ; suppression seulement si rien ne s'en sert. Nouvelle saison proposée à la suite de la dernière.
- **Résultat par section** (inscrits, inscriptions, réglé, écart au seuil de rentabilité) et **par section principale** (inscriptions, recettes et dépenses ventilées, résultat).
- Mode transition : un import WinDev remplace le paramétrage (rappel sur les pages).

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
- Après 0.6.0 : bascule (arrêt de WinDev), puis corrections et demandes au fil de l'usage.
- Abandonnés : scan de chèques, Copilot, imports MonClub et Sportyneo.
