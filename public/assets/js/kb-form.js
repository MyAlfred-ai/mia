/**
 * Fiche d'une base de connaissances — Espaces → Bases de connaissances
 * → « + Nouvelle base » / « Modifier » (templates/admin/knowledge-bases/edit.php).
 *
 * La page porte ses valeurs, rendues par le serveur, en trois questions (ce
 * qu'elle contient, d'où viennent les documents, qui s'en sert) ; ce script
 * ne fait que ce qu'un rendu ne peut pas faire :
 *  - les SOURCES : la source choisie déplie sa configuration (dossier, accès,
 *    synchronisation, connexion d'un cerveau), « Tester », l'explorateur de
 *    dossiers déplié sous le champ, le dépôt de documents (glisser-déposer,
 *    plusieurs à la fois) et leur liste ;
 *  - l'ÉTAT de la base (panneau de la question 2 et ligne de l'en-tête),
 *    rafraîchi tant qu'elle travaille ;
 *  - QUI LA VOIT (moi seul, moi et les administrateurs, partagée) : le choix
 *    pose la case cachée « Uniquement pour moi » et retire les partages ;
 *  - les PASTILLES du partage et des assistants liés, tirées des listes à
 *    cocher (celles qui partent dans le POST), dépliées sous « Ajouter » :
 *    recherche, Tout / Aucun, cases « peut modifier », « cochée par défaut »,
 *    « peut nourrir » liées à leur ligne ;
 *  - les VOLETS : chaque question se replie sur une ligne qui la résume ;
 *    ce que la personne a ouvert ou fermé est retenu par base et par
 *    appareil, et un problème nouveau rouvre « D'où viennent les documents » ;
 *  - la LISTE COMPACTE des documents : cinq lignes, les plus urgentes
 *    d'abord, « Voir les N autres », filtres par état, recherche par nom ;
 *  - le menu « … » de l'en-tête (réglages avancés, suppression) ;
 *  - le VOLET des extraits d'un document, ouvert d'un clic sur un document
 *    qui en a (même chargeur que « Avancé ») ;
 *  - la section « Avancé » : l'explication du choix de réindexation et le
 *    détail des extraits vectorisés, chargé à l'ouverture.
 *
 * Textes affichés : t() / tn() (dictionnaire CLIENT, lang/en.client.php) ;
 * libellés des sections et des infobulles : rendus traduits par le serveur.
 *
 * État de départ : window.KB_FORM (AdminController::renderKnowledgeBaseForm).
 * Enregistrement : celui du formulaire (autosave.js en modification, « Créer
 * la base » en création) — ce script ne poste lui-même que les gestes
 * explicites (tester, parcourir, déposer ou supprimer un document,
 * synchroniser, tester et enregistrer un cerveau).
 */
