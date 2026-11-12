<?php
declare(strict_types=1);

namespace Ascs\Contacts;

use Ascs\Config;
use RuntimeException;

/**
 * Accès minimal à Microsoft Graph avec le jeton de l'application (identifiants client, sans utilisateur connecté).
 */
final class Graph
{
    private ?string $jeton = null;
    private int $expire = 0;

    public function __construct(private readonly string $tenant, private readonly string $clientId, private readonly string $secret,
        private readonly string $loginUrl, private readonly string $graphUrl)
    {
    }

    /** Client configuré par le .env, ou null si les lignes M365_ ne sont pas remplies. */
    public static function depuisConfig(?array $c = null): ?self
    {
        $c ??= Config::load();
        if ($c['m365_tenant'] === '' || $c['m365_client_id'] === '' || $c['m365_secret'] === '' || $c['m365_boite'] === '') {
            return null;
        }
        return new self($c['m365_tenant'], $c['m365_client_id'], $c['m365_secret'], rtrim($c['m365_login_url'], '/'), rtrim($c['m365_graph_url'], '/'));
    }

    private function jeton(): string
    {
        if ($this->jeton !== null && time() < $this->expire - 60) {
            return $this->jeton;
        }
        [$code, $corps] = $this->http('POST', $this->loginUrl . '/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $this->clientId, 'client_secret' => $this->secret, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
        $d = json_decode($corps, true);
        if ($code !== 200 || !isset($d['access_token'])) {
            throw new RuntimeException('Connexion à Microsoft 365 refusée : ' . ($d['error_description'] ?? $d['error'] ?? 'code ' . $code)
                . ' (vérifiez M365_TENANT, M365_CLIENT_ID et M365_CLIENT_SECRET, et la date d\'expiration du secret).');
        }
        $this->jeton = (string) $d['access_token'];
        $this->expire = time() + (int) ($d['expires_in'] ?? 3600);
        return $this->jeton;
    }

    /**
     * Appel de l'API. Renvoie [code HTTP, réponse décodée]. Réessaie après un « trop de requêtes » (429) ou une indisponibilité (503).
     * @return array{0:int,1:array<string,mixed>}
     */
    public function appel(string $methode, string $chemin, ?array $corps = null): array
    {
        for ($essai = 0; ; $essai++) {
            [$code, $rep, $entetes] = $this->http($methode, $this->graphUrl . $chemin,
                ['Authorization: Bearer ' . $this->jeton(), 'Content-Type: application/json', 'Accept: application/json'],
                $corps === null ? null : (string) json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
            if (in_array($code, [429, 503], true) && $essai < 3) {
                $attente = preg_match('/retry-after:\s*(\d+)/i', $entetes, $m) ? min(30, (int) $m[1]) : 2 + 2 * $essai;
                sleep($attente);
                continue;
            }
            return [$code, $rep === '' ? [] : (json_decode($rep, true) ?: [])];
        }
    }

    /** @return array{0:int,1:string,2:string} */
    private function http(string $methode, string $url, array $entetes, ?string $corps, bool $avecEntetes = false): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension curl de PHP absente.');
        }
        $ch = curl_init($url);
        $recus = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$recus) { $recus .= $h; return strlen($h); },
        ]);
        if ($corps !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
        }
        $res = curl_exec($ch);
        if ($res === false) {
            throw new RuntimeException('Microsoft 365 injoignable : ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $res, $recus];
    }
}
