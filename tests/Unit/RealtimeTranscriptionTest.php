<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Dictée vocale — session de transcription OpenAI Realtime.
 *
 * Panne corrigée le 2026-08-09, et elle était MUETTE : la session s'ouvrait,
 * le micro passait au rouge, aucune erreur n'était levée… et aucun texte
 * n'arrivait jamais. Deux causes cumulées :
 *
 *   1. La session était ouverte en `type: realtime` (conversationnelle). Dans
 *      ce mode la transcription est un à-côté des items de conversation, et un
 *      item n'existe que lorsqu'un tour audio est validé.
 *   2. `turn_detection` valait `null` (VAD désactivée) alors que personne
 *      n'envoyait `input_audio_buffer.commit` côté client. Aucun tour n'était
 *      donc jamais validé.
 *
 * Ces tests verrouillent l'invariant qui rend la dictée structurellement
 * impossible à rendre muette : soit le modèle streame de lui-même (et refuse
 * la détection de tour), soit c'est la VAD SERVEUR qui valide les tours.
 * Jamais le client.
 */
final class RealtimeTranscriptionTest extends TestCase
{
    /**
     * Modèles refusant toute détection de tour — l'API répond HTTP 400
     * « Turn detection is not supported for this transcription model ».
     * Ils émettent des deltas au fil de la parole : c'est leur mode normal.
     */
    private const MODELES_LIVE = ['gpt-live-transcribe', 'gpt-realtime-whisper'];

    /** Liste renvoyée par l'API elle-même sur un nom de modèle invalide. */
    private const MODELES_SUPPORTES = [
        'whisper-1', 'gpt-realtime-whisper', 'gpt-live-transcribe', 'gpt-transcribe',
        'gpt-4o-transcribe', 'gpt-4o-mini-transcribe',
        'gpt-4o-mini-transcribe-2025-03-20', 'gpt-4o-mini-transcribe-2025-12-15',
    ];

    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    /** @return array<string, array|null> */
    private static function carte(): array
    {
        $carte = (new ReflectionClass(ChatController::class))->getConstant('TRANSCRIPTION_MODELS');
        self::assertIsArray($carte, 'ChatController::TRANSCRIPTION_MODELS attendue');
        self::assertNotEmpty($carte);
        return $carte;
    }

    private static function methodeSource(): string
    {
        $src = file_get_contents(ROOT . '/src/Controllers/ChatController.php') ?: '';
        $debut = strpos($src, 'public function realtimeTranscriptionToken');
        self::assertIsInt($debut, 'Méthode realtimeTranscriptionToken introuvable');
        return substr($src, (int)$debut, 4000);
    }

    public function testTousLesModelesSontConnusDeLApi(): void
    {
        foreach (array_keys(self::carte()) as $model) {
            $this->assertContains(
                $model,
                self::MODELES_SUPPORTES,
                "Modèle « $model » absent de la liste supportée par l'API : la session serait refusée en 400"
            );
        }
    }

    public function testLesModelesLiveNAcceptentAucuneDetectionDeTour(): void
    {
        $carte = self::carte();
        foreach (self::MODELES_LIVE as $model) {
            if (!array_key_exists($model, $carte)) {
                continue; // modèle retiré de la chaîne : rien à garder
            }
            $this->assertNull(
                $carte[$model],
                "« $model » refuse toute détection de tour (HTTP 400) — sa valeur doit rester null"
            );
        }
    }

    public function testUnModeleParTourConfieLaValidationAuServeurJamaisAuClient(): void
    {
        // LE cœur du défaut : un modèle par tour avec turn_detection null
        // n'émet rien tant que le client ne commit pas — et le client ne
        // commit jamais. La VAD serveur est donc obligatoire.
        foreach (self::carte() as $model => $turnDetection) {
            if (in_array($model, self::MODELES_LIVE, true)) {
                continue;
            }
            $this->assertIsArray(
                $turnDetection,
                "« $model » attend un tour validé : sans VAD serveur, la dictée reste muette"
            );
            $this->assertSame('server_vad', $turnDetection['type'] ?? null, "« $model » : VAD serveur attendue");
        }
    }

    public function testLeModelePrefereEstUnModeleLive(): void
    {
        // Premier de la chaîne = celui qui sera retenu dans le cas nominal.
        // Un modèle live rend le texte au fil de la parole ; un modèle par
        // tour ne rendrait le texte que par bouffées, en fin de phrase.
        $premier = array_key_first(self::carte());
        $this->assertContains($premier, self::MODELES_LIVE, 'Le modèle préféré doit streamer les deltas');
    }

    public function testLaSessionEstDeTranscriptionEtNonConversationnelle(): void
    {
        $src = self::methodeSource();
        $this->assertStringContainsString("'type' => 'transcription'", $src);
        $this->assertStringNotContainsString(
            'output_modalities',
            $src,
            'Session conversationnelle : la transcription y dépend des items de conversation, donc d\'un tour validé'
        );
    }

    /**
     * Ouvrir une session de dictée engage une consommation (minutes d'audio
     * facturées à la fin) : elle est contrôlée comme toute opération IA —
     * quota AVANT la création de la session, et réglage de l'administrateur.
     * Avant : un compte au quota épuisé dictait sans limite, et un micro
     * désactivé en administration restait ouvert à qui appelait la route.
     */
    public function testLeQuotaEtLeReglageSontControlesAvantDOuvrirUneSession(): void
    {
        $src      = self::methodeSource();
        $session  = strpos($src, 'client_secrets');
        $quota    = strpos($src, 'isCreditQuotaExceeded(');
        $reglage  = strpos($src, "Settings::get('mic_enabled'");

        $this->assertIsInt($session);
        $this->assertIsInt($quota, 'Aucun contrôle de quota à l\'ouverture de la dictée.');
        $this->assertIsInt($reglage, 'La route ne vérifie pas que la dictée est activée.');
        $this->assertLessThan($session, $quota, 'Le quota doit être contrôlé AVANT la création de la session.');
        $this->assertLessThan($session, $reglage);
        $this->assertStringContainsString("'quota_exceeded' => true", $src);
    }

    public function testLeClientAfficheLeQuotaEtCoupeLeMicro(): void
    {
        $js    = file_get_contents(ROOT . '/public/assets/js/chat.js') ?: '';
        $debut = strpos($js, 'async function startRecording()');
        $this->assertIsInt($debut);
        $corps = substr($js, (int)$debut, 2500);

        // Même fenêtre « quota atteint » que l'envoi d'un message, et un micro
        // qui s'arrête — y compris sur une reconnexion en cours de dictée.
        $this->assertStringContainsString('handleQuotaExceededPayload(', $corps);
        $this->assertMatchesRegularExpression('/handleQuotaExceededPayload\([^)]*\)\)\s*\{[\s\S]{0,200}?stopRecording\(\)/', $corps);
    }

    public function testLeClientRendLeSilenceVisible(): void
    {
        // La panne n'a pas coûté cher parce qu'elle cassait, mais parce
        // qu'elle ne disait RIEN. Un garde-fou côté client doit signaler
        // l'absence de transcription au lieu de laisser le micro au rouge.
        $js = file_get_contents(ROOT . '/public/assets/js/chat.js') ?: '';
        $this->assertStringContainsString('armSilenceWatchdog', $js);
        $this->assertMatchesRegularExpression(
            '/armSilenceWatchdog[\s\S]{0,600}?showToast\(/',
            $js,
            'Le chien de garde du silence doit prévenir l\'utilisateur'
        );
    }
}
