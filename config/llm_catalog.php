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
 *   retired_models         : (optionnel) { id => {label, retirement} } — modèles que
 *                 MIA ne propose pas (retirés du catalogue, ou jamais proposés)
 *                 mais qu'une instance peut encore porter (ligne `llm_models`
 *                 activée avant leur retrait, modèle de référence d'une clé).
 *                 Seul leur arrêt est déclaré, même forme que `retirement` ;
 *                 lu par `LlmCatalog::retirement()` en repli de `models`. Un
 *                 modèle quittant le catalogue alors qu'il est encore servi y
 *                 passe, avec la date de sa coupure.
 *   web_search_pricing_usd_per_1k : (optionnel) tarif officiel USD pour 1 000 recherches
 *                 web exécutées par l'outil serveur du fournisseur — repli de
 *                 CreditService quand aucun service `websearch:{provider}` n'est
 *                 tarifé dans Admin → Crédits. Absent = recherche « non tarifée »
 *                 (signalée sur la page Usages), jamais gratuite en silence.
 *                 Un modèle peut déclarer le sien (cf. champs par modèle).
 *   web_search_billing     : (optionnel) 'request' | 'prompt', cf. champs par modèle.
 *   transcription_pricing_usd_per_minute : (OpenAI, Mistral) tarif USD par minute
 *                 d'audio des modèles de dictée (clé `default` pour les modèles
 *                 non listés). Mistral = Voxtral, cf. Dictation.
 *   speech_pricing_usd_per_minute : (OpenAI) tarif USD par minute d'audio PRODUIT
 *                 par la synthèse vocale (lecture des réponses en conversation
 *                 vocale, cf. SpeechSynthesizer) ; même forme.
 *   speech_pricing_usd_per_1m_chars : (Mistral) tarif USD par million de
 *                 caractères LUS (Voxtral TTS, facturé au texte) ; même forme.
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
            // Liste des modèles du compte et leur `shutdown_date` : relevée
            // chaque jour par LlmShutdownWatch (lecture seule, non facturée).
            'models'           => 'https://api.openai.com/v1/models',
        ],
        // Modèle utilisé quand une recherche web est demandée sur un modèle qui
        // ne la fait pas nativement : la recherche passe par la Responses API
        // (outil web_search). Les anciens modèles *-search-preview sont dépréciés
        // par OpenAI (coupure 2026-07-23).
        'fallback_search_model' => 'gpt-5.6',
        // Recherche des prix (Tarifs & crédits → « Mettre à jour coûts
        // Internet ») : lire quelques pages de tarifs ne demande ni un modèle
        // frontière ni un raisonnement long — le plus rapide et le moins cher
        // des modèles qui cherchent sur le web. Le modèle de la clé ne sert
        // qu'en repli (compte sans accès à celui-ci).
        'price_lookup_model'    => 'gpt-6-luna',
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
        // Lecture à voix haute (conversation vocale) : USD par minute d'audio
        // produit, MESURÉE sur l'audio reçu (SpeechSynthesizer).
        //  - gpt-4o-mini-tts (POST /v1/audio/speech, arrêté le 06/01/2027) :
        //    facturé au token (0,60 $/M en texte, 12 $/M en audio), équivalent
        //    publié « ≈ 0,015 $ la minute » (page Pricing, relue le 2026-09-18).
        //  - gpt-realtime-2.1-mini (API Realtime, RealtimeSpeech) : texte
        //    0,60 $/M en entrée, 2,40 $/M en sortie (raisonnement et transcript
        //    compris), audio 20 $/M en sortie à 1 jeton par 50 ms = 0,024 $ la
        //    minute (fiche du modèle et guide « Voice latency and cost », relus
        //    le 2026-11-10). Mesuré le 2026-11-10 sur huit lectures réelles :
        //    0,0253 à 0,0255 $ la minute au-delà de 10 s d'audio, 0,028 à
        //    0,029 $ sous 3 s (la consigne pèse). 0,028 $ : jamais en dessous
        //    pour un passage de lecture (≥ 60 caractères, ≈ 4 s).
        // `default` = le plus cher, jamais une sous-facturation muette.
        'speech_pricing_usd_per_minute' => [
            'gpt-realtime-2.1-mini' => 0.028,
            'gpt-4o-mini-tts'       => 0.015,
            'default'               => 0.028,
        ],
        'models' => [
            // GPT-6.2 Astra et GPT-6.2 Sol (17/11/2026), fiches, page Pricing,
            // changelog et guide « Using GPT-6.2 » relus en verbatim le
            // 2026-11-17. Chat Completions ET Responses API, mais « tool
            // calling requires the Responses API » : routage DÉCLARÉ (hors
            // préfixe `gpt-5`). « Omit temperature, top_p… » : pas de
            // `temperature`. L'API refuse `reasoning.effort: none` — MIA
            // n'envoie aucun effort à OpenAI (défaut du modèle). Contexte 1,05M
            // (entrée max 922k), sortie max 128k, palier > 272k tokens d'entrée
            // (×2 entrée et cache, ×1,5 sortie). Cache lu à 5 % de l'entrée, à
            // DÉCLARER ; écriture 30 min à 1,25× (celle de 12 h, 2,5×, n'est pas
            // demandée par MIA). Nouveau à partir de 6.2 : un fil dont le début
            // change en route (consignes, liste d'outils) perd ses éléments de
            // raisonnement — dégradation, pas un refus ; la boucle d'outils
            // enchaîne par `previous_response_id`, seule l'activation d'un
            // connecteur à la demande change la liste d'outils. GPT-6.2 Astra
            // (8 $ / 40 $) est moins cher que GPT-6 Astra ; 6.2 Sol garde le
            // tarif de 6.1 Sol. Aucun ne devient le défaut. Écarté (accès
            // réservé) : gpt-6.2-astra-law.
            'gpt-6.2-astra' => [
                'label'       => 'GPT-6.2 Astra',
                'native_pptx' => true,
                'note'        => 'Modèle le plus capable d\'OpenAI (novembre 2026) — projets difficiles de bout en bout : raisonnement, code, documents, agents. Vision, contexte 1,05M, moins cher que GPT-6 Astra (8 $ / 40 $ le million de tokens).',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'      => ['Coût élevé', 'Tarif majoré au-delà de 272k tokens d\'entrée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée et cache ×2, sortie ×1,5 (fiche du modèle, relue le 2026-11-17)
                    'input'        => 8.00,
                    'output'       => 40.00,
                    'cached_input' => 0.40,
                    'cache_write'  => 10.00,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6.2-astra',
                    'note'         => 'Tarif officiel OpenAI GPT-6.2 Astra, contexte court (cache lu 0,40 $, écrit 10 $ pour 30 min). Au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (palier long_context), relu le 17/11/2026.',
                ],
            ],
            'gpt-6.2-sol' => [
                'label'       => 'GPT-6.2 Sol',
                'native_pptx' => true,
                'note'        => 'Sol de la génération GPT-6.2 (novembre 2026) — le modèle de tous les jours d\'OpenAI : code, agents, travail professionnel. Vision, contexte 1,05M, même tarif que GPT-6.1 Sol.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'      => ['Tarif majoré au-delà de 272k tokens d\'entrée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée et cache ×2, sortie ×1,5 (fiche du modèle, relue le 2026-11-17)
                    'input'        => 2.00,
                    'output'       => 10.00,
                    'cached_input' => 0.10,
                    'cache_write'  => 2.50,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6.2-sol',
                    'note'         => 'Tarif officiel OpenAI GPT-6.2 Sol, contexte court (cache lu 0,10 $, écrit 2,50 $ pour 30 min). Au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (palier long_context), relu le 17/11/2026.',
                ],
            ],
            // GPT-6.1 Sol (29/09/2026), GPT-6.1 Terra (26/10/2026) et GPT-6 Luna
            // (22/09/2026), fiches et grille relues le 2026-10-07 puis le
            // 2026-11-10. Comme Astra, hors du préfixe `gpt-5` :
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
            // Écartés à dessein (accès réservé) : gpt-6-cyber, gpt-6-rosalind,
            // gpt-6-astra-law.
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
            // GPT-6.1 Terra : le tier équilibré de la génération GPT-6, moitié
            // prix de 6.1 Sol et moins cher que GPT-5.6 Terra. Lecture de cache
            // à 5 % de l'entrée (« Cached input tokens are priced at 5% of the
            // uncached input token rate ») : à DÉCLARER, le ratio conventionnel
            // (10 %) donnerait 0,10 $. Écriture de cache 30 min à 1,25 $.
            'gpt-6.1-terra' => [
                'label'       => 'GPT-6.1 Terra',
                'native_pptx' => true,
                'note'        => 'Tier équilibré de la génération GPT-6 (octobre 2026) — rapide et efficace : vision, écriture claire, usage précis des écrans. Contexte 1,05M, moitié prix de GPT-6.1 Sol.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (fiche du modèle, relue le 2026-11-10)
                    'input'        => 1.00,
                    'output'       => 5.00,
                    'cached_input' => 0.05,
                    'cache_write'  => 1.25,
                    'source_url'   => 'https://developers.openai.com/api/docs/models/gpt-6.1-terra',
                    'note'         => 'Tarif officiel OpenAI GPT-6.1 Terra, contexte court (cache lu 0,05 $, écrit 1,25 $). Au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (palier long_context), relu le 10/11/2026.',
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
            // L'alias nu 'gpt-5.6' est routé par OpenAI vers Sol. Contexte 1,05M,
            // sortie max 128k, cutoff 16 février 2026, Responses API recommandée.
            'gpt-5.6' => [
                'label'       => 'GPT-5.6 (alias → Sol)',
                'native_pptx' => true,
                'note'        => 'Alias OpenAI routé vers GPT-5.6 Sol — raisonnement, vision et outils natifs via Responses API.',
                'context_window' => 1050000,
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
                // 0,40 $), annoncé « at least through November 21, 2026 » puis
                // prolongé : « available until November 30, 2026 » (fiche et
                // page Pricing, relues le 2026-11-10). Borne à cette date, puis
                // retour au tarif GA — si OpenAI prolonge encore, MIA
                // surfacture, jamais l'inverse.
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (page Pricing, relue le 2026-10-17)
                    'input'        => 5.00,
                    'output'       => 30.00,
                    'cached_input' => 0.50,
                    'launch'       => [
                        'until'        => '2026-11-30',
                        'input'        => 4.00,
                        'output'       => 20.00,
                        'cached_input' => 0.40,
                        'cache_write'  => 5.00,
                    ],
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel (alias = Sol) : promotion 4 $ / 20 $ (cache 0,40 $) jusqu\'au 30/11/2026, puis 5 $ / 30 $ (cache 0,50 $).',
                ],
            ],
            'gpt-5.6-sol' => [
                'label'       => 'GPT-5.6 Sol',
                'native_pptx' => true,
                'note'        => 'Tier frontière de la famille GPT-5.6 — raisonnement profond, agents, tool calling programmatique.',
                'context_window' => 1050000,
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
                        'until'        => '2026-11-30',
                        'input'        => 4.00,
                        'output'       => 20.00,
                        'cached_input' => 0.40,
                        'cache_write'  => 5.00,
                    ],
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Sol : promotion 4 $ / 20 $ (cache 0,40 $) jusqu\'au 30/11/2026, puis 5 $ / 30 $ (cache 0,50 $).',
                ],
            ],
            'gpt-5.6-terra' => [
                'label'       => 'GPT-5.6 Terra',
                'native_pptx' => true,
                'note'        => 'Tier équilibré de la famille GPT-5.6 — proche de GPT-5.5 pour moitié moins cher.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Responses API recommandée', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                // Baisse de 20 % le 30/07/2026 (« Terra costs 20% less »).
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (fiche du modèle, relue le 2026-11-10)
                    'input'        => 2.00,
                    'output'       => 12.00,
                    'cached_input' => 0.20,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Terra depuis le 30/07/2026, contexte court ; au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (relu le 10/11/2026).',
                ],
            ],
            'gpt-5.6-luna' => [
                'label'       => 'GPT-5.6 Luna',
                'native_pptx' => true,
                'note'        => 'Tier rapide et économique de la famille GPT-5.6 — gros volumes, faible latence.',
                'context_window' => 1050000,
                'tags'        => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'      => ['Capacités réduites vs Sol/Terra', 'Pas de temperature (API)'],
                'web_native'  => true,
                'routing'     => 'responses_api',
                'temperature' => false,
                // Baisse de 80 % le 30/07/2026 (« Luna costs 80% less ») : MIA
                // facturait cinq fois le prix.
                'pricing'     => [
                    'long_context' => ['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], // > 272k jetons d'entrée : entrée ×2, sortie ×1,5 (fiche du modèle, relue le 2026-11-10)
                    'input'        => 0.20,
                    'output'       => 1.20,
                    'cached_input' => 0.02,
                    'source_url'   => 'https://developers.openai.com/api/docs/pricing',
                    'note'         => 'Tarif officiel OpenAI GPT-5.6 Luna depuis le 30/07/2026, contexte court ; au-delà de 272k tokens d\'entrée : ×2 en entrée, ×1,5 en sortie (relu le 10/11/2026).',
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
                'context_window' => 1050000,
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
                'context_window' => 1050000,
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
                'context_window' => 1050000,
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
                'label'       => 'GPT-5.4 (26.03.05)',
                'note'        => 'Snapshot piné de GPT-5.4.',
                'context_window' => 1050000,
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
                'context_window' => 400000,
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
                'context_window' => 400000,
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
                'context_window' => 400000,
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
                'context_window' => 1047576,
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
                'context_window' => 1047576,
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
            // désigné : gpt-5.6-sol (page Deprecations relue le 2026-11-12,
            // courriel rectificatif d'OpenAI : GPT-5, GPT-5 mini, GPT-5 nano,
            // GPT-5 pro, o3 et o3-pro). Snapshot, o3-pro et les autres : cf.
            // `retired_models`.
            'o3' => [
                'label'       => 'o3 (raisonnement)',
                'note'        => 'Modèle de raisonnement avancé. Arrêté par OpenAI le 11/12/2026.',
                'context_window' => 200000,
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
            // o4-mini-deep-research-2025-06-26) sont coupés par OpenAI depuis le
            // 23/10/2026 (cf. `retired_models` ci-dessous).
        ],
        // Modèles que MIA ne PROPOSE PAS — retirés du catalogue, ou jamais
        // proposés — mais qu'une instance peut encore porter : une ligne de
        // `llm_models` activée avant leur retrait (la page Fournisseurs et
        // modèles ne liste que le catalogue), ou le « modèle de référence » d'une
        // clé saisi en texte libre avant le 05/03/2026. Sans bloc, un tel modèle
        // restait proposé dans le chat après sa coupure et échouait à chaque
        // message. Seul leur arrêt est déclaré : `LlmCatalog::retirement()` le
        // lit ici quand le modèle n'est pas dans `models` ; ils n'y figurent
        // jamais, donc ne sont jamais proposés à l'activation.
        // Page Deprecations d'OpenAI relue en verbatim le 2026-11-12 (courriel
        // rectificatif du même jour : six modèles coupés le 11/12/2026).
        'retired_models' => [
            // Notice du 11/06/2026 : GPT-5 et o3, coupés le 11/12/2026. `o3` et
            // `gpt-5-mini` sont au catalogue avec leur bloc ; ici leurs
            // versions figées et les quatre autres. Le remplaçant désigné de
            // GPT-5 Pro et o3-pro est GPT-5.6 Sol en `reasoning.mode: pro`, que
            // MIA n'envoie pas : GPT-5.6 Sol tel quel.
            'gpt-5' => [
                'label'      => 'GPT-5',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5-2025-08-07' => [
                'label'      => 'GPT-5 (2025-08-07)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            // Au catalogue de MIA jusqu'au 16/06/2026 (commit 231259a0).
            'gpt-5-mini-2025-08-07' => [
                'label'      => 'GPT-5 Mini (2025-08-07)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-terra', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5-nano' => [
                'label'      => 'GPT-5 Nano',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-luna', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5-nano-2025-08-07' => [
                'label'      => 'GPT-5 Nano (2025-08-07)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-luna', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5-pro' => [
                'label'      => 'GPT-5 Pro',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5-pro-2025-10-06' => [
                'label'      => 'GPT-5 Pro (2025-10-06)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'o3-2025-04-16' => [
                'label'      => 'o3 (2025-04-16)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'o3-pro' => [
                'label'      => 'o3-pro',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'o3-pro-2025-06-10' => [
                'label'      => 'o3-pro (2025-06-10)',
                'retirement' => ['date' => '2026-12-11', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            // Anciens modèles du catalogue de MIA, déjà coupés : une instance
            // qui les a encore activés les proposait toujours, et chaque
            // message échouait (« modèle introuvable »).
            'o4-mini' => [
                'label'      => 'o4-mini',
                'retirement' => ['date' => '2026-10-23', 'replacement' => 'gpt-5.6-terra', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            'gpt-5.3-chat-latest' => [
                'label'      => 'GPT-5.3 Chat (latest)',
                'retirement' => ['date' => '2026-08-10', 'replacement' => 'gpt-5.6-sol', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            // Alias de gpt-4o-search-preview-2025-03-11, coupé le 23/07/2026.
            'gpt-4o-search-preview' => [
                'label'      => 'GPT-4o Search Preview',
                'retirement' => ['date' => '2026-07-23', 'replacement' => 'gpt-5.6-terra', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
            // Remplaçant désigné par OpenAI en 2025 : gpt-4.1, lui-même coupé le
            // 14/01/2027 (son bloc le dit).
            'gpt-4.5-preview' => [
                'label'      => 'GPT-4.5 Preview',
                'retirement' => ['date' => '2025-07-14', 'replacement' => 'gpt-4.1', 'source_url' => 'https://developers.openai.com/api/docs/deprecations'],
            ],
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
        // de l'exécution de code (Haiku 4.5 et suivants, Fable 5.2 et Haiku 5.5
        // compris, relue le 2026-11-10).
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
            // Génération courante dans l'ordre du tableau d'Anthropic (« Models
            // overview », relu le 2026-11-10) : Fable 5.2, Opus 5.5, Sonnet 5.5,
            // Haiku 5.5 ; les générations précédentes suivent.
            //
            // Claude Fable 5.2 (09/11/2026) : successeur de Fable 5.1, MOINS
            // cher (8 $ / 40 $ au lieu de 10 $ / 50 $), mêmes contraintes —
            // raisonnement toujours actif (`thinking: disabled` refusé), effort
            // par défaut `high`, `tool_choice` forcé refusé (MIA n'envoie que
            // `none`), temperature refusée, conservation des données 30 j.
            // Nouveau : le contrôle de préfixe s'applique à TOUS les comptes
            // (un tour rejoué dont le début a changé est refusé en 400) — sans
            // effet sur MIA, dont la boucle ne fait qu'ajouter des messages et
            // repasse le fil SANS raisonnement quand la liste d'outils change
            // (cf. AnthropicAdapter::roundsToMessages). Seuls Fable 5.2 et
            // Mythos 5.2 lisent son raisonnement : un relais vers Opus le
            // perd, l'API l'écarte sans erreur. Pas le défaut de l'instance.
            'claude-fable-5-2' => [
                'label'      => 'Claude Fable 5.2',
                'note'       => 'Modèle le plus capable d\'Anthropic (novembre 2026) — raisonnement exigeant et agentic longue durée, raisonnement toujours actif, contexte 1M. Moins cher que Fable 5.1. Requiert une rétention de données 30 j côté organisation Anthropic.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût très élevé', 'Rétention 30 j requise', 'Sujets sensibles repris par Opus (classifieurs cyber/bio)', 'Réflexion parfois longue avant de répondre'],
                'web_native' => true,
                'temperature' => false,
                // Repli serveur sur refus : Opus 4.8 ou Opus 5 selon la
                // catégorie (cibles permises de Fable 5.2, page « What's new »).
                'refusal_fallback'   => 'default',
                'retention_fallback' => 'claude-opus-5',
                'progress_updates'   => true,
                // cached_input DÉCLARÉ : lecture de cache à 0,025× l'entrée
                // (0,20 $) ; le ratio conventionnel (0,1×) donnerait 0,80 $.
                'pricing'    => [
                    'input'        => 8.00,
                    'output'       => 40.00,
                    'cached_input' => 0.20,
                    'cache_write'  => 10.00,
                    'source_url'   => 'https://platform.claude.com/docs/en/models/fable-5-2/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Fable 5.2 (lecture de cache 0,20 $, 0,025× l\'entrée ; écriture 5 min 10 $), relu le 10/11/2026.',
                ],
            ],
            // Claude Opus 5.5 (22/09/2026) et Sonnet 5.5 (28/09/2026) : même
            // génération que Fable 5.2. Opus 5.5 est l'Opus courant, MOINS cher
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
            // Claude Haiku 5.5 (07/10/2026) : le Haiku de la génération 5,
            // dix fois moins cher que Haiku 4.5. Raisonnement adaptatif par
            // défaut (MIA n'envoie pas `thinking`), effort par défaut `medium`
            // (relevé à `high` sur un tour à connecteurs, comme Opus 5.5),
            // temperature refusée. Classifieurs de sécurité, mais AUCUN repli
            // serveur (ni crédit de repli) : un refus reste un refus, présenté
            // comme tel. Pas de notes d'avancement entre outils (absent de la
            // liste « Progress updates »). SEUL modèle Claude au palier « contexte
            // long » : une REQUÊTE dont l'entrée dépasse 100 000 jetons (cache lu
            // et écrit compris) paie tout ×5 — chaque requête est jugée seule,
            // d'où l'usage `rounds` rapporté par les boucles de l'adapter.
            'claude-haiku-5-5' => [
                'label'      => 'Claude Haiku 5.5',
                'note'       => 'Modèle le plus rapide et le moins cher d\'Anthropic (octobre 2026) — gros volumes, classement, extraction, tâches courtes. Raisonnement adaptatif, contexte 1M (au-delà de 100k jetons d\'entrée, tarif ×5).',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Capacités réduites'],
                'web_native' => true,
                'temperature' => false,
                'effort_default' => 'medium',
                'pricing'    => [
                    'long_context' => ['above' => 100000, 'input_ratio' => 5.0, 'output_ratio' => 5.0], // > 100k jetons d'entrée par requête : tout ×5 (page Pricing, « Long context pricing », relue le 2026-11-10)
                    'input'        => 0.10,
                    'output'       => 0.50,
                    'cached_input' => 0.01,
                    'cache_write'  => 0.125,
                    'source_url'   => 'https://platform.claude.com/docs/en/models/haiku-5-5/overview',
                    'note'         => 'Tarif officiel Anthropic Claude Haiku 5.5 jusqu\'à 100k jetons d\'entrée par requête (cache lu 0,01 $, écrit 0,125 $) ; au-delà : 0,50 $ / 2,50 $ (palier long_context ×5), relu le 10/11/2026.',
                ],
            ],
            // Claude Fable 5.1 : successeur de Fable 5, même tier et même
            // tarif, sauf la lecture de cache (0,25 $ au lieu de 1 $). Remplacée
            // par Fable 5.2 (moins chère), toujours servie. Refuse
            // la temperature, la désactivation du raisonnement et le
            // `tool_choice` forcé (any/tool) — MIA n'envoie que `none`, accepté.
            'claude-fable-5-1' => [
                'label'      => 'Claude Fable 5.1',
                'note'       => 'Génération Fable précédente (septembre 2026) — raisonnement toujours actif, agentic longue durée, contexte 1M. Plus chère que Fable 5.2. Requiert une rétention de données 30 j côté organisation Anthropic.',
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
                'note'       => 'Première génération Fable — raisonnement toujours actif, agentic longue durée, contexte 1M. Requiert une rétention de données 30 j côté organisation Anthropic.',
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
        // Modèles que MIA ne propose pas mais qu'une instance peut porter :
        // « modèle de référence » d'une clé saisi en texte libre avant le
        // 05/03/2026. Dates et remplaçants de la page Model deprecations
        // d'Anthropic, relue le 2026-11-17. Sonnet 4.5 est encore servi
        // jusqu'au 30/11/2026. Opus 4.5 reste actif (« Not sooner than
        // November 24, 2026 ») : aucune date à poser.
        'retired_models' => [
            'claude-sonnet-4-5-20250929' => [
                'label'      => 'Claude Sonnet 4.5',
                'retirement' => ['date' => '2026-11-30', 'replacement' => 'claude-sonnet-5-5', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-opus-4-1-20250805' => [
                'label'      => 'Claude Opus 4.1',
                'retirement' => ['date' => '2026-08-05', 'replacement' => 'claude-opus-4-8', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-sonnet-4-20250514' => [
                'label'      => 'Claude Sonnet 4',
                'retirement' => ['date' => '2026-06-15', 'replacement' => 'claude-sonnet-5-5', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-opus-4-20250514' => [
                'label'      => 'Claude Opus 4',
                'retirement' => ['date' => '2026-06-15', 'replacement' => 'claude-opus-4-8', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-3-haiku-20240307' => [
                'label'      => 'Claude 3 Haiku',
                'retirement' => ['date' => '2026-04-20', 'replacement' => 'claude-haiku-4-5-20251001', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-3-5-haiku-20241022' => [
                'label'      => 'Claude 3.5 Haiku',
                'retirement' => ['date' => '2026-02-19', 'replacement' => 'claude-haiku-4-5-20251001', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
            ],
            'claude-3-7-sonnet-20250219' => [
                'label'      => 'Claude 3.7 Sonnet',
                'retirement' => ['date' => '2026-02-19', 'replacement' => 'claude-sonnet-5-5', 'source_url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'],
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
        // Dictée vocale avec Voxtral (transcription de fichiers, cf.
        // Dictation::MISTRAL_MODELS) : USD par minute d'audio. Fiches modèles
        // de la doc Mistral relues le 2026-10-28 puis le 2026-11-10 : Voxtral
        // Mini Transcribe 3 « Input $0.0035 /Min » (aperçu public, alias
        // `voxtral-mini-transcribe-latest`), Voxtral Mini Transcribe 2
        // (`voxtral-mini-2602`, alias `voxtral-mini-latest`) « $0.003 /Min »,
        // déprécié le 31/10/2026 et retiré le 30/04/2027.
        // `default` = le plus cher, jamais une sous-facturation muette.
        // Lecture à voix haute avec Voxtral TTS 3 (SpeechSynthesizer) : USD
        // par MILLION DE CARACTÈRES lus — Mistral facture le texte, pas la
        // durée (fiche « Voxtral TTS 3 » relue le 2026-11-10 : « Input $13 /M
        // Chars », aperçu public).
        'speech_pricing_usd_per_1m_chars' => [
            'voxtral-mini-tts-3-0' => 13.0,
            'default'              => 13.0,
        ],
        'transcription_pricing_usd_per_minute' => [
            'voxtral-mini-transcribe-3'      => 0.0035,
            'voxtral-mini-transcribe-3-0'    => 0.0035,
            'voxtral-mini-transcribe-latest' => 0.0035,
            'voxtral-mini-latest'            => 0.003,
            'voxtral-mini-2602'              => 0.003,
            'default'                        => 0.0035,
        ],
        'models' => [
            // Les alias `-latest` sont ceux que Mistral publie sur sa grille
            // tarifaire : ils suivent la génération courante SANS préavis (le
            // 29/04/2026 `mistral-medium-latest` est passé de Medium 3.1 à
            // Medium 3.5, et de 0,40/2,00 $ à 1,50/7,50 $). D'où le doublon
            // systématique alias + snapshot piné : l'alias pour suivre, le
            // snapshot pour figer coût et comportement.
            //
            // Mistral Large 4 (aperçu le 06/10/2026, DISPONIBILITÉ GÉNÉRALE le
            // 05/11/2026 — carte du modèle et page « Models » relues le
            // 2026-11-10) : MoE 52B actifs / 1,05T total, encodeur vision 1,6B,
            // contexte 1M, raisonnement TOUJOURS actif (`reasoning_effort` low,
            // medium ou high ; « If you omit `reasoning_effort`, Mistral Large 4
            // uses `high` », « does not accept `none`, `minimal`, `xhigh`, or
            // `max` » — guide « Reasoning », relu le 2026-11-17 ; MIA n'en envoie
            // pas). Identifiants publiés : `mistral-large-4`, `mistral-large-4-0`
            // (version figée, retenue) et, depuis la revue du 2026-11-17,
            // `mistral-large-latest`. Tarif de GA 0,70 $ / 2,10 $ (cache 0,07 $),
            // moitié de celui de l'aperçu (1,36 $ / 4,18 $, que le catalogue
            // appliquait depuis le 20/10) : 1,4 fois Large 3. Remplaçant désigné
            // de Large 3 et de Medium 3.5, arrêtés le 30/04/2027.
            'mistral-large-4-0' => [
                'label'      => 'Mistral Large 4',
                'note'       => 'Modèle phare de Mistral (nov. 2026) — MoE 52B actifs / 1,05T total, multimodal, contexte 1M, raisonnement réglable. Remplaçant désigné de Large 3 et de Medium 3.5.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Un peu plus cher que Large 3'],
                'web_native' => true,
                'pricing'    => [
                    'input'        => 0.70,
                    'output'       => 2.10,
                    'cached_input' => 0.07,
                    'source_url'   => 'https://docs.mistral.ai/models/mistral-large-4-0',
                    'note'         => 'Tarif officiel Mistral Large 4 en disponibilité générale (05/11/2026) : 0,70 $ / 2,10 $, cache 0,07 $. Relu le 10/11/2026.',
                ],
            ],
            // Alias `mistral-large-latest` (DÉFAUT de Mistral) : il sert
            // désormais Mistral Large 4 — la carte de Large 4 le liste (« API
            // names: `mistral-large-4`, `mistral-large-4-0`,
            // `mistral-large-latest` »), celle de Large 3 ne le cite plus
            // (elle ne nomme que `mistral-large-2512`), relues le 2026-11-17 ; le
            // changelog n'en donne pas la date. Jusqu'au 2026-11-17 MIA le
            // comptait au tarif de Large 3 (0,50 $ / 1,50 $), 1,4 fois moins
            // que le vrai. Plus de bloc `retirement` : l'alias ne vise plus un
            // modèle déprécié. Conséquence pour le défaut : Large 4 raisonne à
            // chaque réponse, en `high` faute d'effort envoyé (plus de jetons
            // de sortie, plus de latence) — envoyer `medium` serait une
            // décision produit.
            'mistral-large-latest' => [
                'label'      => 'Mistral Large (latest)',
                'note'       => 'Alias suivant Mistral Large 4 — MoE 52B actifs / 1,05T total, multimodal, contexte 1M, raisonne à chaque réponse.',
                'context_window' => 1000000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Raisonne à chaque réponse (effort élevé par défaut)'],
                'web_native' => true,
                'default'    => true,
                'pricing'    => [
                    'input'        => 0.70,
                    'output'       => 2.10,
                    'cached_input' => 0.07,
                    'source_url'   => 'https://docs.mistral.ai/models/mistral-large-4-0',
                    'note'         => 'Tarif officiel Mistral Large 4 (l\'alias le sert, carte relue le 17/11/2026) : 0,70 $ / 2,10 $, cache 0,07 $.',
                ],
            ],
            // Large 3 (`mistral-large-2512`) et Medium 3.5 (`mistral-medium-3-5`,
            // alias `mistral-medium-latest`) sont DÉPRÉCIÉS depuis le 31/10/2026
            // et retirés le 30/04/2027 (« Deprecated and retired models », page
            // « Models » relue le 2026-11-17) ; un identifiant retiré répond 404
            // (« Model lifecycle »). Remplaçant désigné : Large 4.
            'mistral-large-2512' => [
                'label'      => 'Mistral Large 3 (25.12)',
                'note'       => 'Snapshot piné de Mistral Large 3. Arrêté par Mistral le 30/04/2027, remplacé par Mistral Large 4.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web'],
                'limits'     => [],
                'web_native' => true,
                'retirement' => [
                    'date'        => '2027-04-30',
                    'replacement' => 'mistral-large-4-0',
                    'source_url'  => 'https://docs.mistral.ai/models',
                ],
                'pricing'    => [
                    'input'      => 0.50,
                    'output'     => 1.50,
                    'source_url' => 'https://docs.mistral.ai/models/mistral-large-3-25-12',
                    'note'       => 'Tarif officiel Mistral Large 3.',
                ],
            ],
            'mistral-medium-latest' => [
                'label'      => 'Mistral Medium (latest)',
                'note'       => 'Alias suivant Mistral Medium 3.5 — modèle frontière multimodal, orienté agents et code. Arrêté par Mistral le 30/04/2027, remplacé par Mistral Large 4.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Plus cher que Large 4'],
                'web_native' => true,
                'retirement' => [
                    'date'        => '2027-04-30',
                    'replacement' => 'mistral-large-4-0',
                    'source_url'  => 'https://docs.mistral.ai/models',
                ],
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://docs.mistral.ai/models/mistral-medium-3-5-26-04',
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
                'note'       => 'Version figée de Mistral Medium 3.5 (identifiant officiel) — effort de raisonnement configurable, encodeur vision natif. Arrêté par Mistral le 30/04/2027, remplacé par Mistral Large 4.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Plus cher que Large 4'],
                'web_native' => true,
                'retirement' => [
                    'date'        => '2027-04-30',
                    'replacement' => 'mistral-large-4-0',
                    'source_url'  => 'https://docs.mistral.ai/models',
                ],
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://docs.mistral.ai/models/mistral-medium-3-5-26-04',
                    'note'       => 'Tarif officiel Mistral Medium 3.5.',
                ],
            ],
            'mistral-medium-2604' => [
                'label'      => 'Mistral Medium 3.5 (26.04)',
                'note'       => 'Ancien identifiant daté de Mistral Medium 3.5, absent de la documentation de Mistral — préférer « Mistral Medium 3.5 » (mistral-medium-3-5), même modèle et même tarif.',
                'context_window' => 256000,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Plus cher que Large 4'],
                'web_native' => true,
                'retirement' => [
                    'date'        => '2027-04-30',
                    'replacement' => 'mistral-large-4-0',
                    'source_url'  => 'https://docs.mistral.ai/models',
                ],
                'pricing'    => [
                    'input'      => 1.50,
                    'output'     => 7.50,
                    'source_url' => 'https://docs.mistral.ai/models/mistral-medium-3-5-26-04',
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
            // Retirés par Mistral, donc retirés d'ici (ils répondraient 404) :
            // ils vivent dans `retired_models` ci-dessous.
        ],
        // Modèles retirés par Mistral qu'une instance peut encore porter :
        // Small 3.2 et Medium 3.1 étaient proposés jusqu'au 24/04/2026
        // (e07a740e). Tableau « Deprecated & retired models » de
        // docs.mistral.ai/models relu le 2026-11-12 ; remplaçants = colonne
        // « Alternative » (Medium 3.5, lui-même arrêté le 30/04/2027 : son bloc
        // le dit).
        'retired_models' => [
            'mistral-small-2506' => [
                'label'      => 'Mistral Small 3.2',
                'retirement' => ['date' => '2026-07-31', 'replacement' => 'mistral-small-2603', 'source_url' => 'https://docs.mistral.ai/models'],
            ],
            'mistral-medium-2508' => [
                'label'      => 'Mistral Medium 3.1',
                'retirement' => ['date' => '2026-08-31', 'replacement' => 'mistral-medium-3-5', 'source_url' => 'https://docs.mistral.ai/models'],
            ],
            'mistral-medium-2505' => [
                'label'      => 'Mistral Medium 3',
                'retirement' => ['date' => '2026-08-31', 'replacement' => 'mistral-medium-3-5', 'source_url' => 'https://docs.mistral.ai/models'],
            ],
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
        // REFORMULÉES en requêtes Agent API (effort `fast` pour Sonar et Sonar
        // Pro, `low` pour Sonar Reasoning Pro), déploiement progressif par
        // modèle, « sans garantie d'exacte compatibilité de chaque paramètre ou
        // champ de réponse ». Aucune date d'arrêt annoncée. Étape suivante :
        // porter PerplexityAdapter sur l'Agent API (`/v1/agent`, format
        // Responses, `effort` — anciennement `preset`, alias encore accepté,
        // changelog d'octobre 2026) — qui ouvrirait aussi les outils (custom
        // functions). Non fait ici : aucun moyen de l'éprouver sans clé. Les
        // fiches Sonar vivent désormais sous « Legacy » (prix inchangés,
        // relus le 2026-11-17).
        'endpoints' => [
            'chat_completions' => 'https://api.perplexity.ai/chat/completions',
        ],
        // Frais PAR REQUÊTE de Sonar, en plus des jetons (5 à 14 $ les 1 000
        // selon le modèle et la taille du contexte de recherche) : chaque
        // réponse en paie un, l'adaptateur en compte donc un par réponse
        // écrite. Tarif du contexte `low`, celui par défaut — MIA n'en
        // demande pas d'autre. Déclaré par modèle.
        'web_search_billing' => 'prompt',
        // Retiré par Perplexity, donc retiré d'ici : `sonar-reasoning`, dans
        // `retired_models` ci-dessous.
        'models' => [
            'sonar-pro' => [
                'label'      => 'Sonar Pro',
                'note'       => 'Modèle puissant avec recherche web native en temps réel.',
                // « 200K context length » (fiche Legacy, relue le 2026-11-17).
                'context_window' => 200000,
                'tags'       => ['web'],
                'limits'     => ['Fin de support annoncée par Perplexity (27/09/2026), toujours servi'],
                'web_native' => true,
                'default'    => true,
                'web_search_pricing_usd_per_1k' => 6.00,
                'pricing'    => [
                    'input'      => 3.00,
                    'output'     => 15.00,
                    'source_url' => 'https://docs.perplexity.ai/docs/legacy/sonar/models/sonar-pro',
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
                    'source_url' => 'https://docs.perplexity.ai/docs/legacy/sonar/models/sonar',
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
                    'source_url' => 'https://docs.perplexity.ai/docs/legacy/sonar/models/sonar-reasoning-pro',
                    'note'       => 'Tarif officiel Perplexity Sonar Reasoning Pro (jetons) ; + 6 $ les 1 000 requêtes (contexte de recherche « low »), compté comme recherche.',
                ],
            ],
        ],
        // Proposé jusqu'au 24/04/2026 (e07a740e) : « deprecated and removed
        // from the API » le 15/12/2025 (changelog Perplexity, relu le
        // 2026-11-12), remplaçant conseillé : Sonar Reasoning Pro.
        'retired_models' => [
            'sonar-reasoning' => [
                'label'      => 'Sonar Reasoning',
                'retirement' => ['date' => '2025-12-15', 'replacement' => 'sonar-reasoning-pro', 'source_url' => 'https://docs.perplexity.ai/docs/resources/changelog'],
            ],
        ],
    ],

    // ============== Gemini ==============
    // Sources vérifiées le 16/09/2026 : ai.google.dev/gemini-api/docs/models,
    // /pricing, /deprecations et /changelog.
    'gemini' => [
        // Défaut inchangé : Gemini 3.8 et 3.6 Flash coûteront 1,50 $ / 7,50 $
        // le million au 1er janvier 2027, plus du double de Gemini 3 Flash
        // (« legacy » chez Google, sans date d'arrêt — remplaçant désigné :
        // 3.6 Flash) — un défaut qui bascule en silence changerait la facture
        // de l'instance. Gemini 2.5 : accès réservé par Google aux comptes qui
        // l'utilisaient déjà (18/09/2026), cf. ses modèles.
        //
        // Pages Models, Pricing, Deprecations et Changelog relues le
        // 2026-11-10 : Gemini 4 Argon (GA 13/10) ajouté ; 3.7 Flash et 3.5
        // Flash dépréciés le 08/10 — l'adresse répond encore mais Google la
        // REDIRIGE (vers 3.8 et 3.6 Flash), ce n'est plus le modèle annoncé
        // qui répond : arrêtés ici à cette date ; 2.5 Pro et 2.5 Flash
        // redirigés le 01/12/2026 (vers 3.8 Flash et 3.5 Flash-Lite, au tarif
        // de la cible, avec des changements cassants : signatures de
        // réflexion, `thinking_level`). Relues de nouveau le 2026-11-19 :
        // Gemini 4 Neon (GA 18/11) ajouté ; aucun autre tarif ni date d'arrêt
        // n'a bougé.
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
            // Gemini 4 Hydrogen (GA 12/11/2026) et Gemini 4 Carbon (GA
            // 10/11/2026) — changelog, fiches, page Pricing et « What's new »
            // relus en verbatim le 2026-11-17. Contexte 1 048 576 (entrée et
            // sortie confondues), sortie 65 536 en flux (jusqu'au million avec
            // le « long decode », que MIA ne demande pas), entrées texte, image,
            // vidéo, audio et PDF, function-calling et recherche Google. Cache
            // implicite seulement, lecture à 5 % de l'entrée (à DÉCLARER).
            // « Remove `temperature`, `top_p`, and `top_k` » : ignorés, et chez
            // Hydrogen une valeur hors limites rend un 400 → temperature=false.
            // Réflexion : Carbon low/medium/high (défaut medium), Hydrogen
            // low/medium/high/xhigh (défaut HIGH, `minimal` refusé) ; MIA
            // n'envoie pas `thinking_level`. Hydrogen est le modèle « par
            // défaut » de Google et ouvre le free tier ; Carbon, le plus
            // capable, ne l'ouvre pas. Aucun ne devient le défaut ici
            // (Hydrogen coûte 3 fois Gemini 3 Flash en entrée, 2,5 en sortie).
            // Non vérifié faute de clé : comme Argon, les instructions internes
            // des outils comptées en entrée avec la recherche.
            // Gemini 4 Neon (GA 18/11/2026) — changelog, fiche, page Pricing et
            // « What's new in Gemini 4 Neon » relus en verbatim le 2026-11-19.
            // Placé par Google ENTRE Hydrogen et Carbon (« frontier-level
            // intelligence with fast end-to-end task completion ») : code
            // complexe, travail documentaire, sciences. Même conception d'API que
            // les autres Gemini 4 (« no request schema changes ») : contexte
            // unifié 1 048 576, sortie 65 536 par requête (long decode non
            // demandé), entrées texte, image, vidéo, audio, PDF, function-calling
            // et recherche Google (14 $ les 1 000 requêtes). Cache IMPLICITE
            // seulement, lu à 5 % de l'entrée (0,15 $ — à déclarer), aucun palier
            // au-delà de 200k. Température : comme Hydrogen, ignorée et 400 hors
            // limites. Réflexion low/medium/high/xhigh, défaut HIGH, `minimal`
            // refusé (MIA n'envoie pas `thinking_level`). Niveau payant
            // seulement (pas de free tier). Jamais défaut : 6 fois Gemini 3
            // Flash en entrée, 5 fois en sortie. Non éprouvé faute de clé.
            'gemini-4-neon' => [
                'label'      => 'Gemini 4 Neon',
                'note'       => 'Modèle intermédiaire de Google (novembre 2026), entre Hydrogen et Carbon — code complexe, analyse de documents, sciences, agents en plusieurs étapes ; vision, audio, vidéo, contexte 1M. Réfléchit en effort élevé par défaut.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Coût élevé', 'Réflexion élevée par défaut (plus de tokens)', 'Pas de temperature (ignorée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 3.00,
                    'output'       => 15.00,
                    'cached_input' => 0.15,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing#gemini-4-neon',
                    'note'         => 'Tarif officiel Google Gemini 4 Neon : 3 $ / 15 $ (cache 0,15 $, 5 % de l\'entrée), sans palier sur toute la fenêtre de 1M. Sortie = réponse + réflexion. Relu le 19/11/2026.',
                ],
            ],
            'gemini-4-hydrogen' => [
                'label'      => 'Gemini 4 Hydrogen',
                'note'       => 'Modèle généraliste de Google (novembre 2026) — rapide et efficace pour le code au quotidien et les agents ; vision, audio, vidéo, contexte 1M. Réfléchit en effort élevé par défaut.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Réflexion élevée par défaut (plus de tokens)', 'Pas de temperature (ignorée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 1.50,
                    'output'       => 7.50,
                    'cached_input' => 0.075,
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing#gemini-4-hydrogen',
                    'note'         => 'Tarif officiel Google Gemini 4 Hydrogen : 1,50 $ / 7,50 $ (cache 0,075 $, 5 % de l\'entrée), sans palier au-delà de 200k. Sortie = réponse + réflexion. Relu le 17/11/2026.',
                ],
            ],
            'gemini-4-carbon' => [
                'label'      => 'Gemini 4 Carbon',
                'note'       => 'Modèle de raisonnement le plus capable de Google (novembre 2026) — code au long cours, connaissances multimodales, sciences ; vision, contexte 1M. Tarif de lancement 2 $/10 $ jusqu\'au 30/11/2026.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Coût élevé', 'Réflexion parfois longue avant de répondre', 'Pas de temperature (ignorée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 4.00,
                    'output'       => 20.00,
                    'cached_input' => 0.20,
                    'launch'       => [
                        'until'        => '2026-11-30',
                        'input'        => 2.00,
                        'output'       => 10.00,
                        'cached_input' => 0.10,
                    ],
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing#gemini-4-carbon',
                    'note'         => 'Tarif officiel Google Gemini 4 Carbon : lancement 2 $ / 10 $ (cache 0,10 $) jusqu\'au 30/11/2026, puis 4 $ / 20 $ (cache 0,20 $, 5 % de l\'entrée). Sortie = réponse + réflexion. Relu le 17/11/2026.',
                ],
            ],
            // Gemini 4 Argon (GA 13 octobre 2026) : « Our efficient Gemini 4
            // frontier reasoning model » — plus rapide et moins gourmand en
            // tokens que Carbon (page Pricing, relue le 2026-11-17 ; il était
            // présenté comme le phare de Google avant Carbon) — code complexe,
            // maths et sciences, agents en plusieurs étapes. Réflexion `medium` par défaut, impossible à
            // couper ; temperature / top_p / top_k ignorés. Sortie 65 536 en
            // flux (jusqu'au million avec le « long decode », que MIA ne
            // demande pas). Lecture de cache à 5 % de l'entrée (à DÉCLARER :
            // le ratio conventionnel donnerait le double) ; cache implicite
            // seulement. Aucun palier au-delà de 200k. Tarif de lancement
            // jusqu'au 30/11/2026. Peut réfléchir plusieurs minutes : la
            // lecture du flux bat le cœur du job (LlmAdapterHelpers::readSseLine).
            // Non vérifié faute de clé : Google facture aussi « les instructions
            // internes des outils et les déclarations de fonctions » quand la
            // recherche est active — dans quel compteur d'usage ils arrivent.
            // Pas le défaut : quatre fois Gemini 3.8 Flash au tarif normal.
            'gemini-4-argon' => [
                'label'      => 'Gemini 4 Argon',
                'note'       => 'Modèle de raisonnement efficace de Google (octobre 2026) — plus rapide et plus économe en tokens que Gemini 4 Carbon : code complexe, maths et sciences, agents ; vision, contexte 1M. Tarif de lancement 2 $/10 $ jusqu\'au 30/11/2026.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Coût élevé', 'Réflexion parfois longue avant de répondre', 'Pas de temperature (ignorée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'pricing'    => [
                    'input'        => 4.00,
                    'output'       => 20.00,
                    'cached_input' => 0.20,
                    'launch'       => [
                        'until'        => '2026-11-30',
                        'input'        => 2.00,
                        'output'       => 10.00,
                        'cached_input' => 0.10,
                    ],
                    'source_url'   => 'https://ai.google.dev/gemini-api/docs/pricing#gemini-4-argon',
                    'note'         => 'Tarif officiel Google Gemini 4 Argon : lancement 2 $ / 10 $ (cache 0,10 $) jusqu\'au 30/11/2026, puis 4 $ / 20 $ (cache 0,20 $, 5 % de l\'entrée). Sortie = réponse + réflexion. Relu le 10/11/2026.',
                ],
            ],
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
            // Gemini 3.7 Flash (GA 13 août 2026) : déprécié le 08/10/2026, ses
            // requêtes sont REDIRIGÉES par Google vers 3.8 Flash (« the endpoint
            // is not shutting down and automatically routes API requests to
            // gemini-3.8-flash ») — arrêté ici à cette date, gardé pour les
            // instances qui l'ont activé.
            'gemini-3.7-flash' => [
                'label'      => 'Gemini 3.7 Flash',
                'note'       => 'Flash d\'août 2026 — remplacé par Gemini 3.8 Flash : depuis le 08/10/2026, Google fait répondre 3.8 Flash à sa place.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Redirigé par Google vers Gemini 3.8 Flash', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                'retirement' => [
                    'date'        => '2026-10-08',
                    'replacement' => 'gemini-3.8-flash',
                    'source_url'  => 'https://ai.google.dev/gemini-api/docs/deprecations',
                ],
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
                'note'       => 'Flash de mai 2026 — remplacé par Gemini 3.6 Flash : depuis le 08/10/2026, Google fait répondre 3.6 Flash à sa place.',
                'context_window' => 1048576,
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement', 'code'],
                'limits'     => ['Redirigé par Google vers Gemini 3.6 Flash', 'Pas de temperature (dépréciée par Google)'],
                'web_native' => true,
                'temperature' => false,
                // Déprécié le 08/10/2026 : Google redirige ses requêtes vers 3.6
                // Flash (même principe que 3.7 Flash).
                'retirement' => [
                    'date'        => '2026-10-08',
                    'replacement' => 'gemini-3.6-flash',
                    'source_url'  => 'https://ai.google.dev/gemini-api/docs/deprecations',
                ],
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
            // Coupé par Google, donc retiré d'ici (il répondrait 404) :
            // `gemini-3.1-flash-lite-preview`, dans `retired_models` ci-dessous.
            //
            // Génération 2.5 : depuis le 18/09/2026, Google en RÉSERVE l'accès
            // aux comptes qui l'utilisaient déjà (« not deprecated », servie
            // « until further notice ») et renvoie vers Gemini 4 Hydrogen ou
            // 3.5 Flash-Lite pour tout nouveau projet (page Models, relue le
            // 2026-11-17). Gardée au catalogue (une instance
            // qui l'a activée continue de la servir) ; la limite le dit.
            'gemini-2.5-pro' => [
                'label'      => 'Gemini 2.5 Pro',
                // Recherche Google et outils ne se cumulent pas en 2.5 (cf. bloc).
                'tool_use'   => false,
                'note'       => 'Modèle stable haut de gamme pour tâches complexes.',
                'tags'       => ['vision', 'fichiers', 'web', 'raisonnement'],
                'limits'     => ['Coût élevé', 'Accès réservé par Google aux comptes qui l\'utilisaient déjà (18/09/2026)'],
                'web_native' => true,
                // Redirigé par Google vers gemini-3.8-flash le 01/12/2026, facturé au tarif de
                // la cible, avec des changements cassants (signatures de
                // réflexion, `thinking_level`) : arrêté ici à cette date.
                'retirement' => [
                    'date'        => '2026-12-01',
                    'replacement' => 'gemini-3.8-flash',
                    'source_url'  => 'https://ai.google.dev/gemini-api/docs/deprecations#gemini-2.5-pro-models',
                ],
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
                // Redirigé par Google vers gemini-3.5-flash-lite le 01/12/2026, facturé au tarif de
                // la cible, avec des changements cassants (signatures de
                // réflexion, `thinking_level`) : arrêté ici à cette date.
                'retirement' => [
                    'date'        => '2026-12-01',
                    'replacement' => 'gemini-3.5-flash-lite',
                    'source_url'  => 'https://ai.google.dev/gemini-api/docs/deprecations#gemini-2.5-flash-models',
                ],
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
        // Modèles coupés par Google qu'une instance peut encore porter :
        // 3.1 Flash-Lite preview proposé jusqu'au 24/04/2026 (e07a740e), 2.0
        // Flash et les deux préversions 2.5 en mars 2026 (bb74a3df, 08bda0f6).
        // Dates et remplaçants : page Deprecations relue le 2026-11-12 ; la
        // préversion 2.5 Flash 04-17 n'y figure plus, sa coupure est au
        // changelog (« will be deprecated on July 15, 2025 ») et son
        // remplaçant celui des autres préversions 2.5 Flash.
        'retired_models' => [
            'gemini-3.1-flash-lite-preview' => [
                'label'      => 'Gemini 3.1 Flash-Lite (preview)',
                'retirement' => ['date' => '2026-05-25', 'replacement' => 'gemini-3.1-flash-lite', 'source_url' => 'https://ai.google.dev/gemini-api/docs/deprecations'],
            ],
            'gemini-2.0-flash' => [
                'label'      => 'Gemini 2.0 Flash',
                'retirement' => ['date' => '2026-06-01', 'replacement' => 'gemini-3.6-flash', 'source_url' => 'https://ai.google.dev/gemini-api/docs/deprecations#gemini-2.0-models'],
            ],
            'gemini-2.5-pro-preview-03-25' => [
                'label'      => 'Gemini 2.5 Pro (preview 03-25)',
                'retirement' => ['date' => '2025-12-02', 'replacement' => 'gemini-3.8-flash', 'source_url' => 'https://ai.google.dev/gemini-api/docs/deprecations#gemini-2.5-pro-models'],
            ],
            'gemini-2.5-flash-preview-04-17' => [
                'label'      => 'Gemini 2.5 Flash (preview 04-17)',
                'retirement' => ['date' => '2025-07-15', 'replacement' => 'gemini-3.6-flash', 'source_url' => 'https://ai.google.dev/gemini-api/docs/changelog'],
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
        // Relu le 2026-11-10 : V4.1 Flash mis à jour le 17/10/2026
        // (« DeepSeek-V4.1-Flash-1017 »), même identifiant, même appel, même
        // tarif ; ni V4.1 Pro ni V5 annoncés (référence de l'API : `model` ∈
        // `deepseek-flash`, `deepseek-v4-pro`).
        'default_model' => 'deepseek-flash',
        // Function-calling au format Chat Completions, en mode SANS réflexion
        // (cf. `thinking` du modèle).
        'tool_use' => true,
        // Recherche Internet INTÉGRÉE depuis le 13/11/2026 : outil serveur
        // `deepseek_web_search` (DeepSeekAdapter), sans réflexion — le mode de
        // MIA. 0,75 $ les 1 000 recherches, autant les 1 000 lectures de page
        // et recherches d'image, comptées dans `usage.server_tool_use` (page
        // Pricing et guide « Web Tools », relus le 2026-11-17). « Other tools
        // cannot be used together with `deepseek_web_search` … yet » : jamais
        // avec les outils de MIA dans une même réponse (comme Mistral) — le
        // contrôleur arbitre (un connecteur ou un outil explicite gagne, la
        // recherche s'éteint et le dit). `safety_check` non envoyé : DeepSeek
        // ne l'exige que d'un service ouvert au public.
        'web_search_pricing_usd_per_1k' => 0.75,
        'tools_with_web_search'         => false,
        'default_context_window' => 1000000,
        'endpoints' => [
            'chat_completions' => 'https://api.deepseek.com/chat/completions',
        ],
        'models' => [
            'deepseek-flash' => [
                'label'       => 'DeepSeek V4.1 Flash',
                'note'        => 'Modèle courant de DeepSeek (septembre 2026, révisé le 17/10) — MoE 552B, contexte 1M, vision native, très économique (0,30 $ / 1,20 $ en heures de pointe, moitié prix en heures creuses).',
                'context_window' => 1000000,
                'tags'        => ['vision', 'web', 'code'],
                'limits'      => ['Recherche Internet et outils jamais dans la même réponse', 'Mode réflexion coupé par MIA (réponse rapide)', 'Tarif de pointe compté même en heures creuses'],
                'web_native'  => true,
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
        // Les deux modèles d'avant V4.1 Flash (cf. en tête du fournisseur).
        // Un message sur l'un d'eux échouait : ils quittent le chat.
        'retired_models' => [
            'deepseek-chat' => [
                'label'      => 'DeepSeek V3',
                'retirement' => ['date' => '2026-07-24', 'replacement' => 'deepseek-flash', 'source_url' => 'https://api-docs.deepseek.com/news/news260424'],
            ],
            'deepseek-reasoner' => [
                'label'      => 'DeepSeek R1',
                'retirement' => ['date' => '2026-07-24', 'replacement' => 'deepseek-flash', 'source_url' => 'https://api-docs.deepseek.com/news/news260424'],
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
            // Fermé aux comptes Moonshot créés depuis le 01/11/2026, arrêt
            // « attendu fin décembre 2026 » (Model List, relue le 2026-11-10) :
            // pas encore de date ferme, donc pas de bloc `retirement` — à poser
            // dès que Moonshot la publie (remplaçant : Kimi K3).
            'kimi-k2.6' => [
                'label'       => 'Kimi K2.6',
                'note'        => 'Généraliste économique Moonshot (avril 2026) — texte, image et vidéo, raisonnement, contexte 256K.',
                'context_window' => 262144,
                'tags'        => ['vision', 'raisonnement'],
                'limits'      => ['Pas de recherche web native', 'Pas de temperature (API)', 'Fermé aux comptes Moonshot créés depuis le 01/11/2026, arrêt prévu fin décembre 2026'],
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
        // GLM-5.3-FlashX (variante rapide de 5.3-Flash, 0,37 $ / 1,25 $) et
        // GLM-5.3-FlashX-Legal (variante juridique, même tarif).
        // Relu le 2026-11-10 : rien de neuf (tarifs, `tool_choice`, bornes de
        // temperature inchangés). `reasoning_effort` vaut `max` par défaut
        // (5.2 et suivants) : MIA ne l'envoie pas, chaque réponse raisonne au
        // plus fort — un réglage plus bas (`high`) ferait gagner temps et
        // jetons, à décider à dessein (non éprouvé faute de clé).
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
