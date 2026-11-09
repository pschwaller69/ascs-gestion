<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Parametres;
use PDO;

/**
 * Inscriptions et cours d'essai remplis par les adhérents eux-mêmes (pages publiques, QR codes).
 * Une demande n'écrit rien dans les fiches : elle attend la validation du bureau (Demandes).
 */
final class EnLigne
{
    public const MAX_PAR_HEURE = 10;
    public const DELAI_MIN_SECONDES = 4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ réglages

    public function ouvert(string $type): bool
    {
        return Parametres::get($this->pdo, $type === 'essai' ? 'essai_ouvert' : 'inscription_ouverte') === '1';
    }

    public function message(): ?string
    {
        return Parametres::get($this->pdo, 'en_ligne_message');
    }

    /** Saison des inscriptions en ligne : réglée par le bureau, sinon celle en cours aujourd'hui, sinon la plus récente. */
    public function saisonId(): int
    {
        $p = (int) Parametres::get($this->pdo, 'en_ligne_saison');
        if ($p > 0 && (int) $this->pdo->query('SELECT COUNT(*) FROM saison WHERE id = ' . $p)->fetchColumn() > 0) {
            return $p;
        }
        $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut <= ? AND date_fin >= ? ORDER BY date_debut DESC LIMIT 1');
        $st->execute([date('Y-m-d'), date('Y-m-d')]);
        $id = $st->fetchColumn();
        return (int) ($id !== false ? $id : $this->pdo->query('SELECT id FROM saison ORDER BY date_debut DESC, id DESC LIMIT 1')->fetchColumn());
    }

    public function saisonTitre(): string
    {
        return (string) $this->pdo->query('SELECT titre FROM saison WHERE id = ' . $this->saisonId())->fetchColumn();
    }

