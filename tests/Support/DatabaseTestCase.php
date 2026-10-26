<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base des tests fonctionnels qui touchent la vraie base MySQL.
 *
 * - Connexion via le singleton Database (qui applique aussi les migrations),
 *   exactement comme l'app au boot.
 * - Si MySQL est indisponible (poste dev sans base de test), les tests sont
 *   SKIPPÉS plutôt qu'en échec : le hook pre-push ne bloque pas un dev sans
 *   base, et la CI (où MySQL est présent) reste le garde-fou réel.
 * - Chaque test démarre sur des tables vidées (isolation).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;
    private static bool $unavailable = false;

    /** Tables vidées avant chaque test (ordre indifférent, FK désactivées). */
    protected const TABLES = [
        'workflow_artifacts',
        'workflow_run_steps',
        'workflow_runs',
        'workflows',
        'scheduled_task_runs',
        'scheduled_tasks',
        'push_subscriptions',
        'job_events',
        'async_jobs',
        'creation_shares',
        'drive_pending_actions',
        'conversation_connector_refs',
        'conversation_knowledge_bases',
        'conversation_context_summaries',
        'messages',
        'conversations',
        'tool_usage_logs',
        'usage_logs',
        'agent_turn_stats',
        'chat_tools',
        'user_group_llms',
        'user_group_profiles',
        'user_group_tools',
        'user_group_members',
        'user_groups',
        'profile_access',
        'profile_tools',
        'profile_document_chunks',
        'profile_documents',
        'profile_knowledge_bases',
        'knowledge_base_shares',
        'knowledge_base_remotes',
        'knowledge_base_chunks',
        'knowledge_base_documents',
        'knowledge_bases',
        'profiles',
        'user_identities',
        'users',
        // Rôles personnalisés et surcharges : vidés, les rôles livrés et leurs
        // défauts s'appliquent (Role les définit dans le code).
        'roles',
    ];

    protected function setUp(): void
    {
        if (self::$unavailable) {
            $this->markTestSkipped('Base MySQL de test indisponible.');
        }
        if (self::$pdo === null) {
            try {
                self::$pdo = Database::getInstance();
            } catch (\Throwable $e) {
                self::$unavailable = true;
                $this->markTestSkipped('Base MySQL de test indisponible : ' . $e->getMessage());
            }
        }
        $this->truncateTables();
        // Incidents de compte fournisseur : magasin MÉMOIRE vidé à chaque test.
        // Les suites unitaire et fonctionnelle tournent dans le même processus
        // (CI) : un « clé refusée » laissé par un test unitaire de la voix
        // devenait un point à régler inattendu ici.
        ProviderIncident::useMemoryStore();
        // Rôles : lecture mémorisée pour le processus — un test qui a modifié
        // un rôle ne doit pas la laisser au suivant.
        Role::forget();
    }

    protected function db(): PDO
    {
        return self::$pdo;
    }

    private function truncateTables(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            try {
                self::$pdo->exec("TRUNCATE TABLE `$table`");
            } catch (\Throwable) {
                // table absente sur ce schéma → ignorer
            }
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Insère un utilisateur avec un id explicite (nécessaire car profiles.created_by
     * et user_group_members.user_id ont une FK vers users(id)).
     */
    protected function insertUser(int $id, string $role = 'user'): int
    {
        self::$pdo->prepare(
            'INSERT INTO users (id, google_id, email, name, role, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, "g{$id}", "user{$id}@test.local", "User {$id}", $role]);
        return $id;
    }

    /** Insère un assistant minimal et renvoie son id. */
    protected function insertProfile(
        string $name,
        bool $isPersonal,
        ?int $createdBy = null,
        bool $active = true
    ): int {
        $stmt = self::$pdo->prepare(
            'INSERT INTO profiles (name, system_prompt, is_active, is_personal, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, 'prompt', $active ? 1 : 0, $isPersonal ? 1 : 0, $createdBy]);
        return (int) self::$pdo->lastInsertId();
    }

    /** Insère un groupe et renvoie son id. */
    protected function insertGroup(string $name, ?int $creditQuotaK = null, bool $active = true): int
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO user_groups (name, credit_quota_k, is_active) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $creditQuotaK, $active ? 1 : 0]);
        return (int) self::$pdo->lastInsertId();
    }

    protected function linkGroupProfile(int $groupId, int $profileId): void
    {
        self::$pdo->prepare('INSERT INTO user_group_profiles (group_id, profile_id) VALUES (?, ?)')
            ->execute([$groupId, $profileId]);
    }

    protected function addMember(int $userId, int $groupId): void
    {
        self::$pdo->prepare('INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)')
            ->execute([$userId, $groupId]);
    }

    protected function addGroupLlm(int $groupId, string $llmKey): void
    {
        self::$pdo->prepare('INSERT INTO user_group_llms (group_id, llm_key) VALUES (?, ?)')
            ->execute([$groupId, $llmKey]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function ids(array $rows): array
    {
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }
}
