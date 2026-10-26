/* =========================================================
   Commandes vocales de la conversation — reconnaissance PURE
   =========================================================
   En conversation vocale, ce que l'utilisateur dit n'est pas toujours un
   message pour l'assistant : « envoie-le », « approuve », « stop ». Ce module
   dit, pour une transcription donnée, si c'est un ORDRE — et lequel :

     - cardCommand()  : un ordre pour la carte en attente sous la réponse
                        (approuver / annuler ; pour un email : envoyer, créer
                        le brouillon, améliorer, confirmer l'envoi…) ;
     - stopCommand()  : un ordre d'arrêt (« stop », « arrête », « tais-toi ») ;
     - interruption() : ce qu'une phrase entendue PENDANT la lecture doit
                        faire — seul un ordre d'arrêt y compte, et la voix de
                        MIA revenue par le micro n'en est pas un ;
     - endCommand()   : la fin de la conversation (« au revoir ») ;
     - looksLikeEcho(): la phrase entendue est-elle un morceau du texte lu ?

   AUCUN DOM, aucun réseau, aucun état : du texte en entrée, une décision en
   sortie. C'est ce qui le rend testable sous node (tests/Unit/
   VoiceCommandsTest.php) — et c'est le seul endroit où vit le vocabulaire.

   RÈGLE DE PRUDENCE, qui vaut pour tout le fichier : un ordre n'est reconnu
   que si la phrase ENTIÈRE est faite de mots attendus (« sac de mots » : au
   moins un mot qui porte l'ordre, et rien d'étranger autour). « Envoie-le »
   envoie ; « envoie-le à Paul plutôt » ou « ne l'envoie pas » ne sont PAS des
   ordres : ils repartent vers l'assistant, comme un message ordinaire. Une
   commande manquée coûte une phrase de plus ; une commande reconnue à tort
   enverrait un email. Le doute profite toujours au message.

   UN « OUI » NU N'APPROUVE RIEN. « Oui », « ok », « d'accord » sont les mots
   les plus dits dans une pièce — à un collègue, au téléphone, à soi-même.
   Approuver une écriture ou confirmer un envoi demande le MOT de l'action
   (« approuve », « confirme », « envoie ») ; un accord ou un refus nu rend
   `reask`, et MIA redit quoi dire. Seuls les renoncements sans conséquence
   (revenir à la relecture, refermer un panneau) acceptent un « non » seul.

   Les deux langues de l'interface sont reconnues en même temps : la
   transcription suit la langue de l'écran, mais personne ne change de
   vocabulaire en changeant de réglage.
   ========================================================= */
