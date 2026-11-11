<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Contrôleurs : bénévoles qui passent dans les cours pour vérifier que tout le monde est inscrit.
 * Chacun a un lien personnel, sans mot de passe, qui ouvre la liste de n'importe quel cours (comme l'animateur),
 * avec la possibilité de noter une personne non inscrite pour que le bureau la relance.
 */
final class Controleurs
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function liste(): array
    {
        return $this->pdo->query('SELECT * FROM controleur ORDER BY nom')->fetchAll();
    }

    public function creer(string $nom): ?string
    {
        $nom = mb_substr(trim(preg_replace('/\s+/', ' ', $nom) ?? ''), 0, 100);
        if ($nom === '') {
            return 'Indiquez le nom du contrôleur.';
        }
        $this->pdo->prepare('INSERT INTO controleur (nom, jeton, cree_le) VALUES (?, ?, ?)')->execute([$nom, Referentiel::uuid(), date('Y-m-d H:i:s')]);
        return null;
    }

    public function nouveauJeton(int $id): void
    {
        $this->pdo->prepare('UPDATE controleur SET jeton = ? WHERE id = ?')->execute([Referentiel::uuid(), $id]);
    }

    public function supprimer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM controleur WHERE id = ?')->execute([$id]);
    }

    public function parJeton(string $jeton): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $jeton)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM controleur WHERE jeton = ?');
        $st->execute([strtolower($jeton)]);
        return $st->fetch() ?: null;
    }

    /**
     * Tous les cours de la saison qui ont des inscrits (les cartes rattachées sont dans leur cours), avec leurs créneaux.
     * @return list<array{id:int,titre:string,essai:bool,inscrits:int,creneaux:list<array{jour:int,heure:string}>}>
     */
    public function cours(int $saisonId): array
    {
        $pr = new Presences($this->pdo);
        $ref = new Referentiel($this->pdo);
        $cachees = (new Cartes($this->pdo))->rattachees();
        $out = [];
        foreach ($this->pdo->query('SELECT id, titre, essai FROM section ORDER BY titre')->fetchAll() as $s) {
            if (in_array((int) $s['id'], $cachees, true)) {
                continue;
            }
            $n = count($pr->inscrits((int) $s['id'], $saisonId));
            if ($n === 0) {
                continue;
            }
            $out[] = ['id' => (int) $s['id'], 'titre' => (string) $s['titre'], 'essai' => (bool) (int) $s['essai'], 'inscrits' => $n,
                'creneaux' => array_map(static fn ($c) => ['jour' => (int) $c['jour'], 'heure' => $c['heure_debut'] ? substr((string) $c['heure_debut'], 0, 5) : ''], $ref->creneaux((int) $s['id']))];
        }
        return $out;
    }
}
