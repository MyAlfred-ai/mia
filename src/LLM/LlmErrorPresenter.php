<?php

declare(strict_types=1);

/**
 * Présentation propre des erreurs d'API LLM.
 *
 * Les adaptateurs n'émettent pas d'exception : en cas d'échec ils streament un
 * chunk texte brut de la forme « [Erreur OpenAI (503) : ...] »,
 * « [Clé API Mistral manquante...] », « [Erreur : impossible de contacter ...] »,
 * « [Le serveur ... n'a renvoyé aucune donnée (statut HTTP NNN)...] », etc.
 *
 * Cette classe est la SOURCE UNIQUE qui :
 *   1. reconnaît ces formats (parse) → descripteur structuré,
 *   2. en déduit la CAUSE (classify) — le statut HTTP seul ne la dit pas : un
 *      compte sans crédit répond 429 chez OpenAI, 400 chez Anthropic, 402 chez
 *      DeepSeek ; une conversation trop longue répond 400 partout. Présentés
 *      par leur seul statut, l'un devenait « service saturé, patientez » et
 *      l'autre « un paramètre est peut-être invalide » : deux messages faux,
 *      et aucun moyen pour l'utilisateur de savoir quoi faire,
 *   3. les traduit en message utilisateur lisible (present), SANS jamais
 *      réexposer le message brut du provider (qui peut contenir une clé API,
 *      ex. « Incorrect API key provided: sk-... » sur un 401).
 *
 * ChatController s'en sert pour émettre l'évènement SSE `llm_error` rendu par
 * chat.js sous forme de bloc d'erreur élégant dans la conversation.
 */
class LlmErrorPresenter
{
    /** Libellés d'affichage par provider (slug minuscule → libellé soigné). */
    private const PROVIDER_LABELS = [
        'openai'     => 'OpenAI',
        'anthropic'  => 'Anthropic',
        'mistral'    => 'Mistral',
        'gemini'     => 'Gemini',
        'google'     => 'Gemini',
        'perplexity' => 'Perplexity',
        'deepseek'   => 'DeepSeek',
        'moonshot'   => 'Kimi (Moonshot)',
        'kimi'       => 'Kimi (Moonshot)',
        'zai'        => 'GLM (Z.ai)',
        'glm'        => 'GLM (Z.ai)',
        'local'      => 'le modèle local',
        // Nom que l'adaptateur local donne dans ses chunks (« [Erreur LLM local … »).
        'llm local'  => 'le modèle local',
    ];

    /**
     * Genres d'erreur — la cause, telle que l'utilisateur doit la comprendre.
     *
     *   missing_key  clé absente de l'instance          auth        clé refusée
     *   permission   clé valide mais modèle interdit    quota       compte fournisseur sans crédit
     *   rate_limit   trop de requêtes                   overloaded  fournisseur surchargé
     *   server       incident interne du fournisseur    network     fournisseur injoignable
     *   timeout      délai dépassé                      empty       flux vide
     *   context      trop de contenu pour le modèle     attachment  pièce jointe refusée
     *   model        modèle retiré ou inconnu           filtered    filtre de sécurité
     *   truncated    limite de longueur atteinte        bad_request autre refus 4xx
     *   refusal      demande déclinée par le modèle     retention   conservation des données
     *   generic      cause inconnue
     */
    public const KINDS = [
        'missing_key', 'auth', 'permission', 'quota', 'rate_limit', 'overloaded',
        'server', 'network', 'timeout', 'empty', 'context', 'attachment', 'model',
        'filtered', 'truncated', 'bad_request', 'refusal', 'retention', 'generic',
    ];

    /**
     * Genres qu'un adaptateur annonce lui-même quand le modèle s'arrête sans
     * avoir rien répondu (LlmAdapterHelpers::stopChunk) : longueur maximale,
     * filtre, fenêtre de contexte… Protocole interne, donc liste fermée.
     */
    public const STOP_KINDS = ['truncated', 'context', 'filtered', 'overloaded', 'generic'];

    /**
     * Pannes qui ne tiennent PAS à la demande : un autre modèle y répondrait.
     * Ce sont elles qui déclenchent le LLM de secours (warrantsFailover).
     */
    private const FAILOVER_KINDS = ['rate_limit', 'overloaded', 'server', 'network', 'timeout', 'quota'];

    /**
     * Pannes qui ne se règlent PAS depuis la conversation : l'encadré
     * d'erreur y propose « Prévenir un administrateur » (et le message le
     * dit). Les autres genres ont leur remède à portée de l'utilisateur —
     * raccourcir, reformuler, joindre un autre fichier, réessayer.
     */
    public const CONTACT_KINDS = [
        'missing_key', 'auth', 'permission', 'quota', 'model', 'retention',
        'rate_limit', 'overloaded', 'server', 'network', 'empty', 'bad_request', 'generic',
    ];

    /**
     * Où un ADMINISTRATEUR règle lui-même la cause (lien affiché à sa place
     * du bouton « Prévenir un administrateur »). Le crédit se recharge chez le
     * fournisseur, mais c'est sur la page des clés qu'on vérifie ensuite que
     * le compte répond (« Tester »). Libellés = clés de traduction CLIENT.
     */
    public const ADMIN_FIX = [
        'missing_key' => ['url' => '/admin/llms', 'label' => 'Ouvrir les clés API'],
        'auth'        => ['url' => '/admin/llms', 'label' => 'Ouvrir les clés API'],
        'quota'       => ['url' => '/admin/llms', 'label' => 'Ouvrir les clés API'],
        'retention'   => ['url' => '/admin/llms', 'label' => 'Ouvrir les clés API'],
        'permission'  => ['url' => '/admin/llms/models', 'label' => 'Ouvrir les modèles'],
        'model'       => ['url' => '/admin/llms/models', 'label' => 'Ouvrir les modèles'],
    ];

