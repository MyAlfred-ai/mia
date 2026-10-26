<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Boucle agentique des connecteurs : gardes de non-régression.
 *
 * Quatre leviers rendent la boucle d'outils réellement « agent » :
 *
 *  1. budget de rounds PARTAGÉ (LLMInterface::TOOL_MAX_ROUNDS = 8, hérité par
 *     les adapters — 3 suffisait à peine à une chaîne chercher → lire → créer,
 *     zéro marge d'adaptation) ;
 *  2. synthèse forcée au dernier round (`tool_choice: none`) — avant, des
 *     outils appelés au dernier tour étaient exécutés sans que le modèle voie
 *     jamais leurs résultats : réponse amputée en plein vol, sans erreur ;
 *  3. garde anti-répétition au dispatch unique (ToolRegistry, src/Agent/) —
 *     un appel strictement identique n'est jamais rejoué (c'est elle qui rend
 *     le budget de 8 rounds sûr) ;
 *  4. guidance transversale « agent » (mini-plan, enchaînement, adaptation,
 *     budget annoncé) + conseils de relance sur recherche vide.
 *
 * Tout est vérifié sans réseau : constantes, fonctions pures et câblage source.
 */
final class ConnectorAgentLoopTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // 1. Budget de rounds : source unique, héritée
    // ---------------------------------------------------------------------

    public function testLeBudgetDeRoundsEstLaConstanteDeLInterface(): void
    {
        $this->assertSame(8, LLMInterface::TOOL_MAX_ROUNDS);
        // Hérité par les adapters à boucle : self::TOOL_MAX_ROUNDS y résout
        // la constante de l'interface.
        $this->assertSame(8, OpenAIAdapter::TOOL_MAX_ROUNDS);
        $this->assertSame(8, AnthropicAdapter::TOOL_MAX_ROUNDS);
    }

    public function testAucunAdapterNeRedeclareLeBudgetLocalement(): void
    {
        foreach (['/src/LLM/OpenAIAdapter.php', '/src/LLM/AnthropicAdapter.php'] as $fichier) {
            $src = (string)file_get_contents(ROOT . $fichier);
            $this->assertStringNotContainsString(
                'const TOOL_MAX_ROUNDS',
                $src,
                $fichier . ' : une constante locale divergerait de la guidance qui annonce le budget au modèle.'
            );
            $this->assertStringContainsString('LlmAdapterHelpers::roundsBudget($options)', $src,
                $fichier . ' : la boucle lit le budget partagé (constante de l\'interface, relevée par un plan long).');
        }
    }

    public function testLeBudgetPartageLitLaConstanteEtSeReleveSansDepasserLePlafond(): void
    {
        $this->assertSame(LLMInterface::TOOL_MAX_ROUNDS, LlmAdapterHelpers::roundsBudget([]));
        $this->assertSame(10, LlmAdapterHelpers::roundsBudget(['max_rounds' => static fn(): int => 10]));
        $this->assertSame(LLMInterface::TOOL_MAX_ROUNDS_EXTENDED, LlmAdapterHelpers::roundsBudget(['max_rounds' => static fn(): int => 99]));
        $this->assertSame(1, LlmAdapterHelpers::roundsBudget(['max_rounds' => static fn(): int => 0]));
    }

    // ---------------------------------------------------------------------
    // 2. Synthèse forcée au dernier round
    // ---------------------------------------------------------------------

    public function testLesTroisBouclesForcentLaSyntheseAuDernierRound(): void
    {
        // OpenAI : deux boucles (Chat Completions + Responses API).
        $openai = (string)file_get_contents(ROOT . '/src/LLM/OpenAIAdapter.php');
        $this->assertSame(
            2,
            substr_count($openai, "\$dernierRound = \$round === LlmAdapterHelpers::roundsBudget(\$options) - 1;"),
            'OpenAI : les DEUX boucles (chat + responses) repèrent le dernier round sur le budget partagé.'
        );
        $this->assertSame(2, substr_count($openai, "\$dernierRound ? 'none' : 'auto'"),
            'OpenAI : les DEUX boucles basculent tool_choice sur none au dernier round.');
        $this->assertSame(2, substr_count($openai, "['rounds_exhausted' => true]"), 'Budget atteint : signalé par les deux boucles.');

        // Anthropic : tool_choice objet, posé seulement si des tools sont
        // envoyés (tool_choice sans tools serait refusé par l'API).
        $anthropic = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertStringContainsString("\$dernierRound = \$round === LlmAdapterHelpers::roundsBudget(\$options) - 1;", $anthropic);
        $this->assertStringContainsString("\$tokens['rounds_exhausted'] = true;", $anthropic);
        $this->assertStringContainsString("['type' => 'none']", $anthropic);
        // La charge utile est reconstruite à CHAQUE essai (repli web, rejeu
        // sans `fallbacks`, relais de modèle) : tool_choice ne peut exister
        // que là où tools est posé — jamais l'un sans l'autre.
        $this->assertMatchesRegularExpression(
            "/if \(\\\$roundTools !== \[\]\) \{\s*\\\$payload\['tools'\] = \\\$roundTools;\s*if \(\\\$dernierRound\) \{\s*\\\$payload\['tool_choice'\] = \['type' => 'none'\];/",
            $anthropic,
            'Anthropic : tool_choice ne doit être posé qu\'avec tools (le barreau sans outil n\'envoie ni l\'un ni l\'autre).'
        );
        $this->assertSame(1, substr_count($anthropic, "\$payload['tool_choice'] ="), 'Une seule affectation de tool_choice, sous la garde.');
    }

    // ---------------------------------------------------------------------
    // 3. Garde anti-répétition (signature pure + câblage)
    // ---------------------------------------------------------------------

    public function testLaSignatureEstInsensibleALOrdreDesCles(): void
    {
        $a = ToolRegistry::signature('rechercher_gmail', ['requete' => 'devis', 'max_resultats' => 5]);
        $b = ToolRegistry::signature('rechercher_gmail', ['max_resultats' => 5, 'requete' => 'devis']);
        $this->assertSame($a, $b, 'Mêmes arguments dans un autre ordre = même appel : la garde ne doit pas être contournable.');

        // Les tableaux imbriqués sont normalisés aussi.
        $c = ToolRegistry::signature('outil', ['x' => ['b' => 2, 'a' => 1]]);
        $d = ToolRegistry::signature('outil', ['x' => ['a' => 1, 'b' => 2]]);
        $this->assertSame($c, $d);
    }

    public function testLaSignatureDistingueOutilEtArguments(): void
    {
        $base = ToolRegistry::signature('rechercher_gmail', ['requete' => 'devis']);
        $this->assertNotSame($base, ToolRegistry::signature('rechercher_outlook', ['requete' => 'devis']));
        $this->assertNotSame($base, ToolRegistry::signature('rechercher_gmail', ['requete' => 'devis 2024']));
        $this->assertNotSame($base, ToolRegistry::signature('rechercher_gmail', []));
    }

    public function testLeDispatchRefuseUnAppelIdentiqueSansLExecuter(): void
    {
        $executions = 0;
        $traces     = [];
        $registry = new ToolRegistry(function (string $name, array $args, array $result) use (&$traces): void {
            $traces[] = $name;
        });
        $registry->add($this->fournisseur('rechercher_gmail', function (array $args) use (&$executions): array {
            $executions++;
            return ['resultats' => [['id' => 'm1']]];
        }));

        $premier = $registry->dispatch('rechercher_gmail', ['requete' => 'devis', 'max_resultats' => 5]);
        $this->assertArrayNotHasKey('error', $premier);

        // Même appel, clés dans un autre ordre : refusé, SANS exécution ni trace
        // (rien n'a eu lieu, rien n'est tracé, rien n'est payé).
        $second = $registry->dispatch('rechercher_gmail', ['max_resultats' => 5, 'requete' => 'devis']);
        $this->assertStringContainsString('Appel identique déjà effectué', (string)($second['error'] ?? ''));
        $this->assertSame(1, $executions, 'La garde doit refuser AVANT le handler.');
        $this->assertSame(['rechercher_gmail'], $traces, 'Un appel refusé ne laisse aucune trace.');
        $this->assertSame(1, $registry->executedCount());

        // Autres arguments : exécuté.
        $registry->dispatch('rechercher_gmail', ['requete' => 'facture']);
        $this->assertSame(2, $executions);
    }

    public function testUnOutilInconnuRendUneErreurAuModele(): void
    {
        $registry = new ToolRegistry();
        $this->assertSame(['error' => 'Outil inconnu : fantome'], $registry->dispatch('fantome', []));
        $this->assertSame(0, $registry->executedCount());

        // Outils présents : la liste revient au modèle, qui corrige au tour suivant.
        $registry->add($this->fournisseur('lire_gmail', fn(array $a): array => ['ok' => 1]));
        $this->assertStringContainsString('Outils disponibles : lire_gmail', $registry->dispatch('lire_mail', [])['error']);
        // Même nom à la casse près : accepté (Anthropic le documente).
        $this->assertSame(['ok' => 1], $registry->dispatch('Lire_Gmail', []));
        $this->assertSame(1, $registry->executedCount());
        $this->assertNull(ToolRegistry::resolveName('LIRE', ['lire', 'Lire']), 'Ambigu : rien n’est deviné.');
    }

    // ---------------------------------------------------------------------
    // 4. Guidance agent transversale
    // ---------------------------------------------------------------------

    public function testLaGuidanceAgentAnnonceLeBudgetReel(): void
    {
        $texte = $this->guidanceAgent();

        $this->assertStringContainsString(
            (string)LLMInterface::TOOL_MAX_ROUNDS,
            $texte,
            'Le budget annoncé au modèle doit être celui des boucles des adapters — jamais un chiffre recopié.'
        );
        // La méthode, pas les règles : plan annoncé, enchaînement, adaptation,
        // et rappel que les écritures restent sous approbation.
        $this->assertStringContainsString('plan', $texte);
        $this->assertStringContainsString('même tour', $texte);
        $this->assertStringContainsString("carte d'approbation", $texte);
        $this->assertStringContainsString('reformule ou élargis', $texte);
    }

    public function testLaGuidanceAgentEstInjecteePourLesConnecteursSeulement(): void
    {
        $src = AgentSource::chat();

        // Les 10 sources de connecteurs se déclarent (drive, gmail, outlook,
        // agenda Google, calendrier Outlook, Slack, Teams, tâches Google,
        // Microsoft To Do, n8n) par
        // connector() : le registre en déduit la LISTE des connecteurs engagés,
        // à laquelle est filtré le rappel des objets déjà consultés.
        $this->assertSame(
            10,
            preg_match_all("/public function connector\\(\\): \\?string\\s*\\{\\s*return '[a-z0-9]+';/", AgentSource::agent()),
            'Chaque fournisseur de connecteur doit se déclarer — un oubli priverait son connecteur de la méthode agent.'
        );
        $this->assertStringContainsString("if (\$engagedConnectors !== [] || \$autoConnectors !== []) {\n            \$toolGuidance .= AgentGuidance::method();", $src,
            'Connecteurs engagés OU activables par MIA (« Laisser MIA choisir ») : la méthode agent suit.');

        // L'outil image seul n'injecte rien : ImageTools n'est pas un connecteur.
        $this->assertStringNotContainsString('public function connector()', AgentSource::tools('ImageTools'));
        $registry = new ToolRegistry();
        $registry->add($this->fournisseur('generer_image', fn(array $a): array => []));
        $this->assertSame([], $registry->engagedConnectors());
    }

    // ---------------------------------------------------------------------
    // 5. Conseils de relance sur recherche vide
    // ---------------------------------------------------------------------

    public function testHintIfEmptyConseilleSurResultatVideSeulement(): void
    {
        $vide = ToolRegistry::hintIfEmpty(['resultats' => []], 'resultats', 'Élargis.');
        $this->assertSame('Élargis.', $vide['note'] ?? null);

        $plein = ToolRegistry::hintIfEmpty(['resultats' => [['id' => 'x']]], 'resultats', 'Élargis.');
        $this->assertArrayNotHasKey('note', $plein);

        // Résultat en erreur (ou clé absente) : rien d'ajouté, le message
        // d'erreur reste seul maître.
        $erreur = ToolRegistry::hintIfEmpty(['error' => 'panne', 'resultats' => []], 'resultats', 'Élargis.');
        $this->assertArrayNotHasKey('note', $erreur);
        $absent = ToolRegistry::hintIfEmpty(['autre' => 1], 'resultats', 'Élargis.');
        $this->assertArrayNotHasKey('note', $absent);
    }

    public function testLesSixRecherchesPortentUnConseilDeRelance(): void
    {
        $src = AgentSource::agent();
        $handlers = [
            "'rechercher_google_drive'",
            "'rechercher_gmail'",
            "'rechercher_outlook'",
            "'rechercher_agenda'",
            "'rechercher_agenda_outlook'",
            "'lister_taches'",
        ];
        foreach ($handlers as $nom) {
            $debut = strpos($src, '$toolHandlers[' . $nom . '] = function');
            $this->assertNotFalse($debut, $nom . ' : handler introuvable.');
            $corps = substr($src, $debut, 900);
            $this->assertStringContainsString(
                'self::hintIfEmpty(',
                $corps,
                $nom . ' : une recherche vide sans conseil de relance fait abandonner le modèle au lieu d\'adapter.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // LLM de secours : il garde les outils, ne bascule jamais après une action
    // ---------------------------------------------------------------------

    public function testLeSecoursNeBasculeJamaisApresUnOutilExecute(): void
    {
        $this->assertFalse(ChatController::failoverBlockedByTools(null), 'Sans outils : bascule permise.');
        $registry = new ToolRegistry();
        $registry->add($this->fournisseur('lire_gmail', fn(array $a): array => ['corps' => 'x']));
        $this->assertFalse(ChatController::failoverBlockedByTools($registry), 'Rien n’a agi : le secours peut reprendre la demande.');

        $registry->dispatch('lire_gmail', ['id' => 'a']);
        $this->assertTrue(ChatController::failoverBlockedByTools($registry),
            'Une lecture faite ou une carte déposée : rejouer la demande sur un autre modèle referait tout en double.');
    }

    public function testUnSecoursSansOutilsEstPrevenu(): void
    {
        $this->assertSame('', ChatController::fallbackWithoutToolsNote(null), '0 token sans outils.');
        $this->assertSame('', ChatController::fallbackWithoutToolsNote(new ToolRegistry()));

        $registry = new ToolRegistry();
        $registry->add($this->fournisseur('generer_image', fn(array $a): array => []));
        $note = ChatController::fallbackWithoutToolsNote($registry);
        $this->assertStringContainsString('ne peux pas utiliser les outils', $note);
        $this->assertStringContainsString("N'affirme jamais avoir consulté", $note,
            'Sans la note, le secours pourrait prétendre avoir lu un Drive qu’il ne voit pas.');
    }

    public function testLeSecoursRecoitLeRegistreEtLeGardeSIlSaitSEnServir(): void
    {
        $src = AgentSource::chat();
        $retry = $this->extraire($src, 'private function retryStreamWithFallback(', 'public static function failoverBlockedByTools(');
        $this->assertStringContainsString('?ToolRegistry $registry = null', $retry);
        $this->assertMatchesRegularExpression('/failoverBlockedByTools\(\$registry\).*?\$fallback = \'\';/s', $retry,
            'Une action déjà menée coupe la bascule AVANT de choisir un secours.');
        $this->assertMatchesRegularExpression('/\$registry->hasTools\(\) && \$newAdapter->supportsToolUse\(\).*?->streamWithTools\(/s', $retry);
        $this->assertStringContainsString('$registry->dispatch(...)', $retry, 'Même dispatch : même garde anti-répétition, même trace.');
        $this->assertStringContainsString('self::fallbackWithoutToolsNote($registry)', $retry);

        // Le chemin des outils de runSend passe bien son registre.
        $this->assertMatchesRegularExpression('/retryStreamWithFallback\(\s*\$llm, \$retryable, .*?\$registry, \$toolGuidance, \$loopOptions\s*\)/s', $src);
    }

    // ---------------------------------------------------------------------
    // Documentation
    // ---------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocEstAJour(): void
    {
        $claude = (string)file_get_contents(ROOT . '/CLAUDE.md');
        $this->assertStringContainsString('TOOL_MAX_ROUNDS', $claude, 'CLAUDE.md doit documenter la boucle agentique.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function guidanceAgent(): string
    {
        return AgentGuidance::method();
    }

    /** Fournisseur minimal d'un seul outil, pour éprouver le registre. */
    private function fournisseur(string $outil, \Closure $handler): ToolProvider
    {
        return new class ($outil, $handler) implements ToolProvider {
            public function __construct(private string $outil, private \Closure $handler)
            {
            }

            public function key(): string
            {
                return 'test_' . $this->outil;
            }

            public function connector(): ?string
            {
                return null;
            }

            public function definitions(): array
            {
                return [['name' => $this->outil, 'description' => 'test', 'parameters' => ['type' => 'object', 'properties' => new \stdClass()]]];
            }

            public function guidance(): string
            {
                return '';
            }

            public function handlers(): array
            {
                return [$this->outil => $this->handler];
            }

            public function externalContentTools(): array
            {
                return [];
            }
        };
    }

    /** Extrait la portion de source entre deux marqueurs (bornes exclues). */
    private function extraire(string $src, string $de, string $a): string
    {
        $debut = strpos($src, $de);
        $this->assertNotFalse($debut, 'Marqueur introuvable : ' . $de);
        $fin = strpos($src, $a, $debut);
        $this->assertNotFalse($fin, 'Marqueur introuvable : ' . $a);
        return substr($src, $debut, $fin - $debut);
    }
}
