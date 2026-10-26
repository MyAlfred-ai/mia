<?php
/**
 * Espaces → Tâches programmées (/settings/schedules) : la liste.
 * Variables fournies par ScheduledTaskController::index :
 *   @var list<array> $rows            une ligne par tâche (cf. listRow)
 *   @var bool        $featureEnabled  proposé par l'administrateur (Outils)
 *   @var int         $activeCount     tâches actives de la personne
 *   @var int         $maxActive       plafond de tâches actives
 *   @var ?string     $success, $error messages de la redirection précédente
 *   @var string      $pushKey         clé publique VAPID de l'instance ('' = notifications indisponibles)
 *   @var list<array> $pushDevices     appareils inscrits du compte (cf. PushController::devices)
 *   @var bool        $isNative        page servie dans l'application mobile (WebView)
 *   @var ?array      $diagram         schéma des tâches (TaskDiagram : catalogue + configuration
 *                                     de chaque tâche) — null sans tâche
 *   @var bool        $autoAllowed     « Agir sans validation » proposé par l'administrateur
 *
 * Interrupteur Active / En pause et « Exécuter maintenant » : enregistrés au
 * clic par schedules.js (JSON) ; chaque geste reste un vrai formulaire, donc
 * utilisable sans JavaScript.
 *
 * Cliquer une tâche affiche son SCHÉMA à droite (déclencheur, assistant,
 * modèle, connecteurs, bases, outils) — dessiné par diagram.js (le moteur,
 * partagé avec le schéma d'un assistant) et schedule-diagram.js (ce qu'on
 * montre d'une tâche). Sur un écran étroit, le schéma se déplie sous la
 * tâche cliquée. Sans
 * JavaScript, le nom de la tâche reste un lien vers sa modification.
 */
