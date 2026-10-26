<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT . '/src/Controllers/ChatController.php';

/**
 * Résultats d'outils démesurés condensés avant de repartir au modèle
 * (ResultCondenser), et lecture PAGINÉE des documents Drive et des emails
 * Gmail / Outlook (TextPager), qui garde les lectures sous le seuil.
 */
final class ResultCondenserTest extends TestCase
{
    /** Recherche de `$n` résultats volumineux, avertissement « données » en tête. */
    private static function bigResult(int $n = 80): array
    {
        $items = [];
        for ($i = 1; $i <= $n; $i++) {
            $items[] = ['id' => 'm' . $i, 'objet' => 'Facture ' . $i, 'extrait' => str_repeat('ligne de facture ', 80)];
        }
        return [ExternalContent::KEY => ExternalContent::warning(), 'total' => $n, 'resultats' => $items];
    }

    public function testLeSeuilResteAuDessusDUnePartieDeLecture(): void
    {
        $this->assertSame(ResultCondenser::MIN_THRESHOLD, ResultCondenser::threshold(2000));
        $this->assertSame(60000 * 4 + 8000, ResultCondenser::threshold(60000), 'Une partie paginée (budget × 4) n\'est jamais condensée.');
    }

    public function testSeulUnResultatDemesureEtSansErreurEstCondense(): void
    {
        $this->assertFalse(ResultCondenser::shouldCondense(['contenu' => 'court'], 60000));
        $this->assertFalse(ResultCondenser::shouldCondense(['error' => str_repeat('x', 90000)], 60000), 'Jamais une erreur.');
        $this->assertTrue(ResultCondenser::shouldCondense(self::bigResult(), 60000));
    }

    public function testLeRaccourcissementDeterministeTientEtLeDit(): void
    {
        $big   = self::bigResult();
        $short = ResultCondenser::shrink($big, 20000);
        $this->assertLessThanOrEqual(21000, ResultCondenser::size($short));
        $this->assertSame(ExternalContent::KEY, array_key_first($short), 'L\'avertissement « données » reste en tête.');
        $this->assertSame(80, $short['total']);
        $this->assertStringContainsString('élément(s) de plus non montrés', (string)json_encode($short, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('raccourci', $short['note']);
        $this->assertSame($short, ResultCondenser::shrink($big, 20000), 'Déterministe : même entrée, même sortie.');
    }

    public function testLeResumeGardeLesClesDeDesignationEtDePagination(): void
    {
        $big = ['id' => 'doc1', 'nom' => 'Rapport.docx', 'partie' => 1, 'parties' => 3, 'suite' => 'partie: 2',
                'contenu' => str_repeat('x', 90000), 'meta' => ['lourd' => str_repeat('y', 1000)]];
        $out = ResultCondenser::condensed(ExternalContent::wrap($big), '- point clé');
        $this->assertSame(ExternalContent::KEY, array_key_first($out));
        foreach (['id', 'nom', 'partie', 'parties', 'suite'] as $k) {
            $this->assertSame($big[$k], $out[$k], "$k gardé");
        }
        $this->assertArrayNotHasKey('contenu', $out);
        $this->assertArrayNotHasKey('meta', $out);
        $this->assertSame('- point clé', $out['resume']);
        $this->assertStringContainsString('condensé pour ta question', $out['note']);
    }

    public function testLaConsigneTraiteLeResultatCommeUneDonneeEtBorneLEntree(): void
    {
        $this->assertStringContainsString('DONNÉE', ResultCondenser::systemPrompt());
        $this->assertStringContainsString('identifiants (id), liens', ResultCondenser::systemPrompt());
        $prompt = ResultCondenser::userPrompt('rechercher_gmail', ['requete' => 'facture'], 'Combien ai-je payé ?',
            ['resultats' => [str_repeat('z', ResultCondenser::MAX_INPUT_CHARS * 2)]]);
        $this->assertStringContainsString('Demande de l\'utilisateur : Combien ai-je payé ?', $prompt);
        $this->assertStringContainsString('Outil appelé : rechercher_gmail {"requete":"facture"}', $prompt);
        $this->assertLessThan(ResultCondenser::MAX_INPUT_CHARS + 2000, mb_strlen($prompt));
        $this->assertStringContainsString('[… fin du résultat non transmise …]', $prompt);
    }

    public function testLeRegistreCondenseApresLaTraceQuiGardeLeResultatEntier(): void
    {
        $vu = null;
        $registry = new ToolRegistry(
            function (string $n, array $a, array $r) use (&$vu): void { $vu = $r; },
            null,
            static fn(string $n, array $a, array $r): array => ['resume' => 'court', 'nom' => $r['nom']]
        );
        $registry->add(new class (new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => [])) extends AbstractToolProvider {
            public function key(): string { return 'test'; }
            public function definitions(): array { return [['name' => 'lire', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => []]]]; }
            public function guidance(): string { return ''; }
            public function handlers(): array { return ['lire' => static fn(array $a): array => ['nom' => 'Gros.pdf', 'contenu' => str_repeat('x', 100)]]; }
        });
        $out = $registry->dispatch('lire', []);
        $this->assertSame(['resume' => 'court', 'nom' => 'Gros.pdf'], $out, 'Le modèle reçoit le résultat condensé.');
        $this->assertSame('Gros.pdf', $vu['nom'], 'La trace et la mémoire lisent le résultat ENTIER.');
        $this->assertArrayHasKey('contenu', $vu);
    }

