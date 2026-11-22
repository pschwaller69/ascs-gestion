<?php
declare(strict_types=1);

namespace Ascs\Mail;

use Ascs\Config;
use Ascs\Domain\Mailing;
use Ascs\Domain\Marques;
use Ascs\Parametres;
use PDO;

/**
 * Retours des mailings : la boîte du compte des mailings (contact@m.ascs.fr) est relevée par IMAP.
 * C'est là qu'arrivent les avis de non-remise (adresse de retour des mailings) et les désinscriptions par mail
 * (en-tête List-Unsubscribe). Chaque message est classé, noté dans la table mail_retour, et :
 *  - adresse inconnue (5.1.x)  : envoi marqué en échec, mail marqué invalide sur les fiches qui le portent ;
 *  - autre rejet définitif (5.x) : envoi marqué en échec (boîte pleine, refus pour spam… : à regarder) ;
 *  - retard (4.x)             : noté seulement, le serveur réessaie tout seul ;
 *  - désinscription            : la fiche est désinscrite des mails d'information ;
 *  - réponse, réponse automatique : notées pour lecture.
 * Les messages restent dans la boîte (ni supprimés ni marqués lus).
 */
final class Retours
{
    public const TYPES = [
        'adresse_inconnue' => 'Adresse inconnue',
        'rejet' => 'Rejeté',
        'retard' => 'Retardé',
        'desinscription' => 'Désinscription',
        'auto' => 'Réponse automatique',
        'reponse' => 'Autre message',
    ];

    /** Messages traités au plus par relève (les suivants le seront à la relève d'après). */
    public const MAX = 200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Réglages IMAP : par défaut le serveur SMTP et le compte des mailings (même boîte chez o2switch). @return ?array{host:string,port:int,user:string,pass:string,securite:string} */
    public static function config(): ?array
    {
        $c = Config::load();
        $user = $c['imap_user'] !== '' ? $c['imap_user'] : ($c['smtp_mailing_user'] !== '' ? $c['smtp_mailing_user'] : $c['smtp_user']);
        $pass = $c['imap_pass'] !== '' ? $c['imap_pass'] : ($c['smtp_mailing_user'] !== '' ? $c['smtp_mailing_pass'] : $c['smtp_pass']);
        $host = $c['imap_host'] !== '' ? $c['imap_host'] : $c['smtp_host'];
        if ($host === '' || $user === '' || $pass === '' || strtolower($c['imap_actif']) === 'non') {
            return null;
        }
        return ['host' => $host, 'port' => (int) $c['imap_port'], 'user' => $user, 'pass' => $pass, 'securite' => strtolower($c['imap_securite'])];
    }

    /** @return array{configure:bool,boite:?string,releve_le:?string,erreur:?string,a_traiter:int} */
    public function etat(): array
    {
        $cfg = self::config();
        return [
            'configure' => $cfg !== null, 'boite' => $cfg['user'] ?? null,
            'releve_le' => Parametres::get($this->pdo, 'retours_releve_le'), 'erreur' => Parametres::get($this->pdo, 'retours_erreur') ?: null,
            'a_traiter' => (int) $this->pdo->query("SELECT COUNT(*) FROM mail_retour WHERE type IN ('rejet','reponse')")->fetchColumn(),
        ];
    }

    /**
     * Relève les nouveaux messages de la boîte.
     * @return array{lus:int,types:array<string,int>,erreur:?string,occupe?:bool}
     */
    public function relever(?Imap $imap = null): array
    {
        $r = ['lus' => 0, 'types' => [], 'erreur' => null];
        $cfg = self::config();
        if ($imap === null && $cfg === null) {
            $r['erreur'] = 'Boîte des mailings non configurée (SMTP_HOST et SMTP_MAILING_USER / SMTP_MAILING_PASS dans le fichier .env).';
            return $r;
        }
        $verrou = @fopen(dirname(__DIR__, 2) . '/storage/retours.lock', 'c');
        if ($verrou && !flock($verrou, LOCK_EX | LOCK_NB)) {
            $r['occupe'] = true;
            return $r;
        }
        $imap ??= new Imap($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['securite']);
        try {
            $validite = $imap->ouvrir();
            $dernier = (int) (Parametres::get($this->pdo, 'retours_dernier_uid') ?? 0);
            $avant = (int) (Parametres::get($this->pdo, 'retours_uidvalidity') ?? 0);
            if ($avant !== 0 && $avant !== $validite) {
                $dernier = 0; // boîte recréée : numéros remis à zéro (les messages déjà notés sont reconnus et sautés)
            }
            Parametres::set($this->pdo, 'retours_uidvalidity', (string) $validite);
            foreach (array_slice($imap->uidsApres($dernier), 0, self::MAX) as $uid) {
                $brut = $imap->message($uid);
                if ($brut !== null && !$this->dejaNote($validite, $uid)) {
                    $t = $this->traiter($brut, $validite, $uid);
                    $r['types'][$t] = ($r['types'][$t] ?? 0) + 1;
                    $r['lus']++;
                }
                Parametres::set($this->pdo, 'retours_dernier_uid', (string) $uid);
            }
            $imap->fermer();
            Parametres::set($this->pdo, 'retours_erreur', '');
        } catch (\RuntimeException $e) {
            $r['erreur'] = 'Relève de la boîte impossible : ' . $e->getMessage() . '.';
            Parametres::set($this->pdo, 'retours_erreur', $r['erreur']);
        }
        Parametres::set($this->pdo, 'retours_releve_le', date('Y-m-d H:i:s'));
        return $r;
    }

