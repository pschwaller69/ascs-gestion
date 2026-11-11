<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Lien personnel de chaque animateur (repéré par le mail d'animateur des sections) : une seule page,
 * sans mot de passe, avec tous ses cours en onglets. Le lien ne change pas aux imports WinDev ni à l'effacement des données.
 */
final class Animateurs
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Animateurs de la saison : un par mail d'animateur, avec ses cours et son lien (créé au besoin).
     * @return list<array{mail:string,jeton:string,sections:list<array<string,mixed>>}>
     */
    public function liste(int $saisonId): array
    {
        $par = [];
        foreach ($this->sectionsAvecMail($saisonId) as $s) {
            $par[mb_strtolower(trim((string) $s['mail_animateur1']))][] = $s;
        }
        ksort($par);
        $out = [];
        foreach ($par as $mail => $sections) {
            $out[] = ['mail' => $mail, 'jeton' => $this->jeton($mail), 'sections' => $sections];
        }
        return $out;
    }

    public function parJeton(string $jeton): ?string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $jeton)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT mail FROM animateur WHERE jeton = ?');
        $st->execute([strtolower($jeton)]);
        $m = $st->fetchColumn();
        return $m === false ? null : (string) $m;
    }

    /** Cours de l'animateur pour la saison (ceux qui ont des inscrits ou des créneaux), cours d'essai en dernier. */
    public function sections(string $mail, int $saisonId): array
    {
        return array_values(array_filter($this->sectionsAvecMail($saisonId), static fn ($s) => mb_strtolower(trim((string) $s['mail_animateur1'])) === mb_strtolower(trim($mail))));
    }

    /** Lien de l'animateur (créé à la première demande). */
    public function jeton(string $mail): string
    {
        $mail = mb_strtolower(trim($mail));
        $st = $this->pdo->prepare('SELECT jeton FROM animateur WHERE mail = ?');
        $st->execute([$mail]);
        $j = $st->fetchColumn();
        if ($j) {
            return (string) $j;
        }
        $j = Referentiel::uuid();
        $this->pdo->prepare('INSERT INTO animateur (mail, jeton, cree_le) VALUES (?, ?, ?)')->execute([$mail, $j, date('Y-m-d H:i:s')]);
        return $j;
    }

    public function nouveauJeton(string $mail): string
    {
        $j = Referentiel::uuid();
        $this->jeton($mail);
        $this->pdo->prepare('UPDATE animateur SET jeton = ? WHERE mail = ?')->execute([$j, mb_strtolower(trim($mail))]);
        return $j;
    }

    /** @return list<array<string,mixed>> */
    private function sectionsAvecMail(int $saisonId): array
    {
        $st = $this->pdo->prepare("SELECT s.id, s.titre, s.essai, s.mail_animateur1, s.presence_token,
                (SELECT COUNT(*) FROM inscription i WHERE i.section_id = s.id AND i.saison_id = ?) AS inscrits,
                (SELECT COUNT(*) FROM creneau c WHERE c.section_id = s.id) AS nb_creneaux
            FROM section s WHERE s.mail_animateur1 IS NOT NULL AND s.mail_animateur1 <> '' ORDER BY s.essai, s.titre");
        $st->execute([$saisonId]);
        $c = new Cartes($this->pdo);
        $cachees = $c->rattachees();
        $out = [];
        foreach ($st->fetchAll() as $s) {
            if (in_array((int) $s['id'], $cachees, true)) {
                continue;
            }
            $s['inscrits'] = count((new Presences($this->pdo))->inscrits((int) $s['id'], $saisonId));
            if ((int) $s['inscrits'] > 0 || ((int) $s['nb_creneaux'] > 0 && !$s['essai'])) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
