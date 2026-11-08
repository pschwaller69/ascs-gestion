<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Tables de paramétrage (saisons, familles, sections principales, sections) : liste, création, modification,
 * suppression seulement si rien ne s'en sert. Les identifiants viennent de WinDev : une création prend le plus grand + 1.
 */
final class Referentiel
{
    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /**
     * @return array<string,array{table:string,titre:string,un:string,ordre:string,champs:array<string,array<string,mixed>>,utilise:list<array{string,string,string}>}>
     */
    public static function definitions(): array
    {
        return [
            'saisons' => [
                'table' => 'saison', 'titre' => 'Saisons', 'un' => 'saison', 'ordre' => 'date_debut DESC, id DESC',
                'champs' => [
                    'titre' => ['libelle' => 'Titre', 'type' => 'text', 'requis' => true, 'max' => 20, 'aide' => 'Par exemple 2027-2028.'],
                    'date_debut' => ['libelle' => 'Début', 'type' => 'date', 'requis' => true],
                    'date_fin' => ['libelle' => 'Fin', 'type' => 'date', 'requis' => true],
                ],
                'utilise' => [['inscription', 'saison_id', 'inscription(s)'], ['compte', 'saison_id', 'ligne(s) de compte']],
            ],
            'familles' => [
                'table' => 'famille_section', 'titre' => 'Familles', 'un' => 'famille', 'ordre' => 'titre',
                'champs' => [
                    'titre' => ['libelle' => 'Titre', 'type' => 'text', 'requis' => true, 'max' => 50, 'aide' => 'Sport, Culture, Musique, Bien-être…'],
                ],
                'utilise' => [['section_principale', 'famille_section_id', 'section(s) principale(s)']],
            ],
            'sections-principales' => [
                'table' => 'section_principale', 'titre' => 'Sections principales', 'un' => 'section principale', 'ordre' => 'titre',
                'champs' => [
                    'titre' => ['libelle' => 'Titre', 'type' => 'text', 'requis' => true, 'max' => 80],
                    'famille_section_id' => ['libelle' => 'Famille', 'type' => 'select', 'requis' => true, 'source' => ['famille_section', 'titre']],
                    'code' => ['libelle' => 'Code', 'type' => 'text', 'max' => 10, 'aide' => 'Code court utilisé dans les comptes.'],
                    'montant_rentabilite_annuel' => ['libelle' => 'Seuil de rentabilité annuel (€)', 'type' => 'number'],
                ],
                'utilise' => [['section', 'section_principale_id', 'section(s)'], ['detail_compte', 'section_principale_id', 'ligne(s) de compte ventilée(s)']],
            ],
            'sections' => [
                'table' => 'section', 'titre' => 'Sections', 'un' => 'section', 'ordre' => 'titre',
                'champs' => [
                    'titre' => ['libelle' => 'Titre', 'type' => 'text', 'requis' => true, 'max' => 80, 'aide' => 'Activité et créneau, par exemple « PILATES MERCREDI ».'],
                    'section_principale_id' => ['libelle' => 'Section principale', 'type' => 'select', 'requis' => true, 'source' => ['section_principale', 'titre']],
                    'montant' => ['libelle' => 'Tarif extérieurs (€)', 'type' => 'number', 'requis' => true, 'aide' => 'Les habitants de Rillieux ont 10 € de réduction, sauf jogging et marche.'],
                    'essai' => ['libelle' => 'Section de cours d\'essai (gratuite)', 'court' => 'Essai', 'type' => 'bool'],
                    'mail_animateur1' => ['libelle' => 'Mail de l\'animateur', 'type' => 'email', 'max' => 150, 'aide' => 'Pour lui envoyer le lien de sa liste de présence.'],
                    'nom_campagne_helloasso' => ['libelle' => 'Campagne HelloAsso', 'type' => 'text', 'max' => 120],
                    'montant_rentabilite_annuel' => ['libelle' => 'Seuil de rentabilité annuel (€)', 'type' => 'number'],
                ],
                'utilise' => [['inscription', 'section_id', 'inscription(s)']],
            ],
        ];
    }

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function def(string $cle): ?array
    {
        return self::definitions()[$cle] ?? null;
    }

