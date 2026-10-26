<?php /* Balises PWA communes : manifest, icône Apple, couleur de thème. */ ?>
<?php /* Dictionnaire de l'interface côté navigateur.
         Les fichiers de public/assets/js/ sont STATIQUES : ils ne peuvent pas
         appeler t(), d'où ce pont. Vide (~40 octets) tant que la langue est le
         français, puisque le français est le code.

         PAS de JSON_UNESCAPED_UNICODE ici, contrairement au reste du dépôt :
         ce partial est requis AVANT <meta charset> dans tous les gabarits.
         L'échappement \uXXXX rend le bloc pur ASCII, donc insensible à
         l'encodage supposé par le navigateur avant qu'il ne lise le charset. */ ?>
<script>
window.I18N = <?= json_encode(I18n::clientDictionary(), JSON_UNESCAPED_SLASHES) ?>;
/* Mêmes règles que t()/plural() côté PHP : repli sur le texte source si la clé
   manque, substituants {nom}, et pluriel qui DIFFÈRE entre FR (> 1) et EN (!= 1). */
window.t = function (s, v) {
    var o = (window.I18N.s && window.I18N.s[s]) || s;
    return v ? o.replace(/\{(\w+)\}/g, function (m, k) { return k in v ? v[k] : m; }) : o;
};
window.tn = function (n, un, plusieurs, v) {
    var pluriel = window.I18N.lang === 'fr' ? n > 1 : n !== 1;
    v = v || {}; v.n = n;
    return window.t(pluriel ? plusieurs : un, v);
};
</script>
<?php /* Compte connecté (vide sur la page de connexion) : user-menu.js s'en
         sert pour qu'un navigateur ne reçoive que les notifications de CE compte. */ ?>
<meta name="mia-user" content="<?= ($miaHeadUserId = (int)(Session::currentUser()['id'] ?? 0)) > 0 ? $miaHeadUserId : '' ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/assets/img/icons/apple-touch-icon.png">
<meta name="theme-color" content="<?= DisplayPreferences::themeColor(DisplayPreferences::currentTheme()) ?>">
<?php /* Réglages → Affichage (cf. DisplayPreferences).
         1. Thème « Automatique » : le serveur ne connaît pas le réglage de
            l'appareil, il rend le thème de l'organisation avec
            data-theme-pref="system". Ce script le corrige AVANT que la feuille
            de style ne soit lue (aucun flash), puis suit l'appareil en direct
            (passage au mode sombre le soir) — et prévient la page par
            l'évènement `mia:themechange` (barre de statut de l'application).
         2. window.miaUiZoom() : le facteur de la taille d'affichage (`zoom` sur
            <html>). Une position lue par getBoundingClientRect() est en pixels
            d'ÉCRAN ; écrite telle quelle dans style.left d'un élément zoomé,
            elle serait zoomée une seconde fois. Les menus flottants divisent
            donc par ce facteur (1 à la taille normale, et dans un navigateur
            qui ignore `zoom`).
         Script pur ASCII : il précède <meta charset> (cf. plus haut). */ ?>
<script>
(function () {
    var d = document.documentElement;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    window.miaApplyTheme = function () {
        if (!mq || d.getAttribute('data-theme-pref') !== 'system') return;
        var theme = mq.matches ? 'dark' : 'light';
        if (d.getAttribute('data-theme') === theme) return;
        d.setAttribute('data-theme', theme);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'light' ? '#f5f5f5' : '#212121');
        try { document.dispatchEvent(new CustomEvent('mia:themechange', { detail: { theme: theme } })); } catch (e) {}
    };
    window.miaApplyTheme();
    if (mq && mq.addEventListener) mq.addEventListener('change', window.miaApplyTheme);
    else if (mq && mq.addListener) mq.addListener(window.miaApplyTheme);
    window.miaUiZoom = function () {
        var z = parseFloat(window.getComputedStyle(d).zoom);
        return z > 0 ? z : 1;
    };
})();
</script>
<?php if (NativeApp::isNative()): /* App mobile (WebView Capacitor) : classe CSS + pont natif */ ?>
<script>document.documentElement.classList.add('in-native-app', 'is-<?= NativeApp::platform() ?>');</script>
<script defer src="/assets/js/native-bridge.js?v=<?= @filemtime(ROOT . '/public/assets/js/native-bridge.js') ?: '1' ?>"></script>
<?php endif; ?>
