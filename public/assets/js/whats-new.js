/**
 * Modale « Nouveautés » — le contenu du clic sur le numéro de version.
 *
 * Ce que l'utilisateur lit n'est PAS un journal de commits mais une liste de
 * fonctionnalités rédigées (docs/NOUVEAUTES.md → public/changelog.json). Le
 * fichier porte trois listes :
 *   - `nouveautes` : les 20 plus récentes {id, titre, corps, genre, pour, date, version} ;
 *   - `anciennes`  : l'historique, même forme, affiché à la demande
 *                    (« Voir les nouveautés plus anciennes ») et toujours
 *                    parcouru par la recherche ;
 *   - `commits`    : les sujets de commit, détail technique replié et réservé
 *                    aux comptes de l'éditeur (window.IS_STAFF).
 *
 * Ce module remplace deux blocs recopiés (chat.js et la page À propos) : le
 * même bouton #versionBtn ouvre la même modale partout. Les libellés fixes
 * (en-tête, filtres, recherche) sont rendus par le gabarit ; ce script ne
 * porte que ceux des cartes.
 *
 * PASTILLE « NON LU » : l'identifiant de la nouveauté la plus récente est
 * injecté par le gabarit (window.WHATS_NEW_LAST_ID) — aucun appel réseau au
 * chargement de la page. Ce qui a été lu est retenu par navigateur, sous une
 * clé qui porte l'identifiant du compte : sur un poste partagé, le collègue
 * qui se connecte ensuite voit ses propres nouveautés comme non lues. Les
 * entrées arrivées depuis la dernière ouverture portent « Nouveau pour vous ».
 */
