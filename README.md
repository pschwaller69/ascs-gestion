# ASCS — gestion de l'association (migration WinDev → web)

**Version : voir `VERSION` — historique dans `CHANGELOG.md`.**

PHP 8.3 natif + PDO, **aucune dépendance** (pas de Composer à installer sur o2switch). Base MariaDB.

## Installation sur o2switch

1. cPanel → *Bases de données MySQL* : créer la base et l'utilisateur.
2. Déposer le dossier du projet **en dehors** de `public_html` (par ex. `~/ascs`) ; seul `public/` est exposé
   (sous-domaine pointant sur `~/ascs/public`).
3. Copier `.env.example` en `.env` et renseigner `DB_NAME`, `DB_USER`, `DB_PASS`.
4. Créer les tables : `php bin/migrate.php` (terminal cPanel), ou coller dans phpMyAdmin le SQL obtenu avec
   `php bin/migrate.php --print`.

## Mise en route sur o2switch (sans Terminal, depuis 0.2.1)
1. Dans cPanel : PHP 8.3 (Sélectionner une version de PHP), une base MySQL et son utilisateur avec tous les privilèges.
2. Envoyer le projet (gestionnaire de fichiers ou FTP) et faire pointer un sous-domaine sur le dossier `public/`.
3. Copier `.env.example` en `.env` à la racine du projet et le remplir (DB_NAME, DB_USER, DB_PASS, et un `SETUP_CODE` d'au moins 12 caractères).
4. Ouvrir `https://votre-sous-domaine/installation` : la page teste la connexion (et dit quoi corriger), crée les tables, puis le premier compte.
5. Se connecter, puis Paramétrage > Import WinDev pour envoyer les 10 fichiers XML.
6. Les autres comptes : Paramétrage > Comptes du bureau. Le jour de l'abandon de WinDev : bouton « Basculer définitivement » sur la page d'import.

Les commandes `bin/*.php` restent disponibles pour le Terminal.

## Déploiement automatique (o2switch)
- Le dossier `/home4/mvpm7496/gestion.ascs.fr` est un clone du dépôt public `pschwaller69/ascs-gestion` (cPanel > Git Version Control).
- Une tâche cron lance toutes les 5 minutes `bin/deployer.sh`, qui récupère la dernière version publiée sur GitHub (avance rapide seulement ; jamais `.env` ni `storage/`). Journal : `storage/deploiement.log`.
- Le dossier du clone doit avoir les droits 0711 (cPanel le crée en 0700, ce qui bloque le site avec une erreur 403).
- Ne modifiez pas les fichiers du projet directement sur le serveur : la mise à jour suivante serait bloquée (voir le journal).

## Mise à jour vers une nouvelle version
Remplacer les fichiers du projet (sans toucher à `.env` ni au dossier `storage/`). À la première page ouverte, l'application crée elle-même les tables nouvelles (depuis 0.3.4).

## Mailings et SMS (depuis 0.5.0)
- Tâche cron toutes les 5 minutes pour envoyer les lots suivants : `/usr/local/bin/php /home4/mvpm7496/gestion.ascs.fr/bin/envois.php`
- SMS depuis le téléphone de l'association : rien à configurer (désactiver les messages de groupe MMS/RCS dans l'application Messages).
- SMS automatiques (facultatif) : application « SMS Gateway for Android » sur le téléphone, identifiants dans `.env` (SMS_USER, SMS_PASS).

## Facture (depuis 0.3.8)
1. Paramétrage > Facture : coordonnées de l'association, signataire, logo et signature (PNG ou JPEG), adresse d'envoi des mails (une adresse existante du domaine chez o2switch, par exemple contact@ascs.fr).
2. Sur une inscription : « Facture PDF » pour l'ouvrir ou l'imprimer, « Envoyer la facture par mail » pour l'envoyer en pièce jointe. Les images sont gardées dans `storage/documents` (jamais servies directement).

## Remise de chèques (depuis 0.3.3)
1. Accueil > Remise de chèques : choisir la date de pose, décocher les chèques absents du paquet, « Créer la remise ». Au-delà de 30 chèques, plusieurs remises sont créées.
2. Imprimer le contrôle de chaque remise, remplir un bordereau de la banque par remise et noter son numéro dans la remise (facultatif mais conseillé).
3. Quand la ligne « REM CHQ » arrive dans les comptes, elle est ventilée automatiquement par section principale.

