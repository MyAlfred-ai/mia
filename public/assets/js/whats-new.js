/**
 * Modale « Nouveautés » — le contenu du clic sur le numéro de version.
 *
 * Ce que l'utilisateur lit n'est PAS un journal de commits mais une liste de
 * fonctionnalités rédigées (docs/NOUVEAUTES.md → public/changelog.json). Le
 * fichier porte deux listes :
 *   - `nouveautes` : 20 entrées {titre, corps, genre, pour, date, version} ;
 *   - `commits`    : les sujets de commit, détail technique replié et réservé
 *                    aux comptes de l'éditeur (window.IS_STAFF).
 *
 * Ce module remplace deux blocs recopiés (chat.js et la page À propos) : le
 * même bouton #versionBtn ouvre la même modale partout.
 *
 * PASTILLE « NON LU » : l'identifiant de la nouveauté la plus récente est
 * injecté par le gabarit (window.WHATS_NEW_LAST_ID) — aucun appel réseau au
 * chargement de la page. Ce qui a été lu est retenu par navigateur, sous une
 * clé qui porte l'identifiant du compte : sur un poste partagé, le collègue
 * qui se connecte ensuite voit ses propres nouveautés comme non lues.
 */
(function () {
    'use strict';

    const btn      = document.getElementById('versionBtn');
    const modal    = document.getElementById('versionModal');
    const list     = document.getElementById('versionList');
    const closeBtn = document.getElementById('versionClose');
    const tech     = document.getElementById('versionTech');
    const techList = document.getElementById('versionTechList');
    if (!btn || !modal || !list) return;

    const GENRES = {
        nouveaute:    { label: t('Nouveauté'),    cls: 'wn-genre-new' },
        amelioration: { label: t('Amélioration'), cls: 'wn-genre-up' },
        correctif:    { label: 'Correctif',    cls: 'wn-genre-fix' },
    };
    const PUBLICS = {
        createurs:       t('Création d\'assistants'),
        administrateurs: 'Administration',
    };
    // Sujets de commit : l'ancien vocabulaire, conservé pour le détail staff.
    const TYPES = { feat: t('Nouveauté'), fix: 'Correctif', refactor: 'Refactor', perf: 'Perf', docs: 'Doc' };

    const CLE_VUE = 'whatsNewSeen:' + (window.WHATS_NEW_USER_ID || 0);

    const escapeHtml = s => String(s == null ? '' : s)
        .replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /**
     * `2026-08-21` → `21 août 2026` (ou `August 21, 2026`).
     *
     * toLocaleDateString est de retour, et la table de mois en dur a disparu :
     * elle n'existait que parce que la langue de l'interface était forcément le
     * français alors que celle du navigateur ne l'est pas toujours. On connaît
     * désormais la première — on la lui passe explicitement, ce qui donne aussi
     * le bon ORDRE des composants (« 21 août » contre « August 21 »), qu'une
     * table de mois ne pouvait pas rendre.
     */
    function dateLisible(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        if (!m) return '';
        const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        const langue = (window.I18N && window.I18N.lang) || 'fr';
        try {
            return d.toLocaleDateString(langue, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
        } catch (_e) {
            return iso;   // locale refusée par le moteur : la date brute reste lisible
        }
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

    // ---- Rendu ------------------------------------------------------------

    function carte(n) {
        const g    = GENRES[n.genre] || GENRES.nouveaute;
        const pour = PUBLICS[n.pour] || '';
        const meta = [dateLisible(n.date), n.version ? 'v' + n.version : '']
            .filter(Boolean).map(escapeHtml);

        return `<article class="wn-item">
            <div class="wn-item-head">
                <span class="wn-genre ${g.cls}">${g.label}</span>
                ${pour ? `<span class="wn-pour">${escapeHtml(pour)}</span>` : ''}
                <span class="wn-meta">${meta.join(' · ')}</span>
            </div>
            <h4 class="wn-item-title">${escapeHtml(n.titre)}</h4>
            <p class="wn-item-body">${escapeHtml(n.corps)}</p>
        </article>`;
    }

    function ligneCommit(c) {
        // Chapeau puis sujet : tout sur une ligne, la date poussée à droite,
        // s'enroulait de façon illisible dès que le sujet dépassait la largeur.
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
            const nouveautes = Array.isArray(data) ? [] : (data.nouveautes || []);
            const commits    = Array.isArray(data) ? data : (data.commits || []);

            list.innerHTML = nouveautes.length
                ? nouveautes.map(carte).join('')
                : '<p class="wn-empty">Aucune nouveauté à afficher pour le moment.</p>';

            if (tech && techList && window.IS_STAFF && commits.length) {
                techList.innerHTML = commits.map(ligneCommit).join('');
                tech.hidden = false;
            }
            charge = true;
        } catch (e) {
            list.innerHTML = '<p class="wn-empty">Liste des nouveautés indisponible.</p>';
        }
    }

    // ---- Ouverture / fermeture -------------------------------------------

    const close = () => { modal.style.display = 'none'; };

    btn.addEventListener('click', () => {
        modal.style.display = 'flex';
        // Marqué lu à l'OUVERTURE, pas à la fermeture : le geste d'ouvrir est
        // la lecture. Attendre la fermeture rallumerait la pastille pour qui
        // ferme par Échap ou change d'onglet.
        marquerLu(window.WHATS_NEW_LAST_ID);
        load();
    });
    closeBtn?.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.style.display !== 'none') close();
    });
})();
