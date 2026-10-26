<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Admin') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'dashboard'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
    $providerLabels = [
        'openai'     => 'OpenAI',
        'anthropic'  => 'Anthropic',
        'mistral'    => 'Mistral',
        'perplexity' => 'Perplexity',
        'gemini'     => 'Gemini',
        'deepseek'   => 'DeepSeek',
        'moonshot'   => 'Kimi (Moonshot)',
        'zai'        => 'GLM (Z.ai)',
        'local'      => t('LLM Local'),
    ];
    // Nombres à la locale de l'écran, mêmes séparateurs que Format :
    // « 12,5 » / « 1 234 » (FR), « 12.5 » / « 1,234 » (EN).
    $dashEn  = I18n::locale() === 'en';
    $dashNum = static fn(float $v, int $d = 1): string => $dashEn ? number_format($v, $d, '.', ',') : number_format($v, $d, ',', ' ');
    // Valeur d'un crédit affichable ("0,001" / "0.001") sans zéros traînants.
    $creditValueLabel = rtrim(rtrim(number_format($creditValueEur, 6, $dashEn ? '.' : ',', ''), '0'), $dashEn ? '.' : ',');
    // Phrase entière dans le dictionnaire : `{a}…{/a}` y marque le lien, posé
    // APRÈS l'échappement (te() rend les accolades telles quelles).
    $withLink = static fn(string $escaped, string $href, string $style = 'color:var(--accent)'): string =>
        str_replace(['{a}', '{/a}'], ['<a href="' . htmlspecialchars($href) . '" style="' . $style . '">', '</a>'], $escaped);
    $monthLabel     = Format::monthLabel(date('Y-m'));
    $prevMonthLabel = Format::monthLabel(date('Y-m', strtotime('first day of last month')));
