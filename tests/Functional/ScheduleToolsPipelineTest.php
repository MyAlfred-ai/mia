<?php

declare(strict_types=1);

/**
 * MIA prépare une tâche programmée, sur la vraie base : l'outil
 * `programmer_tache` dépose une carte, une retouche dans la conversation
 * (`remplace`) la remplace en gardant le reste, l'assistant, les bases et les
 * outils se désignent par leur nom dans le périmètre de la personne, et un
 * modèle sans outils ne peut pas porter une tâche qui en utilise.
 *
 * Aucun appel de modèle : le handler de l'outil est appelé directement, la
 * file d'approbation est la vraie (DrivePendingAction).
 */
final class ScheduleToolsPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    /** @var list<array> évènements SSE émis par le handler */
    private array $emis = [];

    protected function setUp(): void
    {
        parent::setUp();
        I18n::use('fr');
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');

        Settings::refresh();
        foreach ([ScheduledTask::SETTING_ENABLED, 'default_llm', 'connector_web_enabled'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');

        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        // Perplexity : un modèle SANS outils (l'API Sonar n'en a pas).
        ApiKey::save('perplexity', 'pplx-test-factice');
        LlmModel::save([
            'provider' => 'perplexity', 'model' => 'sonar-pro',
            'display_name' => 'Sonar Pro', 'is_active' => 1, 'sort_order' => 1,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        $this->emis = [];
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------

    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return User::findById($id);
    }

    private function conversation(int $userId): int
    {
        return (int)Conversation::create($userId, null, 'openai::' . LlmCatalog::defaultModel('openai'))['id'];
    }

    /** Le fournisseur de l'outil pour ce tour, avec la vraie file d'approbation. */
    private function outil(array $user, int $convId, ?int $profileId = null): ScheduleTools
    {
        $turn = new AgentTurn(
            (int)$user['id'],
            $convId,
            $user,
            4000,
            function (array $e): void {
                $this->emis[] = $e;
            },
            static function (string $tool, array $args, ?array $ref) use ($user, $convId): array {
                $id = DrivePendingAction::create((int)$user['id'], $convId, $tool, $args, (array)($ref['preview'] ?? []));
                return ['statut' => 'en_attente_approbation', 'action_id' => $id];
            },
            'Europe/Paris'
        );
        $pending = DrivePendingAction::latestPendingForTool($convId, (int)$user['id'], ScheduleTools::TOOL);
        return new ScheduleTools(
            $turn,
            ScheduledTask::choicesFor($user),
            ['profile_id' => $profileId, 'llm' => '', 'sources' => ['web'], 'kb_ids' => []],
            $pending !== null ? (array)$pending['args'] : null
        );
    }

    private function appel(ScheduleTools $tools, array $args): array
    {
        return $tools->handlers()[ScheduleTools::TOOL]($args);
    }

    private function base(string $nom, int $createdBy, bool $prete = true): int
    {
        $kb = KnowledgeBase::create([
            'name' => $nom, 'description' => null, 'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536,
            'is_active' => 1, 'created_by' => $createdBy,
        ]);
        if ($prete) {
            $doc = KnowledgeBaseDocument::create([
                'kb_id' => $kb, 'file_name' => 'doc.txt', 'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
                'mime_type' => 'text/plain', 'file_size' => 10,
            ]);
            $this->db()->prepare("UPDATE knowledge_base_documents SET vector_status = 'done', chunk_count = 3 WHERE id = ?")
                ->execute([$doc]);
        }
        return $kb;
    }

    private function outilImage(string $nom, int $groupId): int
    {
        $this->db()->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, config_json, api_key_enc, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([$nom, 'image', 'openai', 'gpt-image-2.5-flare', '{}', Encryption::encrypt('cle-de-test')]);
        $id = (int)$this->db()->lastInsertId();
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$groupId, $id]);
        return $id;
    }

    private function statut(int $actionId): string
    {
        $stmt = $this->db()->prepare('SELECT status FROM drive_pending_actions WHERE id = ?');
        $stmt->execute([$actionId]);
        return (string)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------

    public function testUneRetoucheRemplaceLaCarteEtGardeLeReste(): void
    {
        $user = $this->user(1);
        $conv = $this->conversation(1);

        $premiere = $this->appel($this->outil($user, $conv), [
            'consigne'  => "Fais le point sur mes mails de la semaine.\nTrois puces au plus.",
            'nom'       => 'Point du lundi',
            'frequence' => 'chaque_semaine',
            'jours'     => ['lundi'],
        ]);
        $this->assertSame('en_attente_approbation', $premiere['statut'] ?? null, json_encode($premiere));
        $carte = DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL);
        $this->assertSame('08:00', $carte['args']['time'], 'Sans heure : 8 h.');
        $this->assertSame('Europe/Paris', $carte['args']['timezone'], 'Le fuseau du navigateur.');
        $this->assertSame('Consigne', $carte['preview']['apercu_titre']);

        // Le tour suivant rappelle la tâche en attente au modèle.
        $outil = $this->outil($user, $conv);
        $this->assertStringContainsString("<<<\nFais le point sur mes mails de la semaine.\nTrois puces au plus.\n>>>", $outil->guidance());

        // « Plutôt à 9 h, le mardi » : seulement ce qui change.
        $seconde = $this->appel($outil, ['remplace' => true, 'heure' => '09:00', 'jours' => [2]]);
        $this->assertTrue($seconde['proposition_remplacee'] ?? false, json_encode($seconde));
        $this->assertSame('cancelled', $this->statut((int)$premiere['action_id']), 'La carte d’avant est close.');
        $this->assertSame([['drive_superseded' => ['ids' => [(int)$premiere['action_id']]]]], array_values(array_filter(
            $this->emis,
            static fn(array $e): bool => isset($e['drive_superseded'])
        )));

        $carte = DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL);
        $this->assertSame((int)$seconde['action_id'], (int)$carte['id']);
        $this->assertSame('09:00', $carte['args']['time']);
        $this->assertSame(['2'], $carte['args']['days']);
        $this->assertSame("Fais le point sur mes mails de la semaine.\nTrois puces au plus.", $carte['args']['instruction']);
        $this->assertSame('Point du lundi', $carte['args']['name']);

        // La carte se rejoue telle quelle au clic « Approuver » (règles du formulaire).
        $data = ScheduledTask::normalizeInput(
            ScheduledTask::withoutAutoMode($carte['args'] + ['notify' => '1']),
            ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user))
        );
        $this->assertSame(['freq' => 'weekly', 'days' => [2], 'time' => '09:00'], $data['rule']);
        $this->assertTrue($data['notify']);

        // Une carte d'un autre compte n'est jamais la base d'une retouche.
        $this->assertNull(DrivePendingAction::latestPendingForTool($conv, 2, ScheduleTools::TOOL));
        $this->assertFalse(DrivePendingAction::hasPendingForTool($conv, 2, ScheduleTools::TOOL));
        $this->assertTrue(DrivePendingAction::hasPendingForTool($conv, 1, ScheduleTools::TOOL));
    }

    /** Le modèle renvoie tous les paramètres, les inchangés vides : rien n'est effacé. */
    public function testUneRetoucheAuxChampsVidesNEffaceRien(): void
    {
        $user = $this->user(1);
        $conv = $this->conversation(1);
        $this->appel($this->outil($user, $conv), [
            'consigne' => 'Revue de presse IA.', 'nom' => 'Revue IA', 'frequence' => 'chaque_jour', 'sources' => ['web'],
        ]);

        $res = $this->appel($this->outil($user, $conv), [
            'consigne' => '', 'nom' => '', 'frequence' => 'chaque_jour', 'jours' => [], 'jour_du_mois' => '',
            'heure' => '07:30', 'sources' => [], 'destination' => 'suite', 'actions_a_approuver' => false,
            'me_prevenir' => true, 'remplace' => true,
        ]);
        $this->assertTrue($res['proposition_remplacee'] ?? false, json_encode($res));
        $this->assertStringContainsString('Listes vides laissées telles quelles : sources', $res['note'] ?? '');
        $carte = DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL);
        $this->assertSame('07:30', $carte['args']['time']);
        $this->assertSame(['web'], $carte['args']['sources']);
        $this->assertSame('Revue de presse IA.', $carte['args']['instruction']);
        $this->assertSame('Revue IA', $carte['args']['name']);

        $res = $this->appel($this->outil($user, $conv), ['remplace' => true, 'vider' => ['sources']]);
        $this->assertSame([], DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL)['args']['sources'], json_encode($res));
    }

    public function testRetoucherSansTacheEnAttenteEstUneErreur(): void
    {
        $user = $this->user(1);
        $conv = $this->conversation(1);
        $res = $this->appel($this->outil($user, $conv), ['remplace' => true, 'heure' => '09:00']);
        $this->assertStringContainsString('Aucune tâche n\'attend dans cette conversation', $res['error'] ?? '');

        $res = $this->appel($this->outil($user, $conv), ['consigne' => 'Revue de presse']);
        $this->assertStringContainsString('`frequence` manquante', $res['error'] ?? '');
        $this->assertNull(DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL), 'Aucune carte déposée.');
    }

    public function testAssistantBasesEtOutilsSeChoisissentDansLePerimetre(): void
    {
        $user  = $this->user(1);
        $this->user(2);
        $group = $this->insertGroup('Commerciaux');
        $this->addMember(1, $group);
        $profil  = $this->insertProfile('Veille commerciale', true, 1);
        $mienne  = $this->base('Catalogue produits', 1);
        $this->base('Brouillons', 1, false);
        $autrui  = $this->base('Notes privées', 2);
        $image   = $this->outilImage('Images', $group);
        $conv    = $this->conversation(1);

        $res = $this->appel($this->outil($user, $conv), [
            'consigne'  => 'Prépare une veille sur nos produits.',
            'frequence' => 'chaque_jour',
            'assistant' => 'veille commerciale',
            'bases'     => ['catalogue produits'],
            'outils'    => ['Images'],
        ]);
        $this->assertSame('en_attente_approbation', $res['statut'] ?? null, json_encode($res));
        $carte = DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL);
        $this->assertSame((string)$profil, $carte['args']['profile_id']);
        $this->assertSame([(string)$mienne], $carte['args']['kb_ids']);
        $this->assertSame([(string)$image], $carte['args']['tool_ids']);
        $this->assertStringContainsString('Avec l’assistant « Veille commerciale »', implode(' | ', $carte['preview']['impacts']));

        foreach ([
            [['bases' => ['Brouillons']], 'inconnue ou indisponible'],
            [['bases' => [$autrui]], 'inconnue ou indisponible'],
            [['assistant' => 'Juriste'], 'Assistants disponibles : « Veille commerciale »'],
        ] as [$args, $attendu]) {
            $res = $this->appel($this->outil($user, $conv), $args + ['consigne' => 'X', 'frequence' => 'chaque_jour']);
            $this->assertStringContainsString($attendu, $res['error'] ?? '', json_encode($args));
        }
    }

    public function testUnModeleSansOutilsNePortePasUneTacheQuiEnUtilise(): void
    {
        $user  = $this->user(1);
        $group = $this->insertGroup('Tous');
        $this->addMember(1, $group);
        $image = $this->outilImage('Images', $group);
        $conv  = $this->conversation(1);
        $this->assertArrayHasKey('perplexity::sonar-pro', ScheduledTask::choicesFor($user)['llms']);

        $res = $this->appel($this->outil($user, $conv), [
            'consigne' => 'Illustre la météo du jour.', 'frequence' => 'chaque_jour',
            'modele' => 'perplexity::sonar-pro', 'outils' => [$image],
        ]);
        $this->assertStringContainsString('ne sait pas utiliser d\'outils', $res['error'] ?? '', json_encode($res));
        $this->assertNull(DrivePendingAction::latestPendingForTool($conv, 1, ScheduleTools::TOOL));

        // Recherche Internet seule : le modèle la porte lui-même, rien à redire.
        $res = $this->appel($this->outil($user, $conv), [
            'consigne' => 'Revue de presse IA.', 'frequence' => 'chaque_jour',
            'modele' => 'perplexity::sonar-pro', 'sources' => ['web'],
        ]);
        $this->assertSame('en_attente_approbation', $res['statut'] ?? null, json_encode($res));
    }
}
