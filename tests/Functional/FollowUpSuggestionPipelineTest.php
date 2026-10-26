<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Suggestion de suite, sur base réelle : la VRAIE route (`suggestFollowUp`),
 * le vrai adaptateur OpenAI rejoué depuis un fichier local (le catalogue
 * redirige l'adresse de l'API), le vrai grand livre.
 *
 * Ce qui compte : une suggestion par réponse — calculée une fois, gardée avec
 * elle, relue sans payer —, seulement sous la dernière réponse, jamais pour
 * une réponse ancienne ni quand l'administrateur l'a coupée.
 */
final class FollowUpSuggestionPipelineTest extends DatabaseTestCase
{
    private const UTILISATEUR = 11;

    private ?string $dossier = null;
    private mixed $reglageAvant = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Auth/AuthMiddleware.php';

        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        $this->reglageAvant = Settings::get(FollowUpSuggestion::SETTING_ENABLED);
        Settings::set(FollowUpSuggestion::SETTING_ENABLED, '1');
        // o3 : le modèle OpenAI routé en Chat Completions (le format rejoué ici).
        LlmCatalog::setToday('2026-10-14');

        $this->insertUser(self::UTILISATEUR);
        $this->insertUser(self::UTILISATEUR + 1);
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);

        $this->dossier = sys_get_temp_dir() . '/mia-suggest-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        Session::set('user', User::findById(self::UTILISATEUR));
        $_POST = [];
    }

    protected function tearDown(): void
    {
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        $_POST = [];
        Session::remove('user');
        if ($this->dossier !== null) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::set(FollowUpSuggestion::SETTING_ENABLED, $this->reglageAvant ?? '1');
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------

    public function testLaSuiteEstPrepareeUneFoisGardeeEtRelueSansPayer(): void
    {
        [$conv, $reponse] = $this->conversation();
        // Un plan affiché pendant la réponse : il survit à la suggestion.
        Message::setAgent($reponse, ['plan' => ['etapes' => [['titre' => 'Lire', 'statut' => 'fait']]], 'suite' => null]);
        $this->modele('« Oui, prépare le brouillon pour Marie »');

        $r = $this->demander($conv, $reponse);
        $this->assertSame('Oui, prépare le brouillon pour Marie', $r['suggestion']);
        $agent = Message::agentOf(Message::findById($reponse));
        $this->assertSame('Oui, prépare le brouillon pour Marie', $agent['suggestion']);
        $this->assertSame('Lire', $agent['plan']['etapes'][0]['titre'] ?? null, 'Le plan est intact.');
        $this->assertSame(1, $this->lignesFacturees(), 'Comptée au grand livre.');

        // Deuxième demande (autre onglet, rechargement) : relue, pas recalculée.
        $this->modele('Autre chose');
        $this->assertSame('Oui, prépare le brouillon pour Marie', $this->demander($conv, $reponse)['suggestion']);
        $this->assertSame(1, $this->lignesFacturees());
        $this->assertSame('Oui, prépare le brouillon pour Marie', FollowUpSuggestion::storedOf(Message::findById($reponse)));
    }

    public function testAucuneSuiteEvidenteEstGardeeAussi(): void
    {
        [$conv, $reponse] = $this->conversation();
        $this->modele('AUCUNE');
        $this->assertSame('', $this->demander($conv, $reponse)['suggestion']);
        $this->assertSame('', FollowUpSuggestion::storedOf(Message::findById($reponse)), 'Jamais redemandée.');
    }

    public function testRienSousUneReponseDejaSuivieAncienneOuQuandCEstCoupe(): void
    {
        [$conv, $reponse] = $this->conversation();
        $this->modele('Compare avec le devis 2025');

        Settings::set(FollowUpSuggestion::SETTING_ENABLED, '0');
        $this->assertSame('disabled', $this->demander($conv, $reponse)['reason']);
        Settings::set(FollowUpSuggestion::SETTING_ENABLED, '1');

        // Toute la conversation vieillie (l'ordre des messages ne bouge pas).
        $this->db()->prepare('UPDATE messages SET created_at = NOW() - INTERVAL 13 HOUR WHERE conversation_id = ?')->execute([$conv]);
        $this->assertSame('old', $this->demander($conv, $reponse)['reason']);
        $this->db()->prepare('UPDATE messages SET created_at = NOW() WHERE conversation_id = ?')->execute([$conv]);

        Message::create($conv, 'user', 'Merci, et autre chose');
        $r = $this->demander($conv, $reponse);
        $this->assertNull($r['suggestion']);
        $this->assertSame('not_eligible', $r['reason']);
        $this->assertSame(0, $this->lignesFacturees(), 'Aucun appel payé.');

        // Message d'issue d'une carte (écrit par l'application, sans modèle) : pas de suite.
        $issue = Message::create($conv, 'assistant', '✅ Brouillon Gmail créé');
        $this->assertSame('not_eligible', $this->demander($conv, (int)$issue['id'])['reason']);
    }

    public function testLaConversationDUnAutreEstRefusee(): void
    {
        [$conv, $reponse] = $this->conversation();
        Session::set('user', User::findById(self::UTILISATEUR + 1));
        $this->modele('Oui');
        $r = $this->demander($conv, $reponse);
        $this->assertSame('Accès refusé', $r['error'] ?? null);
        $this->assertNull(FollowUpSuggestion::storedOf(Message::findById($reponse)));
    }

    public function testLaLectureNeTireJamaisLeContenuDesPiecesJointes(): void
    {
        [$conv] = $this->conversation();
        $base64 = base64_encode(random_bytes(30000));
        Message::create($conv, 'user', 'Voici le devis', [['name' => 'devis.pdf', 'type' => 'application/pdf', 'data' => $base64]]);
        Message::create($conv, 'assistant', 'Le devis est complet. Voulez-vous une comparaison ?', [], 'openai', 'o3');

        $rows = Message::recentForSuggestion($conv, 6);
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($rows, 'role'), 'Du plus ancien au plus récent.');
        $this->assertStringNotContainsString($base64, json_encode($rows));
        $this->assertSame(['devis.pdf'], json_decode((string)$rows[2]['file_names'], true));
        $this->assertSame('openai', $rows[3]['llm_provider']);
        $this->assertLessThan(5, (int)$rows[3]['age_sec']);
        $this->assertStringContainsString('[fichiers joints : devis.pdf]', FollowUpSuggestion::transcript($rows));

        // Un état « agent » illisible ne bloque pas l'enregistrement.
        $id = (int)$rows[3]['id'];
        $this->db()->prepare('UPDATE messages SET agent_json = ? WHERE id = ?')->execute(['pas du JSON', $id]);
        Message::setSuggestion($id, 'Compare les deux devis');
        $this->assertSame('Compare les deux devis', FollowUpSuggestion::storedOf(Message::findById($id)));
    }

    // ------------------------------------------------------------------

    /** @return array{0:int, 1:int} conversation, réponse de l'assistant */
    private function conversation(): array
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::UTILISATEUR, 'Devis Durand', 'openai::o3']);
        $conv = (int)$this->db()->lastInsertId();
        Message::create($conv, 'user', 'Résume le mail de Marie sur le devis Durand');
        $reponse = Message::create($conv, 'assistant', 'Marie attend le devis signé avant vendredi. Voulez-vous que je prépare le brouillon de réponse ?', [], 'openai', 'o3');

        return [$conv, (int)$reponse['id']];
    }

    /** @return array<string, mixed> réponse JSON de la route */
    private function demander(int $conv, int $message): array
    {
        $_POST = ['message_id' => (string)$message];
        ob_start();
        try {
            (new ChatController())->suggestFollowUp((string)$conv);
        } finally {
            $sortie = (string)ob_get_clean();
        }
        $json = json_decode($sortie, true);
        $this->assertIsArray($json, 'Réponse non JSON : ' . $sortie);

        return $json;
    }

    /** Le modèle répond ce texte (flux Chat Completions rejoué depuis un fichier). */
    private function modele(string $texte): void
    {
        $fichier = $this->dossier . '/openai-' . bin2hex(random_bytes(3)) . '.sse';
        $sse = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $texte], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 320, 'completion_tokens' => 9]]) . "\n\n"
            . "data: [DONE]\n\n";
        file_put_contents($fichier, $sse);

        $cache = new ReflectionProperty(LlmCatalog::class, 'cache');
        $catalogue = $cache->getValue() ?? require ROOT . '/config/llm_catalog.php';
        $catalogue['openai']['endpoints']['chat_completions'] = $fichier;
        $cache->setValue(null, $catalogue);
    }

    private function lignesFacturees(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM usage_logs WHERE source = ? AND user_id = ?');
        $stmt->execute([UsageSource::FOLLOW_UP, self::UTILISATEUR]);
        return (int)$stmt->fetchColumn();
    }
}
