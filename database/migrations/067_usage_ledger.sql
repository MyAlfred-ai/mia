-- Grand livre des opérations IA : toute consommation (tokens, recherches web,
-- minutes de dictée, embeddings, générations) passe par UsageMeter et atterrit
-- ici avec sa nature (`source`), son coût réel et ses crédits.
--
-- CHANGEMENT DE SENS de `cost_eur` (usage_logs ET tool_usage_logs) : la colonne
-- portait le « coût client » (coût réel × marge × coefficient + frais fixes,
-- avant arrondi) — un doublon approximatif de crédits × valeur du crédit, qui
-- perdait la seule information irremplaçable : ce que le FOURNISSEUR a facturé.
-- Elle porte désormais le coût réel fournisseur (€ HT, avant marge). La
-- valorisation (crédits × valeur du crédit) est dérivée à l'affichage.
-- Les lignes existantes sont ramenées au coût réel en divisant par le
-- coefficient global EN VIGUEUR au moment de la migration (meilleure estimation
-- possible : la marge de l'époque n'est pas mémorisée ; coefficients spécifiques
-- et frais fixes — à 1,0 et 0 par défaut — sont négligés).

-- Coefficient global courant, lu dans la configuration des crédits (1,3 = défaut
-- de l'écran). JSON_VALID protège d'une valeur vide ('' n'est pas du JSON et
-- ferait échouer JSON_EXTRACT) ; une valeur absente ou absurde retombe sur 1,3.
SET @mia_margin := (
    SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.global.margin_multiplier')) AS DECIMAL(12, 6))
    FROM settings
    WHERE `key` = 'credits_config_json' AND JSON_VALID(`value`)
    LIMIT 1
);
SET @mia_margin := IF(@mia_margin IS NULL OR @mia_margin <= 0, 1.3, @mia_margin);

UPDATE usage_logs      SET cost_eur = ROUND(cost_eur / @mia_margin, 6) WHERE cost_eur > 0;
UPDATE tool_usage_logs SET cost_eur = ROUND(cost_eur / @mia_margin, 6) WHERE cost_eur > 0;

ALTER TABLE usage_logs
    -- `source` accueille la taxonomie complète (cf. UsageSource) ; 20 caractères
    -- tenaient `presentation_tool` de justesse.
    MODIFY COLUMN source VARCHAR(40) NOT NULL DEFAULT 'chat',
    -- Quantité hors tokens : nombre de recherches web, minutes de dictée.
    -- L'unité dépend de la source (UsageSource::unit).
    ADD COLUMN quantity DOUBLE NOT NULL DEFAULT 0 AFTER tokens_output_reasoning,
    -- Base de connaissances concernée (alimentation, embedding d'une question) :
    -- permet la répartition des coûts par base. SET NULL à la suppression —
    -- la dépense a eu lieu, elle reste dans les totaux.
    ADD COLUMN kb_id INT UNSIGNED NULL AFTER profile_id,
    -- 0 = consommation réelle mais aucun tarif trouvable (ni service configuré,
    -- ni tarif curé) : créditée au minimum configuré et SIGNALÉE sur la page
    -- Usages, au lieu de disparaître dans un coût à zéro.
    ADD COLUMN priced TINYINT(1) NOT NULL DEFAULT 1 AFTER cost_eur,
    -- 1 = journalisée avec son coût mais n'entame pas le quota mensuel de
    -- l'utilisateur imputé (alimentation d'une base, tests d'outils, recherche
    -- des prix). Figé à l'écriture : la règle du moment fait foi.
    ADD COLUMN quota_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER priced,
    ADD CONSTRAINT fk_usage_kb FOREIGN KEY (kb_id) REFERENCES knowledge_bases(id) ON DELETE SET NULL;

CREATE INDEX idx_usage_source_created ON usage_logs(source, created_at);
CREATE INDEX idx_usage_kb             ON usage_logs(kb_id);

ALTER TABLE tool_usage_logs
    ADD COLUMN source       VARCHAR(40) NOT NULL DEFAULT 'chat' AFTER tool_type,
    ADD COLUMN priced       TINYINT(1)  NOT NULL DEFAULT 1      AFTER cost_eur,
    ADD COLUMN quota_exempt TINYINT(1)  NOT NULL DEFAULT 0      AFTER priced;

CREATE INDEX idx_tool_usage_source_created ON tool_usage_logs(source, created_at);
