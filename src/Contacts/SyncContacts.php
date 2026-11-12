<?php
declare(strict_types=1);

namespace Ascs\Contacts;

use Ascs\Config;
use Ascs\Domain\Presences;
use Ascs\Parametres;
use PDO;
use RuntimeException;

/**
 * Synchronisation des adhérents vers un dossier de contacts « ASCS » de la boîte Microsoft 365 de l'association.
 * Outlook (PC, web, téléphone) le voit ; l'application Outlook du téléphone peut le recopier dans les contacts Android.
 * Seuls les contacts créés par l'application sont modifiés ou supprimés ; le reste de la boîte n'est jamais touché.
 */
final class SyncContacts
{
    public const DOSSIER = 'ASCS';
    private const PAR_LOT = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function configure(): bool
    {
        return Graph::depuisConfig() !== null;
    }

    /** 1 = saison en cours, 2 = saison en cours et précédente. */
    public function saisons(): int
    {
        return Parametres::get($this->pdo, 'contacts_saisons') === '2' ? 2 : 1;
    }

    public function regler(int $saisons): void
    {
        Parametres::set($this->pdo, 'contacts_saisons', $saisons === 2 ? '2' : '1');
    }

    /** @return array{quand:?string,resume:?string,ok:bool} */
    public function derniere(): array
    {
        $d = json_decode((string) Parametres::get($this->pdo, 'contacts_derniere_sync'), true);
        return is_array($d) ? $d + ['quand' => null, 'resume' => null, 'ok' => false] : ['quand' => null, 'resume' => null, 'ok' => false];
    }

