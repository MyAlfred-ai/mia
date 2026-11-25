<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Essai à la main d'un workflow lancé par un email : la fenêtre de lancement
 * propose les derniers emails de la boîte surveillée, et l'email choisi
 * devient la pièce « Email reçu » complète — exactement celle qu'aurait
 * remise la surveillance (de quoi répondre dans le fil). Constaté chez
 * Chez un client (2026-11-19) : lancé à la main sans rien coller, le workflow
 * démarrait sans pièce, et l'étape d'analyse n'avait rien à traiter.
 */
final class WorkflowEmailTrialTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function lire(string $chemin): string
    {
        return (string) file_get_contents(self::root() . '/' . $chemin);
    }

    private static function def(string $source = 'outlook'): array
    {
        return WorkflowSpec::normalize([
            'start' => ['event' => ['kind' => 'email', 'source' => $source, 'timezone' => 'Europe/Paris',
                'filters' => ['mail' => ['from' => '@client.fr']]]],
            'nodes' => [['key' => 'trier', 'type' => 'step', 'title' => 'Trier', 'instruction' => 'Trie @{evenement}.', 'output' => ['key' => 'tri', 'name' => 'Tri', 'form' => 'text']]],
            'end' => ['pieces' => ['tri']],
        ]);
    }

    /** Doublure d'une boîte : lit seulement les messages qu'elle connaît. */
    private static function boite(): object
    {
        return new class {
            public array $reads = [];
            public function canRead(): bool { return true; }
            public function readMessage(string $id, int $budget): array
            {
                $this->reads[] = $id;
                if ($id !== 'AAMk-x_1=') {
                    throw new \RuntimeException('Message introuvable.');
                }
                return ['de' => 'Paul Martin <paul@client.fr>', 'objet' => 'Re: Devis', 'date' => '19/11/2026 11:58',
                    'contenu' => 'Pouvez-vous confirmer ?', 'pieces_jointes' => [['nom' => 'devis.pdf']]];
            }
        };
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    public function testLEmailChoisiDevientLaPieceCompleteDeLEvenement(): void
    {
        $svc = self::boite();
        $content = WorkflowTriggers::chosenEmailEvent(self::def(), 1, 'AAMk-x_1=', $svc);
        $this->assertSame('AAMk-x_1=', $content['message_id'], 'L’identifiant du message : de quoi répondre dans son fil.');
        $this->assertSame('outlook', $content['service']);
        $this->assertSame('paul@client.fr', $content['fields']['expediteur']);
        $this->assertSame('Re: Devis', $content['fields']['objet']);
        $this->assertSame('2026-11-19', $content['fields']['recu_le']);
        $this->assertSame('Pouvez-vous confirmer ?', $content['fields']['contenu']);
        $this->assertSame(['devis.pdf'], $content['fields']['pieces_jointes']);
        $this->assertStringContainsString('pour un essai à la main', $content['summary']);
        $this->assertSame(['AAMk-x_1='], $svc->reads);
    }

    public function testUnEmailIllisibleOuUnIdentifiantInvalideEstRefuseAvecUneRaison(): void
    {
        try {
            WorkflowTriggers::chosenEmailEvent(self::def(), 1, 'autre', self::boite());
            $this->fail('Un email qui n’est pas dans la boîte ne se lance pas.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Message introuvable.', $e->getMessage());
        }
        foreach (['', "a\nb", 'a b', str_repeat('a', 600), '../x?y'] as $id) {
            try {
                WorkflowTriggers::chosenEmailEvent(self::def(), 1, $id, self::boite());
                $this->fail('Identifiant refusé : ' . $id);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Requête invalide.', $e->getMessage());
            }
        }
        // Un workflow qui ne démarre pas sur un email n'en prend pas.
        $this->expectException(\InvalidArgumentException::class);
        WorkflowTriggers::chosenEmailEvent(WorkflowSpec::normalize([]), 1, 'AAMk-x_1=', self::boite());
    }

    public function testLaListeProposeChaqueEmailSansEnCocherAucun(): void
    {
        $html = WorkflowTriggers::recentEmailsHtml(['items' => [
            ['id' => 'm1', 'de' => 'Paul <paul@client.fr>', 'objet' => '<b>Devis</b>', 'received' => 1763549880, 'match' => true, 'reason' => ''],
            ['id' => 'm2', 'de' => 'noreply@google.com', 'objet' => '', 'received' => null, 'match' => false, 'reason' => 'expéditeur différent'],
        ], 'matched' => 1], 'Outlook', 'Europe/Paris');
        $this->assertSame(2, substr_count($html, 'name="event_message"'));
        $this->assertStringContainsString('value="m1"', $html);
        $this->assertStringNotContainsString('checked', $html, 'Rien n’est choisi d’office : la personne désigne l’email.');
        $this->assertStringContainsString('&lt;b&gt;Devis&lt;/b&gt;', $html);
        $this->assertStringContainsString('Paul · ', $html);
        $this->assertMatchesRegularExpression('/11\\h+h\\h+58/u', $html, 'Heure dans le fuseau de la personne.');
        $this->assertStringContainsString('(sans objet)', $html);
        $this->assertStringContainsString('Ne lancerait pas le workflow : expéditeur différent', $html);
        $this->assertStringContainsString('Aucun email dans la boîte de réception Outlook.', WorkflowTriggers::recentEmailsHtml(['items' => []], 'Outlook', ''));

        I18n::use('en');
        $this->assertStringContainsString('Wouldn’t start the workflow', WorkflowTriggers::recentEmailsHtml(['items' => [
            ['id' => 'm2', 'de' => 'x@y.fr', 'objet' => 'A', 'received' => null, 'match' => false, 'reason' => 'r'],
        ]], 'Gmail', ''));
        I18n::use('fr');
    }

    public function testLeLancementEtLaFenetreSontCables(): void
    {
        $index = self::lire('public/index.php');
        $this->assertStringContainsString("\$router->get('/workflows/{id}/recent-emails',", $index);

        $ctl = self::lire('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('public function recentEmails(string $id): void', $ctl);
        $this->assertStringContainsString("\$_POST['event_message']", $ctl);
        $this->assertStringContainsString('WorkflowTriggers::chosenEmailEvent(', $ctl);
        $this->assertStringContainsString("'recent_url' =>", $ctl);
        $this->assertStringContainsString("'id' => (string)\$item['id']", self::lire('src/Workflow/WorkflowTriggers.php'));

        $js = self::lire('public/assets/js/workflows.js');
        $this->assertStringContainsString('function loadRecent()', $js);
        $this->assertStringContainsString('input[name="event_message"]:checked', $js);
        $this->assertStringContainsString('le workflow démarrera sans. Lancer quand même ?', $js);
        $this->assertStringContainsString('.wf-recent-item', self::lire('public/assets/css/workflows.css'));

        $this->assertStringContainsString('dix derniers emails', self::lire('docs/user/fonctionnalites/workflows/demarrer-sur-un-evenement.md'));
        $this->assertStringContainsString('workflow-essai-email-recent', self::lire('docs/NOUVEAUTES.md'));
    }
}
