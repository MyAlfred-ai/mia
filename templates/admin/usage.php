<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Consommation') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'usage'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
    /**
     * Page Usages — le grand livre des opérations IA, lu sous quatre angles :
     * mensuel (familles), par utilisateur, par catégorie (la nature de chaque
     * coût) et répartition (fournisseurs, assistants, outils, modèles, bases).
     *
     * Deux grandeurs € distinctes partout (source : UsageLog / CreditService) :
     *   - coût réel IA  = ce que les fournisseurs facturent (hors marge) ;
     *   - valorisation  = crédits × valeur ACTUELLE du crédit.
     */
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
    $toolTypeLabels = [
        'image'            => t('Image'),
        'html'             => 'HTML',
        'html_illustrated' => t('Site illustré'),
        'video'            => t('Vidéo'),
        'map'              => t('Itinéraire'),
        'presentation'     => t('Présentation'),
        'n8n'              => 'n8n',
        'gamma'            => 'Gamma',
    ];
    $providerColors = [
        'openai'     => '#10a37f',
        'anthropic'  => '#c96442',
        'mistral'    => '#f97316',
        'perplexity' => '#7c3aed',
        'gemini'     => '#3b82f6',
        'deepseek'   => '#06b6d4',
        'moonshot'   => '#f43f5e',
        'zai'        => '#0ea5e9',
        'local'      => '#8b5cf6',
    ];
    $toolTypeColors = [
        'image'            => '#22c55e',
        'html'             => '#f59e0b',
        'html_illustrated' => '#d97706',
        'video'            => '#3b82f6',
        'map'              => '#14b8a6',
        'presentation'     => '#8b5cf6',
        'n8n'              => '#ef4444',
        'gamma'            => '#ec4899',
    ];

    // URLs conservant les filtres courants (période + utilisateur).
    $currentFilters = array_filter([
        'preset'  => $preset !== '' ? $preset : null,
        'from'    => $fromInput !== '' ? $fromInput : null,
        'to'      => $toInput !== '' ? $toInput : null,
        'user_id' => $userId,
    ], static fn($v) => $v !== null && $v !== '');
    $buildUrl = static function (array $overrides = [], string $path = '/admin/usage') use ($currentFilters): string {
        $params = array_filter(
            array_merge($currentFilters, $overrides),
            static fn($v) => $v !== null && $v !== ''
        );
        return $path . ($params !== [] ? '?' . http_build_query($params) : '');
    };

    // Valeur d'un crédit affichable ("0,001") sans zéros traînants.
    $creditValueLabel = rtrim(rtrim(number_format($creditValueEur, 6, ',', ''), '0'), ',');
    $familyKeys       = UsageSource::families();
    $unpricedOps      = (int)($kpis['unpriced_ops'] ?? 0);
    $exemptCredits    = (int)($kpis['exempt_credits'] ?? 0);
    // Nombres dans la locale : virgule décimale et espace des milliers en
    // français, point décimal et virgule des milliers en anglais (comme Format::).
    $usEn = I18n::locale() === 'en';
    $nf   = static fn(float $v, int $d): string => $usEn ? number_format($v, $d, '.', ',') : number_format($v, $d, ',', ' ');
    // Même chose sans zéros décimaux inutiles (« 12,50 » → « 12,5 », « 12,0 » → « 12 »).
    $nfTrim = static fn(float $v, int $d): string => $d > 0 ? rtrim(rtrim($nf($v, $d), '0'), $usEn ? '.' : ',') : $nf($v, 0);
    $quantityLabel    = static function (string $unit, float $quantity) use ($nf): string {
        if ($quantity <= 0) {
            return '';
        }
        return match ($unit) {
            UsageSource::UNIT_SEARCHES => I18n::plural((int)round($quantity), '{n} recherche', '{n} recherches', ['n' => $nf($quantity, 0)]),
            UsageSource::UNIT_MINUTES  => t('{n} min', ['n' => $nf($quantity, 1)]),
            'units'                    => I18n::plural((int)round($quantity), '{n} unité', '{n} unités', ['n' => $nf($quantity, 0)]),
            default                    => '',
        };
    };
