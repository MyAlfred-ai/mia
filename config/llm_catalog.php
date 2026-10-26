<?php

declare(strict_types=1);

/**
 * Catalogue LLM — source unique de vérité
 * --------------------------------------
 * Pour ajouter / mettre à jour un modèle, éditer UNIQUEMENT ce fichier.
 * Tout le reste (adapters, Factory, UI) lit depuis ici via `LlmCatalog`.
 *
 * Champs par modèle :
 *   label       : nom affiché dans l'UI
 *   note        : description courte
 *   tags        : ['vision', 'fichiers', 'web', 'raisonnement', 'code']
 *   limits      : [] (labels "limitations" affichés dans l'UI)
 *   web_native  : le modèle a une recherche web native
 *   default     : true → modèle par défaut du provider
 *   routing     : comment l'adapter doit router la requête
 *                 'chat_completions'     → endpoint classique (OpenAI-compat)
 *                 'responses_api'        → force la Responses API (GPT-5.x)
 *                 'responses_web_search' → Responses API seulement si web_search
 *                 'search_preview'       → modèle search-preview (pas de streaming temp, pas de temperature)
 *   temperature : false → le modèle rejette le paramètre `temperature`
 *   temperature_max : (optionnel, modèle sinon provider) borne haute que l'API
 *                 accepte — le curseur de MIA va jusqu'à 2 (échelle OpenAI) ;
 *                 la valeur envoyée est bornée par LlmCatalog::temperatureFor().
 *   max_output_tokens : (optionnel, Anthropic et DeepSeek) plafond de sortie
 *                 `max_tokens` — défaut de l'adapter sinon (Anthropic : 64 000,
 *                 le plus petit plafond de l'API parmi les modèles Claude actifs ;
 *                 DeepSeek : rien d'envoyé, l'API borne alors à 8K). Le
 *                 raisonnement compte dedans. À DÉCLARER pour un modèle dont
 *                 l'API accepte moins que le défaut (sinon chaque demande part
 *                 trop haut, se fait refuser puis rejouer), ou pour en accorder
 *                 délibérément plus.
 *   thinking    : (optionnel, DeepSeek) `false` → MIA envoie `thinking:
 *                 {type: disabled}` (mode rapide : temperature honorée, outils
 *                 sans rejeu du raisonnement), `true` → `enabled` ; absent →
 *                 rien d'envoyé, le défaut de l'API s'applique (réflexion ACTIVE
 *                 chez DeepSeek depuis V4).
 *   refusal_fallback : (optionnel, Anthropic) `'default'` → repli CÔTÉ SERVEUR
 *                 quand les classifieurs du modèle déclinent une demande
 *                 (paramètre bêta `fallbacks`) ; Anthropic choisit Opus 4.8 ou
 *                 Opus 5 selon la catégorie du refus.
 *   retention_fallback : (optionnel) modèle de relais quand l'organisation ne
 *                 remplit pas une condition d'accès (Fable : conservation des
 *                 données 30 j, sinon 400 avant tout traitement).
 *   pricing     : (optionnel) tarif officiel USD / 1M tokens — source unique de
 *                 vérité pour l'écran Admin → Crédits (« Mettre à jour les prix »).
 *                 Clés : `input`, `output`, `cached_input` (défaut input×0.10),
 *                 `cache_write` (défaut input×1.25), `output_reasoning` (défaut
 *                 output), `source_url`, `note`. Renseigné pour les modèles dont
 *                 le lookup web échoue (modèles trop récents). Sinon, les prix
 *                 sont récupérés dynamiquement via LLM + recherche web.
 *                 `launch` : (optionnel) tarif de lancement TEMPORAIRE —
 *                 `until` (AAAA-MM-JJ, inclus) + les tarifs qu'il remplace.
 *                 `LlmCatalog::pricing()` l'applique jusqu'à sa date, puis rend
 *                 le tarif normal ci-dessus, sans intervention.
 *   web_search_pricing_usd_per_1k : (optionnel) tarif de la recherche web DU
 *                 MODÈLE, prioritaire sur celui du provider (une génération
 *                 peut changer de grille : Gemini 2.5 vs 3.x).
 *   web_search_billing : (optionnel) ce que le fournisseur facture comme UNE
 *                 recherche — 'request' (chaque requête lancée, défaut) ou
 *                 'prompt' (une réponse ancrée, quel que soit le nombre de
 *                 requêtes). Même priorité modèle → provider.
 *   retirement  : (optionnel) arrêt du modèle ANNONCÉ par son éditeur —
 *                 `date` (AAAA-MM-JJ, jour de la coupure), `replacement` (id du
 *                 remplaçant désigné, de préférence présent au catalogue),
 *                 `source_url`. Lu par `LlmCatalog::retirement()` : badge et
 *                 points à régler dans l'administration dès l'annonce, puis, À
 *                 LA DATE, le modèle quitte le chat (`LLMFactory::available`) et
 *                 ses conversations et assistants passent au modèle par défaut.
 *                 Ne pas retirer du catalogue un modèle arrêté tant qu'une
 *                 instance peut l'avoir activé : sans son bloc, il redeviendrait
 *                 proposé dans le chat et échouerait à chaque message.
 *
 * Champs par provider :
 *   default_model          : id du modèle par défaut (fallback si aucun modèle "default:true")
 *   endpoints              : URL des endpoints par nom logique
 *   api_version            : header de version éventuel (ex. Anthropic)
 *   web_search_tools       : {primary, fallback} (ids des tools web search)
 *   fallback_search_model  : modèle à utiliser quand webSearch demandé sur un modèle non capable
 *   gpt5_default_routing   : routage par défaut pour les modèles 'gpt-5*' non listés (catch-all)
 *   web_search_pricing_usd_per_1k : (optionnel) tarif officiel USD pour 1 000 recherches
 *                 web exécutées par l'outil serveur du fournisseur — repli de
 *                 CreditService quand aucun service `websearch:{provider}` n'est
 *                 tarifé dans Admin → Crédits. Absent = recherche « non tarifée »
 *                 (signalée sur la page Usages), jamais gratuite en silence.
 *                 Un modèle peut déclarer le sien (cf. champs par modèle).
 *   web_search_billing     : (optionnel) 'request' | 'prompt', cf. champs par modèle.
 *   transcription_pricing_usd_per_minute : (OpenAI) tarif USD par minute d'audio
 *                 des modèles de dictée (clé `default` pour les modèles non listés).
 *   speech_pricing_usd_per_minute : (OpenAI) tarif USD par minute d'audio PRODUIT
 *                 par la synthèse vocale (lecture des réponses en conversation
 *                 vocale, cf. SpeechSynthesizer) ; même forme.
 */

