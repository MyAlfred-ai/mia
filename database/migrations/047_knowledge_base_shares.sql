-- Partage explicite d'une base de connaissances.
--
-- Par défaut une base n'est visible/modifiable que par son créateur et les
-- administrateurs. Cette table ouvre l'accès à d'autres gestionnaires, soit
-- nominativement (user_id), soit via un groupe (group_id) — exactement une des
-- deux colonnes est renseignée.
--
-- can_manage = 0 → le bénéficiaire voit la base et peut la rattacher à ses
--                  assistants, sans toucher aux documents.
-- can_manage = 1 → il gère aussi documents, métadonnées et réindexation.
-- La suppression de la base et la gestion du partage restent au créateur et
-- aux administrateurs.

CREATE TABLE IF NOT EXISTS knowledge_base_shares (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kb_id      INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED DEFAULT NULL,
    group_id   INT UNSIGNED DEFAULT NULL,
    can_manage TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- MySQL autorise les doublons de NULL : les partages « groupe » (user_id
    -- NULL) ne se gênent donc pas entre eux, et réciproquement.
    UNIQUE KEY uq_kbs_kb_user  (kb_id, user_id),
    UNIQUE KEY uq_kbs_kb_group (kb_id, group_id),
    CONSTRAINT fk_kbs_kb    FOREIGN KEY (kb_id)    REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    CONSTRAINT fk_kbs_user  FOREIGN KEY (user_id)  REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_kbs_group FOREIGN KEY (group_id) REFERENCES user_groups(id)     ON DELETE CASCADE,
    CONSTRAINT ck_kbs_target CHECK ((user_id IS NULL) <> (group_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_kbs_user  ON knowledge_base_shares(user_id);
CREATE INDEX idx_kbs_group ON knowledge_base_shares(group_id);
