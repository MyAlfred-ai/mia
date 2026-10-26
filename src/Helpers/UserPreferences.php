<?php

declare(strict_types=1);

/**
 * Préférences IA d'un utilisateur : style de réponse + contexte personnel.
 * Source unique de vérité pour les presets style et l'assemblage du fragment
 * injecté dans le system prompt des conversations.
 */
final class UserPreferences
{
    /**
     * Styles proposés. `fragment` est la consigne injectée au system prompt
     * (prompt de MÉTHODE : il reste en français, cf. I18n) ; `label` et `hint`
     * s'AFFICHENT sur les cartes de Réglages → Préférences IA et passent par
     * t() dans l'accesseur styles() — une constante ne peut pas appeler t().
     *
     * Le `hint` est écrit pour l'utilisateur, jamais recopié du fragment : la
     * carte disait « Style : réponses courtes et directes, va à l'essentiel. »,
     * une ligne de prompt adressée au modèle, et restait en français en anglais.
     */
    public const STYLES = [
        'concis'    => ['label' => 'Concis',    'hint' => 'Court et direct : l\'essentiel, sans détour.',                 'fragment' => "Style : réponses courtes et directes, va à l'essentiel."],
        'detaille'  => ['label' => 'Détaillé',  'hint' => 'Structuré et complet, avec des exemples.',                      'fragment' => 'Style : réponses détaillées et structurées avec exemples.'],
        'technique' => ['label' => 'Technique', 'hint' => 'Vocabulaire de spécialiste, précision avant tout.',             'fragment' => 'Style : registre technique précis, vocabulaire de spécialiste.'],
        'vulgarise' => ['label' => 'Vulgarisé', 'hint' => 'Sans jargon, avec des analogies simples.',                     'fragment' => "Style : vulgarise, évite le jargon, utilise des analogies simples."],
        'formel'    => ['label' => 'Formel',    'hint' => 'Ton soutenu, vouvoiement.',                                     'fragment' => 'Style : ton formel et soutenu, vouvoiement systématique.'],
        'convivial' => ['label' => 'Convivial', 'hint' => 'Ton chaleureux, tutoiement.',                                   'fragment' => 'Style : ton chaleureux et convivial, tutoiement.'],
    ];

    public const MAX_CONTEXT_LEN = 500;

    /**
     * Renvoie le fragment à concaténer au system prompt, ou '' si rien à injecter.
     */
    public static function promptFragment(?array $user): string
    {
        if (!$user) {
            return '';
        }

        $parts = [];
        $style = (string)($user['ai_style'] ?? '');
        if ($style !== '' && isset(self::STYLES[$style])) {
            $parts[] = self::STYLES[$style]['fragment'];
        }

        $ctx = trim((string)($user['ai_user_context'] ?? ''));
        if ($ctx !== '') {
            // Le bloc est NOMMÉ « Votre contexte » — le titre exact de la carte
            // de Réglages → Préférences IA : quand l'utilisateur demande ce que
            // MIA sait de lui, la réponse reprend le vocabulaire de l'interface,
            // et il sait où aller le corriger.
            // Traduit (lot 3) : le bloc NOMME un écran (« Votre contexte »,
            // Réglages → Préférences IA). En anglais, ces libellés doivent
            // être ceux que l'utilisateur lit, sans quoi on l'envoie chercher
            // un titre qui n'existe pas.
            $parts[] = t("Votre contexte — ce que l'utilisateur a renseigné sur lui (Réglages → Préférences IA → « Votre contexte ») :")
                . "\n" . $ctx . "\n"
                . t("Sers-t'en comme arrière-plan sans le citer à chaque réponse ; mais si l'utilisateur demande ce que tu sais ou retiens de lui, restitue ce texte intégralement sous le titre « Votre contexte » et indique où le modifier.");
        }

        return $parts ? "\n\n" . implode("\n", $parts) : '';
    }

    /**
     * L'utilisateur a-t-il renseigné « Votre contexte » ? Distinct d'un fragment
     * non vide : un style seul ne dit RIEN sur l'utilisateur, il ne compte donc
     * pas comme une chose que MIA « sait de lui ».
     */
    public static function hasContext(?array $user): bool
    {
        return trim((string)($user['ai_user_context'] ?? '')) !== '';
    }

    /**
     * Styles avec leurs libellés et aides TRADUITS, pour l'écran (cas A du
     * contrat i18n : l'accesseur appelle t() pour la constante). Le fragment
     * de prompt n'en fait pas partie : il ne s'affiche pas.
     *
     * @return array<string, array{label:string, hint:string}>
     */
    public static function styles(): array
    {
        $out = [];
        foreach (self::STYLES as $slug => $info) {
            $out[$slug] = ['label' => t($info['label']), 'hint' => t($info['hint'])];
        }

        return $out;
    }

    /** Valide un slug de style (vide accepté = "par défaut"). */
    public static function isValidStyle(string $style): bool
    {
        return $style === '' || isset(self::STYLES[$style]);
    }

    /** Normalise un texte de contexte : trim + collapse espaces multiples. */
    public static function sanitizeContext(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/[ \t]+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace("/\r\n?/", "\n", $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;
        return $clean;
    }

    /** Met à jour les préférences IA d'un utilisateur. */
    public static function save(int $userId, string $style, string $context): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET ai_style = ?, ai_user_context = ? WHERE id = ?')
            ->execute([$style, $context, $userId]);
    }

    /**
     * Langue d'interface (migration 071). Une chaîne vide est une valeur VALIDE
     * et signifie « n'a jamais choisi » : Accept-Language et le défaut
     * d'instance reprennent alors la main (cf. I18n::resolve).
     */
    public static function saveLang(int $userId, string $lang): void
    {
        $lang = I18n::isSupported($lang) ? $lang : '';

        Database::getInstance()
            ->prepare('UPDATE users SET lang = ? WHERE id = ?')
            ->execute([$lang, $userId]);
    }
}
