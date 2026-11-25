<?php

declare(strict_types=1);

/**
 * Mémoire — apprendre des GESTES (lot 1 de « mieux répondre au fil de ce
 * qu'on apprend »).
 *
 * MemoryLearner retient ce que la personne DIT d'elle. Ici, MIA remarque ce
 * qu'elle FAIT : elle redemande « plus court » après une réponse, passe un
 * brouillon d'email au vouvoiement, clique « Plus formel » sur la carte de
 * relecture. Un geste répété — au moins MIN_HITS fois, dans au moins
 * MIN_CONVERSATIONS conversations, sur WINDOW_DAYS jours — devient une
 * PROPOSITION à confirmer (« Vous me demandez souvent de raccourcir mes
 * emails. Je fais plus court d'office désormais ? »), posée par la carte des
 * questions de MIA (MemoryQuestions). Rien ne s'applique sans « oui ».
 *
 * Trois règles :
 *   - AUCUN appel au modèle, AUCUN texte gardé : un geste est reconnu par des
 *     motifs fixes ou une comparaison faite en PHP, et compté (geste, portée,
 *     référence, conversation) dans `memory_signal_events` ;
 *   - seulement ce que la personne écrit ou retouche elle-même : jamais un
 *     contenu lu par un connecteur (email reçu, document) ;
 *   - mêmes portes que l'apprentissage (MemoryLearner::activeFor) : l'option
 *     d'administration, le choix « Ne pas apprendre », la carte d'information,
 *     les comptes de démonstration.
 *
 * La PORTÉE dit à quoi s'applique la préférence : `all` (les réponses en
 * général), `email` (un brouillon d'email), `document` (un fichier livré),
 * `code` — lue dans la réponse que la personne retouche (scopeOfReply).
 */
final class MemorySignals
{
    /** Un geste devient une proposition au bout de… */
    public const MIN_HITS = 3;
    /** … dans au moins ce nombre de conversations différentes… */
    public const MIN_CONVERSATIONS = 2;
    /** … sur cette période (jours). */
    public const WINDOW_DAYS = 60;

    /** Gestes gardés au plus (jours) : au-delà, purgés par l'entretien de la mémoire. */
    public const RETENTION_DAYS = 90;

    /** Une demande de retouche est courte : au-delà, c'est une nouvelle demande. */
    public const FOLLOW_UP_MAX = 160;

    /** Gestes reconnus dans un même message, au plus. */
    public const MAX_PER_MESSAGE = 2;

    /** Brouillon d'email raccourci à la main : au moins ce corps d'origine… */
    public const MAIL_MIN_CHARS = 200;
    /** … ramené à au plus cette part de sa longueur. */
    public const MAIL_SHORTER_RATIO = 0.75;

    public const SCOPE_ALL      = 'all';
    public const SCOPE_EMAIL    = 'email';
    public const SCOPE_DOCUMENT = 'document';
    public const SCOPE_CODE     = 'code';

    /**
     * Gestes reconnus : thème du souvenir, portées où il a un sens, geste
     * contraire, et le souvenir proposé (clé de traduction, une phrase
     * déclarative comme celles de l'apprentissage).
     */
    public const GESTURES = [
        'shorter' => [
            'scopes'   => ['all', 'email', 'document', 'code'],
            'opposite' => 'longer',
            'memory'   => 'Préfère des réponses plus courtes',
        ],
        'longer' => [
            'scopes'   => ['all', 'email', 'document', 'code'],
            'opposite' => 'shorter',
            'memory'   => 'Préfère des réponses plus détaillées',
        ],
        'direct' => [
            'scopes'   => ['all', 'email'],
            'opposite' => null,
            'memory'   => 'Préfère qu\'on aille droit au but',
        ],
        'warm' => [
            'scopes'   => ['all', 'email'],
            'opposite' => null,
            'memory'   => 'Préfère un ton chaleureux',
        ],
        'formal' => [
            'scopes'   => ['all', 'email', 'document'],
            'opposite' => 'casual',
            'memory'   => 'Préfère un ton formel',
        ],
        'casual' => [
            'scopes'   => ['all', 'email', 'document'],
            'opposite' => 'formal',
            'memory'   => 'Préfère un ton détendu',
        ],
        'simpler' => [
            'scopes'   => ['all', 'document', 'code'],
            'opposite' => null,
            'memory'   => 'Préfère des explications simples, sans jargon',
        ],
        'table' => [
            'scopes'   => ['all', 'document'],
            'opposite' => null,
            'memory'   => 'Préfère les tableaux pour présenter comparaisons et données',
        ],
        'bullets' => [
            'scopes'   => ['all', 'email', 'document'],
            'opposite' => null,
            'memory'   => 'Préfère les listes à puces aux longs paragraphes',
        ],
        'vouvoiement' => [
            'scopes'   => ['email'],
            'opposite' => 'tutoiement',
            'memory'   => 'Vouvoie ses correspondants',
        ],
        'tutoiement' => [
            'scopes'   => ['email'],
            'opposite' => 'vouvoiement',
            'memory'   => 'Tutoie ses correspondants',
        ],
    ];

