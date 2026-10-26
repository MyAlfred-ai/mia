<?php

declare(strict_types=1);

/**
 * Réglages d'AFFICHAGE d'un utilisateur (Réglages → Affichage) : thème clair
 * ou sombre, taille d'affichage, contraste renforcé, et touche d'envoi des
 * messages du chat.
 *
 * SOURCE UNIQUE — valeurs autorisées, libellés, normalisation, persistance ET
 * attributs posés sur `<html>` par TOUS les gabarits. Les pages écrivaient
 * `data-theme="<?= Settings::theme() ?>"` chacune de leur côté (le thème de
 * l'instance, le même pour tout le monde) ; elles appellent désormais
 * `htmlAttributes()`, qui résout le choix de la personne connectée et retombe
 * sur le thème de l'instance pour qui n'a rien choisi (page de connexion
 * comprise). Un écran oublié afficherait le thème de l'instance à quelqu'un qui
 * en a choisi un autre : c'est le test qui l'empêche, pas la relecture.
 *
 * Thème — quatre valeurs, et c'est `''` qui compte :
 *  - `''`      « Par défaut » : celui que l'administrateur a choisi pour
 *              l'organisation (Marque & apparence), et qui SUIT ses changements.
 *              Distinct d'un choix explicite identique : l'écran le pré-coche,
 *              et sans cette valeur un enregistrement de la page (formulaire
 *              posté en entier) figerait le thème du jour à la place.
 *  - `light`, `dark` : imposés par la personne.
 *  - `system`  « Automatique » : suit le réglage clair/sombre de l'appareil. Le
 *              serveur ne le connaît pas : il rend le thème de l'instance et
 *              `data-theme-pref="system"`, et le script de head-meta.php le
 *              corrige AVANT le premier rendu (aucun flash), puis suit les
 *              changements de l'appareil en direct.
 *
 * Taille — `zoom` CSS sur `<html>` (cf. le bloc « Taille d'affichage » de
 * main.css) : TOUT grandit d'un même facteur, texte, boutons, icônes, images,
 * marges, comme le zoom du navigateur — que l'application mobile et la PWA
 * installée n'offrent pas. Une mise à l'échelle par `rem` aurait exigé de
 * réécrire les quelque 700 tailles en pixels de la feuille de style. Le
 * `zoom` a deux effets de bord, compensés là où ils se produisent :
 *  - une hauteur en `vh` (ou largeur en `vw`) est zoomée elle aussi et
 *    déborderait de l'écran → chaque unité d'écran est divisée par
 *    `var(--ui-zoom, 1)` dans les feuilles de style ;
 *  - une position calculée en JavaScript depuis getBoundingClientRect() (menus
 *    flottants) est exprimée en pixels d'ÉCRAN, puis zoomée une seconde fois
 *    une fois écrite dans `style.left` → `window.miaUiZoom()` (head-meta.php)
 *    donne le facteur à retirer.
 * Réglage continu (curseur de 80 à 150 %, par pas de 5) : la valeur part sur
 * `<html>` en style (`--ui-scale`), et c'est la feuille de style qui la
 * PLAFONNE selon la largeur de l'écran (`--ui-zoom-max` : 130 % jusqu'à
 * 1 024 px, 115 % jusqu'à 400 px) — une seule règle, que l'aperçu de la page
 * Affichage relit pour annoncer le plafond.
 * À 100 % (le défaut), aucun attribut n'est posé : rendu strictement inchangé.
 *
 * Contraste renforcé — `data-contrast="high"` sur `<html>` : textes, textes
 * secondaires et bordures redéfinis par les jetons de main.css (bloc
 * « Contraste renforcé »), et l'accent assombri en thème clair pour que les
 * liens et les boutons atteignent un contraste lisible. Rien d'autre ne
 * change : c'est une variante des deux thèmes, pas un troisième.
 *
 * Touche d'envoi — un comportement de saisie, rangé ici parce que c'est la
 * page des réglages d'interface. Lue par le chat seul (`data-send-key` sur
 * #msgInput, cf. sendKeyOf()) : `enter` envoie à Entrée (Maj+Entrée va à la
 * ligne), `mod_enter` envoie à Ctrl+Entrée ou ⌘+Entrée, et Entrée va à la
 * ligne — utile pour rédiger de longs messages, et au doigt, où seul le bouton
 * Envoyer envoie alors.
 */
