<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage des fichiers livrés dans une conversation : route, contrôleur,
 * chargement des classes, gabarit du chat, moteur Markdown, prompt,
 * feuille de style et documentation.
 *
 * Ces liens ne se voient qu'à l'exécution de la page entière : une route
 * oubliée donne une carte dont le bouton répond 404, un script chargé après
 * chat.js des blocs de code sans carte, une classe absente de la liste du
 * worker une panne qui n'existe qu'en production. Aucun ne produit d'erreur
 * de syntaxe — d'où ces vérifications sur le code lui-même.
 */
final class FileDeliveryContractTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $contenu = file_get_contents(ROOT . '/' . $chemin);
        if (!is_string($contenu)) {
            self::fail("Fichier introuvable : $chemin");
        }

        return $contenu;
    }

    /** Code PHP sans ses commentaires (un commentaire qui cite un appel ne prouve rien). */
    private static function codeSeul(string $chemin): string
    {
        $code = '';
        foreach (token_get_all(self::lire($chemin)) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }

    /** Corps d'une fonction JavaScript déclarée `function nom(`. */
    private static function fonctionJs(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        if ($debut === false) {
            self::fail("Fonction JavaScript « $nom » introuvable.");
        }
        // Le corps commence APRÈS la liste des paramètres : une valeur par
        // défaut (`options = {}`) porte elle aussi des accolades.
        $parametres = 0;
        for ($i = strpos($source, '(', $debut), $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '(') {
                $parametres++;
            } elseif ($source[$i] === ')' && --$parametres === 0) {
                break;
            }
        }
        $ouverture = strpos($source, '{', $i);
        $profondeur = 0;
        for ($i = $ouverture, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $profondeur++;
            } elseif ($source[$i] === '}' && --$profondeur === 0) {
                return substr($source, $debut, $i - $debut + 1);
            }
        }
        self::fail("Fonction JavaScript « $nom » non refermée.");
    }

    // --------------------------------------------------------- serveur

    public function testLaRouteEstDeclareeEtSuitLeCatalogue(): void
    {
        $index = self::lire('public/index.php');

        $this->assertMatchesRegularExpression(
            "#\\\$router->post\\('/files/build',\\s*\\[FileController::class, 'build'\\]\\);#",
            $index
        );
        $this->assertSame('/files/build', FileFormats::ENDPOINT, 'Le navigateur lit la route dans le catalogue.');
        $this->assertStringNotContainsString("router->get('/files/build'", $index, 'Fabriquer un fichier est un POST (contenu dans le corps, jeton CSRF).');
    }

    public function testLeControleurEstMinceEtSur(): void
    {
        $code = self::codeSeul('src/Controllers/FileController.php');

        // Authentification AVANT tout, session relâchée avant la fabrication.
        $auth     = strpos($code, 'AuthMiddleware::requireAuth()');
        $relache  = strpos($code, 'session_write_close()');
        $fabrique = strpos($code, 'FileFactory::build(');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($relache);
        $this->assertNotFalse($fabrique);
        $this->assertLessThan($relache, $auth);
        $this->assertLessThan($fabrique, $relache);

        // Pièce jointe au nom sûr, jamais interprétée par le navigateur, jamais en cache.
        $this->assertStringContainsString('FileNaming::contentDisposition(', $code);
        $this->assertStringContainsString("'X-Content-Type-Options: nosniff'", $code);
        $this->assertStringContainsString('no-store', $code);
        // Échec : la charge utile présentable, avec son statut.
        $this->assertStringContainsString('$e->toPayload()', $code);
        $this->assertStringContainsString('http_response_code($e->httpStatus())', $code);
        // Corps plus gros que post_max_size : PHP vide $_POST sans rien dire.
        $this->assertStringContainsString("\$_SERVER['CONTENT_LENGTH']", $code);

        // Rien n'est écrit : ni disque, ni base.
        foreach (['file_put_contents', 'fopen(', 'Database::', '->prepare(', 'move_uploaded_file'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $code, "Le contrôleur ne stocke rien ($interdit).");
        }
    }

    /**
     * Pas d'autoloader : chaque classe est requise À LA MAIN, dans l'app ET
     * dans les tests. Une classe oubliée côté CLI ne casserait que le worker.
     */
    public function testChaqueClasseDuModuleEstChargeeDesDeuxCotes(): void
    {
        $app   = self::lire('config/classes.php');
        $tests = self::lire('tests/bootstrap.php');

        $fichiers = array_map('basename', glob(ROOT . '/src/Files/*.php') ?: []);
        $this->assertNotEmpty($fichiers);

        foreach ($fichiers as $fichier) {
            $this->assertStringContainsString("require ROOT . '/src/Files/$fichier';", $app, "$fichier absent de config/classes.php");
            $this->assertStringContainsString("'/src/Files/$fichier',", $tests, "$fichier absent de tests/bootstrap.php");
        }
        $this->assertStringContainsString("require ROOT . '/src/Controllers/FileController.php';", $app);

        // L'interface avant ses fabricants, la fabrique en dernier.
        foreach ([$app, $tests] as $liste) {
            $interface = strpos($liste, '/src/Files/FileBuilder.php');
            foreach ($fichiers as $fichier) {
                if (str_ends_with($fichier, 'FileBuilder.php') && $fichier !== 'FileBuilder.php') {
                    $this->assertLessThan(strpos($liste, "/src/Files/$fichier"), $interface, "$fichier chargé avant l'interface FileBuilder");
                }
            }
            $this->assertGreaterThan(strpos($liste, '/src/Files/PdfFileBuilder.php'), strpos($liste, '/src/Files/FileFactory.php'));
        }
    }

    /** Les classes du module se chargent sans vendor/ : elles ne touchent leur bibliothèque qu'à l'exécution. */
    public function testLeModuleSeChargeSansBibliotheque(): void
    {
        foreach (glob(ROOT . '/src/Files/*.php') ?: [] as $fichier) {
            $classe = basename($fichier, '.php');
            $this->assertTrue(
                class_exists($classe, false) || interface_exists($classe, false),
                "$classe n'est pas chargée par le bootstrap des tests."
            );
        }
    }

    /**
     * La convention vit avec le module : le prompt du chat l'appelle au lieu
     * de la recopier, et les anciennes consignes (« un bloc ```csv copiable
     * suffit ») ont disparu — elles contrediraient la carte.
     */
    public function testLePromptDuChatUtiliseLaConvention(): void
    {
        $code = self::codeSeul('src/Controllers/ChatController.php');

        $this->assertStringContainsString('. FileDelivery::promptFragment()', $code);
        $this->assertSame(1, substr_count($code, 'FileDelivery::promptFragment()'), 'Un seul point d’injection.');
        $this->assertStringNotContainsString('Un bloc ```csv copiable est parfait', $code);
        $this->assertStringNotContainsString('Livraison de fichiers — règles de cette interface', $code, 'Le titre vit dans FileDelivery.');
        // L'interdiction des blocs ```markdown reste — le fichier en est l'exception, dite par le fragment.
        $this->assertStringContainsString("N'encadre JAMAIS du texte rédigé dans un bloc de code ```markdown", $code);
    }

    // ----------------------------------------------------------- gabarit

    public function testLeGabaritChargeLeModuleAuBonEndroit(): void
    {
        $gabarit = self::lire('templates/chat/index.php');

        $this->assertStringContainsString('/assets/css/files.css?v=', $gabarit);
        $this->assertStringContainsString(
            'window.FILE_FORMATS = <?= json_encode(FileFormats::clientCatalog(), JSON_UNESCAPED_UNICODE) ?>;',
            $gabarit
        );

        // Après marked (il y branche le rendu des cartes), AVANT chat.js (qui
        // rend les messages dès son chargement), et après le catalogue.
        $marked   = strpos($gabarit, '<script src="/assets/js/marked.min.js"></script>');
        $module   = strpos($gabarit, '<script src="/assets/js/generated-files.js?v=');
        $chat     = strpos($gabarit, '/assets/js/chat.js?v=');
        $catalog  = strpos($gabarit, 'window.FILE_FORMATS =');
        $this->assertNotFalse($marked);
        $this->assertNotFalse($module);
        $this->assertNotFalse($chat);
        $this->assertLessThan($module, $marked);
        $this->assertLessThan($chat, $module);
        $this->assertLessThan($module, $catalog);

        // Téléchargement depuis l'image agrandie (au doigt, il n'y a pas de survol).
        $this->assertMatchesRegularExpression('/<button type="button" class="image-lightbox-download" id="imageLightboxDownload"/', $gabarit);
    }

    // ---------------------------------------------------------- chat.js

    public function testLeMoteurMarkdownConnaitLesFichiers(): void
    {
        $chat = self::lire('public/assets/js/chat.js');

        $rendu = self::fonctionJs($chat, 'renderMarkdown');
        $equilibre = strpos($rendu, 'window.MiaFiles.balanceFences(text)');
        $parse     = strpos($rendu, 'marked.parse(unwrapMarkdownFences(stripChatgptCitationMarkers(text)))');
        $this->assertNotFalse($equilibre, 'Les délimiteurs d’un document sont rééquilibrés avant la lecture.');
        $this->assertNotFalse($parse);
        $this->assertLessThan($parse, $equilibre);
        $this->assertStringContainsString('window.MiaFiles.codeDownloadHtml(', $rendu, 'Bouton « Télécharger » dans l’en-tête d’un bloc ordinaire.');

        // Un fichier DÉCLARÉ en Markdown n'est jamais déballé en texte.
        $deballage = self::fonctionJs($chat, 'unwrapMarkdownFences');
        $this->assertStringContainsString('window.MiaFiles.parseInfo(', $deballage);
        $this->assertStringContainsString('!declared', $deballage);

        // Copie et exports : les cartes sont aplaties avant tout le reste.
        $neutralisation = self::fonctionJs($chat, 'neutralizeClipboardStyles');
        $this->assertStringContainsString('window.MiaFiles?.flattenForExport(wrapper);', $neutralisation);
    }

    /** Aperçus et compteurs posés à CHAQUE rendu final — jamais pendant l'écriture. */
    public function testLesCartesSontTermineesAuxTroisRendusFinaux(): void
    {
        $chat = self::lire('public/assets/js/chat.js');

        $this->assertSame(
            3,
            preg_match_all('/innerHTML = renderMarkdown\((?:text|raw)\);\s*\n\s*hydrateGeneratedFiles\(md(?:El)?\);/', $chat),
            'appendMessage, finalizeAssistantMessage et le chargement de la page.'
        );
        $this->assertStringContainsString('window.MiaFiles.hydrate(mdEl)', self::fonctionJs($chat, 'hydrateGeneratedFiles'));
    }

    /** Un seul chemin d'enregistrement, qui passe par la feuille de partage dans l'application. */
    public function testUnSeulCheminDEnregistrement(): void
    {
        $chat   = self::lire('public/assets/js/chat.js');
        $module = self::lire('public/assets/js/generated-files.js');

        $this->assertStringContainsString('window.NativeBridge?.saveAndShare', self::fonctionJs($chat, 'triggerBlobDownload'));
        $this->assertStringContainsString("triggerBlobDownload(blob, filename || 'export.pdf');", self::fonctionJs($chat, 'downloadBlobAsFile'));
        $this->assertStringContainsString('root.triggerBlobDownload(blob, filename);', $module);
    }

    // ------------------------------------------------ generated-files.js

    public function testLeModuleNeRecopieAucuneListeDeFormats(): void
    {
        $module = self::lire('public/assets/js/generated-files.js');

        $this->assertStringContainsString('root.FILE_FORMATS', $module);
        // Aucun libellé de format écrit en dur : ils viennent du serveur, traduits.
        foreach (['Classeur Excel', 'Document Word', 'Excel (.xlsx)', 'application/vnd'] as $libelle) {
            $this->assertStringNotContainsString($libelle, $module, "« $libelle » doit venir du catalogue.");
        }
        // Mêmes attributs de repli que le serveur (catalogue absent : page d'erreur, cache).
        $this->assertStringContainsString(
            "attributes: ['" . implode("', '", FileDelivery::ATTRIBUTES) . "']",
            $module
        );
    }

    /** Safari d'avant 16.4 ne connaît pas le lookbehind : une seule expression le fait planter à l'analyse. */
    public function testPasDeLookbehindDansLeModule(): void
    {
        $module = self::lire('public/assets/js/generated-files.js');

        $this->assertStringNotContainsString('(?<=', $module);
        $this->assertStringNotContainsString('(?<!', $module);
        $this->assertStringNotContainsString('(?<', $module, 'Ni groupe nommé, ni lookbehind.');
    }

    /** Chaque échec montre une issue — dont le contenu brut, possible sans le serveur. */
    public function testChaqueEchecProposeUneIssue(): void
    {
        $module = self::lire('public/assets/js/generated-files.js');

        $echec = self::fonctionJs($module, 'showFailure');
        $this->assertStringContainsString("t('Réessayer')", $echec);
        $this->assertStringContainsString("t('Télécharger en {format}'", $echec);
        $this->assertStringContainsString("t('Contenu brut (.{ext})'", $echec);

        $requete = self::fonctionJs($module, 'requestBuild');
        $this->assertStringContainsString("'X-CSRF-Token': csrfToken()", $requete);
        $this->assertStringContainsString("data.code === 'csrf' && !retried", $requete, 'Jeton périmé : rejoué UNE fois.');
        $this->assertStringContainsString('showSessionExpiredModal', $requete);
        $this->assertStringContainsString('controller.abort()', $requete, 'Jamais d’attente sans fin.');

        // Bloc ordinaire : pas de carte où afficher l'échec, le contenu est enregistré tel quel.
        $this->assertStringContainsString('saveRaw(file)', self::fonctionJs($module, 'downloadCode'));
    }

    // -------------------------------------------------------------- CSS

    public function testLaFeuilleDeStyle(): void
    {
        $css = self::lire('public/assets/css/files.css');

        // `display:flex` l'emporte sur l'attribut `hidden` : règle dédiée (piège déjà payé ailleurs).
        $this->assertMatchesRegularExpression('/\.file-card \[hidden\][^{]*\{\s*display:\s*none\s*!important/', $css);
        $this->assertStringContainsString('.file-menu[hidden]', $css);
        // Au doigt : cibles de 44 px ; les survols seulement là où ils existent.
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\)\s*\{[^@]*44px/s', $css);
        $this->assertStringContainsString('@media (hover: hover)', $css);

        // Une pastille par couleur du catalogue.
        foreach (array_unique(array_column(FileFormats::clientCatalog()['formats'], 'tone')) as $teinte) {
            $this->assertStringContainsString('[data-tone="' . $teinte . '"]', $css, "Teinte « $teinte » sans règle.");
        }
    }

    // ------------------------------------------------------ documentation

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testDocumentation(): void
    {
        $nouveautes = self::lire('docs/NOUVEAUTES.md');
        $this->assertStringContainsString('<!-- id: telecharger-fichiers-conversation | genre: nouveaute | pour: tous', $nouveautes);

        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('Télécharger un fichier créé par MIA', $guide);

        $page = 'docs/user/fonctionnalites/conversations/telecharger-un-fichier.md';
        $this->assertFileExists(ROOT . '/' . $page);
        $this->assertStringNotContainsString('](../../admin/', self::lire($page), 'Jamais de lien d’une page utilisateur vers admin/.');
        $this->assertStringContainsString('(telecharger-un-fichier.md)', self::lire('docs/user/fonctionnalites/conversations/index.md'));

        $claude = self::lire('CLAUDE.md');
        $this->assertStringContainsString('## Fichiers livrés dans une conversation', $claude);
        $this->assertStringContainsString('src/Files/', $claude);
    }
}
