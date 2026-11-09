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

    private function vide(): array
    {
        return ['nom' => '', 'prenom' => '', 'genre' => '', 'date_naissance' => '', 'mail' => '', 'telephone1' => '', 'adresse' => '',
            'code_postal' => '', 'ville' => '', 'nom_parent' => '', 'prenom_parent' => '', 'canal_id' => '', 'droit_image' => '',
            'remarques' => '', 'sections' => [], 'section' => '', 'date_essai' => '', 'consentement' => '',
            // bulletin d'inscription
            'telephone2' => '', 'attestation_ce' => '', 'canaux' => [], 'canal_autre' => '', 'assurance' => '', 'assurance_option' => '',
            'moyens' => [], 'nb_cheques' => '1', 'aides' => [], 'code_pass_sport' => '', 'soussigne' => '', 'reglement_lu' => '', 'fait_a' => ''];
    }

    private function formulaire(string $type, array $saisie, array $err, int $status = 200): string
    {
        $_SESSION['form_affiche'][$type] = time();
        if (!$this->el->ouvert($type)) {
            return $this->app->renderPublic('public/ferme', ['type' => $type], $type === 'essai' ? 'Cours d\'essai' : 'Inscription');
        }
        $sections = $this->el->sectionsOuvertes($type === 'essai');
        return $this->app->renderPublic('public/formulaire', [
            'type' => $type, 's' => $saisie, 'err' => $err, 'sections' => $sections, 'canaux' => $this->el->canaux(),
            'saison' => $this->el->saisonTitre(), 'message' => $this->el->message(),
            'reglement' => (new \Ascs\Domain\Bulletin($this->app->pdo))->reglementInterieur(), 'aides' => \Ascs\Domain\Bulletin::aides(),
        ], $type === 'essai' ? 'Cours d\'essai' : 'Inscription', $status);
    }

    public function inscription(array $p): string
    {
        $s = $this->vide();
        if (isset($_GET['section']) && ctype_digit((string) $_GET['section'])) {
            $s['sections'] = [(string) $_GET['section']];
        }
        return $this->formulaire('inscription', $s, []);
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
        if (!$this->el->ouvert($type)) {
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
            $bulletin->enregistrer((int) $st->fetchColumn(), $c);
        }
        $pieces = $c !== null ? $bulletin->pieces($d, $c) : [];
        $this->confirmer($type, $d, (string) $ref, $c, $pieces);
        unset($_SESSION['form_affiche'][$type]);
        $_SESSION['demande_envoyee'] = ['ref' => $ref, 'type' => $type, 'sections' => json_decode($d['sections'], true), 'prenom' => $d['prenom'],
            'recap' => $c['recap'] ?? null, 'pieces' => $pieces];
        $this->app->redirect('/merci');
    }

    /** Mail de confirmation à la personne (sans bloquer la demande si l'envoi échoue). */
    private function confirmer(string $type, array $d, string $ref, ?array $c = null, array $pieces = []): void
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
                . ($type === 'essai' ? 'Le bureau vous recontacte pour vous confirmer le jour du cours.' : 'Le bureau la vérifie et vous recontacte si besoin. L\'inscription est définitive à réception du règlement.')
                . ($c ? "\n\n" . self::texteRecap($c['recap']) : '')
                . ($pieces ? "\n\nÀ apporter au premier cours :\n- " . implode("\n- ", $pieces) : '')
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
        // Liens de paiement en ligne des activités demandées (réglés par le bureau)
        $paiements = [];
        if ($m['type'] === 'inscription') {
            foreach ($this->el->sectionsOuvertes(false) as $s) {
                if (in_array((int) $s['id'], $m['sections'], true) && $s['lien_paiement']) {
                    $paiements[] = $s;
                }
            }
        }
        return $this->app->renderPublic('public/merci', ['m' => $m, 'paiements' => $paiements, 'message' => $this->el->message()], 'Demande envoyée');
    }
}
