<?php
/**
 * Avatar du header mobile — lien direct vers Mon profil (/settings), menu des
 * réglages dans le tiroir ☰. Le bloc crédits complet reste dans
 * user-account-trigger.php (pied de sidebar). Variables préfixées $ha* pour
 * ne pas écraser $currentUser / $userInitials consommés par les autres
 * partials — seule $connectorsToReconnect est PARTAGÉE, à dessein : c'est la
 * même liste que portent l'avatar du pied de sidebar et le menu des réglages
 * (une requête, calculée par le premier partial rendu ou par le contrôleur).
 * Sur mobile la barre latérale est repliée : cet avatar est le seul endroit
 * où la pastille de reconnexion peut se voir.
 */
$haUser = Session::currentUser();
if (!$haUser) {
    return; // Page publique : pas d'avatar header.
}
$haWords = preg_split('/\s+/', trim((string)($haUser['name'] ?? ''))) ?: [];
$haInitials = mb_strtoupper(mb_substr($haWords[0] ?? '', 0, 1) . mb_substr($haWords[1] ?? '', 0, 1));
if ($haInitials === '') { $haInitials = '?'; }
if (!isset($connectorsToReconnect)) {
    $connectorsToReconnect = ConnectorRegistry::reconnectNeeded((int)$haUser['id'], (string)($haUser['role'] ?? 'user'));
}
$haReconnect = ConnectorRegistry::reconnectSummary($connectorsToReconnect);
$haTitle     = t('Réglages') . ($haReconnect !== '' ? ' — ' . $haReconnect : '');
?>
<a href="/settings" class="header-avatar-btn"
        title="<?= htmlspecialchars($haTitle) ?>" aria-label="<?= htmlspecialchars($haTitle) ?>">
    <span class="user-avatar-wrap">
        <?php if (!empty($haUser['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($haUser['avatar_url']) ?>" class="user-avatar" alt="">
        <?php else: ?>
            <span class="user-avatar-fallback"><?= htmlspecialchars($haInitials) ?></span>
        <?php endif; ?>
        <?php if ($haReconnect !== ''): ?><span class="reconnect-dot" aria-hidden="true"></span><?php endif; ?>
    </span>
</a>
