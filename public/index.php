<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/Web/helpers.php';

use Ascs\Web\App;
use Ascs\Web\Controllers\AdherentController;
use Ascs\Web\Controllers\AuthController;
use Ascs\Web\Controllers\InscriptionController;
use Ascs\Web\Controllers\ReglementController;
use Ascs\Web\Controllers\DemandeController;
use Ascs\Web\Controllers\EnLigneController;
use Ascs\Web\Controllers\FactureController;
use Ascs\Web\Controllers\ParametrageController;
use Ascs\Web\Controllers\ResultatController;
use Ascs\Web\Controllers\SectionController;
use Ascs\Web\Controllers\PresenceController;
use Ascs\Web\Controllers\MailingController;
use Ascs\Web\Controllers\BanqueController;
use Ascs\Web\Controllers\ReportingController;
use Ascs\Web\Controllers\HelloAssoController;
use Ascs\Web\Controllers\PublicController;
use Ascs\Web\Controllers\RemiseController;
use Ascs\Web\Controllers\HomeController;
use Ascs\Web\Controllers\ImportController;
use Ascs\Web\Controllers\UtilisateurController;
use Ascs\Web\Router;

App::securityHeaders();

// Serveur PHP intégré (tests) : laisser servir les fichiers statiques.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && basename($f) !== 'index.php') {
        return false;
    }
}

// Page d'installation : fonctionne même si la base est inaccessible (elle sert à le diagnostiquer).
$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#/installation/?$#', $chemin)) {
    (new Ascs\Web\Install())->handle($_SERVER['REQUEST_METHOD'] ?? 'GET');
    exit;
}

try {
    $app = App::boot();
} catch (Throwable $e) {
    http_response_code(500);
    error_log('ASCS démarrage : ' . $e->getMessage());
    App::startSession();
    echo '<!doctype html><meta charset="utf-8"><title>ASCS</title><p>Application indisponible : base de données inaccessible ou non initialisée.</p><p><a href="' . e(url('/installation')) . '">Ouvrir la page d\'installation</a> pour voir la cause.</p>';
    exit;
}

// Base vide (tables pas encore créées) : direction la page d'installation.
if ($app->user === null) {
    try {
        $app->pdo->query('SELECT 1 FROM utilisateur LIMIT 1');
    } catch (Throwable) {
        header('Location: ' . url('/installation'), true, 303);
        exit;
    }
}

// Nouvelle version déposée : création des tables qui manquent (sans passer par le Terminal ni la page d'installation).
// Repère : l'empreinte du schéma tel que le programme le connaît (et non le numéro de version), pour qu'un fichier
// encore en cache côté serveur pendant le déploiement ne marque pas la mise à jour comme faite avec l'ancien schéma.
try {
    $empreinte = Ascs\Schema::empreinte();
    if (Ascs\Parametres::get($app->pdo, 'schema_empreinte') !== $empreinte) {
        Ascs\Migrator::run($app->pdo);
        Ascs\Parametres::set($app->pdo, 'schema_empreinte', $empreinte);
        Ascs\Parametres::set($app->pdo, 'schema_version', Ascs\Web\Ribbon::version());
    }
} catch (Throwable $e) {
    error_log('ASCS mise à jour du schéma : ' . $e->getMessage());
}

$router = new Router();
$router->get('/connexion', [AuthController::class, 'form'], true);
$router->post('/connexion', [AuthController::class, 'connexion'], true);
$router->post('/deconnexion', [AuthController::class, 'deconnexion']);
$router->post('/saison', [AuthController::class, 'saison']);
$router->get('/', [HomeController::class, 'index']);

$router->get('/adherents', [AdherentController::class, 'index']);
$router->get('/adherents/nouveau', [AdherentController::class, 'nouveau']);
$router->get('/adherents/doublons', [AdherentController::class, 'doublons']);
$router->post('/adherents', [AdherentController::class, 'creer']);
$router->get('/adherents/{id}', [AdherentController::class, 'fiche']);
$router->get('/adherents/{id}/fusion/{autre}', [AdherentController::class, 'fusion']);
$router->post('/adherents/{id}/fusion/{autre}', [AdherentController::class, 'fusionner']);
$router->post('/adherents/{id}', [AdherentController::class, 'modifier']);

