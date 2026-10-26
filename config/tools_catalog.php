<?php

declare(strict_types=1);

/**
 * Catalogue des outils de génération (image / vidéo) — source unique de vérité.
 * --------------------------------------------------------------------------
 * Pour ajouter ou modifier un modèle image ou vidéo, éditer UNIQUEMENT ce
 * fichier. Les helpers (ImageGenerator, VideoGenerator) et l'UI
 * (templates/admin/tools.php) lisent depuis ici via `ToolCatalog`.
 *
 * Les outils HTML réutilisent le catalogue LLM (`config/llm_catalog.php`).
 *
 * STRUCTURE :
 *   [type][provider]['default_model']     → id du modèle par défaut
 *   [type][provider]['endpoints']         → URLs des endpoints par nom logique
 *   [type][provider]['models'][id]['label']     → libellé UI
 *   [type][provider]['models'][id]['routing']   → quel endpoint utiliser
 *   [type][provider]['models'][id]['default']   → true = défaut du provider
 *   [type][provider]['sizes'|'qualities'|'aspect_ratios']  → options par provider
 *   [type][provider]['models'][id]['sizes'|'qualities']    → options PAR MODÈLE,
 *                                   qui priment sur celles du provider (ToolCatalog::modelOptions)
 *   [type][provider]['models'][id]['pricing']   → TARIF PUBLIC (facultatif)
 *
 * TARIFS — repli de CreditService quand la ligne de l'outil n'est pas tarifée
 * dans Gestion crédits (écran Crédits : une ligne à 0 par outil). Un seul mode
 * par modèle, TOUJOURS avec `source_url` officielle et date de vérification
 * dans `note` (un tarif sans source se périme en silence) :
 *   'per_image_usd'     => 0.067                       une image
 *   'per_second_usd'    => ['720x1280' => 0.10, …]     une seconde, PAR TAILLE
 *   'tokens_usd_per_1m' => ['text_input' => …, 'image_input' => …, 'image_output' => …]
 *                          coût réel depuis la consommation (`usage`) renvoyée
 *                          par l'API : il dépend de la taille ET de la qualité
 *                          produites, qu'aucun prix unitaire ne résume.
 * Pas de tarif public vérifiable = pas de bloc : l'opération reste « non
 * tarifée », signalée sur la page Usages — jamais un prix deviné.
 *
 * ARRÊTS — `retirement` (facultatif), sur un MODÈLE ou sur tout un FOURNISSEUR
 * quand c'est l'API entière qui disparaît. Lu par ToolCatalog::retirement(),
 * seule lecture autorisée :
 *   'date'       => 'AAAA-MM-JJ'   jour de la coupure (bascule à 0 h, heure du serveur)
 *   'source_url' => 'https://…'    annonce officielle du fournisseur
 *   'note'       => '…'            ce que lit l'administrateur sur la carte de l'outil
 *   'verified'   => 'AAAA-MM-JJ'   date de relecture de la source
 * Avant la date : avertissement sur la carte et au Diagnostic. À partir de la
 * date : l'outil est grisé dans le chat AVEC la raison, aucun appel ne part
 * chez le fournisseur, et le modèle disparaît des listes de création. Aucune
 * écriture en base : choisir un autre modèle suffit à remettre l'outil en
 * service. Ne pas retirer un modèle arrêté de ce fichier tant qu'un outil peut
 * encore le porter : sans son entrée, sa fiche ne saurait plus l'afficher.
 */

