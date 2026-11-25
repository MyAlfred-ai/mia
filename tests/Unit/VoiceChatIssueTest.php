<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * La conversation vocale face à l'IA vocale choisie par l'administrateur.
 *
 * Elle écoute et lit par OpenAI, quel que soit le moteur de la dictée :
 * Voxtral seul ne suffit pas. Le bouton disparaissait alors sans un mot, à côté
 * d'un micro qui marche. Il se montre désormais avec une pastille rouge et la
 * raison (SpeechSynthesizer::issueFrom, source unique).
 */
final class VoiceChatIssueTest extends TestCase
{
    private const OPENAI  = Dictation::ENGINE_OPENAI;
    private const MISTRAL = Dictation::ENGINE_MISTRAL;

    protected function tearDown(): void
    {
        I18n::use('fr');
        ProviderIncident::useMemoryStore();
    }

    private static function src(string $relatif): string
    {
        return (string)file_get_contents(ROOT . '/' . $relatif);
    }

    // ------------------------------------------------------------------
    // Le diagnostic
    // ------------------------------------------------------------------

    public function testAvecUneCleOpenAiLaConversationMarcheQuelQueSoitLeMoteur(): void
    {
        $this->assertNull(SpeechSynthesizer::issueFrom(self::OPENAI, true, null, false));
        $this->assertNull(SpeechSynthesizer::issueFrom(self::MISTRAL, true, null, false), 'Voxtral + clé OpenAI : elle marche, par OpenAI');
    }

    public function testVoxtralSansCleOpenAiBloqueEtDitPourquoi(): void
    {
        $issue = SpeechSynthesizer::issueFrom(self::MISTRAL, false, null, false);
        $this->assertNotNull($issue);
        $this->assertSame(SpeechSynthesizer::ISSUE_VOXTRAL_ONLY, $issue['code']);
        $this->assertTrue($issue['blocking']);
        $this->assertSame('Conversation vocale indisponible', $issue['title']);
        $this->assertStringContainsString('Voxtral', $issue['message']);
        $this->assertStringContainsString('OpenAI', $issue['message']);
        $this->assertStringContainsString('Prévenez un administrateur', $issue['message']);
    }

    public function testQuiPeutReglerLaCauseEnLitLeRemede(): void
    {
        $issue = SpeechSynthesizer::issueFrom(self::MISTRAL, false, null, true);
        $this->assertStringContainsString('Modèles IA › Fournisseurs et modèles', $issue['message']);
        $this->assertStringNotContainsString('Prévenez un administrateur', $issue['message']);
    }

    public function testSansIaVocaleLeBoutonResteAbsentSansExplication(): void
    {
        // Instance sans OpenAI ni Voxtral : personne n'a choisi d'IA vocale, une
        // pastille rouge chez chaque utilisateur ne dirait rien d'utile.
        $this->assertNull(SpeechSynthesizer::issueFrom(null, false, null, false));
        $this->assertNull(SpeechSynthesizer::issueFrom(null, false, 'quota', true));
    }

    public function testUnCompteOpenAiRefuseSeSignaleSansBloquer(): void
    {
        foreach (['quota' => 'crédit', 'auth' => 'clé'] as $incident => $mot) {
            foreach ([self::OPENAI, self::MISTRAL] as $moteur) {
                $issue = SpeechSynthesizer::issueFrom($moteur, true, $incident, false);
                $this->assertNotNull($issue);
                $this->assertSame(SpeechSynthesizer::ISSUE_OPENAI_ACCOUNT, $issue['code']);
                // Le constat peut dater d'avant la recharge : on peut essayer.
                $this->assertFalse($issue['blocking']);
                $this->assertStringContainsString($mot, $issue['message']);
            }
        }
    }

    public function testLeConstatVientDesIncidentsDuCompte(): void
    {
        ProviderIncident::useMemoryStore(['openai' => ['kind' => 'quota', 'first' => time(), 'last' => time(), 'count' => 1]]);
        $this->assertSame(SpeechSynthesizer::ISSUE_OPENAI_ACCOUNT, SpeechSynthesizer::conversationIssue(self::OPENAI, true, false)['code'] ?? null);

        // Un incident chez un autre fournisseur ne la concerne pas.
        ProviderIncident::useMemoryStore(['anthropic' => ['kind' => 'quota', 'first' => time(), 'last' => time(), 'count' => 1]]);
        $this->assertNull(SpeechSynthesizer::conversationIssue(self::OPENAI, true, false));
    }