$router->get('/inscriptions', [InscriptionController::class, 'index']);
$router->get('/inscriptions/nouveau', [InscriptionController::class, 'nouvelle']);
$router->post('/inscriptions', [InscriptionController::class, 'creer']);
$router->get('/inscriptions/{id}', [InscriptionController::class, 'fiche']);
$router->post('/inscriptions/{id}', [InscriptionController::class, 'modifier']);
$router->get('/inscriptions/{id}/facture', [FactureController::class, 'pdf']);
$router->post('/inscriptions/{id}/facture/envoyer', [FactureController::class, 'envoyer']);
$router->get('/parametres/facture', [FactureController::class, 'reglages']);
$router->get('/resultats/sections', [ResultatController::class, 'sections']);
$router->get('/resultats/sections-principales', [ResultatController::class, 'principales']);
$router->get('/parametrage/{cle}', [ParametrageController::class, 'index']);
$router->get('/parametrage/{cle}/nouveau', [ParametrageController::class, 'nouveau']);
$router->post('/parametrage/{cle}', [ParametrageController::class, 'enregistrer']);
$router->get('/parametrage/{cle}/{id}', [ParametrageController::class, 'fiche']);
$router->post('/parametrage/{cle}/{id}', [ParametrageController::class, 'enregistrer']);
$router->post('/parametrage/{cle}/{id}/supprimer', [ParametrageController::class, 'supprimer']);
$router->post('/parametres/facture', [FactureController::class, 'enregistrer']);
$router->post('/parametres/facture/essai', [FactureController::class, 'essai']);
$router->get('/parametres/facture/image/{nom}', [FactureController::class, 'image']);
$router->post('/inscriptions/{id}/supprimer', [InscriptionController::class, 'supprimer']);

$router->get('/reglements', [ReglementController::class, 'index']);
$router->get('/reglements/par-type', [ReglementController::class, 'parType']);
$router->get('/reglements/nouveau', [ReglementController::class, 'nouveau']);
$router->post('/reglements', [ReglementController::class, 'creer']);
$router->get('/reglements/{id}', [ReglementController::class, 'fiche']);
$router->post('/reglements/{id}', [ReglementController::class, 'modifier']);
$router->post('/reglements/{id}/supprimer', [ReglementController::class, 'supprimer']);

