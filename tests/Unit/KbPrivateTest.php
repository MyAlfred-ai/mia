<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bases « Uniquement pour moi » : formes pures (qui la voit, qui pose
 * l'option) et câblage — périmètres du modèle, enregistrement de la fiche,
 * suppression du compte, écrans d'ensemble, gabarits, script, docs. La
 * vérification sur MySQL réel vit dans tests/Functional/KbPrivatePipelineTest.
 */
final class KbPrivateTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
        self::assertIsString($src, $chemin . ' illisible');
        return $src;
    }

    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertNotFalse($debut, $nom . ' introuvable');
        $suite = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10);
        return substr($source, $debut, $suite ? $m[0][1] - $debut : null);
    }

    // =================== Formes pures ===================

    public function testCacheeATousSaufASonCreateurAdministrateursCompris(): void
    {
        $kb = ['id' => 7, 'name' => 'Journal', 'is_private' => 1, 'created_by' => 3];
        $this->assertTrue(KnowledgeBase::isPrivate($kb));
        $this->assertFalse(KnowledgeBase::hiddenFrom($kb, ['id' => 3, 'role' => 'user']));
        $this->assertTrue(KnowledgeBase::hiddenFrom($kb, ['id' => 4, 'role' => 'admin']));
        $this->assertTrue(KnowledgeBase::hiddenFrom($kb, null));
        $this->assertTrue(KnowledgeBase::hiddenFrom($kb, ['id' => 0]));
        // Sans créateur (compte effacé hors de l'écran) : cachée à tout le monde.
        $this->assertTrue(KnowledgeBase::hiddenFrom(['is_private' => 1, 'created_by' => null], ['id' => 1, 'role' => 'admin']));
    }

    public function testUneBaseOrdinaireNEstCacheeAPersonne(): void
    {
        $kb = ['is_private' => 0, 'created_by' => 3];
        $this->assertFalse(KnowledgeBase::isPrivate($kb));
        $this->assertFalse(KnowledgeBase::hiddenFrom($kb, ['id' => 9]));
        $this->assertFalse(KnowledgeBase::hiddenFrom([], null), 'Une ligne sans la colonne reste une base ordinaire.');
    }

    public function testSeulLeCreateurPoseLOption(): void
    {
        $kb = ['is_private' => 0, 'is_system' => 0, 'created_by' => 3];
        $this->assertTrue(KnowledgeBase::createdBy($kb, ['id' => 3]));
        $this->assertFalse(KnowledgeBase::createdBy($kb, ['id' => 4, 'role' => 'admin']));
        $this->assertFalse(KnowledgeBase::createdBy(['is_system' => 1, 'created_by' => 3], ['id' => 3]));
        $this->assertFalse(KnowledgeBase::createdBy(['created_by' => null], ['id' => 0]));
    }

    public function testUnEcranDEnsembleCompteLaBaseSansLaNommer(): void
    {
        $kb = ['id' => 7, 'name' => 'Journal intime', 'is_private' => 1, 'created_by' => 3];
        $this->assertSame('Journal intime', KnowledgeBase::displayNameFor($kb, ['id' => 3]));
        $masque = KnowledgeBase::displayNameFor($kb, ['id' => 4, 'role' => 'admin']);
        $this->assertStringNotContainsString('Journal', $masque);
        $this->assertStringContainsString('7', $masque);
        // Ligne du grand livre (kb_id, kb_name).
        $this->assertSame('Doc', KnowledgeBase::displayNameFor(['kb_id' => 2, 'kb_name' => 'Doc', 'is_private' => 0], null));
    }

    /** Une base locale cachée ne passe pas la porte, sans aucune lecture en base. */
    public function testLaPorteRetireLesBasesLocalesCachees(): void
    {
        $mine  = ['id' => 1, 'backend' => 'local', 'is_private' => 1, 'created_by' => 5];
        $other = ['id' => 2, 'backend' => 'local', 'is_private' => 0, 'created_by' => 5];
        $this->assertSame([$mine, $other], KnowledgeBase::gateForUser([$mine, $other], ['id' => 5]));
        $this->assertSame([$other], KnowledgeBase::gateForUser([$mine, $other], ['id' => 6, 'role' => 'admin']));
        $this->assertSame([$other], KnowledgeBase::gateForUser([$mine, $other], null));
    }

    public function testLeSchemaNeMontreNiPartageNiAdministrateurs(): void
    {
        $access = KbDiagram::access(['created_by' => 3], true, true, ['users' => [9 => 1], 'groups' => [2 => 0]], null, null, true);
        $this->assertTrue($access['private']);
        $this->assertSame([], $access['users']);
        $this->assertSame([], $access['groups']);
        $this->assertFalse(KbDiagram::access(['created_by' => 3], true, true, null, null, null)['private']);

        $js = self::lire('public/assets/js/kb-diagram.js');
        $branche = substr($js, (int)strpos($js, '} else if (access.private) {'), 400);
        $this->assertStringContainsString('L.only_me', $branche);
        $this->assertStringNotContainsString('L.admins', $branche, 'Une base privée n\'affiche pas la ligne « Administrateurs ».');
        $labels = KbDiagram::catalog([], [], [], 10, true)['labels'];
        $this->assertArrayHasKey('only_me', $labels);
        $this->assertArrayHasKey('only_me_note', $labels);
    }

    // =================== Modèle ===================

    public function testLaMigrationAjouteLaColonneSansToucherAuxBasesExistantes(): void
    {
        $sql = self::lire('database/migrations/095_kb_private.sql');
        $this->assertMatchesRegularExpression('/ADD COLUMN is_private TINYINT\(1\) NOT NULL DEFAULT 0/', $sql);
    }

    public function testChaqueLectureDePerimetrePasseParLaRegle(): void
    {
        $src = self::lire('src/Models/KnowledgeBase.php');
        foreach (['ownedBy', 'manageableBy', 'visibleBy', 'canFeed', 'gateForUser'] as $m) {
            $this->assertStringContainsString('self::hiddenFrom(', self::methode($src, $m), $m);
        }
        $forManager = self::methode($src, 'forManager');
        $this->assertStringContainsString('self::hiddenFrom(', $forManager, 'Branche « Gérer » de forManager.');
        $this->assertStringContainsString('kb.is_private = 0 AND EXISTS', $forManager, 'Un partage n\'ouvre pas une base privée.');
        $selectable = self::methode($src, 'selectableForUser');
        $this->assertStringContainsString('(kb.is_private = 0 OR kb.created_by = ?)', $selectable);
        $this->assertStringContainsString('kb.is_private = 0 AND EXISTS', $selectable);
        $this->assertStringContainsString('self::isPrivate($kb)', self::methode($src, 'setShares'));
        $this->assertStringContainsString('DELETE FROM knowledge_base_shares', self::methode($src, 'setPrivate'));
    }

    // =================== Contrôleur ===================

    public function testLaFicheNEnregistreLOptionQueDuCreateurEtAvantLePartage(): void
    {
        $admin  = self::lire('src/Controllers/AdminController.php');
        $update = self::methode($admin, 'updateKnowledgeBase');
        $apply  = self::methode($admin, 'applyKbVisibilityFromPost');
        // « Qui la voit » n'est lu que si la fiche le portait, et du seul propriétaire ;
        // « Privée » se pose ou se retire par le CRÉATEUR seulement.
        $this->assertStringContainsString("isset(\$_POST['visibility_form'])", $apply);
        $this->assertStringContainsString('KnowledgeBase::ownedBy($kbId, $user)', $apply);
        $this->assertStringContainsString('KnowledgeBase::createdBy($before, $user)', $apply);
        $this->assertLessThan(
            strpos($update, 'applyKbSharesFromPost'),
            strpos($update, 'applyKbVisibilityFromPost'),
            'La visibilité se pose AVANT le partage.'
        );
        $this->assertStringContainsString("'is_private'         => self::postedKbVisibility() === KnowledgeBase::VIS_PRIVATE,", self::methode($admin, 'createKnowledgeBase'));
        $this->assertStringContainsString("'is_private'  => self::postedKbVisibility() === KnowledgeBase::VIS_PRIVATE,", self::methode($admin, 'createMyBrainKnowledgeBase'));
        $set = self::methode(self::lire('src/Models/KnowledgeBase.php'), 'setVisibility');
        $this->assertStringContainsString('return $byCreator ? self::setPrivate($id, true) : 0;', $set);
    }

    public function testLeCompteSupprimeEmporteSesBasesPrivees(): void
    {
        $admin  = self::lire('src/Controllers/AdminController.php');
        $delete = self::methode($admin, 'deleteUser');
        $this->assertLessThan(
            strpos($delete, 'User::delete('),
            strpos($delete, 'KnowledgeBase::deletePrivateOf('),
            'Avant User::delete : created_by passerait à NULL.'
        );
        $impact = self::methode($admin, 'userDeletionImpact');
        $this->assertStringContainsString("'private_kb_count'", $impact);
        $this->assertStringContainsString('created_by = ? AND is_private = 0 ORDER BY name', $impact, 'Aucun nom de base privée dans l\'aperçu.');
        $this->assertStringContainsString('it.private_kb_count', self::lire('templates/admin/users.php'));
    }

    public function testLesEcransDEnsembleNeLesNommentPas(): void
    {
        $this->assertStringContainsString('kb.is_private = 0', self::lire('src/Helpers/AdminAttention.php'));
        $this->assertStringContainsString('is_private = 0', self::methode(self::lire('src/Billing/UsageInsights.php'), 'overview'));
        $this->assertStringContainsString('KnowledgeBase::displayNameFor(', self::lire('src/RAG/QdrantHealth.php'));
        $this->assertStringContainsString('KnowledgeBase::displayNameFor($r, $viewer)', self::methode(self::lire('src/Models/UsageLog.php'), 'byKnowledgeBase'));
        $this->assertStringContainsString('UsageLog::byKnowledgeBase($from, $to, $userId, Session::currentUser())', self::lire('src/Controllers/AdminController.php'));
        $this->assertStringContainsString('!KnowledgeBase::isPrivate($kb)', self::lire('src/Helpers/Diagnostics.php'));
    }

    // =================== Gabarits et script ===================

    public function testLInterrupteurNExisteQuePourLeCreateur(): void
    {
        // Piste D : « Privée » est l'un des trois choix ; il ne se coche que par le créateur.
        $tpl = self::lire('templates/admin/knowledge-bases/edit.php');
        $this->assertStringContainsString("\$vOff = \$vKey === KnowledgeBase::VIS_PRIVATE && empty(\$isCreator);", $tpl);
        $this->assertStringContainsString("<?= \$vOff ? ' disabled' : '' ?>", $tpl);
        $this->assertStringContainsString('name="visibility_form" value="1"', $tpl);
        $this->assertStringContainsString('id="kbShareBody"', $tpl);
        $this->assertStringContainsString('id="kbPrivateAssistantsHint"', $tpl);
        $this->assertStringContainsString("'private'     => \$isPrivate", $tpl);

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.kb-share-body[hidden]', $css, 'Le display:flex l\'emporterait sur l\'attribut hidden.');
    }

    public function testLeScriptCacheLePartageEtConfirmeAvantDeLeRetirer(): void
    {
        $js = self::lire('public/assets/js/kb-form.js');
        $this->assertStringContainsString('function syncPrivate()', $js);
        $this->assertStringContainsString("t('Réserver cette base à vous seul ?", $js);
        // Privée ou publique : les partages tombent, la note de l'assistant suit.
        $this->assertStringContainsString("if (next !== 'common') {", $js);
        $this->assertStringContainsString("if (privateNote) privateNote.hidden = access !== 'private';", $js);
        $this->assertStringContainsString('if (shareBody) shareBody.hidden = !common;', $js);
    }

    public function testLaListeEtLeChatLaSignalent(): void
    {
        // Piste D : un cadenas sur la ligne, la phrase en infobulle.
        $this->assertStringContainsString("'lock'", self::lire('src/Sync/KbPages.php'));
        $this->assertStringContainsString('KbPages::visibilityIcon($r[\'vis\'])', self::lire('templates/admin/knowledge-bases.php'));
        $this->assertStringContainsString('kb-picker-badge-private', self::lire('templates/chat/index.php'));
    }

    // =================== Documentation ===================

    public function testLaDocumentationEnParle(): void
    {
        foreach (['docs/GUIDE-CREATEUR.md', 'docs/GUIDE-ADMIN.md', 'docs/NOUVEAUTES.md', 'CLAUDE.md'] as $doc) {
            // Piste D : la réserve s'appelle « Privée » (d'abord « Uniquement pour moi »).
            $this->assertStringContainsString('Privée', self::lire($doc), $doc);
        }
        $page = 'docs/user/fonctionnalites/bases-de-connaissances/reserver-une-base.md';
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . $page);
        $this->assertStringContainsString('reserver-une-base.md', self::lire('docs/user/fonctionnalites/bases-de-connaissances/index.md'));
    }
}
