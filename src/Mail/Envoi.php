<?php
declare(strict_types=1);

namespace Ascs\Mail;

use Ascs\Config;
use PDO;

/**
 * Envoi d'un mail avec pièces jointes par la fonction mail() de PHP (serveur mail d'o2switch).
 * MAIL_TRANSPORT=fichier (tests) : le mail est écrit dans storage/mails au lieu d'être envoyé.
 * Chaque envoi est tracé dans la table envoi_mail.
 */
final class Envoi
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{nom:string,contenu:string,type:string}> $pieces
     * @return ?string message d'erreur, null si envoyé
     */
    public function envoyer(string $expediteur, string $nomExpediteur, string $a, string $objet, string $texte, array $pieces, ?int $inscriptionId, string $par): ?string
    {
        $erreur = null;
        if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse du destinataire invalide.';
        } elseif (!filter_var($expediteur, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse d\'envoi non renseignée : Paramétrage > Facture.';
        }
        if ($erreur === null) {
            $limite = 'ascs-' . bin2hex(random_bytes(12));
            $entetes = [
                'From: ' . self::entete($nomExpediteur) . ' <' . $expediteur . '>',
                'Reply-To: ' . $expediteur,
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $limite . '"',
            ];
            $corps = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $texte))));
            foreach ($pieces as $p) {
                $nom = preg_replace('/[^A-Za-z0-9._-]+/', '-', $p['nom']);
                $corps .= "--$limite\r\nContent-Type: {$p['type']}; name=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"$nom\"\r\n\r\n" . chunk_split(base64_encode($p['contenu']));
            }
            $corps .= "--$limite--\r\n";
            $erreur = $this->transmettre($a, self::entete($objet), $corps, $entetes, $expediteur);
        }
        $this->pdo->prepare('INSERT INTO envoi_mail (date_envoi, destinataire, objet, inscription_id, ok, erreur, par) VALUES (?,?,?,?,?,?,?)')
            ->execute([date('Y-m-d H:i:s'), mb_substr($a, 0, 150), mb_substr($objet, 0, 255), $inscriptionId, $erreur === null ? 1 : 0, $erreur, mb_substr($par, 0, 150)]);
        return $erreur;
    }

    /** @param list<string> $entetes */
    private function transmettre(string $a, string $objet, string $corps, array $entetes, string $expediteur): ?string
    {
        if (strtolower(Config::load()['mail_transport']) === 'fichier') {
            $dir = dirname(__DIR__, 2) . '/storage/mails';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml',
                "To: $a\r\nSubject: $objet\r\n" . implode("\r\n", $entetes) . "\r\n\r\n" . $corps);
            return null;
        }
        $ok = @mail($a, $objet, $corps, implode("\r\n", $entetes), '-f' . $expediteur);
        return $ok ? null : 'Le serveur a refusé l\'envoi du mail.';
    }

    /** En-tête encodé (accents) selon la RFC 2047. */
    private static function entete(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    /** @return list<array<string,mixed>> */
    public function historique(int $inscriptionId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM envoi_mail WHERE inscription_id = ? ORDER BY id DESC');
        $st->execute([$inscriptionId]);
        return $st->fetchAll();
    }
}
