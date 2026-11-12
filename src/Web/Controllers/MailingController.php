<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Documents\Reglages;
use Ascs\Domain\Mailing;
use Ascs\Domain\Marques;
use Ascs\Domain\VerifMails;
use Ascs\Mail\Sms;
use Ascs\Web\App;

/** Mailings et SMS (préparation, validation par le bureau, envoi par lots), validation des mails, export des contacts. */
final class MailingController
{
    private Mailing $m;

    public function __construct(private readonly App $app)
    {
        $this->m = new Mailing($app->pdo);
    }

    private function par(): string
    {
        return (string) ($this->app->user['email'] ?? '');
    }

    public function index(array $p): string
    {
        return $this->app->render('mailing/index', ['campagnes' => $this->m->campagnes(),
            'whatsapp' => (string) \Ascs\Parametres::get($this->app->pdo, 'whatsapp_communaute')], 'Mailings et SMS', 'mail');
    }

    private function sections(): array
    {
        $st = $this->app->pdo->prepare('SELECT s.id, s.titre, COUNT(i.id) AS n FROM section s JOIN inscription i ON i.section_id = s.id AND i.saison_id = ? GROUP BY s.id, s.titre ORDER BY s.titre');
        $st->execute([$this->app->saisonId()]);
        return $st->fetchAll();
    }

    public function nouveau(array $p): string
    {
        $canal = ($_GET['canal'] ?? '') === 'sms' ? 'sms' : 'mail';
        $cible = isset(Mailing::CIBLES[$_GET['cible'] ?? '']) ? $_GET['cible'] : 'saison';
        $d = ['canal' => $canal, 'cible' => $cible, 'sections' => array_map('intval', (array) ($_GET['sections'] ?? [])), 'objet' => '', 'texte' => $canal === 'sms' ? '' : "Bonjour {prenom},\n\n"];
        // Modèle : invitation à la communauté WhatsApp de l'association (tous les adhérents de la saison)
        $wa = (string) \Ascs\Parametres::get($this->app->pdo, 'whatsapp_communaute');
        if (($_GET['modele'] ?? '') === 'whatsapp' && $wa !== '') {
            $asso = (new Reglages($this->app->pdo))->tous()['association'] ?: 'l\'ASCS';
            $d['cible'] = 'saison';
            $d['objet'] = 'Rejoignez la communauté WhatsApp de ' . $asso;
            $d['texte'] = $canal === 'sms'
                ? 'Bonjour {prenom}, rejoignez la communauté WhatsApp de ' . $asso . ' : infos des sections, annulations de cours, événements. ' . $wa
                : "Bonjour {prenom},\n\nL'association a une communauté WhatsApp qui réunit toutes les sections : informations de votre section, annulations ou changements de cours, événements de l'association.\n\nPour la rejoindre, ouvrez ce lien depuis votre téléphone :\n" . $wa
                  . "\n\nVous pouvez ensuite choisir les groupes de vos sections. Votre numéro n'est visible que des administrateurs de la communauté.\n\nÀ bientôt,\n" . $asso;
        }
        return $this->form($d, []);
    }

    private function form(array $d, array $err, int $status = 200): string
    {
        $apercu = $this->m->destinataires($d['canal'], $d['cible'], $this->app->saisonId(), $d['sections']);
        return $this->app->render('mailing/nouveau', [
            'd' => $d, 'err' => $err, 'sections' => $this->sections(), 'apercu' => $apercu,
            'reglages' => (new Reglages($this->app->pdo))->tous(), 'smsPret' => Sms::depuisConfig() !== null,
        ], $d['canal'] === 'sms' ? 'Nouveau SMS' : 'Nouveau mail', 'mail', $status);
    }

    /** Nombre de destinataires pour le choix en cours (formulaire « Nouveau mail / SMS », sans recharger la page). */
    public function compter(array $p): string
    {
        $canal = ($_GET['canal'] ?? '') === 'sms' ? 'sms' : 'mail';
        $cible = isset(Mailing::CIBLES[$_GET['cible'] ?? '']) ? $_GET['cible'] : 'saison';
        $r = $this->m->destinataires($canal, $cible, $this->app->saisonId(), array_map('intval', (array) ($_GET['sections'] ?? [])));
        header('Content-Type: application/json');
        return (string) json_encode(['n' => count($r['retenus']), 'exclus' => $r['exclus'],
            'noms' => array_slice(array_map(static fn ($x) => trim($x['prenom'] . ' ' . $x['nom']), $r['retenus']), 0, 400)], JSON_UNESCAPED_UNICODE);
    }

