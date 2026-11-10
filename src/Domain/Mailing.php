<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Config;
use Ascs\Documents\Reglages;
use Ascs\Mail\Envoi;
use Ascs\Mail\Sms;
use Ascs\Parametres;
use Ascs\Web\App;
use PDO;

/**
 * Mailings et SMS en nombre : choix des destinataires, file d'envoi par lots, désinscription.
 * Rien ne part sans validation du bureau ; les refus (mail, SMS) et les adresses marquées invalides sont toujours exclus.
 */
final class Mailing
{
    public const CIBLES = [
        'saison' => 'Inscrits de la saison',
        'section' => 'Inscrits de sections choisies',
        'non_reinscrits' => 'Inscrits la saison précédente, pas encore réinscrits',
        'anciens' => 'Anciens adhérents (inscrits avant, pas cette saison)',
    ];

    /** Envois par lot : o2switch limite le nombre de mails par heure ; le reste part aux lots suivants (bouton ou tâche cron). */
    public const LOT_MAIL = 40;
    public const LOT_SMS = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    private function saisonPrecedente(int $saisonId): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut < (SELECT date_debut FROM saison WHERE id = ?) ORDER BY date_debut DESC LIMIT 1');
        $st->execute([$saisonId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Destinataires d'une cible, une ligne par adresse (ou par numéro) : une famille qui partage un mail ne reçoit qu'un message.
     * @param list<int> $sections
     * @return array{retenus:list<array<string,mixed>>,exclus:array<string,int>}
     */
    public function destinataires(string $canal, string $cible, int $saisonId, array $sections = []): array
    {
        $args = [];
        $sql = 'SELECT a.id, a.nom, a.prenom, a.mail, a.telephone1, a.telephone2, a.refus_mail, a.refus_sms, a.adresse_mail_invalide FROM adherent a WHERE ';
        $essaiExclu = ' AND i.section_id NOT IN (SELECT id FROM section WHERE essai = 1)';
        switch ($cible) {
            case 'saison':
                $sql .= 'EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?' . $essaiExclu . ')';
                $args[] = $saisonId;
                break;
            case 'section':
                $sections = array_values(array_filter(array_map('intval', $sections)));
                if (!$sections) {
                    return ['retenus' => [], 'exclus' => []];
                }
                $sql .= 'EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ? AND i.section_id IN (' . implode(',', array_fill(0, count($sections), '?')) . '))';
                $args = [$saisonId, ...$sections];
                break;
            case 'non_reinscrits':
                $prec = $this->saisonPrecedente($saisonId);
                if ($prec === null) {
                    return ['retenus' => [], 'exclus' => []];
                }
                $sql .= 'EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?' . $essaiExclu . ')
                    AND NOT EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?' . $essaiExclu . ')';
                $args = [$prec, $saisonId];
                break;
            case 'anciens':
                $sql .= 'EXISTS (SELECT 1 FROM inscription i JOIN saison s ON s.id = i.saison_id WHERE i.adherent_id = a.id AND s.date_debut < (SELECT date_debut FROM saison WHERE id = ?))
                    AND NOT EXISTS (SELECT 1 FROM inscription i WHERE i.adherent_id = a.id AND i.saison_id = ?)';
                $args = [$saisonId, $saisonId];
                break;
            default:
                return ['retenus' => [], 'exclus' => []];
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY a.nom, a.prenom');
        $st->execute($args);
        $retenus = [];
        $vus = [];
        $exclus = ['refus' => 0, 'sans' => 0, 'invalide' => 0, 'doublon' => 0];
        // Un refus vaut pour l'adresse ou le numéro, pas seulement pour la fiche : une famille qui partage un mail
        // et se désinscrit depuis l'une des fiches ne doit plus rien recevoir par les autres.
        $refuses = $this->refuses($canal);
        foreach ($st->fetchAll() as $a) {
            if ($canal === 'sms') {
                if ((int) $a['refus_sms'] === 1) {
                    $exclus['refus']++;
                    continue;
                }
                $num = Sms::mobile((string) $a['telephone1']) ?? Sms::mobile((string) $a['telephone2']);
                if ($num === null) {
                    $exclus['sans']++;
                    continue;
                }
                if (isset($refuses[$num])) {
                    $exclus['refus']++;
                    continue;
                }
                $cle = $num;
            } else {
                if ((int) $a['refus_mail'] === 1) {
                    $exclus['refus']++;
                    continue;
                }
                $m = mb_strtolower(trim((string) $a['mail']));
                if ($m === '') {
                    $exclus['sans']++;
                    continue;
                }
                if ((int) $a['adresse_mail_invalide'] === 1 || !filter_var($m, FILTER_VALIDATE_EMAIL)) {
                    $exclus['invalide']++;
                    continue;
                }
                if (isset($refuses[$m])) {
                    $exclus['refus']++;
                    continue;
                }
                $cle = $m;
            }
            if (isset($vus[$cle])) {
                $exclus['doublon']++;
                continue;
            }
            $vus[$cle] = true;
            $retenus[] = ['adherent_id' => (int) $a['id'], 'nom' => $a['nom'], 'prenom' => $a['prenom'], 'adresse' => $cle];
        }
        return ['retenus' => $retenus, 'exclus' => array_filter($exclus)];
    }

    /** Adresses (ou numéros de portable) portées par au moins une fiche qui refuse ce canal. @return array<string,true> */
    private function refuses(string $canal): array
    {
        $out = [];
        if ($canal === 'sms') {
            foreach ($this->pdo->query('SELECT telephone1, telephone2 FROM adherent WHERE refus_sms = 1')->fetchAll() as $a) {
                foreach ([$a['telephone1'], $a['telephone2']] as $t) {
                    if (($n = Sms::mobile((string) $t)) !== null) {
                        $out[$n] = true;
                    }
                }
            }
            return $out;
        }
        foreach ($this->pdo->query("SELECT mail FROM adherent WHERE refus_mail = 1 AND mail IS NOT NULL AND mail <> ''")->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $out[mb_strtolower(trim((string) $m))] = true;
        }
        return $out;
    }

    /** @param list<int> $sections */
    public function creer(string $canal, string $cible, int $saisonId, array $sections, string $objet, string $texte, string $par): int
    {
        $d = $this->destinataires($canal, $cible, $saisonId, $sections);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO campagne (canal, cible, saison_id, sections, objet, texte, cree_le, par, statut) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$canal, $cible, $saisonId, json_encode(array_values(array_map('intval', $sections))), mb_substr($objet, 0, 255), $texte, date('Y-m-d H:i:s'), mb_substr($par, 0, 150), 'brouillon']);
        $id = (int) $this->pdo->lastInsertId();
        $ins = $this->pdo->prepare('INSERT INTO campagne_destinataire (campagne_id, adherent_id, adresse, prenom, nom, statut) VALUES (?,?,?,?,?,?)');
        foreach ($d['retenus'] as $r) {
            $ins->execute([$id, $r['adherent_id'], $r['adresse'], $r['prenom'], $r['nom'], 'a_envoyer']);
        }
        $this->pdo->commit();
        return $id;
    }

