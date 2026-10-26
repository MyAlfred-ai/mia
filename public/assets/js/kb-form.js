/**
 * Fiche d'une base de connaissances — Espaces → Bases de connaissances
 * → « + Nouvelle base » / « Modifier » (templates/admin/knowledge-bases/edit.php).
 *
 * La page porte ses valeurs, rendues par le serveur ; ce script ne fait que
 * ce qu'un rendu ne peut pas faire :
 *  - les SOURCES : la tuile choisie déplie sa configuration (dossier, accès,
 *    synchronisation, connexion d'un cerveau), « Tester », l'explorateur de
 *    dossiers déplié sous le champ, le dépôt de documents (glisser-déposer,
 *    plusieurs à la fois) et leur liste ;
 *  - l'ÉTAT de la base (panneau en tête), rafraîchi tant qu'elle travaille ;
 *  - les listes à cocher (assistants, partage) : recherche, Tout / Aucun,
 *    cases « peut modifier », « cochée par défaut », « peut nourrir » liées à
 *    leur ligne ;
 *  - le SCHÉMA de la base, à droite, redessiné à chaque geste (diagram.js +
 *    kb-diagram.js) — chaque élément mène à son réglage ;
 *  - la section « Avancé » : l'explication du choix de réindexation et le
 *    détail des extraits vectorisés, chargé à l'ouverture.
 *
 * Textes affichés : t() / tn() (dictionnaire CLIENT, lang/en.client.php) ;
 * libellés du schéma et des sections : rendus traduits par le serveur.
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
            urlLabel: t('Dossier SharePoint'),
            placeholder: t('https://votretenant.sharepoint.com/sites/…'),
            hint: t('Le dossier et ses sous-dossiers sont tenus en miroir. Collez l\'adresse du dossier (« Copier le lien » de SharePoint, ou la barre d\'adresse) : l\'accès passe par l\'application Azure de votre organisation, aucun partage public n\'est requis.'),
        },
        gcalendar: {
            label: t('Google Agenda'),
            badge: t('Agenda'),
            icon: '/assets/img/google-calendar.svg',
            urlLabel: t('Agenda Google'),
            placeholder: t('adresse@gmail.com, xxx@group.calendar.google.com ou URL publique'),
            hint: t('L\'agenda est tenu en miroir, un document par mois (3 mois en arrière, 12 en avant). Il doit être public — Paramètres et partage de l\'agenda → « Rendre disponible publiquement ». Collez son identifiant (Paramètres → « Intégrer l\'agenda ») ou son URL publique.'),
        },
    };

    const tilesBox       = byId('kbSourcesField');
    const syncConfig     = byId('kbSyncConfig');
    const syncHint       = byId('kbSyncHint');
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
            if (syncHint)     syncHint.textContent     = (ownerMode && meta.hintOwner) ? meta.hintOwner : meta.hint;
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
            tilesBox.querySelectorAll('.kb-source-tile').forEach((tile) => {
                const input = tile.querySelector('input[name="sync_provider"]');
                tile.classList.toggle('is-selected', !!input && input.checked);
            });
        }
        updateBackendUI();
    }

    // ── Base en direct (tuile « Cerveau MyBrain ») ──
    // Pas une source de documents : un cerveau interrogé à chaque message. La
    // tuile retire de la fiche ce qui n'a plus de sens (documents, modèle
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
                    if (C.diagram && C.diagram.base && C.diagram.base.remote) C.diagram.base.remote.app = data.application || '';
                    Status.refresh();
                    Diagram.render();
                }
            } catch (e) {
                setMyBrainResult('✗ ' + t('Erreur réseau. Réessayez.'), 'error');
            } finally {
                myBrainTestBtn.disabled = false;
            }
        });
    }

    // Changement de tuile : adresse et synchronisation automatique suivent la
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
                    Diagram.render();
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
    const docsToolbar = byId('kbDocsToolbar');
    const docsCount   = byId('kbDocsCount');
    const docErrorEl  = byId('kbDocError');
    const docFileIn   = byId('kbDocFileInput');
    const drop        = byId('kbDrop');
    const pendingList = byId('kbPendingFiles');
    let docsOnlyProblems = false;
    let docsSignature    = '';

    function showDocError(msg) {
        if (!docErrorEl) return;
        docErrorEl.textContent = msg || '';
        show(docErrorEl, !!msg);
    }

    function docIsProblem(d) {
        const tone = d.state && d.state.tone;
        return tone === 'error' || tone === 'warn';
    }

    function renderDocsToolbar(total, problems) {
        if (!docsToolbar) return;
        docsToolbar.innerHTML = '';
        if (problems === 0) {
            docsOnlyProblems = false;
            show(docsToolbar, false);
            return;
        }
        show(docsToolbar, true);
        const label = document.createElement('span');
        label.className = 'kb-docs-toolbar-label';
        label.textContent = tn(problems, '{n} document à vérifier sur {total}', '{n} documents à vérifier sur {total}', { total });
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kb-docs-filter';
        btn.setAttribute('aria-pressed', docsOnlyProblems ? 'true' : 'false');
        btn.textContent = docsOnlyProblems ? t('Tout afficher') : t('Voir seulement ceux-là');
        btn.addEventListener('click', () => {
            docsOnlyProblems = !docsOnlyProblems;
            renderDocuments(currentDocs);
        });
        docsToolbar.append(label, btn);
    }

    function renderDocuments(docs) {
        if (!docsList) return;
        const all = docs || [];
        docsSignature = JSON.stringify(all);
        docsList.innerHTML = '';
        const problems = all.filter(docIsProblem);
        renderDocsToolbar(all.length, problems.length);
        if (docsCount) docsCount.textContent = all.length === 0 ? '' : String(all.length);
        if (all.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'kb-docs-empty';
            empty.textContent = t('Aucun document pour l\'instant.');
            docsList.appendChild(empty);
            return;
        }
        // Ce qui demande l'attention d'abord (échec, puis à vérifier, puis en
        // cours) : en bas d'une liste de 100 fichiers, un échec ne se voit pas.
        const rank = { error: 0, warn: 1, busy: 2, idle: 3, ok: 4 };
        (docsOnlyProblems ? problems : all)
            .map((d, i) => ({ d, i, r: rank[(d.state && d.state.tone) || 'idle'] ?? 3 }))
            .sort((a, b) => a.r - b.r || a.i - b.i)
            .forEach((x) => docsList.appendChild(documentRow(x.d)));
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
        const title = document.createElement('span');
        title.className = 'kb-doc-title';
        title.textContent = d.file_name;
        title.title = d.file_name;
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
        // Le motif s'affiche EN CLAIR pour ce qui ne va pas : un « échec » sans
        // raison ne dit ni quoi corriger ni s'il faut s'en inquiéter.
        if (st.detail && (st.tone === 'error' || st.tone === 'warn' || st.tone === 'ok')) {
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
                Diagram.render();
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
        Diagram.render();
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
                Diagram.render();
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
    // État de la base (panneau en tête de la fiche)
    // =====================================================================
    // Tant que la base travaille (passage en file ou en cours, documents en
    // lecture ou en vectorisation), panneau d'état et liste des documents se
    // rafraîchissent ensemble toutes les 2,5 s ; au repos, plus rien ne part.
    // « Travaille » est tranché par le serveur (`busy`) : une synchronisation
    // encore EN ATTENTE compte.
    const statusPanel = byId('kbStatusPanel');
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
                if (C.diagram && C.diagram.base) {
                    C.diagram.base.state = { title: data.title || '', tone: data.tone || '' };
                    Diagram.render();
                }
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
    // Assistants liés et partage : recherche, Tout / Aucun, cases liées
    // =====================================================================
    const assistantList   = byId('kbAssistantList');
    const assistantSearch = byId('kbAssistantSearch');
    const assistantEmpty  = byId('kbAssistantEmpty');
    const linkedCount     = byId('kbLinkedCount');
    const shareUserSearch = byId('kbShareUserSearch');
    const shareUserList   = byId('kbShareUserList');
    const shareUserEmpty  = byId('kbShareUserEmpty');

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
        linkedCount.textContent = n === 0 ? t('Aucun') : tn(n, '{n} lié', '{n} liés');
    }

    assistantSearch?.addEventListener('input', () => filterRows(assistantList, assistantSearch.value, assistantEmpty));
    shareUserSearch?.addEventListener('input', () => filterRows(shareUserList, shareUserSearch.value, shareUserEmpty));
    assistantList?.addEventListener('change', updateLinkedCount);

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

    // Interrupteur « Active » de l'en-tête.
    const activeBox  = byId('kbIsActive');
    const activeText = byId('kbActiveText');
    activeBox?.addEventListener('change', () => {
        if (!activeText) return;
        activeText.textContent = activeBox.checked ? t('Active') : t('Inactive');
        activeText.classList.toggle('is-off', !activeBox.checked);
    });

    // Supprimer / Débrancher : confirmation, puis le formulaire dédié.
    const deleteBtn = byId('kbDeleteBtn');
    deleteBtn?.addEventListener('click', () => {
        if (!confirm(deleteBtn.dataset.confirm || '')) return;
        // Plus rien à enregistrer d'une base qui part.
        if (window.MiaAutosave && typeof window.MiaAutosave.detach === 'function') window.MiaAutosave.detach(form);
        byId('kbDeleteForm')?.submit();
    });

    // =====================================================================
    // Schéma de la base, à droite (au-dessus de la fiche sur un écran étroit)
    // =====================================================================
    // Composé depuis les champs, sous la forme de KbDiagram::spec, et
    // redessiné à chaque geste (balisage identique : rien n'est reposé).
    // Chaque élément désigne ce qu'il montre (data-target) ; un clic dessus —
    // ou sur son bouton « crayon », au clavier — amène le réglage à l'écran,
    // l'allume un instant et lui donne le focus.
    const Diagram = (() => {
        const conf  = C.diagram;
        const panel = byId('kbDiagramPanel');
        const host  = byId('kbDiagram');
        const noop  = { render() {} };
        if (!conf || !panel || !host || !window.MiaKbDiagram) return noop;

        const titleEl = byId('kbDiagramTitle');
        const L = (conf.catalog && conf.catalog.labels) || {};
        const base = conf.base || {};
        const checkedIds = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]'))
            .filter((i) => i.checked && !i.disabled)
            .map((i) => Number(i.value));
        const boxValues = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]')).map((i) => Number(i.value));
        const value = (id) => (byId(id) ? byId(id).value : '');
        const hostOf = (url) => {
            try { return new URL(url).hostname; } catch (_e) { return ''; }
        };

        function access() {
            const acc = Object.assign({ owned: true, shared: false, can_manage: true, you: true, creator: C.userName, users: [], groups: [] }, base.access || {});
            if (!acc.owned) return acc;
            const rows = (side) => Array.from(form.querySelectorAll('input[data-share-target="' + side + '"]'))
                .filter((i) => i.checked)
                .map((i) => {
                    const row = i.closest('.kb-share-item');
                    const on = (sel) => !!(row && row.querySelector(sel) && row.querySelector(sel).checked);
                    return { id: Number(i.value), manage: on('.kb-share-manage input'), default: on('.kb-share-default input'), feed: on('.kb-share-feed input') };
                });
            // Sans section de partage dans la page (rien à cocher), la base enregistrée fait foi.
            if (form.querySelector('input[data-share-target]')) {
                acc.users  = rows('user');
                acc.groups = rows('group');
            }
            return acc;
        }

        function source(provider) {
            if (!PROVIDER_META[provider]) return { provider: '' };
            const url = syncUrl ? syncUrl.value.trim() : '';
            const unchanged = provider === saved.provider && url === saved.url;
            const ownerMode = canOwnerAuth(provider) && selectedAuth() === 'owner';
            let folder = '';
            if (pickedFor === provider && pickedName) folder = pickedName;
            else if (unchanged) folder = saved.folder;
            return {
                provider,
                linked: url !== '',
                folder,
                auto: !!(syncEnabled && syncEnabled.checked),
                owner: ownerMode ? (unchanged && saved.owner ? saved.owner : C.userName) : '',
            };
        }

        function spec() {
            const provider = selectedProvider();
            const live = provider === 'mybrain' || (!C.create && C.live);
            const files = C.create
                ? pendingFiles().map((f) => ({ name: f.name, image: (f.type || '').indexOf('image/') === 0, tone: '', label: L.file_new || '' }))
                : currentDocs.filter((d) => (d.source || 'upload') === 'upload').map((d) => ({
                    name: d.file_name,
                    image: (d.mime_type || '').indexOf('image/') === 0,
                    tone: (d.state && d.state.tone) || '',
                    label: (d.state && d.state.label) || '',
                }));
            // Assistants liés hors du périmètre (aucune case ici) : ils survivent à l'enregistrement.
            const inForm = boxValues('profile_ids[]');
            const outside = (base.assistant_ids || []).filter((id) => inForm.indexOf(Number(id)) < 0);
            return {
                name: value('kbName'),
                description: value('kbDescription'),
                active: !!(activeBox && activeBox.checked),
                system: !!base.system,
                live,
                creating: !!C.create,
                source: live ? { provider: '' } : source(provider),
                remote: live ? {
                    host: hostOf(value('kbMyBrainUrl')) || ((base.remote || {}).host || ''),
                    app: (base.remote || {}).app || '',
                } : null,
                files,
                synced_total: C.create ? 0 : currentDocs.filter((d) => (d.source || 'upload') !== 'upload').length,
                chunks: base.chunks || 0,
                usage: base.usage || 0,
                embedding: C.create ? (embSelect ? embSelect.value : '') : (base.embedding || ''),
                state: base.state || null,
                assistant_ids: checkedIds('profile_ids[]').concat(outside),
                access: access(),
            };
        }

        // ---- Du schéma au réglage ----
        // L'intitulé d'une section, sans le compteur que porte son titre.
        const sectionTitle = (id) => {
            const el = byId(id);
            const h = el ? el.querySelector('h4, .kb-advanced-title') : null;
            if (!h) return '';
            const copy = h.cloneNode(true);
            copy.querySelectorAll('.kb-linked-count').forEach((n) => n.remove());
            return copy.textContent.trim();
        };
        const titles = {
            identity:   sectionTitle('kbSectionIdentity'),
            sources:    sectionTitle('kbSectionSources'),
            assistants: sectionTitle('kbSectionAssistants'),
            sharing:    sectionTitle('kbSectionSharing'),
            advanced:   sectionTitle('avance'),
        };
        const firstBox = (scope) => (scope
            ? scope.querySelector('input[type="checkbox"]:checked:not(:disabled)') || scope.querySelector('input[type="checkbox"]:not(:disabled)')
            : null);
        const JUMP_TARGETS = {
            connectors: { section: 'sources', el: () => byId('kbSyncConfig') || byId('kbSectionSources'), field: () => syncUrl },
            live:       { section: 'sources', el: () => byId('kbMyBrainConfig') || byId('kbSectionSources'), field: () => byId('kbMyBrainUrl') },
            docs:       { section: 'sources', el: () => byId('kbDocsBlock') || byId('kbSectionSources'), field: () => byId('kbDocFileInput') || byId('kbDocsCreateInput') },
            llm:        { section: 'advanced', open: true, el: () => byId('kbEmbeddingField'), field: () => byId('kbReindexChoice') || byId('kbEmbeddingChoice') },
            embedding:  { section: 'advanced', open: true, el: () => byId('kbEmbeddingField'), field: () => byId('kbReindexChoice') || byId('kbEmbeddingChoice') },
            chunks:     { section: 'advanced', open: true, el: () => byId('kbChunksBlock') || byId('kbEmbeddingField'), field: () => byId('kbChunkSearch') },
            assistant:  { section: 'identity', el: () => byId('kbSectionIdentity'), field: () => byId('kbName') },
            active:     { section: '', el: () => byId('kbActiveToggle'), field: () => activeBox },
            state:      { section: '', el: () => statusPanel },
            assistants: { section: 'assistants', el: () => byId('kbSectionAssistants'), field: () => firstBox(assistantList) },
            sharing:    { section: 'sharing', el: () => byId('kbSectionSharing'), field: () => firstBox(byId('kbShareGroupList')) || firstBox(shareUserList) },
        };
        const jumpTitles = {};
        Object.keys(JUMP_TARGETS).forEach((key) => {
            const label = titles[JUMP_TARGETS[key].section];
            if (label) jumpTitles[key] = t('Modifier dans « {section} »', { section: label });
        });

        let spotTimer = null;
        function spotlight(el) {
            document.querySelectorAll('.kb-content .is-spotlit').forEach((x) => x.classList.remove('is-spotlit'));
            clearTimeout(spotTimer);
            el.classList.add('is-spotlit');
            spotTimer = setTimeout(() => el.classList.remove('is-spotlit'), 1800);
        }
        function jumpTo(key) {
            const jump = JUMP_TARGETS[key];
            if (!jump) return;
            if (jump.open && advanced && !advanced.open) advanced.open = true;
            const el = jump.el();
            if (!el || el.hidden) return;
            const target = jump.field ? jump.field() : null;
            const reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
            spotlight(el);
            if (target && typeof target.focus === 'function' && !target.disabled) {
                try { target.focus({ preventScroll: true }); } catch (_e) { target.focus(); }
            }
        }
        host.addEventListener('click', (e) => {
            const node = e.target.closest('[data-target]');
            if (node && host.contains(node)) jumpTo(node.dataset.target);
        });

        let timer = null;
        let first = true;
        function paint() {
            timer = null;
            const s = spec();
            if (titleEl) titleEl.textContent = String(s.name || '').trim() || L.untitled || '';
            panel.hidden = false;
            window.MiaKbDiagram.mount(host, s, conf.catalog, { animate: first, jump: jumpTitles });
            first = false;
        }
        function render() {
            if (timer === null) timer = setTimeout(paint, 90);
        }
        // Tout geste de la page : la fiche, l'interrupteur « Active » et le
        // modèle d'embedding rattachés par form= hors d'elle. Les écouteurs
        // des champs passent avant : le schéma lit l'état final.
        document.addEventListener('input', render);
        document.addEventListener('change', render);
        paint();
        return { render };
    })();

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

    // ── Extraits vectorisés : chargés à l'ouverture de « Avancé » ──
    const chunksBlock = byId('kbChunksBlock');
    const chunkList   = byId('kbChunkList');
    const chunkDoc    = byId('kbChunkDoc');
    const chunkSearch = byId('kbChunkSearch');
    const chunkPager  = byId('kbChunkPager');
    const chunkPrev   = byId('kbChunkPrev');
    const chunkNext   = byId('kbChunkNext');
    const chunkPage   = byId('kbChunkPage');
    const chunks = { page: 1, pages: 1, seq: 0, loaded: false };

    function chunkMessage(text) {
        chunkList.innerHTML = '';
        const p = document.createElement('p');
        p.className = 'kb-chunk-empty';
        p.textContent = text;
        chunkList.appendChild(p);
        show(chunkPager, false);
    }

    /** Le texte de l'extrait, le terme cherché surligné (nœuds texte : rien n'est interprété). */
    function highlighted(text, query) {
        const frag = document.createDocumentFragment();
        const q = (query || '').trim();
        if (!q) {
            frag.appendChild(document.createTextNode(text));
            return frag;
        }
        const lower = text.toLowerCase();
        const needle = q.toLowerCase();
        let at = 0;
        let hit = lower.indexOf(needle, at);
        while (hit !== -1) {
            frag.appendChild(document.createTextNode(text.slice(at, hit)));
            const mark = document.createElement('mark');
            mark.textContent = text.slice(hit, hit + q.length);
            frag.appendChild(mark);
            at = hit + q.length;
            hit = lower.indexOf(needle, at);
        }
        frag.appendChild(document.createTextNode(text.slice(at)));
        return frag;
    }

    function chunkRow(c, query, checked) {
        const item = document.createElement('article');
        item.className = 'kb-chunk';
        item.dataset.vector = !checked ? 'unknown' : (c.vector ? 'yes' : 'no');

        const head = document.createElement('div');
        head.className = 'kb-chunk-head';
        const idx = document.createElement('span');
        idx.className = 'kb-chunk-idx';
        idx.textContent = '#' + (Number(c.chunk_index) + 1);
        const doc = document.createElement('span');
        doc.className = 'kb-chunk-doc';
        doc.textContent = c.file_name;
        doc.title = c.file_name;
        const meta = document.createElement('span');
        meta.className = 'kb-chunk-meta';
        meta.textContent = t('{tokens} tokens · {chars} caractères', {
            tokens: Number(c.token_count).toLocaleString(LOCALE),
            chars: Number(c.chars).toLocaleString(LOCALE),
        });
        const vec = document.createElement('span');
        vec.className = 'kb-chunk-vec';
        vec.textContent = !checked ? t('Vecteur non vérifié') : (c.vector ? t('Vectorisé') : t('Sans vecteur'));
        vec.title = !checked
            ? t('Le moteur vectoriel ne répond pas : la présence du vecteur n’a pas pu être vérifiée.')
            : (c.vector ? t('Son vecteur est dans le moteur : la recherche par le sens le retrouve.')
                : t('Aucun vecteur dans le moteur : seule la recherche par mots le retrouve. « Réindexer » le recalcule.'));
        head.append(idx, doc, meta, vec);

        const text = document.createElement('p');
        text.className = 'kb-chunk-text';
        text.appendChild(highlighted(c.content, query));
        if (c.cut) text.appendChild(document.createTextNode(' […]'));
        item.append(head, text);

        // Long : replié sur quatre lignes, à déplier.
        if (c.content.length > 320) {
            text.classList.add('is-clamped');
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'kb-chunk-more';
            more.textContent = t('Afficher tout');
            more.setAttribute('aria-expanded', 'false');
            more.addEventListener('click', () => {
                const open = text.classList.toggle('is-clamped') === false;
                more.textContent = open ? t('Replier') : t('Afficher tout');
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            item.appendChild(more);
        }
        return item;
    }

    async function loadChunks() {
        if (!chunkList || !C.id) return;
        const mine = ++chunks.seq;
        chunks.loaded = true;
        chunkList.classList.add('is-loading');
        chunkList.setAttribute('aria-busy', 'true');
        const query = chunkSearch ? chunkSearch.value.trim() : '';
        const params = new URLSearchParams({ page: String(chunks.page) });
        if (chunkDoc && chunkDoc.value) params.set('doc', chunkDoc.value);
        if (query) params.set('q', query);
        try {
            const r = await fetch('/admin/knowledge-bases/' + C.id + '/chunks?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const data = await r.json().catch(() => ({}));
            if (mine !== chunks.seq) return;
            if (!data || !data.ok) { chunkMessage((data && data.error) || t('Lecture des extraits impossible.')); return; }
            chunks.page  = data.page;
            chunks.pages = data.pages;
            chunkList.innerHTML = '';
            if (!data.chunks.length) {
                chunkMessage(query ? t('Aucun extrait ne contient « {q} ».', { q: query }) : t('Aucun extrait pour ce document.'));
                return;
            }
            if (!data.vectors_checked) {
                const warn = document.createElement('p');
                warn.className = 'kb-chunk-warn';
                warn.textContent = t('Le moteur vectoriel ne répond pas : la présence des vecteurs n’a pas pu être vérifiée.');
                chunkList.appendChild(warn);
            }
            data.chunks.forEach((c) => chunkList.appendChild(chunkRow(c, query, !!data.vectors_checked)));
            const many = data.pages > 1;
            show(chunkPager, true);
            if (chunkPage) {
                chunkPage.textContent = (many ? t('Page {page} / {pages}', { page: data.page, pages: data.pages }) + ' · ' : '')
                    + tn(data.total, '{total} extrait', '{total} extraits', { total: data.total.toLocaleString(LOCALE) });
            }
            show(chunkPrev, many);
            show(chunkNext, many);
            if (chunkPrev) chunkPrev.disabled = data.page <= 1;
            if (chunkNext) chunkNext.disabled = data.page >= data.pages;
        } catch (e) {
            if (mine === chunks.seq) chunkMessage(t('Erreur réseau. Réessayez.'));
        } finally {
            if (mine === chunks.seq) {
                chunkList.classList.remove('is-loading');
                chunkList.removeAttribute('aria-busy');
            }
        }
    }

    if (chunksBlock && chunkList) {
        let searchTimer = null;
        chunkDoc?.addEventListener('change', () => { chunks.page = 1; loadChunks(); });
        chunkSearch?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { chunks.page = 1; loadChunks(); }, 300);
        });
        chunkPrev?.addEventListener('click', () => { if (chunks.page > 1) { chunks.page--; loadChunks(); chunksBlock.scrollIntoView({ block: 'start' }); } });
        chunkNext?.addEventListener('click', () => { if (chunks.page < chunks.pages) { chunks.page++; loadChunks(); chunksBlock.scrollIntoView({ block: 'start' }); } });
        advanced?.addEventListener('toggle', () => {
            if (advanced.open && !chunks.loaded) loadChunks();
        });
    }

    // =====================================================================
    // Démarrage
    // =====================================================================
    if (selectedProvider() === 'drive') setAuth(saved.auth);
    updateFeedUI();
    updateLinkedCount();
    renderDocuments(currentDocs);
    renderPending();
    Status.start();
    // Retour d'une réindexation (#avance) : la section revient ouverte.
    if (location.hash === '#avance' && advanced) {
        advanced.open = true;
        advanced.scrollIntoView({ block: 'start' });
    }
    Diagram.render();
})();