$mainCssVersion     = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$schedulesJsVersion = @filemtime(ROOT . '/public/assets/js/schedules.js') ?: 1;
$engineJsVersion    = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$diagramJsVersion   = @filemtime(ROOT . '/public/assets/js/schedule-diagram.js') ?: 1;
// L'horloge des tâches programmées : tracé unique (ScheduledTask::ICON_PATHS).
$repeatIcon = ScheduledTask::icon(15);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Tâches programmées —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Tâches programmées'); $page = 'schedules'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content sched-content">
    <div class="sched-wrap<?= $diagram !== null ? ' has-diagram' : '' ?>">

        <div class="sched-head">
            <div class="sched-head-text">
                <h1><?= te('Tâches programmées') ?></h1>
                <p class="sched-lead"><?= te('Confiez une demande à MIA ou à un assistant : elle se répète toute seule, à l’heure choisie, et la réponse vous attend dans sa conversation.') ?></p>
            </div>
            <?php if ($featureEnabled && $canCreate && $rows !== []): ?>
                <a class="btn-primary sched-new-btn" href="/settings/schedules/new"><?= te('Nouvelle tâche') ?></a>
            <?php endif; ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success sched-alert" role="status"><?= htmlspecialchars((string)$success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error sched-alert" role="alert"><?= htmlspecialchars((string)$error) ?></div>
        <?php endif; ?>
        <?php if (!$featureEnabled): ?>
            <div class="alert alert-error sched-alert"><?= te('Les tâches programmées sont suspendues par l’administrateur : aucune ne s’exécute pour le moment.') ?></div>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <div class="card sched-empty">
                <span class="sched-empty-icon" aria-hidden="true"><?= $repeatIcon ?></span>
                <h2><?= te('Aucune tâche pour l’instant') ?></h2>
                <p><?= te('Exemples : chaque lundi à 8 h, le point sur vos mails de la semaine ; chaque matin, une revue de presse de votre secteur ; le 1er du mois, un bilan de vos rendez-vous.') ?></p>
                <p class="sched-empty-hint"><?= te('Une réponse vous plaît dans le chat ? Le bouton « Refaire régulièrement » sous la réponse la programme en un clic.') ?></p>
                <?php if ($featureEnabled && $canCreate): ?>
                    <a class="btn-primary" href="/settings/schedules/new"><?= te('Programmer une tâche') ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="sched-count"><?= htmlspecialchars(I18n::plural($activeCount, '{n} tâche active sur {max}', '{n} tâches actives sur {max}', ['max' => $maxActive])) ?></p>

            <div class="sched-split">
            <div class="sched-list" id="schedList">
                <?php foreach ($rows as $row): ?>
                <article class="sched-item<?= $row['active'] ? '' : ' is-paused' ?>" id="task-<?= (int)$row['id'] ?>" data-task-id="<?= (int)$row['id'] ?>">
                    <form class="sched-toggle-form" method="POST" action="/settings/schedules/<?= (int)$row['id'] ?>/toggle">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="active" value="0">
                        <label class="ios-switch" title="<?= te('Active ou en pause') ?>">
                            <input type="checkbox" class="sched-toggle" name="active" value="1"<?= $row['active'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>
                                   aria-label="<?= te('Tâche active') ?>">
                            <span class="ios-switch-slider"></span>
                        </label>
                        <noscript><button type="submit" class="btn-secondary sched-noscript-btn"><?= te('Appliquer') ?></button></noscript>
                    </form>

                    <div class="sched-item-main">
                        <?php if ($canEdit): ?>
                        <a class="sched-item-name" href="/settings/schedules/<?= (int)$row['id'] ?>/edit"><?= htmlspecialchars($row['name']) ?></a>
                        <?php else: ?>
                        <span class="sched-item-name"><?= htmlspecialchars($row['name']) ?></span>
                        <?php endif; ?>
                        <?php if ($row['sentence'] !== ''): ?>
                            <div class="sched-item-rule"><?= $repeatIcon ?><span><?= htmlspecialchars($row['sentence']) ?></span></div>
                        <?php endif; ?>
                        <div class="sched-item-meta">
                            <span><?= htmlspecialchars($row['assistant']) ?></span>
                            <?php if ($row['auto']): ?>
                                <span class="sched-dot" aria-hidden="true">•</span>
                                <?php if ($autoAllowed): ?>
                                    <span class="badge sched-auto-badge" title="<?= $row['auto_send'] ? te('Effectue ses actions elle-même, emails envoyés compris.') : te('Effectue ses actions elle-même ; les emails restent en brouillon.') ?>"><?= te('Agit sans validation') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-gray" title="<?= te('L’administrateur a retiré cette option : ses actions attendent votre validation.') ?>"><?= te('Sans validation : retiré par l’administrateur') ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span class="sched-dot" aria-hidden="true">•</span>
                            <span class="sched-item-state" data-role="state">
                                <?php if ($row['active']): ?>
                                    <?= te('Prochaine : {date}', ['date' => $row['next']]) ?>
                                <?php else: ?>
                                    <?= te('En pause') ?><?= $row['pause'] !== '' && !$row['pause_user'] ? ' — ' . htmlspecialchars($row['pause']) : '' ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($row['pending'] > 0 && $row['thread_id'] !== null): ?>
                            <div class="sched-item-issues">
                                <a class="badge badge-yellow sched-pending" href="/chat/<?= (int)$row['thread_id'] ?>">
                                    <?= htmlspecialchars(I18n::plural($row['pending'], '{n} action à approuver', '{n} actions à approuver')) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php if ($row['issues'] !== []): ?>
                            <div class="sched-item-issues">
                                <?php foreach ($row['issues'] as $issue): ?>
                                    <span class="badge badge-yellow"><?= htmlspecialchars($issue) ?></span>
                                <?php endforeach; ?>
                                <a href="/settings/connections"><?= te('Ouvrir Comptes connectés') ?></a>
                            </div>
                        <?php endif; ?>
                        <?php if ($row['last'] !== null): ?>
                            <div class="sched-item-last sched-run-<?= htmlspecialchars($row['last']['status']) ?>">
                                <?= te('Dernière exécution : {statut}', ['statut' => $row['last']['label']]) ?><?= $row['last']['reason'] !== '' ? ' (' . htmlspecialchars($row['last']['reason']) . ')' : '' ?>
                                · <?= htmlspecialchars($row['last']['when']) ?>
                                <?php if ($row['last']['conversation_id'] !== null): ?>
                                    · <a href="/chat/<?= (int)$row['last']['conversation_id'] ?>"><?= te('Voir le résultat') ?></a>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($row['thread_id'] !== null): ?>
                            <div class="sched-item-last"><a href="/chat/<?= (int)$row['thread_id'] ?>"><?= te('Ouvrir la conversation') ?></a></div>
                        <?php endif; ?>
                        <p class="sched-item-error" data-role="error" hidden></p>
                    </div>

                    <div class="sched-item-actions">
                        <?php if ($featureEnabled && $canRun): ?>
                        <form class="sched-run-form" method="POST" action="/settings/schedules/<?= (int)$row['id'] ?>/run">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary"><?= te('Exécuter maintenant') ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                        <a class="btn-secondary" href="/settings/schedules/<?= (int)$row['id'] ?>/edit"><?= te('Modifier') ?></a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <?php /* Schéma de la tâche cliquée : rempli par schedules.js (rien
                     sans JavaScript — la tâche s'ouvre alors par son nom). */ ?>
            <aside class="sched-diagram-panel" id="schedDiagramPanel" aria-labelledby="schedDiagramTitle" hidden>
                <div class="sched-diagram-head">
                    <div class="sched-diagram-head-text">
                        <span class="sched-diagram-kicker"><?= te('Configuration de la tâche') ?></span>
                        <h2 id="schedDiagramTitle"></h2>
                    </div>
                    <a class="btn-secondary sched-diagram-edit" id="schedDiagramEdit" href="/settings/schedules"<?= $canEdit ? '' : ' hidden' ?>><?= te('Modifier') ?></a>
                    <button type="button" class="sched-diagram-close" id="schedDiagramClose" aria-label="<?= te('Fermer le schéma') ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="sched-diagram" id="schedDiagram"></div>
            </aside>
            </div>
        <?php endif; ?>

        <?php if ($featureEnabled): ?>
        <?php /* Notifications sur les appareils (Web Push). L'état affiché est
                 celui du NAVIGATEUR, calculé par schedules.js ; la liste des
                 appareils vient du serveur. Jamais l'adresse d'envoi ni les
                 clés à l'écran. */ ?>
        <section class="card sched-push" id="schedPush" aria-labelledby="schedPushTitle"
                 data-vapid-key="<?= htmlspecialchars($pushKey) ?>"
                 data-user-id="<?= (int)$user['id'] ?>"
                 data-native="<?= $isNative ? '1' : '0' ?>"
                 data-app-name="<?= htmlspecialchars((string)Settings::get('app_name', 'MIA')) ?>">
            <div class="card-head">
                <span class="card-ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
                <div class="card-head-text">
                    <h3 id="schedPushTitle"><?= te('Notifications') ?></h3>
                    <p><?= te('Soyez prévenu sur vos appareils quand une tâche a un résultat, même {app} fermée. La notification dit ce qui s’est passé, jamais le contenu de la réponse.', ['app' => (string)Settings::get('app_name', 'MIA')]) ?></p>
                </div>
            </div>
            <div class="sched-push-bar">
                <span class="sched-push-state" id="schedPushState" aria-live="polite"><?= te('Vérification…') ?></span>
                <button type="button" class="btn-primary" id="schedPushOn" hidden><?= te('Activer sur cet appareil') ?></button>
                <button type="button" class="btn-secondary" id="schedPushTest" hidden><?= te('Envoyer une notification de test') ?></button>
                <button type="button" class="btn-secondary" id="schedPushOff" hidden><?= te('Désactiver sur cet appareil') ?></button>
            </div>
            <p class="sched-field-error" id="schedPushError" role="alert" hidden></p>
            <p class="sched-hint sched-push-help" id="schedPushHelp" hidden></p>
            <ul class="sched-push-devices" id="schedPushDevices"<?= $pushDevices === [] ? ' hidden' : '' ?>>
                <?php foreach ($pushDevices as $device): ?>
                    <li class="sched-push-device">
                        <span class="sched-push-device-text">
                            <strong><?= htmlspecialchars($device['label']) ?></strong>
                            <span class="sched-hint"><?= $device['last'] !== ''
                                ? te('Ajouté le {date} · dernière notification le {last}', ['date' => $device['added'], 'last' => $device['last']])
                                : te('Ajouté le {date}', ['date' => $device['added']]) ?></span>
                        </span>
                        <button type="button" class="btn-secondary sched-push-remove" data-id="<?= (int)$device['id'] ?>"><?= te('Retirer') ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <noscript><p class="sched-hint"><?= te('Les notifications s’activent avec JavaScript.') ?></p></noscript>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($diagram !== null): ?>
<script>
// Schéma des tâches (clic sur une tâche) : catalogue et configurations
// calculés par le serveur (TaskDiagram), dessinés par diagram.js +
// schedule-diagram.js (le moteur d'abord : le modèle s'y branche).
window.SCHED_DIAGRAMS = <?= json_encode($diagram, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$engineJsVersion ?>" defer></script>
<script src="/assets/js/schedule-diagram.js?v=<?= (int)$diagramJsVersion ?>" defer></script>
<?php endif; ?>
<script src="/assets/js/schedules.js?v=<?= (int)$schedulesJsVersion ?>" defer></script>
</body>
</html>
