<?php
declare(strict_types=1);

namespace Ascs\HelloAsso;

use Ascs\Domain\Adherents;
use Ascs\Domain\DuplicateFinder;
use Ascs\Domain\Inscriptions;
use Ascs\Domain\Input;
use PDO;

/**
 * Validation par le bureau d'une adhésion HelloAsso reçue :
 * choix de l'adhérent (fiche existante ou nouvelle), de la section et de la saison,
 * puis création de l'inscription et des règlements correspondant aux paiements encaissés.
 */
final class Validation
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function article(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM helloasso_item WHERE id = ?');
        $st->execute([$id]);
        $it = $st->fetch();
        if (!$it) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM helloasso_paiement WHERE item_id = ? ORDER BY date_paiement, id');
        $st->execute([$id]);
        $it['paiements'] = $st->fetchAll();
        $it['champs_liste'] = json_decode((string) $it['champs'], true) ?: [];
        return $it;
    }

    public static function montantInscription(array $it): string
    {
        return number_format((float) $it['montant'] + (float) $it['options'], 2, '.', '');
    }

    /** Section retenue pour ce formulaire + tarif, sinon section dont le nom figure dans le formulaire ou le tarif. */
    public function sectionProposee(array $it): array
    {
        $st = $this->pdo->prepare('SELECT section_id FROM helloasso_section WHERE formulaire = ? AND tarif = ?');
        $st->execute([$it['formulaire'], (string) $it['tarif']]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return ['id' => (int) $id, 'source' => 'retenue'];
        }
        $texte = DuplicateFinder::norm($it['formulaire'] . ' ' . $it['tarif']);
        $trouves = [];
        foreach ($this->pdo->query('SELECT id, titre FROM section') as $s) {
            $t = DuplicateFinder::norm($s['titre']);
            if (strlen($t) >= 4 && str_contains($texte, $t)) {
                $trouves[(int) $s['id']] = strlen($t);
            }
        }
        arsort($trouves);
        return $trouves ? ['id' => (int) array_key_first($trouves), 'source' => 'devinee'] : ['id' => null, 'source' => null];
    }

    /** Saison : années citées dans le formulaire (« 2026-2027 »), sinon celle qui contient la date de commande. */
    public function saisonProposee(array $it): ?int
    {
        if (preg_match('/(20\d\d)\D{0,3}(20\d\d)/', $it['formulaire'] . ' ' . $it['tarif'], $m)) {
            foreach ($this->pdo->query('SELECT id, titre FROM saison') as $s) {
                if (str_contains($s['titre'], $m[1]) && str_contains($s['titre'], $m[2])) {
                    return (int) $s['id'];
                }
            }
        }
        $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut <= ? AND date_fin >= ? ORDER BY date_debut DESC LIMIT 1');
        $d = substr((string) $it['date_commande'], 0, 10);
        $st->execute([$d, $d]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array{id:int,niveau:string,raisons:list<string>,fiche:array<string,mixed>}> */
    public function candidats(array $it): array
    {
        return (new DuplicateFinder($this->pdo))->chercher([
            'nom' => $it['nom'], 'prenom' => $it['prenom'], 'mail' => $it['mail'],
            'telephone1' => $it['telephone'], 'date_naissance' => $it['date_naissance'],
        ]);
    }

    /** Genre lu dans une question du formulaire (« Genre », « Sexe », « Civilité »…) : 0 femme, 1 homme, null si absent ou ambigu. */
    public static function genreDepuis(array $it): ?int
    {
        $champs = json_decode((string) ($it['champs'] ?? ''), true) ?: [];
        foreach ($champs as $nom => $val) {
            $n = DuplicateFinder::norm((string) $nom);
            if (!str_contains($n, 'genre') && !str_contains($n, 'sexe') && !str_contains($n, 'civilite')) {
                continue;
            }
            $v = DuplicateFinder::norm((string) $val);
            if (in_array($v, ['f', 'femme', 'fille', 'feminin', 'madame', 'mme', 'mlle', 'mademoiselle'], true)) {
                return 0;
            }
            if (in_array($v, ['m', 'h', 'homme', 'garcon', 'masculin', 'monsieur', 'mr'], true)) {
                return 1;
            }
        }
        return null;
    }

    /**
     * Validation automatique, seulement quand il n'y a aucun doute :
     * - réinscription : une seule fiche « très probable » (même nom, prénom et date de naissance) ;
     * - nouvelle personne : aucune fiche ressemblante, genre fourni par le formulaire ;
     * et dans les deux cas une section déjà retenue pour ce formulaire et ce tarif, une saison reconnue, un paiement encaissé.
     *
     * @return array{in:?array<string,mixed>,raison:?string} in = saisie à valider, sinon raison de la vérification manuelle
     */
    public function propositionAuto(array $it): array
    {
        $non = static fn (string $r) => ['in' => null, 'raison' => $r];
        if (in_array($it['etat'], Sync::ANNULE, true)) {
            return $non('annulée ou remboursée sur HelloAsso');
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM helloasso_paiement WHERE item_id = ? AND etat IN ('Authorized','Registered')");
        $st->execute([(int) $it['id']]);
        if ((int) $st->fetchColumn() === 0) {
            return $non('aucun paiement encaissé');
        }
        $section = $this->sectionProposee($it);
        if ($section['source'] !== 'retenue') {
            return $non('première adhésion de ce formulaire et de ce tarif : section à confirmer');
        }
        $saison = $this->saisonProposee($it);
        if ($saison === null) {
            return $non('saison non reconnue');
        }
        $in = ['section_id' => (string) $section['id'], 'saison_id' => (string) $saison, 'montant' => self::montantInscription($it)];
        $cands = $this->candidats($it);
        if (!$cands) {
            $g = self::genreDepuis($it);
            if ($g === null) {
                return $non('nouvelle personne, genre non indiqué dans le formulaire');
            }
            return ['in' => array_merge(self::ficheDepuis($it), ['adherent' => 'nouveau', 'genre' => (string) $g], $in), 'raison' => null];
        }
        $a = array_values(array_filter($cands, static fn ($c) => $c['niveau'] === 'A'));
        $b = array_filter($cands, static fn ($c) => $c['niveau'] === 'B');
        if (count($a) !== 1 || $b) {
            return $non(count($a) > 1 || $b ? 'plusieurs fiches possibles' : 'une fiche existante lui ressemble');
        }
        $f = $a[0]['fiche'];
        if (DuplicateFinder::norm($f['nom'] . ' ' . $f['prenom']) !== DuplicateFinder::norm($it['nom'] . ' ' . $it['prenom'])) {
            return $non('même date de naissance mais nom ou prénom différent');
        }
        return ['in' => ['adherent' => (string) $a[0]['id'], 'completer' => '1'] + $in, 'raison' => null];
    }

    /** Fiche à créer à partir des données HelloAsso (le payeur devient le parent s'il est une autre personne). */
    public static function ficheDepuis(array $it): array
    {
        $d = Adherents::vide();
        $parent = DuplicateFinder::norm($it['payeur_nom'] . $it['payeur_prenom']) !== DuplicateFinder::norm($it['nom'] . $it['prenom']);
        return array_merge($d, [
            'nom' => (string) $it['nom'], 'prenom' => (string) $it['prenom'], 'mail' => (string) $it['mail'],
            'telephone1' => (string) $it['telephone'], 'date_naissance' => (string) $it['date_naissance'],
            'adresse' => (string) $it['adresse'], 'code_postal' => (string) $it['code_postal'], 'ville' => (string) $it['ville'],
            'nom_parent' => $parent ? (string) $it['payeur_nom'] : '', 'prenom_parent' => $parent ? (string) $it['payeur_prenom'] : '',
        ]);
    }

    /**
     * @param array<string,mixed> $in POST : adherent ('nouveau' ou id), section_id, saison_id, montant, champs de la fiche si nouvelle
     * @return array{ok:bool,erreurs:array<string,string>,message:?string,inscription_id:?int}
     */
    public function valider(int $id, array $in, string $par, bool $rejeu = false): array
    {
        $it = $this->article($id);
        if (!$it || $it['statut'] !== 'a_traiter') {
            return ['ok' => false, 'erreurs' => ['_' => 'Cette adhésion a déjà été traitée.'], 'message' => null, 'inscription_id' => null];
        }
        $err = [];
        $section = Input::int($in, 'section_id');
        $saison = Input::int($in, 'saison_id');
        $montant = Input::montant($in, 'montant');
        $existe = fn (string $t, ?int $x) => $x !== null && (int) $this->pdo->query("SELECT COUNT(*) FROM $t WHERE id = " . (int) $x)->fetchColumn() > 0;
        if (!$existe('section', $section)) {
            $err['section_id'] = 'Choisissez la section.';
        }
        if (!$existe('saison', $saison)) {
            $err['saison_id'] = 'Choisissez la saison.';
        }
        if ($montant === null || (float) $montant < 0) {
            $err['montant'] = 'Montant invalide.';
        }
        $choix = (string) ($in['adherent'] ?? '');
        $adh = new Adherents($this->pdo);
        $fiche = null;
        // Rejeu après import WinDev : si la personne a entre-temps été saisie dans WinDev, on reprend sa fiche.
        if ($choix === 'nouveau' && $rejeu) {
            $tres = array_values(array_filter($this->candidats($it), static fn ($c) => $c['niveau'] === 'A'));
            if (count($tres) === 1) {
                $choix = (string) $tres[0]['id'];
            }
        }
        if ($choix === 'nouveau') {
            [$fiche, $e] = $adh->nettoyer($in);
            foreach ($e as $k => $v) {
                $err[$k] = $v;
            }
        } elseif (!ctype_digit($choix) || !$adh->trouver((int) $choix)) {
            $err['adherent'] = 'Choisissez une fiche existante ou « Nouvelle fiche ».';
        }
        if ($err) {
            return ['ok' => false, 'erreurs' => $err, 'message' => null, 'inscription_id' => null];
        }

        // Appelée seule (bouton) ou depuis une synchronisation déjà en transaction (rejeu après import WinDev)
        $propre = !$this->pdo->inTransaction();
        if ($propre) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($fiche !== null) {
                $adherentId = $adh->creer($fiche);
            } else {
                $adherentId = (int) $choix;
                if (!empty($in['completer'])) {
                    $this->completer($adherentId, $it);
                }
            }
            $insc = new Inscriptions($this->pdo);
            $inscriptionId = $insc->existante($adherentId, (int) $section, (int) $saison);
            $message = 'Inscription créée.';
            if ($inscriptionId) {
                $message = 'Cet adhérent était déjà inscrit à cette section : les paiements HelloAsso y ont été rattachés.';
            } else {
                $remarque = 'HelloAsso, commande ' . $it['commande_id'] . ($it['tarif'] ? ', tarif « ' . $it['tarif'] . ' »' : '')
                    . ($it['detail_options'] ? ', options : ' . $it['detail_options'] : '')
                    . ((float) $it['remise'] > 0 ? ', remise ' . number_format((float) $it['remise'], 2, ',', '') . ' €' : '');
                $inscriptionId = $insc->creer([
                    'adherent_id' => $adherentId, 'section_id' => $section, 'saison_id' => $saison,
                    'date_inscription' => substr((string) $it['date_commande'], 0, 10), 'fait_a' => 'HelloAsso',
                    'montant' => $montant, 'remarques' => $remarque,
                ]);
            }
            // Le choix est gardé pour être rejoué si un import WinDev (mode transition) efface l'inscription.
            $choixGarde = array_intersect_key($in, array_flip(['adherent', 'completer', 'section_id', 'saison_id', 'montant', ...array_keys(Adherents::vide())]));
            if ($fiche !== null) {
                $choixGarde['adherent'] = 'nouveau';
            }
            $this->pdo->prepare('UPDATE helloasso_item SET statut = ?, adherent_id = ?, inscription_id = ?, traite_le = ?, traite_par = ?, choix = ? WHERE id = ?')
                ->execute(['importe', $adherentId, $inscriptionId, date('Y-m-d H:i:s'), $par, json_encode($choixGarde, JSON_UNESCAPED_UNICODE), $id]);
            if ((string) $it['tarif'] !== '') {
                \Ascs\Db::upsertStatement($this->pdo, 'helloasso_section', ['formulaire', 'tarif', 'section_id'], ['formulaire', 'tarif'])
                    ->execute([$it['formulaire'], $it['tarif'], $section]);
            }
            $it['statut'] = 'importe';
            $it['inscription_id'] = $inscriptionId;
            $n = (new Sync($this->pdo))->rapprocherPaiements($it);
            if ($propre) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($propre) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'erreurs' => [], 'message' => $message . ($n ? " $n règlement(s) HelloAsso enregistré(s)." : ''), 'inscription_id' => $inscriptionId];
    }

    /** Remplit les champs vides d'une fiche existante avec les données HelloAsso (n'écrase rien). */
    private function completer(int $adherentId, array $it): void
    {
        $map = ['mail' => $it['mail'] ? mb_strtolower($it['mail']) : null, 'telephone1' => $it['telephone'], 'date_naissance' => $it['date_naissance'],
            'adresse' => $it['adresse'], 'code_postal' => preg_match('/^\d{5}$/', (string) $it['code_postal']) ? $it['code_postal'] : null, 'ville' => $it['ville']];
        foreach ($map as $col => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            $this->pdo->prepare("UPDATE adherent SET $col = ? WHERE id = ? AND " . self::vide($col))->execute([$val, $adherentId]);
        }
    }

    public function changerStatut(int $id, string $de, string $vers, string $par): bool
    {
        $st = $this->pdo->prepare('UPDATE helloasso_item SET statut = ?, traite_le = ?, traite_par = ? WHERE id = ? AND statut = ?');
        $st->execute([$vers, date('Y-m-d H:i:s'), $par, $id, $de]);
        return $st->rowCount() > 0;
    }

    /** @return array<string,int> */
    public function compteurs(): array
    {
        $c = ['a_traiter' => 0, 'importe' => 0, 'deja_present' => 0, 'ignore' => 0];
        foreach ($this->pdo->query('SELECT statut, COUNT(*) n FROM helloasso_item GROUP BY statut') as $r) {
            $c[$r['statut']] = (int) $r['n'];
        }
        return $c;
    }

    /** @return list<array<string,mixed>> */
    public function liste(array $statuts, int $limite = 300): array
    {
        $in = implode(',', array_fill(0, count($statuts), '?'));
        $st = $this->pdo->prepare(
            "SELECT h.*, s.titre AS section,
                    (SELECT COALESCE(SUM(p.montant),0) FROM helloasso_paiement p WHERE p.item_id = h.id AND p.etat IN ('Authorized','Registered')) AS paye
             FROM helloasso_item h LEFT JOIN inscription i ON i.id = h.inscription_id LEFT JOIN section s ON s.id = i.section_id
             WHERE h.statut IN ($in) ORDER BY h.date_commande DESC LIMIT " . (int) $limite
        );
        $st->execute($statuts);
        return $st->fetchAll();
    }

    /** Condition « champ vide » : une date ne se compare pas à '' (MariaDB strict refuse : erreur 1292). */
    private static function vide(string $col): string
    {
        return str_starts_with($col, 'date_') ? "$col IS NULL" : "($col IS NULL OR $col = '')";
    }
}
