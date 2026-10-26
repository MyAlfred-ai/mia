<?php

declare(strict_types=1);

/**
 * L'entreprise qui utilise MIA — SOURCE UNIQUE de son identité.
 *
 * Distincte de la MARQUE de l'application (nom « MIA », logo de la barre
 * latérale, couleur d'accent) : une organisation peut garder l'application
 * sous son nom d'origine et vouloir que ses présentations portent SON logo,
 * et que l'assistant sache qui elle est. Réglée dans Configuration →
 * Entreprise (/admin/company), page distincte de Marque & apparence.
 *
 *  - nom et descriptif → ajoutés au prompt de CHAQUE conversation
 *    (`promptFragment`), comme le vocabulaire commun : l'assistant situe les
 *    demandes (« notre offre », « nos clients ») sans qu'on le lui répète ;
 *  - logo → couverture et pied des présentations fabriquées par MIA ;
 *  - modèle PowerPoint → les présentations téléchargées reprennent ses
 *    masques, ses polices et ses couleurs (cf. PptxTemplate).
 *
 * Le logo est public (dossier `branding`, comme celui de l'application). Le
 * modèle PowerPoint ne l'est PAS : il vit sous `storage/branding` (volume
 * `storage_branding`), hors de la racine web — un modèle d'entreprise porte
 * souvent des slides d'exemple qui n'ont rien à faire en accès libre.
 */
final class CompanyProfile
{
    public const SETTING_NAME        = 'company_name';
    public const SETTING_DESCRIPTION = 'company_description';
    public const SETTING_LOGO        = 'company_logo_url';
    /** Fiche du modèle PowerPoint (JSON) : nom d'origine, taille, date, analyse. */
    public const SETTING_TEMPLATE    = 'company_template_json';
    /** Fiche du modèle Word (JSON), même forme. */
    public const SETTING_WORD_TEMPLATE = 'company_word_template_json';
    /** Mention portée en pied des documents produits (adresse, SIRET, « Document interne »…). */
    public const SETTING_LEGAL       = 'company_legal_footer';

    public const NAME_MAX        = 120;
    public const DESCRIPTION_MAX = 1000;
    public const LEGAL_MAX       = 200;

    /**
     * Rubriques proposées pour guider le descriptif (page Entreprise) : une
     * ligne « Rubrique : … » par sujet ; `hint` = ce qu'on y met. Libellés et
     * aides sont des clés de traduction : l'écran passe par descriptionTopics(),
     * jamais par la constante.
     *
     * @var array<string, array{label: string, hint: string}>
     */
    public const DESCRIPTION_TOPICS = [
        'activite'      => ['label' => 'Activité',      'hint' => 'ce que fait l\'entreprise, son secteur'],
        'clients'       => ['label' => 'Clients',       'hint' => 'pour qui elle travaille, ses marchés'],
        'offre'         => ['label' => 'Offre',         'hint' => 'produits, services, marques'],
        'implantations' => ['label' => 'Implantations', 'hint' => 'sites, pays, siège'],
        'chiffres'      => ['label' => 'Chiffres clés', 'hint' => 'effectif, chiffre d\'affaires, ancienneté'],
        'organisation'  => ['label' => 'Organisation',  'hint' => 'directions, équipes, filiales'],
        'valeurs'       => ['label' => 'Valeurs',       'hint' => 'mission, engagements, certifications'],
    ];

    /**
     * Descriptifs d'exemple à poser d'un clic, par profil d'organisation —
     * même structure que les rubriques. Chaque LIGNE du texte est une clé de
     * traduction (aucune clé multiligne au dictionnaire) : l'écran passe par
     * descriptionExamples(), jamais par la constante.
     *
     * @var list<array{label: string, text: string}>
     */
    public const DESCRIPTION_EXAMPLES = [
        ['label' => 'Industrie', 'text' => "Activité : fabricant de pièces de robinetterie pour l'habitat.\nClients : grande distribution de bricolage et professionnels du bâtiment, en France et en Europe.\nOffre : robinets, siphons, mécanismes de chasse d'eau, sous trois marques.\nImplantations : siège et usine à Nantes, entrepôt à Lyon.\nChiffres clés : 400 salariés, 90 M€ de chiffre d'affaires.\nValeurs : qualité certifiée ISO 9001, fabrication française."],
        ['label' => 'Services', 'text' => "Activité : cabinet de conseil en organisation et transformation numérique.\nClients : ETI et collectivités du Grand Ouest.\nOffre : diagnostic, accompagnement au changement, formation.\nImplantations : Rennes et Nantes.\nChiffres clés : 35 consultants.\nValeurs : indépendance, transmission des savoir-faire."],
        ['label' => 'Association', 'text' => "Activité : association d'aide à l'insertion professionnelle des jeunes.\nClients : jeunes de 16 à 25 ans, entreprises partenaires, financeurs publics.\nOffre : ateliers, parrainage, chantiers d'insertion.\nImplantations : 4 antennes en Bretagne.\nOrganisation : 20 salariés, 150 bénévoles."],
    ];