    /**
     * Signes d'une cause dans le détail d'une erreur — codes machine d'abord
     * (`insufficient_quota`, `context_length_exceeded`, `overloaded_error`…),
     * que LlmAdapterHelpers::errorDetail() place en tête, puis tournures des
     * messages quand le fournisseur n'en donne pas (Anthropic signale un compte
     * sans crédit par un simple `invalid_request_error`).
     *
     * L'ORDRE D'ÉVALUATION vit dans classify() et compte autant que les motifs.
     */
    private const SIGNS = [
        // Trop de contenu : fenêtre de contexte dépassée, requête trop lourde.
        'context' => [
            '/context_length_exceeded|context[_ ]window|maximum context|context limit/i',
            '/prompt is too long|input is too long|too many (?:input )?tokens/i',
            '/exceeds? (?:the )?max(?:imum)? (?:number of )?(?:input )?tokens/i',
            '/exceed\w*.{0,40}token limit|token limit.{0,20}exceed|reduce the length|too large for model/i',
            '/exceeds? (?:the )?max(?:imum)? length/i',
            '/request too large|request_too_large|(?:payload|entity|body) too large|payload size exceeds/i',
            '/string_above_max_length|string too long/i',
        ],
        // Compte fournisseur sans crédit, facturation inactive, plafond atteint.
        'quota' => [
            '/insufficient_quota|exceeded your current quota|exceeded_current_quota/i',
            // OpenAI (guide « Error codes », relu le 2026-10-07) : crédit
            // prépayé épuisé, et plafond MENSUEL attribué par OpenAI selon le
            // palier d'usage — tous deux en 429, comme une limite de débit.
            '/credit_balance_exhausted|organization_usage_limit_exceeded|no prepaid credits/i',
            '/billing[_ ](?:hard[_ ]limit|not[_ ]active|error|disabled)|enable billing|account is not active/i',
            '/billing (?:is |to be |must be )?(?:not )?(?:enabled|active)/i',
            '/credit balance|insufficient[_ ](?:balance|credits?|funds)|out of credits?|no (?:remaining |available )?credits?/i',
            '/payment required|please recharge|arrears|account (?:is |has been )?(?:suspended|overdue)/i',
            '/spend(?:ing)? (?:limit|cap)|budget (?:has been |was |is )?exceeded|(?:plan|subscription) (?:has )?expired/i',
            // Plafond de dépense MENSUEL : Anthropic le signale en 429
            // `rate_limit_error`, comme une limite de débit — seuls
            // `enforced_spend_limit_reached` et la phrase le distinguent, et
            // attendre quelques secondes n'y change rien (accès rendu le 1er du
            // mois suivant). Mistral coupe de même au plafond mensuel du
            // compte (« tokens per month »).
            '/spend_limit|spend_cap|reached (?:your|its) (?:specified )?(?:workspace |organization |organisation )?(?:api )?usage limits?/i',
            '/monthly (?:api )?usage (?:threshold|limit|cap|quota)|(?:tokens?|requests?) per month|monthly (?:token|request|spend(?:ing)?) (?:limit|cap|quota|budget)/i',
        ],
        // Mêmes mots, mais qu'une limite de DÉBIT emploie aussi (« quota
        // exceeded for requests per minute ») : ne valent que sans signe de débit.
        'quota_soft' => [
            '/usage limits?|quota (?:has been |was |is )?exceeded|exceeded (?:your |the )?(?:monthly |daily )?quota/i',
            '/(?:monthly|daily) (?:limit|quota|budget)/i',
        ],
        // Pièce jointe refusée : trop lourde, illisible, type non pris en charge.
        'attachment' => [
            '/\b(?:invalid|unsupported|empty)_(?:image|file|base64_image)\w*|failed_to_download_image/i',
            '/\bimage_(?:too_large|too_small|parse_error|file_too_large|file_not_found)\b/i',
            '/(?:could not|couldn\'t|unable to|failed to|cannot) (?:process|read|parse|decode|download|load) (?:the |this |your |input )?(?:image|pdf|file|document|attachment)/i',
            '/(?:image|pdf|file|document|attachment)s? (?:size )?(?:exceeds?|is too large|are too large|too large|too big)/i',
            '/(?:unsupported|invalid|unknown) (?:image|file|document|pdf|media|mime)/i',
            '/image_url is only supported|(?:does not|doesn\'t|do not|cannot|can\'t) support (?:image|vision|file|pdf|document|audio)/i',
            '/(?:image|vision|file|pdf|document|audio)s? (?:inputs? |content )?(?:is|are) not supported/i',
            '/too many (?:images|files|documents)|pdf pages/i',
            '/\b(?:image|document|pdf)\b.{0,60}(?:not valid|does not match|corrupt|password)/i',
        ],
        // Filtre de sécurité / modération du fournisseur.
        'filtered' => [
            '/content_filter|content[_ ]policy|policy_violation|moderation_blocked|invalid_prompt/i',
            '/blocked by (?:our |the )?(?:moderation|safety|content)|safety (?:system|filter|settings?|reasons?|block|polic)/i',
            '/flagged|violat\w*.{0,60}(?:polic|usage|terms|guideline)|content management policy|responsible ?ai/i',
            '/prohibited_content|blocklist|recitation|\bspii\b|image_safety|block_?reason/i',
            '/sensitive (?:content|information|words?)|unsafe or sensitive|high risk|inappropriate content/i',
        ],
        // Fournisseur surchargé (à distinguer d'une limite de débit du compte).
        'overloaded' => [
            '/overloaded|over capacity|at capacity|capacity exceeded|insufficient_system_resource/i',
            '/high (?:demand|traffic|load)|(?:server|service|system|engine|model) is (?:currently |too )?busy/i',
        ],
        'timeout' => [
            '/time[-_ ]?out|timed out|deadline[_ ]exceeded|took too long/i',
        ],
        // Modèle inconnu du fournisseur : retiré, mal nommé, fermé à la clé.
        'model' => [
            '/model_not_found|invalid[_ ]model|unknown model|no such model|not_found_error|resource_not_found/i',
            '/is not found for api version|try pulling it/i',
            '/\bmodels?\b.{0,80}?(?:does not exist|not exist|not found|deprecated|decommissioned|retired|no longer (?:available|supported)|has been removed|not available)/i',
        ],
        'rate_limit' => [
            '/rate[_ ]limit|too many requests|resource_exhausted|throttl|slow down|concurren/i',
            '/(?:requests?|tokens?) per (?:min|minute|second|day)|\b[rt]pm\b/i',
        ],
        'auth' => [
            '/invalid[_ ]api[_ ]key|incorrect api key|api key not valid|api_key_invalid|api[_ ]key (?:has )?expired|invalid x-api-key/i',
            '/authentication[_ ](?:error|failed|fails)|invalid[_ ]authentication|unauthenticated|\bunauthorized\b|account_deactivated/i',
            '/(?:no|missing) api key|invalid (?:bearer )?token|invalid credentials/i',
        ],
        // Mots entiers : « extra_forbidden » (validation d'un champ, 422) ou
        // « properties are not allowed » ne sont pas des refus d'accès.
        'permission' => [
            '/\bpermission(?:_error|_denied)?\b|access denied|\bforbidden\b|not authorized|must be verified/i',
            '/not allowed to (?:access|use|call)/i',
            '/unsupported_country|country, region, or territory|location is not supported|not available in your (?:country|region)/i',
            '/service_disabled|has not been used in project|(?:does not|do not|don\'t) have access/i',
        ],
        // Sans statut HTTP (erreur arrivée au fil du flux) : incident interne.
        'server' => [
            '/api_error|server_error|internal[_ ](?:server )?error|\binternal\b|bad gateway/i',
            '/service unavailable|\bunavailable\b|(?:unknown|unexpected) error/i',
        ],
        'network' => [
            '/connection (?:reset|refused|aborted|closed)|could not resolve|name or service not known/i',
            '/network[_ ]error|\bssl\b|unexpectedly (?:closed|ended)/i',
        ],
    ];

