<?php

declare(strict_types=1);

/**
 * Rôles et droits sur la vraie base (migration 090) : écriture des rôles
 * personnalisés, surcharge et retour aux défauts d'un rôle livré, suppression
 * avec réaffectation, et surtout les PORTES qui lisent les droits — assistants,
 * outils, bases, connecteurs, tâches programmées, workflows. Une requête
 * fausse passerait tous les tests unitaires.
 */
final class RolePipelineTest extends DatabaseTestCase
{
    private const NOW = '2026-10-05 06:00:00';

    /** @var array<string, mixed> */
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        Role::overrideRows(null);
        Role::forget();
        Settings::refresh();
        foreach ([ScheduledTask::SETTING_ENABLED, Workflow::SETTING_ENABLED, 'default_llm'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM roles');
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
        }
        Role::forget();
        parent::tearDown();
    }

    private function role(string $nom, array $droits): string
    {
        return Role::create(Role::normalizeInput(['name' => $nom, 'color' => '#14b8a6'], []), $droits);
    }

    private function compte(int $id, string $role): array
    {
        $this->insertUser($id, $role);
        return User::findById($id);
    }

    // ------------------------------------------------------------------
    // Écriture des rôles
    // ------------------------------------------------------------------

    public function testUnRolePersonnaliseSEnregistreFermeEtRelu(): void
    {
        $slug = $this->role('Responsable RH', ['users.edit', 'groups.create', 'nimporte.quoi']);
        $this->assertSame('responsable-rh', $slug);
        Role::forget();
        $role = Role::find($slug);
        $this->assertNotNull($role);
        $this->assertFalse($role['system']);
        $this->assertSame('#14b8a6', $role['color']);
        // Fermé et nettoyé : « Modifier » apporte « Voir », l'inconnu disparaît.
        $this->assertSame(['users.view', 'users.edit', 'groups.view', 'groups.create'], $role['permissions']);
        // Un droit ajouté au catalogue après l'enregistrement est REFUSÉ à un rôle personnalisé.
        $map = json_decode((string)$this->db()->query("SELECT permissions FROM roles WHERE slug = 'responsable-rh'")->fetchColumn(), true);
        $this->assertArrayHasKey('users.view', $map);

        Role::updateDetails($slug, ['name' => 'Ressources humaines', 'description' => 'RH', 'color' => '#ef4444']);
        $this->assertSame('Ressources humaines', Role::find($slug)['name']);
        $this->assertSame('responsable-rh', $slug, 'L’identifiant ne change jamais : les comptes gardent leur rôle.');
        Role::savePermissions($slug, ['dashboard.view']);
        $this->assertSame(['dashboard.view'], Role::find($slug)['permissions']);
    }

    public function testUnRoleLivreSeSurchargeEtRevientASesDefauts(): void
    {
        $this->assertFalse(Role::find('creator')['customized']);
        // Ligne absente (table vidée) : créée à la volée.
        Role::savePermissions('creator', array_diff(Permissions::defaultsFor('creator'), ['workflows.view', 'workflows.use', 'workflows.create', 'workflows.edit', 'workflows.delete']));
        $creator = Role::find('creator');
        $this->assertTrue($creator['customized']);
        $this->assertNotContains('workflows.view', $creator['permissions']);
        $this->assertSame('Créateur', $creator['name']);

        Role::resetPermissions('creator');
        $this->assertSame(Permissions::defaultsFor('creator'), Role::permissionsFor('creator'));
        $this->assertFalse(Role::find('creator')['customized']);

        // L'administrateur est verrouillé.
        $this->expectException(\InvalidArgumentException::class);
        Role::savePermissions('admin', []);
    }

