<?php
declare(strict_types=1);

namespace Ascs\Web;

use Ascs\Parametres;
use PDO;

/**
 * Comptes de test (stagiaires, formation) : ils voient tout et peuvent saisir fiches, inscriptions, règlements,
 * présences et ventilations, mais aucun mail n'est envoyé et les actions qui sortent de l'application,
 * qui touchent aux réglages ou qui seraient refaites après un import WinDev (fusions, changements de cours,
 * validations de demandes et d'HelloAsso, remises…) sont refusées.
 */
final class ModeTest
{
    public const CLE = 'comptes_test';
    public const CLE_MAIL = 'mode_test_mail';
    /** Par défaut, les mails des comptes de test arrivent à l'adresse de l'association. */
    public const MAIL_DEFAUT = 'contact@ascs.fr';

    /** Actions permises à un compte de test (méthode POST, chemin). */
    private const PERMIS = [
        '#^/(connexion|deconnexion|saison)$#',
        '#^/adherents(/\d+)?$#',
        '#^/inscriptions(/\d+(/supprimer)?)?$#',
        '#^/reglements(/\d+(/supprimer)?)?$#',
        '#^/(presence|animateur|controle)/[^/]+(/ajouter)?$#',
        '#^/sections/cartes/\d+/recharger$#',
        '#^/sections/cartes/recharge/\d+/supprimer$#',
        '#^/sections/a-relancer/\d+$#',
        '#^/banque/ligne/\d+$#',
        '#^/mailing(/\d+/(modifier|test))?$#',
        // Mails redirigés vers l'adresse de test : on peut essayer les envois un par un
        '#^/inscriptions/\d+/facture/envoyer$#',
        '#^/sections/\d+/envoyer-lien$#',
        '#^/sections/animateur/envoyer$#',
        '#^/parametres/facture/essai$#',
    ];

    /** Adresse qui reçoit tous les mails des comptes de test ; null = aucun mail ne part. */
    public static function adresseMails(?PDO $pdo = null): ?string
    {
        try {
            $pdo ??= \Ascs\Db::connect();
            $v = Parametres::get($pdo, self::CLE_MAIL);
        } catch (\Throwable) {
            return null;
        }
        $v = $v === null ? self::MAIL_DEFAUT : trim((string) $v);
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
    }

    public static function reglerAdresse(PDO $pdo, string $adresse): void
    {
        Parametres::set($pdo, self::CLE_MAIL, trim($adresse));
    }

    /** Pages refusées même en simple consultation (téléchargement de toute la base). */
    private const PAGES_INTERDITES = ['#^/import/sauvegarde$#'];

    private static ?bool $actif = null;

    /** @return list<int> */
    public static function comptes(PDO $pdo): array
    {
        return array_map('intval', json_decode((string) Parametres::get($pdo, self::CLE), true) ?: []);
    }

    public static function regler(PDO $pdo, int $id, bool $test): void
    {
        $ids = array_values(array_diff(self::comptes($pdo), [$id]));
        if ($test) {
            $ids[] = $id;
        }
        Parametres::set($pdo, self::CLE, json_encode($ids));
        self::$actif = null;
    }

    /** Vrai si la personne connectée utilise un compte de test. */
    public static function actif(?PDO $pdo = null): bool
    {
        if (self::$actif !== null) {
            return self::$actif;
        }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid === 0 || PHP_SAPI === 'cli') {
            return false;
        }
        try {
            $pdo ??= \Ascs\Db::connect();
            return self::$actif = in_array($uid, self::comptes($pdo), true);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function autorise(string $methode, string $chemin): bool
    {
        if ($methode !== 'POST') {
            foreach (self::PAGES_INTERDITES as $r) {
                if (preg_match($r, $chemin)) {
                    return false;
                }
            }
            return true;
        }
        foreach (self::PERMIS as $r) {
            if (preg_match($r, $chemin)) {
                return true;
            }
        }
        return false;
    }

    /** Pour les tests automatiques. */
    public static function oublier(): void
    {
        self::$actif = null;
    }
}