    /** @return array<int,string> */
    public function options(array $source): array
    {
        [$table, $col] = $source;
        return $this->pdo->query("SELECT id, $col FROM $table ORDER BY $col")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function trouver(array $def, int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM {$def['table']} WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function liste(array $def): array
    {
        $lignes = $this->pdo->query("SELECT * FROM {$def['table']} ORDER BY {$def['ordre']}")->fetchAll();
        foreach ($lignes as &$l) {
            $l['_usage'] = $this->usage($def, (int) $l['id']);
        }
        return $lignes;
    }

    /** @return list<string> ce qui utilise cet élément (empêche la suppression) */
    public function usage(array $def, int $id): array
    {
        $u = [];
        foreach ($def['utilise'] as [$table, $col, $lib]) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
            $st->execute([$id]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                $u[] = $n . ' ' . $lib;
            }
        }
        return $u;
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>,1:array<string,string>} valeurs propres et erreurs
     */
    public function nettoyer(array $def, array $in, ?int $id): array
    {
        $v = [];
        $err = [];
        foreach ($def['champs'] as $c => $f) {
            $brut = is_string($in[$c] ?? null) ? trim($in[$c]) : '';
            switch ($f['type']) {
                case 'bool':
                    $v[$c] = !empty($in[$c]) ? 1 : 0;
                    continue 2;
                case 'number':
                    $n = str_replace([' ', "\u{202F}", "\u{00A0}", '€', ','], ['', '', '', '', '.'], $brut);
                    if ($brut === '') {
                        $v[$c] = null;
                    } elseif (!is_numeric($n) || (float) $n < 0) {
                        $err[$c] = 'Montant invalide.';
                    } else {
                        $v[$c] = round((float) $n, 2);
                    }
                    break;
                case 'date':
                    $v[$c] = Input::date([$c => $brut], $c);
                    if ($brut !== '' && $v[$c] === null) {
                        $err[$c] = 'Date invalide.';
                    }
                    break;
                case 'select':
                    $v[$c] = ctype_digit($brut) && isset($this->options($f['source'])[(int) $brut]) ? (int) $brut : null;
                    break;
                case 'email':
                    $v[$c] = $brut === '' ? null : $brut;
                    if ($brut !== '' && !filter_var($brut, FILTER_VALIDATE_EMAIL)) {
                        $err[$c] = 'Adresse mail invalide.';
                    }
                    break;
                default:
                    $v[$c] = $brut === '' ? null : mb_substr($brut, 0, (int) ($f['max'] ?? 255));
            }
            if (!empty($f['requis']) && ($v[$c] ?? null) === null && !isset($err[$c])) {
                $err[$c] = 'Obligatoire.';
            }
        }
        if (isset($v['titre']) && !isset($err['titre'])) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$def['table']} WHERE UPPER(titre) = UPPER(?) AND id <> ?");
            $st->execute([$v['titre'], $id ?? 0]);
            if ((int) $st->fetchColumn() > 0) {
                $err['titre'] = 'Ce titre existe déjà.';
            }
        }
        if (isset($v['date_debut'], $v['date_fin']) && $v['date_fin'] <= $v['date_debut']) {
            $err['date_fin'] = 'La fin doit être après le début.';
        }
        if ($def['table'] === 'section' && $v['montant'] === null && !isset($err['montant'])) {
            $v['montant'] = 0.0;
        }
        return [$v, $err];
    }

    public function enregistrer(array $def, array $v, ?int $id): int
    {
        if ($id !== null) {
            $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($v)));
            $this->pdo->prepare("UPDATE {$def['table']} SET $set WHERE id = ?")->execute([...array_values($v), $id]);
            return $id;
        }
        $id = (int) $this->pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM {$def['table']}")->fetchColumn();
        $v = ['id' => $id] + $v;
        if ($def['table'] === 'section') {
            $v['presence_token'] = self::uuid();
        }
        $cols = implode(', ', array_keys($v));
        $this->pdo->prepare("INSERT INTO {$def['table']} ($cols) VALUES (" . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
        return $id;
    }

    public function supprimer(array $def, int $id): ?string
    {
        $u = $this->usage($def, $id);
        if ($u) {
            return 'Suppression impossible : utilisé par ' . implode(', ', $u) . '.';
        }
        if ($def['table'] === 'section') {
            $this->pdo->prepare('DELETE FROM creneau WHERE section_id = ?')->execute([$id]);
            foreach (['section_en_ligne', 'helloasso_section'] as $t) {
                try {
                    $this->pdo->prepare("DELETE FROM $t WHERE section_id = ?")->execute([$id]);
                } catch (\PDOException) {
                }
            }
        }
        $this->pdo->prepare("DELETE FROM {$def['table']} WHERE id = ?")->execute([$id]);
        return null;
    }

    /** @return list<array{jour:int,heure_debut:?string}> */
    public function creneaux(int $sectionId): array
    {
        $st = $this->pdo->prepare('SELECT jour, heure_debut FROM creneau WHERE section_id = ? ORDER BY jour, ordre');
        $st->execute([$sectionId]);
        return $st->fetchAll();
    }

    /** @param list<array{jour:mixed,heure:mixed}> $lignes */
    public function enregistrerCreneaux(int $sectionId, array $lignes): void
    {
        $this->pdo->prepare('DELETE FROM creneau WHERE section_id = ?')->execute([$sectionId]);
        $ordre = [];
        $ins = $this->pdo->prepare('INSERT INTO creneau (section_id, jour, ordre, heure_debut) VALUES (?,?,?,?)');
        foreach ($lignes as $l) {
            $j = (int) ($l['jour'] ?? 0);
            if (!isset(self::JOURS[$j])) {
                continue;
            }
            $h = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($l['heure'] ?? '')) ? $l['heure'] . ':00' : null;
            $ordre[$j] = ($ordre[$j] ?? 0) + 1;
            $ins->execute([$sectionId, $j, $ordre[$j], $h]);
        }
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
