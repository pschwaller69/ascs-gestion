<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Demandes reçues en ligne (inscriptions et cours d'essai), validées par le bureau :
 * fiche existante ou nouvelle, une inscription par activité demandée (montant 0 pour un essai).
 * Le choix est gardé pour être rejoué si un import WinDev (mode transition) efface les inscriptions.
 */
final class Demandes
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** 2026-11-07… -> 07/11/2026 */
    private static function jma(?string $iso): string
    {
        return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : '';
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT d.*, c.libelle AS canal, sa.titre AS saison FROM demande d LEFT JOIN canal c ON c.id = d.canal_id LEFT JOIN saison sa ON sa.id = d.saison_id WHERE d.id = ?');
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) {
            return null;
        }
        $ids = json_decode((string) $d['sections'], true) ?: [];
        $d['liste_sections'] = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $d['liste_sections'] = $this->pdo->query("SELECT id, titre, montant, essai FROM section WHERE id IN ($in) ORDER BY titre")->fetchAll();
        }
        return $d;
    }

    /** @return array<string,int> */
    public function compteurs(): array
    {
        $c = ['a_traiter' => 0, 'valide' => 0, 'refuse' => 0];
        foreach ($this->pdo->query('SELECT statut, COUNT(*) n FROM demande GROUP BY statut') as $r) {
            $c[$r['statut']] = (int) $r['n'];
        }
        return $c;
    }

    /** @return list<array<string,mixed>> */
    public function liste(string $statut, int $limite = 300): array
    {
        $st = $this->pdo->prepare('SELECT * FROM demande WHERE statut = ? ORDER BY cree_le ' . ($statut === 'a_traiter' ? 'ASC' : 'DESC') . ' LIMIT ' . (int) $limite);
        $st->execute([$statut]);
        $out = [];
        foreach ($st->fetchAll() as $d) {
            $ids = json_decode((string) $d['sections'], true) ?: [];
            $d['titres'] = $ids ? implode(', ', $this->pdo->query('SELECT titre FROM section WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY titre')->fetchAll(PDO::FETCH_COLUMN)) : '';
            $out[] = $d;
        }
        return $out;
    }

    public function candidats(array $d): array
    {
        return (new DuplicateFinder($this->pdo))->chercher([
            'nom' => $d['nom'], 'prenom' => $d['prenom'], 'mail' => $d['mail'],
            'telephone1' => $d['telephone'], 'date_naissance' => $d['date_naissance'],
        ]);
    }

    public static function ficheDepuis(array $d): array
    {
        return array_merge(Adherents::vide(), [
            'nom' => (string) $d['nom'], 'prenom' => (string) $d['prenom'], 'genre' => (string) $d['genre'],
            'date_naissance' => (string) $d['date_naissance'], 'mail' => (string) $d['mail'], 'telephone1' => (string) $d['telephone'],
            'adresse' => (string) $d['adresse'], 'code_postal' => (string) $d['code_postal'], 'ville' => (string) $d['ville'],
            'nom_parent' => (string) $d['nom_parent'], 'prenom_parent' => (string) $d['prenom_parent'],
        ]);
    }

    /**
     * @param array<string,mixed> $in adherent ('nouveau' | id), completer, saison_id, creer[] (sections retenues), montant[sid], champs de fiche
     * @param bool $rejeu true après un import WinDev : une fiche « très probable » déjà présente est reprise au lieu d'en créer une nouvelle
     * @return array{ok:bool,erreurs:array<string,string>,inscriptions:list<int>}
     */
    public function valider(int $id, array $in, string $par, bool $rejeu = false): array
    {
        $d = $this->trouver($id);
        if (!$d || $d['statut'] !== 'a_traiter') {
            return ['ok' => false, 'erreurs' => ['_' => 'Cette demande a déjà été traitée.'], 'inscriptions' => []];
        }
        $err = [];
        $saison = Input::int($in, 'saison_id');
        if ($saison === null || (int) $this->pdo->query('SELECT COUNT(*) FROM saison WHERE id = ' . $saison)->fetchColumn() === 0) {
            $err['saison_id'] = 'Choisissez la saison.';
        }
        $retenues = [];
        $montants = (array) ($in['montant'] ?? []);
        foreach ($d['liste_sections'] as $s) {
            if (!in_array((string) $s['id'], array_map('strval', (array) ($in['creer'] ?? [])), true)) {
                continue;
            }
            $m = Input::montant(['m' => (string) ($montants[$s['id']] ?? '')], 'm');
            if ($m === null || (float) $m < 0) {
                $err['montant_' . $s['id']] = 'Montant invalide pour ' . $s['titre'] . '.';
            }
            $retenues[(int) $s['id']] = $m;
        }
        if (!$retenues && !$err) {
            $err['creer'] = 'Cochez au moins une activité à inscrire (ou refusez la demande).';
        }
        $adh = new Adherents($this->pdo);
        $choix = (string) ($in['adherent'] ?? '');
        $fiche = null;
        if ($choix === 'nouveau') {
            if ($rejeu) {
                $tres = array_values(array_filter($this->candidats($d), static fn ($c) => $c['niveau'] === 'A'));
                if (count($tres) === 1) {
                    $choix = (string) $tres[0]['id'];
                }
            }
        }
        if ($choix === 'nouveau') {
            [$fiche, $e] = $adh->nettoyer($in);
            $err += $e;
        } elseif (!ctype_digit($choix) || !$adh->trouver((int) $choix)) {
            $err['adherent'] = 'Choisissez une fiche existante ou « Nouvelle fiche ».';
        }
        if ($err) {
            return ['ok' => false, 'erreurs' => $err, 'inscriptions' => []];
        }

        $propre = !$this->pdo->inTransaction();
        if ($propre) {
            $this->pdo->beginTransaction();
        }
        try {
            $note = $d['droit_image'] === null ? null
                : 'Droit à l\'image : ' . ((int) $d['droit_image'] === 1 ? 'oui' : 'NON') . ' (demande en ligne du ' . self::jma($d['cree_le']) . ')';
            if ($fiche !== null) {
                if ($note) {
                    $fiche['remarques'] = trim(($fiche['remarques'] ?? '') . "\n" . $note);
                }
                $adherentId = $adh->creer($fiche);
            } else {
                $adherentId = (int) $choix;
                if (!empty($in['completer'])) {
                    $this->completer($adherentId, $d);
                }
                if ($note) {
                    $st = $this->pdo->prepare('SELECT remarques FROM adherent WHERE id = ?');
                    $st->execute([$adherentId]);
                    $this->pdo->prepare('UPDATE adherent SET remarques = ? WHERE id = ?')
                        ->execute([trim((string) $st->fetchColumn() . "\n" . $note), $adherentId]);
                }
            }
            if ($d['canal_id']) {
                $st = $this->pdo->prepare('SELECT COUNT(*) FROM adherent_canal WHERE adherent_id = ? AND canal_id = ?');
                $st->execute([$adherentId, $d['canal_id']]);
                if ((int) $st->fetchColumn() === 0) {
                    $this->pdo->prepare('INSERT INTO adherent_canal (adherent_id, canal_id) VALUES (?,?)')->execute([$adherentId, $d['canal_id']]);
                }
            }
            $insc = new Inscriptions($this->pdo);
            $creees = [];
            foreach ($retenues as $sid => $montant) {
                $exist = $insc->existante($adherentId, $sid, (int) $saison);
                if ($exist) {
                    $creees[] = $exist;
                    continue;
                }
                $rem = 'Demande en ligne ' . $d['reference']
                    . ($d['date_essai'] ? ', essai prévu le ' . self::jma($d['date_essai']) : '')
                    . ($d['remarques'] ? ' : ' . $d['remarques'] : '');
                $creees[] = $insc->creer([
                    'adherent_id' => $adherentId, 'section_id' => $sid, 'saison_id' => $saison,
                    'date_inscription' => $d['date_essai'] ?: substr((string) $d['cree_le'], 0, 10), 'fait_a' => 'En ligne',
                    'montant' => $montant, 'remarques' => mb_substr($rem, 0, 5000),
                ]);
            }
            $garde = array_intersect_key($in, array_flip(['adherent', 'completer', 'saison_id', 'creer', 'montant', ...array_keys(Adherents::vide())]));
            if ($fiche !== null) {
                $garde['adherent'] = 'nouveau';
            }
            $this->pdo->prepare('UPDATE demande SET statut = ?, adherent_id = ?, inscriptions = ?, choix = ?, traite_le = ?, traite_par = ? WHERE id = ?')
                ->execute(['valide', $adherentId, json_encode($creees), json_encode($garde, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $par, $id]);
            if ($propre) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($propre) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'erreurs' => [], 'inscriptions' => $creees];
    }

    private function completer(int $adherentId, array $d): void
    {
        $map = ['mail' => $d['mail'], 'telephone1' => $d['telephone'], 'date_naissance' => $d['date_naissance'], 'adresse' => $d['adresse'],
            'code_postal' => $d['code_postal'], 'ville' => $d['ville'], 'nom_parent' => $d['nom_parent'], 'prenom_parent' => $d['prenom_parent']];
        foreach ($map as $col => $val) {
            if ($val !== null && $val !== '') {
                $this->pdo->prepare("UPDATE adherent SET $col = ? WHERE id = ? AND ($col IS NULL OR $col = '')")->execute([$val, $adherentId]);
            }
        }
    }

    public function refuser(int $id, string $par): bool
    {
        $st = $this->pdo->prepare("UPDATE demande SET statut = 'refuse', traite_le = ?, traite_par = ? WHERE id = ? AND statut = 'a_traiter'");
        $st->execute([date('Y-m-d H:i:s'), $par, $id]);
        return $st->rowCount() > 0;
    }

    public function retablir(int $id): bool
    {
        $st = $this->pdo->prepare("UPDATE demande SET statut = 'a_traiter', traite_le = NULL, traite_par = NULL WHERE id = ? AND statut = 'refuse'");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /**
     * Après un import WinDev (mode transition) : les demandes validées dont toutes les inscriptions ont disparu
     * sont revalidées avec le choix du bureau. @return array{rejouees:int,a_revoir:int}
     */
    public function rejouerApresImport(): array
    {
        $r = ['rejouees' => 0, 'a_revoir' => 0];
        $exist = $this->pdo->prepare('SELECT COUNT(*) FROM inscription WHERE id = ?');
        foreach ($this->pdo->query("SELECT id, inscriptions, choix, traite_par FROM demande WHERE statut = 'valide'")->fetchAll() as $d) {
            $ids = json_decode((string) $d['inscriptions'], true) ?: [];
            $reste = 0;
            foreach ($ids as $i) {
                $exist->execute([(int) $i]);
                $reste += (int) $exist->fetchColumn();
            }
            if ($reste > 0) {
                continue;
            }
            $this->pdo->prepare("UPDATE demande SET statut = 'a_traiter', adherent_id = NULL, inscriptions = NULL WHERE id = ?")->execute([$d['id']]);
            $res = $this->valider((int) $d['id'], json_decode((string) $d['choix'], true) ?: [], (string) $d['traite_par'], true);
            $res['ok'] ? $r['rejouees']++ : $r['a_revoir']++;
        }
        return $r;
    }
}
