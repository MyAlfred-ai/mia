-- n8n cesse d'être un « connecteur » (réglage global du menu « + ») pour
-- devenir un OUTIL, au même titre que la génération d'image : une ligne de
-- chat_tools, liée aux assistants autorisés à la déclencher.
--
-- Une instance déjà configurée ne doit pas être perdue : on recopie les
-- réglages dans une ligne chat_tools. Les deux secrets (clé API, valeur de
-- l'en-tête webhook) sont déplacés TELS QUELS — même chiffrement, même
-- ENCRYPTION_KEY —, ils n'ont donc pas besoin d'être re-saisis.
INSERT INTO chat_tools (name, type, provider, model, config_json, api_key_enc, is_active, sort_order)
SELECT
    'Workflows n8n',
    'n8n',
    'n8n',
    'webhook',
    JSON_OBJECT(
        'base_url',            COALESCE((SELECT `value` FROM settings WHERE `key` = 'n8n_base_url'), ''),
        'tag',                 COALESCE(NULLIF((SELECT `value` FROM settings WHERE `key` = 'n8n_tag'), ''), 'mia'),
        'webhook_header_name', COALESCE((SELECT `value` FROM settings WHERE `key` = 'n8n_webhook_header_name'), ''),
        'webhook_secret_enc',  COALESCE((SELECT `value` FROM settings WHERE `key` = 'n8n_webhook_header_value_enc'), ''),
        'auto_read',           COALESCE((SELECT `value` FROM settings WHERE `key` = 'n8n_auto_read'), '0') = '1'
    ),
    (SELECT `value` FROM settings WHERE `key` = 'n8n_api_key_enc'),
    -- Le connecteur masqué devient un outil inactif : masqué reste masqué.
    CASE WHEN COALESCE((SELECT `value` FROM settings WHERE `key` = 'connector_n8n_enabled'), '1') = '0' THEN 0 ELSE 1 END,
    0
FROM DUAL
WHERE EXISTS (
    SELECT 1 FROM settings
    WHERE `key` = 'n8n_base_url' AND `value` IS NOT NULL AND `value` <> ''
)
AND NOT EXISTS (SELECT 1 FROM chat_tools WHERE type = 'n8n');

-- Les réglages n'ont plus de lecteur : les laisser ferait croire à une
-- configuration active alors que seule la ligne chat_tools compte désormais.
DELETE FROM settings WHERE `key` IN (
    'connector_n8n_enabled',
    'n8n_base_url',
    'n8n_api_key_enc',
    'n8n_tag',
    'n8n_webhook_header_name',
    'n8n_webhook_header_value_enc',
    'n8n_auto_read',
    'connector_triggers_n8n'
);
