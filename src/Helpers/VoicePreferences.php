<?php

declare(strict_types=1);

/**
 * Réglages de voix d'un utilisateur (conversation vocale) : vitesse de LECTURE
 * de la réponse et LONGUEUR voulue des réponses à l'oral.
 *
 * SOURCE UNIQUE des deux réglages — crans autorisés, libellés affichés,
 * consigne injectée au system prompt, normalisation, persistance. Deux
 * surfaces les modifient (Réglages → Préférences IA, et le bandeau de la
 * conversation vocale) : elles écrivent la MÊME colonne, il n'existe donc
 * jamais deux états à faire diverger.
 *
 * Deux natures bien distinctes, et c'est ce qui décide de l'implémentation :
 *
 *  - la VITESSE ne concerne que le navigateur (`playbackRate` de l'élément
 *    audio). Rien n'est re-synthétisé, donc rien n'est refacturé, et le cache
 *    disque des annonces (cf. SpeechSynthesizer::synthesizeCached) reste
 *    valable quelle que soit la vitesse choisie. Le paramètre `speed` de
 *    l'API de synthèse aurait imposé l'inverse : une clé de cache par vitesse
 *    et une re-synthèse à chaque réglage.
 *
 *  - la LONGUEUR ne concerne que le MODÈLE : c'est une consigne de prompt,
 *    lue à l'envoi du message et transportée dans la charge du job (le tour
 *    s'exécute dans le worker). Elle est plus courte que l'écrit par défaut :
 *    une réponse lue à voix haute se subit, là où une réponse écrite se
 *    parcourt.
 */
final class VoicePreferences
{
    /**
     * Crans de vitesse, en POURCENTAGE (100 = vitesse naturelle). Des crans
     * et non une valeur libre : c'est le vocabulaire des lecteurs audio, et
     * une vitesse au pour cent près n'apporte rien à l'oreille.
     */
    public const RATES = [75, 100, 125, 150, 175, 200];

    public const DEFAULT_RATE = 100;

    /**
     * Longueurs proposées. `fragment` est un prompt de MÉTHODE (il ne cite
     * rien de l'écran) : il reste en français, comme le reste de la consigne
     * orale. `label` et `hint`, eux, s'affichent — ils passent par t() dans
     * l'accesseur `lengths()`, une constante ne pouvant pas appeler t().
     */
    public const LENGTHS = [
        'bref' => [
            'label'    => 'Brèves',
            'hint'     => 'Deux à quatre phrases. L\'essentiel, puis MIA propose de développer.',
            'fragment' => 'Longueur : très courte — deux à quatre phrases. Donne le point essentiel et propose de développer si l\'utilisateur le souhaite ; n\'énumère pas tout ce que tu sais.',
        ],
        'standard' => [
            'label'    => 'Standard',
            'hint'     => 'Quelques phrases, un peu plus quand la question l\'exige.',
            'fragment' => 'Longueur : mesurée — quelques phrases, une dizaine au plus si la question l\'exige vraiment. Développe seulement ce qui sert la réponse.',
        ],
        'detaille' => [
            'label'    => 'Détaillées',
            'hint'     => 'Aussi complètes qu\'à l\'écrit, mais dites à l\'oral.',
            'fragment' => 'Longueur : tu peux développer aussi longuement qu\'à l\'écrit, mais en restant PARLÉ — enchaîne les idées par des phrases de liaison plutôt que par des listes, et marque les étapes à l\'oral (« d\'abord », « ensuite », « enfin »).',
        ],
    ];

    /**
     * Une réponse LUE est plus courte qu'une réponse écrite : c'est le défaut,
     * et il vaut pour qui n'a jamais choisi (colonne à '').
     */
    public const DEFAULT_LENGTH = 'bref';

    /**
     * Tronc commun de la consigne orale : ce qui ne dépend pas de la longueur.
     * Prompt de MÉTHODE (aucun libellé d'écran cité) → reste en français.
     */
    private const BASE_NOTE = "Conversation vocale : l'utilisateur te parle à voix haute (son message est une transcription, avec ses imprécisions) et ta réponse lui sera LUE par une synthèse vocale. Réponds comme à l'oral : phrases courtes et naturelles, sans mise en forme Markdown (ni titres, ni listes, ni tableaux, ni gras), sans bloc de code, sans adresse web ni chemin de fichier. Si la réponse appelle un contenu long ou structuré (code, tableau, liste détaillée), donne l'essentiel à l'oral et propose de le détailler par écrit. Si la transcription est ambiguë, demande une courte précision plutôt que de deviner.";

