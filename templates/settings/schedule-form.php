<?php
/**
 * Espaces → Tâches programmées : création et modification d'une tâche.
 * Variables fournies par ScheduledTaskController::renderForm :
 *   @var array   $form          valeurs du formulaire (tâche, saisie renvoyée ou pré-remplissage)
 *   @var ?array  $task          tâche modifiée (null à la création)
 *   @var bool    $isEdit
 *   @var ?string $error         erreur de validation (saisie conservée)
 *   @var bool    $fromMessage   pré-rempli depuis une réponse du chat
 *   @var bool    $fromHadFiles  la demande d'origine portait des fichiers (non repris)
 *   @var ?int    $fromAction    pré-rempli depuis une carte « Programmer une tâche »
 *                               (« Ajuster ») : la carte est close à l'enregistrement
 *   @var bool    $fromComposer  ouvert par l'horloge de l'assistant : schedules.js
 *                               reprend le texte en cours de saisie (stockage de session)
 *   @var list<array>          $profiles  assistants accessibles
 *   @var array<string,string> $llms      modèles permis (slug → libellé)
 *   @var array<string,array>  $sources   connecteurs proposés (clé → label, connected)
 *   @var array<int,array>     $kbs       bases de connaissances de la personne (id → name, reason, live, docs…)
 *   @var array<int,array>     $tools     outils qu'une tâche peut engager (id → name, type, reason)
 *   @var array<int,list<array>> $profileKbs bases apportées d'office par chaque assistant
 *   @var array<int,array>     $assistantCards fiche de chaque assistant (avatar, description, modèle, bases, outils)
 *   @var array<int,list<array>> $profileTools outils apportés d'office par chaque assistant
 *   @var ?int    $missingProfileId assistant enregistré qui n'est plus accessible (sélecteur
 *                               « indisponible », enregistrement refusé tant qu'il reste choisi)
 *   @var ?array  $preview       phrase, prochaines dates, volume, fuseau (null si règle invalide)
 *   @var list<array> $runs      dernières exécutions (modification)
 *   @var bool    $limitReached  plafond de tâches actives atteint (création)
 *   @var int     $maxActive
 *   @var array   $diagram       schéma : catalogue TaskDiagram + déclencheur du premier aperçu
 *   @var bool    $autoAllowed   « Agir sans validation » proposé par l'administrateur
 *
 * Les questions dans l'ordre où on se les pose : Quoi, Avec quoi, Quand, Où
 * voir le résultat — puis un essai, sur place, avant de programmer.
 * Enregistrement MANUEL (cf. ScheduledTaskController) : ce formulaire ne
 * porte volontairement pas `data-autosave`.
 *
 * La CONSIGNE est le cœur de la tâche (le texte que l'assistant reçoit, tel
 * quel, à chaque exécution) : elle est mise en avant dans « Quoi », avec des
 * exemples cliquables dessous — ceux d'InstructionExamples, les mêmes que le
 * guide. « Avec quoi » propose ses bases, connecteurs et outils en TUILES
 * alignées (grille, une case par élément : icône, nom, note, coche), toujours
 * cochables d'un clic et à plusieurs ; les contrats lus par schedules.js ne
 * changent pas (.sched-kb[data-kb-id], [data-role="note"][data-default],
 * .sched-tool[data-type][data-acting], is-locked / is-unavailable / is-full).
 *
 * Chaque section et chaque bloc réglable porte un id (schedSection*,
 * schedKbs, schedSources, schedTools, schedActionsMode, schedLlmRow…) :
 * c'est par eux qu'un clic sur un nœud du schéma mène au réglage
 * correspondant (schedules.js, « Du schéma au réglage »).
 *
 * Icônes : celles du menu d'administration (AdminMenu::icon) — l'assistant et
 * la base de connaissances portent le même dessin partout, du menu au
 * composer du chat.
 *
 * À droite, le SCHÉMA de la tâche (déclencheur, assistant, modèle,
 * connecteurs, bases, outils), redessiné à chaque geste par schedules.js avec
 * diagram.js + schedule-diagram.js — le même que la liste. Données : $diagram
 * (TaskDiagram::catalog + déclencheur du premier aperçu).
 */
$mainCssVersion     = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$schedulesJsVersion = @filemtime(ROOT . '/public/assets/js/schedules.js') ?: 1;
$engineJsVersion    = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$diagramJsVersion   = @filemtime(ROOT . '/public/assets/js/schedule-diagram.js') ?: 1;
$action  = $isEdit ? '/settings/schedules/' . (int)$task['id'] : '/settings/schedules';
$freq    = (string)$form['freq'];
$days    = array_map('intval', (array)$form['days']);
$checkedSources = array_flip(array_map('strval', (array)$form['sources']));
$checkedKbs     = array_flip(array_map('intval', (array)($form['kb_ids'] ?? [])));
$checkedTools   = array_flip(array_map('intval', (array)($form['tool_ids'] ?? [])));
$currentProfileId = $form['profile_id'] !== null ? (int)$form['profile_id'] : null;
$currentCard      = $currentProfileId !== null ? ($assistantCards[$currentProfileId] ?? null) : null;
// Assistant enregistré devenu inaccessible : fiche « indisponible » à la place
// de celle de MIA, que la liste aurait montrée en retombant sur sa 1re option.
$missingCard = $missingProfileId !== null ? [
    'name'        => t('Assistant indisponible'),
    'description' => t('Retiré, désactivé ou plus partagé avec vos groupes. Choisissez un autre assistant, ou aucun.'),
] : null;
// Outils que l'assistant choisi apporte d'office : leur tuile le dit.
$assistantToolIds = $currentProfileId !== null
    ? array_flip(array_map(static fn(array $tool): int => (int)$tool['id'], $profileTools[$currentProfileId] ?? []))
    : [];