return [

    // ================================================================
    // STYLES GRAPHIQUES (sites illustrés) — source unique admin + chat
    // ================================================================
    // Proposés par défaut sur l'outil « Site illustré » (admin, templates/admin/tools.php)
    // ET dans le popup de lancement côté chat (window.IMAGE_STYLES, injecté par
    // templates/chat/index.php). 'directive' = fragment ajouté au prompt image.
    // Modifier ici suffit : plus aucune liste à synchroniser à la main.
    'image_styles' => [
        ['key' => 'photo', 'label' => 'Photoréaliste', 'directive' => 'style photographique réaliste, haute qualité, éclairage naturel, détails nets'],
        ['key' => 'illus', 'label' => 'Illustration',  'directive' => 'style illustration vectorielle plate (flat design), formes nettes, couleurs harmonieuses'],
        ['key' => 'min',   'label' => 'Minimaliste',   'directive' => "style minimaliste épuré, beaucoup d'espace négatif, palette sobre, formes simples"],
        ['key' => '3d',    'label' => '3D / rendu',     'directive' => 'rendu 3D moderne, matériaux réalistes, éclairage studio doux, aspect produit'],
    ],

    // ================================================================
    // IMAGE
    // ================================================================
    'image' => [

        // -------- OpenAI --------
        'openai' => [
            'label'         => 'OpenAI (ChatGPT / GPT Image / DALL-E)',
            'default_model' => 'gpt-image-2.5-flare',
            'endpoints' => [
                'images_generations' => 'https://api.openai.com/v1/images/generations',
                // Édition d'une image existante (image-to-image) — multipart,
                // famille gpt-image-* uniquement (DALL-E non supporté).
                'images_edits'       => 'https://api.openai.com/v1/images/edits',
                'responses'          => 'https://api.openai.com/v1/responses',
            ],
            // Options exposées par l'endpoint /images/generations. Ce sont les
            // valeurs DALL-E : la famille gpt-image-* déclare les SIENNES sur
            // chaque modèle (`sizes`/`qualities`), qui priment. Envoyer
            // `standard`/`hd` à un gpt-image-* est refusé par l'API.
            'sizes'     => ['1024x1024', '1792x1024', '1024x1792'],
            'qualities' => ['standard', 'hd'],
            // Options exposées par l'outil `image_generation` de la Responses API
            'responses_sizes'     => ['auto', '1024x1024', '1024x1536', '1536x1024'],
            'responses_qualities' => ['auto', 'low', 'medium', 'high'],
            'models' => [
                // GPT Image 2.5 — deux modèles annoncés le 8 septembre 2026,
                // mêmes tarifs au token que GPT Image 2 (« Token rates match
                // GPT Image 2 », fiche modèle) : ils n'engagent donc aucune
                // dépense supplémentaire par token, seulement un nombre de
                // tokens qui peut différer à qualité égale. Les deux ajoutent
                // les qualités `xhigh` et `max`, absentes de GPT Image 2.
                //
                // CES LISTES SONT LE SEUL GARDE-FOU, et ce n'est pas théorique :
                // mesuré le 2026-09-16 contre l'API, /v1/images/generations
                // valide `quality` sur un schéma COMMUN à tous les modèles —
                // `xhigh` passe donc la validation même avec gpt-image-2, qui ne
                // le rend pas (doc : « For gpt-image-2, the options are low,
                // medium, and high »), et son message de refus liste encore
                // « low, medium, high, auto » sans les deux nouvelles. Rien ne
                // signalerait donc une qualité envoyée au mauvais modèle.
                'gpt-image-2.5-flare' => [
                    'label'   => 'GPT Image 2.5 Flare (septembre 2026)',
                    'note'    => 'Le plus rapide : images de haute qualité au quotidien, moitié moins de latence que GPT Image 2.',
                    'routing' => 'images_generations',
                    'default' => true,
                    // Tailles recommandées par OpenAI ; les deux modèles
                    // acceptent aussi une taille libre LARGEURxHAUTEUR
                    // (multiples de 16, ratio entre 1:3 et 3:1, 3840 px max).
                    'sizes'     => ['auto', '1024x1024', '1536x1024', '1024x1536'],
                    'qualities' => ['auto', 'low', 'medium', 'high', 'xhigh', 'max'],
                    'pricing' => [
                        'tokens_usd_per_1m' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
                        'source_url'        => 'https://developers.openai.com/api/docs/pricing',
                        'note'              => 'Tarif officiel OpenAI GPT Image 2.5 Flare (traitement standard), vérifié le 2026-09-16.',
                    ],
                ],
                'gpt-image-2.5-sunburst' => [
                    'label'   => 'GPT Image 2.5 Sunburst (septembre 2026)',
                    'note'    => 'Le plus capable : génération et retouche, pour les travaux visuels où la précision des modifications prime.',
                    'routing' => 'images_generations',
                    'sizes'     => ['auto', '1024x1024', '1536x1024', '1024x1536'],
                    'qualities' => ['auto', 'low', 'medium', 'high', 'xhigh', 'max'],
                    'pricing' => [
                        'tokens_usd_per_1m' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
                        'source_url'        => 'https://developers.openai.com/api/docs/pricing',
                        'note'              => 'Tarif officiel OpenAI GPT Image 2.5 Sunburst (traitement standard), vérifié le 2026-09-16.',
                    ],
                ],
                'gpt-image-2' => [
                    'label'   => 'GPT Image 2 (avril 2026)',
                    'note'    => 'Génération d\'images frontière OpenAI — jusqu\'à 4K, texte quasi parfait.',
                    'routing' => 'images_generations',
                    'sizes'     => ['auto', '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152', '3840x2160', '2160x3840'],
                    'qualities' => ['auto', 'low', 'medium', 'high'],
                    'pricing' => [
                        'tokens_usd_per_1m' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
                        'source_url'        => 'https://developers.openai.com/api/docs/pricing',
                        'note'              => 'Tarif officiel OpenAI GPT Image 2 (traitement standard), vérifié le 2026-09-10.',
                    ],
                ],
                // gpt-image-1 retiré : déprécié par OpenAI (coupure 2026-10-23).
                // Remplacé par GPT Image 2.5 Flare (défaut), GPT Image 2 ou les
                // modèles GPT-5.x natifs.
                'dall-e-3' => [
                    'label'   => 'DALL-E 3',
                    'note'    => 'Génération classique DALL-E 3.',
                    'routing' => 'images_generations',
                    'retirement' => [
                        'date'       => '2026-05-12',
                        'source_url' => 'https://developers.openai.com/api/docs/deprecations',
                        'note'       => 'OpenAI a arrêté DALL-E 3. Remplaçant conseillé : GPT Image 2.5 Flare.',
                        'verified'   => '2026-09-10',
                    ],
                ],
                'dall-e-2' => [
                    'label'   => 'DALL-E 2',
                    'note'    => 'Génération DALL-E 2 (legacy).',
                    'routing' => 'images_generations',
                    'retirement' => [
                        'date'       => '2026-05-12',
                        'source_url' => 'https://developers.openai.com/api/docs/deprecations',
                        'note'       => 'OpenAI a arrêté DALL-E 2. Remplaçant conseillé : GPT Image 2.5 Flare.',
                        'verified'   => '2026-09-10',
                    ],
                ],
                'gpt-5.4' => [
                    'label'   => 'GPT-5.4 (natif image)',
                    'note'    => 'Génération via Responses API (outil image_generation).',
                    'routing' => 'responses_api',
                ],
                'gpt-5.3-chat-latest' => [
                    'label'   => 'ChatGPT 5.3 (natif image)',
                    'routing' => 'responses_api',
                    'retirement' => [
                        'date'       => '2026-08-10',
                        'source_url' => 'https://developers.openai.com/api/docs/deprecations',
                        'note'       => 'OpenAI a arrêté ChatGPT 5.3. Remplaçant conseillé : GPT Image 2.5 Flare ou GPT-5.4 (natif image).',
                        'verified'   => '2026-09-10',
                    ],
                ],
                'gpt-5-mini' => [
                    'label'   => 'GPT-5 Mini (natif image)',
                    'routing' => 'responses_api',
                ],
            ],
        ],

        // -------- Gemini --------
        'gemini' => [
            'label'         => 'Gemini (Nano Banana / Imagen)',
            'default_model' => 'gemini-3.1-flash-image',
            'endpoints' => [
                // {model} est substitué à l'exécution
                'generate_content' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
                'predict'          => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:predict',
            ],
            'aspect_ratios' => ['1:1', '16:9', '9:16', '4:3', '3:4'],
            'models' => [
                // Ids GA depuis le 28 mai 2026. Les anciens ids preview
                // (gemini-3.1-flash-image-preview, gemini-3-pro-image-preview)
                // sont coupés par Google depuis le 25 juin 2026 — remappés en
                // base par la migration 052.
                'gemini-3.1-flash-image' => [
                    'label'   => 'Nano Banana 2 (Flash)',
                    'routing' => 'generate_content',
                    'default' => true,
                    'pricing' => [
                        'per_image_usd' => 0.067,
                        'source_url'    => 'https://ai.google.dev/gemini-api/docs/pricing',
                        'note'          => 'Tarif officiel Google, niveau payant, image 1K — la résolution que MIA demande (aucune taille envoyée). Vérifié le 2026-09-10.',
                    ],
                ],
                'gemini-3-pro-image' => [
                    'label'   => 'Nano Banana Pro',
                    'routing' => 'generate_content',
                    'pricing' => [
                        'per_image_usd' => 0.134,
                        'source_url'    => 'https://ai.google.dev/gemini-api/docs/pricing',
                        'note'          => 'Tarif officiel Google, niveau payant, image 1K/2K. Vérifié le 2026-09-10.',
                    ],
                ],
                // Arrêté par Google le 17 août 2026. Conservé pour les outils
                // qui le portent encore : grisés, cf. `retirement`.
                'imagen-4.0-generate-001' => [
                    'label'   => 'Imagen 4',
                    'routing' => 'predict',
                    'retirement' => [
                        'date'       => '2026-08-17',
                        'source_url' => 'https://ai.google.dev/gemini-api/docs/deprecations',
                        'note'       => 'Google a arrêté Imagen 4. Remplaçant conseillé : Nano Banana 2 (Flash).',
                        'verified'   => '2026-09-10',
                    ],
                ],
            ],
        ],

    ],

    // ================================================================
    // VIDÉO
    // ================================================================
    'video' => [

        'openai' => [
            'label'         => 'OpenAI (Sora)',
            'default_model' => 'sora-2',
            // Arrêt du FOURNISSEUR, pas d'un modèle : OpenAI retire l'API Videos
            // entière (création, suivi, téléchargement) avec tous les modèles
            // Sora 2, et n'annonce aucun remplaçant.
            'retirement' => [
                'date'       => '2026-09-24',
                'source_url' => 'https://developers.openai.com/api/docs/deprecations',
                'note'       => 'Arrêt de l\'API vidéo d\'OpenAI et de tous les modèles Sora 2, sans remplaçant.',
                'verified'   => '2026-09-10',
            ],
            'endpoints' => [
                'videos' => 'https://api.openai.com/v1/videos',
            ],
            'supports_image_input' => true,
            'seconds_options' => ['4', '8', '12'],
            'format_options'  => ['mp4'],
            // size_options : valeur → libellé
            'size_options' => [
                '720x1280'  => '720×1280 (portrait)',
                '1280x720'  => '1280×720 (paysage)',
                '1024x1792' => '1024×1792 (portrait HD)',
                '1792x1024' => '1792×1024 (paysage HD)',
            ],
            'defaults' => [
                'seconds' => '4',
                'size'    => '720x1280',
                'format'  => 'mp4',
            ],
            'models' => [
                'sora-2' => [
                    'label'   => 'Sora 2',
                    'default' => true,
                    // Tarif public par taille : les tailles « HD » du menu
                    // n'ont pas de prix publié pour Sora 2 — non tarifées.
                    'pricing' => [
                        'per_second_usd' => ['720x1280' => 0.10, '1280x720' => 0.10],
                        'source_url'     => 'https://developers.openai.com/api/docs/pricing',
                        'note'           => 'Tarif officiel OpenAI Sora 2 (720p), vérifié le 2026-09-10. OpenAI coupe l\'API vidéo le 2026-09-24.',
                    ],
                ],
                // `sora-1` n'a jamais été un modèle de l'API OpenAI (ni sa
                // référence ni sa page des dépréciations ne le connaissent) :
                // retiré de la liste, où il ne pouvait qu'échouer.
            ],
        ],

    ],

];
