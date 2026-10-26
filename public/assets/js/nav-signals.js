// Bulle d'explication des pastilles du menu d'administration.
//
// Chaque élément signalé porte `data-nav-signal` et `aria-describedby` vers
// son texte (`.nav-signal-hint`, rendu par admin-nav.php depuis
// AdminAttention). Au survol — ou au focus clavier — une bulle apparaît
// AU-DESSUS de la pastille, avec une flèche qui la désigne.
//
// La bulle est UNE seule, posée sur <body> en `position: fixed` : dans la
// barre latérale, qui défile (`overflow-y: auto`), une bulle absolue serait
// rognée — en haut du menu comme sur son bord droit. Faute de place au-dessus,
// elle passe dessous.
//
// Au doigt (`hover: none`), rien ici : le même texte s'affiche sous l'entrée
// (main.css), on ne compte pas sur un survol qui n'existe pas.
(function () {
    'use strict';
    if (window.__navSignalsInit) return;
    window.__navSignalsInit = true;

    const survol = window.matchMedia ? window.matchMedia('(hover: hover)') : null;
    const DELAI_MS = 120; // traverser le menu ne fait pas clignoter une bulle par ligne
    const MARGE = 8;      // distance minimale aux bords de la fenêtre
    const ECART = 10;     // entre la pastille et la bulle (la flèche y loge)

    let bulle = null;
    let hote = null;
    let minuterie = null;

    function laBulle() {
        if (bulle) return bulle;
        bulle = document.createElement('div');
        bulle.className = 'nav-hint-bubble';
        bulle.setAttribute('role', 'tooltip');
        bulle.hidden = true;
        document.body.appendChild(bulle);
        return bulle;
    }

    function texteDe(el) {
        const id = el.getAttribute('aria-describedby');
        const hint = id ? document.getElementById(id) : null;
        return hint ? hint.textContent.trim() : '';
    }

    // La pastille de l'élément (celle des points à régler, ou les pastilles
    // historiques : clé API, budget) ; à défaut l'élément lui-même.
    function pastilleDe(el) {
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
        const texte = texteDe(el);
        if (texte === '') return;
        const b = laBulle();
        const hint = document.getElementById(el.getAttribute('aria-describedby'));
        b.textContent = texte;
        b.classList.toggle('is-alert', !!hint && hint.classList.contains('is-alert'));
        b.hidden = false;
        hote = el;
        placer(pastilleDe(el));
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

    function hoteDe(cible) {
        return cible && cible.closest ? cible.closest('[data-nav-signal]') : null;
    }

    document.addEventListener('mouseover', e => {
        if (survol && !survol.matches) return;
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
        try { clavier = el.matches(':focus-visible'); } catch (err) { /* moteur ancien : on montre */ }
        if (clavier) montrer(el);
    });
    document.addEventListener('focusout', e => {
        if (hoteDe(e.target) === hote) cacher();
    });

    // La souris quitte la fenêtre : aucun `mouseover` ne viendrait la cacher.
    document.addEventListener('mouseout', e => {
        if (!e.relatedTarget) cacher();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && hote) cacher();
    });
    // Déplier un groupe déplace ce qui suit : la bulle repartirait de travers.
    document.addEventListener('click', cacher);
    window.addEventListener('scroll', cacher, true);
    window.addEventListener('resize', cacher);
})();
