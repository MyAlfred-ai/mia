<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tailles et qualités acceptées PAR MODÈLE image (config/tools_catalog.php).
 *
 * Le problème résolu : les listes vivaient en trois exemplaires — les options
 * en dur du formulaire d'administration (valeurs DALL-E : 1792×1024, standard,
 * HD), un filtre en dur dans `ImageGenerator::editViaImagesApi`, et un test
 * `str_starts_with('gpt-image')` dans le chemin des générations. GPT Image 2.5
 * (Flare et Sunburst, 8 septembre 2026) ajoute les qualités `xhigh` et `max`,
 * qu'aucun des trois ne connaissait : une qualité choisie à l'écran serait
 * partie chez OpenAI qui la refuse, ou aurait été jetée en silence avant
 * l'appel. Le catalogue est désormais la SOURCE UNIQUE, lue par le générateur
 * comme par l'écran (`ToolCatalog::modelOptions`).
 */
final class ImageModelOptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        ToolCatalog::setToday(null);
    }

    public function testLesDeuxModelesGptImage25SontAuCatalogue(): void
    {
        foreach (['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $model) {
            $info = ToolCatalog::model('image', 'openai', $model);
            $this->assertIsArray($info, "$model absent du catalogue");
            // Même endpoint que GPT Image 2 : /v1/images/generations, et
            // /v1/images/edits pour la retouche (fiches modèles OpenAI).
            $this->assertSame('images_generations', $info['routing'], $model);
            $this->assertIsArray($info['pricing'] ?? null, "$model : tarif public attendu");
            $this->assertFalse(ToolCatalog::isRetired('image', 'openai', $model), $model);
            $this->assertArrayHasKey($model, ToolCatalog::offerableModels('image', 'openai'), $model);
        }
    }

    public function testFlareEstLeDefautEtSunburstNeLEstJamais(): void
    {
        // Flare est le modèle « Default » chez OpenAI : rapide, tarifé au même
        // prix par token que GPT Image 2 — l'adopter par défaut n'engage aucune
        // dépense de plus. Sunburst est le modèle premium : jamais un défaut.
        $this->assertSame('gpt-image-2.5-flare', ToolCatalog::defaultModel('image', 'openai'));
        $sunburst = ToolCatalog::model('image', 'openai', 'gpt-image-2.5-sunburst');
        $this->assertArrayNotHasKey('default', (array)$sunburst);
    }

    public function testChaqueModeleDeclareLesQualitesQueSonApiAccepte(): void
    {
        $q = fn(string $m): array => ToolCatalog::modelOptions('image', 'openai', $m, 'qualities');

        foreach (['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $model) {
            $this->assertContains('xhigh', $q($model), "$model accepte xhigh");
            $this->assertContains('max', $q($model), "$model accepte max");
            $this->assertNotContains('standard', $q($model), "$model refuse les qualités DALL-E");
        }
        // GPT Image 2 s'arrête à `high` : lui proposer xhigh serait une erreur API.
        $this->assertSame(['auto', 'low', 'medium', 'high'], $q('gpt-image-2'));
        // DALL-E n'a pas de liste propre : repli sur celle du fournisseur.
        $this->assertSame(['standard', 'hd'], $q('dall-e-3'));
        // Modèle inconnu du catalogue : même repli, jamais une liste vide qui
        // ferait passer toute qualité pour invalide.
        $this->assertSame(['standard', 'hd'], $q('modele-inexistant'));
    }

    public function testLesTaillesRecommandeesSontDeclareesParModele(): void
    {
        foreach (['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $model) {
            $tailles = ToolCatalog::modelOptions('image', 'openai', $model, 'sizes');
            $this->assertSame(['auto', '1024x1024', '1536x1024', '1024x1536'], $tailles, $model);
            // Les tailles DALL-E ne sont pas proposées : un carré 1792×1024
            // reste accepté par l'API (contraintes géométriques), mais ce n'est
            // pas une taille que l'écran doit suggérer.
            $this->assertNotContains('1792x1024', $tailles, $model);
        }
    }

    public function testLeGenerateurNeRecopieAucuneListeDeQualites(): void
    {
        $code = (string)file_get_contents(ROOT . '/src/Helpers/ImageGenerator.php');

        // Les deux chemins OpenAI (génération et retouche) filtrent par le
        // catalogue. Sans ce filtre partagé, une qualité valide pour un modèle
        // serait refusée pour un autre — ou envoyée quand même.
        $this->assertSame(2, substr_count($code, 'self::qualitiesFor($model)'), 'génération ET retouche');
        $this->assertStringContainsString('self::sizesFor($model)', $code);
        $this->assertStringContainsString("ToolCatalog::modelOptions('image', 'openai', \$model, 'qualities')", $code);
        $this->assertStringContainsString("ToolCatalog::modelOptions('image', 'openai', \$model, 'sizes')", $code);

        // L'ancien filtre en dur de la retouche a bien disparu.
        $this->assertStringNotContainsString("in_array(\$size, ['auto', '1024x1024', '1024x1536', '1536x1024'], true)", $code);
        $this->assertStringNotContainsString("in_array(\$quality, ['auto', 'low', 'medium', 'high'], true)", $code);
    }

    public function testLEcranDAdministrationLitLeMemeCatalogue(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/tools.php');

        $this->assertStringContainsString('OPENAI_IMAGE_MODEL_OPTIONS', $gabarit);
        $this->assertStringContainsString("ToolCatalog::modelOptions('image', 'openai', (string)\$imgModelId, 'qualities')", $gabarit);
        $this->assertStringContainsString('fillImageOptionSelect(', $gabarit);

        // Plus aucune option taille/qualité écrite en dur dans le formulaire :
        // elles mentiraient dès qu'un modèle change de liste.
        $this->assertStringNotContainsString('<option value="1792x1024">', $gabarit);
        $this->assertStringNotContainsString('<option value="standard">', $gabarit);
        $this->assertStringNotContainsString('<option value="hd">', $gabarit);
    }

    public function testUneValeurEnregistreeIncompatibleNEstJamaisRemplaceeEnSilence(): void
    {
        // Même leçon que showCurrentModel pour les modèles arrêtés : la valeur
        // hors liste est ajoutée, signalée et sélectionnée — sinon
        // l'enregistrement automatique de la fiche poserait la première de la
        // liste sans que personne ne l'ait demandé.
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString('non supportée par ce modèle', $gabarit);
        $this->assertStringContainsString('!liste.includes(valeur)', $gabarit);
    }

    /**
     * Le VRAI code de remplissage, exécuté sous node : une lecture ne dit pas
     * ce que contient la liste au bout du compte, et un select vide (ou une
     * valeur perdue) ne lèverait aucune erreur à l'écran. Sans node (conteneur
     * applicatif), le test est SKIPPÉ — même parti pris qu'AutosaveSubmitTest.
     */
    public function testLeRemplissageDesListesSeComporteCommeAttendu(): void
    {
        $cas = self::executerJs();

        // Liste normale : « Automatique » en tête, puis les valeurs du modèle,
        // libellées en français sans jamais changer la valeur envoyée à l'API.
        $this->assertSame(
            ['', 'auto', 'low', 'medium', 'high', 'xhigh', 'max'],
            $cas['modele_25']['valeurs']
        );
        $this->assertSame('Très haute', $cas['modele_25']['libelles'][5]);
        $this->assertSame('1024×1536', $cas['tailles']['libelles'][4], 'Les tailles restent lisibles (× typographique).');
        $this->assertSame('1024x1536', $cas['tailles']['valeurs'][4], 'La VALEUR reste celle de l\'API.');

        // Qualité enregistrée que le modèle choisi n'accepte pas : gardée,
        // signalée, sélectionnée. La perdre changerait le réglage en silence.
        $this->assertSame('xhigh', $cas['valeur_incompatible']['selection']);
        $this->assertStringContainsString('non supportée', $cas['valeur_incompatible']['libelle_selection']);

        // Rien d'enregistré : « Automatique », donc rien n'est posté et le
        // défaut du modèle reprend la main.
        $this->assertSame('', $cas['sans_valeur']['selection']);
    }

    /** @return array<string, array<string, mixed>> */
    private static function executerJs(): array
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $debut = strpos($gabarit, 'const IMAGE_OPTION_TEXT');
        self::assertNotFalse($debut, 'Bloc de libellés introuvable dans le gabarit.');
        $fin = strpos($gabarit, "\n}", (int)strpos($gabarit, 'function fillImageOptionSelect', $debut));
        self::assertNotFalse($fin);
        $code = substr($gabarit, (int)$debut, (int)$fin + 2 - (int)$debut);
        // Les libellés sont traduits côté serveur par tj() : rendus ici comme
        // en français (la clé EST le texte français), sans aucun autre PHP.
        $code = (string)preg_replace_callback(
            "/<\\?= tj\\('((?:[^'\\\\]|\\\\.)*)'\\) \\?>/",
            static fn (array $m): string => (string)json_encode(str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]), JSON_UNESCAPED_UNICODE),
            $code
        );
        self::assertStringNotContainsString('<?', $code, 'Le bloc extrait ne doit porter aucun autre PHP que tj().');

        $script = self::PREAMBULE . "\n" . $code . "\n" . self::SCENARIOS;
        $fichier = tempnam(sys_get_temp_dir(), 'mia-image-options-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            self::assertIsResource($processus, 'Impossible de lancer node.');
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $codeSortie = proc_close($processus);
        } finally {
            @unlink($fichier);
        }

        $resultats = json_decode((string)$sortie, true);
        if ($codeSortie !== 0 || !is_array($resultats)) {
            self::fail("Le remplissage a échoué sous node (code $codeSortie) :\n" . $erreurs);
        }
        return $resultats;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    /** Le strict nécessaire d'un <select> : innerHTML posé, value relue. */
    private const PREAMBULE = <<<'JS'
class FauxSelect {
    constructor() { this.opts = []; this._value = ''; }
    set innerHTML(html) {
        this.opts = [...html.matchAll(/<option value="([^"]*)">([^<]*)<\/option>/g)]
            .map(m => ({ value: m[1], label: m[2] }));
    }
    set value(v) {
        this._value = this.opts.some(o => o.value === String(v)) ? String(v) : '';
    }
    get value() { return this._value; }
    resume() {
        return {
            valeurs: this.opts.map(o => o.value),
            libelles: this.opts.map(o => o.label),
            selection: this._value,
            libelle_selection: (this.opts.find(o => o.value === this._value) || {}).label || '',
        };
    }
}
JS;

    private const SCENARIOS = <<<'JS'
const QUALITES_25 = ['auto', 'low', 'medium', 'high', 'xhigh', 'max'];
const TAILLES_25  = ['auto', '1024x1024', '1536x1024', '1024x1536'];
const cas = {};

let sel = new FauxSelect();
fillImageOptionSelect(sel, QUALITES_25, '');
cas.modele_25 = sel.resume();

sel = new FauxSelect();
fillImageOptionSelect(sel, TAILLES_25, '1024x1024');
cas.tailles = sel.resume();

// « xhigh » enregistré, puis bascule sur un modèle qui s'arrête à « high ».
sel = new FauxSelect();
fillImageOptionSelect(sel, ['auto', 'low', 'medium', 'high'], 'xhigh');
cas.valeur_incompatible = sel.resume();

sel = new FauxSelect();
fillImageOptionSelect(sel, QUALITES_25, '');
cas.sans_valeur = sel.resume();

process.stdout.write(JSON.stringify(cas));
JS;

    public function testLeServeurRefuseUneValeurQueLeModeleNAcceptePas(): void
    {
        $code = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');

        // Les deux écritures (création ET modification d'un outil) passent par
        // le même filtre : le POST arrivait jusqu'en base tel quel, or l'API
        // valide `quality` sur un schéma commun à tous les modèles — une valeur
        // incohérente ne se serait donc signalée nulle part.
        $this->assertSame(2, substr_count($code, 'self::imageToolConfigFromPost('), 'création ET modification');
        $this->assertStringContainsString("ToolCatalog::modelOptions('image', \$provider, \$model, 'qualities')", $code);
        $this->assertStringNotContainsString("\$configArr['quality']      = \$_POST['config_quality']", $code);

        // Les tailles libres restent possibles : les modèles GPT Image en
        // acceptent bien au-delà des tailles que l'écran propose.
        $this->assertStringContainsString('^\\d{2,4}x\\d{2,4}$', $code);
    }

    public function testLaRetoucheEstPossibleAvecLesDeuxNouveauxModeles(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';

        foreach (['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $model) {
            $this->assertTrue(
                ImageGenerator::supportsImageEdit(['provider' => 'openai', 'model' => $model]),
                "$model sait retoucher une image (endpoint /v1/images/edits)"
            );
        }
    }
}