## Inscriptions et cours d'essai en ligne (depuis 0.3.1)
1. Accueil > QR codes : cocher « Inscriptions en ligne ouvertes » et/ou « Demandes de cours d'essai ouvertes », choisir la saison, puis les activités proposées (texte et lien de paiement HelloAsso facultatifs).
2. Cliquer sur « Imprimer les deux affiches » : un QR code pour les inscriptions, un pour les cours d'essai (une affiche A4 chacun). Mettre `APP_URL` dans `.env` si l'adresse des QR doit être différente de celle utilisée par le bureau.
3. Les demandes arrivent dans Accueil > Demandes (rappel sur la page d'accueil) : choisir la fiche existante ou « Nouvelle fiche », puis valider.

## HelloAsso (depuis 0.3.0)
1. Sur helloasso.com, « Mon compte > Intégrations et API » : créer les identifiants API.
2. Dans `.env` : `HELLOASSO_CLIENT_ID`, `HELLOASSO_CLIENT_SECRET`, `HELLOASSO_ORGANISATION` (le nom dans helloasso.com/associations/<nom>).
3. Dans l'application : Accueil > HelloAsso > « Synchroniser maintenant ». Les 12 derniers mois sont lus ; « Récupérer des adhésions plus anciennes » pour remonter plus loin.
4. cPanel > Tâches Cron, toutes les 15 minutes : `php ~/ascs/bin/helloasso.php` (adapter le chemin du projet ; utiliser le PHP 8.3 si cPanel le demande).
5. Validation automatique (depuis 0.3.6, réglage sur la page HelloAsso) : une adhésion est validée seule quand il n'y a aucun doute (fiche unique très probable, ou nouvelle personne sans fiche ressemblante avec le genre dans le formulaire, et section déjà retenue pour ce formulaire et ce tarif). Les autres attendent la validation du bureau, avec la raison affichée.
6. Facultatif, notifications instantanées : mettre un jeton secret dans `HELLOASSO_WEBHOOK_TOKEN` et déclarer chez HelloAsso l'URL `https://<sous-domaine>/helloasso/notification/<jeton>`. La notification ne sert qu'à déclencher une synchronisation : les données sont toujours relues sur l'API.

## Importer les données WinDev (rejouable à volonté)

1. Exporter les 10 tables en XML depuis WinDev, dans un même dossier, avec ces noms :
   `saison, famille_section, section_principale, section, adherent, inscription, reglement, type_compte, compte, detail_compte` (`.xml`).
   Les fichiers `.xsl` ne servent pas. Les tables tampon SMS et mailing ne sont pas importées.
2. Essai à blanc (n'écrit rien) : `php bin/import.php ~/export --dry-run`
3. Import réel : `php bin/import.php ~/export`

Chaque import lit le rapport affiché (et enregistré dans `storage/`). Il est **atomique** : en cas d'erreur
ou de référence cassée, rien n'est modifié.

Règles :
- Les identifiants WinDev sont conservés : un nouvel import **met à jour** ce qui existe, **ajoute** le nouveau
  et **supprime** ce qui a disparu de WinDev. Tant que WinDev reste utilisé, c'est lui la référence ;
  ne pas saisir de données dans l'application web sur ces tables avant la bascule.
- Inscriptions en double (même adhérent, section, saison) : fusionnées à l'import. Plusieurs payées → la plus
  ancienne est gardée, montants additionnés, règlements rattachés. Une seule payée → celle-là. Aucune payée → la plus récente.
- Chaque section reçoit un **jeton de présence** (UUID) à sa première apparition ; il n'est jamais modifié par
  les imports suivants (pour la page sans mot de passe des animateurs).
- `adherent.genre` : 0 = femme, 1 = homme.
- Non repris : `Signature` (vide), `AssuranceSport`/`CLeProgrès`/`CPlaquette`/`CBoiteAuxLettres` (toujours à 0),
  `compte.Details` (texte recalculable depuis `detail_compte`).

## Contrôles

`DB_DRIVER=sqlite DB_SQLITE_PATH=/tmp/t.sqlite php bin/migrate.php && php bin/import.php <xml> && php tests/verify.php <xml>`
recalcule les totaux directement depuis les XML, vérifie l'absence d'orphelins et de doublons, et prouve que
rejouer l'import ne change rien.
