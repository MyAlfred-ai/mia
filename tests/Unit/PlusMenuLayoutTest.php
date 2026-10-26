<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Menu « + » du composer en une ligne par entrée, avec un volet « Connecteurs »
 * replié par défaut — cf. CLAUDE.md « Menu « + » du composer ».
 *
 * Le problème résolu : jusqu'à 18 lignes sur deux niveaux, défilement dès la
 * neuvième, chaque description redisant le nom (« Emails Gmail — Recherche et
 * lit vos emails Gmail »), une ligne grisée par service jamais relié, et, menu
 * fermé, un point ambre qui disait seulement « quelque chose a changé ».
 *
 * Ce que ces tests gardent :
 *  - la structure (Joindre, Créer, Sources, volet) et les identifiants que
 *    chat.js et tool-suggest.js écoutent ;
 *  - les descriptions masquées SAUF quand elles informent (outil hors service,
 *    connexion expirée, modèle incompatible) ;
 *  - le volet : un vrai bouton, replié à chaque ouverture du menu,
 *    logos des connecteurs allumés dans son en-tête et à côté du « + » ;
 *  - les consignes de MIA qui citent le menu suivent ses libellés.
 */
final class PlusMenuLayoutTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** Bloc du panneau « + » dans le gabarit du chat. */
    private static function panneau(): string
    {
        $tpl = self::lire('templates/chat/index.php');
        $debut = strpos($tpl, '<div class="plus-menu-panel" id="plusMenuPanel" hidden>');
        $fin   = strpos($tpl, 'id="connectorsChip"');
        self::assertNotFalse($debut);
        self::assertNotFalse($fin);
        return substr($tpl, (int)$debut, (int)$fin - (int)$debut);
    }

    public function testLeMenuSeLitEnTroisParties(): void
    {
        $p = self::panneau();
        $joindre = strpos($p, 'class="plus-menu-actions"');
        $creer   = strpos($p, "te('Créer')");
        $sources = strpos($p, "te('Sources')");
        $volet   = strpos($p, 'id="plusMenuFoldBtn"');
        $this->assertNotFalse($joindre);
        $this->assertNotFalse($creer, 'La section des outils s’appelle « Créer ».');
        $this->assertNotFalse($sources);
        $this->assertNotFalse($volet);
        $this->assertTrue($joindre < $creer && $creer < $sources && $sources < $volet, 'Joindre, Créer, Sources, puis le volet.');
        $this->assertStringNotContainsString("te('Outils')", $p, 'Plus de section « Outils ».');

        // Les identifiants écoutés par chat.js restent les mêmes.
        foreach (['plusMenuAddFiles', 'plusMenuTakePhoto', 'plusMenuWebRow', 'webAccessToggle', 'plusMenuAutoRow', 'connectorsAutoToggle'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $p, $id);
        }
        // Internet reste HORS du volet : c'est l'interrupteur qu'on touche le plus.
        $this->assertLessThan($volet, strpos($p, 'id="plusMenuWebRow"'));
    }

    public function testLeVoletEstUnBoutonRepliePortantLesLignesReliees(): void
    {
        $p = self::panneau();
        $this->assertStringContainsString('aria-expanded="false" aria-controls="plusMenuFoldBody"', $p);
        $this->assertStringContainsString('<ul class="plus-menu-fold-body" id="plusMenuFoldBody" hidden>', $p, 'Replié par défaut, dès le rendu.');
        // Dans le volet : « Laisser MIA choisir » d'abord, puis les lignes reliées ou expirées.
        $corps = substr($p, (int)strpos($p, 'id="plusMenuFoldBody"'));
        $this->assertLessThan(strpos($corps, '<?php foreach ($foldRows as $c):'), strpos($corps, 'id="plusMenuAutoRow"'));
        // Compte jamais relié : plus de ligne grisée, une ligne « Relier N services ».
        $this->assertStringContainsString("I18n::plural(count(\$rows), 'Relier un service', 'Relier {n} services')", $p);
        $this->assertStringContainsString('href="/settings/connections"', $p);
        $this->assertStringContainsString("if (!empty(\$c['connected']) || \$expired) { \$foldRows[] = \$c; } else { \$linkRows[] = \$c; }", $p);
        // Connexion expirée : la pastille passe sur l'en-tête, visible volet replié.
        $this->assertStringContainsString('reconnect-dot plus-menu-fold-alert', $p);
    }

    public function testLesOutilsTiennentSurUneLigneEtAffichentLeurCoutEnCredits(): void
    {
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString("te('≈ {n} cr.', ['n' => Format::credits(CreditEstimate::round(\$toolCost))])", $tpl);
        $this->assertStringNotContainsString("str_repeat('€', \$stars)", substr($tpl, (int)strpos($tpl, '$renderToolItem'), 4000), 'Plus d’échelle en € dans le menu.');
        // La description d'un outil en service part dans l'infobulle.
        $this->assertStringContainsString(": (string)(\$toolHints[\$iconKey] ?? '');", $tpl);
        // Le site illustré a sa propre icône.
        $this->assertStringNotContainsString("\$toolIcons['html_illustrated'] = \$toolIcons['html'];", $tpl);
    }

    public function testLesDescriptionsNeRestentQueQuandEllesInforment(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.plus-menu-panel .plus-menu-hint { display: none; }', $css);
        foreach ([
            '.plus-menu-panel .tool-picker-item.is-disabled .plus-menu-hint',
            '.plus-menu-panel .plus-menu-row.needs-reconnect .plus-menu-hint',
            '.plus-menu-panel .plus-menu-row.is-blocked .plus-menu-hint { display: block; }',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
        $this->assertStringContainsString("c.row.classList.toggle('is-blocked', blocked);", self::lire('public/assets/js/chat.js'));
        // display:flex des lignes l'emporterait sur [hidden].
        $this->assertStringContainsString('.plus-menu-fold-body[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.composer-connectors-chip[hidden] { display: none; }', $css);
    }

    public function testLeVoletEtLaPuceSuiventLesInterrupteurs(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        // Replié à CHAQUE ouverture du menu (décision produit) : aucun état retenu.
        $this->assertStringContainsString("if (panel.hidden) { setFold(false); open(); } else { close(); }", $js);
        $this->assertStringNotContainsString('localStorage.setItem(FOLD_KEY', $js, 'L’état du volet n’est plus retenu.');
        // Le résumé est recalculé partout où les indicateurs le sont (chaque bascule).
        $indicateurs = substr($js, (int)strpos($js, 'const syncOptionIndicators = () => {'), 4000);
        $this->assertStringContainsString('syncConnectorSummary();', $indicateurs);
        // Le point ambre ne parle plus que d'Internet coupé et des connexions à reconnecter.
        $this->assertStringContainsString('if (optionsDot) optionsDot.hidden = webOn && reconnectRows.length === 0;', $js);
        // La puce est hors de #plusMenu : sans stopPropagation, le menu se refermerait aussitôt.
        $puce = substr($js, (int)strpos($js, "connChip?.addEventListener('click'"), 600);
        $this->assertStringContainsString('e.stopPropagation();', $puce);
        $this->assertStringContainsString('setFold(true);', $puce);
        // Jamais scrollIntoView : il ferait défiler la rangée d'actions du composer.
        $reveal = substr($js, (int)strpos($js, 'const revealFold = () => {'), 400);
        $this->assertStringNotContainsString('scrollIntoView', $reveal);
        $this->assertStringContainsString('miaUiZoom', $reveal);

        $client = (array)(require ROOT . '/lang/en.client.php');
        foreach (['{n} sur {total}', '{n} relié', '{n} reliés', 'Connecteurs allumés : {liste}', 'ouvrir les connecteurs', 'Laisser MIA choisir'] as $cle) {
            $this->assertArrayHasKey($cle, $client, $cle);
        }
    }

    public function testLesConsignesDeMiaCitentLeMenuTelQuIlEst(): void
    {
        $note = self::lire('src/Connectors/ConnectorRegistry.php');
        $this->assertStringContainsString('ouvrir le menu « + » sous le champ de saisie, déplier « Connecteurs », activer {premier}', $note);
        $livraison = self::lire('src/Files/FileDelivery.php');
        $this->assertStringContainsString('par le menu « + » du chat, section « Créer »', $livraison);
        $this->assertStringNotContainsString('bouton « Outils »', $livraison);
    }

    public function testLesGuidesDecriventLeVolet(): void
    {
        $this->assertStringContainsString('Connecteurs', self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md'));
        $this->assertStringContainsString('déplie', self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md'));
        $this->assertStringContainsString('menu-plus-volet', self::lire('docs/NOUVEAUTES.md'));
    }
}