?>
<div class="admin-content">
    <h1><?= te('Consommation') ?></h1>
    <?php if (!empty($success)): ?>
        <div class="card" style="border-color:#16a34a;margin-bottom:12px;color:#bbf7d0">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="card" style="border-color:#dc2626;margin-bottom:12px;color:#fecaca">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Filtres : période (sélecteur partagé avec Coûts → Budget) et personne -->
    <div class="card usage-filters">
        <div class="usage-filters-row">
            <?php
            $picker = [
                'period'  => $period,
                'compare' => $compare,
                'path'    => '/admin/usage',
                'keep'    => ['user_id' => $userId],
                'today'   => date('Y-m-d'),
            ];
            require ROOT . '/templates/partials/period-picker.php';
            ?>
            <form method="GET" action="/admin/usage" class="usage-user-filter">
                <?php foreach (UsagePeriod::query($period) as $qName => $qValue): ?>
                    <input type="hidden" name="<?= htmlspecialchars($qName) ?>" value="<?= htmlspecialchars($qValue) ?>">
                <?php endforeach; ?>
                <label for="usageUserFilter"><?= te('Utilisateur') ?></label>
                <select id="usageUserFilter" name="user_id" class="form-select">
                    <option value=""><?= te('Tous les utilisateurs') ?></option>
                    <?php foreach ($usersList as $userOption): ?>
                        <option value="<?= (int)$userOption['id'] ?>" <?= $userId === (int)$userOption['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$userOption['name']) ?> — <?= htmlspecialchars((string)$userOption['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-secondary"><?= te('Filtrer') ?></button>
            </form>
        </div>
        <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px">
            <a href="<?= htmlspecialchars($buildUrl(['export' => 1])) ?>" class="btn-secondary" style="font-size:12px"><?= te('Export brut (logs, 500 dernières lignes)') ?></a>
            <form method="POST" action="/admin/usage/backfill-tools" onsubmit='return confirm(<?= tj('Lancer un backfill estimatif des usages outils historiques ?') ?>);' style="margin:0">
                <button type="submit" class="btn-secondary" style="font-size:12px"><?= te('Backfill estimatif outils') ?></button>
            </form>
            <span style="font-size:12px;color:var(--text-muted)">
                <?= te('Backfill : estimation à partir des messages historiques (crédits non recalculés).') ?>
            </span>
        </div>
    </div>

    <!-- KPIs période — en crédits, opérations et personnes : les euros sont sur la page Budget -->
    <?php
    $ov = $insights;
    $ms = static fn(?float $v): string => $v === null ? '—' : ($v < 1000
        ? $nf($v, 0) . ' ms'
        : $nfTrim($v / 1000, 1) . ' s');
    $pct = static fn(?float $v): string => $v === null ? '—' : t('{n} %', ['n' => $nfTrim($v, 1)]);
    ?>
    <div class="usage-kpis">
        <div class="usage-kpi">
            <span class="usage-kpi-label"><?= te('Crédits consommés') ?></span>
            <span class="usage-kpi-value"><?= Format::credits((int)$kpis['total_credits']) ?></span>
            <span class="usage-kpi-sub"><?= $exemptCredits > 0 ? te('dont {n} hors quota', ['n' => Format::credits($exemptCredits)]) : te('tous dans les quotas') ?></span>
        </div>
        <div class="usage-kpi" title="<?= te('Chaque appel facturable : réponse, régénération, titre, embedding, recherche web, image, vidéo…') ?>">
            <span class="usage-kpi-label"><?= te('Opérations IA') ?></span>
            <span class="usage-kpi-value"><?= Format::credits((int)$kpis['requests']) ?></span>
            <span class="usage-kpi-sub"><?= te('{n} tokens', ['n' => Format::tokens((int)$kpis['tokens'])]) ?></span>
        </div>
        <div class="usage-kpi">
            <span class="usage-kpi-label"><?= te('Personnes actives') ?></span>
            <span class="usage-kpi-value"><?= (int)$kpis['active_users'] ?><?php if ($userId === null): ?> <small>/ <?= (int)$ov['accounts'] ?></small><?php endif; ?></span>
            <span class="usage-kpi-sub"><?= $userId === null && $ov['accounts'] > 0 ? te('{n} % des comptes actifs', ['n' => (int)floor(min(100, (int)$kpis['active_users'] / $ov['accounts'] * 100))]) : te('sur la période') ?></span>
        </div>
        <div class="usage-kpi" title="<?= te("Temps entre l'envoi et la fin de la réponse, réponses abouties") ?>">
            <span class="usage-kpi-label"><?= te('Temps de réponse') ?></span>
            <span class="usage-kpi-value"><?= htmlspecialchars($ms($ov['median_ms'])) ?></span>
            <span class="usage-kpi-sub"><?= $ov['p90_ms'] !== null ? te('9 sur 10 sous {temps}', ['temps' => $ms($ov['p90_ms'])]) : te('médiane') ?></span>
        </div>
        <div class="usage-kpi<?= ($ov['regen_rate'] ?? 0) >= 10 ? ' is-warn' : '' ?>" title="<?= te("Régénérations ÷ réponses : une réponse relancée est souvent une réponse qui n'a pas convenu") ?>">
            <span class="usage-kpi-label"><?= te('Réponses relancées') ?></span>
            <span class="usage-kpi-value"><?= htmlspecialchars($pct($ov['regen_rate'])) ?></span>
            <span class="usage-kpi-sub"><?= te('par « Regénérer »') ?></span>
        </div>
        <div class="usage-kpi<?= ($ov['error_rate'] ?? 0) >= 5 ? ' is-warn' : '' ?>" title="<?= te("Réponses terminées par un encadré d'erreur (fournisseur saturé, crédit épuisé, contexte trop long…)") ?>">
            <span class="usage-kpi-label"><?= te('Réponses en erreur') ?></span>
            <span class="usage-kpi-value"><?= htmlspecialchars($pct($ov['error_rate'])) ?></span>
            <span class="usage-kpi-sub"><?= te('{n} sur {total}', ['n' => Format::credits((int)$ov['errors']), 'total' => Format::credits((int)$ov['responses'])]) ?></span>
        </div>
    </div>
    <p class="usage-eur-note"><?= str_replace('{lien}', '<a href="/admin/budget">' . te('Coûts → Budget') . '</a>', te('Les montants en euros (coût réel, projection, plafond) sont dans {lien}.')) ?></p>

    <?php if ($unpricedOps > 0): ?>
        <div class="card" style="border-color:#f59e0b;margin-bottom:16px;font-size:13px">
            <strong style="color:#f59e0b">⚠ <?= htmlspecialchars(I18n::plural($unpricedOps, '{n} opération sans tarif', '{n} opérations sans tarif', ['n' => Format::credits($unpricedOps)])) ?></strong>
            <span style="color:var(--text-muted)">— <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/credits">', '</a>'], te('consommation réelle, mais aucun prix trouvable (ni service renseigné dans {a}Tarifs & crédits{/a}, ni tarif connu du catalogue). Ces opérations sont créditées au minimum configuré et leur coût réel est compté à 0 €.')) ?></span>
            <?php if (!empty($unpriced)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:8px">
                    <?php foreach (array_slice($unpriced, 0, 12) as $u): ?>
                        <span class="badge badge-gray usage-chip" title="<?= htmlspecialchars(UsageSource::label((string)$u['source'])) ?>"><?= htmlspecialchars((string)$u['name']) ?> · <?= te('{n} op.', ['n' => Format::credits((int)$u['ops'])]) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Onglets + exports -->
    <div class="usage-toolbar">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="usage-tab active" data-tab="overview"><?= te("Vue d'ensemble") ?></button>
            <button class="usage-tab" data-tab="monthly"><?= te('Vue mensuelle') ?></button>
            <button class="usage-tab" data-tab="users"><?= te('Par utilisateur') ?></button>
            <button class="usage-tab" data-tab="categories"><?= te('Par catégorie') ?></button>
            <button class="usage-tab" data-tab="repartition"><?= te('Répartition') ?></button>
        </div>
        <div style="display:flex;gap:8px">
            <a id="usage-export-csv" class="btn-secondary" style="font-size:12px" href="<?= htmlspecialchars($buildUrl(['view' => 'monthly'], '/admin/usage/export-csv')) ?>"><?= te('Export CSV') ?></a>
            <a id="usage-export-pdf" class="btn-secondary" style="font-size:12px" href="<?= htmlspecialchars($buildUrl(['view' => 'monthly'], '/admin/usage/export-pdf')) ?>"><?= te('Export PDF') ?></a>
        </div>
    </div>

    <!-- Vue mensuelle -->
<?php require ROOT . '/templates/admin/usage-overview.php'; ?>

    <div id="tab-monthly" class="usage-tab-panel" style="display:none">
        <?php if (empty($monthly)): ?>
            <div class="card" style="text-align:center;color:var(--text-muted);padding:32px"><?= te('Aucune donnée sur la période.') ?></div>
        <?php else: ?>
        <div class="card" style="margin-bottom:16px">
            <div style="font-weight:600;margin-bottom:4px"><?= te('Crédits consommés par mois') ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px"><?= str_replace('{onglet}', '<em>' . te('Par catégorie') . '</em>', te("Barres empilées par famille d'opérations — le détail de chaque famille est dans l'onglet {onglet}.")) ?></div>
            <div style="position:relative;height:280px"><canvas id="monthly-chart"></canvas></div>
        </div>
        <div class="card table-wrap">
            <table class="sortable usage-monthly-table" data-default-sort-col="0" data-default-sort-dir="asc">
                <thead>
                    <tr>
                        <th data-sort-type="string"><?= te('Mois') ?></th>
                        <?php foreach ($familyKeys as $family): ?>
                            <th data-sort-type="number"><?= htmlspecialchars(UsageSource::familyLabel($family)) ?></th>
                        <?php endforeach; ?>
                        <th data-sort-type="number"><?= te('Total crédits') ?></th>
                        <th data-sort-type="number" title="<?= te('Ce que les fournisseurs IA facturent (hors marge)') ?>"><?= te('Coût réel IA') ?></th>
                        <th data-sort-type="number" title="<?= te('Crédits × valeur actuelle du crédit') ?>"><?= te('Valorisation') ?></th>
                        <th data-sort-type="number"><?= te('Opérations') ?></th>
                        <th data-sort-type="number"><?= te('Utilisateurs actifs') ?></th>
                        <th data-sort-type="number"><?= te('Évolution') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $monthRow): ?>
                    <tr>
                        <td data-label="<?= te('Mois') ?>" data-sort-value="<?= htmlspecialchars($monthRow['month']) ?>"><strong><?= htmlspecialchars(Format::monthLabel($monthRow['month'])) ?></strong></td>
                        <?php foreach ($familyKeys as $family): ?>
                            <?php $fc = (int)($monthRow['families'][$family] ?? 0); ?>
                            <td data-label="<?= htmlspecialchars(UsageSource::familyLabel($family)) ?>" data-sort-value="<?= $fc ?>"><?= $fc > 0 ? Format::credits($fc) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                        <?php endforeach; ?>
                        <td data-label="<?= te('Total crédits') ?>" data-sort-value="<?= (int)$monthRow['total_credits'] ?>"><strong><?= Format::credits((int)$monthRow['total_credits']) ?></strong></td>
                        <td data-label="<?= te('Coût réel IA') ?>" data-sort-value="<?= (float)$monthRow['cost_eur'] ?>"><?= Format::eur((float)$monthRow['cost_eur']) ?></td>
                        <td data-label="<?= te('Valorisation') ?>" data-sort-value="<?= (float)$monthRow['billed_eur'] ?>"><?= Format::eur((float)$monthRow['billed_eur']) ?></td>
                        <td data-label="<?= te('Opérations') ?>" data-sort-value="<?= (int)$monthRow['requests'] ?>" style="color:var(--text-muted)"><?= Format::credits((int)$monthRow['requests']) ?></td>
                        <td data-label="<?= te('Utilisateurs') ?>" data-sort-value="<?= (int)$monthRow['active_users'] ?>" style="color:var(--text-muted)"><?= (int)$monthRow['active_users'] ?></td>
                        <td data-label="<?= te('Évolution') ?>" data-sort-value="<?= $monthRow['evolution_pct'] !== null ? (float)$monthRow['evolution_pct'] : -99999 ?>">
                            <?php if ($monthRow['evolution_pct'] === null): ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php elseif ($monthRow['evolution_pct'] > 0): ?>
                                <span class="badge badge-red"><?= te('{n} %', ['n' => '+' . $nf((float)$monthRow['evolution_pct'], 1)]) ?></span>
                            <?php elseif ($monthRow['evolution_pct'] < 0): ?>
                                <span class="badge badge-green"><?= te('{n} %', ['n' => $nf((float)$monthRow['evolution_pct'], 1)]) ?></span>
                            <?php else: ?>
                                <span class="badge badge-gray"><?= te('{n} %', ['n' => 0]) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid var(--border)">
                        <td><?= te('TOTAL') ?></td>
                        <?php foreach ($familyKeys as $family): ?>
                            <td><?= Format::credits((int)($kpis['families'][$family] ?? 0)) ?></td>
                        <?php endforeach; ?>
                        <td><?= Format::credits((int)$kpis['total_credits']) ?></td>
                        <td><?= Format::eur((float)$kpis['cost_eur']) ?></td>
                        <td><?= Format::eur((float)$kpis['billed_eur']) ?></td>
                        <td><?= Format::credits((int)$kpis['requests']) ?></td>
                        <td><?= (int)$kpis['active_users'] ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Par utilisateur -->
    <div id="tab-users" class="usage-tab-panel" style="display:none">
        <div class="card table-wrap">
            <table class="sortable usage-users-table" data-default-sort-col="1" data-default-sort-dir="desc">
                <thead>
                    <tr>
                        <th data-sort-type="string"><?= te('Utilisateur') ?></th>
                        <th data-sort-type="number"><?= te('Crédits') ?></th>
                        <th data-sort-type="number" title="<?= te("Journalisés sur l'utilisateur mais sans entamer son quota : alimentation de ses bases de connaissances, tests d'outils, recherche des prix") ?>"><?= te('Hors quota') ?></th>
                        <th data-sort-type="number" title="<?= te('Ce que les fournisseurs IA facturent (hors marge)') ?>"><?= te('Coût réel IA') ?></th>
                        <th data-sort-type="number" title="<?= te('Crédits × valeur actuelle du crédit') ?>"><?= te('Valorisation') ?></th>
                        <th data-sort-type="number"><?= te('% du total') ?></th>
                        <th data-sort-type="number"><?= te('Opérations') ?></th>
                        <th data-sort-type="date"><?= te('Dernière activité') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usersTotals as $userRow): ?>
                    <?php $userKey = $userRow['user_id'] !== null ? (string)$userRow['user_id'] : 'deleted'; ?>
                    <tr class="usage-user-row" data-user-key="<?= htmlspecialchars($userKey) ?>" title="<?= te('Cliquer pour le détail mensuel') ?>">
                        <td data-label="<?= te('Utilisateur') ?>" data-sort-value="<?= htmlspecialchars($userRow['user_name']) ?>">
                            <div><strong><?= htmlspecialchars($userRow['user_name']) ?></strong></div>
                            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($userRow['email']) ?></div>
                        </td>
                        <td data-label="<?= te('Crédits') ?>" data-sort-value="<?= (int)$userRow['total_credits'] ?>"><strong><?= Format::credits((int)$userRow['total_credits']) ?></strong></td>
                        <td data-label="<?= te('Hors quota') ?>" data-sort-value="<?= (int)$userRow['exempt_credits'] ?>" style="color:var(--text-muted)"><?= (int)$userRow['exempt_credits'] > 0 ? Format::credits((int)$userRow['exempt_credits']) : '—' ?></td>
                        <td data-label="<?= te('Coût réel IA') ?>" data-sort-value="<?= (float)$userRow['cost_eur'] ?>"><?= Format::eur((float)$userRow['cost_eur']) ?></td>
                        <td data-label="<?= te('Valorisation') ?>" data-sort-value="<?= (float)$userRow['billed_eur'] ?>"><?= Format::eur((float)$userRow['billed_eur']) ?></td>
                        <td data-label="<?= te('% du total') ?>" data-sort-value="<?= (float)$userRow['share_pct'] ?>">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span style="min-width:44px"><?= te('{n} %', ['n' => $nf((float)$userRow['share_pct'], 1)]) ?></span>
                                <div style="flex:1;height:6px;background:var(--bg-surface);border-radius:3px;min-width:50px">
                                    <div style="height:6px;border-radius:3px;background:var(--accent);width:<?= min(100, (float)$userRow['share_pct']) ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="<?= te('Opérations') ?>" data-sort-value="<?= (int)$userRow['requests'] ?>" style="color:var(--text-muted)"><?= Format::credits((int)$userRow['requests']) ?></td>
                        <td data-label="<?= te('Dernière activité') ?>" data-sort-value="<?= htmlspecialchars($userRow['last_activity']) ?>" style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(substr($userRow['last_activity'], 0, 16)) ?></td>
                        <td style="width:28px;text-align:center"><span class="usage-chevron">▸</span></td>
                    </tr>
                    <tr class="usage-detail-row" data-detail-for="<?= htmlspecialchars($userKey) ?>" hidden>
                        <td colspan="9">
                            <?php $userMonths = $monthlyPerUser[$userKey] ?? []; ?>
                            <?php if (empty($userMonths)): ?>
                                <div style="color:var(--text-muted);font-size:13px;padding:8px 0"><?= te('Aucun détail mensuel.') ?></div>
                            <?php else: ?>
                            <table class="usage-user-months">
                                <thead>
                                    <tr>
                                        <th><?= te('Mois') ?></th>
                                        <th><?= te('Crédits') ?></th>
                                        <th><?= te('Hors quota') ?></th>
                                        <th><?= te('Coût réel IA') ?></th>
                                        <th><?= te('Valorisation') ?></th>
                                        <th><?= te('Opérations') ?></th>
                                        <th><?= te('Cumul crédits') ?></th>
                                        <th><?= te('Cumul valorisation') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userMonths as $monthRow): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars(Format::monthLabel($monthRow['month'])) ?></strong></td>
                                        <td><strong><?= Format::credits((int)$monthRow['total_credits']) ?></strong></td>
                                        <td style="color:var(--text-muted)"><?= (int)$monthRow['exempt_credits'] > 0 ? Format::credits((int)$monthRow['exempt_credits']) : '—' ?></td>
                                        <td><?= Format::eur((float)$monthRow['cost_eur']) ?></td>
                                        <td><?= Format::eur((float)$monthRow['billed_eur']) ?></td>
                                        <td style="color:var(--text-muted)"><?= Format::credits((int)$monthRow['requests']) ?></td>
                                        <td><?= Format::credits((int)$monthRow['cumul_credits']) ?></td>
                                        <td><?= Format::eur((float)$monthRow['cumul_billed_eur']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($usersTotals)): ?>
                        <tr class="usage-empty-row"><td colspan="9" style="text-align:center;color:var(--text-muted);padding:24px"><?= te('Aucune donnée') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Par catégorie : la nature de chaque coût -->
    <div id="tab-categories" class="usage-tab-panel" style="display:none">
        <div class="card" style="margin-bottom:16px;font-size:13px;color:var(--text-muted)">
            <?= str_replace('{badge}', '<span class="badge badge-gray">' . te('hors quota') . '</span>', teb("Chaque opération IA est journalisée avec sa {b}nature{/b} : réponse de chat, titre automatique, embedding d'une question, recherche Internet, image, vectorisation d'un document… Les catégories marquées {badge} sont des coûts d'exploitation (imputés à qui les a déclenchés, sans entamer son quota mensuel).")) ?>
            <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/credits">', '</a>'], teb("Une ligne {b}non tarifée{/b} a bien consommé, mais aucun prix n'a été trouvé pour elle : renseignez-le dans {a}Tarifs & crédits{/a}.")) ?>
        </div>
        <div class="usage-category-grid">
            <?php foreach ($byCategory as $cat): ?>
                <?php $hasData = (int)$cat['requests'] > 0; ?>
                <div class="card usage-category-card<?= $hasData ? '' : ' is-empty' ?>" style="--cat-color: <?= htmlspecialchars($cat['color']) ?>">
                    <div class="usage-category-head">
                        <span class="usage-category-dot"></span>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600"><?= htmlspecialchars($cat['label']) ?>
                                <?php if ($cat['quota_exempt']): ?><span class="badge badge-gray" style="margin-left:6px"><?= te('hors quota') ?></span><?php endif; ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars($cat['description']) ?></div>
                        </div>
                    </div>
                    <div class="usage-category-kpis">
                        <div><strong><?= Format::credits((int)$cat['credits']) ?></strong><span><?= te('crédits') ?></span></div>
                        <div><strong><?= Format::eur((float)$cat['cost_eur']) ?></strong><span><?= te('coût réel IA') ?></span></div>
                        <div><strong><?= Format::eur((float)$cat['billed_eur']) ?></strong><span><?= te('valorisation') ?></span></div>
                        <div><strong><?= Format::credits((int)$cat['requests']) ?></strong><span><?= te('opérations') ?></span></div>
                    </div>
                    <?php if (!empty($cat['sources'])): ?>
                    <table class="usage-user-months" style="margin-top:8px">
                        <thead>
                            <tr><th><?= te('Détail') ?></th><th><?= te('Opérations') ?></th><th><?= te('Volume') ?></th><th><?= te('Crédits') ?></th><th><?= te('Coût réel IA') ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cat['sources'] as $src): ?>
                                <?php
                                $volume = (int)$src['tokens'] > 0
                                    ? t('{n} tokens', ['n' => Format::tokens((int)$src['tokens'])])
                                    : $quantityLabel((string)$src['unit'], (float)$src['quantity']);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($src['label']) ?>
                                        <?php if ((int)$src['unpriced_ops'] > 0): ?>
                                            <span class="badge badge-red" title="<?= te('Aucun tarif trouvé pour ces opérations') ?>"><?= htmlspecialchars(I18n::plural((int)$src['unpriced_ops'], '{n} non tarifée', '{n} non tarifées', ['n' => Format::credits((int)$src['unpriced_ops'])])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-muted)"><?= Format::credits((int)$src['requests']) ?></td>
                                    <td style="color:var(--text-muted)"><?= $volume !== '' ? htmlspecialchars($volume) : '—' ?></td>
                                    <td><strong><?= Format::credits((int)$src['credits']) ?></strong></td>
                                    <td><?= Format::eur((float)$src['cost_eur']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:8px"><?= te('Aucune opération sur la période.') ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($byKnowledgeBase)): ?>
        <div class="card table-wrap" style="margin-top:16px">
            <div style="font-weight:600;margin-bottom:4px"><?= te('Coût par base de connaissances') ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= te("Alimentation (découpage, description d'images, vectorisation) et embedding des questions posées aux assistants qui l'utilisent.") ?></div>
            <table class="sortable" data-default-sort-col="2" data-default-sort-dir="desc">
                <thead>
                    <tr>
                        <th data-sort-type="string"><?= te('Base') ?></th>
                        <th data-sort-type="number"><?= te('Opérations') ?></th>
                        <th data-sort-type="number"><?= te('Crédits') ?></th>
                        <th data-sort-type="number"><?= te('Coût réel IA') ?></th>
                        <th data-sort-type="number"><?= te('Tokens') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byKnowledgeBase as $kbRow): ?>
                    <tr>
                        <td data-sort-value="<?= htmlspecialchars($kbRow['kb_name']) ?>"><strong><?= htmlspecialchars($kbRow['kb_name']) ?></strong></td>
                        <td data-sort-value="<?= (int)$kbRow['requests'] ?>" style="color:var(--text-muted)"><?= Format::credits((int)$kbRow['requests']) ?></td>
                        <td data-sort-value="<?= (int)$kbRow['credits'] ?>"><strong><?= Format::credits((int)$kbRow['credits']) ?></strong></td>
                        <td data-sort-value="<?= (float)$kbRow['cost_eur'] ?>"><?= Format::eur((float)$kbRow['cost_eur']) ?></td>
                        <td data-sort-value="<?= (int)$kbRow['tokens'] ?>" style="color:var(--text-muted)"><?= Format::tokens((int)$kbRow['tokens']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Répartition (donuts + modèles + détail consolidé) -->
    <div id="tab-repartition" class="usage-tab-panel" style="display:none">
        <div class="usage-pies-row">
            <div class="card usage-pie-card">
                <div style="font-weight:600;margin-bottom:10px"><?= te('Répartition par fournisseur') ?> <span style="font-weight:400;color:var(--text-muted);font-size:12px"><?= te('(crédits)') ?></span></div>
                <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                    <canvas id="llm-pie" width="200" height="200" style="flex-shrink:0"></canvas>
                    <div id="llm-legend" style="display:flex;flex-direction:column;gap:8px"></div>
                </div>
            </div>
            <div class="card usage-pie-card">
                <div style="font-weight:600;margin-bottom:10px"><?= te('Répartition par assistant') ?> <span style="font-weight:400;color:var(--text-muted);font-size:12px"><?= te('(crédits)') ?></span></div>
                <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                    <canvas id="profile-pie" width="200" height="200" style="flex-shrink:0"></canvas>
                    <div id="profile-legend" style="display:flex;flex-direction:column;gap:8px"></div>
                </div>
            </div>
            <div class="card usage-pie-card">
                <div style="font-weight:600;margin-bottom:10px"><?= te('Répartition par outil') ?> <span style="font-weight:400;color:var(--text-muted);font-size:12px"><?= te('(crédits des générations)') ?></span></div>
                <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                    <canvas id="tool-pie" width="200" height="200" style="flex-shrink:0"></canvas>
                    <div id="tool-legend" style="display:flex;flex-direction:column;gap:8px"></div>
                </div>
            </div>
        </div>

        <?php if (!empty($byModel)): ?>
        <div class="card table-wrap" style="margin-bottom:16px">
            <div style="font-weight:600;margin-bottom:4px"><?= te('Détail par modèle') ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= te("Tous les modèles appelés sur la période — modèles de chat, d'embedding, de dictée — avec leurs tokens détaillés (cache lu, raisonnement).") ?></div>
            <table class="sortable" data-default-sort-col="6" data-default-sort-dir="desc">
                <thead>
                    <tr>
                        <th data-sort-type="string"><?= te('Modèle') ?></th>
                        <th data-sort-type="number"><?= te('Opérations') ?></th>
                        <th data-sort-type="number">Input</th>
                        <th data-sort-type="number" title="<?= te('Tokens lus depuis le cache (tarif réduit)') ?>">Cached</th>
                        <th data-sort-type="number">Output</th>
                        <th data-sort-type="number" title="<?= te('Tokens de raisonnement (modèles thinking)') ?>">Reasoning</th>
                        <th data-sort-type="number"><?= te('Crédits') ?></th>
                        <th data-sort-type="number"><?= te('Coût réel IA') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byModel as $row): ?>
                        <?php
                        $providerLabel = $providerLabels[$row['provider']] ?? ucfirst((string)$row['provider']);
                        $modelLabel = $row['model'] !== '' ? $providerLabel . ' · ' . $row['model'] : $providerLabel;
                        ?>
                        <tr>
                            <td data-sort-value="<?= htmlspecialchars($modelLabel) ?>"><?= htmlspecialchars($modelLabel) ?></td>
                            <td data-sort-value="<?= (int)$row['requests'] ?>" style="color:var(--text-muted)"><?= Format::credits((int)$row['requests']) ?></td>
                            <td data-sort-value="<?= (int)$row['tokens_in'] ?>"><?= Format::tokens((int)$row['tokens_in']) ?></td>
                            <td data-sort-value="<?= (int)$row['tokens_in_cached'] ?>" style="color:var(--text-muted)"><?= (int)$row['tokens_in_cached'] > 0 ? Format::tokens((int)$row['tokens_in_cached']) : '—' ?></td>
                            <td data-sort-value="<?= (int)$row['tokens_out'] ?>"><?= Format::tokens((int)$row['tokens_out']) ?></td>
                            <td data-sort-value="<?= (int)$row['tokens_out_reasoning'] ?>" style="color:var(--text-muted)"><?= (int)$row['tokens_out_reasoning'] > 0 ? Format::tokens((int)$row['tokens_out_reasoning']) : '—' ?></td>
                            <td data-sort-value="<?= (int)$row['credits'] ?>"><strong><?= Format::credits((int)$row['credits']) ?></strong></td>
                            <td data-sort-value="<?= (float)$row['cost_eur'] ?>"><?= Format::eur((float)$row['cost_eur']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="card table-wrap">
            <table class="sortable usage-detail-table" data-default-sort-col="2" data-default-sort-dir="desc">
                <thead>
                    <tr>
                        <th data-sort-type="string"><?= te('Utilisateur') ?></th>
                        <th><?= te('Répartition détaillée') ?></th>
                        <th data-sort-type="number"><?= te('Crédits') ?></th>
                        <th data-sort-type="number"><?= te('Opérations') ?></th>
                        <th data-sort-type="number"><?= te('Total tokens') ?></th>
                        <th data-sort-type="date"><?= te('Dernière activité') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailByUser as $u): ?>
                    <tr>
                        <td data-label="<?= te('Utilisateur') ?>" data-sort-value="<?= htmlspecialchars((string)($u['user_name'] ?? '')) ?>">
                            <div><strong><?= htmlspecialchars($u['user_name'] ?? '—') ?></strong></div>
                            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($u['email'] ?? '') ?></div>
                        </td>
                        <td data-label="<?= te('Répartition') ?>">
                            <div class="usage-breakdown-block">
                                <div class="usage-breakdown-line">
                                    <span class="usage-breakdown-label">LLM</span>
                                    <div class="usage-breakdown-values">
                                        <?php if (!empty($u['llm_breakdown'])): ?>
                                            <?php foreach ($u['llm_breakdown'] as $llm): ?>
                                                <?php $llmName = $providerLabels[$llm['provider']] ?? $llm['provider']; ?>
                                                <span class="badge badge-gray usage-chip"><?= htmlspecialchars($llmName) ?> · <?= te('{n} op.', ['n' => Format::credits((int)$llm['requests'])]) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="usage-breakdown-line">
                                    <span class="usage-breakdown-label"><?= te('Assistants') ?></span>
                                    <div class="usage-breakdown-values">
                                        <?php if (!empty($u['profile_breakdown'])): ?>
                                            <?php foreach ($u['profile_breakdown'] as $profileRow): ?>
                                                <span class="badge badge-gray usage-chip"><?= htmlspecialchars($profileRow['profile_name']) ?> · <?= te('{n} op.', ['n' => Format::credits((int)$profileRow['requests'])]) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="usage-breakdown-line">
                                    <span class="usage-breakdown-label"><?= te('Outils') ?></span>
                                    <div class="usage-breakdown-values">
                                        <?php if (!empty($u['tool_breakdown'])): ?>
                                            <?php foreach ($u['tool_breakdown'] as $toolRow): ?>
                                                <?php $toolType = $toolTypeLabels[$toolRow['tool_type']] ?? $toolRow['tool_type']; ?>
                                                <span class="badge badge-gray usage-chip"><?= htmlspecialchars($toolRow['tool_name']) ?> (<?= htmlspecialchars($toolType) ?>) · <?= Format::credits((int)$toolRow['uses']) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td data-label="<?= te('Crédits') ?>" data-sort-value="<?= (int)($u['credits'] ?? 0) ?>"><strong><?= Format::credits((int)($u['credits'] ?? 0)) ?></strong></td>
                        <td data-label="<?= te('Opérations') ?>" data-sort-value="<?= (int)($u['requests'] ?? 0) ?>"><?= Format::credits((int)($u['requests'] ?? 0)) ?></td>
                        <td data-label="<?= te('Total tokens') ?>" data-sort-value="<?= (int)($u['total_tokens'] ?? 0) ?>"><?= Format::tokens((int)($u['total_tokens'] ?? 0)) ?></td>
                        <td data-label="<?= te('Dernière activité') ?>" data-sort-value="<?= htmlspecialchars((string)($u['last_activity'] ?? '')) ?>" style="font-size:12px;color:var(--text-muted)">
                            <?= !empty($u['last_activity']) ? htmlspecialchars(substr((string)$u['last_activity'], 0, 16)) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($detailByUser)): ?>
                        <tr class="usage-empty-row"><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px"><?= te('Aucune donnée') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="/assets/js/vendor/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    const FAMILIES = <?= json_encode(array_map(static fn(string $f): array => [
        'key'   => $f,
        'label' => UsageSource::familyLabel($f),
        'color' => UsageSource::familyColor($f),
    ], $familyKeys), JSON_UNESCAPED_UNICODE) ?>;
    const MONTHLY = <?= json_encode(array_map(static fn(array $m): array => [
        'month'    => $m['month'],
        'label'    => Format::monthLabel($m['month']),
        'families' => (object)($m['families'] ?? []),
        'total'    => (int)$m['total_credits'],
        'cost'     => (float)$m['cost_eur'],
    ], $monthly), JSON_UNESCAPED_UNICODE) ?>;
    const CREDIT_VALUE_EUR = <?= json_encode((float)$creditValueEur) ?>;
    const EXPORT_URLS = {
        csv: {
            monthly: <?= json_encode($buildUrl(['view' => 'monthly'], '/admin/usage/export-csv'), JSON_UNESCAPED_UNICODE) ?>,
            users: <?= json_encode($buildUrl(['view' => 'users'], '/admin/usage/export-csv'), JSON_UNESCAPED_UNICODE) ?>
        },
        pdf: {
            monthly: <?= json_encode($buildUrl(['view' => 'monthly'], '/admin/usage/export-pdf'), JSON_UNESCAPED_UNICODE) ?>,
            users: <?= json_encode($buildUrl(['view' => 'users'], '/admin/usage/export-pdf'), JSON_UNESCAPED_UNICODE) ?>
        }
    };
    const providerData = <?= json_encode(array_values($byProvider), JSON_UNESCAPED_UNICODE) ?>;
    const profileData  = <?= json_encode(array_values($byProfile), JSON_UNESCAPED_UNICODE) ?>;
    const toolData     = <?= json_encode(array_values($byTool), JSON_UNESCAPED_UNICODE) ?>;
    const providerColors = <?= json_encode($providerColors) ?>;
    const providerLabels = <?= json_encode($providerLabels, JSON_UNESCAPED_UNICODE) ?>;
    const toolTypeLabels = <?= json_encode($toolTypeLabels, JSON_UNESCAPED_UNICODE) ?>;
    const toolTypeColors = <?= json_encode($toolTypeColors) ?>;
    const fallbackColors = ['#10a37f','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6','#f97316'];

    const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    // Nombres et euros dans la langue de la page (mêmes règles que Format::eur).
    const EN = document.documentElement.lang === 'en';
    const NUM_LOCALE = EN ? 'en-US' : 'fr-FR';
    // Substitution des {variables} d'un texte traduit côté serveur (tj()).
    const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));
    const fmtNum = value => Number(value || 0).toLocaleString(NUM_LOCALE);
    const fmtCredits = fmtNum;
    const fmtEur = value => {
        const abs = Math.abs(value);
        const decimals = abs > 0 && abs < 0.01 ? 4 : (abs < 1 ? 3 : 2);
        const n = value.toLocaleString(NUM_LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        return EN ? '€' + n : n + ' €';
    };

    // ---- Onglets (persistance via hash) + synchronisation des boutons d'export
    const tabs = document.querySelectorAll('.usage-tab');
    const panels = { overview: 'tab-overview', monthly: 'tab-monthly', users: 'tab-users', categories: 'tab-categories', repartition: 'tab-repartition' };
    const exportCsvBtn = document.getElementById('usage-export-csv');
    const exportPdfBtn = document.getElementById('usage-export-pdf');

    function activateTab(name) {
        if (!panels[name]) name = 'overview';
        tabs.forEach(btn => btn.classList.toggle('active', btn.dataset.tab === name));
        Object.entries(panels).forEach(([key, id]) => {
            document.getElementById(id).style.display = key === name ? '' : 'none';
        });
        const exportable = name === 'monthly' || name === 'users';
        [exportCsvBtn, exportPdfBtn].forEach(btn => {
            btn.classList.toggle('is-disabled', !exportable);
            btn.title = exportable ? '' : <?= tj('Export disponible sur les vues « Vue mensuelle » et « Par utilisateur »') ?>;
        });
        if (exportable) {
            exportCsvBtn.href = EXPORT_URLS.csv[name];
            exportPdfBtn.href = EXPORT_URLS.pdf[name];
        }
    }
    tabs.forEach(btn => btn.addEventListener('click', () => {
        history.replaceState(null, '', '#' + btn.dataset.tab);
        activateTab(btn.dataset.tab);
    }));
    [exportCsvBtn, exportPdfBtn].forEach(btn => btn.addEventListener('click', e => {
        if (btn.classList.contains('is-disabled')) e.preventDefault();
    }));
    activateTab((location.hash || '').replace('#', '') || 'overview');
    // Les tuiles de la vue d'ensemble mènent à « Par catégorie » par un lien d'ancre.
    window.addEventListener('hashchange', () => activateTab((location.hash || '').replace('#', '')));

    // ---- Bar chart mensuel empilé par famille d'opérations
    const monthlyCanvas = document.getElementById('monthly-chart');
    if (monthlyCanvas && typeof Chart !== 'undefined' && MONTHLY.length) {
        new Chart(monthlyCanvas, {
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
                            label: ctx => fill(<?= tj('{famille} : {n} crédits') ?>, { famille: ctx.dataset.label, n: fmtCredits(ctx.parsed.y) }),
                            footer: items => {
                                const month = MONTHLY[items[0]?.dataIndex] || null;
                                const total = items.reduce((sum, item) => sum + item.parsed.y, 0);
                                const lines = [fill(<?= tj('Total : {n} crédits — valorisation {montant}') ?>, { n: fmtCredits(total), montant: fmtEur(total * CREDIT_VALUE_EUR) })];
                                if (month) lines.push(fill(<?= tj('Coût réel IA : {montant}') ?>, { montant: fmtEur(Number(month.cost || 0)) }));
                                return lines;
                            }
                        }
                    }
                }
            }
        });
    }

    // ---- Donuts « Répartition » (Chart.js) + légendes HTML custom
    function renderDonut(canvasId, legendId, rows) {
        const canvas = document.getElementById(canvasId);
        const legend = document.getElementById(legendId);
        if (!canvas || !legend) return;

        const total = rows.reduce((sum, row) => sum + row.value, 0);
        if (total <= 0 || typeof Chart === 'undefined') {
            legend.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">' + escapeHtml(<?= tj('Aucune donnée') ?>) + '</div>';
            canvas.style.display = 'none';
            return;
        }

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: rows.map(row => row.label),
                datasets: [{
                    data: rows.map(row => row.value),
                    backgroundColor: rows.map(row => row.color),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: false,
                cutout: '55%',
                plugins: { legend: { display: false } }
            }
        });

        // Légende custom avec pourcentages précis (dernier = 100 - somme des autres).
        legend.innerHTML = '';
        let sumPct = 0;
        rows.forEach((row, index) => {
            const isLast = index === rows.length - 1;
            const pctValue = isLast ? (100 - sumPct) : (row.value / total * 100);
            const pct = pctValue.toLocaleString(NUM_LOCALE, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            if (!isLast) sumPct += Math.round(pctValue * 10) / 10;

            const item = document.createElement('div');
            item.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px';
            const avatarHtml = row.avatar
                ? `<img src="${escapeHtml(row.avatar)}" alt="" style="width:18px;height:18px;border-radius:999px;object-fit:cover;flex-shrink:0">`
                : '';
            item.innerHTML = `<span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${row.color};flex-shrink:0"></span>
                ${avatarHtml}
                <span style="font-weight:600">${escapeHtml(row.label)}</span>
                <span style="color:var(--text-muted)">${escapeHtml(pct)}% — ${escapeHtml(row.extra)}</span>`;
            legend.appendChild(item);
        });
    }

    // Les trois répartitions se lisent en CRÉDITS (la grandeur que tout le
    // monde partage) ; tokens et opérations restent en complément.
    renderDonut('llm-pie', 'llm-legend', providerData.filter(row => Number(row.credits || 0) > 0).map((row, index) => ({
        label: providerLabels[row.llm_provider] || row.llm_provider,
        value: Number(row.credits || 0),
        color: providerColors[row.llm_provider] || fallbackColors[index % fallbackColors.length],
        extra: fill(<?= tj('{credits} crédits · {tokens} tokens ({ops} op.)') ?>, { credits: fmtCredits(row.credits), tokens: fmtNum(row.total_tokens), ops: fmtNum(row.requests) })
    })));

    renderDonut('profile-pie', 'profile-legend', profileData.filter(row => Number(row.credits || 0) > 0).map((row, index) => ({
        label: row.profile_name || <?= tj('Sans assistant') ?>,
        value: Number(row.credits || 0),
        color: row.profile_color || fallbackColors[index % fallbackColors.length],
        avatar: (() => {
            const raw = String(row.profile_avatar || '').trim();
            if (!raw) return '';
            const file = raw.split('/').pop();
            return file ? `/assets/img/profils/${encodeURIComponent(file)}` : '';
        })(),
        extra: fill(<?= tj('{credits} crédits ({ops} op.)') ?>, { credits: fmtCredits(row.credits), ops: fmtNum(row.requests) })
    })));

    renderDonut('tool-pie', 'tool-legend', toolData.filter(row => Number(row.credits || 0) > 0 || Number(row.uses || 0) > 0).map((row, index) => ({
        label: `${row.tool_name}${row.tool_type ? ' (' + (toolTypeLabels[row.tool_type] || row.tool_type) + ')' : ''}`,
        value: Number(row.credits || 0) > 0 ? Number(row.credits || 0) : 0,
        color: toolTypeColors[row.tool_type] || fallbackColors[index % fallbackColors.length],
        extra: fill(<?= tj('{credits} crédits · {n} utilisations') ?>, { credits: fmtCredits(row.credits), n: fmtNum(row.uses) })
    })));

    // ---- Lignes dépliables (détail mensuel par utilisateur)
    document.querySelectorAll('.usage-user-row').forEach(row => {
        row.addEventListener('click', () => {
            const key = row.dataset.userKey;
            const detail = document.querySelector(`tr.usage-detail-row[data-detail-for="${CSS.escape(key)}"]`);
            if (!detail) return;
            detail.hidden = !detail.hidden;
            row.classList.toggle('is-open', !detail.hidden);
        });
    });

    // ---- Tri client (les lignes de détail suivent leur ligne mère après tri)
    function initSortableTable(table) {
        const headers = Array.from(table.querySelectorAll('thead th[data-sort-type]'));
        const tbody = table.querySelector('tbody');
        if (!tbody || headers.length === 0) return;

        const parseValue = (raw, type) => {
            const value = String(raw ?? '').trim();
            if (type === 'number') {
                const num = Number(value.replace(/[^0-9.-]+/g, ''));
                return Number.isFinite(num) ? num : 0;
            }
            if (type === 'date') {
                const ts = Date.parse(value);
                return Number.isFinite(ts) ? ts : 0;
            }
            return value.toLowerCase();
        };

        const updateHeaderState = (activeIndex, direction) => {
            headers.forEach((header, index) => {
                header.classList.remove('is-sort-asc', 'is-sort-desc');
                if (index === activeIndex) {
                    header.classList.add(direction === 'asc' ? 'is-sort-asc' : 'is-sort-desc');
                }
            });
        };

        const sortBy = (index, direction) => {
            const type = headers[index].dataset.sortType || 'string';
            // :scope > tr : ne trier que les lignes directes du tbody, jamais
            // celles des sous-tables imbriquées (détail mensuel par utilisateur).
            const rows = Array.from(tbody.querySelectorAll(':scope > tr:not(.usage-empty-row):not(.usage-detail-row)'));
            rows.sort((a, b) => {
                const aCell = a.children[index];
                const bCell = b.children[index];
                const aVal = parseValue(aCell ? (aCell.dataset.sortValue ?? aCell.textContent) : '', type);
                const bVal = parseValue(bCell ? (bCell.dataset.sortValue ?? bCell.textContent) : '', type);
                if (aVal < bVal) return direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return direction === 'asc' ? 1 : -1;
                return 0;
            });
            rows.forEach(row => {
                tbody.appendChild(row);
                const key = row.dataset.userKey;
                if (key) {
                    const detail = tbody.querySelector(`:scope > tr.usage-detail-row[data-detail-for="${CSS.escape(key)}"]`);
                    if (detail) tbody.appendChild(detail);
                }
            });
            updateHeaderState(index, direction);
        };

        headers.forEach((header, index) => {
            header.addEventListener('click', () => {
                const isAsc = header.classList.contains('is-sort-asc');
                sortBy(index, isAsc ? 'desc' : 'asc');
            });
        });

        const defaultSortCol = Number(table.dataset.defaultSortCol);
        const defaultSortDir = (table.dataset.defaultSortDir || 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';
        if (Number.isInteger(defaultSortCol) && defaultSortCol >= 0 && defaultSortCol < headers.length) {
            sortBy(defaultSortCol, defaultSortDir);
        }
    }
    document.querySelectorAll('table.sortable').forEach(initSortableTable);
})();
</script>

