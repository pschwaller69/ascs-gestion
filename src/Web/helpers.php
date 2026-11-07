<?php
declare(strict_types=1);

// Fonctions d'aide pour les gabarits (échappement, formats français, champs de formulaire, icônes).

use Ascs\Web\App;

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return number_format((float) $v, 2, ',', "\u{202F}") . "\u{00A0}€";
}

/** 2026-10-07 (ou datetime) -> 07/10/2026 */
function dfr(?string $iso): string
{
    if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
        return '';
    }
    return "$m[3]/$m[2]/$m[1]";
}

function age_from(?string $iso): ?int
{
    if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso)) {
        return null;
    }
    try {
        return (new DateTimeImmutable(substr($iso, 0, 10)))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string,scalar|null> $query */
function url(string $path = '/', array $query = []): string
{
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    return App::$base . $path . ($query ? '?' . http_build_query($query) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App::csrfToken()) . '">';
}

function attr(bool $cond, string $name): string
{
    return $cond ? ' ' . $name : '';
}

/** Champs cachés à partir d'un tableau POST (hors jeton CSRF), tableaux imbriqués compris. */
function hidden_fields(array $data, string $prefix = ''): string
{
    $out = '';
    foreach ($data as $k => $v) {
        $name = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        if ($prefix === '' && in_array($k, ['_csrf', 'confirme_doublon', 'retour'], true)) {
            continue;
        }
        $out .= is_array($v)
            ? hidden_fields($v, $name)
            : '<input type="hidden" name="' . e($name) . '" value="' . e($v) . '">';
    }
    return $out;
}

/**
 * Champ de saisie standard : étiquette, contrôle, aide et message d'erreur reliés pour l'accessibilité.
 *
 * @param array{type?:string,required?:bool,error?:?string,hint?:?string,maxlength?:int,class?:string,autocomplete?:string,inputmode?:string,rows?:int,step?:string,min?:string,max?:string,readonly?:bool} $o
 */
function champ(string $label, string $name, mixed $value, array $o = []): string
{
    $id = 'f_' . preg_replace('/\W+/', '_', $name);
    $type = $o['type'] ?? 'text';
    $err = $o['error'] ?? null;
    $hint = $o['hint'] ?? null;
    $desc = trim(($hint ? "$id-aide " : '') . ($err ? "$id-err" : ''));
    $common = ' id="' . $id . '" name="' . e($name) . '"'
        . ($desc !== '' ? ' aria-describedby="' . $desc . '"' : '')
        . ($err ? ' aria-invalid="true"' : '')
        . (!empty($o['required']) ? ' required' : '')
        . (!empty($o['readonly']) ? ' readonly' : '')
        . (isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '')
        . (isset($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '')
        . (isset($o['inputmode']) ? ' inputmode="' . e($o['inputmode']) . '"' : '')
        . (isset($o['step']) ? ' step="' . e($o['step']) . '"' : '')
        . (isset($o['min']) ? ' min="' . e($o['min']) . '"' : '')
        . (isset($o['max']) ? ' max="' . e($o['max']) . '"' : '');
    if ($type === 'textarea') {
        $control = '<textarea' . $common . ' rows="' . (int) ($o['rows'] ?? 3) . '">' . e($value) . '</textarea>';
    } else {
        $control = '<input type="' . e($type) . '"' . $common . ' value="' . e($value) . '">';
    }
    return '<div class="champ ' . e($o['class'] ?? '') . ($err ? ' en-erreur' : '') . '">'
        . '<label for="' . $id . '">' . e($label) . (!empty($o['required']) ? ' <span class="oblig" title="obligatoire">*</span>' : '') . '</label>'
        . $control
        . ($hint ? '<p class="aide" id="' . $id . '-aide">' . e($hint) . '</p>' : '')
        . ($err ? '<p class="msg-erreur" id="' . $id . '-err">' . e($err) . '</p>' : '')
        . '</div>';
}

/** Case à cocher. */
function coche(string $label, string $name, bool $checked, string $value = '1'): string
{
    $id = 'c_' . preg_replace('/\W+/', '_', $name . '_' . $value);
    return '<label class="coche" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . e($name) . '" value="' . e($value) . '"' . attr($checked, 'checked') . '> <span>' . e($label) . '</span></label>';
}

/** Icônes en ligne (trait fin, héritent de la couleur du texte). */
function icon(string $name): string
{
    static $paths = [
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.6"/><path d="M16 14.2c3 .3 5 2.4 5 5.3"/>',
        'user-plus' => '<circle cx="10" cy="8" r="3.4"/><path d="M3.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5M19 8v6M16 11h6"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'file-plus' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M12 11v6M9 14h6"/>',
        'wallet' => '<circle cx="12" cy="12" r="9"/><path d="M15.2 9.2a4 4 0 1 0 0 5.6M8 11h5.5M8 13h5.5"/>',
        'alert' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><path d="m8 15 3-4 3 2 4-6"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'bank' => '<path d="M3 9l9-5 9 5M5 9v9M10 9v9M14 9v9M19 9v9M3 20h18"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>',
        'cheque' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h6M7 14h10"/>',
        'town' => '<path d="M3 20h18M5 20V10l7-5 7 5v10M9 20v-5h6v5"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m5 12 5 5 9-10"/>',
        'x' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'logout' => '<path d="M10 4H5v16h5M15 8l4 4-4 4M19 12H9"/>',
        'qr' => '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Pagination : « Précédent · page X sur Y · Suivant », en conservant les filtres. */
function pagination(string $path, array $query, int $page, int $total, int $parPage): string
{
    $pages = max(1, (int) ceil($total / $parPage));
    if ($pages <= 1) {
        return '';
    }
    $lien = static fn (int $p, string $txt, bool $actif) => $actif
        ? '<a class="btn" href="' . e(url($path, ['page' => $p] + $query)) . '">' . e($txt) . '</a>'
        : '<span class="btn inactif" aria-disabled="true">' . e($txt) . '</span>';
    return '<nav class="pagination" aria-label="Pagination">' . $lien($page - 1, 'Précédent', $page > 1)
        . '<span>Page ' . $page . ' sur ' . $pages . '</span>' . $lien($page + 1, 'Suivant', $page < $pages) . '</nav>';
}

/** Pastille d'état d'une inscription : soldée, reste à payer, ou trop-perçu. */
function pastille_solde(float $montant, float $regle): string
{
    $reste = round($montant - $regle, 2);
    if ($reste > 0) {
        return '<span class="pastille pastille-reste">reste ' . e(money($reste)) . '</span>';
    }
    if ($reste < 0) {
        return '<span class="pastille pastille-excedent">trop-perçu ' . e(money(-$reste)) . '</span>';
    }
    return '<span class="pastille pastille-ok">soldée</span>';
}
