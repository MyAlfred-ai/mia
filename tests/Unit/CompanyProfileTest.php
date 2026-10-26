<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PptxTemplateFixture.php';

/**
 * L'entreprise qui utilise MIA (Organisation → Entreprise, page à part) :
 * nom et descriptif donnés à l'assistant dans chaque conversation, logo et
 * modèle PowerPoint qui habillent les présentations téléchargées.
 *
 * Formes pures d'abord, puis le câblage — chaque surface lue dans le code,
 * parce qu'un fragment de prompt oublié dans la régénération ou un modèle
 * déposé hors de son volume ne se voient à aucun affichage.
 */
final class CompanyProfileTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    protected function setUp(): void
    {
        self::settings([]);
    }

    protected function tearDown(): void
    {
        CompanyProfile::overrideTemplate(null);
        Settings::refresh();
        I18n::use('fr');
    }

    /** Réglages en mémoire, sans base (même patron que ProviderToolLoopTest). */
    private static function settings(array $values): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $values);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
    }

    // ------------------------------------------------------------- saisie

    public function testNomSurUneLigneEtBorne(): void
    {
        $this->assertSame('Acme Industries', CompanyProfile::sanitizeName("  Acme\n\tIndustries \x07 "));
        $this->assertSame(CompanyProfile::NAME_MAX, mb_strlen(CompanyProfile::sanitizeName(str_repeat('é', 500))));
        $this->assertSame('', CompanyProfile::sanitizeName(" \n "));
    }

    public function testDescriptifGardeSesParagraphes(): void
    {
        $this->assertSame(
            "Fabricant de robinetterie.\n\nSites : Nantes, Lyon.",
            CompanyProfile::sanitizeDescription("  Fabricant de robinetterie.   \r\n\r\n\r\n\r\nSites : Nantes,\x00 Lyon.  ")
        );
        $this->assertSame(CompanyProfile::DESCRIPTION_MAX, mb_strlen(CompanyProfile::sanitizeDescription(str_repeat('a', 3000))));
    }

    // ------------------------------------------------------------- prompt

    public function testFragmentVideSansRienDeRenseigne(): void
    {
        $this->assertSame('', CompanyProfile::fragmentFrom('', ''));
        $this->assertSame('', CompanyProfile::fragmentFrom(" \n", "\n\n"));
    }

    public function testFragmentPresenteLEntrepriseCommeUneDonnee(): void
    {
        $both = CompanyProfile::fragmentFrom('Acme', "Fabricant de pièces.\n\nClients : distribution.");
        $this->assertStringStartsWith("\n\nEntreprise de l'utilisateur", $both);
        $this->assertStringContainsString(' : Acme.', $both);
        $this->assertStringContainsString("Fabricant de pièces.\n\nClients : distribution.", $both);
        $this->assertStringContainsString('rédigée par son administrateur', $both, 'Un texte écrit par un tiers : une donnée.');
        $this->assertStringContainsString('ne lui prête rien qu\'elle ne dise', $both);

        $nameOnly = CompanyProfile::fragmentFrom('Acme', '');
        $this->assertStringContainsString(' : Acme.', $nameOnly);
        $this->assertStringNotContainsString('Présentation de l\'entreprise', $nameOnly);

        $descOnly = CompanyProfile::fragmentFrom('', 'Fabricant de pièces.');
        $this->assertStringContainsString("cette application) :\nPrésentation", $descOnly);
    }

    public function testLectureDesReglages(): void
    {
        self::settings([
            CompanyProfile::SETTING_NAME        => "  Acme\nIndustries ",
            CompanyProfile::SETTING_DESCRIPTION => 'Fabricant de pièces.',
            CompanyProfile::SETTING_LOGO        => '/assets/img/branding/company-logo.png?v=42',
        ]);

        $this->assertSame('Acme Industries', CompanyProfile::name());
        $this->assertSame('/assets/img/branding/company-logo.png?v=42', CompanyProfile::logoUrl());
        $this->assertSame(
            ['logo' => '/assets/img/branding/company-logo.png', 'name' => 'Acme Industries', 'legal' => ''],
            CompanyProfile::brand(),
            'Une spec de deck refuse le cache-buster.'
        );
        $this->assertStringContainsString('Fabricant de pièces.', CompanyProfile::promptFragment());
    }

    /** Un réglage qui viserait une autre image (ou un autre site) n'est pas un logo d'entreprise. */
    public function testLogoLimiteASonEmplacement(): void
    {
        foreach (['https://exemple.com/logo.png', '/assets/img/logo.png', '/assets/img/branding/logo.png'] as $url) {
            self::settings([CompanyProfile::SETTING_LOGO => $url]);
            $this->assertSame('', CompanyProfile::logoUrl(), $url);
        }
    }

    /** Sans modèle, les présentations prennent la couleur d'accent de l'application. */
    public function testCouleurDAccentSansModele(): void
    {
        CompanyProfile::overrideTemplate('/inexistant/modele.pptx');
        self::settings(['accent_color' => '#C2410C']);
        $this->assertSame(['accent' => '#c2410c'], CompanyProfile::deckTheme());

        self::settings(['accent_color' => 'red; background:url(x)']);
        $this->assertSame([], CompanyProfile::deckTheme());
    }

    /** Sans base (script, panne), l'identité de l'entreprise ne fait rien échouer. */
    public function testLectureSansBaseNeLevePas(): void
    {
        $src = self::source('src/Helpers/CompanyProfile.php');
        $read = substr($src, (int)strpos($src, 'private static function setting('), 400);
        $this->assertStringContainsString('catch (Throwable)', $read);
    }

    public function testLangueDesFichiersOffice(): void
    {
        I18n::use('en');
        $this->assertSame('en-US', CompanyProfile::officeLanguage());
        I18n::use('fr');
        $this->assertSame('fr-FR', CompanyProfile::officeLanguage());
    }

    // ------------------------------------------------------------- modèle

    public function testCouleursDesPresentationsSuiventLeModele(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive indisponible.');
        }
        $path = FileScratch::path('modele-entreprise', 'pptx');
        try {
            PptxTemplateFixture::build($path);
            CompanyProfile::overrideTemplate($path);

            $this->assertSame($path, CompanyProfile::templatePath());
            $this->assertNotNull(CompanyProfile::templateInfo());
            $this->assertSame('#0055a4', CompanyProfile::deckTheme()['accent'] ?? null);

            CompanyProfile::overrideTemplate($path . '.absent');
            $this->assertNull(CompanyProfile::templatePath(), 'Fiche sans fichier : aucun modèle.');
            $this->assertNull(CompanyProfile::templateInfo());
        } finally {
            FileScratch::discard($path);
        }
    }

    // ------------------------------------------------------------- câblage

    /** Chaque conversation, la régénération comprise, et les présentations du chat. */
    public function testLEntrepriseEstDonneeAuxTroisPrompts(): void
    {
        $chat = self::source('src/Controllers/ChatController.php');
        $this->assertSame(3, substr_count($chat, 'CompanyProfile::promptFragment()'));

        foreach (['function runSend(', 'function regenerate('] as $method) {
            $body = substr($chat, (int)strpos($chat, $method), 60000);
            $profile = strpos($body, "\$systemPrompt = \$profile['system_prompt'] ?? 'Tu es un assistant utile.';");
            $company = strpos($body, '$systemPrompt .= CompanyProfile::promptFragment();');
            $this->assertNotFalse($profile, $method);
            $this->assertNotFalse($company, $method);
            $this->assertLessThan(200, $company - $profile, "$method : juste après le prompt de l'assistant.");
        }

        $deck = substr($chat, (int)strpos($chat, 'function buildPresentationContext('), 8000);
        $this->assertStringContainsString('CompanyProfile::promptFragment()', $deck);
    }

    public function testEcranEntreprise(): void
    {
        $page = self::source('templates/admin/company.php');
        $this->assertStringContainsString('<form method="POST" action="/admin/company" enctype="multipart/form-data" data-autosave', $page);
        $this->assertStringContainsString("\$page = 'company';", $page);
        $this->assertStringContainsString('id="entreprise"', $page);
        $this->assertStringContainsString('name="<?= CompanyProfile::SETTING_NAME ?>"', $page);
        $this->assertStringContainsString('name="<?= CompanyProfile::SETTING_DESCRIPTION ?>"', $page);
        $this->assertStringContainsString('name="company_logo"', $page);
        $this->assertStringContainsString('accept="image/png,image/jpeg"', $page, 'Ce que PowerPoint et le PDF savent dessiner.');
        $this->assertStringContainsString('name="company_template"', $page);
        $this->assertStringContainsString('accept=".pptx,.potx', $page);
        $this->assertMatchesRegularExpression('/name="remove_company_logo"[^>]*data-autosave-reset/', $page);
        $this->assertMatchesRegularExpression('/name="remove_company_template"[^>]*data-autosave-reset/', $page);
        $this->assertStringContainsString("require ROOT . '/templates/partials/company-template-status.php'", $page);
        $this->assertStringContainsString("['company_template', 'companyTemplateStatus'", $page, 'L\'état du modèle se repeint après un dépôt.');
        $this->assertStringContainsString("['company_word_template', 'companyWordTemplateStatus'", $page);
        $this->assertStringContainsString("require ROOT . '/templates/partials/company-word-template-status.php'", $page);

        $partial = self::source('templates/partials/company-template-status.php');
        $this->assertStringContainsString('id="companyTemplateStatus"', $partial);
        $this->assertStringContainsString('PptxTemplate::ROLE_LABELS', $partial);
    }

    /**
     * « Comment MIA remplit votre modèle » : une ligne par rôle de mise en
     * page, la MÊME correspondance que PptxTemplate::fill(). Un rôle ajouté
     * au remplissage sans sa ligne laisserait l'administrateur deviner.
     */
    public function testLaPageDitCommentMiaRemplitLeModele(): void
    {
        $partial = self::source('templates/partials/company-template-status.php');
        $this->assertStringContainsString('Comment MIA remplit votre modèle', $partial);
        $this->assertStringContainsString('Utilisé pour vos présentations', $partial);
        $this->assertStringContainsString('Ce qui vient de votre modèle', $partial);
        $this->assertStringContainsString('Ce que MIA ajoute', $partial);
        foreach (PptxTemplate::ROLES as $role) {
            $this->assertStringContainsString("'role' => '$role'", $partial, "Ligne du rôle $role.");
        }
        $fill = self::source('src/Slides/PptxTemplate.php');
        foreach (['cover', 'section', 'two', 'comparison', 'content', 'titleOnly'] as $role) {
            $this->assertStringContainsString("=> ['$role',", $fill, "Rôle $role tenu par le remplissage.");
        }

        // La carte d'une présentation dit à quel modèle elle sera remplie.
        $chat = self::source('templates/chat/index.php');
        $this->assertStringContainsString('window.FILE_DECK_TEMPLATE = <?= json_encode(CompanyProfile::templatePath() !== null ? CompanyProfile::name() : null', $chat);
        $js = self::source('public/assets/js/generated-files.js');
        $this->assertSame(4, substr_count($js, 'deckTemplateNote('), 'Définie une fois, appelée aux trois rendus de la mention.');
        $client = require ROOT . '/lang/en.client.php';
        $this->assertArrayHasKey('au modèle de {name}', $client);
        $this->assertArrayHasKey('au modèle de l’entreprise', $client);
    }

    /**
     * Deux pages, deux formulaires : l'entreprise (qui utilise MIA) n'est plus
     * une carte de Marque & apparence (l'application). Chaque handler n'écrit
     * que ses réglages — un champ homonyme posté au mauvais écran ne passe pas.
     */
    public function testEntrepriseEtMarqueSontDeuxPagesSeparees(): void
    {
        $routes = self::source('public/index.php');
        $this->assertStringContainsString("\$router->get('/admin/company',            [AdminController::class, 'company']);", $routes);
        $this->assertStringContainsString("\$router->post('/admin/company',           [AdminController::class, 'saveCompany']);", $routes);

        $marque = self::source('templates/admin/settings.php');
        foreach (['company_logo', 'company_template', 'CompanyProfile::', 'companyDescription', 'id="entreprise"'] as $trace) {
            $this->assertStringNotContainsString($trace, $marque, "Marque & apparence ne porte plus « $trace ».");
        }
        $this->assertStringContainsString('href="/admin/company"', $marque, 'La page renvoie vers celle de l\'entreprise.');
        $this->assertStringContainsString('href="/admin/settings"', self::source('templates/admin/company.php'), 'Et réciproquement.');

        $admin = self::source('src/Controllers/AdminController.php');
        $start = (int)strpos($admin, 'public function saveSettings(');
        $saveSettings = substr($admin, $start, (int)strpos($admin, 'public function saveCompany(') - $start);
        $this->assertStringNotContainsString('saveCompanyProfile', $saveSettings);
        $this->assertStringNotContainsString('CompanyProfile::', $saveSettings);
        $this->assertStringNotContainsString('company_', $saveSettings);

        $start = (int)strpos($admin, 'public function saveCompany(');
        $saveCompany = substr($admin, $start, (int)strpos($admin, 'private function saveCompanyProfile(') - $start);
        // Droit « Entreprise et apparence — Modifier » (Personnes → Rôles).
        $this->assertStringContainsString("AuthMiddleware::requirePermission('company.edit');", $saveCompany);
        $this->assertStringContainsString("'/admin/company'", $saveCompany);
        $this->assertStringNotContainsString("Settings::set('app_name'", $saveCompany);
        $this->assertStringNotContainsString('logo_url', str_replace('company_logo_url', '', $saveCompany));

        $company = substr($admin, (int)strpos($admin, 'public function company('), 600);
        $this->assertStringContainsString("AuthMiddleware::requirePermission('company.view');", $company);
        $this->assertStringContainsString("templates/admin/company.php", $company);

        // Menu : Organisation → Entreprise, puis Marque & apparence ; la page
        // Entreprise allume la rubrique, qui mène à sa première page.
        $this->assertSame(['company', 'settings', 'sso'], AdminAccess::ENTRY_PAGES['organisation']);
        $nav = self::source('templates/partials/admin-nav.php');
        $entreprise = strpos($nav, "\$navSub('company')");
        $marqueLien = strpos($nav, "\$navSub('settings')");
        $this->assertNotFalse($entreprise);
        $this->assertLessThan($marqueLien, $entreprise);
        $this->assertSame('/admin/company', AdminMenu::ENTRIES['organisation']['href']);

        // Écran de fin d'installation : une étape dédiée.
        $this->assertStringContainsString("'lien'   => '/admin/company',", self::source('src/Controllers/SetupController.php'));
    }

    public function testEnregistrement(): void
    {
        $admin = self::source('src/Controllers/AdminController.php');
        $save  = substr($admin, (int)strpos($admin, 'private function saveCompanyProfile('), 6000);

        $this->assertSame(1, substr_count($admin, '$this->saveCompanyProfile()'), 'Un seul écran écrit l\'entreprise.');
        $this->assertStringContainsString('$errors = $this->saveCompanyProfile();', $admin);
        $store = substr($admin, (int)strpos($admin, 'private function storeCompanyTemplate('), 6000);
        // Piège maison : un champ absent du POST n'est pas un champ vidé.
        $this->assertStringContainsString('if (isset($_POST[CompanyProfile::SETTING_NAME]))', $save);
        $this->assertStringContainsString('if (isset($_POST[CompanyProfile::SETTING_DESCRIPTION]))', $save);
        $this->assertStringContainsString('CompanyProfile::LOGO_TYPES[$mime]', $save);
        $this->assertStringContainsString('if (isset($_POST[CompanyProfile::SETTING_LEGAL]))', $save);
        $this->assertStringContainsString('if (isset($_POST[CompanyVocabulary::SETTING]))', $save);
        $this->assertStringContainsString("'pptx' => \$this->storeCompanyTemplate('pptx')", $save);
        $this->assertStringContainsString("'docx' => \$this->storeCompanyTemplate('docx')", $save);
        // Analysé AVANT d'être gardé : refusé, l'ancien modèle reste en place.
        $analyze = strpos($store, 'PptxTemplate::analyze(');
        $move    = strpos($store, 'move_uploaded_file((string)$file');
        $this->assertNotFalse($analyze);
        $this->assertNotFalse(strpos($store, 'DocxTemplate::analyze('));
        $this->assertLessThan($move, $analyze);
        $this->assertStringContainsString('@rename($tmp, $dest)', $store, 'Écriture atomique.');
        $this->assertStringContainsString("['pptx', 'potx']", $store);
        $this->assertStringContainsString("['docx', 'dotx']", $store);
        $this->assertStringContainsString('CompanyProfile::forgetThumbnail($kind, $dir);', $store, 'Une miniature périmée ne survit pas au modèle.');
    }

    /** Le modèle vit hors de la racine web, sur son volume, et suit la sauvegarde. */
    public function testModeleHorsRacineWebEtSurSonVolume(): void
    {
        $this->assertStringStartsNotWith('/public', CompanyProfile::TEMPLATE_DIR);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $compose) {
            $yaml = self::source($compose);
            $this->assertStringContainsString('storage_branding:/var/www/html/storage/branding', $yaml, $compose);
            $this->assertMatchesRegularExpression('/^\s+storage_branding:\s*$/m', $yaml, "$compose : volume déclaré.");
        }
        $this->assertStringContainsString('storage/branding', self::source('Dockerfile'));
        $this->assertStringContainsString('storage/branding', self::source('docker/entrypoint.sh'));
        $this->assertStringContainsString('storage/branding/', self::source('.gitignore'));
        $this->assertStringContainsString('storage/branding/', self::source('.dockerignore'));
        $this->assertContains('storage/branding', BackupService::FILE_DIRS);
    }

    /** Mention de pied de page : une ligne, bornée ; à défaut, le nom de l'entreprise. */
    public function testMentionDePiedDePage(): void
    {
        $this->assertSame('Acme SA · 12 rue des Forges · SIRET 123', CompanyProfile::sanitizeLegal("Acme SA\r\n12 rue des Forges\n\n  SIRET 123 \x07"));
        $this->assertSame(CompanyProfile::LEGAL_MAX, mb_strlen(CompanyProfile::sanitizeLegal(str_repeat('é', 500))));

        self::settings([CompanyProfile::SETTING_NAME => 'Acme']);
        $this->assertSame('Acme', CompanyProfile::documentFooter(), 'Sans mention : le nom.');
        self::settings([CompanyProfile::SETTING_NAME => 'Acme', CompanyProfile::SETTING_LEGAL => 'Acme SA · Document interne']);
        $this->assertSame('Acme SA · Document interne', CompanyProfile::documentFooter());
        $this->assertSame('Acme SA · Document interne', CompanyProfile::brand()['legal']);
        $this->assertSame(['footer' => 'Acme SA · Document interne', 'logo' => null], CompanyProfile::pdfChrome());
    }

    /** Présentations sans modèle : la mention prend la place du nom, à droite du logo. */
    public function testMentionDansLesSlides(): void
    {
        $slide = LayoutEngine::build(['title' => 'T', 'points' => ['a', 'b'], 'layout' => 'titre-contenu'], 1, 3, false,
            ['logo' => '/assets/img/branding/company-logo.png', 'name' => 'Acme', 'legal' => 'Acme SA · Document interne']);
        $texts = array_column(array_filter($slide['elements'], static fn ($e) => ($e['type'] ?? '') === 'text'), 'text', 'id');
        $this->assertSame('Acme SA · Document interne', $texts['mention'] ?? null);
        $this->assertArrayNotHasKey('marque', $texts);

        $sans = LayoutEngine::build(['title' => 'T', 'points' => ['a'], 'layout' => 'titre-contenu'], 1, 3, false, ['name' => 'Acme']);
        $this->assertSame('Acme', array_column(array_filter($sans['elements'], static fn ($e) => ($e['type'] ?? '') === 'text'), 'text', 'id')['marque'] ?? null);

        $this->assertStringContainsString("'footer' => CompanyProfile::documentFooter(),", self::source('src/Files/PptxFileBuilder.php'),
            'Modèle PowerPoint : la mention dans son espace réservé de pied de page.');
    }

    /** Les documents d'essai passent par chaque mise en page. */
    public function testContenusDEssai(): void
    {
        self::settings([CompanyProfile::SETTING_NAME => 'Acme']);
        $deck = MarkdownDeck::build(MarkdownDocument::parse(CompanyProfile::sampleMarkdown('pptx')), 'essai');
        $layouts = array_column($deck['slides'], 'layout');
        foreach (['titre', 'section', 'comparaison', 'chiffres', 'tableau', 'etapes', 'citation'] as $layout) {
            $this->assertContains($layout, $layouts, $layout);
        }
        $this->assertStringContainsString('Acme — vérification du modèle', CompanyProfile::sampleMarkdown('pptx'));

        $doc = CompanyProfile::sampleMarkdown('docx');
        foreach (["\n## ", "\n### ", "\n- ", "\n1. ", '| Indicateur', "\n> "] as $marque) {
            $this->assertStringContainsString($marque, $doc);
        }
    }

    /** Rubriques et exemples du descriptif : les exemples sont écrits avec les rubriques. */
    public function testRubriquesEtExemples(): void
    {
        $labels = array_column(CompanyProfile::DESCRIPTION_TOPICS, 'label');
        $this->assertSame($labels, array_unique($labels));
        foreach (CompanyProfile::DESCRIPTION_EXAMPLES as $example) {
            $this->assertLessThanOrEqual(CompanyProfile::DESCRIPTION_MAX, mb_strlen($example['text']));
            foreach (explode("\n", $example['text']) as $line) {
                $this->assertContains(trim(explode(':', $line)[0]), $labels, $example['label'] . ' : ' . $line);
            }
        }
        // Une consigne de comportement (ton) n'a rien à faire dans une information.
        $this->assertNotContains('Ton', $labels);
    }

    /** Page Entreprise : aperçu, rubriques, mention, modèle Word, essais. */
    public function testPageEntrepriseComplete(): void
    {
        $page = self::source('templates/admin/company.php');
        foreach (['id="presentation"', 'id="logo"', 'id="vocabulaire"', 'id="apercu"', 'id="modele-powerpoint"', 'id="modele-word"'] as $id) {
            $this->assertStringContainsString($id, $page);
        }
        $this->assertStringContainsString('name="<?= CompanyProfile::SETTING_LEGAL ?>"', $page);
        $this->assertStringContainsString('name="company_word_template"', $page);
        $this->assertStringContainsString('accept=".docx,.dotx', $page);
        $this->assertMatchesRegularExpression('/name="remove_company_word_template"[^>]*data-autosave-reset/', $page);
        $this->assertStringContainsString('href="/admin/company/sample/pptx"', $page);
        $this->assertStringContainsString('href="/admin/company/sample/docx"', $page);
        $this->assertStringContainsString("fetch('/admin/company/preview'", $page);
        $this->assertStringContainsString("'X-CSRF-Token': csrf", $page);
        // La question d'essai n'est jamais enregistrée.
        $this->assertMatchesRegularExpression('/<input type="text" id="companyPreviewQuestion"(?![^>]*\bname=)[^>]*data-autosave-skip/', $page);
        // Rubriques et exemples TRADUITS : l'écran passe par les accesseurs, jamais par les constantes.
        $this->assertStringContainsString('CompanyProfile::descriptionTopics()', $page);
        $this->assertStringContainsString('CompanyProfile::descriptionExamples()', $page);
        $this->assertStringNotContainsString('CompanyProfile::DESCRIPTION_TOPICS', $page);
        $this->assertStringNotContainsString('CompanyProfile::DESCRIPTION_EXAMPLES', $page);
        $this->assertStringContainsString("field.dispatchEvent(new Event('input', { bubbles: true }))", $page,
            'Un texte posé par un clic s\'enregistre comme une frappe.');
        $this->assertStringContainsString("replaceConfirm: <?= tj('Remplacer le descriptif actuel par cet exemple ?') ?>", $page);
        $this->assertStringContainsString('!confirm(COMPANY_TXT.replaceConfirm)', $page);

        $word = self::source('templates/partials/company-word-template-status.php');
        $this->assertStringContainsString('id="companyWordTemplateStatus"', $word);
        $this->assertStringContainsString('DocxTemplate::HEADING_LABELS', $word);
        foreach (['company-template-status.php', 'company-word-template-status.php'] as $partial) {
            $src = self::source('templates/partials/' . $partial);
            $this->assertStringContainsString('/admin/company/thumbnail/', $src);
            $this->assertStringContainsString('company-template-mock', $src, 'Sans miniature : un aperçu indicatif.');
        }
    }

    /** Points d'entrée de la page : admin seulement, l'aperçu n'écrit rien, l'essai passe par la fabrique. */
    public function testPointsDEntree(): void
    {
        $routes = self::source('public/index.php');
        $this->assertStringContainsString("post('/admin/company/preview',", $routes);
        $this->assertStringContainsString("get('/admin/company/sample/{kind}',", $routes);
        $this->assertStringContainsString("get('/admin/company/thumbnail/{kind}',", $routes);

        $admin = self::source('src/Controllers/AdminController.php');
        foreach (['previewCompany', 'companySample', 'companyThumbnail'] as $method) {
            $start = (int)strpos($admin, 'public function ' . $method . '(');
            $body  = substr($admin, $start, 2500);
            // Lectures (aperçu, contenus d'essai, miniature) : « Voir » suffit, rien n'est écrit.
            $this->assertStringContainsString("AuthMiddleware::requirePermission('company.view');", substr($body, 0, 300), $method);
        }
        $preview = substr($admin, (int)strpos($admin, 'public function previewCompany('), 2500);
        $this->assertStringNotContainsString('Settings::set', substr($preview, 0, (int)strpos($preview, 'exit;')));
        $this->assertStringContainsString('CompanyVocabulary::fragmentFrom($text, $context)', $preview);
        $sample = substr($admin, (int)strpos($admin, 'public function companySample('), 2500);
        $this->assertStringContainsString('FileFactory::build(', $sample);
        $this->assertStringContainsString("in_array(\$kind, ['pptx', 'docx'], true)", $sample);
    }

    public function testChargement(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $file) {
            $src = self::source($file);
            $this->assertStringContainsString('/src/Helpers/CompanyProfile.php', $src, $file);
            $exception = strpos($src, '/src/Slides/PptxTemplateException.php');
            $template  = strpos($src, '/src/Slides/PptxTemplate.php');
            $this->assertNotFalse($exception, $file);
            $this->assertLessThan($template, $exception, "$file : l'exception avant la classe.");
        }
    }
}