    // ------------------------------------------------------------------
    // Normalisation (pures)
    // ------------------------------------------------------------------

    /** Cran de vitesse valide, en pourcentage. Toute valeur hors liste → défaut. */
    public static function sanitizeRate(mixed $raw): int
    {
        $rate = (int)(is_numeric($raw) ? $raw : self::DEFAULT_RATE);

        return in_array($rate, self::RATES, true) ? $rate : self::DEFAULT_RATE;
    }

    /** Slug de longueur valide. Vide ou inconnu → défaut applicatif. */
    public static function sanitizeLength(mixed $raw): string
    {
        $slug = is_string($raw) ? trim($raw) : '';

        return isset(self::LENGTHS[$slug]) ? $slug : self::DEFAULT_LENGTH;
    }

    /** Vitesse en vigueur pour un utilisateur (ligne `users`, ou null). */
    public static function rateOf(?array $user): int
    {
        return self::sanitizeRate($user['voice_rate'] ?? null);
    }

    /** Longueur en vigueur pour un utilisateur (ligne `users`, ou null). */
    public static function lengthOf(?array $user): string
    {
        return self::sanitizeLength($user['voice_length'] ?? null);
    }

    // ------------------------------------------------------------------
    // Affichage (libellés calculés UNE fois, côté serveur)
    // ------------------------------------------------------------------

    /**
     * Libellé d'un cran de vitesse (« 1,25× », « 1.25× » en anglais).
     *
     * Rendu ICI et jamais reconstruit par le JavaScript : le gabarit passe la
     * table toute faite au front (`window.VOICE_PREFS.rates`), donc le
     * séparateur décimal ne peut pas diverger entre les deux surfaces.
     */
    public static function rateLabel(int $rate): string
    {
        $texte = number_format($rate / 100, 2, I18n::locale() === 'en' ? '.' : ',', '');
        // 1,00 → 1 ; 1,50 → 1,5 : les zéros de queue n'apprennent rien.
        $texte = rtrim($texte, '0');
        $texte = rtrim($texte, '.,');

        return $texte . '×';
    }

    /** @return array<int, array{value:int, label:string}> */
    public static function rateOptions(): array
    {
        return array_map(
            static fn(int $rate): array => ['value' => $rate, 'label' => self::rateLabel($rate)],
            self::RATES
        );
    }

    /**
     * Longueurs avec leurs libellés TRADUITS (cas A du contrat i18n : une
     * constante ne peut pas appeler t(), l'accesseur le fait pour elle).
     *
     * @return array<string, array{label:string, hint:string}>
     */
    public static function lengths(): array
    {
        $out = [];
        foreach (self::LENGTHS as $slug => $info) {
            $out[$slug] = ['label' => t($info['label']), 'hint' => t($info['hint'])];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Consigne de prompt
    // ------------------------------------------------------------------

    /**
     * Consigne de réponse ORALE complète : tronc commun + clause de longueur.
     * Appelée par ChatController::voiceModeNote(), seul point d'injection.
     */
    public static function promptNote(mixed $length = null): string
    {
        $slug = self::sanitizeLength($length);

        return self::BASE_NOTE . ' ' . self::LENGTHS[$slug]['fragment'];
    }

    // ------------------------------------------------------------------
    // Persistance
    // ------------------------------------------------------------------

    /**
     * Écrit les réglages fournis, et EUX SEULS : un appel qui ne porte que la
     * longueur ne touche pas à la vitesse. Le bandeau de la conversation
     * n'envoie qu'un réglage à la fois, et la carte des paramètres n'est
     * rendue que si la conversation vocale est proposée — remettre les
     * absents à leur défaut effacerait un choix que l'écran n'affichait même
     * pas (le piège maison « champ absent = valeur par défaut »).
     */
    public static function save(int $userId, ?int $rate = null, ?string $length = null): void
    {
        $sets   = [];
        $params = [];

        if ($rate !== null) {
            $sets[]   = 'voice_rate = ?';
            $params[] = self::sanitizeRate($rate);
        }
        if ($length !== null) {
            $sets[]   = 'voice_length = ?';
            $params[] = self::sanitizeLength($length);
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
