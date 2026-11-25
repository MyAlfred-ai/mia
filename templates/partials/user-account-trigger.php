<?php
/**
 * Carte du compte — pied de barre latérale (chat, volet du chat des pages
 * d'espace, administration, paramètres) : avatar, nom, crédits du mois (ou
 * crédits de démo restants, pour un compte de démonstration), puis
 * les deux portes des réglages, libellées (user-account-actions.php). La carte
 * elle-même n'est pas cliquable : une seule entrée par destination, ce sont
 * les liens « Réglages » (Mon profil) et « Admin » (administration).
 * Définit $currentUser / $userInitials / $userRole / $userRoleLabel /
 * $creditQuotaStatus.
 * L'avatar porte la seule pastille de ce partial : les connexions à
 * reconnecter. Les points à régler vivent sur le bouton « Admin ».
 *
 * REPLIABLE (2026-11-10, même mécanique que les sections du volet —
 * sidebar-sections.js, section `account`) : le chevron du coin replie la
 * carte en une seule rangée — l'avatar cerclé de ses crédits restants (anneau
 * aux couleurs de la barre), puis Réglages et Admin en icônes. Toucher
 * l'avatar ou le chevron la redéplie. Le nom et les crédits restent lus par
 * les lecteurs d'écran (masqués à l'œil seulement). État posé sur <html>
 * (`data-sb-account`) avant le premier rendu, par head-meta.php.
 */
$currentUser = Session::currentUser();
if (!$currentUser) {
    // Page publique (ex. À propos) consultée sans session : pas de bloc compte,
    // simple lien de connexion. Le menu popup ne s'affiche pas non plus.
    ?>
    <a class="user-info" href="/auth/login" title="<?= te('Se connecter') ?>" style="justify-content:flex-start">
        <span class="user-avatar-fallback">?</span>
        <span style="flex:1;min-width:0;display:block;text-align:left">
            <span class="user-name" style="display:block"><?= te('Se connecter') ?></span>
        </span>
    </a>
    <?php
    return;
}
// Quota du mois : source unique (CreditQuota) — seules les lignes qui
// entament le quota comptent, l'exploitation (bases, tests) en est exclue.
$creditQuotaStatus = CreditQuota::status($currentUser);
$monthCredits   = (int)$creditQuotaStatus['used'];
$quotaK         = $creditQuotaStatus['quota_k'];
$quotaMax       = $creditQuotaStatus['quota_max'];
$remaining      = $creditQuotaStatus['remaining'];
$quotaPct       = (int)$creditQuotaStatus['percent'];
$quotaBarColor = $quotaPct >= 90 ? '#ef4444' : ($quotaPct >= 70 ? '#f59e0b' : 'var(--accent)');
// Budget de l'organisation atteint : la barre passe au rouge, comme la mention.
if (($creditQuotaStatus['reason'] ?? null) === CreditQuota::REASON_BUDGET) { $quotaBarColor = '#ef4444'; }

// Initiales pour l'avatar de secours (1res lettres des 2 premiers mots du nom)
$nameWords    = preg_split('/\s+/', trim((string)($currentUser['name'] ?? ''))) ?: [];
$userInitials = mb_strtoupper(mb_substr($nameWords[0] ?? '', 0, 1) . mb_substr($nameWords[1] ?? '', 0, 1));
if ($userInitials === '') { $userInitials = '?'; }
// Libellé du rôle : Role::label, source unique (rôles livrés traduits, rôle
// personnalisé = son nom, écrit par l'administrateur).
$userRole      = (string)($currentUser['role'] ?? 'user');
$userRoleLabel = Role::label($userRole);
// Connexions révoquées par leur fournisseur (Gmail, Drive…) : pastille ambre
// sur l'avatar ; le menu des réglages la reprend sur « Comptes connectés ».
// Calculée ici seulement si aucun gabarit ne l'a déjà fait (chat, admin-nav) :
// une requête, partagée par les trois partials du compte.
if (!isset($connectorsToReconnect)) {
    $connectorsToReconnect = ConnectorRegistry::reconnectNeeded((int)$currentUser['id'], $userRole);
}
$reconnectSummary = ConnectorRegistry::reconnectSummary($connectorsToReconnect);
// Carte repliée : l'anneau de l'avatar dit la part de crédits restante (même
// couleur que la barre), et l'infobulle de l'avatar le nom et les crédits.
// chat.js (applyQuotaStatus) tient les deux à jour en direct.
$dockQuotaStyle = $quotaMax !== null
    ? ' style="--quota-left:' . max(0, 100 - $quotaPct) . '%;--quota-color:' . $quotaBarColor . '"'
    : '';
