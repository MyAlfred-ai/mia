<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Diagnostic') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DIAGNOSTIC; require ROOT . '/templates/partials/admin-tabs.php'; ?>
<?php
    $puce = [
        Diagnostics::OK   => ['●', '#4ade80', t('Tout est opérationnel')],
        Diagnostics::WARN => ['▲', '#fbbf24', t('À surveiller')],
        Diagnostics::FAIL => ['✕', '#fb7185', t('À corriger')],
        Diagnostics::INFO => ['·', 'var(--text-muted)', t('Information')],
    ];
    [$puceGlobale, $couleurGlobale, $libelleGlobal] = $puce[$global] ?? $puce[Diagnostics::OK];

    // Deux listes : ce qui appelle une action (FAIL puis WARN, l'ordre de la
    // gravité) et ce qui est au vert (OK + INFO), replié en lignes compactes.
    $problemes = [];
    $sains     = [];
    foreach ($controles as $c) {
        $statut = (string)($c['statut'] ?? Diagnostics::OK);
        if ($statut === Diagnostics::FAIL || $statut === Diagnostics::WARN) {
            $problemes[] = $c;
        } else {
            $sains[] = $c;
        }
    }
    usort($problemes, static fn(array $a, array $b): int =>
        ((($b['statut'] ?? '') === Diagnostics::FAIL) <=> (($a['statut'] ?? '') === Diagnostics::FAIL)));

    $nbFail = count(array_filter($controles, static fn($c) => ($c['statut'] ?? '') === Diagnostics::FAIL));
    $nbWarn = count(array_filter($controles, static fn($c) => ($c['statut'] ?? '') === Diagnostics::WARN));

    // Rapport en texte brut : ce que l'on copie-colle dans un ticket. Il porte
    // les remèdes, donc il se suffit à lui-même.
    // Alignement en CARACTÈRES : sprintf('%-42s') compte des octets, donc tout
    // libellé accentué décalait sa colonne dans le rapport copié.
    $aligner = static function (string $t, int $largeur): string {
        $n = mb_strlen($t);
        return $n >= $largeur ? $t : $t . str_repeat(' ', $largeur - $n);
    };

    $rapport = t('Diagnostic MIA — version {version}', ['version' => defined('APP_VERSION') ? APP_VERSION : '?']) . "\n";
    foreach ($controles as $c) {
        $rapport .= '[' . $aligner(mb_strtoupper($c['statut']), 4) . '] '
            . $aligner($c['libelle'], 46) . ' ' . $c['valeur'] . "\n";
        if ($c['message'] !== '') { $rapport .= "        " . $c['message'] . "\n"; }
        if ($c['remede']  !== '') { $rapport .= "        → " . $c['remede'] . "\n"; }
    }
