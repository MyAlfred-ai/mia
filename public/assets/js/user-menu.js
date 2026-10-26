/**
 * Sélecteur de langue et garde des notifications à la déconnexion — le script
 * chargé par TOUTES les pages applicatives (chat, administration, paramètres,
 * page de connexion).
 * Délégué sur `document` → indépendant de l'ordre de chargement du script
 * vs. du DOM ; garde anti-double-init pour cohabiter sans risque si inclus
 * plusieurs fois.
 */

/**
 * `window.miaSafeAreaTop()` — hauteur réservée en haut d'écran (barre d'état,
 * encoche) en px CSS, 0 en navigateur classique. Le CSS s'appuie sur le jeton
 * `--safe-top` ; les menus positionnés en px par JS (popover du compte, menu
 * d'une conversation) ont besoin de la même valeur en nombre pour ne pas se
 * caler sous l'horloge. `getComputedStyle().getPropertyValue('--safe-top')` ne
 * résout pas `env()` de façon fiable selon les moteurs : on MESURE une sonde
 * jetable, seul moyen sûr. Déclaré ici parce que user-menu.js est le seul
 * script chargé par TOUTES les pages applicatives (chat, admin, paramètres).
 */
window.miaSafeAreaTop = function () {
    var probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;top:0;left:0;width:0;visibility:hidden;'
        + 'pointer-events:none;height:env(safe-area-inset-top,0px)';
    (document.body || document.documentElement).appendChild(probe);
    // offsetHeight, pas getBoundingClientRect() : la sonde est zoomée par la
    // taille d'affichage (Réglages → Affichage), sa hauteur à l'écran
    // vaudrait l'encoche multipliée par le zoom. offsetHeight rend la valeur
    // d'env() telle quelle, c'est-à-dire la zone sûre en pixels d'ÉCRAN.
    var h = probe.offsetHeight || 0;
    probe.remove();
    return h;
};

(function () {
    if (window.__userMenuInit) return;
    window.__userMenuInit = true;

    // ---- Sélecteur de langue (templates/partials/lang-switcher.php) --------
    // Vit ici parce que user-menu.js est le SEUL script chargé par toutes les
    // pages applicatives ; autosave.js n'est présent que sur /admin et
    // /settings, et le sélecteur doit fonctionner jusque sur la page de
    // connexion, où il n'y a ni menu de compte ni formulaire auto-enregistré.
    var langBtn  = function () { return document.getElementById('langSwitcherBtn'); };
    var langList = function () { return document.getElementById('langSwitcherList'); };

    function fermerLangues(rendreFocus) {
        var l = langList(), b = langBtn();
        if (!l || l.hidden) return;
        l.hidden = true;
        if (b) {
            b.setAttribute('aria-expanded', 'false');
            // Échap et le choix d'une option rendent le focus au bouton, sinon
            // il tombe sur <body> et la navigation au clavier repart de zéro.
            if (rendreFocus) b.focus();
        }
    }

    function optionsLangue() {
        var l = langList();
        return l ? Array.prototype.slice.call(l.querySelectorAll('.lang-switcher-option')) : [];
    }

    function ouvrirLangues(decl) {
        var l = langList();
        if (!l) return;
        l.hidden = false;
        decl.setAttribute('aria-expanded', 'true');
        // Le focus va sur la langue ACTIVE : Entrée referme sans rien changer,
        // les flèches partent d'un point connu.
        var actives = optionsLangue().filter(function (o) { return o.getAttribute('aria-selected') === 'true'; });
        (actives[0] || optionsLangue()[0] || decl).focus();
    }

    function basculerLangue(code, options) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (options) options.forEach(function (o) { o.disabled = true; });

        fetch('/settings/lang', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type':     'application/x-www-form-urlencoded',
                'X-CSRF-Token':     meta ? meta.content : '',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json'
            },
            body: 'lang=' + encodeURIComponent(code || '')
        })
        // Un 403 CSRF renvoie du HTML, pas du JSON : .json() rejette, et c'est
        // le .catch() qui rend la main plutôt qu'une liste morte.
        .then(function (r) { return r.json(); })
        .then(function (d) {
            // Rechargement plutôt que re-rendu : les libellés sont produits côté
            // serveur, et window.I18N est inline dans le <head>.
            if (d && d.ok) { location.reload(); return; }
            if (options) options.forEach(function (o) { o.disabled = false; });
        })
        .catch(function () {
            if (options) options.forEach(function (o) { o.disabled = false; });
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        var choix = e.target.closest('.lang-switcher-option');
        if (choix) {
            e.preventDefault();
            if (choix.disabled) return;
            // Langue déjà active : refermer sans aller-retour serveur.
            if (choix.getAttribute('aria-selected') === 'true') { fermerLangues(true); return; }
            basculerLangue(choix.dataset.lang, Array.prototype.slice.call(
                choix.closest('.lang-switcher-list').querySelectorAll('.lang-switcher-option')
            ));
            return;
        }

        var decl = e.target.closest('#langSwitcherBtn');
        if (decl) {
            e.preventDefault();
            var l = langList();
            if (!l) return;
            if (l.hidden) ouvrirLangues(decl); else fermerLangues(false);
            return;
        }

        // Clic ailleurs : on referme. Le test doit venir APRÈS les deux cas
        // ci-dessus, sinon un clic sur la liste la refermerait aussitôt.
        if (!e.target.closest('#langSwitcher')) fermerLangues(false);
    });

    // Le même réglage depuis Mon profil : un <select data-lang-select> (hors
    // du formulaire auto-enregistré) passe par la même route et le même
    // rechargement — un seul comportement pour les deux surfaces.
    document.addEventListener('change', function (e) {
        var select = e.target && e.target.closest ? e.target.closest('select[data-lang-select]') : null;
        if (!select) return;
        basculerLangue(select.value, [select]);
    });

    // Clavier : Échap referme et rend le focus ; ↑/↓ circulent entre les
    // langues ; Début/Fin sautent aux extrémités. Entrée/Espace sont natifs
    // (ce sont des <button>). Sans ces flèches, un listbox qui ne se parcourt
    // qu'à la souris n'en est pas un.
    document.addEventListener('keydown', function (e) {
        var l = langList();
        if (!l || l.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); fermerLangues(true); return; }

        var opts = optionsLangue();
        if (!opts.length) return;
        var idx = opts.indexOf(document.activeElement);
        var cible = null;
        if (e.key === 'ArrowDown')      cible = opts[(idx + 1 + opts.length) % opts.length];
        else if (e.key === 'ArrowUp')   cible = opts[(idx - 1 + opts.length) % opts.length];
        else if (e.key === 'Home')      cible = opts[0];
        else if (e.key === 'End')       cible = opts[opts.length - 1];
        else if (e.key === 'Tab')       { fermerLangues(false); return; }   // on quitte le composant
        if (cible) { e.preventDefault(); cible.focus(); }
    });
    // Même règle que le menu du compte : ne fermer que si la LARGEUR change.
    // Sur mobile, l'ouverture du clavier ou la barre d'URL dynamique ne
    // modifient que la hauteur — fermer alors la liste la rendrait inutilisable
    // au doigt, précisément au moment où on s'en sert.
    var langLargeur = window.innerWidth;
    window.addEventListener('resize', function () {
        if (window.innerWidth !== langLargeur) {
            langLargeur = window.innerWidth;
            fermerLangues(false);
        }
    });
})();

