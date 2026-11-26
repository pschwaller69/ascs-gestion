<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\EnLigne;
use Ascs\Web\App;
use Ascs\Web\Auth;

/** Pages publiques : inscription et cours d'essai remplis par les adhérents (accès par QR code). */
final class PublicController
{
    private EnLigne $el;

    public function __construct(private readonly App $app)
    {
        $this->el = new EnLigne($app->pdo);
    }

    /** Bureau connecté (ou compte de test) : le formulaire fermé au public reste visible et utilisable pour l'essayer. */
    private function apercu(string $type): bool
    {
        $a = $this->app->user !== null && !$this->el->ouvert($type);
        $this->el->apercu($a);
        return $a;
    }

    private function vide(): array
    {
        return ['nom' => '', 'prenom' => '', 'genre' => '', 'date_naissance' => '', 'mail' => '', 'telephone1' => '', 'adresse' => '',
            'code_postal' => '', 'ville' => '', 'nom_parent' => '', 'prenom_parent' => '', 'canal_id' => '', 'droit_image' => '',
            'remarques' => '', 'sections' => [], 'section' => '', 'date_essai' => '', 'consentement' => '',
            // bulletin d'inscription
            'telephone2' => '', 'attestation_ce' => '', 'canaux' => [], 'canal_autre' => '', 'assurance' => '', 'assurance_option' => '',
            'moyens' => [], 'nb_cheques' => '1', 'aides' => [], 'code_pass_sport' => '', 'carte_region_code' => '', 'soussigne' => '', 'reglement_lu' => '', 'fait_a' => ''];
    }

    private function formulaire(string $type, array $saisie, array $err, int $status = 200): string
    {
        $_SESSION['form_affiche'][$type] = time();
        $apercu = $this->apercu($type);
        if (!$this->el->ouvert($type) && !$apercu) {
            return $this->app->renderPublic('public/ferme', ['type' => $type], $type === 'essai' ? 'Cours d\'essai' : 'Inscription');
        }
        $sections = $this->el->sectionsOuvertes($type === 'essai');
        return $this->app->renderPublic('public/formulaire', [
            'type' => $type, 's' => $saisie, 'err' => $err, 'sections' => $sections, 'canaux' => $this->el->canaux(),
            'saison' => $this->el->saisonTitre(), 'message' => $this->el->message(),
            'reglement' => (new \Ascs\Domain\Bulletin($this->app->pdo))->reglementInterieur(), 'aides' => (new \Ascs\Domain\Bulletin($this->app->pdo))->aidesOuvertes(), 'natures' => (new \Ascs\Domain\Bulletin($this->app->pdo))->natures(),
            'horaires' => (new \Ascs\Domain\Bulletin($this->app->pdo))->horairesBureau(),
            'apercu' => $apercu,
            'reinscription' => $type === 'inscription' ? ($_SESSION['reinscription']['prenom'] ?? null) : null,
        ], $type === 'essai' ? 'Cours d\'essai' : 'Inscription', $status);
    }

    public function inscription(array $p): string
    {
        unset($_SESSION['reinscription']);
        $s = $this->vide();
        if (isset($_GET['section']) && ctype_digit((string) $_GET['section'])) {
            $s['sections'] = [(string) $_GET['section']];
        }
        return $this->formulaire('inscription', $s, []);
    }

    /** Lien de réinscription personnel : formulaire d'inscription rempli avec la fiche, demande rattachée à cette fiche. */
    public function reinscription(array $p): string
    {
        $r = new \Ascs\Domain\Reinscription($this->app->pdo);
        $a = $r->adherentDuJeton((string) ($p['jeton'] ?? ''));
        if (!$a) {
            unset($_SESSION['reinscription']);
            return $this->app->renderPublic('public/lien_perime', [], 'Lien de réinscription', 404);
        }
        $_SESSION['reinscription'] = ['id' => (int) $a['id'], 'prenom' => (string) $a['prenom'], 'le' => time()];
        $ouvertes = array_map('intval', array_column($this->el->sectionsOuvertes(false), 'id'));
        return $this->formulaire('inscription', $r->saisie($a, $this->vide(), $ouvertes), []);
    }