(function (root) {
    'use strict';

    // ---- Découpage -------------------------------------------------------
    // Un mot = lettres et chiffres ; l'apostrophe et le trait d'union
    // séparent (« l'envoyer » → l, envoyer ; « tais-toi » → tais, toi).
    // Chaque mot garde sa position dans le texte d'origine : un ordre suivi
    // d'autre chose (« stop, et pour demain ? ») rend le RESTE tel qu'il a
    // été dit, accents et majuscules compris.
    function normWord(word) {
        return word.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
            .replace(/œ/g, 'oe').replace(/æ/g, 'ae');
    }

    function rawWords(text) {
        const out = [];
        const re = /[\p{L}\p{N}]+/gu;
        let m;
        while ((m = re.exec(text)) !== null) {
            out.push({ n: normWord(m[0]), s: m.index, e: m.index + m[0].length });
        }
        return out;
    }

    // Locutions : plusieurs mots qui n'en font qu'un (« s'il te plaît »,
    // « vas-y », « c'est bon »). Jointes par « _ » AVANT toute comparaison,
    // la plus longue d'abord : « plus tard » n'est pas le comparatif « plus »,
    // « rendez-vous » n'est pas le verbe « rendez ».
    const PHRASES = [
        // Politesse et tournures de demande
        's il te plait', 's il vous plait', 'thank you', 'no thanks', 'non merci',
        'tu peux', 'peux tu', 'vous pouvez', 'pouvez vous', 'pourrais tu', 'pourriez vous',
        'est ce que tu peux', 'est ce que vous pouvez', 'je veux que tu', 'je voudrais que tu',
        'can you', 'could you', 'would you',
        // Accord
        'd accord', 'vas y', 'allez y', 'bien sur', 'c est bon', 'c est parfait', 'tres bien',
        'go ahead', 'do it', 'fais le', 'fais la', 'fais ca', 'faites le',
        'je confirme', 'j approuve', 'je valide', 'j accepte', 'j envoie', 'send it',
        // Refus, report
        'laisse tomber', 'laissez tomber', 'oublie ca', 'je refuse', 'pas la peine', 'surtout pas',
        'pas encore', 'pas maintenant', 'plus tard', 'plus rien', 'plus besoin', 'plus maintenant',
        'never mind', 'forget it', 'drop it', 'not yet', 'go back', 'en arriere', 'en fait', 'd abord',
        // Moment
        'tout de suite', 'right away', 'right now',
        // Objets
        'rendez vous', 'les deux', 'les trois', 'un peu', 'droit au but',
        'version precedente', 'previous version',
        // Arrêt de la lecture
        'arrete toi', 'arretez vous', 'arrete de parler', 'arrete la lecture', 'stop la lecture',
        'tais toi', 'taisez vous', 'te taire', 'interromps toi', 'ca suffit', 'ca ira', 'c est assez',
        'une seconde', 'deux secondes', 'un instant', 'une minute', 'ok merci', 'okay merci',
        'stop talking', 'stop reading', 'hold on', 'hang on', 'shut up', 'be quiet',
        'that s enough', 'thats enough', 'one second', 'one moment', 'ok thanks', 'okay thanks',
        // Fin de la conversation
        'fin de la conversation', 'termine la conversation', 'terminer la conversation',
        'arrete la conversation', 'stop la conversation', 'on arrete la', 'on s arrete la',
        'au revoir', 'a bientot', 'a plus tard', 'a plus', 'a la prochaine',
        'end the conversation', 'stop the conversation', 'end conversation', 'good bye', 'see you',
    ].map((phrase) => phrase.split(' '));

    const PHRASE_INDEX = new Map();
    PHRASES.forEach((phrase) => {
        if (!PHRASE_INDEX.has(phrase[0])) PHRASE_INDEX.set(phrase[0], []);
        PHRASE_INDEX.get(phrase[0]).push(phrase);
    });
    PHRASE_INDEX.forEach((list) => list.sort((a, b) => b.length - a.length));

    function joinPhrases(list) {
        const out = [];
        let i = 0;
        while (i < list.length) {
            let hit = null;
            for (const phrase of (PHRASE_INDEX.get(list[i].n) || [])) {
                if (i + phrase.length > list.length) continue;
                let same = true;
                for (let k = 1; k < phrase.length; k++) {
                    if (list[i + k].n !== phrase[k]) { same = false; break; }
                }
                if (same) { hit = phrase; break; }
            }
            if (hit) {
                out.push({ n: hit.join('_'), s: list[i].s, e: list[i + hit.length - 1].e });
                i += hit.length;
            } else {
                out.push(list[i]);
                i++;
            }
        }
        return out;
    }

    /** Mots d'une phrase, locutions jointes, avec leur position d'origine. */
    function tokenize(text) {
        const source = String(text == null ? '' : text).normalize('NFC');
        return { source, list: joinPhrases(rawWords(source)) };
    }

    /** Forme comparée (mots séparés par un blanc) — utile au débogage et aux tests. */
    function normalize(text) {
        return tokenize(text).list.map((w) => w.n).join(' ');
    }

    // ---- Vocabulaire -----------------------------------------------------
    const S = (words) => new Set(words.split(/\s+/).filter(Boolean));
    const union = (...sets) => {
        const out = new Set();
        sets.forEach((set) => set.forEach((w) => out.add(w)));
        return out;
    };

    // Mots qui ne changent rien au sens d'un ordre.
    const NEUTRAL = S(`mia euh heu hum hmm mmh bon ben bah alors eh oh ah hey he bien donc mais et dis
        ecoute ecoutez hein quoi voila well so um uh and please thanks thank_you merci stp svp
        s_il_te_plait s_il_vous_plait tu_peux peux_tu vous_pouvez pouvez_vous pourrais_tu pourriez_vous
        est_ce_que_tu_peux est_ce_que_vous_pouvez je_veux_que_tu je_voudrais_que_tu
        can_you could_you would_you ok_merci okay_merci ok_thanks okay_thanks`);

    // Accord et refus « nus ». Tolérés autour d'un mot d'action (« oui,
    // envoie »), ils ne portent JAMAIS seuls une action qui écrit ou envoie.
    const YES = S(`oui ouais ok okay d_accord yes yeah yep sure bien_sur vas_y allez_y go go_ahead
        c_est_bon c_est_parfait parfait tres_bien`);
    const NO = S('non non_merci no nope no_thanks pas_la_peine surtout_pas');

    // Ce qu'un ordre DÉSIGNE. Les pronoms et déterminants valent partout ;
    // les NOMS, eux, ne valent que pour la carte qui les porte : « crée une
    // tâche » n'approuve pas un évènement en attente, « annule la réunion »
    // n'abandonne pas un email. Pour une carte d'approbation, les noms admis
    // sont ceux de SON intitulé (cf. matchApproval).
    const DET = S(`le la l les lui leur ca cela ce cet cette ces c it this that them the un une des de du d
        a an`);
    const APPROVAL_NOUNS = S('action actions carte');
    const MAIL_NOUNS = S('mail mails email emails e courriel message messages reponse');
    const MAIL_OBJ = union(DET, MAIL_NOUNS);
    const TEMP = S('maintenant tout_de_suite directement deja vite now right_away right_now immediately');
    const ALL_MARKERS = S('tout tous toutes les_deux les_trois all both everything');

    const APPROVE_NEED = S(`approuve approuver approuvez approuves j_approuve valide valider validez valides
        je_valide confirme confirmer confirmez confirmes je_confirme accepte accepter acceptez acceptes
        j_accepte execute executer executez lance lancer lancez fais_le fais_la fais_ca faites_le
        approve approved confirm accept proceed do_it`);
    const APPROVE_ALLOW = S('dans sur mon ma mes in on my to y aussi');
    const CANCEL_NEED = S(`annule annuler annulez annules abandonne abandonner abandonnez laisse_tomber
        laissez_tomber oublie oubliez oublie_ca refuse refuser refusez je_refuse rejette rejeter rejetez
        cancel never_mind forget_it reject discard drop_it decline`);
    const CANCEL_ALLOW = S('finalement plutot en_fait actually rien');

    const SEND_NEED = S('envoie envoies envoyer envoyez j_envoie expedie expedier expediez send send_it');
    const SEND_ALLOW = S('a au aux to brouillon draft');
    const DRAFT_NEED = S('brouillon brouillons draft drafts');
    // « Non, juste en brouillon » : la seule commande qui tolère un « non »
    // en tête — elle répond à « je l'envoie ? » par l'issue la plus prudente.
    const DRAFT_ALLOW = S(`cree creer creez crees fais faire fait faites enregistre enregistrer enregistrez
        garde garder gardez mets mettre mettez met sauvegarde sauvegarder sauvegardez laisse laisser laissez
        prepare preparer preparez en comme dans mes juste seulement plutot simplement uniquement
        create save make keep leave as in my just only rather instead y non no`);
    const IMPROVE_NEED = S('ameliore ameliorer ameliorez ameliores amelioration improve');
    const DISCARD_NEED = S(`abandonne abandonner abandonnez laisse_tomber laissez_tomber oublie oubliez
        oublie_ca annule annuler annulez jette jeter jetez supprime supprimer supprimez efface effacer
        effacez discard cancel never_mind forget_it drop_it scrap delete`);
    // Panneau d'amélioration ouvert : « annule » y referme le panneau (rien
    // n'est perdu) ; abandonner le brouillon demande le mot juste.
    const DISCARD_STRICT_NEED = S(`abandonne abandonner abandonnez jette jeter jetez supprime supprimer
        supprimez efface effacer effacez discard scrap delete`);
    const DISCARD_ALLOW = S('finalement plutot en_fait actually tout brouillon draft');
    const CLOSE_NEED = S(`ferme fermer fermez retour rien plus_rien annule annuler annulez laisse_tomber
        laissez_tomber close back nothing never_mind cancel`);
    const CLOSE_ALLOW = S('panneau finalement a changer');
    const UNDO_NEED = S('version_precedente precedente en_arriere undo revert previous_version');
    const UNDO_ALLOW = S(`reviens revenir revenez remets remettre remettez retour retourne retourner version
        a au to go_back back restore restaure restaurer`);
    const UNDO_VERBS = S('annule annuler annulez undo');
    const UNDO_OBJECTS = S(`modification modifications changement changements amelioration reecriture derniere
        dernier change changes edit last`);

    const CONFIRM_NEED = S(`confirme confirmer confirmez confirmes je_confirme envoie envoies envoyer envoyez
        j_envoie send send_it confirm do_it`);
    const CONFIRM_ALLOW = S('envoi');
    const BACK_NEED = S(`retour reviens revenir annule annuler annulez attends attendez arrete arretez stop
        back go_back cancel wait modifier modifie change pas_encore pas_maintenant plus_tard not_yet
        relire relis`);
    const BACK_ALLOW = S('finalement plutot en_fait en_arriere actually je veux voudrais d_abord avant envoi');

    // Préréglages d'amélioration d'un email, par CLÉ (celles de
    // MailDraftReview::PRESETS) : ce que l'on dit pour les demander. « Moins »
    // n'est admis nulle part — « moins formel » est une consigne libre, pas
    // le préréglage « plus formel ».
    const PRESET_WORDS = {
        plus_court:      S('court courte courts courtes raccourcis raccourcir raccourcissez bref breve concis concise shorter short shorten brief'),
        plus_formel:     S('formel formelle formels formelles soutenu soutenue professionnel professionnelle formal professional'),
        plus_chaleureux: S('chaleureux chaleureuse chaleureuses sympathique sympa amical amicale warmer warm friendly friendlier'),
        plus_direct:     S('direct directe directs directes droit_au_but blunt straightforward'),
        plus_detaille:   S('detaille detaillee detailles detaillees long longue developpe developper rallonge allonge longer detailed elaborate'),
        corriger:        S('corrige corriger corrigez fautes faute orthographe grammaire langue relis relire proofread spelling grammar typos language mistakes'),
    };
    const PRESET_ALLOW = S(`rends rend rendre rendez fais fait faire faites fais_le fais_la fais_ca faites_le
        le la l les un une un_peu peu message mail
        email texte ton plus version ecris ecrire reecris reformule ameliore en du des de d beaucoup tres
        encore make it a bit little more much the text tone be que ce soit sois mets y fix correct check`);

    // Consigne libre d'amélioration. Quatre familles, de la plus sûre à la
    // plus ambiguë : un verbe de réécriture (« reformule… ») ; un comparatif
    // en tête (« plus poli ») ; « rends-le… » avec un comparatif ou un mot du
    // courrier ; un verbe passe-partout (« change », « corrige », « ajoute »)
    // — ce dernier seulement s'il NOMME une partie du courrier (objet, ton,
    // phrase…). « Change le rendez-vous de demain » ou « ajoute une tâche plus
    // urgente » ne sont pas des retouches de l'email en attente : le doute
    // renvoie vers l'assistant, qui sait de quoi l'on parle.
    const REWRITE_VERBS = S(`ameliore ameliorer ameliorez reformule reformuler reformulez reecris reecrire
        reecrivez retravaille retravailler raccourcis raccourcir rallonge rallonger allonge allonger
        improve rewrite rephrase shorten lengthen`);
    const COMPARATIVES = S('plus moins more less');
    // « Rends-le… », « fais-le… » (le pronom est déjà dans la locution).
    const RENDER_VERBS = S('rends rend rendre rendez make fais_le fais_la fais_ca faites_le');
    const SHAPING_VERBS = S(`modifie modifier modifiez change changer changez remplace remplacer remplacez
        corrige corriger corrigez ajoute ajouter ajoutez enleve enlever enlevez retire retirer retirez
        supprime supprimer supprimez edit fix add remove replace`);
    // Volontairement étroit : « message », « texte », « titre » désignent
    // aussi bien un message Slack ou le titre d'une présentation.
    const MAIL_WORDS = S(`mail email courriel objet sujet ton phrase phrases paragraphe paragraphes formule
        politesse salutation signature introduction conclusion brouillon tutoiement vouvoiement subject tone
        sentence sentences paragraph greeting wording draft`);

    // Arrêt de la lecture. Trois familles :
    //  - FRANC (« stop », « tais-toi ») et ATTENTE (« attends ») : peuvent être
    //    suivis d'autre chose — « stop, et pour demain ? » ;
    //  - DOUX (« c'est bon », « ça suffit ») : seulement s'ils sont dits SEULS.
    //    « C'est bon à savoir » est une remarque, pas un ordre.
    const STOP_HARD = S(`stop stoppe stoppez stopper arrete arretez arreter arrete_toi arretez_vous
        arrete_de_parler arrete_la_lecture stop_la_lecture tais_toi taisez_vous te_taire silence chut halte
        interromps_toi shut_up be_quiet quiet stop_talking stop_reading`);
    const STOP_WAIT = S(`attends attendez une_seconde deux_secondes un_instant une_minute pause wait hold_on
        hang_on one_second one_moment`);
    const STOP_SOFT = S(`c_est_bon ca_suffit ca_ira assez c_est_assez passe passez passons suivant coupe
        coupez skip next enough that_s_enough thats_enough ok_merci okay_merci ok_thanks okay_thanks`);
    // Ordres reconnus aussi EN FIN de phrase, pendant la lecture : quand on
    // parle par-dessus MIA, l'annulation d'écho laisse passer des bribes de
    // sa voix, et « stop » arrive au bout d'une phrase qui n'est pas de nous.
    const STOP_TRAILING = S(`stop stoppe stoppez arrete arretez arrete_toi arretez_vous arrete_de_parler
        arrete_la_lecture stop_la_lecture tais_toi taisez_vous silence chut shut_up be_quiet stop_talking
        stop_reading`);
    // Fillers admis AVANT l'ordre d'arrêt. « ok merci » est une locution : le
    // « ok » seul, lui, se saute.
    const STOP_LEAD = S(`mia euh heu hum hmm bon ben bah alors eh oh ah hey he mais et ok okay oui ouais non
        merci well so um uh and yes no please t tu_peux peux_tu vous_pouvez pouvez_vous pourrais_tu
        pourriez_vous est_ce_que_tu_peux est_ce_que_vous_pouvez can_you could_you would_you`);
    const STOP_TAIL = S(`mia merci beaucoup stp svp s_il_te_plait s_il_vous_plait please thanks thank_you
        maintenant now deja ca la`);

    // Premier mot (brut) des ordres francs : sert à savoir si le mot d'arrêt
    // figure dans le texte lu.
    const STOP_WORD_ROOTS = S(`stop stoppe stoppez stopper arrete arretez arreter tais taisez silence chut halte
        interromps attends attendez pause wait hold hang shut quiet`);

    const END_NEED = S(`fin_de_la_conversation termine_la_conversation terminer_la_conversation
        arrete_la_conversation stop_la_conversation on_arrete_la on_s_arrete_la au_revoir a_bientot
        a_plus_tard a_plus a_la_prochaine termine terminer terminez terminons bye goodbye good_bye see_you
        end_the_conversation stop_the_conversation end_conversation`);
    const END_ALLOW = S(`pour aujourd hui la conversation vocale bonne journee soiree maintenant today for now
        later`);

    // ---- Sac de mots -----------------------------------------------------
    // Vrai si chaque mot est attendu, et qu'au moins un porte l'ordre.
    //   need   : mots qui portent l'ordre ;
    //   softs  : accord / refus nus du MÊME camp — tolérés autour d'un mot de
    //            `need`, et suffisants seuls si softOk (renoncements sans
    //            conséquence uniquement) ;
    //   allow  : mots admis autour ;
    //   fill   : mots du camp OPPOSÉ tolérés en plus d'un mot de `need`
    //            (« ok annule » annule ; « non, vas-y » ne décide de rien).
    function bag(names, spec) {
        let hard = 0;
        let soft = 0;
        let fill = 0;
        for (const word of names) {
            if (spec.need.has(word)) { hard++; continue; }
            if (spec.softs && spec.softs.has(word)) { soft++; continue; }
            if (spec.fill && spec.fill.has(word)) { fill++; continue; }
            if (NEUTRAL.has(word) || (spec.allow && spec.allow.has(word))) continue;
            return false;
        }
        if (fill > 0 && hard === 0) return false;
        return hard > 0 || (spec.softOk === true && soft > 0);
    }

    /** Rien d'autre qu'un accord ou un refus nu (« oui », « ok merci… non »). */
    function bareYesNo(names) {
        let seen = false;
        for (const word of names) {
            if (YES.has(word) || NO.has(word)) { seen = true; continue; }
            if (!NEUTRAL.has(word)) return false;
        }
        return seen;
    }

    // ---- Verbe de la carte ----------------------------------------------
    // Une carte s'intitule « Créer un évènement… », « Publier un message… » :
    // dire ce verbe (« crée-le », « publie ») vaut approbation de CETTE carte.
    const IRREGULAR = {
        envoyer:  ['envoyer', 'envoie', 'envoies', 'envoyez'],
        repondre: ['repondre', 'reponds', 'repond', 'repondez'],
        mettre:   ['mettre', 'mets', 'met', 'mettez'],
    };
    function verbForms(infinitive) {
        const verb = normWord(String(infinitive || ''));
        if (IRREGULAR[verb]) return IRREGULAR[verb].slice();
        if (verb.length > 3 && verb.endsWith('er')) {
            const stem = verb.slice(0, -2);
            return [verb, stem + 'e', stem + 'es', stem + 'ez'];
        }
        return verb ? [verb] : [];
    }

    // ---- Carte d'approbation (tous les connecteurs sauf l'email) ---------
    function matchApproval(names, ctx) {
        const titleWords = rawWords(String(ctx.title || '').normalize('NFC')).map((w) => w.n);
        const all = names.some((w) => ALL_MARKERS.has(w));
        // Le refus d'abord : il ne défait rien, et « ok annule » est un refus.
        if (bag(names, {
            need: CANCEL_NEED, softs: NO, fill: YES,
            allow: union(DET, APPROVAL_NOUNS, TEMP, ALL_MARKERS, CANCEL_ALLOW, new Set(titleWords)),
        })) return { act: 'cancel', all };
        if (bag(names, {
            need: union(APPROVE_NEED, new Set(verbForms(titleWords[0]))), softs: YES,
            allow: union(DET, APPROVAL_NOUNS, TEMP, ALL_MARKERS, APPROVE_ALLOW, new Set(titleWords)),
        })) return { act: 'approve', all };
        return bareYesNo(names) ? { act: 'reask' } : null;
    }

    // ---- Carte de relecture d'un email -----------------------------------
    function matchPreset(names, presets) {
        const available = new Set((presets || []).map(String));
        for (const key of Object.keys(PRESET_WORDS)) {
            if (!available.has(key)) continue;
            const words = PRESET_WORDS[key];
            if (!names.some((w) => words.has(w))) continue;
            if (names.every((w) => words.has(w) || PRESET_ALLOW.has(w) || NEUTRAL.has(w) || YES.has(w))) return key;
        }
        return null;
    }

    function matchInstruction(parsed, names, mode) {
        let i = 0;
        while (i < names.length && (NEUTRAL.has(names[i]) || YES.has(names[i]) || NO.has(names[i]))) i++;
        const rest = names.slice(i);
        if (!rest.length) return '';
        const said = parsed.source.slice(parsed.list[i].s).trim();
        // Panneau ouvert à la voix (« améliore ») : MIA vient de demander quoi
        // changer — ce qui suit EST la consigne. Sauf un adieu ou un ordre
        // d'arrêt, qui ne réécrivent pas un email : ils reprennent leur chemin.
        if (mode === 'improve') {
            if (bag(names, { need: END_NEED, fill: YES, allow: END_ALLOW })) return '';
            const stop = stopCommand(parsed.source);
            return stop && !stop.remainder ? '' : said;
        }
        if (rest.length < 2) return '';
        const lead = rest[0];
        if (REWRITE_VERBS.has(lead) || COMPARATIVES.has(lead)) return said;
        const namesMail = rest.some((w) => MAIL_WORDS.has(w));
        if (RENDER_VERBS.has(lead) && (namesMail || rest.some((w) => COMPARATIVES.has(w)))) return said;
        if (SHAPING_VERBS.has(lead) && namesMail) return said;
        return '';
    }

    function matchMail(parsed, names, ctx) {
        const mode = String(ctx.mode || 'preview');
        if (mode === 'send-confirm') {
            // Dernier mot avant un envoi réel. Le renoncement est testé en
            // premier, et lui seul accepte un « non » nu : il ne défait rien.
            if (bag(names, { need: BACK_NEED, softs: NO, softOk: true, fill: YES, allow: union(MAIL_OBJ, TEMP, BACK_ALLOW) })) {
                return { act: 'back' };
            }
            if (bag(names, { need: DRAFT_NEED, softs: YES, allow: union(MAIL_OBJ, TEMP, DRAFT_ALLOW) })) {
                return { act: 'draft' };
            }
            if (bag(names, { need: CONFIRM_NEED, softs: YES, allow: union(MAIL_OBJ, TEMP, CONFIRM_ALLOW) })) {
                return { act: 'confirm' };
            }
            return bareYesNo(names) ? { act: 'reask' } : null;
        }

        // « Envoie-le à Jean » : le complément n'est admis que s'il nomme un
        // destinataire DE LA CARTE. Tout autre nom change la demande.
        const recipients = new Set(
            rawWords(String(ctx.recipients || '').normalize('NFC')).map((w) => w.n).filter((w) => w.length > 1)
        );
        if (bag(names, { need: SEND_NEED, softs: YES, allow: union(MAIL_OBJ, TEMP, SEND_ALLOW, recipients) })) {
            return { act: 'send' };
        }
        if (bag(names, { need: DRAFT_NEED, softs: YES, allow: union(MAIL_OBJ, TEMP, DRAFT_ALLOW) })) {
            return { act: 'draft' };
        }
        if (ctx.canUndo) {
            if (bag(names, { need: UNDO_NEED, allow: union(DET, UNDO_ALLOW) })) return { act: 'undo' };
            if (names.some((w) => UNDO_OBJECTS.has(w))
                && bag(names, { need: UNDO_VERBS, allow: union(DET, UNDO_OBJECTS) })) return { act: 'undo' };
        }
        if (mode === 'improve') {
            if (bag(names, { need: CLOSE_NEED, softs: NO, softOk: true, fill: YES, allow: union(DET, CLOSE_ALLOW) })) {
                return { act: 'close' };
            }
            if (bag(names, { need: DISCARD_STRICT_NEED, softs: NO, fill: YES, allow: union(MAIL_OBJ, TEMP, DISCARD_ALLOW) })) {
                return { act: 'discard' };
            }
        } else if (bag(names, { need: DISCARD_NEED, softs: NO, fill: YES, allow: union(MAIL_OBJ, TEMP, DISCARD_ALLOW) })) {
            return { act: 'discard' };
        }
        if (bag(names, { need: IMPROVE_NEED, softs: YES, allow: union(MAIL_OBJ, TEMP) })) return { act: 'improve' };
        const preset = matchPreset(names, ctx.presets);
        if (preset) return { act: 'preset', preset };
        const instruction = matchInstruction(parsed, names, mode);
        if (instruction) return { act: 'instruct', instruction };
        return null;
    }

    /**
     * Ordre adressé à la carte en attente, ou null (= message ordinaire).
     *
     * ctx : { kind: 'approval' | 'mail',
     *         title (approval : intitulé de la carte, « Créer un évènement… »),
     *         mode  (mail : 'preview' | 'improve' | 'send-confirm'),
     *         recipients (mail : destinataires affichés),
     *         presets (mail : clés des préréglages proposés),
     *         canUndo (mail : une version précédente existe) }
     *
     * Rend { act, all?, preset?, instruction? } — act ∈ approve | cancel |
     * reask (approval) ; send | draft | improve | preset | instruct | undo |
     * discard | close | confirm | back | reask (mail). `reask` : un accord ou
     * un refus nu, là où il faut le mot de l'action.
     */
    function cardCommand(text, ctx) {
        const parsed = tokenize(text);
        const names = parsed.list.map((w) => w.n);
        if (!names.length || !ctx) return null;
        return ctx.kind === 'mail' ? matchMail(parsed, names, ctx) : matchApproval(names, ctx);
    }

    /**
     * Ordre d'arrêt, ou null.
     * Rend { remainder, hard } : `remainder` = ce qui a été dit APRÈS l'ordre
     * (« stop, et pour demain ? » → « et pour demain ? »), '' s'il est seul ;
     * `hard` = ordre franc ou d'attente (jamais un message pour l'assistant),
     * faux pour un ordre doux (« c'est bon »), qui reste une réponse possible
     * hors lecture.
     */
    function stopCommand(text) {
        const parsed = tokenize(text);
        const list = parsed.list;
        let i = 0;
        while (i < list.length && STOP_LEAD.has(list[i].n)) i++;
        if (i >= list.length) return null;

        const word = list[i].n;
        const franc = STOP_HARD.has(word) || STOP_WAIT.has(word);
        if (!franc && !STOP_SOFT.has(word)) return null;

        let j = i + 1;
        // « Arrête de me vouvoyer » : une consigne, pas un simple arrêt — la
        // phrase entière repart vers l'assistant.
        if ((word === 'arrete' || word === 'arretez' || word === 'arreter' || word === 'stop') && j < list.length
            && (list[j].n === 'de' || list[j].n === 'd')) {
            return { remainder: parsed.source.slice(list[i].s).trim(), hard: true };
        }
        while (j < list.length
            && (STOP_HARD.has(list[j].n) || STOP_WAIT.has(list[j].n) || STOP_SOFT.has(list[j].n) || STOP_TAIL.has(list[j].n))) j++;

        const rest = list.slice(j);
        const meaningful = rest.some((w) => !NEUTRAL.has(w.n) && !STOP_TAIL.has(w.n));
        if (!meaningful) return { remainder: '', hard: franc };
        if (!franc) return null; // ordre doux suivi d'autre chose : une remarque
        return { remainder: parsed.source.slice(rest[0].s).trim(), hard: true };
    }

    /**
     * Ce qu'une phrase entendue PENDANT la lecture doit faire : null (rien —
     * une remarque, la voix de MIA), ou { remainder } : MIA se tait, et
     * `remainder` (éventuellement '') part comme le tour suivant.
     *
     * `reading` = le texte que MIA est en train de lire. Trois cas d'écho :
     *  - la phrase ENTIÈRE, ordre compris, est dans le texte lu (« Arrête-toi
     *    à la boulangerie ») : MIA s'entend elle-même → rien ;
     *  - l'ordre est de l'utilisateur mais ce qui SUIT est dans le texte lu
     *    (il a parlé par-dessus MIA) : on interrompt, sans rien envoyer ;
     *  - l'ordre arrive EN FIN d'une phrase qui n'est pas de lui : idem.
     * Un ordre dit SEUL n'est jamais soumis au test : l'ignorer à tort laisse
     * MIA parler contre la volonté de l'utilisateur, le suivre à tort coupe
     * une lecture dont le texte reste à l'écran.
     */
    function interruption(text, reading) {
        const stop = stopCommand(text);
        if (stop) {
            if (!stop.remainder) return { remainder: '' };
            if (orderIsEcho(text, reading)) return null;
            return { remainder: looksLikeEcho(stop.remainder, reading) ? '' : stop.remainder };
        }
        const list = tokenize(text).list;
        let last = list.length - 1;
        while (last > 0 && (STOP_TAIL.has(list[last].n) || NEUTRAL.has(list[last].n))) last--;
        if (last > 0 && STOP_TRAILING.has(list[last].n) && !orderIsEcho(text, reading)) return { remainder: '' };
        return null;
    }

    /**
     * La phrase, ORDRE COMPRIS, vient-elle du texte lu ? Il faut que la phrase
     * y soit (looksLikeEcho tolère un mot d'écart) ET que le mot d'arrêt
     * lui-même y figure — sinon ce mot-là, au moins, a été dit par quelqu'un.
     */
    function orderIsEcho(text, reading) {
        if (!looksLikeEcho(text, reading)) return false;
        const read = new Set(rawWords(String(reading || '').normalize('NFC')).map((w) => w.n));
        return rawWords(String(text || '').normalize('NFC'))
            .some((w) => STOP_WORD_ROOTS.has(w.n) && read.has(w.n));
    }

    /** « Au revoir », « termine la conversation » : vrai si c'est TOUT ce qui est dit. */
    function endCommand(text) {
        const names = tokenize(text).list.map((w) => w.n);
        if (!names.length) return false;
        return bag(names, { need: END_NEED, fill: YES, allow: END_ALLOW });
    }

    /**
     * La phrase entendue est-elle un morceau du texte que MIA est en train de
     * lire (sa voix, revenue par le micro) ? Vrai si au moins 80 % de ses
     * mots se retrouvent, dans l'ordre, dans une fenêtre CONTIGUË du texte —
     * une suite, pas un semis : « et pour la suite » se retrouve en désordre
     * dans n'importe quelle réponse longue, sans en être l'écho.
     */
    function looksLikeEcho(transcript, reference) {
        const heard = rawWords(String(transcript || '').normalize('NFC')).map((w) => w.n).slice(0, 30);
        if (!heard.length) return true;
        const read = rawWords(String(reference || '').normalize('NFC')).map((w) => w.n);
        if (!read.length) return false;
        if (heard.length === 1) return read.includes(heard[0]);

        const n = heard.length;
        const target = Math.ceil(n * 0.8);
        const span = n + 2;
        const starts = new Set(heard.slice(0, 2));
        let prev = new Uint16Array(n + 1);
        let cur = new Uint16Array(n + 1);
        for (let from = 0; from < read.length; from++) {
            if (!starts.has(read[from])) continue;
            prev.fill(0);
            const to = Math.min(read.length, from + span);
            for (let j = from; j < to; j++) {
                cur[0] = 0;
                for (let i = 1; i <= n; i++) {
                    cur[i] = heard[i - 1] === read[j] ? prev[i - 1] + 1 : Math.max(prev[i], cur[i - 1]);
                }
                const swap = prev; prev = cur; cur = swap;
            }
            if (prev[n] >= target) return true;
        }
        return false;
    }

    root.MiaVoiceCommands = { normalize, cardCommand, stopCommand, interruption, endCommand, looksLikeEcho, verbForms };
})(typeof window !== 'undefined' ? window : globalThis);
