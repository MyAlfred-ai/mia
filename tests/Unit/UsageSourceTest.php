<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Taxonomie des opérations IA (UsageSource) : chaque source est rangée,
 * libellée, dotée d'une unité et d'une règle de quota ; les regroupements
 * (catégories → familles) sont complets ; le CASE SQL couvre tout.
 */
final class UsageSourceTest extends TestCase
{
    public function testChaqueSourceEstComplete(): void
    {
        $categories = UsageSource::categories();
        $units = [UsageSource::UNIT_TOKENS, UsageSource::UNIT_SEARCHES, UsageSource::UNIT_MINUTES];
        $this->assertNotEmpty(UsageSource::all());
        foreach (UsageSource::all() as $source) {
            $this->assertTrue(UsageSource::isKnown($source));
            $this->assertNotSame('', UsageSource::label($source), "libellé manquant : $source");
            $this->assertContains(UsageSource::category($source), $categories, "catégorie inconnue : $source");
            $this->assertContains(UsageSource::unit($source), $units, "unité inconnue : $source");
            // Jamais plus long que la colonne `source` VARCHAR(40) de la migration 067.
            $this->assertLessThanOrEqual(40, strlen($source));
        }
    }

    public function testLesSourcesAttenduesExistent(): void
    {
        $attendues = [
            'chat', 'regenerate', 'title', 'memory', 'task_rules', 'image_prompt', 'mail_assist', 'kb_query',
            'context_summary', 'tool_condense',
            'web_search', 'html_tool', 'map_tool', 'presentation_tool', 'transcribe', 'speech',
            'kb_chunking', 'kb_vision', 'kb_embedding', 'prompt_assist', 'tool_test', 'price_refresh',
        ];
        foreach ($attendues as $source) {
            $this->assertTrue(UsageSource::isKnown($source), "source absente : $source");
        }
        // Les sources historiques de la colonne (avant la migration 067) sont toujours connues.
        foreach (['chat', 'regenerate', 'html_tool', 'map_tool', 'presentation_tool', 'prompt_assist', 'transcribe'] as $legacy) {
            $this->assertTrue(UsageSource::isKnown($legacy));
        }
    }

    public function testExemptionDeQuota(): void
    {
        // Hors quota = ce qu'un gestionnaire déclenche pour la plateforme, pas pour lui.
        $this->assertSame(
            ['kb_chunking', 'kb_vision', 'kb_embedding', 'tool_test', 'price_refresh'],
            UsageSource::quotaExemptSources()
        );
        // Ce que l'utilisateur consomme en discutant entame son quota — y compris
        // les appels auxiliaires de son tour et ses recherches web.
        foreach (['chat', 'regenerate', 'title', 'memory', 'kb_query', 'context_summary', 'tool_condense', 'web_search', 'transcribe', 'speech', 'prompt_assist'] as $source) {
            $this->assertFalse(UsageSource::isQuotaExempt($source), "$source ne doit pas être exempté");
        }
        $this->assertTrue(UsageSource::isCategoryQuotaExempt(UsageSource::CAT_KNOWLEDGE));
        $this->assertTrue(UsageSource::isCategoryQuotaExempt(UsageSource::CAT_ADMIN));
        $this->assertFalse(UsageSource::isCategoryQuotaExempt(UsageSource::CAT_CHAT));
        $this->assertFalse(UsageSource::isCategoryQuotaExempt(UsageSource::CAT_CHAT_ASSIST));
    }

    public function testCategoriesEtFamilles(): void
    {
        $families = UsageSource::families();
        $this->assertCount(4, $families);
        foreach (UsageSource::categories() as $category) {
            $this->assertNotSame('', UsageSource::categoryLabel($category));
            $this->assertNotSame('', UsageSource::categoryDescription($category));
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', UsageSource::categoryColor($category));
            $this->assertContains(UsageSource::categoryFamily($category), $families, "famille inconnue : $category");
            $this->assertNotEmpty(UsageSource::sourcesOf($category), "catégorie sans source : $category");
        }
        foreach ($families as $family) {
            $this->assertNotSame('', UsageSource::familyLabel($family));
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', UsageSource::familyColor($family));
        }
        // Les quatre familles sont toutes représentées par au moins une catégorie.
        $used = array_unique(array_map([UsageSource::class, 'categoryFamily'], UsageSource::categories()));
        sort($used);
        $sorted = $families;
        sort($sorted);
        $this->assertSame($sorted, $used);
    }

    public function testUnitesParSource(): void
    {
        $this->assertSame(UsageSource::UNIT_SEARCHES, UsageSource::unit(UsageSource::WEB_SEARCH));
        $this->assertSame(UsageSource::UNIT_MINUTES, UsageSource::unit(UsageSource::TRANSCRIBE));
        // Dictée et lecture à voix haute partagent la catégorie Voix (minutes).
        $this->assertSame(UsageSource::UNIT_MINUTES, UsageSource::unit(UsageSource::SPEECH));
        $this->assertSame(UsageSource::CAT_VOICE, UsageSource::category(UsageSource::SPEECH));
        $this->assertSame(UsageSource::category(UsageSource::TRANSCRIBE), UsageSource::category(UsageSource::SPEECH));
        $this->assertSame(UsageSource::UNIT_TOKENS, UsageSource::unit(UsageSource::CHAT));
        $this->assertSame(UsageSource::UNIT_TOKENS, UsageSource::unit(UsageSource::KB_EMBEDDING));
    }

    public function testNormalisationEtCategorieOutil(): void
    {
        $this->assertSame('chat', UsageSource::normalize('inconnue'));
        $this->assertSame('chat', UsageSource::normalize(''));
        $this->assertSame('kb_query', UsageSource::normalize(' kb_query '));
        $this->assertSame(UsageSource::CAT_CREATION, UsageSource::toolCategory('chat'));
        $this->assertSame(UsageSource::CAT_ADMIN, UsageSource::toolCategory(UsageSource::TOOL_TEST));
        $this->assertSame(UsageSource::CAT_CHAT, UsageSource::category('source-inconnue'));
    }

    public function testLeCaseSqlCouvreToutesLesSources(): void
    {
        $sql = UsageSource::sqlCategoryCase('source');
        $this->assertStringStartsWith('CASE source ', $sql);
        foreach (UsageSource::all() as $source) {
            $this->assertStringContainsString("WHEN '" . $source . "' THEN '" . UsageSource::category($source) . "'", $sql);
        }
        $this->assertStringEndsWith("ELSE 'chat' END", $sql);
        $this->assertStringContainsString("WHEN source = 'tool_test' THEN 'admin' ELSE 'creation' END", UsageSource::sqlToolCategoryCase('source'));
    }
}
