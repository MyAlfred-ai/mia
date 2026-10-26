<?php

declare(strict_types=1);

/**
 * Personnes → Rôles (/admin/roles) — ce que chaque rôle peut voir,
 * utiliser, créer, modifier, supprimer et gérer. Administrateurs SEULS, et ce n'est
 * pas un droit délégable (Permissions::ADMIN_ONLY) : un rôle qui pourrait
 * modifier les rôles pourrait s'accorder tous les droits.
 *
 *   GET  /admin/roles[?role={slug}]       liste des rôles + droits du rôle choisi, case par case
 *                                         (sans ?role= : le premier de la liste)
 *   POST /admin/roles                     créer un rôle (nom, phrase, couleur, point de départ)
 *   POST /admin/roles/{slug}              enregistrer ses droits (et nom, phrase, couleur)
 *   POST /admin/roles/{slug}/duplicate    dupliquer
 *   POST /admin/roles/{slug}/reset        rôle livré : droits par défaut
 *   POST /admin/roles/{slug}/delete       supprimer (ses comptes changent de rôle)
 *
 * ENREGISTREMENT MANUEL, exception assumée à l'auto-save (comme
 * Authentification et Budget) : des droits ne changent pas à moitié cochés —
 * on relit, puis on enregistre, et le message dit ce qui a changé et pour
 * combien de comptes. Chaque écriture est journalisée (`[Rôles]`).
 */
final class RoleController
{
    public function index(): void
    {
        AuthMiddleware::requireAdmin();

        $roles  = Role::all();
        $counts = Role::memberCounts();
        $slug   = (string)($_GET['role'] ?? '');
        if (!isset($roles[$slug])) {
            $slug = (string)array_key_first($roles);
        }
        $role = $roles[$slug];

        // Comptes dont le rôle n'existe plus (supprimé à la main en base,
        // sauvegarde partielle) : ils ont les droits d'un utilisateur — dit.
        $orphanCount = 0;
        foreach ($counts as $countSlug => $n) {
            if (!isset($roles[$countSlug])) {
                $orphanCount += $n;
            }
        }

        $success = Session::getFlash('success');
        $error   = Session::getFlash('error');
        $catalog = Permissions::clientCatalog();
        $openNew = isset($_GET['nouveau']);
        require ROOT . '/templates/admin/roles.php';
    }

    public function create(): void
    {
        AuthMiddleware::requireAdmin();
        try {
            $data = Role::normalizeInput($_POST, self::takenNames());
            $from = (string)($_POST['from'] ?? Role::USER);
            $permissions = ($from === 'none' || !Role::exists($from)) ? [] : Role::permissionsFor($from);
            $slug = Role::create($data, $permissions);
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: /admin/roles?nouveau=1');
            exit;
        } catch (\Throwable $e) {
            error_log('[Rôles] création impossible : ' . $e->getMessage());
            Session::flash('error', t('Le rôle n’a pas pu être créé. Réessayez.'));
            header('Location: /admin/roles');
            exit;
        }
        self::log('« ' . $data['name'] . ' » (' . $slug . ') créé'
            . ($from !== 'none' && Role::exists($from) ? ' à partir de ' . $from : ' sans droit'));
        Session::flash('success', t('Rôle « {name} » créé. Réglez ses droits ci-dessous, puis donnez-le à des comptes dans Personnes → Comptes.', ['name' => $data['name']]));
        header('Location: /admin/roles?role=' . rawurlencode($slug));
        exit;
    }

    public function update(string $slug): void
    {
        AuthMiddleware::requireAdmin();
        $role = Role::find($slug);
        if ($role === null) {
            $this->back(null, 'error', t('Rôle introuvable.'));
        }
        if ($role['locked']) {
            $this->back($slug, 'error', t('Le rôle Administrateur a tous les droits : il ne se modifie pas.'));
        }

        $before = $role['permissions'];
        $after  = Permissions::fromPost($_POST['perms'] ?? []);
        try {
            if (!$role['system']) {
                $data = Role::normalizeInput($_POST, self::takenNames($slug));
                Role::updateDetails($slug, $data);
            }
            Role::savePermissions($slug, $after);
        } catch (\InvalidArgumentException $e) {
            $this->back($slug, 'error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[Rôles] enregistrement de « ' . $slug . ' » impossible : ' . $e->getMessage());
            $this->back($slug, 'error', t('Les droits n’ont pas pu être enregistrés. Réessayez.'));
        }

        $saved   = Role::permissionsFor($slug);
        $diff    = Permissions::diff($before, $saved);
        $members = (int)(Role::memberCounts()[$slug] ?? 0);
        $name    = (string)(Role::find($slug)['name'] ?? $slug);
        self::log('« ' . $name . ' » (' . $slug . ') : '
            . ($diff['added'] !== [] ? '+' . implode(', +', $diff['added']) : '')
            . ($diff['added'] !== [] && $diff['removed'] !== [] ? ' ; ' : '')
            . ($diff['removed'] !== [] ? '−' . implode(', −', $diff['removed']) : '')
            . ($diff['added'] === [] && $diff['removed'] === [] ? 'droits inchangés' : ''));
        $this->back($slug, 'success', self::savedMessage($name, $diff, $members));
    }

