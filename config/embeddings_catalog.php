<?php

declare(strict_types=1);

/**
 * Catalogue des modèles d'EMBEDDING (bases de connaissances).
 * Source unique de vérité, même pattern que config/llm_catalog.php :
 * pour ajouter/mettre à jour un modèle, éditer UNIQUEMENT ce fichier —
 * EmbeddingService et l'UI admin se mettent à jour automatiquement.
 *
 * Par provider :
 *   default_model : modèle proposé par défaut à la création d'une base
 *   endpoint      : URL de l'API ({model} interpolé pour Gemini)
 *   max_batch     : nb max de textes par requête (prudent vs limites tokens)
 *   models        : label affiché, dims (taille de vecteur = collection
 *                   Qdrant), options de requête spécifiques au provider
 *                   (request_dimensions / output_dimensionality),
 *                   task_prefixes (tâche écrite dans le texte : `query` pour
 *                   la question d'un tour, `document` pour un extrait — cf.
 *                   EmbeddingService::withTaskPrefix) et
 *                   pricing_usd_per_1m : tarif officiel USD / 1M tokens —
 *                   repli de CreditService quand aucun service
 *                   `embedding:{provider}::{model}` n'est tarifé dans
 *                   Admin → Crédits.
 *
 * NB Qdrant normalise les vecteurs en distance Cosine : pas besoin de
 * re-normaliser côté client les embeddings Gemini tronqués.
 */
return [
    'openai' => [
        'default_model' => 'text-embedding-3-large',
        'endpoint'      => 'https://api.openai.com/v1/embeddings',
        'max_batch'     => 96,
        'models'        => [
            'text-embedding-3-large' => [
                'label'              => 'OpenAI 3-large (1536d)',
                'dims'               => 1536,
                'request_dimensions' => 1536,
                'pricing_usd_per_1m' => 0.13,
            ],
            // PAS de retrait annoncé. Un courriel d'OpenAI l'a dit coupé au
            // 2026-10-23 : OpenAI a corrigé l'annonce, et la page officielle des
            // dépréciations (developers.openai.com/api/docs/deprecations,
            // vérifiée le 2026-09-10) ne lui donne aucune date. Le libellé
            // « déprécié 10/2026 » écartait donc à tort le modèle le moins cher.
            'text-embedding-3-small' => [
                'label' => 'OpenAI 3-small (1536d)',
                'dims'  => 1536,
                'pricing_usd_per_1m' => 0.02,
            ],
        ],
    ],
    'mistral' => [
        'default_model' => 'mistral-embed',
        'endpoint'      => 'https://api.mistral.ai/v1/embeddings',
        'max_batch'     => 32,
        'models'        => [
            'mistral-embed' => [
                'label' => 'Mistral Embed (1024d)',
                'dims'  => 1024,
                'pricing_usd_per_1m' => 0.10,
            ],
        ],
    ],
    // Pages Embeddings, Models, Pricing et Deprecations de Google relues le
    // 2026-11-19. Gemini Embedding 2 (GA 22/04/2026) est le modèle que Google
    // recommande : gemini-embedding-001 a une date d'arrêt annoncée (« au plus
    // tôt » le 14/05/2028, remplaçant gemini-embedding-2). Les deux espaces de
    // vecteurs sont INCOMPATIBLES : changer de modèle = réindexer la base (ce
    // que fait « Réindexer avec »). Une base existante garde son modèle ; seul
    // le modèle proposé à la création change.
    'gemini' => [
        'default_model' => 'gemini-embedding-2',
        'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:batchEmbedContents',
        'max_batch'     => 64,
        'models'        => [
            // `task_type` REFUSÉ par ce modèle (« the task_type parameter is not
            // supported ») : la tâche s'écrit DANS le texte, préfixe de requête
            // d'un côté, structure de document de l'autre (format « asymétrique »
            // de Google pour la recherche). Sans titre connu : `title: none`.
            // Dimensions réduites renormalisées par Google lui-même (Qdrant
            // compare en cosinus de toute façon). 8 192 jetons par texte.
            'gemini-embedding-2' => [
                'label'                  => 'Gemini Embedding 2 (1536d)',
                'dims'                   => 1536,
                'output_dimensionality'  => 1536,
                'task_prefixes'          => [
                    'query'    => 'task: search result | query: ',
                    'document' => 'title: none | text: ',
                ],
                'pricing_usd_per_1m'     => 0.20,
            ],
            'gemini-embedding-001' => [
                'label'                  => 'Gemini Embedding (1536d)',
                'dims'                   => 1536,
                'output_dimensionality'  => 1536,
                'pricing_usd_per_1m'     => 0.15,
            ],
        ],
    ],
];
