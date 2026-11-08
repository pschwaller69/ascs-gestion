<?php
declare(strict_types=1);

namespace Ascs\Domain;

use PDO;

/**
 * Fusion de deux fiches adhérent qui désignent la même personne (toujours à la main, jamais automatique).
 * La fiche gardée reçoit les valeurs choisies champ par champ, les inscriptions et règlements de l'autre fiche,
 * puis l'autre fiche est supprimée. Une inscription en double (même section, même saison) est regroupée :
 * l'inscription de la fiche gardée reste, les règlements de l'autre y sont rattachés.
 * Chaque fusion est enregistrée pour être refaite après un import WinDev (mode transition).
 */
final class Fusion
{
    /** Champs à choisir quand les deux fiches diffèrent. */
    public const CHAMPS = [
        'nom' => 'Nom', 'prenom' => 'Prénom', 'date_naissance' => 'Date de naissance', 'genre' => 'Genre',
        'mail' => 'Mail', 'telephone1' => 'Téléphone 1', 'telephone2' => 'Téléphone 2',
        'adresse' => 'Adresse', 'code_postal' => 'Code postal', 'ville' => 'Ville',
        'nom_parent' => 'Nom du parent', 'prenom_parent' => 'Prénom du parent', 'licence' => 'Licence',
    ];

    /** Cases cochées sur l'une ou l'autre fiche : gardées (un refus de mail ou de SMS ne se perd pas). */
    private const OU = ['membre_ca', 'attestation_ce', 'refus_mail', 'refus_sms', 'refus_whatsapp'];

