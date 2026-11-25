<?php

declare(strict_types=1);

/**
 * Page d'un assistant, avec la vraie base : ce que disent ses chiffres
 * (réponses, personnes, conversations, crédits des 30 derniers jours,
 * dernière réponse) et les groupes qui l'autorisent.
 *
 * Câblage et formes pures : tests/Unit/AssistantPageTest.php.
 */
final class AssistantPagePipelineTest extends DatabaseTestCase
{
    private function conversation(int $userId, int $profileId): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, profile_id, title) VALUES (?, ?, ?)')
            ->execute([$userId, $profileId, 'Conversation']);
        return (int)$this->db()->lastInsertId();
    }

    private function message(int $convId, string $role, string $quand): void
    {
        $this->db()->prepare("INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, 'x', NOW() - INTERVAL $quand)")
            ->execute([$convId, $role]);
    }

    public function testLesChiffresSontCeuxDesTrenteDerniersJours(): void
    {
        $alice = $this->insertUser(1, 'creator');
        $bob   = $this->insertUser(2);
        $asst  = $this->insertProfile('Juriste', false, $alice);
        $autre = $this->insertProfile('Autre', false, $alice);

        $vierge = Profile::usageSummary($asst);
        $this->assertSame(['replies' => 0, 'people' => 0, 'conversations' => 0, 'credits' => 0, 'last_age' => null], $vierge, 'Jamais servi.');

        $c1 = $this->conversation($alice, $asst);
        $this->message($c1, 'user', '2 DAY');
        $this->message($c1, 'assistant', '2 DAY');
        $this->message($c1, 'assistant', '1 HOUR');
        $c2 = $this->conversation($bob, $asst);
        $this->message($c2, 'assistant', '3 DAY');
        $c3 = $this->conversation($bob, $asst);
        $this->message($c3, 'assistant', '45 DAY'); // hors fenêtre
        $c4 = $this->conversation($bob, $autre);
        $this->message($c4, 'assistant', '1 DAY');  // autre assistant

        $this->db()->prepare("INSERT INTO usage_logs (user_id, profile_id, conversation_id, llm_provider, model, credits_used, created_at) VALUES (?, ?, ?, 'openai', 'gpt', ?, NOW() - INTERVAL 1 DAY)")
            ->execute([$alice, $asst, $c1, 40]);
        $this->db()->prepare("INSERT INTO usage_logs (user_id, profile_id, conversation_id, llm_provider, model, credits_used, created_at) VALUES (?, ?, ?, 'openai', 'gpt', ?, NOW() - INTERVAL 60 DAY)")
            ->execute([$alice, $asst, $c1, 999]);
        $outil = ChatTool::save(['name' => 'Illustrations', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2', 'is_active' => 1]);
        $this->db()->prepare("INSERT INTO tool_usage_logs (tool_id, tool_type, user_id, profile_id, conversation_id, credits_used, created_at) VALUES (?, 'image', ?, ?, ?, ?, NOW() - INTERVAL 2 DAY)")
            ->execute([$outil, $bob, $asst, $c2, 25]);

        $u = Profile::usageSummary($asst);
        $this->assertSame(3, $u['replies'], 'Les réponses de cet assistant, sur 30 jours.');
        $this->assertSame(2, $u['people']);
        $this->assertSame(2, $u['conversations']);
        $this->assertSame(65, $u['credits'], 'Réponses et créations, hors fenêtre exclue.');
        $this->assertNotNull($u['last_age']);
        $this->assertGreaterThanOrEqual(3500, $u['last_age']);
        $this->assertLessThan(3700, $u['last_age'], 'Dernière réponse : il y a une heure.');
    }

    public function testLesGroupesQuiLAutorisent(): void
    {
        $asst = $this->insertProfile('Commercial', false, $this->insertUser(1, 'admin'));
        $this->assertSame([], Profile::authorizedGroups($asst));

        $ventes  = $this->insertGroup('Ventes');
        $anciens = $this->insertGroup('Anciens', null, false);
        $achats  = $this->insertGroup('Achats');
        $this->linkGroupProfile($ventes, $asst);
        $this->linkGroupProfile($anciens, $asst);
        $this->linkGroupProfile($achats, $asst);

        $groupes = Profile::authorizedGroups($asst);
        $this->assertSame(['Achats', 'Ventes', 'Anciens'], array_column($groupes, 'name'), 'Actifs d’abord, puis par nom.');
        $this->assertSame([true, true, false], array_column($groupes, 'active'));
    }
}
