<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Markdown rédigé livré dans un fence ```md/```markdown : rendu, jamais
 * affiché en code source.
 *
 * Le filet historique (`unwrapWholeMarkdownFence`) ne couvrait que l'enveloppe
 * TOTALE de la réponse. Or les modèles encadrent aussi un PASSAGE au milieu
 * (« Proposition de texte : ```md … ``` Si vous voulez… ») — affiché en bloc
 * de code tronqué avec scroll horizontal, illisible. Deux niveaux :
 *
 *  1. front : `unwrapMarkdownFences` (chat.js) déballe tout fence md/markdown,
 *     partiel ou total, par parcours LIGNE À LIGNE avec état — un « ```md »
 *     à l'intérieur d'un bloc d'une autre langue n'est jamais pris pour une
 *     ouverture (une regex globale s'y ferait piéger), fermeture optionnelle
 *     (streaming) ;
 *  2. serveur : `capabilityGuardrails()` interdit le fence markdown même
 *     partiel et donne l'échappatoire ```text pour la source brute demandée
 *     explicitement — c'est CE qui préserve le cas légitime « montre-moi la
 *     syntaxe » malgré le déballage systématique côté front.
 *
 * Pas de moteur JS dans la suite PHP : on verrouille le câblage et les
 * invariants structurels de la fonction (les comportements ont été validés
 * hors suite sur les 8 scénarios — partiel, total, streaming, autres
 * langues, fence interne, multiples).
 */
final class MarkdownFenceRenderingTest extends TestCase
{
    private static function chatJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    public function testLeFiletFrontDeballeAussiLesFencesPartiels(): void
    {
        $js = self::chatJs();

        // La fonction généralisée existe et l'ancienne (enveloppe totale
        // seulement) a disparu — deux filets parallèles divergeraient.
        $this->assertStringContainsString('function unwrapMarkdownFences(text)', $js);
        $this->assertStringNotContainsString('unwrapWholeMarkdownFence', $js);

        // Machine à états ligne à ligne : les deux états doivent exister —
        // sans `inOther`, un « ```md » cité DANS un bloc de code d'une autre
        // langue serait déballé à tort et casserait tout le rendu suivant.
        $corps = $this->extraire($js, 'function unwrapMarkdownFences(text)', "\nfunction ");
        $this->assertStringContainsString('inMd', $corps);
        $this->assertStringContainsString('inOther', $corps);
        $this->assertStringContainsString("lang === 'md' || lang === 'markdown'", $corps);
    }

    public function testRenderMarkdownPasseParLeFilet(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString(
            'marked.parse(unwrapMarkdownFences(stripChatgptCitationMarkers(text)))',
            $js,
            'Le filet doit opérer sur le texte complet à chaque rendu : streaming (fence non fermé) ET messages stockés.'
        );
    }

    public function testLaConsigneServeurInterditLeFencePartielEtDonneLEchappatoire(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $debut = strpos($src, 'function capabilityGuardrails');
        $this->assertNotFalse($debut);
        $consigne = substr($src, $debut, 4000);

        // Plus seulement « réponse entière » : le passage au milieu est
        // explicitement couvert.
        $this->assertStringContainsString('ni un passage au milieu', $consigne);
        // L'échappatoire ```text — sans lui, interdire ```markdown priverait
        // l'utilisateur qui demande la SOURCE brute (le front déballe
        // systématiquement md/markdown, text n'est jamais touché).
        $this->assertStringContainsString('```text', $consigne);
    }

    /** Extrait la portion de source entre deux marqueurs (bornes exclues). */
    private function extraire(string $src, string $de, string $a): string
    {
        $debut = strpos($src, $de);
        $this->assertNotFalse($debut, 'Marqueur introuvable : ' . $de);
        $fin = strpos($src, $a, $debut + strlen($de));
        $this->assertNotFalse($fin, 'Marqueur introuvable : ' . $a);
        return substr($src, $debut, $fin - $debut);
    }
}
