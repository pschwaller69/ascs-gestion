<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Carte PASS'Région (jeunes ou senior) jointe à une inscription en ligne : photo du côté portant le numéro
 * et code à 4 chiffres. La photo est rangée dans storage/cartes (jamais servie directement par le web),
 * le code est chiffré en base ; seuls les comptes du bureau y accèdent, depuis la demande.
 */
final class CartesPass
{
    public const AIDES = ['pass_region', 'pass_senior'];
    public const TAILLE_MAX = 20 * 1024 * 1024;
    /** Photo réduite : plus grand côté en pixels, qualité JPEG. */
    public const COTE_MAX = 1600;
    public const QUALITE = 82;
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function dossier(): string
    {
        return dirname(__DIR__, 2) . '/storage/cartes';
    }

    public static function concernee(array $aides): bool
    {
        return (bool) array_intersect(self::AIDES, $aides);
    }

    /**
     * Contrôle la photo et le code envoyés avec le formulaire (tous deux facultatifs : sinon la carte est montrée au bureau).
     * @return array{0:array{fichier:?string,mime:?string,code:?string},1:array<string,string>}
     */
    public static function controler(array $files, array $in, array $aides): array
    {
        $r = ['fichier' => null, 'mime' => null, 'code' => null];
        $err = [];
        if (!self::concernee($aides)) {
            return [$r, $err];
        }
        $code = preg_replace('/\s+/', '', (string) ($in['carte_region_code'] ?? ''));
        if ($code !== '') {
            if (!preg_match('/^\d{4}$/', $code)) {
                $err['carte_region_code'] = 'Le code de la carte comporte 4 chiffres.';
            } else {
                $r['code'] = $code;
            }
        }
        $f = $files['carte_region_photo'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || (int) $f['size'] > self::TAILLE_MAX) {
                $err['carte_region_photo'] = 'Photo trop lourde (20 Mo au plus).';
            } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                $err['carte_region_photo'] = 'La photo n\'a pas pu être envoyée, réessayez.';
            } else {
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
                if (!isset(self::TYPES[$mime])) {
                    $err['carte_region_photo'] = 'Envoyez une photo (JPEG, PNG, HEIC) ou un PDF.';
                } else {
                    $r['fichier'] = (string) $f['tmp_name'];
                    $r['mime'] = $mime;
                }
            }
        }
        return [$r, $err];
    }

    /** Range la photo et le code d'une demande. */
    public function enregistrer(int $demandeId, array $r): void
    {
        if ($r['fichier'] === null && $r['code'] === null) {
            return;
        }
        $nom = null;
        if ($r['fichier'] !== null) {
            $dir = self::dossier();
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
                @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            }
            $reduite = self::reduire($r['fichier'], (string) $r['mime']);
            if ($reduite !== null) {
                $nom = $demandeId . '-' . bin2hex(random_bytes(8)) . '.jpg';
                $r['mime'] = 'image/jpeg';
                if (@file_put_contents($dir . '/' . $nom, $reduite) === false) {
                    $nom = null;
                }
            } else {
                $nom = $demandeId . '-' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$r['mime']];
                if (!move_uploaded_file($r['fichier'], $dir . '/' . $nom) && !@rename($r['fichier'], $dir . '/' . $nom)) {
                    $nom = null;
                }
            }
        }
        $this->supprimer($demandeId);
        $this->pdo->prepare('INSERT INTO demande_carte (demande_id, fichier, mime, code_chiffre, cree_le) VALUES (?,?,?,?,?)')
            ->execute([$demandeId, $nom, $nom ? $r['mime'] : null, $r['code'] !== null ? $this->chiffrer($r['code']) : null, date('Y-m-d H:i:s')]);
    }

    /**
     * Photo réduite en JPEG (plus grand côté COTE_MAX, sens corrigé d'après l'EXIF du téléphone), ou null si
     * le format ne se lit pas ici (HEIC, PDF, GD absent) : le fichier est alors gardé tel quel.
     */
    public static function reduire(string $chemin, string $mime): ?string
    {
        if (!function_exists('imagecreatefromstring') || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($chemin));
        if ($img === false) {
            return null;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $o = (int) ((@exif_read_data($chemin) ?: [])['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($angle !== 0 && ($t = imagerotate($img, $angle, 0)) !== false) {
                imagedestroy($img);
                $img = $t;
            }
        }
        [$l, $h] = [imagesx($img), imagesy($img)];
        $k = min(1, self::COTE_MAX / max($l, $h));
        if ($k < 1) {
            $n = imagescale($img, max(1, (int) round($l * $k)), max(1, (int) round($h * $k)), IMG_BICUBIC);
            if ($n !== false) {
                imagedestroy($img);
                $img = $n;
            }
        }
        // Fond blanc pour les PNG transparents, puis JPEG
        $fond = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($fond, 0, 0, imagecolorallocate($fond, 255, 255, 255));
        imagecopy($fond, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagedestroy($img);
        ob_start();
        imagejpeg($fond, null, self::QUALITE);
        imagedestroy($fond);
        $out = (string) ob_get_clean();
        return $out !== '' ? $out : null;
    }

    /** @return array{fichier:?string,mime:?string,code:?string}|null */
    public function lire(int $demandeId): ?array
    {
        $st = $this->pdo->prepare('SELECT fichier, mime, code_chiffre FROM demande_carte WHERE demande_id = ?');
        $st->execute([$demandeId]);
        $l = $st->fetch();
        if (!$l) {
            return null;
        }
        $chemin = $l['fichier'] ? self::dossier() . '/' . basename((string) $l['fichier']) : null;
        return ['fichier' => $chemin && is_file($chemin) ? $chemin : null, 'mime' => $l['mime'] ?: null,
            'code' => $l['code_chiffre'] ? $this->dechiffrer((string) $l['code_chiffre']) : null];
    }

    public function supprimer(int $demandeId): void
    {
        $l = $this->lire($demandeId);
        if ($l && $l['fichier']) {
            @unlink($l['fichier']);
        }
        $this->pdo->prepare('DELETE FROM demande_carte WHERE demande_id = ?')->execute([$demandeId]);
    }

    private function cle(): string
    {
        $s = Parametres::get($this->pdo, 'secret_liens');
        if (!$s) {
            $s = bin2hex(random_bytes(32));
            Parametres::set($this->pdo, 'secret_liens', $s);
        }
        return hash('sha256', 'carte-pass|' . $s, true);
    }

    private function chiffrer(string $v): string
    {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($n . sodium_crypto_secretbox($v, $n, $this->cle()));
    }

    private function dechiffrer(string $v): ?string
    {
        $b = base64_decode($v, true);
        if ($b === false || strlen($b) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $r = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->cle());
        return $r === false ? null : $r;
    }
}
