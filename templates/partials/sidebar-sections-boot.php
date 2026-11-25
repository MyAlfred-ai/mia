<?php
/* Sections repliées du volet, AVANT le premier rendu : sans ce bout de script,
   une section s'afficherait dépliée un instant puis se replierait sous les yeux.
   Il pose `data-sb-spaces|pinned|history|account="closed"` sur <html> d'après
   l'état gardé sur le COMPTE (SidebarPreferences : choix de la personne,
   sinon les défauts — Espaces et Épinglées repliés), et le laisse à
   sidebar-sections.js dans window.MIA_SIDEBAR (gestes, enregistrement).
   Compte encore sans état : l'ancien état du navigateur (localStorage, même
   clé qu'avant) est repris une fois — sidebar-sections.js l'enregistre sur le
   compte (`adopt`) puis l'efface.
   Requis par head-meta.php (toutes les pages, dans <head>) pour une personne
   connectée. Script PUR ASCII : head-meta précède <meta charset> (json_encode
   sans JSON_UNESCAPED_UNICODE rend un bloc ASCII). */
$sbBootUserId = (int)(Session::currentUser()['id'] ?? 0);
$sbBootConfig = SidebarPreferences::clientConfig(Session::currentUser());
// Jeton CSRF de l'enregistrement : toutes les pages à volet ne portent pas la
// balise <meta name="csrf-token"> (Diagnostic, Journaux, À propos…).
$sbBootConfig['csrf'] = Csrf::token();
?>
<script>
window.MIA_SIDEBAR = <?= json_encode($sbBootConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
(function (h, c) {
    if (!c.saved) {
        try {
            var old = JSON.parse(localStorage.getItem('miaSidebar:<?= $sbBootUserId ?>') || 'null');
            if (old && typeof old === 'object') { c.state = old; c.adopt = true; }
        } catch (e) { /* storage unavailable: account defaults */ }
    }
    var s = c.state || {};
    ['spaces', 'pinned', 'history', 'account'].forEach(function (k) {
        var open = typeof s[k] === 'boolean' ? s[k] : c.defaults[k] !== false;
        if (!open) h.setAttribute('data-sb-' + k, 'closed');
    });
})(document.documentElement, window.MIA_SIDEBAR);
</script>