    public static function bilan(array $r): string
    {
        if (!empty($r['occupe'])) {
            return 'Une relève est déjà en cours : rechargez la page dans une minute.';
        }
        if (!$r['lus']) {
            return 'Boîte relevée : aucun nouveau message.';
        }
        $p = [];
        foreach ($r['types'] as $t => $n) {
            $p[] = $n . ' ' . mb_strtolower(self::TYPES[$t] ?? $t);
        }
        return 'Boîte relevée : ' . $r['lus'] . ' nouveau(x) message(s) (' . implode(', ', $p) . ').';
    }

    private function dejaNote(int $validite, int $uid): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM mail_retour WHERE uidvalidity = ? AND uid = ?');
        $st->execute([$validite, $uid]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Classe un message, applique ce qu'il faut, le note. @return string type */
    public function traiter(string $brut, int $validite = 0, int $uid = 0): string
    {
        $a = self::analyser($brut);
        $action = null;
        $campagne = $a['campagne_id'];
        $dest = $a['destinataire_id'];
        if ($a['type'] === 'desinscription') {
            $id = Mailing::verifierJeton($a['jeton']);
            $action = $id !== null && (new Mailing($this->pdo))->desinscrire($id) ? 'Fiche n° ' . $id . ' désinscrite des mails d\'information.' : 'Lien de désinscription non valable : rien fait.';
        } elseif (in_array($a['type'], ['adresse_inconnue', 'rejet'], true) && $a['adresse'] !== null) {
            $d = $this->destinataire($a['adresse'], $campagne, $dest);
            if ($d) {
                $campagne = (int) $d['campagne_id'];
                $dest = (int) $d['id'];
                $this->pdo->prepare("UPDATE campagne_destinataire SET statut = 'echec', erreur = ? WHERE id = ?")
                    ->execute(['Rejeté par le serveur du destinataire' . ($a['statut'] ? ' (' . $a['statut'] . ')' : '') . ($a['diagnostic'] ? ' : ' . mb_substr($a['diagnostic'], 0, 300) : ''), $dest]);
                $action = 'Envoi du mailing marqué en échec.';
            }
            if ($a['type'] === 'adresse_inconnue') {
                $st = $this->pdo->prepare('SELECT id FROM adherent WHERE LOWER(mail) = ? AND adresse_mail_invalide = 0');
                $st->execute([$a['adresse']]);
                $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                foreach ($ids as $id) {
                    (new Marques($this->pdo))->poser((int) $id, 'adresse_mail_invalide', 1, 'retour de mailing');
                }
                if ($ids) {
                    $action = trim(($action ?? '') . ' Mail marqué invalide sur ' . count($ids) . ' fiche(s).');
                }
            }
        }
        $this->pdo->prepare('INSERT INTO mail_retour (recu_le, releve_le, uidvalidity, uid, de, objet, type, adresse, statut_smtp, diagnostic, campagne_id, destinataire_id, action)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $a['date'], date('Y-m-d H:i:s'), $validite, $uid, mb_substr($a['de'], 0, 255), mb_substr($a['objet'], 0, 255), $a['type'],
            $a['adresse'] !== null ? mb_substr($a['adresse'], 0, 150) : null, $a['statut'], $a['diagnostic'] !== null ? mb_substr($a['diagnostic'], 0, 2000) : null,
            $campagne, $dest, $action,
        ]);
        return $a['type'];
    }

