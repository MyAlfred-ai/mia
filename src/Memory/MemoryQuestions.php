<?php

declare(strict_types=1);

/**
 * Questions de MIA (mémoire, lot 2).
 *
 * De temps en temps — rarement —, sous une réponse TERMINÉE, une carte
 * discrète pose une question à la personne pour mieux la connaître : la forme
 * qu'elle préfère, sa façon de travailler, ses outils, son métier ; ou lui
 * fait confirmer ce que MIA a cru comprendre (une proposition « à confirmer »
 * revue au moins deux fois). La question est ÉCRITE PAR LE SERVEUR, jamais
 * par le modèle, et la réponse est enregistrée sans appel au modèle : rien
 * ne coûte, rien ne peut être piloté par un contenu lu.
 *
 * Trois étages :
 *   - formes PURES (rythme, conditions du tour, choix de la question, charge
 *     utile de la carte) — testables sans base ;
 *   - `forTurn` : décide à la fin d'un tour de chat (ChatController::runSend)
 *     et inscrit la question — `UNIQUE(conversation_id)` tranche entre deux
 *     onglets ou deux workers : jamais deux questions dans une conversation ;
 *   - `answer` : applique la réponse (un souvenir de source `question`, le
 *     style de réponse, une proposition confirmée, refusée ou corrigée), une
 *     seule fois.
 *
 * Rythme (sans inversion entre les réglages de la personne) : au plus une
 * question par jour tant qu'elle a moins de BOOTSTRAP_BELOW souvenirs actifs,
 * puis tous les 7 jours (« rarement », le défaut) ou tous les 3 jours (« de
 * temps en temps ») ; « jamais » coupe tout. Rien pendant la toute première
 * conversation de la personne, ni sur la première réponse d'une conversation.
 *
 * Une proposition née d'un GESTE répété (MemorySignals : « Vous me demandez
 * souvent de raccourcir mes emails. Je fais plus court d'office
 * désormais ? ») se pose plus tôt : au plus une question par jour, quel que
 * soit le réglage — sauf « jamais ». Elle porte sur ce que la personne vient
 * de faire : attendre une semaine la rendrait incompréhensible.
 *
 * La CARTE D'INFORMATION de l'apprentissage (lot 3, KIND_INTRO) passe avant
 * tout : tant que la personne n'a pas été informée que MIA apprend de ses
 * échanges (MemoryLearner::needsIntro), elle lui est montrée — au plus une
 * fois par jour, quel que soit le réglage des questions (ce n'est pas une
 * question : rien n'est appris avant). Elle ne compte pour « informée »
 * qu'une fois la carte touchée (Compris, Ne pas apprendre, ou ×) ou la page
 * Mémoire ouverte : l'afficher ne suffit pas.
 */
final class MemoryQuestions
{
    /** Réglage d'administration (carte Mémoire de /admin/tools). */
    public const SETTING_ENABLED = 'memory_questions_enabled';

    /** Réglage de la personne (`users.memory_questions`). */
    public const FREQ_RARELY = '';
    public const FREQ_OFTEN  = 'often';
    public const FREQ_OFF    = 'off';

    /** Libellés (clés de traduction, accesseur frequencies()). */
    public const FREQUENCIES = [
        self::FREQ_RARELY => 'Rarement',
        self::FREQ_OFTEN  => 'De temps en temps',
        self::FREQ_OFF    => 'Jamais',
    ];

    /** Aides des choix (clés de traduction). */
    public const FREQUENCY_HINTS = [
        self::FREQ_RARELY => 'Une question par semaine au plus — une par jour pendant que MIA fait votre connaissance.',
        self::FREQ_OFTEN  => 'Une question tous les trois jours au plus.',
        self::FREQ_OFF    => 'MIA ne pose aucune question. Vous pouvez toujours lui dire « souviens-toi que… ».',
    ];

    /** Sous ce nombre de souvenirs actifs, MIA fait connaissance : une question par jour au plus. */
    public const BOOTSTRAP_BELOW = 5;

    public const GAP_BOOTSTRAP = 86400;
    public const GAP_OFTEN     = 3 * 86400;
    public const GAP_RARELY    = 7 * 86400;

    /** Une question de la banque passée ou restée sans réponse revient au plus tôt après… */
    public const REASK_AFTER = 60 * 86400;

    /** Une proposition déjà soumise revient au plus tôt après… */
    public const PROPOSAL_REASK_AFTER = 14 * 86400;

    /** Une proposition n'est soumise qu'une fois revue au moins ce nombre de fois. */
    public const PROPOSAL_MIN_HITS = 2;

    /** Réponse libre : longueur maximale. */
    public const OTHER_MAX = 200;

    public const KIND_BANK     = 'bank';
    public const KIND_PROPOSAL = 'proposal';
    public const KIND_INTRO    = 'intro';

    /** Clé d'une question qui soumet une proposition. */
    public const PROPOSAL_KEY = 'proposition';

