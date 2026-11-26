<?php
declare(strict_types=1);

namespace Ascs\HelloAsso;

use Ascs\Config;
use Ascs\Domain\Bulletin;
use Ascs\Parametres;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Paiement en ligne par HelloAsso Checkout à la fin d'une inscription en ligne.
 *
 *  1. La personne a choisi « Carte bancaire » comme seul moyen de paiement : la page de confirmation
 *     (et le mail) proposent de payer tout de suite le reste à régler, en 1 fois ou en 2–3 fois
 *     (échéances mensuelles, la dernière au plus tard le 31/12).
 *  2. L'application ouvre une intention de paiement HelloAsso et y envoie la personne.
 *  3. Au retour (puis par la notification HelloAsso et la tâche automatique), la commande est relue sur l'API.
 *  4. Les paiements deviennent des règlements « HelloAsso » dès que le bureau a validé la demande
 *     (avant, il n'y a pas encore d'inscription) ; partagés entre les activités au prorata de leur montant.
 *     Échéances futures : règlements à venir, retirés si le prélèvement est refusé.
 */
final class PaiementEnLigne
{
    public const PREFIXE = 'HelloAsso paiement en ligne';
    private const PREVU = ' (prélèvement prévu)';
    /** Paiement en 3 fois proposé à partir de ce montant. */
    public const SEUIL_3_FOIS = 60.0;

    private ?Client $client = null;
    private array $compte = ['verifies' => 0, 'payes' => 0, 'reglements' => 0, 'retires' => 0, 'abandons' => 0];

    public function __construct(private readonly PDO $pdo, ?Client $client = null)
    {
        $this->client = $client;
    }

    public function compteur(): array
    {
        return $this->compte;
    }

    private function client(): Client
    {
        return $this->client ??= Client::depuisConfig($this->pdo, Config::load());
    }

    // ------------------------------------------------------------------ réglages

    public function actif(): bool
    {
        if (Parametres::get($this->pdo, 'paiement_en_ligne') !== '1') {
            return false;
        }
        $c = Config::load();
        return $c['ha_client_id'] !== '' && $c['ha_client_secret'] !== '' && $c['ha_organisation'] !== '';
    }

    public function troisFoisPermis(): bool
    {
        return Parametres::get($this->pdo, 'paiement_en_ligne_3fois') !== '0';
    }

    /** Dernière échéance possible d'un paiement en plusieurs fois : 31/12 de l'année où commence la saison de la demande. */
    public function dateLimite(array $d): DateTimeImmutable
    {
        $st = $this->pdo->prepare('SELECT date_debut FROM saison WHERE id = ?');
        $st->execute([(int) ($d['saison_id'] ?? 0)]);
        $debut = $st->fetchColumn();
        $annee = $debut ? (int) substr((string) $debut, 0, 4) : (int) date('Y');
        return new DateTimeImmutable($annee . '-12-31 23:59:59');
    }