    /**
     * Contacts voulus : adhérents inscrits sur la ou les saisons retenues, avec leurs sections.
     * @return array<int,array<string,mixed>> par adhérent : charge utile Graph
     */
    public function voulus(): array
    {
        $cour = (new Presences($this->pdo))->saisonCourante();
        if (!$cour) {
            return [];
        }
        $ids = [(int) $cour['id']];
        if ($this->saisons() === 2) {
            $st = $this->pdo->prepare('SELECT id FROM saison WHERE date_debut < ? ORDER BY date_debut DESC LIMIT 1');
            $st->execute([$cour['date_debut']]);
            if ($p = $st->fetchColumn()) {
                $ids[] = (int) $p;
            }
        }
        $st = $this->pdo->query('SELECT a.*, s.titre AS section, sa.titre AS saison, i.saison_id FROM inscription i JOIN adherent a ON a.id = i.adherent_id
            JOIN section s ON s.id = i.section_id JOIN saison sa ON sa.id = i.saison_id WHERE i.saison_id IN (' . implode(',', $ids) . ') ORDER BY a.id, sa.date_debut DESC, s.titre');
        $par = [];
        foreach ($st->fetchAll() as $r) {
            $id = (int) $r['id'];
            $par[$id] ??= ['a' => $r, 'cours' => [], 'anciens' => []];
            if ((int) $r['saison_id'] === (int) $cour['id']) {
                $par[$id]['cours'][$r['section']] = true;
            } else {
                $par[$id]['anciens'][$r['section'] . ' (' . $r['saison'] . ')'] = true;
            }
        }
        $out = [];
        foreach ($par as $id => $p) {
            $out[$id] = self::charge($p['a'], array_keys($p['cours']), array_keys($p['anciens']), (string) $cour['titre']);
        }
        return $out;
    }

    /** Contact Outlook d'un adhérent. */
    private static function charge(array $a, array $cours, array $anciens, string $saison): array
    {
        $nom = mb_strtoupper(trim((string) $a['nom']));
        $prenom = trim((string) $a['prenom']);
        $sections = $cours ?: $anciens;
        $c = [
            'givenName' => $prenom, 'surname' => $nom, 'displayName' => trim($nom . ' ' . $prenom), 'fileAs' => trim($nom . ' ' . $prenom),
            'companyName' => 'ASCS', 'department' => mb_substr(implode(', ', $sections), 0, 250),
            'categories' => array_values(array_unique(array_merge(['ASCS'], array_map(static fn ($s) => mb_substr(preg_replace('/ \(\d{4}-\d{4}\)$/', '', $s), 0, 250), $sections)))),
            'emailAddresses' => [], 'homePhones' => [], 'mobilePhone' => null, 'businessPhones' => [],
            'homeAddress' => (object) [],
        ];
        if (filter_var(trim((string) $a['mail']), FILTER_VALIDATE_EMAIL)) {
            $c['emailAddresses'][] = ['address' => trim((string) $a['mail']), 'name' => $c['displayName']];
        }
        $tels = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), [$a['telephone1'], $a['telephone2']])));
        foreach ($tels as $t) {
            if ($c['mobilePhone'] === null && preg_match('/^(\+33\s?[67]|0[67])/', preg_replace('/[\s.]/', '', $t))) {
                $c['mobilePhone'] = $t;
            } else {
                $c['homePhones'][] = $t;
            }
        }
        if (trim((string) $a['adresse']) !== '' || trim((string) $a['ville']) !== '') {
            $c['homeAddress'] = ['street' => trim((string) $a['adresse']), 'postalCode' => trim((string) $a['code_postal']), 'city' => trim((string) $a['ville']), 'countryOrRegion' => 'France'];
        }
        $notes = 'Adhérent ASCS n° ' . $a['id'] . ($cours ? ' – ' . $saison . ' : ' . implode(', ', $cours) : '') . ($anciens ? ' – avant : ' . implode(', ', $anciens) : '');
        if (trim((string) $a['nom_parent'] . (string) $a['prenom_parent']) !== '') {
            $notes .= "\nParent : " . trim($a['prenom_parent'] . ' ' . $a['nom_parent']);
        }
        $c['personalNotes'] = $notes . "\nContact tenu à jour par gestion.ascs.fr : les modifications faites ici seront remplacées.";
        return $c;
    }

    /**
     * Synchronise (par lots, reprend là où elle s'est arrêtée). $max = nombre d'opérations au plus pour ce passage.
     * @return array{crees:int,modifies:int,supprimes:int,inchanges:int,restants:int,erreurs:list<string>}
     */
    public function executer(?Graph $g = null, int $max = 600): array
    {
        $g ??= Graph::depuisConfig();
        if (!$g) {
            throw new RuntimeException('Microsoft 365 n\'est pas configuré (lignes M365_ du fichier .env).');
        }
        $boite = rawurlencode(Config::load()['m365_boite']);
        $r = ['crees' => 0, 'modifies' => 0, 'supprimes' => 0, 'inchanges' => 0, 'restants' => 0, 'erreurs' => []];
        try {
            $dossier = $this->dossier($g, $boite);
            $voulus = $this->voulus();
            $connus = [];
            foreach ($this->pdo->query('SELECT adherent_id, graph_id, empreinte FROM contact_sync')->fetchAll() as $c) {
                $connus[(int) $c['adherent_id']] = $c;
            }
            // Opérations à faire
            $ops = [];
            foreach ($voulus as $id => $charge) {
                $emp = md5((string) json_encode($charge));
                if (!isset($connus[$id])) {
                    $ops[] = ['creer', $id, $charge, $emp];
                } elseif ($connus[$id]['empreinte'] !== $emp) {
                    $ops[] = ['modifier', $id, $charge, $emp, $connus[$id]['graph_id']];
                } else {
                    $r['inchanges']++;
                }
            }
            foreach ($connus as $id => $c) {
                if (!isset($voulus[$id])) {
                    $ops[] = ['supprimer', $id, null, null, $c['graph_id']];
                }
            }
            $r['restants'] = max(0, count($ops) - $max);
            foreach (array_chunk(array_slice($ops, 0, $max), self::PAR_LOT) as $lot) {
                $this->lot($g, $boite, $dossier, $lot, $r);
            }
        } catch (\Throwable $e) {
            $r['erreurs'][] = $e->getMessage();
        }
        $resume = sprintf('%d créé(s), %d mis à jour, %d retiré(s), %d inchangé(s)', $r['crees'], $r['modifies'], $r['supprimes'], $r['inchanges'])
            . ($r['restants'] ? sprintf(', %d restant(s) pour le prochain passage', $r['restants']) : '') . ($r['erreurs'] ? ' — ' . count($r['erreurs']) . ' erreur(s) : ' . $r['erreurs'][0] : '');
        Parametres::set($this->pdo, 'contacts_derniere_sync', (string) json_encode(['quand' => date('Y-m-d H:i:s'), 'resume' => $resume, 'ok' => !$r['erreurs']], JSON_UNESCAPED_UNICODE));
        return $r;
    }

    /** Dossier « ASCS » de la boîte (créé s'il n'existe pas ; s'il a été supprimé dans Outlook, les contacts sont recréés). */
    private function dossier(Graph $g, string $boite): string
    {
        $id = (string) Parametres::get($this->pdo, 'contacts_dossier');
        if ($id !== '') {
            [$code] = $g->appel('GET', "/users/$boite/contactFolders/" . rawurlencode($id) . '?$select=id');
            if ($code === 200) {
                return $id;
            }
            if ($code !== 404) {
                throw new RuntimeException('Dossier de contacts inaccessible (code ' . $code . ') : vérifiez M365_BOITE et le droit Contacts.ReadWrite.');
            }
        }
        [$code, $rep] = $g->appel('GET', "/users/$boite/contactFolders?\$top=100&\$select=id,displayName");
        if ($code !== 200) {
            throw new RuntimeException(self::erreur('Lecture des dossiers de contacts', $code, $rep));
        }
        $trouve = null;
        foreach ($rep['value'] ?? [] as $f) {
            if (($f['displayName'] ?? '') === self::DOSSIER) {
                $trouve = (string) $f['id'];
            }
        }
        if ($trouve === null) {
            [$code, $rep] = $g->appel('POST', "/users/$boite/contactFolders", ['displayName' => self::DOSSIER]);
            if ($code !== 201) {
                throw new RuntimeException(self::erreur('Création du dossier ASCS', $code, $rep));
            }
            $trouve = (string) $rep['id'];
        }
        if ($trouve !== $id) {
            // Nouveau dossier : les anciens identifiants de contacts ne valent plus rien
            $this->pdo->exec('DELETE FROM contact_sync');
        }
        Parametres::set($this->pdo, 'contacts_dossier', $trouve);
        return $trouve;
    }

    private function lot(Graph $g, string $boite, string $dossier, array $lot, array &$r): void
    {
        $req = [];
        foreach ($lot as $k => $op) {
            [$type, , $charge] = $op;
            $req[] = match ($type) {
                'creer' => ['id' => (string) $k, 'method' => 'POST', 'url' => "/users/$boite/contactFolders/" . rawurlencode($dossier) . '/contacts', 'headers' => ['Content-Type' => 'application/json'], 'body' => $charge],
                'modifier' => ['id' => (string) $k, 'method' => 'PATCH', 'url' => "/users/$boite/contacts/" . rawurlencode((string) $op[4]), 'headers' => ['Content-Type' => 'application/json'], 'body' => $charge],
                'supprimer' => ['id' => (string) $k, 'method' => 'DELETE', 'url' => "/users/$boite/contacts/" . rawurlencode((string) $op[4])],
            };
        }
        [$code, $rep] = $g->appel('POST', '/$batch', ['requests' => $req]);
        if ($code !== 200) {
            throw new RuntimeException(self::erreur('Envoi groupé', $code, $rep));
        }
        $maj = $this->pdo->prepare('REPLACE INTO contact_sync (adherent_id, graph_id, empreinte, maj_le) VALUES (?, ?, ?, ?)');
        $sup = $this->pdo->prepare('DELETE FROM contact_sync WHERE adherent_id = ?');
        foreach ($rep['responses'] ?? [] as $x) {
            $op = $lot[(int) $x['id']] ?? null;
            if (!$op) {
                continue;
            }
            [$type, $id, , $emp] = $op + [3 => null];
            $st = (int) ($x['status'] ?? 0);
            if ($type === 'creer' && $st === 201) {
                $maj->execute([$id, (string) $x['body']['id'], $emp, date('Y-m-d H:i:s')]);
                $r['crees']++;
            } elseif ($type === 'modifier' && $st === 200) {
                $maj->execute([$id, (string) $op[4], $emp, date('Y-m-d H:i:s')]);
                $r['modifies']++;
            } elseif ($type === 'modifier' && $st === 404) {
                // Supprimé à la main dans Outlook : recréé au prochain passage
                $sup->execute([$id]);
                $r['restants']++;
            } elseif ($type === 'supprimer' && in_array($st, [204, 404], true)) {
                $sup->execute([$id]);
                $r['supprimes']++;
            } elseif ($st === 429 || $st >= 500) {
                $r['restants']++;
            } else {
                $r['erreurs'][] = 'Adhérent ' . $id . ' : ' . ($x['body']['error']['message'] ?? 'code ' . $st);
            }
        }
    }

    private static function erreur(string $quoi, int $code, array $rep): string
    {
        $m = $rep['error']['message'] ?? '';
        $aide = $code === 403 ? ' L\'application n\'a pas le droit Contacts.ReadWrite (application) avec consentement administrateur.' : ($code === 404 ? ' Boîte M365_BOITE introuvable.' : '');
        return $quoi . ' : code ' . $code . ($m !== '' ? ' (' . $m . ')' : '') . '.' . $aide;
    }
}
