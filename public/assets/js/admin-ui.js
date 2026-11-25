/*
 * Socle commun des pages d'administration et de réglages.
 *
 * 1. État d'enregistrement de la barre du haut (#adminSaveState) : montré
 *    seulement si la page porte un formulaire auto-enregistré ARMÉ (une modale
 *    au repos porte data-autosave="off" : elle ne compte pas), puis tenu à jour
 *    par les évènements d'autosave.js. Aucun texte ici : ils viennent du
 *    gabarit, traduits (data-idle, data-saving, data-saved, data-error).
 * 2. Tableaux « en cartes » au téléphone (`table.table-stack`) : chaque cellule
 *    reçoit le libellé de sa colonne (data-label, lu dans l'en-tête) si le
 *    gabarit ne l'a pas posé — la règle CSS l'affiche devant la valeur.
 */
(function () {
    'use strict';

    var SAVED_MS = 2600;

    function armedForms() {
        return document.querySelectorAll('form[data-autosave]:not([data-autosave="off"])');
    }

    function setupSaveState() {
        var el = document.getElementById('adminSaveState');
        if (!el) { return; }
        var text = el.querySelector('.admin-save-text');
        var timer = null;

        function show(state) {
            clearTimeout(timer);
            el.hidden = false;
            el.setAttribute('data-state', state);
            text.textContent = el.getAttribute('data-' + state) || '';
            if (state === 'saved') {
                timer = setTimeout(function () { show('idle'); }, SAVED_MS);
            }
        }

        if (armedForms().length > 0) { show('idle'); }

        document.addEventListener('mia:autosave-before', function (e) {
            if (!e.defaultPrevented) { show('saving'); }
        });
        document.addEventListener('mia:autosaved', function () { show('saved'); });
        document.addEventListener('mia:autosave-failed', function () { show('error'); });
    }

    function labelStackedTables() {
        document.querySelectorAll('.admin-content table.table-stack').forEach(function (table) {
            var heads = table.querySelectorAll('thead th');
            if (!heads.length) { return; }
            var labels = Array.prototype.map.call(heads, function (th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim();
            });
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                Array.prototype.forEach.call(tr.children, function (td, i) {
                    if (td.tagName !== 'TD' || td.hasAttribute('data-label')) { return; }
                    td.setAttribute('data-label', labels[i] || '');
                });
            });
        });
    }

    function init() {
        setupSaveState();
        labelStackedTables();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // Les tableaux remplis après coup (rendu JavaScript) rappellent ceci.
    window.MiaAdminUi = { labelStackedTables: labelStackedTables };
})();
