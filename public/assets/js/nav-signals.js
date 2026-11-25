// Info-bulles des PASTILLES — points à régler, nouveautés, connexions à
// reconnecter, budget : le texte qui accompagne une pastille ne s'affiche
// jamais en permanence, il vient en bulle.
//
// Deux formes, une seule bulle :
//   - un élément porteur `data-nav-signal` (entrée ou rubrique du menu
//     d'administration) : son texte est l'élément désigné par
//     `aria-describedby` (`.nav-signal-hint`, rendu par admin-nav.php depuis
//     AdminAttention), la bulle se pose sur SA pastille ;
//   - une pastille autonome `data-tip` (élément concerné sur sa page —
//     AdminAttention::itemDot —, nouveauté, connexion à reconnecter, tuile
//     d'espace, « Votre contexte » à remplir…) : son texte est dans
//     l'attribut, `data-tip-tone` dit la couleur du liseré (alert, warn,
//     news, info).
//
// Souris : au survol (après un court délai). Clavier : au focus visible.
// Doigt : toucher la PASTILLE ouvre (ou referme) sa bulle sans suivre le
// lien qui la porte ; toucher ailleurs la referme.
//
// La bulle est UNE seule, posée sur <body> en `position: fixed` : dans la
// barre latérale, qui défile (`overflow-y: auto`), une bulle absolue serait
// rognée — en haut du menu comme sur son bord droit. Elle se pose AU-DESSUS
// de la pastille, flèche pointée sur elle ; faute de place, dessous.
//
// Arrivée sur une ancre `#att-…` (« Régler → » du tableau de bord) : la
// pastille de l'élément est mise en vue et sa bulle ouverte.
//
// Aucun texte ici : tout vient de la page, déjà traduit.
(function () {
    'use strict';
    if (window.__navSignalsInit) return;
    window.__navSignalsInit = true;

    const survol = window.matchMedia ? window.matchMedia('(hover: hover)') : null;
    const DELAI_MS = 120; // traverser le menu ne fait pas clignoter une bulle par ligne
    const MARGE = 8;      // distance minimale aux bords de la fenêtre
    const ECART = 10;     // entre la pastille et la bulle (la flèche y loge)
    // Ce qui se touche pour lire une bulle.
    const PASTILLES = '[data-tip], .nav-signal-dot, .nav-attention-dot, .nav-budget-dot, .news-dot, .reconnect-dot, .info-dot';
    const TONS = ['alert', 'warn', 'news', 'info'];

    let bulle = null;
    let hote = null;
    let minuterie = null;
    let dernierPointeur = 'mouse';
    // Jusqu'à quand un défilement ne referme pas la bulle (mise en vue d'une
    // pastille à l'arrivée : le navigateur défile encore un instant).
    let calmeJusqua = 0;

    function laBulle() {
        if (bulle) return bulle;
        bulle = document.createElement('div');
        bulle.className = 'nav-hint-bubble';
        bulle.setAttribute('role', 'tooltip');
        bulle.hidden = true;
        document.body.appendChild(bulle);
        return bulle;
    }

    // Texte et ton d'un élément porteur ou d'une pastille autonome.
    function contenuDe(el) {
        if (el.hasAttribute('data-tip')) {
            return { texte: (el.getAttribute('data-tip') || '').trim(), ton: el.getAttribute('data-tip-tone') || 'warn' };
        }
        const id = el.getAttribute('aria-describedby');
        const hint = id ? document.getElementById(id) : null;
        if (!hint) return { texte: '', ton: 'warn' };
        return { texte: hint.textContent.trim(), ton: hint.classList.contains('is-alert') ? 'alert' : 'warn' };
    }

    // Où poser la bulle : la pastille d'un élément porteur, sinon l'élément.
    function ancreDe(el) {
        if (el.hasAttribute('data-tip')) return el;
        return el.querySelector('.nav-signal-dot, .nav-attention-dot, .nav-budget-dot') || el;
    }

    function placer(ancre) {
        const b = laBulle();
        // Pixels d'ÉCRAN jusqu'à l'écriture, divisés alors par la taille
        // d'affichage (zoom de <html>, cf. head-meta.php).
        const z = window.miaUiZoom ? window.miaUiZoom() : 1;
        const r = ancre.getBoundingClientRect();
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        const centre = r.left + r.width / 2;
        const largeur = b.offsetWidth * z;
        const hauteur = b.offsetHeight * z;

        let gauche = Math.round(centre - largeur / 2);
        gauche = Math.max(MARGE, Math.min(gauche, vw - largeur - MARGE));
        let haut = Math.round(r.top - hauteur - ECART);
        let dessous = false;
        if (haut < MARGE) {
            haut = Math.round(r.bottom + ECART);
            dessous = true;
        }
        haut = Math.max(MARGE, Math.min(haut, vh - hauteur - MARGE));

        b.style.left = gauche / z + 'px';
        b.style.top = haut / z + 'px';
        // La flèche reste pointée sur la pastille même quand la bulle est
        // décalée pour tenir dans la fenêtre.
        b.style.setProperty('--nav-hint-arrow', Math.round((centre - gauche) / z) + 'px');
        b.classList.toggle('is-below', dessous);
    }

    function montrer(el) {
        const { texte, ton } = contenuDe(el);
        if (texte === '') return;
        const b = laBulle();
        b.textContent = texte;
        TONS.forEach(t => b.classList.toggle('is-' + t, t === ton && t !== 'warn'));
        b.hidden = false;
        hote = el;
        placer(ancreDe(el));
        b.classList.add('is-visible');
    }

    function cacher() {
        clearTimeout(minuterie);
        minuterie = null;
        hote = null;
        if (!bulle) return;
        bulle.classList.remove('is-visible');
        bulle.hidden = true;
    }

    // L'élément qui porte une bulle sous la cible : la pastille autonome
    // d'abord (une nouveauté posée dans une entrée signalée a SA bulle), puis
    // l'élément porteur.
    function hoteDe(cible) {
        if (!cible || !cible.closest) return null;
        return cible.closest('[data-tip]') || cible.closest('[data-nav-signal]');
    }

    document.addEventListener('pointerdown', e => {
        dernierPointeur = e.pointerType || 'mouse';
    }, true);

    // Doigt : toucher une pastille lit sa bulle au lieu de suivre le lien (ou
    // de déplier la rubrique) qui la porte. Phase de capture : rien d'autre
    // ne voit ce toucher.
    document.addEventListener('click', e => {
        if (dernierPointeur !== 'touch' && dernierPointeur !== 'pen') return;
        const pastille = e.target && e.target.closest ? e.target.closest(PASTILLES) : null;
        if (!pastille) return;
        const el = pastille.hasAttribute('data-tip') ? pastille : pastille.closest('[data-nav-signal]');
        if (!el || contenuDe(el).texte === '') return;
        e.preventDefault();
        e.stopPropagation();
        if (hote === el) {
            cacher();
            return;
        }
        montrer(el);
    }, true);

    document.addEventListener('mouseover', e => {
        if (survol && !survol.matches) return;
        if (dernierPointeur === 'touch') return;
        const el = hoteDe(e.target);
        if (el === hote) return;
        clearTimeout(minuterie);
        if (!el) {
            cacher();
            return;
        }
        minuterie = setTimeout(() => montrer(el), DELAI_MS);
    });

    // Clavier : la bulle suit le focus VISIBLE seulement (un clic de souris
    // donne aussi le focus, la bulle du survol suffit alors).
    document.addEventListener('focusin', e => {
        const el = hoteDe(e.target);
        if (!el) return;
        let clavier = true;
        try { clavier = e.target.matches(':focus-visible'); } catch (err) { /* moteur ancien : on montre */ }
        if (clavier) montrer(el);
    });
    document.addEventListener('focusout', e => {
        if (hoteDe(e.target) === hote) cacher();
    });

    // La souris quitte la fenêtre : aucun `mouseover` ne viendrait la cacher.
    document.addEventListener('mouseout', e => {
        if (!e.relatedTarget && dernierPointeur !== 'touch') cacher();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && hote) cacher();
    });
    // Déplier un groupe déplace ce qui suit : la bulle repartirait de travers.
    document.addEventListener('click', cacher);
    // Un défilement referme une bulle OUVERTE (elle resterait en l'air) ; une
    // bulle encore en attente s'ouvrira à la bonne place, mesurée à ce moment.
    window.addEventListener('scroll', () => {
        if (hote && Date.now() > calmeJusqua) cacher();
    }, true);
    window.addEventListener('resize', cacher);

    // Arrivée sur la pastille d'un élément (« Régler → » du tableau de bord) :
    // mise en vue, repère qui pulse, bulle ouverte.
    // Après le défilement natif vers l'ancre (fin du chargement) : sinon il
    // remettrait la pastille tout en haut, sous la barre, et refermerait la bulle.
    function surAncre() {
        const h = location.hash || '';
        if (!/^#att-[a-z0-9-]+$/.test(h)) return;
        const el = document.getElementById(h.slice(1));
        if (!el || !el.hasAttribute('data-tip')) return;
        setTimeout(() => {
            calmeJusqua = Date.now() + 600;
            try { el.scrollIntoView({ block: 'center' }); } catch (err) { el.scrollIntoView(); }
            el.classList.add('is-spotlit');
            setTimeout(() => el.classList.remove('is-spotlit'), 2400);
            setTimeout(() => montrer(el), 200);
        }, 60);
    }
    if (document.readyState === 'complete') surAncre();
    else window.addEventListener('load', surAncre);
    window.addEventListener('hashchange', surAncre);
})();