    /** Sections ouvertes en ligne : activités (essai = false) ou cours d'essai (essai = true). @return list<array<string,mixed>> */
    public function sectionsOuvertes(bool $essai): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.id, s.titre, s.montant, e.description, e.lien_paiement
             FROM section s JOIN section_en_ligne e ON e.section_id = s.id
             WHERE e.ouvert = 1 AND s.essai = ? ORDER BY s.titre'
        );
        $st->execute([$essai ? 1 : 0]);
        return $st->fetchAll();
    }

    /** Toutes les sections avec leurs réglages, pour la page du bureau. @return list<array<string,mixed>> */
    public function toutesSections(): array
    {
        return $this->pdo->query(
            'SELECT s.id, s.titre, s.montant, s.essai, COALESCE(e.ouvert, 0) AS ouvert, e.description, e.lien_paiement
             FROM section s LEFT JOIN section_en_ligne e ON e.section_id = s.id ORDER BY s.essai, s.titre'
        )->fetchAll();
    }

    public function canaux(): array
    {
        return $this->pdo->query('SELECT id, libelle FROM canal ORDER BY id')->fetchAll();
    }

    // ------------------------------------------------------------------ contrôle de la saisie publique

    public static function age(string $dateIso): int
    {
        return (new \DateTimeImmutable($dateIso))->diff(new \DateTimeImmutable('today'))->y;
    }

    /**
     * @param 'inscription'|'essai' $type
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public function nettoyer(array $in, string $type): array
    {
        // Mêmes règles de mise en forme que les fiches (NOM en majuscules, Prénom, mail en minuscules…)
        [$a, $err] = (new Adherents($this->pdo))->nettoyer($in);
        unset($err['nom']);
        $oblig = ['nom' => 'Indiquez le nom.', 'prenom' => 'Indiquez le prénom.', 'date_naissance' => 'Indiquez la date de naissance.',
            'mail' => 'Indiquez une adresse mail.', 'telephone1' => 'Indiquez un numéro de téléphone.'];
        if ($type === 'inscription') {
            $oblig += ['adresse' => 'Indiquez l\'adresse.', 'code_postal' => 'Indiquez le code postal.', 'ville' => 'Indiquez la ville.'];
        }
        foreach ($oblig as $k => $msg) {
            if (($a[$k] ?? null) === null && !isset($err[$k])) {
                $err[$k] = $msg;
            }
        }
        $mineur = $a['date_naissance'] !== null && self::age($a['date_naissance']) < 18;
        if ($mineur) {
            if ($a['nom_parent'] === null) {
                $err['nom_parent'] = 'Pour un mineur, indiquez le nom du parent ou du responsable légal.';
            }
            if ($a['prenom_parent'] === null) {
                $err['prenom_parent'] = 'Indiquez le prénom du parent.';
            }
        }

        $ouvertes = array_map('intval', array_column($this->sectionsOuvertes($type === 'essai'), 'id'));
        $choisies = [];
        if ($type === 'essai') {
            $sid = Input::int($in, 'section');
            if ($sid !== null && in_array($sid, $ouvertes, true)) {
                $choisies = [$sid];
            } else {
                $err['sections'] = 'Choisissez l\'activité à essayer.';
            }
        } else {
            foreach ((array) ($in['sections'] ?? []) as $v) {
                if (is_string($v) && ctype_digit($v) && in_array((int) $v, $ouvertes, true)) {
                    $choisies[] = (int) $v;
                }
            }
            if (!$choisies) {
                $err['sections'] = 'Cochez au moins une activité.';
            }
        }

        $dateEssai = null;
        if ($type === 'essai' && Input::str($in, 'date_essai', 10) !== null) {
            $dateEssai = Input::date($in, 'date_essai');
            if ($dateEssai === null || $dateEssai < date('Y-m-d') || $dateEssai > date('Y-m-d', strtotime('+6 months'))) {
                $err['date_essai'] = 'Choisissez une date dans les six prochains mois.';
                $dateEssai = null;
            }
        }
        $canal = Input::int($in, 'canal_id');
        if ($canal !== null && (int) $this->pdo->query('SELECT COUNT(*) FROM canal WHERE id = ' . $canal)->fetchColumn() === 0) {
            $canal = null;
        }
        // Droit à l'image : accordé par l'adhésion (règlement intérieur), plus de question séparée (décision du bureau, 09/11/2026)
        $droitImage = null;
        if (empty($in['consentement'])) {
            $err['consentement'] = 'Cochez cette case pour envoyer votre demande.';
        }

        $d = [
            'type' => $type, 'saison_id' => $this->saisonId(), 'sections' => json_encode($choisies), 'date_essai' => $dateEssai,
            'nom' => $a['nom'], 'prenom' => $a['prenom'], 'genre' => $a['genre'], 'date_naissance' => $a['date_naissance'],
            'mail' => $a['mail'], 'telephone' => $a['telephone1'], 'adresse' => $a['adresse'], 'code_postal' => $a['code_postal'],
            'ville' => $a['ville'], 'nom_parent' => $mineur ? $a['nom_parent'] : null, 'prenom_parent' => $mineur ? $a['prenom_parent'] : null,
            'canal_id' => $canal, 'droit_image' => $droitImage, 'remarques' => Input::text($in, 'remarques', 1000),
        ];
        return [$d, $err];
    }

    /** Vrai si l'envoi ressemble à un robot (champ piège rempli ou formulaire envoyé trop vite). */
    public static function robot(array $in, ?int $afficheLe): bool
    {
        if (trim((string) ($in['site_web'] ?? '')) !== '') {
            return true;
        }
        return $afficheLe === null || time() - $afficheLe < self::DELAI_MIN_SECONDES;
    }

    public function tropDEnvois(string $ip): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM demande WHERE ip = ? AND cree_le >= ?');
        $st->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
        return (int) $st->fetchColumn() >= self::MAX_PAR_HEURE;
    }

    /** Référence courte à communiquer (sans lettres ambiguës). */
    private function reference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $r = '';
            for ($i = 0; $i < 8; $i++) {
                $r .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM demande WHERE reference = ?');
            $st->execute([$r]);
        } while ((int) $st->fetchColumn() > 0);
        return $r;
    }

    public function enregistrer(array $d, string $ip): string
    {
        $d['reference'] = $this->reference();
        $d['cree_le'] = date('Y-m-d H:i:s');
        $d['ip'] = $ip;
        $d['statut'] = 'a_traiter';
        $cols = array_keys($d);
        $this->pdo->prepare('INSERT INTO demande (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($d));
        return $d['reference'];
    }
}
