<?php
/**
 * Sélecteur de période PARTAGÉ — Coûts → Budget (section « Dépenses ») et
 * Coûts → Consommation : tranches prédéfinies + plage personnalisée, puis la
 * phrase qui dit ce qui est affiché (dates, jours, comparaison). Le catalogue,
 * les bornes et les libellés viennent de UsagePeriod : deux pages, un seul
 * vocabulaire.
 *
 * Marche SANS JavaScript : des liens et un formulaire GET. period-picker.js
 * ajoute la fermeture du panneau (Échap, clic ailleurs), le contrôle des
 * dates et, quand `swap` est posé, le remplacement de la seule section
 * concernée sans recharger la page.
 *
 * Variables : $picker = [
 *   'period'  => UsagePeriod::resolve(),
 *   'range'   => ['from' => 'Y-m-d', 'to' => 'Y-m-d'] bornes posées (facultatif),
 *   'compare' => ['from', 'to'] période de comparaison (facultatif),
 *   'path'    => '/admin/budget',
 *   'keep'    => paramètres d'URL à garder (ex. ['user_id' => 3]),
 *   'anchor'  => fragment posé sur les liens (facultatif),
 *   'swap'    => sélecteur de la région remplacée sans rechargement (facultatif),
 *   'today'   => 'Y-m-d',
 * ]
 */
$pp       = $picker;
$ppPeriod = $pp['period'];
$ppKeep   = array_filter($pp['keep'] ?? [], static fn($v): bool => $v !== null && $v !== '');
$ppAnchor = !empty($pp['anchor']) ? '#' . $pp['anchor'] : '';
$ppHref   = static function (array $params) use ($pp, $ppKeep, $ppAnchor): string {
    $q = http_build_query(array_merge($ppKeep, $params));
    return $pp['path'] . ($q !== '' ? '?' . $q : '') . $ppAnchor;
};
$ppRange  = $pp['range'] ?? (($ppPeriod['from'] ?? null) !== null
    ? ['from' => $ppPeriod['from'], 'to' => $ppPeriod['to']]
    : null);
$ppCustom = !empty($ppPeriod['custom']);
$ppToday  = (string)($pp['today'] ?? date('Y-m-d'));
$ppCal    = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
$ppDays   = $ppRange !== null ? UsagePeriod::days($ppRange['from'], $ppRange['to']) : null;
?>
<div class="period-picker" data-period-picker<?= !empty($pp['swap']) ? ' data-period-swap="' . htmlspecialchars((string)$pp['swap']) . '"' : '' ?>>
    <div class="period-bar">
        <nav class="period-presets" aria-label="<?= te('Période affichée') ?>">
            <?php foreach (UsagePeriod::PRESETS as $ppKey => $ppPreset):
                $ppActive = !$ppCustom && ($ppPeriod['preset'] ?? '') === $ppKey; ?>
                <a href="<?= htmlspecialchars($ppHref(['preset' => $ppKey])) ?>"
                   class="period-preset<?= $ppActive ? ' is-active' : '' ?>"
                   data-period="<?= htmlspecialchars($ppKey) ?>"
                   <?= $ppActive ? 'aria-current="true"' : '' ?>
                   title="<?= htmlspecialchars(UsagePeriod::label(['preset' => $ppKey])) ?>"><?= htmlspecialchars(UsagePeriod::shortLabel($ppKey)) ?></a>
            <?php endforeach; ?>
        </nav>

        <details class="period-custom<?= $ppCustom ? ' is-active' : '' ?>">
            <summary class="period-preset period-custom-toggle"<?= $ppCustom ? ' aria-current="true"' : '' ?>>
                <?= $ppCal ?><span><?= te('Personnalisée') ?></span>
            </summary>
            <form method="GET" action="<?= htmlspecialchars($pp['path'] . $ppAnchor) ?>" class="period-custom-panel" novalidate>
                <?php foreach ($ppKeep as $ppName => $ppValue): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$ppName) ?>" value="<?= htmlspecialchars((string)$ppValue) ?>">
                <?php endforeach; ?>
                <p class="period-custom-title"><?= te('Choisir les dates') ?></p>
                <div class="period-custom-fields">
                    <label class="period-field">
                        <span><?= te('Du') ?></span>
                        <input type="date" name="from" class="form-input" required max="<?= htmlspecialchars($ppToday) ?>"
                               value="<?= htmlspecialchars($ppRange['from'] ?? '') ?>">
                    </label>
                    <span class="period-custom-arrow" aria-hidden="true">→</span>
                    <label class="period-field">
                        <span><?= te('Au') ?></span>
                        <input type="date" name="to" class="form-input" required max="<?= htmlspecialchars($ppToday) ?>"
                               value="<?= htmlspecialchars($ppRange['to'] ?? '') ?>">
                    </label>
                </div>
                <p class="period-custom-error" role="alert" hidden></p>
                <div class="period-custom-actions">
                    <span class="period-custom-hint"><?= te("Bornes comprises, jusqu'à aujourd'hui.") ?></span>
                    <button type="submit" class="btn-primary"><?= te('Afficher') ?></button>
                </div>
            </form>
        </details>
    </div>

    <p class="period-summary">
        <?= $ppCal ?>
        <?php if ($ppRange !== null): ?>
            <strong><?= htmlspecialchars(($ppPeriod['preset'] ?? '') === 'all'
                ? t('Depuis le {date}', ['date' => UsagePeriod::rangeLabel($ppRange['from'], $ppRange['from'])])
                : UsagePeriod::rangeLabel($ppRange['from'], $ppRange['to'])) ?></strong>
            <?php // Espace INSÉCABLE (U+00A0) entre le nombre et « jour » : invisible à l'éditeur. ?>
            <span><?= htmlspecialchars(I18n::plural((int)$ppDays, '{n} jour', '{n} jours')) ?></span>
            <?php if (!empty($pp['compare'])): ?>
                <span class="period-compare"><?= te('comparé au {periode}', ['periode' => UsagePeriod::rangeLabel($pp['compare']['from'], $pp['compare']['to'])]) ?></span>
            <?php endif; ?>
        <?php else: ?>
            <strong><?= htmlspecialchars(UsagePeriod::label($ppPeriod)) ?></strong>
        <?php endif; ?>
    </p>
</div>