// Bases que l'assistant choisi apporte d'office : leurs cases sont cochées
// et verrouillées (elles ne consomment aucun des trois crans).
$lockedKbIds = $currentProfileId !== null
    ? array_flip(array_map(static fn(array $kb): int => (int)$kb['id'], $profileKbs[$currentProfileId] ?? []))
    : [];
$miaLogo  = Settings::get('logo_url', '/assets/img/logo.png');
$appName  = Settings::get('app_name', 'MIA');
// Exemples de consigne (puces sous le champ) : le premier sert aussi de texte
// d'invite au champ — l'exemple montré est un exemple cliquable.
$examples = InstructionExamples::all();
// Bases cochées qui consomment un cran (ni verrouillées par l'assistant, ni
// indisponibles) : le compteur « n/3 » de la rangée, tenu à jour par schedules.js.
$freeKbChecked = 0;
foreach ($kbs as $kbId => $kb) {
    if ($kb['reason'] === null && !isset($lockedKbIds[(int)$kbId]) && isset($checkedKbs[(int)$kbId])) {
        $freeKbChecked++;
    }
}
// Coche d'une tuile cochée (« Avec quoi »), une seule fois.
$tileCheck = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
// Un outil qui agit impose la validation : l'écran le montre d'emblée.
$actingChecked = false;
foreach ($tools as $toolId => $tool) {
    if (isset($checkedTools[(int)$toolId]) && in_array($tool['type'], ScheduledTask::ACTING_TOOL_TYPES, true)) {
        $actingChecked = true;
    }
}
// Ce que la tâche peut faire : le choix enregistré ; la lecture seule exclue
// par un outil qui agit ; « sans validation » ramené aux cartes quand
// l'administrateur l'a retiré — c'est ce qui se passera, l'écran le montre.
$actionMode = (string)($form['action_mode'] ?? ScheduledTask::MODE_READ);
$autoHeld   = $actionMode === ScheduledTask::MODE_AUTO && !$autoAllowed;
if ($autoHeld || ($actionMode === ScheduledTask::MODE_READ && $actingChecked)) {
    $actionMode = ScheduledTask::MODE_APPROVE;
}
$presets = [
    Recurrence::DAILY    => t('Chaque jour'),
    Recurrence::WEEKDAYS => t('Jours ouvrés'),
    Recurrence::WEEKLY   => t('Chaque semaine'),
    Recurrence::MONTHLY  => t('Chaque mois'),
];
// Jours cochés à l'écran : ceux du préréglage, pour qu'on voie ce qu'il couvre.
$shownDays = match ($freq) {
    Recurrence::DAILY    => [1, 2, 3, 4, 5, 6, 7],
    Recurrence::WEEKDAYS => [1, 2, 3, 4, 5],
    default              => $days,
};
$sectionIcon = static fn(string $paths): string =>
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= $isEdit ? te('Modifier la tâche —') : te('Nouvelle tâche —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Tâches programmées'); $page = 'schedules'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content sched-content">
    <div class="sched-wrap sched-form-wrap has-diagram">

        <a class="sched-back" href="/settings/schedules">‹ <?= te('Tâches programmées') ?></a>
        <h1><?= $isEdit ? te('Modifier la tâche') : te('Nouvelle tâche programmée') ?></h1>
        <p class="sched-lead"><?= te('Une demande qui se répète toute seule. La réponse arrive dans la conversation de la tâche ; rien n’est envoyé ni modifié sans votre accord, sauf si vous lui permettez d’agir sans validation.') ?></p>

        <?php if ($fromAction !== null): ?>
            <div class="alert alert-success sched-alert" role="status">
                <?= te('Tâche préparée par MIA : ajustez-la, puis programmez-la. La carte de la conversation sera close.') ?>
            </div>
        <?php endif; ?>
        <?php if ($fromMessage): ?>
            <div class="alert alert-success sched-alert" role="status">
                <?= te('Pré-remplie depuis votre conversation : relisez la consigne, puis choisissez quand la répéter.') ?>
                <?php if ($fromHadFiles): ?>
                    <?= te('Les fichiers joints à la demande ne sont pas repris : décrivez-les dans la consigne si besoin.') ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error sched-alert" role="alert"><?= htmlspecialchars((string)$error) ?></div>
        <?php endif; ?>
        <?php if ($limitReached): ?>
            <div class="alert alert-error sched-alert"><?= te('Vous avez déjà {n} tâches actives : mettez-en une en pause pour en programmer une autre.', ['n' => $maxActive]) ?></div>
        <?php endif; ?>
        <?php if ($isEdit && $task['status'] !== 'active'): ?>
            <div class="alert alert-error sched-alert">
                <?= te('Cette tâche est en pause') ?><?= (string)$task['pause_reason'] !== '' && (string)$task['pause_reason'] !== ScheduledTask::PAUSE_USER ? ' : ' . htmlspecialchars(ScheduledTask::reasonLabel((string)$task['pause_reason'])) : '' ?>.
                <?= te('Réactivez-la depuis la liste des tâches.') ?>
            </div>
        <?php endif; ?>

        <div class="sched-form-layout">
        <?php /* Schéma de la tâche telle que saisie : rempli par schedules.js
                 (rien sans JavaScript). Avant le formulaire dans le document :
                 sur un écran étroit, il ouvre la page comme un résumé. */ ?>
        <aside class="sched-diagram-panel is-form" id="schedDiagramPanel" aria-labelledby="schedDiagramTitle" hidden>
            <div class="sched-diagram-head">
                <div class="sched-diagram-head-text">
                    <span class="sched-diagram-kicker"><?= te('Aperçu de la tâche') ?></span>
                    <h2 id="schedDiagramTitle"></h2>
                </div>
            </div>
            <p class="sched-diagram-hint"><?= te('Cliquez un élément du schéma pour aller à son réglage.') ?></p>
            <div class="sched-diagram" id="schedDiagram"></div>
        </aside>

        <div class="sched-form-main">
        <form method="POST" action="<?= htmlspecialchars($action) ?>" id="schedForm" class="sched-form"<?= $fromComposer ? ' data-compose="1"' : '' ?>>
            <?= Csrf::field() ?>
            <?php if ($fromAction !== null): ?>
                <input type="hidden" name="from_action" value="<?= (int)$fromAction ?>">
            <?php endif; ?>
            <input type="hidden" name="timezone" id="schedTz" value="<?= htmlspecialchars((string)$form['timezone']) ?>">

            <!-- 1. Quoi -->
            <section class="card sched-section" id="schedSectionWhat" aria-labelledby="schedWhatTitle">
                <div class="card-head">
                    <span class="card-ic"><?= $sectionIcon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="schedWhatTitle"><?= te('Quoi') ?></h3>
                        <p><?= te('La demande, écrite comme dans le chat, puis qui la reçoit.') ?></p>
                    </div>
                </div>

                <?php /* La consigne est le cœur de la tâche : bloc mis en avant
                         (bordure d'accent, champ plus haut) et, dessous, des
                         exemples cliquables — ceux du guide (InstructionExamples).
                         Un clic pose l'exemple dans le champ (schedules.js),
                         jamais par-dessus un texte écrit sans confirmation. */ ?>
                <div class="form-group sched-instruction" id="schedInstructionBlock">
                    <div class="sched-instruction-head">
                        <label for="schedInstruction"><?= te('Consigne') ?></label>
                        <span class="sched-instruction-kicker"><?= te('Le cœur de la tâche — envoyée telle quelle à chaque exécution') ?></span>
                    </div>
                    <textarea id="schedInstruction" name="instruction" class="form-textarea" rows="5" required
                              maxlength="<?= ScheduledTask::INSTRUCTION_MAX ?>"
                              placeholder="<?= htmlspecialchars((string)($examples[0]['instruction'] ?? '')) ?>"><?= htmlspecialchars((string)$form['instruction']) ?></textarea>
                    <p class="sched-field-error" id="schedInstructionError" hidden></p>
                    <div class="sched-examples" id="schedExamples" role="group" aria-label="<?= te('Exemples de consigne') ?>">
                        <span class="sched-examples-label"><?= te('Par exemple') ?></span>
                        <?php foreach ($examples as $example): ?>
                            <button type="button" class="sched-example"
                                    data-name="<?= htmlspecialchars($example['label']) ?>"
                                    data-instruction="<?= htmlspecialchars($example['instruction']) ?>"
                                    title="<?= htmlspecialchars($example['instruction']) ?>"><?= htmlspecialchars($example['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sched-grid-2">
                    <div class="form-group">
                        <label for="schedName"><?= te('Nom') ?></label>
                        <input type="text" id="schedName" name="name" class="form-input" maxlength="<?= ScheduledTask::NAME_MAX ?>"
                               value="<?= htmlspecialchars((string)$form['name']) ?>" placeholder="<?= te('Point du lundi') ?>">
                    </div>
                    <div class="form-group" id="schedAssistantField">
                        <label for="schedProfile" class="sched-label-ic"><?= AdminMenu::icon('profiles', 15) ?><?= te('Assistant') ?></label>
                        <div class="sched-assistant-select">
                            <span class="sched-assistant-ava" id="schedProfileAvatar" aria-hidden="true"><?php
                                if ($currentCard !== null && $currentCard['avatar'] !== ''): ?><img src="<?= htmlspecialchars($currentCard['avatar']) ?>" alt=""><?php
                                elseif ($currentCard !== null): ?><span class="sched-assistant-dot" style="background:<?= htmlspecialchars($currentCard['color'] !== '' ? $currentCard['color'] : 'var(--accent)') ?>"></span><?php
                                elseif ($missingCard !== null): ?><span class="sched-assistant-dot is-missing"></span><?php
                                else: ?><img src="<?= htmlspecialchars($miaLogo) ?>" alt=""><?php endif; ?></span>
                            <select id="schedProfile" name="profile_id" class="form-select"<?= $missingProfileId !== null ? ' aria-describedby="schedProfileMissing" aria-invalid="true"' : '' ?>>
                                <option value=""><?= te('{app}, sans assistant', ['app' => $appName]) ?></option>
                                <?php if ($missingProfileId !== null): ?>
                                    <?php /* Option envoyée telle quelle (jamais `disabled` : une option
                                             désactivée n'est pas envoyée, la tâche passerait « sans
                                             assistant ») — le serveur la refuse, schedules.js aussi. */ ?>
                                    <option value="<?= (int)$missingProfileId ?>" selected data-missing="1"><?= te('Assistant indisponible — à remplacer') ?></option>
                                <?php endif; ?>
                                <?php foreach ($profiles as $profile): ?>
                                    <option value="<?= (int)$profile['id'] ?>"<?= (int)$profile['id'] === (int)($form['profile_id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars((string)$profile['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="sched-field-error" id="schedProfileMissing" role="alert"<?= $missingProfileId !== null ? '' : ' hidden' ?>><?= te('L’assistant de cette tâche n’est plus disponible pour votre compte. Choisissez-en un autre, ou « sans assistant » : la tâche ne s’enregistre pas sans ce choix.') ?></p>
                    </div>
                </div>

                <?php /* Fiche de l'assistant choisi : avatar, description, modèle, bases
                         qu'il apporte. Rendue ici pour le choix initial, tenue à jour par
                         schedules.js depuis la même charge (window.SCHED_FORM). */ ?>
                <div class="sched-assistant-card<?= $missingCard !== null && $currentCard === null ? ' is-missing' : '' ?>" id="schedAssistantCard" aria-live="polite">
                    <span class="sched-assistant-card-ava" data-role="avatar"><?php
                        if ($currentCard !== null && $currentCard['avatar'] !== ''): ?><img src="<?= htmlspecialchars($currentCard['avatar']) ?>" alt=""><?php
                        elseif ($currentCard !== null): ?><span class="sched-assistant-dot" style="background:<?= htmlspecialchars($currentCard['color'] !== '' ? $currentCard['color'] : 'var(--accent)') ?>"></span><?php
                        elseif ($missingCard !== null): ?><span class="sched-assistant-dot is-missing"></span><?php
                        else: ?><img src="<?= htmlspecialchars($miaLogo) ?>" alt=""><?php endif; ?></span>
                    <span class="sched-assistant-card-text">
                        <strong data-role="name"><?= htmlspecialchars($currentCard !== null ? $currentCard['name'] : ($missingCard !== null ? $missingCard['name'] : $appName)) ?></strong>
                        <span class="sched-hint" data-role="description"><?= htmlspecialchars($currentCard !== null
                            ? $currentCard['description']
                            : ($missingCard !== null ? $missingCard['description'] : t('Sans assistant : choisissez le modèle ci-dessous.'))) ?></span>
                        <span class="sched-assistant-card-meta" data-role="meta"<?= $currentCard === null ? ' hidden' : '' ?>><?= htmlspecialchars($currentCard !== null ? $currentCard['meta'] : '') ?></span>
                    </span>
                </div>

                <div class="form-group" id="schedLlmRow"<?= $form['profile_id'] !== null ? ' hidden' : '' ?>>
                    <label for="schedLlm"><?= te('Modèle') ?></label>
                    <select id="schedLlm" name="llm" class="form-select">
                        <option value=""><?= te('Modèle par défaut') ?></option>
                        <?php foreach ($llms as $slug => $label): ?>
                            <option value="<?= htmlspecialchars((string)$slug) ?>"<?= (string)$slug === (string)$form['llm'] ? ' selected' : '' ?>><?= htmlspecialchars((string)$label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="sched-hint"><?= te('Un assistant impose son propre modèle.') ?></p>
                </div>
            </section>

            <!-- 2. Avec quoi -->
            <section class="card sched-section" id="schedSectionWith" aria-labelledby="schedWithTitle">
                <div class="card-head">
                    <span class="card-ic"><?= AdminMenu::icon('connectors', 18) ?></span>
                    <div class="card-head-text">
                        <h3 id="schedWithTitle"><?= te('Avec quoi') ?></h3>
                        <p><?= te('Ce que la tâche consulte, et ce qu’elle peut faire. Les bases et outils de l’assistant s’y ajoutent d’office.') ?></p>
                    </div>
                </div>

                <?php /* Trois rangées de TUILES (bases, connecteurs, outils) : même
                         grille, même anatomie — icône, nom, note, coche. Une tuile
                         est l'habillage d'une case à cocher ordinaire : un clic la
                         coche, plusieurs se cochent, le clavier et l'envoi du
                         formulaire ne voient qu'un <input>. */ ?>
                <?php if ($kbs !== []): ?>
                <fieldset class="sched-pick" id="schedKbs" data-max="<?= ConversationKnowledgeBase::MAX_SELECTED ?>">
                    <legend class="sched-pick-head">
                        <span class="sched-pick-ic" aria-hidden="true"><?= AdminMenu::icon('kb', 15) ?></span>
                        <span class="sched-pick-title"><?= te('Bases de connaissances') ?></span>
                        <span class="sched-pick-count" data-role="count" title="<?= te('Bases choisies pour la tâche, en plus de celles de l’assistant') ?>"><?= (int)$freeKbChecked ?>/<?= ConversationKnowledgeBase::MAX_SELECTED ?></span>
                    </legend>
                    <div class="sched-tiles">
                        <?php foreach ($kbs as $kbId => $kb):
                            $kbOff    = $kb['reason'] !== null;
                            $kbLocked = isset($lockedKbIds[(int)$kbId]);
                            // Note par défaut (hors assistant) : schedules.js la remet quand
                            // l'assistant qui verrouillait la base change.
                            $kbNote   = $kbOff ? (string)$kb['reason'] : ($kb['live'] ? t('en direct') : I18n::plural($kb['docs'], '{n} document', '{n} documents'));
                        ?>
                            <label class="sched-tile sched-kb<?= $kbOff ? ' is-unavailable' : '' ?><?= $kbLocked ? ' is-locked' : '' ?>"
                                   data-kb-id="<?= (int)$kbId ?>"
                                   title="<?= htmlspecialchars($kbOff ? (string)$kb['reason'] : ($kb['description'] !== '' ? $kb['description'] : $kb['name'])) ?>">
                                <input type="checkbox" name="kb_ids[]" value="<?= (int)$kbId ?>"<?= ($kbLocked || (!$kbOff && isset($checkedKbs[(int)$kbId]))) ? ' checked' : '' ?><?= ($kbOff || $kbLocked) ? ' disabled' : '' ?>>
                                <span class="sched-tile-ic" aria-hidden="true"><?php if ($kb['live']): ?><img src="/assets/img/mybrain.png" alt=""><?php else: ?><?= AdminMenu::icon('kb', 16) ?><?php endif; ?></span>
                                <span class="sched-tile-text">
                                    <span class="sched-tile-name"><?= htmlspecialchars((string)$kb['name']) ?></span>
                                    <span class="sched-tile-note<?= $kbOff ? ' is-warn' : '' ?>" data-role="note" data-default="<?= htmlspecialchars($kbNote) ?>"><?= htmlspecialchars($kbLocked ? t('incluse par l’assistant') : $kbNote) ?></span>
                                </span>
                                <span class="sched-tile-check" aria-hidden="true"><?= $tileCheck ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="sched-hint"><?= te('{n} au plus, en plus de celles de l’assistant. Les mêmes que le sélecteur de bases du chat.', ['n' => ConversationKnowledgeBase::MAX_SELECTED]) ?></p>
                </fieldset>
                <?php endif; ?>

                <?php if ($sources !== []): ?>
                <fieldset class="sched-pick" id="schedSources">
                    <legend class="sched-pick-head">
                        <span class="sched-pick-ic" aria-hidden="true"><?= AdminMenu::icon('connectors', 15) ?></span>
                        <span class="sched-pick-title"><?= te('Connecteurs') ?></span>
                    </legend>
                    <div class="sched-tiles">
                        <?php foreach ($sources as $key => $source): ?>
                            <label class="sched-tile sched-source<?= $source['connected'] ? '' : ' is-unlinked' ?>">
                                <input type="checkbox" name="sources[]" value="<?= htmlspecialchars((string)$key) ?>"<?= isset($checkedSources[(string)$key]) ? ' checked' : '' ?>>
                                <span class="sched-tile-ic" aria-hidden="true"><?= ($source['icon'] ?? '') !== '' ? $source['icon'] : AdminMenu::icon('connectors', 16) ?></span>
                                <span class="sched-tile-text">
                                    <span class="sched-tile-name"><?= htmlspecialchars((string)$source['label']) ?></span>
                                    <?php if (!$source['connected']): ?>
                                        <span class="sched-tile-note is-warn"><?= te('non connecté') ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="sched-tile-check" aria-hidden="true"><?= $tileCheck ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="sched-hint"><?= te('Les mêmes que le menu « + » du chat.') ?></p>
                </fieldset>
                <?php endif; ?>

                <?php if ($tools !== []): ?>
                <fieldset class="sched-pick" id="schedTools">
                    <legend class="sched-pick-head">
                        <span class="sched-pick-ic" aria-hidden="true"><?= AdminMenu::icon('tools', 15) ?></span>
                        <span class="sched-pick-title"><?= te('Outils') ?></span>
                    </legend>
                    <div class="sched-tiles">
                        <?php foreach ($tools as $toolId => $tool):
                            $toolOff = $tool['reason'] !== null;
                            $acting  = in_array($tool['type'], ScheduledTask::ACTING_TOOL_TYPES, true);
                            // Note par défaut (hors assistant) : schedules.js la remet quand
                            // l'assistant qui apportait l'outil change.
                            $toolNote    = $toolOff ? (string)$tool['reason'] : ScheduledTask::toolTypeLabel($tool['type']);
                            $byAssistant = !$toolOff && isset($assistantToolIds[(int)$toolId]);
                        ?>
                            <label class="sched-tile sched-tool<?= $toolOff ? ' is-unavailable' : '' ?>"
                                   data-type="<?= htmlspecialchars($tool['type']) ?>"<?= $acting ? ' data-acting="1"' : '' ?>
                                   title="<?= htmlspecialchars($toolOff ? (string)$tool['reason'] : ScheduledTask::toolTypeLabel($tool['type'])) ?>">
                                <input type="checkbox" name="tool_ids[]" value="<?= (int)$toolId ?>"<?= (!$toolOff && isset($checkedTools[(int)$toolId])) ? ' checked' : '' ?><?= $toolOff ? ' disabled' : '' ?>>
                                <span class="sched-tile-ic" aria-hidden="true"><?= TaskDiagram::toolIcon((string)$tool['type']) ?></span>
                                <span class="sched-tile-text">
                                    <span class="sched-tile-name"><?= htmlspecialchars((string)$tool['name']) ?></span>
                                    <span class="sched-tile-note<?= $toolOff ? ' is-warn' : '' ?>" data-role="note" data-default="<?= htmlspecialchars($toolNote) ?>"><?= htmlspecialchars($byAssistant ? t('apporté par l’assistant') : $toolNote) ?></span>
                                </span>
                                <span class="sched-tile-check" aria-hidden="true"><?= $tileCheck ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="sched-hint"><?= te('Un outil par type. Une automatisation n8n ou une présentation Gamma attend votre validation. En « Agir sans validation », elle ne s’exécute seule que si son outil est coché ici : celle d’un outil apporté par l’assistant attend toujours votre validation.') ?></p>
                </fieldset>
                <?php endif; ?>

                <fieldset class="sched-fieldset sched-actions-mode" id="schedActionsMode">
                    <legend><?= te('Ce que la tâche peut faire') ?></legend>
                    <label class="sched-radio">
                        <input type="radio" name="allow_actions" value=""<?= $actionMode === ScheduledTask::MODE_READ ? ' checked' : '' ?><?= $actingChecked ? ' disabled' : '' ?>>
                        <span>
                            <strong><?= te('Lire et rédiger') ?></strong>
                            <span class="sched-hint"><?= te('Lecture seule : rien n’est préparé, envoyé ni modifié.') ?></span>
                        </span>
                    </label>
                    <label class="sched-radio">
                        <input type="radio" name="allow_actions" value="1"<?= $actionMode === ScheduledTask::MODE_APPROVE ? ' checked' : '' ?>>
                        <span>
                            <strong><?= te('Préparer des actions à approuver') ?></strong>
                            <span class="sched-hint"><?= te('Brouillons d’email, évènements, tâches, workflows… La tâche les prépare ; chacun attend votre validation dans sa conversation, une semaine au plus.') ?></span>
                        </span>
                    </label>
                    <?php /* Troisième choix : grisé avec sa raison quand l'administrateur
                             ne le propose pas, jamais masqué. */ ?>
                    <label class="sched-radio sched-radio-auto<?= $autoAllowed ? '' : ' is-unavailable' ?>">
                        <input type="radio" name="allow_actions" value="<?= ScheduledTask::MODE_AUTO ?>"<?= $actionMode === ScheduledTask::MODE_AUTO ? ' checked' : '' ?><?= $autoAllowed ? '' : ' disabled' ?>>
                        <span>
                            <strong><?= te('Agir sans validation') ?></strong>
                            <span class="sched-hint"><?= $autoAllowed
                                ? te('La tâche effectue elle-même ce qu’elle prépare — évènements, tâches, messages Slack, fichiers, workflows — sans rien vous demander. Chaque action est notée dans sa conversation.')
                                : te('Non proposé par l’administrateur de votre organisation.') ?></span>
                        </span>
                    </label>
                    <div class="sched-auto-options" id="schedAutoOptions"<?= $actionMode === ScheduledTask::MODE_AUTO ? '' : ' hidden' ?>>
                        <label class="sched-check">
                            <input type="checkbox" name="auto_send_mail" value="1"<?= !empty($form['auto_send_mail']) ? ' checked' : '' ?>>
                            <span>
                                <strong><?= te('Envoyer les emails') ?></strong>
                                <span class="sched-hint"><?= te('Décoché, un email préparé est enregistré en brouillon dans votre boîte : vous le relisez et l’envoyez vous-même.') ?></span>
                            </span>
                        </label>
                        <p class="sched-warn" role="note"><?= te('Rien ne vous sera demandé : un email envoyé ou un message publié ne se rattrape pas. La tâche lit aussi des contenus écrits par d’autres (emails, pages web) : elle a pour consigne de ne jamais suivre leurs instructions, mais aucune consigne n’est infaillible. Au plus {n} actions par exécution ; au-delà, elles attendent votre validation.', ['n' => ScheduledTask::AUTO_ACTIONS_MAX]) ?></p>
                    </div>
                    <?php if ($autoHeld): ?>
                        <p class="sched-forced"><?= te('Cette tâche agissait sans validation : l’administrateur a retiré cette option, ses actions attendent désormais votre validation.') ?></p>
                    <?php endif; ?>
                    <?php /* Outil qui agit apporté par l'assistant, en « Agir sans validation » :
                             ses actions attendront une validation (Scheduler::autoExecutable).
                             Nommé par schedules.js selon l'assistant et les outils cochés. */ ?>
                    <p class="sched-forced" id="schedAssistantToolsHeld" role="note" hidden></p>
                    <p class="sched-forced" id="schedActionsForced"<?= $actingChecked ? '' : ' hidden' ?>><?= te('L’outil choisi agit (automatisation n8n, présentation Gamma) : en lecture seule, il ne servirait à rien.') ?></p>
                </fieldset>
            </section>

            <!-- 3. Quand -->
            <section class="card sched-section" id="schedSectionWhen" aria-labelledby="schedWhenTitle">
                <div class="card-head">
                    <span class="card-ic"><?= $sectionIcon('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="schedWhenTitle"><?= te('Quand') ?></h3>
                        <p><?= te('Un rythme, puis l’heure. Les prochaines exécutions s’affichent en dessous.') ?></p>
                    </div>
                </div>

                <div class="sched-presets" role="radiogroup" aria-label="<?= te('Fréquence') ?>">
                    <?php foreach ($presets as $value => $label): ?>
                        <label class="sched-preset">
                            <input type="radio" name="freq" value="<?= htmlspecialchars($value) ?>"<?= $freq === $value ? ' checked' : '' ?>>
                            <span><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="sched-when-row">
                    <div class="sched-days" id="schedDays" role="group" aria-label="<?= te('Jours') ?>"<?= $freq === Recurrence::MONTHLY ? ' hidden' : '' ?>>
                        <?php for ($d = 1; $d <= 7; $d++): ?>
                            <label class="sched-day" title="<?= htmlspecialchars(Recurrence::dayName($d)) ?>">
                                <input type="checkbox" name="days[]" value="<?= $d ?>"<?= in_array($d, $shownDays, true) ? ' checked' : '' ?>
                                       aria-label="<?= htmlspecialchars(Recurrence::dayName($d)) ?>">
                                <span aria-hidden="true"><?= htmlspecialchars(Format::dayShort($d)) ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                    <div class="sched-monthday" id="schedMonthDay"<?= $freq === Recurrence::MONTHLY ? '' : ' hidden' ?>>
                        <label for="schedDay"><?= te('Le') ?></label>
                        <select id="schedDay" name="day" class="form-select">
                            <?php for ($n = 1; $n <= Recurrence::MONTH_DAY_MAX; $n++): ?>
                                <option value="<?= $n ?>"<?= (string)$form['day'] === (string)$n ? ' selected' : '' ?>><?= $n === 1 ? te('1er') : $n ?></option>
                            <?php endfor; ?>
                            <option value="<?= Recurrence::LAST_DAY ?>"<?= (string)$form['day'] === Recurrence::LAST_DAY ? ' selected' : '' ?>><?= te('dernier jour') ?></option>
                        </select>
                    </div>
                    <div class="sched-time">
                        <label for="schedTime"><?= te('à') ?></label>
                        <input type="time" id="schedTime" name="time" class="form-input" required step="60"
                               value="<?= htmlspecialchars((string)$form['time']) ?>">
                    </div>
                </div>
                <p class="sched-field-error" id="schedWhenError" hidden></p>

                <div class="sched-summary" id="schedSummary" aria-live="polite">
                    <strong id="schedSentence"><?= htmlspecialchars((string)($preview['sentence'] ?? '')) ?></strong>
                    <span id="schedNext"><?= $preview !== null ? te('Prochaines : {dates}', ['dates' => implode(', ', $preview['next'])]) : '' ?></span>
                    <span class="sched-summary-meta" id="schedMeta"><?= $preview !== null ? htmlspecialchars($preview['per_month'] . ' · ' . $preview['zone']) : '' ?></span>
                </div>
            </section>

            <!-- 4. Résultat -->
            <section class="card sched-section" id="schedSectionWhere" aria-labelledby="schedWhereTitle">
                <div class="card-head">
                    <span class="card-ic"><?= $sectionIcon('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="schedWhereTitle"><?= te('Où voir le résultat') ?></h3>
                        <p><?= te('Une pastille signale la conversation dans la barre latérale quand un résultat arrive.') ?></p>
                    </div>
                </div>
                <label class="sched-radio">
                    <input type="radio" name="destination" value="<?= ScheduledTask::DEST_THREAD ?>"<?= $form['destination'] !== ScheduledTask::DEST_NEW ? ' checked' : '' ?>>
                    <span>
                        <strong><?= te('Dans une même conversation, à la suite') ?></strong>
                        <span class="sched-hint"><?= te('L’assistant voit les résultats précédents et peut dire ce qui a changé.') ?></span>
                    </span>
                </label>
                <label class="sched-radio">
                    <input type="radio" name="destination" value="<?= ScheduledTask::DEST_NEW ?>"<?= $form['destination'] === ScheduledTask::DEST_NEW ? ' checked' : '' ?>>
                    <span>
                        <strong><?= te('Une nouvelle conversation à chaque fois') ?></strong>
                        <span class="sched-hint"><?= te('Chaque résultat repart de zéro, avec la date dans le titre.') ?></span>
                    </span>
                </label>
                <label class="sched-check sched-notify">
                    <input type="checkbox" name="notify" value="1"<?= !empty($form['notify']) ? ' checked' : '' ?>>
                    <span>
                        <strong><?= te('Me prévenir sur mes appareils') ?></strong>
                        <span class="sched-hint"><?= te('Une notification quand le résultat arrive, ou quand l’exécution échoue ou est sautée — sur les appareils où vous avez activé les notifications (page Tâches programmées).') ?></span>
                    </span>
                </label>
            </section>

            <?php if (ScheduledTask::isEnabled() && $canRun): ?>
            <!-- 5. Essai -->
            <section class="card sched-section sched-trial" id="schedTrial" aria-labelledby="schedTrialTitle"
                     data-task-id="<?= $isEdit ? (int)$task['id'] : 0 ?>">
                <div class="card-head">
                    <span class="card-ic"><?= $sectionIcon('<polygon points="6 4 20 12 6 20 6 4"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="schedTrialTitle"><?= te('Essayer') ?></h3>
                        <p><?= te('Lancez la tâche une fois, maintenant, avec les réglages ci-dessus : rien n’est enregistré. L’essai consomme des crédits comme un message, et n’agit jamais sans vous : ses actions attendent votre validation, même si la tâche agit sans validation.') ?></p>
                    </div>
                </div>
                <div class="sched-trial-bar">
                    <button type="button" class="btn-secondary" id="schedTrialBtn"><?= te('Lancer un essai') ?></button>
                    <button type="button" class="btn-secondary" id="schedTrialStop" hidden><?= te('Arrêter') ?></button>
                    <span class="sched-trial-status" id="schedTrialStatus" aria-live="polite"></span>
                </div>
                <p class="sched-field-error" id="schedTrialError" role="alert" hidden></p>
                <div class="sched-trial-result" id="schedTrialResult" hidden>
                    <ul class="sched-trial-actions" id="schedTrialActions" hidden></ul>
                    <div class="sched-trial-text" id="schedTrialText"></div>
                    <div class="sched-trial-failed" id="schedTrialFailed" hidden><strong></strong><span></span></div>
                    <p class="sched-trial-foot" id="schedTrialFoot" hidden>
                        <span class="badge badge-yellow" id="schedTrialPending" hidden></span>
                        <a id="schedTrialLink" href="#" target="_blank" rel="noopener"><?= te('Ouvrir la conversation de l’essai') ?></a>
                    </p>
                    <p class="sched-hint" id="schedTrialHeld" hidden><?= te('Pendant un essai, rien n’est fait sans vous. Programmée, la tâche effectuera ces actions elle-même.') ?></p>
                </div>
            </section>
            <?php endif; ?>

            <div class="sched-actions">
                <a class="btn-secondary" href="/settings/schedules"><?= te('Annuler') ?></a>
                <button type="submit" class="btn-primary"<?= $limitReached ? ' disabled' : '' ?>><?= $isEdit ? te('Enregistrer') : te('Programmer') ?></button>
            </div>
        </form>

        <?php if ($isEdit): ?>
            <section class="card sched-section sched-history" aria-labelledby="schedHistoryTitle">
                <div class="sched-history-head">
                    <h3 id="schedHistoryTitle"><?= te('Dernières exécutions') ?></h3>
                    <?php if (ScheduledTask::isEnabled() && $canRun): ?>
                    <form class="sched-run-form" method="POST" action="/settings/schedules/<?= (int)$task['id'] ?>/run">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn-secondary"><?= te('Exécuter maintenant') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <p class="sched-item-error" data-role="error" hidden></p>
                <?php if ($runs === []): ?>
                    <p class="sched-hint"><?= te('Aucune exécution pour l’instant.') ?></p>
                <?php else: ?>
                    <ul class="sched-runs">
                        <?php foreach ($runs as $run): ?>
                            <li class="sched-run-<?= htmlspecialchars($run['status']) ?>">
                                <span class="sched-run-status"><?= htmlspecialchars($run['label']) ?></span>
                                <span><?= htmlspecialchars($run['when']) ?><?= $run['manual'] ? ' · ' . te('à la demande') : '' ?></span>
                                <?php if ($run['reason'] !== ''): ?><span class="sched-hint"><?= htmlspecialchars($run['reason']) ?></span><?php endif; ?>
                                <?php if ($run['conversation_id'] !== null && in_array($run['status'], ['done', 'failed', 'queued'], true)): ?>
                                    <a href="/chat/<?= (int)$run['conversation_id'] ?>"><?= te('Voir') ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($canDelete): ?>
            <form class="sched-delete-form" method="POST" action="/settings/schedules/<?= (int)$task['id'] ?>/delete"
                  data-confirm="<?= te('Supprimer cette tâche ? Ses conversations restent dans votre historique.') ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-danger-link"><?= te('Supprimer la tâche') ?></button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        </div>
    </div>
</div>

<script>
// Fiches des assistants (aperçu sous le sélecteur) : calculées par le serveur,
// affichées telles quelles par schedules.js.
window.SCHED_FORM = <?= json_encode([
    'assistants' => (object)$assistantCards,
    'mia'        => ['name' => $appName, 'avatar' => $miaLogo, 'description' => t('Sans assistant : choisissez le modèle ci-dessous.')],
    // Assistant enregistré devenu inaccessible (option « à remplacer »).
    'missing'    => $missingCard,
    // Schéma : catalogue (libellés, icônes, fiches) et déclencheur du premier aperçu.
    'diagram'    => $diagram,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$engineJsVersion ?>" defer></script>
<script src="/assets/js/schedule-diagram.js?v=<?= (int)$diagramJsVersion ?>" defer></script>
<script src="/assets/js/schedules.js?v=<?= (int)$schedulesJsVersion ?>" defer></script>
</body>
</html>
