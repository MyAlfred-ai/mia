<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tableaux d'une réponse : fermés en bas.
 *
 * main.css retire le filet de la dernière ligne de TOUS les tableaux
 * (`tr:last-child td { border-bottom: none }`, pensé pour l'administration).
 * Spécificité (0,1,2) : elle l'emportait sur `.msg-content td` (0,1,1), et un
 * tableau rédigé par MIA s'affichait ouvert en bas, sans son dernier filet —
 * comme les aperçus de contenu des workflows. Ces tests gardent la règle qui
 * le rétablit, et sa spécificité.
 */
final class MarkdownTableBorderTest extends TestCase
{
    private static function css(string $file): string
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/' . $file);
        return (string)preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** Déclarations de la règle dont le sélecteur est exactement `$selector`. */
    private static function rule(string $css, string $selector): ?string
    {
        foreach (preg_split('/\}/', $css) ?: [] as $block) {
            $parts = explode('{', $block, 2);
            if (count($parts) === 2 && trim($parts[0]) === $selector) {
                return $parts[1];
            }
        }
        return null;
    }

    public function testLaRegleGlobaleRetireToujoursLeFiletDesTableauxDAdministration(): void
    {
        $decl = self::rule(self::css('main.css'), 'tr:last-child td');
        $this->assertNotNull($decl, 'Règle globale introuvable : ce test ne garde plus rien, à revoir.');
        $this->assertMatchesRegularExpression('/border-bottom:\s*none/', $decl);
    }

    public function testLeChatRetablitLeDernierFilet(): void
    {
        $decl = self::rule(self::css('main.css'), '.msg-content tr:last-child > :is(td, th)');
        $this->assertNotNull($decl, 'La dernière ligne d\'un tableau du chat doit garder son filet.');
        $this->assertMatchesRegularExpression('/border-bottom:\s*1px solid var\(--border\)/', $decl);
    }

    public function testLesApercusDesWorkflowsRetablissentLeDernierFilet(): void
    {
        $decl = self::rule(self::css('workflows.css'), ':is(.wf-view-text, .wf-view-table) tr:last-child > :is(td, th)');
        $this->assertNotNull($decl);
        $this->assertMatchesRegularExpression('/border-bottom:\s*1px solid var\(--border\)/', $decl);
    }
}
