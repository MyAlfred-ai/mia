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
 *                   (request_dimensions / output_dimensionality) et
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
    'gemini' => [
        'default_model' => 'gemini-embedding-001',
        'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:batchEmbedContents',
        'max_batch'     => 64,
        'models'        => [
            'gemini-embedding-001' => [
                'label'                  => 'Gemini Embedding (1536d)',
                'dims'                   => 1536,
                'output_dimensionality'  => 1536,
                'pricing_usd_per_1m'     => 0.15,
            ],
        ],
    ],
];