// Pages publiques (QR codes) : sans connexion
$router->get('/inscription', [PublicController::class, 'inscription'], true);
$router->post('/inscription', [PublicController::class, 'envoyerInscription'], true);
$router->get('/essai', [PublicController::class, 'essai'], true);
$router->post('/essai', [PublicController::class, 'envoyerEssai'], true);
$router->get('/merci', [PublicController::class, 'merci'], true);
$router->get('/presence/{jeton}', [PresenceController::class, 'liste'], true);
$router->get('/desinscription/{jeton}', [MailingController::class, 'desinscription'], true);
$router->post('/desinscription/{jeton}', [MailingController::class, 'desinscrire'], true);
$router->get('/mailing', [MailingController::class, 'index']);
$router->get('/banque', [BanqueController::class, 'index']);
$router->post('/banque/nom', [BanqueController::class, 'nommer']);
$router->get('/banque/ligne/{id}', [BanqueController::class, 'ligne']);
$router->post('/banque/ligne/{id}', [BanqueController::class, 'ventiler']);
$router->get('/banque/import', [BanqueController::class, 'import']);
$router->post('/banque/import', [BanqueController::class, 'lire']);
$router->post('/banque/import/confirmer', [BanqueController::class, 'confirmer']);
$router->get('/banque/controle', [BanqueController::class, 'controle']);
$router->get('/banque/export', [BanqueController::class, 'export']);
$router->post('/banque/export/frais', [BanqueController::class, 'fraisAscs']);
$router->get('/reporting/adherents', [ReportingController::class, 'adherents']);
$router->get('/reporting/sections', [ReportingController::class, 'sections']);
$router->get('/reporting/courbe', [ReportingController::class, 'courbe']);
$router->get('/reporting/mairie', [ReportingController::class, 'mairie']);
$router->get('/mailing/nouveau', [MailingController::class, 'nouveau']);
$router->get('/mailing/compter', [MailingController::class, 'compter']);
$router->post('/mailing/whatsapp', [MailingController::class, 'whatsapp']);
$router->post('/mailing', [MailingController::class, 'creer']);
$router->get('/mailing/{id}', [MailingController::class, 'fiche']);
$router->post('/mailing/{id}/modifier', [MailingController::class, 'modifier']);
$router->post('/mailing/{id}/valider', [MailingController::class, 'valider']);
$router->post('/mailing/{id}/lot', [MailingController::class, 'lot']);
$router->post('/mailing/{id}/test', [MailingController::class, 'test']);
$router->post('/mailing/{id}/marquer', [MailingController::class, 'marquer']);
$router->post('/mailing/{id}/supprimer', [MailingController::class, 'supprimer']);
$router->post('/mailing/{id}/destinataire/{dest}', [MailingController::class, 'destinataire']);
$router->get('/mails/verification', [MailingController::class, 'verification']);
$router->post('/mails/verification/{id}', [MailingController::class, 'verificationAction']);
$router->get('/contacts/outlook', [MailingController::class, 'outlook']);
$router->get('/contacts', [MailingController::class, 'contacts']);
$router->post('/contacts', [MailingController::class, 'contactsSync']);
$router->post('/presence/{jeton}', [PresenceController::class, 'enregistrer'], true);
$router->get('/presence/{jeton}/changer/{inscription}', [PresenceController::class, 'changer'], true);
$router->get('/animateur/{jeton}', [PresenceController::class, 'animateur'], true);
$router->get('/controle/{jeton}', [PresenceController::class, 'controle'], true);
$router->post('/controle/{jeton}', [PresenceController::class, 'controleEnregistrer'], true);
$router->get('/controle/{jeton}/ajouter', [PresenceController::class, 'controleAjouter'], true);
$router->post('/controle/{jeton}/ajouter', [PresenceController::class, 'controleEnregistrerAjout'], true);
$router->post('/sections/controleurs', [SectionController::class, 'controleurs']);
$router->get('/activites-arretees', [SectionController::class, 'arrets']);
$router->post('/activites-arretees', [SectionController::class, 'arretsEnregistrer']);
$router->get('/animateur/{jeton}/ajouter', [PresenceController::class, 'animateurAjouter'], true);
$router->post('/animateur/{jeton}/ajouter', [PresenceController::class, 'animateurEnregistrerAjout'], true);
$router->get('/presence/{jeton}/ajouter', [PresenceController::class, 'ajouter'], true);
$router->post('/presence/{jeton}/ajouter', [PresenceController::class, 'enregistrerAjout'], true);
$router->get('/sections/a-relancer', [SectionController::class, 'aRelancer']);
$router->get('/sections/cartes', [SectionController::class, 'cartes']);
$router->get('/sections/presences', [SectionController::class, 'presences']);
$router->post('/sections/cartes/reglage', [SectionController::class, 'cartesReglage']);
$router->post('/sections/cartes/{inscription}/recharger', [SectionController::class, 'cartesRecharger']);
$router->post('/sections/cartes/recharge/{id}/supprimer', [SectionController::class, 'cartesSupprimerRecharge']);
$router->post('/sections/a-relancer/{id}', [SectionController::class, 'statutParticipant']);
$router->post('/animateur/{jeton}', [PresenceController::class, 'animateurEnregistrer'], true);
$router->get('/animateur/{jeton}/changer/{inscription}', [PresenceController::class, 'animateurChanger'], true);
$router->post('/animateur/{jeton}/changer/{inscription}', [PresenceController::class, 'animateurEnregistrerChangement'], true);
$router->post('/sections/animateur/nouveau-lien', [SectionController::class, 'nouveauLienAnimateur']);
$router->post('/sections/animateur/envoyer', [SectionController::class, 'envoyerLienAnimateur']);
$router->post('/presence/{jeton}/changer/{inscription}', [PresenceController::class, 'enregistrerChangement'], true);
$router->post('/sections/changements/{id}/vu', [SectionController::class, 'changementVu']);
$router->post('/sections/changements/{id}/annuler', [SectionController::class, 'changementAnnuler']);
$router->get('/sections', [SectionController::class, 'index']);
$router->post('/sections/envoyer-liens', [SectionController::class, 'envoyerTous']);
$router->get('/sections/{id}', [SectionController::class, 'fiche']);
$router->get('/sections/{id}/emargement', [SectionController::class, 'emargement']);
$router->post('/sections/{id}/envoyer-lien', [SectionController::class, 'envoyerLien']);
$router->post('/sections/{id}/nouveau-lien', [SectionController::class, 'nouveauLien']);

