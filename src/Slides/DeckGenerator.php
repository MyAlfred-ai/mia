<?php

declare(strict_types=1);

/**
 * Génération d'une présentation par LLM : prompts, décodage et garanties.
 *
 * Cette classe ne fait AUCUN appel réseau — elle produit les prompts et
 * interprète les réponses. L'orchestration (adaptateur LLM, SSE, facturation)
 * reste dans ChatController, comme pour les autres outils.
 *
 * Deux étapes distinctes, volontairement séparées :
 *  1. le PLAN (titres + points d'intention), montré à l'utilisateur et éditable ;
 *  2. la RÉDACTION des slides à partir du plan validé, par lots.
 *
 * Le nombre de slides n'est jamais laissé à l'appréciation du modèle : il est
 * corrigé de façon déterministe côté serveur (cf. enforceSlideCount).
 */
final class DeckGenerator
{
    /** Slides rédigées par appel LLM. Compromis coût/latence/robustesse du JSON. */
    public const BATCH_SIZE = 4;

    /** Consignes de longueur, par option de l'étape « Options ». */
    private const LENGTH_RULES = [
        'court'    => 'Chaque point fait 3 à 7 mots. Style télégraphique, aucun verbe conjugué superflu.',
        'moyen'    => 'Chaque point fait une phrase courte de 10 à 18 mots.',
        'detaille' => 'Chaque point fait une à deux phrases, 20 à 35 mots au total.',
    ];

    /** Consignes de ton. */
    private const TONE_RULES = [
        'professionnel' => 'Ton professionnel et factuel. Pas de superlatifs, pas d\'emphase.',
        'pedagogique'   => 'Ton pédagogique : progression du simple au complexe, exemples concrets, vocabulaire explicité.',
        'inspirant'     => 'Ton inspirant : bénéfices, vision, projection. Formules mémorables mais jamais creuses.',
        'direct'        => 'Ton direct : phrases courtes, verbes d\'action, une idée par point.',
    ];

    /** Nombre de points attendus par slide selon la longueur choisie. */
    private const POINTS_PER_SLIDE = [
        'court'    => '4 à 6',
        'moyen'    => '3 à 5',
        'detaille' => '3 à 4',
    ];

    /** Partie éditoriale du prompt : celle de l'administrateur, ou le défaut. */
    private static function persona(string $persona): string
    {
        $persona = trim($persona);
        return $persona !== '' ? $persona : ToolPrompts::defaultFor('presentation');
    }

    // ------------------------------------------------------------ étape 1 : plan

