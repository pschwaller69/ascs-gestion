<?php
declare(strict_types=1);

namespace Ascs\Web;

/** Routeur minimal : méthode + motif ("/adherents/{id}") -> [classe, méthode]. */
final class Router
{
    /** @var list<array{string,string,array{class-string,string},bool,bool}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, bool $public = false): void
    {
        $this->routes[] = ['GET', $pattern, $handler, $public, true];
    }

    /** $csrf = false uniquement pour un appel de serveur à serveur protégé autrement (notification HelloAsso). */
    public function post(string $pattern, array $handler, bool $public = false, bool $csrf = true): void
    {
        $this->routes[] = ['POST', $pattern, $handler, $public, $csrf];
    }

    public function dispatch(App $app, string $method, string $path): void
    {
        $matchedPath = false;
        foreach ($this->routes as [$m, $pattern, $handler, $public, $csrf]) {
            $regex = '#^' . preg_replace_callback('/\{(\w+)\}/', static fn ($x) => $x[1] === 'id' ? '(?<id>\d+)' : '(?<' . $x[1] . '>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $matchedPath = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);

            if (!$public && $app->user === null) {
                if ($method === 'GET' && !str_starts_with($path, '/assets')) {
                    $app->redirect('/connexion', ['retour' => $path]);
                }
                $app->redirect('/connexion');
            }
            if ($method === 'POST' && $csrf && !App::csrfValid()) {
                http_response_code(419);
                echo $app->render('erreur', ['titre' => 'Session expirée', 'message' => 'Le formulaire n\'a pas pu être vérifié (jeton de sécurité absent ou périmé). Revenez à la page précédente, rechargez-la et recommencez.'], 'Session expirée', 'accueil', 419);
                return;
            }
            [$class, $fn] = $handler;
            $out = (new $class($app))->$fn($params);
            if (is_string($out)) {
                echo $out;
            }
            return;
        }
        if ($matchedPath) {
            http_response_code(405);
            echo $app->render('erreur', ['titre' => 'Action impossible', 'message' => 'Cette page ne peut pas être appelée de cette façon.'], 'Action impossible', 'accueil', 405);
            return;
        }
        echo $app->render('erreur', ['titre' => 'Page introuvable', 'message' => 'Cette adresse ne correspond à aucune page. Utilisez le menu pour revenir.'], 'Page introuvable', 'accueil', 404);
    }
}
