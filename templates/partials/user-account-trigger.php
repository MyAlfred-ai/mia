<?php
/**
 * Carte du compte — pied de barre latérale (chat, volet du chat des pages
 * d'espace, administration, paramètres) : avatar, nom, crédits du mois, puis
 * les deux portes des réglages, libellées (user-account-actions.php). La carte
 * elle-même n'est pas cliquable : une seule entrée par destination, ce sont
 * les liens « Réglages » (Mon profil) et « Admin » (administration).
 * Définit $currentUser / $userInitials / $userRole / $userRoleLabel /
 * $creditQuotaStatus.
 * L'avatar porte la seule pastille de ce partial : les connexions à
 * reconnecter. Les points à régler vivent sur le bouton « Admin ».
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
?>
<div class="user-dock">
<div class="user-info" id="userAccountCard"<?= $reconnectSummary !== '' ? ' title="' . htmlspecialchars($reconnectSummary) . '"' : '' ?>>
    <span class="user-avatar-wrap">
        <?php if (!empty($currentUser['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" class="user-avatar" alt="">
        <?php else: ?>
            <span class="user-avatar-fallback"><?= htmlspecialchars($userInitials) ?></span>
        <?php endif; ?>
        <?php if ($reconnectSummary !== ''): ?><span class="reconnect-dot" aria-hidden="true"></span><?php endif; ?>
    </span>
    <span style="flex:1;min-width:0;display:block;text-align:left">
        <span class="user-name" style="display:block"><?= htmlspecialchars($currentUser['name']) ?></span>
        <span id="creditQuotaPrimary" style="display:block;font-size:11px;color:var(--text-muted);margin-top:2px">
            <?php if (($creditQuotaStatus['reason'] ?? null) === CreditQuota::REASON_BUDGET): ?>
                <!-- Budget IA de l'organisation atteint : même mention que chat.js (applyQuotaStatus). -->
                <span id="creditRemainingLabel" style="color:#ef4444"><?= te('Service IA suspendu') ?></span>
                <span id="creditBreakdownLabel"></span>
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
