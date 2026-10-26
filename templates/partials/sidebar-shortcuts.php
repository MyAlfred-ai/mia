<!-- Tuiles de l'ESPACE DE TRAVAIL — ce que l'on fait avec MIA, selon ses
     droits (NavRegistry::workspaceFor, source unique) : Assistants, Bases de
     connaissances, Tâches programmées, Workflows, Mes créations. Un simple
     utilisateur y voit au moins ses tâches et ses créations, qui vivaient
     avant dans « Paramètres ». Une page, une entrée : ces pages ne sont plus
     dans aucun autre menu.
     Partagé par la page de discussion et le volet du chat des pages d'espace
     (chat-shell-sidebar.php, NavShell) : `$sbNavActive` (clé de
     NavShell::SHORTCUT_OF) allume la tuile de la page ouverte. Pour qui
     administre, les tuiles Assistants et Bases portent la pastille des points
     à régler de leur page (AdminAttention), comme le menu latéral le fait pour
     ses rubriques. La dernière tuile, « Tout », ouvre la palette ⌘K. -->
<?php
    $sbNavUser   = Session::currentUser();
    $sbNavItems  = NavRegistry::workspaceFor($sbNavUser);
    $sbNavActive = $sbNavActive ?? '';
    $sbNavAtt    = AdminAccess::hasAdministration($sbNavUser)
        ? AdminAccess::filterAttention(AdminAttention::current(), $sbNavUser)
        : [];
    $sbNavSignal = static function (string $key) use ($sbNavAtt): ?array {
        $page = ['profiles' => 'profiles', 'kb' => 'knowledge-bases'][$key] ?? null;
        return $page === null ? null : AdminAttention::forEntry($sbNavAtt, $page);
    };
?>
<?php if ($sbNavItems !== []): ?>
<nav class="sidebar-spaces" aria-label="<?= te('Espaces') ?>">
    <div class="sidebar-spaces-label"><?= te('Espaces') ?></div>
    <div class="sidebar-spaces-grid">
        <?php foreach ($sbNavItems as $sbKey => $sbItem): ?>
        <?php $sbSig = $sbNavSignal($sbKey); ?>
        <a href="<?= htmlspecialchars($sbItem['href']) ?>" class="space-tile<?= $sbNavActive === $sbKey ? ' active' : '' ?>"<?= $sbNavActive === $sbKey ? ' aria-current="page"' : '' ?>
           title="<?= htmlspecialchars($sbSig !== null ? $sbSig['text'] : t($sbItem['label'])) ?>">
            <span class="space-tile-icon" aria-hidden="true"><?= NavRegistry::icon($sbItem['icon'], 18) ?></span>
            <span class="space-tile-label"><?= te($sbItem['short']) ?></span>
            <?php if (!empty($sbItem['beta'])): ?><?= Workflow::betaChip() ?><?php endif; ?>
            <?php if ($sbSig !== null): ?><span class="nav-signal-dot <?= $sbSig['level'] === AdminAttention::ALERT ? 'is-alert' : 'is-warn' ?>" aria-hidden="true"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php // « Tout » : la palette — toutes les pages, réglages et actions, et les conversations. ?>
        <button type="button" class="space-tile space-tile-all" data-palette-open aria-haspopup="dialog" aria-expanded="false" title="<?= te('Rechercher ou aller à…') ?>">
            <span class="space-tile-icon" aria-hidden="true"><?= NavRegistry::icon('search', 18) ?></span>
            <span class="space-tile-label"><?= te('Tout') ?></span>
        </button>
    </div>
</nav>
<?php endif; ?>
