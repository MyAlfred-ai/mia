<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Agent') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'agent'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DIAGNOSTIC; require ROOT . '/templates/partials/admin-tabs.php'; ?>
    <?php
        // Formats d'affichage dans la langue de la page : en français, virgule
        // décimale, espace fine insécable des milliers et « % » détaché ; en
        // anglais, point décimal, virgule des milliers et « % » collé.
        $en  = I18n::locale() === 'en';
        $nb  = static fn(int|float $n): string => $en ? number_format((float)$n, 0, '.', ',') : number_format((float)$n, 0, ',', "\u{202F}");
        $pct = static fn(?float $r): string => $r === null ? '—' : ($en
            ? number_format($r * 100, $r < 0.1 && $r > 0 ? 1 : 0, '.', ',') . '%'
            : number_format($r * 100, $r < 0.1 && $r > 0 ? 1 : 0, ',', ' ') . "\u{00A0}%");
        $dec = static fn(?float $v): string => $v === null ? '—' : ($en ? number_format($v, 1, '.', ',') : number_format($v, 1, ',', ' '));
        $dur = static function (int|float $ms) use ($en): string {
            $ms  = (int)$ms;
            $un  = static fn(float $v): string => $en ? number_format($v, 1, '.', ',') : number_format($v, 1, ',', ' ');
            if ($ms >= 60000) {
                return $un($ms / 60000) . "\u{00A0}min";
            }
            return $ms >= 1000 ? $un($ms / 1000) . "\u{00A0}s" : $ms . "\u{00A0}ms";
        };
        $tuiles = [
            [t('Réponses avec outils'), $nb((int)($summary['turns'] ?? 0)), t('{pct} des réponses', ['pct' => $pct($summary['tool_share'] ?? null)]), 'var(--accent)'],
            [t('Allers-retours par réponse'), $dec($summary['avg_rounds'] ?? null), t('avec le modèle'), '#38bdf8'],
            [t("Appels d'outils"), $nb((int)($summary['tool_calls'] ?? 0)), t('{n} par réponse', ['n' => $dec($summary['avg_tools'] ?? null)]), '#a78bfa'],
            [t("Taux d'erreur des outils"), $pct($summary['error_rate'] ?? null), I18n::plural((int)($summary['tool_errors'] ?? 0), '{n} appel en erreur', '{n} appels en erreur', ['n' => $nb((int)($summary['tool_errors'] ?? 0))]), '#fb7185'],
            [t('Budget épuisé'), $pct($summary['exhausted_rate'] ?? null), I18n::plural((int)($summary['exhausted'] ?? 0), '{n} réponse', '{n} réponses', ['n' => $nb((int)($summary['exhausted'] ?? 0))]), '#fbbf24'],
            [t('Répétitions bloquées'), $nb((int)($summary['duplicates'] ?? 0)), I18n::plural((int)($summary['unknown'] ?? 0), '{n} outil inconnu appelé', '{n} outils inconnus appelés', ['n' => $nb((int)($summary['unknown'] ?? 0))]), '#94a3b8'],
            [t('Entrée relue du cache'), $pct($summary['cache_rate'] ?? null), t("des jetons d'entrée"), '#4ade80'],
            [t('Durée moyenne'), $dur((float)($summary['avg_ms'] ?? 0)), t('{n} sur le modèle de secours', ['n' => $nb((int)($summary['failover'] ?? 0))]), '#f472b6'],
        ];
        $cardsTotal = $cards !== [] ? $cards[count($cards) - 1] : null;
    ?>

    <div class="lg-header">
        <div>
            <h1 style="margin-bottom:4px"><?= te('Agent') ?></h1>
            <p style="color:var(--text-muted);font-size:13px;margin:0;max-width:760px">
                <?= te("Ce que fait MIA quand elle se sert de ses outils et connecteurs : allers-retours avec le modèle, appels d'outils, erreurs, cartes d'approbation. Seules les réponses passées par les outils sont mesurées — des nombres et des noms d'outils, jamais un contenu.") ?>
            </p>
        </div>
        <div class="lg-header-actions agent-periods" role="group" aria-label="<?= te('Période') ?>">
            <?php foreach (AgentTelemetry::PERIODS as $p): ?>
                <a href="/admin/agent?jours=<?= $p ?>" class="lg-range<?= $p === $days ? ' is-active' : '' ?>"<?= $p === $days ? ' aria-current="true"' : '' ?>><?= htmlspecialchars(I18n::plural((int)$p, '{n} jour', '{n} jours')) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="agent-stats">
        <?php foreach ($tuiles as [$libelle, $valeur, $detail, $couleur]): ?>
            <div class="lg-stat">
                <span class="lg-stat-value" style="color:<?= $couleur ?>"><?= htmlspecialchars($valeur) ?></span>
                <span class="lg-stat-label"><?= htmlspecialchars($libelle) ?></span>
                <span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($detail) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ((int)($summary['turns'] ?? 0) === 0 && $error === null): ?>
        <div class="card" style="margin-bottom:16px">
            <p style="margin:0;font-size:13px;color:var(--text-muted)">
                <?= te("Aucune réponse avec outils sur cette période. Les mesures commencent avec les premières réponses qui consultent un connecteur, une base en direct ou un outil d'assistant.") ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($signals !== []): ?>
        <div class="card" style="margin-bottom:16px">
            <h3 style="margin-bottom:10px;font-size:15px"><?= te('À surveiller') ?></h3>
            <ul class="agent-signals">
                <?php foreach ($signals as $s): ?>
                    <li class="agent-signal agent-signal-<?= htmlspecialchars($s['level']) ?>"><?= htmlspecialchars($s['text']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($tools !== []): ?>
        <div class="card" style="margin-bottom:16px">
            <h3 style="margin-bottom:10px;font-size:15px"><?= te('Par outil') ?></h3>
            <div class="table-wrap">
                <table class="agent-table">
                    <thead><tr><th><?= te('Outil') ?></th><th><?= te('Appels') ?></th><th><?= te('Erreurs') ?></th><th><?= te('Durée moyenne') ?></th><th><?= te('Durée maximale') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($tools as $t): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(AgentStatsController::toolLabel($t['tool'])) ?>
                                <code class="agent-tool-name"><?= htmlspecialchars($t['tool']) ?></code>
                            </td>
                            <td><?= $nb($t['n']) ?></td>
                            <td<?= $t['n'] >= AgentTelemetry::SIGNAL_MIN && $t['error_rate'] >= 0.2 ? ' class="agent-bad"' : '' ?>><?= $nb($t['err']) ?> <span class="agent-muted">(<?= $pct($t['error_rate']) ?>)</span></td>
                            <td><?= $dur($t['avg_ms']) ?></td>
                            <td><?= $dur($t['max_ms']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="agent-note"><?= te("Durée de l'outil seul (appel du service, lecture, recherche), sans le temps de réflexion du modèle.") ?></p>
        </div>
    <?php endif; ?>

    <?php if ($cards !== []): ?>
        <div class="card" style="margin-bottom:16px">
            <h3 style="margin-bottom:10px;font-size:15px"><?= te("Cartes d'approbation") ?></h3>
            <div class="table-wrap">
                <table class="agent-table">
                    <thead><tr><th><?= te('Action') ?></th><th><?= te('Proposées') ?></th><th><?= te('Approuvées') ?></th><th><?= te('Refusées') ?></th><th><?= te("Échec à l'exécution") ?></th><th><?= te('Sans décision') ?></th><th><?= te('Délai de décision') ?></th><th><?= te("Taux d'approbation") ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $c): $isTotal = $c['tool'] === ''; ?>
                        <tr<?= $isTotal ? ' class="agent-total"' : '' ?>>
                            <td><?= $isTotal ? te('Total') : htmlspecialchars(AgentStatsController::toolLabel($c['tool'])) . ' <code class="agent-tool-name">' . htmlspecialchars($c['tool']) . '</code>' ?></td>
                            <td><?= $nb($c['proposed']) ?></td>
                            <td><?= $nb($c['executed']) ?></td>
                            <td><?= $nb($c['cancelled']) ?></td>
                            <td><?= $nb($c['failed']) ?></td>
                            <td><?= $nb($c['expired']) ?><?= $c['pending'] > 0 ? ' <span class="agent-muted">' . te('(+{n} en attente)', ['n' => $nb($c['pending'])]) . '</span>' : '' ?></td>
                            <td><?= $c['avg_decision_s'] === null ? '—' : $dur($c['avg_decision_s'] * 1000) ?></td>
                            <td><?= $pct($c['approval_rate']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="agent-note">
                <?= te("Une carte non tranchée dans les {n} minutes compte « sans décision ». Le taux d'approbation rapporte les cartes approuvées (exécutées ou en échec) aux cartes tranchées. Les cartes des conversations supprimées ne sont plus comptées.", ['n' => (int)DrivePendingAction::TTL_MINUTES]) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($models !== []): ?>
        <div class="card" style="margin-bottom:16px">
            <h3 style="margin-bottom:10px;font-size:15px"><?= te('Par modèle') ?></h3>
            <div class="table-wrap">
                <table class="agent-table">
                    <thead><tr><th><?= te('Modèle') ?></th><th><?= te('Réponses') ?></th><th><?= te('Allers-retours') ?></th><th><?= te("Appels d'outils") ?></th><th><?= te('Erreurs') ?></th><th><?= te('Budget épuisé') ?></th><th><?= te('Cache relu') ?></th><th><?= te('Durée moyenne') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($models as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars(ucfirst($m['provider'])) ?> <span class="agent-muted"><?= htmlspecialchars($m['model']) ?></span></td>
                            <td><?= $nb($m['turns']) ?></td>
                            <td><?= $dec($m['avg_rounds']) ?></td>
                            <td><?= $dec($m['avg_tools']) ?> <span class="agent-muted"><?= te('/ réponse') ?></span></td>
                            <td><?= $pct($m['error_rate']) ?></td>
                            <td><?= $pct($m['exhausted_rate']) ?></td>
                            <td><?= $pct($m['cache_rate']) ?></td>
                            <td><?= $dur($m['avg_ms']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <details class="card agent-details">
        <summary><?= te("Ce qui est mesuré, et ce qui ne l'est pas") ?></summary>
        <ul>
            <li><?= teb("{b}Une ligne par réponse qui passe par les outils{/b} (connecteurs, bases en direct, outils d'assistant) : nombre d'allers-retours avec le modèle, appels d'outils et leurs erreurs, appels identiques bloqués, noms d'outils inexistants, cartes proposées, résultats condensés, jetons (dont la part relue du cache), durée, budget atteint, modèle de secours. Les réponses sans outils ne sont que comptées (part des réponses).") ?></li>
            <li><?= teb('{b}Aucun contenu{/b} : ni question, ni réponse, ni nom de fichier, ni personne. Le devenir des cartes vient des cartes elles-mêmes.') ?></li>
            <li><?= teb("{b}{n} jours{/b} de mesures conservées, effacées ensuite au fil de l'eau. Elles ne font pas partie de la sauvegarde.", ['n' => (int)AgentTelemetry::RETENTION_DAYS]) ?></li>
            <li><?= teb("Le {b}budget épuisé{/b} veut dire que le modèle a dû conclure avant d'avoir fini (l'utilisateur voit « Continuer ») : un taux élevé signale des demandes trop vastes pour un tour, ou un modèle qui tourne en rond.") ?></li>
        </ul>
    </details>
</div>
</body>
</html>
