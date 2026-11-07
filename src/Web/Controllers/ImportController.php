<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Import\Importer;
use Ascs\Parametres;
use Ascs\Web\App;

/**
 * Import WinDev depuis le navigateur : envoi des 10 fichiers XML, simulation, puis import réel.
 * Les fichiers sont gardés dans storage/import/<jeton>/ (hors du dossier public) le temps de confirmer.
 */
final class ImportController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function dossierBase(): string
    {
        return dirname(__DIR__, 3) . '/storage/import';
    }

    /** Fichier envoyé -> table : la table dont le nom figure dans le nom du fichier (la plus longue l'emporte). */
    public static function tablePour(string $nomFichier): ?string
    {
        $n = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', pathinfo($nomFichier, PATHINFO_FILENAME)));
        $n = '_' . trim($n, '_') . '_';
        $tables = Importer::FILES;
        usort($tables, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($tables as $t) {
            if (str_contains($n, '_' . $t . '_')) {
                return $t;
            }
        }
        return null;
    }

    private function nettoyerAnciens(): void
    {
        foreach (glob(self::dossierBase() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 86400) {
                $this->supprimerDossier($d);
            }
        }
    }

    private function supprimerDossier(string $d): void
    {
        foreach (glob($d . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($d);
    }

    private function bascule(): ?string
    {
        return Parametres::get($this->app->pdo, 'bascule');
    }

    public function index(array $p): string
    {
        $dernier = null;
        try {
            $dernier = $this->app->pdo->query('SELECT * FROM import_run ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        } catch (\PDOException) {
        }
        return $this->app->render('import/index', [
            'bascule' => $this->bascule(), 'dernier' => $dernier, 'tables' => Importer::FILES,
            'uploadMax' => ini_get('upload_max_filesize'), 'postMax' => ini_get('post_max_size'),
        ], 'Import WinDev', 'parametrage');
    }

    /** Réception des fichiers et simulation. */
    public function envoyer(array $p): string
    {
        if ($this->bascule()) {
            $this->app->flash('erreur', 'Import refusé : la bascule vers l\'application web a eu lieu. Annulez-la d\'abord si vous voulez vraiment réimporter.');
            $this->app->redirect('/import');
        }
        if (empty($_FILES['fichiers']) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->app->flash('erreur', 'Les fichiers dépassent la taille d\'envoi autorisée par le serveur (' . ini_get('post_max_size') . '). Envoyez-les en plusieurs fois ou compressez-les.');
            $this->app->redirect('/import');
        }
        $this->nettoyerAnciens();
        $jeton = $_SESSION['import_jeton'] ?? bin2hex(random_bytes(12));
        $dir = self::dossierBase() . '/' . $jeton;
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            $this->app->flash('erreur', 'Impossible de créer le dossier temporaire storage/import (droits d\'écriture ?).');
            $this->app->redirect('/import');
        }
        $_SESSION['import_jeton'] = $jeton;

        $ignores = [];
        $f = $_FILES['fichiers'] ?? null;
        if ($f && is_array($f['name'])) {
            foreach ($f['name'] as $i => $nom) {
                if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($f['error'][$i] !== UPLOAD_ERR_OK) {
                    $ignores[] = $nom . ' (envoi interrompu ou trop gros, code ' . $f['error'][$i] . ')';
                    continue;
                }
                $table = self::tablePour((string) $nom);
                if ($table === null || strtolower(pathinfo((string) $nom, PATHINFO_EXTENSION)) !== 'xml') {
                    $ignores[] = $nom . ' (nom non reconnu)';
                    continue;
                }
                move_uploaded_file($f['tmp_name'][$i], "$dir/$table.xml");
            }
        }
        return $this->apercu($dir, $ignores);
    }

    private function apercu(string $dir, array $ignores): string
    {
        $recus = [];
        foreach (Importer::FILES as $t) {
            $recus[$t] = is_file("$dir/$t.xml") ? filesize("$dir/$t.xml") : null;
        }
        $complet = !in_array(null, $recus, true);
        $rapport = null;
        $erreur = null;
        if ($complet) {
            @set_time_limit(300);
            try {
                $rapport = (new Importer($this->app->pdo))->run($dir, true)['report'];
            } catch (\Throwable $e) {
                $erreur = $e->getMessage();
            }
        }
        return $this->app->render('import/apercu', [
            'recus' => $recus, 'ignores' => $ignores, 'complet' => $complet, 'rapport' => $rapport, 'erreur' => $erreur,
        ], 'Import WinDev', 'parametrage');
    }

    public function lancer(array $p): string
    {
        $jeton = $_SESSION['import_jeton'] ?? '';
        $dir = self::dossierBase() . '/' . $jeton;
        if ($jeton === '' || !is_dir($dir)) {
            $this->app->flash('erreur', 'Fichiers introuvables : renvoyez-les.');
            $this->app->redirect('/import');
        }
        if ($this->bascule()) {
            $this->app->flash('erreur', 'Import refusé : la bascule vers l\'application web a eu lieu.');
            $this->app->redirect('/import');
        }
        @set_time_limit(600);
        ignore_user_abort(true);
        try {
            $res = (new Importer($this->app->pdo))->run($dir, false);
        } catch (\Throwable $e) {
            return $this->app->render('import/resultat', ['ok' => false, 'rapport' => $e->getMessage()], 'Import WinDev', 'parametrage');
        }
        $res['report'] .= \Ascs\Transition::apresImport($this->app->pdo);
        @file_put_contents(dirname(__DIR__, 3) . '/storage/rapport_import_' . date('Ymd_His') . '.txt', $res['report']);
        $this->supprimerDossier($dir);
        unset($_SESSION['import_jeton']);
        return $this->app->render('import/resultat', ['ok' => true, 'rapport' => $res['report']], 'Import WinDev', 'parametrage');
    }

    public function annuler(array $p): void
    {
        if (!empty($_SESSION['import_jeton'])) {
            $this->supprimerDossier(self::dossierBase() . '/' . $_SESSION['import_jeton']);
            unset($_SESSION['import_jeton']);
        }
        $this->app->redirect('/import');
    }

    public function basculer(array $p): void
    {
        if (($_POST['confirme'] ?? '') !== 'BASCULER' && ($_POST['etat'] ?? '') === 'oui') {
            $this->app->flash('erreur', 'Tapez BASCULER en majuscules pour confirmer.');
            $this->app->redirect('/import');
        }
        if (($_POST['etat'] ?? '') === 'oui') {
            Parametres::set($this->app->pdo, 'bascule', date('Y-m-d H:i:s'));
            $this->app->flash('ok', 'Bascule enregistrée : l\'application web est désormais la référence et l\'import WinDev est désactivé.');
        } else {
            Parametres::set($this->app->pdo, 'bascule', null);
            $this->app->flash('ok', 'Retour au mode transition : l\'import WinDev est de nouveau possible.');
        }
        $this->app->redirect('/import');
    }
}
