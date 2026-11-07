<?php
declare(strict_types=1);

namespace Ascs\Web;

use Ascs\Db;
use Ascs\Parametres;
use PDO;

/** Contexte d'une requête : base de données, session, utilisateur, messages, rendu des gabarits. */
final class App
{
    public static string $base = '';
    private const IDLE_SECONDS = 8 * 3600;

    public ?array $user = null;

    public function __construct(public readonly PDO $pdo)
    {
    }

    /** Session et chemin de base, sans base de données (utilisé aussi par la page d'installation). */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        date_default_timezone_set('Europe/Paris');
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        self::$base = rtrim($script === '/' ? '' : $script, '/');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
        session_name('ascs');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public static function boot(): self
    {
        self::startSession();
        $app = new self(Db::connect());
        if (isset($_SESSION['uid'])) {
            if (time() - (int) ($_SESSION['last'] ?? 0) > self::IDLE_SECONDS) {
                $_SESSION = [];
                session_regenerate_id(true);
            } else {
                $st = $app->pdo->prepare('SELECT id, email, nom FROM utilisateur WHERE id = ? AND actif = 1');
                $st->execute([(int) $_SESSION['uid']]);
                $app->user = $st->fetch() ?: null;
                if ($app->user === null) {
                    $_SESSION = [];
                } else {
                    $_SESSION['last'] = time();
                }
            }
        }
        return $app;
    }

    // ------------------------------------------------------------------ sécurité

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfValid(): bool
    {
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && $sent !== '' && hash_equals(self::csrfToken(), $sent);
    }

    public static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=15552000');
        }
    }

    // ------------------------------------------------------------------ messages et redirections

    public function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlashes(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public function redirect(string $path, array $query = []): never
    {
        header('Location: ' . url($path, $query), true, 303);
        exit;
    }

    public function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------ saison de travail

    /** Saison affichée par défaut : celle en cours aujourd'hui, sinon la plus récente. */
    public function saisonId(): int
    {
        $choisie = (int) ($_SESSION['saison'] ?? 0);
        if ($choisie > 0) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM saison WHERE id = ?');
            $st->execute([$choisie]);
            if ((int) $st->fetchColumn() > 0) {
                return $choisie;
            }
        }
        $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut <= ? AND date_fin >= ? ORDER BY date_debut DESC LIMIT 1');
        $today = date('Y-m-d');
        $st->execute([$today, $today]);
        $id = $st->fetchColumn();
        if ($id === false) {
            $id = $this->pdo->query('SELECT id FROM saison ORDER BY date_debut DESC, id DESC LIMIT 1')->fetchColumn();
        }
        return (int) $id;
    }

    public function transition(): bool
    {
        return Parametres::get($this->pdo, 'bascule') === null;
    }

    // ------------------------------------------------------------------ rendu

    /** @param array<string,mixed> $vars */
    public function render(string $view, array $vars = [], string $title = '', string $onglet = 'accueil', int $status = 200): string
    {
        http_response_code($status);
        $content = $this->capture(dirname(__DIR__, 2) . '/templates/' . $view . '.php', $vars + ['app' => $this]);
        return $this->capture(dirname(__DIR__, 2) . '/templates/layout.php', [
            'app' => $this, 'content' => $content, 'title' => $title, 'onglet' => $onglet,
            'flashes' => $this->takeFlashes(),
            'saisons' => $this->user ? $this->pdo->query('SELECT id, titre FROM saison ORDER BY date_debut DESC, id DESC')->fetchAll() : [],
            'saisonId' => $this->user ? $this->saisonId() : 0,
            'transition' => $this->user ? $this->transition() : false,
            'version' => Ribbon::version(),
        ]);
    }

    /** Pages publiques (inscription, essai) : mise en page simple, sans ruban ni menu du bureau. */
    public function renderPublic(string $view, array $vars = [], string $title = '', int $status = 200): string
    {
        http_response_code($status);
        $content = $this->capture(dirname(__DIR__, 2) . '/templates/' . $view . '.php', $vars + ['app' => $this]);
        return $this->capture(dirname(__DIR__, 2) . '/templates/layout_public.php', [
            'content' => $content, 'title' => $title, 'version' => Ribbon::version(),
        ]);
    }

    /** Adresse complète d'une page (pour les QR codes) : APP_URL du .env, sinon l'adresse de la requête. */
    public static function urlAbsolue(string $path, array $query = []): string
    {
        $base = rtrim(\Ascs\Config::load()['app_url'] ?? '', '/');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
            $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . self::$base;
        }
        return $base . $path . ($query ? '?' . http_build_query($query) : '');
    }

    /** @param array<string,mixed> $vars */
    private function capture(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