    /**
     * Analyse un chunk émis par un adaptateur. Retourne un descripteur
     * ['kind','provider','status'] — plus `retry_after` (secondes) quand le
     * fournisseur annonce un délai — ou null si le texte n'est pas une erreur
     * reconnue (= un vrai token de réponse).
     *
     * @return array{kind:string,provider:?string,status:?int,retry_after?:int}|null
     */
    public static function parse(string $chunk): ?array
    {
        $c = trim($chunk);
        // Toutes les erreurs adaptateurs sont encadrées par des crochets.
        if ($c === '' || $c[0] !== '[') {
            return null;
        }

        // [Clé API <Provider> manquante ...]
        if (preg_match('/^\[Cl[ée] API\s+([^\]]+?)\s+manquante/u', $c, $m) === 1) {
            return ['kind' => 'missing_key', 'provider' => self::cleanProvider($m[1]), 'status' => null];
        }

        // [Erreur : URL du LLM local non configurée] — même nature qu'une clé
        // absente : rien à réessayer, c'est l'instance qui n'est pas réglée.
        if (preg_match('/^\[Erreur\s*:\s*URL du LLM local non configur/u', $c) === 1) {
            return ['kind' => 'missing_key', 'provider' => 'local', 'status' => null];
        }

        // [Erreur : impossible de contacter <Provider>]
        if (preg_match('/impossible de contacter\s+(.+?)\]?$/u', $c, $m) === 1) {
            return ['kind' => 'network', 'provider' => self::cleanProvider($m[1]), 'status' => null];
        }

        // [Le serveur <Provider> n'a renvoyé aucune donnée (statut HTTP NNN) ...]
        if (preg_match('/Le serveur\s+(.+?)\s+n[\'’]a renvoy[ée] aucune donn[ée]e.*?statut HTTP\s*(\d{3}|inconnu)/u', $c, $m) === 1) {
            $status = ctype_digit($m[2]) ? (int)$m[2] : null;
            // Un flux vide derrière un statut d'erreur EST cette erreur (503,
            // 429…) : « réponse vide » n'en dirait rien.
            $kind = ($status !== null && $status >= 400) ? self::classify($status, '') : 'empty';
            return ['kind' => $kind, 'provider' => self::cleanProvider($m[1]), 'status' => $status];
        }

        // [Aucune réponse renvoyée par le modèle ...]
        if (preg_match('/^\[Aucune r[ée]ponse renvoy[ée]e par le mod[èe]le/u', $c) === 1) {
            return ['kind' => 'empty', 'provider' => null, 'status' => null];
        }

        // [Refus <Provider> : ...] — le modèle a décliné la demande
        // (stop_reason « refusal » : classifieurs de sécurité de Fable, Opus 5).
        if (preg_match('/^\[Refus\s+([^:\]]+?)\s*:/u', $c, $m) === 1) {
            return ['kind' => 'refusal', 'provider' => self::cleanProvider($m[1]), 'status' => null];
        }

        // [Erreur <Provider> (NNN) : <détail>]
        if (preg_match('/^\[Erreur\s+([^(\]:]+?)\s*\((\d{3})\)\s*:?\s*(.*)$/su', $c, $m) === 1) {
            return self::describe((int)$m[2], $m[3], self::cleanProvider($m[1]));
        }

        // [Erreur <Provider> : <détail>] (erreur au fil du flux, sans code HTTP)
        if (preg_match('/^\[Erreur\s+([^(\]:]+?)\s*:\s*(.*)$/su', $c, $m) === 1) {
            return self::describe(null, $m[2], self::cleanProvider($m[1]));
        }

        // [Erreur ...] générique (ex. « [Erreur : impossible d'ouvrir le stream] »)
        if (preg_match('/^\[Erreur\b\s*:?\s*(.*)$/su', $c, $m) === 1) {
            return self::describe(null, $m[1], null);
        }

        return null;
    }

    /**
     * Même analyse, pour un chunk arrivé APRÈS le début de la réponse. Là, le
     * modèle écrit du texte — et peut très bien écrire « [Erreur : …] » de
     * lui-même. Ne compte donc qu'un chunk ENTIER, signé d'un fournisseur
     * connu : ce qu'un adaptateur émet, et qu'un modèle ne produit pas par
     * hasard en un seul fragment de flux.
     *
     * @return array{kind:string,provider:?string,status:?int,retry_after?:int}|null
     */
    public static function parseMidStream(string $chunk): ?array
    {
        $c = trim($chunk);
        if ($c === '' || $c[0] !== '[' || !str_ends_with($c, ']')) {
            return null;
        }
        $desc = self::parse($c);
        if ($desc === null || $desc['provider'] === null) {
            return null;
        }
        return isset(self::PROVIDER_LABELS[strtolower($desc['provider'])]) ? $desc : null;
    }

