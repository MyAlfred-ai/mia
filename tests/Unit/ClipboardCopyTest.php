<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Copie d'une réponse : fond TOUJOURS transparent.
 *
 * Deux chemins mènent au presse-papiers — le bouton « Copier » et la copie
 * native (sélection + Ctrl+C). Le second laissait le navigateur sérialiser les
 * styles CALCULÉS des ancêtres, donc le `background-color` de la page : le
 * texte collé arrivait sur un fond gris. Ces tests gardent le CÂBLAGE de
 * chat.js (source unique de la neutralisation, interception de la copie
 * native), pas son exécution.
 */
final class ClipboardCopyTest extends TestCase
{
    private static function chatJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    public function testLaNeutralisationEstDeclareeUneSeuleFois(): void
    {
        $js = self::chatJs();

        preg_match_all('/function neutralizeClipboardStyles\(/', $js, $m);
        $this->assertCount(1, $m[0], 'neutralizeClipboardStyles doit être la source unique');

        // Deux règles divergentes donneraient deux collages différents pour le
        // même texte : les DEUX chemins passent par elle.
        $this->assertMatchesRegularExpression(
            '/function buildAssistantClipboardPayload\(.*?neutralizeClipboardStyles\(wrapper\)/s',
            $js,
            'le bouton « Copier » doit passer par la neutralisation partagée'
        );
        $this->assertMatchesRegularExpression(
            '/function cloneSelectionForClipboard\(.*?return neutralizeClipboardStyles\(inner\)/s',
            $js,
            'la copie native doit passer par la neutralisation partagée'
        );
    }

    public function testLaNeutralisationEffaceLesFondsSansToucherAuMarkup(): void
    {
        $js = self::chatJs();
        $bloc = self::extraireFonction($js, 'neutralizeClipboardStyles');

        $this->assertStringContainsString("el.style.background = 'transparent'", $bloc);
        $this->assertStringContainsString("el.style.backgroundColor = 'transparent'", $bloc);
        $this->assertStringContainsString("wrapper.style.background = 'transparent'", $bloc);

        // Le reste de la mise en forme est porté par le markup (h1-h6, ul, ol,
        // table, strong, a) : rien ne doit le retirer.
        $this->assertStringNotContainsString('textContent', $bloc);
        $this->assertStringNotContainsString('innerText', $bloc);
    }

    public function testLaCopieNativeEstInterceptee(): void
    {
        $js = self::chatJs();

        preg_match_all("/document\.addEventListener\('copy'/", $js, $m);
        $this->assertCount(1, $m[0], 'un seul intercepteur de copie');

        $pos = strpos($js, "document.addEventListener('copy'");
        $this->assertIsInt($pos);
        $bloc = substr($js, $pos, 1600);

        // Borné au fil de conversation : la copie ailleurs (composer, volets,
        // pages d'admin) reste celle du navigateur.
        $this->assertStringContainsString("getElementById('chatMessages')", $bloc);
        $this->assertStringContainsString('container.contains(range.startContainer)', $bloc);
        $this->assertStringContainsString('container.contains(range.endContainer)', $bloc);

        // Sans preventDefault, le navigateur réécrirait par-dessus notre
        // fragment et le fond gris reviendrait.
        $this->assertStringContainsString("setData('text/html'", $bloc);
        $this->assertStringContainsString("setData('text/plain'", $bloc);
        $this->assertStringContainsString('e.preventDefault()', $bloc);
        $this->assertStringContainsString('background:transparent', $bloc);
    }

    public function testLeFragmentGardeSesAncetresDeMiseEnForme(): void
    {
        $js = self::chatJs();
        $bloc = self::extraireFonction($js, 'cloneSelectionForClipboard');

        // `cloneContents()` s'arrête au plus petit ancêtre commun : un <li>
        // collé sans son <ul> perdrait sa puce.
        $this->assertStringContainsString('range.cloneContents()', $bloc);
        $this->assertStringContainsString('el.cloneNode(false)', $bloc);
        $this->assertStringContainsString("el.classList?.contains('msg-content')", $bloc);
    }

    private static function extraireFonction(string $js, string $nom): string
    {
        $pos = strpos($js, 'function ' . $nom . '(');
        self::assertIsInt($pos, 'fonction ' . $nom . ' introuvable');

        $ouvrante = strpos($js, '{', $pos);
        $profondeur = 0;
        for ($i = $ouvrante; $i < strlen($js); $i++) {
            if ($js[$i] === '{') $profondeur++;
            if ($js[$i] === '}') {
                $profondeur--;
                if ($profondeur === 0) return substr($js, $pos, $i - $pos + 1);
            }
        }

        self::fail('fin de la fonction ' . $nom . ' introuvable');
    }
}
