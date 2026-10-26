-- Liste blanche des LLMs autorisés par groupe utilisateur.
-- llm_key = slug "provider::model" (chaîne libre, pas de FK car le catalogue
-- est composé du dictionnaire `config/llm_catalog.php` + table `llm_models`).
-- Sémantique : groupe sans aucune ligne ici = aucune restriction (tous LLMs).
-- Suppression du groupe → ON DELETE CASCADE nettoie l'entrée.

CREATE TABLE IF NOT EXISTS user_group_llms (
    group_id INT UNSIGNED NOT NULL,
    llm_key  VARCHAR(120) NOT NULL,
    PRIMARY KEY (group_id, llm_key),
    CONSTRAINT fk_ugl_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ugl_llm_key ON user_group_llms(llm_key);
