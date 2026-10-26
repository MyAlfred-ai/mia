<?php

declare(strict_types=1);

/**
 * Socle des services GOOGLE par utilisateur (connecteurs personnels : Drive,
 * Gmail, Agenda, Tasks…). Chaque connecteur n'écrit plus que son MÉTIER
 * (recherche, lecture, écritures approuvées) : l'identité API se déclare en
 * constantes, la plomberie commune à TOUS les fournisseurs vit dans
 * ConnectorApiService (refresh lazy, retry 401, erreurs FR sans fuite), et ce
 * fichier ne garde que ce qui est propre à Google —
 *
 *   - 403 « scope insuffisant » (ACCESS_TOKEN_SCOPE_INSUFFICIENT) — détection
 *     partagée `isScopeError()`, parce que la distinguer d'un 403 « droits
 *     insuffisants sur la ressource » est un piège que chaque connecteur
 *     rencontre : les deux portent le même `PERMISSION_DENIED`,
 *   - 403 « API non activée sur le projet Google Cloud » (SERVICE_DISABLED),
 *     avec le nom exact de l'API à activer et le numéro du projet en cause,
 *   - la forme du corps d'erreur Google (`error.status`).
 *
 * Ajouter un connecteur Google = une sous-classe GoogleUserOAuth + une
 * sous-classe d'ICI qui déclare ses constantes et son métier (+ l'entrée
 * ConnectorRegistry et le câblage chat). Ajouter un connecteur d'un AUTRE
 * fournisseur = un socle frère (cf. MicrosoftUserApiService).
 *
 * Le Bearer voyage UNIQUEMENT en header ; chaque service garde ses messages
 * d'erreur propres (MSG_*) : ils nomment le connecteur et guident vers la
 * bonne action (reconnexion, console Google Cloud, réessai).
 */
abstract class GoogleUserApiService extends ConnectorApiService
{
    /**
     * Particularités Google, consultées après le hook du connecteur et avant
     * le squelette commun. L'ordre interne reproduit celui d'origine : un
     * scope insuffisant se reconnaît avant une API désactivée.
     */
    protected function providerHttpError(int $status, string $body): ?\RuntimeException
    {
        if ($status === 403 && self::isScopeError($body)) {
            return new \RuntimeException(static::MSG_SCOPE_INSUFFICIENT);
        }
        if ($status === 403 && ($api = self::disabledApiFromBody($body)) !== null) {
            $proj = self::projectNumberFromBody($body);
            return new \RuntimeException(
                'L\'API ' . $api . ' n\'est pas activée sur le projet Google Cloud de l\'application'
                . ($proj !== null ? ' (projet n° ' . $proj . ')' : '') . '. '
                . 'Un administrateur doit l\'activer dans la console Google Cloud '
                . '(APIs et services → Bibliothèque), sur CE projet précisément, '
                . 'puis réessayer après quelques minutes de propagation.'
            );
        }
        return null;
    }

    /**
     * Ce 403 dit-il que l'APPLICATION n'a pas l'autorisation (scope non
     * accordé), plutôt que l'UTILISATEUR les droits sur la ressource ?
     *
     * Statique publique parce que c'est une distinction que les connecteurs
     * doivent pouvoir faire eux-mêmes : les deux cas portent le même
     * `"status": "PERMISSION_DENIED"` alors que les remèdes sont OPPOSÉS —
     * reconnecter en accordant l'autorisation d'un côté, demander le rôle
     * « Éditeur » au propriétaire de l'autre. Confondre les deux fait perdre
     * un temps considérable : le message envoie réclamer un droit que l'on
     * possède déjà, en affirmant qu'une reconnexion n'y changerait rien.
     *
     * `insufficientPermissions` est la raison Google du scope manquant ; elle
     * est cherchée telle quelle et JAMAIS par un `str_contains('insufficient')`
     * qui attraperait aussi `insufficientFilePermissions` — le cas exactement
     * inverse.
     */
    public static function isScopeError(string $body): bool
    {
        return str_contains($body, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')
            || str_contains($body, '"insufficientPermissions"')
            || str_contains($body, 'insufficient authentication scopes');
    }

    /** Corps d'erreur Google : le statut structuré (ex. PERMISSION_DENIED). */
    protected function errorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) && is_string($decoded['error']['status'] ?? null)
            ? (string)$decoded['error']['status']
            : '';
    }

    /**
     * Détecte un 403 « API non activée sur le projet Google Cloud »
     * (reason SERVICE_DISABLED, ou accessNotConfigured en format legacy) et
     * renvoie le nom lisible du service en cause, null sinon. Statique pure :
     * l'appelant décide du message.
     */
    public static function disabledApiFromBody(string $body): ?string
    {
        if (!str_contains($body, 'SERVICE_DISABLED') && !str_contains($body, 'accessNotConfigured')) {
            return null;
        }
        return match (true) {
            str_contains($body, 'docs.googleapis.com')     => 'Google Docs',
            str_contains($body, 'sheets.googleapis.com')   => 'Google Sheets',
            str_contains($body, 'gmail.googleapis.com')    => 'Gmail',
            str_contains($body, 'calendar-json.googleapis.com') => 'Google Agenda',
            str_contains($body, 'tasks.googleapis.com')    => 'Google Tasks',
            str_contains($body, 'drive.googleapis.com')    => 'Google Drive',
            default                                        => 'Google',
        };
    }

    /**
     * Numéro du projet Google Cloud cité dans un corps d'erreur Google
     * (« project 123456 » ou « projects/123456 »), null sinon. Sert à
     * lever l'ambiguïté « API activée mais sur le mauvais projet ».
     */
    public static function projectNumberFromBody(string $body): ?string
    {
        return preg_match('#projects?[/ ](\d{6,})#', $body, $m) === 1 ? $m[1] : null;
    }

    /**
     * Un scope a-t-il réellement été accordé ? Comparaison sur la liste EXACTE
     * des scopes (séparés par des espaces) et JAMAIS par `str_contains` : chez
     * Google, un scope est systématiquement le préfixe d'un scope plus étroit —
     * `.../auth/tasks` préfixe `.../auth/tasks.readonly`, `.../auth/drive`
     * préfixe `drive.readonly` ET `drive.file`, `.../auth/drive.metadata`
     * préfixe `drive.metadata.readonly`. Une connexion en lecture seule
     * passerait donc pour une connexion en écriture, le connecteur exposerait
     * des outils que Google refuserait ensuite en 403, et la page Connexions
     * afficherait « Écriture ✓ » sur une connexion incapable d'écrire.
     *
     * Vit sur le socle (et non sur un connecteur) pour la même raison que son
     * homologue de `MicrosoftUserApiService` : deux comparaisons divergentes
     * mentiraient à moitié. Reste adressable par héritage
     * (`UserTasksService::scopeGranted()`, `UserDriveService::scopeGranted()`),
     * ce qu'utilise la page Connexions, qui n'a que la colonne `scope`.
     */
    public static function scopeGranted(string $grantedScope, string $scope): bool
    {
        foreach (preg_split('/\s+/', trim($grantedScope)) ?: [] as $granted) {
            if ($granted === $scope) {
                return true;
            }
        }
        return false;
    }
}
