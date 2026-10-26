<?php

declare(strict_types=1);

/**
 * Administrateurs à prévenir — ceux que l'encadré d'erreur du chat propose
 * d'écrire quand une panne ne se règle que dans l'administration (crédit
 * épuisé chez un fournisseur, clé refusée, modèle retiré).
 *
 * Le problème résolu : « Contactez un administrateur » ne dit pas QUI. Un
 * utilisateur qui n'a jamais eu affaire à l'administration de MIA restait
 * devant une consigne impossible à suivre. Le chat reçoit la liste des
 * administrateurs actifs (nom et adresse, rien d'autre), et l'encadré la
 * déplie au clic sur « Prévenir un administrateur », chaque nom ouvrant un
 * message prérempli.
 *
 * Règles :
 *   - comptes administrateurs ACTIFS seulement, dont l'adresse est valide ;
 *     l'accès de secours local (`local_admin`, « admin@local ») n'est la
 *     boîte de personne ;
 *   - la personne connectée n'est pas proposée à elle-même ;
 *   - au plus `MAX` noms, triés par nom : au-delà, une liste n'aide plus à
 *     choisir à qui écrire ;
 *   - lecture best-effort : une panne rend une liste vide, et l'encadré
 *     retombe sur sa consigne générale.
 */
final class AdminContacts
{
    public const MAX = 5;

    /**
     * Administrateurs à proposer à `$viewerId` (null = personne à exclure).
     *
     * @return list<array{name:string, email:string}>
     */
    public static function forViewer(?int $viewerId): array
    {
        try {
            $rows = Database::getInstance()->query(
                "SELECT id, name, email, role, is_active, google_id FROM users WHERE role = 'admin' AND is_active = 1"
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('[Contacts administrateurs] lecture impossible : ' . $e->getMessage());
            return [];
        }
        return self::fromRows(is_array($rows) ? $rows : [], $viewerId);
    }

    /**
     * Forme PURE : lignes `users` → contacts à afficher.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{name:string, email:string}>
     */
    public static function fromRows(array $rows, ?int $viewerId, int $max = self::MAX): array
    {
        $out  = [];
        $vues = [];
        foreach ($rows as $r) {
            if (($r['role'] ?? '') !== 'admin' || (int)($r['is_active'] ?? 0) !== 1) {
                continue;
            }
            if ($viewerId !== null && (int)($r['id'] ?? 0) === $viewerId) {
                continue;
            }
            if (($r['google_id'] ?? '') === 'local_admin') {
                continue;
            }
            $email = strtolower(trim((string)($r['email'] ?? '')));
            // Une adresse sans domaine pointé (« admin@local ») n'atteint personne.
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !str_contains(substr($email, strpos($email, '@') + 1), '.')) {
                continue;
            }
            if (isset($vues[$email])) {
                continue;
            }
            $vues[$email] = true;
            $name  = trim((string)preg_replace('/\s+/u', ' ', (string)($r['name'] ?? '')));
            $out[] = ['name' => $name !== '' ? $name : $email, 'email' => $email];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_slice($out, 0, max(0, $max));
    }
}