return [

    // ============== OpenAI ==============
    'openai' => [
        'default_model' => 'gpt-5.6',
        // Function-calling (connecteurs, outils) : tous les routages qui
        // portent des outils (cf. LlmCatalog::supportsToolUse) ; la recherche
        // web les accompagne via la Responses API.
        'tool_use'              => true,
        // Présentation réalisée par le modèle (interpréteur de code de la
        // Responses API, NativeDeck) : déclarée PAR MODÈLE — seuls GPT-5.6 et
        // la génération GPT-6 sont listés par OpenAI pour l'outil (relu le
        // 2026-10-14).
        'native_pptx'           => false,
        'tools_with_web_search' => true,
        // Plancher sûr (= plus petite fenêtre du provider) ; les gros modèles
        // surclassent via leur propre `context_window`. Sert au budget RAG.
        'default_context_window' => 128000,
        'endpoints' => [
            'chat_completions' => 'https://api.openai.com/v1/chat/completions',
            'responses'        => 'https://api.openai.com/v1/responses',
        ],
        // Modèle utilisé quand une recherche web est demandée sur un modèle qui
        // ne la fait pas nativement : la recherche passe par la Responses API
        // (outil web_search). Les anciens modèles *-search-preview sont dépréciés
        // par OpenAI (coupure 2026-07-23).
        'fallback_search_model' => 'gpt-5.6',
        'gpt5_default_routing'  => 'responses_api',
        // Outil serveur `web_search` (Responses API) : facturé à l'appel, les
        // tokens de contenu de recherche étant comptés au tarif du modèle.
        'web_search_pricing_usd_per_1k' => 10.00,
        // Code Interpreter (« Version soignée » d'une présentation ou d'un
        // classeur) : conteneur 1 Go facturé « $0.03 per 20-minute session »
        // en plus des jetons — page Pricing, section Tools, relue le
        // 2026-10-17. La facturation à la minute (5 minutes au moins) ne vaut
        // que pour des sessions « éligibles » que la page ne définit pas : la
        // session entière est comptée, jamais moins que ce qui est dû.
        'container_pricing_usd_per_session' => 0.03,
        // Dictée vocale (Realtime transcription) : USD par minute d'audio.
        // Grille « Transcription » de la page Pricing, relue le 2026-10-07. Les
        // deux modèles live (les PREMIERS que la dictée essaie) n'y figuraient
        // pas : ils étaient comptés au défaut de 0,006 $ au lieu de 0,017 $,
        // trois fois moins que ce qu'OpenAI facture.
        'transcription_pricing_usd_per_minute' => [
            'gpt-live-transcribe'    => 0.017,
            'gpt-realtime-whisper'   => 0.017,
            'gpt-transcribe'         => 0.0045,
            'gpt-4o-transcribe'      => 0.006,
            'gpt-4o-mini-transcribe' => 0.003,
            'whisper-1'              => 0.006,
            'default'                => 0.017,
        ],
        // Lecture à voix haute (conversation vocale, POST /v1/audio/speech) :
        // USD par minute d'audio produit. OpenAI facture gpt-4o-mini-tts au
        // token (0,60 $/M en texte, 12 $/M en audio) et publie l'équivalent
        // « ≈ 0,015 $ la minute » ; c'est cette minute, MESURÉE sur l'audio
        // reçu, que MIA facture. Source : https://openai.com/api/pricing/
        // (relue le 2026-09-18).
        'speech_pricing_usd_per_minute' => [
            'gpt-4o-mini-tts' => 0.015,
            'default'         => 0.015,
        ],
        'models' => [
            // GPT-6.1 Sol (29/09/2026) et GPT-6 Luna (22/09/2026), fiches et
            // grille relues le 2026-10-07. Comme Astra, hors du préfixe `gpt-5` :
            // routage DÉCLARÉ (Responses API — Chat Completions n'y porte pas
            // les outils : « Chat Completions is supported without tool
            // calling » pour 6.1 Sol, « only with reasoning_effort none » pour
            // Luna). Effort par défaut `medium`, donc pas de `temperature`
            // (« When reasoning effort is not none, remove temperature »).
            // Contexte 1,05M (entrée max 922k), sortie max 128k. Au-delà de
            // 272k tokens d'entrée, toute la requête est facturée ×2 en entrée
            // et ×1,5 en sortie (palier `long_context`, comme Astra). Non repris :
            // GPT-6 Sol (22/09), même tarif que 6.1 Sol que la fiche désigne
            // comme « the newer Sol model ». Aucun ne devient le défaut.
            'gpt-6.1-sol' => [
                'label'       => 'GPT-6.1 Sol',
                'native_pptx' => true,
                'note'        => 'Sol de la génération GPT-6 (septembre 2026) — performances proches d\'Astra pour un coût bien moindre : code, agents, travail professionnel. Vision, contexte 1,05M.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'      => ['Tarif majoré au-delà de 272k tokens d\'entrée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                // Lecture de cache à 5 % de l'entrée (« Cached input tokens are
                // priced at 5% ») : hors ratio conventionnel, à DÉCLARER.
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 2.00,
                    'output'       => 10.00,
                    'cached_input' => 0.10,
                    'cache_write'  => 2.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6.1-sol',
                    'note'         => 'Tarif officiel OpenAI GPT-6.1 Sol, contexte court (cache lu 0,10 $, écrit 2,50 $). Au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (palier long_context).',
                ],
            ],
            'gpt-6-luna' => [
                'label'       => 'GPT-6 Luna',
                'native_pptx' => true,
                'note'        => 'Modèle le plus économique d\'OpenAI (septembre 2026) — gros volumes, tâches ciblées, faible latence. Vision, contexte 1,05M.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Capacités réduites vs Sol/Astra', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 0.10,
                    'output'       => 0.50,
                    'cached_input' => 0.01,
                    'cache_write'  => 0.125,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6-luna',
                    'note'         => 'Tarif officiel OpenAI GPT-6 Luna, contexte court. Au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (palier long_context).',
                ],
            ],
            // GPT-6 Astra (GA 3 septembre 2026) : modèle frontière, 2,5 fois le
            // tarif PROMOTIONNEL de GPT-5.6 Sol. Pas d'alias nu `gpt-6` :
            // l'identifiant est le seul point d'entrée, Responses API ET Chat
            // Completions. Contexte 1,05M (entrée max 922k), sortie max 128k,
            // cutoff 30 avril 2026, effort de raisonnement de low à max.
            'gpt-6-astra' => [
                'label'       => 'GPT-6 Astra',
                'native_pptx' => true,
                'note'        => 'Modèle frontière OpenAI (septembre 2026) — raisonnement profond, agents, vision, contexte 1,05M. Le plus cher d\'OpenAI (10 $ / 50 $ le million de tokens).',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'      => ['Coût très élevé', 'Tarif majoré au-delà de 272k tokens d\'entrée', 'Pas de temperature (API)'],
                'web_native'  => true,
                // Hors du préfixe `gpt-5` : le routage par défaut du provider
                // ne le rattraperait pas, il est donc déclaré ici.
                'routing'     => 'responses_api',
                'temperature' => false,
                // Écriture de cache facturée (1,25× l'entrée), rapportée par
                // l'API dans `input_tokens_details.cache_write_tokens`.
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 10.00,
                    'output'       => 50.00,
                    'cached_input' => 1.00,
                    'cache_write'  => 12.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6-astra',
                    'note'         => 'Tarif officiel OpenAI GPT-6 Astra, contexte court. Au-delà de 272k tokens d\'entrée, OpenAI facture toute la requête ×2 en entrée et ×1,5 en sortie (palier long_context).',
                ],
            ],
            // Famille GPT-5.6 (GA 9 juillet 2026) : trois tiers Sol / Terra / Luna.
            // L'alias nu 'gpt-5.6' est routé par OpenAI vers Sol. Contexte 1M,
            // sortie max 128k, cutoff 16 février 2026, Responses API recommandée.
            'gpt-5.6' => [
                'label'       => 'GPT-5.6 (alias → Sol)',
                'native_pptx' => true,
                'note'        => 'Alias OpenAI routé vers GPT-5.6 Sol — raisonnement, vision et outils natifs via Responses API.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'default'     => true,
                'routing'     => 'responses_api',
                // GPT-5.x raisonnement : l'API rejette le paramètre temperature
                // (400 « Unsupported parameter »). Ne jamais l'envoyer.
                'temperature' => false,
                // Tarif USD / 1M tokens (cf. section "pricing" en tête de fichier).
                // Tarif PROMOTIONNEL depuis le 21/08/2026 (4 $ / 20 $, cache
                // 0,40 $) « at least through November 21, 2026 » : borne à
                // cette date, puis retour au tarif GA — si OpenAI prolonge, MIA
                // surfacture, jamais l'inverse.
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 5.00,
                    'output'       => 30.00,
                    'cached_input' => 0.50,
                    'launch'       => [
                        'until'        => '2026-11-21',
                        'input'        => 4.00,
                        'output'       => 20.00,
                        'cached_input' => 0.40,
                        'cache_write'  => 5.00,
                    ],
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel (alias = Sol) : promotion 4 $ / 20 $ (cache 0,40 $) au moins jusqu\'au 21/11/2026, puis 5 $ / 30 $ (cache 0,50 $).',
                ],
            ],
            'gpt-5.6-sol' => [
                'label'       => 'GPT-5.6 Sol',
                'native_pptx' => true,
                'note'        => 'Tier frontière de la famille GPT-5.6 — raisonnement profond, agents, tool calling programmatique.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Coût élevé', 'Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 5.00,
                    'output'       => 30.00,
                    'cached_input' => 0.50,
                    'launch'       => [
                        'until'        => '2026-11-21',
                        'input'        => 4.00,
                        'output'       => 20.00,
                        'cached_input' => 0.40,
                        'cache_write'  => 5.00,
                    ],
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Sol : promotion 4 $ / 20 $ (cache 0,40 $) au moins jusqu\'au 21/11/2026, puis 5 $ / 30 $ (cache 0,50 $).',
                ],
            ],
            'gpt-5.6-terra' => [
                'label'       => 'GPT-5.6 Terra',
                'native_pptx' => true,
                'note'        => 'Tier équilibré de la famille GPT-5.6 — proche de GPT-5.5 pour moitié moins cher.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                // Baisse de 20 % le 30/07/2026 (« Terra costs 20% less »).
                'pricing'     => [
                    'input'        => 2.00,
                    'output'       => 12.00,
                    'cached_input' => 0.20,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Terra depuis le 30/07/2026 (relu le 07/10/2026).',
                ],
            ],
            'gpt-5.6-luna' => [
                'label'       => 'GPT-5.6 Luna',
                'native_pptx' => true,
                'note'        => 'Tier rapide et économique de la famille GPT-5.6 — gros volumes, faible latence.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Capacités réduites vs Sol/Terra', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                // Baisse de 80 % le 30/07/2026 (« Luna costs 80% less ») : MIA
                // facturait cinq fois le prix.
                'pricing'     => [
                    'input'        => 0.20,
                    'output'       => 1.20,
                    'cached_input' => 0.02,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Luna depuis le 30/07/2026 (relu le 07/10/2026).',
                ],
            ],
            // `gpt-5.6-pro` retiré : aucun modèle de ce nom chez OpenAI (fiche
            // introuvable, absent de la liste des modèles, relu le 2026-10-07).
            // Le mode Pro de GPT-5.6 passe par `gpt-5.6-sol` + `reasoning.mode:
            // pro` (remplaçant désigné de o1-pro, o3-pro et gpt-5-pro), que MIA
            // n'envoie pas.
            'gpt-5.5' => [
                'label'       => 'GPT-5.5',
                'note'        => 'Génération GPT-5 précédente (avril 2026) — raisonnement, vision et outils natifs via Responses API.',
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                // GPT-5.x raisonnement : l'API rejette le paramètre temperature
                // (400 « Unsupported parameter »). Ne jamais l'envoyer.
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 5.00,
                    'output'       => 30.00,
                    'cached_input' => 0.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-5.5',
                    'note'         => 'Tarif officiel OpenAI GPT-5.5.',
                ],
            ],
            'gpt-5.5-pro' => [
                'label'       => 'GPT-5.5 Pro',
                'note'        => 'Variante haute précision de GPT-5.5 (tâches longues, accuracy max).',
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Coût élevé', 'Latence supérieure', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 30.00,
                    'output'       => 180.00,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.5 Pro, contexte court (< 272k) ; aucun tarif de cache publié. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            'gpt-5.4' => [
                'label'       => 'GPT-5.4',
                'note'        => 'Génération GPT-5 précédente (raisonnement, vision, outils natifs via Responses API).',
                'tags'        => ['vision', 'web', 'fichiers', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 2.50,
                    'output'       => 15.00,
                    'cached_input' => 0.25,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.4, contexte court (< 272k). Traitement standard, relu le 2026-10-17.',
                ],
            ],
            'gpt-5.4-2026-03-05' => [
                'label'       => 'GPT-5.4 (25.03.05)',
                'note'        => 'Snapshot piné de GPT-5.4.',
                'tags'        => ['vision', 'web', 'fichiers', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 2.50,
                    'output'       => 15.00,
                    'cached_input' => 0.25,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.4 (version figée), contexte court (< 272k). Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // Déprécié le 01/10/2026, coupé le 01/04/2027 (remplaçant : GPT-6 Sol,
            // absent du catalogue — GPT-6.1 Sol y est, même tarif).
            'gpt-5.3-codex' => [
                'label'       => 'GPT-5.3 Codex',
                'note'        => 'Modèle orienté développement logiciel et agents. Arrêté par OpenAI le 01/04/2027.',
                'tags'        => ['code', 'vision', 'raisonnement'],
                'limits'      => ['Spécialisé code (moins généraliste)', 'Pas de temperature (API)'],
                'web_native'  => false,
                'routing'     => 'responses_api',
                'temperature' => false,
                'retirement'  => [
                    'date'        => '2027-04-01',
                    'replacement' => 'gpt-6-sol',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 1.75,
                    'output'       => 14.00,
                    'cached_input' => 0.175,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.3 Codex. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // `gpt-5.3-chat-latest` retiré : coupé par OpenAI le 10/08/2026
            // (remplaçant : gpt-5.6-sol) — il répondait « modèle introuvable ».
            // Le seul snapshot de gpt-5-mini (gpt-5-mini-2025-08-07) est coupé le
            // 11/12/2026, remplaçant désigné : gpt-5.6-terra (page Deprecations,
            // relue le 2026-10-14). Aucun re-pointage de l'alias n'est annoncé :
            // le bloc `retirement` le retire du chat à cette date.
            'gpt-5-mini' => [
                'label'       => 'GPT-5 Mini',
                'note'        => 'Version compacte GPT-5, performante et plus économique. Arrêté par OpenAI le 11/12/2026.',
                'tags'        => ['vision', 'web', 'fichiers', 'raisonnement'],
                'limits'      => ['Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'retirement'  => [
                    'date'        => '2026-12-11',
                    'replacement' => 'gpt-5.6-terra',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 0.25,
                    'output'       => 2.00,
                    'cached_input' => 0.025,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5 mini. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            'gpt-5.2' => [
                'label'       => 'GPT-5.2 (legacy)',
                'note'        => 'Ancienne itération GPT-5, conservée pour compatibilité.',
                'tags'        => ['vision', 'web', 'fichiers'],
                'limits'      => ['Legacy', 'Responses API requise pour les outils natifs', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'input'        => 1.75,
                    'output'       => 14.00,
                    'cached_input' => 0.175,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.2. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // Série GPT-4 dépréciée le 14/10/2026 (courriel d'OpenAI + page
            // Deprecations, relue le 2026-10-14) : gpt-4o-2024-08-06,
            // gpt-4o-2024-11-20, gpt-4o-mini-2024-07-18, gpt-4.1-2025-04-14 et
            // gpt-4.1-mini-2025-04-14 coupés le 14/01/2027, remplaçant unique
            // désigné : gpt-6-luna (moins cher que les quatre). Les versions
            // affinées suivent la même date. Le bloc `retirement` les retire du
            // chat ce jour-là — les garder au catalogue jusque-là, et après.
            'gpt-4o' => [
                'label'       => 'GPT-4o',
                'note'        => 'Modèle multimodal précédent (web search via Responses API). Arrêté par OpenAI le 14/01/2027.',
                'tags'        => ['vision', 'fichiers', 'web'],
                'limits'      => [],
                'web_native'  => true,
                'routing'     => 'responses_web_search',
                'temperature' => true,
                'retirement'  => [
                    'date'        => '2027-01-14',
                    'replacement' => 'gpt-6-luna',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 2.50,
                    'output'       => 10.00,
                    'cached_input' => 1.25,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-4o. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            'gpt-4o-mini' => [
                'label'       => 'GPT-4o Mini',
                'note'        => 'Version allégée, plus rapide et moins coûteuse (web search via Responses API). Arrêté par OpenAI le 14/01/2027.',
                'tags'        => ['vision', 'web'],
                'limits'      => ['Contexte réduit'],
                'web_native'  => true,
                'routing'     => 'responses_web_search',
                'temperature' => true,
                'retirement'  => [
                    'date'        => '2027-01-14',
                    'replacement' => 'gpt-6-luna',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 0.15,
                    'output'       => 0.60,
                    'cached_input' => 0.075,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-4o mini. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // gpt-4o-search-preview retiré : déprécié par OpenAI (coupure
            // 2026-07-23). La recherche web passe désormais par la Responses API
            // (outil web_search natif), cf. routing 'responses_web_search'.
            // `gpt-4.5-preview` retiré : coupé par OpenAI le 14/07/2025
            // (remplaçant : gpt-4.1).
            'gpt-4.1' => [
                'label'       => 'GPT-4.1',
                'note'        => 'Contexte 1M tokens, performances améliorées (web search via Responses API). Arrêté par OpenAI le 14/01/2027.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'fichiers', 'web'],
                'limits'      => [],
                'web_native'  => true,
                'routing'     => 'responses_web_search',
                'temperature' => true,
                'retirement'  => [
                    'date'        => '2027-01-14',
                    'replacement' => 'gpt-6-luna',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 2.00,
                    'output'       => 8.00,
                    'cached_input' => 0.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-4.1. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            'gpt-4.1-mini' => [
                'label'       => 'GPT-4.1 Mini',
                'note'        => 'Version rapide de GPT-4.1 (web search via Responses API). Arrêté par OpenAI le 14/01/2027.',
                'tags'        => ['vision', 'web'],
                'limits'      => ['Contexte réduit'],
                'web_native'  => true,
                'routing'     => 'responses_web_search',
                'temperature' => true,
                'retirement'  => [
                    'date'        => '2027-01-14',
                    'replacement' => 'gpt-6-luna',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 0.40,
                    'output'       => 1.60,
                    'cached_input' => 0.10,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-4.1 mini. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // Seul snapshot (o3-2025-04-16) coupé le 11/12/2026, remplaçant
            // désigné : gpt-5.6-sol.
            'o3' => [
                'label'       => 'o3 (raisonnement)',
                'note'        => 'Modèle de raisonnement avancé. Arrêté par OpenAI le 11/12/2026.',
                'tags'        => ['raisonnement'],
                'limits'      => ['Pas de streaming', 'Latence élevée', 'Coût élevé'],
                'web_native'  => false,
                'routing'     => 'chat_completions',
                'temperature' => true,
                'retirement'  => [
                    'date'        => '2026-12-11',
                    'replacement' => 'gpt-5.6-sol',
                    'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
                ],
                'pricing'     => [
                    'input'        => 2.00,
                    'output'       => 8.00,
                    'cached_input' => 0.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI o3. Traitement standard, relu le 2026-10-17.',
                ],
            ],
            // o4-mini retiré : ses snapshots (o4-mini-2025-04-16,
            // o4-mini-deep-research-2025-06-26) sont dépréciés par OpenAI
            // (coupure 2026-10-23). Pour du raisonnement rapide, utiliser o3 ou
            // un modèle GPT-5.x (raisonnement natif).
        ],
    ],

    // ============== Anthropic ==============
    'anthropic' => [
        'default_model' => 'claude-opus-5',
        // Outils applicatifs et outil serveur de recherche dans le même
        // tableau `tools` : rien ne s'exclut.
        'tool_use'              => true,
        // « Réaliser avec {modèle} » une présentation PowerPoint : compétence
        // « pptx » + outil d'exécution de code + Files API (NativeDeck).
        // Tous les modèles Claude actifs sont de la liste de compatibilité
        // de l'exécution de code (Haiku 4.5 et suivants, relue le 2026-10-14).
        'native_pptx'           => true,
        'tools_with_web_search' => true,
        'default_context_window' => 200000,
        // « Ranges from 0.0 to 1.0 » (référence Messages API, relue le
        // 2026-10-07) : Claude 4.6 et Haiku 4.5 acceptent encore `temperature`,
        // un assistant réglé au-delà de 1 recevait un 400 à chaque message.
        'temperature_max' => 1.0,
        'endpoints' => [
            'messages' => 'https://api.anthropic.com/v1/messages',
        ],
        'api_version' => '2023-06-01',
        'web_search_tools' => [
            'primary'  => 'web_search_20260209',
            'fallback' => 'web_search_20250305',
        ],
        // Outil serveur web search : 10 $ les 1 000 recherches (plus les tokens).
        'web_search_pricing_usd_per_1k' => 10.00,
        'models' => [
            // Claude Opus 5.5 (22/09/2026) et Sonnet 5.5 (28/09/2026) ouvrent
            // Anthropic : même génération, l'Opus d'abord comme dans le tableau
            // d'Anthropic. Opus 5.5 est l'Opus courant, MOINS cher
            // qu'Opus 5 (4 $ / 20 $ au lieu de 5 $ / 25 $). Raisonnement
            // TOUJOURS actif — `thinking: disabled` est refusé en 400, MIA
            // n'envoie jamais le champ —, effort par défaut `medium` (Opus 5 :
            // `high`), `tool_choice` forcé refusé (MIA n'envoie que `none`,
            // accepté). Pas le défaut de l'instance : un défaut qui bascule
            // change en silence le comportement de tous les assistants.
            'claude-opus-5-5' => [
                'label'      => 'Claude Opus 5.5',
                'note'       => 'Opus courant d\'Anthropic — agentic longue durée et travail de connaissance, raisonnement toujours actif, contexte 1M. Moins cher qu\'Opus 5.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé'],
                'web_native' => true,
                // L'API rejette temperature/top_p/top_k (400) sur Claude 4.7+.
                'temperature' => false,
                // Classifieurs cyber, bio et extraction du raisonnement : un
                // refus est relayé par Anthropic vers le modèle qu'il
                // recommande pour la catégorie, dans la même requête.
                'refusal_fallback' => 'default',
                // Notes d'avancement entre deux outils rendues en texte
                // (`thinking.display: "updates"`, bêta) et effort par défaut
                // `medium`, relevé à `high` sur un tour à connecteurs — cf.
                // AnthropicAdapter::withAgentFeatures.
                'progress_updates' => true,
                'effort_default'   => 'medium',
                // cached_input DÉCLARÉ : la lecture de cache vaut 0,05×
                // l'entrée (0,20 $) ; le ratio conventionnel (0,1×) donnerait
                // 0,40 $.
                'pricing'    => [
                    'input'        => 4.00,
                    'output'       => 20.00,
                    'cached_input' => 0.20,
                    'cache_write'  => 5.00,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Opus 5.5 (lecture de cache 0,20 $, 0,05× l\'entrée), relu le 30/09/2026.',
                ],
            ],
            // Claude Sonnet 5.5 : même tarif que Sonnet 5.
            // Raisonnement adaptatif par défaut — `thinking: disabled` est
            // refusé (400), c'est `between_tools` qui l'éteint ; MIA n'envoie
            // ni l'un ni l'autre. `tool_choice` forcé refusé, comme Opus 5.5.
            // Le repli serveur ne reprend que les refus `cyber` et
            // `frontier_llm` (sur Sonnet 5) : les autres catégories restent un
            // refus, présenté comme tel.
            'claude-sonnet-5-5' => [
                'label'      => 'Claude Sonnet 5.5',
                'note'       => 'Meilleur équilibre vitesse / intelligence d\'Anthropic — qualité proche Opus sur code et agentic, contexte 1M, au coût Sonnet.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => [],
                'web_native' => true,
                'temperature' => false,
                'refusal_fallback' => 'default',
                'progress_updates' => true,
                'pricing'    => [
                    'input'        => 2.00,
                    'output'       => 10.00,
                    'cached_input' => 0.10,
                    'cache_write'  => 2.50,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Sonnet 5.5 : entrée et sortie de Sonnet 5, mais lecture de cache à 0,10 $ (0,05× l\'entrée, et non 0,20 $), relu le 17/10/2026.',
                ],
            ],
            // Claude Fable 5.1 : successeur de Fable 5, même tier et même
            // tarif, sauf la lecture de cache (0,25 $ au lieu de 1 $). Refuse
            // la temperature, la désactivation du raisonnement et le
            // `tool_choice` forcé (any/tool) — MIA n'envoie que `none`, accepté.
            'claude-fable-5-1' => [
                'label'      => 'Claude Fable 5.1',
                'note'       => 'Modèle le plus capable d\'Anthropic, successeur de Fable 5 au même tarif — raisonnement toujours actif, agentic longue durée, contexte 1M. Requiert une rétention de données 30 j côté organisation Anthropic.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût très élevé', 'Rétention 30 j requise', 'Sujets sensibles repris par Opus (classifieurs cyber/bio)', 'Réflexion parfois longue avant de répondre'],
                'web_native' => true,
                'temperature' => false,
                // Raisonnement toujours actif ET compté dans max_tokens : il
                // déclarait 32 000 quand le défaut valait 4 096. Le défaut de
                // l'adapter (64 000) le couvre désormais, comme tous les Claude.
                // Refus des classifieurs → Anthropic relaie vers Opus (4.8 ou 5
                // selon la catégorie) dans la même requête.
                'refusal_fallback'  => 'default',
                // Organisation en « zéro rétention » : 400 avant tout
                // traitement, MIA rejoue la demande sur Opus 5.
                'retention_fallback' => 'claude-opus-5',
                'progress_updates' => true,
                // cached_input DÉCLARÉ : le ratio conventionnel (0,1× l'entrée)
                // donnerait 1 $, quatre fois le tarif réel de ce modèle.
                'pricing'    => [
                    'input'        => 10.00,
                    'output'       => 50.00,
                    'cached_input' => 0.25,
                    'cache_write'  => 12.50,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/models/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Fable 5.1 (lecture de cache 0,25 $, 0,025× l\'entrée).',
                ],
            ],
            'claude-opus-5' => [
                'label'      => 'Claude Opus 5',
                'note'       => 'Génération Opus précédente (juillet 2026) — raisonnement adaptatif par défaut, agentic, vision HD, contexte 1M. Même tarif qu\'Opus 4.8.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé'],
                'web_native' => true,
                'default'    => true,
                // L'API rejette temperature/top_p/top_k (400) sur Opus 4.7+.
                'temperature' => false,
                // Classifieurs cyber : un refus est relayé par Anthropic vers
                // Opus 4.8 dans la même requête (repli serveur « default »).
                'refusal_fallback' => 'default',
                // Tarif USD / 1M tokens. cache_write = 1.25x input (TTL 5 min),
                // cached_input (lecture) = 0.1x input.
                'pricing'    => [
                    'input'        => 5.00,
                    'output'       => 25.00,
                    'cached_input' => 0.50,
                    'cache_write'  => 6.25,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/models/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Opus 5.',
                ],
            ],
            'claude-fable-5' => [
                'label'      => 'Claude Fable 5',
                'note'       => 'Modèle le plus intelligent d\'Anthropic — raisonnement toujours actif, agentic longue durée, contexte 1M. Requiert une rétention de données 30 j côté organisation Anthropic.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût très élevé', 'Rétention 30 j requise', 'Sujets sensibles repris par Opus (classifieurs cyber/bio)', 'Réflexion parfois longue avant de répondre'],
                'web_native' => true,
                'temperature' => false,
                // Mêmes contraintes que Fable 5.1 : mêmes réglages.
                'refusal_fallback'   => 'default',
                'retention_fallback' => 'claude-opus-5',
                'progress_updates' => true,
                'pricing'    => [
                    'input'        => 10.00,
                    'output'       => 50.00,
                    'cached_input' => 1.00,
                    'cache_write'  => 12.50,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/models/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Fable 5.',
                ],
            ],
            'claude-sonnet-5' => [
                'label'      => 'Claude Sonnet 5',
                'note'       => 'Génération Sonnet précédente — qualité proche Opus sur code et agentic, au coût Sonnet.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => [],
                'web_native' => true,
                'temperature' => false,
                // Le tarif de lancement (2 $ / 10 $) est devenu le tarif
                // définitif : la hausse à 3 $ / 15 $ annoncée pour le
                // 01/09/2026 n'a pas eu lieu (note 3 de la page des tarifs).
                // Le catalogue appliquait 3 $ / 15 $ depuis l'ajout du modèle
                // (03/08/2026), sans modéliser le tarif de lancement.
                'pricing'    => [
                    'input'        => 2.00,
                    'output'       => 10.00,
                    'cached_input' => 0.20,
                    'cache_write'  => 2.50,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Claude Sonnet 5 : le tarif de lancement 2 $/10 $ est devenu définitif (relu le 30/09/2026).',
                ],
            ],
            'claude-opus-4-8' => [
                'label'      => 'Claude Opus 4.8',
                'note'       => 'Génération Opus précédente — agentic, vision HD, contexte 1M.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé'],
                'web_native' => true,
                'temperature' => false,
                // Tarif USD / 1M tokens. cache_write = 1.25x input (TTL 5 min),
                // cached_input (lecture) = 0.1x input.
                'pricing'    => [
                    'input'        => 5.00,
                    'output'       => 25.00,
                    'cached_input' => 0.50,
                    'cache_write'  => 6.25,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/models/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Opus 4.8.',
                ],
            ],
            'claude-opus-4-7' => [
                'label'      => 'Claude Opus 4.7',
                'note'       => 'Génération Opus précédente (avril 2026) — agentic, vision HD, contexte 1M.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé'],
                'web_native' => true,
                'temperature' => false,
                // Les quatre modèles précédents n'avaient AUCUN tarif au
                // catalogue : leurs opérations restaient « non tarifées » tant
                // qu'un prix n'était pas saisi dans Tarifs & crédits.
                'pricing'    => [
                    'input'        => 5.00,
                    'output'       => 25.00,
                    'cached_input' => 0.50,
                    'cache_write'  => 6.25,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Opus 4.7 (relu le 07/10/2026).',
                ],
            ],
            'claude-sonnet-4-6' => [
                'label'      => 'Claude Sonnet 4.6',
                'note'       => 'Modèle équilibré performance / coût.',
                // « Context window: 1M tokens » (fiche du modèle) : le défaut
                // du fournisseur (200 000) coupait de moitié son budget RAG.
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => [],
                'web_native' => true,
                'pricing'    => [
                    'input'        => 3.00,
                    'output'       => 15.00,
                    'cached_input' => 0.30,
                    'cache_write'  => 3.75,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Sonnet 4.6 (relu le 07/10/2026).',
                ],
            ],
            'claude-opus-4-6' => [
                'label'      => 'Claude Opus 4.6',
                'note'       => 'Génération Opus précédente.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => ['Coût élevé'],
                'web_native' => true,
                'pricing'    => [
                    'input'        => 5.00,
                    'output'       => 25.00,
                    'cached_input' => 0.50,
                    'cache_write'  => 6.25,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Opus 4.6 (relu le 07/10/2026).',
                ],
            ],
            'claude-haiku-4-5-20251001' => [
                'label'      => 'Claude Haiku 4.5',
                'note'       => 'Modèle rapide et économique.',
                'tags'       => ['vision', 'web'],
                'limits'     => ['Capacités réduites'],
                'web_native' => true,
                // Plafond de l'API : 64 000 (les autres modèles du catalogue
                // acceptent 128 000). Égal au défaut de l'adapter aujourd'hui,
                // déclaré pour qu'un défaut relevé un jour ne le dépasse pas.
                'max_output_tokens' => 64000,
                'pricing'    => [
                    'input'        => 1.00,
                    'output'       => 5.00,
                    'cached_input' => 0.10,
                    'cache_write'  => 1.25,
                    'source_url'   => 'https://platform.claude.com/docs/en/about-claude/pricing',
                    'note'         => 'Tarif officiel Anthropic Claude Haiku 4.5 (relu le 07/10/2026).',
                ],
            ],
        ],
    ],

    // ============== Mistral ==============
    'mistral' => [
        'default_model' => 'mistral-large-latest',
        // Function-calling au format Chat Completions (tous les modèles du
        // catalogue : Large 3, Medium 3.5, Small, Codestral — page « Function
        // calling » de la doc Mistral, relue le 2026-10-01). La recherche web
        // passe, elle, par la Conversations API : les deux ne se cumulent pas
        // dans une même réponse, le contrôleur arbitre.
        'tool_use'              => true,
        'tools_with_web_search' => false,
        // Mistral EXIGE `name` sur chaque message `tool` (« required fields
        // name, content, tool_call_id »).
        'tool_calling' => ['tool_message_name' => true],
        // `temperature` « maximum: 1.5 » (spécification OpenAPI de Mistral,
        // ChatCompletionRequest, relue le 2026-10-07).
        'temperature_max' => 1.5,
        'default_context_window' => 128000,
        'endpoints' => [
            'chat'          => 'https://api.mistral.ai/v1/chat/completions',
            'conversations' => 'https://api.mistral.ai/v1/conversations',
            'files'         => 'https://api.mistral.ai/v1/files',
        ],
        'web_search_tools' => [
            'primary'  => 'web_search_premium',
            'fallback' => 'web_search',
        ],
        'models' => [
            // Les alias `-latest` sont ceux que Mistral publie sur sa grille
            // tarifaire : ils suivent la génération courante SANS préavis (le
            // 29/04/2026 `mistral-medium-latest` est passé de Medium 3.1 à
            // Medium 3.5, et de 0,40/2,00 $ à 1,50/7,50 $). D'où le doublon
            // systématique alias + snapshot piné : l'alias pour suivre, le
            // snapshot pour figer coût et comportement.
            //
            // Mistral Large 4 (06/10/2026, page « Models » et carte du modèle
            // relues le 2026-10-07) : MoE 52B actifs / 1,05T total, encodeur
            // vision 1,6B, contexte 1M, raisonnement réglable (`reasoning_effort`).
            // En APERÇU PUBLIC (« Public Preview ») : mises à jour silencieuses
            // possibles, aucun alias `-latest` tant qu'il n'est pas en
            // disponibilité générale, retrait possible avant (préavis d'un mois).
            // Identifiants publiés : `mistral-large-4` (génération) et
            // `mistral-large-4-0` (version figée) — c'est elle qu'on retient,
            // pour figer coût et comportement. Pas le défaut : il coûte 2,7 fois
            // Large 3 au tarif normal. Tarif de lancement : « 50% off for 2
            // weeks » à compter du 06/10, sans date de fin écrite — borne
            // prudente au 19/10 inclus (au pire un jour facturé au tarif normal,
            // jamais une sous-facturation muette).
            'mistral-large-4-0' => [
                'label'      => 'Mistral Large 4 (aperçu)',
                'note'       => 'Aperçu public de Mistral Large 4 (oct. 2026) — MoE 52B actifs / 1,05T total, multimodal, contexte 1M, raisonnement réglable. Mistral peut le modifier sans préavis avant sa disponibilité générale.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Aperçu public : comportement susceptible d\'évoluer', 'Plus cher que Large 3'],
                'web_native' => true,
                'pricing'    => [
                    'input'        => 1.36,
                    'output'       => 4.18,
                    'cached_input' => 0.14,
                    'launch'       => [
                        'until'        => '2026-10-19',
                        'input'        => 0.68,
                        'output'       => 2.09,
                        'cached_input' => 0.07,
                    ],
                    'source_url'   => 'https://docs.mistral.ai/inference/pricing',
                    'note'         => 'Tarif officiel Mistral Large 4 : 1,36 $ / 4,18 $ (cache 0,14 $) ; lancement à -50 % (0,68 $ / 2,09 $, cache 0,07 $) pendant deux semaines à compter du 06/10/2026.',
                ],
            ],
            // L'alias `-latest` ne vise que la disponibilité générale : il
            // restera sur Large 3 tant que Large 4 est en aperçu, puis BASCULERA
            // sur Large 4 sans préavis — tarif compris (×2,7 en entrée).
            'mistral-large-latest' => [
                'label'      => 'Mistral Large (latest)',
                'note'       => 'Alias suivant Mistral Large 3 — MoE 41B actifs / 675B total, contexte 256k (déc. 2025). Basculera sur Mistral Large 4 à sa disponibilité générale, tarif compris.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => [],
                'web_native' => true,
                'default'    => true,
                'pricing'    => [
                    'input'      => 0.50,
                    'output'     => 1.50,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Mistral Large 3.',
                ],
            ],
            'mistral-large-2512' => [
                'label'      => 'Mistral Large 3 (25.12)',
                'note'       => 'Snapshot piné de Mistral Large 3.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => [],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 0.50,
                    'output'     => 1.50,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Mistral Large 3.',
                ],
            ],
            'mistral-medium-latest' => [
                'label'      => 'Mistral Medium (latest)',
                'note'       => 'Alias suivant Mistral Medium 3.5 — modèle frontière multimodal, orienté agents et code.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Plus cher que Large 3 en sortie'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Mistral Medium 3.5.',
                ],
            ],
            // Medium 3.5 (28/04/2026, 128B dense, licence MIT modifiée) : fond
            // en un seul modèle l'instruct, le raisonnement et le code —
            // Mistral y a replié Magistral ET Devstral 2, tous deux retirés.
            // Identifiants publiés (carte du modèle, relue le 2026-10-07) :
            // `mistral-medium-3-5` (version figée), `mistral-medium-3`,
            // `mistral-medium-latest`. `mistral-medium-2604`, retenu à l'ajout du
            // modèle, n'apparaît nulle part dans la documentation de Mistral :
            // gardé pour les instances qui l'ont activé (même modèle, même tarif),
            // mais seul `mistral-medium-3-5` est couvert par la politique de
            // cycle de vie (« pin your deployment to a specific major.minor »).
            'mistral-medium-3-5' => [
                'label'      => 'Mistral Medium 3.5',
                'note'       => 'Version figée de Mistral Medium 3.5 (identifiant officiel) — remplace Medium 3.1, Magistral et Devstral 2. Effort de raisonnement configurable, encodeur vision natif.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Plus cher que Large 3 en sortie'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://docs.mistral.ai/inference/pricing',
                    'note'       => 'Tarif officiel Mistral Medium 3.5.',
                ],
            ],
            'mistral-medium-2604' => [
                'label'      => 'Mistral Medium 3.5 (26.04)',
                'note'       => 'Ancien identifiant daté de Mistral Medium 3.5, absent de la documentation de Mistral — préférer « Mistral Medium 3.5 » (mistral-medium-3-5), même modèle et même tarif.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Plus cher que Large 3 en sortie'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://docs.mistral.ai/models/model-cards/mistral-medium-3-5-26-04',
                    'note'       => 'Tarif officiel Mistral Medium 3.5.',
                ],
            ],
            'mistral-small-latest' => [
                'label'      => 'Mistral Small (latest)',
                'note'       => 'Alias suivant Mistral Small 4 — rapide et économique, instruct + raisonnement + code.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Capacités réduites'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 0.15,
                    'output'     => 0.60,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Mistral Small 4.',
                ],
            ],
            'mistral-small-2603' => [
                'label'      => 'Mistral Small 4 (26.03)',
                'note'       => 'Snapshot piné de Mistral Small 4 — modèle hybride (instruct, raisonnement, code) multimodal. Remplace Small 3.2 et Magistral Small.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Capacités réduites'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 0.15,
                    'output'     => 0.60,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Mistral Small 4.',
                ],
            ],
            'codestral-latest' => [
                'label'      => 'Codestral (latest)',
                'note'       => 'Alias recommandé pour la dernière version de Codestral.',
                'tags'       => ['code', 'web'],
                'limits'     => ['Orienté code uniquement'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 0.30,
                    'output'     => 0.90,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Codestral.',
                ],
            ],
            'codestral-2508' => [
                'label'      => 'Codestral 25.08',
                'note'       => 'Modèle orienté code avec support des endpoints chat/completions.',
                'tags'       => ['code', 'web'],
                'limits'     => ['Orienté code uniquement'],
                'web_native' => true,
                'pricing'    => [
                    'input'      => 0.30,
                    'output'     => 0.90,
                    'source_url' => 'https://mistral.ai/pricing/api/',
                    'note'       => 'Tarif officiel Codestral.',
                ],
            ],
            // Retirés du catalogue Mistral, donc retirés d'ici — les laisser
            // afficherait dans le picker des modèles qui répondent 404 :
            //   mistral-small-2506 (Small 3.2)   — retiré le 31/07/2026
            //   mistral-medium-2508 (Medium 3.1) — retiré le 31/08/2026
            //   mistral-medium-2505 (Medium 3)   — retiré le 31/08/2026
            // Remplaçants : Small 4 / Medium 3.5 ci-dessus.
        ],
    ],

    // ============== Perplexity ==============
    'perplexity' => [
        'default_model' => 'sonar-pro',
        // L'API Sonar n'expose pas de function-calling : aucun outil, aucun
        // connecteur. La recherche est intégrée à chaque réponse.
        'tool_use' => false,
        'default_context_window' => 128000,
        // FIN DE SUPPORT de Sonar Chat Completions le 27/09/2026 (guide
        // « Migrate from Sonar to the Agent API », relu le 2026-10-01) : les
        // requêtes synchrones et en flux — celles de MIA — « keep working »,
        // REFORMULÉES en requêtes Agent API (preset `fast` pour Sonar et Sonar
        // Pro, `low` pour Sonar Reasoning Pro), déploiement progressif par
        // modèle, « sans garantie d'exacte compatibilité de chaque paramètre ou
        // champ de réponse ». Aucune date d'arrêt annoncée. Étape suivante :
        // porter PerplexityAdapter sur l'Agent API (`/v1/agent`, format
        // Responses, `preset`) — qui ouvrirait aussi les outils (custom
        // functions). Non fait ici : aucun moyen de l'éprouver sans clé.
        'endpoints' => [
            'chat_completions' => 'https://api.perplexity.ai/chat/completions',
        ],
        // Frais PAR REQUÊTE de Sonar, en plus des jetons (5 à 14 $ les 1 000
        // selon le modèle et la taille du contexte de recherche) : chaque
        // réponse en paie un, l'adaptateur en compte donc un par réponse
        // écrite. Tarif du contexte `low`, celui par défaut — MIA n'en
        // demande pas d'autre. Déclaré par modèle.
        'web_search_billing' => 'prompt',
        // Retiré par Perplexity, donc retiré d'ici :
        //   sonar-reasoning — « deprecated and removed from the API » le
        //   15/12/2025 (changelog Perplexity) ; remplaçant : Sonar Reasoning Pro.
        'models' => [
            'sonar-pro' => [
                'label'      => 'Sonar Pro',
                'note'       => 'Modèle puissant avec recherche web native en temps réel.',
                'tags'       => ['web'],
                'limits'     => ['Fin de support annoncée par Perplexity (27/09/2026), toujours servi'],
                'web_native' => true,
                'default'    => true,
                'web_search_pricing_usd_per_1k' => 6.00,
                'pricing'    => [
                    'input'      => 3.00,
                    'output'     => 15.00,
                    'source_url' => 'https://docs.perplexity.ai/docs/getting-started/pricing',
                    'note'       => 'Tarif officiel Perplexity Sonar Pro (jetons) ; + 6 $ les 1 000 requêtes (contexte de recherche « low »), compté comme recherche.',
                ],
            ],
            'sonar' => [
                'label'      => 'Sonar',
                'note'       => 'Recherche web rapide et économique.',
                'tags'       => ['web'],
                'limits'     => ['Capacités réduites vs Sonar Pro', 'Fin de support annoncée par Perplexity (27/09/2026), toujours servi'],
                'web_native' => true,
                'web_search_pricing_usd_per_1k' => 5.00,
                'pricing'    => [
                    'input'      => 1.00,
                    'output'     => 1.00,
                    'source_url' => 'https://docs.perplexity.ai/docs/getting-started/pricing',
                    'note'       => 'Tarif officiel Perplexity Sonar (jetons) ; + 5 $ les 1 000 requêtes (contexte de recherche « low »), compté comme recherche.',
                ],
            ],
            'sonar-reasoning-pro' => [
                'label'       => 'Sonar Reasoning Pro',
                'note'        => 'Raisonnement avancé avec recherche web intégrée.',
                'tags'        => ['web', 'raisonnement'],
                'limits'      => ['Pas de temperature', 'Latence élevée', 'Fin de support annoncée par Perplexity (27/09/2026), toujours servi'],
                'web_native'  => true,
                'temperature' => false,
                'web_search_pricing_usd_per_1k' => 6.00,
                'pricing'     => [
                    'input'      => 2.00,
                    'output'     => 8.00,
                    'source_url' => 'https://docs.perplexity.ai/docs/getting-started/pricing',
                    'note'       => 'Tarif officiel Perplexity Sonar Reasoning Pro (jetons) ; + 6 $ les 1 000 requêtes (contexte de recherche « low »), compté comme recherche.',
                ],
            ],
        ],
    ],

    // ============== Gemini ==============
    // Sources vérifiées le 16/09/2026 : ai.google.dev/gemini-api/docs/models,
    // /pricing, /deprecations et /changelog.
    'gemini' => [
        // Défaut inchangé : Gemini 3.8, 3.7 et 3.6 Flash coûteront 1,50 $ /
        // 7,50 $ le million au 1er janvier 2027, plus du double de Gemini 3
        // Flash — un défaut qui bascule en silence changerait la facture de
        // l'instance. Gemini 2.5 : accès réservé par Google aux comptes qui
        // l'utilisaient déjà (18/09/2026), cf. ses modèles.
        'default_model' => 'gemini-3-flash-preview',
        'default_context_window' => 1000000,
        // Function-calling natif (functionDeclarations), boucle dans
        // GeminiAdapter. Gemini 3 cumule recherche Google et outils dans le
        // même appel (« Gemini 3 allows the use of built-in tools … and custom
        // function calling tools in the same API call », guide Gemini 3, relu le
        // 2026-10-01) ; la génération 2.5 ne le sait pas, d'où `tool_use` faux
        // sur ses modèles : la recherche Internet, cochée par défaut, y aurait
        // été éteinte à chaque message qui porte un outil.
        'tool_use'              => true,
        'tools_with_web_search' => true,
        'endpoints' => [
            // URL construite dynamiquement côté adapter : {base}/{model}:streamGenerateContent
            'base' => 'https://generativelanguage.googleapis.com/v1beta/models/',
            // Pour le test-key : endpoint OpenAI-compat
            'openai_compat' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        ],
        // Grounding with Google Search, génération 3.x : facturé À LA REQUÊTE —
        // une réponse peut en lancer plusieurs, chacune comptée —, 14 $ les
        // 1 000 au-delà de 5 000 gratuites par mois partagées entre modèles 3.x
        // (franchise non modélisée : MIA compte tout au tarif). La génération
        // 2.5 facture encore le PROMPT ANCRÉ, 35 $ les 1 000 : déclaré sur
        // chacun de ses modèles.
        'web_search_pricing_usd_per_1k' => 14.00,
        // Gemini 3 : Google a déprécié temperature / top_p / top_k (21/07/2026)
        // et déconseille toute autre valeur que 1,0 (boucles, raisonnement
        // dégradé) — ces modèles sont donc tous marqués temperature=false.
        // La réflexion est active par défaut et facturée au tarif de sortie.
        'models' => [
            // Gemini 3.8 Flash (GA 2 septembre 2026) : le Flash le plus capable,
            // pensé pour le code au long cours et les agents. Réflexion `medium`
            // par défaut (`minimal` refusé) ; il consomme plus de tokens que
            // 3.7 Flash sur les tâches complexes, par conception.
            'gemini-3.8-flash' => [
                'label'      => 'Gemini 3.8 Flash',
                'note'       => 'Flash le plus capable de Google (septembre 2026) — code au long cours, agents, tâches complexes en plusieurs étapes ; vision, contexte 1M. Tarif de lancement 0,75 $/3,75 $ jusqu\'au 31/12/2026.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Réflexion plus longue (plus de tokens) sur les tâches complexes', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 1.50,
                    'output'       => 7.50,
                    'cached_input' => 0.15,
                    // Tarif de lancement commun à 3.8, 3.7 et 3.6 Flash.
                    'launch'       => [
                        'until'        => '2026-12-31',
                        'input'        => 0.75,
                        'output'       => 3.75,
                        'cached_input' => 0.075,
                    ],
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.8 Flash : lancement 0,75 $ / 3,75 $ (cache 0,075 $) jusqu\'au 31/12/2026, puis 1,50 $ / 7,50 $ (cache 0,15 $). Sortie = réponse + réflexion.',
                ],
            ],
            // Gemini 3.7 Flash (GA 13 août 2026) : Google le garde pleinement
            // supporté comme alternative à 3.8 Flash quand ses longues
            // vérifications ne se justifient pas. Réflexion `medium` par défaut.
            'gemini-3.7-flash' => [
                'label'      => 'Gemini 3.7 Flash',
                'note'       => 'Flash d\'août 2026 — code, agents et exécution fiable en plusieurs étapes ; moins gourmand en tokens que 3.8 Flash, au même tarif. Tarif de lancement 0,75 $/3,75 $ jusqu\'au 31/12/2026.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 1.50,
                    'output'       => 7.50,
                    'cached_input' => 0.15,
                    'launch'       => [
                        'until'        => '2026-12-31',
                        'input'        => 0.75,
                        'output'       => 3.75,
                        'cached_input' => 0.075,
                    ],
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.7 Flash : lancement 0,75 $ / 3,75 $ (cache 0,075 $) jusqu\'au 31/12/2026, puis 1,50 $ / 7,50 $ (cache 0,15 $). Sortie = réponse + réflexion.',
                ],
            ],
            // Gemini 3.6 Flash (GA 21 juillet 2026) : « génération précédente »
            // chez Google, qui le désigne en remplaçant de Gemini 3 Flash
            // (preview) ; même tarif et même lancement que 3.7 et 3.8 Flash.
            'gemini-3.6-flash' => [
                'label'      => 'Gemini 3.6 Flash',
                'note'       => 'Flash de juillet 2026 — vitesse et multimodal pour les tâches courantes et agentiques ; remplaçant désigné de Gemini 3 Flash (preview). Tarif de lancement 0,75 $/3,75 $ jusqu\'au 31/12/2026.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 1.50,
                    'output'       => 7.50,
                    'cached_input' => 0.15,
                    'launch'       => [
                        'until'        => '2026-12-31',
                        'input'        => 0.75,
                        'output'       => 3.75,
                        'cached_input' => 0.075,
                    ],
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.6 Flash : lancement 0,75 $ / 3,75 $ (cache 0,075 $) jusqu\'au 31/12/2026, puis 1,50 $ / 7,50 $ (cache 0,15 $). Sortie = réponse + réflexion.',
                ],
            ],
            // Gemini 3.5 Flash-Lite (GA 21 juillet 2026) : successeur désigné
            // de Gemini 3.1 Flash-Lite. Réflexion `minimal` par défaut.
            'gemini-3.5-flash-lite' => [
                'label'      => 'Gemini 3.5 Flash-Lite',
                'note'       => 'Le plus rapide et le plus économique de la génération 3.5 (juillet 2026) — gros volumes, traduction, traitements simples.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => ['Capacités réduites', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 0.30,
                    'output'       => 2.50,
                    'cached_input' => 0.03,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.5 Flash-Lite (cache 0,03 $). Sortie = réponse + réflexion.',
                ],
            ],
            // Gemini 3.5 Flash (GA 19 mai 2026) : classé « legacy » par Google,
            // sans date d'arrêt annoncée, et plus cher que ses successeurs 3.6
            // à 3.8 Flash (1,50 $ / 9 $, sans tarif de lancement). Au
            // catalogue pour les instances qui l'ont choisi — jamais le défaut.
            'gemini-3.5-flash' => [
                'label'      => 'Gemini 3.5 Flash',
                'note'       => 'Flash de mai 2026, classé « legacy » par Google — 1,50 $ / 9 $ le million, plus cher que ses successeurs 3.6 à 3.8 Flash.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Legacy chez Google (préférer 3.6 à 3.8 Flash)', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 1.50,
                    'output'       => 9.00,
                    'cached_input' => 0.15,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.5 Flash (cache 0,15 $). Sortie = réponse + réflexion.',
                ],
            ],
            // Gemini 3.1 Flash-Lite (GA 7 mai 2026) : le moins cher de la
            // génération 3. Arrêt annoncé par Google au plus tôt le 07/05/2027,
            // remplaçant désigné Gemini 3.5 Flash-Lite — à retirer d'ici à
            // cette date.
            'gemini-3.1-flash-lite' => [
                'label'      => 'Gemini 3.1 Flash-Lite',
                'note'       => 'Le moins cher de la génération 3 (0,25 $ / 1,50 $) — gros volumes, tâches simples. Arrêt annoncé par Google au plus tôt le 07/05/2027.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => ['Capacités réduites', 'Arrêt annoncé au plus tôt le 07/05/2027 (remplaçant : Gemini 3.5 Flash-Lite)', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 0.25,
                    'output'       => 1.50,
                    'cached_input' => 0.025,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.1 Flash-Lite (texte, image, vidéo ; cache 0,025 $). Audio en entrée à 0,50 $ — non modélisé. Sortie = réponse + réflexion.',
                ],
            ],
            // Classé « legacy » par Google, sans date d'arrêt annoncée
            // (remplaçant recommandé : Gemini 3.6 Flash, ci-dessus).
            'gemini-3-flash-preview' => [
                'label'      => 'Gemini 3 Flash (preview)',
                'note'       => 'Flash de la génération 3 (décembre 2025), rapide et polyvalent.',
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Preview — peut évoluer', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'default'    => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 0.50,
                    'output'       => 3.00,
                    'cached_input' => 0.05,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3 Flash Preview (texte, image, vidéo ; cache 0,05 $). Sortie = réponse + réflexion.',
                ],
            ],
            'gemini-3.1-pro-preview' => [
                'label'      => 'Gemini 3.1 Pro (preview)',
                'note'       => 'Modèle premium Gemini 3.1 (raisonnement avancé, long contexte).',
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Preview — peut évoluer', 'Coût élevé', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'long_context' => ['above' => 200000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 200k jetons de prompt : entrée ×2, sortie ×1,5 (page Pricing de Gemini, relue le 2026-10-17)
                    'input'        => 2.00,
                    'output'       => 12.00,
                    'cached_input' => 0.20,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 3.1 Pro Preview, requêtes ≤ 200k tokens. Au-delà, 4 $ / 18 $ (cache 0,40 $) — palier long_context.',
                ],
            ],
            // Coupé par Google, donc retiré d'ici — le laisser afficherait dans
            // le picker un modèle qui répond 404 :
            //   gemini-3.1-flash-lite-preview — coupé le 25/05/2026
            // Remplaçant : Gemini 3.5 Flash-Lite ci-dessus.
            //
            // Génération 2.5 : depuis le 18/09/2026, Google en RÉSERVE l'accès
            // aux comptes qui l'utilisaient déjà (« not deprecated », servie
            // « until further notice ») et renvoie vers 3.5 Flash-Lite ou 3.8
            // Flash pour tout nouveau projet. Gardée au catalogue (une instance
            // qui l'a activée continue de la servir) ; la limite le dit.
            'gemini-2.5-pro' => [
                'label'      => 'Gemini 2.5 Pro',
                // Recherche Google et outils ne se cumulent pas en 2.5 (cf. bloc).
                'tool_use'   => false,
                'note'       => 'Modèle stable haut de gamme pour tâches complexes.',
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé', 'Accès réservé par Google aux comptes qui l\'utilisaient déjà (18/09/2026)'],
                'web_native' => true,
                // Génération 2.5 : recherche Google facturée au prompt ancré.
                'web_search_billing'            => 'prompt',
                'web_search_pricing_usd_per_1k' => 35.00,
                'pricing'    => [
                    'long_context' => ['above' => 200000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 200k jetons de prompt : entrée ×2, sortie ×1,5 (page Pricing de Gemini, relue le 2026-10-17)
                    'input'        => 1.25,
                    'output'       => 10.00,
                    'cached_input' => 0.125,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 2.5 Pro, requêtes ≤ 200k tokens. Au-delà, 2,50 $ / 15 $ (cache 0,25 $) — palier long_context.',
                ],
            ],
            'gemini-2.5-flash' => [
                'label'      => 'Gemini 2.5 Flash',
                // Recherche Google et outils ne se cumulent pas en 2.5 (cf. bloc).
                'tool_use'   => false,
                'note'       => 'Modèle stable et rapide pour usage quotidien.',
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => ['Accès réservé par Google aux comptes qui l\'utilisaient déjà (18/09/2026)'],
                'web_native' => true,
                'web_search_billing'            => 'prompt',
                'web_search_pricing_usd_per_1k' => 35.00,
                'pricing'    => [
                    'input'        => 0.30,
                    'output'       => 2.50,
                    'cached_input' => 0.03,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 2.5 Flash (texte, image, vidéo ; cache 0,03 $).',
                ],
            ],
            'gemini-2.5-flash-lite' => [
                'label'      => 'Gemini 2.5 Flash-Lite',
                // Recherche Google et outils ne se cumulent pas en 2.5 (cf. bloc).
                'tool_use'   => false,
                'note'       => 'Version la plus économique de la famille 2.5.',
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => ['Capacités réduites', 'Accès réservé par Google aux comptes qui l\'utilisaient déjà (18/09/2026)'],
                'web_native' => true,
                'web_search_billing'            => 'prompt',
                'web_search_pricing_usd_per_1k' => 35.00,
                'pricing'    => [
                    'input'        => 0.10,
                    'output'       => 0.40,
                    'cached_input' => 0.01,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing',
                    'note'         => 'Tarif officiel Google Gemini 2.5 Flash-Lite (texte, image, vidéo ; cache 0,01 $).',
                ],
            ],
        ],
    ],

    // ============== DeepSeek ==============
    'deepseek' => [
        // DeepSeek V4.1 Flash (10 septembre 2026) est le SEUL modèle servi par
        // l'API (Models & Pricing et référence de l'API, relues le 2026-10-01) :
        //   - `deepseek-chat` (V3) et `deepseek-reasoner` (R1), les deux seules
        //     entrées d'avant, sont RETIRÉS et inaccessibles depuis le
        //     24/07/2026 15:59 UTC (annonce « DeepSeek V4 Preview Release » du
        //     24/04/2026) : chaque message échouait en « modèle introuvable » ;
        //   - `deepseek-v4-flash` et `deepseek-v4-flash-vision-exp` sont des
        //     alias provisoires servis par V4.1 Flash — inutiles ici ;
        //   - `deepseek-v4-pro` : l'annonce du 10/09 le disait relayé sur V4.1
        //     Flash à partir du 14/09/2026 ; DeepSeek s'est ravisé (Change Log :
        //     « continue providing API services for DeepSeek V4 Pro after
        //     September 14, 2026, with the billing method remaining unchanged »,
        //     relu le 2026-10-07) et la grille le sert encore (V4-Pro-0813,
        //     1,32 $ / 3,96 $ en pointe, sans vision). Pas proposé ici pour
        //     autant : DeepSeek le met « progressivement » à la retraite et le
        //     dit dépassé par V4.1 Flash, quatre fois moins cher. À revoir à la
        //     sortie de V4.1 Pro.
        'default_model' => 'deepseek-flash',
        // Function-calling au format Chat Completions, en mode SANS réflexion
        // (cf. `thinking` du modèle).
        'tool_use' => true,
        'default_context_window' => 1000000,
        'endpoints' => [
            'chat_completions' => 'https://api.deepseek.com/chat/completions',
        ],
        'models' => [
            'deepseek-flash' => [
                'label'       => 'DeepSeek V4.1 Flash',
                'note'        => 'Modèle unique de DeepSeek (septembre 2026) — MoE 552B, contexte 1M, vision native, très économique (0,30 $ / 1,20 $ en heures de pointe, moitié prix en heures creuses).',
                'context_window' => 1000000,
                'tags'        => ['vision', 'code'],
                'limits'      => ['Pas de recherche web native', 'Mode réflexion coupé par MIA (réponse rapide)', 'Tarif de pointe compté même en heures creuses'],
                'web_native'  => false,
                'default'     => true,
                'routing'     => 'chat_completions',
                'temperature' => true,
                // La réflexion est ACTIVE PAR DÉFAUT chez DeepSeek (effort
                // `high`). MIA la coupe (`thinking: {type: disabled}`) : en mode
                // réflexion, `temperature` est ignorée, et avec des outils le
                // `reasoning_content` de TOUS les tours passés doit être renvoyé
                // (400 sinon — guide « Thinking Mode », relu le 2026-10-01),
                // que MIA ne conserve pas. Sans ce réglage explicite, chaque
                // message portant un outil échouerait dès le second tour.
                'thinking'    => false,
                // Sans `max_tokens`, DeepSeek borne la sortie à 8K en mode sans
                // réflexion : une page web ou un long rapport seraient coupés.
                // L'API accepte jusqu'à 384K ; seuls les jetons produits sont
                // facturés.
                'max_output_tokens' => 65536,
                'pricing'     => [
                    // Tarif de POINTE (01:00–04:00 et 06:00–10:00 UTC, jours
                    // ouvrés hors fériés chinois) ; les heures creuses valent
                    // la moitié — non modélisé, MIA compte tout au tarif de
                    // pointe (jamais de sous-facturation muette). Cache
                    // automatique : lecture 0,006 $, aucune surfacturation à
                    // l'écriture (un cache miss est facturé au tarif d'entrée).
                    'input'        => 0.30,
                    'output'       => 1.20,
                    'cached_input' => 0.006,
                    'cache_write'  => 0.30,
                    'source_url'   => 'https://api-docs.deepseek.com/quick_start/pricing',
                    'note'         => 'Tarif officiel DeepSeek V4.1 Flash en heures de pointe (cache hit 0,006 $). Heures creuses (nuits, week-ends et fériés chinois) : moitié prix, non modélisé.',
                ],
            ],
        ],
    ],

    // ============== Moonshot (Kimi) ==============
    'moonshot' => [
        'default_model' => 'kimi-k3',
        // Function-calling au format Chat Completions. Kimi K3 raisonne
        // toujours et refuse un appel d'outil rejoué sans son
        // `reasoning_content` (renvoyé par la boucle, cf.
        // ChatCompletionsToolLoopTrait) ; ses exemples nomment la fonction
        // sur chaque résultat.
        'tool_use'     => true,
        'tool_calling' => ['tool_message_name' => true],
        'default_context_window' => 128000,
        'endpoints' => [
            'chat_completions' => 'https://api.moonshot.ai/v1/chat/completions',
        ],
        // Pages Model List, Pricing, Model Parameter Reference et Platform
        // Changelog de Moonshot relues le 2026-10-01 (platform.kimi.ai/docs,
        // API toujours sur api.moonshot.ai). Retirés par Moonshot : kimi-k2.5
        // et toute la série moonshot-v1 (31/08/2026, 404), la série kimi-k2
        // (25/05/2026). Écarté à dessein : kimi-k2.7-code (et -highspeed) —
        // « Preserved Thinking » toujours actif, le `reasoning_content` de
        // TOUS les messages passés est exigé (« must therefore (not
        // optionally) keep »), que MIA ne conserve pas.
        //
        // Non vérifié faute de clé : pour K3, la doc dit ce même raisonnement
        // passé « required in multi-turn conversations » sans dire qu'une
        // absence est refusée ; MIA ne le renvoie qu'à l'intérieur d'une
        // boucle d'outils (`reasoning_echo`).
        'models' => [
            'kimi-k3' => [
                'label'       => 'Kimi K3',
                'note'        => 'Modèle frontière Moonshot (juillet 2026) — MoE 2,8T paramètres, raisonnement natif, contexte 1M.',
                'context_window' => 1048576,
                'tags'        => ['vision', 'raisonnement'],
                'limits'      => ['Pas de recherche web native', 'Pas de temperature (API)'],
                'web_native'  => false,
                'default'     => true,
                'routing'     => 'chat_completions',
                // Échantillonnage figé côté Moonshot : temperature / top_p / seed
                // rejetés par l'API. Ne jamais les envoyer.
                'temperature' => false,
                'pricing'     => [
                    'input'        => 3.00,
                    'output'       => 15.00,
                    'cached_input' => 0.30,
                    // Écriture de cache (TTL 5 min, défaut) au prix de
                    // l'entrée : 1,0×, pas le 1,25× conventionnel.
                    'cache_write'  => 3.00,
                    'source_url'   => 'https://platform.kimi.ai/docs/pricing/chat',
                    'note'         => 'Tarif officiel Moonshot Kimi K3 (cache-hit 0,30 $ ; écriture de cache 3 $ en TTL 5 min, 6 $ en TTL 1 h — MIA ne demande pas le TTL 1 h).',
                ],
            ],
            // Kimi K2.6 (avril 2026) : le généraliste économique de Moonshot,
            // texte, image et vidéo, 256K. Réflexion active par défaut ; MIA la
            // laisse (le raisonnement est rendu dans la boucle d'outils), et
            // n'envoie ni `thinking` ni `max_completion_tokens` — un plafond
            // tel que entrée + plafond > contexte est REFUSÉ par l'API.
            'kimi-k2.6' => [
                'label'       => 'Kimi K2.6',
                'note'        => 'Généraliste économique Moonshot (avril 2026) — texte, image et vidéo, raisonnement, contexte 256K.',
                'context_window' => 262144,
                'tags'        => ['vision', 'raisonnement'],
                'limits'      => ['Pas de recherche web native', 'Pas de temperature (API)'],
                'web_native'  => false,
                'routing'     => 'chat_completions',
                // « Cannot be modified » (1,0 en réflexion) : ne jamais l'envoyer.
                'temperature' => false,
                'pricing'     => [
                    'input'        => 0.95,
                    'output'       => 4.00,
                    'cached_input' => 0.16,
                    // Série K2 : aucune surfacturation à l'écriture de cache.
                    'cache_write'  => 0.95,
                    'source_url'   => 'https://platform.kimi.ai/docs/pricing/chat',
                    'note'         => 'Tarif officiel Moonshot Kimi K2.6 (cache-hit 0,16 $).',
                ],
            ],
        ],
    ],

    // ============== Z.ai (GLM) ==============
    'zai' => [
        'default_model' => 'glm-5.2',
        // Function-calling au format Chat Completions. `tool_choice` « only
        // supports auto » (doc Z.ai, Function Calling, relue le 2026-10-01) :
        // la synthèse du dernier aller passe par une consigne.
        'tool_use'     => true,
        'tool_calling' => ['tool_choice_none' => false],
        // GLM accepte temperature ∈ [0, 1].
        'temperature_max' => 1.0,
        'default_context_window' => 128000,
        'endpoints' => [
            'chat_completions' => 'https://api.z.ai/api/paas/v4/chat/completions',
        ],
        // Pages Pricing, New Released, GLM-5.3, GLM-5.3-Flash et référence
        // de l'API Z.ai relues le 2026-10-01. Défaut inchangé (GLM 5.2) : un
        // nouveau modèle phare ne devient pas le défaut en silence. GLM-5.3 et
        // 5.3-Flash raisonnent TOUJOURS (`thinking: disabled` refusé) : MIA
        // n'envoie pas `thinking`, et la boucle d'outils rend le raisonnement
        // (« thinking blocks should be explicitly preserved and returned
        // together with the tool results », guide Thinking Mode). Non repris :
        // GLM-5.3-FlashX (variante rapide de 5.3-Flash, 0,37 $ / 1,25 $).
        'models' => [
            'glm-5.3-flash' => [
                'label'       => 'GLM 5.3 Flash',
                'note'        => 'Modèle économique Z.ai (août 2026) — vision native (images), raisonnement, contexte 1M, sortie 128k.',
                'context_window' => 1000000,
                'tags'        => ['vision', 'raisonnement', 'code'],
                'limits'      => ['Pas de recherche web native', 'Réflexion toujours active'],
                'web_native'  => false,
                'routing'     => 'chat_completions',
                'temperature' => true,
                'pricing'     => [
                    'input'        => 0.15,
                    'output'       => 0.50,
                    'cached_input' => 0.03,
                    // Stockage du cache gratuit pour un temps limité, aucune
                    // surfacturation à l'écriture.
                    'cache_write'  => 0.15,
                    'source_url'   => 'https://docs.z.ai/guides/overview/pricing',
                    'note'         => 'Tarif officiel Z.ai GLM-5.3-Flash (cache 0,03 $).',
                ],
            ],
            'glm-5.3' => [
                'label'       => 'GLM 5.3',
                'note'        => 'Modèle phare Z.ai (août 2026) — code et agents, raisonnement toujours actif, contexte 1M, sortie 128k.',
                'context_window' => 1000000,
                'tags'        => ['raisonnement', 'code'],
                'limits'      => ['Pas de vision', 'Pas de recherche web native', 'Réflexion toujours active'],
                'web_native'  => false,
                'routing'     => 'chat_completions',
                'temperature' => true,
                'pricing'     => [
                    'input'        => 1.40,
                    'output'       => 4.40,
                    'cached_input' => 0.26,
                    'cache_write'  => 1.40,
                    'source_url'   => 'https://docs.z.ai/guides/overview/pricing',
                    'note'         => 'Tarif officiel Z.ai GLM-5.3 (cache 0,26 $), identique à GLM-5.2.',
                ],
            ],
            'glm-5.2' => [
                'label'       => 'GLM 5.2',
                'note'        => 'Modèle open-weights Z.ai (juin 2026) — MoE 744B paramètres, contexte 1M, sortie 128k, licence MIT.',
                'context_window' => 1000000,
                'tags'        => ['raisonnement', 'code'],
                'limits'      => ['Pas de vision', 'Pas de recherche web native'],
                'web_native'  => false,
                'default'     => true,
                'routing'     => 'chat_completions',
                'temperature' => true,
                'pricing'     => [
                    'input'        => 1.40,
                    'output'       => 4.40,
                    'cached_input' => 0.26,
                    'cache_write'  => 1.40,
                    'source_url'   => 'https://docs.z.ai/guides/overview/pricing',
                    'note'         => 'Tarif officiel Z.ai GLM 5.2 (cache 0,26 $).',
                ],
            ],
        ],
    ],
];