    public function testLesMessagesSontTraduitsSansJargon(): void
    {
        $cas = [
            [self::MISTRAL, false, null], [self::OPENAI, true, 'quota'], [self::OPENAI, true, 'auth'],
        ];
        foreach ([false, true] as $canFix) {
            foreach ($cas as [$moteur, $cle, $incident]) {
                I18n::use('fr');
                $fr = SpeechSynthesizer::issueFrom($moteur, $cle, $incident, $canFix);
                foreach (['instance', 'quota', ' API', 'HTTP'] as $jargon) {
                    $this->assertStringNotContainsString($jargon, $fr['message']);
                }
                I18n::use('en');
                $en = SpeechSynthesizer::issueFrom($moteur, $cle, $incident, $canFix);
                $this->assertNotSame($fr['message'], $en['message'], 'Message non traduit');
                $this->assertNotSame($fr['title'], $en['title'], 'Titre non traduit');
            }
        }
    }

    // ------------------------------------------------------------------
    // Le bouton du chat
    // ------------------------------------------------------------------

    /** Le vrai fragment du gabarit, rendu avec ces variables. */
    private static function renderButton(bool $voiceChatEnabled, bool $hasOpenaiKey, ?array $voiceChatIssue): string
    {
        $tpl   = self::src('templates/chat/index.php');
        $debut = strpos($tpl, '<?php if (!empty($voiceChatEnabled) && (!empty($hasOpenaiKey) || !empty($voiceChatIssue))): ?>');
        self::assertIsInt($debut);
        $fin = strpos($tpl, '<button type="submit" class="btn-send" id="sendBtn"', $debut);
        self::assertIsInt($fin);
        $fragment = substr($tpl, $debut, $fin - $debut);
        ob_start();
        eval('?>' . $fragment);
        return (string)ob_get_clean();
    }

    public function testLeBoutonOrdinaireNePorteNiPastilleNiBlocage(): void
    {
        $html = self::renderButton(true, true, null);
        $this->assertStringContainsString('id="voiceChatBtn"', $html);
        $this->assertStringContainsString('title="Converser à la voix"', $html);
        $this->assertStringNotContainsString('<span class="voice-issue-dot"', $html);
        $this->assertStringNotContainsString('data-voice-blocked', $html);
    }

    public function testVoxtralSeulMontreLeBoutonAvecSaPastilleEtSaRaison(): void
    {
        $issue = SpeechSynthesizer::issueFrom(self::MISTRAL, false, null, false);
        $html  = self::renderButton(true, false, $issue);
        $this->assertStringContainsString('id="voiceChatBtn"', $html);
        $this->assertStringContainsString('<span class="voice-issue-dot" aria-hidden="true"></span>', $html);
        $this->assertStringContainsString('class="btn-icon btn-voice is-blocked"', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('data-voice-blocked="1"', $html);
        $this->assertStringContainsString('data-issue-title="Conversation vocale indisponible"', $html);
        // La raison se lit au survol et part au script pour le clic ou le doigt.
        $this->assertSame(2, substr_count($html, 'Voxtral (Mistral) ne sert'), 'Raison dans title et data-issue-message');
    }

    public function testUnCompteRefuseGardeLeBoutonActifAvecSaPastille(): void
    {
        $issue = SpeechSynthesizer::issueFrom(self::OPENAI, true, 'quota', false);
        $html  = self::renderButton(true, true, $issue);
        $this->assertStringContainsString('<span class="voice-issue-dot"', $html);
        $this->assertStringNotContainsString('data-voice-blocked', $html, 'Le clic reste possible');
        $this->assertStringContainsString('crédit de votre organisation', $html);
    }

    public function testSansIaVocaleOuReglageCoupeRienNeSAffiche(): void
    {
        $this->assertStringNotContainsString('voiceChatBtn', self::renderButton(true, false, null));
        $this->assertStringNotContainsString('voiceChatBtn', self::renderButton(false, true, null));
        $this->assertStringNotContainsString('voiceChatBtn', self::renderButton(false, false, null));
    }

    public function testLesDeuxPagesDuChatCalculentLeDiagnostic(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($chat, "SpeechSynthesizer::conversationIssue(\$dictationEngine, \$hasOpenaiKey, Session::can('llms.edit'))"));
    }

    public function testBandeauEtOrdresVocauxExigentToujoursLaCle(): void
    {
        // Bloqué, le bouton ne lance rien : ni bandeau, ni module à charger.
        $tpl = self::src('templates/chat/index.php');
        $bandeau = strrpos(substr($tpl, 0, (int)strpos($tpl, 'id="voiceChatBar"')), '<?php if');
        $condition = '<?php if (!empty($hasOpenaiKey) && !empty($voiceChatEnabled)): ?>';
        $this->assertSame($condition, substr($tpl, $bandeau, strlen($condition)));
    }

