<?php

declare(strict_types=1);

/**
 * Mise en forme des Google Docs — conversion Markdown → requêtes batchUpdate
 * de l'API Docs v1, et localisation de texte existant pour le styler.
 *
 * Pourquoi une conversion serveur : le LLM écrit spontanément du Markdown
 * (c'est son format naturel, celui du chat) — inséré tel quel par
 * `insertText`, il atterrissait EN TOUTES LETTRES dans le document
 * (« ## Titre », « **gras** »), d'où des Docs illisibles. Lui faire produire
 * directement des requêtes Docs API serait pire : leurs index se comptent en
 * unités UTF-16 et se décalent à chaque insertion — arithmétique ingérable
 * pour un modèle.
 *
 * LE piège de toute cette classe : les index de l'API Docs sont des unités
 * **UTF-16** (un emoji ou tout code point > U+FFFF en vaut DEUX), pas des
 * caractères. Tout offset qui traverse ce fichier passe par `utf16Len()` —
 * un `mb_strlen` décalerait chaque style d'un cran après le premier emoji,
 * et le décalage s'accumulerait sur toute la suite du document.
 *
 * Le SECOND piège, découvert en recette (« des puces partout ») : un
 * paragraphe créé par `insertText` HÉRITE des propriétés de celui où l'on
 * insère, appartenance à une liste comprise — et `updateParagraphStyle` ne
 * défait pas cet héritage. Toute conversion commence donc par un
 * `deleteParagraphBullets` sur l'intégralité de la plage insérée.
 *
 * Périmètre volontairement borné : titres (# à ####), gras, italique,
 * souligné (via l'outil de mise en forme), `code`, listes À PLAT (les tabs
 * d'imbrication sont CONSOMMÉS par createParagraphBullets et décaleraient
 * tous les index suivants), citations (rendues en italique), liens, blocs
 * ``` (police à chasse fixe). Les tableaux Markdown ne sont pas convertis.
 *
 * Classe entièrement pure (aucun réseau) → testable exhaustivement ; les
 * appels API vivent dans UserDriveService.
 */
class DocsFormat
{
    /** Styles de paragraphe nommés de l'API par type de ligne. */
    private const NAMED_STYLES = [
        'h1' => 'HEADING_1',
        'h2' => 'HEADING_2',
        'h3' => 'HEADING_3',
        'h4' => 'HEADING_4',
    ];

    /** Cibles autorisées pour `style_paragraphe` (outil de mise en forme). */
    public const STYLES_PARAGRAPHE = [
        'titre_1' => 'HEADING_1',
        'titre_2' => 'HEADING_2',
        'titre_3' => 'HEADING_3',
        'normal'  => 'NORMAL_TEXT',
    ];

    /** Couleurs nommées FR acceptées par l'outil (en plus de #RRGGBB). */
    public const COULEURS = [
        'noir'   => '000000', 'gris'   => '6B7280', 'blanc'  => 'FFFFFF',
        'rouge'  => 'C0392B', 'orange' => 'D35400', 'jaune'  => 'B7950B',
        'vert'   => '1E8449', 'bleu'   => '1A5276', 'violet' => '6C3483',
        'rose'   => 'C2185B',
    ];

    /**
     * Motifs inline, essayés à chaque position — le PLUS TÔT dans la ligne
     * gagne (ordre du tableau = priorité à position égale : `**` avant `*`).
     * Les gardes interdisent l'italique au milieu d'un mot : `snake_case` ou
     * `2*3*4` ne sont pas des emphases.
     */
    private const INLINE = [
        'code'  => '/`([^`\n]+)`/u',
        'lien'  => '/\[([^\]\n]+)\]\(([^)\s]+)\)/u',
        // (?!\*) / (?!_) sur la fermeture : dans « **gras *italique*** », le
        // non-gourmand fermerait sur les deux PREMIÈRES étoiles du *** final
        // et laisserait la troisième en littéral — la fermeture doit être les
        // deux dernières, pour que l'italique interne reste entier.
        'gras'  => '/\*\*(?!\s)(.+?)(?<!\s)\*\*(?!\*)/su',
        'gras2' => '/__(?!\s)(.+?)(?<!\s)__(?!_)/su',
        'ital'  => '/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u',
        'ital2' => '/(?<!\w)_(?!\s)([^_\n]+?)(?<!\s)_(?!\w)/u',
    ];