    /** Ce que MIA a remarqué (clés de traduction ; {quoi} = SCOPE_OBJECTS). */
    public const REASONS = [
        'shorter'     => 'Vous me demandez souvent de raccourcir {quoi}.',
        'longer'      => 'Vous me demandez souvent plus de détails dans {quoi}.',
        'direct'      => 'Vous me demandez souvent d\'aller plus droit au but dans {quoi}.',
        'warm'        => 'Vous me demandez souvent un ton plus chaleureux dans {quoi}.',
        'formal'      => 'Vous me demandez souvent un ton plus formel dans {quoi}.',
        'casual'      => 'Vous me demandez souvent un ton plus détendu dans {quoi}.',
        'simpler'     => 'Vous me demandez souvent plus de simplicité dans {quoi}.',
        'table'       => 'Vous me demandez souvent de présenter {quoi} en tableau.',
        'bullets'     => 'Vous me demandez souvent des listes à puces dans {quoi}.',
        'vouvoiement' => 'Vous passez souvent mes emails au vouvoiement.',
        'tutoiement'  => 'Vous passez souvent mes emails au tutoiement.',
    ];

    /** Ce que MIA propose de faire désormais (clés de traduction). */
    public const QUESTIONS = [
        'shorter'     => 'Je fais plus court d\'office désormais ?',
        'longer'      => 'Je développe davantage d\'office désormais ?',
        'direct'      => 'Je vais droit au but d\'office désormais ?',
        'warm'        => 'Je prends un ton plus chaleureux d\'office désormais ?',
        'formal'      => 'Je prends un ton plus formel d\'office désormais ?',
        'casual'      => 'Je prends un ton plus détendu d\'office désormais ?',
        'simpler'     => 'J\'évite le jargon d\'office désormais ?',
        'table'       => 'Je présente comparaisons et données en tableau d\'office désormais ?',
        'bullets'     => 'J\'utilise davantage les listes à puces désormais ?',
        'vouvoiement' => 'Je vouvoie vos correspondants par défaut désormais ?',
        'tutoiement'  => 'Je tutoie vos correspondants par défaut désormais ?',
    ];

    /** Objet de la portée dans les phrases ci-dessus (clés de traduction). */
    public const SCOPE_OBJECTS = [
        'all'      => 'mes réponses',
        'email'    => 'mes emails',
        'document' => 'mes documents',
        'code'     => 'mon code',
    ];

    /** Préréglage « Améliorer » d'un brouillon d'email → geste (MailDraftReview::PRESETS). */
    public const PRESET_GESTURES = [
        'plus_court'      => 'shorter',
        'plus_formel'     => 'formal',
        'plus_chaleureux' => 'warm',
        'plus_direct'     => 'direct',
        'plus_detaille'   => 'longer',
    ];

