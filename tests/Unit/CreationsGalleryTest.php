<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Galerie « Mes créations » (/settings/creations) : helpers purs d'extraction
 * (images, titres) et câblage de la page de bout en bout.
 */
final class CreationsGalleryTest extends TestCase
{
    // ---------- extractGeneratedImages ----------

    public function testImageSeule(): void
    {
        $images = Message::extractGeneratedImages('![Image générée](/assets/img/generated/abc.png)');
        $this->assertSame([['url' => '/assets/img/generated/abc.png', 'alt' => 'Image générée']], $images);
    }

    /**
     * imagine() ajoute « *Prompt enrichi : …* » sous l'image quand le fournisseur
     * renvoie un revised_prompt : generatedImageUrl() (strict, partage) échoue,
     * la galerie doit malgré tout voir l'image.
     */
    public function testImageSuivieDuPromptEnrichi(): void
    {
        $content = "![Image générée](/assets/img/generated/abc.png)\n\n*Prompt enrichi : un chat astronaute*";
        $this->assertNull(Message::generatedImageUrl(['content' => $content]));

        $images = Message::extractGeneratedImages($content);
        $this->assertCount(1, $images);
        $this->assertSame('/assets/img/generated/abc.png', $images[0]['url']);
    }

    /** Chemin function-calling : plusieurs images noyées dans de la prose. */
    public function testPlusieursImagesDansDeLaProse(): void
    {
        $content = "Voici la première :\n![Un chat](/assets/img/generated/a.png)\n"
                 . "et la seconde :\n![Un chien](/assets/img/generated/b.jpg)\nVoilà.";
        $images  = Message::extractGeneratedImages($content);

        $this->assertCount(2, $images);
        $this->assertSame('/assets/img/generated/a.png', $images[0]['url']);
        $this->assertSame('Un chat', $images[0]['alt']);
        $this->assertSame('/assets/img/generated/b.jpg', $images[1]['url']);
        $this->assertSame('Un chien', $images[1]['alt']);
    }

    public function testImageExterneIgnoree(): void
    {
        $this->assertSame([], Message::extractGeneratedImages('![photo](https://exemple.com/photo.png)'));
    }

    /** Une page HTML générée contient des <img src="/assets/img/generated/…"> : jamais du markdown. */
    public function testBaliseImgHtmlIgnoree(): void
    {
        $html = '<html><body><img src="/assets/img/generated/a.png" alt="x"></body></html>';
        $this->assertSame([], Message::extractGeneratedImages($html));
    }

    public function testUrlDedupliquee(): void
    {
        $content = "![a](/assets/img/generated/x.png)\ntexte\n![b](/assets/img/generated/x.png)";
        $this->assertCount(1, Message::extractGeneratedImages($content));
    }

    public function testUneImageMontreeParDeuxMessagesNAQuUneCarte(): void
    {
        // Lignes du plus récent au plus ancien : le livrable d'un workflow reprend l'image générée plus haut.
        $cards = [
            ['type' => 'generated_image', 'url' => '/assets/img/generated/a.png', 'shareable' => false, 'message_id' => 9],
            ['type' => 'html_page', 'message_id' => 8],
            ['type' => 'generated_image', 'url' => '/assets/img/generated/a.png', 'shareable' => true, 'message_id' => 7],
            ['type' => 'generated_image', 'url' => '/assets/img/generated/b.png', 'shareable' => false, 'message_id' => 6],
            ['type' => 'generated_image', 'url' => '/assets/img/generated/b.png', 'shareable' => false, 'message_id' => 5],
        ];
        $this->assertSame([7, 8, 6], array_column(Message::withoutRepeatedImages($cards), 'message_id'),
            'La carte qui se partage, à la place de la plus récente ; sinon la première.');
    }

    public function testEntitesDecodeesDansLUrl(): void
    {
        $images = Message::extractGeneratedImages('![a](/assets/img/generated/a&amp;b.png)');
        $this->assertSame('/assets/img/generated/a&b.png', $images[0]['url']);
    }