    /** Destinataire du mailing concerné : d'après la référence du mail d'origine, sinon le dernier envoi à cette adresse (15 jours). */
    private function destinataire(string $adresse, ?int $campagne, ?int $dest): ?array
    {
        if ($dest) {
            $st = $this->pdo->prepare('SELECT * FROM campagne_destinataire WHERE id = ? AND LOWER(adresse) = ?');
            $st->execute([$dest, $adresse]);
            if ($d = $st->fetch()) {
                return $d;
            }
        }
        $st = $this->pdo->prepare("SELECT * FROM campagne_destinataire WHERE LOWER(adresse) = ? AND statut IN ('envoye','echec') AND envoye_le >= ?"
            . ($campagne ? ' AND campagne_id = ' . (int) $campagne : '') . ' ORDER BY envoye_le DESC, id DESC LIMIT 1');
        $st->execute([$adresse, date('Y-m-d H:i:s', time() - 15 * 86400)]);
        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function liste(string $type = '', int $limite = 300): array
    {
        $sql = 'SELECT r.*, c.objet AS campagne_objet FROM mail_retour r LEFT JOIN campagne c ON c.id = r.campagne_id';
        $args = [];
        if (isset(self::TYPES[$type])) {
            $sql .= ' WHERE r.type = ?';
            $args[] = $type;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY r.id DESC LIMIT ' . (int) $limite);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function deCampagne(int $campagneId): array
    {
        try {
            $st = $this->pdo->prepare('SELECT * FROM mail_retour WHERE campagne_id = ? ORDER BY id DESC');
            $st->execute([$campagneId]);
            return $st->fetchAll();
        } catch (\PDOException) {
            return [];
        }
    }

    /* ------------------------------------------------------------------ lecture d'un message */

    /**
     * @return array{type:string,de:string,objet:string,date:string,adresse:?string,statut:?string,diagnostic:?string,campagne_id:?int,destinataire_id:?int,jeton:string}
     */
    public static function analyser(string $brut): array
    {
        [$h, $corps] = self::separer($brut);
        $objet = self::decoderEntete($h['subject'][0] ?? '');
        $de = self::decoderEntete($h['from'][0] ?? '');
        $type = strtolower($h['content-type'][0] ?? '');
        $textes = [];
        self::parties($h, $corps, $textes);
        $texte = implode("\n", $textes);
        $date = strtotime($h['date'][0] ?? '') ?: time();
        $a = [
            'type' => 'reponse', 'de' => $de, 'objet' => $objet, 'date' => date('Y-m-d H:i:s', $date),
            'adresse' => null, 'statut' => null, 'diagnostic' => null, 'campagne_id' => null, 'destinataire_id' => null, 'jeton' => '',
        ];
        if (preg_match('/X-ASCS-Ref:\s*c(\d+)-d(\d+)/i', $texte . "\n" . $brut, $m)) {
            $a['campagne_id'] = (int) $m[1];
            $a['destinataire_id'] = (int) $m[2] ?: null;
        }
        // Désinscription par mail (mailto de l'en-tête List-Unsubscribe) : objet « desinscription <jeton> »
        if (preg_match('/d[ée]sinscription\s+(\d+-[0-9a-f]{24})/iu', $objet, $m)) {
            $a['type'] = 'desinscription';
            $a['jeton'] = $m[1];
            return $a;
        }
        $dsn = str_contains($type, 'report-type=delivery-status') || str_contains($type, 'report-type="delivery-status"')
            || isset($h['x-failed-recipients'])
            || preg_match('/mailer-daemon|postmaster|mail delivery (sub)?system/i', $de)
            || preg_match('/undeliver|delivery (status|failure|has failed)|mail delivery failed|returned mail|failure notice|non remis|non distribu|échec de (la )?remise|delayed mail|warning: message/iu', $objet);
        if ($dsn) {
            $f = self::champsDsn($texte);
            $adresse = $f['final-recipient'] ?? $f['original-recipient'] ?? null;
            if ($adresse !== null) {
                $adresse = preg_replace('/^[^;]*;\s*/', '', $adresse);
            }
            $adresse ??= trim(explode(',', $h['x-failed-recipients'][0] ?? '')[0]) ?: null;
            if ($adresse === null && preg_match('/^\s+(\S+@\S+\.[a-z]{2,})\s*$/mi', $texte, $m)) {
                $adresse = $m[1]; // avis en texte seul (Exim) : adresse en retrait sous « failed »
            }
            $a['adresse'] = $adresse !== null ? mb_strtolower(trim($adresse, " <>\t")) : null;
            $statut = $f['status'] ?? null;
            if ($statut === null && preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $texte, $m)) {
                $statut = $m[1];
            }
            $diag = $f['diagnostic-code'] ?? null;
            if ($diag === null && preg_match('/^.*\b[45]\d\d[ -].*$/m', $texte, $m)) {
                $diag = trim($m[0]);
            }
            $a['statut'] = $statut !== null ? preg_replace('/\s.*/', '', $statut) : null;
            $a['diagnostic'] = $diag !== null ? trim(preg_replace('/^[^;]*;\s*/', '', $diag)) : null;
            $action = strtolower($f['action'] ?? '');
            if ($action === 'delayed' || str_starts_with((string) $a['statut'], '4') || preg_match('/delayed|warning: message|retard/iu', $objet)) {
                $a['type'] = 'retard';
            } elseif (preg_match('/^5\.1\.\d+$/', (string) $a['statut'])
                || preg_match('/user unknown|unknown user|no such user|does not exist|doesn\'t exist|mailbox unavailable|address rejected|recipient address rejected|invalid recipient|n\'existe pas|introuvable|inconnu/iu', (string) $a['diagnostic'])) {
                $a['type'] = 'adresse_inconnue';
            } else {
                $a['type'] = 'rejet';
            }
            return $a;
        }
        $auto = strtolower($h['auto-submitted'][0] ?? 'no');
        if (($auto !== 'no' && $auto !== '') || isset($h['x-autoreply']) || isset($h['x-autorespond'])
            || preg_match('/^(r[ée]ponse automatique|automatic reply|auto(matic)?[ -]?reply|autoreply|absence|out of (the )?office|abwesen)/iu', $objet)) {
            $a['type'] = 'auto';
        }
        $a['diagnostic'] = mb_substr(trim((string) preg_replace('/\s+/', ' ', $textes[0] ?? '')), 0, 500) ?: null;
        return $a;
    }

    /** @return array{0:array<string,list<string>>,1:string} en-têtes (noms en minuscules, lignes repliées réunies) et corps */
    private static function separer(string $brut): array
    {
        $brut = str_replace("\r\n", "\n", $brut);
        $pos = strpos($brut, "\n\n");
        $tete = $pos === false ? $brut : substr($brut, 0, $pos);
        $corps = $pos === false ? '' : substr($brut, $pos + 2);
        $h = [];
        foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', $tete)) as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $h[strtolower(trim($k))][] = trim($v);
            }
        }
        return [$h, $corps];
    }

