<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Tarifs & crédits') ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <style>
        .credits-header p { font-size: 13px; color: var(--text-muted); margin-top: 6px; }
        .credits-table td, .credits-table th { vertical-align: top; }
        .credits-table tr.credits-row-type-llm_text > td {
            background: color-mix(in srgb, #3b82f6 8%, transparent);
        }
        .credits-table tr.credits-row-type-image > td {
            background: color-mix(in srgb, #f59e0b 9%, transparent);
        }
        .credits-table tr.credits-row-type-video > td {
            background: color-mix(in srgb, #ef4444 9%, transparent);
        }
        .credits-table tr.credits-row-type-audio > td {
            background: color-mix(in srgb, #14b8a6 8%, transparent);
        }
        .credits-table tr.credits-row-type-embedding > td {
            background: color-mix(in srgb, #10b981 8%, transparent);
        }
        .credits-table tr.credits-row-type-web_search > td {
            background: color-mix(in srgb, #0ea5e9 8%, transparent);
        }
        .credits-table tr.credits-row-type-other > td {
            background: color-mix(in srgb, #64748b 8%, transparent);
        }
        .credits-table tr.credits-row-active > td {
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 45%, transparent);
        }
        .credits-inline-detail-row td {
            background: color-mix(in srgb, var(--bg-surface) 88%, transparent);
            border-top: 0;
        }
        .credits-inline-detail {
            padding: 10px;
            border: 1px dashed var(--border);
            border-radius: var(--radius-sm);
            background: color-mix(in srgb, var(--bg-surface) 90%, transparent);
        }
        .credits-service-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.35;
        }
        .credits-service-cell {
            min-width: 240px;
            max-width: 340px;
            white-space: normal;
        }
        .credits-service-name {
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            margin: 0;
        }
        .credits-table input[type="text"],
        .credits-table input[type="number"],
        .credits-table select {
            min-width: 110px;
            font-size: 12px;
            padding: 6px 8px;
        }
        .credits-table textarea {
            min-width: 180px;
            min-height: 32px;
            max-height: 80px;
            resize: vertical;
            font-size: 12px;
            padding: 6px 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text);
            width: 100%;
        }
        .credits-readonly-type {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border: 1px dashed var(--border);
            border-radius: var(--radius-sm);
            background: color-mix(in srgb, var(--bg-surface) 90%, transparent);
            color: var(--text-muted);
            font-size: 12px;
        }
        .credits-result-strong { color: var(--accent); font-weight: 700; font-size: 13px; }
        .credits-muted { color: var(--text-muted); font-size: 11px; margin-top: 2px; }
        .credits-cost-source.is-none { color: #f59e0b; }
        .credits-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .credits-actions-left,
        .credits-actions-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .credits-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border);
            background: var(--bg-surface);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .credits-kpis {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .credits-kpi {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 10px;
            background: var(--bg-surface);
        }
        .credits-kpi strong { display: block; font-size: 16px; color: var(--text); line-height: 1.2; }
        .credits-kpi span { font-size: 11px; color: var(--text-muted); }
        .credits-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
        .credits-list li {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 10px;
            background: color-mix(in srgb, var(--bg-surface) 80%, transparent);
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .credits-detail-line { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .credits-detail-line strong { color: var(--text); font-weight: 600; }
        .credits-sim-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .credits-sim-output {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px;
            background: var(--bg-surface);
            margin-top: 10px;
        }
        .credits-mini-btn {
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            padding: 4px 8px;
            cursor: pointer;
            font-size: 11px;
        }
        .credits-mini-btn:hover { color: var(--text); border-color: var(--accent); }
        .credits-sort-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 600;
            padding: 0;
            cursor: pointer;
        }
        .credits-sort-btn:hover { color: var(--accent); }
        .credits-sort-btn.active { color: var(--accent); }
        .credits-sort-indicator {
            font-size: 11px;
            color: var(--text-muted);
        }
        .credits-sort-btn.active .credits-sort-indicator {
            color: var(--accent);
        }
        .credits-modal {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(2, 6, 23, .55);
            padding: 16px;
        }
        .credits-modal.open { display: flex; }
        .credits-modal-dialog {
            width: min(960px, 100%);
            max-height: calc(92vh / var(--ui-zoom, 1));
            overflow: auto;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 20px 45px rgba(2, 6, 23, .28);
            padding: 16px;
        }
        .credits-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .credits-modal-close {
            border: 1px solid var(--border);
            border-radius: 999px;
            background: transparent;
            color: var(--text-muted);
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .credits-modal-close:hover {
            border-color: var(--accent);
            color: var(--text);
        }
        @media (max-width: 1260px) {
            .credits-modal-dialog {
                width: min(1000px, 100%);
                max-height: calc(95vh / var(--ui-zoom, 1));
            }
        }
        @media (max-width: 900px) {
            .credits-kpis,
            .credits-sim-grid { grid-template-columns: 1fr; }
        }

        /* Bandeau « recherche Internet en cours » — volontairement NON bloquant :
           le travail vit côté serveur, quitter l'écran ne l'annule pas. */
        .price-job-banner {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            margin-bottom: 12px;
            border: 1px solid var(--border, rgba(127,127,127,.28));
            border-left: 3px solid var(--accent);
            border-radius: 10px;
            background: var(--bg-secondary, rgba(127,127,127,.06));
        }
        .price-job-banner[hidden] { display: none; }
        .price-job-text { flex: 1; min-width: 0; }
        .price-job-title {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
        }
        .price-job-sub {
            margin: 2px 0 0;
            font-size: 12px;
            color: var(--text-muted, #666);
            line-height: 1.45;
        }
        .refresh-spinner {
            width: 28px;
            height: 28px;
            flex: 0 0 auto;
            position: relative;
        }
        .refresh-spinner::before,
        .refresh-spinner::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 4px solid transparent;
        }
        .refresh-spinner::before {
            border-top-color: var(--accent);
            border-right-color: var(--accent);
            animation: refresh-spin 1s linear infinite;
        }
        .refresh-spinner::after {
            inset: 6px;
            border-top-color: var(--accent);
            opacity: .4;
            animation: refresh-spin 1.4s linear reverse infinite;
        }
        @keyframes refresh-spin {
            to { transform: rotate(360deg); }
        }
        .price-job-dots::after {
            content: '';
            display: inline-block;
            width: 1.2em;
            text-align: left;
            animation: refresh-dots 1.4s steps(4, end) infinite;
        }
        @keyframes refresh-dots {
            0%   { content: ''; }
            25%  { content: '.'; }
            50%  { content: '..'; }
            75%  { content: '...'; }
            100% { content: ''; }
        }
    </style>
</head>
<body class="admin-page">
<?php $page = 'credits'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
$bootstrapJson = json_encode(
    $bootstrap ?? [],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($bootstrapJson)) {
    $bootstrapJson = '{}';
}
?>

<div class="admin-content">
    <div class="credits-header">
        <h1><?= te('Tarifs & crédits') ?></h1>
        <p><?= te('Cette section permet de définir la conversion entre le coût réel des services IA et les crédits consommés par les utilisateurs.') ?></p>
    </div>

    <?php if ($success ?? false): ?>
        <div class="alert alert-success" style="margin-bottom:12px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error ?? false): ?>
        <div class="alert alert-error" style="margin-bottom:12px"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div id="creditsClientError" class="alert alert-error" style="display:none;margin-bottom:12px"></div>
    <div id="creditsPriceFlash" class="alert alert-success" style="display:none;margin-bottom:12px"></div>

    <?php /* Bandeau de suivi, hors du formulaire : la recherche vit côté
             serveur (job asynchrone), l'écran n'en est que le spectateur.
             Le bloquer derrière un voile ferait croire qu'en partir annule
             le travail. */ ?>
    <div id="priceJobBanner" class="price-job-banner" role="status" aria-live="polite" hidden>
        <div class="refresh-spinner" aria-hidden="true"></div>
        <div class="price-job-text">
            <p class="price-job-title" id="priceJobTitle"><?= te('Recherche des coûts sur Internet') ?><span class="price-job-dots"></span></p>
            <p class="price-job-sub"><?= te('Le traitement se poursuit côté serveur : vous pouvez changer d\'écran ou fermer cette page, les prix trouvés seront enregistrés.') ?></p>
        </div>
        <button type="button" id="priceJobCancelBtn" class="btn-secondary" style="font-size:12px;padding:6px 12px"><?= te('Arrêter') ?></button>
    </div>

    <?php /* L'état de cet écran vit dans un objet JavaScript, pas dans des
             champs nommés : `renderDirtyState()` réclame l'enregistrement et
             `mia:autosave-before` sérialise l'état dans le champ caché. */ ?>
    <form id="creditsForm" method="POST" action="/admin/credits" data-autosave>
        <?= Csrf::field() ?>
        <input type="hidden" id="creditsConfigJson" name="credits_config_json" value="">

        <div class="card" style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div>
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Paramètres généraux') ?></h3>
                    <p style="margin:0;font-size:12px;color:var(--text-muted)"><?= te('Règles globales de conversion coût réel → crédits.') ?></p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                    <span class="credits-pill" id="creditsDirtyPill"><?= te('Tout est enregistré') ?></span>
                    <button id="refreshPricesBtn" type="button" class="btn-primary" style="font-size:12px;padding:7px 12px">
                        <?= te('Mettre à jour coûts Internet (USD)') ?>
                    </button>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:14px">
                <div class="form-group" style="margin:0">
                    <label id="creditValueLabel"><?= te('Valeur d\'un crédit ({symbol})', ['symbol' => '€']) ?></label>
                    <input id="creditValueInput" type="number" class="form-input" step="0.000001" min="0.000001">
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('Coefficient global') ?></label>
                    <input id="marginMultiplierInput" type="number" class="form-input" step="0.0001" min="0.0001">
                </div>
            </div>
            <!-- Pont avec le budget IA (Coûts → Budget) : même formule que
                 CreditService::creditsPerRealEur(), recalculée à la frappe. -->
            <p id="creditsRealEurRate" style="margin:10px 0 0;font-size:12px;color:var(--text-muted)">
                <?= str_replace('{rate}', '<strong data-rate>—</strong>', te('1 € de coût réel ≈ {rate} crédits (coefficient ÷ valeur du crédit, hors frais fixes, arrondi et minimum)')) ?>
                · <?= te('Plafond mensuel :') ?> <a href="/admin/budget"><?= te('Coûts → Budget') ?></a>
            </p>
            <details id="advancedParams">
                <summary><?= te('Paramètres avancés') ?></summary>
                <div class="advanced-params-grid">
                    <div class="form-group" style="margin:0">
                        <label id="fixedFeeLabel"><?= te('Frais fixes par opération ({symbol})', ['symbol' => '€']) ?></label>
                        <input id="fixedFeeInput" type="number" class="form-input" step="0.0001" min="0">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Mode d\'arrondi') ?></label>
                        <select id="roundingModeInput" class="form-select">
                            <option value="ceil"><?= te('Entier supérieur') ?></option>
                            <option value="nearest"><?= te('Entier le plus proche') ?></option>
                            <option value="floor"><?= te('Entier inférieur') ?></option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Minimum crédits / opération') ?></label>
                        <input id="minCreditsInput" type="number" class="form-input" step="1" min="0">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label id="fxRateLabel"><?= te('Taux 1 EUR → USD') ?></label>
                        <input id="fxRateInput" type="number" class="form-input" step="0.0001" min="0.0001">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Devise affichée') ?></label>
                        <select id="currencyInput" class="form-select">
                            <option value="EUR">EUR</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                </div>
            </details>
        </div>

        <div class="view-toggle-bar">
            <button type="button" class="btn-view-toggle active" data-view="services" id="btnViewServices"><?= te('Vue services') ?></button>
            <button type="button" class="btn-view-toggle" data-view="models" id="btnViewModels"><?= te('Tarif par modèle') ?></button>
            <button type="button" class="btn-view-toggle" data-view="tools" id="btnViewTools"><?= te('Tarif par outil') ?></button>
        </div>
        <div id="modelView" style="display:none;margin-bottom:12px"></div>
        <div id="toolView" style="display:none;margin-bottom:12px"></div>

        <div class="card table-wrap" style="margin:0">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                <div>
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Services IA') ?></h3>
                    <p style="margin:0;font-size:12px;color:var(--text-muted)"><?= te('Configurez les coûts réels et coefficients par service. Cliquez sur une ligne pour afficher son détail de calcul.') ?></p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <button id="openSimulatorBtn" type="button" class="btn-secondary" style="font-size:12px;padding:6px 12px"><?= te('Ouvrir simulateur') ?></button>
                    <button id="openSummaryBtn" type="button" class="btn-secondary" style="font-size:12px;padding:6px 12px"><?= te('Ouvrir synthèse') ?></button>
                </div>
            </div>

            <table class="credits-table">
                <thead id="servicesTableHead">
                    <tr>
                        <th>
                            <button type="button" class="credits-sort-btn" data-sort-key="name">
                                <?= te('Service') ?> <span class="credits-sort-indicator" data-sort-indicator="name">↕</span>
                            </button>
                        </th>
                        <th><?= te('Unité') ?></th>
                        <th id="realCostHeader">
                            <button type="button" class="credits-sort-btn" data-sort-key="real_cost_eur">
                                <span id="realCostHeaderLabel"><?= te('Coût réel ({currency})', ['currency' => 'EUR']) ?></span> <span class="credits-sort-indicator" data-sort-indicator="real_cost_eur">↕</span>
                            </button>
                        </th>
                        <th><?= te('Coeff. spécifique') ?></th>
                        <th>
                            <button type="button" class="credits-sort-btn" data-sort-key="final_credits">
                                <?= te('Crédits finaux') ?> <span class="credits-sort-indicator" data-sort-indicator="final_credits">↕</span>
                            </button>
                        </th>
                        <th><?= te('Commentaire interne') ?></th>
                        <th><?= te('Actions') ?></th>
                    </tr>
                </thead>
                <tbody id="servicesTableBody">
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--text-muted);padding:18px"><?= te('Chargement…') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-top:16px">
            <div class="credits-actions">
                <div class="credits-actions-left">
                    <span class="credits-pill" id="saveStateLabel"><?= te('Configuration synchronisée') ?></span>
                </div>
                <div class="credits-actions-right">
                    <?php /* « Annuler » et « Réinitialiser » restauraient tous
                             deux la dernière configuration enregistrée. Avec
                             l'enregistrement automatique, cette référence EST
                             l'état affiché : les deux boutons ne pouvaient plus
                             rien restaurer. */ ?>
                    <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                    <button id="exportBtn" type="button" class="btn-secondary"><?= te('Exporter la configuration') ?></button>
                    <button id="exportPdfBtn" type="button" class="btn-secondary"><?= te('Exporter PDF (avec simulations)') ?></button>
                </div>
            </div>
        </div>
    </form>
</div>

<div id="simulatorModal" class="credits-modal" aria-hidden="true">
    <div class="credits-modal-dialog">
        <div class="credits-modal-header">
            <div>
                <h3 style="margin:0;font-size:15px"><?= te('Simulateur') ?></h3>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0"><?= te('Testez un usage concret avec vos paramètres courants.') ?></p>
            </div>
            <button id="closeSimulatorModalBtn" type="button" class="credits-modal-close" aria-label="<?= te('Fermer') ?>">×</button>
        </div>

        <div class="form-group" style="margin:0 0 10px">
            <label><?= te('Service') ?></label>
            <select id="simServiceSelect" class="form-select"></select>
        </div>

        <div class="credits-sim-grid">
            <div class="form-group" style="margin:0">
                <label><?= te('Quantité (unités)') ?></label>
                <input id="simQuantityInput" type="number" class="form-input" min="0.000001" step="0.0001" value="1">
            </div>
            <div class="form-group" id="simTokensGroup" style="margin:0;display:none">
                <label><?= te('Tokens bruts (optionnel)') ?></label>
                <input id="simTokensInput" type="number" class="form-input" min="0" step="1" placeholder="<?= te('ex: 25000') ?>">
            </div>
        </div>

        <div id="simulatorOutput" class="credits-sim-output"></div>
    </div>
</div>

<div id="summaryModal" class="credits-modal" aria-hidden="true">
    <div class="credits-modal-dialog">
        <div class="credits-modal-header">
            <div>
                <h3 style="margin:0;font-size:15px"><?= te('Synthèse') ?></h3>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0"><?= te('Lecture rapide du paramétrage actuel.') ?></p>
            </div>
            <button id="closeSummaryModalBtn" type="button" class="credits-modal-close" aria-label="<?= te('Fermer') ?>">×</button>
        </div>
        <div id="summaryOutput"></div>
    </div>
</div>

<script type="application/json" id="creditsBootstrap"><?= $bootstrapJson ?></script>
<script>
(function () {
    // Langue de la page (window.I18N, posé par head-meta) : montants, nombres,
    // dates et tris suivent la langue choisie.
    const EN = !!(window.I18N && window.I18N.lang === 'en');
    const PAGE_LANG = EN ? 'en' : 'fr';
    const NUM_LOCALE = EN ? 'en-US' : 'fr-FR';
    const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));
    // Pluriel : le français l'emploie au-delà de 1, l'anglais dès que n ≠ 1.
    const plural = (n, one, many, vars) => fill((EN ? n !== 1 : n > 1) ? many : one, Object.assign({ n: n }, vars || {}));

    // Libellés de l'écran, traduits côté serveur. Les phrases gardent leurs
    // substituants {nom}, remplis par fill().
    const L = {
        creditOne: <?= tj('{n} crédit') ?>,
        creditMany: <?= tj('{n} crédits') ?>,
        creditValue: <?= tj("Valeur d'un crédit ({symbol})") ?>,
        fixedFee: <?= tj('Frais fixes par opération ({symbol})') ?>,
        fxRate: <?= tj('Taux 1 EUR → USD') ?>,
        realCost: <?= tj('Coût réel ({currency})') ?>,
        noService: <?= tj('Aucun service configuré.') ?>,
        providerCategory: <?= tj('Fournisseur: {provider} · Catégorie: {category}') ?>,
        internalComment: <?= tj('Commentaire interne') ?>,
        delete: <?= tj('Supprimer') ?>,
        costCatalog: <?= tj('Tarif public appliqué') ?>,
        costDerived: <?= tj('Dérivé du prix de référence') ?>,
        costNone: <?= tj('Non tarifé : compté au minimum') ?>,
        model: <?= tj('Modèle') ?>,
        costStars: <?= tj('Coût €/5') ?>,
        costInput: <?= tj('Coût input (€/1M tok.)') ?>,
        creditsInput: <?= tj('Crédits/tok. input') ?>,
        costOutput: <?= tj('Coût output (€/1M tok.)') ?>,
        creditsOutput: <?= tj('Crédits/tok. output') ?>,
        noModel: <?= tj('Aucun modèle LLM configuré.') ?>,
        hintQuality: <?= tj('qualité {value}') ?>,
        scenarioImage: <?= tj('1 image standard') ?>,
        scenarioVideo: <?= tj('{n} s vidéo standard') ?>,
        scenarioAudio: <?= tj('{n} min audio standard') ?>,
        scenarioHtml: <?= tj('1 page HTML standard') ?>,
        scenarioOther: <?= tj('1 génération standard') ?>,
        noTool: <?= tj('Aucun outil actif configuré pour cette vue.') ?>,
        toolIntro: <?= tj("Résumé standard par outil actif. L'échelle € est relative uniquement entre les outils.") ?>,
        tool: <?= tj('Outil') ?>,
        type: <?= tj('Type') ?>,
        providerModel: <?= tj('Provider / modèle') ?>,
        standardGeneration: <?= tj('Génération standard') ?>,
        relativeCost: <?= tj('Coût relatif') ?>,
        pedago: <?= tj('{cost} → coeff. {coeff} → {credits}') ?>,
        roundUp: <?= tj('arrondi supérieur') ?>,
        roundDown: <?= tj('arrondi inférieur') ?>,
        roundStandard: <?= tj('arrondi standard') ?>,
        detailProvider: <?= tj('Fournisseur :') ?>,
        detailCategory: <?= tj('Catégorie :') ?>,
        detailUnit: <?= tj('Unité :') ?>,
        detailRealCost: <?= tj('Coût réel :') ?>,
        detailAfterCoeffs: <?= tj('Après coefficients :') ?>,
        detailAfterFixed: <?= tj('Après frais fixes :') ?>,
        detailRaw: <?= tj('Conversion brute :') ?>,
        detailResult: <?= tj('Résultat :') ?>,
        detailChain: <?= tj('{cost} → coefficient {coeff} → {after} → {rounding} → {credits}') ?>,
        noServiceOption: <?= tj('Aucun service') ?>,
        selectService: <?= tj('Sélectionnez un service pour simuler.') ?>,
        tokensQuantity: <?= tj('{tokens} tokens ({units} {unit})') ?>,
        estimatedCost: <?= tj('Coût réel estimé :') ?>,
        estimatedCredits: <?= tj('Crédits estimés :') ?>,
        simDetail: <?= tj('Détail: ({cost} × coeffs) + frais fixes = {total} → {raw} → {credits}') ?>,
        noActiveService: <?= tj('Aucun service actif.') ?>,
        activeServices: <?= tj('Services actifs') ?>,
        averageCredits: <?= tj('Crédits moyens') ?>,
        cheapest: <?= tj('Moins coûteux: {name}') ?>,
        mostExpensive: <?= tj('Plus coûteux: {name}') ?>,
        averageByCategory: <?= tj('Coût moyen par catégorie') ?>,
        topConsumers: <?= tj('Top services consommateurs') ?>,
        pdfDetail: <?= tj('({cost} × {coeff}) + frais = {total} → {raw} → {credits}') ?>,
        sim25kInput: <?= tj('Simulation: 25 000 tokens input') ?>,
        sim12kOutput: <?= tj('Simulation: 12 000 tokens output') ?>,
        sim3Images: <?= tj('Simulation: 3 images générées') ?>,
        sim10sVideo: <?= tj('Simulation: 10 secondes vidéo') ?>,
        simExtra: <?= tj('Simulation complémentaire') ?>,
        roundingUp: <?= tj('Entier supérieur') ?>,
        roundingDown: <?= tj('Entier inférieur') ?>,
        roundingNearest: <?= tj('Entier le plus proche') ?>,
        formula: <?= tj('Crédits = ((coût réel × coeff. global × coeff. spécifique) + frais fixes) / valeur crédit') ?>,
        publicTariff: <?= tj('(tarif public)') ?>,
        active: <?= tj('Actif') ?>,
        inactive: <?= tj('Inactif') ?>,
        noSimulation: <?= tj('Aucune simulation disponible.') ?>,
        pdfFileTitle: <?= tj('gestion-credits-{date}') ?>,
        pageTitle: <?= tj('Tarifs & crédits') ?>,
        pdfGenerated: <?= tj('Export PDF généré le {date}.') ?>,
        chipCurrency: <?= tj('Devise: {value}') ?>,
        chipCreditValue: <?= tj('Valeur crédit: {value}') ?>,
        chipGlobalCoeff: <?= tj('Coeff. global: {value}') ?>,
        chipFixedFee: <?= tj('Frais fixes: {value}') ?>,
        chipRounding: <?= tj('Arrondi: {value}') ?>,
        chipMinCredits: <?= tj('Min crédits/op: {value}') ?>,
        chipFxRate: <?= tj('Taux EUR→USD: {value}') ?>,
        formulaLabel: <?= tj('Formule:') ?>,
        aiServices: <?= tj('Services IA') ?>,
        aiServicesIntro: <?= tj('Vue complète des services configurés et du coût crédit par unité.') ?>,
        service: <?= tj('Service') ?>,
        unit: <?= tj('Unité') ?>,
        realCostPlain: <?= tj('Coût réel') ?>,
        specificCoeff: <?= tj('Coeff. spécifique') ?>,
        creditsPerUnit: <?= tj('Crédits / unité') ?>,
        status: <?= tj('Statut') ?>,
        simulationsTitle: <?= tj('Simulations (3-4 cas concrets)') ?>,
        simulationsIntro: <?= tj("Exemples pour visualiser l'impact métier des crédits.") ?>,
        scenario: <?= tj('Scénario') ?>,
        quantity: <?= tj('Quantité') ?>,
        credits: <?= tj('Crédits') ?>,
        calcDetail: <?= tj('Détail du calcul') ?>,
        popupBlocked: <?= tj("Impossible d'ouvrir la fenêtre d'export PDF (popup bloquée).") ?>,
        saving: <?= tj('Enregistrement en cours…') ?>,
        allSaved: <?= tj('Tout est enregistré') ?>,
        sendingSoon: <?= tj('Envoi imminent') ?>,
        configSynced: <?= tj('Configuration synchronisée') ?>,
        confirmDelete: <?= tj('Supprimer le service « {name} » ?') ?>,
        errCreditValue: <?= tj("La valeur d'un crédit doit être supérieure à 0.") ?>,
        errGlobalCoeff: <?= tj('Le coefficient global doit être supérieur à 0.') ?>,
        errFixedFee: <?= tj('Les frais fixes doivent être positifs ou nuls.') ?>,
        errFxRate: <?= tj('Le taux EUR → USD doit être supérieur à 0.') ?>,
        errMinCredits: <?= tj('Le minimum de crédits par opération doit être positif ou nul.') ?>,
        errName: <?= tj('Service #{n}: le nom est obligatoire.') ?>,
        errProvider: <?= tj('Service #{n}: le fournisseur est obligatoire.') ?>,
        errRealCost: <?= tj('Service #{n}: le coût réel doit être positif ou nul.') ?>,
        errSpecificCoeff: <?= tj('Service #{n}: le coefficient spécifique doit être supérieur à 0.') ?>,
        noServiceToUpdate: <?= tj('Aucun service IA à mettre à jour.') ?>,
        confirmRefresh: <?= tj('Rechercher les coûts réels sur Internet pour tous les services listés et appliquer les valeurs en USD ?') ?>,
        csrfMissing: <?= tj('Token CSRF manquant. Rechargez la page puis réessayez.') ?>,
        pricesUnavailable: <?= tj('Impossible de récupérer les prix via Internet.') ?>,
        unknownError: <?= tj('Erreur inconnue lors de la mise à jour.') ?>,
        searching: <?= tj('Recherche Internet en cours…') ?>,
        searchFailed: <?= tj('La recherche de prix a échoué.') ?>,
        trackingLost: <?= tj('Suivi de la recherche perdu. Elle se poursuit côté serveur : rechargez la page dans un moment.') ?>,
        refreshCancelled: <?= tj('Mise à jour des prix annulée.') ?>,
        noPriceFound: <?= tj('Aucun coût exploitable trouvé en ligne.') ?>,
        servicesWithoutPrice: <?= tj('Services sans prix : {n}.') ?>,
        updatedOne: <?= tj('{n} service mis à jour en USD via Internet') ?>,
        updatedMany: <?= tj('{n} services mis à jour en USD via Internet') ?>,
    };

    const CATEGORY_OPTIONS = [
        { value: 'llm_text', label: <?= tj('Texte') ?> },
        { value: 'embedding', label: 'Embeddings' },
        { value: 'web_search', label: <?= tj('Recherche web') ?> },
        { value: 'image', label: 'Image' },
        { value: 'video', label: <?= tj('Vidéo') ?> },
        { value: 'audio', label: 'Audio' },
        { value: 'other', label: <?= tj('Autre') ?> },
    ];
    // « Embeddings », « Image », « Audio », « 1M tokens », « 1K tokens » et
    // « Minute » s'écrivent pareil dans les deux langues.
    const UNIT_OPTIONS = [
        { value: '1m_input_tokens', label: <?= tj('1M tokens input') ?> },
        { value: '1m_output_tokens', label: <?= tj('1M tokens output') ?> },
        { value: '1m_tokens', label: '1M tokens' },
        { value: '1k_tokens', label: '1K tokens' },
        { value: 'image_generated', label: <?= tj('Image générée') ?> },
        { value: 'video_second', label: <?= tj('Seconde vidéo') ?> },
        { value: 'audio_minute', label: <?= tj('Minute audio') ?> },
        { value: 'api_call', label: <?= tj('Appel API') ?> },
        { value: 'request', label: <?= tj('Requête') ?> },
        { value: 'minute', label: 'Minute' },
        { value: 'second', label: <?= tj('Seconde') ?> },
        { value: 'other', label: <?= tj('Autre') ?> },
    ];
    // Unité telle que la lit la RECHERCHE DES PRIX (`billing_hint`) : une
    // donnée de la consigne du modèle, rédigée en français comme elle, qui ne
    // dépend donc pas de la langue de l'écran.
    const UNIT_HINTS = {
        '1m_input_tokens': '1M tokens input',
        '1m_output_tokens': '1M tokens output',
        '1m_tokens': '1M tokens',
        '1k_tokens': '1K tokens',
        image_generated: 'Image générée',
        video_second: 'Seconde vidéo',
        audio_minute: 'Minute audio',
        api_call: 'Appel API',
        request: 'Requête',
        minute: 'Minute',
        second: 'Seconde',
        other: 'Autre',
    };
    const ROUNDING_OPTIONS = ['ceil', 'nearest', 'floor'];
    const CURRENCY_OPTIONS = ['EUR', 'USD'];
    const TOKEN_UNITS = {
        '1m_input_tokens': 1000000,
        '1m_output_tokens': 1000000,
        '1m_tokens': 1000000,
        '1k_tokens': 1000,
    };

    const els = {
        form: document.getElementById('creditsForm'),
        hiddenConfig: document.getElementById('creditsConfigJson'),
        clientError: document.getElementById('creditsClientError'),
        dirtyPill: document.getElementById('creditsDirtyPill'),
        saveStateLabel: document.getElementById('saveStateLabel'),
        exportBtn: document.getElementById('exportBtn'),
        exportPdfBtn: document.getElementById('exportPdfBtn'),
        openSimulatorBtn: document.getElementById('openSimulatorBtn'),
        openSummaryBtn: document.getElementById('openSummaryBtn'),
        refreshPricesBtn: document.getElementById('refreshPricesBtn'),
        priceFlash: document.getElementById('creditsPriceFlash'),
        priceJobBanner: document.getElementById('priceJobBanner'),
        priceJobTitle: document.getElementById('priceJobTitle'),
        priceJobCancelBtn: document.getElementById('priceJobCancelBtn'),
        tableHead: document.getElementById('servicesTableHead'),
        tableBody: document.getElementById('servicesTableBody'),
        summary: document.getElementById('summaryOutput'),
        simulatorOutput: document.getElementById('simulatorOutput'),
        simServiceSelect: document.getElementById('simServiceSelect'),
        simQuantityInput: document.getElementById('simQuantityInput'),
        simTokensInput: document.getElementById('simTokensInput'),
        simTokensGroup: document.getElementById('simTokensGroup'),
        simulatorModal: document.getElementById('simulatorModal'),
        summaryModal: document.getElementById('summaryModal'),
        closeSimulatorModalBtn: document.getElementById('closeSimulatorModalBtn'),
        closeSummaryModalBtn: document.getElementById('closeSummaryModalBtn'),
        creditValueInput: document.getElementById('creditValueInput'),
        marginMultiplierInput: document.getElementById('marginMultiplierInput'),
        fixedFeeInput: document.getElementById('fixedFeeInput'),
        roundingModeInput: document.getElementById('roundingModeInput'),
        minCreditsInput: document.getElementById('minCreditsInput'),
        fxRateInput: document.getElementById('fxRateInput'),
        currencyInput: document.getElementById('currencyInput'),
        creditValueLabel: document.getElementById('creditValueLabel'),
        fixedFeeLabel: document.getElementById('fixedFeeLabel'),
        fxRateLabel: document.getElementById('fxRateLabel'),
        realCostHeader: document.getElementById('realCostHeaderLabel'),
    };

    function parseBootstrap() {
        const raw = document.getElementById('creditsBootstrap');
        if (!raw) return {};
        try {
            return JSON.parse(raw.textContent || '{}');
        } catch (_e) {
            return {};
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name=\"csrf-token\"]');
        return meta ? String(meta.getAttribute('content') || '') : '';
    }

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function parseNumber(value, fallback) {
        const normalized = String(value ?? '').trim().replace(',', '.');
        if (normalized === '') return fallback;
        const num = Number(normalized);
        return Number.isFinite(num) ? num : fallback;
    }

    function toFixedTrim(number, precision) {
        return Number(number).toFixed(precision).replace(/\.?0+$/, '');
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function unitLabel(unit) {
        const found = UNIT_OPTIONS.find((opt) => opt.value === unit);
        return found ? found.label : unit;
    }

    function unitHint(unit) {
        return Object.prototype.hasOwnProperty.call(UNIT_HINTS, unit) ? UNIT_HINTS[unit] : unit;
    }

    // `shown` : la valeur telle qu'affichée (décimales comprises), quand elle
    // diffère du nombre qui décide du pluriel.
    function creditsText(n, shown) {
        return plural(Number(n) || 0, L.creditOne, L.creditMany, { n: shown === undefined ? n : shown });
    }

    function categoryLabel(category) {
        const found = CATEGORY_OPTIONS.find((opt) => opt.value === category);
        return found ? found.label : category;
    }

    function roundCredits(value, mode) {
        if (!Number.isFinite(value)) return 0;
        if (mode === 'floor') return Math.floor(value);
        if (mode === 'nearest') return Math.round(value);
        return Math.ceil(value);
    }

    function normalizeService(service, index) {
        const id = String(service.id || generateId('svc', index));
        const type = CATEGORY_OPTIONS.some((o) => o.value === service.type) ? service.type : 'other';
        const unit = UNIT_OPTIONS.some((o) => o.value === service.unit) ? service.unit : 'request';
        return {
            id,
            name: String(service.name || 'Service IA').trim() || 'Service IA',
            provider: String(service.provider || 'N/A').trim() || 'N/A',
            type,
            unit,
            real_cost_eur: Math.max(0, parseNumber(service.real_cost_eur, 0)),
            multiplier: Math.max(0.0001, parseNumber(service.multiplier, 1)),
            active: !!service.active,
            catalog_key: String(service.catalog_key || '').trim(),
            internal_comment: String(service.internal_comment || '').trim(),
        };
    }

    // L'estampille « Maj Internet … » du commentaire interne est posée côté
    // serveur (`AdminController::stampPriceSourceComment`) : le job écrit la
    // configuration, la page n'applique plus les prix elle-même.
    function normalizeConfig(rawConfig) {
        const rawGlobal = (rawConfig && rawConfig.global) || {};
        const global = {
            credit_value_eur: Math.max(0.000001, parseNumber(rawGlobal.credit_value_eur, 0.001)),
            margin_multiplier: Math.max(0.0001, parseNumber(rawGlobal.margin_multiplier, 1.2)),
            fixed_fee_eur: Math.max(0, parseNumber(rawGlobal.fixed_fee_eur, 0)),
            rounding_mode: ROUNDING_OPTIONS.includes(rawGlobal.rounding_mode) ? rawGlobal.rounding_mode : 'ceil',
            min_credits_per_operation: Math.max(0, Math.floor(parseNumber(rawGlobal.min_credits_per_operation, 1))),
            fx_rate_eur_usd: Math.max(0.0001, parseNumber(rawGlobal.fx_rate_eur_usd, 1.08)),
            currency: CURRENCY_OPTIONS.includes(String(rawGlobal.currency || '').toUpperCase())
                ? String(rawGlobal.currency).toUpperCase()
                : 'EUR',
        };

        const services = Array.isArray(rawConfig && rawConfig.services)
            ? rawConfig.services.map((service, index) => normalizeService(service, index))
            : [];

        return {
            version: 1,
            global,
            services,
        };
    }

    function formatMoney(value) {
        const currency = state.global.currency || 'EUR';
        const converted = convertMoneyFromEur(value, currency);
        try {
            return new Intl.NumberFormat(NUM_LOCALE, {
                style: 'currency',
                currency,
                minimumFractionDigits: 2,
                maximumFractionDigits: 4,
            }).format(converted);
        } catch (_e) {
            return toFixedTrim(converted, 4) + ' ' + currency;
        }
    }

    function formatNumber(value, precision) {
        const fixed = Number(value).toFixed(precision);
        // Sans décimale, rien à retirer : « 25000 » restait « 25 ».
        return precision > 0 ? fixed.replace(/\.?0+$/, '') : fixed;
    }

    function generateId(prefix, index) {
        return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 6) + '-' + index;
    }

    function normalizeCurrencyCode(value) {
        const code = String(value || '').toUpperCase();
        return CURRENCY_OPTIONS.includes(code) ? code : 'EUR';
    }

    function currentFxRate() {
        return Math.max(0.0001, parseNumber(state.global.fx_rate_eur_usd, 1.08));
    }

    function convertMoneyFromEur(amountEur, currencyCode) {
        const eur = Math.max(0, Number(amountEur || 0));
        const code = normalizeCurrencyCode(currencyCode || state.global.currency);
        if (code === 'USD') {
            return eur * currentFxRate();
        }
        return eur;
    }

    function convertMoneyToEur(amount, currencyCode) {
        const value = Math.max(0, Number(amount || 0));
        const code = normalizeCurrencyCode(currencyCode || state.global.currency);
        if (code === 'USD') {
            return value / currentFxRate();
        }
        return value;
    }

    // Tarif PUBLIC qu'applique la facturation à une ligne sans prix
    // (CreditService::catalogUnitCost), par clé de catalogue, au prix de SON
    // unité. Sans lui, une ligne à 0 € s'affichait « 1 crédit » alors que
    // chaque message était facturé au tarif public : la page mentait.
    const CATALOG_COSTS = (function () {
        try {
            const raw = JSON.parse(document.getElementById('creditsBootstrap')?.textContent || '{}');
            return raw && typeof raw.catalog_costs === 'object' && raw.catalog_costs ? raw.catalog_costs : {};
        } catch (_e) {
            return {};
        }
    })();
    const TOKEN_UNIT_SIZE = { '1m_input_tokens': 1000000, '1m_output_tokens': 1000000, '1m_tokens': 1000000, '1k_tokens': 1000 };
    // Variantes d'un modèle sans tarif public : dérivées du prix de référence
    // renseigné, par les ratios de la facturation (CreditService).
    const LLM_VARIANT_RATIO = { input_cached: 0.10, input_cache_write: 1.25, output_reasoning: 1.0 };

    /**
     * Coût réel par unité de la ligne TEL QUE LA FACTURATION L'APPLIQUE :
     * le prix saisi, sinon le tarif public, sinon une variante dérivée du prix
     * de référence. `source` : own | catalog | derived | none (non tarifée).
     */
    function effectiveCost(service) {
        const own = Math.max(0, Number(service && service.real_cost_eur || 0));
        if (own > 0) return { eur: own, source: 'own' };
        const key = String(service && service.catalog_key || '');
        const cat = CATALOG_COSTS[key];
        if (cat && cat.eur !== null && Number.isFinite(Number(cat.eur))) {
            const from = TOKEN_UNIT_SIZE[cat.unit];
            const to = TOKEN_UNIT_SIZE[service.unit];
            const eur = from && to ? Number(cat.eur) * to / from : Number(cat.eur);
            return { eur: Math.max(0, eur), source: 'catalog' };
        }
        const m = /^llm:(.+):(input_cached|input_cache_write|output_reasoning)$/.exec(key);
        if (m) {
            const refKey = 'llm:' + m[1] + ':' + (m[2] === 'output_reasoning' ? 'output' : 'input');
            const ref = state.services.find((svc) => svc.catalog_key === refKey);
            const refCost = ref ? effectiveCost(ref) : null;
            if (refCost && refCost.source !== 'none' && refCost.eur > 0) {
                return { eur: refCost.eur * LLM_VARIANT_RATIO[m[2]], source: 'derived' };
            }
        }
        return { eur: 0, source: 'none' };
    }

    function computeCreditsForQuantity(service, quantityUnits) {
        const qty = Math.max(0, quantityUnits);
        const real = effectiveCost(service).eur * qty;
        const afterCoefficients = real * Math.max(0.0001, Number(state.global.margin_multiplier || 1)) * Math.max(0.0001, Number(service.multiplier || 1));
        const afterFixed = afterCoefficients + Math.max(0, Number(state.global.fixed_fee_eur || 0));
        const rawCredits = afterFixed / Math.max(0.000001, Number(state.global.credit_value_eur || 0.001));
        const roundedCredits = roundCredits(rawCredits, state.global.rounding_mode);
        const finalCredits = Math.max(roundedCredits, Math.max(0, Number(state.global.min_credits_per_operation || 0)));

        return {
            quantityUnits: qty,
            real,
            afterCoefficients,
            afterFixed,
            rawCredits,
            roundedCredits,
            finalCredits,
        };
    }

    function computePerUnit(service) {
        return computeCreditsForQuantity(service, 1);
    }

    function getServiceById(serviceId) {
        return state.services.find((service) => service.id === serviceId) || null;
    }

    function ensureSelectedService() {
        if (state.services.length === 0) {
            selectedServiceId = null;
            return;
        }
        if (!getServiceById(selectedServiceId)) {
            selectedServiceId = state.services[0].id;
        }
    }

    function getSimulationServicePool() {
        const active = state.services.filter((service) => service.active);
        return active.length > 0 ? active : state.services;
    }

    function ensureSimulationService() {
        const pool = getSimulationServicePool();
        if (pool.length === 0) {
            simulator.serviceId = '';
            return;
        }
        const exists = pool.some((service) => service.id === simulator.serviceId);
        if (!exists) {
            simulator.serviceId = pool[0].id;
        }
    }

    function optionsHtml(options, selectedValue) {
        return options
            .map((opt) => '<option value="' + escapeHtml(opt.value) + '"' + (opt.value === selectedValue ? ' selected' : '') + '>' + escapeHtml(opt.label) + '</option>')
            .join('');
    }

    function compareStrings(a, b) {
        return String(a || '').localeCompare(String(b || ''), PAGE_LANG, { sensitivity: 'base', numeric: true });
    }

    function getSortableServiceValue(service, key) {
        if (key === 'name') {
            return String(service.name || '');
        }
        if (key === 'real_cost_eur') {
            return effectiveCost(service).eur;
        }
        if (key === 'final_credits') {
            return Number(computePerUnit(service).finalCredits || 0);
        }
        return '';
    }

    function sortServicesForDisplay(services) {
        const key = sortState.key;
        if (!key) return services.slice();

        const direction = sortState.direction === 'desc' ? -1 : 1;
        return services.slice().sort((a, b) => {
            const va = getSortableServiceValue(a, key);
            const vb = getSortableServiceValue(b, key);
            if (typeof va === 'number' && typeof vb === 'number') {
                return (va - vb) * direction;
            }
            return compareStrings(va, vb) * direction;
        });
    }

    function renderSortIndicators() {
        const indicators = els.tableHead.querySelectorAll('[data-sort-indicator]');
        indicators.forEach((node) => {
            const key = String(node.getAttribute('data-sort-indicator') || '');
            if (sortState.key === key) {
                node.textContent = sortState.direction === 'desc' ? '↓' : '↑';
            } else {
                node.textContent = '↕';
            }
        });

        const buttons = els.tableHead.querySelectorAll('[data-sort-key]');
        buttons.forEach((button) => {
            const key = String(button.getAttribute('data-sort-key') || '');
            if (sortState.key === key) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        });
    }

    function renderGlobalFields() {
        const currency = normalizeCurrencyCode(state.global.currency);
        els.creditValueInput.value = formatNumber(convertMoneyFromEur(state.global.credit_value_eur, currency), 6);
        els.marginMultiplierInput.value = formatNumber(state.global.margin_multiplier, 4);
        els.fixedFeeInput.value = formatNumber(convertMoneyFromEur(state.global.fixed_fee_eur, currency), 4);
        els.roundingModeInput.value = state.global.rounding_mode;
        els.minCreditsInput.value = String(state.global.min_credits_per_operation);
        els.fxRateInput.value = formatNumber(state.global.fx_rate_eur_usd, 4);
        els.currencyInput.value = currency;
        renderCurrencyLabels();
        renderRealEurRate();
    }

    // Toujours depuis l'état (en EUR même si l'affichage est en USD) : c'est le
    // coût réel en euros que mesure le budget.
    function renderRealEurRate() {
        const target = document.querySelector('#creditsRealEurRate [data-rate]');
        if (!target) return;
        const value = Number(state.global.credit_value_eur) || 0;
        const rate = value > 0 ? Number(state.global.margin_multiplier) / value : 0;
        target.textContent = Math.round(rate).toLocaleString(NUM_LOCALE);
    }

    function renderCurrencyLabels() {
        const code = normalizeCurrencyCode(state.global.currency);
        const symbol = code === 'USD' ? '$' : '€';
        if (els.creditValueLabel) els.creditValueLabel.textContent = fill(L.creditValue, { symbol: symbol });
        if (els.fixedFeeLabel) els.fixedFeeLabel.textContent = fill(L.fixedFee, { symbol: symbol });
        if (els.fxRateLabel) els.fxRateLabel.textContent = L.fxRate;
        if (els.realCostHeader) els.realCostHeader.textContent = fill(L.realCost, { currency: code });
    }

    function renderServicesTable() {
        if (state.services.length === 0) {
            els.tableBody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:20px">' + escapeHtml(L.noService) + '</td></tr>';
            renderSortIndicators();
            return;
        }

        const orderedServices = sortServicesForDisplay(state.services);
        els.tableBody.innerHTML = orderedServices.map((service) => {
            const unitOptions = optionsHtml(UNIT_OPTIONS, service.unit);
            const selectedClass = service.id === selectedServiceId ? 'credits-row-active' : '';
            const typeClass = 'credits-row-type-' + (CATEGORY_OPTIONS.some((opt) => opt.value === service.type) ? service.type : 'other');
            const rowClasses = [typeClass, selectedClass].filter(Boolean).join(' ');

            const detailRow = service.id === selectedServiceId
                ? '<tr class="credits-inline-detail-row ' + typeClass + '"><td colspan="7"><div class="credits-inline-detail" data-role="inline-detail-content">' + buildInlineDetailHtml(service) + '</div></td></tr>'
                : '';

            return '' +
                '<tr class="' + rowClasses + '" data-id="' + escapeHtml(service.id) + '">' +
                    '<td class="credits-service-cell">' +
                        '<p class="credits-service-name">' + escapeHtml(service.name) + '</p>' +
                        '<div class="credits-service-meta">' + fill(escapeHtml(L.providerCategory), {
                            provider: '<strong>' + escapeHtml(service.provider) + '</strong>',
                            category: '<strong>' + escapeHtml(categoryLabel(service.type)) + '</strong>',
                        }) + '</div>' +
                    '</td>' +
                    '<td><select class="form-select" data-field="unit">' + unitOptions + '</select></td>' +
                    '<td>' + realCostCellHtml(service) + '</td>' +
                    '<td><input type="number" class="form-input" data-field="multiplier" min="0.0001" step="0.0001" value="' + escapeHtml(formatNumber(service.multiplier, 4)) + '"></td>' +
                    '<td>' +
                        '<div class="credits-result-strong" data-role="computed">-</div>' +
                        '<div class="credits-muted" data-role="pedago">-</div>' +
                    '</td>' +
                    '<td><textarea data-field="internal_comment" placeholder="' + escapeHtml(L.internalComment) + '">' + escapeHtml(service.internal_comment) + '</textarea></td>' +
                    '<td>' +
                        '<div style="display:flex;flex-direction:column;gap:6px">' +
                            '<button type="button" class="credits-mini-btn" data-action="delete" style="color:#fca5a5;border-color:rgba(239,68,68,.35)">' + escapeHtml(L.delete) + '</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>' +
                detailRow;
        }).join('');

        refreshSelectionStyle();
        refreshComputedCells();
        renderSortIndicators();
    }

    /**
     * Cellule du coût réel : le prix saisi, ou — champ vide — le tarif que la
     * facturation applique à sa place, dit sous le champ. Laisser le champ
     * vide suit le tarif public ; une saisie le remplace.
     */
    function realCostCellHtml(service) {
        const cost = effectiveCost(service);
        const own = cost.source === 'own';
        const shown = escapeHtml(formatNumber(convertMoneyFromEur(cost.eur), 6));
        const note = cost.source === 'catalog'
            ? '<div class="credits-muted credits-cost-source">' + escapeHtml(L.costCatalog) + '</div>'
            : (cost.source === 'derived'
                ? '<div class="credits-muted credits-cost-source">' + escapeHtml(L.costDerived) + '</div>'
                : (cost.source === 'none' ? '<div class="credits-muted credits-cost-source is-none">' + escapeHtml(L.costNone) + '</div>' : ''));
        return '<input type="number" class="form-input" data-field="real_cost_eur" min="0" step="0.000001"'
            + (own ? ' value="' + shown + '"' : ' value="" placeholder="' + shown + '"') + '>' + note;
    }

    function llmDirectionFromUnit(unit) {
        if (unit === '1m_output_tokens') return 'output';
        if (unit === '1m_input_tokens' || unit === '1k_tokens') return 'input';
        return null;
    }

    function llmCostPerMillionTokens(service) {
        if (!service) return 0;
        const real = effectiveCost(service).eur;
        if (service.unit === '1k_tokens') return real * 1000;
        return real;
    }

    function creditsPerToken(service) {
        if (!service) return 0;
        const raw = Math.max(0, Number(computePerUnit(service).rawCredits || 0));
        const tokensByUnit = service.unit === '1k_tokens'
            ? 1000
            : ((service.unit === '1m_input_tokens' || service.unit === '1m_output_tokens') ? 1000000 : 1);
        if (tokensByUnit <= 0) return 0;
        return raw / tokensByUnit;
    }

    function servicePriorityScore(service, expectedProvider, expectedModel) {
        const parsed = parseLlmCatalogKey(service.catalog_key);
        let score = 0;
        if (Math.max(0, Number(service.real_cost_eur || 0)) > 0) score += 120;
        if (service.active) score += 60;
        if (parsed) score += 15;
        if (parsed && parsed.provider === expectedProvider) score += 10;
        if (parsed && String(parsed.model || '').toLowerCase() === String(expectedModel || '').toLowerCase()) score += 20;
        return score;
    }

    function choosePreferredService(current, candidate, expectedProvider, expectedModel) {
        if (!current) return candidate;
        const curScore = servicePriorityScore(current, expectedProvider, expectedModel);
        const nextScore = servicePriorityScore(candidate, expectedProvider, expectedModel);
        if (nextScore !== curScore) {
            return nextScore > curScore ? candidate : current;
        }

        const curCost = llmCostPerMillionTokens(current);
        const nextCost = llmCostPerMillionTokens(candidate);
        if (nextCost !== curCost) {
            return nextCost > curCost ? candidate : current;
        }
        return current;
    }

    function buildModelIndex(services) {
        const llmServices = services.filter((s) => s.type === 'llm_text' || s.type === 'llm');
        const modelIndex = {};
        const providerFallbacks = {};

        function ensureEntry(provider, model) {
            const safeProvider = String(provider || '').trim().toLowerCase();
            const safeModel = String(model || '').trim();
            if (!safeProvider || !safeModel) return null;
            const key = safeProvider + '::' + safeModel;
            if (!modelIndex[key]) {
                modelIndex[key] = {
                    parsed: { provider: safeProvider, model: safeModel, direction: '' },
                    input: null,
                    output: null,
                };
            }
            return modelIndex[key];
        }

        const catalogModels = Array.isArray(catalog.llm_models) ? catalog.llm_models : [];
        catalogModels.forEach((row) => {
            if (!row || typeof row !== 'object') return;
            const provider = String(row.provider || '').trim().toLowerCase();
            const model = String(row.model || '').trim();
            ensureEntry(provider, model);
        });

        llmServices.forEach((service) => {
            const parsed = parseLlmCatalogKey(service.catalog_key);
            const provider = parsed
                ? parsed.provider
                : String(service.provider || '').trim().toLowerCase();
            if (!provider) return;

            const direction = parsed ? parsed.direction : llmDirectionFromUnit(service.unit);
            if (!direction) return;

            let explicitModel = '';
            if (parsed && parsed.model && String(parsed.model).toLowerCase() !== provider) {
                explicitModel = String(parsed.model);
            } else {
                explicitModel = inferModelFromService(service);
            }

            const fallbackByProvider = providerFallbacks[provider] || { input: null, output: null };
            fallbackByProvider[direction] = choosePreferredService(
                fallbackByProvider[direction],
                service,
                provider,
                explicitModel || provider
            );
            providerFallbacks[provider] = fallbackByProvider;

            if (!explicitModel) return;

            const entry = ensureEntry(provider, explicitModel);
            if (!entry) return;
            entry[direction] = choosePreferredService(entry[direction], service, provider, explicitModel);
        });

        Object.values(modelIndex).forEach((entry) => {
            const provider = entry.parsed.provider;
            const fallback = providerFallbacks[provider] || null;
            if (!fallback) return;
            if (!entry.input && fallback.input) entry.input = fallback.input;
            if (!entry.output && fallback.output) entry.output = fallback.output;
        });

        return modelIndex;
    }

    function modelEffectiveCost(entry) {
        const inCost = llmCostPerMillionTokens(entry.input);
        const outCost = llmCostPerMillionTokens(entry.output);
        if (inCost > 0 && outCost > 0) {
            return (inCost * 0.4) + (outCost * 0.6);
        }
        return inCost > 0 ? inCost : outCost;
    }

    function computeModelStars(modelIndex) {
        const stars = {};
        const rows = Object.entries(modelIndex)
            .map(([key, entry]) => ({ key, cost: modelEffectiveCost(entry) }))
            .filter((row) => Number.isFinite(row.cost) && row.cost > 0);

        if (rows.length === 0) {
            Object.keys(modelIndex).forEach((key) => {
                stars[key] = 3;
            });
            return stars;
        }

        const values = rows.map((row) => row.cost);
        const min = Math.min(...values);
        const max = Math.max(...values);

        if (Math.abs(max - min) < 1e-12) {
            rows.forEach((row) => {
                stars[row.key] = 3;
            });
            return stars;
        }

        rows.forEach((row) => {
            const ratio = (row.cost - min) / (max - min);
            const computed = 1 + Math.round(ratio * 4);
            stars[row.key] = Math.max(1, Math.min(5, computed));
        });

        return stars;
    }

    function renderModelView() {
        const el = document.getElementById('modelView');
        if (!el) return;

        const modelIndex = buildModelIndex(state.services);
        const stars = computeModelStars(modelIndex);

        const grouped = {};
        Object.values(modelIndex).forEach((entry) => {
            const provider = entry.parsed.provider;
            if (!grouped[provider]) grouped[provider] = [];
            grouped[provider].push(entry);
        });

        let html = '';
        for (const [provider, rows] of Object.entries(grouped)) {
            html += '<h3 class="model-view-provider">' + escapeHtml(provider) + '</h3>' +
                '<table class="model-view-table"><thead><tr>' +
                '<th>' + escapeHtml(L.model) + '</th><th>' + escapeHtml(L.costStars) + '</th>' +
                '<th>' + escapeHtml(L.costInput) + '</th><th>' + escapeHtml(L.creditsInput) + '</th>' +
                '<th>' + escapeHtml(L.costOutput) + '</th><th>' + escapeHtml(L.creditsOutput) + '</th>' +
                '</tr></thead><tbody>';

            rows.sort((a, b) => modelEffectiveCost(a) - modelEffectiveCost(b));

            rows.forEach((entry) => {
                const key = entry.parsed.provider + '::' + entry.parsed.model;
                const s = stars[key] || 1;
                const starsStr = '€'.repeat(s) + '·'.repeat(5 - s);
                const inSvc = entry.input;
                const outSvc = entry.output;

                const inCost = inSvc ? formatNumber(llmCostPerMillionTokens(inSvc), 6) : '—';
                const outCost = outSvc ? formatNumber(llmCostPerMillionTokens(outSvc), 6) : '—';
                const inCredits = inSvc ? formatNumber(creditsPerToken(inSvc), 8) : '—';
                const outCredits = outSvc ? formatNumber(creditsPerToken(outSvc), 8) : '—';

                html += '<tr>' +
                    '<td><strong>' + escapeHtml(entry.parsed.model) + '</strong></td>' +
                    '<td class="model-stars">' + starsStr + '</td>' +
                    '<td>' + inCost + '</td>' +
                    '<td>' + inCredits + '</td>' +
                    '<td>' + outCost + '</td>' +
                    '<td>' + outCredits + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
        }

        el.innerHTML = html || '<p style="color:var(--text-muted);font-size:13px">' + escapeHtml(L.noModel) + '</p>';
    }

    function buildStandardToolScenario(service, tool) {
        const unit = String(service.unit || '').trim();
        const toolType = String((tool && tool.type) || service.type || 'other').trim().toLowerCase();
        const toolConfig = safeParseJsonObject(String((tool && tool.config_json) || ''));
        const hints = [];

        if (toolConfig && typeof toolConfig === 'object') {
            if (toolConfig.size) hints.push('size ' + String(toolConfig.size));
            if (toolConfig.quality) hints.push(fill(L.hintQuality, { value: String(toolConfig.quality) }));
            if (toolConfig.aspect_ratio) hints.push('ratio ' + String(toolConfig.aspect_ratio));
            if (toolConfig.format) hints.push('format ' + String(toolConfig.format));
        }

        if (toolType === 'image' || unit === 'image_generated') {
            return {
                quantityUnits: (unit === 'image_generated') ? 1 : 1,
                summary: L.scenarioImage,
                hints: hints.join(' · '),
            };
        }

        if (toolType === 'video' || unit === 'video_second' || unit === 'second') {
            let seconds = parseNumber(toolConfig && toolConfig.duration, 8);
            if (!Number.isFinite(seconds) || seconds <= 0) seconds = 8;
            seconds = Math.max(1, Math.round(seconds));
            return {
                quantityUnits: (unit === 'video_second' || unit === 'second') ? seconds : 1,
                summary: fill(L.scenarioVideo, { n: seconds }),
                hints: hints.join(' · '),
            };
        }

        if (toolType === 'audio' || unit === 'audio_minute' || unit === 'minute') {
            const minutes = 1;
            return {
                quantityUnits: (unit === 'audio_minute' || unit === 'minute') ? minutes : 1,
                summary: fill(L.scenarioAudio, { n: minutes }),
                hints: hints.join(' · '),
            };
        }

        if (toolType === 'html') {
            return {
                quantityUnits: 1,
                summary: L.scenarioHtml,
                hints: hints.join(' · '),
            };
        }

        return {
            quantityUnits: 1,
            summary: L.scenarioOther,
            hints: hints.join(' · '),
        };
    }

    function computeToolStars(rows) {
        const stars = {};
        if (!Array.isArray(rows) || rows.length === 0) return stars;

        const pricedRows = rows.filter((row) => Number.isFinite(row.standardCost) && row.standardCost > 0);
        if (pricedRows.length === 0) {
            rows.forEach((row) => { stars[row.toolId] = 3; });
            return stars;
        }

        const values = pricedRows.map((row) => row.standardCost);
        const min = Math.min(...values);
        const max = Math.max(...values);

        if (Math.abs(max - min) < 1e-12) {
            pricedRows.forEach((row) => { stars[row.toolId] = 3; });
            return stars;
        }

        pricedRows.forEach((row) => {
            const ratio = (row.standardCost - min) / (max - min);
            const computed = 1 + Math.round(ratio * 4);
            stars[row.toolId] = Math.max(1, Math.min(5, computed));
        });

        return stars;
    }

    function renderToolView() {
        const el = document.getElementById('toolView');
        if (!el) return;

        const rows = [];
        state.services.forEach((service) => {
            const toolId = parseToolCatalogKey(service.catalog_key);
            if (!toolId) return;

            const tool = catalogIndexes.toolsById.get(toolId);
            if (!tool) return;

            const scenario = buildStandardToolScenario(service, tool);
            const calc = computeCreditsForQuantity(service, Math.max(0.000001, scenario.quantityUnits));
            const provider = String(tool.provider || service.provider || '').trim();
            const model = String(tool.model || '').trim();
            const providerModel = [provider, model].filter(Boolean).join(' · ');

            rows.push({
                toolId,
                name: String(tool.name || service.name || L.tool),
                typeLabel: categoryLabel(String(tool.type || service.type || 'other')),
                providerModel: providerModel || 'N/A',
                scenarioLabel: scenario.summary,
                scenarioHints: scenario.hints,
                standardCost: Number(calc.real) || 0,
                standardCredits: Number(calc.finalCredits) || 0,
            });
        });

        if (rows.length === 0) {
            el.innerHTML = '<p style="color:var(--text-muted);font-size:13px">' + escapeHtml(L.noTool) + '</p>';
            return;
        }

        rows.sort((a, b) => {
            if (a.standardCost !== b.standardCost) return a.standardCost - b.standardCost;
            return a.name.localeCompare(b.name, PAGE_LANG);
        });
        const stars = computeToolStars(rows);

        let html = '' +
            '<p style="font-size:12px;color:var(--text-muted);margin:0 0 8px">' + escapeHtml(L.toolIntro) + '</p>' +
            '<table class="tool-view-table"><thead><tr>' +
            '<th>' + escapeHtml(L.tool) + '</th><th>' + escapeHtml(L.type) + '</th><th>' + escapeHtml(L.providerModel) + '</th><th>' + escapeHtml(L.standardGeneration) + '</th><th>' + escapeHtml(L.relativeCost) + '</th>' +
            '</tr></thead><tbody>';

        rows.forEach((row) => {
            const s = stars[row.toolId] || 1;
            const starsStr = '€'.repeat(s) + '·'.repeat(5 - s);
            const hint = row.scenarioHints ? ('<div style="font-size:11px;color:var(--text-muted)">' + escapeHtml(row.scenarioHints) + '</div>') : '';

            html += '' +
                '<tr>' +
                    '<td><strong>' + escapeHtml(row.name) + '</strong></td>' +
                    '<td>' + escapeHtml(row.typeLabel) + '</td>' +
                    '<td>' + escapeHtml(row.providerModel) + '</td>' +
                    '<td class="tool-view-wrap">' +
                        '<div>' + escapeHtml(row.scenarioLabel) + '</div>' +
                        hint +
                    '</td>' +
                    '<td class="tool-view-wrap">' +
                        '<div class="tool-stars">' + starsStr + '</div>' +
                        '<div style="font-size:11px;color:var(--text-muted)">' + escapeHtml(formatMoney(row.standardCost)) + ' · ' + escapeHtml(creditsText(row.standardCredits)) + '</div>' +
                    '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        el.innerHTML = html;
    }

    function refreshSelectionStyle() {
        const rows = els.tableBody.querySelectorAll('tr[data-id]');
        rows.forEach((row) => {
            if (row.getAttribute('data-id') === selectedServiceId) {
                row.classList.add('credits-row-active');
            } else {
                row.classList.remove('credits-row-active');
            }
        });
    }

    function refreshComputedCells() {
        const rows = els.tableBody.querySelectorAll('tr[data-id]');
        rows.forEach((row) => {
            const id = row.getAttribute('data-id');
            const service = getServiceById(id);
            if (!service) return;
            const calc = computePerUnit(service);
            const computedEl = row.querySelector('[data-role="computed"]');
            const pedagoEl = row.querySelector('[data-role="pedago"]');
            if (computedEl) {
                computedEl.textContent = creditsText(calc.finalCredits);
            }
            if (pedagoEl) {
                pedagoEl.textContent = fill(L.pedago, {
                    cost: formatMoney(calc.real),
                    coeff: formatNumber(state.global.margin_multiplier * service.multiplier, 4),
                    credits: String(calc.finalCredits),
                });
            }
        });
    }

    function buildInlineDetailHtml(service) {
        const calc = computePerUnit(service);
        const roundingLabel = state.global.rounding_mode === 'ceil'
            ? L.roundUp
            : (state.global.rounding_mode === 'floor' ? L.roundDown : L.roundStandard);

        return '' +
            '<div style="font-size:13px;color:var(--text);font-weight:600">' + escapeHtml(service.name) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailProvider) + '</strong> ' + escapeHtml(service.provider) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailCategory) + '</strong> ' + escapeHtml(categoryLabel(service.type)) + ' · <strong>' + escapeHtml(L.detailUnit) + '</strong> ' + escapeHtml(unitLabel(service.unit)) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailRealCost) + '</strong> ' + formatMoney(calc.real) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailAfterCoeffs) + '</strong> ' + formatMoney(calc.afterCoefficients) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailAfterFixed) + '</strong> ' + formatMoney(calc.afterFixed) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailRaw) + '</strong> ' + escapeHtml(creditsText(Number(formatNumber(calc.rawCredits, 4)), formatNumber(calc.rawCredits, 4))) + '</div>' +
            '<div class="credits-detail-line"><strong>' + escapeHtml(L.detailResult) + '</strong> ' + escapeHtml(creditsText(calc.finalCredits)) + ' (' + escapeHtml(roundingLabel) + ')</div>' +
            '<div class="credits-detail-line" style="margin-top:10px;padding:8px;border:1px dashed var(--border);border-radius:8px">' +
                fill(escapeHtml(L.detailChain), {
                    cost: formatMoney(calc.real),
                    coeff: formatNumber(state.global.margin_multiplier * service.multiplier, 4),
                    after: formatMoney(calc.afterCoefficients),
                    rounding: escapeHtml(roundingLabel),
                    credits: '<strong>' + escapeHtml(creditsText(calc.finalCredits)) + '</strong>',
                }) +
            '</div>';
    }

    function refreshInlineDetailForSelected() {
        const service = getServiceById(selectedServiceId);
        if (!service) return;
        const container = els.tableBody.querySelector('[data-role="inline-detail-content"]');
        if (!container) return;
        container.innerHTML = buildInlineDetailHtml(service);
    }

    function renderSimulatorControls() {
        const pool = getSimulationServicePool();
        if (pool.length === 0) {
            els.simServiceSelect.innerHTML = '<option value="">' + escapeHtml(L.noServiceOption) + '</option>';
            els.simServiceSelect.disabled = true;
            return;
        }

        els.simServiceSelect.disabled = false;
        ensureSimulationService();
        els.simServiceSelect.innerHTML = pool.map((service) => {
            return '<option value="' + escapeHtml(service.id) + '"' + (service.id === simulator.serviceId ? ' selected' : '') + '>' +
                escapeHtml(service.name + ' — ' + unitLabel(service.unit)) + '</option>';
        }).join('');
    }

    function renderSimulatorResult() {
        const service = getServiceById(simulator.serviceId);
        if (!service) {
            els.simTokensGroup.style.display = 'none';
            els.simulatorOutput.innerHTML = '<span style="font-size:12px;color:var(--text-muted)">' + escapeHtml(L.selectService) + '</span>';
            return;
        }

        const quantityInput = Math.max(0.000001, parseNumber(els.simQuantityInput.value, 1));
        const tokenFactor = TOKEN_UNITS[service.unit] || null;
        let quantityUnits = quantityInput;
        let quantityLabel = formatNumber(quantityUnits, 4) + ' ' + unitLabel(service.unit);

        if (tokenFactor) {
            els.simTokensGroup.style.display = '';
            const rawTokens = Math.max(0, parseNumber(els.simTokensInput.value, 0));
            if (rawTokens > 0) {
                quantityUnits = rawTokens / tokenFactor;
                quantityLabel = fill(L.tokensQuantity, {
                    tokens: formatNumber(rawTokens, 0),
                    units: formatNumber(quantityUnits, 6),
                    unit: unitLabel(service.unit),
                });
            }
        } else {
            els.simTokensGroup.style.display = 'none';
            els.simTokensInput.value = '';
        }

        const calc = computeCreditsForQuantity(service, quantityUnits);
        els.simulatorOutput.innerHTML = '' +
            '<div style="display:flex;flex-direction:column;gap:6px">' +
                '<div style="font-size:12px;color:var(--text-muted)"><strong style="color:var(--text)">' + escapeHtml(service.name) + '</strong> · ' + escapeHtml(quantityLabel) + '</div>' +
                '<div style="font-size:13px"><strong>' + escapeHtml(L.estimatedCost) + '</strong> ' + formatMoney(calc.real) + '</div>' +
                '<div style="font-size:13px"><strong>' + escapeHtml(L.estimatedCredits) + '</strong> <span style="color:var(--accent);font-weight:700">' + escapeHtml(creditsText(calc.finalCredits)) + '</span></div>' +
                '<div class="credits-muted">' + escapeHtml(fill(L.simDetail, {
                    cost: formatMoney(calc.real),
                    total: formatMoney(calc.afterFixed),
                    raw: formatNumber(calc.rawCredits, 4),
                    credits: creditsText(calc.finalCredits),
                })) + '</div>' +
            '</div>';
    }

    function renderSummary() {
        const activeServices = state.services.filter((service) => service.active);
        if (activeServices.length === 0) {
            els.summary.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">' + escapeHtml(L.noActiveService) + '</div>';
            return;
        }

        const computed = activeServices.map((service) => ({ service, calc: computePerUnit(service) }));
        computed.sort((a, b) => a.calc.finalCredits - b.calc.finalCredits);

        const cheapest = computed[0];
        const expensive = computed[computed.length - 1];

        const byCategory = {};
        computed.forEach((item) => {
            const key = item.service.type;
            if (!byCategory[key]) {
                byCategory[key] = { sum: 0, count: 0 };
            }
            byCategory[key].sum += item.calc.finalCredits;
            byCategory[key].count += 1;
        });

        const avgByCategoryHtml = Object.keys(byCategory)
            .map((key) => {
                const info = byCategory[key];
                const avg = info.count > 0 ? (info.sum / info.count) : 0;
                return '<li><span>' + escapeHtml(categoryLabel(key)) + '</span><strong>' + escapeHtml(creditsText(Number(formatNumber(avg, 2)), formatNumber(avg, 2))) + '</strong></li>';
            })
            .join('');

        const topConsumersHtml = computed
            .slice()
            .sort((a, b) => b.calc.finalCredits - a.calc.finalCredits)
            .slice(0, 3)
            .map((item) => '<li><span>' + escapeHtml(item.service.name) + '</span><strong>' + escapeHtml(creditsText(item.calc.finalCredits)) + '</strong></li>')
            .join('');

        els.summary.innerHTML = '' +
            '<div class="credits-kpis">' +
                '<div class="credits-kpi"><strong>' + activeServices.length + '</strong><span>' + escapeHtml(L.activeServices) + '</span></div>' +
                '<div class="credits-kpi"><strong>' + formatNumber(computed.reduce((sum, item) => sum + item.calc.finalCredits, 0) / activeServices.length, 2) + '</strong><span>' + escapeHtml(L.averageCredits) + '</span></div>' +
                '<div class="credits-kpi"><strong>' + cheapest.calc.finalCredits + '</strong><span>' + escapeHtml(fill(L.cheapest, { name: cheapest.service.name })) + '</span></div>' +
                '<div class="credits-kpi"><strong>' + expensive.calc.finalCredits + '</strong><span>' + escapeHtml(fill(L.mostExpensive, { name: expensive.service.name })) + '</span></div>' +
            '</div>' +
            '<div style="font-size:12px;color:var(--text-muted);margin:6px 0 4px">' + escapeHtml(L.averageByCategory) + '</div>' +
            '<ul class="credits-list">' + avgByCategoryHtml + '</ul>' +
            '<div style="font-size:12px;color:var(--text-muted);margin:10px 0 4px">' + escapeHtml(L.topConsumers) + '</div>' +
            '<ul class="credits-list">' + topConsumersHtml + '</ul>';
    }

    function formatReportDate() {
        try {
            return new Intl.DateTimeFormat(NUM_LOCALE, {
                dateStyle: 'long',
                timeStyle: 'short',
            }).format(new Date());
        } catch (_e) {
            return new Date().toLocaleString(NUM_LOCALE);
        }
    }

    function buildPdfSimulationEntries() {
        const pool = getSimulationServicePool();
        if (pool.length === 0) return [];

        const used = new Set();
        const entries = [];

        function pick(matchFn) {
            for (let i = 0; i < pool.length; i += 1) {
                const service = pool[i];
                if (used.has(service.id)) continue;
                if (!matchFn(service)) continue;
                used.add(service.id);
                return service;
            }
            return null;
        }

        function addEntry(service, quantityUnits, scenarioLabel) {
            if (!service) return;
            const calc = computeCreditsForQuantity(service, quantityUnits);
            let quantityLabel = formatNumber(quantityUnits, 6) + ' ' + unitLabel(service.unit);
            const tokenFactor = TOKEN_UNITS[service.unit] || null;
            if (tokenFactor) {
                const tokens = quantityUnits * tokenFactor;
                quantityLabel = fill(L.tokensQuantity, {
                    tokens: formatNumber(tokens, 0),
                    units: formatNumber(quantityUnits, 6),
                    unit: unitLabel(service.unit),
                });
            }

            entries.push({
                scenarioLabel,
                serviceName: service.name,
                provider: service.provider,
                unitLabel: unitLabel(service.unit),
                quantityLabel,
                realCostLabel: formatMoney(calc.real),
                creditsLabel: creditsText(calc.finalCredits),
                detailLabel: fill(L.pdfDetail, {
                    cost: formatMoney(calc.real),
                    coeff: formatNumber(state.global.margin_multiplier * service.multiplier, 4),
                    total: formatMoney(calc.afterFixed),
                    raw: formatNumber(calc.rawCredits, 4),
                    credits: String(calc.finalCredits),
                }),
            });
        }

        const inputLlm = pick((service) => service.type === 'llm_text' && (service.unit === '1m_input_tokens' || service.unit === '1k_tokens'));
        if (inputLlm) {
            const tokenFactor = TOKEN_UNITS[inputLlm.unit] || 1000000;
            addEntry(inputLlm, 25000 / tokenFactor, L.sim25kInput);
        }

        const outputLlm = pick((service) => service.type === 'llm_text' && service.unit === '1m_output_tokens');
        if (outputLlm) {
            addEntry(outputLlm, 12000 / 1000000, L.sim12kOutput);
        }

        const imageService = pick((service) => service.unit === 'image_generated' || service.type === 'image');
        addEntry(imageService, 3, L.sim3Images);

        const videoService = pick((service) => service.unit === 'video_second' || service.type === 'video');
        addEntry(videoService, 10, L.sim10sVideo);

        for (let i = 0; i < pool.length && entries.length < 4; i += 1) {
            const service = pool[i];
            if (used.has(service.id)) continue;
            used.add(service.id);
            addEntry(service, 1, L.simExtra);
        }

        return entries.slice(0, 4);
    }

    function buildPdfReportHtml() {
        const todayIso = new Date().toISOString().slice(0, 10);
        const currency = normalizeCurrencyCode(state.global.currency);
        const roundingLabel = state.global.rounding_mode === 'ceil'
            ? L.roundingUp
            : (state.global.rounding_mode === 'floor' ? L.roundingDown : L.roundingNearest);
        const formulaLine = L.formula;

        const servicesRows = state.services.map((service) => {
            const perUnit = computePerUnit(service);
            return '' +
                '<tr>' +
                    '<td><strong>' + escapeHtml(service.name) + '</strong><br><span style="color:#64748b;font-size:11px">' + fill(escapeHtml(L.providerCategory), {
                        provider: escapeHtml(service.provider),
                        category: escapeHtml(categoryLabel(service.type)),
                    }) + '</span></td>' +
                    '<td>' + escapeHtml(unitLabel(service.unit)) + '</td>' +
                    '<td>' + formatMoney(effectiveCost(service).eur) + (effectiveCost(service).source === 'catalog' ? ' ' + escapeHtml(L.publicTariff) : '') + '</td>' +
                    '<td>' + formatNumber(service.multiplier, 4) + '</td>' +
                    '<td><strong>' + escapeHtml(creditsText(perUnit.finalCredits)) + '</strong></td>' +
                    '<td>' + escapeHtml(service.active ? L.active : L.inactive) + '</td>' +
                '</tr>';
        }).join('');

        const simulations = buildPdfSimulationEntries();
        const simRows = simulations.length > 0
            ? simulations.map((sim) => {
                return '' +
                    '<tr>' +
                        '<td>' + escapeHtml(sim.scenarioLabel) + '</td>' +
                        '<td><strong>' + escapeHtml(sim.serviceName) + '</strong><br><span style="color:#64748b;font-size:11px">' + escapeHtml(sim.provider) + ' · ' + escapeHtml(sim.unitLabel) + '</span></td>' +
                        '<td>' + escapeHtml(sim.quantityLabel) + '</td>' +
                        '<td>' + escapeHtml(sim.realCostLabel) + '</td>' +
                        '<td><strong>' + escapeHtml(sim.creditsLabel) + '</strong></td>' +
                        '<td style="font-size:11px;color:#475569">' + escapeHtml(sim.detailLabel) + '</td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="6">' + escapeHtml(L.noSimulation) + '</td></tr>';

        return '' +
            '<!doctype html>' +
            '<html lang="' + PAGE_LANG + '">' +
            '<head>' +
                '<meta charset="utf-8">' +
                '<title>' + escapeHtml(fill(L.pdfFileTitle, { date: todayIso })) + '</title>' +
                '<style>' +
                    'body{font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,\"Helvetica Neue\",Arial,sans-serif;color:#0f172a;padding:24px;line-height:1.4}' +
                    'h1{margin:0 0 6px;font-size:22px}h2{margin:20px 0 8px;font-size:16px}' +
                    'p{margin:4px 0;font-size:12px;color:#334155}' +
                    '.meta{display:flex;gap:16px;flex-wrap:wrap;margin:8px 0 0}' +
                    '.chip{border:1px solid #cbd5e1;border-radius:999px;padding:4px 10px;font-size:11px;color:#334155}' +
                    'table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px}' +
                    'th,td{border:1px solid #cbd5e1;padding:8px;vertical-align:top;text-align:left}' +
                    'th{background:#f8fafc}' +
                    '.formula{margin-top:8px;padding:8px;border:1px dashed #94a3b8;background:#f8fafc;border-radius:8px;font-size:12px}' +
                    '.small{font-size:11px;color:#64748b}' +
                    '@media print{body{padding:10mm} .no-print{display:none}}' +
                '</style>' +
            '</head>' +
            '<body>' +
                '<h1>' + escapeHtml(L.pageTitle) + '</h1>' +
                '<p>' + escapeHtml(fill(L.pdfGenerated, { date: formatReportDate() })) + '</p>' +
                '<div class="meta">' +
                    '<span class="chip">' + escapeHtml(fill(L.chipCurrency, { value: currency })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipCreditValue, { value: formatMoney(state.global.credit_value_eur) })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipGlobalCoeff, { value: formatNumber(state.global.margin_multiplier, 4) })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipFixedFee, { value: formatMoney(state.global.fixed_fee_eur) })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipRounding, { value: roundingLabel })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipMinCredits, { value: String(state.global.min_credits_per_operation) })) + '</span>' +
                    '<span class="chip">' + escapeHtml(fill(L.chipFxRate, { value: formatNumber(state.global.fx_rate_eur_usd, 4) })) + '</span>' +
                '</div>' +
                '<div class="formula">' +
                    '<strong>' + escapeHtml(L.formulaLabel) + '</strong> ' + escapeHtml(formulaLine) +
                '</div>' +

                '<h2>' + escapeHtml(L.aiServices) + '</h2>' +
                '<p class="small">' + escapeHtml(L.aiServicesIntro) + '</p>' +
                '<table>' +
                    '<thead><tr><th>' + escapeHtml(L.service) + '</th><th>' + escapeHtml(L.unit) + '</th><th>' + escapeHtml(L.realCostPlain) + '</th><th>' + escapeHtml(L.specificCoeff) + '</th><th>' + escapeHtml(L.creditsPerUnit) + '</th><th>' + escapeHtml(L.status) + '</th></tr></thead>' +
                    '<tbody>' + servicesRows + '</tbody>' +
                '</table>' +

                '<h2>' + escapeHtml(L.simulationsTitle) + '</h2>' +
                '<p class="small">' + escapeHtml(L.simulationsIntro) + '</p>' +
                '<table>' +
                    '<thead><tr><th>' + escapeHtml(L.scenario) + '</th><th>' + escapeHtml(L.service) + '</th><th>' + escapeHtml(L.quantity) + '</th><th>' + escapeHtml(L.realCostPlain) + '</th><th>' + escapeHtml(L.credits) + '</th><th>' + escapeHtml(L.calcDetail) + '</th></tr></thead>' +
                    '<tbody>' + simRows + '</tbody>' +
                '</table>' +
            '</body>' +
            '</html>';
    }

    function exportPdfReport() {
        const html = buildPdfReportHtml();
        const popup = window.open('', '_blank', 'noopener,noreferrer');
        if (!popup) {
            showClientError(L.popupBlocked);
            return;
        }
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
        popup.focus();
        setTimeout(function () {
            popup.print();
        }, 300);
    }

    function openModal(modalEl) {
        if (!modalEl) return;
        modalEl.classList.add('open');
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modalEl) {
        if (!modalEl) return;
        modalEl.classList.remove('open');
        modalEl.setAttribute('aria-hidden', 'true');
        const hasOpenModal = document.querySelector('.credits-modal.open');
        if (!hasOpenModal) {
            document.body.style.overflow = '';
        }
    }

    function closeAllModals() {
        closeModal(els.simulatorModal);
        closeModal(els.summaryModal);
    }

    function renderDirtyState() {
        const current = JSON.stringify(normalizeConfig(state));
        const saved = JSON.stringify(normalizeConfig(savedState));
        const dirty = current !== saved;

        els.dirtyPill.textContent = dirty ? L.saving : L.allSaved;
        els.dirtyPill.style.color = dirty ? '#fbbf24' : 'var(--text-muted)';
        els.dirtyPill.style.borderColor = dirty ? 'rgba(251,191,36,.45)' : 'var(--border)';

        els.saveStateLabel.textContent = dirty ? L.sendingSoon : L.configSynced;
        els.saveStateLabel.style.color = dirty ? '#fbbf24' : 'var(--text-muted)';
        if (syncFlashMessage !== '') {
            els.saveStateLabel.textContent = syncFlashMessage;
            els.saveStateLabel.style.color = '#6ee7b7';
        }

        // Aucun champ nommé ne change dans ce formulaire : le module ne peut
        // pas détecter la modification tout seul, c'est l'écran qui la lui
        // signale. Délai long — les valeurs se saisissent au clavier.
        if (dirty && window.MiaAutosave) {
            window.MiaAutosave.save(els.form, 900);
        }
    }

    function setCreditsView(viewKey) {
        const nextView = (viewKey === 'models' || viewKey === 'tools') ? viewKey : 'services';
        currentView = nextView;

        const servicesBtn = document.getElementById('btnViewServices');
        const modelsBtn = document.getElementById('btnViewModels');
        const toolsBtn = document.getElementById('btnViewTools');
        const servicesWrap = document.querySelector('.table-wrap');
        const modelView = document.getElementById('modelView');
        const toolView = document.getElementById('toolView');

        if (servicesBtn) servicesBtn.classList.toggle('active', nextView === 'services');
        if (modelsBtn) modelsBtn.classList.toggle('active', nextView === 'models');
        if (toolsBtn) toolsBtn.classList.toggle('active', nextView === 'tools');

        if (servicesWrap) servicesWrap.style.display = nextView === 'services' ? '' : 'none';
        if (modelView) modelView.style.display = nextView === 'models' ? '' : 'none';
        if (toolView) toolView.style.display = nextView === 'tools' ? '' : 'none';

        if (nextView === 'models') renderModelView();
        if (nextView === 'tools') renderToolView();
    }

    function renderAll() {
        ensureSelectedService();
        ensureSimulationService();
        renderGlobalFields();
        renderServicesTable();
        setCreditsView(currentView);
        renderSimulatorControls();
        renderSimulatorResult();
        renderSummary();
        renderDirtyState();
    }

    function clearClientError() {
        els.clientError.style.display = 'none';
        els.clientError.textContent = '';
    }

    function showClientError(message) {
        els.clientError.style.display = '';
        els.clientError.textContent = message;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function deleteService(serviceId) {
        const service = getServiceById(serviceId);
        if (!service) return;
        const confirmed = window.confirm(fill(L.confirmDelete, { name: service.name }));
        if (!confirmed) return;

        state.services = state.services.filter((item) => item.id !== serviceId);
        if (selectedServiceId === serviceId) {
            selectedServiceId = state.services[0] ? state.services[0].id : null;
        }
        if (simulator.serviceId === serviceId) {
            simulator.serviceId = state.services[0] ? state.services[0].id : '';
        }
        renderAll();
    }

    function validateConfig(config) {
        const errors = [];
        if (!(config.global.credit_value_eur > 0)) errors.push(L.errCreditValue);
        if (!(config.global.margin_multiplier > 0)) errors.push(L.errGlobalCoeff);
        if (config.global.fixed_fee_eur < 0) errors.push(L.errFixedFee);
        if (!(config.global.fx_rate_eur_usd > 0)) errors.push(L.errFxRate);
        if (config.global.min_credits_per_operation < 0) errors.push(L.errMinCredits);

        config.services.forEach((service, index) => {
            if (!service.name || !service.name.trim()) {
                errors.push(fill(L.errName, { n: index + 1 }));
            }
            if (!service.provider || !service.provider.trim()) {
                errors.push(fill(L.errProvider, { n: index + 1 }));
            }
            if (service.real_cost_eur < 0) {
                errors.push(fill(L.errRealCost, { n: index + 1 }));
            }
            if (!(service.multiplier > 0)) {
                errors.push(fill(L.errSpecificCoeff, { n: index + 1 }));
            }
        });

        return errors;
    }

    function bindEvents() {
        els.creditValueInput.addEventListener('input', function () {
            syncFlashMessage = '';
            const currency = normalizeCurrencyCode(state.global.currency);
            state.global.credit_value_eur = Math.max(0.000001, convertMoneyToEur(parseNumber(this.value, convertMoneyFromEur(state.global.credit_value_eur, currency)), currency));
            renderRealEurRate();
            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        });
        els.marginMultiplierInput.addEventListener('input', function () {
            syncFlashMessage = '';
            state.global.margin_multiplier = Math.max(0.0001, parseNumber(this.value, state.global.margin_multiplier));
            renderRealEurRate();
            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        });
        els.fixedFeeInput.addEventListener('input', function () {
            syncFlashMessage = '';
            const currency = normalizeCurrencyCode(state.global.currency);
            state.global.fixed_fee_eur = Math.max(0, convertMoneyToEur(parseNumber(this.value, convertMoneyFromEur(state.global.fixed_fee_eur, currency)), currency));
            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        });
        els.fxRateInput.addEventListener('change', function () {
            syncFlashMessage = '';
            state.global.fx_rate_eur_usd = Math.max(0.0001, parseNumber(this.value, state.global.fx_rate_eur_usd));
            renderAll();
        });

        els.roundingModeInput.addEventListener('change', function () {
            syncFlashMessage = '';
            state.global.rounding_mode = ROUNDING_OPTIONS.includes(this.value) ? this.value : 'ceil';
            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        });
        els.minCreditsInput.addEventListener('input', function () {
            syncFlashMessage = '';
            state.global.min_credits_per_operation = Math.max(0, Math.floor(parseNumber(this.value, state.global.min_credits_per_operation)));
            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        });
        els.currencyInput.addEventListener('change', function () {
            syncFlashMessage = '';
            state.global.currency = normalizeCurrencyCode(this.value);
            renderAll();
        });

        els.tableHead.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-sort-key]');
            if (!trigger) return;
            const key = String(trigger.getAttribute('data-sort-key') || '');
            if (!key) return;
            if (sortState.key === key) {
                sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.key = key;
                sortState.direction = 'asc';
            }
            renderServicesTable();
        });

        els.openSimulatorBtn.addEventListener('click', function () {
            renderSimulatorControls();
            renderSimulatorResult();
            openModal(els.simulatorModal);
        });
        els.openSummaryBtn.addEventListener('click', function () {
            renderSummary();
            openModal(els.summaryModal);
        });
        els.closeSimulatorModalBtn.addEventListener('click', function () {
            closeModal(els.simulatorModal);
        });
        els.closeSummaryModalBtn.addEventListener('click', function () {
            closeModal(els.summaryModal);
        });
        els.simulatorModal.addEventListener('click', function (event) {
            if (event.target === els.simulatorModal) closeModal(els.simulatorModal);
        });
        els.summaryModal.addEventListener('click', function (event) {
            if (event.target === els.summaryModal) closeModal(els.summaryModal);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAllModals();
        });

        els.refreshPricesBtn.addEventListener('click', async function () {
            clearClientError();
            syncFlashMessage = '';
            if (!Array.isArray(state.services) || state.services.length === 0) {
                showClientError(L.noServiceToUpdate);
                return;
            }

            const confirmed = window.confirm(L.confirmRefresh);
            if (!confirmed) {
                return;
            }

            const csrfToken = getCsrfToken();
            if (!csrfToken) {
                showClientError(L.csrfMissing);
                return;
            }

            const payload = {
                services: state.services.map((service) => buildRefreshPayloadService(service)),
            };

            setPriceJobRunning(true);
            try {
                const response = await fetch('/admin/credits/refresh-prices', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const queued = await response.json().catch(() => ({}));
                if (!response.ok || !queued.ok || !queued.job_id) {
                    throw new Error(queued.message || L.pricesUnavailable);
                }

                followPriceJob(queued.job_id);
            } catch (error) {
                setPriceJobRunning(false);
                showClientError(error instanceof Error ? error.message : L.unknownError);
            }
        });

        els.priceJobCancelBtn?.addEventListener('click', async function () {
            if (!priceJobId) return;
            this.disabled = true;
            try {
                await fetch('/jobs/' + priceJobId + '/cancel', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': getCsrfToken(), 'Accept': 'application/json' },
                });
            } catch (_e) {
                // Réseau coupé : le job reste en cours, le bouton se réarme.
                this.disabled = false;
            }
        });

        els.tableBody.addEventListener('click', function (event) {
            const actionBtn = event.target.closest('button[data-action]');
            const row = event.target.closest('tr[data-id]');
            if (!row) return;

            const serviceId = row.getAttribute('data-id');
            if (actionBtn) {
                const action = actionBtn.getAttribute('data-action');
                if (action === 'delete') deleteService(serviceId);
                return;
            }

            const tag = String(event.target.tagName || '').toLowerCase();
            if (['input', 'select', 'textarea', 'button', 'label'].includes(tag)) {
                return;
            }

            selectedServiceId = serviceId;
            renderServicesTable();
        });

        function onServiceField(event) {
            const target = event.target;
            const row = target.closest('tr[data-id]');
            if (!row) return;

            const service = getServiceById(row.getAttribute('data-id'));
            if (!service) return;

            const field = target.getAttribute('data-field');
            if (!field) return;
            syncFlashMessage = '';

            if (field === 'unit') service.unit = UNIT_OPTIONS.some((opt) => opt.value === target.value) ? target.value : 'request';
            if (field === 'real_cost_eur') {
                service.real_cost_eur = Math.max(0, convertMoneyToEur(parseNumber(target.value, 0)));
            }
            if (field === 'multiplier') service.multiplier = Math.max(0.0001, parseNumber(target.value, 1));
            if (field === 'internal_comment') service.internal_comment = String(target.value || '');

            refreshComputedCells();
            refreshInlineDetailForSelected();
            renderSimulatorControls();
            renderSimulatorResult();
            renderSummary();
            renderDirtyState();
        }

        els.tableBody.addEventListener('input', onServiceField);
        els.tableBody.addEventListener('change', onServiceField);

        els.simServiceSelect.addEventListener('change', function () {
            syncFlashMessage = '';
            simulator.serviceId = this.value;
            renderSimulatorResult();
        });
        els.simQuantityInput.addEventListener('input', function () {
            syncFlashMessage = '';
            simulator.quantity = Math.max(0.000001, parseNumber(this.value, 1));
            renderSimulatorResult();
        });
        els.simTokensInput.addEventListener('input', function () {
            syncFlashMessage = '';
            simulator.tokens = this.value;
            renderSimulatorResult();
        });

        els.exportBtn.addEventListener('click', function () {
            const payload = normalizeConfig(state);
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'credits-config-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });

        els.exportPdfBtn.addEventListener('click', function () {
            clearClientError();
            exportPdfReport();
        });

        // L'état de cet écran ne vit pas dans des champs nommés (tableaux
        // rendus en JS) mais dans un objet sérialisé dans un champ caché.
        // `mia:autosave-before` est le moment où le module réclame cette
        // sérialisation ; l'annuler refuse l'envoi (configuration invalide).
        // Le `submit` reste écouté pour le mode sans JavaScript du module.
        function serializeForSave(event) {
            clearClientError();
            const payload = normalizeConfig(state);
            const errors = validateConfig(payload);
            if (errors.length > 0) {
                event.preventDefault();
                showClientError(errors[0]);
                if (window.MiaAutosave) {
                    window.MiaAutosave.paint('invalid', errors[0]);
                }
                return;
            }

            els.hiddenConfig.value = JSON.stringify(payload);
        }
        els.form.addEventListener('mia:autosave-before', serializeForSave);
        els.form.addEventListener('submit', serializeForSave);

        // Enregistré côté serveur : la référence de comparaison devient l'état
        // courant, sans quoi l'écran se croirait modifié en permanence et
        // relancerait un enregistrement à chaque rendu.
        els.form.addEventListener('mia:autosaved', function () {
            savedState = deepClone(state);
            renderDirtyState();
        });

        document.getElementById('btnViewServices')?.addEventListener('click', function () {
            setCreditsView('services');
        });
        document.getElementById('btnViewModels')?.addEventListener('click', function () {
            setCreditsView('models');
        });
        document.getElementById('btnViewTools')?.addEventListener('click', function () {
            setCreditsView('tools');
        });
    }

    const bootstrap = parseBootstrap();
    const catalog = bootstrap.catalog || {};
    const catalogIndexes = (function buildCatalogIndexes() {
        const toolsById = new Map();

        const tools = Array.isArray(catalog.tools) ? catalog.tools : [];
        tools.forEach((row) => {
            if (!row || typeof row !== 'object') return;
            const id = String(row.id || '').trim();
            if (!id) return;
            toolsById.set(id, row);
        });

        return { toolsById };
    })();

    function safeParseJsonObject(raw) {
        if (!raw || typeof raw !== 'string') return null;
        try {
            const decoded = JSON.parse(raw);
            return decoded && typeof decoded === 'object' ? decoded : null;
        } catch (_e) {
            return null;
        }
    }

    function parseLlmCatalogKey(catalogKey) {
        const raw = String(catalogKey || '').trim();
        if (!raw.toLowerCase().startsWith('llm:')) return null;

        const body = raw.slice(4);
        const directionMatch = body.match(/^(.*):(input|output)$/i);
        if (!directionMatch) return null;

        const descriptor = String(directionMatch[1] || '').trim();
        const direction = String(directionMatch[2] || '').trim().toLowerCase();

        let provider = '';
        let model = '';

        if (descriptor.includes('::')) {
            const sep = descriptor.indexOf('::');
            provider = descriptor.slice(0, sep).trim().toLowerCase();
            model = descriptor.slice(sep + 2).trim();
        } else {
            const parts = descriptor
                .split(':')
                .map((part) => part.trim())
                .filter(Boolean);
            provider = String(parts.shift() || '').toLowerCase();
            model = parts.join(':').trim();
        }

        if (provider === '') return null;
        if (model === '') model = provider;

        return { provider, model, direction };
    }

    function parseToolCatalogKey(catalogKey) {
        const match = String(catalogKey || '').match(/^tool:(\d+)$/i);
        if (!match) return null;
        return String(match[1] || '').trim();
    }

    function inferModelFromService(service) {
        const key = String(service.catalog_key || '').toLowerCase();
        const name = String(service.name || '').toLowerCase();
        const provider = String(service.provider || '').toLowerCase();

        // Mapping samples → vrais modèles phares (utilisés quand le catalog_key
        // est un sample seedé, ex. "sample:mistral:input"). Sinon le LLM
        // appelé pour la mise à jour des prix reçoit un identifiant trop
        // générique ("mistral") et renvoie not_found.
        const SAMPLE_TO_MODEL = {
            gpt5:    'gpt-5.6',
            gpt4:    'gpt-4o',
            claude:  'claude-opus-4-7',
            mistral: 'mistral-large-latest',
            gemini:  'gemini-2.5-pro',
            dalle:   'dall-e-3',
            imagen:  'imagen-4.0-generate-001',
            sora:    'sora-2',
        };

        const sampleMatch = key.match(/^sample:([^:]+):/);
        if (sampleMatch && sampleMatch[1]) {
            const rawSample = String(sampleMatch[1]).trim().toLowerCase();
            if (SAMPLE_TO_MODEL[rawSample]) return SAMPLE_TO_MODEL[rawSample];
            if (rawSample !== '') return rawSample;
        }

        if (key.includes('sora') || name.includes('sora')) return 'sora-2';
        if (key.includes('dalle') || name.includes('dall-e') || name.includes('dalle')) return 'dall-e-3';
        if (key.includes('imagen') || name.includes('imagen')) return 'imagen-4.0-generate-001';
        if (name.includes('gemini')) return 'gemini-2.5-pro';
        if (name.includes('gpt-5')) return 'gpt-5.6';
        if (name.includes('gpt-4')) return 'gpt-4o';
        if (name.includes('claude')) return 'claude-opus-4-7';
        if (name.includes('mistral large')) return 'mistral-large-latest';
        if (name.includes('mistral medium')) return 'mistral-medium-latest';
        if (name.includes('mistral small')) return 'mistral-small-latest';
        if (name.includes('mistral')) return 'mistral-large-latest';

        if (provider === 'openai' && service.type === 'video') return 'sora-2';
        return '';
    }

    function buildRefreshPayloadService(service) {
        const payload = {
            id: service.id,
            name: service.name,
            provider: service.provider,
            type: service.type,
            unit: service.unit,
            // Transmis pour la résolution déterministe des prix curés côté serveur
            // (catalogue = source unique de vérité, ex. GPT-5.6 / Opus 4.8).
            catalog_key: service.catalog_key || '',
        };

        const llmInfo = parseLlmCatalogKey(service.catalog_key);
        const hasExplicitModel = llmInfo
            && String(llmInfo.model || '').toLowerCase() !== String(llmInfo.provider || '').toLowerCase();
        if (llmInfo && hasExplicitModel) {
            payload.provider = llmInfo.provider || payload.provider;
            payload.model = llmInfo.model;
            payload.direction = llmInfo.direction;
            payload.billing_hint = unitHint(service.unit);
            return payload;
        }

        if (service.type === 'llm_text') {
            const providerKey = String(service.provider || '').trim().toLowerCase();
            if (providerKey) payload.provider = providerKey;
            const modelGuess = inferModelFromService(service) || String(service.name || '').trim();
            if (modelGuess) payload.model = modelGuess;
            if (service.unit === '1m_input_tokens') payload.direction = 'input';
            if (service.unit === '1m_output_tokens') payload.direction = 'output';
            payload.billing_hint = unitHint(service.unit);
            return payload;
        }

        const toolId = parseToolCatalogKey(service.catalog_key);
        if (!toolId) {
            const providerKey = String(service.provider || '').trim().toLowerCase();
            if (providerKey) payload.provider = providerKey;
            const inferredModel = inferModelFromService(service);
            if (inferredModel) payload.model = inferredModel;
            const hints = [unitHint(service.unit)];
            if (service.unit === 'video_second') {
                hints.push('expected_unit=video_second');
                hints.push('paid_production_pricing_only=true');
            }
            payload.billing_hint = hints.join(' | ');
            return payload;
        }

        const tool = catalogIndexes.toolsById.get(toolId);
        if (!tool) {
            payload.billing_hint = unitHint(service.unit);
            return payload;
        }

        if (tool.provider) payload.provider = String(tool.provider).trim();
        if (tool.model) payload.model = String(tool.model).trim();
        const toolConfig = safeParseJsonObject(String(tool.config_json || ''));
        if (toolConfig) payload.tool_config_json = toolConfig;

        const hints = [unitHint(service.unit)];
        if (toolConfig && typeof toolConfig === 'object') {
            if (toolConfig.size) hints.push('size=' + String(toolConfig.size));
            if (toolConfig.quality) hints.push('quality=' + String(toolConfig.quality));
            if (toolConfig.aspect_ratio) hints.push('aspect_ratio=' + String(toolConfig.aspect_ratio));
            if (toolConfig.duration) hints.push('duration=' + String(toolConfig.duration));
        }
        if (service.unit === 'video_second') {
            hints.push('expected_unit=video_second');
            hints.push('paid_production_pricing_only=true');
        }
        payload.billing_hint = hints.join(' | ');

        return payload;
    }

    /* ------------------------------------------------------------------
     * Recherche de prix : job asynchrone
     *
     * La recherche vit dans la file de jobs côté serveur, et c'est le JOB qui
     * enregistre la configuration. Quitter l'écran, recharger ou fermer
     * l'onglet ne l'annule donc pas — la page ne fait que s'attacher à son
     * flux pour afficher l'avancement.
     * ------------------------------------------------------------------ */
    const PRICE_FLASH_KEY = 'miaCreditsPriceFlash';
    const REFRESH_LABEL = els.refreshPricesBtn ? els.refreshPricesBtn.textContent.trim() : '';
    let priceJobId = null;
    let priceJobStream = null;

    function setPriceJobRunning(running) {
        if (els.priceJobBanner) els.priceJobBanner.hidden = !running;
        if (els.priceJobCancelBtn) els.priceJobCancelBtn.disabled = false;
        if (!els.refreshPricesBtn) return;
        els.refreshPricesBtn.disabled = running;
        els.refreshPricesBtn.textContent = running ? L.searching : REFRESH_LABEL;
    }

    /**
     * S'attache au flux du job. EventSource plutôt que polling : reconnexion et
     * reprise du curseur (`Last-Event-ID`) sont gérées par le navigateur, rien
     * n'est perdu si le réseau hoquette pendant la recherche.
     */
    function followPriceJob(jobId) {
        if (priceJobStream) { priceJobStream.close(); }
        priceJobId = jobId;
        setPriceJobRunning(true);

        const es = new EventSource('/jobs/' + jobId + '/stream');
        priceJobStream = es;
        let payload = null;
        let errors = 0;

        es.onmessage = function (ev) {
            errors = 0;
            let json;
            try { json = JSON.parse(ev.data); } catch (_e) { return; }
            if (json.llm_error) {
                closePriceJob();
                showClientError(json.llm_error.message || L.searchFailed);
                return;
            }
            if (json.done) payload = json;
            if (json.job_end) {
                closePriceJob();
                finishPriceJob(String(json.status || ''), payload);
            }
        };
        es.onerror = function () {
            errors += 1;
            if (errors >= 6) {
                closePriceJob();
                showClientError(L.trackingLost);
            }
        };
    }

    function closePriceJob() {
        if (priceJobStream) { priceJobStream.close(); priceJobStream = null; }
        priceJobId = null;
        setPriceJobRunning(false);
    }

    function finishPriceJob(status, result) {
        if (status === 'cancelled' || (result && result.cancelled)) {
            showPriceFlash(L.refreshCancelled);
            return;
        }
        if (!result || !result.ok) {
            showClientError((result && result.message) || L.pricesUnavailable);
            return;
        }

        const applied = parseNumber(result.applied, 0);
        if (applied <= 0) {
            const nfCount = Array.isArray(result.not_found) ? result.not_found.length : 0;
            showClientError(L.noPriceFound + (nfCount > 0 ? ' ' + fill(L.servicesWithoutPrice, { n: nfCount }) : ''));
            return;
        }

        // Le job a déjà écrit la configuration : la page se recharge pour
        // l'afficher telle qu'elle est en base. Sans désarmer l'auto-save, un
        // envoi resté en attente reposterait l'état d'AVANT la recherche et
        // écraserait les prix tout juste enregistrés.
        try { if (window.MiaAutosave) window.MiaAutosave.detach(els.form); } catch (_e) {}
        try {
            sessionStorage.setItem(
                PRICE_FLASH_KEY,
                plural(applied, L.updatedOne, L.updatedMany)
                    + (result.engine ? ' (' + result.engine + ')' : '') + '.'
            );
        } catch (_e) {}
        window.location.reload();
    }

    function showPriceFlash(message) {
        if (!els.priceFlash) return;
        els.priceFlash.textContent = message;
        els.priceFlash.style.display = 'block';
    }

    /** Message posé avant le rechargement qui suit une mise à jour réussie. */
    function consumePriceFlash() {
        try {
            const message = sessionStorage.getItem(PRICE_FLASH_KEY);
            if (!message) return;
            sessionStorage.removeItem(PRICE_FLASH_KEY);
            showPriceFlash(message);
        } catch (_e) {}
    }

    /**
     * Une recherche lancée avant (autre écran, autre onglet, rechargement,
     * autre appareil) tourne-t-elle encore ? La file est la source de vérité :
     * un drapeau local ne verrait pas les trois derniers cas.
     */
    function restorePriceJob() {
        fetch('/jobs/active', { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                const jobs = data && Array.isArray(data.jobs) ? data.jobs : [];
                for (let i = 0; i < jobs.length; i++) {
                    if (jobs[i] && jobs[i].type === 'credits_refresh') {
                        followPriceJob(jobs[i].id);
                        return;
                    }
                }
            })
            .catch(function () { /* suivi indisponible : la recherche continue sans nous */ });
    }

    let savedState = normalizeConfig(bootstrap.config || {});
    let state = deepClone(savedState);
    let selectedServiceId = state.services[0] ? state.services[0].id : null;
    let simulator = {
        serviceId: selectedServiceId || '',
        quantity: 1,
        tokens: '',
    };
    let sortState = {
        key: null,
        direction: 'asc',
    };
    let syncFlashMessage = '';
    let currentView = 'services';

    bindEvents();
    renderAll();
    consumePriceFlash();
    restorePriceJob();
})();
</script>
</body>
</html>