    // =====================================================================
    // UTF-16
    // =====================================================================

    /** Longueur d'une chaîne UTF-8 en unités UTF-16 (l'unité des index Docs). */
    public static function utf16Len(string $texte): int
    {
        if ($texte === '') {
            return 0;
        }
        $conv = mb_convert_encoding($texte, 'UTF-16BE', 'UTF-8');
        return intdiv(strlen($conv), 2);
    }

    // =====================================================================
    // Markdown → texte inséré + requêtes de style
    // =====================================================================

    /**
     * Convertit un Markdown en texte à insérer (marqueurs retirés) + requêtes
     * de style aux index ABSOLUS, `$base` étant l'index d'insertion du texte.
     *
     * Les styles de paragraphe sont émis pour CHAQUE paragraphe, y compris
     * NORMAL_TEXT : une insertion hérite du style au point d'insertion — un
     * ajout à la suite d'un titre deviendrait sinon un titre géant, et le
     * style se propagerait à tous les paragraphes créés par les \n insérés.
     *
     * @return array{texte:string,requetes:array<int,array<string,mixed>>}
     */
    public static function convertir(string $markdown, int $base): array
    {
        $parags = self::parseBlocs($markdown);

        // Lignes vides d'extrémité retirées : elles créeraient des paragraphes
        // vides en tête/queue du document.
        while ($parags !== [] && $parags[0]['type'] === 'normal' && trim($parags[0]['texte']) === '') {
            array_shift($parags);
        }
        while ($parags !== [] && end($parags)['type'] === 'normal' && trim((string)end($parags)['texte']) === '') {
            array_pop($parags);
        }
        $parags = array_values($parags);
        if ($parags === []) {
            return ['texte' => '', 'requetes' => []];
        }

        // Assemblage : positions de chaque paragraphe en unités UTF-16.
        $texte = '';
        $u16 = 0;
        $positions = [];
        foreach ($parags as $i => $p) {
            if ($i > 0) {
                $texte .= "\n";
                $u16++;
            }
            $positions[$i] = $u16;
            $texte .= $p['texte'];
            $u16 += self::utf16Len($p['texte']);
        }

        $requetes = [];
        $dernier  = count($parags) - 1;

        // 0. Puces HÉRITÉES du point d'insertion — la cause des « puces
        //    partout ». `insertText` ne crée pas des paragraphes neutres : les
        //    paragraphes nés des \n insérés HÉRITENT des propriétés de celui
        //    où l'on insère. Insérer à la fin d'un document qui se termine par
        //    une liste (ou réécrire un document dont le dernier paragraphe en
        //    était une, `remplacer_tout` ne pouvant pas supprimer ce dernier
        //    paragraphe) mettait donc TOUT le texte à puces — titres compris.
        //    `updateParagraphStyle` n'y peut rien : le style nommé et
        //    l'appartenance à une liste sont deux propriétés distinctes, seul
        //    `deleteParagraphBullets` retire la seconde. Il ne consomme aucun
        //    caractère → tous les index calculés plus bas restent valides, et
        //    il est émis EN PREMIER pour que le createParagraphBullets des
        //    vraies listes (étape 2) reste le dernier mot.
        if ($u16 > 0) {
            $requetes[] = ['deleteParagraphBullets' => [
                'range' => ['startIndex' => $base, 'endIndex' => $base + $u16],
            ]];
        }

        // 1. Styles de paragraphe (titres + NORMAL_TEXT explicite partout).
        foreach ($parags as $i => $p) {
            $len = self::utf16Len($p['texte']);
            if ($len === 0 && $i === $dernier) {
                continue; // rien à styler, et une plage vide serait refusée
            }
            $liste = $p['type'] === 'puce' || $p['type'] === 'numero';
            $style  = ['namedStyleType' => self::NAMED_STYLES[$p['type']] ?? 'NORMAL_TEXT'];
            $fields = 'namedStyleType';
            if (!$liste) {
                // `deleteParagraphBullets` « préserve visuellement » le niveau
                // d'imbrication en posant une indentation à la place de la
                // puce retirée : sans cette remise à zéro, un texte ajouté à la
                // suite d'une liste resterait décalé vers la droite. Les
                // paragraphes de liste, eux, sont indentés par le
                // createParagraphBullets qui suit — y toucher ici serait au
                // mieux inutile, au pire concurrent.
                $style['indentStart']     = ['magnitude' => 0, 'unit' => 'PT'];
                $style['indentFirstLine'] = ['magnitude' => 0, 'unit' => 'PT'];
                $fields .= ',indentStart,indentFirstLine';
            }
            // Paragraphe vide non final : sa plage est son \n.
            $fin = $positions[$i] + max(1, $len);
            $requetes[] = ['updateParagraphStyle' => [
                'range'          => ['startIndex' => $base + $positions[$i], 'endIndex' => $base + $fin],
                'paragraphStyle' => $style,
                'fields'         => $fields,
            ]];
        }

        // 2. Puces : un createParagraphBullets par bloc CONTIGU du même type.
        //    Aucune tabulation insérée (listes à plat) → l'API ne consomme
        //    aucun caractère, les index suivants restent valides.
        $i = 0;
        $n = count($parags);
        while ($i < $n) {
            $t = $parags[$i]['type'];
            if ($t !== 'puce' && $t !== 'numero') {
                $i++;
                continue;
            }
            $j = $i;
            while ($j + 1 < $n && $parags[$j + 1]['type'] === $t) {
                $j++;
            }
            $finBloc = $positions[$j] + max(1, self::utf16Len($parags[$j]['texte']));
            $requetes[] = ['createParagraphBullets' => [
                'range'        => ['startIndex' => $base + $positions[$i], 'endIndex' => $base + $finBloc],
                'bulletPreset' => $t === 'puce' ? 'BULLET_DISC_CIRCLE_SQUARE' : 'NUMBERED_DECIMAL_ALPHA_ROMAN',
            ]];
            $i = $j + 1;
        }

        // 3. Styles de texte (gras, italique, code, liens ; blocs ``` et
        //    citations stylés ligne entière).
        foreach ($parags as $i => $p) {
            $spans = $p['spans'];
            $len   = self::utf16Len($p['texte']);
            if ($p['type'] === 'mono' && $len > 0) {
                $spans[] = [0, $len, ['mono' => true]];
            }
            if ($p['type'] === 'citation' && $len > 0) {
                $spans[] = [0, $len, ['italique' => true]];
            }
            foreach ($spans as [$debut, $fin, $style]) {
                if ($fin <= $debut) {
                    continue;
                }
                [$textStyle, $fields] = self::texteStyle($style);
                if ($fields === '') {
                    continue;
                }
                $requetes[] = ['updateTextStyle' => [
                    'range'     => ['startIndex' => $base + $positions[$i] + $debut, 'endIndex' => $base + $positions[$i] + $fin],
                    'textStyle' => $textStyle,
                    'fields'    => $fields,
                ]];
            }
        }

        return ['texte' => $texte, 'requetes' => $requetes];
    }