?>
<div class="admin-content">
    <h1><?= te('Tableau de bord') ?></h1>
    <p style="color:var(--text-muted);font-size:13px;margin:-8px 0 20px"><?= te('Situation au {date} — mois en cours ({mois}) comparé à {mois_prec}.', ['date' => Format::dateFr(date('Y-m-d')), 'mois' => $monthLabel, 'mois_prec' => $prevMonthLabel]) ?></p>

    <?php if (!empty($llmSetupNeeded)): // calculé par admin-nav.php (requis ci-dessus) : aucune clé API LLM active ?>
    <div class="llm-setup-banner">
        <div class="llm-setup-banner-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
        </div>
        <div class="llm-setup-banner-text">
            <div class="llm-setup-banner-title"><?= te('Bienvenue ! Une étape indispensable reste à faire.') ?></div>
            <div class="llm-setup-banner-sub">
                <?= te('MIA a besoin d\'au moins une clé API de modèle (OpenAI, Anthropic, Mistral… ou un LLM local) pour pouvoir répondre. Tant qu\'aucune clé n\'est enregistrée, les assistants restent muets.') ?>
            </div>
        </div>
        <div class="llm-setup-banner-actions">
            <a href="/admin/llms" class="btn-primary"><?= te('Renseigner une clé API') ?></a>
            <a href="/setup/suivant" class="llm-setup-banner-alt"><?= te('Voir la liste de démarrage →') ?></a>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPIs mois en cours -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:16px">
        <div class="card" style="text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= Format::credits((int)$monthKpis['total_credits']) ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Crédits ce mois-ci') ?></div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><?= te('mois précédent : {valeur}', ['valeur' => Format::credits((int)$prevKpis['total_credits'])]) ?></div>
        </div>
        <div class="card" style="text-align:center" title="<?= te('Coût réel facturé par les fournisseurs IA (tarifs de Tarifs & crédits, hors marge)') ?>">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= Format::eur((float)$monthKpis['cost_eur']) ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Coût réel IA ce mois-ci') ?></div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><?= te('mois préc. : {valeur}', ['valeur' => Format::eur((float)$prevKpis['cost_eur'])]) ?></div>
        </div>
        <div class="card" style="text-align:center" title="<?= te('Crédits consommés × valeur actuelle du crédit (configurable dans Tarifs & crédits)') ?>">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= Format::eur((float)$monthKpis['billed_eur']) ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Valorisation des crédits') ?></div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><a href="/admin/credits" style="color:var(--text-muted)"><?= te('{valeur} €/crédit', ['valeur' => $creditValueLabel]) ?></a> · <?= te('mois préc. : {valeur}', ['valeur' => Format::eur((float)$prevKpis['billed_eur'])]) ?></div>
        </div>
        <div class="card" style="text-align:center" title="<?= te('Coût réel du mois complet au rythme quotidien actuel (celui que plafonne le budget IA)') ?>">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= $projection['cost_eur'] !== null ? Format::eur((float)$projection['cost_eur']) : '—' ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Projection fin de mois') ?></div>
            <div style="font-size:11px;margin-top:2px">
                <?php if ($projection['cost_eur'] === null): ?>
                    <span style="color:var(--text-muted)"><?= te('disponible à partir du 3') ?></span>
                <?php elseif ($projection['evolution_pct'] === null): ?>
                    <span style="color:var(--text-muted)"><?= te('coût réel estimé') ?></span>
                <?php elseif ($projection['evolution_pct'] > 0): ?>
                    <span class="badge badge-red"><?= te('{pct} % vs mois préc.', ['pct' => '+' . $dashNum((float)$projection['evolution_pct'])]) ?></span>
                <?php elseif ($projection['evolution_pct'] < 0): ?>
                    <span class="badge badge-green"><?= te('{pct} % vs mois préc.', ['pct' => $dashNum((float)$projection['evolution_pct'])]) ?></span>
                <?php else: ?>
                    <span class="badge badge-gray"><?= te('stable vs mois préc.') ?></span>
                <?php endif; ?>
                <?php if (!$budget['enabled']): ?>
                    <div style="margin-top:4px"><a href="/admin/budget" style="color:var(--accent)"><?= te('Fixer un budget →') ?></a></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card" style="text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= Format::credits((int)$monthKpis['requests']) ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Requêtes ce mois-ci') ?></div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><?= te('mois précédent : {valeur}', ['valeur' => Format::credits((int)$prevKpis['requests'])]) ?></div>
        </div>
        <div class="card" style="text-align:center">
            <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= (int)$monthKpis['active_users'] ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= te('Utilisateurs actifs ce mois-ci') ?></div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><?= htmlspecialchars(I18n::plural((int)$userStats['active'], 'sur {n} compte actif', 'sur {n} comptes actifs')) ?></div>
        </div>
    </div>

    <?php if ($budget['enabled']):
        $bFill = min(100, max(0, (int)$budget['pct']));
        $bTone = $budget['state'] === AiBudget::STATE_REACHED ? 'is-reached' : ($budget['state'] === AiBudget::STATE_ALERT ? 'is-alert' : '');
        $bBadge = match ($budget['state']) {
            AiBudget::STATE_REACHED => ['badge-red', $budget['blocks'] ? t('Budget atteint : service suspendu') : t('Budget dépassé : signalement seul')],
            AiBudget::STATE_ALERT   => ['badge-yellow', t('Seuil d\'alerte atteint')],
            default                 => ['badge-green', t('Sous contrôle')],
        };
    ?>
    <!-- Budget IA du mois (Coûts → Budget) -->
    <div class="card budget-band" style="margin-bottom:16px">
        <div class="budget-card-head">
            <div style="font-weight:600"><?= te('Budget IA — {mois}', ['mois' => Format::monthLabel($budget['month'])]) ?></div>
            <span class="badge <?= $bBadge[0] ?>"><?= htmlspecialchars($bBadge[1]) ?></span>
        </div>
        <div class="budget-bar <?= $bTone ?>" role="img" aria-label="<?= te('{pct} % du budget consommé', ['pct' => (int)$budget['pct']]) ?>">
            <span class="budget-bar-fill" style="width:<?= $bFill ?>%"></span>
            <span class="budget-bar-mark" style="left:<?= (int)$budget['alert_pct'] ?>%"></span>
        </div>
        <p class="budget-line">
            <?= teb('{b}{depense}{/b} dépensés sur {b}{plafond}{/b} ({pct} %)', ['depense' => Format::eur((float)$budget['spent_eur']), 'plafond' => Format::eur((float)$budget['limit_eur']), 'pct' => (int)$budget['pct']]) ?>
            <?php if ($projection['cost_eur'] !== null): ?>
                · <?= $projection['reach_date']
                    ? te('projection ≈ {montant}, plafond atteint vers le {date}', ['montant' => Format::eur((float)$projection['cost_eur']), 'date' => Format::dateFr($projection['reach_date'])])
                    : te('projection ≈ {montant}', ['montant' => Format::eur((float)$projection['cost_eur'])]) ?>
            <?php endif; ?>
            · <a href="/admin/budget" style="color:var(--accent)"><?= te('Gérer le budget →') ?></a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Tendance 12 mois + top consommateurs -->
    <div class="dashboard-main-grid">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <div style="font-weight:600"><?= te('Consommation des 12 derniers mois') ?></div>
                <a href="/admin/usage" style="font-size:12px;color:var(--accent);text-decoration:none"><?= te('Analyse détaillée →') ?></a>
            </div>
            <?php $hasMonthlyData = array_sum(array_column($monthly, 'total_credits')) > 0; ?>
            <?php if ($hasMonthlyData): ?>
                <div style="position:relative;height:260px"><canvas id="dashboard-monthly-chart"></canvas></div>
            <?php else: ?>
                <div style="text-align:center;color:var(--text-muted);padding:48px 0;font-size:13px"><?= te('Aucune consommation enregistrée sur les 12 derniers mois.') ?></div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <div style="font-weight:600"><?= te('Top consommateurs — {mois}', ['mois' => $monthLabel]) ?></div>
                <a href="/admin/usage?preset=current_month#users" style="font-size:12px;color:var(--accent);text-decoration:none"><?= te('Tout voir →') ?></a>
            </div>
            <?php if (empty($topUsers)): ?>
                <div style="text-align:center;color:var(--text-muted);padding:32px 0;font-size:13px"><?= te('Aucune consommation ce mois-ci.') ?></div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:12px">
                    <?php foreach ($topUsers as $topUser): ?>
                        <div>
                            <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px;margin-bottom:4px">
                                <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($topUser['user_name']) ?></strong>
                                <span style="color:var(--text-muted);flex-shrink:0"><?= te('{n} cr.', ['n' => Format::credits((int)$topUser['total_credits'])]) ?> · <?= Format::eur((float)$topUser['billed_eur']) ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;height:6px;background:var(--bg-surface);border-radius:3px">
                                    <div style="height:6px;border-radius:3px;background:var(--accent);width:<?= min(100, (float)$topUser['share_pct']) ?>%"></div>
                                </div>
                                <span style="font-size:11px;color:var(--text-muted);min-width:42px;text-align:right"><?= te('{pct} %', ['pct' => $dashNum((float)$topUser['share_pct'])]) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alertes + état plateforme -->
    <div class="dashboard-bottom-grid">
        <div class="card">
            <div style="font-weight:600;margin-bottom:12px"><?= te('Alertes') ?></div>
            <?php
                // Budget IA : seuil franchi, plafond atteint, ou projection au-dessus du plafond.
                $budgetAlert = $budget['enabled'] && (
                    in_array($budget['state'], [AiBudget::STATE_ALERT, AiBudget::STATE_REACHED], true)
                    || !empty($budget['projection']['over'])
                );
                // Points à régler (AdminAttention — la liste des pastilles du
                // menu, déjà calculée par admin-nav.php pour cette requête).
                // « Aucune clé API » a son bandeau et sa ligne ci-dessous : les
                // constats dédiés ne se répètent pas ici.
                $configSignals = AdminAttention::shown(AdminAttention::current());
                $hasAlerts = !empty($quotaAlerts) || !empty($configSignals) || empty($activeProviders) || $budgetAlert;
            ?>
            <?php if (!$hasAlerts): ?>
                <div style="color:var(--text-muted);font-size:13px"><?= te('✓ Aucune alerte : quotas sous contrôle, configuration complète.') ?></div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
                    <?php if (empty($activeProviders)): ?>
                        <div style="display:flex;align-items:baseline;gap:8px">
                            <span class="badge badge-red"><?= te('Config') ?></span>
                            <span><?= $withLink(te('Aucune clé API LLM active — {a}configurer un fournisseur{/a}.'), '/admin/llms') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($budgetAlert): ?>
                        <div style="display:flex;align-items:baseline;gap:8px">
                            <?php if ($budget['state'] === AiBudget::STATE_REACHED): ?>
                                <span class="badge badge-red"><?= te('Budget') ?></span>
                                <span><?= $budget['blocks']
                                    ? $withLink(te('Budget IA atteint : les demandes sont suspendues pour tous jusqu\'au {date}. {a}Accorder une rallonge{/a}.', ['date' => CreditQuota::resetLabel($budget['resets_on'])]), '/admin/budget')
                                    : $withLink(te('Budget IA dépassé ({pct} %) : signalement seul, rien n\'est bloqué. {a}Voir le budget{/a}.', ['pct' => (int)$budget['pct']]), '/admin/budget') ?></span>
                            <?php elseif ($budget['state'] === AiBudget::STATE_ALERT): ?>
                                <span class="badge badge-yellow"><?= te('{pct} %', ['pct' => (int)$budget['pct']]) ?></span>
                                <span><?= $withLink(te('Budget IA : seuil d\'alerte franchi ({depense} sur {plafond}) — {a}voir le budget{/a}.', ['depense' => Format::eur((float)$budget['spent_eur']), 'plafond' => Format::eur((float)$budget['limit_eur'])]), '/admin/budget') ?></span>
                            <?php else: ?>
                                <span class="badge badge-yellow"><?= te('Budget') ?></span>
                                <span><?= $withLink(!empty($budget['projection']['reach_date'])
                                    ? te('Au rythme actuel, le budget IA sera atteint vers le {date} — {a}voir le budget{/a}.', ['date' => Format::dateFr($budget['projection']['reach_date'])])
                                    : te('Au rythme actuel, le budget IA sera atteint — {a}voir le budget{/a}.'), '/admin/budget') ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($configSignals as $sig): [, $sigPath, $sigHref] = AdminAttention::ENTRIES[$sig['entry']]; ?>
                        <div style="display:flex;align-items:baseline;gap:8px">
                            <span class="badge <?= $sig['level'] === AdminAttention::ALERT ? 'badge-red' : 'badge-yellow' ?>"><?= te('Config') ?></span>
                            <?php // Chemin traduit depuis le menu (« Personnes › Comptes »), le même mot pour mot en français. ?>
                            <span><strong><?= htmlspecialchars(AdminMenu::pathText((string)$sig['entry'], (string)$sigPath)) ?></strong> — <?= htmlspecialchars($sig['text']) ?>
                                <a href="<?= htmlspecialchars($sigHref) ?>" style="color:var(--accent)"><?= te('Régler →') ?></a></span>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($quotaAlerts as $alert): ?>
                        <div style="display:flex;align-items:baseline;gap:8px">
                            <span class="badge <?= $alert['pct'] >= 100 ? 'badge-red' : 'badge-yellow' ?>"><?= te('{pct} %', ['pct' => (int)$alert['pct']]) ?></span>
                            <?php $alertVars = ['nom' => $alert['user_name'], 'utilise' => Format::credits((int)$alert['used_credits']), 'quota' => Format::credits((int)$alert['quota_credits'])]; ?>
                            <span><?= $alert['pct'] >= 100
                                ? teb('{b}{nom}{/b} — {utilise} / {quota} crédits du quota mensuel (bloqué).', $alertVars)
                                : teb('{b}{nom}{/b} — {utilise} / {quota} crédits du quota mensuel.', $alertVars) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div style="font-weight:600;margin-bottom:12px"><?= te('Plateforme') ?></div>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <span style="color:var(--text-muted)"><?= te('Comptes utilisateurs') ?></span>
                    <a href="/admin/users" style="color:var(--text);text-decoration:none"><?= str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars(I18n::plural((int)$userStats['active'], '{b}{n}{/b} actif / {total}', '{b}{n}{/b} actifs / {total}', ['total' => (int)$userStats['total']]))) ?></a>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <span style="color:var(--text-muted)"><?= te('Nouveaux comptes ce mois-ci') ?></span>
                    <strong><?= (int)$userStats['new_this_month'] ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <span style="color:var(--text-muted)"><?= te('Assistants actifs') ?></span>
                    <a href="/admin/profiles" style="color:var(--text);text-decoration:none"><strong><?= (int)$activeProfilesCount ?></strong> / <?= (int)$totalProfilesCount ?></a>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">
                    <span style="color:var(--text-muted)"><?= te('Fournisseurs LLM actifs') ?></span>
                    <a href="/admin/llms" style="color:var(--text);text-decoration:none;text-align:right">
                        <?php if (empty($activeProviders)): ?>
                            <strong>0</strong>
                        <?php else: ?>
                            <strong><?= count($activeProviders) ?></strong>
                            <span style="color:var(--text-muted);font-size:12px">(<?= htmlspecialchars(implode(', ', array_map(
                                static fn(array $k): string => $providerLabels[(string)$k['provider']] ?? (string)$k['provider'],
                                $activeProviders
                            ))) ?>)</span>
                        <?php endif; ?>
                    </a>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <span style="color:var(--text-muted)"><?= te('Valeur du crédit') ?></span>
                    <a href="/admin/credits" style="color:var(--text);text-decoration:none"><strong><?= te('{valeur} €', ['valeur' => $creditValueLabel]) ?></strong></a>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <span style="color:var(--text-muted)"><?= te('Tokens consommés ce mois-ci') ?></span>
                    <strong><?= Format::tokens((int)$monthKpis['tokens']) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Stockage -->
    <?php
        // Répartition de l'empreinte disque ($storage : cf. StorageInfo).
        $storageItems = [
            ['key' => 'mysql',     'label' => t('Base de données MySQL'),     'color' => '#3b82f6', 'hint' => t('données + index')],
            ['key' => 'qdrant',    'label' => t('Base vectorielle (Qdrant)'), 'color' => '#8b5cf6', 'hint' => $storage['qdrant_exact'] ? t('mesure du volume qdrant_data') : t('estimation depuis l\'index (volume non monté)')],
            ['key' => 'generated', 'label' => t('Médias générés'),            'color' => '#f59e0b', 'hint' => t('images, vidéos et contenus créés par les outils')],
            ['key' => 'documents', 'label' => t('Documents & fichiers'),      'color' => '#10b981', 'hint' => t('pièces jointes, bases de connaissances, docs assistants')],
            ['key' => 'app',       'label' => t('Application'),               'color' => '#64748b', 'hint' => t('code, assets, logs, cache')],
        ];
        $storageTotal   = max(1, (int)$storage['total']);
        $storageTimeFr  = preg_match('/ (\d{2}:\d{2})$/', (string)$storage['computed_at'], $stM) === 1 ? $stM[1] : '';
    ?>
    <div class="card" style="margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <div style="font-weight:600"><?= te('Stockage') ?></div>
            <div style="font-size:13px;color:var(--text-muted)"><?= str_replace(['{b}', '{/b}'], ['<strong style="color:var(--text)">', '</strong>'], te('Total application : {b}{taille}{/b}', ['taille' => Format::sizeGoMo((int)$storage['total'])])) ?></div>
        </div>
        <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;background:var(--bg-surface);margin-bottom:14px">
            <?php foreach ($storageItems as $item): $bytes = $storage[$item['key']]; ?>
                <?php if ($bytes !== null && $bytes > 0): ?>
                    <div style="width:<?= round($bytes / $storageTotal * 100, 2) ?>%;background:<?= $item['color'] ?>" title="<?= htmlspecialchars($item['label'] . ' — ' . Format::sizeGoMo((int)$bytes)) ?>"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="dashboard-storage-legend">
            <?php foreach ($storageItems as $item): $bytes = $storage[$item['key']]; ?>
                <div style="display:flex;align-items:baseline;gap:8px;font-size:13px" title="<?= htmlspecialchars($item['hint']) ?>">
                    <span style="width:10px;height:10px;border-radius:3px;background:<?= $item['color'] ?>;flex-shrink:0;align-self:center"></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($item['label']) ?></span>
                    <span style="margin-left:auto;color:var(--text-muted);flex-shrink:0">
                        <?php if ($bytes === null): ?>
                            <?= te('indisponible') ?>
                        <?php else: ?>
                            <strong style="color:var(--text)"><?= htmlspecialchars(Format::sizeGoMo((int)$bytes)) ?></strong>
                            · <?= te('{pct} %', ['pct' => $dashNum($bytes / $storageTotal * 100)]) ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:12px">
            <?= $storage['qdrant_exact']
                ? te('Base vectorielle mesurée sur le volume qdrant_data (WAL et index compris).')
                : te('Base vectorielle estimée d\'après l\'index Qdrant (WAL, vecteurs, graphe) — monter le volume qdrant_data pour une mesure exacte.') ?>
            <?= $storageTimeFr !== ''
                ? te('Mesure actualisée toutes les 10 minutes — dernière à {heure}.', ['heure' => $storageTimeFr])
                : te('Mesure actualisée toutes les 10 minutes.') ?>
        </div>
    </div>
