<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Modifier les slides » : la présentation livrée dans le chat s'ouvre dans
 * l'éditeur du volet — et l'outil Présentation du menu « + », qu'elle
 * remplace, n'est plus proposé.
 *
 * Un seul chemin pour faire une présentation : la demander dans la
 * conversation. La carte offre ensuite le téléchargement, la retouche à
 * l'écran (ici), la réalisation par le modèle et Gamma.
 */
final class DeckFromCardTest extends TestCase
{
    private static function source(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** La spec est construite par le serveur depuis le texte, comme le PowerPoint de la carte. */
    public function testLaRouteConstruitLaPresentationEditableCommeLaCarte(): void
    {
        $this->assertStringContainsString(
            "\$router->post('/chat/{id}/deck-from-file', [ChatController::class, 'deckFromFile']);",
            self::source('public/index.php')
        );

        $code = self::source('src/Controllers/ChatController.php');
        $debut = strpos($code, 'public function deckFromFile(');
        $this->assertNotFalse($debut);
        $corps = substr($code, $debut, 3500);
        $this->assertStringContainsString('Conversation::belongsToUser($convId, $user[\'id\'])', $corps);
        $this->assertStringContainsString('MarkdownDeck::build(', $corps);
        $this->assertStringContainsString('CompanyProfile::brand()', $corps, 'Même habillage que le PowerPoint de la carte.');
        $this->assertStringContainsString('CompanyProfile::deckTheme()', $corps);
        $this->assertStringContainsString("[['type' => 'slide_deck', 'name' => 'presentation.json']]", $corps,
            'Une présentation ÉDITABLE : le message de l\'ancien outil, que le volet sait ouvrir.');
        $this->assertStringContainsString('AND content = ?', $corps, 'Deux clics sur la même carte rouvrent la même présentation.');
        $this->assertStringNotContainsString("\$_POST['deck']", $corps, 'La spec ne vient jamais du navigateur.');

        // La spec construite est bien une présentation que l'éditeur sait relire.
        $deck = MarkdownDeck::build(MarkdownDocument::parse("# Offre\n\nSous-titre\n\n## A\n\n- un\n- deux"), 'offre');
        $this->assertSame($deck, DeckSpec::decode(DeckSpec::encode($deck)));
    }

    /** La carte porte le bouton, et le volet s'ouvre dans l'éditeur. */
    public function testLaCarteOuvreLEditeur(): void
    {
        $js = self::source('public/assets/js/generated-files.js');
        $this->assertStringContainsString("(offersGamma(file) ? editDeckButtonHtml() : '')", $js);
        $this->assertStringContainsString("'/deck-from-file'", $js);
        $this->assertStringContainsString("target.closest('.file-edit-deck')", $js);
        $this->assertStringContainsString('root.Deck.openFromMessage(String(data.msg_id), { edit: true });', $js);
        $this->assertStringContainsString("root.appendMessage('assistant', JSON.stringify(data.deck), [{ type: 'slide_deck', name: 'presentation.json' }]", $js,
            'La présentation éditable rejoint le fil la première fois.');

        $deck = self::source('public/assets/js/deck.js');
        $this->assertStringContainsString('async function openFromMessage(msgId, opts)', $deck);
        $this->assertStringContainsString('opts && opts.edit', $deck);
        $this->assertStringContainsString('await setEditMode(true);', $deck);
        // Couleurs nommées des présentations du chat : résolues pour l'input couleur.
        $this->assertStringContainsString('function hexColor(value)', $deck);
        $this->assertStringContainsString("theme[v.replace(/-/g, '_')]", $deck);

        $css = self::source('public/assets/css/files.css');
        $this->assertStringContainsString('.file-edit-deck', $css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\)\s*\{[^}]*\.file-edit-deck[^}]*44px/s', $css);

        $client = require ROOT . '/lang/en.client.php';
        $this->assertSame('Edit slides', $client['Modifier les slides'] ?? null);
    }

    /** Les couleurs nommées que la spec porte existent dans le thème que l'éditeur lit. */
    public function testLesCouleursNommeesSeResolventDansLeTheme(): void
    {
        $theme = DeckSpec::normalizeTheme(['accent' => '#0055a4']);
        foreach (DeckSpec::TEXT_TOKENS as $token) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string)($theme[str_replace('-', '_', $token)] ?? ''), $token);
        }
    }

    /** L'outil Présentation a quitté le menu, la création et les suggestions. */
    public function testLOutilPresentationNEstPlusPropose(): void
    {
        $chat = self::source('templates/chat/index.php');
        $this->assertStringNotContainsString("\$renderToolItem(\$presTool, 'presentation')", $chat);

        $this->assertNull(ToolTriggers::ruleForType('presentation'));
        $this->assertStringNotContainsString("toolRule('presentation'", self::source('public/assets/js/tool-suggest.js'));

        $admin = self::source('templates/admin/tools.php');
        $this->assertStringContainsString('<label class="type-card is-disabled" data-t="presentation">', $admin);
        $this->assertStringContainsString('<input type="radio" name="type" value="presentation" disabled>', $admin);
        $this->assertStringContainsString("N'apparaît plus dans le chat", $admin, 'Un outil déjà créé le dit.');

        $prompt = FileDelivery::promptFragment();
        $this->assertStringContainsString('« Modifier les slides »', $prompt);
    }

    /** Les guides disent le nouveau chemin. */
    public function testLaDocumentationLeDit(): void
    {
        foreach (['docs/user/fonctionnalites/outils/creer-une-presentation.md', 'docs/user/fonctionnalites/conversations/telecharger-un-fichier.md',
                  'docs/GUIDE-UTILISATEUR.md', 'docs/NOUVEAUTES.md'] as $doc) {
            $this->assertStringContainsString('Modifier les slides', self::source($doc), $doc);
        }
    }
}