    /** Découpe le Markdown en paragraphes typés, marqueurs retirés. */
    private static function parseBlocs(string $markdown): array
    {
        $parags = [];
        $fence  = false;
        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $ligne) {
            if (preg_match('/^\s*```/', $ligne) === 1) {
                $fence = !$fence; // la ligne de fence (et sa langue) disparaît
                continue;
            }
            if ($fence) {
                // Contenu de bloc de code : AUCUNE interprétation inline.
                $parags[] = ['type' => 'mono', 'texte' => $ligne, 'spans' => []];
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/', $ligne, $m) === 1) {
                $p = self::parseInline(trim($m[2]));
                $p['type'] = 'h' . strlen($m[1]);
                $parags[] = $p;
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $ligne, $m) === 1) {
                $p = self::parseInline($m[1]);
                $p['type'] = 'puce';
                $parags[] = $p;
                continue;
            }
            if (preg_match('/^\s*\d{1,3}[.)]\s+(.+)$/', $ligne, $m) === 1) {
                $p = self::parseInline($m[1]);
                $p['type'] = 'numero';
                $parags[] = $p;
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $ligne, $m) === 1) {
                $p = self::parseInline($m[1]);
                $p['type'] = 'citation';
                $parags[] = $p;
                continue;
            }
            $p = self::parseInline($ligne);
            $p['type'] = 'normal';
            $parags[] = $p;
        }
        return $parags;
    }

    /**
     * Marqueurs inline d'une ligne → texte nettoyé + spans de style en
     * offsets UTF-16 RELATIFS à la ligne. Récursif : `**gras *italique***`
     * produit deux spans qui se chevauchent (gras sur tout, italique sur la
     * partie interne) — l'API les cumule.
     *
     * @return array{texte:string,spans:array<int,array{0:int,1:int,2:array<string,mixed>}>}
     */
    public static function parseInline(string $ligne): array
    {
        $texte = '';
        $u16   = 0;
        $spans = [];
        self::inline($ligne, $texte, $u16, $spans);
        return ['texte' => $texte, 'spans' => $spans];
    }

    private static function inline(string $frag, string &$texte, int &$u16, array &$spans): void
    {
        while ($frag !== '') {
            $tot = null;
            foreach (self::INLINE as $type => $regex) {
                if (preg_match($regex, $frag, $m, PREG_OFFSET_CAPTURE) === 1
                    && ($tot === null || $m[0][1] < $tot['pos'])) {
                    $tot = ['type' => $type, 'm' => $m, 'pos' => $m[0][1]];
                }
            }
            if ($tot === null) {
                $texte .= $frag;
                $u16   += self::utf16Len($frag);
                return;
            }
            $avant  = substr($frag, 0, $tot['pos']);
            $texte .= $avant;
            $u16   += self::utf16Len($avant);

            $m     = $tot['m'];
            $debut = $u16;
            switch ($tot['type']) {
                case 'code':
                    $texte .= $m[1][0];
                    $u16   += self::utf16Len($m[1][0]);
                    $spans[] = [$debut, $u16, ['mono' => true]];
                    break;
                case 'lien':
                    $texte .= $m[1][0];
                    $u16   += self::utf16Len($m[1][0]);
                    $spans[] = [$debut, $u16, ['lien' => $m[2][0]]];
                    break;
                case 'gras':
                case 'gras2':
                    self::inline($m[1][0], $texte, $u16, $spans);
                    $spans[] = [$debut, $u16, ['gras' => true]];
                    break;
                default: // ital / ital2
                    self::inline($m[1][0], $texte, $u16, $spans);
                    $spans[] = [$debut, $u16, ['italique' => true]];
            }
            $frag = substr($frag, $tot['pos'] + strlen($m[0][0]));
        }
    }

    /**
     * Style abstrait → couple [textStyle API, fields]. Partagé par la
     * conversion Markdown et l'outil de mise en forme de texte existant.
     *
     * @param array<string,mixed> $style gras|italique|souligne|mono|lien|couleur (rgb)|taille (pt)
     * @return array{0:array<string,mixed>,1:string}
     */
    public static function texteStyle(array $style): array
    {
        $ts = [];
        $fields = [];
        if (($style['gras'] ?? null) !== null) {
            $ts['bold'] = (bool)$style['gras'];
            $fields[] = 'bold';
        }
        if (($style['italique'] ?? null) !== null) {
            $ts['italic'] = (bool)$style['italique'];
            $fields[] = 'italic';
        }
        if (($style['souligne'] ?? null) !== null) {
            $ts['underline'] = (bool)$style['souligne'];
            $fields[] = 'underline';
        }
        if (!empty($style['mono'])) {
            $ts['weightedFontFamily'] = ['fontFamily' => 'Courier New'];
            $fields[] = 'weightedFontFamily';
        }
        if (isset($style['lien']) && is_string($style['lien']) && $style['lien'] !== '') {
            $ts['link'] = ['url' => $style['lien']];
            $fields[] = 'link';
        }
        if (isset($style['couleur']) && is_array($style['couleur'])) {
            $ts['foregroundColor'] = ['color' => ['rgbColor' => $style['couleur']]];
            $fields[] = 'foregroundColor';
        }
        if (isset($style['taille']) && is_int($style['taille'])) {
            $ts['fontSize'] = ['magnitude' => $style['taille'], 'unit' => 'PT'];
            $fields[] = 'fontSize';
        }
        return [$ts, implode(',', $fields)];
    }

    // =====================================================================
    // Outil « mettre en forme » : arguments LLM + localisation de texte
    // =====================================================================

    /**
     * Arguments du tool call → style normalisé. Lève si AUCUN style n'est
     * demandé (l'appel ne ferait rien) ou si une valeur est invalide.
     * Booléens en FILTER_VALIDATE_BOOLEAN : les modèles envoient "true"/"faux".
     *
     * @return array{gras:?bool,italique:?bool,souligne:?bool,couleur:?array,taille:?int,style_paragraphe:?string}
     */
    public static function styleDepuisArgs(array $args): array
    {
        $bool = static function (mixed $v): ?bool {
            if ($v === null || $v === '') {
                return null;
            }
            return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        };
        $style = [
            'gras'             => $bool($args['gras'] ?? null),
            'italique'         => $bool($args['italique'] ?? null),
            'souligne'         => $bool($args['souligne'] ?? null),
            'couleur'          => null,
            'taille'           => null,
            'style_paragraphe' => null,
        ];
        $couleur = trim((string)($args['couleur'] ?? ''));
        if ($couleur !== '') {
            $style['couleur'] = self::parseCouleur($couleur);
        }
        $taille = $args['taille'] ?? null;
        if ($taille !== null && $taille !== '') {
            $t = (int)$taille;
            if ($t < 6 || $t > 96) {
                throw new \RuntimeException('Taille de police invalide : ' . $t . ' (6 à 96 points).');
            }
            $style['taille'] = $t;
        }
        $sp = trim((string)($args['style_paragraphe'] ?? ''));
        if ($sp !== '') {
            if (!isset(self::STYLES_PARAGRAPHE[$sp])) {
                throw new \RuntimeException('Style de paragraphe inconnu : ' . $sp
                    . ' (attendus : ' . implode(', ', array_keys(self::STYLES_PARAGRAPHE)) . ').');
            }
            $style['style_paragraphe'] = $sp;
        }
        if ($style['gras'] === null && $style['italique'] === null && $style['souligne'] === null
            && $style['couleur'] === null && $style['taille'] === null && $style['style_paragraphe'] === null) {
            throw new \RuntimeException('Aucune mise en forme demandée : précise gras, italique, souligne, couleur, taille ou style_paragraphe.');
        }
        return $style;
    }

    /**
     * Couleur nommée FR ou #RRGGBB → rgbColor API (fractions 0–1).
     * @return array{red:float,green:float,blue:float}
     */
    public static function parseCouleur(string $entree): array
    {
        $cle = mb_strtolower(trim($entree));
        $hex = self::COULEURS[$cle] ?? null;
        if ($hex === null && preg_match('/^#?([0-9a-fA-F]{6})$/', trim($entree), $m) === 1) {
            $hex = $m[1];
        }
        if ($hex === null) {
            throw new \RuntimeException('Couleur non reconnue : « ' . $entree . ' » (nom accepté : '
                . implode(', ', array_keys(self::COULEURS)) . ' — ou code #RRGGBB).');
        }
        return [
            'red'   => round(hexdec(substr($hex, 0, 2)) / 255, 4),
            'green' => round(hexdec(substr($hex, 2, 2)) / 255, 4),
            'blue'  => round(hexdec(substr($hex, 4, 2)) / 255, 4),
        ];
    }

    /** Résumé humain du style pour la carte d'approbation et le message final. */
    public static function resumeStyles(array $style): string
    {
        $parts = [];
        foreach ([['gras', 'gras'], ['italique', 'italique'], ['souligne', 'souligné']] as [$cle, $mot]) {
            if (($style[$cle] ?? null) === true) {
                $parts[] = $mot;
            } elseif (($style[$cle] ?? null) === false) {
                $parts[] = 'retrait du ' . $mot;
            }
        }
        if (($style['couleur'] ?? null) !== null) {
            $parts[] = 'couleur';
        }
        if (($style['taille'] ?? null) !== null) {
            $parts[] = 'taille ' . $style['taille'] . ' pt';
        }
        if (($style['style_paragraphe'] ?? null) !== null) {
            $parts[] = str_replace('_', ' ', $style['style_paragraphe']);
        }
        return implode(', ', $parts);
    }

    /**
     * Localise un texte dans le corps d'un document (réponse de l'API,
     * `body.content`) : plages UTF-16 absolues des occurrences + plage du
     * paragraphe contenant chacune. Recherche insensible à la casse, mais PAS
     * aux accents (le texte stylé doit être exactement celui visé).
     *
     * Chaque code point est cartographié individuellement vers son index
     * absolu : un run scindé par une mise en forme existante ou un emoji ne
     * décale rien.
     *
     * @return array{occurrences:array<int,array{0:int,1:int}>,paragraphes:array<int,array{0:int,1:int}>}
     */
    public static function localiserOccurrences(array $contenu, string $recherche): array
    {
        $aiguille = mb_str_split(mb_strtolower($recherche));
        $lAig = count($aiguille);
        if ($lAig === 0) {
            return ['occurrences' => [], 'paragraphes' => []];
        }

        $occurrences = [];
        $paragraphes = [];
        foreach ($contenu as $element) {
            if (!is_array($element) || !isset($element['paragraph'])) {
                continue;
            }
            // Carte code point → [index absolu, largeur UTF-16, char minuscule]
            $carte = [];
            foreach ((array)($element['paragraph']['elements'] ?? []) as $run) {
                $contenuRun = (string)($run['textRun']['content'] ?? '');
                if ($contenuRun === '') {
                    continue;
                }
                $abs = (int)($run['startIndex'] ?? 0);
                foreach (mb_str_split($contenuRun) as $char) {
                    $largeur = self::utf16Len($char);
                    $carte[] = [$abs, $largeur, mb_strtolower($char)];
                    $abs += $largeur;
                }
            }
            $nb = count($carte);
            for ($i = 0; $i + $lAig <= $nb; $i++) {
                $ok = true;
                for ($j = 0; $j < $lAig; $j++) {
                    if ($carte[$i + $j][2] !== $aiguille[$j]) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                $fin = $carte[$i + $lAig - 1];
                $occurrences[] = [$carte[$i][0], $fin[0] + $fin[1]];
                $paragraphes[] = [(int)($element['startIndex'] ?? 0), (int)($element['endIndex'] ?? 0)];
                $i += $lAig - 1; // pas de chevauchement
            }
        }
        return ['occurrences' => $occurrences, 'paragraphes' => $paragraphes];
    }
}