    public function campagne(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT c.*,
                (SELECT COUNT(*) FROM campagne_destinataire d WHERE d.campagne_id = c.id) AS total,
                (SELECT COUNT(*) FROM campagne_destinataire d WHERE d.campagne_id = c.id AND d.statut = \'envoye\') AS envoyes,
                (SELECT COUNT(*) FROM campagne_destinataire d WHERE d.campagne_id = c.id AND d.statut = \'echec\') AS echecs,
                (SELECT COUNT(*) FROM campagne_destinataire d WHERE d.campagne_id = c.id AND d.statut = \'retire\') AS retires
            FROM campagne c WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function campagnes(int $limite = 50): array
    {
        $ids = $this->pdo->query('SELECT id FROM campagne ORDER BY id DESC LIMIT ' . (int) $limite)->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map(fn ($i) => $this->campagne((int) $i), $ids)));
    }

    /** @return list<array<string,mixed>> */
    public function destinatairesCampagne(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM campagne_destinataire WHERE campagne_id = ? ORDER BY nom, prenom');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** Retire ou remet un destinataire tant que la campagne n'est pas partie. */
    public function basculer(int $campagneId, int $destId): void
    {
        $this->pdo->prepare("UPDATE campagne_destinataire SET statut = CASE statut WHEN 'retire' THEN 'a_envoyer' WHEN 'a_envoyer' THEN 'retire' ELSE statut END
            WHERE id = ? AND campagne_id = ? AND campagne_id IN (SELECT id FROM campagne WHERE statut = 'brouillon')")->execute([$destId, $campagneId]);
    }

    public function modifier(int $id, string $objet, string $texte): void
    {
        $this->pdo->prepare("UPDATE campagne SET objet = ?, texte = ? WHERE id = ? AND statut = 'brouillon'")->execute([mb_substr($objet, 0, 255), $texte, $id]);
    }

    public function valider(int $id, string $par): void
    {
        $this->pdo->prepare("UPDATE campagne SET statut = 'validee', valide_le = ?, valide_par = ? WHERE id = ? AND statut = 'brouillon'")
            ->execute([date('Y-m-d H:i:s'), mb_substr($par, 0, 150), $id]);
    }

    public function supprimer(int $id): bool
    {
        $c = $this->campagne($id);
        if (!$c || $c['statut'] !== 'brouillon') {
            return false;
        }
        $this->pdo->prepare('DELETE FROM campagne_destinataire WHERE campagne_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM campagne WHERE id = ?')->execute([$id]);
        return true;
    }

    /** Texte personnalisé : {prenom} et {nom} remplacés ; pour un mail, lien de désinscription ajouté. */
    public static function personnaliser(string $texte, array $d, string $canal): string
    {
        $t = strtr($texte, ['{prenom}' => (string) $d['prenom'], '{nom}' => (string) $d['nom']]);
        if ($canal === 'mail') {
            $t .= "\n\n--\nPour ne plus recevoir les mails d'information de l'association : " . App::urlAbsolue('/desinscription/' . self::jeton((int) $d['adherent_id']));
        }
        return $t;
    }

    /** Envoie le lot suivant des campagnes validées. @return array{envoyes:int,echecs:int,restants:int} */
    public function envoyerLot(?int $campagneId = null): array
    {
        $r = ['envoyes' => 0, 'echecs' => 0, 'restants' => 0];
        // Un seul envoi à la fois : la tâche automatique et le bouton « Envoyer le lot » ne doivent pas servir deux fois le même destinataire.
        $verrou = @fopen(dirname(__DIR__, 2) . '/storage/envois-lot.lock', 'c');
        if ($verrou && !flock($verrou, LOCK_EX | LOCK_NB)) {
            $r['occupe'] = true;
            return $r;
        }
        $where = "c.statut IN ('validee','en_cours')" . ($campagneId ? ' AND c.id = ' . (int) $campagneId : '');
        $reg = (new Reglages($this->pdo))->tous();
        $envoi = new Envoi($this->pdo, 'mailing');
        $sms = Sms::depuisConfig();
        foreach ($this->pdo->query("SELECT c.* FROM campagne c WHERE $where ORDER BY c.id")->fetchAll() as $c) {
            if ($c['canal'] === 'sms' && !$sms) {
                continue; // sans passerelle : les SMS partent depuis le téléphone de l'association (page de l'envoi)
            }
            $lot = $c['canal'] === 'sms' ? self::LOT_SMS : self::LOT_MAIL;
            $st = $this->pdo->prepare("SELECT * FROM campagne_destinataire WHERE campagne_id = ? AND statut = 'a_envoyer' ORDER BY id LIMIT $lot");
            $st->execute([$c['id']]);
            $this->pdo->prepare("UPDATE campagne SET statut = 'en_cours' WHERE id = ?")->execute([$c['id']]);
            $prendre = $this->pdo->prepare("UPDATE campagne_destinataire SET statut = 'en_envoi' WHERE id = ? AND statut = 'a_envoyer'");
            foreach ($st->fetchAll() as $d) {
                // Réservé avant l'envoi : si le traitement est coupé en route, le destinataire n'est pas servi une seconde fois.
                $prendre->execute([$d['id']]);
                if ($prendre->rowCount() !== 1) {
                    continue;
                }
                $texte = self::personnaliser((string) $c['texte'], $d, (string) $c['canal']);
                $erreur = $c['canal'] === 'sms'
                    ? ($sms ? $sms->envoyer((string) $d['adresse'], $texte) : 'Passerelle SMS non configurée.')
                    : $envoi->envoyer($reg['expediteur'], $reg['association'] ?: 'ASCS', (string) $d['adresse'], (string) $c['objet'], $texte, [], null, 'mailing ' . $c['id']);
                $this->pdo->prepare('UPDATE campagne_destinataire SET statut = ?, erreur = ?, envoye_le = ? WHERE id = ?')
                    ->execute([$erreur ? 'echec' : 'envoye', $erreur, date('Y-m-d H:i:s'), $d['id']]);
                $erreur ? $r['echecs']++ : $r['envoyes']++;
                if ($erreur && $c['canal'] === 'mail' && (str_contains($erreur, 'Adresse d\'envoi') || str_contains($erreur, 'identifiant ou mot de passe') || str_contains($erreur, 'connexion impossible'))) {
                    break 2; // réglage manquant : inutile d'insister
                }
            }
            $reste = $this->pdo->prepare("SELECT COUNT(*) FROM campagne_destinataire WHERE campagne_id = ? AND statut = 'a_envoyer'");
            $reste->execute([$c['id']]);
            $n = (int) $reste->fetchColumn();
            $r['restants'] += $n;
            if ($n === 0) {
                $this->pdo->prepare("UPDATE campagne SET statut = 'terminee', termine_le = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $c['id']]);
            }
            if ($campagneId === null) {
                break; // un lot par passage (limite horaire du serveur mail)
            }
        }
        return $r;
    }

    /**
     * SMS envoyés à la main depuis le téléphone (application Messages ouverte par l'application) : marqués envoyés.
     * @param list<int> $ids
     */
    public function marquerEnvoyes(int $campagneId, array $ids): int
    {
        $n = 0;
        $st = $this->pdo->prepare("UPDATE campagne_destinataire SET statut = 'envoye', envoye_le = ?, erreur = 'depuis le téléphone' WHERE id = ? AND campagne_id = ? AND statut = 'a_envoyer'");
        foreach ($ids as $id) {
            $st->execute([date('Y-m-d H:i:s'), (int) $id, $campagneId]);
            $n += $st->rowCount();
        }
        $this->pdo->prepare("UPDATE campagne SET statut = 'en_cours' WHERE id = ? AND statut = 'validee'")->execute([$campagneId]);
        $reste = $this->pdo->prepare("SELECT COUNT(*) FROM campagne_destinataire WHERE campagne_id = ? AND statut = 'a_envoyer'");
        $reste->execute([$campagneId]);
        if ((int) $reste->fetchColumn() === 0) {
            $this->pdo->prepare("UPDATE campagne SET statut = 'terminee', termine_le = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $campagneId]);
        }
        return $n;
    }

    /** Adresses ou numéros de test enregistrés (Paramétrage), un par ligne ou séparés par des virgules. @return list<string> */
    public function destinatairesTest(string $canal): array
    {
        $brut = (string) (Parametres::get($this->pdo, $canal === 'sms' ? 'test_tels' : 'test_mails') ?? '');
        return array_values(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $brut))));
    }

    /**
     * Envoi de test : le message de la campagne, préfixé [TEST], personnalisé avec le premier destinataire,
     * vers les adresses ou numéros donnés (enregistrés pour la prochaine fois). Ne touche pas aux destinataires de la campagne.
     * @return array{envoyes:list<string>,erreurs:list<string>,liens:list<string>} liens : SMS à ouvrir dans Messages (sans passerelle)
     */
    public function envoyerTest(int $campagneId, string $saisie, string $par): array
    {
        $c = $this->campagne($campagneId);
        $r = ['envoyes' => [], 'erreurs' => [], 'liens' => []];
        if (!$c) {
            return $r;
        }
        $canal = (string) $c['canal'];
        $cibles = [];
        foreach (array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $saisie))) as $x) {
            $ok = $canal === 'sms' ? Sms::mobile($x) : (filter_var($x, FILTER_VALIDATE_EMAIL) ? mb_strtolower($x) : null);
            $ok === null ? $r['erreurs'][] = $x . ' : ' . ($canal === 'sms' ? 'pas un numéro de portable' : 'adresse invalide') : $cibles[$ok] = true;
        }
        $cibles = array_slice(array_keys($cibles), 0, 5);
        Parametres::set($this->pdo, $canal === 'sms' ? 'test_tels' : 'test_mails', implode(', ', $cibles));
        $st = $this->pdo->prepare("SELECT * FROM campagne_destinataire WHERE campagne_id = ? AND statut <> 'retire' ORDER BY nom, prenom LIMIT 1");
        $st->execute([$campagneId]);
        $modele = $st->fetch() ?: ['prenom' => 'Prénom', 'nom' => 'NOM', 'adherent_id' => 0];
        $texte = self::personnaliser((string) $c['texte'], $modele, $canal);
        if ($canal === 'sms') {
            $sms = Sms::depuisConfig();
            foreach ($cibles as $num) {
                if ($sms) {
                    $e = $sms->envoyer($num, '[TEST] ' . $texte);
                    $e ? $r['erreurs'][] = $num . ' : ' . $e : $r['envoyes'][] = $num;
                } else {
                    $r['liens'][] = 'sms:' . $num . '?&body=' . rawurlencode('[TEST] ' . $texte);
                    $r['envoyes'][] = $num;
                }
            }
            return $r;
        }
        $reg = (new Reglages($this->pdo))->tous();
        $envoi = new Envoi($this->pdo, 'mailing');
        foreach ($cibles as $a) {
            $e = $envoi->envoyer($reg['expediteur'], $reg['association'] ?: 'ASCS', $a, '[TEST] ' . $c['objet'], $texte, [], null, 'test mailing ' . $campagneId . ' (' . $par . ')');
            $e ? $r['erreurs'][] = $a . ' : ' . $e : $r['envoyes'][] = $a;
        }
        return $r;
    }

    /** Jeton de désinscription : signature de l'identifiant, sans rien stocker. */
    public static function jeton(int $adherentId): string
    {
        return $adherentId . '-' . substr(hash_hmac('sha256', 'desinscription:' . $adherentId, self::secret()), 0, 24);
    }

    public static function verifierJeton(string $jeton): ?int
    {
        if (!preg_match('/^(\d+)-([0-9a-f]{24})$/', $jeton, $m)) {
            return null;
        }
        return hash_equals(self::jeton((int) $m[1]), $jeton) ? (int) $m[1] : null;
    }

    private static function secret(): string
    {
        static $s = null;
        if ($s === null) {
            $pdo = \Ascs\Db::connect();
            $s = Parametres::get($pdo, 'secret_liens');
            if (!$s) {
                $s = bin2hex(random_bytes(32));
                Parametres::set($pdo, 'secret_liens', $s);
            }
        }
        return $s;
    }

    public function desinscrire(int $adherentId): bool
    {
        return (new Marques($this->pdo))->poser($adherentId, 'refus_mail', 1, 'lien de désinscription');
    }
}
