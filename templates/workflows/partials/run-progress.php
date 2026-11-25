<?php
/**
 * Volet « Avancement » d'une exécution, dans SA conversation (chat).
 * Rendu par WorkflowController::progress et posé tel quel par
 * public/assets/js/workflow-progress.js (aucun texte côté script).
 *
 * @var array $p      WorkflowProgress::view
 * @var array $icons  WorkflowCatalog::icons
 */
$ic = static fn(string $k): string => $icons[$k] ?? $icons['step'] ?? '';
$stateLabel = [
    'done' => t('Fait'), 'run' => t('En cours'), 'wait' => t('Vous attend'), 'err' => t('En échec'),
    'off' => t('Arrêtée'), 'skip' => t('Ignorée'), 'todo' => t('À venir'),
];
$a = $p['attention'];
?>
<div class="wfp" data-wfp-run="<?= (int)$p['run_id'] ?>" data-wfp-poll="<?= (int)$p['poll'] ?>" data-wfp-sig="<?= htmlspecialchars((string)$p['signature']) ?>" data-wfp-terminal="<?= $p['terminal'] ? '1' : '0' ?>" style="--hue:<?= htmlspecialchars($p['color']) ?>">
    <header class="wfp-head">
        <span class="wfp-ic"><?= Workflow::icon(16) ?></span>
        <div class="wfp-title">
            <small><?= te('Workflow') ?></small>
            <strong title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></strong>
        </div>
        <button type="button" class="wfp-close" data-wfp-act="close" aria-label="<?= te('Masquer l’avancement') ?>" title="<?= te('Masquer l’avancement') ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </header>
    <div class="wfp-status">
        <span class="wfp-pill <?= htmlspecialchars($p['status_tone']) ?>"><?= htmlspecialchars($p['status_label']) ?></span>
        <span class="wfp-count"><?= te('{n} / {total} étapes', ['n' => $p['done'], 'total' => $p['total']]) ?></span>
        <?php if ($p['credits'] > 0): ?><span class="wfp-credits"><?= te('{n} crédits', ['n' => Format::credits($p['credits'])]) ?></span><?php endif; ?>
    </div>
    <div class="wfp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int)$p['total'] ?>" aria-valuenow="<?= (int)$p['done'] ?>" aria-label="<?= te('Avancement') ?>"><i style="width:<?= $p['total'] > 0 ? (int)round($p['done'] * 100 / $p['total']) : 0 ?>%"></i></div>

    <?php if ($a !== null): ?>
        <div class="wfp-attn is-<?= htmlspecialchars($a['kind']) ?>" role="status">
            <?php switch ($a['kind']):
                case 'gate': ?>
                    <b><?= te('Votre validation est attendue') ?></b>
                    <p><?= te('« {titre} » : relisez et décidez dans la conversation.', ['titre' => $a['title']]) ?></p>
                    <div class="wfp-row">
                        <?php if ($a['action_id'] > 0): ?><button type="button" class="wfp-btn is-primary" data-wfp-act="scroll" data-wfp-card="<?= (int)$a['action_id'] ?>"><?= te('Voir la carte') ?></button><?php endif; ?>
                        <?php if ($a['loop_gate'] !== ''): ?><a class="wfp-btn" href="<?= htmlspecialchars($p['detail_url']) ?>"><?= te('Choisir dans le suivi détaillé') ?></a><?php endif; ?>
                    </div>
                    <?php break;
                case 'doubt': ?>
                    <b><?= te('MIA hésite : à vous de choisir') ?></b>
                    <p><?= htmlspecialchars($a['title']) ?></p>
                    <?php if ($a['reason'] !== ''): ?><p class="wfp-why"><?= htmlspecialchars(mb_substr($a['reason'], 0, 300)) ?></p><?php endif; ?>
                    <div class="wfp-row">
                        <?php if ($a['action_id'] > 0): ?><button type="button" class="wfp-btn is-primary" data-wfp-act="scroll" data-wfp-card="<?= (int)$a['action_id'] ?>"><?= te('Voir la carte') ?></button><?php endif; ?>
                    </div>
                    <?php break;
                case 'cards': ?>
                    <b><?= te('Des actions attendent votre validation') ?></b>
                    <p><?= te('Approuvez-les dans la conversation : le workflow reprend ensuite tout seul.') ?></p>
                    <div class="wfp-row"><button type="button" class="wfp-btn is-primary" data-wfp-act="scroll"><?= te('Voir les cartes') ?></button></div>
                    <?php break;
                case 'call_wait': ?>
                    <b><?= te('« {nom} » attend votre décision', ['nom' => $a['name']]) ?></b>
                    <p><?= te('Ce workflow l’a appelé et l’attend : décidez dans son exécution, celui-ci reprendra tout seul.') ?></p>
                    <div class="wfp-row"><a class="wfp-btn is-primary" href="<?= htmlspecialchars($a['url']) ?>"><?= te('Ouvrir son exécution') ?></a></div>
                    <?php break;
                case 'call': ?>
                    <b><?= te('« {nom} » travaille', ['nom' => $a['name']]) ?></b>
                    <p><?= te('Ce workflow l’a appelé et reprendra dès qu’il aura terminé.') ?></p>
                    <div class="wfp-row"><a class="wfp-btn" href="<?= htmlspecialchars($a['url']) ?>"><?= te('Suivre son exécution') ?></a></div>
                    <?php break;
                case 'timer': ?>
                    <b><?= $a['retry'] ? te('Nouvel essai automatique') : te('En attente') ?></b>
                    <p><?= te('Le workflow reprendra seul le {quand}.', ['quand' => $a['at']]) ?></p>
                    <?php break;
                case 'failed': ?>
                    <b><?= htmlspecialchars($a['title'] !== '' ? $a['title'] : t('Le workflow s’est arrêté sur une erreur')) ?></b>
                    <?php if ($a['message'] !== ''): ?><p><?= htmlspecialchars(mb_substr($a['message'], 0, 400)) ?></p><?php endif; ?>
                    <?php if ($a['resumable']): ?>
                        <p class="wfp-why"><?= te('Une fois la cause réglée (un compte relié, par exemple), reprenez : ce qui est fait n’est pas refait.') ?></p>
                        <div class="wfp-row">
                            <button type="button" class="wfp-btn is-primary" data-wfp-act="retry"><?= te('Reprendre') ?></button>
                            <?php if ($a['latest'] > 0): ?><button type="button" class="wfp-btn" data-wfp-act="retry-latest"><?= te('Reprendre avec la version {v}', ['v' => $a['latest']]) ?></button><?php endif; ?>
                            <?php if (!empty($a['child_url'])): ?><a class="wfp-btn" href="<?= htmlspecialchars($a['child_url']) ?>"><?= te('Ouvrir l’exécution appelée') ?></a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php break;
                case 'paused': ?>
                    <b><?= te('Le workflow est en pause') ?></b>
                    <?php if ($a['message'] !== ''): ?><p><?= htmlspecialchars(mb_substr($a['message'], 0, 400)) ?></p><?php endif; ?>
                    <?php if ($a['resumable']): ?><div class="wfp-row"><button type="button" class="wfp-btn is-primary" data-wfp-act="resume"><?= te('Reprendre') ?></button></div><?php endif; ?>
                    <?php break;
                case 'done': ?>
                    <b><?= te('Terminé : le livrable est prêt') ?></b>
                    <p><?= te('Il est dans la conversation et dans le dossier du suivi détaillé.') ?></p>
                    <?php break;
                case 'stopped': ?>
                    <b><?= te('Arrêté comme prévu') ?></b>
                    <?php break;
                case 'cancelled': ?>
                    <b><?= te('Arrêté') ?></b>
                    <?php break;
            endswitch; ?>
        </div>
    <?php endif; ?>

    <ol class="wfp-steps">
        <?php foreach ($p['rows'] as $r): ?>
            <?php if ($r['kind'] === 'lane'): ?>
                <li class="wfp-lane" style="--d:<?= (int)$r['depth'] ?>;--lane:var(--wf-l<?= ((int)($r['lane'] ?? 0) % 6) + 1 ?>)"><span><?= te('Voie prise : {voie}', ['voie' => $r['title']]) ?></span></li>
                <?php continue; ?>
            <?php endif; ?>
            <li class="wfp-step is-<?= htmlspecialchars($r['state']) ?>" style="--d:<?= (int)$r['depth'] ?>" data-wfp-key="<?= htmlspecialchars($r['key']) ?>">
                <span class="wfp-dot" aria-hidden="true"></span>
                <span class="wfp-kind" aria-hidden="true"><?= $ic($r['kind']) ?></span>
                <span class="wfp-name"><?= htmlspecialchars($r['title'] !== '' ? $r['title'] : t('Étape sans nom')) ?>
                    <span class="wfp-sr"> — <?= htmlspecialchars(!empty($r['timer']) ? t('En attente') : ($stateLabel[$r['state']] ?? '')) ?></span></span>
                <?php if ($r['note'] !== ''): ?>
                    <span class="wfp-note<?= isset($r['lane']) ? ' is-lane' : '' ?>"<?= isset($r['lane']) ? ' style="--lane:var(--wf-l' . (((int)$r['lane'] % 6) + 1) . ')"' : '' ?>><?= htmlspecialchars($r['note']) ?></span>
                <?php elseif ($r['state'] === 'run'): ?>
                    <span class="wfp-note is-run"><?= te('En cours') ?></span>
                <?php elseif ($r['state'] === 'wait'): ?>
                    <span class="wfp-note is-wait"><?= te('Vous attend') ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <p class="wfp-err" role="alert" hidden></p>
    <footer class="wfp-foot">
        <a class="wfp-btn" href="<?= htmlspecialchars($p['detail_url']) ?>"><?= te('Suivi détaillé') ?></a>
        <?php if (!$p['terminal']): ?>
            <button type="button" class="wfp-btn is-danger" data-wfp-act="cancel" data-wfp-confirm="<?= te('Arrêter ce workflow ? Ce qui est déjà fait reste dans la conversation.') ?>"><?= te('Arrêter') ?></button>
        <?php endif; ?>
    </footer>
</div>