</div>

<style>
.dashboard-main-grid { display:grid; grid-template-columns: 2fr 1fr; gap:12px; margin-bottom:16px; }
.dashboard-bottom-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
.dashboard-bottom-grid .badge { white-space:nowrap; flex-shrink:0; }
.dashboard-storage-legend { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px 24px; }
@media (max-width: 900px) {
    .dashboard-main-grid, .dashboard-bottom-grid { grid-template-columns: 1fr; }
}
</style>

<?php if ($hasMonthlyData): ?>
<script src="/assets/js/vendor/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    // Barres empilées par FAMILLE d'opérations (UsageSource::families) :
    // conversations, créations, bases de connaissances, conception & admin.
    const FAMILIES = <?= json_encode(array_map(static fn(string $f): array => [
        'key'   => $f,
        'label' => UsageSource::familyLabel($f),
        'color' => UsageSource::familyColor($f),
    ], UsageSource::families()), JSON_UNESCAPED_UNICODE) ?>;
    const MONTHLY = <?= json_encode(array_map(static fn(array $m): array => [
        'label'    => Format::monthLabel($m['month']),
        'families' => (object)($m['families'] ?? []),
    ], $monthly), JSON_UNESCAPED_UNICODE) ?>;
    const CREDIT_VALUE_EUR = <?= json_encode((float)$creditValueEur) ?>;
    // Langue de l'écran : nombres et montants comme Format (« 1 234,56 € » / « €1,234.56 »).
    const EN = !!(window.I18N && window.I18N.lang === 'en');
    const LOCALE = EN ? 'en-US' : 'fr-FR';
    const L = {
        line:  <?= tj('{famille} : {n} crédits') ?>,
        total: <?= tj('Total : {n} crédits — {montant}') ?>,
    };
    const fill = (tpl, vars) => tpl.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));

    const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const fmtCredits = value => Number(value || 0).toLocaleString(LOCALE);
    const fmtEur = value => {
        const abs = Math.abs(value);
        const decimals = abs > 0 && abs < 0.01 ? 4 : (abs < 1 ? 3 : 2);
        const nb = value.toLocaleString(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        return EN ? '€' + nb : nb + ' €';
    };

    const canvas = document.getElementById('dashboard-monthly-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: MONTHLY.map(m => m.label),
            datasets: FAMILIES.map(f => ({
                label: f.label,
                data: MONTHLY.map(m => Number((m.families || {})[f.key] || 0)),
                backgroundColor: f.color,
                borderRadius: 4
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { color: cssVar('--text-muted') } },
                y: { stacked: true, grid: { color: cssVar('--border') }, ticks: { color: cssVar('--text-muted') } }
            },
            plugins: {
                legend: { labels: { color: cssVar('--text') } },
                tooltip: {
                    callbacks: {
                        label: ctx => fill(L.line, { famille: ctx.dataset.label, n: fmtCredits(ctx.parsed.y) }),
                        footer: items => {
                            const total = items.reduce((sum, item) => sum + item.parsed.y, 0);
                            return fill(L.total, { n: fmtCredits(total), montant: fmtEur(total * CREDIT_VALUE_EUR) });
                        }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