<style>
.usage-tab {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    font-size: 13px;
    padding: 6px 16px;
    cursor: pointer;
    transition: all .15s;
}
.usage-tab:hover { color: var(--text); border-color: var(--accent); }
.usage-tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.usage-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.usage-chip { margin-right: 4px; margin-bottom: 4px; }
.usage-breakdown-block { display: flex; flex-direction: column; gap: 6px; }
.usage-breakdown-line { display: flex; align-items: flex-start; gap: 8px; }
.usage-breakdown-label { width: 52px; flex-shrink: 0; font-size: 12px; color: var(--text-muted); font-weight: 600; }
.usage-breakdown-values { display: flex; flex-wrap: wrap; gap: 4px; }
.usage-pies-row {
    margin-bottom: 16px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.usage-pie-card { min-width: 0; }
.usage-user-row { cursor: pointer; }
.usage-user-row:hover td { background: color-mix(in srgb, var(--accent) 6%, transparent); }
.usage-chevron { display: inline-block; transition: transform .15s; color: var(--text-muted); }
.usage-user-row.is-open .usage-chevron { transform: rotate(90deg); }
.usage-detail-row > td {
    background: var(--bg-surface);
    padding: 12px 16px;
}
.usage-user-months { width: 100%; border-collapse: collapse; }
.usage-user-months th, .usage-user-months td {
    font-size: 12px;
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
    text-align: left;
}
.usage-user-months th { color: var(--text-muted); font-weight: 600; }
.usage-user-months tr:last-child td { border-bottom: none; }
#usage-export-csv.is-disabled, #usage-export-pdf.is-disabled {
    opacity: .45;
    cursor: not-allowed;
}
table.sortable thead th[data-sort-type] {
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}
table.sortable thead th[data-sort-type]::after {
    content: '↕';
    opacity: .35;
    margin-left: 6px;
    font-size: 11px;
}
table.sortable thead th.is-sort-asc::after { content: '↑'; opacity: .9; }
table.sortable thead th.is-sort-desc::after { content: '↓'; opacity: .9; }
@media (max-width: 1100px) {
    .usage-pies-row { grid-template-columns: 1fr; }
}

.usage-category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(420px, 100%), 1fr));
    gap: 12px;
    align-items: start;
}
.usage-category-card { border-top: 3px solid var(--cat-color, var(--accent)); }
.usage-category-card.is-empty { opacity: .6; }
.usage-category-head { display: flex; gap: 10px; align-items: flex-start; }
.usage-category-dot { width: 12px; height: 12px; border-radius: 3px; background: var(--cat-color, var(--accent)); flex-shrink: 0; margin-top: 4px; }
.usage-category-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
.usage-category-kpis > div { display: flex; flex-direction: column; gap: 2px; }
.usage-category-kpis strong { font-size: 15px; }
.usage-category-kpis span { font-size: 11px; color: var(--text-muted); }
@media (max-width: 640px) {
    .usage-category-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
<script src="/assets/js/period-picker.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/period-picker.js') ?: 1) ?>"></script>
</body>
</html>
