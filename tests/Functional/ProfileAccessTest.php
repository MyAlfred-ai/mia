<?php

declare(strict_types=1);

/**
 * Visibilité des assistants — le cœur du contrôle d'accès (régression du bug
 * « tout le monde voit tous les assistants partagés »).
 *
 * Règles vérifiées (Profile::accessibleByUser) :
 *  - un utilisateur ne voit QUE les assistants d'organisation cochés dans l'un
 *    de ses groupes actifs ;
 *  - sans groupe → aucun assistant d'organisation ;
 *  - un assistant d'un autre groupe n'est PAS visible (cloisonnement) ;
 *  - un admin voit tous les assistants d'organisation actifs (jamais les inactifs
 *    ni les personnels d'autrui) ;
 *  - un assistant personnel n'est visible que par son créateur, même coché dans
 *    un groupe.
 */
final class ProfileAccessTest extends DatabaseTestCase
{
    public function testUserSeesOnlyProfilesAllowedInTheirGroups(): void
    {
        $orgA = $this->insertProfile('OrgA', isPersonal: false);
        $orgB = $this->insertProfile('OrgB', isPersonal: false);
        $g1 = $this->insertGroup('G1');
        $g2 = $this->insertGroup('G2');
        $this->linkGroupProfile($g1, $orgA);
        $this->linkGroupProfile($g2, $orgB);

        $visible = $this->ids(Profile::accessibleByUser(1, 'user', [$g1], true));

        $this->assertContains($orgA, $visible, 'doit voir l\'assistant de son groupe');
        $this->assertNotContains($orgB, $visible, 'NE doit PAS voir l\'assistant d\'un autre groupe');
    }

    public function testUserWithoutGroupSeesNoOrgProfile(): void
    {
        $this->insertProfile('OrgA', isPersonal: false);

        $visible = Profile::accessibleByUser(1, 'user', [], false);

        $this->assertCount(0, $visible, 'sans groupe → aucun assistant d\'organisation');
    }

    public function testAdminSeesAllActiveOrgProfilesButNotInactiveNorOthersPersonal(): void
    {
        $this->insertUser(2);
        $orgA = $this->insertProfile('OrgA', isPersonal: false);
        $orgB = $this->insertProfile('OrgB', isPersonal: false);
        $inactive = $this->insertProfile('Inactif', isPersonal: false, active: false);
        $perso = $this->insertProfile('Perso', isPersonal: true, createdBy: 2);

        $visible = $this->ids(Profile::accessibleByUser(99, 'admin', [], false));

        $this->assertContains($orgA, $visible);
        $this->assertContains($orgB, $visible);
        $this->assertNotContains($inactive, $visible, 'admin ne voit pas les assistants inactifs');
        $this->assertNotContains($perso, $visible, 'admin ne voit pas le personnel d\'un autre');
    }

    public function testPersonalProfileVisibleOnlyToCreatorEvenIfGroupLinked(): void
    {
        $this->insertUser(2);
        $perso = $this->insertProfile('Perso', isPersonal: true, createdBy: 2);
        $group = $this->insertGroup('G1');
        // Lien « accidentel » : ne doit RIEN changer (branche org filtre is_personal=0).
        $this->linkGroupProfile($group, $perso);

        $creatorView = $this->ids(Profile::accessibleByUser(2, 'creator', [], false));
        $otherView   = $this->ids(Profile::accessibleByUser(3, 'user', [$group], true));

        $this->assertContains($perso, $creatorView, 'le créateur voit son assistant personnel');
        $this->assertNotContains($perso, $otherView, 'un autre membre du groupe ne le voit pas');
    }

    public function testProfileAllowedInMultipleGroupsIsNotDuplicated(): void
    {
        $org = $this->insertProfile('OrgShared', isPersonal: false);
        $g1 = $this->insertGroup('G1');
        $g2 = $this->insertGroup('G2');
        $this->linkGroupProfile($g1, $org);
        $this->linkGroupProfile($g2, $org);

        $visible = $this->ids(Profile::accessibleByUser(1, 'user', [$g1, $g2], true));

        $this->assertSame([$org], $visible, 'dédupliqué par id malgré 2 groupes');
    }
}
