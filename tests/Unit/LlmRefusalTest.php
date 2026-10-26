<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refus du modèle (`stop_reason: "refusal"`).
 *
 * Claude Fable 5 / 5.1 (classifieurs cyber, bio…), Opus 5 (cyber), Opus 5.5
 * et Sonnet 5.5 déclinent une demande par un HTTP 200 : flux vide si le refus
 * précède toute sortie,
 * interrompu sinon. Sans traitement dédié, le flux vide passait pour une
 * panne — « le serveur n'a renvoyé aucune donnée, vérifiez votre clé API » —
 * et « Réessayer » reproduisait le même refus. Un refus survenu en pleine
 * boucle d'outils laissait en outre exécuter les appels d'une sortie
 * déclinée.
 */
final class LlmRefusalTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::use('fr');
        parent::tearDown();
    }

    // ---------- Lecture du message_delta ----------

    /** @return array<string, array{mixed, ?string}> */
    public static function deltas(): array
    {
        return [
            'refus avec catégorie' => [
                ['stop_reason' => 'refusal', 'stop_sequence' => null,
                 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => '…']],
                'cyber',
            ],
            // stop_details est informatif et peut manquer : c'est stop_reason qui tranche.
            'refus sans détail'    => [['stop_reason' => 'refusal'], ''],
            'catégorie nulle'      => [['stop_reason' => 'refusal', 'stop_details' => ['category' => null]], ''],
            'fin normale'          => [['stop_reason' => 'end_turn'], null],
            'limite atteinte'      => [['stop_reason' => 'max_tokens'], null],
            'appel d\'outil'       => [['stop_reason' => 'tool_use'], null],
            'delta absent'         => [null, null],
        ];
    }

    #[DataProvider('deltas')]
    public function testLeRefusSeLitSurLeStopReason(mixed $delta, ?string $attendu): void
    {
        $this->assertSame($attendu, AnthropicAdapter::refusalFromDelta($delta));
    }

    // ---------- Ce qui part vers le client ----------

    public function testUnRefusAvantToutTexteDevientUneDemandeDeclinee(): void
    {
        $chunk = AnthropicAdapter::refusalChunk('cyber', false);

        $desc = LlmErrorPresenter::parse($chunk);
        $this->assertSame('refusal', $desc['kind'] ?? null, 'Le chunk doit être reconnu comme un refus : ' . $chunk);
        $this->assertSame('Anthropic', $desc['provider']);

        $payload = LlmErrorPresenter::present($desc);
        $this->assertSame('Demande déclinée', $payload['title']);
        $this->assertStringContainsString('Anthropic a décliné cette demande', $payload['message']);
        $this->assertFalse($payload['retryable'], 'Réessayer la même demande reproduirait le même refus.');
    }

    public function testLeRefusNeDeclenchePasLaBasculeSurLeLlmDeSecours(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $controleur = (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ChatController::class, 'isRetryableLlmChunk');

        $this->assertFalse(
            $m->invoke($controleur, AnthropicAdapter::refusalChunk('cyber', false)),
            'Un refus n\'est pas une panne : il ne doit pas passer pour une erreur 5xx/429.'
        );
    }

    public function testUnRefusApresUnDebutDeReponseLaisseUneNoteVisible(): void
    {
        $note = AnthropicAdapter::refusalChunk('cyber', true);

        $this->assertStringStartsWith("\n\n*[", $note);
        $this->assertStringContainsString('Réponse interrompue', $note);
        // Arrivé après du texte, un chunk d'erreur serait affiché tel quel :
        // la note ne doit pas ressembler à une erreur d'adaptateur.
        $this->assertNull(LlmErrorPresenter::parse($note));
    }

    public function testLeRefusParleLaLangueDuLecteur(): void
    {
        I18n::use('en');

        $payload = LlmErrorPresenter::present(['kind' => 'refusal', 'provider' => 'anthropic', 'status' => null]);
        $this->assertSame('Request declined', $payload['title']);
        $this->assertSame('Anthropic declined this request. Rephrase it, or choose another model.', $payload['message']);

        $this->assertStringContainsString('Response interrupted', AnthropicAdapter::refusalChunk('', true));
        // Le chunk d'erreur, lui, est un protocole interne : il reste français
        // pour que les expressions de LlmErrorPresenter::parse le reconnaissent.
        $this->assertSame('refusal', LlmErrorPresenter::parse(AnthropicAdapter::refusalChunk('', false))['kind'] ?? null);
    }

    // ---------- Câblage dans l'adapter ----------

    public function testLesDeuxCheminsDeStreamingLisentLeRefus(): void
    {
        $src = file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');

        $this->assertSame(
            2,
            substr_count($src, 'self::refusalFromDelta($json[\'delta\'] ?? null)'),
            'stream() et la boucle d\'outils doivent tous deux lire le stop_reason.'
        );
        // Un refus avant toute sortie n'est pas facturé (0 token) : sans la
        // garde, il déclencherait AUSSI le diagnostic « flux vide ».
        $this->assertMatchesRegularExpression('/\$tokensIn === 0 && \$tokensOut === 0 .*\$refusal === null/', $src);
    }

    /**
     * Un refus AVANT toute sortie n'est pas toujours gratuit : Anthropic
     * facture les catégories où ses classifieurs se trompent peu (biologie,
     * développement de modèles concurrents, extraction du raisonnement —
     * documentation relue le 30/09/2026). Les remettre à zéro faisait sortir
     * ces coûts du grand livre ; les autres restent à zéro.
     */
    public function testSeulsLesRefusFacturesSontComptes(): void
    {
        foreach (['bio', 'frontier_llm', 'reasoning_extraction'] as $facturee) {
            $this->assertTrue(AnthropicAdapter::refusalBilledBeforeOutput($facturee), $facturee);
        }
        foreach (['cyber', 'general_harms', '', 'inconnue'] as $gratuite) {
            $this->assertFalse(AnthropicAdapter::refusalBilledBeforeOutput($gratuite), $gratuite === '' ? '(sans catégorie)' : $gratuite);
        }

        // Les deux chemins (sans outils, boucle d'outils) appliquent la même règle.
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertStringContainsString('if ($refusal !== null && $tokensOut === 0 && !self::refusalBilledBeforeOutput($refusal)) {', $src);
        $this->assertStringContainsString('if ($out === 0 && !self::refusalBilledBeforeOutput($refusal)) {', $src);
    }

    public function testUnTourDeclineNExecuteAucunAppelDOutil(): void
    {
        $src = file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$refusal !== null\) \{[^}]*\$blocks = \[\];/s',
            $src,
            'Les tool_use d\'un tour refusé appartiennent à une sortie déclinée : ils ne doivent pas être exécutés.'
        );
        $this->assertStringContainsString("if ((\$res['refusal'] ?? null) !== null) {", $src);
        $this->assertStringContainsString('self::refusalChunk($res[\'refusal\'], $textSent)', $src);
    }

    public function testLeBlocDErreurDuRefusEstAmbre(): void
    {
        $css = file_get_contents(ROOT . '/public/assets/css/main.css');
        foreach (['', ' .msg-error-icon', ' .msg-error-title'] as $suffixe) {
            $this->assertStringContainsString('.msg-error[data-kind="refusal"]' . $suffixe, $css);
        }
    }
}
