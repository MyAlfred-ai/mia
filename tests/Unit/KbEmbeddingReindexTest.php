<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Changer le modèle d'embedding d'une base — par « Réindexer ».
 *
 * Tout le monde le promettait (aide des champs de la fenêtre, guides,
 * commentaire de DefaultAssistant) et le contrôleur savait le faire, mais le
 * formulaire « Réindexer » de la carte n'envoyait aucun choix : le modèle
 * d'une base était figé pour de bon. Et quand un choix arrivait quand même,
 * un choix INVALIDE était remplacé en silence par le modèle par défaut — toute
 * la base re-vectorisée sur un modèle que personne n'avait choisi.
 */
final class KbEmbeddingReindexTest extends TestCase
{
    /** Fournisseurs « disponibles » (clé configurée), au format du catalogue. */
    private const DISPONIBLES = [
        'openai'  => ['models' => [
            'text-embedding-3-large' => ['dims' => 1536],
            'text-embedding-3-small' => ['dims' => 1536],
        ]],
        'mistral' => ['models' => ['mistral-embed' => ['dims' => 1024]]],
    ];

    public function testUnChoixUtilisableEstResolu(): void
    {
        $this->assertSame(
            ['provider' => 'mistral', 'model' => 'mistral-embed', 'dims' => 1024],
            EmbeddingService::resolveChoice(' mistral::mistral-embed ', self::DISPONIBLES)
        );
    }

    /** @return array<string, array{string}> */
    public static function choixInutilisables(): array
    {
        return [
            'vide'                        => [''],
            'sans séparateur'             => ['openai'],
            'fournisseur sans clé'        => ['gemini::gemini-embedding-001'],
            'modèle hors catalogue'       => ['openai::text-embedding-ada-002'],
            'fournisseur vide'            => ['::mistral-embed'],
            'modèle vide'                 => ['mistral::'],
        ];
    }

    #[DataProvider('choixInutilisables')]
    public function testUnChoixInutilisableNeDesigneRien(string $saisie): void
    {
        $this->assertNull(EmbeddingService::resolveChoice($saisie, self::DISPONIBLES));
    }

    public function testLaReindexationRefuseUnChoixInvalideAuLieuDeLeRemplacer(): void
    {
        $corps = $this->corps(AdminController::class, 'reindexKnowledgeBase');

        $this->assertStringContainsString('EmbeddingService::resolveChoice(', $corps);
        // Le résolveur de la CRÉATION retombe sur le défaut : jamais ici.
        $this->assertStringNotContainsString('resolveEmbeddingChoiceFromRequest', $corps);
        $this->assertStringNotContainsString('defaultChoice', $corps);
        // Le refus s'annonce et n'enfile aucune réindexation.
        $this->assertMatchesRegularExpression(
            "/=== null\)\s*\{\s*Session::flash\('error'.*?exit;/s",
            $corps,
            'Un choix indisponible doit être refusé avec un message, avant tout dispatch.'
        );
    }

    /**
     * La réindexation vit dans la section « Avancé » de la fiche : le choix du
     * modèle accompagne le bouton (plus de fenêtre), le modèle actuel est
     * présélectionné, et rien ne part sans confirmation.
     */
    public function testLaFicheOffreLeChoixDuModele(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/knowledge-bases/edit.php');

        // Le formulaire porte le champ que lit le contrôleur, dans « Avancé ».
        $this->assertMatchesRegularExpression(
            '/id="avance".*?id="kbReindexForm".*?<select name="embedding_choice" id="kbReindexChoice"/s',
            $gabarit
        );
        // Le modèle actuel est déclaré et présélectionné.
        $this->assertStringContainsString('data-current="<?= htmlspecialchars($embCurrent) ?>"', $gabarit);
        $this->assertStringContainsString("\$optValue === \$embCurrent ? ' selected' : ''", $gabarit);
        // Le contrôleur ramène dans « Avancé » après la réindexation.
        $ctrl = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("'/admin/knowledge-bases/' . \$kbId . '/edit#avance'", $ctrl);

        // Le plan (même modèle ou changement) s'affiche, et le clic confirme.
        $js = (string)file_get_contents(ROOT . '/public/assets/js/kb-form.js');
        $this->assertStringContainsString('function reindexPlan()', $js);
        $this->assertMatchesRegularExpression("/reForm\?\.addEventListener\('submit'.*?if \(!confirm\(what\)\) e\.preventDefault\(\);/s", $js);
    }

    public function testLaDocumentationDitCommentChangerDeModele(): void
    {
        foreach ([
            'docs/user/admin/bases-de-connaissances/administrer-les-bases.md',
            'docs/user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md',
        ] as $page) {
            $texte = (string)file_get_contents(ROOT . '/' . $page);
            $this->assertStringContainsString('Modèle d\'embedding', $texte, $page);
            $this->assertStringNotContainsString('il ne se change plus ensuite', $texte, $page);
        }
    }

    private function corps(string $classe, string $methode): string
    {
        require_once ROOT . '/src/Controllers/AdminController.php';
        $m      = new ReflectionMethod($classe, $methode);
        $lignes = file((string)$m->getFileName()) ?: [];
        return implode('', array_slice(
            $lignes,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));
    }
}
