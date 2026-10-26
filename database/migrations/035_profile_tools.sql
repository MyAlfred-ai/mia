-- Liaison assistant ↔ outils : déclare quels outils (image/vidéo/HTML) un
-- assistant est autorisé à appeler via function-calling. Table de jointure,
-- miroir de user_group_tools. Le binding autorise l'assistant, sans contourner
-- le scope user-group (re-validé à l'exécution).
CREATE TABLE IF NOT EXISTS profile_tools (
    profile_id INT UNSIGNED NOT NULL,
    tool_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (profile_id, tool_id),
    CONSTRAINT fk_pt_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_tool    FOREIGN KEY (tool_id)    REFERENCES chat_tools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
