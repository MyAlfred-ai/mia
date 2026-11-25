<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Gemini Embedding 2 — pages Embeddings, Models, Pricing et Deprecations de
 * Google relues le 2026-11-19.
 *
 * MIA ne proposait que gemini-embedding-001, dont Google annonce l'arrêt (au
 * plus tôt le 14/05/2028) avec gemini-embedding-2 pour remplaçant. Le
 * nouveau modèle a une particularité qui casserait une intégration recopiée :
 * il REFUSE le paramètre `task_type` et attend la tâche écrite DANS le texte
 * — un préfixe pour la question, une structure « title | text » pour le
 * document. Sans ce préfixe, Google le dit « fortement recommandé » : la
 * recherche marche, moins bien.
 */
final class GeminiEmbedding2Test extends TestCase
{
    public function testLeModeleEstAuCatalogueEtProposeALaCreation(): void
    {
        $gemini = EmbeddingService::catalog()['gemini'];
        $this->assertSame('gemini-embedding-2', $gemini['default_model'], 'Le modèle recommandé par Google est proposé à la création.');
        $m = $gemini['models']['gemini-embedding-2'];
        $this->assertSame(1536, $m['dims']);
        $this->assertSame(1536, $m['output_dimensionality'], 'Les vecteurs doivent avoir la taille de la collection Qdrant.');
        $this->assertSame(0.20, $m['pricing_usd_per_1m'], 'Tarif texte du niveau payant.');
        $this->assertSame(1536, EmbeddingService::dimsFor('gemini', 'gemini-embedding-2'));
        $this->assertStringContainsString('Gemini Embedding 2', EmbeddingService::label('gemini', 'gemini-embedding-2'));
        // L'ancien reste proposé : les bases qui le portent le gardent, et
        // « Réindexer avec » doit pouvoir y revenir.
        $this->assertArrayHasKey('gemini-embedding-001', $gemini['models']);
        $this->assertArrayNotHasKey('task_prefixes', $gemini['models']['gemini-embedding-001'], 'gemini-embedding-001 garde ses textes tels quels.');
    }

    public function testLaQuestionEtLeDocumentPortentChacunLeurPrefixe(): void
    {
        $m = EmbeddingService::catalog()['gemini']['models']['gemini-embedding-2'];
        $this->assertSame(
            'task: search result | query: Quel est le délai de livraison ?',
            EmbeddingService::withTaskPrefix($m, EmbeddingService::KIND_QUERY, 'Quel est le délai de livraison ?')
        );
        $this->assertSame(
            'title: none | text: Livraison sous 48 h.',
            EmbeddingService::withTaskPrefix($m, EmbeddingService::KIND_DOCUMENT, 'Livraison sous 48 h.')
        );
        $this->assertSame(
            'title: none | text: x',
            EmbeddingService::withTaskPrefix($m, 'inconnue', 'x'),
            'Une nature inconnue est traitée comme un document, jamais comme une question.'
        );
    }

    public function testLesAutresModelesGardentLeurTexte(): void
    {
        foreach (EmbeddingService::catalog() as $provider => $entry) {
            foreach ($entry['models'] as $model => $info) {
                if (isset($info['task_prefixes'])) {
                    continue;
                }
                foreach ([EmbeddingService::KIND_QUERY, EmbeddingService::KIND_DOCUMENT] as $kind) {
                    $this->assertSame('texte', EmbeddingService::withTaskPrefix($info, $kind, 'texte'), "$provider/$model");
                }
            }
        }
    }

    public function testAucunTaskTypeNePartEtLaQuestionEstMarquee(): void
    {
        $service = (string)file_get_contents(ROOT . '/src/RAG/EmbeddingService.php');
        // Un `taskType` ferait refuser chaque requête par gemini-embedding-2.
        $code = '';
        foreach (token_get_all($service) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        $this->assertStringNotContainsStringIgnoringCase('tasktype', $code);
        $this->assertStringNotContainsStringIgnoringCase('task_type', $code);
        // Le préfixe est posé avant les lots, donc aussi pour l'estimation.
        $this->assertMatchesRegularExpression('/function embedBatchWithUsage\([^)]*string \$kind = self::KIND_DOCUMENT\)/', $service);
        $this->assertStringContainsString('self::withTaskPrefix($modelEntry, $kind, (string)$t)', $service);

        // La question d'un tour part comme une QUESTION, les extraits comme
        // des documents (valeur par défaut).
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression('/EmbeddingService::embedOneWithUsage\(\s*[^;]*\$query,\s*EmbeddingService::KIND_QUERY\s*\)/s', $chat);
        $doc = (string)file_get_contents(ROOT . '/src/Models/KnowledgeBaseDocument.php');
        $this->assertStringNotContainsString('KIND_QUERY', $doc);
    }
}
