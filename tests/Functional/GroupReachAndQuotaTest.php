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

    /**
     * Le cas constaté chez un client : quota individuel ancien (10) et groupe « Power User »
     * à 50 — le plus généreux s'applique, au moment de consommer comme dans
     * la liste des comptes. Un groupe inactif, même plus généreux, ne compte pas.
     */
    public function testLeQuotaDUnGroupePlusGenereuxPasseDevantLIndividuel(): void
    {
        $this->db()->prepare('UPDATE users SET credit_quota_k = 10, token_quota = 10 WHERE id = 7')->execute();
        $this->addMember(7, $this->insertGroup('Power User', creditQuotaK: 50));
        $this->addMember(7, $this->insertGroup('Ancien', creditQuotaK: 500, active: false));

        $user = User::findById(7);
        $this->assertSame(50, User::effectiveCreditQuotaK($user));
        $this->assertSame(50_000, CreditQuota::status($user)['quota_max']);
        $this->assertSame(
            50,
            User::effectiveCreditQuotaKFrom($user, UserGroup::effectiveQuotasByUserIds([7])[7] ?? null),
            'La liste des comptes dit la même chose.'
        );

        // Un individuel plus généreux que les groupes reste appliqué.
        $this->db()->prepare('UPDATE users SET credit_quota_k = 80, token_quota = 80 WHERE id = 7')->execute();
        $this->assertSame(80_000, CreditQuota::status(User::findById(7))['quota_max']);
    }

    /** Outils et assistants : chaque groupe actif AJOUTE les siens, aucun n'en retire. */
    public function testLesAccesDesGroupesSAdditionnent(): void
    {
        $image  = $this->insertTool('Visuels');
        $autre  = $this->insertTool('Croquis');
        $a      = $this->insertGroup('Marketing');
        $b      = $this->insertGroup('Power User');
        $this->addMember(7, $a);
        $this->addMember(7, $b);
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?), (?, ?)')
            ->execute([$a, $image, $b, $autre]);
        $pa = $this->insertProfile('Rédacteur', false);
        $pb = $this->insertProfile('Juriste', false);
        $this->linkGroupProfile($a, $pa);
        $this->linkGroupProfile($b, $pb);

        $scope = UserGroup::membershipScopeForUser(7);
        $outils = array_map('intval', array_column(ChatTool::activeByUser('image', 'user', $scope['active_group_ids'], true), 'id'));
        sort($outils);
        $this->assertSame([$image, $autre], $outils);

        $assistants = array_map('intval', array_column(
            Profile::accessibleByUser(7, 'user', $scope['active_group_ids'], true),
            'id'
        ));
        $this->assertContains($pa, $assistants);
        $this->assertContains($pb, $assistants);
    }

    private function insertTool(string $name): int
    {
        $this->db()->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$name, 'image', 'openai', 'gpt-image-2']);
        return (int)$this->db()->lastInsertId();
    }
}
