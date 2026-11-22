<?php
declare(strict_types=1);

namespace Ascs\Mail;

/**
 * Client IMAP minimal, en lecture seule (relève de la boîte des mailings, par exemple contact@m.ascs.fr chez o2switch).
 * Port 993 : SSL direct. Les messages ne sont ni supprimés ni marqués lus : on retient seulement le dernier numéro (UID) traité.
 */
final class Imap
{
    /** @var resource|null */
    private $flux = null;
    private int $n = 0;

    public function __construct(
        private readonly string $hote,
        private readonly int $port,
        private readonly string $utilisateur,
        private readonly string $motDePasse,
        private readonly string $securite, // ssl | aucune (tests)
    ) {
    }

    public function __destruct()
    {
        $this->fermer();
    }

    public function fermer(): void
    {
        if ($this->flux) {
            try {
                $this->commande('LOGOUT');
            } catch (\RuntimeException) {
                // déjà coupée
            }
            @fclose($this->flux);
            $this->flux = null;
        }
    }

    /** Connexion et ouverture de la boîte de réception. @return int UIDVALIDITY de la boîte */
    public function ouvrir(string $dossier = 'INBOX'): int
    {
        $cible = ($this->securite === 'ssl' ? 'ssl://' : 'tcp://') . $this->hote . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->hote]]);
        $f = @stream_socket_client($cible, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$f) {
            throw new \RuntimeException('connexion impossible à ' . $this->hote . ':' . $this->port . ' (' . trim($errstr) . ')');
        }
        stream_set_timeout($f, 30);
        $this->flux = $f;
        $accueil = $this->ligne();
        if (!str_starts_with($accueil, '* OK')) {
            throw new \RuntimeException('le serveur IMAP refuse la connexion : ' . $accueil);
        }
        try {
            $this->commande('LOGIN ' . self::chaine($this->utilisateur) . ' ' . self::chaine($this->motDePasse));
        } catch (\RuntimeException) {
            throw new \RuntimeException('identifiant ou mot de passe refusé par le serveur IMAP');
        }
        $validite = 0;
        foreach ($this->commande('SELECT ' . self::chaine($dossier))['lignes'] as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                $validite = (int) $m[1];
            }
        }
        return $validite;
    }

    /** UID des messages strictement après $apres. @return list<int> */
    public function uidsApres(int $apres): array
    {
        $uids = [];
        foreach ($this->commande('UID SEARCH UID ' . ($apres + 1) . ':*')['lignes'] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    // « n:* » renvoie toujours le dernier message, même s'il est avant n
                    if ($u !== '' && (int) $u > $apres) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** Message complet (en-têtes et corps), sans le marquer lu. */
    public function message(int $uid): ?string
    {
        $r = $this->commande('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $r['litteraux'][0] ?? null;
    }

    /** @return array{lignes:list<string>,litteraux:list<string>} */
    private function commande(string $cmd): array
    {
        if (!$this->flux) {
            throw new \RuntimeException('connexion perdue');
        }
        $tag = 'A' . (++$this->n);
        fwrite($this->flux, $tag . ' ' . $cmd . "\r\n");
        $lignes = [];
        $litteraux = [];
        while (true) {
            $l = $this->ligne();
            // Littéral {n} : n octets bruts suivent (le message), puis la fin de la ligne
            while (preg_match('/\{(\d+)\}$/', $l, $m)) {
                $litteraux[] = $this->octets((int) $m[1]);
                $l = substr($l, 0, -strlen($m[0])) . '{…}' . $this->ligne();
            }
            if (str_starts_with($l, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $l)) {
                    throw new \RuntimeException('le serveur IMAP a répondu : ' . substr($l, strlen($tag) + 1));
                }
                return ['lignes' => $lignes, 'litteraux' => $litteraux];
            }
            $lignes[] = $l;
        }
    }

    private function ligne(): string
    {
        $l = fgets($this->flux, 65536);
        if ($l === false) {
            $meta = stream_get_meta_data($this->flux);
            throw new \RuntimeException(!empty($meta['timed_out']) ? 'pas de réponse du serveur IMAP' : 'connexion perdue');
        }
        return rtrim($l, "\r\n");
    }

    private function octets(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $b = fread($this->flux, min(65536, $n - strlen($out)));
            if ($b === false || $b === '') {
                throw new \RuntimeException('connexion perdue');
            }
            $out .= $b;
        }
        return $out;
    }

    /** Chaîne entre guillemets (RFC 3501). */
    private static function chaine(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
