<?php
/**
 * Modale « Nouveautés » — ouverte par le bouton #versionBtn (pied du chat et
 * page À propos).
 *
 * PARTIAL COMMUN, et c'est le but : la modale vivait en double, recopiée dans
 * chat.js et dans templates/about/index.php. Deux exemplaires du même écran
 * divergent au premier ajustement, et l'un des deux part en production sans
 * qu'on le voie.
 *
 * Le gabarit ne rend AUCUNE nouveauté : elles sont chargées à l'ouverture par
 * whats-new.js depuis /changelog.json (qui n'est pas dans le cache du
 * navigateur). Seul l'identifiant de la plus récente est injecté côté serveur,
 * pour que la pastille « non lu » s'affiche sans une requête par page.
 *
 * Le gabarit porte en revanche TOUS les libellés fixes (en-tête, filtres,
 * recherche) : rendus et traduits ici, ils n'ont pas à vivre dans le script.
 *
 * Attend $currentUser dans la portée appelante (facultatif).
 */
$wnUser    = $currentUser ?? Session::currentUser();
$wnDernier = Nouveautes::dernierId(ROOT);
$wnJs      = '/assets/js/whats-new.js?v=' . (@filemtime(ROOT . '/public/assets/js/whats-new.js') ?: 0);
$wnApp     = Settings::get('app_name', 'MIA');
?>
<div class="modal wn-modal" id="versionModal" style="display:none">
    <div class="modal-box wn-box" role="dialog" aria-modal="true" aria-labelledby="wnTitle">
        <header class="wn-hero">
            <div class="wn-hero-glow" aria-hidden="true"></div>
            <span class="wn-hero-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
            </span>
            <div class="wn-hero-text">
                <h3 class="wn-title" id="wnTitle"><?= te('Nouveautés') ?></h3>
                <p class="wn-subtitle"><?= te('Ce que {app} sait faire de plus.', ['app' => $wnApp]) ?></p>
            </div>
            <span class="wn-version" title="<?= te('Version installée :') ?> v<?= htmlspecialchars(APP_VERSION) ?>">v<?= htmlspecialchars(APP_VERSION) ?></span>
            <button type="button" class="wn-x" id="versionCloseX" aria-label="<?= te('Fermer') ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </header>

        <div class="wn-toolbar">
            <label class="wn-search">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" id="versionSearch" autocomplete="off"
                       placeholder="<?= te('Rechercher une nouveauté') ?>"
                       aria-label="<?= te('Rechercher une nouveauté') ?>">
            </label>
            <div class="wn-filters" id="versionFilters" role="tablist" aria-label="<?= te('Filtrer') ?>">
                <button type="button" class="wn-filter is-active" data-genre="" role="tab" aria-selected="true"><?= te('Tout') ?><span class="wn-count"></span></button>
                <button type="button" class="wn-filter" data-genre="nouveaute" role="tab" aria-selected="false"><i class="wn-dot wn-tone-new"></i><?= te('Nouveautés') ?><span class="wn-count"></span></button>
                <button type="button" class="wn-filter" data-genre="amelioration" role="tab" aria-selected="false"><i class="wn-dot wn-tone-up"></i><?= te('Améliorations') ?><span class="wn-count"></span></button>
                <button type="button" class="wn-filter" data-genre="correctif" role="tab" aria-selected="false"><i class="wn-dot wn-tone-fix"></i><?= te('Correctifs') ?><span class="wn-count"></span></button>
            </div>
        </div>

        <div id="versionList" class="wn-list" aria-live="polite">
            <div class="wn-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
            <p class="wn-empty"><?= te('Chargement…') ?></p>
        </div>

        <footer class="wn-foot">
            <!-- Sujets de commit : lecture d'ingénierie, sans intérêt pour qui
                 utilise l'application. Rendu par le JS, replié, et seulement
                 pour les comptes de l'éditeur. -->
            <details id="versionTech" class="wn-tech" hidden>
                <summary><?= te('Détail technique') ?></summary>
                <div id="versionTechList" class="wn-tech-list"></div>
            </details>
            <button type="button" class="btn-primary wn-close" id="versionClose"><?= te('Fermer') ?></button>
        </footer>
    </div>
</div>
<script>
window.IS_STAFF = <?= json_encode(Settings::isStaffEmail($wnUser['email'] ?? null)) ?>;
window.WHATS_NEW_LAST_ID = <?= json_encode($wnDernier) ?>;
window.WHATS_NEW_USER_ID = <?= json_encode((int) ($wnUser['id'] ?? 0)) ?>;
</script>
<script src="<?= htmlspecialchars($wnJs) ?>" defer></script>
