<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/** Validation des mails : adresse mal formée, faute de frappe connue dans le domaine, domaine qui ne reçoit pas de mail. */
final class VerifMails
{
    /** Fautes de frappe fréquentes -> domaine probable. */
    private const FAUTES = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmail.fr' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gamil.com' => 'gmail.com',
        'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.cm' => 'gmail.com',
        'hotmail.fe' => 'hotmail.fr', 'hotmial.fr' => 'hotmail.fr', 'hotmal.fr' => 'hotmail.fr', 'hotmail.ft' => 'hotmail.fr', 'hotmai.fr' => 'hotmail.fr',
        'yahoo.fe' => 'yahoo.fr', 'yahou.fr' => 'yahoo.fr', 'yaho.fr' => 'yahoo.fr', 'wanado.fr' => 'wanadoo.fr', 'wanadoo.com' => 'wanadoo.fr',
        'orange.com' => 'orange.fr', 'orage.fr' => 'orange.fr', 'free.com' => 'free.fr', 'sfr.com' => 'sfr.fr', 'laposte.fr' => 'laposte.net',
        'outlook.fe' => 'outlook.fr', 'icloud.fr' => 'icloud.com', 'live.fe' => 'live.fr', 'neuf.com' => 'neuf.fr',
    ];

    /** @var array<string,bool> */
    private array $dns = [];
    private ?bool $dnsDisponible = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** null si l'adresse paraît bonne, sinon [problème, suggestion ?] */
    public function verifier(string $mail): ?array
    {
        $m = trim($mail);
        if ($m === '') {
            return null;
        }
        if (!filter_var($m, FILTER_VALIDATE_EMAIL) || preg_match('/\s|\.\.|@.*@/', $m)) {
            return ['Adresse mal formée', null];
        }
        $dom = mb_strtolower(substr(strrchr($m, '@'), 1));
        if (isset(self::FAUTES[$dom])) {
            return ['Faute de frappe probable dans le domaine', substr($m, 0, strrpos($m, '@') + 1) . self::FAUTES[$dom]];
        }
        if ($this->dnsDisponible()) {
            if (!array_key_exists($dom, $this->dns)) {
                $this->dns[$dom] = checkdnsrr($dom, 'MX') || checkdnsrr($dom, 'A');
            }
            if (!$this->dns[$dom]) {
                return ['Le domaine « ' . $dom . ' » ne reçoit pas de mail', null];
            }
        }
        return null;
    }

    public function dnsDisponible(): bool
    {
        return $this->dnsDisponible ??= function_exists('checkdnsrr') && @checkdnsrr('gmail.com', 'MX');
    }

    /**
     * Fiches dont le mail pose problème (toutes, ou inscrits de la saison), y compris celles déjà marquées invalides.
     * @return list<array<string,mixed>>
     */
    public function problemes(?int $saisonId): array
    {
        $sql = 'SELECT a.id, a.nom, a.prenom, a.mail, a.adresse_mail_invalide, a.refus_mail FROM adherent a WHERE a.mail IS NOT NULL AND a.mail <> \'\'';
        $args = [];
        if ($saisonId) {
            $sql .= ' AND EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?)';
            $args[] = $saisonId;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY a.nom, a.prenom');
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll() as $a) {
            $p = $this->verifier((string) $a['mail']);
            if ($p || (int) $a['adresse_mail_invalide'] === 1) {
                $out[] = $a + ['probleme' => $p[0] ?? 'Marquée invalide (retours en erreur)', 'suggestion' => $p[1] ?? null];
            }
        }
        return $out;
    }

    /** Corrige le mail d'une fiche (suggestion acceptée) et retire la marque « invalide ». */
    public function corriger(int $adherentId, string $mail): bool
    {
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $this->pdo->prepare('UPDATE adherent SET mail = ?, adresse_mail_invalide = 0, date_modification = ? WHERE id = ?')->execute([$mail, date('Y-m-d H:i:s'), $adherentId]);
        $this->pdo->prepare("DELETE FROM marque_adherent WHERE adherent_id = ? AND champ = 'adresse_mail_invalide'")->execute([$adherentId]);
        return true;
    }
}