    /** Plafond d'un modèle PowerPoint téléversé (octets). */
    public const TEMPLATE_MAX_BYTES = 20_000_000;

    /** Dossier du modèle, relatif à ROOT — hors de `public/`, sur son volume. */
    public const TEMPLATE_DIR  = '/storage/branding';
    public const TEMPLATE_FILE = 'company-template.pptx';
    public const WORD_TEMPLATE_FILE = 'company-template.docx';

    /**
     * Miniature d'un modèle : celle que PowerPoint ou Word enregistrent dans
     * le fichier (`docProps/thumbnail`), extraite à côté du modèle. Nom fixe
     * par type, extension selon l'image.
     *
     * @var array<string, string> type → radical
     */
    public const THUMB_STEMS = ['pptx' => 'company-template-thumb', 'docx' => 'company-word-thumb'];

    /** Logo : dossier public des marques, nom fixe (l'extension suit l'image). */
    public const LOGO_DIR  = '/public/assets/img/branding';
    public const LOGO_STEM = 'company-logo';

    /**
     * Formats de logo acceptés : ceux que PowerPoint ET le PDF savent
     * dessiner. Un SVG ou un WebP s'afficherait à l'écran et disparaîtrait
     * des présentations, sans un mot.
     *
     * @var array<string, string> type MIME → extension
     */
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /**
     * Rubriques du descriptif, TRADUITES (cas A du contrat i18n : la constante
     * ne peut pas appeler t()). Un descriptif écrit en anglais doit trouver
     * « Activity: », pas « Activité : » — les exemples traduits portent les
     * mêmes libellés traduits.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function descriptionTopics(): array
    {
        $topics = [];
        foreach (self::DESCRIPTION_TOPICS as $key => $topic) {
            $topics[$key] = ['label' => t($topic['label']), 'hint' => t($topic['hint'])];
        }

        return $topics;
    }

    /**
     * Exemples du descriptif, TRADUITS ligne par ligne : c'est du texte livré
     * avec l'application, il suit la langue de qui le pose (un texte écrit
     * ensuite par l'administrateur, lui, n'est jamais traduit).
     *
     * @return list<array{label: string, text: string}>
     */
    public static function descriptionExamples(): array
    {
        return array_map(
            static fn (array $example): array => [
                'label' => t($example['label']),
                'text'  => implode("\n", array_map(
                    static fn (string $line): string => t($line),
                    explode("\n", $example['text'])
                )),
            ],
            self::DESCRIPTION_EXAMPLES
        );
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public static function name(): string
    {
        return self::sanitizeName(self::setting(self::SETTING_NAME));
    }

    public static function description(): string
    {
        return self::sanitizeDescription(self::setting(self::SETTING_DESCRIPTION));
    }

    /** URL publique du logo (avec son cache-buster), '' sans logo. */
    public static function logoUrl(): string
    {
        $url = self::setting(self::SETTING_LOGO);

        return str_starts_with($url, '/assets/img/branding/' . self::LOGO_STEM . '.') ? $url : '';
    }

    /** URL du logo sans cache-buster : la forme qu'accepte une spec de deck. */
    public static function logoAssetUrl(): string
    {
        return (string)strtok(self::logoUrl(), '?');
    }

    /**
     * Mention en pied des documents (une ligne, ≤ LEGAL_MAX caractères),
     * '' sans mention.
     */
    public static function legalMention(): string
    {
        return self::sanitizeLegal(self::setting(self::SETTING_LEGAL));
    }

    /**
     * Texte du pied de page des documents produits : la mention s'il y en a
     * une (elle nomme d'ordinaire l'entreprise), sinon le nom.
     */
    public static function documentFooter(): string
    {
        $legal = self::legalMention();

        return $legal !== '' ? $legal : self::name();
    }

    /**
     * Habillage d'un PDF produit pour une personne (PdfExportService) : logo
     * en en-tête, mention et numéro de page en pied.
     *
     * @return array{footer: string, logo: ?string}
     */
    public static function pdfChrome(): array
    {
        return ['footer' => self::documentFooter(), 'logo' => self::logoPath()];
    }

    /** Chemin LOCAL du logo (pour Dompdf, PhpWord), null sans logo. */
    public static function logoPath(): ?string
    {
        $url = self::logoAssetUrl();
        if ($url === '') {
            return null;
        }
        $path = (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . '/public' . $url;

        return is_file($path) ? $path : null;
    }

    /**
     * Modèle imposé par un test (null = celui du volume). Les fabricants le
     * lisent par templatePath() : sans ce point d'appui, éprouver le chemin
     * « modèle de l'entreprise » obligerait à écrire dans `storage/branding`
     * du dépôt — monté en lecture seule dans le conteneur de test.
     */
    private static ?string $templateOverride = null;
    private static ?string $wordTemplateOverride = null;

    public static function overrideTemplate(?string $path): void
    {
        self::$templateOverride = $path;
    }

    public static function overrideWordTemplate(?string $path): void
    {
        self::$wordTemplateOverride = $path;
    }

    /** Chemin local du modèle Word, null s'il n'y en a pas. */
    public static function wordTemplatePath(): ?string
    {
        if (self::$wordTemplateOverride !== null) {
            return is_file(self::$wordTemplateOverride) ? self::$wordTemplateOverride : null;
        }
        $path = self::templateDir() . '/' . self::WORD_TEMPLATE_FILE;

        return is_file($path) ? $path : null;
    }

    /**
     * Fiche du modèle Word, null sans modèle (même règle que templateInfo :
     * une fiche sans fichier ne compte pas).
     *
     * @return array<string, mixed>|null
     */
    public static function wordTemplateInfo(): ?array
    {
        if (self::wordTemplatePath() === null) {
            return null;
        }
        $info = json_decode(self::setting(self::SETTING_WORD_TEMPLATE), true);

        return is_array($info) ? $info : ['name' => self::WORD_TEMPLATE_FILE];
    }

    /**
     * Miniature d'un modèle ('pptx' | 'docx') : extraite du fichier au premier
     * besoin, puis gardée à côté de lui. Null si le modèle n'en porte pas
     * (Word ne l'enregistre pas par défaut) ou n'est qu'un format qu'un
     * navigateur ne sait pas afficher (WMF, EMF).
     *
     * @return array{path: string, mime: string}|null
     */
    public static function templateThumbnail(string $kind): ?array
    {
        $stem = self::THUMB_STEMS[$kind] ?? null;
        $template = $kind === 'docx' ? self::wordTemplatePath() : ($kind === 'pptx' ? self::templatePath() : null);
        if ($stem === null || $template === null) {
            return null;
        }
        $dir = dirname($template);
        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $ext => $mime) {
            $path = $dir . '/' . $stem . '.' . $ext;
            if (is_file($path) && filemtime($path) >= filemtime($template)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }

        try {
            $thumb = OfficePackage::thumbnail($template);
        } catch (Throwable) {
            $thumb = null;
        }
        self::forgetThumbnail($kind, $dir);
        if ($thumb === null) {
            return null;
        }
        $path = $dir . '/' . $stem . '.' . $thumb['ext'];
        if (@file_put_contents($path, $thumb['bytes']) === false) {
            return null;
        }

        return ['path' => $path, 'mime' => $thumb['ext'] === 'png' ? 'image/png' : 'image/jpeg'];
    }

    /** Efface la miniature d'un modèle (retiré ou remplacé). */
    public static function forgetThumbnail(string $kind, ?string $dir = null): void
    {
        $stem = self::THUMB_STEMS[$kind] ?? null;
        if ($stem === null) {
            return;
        }
        foreach (['jpg', 'png'] as $ext) {
            @unlink(($dir ?? self::templateDir()) . '/' . $stem . '.' . $ext);
        }
    }

    /** Chemin local du modèle PowerPoint, null s'il n'y en a pas. */
    public static function templatePath(): ?string
    {
        if (self::$templateOverride !== null) {
            return is_file(self::$templateOverride) ? self::$templateOverride : null;
        }
        $path = self::templateDir() . '/' . self::TEMPLATE_FILE;

        return is_file($path) ? $path : null;
    }

    /**
     * Fiche du modèle (nom d'origine, taille, date, mises en page reconnues),
     * null sans modèle. La fiche sans fichier (volume perdu) ne compte pas :
     * l'écran dirait « modèle en place » pour des présentations qui ne le
     * suivraient plus.
     *
     * @return array<string, mixed>|null
     */
    public static function templateInfo(): ?array
    {
        if (self::templatePath() === null) {
            return null;
        }
        $info = json_decode(self::setting(self::SETTING_TEMPLATE), true);

        return is_array($info) ? $info : ['name' => self::TEMPLATE_FILE];
    }

    /**
     * Marque des présentations fabriquées par MIA (cf. LayoutEngine) : logo
     * (URL d'une image de l'application), nom, et mention de pied de page.
     *
     * @return array{logo: string, name: string, legal: string}
     */
    public static function brand(): array
    {
        return ['logo' => self::logoAssetUrl(), 'name' => self::name(), 'legal' => self::legalMention()];
    }

    /**
     * Couleurs des présentations fabriquées par MIA : celles du modèle
     * PowerPoint de l'entreprise s'il y en a un — le PDF de slides ressemble
     * alors au PowerPoint —, sinon la couleur d'accent de l'application.
     *
     * @return array<string, string>
     */
    public static function deckTheme(): array
    {
        $template = self::templatePath();
        if ($template !== null) {
            $info    = self::templateInfo();
            $palette = is_array($info['palette'] ?? null) ? $info['palette'] : PptxTemplate::themeColors($template);
            $palette = array_filter(
                array_map('strval', $palette),
                static fn (string $c): bool => preg_match('/^#[0-9a-f]{6}$/i', $c) === 1
            );
            if ($palette !== []) {
                return $palette;
            }
        }
        $accent = self::setting('accent_color');

        return preg_match('/^#[0-9a-f]{6}$/i', $accent) === 1 ? ['accent' => strtolower($accent)] : [];
    }

    /** Langue de vérification d'un fichier Office, d'après la langue de la personne. */
    public static function officeLanguage(): string
    {
        return I18n::locale() === 'en' ? 'en-US' : 'fr-FR';
    }

    /**
     * Lecture d'un réglage, sans jamais lever : l'identité de l'entreprise
     * habille une réponse ou un fichier, elle ne doit pas les faire échouer
     * (base indisponible, script sans base).
     */
    private static function setting(string $key): string
    {
        try {
            return (string)Settings::get($key, '');
        } catch (Throwable) {
            return '';
        }
    }

    public static function templateDir(): string
    {
        return (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . self::TEMPLATE_DIR;
    }

    // ------------------------------------------------------------------
    // Documents d'essai (page Entreprise)
    // ------------------------------------------------------------------

    /**
     * Contenu d'un document d'essai : une présentation qui passe par chaque
     * mise en page (couverture, contenu, intercalaire, deux colonnes,
     * comparaison, chiffres, tableau, étapes, citation, conclusion), ou un
     * document Word qui montre titres, listes, tableau et citation. Écran
     * d'administration : en français.
     */
    public static function sampleMarkdown(string $kind): string
    {
        $name = self::name() !== '' ? self::name() : 'Votre entreprise';
        if ($kind === 'pptx') {
            return "# Présentation d'essai\n\n" . $name . " — vérification du modèle\n\n"
                . "## Contexte\n\n- Un point de contenu, dans la mise en page « Titre et contenu »\n- Un deuxième point, plus long, pour voir comment le texte revient à la ligne dans l'espace réservé du modèle\n- Un troisième point\n\nNotes : la note du présentateur de cette slide.\n\n"
                . "## Première partie\n\n"
                . "## Deux colonnes\n\n- Premier\n- Deuxième\n- Troisième\n- Quatrième\n- Cinquième\n- Sixième\n- Septième\n- Huitième\n\n"
                . "## Comparaison\n\n### Aujourd'hui\n\n- Saisie manuelle\n- Délais d'une semaine\n\n### Demain\n\n- Saisie automatique\n- Réponse dans la journée\n\n"
                . "## Chiffres clés\n\n- **42 %** — de demandes traitées en moins d'une heure\n- **3 200** — clients accompagnés\n- **12** — sites en France\n\n"
                . "## Un tableau\n\n| Indicateur | 2025 | 2026 |\n|---|---|---|\n| Chiffre d'affaires | 80 M€ | 90 M€ |\n| Effectif | 380 | 400 |\n| Sites | 11 | 12 |\n\n"
                . "## Les étapes\n\n1. Cadrer le besoin\n2. Construire la solution\n3. Déployer\n4. Mesurer\n\n"
                . "## Citation\n\n> Une citation, pour voir sa mise en page.\n\n— Une personne\n\n"
                . "## Prochaines étapes\n\n- Valider le modèle\n- Prévenir les utilisateurs\n";
        }

        return "# Document d'essai\n\n" . $name . " — vérification du modèle Word.\n\n"
            . "## Un titre de niveau 2\n\nUn paragraphe de texte courant, avec du **gras**, de l'*italique* et [un lien](https://www.example.com). "
            . "Il est assez long pour voir l'interligne, la police et la couleur du texte du modèle sur plusieurs lignes.\n\n"
            . "### Un titre de niveau 3\n\n- Une liste à puces\n- Deuxième élément\n  - Un sous-élément\n\n1. Une liste numérotée\n2. Deuxième élément\n\n"
            . "| Indicateur | 2025 | 2026 |\n|---|---|---|\n| Chiffre d'affaires | 80 M€ | 90 M€ |\n| Effectif | 380 | 400 |\n\n"
            . "> Une citation, pour voir sa mise en forme.\n\nFin du document d'essai.\n";
    }

    // ------------------------------------------------------------------
    // Prompt
    // ------------------------------------------------------------------

    /** Fragment du prompt système : l'entreprise, '' si rien n'est renseigné. */
    public static function promptFragment(): string
    {
        return self::fragmentFrom(self::name(), self::description());
    }

    /**
     * Forme PURE du fragment. Prompt de MÉTHODE, en français comme les autres :
     * il ne cite aucun libellé de l'écran. Le descriptif est un texte écrit par
     * l'administrateur — une donnée, pas une consigne qui pourrait élargir ce
     * que l'assistant a le droit de faire.
     */
    public static function fragmentFrom(string $name, string $description): string
    {
        $name        = self::sanitizeName($name);
        $description = self::sanitizeDescription($description);
        if ($name === '' && $description === '') {
            return '';
        }

        $out = "\n\nEntreprise de l'utilisateur (l'organisation qui utilise cette application)"
            . ($name !== '' ? ' : ' . $name . '.' : ' :');
        if ($description !== '') {
            $out .= "\nPrésentation de l'entreprise, rédigée par son administrateur :\n" . $description;
        }

        return $out . "\nSers-t'en pour situer les demandes (« notre offre », « nos clients »…) ;"
            . " ne la cite que si c'est utile, et ne lui prête rien qu'elle ne dise.";
    }

    // ------------------------------------------------------------------
    // Saisie
    // ------------------------------------------------------------------

    /**
     * Mention de pied de page : une ligne (les retours deviennent « · »),
     * sans caractère de contrôle, bornée à LEGAL_MAX caractères.
     */
    public static function sanitizeLegal(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_filter(array_map('trim', explode("\n", $value)), static fn (string $l): bool => $l !== '');
        $value = implode(' · ', $lines);
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, self::LEGAL_MAX);
    }

    /** Nom : une ligne, sans caractère de contrôle, borné. */
    public static function sanitizeName(string $value): string
    {
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, self::NAME_MAX);
    }

    /**
     * Descriptif : paragraphes conservés (une ligne vide au plus entre deux),
     * caractères de contrôle retirés, borné à DESCRIPTION_MAX caractères.
     */
    public static function sanitizeDescription(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);
        $value = (string)preg_replace('/[ \t]+\n/u', "\n", $value);
        $value = trim((string)preg_replace('/\n{3,}/u', "\n\n", $value));

        return rtrim(mb_substr($value, 0, self::DESCRIPTION_MAX));
    }
}
