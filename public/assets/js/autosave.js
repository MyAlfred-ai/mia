/**
 * Enregistrement automatique des formulaires de configuration — SOURCE UNIQUE.
 *
 * Principe : plus de bouton « Enregistrer ». Un formulaire marqué
 * `data-autosave` poste TOUT son contenu sur sa propre action dès que
 * l'utilisateur modifie un champ, et une pastille annonce l'état réel de
 * l'enregistrement.
 *
 * Pourquoi poster le formulaire ENTIER et non le seul champ modifié : les
 * handlers PHP lisent l'absence d'une case à cocher comme « décoché »
 * (`(int)($_POST['is_active'] ?? 0)`). Un envoi partiel éteindrait donc tout ce
 * qui n'a pas été renvoyé. Poster l'intégralité, c'est rejouer exactement ce
 * que faisait le bouton submit — aucun handler n'a eu à changer de sémantique.
 *
 * Attributs reconnus sur le `<form>` :
 *   data-autosave              active le module ; la valeur « off » le suspend
 *                              (formulaires à double mode création/édition :
 *                              on ne crée jamais un objet à la frappe). Suspendu,
 *                              le formulaire se soumet normalement par son
 *                              bouton « Créer », validation native comprise
 *   data-autosave-label        nom de l'objet, affiché dans la pastille
 *   data-autosave-reload       « close » : la page se recharge à la fermeture
 *                              de la modale si au moins un enregistrement a eu
 *                              lieu (la liste derrière serait sinon périmée)
 *
 * Attributs reconnus sur les champs :
 *   data-autosave-skip         champ ignoré (filtres, recherches locales) —
 *                              ni écouté, ni envoyé
 *   data-autosave-silent       champ ENVOYÉ mais dont la modification ne
 *                              déclenche rien : sélecteur de cible (« quel
 *                              fournisseur je configure »), pas une valeur
 *   data-autosave-reset        champ vidé après un enregistrement réussi
 *                              (fichiers déjà téléversés, drapeaux à usage
 *                              unique comme « supprimer le logo »)
 *   data-autosave-now          enregistre sans attendre le débounce
 *
 * Évènements émis sur le `<form>` :
 *   mia:autosave-before        ANNULABLE — dernière chance de remplir un champ
 *                              calculé ou de refuser l'envoi (écrans qui
 *                              sérialisent leur état dans un champ caché)
 *   mia:autosaved              enregistrement confirmé par le serveur
 *   mia:autosave-failed        échec, la modification reste en attente
 *
 * API publique (`window.MiaAutosave`) : `attach`, `detach`, `flush`, `save`,
 * `scan`, `isDirty`, `hasSaved`, `savedCount`, `paint`.
 */
