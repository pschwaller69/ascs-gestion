<?php
declare(strict_types=1);

namespace Ascs\Web;

/**
 * Ruban de navigation (mêmes cinq onglets que l'ancien logiciel).
 * Un bouton sans lien porte la version dans laquelle il arrive.
 */
final class Ribbon
{
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
                    ['Par section', 'layers', null, '0.4.0'],
                    ['Émargement', 'list', null, '0.4.0'],
                ],
                'Règlement' => [
                    ['Règlements', 'wallet', '/reglements', null],
                    ['Remise de chèques', 'cheque', '/remises', null],
                    ['Par type', 'chart', null, '0.3.5'],
                ],
                'En ligne' => [
                    ['HelloAsso', 'upload', '/helloasso', null],
                    ['Demandes', 'list', '/demandes', null],
                    ['QR codes', 'qr', '/en-ligne', null],
                ],
            ]],
            'mail' => ['titre' => 'Mail', 'groupes' => [
                'Mailing' => [
                    ['Validation des mails', 'mail', null, '0.5.0'],
                    ['Anciens adhérents', 'mail', null, '0.5.0'],
                    ['Saison', 'mail', null, '0.5.0'],
                    ['N-1 non réinscrits', 'mail', null, '0.5.0'],
                    ['Section', 'mail', null, '0.5.0'],
                    ['Liste de présence', 'list', null, '0.4.0'],
                ],
                'Contact' => [
                    ['Ajout Outlook', 'users', null, '0.5.0'],
                ],
            ]],
            'parametrage' => ['titre' => 'Paramétrage', 'groupes' => [
                'Saison' => [['Saison', 'calendar', null, '0.4.0']],
                'Section' => [['Section', 'layers', null, '0.4.0'], ['Résultat section', 'chart', null, '0.4.0']],
                'Section principale' => [['Section principale', 'layers', null, '0.4.0'], ['Résultat', 'chart', null, '0.4.0']],
                'Famille' => [['Famille', 'layers', null, '0.4.0']],
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
