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

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("INSERT INTO paiement_en_ligne (demande_id, fois, montant, statut, cree_le, maj_le) VALUES (?,?,?,'cree',?,?)")
            ->execute([(int) $d['id'], $fois, number_format($montant, 2, '.', ''), $now, $now]);
        $pid = (int) $this->pdo->lastInsertId();

        $titres = $this->titresSections($d);
        $saison = (string) $this->pdo->query('SELECT titre FROM saison WHERE id = ' . (int) $d['saison_id'])->fetchColumn();
        $corps = [
            'totalAmount' => $centimes, 'initialAmount' => $initial,
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
                $remarque = self::PREFIXE . ', demande ' . $d['reference'] . ($e['echeance'] && (int) $e['echeance'] > 0 ? ' – échéance ' . $e['echeance'] : '') . ($prevu ? self::PREVU : '');
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
