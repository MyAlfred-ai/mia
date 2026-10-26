<?php

declare(strict_types=1);

/**
 * Socle des services MICROSOFT par utilisateur (connecteurs personnels bâtis
 * sur Microsoft Graph : Outlook, et les suivants). Frère de
 * GoogleUserApiService : la plomberie commune aux deux fournisseurs vit dans
 * ConnectorApiService, ce fichier ne porte que ce qui est propre à Microsoft.
 *
 * DEUX différences avec Google, toutes deux payées ailleurs dans ce dépôt et
 * qu'il ne faut pas gommer :
 *
 *  1. **Jetons roulants.** Microsoft renvoie un NOUVEAU refresh token à chaque
 *     rafraîchissement. Ne pas le persister condamne la connexion à mourir à
 *     l'expiration du premier jeton, sans une ligne de log — c'est le piège
 *     déjà rencontré sur OneDrive (cf. UserOneDriveService). D'où
 *     `afterRefresh()`, qui écrit le jeton roulé via
 *     `UserDriveToken::updateRefreshToken()`.
 *
 *  2. **Pas de « SERVICE_DISABLED ».** Là où Google réclame l'activation d'une
 *     API sur un projet Cloud, Microsoft refuse sur des CODES d'erreur Graph.
 *     Les deux qui comptent, parce qu'ils envoient chercher au bon endroit :
 *     `Authorization_RequestDenied` / `ErrorAccessDenied` (permission déléguée
 *     non consentie → reconnexion, ou consentement administrateur de
 *     l'annuaire) et `MailboxNotEnabledForRESTAPI` (le compte n'a PAS de boîte
 *     Exchange Online — aucune reconnexion n'y changera rien, et sans ce
 *     message l'utilisateur boucle sur « reconnectez-vous »).
 *
 * Le Bearer voyage UNIQUEMENT en header, et le corps d'erreur Graph n'est
 * jamais renvoyé au client : seul son `error.code` structuré l'est.
 */
abstract class MicrosoftUserApiService extends ConnectorApiService
{
    /** Racine Microsoft Graph — un seul endroit à bouger si la version change. */
    protected const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /**
     * Le compte n'a pas de boîte Exchange Online (licence absente, compte
     * annuaire-seul). À déclarer par le service : lui seul sait nommer ce que
     * l'utilisateur croyait atteindre.
     */
    protected const MSG_NO_MAILBOX = '';

    /**
     * Refresh réussi : Microsoft a pu renvoyer un refresh token ROULANT, qui
     * remplace l'ancien. Sans cette écriture, la connexion expire en silence.
     */
    protected function afterRefresh(array $fresh): void
    {
        $rolled = $fresh['refresh_token'] ?? null;
        if (is_string($rolled) && $rolled !== '' && $rolled !== $this->refreshToken) {
            $this->refreshToken = $rolled;
            UserDriveToken::updateRefreshToken($this->userId, $rolled, static::TOKEN_PROVIDER);
        }
    }

    /**
     * Particularités Microsoft Graph, consultées après le hook du connecteur
     * et avant le squelette commun. L'absence de boîte est testée AVANT le
     * refus d'accès : c'est le cas le plus spécifique, et le seul qu'une
     * reconnexion ne résout pas.
     */
    protected function providerHttpError(int $status, string $body): ?\RuntimeException
    {
        $code = self::graphErrorCode($body);

        if (self::isMailboxMissing($code, $body) && static::MSG_NO_MAILBOX !== '') {
            return new \RuntimeException(static::MSG_NO_MAILBOX);
        }
        if ($status === 403 && self::isPermissionDenied($code)) {
            return new \RuntimeException(static::MSG_SCOPE_INSUFFICIENT);
        }
        return null;
    }

    /** Corps d'erreur Graph : le code structuré (ex. ErrorAccessDenied). */
    protected function errorDetail(string $body): string
    {
        return self::graphErrorCode($body);
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Le scope est-il réellement accordé ? Comparaison EXACTE, jeton par jeton,
     * JAMAIS un `str_contains` — et c'est valable pour tout Microsoft Graph, pas
     * seulement pour un connecteur :
     *   - `Mail.Read` est un préfixe de `Mail.ReadWrite` (qui l'implique) ET de
     *     `Mail.ReadBasic` (qui n'ouvre PAS le corps des messages) ;
     *   - `Calendars.Read` est un préfixe de `Calendars.ReadWrite` (qui
     *     l'implique) ET de `Calendars.ReadBasic` (qui ne rend que des créneaux
     *     occupé/libre, sans titre ni participants).
     * Une comparaison par sous-chaîne afficherait donc « Lecture ✓ » sur une
     * connexion incapable de lire quoi que ce soit d'utile, et exposerait des
     * outils voués au 403.
     *
     * Microsoft renvoie les scopes soit en forme courte, soit préfixés de
     * `https://graph.microsoft.com/` : les deux sont acceptées.
     */
    public static function scopeGranted(string $granted, string $scope): bool
    {
        foreach (preg_split('/\s+/', trim($granted)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $court = str_contains($token, '/') ? substr((string)strrchr($token, '/'), 1) : $token;
            if (strcasecmp($court, $scope) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * `error.code` d'un corps d'erreur Graph, chaîne vide si illisible. Graph
     * imbrique parfois le vrai code dans `error.innerError.code` — c'est lui
     * qui porte `MailboxNotEnabledForRESTAPI` sur certaines réponses.
     */
    public static function graphErrorCode(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        foreach ([$decoded['error']['code'] ?? null, $decoded['error']['innerError']['code'] ?? null] as $code) {
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }
        return '';
    }

    /**
     * Le compte n'a pas de boîte Exchange Online exploitable. Testé sur le
     * code ET sur le corps : selon l'endpoint, Graph range ce refus tantôt en
     * `error.code`, tantôt seulement dans le message.
     */
    public static function isMailboxMissing(string $code, string $body): bool
    {
        foreach (['MailboxNotEnabledForRESTAPI', 'MailboxNotHostedInExchangeOnline', 'ResourceNotDiscovered'] as $needle) {
            if (str_contains($code, $needle) || str_contains($body, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Permission déléguée non consentie (utilisateur ou administrateur). */
    public static function isPermissionDenied(string $code): bool
    {
        return in_array($code, [
            'Authorization_RequestDenied',
            'ErrorAccessDenied',
            'AccessDenied',
        ], true);
    }
}
