<?php
declare(strict_types=1);

namespace Ascs\Domain;

use Ascs\Documents\Reglages;
use Ascs\Mail\Envoi;
use Ascs\Parametres;
use PDO;

/**
 * Chèques de caution : l'adhérent paie en espèces plus tard et laisse en attendant un chèque, saisi comme
 * règlement « Chèque » avec « caution » dans les remarques, à la date où il sera déposé en banque.
 * Quand il apporte les espèces, le bureau passe le règlement en « Espèces » : le chèque sort de la liste.
 *
 * Rappel automatique par mail (tâche bin/envois.php, une fois par jour à partir de 9 h) quelques jours avant
 * la date de pose : un mail par adresse et par date, qui liste tous les chèques concernés (une famille = un mail).
 * Un chèque n'est rappelé qu'une fois par date de pose ; si la date change, il est rappelé de nouveau.
 */
final class Cautions
{
    public const JOURS_DEFAUT = 7;
    public const HEURE_ENVOI = 9;
    public const OBJET_DEFAUT = 'Votre chèque de caution sera déposé en banque le {date}';
    public const TEXTE_DEFAUT = "Bonjour,\n\n"
        . "Lors de l'inscription de {noms}, vous nous avez laissé {un_cheque} de caution en attendant votre règlement en espèces :\n"
        . "{cheques}\n\n"
        . "Sans règlement en espèces de votre part, {ce_cheque} déposé{s} en banque le {date}.\n\n"
        . "Si vous souhaitez toujours régler en espèces, merci de nous apporter la somme avant cette date : nous vous rendrons alors {votre_cheque}.\n\n"
        . "Cordialement,\n{association}";

    private const CAUTION = "r.mode_reglement_id = 1 AND LOWER(COALESCE(r.remarques, '')) LIKE '%caution%'
        AND NOT EXISTS (SELECT 1 FROM remise_cheque rc WHERE rc.reglement_id = r.id)";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{actif:bool,jours:int,objet:string,texte:string} */
    public function reglages(): array
    {
        $j = (int) (Parametres::get($this->pdo, 'caution_rappel_jours') ?? self::JOURS_DEFAUT);
        return [
            'actif' => Parametres::get($this->pdo, 'caution_rappel_actif') === '1',
            'jours' => $j >= 1 && $j <= 60 ? $j : self::JOURS_DEFAUT,
            'objet' => Parametres::get($this->pdo, 'caution_rappel_objet') ?: self::OBJET_DEFAUT,
            'texte' => Parametres::get($this->pdo, 'caution_rappel_texte') ?: self::TEXTE_DEFAUT,
        ];
    }

    public function enregistrer(bool $actif, int $jours, string $objet, string $texte): void
    {
        if ($jours < 1 || $jours > 60) {
            throw new \RuntimeException('Le délai doit être compris entre 1 et 60 jours.');
        }
        if (!str_contains($texte, '{date}') || !str_contains($texte, '{cheques}')) {
            throw new \RuntimeException('Le texte doit contenir {date} et {cheques}.');
        }
        Parametres::set($this->pdo, 'caution_rappel_actif', $actif ? '1' : '0');
        Parametres::set($this->pdo, 'caution_rappel_jours', (string) $jours);
        Parametres::set($this->pdo, 'caution_rappel_objet', trim($objet) === '' || trim($objet) === self::OBJET_DEFAUT ? null : trim($objet));
        Parametres::set($this->pdo, 'caution_rappel_texte', trim($texte) === trim(self::TEXTE_DEFAUT) ? null : trim($texte));
    }

