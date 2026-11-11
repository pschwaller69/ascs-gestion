<?php
declare(strict_types=1);

namespace Ascs;

/**
 * Définition unique du schéma. Le DDL est généré pour MariaDB (production o2switch)
 * et pour SQLite (tests locaux) à partir de cette même description.
 *
 * Types : int, smallint, bool, str:N, text, date, datetime, time, dec:P,S, uuid.
 * Suffixe "?" = colonne NULL autorisée (sinon NOT NULL).
 * Les tables importées gardent les identifiants d'origine WinDev (clé primaire non auto-incrémentée),
 * ce qui permet de rejouer l'import complet autant de fois que nécessaire.
 */
final class Schema
{
    /** @return array<string,array<string,mixed>> */
    /** Empreinte du schéma connu par le programme : change dès qu'une table ou une colonne est ajoutée. */
    public static function empreinte(): string
    {
        return substr(md5((string) json_encode(self::tables())), 0, 16);
    }

    public static function tables(): array
    {
        $dates = ['date_creation' => 'datetime?', 'date_modification' => 'datetime?'];

        return [
            'saison' => [
                'cols' => ['id' => 'int', 'titre' => 'str:20', 'date_debut' => 'date?', 'date_fin' => 'date?'],
                'pk' => ['id'],
            ],
            'famille_section' => [
                'cols' => ['id' => 'int', 'titre' => 'str:50'],
                'pk' => ['id'],
            ],
            'section_principale' => [
                'cols' => [
                    'id' => 'int', 'titre' => 'str:80', 'famille_section_id' => 'int?',
                    'code' => 'str:10?', 'montant_rentabilite_annuel' => 'dec:10,2?',
                ],
                'pk' => ['id'],
                'fk' => ['famille_section_id' => 'famille_section'],
            ],
            'section' => [
                'cols' => [
                    'id' => 'int', 'titre' => 'str:80', 'montant' => 'dec:8,2',
                    'section_principale_id' => 'int?', 'essai' => 'bool',
                    'nom_produit_sportyneo' => 'str:120?', 'nom_campagne_helloasso' => 'str:120?',
                    'nom_saison_monclub' => 'str:120?', 'mail_animateur1' => 'str:150?',
                    'montant_rentabilite_annuel' => 'dec:10,2?', 'lien_animateurs' => 'str:255?',
                    // identifiant non devinable pour la page de présence des animateurs (sans mot de passe)
                    'presence_token' => 'uuid',
                ],
                'pk' => ['id'],
                'fk' => ['section_principale_id' => 'section_principale'],
                'unique' => [['presence_token']],
            ],
            // Jour 1=lundi … 7=dimanche ; ordre 1/2 = heure de début du 1er / 2e cours de la journée (colonnes H1/H2 de WinDev).
            'creneau' => [
                'cols' => ['id' => 'auto', 'section_id' => 'int', 'jour' => 'smallint', 'ordre' => 'smallint', 'heure_debut' => 'time?'],
                'pk' => ['id'],
                'fk' => ['section_id' => 'section'],
                'unique' => [['section_id', 'jour', 'ordre']],
            ],
            'adherent' => [
                'cols' => [
                    'id' => 'auto', 'mail' => 'str:150?', 'nom' => 'str:100?', 'prenom' => 'str:100?',
                    'nom_parent' => 'str:100?', 'prenom_parent' => 'str:100?', 'adresse' => 'str:255?',
                    'code_postal' => 'str:10?', 'ville' => 'str:100?', 'date_naissance' => 'date?',
                    'genre' => 'smallint', 'telephone1' => 'str:30?', 'telephone2' => 'str:30?',
                    'attestation_ce' => 'bool', 'membre_ca' => 'bool', 'canal_autre' => 'str:255?',
                    'remarques' => 'text?', 'adresse_mail_invalide' => 'bool', 'refus_mail' => 'bool',
                    'refus_sms' => 'bool', 'refus_whatsapp' => 'bool', 'num_monclub' => 'int?',
                    'reche_sportyneo' => 'str:120?', 'licence' => 'str:20?', 'distance' => 'dec:10,2?',
                ] + $dates,
                'pk' => ['id'],
                'index' => [['nom', 'prenom']],
            ],
            'canal' => [
                'cols' => ['id' => 'int', 'code' => 'str:30', 'libelle' => 'str:60'],
                'pk' => ['id'],
                'unique' => [['code']],
            ],
            'adherent_canal' => [
                'cols' => ['adherent_id' => 'int', 'canal_id' => 'int'],
                'pk' => ['adherent_id', 'canal_id'],
                'fk' => ['adherent_id' => 'adherent', 'canal_id' => 'canal'],
            ],
            'inscription' => [
                'cols' => [
                    'id' => 'auto', 'adherent_id' => 'int', 'section_id' => 'int', 'saison_id' => 'int',
                    'date_inscription' => 'date?', 'fait_a' => 'str:60?', 'montant' => 'dec:8,2',
                    'remarques' => 'text?', 'identifiant' => 'str:30?',
                ] + $dates,
                'pk' => ['id'],
                'fk' => ['adherent_id' => 'adherent', 'section_id' => 'section', 'saison_id' => 'saison'],
                'unique' => [['adherent_id', 'section_id', 'saison_id']],
                'index' => [['saison_id']],
            ],
            'mode_reglement' => [
                'cols' => ['id' => 'int', 'libelle' => 'str:40'],
                'pk' => ['id'],
            ],
            'reglement' => [
                'cols' => [
                    'id' => 'auto', 'inscription_id' => 'int', 'mode_reglement_id' => 'int',
                    'montant' => 'dec:8,2', 'identifiant' => 'str:60?', 'remarques' => 'text?',
                    'date_reglement' => 'date?', 'date_saisie' => 'datetime?', 'date_modification' => 'datetime?',
                ],
                'pk' => ['id'],
                'fk' => ['inscription_id' => 'inscription', 'mode_reglement_id' => 'mode_reglement'],
                'index' => [['inscription_id'], ['date_reglement']],
            ],
            'type_compte' => [
                'cols' => ['id' => 'int', 'libelle' => 'str:50'],
                'pk' => ['id'],
            ],
            'compte' => [
                'cols' => [
                    'id' => 'int', 'saison_id' => 'int', 'date_operation' => 'date', 'date_valeur' => 'date?',
                    'debit' => 'dec:10,2', 'credit' => 'dec:10,2', 'valeur' => 'dec:10,2', 'solde' => 'dec:12,2?',
                    'numero_compte' => 'str:20', 'libelle' => 'str:255', 'observation' => 'str:255?',
                    'ligne_import' => 'str:500?', 'lien' => 'str:255?', 'virement_interne' => 'bool',
                ] + $dates,
                'pk' => ['id'],
                'fk' => ['saison_id' => 'saison'],
                'index' => [['date_operation'], ['numero_compte'], ['saison_id']],
            ],
            'detail_compte' => [
                'cols' => [
                    'id' => 'int', 'compte_id' => 'int', 'section_principale_id' => 'int?', 'type_compte_id' => 'int?',
                    'debit' => 'dec:10,2', 'credit' => 'dec:10,2', 'valeur' => 'dec:10,2', 'libelle' => 'str:255',
                    'observation' => 'str:255?', 'traite_auto' => 'bool', 'lien' => 'str:255?',
                ] + $dates,
                'pk' => ['id'],
                'fk' => [
                    'compte_id' => 'compte', 'section_principale_id' => 'section_principale',
                    'type_compte_id' => 'type_compte',
                ],
                'index' => [['compte_id']],
            ],
            // --- Application web ---------------------------------------------------------------
            'utilisateur' => [
                'cols' => [
                    'id' => 'auto', 'email' => 'str:150', 'nom' => 'str:100', 'mot_de_passe' => 'str:255',
                    'actif' => 'bool', 'derniere_connexion' => 'datetime?', 'cree_le' => 'datetime',
                ],
                'pk' => ['id'],
                'unique' => [['email']],
            ],
            'tentative_connexion' => [
                'cols' => ['id' => 'auto', 'email' => 'str:150', 'ip' => 'str:45', 'date_tentative' => 'datetime'],
                'pk' => ['id'],
                'index' => [['email', 'date_tentative'], ['ip', 'date_tentative']],
            ],
            'parametre' => [
                'cols' => ['cle' => 'str:50', 'valeur' => 'text?'],
                'pk' => ['cle'],
            ],
            'import_run' => [
                'cols' => ['id' => 'auto', 'debut' => 'datetime', 'fin' => 'datetime?', 'resume' => 'text?'],
                'pk' => ['id'],
            ],
            // --- Remises de chèques ------------------------------------------------------------
            // 30 chèques au plus par remise (règle de la banque). Les chèques sont recopiés dans remise_cheque :
            // le bordereau reste exact même si un import WinDev modifie les règlements (mode transition).
            'remise' => [
                'cols' => [
                    'id' => 'auto', 'date_pose' => 'date', 'date_depot' => 'date?', 'numero' => 'str:20?', 'numero_compte' => 'str:20?',
                    'cree_le' => 'datetime', 'cree_par' => 'str:150', 'compte_id' => 'int?', 'etat_banque' => 'str:20',
                    'ventilee_le' => 'datetime?',
                ],
                'pk' => ['id'],
                'index' => [['date_pose'], ['numero']],
            ],
            'remise_cheque' => [
                'cols' => [
                    'id' => 'auto', 'remise_id' => 'int', 'reglement_id' => 'int', 'section_principale_id' => 'int?', 'section' => 'str:80',
                    'nom' => 'str:100?', 'prenom' => 'str:100?', 'nom_parent' => 'str:100?', 'prenom_parent' => 'str:100?',
                    'numero_cheque' => 'str:60?', 'montant' => 'dec:8,2', 'date_reglement' => 'date?',
                ],
                'pk' => ['id'],
                'fk' => ['remise_id' => 'remise'],
                'unique' => [['reglement_id']],
                'index' => [['remise_id']],
            ],
            // --- Inscriptions et essais en ligne (formulaires publics) ---------------------------
            // Pas de clé étrangère vers section / adherent : l'import WinDev peut les modifier (mode transition).
            'section_en_ligne' => [
                'cols' => ['section_id' => 'int', 'ouvert' => 'bool', 'description' => 'text?', 'lien_paiement' => 'str:255?'],
                'pk' => ['section_id'],
            ],
            'demande' => [
                'cols' => [
                    'id' => 'auto', 'reference' => 'str:12', 'type' => 'str:12', 'cree_le' => 'datetime', 'ip' => 'str:45',
                    'statut' => 'str:12', 'saison_id' => 'int', 'sections' => 'text', 'date_essai' => 'date?',
                    'nom' => 'str:100', 'prenom' => 'str:100', 'genre' => 'smallint', 'date_naissance' => 'date',
                    'mail' => 'str:150', 'telephone' => 'str:30', 'adresse' => 'str:255?', 'code_postal' => 'str:10?',
                    'ville' => 'str:100?', 'nom_parent' => 'str:100?', 'prenom_parent' => 'str:100?', 'canal_id' => 'int?',
                    'droit_image' => 'bool?', 'remarques' => 'text?',
                    'adherent_id' => 'int?', 'inscriptions' => 'text?', 'choix' => 'text?', 'traite_le' => 'datetime?', 'traite_par' => 'str:150?',
                ],
                'pk' => ['id'],
                'unique' => [['reference']],
                'index' => [['statut'], ['ip', 'cree_le']],
            ],
            // --- HelloAsso (API v5) ------------------------------------------------------------
            // Pas de clé étrangère vers adherent / inscription / reglement : pendant la transition,
            // l'import WinDev supprime les saisies web ; les liens sont revérifiés à chaque synchronisation.
            'helloasso_item' => [
                'cols' => [
                    'id' => 'int', 'commande_id' => 'int', 'formulaire' => 'str:150', 'type_formulaire' => 'str:30',
                    'tarif' => 'str:255?', 'tarif_id' => 'int?', 'date_commande' => 'datetime', 'etat' => 'str:30',
                    'montant' => 'dec:10,2', 'options' => 'dec:10,2', 'remise' => 'dec:10,2', 'detail_options' => 'text?',
                    'nom' => 'str:100?', 'prenom' => 'str:100?', 'date_naissance' => 'date?', 'telephone' => 'str:30?',
                    'mail' => 'str:150?', 'payeur_nom' => 'str:100?', 'payeur_prenom' => 'str:100?', 'payeur_mail' => 'str:150?',
                    'adresse' => 'str:255?', 'code_postal' => 'str:10?', 'ville' => 'str:100?', 'champs' => 'text?',
                    'statut' => 'str:20', 'adherent_id' => 'int?', 'inscription_id' => 'int?', 'traite_le' => 'datetime?',
                    'traite_par' => 'str:150?', 'choix' => 'text?', 'recu_le' => 'datetime', 'maj_le' => 'datetime', 'brut' => 'text',
                ],
                'pk' => ['id'],
                'index' => [['statut'], ['commande_id'], ['formulaire', 'tarif']],
            ],
            'helloasso_paiement' => [
                'cols' => [
                    'id' => 'int', 'item_id' => 'int', 'commande_id' => 'int', 'montant' => 'dec:10,2', 'date_paiement' => 'datetime?',
                    'etat' => 'str:30', 'echeance' => 'int?', 'moyen' => 'str:30?', 'reglement_id' => 'int?', 'maj_le' => 'datetime',
                ],
                'pk' => ['id', 'item_id'],
                'index' => [['item_id']],
            ],
            // Formulaire + tarif HelloAsso -> section, retenu à la première validation
            'helloasso_section' => [
                'cols' => ['formulaire' => 'str:150', 'tarif' => 'str:255', 'section_id' => 'int'],
                'pk' => ['formulaire', 'tarif'],
            ],
            'demande_complement' => [
                'cols' => ['demande_id' => 'int', 'donnees' => 'text'],
                'pk' => ['demande_id'],
            ],
            'campagne' => [
                'cols' => [
                    'id' => 'auto', 'canal' => 'str:10', 'cible' => 'str:30', 'saison_id' => 'int', 'sections' => 'text?',
                    'objet' => 'str:255?', 'texte' => 'text', 'cree_le' => 'datetime', 'par' => 'str:150?', 'statut' => 'str:20',
                    'valide_le' => 'datetime?', 'valide_par' => 'str:150?', 'termine_le' => 'datetime?',
                ],
                'pk' => ['id'],
            ],
            'campagne_destinataire' => [
                'cols' => [
                    'id' => 'auto', 'campagne_id' => 'int', 'adherent_id' => 'int?', 'adresse' => 'str:150', 'prenom' => 'str:100?', 'nom' => 'str:100?',
                    'statut' => 'str:20', 'erreur' => 'text?', 'envoye_le' => 'datetime?',
                ],
                'pk' => ['id'],
                'index' => [['campagne_id', 'statut']],
            ],
            'marque_adherent' => [
                'cols' => [
                    'id' => 'auto', 'adherent_id' => 'int', 'champ' => 'str:40', 'valeur' => 'int', 'mail' => 'str:150?',
                    'date_marque' => 'datetime', 'par' => 'str:150?',
                ],
                'pk' => ['id'],
                'index' => [['adherent_id']],
            ],
            'presence' => [
                'cols' => [
                    'id' => 'auto', 'section_id' => 'int', 'saison_id' => 'int', 'date_seance' => 'date', 'adherent_id' => 'int',
                    'present' => 'bool', 'saisi_le' => 'datetime', 'saisi_par' => 'str:150?',
                ],
                'pk' => ['id'],
                'unique' => [['section_id', 'saison_id', 'date_seance', 'adherent_id']],
            ],
            // Lien personnel d'un animateur (repéré par son mail) : tous ses cours sur une page
            'animateur' => [
                'cols' => ['id' => 'auto', 'mail' => 'str:150', 'jeton' => 'uuid', 'cree_le' => 'datetime'],
                'pk' => ['id'],
                'unique' => [['mail'], ['jeton']],
            ],
            // Personne venue au cours sans être inscrite cette saison, ajoutée par l'animateur (à relancer par le bureau)
            'participant' => [
                'cols' => [
                    'id' => 'auto', 'section_id' => 'int', 'saison_id' => 'int', 'adherent_id' => 'int?', 'nom' => 'str:100', 'prenom' => 'str:100',
                    'telephone' => 'str:30?', 'ajoute_le' => 'datetime', 'ajoute_par' => 'str:150?', 'statut' => 'str:20', 'relance_le' => 'datetime?',
                ],
                'pk' => ['id'],
                'index' => [['saison_id'], ['section_id']],
            ],
            'presence_participant' => [
                'cols' => ['id' => 'auto', 'participant_id' => 'int', 'date_seance' => 'date', 'present' => 'bool', 'saisi_le' => 'datetime'],
                'pk' => ['id'],
                'unique' => [['participant_id', 'date_seance']],
            ],
            // Abonnement à la carte : nombre de séances par carte, cours où elle s'utilise (null = la section elle-même)
            'section_carte' => [
                'cols' => ['section_id' => 'int', 'seances' => 'int', 'cours_id' => 'int?'],
                'pk' => ['section_id'],
            ],
            // Carte rachetée en cours de saison (la première carte est l'inscription elle-même)
            'carte_recharge' => [
                'cols' => [
                    'id' => 'auto', 'inscription_id' => 'int', 'seances' => 'int', 'montant' => 'dec:8,2?', 'date_achat' => 'date',
                    'note' => 'str:255?', 'par' => 'str:150?', 'cree_le' => 'datetime',
                ],
                'pk' => ['id'],
                'index' => [['inscription_id']],
            ],
            // Changement de cours fait par un animateur (ou le bureau) depuis la liste de présence
            'changement_cours' => [
                'cols' => [
                    'id' => 'auto', 'date_changement' => 'datetime', 'inscription_id' => 'int', 'adherent_id' => 'int', 'saison_id' => 'int',
                    'de_section_id' => 'int', 'vers_section_id' => 'int', 'motif' => 'str:20', 'precision_motif' => 'str:255?',
                    'par' => 'str:150?', 'vu' => 'bool', 'annule' => 'bool',
                ],
                'pk' => ['id'],
                'index' => [['saison_id']],
            ],
            'fusion' => [
                'cols' => [
                    'id' => 'auto', 'date_fusion' => 'datetime', 'garde_id' => 'int', 'fusionne_id' => 'int',
                    'nom' => 'str:200?', 'choix' => 'text?', 'par' => 'str:150?',
                ],
                'pk' => ['id'],
            ],
            'envoi_mail' => [
                'cols' => [
                    'id' => 'auto', 'date_envoi' => 'datetime', 'destinataire' => 'str:150', 'objet' => 'str:255',
                    'inscription_id' => 'int?', 'ok' => 'bool', 'erreur' => 'text?', 'par' => 'str:150?',
                ],
                'pk' => ['id'],
                'index' => [['inscription_id']],
            ],
            'helloasso_sync' => [
                'cols' => [
                    'id' => 'auto', 'debut' => 'datetime', 'fin' => 'datetime?', 'origine' => 'str:20',
                    'ok' => 'bool', 'resume' => 'text?',
                ],
                'pk' => ['id'],
            ],
        ];
    }

