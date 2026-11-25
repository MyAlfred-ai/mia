<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Champ « Choix » du départ d'un workflow.
 *
 * Le défaut payé : dans l'éditeur, quitter le champ « Options » déclenchait
 * l'évènement `change`, qui recopiait la ligne de texte (« Rouge, Vert ») à
 * la place de la LISTE des options. Le rendu suivant du volet échouait sur
 * `(f.options || []).join` — le volet restait figé sur la brique d'avant,
 * l'éditeur ne répondait plus — et le serveur, qui n'attend qu'une liste,
 * enregistrait un choix sans aucune option.
 */
final class WorkflowChoiceFieldTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function startWith(array $field): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [$field + ['key' => 'couleur', 'label' => 'Couleur', 'type' => 'choice', 'required' => true]]],
            'nodes' => [['type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['name' => 'R', 'form' => 'text']]],
        ]);
    }

    public function testLesOptionsRestentUneListe(): void
    {
        $f = self::startWith(['options' => ['Rouge', ' Vert ', 'Rouge', '', 'Bleu']])['start']['fields'][0];
        $this->assertSame(['Rouge', 'Vert', 'Bleu'], $f['options'], 'Options nettoyées, sans doublon ni vide.');
    }

    public function testUneLigneDOptionsEstLueCommeLaListeQuElleDecrit(): void
    {
        // Un onglet resté sur l'éditeur d'avant le correctif poste encore une chaîne.
        $f = self::startWith(['options' => 'Rouge, Vert, Bleu'])['start']['fields'][0];
        $this->assertSame(['Rouge', 'Vert', 'Bleu'], $f['options']);
        $issues = array_filter(WorkflowSpec::issues(self::startWith(['options' => 'Rouge, Vert']), []),
            static fn($i) => $i['target'] === 'field:couleur');
        $this->assertSame([], $issues, 'Deux options lues : rien à régler.');
    }

    public function testLaValeurParDefautEstUneDesOptions(): void
    {
        $ok = self::startWith(['options' => ['Rouge', 'Vert'], 'default' => 'Vert'])['start']['fields'][0];
        $this->assertSame('Vert', $ok['default']);
        $ko = self::startWith(['options' => ['Rouge', 'Vert'], 'default' => 'vert'])['start']['fields'][0];
        $this->assertSame('', $ko['default'], 'Une valeur hors des options serait refusée au lancement : elle est retirée.');
        $text = WorkflowSpec::normalize(['start' => ['fields' => [['key' => 't', 'label' => 'T', 'type' => 'text', 'default' => 'libre']]]]);
        $this->assertSame('libre', $text['start']['fields'][0]['default'], 'Un champ texte garde sa valeur libre.');
    }

    public function testLeLancementPrendLaValeurParDefaut(): void
    {
        $def = self::startWith(['options' => ['Rouge', 'Vert'], 'default' => 'Vert']);
        $this->assertSame('Vert', WorkflowEngine::validateInputs($def, [], [])['fields']['couleur']);
        $this->assertSame('Rouge', WorkflowEngine::validateInputs($def, ['couleur' => 'Rouge'], [])['fields']['couleur']);
        $this->expectException(InvalidArgumentException::class);
        WorkflowEngine::validateInputs($def, ['couleur' => 'Jaune'], []);
    }

    public function testLEditeurNeRemplacePlusLaListeParSaLigne(): void
    {
        $ed = (string)file_get_contents(ROOT . '/public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('function choiceOptions(f)', $ed);
        $this->assertStringContainsString('function parseChoiceOptions(text)', $ed);
        $this->assertStringNotContainsString('(f.options || []).join', $ed, 'Une chaîne n’a pas de join : le volet ne se dessinait plus.');
        $this->assertStringContainsString('choiceOptions(f).join(\', \')', $ed);

        // `change` ne touche plus qu'aux cases et aux listes : le champ de saisie est tenu par `input`.
        $this->assertStringContainsString("if (el.matches('[data-field]') && (el.type === 'checkbox' || el.tagName === 'SELECT')) {", $ed);
        $this->assertStringContainsString("if (el.matches('[data-field]')) { return; }", $ed);
        $this->assertStringContainsString('f.options = parseChoiceOptions(el.value);', $ed);

        // La valeur par défaut d'un Choix se choisit parmi ses options, et suit leurs changements.
        $this->assertStringContainsString('data-prop="default" aria-label="', $ed);
        $this->assertStringContainsString('function syncChoiceDefault(i)', $ed);
        $this->assertStringContainsString('syncChoiceDefault(fi);', $ed);

        $en = require ROOT . '/lang/en.client.php';
        $this->assertArrayHasKey('Aucune valeur par défaut', $en);
    }
}