    public function testContenuSansImage(): void
    {
        $this->assertSame([], Message::extractGeneratedImages('Bonjour, voici ma réponse.'));
        $this->assertSame([], Message::extractGeneratedImages(''));
    }

    // ---------- extractHtmlTitle ----------

    public function testTitreHtml(): void
    {
        $this->assertSame(
            'Mon site vitrine',
            Message::extractHtmlTitle('<!DOCTYPE html><html><head><title>Mon site vitrine</title></head><body></body></html>')
        );
    }

    public function testTitreHtmlAvecAttributsEtEntites(): void
    {
        $this->assertSame(
            'Café & Co',
            Message::extractHtmlTitle('<html><head><TITLE data-x="1">  Caf&eacute;   &amp; Co  </TITLE></head></html>')
        );
    }

    public function testTitreHtmlAbsentOuVide(): void
    {
        $this->assertNull(Message::extractHtmlTitle('<html><body>Sans titre</body></html>'));
        $this->assertNull(Message::extractHtmlTitle('<html><head><title>   </title></head></html>'));
    }

    public function testTitreHtmlBorne(): void
    {
        $long = str_repeat('a', 400);
        $this->assertSame(150, mb_strlen((string)Message::extractHtmlTitle("<title>$long</title>")));
    }

    // ---------- extractJsonTitle ----------

    public function testTitreJsonValide(): void
    {
        $json = json_encode(['title' => 'Balade à Lyon', 'waypoints' => [['query' => 'a'], ['query' => 'b']]]);
        $this->assertSame('Balade à Lyon', Message::extractJsonTitle((string)$json));
    }

    /** Le LEFT() SQL de creationsForUser peut couper le JSON en plein milieu. */
    public function testTitreJsonTronque(): void
    {
        $tronque = '{"version":1,"title":"Roadmap produit","slides":[{"id":"s1","layo';
        $this->assertNull(json_decode($tronque, true), 'Le fixture doit bien être un JSON invalide.');
        $this->assertSame('Roadmap produit', Message::extractJsonTitle($tronque));
    }

    public function testTitreJsonTronqueAvecEchappements(): void
    {
        $tronque = '{"title":"L\'été \\"chaud\\"","slides":[{"id";';
        $this->assertSame('L\'été "chaud"', Message::extractJsonTitle($tronque));
    }

    public function testTitreJsonAbsent(): void
    {
        $this->assertNull(Message::extractJsonTitle('{"slides":[]}'));
        $this->assertNull(Message::extractJsonTitle('pas du json'));
    }

    // ---------- Câblage de la page ----------

    /**
     * Un maillon manquant ne casse rien visiblement : la page reste
     * inaccessible, ou le menu pointe dans le vide. D'où ces assertions.
     */
    public function testLaPageEstBrancheeDeBoutEnBout(): void
    {
        $index = (string)file_get_contents(ROOT . '/public/index.php');
        $this->assertStringContainsString("'/settings/creations'", $index, 'Route absente.');
        $this->assertStringContainsString("[UserSettingsController::class, 'creations']", $index);

        $controller = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('public function creations(', $controller);
        $this->assertStringContainsString('Message::creationsForUser', $controller);
        $this->assertStringContainsString('Message::extractGeneratedImages', $controller);

        // Une page de l'espace de travail : sa tuile dans le volet du chat, qu'elle
        // garde (NavShell), et sa page s'y déclare sous la clé qui l'allume.
        $this->assertSame('/settings/creations', NavRegistry::WORKSPACE['creations']['href']);
        $this->assertSame('Mes créations', NavRegistry::WORKSPACE['creations']['label']);
        $this->assertSame('creations', NavShell::SHORTCUT_OF['creations'], "La tuile ne s'allumerait jamais.");
        $this->assertStringContainsString(
            "\$page = 'creations'",
            (string)file_get_contents(ROOT . '/templates/settings/creations.php')
        );
    }

