<?php
/**
 * Palette « Rechercher ou aller à… » (⌘K / Ctrl+K) — la seule recherche de
 * MIA : pages des trois espaces, actions et conversations. Ouverte par tout
 * [data-palette-open] (bouton du haut de la barre latérale, tuile « Tout ») et
 * par le raccourci clavier. Comportement : public/assets/js/command-palette.js.
 *
 * Données : NavRegistry::paletteEntries (libellés traduits, droits
 * appliqués), plus les textes de l'interface — le script n'en a aucun. Les
 * icônes sont rendues ici, une fois par clé utilisée.
 *
 * Inclus par chat/index.php et admin-nav.php (réglages, administration,
 * pages d'espace), APRÈS </aside> : dans le tiroir mobile, position: fixed se
 * rapporterait au tiroir transformé.
 */
$paletteUser = Session::currentUser();
if (!$paletteUser) {
    return;
}
$paletteReconnect = $connectorsToReconnect
    ?? ConnectorRegistry::reconnectNeeded((int)$paletteUser['id'], (string)($paletteUser['role'] ?? 'user'));
$paletteEntries = NavRegistry::paletteEntries($paletteUser, $paletteReconnect);
$paletteIcons = ['chat' => NavRegistry::icon('chat', 16)];
foreach ($paletteEntries as $paletteEntry) {
    $paletteIcons[$paletteEntry['icon']] ??= NavRegistry::icon($paletteEntry['icon'], 16);
}
$paletteIcons['pin'] = '<svg width="10" height="12" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>';
$paletteData = [
    'entries' => $paletteEntries,
    'icons'   => $paletteIcons,
    'groups'  => [
        'work'  => t('Espace de travail'),
        'me'    => t('Mon compte'),
        'admin' => t('Administration'),
        'conv'  => t('Conversations'),
    ],
    'text' => [
        'suggestions' => t('Suggestions'),
        'searching'   => t('Recherche dans les conversations…'),
        'unavailable' => t('Recherche indisponible'),
        'empty'       => t('Aucun résultat'),
        'action'      => t('Action'),
    ],
];
?>
<div id="cmdPaletteBackdrop" class="cmdp-backdrop" data-palette-close hidden></div>
<div id="cmdPalette" class="cmdp" role="dialog" aria-modal="true" aria-label="<?= te('Rechercher ou aller à…') ?>" hidden>
    <div class="cmdp-head">
        <svg class="cmdp-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="cmdPaletteInput" class="cmdp-input" role="combobox"
               aria-expanded="true" aria-controls="cmdPaletteList" aria-autocomplete="list"
               autocomplete="off" autocapitalize="off" spellcheck="false"
               placeholder="<?= te('Rechercher ou aller à…') ?>"
               aria-label="<?= te('Rechercher une page, une action ou une conversation') ?>">
        <button type="button" class="cmdp-close" data-palette-close aria-label="<?= te('Fermer') ?>">
            <span class="cmdp-close-key"><?= te('Échap') ?></span>
            <span class="cmdp-close-text"><?= te('Annuler') ?></span>
        </button>
    </div>
    <div class="cmdp-list" id="cmdPaletteList" role="listbox" aria-label="<?= te('Résultats') ?>"></div>
    <div class="cmdp-foot" aria-hidden="true">
        <span><kbd>↑</kbd><kbd>↓</kbd> <?= te('naviguer') ?></span>
        <span><kbd>↵</kbd> <?= te('ouvrir') ?></span>
        <span><kbd><?= te('Échap') ?></kbd> <?= te('fermer') ?></span>
    </div>
</div>
<script>window.MIA_PALETTE = <?= json_encode($paletteData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php $paletteJsVersion = @filemtime(ROOT . '/public/assets/js/command-palette.js') ?: time(); ?>
<script defer src="/assets/js/command-palette.js?v=<?= (int)$paletteJsVersion ?>"></script>
