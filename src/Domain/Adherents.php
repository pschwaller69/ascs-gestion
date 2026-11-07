<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

final class Adherents
{
    public const PAR_PAGE = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Valeurs vides d'une nouvelle fiche. */
    public static function vide(): array
    {
        return [
            'nom' => '', 'prenom' => '', 'mail' => '', 'nom_parent' => '', 'prenom_parent' => '', 'adresse' => '',
            'code_postal' => '', 'ville' => '', 'date_naissance' => '', 'genre' => '', 'telephone1' => '', 'telephone2' => '',
            'remarques' => '', 'membre_ca' => 0, 'attestation_ce' => 0, 'refus_mail' => 0, 'refus_sms' => 0,
            'refus_whatsapp' => 0, 'adresse_mail_invalide' => 0,
        ];
    }

    /**
     * Nettoie et contrôle une saisie.
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} [données, erreurs par champ]
     */
    public function nettoyer(array $in): array
    {
        $err = [];
        $nom = Input::str($in, 'nom', 100);
        $prenom = Input::str($in, 'prenom', 100);
        $titre = static fn (?string $s) => $s === null ? null : mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
        $d = [
            'nom' => $nom === null ? null : mb_strtoupper($nom),
            'prenom' => $titre($prenom),
            'mail' => ($m = Input::str($in, 'mail', 150)) === null ? null : mb_strtolower($m),
            'nom_parent' => ($x = Input::str($in, 'nom_parent', 100)) === null ? null : mb_strtoupper($x),
            'prenom_parent' => $titre(Input::str($in, 'prenom_parent', 100)),
            'adresse' => Input::str($in, 'adresse'),
            'code_postal' => Input::str($in, 'code_postal', 10),
            'ville' => Input::str($in, 'ville', 100),
            'date_naissance' => null,
            'genre' => null,
            'telephone1' => Input::str($in, 'telephone1', 30),
            'telephone2' => Input::str($in, 'telephone2', 30),
            'remarques' => Input::text($in, 'remarques'),
            'membre_ca' => Input::bool($in, 'membre_ca'),
            'attestation_ce' => Input::bool($in, 'attestation_ce'),
            'refus_mail' => Input::bool($in, 'refus_mail'),
            'refus_sms' => Input::bool($in, 'refus_sms'),
            'refus_whatsapp' => Input::bool($in, 'refus_whatsapp'),
            'adresse_mail_invalide' => Input::bool($in, 'adresse_mail_invalide'),
        ];
        if ($d['nom'] === null && $d['prenom'] === null) {
            $err['nom'] = 'Indiquez au moins le nom ou le prénom.';
        }
        if ($d['mail'] !== null && !filter_var($d['mail'], FILTER_VALIDATE_EMAIL)) {
            $err['mail'] = 'Adresse mail invalide.';
        }
        if ($d['code_postal'] !== null && !preg_match('/^\d{5}$/', $d['code_postal'])) {
            $err['code_postal'] = 'Un code postal compte 5 chiffres.';
        }
        foreach (['telephone1', 'telephone2'] as $t) {
            if ($d[$t] !== null && !preg_match('/^[0-9 +().\/-]{6,25}$/', $d[$t])) {
                $err[$t] = 'Numéro de téléphone invalide.';
            }
        }
        $dnBrute = Input::str($in, 'date_naissance', 10);
        if ($dnBrute !== null) {
            $dn = Input::date($in, 'date_naissance');
            if ($dn === null) {
                $err['date_naissance'] = 'Date invalide.';
            } elseif ((int) substr($dn, 0, 4) < 1900 || $dn > date('Y-m-d')) {
                $err['date_naissance'] = 'Date de naissance improbable : vérifiez l\'année.';
            } else {
                $d['date_naissance'] = $dn;
            }
        }
        $g = $in['genre'] ?? '';
        if ($g === '0' || $g === '1') {
            $d['genre'] = (int) $g;
        } else {
            $err['genre'] = 'Choisissez femme ou homme.';
        }
        return [$d, $err];
    }

    public function creer(array $d): int
    {
        $d['date_creation'] = date('Y-m-d H:i:s');
        $cols = array_keys($d);
        $this->pdo->prepare('INSERT INTO adherent (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function modifier(int $id, array $d): void
    {
        $d['date_modification'] = date('Y-m-d H:i:s');
        $set = implode(',', array_map(static fn ($c) => "$c = ?", array_keys($d)));
        $this->pdo->prepare("UPDATE adherent SET $set WHERE id = ?")->execute([...array_values($d), $id]);
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM adherent WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Liste paginée.
     *
     * @return array{lignes:list<array<string,mixed>>,total:int}
     */
    public function lister(string $q, bool $saisonSeule, int $saisonId, int $page): array
    {
        $where = [];
        $args = [];
        if ($q !== '') {
            foreach (preg_split('/\s+/', $q) ?: [] as $mot) {
                $like = Input::like($mot);
                $where[] = "(a.nom LIKE ? ESCAPE '!' OR a.prenom LIKE ? ESCAPE '!' OR a.mail LIKE ? ESCAPE '!' OR a.telephone1 LIKE ? ESCAPE '!' OR a.telephone2 LIKE ? ESCAPE '!' OR a.nom_parent LIKE ? ESCAPE '!')";
                array_push($args, $like, $like, $like, $like, $like, $like);
            }
        }
        if ($saisonSeule) {
            $where[] = 'EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?)';
            $args[] = $saisonId;
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM adherent a $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare(
            "SELECT a.id, a.nom, a.prenom, a.mail, a.telephone1, a.date_naissance, a.ville,
                    (SELECT COUNT(*) FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?) AS nb_saison
             FROM adherent a $w ORDER BY a.nom, a.prenom, a.id LIMIT " . self::PAR_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PAR_PAGE
        );
        $st->execute([$saisonId, ...$args]);
        return ['lignes' => $st->fetchAll(), 'total' => $total];
    }
}