    /**
     * Cause d'une erreur, d'après son statut HTTP et son détail (« code —
     * message », cf. LlmAdapterHelpers::errorDetail). PURE.
     *
     * L'ordre est le cœur de la règle :
     *   - un 5xx est une panne du fournisseur quoi que dise son message ;
     *   - « trop de contenu » passe avant les limites de débit : OpenAI répond
     *     429 à une requête trop lourde pour le palier du compte (« Request
     *     too large … tokens per min »), et attendre n'y changera rien ;
     *   - « plus de crédit » passe avant la limite de débit pour la même
     *     raison (429 `insufficient_quota`) — sauf chez Google, dont le 429
     *     RESOURCE_EXHAUSTED porte la MÊME phrase (« check your plan and
     *     billing details ») pour une limite à la minute ;
     *   - un modèle introuvable passe avant le refus d'accès (« does not exist
     *     or you do not have access to it ») ;
     *   - les statuts ne tranchent qu'en dernier, quand le détail ne dit rien.
     */
    public static function classify(?int $status, string $detail): string
    {
        $d = trim($detail);

        // Arrêt annoncé par l'adaptateur lui-même : sans ambiguïté.
        if (preg_match('/\bstop_reason=([a-z_]+)/', $d, $m) === 1 && in_array($m[1], self::STOP_KINDS, true)) {
            return $m[1];
        }

        if ($status !== null && $status >= 500) {
            if ($status === 529 || self::hits('overloaded', $d)) {
                return 'overloaded';
            }
            if ($status === 504 || $status === 524 || self::hits('timeout', $d)) {
                return 'timeout';
            }
            return 'server';
        }

        // Modèle réservé aux organisations qui conservent les données (Claude
        // Fable), quand aucun relais n'a pu reprendre la demande.
        if ($status === 400 && stripos($d, 'data retention') !== false) {
            return 'retention';
        }

        if ($status === 413 || self::hits('context', $d)) {
            return 'context';
        }

        if (stripos($d, 'RESOURCE_EXHAUSTED') !== false) {
            // La phrase type de Google nomme la facturation même pour une
            // limite à la minute : elle ne prouve rien, on la retire.
            $reste = str_ireplace('please check your plan and billing details', '', $d);
            return preg_match('/per[_ ]?day|daily|billing|spend|credit|prepa/i', $reste) === 1 ? 'quota' : 'rate_limit';
        }

        if ($status === 402 || self::hits('quota', $d)) {
            return 'quota';
        }
        if (self::hits('quota_soft', $d) && !self::hits('rate_limit', $d)) {
            return 'quota';
        }

        foreach (['attachment', 'filtered', 'overloaded', 'timeout', 'model'] as $kind) {
            if (self::hits($kind, $d)) {
                return $kind;
            }
        }

        if ($status === 408) {
            return 'timeout';
        }
        if ($status === 429 || self::hits('rate_limit', $d)) {
            return 'rate_limit';
        }
        if ($status === 401 || self::hits('auth', $d)) {
            return 'auth';
        }
        if ($status === 403 || self::hits('permission', $d)) {
            return 'permission';
        }
        if ($status === 404) {
            return 'model';
        }
        if ($status !== null && $status >= 400) {
            return 'bad_request';
        }

        foreach (['server', 'network'] as $kind) {
            if (self::hits($kind, $d)) {
                return $kind;
            }
        }

        return 'generic';
    }