(function () {
    'use strict';

    // Débounce court pour un choix discret (un clic = une intention arrêtée),
    // long pour la frappe (sinon un champ texte partirait à chaque lettre).
    var DELAY_CHOICE = 250;
    var DELAY_TYPING = 900;
    var SAVED_VISIBLE_MS = 2200;

    var forms = new Map();
    var pill = null;
    var pillTimer = null;
    var pillState = '';

    /* ---------------------------------------------------------------
       Pastille d'état — une seule pour toute la page.
       Plusieurs pastilles (une par formulaire) se contrediraient dès
       qu'une modale se superpose à la page qui l'a ouverte.
       --------------------------------------------------------------- */

    var ICONS = {
        saving: '<span class="autosave-spinner" aria-hidden="true"></span>',
        saved: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><line x1="12" y1="7.5" x2="12" y2="13"/><line x1="12" y1="16.5" x2="12" y2="16.5"/></svg>',
        invalid: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17"/></svg>'
    };

    function ensurePill() {
        if (pill) { return pill; }
        pill = document.createElement('div');
        pill.className = 'autosave-pill';
        pill.id = 'autosavePill';
        // `status` + `aria-live=polite` : annoncé sans voler le focus, donc
        // sans interrompre la saisie en cours.
        pill.setAttribute('role', 'status');
        pill.setAttribute('aria-live', 'polite');
        pill.hidden = true;
        document.body.appendChild(pill);
        return pill;
    }

    function showPill(state, text, retry) {
        var el = ensurePill();
        clearTimeout(pillTimer);
        pillState = state;

        var html = '<span class="autosave-pill-icon">' + (ICONS[state] || '') + '</span>'
                 + '<span class="autosave-pill-text"></span>';
        el.innerHTML = html;
        el.querySelector('.autosave-pill-text').textContent = text;

        if (retry) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'autosave-pill-retry';
            btn.textContent = t('Réessayer');
            btn.addEventListener('click', retry);
            el.appendChild(btn);
        }

        el.className = 'autosave-pill is-' + state;
        el.hidden = false;
        // Reflow forcé : sans lui, l'ajout simultané de `hidden = false` et de
        // la classe d'entrée ne produit aucune transition.
        void el.offsetWidth;
        el.classList.add('is-visible');

        if (state === 'saved') {
            pillTimer = setTimeout(hidePill, SAVED_VISIBLE_MS);
        }
    }

    function hidePill() {
        if (!pill) { return; }
        pill.classList.remove('is-visible');
        pillTimer = setTimeout(function () {
            // Une erreur affichée entre-temps ne doit pas être masquée par la
            // fin de temporisation d'un succès précédent.
            if (!pill.classList.contains('is-visible')) { pill.hidden = true; }
        }, 200);
    }

    /* Miroirs facultatifs : `[data-autosave-status]` dans un pied de modale. */
    function paintMirrors(state, text) {
        document.querySelectorAll('[data-autosave-status]').forEach(function (el) {
            el.textContent = state === 'idle' ? '' : text;
            el.className = 'autosave-inline is-' + state;
        });
    }

    function paint(state, text, retry) {
        if (state === 'idle') {
            hidePill();
        } else {
            showPill(state, text, retry);
        }
        paintMirrors(state, text);
    }

    /* ---------------------------------------------------------------
       Collecte des champs
       --------------------------------------------------------------- */

    function isSkipped(field) {
        if (!field.name) { return true; }
        if (field.disabled) { return true; }
        if (field.closest('[data-autosave-skip]')) { return true; }
        if (field.type === 'submit' || field.type === 'button' || field.type === 'reset') { return true; }
        return false;
    }

    /** Champ envoyé, mais dont la modification ne déclenche pas d'envoi. */
    function isSilent(field) {
        return !!field.closest('[data-autosave-silent]');
    }

    function buildBody(form) {
        var body = new FormData();
        var els = form.elements;

        for (var i = 0; i < els.length; i++) {
            var f = els[i];
            if (isSkipped(f)) { continue; }

            if (f.type === 'file') {
                // Un input fichier vide produit une entrée `File` de taille
                // nulle que PHP compte comme UPLOAD_ERR_NO_FILE : inoffensif,
                // mais le renvoyer à chaque frappe re-téléverserait l'image.
                for (var k = 0; k < f.files.length; k++) {
                    body.append(f.name, f.files[k], f.files[k].name);
                }
                continue;
            }
            if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) { continue; }
            if (f.type === 'select-multiple') {
                for (var m = 0; m < f.options.length; m++) {
                    if (f.options[m].selected) { body.append(f.name, f.options[m].value); }
                }
                continue;
            }
            body.append(f.name, f.value);
        }
        return body;
    }

    /** Premier champ invalide au sens de la validation native, ou null. */
    function firstInvalid(form) {
        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var f = els[i];
            if (isSkipped(f) || f.type === 'file') { continue; }
            if (typeof f.checkValidity === 'function' && !f.checkValidity()) { return f; }
        }
        return null;
    }

    function fieldLabel(field) {
        var wrap = field.closest('.form-group, .pf-field, .modal-field, label');
        var lbl = wrap ? wrap.querySelector('label') : null;
        var txt = lbl ? lbl.textContent : (field.getAttribute('aria-label') || field.name || '');
        return txt.replace(/\s+/g, ' ').replace(/\s*[:*]\s*$/, '').trim();
    }

    /* ---------------------------------------------------------------
       Cycle d'enregistrement
       --------------------------------------------------------------- */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /** Reprend un jeton frais auprès de `GET /auth/csrf` (même origine). */
    function refreshCsrfToken() {
        return fetch('/auth/csrf', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.token) { return false; }
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) { meta.setAttribute('content', d.token); }
                // Les champs cachés des formulaires portent aussi le jeton : un
                // POST sans JavaScript juste après échouerait sinon.
                document.querySelectorAll('input[name="csrf_token"]').forEach(function (i) {
                    i.value = d.token;
                });
                return true;
            })
            .catch(function () { return false; });
    }

    function isActive(form) {
        return form.dataset.autosave !== 'off';
    }

    function schedule(form, delay) {
        var st = forms.get(form);
        if (!st || !isActive(form)) { return; }
        st.dirty = true;
        clearTimeout(st.timer);
        st.timer = setTimeout(function () { send(form); }, delay);
    }

    function send(form, opts) {
        var st = forms.get(form);
        if (!st || !isActive(form)) { return; }
        clearTimeout(st.timer);
        st.timer = null;

        // Dernière chance pour un écran qui sérialise son état dans un champ
        // caché (la configuration des crédits) : remplir, ou refuser l'envoi.
        var before = new CustomEvent('mia:autosave-before', { bubbles: true, cancelable: true });
        if (!form.dispatchEvent(before)) {
            st.dirty = true;
            return;
        }

        if (st.inflight) {
            // Une seule requête en vol : la suivante repart à son retour, avec
            // l'état le plus récent du formulaire. Deux POST concurrents sur la
            // même ressource se rendraient l'un l'autre imprévisibles.
            st.again = true;
            return;
        }

        var bad = firstInvalid(form);
        if (bad) {
            st.dirty = true;
            paint('invalid', fieldLabel(bad) + t(' : valeur incomplète, rien n\'a été enregistré.'));
            return;
        }

        var action = form.getAttribute('action') || window.location.pathname;
        var body = buildBody(form);
        var fileFields = Array.prototype.filter.call(form.elements, function (f) {
            return f.type === 'file' && !isSkipped(f) && f.files.length > 0;
        });

        st.inflight = true;
        st.dirty = false;
        paint('saving', 'Enregistrement…');

        fetch(action, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body,
            // Uniquement au départ de la page, et jamais avec un fichier joint :
            // `keepalive` plafonne la charge utile à 64 Ko et rejetterait
            // l'envoi. Il permet à la requête de survivre à la navigation, donc
            // d'éviter l'avertissement « quitter le site ? » dans le cas
            // courant d'une modification à peine tapée.
            keepalive: !!(opts && opts.keepalive)
        })
            .then(function (res) {
                return res.json().catch(function () {
                    // Une redirection 302 suivie automatiquement rend du HTML :
                    // le formulaire a bien été traité, mais la route ne connaît
                    // pas encore la branche JSON. Le dire plutôt que de fêter un
                    // succès qu'on n'a pas vérifié.
                    throw new Error(t('Réponse inattendue du serveur.'));
                });
            })
            .then(function (data) {
                if (data && data.code === 'csrf' && !(opts && opts.csrfRetried)) {
                    // Page d'administration restée ouverte le temps qu'une
                    // session expire ou qu'un redéploiement recycle le
                    // conteneur : le jeton en mémoire est périmé. On en prend un
                    // frais et on rejoue UNE fois — c'est exactement le cas
                    // qu'une page qui s'enregistre toute seule doit encaisser.
                    st.inflight = false;
                    st.dirty = true;
                    return refreshCsrfToken().then(function (ok) {
                        if (!ok) { throw new Error(t('Session expirée — rechargez la page.')); }
                        send(form, { keepalive: opts && opts.keepalive, csrfRetried: true });
                    });
                }
                if (!data || data.ok !== true) {
                    throw new Error((data && data.error) || t('Enregistrement refusé.'));
                }

                st.saved += 1;
                // Champs à usage unique. Sans ça, un fichier déjà téléversé
                // repartirait à chaque frappe, un secret déjà enregistré
                // resterait dans le DOM et une case « supprimer la clé »
                // rejouerait sa suppression à chaque enregistrement suivant.
                fileFields.forEach(function (f) { f.value = ''; });
                form.querySelectorAll('[data-autosave-reset]').forEach(function (f) {
                    if (f.type === 'checkbox' || f.type === 'radio') {
                        f.checked = false;
                    } else {
                        f.value = '';
                    }
                });

                var chain = st.dirty || st.again;
                // `inflight` remis à faux AVANT l'évènement : `settle()` teste
                // cet indicateur pour savoir si le formulaire est au repos.
                // L'ordre inverse le laisserait attendre un évènement de plus,
                // qui ne viendrait jamais sur le dernier tour.
                st.inflight = false;
                st.again = false;

                form.dispatchEvent(new CustomEvent('mia:autosaved', {
                    bubbles: true,
                    detail: { data: data }
                }));

                if (chain) {
                    // Modifié pendant l'envoi : on repart, sans annoncer un
                    // « Enregistré » qui porterait sur un état déjà dépassé.
                    send(form);
                    return;
                }
                if (data.warning) {
                    // Enregistré, mais une partie du réglage demande attention
                    // (identifiants de synchronisation refusés, par exemple).
                    // Le taire ferait passer un demi-succès pour un succès.
                    paint('invalid', data.warning);
                } else {
                    paint('saved', data.message || t('Enregistré'));
                }
            })
            .catch(function (err) {
                st.inflight = false;
                st.again = false;
                st.dirty = true;
                form.dispatchEvent(new CustomEvent('mia:autosave-failed', {
                    bubbles: true,
                    detail: { error: err }
                }));
                paint('error', err.message || t('Échec de l\'enregistrement.'), function () { send(form); });
            });
    }

    /* ---------------------------------------------------------------
       Branchement
       --------------------------------------------------------------- */

    /*
       Délégation au niveau `document`, et non sur chaque formulaire : un champ
       rattaché par l'attribut `form="…"` mais placé HORS du formulaire (la case
       « Actif » de la fiche assistant, les boutons des pieds de modale) fait
       remonter ses évènements dans l'arbre DOM, pas vers son formulaire. Un
       écouteur posé sur le `<form>` ne les verrait jamais. `field.form` donne
       en revanche toujours le bon formulaire.
     */
    function formOf(target) {
        if (!target || typeof target.closest !== 'function') { return null; }
        var form = target.form || target.closest('form');
        return form && forms.has(form) ? form : null;
    }

    function onDocInput(e) {
        var f = e.target;
        var form = formOf(f);
        if (!form || isSkipped(f) || isSilent(f)) { return; }
        schedule(form, f.hasAttribute('data-autosave-now') ? 0 : DELAY_TYPING);
    }

    function onDocChange(e) {
        var f = e.target;
        var form = formOf(f);
        if (!form || isSkipped(f) || isSilent(f)) { return; }
        // `change` d'un champ texte suit son `blur` : partir tout de suite
        // évite de laisser une modification en suspens quand l'utilisateur
        // quitte le champ pour fermer la modale.
        schedule(form, DELAY_CHOICE);
    }

    function onDocSubmit(e) {
        var form = e.target;
        if (!forms.has(form)) { return; }
        // Un autre écouteur a déjà refusé l'envoi (validation maison) : ne pas
        // le contredire en enregistrant quand même.
        if (e.defaultPrevented) { return; }
        if (!isActive(form)) {
            // Module suspendu (`data-autosave="off"`) : le formulaire redevient
            // un formulaire ordinaire — c'est le bouton « Créer » des modales à
            // double mode (bases de connaissances, groupes). L'intercepter ici
            // pour un `send()` qui refuse de partir rendait ce bouton muet.
            // Reste à rendre la validation native qu'`attach()` a coupée
            // (`novalidate`) : sans elle, un nom oublié partirait au serveur et
            // la modale se refermerait sur une erreur, saisie perdue.
            var sansValidation = !!(e.submitter && e.submitter.formNoValidate);
            if (!sansValidation && typeof form.reportValidity === 'function' && !form.reportValidity()) {
                e.preventDefault();
            }
            return;
        }
        // Entrée dans un champ texte, ou bouton submit resté en place :
        // enregistrer sans recharger la page.
        e.preventDefault();
        send(form);
    }

    document.addEventListener('input', onDocInput, true);
    document.addEventListener('change', onDocChange, true);
    // Bulle et non capture : les validations maison de la page doivent pouvoir
    // s'exécuter (et poser `preventDefault`) AVANT qu'on décide d'envoyer.
    document.addEventListener('submit', onDocSubmit);

    function attach(form) {
        if (!form || forms.has(form)) { return; }
        forms.set(form, { timer: null, inflight: false, dirty: false, again: false, saved: 0 });
        // La validation native est consultée par `firstInvalid()` ; la laisser
        // active afficherait en plus ses bulles à chaque envoi automatique.
        form.setAttribute('novalidate', 'novalidate');
    }

    function detach(form) {
        var st = forms.get(form);
        if (!st) { return; }
        clearTimeout(st.timer);
        forms.delete(form);
    }

    /** Envoie tout de suite ce qui est en attente (fermeture de modale, départ). */
    function flush(form, opts) {
        if (form) {
            var st = forms.get(form);
            if (st && (st.dirty || st.timer)) { send(form, opts); }
            return;
        }
        forms.forEach(function (st, f) {
            if (st.dirty || st.timer) { send(f, opts); }
        });
    }

    /** Un formulaire porte-t-il un fichier non encore téléversé ? */
    function hasPendingFiles(form) {
        return Array.prototype.some.call(form.elements, function (f) {
            return f.type === 'file' && !isSkipped(f) && f.files && f.files.length > 0;
        });
    }

    /**
     * Départ de la page : tout envoyer, et n'avertir que si quelque chose ne
     * PEUT PAS partir — un fichier en attente (trop lourd pour `keepalive`) ou
     * un champ invalide. Avertir dans tous les cas transformerait le moindre
     * mot tapé en boîte de dialogue « quitter le site ? », alors que la requête
     * part très bien toute seule.
     */
    function flushOnUnload() {
        var risky = false;
        forms.forEach(function (st, form) {
            if (!st.dirty && !st.timer && !st.inflight) { return; }
            if (!isActive(form)) { return; }
            if (hasPendingFiles(form) || firstInvalid(form)) {
                risky = true;
                flush(form);
                return;
            }
            if (st.inflight) { risky = true; return; }
            flush(form, { keepalive: true });
        });
        return risky;
    }

    function isDirty(form) {
        if (form) {
            var st = forms.get(form);
            return !!st && (st.dirty || st.inflight);
        }
        var any = false;
        forms.forEach(function (st) { if (st.dirty || st.inflight) { any = true; } });
        return any;
    }

    function savedCount(form) {
        var st = forms.get(form);
        return st ? st.saved : 0;
    }

    /**
     * Promesse résolue quand le formulaire n'a plus rien en attente : envoi
     * différé déclenché, requête en vol terminée, éventuelle relance en chaîne
     * incluse. Sert à décider APRÈS coup (recharger ou non), jamais à bloquer
     * la fermeture d'une modale.
     */
    function settle(form) {
        var st = forms.get(form);
        if (!st) { return Promise.resolve(); }
        if (st.dirty || st.timer) { send(form); }
        if (!st.inflight) { return Promise.resolve(); }

        return new Promise(function (resolve) {
            function done() {
                if (st.inflight) { return; }   // relance en chaîne : on attend encore
                form.removeEventListener('mia:autosaved', done);
                form.removeEventListener('mia:autosave-failed', done);
                resolve();
            }
            form.addEventListener('mia:autosaved', done);
            form.addEventListener('mia:autosave-failed', done);
        });
    }

    /**
     * Fermeture d'une modale à enregistrement automatique.
     *
     * La modale se ferme TOUT DE SUITE (`onClose`) : faire patienter
     * l'utilisateur derrière une requête réseau serait exactement le temps
     * d'attente que l'auto-save doit supprimer. La page ne se recharge
     * qu'ensuite, et seulement si quelque chose a réellement été enregistré —
     * la liste ou la carte derrière porte les valeurs modifiées.
     */
    function closeAndReload(form, onClose) {
        if (typeof onClose === 'function') { onClose(); }
        settle(form).then(function () {
            if (form.dataset.autosaveReload !== 'close') { return; }
            var reload = savedCount(form) > 0;
            // Désarmé une fois au repos : le prochain remplissage par script
            // ne doit pas passer pour une modification de l'utilisateur. Réservé
            // aux formulaires de modale (`reload="close"`) — un formulaire de
            // page resterait éteint pour de bon.
            form.dataset.autosave = 'off';
            var st = forms.get(form);
            if (st) { st.saved = 0; }
            if (reload) { window.location.reload(); }
        });
    }

    function scan(root) {
        (root || document).querySelectorAll('form[data-autosave]').forEach(attach);
    }

    /**
     * Demande explicite d'enregistrement, pour les écrans dont l'état ne vit
     * pas dans des champs nommés (tableau de la configuration des crédits) :
     * aucun évènement de champ ne partira, c'est à la page de le dire.
     */
    function save(form, delay) {
        if (!forms.has(form)) { return; }
        schedule(form, typeof delay === 'number' ? delay : DELAY_CHOICE);
    }

    window.MiaAutosave = {
        attach: attach,
        detach: detach,
        flush: flush,
        save: save,
        scan: scan,
        settle: settle,
        closeAndReload: closeAndReload,
        isDirty: isDirty,
        hasSaved: function (form) { return savedCount(form) > 0; },
        savedCount: savedCount,
        paint: paint
    };

    // Quitter la page avec une modification non partie la perdrait en silence.
    window.addEventListener('beforeunload', function (e) {
        if (!isDirty()) { return; }
        if (!flushOnUnload()) { return; }
        e.preventDefault();
        e.returnValue = '';
    });

    // Onglet masqué / application mise en arrière-plan : on ne parie pas sur un
    // retour. `visibilitychange` est le seul évènement fiable sur mobile.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { flush(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }
})();
