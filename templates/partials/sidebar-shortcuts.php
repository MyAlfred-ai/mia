<!-- Section ESPACES du volet — ce que l'on fait avec MIA, selon ses droits
     (NavRegistry::workspaceFor, source unique) : Assistants, Bases de
     connaissances, Tâches programmées, Workflows, Mes créations. Un simple
     utilisateur y voit au moins ses tâches et ses créations.
     Partagé par la page de discussion et le volet du chat des pages d'espace
     (chat-shell-sidebar.php, NavShell) : `$sbNavActive` (clé de
     NavShell::SHORTCUT_OF) allume l'espace de la page ouverte.

     Piste A (2026-11-09) — une section REPLIABLE :
       - dépliée : de grandes tuiles sur deux colonnes, chacune à la couleur de
         son espace (SidebarSpaces::TONES), avec une ligne d'information
         (« 12 disponibles », « 1 vous attend » — SidebarSpaces::metas) ;
       - repliée : une rangée d'icônes de couleur, toujours cliquables.
     Les deux rendus sont dans la page ; l'attribut `data-sb-spaces` de <html>
     (sidebar-sections.js, posé avant le premier rendu par
     sidebar-sections-boot.php, que head-meta.php requiert) choisit lequel se voit.

     Pour qui administre, Assistants et Bases portent la pastille des points à
     régler de leur page (AdminAttention). Une nouveauté MAJEURE pas encore
     visitée (MajorFeatures) pose sa pastille verte contre l'icône ; elle
     s'éteint à l'ouverture de l'espace. La recherche (⌘K) est dans le bloc de
     départ, juste au-dessus : plus de tuile « Tout ».

     `$sbNavKnown` (facultatif) : comptes déjà lus par l'appelant
     (`profiles`, `kb`) — la page de discussion les connaît, elle évite ainsi
     de les relire. -->
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
    $sbNavMetas  = SidebarSpaces::metas($sbNavUser, array_keys($sbNavItems), $sbNavKnown ?? []);
    $sbChevron   = '<svg class="sb-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
?>
<?php if ($sbNavItems !== []): ?>
<nav class="sidebar-spaces" aria-label="<?= te('Espaces') ?>">
    <button type="button" class="sb-section-toggle" data-sb-toggle="spaces" aria-expanded="true" aria-controls="sbSpacesGrid">
        <span class="sb-section-title"><?= te('Espaces') ?></span>
        <?= $sbChevron ?>
    </button>
    <div class="sidebar-spaces-grid" id="sbSpacesGrid">
        <?php foreach ($sbNavItems as $sbKey => $sbItem): ?>
        <?php
            $sbSig    = $sbNavSignal($sbKey);
            $sbMeta   = $sbNavMetas[$sbKey] ?? null;
            $sbIsOpen = $sbNavActive === $sbKey;
        ?>
        <a href="<?= htmlspecialchars($sbItem['href']) ?>" class="space-tile space-<?= SidebarSpaces::tone($sbKey) ?><?= $sbIsOpen ? ' active' : '' ?>"<?= $sbIsOpen ? ' aria-current="page"' : '' ?>
           title="<?= te($sbItem['label']) ?>">
            <span class="space-tile-icon"><span aria-hidden="true"><?= NavRegistry::icon($sbItem['icon'], 18) ?></span><?= MajorFeatures::dotAt(MajorFeatures::PLACE_SPACE, $sbKey) ?></span>
            <span class="space-tile-text">
                <span class="space-tile-label"><?= te($sbItem['short']) ?></span>
                <?php if ($sbMeta !== null): ?><span class="space-tile-meta<?= $sbMeta['attention'] ? ' is-attention' : '' ?>"><?= htmlspecialchars($sbMeta['text']) ?></span><?php endif; ?>
            </span>
            <?php if (!empty($sbItem['beta'])): ?><?= Workflow::betaChip() ?><?php endif; ?>
            <?php if ($sbSig !== null): ?><span class="nav-signal-dot <?= $sbSig['level'] === AdminAttention::ALERT ? 'is-alert' : 'is-warn' ?>" aria-hidden="true" data-tip="<?= htmlspecialchars($sbSig['text']) ?>" data-tip-tone="<?= $sbSig['level'] === AdminAttention::ALERT ? 'alert' : 'warn' ?>"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php // Section repliée : une icône de couleur par espace, toujours cliquable. ?>
    <div class="sidebar-spaces-mini">
        <?php foreach ($sbNavItems as $sbKey => $sbItem): ?>
        <?php
            $sbSig    = $sbNavSignal($sbKey);
            $sbMeta   = $sbNavMetas[$sbKey] ?? null;
            $sbIsOpen = $sbNavActive === $sbKey;
            $sbTitle  = t($sbItem['label']) . ($sbMeta !== null ? ' — ' . $sbMeta['text'] : '');
        ?>
        <a href="<?= htmlspecialchars($sbItem['href']) ?>" class="space-mini space-<?= SidebarSpaces::tone($sbKey) ?><?= $sbIsOpen ? ' active' : '' ?>"<?= $sbIsOpen ? ' aria-current="page"' : '' ?>
           title="<?= htmlspecialchars($sbTitle) ?>" aria-label="<?= htmlspecialchars($sbTitle) ?>">
            <span aria-hidden="true"><?= NavRegistry::icon($sbItem['icon'], 18) ?></span>
            <?= MajorFeatures::dotAt(MajorFeatures::PLACE_SPACE, $sbKey) ?>
            <?php if ($sbSig !== null): ?><span class="nav-signal-dot <?= $sbSig['level'] === AdminAttention::ALERT ? 'is-alert' : 'is-warn' ?>" aria-hidden="true" data-tip="<?= htmlspecialchars($sbSig['text']) ?>" data-tip-tone="<?= $sbSig['level'] === AdminAttention::ALERT ? 'alert' : 'warn' ?>"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <button type="button" class="sb-mini-open" data-sb-toggle="spaces" aria-expanded="false" aria-controls="sbSpacesGrid"
                title="<?= te('Déplier les espaces') ?>" aria-label="<?= te('Déplier les espaces') ?>"><?= $sbChevron ?></button>
    </div>
</nav>
<?php endif; ?>