    public function creer(array $p): ?string
    {
        $d = [
            'canal' => ($_POST['canal'] ?? '') === 'sms' ? 'sms' : 'mail',
            'cible' => isset(Mailing::CIBLES[$_POST['cible'] ?? '']) ? $_POST['cible'] : 'saison',
            'sections' => array_map('intval', (array) ($_POST['sections'] ?? [])),
            'objet' => trim((string) ($_POST['objet'] ?? '')),
            'texte' => trim(str_replace("\r\n", "\n", (string) ($_POST['texte'] ?? ''))),
        ];
        if (isset($_POST['actualiser'])) {
            return $this->form($d, []);
        }
        $err = [];
        if ($d['canal'] === 'mail' && $d['objet'] === '') {
            $err['objet'] = 'Obligatoire.';
        }
        if ($d['texte'] === '' || $d['texte'] === 'Bonjour {prenom},') {
            $err['texte'] = 'Écrivez le message.';
        }
        if ($d['cible'] === 'section' && !$d['sections']) {
            $err['sections'] = 'Cochez au moins une section.';
        }
        if ($err) {
            return $this->form($d, $err, 422);
        }
        $id = $this->m->creer($d['canal'], $d['cible'], $this->app->saisonId(), $d['sections'], $d['objet'], $d['texte'], $this->par());
        $this->app->flash('ok', 'Brouillon créé : vérifiez les destinataires puis validez l\'envoi.');
        $this->app->redirect('/mailing/' . $id);
        return null;
    }

    private function campagne(array $p): array
    {
        $c = $this->m->campagne((int) $p['id']);
        if (!$c) {
            $this->app->flash('erreur', 'Envoi introuvable.');
            $this->app->redirect('/mailing');
        }
        return $c;
    }

