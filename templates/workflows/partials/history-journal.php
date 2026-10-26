<?php
/**
 * Historique des exécutions — le journal d'UN lancement, rendu par
 * WorkflowController::journal (et au premier affichage de l'onglet) :
 * en tête le workflow, l'état, de quoi il s'agissait, quand, comment,
 * combien de temps et de crédits, la cause d'un arrêt, ce qui a été remis,
 * et les gestes (décider, reprendre, suivre, relancer) ; puis le déroulé,
 * une entrée par passage de brique (WorkflowJournal::entries).
 *
 *   @var array       $head     WorkflowJournal::head
 *   @var list<array> $entries  WorkflowJournal::entries
 *   @var ?array      $wfInfo   le workflow : id, name, color, edit (adresse de l'éditeur, ou null)
 *   @var ?array      $relaunch ['id', 'values'] — relancer avec les mêmes données, ou null
 *   @var list<array> $outputs  WorkflowJournal::outputs (pièces du livrable d'un lancement terminé)
 *   @var array       $icons    WorkflowCatalog::icons
 */
$pill = ['ok' => 'ok', 'run' => 'run', 'wait' => 'wait', 'warn' => 'wait', 'err' => 'err', 'off' => ''];
$toneIcon = ['ok' => 'check', 'run' => 'spark', 'wait' => 'user', 'warn' => 'alert', 'err' => 'alert', 'off' => 'stop'];
$ic = static fn(string $k): string => $icons[$k] ?? $icons['step'];
$wfInfo ??= null;
$relaunch ??= null;
$outputs ??= [];
// Le contenu entier, à déplier (WorkflowContentView : HTML sûr, rendu côté
// serveur). `data-view` garde le dépliant ouvert quand le journal se met à
// jour (workflow-history.js) ; une page web s'y charge à l'ouverture
// (html-preview.js, cadre isolé).
$viewBox = static function (string $html, string $label, string $key): string {
    if (trim($html) === '') {
        return '';
    }
    return '<details class="wf-tl-view" data-view="' . htmlspecialchars($key) . '"><summary>' . htmlspecialchars($label) . '</summary>'
        . '<div class="wf-view">' . $html . '</div></details>';
};
// Une pièce remise : sa nature, son aperçu, son téléchargement, son contenu (déroulé et « Résultat »).
$pieceCard = static function (array $p, string $scope = 'piece') use ($icons, $ic, $viewBox): string {
    // « Image · Image 1536 × 1024 » : la forme ne se répète pas quand le résumé la dit déjà.
    $meta = array_filter([
        mb_stripos($p['summary'], $p['form']) === 0 ? '' : $p['form'],
        $p['summary'],
        $p['version'] > 1 ? t('version {v}', ['v' => (int)$p['version']]) : '',
    ], static fn(string $x): bool => $x !== '');
    $html = '<div class="wf-tl-piece"><span class="wf-tl-piece-ic">' . $ic($p['icon']) . '</span>'
        . '<span class="grow"><b>' . htmlspecialchars($p['name']) . '</b><small>' . htmlspecialchars(implode(' · ', $meta)) . '</small></span>'
        . '<a class="wf-tl-dl" href="' . htmlspecialchars($p['download']) . '" title="' . te('Télécharger') . '" aria-label="' . te('Télécharger « {nom} »', ['nom' => $p['name']]) . '">' . $icons['download'] . '</a>';
    if ($p['image'] !== '') {
        $html .= '<img class="wf-tl-thumb" src="' . htmlspecialchars($p['image']) . '" alt="' . htmlspecialchars($p['name']) . '" loading="lazy">';
    }
    if ($p['fields'] !== []) {
        $html .= '<dl class="wf-tl-fields">';
        foreach ($p['fields'] as $f) {
            $html .= '<div><dt>' . htmlspecialchars($f['label']) . '</dt><dd>' . htmlspecialchars($f['value']) . '</dd></div>';
        }
        $html .= '</dl>';
    } elseif ($p['excerpt'] !== '') {
        $html .= '<p class="wf-tl-excerpt">' . htmlspecialchars($p['excerpt']) . '</p>';
    }
    return $html . $viewBox($p['view'] ?? '', $p['view_label'] ?? t('Voir le contenu'), $scope . ':' . $p['key']) . '</div>';
};
?>
<div class="wf-jr" data-run="<?= (int)$head['id'] ?>">
    <button type="button" class="wf-jr-back" data-hist-back><?= $icons['back'] ?><?= te('Tous les lancements') ?></button>

    <header class="wf-jr-head tone-<?= htmlspecialchars($head['tone']) ?>">
        <?php if ($wfInfo !== null): ?>
        <div class="wf-jr-kicker" style="--hue:<?= htmlspecialchars($wfInfo['color']) ?>">
            <i class="wf-jr-kdot" aria-hidden="true"></i>
            <?php if ($wfInfo['edit'] !== null): ?>
                <a href="<?= htmlspecialchars($wfInfo['edit']) ?>" title="<?= te('Modifier le workflow') ?>"><?= htmlspecialchars($wfInfo['name']) ?><?= $icons['pencil'] ?></a>
            <?php else: ?>
                <span><?= htmlspecialchars($wfInfo['name']) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="wf-jr-title">
            <span class="wf-jr-badge"><?= $ic($toneIcon[$head['tone']] ?? 'check') ?></span>
            <div class="grow">
                <h3><?= te('Lancement n° {n}', ['n' => (int)$head['n']]) ?></h3>
                <p><span class="wf-pill <?= $pill[$head['tone']] ?? '' ?>"><?= htmlspecialchars($head['label']) ?></span> <?= htmlspecialchars($head['started']) ?></p>
            </div>
        </div>
        <?php if ($head['context'] !== ''): ?>
            <p class="wf-jr-context"><?= htmlspecialchars($head['context']) ?></p>
        <?php endif; ?>
        <dl class="wf-jr-facts">
            <div><dt><?= te('Déclencheur') ?></dt><dd><?= $ic($head['trigger_icon']) ?><?= htmlspecialchars($head['trigger']) ?></dd></div>
            <?php if ($head['duration'] !== ''): ?><div><dt><?= te('Durée') ?></dt><dd><?= htmlspecialchars($head['duration']) ?></dd></div><?php endif; ?>
            <div><dt><?= te('Briques faites') ?></dt><dd><?= (int)$head['done_count'] ?></dd></div>
            <div><dt><?= te('Coût') ?></dt><dd><?= htmlspecialchars(I18n::plural((int)$head['credits'], '{n} crédit', '{n} crédits', ['n' => Format::credits((int)$head['credits'])])) ?></dd></div>
            <div><dt><?= te('Version') ?></dt><dd><?= te('v{v}', ['v' => (int)$head['version']]) ?><?php if ($head['old_version']): ?> <small><?= te('(une plus récente est publiée)') ?></small><?php endif; ?></dd></div>
        </dl>
        <div class="wf-jr-track" aria-hidden="true">
            <?php foreach ($head['track'] as $seg): ?><i class="tone-<?= htmlspecialchars($seg['tone'] !== '' ? $seg['tone'] : 'none') ?>" title="<?= htmlspecialchars($seg['title']) ?>"></i><?php endforeach; ?>
        </div>
        <?php if ($head['error_box'] !== null): ?>
        <div class="wf-jr-error" role="note">
            <?= $icons['alert'] ?>
            <div>
                <?php if ($head['error_box']['title'] !== ''): ?><b><?= htmlspecialchars($head['error_box']['title']) ?></b><?php endif; ?>
                <?php if ($head['error_box']['message'] !== ''): ?><p><?= htmlspecialchars($head['error_box']['message']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($outputs !== []): ?>
        <div class="wf-jr-outputs">
            <small><?= te('Résultat') ?></small>
            <?php foreach ($outputs as $p): ?><?= $pieceCard($p, 'out') ?><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="wf-jr-actions">
            <a class="wf-btn sm primary" href="<?= htmlspecialchars($head['href']) ?>"><?= $ic($head['open']['icon']) ?><?= htmlspecialchars($head['open']['label']) ?></a>
            <?php if ($head['conversation_url'] !== null): ?>
                <a class="wf-btn sm" href="<?= htmlspecialchars($head['conversation_url']) ?>"><?= $icons['chat'] ?><?= te('Conversation') ?></a>
            <?php endif; ?>
            <?php if ($relaunch !== null): ?>
                <button type="button" class="wf-btn sm" data-launch="<?= (int)$relaunch['id'] ?>"<?php if ($relaunch['values'] !== []): ?> data-launch-values="<?= htmlspecialchars((string)json_encode($relaunch['values'], JSON_UNESCAPED_UNICODE)) ?>" title="<?= te('Rouvre la fenêtre de lancement, remplie avec les données de ce lancement') ?>"<?php endif; ?>><?= $icons['spark'] ?><?= te('Relancer') ?></button>
            <?php endif; ?>
            <?php if ($head['parent'] !== null): ?>
                <a class="wf-btn sm ghost" href="<?= htmlspecialchars($head['parent']) ?>"><?= $icons['chain'] ?><?= te('Lancement précédent') ?></a>
            <?php endif; ?>
        </div>
    </header>

    <h4 class="wf-jr-section"><?= te('Déroulé') ?><span><?= htmlspecialchars(I18n::plural(count($entries), '{n} passage', '{n} passages')) ?></span></h4>
    <?php if ($entries === []): ?>
        <p class="wf-hint"><?= te('Rien n’a encore été fait.') ?></p>
    <?php else: ?>
    <ol class="wf-tl">
        <?php foreach ($entries as $e): ?>
        <li class="wf-tl-item tone-<?= htmlspecialchars($e['tone']) ?> k-<?= htmlspecialchars($e['type']) ?><?= $e['redone'] ? ' is-redone' : '' ?><?= $e['nested'] ? ' is-nested' : '' ?>">
            <div class="wf-tl-time"><?= htmlspecialchars($e['time']) ?><?php if ($e['day'] !== ''): ?><small><?= htmlspecialchars($e['day']) ?></small><?php endif; ?></div>
            <div class="wf-tl-node"><span class="wf-tl-dot"><?= $ic($e['icon']) ?></span></div>
            <div class="wf-tl-card">
                <div class="wf-tl-top">
                    <b class="wf-tl-title"><?= htmlspecialchars($e['title']) ?></b>
                    <?php if ($e['duration'] !== ''): ?><span class="wf-tl-dur"><?= htmlspecialchars($e['duration']) ?></span><?php endif; ?>
                </div>
                <?php if ($e['who'] !== ''): ?><div class="wf-tl-who"><?= htmlspecialchars($e['who']) ?></div><?php endif; ?>
                <p class="wf-tl-status"><?= htmlspecialchars($e['status']) ?></p>
                <?php if ($e['tone'] === 'wait'): ?>
                <a class="wf-tl-cta" href="<?= htmlspecialchars($head['href']) ?>"><?= te('Décider dans l’exécution') ?><?= $icons['chev'] ?></a>
                <?php endif; ?>
                <?php if ($e['chips'] !== []): ?>
                <div class="wf-tl-chips"><?php foreach ($e['chips'] as $chip): ?><span><?= htmlspecialchars($chip) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($e['error'] !== null): ?>
                <div class="wf-tl-error"><?= $icons['alert'] ?><div><?php if ($e['error']['title'] !== ''): ?><b><?= htmlspecialchars($e['error']['title']) ?></b><?php endif; ?><?php if ($e['error']['message'] !== ''): ?><p><?= htmlspecialchars($e['error']['message']) ?></p><?php endif; ?></div></div>
                <?php endif; ?>
                <?php if ($e['quote'] !== null): $long = mb_strlen($e['quote']['text']) > 600; ?>
                <blockquote class="wf-tl-quote"><small><?= htmlspecialchars($e['quote']['label']) ?></small><?= nl2br(htmlspecialchars($long ? rtrim(mb_substr($e['quote']['text'], 0, 600)) . '…' : $e['quote']['text']), false) ?></blockquote>
                <?php if ($long): ?><?= $viewBox('<div class="wf-view-pre">' . htmlspecialchars($e['quote']['text']) . '</div>', t('Lire en entier'), 'quote:' . $e['key']) ?><?php endif; ?>
                <?php endif; ?>
                <?php if ($e['lines'] !== []): ?>
                <ul class="wf-tl-lines"><?php foreach ($e['lines'] as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <?php if ($e['fields'] !== []): ?>
                <dl class="wf-tl-fields"><?php foreach ($e['fields'] as $f): ?><div><dt><?= htmlspecialchars($f['label']) ?></dt><dd><?= htmlspecialchars($f['value']) ?></dd></div><?php endforeach; ?></dl>
                <?php endif; ?>
                <?php if ($e['more'] !== null): ?><?= $viewBox($e['more']['html'], $e['more']['label'], 'more:' . $e['key']) ?><?php endif; ?>
                <?php if ($e['piece'] !== null): ?><?= $pieceCard($e['piece']) ?><?php endif; ?>
                <?php foreach ($e['files'] as $fp): ?><?= $pieceCard($fp) ?><?php endforeach; ?>
                <?php if ($e['actions'] !== []): ?>
                <details class="wf-tl-acts"<?= count($e['actions']) <= 4 ? ' open' : '' ?>>
                    <summary><?= htmlspecialchars(I18n::plural(count($e['actions']), 'Ce qui a été fait : {n} action', 'Ce qui a été fait : {n} actions')) ?></summary>
                    <ul>
                        <?php foreach ($e['actions'] as $a): ?>
                        <li class="<?= $a['ok'] ? '' : 'is-err' ?>"><span class="msg-trace-icon" data-icon="<?= htmlspecialchars($a['ok'] ? $a['icon'] : 'warn') ?>" aria-hidden="true"></span><span><?= htmlspecialchars($a['label']) ?><?php if ($a['detail'] !== ''): ?><small><?= htmlspecialchars($a['detail']) ?></small><?php endif; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endif; ?>
                <?php foreach ([['cards', t('Actions proposées')], ['deliveries', t('Livraisons')]] as [$list, $title]): if ($e[$list] === []) { continue; } ?>
                <div class="wf-tl-outcomes">
                    <small><?= htmlspecialchars($title) ?></small>
                    <ul>
                        <?php foreach ($e[$list] as $i => $c): ?>
                        <li class="tone-<?= htmlspecialchars($c['tone']) ?>">
                            <span class="grow"><b><?= htmlspecialchars($c['label']) ?></b><?php if (!empty($c['object'])): ?><small><?= htmlspecialchars($c['object']) ?></small><?php endif; ?><?php if ($c['error'] !== ''): ?><small class="is-err"><?= htmlspecialchars($c['error']) ?></small><?php endif; ?></span>
                            <span class="wf-tl-state"><?= htmlspecialchars($c['state']) ?></span>
                            <?php if ($list === 'cards'): ?>
                                <?= $viewBox($c['view'], $c['view_label'], 'card:' . $c['id']) ?>
                            <?php else: foreach ($c['cards'] ?? [] as $cc): ?>
                                <?= $viewBox($cc['view'], count($c['cards']) > 1 && $cc['object'] !== '' ? $cc['view_label'] . ' — ' . $cc['object'] : $cc['view_label'], 'card:' . $cc['id']) ?>
                            <?php endforeach; endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</div>
