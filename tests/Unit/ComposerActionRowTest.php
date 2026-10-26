<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rangée d'actions du composer sur téléphone — « + », assistants et bases
 * toujours visibles.
 *
 * Le défaut corrigé (constaté sur téléphone, MIA Recette) : la puce des
 * connecteurs allumés (logos) et la pastille de l'outil actif sont rendues
 * ENTRE « + » et les boutons assistants / bases, dans une rangée qui défile
 * avec une barre invisible. Dès qu'un connecteur ou un outil était allumé,
 * les deux boutons sortaient de l'écran, sans aucun moyen de les atteindre.
 *
 * Ce que ces tests gardent (CSS et câblage ; le rendu a été mesuré au banc de
 * 320 à 600 px) :
 *  - jusqu'à 768 px, les puces passent APRÈS les boutons et sont les seules à
 *    rétrécir ; la puce des connecteurs ne garde que son premier logo et le
 *    nombre des autres ;
 *  - sous 480 px, la puce disparaît et les connecteurs se comptent sur le
 *    « + » ;
 *  - chat.js pose les deux comptes depuis les interrupteurs.
 */
final class ComposerActionRowTest extends TestCase
{
    private static function css(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/css/main.css');
    }

    /** Contenu d'un bloc @media (accolades équilibrées), ou '' s'il manque. */
    private static function media(string $css, string $query, int $from = 0): string
    {
        $pos = strpos($css, '@media (' . $query . ')', $from);
        if ($pos === false) {
            return '';
        }
        $open = strpos($css, '{', $pos);
        $depth = 0;
        for ($i = (int)$open, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, (int)$open + 1, $i - (int)$open - 1);
                }
            }
        }
        return '';
    }

    /**
     * Premier bloc @media de cette requête qui contient $marker (plusieurs
     * blocs portent la même requête dans main.css).
     */
    private static function blockWith(string $query, string $marker): string
    {
        $css = self::css();
        $from = 0;
        while (($pos = strpos($css, '@media (' . $query . ')', $from)) !== false) {
            $block = self::media($css, $query, $pos);
            if (str_contains($block, $marker)) {
                return $block;
            }
            $from = $pos + 1;
        }
        self::fail("Bloc @media ($query) contenant « $marker » introuvable.");
    }

    /** Bloc ≤ 768 px qui porte la rangée défilante du composer. */
    private static function mobileBlock(): string
    {
        return self::blockWith('max-width: 768px', '.input-actions-left::-webkit-scrollbar');
    }

    public function testLesPucesPassentApresLesBoutonsEtSeulesRetrecissent(): void
    {
        $block = self::mobileBlock();
        // Les boutons gardent leur taille ; seules les puces cèdent.
        $this->assertStringContainsString('.input-actions-left > * { flex-shrink: 0; }', $block);
        $ok = preg_match(
            '/\.input-actions-left > \.composer-connectors-chip,\s*\.input-actions-left > \.composer-tool-chip\s*\{([^}]*)\}/',
            $block,
            $m
        );
        $this->assertSame(1, $ok, 'Règle commune des deux puces introuvable.');
        $this->assertMatchesRegularExpression('/order:\s*1/', $m[1], 'Les puces passent après « + », assistants et bases.');
        $this->assertMatchesRegularExpression('/flex-shrink:\s*1/', $m[1]);
        $this->assertMatchesRegularExpression('/min-width:\s*0/', $m[1]);
        // Placée APRÈS la règle générale, sinon flex-shrink: 0 l'emporterait.
        $this->assertGreaterThan(
            strpos($block, '.input-actions-left > * { flex-shrink: 0; }'),
            strpos($block, '.input-actions-left > .composer-connectors-chip')
        );
    }

    public function testLaPuceDesConnecteursSeReduitAUnLogoEtUnCompte(): void
    {
        $block = self::mobileBlock();
        $this->assertStringContainsString('.composer-connectors-chip > :not(:first-child) { display: none; }', $block);
        $this->assertStringContainsString('.composer-connectors-chip[data-more]::after { content: attr(data-more); }', $block);
    }

    public function testSousQuatreCentQuatreVingtsPixelsLesConnecteursSeComptentSurLePlus(): void
    {
        $block = self::blockWith('max-width: 480px', '.plus-menu-btn[data-count]');
        $this->assertMatchesRegularExpression('/\.input-actions-left > \.composer-connectors-chip\s*\{\s*display:\s*none;\s*\}/', $block);
        $this->assertMatchesRegularExpression('/\.plus-menu-btn\[data-count\]::after\s*\{[^}]*content:\s*attr\(data-count\)/', $block);
        // Le point ambre (Internet coupé, connexion à reconnecter) ne passe pas sous le compte.
        $this->assertStringContainsString('.plus-menu-btn[data-count] .plus-menu-dot', $block);
        // Le compte se pose sur le bouton, qui sert de repère.
        $this->assertMatchesRegularExpression('/\.plus-menu-btn\s*\{\s*position:\s*relative;\s*\}/', self::css());
    }

    public function testLeModeleCedeSaPlaceQuandUnOutilEstActif(): void
    {
        $block = self::blockWith('max-width: 440px', '.composer-tool-chip');
        $this->assertStringContainsString(
            '.input-actions:has(.composer-tool-chip:not([hidden])) .llm-inline-picker .llm-picker-btn span { display: none; }',
            $block
        );
    }

    public function testChatJsPoseLesDeuxComptesDepuisLesInterrupteurs(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $start = strpos($js, 'function syncConnectorSummary() {');
        $this->assertNotFalse($start);
        $fn = substr($js, (int)$start, 3000);
        $this->assertStringContainsString("connChip.dataset.more = '+' + others;", $fn);
        $this->assertStringContainsString('delete connChip.dataset.more;', $fn);
        $this->assertStringContainsString('btn.dataset.count = String(', $fn);
        $this->assertStringContainsString('delete btn.dataset.count;', $fn);
        // « +N » est un élément : la règle « tout sauf le premier logo » le masque.
        $this->assertStringNotContainsString("connChip.append('+' + extra)", $fn);
        $this->assertStringContainsString("plus.className = 'composer-connectors-more';", $fn);
    }

    public function testLesBoutonsAssistantsEtBasesSuiventLesPucesDansLeGabarit(): void
    {
        // L'ordre du DOM reste celui du gabarit : c'est le CSS qui range les
        // puces après les boutons. Si le gabarit change d'ordre, ce test le dit.
        $tpl = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $plus = strpos($tpl, 'id="plusMenuBtn"');
        $chip = strpos($tpl, 'id="connectorsChip"');
        $tool = strpos($tpl, 'id="toolChip"');
        $this->assertNotFalse($plus);
        $this->assertNotFalse($chip);
        $this->assertNotFalse($tool);
        $this->assertTrue($plus < $chip && $chip < $tool);
    }

    public function testLesGuidesEtLesNouveautesLeDisent(): void
    {
        $this->assertStringContainsString('telephone-boutons-assistants-bases', (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md'));
        $this->assertStringContainsString(
            'leur nombre s\'affiche sur le bouton **+**',
            (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md')
        );
    }
}
