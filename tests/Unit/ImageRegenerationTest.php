<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * « Regénérer » sous une image produite par l'outil image.
 *
 * Le bouton rejouait la demande à travers le LLM de la conversation, en flux
 * texte (ChatController::regenerate) : l'image disparaissait au profit d'une
 * réponse rédigée, alors que la personne attendait une autre version de son
 * image. La réponse stockée dit ce qu'il faut rejouer : une image de l'outil
 * image repart en génération d'image — même outil, même demande.
 */
final class ImageRegenerationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function reponses(): array
    {
        $img = '![Image générée](/assets/img/generated/img_abc123.png)';
        return [
            'image seule'                    => [['role' => 'assistant', 'content' => $img], true],
            'image + prompt enrichi'         => [['role' => 'assistant', 'content' => $img . "\n\n*Prompt enrichi : un chat roux, lumière du soir*"], true],
            'prompt enrichi sur deux lignes' => [['role' => 'assistant', 'content' => $img . "\n\n*Prompt enrichi : un chat roux,\nlumière du soir*"], true],
            'image dans de la prose'         => [['role' => 'assistant', 'content' => "Voici l'affiche :\n\n" . $img], false],
            'image hors dossier généré'      => [['role' => 'assistant', 'content' => '![x](https://exemple.test/a.png)'], false],
            'message utilisateur'            => [['role' => 'user', 'content' => $img], false],
            'texte'                          => [['role' => 'assistant', 'content' => 'Bonjour'], false],
        ];
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('reponses')]
    public function testSeuleUneReponseDeLOutilImageRepartEnImage(array $message, bool $attendu): void
    {
        $this->assertSame($attendu, Message::isImageToolAnswer($message));
    }

    public function testLOutilQuiAProduitLImageEstRetenu(): void
    {
        $reponse = ['llm_provider' => 'openai', 'llm_model' => 'gpt-image-2'];
        $outils  = [
            ['id' => 3, 'provider' => 'gemini', 'model' => 'gemini-3-pro-image'],
            ['id' => 5, 'provider' => 'openai', 'model' => 'gpt-image-2'],
            ['id' => 8, 'provider' => 'openai', 'model' => 'gpt-image-2'],
        ];

        // L'outil engagé dans le composer départage deux outils du même moteur…
        $this->assertSame(8, ChatController::pickRegenerationImageTool($reponse, $outils, 8)['id']);
        // … sans jamais l'emporter sur le moteur qui a produit l'image.
        $this->assertSame(5, ChatController::pickRegenerationImageTool($reponse, $outils, 3)['id']);
        $this->assertSame(5, ChatController::pickRegenerationImageTool($reponse, $outils, 0)['id']);
    }

    public function testSansLOutilDOrigineSeulLOutilEngageEstAccepte(): void
    {
        $reponse = ['llm_provider' => 'openai', 'llm_model' => 'dall-e-3'];
        $outils  = [['id' => 3, 'provider' => 'gemini', 'model' => 'gemini-3-pro-image']];

        $this->assertSame(3, ChatController::pickRegenerationImageTool($reponse, $outils, 3)['id']);
        // Rien d'engagé : aucun générateur n'est choisi en silence.
        $this->assertNull(ChatController::pickRegenerationImageTool($reponse, $outils, 0));
        $this->assertNull(ChatController::pickRegenerationImageTool($reponse, [], 3));
    }

    public function testRegenererRepartEnImageAvantDeSupprimerOuDeStreamer(): void
    {
        $corps = $this->corps('regenerate');
        $image = strpos($corps, 'Message::isImageToolAnswer(');
        $this->assertIsInt($image, 'regenerate() ne distingue pas une réponse de l\'outil image.');
        $this->assertLessThan(strpos($corps, 'Message::deleteLastAssistant('), $image);
        $this->assertLessThan(strpos($corps, "header('Content-Type: text/event-stream')"), $image);

        $regen = $this->corps('regenerateImage');
        $this->assertStringContainsString("JobQueue::enqueue(\n            'image'", $regen);
        $this->assertStringContainsString("'user_msg_id'", $regen);
        $this->assertStringContainsString('ChatTool::activeByUser(', $regen);
    }

    public function testLeBoutonAttacheLeJobImageAvantLeGardeDuFlux(): void
    {
        $js    = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $debut = strpos($js, "const regenBtn = e.target.closest('.btn-regen');");
        $this->assertIsInt($debut);
        $corps = substr($js, (int)$debut, 9000);

        $job   = strpos($corps, "attachJob(payload.job_id, { type: 'image'");
        $garde = strpos($corps, 'guardStreamResponse(response, newContent)');
        $this->assertIsInt($job, 'La réponse « job image » de /regenerate n\'est pas attachée.');
        $this->assertLessThan($garde, $job, 'Le garde du flux prendrait la réponse JSON pour une erreur.');
        // L'outil engagé part avec la demande : il départage deux outils du même moteur.
        $this->assertStringContainsString("selectedTool?.type === 'image'", $corps);
    }

    private function corps(string $methode): string
    {
        $m      = new ReflectionMethod(ChatController::class, $methode);
        $lignes = file((string)$m->getFileName()) ?: [];
        return implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }
}
