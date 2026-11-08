<?php
declare(strict_types=1);

namespace Ascs\HelloAsso;

use Ascs\Db;
use Ascs\Domain\DuplicateFinder;
use Ascs\Parametres;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Synchronisation des adhésions HelloAsso.
 *  1. lecture de toutes les adhésions depuis une date (12 mois glissants par défaut) ;
 *  2. enregistrement dans helloasso_item / helloasso_paiement (les choix du bureau sont conservés) ;
 *  3. rapprochement : commande déjà saisie (référence = identifiant du règlement), liens encore valides,
 *     nouvelles échéances payées transformées en règlements pour les adhésions déjà validées.
 * Ne crée jamais d'adhérent ni d'inscription : c'est le rôle de la validation par le bureau.
 */
final class Sync
{
    public const MODE_HELLOASSO = 5;
    /** États d'un paiement considéré comme encaissé. */
    public const PAYE = ['Authorized', 'Registered'];
    /** États d'une adhésion à ne pas importer. */
    public const PAR_AUTO = 'automatique (HelloAsso)';
    public const ANNULE = ['Canceled', 'Refunded', 'Refused', 'Abandoned', 'Deleted', 'Contested'];

    private array $compte = ['recues' => 0, 'nouvelles' => 0, 'deja_present' => 0, 'reglements' => 0, 'liens_perdus' => 0, 'rejouees' => 0, 'auto' => 0];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,int> */
    public function compteur(): array
    {
        return $this->compte;
    }

    // ------------------------------------------------------------------ conversions

    public static function euros(mixed $centimes): string
    {
        return number_format(((int) $centimes) / 100, 2, '.', '');
    }

