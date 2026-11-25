<?php
/**
 * Carte de réalisation d'une « Version soignée » (NativeDeckRun::view()).
 *
 * SEUL rendu de la carte : la page (message.php), le lancement, l'historique
 * JSON et la fin du job renvoient ce HTML tout fait (NativeDeckRun::html()).
 * public/assets/js/native-run.js l'anime avec les textes posés ici en
 * attributs data-* — il n'en a aucun.
 *
 * @var array<string, mixed> $nativeRun
 */
$runStatus  = (string)$nativeRun['status'];
$runRunning = $runStatus === NativeDeckRun::RUNNING;
$runPhases  = json_encode($nativeRun['phases'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<div class="native-run is-<?= htmlspecialchars($runStatus) ?>"
     data-status="<?= htmlspecialchars($runStatus) ?>"
     data-tone="<?= htmlspecialchars((string)$nativeRun['tone']) ?>"
     data-job-id="<?= (int)$nativeRun['job_id'] ?>"
     data-message-id="<?= (int)$nativeRun['message_id'] ?>"
<?php if ($runRunning): ?>
     data-elapsed="<?= (int)$nativeRun['elapsed'] ?>"
     data-cap="<?= NativeDeckRun::PROGRESS_CAP ?>"
     data-tau="<?= NativeDeckRun::PROGRESS_TAU ?>"
     data-slow-after="<?= (int)$nativeRun['slow_after'] ?>"
     data-slow-text="<?= htmlspecialchars((string)$nativeRun['slow_text']) ?>"
     data-phases="<?= htmlspecialchars((string)$runPhases) ?>"
     data-fmt-sec="<?= htmlspecialchars((string)$nativeRun['fmt_sec']) ?>"
     data-fmt-min="<?= htmlspecialchars((string)$nativeRun['fmt_min']) ?>"
     data-confirm="<?= htmlspecialchars((string)$nativeRun['confirm']) ?>"
     data-stopping="<?= htmlspecialchars((string)$nativeRun['stopping']) ?>"
     data-failed-state="<?= htmlspecialchars((string)$nativeRun['failed_state']) ?>"
     data-failed-by="<?= htmlspecialchars((string)$nativeRun['failed_by']) ?>"
<?php endif; ?>
     style="--run-progress: <?= $runStatus === NativeDeckRun::DONE ? 100 : (int)$nativeRun['progress'] ?>%">
    <span class="native-run-sheen" aria-hidden="true"></span>
    <div class="native-run-main">
        <div class="native-run-badge" aria-hidden="true">
            <span class="native-run-orbit"></span>
            <span class="native-run-ext"><?= htmlspecialchars((string)$nativeRun['ext']) ?></span>
            <?php /* Pastille d'état (coche, arrêt, alerte) : dessinée par files.css
                     selon .is-done / .is-cancelled / .is-failed — native-run.js n'a
                     qu'une classe à changer quand la réalisation échoue en direct. */ ?>
            <span class="native-run-mark"></span>
        </div>
        <div class="native-run-text">
            <div class="native-run-kicker">
                <svg class="native-run-spark" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.2 6.6L21 11l-6.8 2.4L12 20l-2.2-6.6L3 11l6.8-2.4z"/></svg>
                <span><?= htmlspecialchars((string)$nativeRun['kicker']) ?></span>
                <span class="native-run-state"><?= htmlspecialchars((string)$nativeRun['state']) ?></span>
            </div>
            <div class="native-run-title"><?= htmlspecialchars((string)$nativeRun['title']) ?></div>
            <div class="native-run-by">
                <?php if (!empty($nativeRun['logo'])): ?><img src="<?= htmlspecialchars((string)$nativeRun['logo']) ?>" alt="" width="16" height="16"><?php endif; ?>
                <span data-run-by><?= htmlspecialchars((string)$nativeRun['by']) ?></span>
            </div>
        </div>
    </div>
    <?php if ($runRunning): ?>
    <div class="native-run-track" aria-hidden="true"><span class="native-run-bar"></span></div>
    <div class="native-run-foot">
        <span class="native-run-phase" data-run-phase role="status" aria-live="polite"><?= htmlspecialchars((string)$nativeRun['phase']) ?></span>
        <span class="native-run-time"><span data-run-elapsed><?= htmlspecialchars((string)$nativeRun['elapsed_text']) ?></span> · <?= htmlspecialchars((string)$nativeRun['window']) ?></span>
    </div>
    <div class="native-run-actions">
        <span class="native-run-hint"><?= htmlspecialchars((string)$nativeRun['hint']) ?></span>
        <?php if ((int)$nativeRun['job_id'] > 0): ?>
        <button type="button" class="native-run-stop">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2.5"/></svg>
            <?= htmlspecialchars((string)$nativeRun['stop']) ?>
        </button>
        <?php endif; ?>
    </div>
    <?php elseif ($runStatus === NativeDeckRun::DONE && $nativeRun['url'] !== ''): ?>
    <div class="native-run-actions">
        <a class="native-run-download" href="<?= htmlspecialchars((string)$nativeRun['url']) ?>" data-filename="<?= htmlspecialchars((string)$nativeRun['file']) ?>" title="<?= htmlspecialchars((string)$nativeRun['download']) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="native-run-download-label"><?= htmlspecialchars((string)$nativeRun['download']) ?></span>
            <span class="native-run-file"><?= htmlspecialchars((string)$nativeRun['file']) ?></span>
        </a>
        <span class="native-run-hint"><?= htmlspecialchars((string)$nativeRun['kept']) ?></span>
    </div>
    <?php endif; ?>
</div>