final class DisplayPreferences
{
    /** Thèmes proposés. Clé `''` = celui de l'organisation (cf. en-tête). */
    public const THEMES = ['', 'light', 'dark', 'system'];

    /**
     * Taille d'affichage, en POURCENTAGE : de MIN_SCALE à MAX_SCALE par pas de
     * SCALE_STEP. En deçà de 80 %, le texte courant passe sous 12 px ; au-delà
     * de 150 %, même un grand écran n'a plus la largeur d'une mise en page de
     * bureau. Sur un écran plus étroit, la feuille de style plafonne encore
     * (cf. en-tête).
     */
    public const MIN_SCALE  = 80;
    public const MAX_SCALE  = 150;
    public const SCALE_STEP = 5;

    public const DEFAULT_SCALE = 100;

    /** Repères affichés sous le curseur (cliquables). */
    public const SCALE_TICKS = [80, 100, 125, 150];

    /** Touches d'envoi. `''` en base = jamais choisi = `enter`. */
    public const SEND_KEYS = ['enter', 'mod_enter'];

    public const DEFAULT_SEND_KEY = 'enter';

    /**
     * Libellés et aides, en français : la clé de traduction EST le texte
     * (contrat i18n). Une constante ne pouvant pas appeler t(), les accesseurs
     * themes() / scales() traduisent.
     */
    private const THEME_LABELS = [
        ''       => ['label' => 'Par défaut', 'hint' => 'Celui de votre organisation : {theme}.'],
        'light'  => ['label' => 'Clair', 'hint' => 'Fond clair, texte sombre.'],
        'dark'   => ['label' => 'Sombre', 'hint' => 'Fond sombre, plus reposant le soir.'],
        'system' => ['label' => 'Automatique', 'hint' => 'Suit le réglage clair ou sombre de votre appareil.'],
    ];

    private const CONTRAST_LABELS = [
        0 => ['label' => 'Standard', 'hint' => 'Les couleurs habituelles de MIA.'],
        1 => ['label' => 'Renforcé', 'hint' => 'Textes plus foncés ou plus clairs, bordures plus marquées, liens plus lisibles.'],
    ];

    private const SEND_KEY_LABELS = [
        'enter'     => ['label' => 'Entrée', 'hint' => 'Entrée envoie le message, Maj + Entrée va à la ligne.'],
        'mod_enter' => ['label' => 'Ctrl + Entrée', 'hint' => 'Ctrl + Entrée (⌘ + Entrée sur Mac) envoie, Entrée va à la ligne. Au doigt, seul le bouton Envoyer envoie.'],
    ];

    // ------------------------------------------------------------------
    // Normalisation (pures)
    // ------------------------------------------------------------------

    /** Thème valide ; toute autre valeur → `''` (celui de l'organisation). */
    public static function sanitizeTheme(mixed $raw): string
    {
        $theme = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($theme, self::THEMES, true) ? $theme : '';
    }

    /**
     * Taille valide, en pourcentage : arrondie au pas, bornée à [MIN, MAX].
     * Non numérique → défaut (100).
     */
    public static function sanitizeScale(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_SCALE;
        }
        $scale = (int)(round((float)$raw / self::SCALE_STEP) * self::SCALE_STEP);

