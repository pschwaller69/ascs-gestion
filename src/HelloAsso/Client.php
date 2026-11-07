<?php
declare(strict_types=1);

namespace Ascs\HelloAsso;

use Ascs\Parametres;
use PDO;
use RuntimeException;

/**
 * Client minimal de l'API HelloAsso v5 (lecture seule).
 * Jeton OAuth2 « client_credentials » (30 min), gardé dans la table parametre pour éviter
 * d'en redemander un à chaque appel (l'API limite les demandes de jetons).
 */
final class Client
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $organisation,
        private readonly string $baseUrl = 'https://api.helloasso.com',
    ) {
    }

    public static function depuisConfig(PDO $pdo, array $cfg): self
    {
        foreach (['ha_client_id' => 'HELLOASSO_CLIENT_ID', 'ha_client_secret' => 'HELLOASSO_CLIENT_SECRET', 'ha_organisation' => 'HELLOASSO_ORGANISATION'] as $k => $var) {
            if (($cfg[$k] ?? '') === '') {
                throw new RuntimeException("HelloAsso n'est pas configuré : $var est vide dans le fichier .env.");
            }
        }
        return new self($pdo, $cfg['ha_client_id'], $cfg['ha_client_secret'], $cfg['ha_organisation'], rtrim($cfg['ha_api_url'] ?: 'https://api.helloasso.com', '/'));
    }

    public function organisation(): string
    {
        return $this->organisation;
    }

    /** @return array{0:int,1:string} [code HTTP, corps] */
    private function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $res = curl_exec($ch);
            if ($res === false) {
                $err = curl_error($ch);
                throw new RuntimeException("HelloAsso injoignable : $err");
            }
            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $res];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 60, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new RuntimeException('HelloAsso injoignable.');
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $res];
    }

    private function jeton(bool $forcer = false): string
    {
        if (!$forcer) {
            $j = json_decode((string) Parametres::get($this->pdo, 'helloasso_jeton'), true);
            if (is_array($j) && ($j['expire'] ?? 0) > time() + 60 && ($j['client'] ?? '') === hash('sha256', $this->clientId . $this->clientSecret)) {
                return $j['acces'];
            }
        }
        [$code, $body] = $this->http('POST', $this->baseUrl . '/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
        ]));
        $d = json_decode($body, true);
        if ($code !== 200 || !is_array($d) || empty($d['access_token'])) {
            throw new RuntimeException($code === 400 || $code === 401
                ? 'HelloAsso refuse les identifiants (HELLOASSO_CLIENT_ID / HELLOASSO_CLIENT_SECRET) : vérifiez-les dans « Mon compte > Intégrations et API ».'
                : "HelloAsso : obtention du jeton impossible (HTTP $code).");
        }
        Parametres::set($this->pdo, 'helloasso_jeton', json_encode([
            'acces' => $d['access_token'], 'expire' => time() + (int) ($d['expires_in'] ?? 1800), 'client' => hash('sha256', $this->clientId . $this->clientSecret),
        ]));
        return $d['access_token'];
    }

    /** GET JSON sur /v5/…, avec un nouvel essai si le jeton a expiré entre-temps. */
    public function get(string $chemin, array $query = []): array
    {
        $url = $this->baseUrl . '/v5' . $chemin . ($query ? '?' . self::query($query) : '');
        foreach ([false, true] as $forcer) {
            [$code, $body] = $this->http('GET', $url, ['Authorization: Bearer ' . $this->jeton($forcer), 'Accept: application/json']);
            if ($code === 401 && !$forcer) {
                continue;
            }
            if ($code === 403) {
                throw new RuntimeException('HelloAsso refuse l\'accès aux données de « ' . $this->organisation . ' » (HTTP 403). Vérifiez HELLOASSO_ORGANISATION (le nom de l\'association dans l\'adresse helloasso.com/associations/…) et que le client API a bien le droit « AccessTransactions ».');
            }
            if ($code === 404) {
                throw new RuntimeException('HelloAsso ne connaît pas l\'association « ' . $this->organisation . ' » (HTTP 404). Vérifiez HELLOASSO_ORGANISATION.');
            }
            if ($code === 429) {
                throw new RuntimeException('HelloAsso limite le nombre d\'appels (HTTP 429) : réessayez dans quelques minutes.');
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("HelloAsso : réponse inattendue (HTTP $code) sur $chemin.");
            }
            $d = json_decode($body, true);
            if (!is_array($d)) {
                throw new RuntimeException("HelloAsso : réponse illisible sur $chemin.");
            }
            return $d;
        }
        throw new RuntimeException('HelloAsso : jeton refusé.');
    }

    /** Paramètres répétés à la façon .NET : tierTypes=Membership&tierTypes=… */
    private static function query(array $q): string
    {
        $parts = [];
        foreach ($q as $k => $v) {
            foreach ((array) $v as $x) {
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($x) ? ($x ? 'true' : 'false') : (string) $x);
            }
        }
        return implode('&', $parts);
    }

    /**
     * Toutes les adhésions vendues depuis une date (pagination par jeton de continuation).
     *
     * @return list<array<string,mixed>>
     */
    public function adhesions(string $depuisIso): array
    {
        $tout = [];
        $jeton = null;
        for ($page = 0; $page < 200; $page++) {
            $q = ['from' => $depuisIso, 'tierTypes' => 'Membership', 'withDetails' => true, 'pageSize' => 100, 'sortOrder' => 'Asc'];
            if ($jeton) {
                $q['continuationToken'] = $jeton;
            }
            $d = $this->get('/organizations/' . rawurlencode($this->organisation) . '/items', $q);
            $lot = $d['data'] ?? [];
            foreach ($lot as $item) {
                $tout[] = $item;
            }
            $suivant = $d['pagination']['continuationToken'] ?? null;
            // On lit jusqu'à une page vide : ne pas supposer que l'API respecte pageSize.
            if (!$lot || !$suivant || $suivant === $jeton) {
                break;
            }
            $jeton = $suivant;
        }
        return $tout;
    }
}