    public function duplicate(string $slug): void
    {
        AuthMiddleware::requireAdmin();
        $role = Role::find($slug);
        if ($role === null) {
            $this->back(null, 'error', t('Rôle introuvable.'));
        }
        try {
            $data = Role::normalizeInput([
                'name'        => self::copyName((string)$role['name']),
                'description' => (string)$role['description'],
                'color'       => $role['system'] ? Role::COLORS[0] : (string)$role['color'],
            ], self::takenNames());
            $copy = Role::create($data, $role['permissions']);
        } catch (\InvalidArgumentException $e) {
            $this->back($slug, 'error', $e->getMessage());
        }
        self::log('« ' . $data['name'] . ' » (' . $copy . ') créé par duplication de ' . $slug);
        Session::flash('success', t('Rôle « {name} » créé avec les droits de « {source} ». Ajustez-les, puis enregistrez.', ['name' => $data['name'], 'source' => $role['name']]));
        header('Location: /admin/roles?role=' . rawurlencode($copy));
        exit;
    }

    public function reset(string $slug): void
    {
        AuthMiddleware::requireAdmin();
        $role = Role::find($slug);
        try {
            Role::resetPermissions($slug);
        } catch (\InvalidArgumentException $e) {
            $this->back($slug, 'error', $e->getMessage());
        }
        $after   = Role::permissionsFor($slug);
        $diff    = Permissions::diff((array)($role['permissions'] ?? []), $after);
        $members = (int)(Role::memberCounts()[$slug] ?? 0);
        self::log('« ' . ($role['name'] ?? $slug) . ' » (' . $slug . ') : droits par défaut rétablis');
        $parts = self::countsParts($diff, $members);
        $this->back($slug, 'success', $parts === null
            ? t('Droits par défaut du rôle « {name} » rétablis.', ['name' => $role['name'] ?? $slug])
            : t('Droits par défaut du rôle « {name} » rétablis : {changes} — {accounts}.', ['name' => $role['name'] ?? $slug] + $parts));
    }

    public function delete(string $slug): void
    {
        AuthMiddleware::requireAdmin();
        $role = Role::find($slug);
        if ($role === null) {
            $this->back(null, 'error', t('Rôle introuvable.'));
        }
        $fallback = (string)($_POST['fallback'] ?? Role::USER);
        try {
            $moved = Role::delete($slug, $fallback);
        } catch (\InvalidArgumentException $e) {
            $this->back($slug, 'error', $e->getMessage());
        }
        $fallbackName = (string)(Role::find($fallback)['name'] ?? $fallback);
        self::log('« ' . $role['name'] . ' » (' . $slug . ') supprimé — ' . $moved . ' compte(s) passé(s) à ' . $fallback);
        Session::flash('success', t('Rôle « {name} » supprimé.', ['name' => $role['name']])
            . ($moved > 0 ? ' ' . I18n::plural($moved, '{n} compte a pris le rôle « {role} ».', '{n} comptes ont pris le rôle « {role} ».', ['role' => $fallbackName]) : ''));
        header('Location: /admin/roles?role=' . rawurlencode($fallback));
        exit;
    }

    // ------------------------------------------------------------------
    // Formes (pures)
    // ------------------------------------------------------------------

    /**
     * Message après enregistrement : ce qui a changé, et pour combien de
     * comptes — c'est ce que l'administrateur doit savoir avant de partir.
     *
     * @param array{added: list<string>, removed: list<string>} $diff
     */
    public static function savedMessage(string $name, array $diff, int $members): string
    {
        $parts = self::countsParts($diff, $members);
        if ($parts === null) {
            return t('Rôle « {name} » enregistré : droits inchangés.', ['name' => $name]);
        }
        return t('Droits du rôle « {name} » enregistrés : {changes} — {accounts}.', ['name' => $name] + $parts);
    }

    /**
     * Les deux morceaux du message : « 2 droits ajoutés, 1 droit retiré » et
     * « 12 comptes concernés » ; null quand rien n'a changé.
     *
     * @param array{added: list<string>, removed: list<string>} $diff
     * @return array{changes: string, accounts: string}|null
     */
    private static function countsParts(array $diff, int $members): ?array
    {
        $parts = [];
        if ($diff['added'] !== []) {
            $parts[] = I18n::plural(count($diff['added']), '{n} droit ajouté', '{n} droits ajoutés');
        }
        if ($diff['removed'] !== []) {
            $parts[] = I18n::plural(count($diff['removed']), '{n} droit retiré', '{n} droits retirés');
        }
        if ($parts === []) {
            return null;
        }
        return [
            'changes'  => implode(', ', $parts),
            'accounts' => $members > 0
                ? I18n::plural($members, '{n} compte concerné', '{n} comptes concernés')
                : t('aucun compte ne porte ce rôle'),
        ];
    }

    /** « Copie de X » (le nom reste sous la limite). */
    public static function copyName(string $name): string
    {
        return mb_substr(t('Copie de {name}', ['name' => $name]), 0, Role::NAME_MAX);
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Noms déjà pris, hors `$except` : ceux des rôles personnalisés, et ceux
     * des rôles livrés dans toutes les langues (nom et libellé court).
     *
     * @return list<string>
     */
    private static function takenNames(?string $except = null): array
    {
        $names = Role::builtinNames();
        foreach (Role::custom() as $slug => $role) {
            if ($slug === $except) {
                continue;
            }
            $names[] = (string)$role['name'];
        }
        return $names;
    }

    /** Trace d'audit : qui a changé quels droits. */
    private static function log(string $what): void
    {
        $actor = Session::currentUser();
        error_log('[Rôles] ' . $what . ' — par #' . (int)($actor['id'] ?? 0) . ' (' . ($actor['email'] ?? '') . ')');
    }

    private function back(?string $slug, string $kind, string $message): never
    {
        Session::flash($kind, $message);
        header('Location: /admin/roles' . ($slug !== null ? '?role=' . rawurlencode($slug) : ''));
        exit;
    }
}