    public static function dateLocale(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** "12/03/2015", "12-03-2015", "2015-03-12…" -> 2015-03-12 ; null si invalide ou improbable. */
    public static function dateNaissance(?string $s): ?string
    {
        $s = trim((string) $s);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $s, $m)) {
            [$j, $mo, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $s, $m)) {
            [$a, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($mo, $j, $a) || $a < 1900 || sprintf('%04d-%02d-%02d', $a, $mo, $j) > date('Y-m-d')) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $a, $mo, $j);
    }

    /**
     * Transforme un article HelloAsso (JSON de l'API) en ligne helloasso_item.
     * Les champs personnalisés varient d'un formulaire à l'autre : on les reconnaît par leur libellé.
     */
    public static function ligne(array $it): array
    {
        $order = $it['order'] ?? [];
        $payer = $it['payer'] ?? [];
        $user = $it['user'] ?? [];
        $champs = [];
        foreach ($it['customFields'] ?? [] as $c) {
            if (isset($c['name'])) {
                $champs[(string) $c['name']] = (string) ($c['answer'] ?? '');
            }
        }
        $options = 0;
        $detail = [];
        foreach ($it['options'] ?? [] as $o) {
            $options += (int) ($o['amount'] ?? 0);
            $detail[] = trim((string) ($o['name'] ?? 'Option')) . ' (' . number_format(((int) ($o['amount'] ?? 0)) / 100, 2, ',', '') . ' €)';
            foreach ($o['customFields'] ?? [] as $c) {
                if (isset($c['name'])) {
                    $champs[(string) $c['name']] = (string) ($c['answer'] ?? '');
                }
            }
        }
        $cherche = static function (array $motifs, array $exclus = []) use ($champs): ?string {
            foreach ($champs as $nom => $val) {
                $n = DuplicateFinder::norm($nom);
                foreach ($exclus as $x) {
                    if (str_contains($n, $x)) {
                        continue 2;
                    }
                }
                foreach ($motifs as $m) {
                    // « =xx » : libellé exactement égal (évite que « cp » ou « tel » trouvent n'importe quoi)
                    $ok = $m[0] === '=' ? $n === substr($m, 1) : str_contains($n, $m);
                    if ($ok && trim($val) !== '') {
                        return trim($val);
                    }
                }
            }
            return null;
        };
        $memePersonne = DuplicateFinder::norm($user['lastName'] ?? '') === DuplicateFinder::norm($payer['lastName'] ?? '')
            && DuplicateFinder::norm($user['firstName'] ?? '') === DuplicateFinder::norm($payer['firstName'] ?? '');
        $dn = self::dateNaissance($cherche(['naissance'], ['lieu', 'ville', 'pays']));
        if ($dn === null && $memePersonne && !empty($payer['dateOfBirth'])) {
            $dn = self::dateNaissance((string) $payer['dateOfBirth']);
        }
        $tronque = static fn (?string $s, int $n) => $s === null || trim($s) === '' ? null : mb_substr(trim($s), 0, $n);

        return [
            'id' => (int) $it['id'],
            'commande_id' => (int) ($order['id'] ?? 0),
            'formulaire' => (string) ($order['formSlug'] ?? ''),
            'type_formulaire' => (string) ($order['formType'] ?? ''),
            'tarif' => $tronque($it['name'] ?? null, 255),
            'tarif_id' => isset($it['tierId']) ? (int) $it['tierId'] : null,
            'date_commande' => self::dateLocale($order['date'] ?? null) ?? date('Y-m-d H:i:s'),
            'etat' => (string) ($it['state'] ?? 'Unknown'),
            'montant' => self::euros($it['amount'] ?? 0),
            'options' => self::euros($options),
            'remise' => self::euros($it['discount']['amount'] ?? 0),
            'detail_options' => $detail ? implode(', ', $detail) : null,
            'nom' => $tronque($user['lastName'] ?? $payer['lastName'] ?? null, 100),
            'prenom' => $tronque($user['firstName'] ?? $payer['firstName'] ?? null, 100),
            'date_naissance' => $dn,
            'telephone' => $tronque($cherche(['telephone', 'portable', 'mobile', '=tel']), 30),
            'mail' => $tronque($cherche(['mail', 'courriel']) ?? $payer['email'] ?? null, 150),
            'payeur_nom' => $tronque($payer['lastName'] ?? null, 100),
            'payeur_prenom' => $tronque($payer['firstName'] ?? null, 100),
            'payeur_mail' => $tronque($payer['email'] ?? null, 150),
            'adresse' => $tronque($cherche(['adresse'], ['mail']) ?? $payer['address'] ?? null, 255),
            'code_postal' => $tronque($cherche(['codepostal', '=cp']) ?? $payer['zipCode'] ?? null, 10),
            'ville' => $tronque($cherche(['ville', 'commune'], ['naissance']) ?? $payer['city'] ?? null, 100),
            'champs' => $champs ? json_encode($champs, JSON_UNESCAPED_UNICODE) : null,
            'brut' => json_encode($it, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return list<array<string,mixed>> paiements de l'article (part revenant à cet article) */
    public static function paiements(array $it): array
    {
        $out = [];
        foreach ($it['payments'] ?? [] as $p) {
            if (!isset($p['id'])) {
                continue;
            }
            $out[] = [
                'id' => (int) $p['id'],
                'item_id' => (int) $it['id'],
                'commande_id' => (int) ($it['order']['id'] ?? 0),
                // Une commande peut regrouper plusieurs adhésions (deux enfants) : shareAmount = part de cet article.
                'montant' => self::euros($p['shareAmount'] ?? $p['amount'] ?? 0),
                'date_paiement' => self::dateLocale($p['date'] ?? null),
                'etat' => (string) ($p['state'] ?? 'Unknown'),
                'echeance' => isset($p['installmentNumber']) ? (int) $p['installmentNumber'] : null,
                'moyen' => isset($p['paymentMeans']) ? (string) $p['paymentMeans'] : null,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ synchronisation

    public function depuisParDefaut(): string
    {
        $p = Parametres::get($this->pdo, 'helloasso_depuis');
        return $p ?: (new DateTimeImmutable('-12 months'))->format('Y-m-d');
    }

    /** Enregistre une liste d'articles (déjà lus sur l'API) puis rapproche. @return array<string,int> */
    public function enregistrer(array $items): array
    {
        $now = date('Y-m-d H:i:s');
        $cols = array_keys(self::ligne(['id' => 0]));
        $upItem = Db::upsertStatement($this->pdo, 'helloasso_item', [...$cols, 'statut', 'recu_le', 'maj_le'], ['id'], ['statut', 'recu_le']);
        $pCols = ['id', 'item_id', 'commande_id', 'montant', 'date_paiement', 'etat', 'echeance', 'moyen', 'maj_le'];
        $upPay = Db::upsertStatement($this->pdo, 'helloasso_paiement', $pCols, ['id', 'item_id']);
        $existe = $this->pdo->prepare('SELECT COUNT(*) FROM helloasso_item WHERE id = ?');

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                if (!isset($it['id'])) {
                    continue;
                }
                $type = $it['order']['formType'] ?? '';
                if (($it['type'] ?? 'Membership') !== 'Membership' && $type !== 'Membership') {
                    continue;
                }
                $l = self::ligne($it);
                $existe->execute([$l['id']]);
                if ((int) $existe->fetchColumn() === 0) {
                    $this->compte['nouvelles']++;
                }
                $this->compte['recues']++;
                $upItem->execute([...array_values($l), 'a_traiter', $now, $now]);
                foreach (self::paiements($it) as $p) {
                    $upPay->execute([...array_values($p), $now]);
                }
            }
            $this->rapprocher();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->compte;
    }

    /** Lecture sur l'API puis enregistrement ; trace dans helloasso_sync. */
    public function executer(Client $client, string $origine, ?string $depuis = null): array
    {
        // Un seul passage à la fois (cron, notification et bouton peuvent se croiser)
        $verrou = @fopen(dirname(__DIR__, 2) . '/storage/helloasso.lock', 'c');
        if ($verrou && !flock($verrou, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('une synchronisation est déjà en cours, réessayez dans une minute.');
        }
        $depuis ??= $this->depuisParDefaut();
        $debut = date('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO helloasso_sync (debut, origine, ok) VALUES (?,?,0)')->execute([$debut, $origine]);
        $syncId = (int) $this->pdo->lastInsertId();
        try {
            $items = $client->adhesions((new DateTimeImmutable($depuis))->format('Y-m-d\T00:00:00P'));
            $c = $this->enregistrer($items);
            $resume = sprintf('%d adhésion(s) lue(s), %d nouvelle(s), %d déjà saisie(s) dans la base, %d règlement(s) créé(s)', $c['recues'], $c['nouvelles'], $c['deja_present'], $c['reglements']);
            if ($c['auto']) {
                $resume .= sprintf(', %d validée(s) automatiquement', $c['auto']);
            }
            if ($c['rejouees']) {
                $resume .= sprintf(', %d validation(s) refaite(s) automatiquement après un import WinDev', $c['rejouees']);
            }
            if ($c['liens_perdus'] > 0) {
                $resume .= sprintf(', %d adhésion(s) à revalider après un import WinDev', $c['liens_perdus']);
            }
            $this->pdo->prepare('UPDATE helloasso_sync SET fin = ?, ok = 1, resume = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $resume, $syncId]);
            Parametres::set($this->pdo, 'helloasso_derniere_sync', date('Y-m-d H:i:s'));
            return $c + ['resume' => $resume];
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE helloasso_sync SET fin = ?, ok = 0, resume = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), mb_substr($e->getMessage(), 0, 2000), $syncId]);
            throw $e;
        }
    }

    // ------------------------------------------------------------------ rapprochement

    private function inscriptionExiste(?int $id): bool
    {
        if (!$id) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM inscription WHERE id = ?');
        $st->execute([$id]);
        return (int) $st->fetchColumn() > 0;
    }

    /**
     * Inscription déjà en base pour cette commande : un règlement porte la référence de commande HelloAsso.
     * Si la commande contient plusieurs adhésions, on choisit l'inscription dont l'adhérent porte le même nom.
     *
     * @return array{inscription_id:int,adherent_id:int}|null
     */
    public function dejaSaisie(array $item): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT DISTINCT i.id AS inscription_id, i.adherent_id, a.nom, a.prenom
             FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN adherent a ON a.id = i.adherent_id
             WHERE r.identifiant = ?'
        );
        $st->execute([(string) $item['commande_id']]);
        $cands = $st->fetchAll();
        if (!$cands) {
            return null;
        }
        $n = DuplicateFinder::norm($item['nom']);
        $p = DuplicateFinder::norm($item['prenom']);
        $memeNom = array_values(array_filter($cands, static fn ($c) => DuplicateFinder::norm($c['nom']) === $n && DuplicateFinder::norm($c['prenom']) === $p));
        $memeNom = $memeNom ?: array_values(array_filter($cands, static fn ($c) => DuplicateFinder::norm($c['nom']) === $p && DuplicateFinder::norm($c['prenom']) === $n));
        $choix = count($memeNom) === 1 ? $memeNom[0] : (count($cands) === 1 && $this->articlesDeLaCommande((int) $item['commande_id']) === 1 ? $cands[0] : null);
        // Inscription déjà prise par un autre article de la même commande ?
        if ($choix) {
            $pris = $this->pdo->prepare('SELECT COUNT(*) FROM helloasso_item WHERE inscription_id = ? AND id <> ?');
            $pris->execute([$choix['inscription_id'], $item['id']]);
            if ((int) $pris->fetchColumn() > 0) {
                return null;
            }
        }
        return $choix ? ['inscription_id' => (int) $choix['inscription_id'], 'adherent_id' => (int) $choix['adherent_id']] : null;
    }

    private function articlesDeLaCommande(int $commande): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM helloasso_item WHERE commande_id = ?');
        $st->execute([$commande]);
        return (int) $st->fetchColumn();
    }

    /** Validation automatique des adhésions sans doute possible : activée sauf si le bureau l'a coupée. */
    public function autoActive(): bool
    {
        return Parametres::get($this->pdo, 'helloasso_auto') !== '0';
    }

    public function rapprocher(): void
    {
        $maj = $this->pdo->prepare('UPDATE helloasso_item SET statut = ?, adherent_id = ?, inscription_id = ? WHERE id = ?');
        foreach ($this->pdo->query("SELECT * FROM helloasso_item WHERE statut IN ('a_traiter','importe','deja_present')")->fetchAll() as $it) {
            $id = (int) $it['id'];
            if ($it['statut'] !== 'a_traiter' && !$this->inscriptionExiste($it['inscription_id'] ? (int) $it['inscription_id'] : null)) {
                // Inscription disparue (import WinDev en mode transition) : on repart de zéro pour cet article.
                $this->pdo->prepare('UPDATE helloasso_paiement SET reglement_id = NULL WHERE item_id = ?')->execute([$id]);
                $maj->execute(['a_traiter', null, null, $id]);
                $it['statut'] = 'a_traiter';
                $this->compte['liens_perdus']++;
            }
            if ($it['statut'] === 'a_traiter') {
                $d = $this->dejaSaisie($it);
                if ($d) {
                    $maj->execute(['deja_present', $d['adherent_id'], $d['inscription_id'], $id]);
                    $it['statut'] = 'deja_present';
                    $it['inscription_id'] = $d['inscription_id'];
                    $this->compte['deja_present']++;
                } elseif ($it['choix']) {
                    // Validée dans l'application puis effacée par un import WinDev : on rejoue le choix du bureau.
                    $r = (new Validation($this->pdo))->valider($id, json_decode((string) $it['choix'], true) ?: [], (string) $it['traite_par'], true);
                    if ($r['ok']) {
                        $this->compte['rejouees']++;
                        $this->compte['liens_perdus']--;
                    }
                    continue;
                } elseif ($this->autoActive()) {
                    $val = new Validation($this->pdo);
                    $prop = $val->propositionAuto($it);
                    if ($prop['in'] !== null) {
                        $r = $val->valider($id, $prop['in'], self::PAR_AUTO);
                        if ($r['ok']) {
                            $this->compte['auto']++;
                        }
                        continue;
                    }
                }
            }
            if ($it['statut'] !== 'a_traiter') {
                $this->rapprocherPaiements($it);
            }
        }
    }

    /**
     * Relie chaque paiement encaissé à un règlement de l'inscription (même référence de commande, même montant).
     * Les paiements sans règlement deviennent des règlements pour les adhésions validées dans l'application,
     * et pour toutes les adhésions une fois la bascule faite (WinDev ne les saisira plus).
     */
    public function rapprocherPaiements(array $it): int
    {
        $insc = (int) $it['inscription_id'];
        $pays = $this->pdo->prepare('SELECT * FROM helloasso_paiement WHERE item_id = ? ORDER BY date_paiement, id');
        $pays->execute([(int) $it['id']]);
        $pays = $pays->fetchAll();

        // Règlements existants de cette inscription pour cette commande
        $st = $this->pdo->prepare('SELECT id, montant FROM reglement WHERE inscription_id = ? AND identifiant = ? ORDER BY date_reglement, id');
        $st->execute([$insc, (string) $it['commande_id']]);
        $libres = [];
        foreach ($st->fetchAll() as $r) {
            $libres[(int) $r['id']] = (float) $r['montant'];
        }
        // Les liens encore valides réservent leur règlement
        foreach ($pays as $p) {
            if ($p['reglement_id'] !== null && isset($libres[(int) $p['reglement_id']])) {
                unset($libres[(int) $p['reglement_id']]);
            }
        }
        $avant = $this->compte['reglements'];
        $creer = $it['statut'] === 'importe' || Parametres::get($this->pdo, 'bascule') !== null;
        $lien = $this->pdo->prepare('UPDATE helloasso_paiement SET reglement_id = ? WHERE id = ? AND item_id = ?');
        foreach ($pays as $p) {
            $valide = $p['reglement_id'] !== null && $this->reglementValide((int) $p['reglement_id'], $insc, (string) $it['commande_id']);
            if ($valide || !in_array($p['etat'], self::PAYE, true)) {
                continue;
            }
            $rid = null;
            foreach ($libres as $lid => $m) {
                if (abs($m - (float) $p['montant']) < 0.005) {
                    $rid = $lid;
                    unset($libres[$lid]);
                    break;
                }
            }
            if ($rid === null && $creer) {
                $this->pdo->prepare('INSERT INTO reglement (inscription_id, mode_reglement_id, montant, date_reglement, identifiant, remarques, date_saisie) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$insc, self::MODE_HELLOASSO, $p['montant'], substr((string) ($p['date_paiement'] ?? $it['date_commande']), 0, 10), (string) $it['commande_id'],
                        'HelloAsso' . ($p['echeance'] ? ' – échéance ' . $p['echeance'] : ''), date('Y-m-d H:i:s')]);
                $rid = (int) $this->pdo->lastInsertId();
                $this->compte['reglements']++;
            }
            $lien->execute([$rid, $p['id'], $p['item_id']]);
        }
        return $this->compte['reglements'] - $avant;
    }

    private function reglementValide(int $rid, int $insc, string $commande): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM reglement WHERE id = ? AND inscription_id = ? AND identifiant = ?');
        $st->execute([$rid, $insc, $commande]);
        return (int) $st->fetchColumn() > 0;
    }
}
