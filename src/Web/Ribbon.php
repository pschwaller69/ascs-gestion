<?php
declare(strict_types=1);

namespace Ascs\Web;

/**
 * Ruban de navigation (mêmes cinq onglets que l'ancien logiciel).
 * Un bouton sans lien porte la version dans laquelle il arrive.
 */
final class Ribbon
{
    /** Date de la dernière mise à jour déposée sur le serveur (fichier VERSION réécrit à chaque déploiement). */
    public static function miseAJour(): ?string
    {
        $f = dirname(__DIR__, 2) . '/VERSION';
        return is_file($f) ? date('d/m H:i', (int) filemtime($f)) : null;
    }

    public static function version(): string
    {
        $f = dirname(__DIR__, 2) . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : '?';
    }

    /** @return array<string,array{titre:string,groupes:array<string,list<array{string,string,?string,?string}>>}> */
    public static function onglets(): array
    {
        return [
            'accueil' => ['titre' => 'Accueil', 'groupes' => [
                'Adhérent' => [
                    ['Adhérents', 'users', '/adherents', null],
                    ['Nouvel adhérent', 'user-plus', '/adherents/nouveau', null],
                ],
                'Inscription' => [
                    ['Inscriptions', 'file', '/inscriptions', null],
                    ['Nouvelle inscription', 'file-plus', '/inscriptions/nouveau', null],
                    ['Non payées', 'alert', '/inscriptions?statut=impayees', null],
                    ['Par section', 'layers', '/sections', null],
                    ['Émargement', 'list', '/sections', null],
                ],
                'Règlement' => [
                    ['Règlements', 'wallet', '/reglements', null],
                    ['Remise de chèques', 'cheque', '/remises', null],
                    ['Par type', 'chart', '/reglements/par-type', null],
                ],
                'En ligne' => [
                    ['HelloAsso', 'upload', '/helloasso', null],
                    ['Demandes', 'list', '/demandes', null],
                    ['QR codes', 'qr', '/en-ligne', null],
                ],
            ]],
            'mail' => ['titre' => 'Mail', 'groupes' => [
                'Mailing' => [
                    ['Envois', 'mail', '/mailing', null],
                    ['Saison', 'mail', '/mailing/nouveau?cible=saison', null],
                    ['Section', 'mail', '/mailing/nouveau?cible=section', null],
                    ['N-1 non réinscrits', 'mail', '/mailing/nouveau?cible=non_reinscrits', null],
                    ['Anciens adhérents', 'mail', '/mailing/nouveau?cible=anciens', null],
                ],
                'SMS' => [
                    ['Nouveau SMS', 'mail', '/mailing/nouveau?canal=sms', null],
                ],
                'Contrôle' => [
                    ['Validation des mails', 'mail', '/mails/verification', null],
                    ['Liste de présence', 'list', '/sections', null],
                ],
                'Contact' => [
                    ['Ajout Outlook', 'download', '/contacts/outlook', null],
                ],
            ]],
            'parametrage' => ['titre' => 'Paramétrage', 'groupes' => [
                'Saison' => [['Saisons', 'calendar', '/parametrage/saisons', null]],
                'Section' => [['Sections', 'layers', '/parametrage/sections', null], ['Résultat section', 'chart', '/resultats/sections', null]],
                'Section principale' => [['Sections principales', 'layers', '/parametrage/sections-principales', null], ['Résultat', 'chart', '/resultats/sections-principales', null]],
                'Famille' => [['Familles', 'layers', '/parametrage/familles', null]],
                'Documents' => [
                    ['Facture', 'file', '/parametres/facture', null],
                ],
                'Données' => [
                    ['Import WinDev', 'upload', '/import', null],
                    ['Comptes du bureau', 'users', '/utilisateurs', null],
                ],
            ]],
            'financier' => ['titre' => 'Financier', 'groupes' => [
                'Banque' => [
                    ['Compte bancaire', 'bank', null, '0.6.0'],
                    ['Contrôle', 'bank', null, '0.6.0'],
                    ['Export Excel', 'download', null, '0.6.0'],
                ],
            ]],
            'reporting' => ['titre' => 'Reporting', 'groupes' => [
                'Adhérents' => [
                    ['Adhérents', 'users', null, '0.6.0'],
                    ['Adhérent par section', 'layers', null, '0.6.0'],
                    ['Courbe d\'inscriptions', 'chart', null, '0.6.0'],
                    ['Reporting mairie', 'town', null, '0.6.0'],
                ],
            ]],
        ];
    }
}