    /**
     * Délai annoncé par le fournisseur avant un nouvel essai (« Please try
     * again in 20s », « retry in 46.3s », « in 6m0s »), en secondes — null
     * s'il n'en donne pas. PURE. Un délai de plus d'un jour n'est pas repris :
     * « réessayez dans 31 heures » n'aide personne.
     */
    public static function retryDelaySeconds(string $detail): ?int
    {
        if (preg_match('/(?:try again|retry) in\s+((?:\d+(?:\.\d+)?\s*(?:ms|h|m|s)\s*)+)/i', $detail, $m) !== 1) {
            return null;
        }
        $total = 0.0;
        preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|h|m|s)/i', $m[1], $parts, PREG_SET_ORDER);
        foreach ($parts as $p) {
            $total += (float)$p[1] * match (strtolower($p[2])) {
                'h'  => 3600,
                'm'  => 60,
                'ms' => 0.001,
                default => 1,
            };
        }
        $seconds = (int)ceil($total);
        return ($seconds >= 1 && $seconds <= 86400) ? $seconds : null;
    }

    /**
     * La panne justifie-t-elle de passer la demande au LLM de secours ? Oui
     * quand elle tient au FOURNISSEUR et non à la demande : surcharge,
     * incident, délai, réseau, limite de débit — et compte sans crédit, qui
     * rend le fournisseur indisponible pour toute l'instance au même titre
     * qu'une panne. Jamais pour ce qu'un autre modèle refuserait pareil
     * (contenu trop long, pièce jointe) ni pour un défaut de configuration
     * (clé, modèle retiré), que la bascule masquerait à l'administrateur.
     *
     * Les statuts 429 / 408 / 5xx restent décisifs à eux seuls : c'était la
     * règle avant la classification, elle ne doit pas se resserrer.
     *
     * @param array{kind?:string,status?:?int} $desc
     */
    public static function warrantsFailover(array $desc): bool
    {
        $status = $desc['status'] ?? null;
        if ($status !== null && ($status >= 500 || $status === 429 || $status === 408)) {
            return true;
        }
        return in_array($desc['kind'] ?? '', self::FAILOVER_KINDS, true);
    }

    /**
     * Traduit un descripteur en charge utile destinée à l'UI : titre + message
     * lisibles, drapeau `retryable` (réessayer la même demande a-t-il un sens).
     * Aucun détail technique brut du provider n'est propagé ici.
     *
     * @param array{kind:string,provider:?string,status:?int,retry_after?:int} $desc
     * @return array{kind:string,provider:?string,provider_label:?string,status:?int,title:string,message:string,retryable:bool,contact_admin:bool}
     */
    public static function present(array $desc): array
    {
        $kind     = $desc['kind'] ?? 'generic';
        $status   = $desc['status'] ?? null;
        $provider = $desc['provider'] ?? null;
        $label    = self::providerLabel($provider);
        $svc      = $label ?? t('le service IA');
        // « Le compte OpenAI », « chez OpenAI », « la clé API OpenAI » : seule
        // une MARQUE s'y glisse telle quelle. Sans fournisseur connu — ou pour
        // la périphrase du modèle local — c'est une AUTRE phrase, entière : un
        // substituant (« du fournisseur ») tient en français et donne « The
        // for the provider API key » une fois traduit.
        $brand    = ($label !== null && !self::isPeriphrase($provider)) ? $label : null;
        // Substituants {nom} et non %s : l'ordre des mots change d'une langue à
        // l'autre, et un « % » égaré dans une traduction ferait lever sprintf.
        $statusSuffix = $status !== null ? t(' (erreur {code})', ['code' => $status]) : '';

        switch ($kind) {
            case 'missing_key':
                $title     = t('Configuration requise');
                $message   = match (true) {
                    self::isPeriphrase($provider)
                        => t("Le modèle local n'est pas encore configuré : il ne peut pas répondre. Prévenez un administrateur ; en attendant, choisissez un autre modèle."),
                    $brand !== null
                        => t("Ce modèle n'est pas encore relié à {service} : il ne peut pas répondre. Prévenez un administrateur pour qu'il termine sa configuration ; en attendant, choisissez un autre modèle.", ['service' => $brand]),
                    default
                        => t("Ce modèle n'est pas encore relié à son fournisseur : il ne peut pas répondre. Prévenez un administrateur pour qu'il termine sa configuration ; en attendant, choisissez un autre modèle."),
                };
                $retryable = false;
                break;
            case 'auth':
                $title     = t('Accès au service refusé');
                // Pas de « clé API » nue : l'expression ne dit rien à qui n'a
                // jamais administré MIA. La parenthèse la garde pour celui
                // qui la transmettra à un administrateur.
                $message   = $brand !== null
                    ? t("{service} n'accepte plus la connexion de votre organisation (clé d'accès invalide ou expirée) : ce modèle ne peut pas répondre. Prévenez un administrateur pour qu'il la renouvelle ; en attendant, choisissez un autre modèle.", ['service' => $brand])
                    : t("Le fournisseur de ce modèle n'accepte plus la connexion de votre organisation (clé d'accès invalide ou expirée) : ce modèle ne peut pas répondre. Prévenez un administrateur pour qu'il la renouvelle ; en attendant, choisissez un autre modèle.");
                $retryable = false;
                break;
            case 'permission':
                $title     = t('Accès au modèle refusé');
                $message   = t("{service} refuse l'accès à ce modèle à votre organisation : il ne lui est pas ouvert, ou il n'est pas proposé dans votre pays. Choisissez un autre modèle ; si vous en avez besoin, prévenez un administrateur.", ['service' => self::ucfirst($svc)]);
                $retryable = false;
                break;
            case 'quota':
                // « Crédit » et non « quota » : le quota, dans MIA, est celui
                // de l'utilisateur. Ici c'est le compte du FOURNISSEUR qui est
                // à sec — d'où la phrase qui écarte la confusion, sans un mot
                // technique (ni « instance », ni « quota », ni « API ») : c'est
                // souvent la première fois que la personne voit ce message.
                $title     = $brand !== null
                    ? t('Crédit épuisé chez {service}', ['service' => $brand])
                    : t('Crédit épuisé chez le fournisseur');
                $message   = $brand !== null
                    ? t("Le crédit de votre organisation chez {service} est épuisé : ce modèle ne peut plus répondre pour le moment. Ce n'est lié ni à votre message ni à votre compte. Prévenez un administrateur pour qu'il recharge ce crédit ; en attendant, choisissez un autre modèle.", ['service' => $brand])
                    : t("Le crédit de votre organisation chez le fournisseur de ce modèle est épuisé : ce modèle ne peut plus répondre pour le moment. Ce n'est lié ni à votre message ni à votre compte. Prévenez un administrateur pour qu'il recharge ce crédit ; en attendant, choisissez un autre modèle.");
                $retryable = false;
                break;
            case 'rate_limit':
                $title     = t('Service momentanément saturé');
                $delay     = self::delayPhrase($desc['retry_after'] ?? null);
                $message   = $delay !== null
                    ? t('{service} reçoit trop de demandes en ce moment. Réessayez dans {delai} ; si cela persiste, prévenez un administrateur.', ['service' => self::ucfirst($svc), 'delai' => $delay])
                    : t('{service} reçoit trop de demandes en ce moment. Patientez quelques secondes puis réessayez ; si cela persiste, prévenez un administrateur.', ['service' => self::ucfirst($svc)]);
                $retryable = true;
                break;
            case 'overloaded':
                $title     = t('Service surchargé');
                $message   = t("{service} est surchargé en ce moment : trop de demandes lui arrivent en même temps. Ce n'est pas lié à votre message. Réessayez dans quelques instants ou choisissez un autre modèle ; si cela persiste, prévenez un administrateur.", ['service' => self::ucfirst($svc)]);
                $retryable = true;
                break;
            case 'server':
                $title     = t('Service temporairement indisponible');
                $message   = t("{service} rencontre un incident technique{code}. Ce n'est pas lié à votre message : réessayez dans un instant ou choisissez un autre modèle ; si cela persiste, prévenez un administrateur.", ['service' => self::ucfirst($svc), 'code' => $statusSuffix]);
                $retryable = true;
                break;
            case 'network':
                $title     = t('Connexion impossible');
                $message   = t("Le serveur n'arrive pas à joindre {service} pour le moment. Réessayez dans un instant ; si cela persiste, prévenez un administrateur.", ['service' => $svc]);
                $retryable = true;
                break;
            case 'timeout':
                $title     = t('Délai de réponse dépassé');
                $message   = t('{service} a mis trop de temps à répondre et la demande a été interrompue. Réessayez ; si cela se répète, raccourcissez la demande ou choisissez un modèle plus rapide.', ['service' => self::ucfirst($svc)]);
                $retryable = true;
                break;
            case 'empty':
                $title     = t('Réponse vide');
                $message   = t("{service} n'a renvoyé aucune réponse. Réessayez, éventuellement avec un autre modèle ; si cela persiste, prévenez un administrateur.", ['service' => self::ucfirst($svc)]);
                $retryable = true;
                break;
            case 'context':
                // Réessayer tel quel échouerait à l'identique : le remède est
                // dans la conversation, pas dans l'attente.
                $title     = t('Trop de contenu pour ce modèle');
                $message   = t('Cette conversation — ses messages, ses pièces jointes et les documents consultés — dépasse ce que ce modèle peut lire en une fois. Démarrez une nouvelle conversation, joignez un fichier plus court, ou choisissez un modèle qui accepte plus de contenu.');
                $retryable = false;
                break;
            case 'attachment':
                $title     = t('Pièce jointe refusée');
                $message   = t("{service} n'a pas accepté une pièce jointe de cette conversation : fichier trop lourd, illisible, ou d'un type que ce modèle ne lit pas. Renvoyez votre message dans une nouvelle conversation avec un fichier plus léger ou converti, ou choisissez un autre modèle.", ['service' => self::ucfirst($svc)]);
                $retryable = false;
                break;
            case 'model':
                $title     = t('Modèle indisponible');
                $message   = t("{service} ne propose plus ce modèle, ou votre organisation n'y a pas accès. Choisissez un autre modèle et prévenez un administrateur pour qu'il mette la liste à jour.", ['service' => self::ucfirst($svc)]);
                $retryable = false;
                break;
            case 'filtered':
                $title     = t('Demande bloquée par le filtre de sécurité');
                $message   = t("{service} a bloqué cette demande ou sa réponse : son filtre de sécurité l'a jugée sensible. Reformulez-la, ou choisissez un autre modèle.", ['service' => self::ucfirst($svc)]);
                $retryable = false;
                break;
            case 'truncated':
                // Arrêt SANS réponse : un modèle qui raisonne compte sa
                // réflexion dans son plafond de sortie, et peut l'épuiser
                // avant d'avoir écrit un mot.
                $title     = t('Limite de longueur atteinte');
                $message   = t("Le modèle a atteint sa limite de longueur avant d'avoir rédigé sa réponse : sa réflexion a occupé toute la place disponible. Demandez une réponse plus courte, découpez la demande, ou choisissez un autre modèle.");
                $retryable = true;
                break;
            case 'bad_request':
                $title     = t('Requête refusée');
                $message   = t("{service} a refusé cette demande{code} : elle contient un réglage, un format ou un contenu que ce modèle n'accepte pas. Essayez un autre modèle ; si cela se répète, prévenez un administrateur.", ['service' => self::ucfirst($svc), 'code' => $statusSuffix]);
                $retryable = false;
                break;
            case 'refusal':
                // Pas une panne : réessayer la même demande sur le même
                // modèle reproduirait le refus, d'où retryable = false.
                $title     = t('Demande déclinée');
                $message   = t('{service} a décliné cette demande. Reformulez-la, ou choisissez un autre modèle.', ['service' => self::ucfirst($svc)]);
                $retryable = false;
                break;
            case 'retention':
                $title     = t('Modèle indisponible pour votre organisation');
                $message   = $brand !== null
                    ? t("Ce modèle n'est ouvert qu'aux organisations qui laissent {service} conserver leurs données 30 jours. Prévenez un administrateur, qui peut activer cette option ; en attendant, choisissez un autre modèle.", ['service' => $brand])
                    : t("Ce modèle n'est ouvert qu'aux organisations qui laissent son fournisseur conserver leurs données 30 jours. Prévenez un administrateur, qui peut activer cette option ; en attendant, choisissez un autre modèle.");
                $retryable = false;
                break;
            default:
                $kind      = 'generic';
                $title     = t('Une erreur est survenue');
                $message   = $label !== null
                    ? t("{service} n'a pas pu rédiger sa réponse. Réessayez dans un instant ; si cela persiste, prévenez un administrateur.", ['service' => self::ucfirst($label)])
                    : t("La réponse n'a pas pu être générée. Réessayez dans un instant ; si cela persiste, prévenez un administrateur.");
                $retryable = true;
                break;
        }

        return [
            'kind'           => $kind,
            'provider'       => $provider,
            'provider_label' => $label,
            'status'         => $status,
            'title'          => $title,
            'message'        => $message,
            'retryable'      => $retryable,
            // L'encadré propose « Prévenir un administrateur » (cf. chat.js) :
            // vrai pour ce qui ne se règle pas depuis la conversation.
            'contact_admin'  => in_array($kind, self::CONTACT_KINDS, true),
        ];
    }

    /**
     * Ce que l'encadré d'erreur du chat propose, selon le genre — livré au
     * navigateur (`window.LLM_ERROR_ACTIONS`), qui ne connaît que le genre
     * d'une erreur relue : la charge utile `contact_admin` ne voyage qu'en
     * direct. `contact` : genres où « Prévenir un administrateur » a un sens ;
     * `fix` : page d'administration où un ADMINISTRATEUR règle la cause
     * lui-même (il voit ce lien à la place du bouton).
     *
     * @return array{contact: list<string>, fix: array<string, array{url:string, label:string}>}
     */
    public static function clientActions(): array
    {
        return [
            'contact' => self::CONTACT_KINDS,
            'fix'     => self::ADMIN_FIX,
        ];
    }

    /**
     * Charges utiles toutes prêtes d'un fournisseur, pour les rares pannes
     * que seul le NAVIGATEUR voit : la dictée parle directement à OpenAI
     * (WebRTC), et ses erreurs de session arrivent par le canal de données,
     * jamais par le serveur. Le navigateur n'y retrouve que le genre ; titre
     * et message restent ceux d'ici — une seule source pour les mots.
     *
     * @return array<string, array> genre → present()
     */
    public static function clientPayloads(string $provider): array
    {
        $out = [];
        foreach (['quota', 'auth', 'permission', 'rate_limit', 'overloaded', 'server', 'network'] as $kind) {
            $out[$kind] = self::present(['kind' => $kind, 'provider' => $provider, 'status' => null]);
        }
        return $out;
    }

    /**
     * Charge utile présentable d'une réponse HTTP en erreur, pour les appels
     * qui ne passent pas par un adaptateur de conversation (session de dictée,
     * synthèse vocale) : même lecture du corps, même cause, mêmes mots.
     *
     * @return array{kind:string,provider:?string,provider_label:?string,status:?int,title:string,message:string,retryable:bool,contact_admin:bool}
     */
    public static function fromHttp(?int $status, string $rawBody, ?string $provider): array
    {
        $detail = LlmAdapterHelpers::errorDetail(json_decode($rawBody, true), $rawBody, $status);
        if ($status === null || $status < 400) {
            return self::present(['kind' => self::classify(null, $detail), 'provider' => $provider, 'status' => null]);
        }
        return self::present(self::describe($status, $detail, $provider));
    }

    /**
     * Note posée SOUS une réponse déjà commencée quand le fournisseur lâche en
     * route ou que le modèle s'arrête avant la fin. Le texte déjà affiché ne
     * peut pas être retiré : on dit pourquoi il s'arrête là, au lieu de
     * laisser le message brut du fournisseur (en anglais, parfois avec un
     * identifiant de requête) terminer la réponse — ou de ne rien dire du tout.
     *
     * Même forme que le marqueur « interrompue par l'utilisateur » : une ligne
     * en italique, entre crochets, qui ne ressemble pas à une erreur
     * d'adaptateur (parse() la laisse passer).
     *
     * @param array{kind?:string,provider?:?string} $desc
     */
    public static function interruptionNote(array $desc): string
    {
        $kind  = $desc['kind'] ?? 'generic';
        $label = self::providerLabel($desc['provider'] ?? null);
        $svc   = $label ?? t('le service IA');
        $brand = ($label !== null && !self::isPeriphrase($desc['provider'] ?? null)) ? $label : null;

        $texte = match ($kind) {
            'quota'      => $brand !== null
                ? t('Réponse interrompue : le crédit de votre organisation chez {service} est épuisé', ['service' => $brand])
                : t('Réponse interrompue : le crédit de votre organisation chez le fournisseur est épuisé'),
            'rate_limit' => t('Réponse interrompue : {service} reçoit trop de requêtes en ce moment', ['service' => $svc]),
            'overloaded' => t('Réponse interrompue : {service} est surchargé', ['service' => $svc]),
            'server'     => t('Réponse interrompue : {service} a rencontré un incident technique', ['service' => $svc]),
            'network'    => t('Réponse interrompue : la liaison avec {service} a été perdue', ['service' => $svc]),
            'timeout'    => t('Réponse interrompue : {service} a cessé de répondre', ['service' => $svc]),
            'context'    => t('Réponse interrompue : la conversation dépasse ce que ce modèle peut traiter en une fois'),
            'filtered'   => t('Réponse interrompue : le filtre de sécurité de ce modèle a bloqué la suite'),
            'truncated'  => t('Réponse interrompue : le modèle a atteint sa longueur maximale de réponse — écrivez « continue » pour obtenir la suite'),
            'refusal'    => t('Réponse interrompue : le modèle a décliné la suite de cette demande'),
            default      => t('Réponse interrompue : {service} a rencontré une erreur', ['service' => $svc]),
        };

        return "\n\n*[" . $texte . ']*';
    }

    /**
     * Descripteur d'un arrêt anormal rapporté par l'adaptateur dans ses jetons
     * (clé `stop` : le modèle s'est arrêté avant la fin — longueur maximale,
     * filtre, fenêtre de contexte), ou null quand il a simplement fini. PURE.
     *
     * Cette information ne voyage PAS dans le flux : un marqueur entre crochets
     * finirait dans une page web ou un JSON générés. Seul l'appelant qui sait
     * quoi en faire la lit — le chat, qui pose la note sous la réponse.
     *
     * @return array{kind:string,provider:?string,status:null}|null
     */
    public static function stopDescriptor(mixed $tokens, ?string $provider): ?array
    {
        $stop = is_array($tokens) ? ($tokens['stop'] ?? null) : null;
        $kind = is_array($stop) ? ($stop['kind'] ?? null) : null;
        if (!is_string($kind) || !in_array($kind, self::STOP_KINDS, true)) {
            return null;
        }
        return ['kind' => $kind, 'provider' => $provider, 'status' => null];
    }

    /**
     * Raccourci : analyse un chunk brut et renvoie directement la charge utile
     * présentable. Retombe sur une erreur générique si le format est inconnu.
     *
     * @return array{kind:string,provider:?string,provider_label:?string,status:?int,title:string,message:string,retryable:bool}
     */
    public static function fromChunk(string $chunk): array
    {
        return self::present(self::parse($chunk) ?? ['kind' => 'generic', 'provider' => null, 'status' => null]);
    }

    /**
     * Ligne de diagnostic pour un ADMINISTRATEUR (bouton « Tester » d'un
     * outil) : la cause nommée, puis le chunk d'origine — que l'utilisateur ne
     * voit jamais, mais dont celui qui règle l'instance a besoin (code
     * d'erreur, paramètre en cause). Un texte qui n'est pas une erreur
     * d'adaptateur est rendu tel quel.
     */
    public static function adminLine(string $chunk): string
    {
        $chunk = trim($chunk);
        $desc  = self::parse($chunk);

        return $desc === null ? $chunk : self::present($desc)['title'] . ' — ' . $chunk;
    }

    /**
     * Charge utile « réponse vide » pour le garde-fou de flux vide sans erreur
     * exploitable (le modèle a fermé le flux sans rien produire).
     *
     * @return array{kind:string,provider:?string,provider_label:?string,status:?int,title:string,message:string,retryable:bool}
     */
    public static function emptyError(?string $provider): array
    {
        return self::present(['kind' => 'empty', 'provider' => $provider, 'status' => null]);
    }

    /**
     * Texte persisté en base pour le message assistant en erreur. Reste lisible
     * partout (rechargement de conversation, export PDF/Word) et porte le
     * préfixe ⚠️ reconnu par chat.js pour un rendu en bloc d'erreur.
     *
     * Le TITRE est persisté avec le message, en tête et sur sa propre ligne :
     * c'est lui qui nomme la cause (« Crédit épuisé chez OpenAI »). Sans lui,
     * le bloc rechargé retombait sur « Une erreur est survenue » et la cause
     * n'était lisible qu'au moment de l'erreur. chat.js relit cette forme
     * (errorPayloadFromText) : un seul paragraphe = message seul, comme avant.
     *
     * @param array{title?:string,message?:string} $payload
     */
    public static function toPlainText(array $payload): string
    {
        // Ce texte est PERSISTÉ dans messages.content : il est figé dans la
        // langue qui avait cours au moment de l'erreur, comme le reste du
        // contenu d'une conversation. On le traduit à l'écriture, jamais après.
        $message = trim((string)($payload['message'] ?? ''));
        if ($message === '') {
            $message = t('Une erreur est survenue.');
        }
        // Une ligne, bornée : le titre vient parfois d'ailleurs que present()
        // (échec d'un job), et chat.js ne reconnaît qu'un titre court.
        $title = trim((string)preg_replace('/\s+/u', ' ', (string)($payload['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > 120) {
            return '⚠️ ' . $message;
        }

        return '⚠️ ' . $title . "\n\n" . $message;
    }

    /**
     * Table « début de titre → genre », dans toutes les langues servies, les
     * titres les plus longs d'abord.
     *
     * Le genre d'une erreur n'est pas persisté : seul son texte l'est
     * (toPlainText). Au rechargement, chat.js retrouve le genre — donc le TON
     * de l'encadré, ambre ou rouge — en reconnaissant le titre. Sans cette
     * table, un encadré ambre en direct redevenait rouge à la relecture.
     *
     * La partie fixe d'un titre s'arrête où commence le nom du fournisseur
     * (« Crédit épuisé chez » OpenAI) ; l'ordre par longueur évite qu'un titre
     * court en masque un plus long (« Modèle indisponible » / « … pour votre
     * organisation »).
     *
     * @return array<string, string>
     */
    public static function titleKinds(): array
    {
        $courante = I18n::locale();
        $table    = [];
        try {
            foreach (array_keys(I18n::LOCALES) as $langue) {
                I18n::use($langue);
                foreach (self::KINDS as $kind) {
                    $title  = self::present(['kind' => $kind, 'provider' => 'openai', 'status' => null])['title'];
                    $prefix = rtrim(explode('OpenAI', $title)[0]);
                    if ($prefix !== '') {
                        $table[$prefix] ??= $kind;
                    }
                }
            }
        } finally {
            // La locale est un statique de process : toujours la rendre.
            I18n::use($courante);
        }
        uksort($table, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $table;
    }

    /**
     * Libellé d'affichage d'un provider (slug ou nom déjà soigné).
     *
     * PROVIDER_LABELS ne contient que des NOMS PROPRES, qui ne se traduisent
     * pas — à une exception près, « le modèle local », qui est une périphrase et
     * non une marque. D'où le t() ciblé plutôt qu'une traduction du tableau.
     */
    public static function providerLabel(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }
        $libelle = self::PROVIDER_LABELS[strtolower($provider)] ?? $provider;

        // Seule périphrase du tableau, donc seul libellé traduisible : les
        // autres sont des marques. t() sur un nom propre est inoffensif (aucune
        // clé ne lui correspond), mais l'intention doit rester lisible.
        return $libelle === 'le modèle local' ? t($libelle) : $libelle;
    }

    /** Descripteur d'une erreur à détail : cause + délai annoncé s'il y en a un. */
    private static function describe(?int $status, string $detail, ?string $provider): array
    {
        $desc = ['kind' => self::classify($status, $detail), 'provider' => $provider, 'status' => $status];
        if (in_array($desc['kind'], ['rate_limit', 'overloaded'], true)) {
            $delay = self::retryDelaySeconds($detail);
            if ($delay !== null) {
                $desc['retry_after'] = $delay;
            }
        }
        return $desc;
    }

    /** Le détail porte-t-il l'un des signes du genre ? */
    private static function hits(string $kind, string $detail): bool
    {
        if ($detail === '') {
            return false;
        }
        foreach (self::SIGNS[$kind] ?? [] as $pattern) {
            if (preg_match($pattern, $detail) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Le fournisseur est-il désigné par une périphrase (« le modèle local ») plutôt qu'une marque ? */
    private static function isPeriphrase(?string $provider): bool
    {
        return $provider !== null
            && (self::PROVIDER_LABELS[strtolower($provider)] ?? '') === 'le modèle local';
    }

    /**
     * Délai lisible : « 20 secondes », « 6 minutes ». Null sans délai connu —
     * et au-delà d'une heure, où « réessayez plus tard » dit la même chose
     * sans donner une précision trompeuse.
     */
    private static function delayPhrase(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 1 || $seconds > 3600) {
            return null;
        }
        if ($seconds < 90) {
            return I18n::plural($seconds, '{n} seconde', '{n} secondes');
        }
        return I18n::plural((int)ceil($seconds / 60), '{n} minute', '{n} minutes');
    }

    /** Normalise le provider extrait d'un message (retire « Responses API », ponctuation). */
    private static function cleanProvider(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $p = trim($raw);
        // « OpenAI Responses API » → « OpenAI »
        $p = preg_replace('/\s+Responses API$/iu', '', $p) ?? $p;
        // « le LLM local à http://hôte:port/… » → « LLM local » : ni l'article
        // ni l'adresse interne du serveur ne doivent atteindre l'écran.
        $p = preg_replace('/\s+à\s+\S+$/u', '', $p) ?? $p;
        $p = preg_replace('/^le\s+/iu', '', $p) ?? $p;
        $p = trim($p, " \t:.");
        return $p === '' ? null : $p;
    }

    /** ucfirst multibyte-safe (les libellés restent ASCII mais on reste prudent). */
    private static function ucfirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
