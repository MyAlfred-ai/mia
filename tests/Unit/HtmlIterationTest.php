<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Itération sur les pages HTML générées : extraction du résumé de
 * modifications (GeneratedHtml::splitSummary), directive de modification du
 * job (HtmlPageJob::modificationDirective) et câblage front (chip d'outil
 * ré-armé, résumé affiché) — sans base ni LLM.
 */
final class HtmlIterationTest extends TestCase
{
    private const DOC = "<!DOCTYPE html>\n<html lang=\"fr\"><head><meta charset=\"UTF-8\"></head><body><h1>Site</h1></body></html>";

    // ---- splitSummary ----

    public function testSplitSummaryExtraitEtRetireLeResume(): void
    {
        $raw = self::DOC . "\n" . GeneratedHtml::SUMMARY_MARKER . " Fond du header passé en bleu, section contact ajoutée.";
        [$html, $summary] = GeneratedHtml::splitSummary($raw);

        $this->assertSame('Fond du header passé en bleu, section contact ajoutée.', $summary);
        $this->assertStringNotContainsString('MODIFICATIONS', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testSplitSummaryTolereLesVariantesDeMarqueur(): void
    {
        foreach ([
            'RÉSUMÉ_MODIFICATIONS : ',
            'Resume modifications: ',
            '**RESUME_MODIFICATIONS:** ',
        ] as $marker) {
            $raw = self::DOC . "\n" . $marker . 'Titre corrigé.';
            [, $summary] = GeneratedHtml::splitSummary($raw);
            $this->assertSame('Titre corrigé.', $summary, 'Marqueur non reconnu : ' . $marker);
        }
    }

    public function testSplitSummarySansMarqueurRendLaReponseIntacte(): void
    {
        [$html, $summary] = GeneratedHtml::splitSummary(self::DOC);
        $this->assertSame(self::DOC, $html);
        $this->assertNull($summary);
    }

    public function testSplitSummaryRefuseUnMarqueurQuiPrecedeLeDocument(): void
    {
        // Marqueur AVANT la page : amputer la réponse détruirait le document.
        $raw = GeneratedHtml::SUMMARY_MARKER . " blabla\n" . self::DOC;
        [$html, $summary] = GeneratedHtml::splitSummary($raw);
        $this->assertSame($raw, $html);
        $this->assertNull($summary);
    }

    public function testSplitSummaryPrendLaDerniereOccurrence(): void
    {
        // Une page qui CITE le marqueur dans son contenu ne doit pas être
        // coupée au milieu : seule la dernière occurrence (hors document) vaut.
        $doc = str_replace('<h1>Site</h1>', '<h1>Site</h1><p>Le format RESUME_MODIFICATIONS: est documenté ici.</p>', self::DOC);
        $raw = $doc . "\nRESUME_MODIFICATIONS: Paragraphe d'aide ajouté.";
        [$html, $summary] = GeneratedHtml::splitSummary($raw);

        $this->assertSame("Paragraphe d'aide ajouté.", $summary);
        $this->assertStringContainsString('est documenté ici', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testSplitSummaryBorneLaLongueur(): void
    {
        $raw = self::DOC . "\n" . GeneratedHtml::SUMMARY_MARKER . ' ' . str_repeat('a', 2000);
        [, $summary] = GeneratedHtml::splitSummary($raw);
        $this->assertNotNull($summary);
        $this->assertLessThanOrEqual(801, mb_strlen($summary)); // 800 + ellipse
    }

    public function testSanitizeApresSplitRendUnePagePropre(): void
    {
        $raw = "```html\n" . self::DOC . "\n```\n" . GeneratedHtml::SUMMARY_MARKER . ' Pied de page enrichi.';
        [$htmlPart, $summary] = GeneratedHtml::splitSummary($raw);
        $clean = GeneratedHtml::sanitize($htmlPart);

        $this->assertSame('Pied de page enrichi.', $summary);
        $this->assertStringNotContainsString('MODIFICATIONS', $clean);
        $this->assertStringNotContainsString('```', $clean);
        $this->assertStringContainsString('<h1>Site</h1>', $clean);
    }

    // ---- Directive de modification du job ----

    public function testModificationDirectivePorteBaseDeTravailEtMarqueur(): void
    {
        $directive = HtmlPageJob::modificationDirective();
        $this->assertStringContainsString('base de travail', $directive);
        $this->assertStringContainsString('PAGE_HTML_EXISTANTE', $directive);
        $this->assertStringContainsString(GeneratedHtml::SUMMARY_MARKER, $directive);
        $this->assertStringContainsString('</html>', $directive);
    }

    public function testRunCableLaDirectiveEtLeResume(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Jobs/HtmlPageJob.php');
        // La directive statique est bien celle injectée dans le system prompt…
        $this->assertStringContainsString('self::modificationDirective()', $src);
        // …le résumé est extrait avant sanitize et persisté + émis dans done.
        $this->assertStringContainsString('GeneratedHtml::splitSummary', $src);
        $this->assertStringContainsString("'summary_msg_id'", $src);
    }

    // ---- Câblage front (chat.js) ----

    public function testChatJsCableIterationEtResume(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');

        // Mode itération : une conversation qui porte déjà une page arme
        // l'outil Page HTML, mais seulement à DÉFAUT de choix mémorisé —
        // celui-ci fait foi (cf. ToolPersistenceTest).
        $this->assertStringContainsString('function syncToolForConversation()', $js);
        $this->assertStringContainsString('conversationHasGeneratedHtml', $js);
        $this->assertMatchesRegularExpression(
            '/restoreToolForConversation\?\.\(\)\) return;\s*\n\s*if \(conversationHasGeneratedHtml\(\)\) window\.armToolFromPicker/',
            $js,
            'Le choix mémorisé doit primer sur le ré-armement automatique de la Page HTML'
        );

        // Résumé des ajustements affiché en direct à la fin du job.
        $this->assertStringContainsString('json.summary', $js);

        // Filet client : le marqueur ne doit jamais rester dans l'aperçu.
        $this->assertStringContainsString('MODIFICATIONS', $js);
    }
}
