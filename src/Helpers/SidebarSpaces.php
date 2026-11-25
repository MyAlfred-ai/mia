<?php

declare(strict_types=1);

/**
 * Tuiles d'espace du volet de gauche (piste A, choisie le 2026-11-09) : la
 * TEINTE de chaque espace et la LIGNE D'INFORMATION sous son nom
 * (« 12 disponibles », « 1 vous attend »…). SOURCE UNIQUE, partagée par la
 * page de discussion et le volet des pages d'espace (sidebar-shortcuts.php).
 *
 * Deux étages :
 *  - metaText() — PURE : des comptes → un texte traduit (ou null) ;
 *  - metas()    — les lectures, une fois par requête et par compte, qui ne
 *    lèvent JAMAIS : une ligne d'information n'empêche pas d'afficher le volet
 *    (lecture en échec → la tuile n'affiche que son nom).
 *
 * Coût : la page de discussion connaît déjà ses assistants et ses bases
 * (`$known`) ; restent deux comptes simples (tâches, workflows). Les pages
 * d'espace, qui ne les connaissent pas, en lisent quatre.
 */
final class SidebarSpaces
{
    /** Clé de NavRegistry::WORKSPACE → suffixe de la classe de teinte (`space-<ton>`, main.css). */
    public const TONES = [
        'profiles'  => 'asst',
        'kb'        => 'kb',
        'schedules' => 'task',
        'workflows' => 'wf',
        'creations' => 'crea',
    ];

    /** @var array<int, array<string, array{text:string, attention:bool}|null>> */
    private static array $memo = [];

    public static function tone(string $key): string
    {
        return self::TONES[$key] ?? 'neutral';
    }

    /**
     * Texte de la ligne d'information d'un espace, d'après ses comptes.
     * Null quand le compte manque (lecture impossible) : la tuile se tait.
     *
     * @param array<string, int|null> $c
     * @return array{text:string, attention:bool}|null
     */
    public static function metaText(string $key, array $c): ?array
    {
        $line = static fn(string $text, bool $attention = false): array => ['text' => $text, 'attention' => $attention];
        switch ($key) {
            case 'profiles':
                if (!isset($c['profiles'])) {
                    return null;
                }
                return $c['profiles'] === 0
                    ? $line(t('Aucun disponible'))
                    : $line(I18n::plural($c['profiles'], '{n} disponible', '{n} disponibles'));
            case 'kb':
                if (!isset($c['kb'])) {
                    return null;
                }
                return $c['kb'] === 0
                    ? $line(t('Aucune base'))
                    : $line(I18n::plural($c['kb'], '{n} base', '{n} bases'));
            case 'schedules':
                if (!isset($c['schedules'])) {
                    return null;
                }
                return $c['schedules'] === 0
                    ? $line(t('Aucune active'))
                    : $line(I18n::plural($c['schedules'], '{n} active', '{n} actives'));
            case 'workflows':
                // Ce qui VOUS attend passe avant tout : une décision, une carte.
                if (($c['wf_waiting'] ?? 0) > 0) {
                    return $line(I18n::plural((int)$c['wf_waiting'], '{n} vous attend', '{n} vous attendent'), true);
                }
                if (($c['wf_running'] ?? 0) > 0) {
                    return $line(t('{n} en cours', ['n' => (int)$c['wf_running']]));
                }
                if (!isset($c['wf_total'])) {
                    return null;
                }
                return $c['wf_total'] === 0
                    ? $line(t('Aucun workflow'))
                    : $line(I18n::plural($c['wf_total'], '{n} workflow', '{n} workflows'));
            case 'creations':
                return $line(t('Images, pages, présentations'));
        }
        return null;
    }

    /**
     * Lignes d'information des espaces ouverts à ce compte.
     *
     * @param list<string>          $keys  Espaces affichés (NavRegistry::workspaceFor).
     * @param array<string, int>    $known Comptes déjà connus de l'appelant (`profiles`, `kb`).
     * @return array<string, array{text:string, attention:bool}|null>
     */
    public static function metas(?array $user, array $keys, array $known = []): array
    {
        if ($user === null || empty($user['id'])) {
            return [];
        }
        $uid = (int)$user['id'];
        if (!isset(self::$memo[$uid])) {
            self::$memo[$uid] = [];
        }
        $out = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, self::$memo[$uid])) {
                self::$memo[$uid][$key] = self::metaText($key, self::counts($user, $key, $known));
            }
            $out[$key] = self::$memo[$uid][$key];
        }
        return $out;
    }

    /** Oublie les lignes mémorisées (tests, process long). */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /** @return array<string, int|null> */
    private static function counts(array $user, string $key, array $known): array
    {
        try {
            switch ($key) {
                case 'profiles':
                    if (isset($known['profiles'])) {
                        return ['profiles' => (int)$known['profiles']];
                    }
                    $scope = UserGroup::membershipScopeForUser((int)$user['id']);
                    return ['profiles' => count(Profile::accessibleByUser(
                        (int)$user['id'], (string)$user['role'], $scope['active_group_ids'], $scope['has_assignments']
                    ))];
                case 'kb':
                    if (isset($known['kb'])) {
                        return ['kb' => (int)$known['kb']];
                    }
                    return ['kb' => count(KnowledgeBase::selectableForUser($user))];
                case 'schedules':
                    $stmt = Database::getInstance()->prepare(
                        "SELECT COUNT(*) FROM scheduled_tasks WHERE user_id = ? AND status = 'active'"
                    );
                    $stmt->execute([(int)$user['id']]);
                    return ['schedules' => (int)$stmt->fetchColumn()];
                case 'workflows':
                    $stmt = Database::getInstance()->prepare(
                        "SELECT (SELECT COUNT(*) FROM workflow_runs WHERE user_id = ? AND status = 'running'),
                                (SELECT COUNT(*) FROM workflows WHERE user_id = ?)"
                    );
                    $stmt->execute([(int)$user['id'], (int)$user['id']]);
                    $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
                    return [
                        'wf_waiting' => count(WorkflowRun::waitingForUser((int)$user['id'])),
                        'wf_running' => (int)$row[0],
                        'wf_total'   => (int)$row[1],
                    ];
            }
        } catch (\Throwable $e) {
            error_log('[Volet] ligne d’information « ' . $key . ' » indisponible : ' . $e->getMessage());
        }
        return [];
    }
}
