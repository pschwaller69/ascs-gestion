<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Options du formulaire d'inscription en ligne, comme les options des formulaires HelloAsso :
 * une question liée à certaines activités (location d'arc pour le tir à l'arc…), avec des choix et leur prix.
 * Le prix du choix s'ajoute au montant de l'inscription de l'activité concernée.
 */
final class OptionsInscription
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,nom:string,choix:list<array{libelle:string,prix:float}>,sections:list<int>,obligatoire:bool,actif:bool,explication:?string}> */
    public function liste(bool $actives = false): array
    {
        $this->initialiser();
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM option_inscription' . ($actives ? ' WHERE actif = 1' : '') . ' ORDER BY ordre, id')->fetchAll() as $r) {
            $out[] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'choix' => json_decode((string) $r['choix'], true) ?: [],
                'sections' => array_map('intval', json_decode((string) $r['sections'], true) ?: []), 'obligatoire' => (bool) (int) $r['obligatoire'],
                'actif' => (bool) (int) $r['actif'], 'explication' => $r['explication'] !== null && $r['explication'] !== '' ? (string) $r['explication'] : null];
        }
        return $out;
    }

    public function trouver(int $id): ?array
    {
        foreach ($this->liste() as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }
        return null;
    }

    /**
     * Lit les options choisies dans le formulaire (opt[id] = numéro du choix, vide = aucun) pour les activités cochées.
     * @param list<int> $sections
     * @return array{0:list<array{option_id:int,nom:string,choix:string,prix:float,section_id:int}>,1:array<string,string>}
     */
    public function nettoyer(array $in, array $sections): array
    {
        $choisies = [];
        $err = [];
        $saisie = (array) ($in['opt'] ?? []);
        foreach ($this->liste(true) as $o) {
            $concernees = array_values(array_intersect($sections, $o['sections']));
            if (!$concernees) {
                continue;
            }
            $v = (string) ($saisie[$o['id']] ?? '');
            if ($v === '' || !ctype_digit($v) || !isset($o['choix'][(int) $v])) {
                if ($o['obligatoire']) {
                    $err['opt_' . $o['id']] = 'Choisissez une réponse pour « ' . $o['nom'] . ' ».';
                }
                continue;
            }
            $c = $o['choix'][(int) $v];
            $choisies[] = ['option_id' => $o['id'], 'nom' => $o['nom'], 'choix' => (string) $c['libelle'], 'prix' => round((float) $c['prix'], 2), 'section_id' => $concernees[0]];
        }
        return [$choisies, $err];
    }

    /** Somme des options rattachées à une activité. @param list<array> $options */
    public static function prixPour(array $options, int $sectionId): float
    {
        return round(array_sum(array_map(static fn ($o) => (int) $o['section_id'] === $sectionId ? (float) $o['prix'] : 0.0, $options)), 2);
    }

    /** « Location d'arc : Arc débutant (40,00 €) ». @param list<array> $options */
    public static function texte(array $options): string
    {
        return implode(', ', array_map(static fn ($o) => $o['nom'] . ' : ' . $o['choix'] . ((float) $o['prix'] > 0 ? ' (' . number_format((float) $o['prix'], 2, ',', ' ') . ' €)' : ''), $options));
    }

    /**
     * Contrôle et enregistre une option. Renvoie un message d'erreur ou null.
     * @param list<array{libelle?:mixed,prix?:mixed}> $choix
     * @param list<mixed> $sections
     */
    public function enregistrer(?int $id, string $nom, array $choix, array $sections, bool $obligatoire, bool $actif, string $explication): ?string
    {
        $nom = mb_substr(trim(preg_replace('/\s+/', ' ', $nom) ?? ''), 0, 150);
        if ($nom === '') {
            return 'Indiquez le nom de l\'option (par exemple « Location d\'arc »).';
        }
        $propres = [];
        foreach ($choix as $c) {
            $lib = mb_substr(trim((string) ($c['libelle'] ?? '')), 0, 100);
            $p = trim((string) ($c['prix'] ?? ''));
            if ($lib === '' && $p === '') {
                continue;
            }
            $prix = (float) str_replace([',', ' ', '€'], ['.', '', ''], $p);
            if ($lib === '' || $prix < 0 || $prix > 1000) {
                return 'Chaque choix a un libellé et un prix entre 0 et 1 000 €.';
            }
            $propres[] = ['libelle' => $lib, 'prix' => round($prix, 2)];
        }
        if (!$propres) {
            return 'Indiquez au moins un choix (par exemple « Arc débutant » et son prix).';
        }
        $valides = array_map('intval', $this->pdo->query('SELECT id FROM section')->fetchAll(PDO::FETCH_COLUMN));
        $sections = array_values(array_intersect(array_map('intval', $sections), $valides));
        if (!$sections) {
            return 'Cochez au moins une activité concernée.';
        }
        $v = [$nom, json_encode($propres, JSON_UNESCAPED_UNICODE), json_encode($sections), $obligatoire ? 1 : 0, $actif ? 1 : 0, mb_substr(trim($explication), 0, 255) ?: null];
        if ($id) {
            $this->pdo->prepare('UPDATE option_inscription SET nom = ?, choix = ?, sections = ?, obligatoire = ?, actif = ?, explication = ? WHERE id = ?')->execute([...$v, $id]);
        } else {
            $ordre = (int) $this->pdo->query('SELECT COALESCE(MAX(ordre), 0) + 10 FROM option_inscription')->fetchColumn();
            $this->pdo->prepare('INSERT INTO option_inscription (ordre, nom, choix, sections, obligatoire, actif, explication, cree_le) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$ordre, ...$v, date('Y-m-d H:i:s')]);
        }
        return null;
    }

    public function supprimer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM option_inscription WHERE id = ?')->execute([$id]);
    }

    /** Une seule fois : l'option de HelloAsso pour le tir à l'arc, à compléter (prix) puis activer. */
    private function initialiser(): void
    {
        if (Parametres::get($this->pdo, 'options_inscription_init') !== null) {
            return;
        }
        Parametres::set($this->pdo, 'options_inscription_init', date('Y-m-d'));
        $arc = array_map('intval', $this->pdo->query("SELECT id FROM section WHERE essai = 0 AND UPPER(titre) LIKE '%TIR%ARC%'")->fetchAll(PDO::FETCH_COLUMN));
        if (!$arc) {
            return;
        }
        $this->pdo->prepare('INSERT INTO option_inscription (ordre, nom, choix, sections, obligatoire, actif, explication, cree_le) VALUES (10, ?, ?, ?, 0, 0, ?, ?)')
            ->execute(['Location d\'arc', json_encode([['libelle' => 'Arc débutant', 'prix' => 0], ['libelle' => 'Arc confirmé', 'prix' => 0]], JSON_UNESCAPED_UNICODE),
                json_encode($arc), 'Pour ceux qui n\'ont pas leur propre arc.', date('Y-m-d H:i:s')]);
    }
}
