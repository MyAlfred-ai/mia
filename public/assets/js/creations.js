// =========================================================
// GALERIE « MES CRÉATIONS » (/settings/creations)
// =========================================================
// Script autonome : AUCUNE dépendance à chat.js. La grille est rendue côté
// serveur ; ce script assure le filtre, les aperçus lazy et la visionneuse.
// - Aperçus page web / présentation : iframe blob: sandboxée alimentée par
//   /chat/message/{id}/html|deck (gardés par belongsToUser côté serveur).
// - Répliques de HTML_PREVIEW_GUARD_STYLE / HTML_PREVIEW_CSP /
//   absolutizeGeneratedAssets (chat.js, déjà dupliquées dans share.js) —
//   à synchroniser si la politique de l'aperçu évolue.

(function () {
    'use strict';

    const grid = document.getElementById('crGrid');
    if (!grid) return; // page vide (aucune création)

    // ---------------------------------------------------------
    // GARDES D'APERÇU (CSP + reset) — répliques de chat.js/share.js
    // ---------------------------------------------------------
    const GUARD_STYLE = `<style id="myllm-preview-guard">
html, body { margin: 0; padding: 0; width: 100%; max-width: 100%; overflow-x: hidden; }
*, *::before, *::after { box-sizing: border-box; }
body { overflow-wrap: break-word; }
img, video, canvas, svg, iframe { max-width: 100%; height: auto; }
pre { max-width: 100%; overflow: auto; }
</style>`;
    const PREVIEW_CDN = 'https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com';
    const PREVIEW_CSP = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ${location.origin}/assets/img/generated/ https: data: blob:; font-src data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'unsafe-inline' data: https://fonts.googleapis.com ${PREVIEW_CDN}; script-src 'unsafe-inline' 'unsafe-eval' blob: ${PREVIEW_CDN}; media-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'">`;

    // Base blob: (origine opaque) : les chemins absolus /assets/img/generated/…
    // n'y résolvent pas → réécrits en URL absolue de l'app (servie statiquement).
    function absolutizeGeneratedAssets(html) {
        return String(html || '').replace(
            /(\s(?:src|srcset)\s*=\s*["'])\/assets\/img\/generated\//gi,
            '$1' + location.origin + '/assets/img/generated/'
        );
    }

    function ensurePreviewGuards(rawHtml) {
        let doc = String(rawHtml || '').trim();
        const baseHead = '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';

        if (!doc) {
            return `<!DOCTYPE html><html><head>${PREVIEW_CSP}${baseHead}${GUARD_STYLE}</head><body></body></html>`;
        }
        if (!/<html\b/i.test(doc)) {
            return `<!DOCTYPE html><html><head>${PREVIEW_CSP}${baseHead}${GUARD_STYLE}</head><body>${doc}</body></html>`;
        }
        if (!/<!doctype\s+html\b/i.test(doc)) {
            doc = `<!DOCTYPE html>\n${doc}`;
        }
        if (!/<head\b/i.test(doc)) {
            doc = doc.replace(/<html\b([^>]*)>/i, `<html$1><head>${PREVIEW_CSP}${baseHead}${GUARD_STYLE}</head>`);
        } else {
            if (!/<meta[^>]+charset\s*=\s*["']?utf-8["']?/i.test(doc)) {
                doc = doc.replace(/<head\b[^>]*>/i, '$&<meta charset="UTF-8">');
            }
            if (!/<meta[^>]+name\s*=\s*["']viewport["']/i.test(doc)) {
                doc = doc.replace(/<head\b[^>]*>/i, '$&<meta name="viewport" content="width=device-width, initial-scale=1.0">');
            }
            if (!/id=(["'])myllm-preview-guard\1/i.test(doc)) {
                doc = doc.replace(/<head\b[^>]*>/i, `$&${GUARD_STYLE}`);
            }
            // Injectée en dernier → juste après <head> (gouverne tout ce qui suit).
            if (!/http-equiv\s*=\s*["']content-security-policy["']/i.test(doc)) {
                doc = doc.replace(/<head\b[^>]*>/i, `$&${PREVIEW_CSP}`);
            }
        }
        if (!/<body\b/i.test(doc)) {
            if (/<\/head>/i.test(doc)) doc = doc.replace(/<\/head>/i, '</head><body>');
            if (!/<\/body>/i.test(doc)) doc += '</body>';
        }
        if (!/<\/html>/i.test(doc)) doc += '</html>';
        return doc;
    }

    // ---------------------------------------------------------
    // FILTRE (recherche + type)
    // ---------------------------------------------------------
    const cards      = Array.from(grid.querySelectorAll('.creation-card'));
    const searchEl   = document.getElementById('crSearch');
    const typeEl     = document.getElementById('crType');
    const countEl    = document.getElementById('crCount');
    const noResults  = document.getElementById('crNoResults');
    const total      = cards.length;

    // Miroir de $crNorm (templates/settings/creations.php) : minuscules, accents ignorés.
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '').replace(/œ/g, 'oe').replace(/æ/g, 'ae');
    }

    function applyFilters() {
        const q    = norm(searchEl ? searchEl.value.trim() : '');
        const type = typeEl ? typeEl.value : '';
        let shown  = 0;

        cards.forEach((card) => {
            const okType   = !type || card.dataset.type === type;
            const okSearch = !q
                || (card.dataset.title || '').includes(q)
                || (card.dataset.conv  || '').includes(q);
            const visible  = okType && okSearch;
            card.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });

        if (countEl) {
            countEl.textContent = (shown === total)
                ? tn(total, '{n} création', '{n} créations')
                : tn(total, '{vus} / {n} création', '{vus} / {n} créations', { vus: shown });
        }
        if (noResults) noResults.style.display = shown === 0 ? '' : 'none';
    }

    if (searchEl) searchEl.addEventListener('input', applyFilters);
    if (typeEl)   typeEl.addEventListener('change', applyFilters);

    // ---------------------------------------------------------
    // APERÇUS LAZY (page web / présentation)
    // ---------------------------------------------------------
    // Largeur du « navigateur » virtuel dans lequel la page est rendue avant
    // réduction. 1280 px donnait un facteur d'échelle de ~0,22 sur une carte :
    // le texte tombait sous le pixel et la vignette n'était qu'une tache. À
    // 720 px, le facteur double, les pages responsives passent en mise en page
    // tablette (colonne large, gros titres) et la vignette redevient lisible.
    const PREVIEW_W     = 720;
    const PREVIEW_RATIO = 16 / 10; // doit suivre l'aspect-ratio de .creation-thumb
    const PREVIEW_H     = Math.round(PREVIEW_W / PREVIEW_RATIO);
    const blobCache = new Map(); // message_id -> blob URL (réutilisé par la visionneuse)

    function fetchPreviewDoc(msgId, kind) {
        if (blobCache.has(msgId)) return Promise.resolve(blobCache.get(msgId));
        const url = '/chat/message/' + encodeURIComponent(msgId) + (kind === 'deck' ? '/deck' : '/html');
        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then((data) => {
                const doc     = ensurePreviewGuards(absolutizeGeneratedAssets(data.html));
                const blobUrl = URL.createObjectURL(new Blob([doc], { type: 'text/html' }));
                blobCache.set(msgId, blobUrl);
                return blobUrl;
            });
    }

    function scaleFrame(frame) {
        const thumb = frame.parentElement;
        if (!thumb) return;
        const ratio = thumb.clientWidth / PREVIEW_W;
        frame.style.transform = `scale(${ratio})`;
    }

    function loadPreview(card) {
        const thumb = card.querySelector('.creation-thumb');
        const msgId = card.dataset.msg;
        if (!thumb || !msgId) return;

        fetchPreviewDoc(msgId, card.dataset.preview).then((blobUrl) => {
            const frame = document.createElement('iframe');
            frame.className = 'creation-thumb-frame';
            frame.setAttribute('sandbox', 'allow-scripts');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            frame.setAttribute('tabindex', '-1');
            frame.setAttribute('aria-hidden', 'true');
            // Dimensions posées ici (et non en CSS) : elles doivent rester
            // solidaires du facteur d'échelle calculé sur PREVIEW_W.
            frame.style.width  = PREVIEW_W + 'px';
            frame.style.height = PREVIEW_H + 'px';
            frame.src = blobUrl;
            thumb.appendChild(frame);
            scaleFrame(frame);
            const loader = thumb.querySelector('.creation-thumb-loading');
            if (loader) loader.remove();
        }).catch(() => {
            // Session expirée, message supprimé… : on garde la tuile d'icône,
            // sans texte d'attente trompeur.
            const span = thumb.querySelector('.creation-thumb-loading span');
            if (span) span.remove();
        });
    }

    const lazyCards = cards.filter((c) => c.dataset.preview);
    if (lazyCards.length) {
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    io.unobserve(entry.target);
                    loadPreview(entry.target);
                });
            }, { rootMargin: '250px' });
            lazyCards.forEach((c) => io.observe(c));
        } else {
            lazyCards.forEach(loadPreview);
        }
    }

    // Redimensionnement : les iframes déjà posées se remettent à l'échelle.
    let resizeRaf = 0;
    window.addEventListener('resize', () => {
        if (resizeRaf) return;
        resizeRaf = requestAnimationFrame(() => {
            resizeRaf = 0;
            grid.querySelectorAll('.creation-thumb-frame').forEach(scaleFrame);
        });
    });

    // ---------------------------------------------------------
    // VISIONNEUSE
    // ---------------------------------------------------------
    const viewer   = document.getElementById('creationViewer');
    const cvChip   = document.getElementById('cvChip');
    const cvTitle  = document.getElementById('cvTitle');
    const cvBody   = document.getElementById('cvBody');
    const cvOpen   = document.getElementById('cvOpenConv');
    const cvClose  = document.getElementById('cvClose');

    function closeViewer() {
        if (!viewer) return;
        viewer.style.display = 'none';
        if (cvBody) cvBody.innerHTML = ''; // stoppe une vidéo en lecture
        document.body.classList.remove('lightbox-open');
    }

    function openViewer(card) {
        if (!viewer || !cvBody) return;
        const type = card.dataset.type;

        cvChip.textContent = card.dataset.label || t('Création');
        cvChip.style.setProperty('--tool-accent', getComputedStyle(card).getPropertyValue('--tool-accent'));
        cvTitle.textContent = (card.querySelector('.creation-title') || {}).textContent || '';
        if (cvOpen) cvOpen.href = '/chat/' + card.dataset.convId;
        cvBody.innerHTML = '';

        if (type === 'generated_image') {
            const img = document.createElement('img');
            img.src = card.dataset.url;
            img.alt = cvTitle.textContent;
            cvBody.appendChild(img);
        } else if (type === 'video_file') {
            const video = document.createElement('video');
            video.src = card.dataset.url;
            video.controls = true;
            video.autoplay = true;
            video.playsInline = true;
            cvBody.appendChild(video);
        } else {
            const frame = document.createElement('iframe');
            frame.className = 'creation-viewer-frame';
            frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            cvBody.appendChild(frame);
            fetchPreviewDoc(card.dataset.msg, card.dataset.preview)
                .then((blobUrl) => { frame.src = blobUrl; })
                .catch(() => { cvBody.innerHTML = '<div class="al-empty">Aperçu indisponible.</div>'; });
        }

        viewer.style.display = 'flex';
        document.body.classList.add('lightbox-open');
    }

    function activate(card) {
        // L'itinéraire n'a pas d'aperçu autonome (Leaflet vit dans le chat) :
        // on renvoie directement vers sa conversation.
        if (card.dataset.type === 'map_route') {
            location.href = '/chat/' + card.dataset.convId;
            return;
        }
        openViewer(card);
    }

    // La carte entière est cliquable : les boutons d'action sont donc traités
    // AVANT elle, sinon chaque téléchargement ouvrirait aussi la visionneuse.
    grid.addEventListener('click', (e) => {
        const shareBtn = e.target.closest('.btn-share-creation');
        if (shareBtn) { handleShareCreationClick(shareBtn); return; }

        const downloadBtn = e.target.closest('.btn-download-creation');
        if (downloadBtn) { handleDownloadClick(downloadBtn); return; }

        if (e.target.closest('a')) return; // lien « conversation » : navigation normale
        const card = e.target.closest('.creation-card');
        if (card) activate(card);
    });

    grid.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('button')) return; // le bouton reçoit son propre clic
        const card = e.target.closest('.creation-card');
        if (!card) return;
        e.preventDefault();
        activate(card);
    });

    if (cvClose) cvClose.addEventListener('click', closeViewer);
    if (viewer) viewer.addEventListener('click', (e) => { if (e.target === viewer) closeViewer(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && viewer && viewer.style.display !== 'none') closeViewer();
    });

    // ---------------------------------------------------------
    // PARTAGE — réplique de chat.js (§ « PARTAGE D'UNE CRÉATION »)
    // ---------------------------------------------------------
    // Mêmes routes, mêmes classes, mêmes libellés, même enchaînement (1er clic
    // = création du lien + copie ; bouton « Partagé » = menu Copier / Régénérer
    // / Révoquer). chat.js n'expose rien sur window et sa délégation est clouée
    // à #chatMessages : le code est donc dupliqué — à synchroniser si la
    // mécanique de partage évolue côté chat.

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    /** Toast de notification — réplique de showToast (chat.js), CSS déjà global. */
    function showToast(message, type = 'info') {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.style.cssText = 'position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:9999';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('toast-visible'), 10);
        setTimeout(() => {
            toast.classList.remove('toast-visible');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    async function shareCreationRequest(msgId, regenerate) {
        const body = new FormData();
        if (regenerate) body.append('regenerate', '1');
        const res = await fetch(`/chat/message/${msgId}/share`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            body,
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function unshareCreationRequest(msgId) {
        const res = await fetch(`/chat/message/${msgId}/unshare`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // Un message image-seule peut porter PLUSIEURS cartes (une par image) :
    // toutes suivent l'état du message, qui est l'unité de partage.
    function setShareButtonsState(msgId, token) {
        document.querySelectorAll(`.btn-share-creation[data-msg-id="${msgId}"]`).forEach((btn) => {
            btn.dataset.shareToken = token || '';
            btn.classList.toggle('shared', !!token);
            const svg = btn.querySelector('svg');
            btn.innerHTML = (svg ? svg.outerHTML : '') + (token ? t('Partagé') : t('Partager'));
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

    // Un seul menu flottant à la fois, partage ou téléchargement confondus.
    function closePopovers() {
        document.getElementById('sharePopover')?.remove();
        document.getElementById('creationDownloadPopover')?.remove();
    }

    /** Place un menu flottant sous son bouton, borné à la fenêtre. */
    function placePopover(pop, btn) {
        // Pixels d'écran → pixels de page : la taille d'affichage zoome <html>
        // (cf. head-meta.php, window.miaUiZoom).
        const z = window.miaUiZoom ? window.miaUiZoom() : 1;
        const rect = btn.getBoundingClientRect();
        pop.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - pop.offsetWidth * z - 8)) / z}px`;
        pop.style.top  = `${(rect.bottom + 6) / z}px`;
    }

    /** Fermeture au clic hors du menu (différée : le clic courant ne doit pas fermer). */
    function closeOnOutsideClick(selector) {
        setTimeout(() => {
            document.addEventListener('click', function onDocClick(ev) {
                if (!ev.target.closest(selector)) {
                    closePopovers();
                    document.removeEventListener('click', onDocClick);
                }
            });
        }, 0);
    }

    // Menu flottant du bouton « Partagé » : Copier / Régénérer / Révoquer.
    function openSharePopover(btn) {
        closePopovers();
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
        placePopover(pop, btn);

        pop.addEventListener('click', async (ev) => {
            const action = ev.target.closest('[data-share-action]')?.dataset.shareAction;
            if (!action) return;
            closePopovers();
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

        closeOnOutsideClick('#sharePopover');
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

    // ---------------------------------------------------------
    // TÉLÉCHARGEMENT
    // ---------------------------------------------------------
    // Trois chemins selon le type, comme dans le chat : fichier statique
    // (image, vidéo) servi tel quel, document HTML relu avec ses images
    // ré-embarquées, présentation exportée en PPTX / PDF / page HTML.

    function saveBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 8000);
    }

    /** Fichier statique same-origin : l'ancre suffit, ni fetch ni blob. */
    function saveDirect(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || '';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    // `inline=1` ré-embarque les images générées en data-URI : le fichier
    // enregistré s'ouvre depuis le disque, sans accès au serveur. On ne
    // réutilise donc PAS le blob de l'aperçu (qui, lui, pointe des URL).
    async function fetchInlineHtml(msgId, kind) {
        const path = kind === 'deck' ? '/deck' : '/html';
        const res  = await fetch(`/chat/message/${encodeURIComponent(msgId)}${path}?inline=1`, {
            headers: { 'Accept': 'application/json' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (typeof data.html !== 'string') throw new Error(t('Réponse inattendue'));
        return data.html;
    }

    async function exportDeckBinary(msgId, format) {
        const res = await fetch(`/chat/message/${encodeURIComponent(msgId)}/deck/${format}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        if (!res.ok) {
            const payload = await res.json().catch(() => ({}));
            throw new Error(payload.error || t('Export impossible.'));
        }
        return res.blob();
    }

    /** Exécute un téléchargement en gardant le bouton occupé pendant l'attente. */
    async function runDownload(btn, task) {
        const label = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = t('Préparation…');
        try {
            await task();
        } catch (err) {
            showToast(err?.message || t('Téléchargement impossible.'), 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = label;
        }
    }

    // Menu des formats d'une présentation — même liste que « Exporter » du volet deck.
    function openDeckDownloadMenu(btn) {
        closePopovers();
        const msgId = btn.dataset.msgId;
        const base  = btn.dataset.filename || 'presentation';
        if (!msgId) return;

        const pop = document.createElement('div');
        pop.id = 'creationDownloadPopover';
        pop.className = 'creation-popover';
        pop.innerHTML = `
            <button type="button" data-format="pptx">PowerPoint (.pptx)</button>
            <button type="button" data-format="pdf">PDF (paysage)</button>
            <button type="button" data-format="html">Page HTML</button>`;
        document.body.appendChild(pop);
        placePopover(pop, btn);

        pop.addEventListener('click', (ev) => {
            const format = ev.target.closest('[data-format]')?.dataset.format;
            if (!format) return;
            closePopovers();
            runDownload(btn, async () => {
                if (format === 'html') {
                    const html = await fetchInlineHtml(msgId, 'deck');
                    saveBlob(new Blob([html], { type: 'text/html' }), `${base}.html`);
                } else {
                    saveBlob(await exportDeckBinary(msgId, format), `${base}.${format}`);
                }
            });
        });

        closeOnOutsideClick('#creationDownloadPopover');
    }

    function handleDownloadClick(btn) {
        const kind = btn.dataset.download;

        if (kind === 'direct') {
            saveDirect(btn.dataset.url, btn.dataset.filename);
            return;
        }
        if (kind === 'deck') {
            openDeckDownloadMenu(btn);
            return;
        }
        if (kind === 'html') {
            runDownload(btn, async () => {
                const html = await fetchInlineHtml(btn.dataset.msgId, 'html');
                saveBlob(new Blob([html], { type: 'text/html' }), btn.dataset.filename || 'page.html');
            });
        }
    }

    // Un menu flottant ne suit pas son bouton : le fermer plutôt que le laisser dériver.
    window.addEventListener('scroll', closePopovers, true);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePopovers(); });

    applyFilters();
})();