    public function testUnRoleEnregistreAvantLaColonneGererEstReluSansGagnerDeDroit(): void
    {
        // Deux rôles écrits par la version d'avant (portée sous `.all`), à la main.
        $ancienne = static function (array $vrais): string {
            $map = [];
            foreach (Permissions::allKeys() as $k) {
                if (!str_ends_with($k, '.manage')) {
                    $map[$k] = in_array($k, $vrais, true);
                }
            }
            $map['assistants.all'] = in_array('assistants.all', $vrais, true);
            $map['knowledge_bases.all'] = in_array('knowledge_bases.all', $vrais, true);
            return (string)json_encode($map);
        };
        $insert = $this->db()->prepare('INSERT INTO roles (slug, name, description, color, permissions, is_system) VALUES (?, ?, \'\', \'#14b8a6\', ?, 0)');
        $insert->execute(['referent', 'Référent', $ancienne(['assistants.use', 'assistants.view', 'assistants.edit', 'assistants.all'])]);
        $insert->execute(['auditeur', 'Auditeur', $ancienne(['knowledge_bases.view', 'knowledge_bases.all'])]);
        Role::forget();

        // Portée + Modifier → Gérer : il garde la main sur tous les assistants.
        $this->assertSame(['assistants.view', 'assistants.use', 'assistants.edit', 'assistants.manage'], Role::permissionsFor('referent'));
        // Portée sans Modifier : Gérer supposerait Modifier — rien de gagné.
        $this->assertSame(['knowledge_bases.view'], Role::permissionsFor('auditeur'));

        // Réenregistré, il s'écrit dans la forme d'aujourd'hui.
        Role::savePermissions('referent', Role::permissionsFor('referent'));
        $map = json_decode((string)$this->db()->query("SELECT permissions FROM roles WHERE slug = 'referent'")->fetchColumn(), true);
        $this->assertTrue($map['assistants.manage']);
        $this->assertArrayNotHasKey('assistants.all', $map);
        Role::forget();
        $this->assertContains('assistants.manage', Role::permissionsFor('referent'));
    }

