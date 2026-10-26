<?php

declare(strict_types=1);

/**
 * Groupes utilisateurs : quota effectif (max des groupes actifs), périmètre
 * d'appartenance (groupes actifs), et liste blanche LLM (union, null = aucune
 * restriction).
 */
final class UserGroupAccessTest extends DatabaseTestCase
{
    // user_group_members.user_id a une FK vers users(id) → l'utilisateur testé
    // doit exister. On le (re)crée après le truncate de chaque test.
    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(7);
    }

    public function testEffectiveQuotaTakesMaxAcrossActiveGroups(): void
    {
        $g1 = $this->insertGroup('Petit', creditQuotaK: 100);
        $g2 = $this->insertGroup('Grand', creditQuotaK: 300);
        $this->addMember(7, $g1);
        $this->addMember(7, $g2);

        $this->assertSame(300, UserGroup::effectiveQuotaForUserId(7));
    }

    public function testEffectiveQuotaIgnoresInactiveGroups(): void
    {
        $active   = $this->insertGroup('Actif', creditQuotaK: 100, active: true);
        $inactive = $this->insertGroup('Inactif', creditQuotaK: 999, active: false);
        $this->addMember(7, $active);
        $this->addMember(7, $inactive);

        $this->assertSame(100, UserGroup::effectiveQuotaForUserId(7), 'le groupe inactif ne compte pas');
    }

    public function testEffectiveQuotaNullWhenNoQuotaDefined(): void
    {
        $g = $this->insertGroup('SansQuota', creditQuotaK: null);
        $this->addMember(7, $g);

        $this->assertNull(UserGroup::effectiveQuotaForUserId(7));
    }

    public function testMembershipScopeReturnsOnlyActiveGroups(): void
    {
        $active   = $this->insertGroup('Actif', active: true);
        $inactive = $this->insertGroup('Inactif', active: false);
        $this->addMember(7, $active);
        $this->addMember(7, $inactive);

        $scope = UserGroup::membershipScopeForUser(7);

        $this->assertTrue($scope['has_assignments']);
        $this->assertSame([$active], $scope['active_group_ids']);
    }

    public function testAllowedLlmKeysUnionOfActiveGroups(): void
    {
        $g1 = $this->insertGroup('G1');
        $g2 = $this->insertGroup('G2');
        $this->addGroupLlm($g1, 'openai::gpt-5.5');
        $this->addGroupLlm($g1, 'anthropic::claude-opus-4-8');
        // g2 ne définit aucune liste → neutre dans l'union.

        $allowed = UserGroup::allowedLlmKeysForUser([$g1, $g2]);

        $this->assertIsArray($allowed);
        sort($allowed);
        $this->assertSame(['anthropic::claude-opus-4-8', 'openai::gpt-5.5'], $allowed);
    }

    public function testAllowedLlmKeysNullWhenNoGroupDefinesList(): void
    {
        $g = $this->insertGroup('G1');
        // Aucune clé LLM définie → null = aucune restriction (tous les LLMs actifs).
        $this->assertNull(UserGroup::allowedLlmKeysForUser([$g]));
    }
}