    /**
     * Prompt système du plan.
     *
     * `$persona` = la partie ÉDITORIALE, éditable par l'administrateur
     * (ToolPrompts::defaultFor('presentation') quand rien n'est saisi). Le
     * contrat JSON qui suit n'est jamais éditable : sans lui, le volet ne
     * saurait pas lire la réponse.
     */
    public static function outlineSystemPrompt(array $options, string $persona = ''): string
    {
        $count  = DeckSpec::slideCount($options['slide_count'] ?? DeckSpec::DEFAULT_SLIDES);
        $length = (string)($options['text_length'] ?? 'moyen');
        $tone   = (string)($options['tone'] ?? 'professionnel');
        $images = ((string)($options['image_style'] ?? '')) !== '';
        $layouts = implode(', ', DeckSpec::LAYOUTS);
        $points  = self::POINTS_PER_SLIDE[$length] ?? '3 à 5';

        $prompt = self::persona($persona)
            . "\n\nÉTAPE EN COURS : le PLAN de la présentation (titres et points d'intention), pas encore le texte définitif."
            . "\n\nRéponds UNIQUEMENT avec un objet JSON valide : aucun texte avant ou après, pas de balises markdown, pas de ```."
            . "\nFormat EXACT :"
            . "\n{\"title\":\"titre de la présentation\",\"slides\":[{\"title\":\"titre de la slide\",\"points\":[\"point 1\",\"point 2\"],\"layout\":\"titre-contenu\",\"image_prompt\":\"\"}]}"
            . "\n\nRÈGLES IMPÉRATIVES :"
            . "\n- Le tableau \"slides\" contient EXACTEMENT {$count} entrées. Compte-les avant de répondre."
            . "\n- La PREMIÈRE slide est la couverture : layout \"titre\", un seul point servant de sous-titre."
            . "\n- La DERNIÈRE slide est la synthèse : layout \"conclusion\"."
            . "\n- Les autres slides portent {$points} points chacune."
            . "\n- \"layout\" vaut obligatoirement l'une de ces valeurs : {$layouts}."
            . "\n- Varie les layouts selon le contenu : \"deux-colonnes\" pour une liste longue, \"citation\" pour une formule forte,"
            . " \"section\" pour ouvrir une partie, \"titre-contenu\" par défaut."
            . "\n- Aucune syntaxe markdown dans les textes (ni **, ni #, ni tirets de liste)."
            . "\n- Pas de numérotation manuelle des slides : elle est ajoutée automatiquement.";

        $prompt .= "\n\nSTYLE :\n- " . (self::LENGTH_RULES[$length] ?? self::LENGTH_RULES['moyen'])
            . "\n- " . (self::TONE_RULES[$tone] ?? self::TONE_RULES['professionnel']);

        if ($images) {
            $prompt .= "\n\nILLUSTRATIONS :"
                . "\n- \"image_prompt\" décrit le VISUEL à générer, en une phrase autoportante (sujet, cadrage, ambiance)."
                . " Le modèle d'image ne voit ni la slide ni la présentation."
                . "\n- Aucun texte, aucun mot, aucun logo dans l'image demandée : les modèles les rendent mal."
                . "\n- Renseigne \"image_prompt\" pour 3 à 5 slides au maximum, celles qui gagnent vraiment à être illustrées."
                . " Laisse la chaîne vide partout ailleurs, et toujours vide sur les slides \"deux-colonnes\"."
                . "\n- Utilise le layout \"image-droite\" ou \"image-pleine\" sur les slides que tu illustres.";
        } else {
            $prompt .= "\n\nAucune image ne sera générée : \"image_prompt\" reste TOUJOURS une chaîne vide,"
                . " et les layouts \"image-droite\" et \"image-pleine\" sont interdits.";
        }

        return $prompt;
    }

    /** Prompt utilisateur du plan : sujet, documents joints, rappel du nombre. */
    public static function outlineUserPrompt(string $subject, string $documents, array $options): string
    {
        $count  = DeckSpec::slideCount($options['slide_count'] ?? DeckSpec::DEFAULT_SLIDES);
        $prompt = "SUJET DE LA PRÉSENTATION :\n" . trim($subject);

        if (trim($documents) !== '') {
            $prompt .= "\n\nDOCUMENTS FOURNIS (source prioritaire — appuie-toi dessus plutôt que sur des généralités) :\n"
                . trim($documents);
        }

        return $prompt . "\n\nProduis le plan en EXACTEMENT {$count} slides, au format JSON demandé.";
    }

    // ------------------------------------------------- étape 2 : rédaction

