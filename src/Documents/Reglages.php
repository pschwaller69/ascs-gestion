<?php
declare(strict_types=1);

namespace Ascs\Documents;

use Ascs\Parametres;
use PDO;

/**
 * Coordonnées de l'association imprimées sur les documents (facture) et images (logo, signature).
 * Les textes sont dans la table parametre (clé « doc_… »), les images dans storage/documents (jamais servies directement).
 */
final class Reglages
{
    /** Champs modifiables, avec leur libellé et une valeur proposée. */
    public const CHAMPS = [
        'association' => ['Nom de l\'association', 'ASCS'],
        'signataire' => ['Signataire de l\'attestation', 'Anthony LABDI'],
        'qualite' => ['Qualité du signataire', 'Président'],
        'adresse' => ['Adresse', ''],
        'code_postal_ville' => ['Code postal et ville', '69140 Rillieux-la-Pape'],
        'telephone' => ['Téléphone', ''],
        'mail' => ['Mail', ''],
        'site' => ['Site', 'www.ascs.fr'],
        'agrement' => ['Agrément Jeunesse et Sports', ''],
        'siret' => ['SIRET', ''],
        'forme' => ['Forme juridique', 'Association loi 1901'],
        'naf' => ['Code NAF', ''],
        'expediteur' => ['Adresse d\'envoi des mails', ''],
        'reponse' => ['Adresse de réponse', ''],
    ];

    public const IMAGES = ['logo' => 'Logo', 'signature' => 'Signature et tampon'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public function tous(): array
    {
        $v = [];
        foreach (self::CHAMPS as $k => [, $defaut]) {
            $v[$k] = Parametres::get($this->pdo, 'doc_' . $k) ?? $defaut;
        }
        // Compte SMTP configuré dans .env : c'est lui l'expéditeur
        $smtp = self::compteSmtp();
        if ($smtp !== null) {
            $v['expediteur'] = $smtp;
        }
        return $v;
    }

    /** Adresse du compte SMTP (.env) quand l'envoi passe par SMTP, sinon null. */
    public static function compteSmtp(string $flux = 'transactionnel'): ?string
    {
        return \Ascs\Mail\Envoi::compte($flux)[0] ?? null;
    }

    /** @param array<string,mixed> $in */
    public function enregistrer(array $in): void
    {
        foreach (array_keys(self::CHAMPS) as $k) {
            $val = trim((string) ($in[$k] ?? ''));
            Parametres::set($this->pdo, 'doc_' . $k, mb_substr($val, 0, 255));
        }
    }

    public static function dossier(): string
    {
        return dirname(__DIR__, 2) . '/storage/documents';
    }

    public static function image(string $nom): ?string
    {
        $f = self::dossier() . '/' . $nom . '.png';
        return isset(self::IMAGES[$nom]) && is_file($f) ? $f : null;
    }

    /**
     * Enregistre une image envoyée (PNG, JPEG, GIF ou WebP), convertie en PNG non entrelacé, 1200 px au plus.
     * @return ?string message d'erreur
     */
    public static function deposer(string $nom, array $fichier): ?string
    {
        if (!isset(self::IMAGES[$nom])) {
            return 'Image inconnue.';
        }
        if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $fichier['tmp_name'])) {
            return 'Fichier non reçu.';
        }
        if ((int) $fichier['size'] > 5 * 1024 * 1024) {
            return 'Image trop lourde (5 Mo au plus).';
        }
        $data = (string) file_get_contents((string) $fichier['tmp_name']);
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return 'Choisissez une image PNG ou JPEG.';
        }
        if (!is_dir(self::dossier())) {
            mkdir(self::dossier(), 0700, true);
        }
        return self::ecrirePng($data, self::dossier() . '/' . $nom . '.png');
    }

    public static function ecrirePng(string $data, string $cible): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            $info = @getimagesizefromstring($data);
            if ($info && $info[2] === IMAGETYPE_PNG) {
                file_put_contents($cible, $data);
                return null;
            }
            return 'Le serveur ne sait pas convertir cette image : envoyez-la en PNG.';
        }
        $im = @imagecreatefromstring($data);
        if (!$im) {
            return 'Image illisible.';
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > 1200) {
            $nh = (int) round($h * 1200 / $w);
            $petit = imagecreatetruecolor(1200, $nh);
            imagealphablending($petit, false);
            imagesavealpha($petit, true);
            imagecopyresampled($petit, $im, 0, 0, 0, 0, 1200, $nh, $w, $h);
            imagedestroy($im);
            $im = $petit;
        }
        imagesavealpha($im, true);
        imageinterlace($im, false);
        imagepng($im, $cible);
        imagedestroy($im);
        return null;
    }

    public static function supprimer(string $nom): void
    {
        $f = self::image($nom);
        if ($f) {
            unlink($f);
        }
    }
}
