-- Budget IA mensuel : la dépense du mois (somme de cost_eur, séparée usage /
-- maintenance, opérations sans tarif) est lue par AiBudget via
-- UsageLog::costBetween(). Index COUVRANTS : la requête ne lit aucune ligne,
-- seulement l'index — elle tourne près du plafond à chaque opération IA.
CREATE INDEX idx_usage_month_cost ON usage_logs(created_at, quota_exempt, priced, cost_eur);
CREATE INDEX idx_tool_usage_month_cost ON tool_usage_logs(created_at, quota_exempt, priced, cost_eur);