$router->get('/demandes', [DemandeController::class, 'index']);
$router->get('/demandes/{id}', [DemandeController::class, 'fiche']);
$router->post('/demandes/{id}', [DemandeController::class, 'valider']);
$router->post('/demandes/{id}/refuser', [DemandeController::class, 'refuser']);
$router->post('/demandes/{id}/retablir', [DemandeController::class, 'retablir']);
$router->get('/en-ligne', [EnLigneController::class, 'index']);
$router->post('/en-ligne', [EnLigneController::class, 'enregistrer']);
$router->get('/en-ligne/affiches', [EnLigneController::class, 'affiches']);

$router->get('/helloasso', [HelloAssoController::class, 'index']);
$router->post('/helloasso/synchroniser', [HelloAssoController::class, 'synchroniser']);
$router->post('/helloasso/automatique', [HelloAssoController::class, 'automatique']);
$router->post('/helloasso/notification/{jeton}', [HelloAssoController::class, 'notification'], true, false);
$router->get('/helloasso/{id}', [HelloAssoController::class, 'fiche']);
$router->post('/helloasso/{id}', [HelloAssoController::class, 'valider']);
$router->post('/helloasso/{id}/ignorer', [HelloAssoController::class, 'ignorer']);
$router->post('/helloasso/{id}/retablir', [HelloAssoController::class, 'retablir']);

$router->get('/remises', [RemiseController::class, 'index']);
$router->post('/remises', [RemiseController::class, 'creer']);
$router->post('/remises/depuis', [RemiseController::class, 'depuis']);
$router->get('/remises/nouvelle', [RemiseController::class, 'nouvelle']);
$router->get('/remises/{id}', [RemiseController::class, 'fiche']);
$router->post('/remises/{id}', [RemiseController::class, 'enregistrer']);
$router->get('/remises/{id}/imprimer', [RemiseController::class, 'imprimer']);
$router->post('/remises/{id}/retirer', [RemiseController::class, 'retirer']);
$router->post('/remises/{id}/supprimer', [RemiseController::class, 'supprimer']);

$router->get('/import', [ImportController::class, 'index']);
$router->post('/import', [ImportController::class, 'envoyer']);
$router->post('/import/lancer', [ImportController::class, 'lancer']);
$router->post('/import/annuler', [ImportController::class, 'annuler']);
$router->post('/import/bascule', [ImportController::class, 'basculer']);
$router->post('/import/effacer', [ImportController::class, 'effacer']);
$router->get('/import/sauvegarde', [ImportController::class, 'sauvegarde']);

$router->get('/journal-erreurs', [UtilisateurController::class, 'journal']);
$router->get('/utilisateurs', [UtilisateurController::class, 'index']);
$router->post('/utilisateurs', [UtilisateurController::class, 'creer']);
$router->post('/utilisateurs/{id}/mot-de-passe', [UtilisateurController::class, 'motDePasse']);
$router->post('/utilisateurs/{id}/activer', [UtilisateurController::class, 'activer']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (App::$base !== '' && str_starts_with($path, App::$base)) {
    $path = substr($path, strlen(App::$base)) ?: '/';
}
if ($path === '/index.php') {
    $path = '/';
}
$path = rtrim($path, '/') ?: '/';

try {
    $router->dispatch($app, $_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    if ($e instanceof PDOException && str_contains($e->getMessage(), '42S02') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['_maj'])) {
        try {
            Ascs\Migrator::run($app->pdo);
            Ascs\Parametres::set($app->pdo, 'schema_empreinte', Ascs\Schema::empreinte());
            $u = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . $u . (str_contains($u, '?') ? '&' : '?') . '_maj=1', true, 303);
            exit;
        } catch (Throwable) {
            // on affiche l'erreur d'origine
        }
    }
    $f = \Ascs\Web\Erreurs::consigner($e);
    http_response_code(500);
    // Détail technique pour le bureau (connecté) ; une page publique n'affiche que l'explication et la référence.
    echo $app->render('erreur', ['titre' => 'Erreur', 'fiche' => $f, 'detail' => isset($_SESSION['uid']),
        'message' => 'L\'opération n\'a pas abouti. Revenez en arrière et recommencez ; si cela persiste, transmettez le détail ci-dessous.'], 'Erreur', 'accueil', 500);
}
