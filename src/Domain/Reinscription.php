<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use Ascs\Web\App;
use PDO;

/**
 * Lien de réinscription personnel : le formulaire d'inscription s'ouvre rempli avec la fiche de l'adhérent,
 * et la demande arrive rattachée à cette fiche (plus de doublon).
 *
 * - Jeton signé /r/<adhérent>-<saison>-<signature> : rien à stocker, valable seulement pour la saison
 *   des inscriptions en ligne (il expire tout seul au changement de saison).
 * - Le bureau peut couper le lien d'une fiche (table reinscription_coupee).
 * - Balise {lien_reinscription} des mailings : un lien par membre de la famille qui partage l'adresse mail.
 */
final class Reinscription
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private static function secret(PDO $pdo): string
    {
        $s = Parametres::get($pdo, 'secret_liens');
        if (!$s) {
            $s = bin2hex(random_bytes(32));
            Parametres::set($pdo, 'secret_liens', $s);
        }
        return $s;
    }

    public function saison(): int
    {
        return (int) Parametres::get($this->pdo, 'en_ligne_saison');
    }

    public function jeton(int $adherentId, ?int $saisonId = null): string
    {
        $saisonId ??= $this->saison();
        $base = $adherentId . '-' . $saisonId;
        return $base . '-' . substr(hash_hmac('sha256', 'reinscription:' . $base, self::secret($this->pdo)), 0, 24);
    }

    public function lien(int $adherentId): string
    {
        return App::urlAbsolue('/r/' . $this->jeton($adherentId));
    }

    /** Fiche désignée par un jeton valable (bonne signature, saison en cours, lien non coupé), sinon null. */
    public function adherentDuJeton(string $jeton): ?array
    {
        if (!preg_match('/^(\d+)-(\d+)-([0-9a-f]{24})$/', $jeton, $m) || !hash_equals($this->jeton((int) $m[1], (int) $m[2]), $jeton)) {
            return null;
        }
        if ((int) $m[2] !== $this->saison() || $this->coupe((int) $m[1])) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM adherent WHERE id = ?');
        $st->execute([(int) $m[1]]);
        return $st->fetch() ?: null;
    }

    public function coupe(int $adherentId): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM reinscription_coupee WHERE adherent_id = ?');
        $st->execute([$adherentId]);
        return (int) $st->fetchColumn() > 0;
    }

    public function couper(int $adherentId, bool $oui, string $par): void
    {
        $this->pdo->prepare('DELETE FROM reinscription_coupee WHERE adherent_id = ?')->execute([$adherentId]);
        if ($oui) {
            $this->pdo->prepare('INSERT INTO reinscription_coupee (adherent_id, coupe_le, coupe_par) VALUES (?,?,?)')
                ->execute([$adherentId, date('Y-m-d H:i:s'), mb_substr($par, 0, 150)]);
        }
    }

    /**
     * Saisie du formulaire d'inscription pré-remplie avec la fiche. Activités : celles de la saison précédente
     * encore ouvertes à l'inscription (la personne peut les décocher).
     * @param list<int> $ouvertes sections proposées dans le formulaire
     */
    public function saisie(array $a, array $vide, array $ouvertes): array
    {
        $s = $vide;
        foreach (['nom', 'prenom', 'mail', 'telephone1', 'telephone2', 'adresse', 'code_postal', 'ville', 'nom_parent', 'prenom_parent', 'canal_autre'] as $k) {
            if (array_key_exists($k, $s)) {
                $s[$k] = trim((string) ($a[$k] ?? ''));
            }
        }
        $s['code_postal'] = $s['code_postal'] === '0' ? '' : $s['code_postal'];
        $s['date_naissance'] = (string) ($a['date_naissance'] ?? '');
        $s['genre'] = isset($a['genre']) ? (string) (int) $a['genre'] : '';
        $s['attestation_ce'] = !empty($a['attestation_ce']) ? '1' : '';
        // Activités de la dernière saison où la personne était inscrite (hors essais), si elles sont encore proposées
        $st = $this->pdo->prepare(
            'SELECT i.section_id FROM inscription i JOIN saison s ON s.id = i.saison_id JOIN section x ON x.id = i.section_id
             WHERE i.adherent_id = ? AND x.essai = 0 AND i.saison_id <> ?
               AND s.date_debut = (SELECT MAX(s2.date_debut) FROM inscription i2 JOIN saison s2 ON s2.id = i2.saison_id JOIN section x2 ON x2.id = i2.section_id
                                   WHERE i2.adherent_id = ? AND x2.essai = 0 AND i2.saison_id <> ?)'
        );
        $st->execute([(int) $a['id'], $this->saison(), (int) $a['id'], $this->saison()]);
        $s['sections'] = array_values(array_map('strval', array_intersect(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $ouvertes)));
        return $s;
    }

    // ------------------------------------------------------------------ rattachement de la demande

    public function rattacher(int $demandeId, int $adherentId): void
    {
        \Ascs\Db::upsertStatement($this->pdo, 'demande_reinscription', ['demande_id', 'adherent_id'], ['demande_id'])->execute([$demandeId, $adherentId]);
    }

    /** Fiche d'origine d'une demande faite par un lien de réinscription (si elle existe encore). */
    public function ficheDeLaDemande(int $demandeId): ?array
    {
        $st = $this->pdo->prepare('SELECT a.* FROM demande_reinscription r JOIN adherent a ON a.id = r.adherent_id WHERE r.demande_id = ?');
        $st->execute([$demandeId]);
        return $st->fetch() ?: null;
    }

    /** Champs que la personne a modifiés par rapport à sa fiche : [champ => [ancien, nouveau]]. */
    public static function changements(array $fiche, array $d): array
    {
        $nouveaux = [
            'mail' => $d['mail'], 'telephone1' => $d['telephone'], 'telephone2' => $d['complement']['telephone2'] ?? null,
            'adresse' => $d['adresse'], 'code_postal' => $d['code_postal'], 'ville' => $d['ville'],
            'nom_parent' => $d['nom_parent'], 'prenom_parent' => $d['prenom_parent'],
        ];
        $out = [];
        foreach ($nouveaux as $k => $v) {
            $v = trim((string) $v);
            $ancien = trim((string) ($fiche[$k] ?? ''));
            if ($v !== '' && mb_strtolower($v) !== mb_strtolower($ancien)) {
                $out[$k] = [$ancien, $v];
            }
        }
        return $out;
    }

    /** Met à jour la fiche avec les coordonnées modifiées dans le formulaire (validation par le bureau). */
    public function mettreAJour(int $adherentId, array $changements): void
    {
        foreach ($changements as $col => [, $nouveau]) {
            if (in_array($col, ['mail', 'telephone1', 'telephone2', 'adresse', 'code_postal', 'ville', 'nom_parent', 'prenom_parent'], true)) {
                $this->pdo->prepare("UPDATE adherent SET $col = ? WHERE id = ?")->execute([$nouveau, $adherentId]);
            }
        }
    }

    // ------------------------------------------------------------------ mailings

    /**
     * Texte de la balise {lien_reinscription} pour un destinataire : un lien si l'adresse ne sert qu'à une fiche,
     * sinon une ligne « Prénom : lien » par membre de la famille pas encore inscrit cette saison.
     */
    public function texteMailing(int $adherentId): string
    {
        $st = $this->pdo->prepare('SELECT mail FROM adherent WHERE id = ?');
        $st->execute([$adherentId]);
        $mail = mb_strtolower(trim((string) $st->fetchColumn()));
        $ids = [$adherentId];
        if ($mail !== '') {
            $st = $this->pdo->prepare(
                'SELECT a.id FROM adherent a WHERE LOWER(TRIM(a.mail)) = ?
                   AND NOT EXISTS (SELECT 1 FROM inscription i JOIN section x ON x.id = i.section_id WHERE i.adherent_id = a.id AND i.saison_id = ? AND x.essai = 0)
                   AND EXISTS (SELECT 1 FROM inscription i JOIN section x ON x.id = i.section_id WHERE i.adherent_id = a.id AND x.essai = 0)
                 ORDER BY a.prenom'
            );
            $st->execute([$mail, $this->saison()]);
            $ids = array_values(array_unique(array_merge([$adherentId], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)))));
        }
        $ids = array_values(array_filter($ids, fn ($id) => !$this->coupe($id)));
        if (!$ids) {
            return '';
        }
        if (count($ids) === 1) {
            return $this->lien($ids[0]);
        }
        $noms = $this->pdo->query('SELECT id, prenom FROM adherent WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
        $lignes = [];
        foreach ($ids as $id) {
            $lignes[] = '- ' . trim((string) ($noms[$id] ?? '')) . ' : ' . $this->lien($id);
        }
        return "\n" . implode("\n", $lignes) . "\n";
    }
}
