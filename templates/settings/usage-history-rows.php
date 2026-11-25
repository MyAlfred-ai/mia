<?php
/**
 * Journal de l'historique (Réglages → Usage & crédits → Historique) : les
 * opérations d'une page, rangées par jour, puis « Afficher plus ».
 *
 * Rendu dans la région #puHistory (usage-history.php) et SEUL
 * (`?fragment=rows&apres=N`), quand usage-history.js ajoute la page suivante
 * sans recharger : il ne dépend que de $hx (jours, page suivante, filtres).
 * Un jour coupé entre deux pages est recousu par le script (même data-day).
 */
$hxRowsCredits = static fn(int $n): string => I18n::plural($n, '{n} crédit', '{n} crédits', ['n' => Format::credits($n)]);
$hxRowsUrl = static fn(array $extra): string => '/settings/usage?' . http_build_query(array_merge($hx['base'], UsageHistory::query($hx['filters']), $extra));
?>
<?php foreach ($hx['days'] as $hxDay): ?>
<section class="hx-day" data-day="<?= htmlspecialchars($hxDay['day']) ?>">
    <h4 class="hx-day-head">
        <span><?= htmlspecialchars($hxDay['label']) ?></span>
        <span class="hx-day-total"><?= htmlspecialchars($hxRowsCredits((int)$hxDay['credits'])) ?></span>
    </h4>
    <ol class="hx-ops">
        <?php foreach ($hxDay['rows'] as $hxRow): ?>
        <li class="hx-op" data-id="<?= htmlspecialchars($hxRow['id']) ?>">
            <div class="hx-op-head">
                <span class="hx-op-ic" style="--op:<?= htmlspecialchars($hxRow['color']) ?>"><?= UsageHistory::icon($hxRow['type'], 15) ?></span>
                <div class="hx-op-main">
                    <p class="hx-op-title">
                        <span><?= htmlspecialchars($hxRow['title']) ?></span>
                        <?php if ($hxRow['who'] !== ''): ?><span class="hx-op-who"><?= htmlspecialchars($hxRow['who']) ?></span><?php endif; ?>
                    </p>
                    <p class="hx-op-meta">
                        <span class="hx-origin" style="--o:<?= htmlspecialchars($hxRow['origin_color']) ?>"><i aria-hidden="true"></i><?= htmlspecialchars($hxRow['origin_label']) ?></span>
                        <?php if ($hxRow['context']['label'] !== ''): ?>
                            <?php if ($hxRow['context']['href'] !== null): ?>
                                <a class="hx-ctx" href="<?= htmlspecialchars($hxRow['context']['href']) ?>"><?= htmlspecialchars($hxRow['context']['label']) ?></a>
                            <?php else: ?>
                                <span class="hx-ctx"><?= htmlspecialchars($hxRow['context']['label']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($hxRow['profile'] !== null): ?>
                            <span class="hx-assistant"><i aria-hidden="true"<?= $hxRow['profile']['color'] !== '' ? ' style="background:' . htmlspecialchars($hxRow['profile']['color']) . '"' : '' ?>></i><?= htmlspecialchars($hxRow['profile']['name']) ?></span>
                        <?php endif; ?>
                        <?php if (count($hxRow['lines']) === 1 && $hxRow['lines'][0]['volume'] !== ''): ?>
                            <span class="hx-vol"><?= htmlspecialchars($hxRow['lines'][0]['volume']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="hx-op-side">
                    <span class="hx-op-credits"><?= htmlspecialchars($hxRowsCredits((int)$hxRow['credits'])) ?></span>
                    <span class="hx-op-time"><?= htmlspecialchars($hxRow['time']) ?><?php if ($hxRow['all_exempt']): ?> · <em><?= te('hors quota') ?></em><?php endif; ?></span>
                </div>
            </div>
            <?php if (count($hxRow['lines']) > 1): ?>
                <?php
                $hxExtras = array_map(static fn(array $e): string => $e['count'] > 1
                    ? t('{libelle} × {n}', ['libelle' => $e['label'], 'n' => $e['count']])
                    : $e['label'], $hxRow['extras']);
                ?>
                <details class="hx-op-more">
                    <summary><?= te('Avec : {liste}', ['liste' => implode(', ', $hxExtras)]) ?></summary>
                    <div class="table-wrap">
                        <table class="hx-lines">
                            <thead>
                                <tr>
                                    <th><?= te('Opération') ?></th>
                                    <th><?= te('Modèle ou outil') ?></th>
                                    <th class="is-num"><?= te('Volume') ?></th>
                                    <th class="is-num"><?= te('Crédits') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hxRow['lines'] as $hxLine): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($hxLine['label']) ?><?php if ($hxLine['exempt']): ?> <em><?= te('hors quota') ?></em><?php endif; ?></td>
                                        <td class="pu-muted"><?= $hxLine['who'] !== '' ? htmlspecialchars($hxLine['who']) : '—' ?></td>
                                        <td class="is-num pu-muted"><?= $hxLine['volume'] !== '' ? htmlspecialchars($hxLine['volume']) : '—' ?></td>
                                        <td class="is-num"><?= Format::credits((int)$hxLine['credits']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endforeach; ?>
<?php if ($hx['more']): ?>
    <p class="hx-more" data-history-more>
        <a class="btn-secondary" href="<?= htmlspecialchars($hxRowsUrl(['apres' => $hx['next']])) ?>"
           data-fragment="<?= htmlspecialchars($hxRowsUrl(['apres' => $hx['next'], 'fragment' => 'rows'])) ?>"><?= te('Afficher plus d’opérations') ?></a>
    </p>
<?php endif; ?>