    /** Repris de l'autre fiche seulement s'ils sont vides sur la fiche gardée. */
    private const SI_VIDE = ['canal_autre', 'num_monclub', 'reche_sportyneo', 'distance'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    private function fiche(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM adherent WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function affiche(string $champ, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return match ($champ) {
            'genre' => (int) $v === 0 ? 'Femme' : 'Homme',
            'date_naissance' => preg_match('/^(\d{4})-(\d\d)-(\d\d)/', (string) $v, $m) ? "$m[3]/$m[2]/$m[1]" : (string) $v,
            default => (string) $v,
        };
    }

    /**
     * Comparaison des deux fiches pour l'écran de fusion.
     * @return ?array{garde:array,autre:array,champs:list<array{champ:string,libelle:string,garde:string,autre:string,differe:bool,defaut:string}>,inscriptions_garde:list<array>,inscriptions_autre:list<array>,doubles:list<array>}
     */
    public function comparer(int $gardeId, int $autreId): ?array
    {
        $g = $this->fiche($gardeId);
        $a = $this->fiche($autreId);
        if (!$g || !$a || $gardeId === $autreId) {
            return null;
        }
        $champs = [];
        foreach (self::CHAMPS as $c => $lib) {
            $vg = self::affiche($c, $g[$c]);
            $va = self::affiche($c, $a[$c]);
            $champs[] = ['champ' => $c, 'libelle' => $lib, 'garde' => $vg, 'autre' => $va,
                'differe' => $vg !== $va,
                'defaut' => $vg === '' && $va !== '' ? 'autre' : 'garde'];
        }
        $insc = new Inscriptions($this->pdo);
        return ['garde' => $g, 'autre' => $a, 'champs' => $champs,
            'inscriptions_garde' => $insc->deAdherent($gardeId), 'inscriptions_autre' => $insc->deAdherent($autreId),
            'doubles' => $this->doubles($gardeId, $autreId)];
    }

    /** Inscriptions des deux fiches à la même section la même saison. @return list<array{garde:int,autre:int,section:string,saison:string}> */
    public function doubles(int $gardeId, int $autreId): array
    {
        $st = $this->pdo->prepare('SELECT g.id AS garde, a.id AS autre, s.titre AS section, sa.titre AS saison
            FROM inscription g JOIN inscription a ON a.section_id = g.section_id AND a.saison_id = g.saison_id
            JOIN section s ON s.id = g.section_id JOIN saison sa ON sa.id = g.saison_id
            WHERE g.adherent_id = ? AND a.adherent_id = ?');
        $st->execute([$gardeId, $autreId]);
        return $st->fetchAll();
    }

    /**
     * @param array<string,string> $choix champ => 'garde' | 'autre' (les champs absents gardent la valeur de la fiche gardée)
     * @return array{ok:bool,message:string,inscriptions:int,regroupees:int}
     */
    public function fusionner(int $gardeId, int $autreId, array $choix, string $par, bool $rejeu = false): array
    {
        $g = $this->fiche($gardeId);
        $a = $this->fiche($autreId);
        if (!$g || !$a || $gardeId === $autreId) {
            return ['ok' => false, 'message' => 'Une des deux fiches n\'existe plus.', 'inscriptions' => 0, 'regroupees' => 0];
        }
        $choix = array_intersect_key(array_map('strval', $choix), self::CHAMPS);
        $propre = !$this->pdo->inTransaction();
        if ($propre) {
            $this->pdo->beginTransaction();
        }
        try {
            // 1. Fiche gardée : valeurs choisies, cases cochées de l'une ou l'autre, compléments
            $maj = [];
            foreach (self::CHAMPS as $c => $_) {
                if (($choix[$c] ?? 'garde') === 'autre') {
                    $maj[$c] = $a[$c];
                }
            }
            if (array_key_exists('mail', $maj)) {
                $maj['adresse_mail_invalide'] = $a['adresse_mail_invalide'];
            }
            foreach (self::OU as $c) {
                if ((int) $a[$c] === 1 && (int) $g[$c] === 0) {
                    $maj[$c] = 1;
                }
            }
            foreach (self::SI_VIDE as $c) {
                if (($g[$c] === null || $g[$c] === '') && $a[$c] !== null && $a[$c] !== '') {
                    $maj[$c] = $a[$c];
                }
            }
            $rg = trim((string) $g['remarques']);
            $ra = trim((string) $a['remarques']);
            if ($ra !== '' && !str_contains($rg, $ra)) {
                $maj['remarques'] = trim($rg . "\n" . $ra);
            }
            if ($maj) {
                $maj['date_modification'] = date('Y-m-d H:i:s');
                $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($maj)));
                $this->pdo->prepare("UPDATE adherent SET $set WHERE id = ?")->execute([...array_values($maj), $gardeId]);
            }

            // 2. Canaux (comment la personne a connu l'association) : réunis
            $st = $this->pdo->prepare('SELECT canal_id FROM adherent_canal WHERE adherent_id = ?');
            $st->execute([$gardeId]);
            $deja = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st->execute([$autreId]);
            foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $c) {
                if (!in_array($c, $deja, true)) {
                    $this->pdo->prepare('INSERT INTO adherent_canal (adherent_id, canal_id) VALUES (?,?)')->execute([$gardeId, $c]);
                }
            }
            $this->pdo->prepare('DELETE FROM adherent_canal WHERE adherent_id = ?')->execute([$autreId]);

            // 3. Inscriptions en double : règlements rattachés à l'inscription gardée, l'autre supprimée
            $regroupees = 0;
            foreach ($this->doubles($gardeId, $autreId) as $d) {
                $this->remplacerInscription((int) $d['autre'], (int) $d['garde']);
                $regroupees++;
            }
            // 4. Autres inscriptions : passent sur la fiche gardée
            $st = $this->pdo->prepare('UPDATE inscription SET adherent_id = ?, date_modification = ? WHERE adherent_id = ?');
            $st->execute([$gardeId, date('Y-m-d H:i:s'), $autreId]);
            $deplacees = $st->rowCount();

            // 5. Liens de l'application vers l'ancienne fiche (HelloAsso, demandes en ligne), choix rejoués compris
            $this->pdo->prepare('UPDATE helloasso_item SET adherent_id = ? WHERE adherent_id = ?')->execute([$gardeId, $autreId]);
            $this->pdo->prepare('UPDATE demande SET adherent_id = ? WHERE adherent_id = ?')->execute([$gardeId, $autreId]);
            foreach (['helloasso_item', 'demande'] as $t) {
                foreach ($this->pdo->query("SELECT id, choix FROM $t WHERE choix IS NOT NULL")->fetchAll() as $r) {
                    $c = json_decode((string) $r['choix'], true);
                    if (is_array($c) && (string) ($c['adherent'] ?? '') === (string) $autreId) {
                        $c['adherent'] = (string) $gardeId;
                        $this->pdo->prepare("UPDATE $t SET choix = ? WHERE id = ?")->execute([json_encode($c, JSON_UNESCAPED_UNICODE), $r['id']]);
                    }
                }
            }

            // Présences notées par les animateurs (une séance notée sur les deux fiches : on garde « présent » si l'une l'est)
            $st = $this->pdo->prepare('SELECT id, section_id, saison_id, date_seance, present FROM presence WHERE adherent_id = ?');
            $st->execute([$autreId]);
            foreach ($st->fetchAll() as $pr) {
                $q = $this->pdo->prepare('SELECT id FROM presence WHERE adherent_id = ? AND section_id = ? AND saison_id = ? AND date_seance = ?');
                $q->execute([$gardeId, $pr['section_id'], $pr['saison_id'], $pr['date_seance']]);
                $deja = $q->fetchColumn();
                if ($deja) {
                    if ((int) $pr['present'] === 1) {
                        $this->pdo->prepare('UPDATE presence SET present = 1 WHERE id = ?')->execute([$deja]);
                    }
                    $this->pdo->prepare('DELETE FROM presence WHERE id = ?')->execute([$pr['id']]);
                } else {
                    $this->pdo->prepare('UPDATE presence SET adherent_id = ? WHERE id = ?')->execute([$gardeId, $pr['id']]);
                }
            }

            $this->pdo->prepare('UPDATE marque_adherent SET adherent_id = ? WHERE adherent_id = ?')->execute([$gardeId, $autreId]);
            $this->pdo->prepare('UPDATE campagne_destinataire SET adherent_id = ? WHERE adherent_id = ?')->execute([$gardeId, $autreId]);

            // 6. Suppression de l'autre fiche et trace de la fusion
            $this->pdo->prepare('DELETE FROM adherent WHERE id = ?')->execute([$autreId]);
            if (!$rejeu) {
                $this->pdo->prepare('INSERT INTO fusion (date_fusion, garde_id, fusionne_id, nom, choix, par) VALUES (?,?,?,?,?,?)')
                    ->execute([date('Y-m-d H:i:s'), $gardeId, $autreId, mb_substr(trim($a['nom'] . ' ' . $a['prenom']), 0, 200),
                        json_encode($choix, JSON_UNESCAPED_UNICODE), mb_substr($par, 0, 150)]);
            }
            if ($propre) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($propre) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $n = $deplacees + $regroupees;
        return ['ok' => true, 'inscriptions' => $n, 'regroupees' => $regroupees,
            'message' => 'Fiches fusionnées : ' . $n . ' inscription(s) reprise(s)' . ($regroupees ? ', dont ' . $regroupees . ' regroupée(s) avec une inscription identique' : '') . '.'];
    }

    /** Inscription en double : ses règlements et liens passent sur l'inscription gardée, puis elle est supprimée. */
    private function remplacerInscription(int $ancienne, int $nouvelle): void
    {
        $st = $this->pdo->prepare('SELECT remarques FROM inscription WHERE id = ?');
        $st->execute([$ancienne]);
        $ra = trim((string) $st->fetchColumn());
        $st->execute([$nouvelle]);
        $rn = trim((string) $st->fetchColumn());
        if ($ra !== '' && !str_contains($rn, $ra)) {
            $this->pdo->prepare('UPDATE inscription SET remarques = ? WHERE id = ?')->execute([trim($rn . "\n" . $ra), $nouvelle]);
        }
        $this->pdo->prepare('UPDATE reglement SET inscription_id = ? WHERE inscription_id = ?')->execute([$nouvelle, $ancienne]);
        $this->pdo->prepare('UPDATE helloasso_item SET inscription_id = ? WHERE inscription_id = ?')->execute([$nouvelle, $ancienne]);
        $this->pdo->prepare('UPDATE envoi_mail SET inscription_id = ? WHERE inscription_id = ?')->execute([$nouvelle, $ancienne]);
        foreach ($this->pdo->query('SELECT id, inscriptions FROM demande WHERE inscriptions IS NOT NULL')->fetchAll() as $d) {
            $ids = json_decode((string) $d['inscriptions'], true);
            if (is_array($ids) && in_array($ancienne, array_map('intval', $ids), true)) {
                $ids = array_values(array_unique(array_map(static fn ($i) => (int) $i === $ancienne ? $nouvelle : (int) $i, $ids)));
                $this->pdo->prepare('UPDATE demande SET inscriptions = ? WHERE id = ?')->execute([json_encode($ids), $d['id']]);
            }
        }
        $this->pdo->prepare('DELETE FROM inscription WHERE id = ?')->execute([$ancienne]);
    }

    /**
     * Mode transition : après un import WinDev, les fiches fusionnées dans l'application reviennent.
     * On refait chaque fusion dont les deux fiches existent de nouveau (sinon elle a été faite aussi dans WinDev).
     * @return array{refaites:int,deja:int}
     */
    public function rejouerApresImport(): array
    {
        $r = ['refaites' => 0, 'deja' => 0];
        foreach ($this->pdo->query('SELECT * FROM fusion ORDER BY id')->fetchAll() as $f) {
            if ($this->fiche((int) $f['garde_id']) && $this->fiche((int) $f['fusionne_id'])) {
                $res = $this->fusionner((int) $f['garde_id'], (int) $f['fusionne_id'], json_decode((string) $f['choix'], true) ?: [], (string) $f['par'], true);
                $res['ok'] ? $r['refaites']++ : null;
            } else {
                $r['deja']++;
            }
        }
        return $r;
    }

    /** @return list<array<string,mixed>> */
    public function historique(int $limite = 50): array
    {
        return $this->pdo->query('SELECT * FROM fusion ORDER BY id DESC LIMIT ' . (int) $limite)->fetchAll();
    }
}