        return max(self::MIN_SCALE, min(self::MAX_SCALE, $scale));
    }

    /** Toutes les valeurs du curseur, dans l'ordre. @return list<int> */
    public static function scaleSteps(): array
    {
        return range(self::MIN_SCALE, self::MAX_SCALE, self::SCALE_STEP);
    }

    /** Contraste renforcé ? Toute valeur « vraie » (1, '1', true, 'on'). */
    public static function sanitizeContrast(mixed $raw): bool
    {
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** Touche d'envoi valide ; vide ou inconnue → défaut (Entrée). */
    public static function sanitizeSendKey(mixed $raw): string
    {
        $key = is_string($raw) ? trim($raw) : '';

        return in_array($key, self::SEND_KEYS, true) ? $key : self::DEFAULT_SEND_KEY;
    }

    public static function contrastOf(?array $user): bool
    {
        return self::sanitizeContrast($user['ui_contrast'] ?? 0);
    }

    /** Touche d'envoi en vigueur pour un utilisateur (ligne `users`, ou null). */
    public static function sendKeyOf(?array $user): string
    {
        return self::sanitizeSendKey($user['ui_send_key'] ?? '');
    }

    /** Thème CHOISI par l'utilisateur (ligne `users`, ou null). */
    public static function themeOf(?array $user): string
    {
        return self::sanitizeTheme($user['ui_theme'] ?? '');
    }

    /** Taille choisie par l'utilisateur (ligne `users`, ou null). */
    public static function scaleOf(?array $user): int
    {
        return self::sanitizeScale($user['ui_scale'] ?? self::DEFAULT_SCALE);
    }

    /**
     * Thème RENDU par le serveur (`light` | `dark`) : le choix de la personne,
     * sinon celui de l'instance. « Automatique » rend aussi celui de
     * l'instance — seul le navigateur connaît le réglage de l'appareil, et le
     * script de head-meta.php corrige avant le premier rendu.
     */
    public static function resolveTheme(string $pref, string $instanceTheme): string
    {
        $instance = $instanceTheme === 'light' ? 'light' : 'dark';

        return in_array($pref, ['light', 'dark'], true) ? $pref : $instance;
    }

    /**
     * Attributs de `<html>`, échappés, sans espace de tête : à écrire tels
     * quels dans `<html lang="…" <?= … ?>>`.
     *
     * `data-theme-pref` n'est posé que pour un choix explicite : son absence
     * signifie « celui de l'organisation » (c'est ce que lit l'écran Marque &
     * apparence pour savoir s'il peut appliquer le thème de l'instance à la
     * page en cours). `data-ui-scale` et le facteur `--ui-scale` (style en
     * ligne : une valeur continue n'a pas de règle CSS par cran) ne sont posés
     * qu'en dehors de 100 %, `data-contrast` qu'en contraste renforcé.
     */
    public static function htmlAttributesFor(?array $user, string $instanceTheme): string
    {
        $pref  = self::themeOf($user);
        $scale = self::scaleOf($user);

        $out = 'data-theme="' . self::resolveTheme($pref, $instanceTheme) . '"';
        if ($pref !== '') {
            $out .= ' data-theme-pref="' . $pref . '"';
        }
        if ($scale !== self::DEFAULT_SCALE) {
            $out .= ' data-ui-scale="' . $scale . '" style="--ui-scale: ' . self::factor($scale) . '"';
        }
        if (self::contrastOf($user)) {
            $out .= ' data-contrast="high"';
        }

        return $out;
    }

    /**
     * Attributs de `<html>` pour la requête en cours (personne connectée,
     * sinon thème de l'instance). Ne lève jamais : une page d'erreur doit
     * pouvoir s'afficher même quand la base ne répond plus.
     */
    public static function htmlAttributes(): string
    {
        try {
            $user = Session::currentUser();
        } catch (\Throwable) {
            $user = null;
        }
        try {
            $instance = Settings::theme();
        } catch (\Throwable) {
            $instance = 'dark';
        }

        return self::htmlAttributesFor(is_array($user) ? $user : null, $instance);
    }

    /**
     * Thème rendu pour la requête en cours (`light` | `dark`) — celui que
     * porte `data-theme` : head-meta.php en tire la couleur de la barre du
     * navigateur.
     */
    public static function currentTheme(): string
    {
        try {
            $user     = Session::currentUser();
            $instance = Settings::theme();
        } catch (\Throwable) {
            return 'dark';
        }

        return self::resolveTheme(self::themeOf(is_array($user) ? $user : null), $instance);
    }

    /** Couleur de la barre du navigateur (`<meta name="theme-color">`). */
    public static function themeColor(string $theme): string
    {
        return $theme === 'light' ? '#f5f5f5' : '#212121';
    }

    // ------------------------------------------------------------------
    // Affichage (libellés traduits, calculés côté serveur)
    // ------------------------------------------------------------------

    /**
     * Thèmes avec libellé et aide TRADUITS. L'aide de « Par défaut » nomme le
     * thème de l'organisation, pour qu'on sache ce qu'on choisit.
     *
     * @return array<string, array{label:string, hint:string}>
     */
    public static function themes(string $instanceTheme): array
    {
        $instanceLabel = t(self::THEME_LABELS[$instanceTheme === 'light' ? 'light' : 'dark']['label']);
        $out = [];
        foreach (self::THEME_LABELS as $slug => $info) {
            $out[$slug] = [
                'label' => t($info['label']),
                'hint'  => t($info['hint'], ['theme' => mb_strtolower($instanceLabel)]),
            ];
        }

        return $out;
    }

    /** Facteur CSS d'une taille (110 → « 1.1 », 85 → « 0.85 ») : point décimal, toujours. */
    public static function factor(int $scale): string
    {
        return rtrim(rtrim(number_format($scale / 100, 2, '.', ''), '0'), '.');
    }

    /** Pourcentage mis en forme selon la langue (« 110 % » en français, « 110% » en anglais). */
    public static function percentLabel(int $scale): string
    {
        return $scale . (I18n::locale() === 'en' ? '' : "\u{00A0}") . '%';
    }

    /**
     * Libellé de CHAQUE valeur du curseur, calculé ici une fois pour toutes :
     * la page le passe au script (data-labels), qui n'a donc aucune règle de
     * mise en forme à recopier.
     *
     * @return array<int, string>
     */
    public static function scaleLabels(): array
    {
        $out = [];
        foreach (self::scaleSteps() as $scale) {
            $out[$scale] = self::percentLabel($scale);
        }

        return $out;
    }

    /**
     * Contrastes proposés, libellés traduits.
     *
     * @return array<int, array{label:string, hint:string}>
     */
    public static function contrasts(): array
    {
        $out = [];
        foreach (self::CONTRAST_LABELS as $value => $info) {
            $out[$value] = ['label' => t($info['label']), 'hint' => t($info['hint'])];
        }

        return $out;
    }

    /**
     * Touches d'envoi proposées, libellés traduits.
     *
     * @return array<string, array{label:string, hint:string}>
     */
    public static function sendKeys(): array
    {
        $out = [];
        foreach (self::SEND_KEY_LABELS as $slug => $info) {
            $out[$slug] = ['label' => t($info['label']), 'hint' => t($info['hint'])];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Persistance
    // ------------------------------------------------------------------

    /**
     * Écrit les réglages fournis, et EUX SEULS (null = inchangé) : même règle
     * que VoicePreferences::save — un appel partiel n'efface jamais l'autre
     * réglage (le piège maison « champ absent = valeur par défaut »).
     */
    public static function save(
        int $userId,
        ?string $theme = null,
        ?int $scale = null,
        ?bool $contrast = null,
        ?string $sendKey = null
    ): void {
        $sets   = [];
        $params = [];

        if ($theme !== null) {
            $sets[]   = 'ui_theme = ?';
            $params[] = self::sanitizeTheme($theme);
        }
        if ($scale !== null) {
            $sets[]   = 'ui_scale = ?';
            $params[] = self::sanitizeScale($scale);
        }
        if ($contrast !== null) {
            $sets[]   = 'ui_contrast = ?';
            $params[] = $contrast ? 1 : 0;
        }
        if ($sendKey !== null) {
            $sets[]   = 'ui_send_key = ?';
            $params[] = self::sanitizeSendKey($sendKey);
        }
        if (!$sets) {
            return;
        }

        $params[] = $userId;
        Database::getInstance()
            ->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);
    }
}
