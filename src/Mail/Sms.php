<?php
declare(strict_types=1);

namespace Ascs\Mail;

use Ascs\Config;

/**
 * Envoi de SMS par la passerelle « SMS Gateway for Android » (application sur un téléphone Android de l'association,
 * mode cloud : https://api.sms-gate.app/3rdparty/v1/messages, authentification Basic).
 * SMS_TRANSPORT=fichier (tests) : les SMS sont écrits dans storage/sms au lieu d'être envoyés.
 */
final class Sms
{
    public function __construct(private readonly string $url, private readonly string $user, private readonly string $pass, private readonly string $transport)
    {
    }

    public static function depuisConfig(): ?self
    {
        $c = Config::load();
        if (strtolower($c['sms_transport']) === 'fichier') {
            return new self('', '', '', 'fichier');
        }
        if ($c['sms_user'] === '' || $c['sms_pass'] === '') {
            return null;
        }
        return new self($c['sms_url'], $c['sms_user'], $c['sms_pass'], 'passerelle');
    }

    /** Numéro de portable français au format international (+33…), sinon null (fixe, étranger illisible, vide). */
    public static function mobile(string $tel): ?string
    {
        $d = preg_replace('/\D/', '', $tel);
        if (str_starts_with($d, '0033')) {
            $d = '0' . substr($d, 4);
        } elseif (str_starts_with($d, '33') && strlen($d) === 11) {
            $d = '0' . substr($d, 2);
        }
        return preg_match('/^0[67]\d{8}$/', $d) ? '+33' . substr($d, 1) : null;
    }

    /** Nombre de SMS facturés pour un texte (160 caractères, 153 par partie au-delà ; 70/67 avec accents hors GSM). */
    public static function parties(string $texte): int
    {
        $gsm = !preg_match('/[^\x0A\x0D\x20-\x7E£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà€]/u', $texte);
        $n = mb_strlen($texte);
        [$un, $plusieurs] = $gsm ? [160, 153] : [70, 67];
        return $n <= $un ? 1 : (int) ceil($n / $plusieurs);
    }

    /** @return ?string message d'erreur, null si accepté par la passerelle */
    public function envoyer(string $numero, string $texte): ?string
    {
        if ($this->transport === 'fichier') {
            $dir = dirname(__DIR__, 2) . '/storage/sms';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt', $numero . "\n" . $texte);
            return null;
        }
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['textMessage' => ['text' => $texte], 'phoneNumbers' => [$numero]], JSON_UNESCAPED_UNICODE),
        ]);
        $rep = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($rep === false) {
            return 'Passerelle SMS injoignable (' . $err . ').';
        }
        return $code >= 200 && $code < 300 ? null : 'Passerelle SMS : erreur ' . $code . ' ' . mb_substr(strip_tags((string) $rep), 0, 120);
    }
}