    /**
     * Chèques de caution pas encore remis en banque, avec leur dernier rappel pour leur date de pose actuelle.
     *
     * @return list<array<string,mixed>>
     */
    public function enAttente(?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        $depuis = min((new Remises($this->pdo))->depuis(), $aujourdhui);
        $st = $this->pdo->prepare('SELECT r.id, r.inscription_id, r.montant, r.identifiant, r.date_reglement, r.remarques,
                a.id AS adherent_id, a.nom, a.prenom, a.nom_parent, a.prenom_parent, a.mail, a.adresse_mail_invalide, a.telephone1,
                s.titre AS section, sa.titre AS saison
            FROM reglement r JOIN inscription i ON i.id = r.inscription_id JOIN adherent a ON a.id = i.adherent_id
            JOIN section s ON s.id = i.section_id LEFT JOIN saison sa ON sa.id = i.saison_id
            WHERE ' . self::CAUTION . ' AND r.date_reglement IS NOT NULL AND r.date_reglement >= ?
            ORDER BY r.date_reglement, a.nom, a.prenom, r.id');
        $st->execute([$depuis]);
        $liste = $st->fetchAll();
        $jours = $this->reglages()['jours'];
        $rap = $this->pdo->prepare('SELECT envoye_le, destinataire, ok, erreur FROM rappel_caution WHERE reglement_id = ? AND date_pose = ? ORDER BY ok DESC, id DESC LIMIT 1');
        foreach ($liste as &$c) {
            $rap->execute([$c['id'], $c['date_reglement']]);
            $c['rappel'] = $rap->fetch() ?: null;
            $c['adresse'] = self::adresse($c);
            $c['rappel_prevu'] = date('Y-m-d', strtotime($c['date_reglement'] . " -$jours days"));
        }
        return $liste;
    }

    /** Adresse où envoyer le rappel, ou null (pas de mail, ou mail marqué invalide). */
    private static function adresse(array $c): ?string
    {
        $m = trim((string) $c['mail']);
        return !$c['adresse_mail_invalide'] && filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : null;
    }

    /**
     * Rappels dus aujourd'hui, regroupés par adresse et date de pose.
     *
     * @return list<array{adresse:string,date:string,cheques:list<array<string,mixed>>}>
     */
    public function aRappeler(?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        $limite = date('Y-m-d', strtotime($aujourdhui . ' +' . $this->reglages()['jours'] . ' days'));
        $groupes = [];
        foreach ($this->enAttente($aujourdhui) as $c) {
            $r = $c['rappel'];
            $dejaFait = $r && ((int) $r['ok'] === 1 || substr((string) $r['envoye_le'], 0, 10) === $aujourdhui);
            if ($c['adresse'] === null || $dejaFait || $c['date_reglement'] < $aujourdhui || $c['date_reglement'] > $limite) {
                continue;
            }
            $k = mb_strtolower($c['adresse']) . '|' . $c['date_reglement'];
            $groupes[$k] ??= ['adresse' => $c['adresse'], 'date' => (string) $c['date_reglement'], 'cheques' => []];
            $groupes[$k]['cheques'][] = $c;
        }
        return array_values($groupes);
    }

    /** @return array{0:string,1:string} objet et texte du mail pour un groupe de chèques */
    public function message(array $groupe): array
    {
        $reg = $this->reglages();
        $asso = (new Reglages($this->pdo))->tous()['association'] ?: 'ASCS';
        $noms = array_values(array_unique(array_map(static fn ($c) => trim($c['prenom'] . ' ' . $c['nom']), $groupe['cheques'])));
        $dernier = array_pop($noms);
        $lignes = array_map(static fn ($c) => '- chèque' . (trim((string) $c['identifiant']) !== '' ? ' n° ' . trim((string) $c['identifiant']) : '')
            . ' de ' . self::euros($c['montant']) . ' (' . $c['section'] . ($c['saison'] ? ', saison ' . $c['saison'] : '') . ')', $groupe['cheques']);
        $total = array_sum(array_map(static fn ($c) => (float) $c['montant'], $groupe['cheques']));
        $vars = [
            '{date}' => date('d/m/Y', strtotime($groupe['date'])), '{noms}' => ($noms ? implode(', ', $noms) . ' et ' : '') . $dernier,
            '{cheques}' => implode("\n", $lignes), '{total}' => self::euros($total), '{association}' => $asso,
        ];
        $n = count($groupe['cheques']);
        $vars += $n > 1
            ? ['{un_cheque}' => $n . ' chèques', '{ce_cheque}' => 'ces chèques seront', '{votre_cheque}' => 'vos chèques', '{s}' => 's']
            : ['{un_cheque}' => 'un chèque', '{ce_cheque}' => 'ce chèque sera', '{votre_cheque}' => 'votre chèque', '{s}' => ''];
        return [strtr($reg['objet'], $vars), strtr($reg['texte'], $vars)];
    }

    /**
     * Envoie les rappels dus. Sans effet si les rappels sont désactivés (sauf $force, bouton du bureau).
     *
     * @return array{envoyes:int,echecs:int,erreurs:list<string>}
     */
    public function envoyerRappels(string $par, bool $force = false, ?string $aujourdhui = null): array
    {
        $bilan = ['envoyes' => 0, 'echecs' => 0, 'erreurs' => []];
        if (!$force && !$this->reglages()['actif']) {
            return $bilan;
        }
        $reg = (new Reglages($this->pdo))->tous();
        $envoi = new Envoi($this->pdo);
        $ins = $this->pdo->prepare('INSERT INTO rappel_caution (reglement_id, date_pose, envoye_le, destinataire, ok, erreur, par) VALUES (?,?,?,?,?,?,?)');
        foreach ($this->aRappeler($aujourdhui) as $g) {
            [$objet, $texte] = $this->message($g);
            $e = $envoi->envoyer($reg['expediteur'], $reg['association'] ?: 'ASCS', $g['adresse'], $objet, $texte, [],
                (int) $g['cheques'][0]['inscription_id'], 'rappel caution (' . $par . ')');
            foreach ($g['cheques'] as $c) {
                $ins->execute([$c['id'], $g['date'], date('Y-m-d H:i:s'), mb_substr($g['adresse'], 0, 150), $e === null ? 1 : 0, $e, mb_substr($par, 0, 150)]);
            }
            if ($e === null) {
                $bilan['envoyes']++;
            } else {
                $bilan['echecs']++;
                $bilan['erreurs'][] = $g['adresse'] . ' : ' . $e;
            }
        }
        return $bilan;
    }

    /** Montant pour le texte du mail (les fonctions d'affichage du site ne sont pas chargées par la tâche automatique). */
    private static function euros(mixed $v): string
    {
        return number_format((float) $v, 2, ',', ' ') . ' €';
    }

    /** Tâche automatique : une passe par jour, à partir de 9 h. @return ?array{envoyes:int,echecs:int,erreurs:list<string>} */
    public function tache(): ?array
    {
        $jour = date('Y-m-d');
        if ((int) date('G') < self::HEURE_ENVOI || !$this->reglages()['actif'] || Parametres::get($this->pdo, 'caution_rappel_le') === $jour) {
            return null;
        }
        Parametres::set($this->pdo, 'caution_rappel_le', $jour);
        return $this->envoyerRappels('tâche automatique');
    }
}