    public function essai(array $p): string
    {
        $s = $this->vide();
        if (isset($_GET['section']) && ctype_digit((string) $_GET['section'])) {
            $s['section'] = (string) $_GET['section'];
        }
        return $this->formulaire('essai', $s, []);
    }

    public function envoyerInscription(array $p): string
    {
        return $this->envoyer('inscription');
    }

    public function envoyerEssai(array $p): string
    {
        return $this->envoyer('essai');
    }

    private function envoyer(string $type): string
    {
        if (!$this->el->ouvert($type) && !$this->apercu($type)) {
            return $this->app->renderPublic('public/ferme', ['type' => $type], 'Fermé');
        }
        $affiche = $_SESSION['form_affiche'][$type] ?? null;
        $saisie = array_merge($this->vide(), array_map(static fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_string')) : (is_string($v) ? $v : ''), $_POST));
        if (EnLigne::robot($_POST, $affiche)) {
            // Réponse neutre : on ne dit pas au robot pourquoi.
            return $this->formulaire($type, $saisie, ['_' => 'Votre demande n\'a pas pu être envoyée. Vérifiez le formulaire et envoyez-le à nouveau.'], 422);
        }
        $ip = Auth::ip();
        if ($this->el->tropDEnvois($ip)) {
            return $this->formulaire($type, $saisie, ['_' => 'Trop de demandes envoyées depuis cette connexion. Réessayez dans une heure, ou adressez-vous au bureau.'], 429);
        }
        [$d, $err] = $this->el->nettoyer($_POST, $type);
        $bulletin = new \Ascs\Domain\Bulletin($this->app->pdo);
        $c = null;
        if ($type === 'inscription') {
            [$c, $e2] = $bulletin->nettoyer($_POST, $d);
            $err += $e2;
            [$carte, $e3] = \Ascs\Domain\CartesPass::controler($_FILES, $_POST, $c['aides'] ?? []);
            $err += $e3;
            $c['carte_region'] = ['photo' => $carte['fichier'] !== null, 'code' => $carte['code'] !== null];
            if ($err && $carte['fichier'] !== null) {
                $err['carte_region_photo'] = $err['carte_region_photo'] ?? 'Corrigez les autres champs puis choisissez à nouveau la photo de la carte.';
            }
        }
        if ($err) {
            return $this->formulaire($type, $saisie, $err, 422);
        }
        if ($c !== null && $c['canaux']) {
            $d['canal_id'] = $c['canaux'][0];
        }
        $ref = $this->el->enregistrer($d, $ip);
        if ($c !== null) {
            $st = $this->app->pdo->prepare('SELECT id FROM demande WHERE reference = ?');
            $st->execute([$ref]);
            $demandeId = (int) $st->fetchColumn();
            $bulletin->enregistrer($demandeId, $c);
            (new \Ascs\Domain\CartesPass($this->app->pdo))->enregistrer($demandeId, $carte);
            // Venue d'un lien de réinscription (dans les 12 dernières heures) : demande rattachée à la fiche
            $ri = $_SESSION['reinscription'] ?? null;
            if (is_array($ri) && time() - (int) $ri['le'] < 12 * 3600) {
                (new \Ascs\Domain\Reinscription($this->app->pdo))->rattacher($demandeId, (int) $ri['id']);
            }
            unset($_SESSION['reinscription']);
        }
        // Cours d'essai : validé seul quand la fiche ne fait pas de doute (sinon il attend le bureau dans Demandes)
        $auto = false;
        if ($type === 'essai') {
            try {
                $st = $this->app->pdo->prepare('SELECT id FROM demande WHERE reference = ?');
                $st->execute([$ref]);
                $auto = (new \Ascs\Domain\Demandes($this->app->pdo))->validerEssaiAuto((int) $st->fetchColumn())['ok'];
            } catch (\Throwable $e) {
                error_log('Validation automatique du cours d\'essai ' . $ref . ' : ' . $e->getMessage());
            }
        }
        // Dates à retenir : reprise des cours des activités choisies, ou jour du cours d'essai
        $dates = [];
        $choisies = array_map('intval', json_decode((string) $d['sections'], true) ?: []);
        foreach ($this->el->sectionsOuvertes($type === 'essai') as $s) {
            if (!in_array((int) $s['id'], $choisies, true)) {
                continue;
            }
            $titre = (string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $s['titre']);
            if ($type === 'essai') {
                $jour = $d['date_essai'] ?: null;
                $quand = $jour ? 'cours d\'essai le ' . dfr($jour) : 'cours d\'essai dès maintenant, au prochain cours';
            } else {
                $quand = $s['reprise'] ? 'reprise des cours le ' . dfr($s['reprise']) : '';
            }
            if ($quand !== '' || $s['adresse']) {
                $dates[] = ['titre' => $titre, 'quand' => $quand, 'adresse' => $s['adresse']];
            }
        }
        $pieces = $c !== null ? $bulletin->pieces($d, $c) : [];
        $bureau = $c !== null ? $bulletin->auBureau($c) : [];
        $horaires = $bulletin->horairesBureau();
        $this->confirmer($type, $d, (string) $ref, $c, $pieces, $bureau, $horaires, $auto, $dates);
        unset($_SESSION['form_affiche'][$type]);
        $_SESSION['demande_envoyee'] = ['ref' => $ref, 'type' => $type, 'sections' => json_decode($d['sections'], true), 'prenom' => $d['prenom'],
            'recap' => $c['recap'] ?? null, 'essai_valide' => $auto, 'dates' => $dates, 'whatsapp' => $this->el->whatsapp(), 'pieces' => $pieces, 'bureau' => $bureau, 'horaires' => $horaires];
        $this->app->redirect('/merci');
    }

    /** Mail de confirmation à la personne (sans bloquer la demande si l'envoi échoue). */
    private function confirmer(string $type, array $d, string $ref, ?array $c = null, array $pieces = [], array $bureau = [], string $horaires = '', bool $essaiValide = false, array $dates = []): void
    {
        try {
            $r = (new \Ascs\Documents\Reglages($this->app->pdo))->tous();
            if ($r['expediteur'] === '' || !filter_var((string) $d['mail'], FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $ids = array_map('intval', json_decode((string) $d['sections'], true) ?: []);
            $noms = [];
            foreach ($this->el->sectionsOuvertes($type === 'essai') as $s) {
                if (in_array((int) $s['id'], $ids, true)) {
                    $noms[] = (string) preg_replace('/^Z\s*ESSAI\s*/iu', '', (string) $s['titre']);
                }
            }
            $asso = $r['association'] ?: 'ASCS';
            $texte = 'Bonjour ' . trim((string) $d['prenom']) . ",\n\n"
                . ($type === 'essai' ? 'Nous avons bien reçu votre demande de cours d\'essai' : 'Nous avons bien reçu votre demande d\'inscription')
                . ($noms ? ' pour : ' . implode(', ', $noms) : '') . ".\nRéférence : " . $ref . "\n\n"
                . ($type === 'essai' ? ($essaiValide ? 'Votre cours d\'essai est enregistré : l\'animateur a votre nom sur sa liste. Présentez-vous à l\'heure du cours.' : 'Le bureau vous recontacte pour vous confirmer le jour du cours.') : 'Le bureau la vérifie et vous recontacte si besoin. L\'inscription est définitive à réception du règlement.')
                . ($dates ? "\n\nÀ retenir :" . implode('', array_map(static fn ($x) => "\n- " . $x['titre'] . ($x['quand'] !== '' ? ' : ' . $x['quand'] : '')
                    . ($x['adresse'] ? "\n  Adresse : " . $x['adresse'] . "\n  Plan : " . EnLigne::lienPlan($x['adresse']) : ''), $dates)) : '')
                . (($wa = $this->el->whatsapp()) ? "\n\nVous ne trouvez pas le cours, une question ? Rejoignez la communauté WhatsApp de l'association : " . $wa : '')
                . ($c ? "\n\n" . self::texteRecap($c['recap']) : '')
                . ($bureau ? "\n\nÀ déposer au bureau de l'association (ouvert " . $horaires . ") :\n- " . implode("\n- ", $bureau) : '')
                . ($pieces ? "\n\nÀ apporter au premier cours :\n- " . implode("\n- ", $pieces) : '')
                . (($pl = $type === 'inscription' ? $this->paiementEnLigne($ref) : null)
                    ? "\n\nPayer en ligne par carte bancaire (HelloAsso) : " . \Ascs\Web\App::urlAbsolue('/paiement/' . $pl['jeton'])
                        . "\nHelloAsso propose une « contribution volontaire » à son fonctionnement : elle n'est pas obligatoire et ne va pas à l'association. Vous pouvez la modifier et la mettre à 0 €." : '')
                . ($c ? "\n\nVous avez accepté le règlement intérieur (signé en ligne par " . $c['soussigne'] . ' le ' . date('d/m/Y') . ').' : '')
                . "\n\nÀ bientôt,\n" . $asso . ($r['telephone'] ? "\n" . $r['telephone'] : '') . ($r['site'] ? "\n" . $r['site'] : '');
            (new \Ascs\Mail\Envoi($this->app->pdo))->envoyer($r['expediteur'], $asso, (string) $d['mail'],
                ($type === 'essai' ? 'Demande de cours d\'essai' : 'Demande d\'inscription') . ' – ' . $asso, $texte, [], null, 'demande en ligne ' . $ref);
        } catch (\Throwable) {
            // la demande est enregistrée : un mail qui ne part pas ne doit pas l'empêcher
        }
    }

    public static function texteRecap(array $r): string
    {
        $f = static fn (float $v) => number_format($v, 2, ',', ' ') . ' €';
        $t = 'Récapitulatif :';
        foreach ($r['lignes'] as $l) {
            $t .= "\n- " . $l['libelle'] . ' : ' . $f($l['montant']);
        }
        foreach ($r['aides'] as $a) {
            $t .= "\n- " . $a['libelle'] . ' : −' . $f($a['montant']);
        }
        if ($r['frais'] > 0) {
            $t .= "\n- Frais de gestion chèques ANCV : " . $f($r['frais']);
        }
        return $t . "\nReste à régler : " . $f($r['reste']) . ($r['rillieux'] ? ' (tarif habitant de Rillieux)' : '');
    }

    public function merci(array $p): string
    {
        $m = $_SESSION['demande_envoyee'] ?? null;
        if (!$m) {
            $this->app->redirect('/inscription');
        }
        return $this->app->renderPublic('public/merci', ['m' => $m, 'message' => $this->el->message(),
            'enLigne' => $m['type'] === 'inscription' ? $this->paiementEnLigne((string) $m['ref']) : null], 'Demande envoyée');
    }

    /** Paiement HelloAsso proposé pour cette demande : jeton du lien, montant, nombre d'échéances possible ; null sinon. */
    private function paiementEnLigne(string $ref): ?array
    {
        try {
            $pel = new \Ascs\HelloAsso\PaiementEnLigne($this->app->pdo);
            if (!$pel->actif()) {
                return null;
            }
            $d = $pel->demande($ref);
            $montant = $d ? \Ascs\HelloAsso\PaiementEnLigne::montantDu($d) : null;
            if ($montant === null) {
                return null;
            }
            return ['jeton' => \Ascs\HelloAsso\PaiementEnLigne::jeton($ref), 'montant' => $montant,
                'fois' => $pel->foisPossible($d), 'garanties' => $pel->garanties($d)];
        } catch (\Throwable) {
            return null;
        }
    }
}
