<?php
declare(strict_types=1);

namespace Ascs;

/** Configuration lue dans l'environnement ou dans le fichier .env à la racine du projet. */
final class Config
{
    /** @return array{driver:string,host:string,name:string,user:string,pass:string,sqlite_path:string,setup_code:string,app_url:string,ha_client_id:string,ha_client_secret:string,ha_organisation:string,ha_webhook_token:string,ha_api_url:string,mail_transport:string,smtp_host:string,smtp_port:string,smtp_user:string,smtp_pass:string,smtp_securite:string,smtp_mailing_user:string,smtp_mailing_pass:string,sms_url:string,sms_user:string,sms_pass:string,sms_transport:string,m365_tenant:string,m365_client_id:string,m365_secret:string,m365_boite:string,m365_login_url:string,m365_graph_url:string} */
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
            // SMTP authentifié (compte mail o2switch) : utilisé dès que SMTP_HOST est renseigné
            'smtp_host'        => $get('SMTP_HOST'),
            'smtp_port'        => $get('SMTP_PORT', '465'),
            'smtp_user'        => $get('SMTP_USER'),
            'smtp_pass'        => $get('SMTP_PASS'),
            'smtp_securite'    => $get('SMTP_SECURITE', 'ssl'),
            // Second compte, réservé aux mailings en nombre (facultatif : sinon le compte ci-dessus sert à tout)
            'smtp_mailing_user' => $get('SMTP_MAILING_USER'),
            'smtp_mailing_pass' => $get('SMTP_MAILING_PASS'),
            // SMS : passerelle « SMS Gateway for Android » (identifiants affichés dans l'application du téléphone)
            'sms_url'          => $get('SMS_URL', 'https://api.sms-gate.app/3rdparty/v1/messages'),
            'sms_user'         => $get('SMS_USER'),
            'sms_pass'         => $get('SMS_PASS'),
            'sms_transport'    => $get('SMS_TRANSPORT', 'passerelle'),
            // Contacts dans Microsoft 365 (Outlook) : application déclarée dans Microsoft Entra, droit Contacts.ReadWrite (application)
            'm365_tenant'      => $get('M365_TENANT'),
            'm365_client_id'   => $get('M365_CLIENT_ID'),
            'm365_secret'      => $get('M365_CLIENT_SECRET'),
            'm365_boite'       => $get('M365_BOITE'),
            // Adresses de Microsoft (à ne changer que pour les tests)
            'm365_login_url'   => $get('M365_LOGIN_URL', 'https://login.microsoftonline.com'),
            'm365_graph_url'   => $get('M365_GRAPH_URL', 'https://graph.microsoft.com/v1.0'),
        ];
    }
}