    public function testLeChatCondenseAuSeuilEtRetombeSurLeRaccourcissement(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('$threshold = ResultCondenser::threshold(self::connectorReadBudget($ragBudget));', $src);
        $this->assertStringContainsString('if (!ResultCondenser::shouldCondense($result, $threshold)) {', $src);
        $fn = substr($src, (int)strpos($src, 'private function condenseToolResult('), 2500);
        $this->assertGreaterThanOrEqual(3, substr_count($fn, 'return ResultCondenser::shrink($result);'),
            'Sans modèle, sur exception, sur réponse vide ou en erreur : raccourcissement, jamais le résultat entier.');
        $this->assertStringContainsString('UsageSource::TOOL_CONDENSE', $fn);
        $this->assertStringContainsString('$this->resolveHelperLlm($conv)', $fn);
    }

    public function testLesLecturesDeDocumentsEtDEmailsSePaginent(): void
    {
        foreach (['DriveTools' => 'lire_google_drive', 'GmailTools' => 'lire_gmail', 'OutlookTools' => 'lire_outlook'] as $classe => $outil) {
            $tools = AgentSource::tools($classe);
            $this->assertStringContainsString('self::partArg($args)', $tools, "$outil lit la partie demandée");
            $this->assertMatchesRegularExpression("/'name'\\s+=> '" . $outil . "'.*?'partie' => \\[/s", $tools, "$outil déclare `partie`");
        }
        foreach (['src/Drive/UserDriveService.php', 'src/Gmail/UserGmailService.php', 'src/Outlook/UserOutlookService.php'] as $service) {
            $code = (string)file_get_contents(ROOT . '/' . $service);
            $this->assertStringContainsString('TextPager::page($text, max(1000, $tokenBudget * 4), $part)', $code, $service);
            $this->assertStringNotContainsString('[… contenu tronqué]', substr($code, (int)strpos($code, 'function read'), 4000),
                "$service : la suite d'un long contenu est lisible, plus coupée.");
        }

        $partArg = new \ReflectionMethod(AbstractToolProvider::class, 'partArg');
        $this->assertSame(1, $partArg->invoke(null, []));
        $this->assertSame(3, $partArg->invoke(null, ['partie' => '3']));
        $this->assertSame(1, $partArg->invoke(null, ['partie' => 'suite']));
        $this->assertSame(1, $partArg->invoke(null, ['partie' => -2]));
    }
}
