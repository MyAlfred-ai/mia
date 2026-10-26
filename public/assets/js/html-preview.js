/**
 * Aperçu d'une page web créée par un workflow, dans l'historique et sur la
 * page de l'exécution : un cadre `[data-html-preview="<adresse>"]` (rendu par
 * WorkflowContentView) contient une <iframe sandbox="allow-scripts">. À
 * l'ouverture du dépliant qui le porte, on lit la page (son téléchargement :
 * images déjà embarquées), on y pose les gardes de l'aperçu du chat (CSP qui
 * scelle les canaux d'exfiltration, remise à zéro de la mise en page), et on
 * la sert en blob: — origine opaque, isolée de l'application.
 *
 * Aucun texte ici : l'avertissement d'un échec est rendu (caché) par le
 * serveur, ce script ne fait que le montrer.
 *
 * Répliques de HTML_PREVIEW_GUARD_STYLE / HTML_PREVIEW_CSP (chat.js, déjà
 * dupliquées dans share.js et creations.js) — à synchroniser si la politique
 * de l'aperçu évolue.
 */
(function () {
    'use strict';

    var GUARD_STYLE = '<style id="myllm-preview-guard">'
        + 'html, body { margin: 0; padding: 0; width: 100%; max-width: 100%; overflow-x: hidden; }'
        + '*, *::before, *::after { box-sizing: border-box; }'
        + 'body { overflow-wrap: break-word; }'
        + 'img, video, canvas, svg, iframe { max-width: 100%; height: auto; }'
        + 'pre { max-width: 100%; overflow: auto; }'
        + '</style>';
    var PREVIEW_CDN = 'https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com';
    var PREVIEW_CSP = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src '
        + location.origin + '/assets/img/generated/ https: data: blob:; font-src data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; '
        + 'style-src \'unsafe-inline\' data: https://fonts.googleapis.com ' + PREVIEW_CDN + '; script-src \'unsafe-inline\' \'unsafe-eval\' blob: ' + PREVIEW_CDN + '; '
        + 'media-src data: blob:; connect-src \'none\'; form-action \'none\'; base-uri \'none\'">';
    var BASE_HEAD = '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';

    /** La page, gardes posées en tête (la CSP AVANT tout script de la page). Pure. */
    function guard(raw) {
        var doc = String(raw || '').trim();
        if (!doc) {
            return '<!DOCTYPE html><html><head>' + PREVIEW_CSP + BASE_HEAD + GUARD_STYLE + '</head><body></body></html>';
        }
        if (!/<html\b/i.test(doc)) {
            return '<!DOCTYPE html><html><head>' + PREVIEW_CSP + BASE_HEAD + GUARD_STYLE + '</head><body>' + doc + '</body></html>';
        }
        if (!/<!doctype\s+html\b/i.test(doc)) {
            doc = '<!DOCTYPE html>\n' + doc;
        }
        if (!/<head\b/i.test(doc)) {
            return doc.replace(/<html\b([^>]*)>/i, function (m, attrs) {
                return '<html' + attrs + '><head>' + PREVIEW_CSP + BASE_HEAD + GUARD_STYLE + '</head>';
            });
        }
        return doc.replace(/<head\b[^>]*>/i, function (m) { return m + PREVIEW_CSP + BASE_HEAD + GUARD_STYLE; });
    }

    // Une page lue une fois par adresse : le journal se redessine en direct,
    // la même page ne se retélécharge pas à chaque fois.
    var urls = {};

    function blobUrl(src) {
        if (!urls[src]) {
            urls[src] = fetch(src, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) { throw new Error(String(r.status)); }
                    return r.text();
                })
                .then(function (html) {
                    return URL.createObjectURL(new Blob([guard(html)], { type: 'text/html' }));
                })
                .catch(function (e) {
                    delete urls[src];
                    throw e;
                });
        }
        return urls[src];
    }

    function mount(box) {
        if (box.getAttribute('data-mounted')) { return; }
        var src = box.getAttribute('data-html-preview') || '';
        var frame = box.querySelector('iframe');
        // Seulement une adresse de cette application (jamais un tiers).
        if (!frame || src.charAt(0) !== '/' || src.charAt(1) === '/') { return; }
        box.setAttribute('data-mounted', '1');
        box.classList.add('is-loading');
        blobUrl(src).then(function (url) {
            frame.src = url;
            box.classList.remove('is-loading');
            box.classList.add('is-ready');
        }, function () {
            box.classList.remove('is-loading');
            box.removeAttribute('data-mounted');
            var err = box.querySelector('[data-preview-error]');
            if (err) { err.hidden = false; }
            frame.hidden = true;
        });
    }

    /** Monte les aperçus visibles de `root` (ceux d'un dépliant fermé attendent son ouverture). */
    function scan(root) {
        (root || document).querySelectorAll('[data-html-preview]').forEach(function (box) {
            var d = box.closest('details');
            if (!d || d.open) { mount(box); }
        });
    }

    // `toggle` ne remonte pas : écouté en capture, sur tout le document.
    document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (d && d.tagName === 'DETAILS' && d.open) { scan(d); }
    }, true);

    window.MiaHtmlPreview = { guard: guard, scan: scan };
})();