if (($creditQuotaStatus['reason'] ?? null) === CreditQuota::REASON_BUDGET) {
    $dockCreditText = t('Service IA suspendu');
} elseif ($quotaMax === null) {
    $dockCreditText = t('Illimité');
} elseif ($remaining > 0) {
    $dockCreditText = !empty($creditQuotaStatus['demo'])
        ? t('{n} crédits de démo restants', ['n' => Format::credits($remaining)])
        : t('{n} crédits restants', ['n' => Format::credits($remaining)]);
} else {
    $dockCreditText = !empty($creditQuotaStatus['demo']) ? t('Démo terminée') : t('Quota épuisé');
}
// La connexion à reconnecter a SA bulle, sur sa pastille (nav-signals.js).
$dockAvatarTitle = trim((string)($currentUser['name'] ?? '')) . ' — ' . $dockCreditText;
?>
<div class="user-dock<?= $quotaMax !== null ? ' has-quota' : '' ?>" id="userDock"<?= $dockQuotaStyle ?>>
<button type="button" class="user-dock-toggle" data-sb-toggle="account" aria-expanded="true" aria-controls="userDock"
        data-label-fold="<?= te('Replier le compte') ?>" data-label-unfold="<?= te('Déplier le compte') ?>"
        title="<?= te('Replier le compte') ?>" aria-label="<?= te('Replier le compte') ?>">
    <svg class="sb-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
</button>
<div class="user-info" id="userAccountCard">
    <span class="user-avatar-wrap" title="<?= htmlspecialchars($dockAvatarTitle) ?>">
        <?php if (!empty($currentUser['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" class="user-avatar" alt="">
        <?php else: ?>
            <span class="user-avatar-fallback"><?= htmlspecialchars($userInitials) ?></span>
        <?php endif; ?>
        <?= ConnectorRegistry::reconnectDot($connectorsToReconnect) ?>
    </span>
    <span class="user-info-text" style="flex:1;min-width:0;display:block;text-align:left">
        <span class="user-name" style="display:block"><?= htmlspecialchars($currentUser['name']) ?></span>
        <span id="creditQuotaPrimary" style="display:block;font-size:11px;color:var(--text-muted);margin-top:2px">
            <?php if (($creditQuotaStatus['reason'] ?? null) === CreditQuota::REASON_BUDGET): ?>
                <!-- Budget IA de l'organisation atteint : même mention que chat.js (applyQuotaStatus). -->
                <span id="creditRemainingLabel" style="color:#ef4444"><?= te('Service IA suspendu') ?></span>
                <span id="creditBreakdownLabel"></span>
            <?php elseif (!empty($creditQuotaStatus['demo'])): ?>
                <!-- Compte de démo : enveloppe sans renouvellement — même mention que chat.js (applyQuotaStatus). -->
                <span id="creditRemainingLabel" style="color:<?= $remaining === 0 ? '#ef4444' : ($quotaPct >= 70 ? '#f59e0b' : 'var(--text-muted)') ?>">
                    <?= $remaining > 0 ? te('{n} crédits de démo restants', ['n' => Format::credits($remaining)]) : te('Démo terminée') ?>
                </span>
                <?php if ($remaining > 0): ?>
                    <span id="creditBreakdownLabel"> <?= te('sur {max}', ['max' => Format::credits((int)$quotaMax)]) ?></span>
                <?php else: ?>
                    <span id="creditBreakdownLabel"></span>
                <?php endif; ?>
            <?php elseif ($quotaMax !== null): ?>
                <span id="creditRemainingLabel" style="color:<?= $remaining === 0 ? '#ef4444' : ($quotaPct >= 70 ? '#f59e0b' : 'var(--text-muted)') ?>">
                    <?= $remaining > 0 ? te('{n} crédits restants', ['n' => Format::credits($remaining)]) : te('Quota épuisé') ?>
                </span>
                <?php if ($remaining > 0): ?>
                    <span id="creditBreakdownLabel"> / <?= htmlspecialchars(Format::credits($quotaMax)) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span id="creditRemainingLabel"><?= te('Illimité') ?></span>
                <span id="creditBreakdownLabel"></span>
            <?php endif; ?>
        </span>
        <?php if ($quotaMax !== null): ?>
        <span style="display:block;height:3px;background:rgba(255,255,255,.08);border-radius:2px;margin-top:4px">
            <span id="creditQuotaBar" style="display:block;height:3px;border-radius:2px;background:<?= $quotaBarColor ?>;width:<?= max(0, 100 - $quotaPct) ?>%"></span>
        </span>
        <?php endif; ?>
    </span>
</div>
<?php require ROOT . '/templates/partials/user-account-actions.php'; ?>
</div>