?>
    <div class="lg-header">
        <div>
            <h1 style="margin-bottom:4px"><?= te('Diagnostic') ?></h1>
            <p style="color:var(--text-muted);font-size:13px;margin:0">
                <?= strtr(te('État réel de l\'installation. Les mêmes contrôles sont disponibles en ligne de commande ({commande}) et sous forme de sonde ({sonde}).'), [
                    '{commande}' => '<code>php scripts/doctor.php</code>',
                    '{sonde}'    => '<code>/healthz</code>',
                ]) ?>
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" class="btn-secondary" id="btnCopierRapport"><?= te('Copier le rapport') ?></button>
            <a href="/admin/diagnostics" class="btn-secondary">↻ <?= te('Actualiser') ?></a>
        </div>
    </div>

    <!-- Bandeau : verdict + décompte par gravité + version, en une ligne. -->
    <div class="card diag-band">
        <span class="diag-band-dot" style="color:<?= $couleurGlobale ?>"><?= $puceGlobale ?></span>
        <strong class="diag-band-label"><?= htmlspecialchars($libelleGlobal) ?></strong>
        <span class="diag-chips">
            <?php if ($nbFail > 0): ?><span class="diag-chip diag-chip-fail"><?= te('{n} à corriger', ['n' => $nbFail]) ?></span><?php endif; ?>
            <?php if ($nbWarn > 0): ?><span class="diag-chip diag-chip-warn"><?= te('{n} à surveiller', ['n' => $nbWarn]) ?></span><?php endif; ?>
            <span class="diag-chip"><?= te('{n} au vert', ['n' => count($sains)]) ?></span>
        </span>
        <span class="diag-band-meta"><?= te('version {version}', ['version' => defined('APP_VERSION') ? APP_VERSION : '?']) ?></span>
    </div>

    <?php if ($problemes !== []): ?>
        <h2 class="diag-h2"><?= te('À traiter') ?></h2>
        <?php foreach ($problemes as $c): ?>
            <?php
                [$symbole, $couleur] = $puce[$c['statut']] ?? $puce[Diagnostics::INFO];
                $actions = Diagnostics::actions($c);
            ?>
            <div class="card diag-issue" data-statut="<?= htmlspecialchars((string)$c['statut']) ?>">
                <div class="diag-issue-head">
                    <span style="color:<?= $couleur ?>"><?= $symbole ?></span>
                    <strong><?= htmlspecialchars($c['libelle']) ?></strong>
                    <span class="diag-issue-value"><?= htmlspecialchars($c['valeur']) ?></span>
                    <span class="diag-issue-cat"><?= htmlspecialchars(Diagnostics::libelleCategorie((string)($c['categorie'] ?? ''))) ?></span>
                </div>
                <?php if ($c['message'] !== ''): ?>
                    <p class="diag-issue-msg"><?= htmlspecialchars($c['message']) ?></p>
                <?php endif; ?>
                <?php if ($c['remede'] !== ''): ?>
                    <p class="diag-issue-fix">→ <?= htmlspecialchars($c['remede']) ?></p>
                <?php endif; ?>
                <?php if ($actions !== []): ?>
                    <div class="diag-actions">
                        <?php foreach ($actions as $a): ?>
                            <?php if ($a['type'] === 'link'): ?>
                                <a class="btn-secondary diag-action" href="<?= htmlspecialchars($a['value']) ?>"><?= htmlspecialchars($a['label']) ?></a>
                            <?php else: ?>
                                <!-- Commande shell : à COPIER, jamais exécutée depuis le web
                                     (elle vise l'hôte Docker, pas le conteneur — et une page
                                     qui exécute du shell est une porte qu'on n'ouvre pas). -->
                                <button type="button" class="btn-secondary diag-action diag-cmd"
                                        data-cmd="<?= htmlspecialchars($a['value'], ENT_QUOTES) ?>"
                                        title="<?= te('Copier : {commande}', ['commande' => $a['value']]) ?>">
                                    <?= htmlspecialchars($a['label']) ?>
                                    <code><?= htmlspecialchars($a['value']) ?></code>
                                </button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Contrôles au vert : une ligne chacun, groupés par catégorie. Le détail
         qui justifiait l'ancienne liste longue n'existe que sur les problèmes. -->
    <h2 class="diag-h2"><?= te('Contrôles au vert') ?></h2>
    <div class="card diag-ok-card">
        <?php foreach ($groupes as $cle => $liste): ?>
            <?php
                $ok = array_values(array_filter($liste, static fn($c) =>
                    in_array((string)($c['statut'] ?? ''), [Diagnostics::OK, Diagnostics::INFO], true)));
                if ($ok === []) { continue; }
            ?>
            <div class="diag-ok-cat"><?= htmlspecialchars(Diagnostics::libelleCategorie($cle)) ?></div>
            <div class="diag-ok-grid">
                <?php foreach ($ok as $c): ?>
                    <?php [$symbole, $couleur] = $puce[$c['statut']] ?? $puce[Diagnostics::INFO]; ?>
                    <div class="diag-ok-row"<?= $c['message'] !== '' ? ' title="' . htmlspecialchars($c['message'], ENT_QUOTES) . '"' : '' ?>>
                        <span style="color:<?= $couleur ?>"><?= $symbole ?></span>
                        <span class="diag-ok-label"><?= htmlspecialchars($c['libelle']) ?></span>
                        <span class="diag-ok-value"><?= htmlspecialchars($c['valeur']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    const rapport = <?= json_encode($rapport, JSON_UNESCAPED_UNICODE) ?>;
    const bouton  = document.getElementById('btnCopierRapport');

    async function copier(texte, el, libelle) {
        try {
            await navigator.clipboard.writeText(texte);
            const avant = libelle();
            el.dataset.avant = avant;
            libelle(<?= tj('Copié ✓') ?>);
            setTimeout(() => libelle(el.dataset.avant), 1500);
        } catch (e) {
            // Presse-papiers refusé (contexte non sécurisé) : on montre le texte.
            window.prompt(<?= tj('Copiez :') ?>, texte);
        }
    }

    if (bouton) {
        bouton.addEventListener('click', () => copier(rapport, bouton, (v) => {
            if (v === undefined) return bouton.textContent;
            bouton.textContent = v;
        }));
    }

    // Boutons-commandes : copie dans le presse-papiers, confirmation sur le
    // libellé sans toucher au <code> (il doit rester lisible).
    document.querySelectorAll('.diag-cmd').forEach(btn => {
        const label = btn.childNodes[0]; // texte avant le <code>
        btn.addEventListener('click', () => copier(btn.dataset.cmd || '', btn, (v) => {
            if (v === undefined) return label.textContent.trim();
            label.textContent = v + ' ';
        }));
    });
})();
</script>
</body>
</html>
