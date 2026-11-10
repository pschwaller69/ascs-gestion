<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Cases cochées sur une fiche depuis l'application (désinscription des mails, mail invalide…) :
 * enregistrées à part pour être réappliquées après un import WinDev (mode transition).
 */
final class Marques
{
    public const CHAMPS = ['refus_mail', 'refus_sms', 'adresse_mail_invalide'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function poser(int $adherentId, string $champ, int $valeur, string $par): bool
    {
        if (!in_array($champ, self::CHAMPS, true)) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT mail FROM adherent WHERE id = ?');
        $st->execute([$adherentId]);
        $mail = $st->fetchColumn();
        if ($mail === false) {
            return false;
        }
        $this->pdo->prepare("UPDATE adherent SET $champ = ?, date_modification = ? WHERE id = ?")->execute([$valeur, date('Y-m-d H:i:s'), $adherentId]);
        $this->pdo->prepare('DELETE FROM marque_adherent WHERE adherent_id = ? AND champ = ?')->execute([$adherentId, $champ]);
        $this->pdo->prepare('INSERT INTO marque_adherent (adherent_id, champ, valeur, mail, date_marque, par) VALUES (?,?,?,?,?,?)')
            ->execute([$adherentId, $champ, $valeur, $mail, date('Y-m-d H:i:s'), mb_substr($par, 0, 150)]);
        return true;
    }

    /** Après un import : réapplique chaque marque ; « mail invalide » seulement si le mail n'a pas changé entre-temps. */
    public function rejouerApresImport(): int
    {
        $n = 0;
        foreach ($this->pdo->query('SELECT * FROM marque_adherent ORDER BY id')->fetchAll() as $m) {
            if (!in_array($m['champ'], self::CHAMPS, true)) {
                continue;
            }
            $st = $this->pdo->prepare('SELECT mail, ' . $m['champ'] . ' AS v FROM adherent WHERE id = ?');
            $st->execute([$m['adherent_id']]);
            $a = $st->fetch();
            if (!$a || (int) $a['v'] === (int) $m['valeur']) {
                continue;
            }
            if ($m['champ'] === 'adresse_mail_invalide' && mb_strtolower((string) $a['mail']) !== mb_strtolower((string) $m['mail'])) {
                continue;
            }
            $this->pdo->prepare("UPDATE adherent SET {$m['champ']} = ? WHERE id = ?")->execute([$m['valeur'], $m['adherent_id']]);
            $n++;
        }
        return $n;
    }
}
