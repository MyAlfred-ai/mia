<?php
/**
 * Page de CONSULTATION d'un assistant (/admin/profiles/{id}) — piste C, même
 * grammaire que la page d'une base de connaissances (kb-pages.css), en violet.
 *
 * Ce qu'on veut savoir sans rien risquer de changer : qui il est, s'il sert
 * (son usage des 30 derniers jours), ce qu'il dit faire (sa consigne), avec
 * quoi il répond (modèle, température), ce qu'il connaît (bases, documents),
 * ce qu'il sait faire (outils) et qui le voit. « Posez-lui une question »
 * ouvre une conversation avec lui, la question prête à partir ; « Modifier »
 * ouvre sa fiche. Son schéma (diagram.js + assistant-diagram.js) ferme la
 * page, en lecture.
 *
 * Attend (AdminController::showProfile) : $profile, $actions
 * (AssistantPages::actions), $vis, $ownerName, $isMine, $llmLabel,
 * $llmFollows, $llmMissing, $llmSlug, $llmLogo, $tempSupported, $kbRows,
 * $kbHidden, $docs, $tools, $groups, $usage, $attention, $diagram,
 * $currentUser, $success, $error.
 */
$pid      = (int)$profile['id'];
$name     = (string)$profile['name'];
$desc     = trim((string)($profile['description'] ?? ''));
$active   = !empty($profile['is_active']);
$color    = AssistantPages::color($profile);
$avatar   = AssistantDiagram::avatarUrl((string)($profile['avatar'] ?? ''));
$visTxt   = AssistantPages::visibilityText($vis);
$editUrl  = '/admin/profiles/' . $pid . '/edit';
$prompt   = trim((string)($profile['system_prompt'] ?? ''));
$long     = AssistantPages::promptIsLong($prompt);
$lastUse  = $usage['last_age'] === null ? t('jamais') : KbStatus::ago((int)$usage['last_age']);
$canEdit  = $actions['edit'];
$hasMenu  = $actions['toggle'] || $actions['groups'] || $actions['delete'];
$version  = static fn(string $file): int => (int)(@filemtime(ROOT . $file) ?: 1);
// Qui l'a créé : vous, une personne, ou personne (assistant livré avec MIA, ou créateur supprimé).
$byLine = $isMine ? t('Créé par vous') : ($ownerName !== '' ? t('Créé par {nom}', ['nom' => $ownerName])
    : ($vis === AssistantPages::VIS_GLOBAL ? t('Livré avec MIA') : t('Sans créateur')));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($name) ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= $version('/public/assets/css/main.css') ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= $version('/public/assets/css/kb-pages.css') ?>">
    <link rel="stylesheet" href="/assets/css/assistants.css?v=<?= $version('/public/assets/css/assistants.css') ?>">