    /**
     * Motifs d'une demande de retouche, FR et EN. Mots entiers ; un geste qui
     * n'en reconnaît aucun ne compte pas (une retouche manquée ne coûte rien,
     * une retouche inventée finirait en question inutile).
     */
    private const PATTERNS = [
        'shorter' => [
            '/\bplus\s+(?:courte?s?|concise?s?|brefs?|br[eè]ves?|synth[ée]tiques?|succinc?te?s?)\b/iu',
            '/\braccourci(?:s|t|ssez|r|e|es)?\b/iu',
            '/\btrop\s+(?:longs?|longues?|d[ée]taill[ée]e?s?|verbeu(?:x|ses?))\b/iu',
            '/\bmoins\s+(?:longs?|longues?|d[ée]taill[ée]e?s?|verbeu(?:x|ses?)|de\s+d[ée]tails)\b/iu',
            '/\ben\s+(?:moins\s+de|deux|trois|quelques)\s+(?:mots|lignes|phrases)\b/iu',
            '/\b(?:shorter|briefer|more\s+concise|shorten(?:\s+it)?|too\s+long|less\s+detail(?:ed)?|fewer\s+words)\b/iu',
        ],
        'longer' => [
            '/\bplus\s+(?:longs?|longues?|d[ée]taill[ée]e?s?|compl[eè]te?s?|approfondie?s?|d[ée]velopp[ée]e?s?)\b/iu',
            '/\bd[ée]veloppe(?:s|z|r)?\b/iu',
            '/\bplus\s+de\s+d[ée]tails?\b/iu',
            '/\btrop\s+(?:courte?s?|succinc?te?s?)\b/iu',
            '/\b(?:longer|more\s+detail(?:s|ed)?|more\s+thorough|elaborate|expand\s+on\s+(?:it|that|this)|too\s+short)\b/iu',
        ],
        'direct' => [
            '/\bplus\s+directe?s?\b/iu',
            '/\bdroit\s+au\s+but\b/iu',
            '/\b(?:va|allez)\s+[àa]\s+l[\'’]essentiel\b/iu',
            '/\bsans\s+(?:d[ée]tours?|blabla|fioritures?)\b/iu',
            '/\b(?:more\s+direct|get\s+to\s+the\s+point|straight\s+to\s+the\s+point|cut\s+to\s+the\s+chase)\b/iu',
        ],
        'warm' => [
            '/\bplus\s+(?:chaleureu(?:x|ses?)|humaine?s?|sympa(?:thique)?s?|amicale?s?|amicaux|aimables?)\b/iu',
            '/\b(?:warmer|friendlier|more\s+friendly|more\s+human)\b/iu',
        ],
        'formal' => [
            '/\bplus\s+(?:formel(?:le)?s?|professionnel(?:le)?s?|soutenue?s?|solennel(?:le)?s?|courtoise?s?)\b/iu',
            '/\bmoins\s+(?:familiers?|famili[eè]res?|d[ée]contract[ée]e?s?)\b/iu',
            '/\b(?:more\s+formal|more\s+professional|less\s+casual)\b/iu',
        ],
        'casual' => [
            '/\bmoins\s+(?:formel(?:le)?s?|guind[ée]e?s?|rigides?|solennel(?:le)?s?|corporate)\b/iu',
            '/\bplus\s+(?:d[ée]contract[ée]e?s?|d[ée]tendue?s?|familiers?|famili[eè]res?|naturel(?:le)?s?|informel(?:le)?s?)\b/iu',
            '/\b(?:less\s+formal|more\s+casual|more\s+relaxed|more\s+informal)\b/iu',
        ],
        'simpler' => [
            '/\bplus\s+(?:simples?|simplement|claire?s?|accessibles?|p[ée]dagogiques?)\b/iu',
            '/\bsimplifie(?:s|z|r)?\b/iu',
            '/\bsans\s+jargon\b/iu',
            '/\bmoins\s+techniques?\b/iu',
            '/\bvulgaris(?:e|es|ez|er)\b/iu',
            '/\b(?:simpler|simplify|plain\s+(?:english|language)|less\s+technical|no\s+jargon)\b/iu',
        ],
        'table' => [
            '/\b(?:en|sous\s+forme\s+de|dans\s+un)\s+tableau\b/iu',
            '/\bfais[\-\s]en\s+un\s+tableau\b/iu',
            '/\b(?:in\s+a\s+table|as\s+a\s+table|in\s+table\s+form|make\s+(?:it|that|this)\s+a\s+table)\b/iu',
        ],
        'bullets' => [
            '/\b(?:en|sous\s+forme\s+de)\s+(?:listes?|puces|points)\b/iu',
            '/\b[àa]\s+puces\b/iu',
            '/\b(?:bullet\s+points?|bulleted|as\s+a\s+list|in\s+a\s+list|as\s+bullets)\b/iu',
        ],
        'vouvoiement' => [
            '/\bvouvoi(?:e|es|ez|er|ement)\b/iu',
        ],
        'tutoiement' => [
            '/\btutoi(?:e|es|ez|er|ement)\b/iu',
        ],
    ];