    /** Textes lisibles d'un message (parties texte, rapports de remise, en-têtes du mail d'origine), décodés. @param list<string> $out */
    private static function parties(array $h, string $corps, array &$out, int $profondeur = 0): void
    {
        $type = strtolower($h['content-type'][0] ?? 'text/plain');
        if ($profondeur < 5 && str_starts_with($type, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $h['content-type'][0], $m)) {
            foreach (array_slice(explode('--' . $m[1], $corps), 1) as $p) {
                if (str_starts_with($p, '--')) {
                    break;
                }
                [$ph, $pc] = self::separer(ltrim($p, "\n"));
                self::parties($ph, $pc, $out, $profondeur + 1);
            }
            return;
        }
        $enc = strtolower($h['content-transfer-encoding'][0] ?? '');
        $txt = match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $corps)),
            'quoted-printable' => quoted_printable_decode($corps),
            default => $corps,
        };
        if (preg_match('/charset="?([^";]+)/i', $type, $m) && !in_array(strtolower($m[1]), ['utf-8', 'us-ascii'], true)) {
            $txt = (string) @mb_convert_encoding($txt, 'UTF-8', $m[1]);
        }
        if ($profondeur < 5 && str_starts_with($type, 'message/rfc822')) {
            [$ph, $pc] = self::separer($txt);
            $out[] = self::teteBrute($txt);
            self::parties($ph, $pc, $out, $profondeur + 1);
            return;
        }
        if (str_starts_with($type, 'text/plain') || str_starts_with($type, 'message/') || str_starts_with($type, 'text/rfc822-headers')) {
            $out[] = $txt;
        } elseif (str_starts_with($type, 'text/html')) {
            $out[] = html_entity_decode(strip_tags(preg_replace('/<(br|p|div)[^>]*>/i', "\n", $txt)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    private static function teteBrute(string $m): string
    {
        $pos = strpos($m, "\n\n");
        return $pos === false ? $m : substr($m, 0, $pos);
    }

    /** Champs d'un rapport de remise (RFC 3464), première valeur de chacun. @return array<string,string> */
    private static function champsDsn(string $texte): array
    {
        $f = [];
        foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', str_replace("\r\n", "\n", $texte))) as $l) {
            if (preg_match('/^(Final-Recipient|Original-Recipient|Action|Status|Diagnostic-Code)\s*:\s*(.+)$/i', $l, $m)) {
                $f[strtolower($m[1])] ??= trim($m[2]);
            }
        }
        return $f;
    }

    private static function decoderEntete(string $s): string
    {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $d === false ? $s : $d;
    }
}