    public function fiche(array $p): string
    {
        $c = $this->campagne($p);
        $dest = $this->m->destinatairesCampagne((int) $c['id']);
        $premier = null;
        foreach ($dest as $d) {
            if ($d['statut'] !== 'retire') {
                $premier = $d;
                break;
            }
        }
        $sec = json_decode((string) $c['sections'], true) ?: [];
        $noms = [];
        if ($sec) {
            $st = $this->app->pdo->query('SELECT titre FROM section WHERE id IN (' . implode(',', array_map('intval', $sec)) . ') ORDER BY titre');
            $noms = $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $this->app->render('mailing/fiche', [
            'c' => $c, 'dest' => $dest, 'sectionsNoms' => $noms,
            'apercu' => $premier ? Mailing::personnaliser((string) $c['texte'], $premier, (string) $c['canal']) : (string) $c['texte'],
            'reglages' => (new Reglages($this->app->pdo))->tous(), 'smsPret' => Sms::depuisConfig() !== null,
            'test' => implode(', ', $this->m->destinatairesTest((string) $c['canal'])), 'liensTest' => $this->lienTests(),
        ], ($c['canal'] === 'sms' ? 'SMS' : 'Mail') . ' ' . ($c['objet'] ?: '#' . $c['id']), 'mail');
    }

    private function lienTests(): array
    {
        $l = $_SESSION['liens_test_sms'] ?? [];
        unset($_SESSION['liens_test_sms']);
        return $l;
    }

    public function modifier(array $p): void
    {
        $c = $this->campagne($p);
        $this->m->modifier((int) $c['id'], trim((string) ($_POST['objet'] ?? '')), trim(str_replace("\r\n", "\n", (string) ($_POST['texte'] ?? ''))));
        $this->app->flash('ok', 'Message modifié.');
        $this->app->redirect('/mailing/' . $c['id']);
    }

    public function destinataire(array $p): void
    {
        $this->m->basculer((int) $p['id'], (int) $p['dest']);
        $this->app->redirect('/mailing/' . (int) $p['id'] . '#d' . (int) $p['dest']);
    }

    public function valider(array $p): void
    {
        $c = $this->campagne($p);
        if ($c['canal'] === 'mail' && (new Reglages($this->app->pdo))->tous()['expediteur'] === '') {
            $this->app->flash('erreur', 'Renseignez d\'abord l\'adresse d\'envoi des mails (Paramétrage > Facture).');
            $this->app->redirect('/mailing/' . $c['id']);
        }
        $this->m->valider((int) $c['id'], $this->par());
        if ($c['canal'] === 'sms' && Sms::depuisConfig() === null) {
            $this->app->flash('ok', 'SMS validé. Ouvrez cette page sur le téléphone de l\'association pour les envoyer avec l\'application Messages.');
            $this->app->redirect('/mailing/' . $c['id']);
        }
        $r = $this->m->envoyerLot((int) $c['id']);
        $this->app->flash($r['echecs'] ? 'erreur' : 'ok', $this->bilan($r, (string) $c['canal']));
        $this->app->redirect('/mailing/' . $c['id']);
    }

    public function test(array $p): ?string
    {
        $c = $this->campagne($p);
        $r = $this->m->envoyerTest((int) $c['id'], (string) ($_POST['test'] ?? ''), $this->par());
        $msg = $r['envoyes'] ? ($c['canal'] === 'sms' && $r['liens'] ? 'SMS de test prêt' : 'Test envoyé') . ' à ' . implode(', ', $r['envoyes']) . '.' : 'Aucun test envoyé.';
        if ($r['erreurs']) {
            $msg .= ' Problème : ' . implode(' ; ', $r['erreurs']);
        }
        $this->app->flash($r['erreurs'] || !$r['envoyes'] ? 'erreur' : 'ok', $msg);
        $_SESSION['liens_test_sms'] = $r['liens'];
        $this->app->redirect('/mailing/' . $c['id'] . '#test');
        return null;
    }

    public function lot(array $p): void
    {
        $c = $this->campagne($p);
        $r = $this->m->envoyerLot((int) $c['id']);
        $this->app->flash($r['echecs'] ? 'erreur' : 'ok', $this->bilan($r, (string) $c['canal']));
        $this->app->redirect('/mailing/' . $c['id']);
    }

    private function bilan(array $r, string $canal): string
    {
        if (!empty($r['occupe'])) {
            return 'Un envoi est déjà en cours (tâche automatique ou autre personne du bureau) : rien n\'a été envoyé en double. Rechargez la page dans une minute.';
        }
        $mot = $canal === 'sms' ? 'SMS' : 'mail(s)';
        return sprintf('%d %s envoyé(s)', $r['envoyes'], $mot) . ($r['echecs'] ? sprintf(', %d en échec', $r['echecs']) : '')
            . ($r['restants'] ? sprintf('. %d restent à envoyer : ils partiront par lots (bouton « Envoyer le lot suivant » ou tâche automatique).', $r['restants']) : '. Envoi terminé.');
    }

    public function marquer(array $p): void
    {
        $c = $this->campagne($p);
        $n = $this->m->marquerEnvoyes((int) $c['id'], array_map('intval', (array) ($_POST['ids'] ?? [])));
        $this->app->flash('ok', $n . ' SMS marqué(s) envoyé(s).');
        $this->app->redirect('/mailing/' . $c['id'] . '#a-envoyer');
    }

    public function supprimer(array $p): void
    {
        $ok = $this->m->supprimer((int) $p['id']);
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Brouillon supprimé.' : 'Seul un brouillon peut être supprimé.');
        $this->app->redirect('/mailing');
    }

    /* Validation des mails */

    public function verification(array $p): string
    {
        $tout = isset($_GET['toutes']);
        $v = new VerifMails($this->app->pdo);
        return $this->app->render('mailing/verification', [
            'lignes' => $v->problemes($tout ? null : $this->app->saisonId()), 'tout' => $tout, 'dns' => $v->dnsDisponible(),
        ], 'Validation des mails', 'mail');
    }

    public function verificationAction(array $p): void
    {
        $id = (int) $p['id'];
        $action = (string) ($_POST['action'] ?? '');
        $ok = match ($action) {
            'corriger' => (new VerifMails($this->app->pdo))->corriger($id, trim((string) ($_POST['mail'] ?? ''))),
            'invalide' => (new Marques($this->app->pdo))->poser($id, 'adresse_mail_invalide', 1, $this->par()),
            'valide' => (new Marques($this->app->pdo))->poser($id, 'adresse_mail_invalide', 0, $this->par()),
            default => false,
        };
        $this->app->flash($ok ? 'ok' : 'erreur', $ok ? 'Fiche mise à jour.' : 'Action impossible.');
        $this->app->redirect('/mails/verification' . (isset($_POST['toutes']) ? '?toutes=1' : ''));
    }

    /* Contacts pour Outlook : CSV au format d'import d'Outlook (inscrits de la saison) */

    public function outlook(array $p): string
    {
        $st = $this->app->pdo->prepare('SELECT a.id, a.prenom, a.nom, a.mail, a.telephone1, a.telephone2, a.adresse, a.code_postal, a.ville, s.titre AS section
            FROM adherent a JOIN inscription i ON i.adherent_id = a.id JOIN section s ON s.id = i.section_id
            WHERE i.saison_id = ? ORDER BY a.nom, a.prenom');
        $st->execute([$this->app->saisonId()]);
        $par = [];
        foreach ($st->fetchAll() as $r) {
            $par[(int) $r['id']] ??= $r + ['sections' => []];
            $par[(int) $r['id']]['sections'][] = $r['section'];
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['First Name', 'Last Name', 'E-mail Address', 'Mobile Phone', 'Home Phone', 'Home Street', 'Home Postal Code', 'Home City', 'Categories'], ',', '"', '');
        foreach ($par as $r) {
            fputcsv($out, csv_sur([$r['prenom'], $r['nom'], $r['mail'], $r['telephone1'], $r['telephone2'], $r['adresse'], $r['code_postal'], $r['ville'],
                'ASCS;' . implode(';', array_unique($r['sections']))]), ',', '"', '');
        }
        rewind($out);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-ascs-' . date('Y-m-d') . '.csv"');
        return (string) stream_get_contents($out);
    }

    /* Désinscription (page publique, lien en bas de chaque mailing) */

    public function desinscription(array $p): string
    {
        header('X-Robots-Tag: noindex, nofollow');
        $id = Mailing::verifierJeton((string) $p['jeton']);
        return $this->app->renderPublic('mailing/desinscription', ['ok' => $id !== null, 'fait' => false, 'jeton' => $p['jeton']], 'Désinscription', $id ? 200 : 404);
    }

    public function desinscrire(array $p): string
    {
        $id = Mailing::verifierJeton((string) $p['jeton']);
        $fait = $id !== null && $this->m->desinscrire($id);
        return $this->app->renderPublic('mailing/desinscription', ['ok' => $id !== null, 'fait' => $fait, 'jeton' => $p['jeton']], 'Désinscription', $id ? 200 : 404);
    }

    /** Lien d'invitation de la communauté WhatsApp (gardé dans les réglages). */
    public function whatsapp(array $p): void
    {
        $lien = trim((string) ($_POST['lien'] ?? ''));
        if ($lien !== '' && !preg_match('#^https://chat\.whatsapp\.com/[A-Za-z0-9]+$#', $lien)) {
            $this->app->flash('erreur', 'Lien non reconnu : il doit ressembler à https://chat.whatsapp.com/… (Communauté > Inviter > Copier le lien).');
        } else {
            \Ascs\Parametres::set($this->app->pdo, 'whatsapp_communaute', $lien);
            $this->app->flash('ok', $lien === '' ? 'Lien retiré.' : 'Lien de la communauté WhatsApp enregistré.');
        }
        $this->app->redirect('/mailing');
    }

    /** Contacts dans Outlook (Microsoft 365) : état, réglage, synchronisation immédiate. */
    public function contacts(array $p): string
    {
        $s = new \Ascs\Contacts\SyncContacts($this->app->pdo);
        return $this->app->render('mailing/contacts', [
            'configure' => \Ascs\Contacts\SyncContacts::configure(), 'saisons' => $s->saisons(), 'derniere' => $s->derniere(),
            'nbVoulus' => count($s->voulus()), 'nbSynchro' => (int) $this->app->pdo->query('SELECT COUNT(*) FROM contact_sync')->fetchColumn(),
            'boite' => \Ascs\Config::load()['m365_boite'],
        ], 'Contacts Outlook', 'mail');
    }

    public function contactsSync(array $p): void
    {
        $s = new \Ascs\Contacts\SyncContacts($this->app->pdo);
        if (isset($_POST['saisons'])) {
            $s->regler((int) $_POST['saisons']);
        }
        if (($_POST['action'] ?? '') === 'synchroniser') {
            @set_time_limit(120);
            $r = $s->executer(null, 400);
            $this->app->flash($r['erreurs'] ? 'erreur' : 'ok', 'Contacts Outlook : ' . $s->derniere()['resume'] . '.');
        } else {
            $this->app->flash('ok', 'Réglage enregistré : il s\'appliquera à la prochaine synchronisation.');
        }
        $this->app->redirect('/contacts');
    }
}
