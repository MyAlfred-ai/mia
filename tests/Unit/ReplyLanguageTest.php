<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MIA écrit dans la langue CHOISIE par la personne.
 *
 * Tout ce que le modèle lit avant la question est rédigé en français
 * (consignes de MIA, prompts d'assistants, guides) : sans consigne explicite,
 * une personne qui a choisi l'anglais recevait des réponses, des titres de
 * conversation et des tâches ou workflows préparés en français. Ce test garde
 * les quatre points où la langue est imposée :
 *   1. les réponses (envoi, régénération, tâche programmée, étape de workflow) ;
 *   2. le titre résumé d'une conversation (historique) ;
 *   3. ce que MIA prépare (tâches, workflows, assistants, pages web, cartes) ;
 *   4. la langue d'exécution d'un job, prise à l'écran qui l'a lancé.
 */
final class ReplyLanguageTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function lire(string $chemin): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    public function testLeNomDeLaLangueDuPromptSuitLaLocale(): void
    {
        $this->assertSame('français', I18n::promptLanguage('fr'));
        $this->assertSame('anglais', I18n::promptLanguage('en'));
        // Code inconnu : repli sur le français, jamais d'exception.
        $this->assertSame('français', I18n::promptLanguage('xx'));

        I18n::use('en');
        $this->assertSame('anglais', I18n::promptLanguage());
    }

    public function testLaConsigneDeLangueDitParDefautEtLaisseLaMainALUtilisateur(): void
    {
        $en = I18n::replyLanguageNote('en');
        $this->assertStringContainsString('réponds en anglais par défaut', $en);
        // Ce que MIA prépare suit la même langue.
        $this->assertStringContainsString('rédige aussi en anglais', $en);
        $this->assertStringContainsString('workflows', $en);
        // « Par défaut » : une demande ou un message dans une autre langue l'emporte.
        $this->assertStringContainsString('te le demande', $en);
        $this->assertStringContainsString('dans une autre langue', $en);

        $this->assertStringContainsString('réponds en français par défaut', I18n::replyLanguageNote('fr'));
    }

    public function testLaConsigneEstAjouteeAuxDeuxCheminsDeReponse(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');

        // Envoi (runSend : chat, tâche programmée, étape de workflow).
        $this->assertMatchesRegularExpression(
            '/\$systemPrompt \.= self::nowLine\(\$timeZone\);\s*(?:\/\/[^\n]*\n\s*)*\$systemPrompt \.= I18n::replyLanguageNote\(\);/',
            $chat,
            'runSend doit poser la consigne de langue juste après la date.'
        );
        // Régénération.
        $this->assertMatchesRegularExpression(
            '/self::nowLine\(self::sanitizeTimeZone\([^\n]*\n\s*\$systemPrompt \.= I18n::replyLanguageNote\(\);/',
            $chat,
            'regenerate doit poser la consigne de langue juste après la date.'
        );
    }

    public function testLeTitreDeConversationEstRedigeDansLaLangueChoisie(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $debut = strpos($chat, 'private function generateConversationTitle(');
        $this->assertNotFalse($debut);
        $corps = substr($chat, $debut, 2000);

        $this->assertStringContainsString('Rédige-le en " . I18n::promptLanguage()', $corps);
        $this->assertStringContainsString('quelle que soit la langue', $corps);
        // Un préfixe anglais est retiré comme le préfixe français.
        $this->assertStringContainsString('(titre|title|sujet|topic)', $chat);
    }

    public function testLeTitreParDefautSAfficheDansLaLangueDuLecteur(): void
    {
        $this->assertSame('Nouvelle conversation', Conversation::DEFAULT_TITLE);
        $this->assertSame('Nouvelle conversation', Conversation::displayTitle(Conversation::DEFAULT_TITLE));
        $this->assertSame('Conversation', Conversation::displayTitle(''));
        $this->assertSame('Mon titre', Conversation::displayTitle('Mon titre'));

        I18n::use('en');
        $this->assertSame('New conversation', Conversation::displayTitle(Conversation::DEFAULT_TITLE));
        // Un titre rédigé est du contenu : jamais retraduit.
        $this->assertSame('Point budget', Conversation::displayTitle('Point budget'));

        foreach (['templates/chat/index.php', 'templates/partials/chat-shell-sidebar.php'] as $gabarit) {
            $this->assertStringContainsString('Conversation::displayTitle($conv[\'title\'] ?? null)', self::lire($gabarit), $gabarit);
        }
    }

    public function testUnJobParleLaLangueDeLEcranQuiLaLance(): void
    {
        $fr = ['id' => 1, 'lang' => 'fr'];
        $en = ['id' => 2, 'lang' => 'en'];

        // La langue de l'écran (charge utile) prime : l'interface peut suivre le
        // navigateur sans choix enregistré, que le worker ne connaît pas.
        $this->assertSame('en', JobQueue::localeFor($fr, [JobQueue::LOCALE_KEY => 'en']));
        $this->assertSame('fr', JobQueue::localeFor($en, [JobQueue::LOCALE_KEY => 'fr']));
        // Sans elle (planificateur, suite d'un workflow), la langue du compte.
        $this->assertSame('en', JobQueue::localeFor($en, []));
        $this->assertSame('fr', JobQueue::localeFor($fr, [JobQueue::LOCALE_KEY => 'xx']));

        $queue = self::lire('src/Jobs/JobQueue.php');
        $this->assertStringContainsString('I18n::use(self::localeFor($user, $payload));', $queue);
        $this->assertStringContainsString('$payload[self::LOCALE_KEY] = I18n::locale();', $queue);
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $queue);
    }

    public function testCeQueMiaPrepareEstRedigeDansLaLangueChoisie(): void
    {
        I18n::use('en');
        $guide = WorkflowDraft::schemaGuide([]);
        $this->assertStringContainsString('LANGUE : rédige le nom', $guide);
        $this->assertStringContainsString('en anglais', $guide);

        $sources = [
            'src/Workflow/WorkflowDraft.php'      => 2,  // forme partagée + « Décrire à MIA »
            'src/Workflow/WorkflowAi.php'         => 3,  // condition, décision, repérage
            'src/Agent/Tools/ScheduleTools.php'   => 3,  // consigne, nom, consigne de l'outil
            'src/Agent/Tools/AssistantTools.php'  => 1,
            'src/Jobs/HtmlPageJob.php'            => 1,
        ];
        foreach ($sources as $chemin => $min) {
            $this->assertGreaterThanOrEqual(
                $min,
                substr_count(self::lire($chemin), 'I18n::promptLanguage()'),
                "$chemin : la langue de l'utilisateur doit être imposée à ce que MIA rédige."
            );
            $this->assertStringNotContainsString('en français.";', self::lire($chemin), $chemin);
        }
        $this->assertStringNotContainsString(
            'des consignes précises en français',
            self::lire('src/Workflow/WorkflowDraft.php')
        );
    }

    public function testLAssistantDAideNImposePlusLeFrancais(): void
    {
        $aide = self::lire('src/Models/DefaultAssistant.php');
        $this->assertStringNotContainsString('- Réponds en français', $aide);
        $this->assertStringContainsString('Réponds dans la langue de l\'utilisateur', $aide);
        // Le prompt entre dans la signature : une consigne retouchée atteint
        // les instances déjà semées sans attendre un changement de guide.
        $this->assertStringContainsString("'prompt:' . hash('sha256', self::SYSTEM_PROMPT)", $aide);
    }
}
