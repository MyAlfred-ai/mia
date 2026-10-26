-- Instances LLM configurées par l'admin (plusieurs modèles par fournisseur possible)
CREATE TABLE IF NOT EXISTS llm_models (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider     VARCHAR(50)  NOT NULL,
    model        VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_llm_models_provider_model (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
