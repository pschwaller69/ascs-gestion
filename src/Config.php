<?php
declare(strict_types=1);

namespace Ascs;

/** Configuration lue dans l'environnement ou dans le fichier .env à la racine du projet. */
final class Config
{
    /** @return array{driver:string,host:string,name:string,user:string,pass:string,sqlite_path:string,setup_code:string,app_url:string,ha_client_id:string,ha_client_secret:string,ha_organisation:string,ha_webhook_token:string,ha_api_url:string,mail_transport:string} */
    public static function load(?string $root = null): array
    {
        $root ??= dirname(__DIR__);
        $env = [];
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
        $get = static fn (string $k, string $default = ''): string =>
            (getenv($k) !== false ? (string) getenv($k) : ($env[$k] ?? $default));

        return [
            'driver'      => $get('DB_DRIVER', 'mysql'),
            'host'        => $get('DB_HOST', 'localhost'),
            'name'        => $get('DB_NAME'),
            'user'        => $get('DB_USER'),
            'pass'        => $get('DB_PASS'),
            'sqlite_path' => $get('DB_SQLITE_PATH', $root . '/storage/ascs.sqlite'),
            // Code d'accès à la page /installation (12 caractères minimum, sinon la page reste fermée)
            'setup_code'  => $get('SETUP_CODE'),
            // Adresse publique de l'application (QR codes), ex. https://gestion.ascs.fr — facultatif
            'app_url'     => $get('APP_URL'),
            // HelloAsso (API v5) : identifiants créés dans « Mon compte > Intégrations et API »
            'ha_client_id'     => $get('HELLOASSO_CLIENT_ID'),
            'ha_client_secret' => $get('HELLOASSO_CLIENT_SECRET'),
            'ha_organisation'  => $get('HELLOASSO_ORGANISATION'),
            'ha_webhook_token' => $get('HELLOASSO_WEBHOOK_TOKEN'),
            // Adresses de l'API (à ne changer que pour la sandbox HelloAsso ou les tests)
            'ha_api_url'       => $get('HELLOASSO_API_URL', 'https://api.helloasso.com'),
            // Envoi des mails : « mail » (serveur d'o2switch) ou « fichier » (tests : écrits dans storage/mails)
            'mail_transport'   => $get('MAIL_TRANSPORT', 'mail'),
        ];
    }
}
