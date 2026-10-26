<?php

declare(strict_types=1);

/**
 * Ce que les écrans d'administration doivent afficher, vérifié contre la base :
 * la portée réelle d'un outil (groupes ACTIFS qui l'autorisent) et la
 * neutralité d'un groupe sans quota.
 */
final class GroupReachAndQuotaTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(7);
    }

    public function testUnOutilNeufNEstAutoriseDansAucunGroupe(): void
    {
        $outil = $this->insertTool('Visuels');
        $this->insertGroup('Marketing');

        $this->assertArrayNotHasKey($outil, ChatTool::activeGroupCounts());
        // Et un utilisateur de ce groupe ne le voit pas : c'est ce que le
        // libellé « utilisable par tous » cachait.
        $this->assertSame([], ChatTool::activeByUser('image', 'user', [$this->insertGroup('Support')], true));
    }

    public function testLaPorteeNeCompteQueLesGroupesActifs(): void
    {
        $outil   = $this->insertTool('Clips');
        $actif1  = $this->insertGroup('Marketing');
        $actif2  = $this->insertGroup('Direction');
        $inactif = $this->insertGroup('Ancien', active: false);
        foreach ([$actif1, $actif2, $inactif] as $g) {
            $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$g, $outil]);
        }

        $this->assertSame(2, ChatTool::activeGroupCounts()[$outil] ?? 0);
    }

    public function testUnGroupeSansQuotaNOuvrePasLePlafondDUnAutre(): void
    {
        $plafonne = $this->insertGroup('Par défaut', creditQuotaK: 5);
        $sansQuota = $this->insertGroup('Direction', creditQuotaK: null);
        $this->addMember(7, $plafonne);
        $this->addMember(7, $sansQuota);

        // « Vide = illimité » aurait laissé attendre null (illimité) : c'est 5.
        $this->assertSame(5, UserGroup::effectiveQuotaForUserId(7));
    }

    private function insertTool(string $name): int
    {
        $this->db()->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$name, 'image', 'openai', 'gpt-image-2']);
        return (int)$this->db()->lastInsertId();
    }
}