(function () {
    'use strict';

    const btn      = document.getElementById('versionBtn');
    const modal    = document.getElementById('versionModal');
    const list     = document.getElementById('versionList');
    const closeBtn = document.getElementById('versionClose');
    const closeX   = document.getElementById('versionCloseX');
    const search   = document.getElementById('versionSearch');
    const filters  = document.getElementById('versionFilters');
    const tech     = document.getElementById('versionTech');
    const techList = document.getElementById('versionTechList');
    if (!btn || !modal || !list) return;

    const ICONS = {
        nouveaute:    '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>',
        amelioration: '<path d="M4 17l6-6 4 4 6-7"/><path d="M14 8h6v6"/>',
        correctif:    '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
    };
    const GENRES = {
        nouveaute:    { label: t('Nouveauté'),    cls: 'wn-tone-new' },
        amelioration: { label: t('Amélioration'), cls: 'wn-tone-up' },
        correctif:    { label: t('Correctif'),    cls: 'wn-tone-fix' },
    };
    const PUBLICS = {
        createurs:       t('Création d\'assistants'),
        administrateurs: t('Administration'),
    };
    // Sujets de commit : l'ancien vocabulaire, conservé pour le détail staff.
    const TYPES = { feat: t('Nouveauté'), fix: t('Correctif'), refactor: 'Refactor', perf: 'Perf', docs: 'Doc' };

    const CLE_VUE = 'whatsNewSeen:' + (window.WHATS_NEW_USER_ID || 0);
    const LANGUE  = (window.I18N && window.I18N.lang) || 'fr';

    const escapeHtml = s => String(s == null ? '' : s)
        .replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /**
     * Le corps est rédigé en Markdown léger (« **Réglages → Comptes
     * connectés** ») : les astérisques s'affichaient en toutes lettres. Gras,
     * italique et code, sur un texte DÉJÀ échappé — aucune balise ne peut
     * venir du fichier.
     */
    function inline(s) {
        return escapeHtml(s)
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\w)/g, '$1<em>$2</em>');
    }

    function sansAccents(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function parseIso(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null;
    }

    /** `2026-08-21` → `21 août 2026` (ou `August 21, 2026`), dans la langue de l'écran. */
    function dateLisible(iso) {
        const d = parseIso(iso);
        if (!d) return '';
        try {
            return d.toLocaleDateString(LANGUE, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
        } catch (_e) {
            return iso;   // locale refusée par le moteur : la date brute reste lisible
        }
    }

    /** `2026-08-21` → `Août 2026`, l'intitulé d'un groupe. */
    function moisLisible(iso) {
        const d = parseIso(iso);
        if (!d) return t('Plus anciennes');
        let s;
        try {
            s = d.toLocaleDateString(LANGUE, { month: 'long', year: 'numeric', timeZone: 'UTC' });
        } catch (_e) {
            s = iso.slice(0, 7);
        }
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    // ---- Pastille « non lu » --------------------------------------------

    function lu() {
        try { return localStorage.getItem(CLE_VUE) || ''; } catch (e) { return ''; }
    }
    function marquerLu(id) {
        if (!id) return;
        try { localStorage.setItem(CLE_VUE, id); } catch (e) { /* navigation privée */ }
        btn.classList.remove('has-news');
    }
    // La pastille tient à un identifiant, jamais à un compteur : republier la
    // liste sans nouveauté (une reformulation) ne doit rien rallumer.
    if (window.WHATS_NEW_LAST_ID && lu() !== window.WHATS_NEW_LAST_ID) {
        btn.classList.add('has-news');
        btn.title = t('Nouveautés à découvrir');
    }
    // Lu à l'ouverture de la PAGE : la première ouverture de la modale marque
    // tout lu, mais « Nouveau pour vous » doit rester visible si on la rouvre.
    const vuAvant = lu();

    // ---- État -------------------------------------------------------------

    const state = { recentes: [], anciennes: [], nonLus: new Set(), genre: '', q: '', archive: false };

    function calculerNonLus() {
        // Première visite (rien de retenu) : aucune mise en avant — tout serait
        // « nouveau », donc rien ne le serait.
        if (!vuAvant) return;
        for (const n of state.recentes) {
            if (n.id === vuAvant) return;
            state.nonLus.add(n.id);
        }
    }

    function correspond(n, sansGenre) {
        if (!sansGenre && state.genre && n.genre !== state.genre) return false;
        if (!state.q) return true;
        const mots = sansAccents(state.q).split(/\s+/).filter(Boolean);
        const texte = sansAccents(n.titre + ' ' + n.corps);
        return mots.every(m => texte.includes(m));
    }

    // ---- Rendu ------------------------------------------------------------

    function carte(n, i) {
        const g     = GENRES[n.genre] || GENRES.nouveaute;
        const pour  = PUBLICS[n.pour] || '';
        const date  = dateLisible(n.date);
        const neuf  = state.nonLus.has(n.id);

        return `<article class="wn-item ${g.cls}${neuf ? ' is-unseen' : ''}" style="--i:${Math.min(i, 12)}">
            <span class="wn-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${ICONS[n.genre] || ICONS.nouveaute}</svg></span>
            <div class="wn-item-main">
                <div class="wn-item-head">
                    <span class="wn-genre">${escapeHtml(g.label)}</span>
                    ${neuf ? `<span class="wn-unseen">${escapeHtml(t('Nouveau pour vous'))}</span>` : ''}
                    ${pour ? `<span class="wn-pour">${escapeHtml(pour)}</span>` : ''}
                </div>
                <h4 class="wn-item-title">${escapeHtml(n.titre)}</h4>
                <p class="wn-item-body">${inline(n.corps)}</p>
                <div class="wn-meta">
                    ${date ? `<time datetime="${escapeHtml(n.date)}">${escapeHtml(date)}</time>` : ''}
                    ${n.version ? `<span class="wn-meta-version">v${escapeHtml(n.version)}</span>` : ''}
                </div>
            </div>
        </article>`;
    }

    /** Cartes groupées par mois, dans l'ordre reçu (le plus récent en tête). */
    function groupes(items) {
        let html = '', mois = null, i = 0;
        for (const n of items) {
            const m = String(n.date || '').slice(0, 7);
            if (m !== mois) {
                if (mois !== null) html += '</div></section>';
                html += `<section class="wn-month"><h5 class="wn-month-title">${escapeHtml(moisLisible(n.date))}</h5><div class="wn-month-items">`;
                mois = m;
            }
            html += carte(n, i++);
        }
        if (mois !== null) html += '</div></section>';
        return html;
    }

    function majCompteurs(base) {
        if (!filters) return;
        // Les compteurs suivent la recherche, pas le filtre : ils disent ce que
        // donnerait chaque onglet.
        const vus = base.filter(x => correspond(x, true));
        filters.querySelectorAll('.wn-filter').forEach(b => {
            const g = b.dataset.genre || '';
            const n = vus.filter(x => !g || x.genre === g).length;
            const c = b.querySelector('.wn-count');
            if (c) c.textContent = String(n);
        });
    }

    function render() {
        // La recherche parcourt TOUT l'historique : chercher « Teams » et ne
        // rien trouver parce que l'annonce date de trois mois serait absurde.
        const avecArchive = state.archive || state.q !== '';
        const base = avecArchive ? state.recentes.concat(state.anciennes) : state.recentes;
        majCompteurs(base);

        const items = base.filter(n => correspond(n));
        let html = items.length
            ? groupes(items)
            : `<div class="wn-none"><strong>${escapeHtml(t('Aucune nouveauté ne correspond.'))}</strong><span>${escapeHtml(t('Essayez un autre mot ou retirez le filtre.'))}</span></div>`;

        if (!avecArchive && state.anciennes.length) {
            const n = state.anciennes.length;
            html += `<button type="button" class="wn-more" data-wn-more>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
                ${escapeHtml(tn(n, 'Voir {n} nouveauté plus ancienne', 'Voir les {n} nouveautés plus anciennes'))}
            </button>`;
        } else if (state.archive && !state.q) {
            html += `<p class="wn-end">${escapeHtml(t('Vous êtes arrivé au début de l\'histoire.'))}</p>`;
        }
        list.innerHTML = html;
    }

    function ligneCommit(c) {
        return `<div class="wn-commit">
            <div class="wn-commit-head">
                <span class="wn-commit-type">${escapeHtml(TYPES[c.type] || c.type || '')}</span>
                ${c.version ? `<span class="wn-commit-version">v${escapeHtml(c.version)}</span>` : ''}
                ${c.scope ? `<span class="wn-commit-scope">${escapeHtml(c.scope)}</span>` : ''}
                <span class="wn-commit-date">${escapeHtml(c.date || '')}</span>
            </div>
            <div class="wn-commit-subject">${escapeHtml(c.subject)}</div>
        </div>`;
    }

    let charge = false;
    async function load() {
        if (charge) return;
        try {
            const r = await fetch('/changelog.json', { cache: 'no-cache' });
            if (!r.ok) throw new Error('changelog absent');
            const data = await r.json();

            // Format d'avant les nouveautés rédigées : un tableau de commits.
            // Un navigateur qui garde l'ancien fichier en cache ne doit pas
            // afficher « indisponible » alors que le serveur a la liste.
            state.recentes  = Array.isArray(data) ? [] : (data.nouveautes || []);
            state.anciennes = Array.isArray(data) ? [] : (data.anciennes || []);
            const commits   = Array.isArray(data) ? data : (data.commits || []);
            calculerNonLus();

            if (state.recentes.length) {
                render();
            } else {
                list.innerHTML = `<p class="wn-empty">${escapeHtml(t('Aucune nouveauté à afficher pour le moment.'))}</p>`;
            }

            if (tech && techList && window.IS_STAFF && commits.length) {
                techList.innerHTML = commits.map(ligneCommit).join('');
                tech.hidden = false;
            }
            charge = true;
        } catch (e) {
            list.innerHTML = `<p class="wn-empty">${escapeHtml(t('Liste des nouveautés indisponible.'))}</p>`;
        }
    }

    // ---- Interactions -----------------------------------------------------

    filters?.addEventListener('click', e => {
        const b = e.target.closest('.wn-filter');
        if (!b) return;
        state.genre = b.dataset.genre || '';
        filters.querySelectorAll('.wn-filter').forEach(x => {
            const on = x === b;
            x.classList.toggle('is-active', on);
            x.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (charge) { render(); list.scrollTop = 0; }
    });

    let delai = null;
    search?.addEventListener('input', () => {
        clearTimeout(delai);
        delai = setTimeout(() => {
            state.q = search.value.trim();
            if (charge) { render(); list.scrollTop = 0; }
        }, 120);
    });

    list.addEventListener('click', e => {
        if (!e.target.closest('[data-wn-more]')) return;
        const haut = list.scrollHeight;
        state.archive = true;
        render();
        // On reste où l'on était : l'historique s'ajoute SOUS la liste.
        list.scrollTo({ top: Math.max(0, haut - list.clientHeight * 0.4), behavior: 'smooth' });
    });

    // ---- Ouverture / fermeture -------------------------------------------

    let retourFocus = null;
    const close = () => {
        modal.classList.remove('is-open');
        modal.style.display = 'none';
        if (retourFocus && typeof retourFocus.focus === 'function') retourFocus.focus();
    };

    btn.addEventListener('click', () => {
        retourFocus = document.activeElement;
        modal.style.display = 'flex';
        requestAnimationFrame(() => modal.classList.add('is-open'));
        // Marqué lu à l'OUVERTURE, pas à la fermeture : le geste d'ouvrir est
        // la lecture. Attendre la fermeture rallumerait la pastille pour qui
        // ferme par Échap ou change d'onglet.
        marquerLu(window.WHATS_NEW_LAST_ID);
        load();
        // Au doigt, pas de focus dans la recherche : le clavier surgirait.
        const fin = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        (fin && search ? search : closeX || closeBtn)?.focus({ preventScroll: true });
    });
    closeBtn?.addEventListener('click', close);
    closeX?.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape' || modal.style.display === 'none') return;
        // Échap dans une recherche remplie la vide d'abord.
        if (e.target === search && search.value) {
            search.value = '';
            search.dispatchEvent(new Event('input'));
            return;
        }
        close();
    });
})();
