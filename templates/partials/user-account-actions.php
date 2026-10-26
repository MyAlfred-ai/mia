<?php
/**
 * Les deux portes des réglages, LIBELLÉES, sous le nom en bas de la barre
 * latérale — une ligne de liens DIRECTS, sans fenêtre intermédiaire (le
 * « Centre de réglages » qu'ils ouvraient coûtait un clic de plus) :
 *   • « Réglages » mène à Mon profil (/settings), menu « Mon compte » à gauche ;
 *   • « Admin », pour qui ouvre l'administration (AdminAccess), mène à /admin
 *     (Tableau de bord, sinon la première page ouverte — AdminAccess::home),
 *     menu des rubriques à gauche. Il porte la pastille des points à régler
 *     (AdminAttention, ceux des pages ouvertes) — jamais l'avatar, réservé aux
 *     connexions à reconnecter.
 * Inclus par user-account-trigger.php, dans sa carte `.user-dock`.
 */
$uaUser = $currentUser ?? Session::currentUser();
if (!$uaUser) {
    return; // Page publique : pas d'actions.
}
$uaHasAdmin  = AdminAccess::hasAdministration($uaUser);
$uaAttention = $uaHasAdmin ? AdminAccess::filterAttention(AdminAttention::current(), $uaUser) : [];
$uaBadge     = AdminMenu::badge($uaAttention);
$uaAdminTitle = t('Administration')
    . ($uaBadge !== null ? ' — ' . I18n::plural($uaBadge['count'], '{n} point à régler', '{n} points à régler') : '');
// Section courante : le bouton s'allume sur /settings/*, ou sur /admin/*.
$uaPath       = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$uaOnSettings = $uaPath === '/settings' || (str_starts_with($uaPath, '/settings/') && !str_starts_with($uaPath, '/settings/schedules') && !str_starts_with($uaPath, '/settings/creations'));
$uaOnAdmin    = ($uaPath === '/admin' || str_starts_with($uaPath, '/admin/')) && !str_starts_with($uaPath, '/admin/profiles') && !str_starts_with($uaPath, '/admin/knowledge-bases');
?>
<div class="user-actions<?= $uaHasAdmin ? ' has-admin' : '' ?>">
    <a href="/settings" class="user-action-btn" id="userSettingsBtn"
       title="<?= te('Réglages de votre compte') ?>"<?= $uaOnSettings ? ' aria-current="page"' : '' ?>>
        <?= NavRegistry::icon('gear', 16) ?>
        <span class="user-action-label"><?= te('Réglages') ?></span>
    </a>
    <?php if ($uaHasAdmin): ?>
    <a href="/admin" class="user-action-btn is-admin" id="adminMenuBtn"
       title="<?= htmlspecialchars($uaAdminTitle) ?>"<?= $uaOnAdmin ? ' aria-current="page"' : '' ?>>
        <?= NavRegistry::icon('shield', 16) ?>
        <span class="user-action-label"><?= te('Admin') ?></span>
        <?php if ($uaBadge !== null): ?><span class="user-action-count <?= $uaBadge['level'] === AdminAttention::ALERT ? 'is-alert' : 'is-warn' ?>" aria-hidden="true"><?= (int)$uaBadge['count'] ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
</div>
