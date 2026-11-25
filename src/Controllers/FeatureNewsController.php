<?php

declare(strict_types=1);

/**
 * Fenêtre « Quoi de neuf » (MajorFeatures) — une seule route : la fenêtre
 * s'est ouverte, ses nouveautés ne doivent plus revenir, ni ici ni sur un
 * autre appareil. Appelée par feature-news.js DÈS l'ouverture (comme la
 * fenêtre des Nouveautés marque lu à l'ouverture) : attendre la fermeture la
 * rouvrirait chez qui recharge la page sans l'avoir fermée.
 */
final class FeatureNewsController
{
    /** POST /news/seen — `ids[]` : les nouveautés que la fenêtre compte vues. */
    public function seen(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();
        $ids  = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_slice(array_map('strval', $ids), 0, 20);
        try {
            MajorFeatures::markShown((int)$user['id'], $ids);
        } catch (\Throwable $e) {
            error_log('[Nouveautés majeures] ' . $e->getMessage());
            AjaxResponse::fail(t('Une erreur est survenue.'), 500);
        }
        AjaxResponse::ok();
    }
}