    /** Clé de la carte d'information de l'apprentissage. */
    public const INTRO_KEY = 'apprentissage';

    public const OUTCOME_ANSWERED = 'answered';
    public const OUTCOME_SKIPPED  = 'skipped';
    public const OUTCOME_STOPPED  = 'stopped';

    /** Choix communs à toutes les cartes. */
    public const CHOICE_OTHER = 'autre';
    public const CHOICE_SKIP  = 'passer';
    public const CHOICE_STOP  = 'stop';
    public const CHOICE_YES   = 'oui';
    public const CHOICE_NO    = 'non';
    /** Carte d'information : garder l'apprentissage, ou le refuser. */
    public const CHOICE_OK     = 'compris';
    public const CHOICE_REFUSE = 'refuser';

    /**
     * La banque : clé STABLE (journal, réponses déjà données) → question.
     * Textes français = clés de traduction (accesseurs : le gabarit et le
     * navigateur reçoivent des textes déjà traduits).
     *
     *   - `options` : choix => libellé + effet (`style` : style de réponse ;
     *     `memoire` : le souvenir retenu ; ni l'un ni l'autre : rien à retenir) ;
     *   - `other`   : gabarit d'une réponse libre (« {reponse} »), null = pas
     *     de réponse libre ;
     *   - `when`    : condition (`style_vide`, `connecteur`, `profil_vide`).
     *
     * Le thème du souvenir est le préfixe de la clé.
     */
    public const BANK = [
        'reponses.longueur' => [
            'question' => 'Pour vos questions de tous les jours, vous préférez des réponses…',
            'options'  => [
                'courtes' => ['label' => 'Courtes, à l\'essentiel', 'style' => 'concis'],
                'detail'  => ['label' => 'Détaillées, avec des explications', 'style' => 'detaille'],
                'depend'  => ['label' => 'Ça dépend du sujet'],
            ],
            'other'    => null,
            'when'     => 'style_vide',
        ],
        'reponses.structure' => [
            'question' => 'Quand une réponse est un peu longue, qu\'est-ce qui vous aide le plus ?',
            'options'  => [
                'listes'      => ['label' => 'Des listes à puces', 'memoire' => 'Préfère des réponses structurées en listes à puces'],
                'tableaux'    => ['label' => 'Des tableaux quand c\'est possible', 'memoire' => 'Apprécie les tableaux pour comparer ou résumer'],
                'paragraphes' => ['label' => 'Des paragraphes rédigés', 'memoire' => 'Préfère des réponses rédigées en paragraphes plutôt qu\'en listes'],
            ],
            'other'    => 'Pour la présentation des réponses : {reponse}',
        ],
        'methode.clarifier' => [
            'question' => 'Quand votre demande est un peu floue, je dois plutôt…',
            'options'  => [
                'question' => ['label' => 'Vous poser une question d\'abord', 'memoire' => 'Préfère qu\'on lui pose une question quand sa demande est ambiguë, avant de répondre'],
                'proposer' => ['label' => 'Proposer directement une réponse', 'memoire' => 'Préfère une réponse directe, quitte à l\'ajuster ensuite, plutôt qu\'une question préalable'],
            ],
            'other'    => 'Quand sa demande est floue : {reponse}',
        ],
        'methode.sources' => [
            'question' => 'Quand je m\'appuie sur des sources (Internet, documents), vous voulez…',
            'options'  => [
                'citer'     => ['label' => 'Que je cite mes sources à chaque fois', 'memoire' => 'Souhaite que les sources soient citées à chaque fois'],
                'essentiel' => ['label' => 'L\'essentiel, sans la liste des sources', 'memoire' => 'Préfère l\'essentiel sans la liste des sources, sauf s\'il la demande'],
            ],
            'other'    => 'Pour les sources : {reponse}',
        ],
        'methode.options' => [
            'question' => 'Face à un choix (une formulation, une approche), vous préférez…',
            'options'  => [
                'une'       => ['label' => 'Une seule proposition, la meilleure', 'memoire' => 'Préfère une seule recommandation plutôt que plusieurs options'],
                'plusieurs' => ['label' => 'Deux ou trois options à comparer', 'memoire' => 'Aime avoir deux ou trois options à comparer avant de choisir'],
            ],
            'other'    => 'Face à un choix : {reponse}',
        ],
        'outils.initiative' => [
            'question' => 'Quand vos outils connectés (emails, agenda, fichiers) peuvent m\'aider à répondre, je dois…',
            'options'  => [
                'office'   => ['label' => 'Les consulter d\'office quand c\'est utile', 'memoire' => 'Souhaite que ses outils connectés soient consultés d\'office quand c\'est utile (les actions restent soumises à sa validation)'],
                'demander' => ['label' => 'Vous demander avant de les consulter', 'memoire' => 'Préfère qu\'on lui demande avant de consulter ses outils connectés'],
            ],
            'other'    => 'Pour ses outils connectés : {reponse}',
            'when'     => 'connecteur',
        ],
        'outils.livrables' => [
            'question' => 'Quand le résultat s\'y prête, vous préférez recevoir…',
            'options'  => [
                'fichier' => ['label' => 'Un fichier à télécharger (Word, Excel, PowerPoint)', 'memoire' => 'Préfère recevoir un fichier à télécharger (Word, Excel, PowerPoint) quand le résultat s\'y prête'],
                'texte'   => ['label' => 'Le texte directement dans la conversation', 'memoire' => 'Préfère le texte directement dans la conversation plutôt qu\'un fichier'],
            ],
            'other'    => 'Pour les livrables : {reponse}',
        ],
        'profil.role' => [
            'question' => 'Pour mieux vous répondre : quel est votre métier, en quelques mots ?',
            'options'  => [],
            'other'    => 'Métier : {reponse}',
            'when'     => 'profil_vide',
        ],
    ];

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED, '1') !== '0';
    }

    // -----------------------------------------------------------------------
    // Formes pures
    // -----------------------------------------------------------------------

    /** Réglage de la personne, normalisé. */
    public static function frequencyOf(?array $user): string
    {
        $freq = (string)($user['memory_questions'] ?? '');
        return array_key_exists($freq, self::FREQUENCIES) ? $freq : self::FREQ_RARELY;
    }

    /** @return array<string, string> libellés traduits, dans l'ordre d'affichage */
    public static function frequencies(): array
    {
        $out = [];
        foreach (self::FREQUENCIES as $key => $label) {
            $out[$key] = t($label);
        }
        return $out;
    }

    /** @return array<string, string> aides traduites */
    public static function frequencyHints(): array
    {
        $out = [];
        foreach (self::FREQUENCY_HINTS as $key => $hint) {
            $out[$key] = t($hint);
        }
        return $out;
    }

    /**
     * Écart minimal entre deux questions, en secondes ; null = jamais.
     * Le démarrage (moins de BOOTSTRAP_BELOW souvenirs) est commun aux deux
     * réglages : « de temps en temps » ne pose jamais moins que « rarement ».
     */
    public static function gapFor(string $freq, int $activeCount): ?int
    {
        if ($freq === self::FREQ_OFF) {
            return null;
        }
        if ($activeCount < self::BOOTSTRAP_BELOW) {
            return self::GAP_BOOTSTRAP;
        }
        return $freq === self::FREQ_OFTEN ? self::GAP_OFTEN : self::GAP_RARELY;
    }

    /**
     * Une question peut-elle être posée maintenant ? `$sinceLast` = secondes
     * depuis la dernière question posée (null = aucune), mesurées par MySQL :
     * jamais une horloge PHP comparée à `NOW()`.
     */
    public static function dueNow(?int $sinceLast, string $freq, int $activeCount): bool
    {
        $gap = self::gapFor($freq, $activeCount);
        if ($gap === null) {
            return false;
        }
        return $sinceLast === null || $sinceLast >= $gap;
    }

    /**
     * Une proposition née d'un geste peut-elle être posée maintenant ? Au
     * plus une question par jour, sauf si la personne a dit « jamais ».
     */
    public static function signalDue(?int $sinceLast, string $freq): bool
    {
        return $freq !== self::FREQ_OFF && ($sinceLast === null || $sinceLast >= self::GAP_BOOTSTRAP);
    }

    /**
     * L'état réduit aux propositions nées d'un geste (le rythme ordinaire
     * n'est pas encore échu : ni banque, ni autre proposition).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function signalOnly(array $state): array
    {
        return ['proposals' => array_values(array_filter(
            (array)($state['proposals'] ?? []),
            static fn(array $p): bool => trim((string)($p['signal'] ?? '')) !== ''
        ))];
    }

    /**
     * Le tour se prête-t-il à une question ? Jamais quand la personne a autre
     * chose à faire ou à lire : erreur, réponse coupée, carte à approuver,
     * choix en boutons, version soignée proposée, mémoire déjà touchée ; ni à
     * l'oral, ni dans un tour sans personne ; ni sur la première réponse d'une
     * conversation, ni pendant la toute première conversation.
     *
     * @param array<string, mixed> $f
     */
    public static function turnAllows(array $f): bool
    {
        foreach (['voice', 'unattended', 'error', 'interrupted', 'choice_block', 'polish_offer', 'captured', 'empty'] as $flag) {
            if (!empty($f[$flag])) {
                return false;
            }
        }
        return (int)($f['cards'] ?? 0) === 0
            && (int)($f['prior_replies'] ?? 0) >= 1
            && (int)($f['conversations'] ?? 0) >= 2;
    }

    /**
     * Choisit la question à poser, ou null. Ordre : la carte d'information de
     * l'apprentissage, puis une proposition revue au
     * moins deux fois (la plus revue, puis la plus ancienne), puis la banque,
     * dans son ordre, sans ce qui a déjà reçu une réponse ni ce qui a été posé
     * récemment.
     *
     * @param array{
     *   intro?: bool,
     *   proposals?: list<array{id:int, content:string, hits:int, since?:?int, signal?:string, scope?:string}>,
     *   bank?: array<string, array{answered?:bool, since?:?int}>,
     *   style_empty?: bool, has_connector?: bool, profile_empty?: bool
     * } $state
     * @return array{kind:string, key:string, memory_id?:int, content?:string, signal?:string, scope?:string}|null
     */
    public static function pick(array $state): ?array
    {
        if (!empty($state['intro'])) {
            return ['kind' => self::KIND_INTRO, 'key' => self::INTRO_KEY];
        }
        $proposals = array_values(array_filter(
            (array)($state['proposals'] ?? []),
            static fn(array $p): bool => (int)($p['hits'] ?? 0) >= self::PROPOSAL_MIN_HITS
                && trim((string)($p['content'] ?? '')) !== ''
                && (($p['since'] ?? null) === null || (int)$p['since'] >= self::PROPOSAL_REASK_AFTER)
        ));
        usort($proposals, static fn(array $a, array $b): int
            => [(int)$b['hits'], (int)$a['id']] <=> [(int)$a['hits'], (int)$b['id']]);
        if ($proposals !== []) {
            $picked = [
                'kind'      => self::KIND_PROPOSAL,
                'key'       => self::PROPOSAL_KEY,
                'memory_id' => (int)$proposals[0]['id'],
                'content'   => (string)$proposals[0]['content'],
            ];
            // Née d'un geste, ou de portée restreinte : de quoi l'écrire.
            foreach (['signal', 'scope'] as $field) {
                if (trim((string)($proposals[0][$field] ?? '')) !== '') {
                    $picked[$field] = (string)$proposals[0][$field];
                }
            }
            return $picked;
        }

        $bank = (array)($state['bank'] ?? []);
        foreach (self::BANK as $key => $question) {
            $seen = $bank[$key] ?? [];
            if (!empty($seen['answered'])) {
                continue;
            }
            if (($seen['since'] ?? null) !== null && (int)$seen['since'] < self::REASK_AFTER) {
                continue;
            }
            $when = $question['when'] ?? null;
            if ($when === 'style_vide' && empty($state['style_empty'])) {
                continue;
            }
            if ($when === 'connecteur' && empty($state['has_connector'])) {
                continue;
            }
            if ($when === 'profil_vide' && empty($state['profile_empty'])) {
                continue;
            }
            return ['kind' => self::KIND_BANK, 'key' => $key];
        }
        return null;
    }

    /** Thème du souvenir que retient une question de la banque. */
    public static function themeOf(string $key): string
    {
        return UserMemory::normalizeTheme((string)strstr($key, '.', true));
    }

    /**
     * Ce que la carte affiche (textes traduits dans la langue courante).
     *
     * @param array{id:int, kind:string, key:string, content?:string, message_id?:int} $q
     * @return array<string, mixed>
     */
    public static function payload(array $q): array
    {
        $base = [
            'id'         => (int)$q['id'],
            'message_id' => (int)($q['message_id'] ?? 0),
            'kind'       => (string)$q['kind'],
        ];
        if ($q['kind'] === self::KIND_INTRO) {
            // Pas une question : une information, avec le droit de refuser.
            return $base + [
                'title'       => t('MIA apprend à vous connaître'),
                'question'    => t('Désormais, MIA retient d\'elle-même ce que vous lui dites de vous et de vos préférences — votre métier, la forme de réponse que vous aimez, vos outils — pour mieux vous répondre.'),
                'quote'       => null,
                'options'     => [
                    ['value' => self::CHOICE_OK, 'label' => t('Compris')],
                    ['value' => self::CHOICE_REFUSE, 'label' => t('Ne pas apprendre de mes échanges')],
                ],
                'other'       => null,
                'placeholder' => '',
                'why'         => t('Vous gardez la main : tout est visible, modifiable et effaçable dans Réglages → Mémoire. Rien de sensible n\'est retenu, et ce que MIA déduit attend votre accord.'),
                'stop'        => false,
            ];
        }
        if ($q['kind'] === self::KIND_PROPOSAL) {
            // Née d'un geste répété : MIA dit ce qu'elle a remarqué et ce
            // qu'elle propose de faire désormais.
            $signalQuestion = MemorySignals::question((string)($q['signal'] ?? ''));
            if ($signalQuestion !== '') {
                return $base + [
                    'question'    => $signalQuestion,
                    'quote'       => (string)($q['content'] ?? '') . UserMemory::scopeSuffix((string)($q['scope'] ?? '')),
                    'options'     => [
                        ['value' => self::CHOICE_YES, 'label' => t('Oui')],
                        ['value' => self::CHOICE_NO, 'label' => t('Non merci')],
                    ],
                    'other'       => t('Corriger…'),
                    'placeholder' => t('Votre version, en une phrase'),
                ];
            }
            return $base + [
                'question'    => t('J\'ai cru comprendre ceci à votre sujet. C\'est bien ça ?'),
                'quote'       => (string)($q['content'] ?? '') . UserMemory::scopeSuffix((string)($q['scope'] ?? '')),
                'options'     => [
                    ['value' => self::CHOICE_YES, 'label' => t('Oui, c\'est ça')],
                    ['value' => self::CHOICE_NO, 'label' => t('Non')],
                ],
                'other'       => t('Corriger…'),
                'placeholder' => t('Votre version, en une phrase'),
            ];
        }

        $def = self::BANK[$q['key']] ?? null;
        if ($def === null) {
            return [];
        }
        $options = [];
        foreach ($def['options'] as $value => $option) {
            $options[] = ['value' => (string)$value, 'label' => t($option['label'])];
        }
        return $base + [
            'question'    => t($def['question']),
            'quote'       => null,
            'options'     => $options,
            'other'       => $def['other'] !== null ? ($options === [] ? '' : t('Autre…')) : null,
            'placeholder' => $q['key'] === 'profil.role' ? t('Votre métier, en quelques mots') : t('Votre réponse, en quelques mots'),
        ];
    }

    /** Une réponse libre, rendue en souvenir avec le gabarit de la question. */
    public static function otherMemory(string $key, string $text): string
    {
        $template = self::BANK[$key]['other'] ?? null;
        $text     = UserMemory::sanitize($text, self::OTHER_MAX);
        if ($template === null || $text === '') {
            return '';
        }
        return t($template, ['reponse' => rtrim($text, '. ')]);
    }

    /**
     * Ce que le navigateur reçoit (window.MIA_MEMORY_Q) : les libellés fixes de
     * la carte, traduits, et la question restée en attente à reposer.
     * memory-question.js ne contient aucun texte.
     *
     * @param array<string, mixed>|null $pending
     * @return array<string, mixed>
     */
    public static function clientConfig(?array $pending): array
    {
        return [
            'pending' => $pending,
            'labels'  => [
                'title' => t('Question de MIA'),
                'why'   => t('Votre réponse rejoint votre mémoire : vous la retrouvez dans Réglages → Mémoire.'),
                'send'  => t('Valider'),
                'skip'  => t('Passer'),
                'stop'  => t('Ne plus me poser de questions'),
                'link'  => t('Voir ma mémoire'),
                'error' => t('Opération impossible. Réessayez.'),
            ],
        ];
    }

    /**
     * Phrase montrée à la place de la carte une fois la réponse donnée, ou le
     * refus. Traduite dans la langue de la requête.
     *
     * @param array{status:string, saved?:array{kind:string, text:string}} $result
     */
    public static function message(array $result): string
    {
        $text = (string)($result['saved']['text'] ?? '');
        return match ($result['status']) {
            'not_found' => t('Question introuvable.'),
            'already'   => t('Cette question a déjà reçu une réponse.'),
            'invalid'   => t('Écrivez votre réponse, ou choisissez une option.'),
            'sensitive' => t('Cette réponse ressemble à une donnée sensible (mot de passe, code, numéro de carte, IBAN) : elle n\'est pas enregistrée.'),
            default     => match ((string)($result['saved']['kind'] ?? '')) {
                'memory'    => t('Retenu : {texte}', ['texte' => $text]),
                'style'     => t('Style de réponse : {texte}', ['texte' => $text]),
                'confirmed' => t('Confirmé : {texte}', ['texte' => $text]),
                'rejected'  => t('C\'est noté : MIA ne le retiendra pas.'),
                'skipped'   => t('Question passée.'),
                'stopped'   => t('MIA ne vous posera plus de questions. Vous pouvez changer d\'avis dans Réglages → Mémoire.'),
                'full'      => t('Mémoire pleine : rien n\'a été retenu. Faites de la place dans Réglages → Mémoire.'),
                'gone'      => t('Ce souvenir n\'attendait plus de réponse.'),
                'informed'  => t('C\'est noté. Vous retrouvez ce que MIA retient dans Réglages → Mémoire.'),
                'learn_off' => t('MIA n\'apprendra pas de vos échanges. Vous pouvez changer d\'avis dans Réglages → Mémoire.'),
                default     => t('Merci, c\'est noté.'),
            },
        };
    }

    /** Enregistre le réglage de la personne (valeur inconnue = « rarement »). */
    public static function saveFrequency(int $userId, string $freq): void
    {
        $freq = array_key_exists($freq, self::FREQUENCIES) ? $freq : self::FREQ_RARELY;
        Database::getInstance()->prepare('UPDATE users SET memory_questions = ? WHERE id = ?')->execute([$freq, $userId]);
    }

    // -----------------------------------------------------------------------
    // Fin d'un tour de chat
    // -----------------------------------------------------------------------

    /**
     * Décide, à la fin d'un tour, s'il faut poser une question, et l'inscrit.
     * Ne lève jamais : une question manquée ne coûte rien.
     *
     * @param array<string, mixed> $facts cf. turnAllows (prior_replies fourni par l'appelant)
     * @return array<string, mixed>|null charge utile de la carte
     */
    public static function forTurn(array $user, int $convId, int $messageId, array $facts): ?array
    {
        try {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0 || $convId <= 0 || $messageId <= 0 || !UserMemory::isEnabled()) {
                return null;
            }
            // La carte d'information ignore le réglage des questions : rien
            // n'est appris tant que la personne ne l'a pas vue.
            $intro = MemoryLearner::needsIntro($user);
            $freq  = self::frequencyOf($user);
            if (!$intro && (!self::isEnabled() || $freq === self::FREQ_OFF)) {
                return null;
            }
            $db = Database::getInstance();
            $facts['conversations'] = (int)self::scalar($db, 'SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$userId]);
            if (!self::turnAllows($facts)) {
                return null;
            }
            if (self::scalar($db, 'SELECT 1 FROM user_memory_questions WHERE conversation_id = ? LIMIT 1', [$convId]) !== null) {
                return null;
            }
            $since = self::scalar($db, 'SELECT TIMESTAMPDIFF(SECOND, MAX(asked_at), NOW()) FROM user_memory_questions WHERE user_id = ?', [$userId]);
            $since = $since === null ? null : (int)$since;
            if ($intro) {
                // Au plus une fois par jour, jusqu'à ce que la personne la touche.
                if ($since !== null && $since < self::GAP_BOOTSTRAP) {
                    return null;
                }
                $picked = self::pick(['intro' => true]);
            } else {
                if (!self::signalDue($since, $freq)) {
                    return null;
                }
                $counts = UserMemory::counts($userId);
                $state  = self::stateFor($db, $user);
                if (!self::dueNow($since, $freq, $counts['active'])) {
                    // Rythme ordinaire pas encore échu : seulement ce qu'un
                    // geste répété vient de faire naître.
                    $state = self::signalOnly($state);
                }
                $picked = self::pick($state);
            }
            if ($picked === null) {
                return null;
            }
            try {
                $db->prepare(
                    'INSERT INTO user_memory_questions (user_id, qkey, memory_id, conversation_id, message_id) VALUES (?, ?, ?, ?, ?)'
                )->execute([$userId, $picked['key'], $picked['memory_id'] ?? null, $convId, $messageId]);
            } catch (\PDOException $e) {
                // Une autre question vient d'être posée dans cette conversation.
                if ((string)$e->getCode() === '23000') {
                    return null;
                }
                throw $e;
            }
            return self::payload($picked + ['id' => (int)$db->lastInsertId(), 'message_id' => $messageId]);
        } catch (\Throwable $e) {
            error_log('[Mémoire] question non posée (conversation ' . $convId . ') : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Question sans réponse de cette conversation, à reposer au rechargement
     * — à l'appelant de vérifier que sa réponse est toujours la dernière.
     *
     * @return array<string, mixed>|null charge utile (avec `message_id`)
     */
    public static function pendingFor(int $userId, int $convId): ?array
    {
        try {
            if (!UserMemory::isEnabled()) {
                return null;
            }
            $stmt = Database::getInstance()->prepare(
                'SELECT q.id, q.qkey, q.memory_id, q.message_id, m.content AS memory_content, m.status AS memory_status,
                        m.signal_key AS memory_signal, m.scope AS memory_scope
                   FROM user_memory_questions q
                   LEFT JOIN user_memories m ON m.id = q.memory_id AND m.user_id = q.user_id
                  WHERE q.user_id = ? AND q.conversation_id = ? AND q.answered_at IS NULL
                  LIMIT 1'
            );
            $stmt->execute([$userId, $convId]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['message_id'] <= 0) {
                return null;
            }
            if ($row['qkey'] === self::INTRO_KEY) {
                // Informée entre-temps (page Mémoire) : plus rien à montrer.
                if (!MemoryLearner::needsIntro(User::findById($userId))) {
                    return null;
                }
                return self::payload(['id' => (int)$row['id'], 'kind' => self::KIND_INTRO, 'key' => self::INTRO_KEY,
                    'message_id' => (int)$row['message_id']]);
            }
            if (!self::isEnabled()) {
                return null;
            }
            if ($row['qkey'] === self::PROPOSAL_KEY) {
                // Confirmée ou refusée entre-temps (page Mémoire) : plus rien à demander.
                if (($row['memory_status'] ?? '') !== UserMemory::STATUS_PROPOSED) {
                    return null;
                }
                return self::payload(['id' => (int)$row['id'], 'kind' => self::KIND_PROPOSAL, 'key' => self::PROPOSAL_KEY,
                    'content' => (string)$row['memory_content'], 'message_id' => (int)$row['message_id'],
                    'signal' => (string)($row['memory_signal'] ?? ''), 'scope' => (string)($row['memory_scope'] ?? '')]);
            }
            if (!isset(self::BANK[$row['qkey']])) {
                return null;
            }
            return self::payload(['id' => (int)$row['id'], 'kind' => self::KIND_BANK, 'key' => (string)$row['qkey'],
                'message_id' => (int)$row['message_id']]);
        } catch (\Throwable $e) {
            error_log('[Mémoire] question en attente illisible (conversation ' . $convId . ') : ' . $e->getMessage());
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Réponse de la personne
    // -----------------------------------------------------------------------

    /**
     * Applique la réponse, une seule fois.
     *
     * @return array{status:string, saved?:array{kind:string, text:string}}
     *   status : ok | not_found | already | invalid | sensitive
     */
    public static function answer(int $userId, int $id, string $choice, string $text = ''): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id, qkey, memory_id, conversation_id, answered_at FROM user_memory_questions WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['status' => 'not_found'];
        }
        if ($row['answered_at'] !== null) {
            return ['status' => 'already'];
        }

        $key      = (string)$row['qkey'];
        if ($key === self::INTRO_KEY) {
            return self::answerIntro($userId, $id, $choice);
        }
        $proposal = $key === self::PROPOSAL_KEY;
        $def      = $proposal ? null : (self::BANK[$key] ?? null);
        if (!$proposal && $def === null) {
            return ['status' => 'invalid'];
        }

        // Ce que la réponse va faire, validé AVANT de la consigner.
        $effect = null;
        $memory = '';
        $outcome = self::OUTCOME_ANSWERED;
        if ($choice === self::CHOICE_SKIP) {
            $outcome = self::OUTCOME_SKIPPED;
        } elseif ($choice === self::CHOICE_STOP) {
            $outcome = self::OUTCOME_STOPPED;
        } elseif ($choice === self::CHOICE_OTHER) {
            $memory = $proposal
                ? UserMemory::sanitize($text, self::OTHER_MAX)
                : self::otherMemory($key, $text);
            if ($memory === '') {
                return ['status' => 'invalid'];
            }
            if (UserMemory::looksSensitive($memory)) {
                return ['status' => 'sensitive'];
            }
            $effect = $proposal ? 'correct' : 'memory';
        } elseif ($proposal) {
            if (!in_array($choice, [self::CHOICE_YES, self::CHOICE_NO], true)) {
                return ['status' => 'invalid'];
            }
            $effect = $choice === self::CHOICE_YES ? 'confirm' : 'reject';
        } else {
            $option = $def['options'][$choice] ?? null;
            if ($option === null) {
                return ['status' => 'invalid'];
            }
            if (isset($option['style'])) {
                $effect = 'style';
                $memory = (string)$option['style'];
            } elseif (isset($option['memoire'])) {
                $effect = 'memory';
                $memory = t($option['memoire']);
            } else {
                $effect = 'none';
            }
        }

        // Une seule réponse : la première qui arrive consigne, les autres voient « already ».
        $claim = $db->prepare('UPDATE user_memory_questions SET answered_at = NOW(), outcome = ? WHERE id = ? AND user_id = ? AND answered_at IS NULL');
        $claim->execute([$outcome, $id, $userId]);
        if ($claim->rowCount() === 0) {
            return ['status' => 'already'];
        }

        if ($outcome === self::OUTCOME_SKIPPED) {
            return ['status' => 'ok', 'saved' => ['kind' => 'skipped', 'text' => '']];
        }
        if ($outcome === self::OUTCOME_STOPPED) {
            $db->prepare('UPDATE users SET memory_questions = ? WHERE id = ?')->execute([self::FREQ_OFF, $userId]);
            return ['status' => 'ok', 'saved' => ['kind' => 'stopped', 'text' => '']];
        }

        $convId   = $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null;
        $memoryId = $row['memory_id'] !== null ? (int)$row['memory_id'] : 0;
        switch ($effect) {
            case 'style':
                UserPreferences::save($userId, $memory, null);
                $label = UserPreferences::styles()[$memory]['label'] ?? $memory;
                return ['status' => 'ok', 'saved' => ['kind' => 'style', 'text' => (string)$label]];
            case 'memory':
                $res = UserMemory::add($userId, $memory, [
                    'source'          => UserMemory::SOURCE_QUESTION,
                    'theme'           => self::themeOf($key),
                    'conversation_id' => $convId,
                ]);
                return ['status' => 'ok', 'saved' => ['kind' => $res['status'] === 'full' ? 'full' : 'memory', 'text' => $res['content']]];
            case 'confirm':
                $res = $memoryId > 0 ? UserMemory::confirm($userId, $memoryId) : ['status' => 'not_found', 'content' => ''];
                return ['status' => 'ok', 'saved' => ['kind' => $res['status'] === 'ok' ? 'confirmed' : ($res['status'] === 'full' ? 'full' : 'gone'), 'text' => $res['content']]];
            case 'reject':
                if ($memoryId > 0) {
                    UserMemory::reject($userId, $memoryId);
                }
                return ['status' => 'ok', 'saved' => ['kind' => 'rejected', 'text' => '']];
            case 'correct':
                $res = $memoryId > 0 ? UserMemory::update($userId, $memoryId, $memory, null, true) : ['status' => 'not_found', 'content' => $memory];
                if ($res['status'] === 'not_found') {
                    // La proposition a disparu entre-temps : la réponse de la
                    // personne vaut quand même souvenir.
                    $res = UserMemory::add($userId, $memory, ['source' => UserMemory::SOURCE_QUESTION, 'conversation_id' => $convId]);
                }
                return ['status' => 'ok', 'saved' => ['kind' => $res['status'] === 'full' ? 'full' : 'memory', 'text' => $res['content']]];
            default:
                return ['status' => 'ok', 'saved' => ['kind' => 'none', 'text' => '']];
        }
    }

    /**
     * Carte d'information : « Compris » et × gardent l'apprentissage, « Ne pas
     * apprendre de mes échanges » le coupe (et vide ce qui attendait). Dans
     * les trois cas, la personne est informée : la carte ne revient pas.
     *
     * @return array{status:string, saved?:array{kind:string, text:string}}
     */
    private static function answerIntro(int $userId, int $id, string $choice): array
    {
        if (!in_array($choice, [self::CHOICE_OK, self::CHOICE_REFUSE, self::CHOICE_SKIP], true)) {
            return ['status' => 'invalid'];
        }
        $outcome = $choice === self::CHOICE_SKIP ? self::OUTCOME_SKIPPED : self::OUTCOME_ANSWERED;
        $claim   = Database::getInstance()->prepare(
            'UPDATE user_memory_questions SET answered_at = NOW(), outcome = ? WHERE id = ? AND user_id = ? AND answered_at IS NULL'
        );
        $claim->execute([$outcome, $id, $userId]);
        if ($claim->rowCount() === 0) {
            return ['status' => 'already'];
        }
        MemoryLearner::markInformed($userId);
        if ($choice === self::CHOICE_REFUSE) {
            MemoryLearner::saveLearn($userId, false);
            return ['status' => 'ok', 'saved' => ['kind' => 'learn_off', 'text' => '']];
        }
        return ['status' => 'ok', 'saved' => ['kind' => $choice === self::CHOICE_SKIP ? 'skipped' : 'informed', 'text' => '']];
    }

    // -----------------------------------------------------------------------
    // Interne
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> état lu en base pour pick() */
    private static function stateFor(PDO $db, array $user): array
    {
        $userId = (int)$user['id'];

        $stmt = $db->prepare(
            'SELECT m.id, m.content, m.hits, m.signal_key, m.scope,
                    (SELECT TIMESTAMPDIFF(SECOND, MAX(q.asked_at), NOW())
                       FROM user_memory_questions q WHERE q.memory_id = m.id) AS since
               FROM user_memories m
              WHERE m.user_id = ? AND m.status = ? AND m.hits >= ?
              ORDER BY m.id ASC'
        );
        $stmt->execute([$userId, UserMemory::STATUS_PROPOSED, self::PROPOSAL_MIN_HITS]);
        $proposals = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $proposals[] = [
                'id'      => (int)$row['id'],
                'content' => (string)$row['content'],
                'hits'    => (int)$row['hits'],
                'since'   => $row['since'] === null ? null : (int)$row['since'],
                'signal'  => (string)($row['signal_key'] ?? ''),
                'scope'   => (string)($row['scope'] ?? ''),
            ];
        }

        $stmt = $db->prepare(
            'SELECT qkey, MAX(outcome = ?) AS answered, TIMESTAMPDIFF(SECOND, MAX(asked_at), NOW()) AS since
               FROM user_memory_questions WHERE user_id = ? GROUP BY qkey'
        );
        $stmt->execute([self::OUTCOME_ANSWERED, $userId]);
        $bank = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $bank[(string)$row['qkey']] = [
                'answered' => (int)$row['answered'] === 1,
                'since'    => $row['since'] === null ? null : (int)$row['since'],
            ];
        }

        $profileMemories = self::scalar($db,
            'SELECT COUNT(*) FROM user_memories WHERE user_id = ? AND status = ? AND theme = ?',
            [$userId, UserMemory::STATUS_ACTIVE, 'profil']);

        return [
            'proposals'     => $proposals,
            'bank'          => $bank,
            'style_empty'   => trim((string)($user['ai_style'] ?? '')) === '',
            'has_connector' => self::scalar($db, 'SELECT 1 FROM user_drive_tokens WHERE user_id = ? AND status = \'ok\' LIMIT 1', [$userId]) !== null,
            'profile_empty' => (int)$profileMemories === 0 && !UserPreferences::hasContext($user),
        ];
    }

    private static function scalar(PDO $db, string $sql, array $params): mixed
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }
}
