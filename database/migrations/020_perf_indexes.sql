-- Index additionnels pour la performance.

-- Accélère UsageLog::conversationCreditsTotal() (filtre par conversation_id)
CREATE INDEX idx_usage_conversation
    ON usage_logs(conversation_id);

-- Idem pour les logs d'outils filtrés par conversation
CREATE INDEX idx_tool_usage_conversation
    ON tool_usage_logs(conversation_id);

-- Remplace idx_messages_conversation pour Message::forConversation() qui trie par created_at ASC
CREATE INDEX idx_messages_conv_created
    ON messages(conversation_id, created_at);