    public function testLeGabaritEtSonScriptSontCoherents(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/creations.php');
        $this->assertStringContainsString("\$page = 'creations'", $tpl, 'État actif de la nav non posé.');
        $this->assertStringContainsString('/assets/js/creations.js', $tpl);
        $this->assertStringContainsString('data-preview', $tpl, 'Aucune carte marquée pour l\'aperçu lazy.');
        $this->assertStringContainsString('id="crGrid"', $tpl);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/creations.js');
        // Garde d'aperçu : sans elle, une page générée pourrait exfiltrer.
        $this->assertStringContainsString("connect-src 'none'", $js);
        $this->assertStringContainsString("form-action 'none'", $js);
        // Sans absolutisation, les images d'un aperçu blob: restent vides.
        $this->assertStringContainsString('absolutizeGeneratedAssets', $js);
        $this->assertStringContainsString('/chat/message/', $js, 'Les aperçus ne sauraient pas où lire le contenu.');
    }

    /**
     * Le bouton de partage de la galerie doit être le MÊME que celui du chat :
     * mêmes classes et data-* (le gabarit), mêmes routes et mêmes états (le
     * script). Une divergence donnerait deux partages qui ne se voient pas.
     */
    public function testLePartageReprendLeContratDuChat(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/creations.php');
        $this->assertStringContainsString('btn-share-creation btn-secondary', $tpl);
        $this->assertStringContainsString('data-share-token', $tpl);
        $this->assertStringContainsString('data-msg-id', $tpl);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/creations.js');
        foreach (['/share`', '/unshare`', 'X-CSRF-Token', 'sharePopover', 'data-share-action'] as $needle) {
            $this->assertStringContainsString($needle, $js, "Contrat de partage incomplet : $needle");
        }
        // L'état est porté par le bouton (plus de pastille statique à désynchroniser).
        $this->assertStringContainsString('setShareButtonsState', $js);
        $this->assertStringNotContainsString('creation-shared', $tpl);

        // Le serveur refuse (422) un message dont creationType() est null : le
        // bouton ne doit donc pas être rendu pour une image noyée dans la prose.
        $controller = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString("'shareable'       => \$type !== null", $controller);
        $this->assertStringContainsString('$imageShareable', $controller);
    }

    /**
     * Téléchargement : chaque type prend le chemin du chat. L'itinéraire n'en
     * a pas (l'export PDF exige une carte Leaflet vivante), et les documents
     * passent par `inline=1` — sans quoi le fichier enregistré aurait des
     * images mortes.
     */
    public function testLeTelechargementCouvreLesTypesDeLaBonneFacon(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/creations.php');
        $this->assertStringContainsString('btn-download-creation', $tpl);
        $this->assertStringContainsString('data-download', $tpl);

        $controller = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString("'download'  => ''", $controller, "L'itinéraire ne doit pas être téléchargeable.");
        $this->assertStringContainsString("'download'  => 'direct'", $controller);
        $this->assertStringContainsString("'download'  => 'deck'", $controller);
        $this->assertStringContainsString("'download'  => 'html'", $controller);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/creations.js');
        $this->assertStringContainsString('?inline=1', $js, 'Fichier enregistré aux images mortes.');
        $this->assertStringContainsString('/deck/${format}', $js, 'Export PPTX/PDF non branché.');
        foreach (['pptx', 'pdf', 'html'] as $format) {
            $this->assertStringContainsString('data-format="' . $format . '"', $js);
        }
    }

    /** Un clic sur un bouton d'action ne doit pas AUSSI ouvrir la visionneuse. */
    public function testLesBoutonsNOuvrentPasLaVisionneuse(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/creations.js');
        $click = strpos($js, "grid.addEventListener('click'");
        $this->assertIsInt($click);
        $handler = substr($js, $click, 700);
        $this->assertStringContainsString('btn-share-creation', $handler);
        $this->assertStringContainsString('btn-download-creation', $handler);
        // Les deux gardes doivent précéder l'activation de la carte.
        $this->assertLessThan(
            strpos($handler, 'activate(card)'),
            strpos($handler, 'btn-download-creation'),
            'Le bouton est traité après la carte : le clic ouvrirait la visionneuse.'
        );
    }

    public function testLaDocumentationUtilisateurMentionneLaPage(): void
    {
        $guide = (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('Mes créations', $guide);
    }
}