/**
 * Notifications des tâches programmées : un navigateur ne reçoit que celles du
 * compte CONNECTÉ. Le compte qui l'a inscrit est retenu (localStorage
 * `mia.push.owner`, posé par schedules.js) ; le navigateur est désinscrit — ici
 * ET sur le serveur — à la DÉCONNEXION, et quand un AUTRE compte s'y connecte.
 * Sur un ordinateur partagé, les notifications de l'un n'apparaissent jamais
 * chez l'autre. Dans un navigateur jamais inscrit, rien ne se passe.
 */
(function () {
    if (window.__pushGuardInit) return;
    window.__pushGuardInit = true;

    var OWNER_KEY = 'mia.push.owner';
    function owner() {
        try { return localStorage.getItem(OWNER_KEY) || ''; } catch (e) { return ''; }
    }
    function forget() {
        try { localStorage.removeItem(OWNER_KEY); } catch (e) { /* rien à oublier */ }
    }

    /** Désinscrit ce navigateur ; rend la main au plus tard après `delai` ms. */
    function release(delai) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var work = !('serviceWorker' in navigator) ? Promise.resolve() : navigator.serviceWorker.getRegistration('/')
            .then(function (reg) { return reg && reg.pushManager ? reg.pushManager.getSubscription() : null; })
            .then(function (sub) {
                if (!sub) return null;
                var endpoint = sub.endpoint;
                return sub.unsubscribe().catch(function () {}).then(function () {
                    if (!meta) return null;
                    return fetch('/settings/push/unsubscribe', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': meta.content,
                            'Accept': 'application/json'
                        },
                        body: 'endpoint=' + encodeURIComponent(endpoint)
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        // La page des tâches réaffiche la liste des appareils à jour.
                        if (d && Array.isArray(d.devices)) window.miaPushGuardDevices = d.devices;
                    });
                });
            })
            .catch(function () { /* au pire, le serveur l'oubliera au premier envoi refusé */ });
        return Promise.race([
            work.then(forget, forget),
            new Promise(function (resolve) { setTimeout(resolve, delai); })
        ]);
    }

    var me = (document.querySelector('meta[name="mia-user"]') || {}).content || '';
    var inscrit = owner();
    if (inscrit !== '' && me !== '' && inscrit !== me) {
        // Attendue par la page des tâches avant d'afficher l'état (schedules.js).
        window.miaPushGuard = release(5000);
    }

    // Déconnexion : le lien part APRÈS la désinscription (1,5 s au plus). Un
    // clic annulé par la confirmation (« Se déconnecter ? ») ne fait rien.
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href="/auth/logout"]') : null;
        if (!a || e.defaultPrevented || owner() === '') return;
        e.preventDefault();
        release(1500).then(function () { window.location.href = a.href; });
    });
})();