    public function testSupprimerUnRoleReaffecteSesComptesSansJamaisPromouvoir(): void
    {
        $slug = $this->role('Support', ['assistants.use']);
        $this->compte(1, $slug);
        $this->compte(2, $slug);
        $this->compte(3, 'user');
        $this->assertSame(2, Role::memberCounts()[$slug] ?? 0);

        foreach (['admin', $slug, 'inexistant'] as $refuse) {
            try {
                Role::delete($slug, $refuse);
                $this->fail("Réaffectation acceptée à tort vers $refuse");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(2, Role::delete($slug, 'creator'));
        $this->assertFalse(Role::exists($slug));
        $this->assertSame('creator', User::findById(1)['role']);
        $this->assertSame('creator', User::findById(2)['role']);
        $this->assertSame('user', User::findById(3)['role']);

        foreach (Role::SYSTEM as $livre) {
            try {
                Role::delete($livre, 'user');
                $this->fail("Rôle livré supprimé : $livre");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(Role::exists($livre));
            }
        }
    }

    public function testLePlafondDeRolesPersonnalises(): void
    {
        for ($i = 0; $i < Role::MAX_CUSTOM; $i++) {
            $this->role('Rôle ' . $i, []);
        }
        $this->assertCount(Role::MAX_CUSTOM, Role::custom());
        $this->expectException(\InvalidArgumentException::class);
        $this->role('Un de trop', []);
    }

    // ------------------------------------------------------------------
    // Les portes qui lisent les droits
    // ------------------------------------------------------------------

    public function testLesAssistantsSuiventLeDroitUtiliserEtLaPortee(): void
    {
        $this->compte(1, 'creator');
        $orga   = $this->insertProfile('Commercial', false);
        $perso  = $this->insertProfile('Mon assistant', true, 1);
        $groupe = $this->insertGroup('Ventes');
        $this->linkGroupProfile($groupe, $orga);

        // Utilisateur ordinaire : les assistants de ses groupes ; son créateur
        // retrouve en plus son assistant personnel.
        $this->assertSame([$orga], $this->ids(Profile::accessibleByUser(9, 'user', [$groupe], true)));
        $this->assertSame([$orga, $perso], $this->ids(Profile::accessibleByUser(1, 'creator', [$groupe], true)));
        // Sans « Assistants — Utiliser » : aucun, ni ceux des groupes, ni les siens.
        $muet = $this->role('Sans assistants', ['tools.use']);
        $this->assertSame([], Profile::accessibleByUser(1, $muet, [$groupe], true));
        // « Gérer » : tous les assistants d'organisation, sans groupe.
        $tous = $this->role('Tous les assistants', ['assistants.use', 'assistants.manage']);
        $this->assertSame([$orga], $this->ids(Profile::accessibleByUser(2, $tous, [], false)));
        $this->assertNotContains($perso, $this->ids(Profile::accessibleByUser(2, $tous, [], false)), 'Jamais l’assistant personnel d’un autre.');

        // Modifier : les siens ; avec « Gérer », tous.
        $editeur = $this->compte(2, $this->role('Éditeur', ['assistants.manage']));
        $this->assertContains('assistants.edit', Role::permissionsFor((string)$editeur['role']), 'Gérer suppose Modifier.');
        $this->assertTrue(Profile::manageableBy($perso, User::findById(1)));
        $this->assertTrue(Profile::manageableBy($orga, $editeur));
        $this->assertTrue(Profile::manageableBy($perso, $editeur));
        $this->assertFalse(Profile::manageableBy($orga, User::findById(1)), 'Un créateur ne gère que les siens.');
    }

    public function testLesOutilsSuiventLeDroitUtiliser(): void
    {
        $groupe = $this->insertGroup('Ventes');
        $this->db()->prepare(
            "INSERT INTO chat_tools (name, type, provider, model, config_json, api_key_enc, is_active) VALUES ('Images', 'image', 'openai', 'gpt-image-2', '{}', ?, 1)"
        )->execute([Encryption::encrypt('cle-de-test')]);
        $outil = (int)$this->db()->lastInsertId();
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$groupe, $outil]);

        $this->assertSame([$outil], $this->ids(ChatTool::activeByUser('image', 'user', [$groupe], true)));
        $sans = $this->role('Sans outils', ['assistants.use']);
        $this->assertSame([], ChatTool::activeByUser('image', $sans, [$groupe], true));
        $this->assertNotNull(ChatTool::findActiveByIdForUser($outil, 'user', [$groupe], true));
        $this->assertNull(ChatTool::findActiveByIdForUser($outil, $sans, [$groupe], true), 'Revérifié au moment T (carte approuvée, job).');
    }