    /** Valeurs de référence fixes (modes de règlement, canaux de connaissance). */
    public static function seeds(): array
    {
        return [
            'mode_reglement' => [
                [1, 'Chèque'], [2, 'Espèces'], [3, 'Chèques Vacances'], [4, 'Sportyneo'], [5, 'HelloAsso'],
                [6, 'Coupon Mairie'], [7, 'Pass Sport'], [8, 'Pass Région'], [9, 'Virement'], [10, 'CB'],
            ],
            'canal' => [
                [1, 'internet', 'Internet'], [2, 'ancien', 'Ancien adhérent'], [3, 'site_ascs', 'Site ASCS'],
                [4, 'ville', 'Ville de Rillieux'], [5, 'connaissances', 'Connaissances'],
                [6, 'le_progres', 'Le Progrès'], [7, 'plaquette', 'Plaquette'], [8, 'boite_lettres', 'Boîte aux lettres'],
            ],
        ];
    }

    /** @return list<string> instructions SQL dans l'ordre de création */
    public static function ddl(string $driver): array
    {
        $statements = [];
        foreach (self::tables() as $name => $t) {
            $lines = [];
            $inlineAuto = false;
            foreach ($t['cols'] as $col => $spec) {
                if ($spec === 'auto') {
                    $inlineAuto = true;
                    $lines[] = $driver === 'sqlite'
                        ? "`$col` INTEGER PRIMARY KEY AUTOINCREMENT"
                        : "`$col` INT NOT NULL AUTO_INCREMENT";
                    continue;
                }
                $null = str_ends_with($spec, '?');
                $type = rtrim($spec, '?');
                $def = "`$col` " . self::sqlType($type, $driver) . ($null ? ' NULL' : ' NOT NULL');
                if ($type === 'bool') {
                    $def .= ' DEFAULT 0';
                }
                $lines[] = $def;
            }
            if (!($inlineAuto && $driver === 'sqlite')) {
                $lines[] = 'PRIMARY KEY (' . self::cols($t['pk']) . ')';
            }
            foreach ($t['unique'] ?? [] as $u) {
                $lines[] = 'UNIQUE (' . self::cols($u) . ')';
            }
            foreach ($t['fk'] ?? [] as $col => $ref) {
                $lines[] = "FOREIGN KEY (`$col`) REFERENCES `$ref` (`id`)";
            }
            $extra = [];
            foreach ($t['index'] ?? [] as $idx) {
                $iname = 'idx_' . $name . '_' . implode('_', $idx);
                if ($driver === 'sqlite') {
                    $extra[] = "CREATE INDEX IF NOT EXISTS `$iname` ON `$name` (" . self::cols($idx) . ')';
                } else {
                    $lines[] = "KEY `$iname` (" . self::cols($idx) . ')';
                }
            }
            $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $statements[] = "CREATE TABLE IF NOT EXISTS `$name` (\n  " . implode(",\n  ", $lines) . "\n)$tail";
            array_push($statements, ...$extra);
        }
        return $statements;
    }

    /** @param list<string> $cols */
    private static function cols(array $cols): string
    {
        return implode(', ', array_map(static fn ($c) => "`$c`", $cols));
    }

    private static function sqlType(string $type, string $driver): string
    {
        [$base, $arg] = array_pad(explode(':', $type, 2), 2, null);
        if ($driver === 'sqlite') {
            return match ($base) {
                'int', 'smallint', 'bool' => 'INTEGER',
                'dec' => 'NUMERIC',
                default => 'TEXT',
            };
        }
        return match ($base) {
            'int' => 'INT',
            'smallint' => 'SMALLINT',
            'bool' => 'TINYINT(1)',
            'str' => "VARCHAR($arg)",
            'text' => 'TEXT',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'time' => 'TIME',
            'dec' => "DECIMAL($arg)",
            'uuid' => 'CHAR(36)',
            default => throw new \InvalidArgumentException("Type inconnu : $type"),
        };
    }
}
