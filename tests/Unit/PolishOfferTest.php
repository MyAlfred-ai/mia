<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Version soignée » proposée AVANT d'écrire le fichier (PolishOffer) : la
 * demande d'un PDF, d'un document Word ou d'une présentation fait proposer le
 * choix en boutons ; « Version soignée » choisie, le fichier écrit part
 * aussitôt chez le modèle — produit une fois, pas deux.
 */
final class PolishOfferTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testUneDemandeDeFichierEstReconnue(): void
    {
        $cas = [
            'Fais-moi un rapport PDF sur nos ventes'                    => 'pdf',
            'Peux-tu me préparer ce compte rendu en PDF ?'               => 'pdf',
            'Génère une note au format PDF'                              => 'pdf',
            'Rédige le contrat en Word'                                  => 'docx',
            'Crée un document Word avec le planning'                     => 'docx',
            'Exporte ça en docx'                                         => 'docx',
            'Fais une présentation PowerPoint de 8 slides'               => 'pptx',
            'Can you make a PDF report of the meeting?'                  => 'pdf',
            'Write a Word document with the agenda'                      => 'docx',
            'I need a PowerPoint for Monday'                             => 'pptx',
        ];
        foreach ($cas as $message => $genre) {
            $this->assertSame($genre, PolishOffer::requestedKind($message), $message);
        }
    }

    /** Parler d'un fichier n'est pas en demander un. */
    public function testParlerDUnFichierNeDeclenchePas(): void
    {
        foreach ([
            'Résume ce PDF',
            'Que dit le document Word joint ?',
            'Lis le pdf et donne-moi les dates clés',
            'Merci pour le PDF !',
            'Combien de pages fait ce fichier ?',
            'Fais-moi un résumé en trois points',
            '',
        ] as $message) {
            $this->assertNull(PolishOffer::requestedKind($message), $message);
        }
    }

    public function testLeChoixEstLuDansLeMessage(): void
    {
        $this->assertSame(PolishOffer::POLISH, PolishOffer::choiceIn('Version soignée'));
        $this->assertSame(PolishOffer::POLISH, PolishOffer::choiceIn('fais-moi un PDF en version soignée'));
        $this->assertSame(PolishOffer::POLISH, PolishOffer::choiceIn('Polished version'));
        $this->assertSame(PolishOffer::QUICK, PolishOffer::choiceIn('Version rapide'));
        $this->assertSame(PolishOffer::QUICK, PolishOffer::choiceIn('Quick version please'));
        $this->assertNull(PolishOffer::choiceIn('Pas la version soignée, la version rapide'), 'Les deux à la fois ne disent rien.');
        $this->assertNull(PolishOffer::choiceIn('Fais un PDF'));
    }

    public function testPremierFichierDUneReponse(): void
    {
        $reply = "Voici le document :\n\n````markdown fichier=\"rapport-2026.pdf\"\n# Rapport\n\n```json\n{\"a\": 1}\n```\n\nFin.\n````\n\nBonne lecture.";
        $this->assertSame(
            ['name' => 'rapport-2026.pdf', 'language' => 'markdown', 'source' => "# Rapport\n\n```json\n{\"a\": 1}\n```\n\nFin."],
            PolishOffer::firstFile($reply),
            'Quatre accents graves : le code du document ne ferme pas le fichier.'
        );
        $this->assertSame('plan.docx', PolishOffer::firstFile("```md file='plan.docx'\n# Plan\n```")['name'] ?? null);
        $this->assertSame('budget.xlsx', PolishOffer::firstFile("```csv filename=budget.xlsx\na;b\n```")['name'] ?? null);
        $this->assertNull(PolishOffer::firstFile("```python\nprint(1)\n```"), 'Un bloc sans nom n’est pas un fichier.');
        $this->assertNull(PolishOffer::firstFile("```markdown fichier=\"x.pdf\"\n# pas fini"), 'Un fichier non refermé ne part pas.');
    }

    public function testLOffreNeRevientPasAChaqueRetouche(): void
    {
        $offre = ['role' => 'assistant', 'content' => "Je vous propose :\n```choix\nQuelle version voulez-vous ?\n- Version rapide — tout de suite\n- Version soignée — par GPT\n```"];
        $fichier = ['role' => 'assistant', 'content' => "```markdown fichier=\"a.pdf\"\n# A\n```"];
        $neutre = ['role' => 'assistant', 'content' => 'Bonjour !'];
        $question = ['role' => 'user', 'content' => 'Version soignée'];

        $this->assertFalse(PolishOffer::alreadyHandled([]));
        $this->assertFalse(PolishOffer::alreadyHandled([$neutre, $question]));
        $this->assertTrue(PolishOffer::alreadyHandled([$offre, $question]));
        $this->assertTrue(PolishOffer::alreadyHandled([$fichier, $neutre]));
    }

    /** Les libellés que l'offre fait recopier sont ceux que choiceIn reconnaît — en français et en anglais. */
    public function testLOffreEnseigneDesLibellesReconnus(): void
    {
        foreach (['fr', 'en'] as $langue) {
            I18n::use($langue);
            $offre = PolishOffer::offerFragment('pdf', 'GPT-6 Luna', 40);
            $this->assertStringContainsString('GPT-6 Luna', $offre);
            $this->assertStringContainsString('40', $offre);
            $this->assertMatchesRegularExpression('/```choi(x|ces)\n/', $offre, $langue);

            $options = [];
            foreach (explode("\n", $offre) as $ligne) {
                if (preg_match('/^- (.+?) — /u', $ligne, $m) === 1) {
                    $options[] = PolishOffer::choiceIn($m[1]);
                }
            }
            $this->assertSame([PolishOffer::QUICK, PolishOffer::POLISH], $options, "Le libellé d'un bouton désigne sa version ($langue).");
            $this->assertStringContainsString('GPT-6 Luna', PolishOffer::chosenFragment('GPT-6 Luna'));
        }
        I18n::use('fr');
        $this->assertStringNotContainsString('crédits', PolishOffer::offerFragment('docx', 'Claude', null), 'Coût inconnu : rien n’est annoncé.');
    }

    /** Câblage : offre au prompt, lancement après la réponse, carte posée par le navigateur. */
    public function testCablage(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('[$polishLlm, $polishPrompt, $polishOffer] = $this->polishOfferFor($user, $profile ?: null, $llm, $content, $history);', $chat);
        $this->assertStringContainsString('$this->launchPolishedFile($user, $convId, $llm, $polishLlm, $fullResp, (int)$msg[\'id\'],', $chat);
        $this->assertStringContainsString("'native_runs' => \$nativeRuns,", $chat);
        // Un seul chemin de lancement : le bouton de la carte et la version choisie d'avance.
        $this->assertSame(2, substr_count($chat, '$this->launchNativeDeck('));
        $this->assertSame(1, substr_count($chat, "JobQueue::enqueue('native_deck'"));
        // Jamais sans quelqu'un devant l'écran : ni tâche programmée, ni workflow, ni voix.
        $this->assertMatchesRegularExpression('/if \(!\$unattended && !\$voiceMode\) \{\s+\[\$polishLlm, \$polishPrompt, \$polishOffer\]/', $chat);

        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('if (Array.isArray(json.native_runs)) {', $js);
        $this->assertStringContainsString("attachJob(String(run.job_id), { type: String(run.job_type || 'native_deck'), convId: cidStr });", $js);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Files/PolishOffer.php', self::src($f), $f);
        }
    }
}