</head>
<body class="admin-page">
<?php $page = 'profiles'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kbx-page">
<div class="kbx kbx-show is-asst" id="asxShow" data-profile-id="<?= $pid ?>">

    <a class="kbx-back" href="/admin/profiles#assistant-<?= $pid ?>"><?= KbPages::icon('back', '') ?><?= te('Assistants') ?></a>

    <?php if ($success): ?><div class="alert alert-success" style="margin-top:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-top:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <header class="kbx-head asx-head">
        <span class="asx-ava is-big<?= $avatar === '' ? ' is-initial' : '' ?><?= $active ? '' : ' is-off' ?>" style="--asx-c: <?= htmlspecialchars($color) ?>" aria-hidden="true">
            <?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><span><?= htmlspecialchars(AssistantPages::initial($name)) ?></span><?php endif; ?>
        </span>
        <div class="kbx-head-main">
            <h1><?= htmlspecialchars($name) ?><?= AdminAttention::itemDot('profile:' . $pid) ?></h1>
            <?php if ($desc !== ''): ?><p class="kbx-desc"><?= htmlspecialchars($desc) ?></p><?php endif; ?>
            <div class="kbx-status">
                <span><span class="asx-dot<?= $active ? '' : ' is-off' ?>"></span><b><?= $active ? te('Actif') : te('Inactif') ?></b></span>
                <span class="kbx-vischip" title="<?= htmlspecialchars($visTxt['hint']) ?>"><?= AssistantPages::visibilityIcon($vis) ?><?= htmlspecialchars($visTxt['label']) ?></span>
                <span><?= htmlspecialchars($byLine) ?></span>
                <span><?= te('Dernière réponse : {quand}', ['quand' => $lastUse]) ?></span>
            </div>
        </div>
        <div class="kbx-actions">
            <?php if ($hasMenu): ?>
                <div class="asx-menu">
                    <button type="button" class="kbx-btn asx-more" data-asx-menu aria-haspopup="true" aria-expanded="false" aria-label="<?= te("Plus d'actions pour « {nom} »", ['nom' => $name]) ?>" title="<?= te("Plus d'actions") ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                    </button>
                    <div class="kebab-menu" hidden role="menu">
                        <?php if ($actions['toggle']): ?>
                            <form method="POST" action="/admin/profiles/<?= $pid ?>/toggle-personal"<?php if ($vis !== AssistantPages::VIS_PERSONAL): ?> data-asx-confirm="<?= te('Rendre « {nom} » personnel ? Ses autorisations de groupe seront effacées : il ne sera plus visible que de son créateur.', ['nom' => $name]) ?>"<?php endif; ?>>
                                <?= Csrf::field() ?>
                                <input type="hidden" name="return" value="page">
                                <?php if ($vis === AssistantPages::VIS_PERSONAL): ?>
                                    <button type="submit" class="kebab-row" role="menuitem" title="<?= te('Il pourra ensuite être autorisé dans des groupes.') ?>"><?= te('Partager à l’organisation') ?></button>
                                <?php else: ?>
                                    <button type="submit" class="kebab-row" role="menuitem" title="<?= te('Il ne sera plus visible que de son créateur.') ?>"><?= te('Rendre personnel') ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                        <?php if ($actions['groups']): ?>
                            <a class="kebab-row" role="menuitem" href="/admin/user-groups"><?= te('Autoriser dans les groupes →') ?></a>
                        <?php endif; ?>
                        <?php if ($actions['delete']): ?>
                            <?php if ($actions['toggle'] || $actions['groups']): ?><div class="kebab-sep"></div><?php endif; ?>
                            <form method="POST" action="/admin/profiles/<?= $pid ?>/delete" data-asx-confirm="<?= te('Supprimer « {nom} » définitivement ? Cette action est irréversible.', ['nom' => $name]) ?>">
                                <?= Csrf::field() ?>
                                <button type="submit" class="kebab-danger" role="menuitem"><?= te('Supprimer…') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <a class="kbx-btn" href="<?= htmlspecialchars($editUrl) ?>"><?= KbPages::icon('pencil', '') ?><?= $canEdit ? te('Modifier') : te('Voir la fiche') ?></a>
            <?php if ($actions['use']): ?>
                <form method="POST" action="/chat/new" class="asx-inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="profile_id" value="<?= $pid ?>">
                    <button type="submit" class="kbx-btn is-primary"><?= KbPages::icon('chat', '') ?><?= te('Discuter') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($attention !== null && !empty($attention['lines'])): ?>
        <div class="asx-alert<?= ($attention['level'] ?? '') === AdminAttention::ALERT ? ' is-alert' : '' ?>" role="note">
            <?= KbPages::icon('alert', '') ?>
            <div>
                <b><?= te('À régler') ?></b>
                <?php foreach ($attention['lines'] as $line): ?><p><?= htmlspecialchars((string)$line) ?></p><?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!$active): ?>
        <div class="asx-alert is-off" role="note">
            <?= KbPages::icon('ban', '') ?>
            <div>
                <b><?= te('Inactif') ?></b>
                <p><?= te('Il n’apparaît dans le chat de personne.') ?><?php if ($canEdit): ?> <a href="<?= htmlspecialchars($editUrl) ?>#identite"><?= te('Le réactiver dans sa fiche') ?></a><?php endif; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($actions['use']): ?>
    <section class="kbx-probe asx-ask" aria-labelledby="asxAskTitle">
        <h2 id="asxAskTitle"><?= te('Posez-lui une question') ?></h2>
        <p><?= te('Une conversation s’ouvre avec lui, votre question prête à partir.') ?></p>
        <form class="kbx-probe-form" method="POST" action="/chat/new" data-asx-ask>
            <?= Csrf::field() ?>
            <input type="hidden" name="profile_id" value="<?= $pid ?>">
            <?= KbPages::icon('chat', '') ?>
            <input type="text" id="asxAskInput" autocomplete="off" maxlength="2000"
                   placeholder="<?= te('Votre question pour « {nom} »…', ['nom' => $name]) ?>"
                   aria-label="<?= te('Votre question pour « {nom} »', ['nom' => $name]) ?>">
            <button type="submit" class="asx-ask-go" aria-label="<?= te('Ouvrir la conversation') ?>" title="<?= te('Ouvrir la conversation') ?>"><?= KbPages::icon('arrow', '') ?></button>
        </form>
    </section>
    <?php endif; ?>

    <p class="asx-stats-cap"><?= te('Ces {n} derniers jours', ['n' => Profile::USAGE_DAYS]) ?></p>
    <div class="kbx-stats asx-stats">
        <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($usage['replies'])) ?></b><small><?= htmlspecialchars(I18n::plural($usage['replies'], 'réponse', 'réponses')) ?></small></div>
        <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($usage['people'])) ?></b><small><?= htmlspecialchars(I18n::plural($usage['people'], 'personne', 'personnes')) ?></small></div>
        <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($usage['conversations'])) ?></b><small><?= htmlspecialchars(I18n::plural($usage['conversations'], 'conversation', 'conversations')) ?></small></div>
        <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($usage['credits'])) ?></b><small><?= htmlspecialchars(I18n::plural($usage['credits'], 'crédit', 'crédits')) ?></small></div>
    </div>

    <div class="kbx-cols">
        <div class="kbx-main">
            <section class="kbx-card" aria-labelledby="asxPromptTitle">
                <div class="kbx-card-head">
                    <h2 id="asxPromptTitle"><?= te('Sa consigne') ?></h2>
                    <?php if ($canEdit): ?><a class="kbx-btn is-sm" href="<?= htmlspecialchars($editUrl) ?>#consigne"><?= KbPages::icon('pencil', '') ?><?= te('Modifier') ?></a><?php endif; ?>
                </div>
                <div class="asx-prompt<?= $long ? ' is-clamped' : '' ?>" id="asxPrompt"><?= $prompt !== '' ? htmlspecialchars($prompt) : '<em>' . te('Aucune consigne.') . '</em>' ?></div>
                <?php if ($long): ?>
                    <button type="button" class="kbx-more" id="asxPromptMore" aria-controls="asxPrompt" aria-expanded="false"
                            data-more="<?= te('Afficher toute la consigne') ?>" data-less="<?= te('Replier la consigne') ?>"><?= te('Afficher toute la consigne') ?></button>
                <?php else: ?>
                    <div class="asx-card-pad"></div>
                <?php endif; ?>
            </section>

            <section class="kbx-card" aria-labelledby="asxKnowTitle">
                <div class="kbx-card-head">
                    <h2 id="asxKnowTitle"><?= te('Ce qu’il connaît') ?></h2>
                    <?php if ($canEdit): ?><a class="kbx-btn is-sm" href="<?= htmlspecialchars($editUrl) ?>#connaissances"><?= KbPages::icon('pencil', '') ?><?= te('Modifier') ?></a><?php endif; ?>
                </div>
                <?php if ($kbRows === [] && $docs === [] && $kbHidden === 0): ?>
                    <p class="kbx-muted asx-pad"><?= te('Ce que sait le modèle, sans base ni document.') ?></p>
                <?php else: ?>
                    <ul class="kbx-docs asx-know">
                        <?php foreach ($kbRows as $row):
                            $kb    = $row['kb'];
                            $entry = $row['entry'];
                            $open  = KnowledgeBase::visibleBy((int)$kb['id'], $currentUser); ?>
                            <li class="kbx-doc">
                                <?php if ($open): ?><a class="kbx-doc-open" href="/admin/knowledge-bases/<?= (int)$kb['id'] ?>"><?php else: ?><div class="kbx-doc-open"><?php endif; ?>
                                    <?= KbPages::tile($kb) ?>
                                    <span class="kbx-doc-main"><b><?= htmlspecialchars($entry['name']) ?></b><small><?= htmlspecialchars($entry['note']) ?></small></span>
                                    <span class="kbx-dst"<?= $entry['warn'] ? ' data-tone="warn"' : '' ?>><?= KnowledgeBase::isTable($kb) ? te('Table') : te('Base') ?></span>
                                    <?php if ($open): ?><?= KbPages::icon('chev', 'kbx-doc-go') ?><?php endif; ?>
                                <?php if ($open): ?></a><?php else: ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($docs as $doc):
                            $d     = AssistantDiagram::docEntry($doc);
                            $st    = $d['extraction_status'];
                            $isImg = str_starts_with($d['mime_type'], 'image/');
                            $note  = $st === 'pending' || $st === 'processing' ? t('Lecture en cours…')
                                : ($st === 'failed' ? t('Lecture en échec')
                                : ($d['has_text'] ? t('~{n} tokens', ['n' => Format::number($d['token_count'])])
                                : ($isImg ? t('Image montrée au modèle') : t('Pas de texte extrait'))));
                            $warn  = $st === 'failed' || (!$d['has_text'] && !$isImg && $st === 'done'); ?>
                            <li class="kbx-doc">
                                <div class="kbx-doc-open">
                                    <span class="kbx-ft" data-ext="<?= htmlspecialchars(KbPages::fileKind($d['file_name'])) ?>"><?= htmlspecialchars(KbPages::fileBadge($d['file_name'])) ?></span>
                                    <span class="kbx-doc-main"><b><?= htmlspecialchars($d['file_name']) ?></b><small><?= htmlspecialchars(Format::sizeShort($d['file_size'])) ?> · <?= htmlspecialchars($note) ?></small></span>
                                    <span class="kbx-dst"<?= $warn ? ' data-tone="warn"' : '' ?>><?= te('Document') ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($kbHidden > 0): ?>
                        <p class="kbx-docs-hint"><?= htmlspecialchars(I18n::plural($kbHidden, '+ {n} base hors de votre périmètre', '+ {n} bases hors de votre périmètre')) ?></p>
                    <?php endif; ?>
                    <div class="asx-card-pad"></div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="kbx-side">
            <section class="kbx-card kbx-box">
                <h3><?= te('Il répond avec') ?><?php if ($canEdit): ?><a class="asx-box-edit" href="<?= htmlspecialchars($editUrl) ?>#modele"><?= te('Modifier') ?></a><?php endif; ?></h3>
                <div class="asx-model">
                    <span class="asx-model-logo"><?php if ($llmLogo !== ''): ?><img src="<?= htmlspecialchars($llmLogo) ?>" alt=""><?php else: ?><?= KbPages::icon('robot', '') ?><?php endif; ?></span>
                    <div>
                        <b><?= htmlspecialchars($llmLabel !== '' ? $llmLabel : t('Aucun modèle disponible')) ?></b>
                        <?php if ($llmFollows): ?><small><?= te('Le modèle de l’organisation : il suit ses changements.') ?></small><?php endif; ?>
                    </div>
                </div>
                <?php if ($llmMissing): ?>
                    <p class="asx-fact is-warn"><?= te('« {modele} » n’est plus proposé : il répond avec le modèle de l’organisation.', ['modele' => $llmSlug]) ?></p>
                <?php endif; ?>
                <p class="asx-fact"><?= htmlspecialchars(AssistantPages::temperatureText($profile['temperature'] ?? null, $tempSupported)) ?></p>
            </section>

            <section class="kbx-card kbx-box">
                <h3><?= te('Il sait faire') ?><?php if ($canEdit): ?><a class="asx-box-edit" href="<?= htmlspecialchars($editUrl) ?>#capacites"><?= te('Modifier') ?></a><?php endif; ?></h3>
                <?php if ($tools === []): ?>
                    <p class="kbx-muted"><?= te('Rédiger et répondre, sans outil de création.') ?></p>
                <?php else: ?>
                    <div class="kbx-people">
                        <?php foreach ($tools as $tool):
                            $te = AssistantDiagram::toolEntry($tool); ?>
                            <div class="kbx-person">
                                <span class="kbx-grp asx-tool-ic"><?= TaskDiagram::toolIcon($te['type']) ?></span>
                                <div><?= htmlspecialchars($te['name']) ?><small<?= $te['warn'] ? ' class="is-warn"' : '' ?>><?= htmlspecialchars($te['note']) ?></small></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="kbx-card kbx-box">
                <h3><?= te('Qui le voit') ?></h3>
                <div class="kbx-person">
                    <span class="kbx-grp"><?= AssistantPages::visibilityIcon($vis) ?></span>
                    <div><?= htmlspecialchars($visTxt['label']) ?><small><?php
                        if ($vis === AssistantPages::VIS_PERSONAL) {
                            echo $isMine ? te('Vous seul.') : ($ownerName !== '' ? te('{nom} seul.', ['nom' => $ownerName]) : te('Personne : son créateur a été supprimé.'));
                        } else {
                            echo htmlspecialchars($visTxt['hint']);
                        }
                    ?></small></div>
                </div>
                <?php if ($vis === AssistantPages::VIS_SHARED): ?>
                    <?php if ($groups === []): ?>
                        <p class="asx-fact is-warn"><?= te('Autorisé dans aucun groupe : seuls les administrateurs le voient.') ?></p>
                    <?php else: ?>
                        <div class="kbx-people" style="margin-top:12px">
                            <?php foreach ($groups as $g): ?>
                                <div class="kbx-person"><span class="kbx-grp"><?= KbPages::icon('group', '') ?></span><div><?= htmlspecialchars($g['name']) ?><?php if (!$g['active']): ?><small><?= te('Groupe désactivé') ?></small><?php endif; ?></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($actions['groups']): ?><p class="kbx-foot" style="margin-top:10px"><a href="/admin/user-groups"><?= te('Autoriser dans les groupes →') ?></a></p><?php endif; ?>
                <?php endif; ?>
            </section>
        </aside>
    </div>

    <section class="kbx-ensemble" aria-labelledby="asxEnsembleTitle">
        <div class="kbx-ensemble-h">
            <div>
                <h2 id="asxEnsembleTitle"><?= te('Vue d\'ensemble') ?></h2>
                <p><?= te('Son modèle, sa consigne, ce qu’il connaît, ses outils et qui le voit, en un schéma.') ?></p>
            </div>
        </div>
        <div class="kbx-diagram sched-diagram" id="asxShowDiagram"></div>
    </section>
</div>
</div>

<script>
window.ASSISTANT_SHOW = <?= json_encode($diagram, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= $version('/public/assets/js/diagram.js') ?>"></script>
<script src="/assets/js/assistant-diagram.js?v=<?= $version('/public/assets/js/assistant-diagram.js') ?>"></script>
<script src="/assets/js/assistant-show.js?v=<?= $version('/public/assets/js/assistant-show.js') ?>"></script>
</body>
</html>