    /** Prompt système de rédaction des slides (même partie éditoriale que le plan). */
    public static function slidesSystemPrompt(array $options, string $persona = ''): string
    {
        $length  = (string)($options['text_length'] ?? 'moyen');
        $tone    = (string)($options['tone'] ?? 'professionnel');
        $layouts = implode(', ', DeckSpec::LAYOUTS);

        return self::persona($persona)
            . "\n\nÉTAPE EN COURS : la RÉDACTION du contenu définitif des slides, à partir d'un plan déjà validé."
            . "\n\nRéponds UNIQUEMENT avec un objet JSON valide : aucun texte avant ou après, pas de markdown, pas de ```."
            . "\nFormat EXACT :"
            . "\n{\"slides\":[{\"id\":\"s1\",\"title\":\"...\",\"points\":[\"...\"],\"layout\":\"titre-contenu\",\"image_prompt\":\"\",\"notes\":\"...\"}]}"
            . "\n\nRÈGLES IMPÉRATIVES :"
            . "\n- Rédige UNIQUEMENT les slides demandées dans le message, en conservant leur \"id\" à l'identique."
            . "\n- Conserve l'intention de chaque titre du plan ; tu peux l'affiner, pas le remplacer par un autre sujet."
            . "\n- \"points\" contient le texte FINAL affiché sur la slide, pas des notes de travail."
            . "\n- \"notes\" est le commentaire du présentateur : 1 à 3 phrases, ce qui se dit à l'oral et n'est pas écrit sur la slide."
            . "\n- \"layout\" vaut obligatoirement l'une de ces valeurs : {$layouts}. Conserve celui du plan sauf incohérence manifeste."
            . "\n- Conserve \"image_prompt\" tel quel."
            . "\n- Aucune syntaxe markdown dans les textes."
            . "\n\nSTYLE :\n- " . (self::LENGTH_RULES[$length] ?? self::LENGTH_RULES['moyen'])
            . "\n- " . (self::TONE_RULES[$tone] ?? self::TONE_RULES['professionnel']);
    }

