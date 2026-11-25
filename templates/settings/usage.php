<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Usage —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <link rel="stylesheet" href="/assets/css/usage-history.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/usage-history.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Usage & crédits'); $page = 'usage'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<!-- Contenu -->
<div class="admin-content">
    <?php require ROOT . '/templates/partials/admin-topbar.php'; ?>
    <?php $usageView = $usageView ?? 'month'; ?>
    <div class="pu-page<?= $usageView === 'history' ? ' is-history' : '' ?>">
        <?php // Même nom que l'entrée du menu (« Usage ») : un seul nom pour une page. ?>
        <header class="page-head">
        <div class="page-head-text">
            <h1 style="margin-bottom:6px"><?= te('Usage & crédits') ?></h1>
            <p class="page-head-sub">
            <?php if ($usageView === 'history'): ?>
            <?= te('Toutes vos opérations, mois après mois : filtrez, recherchez, exportez.') ?>
            <?php else: ?>
            <?= te('Consommation du mois de {mois}', ['mois' => Format::monthLabel(date('Y-m'))]) ?>
            <?php if (empty($quotaStatus['demo'])): // Démo : l'enveloppe ne se réinitialise pas. ?>
            · <?= te('Réinitialisation le {date}', ['date' => Format::dateFr(date('Y-m-d', strtotime($resetDate)))]) ?>
            (<?= htmlspecialchars(I18n::plural((int)$daysUntilReset, 'dans {n} jour', 'dans {n} jours')) ?>)
            <?php endif; ?>
            <?php endif; ?>
        </p>
        </div>
        </header>

        <?php // Deux onglets, une page : le mois en cours (solde, prévision) et l'historique (toutes les périodes). ?>
        <nav class="admin-tabs pu-tabs" aria-label="<?= te('Usage & crédits') ?>">
            <a href="/settings/usage" class="admin-tab<?= $usageView === 'month' ? ' active' : '' ?>"<?= $usageView === 'month' ? ' aria-current="page"' : '' ?>><?= te('Ce mois-ci') ?></a>
            <a href="/settings/usage?vue=<?= htmlspecialchars(UserSettingsController::USAGE_HISTORY_VIEW) ?>" class="admin-tab<?= $usageView === 'history' ? ' active' : '' ?>"<?= $usageView === 'history' ? ' aria-current="page"' : '' ?>><?= te('Historique') ?></a>
        </nav>

        <?php if ($usageView === 'history'): ?>
            <?php require ROOT . '/templates/settings/usage-history.php'; ?>
        <?php else: ?>

        <?php
        /**
         * Page « Usage » — lecture du grand livre (UsageLog) pour un
         * utilisateur : tout ce qui lui est imputé ce mois-ci, catégorie par
         * catégorie. AUCUN euro : la personne raisonne en crédits, comme son
         * quota (le coût réel est réservé à l'administration, page Budget).
         * Solde et prévision, anneaux, coûts moyens et conseils viennent de
         * PersonalUsage (formes pures) ; anneaux dessinés en SVG, sans script.
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
        $totalCredits   = (int)$breakdown['total_credits'];
        $quotaCredits   = (int)$breakdown['quota_credits'];
        $exemptCredits  = (int)$breakdown['exempt_credits'];
        // Formatage centralisé (source unique : src/Helpers/Format.php).
        $formatTokens = static fn(int $value): string => Format::tokens($value);
        $quantityLabel = static function (string $unit, float $quantity): string {
            if ($quantity <= 0) {
                return '';
            }
            return match ($unit) {
                UsageSource::UNIT_SEARCHES => I18n::plural((int)$quantity, '{n} recherche', '{n} recherches', ['n' => Format::credits((int)$quantity)]),
                UsageSource::UNIT_MINUTES  => t('{n} min', ['n' => number_format($quantity, 1, ',', ' ')]),
                'units'                    => I18n::plural((int)$quantity, '{n} unité', '{n} unités', ['n' => Format::credits((int)$quantity)]),
                default                    => '',
            };
        };
        ?>

        <?php if (isset($quotaStatus) && ($quotaStatus['reason'] ?? null) === CreditQuota::REASON_BUDGET): ?>
            <!-- Budget IA de l'organisation atteint : même message que la modale du chat, sans montant. -->
            <div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars(CreditQuota::refusal($quotaStatus)['message']) ?></div>
        <?php endif; ?>


        <?php
        // ---- Solde et prévision ---------------------------------------------
        $fc = $forecast;
        $resetShort = Format::shortDate(new DateTimeImmutable($resetDate));
        $monthEndShort = Format::shortDate(new DateTimeImmutable(date('Y-m-t')));
        [$pillClass, $pillText] = match ($fc['state']) {
            PersonalUsage::STATE_OK        => ['is-ok', t('Votre quota tiendra jusqu’à la fin du mois')],
            PersonalUsage::STATE_TIGHT     => ['is-warn', t('À ce rythme, quota atteint vers le {date}', ['date' => Format::shortDate(new DateTimeImmutable((string)$fc['reach_date']))])],
            PersonalUsage::STATE_REACHED   => ['is-danger', t('Quota atteint — nouveau quota le {date}', ['date' => $resetShort])],
            PersonalUsage::STATE_EARLY     => ['is-muted', t('Prévision à partir du 3 du mois')],
            default                        => ['is-muted', t('Quota illimité')],
        };
        $catIcons = [
            UsageSource::CAT_CHAT        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            UsageSource::CAT_OFFICE      => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/>',
            UsageSource::CAT_CHAT_ASSIST => '<path d="M12 3l1.8 4.6L18 9l-4.2 1.4L12 15l-1.8-4.6L6 9l4.2-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
            UsageSource::CAT_WEB_SEARCH  => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            UsageSource::CAT_CREATION    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            UsageSource::CAT_VOICE       => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/>',
            UsageSource::CAT_KNOWLEDGE   => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            UsageSource::CAT_DESIGN      => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/>',
            UsageSource::CAT_ADMIN       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        ];
        $svg = static fn(string $paths, int $size = 16): string =>
            '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
        $sharePct = static fn(float $v): string => $v > 0 && $v < 1 ? '<1 %' : number_format($v, 0, ',', ' ') . ' %';
        ?>

        <!-- ================= Solde et prévision ================= -->
        <?php if (!empty($quotaStatus['demo'])): ?>
        <?php
        // Compte de démo : une enveloppe sans renouvellement — ni prévision
        // mensuelle, ni date de fin. Juste ce qui reste (DemoAccount).
        $demoRemaining = (int)($quotaStatus['remaining'] ?? 0);
        $demoMax       = (int)($quotaStatus['quota_max'] ?? 0);
        $demoUsed      = (int)($quotaStatus['used'] ?? 0);
        $demoPct       = (int)($quotaStatus['percent'] ?? 0);
        $demoEnded     = ($quotaStatus['reason'] ?? null) === CreditQuota::REASON_QUOTA;
        $demoEmail     = DemoAccount::contactEmail();
        ?>
        <section class="card pu-hero" aria-labelledby="puBalance">
            <div class="pu-hero-top">
                <span class="pu-pill <?= $demoEnded ? 'is-danger' : 'is-muted' ?>"><?= $demoEnded ? te('Démo terminée') : te('Compte de démonstration') ?></span>
            </div>
            <p class="pu-balance" id="puBalance"><?= Format::credits($demoRemaining) ?> <span><?= te('crédits de démo restants sur {max}', ['max' => Format::credits($demoMax)]) ?></span></p>
            <div class="pu-gauge <?= $demoEnded ? 'is-danger' : 'is-ok' ?>" role="img"
                 aria-label="<?= te('{pct} % de vos crédits de démo utilisés', ['pct' => $demoPct]) ?>">
                <span class="pu-gauge-fill" style="width:<?= max($demoPct, $demoUsed > 0 ? 1 : 0) ?>%"></span>
            </div>
            <?php if ($demoEnded): ?>
                <p class="pu-note"><strong><?= htmlspecialchars(DemoAccount::endedTitle()) ?></strong> — <?= htmlspecialchars(DemoAccount::endedMessageForInstance()) ?></p>
                <?php if ($demoEmail !== ''): ?>
                    <p style="margin:12px 0 0"><a class="btn-primary demo-ended-mail" href="<?= htmlspecialchars(DemoAccount::mailtoHref($demoEmail)) ?>"><?= te('Écrire à {email}', ['email' => $demoEmail]) ?></a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="card pu-hero" aria-labelledby="puBalance">
            <div class="pu-hero-top">
                <span class="pu-pill <?= $pillClass ?>"><?= htmlspecialchars($pillText) ?></span>
            </div>
            <?php if ($fc['quota'] !== null): ?>
                <p class="pu-balance" id="puBalance"><?= Format::credits((int)$fc['remaining']) ?> <span><?= te('crédits restants') ?></span></p>
                <p class="pu-sub">
                    <?= te('sur {quota} ce mois-ci · {used} utilisés', ['quota' => Format::credits((int)$fc['quota']), 'used' => Format::credits((int)$fc['used'])]) ?>
                    <?php if ($fc['projected'] !== null && $fc['state'] !== PersonalUsage::STATE_REACHED): ?>
                        · <?= te('≈ {n} au {date} à ce rythme', ['n' => Format::credits((int)$fc['projected']), 'date' => $monthEndShort]) ?>
                    <?php endif; ?>
                </p>
                <div class="pu-gauge <?= $pillClass ?>" role="img"
                     aria-label="<?= te('{pct} % de votre quota utilisé', ['pct' => (int)$fc['pct']]) ?>">
                    <?php if ($fc['proj_pct'] !== null && $fc['proj_pct'] > $fc['pct'] && $fc['state'] !== PersonalUsage::STATE_REACHED): ?>
                        <span class="pu-gauge-proj" style="left:<?= (int)$fc['pct'] ?>%;width:<?= (int)$fc['proj_pct'] - (int)$fc['pct'] ?>%"></span>
                    <?php endif; ?>
                    <span class="pu-gauge-fill" style="width:<?= max((int)$fc['pct'], $fc['used'] > 0 ? 1 : 0) ?>%"></span>
                </div>
            <?php else: ?>
                <p class="pu-balance" id="puBalance"><?= Format::credits((int)$fc['used']) ?> <span><?= te('crédits utilisés ce mois-ci') ?></span></p>
                <?php if ($fc['projected'] !== null): ?>
                    <p class="pu-sub"><?= te('≈ {n} au {date} à ce rythme', ['n' => Format::credits((int)$fc['projected']), 'date' => $monthEndShort]) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <div class="pu-facts">
                <?php if ($fc['daily'] !== null): ?>
                    <span><?= te('Votre rythme : ≈ {n} crédits par jour', ['n' => Format::credits((int)round((float)$fc['daily']))]) ?></span>
                <?php endif; ?>
                <?php if ($fc['per_day_left'] !== null && $fc['state'] !== PersonalUsage::STATE_REACHED): ?>
                    <span><?= te('Disponible : ≈ {n} par jour jusqu’au {date}', ['n' => Format::credits((int)$fc['per_day_left']), 'date' => $monthEndShort]) ?></span>
                <?php endif; ?>
                <?php if ($fc['quota'] !== null): ?>
                    <span><?= htmlspecialchars(I18n::plural((int)$daysUntilReset, 'Nouveau quota dans {n} jour', 'Nouveau quota dans {n} jours')) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($exemptCredits > 0): ?>
                <p class="pu-note">
                    + <strong><?= Format::credits($exemptCredits) ?> <?= te('crédits hors quota') ?></strong> <?= te('ce mois (alimentation de vos bases de connaissances, tests d\'administration) : comptés dans votre consommation, jamais retirés de votre quota.') ?>
                </p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ================= Camemberts d'utilisation ================= -->
        <?php
        $renderRing = static function (array $ring, string $title, bool $withTotal) use ($sharePct): string {
            ob_start();
            $c = $ring['circumference'];
            ?>
            <div class="pu-ring">
                <?php if ($ring['slices'] === []): ?>
                    <div class="pu-ring-empty" aria-hidden="true"></div>
                    <p class="pu-ring-title"><?= htmlspecialchars($title) ?></p>
                    <p class="pu-muted"><?= te('Aucune donnée ce mois') ?></p>
                <?php else: ?>
                    <svg viewBox="0 0 100 100" role="img" aria-label="<?= htmlspecialchars($title . ' : ' . implode(', ', array_map(static fn(array $s): string => $s['label'] . ' ' . $sharePct((float)$s['share']), $ring['slices']))) ?>">
                        <circle class="pu-ring-track" cx="50" cy="50" r="<?= PersonalUsage::RING_RADIUS ?>"/>
                        <g transform="rotate(-90 50 50)">
                            <?php foreach ($ring['slices'] as $s): ?>
                                <circle class="pu-ring-seg" cx="50" cy="50" r="<?= PersonalUsage::RING_RADIUS ?>"
                                        stroke="<?= htmlspecialchars($s['color']) ?>"
                                        stroke-dasharray="<?= $s['dash'] ?> <?= $c ?>" stroke-dashoffset="<?= $s['offset'] ?>"><title><?= htmlspecialchars($s['label'] . ' : ' . Format::credits((int)round((float)$s['value'])) . ' ' . t('crédits')) ?></title></circle>
                            <?php endforeach; ?>
                        </g>
                        <?php if ($withTotal): ?>
                            <text x="50" y="50" class="pu-ring-total"><?= Format::credits((int)round((float)$ring['total'])) ?></text>
                            <text x="50" y="62" class="pu-ring-unit"><?= te('crédits') ?></text>
                        <?php endif; ?>
                    </svg>
                    <p class="pu-ring-title"><?= htmlspecialchars($title) ?></p>
                    <ul class="pu-legend">
                        <?php foreach ($ring['slices'] as $s): ?>
                            <li>
                                <i style="background:<?= htmlspecialchars($s['color']) ?>"></i>
                                <span><?= htmlspecialchars($s['label']) ?><?php if (!empty($s['exempt'])): ?> <em><?= te('hors quota') ?></em><?php endif; ?></span>
                                <b><?= htmlspecialchars($sharePct((float)$s['share'])) ?></b>
                            </li>
                            <?php // « Autres » dit ce qu'il regroupe : une petite consommation reste lisible. ?>
                            <?php if (!empty($s['members'])):
                                $members = array_slice($s['members'], 0, PersonalUsage::RING_MEMBERS_SHOWN);
                                $hidden  = count($s['members']) - count($members); ?>
                                <?php foreach ($members as $m): ?>
                                    <li class="pu-legend-member">
                                        <i style="background:<?= htmlspecialchars((string)($m['color'] ?? '')) ?>"></i>
                                        <span><?= htmlspecialchars((string)$m['label']) ?><?php if (!empty($m['exempt'])): ?> <em><?= te('hors quota') ?></em><?php endif; ?></span>
                                        <?php // Ses crédits plutôt que sa part : dans « Autres », chacune pèse moins de quelques %. ?>
                                        <b title="<?= htmlspecialchars($sharePct((float)$m['share'])) ?>"><?= te('{n} crédits', ['n' => Format::credits((int)round((float)$m['value']))]) ?></b>
                                    </li>
                                <?php endforeach; ?>
                                <?php if ($hidden > 0): ?>
                                    <li class="pu-legend-member is-more"><span></span><span><?= htmlspecialchars(I18n::plural($hidden, 'et {n} autre', 'et {n} autres')) ?></span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php
            return (string)ob_get_clean();
        };
        ?>
        <section class="card pu-card" aria-labelledby="puRingsTitle">
            <div class="card-head">
                <span class="card-ic"><?= $svg('<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>', 20) ?></span>
                <div class="card-head-text">
                    <h3 id="puRingsTitle"><?= te('Ce qui a consommé vos crédits') ?></h3>
                    <p><?= te('Part des {n} crédits utilisés ce mois-ci', ['n' => Format::credits($quotaCredits)]) ?></p>
                </div>
            </div>
            <?php if ($quotaCredits <= 0): ?>
                <p class="pu-muted"><?= te('Aucune opération ce mois.') ?></p>
            <?php else: ?>
                <div class="pu-rings">
                    <?= $renderRing($ringUsage, t('Par usage'), true) ?>
                    <?= $renderRing($ringAssistants, t('Par assistant'), false) ?>
                    <?= $renderRing($ringModels, t('Par modèle'), false) ?>
                </div>
                <?php if ($unitCosts !== []): ?>
                    <?php
                    $unitLabels = [
                        'answer' => static fn(int $n): string => t('une réponse ≈ {n} crédits', ['n' => Format::credits($n)]),
                        'image'  => static fn(int $n): string => t('une image ≈ {n}', ['n' => Format::credits($n)]),
                        'search' => static fn(int $n): string => t('une recherche Internet ≈ {n}', ['n' => Format::credits($n)]),
                        'minute' => static fn(int $n): string => t('une minute de voix ≈ {n}', ['n' => Format::credits($n)]),
                        'office' => static fn(int $n): string => t('une demande dans Office ≈ {n}', ['n' => Format::credits($n)]),
                    ];
                    $units = [];
                    foreach ($unitCosts as $k => $n) {
                        $units[] = $unitLabels[$k]($n);
                    }
                    ?>
                    <p class="pu-units"><?= te('En moyenne :') ?> <?= htmlspecialchars(implode(' · ', $units)) ?>.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- ================= Top 10 ================= -->
        <section class="card pu-card" aria-labelledby="puTopTitle">
            <div class="card-head">
                <span class="card-ic"><?= $svg('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>', 20) ?></span>
                <div class="card-head-text">
                    <h3 id="puTopTitle"><?= te('Top 10 — opérations les plus coûteuses ce mois') ?></h3>
                    <p><?= te('Une opération rattachée à une conversation la rouvre d’un clic.') ?></p>
                </div>
            </div>
            <?php if (!empty($top10)): ?>
                <div class="table-wrap">
                    <table class="pu-top table-stack">
                        <thead>
                            <tr>
                                <th><?= te('Opération') ?></th>
                                <th><?= te('Assistant') ?></th>
                                <th><?= te('Date') ?></th>
                                <th class="is-num"><?= te('Volume') ?></th>
                                <th class="is-num"><?= te('Crédits') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top10 as $row): ?>
                                <?php
                                $isLlm = ($row['op_type'] === 'llm');
                                if ($isLlm) {
                                    $category = UsageSource::category((string)$row['source']);
                                    $opLabel  = t(UsageSource::label((string)$row['source']));
                                } else {
                                    $category = UsageSource::toolCategory((string)$row['source']);
                                    $opLabel  = $row['source'] === UsageSource::TOOL_TEST
                                        ? t('Test d\'outil')
                                        : ($toolTypeLabels[(string)($row['tool_type'] ?? '')] ?? (string)$row['op_name']);
                                }
                                $rowTokens = (int)($row['tokens_total'] ?? 0);
                                $volume = $rowTokens > 0
                                    ? t('{n} tokens', ['n' => $formatTokens($rowTokens)])
                                    : ($isLlm ? $quantityLabel(UsageSource::unit((string)$row['source']), (float)$row['quantity']) : '');
                                $at = new DateTimeImmutable((string)$row['created_at']);
                                $color = UsageSource::categoryColor($category);
                                ?>
                                <tr>
                                    <td>
                                        <span class="pu-op">
                                            <span class="pu-op-ic" style="--op:<?= htmlspecialchars($color) ?>"><?= $svg($catIcons[$category] ?? $catIcons[UsageSource::CAT_CHAT], 14) ?></span>
                                            <?php if (!empty($row['conversation_id'])): ?>
                                                <a href="/chat/<?= (int)$row['conversation_id'] ?>"><?= htmlspecialchars($opLabel) ?></a>
                                            <?php else: ?>
                                                <span><?= htmlspecialchars($opLabel) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="pu-muted"><?= !empty($row['profile_name']) ? htmlspecialchars((string)$row['profile_name']) : '—' ?></td>
                                    <td class="pu-muted pu-nowrap"><?= htmlspecialchars(Format::shortDate($at) . ' · ' . Format::time($at->format('H:i'))) ?></td>
                                    <td class="is-num pu-muted"><?= $volume !== '' ? htmlspecialchars($volume) : '—' ?></td>
                                    <td class="is-num"><strong><?= Format::credits((int)$row['credits_used']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="pu-muted"><?= te('Aucune opération facturée ce mois.') ?></p>
            <?php endif; ?>
            <p class="pu-more-link"><a href="/settings/usage?vue=<?= htmlspecialchars(UserSettingsController::USAGE_HISTORY_VIEW) ?>&amp;preset=current_month"><?= te('Toutes les opérations du mois, et des précédents, dans l’historique →') ?></a></p>
        </section>

        <!-- ================= Workflows ================= -->
        <?php if ($workflowRows !== []): ?>
        <section class="card pu-card" aria-labelledby="puWorkflowsTitle">
            <div class="card-head">
                <span class="card-ic"><?= $svg(Workflow::ICON_PATHS, 20) ?></span>
                <div class="card-head-text">
                    <h3 id="puWorkflowsTitle"><?= te('Vos workflows ce mois-ci') ?></h3>
                    <p><?= $quotaCredits > 0
                        ? te('{n} crédits, soit {part} % de vos crédits du mois. Ils sont déjà comptés ci-dessus, surtout dans « Conversations ».', [
                            'n' => Format::credits((int)$workflowCredits),
                            'part' => (int)round($workflowCredits / $quotaCredits * 100),
                        ])
                        : te('{n} crédits. Ils sont déjà comptés ci-dessus, surtout dans « Conversations ».', ['n' => Format::credits((int)$workflowCredits)]) ?></p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="pu-top table-stack">
                    <thead>
                        <tr>
                            <th><?= te('Workflow') ?></th>
                            <th class="is-num"><?= te('Exécutions') ?></th>
                            <th class="is-num"><?= te('Crédits') ?></th>
                            <th class="is-num" title="<?= te('Crédits par exécution, en moyenne') ?>"><?= te('Par exécution') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workflowRows as $w): ?>
                            <tr>
                                <td>
                                    <span class="pu-op">
                                        <i class="pu-wf-dot"<?= $w['color'] !== '' ? ' style="background:' . htmlspecialchars($w['color']) . '"' : '' ?>></i>
                                        <?php if ($workflowLinks && $w['owner_id'] === $userId): ?>
                                            <a href="/workflows?vue=historique&amp;workflow=<?= (int)$w['workflow_id'] ?>" title="<?= te('Voir ses exécutions') ?>"><?= htmlspecialchars($w['name']) ?></a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($w['name']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="is-num pu-muted"><?= Format::credits((int)$w['runs']) ?></td>
                                <td class="is-num"><strong><?= Format::credits((int)$w['credits']) ?></strong></td>
                                <td class="is-num pu-muted"><?= Format::credits((int)round((int)$w['credits'] / max(1, (int)$w['runs']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- ================= Conseils ================= -->
        <?php if ($tips !== []): ?>
        <section class="card pu-card pu-tips" aria-labelledby="puTipsTitle">
            <div class="card-head">
                <span class="card-ic"><?= $svg('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/>', 20) ?></span>
                <div class="card-head-text">
                    <h3 id="puTipsTitle"><?= te('Pour faire durer vos crédits') ?></h3>
                    <p><?= te('D’après votre usage ce mois-ci.') ?></p>
                </div>
            </div>
            <ul>
                <?php foreach ($tips as $tip): $p = $tip['params']; ?>
                    <li><?php
                        echo match ($tip['key']) {
                            'model'     => te('Une réponse de {cher} coûte ≈ {n_cher} crédits, de {econome} ≈ {n_econome} : le second suffit souvent aux questions simples.', [
                                'cher' => (string)$p['cher'], 'n_cher' => Format::credits((int)$p['n_cher']),
                                'econome' => (string)$p['econome'], 'n_econome' => Format::credits((int)$p['n_econome'])]),
                            'web'       => te('La recherche Internet représente {part} % de vos crédits : décochez « Recherche Internet » dans le menu « + » quand la question n’en a pas besoin.', ['part' => (int)$p['part']]),
                            'regen'     => te('Vous relancez {part} % de vos réponses : préciser la demande dès le départ économise ces crédits.', ['part' => (int)$p['part']]),
                            'creations' => te('Les créations (images, pages web, présentations) représentent {part} % de vos crédits : une consigne précise avant de générer évite de recommencer.', ['part' => (int)$p['part']]),
                            default     => '',
                        };
                    ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <!-- Détail technique par modèle (tokens cached/reasoning) -->
        <?php if (!empty($llmByModel)): ?>
        <details class="card" style="margin-bottom:20px;padding:0">
            <summary style="padding:18px 20px;cursor:pointer;font-size:14px;font-weight:600;list-style:none;display:flex;justify-content:space-between;align-items:center">
                <span><?= te('Détail technique par modèle') ?></span>
                <span style="font-size:12px;color:var(--text-muted);font-weight:400"><?= te('tokens cached · reasoning · crédits') ?></span>
            </summary>
            <div style="padding:0 20px 20px;overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border)">
                            <th style="text-align:left;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px"><?= te('Modèle') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px"><?= te('Op.') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px"><?= te('Input') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px" title="<?= te('Tokens lus depuis le cache (tarif réduit)') ?>"><?= te('Cached') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px"><?= te('Output') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px" title="<?= te('Tokens de raisonnement (modèles thinking)') ?>"><?= te('Reasoning') ?></th>
                            <th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--text-muted);font-size:12px"><?= te('Crédits') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($llmByModel as $row): ?>
                            <?php
                            $providerLabel = $providerLabels[$row['provider']] ?? ucfirst((string)$row['provider']);
                            $modelLabel = $row['model'] !== '' ? $providerLabel . ' · ' . $row['model'] : $providerLabel;
                            ?>
                            <tr style="border-bottom:1px solid var(--border)">
                                <td style="padding:8px 10px"><?= htmlspecialchars($modelLabel) ?></td>
                                <td style="padding:8px 10px;text-align:right;color:var(--text-muted)"><?= number_format((int)$row['requests']) ?></td>
                                <td style="padding:8px 10px;text-align:right"><?= (int)$row['tokens_in'] > 0 ? $formatTokens((int)$row['tokens_in']) : '—' ?></td>
                                <td style="padding:8px 10px;text-align:right;color:var(--text-muted)"><?= (int)$row['tokens_in_cached'] > 0 ? $formatTokens((int)$row['tokens_in_cached']) : '—' ?></td>
                                <td style="padding:8px 10px;text-align:right"><?= (int)$row['tokens_out'] > 0 ? $formatTokens((int)$row['tokens_out']) : '—' ?></td>
                                <td style="padding:8px 10px;text-align:right;color:var(--text-muted)"><?= (int)$row['tokens_out_reasoning'] > 0 ? $formatTokens((int)$row['tokens_out_reasoning']) : '—' ?></td>
                                <td style="padding:8px 10px;text-align:right;font-weight:600"><?= number_format((int)$row['credits']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php endif; // vue « Ce mois-ci » ?>
    </div><!-- /pu-page -->
</div><!-- /admin-content -->

<?php if ($usageView === 'history'): ?>
<script src="/assets/js/period-picker.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/period-picker.js') ?: 1) ?>"></script>
<script src="/assets/js/usage-history.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/usage-history.js') ?: 1) ?>"></script>
<?php endif; ?>

</body>
</html>
