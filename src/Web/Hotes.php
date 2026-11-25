<?php
declare(strict_types=1);

namespace Ascs\Web;

use Ascs\Config;

/**
 * Deux sous-domaines pour la même application (PUBLIC_URL dans .env, par exemple https://inscription.ascs.fr) :
 *  - le sous-domaine public ne sert que les pages des adhérents et des animateurs (inscription, essai, paiement,
 *    désinscription, listes de présence) : la connexion et toutes les pages du bureau y répondent « introuvable » ;
 *  - le sous-domaine du bureau (gestion.ascs.fr) renvoie ces pages publiques vers le sous-domaine public,
 *    pour que les anciens QR codes et liens continuent de marcher.
 * ESSAI_URL (facultatif, ex. https://essai.ascs.fr) : même rôle, ouvre directement le formulaire de cours d'essai.
 * PUBLIC_URL et ESSAI_URL vides : un seul sous-domaine, comme avant.
 */
final class Hotes
{
    /** Débuts de chemin des pages publiques (adhérents, animateurs, contrôleurs). */
    public const CHEMINS_PUBLICS = ['/inscription', '/essai', '/merci', '/paiement/', '/desinscription/', '/presence/', '/animateur/', '/controle/'];
    /** Toujours servis sur les deux sous-domaines (fichiers de l'application, appel de serveur à serveur). */
    private const CHEMINS_COMMUNS = ['/assets/', '/manifest.webmanifest', '/sw.js', '/hors-ligne.html', '/helloasso/notification/'];

    public static function urlPublique(): string
    {
        return self::url('public_url');
    }

    /** Sous-domaine du cours d'essai (ESSAI_URL, ex. https://essai.ascs.fr), facultatif. */
    public static function urlEssai(): string
    {
        return self::url('essai_url');
    }

    private static function url(string $cle): string
    {
        $u = rtrim(Config::load()[$cle] ?? '', '/');
        return preg_match('#^https?://[^/]+#i', $u) ? $u : '';
    }

    private static function hote(string $url): ?string
    {
        return $url === '' ? null : strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /** Un sous-domaine public au moins est réglé. */
    public static function hotePublic(): ?string
    {
        return self::hote(self::urlPublique()) ?? self::hote(self::urlEssai());
    }

    /** Adresse de base d'une page publique : celle du cours d'essai pour /essai, sinon celle des adhérents. */
    public static function basePour(string $chemin): string
    {
        $essai = self::urlEssai();
        if ($essai !== '' && ($chemin === '/essai' || str_starts_with($chemin, '/essai/'))) {
            return $essai;
        }
        return self::urlPublique() !== '' ? self::urlPublique() : $essai;
    }

    private static function hoteRequete(): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    }

    private static function surHoteEssai(): bool
    {
        $h = self::hote(self::urlEssai());
        return $h !== null && self::hoteRequete() === $h;
    }

    public static function surHotePublic(): bool
    {
        $h = self::hote(self::urlPublique());
        return ($h !== null && self::hoteRequete() === $h) || self::surHoteEssai();
    }

    public static function cheminPublic(string $chemin): bool
    {
        foreach (self::CHEMINS_PUBLICS as $p) {
            if ($chemin === rtrim($p, '/') || str_starts_with($chemin, rtrim($p, '/') . '/') || $chemin === $p) {
                return true;
            }
        }
        return false;
    }

    private static function cheminCommun(string $chemin): bool
    {
        foreach (self::CHEMINS_COMMUNS as $p) {
            if ($chemin === $p || str_starts_with($chemin, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sous-domaine public : à appeler avant tout le reste (installation comprise).
     * Rend la main si la requête peut être servie ; sinon répond (redirection ou introuvable) et s'arrête.
     */
    public static function filtrerPublic(string $chemin): void
    {
        if (!self::surHotePublic() || self::cheminCommun($chemin) || self::cheminPublic($chemin)) {
            return;
        }
        if ($chemin === '/' || $chemin === '') {
            header('Location: ' . (self::surHoteEssai() ? '/essai' : '/inscription'), true, 302);
            exit;
        }
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Page introuvable</title>'
            . '<link rel="stylesheet" href="/assets/app.css"><main class="public"><h1>Page introuvable</h1>'
            . '<p>Cette adresse ne correspond à aucune page.</p><p><a href="/inscription">Inscription</a> · <a href="/essai">Cours d\'essai</a></p></main></html>';
        exit;
    }

    /**
     * Sous-domaine du bureau : une page publique consultée (GET) par quelqu'un qui n'est pas connecté part vers le
     * sous-domaine public (anciens QR codes, liens des animateurs). Restent servis ici : les envois (POST : formulaire
     * déjà ouvert, désinscription en un clic des anciens mails), la page de fin d'un tel envoi, et l'aperçu des
     * formulaires par le bureau connecté.
     */
    public static function renvoyerVersPublic(string $chemin, bool $connecte): void
    {
        if (self::hotePublic() === null || self::surHotePublic() || $connecte || $chemin === '/merci' || !self::cheminPublic($chemin)
            || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return;
        }
        $q = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        header('Location: ' . self::basePour($chemin) . $chemin . ($q !== '' ? '?' . $q : ''), true, 302);
        exit;
    }
}
