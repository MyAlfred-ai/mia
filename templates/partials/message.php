<?php /** @var array $msg */ ?>
<?php
// État « agent » de la réponse (migration 078) : plan de travail affiché, et
// raison de la poursuivre (bouton « Continuer », posé par chat.js).
$msgAgent = $msg['role'] === 'assistant' ? Message::agentOf($msg) : [];
$msgPlan  = isset($msgAgent['plan']) && is_array($msgAgent['plan']) ? PlanTools::normalize($msgAgent['plan']) : null;
$msgSuite = in_array($msgAgent['suite'] ?? null, ['plan', 'budget'], true) ? $msgAgent['suite'] : '';
// Suggestion de suite déjà calculée (FollowUpSuggestion, '' = aucune suite) :
// chat.js la pose dans la zone de saisie sans nouvel appel. NOTE
// SYNCHRONISATION : appendMessage() (chat.js) pose le même attribut.
$msgSuggestion = $msg['role'] === 'assistant' ? FollowUpSuggestion::storedOf($msg) : null;
?>
<div class="message message-<?= $msg['role'] ?>" data-message-id="<?= (int)$msg['id'] ?>"<?= $msgSuite !== '' ? ' data-suite="' . $msgSuite . '"' : '' ?><?= $msgSuggestion !== null ? ' data-suggestion="' . htmlspecialchars($msgSuggestion) . '"' : '' ?>>
    <?php if ($msg['role'] === 'assistant'): ?>
        <div class="msg-avatar assistant-avatar">
            <?php $avatarSrc = !empty($profileAvatar) ? '/assets/img/profils/' . htmlspecialchars(basename($profileAvatar)) : htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')); ?>
            <img src="<?= $avatarSrc ?>" alt="<?= te('assistant') ?>" style="width:50px;height:50px;border-radius:50%;object-fit:cover">
        </div>
    <?php else: ?>
        <?php $u = Session::currentUser(); ?>
        <?php if (!empty($u['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" class="msg-avatar user-avatar" alt="" style="width:32px;height:32px">
        <?php else: ?>
            <div class="msg-avatar user-avatar-fallback">?</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="msg-body">
        <?php
        // Trace des actions effectuées par MIA avant de répondre (migration 064).
        // HORS .msg-content : jamais dans data-raw, donc jamais dans Copier ni
        // les exports PDF/Word. NOTE SYNCHRONISATION : renderActionTrace()
        // (chat.js) rend la même structure côté client (live + historique JSON).
        $msgActionsTrace = $msg['role'] === 'assistant' && !empty($msg['actions_json'])
            ? (json_decode((string)$msg['actions_json'], true) ?: [])
            : [];
        $traceVisible = 5; // = ACTION_TRACE_VISIBLE (chat.js)
        // Icône : la NATURE de l'action (mail, agenda, tâche, fichier…) plutôt
        // qu'une coche « fait » identique partout. Clé résolue par PHP (source
        // unique ChatController::ACTION_ICONS), glyphe posé par main.css —
        // aucun SVG ici ni dans chat.js, deux copies divergeraient.
        ?>
        <?php if ($msgPlan !== null):
            // Carte du plan de travail. NOTE SYNCHRONISATION : renderPlanCard()
            // (chat.js) rend la même structure (live + historique JSON).
            $planLabels = ['a_faire' => te('À faire'), 'en_cours' => te('En cours'), 'fait' => te('Fait'), 'abandonne' => te('Abandonnée')];
            $planDone = count(array_filter($msgPlan['etapes'], static fn(array $s): bool => $s['statut'] === 'fait'));
        ?>
        <div class="msg-plan">
            <?php
                // « Refaire en workflow » : un plan d'au moins deux étapes devient
                // un workflow (/workflows/new?from=). Même rendu que renderPlanCard().
                $workflowsUsable ??= Workflow::allows(Session::currentUser(), 'create');
                $planToWorkflow = $workflowsUsable && count($msgPlan['etapes']) >= 2;
            ?>
            <div class="msg-plan-head"><span class="msg-plan-icon" aria-hidden="true"></span><span><?= te('Plan de travail') ?></span><span class="msg-plan-count"><?= $planDone ?>/<?= count($msgPlan['etapes']) ?></span><?php if ($planToWorkflow): ?><a class="msg-plan-workflow" href="/workflows/new?from=<?= (int)$msg['id'] ?>" title="<?= te('Faire de ce plan un workflow réutilisable') ?>"><?= Workflow::icon(12) ?><?= te('Refaire en workflow') ?></a><?php endif; ?></div>
            <ol class="msg-plan-steps">
                <?php foreach ($msgPlan['etapes'] as $planStep): ?>
                <li class="msg-plan-step" data-status="<?= htmlspecialchars($planStep['statut']) ?>"><span class="msg-plan-mark" aria-hidden="true"></span><span class="msg-plan-title"><?= htmlspecialchars($planStep['titre']) ?></span><span class="msg-plan-status"><?= $planLabels[$planStep['statut']] ?></span></li>
                <?php endforeach; ?>
            </ol>
            <?php if (!empty($msgPlan['commentaire'])): ?><div class="msg-plan-note"><?= htmlspecialchars($msgPlan['commentaire']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($msgActionsTrace): ?>
        <div class="msg-trace">
            <?php foreach ($msgActionsTrace as $i => $ta):
                if (!is_array($ta)) continue;
                $taFailed = array_key_exists('ok', $ta) && !$ta['ok'];
                $taDetail = trim((string)($ta['d'] ?? ''));
                $taIcon   = $taFailed ? 'warn' : ChatController::actionIcon((string)($ta['t'] ?? ''));
            ?>
            <div class="msg-trace-row<?= $taFailed ? ' is-failed' : '' ?>"<?= $i >= $traceVisible ? ' hidden' : '' ?>>
                <span class="msg-trace-icon" data-icon="<?= htmlspecialchars($taIcon) ?>" aria-hidden="true"></span>
                <?php /* Libellé RÉSOLU depuis le type, jamais affiché tel qu'il a été
                         persisté : un message écrit en français doit se relire dans la
                         langue courante. Le libellé enregistré ne sert que de repli
                         pour un type disparu du catalogue depuis. */ ?>
                <span class="msg-trace-label"><?= htmlspecialchars(ChatController::actionLabel((string)($ta['t'] ?? ''), (string)($ta['l'] ?? ''))) ?></span>
                <?php if ($taDetail !== ''): ?><span class="msg-trace-detail"><?= htmlspecialchars($taDetail) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php $traceHidden = count($msgActionsTrace) - $traceVisible; if ($traceHidden > 0): ?>
            <?php /* I18n::plural et non « > 1 » : le français met 0 et 1 au singulier,
                     l'anglais met 0 au pluriel. Même formulation que renderActionTrace()
                     dans chat.js — les deux rendus doivent rester synchronisés. */ ?>
            <button type="button" class="msg-trace-more"><?= htmlspecialchars(I18n::plural($traceHidden, '+{n} autre action', '+{n} autres actions')) ?></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
        // Résoudre les fichiers : files_json (multi) > legacy (mono)
        $msgFiles = [];
        if (!empty($msg['files_json'])) {
            $msgFiles = json_decode($msg['files_json'], true) ?: [];
        } elseif (!empty($msg['file_name'])) {
            $msgFiles = [[
                'name'      => $msg['file_name'],
                'type'      => $msg['file_type'] ?? '',
                'extracted' => $msg['file_extracted'] ?? '',
            ]];
        }

        // Détecter si le message contient une page HTML, vidéo ou carte générée
        $hasRichAttachment = false;
        foreach ($msgFiles as $mf) {
            $t = $mf['type'] ?? '';
            if ($t === 'html_page' || $t === 'video_file' || $t === 'map_route' || $t === 'slide_deck') { $hasRichAttachment = true; break; }
        }

        // Bouton « Partager » des cartes de créations (lien unique /share/{token},
        // visible par les utilisateurs connectés). Token vide = pas encore partagé.
        $shareToken   = isset($sharedTokens) ? (string)($sharedTokens[(int)$msg['id']] ?? '') : '';
        $shareCardBtn = '<button class="btn-share-creation btn-secondary' . ($shareToken !== '' ? ' shared' : '') . '"'
            . ' data-msg-id="' . (int)$msg['id'] . '"'
            . ' data-share-token="' . htmlspecialchars($shareToken) . '"'
            . ' title="' . te('Partager par lien (visible uniquement par les utilisateurs connectés)') . '"'
            . ' style="font-size:12px;padding:4px 10px">'
            . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>'
            . te($shareToken !== '' ? 'Partagé' : 'Partager') . '</button>';

        foreach ($msgFiles as $mf):
            if (($mf['type'] ?? '') === 'html_page'): ?>
                <div class="msg-html-card">
                    <div class="msg-html-card-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                    </div>
                    <div class="msg-html-card-info">
                        <span class="msg-html-card-name"><?= htmlspecialchars($mf['name'] ?? 'page.html') ?></span>
                        <span style="color:var(--text-muted);font-size:11px"><?= te('{taille} Ko', ['taille' => number_format(strlen($msg['content']) / 1024, 1)]) ?></span>
                    </div>
                    <div class="msg-html-card-actions">
                        <button class="btn-open-html-viewer btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg><?= te('Ouvrir') ?>
                        </button>
                        <button class="btn-download-html btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                data-filename="<?= htmlspecialchars($mf['name'] ?? 'page.html') ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><?= te('Télécharger') ?>
                        </button>
                        <button class="btn-open-html-external btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg><?= te('Fenêtre') ?>
                        </button>
                        <?= $shareCardBtn ?>
                    </div>
                </div>
            <?php elseif (($mf['type'] ?? '') === 'map_route'): ?>
                <?php
                    $mapSpec  = json_decode((string)($msg['content'] ?? ''), true) ?: [];
                    $mapTitle = trim((string)($mapSpec['title'] ?? '')) ?: t('Itinéraire');
                    $mapSteps = is_array($mapSpec['waypoints'] ?? null) ? count($mapSpec['waypoints']) : 0;
                ?>
                <div class="msg-html-card msg-map-card">
                    <div class="msg-html-card-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/>
                            <line x1="8" y1="3" x2="8" y2="18"/>
                            <line x1="16" y1="6" x2="16" y2="21"/>
                        </svg>
                    </div>
                    <div class="msg-html-card-info">
                        <span class="msg-html-card-name">🗺 <?= htmlspecialchars($mapTitle) ?></span>
                        <span style="color:var(--text-muted);font-size:11px"><?= htmlspecialchars(I18n::plural($mapSteps, '{n} étape', '{n} étapes')) ?></span>
                    </div>
                    <div class="msg-html-card-actions">
                        <button class="btn-open-map-viewer btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg><?= te('Ouvrir la carte') ?>
                        </button>
                        <?= $shareCardBtn ?>
                    </div>
                </div>
            <?php elseif (($mf['type'] ?? '') === 'slide_deck'): ?>
                <?php
                    $deckSpec   = json_decode((string)($msg['content'] ?? ''), true) ?: [];
                    $deckTitle  = trim((string)($deckSpec['title'] ?? '')) ?: t('Présentation');
                    $deckSlides = is_array($deckSpec['slides'] ?? null) ? count($deckSpec['slides']) : 0;
                ?>
                <div class="msg-html-card msg-deck-card">
                    <div class="msg-html-card-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="20" height="13" rx="2"/>
                            <path d="M8 21h8M12 16v5"/>
                        </svg>
                    </div>
                    <div class="msg-html-card-info">
                        <span class="msg-html-card-name">📊 <?= htmlspecialchars($deckTitle) ?></span>
                        <span style="color:var(--text-muted);font-size:11px"><?= htmlspecialchars(I18n::plural($deckSlides, '{n} slide', '{n} slides')) ?></span>
                    </div>
                    <div class="msg-html-card-actions">
                        <button class="btn-open-deck btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg><?= te('Ouvrir') ?>
                        </button>
                        <?= $shareCardBtn ?>
                    </div>
                </div>
            <?php elseif (($mf['type'] ?? '') === 'video_file'): ?>
                <?php
                    $videoPath = $mf['path'] ?? '';
                    $videoName = $mf['name'] ?? ($videoPath ? basename($videoPath) : 'video.mp4');
                ?>
                <div class="msg-video-card">
                    <div class="msg-video-card-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <polygon points="10 9 16 12 10 15"/>
                        </svg>
                    </div>
                    <div class="msg-video-card-info">
                        <span class="msg-video-card-name"><?= htmlspecialchars($videoName) ?></span>
                        <span style="color:var(--text-muted);font-size:11px"><?= te('Vidéo MP4') ?></span>
                    </div>
                    <div class="msg-video-card-actions">
                        <button class="btn-open-video-viewer btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                data-video-url="<?= htmlspecialchars($videoPath) ?>"
                                data-filename="<?= htmlspecialchars($videoName) ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 16 12 10 15"/></svg><?= te('Ouvrir') ?>
                        </button>
                        <button class="btn-download-video btn-secondary"
                                data-msg-id="<?= $msg['id'] ?>"
                                data-video-url="<?= htmlspecialchars($videoPath) ?>"
                                data-filename="<?= htmlspecialchars($videoName) ?>"
                                style="font-size:12px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><?= te('Télécharger') ?>
                        </button>
                        <?= $shareCardBtn ?>
                    </div>
                </div>
            <?php elseif (str_starts_with($mf['type'] ?? '', 'image/') && !empty($mf['extracted'])): ?>
                <div class="msg-image-wrap">
                    <img src="data:<?= htmlspecialchars($mf['type']) ?>;base64,<?= htmlspecialchars($mf['extracted']) ?>"
                         alt="<?= htmlspecialchars($mf['name']) ?>"
                         class="msg-image">
                    <div class="msg-image-name"><?= htmlspecialchars($mf['name']) ?></div>
                </div>
            <?php else: ?>
                <div class="msg-file">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <?= htmlspecialchars($mf['name']) ?>
                </div>
            <?php endif;
        endforeach; ?>

        <?php if (!$hasRichAttachment): ?>
        <div class="msg-content"
             data-role="<?= $msg['role'] ?>"
             data-message-id="<?= (int)$msg['id'] ?>"
             data-raw="<?= htmlspecialchars($msg['content']) ?>"><?= $msg['role'] === 'assistant'
                ? '<span class="md-render"></span>'
                : htmlspecialchars($msg['content']) ?></div>
        <?php endif; ?>

        <?php if ($msg['role'] === 'user' && trim((string)($msg['content'] ?? '')) !== ''): ?>
        <!-- Bouton Copier du message utilisateur — révélé au clic sur le texte (chat.js) -->
        <div class="msg-actions msg-actions-user">
            <button class="msg-action-btn btn-copy-user" title="<?= te('Copier le message') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <?= te('Copier') ?>
            </button>
        </div>
        <?php endif; ?>

        <?php if ($msg['role'] === 'assistant'):
            // Badge LLM ayant généré la réponse (provider + modèle).
            // NULL pour messages historiques (avant migration 026) → pas de badge.
            $llmProv  = trim((string)($msg['llm_provider'] ?? ''));
            $llmModel = trim((string)($msg['llm_model']    ?? ''));
            if ($llmProv !== ''):
                $llmLogos     = LLMFactory::logos();
                $llmProvLogo  = $llmLogos[$llmProv] ?? null;
                $llmProvLabel = ucfirst($llmProv);
                $llmModelInfo = $llmModel !== '' ? LlmCatalog::model($llmProv, $llmModel) : null;
                $llmModelLabel = is_array($llmModelInfo) && !empty($llmModelInfo['label'])
                    ? (string)$llmModelInfo['label']
                    : $llmModel;
        ?>
        <?php $msgCredits = (int)($msg['credits_used'] ?? 0); ?>
        <div class="msg-llm-badge" title="<?= htmlspecialchars($llmProvLabel . ($llmModelLabel !== '' ? ' · ' . $llmModelLabel : '')) ?>">
            <?php if ($llmProvLogo): ?>
                <img src="<?= htmlspecialchars($llmProvLogo) ?>" alt="<?= htmlspecialchars($llmProvLabel) ?>" class="msg-llm-badge-logo" width="16" height="16" style="width:16px;height:16px;flex:0 0 16px;border-radius:50%;object-fit:contain">
            <?php endif; ?>
            <span class="msg-llm-badge-text">
                <?php if ($llmModelLabel !== ''): ?>
                    <?= htmlspecialchars($llmModelLabel) ?>
                <?php else: ?>
                    <?= htmlspecialchars($llmProvLabel) ?>
                <?php endif; ?>
            </span>
            <?php /* Entre {n} et l'unité : U+00A0, le caractère lui-même (invisible
                     ici). te() échappe sa sortie : l'entité HTML s'afficherait en
                     toutes lettres sous chaque réponse. */ ?>
            <span class="msg-llm-badge-credits"<?= $msgCredits > 0 ? '' : ' hidden' ?> title="<?= te('Crédits consommés par cette réponse') ?>">
                · <?= te('{n} crédits', ['n' => Format::credits($msgCredits)]) ?>
            </span>
            <?php $msgRespMs = isset($msg['response_ms']) && $msg['response_ms'] !== null ? (int)$msg['response_ms'] : 0; ?>
            <span class="msg-llm-badge-time"<?= $msgRespMs > 0 ? '' : ' hidden' ?> title="<?= te('Temps de génération de la réponse') ?>">
                · <?= te('{n} s', ['n' => number_format($msgRespMs / 1000, 1, ',', ' ')]) ?>
            </span>
        </div>
        <?php endif; ?>
        <?php
            // Détecte un message qui ne contient qu'une image markdown (outil "Imagine")
            // → on remplace "Exporter en PDF" par "Sauvegarder" (téléchargement local).
            $isImageOnly = (bool)preg_match('/^\s*!\[[^\]]*\]\([^)]+\)\s*$/u', (string)($msg['content'] ?? ''));
        ?>
        <?php if (!$hasRichAttachment): /* Une création (deck, page web, carte, vidéo) porte
            ses propres actions sur sa carte : « Copier » recopierait sa spec JSON,
            « Regénérer » produirait une réponse de chat ordinaire, et les exports
            PDF/Word travailleraient sur du JSON. */ ?>
        <div class="msg-actions" data-image-only="<?= $isImageOnly ? '1' : '0' ?>">
            <button class="msg-action-btn btn-copy" title="<?= te($isImageOnly ? 'Copier l\'image dans le presse-papier' : 'Copier la réponse') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <?= te('Copier') ?>
            </button>
            <button class="msg-action-btn btn-regen" title="<?= te('Regénérer la réponse') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                <?= te('Regénérer') ?>
            </button>
            <?php if ($isImageOnly): ?>
            <button class="msg-action-btn btn-save-image" title="<?= te('Télécharger l\'image sur l\'ordinateur') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= te('Télécharger l\'image') ?>
            </button>
            <button class="msg-action-btn btn-share-creation<?= $shareToken !== '' ? ' shared' : '' ?>"
                    data-msg-id="<?= (int)$msg['id'] ?>"
                    data-share-token="<?= htmlspecialchars($shareToken) ?>"
                    title="<?= te('Partager par lien (visible uniquement par les utilisateurs connectés)') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                <?= te($shareToken !== '' ? 'Partagé' : 'Partager') ?>
            </button>
            <?php else: ?>
            <button class="msg-action-btn btn-export-pdf" title="<?= te('Exporter la réponse en PDF') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                <?= te('Exporter en PDF') ?>
            </button>
            <button class="msg-action-btn btn-export-docx" title="<?= te('Exporter la réponse en Word (.docx)') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13l1 4 1.5-3 1.5 3 1-4"/></svg>
                <?= te('Exporter en Word') ?>
            </button>
            <?php
                // « Refaire régulièrement » : la demande qui a produit cette
                // réponse devient une tâche programmée (formulaire pré-rempli).
                // Un lien, pour marcher sans JavaScript ; chat.js y ajoute les
                // sources cochées dans le menu « + ». Même rendu que
                // buildAssistantActionsHtml() (chat.js) — à garder synchronisés.
                // Programmer = droit « Tâches programmées — Créer » du rôle.
                $schedulesEnabled ??= ScheduledTask::allows(Session::currentUser(), 'create');
            ?>
            <?php if ($schedulesEnabled && !str_starts_with(ltrim((string)($msg['content'] ?? '')), '⚠')): ?>
            <a class="msg-action-btn btn-schedule" href="/settings/schedules/new?from=<?= (int)$msg['id'] ?>" title="<?= te('Programmer cette demande pour qu’elle se répète') ?>">
                <?= ScheduledTask::icon(14) ?>
                <?= te('Refaire régulièrement') ?>
            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; /* fin !$hasRichAttachment */ ?>
        <?php endif; ?>
    </div>
</div>