    /**
     * Une négation : le geste n'est pas compté (« pas trop long », « ne
     * raccourcis pas »). Prudent : mieux vaut un geste manqué.
     */
    private const NEGATION = '/\b(?:pas|ne|jamais|not|never|dont)\b|\bn[\'’]|n[\'’]t\b/iu';

    /**
     * Une demande DURABLE (« désormais plus court », « from now on ») : c'est
     * une préférence dite, que la capture explicite et l'apprentissage
     * retiennent déjà — la compter ici la proposerait une seconde fois.
     */
    private const DURABLE = '/\b(?:d[ée]sormais|dor[ée]navant|[àa]\s+l[\'’]avenir|toujours|la\s+prochaine\s+fois|[àa]\s+chaque\s+fois|from\s+now\s+on|always|next\s+time|every\s+time|in\s+the\s+future)\b/iu';

    /** Extensions d'un fichier livré qui en font du code. */
    private const CODE_EXTENSIONS = [
        'py', 'js', 'mjs', 'ts', 'tsx', 'jsx', 'php', 'java', 'kt', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rb', 'rs',
        'swift', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'sql', 'r', 'scala', 'pl', 'lua', 'vb', 'vbs', 'css', 'scss',
    ];

    /** Langages d'un bloc de code (sans nom de fichier) qui font d'une réponse du code. */
    private const CODE_LANGUAGES = [
        'python', 'py', 'javascript', 'js', 'typescript', 'ts', 'php', 'java', 'kotlin', 'c', 'cpp', 'c++', 'csharp', 'cs',
        'go', 'golang', 'ruby', 'rb', 'rust', 'rs', 'swift', 'bash', 'sh', 'shell', 'zsh', 'powershell', 'ps1', 'bat',
        'sql', 'r', 'scala', 'perl', 'lua', 'vb', 'vba', 'html', 'css', 'scss', 'jsx', 'tsx', 'dockerfile', 'yaml', 'yml',
    ];

    // -----------------------------------------------------------------------
    // Formes pures
    // -----------------------------------------------------------------------

    /** Le geste a-t-il un sens dans cette portée ? */
    public static function allowed(string $gesture, string $scope): bool
    {
        return in_array($scope, self::GESTURES[$gesture]['scopes'] ?? [], true);
    }

    public static function signalKey(string $gesture, string $scope): string
    {
        return $gesture . ':' . $scope;
    }

    /** @return array{0:string, 1:string}|null [geste, portée] d'une clé connue */
    public static function parseKey(string $key): ?array
    {
        $parts = explode(':', $key, 2);
        if (count($parts) !== 2 || !self::allowed($parts[0], $parts[1])) {
            return null;
        }
        return [$parts[0], $parts[1]];
    }