(function () {
    'use strict';

    const C    = window.KB_FORM;
    const form = document.getElementById('editKbForm');
    if (!C || !form) return;

    const byId = (id) => document.getElementById(id);
    const show = (el, on) => { if (el) el.hidden = !on; };
    // Les résumés des volets s'écrivent une fois tout en place (Démarrage) :
    // avant, les éléments qu'ils lisent ne sont pas encore repérés.
    let foldsReady = false;

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    /** POST AJAX (jeton CSRF en champ ET en en-tête) ; rend la réponse et son JSON. */
    async function post(url, fields) {
        const fd = new FormData();
        fd.append('csrf_token', csrf());
        Object.keys(fields || {}).forEach((k) => fd.append(k, fields[k]));
        const r = await fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await r.json().catch(() => ({}));
        return { r, data };
    }

    // Langue de la page (window.I18N, posé par head-meta) : nombres, tailles.
    const EN     = !!(window.I18N && window.I18N.lang === 'en');
    const LOCALE = EN ? 'en-US' : 'fr-FR';

    // Une unité par clé : « 12,5 Ko » / « 12.5 KB ».
    const SIZE_UNITS = [
        (v) => t('{n} o', { n: v }),
        (v) => t('{n} Ko', { n: v }),
        (v) => t('{n} Mo', { n: v }),
        (v) => t('{n} Go', { n: v }),
    ];

    function formatBytes(n) {
        if (!Number.isFinite(n) || n <= 0) return SIZE_UNITS[0](0);
        let i = 0;
        while (n >= 1024 && i < SIZE_UNITS.length - 1) { n /= 1024; i++; }
        const value = i === 0 ? String(n) : (EN ? n.toFixed(1) : n.toFixed(1).replace('.', ','));
        return SIZE_UNITS[i](value);
    }

    // =====================================================================
    // Infobulles (points « i » de la fiche)
    // =====================================================================
    // Le détail d'un réglage vit dans `data-tip`. UNE bulle, posée sur <body> :
    // dans une carte, elle serait rognée, ou ferait défiler la page de côté
    // au téléphone. Souris : au survol ; clavier et doigt : au focus (le
    // point est focalisable, le toucher le focalise). Échap ou défilement la
    // referment. Positions mesurées en pixels d'écran, écrites en pixels de
    // page (÷ miaUiZoom, taille d'affichage de la personne).
    (function tips() {
        let bubble = null;
        let owner  = null;
        let timer  = null;
        const zoom = () => (typeof window.miaUiZoom === 'function' ? (window.miaUiZoom() || 1) : 1);

        function hide() {
            clearTimeout(timer);
            if (owner) owner.classList.remove('is-on');
            owner = null;
            if (bubble) bubble.hidden = true;
        }
        function showFor(el) {
            const text = el.dataset.tip || '';
            if (text === '') return;
            if (!bubble) {
                bubble = document.createElement('div');
                bubble.className = 'kbf-bubble';
                bubble.setAttribute('aria-hidden', 'true'); // le point porte déjà son aria-label
                bubble.hidden = true;
                document.body.appendChild(bubble);
            }
            if (owner && owner !== el) owner.classList.remove('is-on');
            owner = el;
            el.classList.add('is-on');
            bubble.textContent = text;
            bubble.style.left = '0px';
            bubble.style.top = '0px';
            bubble.hidden = false;
            const z = zoom();
            const r = el.getBoundingClientRect();
            const w = bubble.offsetWidth * z;
            const h = bubble.offsetHeight * z;
            const margin = 12;
            let left = r.left + r.width / 2 - w / 2;
            left = Math.max(margin, Math.min(left, window.innerWidth - margin - w));
            // Dessous ; au-dessus si la place manque en bas.
            let top = r.bottom + 8;
            if (top + h > window.innerHeight - margin && r.top - 8 - h > margin) top = r.top - 8 - h;
            bubble.style.left = (left / z) + 'px';
            bubble.style.top = (top / z) + 'px';
        }

        document.addEventListener('pointerover', (e) => {
            if (e.pointerType !== 'mouse') return;
            const el = e.target.closest('.kbf-tip');
            if (!el) return;
            clearTimeout(timer);
            timer = setTimeout(() => showFor(el), owner ? 0 : 120);
        });
        document.addEventListener('pointerout', (e) => {
            if (e.pointerType !== 'mouse') return;
            const el = e.target.closest('.kbf-tip');
            if (!el || (e.relatedTarget && el.contains(e.relatedTarget))) return;
            if (document.activeElement === el) return;
            hide();
        });
        document.addEventListener('focusin', (e) => {
            const el = e.target.closest && e.target.closest('.kbf-tip');
            if (el) showFor(el);
        });
        document.addEventListener('focusout', (e) => {
            if (e.target.closest && e.target.closest('.kbf-tip') === owner) hide();
        });
        // Filet du toucher : un moteur qui ne focalise pas au toucher.
        document.addEventListener('click', (e) => {
            const el = e.target.closest('.kbf-tip');
            if (el && owner !== el) showFor(el);
            else if (!el && owner && document.activeElement !== owner) hide();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && owner) hide();
        });
        window.addEventListener('scroll', () => { if (owner) hide(); }, true);
        window.addEventListener('resize', () => { if (owner) hide(); });
    })();

    const saved = Object.assign({ provider: '', url: '', auto: true, auth: 'key', owner: '', folder: '' }, C.source || {});
    let currentDocs = (C.docs || []).slice();

    // =====================================================================
    // Sources : la tuile choisie et sa configuration
    // =====================================================================

    // Textes par fournisseur (configuration du dossier, pastille des documents).
    const PROVIDER_META = {
        drive: {
            label: 'Google Drive',
            badge: 'Drive',
            icon: '/assets/img/google-drive.svg',
            short: t('Un dossier tenu en miroir, sous-dossiers compris.'),
            urlLabel: t('Dossier Google Drive'),
            placeholder: 'https://drive.google.com/drive/folders/…',
            browsable: true, // seulement en mode « ma connexion » (cf. canBrowse)
            hint: t('Le dossier et ses sous-dossiers sont tenus en miroir : ajouts, modifications, renommages et suppressions sont répercutés dans la base. Le dossier doit être partagé « Tous les utilisateurs disposant du lien » (Lecteur). Les Google Docs, Sheets et Slides sont convertis automatiquement. Pour un Drive partagé, préférez « Ma connexion Google Drive ».'),
            hintOwner: t('Le dossier et ses sous-dossiers sont tenus en miroir : ajouts, modifications, renommages et suppressions sont répercutés dans la base. Il est lu avec VOTRE connexion Google Drive : il suffit qu\'il vous appartienne, soit partagé avec votre compte ou se trouve dans un Drive partagé dont vous êtes membre — aucun partage public.'),
        },
        onedrive: {
            label: 'OneDrive',
            badge: 'OneDrive',
            icon: '/assets/img/onedrive.svg',
            short: t('Un dossier tenu en miroir, sous-dossiers compris.'),
            urlLabel: t('Dossier OneDrive'),
            placeholder: t('Choisissez un dossier avec « Parcourir… »'),
            browsable: true,
            ownerOnly: true,
            hint: t('Le dossier et ses sous-dossiers sont tenus en miroir. Il est lu avec VOTRE connexion OneDrive : « Parcourir… » vous montre vos dossiers et ceux partagés avec vous — aucun partage public, aucun lien à copier.'),
        },
        sharepoint: {
            label: 'SharePoint',
            badge: 'SharePoint',
            icon: '/assets/img/sharepoint.svg',
            short: t('Un dossier tenu en miroir, sous-dossiers compris.'),
            urlLabel: t('Dossier SharePoint'),
            placeholder: t('https://votretenant.sharepoint.com/sites/…'),
            hint: t('Le dossier et ses sous-dossiers sont tenus en miroir. Collez l\'adresse du dossier (« Copier le lien » de SharePoint, ou la barre d\'adresse) : l\'accès passe par l\'application Azure de votre organisation, aucun partage public n\'est requis.'),
        },
        gcalendar: {
            label: t('Google Agenda'),
            badge: t('Agenda'),
            icon: '/assets/img/google-calendar.svg',
            short: t('Un agenda public tenu en miroir, un document par mois.'),
            urlLabel: t('Agenda Google'),
            placeholder: t('adresse@gmail.com, xxx@group.calendar.google.com ou URL publique'),
            hint: t('L\'agenda est tenu en miroir, un document par mois (3 mois en arrière, 12 en avant). Il doit être public — Paramètres et partage de l\'agenda → « Rendre disponible publiquement ». Collez son identifiant (Paramètres → « Intégrer l\'agenda ») ou son URL publique.'),
        },
    };

    const tilesBox       = byId('kbSourcesField');
    const syncConfig     = byId('kbSyncConfig');
    const syncHintText   = byId('kbSyncHintText');
    const syncHintTip    = byId('kbSyncHintTip');
    const syncUrlLabel   = byId('kbSyncUrlLabel');
    const syncUrl        = byId('kbSyncUrl');
    const syncTestBtn    = byId('kbSyncTestBtn');
    const browseBtn      = byId('kbSyncBrowseBtn');
    const oneDriveNote   = byId('kbOneDriveConnectNote');
    const pickedNameEl   = byId('kbSyncPickedName');
    const syncUrlHelp    = byId('kbSyncUrlHelp');
    const browseHelp     = byId('kbSyncBrowseHelp');
    const syncTestOut    = byId('kbSyncTestResult');
    const syncEnabled    = byId('kbSyncEnabled');
    const syncSwitchWarn = byId('kbSyncSwitchWarn');
    const docsBlock      = byId('kbDocsBlock');
    const advanced       = byId('avance');
    const embSelect      = byId('kbEmbeddingChoice');

    // Accès Drive : clé API globale vs connexion personnelle de l'enregistreur.
    const driveAuthRow   = byId('kbDriveAuthRow');
    const authOwnerNote  = byId('kbAuthOwnerNote');
    const DRIVE_KEY_OK   = !!driveAuthRow && driveAuthRow.dataset.keyConfigured === '1';
    const DRIVE_OWNER_OK = !!driveAuthRow && driveAuthRow.dataset.ownerReady === '1';
    const oneDriveTile   = tilesBox ? tilesBox.querySelector('[data-provider="onedrive"]') : null;
    const ONEDRIVE_ON    = !!oneDriveTile && oneDriveTile.dataset.configured === '1';
    const ONEDRIVE_OWNER = !!oneDriveTile && oneDriveTile.dataset.ownerReady === '1';

    // Nom lisible du dossier retenu (affichage) et la source à laquelle il vaut.
    let pickedName = saved.folder || '';
    let pickedFor  = saved.provider;

    function selectedProvider() {
        const checked = form.querySelector('input[name="sync_provider"]:checked');
        return checked ? checked.value : '';
    }

    function isLive() {
        return selectedProvider() === 'mybrain';
    }

    // Accès OneDrive : il n'y a PAS de choix — l'application Microsoft n'a
    // aucune permission propre, tout passe par la connexion de l'utilisateur.
    function selectedAuth() {
        const meta = PROVIDER_META[selectedProvider()];
        if (meta && meta.ownerOnly) return 'owner';
        const checked = form.querySelector('input[name="sync_auth"]:checked');
        return checked ? checked.value : 'key';
    }

    // Fournisseurs lus par une connexion personnelle (miroir de KbSync::OWNER_PROVIDERS).
    function canOwnerAuth(p) {
        return p === 'drive' || p === 'onedrive';
    }

    // Que faire, concrètement — selon ce qui est disponible. Un « Parcourir… »
    // sans mode d'emploi laisse deviner d'où viennent les dossiers proposés.
    function browseSteps(p) {
        if (p === 'onedrive') {
            if (!ONEDRIVE_ON) return '';
            if (!ONEDRIVE_OWNER) {
                return t('Marche à suivre : 1. connectez votre OneDrive (Réglages → Comptes connectés) — 2. revenez ici et cliquez « Parcourir… » — 3. choisissez le dossier à indexer.');
            }
            return t('Marche à suivre : 1. « Parcourir… » — 2. descendez dans « Mon OneDrive » ou « Partagés avec moi » jusqu\'au dossier voulu — 3. « Choisir ce dossier ». Les sous-dossiers sont inclus ; les fichiers non pris en charge sont ignorés (l\'explorateur les signale).');
        }
        if (p === 'drive' && selectedAuth() === 'owner') {
            return DRIVE_OWNER_OK
                ? t('Marche à suivre : « Parcourir… » affiche « Mon Drive », vos « Drive partagés » et les dossiers « Partagés avec moi » — un Drive partagé se choisit entier ou dossier par dossier. Ou collez le lien du dossier, puis « Tester ». Les sous-dossiers sont inclus.')
                : t('Connectez d\'abord votre Google Drive (Réglages → Comptes connectés) pour parcourir vos dossiers.');
        }
        return '';
    }

    function canBrowse() {
        const p    = selectedProvider();
        const meta = PROVIDER_META[p];
        if (!meta || !meta.browsable) return false;
        if (p === 'drive')    return selectedAuth() === 'owner' && DRIVE_OWNER_OK;
        if (p === 'onedrive') return ONEDRIVE_ON && ONEDRIVE_OWNER;
        return false;
    }

    // Coche un accès en respectant les radios désactivées : un mode
    // indisponible ne doit jamais partir dans le POST.
    function setAuth(value) {
        if (!driveAuthRow) return;
        let radio = form.querySelector('input[name="sync_auth"][value="' + value + '"]');
        if (!radio || radio.disabled) radio = form.querySelector('input[name="sync_auth"]:not(:disabled)');
        form.querySelectorAll('input[name="sync_auth"]').forEach((r) => { r.checked = false; });
        if (radio) radio.checked = true;
    }

    function defaultAuth() {
        return DRIVE_KEY_OK ? 'key' : 'owner';
    }

    function clearSyncTest() {
        if (syncTestOut) { syncTestOut.textContent = ''; show(syncTestOut, false); }
    }

    // La section Sources suit la tuile choisie : configuration du dossier,
    // textes du fournisseur, explorateur, avertissement de changement de
    // source ; une base en direct remplace tout cela par sa connexion.
    function updateFeedUI() {
        const p    = selectedProvider();
        const meta = PROVIDER_META[p] || null;
        show(syncConfig, !!meta);
        show(driveAuthRow, p === 'drive');
        if (meta) {
            const ownerMode = p === 'drive' && selectedAuth() === 'owner';
            // Une ligne ; le détail, propre au fournisseur, dans l'infobulle.
            const detail = (ownerMode && meta.hintOwner) ? meta.hintOwner : meta.hint;
            if (syncHintText) syncHintText.textContent = meta.short;
            if (syncHintTip) {
                syncHintTip.dataset.tip = detail;
                syncHintTip.setAttribute('aria-label', detail);
            }
            if (syncUrlLabel) syncUrlLabel.textContent = meta.urlLabel;
            if (syncUrl)      syncUrl.placeholder      = meta.placeholder;
        }
        show(browseBtn, canBrowse());
        if (!canBrowse()) closeBrowse();
        show(oneDriveNote, p === 'onedrive' && ONEDRIVE_ON && !ONEDRIVE_OWNER);
        // Une référence OneDrive n'est pas un lien lisible ({driveId}:{itemId}) :
        // le nom du dossier retenu est affiché à part.
        if (pickedNameEl) {
            const on = pickedName !== '' && p === pickedFor;
            pickedNameEl.textContent = on ? t('Dossier : {nom}', { nom: pickedName }) : '';
            show(pickedNameEl, on);
        }
        show(syncUrlHelp, !!meta && p !== 'onedrive');
        if (browseHelp) {
            const steps = browseSteps(p);
            browseHelp.textContent = steps;
            show(browseHelp, steps !== '');
        }
        // Base enregistrée sur la connexion de quelqu'un d'autre : enregistrer
        // en mode « ma connexion » la re-liera à MA connexion.
        if (authOwnerNote) {
            const on = selectedAuth() === 'owner' && canOwnerAuth(p)
                && !C.create && saved.auth === 'owner' && saved.owner !== '' && saved.owner !== C.userName;
            authOwnerNote.textContent = on
                ? t('Actuellement synchronisée avec la connexion de {nom} — enregistrer la basculera sur la vôtre.', { nom: saved.owner })
                : '';
            show(authOwnerNote, on);
        }
        const hasSyncedDocs = currentDocs.some((d) => d.source && d.source !== 'upload');
        show(syncSwitchWarn, !C.create && saved.provider !== '' && p !== saved.provider && hasSyncedDocs);
        if (tilesBox) {
            tilesBox.querySelectorAll('.kbf-pill').forEach((tile) => {
                const input = tile.querySelector('input[name="sync_provider"]');
                tile.classList.toggle('is-selected', !!input && input.checked);
            });
        }
        updateBackendUI();
        refreshFoldSummaries();
    }

    // ── Base en direct (source « Cerveau MyBrain ») ──
    // Pas une source de documents : un cerveau interrogé à chaque message. La
    // source retire de la fiche ce qui n'a plus de sens (documents, modèle
    // d'embedding) et fait parler le partage de « qui interroge ».
    const myBrainConfig  = byId('kbMyBrainConfig');
    const myBrainUrl     = byId('kbMyBrainUrl');
    const myBrainKey     = byId('kbMyBrainKey');
    const myBrainKeyHint = byId('kbMyBrainKeyHint');
    const myBrainTestBtn = byId('kbMyBrainTestBtn');
    const myBrainResult  = byId('kbMyBrainTestResult');
    const shareHintLocal = byId('kbShareHintLocal');
    const shareHintLive  = byId('kbShareHintLive');
    const docsCreateIn   = byId('kbDocsCreateInput');

    function setMyBrainResult(text, tone) {
        if (!myBrainResult) return;
        myBrainResult.textContent = text;
        myBrainResult.dataset.tone = tone || '';
        myBrainResult.hidden = text === '';
    }

    function updateBackendUI() {
        const live = isLive();
        show(myBrainConfig, live);
        show(docsBlock, !live);
        // Les sources à configurer ne concernent pas un cerveau.
        show(byId('kbOtherSources'), !live);
        // Aucun fichier ni modèle d'embedding ne part avec un cerveau.
        if (docsCreateIn) docsCreateIn.disabled = live;
        show(advanced, !live);
        if (embSelect) embSelect.disabled = live;
        show(shareHintLocal, !live);
        show(shareHintLive, live);
        form.querySelectorAll('.kb-share-item').forEach((row) => {
            const manageWrap = row.querySelector('.kb-share-manage');
            const defWrap    = row.querySelector('.kb-share-default');
            const feedWrap   = row.querySelector('.kb-share-feed');
            if (manageWrap) manageWrap.hidden = live;
            if (defWrap)    defWrap.hidden    = !live;
            if (feedWrap)   feedWrap.hidden   = !live;
            syncManageBox(row);
        });
        // Les droits montrés sur les pastilles changent avec la nature.
        renderShareChips();
    }

    if (myBrainTestBtn) {
        myBrainTestBtn.addEventListener('click', async () => {
            myBrainTestBtn.disabled = true;
            setMyBrainResult(t('Vérification auprès de MyBrain…'), 'busy');
            try {
                const fields = {
                    mybrain_url: myBrainUrl ? myBrainUrl.value.trim() : '',
                    mybrain_key: myBrainKey ? myBrainKey.value.trim() : '',
                };
                if (C.id) fields.kb_id = String(C.id);
                const { r, data } = await post('/admin/knowledge-bases/mybrain/test', fields);
                if (!r.ok || !data.ok) {
                    setMyBrainResult('✗ ' + (data.error || t('Test impossible.')), 'error');
                    // Un échec de la clé ENREGISTRÉE est consigné côté serveur :
                    // le panneau d'état doit le montrer tout de suite.
                    if (C.id) Status.refresh();
                    return;
                }
                setMyBrainResult('✓ ' + (data.message || t('Connexion vérifiée.')), 'ok');
                if (data.saved) {
                    if (myBrainKey) myBrainKey.value = '';
                    if (myBrainUrl && data.url) myBrainUrl.value = data.url;
                    if (myBrainKeyHint && data.key_hint) {
                        myBrainKeyHint.textContent = t('Clé enregistrée : {indice} — laissez vide pour la garder.', { indice: data.key_hint });
                    }
                    Status.refresh();
                }
            } catch (e) {
                setMyBrainResult('✗ ' + t('Erreur réseau. Réessayez.'), 'error');
            } finally {
                myBrainTestBtn.disabled = false;
            }
        });
    }

    // Changement de source : adresse et synchronisation automatique suivent la
    // source (retour sur la source enregistrée = valeurs enregistrées ;
    // nouvelle source = champ vide, synchronisation pré-cochée).
    if (tilesBox) {
        tilesBox.addEventListener('change', (e) => {
            if (!e.target.matches('input[name="sync_provider"]')) return;
            const p = e.target.value;
            if (syncUrl)     syncUrl.value       = p === saved.provider ? saved.url : '';
            if (syncEnabled) syncEnabled.checked = p === saved.provider ? !!saved.auto : p !== '';
            if (p === 'drive') setAuth(p === saved.provider ? saved.auth : defaultAuth());
            clearSyncTest();
            setMyBrainResult('', '');
            // Une autre source se règle : ses réglages s'ouvrent.
            const syncFold = byId('kbSyncFold');
            if (syncFold && p !== saved.provider) syncFold.open = true;
            updateFeedUI();
        });
    }
    // Changement d'accès Drive : l'aide et le test précédent ne valent plus.
    if (driveAuthRow) {
        driveAuthRow.addEventListener('change', (e) => {
            if (!e.target.matches('input[name="sync_auth"]')) return;
            clearSyncTest();
            updateFeedUI();
        });
    }
    if (syncUrl) {
        syncUrl.addEventListener('input', () => {
            // Un autre lien que celui choisi dans l'explorateur : son nom ne vaut plus.
            if (pickedFor === selectedProvider() && syncUrl.value.trim() !== (pickedRef || saved.url)) {
                pickedName = '';
                pickedFor  = '';
            }
            clearSyncTest();
            updateFeedUI();
        });
    }
    let pickedRef = '';

    // `ok` : true = réussi, false = échec, null = vérification en cours.
    function showSyncTestResult(ok, msg) {
        if (!syncTestOut) return;
        syncTestOut.textContent = (ok === null ? '⏳ ' : (ok ? '✓ ' : '⚠ ')) + msg;
        syncTestOut.classList.toggle('kb-hint-warn', ok === false);
        show(syncTestOut, true);
    }

    if (syncTestBtn) {
        syncTestBtn.addEventListener('click', async () => {
            const provider = selectedProvider();
            const url      = (syncUrl ? syncUrl.value : '').trim();
            if (!PROVIDER_META[provider]) { showSyncTestResult(false, t('Choisissez d\'abord une source en ligne.')); return; }
            if (!url) { showSyncTestResult(false, t('Collez d\'abord le lien du dossier.')); return; }
            syncTestBtn.disabled = true;
            syncTestBtn.textContent = t('Test…');
            // Le résultat remplace souvent une ligne presque identique : sans
            // étape intermédiaire visible, le clic paraissait sans effet.
            showSyncTestResult(null, t('Vérification de l\'accès…'));
            try {
                const { data } = await post('/admin/knowledge-bases/sync/test', {
                    provider, folder_url: url, sync_auth: selectedAuth(),
                });
                showSyncTestResult(!!data.ok, data.ok ? t('Source accessible : « {nom} »', { nom: data.name }) : (data.error || t('Test impossible.')));
                if (data.ok && data.name) {
                    pickedName = String(data.name);
                    pickedFor  = provider;
                    pickedRef  = url;
                }
            } catch (e) {
                showSyncTestResult(false, t('Erreur réseau. Réessayez.'));
            } finally {
                syncTestBtn.disabled = false;
                syncTestBtn.textContent = t('Tester');
            }
        });
    }

    // =====================================================================
    // Explorateur de dossiers (Google Drive « ma connexion » / OneDrive)
    // =====================================================================
    // Déplié sous le champ. Le fil d'Ariane est tenu CÔTÉ CLIENT, empilé au
    // fil de la navigation : le serveur ne rend qu'un niveau à la fois.
    const browse        = byId('kbBrowse');
    const browseBody    = byId('kbBrowseBody');
    const browseCrumbs  = byId('kbBrowseCrumbs');
    const browseUpBtn   = byId('kbBrowseUpBtn');
    const browsePickBtn = byId('kbBrowsePickBtn');
    const browseCount   = byId('kbBrowseCount');
    let browseTrail   = [];   // [{ref, name}] du plus haut au niveau affiché
    let browseCurrent = null; // dernier niveau rendu par le serveur
    let browseBusy    = false;

    function closeBrowse() {
        show(browse, false);
        if (browseBtn) browseBtn.setAttribute('aria-expanded', 'false');
    }

    function renderCrumbs() {
        if (!browseCrumbs) return;
        browseCrumbs.innerHTML = '';
        browseTrail.forEach((step, i) => {
            if (i > 0) {
                const sep = document.createElement('span');
                sep.className = 'kb-crumb-sep';
                sep.textContent = '›';
                browseCrumbs.appendChild(sep);
            }
            if (i === browseTrail.length - 1) {
                const cur = document.createElement('span');
                cur.className = 'kb-crumb-current';
                cur.textContent = step.name;
                browseCrumbs.appendChild(cur);
                return;
            }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = step.name;
            btn.addEventListener('click', () => {
                browseTrail = browseTrail.slice(0, i); // le niveau visé se ré-empile
                loadBrowse(step.ref, step.name);
            });
            browseCrumbs.appendChild(btn);
        });
    }

    function browseMessage(text) {
        browseBody.innerHTML = '';
        const p = document.createElement('p');
        p.className = 'kb-browse-empty';
        p.textContent = text;
        browseBody.appendChild(p);
    }

    function browseRow(tag, icon, name, meta) {
        const row = document.createElement(tag);
        row.className = 'kb-browse-row';
        if (tag === 'button') row.type = 'button';
        const ic = document.createElement('span');
        ic.className = 'kb-browse-row-icon';
        ic.textContent = icon;
        const label = document.createElement('span');
        label.className = 'kb-browse-row-name';
        label.textContent = name;
        row.append(ic, label);
        if (meta) {
            const m = document.createElement('span');
            m.className = 'kb-browse-row-meta';
            m.textContent = meta;
            row.appendChild(m);
        }
        return row;
    }

    function renderBrowseLevel(data) {
        browseBody.innerHTML = '';
        if ((data.folders || []).length === 0 && (data.files || []).length === 0) {
            // Une racine virtuelle vide (aucun Drive partagé) a sa propre
            // explication : « dossier vide » n'y voudrait rien dire.
            browseMessage(data.empty || t('Ce dossier est vide.'));
        }
        (data.folders || []).forEach((f) => {
            const row = browseRow('button', '📁', f.name, '');
            row.addEventListener('click', () => loadBrowse(f.ref, f.name));
            browseBody.appendChild(row);
        });
        if ((data.files || []).length > 0) {
            const sep = document.createElement('p');
            sep.className = 'kb-browse-sep';
            sep.textContent = t('Fichiers de ce dossier');
            browseBody.appendChild(sep);
        }
        (data.files || []).forEach((f) => {
            const row = browseRow('div', f.supported ? '📄' : '🚫', f.name, f.supported ? formatBytes(f.size) : t('format ignoré'));
            row.classList.add('kb-browse-file');
            if (!f.supported) row.classList.add('is-ignored');
            browseBody.appendChild(row);
        });
        // Le compteur porte sur CE dossier ; les sous-dossiers sont repris
        // aussi par la synchronisation, d'où la mention explicite.
        if (browseCount) {
            const n = data.syncable || 0;
            browseCount.textContent = data.selectable
                ? ((data.folders || []).length > 0
                    ? tn(n, '{n} fichier repris ici, sous-dossiers inclus', '{n} fichiers repris ici, sous-dossiers inclus')
                    : tn(n, '{n} fichier repris ici', '{n} fichiers repris ici'))
                : t('Ouvrez un dossier pour le choisir.');
        }
        if (browsePickBtn) browsePickBtn.disabled = !data.selectable;
        if (browseUpBtn)   browseUpBtn.disabled   = browseTrail.length <= 1;
    }

    async function loadBrowse(ref, name) {
        if (!browseBody || browseBusy) return;
        browseBusy = true;
        browseMessage(t('Chargement…'));
        if (browsePickBtn) browsePickBtn.disabled = true;
        try {
            const { data } = await post('/admin/knowledge-bases/sync/browse', {
                provider: selectedProvider(), folder_ref: ref || '',
            });
            if (!data || !data.ok) {
                browseMessage(data && data.error ? data.error : t('Navigation impossible.'));
                if (browseCount) browseCount.textContent = '';
                return;
            }
            browseCurrent = data;
            browseTrail.push({ ref: data.ref, name: name || data.name });
            renderCrumbs();
            renderBrowseLevel(data);
        } catch (e) {
            browseMessage(t('Erreur réseau. Réessayez.'));
        } finally {
            browseBusy = false;
        }
    }

    if (browseBtn) {
        browseBtn.setAttribute('aria-controls', 'kbBrowse');
        browseBtn.setAttribute('aria-expanded', 'false');
        browseBtn.addEventListener('click', () => {
            if (!canBrowse() || !browse) return;
            if (!browse.hidden) { closeBrowse(); return; }
            browseTrail   = [];
            browseCurrent = null;
            show(browse, true);
            browseBtn.setAttribute('aria-expanded', 'true');
            // Toujours ouvert à la racine du compte : rouvrir sur le dossier
            // enregistré interdirait de remonter au-dessus de lui.
            loadBrowse('', '');
        });
    }
    if (browseUpBtn) {
        browseUpBtn.addEventListener('click', () => {
            if (browseTrail.length <= 1) return;
            const parent = browseTrail[browseTrail.length - 2];
            browseTrail = browseTrail.slice(0, browseTrail.length - 2);
            loadBrowse(parent.ref, parent.name);
        });
    }
    if (browsePickBtn) {
        browsePickBtn.addEventListener('click', () => {
            if (!browseCurrent || !browseCurrent.selectable) return;
            if (syncUrl) syncUrl.value = browseCurrent.ref;
            pickedName = browseCurrent.name || '';
            pickedFor  = selectedProvider();
            pickedRef  = browseCurrent.ref;
            if (syncEnabled && !syncEnabled.checked) syncEnabled.checked = true;
            // Le dossier vient d'être listé avec succès : le dire tout de suite
            // évite un « Tester » redondant sur ce qu'on vient de parcourir.
            showSyncTestResult(true, t('Dossier choisi : « {nom} »', { nom: pickedName }));
            closeBrowse();
            updateFeedUI();
            // L'enregistrement automatique ne voit pas une affectation par script.
            if (syncUrl) syncUrl.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
    byId('kbBrowseCloseBtn')?.addEventListener('click', closeBrowse);
    byId('kbBrowseCancelBtn')?.addEventListener('click', closeBrowse);

    // =====================================================================
    // Documents de la base
    // =====================================================================
    // Le verdict de chaque document (libellé, ton, motif) est calculé côté
    // serveur par KbStatus::documentState — la liste l'affiche tel quel, les
    // cartes s'appuient sur la même règle : elles ne peuvent pas se contredire.
    const docsList    = byId('kbDocumentsList');
    const drawerEl    = byId('kbDocDrawer');
    const docsToolbar = byId('kbDocsToolbar');
    const docsCount   = byId('kbDocsCount');
    const docErrorEl  = byId('kbDocError');
    const docFileIn   = byId('kbDocFileInput');
    const drop        = byId('kbDrop');
    const pendingList = byId('kbPendingFiles');
    const headDocs    = byId('kbHeadDocs');
    const docsFilters = byId('kbDocsFilters');
    const docsSearch  = byId('kbDocsSearch');
    const docsMore    = byId('kbDocsMore');
    let docsSignature = '';
    // Le volet des extraits (plus bas) suit la liste redessinée.
    let onDocsRendered = null;

    // Liste compacte : cinq lignes (ce qui demande l'attention d'abord), puis
    // « Voir les N autres » ; au-delà de cinq documents, des filtres par état
    // — seulement s'il y a deux états au moins —, et dès huit une recherche
    // par nom. Le volet des extraits passe d'un document à l'autre dans CET
    // ordre (docsView), lignes repliées comprises.
    const DOCS_VISIBLE    = 5;
    const DOCS_SEARCH_MIN = 8;
    const docTone = (d) => (d.state && d.state.tone) || 'idle';
    const docExcluded = (d) => !!d.excluded || (d.state && d.state.key === 'excluded');
    const DOC_FILTERS = [
        { key: 'all',      label: () => t('Tous') },
        { key: 'check',    label: () => t('À vérifier'), tone: 'warn', test: (d) => docIsProblem(d) },
        { key: 'busy',     label: () => t('En cours'),   tone: 'busy', test: (d) => !docExcluded(d) && (docTone(d) === 'busy' || docTone(d) === 'idle') },
        { key: 'ok',       label: () => t('Indexés'),    tone: 'ok',   test: (d) => docTone(d) === 'ok' },
        { key: 'excluded', label: () => t('Exclus'),     tone: 'idle', test: (d) => docExcluded(d) },
    ];
    let docsFilter   = 'all';
    let docsQuery    = '';
    let docsExpanded = false;
    let docsView     = [];

    // Recherche sans casse ni accents : « regle » trouve « Règlement ».
    const plainName = (s) => String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

    function showDocError(msg) {
        if (!docErrorEl) return;
        docErrorEl.textContent = msg || '';
        show(docErrorEl, !!msg);
    }

    function docIsProblem(d) {
        const tone = d.state && d.state.tone;
        return tone === 'error' || tone === 'warn';
    }

    // Les filtres sont des LIENS : en lecture seule, le fieldset désactivé
    // désactiverait des boutons. Rebâtis seulement quand leur liste change
    // (le focus reste sur le filtre touché) ; sinon les nombres suivent.
    function renderDocsToolbar(all) {
        if (!docsToolbar) return;
        const shown = DOC_FILTERS
            .map((f) => ({ f, n: f.test ? all.filter(f.test).length : all.length }))
            .filter((x) => x.f.key === 'all' || x.n > 0);
        const chips = all.length > DOCS_VISIBLE && shown.length > 2 ? shown : [];
        if (!chips.some((x) => x.f.key === docsFilter)) docsFilter = 'all';
        // La recherche est un champ : désactivé avec le reste en lecture seule.
        const searchOn = !C.readOnly && !!docsSearch && all.length >= DOCS_SEARCH_MIN;
        if (!searchOn && docsQuery !== '') { docsQuery = ''; if (docsSearch) docsSearch.value = ''; }
        show(docsSearch, searchOn);
        show(docsToolbar, chips.length > 0 || searchOn);
        if (!docsFilters) return;
        const keys = chips.map((x) => x.f.key).join(',');
        if (docsFilters.dataset.keys !== keys) {
            docsFilters.dataset.keys = keys;
            docsFilters.innerHTML = '';
            chips.forEach(({ f }) => {
                const a = document.createElement('a');
                a.href = '#kbDocumentsList';
                a.className = 'kb-docs-chip';
                a.setAttribute('role', 'button');
                a.dataset.filter = f.key;
                if (f.tone) a.dataset.tone = f.tone;
                const label = document.createElement('span');
                label.className = 'kb-docs-chip-label';
                label.textContent = f.label();
                const n = document.createElement('span');
                n.className = 'kb-docs-chip-n';
                a.append(label, n);
                docsFilters.appendChild(a);
            });
        }
        show(docsFilters, chips.length > 0);
        chips.forEach(({ f, n }) => {
            const a = docsFilters.querySelector('[data-filter="' + f.key + '"]');
            if (!a) return;
            a.querySelector('.kb-docs-chip-n').textContent = String(n);
            a.setAttribute('aria-pressed', f.key === docsFilter ? 'true' : 'false');
        });
    }

    function setDocsFilter(key) {
        if (key === docsFilter) return;
        docsFilter = key;
        docsExpanded = false;
        renderDocuments(currentDocs);
    }
    docsFilters?.addEventListener('click', (e) => {
        const a = e.target.closest('[data-filter]');
        if (!a) return;
        e.preventDefault();
        setDocsFilter(a.dataset.filter);
    });
    // Un lien rôle bouton : Espace l'active aussi.
    docsFilters?.addEventListener('keydown', (e) => {
        const a = e.target.closest('[data-filter]');
        if (!a || e.key !== ' ') return;
        e.preventDefault();
        setDocsFilter(a.dataset.filter);
    });
    docsSearch?.addEventListener('input', () => {
        docsQuery = docsSearch.value;
        renderDocuments(currentDocs);
    });

    // « Voir les N autres » / « Réduire la liste » : un seul lien, gardé
    // d'un rendu à l'autre (il garde le focus).
    let docsMoreLink = null;
    function renderDocsMore(hidden, expandedBeyond) {
        if (!docsMore) return;
        const on = hidden > 0 || expandedBeyond;
        show(docsMore, on);
        if (!on) return;
        if (!docsMoreLink) {
            docsMoreLink = document.createElement('a');
            docsMoreLink.href = '#kbDocumentsList';
            docsMoreLink.className = 'kb-docs-more-link';
            docsMoreLink.setAttribute('role', 'button');
            docsMoreLink.setAttribute('aria-controls', 'kbDocumentsList');
            const toggle = (e) => {
                e.preventDefault();
                const first = docsExpanded ? null : DOCS_VISIBLE;
                docsExpanded = !docsExpanded;
                renderDocuments(currentDocs);
                // Déplié : le regard (et le clavier) reprend au premier document
                // révélé ; replié : la liste revient en vue.
                if (first !== null) {
                    const row = docsList ? docsList.children[first] : null;
                    row?.querySelector('a.kb-doc-open')?.focus({ preventScroll: true });
                } else if (docsList && docsList.getBoundingClientRect().top < 0) {
                    docsList.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
                }
            };
            docsMoreLink.addEventListener('click', toggle);
            docsMoreLink.addEventListener('keydown', (e) => { if (e.key === ' ') toggle(e); });
            docsMore.appendChild(docsMoreLink);
        }
        docsMoreLink.setAttribute('aria-expanded', hidden > 0 ? 'false' : 'true');
        docsMoreLink.textContent = hidden > 0
            ? tn(hidden, 'Voir l’autre document', 'Voir les {n} autres documents')
            : t('Réduire la liste');
    }

    function renderDocuments(docs) {
        if (!docsList) return;
        const all = docs || [];
        docsSignature = JSON.stringify(all);
        docsList.innerHTML = '';
        if (docsCount) docsCount.textContent = all.length === 0 ? '' : String(all.length);
        if (headDocs) headDocs.textContent = tn(all.length, '{n} document', '{n} documents');
        // Ce qui demande l'attention d'abord (échec, puis à vérifier, puis en
        // cours) : en bas d'une liste de 100 fichiers, un échec ne se voit pas.
        const rank = { error: 0, warn: 1, busy: 2, idle: 3, ok: 4 };
        // Les exclus en dernier : ils ne demandent plus rien.
        const sorted = all
            .map((d, i) => ({ d, i, r: docExcluded(d) ? 5 : (rank[docTone(d)] ?? 3) }))
            .sort((a, b) => a.r - b.r || a.i - b.i)
            .map((x) => x.d);
        renderDocsToolbar(sorted);
        const filter = DOC_FILTERS.find((f) => f.key === docsFilter) || DOC_FILTERS[0];
        const q = plainName(docsQuery.trim());
        docsView = sorted.filter((d) => (!filter.test || filter.test(d)) && (q === '' || plainName(d.file_name).includes(q)));
        if (docsView.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'kb-docs-empty';
            empty.textContent = all.length === 0 ? t('Aucun document pour l\'instant.') : t('Aucun document ne correspond.');
            docsList.appendChild(empty);
        }
        // Une recherche montre tout ce qu'elle trouve.
        const limit = docsExpanded || q !== '' ? docsView.length : Math.min(docsView.length, DOCS_VISIBLE);
        docsView.slice(0, limit).forEach((d) => docsList.appendChild(documentRow(d)));
        renderDocsMore(docsView.length - limit, docsExpanded && q === '' && docsView.length > DOCS_VISIBLE);
        refreshFoldSummaries();
        if (onDocsRendered) onDocsRendered();
    }

    /**
     * Le lien d'un document dans la liste, la liste dépliée et son volet
     * ouvert s'il le faut (le volet des extraits y rend le focus).
     */
    function revealDoc(id) {
        const find = () => (docsList ? docsList.querySelector('.kb-doc-open[data-doc-id="' + id + '"]') : null);
        let link = find();
        if (!link && docsView.some((d) => d.id === id)) {
            docsExpanded = true;
            renderDocuments(currentDocs);
            link = find();
        }
        if (link) openFoldOf(link);
        return link;
    }

    function documentRow(d) {
        const st  = d.state || { tone: 'idle', label: '', detail: '' };
        const row = document.createElement('div');
        row.className = 'kb-doc';
        row.dataset.tone = st.tone;

        const info = document.createElement('div');
        info.className = 'kb-doc-main';
        const name = document.createElement('div');
        name.className = 'kb-doc-name';
        // Chaque document s'ouvre dans sa fiche (volet) : étapes, pourquoi il
        // pose problème, nouvel essai ou exclusion, extraits. Son nom est un
        // lien, étendu à toute la ligne (hors corbeille).
        const viewable = !!drawerEl;
        const title = document.createElement(viewable ? 'a' : 'span');
        title.className = 'kb-doc-title';
        title.textContent = d.file_name;
        if (viewable) {
            row.classList.add('is-viewable');
            title.classList.add('kb-doc-open');
            title.href = '#document-' + d.id;
            title.dataset.docId = String(d.id);
            title.setAttribute('aria-haspopup', 'dialog');
            title.title = t('Voir le détail de « {nom} »', { nom: d.file_name });
        } else {
            title.title = d.file_name;
        }
        name.appendChild(title);
        const source = d.source || 'upload';
        const synced = source !== 'upload';
        if (synced) {
            const meta = PROVIDER_META[source] || { label: source, badge: source, icon: null };
            const chip = document.createElement('span');
            chip.className = 'kb-doc-src';
            chip.title = t('Document repris de {source}', { source: meta.label });
            if (meta.icon) {
                const img = document.createElement('img');
                img.src = meta.icon;
                img.alt = '';
                img.width = 11;
                img.height = 10;
                chip.appendChild(img);
            }
            chip.appendChild(document.createTextNode(meta.badge));
            name.appendChild(chip);
        }
        const sub = document.createElement('div');
        sub.className = 'kb-doc-sub';
        sub.textContent = formatBytes(d.file_size);
        // Dernière indexation, dans l'heure du navigateur (le serveur l'envoie en UTC).
        // Pas sur un document sans extrait (« Sans texte ») : « indexé » s'y lirait
        // comme une réussite ; sa fiche garde l'heure.
        const when = d.indexed_at && !docExcluded(d) && Number(d.chunk_count) > 0 && window.MiaKbDoc ? window.MiaKbDoc.formatTime(d.indexed_at) : '';
        if (when) sub.append(document.createTextNode(' · ' + t('indexé le {date}', { date: when })));
        // Le motif s'affiche EN CLAIR pour ce qui ne va pas : un « échec » sans
        // raison ne dit ni quoi corriger ni s'il faut s'en inquiéter.
        if (st.detail && (st.tone === 'error' || st.tone === 'warn' || st.tone === 'ok' || docExcluded(d))) {
            const why = document.createElement('span');
            why.className = 'kb-doc-why';
            why.textContent = st.detail;
            sub.append(document.createTextNode(' · '), why);
        }
        info.append(name, sub);
        row.appendChild(info);

        const state = document.createElement('span');
        state.className = 'kb-doc-state';
        state.dataset.tone = st.tone;
        state.textContent = st.label;
        row.appendChild(state);
        if (viewable) {
            const go = document.createElement('span');
            go.className = 'kb-doc-go';
            go.setAttribute('aria-hidden', 'true');
            go.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
            row.appendChild(go);
        }

        // Un document piloté par la synchronisation ACTIVE ne se supprime pas
        // ici : il reviendrait au prochain passage (retirez-le du dossier
        // source). Un document d'une ANCIENNE source reste supprimable.
        if (C.readOnly || (synced && saved.auto && source === saved.provider)) return row;

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'kb-doc-del';
        del.title = t('Retirer « {nom} » de la base', { nom: d.file_name });
        del.setAttribute('aria-label', del.title);
        del.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
        del.addEventListener('click', async () => {
            if (!confirm(t('Retirer « {nom} » de la base ?', { nom: d.file_name }))) return;
            del.disabled = true;
            try {
                const { r, data } = await post('/admin/knowledge-bases/' + C.id + '/documents/' + d.id + '/delete', {});
                if (!r.ok || !data.ok) {
                    showDocError(data.error || t('Suppression impossible.'));
                    del.disabled = false;
                    return;
                }
                currentDocs = currentDocs.filter((x) => x.id !== d.id);
                renderDocuments(currentDocs);
                showDocError('');
                Status.refresh();
            } catch (e) {
                showDocError(t('Erreur réseau. Réessayez.'));
                del.disabled = false;
            }
        });
        row.appendChild(del);
        return row;
    }

    // Dépôt — modification : chaque fichier part aussitôt, un à la fois ; la
    // lecture puis la vectorisation sont suivies par le panneau d'état.
    async function uploadFiles(files) {
        if (!C.id || !files.length || !drop) return;
        const text = drop.querySelector('.kb-drop-text strong');
        const label = text ? text.textContent : '';
        drop.classList.add('is-busy');
        if (docFileIn) docFileIn.disabled = true;
        const errors = [];
        for (let i = 0; i < files.length; i++) {
            if (text) text.textContent = t('Envoi {i} / {total} — {nom}', { i: i + 1, total: files.length, nom: files[i].name });
            try {
                const { r, data } = await post('/admin/knowledge-bases/' + C.id + '/documents', { document: files[i] });
                if (!r.ok || !data.ok) {
                    errors.push(t('{nom} : {erreur}', { nom: files[i].name, erreur: data.error || t('envoi impossible.') }));
                } else if (data.document) {
                    currentDocs.push(data.document);
                    renderDocuments(currentDocs);
                }
            } catch (e) {
                errors.push(t('{nom} : erreur réseau.', { nom: files[i].name }));
            }
        }
        if (text) text.textContent = label;
        drop.classList.remove('is-busy');
        if (docFileIn) { docFileIn.disabled = false; docFileIn.value = ''; }
        showDocError(errors.join(' '));
        Status.refresh();
    }

    if (docFileIn) {
        docFileIn.addEventListener('change', () => uploadFiles(Array.from(docFileIn.files || [])));
    }

    // Création : les fichiers choisis partent avec « Créer la base » ; leur
    // liste se relit et se corrige avant (un fichier retiré l'est du champ).
    function pendingFiles() {
        return docsCreateIn && docsCreateIn.files ? Array.from(docsCreateIn.files) : [];
    }

    function renderPending() {
        if (!pendingList) return;
        const files = pendingFiles();
        pendingList.innerHTML = '';
        show(pendingList, files.length > 0);
        if (docsCount) docsCount.textContent = files.length ? String(files.length) : '';
        files.forEach((f, i) => {
            const li = document.createElement('li');
            li.className = 'kb-pending-file';
            const name = document.createElement('span');
            name.className = 'kb-pending-name';
            name.textContent = f.name;
            const size = document.createElement('span');
            size.className = 'kb-pending-size';
            size.textContent = formatBytes(f.size);
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'kb-doc-del';
            del.title = t('Retirer « {nom} »', { nom: f.name });
            del.setAttribute('aria-label', del.title);
            del.textContent = '×';
            del.addEventListener('click', () => {
                if (typeof DataTransfer !== 'function') return;
                const dt = new DataTransfer();
                pendingFiles().forEach((x, j) => { if (j !== i) dt.items.add(x); });
                docsCreateIn.files = dt.files;
                renderPending();
            });
            li.append(name, size, del);
            pendingList.appendChild(li);
        });
    }

    if (docsCreateIn) docsCreateIn.addEventListener('change', renderPending);

    // Glisser-déposer : le champ fichier recouvre la zone, le navigateur y
    // dépose lui-même ; la zone ne fait que s'allumer.
    if (drop) {
        let depth = 0;
        drop.addEventListener('dragenter', () => { depth++; drop.classList.add('is-drag'); });
        drop.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) drop.classList.remove('is-drag'); });
        drop.addEventListener('drop', () => { depth = 0; drop.classList.remove('is-drag'); });
    }

    // =====================================================================
    // État de la base (panneau de la question 2, ligne de l'en-tête)
    // =====================================================================
    // Tant que la base travaille (passage en file ou en cours, documents en
    // lecture ou en vectorisation), panneau d'état et liste des documents se
    // rafraîchissent ensemble toutes les 2,5 s ; au repos, plus rien ne part.
    // « Travaille » est tranché par le serveur (`busy`) : une synchronisation
    // encore EN ATTENTE compte.
    const statusPanel = byId('kbStatusPanel');
    const headState   = byId('kbHeadState');
    const Status = (() => {
        let timer = null;
        let seq   = 0;
        let html  = statusPanel ? statusPanel.innerHTML : '';

        function apply(next) {
            if (!statusPanel || next === html) return;
            // La liste des fichiers non repris reste dépliée d'un rafraîchissement à l'autre.
            const open = !!statusPanel.querySelector('details[open]');
            statusPanel.innerHTML = next;
            html = next;
            if (open) statusPanel.querySelector('details')?.setAttribute('open', '');
        }

        async function refresh() {
            // Une table permanente n'a ni passage ni document à suivre :
            // ses lignes se rafraîchissent par ses propres gestes (kb-table.js).
            if (C.table) return;
            clearTimeout(timer);
            if (!C.id) return;
            const mine = ++seq;
            let next = 0;
            try {
                const r = await fetch('/admin/knowledge-bases/' + C.id + '/sync-status', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                const data = await r.json().catch(() => ({}));
                if (mine !== seq || !data || !data.ok) return;
                if (typeof data.panel_html === 'string') apply(data.panel_html);
                if (Array.isArray(data.documents) && JSON.stringify(data.documents) !== docsSignature) {
                    currentDocs = data.documents;
                    renderDocuments(currentDocs);
                    updateFeedUI(); // l'avertissement de changement de source dépend des documents
                }
                if (headState && data.title) {
                    headState.textContent = data.title;
                    headState.dataset.tone = data.tone || '';
                }
                // Un problème NOUVEAU (échec, documents à vérifier…) rouvre le
                // volet des documents, même replié par la personne.
                if (typeof data.attention === 'string') Folds.attention(data.attention, data.busy);
                refreshFoldSummaries();
                // Un scan lu, une version en double déposée : les conseils suivent.
                if (data.advice) applyAdvice(data.advice);
                next = data.busy ? 2500 : 0;
            } catch (e) {
                next = 6000; // réseau capricieux : on retente, plus doucement
            }
            if (next > 0 && mine === seq) timer = setTimeout(refresh, next);
        }

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && C.id) refresh();
        });

        return {
            refresh,
            /** Au chargement : suivi immédiat si la base travaille déjà. */
            start() {
                const panel = statusPanel ? statusPanel.querySelector('.kb-panel') : null;
                if (panel && panel.dataset.busy === '1') timer = setTimeout(refresh, C.pollBusyMs || 2500);
            },
        };
    })();

    // =====================================================================
    // Pour une base efficace : points à améliorer et pastille de l'en-tête
    // =====================================================================
    // Rendus par le serveur (KbAdvice, partiel kb-advice.php) au chargement et
    // à chaque rafraîchissement de l'état : ce script ne fait que les poser.
    // La pastille (« 2 points à améliorer ») ouvre la section et y mène.
    const adviceBox   = byId('conseils');
    const advicePill  = byId('kbAdvicePill');
    const adviceCount = byId('kbAdviceCount');
    const adviceList  = byId('kbAdviceIssues');

    function applyAdvice(a) {
        const label = Number(a.count) > 0 ? String(a.label || '') : '';
        [advicePill, adviceCount].forEach((el) => {
            if (!el) return;
            el.textContent = label;
            el.hidden = label === '';
        });
        if (adviceList && typeof a.html === 'string' && adviceList.innerHTML.trim() !== a.html.trim()) adviceList.innerHTML = a.html;
        refreshFoldSummaries();
    }

    function openAdvice() {
        if (!adviceBox) return;
        openFoldOf(adviceBox);
        adviceBox.open = true;
        const reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        adviceBox.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
    }
    advicePill?.addEventListener('click', (e) => {
        e.preventDefault();
        openAdvice();
    });

    // « Synchroniser maintenant » / « Réessayer » du panneau.
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-kb-sync-now]');
        if (!btn) return;
        e.preventDefault();
        if (btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('is-busy');
        try {
            const { data } = await post('/admin/knowledge-bases/' + Number(btn.dataset.kbSyncNow) + '/sync', {});
            if (!data.ok) {
                if (window.MiaAutosave && typeof window.MiaAutosave.paint === 'function') window.MiaAutosave.paint('error', data.error || t('Lancement impossible.'));
                else alert(data.error || t('Lancement impossible.'));
                btn.disabled = false;
                btn.classList.remove('is-busy');
                return;
            }
        } catch (err) {
            alert(t('Erreur réseau. Réessayez.'));
            btn.disabled = false;
            btn.classList.remove('is-busy');
            return;
        }
        // « En attente » arrive aussitôt ; le bouton disparaît avec le panneau
        // qui le portait, jusqu'à la fin du passage.
        Status.refresh();
    });

    // Un enregistrement peut lancer un passage (source ou dossier changé,
    // synchronisation réactivée) : l'état suit aussitôt, et la source
    // enregistrée devient la référence (retour sur elle, avertissement).
    form.addEventListener('mia:autosaved', (e) => {
        const data = (e.detail && e.detail.data) || {};
        if (!data.warning && syncConfig) {
            const p = selectedProvider();
            saved.provider = PROVIDER_META[p] && syncUrl && syncUrl.value.trim() !== '' ? p : '';
            saved.url      = saved.provider ? syncUrl.value.trim() : '';
            saved.auto     = !!(syncEnabled && syncEnabled.checked);
            saved.auth     = selectedAuth();
            if (canOwnerAuth(saved.provider) && saved.auth === 'owner') saved.owner = C.userName;
            if (pickedFor === saved.provider && pickedName) saved.folder = pickedName;
            updateFeedUI();
        }
        Status.refresh();
    });

    // =====================================================================
    // Qui s'en sert : qui la voit, partage et assistants liés en pastilles
    // =====================================================================
    // Les listes à cocher restent la vérité — ce sont elles qui partent dans
    // le POST ; les pastilles en sont le résumé, redessiné à chaque geste, et
    // « Ajouter » / « Lier un assistant » déplient les listes.
    const assistantList   = byId('kbAssistantList');
    const assistantSearch = byId('kbAssistantSearch');
    const assistantEmpty  = byId('kbAssistantEmpty');
    const assistantChips  = byId('kbAssistantChips');
    const linkedCount     = byId('kbLinkedCount');
    const shareUserSearch = byId('kbShareUserSearch');
    const shareUserList   = byId('kbShareUserList');
    const shareUserEmpty  = byId('kbShareUserEmpty');
    const shareChips      = byId('kbShareChips');
    const sharePanelEl    = byId('kbSharePanel');
    const shareBody       = byId('kbShareBody');
    const privateNote     = byId('kbPrivateAssistantsHint');
    const accessBox       = byId('kbAccess');

    const SVG_X     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    const SVG_GROUP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
    const SVG_USER  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    const reducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function filterRows(list, query, empty) {
        if (!list) return;
        const q = (query || '').trim().toLowerCase();
        let visible = 0;
        list.querySelectorAll('.ug-check-item').forEach((row) => {
            const hit = !q || (row.dataset.search || '').includes(q);
            row.hidden = !hit;
            if (hit) visible++;
        });
        show(empty, visible === 0);
    }

    function updateLinkedCount() {
        if (!linkedCount || !assistantList) return;
        const n = assistantList.querySelectorAll('input[type="checkbox"]:checked').length;
        linkedCount.textContent = n === 0 ? '' : tn(n, '{n} lié', '{n} liés');
    }

    // ── Listes dépliées sous « Ajouter » / « Lier un assistant » ──
    function foldable(btn, panel) {
        function set(on) {
            if (!btn || !panel) return;
            panel.hidden = !on;
            btn.setAttribute('aria-expanded', on ? 'true' : 'false');
            btn.classList.toggle('is-open', on);
        }
        btn?.addEventListener('click', () => {
            const on = !!panel && panel.hidden;
            set(on);
            // Au clavier ou à la souris, la recherche prend le focus ; au doigt,
            // le clavier virtuel surgirait avant qu'on ait regardé la liste.
            if (on && window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                panel.querySelector('input.ug-search')?.focus();
            }
        });
        return { open: () => set(true), close: () => set(false) };
    }
    const sharePanel     = foldable(byId('kbShareAddBtn'), sharePanelEl);
    const assistantPanel = foldable(byId('kbAssistantAddBtn'), byId('kbAssistantPicker'));

    // Une pastille ouvre la liste sur sa ligne (ses cases « peut modifier »…).
    function revealRow(panel, row) {
        panel.open();
        const list = row.closest('.ug-check-list');
        const search = list ? list.parentElement.querySelector('input.ug-search') : null;
        if (search && search.value && row.hidden) {
            search.value = '';
            search.dispatchEvent(new Event('input', { bubbles: true }));
        }
        row.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
        row.classList.remove('is-spotlit');
        void row.offsetWidth;
        row.classList.add('is-spotlit');
        setTimeout(() => row.classList.remove('is-spotlit'), 1800);
    }

    function rowName(row) {
        const n = row.querySelector('.ug-member-name');
        return n ? n.textContent.trim() : '';
    }

    function chipFor(o) {
        const el = document.createElement('span');
        el.className = 'kbf-chip';
        el.setAttribute('role', 'listitem');
        const body = document.createElement('button');
        body.type = 'button';
        body.className = 'kbf-chip-body';
        body.title = t('Voir dans la liste');
        if (o.lead) body.appendChild(o.lead);
        const name = document.createElement('span');
        name.className = 'kbf-chip-name';
        name.textContent = o.label;
        body.appendChild(name);
        if (o.sub) {
            const sub = document.createElement('span');
            sub.className = 'kbf-chip-sub';
            sub.textContent = o.sub;
            body.appendChild(sub);
        }
        body.addEventListener('click', o.onOpen);
        el.appendChild(body);
        if (!C.readOnly && o.onRemove) {
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'kbf-chip-x';
            x.title = t('Retirer « {nom} »', { nom: o.label });
            x.setAttribute('aria-label', x.title);
            x.innerHTML = SVG_X;
            x.addEventListener('click', o.onRemove);
            el.appendChild(x);
        }
        return el;
    }

    function chipsEmpty(box, text) {
        const empty = document.createElement('span');
        empty.className = 'kbf-chips-empty';
        empty.textContent = text;
        box.appendChild(empty);
    }

    // Groupes et personnes cochés, avec leurs droits.
    function renderShareChips() {
        if (!shareChips) return;
        shareChips.innerHTML = '';
        const live = isLive();
        const rows = Array.from(form.querySelectorAll('.kb-share-item')).filter((row) => {
            const target = row.querySelector('input[data-share-target]');
            return !!target && target.checked;
        });
        rows.forEach((row) => {
            const target = row.querySelector('input[data-share-target]');
            const on = (sel) => { const box = row.querySelector(sel); return !!(box && box.checked); };
            const rights = [];
            if (!live && on('.kb-share-manage input')) rights.push(t('peut modifier'));
            if (live && on('.kb-share-default input')) rights.push(t('cochée par défaut'));
            if (live && on('.kb-share-feed input')) rights.push(t('peut nourrir'));
            const lead = document.createElement('span');
            lead.className = 'kbf-chip-ic';
            lead.innerHTML = target.dataset.shareTarget === 'group' ? SVG_GROUP : SVG_USER;
            shareChips.appendChild(chipFor({
                label: rowName(row),
                sub: rights.join(' · '),
                lead,
                onOpen: () => revealRow(sharePanel, row),
                // Le geste repasse par la case : sa ligne, les pastilles et
                // l'enregistrement automatique suivent comme pour un clic.
                onRemove: () => {
                    target.checked = false;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                },
            }));
        });
        if (rows.length === 0) chipsEmpty(shareChips, t('Aucun groupe ni personne pour l’instant.'));
    }

    // Assistants cochés, avec leur avatar.
    function renderAssistantChips() {
        if (!assistantChips || !assistantList) return;
        assistantChips.innerHTML = '';
        const rows = Array.from(assistantList.querySelectorAll('.ug-check-item')).filter((row) => {
            const box = row.querySelector('input[type="checkbox"]');
            return !!box && box.checked;
        });
        rows.forEach((row) => {
            const box = row.querySelector('input[type="checkbox"]');
            const ava = row.querySelector('.kb-pick-ava');
            const lead = ava ? ava.cloneNode(true) : null;
            const inactive = row.querySelector('.badge-red');
            assistantChips.appendChild(chipFor({
                label: rowName(row),
                sub: inactive ? inactive.textContent.trim() : '',
                lead,
                onOpen: () => revealRow(assistantPanel, row),
                onRemove: () => {
                    box.checked = false;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                },
            }));
        });
        if (rows.length === 0) chipsEmpty(assistantChips, t('Aucun assistant lié pour l’instant.'));
    }

    assistantSearch?.addEventListener('input', () => filterRows(assistantList, assistantSearch.value, assistantEmpty));
    shareUserSearch?.addEventListener('input', () => filterRows(shareUserList, shareUserSearch.value, shareUserEmpty));
    assistantList?.addEventListener('change', () => { updateLinkedCount(); renderAssistantChips(); });

    // Tout / Aucun : n'agit que sur les lignes visibles (respecte la
    // recherche), et dit au formulaire ce qui a changé — une affectation par
    // script n'émet rien, l'enregistrement automatique ne la verrait pas.
    document.querySelectorAll('[data-check-all], [data-check-none]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const allId = btn.getAttribute('data-check-all');
            const list  = byId(allId || btn.getAttribute('data-check-none'));
            if (!list) return;
            let changed = null;
            list.querySelectorAll('.ug-check-item').forEach((row) => {
                if (row.hidden) return;
                const cb = row.querySelector('input[type="checkbox"]');
                if (cb && !cb.disabled && cb.checked !== !!allId) {
                    cb.checked = !!allId;
                    changed = cb;
                }
            });
            if (changed) changed.dispatchEvent(new Event('change', { bubbles: true }));
            updateLinkedCount();
            renderAssistantChips();
        });
    });

    // Les cases « peut modifier » (base de documents) et « cochée par défaut »
    // / « peut nourrir » (base en direct) n'ont de sens que si la ligne est
    // partagée — et seule celle de la nature courante part dans le POST : la
    // case de l'autre nature reste désactivée, donc absente.
    function syncManageBox(row) {
        const target = row.querySelector('input[data-share-target]');
        if (!target) return;
        const live   = isLive();
        const manage = row.querySelector('.kb-share-manage input');
        const def    = row.querySelector('.kb-share-default input');
        const feed   = row.querySelector('.kb-share-feed input');
        const locked = C.readOnly;
        if (manage) {
            manage.disabled = locked || !target.checked || live;
            if (!target.checked) manage.checked = false;
        }
        [def, feed].forEach((box) => {
            if (!box) return;
            box.disabled = locked || !target.checked || !live;
            if (!target.checked) box.checked = false;
        });
        row.classList.toggle('is-shared-on', target.checked);
    }
    form.querySelectorAll('.kb-share-item').forEach((row) => {
        row.querySelector('input[data-share-target]')?.addEventListener('change', () => syncManageBox(row));
    });
    // Tout geste dans la liste (ligne, droits) redessine les pastilles.
    sharePanelEl?.addEventListener('change', renderShareChips);

    // Interrupteur « Active » de l'en-tête.
    const activeBox  = byId('kbIsActive');
    const activeText = byId('kbActiveText');
    activeBox?.addEventListener('change', () => {
        if (!activeText) return;
        activeText.textContent = activeBox.checked ? t('Active') : t('Inactive');
        activeText.classList.toggle('is-off', !activeBox.checked);
    });

    // ── Qui la voit : privée, commune ou publique ──
    // Trois boutons radio `visibility` (envoyés avec la fiche, `visibility_form`
    // dit au serveur qu'ils étaient affichés). Le partage aux groupes et aux
    // personnes ne vaut que pour une base COMMUNE : passer en privée ou en
    // publique retire les partages accordés — on le confirme, ils ne
    // reviendront pas d'eux-mêmes.
    const sharedBoxes = () => Array.from(form.querySelectorAll('input[data-share-target]:checked'));
    const checkedAccess = () => {
        const radio = accessBox ? accessBox.querySelector('input[name="visibility"]:checked') : null;
        return radio ? radio.value : (C.private ? 'private' : 'common');
    };
    let access = checkedAccess();

    function checkAccess(value) {
        const radio = accessBox ? accessBox.querySelector('input[name="visibility"][value="' + value + '"]') : null;
        if (radio) radio.checked = true;
    }

    function syncPrivate() {
        const common = access === 'common';
        if (shareBody) shareBody.hidden = !common;
        if (privateNote) privateNote.hidden = access !== 'private';
        form.querySelectorAll('.kb-share-item').forEach((row) => {
            const target = row.querySelector('input[data-share-target]');
            if (target) target.disabled = C.readOnly || !common;
            if (!common) row.querySelectorAll('input').forEach((i) => { i.disabled = true; });
            else syncManageBox(row);
        });
        accessBox?.querySelectorAll('[data-vis-note]').forEach((note) => {
            note.hidden = note.dataset.visNote !== access;
        });
        renderShareChips();
    }

    accessBox?.addEventListener('change', (e) => {
        const radio = e.target.closest('input[name="visibility"]');
        if (!radio) return;
        const next   = radio.value;
        const shared = sharedBoxes();
        if (next === 'private' && shared.length > 0
            && !confirm(t('Réserver cette base à vous seul ? Les {n} partages accordés seront retirés, et ni les personnes concernées ni les administrateurs ne la verront plus.', { n: shared.length }))) {
            e.stopImmediatePropagation();
            checkAccess(access);
            return;
        }
        if (next === 'public' && shared.length > 0
            && !confirm(tn(shared.length, 'Rendre la base publique ? Toute l’organisation la verra ; le partage accordé devient inutile et sera retiré.', 'Rendre la base publique ? Toute l’organisation la verra ; les {n} partages accordés deviennent inutiles et seront retirés.'))) {
            e.stopImmediatePropagation();
            checkAccess(access);
            return;
        }
        access = next;
        if (next !== 'common') {
            shared.forEach((box) => {
                box.checked = false;
                const row = box.closest('.kb-share-item');
                if (row) syncManageBox(row);
            });
        }
        syncPrivate();
        if (next === 'common' && sharedBoxes().length === 0 && !C.create) sharePanel.open();
    }, true);
    syncPrivate();

    // ── Menu « … » de l'en-tête : réglages avancés, suppression ──
    const moreBtn  = byId('kbMoreBtn');
    const moreMenu = byId('kbMoreMenu');
    const moreItems = () => (moreMenu ? Array.from(moreMenu.querySelectorAll('[role="menuitem"]')) : []);
    function setMore(on, focusFirst) {
        if (!moreBtn || !moreMenu) return;
        moreMenu.hidden = !on;
        moreBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on && focusFirst) moreItems()[0]?.focus();
    }
    moreBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        // detail === 0 : ouvert au clavier, le focus entre dans le menu.
        setMore(moreMenu.hidden, e.detail === 0);
    });
    document.addEventListener('click', (e) => {
        if (moreMenu && !moreMenu.hidden && !e.target.closest('.kbf-more')) setMore(false);
    });
    moreMenu?.addEventListener('keydown', (e) => {
        const items = moreItems();
        const at = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const step = e.key === 'ArrowDown' ? 1 : -1;
            items[(at + step + items.length) % items.length]?.focus();
        } else if (e.key === 'Escape') {
            setMore(false);
            moreBtn.focus();
        } else if (e.key === 'Tab') {
            setMore(false);
        }
    });
    moreMenu?.querySelector('[data-kb-open-advanced]')?.addEventListener('click', (e) => {
        e.preventDefault();
        setMore(false);
        if (!advanced) return;
        advanced.open = true;
        advanced.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
        advanced.querySelector('summary')?.focus({ preventScroll: true });
    });

    // Supprimer / Débrancher : confirmation, puis le formulaire dédié.
    const deleteBtn = byId('kbDeleteBtn');
    deleteBtn?.addEventListener('click', () => {
        setMore(false);
        if (!confirm(deleteBtn.dataset.confirm || '')) return;
        // Plus rien à enregistrer d'une base qui part.
        if (window.MiaAutosave && typeof window.MiaAutosave.detach === 'function') window.MiaAutosave.detach(form);
        byId('kbDeleteForm')?.submit();
    });

    // =====================================================================
    // Avancé : réindexation et extraits vectorisés
    // =====================================================================
    const reForm = byId('kbReindexForm');
    const reSel  = byId('kbReindexChoice');
    const reHint = byId('kbReindexHint');
    const reBase = reHint ? reHint.textContent.trim() : '';
    const optionLabel = (val) => {
        const opt = reSel ? Array.from(reSel.options).find((o) => o.value === val) : null;
        return opt ? opt.textContent.trim() : val;
    };

    // Le choix dit ce qu'il fera : même modèle (vecteurs recalculés), autre
    // modèle (tout re-vectorisé), modèle actuel devenu inutilisable.
    function reindexPlan() {
        if (!reSel) return reBase;
        const current = reSel.dataset.current || '';
        const currentLabel = reSel.dataset.currentLabel || current;
        const usable = Array.from(reSel.options).some((o) => o.value === current);
        if (reSel.value === current) return t('Même modèle : les vecteurs sont recalculés sans changer de moteur.') + ' ' + reBase;
        if (!usable) {
            return t('Le modèle actuel ({actuel}) n’est plus utilisable : la clé de son fournisseur n’est pas configurée. La base passera sur « {nouveau} ».',
                { actuel: currentLabel, nouveau: optionLabel(reSel.value) }) + ' ' + reBase;
        }
        return t('« {nouveau} » remplacera « {actuel} » : tous les documents sont re-vectorisés avec le nouveau modèle.',
            { nouveau: optionLabel(reSel.value), actuel: currentLabel }) + ' ' + reBase;
    }
    if (reSel && reHint) {
        reSel.addEventListener('change', () => { reHint.textContent = reindexPlan(); });
        reHint.textContent = reindexPlan();
    }
    reForm?.addEventListener('submit', (e) => {
        const what = reSel && reSel.value !== (reSel.dataset.current || '')
            ? t('Réindexer la base avec « {modele} » ? Tous ses documents seront re-vectorisés.', { modele: optionLabel(reSel.value) })
            : t('Réindexer la base ? Tous ses documents seront re-vectorisés avec le même modèle.');
        if (!confirm(what)) e.preventDefault();
    });

    // ── Extraits vectorisés : un SEUL chargeur (kb-doc.js, MiaKbDoc) ──
    // Mêmes extraits (…/chunks), même rendu, même recherche, même pagination
    // pour « Avancé » et pour la fiche d'un document (volet) : la page de la
    // base et sa fiche partagent le module.
    const chunkViewer = (o) => (window.MiaKbDoc
        ? window.MiaKbDoc.chunkViewer(Object.assign({ kbId: C.id }, o))
        : { load() {}, restart() {}, clear() {}, loaded: false });

    // « Avancé » : tous les documents, ou celui choisi ; chargé à l'ouverture.
    const chunksBlock = byId('kbChunksBlock');
    const chunkList   = byId('kbChunkList');
    const chunkDoc    = byId('kbChunkDoc');
    if (chunksBlock && chunkList) {
        const advancedChunks = chunkViewer({
            list: chunkList,
            search: byId('kbChunkSearch'),
            pager: byId('kbChunkPager'),
            prev: byId('kbChunkPrev'),
            next: byId('kbChunkNext'),
            pageLabel: byId('kbChunkPage'),
            docId: () => (chunkDoc ? chunkDoc.value : ''),
            toTop: () => chunksBlock.scrollIntoView({ block: 'start' }),
            showDoc: true,
        });
        chunkDoc?.addEventListener('change', () => advancedChunks.restart(false));
        advanced?.addEventListener('toggle', () => {
            if (advanced.open && !advancedChunks.loaded) advancedChunks.load();
        });
    }

    // =====================================================================
    // Fiche d'un document (volet, kb-doc.js) — clic sur un document
    // =====================================================================
    // Le même volet que la page de la base : étapes du traitement, ce qui ne
    // va pas et pourquoi, nouvel essai ou exclusion, puis ses extraits.
    // L'adresse porte #document-ID (#extraits-ID, ancien lien, y mène aussi) ;
    // un geste fait relire l'état de la base et la liste des documents.
    const Drawer = (window.MiaKbDoc && drawerEl && C.id)
        ? window.MiaKbDoc.drawer({
            kbId: C.id,
            ids: () => docsView.map((d) => Number(d.id)),
            reveal: (id) => revealDoc(id),
            onChange: (data) => {
                if (data && data.deleted) {
                    currentDocs = currentDocs.filter((x) => x.id !== data.deleted);
                    renderDocuments(currentDocs);
                }
                Status.refresh();
            },
        })
        : { open() {}, close() {}, refresh() {}, openFromHash() {}, isOpen: () => false, docId: 0 };

    docsList?.addEventListener('click', (e) => {
        const link = e.target.closest('.kb-doc-open');
        if (!link) return;
        e.preventDefault();
        Drawer.open(Number(link.dataset.docId), link);
    });

    // La liste redessinée (rafraîchissement, document retiré) : un document
    // disparu ferme le volet.
    onDocsRendered = () => {
        if (Drawer.isOpen() && !currentDocs.some((x) => x.id === Drawer.docId)) Drawer.close();
    };

    // =====================================================================
    // Volets : chaque question se replie sur une ligne qui la résume
    // =====================================================================
    // Ouverts d'office par le serveur : tout à la création ; en modification,
    // seule « D'où viennent les documents », quand la base demande qu'on s'en
    // occupe (KbStatus::attention). Ce que la personne ouvre ou ferme est
    // retenu pour CETTE base sur cet appareil (clé par compte : poste
    // partagé) ; un volet jamais touché suit le serveur. Un problème NOUVEAU
    // — sa signature change — rouvre les documents malgré un repli retenu.
    const foldEls    = Array.from(document.querySelectorAll('details.kbf-fold'));
    const foldAllBtn = byId('kbFoldAll');
    const FOLD_KEY   = 'kbFold:' + (Number(C.userId) || 0) + ':' + (Number(C.id) || 0);
    const foldSetup  = C.fold || { open: {}, attention: '', busy: false };

    function readFoldMemory() {
        try {
            const v = JSON.parse(localStorage.getItem(FOLD_KEY) || 'null');
            if (v && typeof v === 'object' && v.open && typeof v.open === 'object') return { open: v.open, attn: String(v.attn || '') };
        } catch (_e) { /* stockage indisponible ou abîmé : les volets suivent le serveur */ }
        return null;
    }
    function writeFoldMemory() {
        if (!foldMem) return;
        try { localStorage.setItem(FOLD_KEY, JSON.stringify(foldMem)); } catch (_e) { /* navigation privée, quota : rien */ }
    }
    // Rien à retenir à la création : la base n'existe pas encore.
    const foldMem = C.create || !C.id ? null : (readFoldMemory() || { open: {}, attn: '' });
    const sourcesFold = foldEls.find((d) => d.dataset.fold === 'sources') || null;

    // Signature de ce qui demande l'attention. Pendant un passage (busy), le
    // serveur ne dit rien : la retenir ferait prendre le problème d'avant,
    // revenu à la fin du passage, pour un nouveau.
    function noteAttention(sig, busy) {
        if (!foldMem || busy) return;
        if (sig !== '' && sig !== foldMem.attn) {
            delete foldMem.open.sources;
            if (sourcesFold) sourcesFold.open = true;
        }
        foldMem.attn = sig;
        writeFoldMemory();
    }

    function applyFoldMemory() {
        if (!foldMem) return;
        noteAttention(String(foldSetup.attention || ''), !!foldSetup.busy);
        foldEls.forEach((d) => {
            const key = d.dataset.fold;
            if (Object.prototype.hasOwnProperty.call(foldMem.open, key)) d.open = !!foldMem.open[key];
        });
    }

    const Folds = {
        attention: (sig, busy) => noteAttention(String(sig || ''), !!busy),
    };

    /** Ouvre les volets qui contiennent `el` (lien profond, document, champ invalide). */
    function openFoldOf(el) {
        let d = el ? el.closest('details') : null;
        while (d) {
            if (!d.open && (d.classList.contains('kbf-fold') || d.classList.contains('kbf-subfold'))) d.open = true;
            d = d.parentElement ? d.parentElement.closest('details') : null;
        }
    }

    function syncFoldAll() {
        if (!foldAllBtn) return;
        const allOpen = foldEls.length > 0 && foldEls.every((d) => d.open);
        foldAllBtn.setAttribute('aria-expanded', allOpen ? 'true' : 'false');
        foldAllBtn.classList.toggle('is-open', allOpen);
        const txt = foldAllBtn.querySelector('.kbf-fold-all-text');
        if (txt) txt.textContent = allOpen ? t('Tout replier') : t('Tout déplier');
    }

    foldEls.forEach((d) => {
        // Le clic précède la bascule : l'état retenu est celui d'après. Le
        // clavier (Entrée, Espace) passe aussi par ce clic.
        d.querySelector(':scope > summary')?.addEventListener('click', () => {
            if (!foldMem) return;
            foldMem.open[d.dataset.fold] = !d.open;
            writeFoldMemory();
        });
        d.addEventListener('toggle', syncFoldAll);
    });
    foldAllBtn?.addEventListener('click', () => {
        const open = !foldEls.every((d) => d.open);
        foldEls.forEach((d) => {
            d.open = open;
            if (foldMem) foldMem.open[d.dataset.fold] = open;
        });
        writeFoldMemory();
        syncFoldAll();
    });

    // Un champ invalide dans un volet replié ne pourrait pas prendre le focus.
    form.addEventListener('invalid', (e) => openFoldOf(e.target), true);

    // Lien profond vers une partie de la fiche (#kbSectionUse, #kbSyncFold…) :
    // ses volets s'ouvrent. #conseils, #avance et #extraits-ID ont leur geste.
    function openForHash() {
        const id = location.hash.slice(1);
        if (!id || id === 'conseils' || id === 'avance' || /^(?:extraits|document)-\d+$/.test(id)) return;
        let el = null;
        try { el = document.getElementById(decodeURIComponent(id)); } catch (_e) { return; }
        if (!el || !el.closest('details.kbf-fold')) return;
        openFoldOf(el);
        if (el.matches('details')) el.open = true;
        el.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
    }
    window.addEventListener('hashchange', openForHash);

    // ── Résumés : une ligne par volet replié, recalculée à chaque geste ──
    function writeSummary(box, items) {
        if (!box) return;
        const list = items.filter((x) => x && x.text);
        box.innerHTML = '';
        list.forEach((x) => {
            const span = document.createElement('span');
            span.className = 'kbf-sum-item' + (x.strong ? ' is-strong' : '');
            if (x.tone) span.dataset.tone = x.tone;
            span.textContent = x.text;
            box.appendChild(span);
        });
        // Coupé par la largeur : le texte entier reste au survol.
        box.title = list.map((x) => x.text).join(' · ');
    }

    function identitySummary() {
        const name = (byId('kbName')?.value || '').trim();
        const desc = (byId('kbDescription')?.value || '').trim();
        return [{ text: name || t('Sans nom'), strong: true, tone: name ? '' : 'warn' }, { text: desc }];
    }

    function sourcesSummary() {
        // Table permanente : ses lignes, ses colonnes, sa dernière
        // modification — la ligne que le serveur a rendue (kb-table.js la
        // tient à jour après chaque geste).
        if (C.table) {
            const zone = byId('kbTableSection');
            const text = zone ? (zone.dataset.summary || '') : '';
            return text ? [{ text, strong: true }] : [];
        }
        const items = [];
        const pill = tilesBox ? tilesBox.querySelector('input[name="sync_provider"]:checked') : null;
        const pillName = pill ? pill.closest('.kbf-pill')?.querySelector('.kbf-pill-name') : null;
        if (pillName) items.push({ text: pillName.textContent.trim(), strong: true });
        else if (!tilesBox) items.push({ text: t('Documentation de MIA'), strong: true });
        if (headState && headState.textContent.trim() !== '') {
            items.push({ text: headState.textContent.trim(), tone: headState.dataset.tone || '' });
        }
        if (!isLive()) {
            // Base vide : son état (« Aucun document ») le dit déjà.
            items.push({ text: C.create || currentDocs.length === 0 ? '' : tn(currentDocs.length, '{n} document', '{n} documents') });
            if (adviceCount && !adviceCount.hidden && adviceCount.textContent.trim() !== '') {
                items.push({ text: adviceCount.textContent.trim(), tone: 'advice' });
            }
        }
        return items;
    }

    function useSummary() {
        const items = [];
        const radio = accessBox ? accessBox.querySelector('input[name="visibility"]:checked') : null;
        if (radio) {
            if (radio.value === 'common' && sharedBoxes().length > 0) {
                const shared = sharedBoxes();
                const groups = shared.filter((b) => b.dataset.shareTarget === 'group').length;
                const people = shared.length - groups;
                const parts = [];
                if (groups) parts.push(tn(groups, '{n} groupe', '{n} groupes'));
                if (people) parts.push(tn(people, '{n} personne', '{n} personnes'));
                items.push(parts.length
                    ? { text: t('Partagée avec {liste}', { liste: parts.join(', ') }), strong: true }
                    : { text: t('Partagée — avec personne pour l’instant'), strong: true, tone: 'warn' });
            } else {
                const name = radio.closest('.kbx-acc')?.querySelector('b');
                if (name) items.push({ text: name.textContent.trim(), strong: true });
            }
        } else if (C.system) {
            items.push({ text: t('Sert tout le monde'), strong: true });
        } else if (!C.owned) {
            items.push({ text: t('Partagée avec vous'), strong: true });
        }
        if (!C.system) {
            const n = assistantList ? assistantList.querySelectorAll('input[type="checkbox"]:checked').length : 0;
            items.push({ text: n ? tn(n, '{n} assistant lié', '{n} assistants liés') : t('Aucun assistant lié') });
        }
        return items;
    }

    // Un lien de dossier sans nom connu : son site et son dernier segment
    // (« drive.google.com › 1AbC… », « contoso.sharepoint.com › Contrats »).
    function shortFolderRef(url) {
        try {
            const u = new URL(url);
            const segs = u.pathname.split('/').filter(Boolean);
            const last = segs.length ? decodeURIComponent(segs[segs.length - 1]) : '';
            return last ? u.host + ' › ' + last : u.host;
        } catch (_e) {
            return url; // une adresse d'agenda, un identifiant collé
        }
    }

    // Réglages du dossier (sous-volet de la question 2) : dossier, rythme, accès.
    function syncFoldSummary() {
        const p = selectedProvider();
        if (!PROVIDER_META[p]) return [];
        const url = syncUrl ? syncUrl.value.trim() : '';
        let folder = '';
        if (pickedName && pickedFor === p) folder = pickedName;
        else if (p === saved.provider && url === saved.url && saved.folder) folder = saved.folder;
        else if (url !== '') folder = shortFolderRef(url);
        const items = [folder
            ? { text: folder, strong: true }
            : { text: t('Aucun dossier choisi'), strong: true, tone: 'warn' }];
        items.push({ text: syncEnabled && syncEnabled.checked
            ? t('automatique, toutes les {n} min', { n: Number(C.syncInterval) || 10 })
            : t('synchronisation manuelle') });
        if (canOwnerAuth(p)) {
            if (selectedAuth() === 'owner') {
                const other = !C.create && saved.auth === 'owner' && saved.owner !== '' && saved.owner !== C.userName && p === saved.provider;
                items.push({ text: other ? t('avec la connexion de {nom}', { nom: saved.owner }) : t('avec votre connexion') });
            } else {
                items.push({ text: t('par la clé API globale') });
            }
        }
        return items;
    }

    function refreshFoldSummaries() {
        if (!foldsReady) return;
        foldEls.forEach((d) => {
            const box = d.querySelector(':scope > summary [data-fold-summary]');
            const key = d.dataset.fold;
            writeSummary(box, key === 'identity' ? identitySummary()
                : key === 'sources' ? sourcesSummary()
                : key === 'use' ? useSummary() : []);
        });
        writeSummary(byId('kbSyncFoldSum'), syncFoldSummary());
    }
    // Tout geste dans le formulaire (nom, source, partage, assistant…).
    form.addEventListener('input', refreshFoldSummaries);
    form.addEventListener('change', refreshFoldSummaries);

    // =====================================================================
    // Démarrage
    // =====================================================================
    if (selectedProvider() === 'drive') setAuth(saved.auth);
    updateFeedUI();
    updateLinkedCount();
    renderDocuments(currentDocs);
    renderPending();
    Status.start();
    renderAssistantChips();
    applyFoldMemory();
    foldsReady = true;
    refreshFoldSummaries();
    // Table permanente : ses lignes changent par kb-table.js, qui le signale —
    // le résumé de son volet suit.
    document.addEventListener('mia:kb-table-changed', refreshFoldSummaries);
    syncFoldAll();
    openForHash();
    // Retour d'une réindexation (#avance) : la section revient ouverte.
    if (location.hash === '#avance' && advanced) {
        advanced.open = true;
        advanced.scrollIntoView({ block: 'start' });
    }
    // Arrivée depuis la pastille « À améliorer » de la liste (#conseils).
    if (location.hash === '#conseils') openAdvice();
    // Lien vers un document (#document-ID, ou #extraits-ID) : sa fiche s'ouvre.
    Drawer.openFromHash();
})();
