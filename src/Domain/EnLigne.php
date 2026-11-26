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
    /** Aperçu pour le bureau connecté : formulaire visible même fermé, avec toutes les activités si aucune n'est ouverte. */
    private bool $apercu = false;

    public function apercu(bool $oui): void
    {
        $this->apercu = $oui;
    }

    public function sectionsOuvertes(bool $essai): array
    {
        return $this->avecDates($this->sectionsOuvertesBrutes($essai));
    }

    /**
     * Dates de la saison en ligne par activité : reprise des cours, et début des cours d'essai (réglé, sinon la reprise).
     * @return array<int,array{reprise:?string,essai_des:?string,essai_debut:?string}>
     */
    public function dates(): array
    {
        $st = $this->pdo->prepare('SELECT section_id, reprise, essai_des FROM section_reprise WHERE saison_id = ?');
        $st->execute([$this->saisonId()]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $rep = $r['reprise'] ? substr((string) $r['reprise'], 0, 10) : null;
            $ess = $r['essai_des'] ? substr((string) $r['essai_des'], 0, 10) : null;
            $out[(int) $r['section_id']] = ['reprise' => $rep, 'essai_des' => $ess, 'essai_debut' => $ess ?? $rep];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $l @return list<array<string,mixed>> */
    private function avecDates(array $l): array
    {
        $d = $this->dates();
        foreach ($l as &$s) {
            $s += $d[(int) $s['id']] ?? ['reprise' => null, 'essai_des' => null, 'essai_debut' => null];
        }
        unset($s);
        return $l;
    }

    /** Premier jour où un cours d'essai est possible pour cette activité (aujourd'hui si aucune date ou date passée). */
    public function debutEssai(int $sectionId): string
    {
        $d = $this->dates()[$sectionId]['essai_debut'] ?? null;
        return $d !== null && $d > date('Y-m-d') ? $d : date('Y-m-d');
    }

    /** @return list<array<string,mixed>> */
    private function sectionsOuvertesBrutes(bool $essai): array
    {
        if ($this->apercu) {
            $arretees = (new Arrets($this->pdo))->sections();
            $ouvertes = $this->sectionsOuvertesSeules($essai);
            if ($ouvertes) {
                return $ouvertes;
            }
            $st = $this->pdo->prepare('SELECT s.id, s.titre, s.montant, NULL AS description, NULL AS lien_paiement FROM section s
                WHERE s.essai = ?' . ($essai ? '' : ' AND s.montant > 0') . ' AND EXISTS (SELECT 1 FROM inscription i WHERE i.section_id = s.id AND i.saison_id = ?) ORDER BY s.titre');
            $st->execute([0, $this->saisonId()]);
            return array_values(array_filter($st->fetchAll(), static fn ($s) => !in_array((int) $s['id'], $arretees, true)));
        }
        return $this->sectionsOuvertesSeules($essai);
    }

    /** Une section de cours d'essai : drapeau « essai » et titre « Z ESSAI … » (les autres, Z MAIRIE, Z ASSOCIATIONS…, servent aux mailings). */
    public static function estSectionEssai(array $s): bool
    {
        return (int) $s['essai'] === 1 && preg_match('/^Z\s*ESSAI\b/iu', (string) $s['titre']) === 1;
    }

    /** @return list<array<string,mixed>> */
    private function sectionsOuvertesSeules(bool $essai): array
    {
        if ($essai) {
            // Cours d'essai : activités normales cochées « Cours d'essai » (les sections « Z ESSAI » ne sont plus proposées)
            $arretees = (new Arrets($this->pdo))->sections();
            $lignes = $this->pdo->query(
                'SELECT s.id, s.titre, s.montant, s.essai, COALESCE(e.ouvert, 0) AS ouvert, e.description, e.lien_paiement, se.ouvert AS essai_ouvert
                 FROM section s LEFT JOIN section_en_ligne e ON e.section_id = s.id LEFT JOIN section_essai se ON se.section_id = s.id
                 WHERE s.essai = 0 ORDER BY s.titre'
            )->fetchAll();
            return array_values(array_filter($lignes, static fn ($s) => self::essaiOuvert($s) && !in_array((int) $s['id'], $arretees, true)));
        }
        $st = $this->pdo->prepare(
            'SELECT s.id, s.titre, s.montant, e.description, e.lien_paiement
             FROM section s JOIN section_en_ligne e ON e.section_id = s.id
             WHERE e.ouvert = 1 AND s.essai = ? ORDER BY s.titre'
        );
        $st->execute([$essai ? 1 : 0]);
        $arretees = (new Arrets($this->pdo))->sections();
        return array_values(array_filter($st->fetchAll(), static fn ($s) => !in_array((int) $s['id'], $arretees, true)));
    }

    /** Activités (hors sections « essai ») avec leurs réglages, pour la page du bureau. @return list<array<string,mixed>> */
    public function toutesSections(): array
    {
        $l = $this->pdo->query(
            'SELECT s.id, s.titre, s.montant, s.essai, COALESCE(e.ouvert, 0) AS ouvert, e.description, e.lien_paiement, se.ouvert AS essai_ouvert
             FROM section s LEFT JOIN section_en_ligne e ON e.section_id = s.id LEFT JOIN section_essai se ON se.section_id = s.id
             WHERE s.essai = 0 ORDER BY s.titre'
        )->fetchAll();
        foreach ($l as &$s) {
            $s['essai_ouvert'] = self::essaiOuvert($s);
        }
        unset($s);
        return $this->avecDates($l);
    }

    /** Cours d'essai ouvert pour une activité : réglage enregistré, sinon comme l'inscription en ligne (sauf couture). */
    public static function essaiOuvert(array $s): bool
    {
        if (isset($s['essai_ouvert']) && $s['essai_ouvert'] !== null && $s['essai_ouvert'] !== '') {
            return (bool) (int) $s['essai_ouvert'];
        }
        return (int) ($s['ouvert'] ?? 0) === 1 && !str_contains(DuplicateFinder::norm((string) $s['titre']), 'couture');
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
        // Cours d'essai : pas avant le premier jour possible de l'activité (sans date choisie : ce jour-là s'il est à venir)
        $debut = $type === 'essai' && $choisies ? $this->debutEssai($choisies[0]) : date('Y-m-d');
        if ($type === 'essai' && Input::str($in, 'date_essai', 10) !== null) {
            $dateEssai = Input::date($in, 'date_essai');
            if ($dateEssai === null || $dateEssai < date('Y-m-d') || $dateEssai > date('Y-m-d', strtotime($debut . ' +6 months'))) {
                $err['date_essai'] = 'Choisissez une date dans les six prochains mois.';
                $dateEssai = null;
            } elseif ($dateEssai < $debut) {
                $err['date_essai'] = 'Les cours d\'essai de cette activité commencent le ' . date('d/m/Y', strtotime($debut)) . ' : choisissez cette date ou une date suivante.';
                $dateEssai = null;
            }
        } elseif ($type === 'essai' && $debut > date('Y-m-d')) {
            $dateEssai = $debut;
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