    /**
     * Prompt utilisateur d'un lot : le plan complet est rappelé pour la
     * cohérence d'ensemble, mais seules les slides du lot sont à rédiger.
     */
    public static function slidesUserPrompt(string $deckTitle, array $outline, array $batch): string
    {
        $context = '';
        foreach ($outline as $i => $item) {
            $context .= sprintf("%d. %s\n", $i + 1, (string)($item['title'] ?? ''));
        }

        $todo = [];
        foreach ($batch as $item) {
            $todo[] = [
                'id'           => (string)($item['id'] ?? ''),
                'title'        => (string)($item['title'] ?? ''),
                'points'       => array_values((array)($item['points'] ?? [])),
                'layout'       => (string)($item['layout'] ?? 'titre-contenu'),
                'image_prompt' => (string)($item['image_prompt'] ?? ''),
            ];
        }

        return "PRÉSENTATION : " . trim($deckTitle)
            . "\n\nPLAN COMPLET (pour la cohérence, ne pas rédiger) :\n" . rtrim($context)
            . "\n\nSLIDES À RÉDIGER MAINTENANT :\n"
            . (string)json_encode($todo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nRenvoie le JSON des " . count($todo) . " slides ci-dessus, dans le même ordre.";
    }

    /** Prompt de réparation : unique seconde chance quand le JSON est illisible. */
    public static function repairSystemPrompt(): string
    {
        return "La réponse précédente devait être un objet JSON valide mais ne l'était pas."
            . "\nRenvoie MAINTENANT le même contenu sous forme d'objet JSON strictement valide :"
            . " aucun texte avant ou après, pas de markdown, pas de ```, guillemets doubles, aucune virgule finale."
            . "\nNe change ni le contenu ni la structure — corrige uniquement la syntaxe.";
    }

    // ------------------------------------------------------------- décodage

    /**
     * Décode une réponse LLM censée être du JSON.
     *
     * Tolère les enrobages courants (fence markdown, phrase d'introduction)
     * en isolant le premier objet équilibré. Renvoie null si rien d'exploitable
     * — l'appelant tente alors une réparation, puis abandonne proprement.
     */
    public static function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        // Retrait d'un éventuel bloc de code ```json … ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Repli : isoler le premier objet JSON équilibré du flux.
        $extracted = self::extractFirstObject($text);
        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Isole le premier objet `{...}` équilibré, en ignorant les accolades
     * situées dans des chaînes (un titre contenant « { » ne doit pas fausser
     * le comptage).
     */
    private static function extractFirstObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------- garanties

    /**
     * Force le nombre de slides demandé.
     *
     * Les modèles comptent mal : on ne se fie jamais à leur sortie. En excès on
     * tronque en gardant la conclusion ; en défaut on complète par des slides
     * explicitement à compléter — visibles et éditables dans le plan, plutôt
     * qu'un silence sur une demande non tenue.
     *
     * @param array $outline entrées d'outline normalisées
     */
    public static function enforceSlideCount(array $outline, int $wanted): array
    {
        $wanted  = DeckSpec::slideCount($wanted);
        $outline = array_values($outline);
        $current = count($outline);

        if ($current === $wanted) {
            return self::reindex($outline);
        }

        if ($current > $wanted) {
            // On conserve la dernière slide (la conclusion) en coupant au milieu.
            $last = $outline[$current - 1];
            $kept = array_slice($outline, 0, $wanted - 1);
            $kept[] = $last;

            return self::reindex($kept);
        }

        // Les slides de complément s'insèrent AVANT la dernière : celle-ci est
        // la conclusion, et la reléguer au milieu de la présentation serait pire
        // que le manque de slides qu'on comble.
        $last = $current > 1 ? array_pop($outline) : null;
        for ($i = count($outline); $i < ($last !== null ? $wanted - 1 : $wanted); $i++) {
            $outline[] = [
                'id'           => 's' . ($i + 1),
                'title'        => 'Slide à compléter',
                'points'       => ['Ajoutez ici le contenu de cette slide'],
                'layout'       => 'titre-contenu',
                'image_prompt' => '',
            ];
        }
        if ($last !== null) {
            $outline[] = $last;
        }

        return self::reindex($outline);
    }

    /** Renumérote les identifiants pour qu'ils suivent l'ordre d'affichage. */
    private static function reindex(array $outline): array
    {
        $out = [];
        foreach (array_values($outline) as $i => $item) {
            $item['id'] = 's' . ($i + 1);
            $out[] = $item;
        }

        return $out;
    }

    /** Découpe le plan en lots de rédaction. */
    public static function batches(array $outline, int $size = self::BATCH_SIZE): array
    {
        $size = max(1, $size);

        return array_chunk(array_values($outline), $size, false);
    }

    /**
     * Fusionne les slides rédigées dans le plan, par identifiant.
     *
     * Une slide absente ou mal formée de la réponse conserve son entrée de
     * plan : une rédaction ratée dégrade la slide, elle ne la fait pas
     * disparaître.
     */
    public static function mergeWritten(array $outline, array $written): array
    {
        $byId = [];
        foreach ($written as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $id = (string)($slide['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $slide;
            }
        }

        $merged = [];
        foreach ($outline as $item) {
            $id  = (string)($item['id'] ?? '');
            $new = $byId[$id] ?? null;
            if ($new === null) {
                $merged[] = $item;
                continue;
            }

            $points = is_array($new['points'] ?? null) ? $new['points'] : ($item['points'] ?? []);
            $merged[] = [
                'id'           => $id,
                'title'        => trim((string)($new['title'] ?? '')) !== ''
                    ? (string)$new['title']
                    : (string)($item['title'] ?? ''),
                'points'       => $points,
                'layout'       => (string)($new['layout'] ?? ($item['layout'] ?? 'titre-contenu')),
                // Le prompt d'image reste maître côté plan : le modèle de
                // rédaction n'a pas à inventer d'illustration non prévue.
                'image_prompt' => (string)($item['image_prompt'] ?? ''),
                'notes'        => (string)($new['notes'] ?? ''),
            ];
        }

        return $merged;
    }

    /**
     * Prompt final envoyé au modèle d'image pour une slide.
     * La directive de style vient du catalogue d'outils (source unique).
     */
    public static function imagePrompt(string $prompt, string $styleDirective): string
    {
        $prompt = trim($prompt);
        $suffix = ' Aucun texte, aucun mot, aucun chiffre, aucun logo dans l\'image.';

        return trim($styleDirective) !== ''
            ? $prompt . ' ' . trim($styleDirective) . $suffix
            : $prompt . $suffix;
    }
}