    /** Clé de la préférence contraire de même portée, ou null. */
    public static function oppositeKey(string $key): ?string
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return null;
        }
        $opposite = self::GESTURES[$parsed[0]]['opposite'] ?? null;
        return $opposite !== null && self::allowed($opposite, $parsed[1]) ? self::signalKey($opposite, $parsed[1]) : null;
    }

    /**
     * Un geste mérite-t-il une proposition ? Assez répété, dans assez de
     * conversations, et pas contredit par le geste inverse (une personne qui
     * fait tantôt raccourcir, tantôt développer n'a pas de préférence).
     */
    public static function isDue(int $hits, int $conversations, int $oppositeHits = 0): bool
    {
        return $hits >= self::MIN_HITS
            && $conversations >= self::MIN_CONVERSATIONS
            && $oppositeHits * 2 < $hits;
    }

    /**
     * Gestes reconnus dans un texte de la personne (une demande de retouche,
     * une consigne d'amélioration). Rien sur une négation ni sur une demande
     * durable ; deux gestes contraires s'annulent ; MAX_PER_MESSAGE au plus.
     *
     * @return list<string>
     */
    public static function detect(string $text): array
    {
        $text = trim($text);
        if ($text === '' || preg_match(self::NEGATION, $text) === 1 || preg_match(self::DURABLE, $text) === 1) {
            return [];
        }
        $found = [];
        foreach (self::PATTERNS as $gesture => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $found[] = $gesture;
                    break;
                }
            }
        }
        foreach ($found as $gesture) {
            $opposite = self::GESTURES[$gesture]['opposite'];
            if ($opposite !== null && in_array($opposite, $found, true)) {
                return [];
            }
        }
        return array_slice($found, 0, self::MAX_PER_MESSAGE);
    }

    /**
     * Gestes d'un message qui suit une réponse : une demande COURTE qui
     * retouche ce qui vient d'être écrit. Au-delà de FOLLOW_UP_MAX caractères,
     * c'est une nouvelle demande.
     *
     * @return list<string>
     */
    public static function followUpGestures(string $message): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > self::FOLLOW_UP_MAX) {
            return [];
        }
        return self::detect($message);
    }

    /**
     * Portée d'une réponse de MIA (ligne `messages`) : un brouillon d'email
     * préparé (trace d'actions), un fichier livré (code ou document), une
     * réponse faite surtout de code, sinon les réponses en général.
     */
    public static function scopeOfReply(array $row): string
    {
        $actions = json_decode((string)($row['actions_json'] ?? ''), true);
        if (is_array($actions)) {
            foreach ($actions as $entry) {
                if (is_array($entry) && MailDraftReview::isMailTool((string)($entry['t'] ?? ''))) {
                    return self::SCOPE_EMAIL;
                }
            }
        }

        $content = (string)($row['content'] ?? '');
        if (preg_match_all('/^```[^\n]*?\b(?:fichier|file|filename)\s*=\s*["\']?([^"\'\s`]+)/imu', $content, $files) > 0) {
            foreach ($files[1] as $name) {
                $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
                if (!in_array($ext, self::CODE_EXTENSIONS, true)) {
                    return self::SCOPE_DOCUMENT;
                }
            }
            return self::SCOPE_CODE;
        }

        $total = mb_strlen(trim($content));
        if ($total > 0 && preg_match_all('/^```([\w+#\-]*)[^\n]*\n(.*?)^```/msu', $content, $blocks, PREG_SET_ORDER) > 0) {
            $code = 0;
            foreach ($blocks as $block) {
                $lang = strtolower(trim($block[1]));
                if ($lang === '' || in_array($lang, self::CODE_LANGUAGES, true)) {
                    $code += mb_strlen($block[2]);
                }
            }
            if ($code > 0 && $code >= 0.4 * $total) {
                return self::SCOPE_CODE;
            }
        }
        return self::SCOPE_ALL;
    }

    /**
     * Ce qu'une retouche à la main d'un brouillon d'email révèle : un corps
     * nettement raccourci, un passage du tutoiement au vouvoiement (ou
     * l'inverse). Rien sur un ajout de contenu : écrire plus, c'est souvent
     * ajouter un fait, pas une préférence.
     *
     * @return list<string>
     */
    public static function mailEditGestures(string $before, string $after): array
    {
        $before = trim((string)preg_replace('/\s+/u', ' ', $before));
        $after  = trim((string)preg_replace('/\s+/u', ' ', $after));
        if ($before === '' || $after === '' || $before === $after) {
            return [];
        }
        $out = [];
        $lb  = mb_strlen($before);
        if ($lb >= self::MAIL_MIN_CHARS && mb_strlen($after) <= self::MAIL_SHORTER_RATIO * $lb) {
            $out[] = 'shorter';
        }
        [$tuB, $vousB] = self::addressCounts($before);
        [$tuA, $vousA] = self::addressCounts($after);
        if ($tuB >= 2 && $vousB === 0 && $vousA >= 2 && $tuA === 0) {
            $out[] = 'vouvoiement';
        } elseif ($vousB >= 2 && $tuB === 0 && $tuA >= 2 && $vousA === 0) {
            $out[] = 'tutoiement';
        }
        return $out;
    }

    /** Geste d'un préréglage « Améliorer », ou null (« Corriger la langue »). */
    public static function presetGesture(string $preset): ?string
    {
        return self::PRESET_GESTURES[$preset] ?? null;
    }

    /** Le souvenir proposé (traduit dans la langue courante). */
    public static function memoryText(string $gesture): string
    {
        return t(self::GESTURES[$gesture]['memory'] ?? '');
    }

    /** Ce que MIA a remarqué, pour une clé « geste:portée » (traduit), ou ''. */
    public static function reason(string $key): string
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return '';
        }
        return t(self::REASONS[$parsed[0]], ['quoi' => t(self::SCOPE_OBJECTS[$parsed[1]])]);
    }

    /** La question : ce que MIA a remarqué, puis ce qu'elle propose (traduit), ou ''. */
    public static function question(string $key): string
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return '';
        }
        return self::reason($key) . ' ' . t(self::QUESTIONS[$parsed[0]]);
    }

    // -----------------------------------------------------------------------
    // Enregistrement
    // -----------------------------------------------------------------------

    /**
     * Une demande de retouche après une réponse (fin d'un tour de chat).
     * `$priorHistory` = messages ANTÉRIEURS au message de la personne : le
     * dernier doit être une vraie réponse de MIA. Ne lève jamais.
     *
     * @param list<array> $priorHistory
     */
    public static function fromFollowUp(array $user, int $convId, int $userMsgId, string $content, array $priorHistory): void
    {
        try {
            if ($convId <= 0 || $userMsgId <= 0 || !MemoryLearner::activeFor($user)) {
                return;
            }
            $gestures = self::followUpGestures($content);
            if ($gestures === []) {
                return;
            }
            $previous = $priorHistory === [] ? null : $priorHistory[array_key_last($priorHistory)];
            if (!is_array($previous) || !FollowUpSuggestion::isEligibleReply($previous)) {
                return;
            }
            $scope = self::scopeOfReply($previous);
            foreach ($gestures as $gesture) {
                if (self::allowed($gesture, $scope)) {
                    self::record($user, $gesture, $scope, 'msg:' . $userMsgId, $convId);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Mémoire] gestes non comptés (conversation ' . $convId . ') : ' . $e->getMessage());
        }
    }

    /**
     * Une révision de la carte de relecture d'un email : retouche à la main
     * (corps comparé avant / après), préréglage ou consigne d'amélioration.
     * Une même carte ne compte qu'une fois par geste. « Version précédente »
     * ne compte pas. Ne lève jamais.
     *
     * @param array<string, mixed> $before arguments du brouillon avant la révision
     * @param array<string, mixed> $edited arguments après les champs de l'éditeur (avant amélioration)
     * @param array<string, mixed> $post   formulaire reçu (preset, instruction, restore)
     */
    public static function fromMailRevision(array $user, int $convId, int $actionId, array $before, array $edited, string $mode, array $post): void
    {
        try {
            if ($actionId <= 0 || !empty($post['restore']) || !MemoryLearner::activeFor($user)) {
                return;
            }
            $gestures = array_key_exists('corps', $post)
                ? self::mailEditGestures((string)($before['corps'] ?? ''), (string)($edited['corps'] ?? ''))
                : [];
            if ($mode === 'improve') {
                $preset = trim((string)($post['preset'] ?? ''));
                if ($preset !== '') {
                    $gesture = self::presetGesture($preset);
                    if ($gesture !== null) {
                        $gestures[] = $gesture;
                    }
                } else {
                    $gestures = array_merge($gestures, self::detect((string)($post['instruction'] ?? '')));
                }
            }
            foreach (array_unique($gestures) as $gesture) {
                if (self::allowed($gesture, self::SCOPE_EMAIL)) {
                    self::record($user, $gesture, self::SCOPE_EMAIL, 'mail:' . $actionId, $convId > 0 ? $convId : null);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Mémoire] gestes d\'un brouillon non comptés (action ' . $actionId . ') : ' . $e->getMessage());
        }
    }

    /**
     * Compte un geste et, s'il est assez répété, crée la proposition « à
     * confirmer ». Une référence (« msg:… », « mail:… ») ne compte qu'une fois
     * par geste. Jamais reproposé : une proposition en attente, acceptée,
     * refusée ou retirée (empreinte) porte la même clé. Rend l'id de la
     * proposition créée, ou null. Ne lève jamais.
     */
    public static function record(array $user, string $gesture, string $scope, string $ref, ?int $convId = null): ?int
    {
        try {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0 || !self::allowed($gesture, $scope) || trim($ref) === '' || !MemoryLearner::activeFor($user)) {
                return null;
            }
            $db  = Database::getInstance();
            $ins = $db->prepare(
                'INSERT IGNORE INTO memory_signal_events (user_id, gesture, scope, ref, conversation_id) VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$userId, $gesture, $scope, mb_substr($ref, 0, 40), $convId !== null && $convId > 0 ? $convId : null]);
            if ($ins->rowCount() === 0) {
                return null; // déjà compté pour cette référence
            }

            $key   = self::signalKey($gesture, $scope);
            $known = $db->prepare('SELECT 1 FROM user_memories WHERE user_id = ? AND signal_key = ? LIMIT 1');
            $known->execute([$userId, $key]);
            if ($known->fetchColumn() !== false) {
                return null;
            }

            [$hits, $conversations] = self::counts($userId, $gesture, $scope);
            $opposite     = self::GESTURES[$gesture]['opposite'];
            $oppositeHits = $opposite !== null && self::allowed($opposite, $scope) ? self::counts($userId, $opposite, $scope)[0] : 0;
            if (!self::isDue($hits, $conversations, $oppositeHits)) {
                return null;
            }

            $res = UserMemory::add($userId, self::memoryText($gesture), [
                'source'          => UserMemory::SOURCE_AUTO,
                'status'          => UserMemory::STATUS_PROPOSED,
                'theme'           => 'reponses',
                'scope'           => $scope,
                'signal_key'      => $key,
                'hits'            => min(255, $hits),
                'conversation_id' => $convId,
            ]);
            return $res['status'] === 'ok' ? (int)($res['id'] ?? 0) : null;
        } catch (\Throwable $e) {
            error_log('[Mémoire] geste non compté (' . $gesture . ':' . $scope . ') : ' . $e->getMessage());
            return null;
        }
    }

    /** Gestes plus anciens que RETENTION_DAYS (entretien quotidien de la mémoire). */
    public static function purge(): void
    {
        Database::getInstance()->exec(
            'DELETE FROM memory_signal_events WHERE created_at < NOW() - INTERVAL ' . self::RETENTION_DAYS . ' DAY'
        );
    }

    /** La personne efface toute sa mémoire : ses gestes comptés repartent de zéro. */
    public static function forgetUser(int $userId): void
    {
        Database::getInstance()->prepare('DELETE FROM memory_signal_events WHERE user_id = ?')->execute([$userId]);
    }

    // -----------------------------------------------------------------------
    // Interne
    // -----------------------------------------------------------------------

    /** @return array{0:int, 1:int} [gestes, conversations] sur la période */
    private static function counts(int $userId, string $gesture, string $scope): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS hits, COUNT(DISTINCT conversation_id) AS convs
               FROM memory_signal_events
              WHERE user_id = ? AND gesture = ? AND scope = ?
                AND created_at >= NOW() - INTERVAL ' . self::WINDOW_DAYS . ' DAY'
        );
        $stmt->execute([$userId, $gesture, $scope]);
        $row = $stmt->fetch() ?: [];
        return [(int)($row['hits'] ?? 0), (int)($row['convs'] ?? 0)];
    }

    /** @return array{0:int, 1:int} [tutoiement, vouvoiement] dans un texte */
    private static function addressCounts(string $text): array
    {
        $tu   = preg_match_all('/\b(?:tu|toi|ton|ta|tes|te)\b|\bt[\'’](?=\p{L})/iu', $text);
        $vous = preg_match_all('/\b(?:vous|votre|vos)\b/iu', $text);
        return [(int)$tu, (int)$vous];
    }
}
