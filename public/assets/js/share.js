// =========================================================
// PAGE DE PARTAGE D'UNE CRÉATION (/share/{token})
// =========================================================
// Script autonome : AUCUNE dépendance à chat.js. Le payload (type + contenu)
// est lu depuis le data-island JSON #sharePayload rendu côté serveur.
// - Page HTML  : rendue dans une iframe blob: sandboxée, jamais same-origin.
// - Itinéraire : rendu Leaflet épuré, dupliqué depuis renderMapSpec (chat.js)
//   — à synchroniser si la logique de rendu carte évolue côté chat.
// - Image / vidéo : rendues par le template, rien à faire ici.

(function () {
    'use strict';

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    // ---------------------------------------------------------
    // PAGE HTML — iframe blob: sandboxée (parité viewer chat.js)
    // ---------------------------------------------------------

    // Répliques de HTML_PREVIEW_GUARD_STYLE / HTML_PREVIEW_CSP (chat.js) —
    // à synchroniser si la politique de l'aperçu évolue. La CSP scelle les
    // canaux d'exfiltration (connect-src/form-action/base-uri 'none') tout en
    // autorisant les CDN statiques dont dépendent les pages générées par LLM.
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

    function renderSharedHtml(html) {
        const frame = document.getElementById('shareHtmlFrame');
        if (!frame) return;
        const doc  = ensurePreviewGuards(absolutizeGeneratedAssets(html));
        const blob = new Blob([doc], { type: 'text/html' });
        frame.src  = URL.createObjectURL(blob);
    }

    // ---------------------------------------------------------
    // ITINÉRAIRE — rendu Leaflet (réplique épurée de renderMapSpec)
    // ---------------------------------------------------------

    const MAP_PROFILE_LABELS = { driving: t('En voiture'), cycling: t('À vélo'), walking: t('À pied') };

    function mapProfileToOsrm(profile) {
        return { driving: 'driving', cycling: 'cycling', walking: 'walking' }[profile] || 'driving';
    }

    // Instances OSRM FOSSGIS par profil (réplique de mapOsrmBase, chat.js — à
    // synchroniser) : routed-foot suit les sentiers, le serveur démo OSRM ne
    // servant que le profil voiture.
    function mapOsrmBase(profile) {
        return {
            driving: 'https://routing.openstreetmap.de/routed-car',
            cycling: 'https://routing.openstreetmap.de/routed-bike',
            walking: 'https://routing.openstreetmap.de/routed-foot',
        }[profile] || 'https://routing.openstreetmap.de/routed-car';
    }

    function mapAccentColor() {
        const c = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
        return c || '#2563eb';
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

    function renderMapNotes(spec, meta, warnings) {
        const panel = document.getElementById('shareMapNotes');
        if (!panel) return;

        const statParts = [MAP_PROFILE_LABELS[spec.profile] || MAP_PROFILE_LABELS.driving];
        if (meta && !meta.approx) {
            const d = formatMapDistance(meta.distance);
            if (d) statParts.push(d);
            // À pied : duration_hint du modèle (tient compte du dénivelé) prime
            // sur la durée OSRM à plat (parité renderMapNotes, chat.js).
            const duree = formatMapDuration(meta.duration);
            if (spec.profile === 'walking' && spec.duration_hint) {
                statParts.push(spec.duration_hint);
            } else if (spec.profile === 'walking' && duree) {
                statParts.push(t('≈ {duree} (hors dénivelé)', { duree: duree }));
            } else if (duree) {
                statParts.push(t('≈ {duree}', { duree: duree }));
            }
        } else if (spec.duration_hint) {
            statParts.push(spec.duration_hint);
        }

        const steps = (spec.waypoints || []).map((w) => {
            const lost = (typeof w.lat !== 'number' || typeof w.lon !== 'number')
                ? ' <span class="map-step-lost" title="Lieu non localisé sur la carte">non localisée</span>' : '';
            const note = w.note ? `<span class="map-step-note">${escHtml(w.note)}</span>` : '';
            return `<li><strong>${escHtml(w.name || w.query || '?')}</strong>${lost}${note}</li>`;
        }).join('');

        const notes = Array.isArray(spec.notes) ? spec.notes.filter(Boolean) : [];
        const warns = (warnings || []).filter(Boolean);

        panel.innerHTML = `
            <div class="map-notes-head"><strong>${escHtml(spec.title || 'Itinéraire')}</strong></div>
            <div class="map-notes-stats">${escHtml(statParts.join(' · '))}</div>
            ${spec.summary ? `<p class="map-notes-summary">${escHtml(spec.summary)}</p>` : ''}
            ${warns.length ? `<div class="map-notes-warnings">${warns.map((w) => `<div>⚠ ${escHtml(w)}</div>`).join('')}</div>` : ''}
            <ol class="map-notes-steps">${steps}</ol>
            ${notes.length ? `<ul class="map-notes-general">${notes.map((n) => `<li>${escHtml(n)}</li>`).join('')}</ul>` : ''}
        `;
    }

    async function renderSharedMap(spec) {
        const canvas = document.getElementById('shareMapCanvas');
        if (!canvas || !Array.isArray(spec.waypoints)) return;
        if (typeof L === 'undefined') {
            console.error('[Share] Leaflet indisponible');
            return;
        }

        const map = L.map('shareMapCanvas', { preferCanvas: true });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            crossOrigin: 'anonymous',
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
        }).addTo(map);

        const waypoints = spec.waypoints;
        const located = waypoints.filter((w) => typeof w.lat === 'number' && typeof w.lon === 'number');
        const lost    = waypoints.filter((w) => typeof w.lat !== 'number' || typeof w.lon !== 'number');
        const warnings = [];
        if (lost.length) {
            warnings.push(t('Étape(s) non localisée(s) : ') + lost.map((w) => w.name || w.query || '?').join(', ') + '.');
        }

        located.forEach((w) => {
            const idx  = waypoints.indexOf(w) + 1;
            const icon = L.divIcon({
                className: 'map-marker-wrap',
                html: `<div class="map-marker">${idx}</div>`,
                iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -30],
            });
            const note = w.note ? `<div class="map-popup-note">${escHtml(w.note)}</div>` : '';
            L.marker([w.lat, w.lon], { icon, title: w.name || '' })
                .addTo(map)
                .bindPopup(`<strong>${idx}. ${escHtml(w.name || '')}</strong>${note}`);
        });

        if (located.length === 0) {
            map.setView([46.6, 2.4], 5); // vue France par défaut
            warnings.push(t('Aucune étape localisable.'));
            renderMapNotes(spec, null, warnings);
            return;
        }

        map.fitBounds(L.latLngBounds(located.map((w) => [w.lat, w.lon])), { padding: [40, 40], maxZoom: 14 });
        renderMapNotes(spec, null, warnings);

        // Tracé réel par la route (OSRM public), fallback lignes droites pointillées
        let meta = null;
        let routeLatLngs = null;
        if (located.length >= 2) {
            try {
                const coords = located.map((w) => `${w.lon},${w.lat}`).join(';');
                const ctrl = new AbortController();
                const timer = setTimeout(() => ctrl.abort(), 12000);
                const res = await fetch(
                    `${mapOsrmBase(spec.profile)}/route/v1/${mapProfileToOsrm(spec.profile)}/${coords}?overview=full&geometries=geojson&steps=false`,
                    { signal: ctrl.signal }
                );
                clearTimeout(timer);
                const data = await res.json();
                if (data && data.code === 'Ok' && data.routes && data.routes[0]) {
                    routeLatLngs = data.routes[0].geometry.coordinates.map((c) => [c[1], c[0]]);
                    meta = { distance: data.routes[0].distance, duration: data.routes[0].duration, approx: false };
                }
            } catch (_) { /* OSRM injoignable → fallback ci-dessous */ }
        }

        if (routeLatLngs) {
            L.polyline(routeLatLngs, { color: mapAccentColor(), weight: 5, opacity: 0.85 }).addTo(map);
            map.fitBounds(L.latLngBounds(routeLatLngs), { padding: [40, 40] });
        } else if (located.length >= 2) {
            L.polyline(located.map((w) => [w.lat, w.lon]), {
                color: mapAccentColor(), weight: 4, opacity: 0.7, dashArray: '8 8',
            }).addTo(map);
            warnings.push(t('Tracé approximatif : le calcul d\'itinéraire par la route est momentanément indisponible.'));
            meta = { approx: true };
        }
        renderMapNotes(spec, meta, warnings);
    }

    // ---------------------------------------------------------
    // POINT D'ENTRÉE — en fin de script : les const ci-dessus (GUARD_STYLE,
    // PREVIEW_CSP, MAP_PROFILE_LABELS…) doivent être initialisées AVANT tout
    // rendu (les fonctions sont hoistées, les const non — zone morte temporelle).
    // ---------------------------------------------------------
    const payloadEl = document.getElementById('sharePayload');
    if (!payloadEl) return;

    let payload = null;
    try {
        payload = JSON.parse(payloadEl.textContent || 'null');
    } catch (_) { /* payload illisible → page inerte */ }
    if (!payload || !payload.type) return;

    // Une présentation est du HTML autonome produit par DeckRenderer : même
    // chemin de rendu qu'une page web partagée.
    if ((payload.type === 'html_page' || payload.type === 'slide_deck') && typeof payload.html === 'string') {
        renderSharedHtml(payload.html);
    } else if (payload.type === 'map_route' && payload.map) {
        renderSharedMap(payload.map);
    }
})();
