<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Le quota mensuel appliqué à une personne est le PLUS GÉNÉREUX de son quota
 * individuel et de ceux de ses groupes actifs — jamais le plus restrictif.
 *
 * Le défaut corrigé (constaté chez un client) : un quota individuel l'emportait
 * toujours, même plus bas. Le groupe « Power User » passé à 50 000 crédits
 * restait sans effet pour ses membres qui avaient un ancien quota individuel ;
 * l'administrateur devait le relever à la main, compte par compte.
 */
final class QuotaBestOfTest extends TestCase
{
    private static function source(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    public function testLeQuotaLePlusGenereuxLEmporte(): void
    {
        $this->assertSame(50, User::bestQuotaK(10, 50), 'Le groupe plus généreux que l’individuel s’applique.');
        $this->assertSame(80, User::bestQuotaK(80, 50), 'L’individuel plus généreux que les groupes s’applique.');
        $this->assertSame(50, User::bestQuotaK(0, 50), 'Un individuel à 0 ne bloque plus un membre d’un groupe doté.');
        $this->assertSame(10, User::bestQuotaK(10, null), 'Sans quota de groupe : l’individuel.');
        $this->assertSame(50, User::bestQuotaK(null, 50), 'Sans individuel : les groupes.');
        $this->assertNull(User::bestQuotaK(null, null), 'Aucun quota nulle part : illimité.');
        $this->assertSame(0, User::bestQuotaK(0, null), 'Un 0 seul reste un blocage.');
    }

    public function testLesDeuxCalculsSuiventLaMemeRegle(): void
    {
        $user = ['credit_quota_k' => 10, 'token_quota' => 10];
        $this->assertSame(50, User::effectiveCreditQuotaKFrom($user, 50));
        $this->assertSame(50, User::effectiveCreditQuotaK($user + ['effective_group_credit_quota_k' => 50]));

        // Ancienne colonne seule (comptes d'avant la migration 018).
        $legacy = ['credit_quota_k' => null, 'token_quota' => 30];
        $this->assertSame(30, User::individualQuotaK($legacy));
        $this->assertSame(50, User::effectiveCreditQuotaKFrom($legacy, 50));
        $this->assertSame(30, User::effectiveCreditQuotaKFrom($legacy, 20));
    }

    public function testLOrigineAfficheeEstCelleDuQuotaApplique(): void
    {
        $this->assertSame('groups', User::quotaOrigin(10, 50));
        $this->assertSame('user', User::quotaOrigin(80, 50));
        $this->assertSame('groups', User::quotaOrigin(50, 50), 'À égalité, retirer l’individuel ne changerait rien.');
        $this->assertSame('user', User::quotaOrigin(10, null));
        $this->assertSame('groups', User::quotaOrigin(null, 50));
        $this->assertSame('none', User::quotaOrigin(null, null));
    }

    public function testLaListeDesComptesAppliqueLaMemeRegleQueLaConsommation(): void
    {
        $tpl = self::source('templates/admin/users.php');
        $this->assertStringContainsString('User::effectiveCreditQuotaKFrom($u, $groupQuota)', $tpl);
        $this->assertStringContainsString('User::quotaOrigin($userQuota, $groupQuota)', $tpl);
        $this->assertStringNotContainsString('$userQuota !== null ? $userQuota : $groupQuota', $tpl, 'L’individuel ne prime plus.');
        $this->assertStringNotContainsString('prime sur les groupes', $tpl);
        // L'aide sous le champ suit la saisie : la règle se lit avant d'enregistrer.
        $this->assertStringContainsString("getElementById('euQuota')?.addEventListener('input'", $tpl);

        $groups = self::source('templates/admin/user-groups.php');
        $this->assertStringNotContainsString('prend toujours le dessus', $groups);
        $this->assertStringContainsString('jamais le plus restrictif', $groups);
    }

    public function testLaBarriereUniqueSAppuieSurLaRegle(): void
    {
        $this->assertStringContainsString('User::effectiveCreditQuotaK($user)', self::source('src/Helpers/CreditQuota.php'));
        $src = self::source('src/Models/User.php');
        $this->assertSame(1, substr_count($src, 'max($defined)'), 'Une seule formule du « plus généreux ».');
    }

    public function testLaDocumentationDitLaNouvelleRegle(): void
    {
        foreach ([
            'docs/user/admin/credits/fixer-les-quotas.md',
            'docs/user/admin/utilisateurs/index.md',
            'docs/user/admin/utilisateurs/organiser-les-groupes.md',
            'docs/user/admin/utilisateurs/gerer-les-utilisateurs.md',
            'docs/user/admin/credits/index.md',
            'docs/GUIDE-ADMIN.md',
        ] as $doc) {
            $texte = self::source($doc);
            $this->assertStringNotContainsString('qui prime', $texte, $doc);
            $this->assertStringNotContainsString('prime sur', $texte, $doc);
            $this->assertStringNotContainsString('même plus bas', $texte, $doc);
        }
        $this->assertStringContainsString('le plus généreux', self::source('docs/user/admin/credits/fixer-les-quotas.md'));
    }
}
