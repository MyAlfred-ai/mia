<?php
/**
 * Workflows (/workflows) : la liste.
 * Variables fournies par WorkflowController::index :
 *   @var string      $view       'mes' | 'historique' | 'modeles'
 *   @var list<array> $workflows  workflows de la personne (Workflow::forUser)
 *   @var ?array      $history    onglet « Historique des exécutions » (WorkflowController::historyPage)
 *   @var list<array> $waiting    exécutions qui attendent la personne
 *   @var list<array> $templates  modèles livrés (WorkflowTemplates::catalog)
 *   @var ?string     $success, $error
 *   @var array       $catalog    icônes, teintes, libellés
 *
 *   @var array<int, array> $coverProfiles  assistants montrés au pied des vignettes
 *
 * Chaque vignette (« la couverture », WorkflowCover) porte une couverture à la
 * couleur du workflow où son déroulé est tracé en ligne de métro, puis
 * « Quand » et « Résultat ». Toute la vignette ouvre le workflow : le nom
 * porte un lien étiré ; Lancer, la pastille d'état et la pause passent
 * au-dessus (.wf-over). Les modèles prennent la même couverture, avec les
 * briques qu'ils mettent en avant ; « Utiliser ce modèle » est leur seul geste
 * (il crée un workflow : pas de lien étiré). L'historique des exécutions —
 * la liste des lancements et le journal de chacun — a son propre gabarit
 * (partials/history.php).
 */
$mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$wfCssVersion   = @filemtime(ROOT . '/public/assets/css/workflows.css') ?: 1;
$wfJsVersion    = @filemtime(ROOT . '/public/assets/js/workflows.js') ?: 1;
$histJsVersion  = @filemtime(ROOT . '/public/assets/js/workflow-history.js') ?: 1;
$previewJsVersion = @filemtime(ROOT . '/public/assets/js/html-preview.js') ?: 1;
$icons = $catalog['icons'];
// Droits du rôle sur ses workflows (Personnes → Rôles) : créer, modifier,
// lancer. Le serveur refuse de toute façon ce qu'un bouton absent ne propose pas.
$wfUser   = Session::currentUser();
$wfCanNew = Workflow::allows($wfUser, 'create');
$wfCanEdit = Workflow::allows($wfUser, 'edit');
$wfCanRun = Workflow::allows($wfUser, 'use');
$countLine = static function (array $c): string {
    $parts = [I18n::plural($c['steps'], '{n} étape', '{n} étapes')];
    if ($c['conditions'] > 0) {
        $parts[] = I18n::plural($c['conditions'], '{n} condition', '{n} conditions');
    }
    if (($c['routes'] ?? 0) > 0) {
        $parts[] = I18n::plural($c['routes'], '{n} décision', '{n} décisions');
    }
    if ($c['gates'] > 0) {
        $parts[] = I18n::plural($c['gates'], '{n} validation', '{n} validations');
    }
    if ($c['actions'] > 0) {
        $parts[] = I18n::plural($c['actions'], '{n} action', '{n} actions');
    }
    if (($c['creations'] ?? 0) > 0) {
        $parts[] = I18n::plural($c['creations'], '{n} création', '{n} créations');
    }
    return implode(' · ', $parts);
};
// Briques mises en avant sur la vignette d'un modèle (mêmes noms que dans l'éditeur).
$featureLabels = $catalog['labels']['kinds'] ?? [];
// `$short` : l'étiquette au téléphone, quand la longue ferait défiler la rangée.
$tab = static fn(string $v, string $label, int $n = 0, string $short = ''): string => '<a href="/workflows' . ($v === 'mes' ? '' : '?vue=' . $v) . '"' . ($view === $v ? ' class="is-on" aria-current="page"' : '') . '>'
    . ($short !== '' ? '<span class="wf-tab-long">' . htmlspecialchars($label) . '</span><span class="wf-tab-short" aria-hidden="true">' . htmlspecialchars($short) . '</span>' : htmlspecialchars($label))
    . ($n > 0 ? '<em>' . $n . '</em>' : '') . '</a>';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Workflows —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/workflows.css?v=<?= (int)$wfCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Workflows'); $page = 'workflows'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content wf-content<?= $view === 'historique' ? ' wf-hx-content' : '' ?>">
    <div class="wf-page-head">
        <div>
            <h1><span class="wf-h-ic"><?= Workflow::icon(20) ?></span><?= te('Workflows') ?><?= Workflow::betaChip() ?></h1>
            <p class="wf-lead"><?= te('Un travail en plusieurs étapes, mené par MIA et vos assistants : chaque étape remet une pièce au dossier, la suivante la reprend, et vous validez quand il le faut.') ?></p>
        </div>
        <?php if ($workflows !== [] && $wfCanNew): ?>
            <a class="wf-btn primary" href="/workflows/new"><?= $icons['plus'] ?><?= te('Nouveau workflow') ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($success)): ?><div class="wf-flash ok" role="status"><?= htmlspecialchars((string)$success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="wf-flash err" role="alert"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>

    <?php if ($waiting !== []): ?>
    <div class="wf-attn" aria-label="<?= te('Ce qui vous attend') ?>">
        <?php foreach ($waiting as $r): ?>
        <a class="wf-attn-item" href="/workflows/runs/<?= (int)$r['id'] ?>">
            <span class="wf-round"><?= $icons['gate'] ?></span>
            <div>
                <b><?= htmlspecialchars((string)$r['workflow_name']) ?></b>
                <small><?= te('Attend votre décision — lancé le {date}', ['date' => WorkflowRun::startedLabel($r)]) ?></small>
            </div>
            <span class="wf-btn sm"><?= te('Ouvrir') ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <nav class="wf-tabs" aria-label="<?= te('Vues') ?>">
        <?= $tab('mes', t('Mes workflows'), count($workflows)) ?>
        <?= $tab('historique', t('Historique des exécutions'), 0, t('Historique')) ?>
        <?= $tab('modeles', t('Modèles'), count($templates)) ?>
    </nav>

    <?php if ($view === 'mes'): ?>
        <?php if ($workflows === []): ?>
        <div class="wf-empty">
            <?= WorkflowCatalog::silhouetteHtml('dsgsxe') ?>
            <h2><?= te('Votre premier workflow') ?></h2>
            <p><?= te('Décrivez un processus à MIA en deux phrases, partez d’un modèle, ou composez-le brique par brique.') ?></p>
            <?php if ($wfCanNew): ?>
            <a class="wf-btn primary" href="/workflows/new"><?= $icons['plus'] ?><?= te('Créer un workflow') ?></a>
            <?php endif; ?>
        </div>
        <?php else:
            // L'exécution qui vous attend, par workflow (la plus récente) : elle prime sur la dernière.
            $waitingBy = [];
            foreach ($waiting as $r) {
                $waitingBy[(int)$r['workflow_id']] ??= $r;
            }
        ?>
        <div class="wf-grid">
            <?php foreach ($workflows as $w):
                $def = $w['published'] ?? $w['draft'];
                $c = WorkflowSpec::counts($def);
                $st = WorkflowCover::state($w, ($w['last_run_status'] ?? null) === WorkflowRun::WAITING && WorkflowRun::timerAt((int)$w['last_run_id']) !== null, $waitingBy[(int)$w['id']] ?? null);
                // Vue détaillée : l'éditeur ; sans droit de modifier, la dernière exécution.
                $cardHref = $wfCanEdit ? '/workflows/' . (int)$w['id'] . '/edit' : $st['href'];
                $watchErr = $w['published'] !== null && ($def['start']['event'] ?? null) !== null && WorkflowTriggers::watches($w['published'])
                    ? (string)(Workflow::watchState($w)['error'] ?? '') : '';
                $result = WorkflowCover::result($def);
                $team = [];
                foreach (WorkflowCover::profileIds($def) as $pid) {
                    if (isset($coverProfiles[$pid])) {
                        $team[] = $coverProfiles[$pid];
                    }
                }
                $cardClass = 'wf-cover-card' . ($w['published'] === null ? ' is-draft' : ($st['tone'] === 'pause' ? ' is-paused' : ''));
            ?>
            <article class="<?= $cardClass ?>" style="--hue:<?= htmlspecialchars($w['color']) ?>;--cover:<?= htmlspecialchars(WorkflowCover::coverColor((string)$w['color'])) ?>">
                <div class="wf-cc-cover">
                    <div class="wf-cc-art"><?= WorkflowCover::svg($def, $st['ring'] !== '' ? $st['key'] : null, $st['ring']) ?></div>
                    <?php if ($st['href'] !== null): ?>
                        <a class="wf-cc-state wf-over is-<?= htmlspecialchars($st['tone']) ?>" href="<?= htmlspecialchars($st['href']) ?>" title="<?= te('Ouvrir l’exécution') ?>"><i aria-hidden="true"></i><?= htmlspecialchars($st['label']) ?></a>
                    <?php else: ?>
                        <span class="wf-cc-state is-<?= htmlspecialchars($st['tone']) ?>"><i aria-hidden="true"></i><?= htmlspecialchars($st['label']) ?></span>
                    <?php endif; ?>
                    <span class="wf-cc-trig<?= $watchErr !== '' ? ' is-err' : '' ?>" aria-hidden="true"><?= $icons[$watchErr !== '' ? 'alert' : WorkflowCover::triggerIcon($def)] ?? '' ?></span>
                </div>
                <div class="wf-cc-body">
                    <h3><?php if ($cardHref !== null): ?><a class="wf-cc-link" href="<?= htmlspecialchars($cardHref) ?>"><?= htmlspecialchars($w['name']) ?></a><?php else: ?><?= htmlspecialchars($w['name']) ?><?php endif; ?></h3>
                    <dl class="wf-cc-qa">
                        <div><dt><?= te('Quand') ?></dt><dd<?= $watchErr !== '' ? ' class="is-err"' : '' ?>><?= htmlspecialchars($watchErr !== '' ? t('Surveillance interrompue : {cause}', ['cause' => $watchErr]) : WorkflowCover::when($def)) ?></dd></div>
                        <?php if ($result !== ''): ?>
                        <div><dt><?= te('Résultat') ?></dt><dd><?= htmlspecialchars($result) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <div class="wf-cc-foot">
                        <span class="wf-cc-avas" title="<?= htmlspecialchars($team === [] ? 'MIA' : implode(', ', array_column($team, 'name'))) ?>">
                            <?php if ($team === []): ?>
                                <span class="wf-ava mia"><?= $icons['mia'] ?></span>
                            <?php endif; ?>
                            <?php foreach (array_slice($team, 0, WorkflowCover::MAX_AVATARS) as $p): ?>
                                <span class="wf-ava" style="--av:<?= htmlspecialchars($p['color'] !== '' ? $p['color'] : Workflow::DEFAULT_COLOR) ?>"><?php if ($p['avatar'] !== ''): ?><img src="<?= htmlspecialchars($p['avatar']) ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?><?php endif; ?></span>
                            <?php endforeach; ?>
                            <?php if (count($team) > WorkflowCover::MAX_AVATARS): ?><em>+<?= count($team) - WorkflowCover::MAX_AVATARS ?></em><?php endif; ?>
                        </span>
                        <span class="wf-cc-count" title="<?= htmlspecialchars($countLine($c)) ?>"><?= htmlspecialchars($countLine($c)) ?></span>
                        <span class="wf-cc-acts">
                            <?php if ($w['published'] !== null && $w['is_active'] && $wfCanRun): ?>
                                <button type="button" class="wf-btn sm primary wf-over" data-launch="<?= (int)$w['id'] ?>" aria-label="<?= te('Lancer « {nom} »', ['nom' => $w['name']]) ?>"><?= $icons['spark'] ?><?= te('Lancer') ?></button>
                            <?php endif; ?>
                            <?php if ($w['published'] !== null && $wfCanEdit): ?>
                            <form method="post" action="/workflows/<?= (int)$w['id'] ?>/toggle" class="wf-over">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                                <?php if ($w['is_active']): ?>
                                    <button type="submit" class="wf-btn sm ghost icon" title="<?= te('Mettre en pause') ?>" aria-label="<?= te('Mettre en pause « {nom} »', ['nom' => $w['name']]) ?>"><?= $icons['pause'] ?></button>
                                <?php else: ?>
                                    <button type="submit" class="wf-btn sm"><?= te('Réactiver') ?></button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'historique'): ?>
        <?php require ROOT . '/templates/workflows/partials/history.php'; ?>

    <?php else: ?>
        <p class="wf-lead" style="margin-bottom:16px"><?= te('Des workflows prêts à l’emploi : choisissez-en un, il s’ouvre dans l’éditeur pour que vous le régliez à votre façon avant de le publier.') ?></p>
        <div class="wf-grid">
            <?php foreach ($templates as $tpl):
                $tdef = $tpl['definition'];
                $tresult = WorkflowCover::result($tdef);
            ?>
            <article class="wf-cover-card is-template" style="--hue:<?= htmlspecialchars($tpl['color']) ?>;--cover:<?= htmlspecialchars(WorkflowCover::coverColor((string)$tpl['color'])) ?>">
                <div class="wf-cc-cover">
                    <div class="wf-cc-art"><?= WorkflowCover::svg($tdef) ?></div>
                    <span class="wf-cc-state is-draft"><i aria-hidden="true"></i><?= te('Modèle de workflow') ?></span>
                    <span class="wf-cc-trig" aria-hidden="true"><?= $icons[WorkflowCover::triggerIcon($tdef)] ?? '' ?></span>
                </div>
                <div class="wf-cc-body">
                    <h3><?= htmlspecialchars($tpl['name']) ?></h3>
                    <p class="wf-cc-desc"><?= htmlspecialchars($tpl['description']) ?></p>
                    <dl class="wf-cc-qa">
                        <div><dt><?= te('Quand') ?></dt><dd><?= htmlspecialchars(WorkflowCover::when($tdef)) ?></dd></div>
                        <?php if ($tresult !== ''): ?>
                        <div><dt><?= te('Résultat') ?></dt><dd><?= htmlspecialchars($tresult) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if ($tpl['features'] !== []): ?>
                    <ul class="wf-cc-feats" aria-label="<?= te('Briques du modèle') ?>" title="<?= htmlspecialchars($countLine($tpl['counts'])) ?>">
                        <?php foreach ($tpl['features'] as $type): ?>
                        <li style="--k:<?= htmlspecialchars(WorkflowCatalog::HUES[$type] ?? Workflow::DEFAULT_COLOR) ?>"><?= $icons[$type] ?? '' ?><?= htmlspecialchars($featureLabels[$type] ?? $type) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <div class="wf-cc-foot">
                        <?php if ($wfCanNew): ?>
                        <form method="post" action="/workflows" class="wf-cc-acts">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                            <input type="hidden" name="mode" value="template">
                            <input type="hidden" name="template" value="<?= htmlspecialchars($tpl['key']) ?>">
                            <button type="submit" class="wf-btn sm primary" aria-label="<?= te('Utiliser le modèle « {nom} »', ['nom' => $tpl['name']]) ?>"><?= $icons['plus'] ?><?= te('Utiliser ce modèle') ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="/assets/js/workflows.js?v=<?= (int)$wfJsVersion ?>" defer></script>
<?php if ($view === 'historique'): ?><script src="/assets/js/html-preview.js?v=<?= (int)$previewJsVersion ?>" defer></script><script src="/assets/js/workflow-history.js?v=<?= (int)$histJsVersion ?>" defer></script><?php endif; ?>
</body>
</html>
