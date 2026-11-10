<?php
declare(strict_types=1);

namespace Ascs\Mail;

/**
 * Client SMTP minimal avec authentification (compte mail o2switch, par exemple contact@m.ascs.fr).
 * Port 465 : SSL direct ; port 587 : STARTTLS. Une connexion est gardée ouverte pendant un lot d'envois.
 */
final class Smtp
{
    /** @var resource|null */
    private $flux = null;

    public function __construct(
        private readonly string $hote,
        private readonly int $port,
        private readonly string $utilisateur,
        private readonly string $motDePasse,
        private readonly string $securite, // ssl | tls | aucune (tests)
    ) {
    }

    public function __destruct()
    {
        if ($this->flux) {
            @fwrite($this->flux, "QUIT\r\n");
            @fclose($this->flux);
        }
    }

    /** @return ?string erreur, null si le serveur a accepté le message */
    public function envoyer(string $de, string $a, string $message): ?string
    {
        try {
            $this->connecter();
            $this->commande('MAIL FROM:<' . $de . '>', [250]);
            $this->commande('RCPT TO:<' . $a . '>', [250, 251]);
            $this->commande('DATA', [354]);
            // Lignes commençant par un point doublées (RFC 5321)
            $corps = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
            $this->commande(str_replace("\n", "\r\n", rtrim($corps, "\n")) . "\r\n.", [250]);
            return null;
        } catch (\RuntimeException $e) {
            if ($this->flux) {
                // Connexion coupée ou muette : on la ferme pour que le mail suivant en ouvre une neuve.
                $meta = stream_get_meta_data($this->flux);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || str_contains($e->getMessage(), 'connexion perdue') || str_contains($e->getMessage(), 'pas de réponse')) {
                    @fclose($this->flux);
                    $this->flux = null;
                } else {
                    @fwrite($this->flux, "RSET\r\n");
                    @fgets($this->flux, 515);
                }
            }
            return 'SMTP : ' . $e->getMessage();
        }
    }

    private function connecter(): void
    {
        if ($this->flux) {
            return;
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $cible = ($this->securite === 'ssl' ? 'ssl://' : 'tcp://') . $this->hote . ':' . $this->port;
        $f = @stream_socket_client($cible, $no, $msg, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$f) {
            throw new \RuntimeException('connexion impossible à ' . $this->hote . ':' . $this->port . ' (' . $msg . ')');
        }
        stream_set_timeout($f, 30);
        $this->flux = $f;
        try {
            $this->ouvrirSession();
        } catch (\RuntimeException $e) {
            @fclose($f);
            $this->flux = null; // prochaine tentative : nouvelle connexion
            throw $e;
        }
    }

    private function ouvrirSession(): void
    {
        $f = $this->flux;
        $this->lire([220]);
        $nom = gethostname() ?: 'localhost';
        $ehlo = $this->commande('EHLO ' . $nom, [250]);
        if ($this->securite === 'tls') {
            $this->commande('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('chiffrement TLS impossible');
            }
            $ehlo = $this->commande('EHLO ' . $nom, [250]);
        }
        if ($this->utilisateur !== '') {
            if (stripos($ehlo, 'AUTH') === false) {
                throw new \RuntimeException('le serveur ne propose pas d\'authentification');
            }
            $this->commande('AUTH LOGIN', [334]);
            $this->commande(base64_encode($this->utilisateur), [334]);
            $this->commande(base64_encode($this->motDePasse), [235], true);
        }
    }

    /** @param list<int> $codes */
    private function commande(string $ligne, array $codes, bool $secret = false): string
    {
        if (@fwrite($this->flux, $ligne . "\r\n") === false) {
            throw new \RuntimeException('connexion perdue');
        }
        try {
            return $this->lire($codes);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(($secret ? 'identifiant ou mot de passe refusé' : explode(' ', $ligne)[0]) . ' : ' . $e->getMessage());
        }
    }

    /** @param list<int> $codes */
    private function lire(array $codes): string
    {
        $tout = '';
        while (($l = fgets($this->flux, 515)) !== false) {
            $tout .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($tout, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(trim($tout) !== '' ? trim(preg_replace('/\s+/', ' ', $tout)) : 'pas de réponse du serveur');
        }
        return $tout;
    }
}