    public function testLesBasesSuiventLeDroitUtiliserEtLaPortee(): void
    {
        $this->compte(1, 'creator');
        $kb = KnowledgeBase::create([
            'name' => 'Comptes rendus', 'description' => null, 'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536, 'is_active' => 1, 'created_by' => 1,
        ]);
        $this->assertContains($kb, array_map('intval', array_column(KnowledgeBase::selectableForUser(User::findById(1)), 'id')));
        $this->assertTrue(KnowledgeBase::manageableBy($kb, User::findById(1)));

        // Sans « Bases — Utiliser » : rien à choisir dans la conversation, même ses propres bases.
        Role::savePermissions('creator', array_diff(Permissions::defaultsFor('creator'), ['knowledge_bases.use']));
        $this->assertSame([], KnowledgeBase::selectableForUser(User::findById(1)));
        Role::resetPermissions('creator');

        // « Gérer » : voit, gère et partage la base d'un autre.
        $gestion = $this->compte(2, $this->role('Gestion des bases', ['knowledge_bases.use', 'knowledge_bases.manage']));
        $this->assertTrue(KnowledgeBase::manageableBy($kb, $gestion));
        $this->assertTrue(KnowledgeBase::ownedBy($kb, $gestion), 'Gérer ouvre le partage des bases des autres.');
        $this->assertContains($kb, array_map('intval', array_column(KnowledgeBase::forManager($gestion), 'id')));
        $this->assertContains($kb, array_map('intval', array_column(KnowledgeBase::selectableForUser($gestion), 'id')));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $this->compte(3, 'user')));
        // Modifier sans Gérer : les siennes seulement.
        $modif = $this->compte(4, $this->role('Modif des bases', ['knowledge_bases.use', 'knowledge_bases.edit']));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $modif));
        $this->assertFalse(KnowledgeBase::ownedBy($kb, $modif));
    }

    public function testLesConnecteursSuiventLeDroitUtiliser(): void
    {
        $this->assertTrue(ConnectorRegistry::usableBy($this->compte(1, 'user')));
        $sans = $this->compte(2, $this->role('Sans connecteurs', ['assistants.use']));
        $this->assertFalse(ConnectorRegistry::usableBy($sans));
        $this->assertFalse(ConnectorRegistry::oneDriveUsefulFor('user'), 'OneDrive ne sert qu’à alimenter une base.');
        $this->assertTrue(ConnectorRegistry::oneDriveUsefulFor('creator'));
        $this->assertTrue(ConnectorRegistry::oneDriveUsefulFor('admin'));
    }

    public function testLesWorkflowsEtLesTachesSuiventLeRoleEtLeReglage(): void
    {
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        Settings::set(Workflow::SETTING_ENABLED, '1');
        $user    = $this->compte(1, 'user');
        $creator = $this->compte(2, 'creator');
        $this->assertTrue(ScheduledTask::allows($user, 'create'));
        $this->assertFalse(Workflow::allows($user, 'view'), 'Les workflows restent aux créateurs par défaut.');
        $this->assertTrue(Workflow::allows($creator, 'create'));

        $lecteur = $this->compte(3, $this->role('Lecteur', ['schedules.view']));
        $this->assertTrue(ScheduledTask::allows($lecteur, 'view'));
        $this->assertFalse(ScheduledTask::allows($lecteur, 'use'));
        $this->assertFalse(ScheduledTask::allows($lecteur, 'create'));

        // La fonctionnalité coupée par l'administrateur l'emporte sur le rôle.
        Settings::set(ScheduledTask::SETTING_ENABLED, '0');
        $this->assertFalse(ScheduledTask::allows(User::findById(1), 'view'));
        Settings::set(Workflow::SETTING_ENABLED, '0');
        $this->assertFalse(Workflow::allows(User::findById(2), 'view'));
    }

    public function testUneTacheDontLeRoleNePermetPlusDeLExecuterEstSauteeSansPause(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'), 'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));

        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $this->compte(1, 'user');
        $task = ScheduledTask::create(1, [
            'name' => 'Point du lundi', 'instruction' => 'Fais le point.', 'profile_id' => null, 'llm' => '',
            'rule' => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], 'timezone' => 'Europe/Paris',
            'sources' => [], 'destination' => ScheduledTask::DEST_THREAD,
        ], $now->modify('-7 days'));
        ScheduledTask::setNextRun($task, $now->modify('-1 minute'));

        // Le rôle perd « Tâches — Utiliser » après la création de la tâche.
        Role::savePermissions('user', array_diff(Permissions::defaultsFor('user'), ['schedules.use', 'schedules.create', 'schedules.edit', 'schedules.delete']));

        $this->assertSame(0, Scheduler::tick($now, false));
        $run = $this->db()->query('SELECT status, reason FROM scheduled_task_runs')->fetch();
        $this->assertSame(['status' => 'skipped', 'reason' => ScheduledTask::REASON_ROLE], ['status' => $run['status'], 'reason' => $run['reason']]);
        $tache = ScheduledTask::findForUser($task, 1);
        $this->assertSame('active', $tache['status'], 'Pas de pause : le droit rétabli, la tâche repart d’elle-même.');
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM messages')->fetchColumn(), 'Aucun message, aucun job.');
        $this->assertGreaterThan($now->format('Y-m-d H:i:s'), $tache['next_run_at']);
    }
}
