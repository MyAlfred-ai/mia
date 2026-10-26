/**
 * Pont natif — application mobile (wrapper Capacitor).
 *
 * Chargé UNIQUEMENT quand la page est servie à la WebView de l'app mobile
 * (détection User-Agent côté serveur, cf. NativeApp.php + head-meta.php).
 * En mode `server.url`, la WebView native injecte elle-même `capacitor.js`
 * et les proxys des plugins : ce fichier ne fait que les consommer.
 *
 * Règle : no-op complet hors contexte Capacitor (filet si le fichier est
 * chargé par erreur dans un navigateur classique), et feature-detection sur
 * chaque plugin (seuls ceux compilés dans l'app existent).
 */
(function () {
    'use strict';

    const cap = window.Capacitor;
    if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) {
        return; // navigateur classique : rien à faire
    }

    /** Accès sûr à un plugin Capacitor (null s'il n'est pas embarqué). */
    function plugin(name) {
        return (cap.Plugins && cap.Plugins[name]) || null;
    }

    /** Token CSRF de la page (même mécanisme que chat.js). */
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    const NativeBridge = {
        platform: cap.getPlatform ? cap.getPlatform() : 'unknown', // 'ios' | 'android'
        plugin,
        csrfToken,
    };
    window.NativeBridge = NativeBridge;

    // Environnements de l'app. Les URLs peuvent être fournies par la page ;
    // sans configuration distincte, l'application reste sur son origine.
    // La bascule recette se fait par geste caché (7 taps sur le logo de la
    // page de connexion), persistée via le plugin Preferences.
    const ENV_URLS = window.MIA_ENV_URLS || {
        prod: location.origin,
        staging: location.origin,
    };
    const ENV_PREF_KEY = 'myllm_env';

    // ---- Bascule d'environnement prod ⇄ recette ---------------------------
    function setupEnvSwitch() {
        const Preferences = plugin('Preferences');
        const prodHost = new URL(ENV_URLS.prod).host;
        const stagingHost = new URL(ENV_URLS.staging).host;
        const onStaging = location.host === stagingHost;

        // Badge permanent quand l'app est branchée sur la recette.
        if (onStaging) {
            const badge = document.createElement('div');
            badge.className = 'native-env-badge';
            badge.textContent = 'STAGING';
            document.body.appendChild(badge);
        }

        // Prod et recette identiques (prod pas encore déployée) : rien à
        // basculer — badge seul, pas de geste ni de redirection.
        if (prodHost === stagingHost) return;

        if (!Preferences) return;

        // Au démarrage à froid l'app charge la prod : si la préférence dit
        // « staging », on rebascule immédiatement (chemin conservé).
        Preferences.get({ key: ENV_PREF_KEY }).then(({ value }) => {
            if (value === 'staging' && location.host === prodHost) {
                location.replace(ENV_URLS.staging + location.pathname + location.search);
            }
        }).catch(() => {});

        // Geste caché : 7 taps en moins de 3 s sur le logo de connexion.
        const logo = document.querySelector('.login-logo');
        if (!logo) return;
        let taps = 0;
        let timer = null;
        logo.addEventListener('click', () => {
            taps++;
            clearTimeout(timer);
            timer = setTimeout(() => { taps = 0; }, 3000);
            if (taps < 7) return;
            taps = 0;
            const question = onStaging
                ? t('Rebasculer sur l’environnement de PRODUCTION ?')
                : t('Basculer sur l’environnement de RECETTE (staging) ?');
            if (!confirm(question)) return;
            Preferences.set({ key: ENV_PREF_KEY, value: onStaging ? '' : 'staging' })
                .then(() => location.replace(onStaging ? ENV_URLS.prod : ENV_URLS.staging))
                .catch(() => {});
        });
    }

    // ---- Barre de statut : suit le thème de la page ----------------------
    function syncStatusBar() {
        const StatusBar = plugin('StatusBar');
        if (!StatusBar) return;
        const light = (document.documentElement.getAttribute('data-theme') || '') === 'light';
        // Style = couleur du TEXTE de la barre : Dark = texte sombre (fond clair).
        StatusBar.setStyle({ style: light ? 'DARK' : 'LIGHT' }).catch(() => {});
        if (NativeBridge.platform === 'android') {
            StatusBar.setBackgroundColor({ color: light ? '#f5f5f5' : '#212121' }).catch(() => {});
        }
    }

    // ---- Revalidation de session au retour au premier plan ---------------
    // L'app peut rester des jours en arrière-plan : au retour, on vérifie que
    // la session serveur est toujours vivante (endpoint existant), sinon on
    // ramène proprement à l'écran de connexion.
    function watchAppResume() {
        const App = plugin('App');
        if (!App) return;
        App.addListener('resume', async () => {
            if (location.pathname.startsWith('/auth/')) return;
            try {
                const res = await fetch('/auth/session-status', {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.status === 401) { location.href = '/auth/login'; return; }
            } catch (_) {
                /* hors-ligne : l'overlay réseau prend le relais */
                return;
            }
            // Retour d'un consentement Google effectué dans le navigateur
            // système (connecteurs Drive/Gmail) : recharger pour afficher
            // le nouveau statut de connexion.
            if (location.pathname === '/settings/connections') location.reload();
        });
    }

    // ---- Overlay hors-ligne ----------------------------------------------
    function watchNetwork() {
        const Network = plugin('Network');
        if (!Network) return;
        let overlay = null;
        const show = () => {
            if (overlay) return;
            overlay = document.createElement('div');
            overlay.className = 'native-offline-overlay';
            overlay.innerHTML = '<div class="native-offline-box">' +
                '<div class="native-offline-icon">&#9889;</div>' +
                '<p>Connexion perdue</p>' +
                '<span>L’application se reconnectera automatiquement.</span>' +
                '</div>';
            document.body.appendChild(overlay);
        };
        const hide = () => { if (overlay) { overlay.remove(); overlay = null; } };
        Network.addListener('networkStatusChange', (st) => {
            if (st && st.connected) hide(); else show();
        });
        Network.getStatus().then((st) => { if (!st.connected) show(); }).catch(() => {});
    }

    // ---- Téléchargements → feuille de partage native ----------------------
    // a.download + blob: est inopérant en WKWebView iOS (et peu fiable sur
    // Android) : on écrit le fichier dans le cache natif puis on ouvre la
    // feuille de partage (AirDrop, Fichiers, Drive, mail…).
    function blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(reader.error);
            reader.onload = () => {
                const dataUrl = String(reader.result || '');
                resolve(dataUrl.slice(dataUrl.indexOf(',') + 1));
            };
            reader.readAsDataURL(blob);
        });
    }

    NativeBridge.saveAndShare = async function (blob, filename) {
        const Filesystem = plugin('Filesystem');
        const Share = plugin('Share');
        if (!Filesystem || !Share) {
            alert(t('Partage indisponible dans cette version de l’application.'));
            return;
        }
        try {
            const safeName = String(filename || 'fichier')
                .replace(/[\\/:*?"<>|]+/g, '_')
                .slice(0, 120) || 'fichier';
            const data = await blobToBase64(blob);
            const written = await Filesystem.writeFile({
                path: safeName,
                data,
                directory: 'CACHE',
            });
            await Share.share({ files: [written.uri] });
        } catch (err) {
            // Fermeture de la feuille de partage sans choisir de cible : normal.
            const msg = String((err && err.message) || err || '');
            if (!/cancel|annul|dismiss/i.test(msg)) {
                alert(t('Le partage du fichier a échoué.'));
            }
        }
    };

    // ---- Liens externes → navigateur système ------------------------------
    // Un target=_blank vers un autre domaine doit sortir de la WebView
    // (onglet système avec partage/retour), pas y rester piégé.
    function setupExternalLinks() {
        const Browser = plugin('Browser');
        if (!Browser) return;
        document.addEventListener('click', (ev) => {
            const a = ev.target && ev.target.closest ? ev.target.closest('a[target="_blank"]') : null;
            if (!a || !a.href || !/^https?:/i.test(a.href)) return;
            if (new URL(a.href, location.href).host === location.host) return;
            ev.preventDefault();
            Browser.open({ url: a.href }).catch(() => {});
        }, true);
    }

    // ---- Connecteurs Google (Drive / Gmail / Agenda) → navigateur système --
    // Google interdit ses écrans OAuth en WebView : le pont récupère l'URL de
    // consentement au flux NATIF (state signé, retour sans session) et l'ouvre
    // dans le navigateur système. Au retour dans l'app, la page Connexions se
    // recharge (cf. watchAppResume).
    function setupConnectorLinks() {
        const Browser = plugin('Browser');
        if (!Browser) return;
        document.addEventListener('click', (ev) => {
            const a = ev.target && ev.target.closest
                ? ev.target.closest('a[href^="/settings/drive/connect"], a[href^="/settings/gmail/connect"], a[href^="/settings/calendar/connect"], a[href^="/settings/tasks/connect"], a[href^="/settings/outlook/connect"], a[href^="/settings/mscalendar/connect"], a[href^="/settings/mstodo/connect"], a[href^="/settings/teams/connect"], a[href^="/settings/onedrive/connect"]')
                : null;
            if (!a) return;
            ev.preventDefault();
            ev.stopPropagation();
            fetch(a.getAttribute('href') + '?format=json', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (data && data.url) return Browser.open({ url: data.url });
                    throw new Error(t('URL absente'));
                })
                .catch(() => { location.href = a.getAttribute('href'); });
        }, true);
    }

    // ---- window.open → comportement natif ---------------------------------
    // chat.js appelle window.open() directement (Google Maps, sources web,
    // vidéo en « nouvel onglet ») : pas de nouvel onglet en WebView.
    //  - URL externe → onglet du navigateur système (plugin Browser) ;
    //  - URL même origine (ex. vidéo générée) → téléchargement + feuille de
    //    partage (le navigateur système n'a pas le cookie de session) ;
    //  - autres schémas (blob:…) → comportement d'origine.
    function setupWindowOpen() {
        const Browser = plugin('Browser');
        const origOpen = window.open.bind(window);
        window.open = function (url, target, features) {
            if (!url) return origOpen(url, target, features);
            try {
                const u = new URL(String(url), location.href);
                if (u.protocol === 'http:' || u.protocol === 'https:') {
                    if (u.host === location.host) {
                        const name = decodeURIComponent(u.pathname.split('/').pop() || 'fichier');
                        fetch(u.href)
                            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
                            .then(b => NativeBridge.saveAndShare(b, name))
                            .catch(() => { location.href = u.href; });
                        return null;
                    }
                    if (Browser) {
                        Browser.open({ url: u.href }).catch(() => {});
                        return null;
                    }
                }
            } catch (_) { /* URL invalide : comportement d'origine */ }
            return origOpen(url, target, features);
        };
    }

    // ---- Connexion Google native (page /auth/login) -----------------------
    // L'OAuth web est bloqué par Google en WebView (disallowed_useragent) :
    // le plugin natif fournit un idToken, vérifié par POST /auth/google-token.
    function setupGoogleLogin() {
        const btn = document.getElementById('nativeGoogleBtn');
        if (!btn) return; // pas sur la page de connexion

        const SocialLogin = plugin('SocialLogin');
        const ids = window.GOOGLE_NATIVE_CLIENT_IDS || {};

        const showError = (code, detail) => {
            const messages = {
                inactive: t('Votre compte est en attente de validation par un administrateur.'),
                too_many_attempts: t('Trop de tentatives. Réessayez dans 15 minutes.'),
                oauth_failed: t('Erreur lors de la connexion Google (côté serveur). Réessayez.'),
                developer_error: t('Configuration Google incomplète : vérifier le client OAuth Android ')
                    + t('(package ai.myalfred.myllm + SHA-1 exact, même projet que le client web). ')
                    + t('La propagation côté Google peut prendre de quelques minutes à quelques heures.'),
                no_credentials: t('Aucun compte Google utilisable sur cet appareil, ou configuration ')
                    + t('pas encore propagée côté Google (patienter, puis réessayer).'),
                network: t('Problème réseau pendant la connexion Google. Réessayez.'),
                no_idtoken: t('Google n’a pas fourni de jeton d’identité. Réessayez.'),
                plugin_failed: t('Erreur du module Google sur l’appareil.'),
                csrf: t('Session expirée pendant la connexion. Réessayez.'),
            };
            let alertBox = document.querySelector('.login-box .alert-error');
            if (!alertBox) {
                alertBox = document.createElement('div');
                alertBox.className = 'alert alert-error';
                btn.parentNode.insertBefore(alertBox, btn);
            }
            let text = messages[code] || messages.oauth_failed;
            // Détail technique brut : précieux pour diagnostiquer (app interne).
            if (detail) text += ' [' + String(detail).slice(0, 180) + ']';
            alertBox.textContent = text;
        };

        /** POST de l'idToken Google au serveur, avec le jeton CSRF fourni. */
        const postIdToken = (idToken, token) => fetch('/auth/google-token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': token,
            },
            body: JSON.stringify({ id_token: idToken }),
        });

        /** Jeton CSRF frais (met aussi à jour la balise meta). '' si échec. */
        const refreshCsrf = async () => {
            try {
                const res = await fetch('/auth/csrf', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                const token = (data && data.token) || '';
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta && token) meta.setAttribute('content', token);
                return token;
            } catch (_) {
                return '';
            }
        };

        // Classement des erreurs du plugin natif (Credential Manager Android /
        // SDK Google iOS) vers un message actionnable.
        const classifyPluginError = (msg) => {
            const m = msg.toLowerCase();
            if (/(developer.?error|code[: ]*10|invalid.?audience|unregistered)/.test(m)) return 'developer_error';
            if (/(no.?credential|getcredentialexception|no accounts|sign.?in.?required|16\b)/.test(m)) return 'no_credentials';
            if (/(network|timeout|7\b|unreachable)/.test(m)) return 'network';
            return 'plugin_failed';
        };

        if (!SocialLogin || !ids.web) {
            // Plugin absent ou client ID non configuré : on masque le bouton,
            // la connexion email/mot de passe reste disponible.
            btn.style.display = 'none';
            return;
        }

        let ready = false;
        const ensureInit = async () => {
            if (ready) return;
            const google = { webClientId: ids.web };
            if (NativeBridge.platform === 'ios' && ids.ios) {
                google.iOSClientId = ids.ios;
                google.iOSServerClientId = ids.web;
            }
            await SocialLogin.initialize({ google });
            ready = true;
        };

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            let phase = 'plugin'; // pour distinguer échec appareil / échec serveur
            try {
                await ensureInit();
                // Pas de `scopes` : sur Android ils exigeraient une MainActivity
                // modifiée (doc du plugin), et l'idToken par défaut porte déjà
                // email + profil — tout ce dont le serveur a besoin.
                const res = await SocialLogin.login({ provider: 'google', options: {} });
                const idToken = (res && res.result && (res.result.idToken
                    || (res.result.accessToken && res.result.accessToken.idToken))) || null;
                if (!idToken) { showError('no_idtoken'); return; }

                phase = 'server';
                let httpRes = await postIdToken(idToken, csrfToken());
                // 403 = jeton CSRF refusé : la page de connexion peut avoir été
                // chargée avant une expiration de session ou un redéploiement
                // (les sessions PHP vivent dans le conteneur). Jeton frais puis
                // rejeu UNE fois — l'idToken Google n'est pas consommé et reste
                // valable ~1 h.
                if (httpRes.status === 403) {
                    const fresh = await refreshCsrf();
                    if (fresh) httpRes = await postIdToken(idToken, fresh);
                }
                const data = await httpRes.json().catch(() => ({}));
                if (httpRes.ok && data.ok) {
                    location.href = '/';
                    return;
                }
                showError(data.code || data.error || 'oauth_failed', 'HTTP ' + httpRes.status);
            } catch (err) {
                const msg = String((err && err.message) || err || '');
                console.error('[GoogleLogin]', phase, err);
                // Annulation utilisateur du sélecteur de compte : pas d'erreur.
                if (/cancel|annul|dismiss/i.test(msg)) return;
                if (phase === 'server') showError('network', msg);
                else showError(classifyPluginError(msg), msg);
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- Démarrage --------------------------------------------------------
    function init() {
        syncStatusBar();
        // Thème « Automatique » (Réglages → Affichage) : l'appareil passe en
        // mode sombre pendant que l'application est ouverte, ou la page de
        // réglages change de thème — la barre de statut suit.
        document.addEventListener('mia:themechange', syncStatusBar);
        setupEnvSwitch();
        watchAppResume();
        watchNetwork();
        setupExternalLinks();
        setupConnectorLinks();
        setupWindowOpen();
        setupGoogleLogin();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
