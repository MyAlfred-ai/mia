<?php
/**
 * Usages → Vue d'ensemble (onglet par défaut de templates/admin/usage.php).
 *
 * Ce que MIA fait, qui s'en sert, avec quelle qualité — AUCUN montant en euros
 * (ils vivent sur la page Budget). Tout est calculé par UsageInsights::overview()
 * ; ce gabarit ne fait que poser.
 *
 * Variables (héritées de usage.php) : $insights (alias $ov), $ms, $pct, $nfTrim,
 * $providerColors, $userId.
 */
$ovIcon = static fn(string $paths): string =>
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$featureIcons = [
    'chat'         => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'assistants'   => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/>',
    'web'          => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'kb'           => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'connectors'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'image'        => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'html'         => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    'presentation' => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    'map'          => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    'video'        => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    'voice'        => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/>',
    'memory'       => '<path d="M12 2a7 7 0 0 0-7 7c0 3 2 5 3 6v3h8v-3c1-1 3-3 3-6a7 7 0 0 0-7-7z"/><line x1="9" y1="22" x2="15" y2="22"/>',
    'automation'   => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'scheduled'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
];
$trendLabel = static function (int|string|null $t): array {
    if ($t === null) { return ['—', 'is-none']; }
    if ($t === 'new') { return [t('nouveau'), 'is-up']; }
    if ($t === 0) { return ['=', 'is-flat']; }
    return [t('{n} %', ['n' => ($t > 0 ? '+' : '−') . abs((int)$t)]), $t > 0 ? 'is-up' : 'is-down'];
};
$credits = static fn(float $v): string => Format::credits((int)round($v));
$modelColors = ['#6366f1', '#10a37f', '#f59e0b', '#0ea5e9', '#ec4899', '#8b5cf6'];
?>
    <div id="tab-overview" class="usage-tab-panel usage-ov">
        <!-- ================= Répartition de l'usage ================= -->
        <section class="card usage-ov-card" aria-labelledby="ovSplitTitle">
            <div class="card-head">
                <span class="card-ic"><?= $ovIcon('<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="ovSplitTitle"><?= te("Répartition de l'usage") ?></h3>
                    <p><?= te("Part des crédits consommés, par catégorie d'opération. Une tuile ouvre son détail.") ?></p>
                </div>
            </div>
            <?php if ($ov['treemap'] === []): ?>
                <p class="usage-ov-empty"><?= te('Aucune consommation sur la période.') ?></p>
            <?php else: ?>
                <div class="usage-treemap" role="list">
                    <?php foreach ($ov['treemap'] as $tile):
                        $tileVars = ['libelle' => $tile['label'], 'n' => $credits((float)$tile['value']), 'part' => t('{n} %', ['n' => $nfTrim((float)$tile['share'], 1)])]; ?>
                        <a class="usage-tile<?= $tile['compact'] ? ' is-compact' : '' ?><?= $tile['tiny'] ? ' is-tiny' : '' ?>" role="listitem"
                           href="#categories"
                           style="left:<?= $tile['x'] ?>%;top:<?= $tile['y'] ?>%;width:<?= $tile['w'] ?>%;height:<?= $tile['h'] ?>%;--tile:<?= htmlspecialchars($tile['color']) ?>"
                           title="<?= $tile['maintenance'] ? te('{libelle} : {n} crédits, {part} (maintenance, hors quota)', $tileVars) : te('{libelle} : {n} crédits, {part}', $tileVars) ?>">
                            <span class="usage-tile-name"><?= htmlspecialchars($tile['label']) ?></span>
                            <span class="usage-tile-share"><?= te('{n} %', ['n' => $nfTrim((float)$tile['share'], 1)]) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php // Téléphone : la carte proportionnelle n'a pas la place de ses libellés, même lecture en liste. ?>
                <ul class="usage-split-list">
                    <?php foreach ($ov['treemap'] as $tile): ?>
                        <li style="--tile:<?= htmlspecialchars($tile['color']) ?>">
                            <a href="#categories"><i class="usage-dot"></i><?= htmlspecialchars($tile['label']) ?></a>
                            <span><?= te('{n} %', ['n' => $nfTrim((float)$tile['share'], 1)]) ?></span>
                            <span class="usage-split-bar"><span style="width:<?= max(1.5, (float)$tile['share']) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="usage-ov-foot"><?= $exemptCredits > 0
                    ? teb('Total : {b}{n}{/b} crédits, dont {hors} de maintenance hors quota.', ['n' => Format::credits((int)$kpis['total_credits']), 'hors' => Format::credits($exemptCredits)])
                    : teb('Total : {b}{n}{/b} crédits.', ['n' => Format::credits((int)$kpis['total_credits'])]) ?></p>
            <?php endif; ?>
        </section>

        <!-- ================= Adoption des fonctionnalités ================= -->
        <section class="card usage-ov-card" aria-labelledby="ovAdoptionTitle">
            <div class="card-head">
                <span class="card-ic"><?= $ovIcon('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="ovAdoptionTitle"><?= te('Adoption des fonctionnalités') ?></h3>
                    <?php $ovWho = $userId === null
                        ? t("Qui s'en sert, sur {n} comptes actifs", ['n' => (int)$ov['accounts']])
                        : t('Ce que cette personne a utilisé'); ?>
                    <p><?= $ov['has_previous'] && !empty($compare)
                        ? te("{texte}, et l'évolution par rapport au {periode}.", ['texte' => $ovWho, 'periode' => UsagePeriod::rangeLabel($compare['from'], $compare['to'])])
                        : te('{texte}.', ['texte' => $ovWho]) ?></p>
                </div>
            </div>
            <?php if ($ov['adoption']['used'] === []): ?>
                <p class="usage-ov-empty"><?= te('Aucune fonctionnalité utilisée sur la période.') ?></p>
            <?php else: ?>
                <div class="usage-features">
                    <div class="usage-feature is-head" aria-hidden="true">
                        <span></span><span><?= te('Personnes') ?></span><span><?= te('Usages') ?></span><span><?= te('Évolution') ?></span>
                    </div>
                    <?php foreach ($ov['adoption']['used'] as $f): [$tl, $tc] = $trendLabel($f['trend']); ?>
                        <div class="usage-feature">
                            <span class="usage-feature-name"><span class="usage-feature-ic"><?= $ovIcon($featureIcons[$f['key']] ?? '') ?></span><?= htmlspecialchars($f['label']) ?></span>
                            <span class="usage-feature-people" title="<?= htmlspecialchars(I18n::plural((int)$f['people'], '{n} personne', '{n} personnes')) ?>">
                                <span class="usage-feature-bar"><span style="width:<?= max(1.5, (float)$f['share']) ?>%"></span></span>
                                <span class="usage-feature-count"><?= (int)$f['people'] ?></span>
                            </span>
                            <span class="usage-feature-uses"><?= Format::credits((int)$f['uses']) ?></span>
                            <span class="usage-trend <?= $tc ?>"><?= htmlspecialchars($tl) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($ov['adoption']['unused'] !== []): ?>
                    <p class="usage-ov-foot"><?= te('Non utilisées sur la période : {liste}.', ['liste' => implode(', ', $ov['adoption']['unused'])]) ?></p>
                <?php endif; ?>
                <details class="usage-ov-howto">
                    <summary><?= te('Comment ces chiffres sont comptés') ?></summary>
                    <p><?= te("Un usage est une opération IA (réponse, recherche, image…), une action menée pendant une réponse (consultation d'une base, lecture d'un email, création d'un brouillon…) ou une exécution de tâche programmée. Une personne compte une fois par fonctionnalité. Les connecteurs se lisent dans les actions des réponses ; les bases, dans leurs consultations.") ?></p>
                </details>
            <?php endif; ?>
        </section>

        <div class="usage-ov-grid">
            <!-- ================= Modèles ================= -->
            <section class="card usage-ov-card" aria-labelledby="ovModelsTitle">
                <div class="card-head">
                    <span class="card-ic"><?= $ovIcon('<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="ovModelsTitle"><?= te('Modèles', [], 'llm') ?></h3>
                        <p><?= te('Part des réponses, rapidité et réponses relancées.') ?></p>
                    </div>
                </div>
                <?php $models = $ov['models']; ?>
                <?php if ($models['rows'] === []): ?>
                    <p class="usage-ov-empty"><?= te('Aucune réponse sur la période.') ?></p>
                <?php else: ?>
                    <div class="usage-stack" role="img" aria-label="<?= htmlspecialchars(implode(', ', array_map(static fn(array $m): string => $m['label'] . ' ' . $pct((float)$m['share']), $models['rows']))) ?>">
                        <?php foreach ($models['rows'] as $i => $m): ?>
                            <span style="flex-grow:<?= max(0.1, (float)$m['share']) ?>;background:<?= $modelColors[$i % count($modelColors)] ?>"></span>
                        <?php endforeach; ?>
                        <?php if ($models['other'] !== null): ?><span class="is-other" style="flex-grow:<?= max(0.1, (float)$models['other']['share']) ?>"></span><?php endif; ?>
                    </div>
                    <div class="table-wrap">
                        <table class="usage-ov-table">
                            <thead><tr><th><?= te('Modèle') ?></th><th><?= te('Part') ?></th><th title="<?= te('Temps de réponse médian') ?>"><?= te('Médiane') ?></th><th title="<?= te('9 réponses sur 10 arrivent en moins de…') ?>"><?= te('9 sur 10') ?></th><th title="<?= te('Réponses régénérées') ?>"><?= te('Relancées') ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($models['rows'] as $i => $m): ?>
                                <tr>
                                    <td><i class="usage-dot" style="background:<?= $modelColors[$i % count($modelColors)] ?>"></i><?= htmlspecialchars($m['label']) ?></td>
                                    <td><?= htmlspecialchars($pct((float)$m['share'])) ?></td>
                                    <td><?= htmlspecialchars($ms($m['median_ms'])) ?></td>
                                    <td><?= htmlspecialchars($ms($m['p90_ms'])) ?></td>
                                    <td class="<?= ($m['regen_rate'] ?? 0) >= 10 ? 'is-warn' : '' ?>"><?= htmlspecialchars($pct($m['regen_rate'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($models['other'] !== null): ?>
                                <tr class="is-muted"><td><i class="usage-dot"></i><?= te('Autres ({n})', ['n' => (int)$models['other']['count']]) ?></td><td><?= htmlspecialchars($pct((float)$models['other']['share'])) ?></td><td></td><td></td><td></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ================= Assistants ================= -->
            <section class="card usage-ov-card" aria-labelledby="ovAssistantsTitle">
                <div class="card-head">
                    <span class="card-ic"><?= $ovIcon($featureIcons['assistants']) ?></span>
                    <div class="card-head-text">
                        <h3 id="ovAssistantsTitle"><?= te('Assistants') ?></h3>
                        <p><?= te('Conversations, personnes et réponses relancées.') ?></p>
                    </div>
                </div>
                <?php if ($ov['assistants'] === []): ?>
                    <p class="usage-ov-empty"><?= te('Aucune conversation sur la période.') ?></p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="usage-ov-table">
                            <thead><tr><th><?= te('Assistant') ?></th><th><?= te('Conversations') ?></th><th><?= te('Personnes') ?></th><th><?= te('Relancées') ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($ov['assistants'] as $a): ?>
                                <tr class="<?= $a['profile_id'] === null ? 'is-muted' : '' ?>">
                                    <td><?= htmlspecialchars($a['label']) ?></td>
                                    <td><?= Format::credits((int)$a['conversations']) ?></td>
                                    <td><?= (int)$a['people'] ?></td>
                                    <td class="<?= ($a['regen_rate'] ?? 0) >= 10 ? 'is-warn' : '' ?>"><?= htmlspecialchars($pct($a['regen_rate'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($ov['assistants_more'] > 0): ?>
                        <p class="usage-ov-foot"><?= htmlspecialchars(I18n::plural((int)$ov['assistants_more'], 'Et {n} autre assistant moins utilisé : voir l\'onglet Répartition.', 'Et {n} autres assistants moins utilisés : voir l\'onglet Répartition.')) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($userId === null && $ov['idle_assistants'] > 0): ?>
                    <p class="usage-ov-foot"><?= htmlspecialchars(I18n::plural((int)$ov['idle_assistants'], '{n} assistant actif sans aucune conversation sur la période', '{n} assistants actifs sans aucune conversation sur la période')) ?> · <a href="/admin/profiles"><?= te('Voir les assistants') ?></a></p>
                <?php endif; ?>
            </section>
        </div>

        <!-- ================= Bases de connaissances ================= -->
        <?php if ($ov['kb_total'] > 0): ?>
        <section class="card usage-ov-card" aria-labelledby="ovKbTitle">
            <div class="card-head">
                <span class="card-ic"><?= $ovIcon($featureIcons['kb']) ?></span>
                <div class="card-head-text">
                    <h3 id="ovKbTitle"><?= te('Bases de connaissances') ?></h3>
                    <p><?= te('Réponses nourries par chaque base depuis sa création (toutes personnes confondues).') ?></p>
                </div>
            </div>
            <?php if ($ov['kbs'] === []): ?>
                <p class="usage-ov-empty"><?= te("Aucune base n'a encore nourri de réponse.") ?></p>
            <?php else: $kbMax = max(1, (int)$ov['kbs'][0]['query_count']); ?>
                <ul class="usage-kbs">
                    <?php foreach ($ov['kbs'] as $kb): ?>
                        <li>
                            <span class="usage-kb-name"><?= htmlspecialchars((string)$kb['name']) ?></span>
                            <span class="usage-kb-count"><?= htmlspecialchars(I18n::plural((int)$kb['query_count'], '{n} réponse', '{n} réponses', ['n' => Format::credits((int)$kb['query_count'])])) ?></span>
                            <span class="usage-kb-bar"><span style="width:<?= max(1.5, (int)$kb['query_count'] / $kbMax * 100) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($ov['kb_dormant'] !== []): $n = count($ov['kb_dormant']); ?>
                <p class="usage-ov-note is-warn">
                    <?= $ovIcon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>') ?>
                    <span><?= htmlspecialchars(I18n::plural($n, '{n} base active n\'a nourri aucune réponse depuis {jours} jours : {liste}', '{n} bases actives n\'ont nourri aucune réponse depuis {jours} jours : {liste}', [
                        'jours' => UsageInsights::DORMANT_DAYS,
                        'liste' => implode(', ', array_slice($ov['kb_dormant'], 0, 6)) . ($n > 6 ? '…' : ''),
                    ])) ?>
                    · <a href="/admin/knowledge-bases"><?= te('Voir les bases') ?></a></span>
                </p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