    /**
     * Nombre d'échéances possible (1 à 3) : mensuelles, la dernière au plus tard le 31/12 (dateLimite).
     * Inscription en novembre : 2 fois ; en décembre ou après le 31/12 : 1 fois.
     */
    public function foisPossible(array $d, ?DateTimeImmutable $aujourdhui = null): int
    {
        $montant = self::montantDu($d);
        if ($montant === null || !$this->troisFoisPermis() || $montant < self::SEUIL_3_FOIS) {
            return 1;
        }
        $aujourdhui ??= new DateTimeImmutable('today');
        $limite = $this->dateLimite($d);
        $n = 1;
        foreach (self::echeancier(300, 3, $aujourdhui) as $t) {
            if (new DateTimeImmutable($t['date']) <= $limite) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ lien signé

    /** Jeton du lien de paiement : référence de la demande + signature (rien à stocker). */
    public static function jeton(string $reference): string
    {
        return $reference . '-' . substr(hash_hmac('sha256', 'paiement:' . $reference, self::secret()), 0, 20);
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

    /** Demande désignée par un jeton valide, avec son bulletin (complement). */
    public function demandeDuJeton(string $jeton): ?array
    {
        if (!preg_match('/^([A-Za-z0-9]{1,12})-([0-9a-f]{20})$/', $jeton, $m) || !hash_equals(self::jeton($m[1]), $jeton)) {
            return null;
        }
        return $this->demande($m[1]);
    }

    public function demande(string $reference): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM demande WHERE reference = ?');
        $st->execute([$reference]);
        $d = $st->fetch();
        if (!$d) {
            return null;
        }
        $d['complement'] = (new Bulletin($this->pdo))->lire((int) $d['id']);
        return $d;
    }

    /**
     * Montant à payer en ligne : reste à régler annoncé, seulement si la carte bancaire est le seul moyen choisi
     * (les aides sont déjà déduites). null = pas de paiement en ligne pour cette demande.
     */
    public static function montantDu(array $d): ?float
    {
        $c = $d['complement'] ?? null;
        if (($d['type'] ?? '') !== 'inscription' || ($d['statut'] ?? '') === 'refuse' || !is_array($c)) {
            return null;
        }
        if (($c['moyens'] ?? []) !== ['cb']) {
            return null;
        }
        $reste = round((float) ($c['recap']['reste'] ?? 0), 2);
        return $reste > 0 ? $reste : null;
    }

    /**
     * Garanties par carte des aides demandées (coupon mairie…) : montant de l'aide programmé en dernière échéance
     * à la date réglée (Paramétrage > En ligne), annulé quand l'aide est confirmée.
     * Seulement pour un paiement par carte, et si la date est encore à venir.
     * @return list<array{aide:string,libelle:string,montant:float,date:string}>
     */
    public function garanties(array $d, ?string $aujourdhui = null): array
    {
        if (self::montantDu($d) === null) {
            return [];
        }
        $auj = $aujourdhui ?? date('Y-m-d');
        $b = new Bulletin($this->pdo);
        $out = [];
        foreach (Bulletin::aides() as $a) {
            if (!in_array($a['cle'], (array) ($d['complement']['aides'] ?? []), true)) {
                continue;
            }
            $date = $b->garantieAide($a['cle']);
            if ($date !== null && $date > $auj && (float) $a['montant'] > 0) {
                $out[] = ['aide' => $a['cle'], 'libelle' => $a['libelle'], 'montant' => (float) $a['montant'], 'date' => $date];
            }
        }
        return $out;
    }

    /** Paiement déjà fait pour cette demande (le plus récent), sinon null. */
    public function paye(int $demandeId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM paiement_en_ligne WHERE demande_id = ? AND statut = 'paye' ORDER BY id DESC LIMIT 1");
        $st->execute([$demandeId]);
        return $st->fetch() ?: null;
    }

    /** Toutes les tentatives d'une demande, avec leurs échéances (fiche de la demande). */
    public function historique(int $demandeId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM paiement_en_ligne WHERE demande_id = ? ORDER BY id');
        $st->execute([$demandeId]);
        $out = [];
        $ech = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_echeance WHERE paiement_id = ? ORDER BY date_paiement, id');
        foreach ($st->fetchAll() as $p) {
            $ech->execute([(int) $p['id']]);
            $p['echeances'] = $ech->fetchAll();
            $out[] = $p;
        }
        return $out;
    }

    // ------------------------------------------------------------------ ouverture du paiement

    /** @return list<array{amount:int,date:string}> */
    public static function echeancier(int $centimes, int $fois, ?DateTimeImmutable $depuis = null): array
    {
        if ($fois <= 1) {
            return [];
        }
        $depuis ??= new DateTimeImmutable('today');
        $part = intdiv($centimes, $fois);
        $terms = [];
        for ($i = 1; $i < $fois; $i++) {
            $terms[] = ['amount' => $part, 'date' => $depuis->modify("+$i month")->format('Y-m-d\T12:00:00P')];
        }
        return $terms;
    }

    /**
     * Ouvre une intention de paiement HelloAsso et rend l'adresse de la page de paiement (valable 15 minutes).
     * $urls : back, retour, erreur (adresses absolues de l'application).
     */
    public function ouvrir(array $d, int $fois, array $urls): string
    {
        $montant = self::montantDu($d);
        if ($montant === null) {
            throw new RuntimeException('Pas de paiement en ligne prévu pour cette inscription.');
        }
        if ($this->paye((int) $d['id'])) {
            throw new RuntimeException('Cette inscription est déjà réglée en ligne.');
        }
        // En plusieurs fois : la dernière échéance ne dépasse pas le 31/12 (sinon moins d'échéances)
        $fois = max(1, min($fois, $this->foisPossible($d)));
        $centimes = (int) round($montant * 100);
        $terms = self::echeancier($centimes, $fois);
        $initial = $centimes - array_sum(array_column($terms, 'amount'));
        // Garanties des aides : échéances supplémentaires (une par date), après les échéances du reste à régler
        $garanties = $this->garanties($d);
        $parDate = [];
        foreach ($garanties as $g) {
            $parDate[$g['date']] = ($parDate[$g['date']] ?? 0) + (int) round($g['montant'] * 100);
        }
        ksort($parDate);
        foreach ($parDate as $date => $c) {
            $terms[] = ['amount' => $c, 'date' => (new DateTimeImmutable($date))->format('Y-m-d\T12:00:00P')];
        }
        $total = $centimes + array_sum($parDate);

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("INSERT INTO paiement_en_ligne (demande_id, fois, montant, statut, cree_le, maj_le) VALUES (?,?,?,'cree',?,?)")
            ->execute([(int) $d['id'], $fois, number_format($montant, 2, '.', ''), $now, $now]);
        $pid = (int) $this->pdo->lastInsertId();
        foreach ($garanties as $g) {
            $this->pdo->prepare("INSERT INTO paiement_en_ligne_garantie (paiement_id, aide, libelle, montant, date_prevue, statut, maj_le) VALUES (?,?,?,?,?,'prevue',?)")
                ->execute([$pid, $g['aide'], $g['libelle'], number_format($g['montant'], 2, '.', ''), $g['date'], $now]);
        }

        $titres = $this->titresSections($d);
        $saison = (string) $this->pdo->query('SELECT titre FROM saison WHERE id = ' . (int) $d['saison_id'])->fetchColumn();
        $corps = [
            'totalAmount' => $total, 'initialAmount' => $initial,
            'itemName' => mb_substr('Inscription ' . trim($d['prenom'] . ' ' . $d['nom']) . ($saison ? ' ' . $saison : '') . ($titres ? ' – ' . implode(', ', $titres) : ''), 0, 250),
            'backUrl' => $urls['back'], 'errorUrl' => $urls['erreur'], 'returnUrl' => $urls['retour'],
            'containsDonation' => false,
            'metadata' => ['demande' => (string) $d['reference'], 'paiement' => $pid],
        ];
        if ($terms) {
            $corps['terms'] = $terms;
        }
        $payeur = self::payeur($d);
        try {
            [$code, $r] = $this->client()->creerCheckout($payeur ? $corps + ['payer' => $payeur] : $corps);
            if ($code === 400 && $payeur) {
                // HelloAsso refuse parfois un nom ou un mail pré-rempli : la personne les saisira elle-même.
                [$code, $r] = $this->client()->creerCheckout($corps);
            }
            if ($code < 200 || $code >= 300 || empty($r['id']) || empty($r['redirectUrl'])) {
                throw new RuntimeException("HelloAsso n'a pas ouvert le paiement (HTTP $code)" . self::detailErreur($r) . '.');
            }
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE paiement_en_ligne SET statut = 'echec', erreur = ?, maj_le = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 2000), date('Y-m-d H:i:s'), $pid]);
            throw $e;
        }
        $this->pdo->prepare('UPDATE paiement_en_ligne SET checkout_id = ?, maj_le = ? WHERE id = ?')->execute([(int) $r['id'], date('Y-m-d H:i:s'), $pid]);
        return (string) $r['redirectUrl'];
    }

    private static function detailErreur(array $r): string
    {
        $m = [];
        foreach ((array) ($r['errors'] ?? []) as $e) {
            $m[] = is_array($e) ? (string) ($e['message'] ?? '') : (string) $e;
        }
        $m = array_filter($m);
        return $m ? ' : ' . mb_substr(implode(' ; ', $m), 0, 300) : '';
    }

    /** Payeur pré-rempli : le parent s'il est indiqué, sinon la personne inscrite. */
    private static function payeur(array $d): ?array
    {
        $parent = trim((string) $d['nom_parent']) !== '' && trim((string) $d['prenom_parent']) !== '';
        $p = array_filter([
            'firstName' => trim((string) ($parent ? $d['prenom_parent'] : $d['prenom'])),
            'lastName' => trim((string) ($parent ? $d['nom_parent'] : $d['nom'])),
            'email' => filter_var((string) $d['mail'], FILTER_VALIDATE_EMAIL) ? (string) $d['mail'] : '',
        ], static fn ($v) => $v !== '');
        return $p ?: null;
    }

    /** @return list<string> */
    private function titresSections(array $d): array
    {
        $ids = array_map('intval', json_decode((string) $d['sections'], true) ?: []);
        if (!$ids) {
            return [];
        }
        return array_map(static fn ($t) => (string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $t),
            $this->pdo->query('SELECT titre FROM section WHERE id IN (' . implode(',', $ids) . ') ORDER BY titre')->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------ retour et suivi

    /**
     * Relit sur HelloAsso une intention de paiement de la demande. Rend le statut obtenu (cree | paye | echec).
     * $checkoutId = identifiant renvoyé dans l'adresse de retour ; null = toutes les intentions en attente de la demande.
     */
    public function verifierDemande(array $d, ?int $checkoutId = null): string
    {
        $sql = "SELECT * FROM paiement_en_ligne WHERE demande_id = ? AND checkout_id IS NOT NULL AND statut = 'cree'" . ($checkoutId ? ' AND checkout_id = ?' : '') . ' ORDER BY id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($checkoutId ? [(int) $d['id'], $checkoutId] : [(int) $d['id']]);
        foreach ($st->fetchAll() as $p) {
            $this->verifier($p);
        }
        return $this->paye((int) $d['id']) ? 'paye' : 'cree';
    }

    /** Une intention : commande présente = paiement autorisé. */
    private function verifier(array $p): void
    {
        $this->compte['verifies']++;
        $ci = $this->client()->checkout((int) $p['checkout_id']);
        $order = $ci['order'] ?? null;
        if (!is_array($order) || empty($order['id'])) {
            // Plus de 2 jours sans paiement : l'adresse de paiement n'est plus valable, la tentative est abandonnée.
            if (strtotime((string) $p['cree_le']) < time() - 2 * 86400) {
                $this->pdo->prepare("UPDATE paiement_en_ligne SET statut = 'echec', erreur = ?, maj_le = ? WHERE id = ? AND statut = 'cree'")
                    ->execute(['paiement non terminé', date('Y-m-d H:i:s'), (int) $p['id']]);
                $this->compte['abandons']++;
            }
            return;
        }
        $this->enregistrerCommande((int) $p['id'], $order);
    }

    /** Enregistre la commande et ses paiements, puis crée les règlements si la demande est validée. */
    public function enregistrerCommande(int $paiementId, array $order): void
    {
        $now = date('Y-m-d H:i:s');
        $up = \Ascs\Db::upsertStatement($this->pdo, 'paiement_en_ligne_echeance',
            ['id', 'paiement_id', 'montant', 'date_paiement', 'etat', 'echeance', 'maj_le'], ['id']);
        $payes = [];
        foreach ((array) ($order['payments'] ?? []) as $pay) {
            if (!isset($pay['id'])) {
                continue;
            }
            $etat = (string) ($pay['state'] ?? 'Unknown');
            $date = Sync::dateLocale($pay['date'] ?? null);
            $up->execute([(int) $pay['id'], $paiementId, Sync::euros($pay['amount'] ?? 0), $date, $etat,
                isset($pay['installmentNumber']) ? (int) $pay['installmentNumber'] : null, $now]);
            if (in_array($etat, Sync::PAYE, true)) {
                $payes[] = $date ?? $now;
            }
        }
        $this->rattacherGaranties($paiementId);
        $st = $this->pdo->prepare('SELECT statut, demande_id FROM paiement_en_ligne WHERE id = ?');
        $st->execute([$paiementId]);
        $p = $st->fetch();
        if (!$p) {
            return;
        }
        if ($payes && $p['statut'] !== 'paye') {
            sort($payes);
            $this->pdo->prepare("UPDATE paiement_en_ligne SET statut = 'paye', commande_id = ?, paye_le = ?, erreur = NULL, maj_le = ? WHERE id = ?")
                ->execute([(int) $order['id'], $payes[0], $now, $paiementId]);
            $this->compte['payes']++;
        } else {
            $this->pdo->prepare('UPDATE paiement_en_ligne SET commande_id = ?, maj_le = ? WHERE id = ?')->execute([(int) $order['id'], $now, $paiementId]);
        }
        $this->creerReglements((int) $p['demande_id']);
    }

    /**
     * Tâche automatique et notification HelloAsso :
     *  - intentions ouvertes depuis moins de 3 jours et pas encore payées (la personne a pu fermer la page avant le retour) ;
     *  - commandes payées avec des échéances encore prévues (prélèvement fait ou refusé).
     */
    public function suivre(): array
    {
        $limite = date('Y-m-d H:i:s', time() - 3 * 86400);
        $st = $this->pdo->prepare("SELECT * FROM paiement_en_ligne WHERE statut = 'cree' AND checkout_id IS NOT NULL AND cree_le >= ? AND cree_le <= ?");
        $st->execute([$limite, date('Y-m-d H:i:s', time() - 60)]);
        foreach ($st->fetchAll() as $p) {
            try {
                $this->verifier($p);
            } catch (\Throwable $e) {
                error_log('ASCS paiement en ligne ' . $p['id'] . ' : ' . $e->getMessage());
            }
        }
        $q = $this->pdo->query(
            "SELECT DISTINCT p.id, p.commande_id FROM paiement_en_ligne p JOIN paiement_en_ligne_echeance e ON e.paiement_id = p.id
             WHERE p.statut = 'paye' AND p.commande_id IS NOT NULL AND e.etat IN ('" . implode("','", Sync::A_VENIR) . "')"
        );
        foreach ($q->fetchAll() as $p) {
            try {
                $this->enregistrerCommande((int) $p['id'], $this->client()->commande((int) $p['commande_id']));
            } catch (\Throwable $e) {
                error_log('ASCS paiement en ligne, commande ' . $p['commande_id'] . ' : ' . $e->getMessage());
            }
        }
        $this->reglementsManquants();
        $this->tenterAnnulations();
        return $this->compte;
    }

    /** Règlements manquants de toutes les demandes validées payées en ligne (après un import WinDev, sans appel à HelloAsso). */
    public function reglementsManquants(): int
    {
        $n = 0;
        foreach ($this->pdo->query("SELECT DISTINCT p.demande_id FROM paiement_en_ligne p JOIN demande d ON d.id = p.demande_id WHERE p.statut = 'paye' AND d.statut = 'valide'")->fetchAll(PDO::FETCH_COLUMN) as $did) {
            $n += $this->creerReglements((int) $did);
        }
        return $n;
    }

    // ------------------------------------------------------------------ règlements

    /**
     * Règlements des paiements en ligne d'une demande validée (rejouable : ne crée que ce qui manque).
     * Chaque échéance est partagée entre les inscriptions créées, au prorata de leur montant.
     * @return int nombre de règlements créés
     */
    public function creerReglements(int $demandeId): int
    {
        $st = $this->pdo->prepare('SELECT * FROM demande WHERE id = ?');
        $st->execute([$demandeId]);
        $d = $st->fetch();
        if (!$d) {
            return 0;
        }
        $avant = $this->compte['reglements'];
        $inscriptions = [];
        if ($d['statut'] === 'valide') {
            $ids = array_map('intval', json_decode((string) $d['inscriptions'], true) ?: []);
            if ($ids) {
                foreach ($this->pdo->query('SELECT id, montant FROM inscription WHERE id IN (' . implode(',', $ids) . ') ORDER BY id') as $r) {
                    $inscriptions[(int) $r['id']] = max(0.0, (float) $r['montant']);
                }
            }
        }
        $ech = $this->pdo->prepare(
            "SELECT e.*, p.commande_id FROM paiement_en_ligne_echeance e JOIN paiement_en_ligne p ON p.id = e.paiement_id
             WHERE p.demande_id = ? AND p.statut = 'paye' ORDER BY e.date_paiement, e.id"
        );
        $ech->execute([$demandeId]);
        $liens = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_reglement WHERE echeance_id = ?');
        $ge = $this->pdo->prepare('SELECT g.echeance_id FROM paiement_en_ligne_garantie g JOIN paiement_en_ligne p ON p.id = g.paiement_id WHERE p.demande_id = ? AND g.echeance_id IS NOT NULL');
        $ge->execute([$demandeId]);
        $garantiesEch = array_flip(array_map('intval', $ge->fetchAll(PDO::FETCH_COLUMN)));
        // Partage cumulé : la somme des échéances retombe exactement sur le prorata du total (arrondis compensés)
        $cumul = 0.0;
        $avantParts = $inscriptions ? array_fill_keys(array_keys($inscriptions), 0.0) : [];
        foreach ($ech->fetchAll() as $e) {
            $liens->execute([(int) $e['id']]);
            $existants = [];
            foreach ($liens->fetchAll() as $l) {
                $existants[(int) $l['inscription_id']] = $l;
            }
            $paye = in_array($e['etat'], Sync::PAYE, true);
            $prevu = in_array($e['etat'], Sync::A_VENIR, true);
            // Échéance de garantie d'une aide : pas un règlement tant qu'elle n'est pas prélevée
            if (isset($garantiesEch[(int) $e['id']]) && !$paye) {
                $prevu = false;
            }
            $parts = [];
            if (($paye || $prevu) && $inscriptions) {
                $cumul += (float) $e['montant'];
                $total = self::repartir($cumul, $inscriptions);
                foreach ($total as $iid => $m) {
                    $parts[$iid] = round($m - $avantParts[$iid], 2);
                }
                $avantParts = $total;
            }
            // Liens devenus faux (échéance refusée, inscription disparue ou montants changés) : règlement retiré
            foreach ($existants as $iid => $l) {
                if (isset($parts[$iid]) && abs((float) $l['montant'] - $parts[$iid]) < 0.005) {
                    continue;
                }
                $this->retirer((int) $e['id'], $iid, $l['reglement_id'] !== null ? (int) $l['reglement_id'] : null);
                unset($existants[$iid]);
            }
            foreach ($parts as $iid => $montant) {
                $l = $existants[$iid] ?? null;
                $rid = $l && $l['reglement_id'] !== null && $this->reglementExiste((int) $l['reglement_id'], $iid) ? (int) $l['reglement_id'] : null;
                $remarque = self::PREFIXE . ', demande ' . $d['reference'] . (isset($garantiesEch[(int) $e['id']]) ? ' – garantie d\'aide prélevée (aide non confirmée)' : ($e['echeance'] && (int) $e['echeance'] > 0 ? ' – échéance ' . $e['echeance'] : '')) . ($prevu ? self::PREVU : '');
                $date = substr((string) ($e['date_paiement'] ?? date('Y-m-d')), 0, 10);
                if ($rid !== null) {
                    $this->pdo->prepare('UPDATE reglement SET remarques = ?, date_reglement = ?, date_modification = ? WHERE id = ? AND remarques LIKE ? AND (remarques <> ? OR date_reglement <> ?)')
                        ->execute([$remarque, $date, date('Y-m-d H:i:s'), $rid, self::PREFIXE . '%', $remarque, $date]);
                    continue;
                }
                if ($montant <= 0) {
                    continue;
                }
                $this->pdo->prepare('INSERT INTO reglement (inscription_id, mode_reglement_id, montant, date_reglement, identifiant, remarques, date_saisie) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$iid, Sync::MODE_HELLOASSO, number_format($montant, 2, '.', ''), $date, (string) $e['commande_id'], $remarque, date('Y-m-d H:i:s')]);
                $rid = (int) $this->pdo->lastInsertId();
                $this->compte['reglements']++;
                \Ascs\Db::upsertStatement($this->pdo, 'paiement_en_ligne_reglement', ['echeance_id', 'inscription_id', 'montant', 'reglement_id'], ['echeance_id', 'inscription_id'])
                    ->execute([(int) $e['id'], $iid, number_format($montant, 2, '.', ''), $rid]);
            }
        }
        return $this->compte['reglements'] - $avant;
    }

    private function retirer(int $echeanceId, int $inscriptionId, ?int $reglementId): void
    {
        if ($reglementId !== null) {
            // Seulement un règlement créé ici (le bureau a pu le modifier ou le remplacer : on n'y touche pas alors)
            $st = $this->pdo->prepare('SELECT remarques FROM reglement WHERE id = ?');
            $st->execute([$reglementId]);
            $rem = $st->fetchColumn();
            if ($rem !== false && str_starts_with((string) $rem, self::PREFIXE)) {
                $this->pdo->prepare('DELETE FROM reglement WHERE id = ?')->execute([$reglementId]);
                $this->compte['retires']++;
            }
        }
        $this->pdo->prepare('DELETE FROM paiement_en_ligne_reglement WHERE echeance_id = ? AND inscription_id = ?')->execute([$echeanceId, $inscriptionId]);
    }

    private function reglementExiste(int $rid, int $inscriptionId): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM reglement WHERE id = ? AND inscription_id = ?');
        $st->execute([$rid, $inscriptionId]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Montant encaissé (échéances payées, non remboursées) des paiements en ligne d'une demande. */
    public function encaisse(int $demandeId): float
    {
        $st = $this->pdo->prepare(
            "SELECT e.montant, e.etat FROM paiement_en_ligne_echeance e JOIN paiement_en_ligne p ON p.id = e.paiement_id
             WHERE p.demande_id = ? AND p.statut = 'paye'"
        );
        $st->execute([$demandeId]);
        $t = 0.0;
        foreach ($st->fetchAll() as $e) {
            if (in_array($e['etat'], Sync::PAYE, true)) {
                $t += (float) $e['montant'];
            }
        }
        return round($t, 2);
    }

    /**
     * Rembourse sur HelloAsso tous les paiements encaissés d'une demande non validée et annule les échéances futures.
     * Les règlements éventuellement créés sont retirés. @return array{rembourse:float,erreurs:list<string>}
     */
    public function rembourser(int $demandeId, string $par): array
    {
        $st = $this->pdo->prepare('SELECT reference, statut FROM demande WHERE id = ?');
        $st->execute([$demandeId]);
        $d = $st->fetch();
        if (!$d || $d['statut'] === 'valide') {
            return ['rembourse' => 0.0, 'erreurs' => ['Remboursement possible seulement pour une demande à traiter ou refusée.']];
        }
        $rembourse = 0.0;
        $erreurs = [];
        $ps = $this->pdo->prepare("SELECT * FROM paiement_en_ligne WHERE demande_id = ? AND statut = 'paye' AND commande_id IS NOT NULL");
        $ps->execute([$demandeId]);
        foreach ($ps->fetchAll() as $p) {
            $es = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_echeance WHERE paiement_id = ? ORDER BY date_paiement, id');
            $es->execute([(int) $p['id']]);
            $payees = array_values(array_filter($es->fetchAll(), static fn ($e) => in_array($e['etat'], Sync::PAYE, true)));
            $ok = true;
            foreach ($payees as $k => $e) {
                try {
                    // Le dernier remboursement annule aussi la commande (échéances futures)
                    [$code, $r] = $this->client()->rembourser((int) $e['id'], 'Inscription non retenue - ASCS, demande ' . $d['reference'], $k === count($payees) - 1);
                } catch (\Throwable $ex) {
                    $code = 0;
                    $r = ['errors' => [['message' => $ex->getMessage()]]];
                }
                $detail = $code >= 200 && $code < 300 ? 'ok' : "HTTP $code" . self::detailErreur($r);
                \Ascs\Db::upsertStatement($this->pdo, 'paiement_en_ligne_remboursement', ['echeance_id', 'paiement_id', 'montant', 'le', 'par', 'resultat'], ['echeance_id'])
                    ->execute([(int) $e['id'], (int) $p['id'], $e['montant'], date('Y-m-d H:i:s'), mb_substr($par, 0, 150), mb_substr($detail, 0, 2000)]);
                if ($detail === 'ok') {
                    $rembourse += (float) $e['montant'];
                } else {
                    $ok = false;
                    $erreurs[] = 'Échéance de ' . number_format((float) $e['montant'], 2, ',', ' ') . ' € : ' . ($code === 0 ? ltrim(self::detailErreur($r), ' :') : $detail);
                }
            }
            if (!$payees) {
                // Rien d'encaissé : seulement annuler les échéances à venir
                try {
                    [$code, $r] = $this->client()->annulerCommande((int) $p['commande_id']);
                    if ($code < 200 || $code >= 300) {
                        $ok = false;
                        $erreurs[] = "Annulation de la commande : HTTP $code" . self::detailErreur($r);
                    }
                } catch (\Throwable $ex) {
                    $ok = false;
                    $erreurs[] = 'Annulation de la commande : ' . $ex->getMessage();
                }
            }
            if ($ok) {
                $this->pdo->prepare("UPDATE paiement_en_ligne SET statut = 'rembourse', erreur = ?, maj_le = ? WHERE id = ?")
                    ->execute(['remboursé le ' . date('d/m/Y H:i') . ' par ' . $par, date('Y-m-d H:i:s'), (int) $p['id']]);
                $this->pdo->prepare("UPDATE paiement_en_ligne_garantie SET statut = 'annulee', note = 'paiement remboursé', maj_le = ? WHERE paiement_id = ? AND statut IN ('prevue','a_annuler')")
                    ->execute([date('Y-m-d H:i:s'), (int) $p['id']]);
                // Règlements créés à partir de ce paiement (s'il y en a) retirés
                $ls = $this->pdo->prepare('SELECT l.echeance_id, l.inscription_id, l.reglement_id FROM paiement_en_ligne_reglement l JOIN paiement_en_ligne_echeance e ON e.id = l.echeance_id WHERE e.paiement_id = ?');
                $ls->execute([(int) $p['id']]);
                foreach ($ls->fetchAll() as $l) {
                    $this->retirer((int) $l['echeance_id'], (int) $l['inscription_id'], $l['reglement_id'] !== null ? (int) $l['reglement_id'] : null);
                }
            }
            // État des échéances relu (remboursé, annulé)
            try {
                $order = $this->client()->commande((int) $p['commande_id']);
                $up = $this->pdo->prepare('UPDATE paiement_en_ligne_echeance SET etat = ?, maj_le = ? WHERE id = ?');
                foreach ((array) ($order['payments'] ?? []) as $pay) {
                    if (isset($pay['id'], $pay['state'])) {
                        $up->execute([(string) $pay['state'], date('Y-m-d H:i:s'), (int) $pay['id']]);
                    }
                }
            } catch (\Throwable) {
            }
        }
        return ['rembourse' => round($rembourse, 2), 'erreurs' => $erreurs];
    }

    // ------------------------------------------------------------------ garanties des aides

    /** Relie chaque garantie à son échéance HelloAsso (même date, même montant) et note celles prélevées. */
    private function rattacherGaranties(int $paiementId): void
    {
        $gs = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_garantie WHERE paiement_id = ? ORDER BY id');
        $gs->execute([$paiementId]);
        $garanties = $gs->fetchAll();
        if (!$garanties) {
            return;
        }
        $es = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_echeance WHERE paiement_id = ?');
        $es->execute([$paiementId]);
        $echeances = $es->fetchAll();
        // Garanties d'une même date regroupées dans une seule échéance
        $parDate = [];
        foreach ($garanties as $g) {
            $parDate[(string) $g['date_prevue']][] = $g;
        }
        foreach ($parDate as $date => $groupe) {
            $somme = round(array_sum(array_map(static fn ($g) => (float) $g['montant'], $groupe)), 2);
            foreach ($echeances as $e) {
                if (substr((string) $e['date_paiement'], 0, 10) === $date && abs((float) $e['montant'] - $somme) < 0.005) {
                    foreach ($groupe as $g) {
                        $statut = (string) $g['statut'];
                        if (in_array($e['etat'], Sync::PAYE, true) && in_array($statut, ['prevue', 'a_annuler'], true)) {
                            $statut = 'prelevee';
                        } elseif (!in_array($e['etat'], Sync::PAYE, true) && !in_array($e['etat'], Sync::A_VENIR, true) && in_array($statut, ['prevue', 'a_annuler'], true)) {
                            $statut = 'annulee';
                        }
                        $this->pdo->prepare('UPDATE paiement_en_ligne_garantie SET echeance_id = ?, statut = ?, maj_le = ? WHERE id = ?')
                            ->execute([(int) $e['id'], $statut, date('Y-m-d H:i:s'), (int) $g['id']]);
                    }
                    break;
                }
            }
        }
    }

    /** Garanties à suivre (page Règlement > Garanties par carte, fiche de la demande). */
    public function listeGaranties(?int $demandeId = null, bool $toutes = false): array
    {
        $sql = 'SELECT g.*, p.demande_id, p.commande_id, d.reference, d.nom, d.prenom, d.mail, d.statut AS statut_demande
                FROM paiement_en_ligne_garantie g JOIN paiement_en_ligne p ON p.id = g.paiement_id JOIN demande d ON d.id = p.demande_id
                WHERE p.statut IN (\'paye\', \'rembourse\')' . ($demandeId ? ' AND p.demande_id = ' . (int) $demandeId : '')
            . ($toutes ? '' : " AND g.statut IN ('prevue','a_annuler','prelevee')") . ' ORDER BY g.date_prevue, d.nom';
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * L'aide est confirmée (la mairie a validé le coupon) : la garantie ne doit pas être prélevée.
     * HelloAsso n'annule que toutes les échéances futures d'une commande : si une échéance normale reste à venir,
     * l'annulation est programmée et faite par la tâche automatique dès qu'elle est passée.
     * @return string message pour le bureau
     */
    public function confirmerAide(int $garantieId, string $par): string
    {
        $st = $this->pdo->prepare('SELECT * FROM paiement_en_ligne_garantie WHERE id = ?');
        $st->execute([$garantieId]);
        $g = $st->fetch();
        if (!$g) {
            return 'Garantie introuvable.';
        }
        if ($g['statut'] === 'prelevee') {
            return 'La garantie a déjà été prélevée : remboursez-la avec le bouton « Rembourser ».';
        }
        if (!in_array($g['statut'], ['prevue', 'a_annuler'], true)) {
            return 'Rien à annuler.';
        }
        $this->pdo->prepare("UPDATE paiement_en_ligne_garantie SET statut = 'a_annuler', par = ?, maj_le = ? WHERE id = ?")
            ->execute([mb_substr($par, 0, 150), date('Y-m-d H:i:s'), $garantieId]);
        $r = $this->tenterAnnulations((int) $g['paiement_id']);
        $st->execute([$garantieId]);
        $g = $st->fetch();
        return match ($g['statut']) {
            'annulee' => 'Prélèvement de garantie annulé sur HelloAsso.',
            'a_annuler' => $r !== '' ? 'Annulation programmée : ' . $r : 'Annulation programmée : une autre échéance reste à venir sur ce paiement ; le prélèvement de garantie sera annulé automatiquement dès qu\'elle sera passée, avant le ' . date('d/m/Y', strtotime((string) $g['date_prevue'])) . '.',
            default => 'État : ' . $g['statut'] . '.',
        };
    }

    /**
     * Annule sur HelloAsso les commandes dont toutes les échéances à venir sont des garanties à annuler.
     * @return string dernière erreur HelloAsso (vide si aucune)
     */
    public function tenterAnnulations(?int $paiementId = null): string
    {
        $erreur = '';
        $sql = "SELECT DISTINCT p.id, p.commande_id FROM paiement_en_ligne_garantie g JOIN paiement_en_ligne p ON p.id = g.paiement_id
                WHERE g.statut = 'a_annuler' AND p.statut = 'paye' AND p.commande_id IS NOT NULL" . ($paiementId ? ' AND p.id = ' . $paiementId : '');
        foreach ($this->pdo->query($sql)->fetchAll() as $p) {
            try {
                // État à jour des échéances
                $this->enregistrerCommande((int) $p['id'], $this->client()->commande((int) $p['commande_id']));
            } catch (\Throwable $e) {
                $erreur = $e->getMessage();
                continue;
            }
            $es = $this->pdo->prepare('SELECT e.id, e.etat, g.statut AS garantie FROM paiement_en_ligne_echeance e
                LEFT JOIN paiement_en_ligne_garantie g ON g.echeance_id = e.id WHERE e.paiement_id = ?');
            $es->execute([(int) $p['id']]);
            $bloque = false;
            $aAnnuler = false;
            foreach ($es->fetchAll() as $e) {
                if (!in_array($e['etat'], Sync::A_VENIR, true)) {
                    continue;
                }
                if ($e['garantie'] === 'a_annuler') {
                    $aAnnuler = true;
                } else {
                    $bloque = true; // échéance normale (ou garantie d'une autre aide non confirmée) encore à venir
                }
            }
            if (!$aAnnuler || $bloque) {
                continue;
            }
            try {
                [$code, $r] = $this->client()->annulerCommande((int) $p['commande_id']);
            } catch (\Throwable $e) {
                $erreur = $e->getMessage();
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $erreur = "HelloAsso a refusé l'annulation (HTTP $code)" . self::detailErreur($r);
                continue;
            }
            $this->pdo->prepare("UPDATE paiement_en_ligne_garantie SET statut = 'annulee', note = ?, maj_le = ? WHERE paiement_id = ? AND statut = 'a_annuler'")
                ->execute(['annulée le ' . date('d/m/Y'), date('Y-m-d H:i:s'), (int) $p['id']]);
            try {
                $this->enregistrerCommande((int) $p['id'], $this->client()->commande((int) $p['commande_id']));
            } catch (\Throwable) {
            }
        }
        return $erreur;
    }

    /** Garantie prélevée alors que l'aide est confirmée : remboursement de cette échéance seulement. */
    public function rembourserGarantie(int $garantieId, string $par): ?string
    {
        $st = $this->pdo->prepare('SELECT g.*, p.demande_id FROM paiement_en_ligne_garantie g JOIN paiement_en_ligne p ON p.id = g.paiement_id WHERE g.id = ?');
        $st->execute([$garantieId]);
        $g = $st->fetch();
        if (!$g || $g['statut'] !== 'prelevee' || !$g['echeance_id']) {
            return 'Cette garantie n\'a pas été prélevée.';
        }
        // Plusieurs aides dans la même échéance : remboursement de l'échéance entière seulement si toutes sont confirmées
        $autres = $this->pdo->prepare("SELECT COUNT(*) FROM paiement_en_ligne_garantie WHERE echeance_id = ? AND id <> ?");
        $autres->execute([(int) $g['echeance_id'], $garantieId]);
        if ((int) $autres->fetchColumn() > 0) {
            return 'Cette échéance regroupe plusieurs aides : remboursez-la depuis l\'espace HelloAsso.';
        }
        try {
            [$code, $r] = $this->client()->rembourser((int) $g['echeance_id'], 'Aide confirmée (' . $g['libelle'] . ') - ASCS', false);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if ($code < 200 || $code >= 300) {
            return "HelloAsso a refusé (HTTP $code)" . self::detailErreur($r);
        }
        $this->pdo->prepare("UPDATE paiement_en_ligne_garantie SET statut = 'remboursee', par = ?, note = ?, maj_le = ? WHERE id = ?")
            ->execute([mb_substr($par, 0, 150), 'remboursée le ' . date('d/m/Y'), date('Y-m-d H:i:s'), $garantieId]);
        $this->pdo->prepare("UPDATE paiement_en_ligne_echeance SET etat = 'Refunded', maj_le = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $g['echeance_id']]);
        $this->creerReglements((int) $g['demande_id']);
        return null;
    }

    /**
     * Partage d'un montant entre inscriptions au prorata de leur montant (à parts égales si tout est à 0),
     * au centime près : l'arrondi va à la dernière. @param array<int,float> $poids @return array<int,float>
     */
    public static function repartir(float $montant, array $poids): array
    {
        $total = array_sum($poids);
        if ($total <= 0) {
            $poids = array_fill_keys(array_keys($poids), 1.0);
            $total = (float) count($poids);
        }
        $cent = (int) round($montant * 100);
        $parts = [];
        $reste = $cent;
        $cles = array_keys($poids);
        foreach ($cles as $i => $k) {
            $c = $i === count($cles) - 1 ? $reste : (int) floor($cent * $poids[$k] / $total);
            $parts[$k] = $c / 100;
            $reste -= $c;
        }
        return $parts;
    }
}
