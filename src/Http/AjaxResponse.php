<?php

declare(strict_types=1);

/**
 * Double sortie des écrans de configuration — SOURCE UNIQUE.
 *
 * Les formulaires d'administration et de paramètres s'enregistrent seuls
 * (`public/assets/js/autosave.js`) : ils postent sur la MÊME route que le
 * formulaire classique, avec l'en-tête `X-Requested-With`. Chaque handler doit
 * donc savoir répondre deux fois — en JSON pour l'auto-save, en flash +
 * redirection pour un POST sans JavaScript (ou pour une création qui change de
 * page). Ce contrat vit ici et non recopié dans chaque contrôleur : une
 * variante oubliée renverrait une redirection 302 à `fetch()`, que le module
 * lirait comme un succès muet alors que rien n'aurait été enregistré.
 *
 * Convention de charge utile : `{"ok": true|false, "message"|"error": "…"}`.
 * Le module n'affiche « Enregistré » que sur `ok: true` — jamais sur un simple
 * statut HTTP 200, précisément pour attraper ce cas.
 */
final class AjaxResponse
{
    /**
     * Vrai si l'appelant attend du JSON.
     *
     * Même détection que l'historique `AdminController::wantsJsonResponse()`
     * (documents de profil, bases de connaissances) : `Accept` explicite OU
     * en-tête AJAX. Les deux, parce que les appels existants n'envoient pas
     * tous les mêmes en-têtes.
     */
    public static function wanted(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Émet une charge utile JSON et termine la requête.
     *
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Succès JSON.
     *
     * @param array<string, mixed> $extra Données que l'écran doit resynchroniser
     *                                    (libellés recalculés, listes canoniques…).
     */
    public static function ok(array $extra = []): void
    {
        self::send(['ok' => true] + $extra);
    }

    /** Échec JSON. 422 par défaut : la requête est valide, la donnée non. */
    public static function fail(string $error, int $status = 422): void
    {
        self::send(['ok' => false, 'error' => $error], $status);
    }

    /**
     * Sortie de succès d'un handler à double mode.
     *
     * @param array<string, mixed> $extra
     */
    public static function finish(
        bool $asJson,
        string $flashKey,
        string $message,
        string $location,
        array $extra = []
    ): void {
        if ($asJson) {
            self::ok(['message' => $message] + $extra);
        }
        Session::flash($flashKey, $message);
        header('Location: ' . $location);
        exit;
    }

    /** Sortie d'échec d'un handler à double mode. */
    public static function abort(
        bool $asJson,
        string $flashKey,
        string $error,
        string $location,
        int $status = 422
    ): void {
        if ($asJson) {
            self::fail($error, $status);
        }
        Session::flash($flashKey, $error);
        header('Location: ' . $location);
        exit;
    }
}