    public function testLaPastilleEstRougeEtDessineeSurLeBouton(): void
    {
        $css = self::src('public/assets/css/main.css');
        $this->assertMatchesRegularExpression('/\.voice-issue-dot \{[^}]*background: #ef4444;/s', $css);
        $this->assertMatchesRegularExpression('/\.btn-voice \{ position: relative; \}/', $css);
        $this->assertStringContainsString('.btn-voice.is-blocked { cursor: help; }', $css);
    }

    /** Le VRAI script du bouton, exécuté sous node : bloqué, il ne tente rien. */
    public function testBloqueLeClicDitLaRaisonSansOuvrirDeSession(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $script = <<<'JS'
            const fs = require('fs');
            const chat = fs.readFileSync(process.argv[2] + '/public/assets/js/chat.js', 'utf8');
            const a = chat.indexOf('   Conversation vocale — écouter → envoyer → MIA répond → lire');
            const b = chat.indexOf("// ---- Cartes d'approbation Google Drive en attente", a);
            const bloc = chat.slice(a, b);
            const iife = bloc.slice(bloc.indexOf('(() => {'));
            const log = [];
            const listeners = {};
            const btn = {
                dataset: { voiceBlocked: '1', issueTitle: 'Titre', issueMessage: 'Raison' },
                addEventListener(type, fn) { listeners[type] = fn; },
            };
            globalThis.window = globalThis;
            globalThis.document = { getElementById: (id) => (id === 'voiceChatBtn' ? btn : null) };
            globalThis.showToast = (msg, kind, opts) => log.push(['toast', msg, kind, opts.title]);
            globalThis.createRealtimeTranscriber = () => { log.push(['session']); return {}; };
            globalThis.Audio = class { constructor() { log.push(['audio']); } };
            (0, eval)(iife);
            listeners.click();
            process.stdout.write(JSON.stringify({ log, stop: typeof globalThis.stopVoiceChat }));
            JS;
        $fichier = tempnam(sys_get_temp_dir(), 'mia-voice-issue-');
        file_put_contents($fichier, $script);
        try {
            $sortie = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' ' . escapeshellarg(ROOT) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $res = json_decode((string)$sortie, true);
        $this->assertIsArray($res, (string)$sortie);
        $this->assertSame([['toast', 'Raison', 'error', 'Titre']], $res['log']);
        $this->assertSame('undefined', $res['stop'], 'Rien à couper pour la dictée');
    }

    public function testLeTitreDuBoutonRevientAvecSaRaisonApresUneConversation(): void
    {
        $chat = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('const idleTitle = btn.title;', $chat);
        $this->assertStringContainsString("btn.title = on ? t('Terminer la conversation vocale') : idleTitle;", $chat);
    }

    // ------------------------------------------------------------------
    // L'administration
    // ------------------------------------------------------------------

    public function testLaCarteDitLeMemeDiagnosticEtOuPartLaVoix(): void
    {
        $tpl = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('SpeechSynthesizer::conversationIssue($micEffective, $hasOpenAiKey, true)', $tpl);
        $this->assertStringContainsString('has-voice-issue', $tpl);
        $this->assertStringContainsString('<span class="voice-issue-dot"></span>', $tpl);
        $this->assertStringContainsString("htmlspecialchars(\$voiceChatIssue['message'])", $tpl);
        // Voxtral + clé OpenAI : pas de panne, mais l'audio part chez OpenAI, et ça se dit.
        $this->assertStringContainsString('donc la voix de l\\\'utilisateur part chez OpenAI', $tpl);
    }

    public function testLePointAReglerConnaitLeMoteurEnVigueur(): void
    {
        $source = self::src('src/Helpers/AdminAttention.php');
        $this->assertStringContainsString('=== Dictation::ENGINE_MISTRAL', $source);
    }

    public function testLeMoteurDeLaDicteeNeTouchePasLaRouteDeLaConversation(): void
    {
        $chat  = self::src('src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function realtimeTranscriptionToken(): void');
        $this->assertIsInt($debut);
        $corps = substr($chat, $debut, (int)strpos($chat, 'jsonVoiceSessionError($cause, 502)', $debut) - $debut);
        $this->assertStringNotContainsString('Dictation::', $corps);
        $this->assertStringContainsString("ApiKey::getDecrypted('openai')", $corps);
    }

    public function testLaDocDitLaRegle(): void
    {
        foreach (['docs/GUIDE-ADMIN.md', 'docs/user/admin/outils/regler-la-conversation-vocale.md', 'docs/user/fonctionnalites/conversations/converser-a-la-voix.md', 'docs/GUIDE-UTILISATEUR.md'] as $doc) {
            $this->assertStringContainsString('pastille rouge', self::src($doc), $doc);
        }
    }

    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '') {
            return $explicite;
        }
        $trouve = trim((string)shell_exec('command -v node 2>/dev/null'));
        return $trouve !== '' ? $trouve : null;
    }
}
