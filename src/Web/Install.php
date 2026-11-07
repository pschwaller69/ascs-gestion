<?php
declare(strict_types=1);

namespace Ascs\Web;

use Ascs\Config;
use Ascs\Db;
use Ascs\Migrator;
use Ascs\Schema;
use PDO;

/**
 * Page /installation : remplace les commandes du Terminal pour la mise en route.
 * Fonctionne sans base (elle sert justement à diagnostiquer la connexion).
 * Ouverte seulement si SETUP_CODE (12 caractères min.) est défini dans .env,
 * et fermée définitivement dès qu'un compte du bureau existe.
 */
final class Install
{
    private array $cfg;
    private ?PDO $pdo = null;
    private ?string $erreurConnexion = null;

    public function __construct()
    {
        $this->cfg = Config::load();
        try {
            $this->pdo = Db::connect($this->cfg);
        } catch (\Throwable $e) {
            $this->erreurConnexion = $e->getMessage();
        }
    }

    private function nbUtilisateurs(): ?int
    {
        if (!$this->pdo) {
            return null;
        }
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn();
        } catch (\PDOException) {
            return null;
        }
    }

    public function handle(string $method): void
    {
        App::startSession();
        $msg = null;
        $erreur = null;

        if (($this->nbUtilisateurs() ?? 0) > 0) {
            $this->page('fermee', []);
            return;
        }
        $code = $this->cfg['setup_code'];
        if (mb_strlen($code) < 12) {
            $this->page('sans_code', []);
            return;
        }

        if ($method === 'POST') {
            if (!App::csrfValid()) {
                $erreur = 'Formulaire expiré : rechargez la page et recommencez.';
            } else {
                $action = $_POST['action'] ?? '';
                if ($action === 'code') {
                    if (hash_equals($code, (string) ($_POST['code'] ?? ''))) {
                        session_regenerate_id(true);
                        $_SESSION['install_ok'] = true;
                    } else {
                        sleep(2);
                        $erreur = 'Code incorrect.';
                    }
                } elseif (empty($_SESSION['install_ok'])) {
                    $erreur = 'Saisissez d\'abord le code d\'installation.';
                } elseif ($action === 'tables' && $this->pdo) {
                    try {
                        $msg = Migrator::run($this->pdo);
                    } catch (\Throwable $e) {
                        $erreur = 'Création des tables impossible : ' . $e->getMessage();
                    }
                } elseif ($action === 'compte' && $this->pdo) {
                    $erreur = $this->creerCompte();
                    if ($erreur === null) {
                        unset($_SESSION['install_ok']);
                        $_SESSION['flash'][] = ['type' => 'ok', 'message' => 'Compte créé. L\'installation est terminée : connectez-vous.'];
                        header('Location: ' . url('/connexion'), true, 303);
                        return;
                    }
                }
            }
        }

        if (empty($_SESSION['install_ok'])) {
            $this->page('code', ['erreur' => $erreur]);
            return;
        }
        $tables = $this->pdo ? Migrator::tablesPresentes($this->pdo) : 0;
        $this->page('etapes', [
            'cfg' => $this->cfg, 'erreurConnexion' => $this->erreurConnexion, 'tables' => $tables,
            'totalTables' => count(Schema::tables()), 'msg' => $msg, 'erreur' => $erreur,
            'envFichier' => is_file(dirname(__DIR__, 2) . '/.env'),
            'php' => PHP_VERSION, 'pdoMysql' => extension_loaded('pdo_mysql'),
            'uploadMax' => ini_get('upload_max_filesize'), 'postMax' => ini_get('post_max_size'),
        ]);
    }

    private function creerCompte(): ?string
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $mdp = (string) ($_POST['mot_de_passe'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse mail invalide.';
        }
        if (mb_strlen($mdp) < 12) {
            return 'Le mot de passe doit faire au moins 12 caractères.';
        }
        if ($mdp !== (string) ($_POST['confirmation'] ?? '')) {
            return 'Les deux mots de passe ne correspondent pas.';
        }
        if (Migrator::tablesPresentes($this->pdo) < count(Schema::tables())) {
            return 'Créez d\'abord les tables (étape 2).';
        }
        $this->pdo->prepare('INSERT INTO utilisateur (email, nom, mot_de_passe, actif, cree_le) VALUES (?,?,?,1,?)')
            ->execute([$email, mb_substr($nom, 0, 100), password_hash($mdp, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        return null;
    }

    private function page(string $etat, array $vars): void
    {
        $version = Ribbon::version();
        extract($vars, EXTR_SKIP);
        require dirname(__DIR__, 2) . '/templates/installation.php';
    }
}
