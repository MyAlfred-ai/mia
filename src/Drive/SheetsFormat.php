<?php

declare(strict_types=1);

/**
 * Mise en forme des Google Sheets — traduction d'une plage A1 en GridRange et
 * construction des requêtes `spreadsheets.batchUpdate` (Sheets API v4).
 *
 * Pendant Sheets de DocsFormat, et même motif : les valeurs écrites par
 * `values.update` arrivent brutes (aucun en-tête distinct, colonnes trop
 * étroites, nombres non formatés), et demander au modèle de produire des
 * requêtes batchUpdate serait ingérable — elles n'emploient pas la notation A1
 * mais des GridRange indexés.
 *
 * DEUX pièges structurent tout ce fichier :
 *
 *  1. **GridRange est 0-based et sa borne de fin est EXCLUSIVE**, alors que la
 *     notation A1 est 1-based et inclusive. « A1:C10 » devient donc
 *     rows [0,10) × cols [0,3). Un décalage d'un cran ici stylerait la
 *     mauvaise ligne, silencieusement.
 *  2. **`repeatCell` remplace ce que ses `fields` désignent.** Envoyer
 *     `fields: 'userEnteredFormat'` effacerait bordures, alignement et format
 *     de nombre existants pour ne garder que ce qu'on envoie. Les `fields`
 *     sont donc calculés au sous-champ près (`userEnteredFormat.textFormat.bold`),
 *     et c'est ce que verrouillent les tests.
 *
 * Périmètre : gras, italique, taille, couleur du texte, couleur de fond,
 * alignement, format de nombre, plus les requêtes d'en-tête (gras + fond +
 * ligne gelée + colonnes ajustées). Les bordures, fusions et mises en forme
 * conditionnelles sont hors périmètre.
 *
 * Classe entièrement pure (aucun réseau) : les appels vivent dans
 * UserDriveService.
 */
class SheetsFormat
{
    /** Formats de nombre exposés au modèle → motif Sheets. */
    public const FORMATS_NOMBRE = [
        'nombre'      => ['type' => 'NUMBER',     'pattern' => '#,##0.##'],
        'entier'      => ['type' => 'NUMBER',     'pattern' => '#,##0'],
        'monnaie'     => ['type' => 'CURRENCY',   'pattern' => '#,##0.00 €'],
        'pourcentage' => ['type' => 'PERCENT',    'pattern' => '0.0%'],
        'date'        => ['type' => 'DATE',       'pattern' => 'dd/mm/yyyy'],
        'date_heure'  => ['type' => 'DATE_TIME',  'pattern' => 'dd/mm/yyyy hh:mm'],
        'texte'       => ['type' => 'TEXT',       'pattern' => '@'],
    ];

    /** Alignements horizontaux acceptés. */
    public const ALIGNEMENTS = [
        'gauche' => 'LEFT',
        'centre' => 'CENTER',
        'droite' => 'RIGHT',
    ];

    /** Fond gris clair de la ligne d'en-tête posée à la création. */
    private const HEADER_BG = ['red' => 0.9294, 'green' => 0.9294, 'blue' => 0.9294];

    // =====================================================================
    // Notation A1 → GridRange
    // =====================================================================

    /**
     * Découpe une plage A1 en nom d'onglet (ou null) et bornes.
     *
     * Les bornes sont rendues telles que l'API les attend — 0-based, fin
     * EXCLUSIVE — et une dimension absente reste `null` : « A:C » ne borne pas
     * les lignes, « 2:5 » ne borne pas les colonnes, et poser un 0 à la place
     * de `null` restreindrait la plage au lieu de la laisser ouverte.
     *
     * Formes acceptées : `Feuille1!B2:D5`, `'Mon onglet'!A1`, `B2:D5`, `A1`,
     * `A:C`, `2:5`. Les bornes inversées sont remises dans l'ordre (« C3:A1 »).
     *
     * @return array{onglet:?string,startRowIndex:?int,endRowIndex:?int,startColumnIndex:?int,endColumnIndex:?int}
     */
    public static function parseA1(string $plage): array
    {
        $plage = trim($plage);
        if ($plage === '') {
            throw new \RuntimeException('Plage vide (ex. Feuille1!A1:C10, B2, A:C).');
        }

        $onglet = null;
        if (($pos = strrpos($plage, '!')) !== false) {
            $onglet = substr($plage, 0, $pos);
            $plage  = substr($plage, $pos + 1);
            // Onglet quoté (nom contenant une espace) : les apostrophes
            // internes y sont doublées.
            if (strlen($onglet) >= 2 && $onglet[0] === "'" && substr($onglet, -1) === "'") {
                $onglet = str_replace("''", "'", substr($onglet, 1, -1));
            }
            $onglet = trim($onglet);
            if ($onglet === '') {
                throw new \RuntimeException('Nom d\'onglet vide dans la plage.');
            }
        }
        $plage = strtoupper(trim($plage));
        if ($plage === '') {
            throw new \RuntimeException('Plage sans référence de cellule (ex. Feuille1!A1:C10).');
        }

        $bornes = explode(':', $plage);
        if (count($bornes) > 2) {
            throw new \RuntimeException('Plage invalide : ' . $plage);
        }
        $debut = self::parseCellule($bornes[0]);
        $fin   = count($bornes) === 2 ? self::parseCellule($bornes[1]) : $debut;

        // Une borne mixte (« A1:C » ou « A:C10 ») est refusée : l'interpréter
        // demanderait de deviner, et un mauvais choix stylerait des cellules
        // que l'utilisateur n'a pas visées.
        if (($debut['col'] === null) !== ($fin['col'] === null)
            || ($debut['ligne'] === null) !== ($fin['ligne'] === null)) {
            throw new \RuntimeException('Plage incohérente : ' . $plage
                . ' (utilise A1:C10 pour un bloc, A:C pour des colonnes entières, 2:5 pour des lignes entières).');
        }
        if ($debut['col'] === null && $debut['ligne'] === null) {
            throw new \RuntimeException('Plage invalide : ' . $plage);
        }

        $out = ['onglet' => $onglet, 'startRowIndex' => null, 'endRowIndex' => null,
                'startColumnIndex' => null, 'endColumnIndex' => null];
        if ($debut['ligne'] !== null) {
            $l1 = min($debut['ligne'], (int)$fin['ligne']);
            $l2 = max($debut['ligne'], (int)$fin['ligne']);
            $out['startRowIndex'] = $l1 - 1;   // 1-based → 0-based
            $out['endRowIndex']   = $l2;       // inclusif → exclusif
        }
        if ($debut['col'] !== null) {
            $c1 = min($debut['col'], (int)$fin['col']);
            $c2 = max($debut['col'], (int)$fin['col']);
            $out['startColumnIndex'] = $c1 - 1;
            $out['endColumnIndex']   = $c2;
        }
        return $out;
    }

    /** « B2 » → col 2, ligne 2 ; « B » → col seule ; « 2 » → ligne seule. */
    private static function parseCellule(string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('/^(\$?)([A-Z]{1,3})(\$?)(\d{1,7})$/', $ref, $m) === 1) {
            return ['col' => self::colToIndex($m[2]), 'ligne' => (int)$m[4]];
        }
        if (preg_match('/^\$?([A-Z]{1,3})$/', $ref, $m) === 1) {
            return ['col' => self::colToIndex($m[1]), 'ligne' => null];
        }
        if (preg_match('/^\$?(\d{1,7})$/', $ref, $m) === 1) {
            return ['col' => null, 'ligne' => (int)$m[1]];
        }
        throw new \RuntimeException('Référence de cellule invalide : ' . $ref);
    }

    /**
     * Lettres de colonne → numéro 1-based. Base 26 SANS zéro : après Z vient
     * AA (27), pas A0 — d'où l'accumulation `× 26 + rang` et non un poids
     * positionnel classique.
     */
    public static function colToIndex(string $lettres): int
    {
        $n = 0;
        foreach (str_split(strtoupper($lettres)) as $lettre) {
            $n = $n * 26 + (ord($lettre) - 64);
        }
        if ($n < 1) {
            throw new \RuntimeException('Colonne invalide : ' . $lettres);
        }
        return $n;
    }

    /** Numéro 1-based → lettres de colonne (A, Z, AA, AB…). */
    public static function indexToCol(int $index): string
    {
        $out = '';
        while ($index > 0) {
            $reste = ($index - 1) % 26;
            $out   = chr(65 + $reste) . $out;
            $index = intdiv($index - 1 - $reste, 26);
        }
        return $out === '' ? 'A' : $out;
    }

    /** Plage bornée rendue lisible pour la carte d'approbation. */
    public static function decrirePlage(array $bornes): string
    {
        $col = static fn(?int $i): string => $i === null ? '' : self::indexToCol($i + 1);
        if ($bornes['startRowIndex'] === null) {
            return 'colonnes ' . $col($bornes['startColumnIndex']) . ' à ' . self::indexToCol((int)$bornes['endColumnIndex']);
        }
        if ($bornes['startColumnIndex'] === null) {
            return 'lignes ' . ((int)$bornes['startRowIndex'] + 1) . ' à ' . (int)$bornes['endRowIndex'];
        }
        $depart = $col($bornes['startColumnIndex']) . ((int)$bornes['startRowIndex'] + 1);
        $arrivee = self::indexToCol((int)$bornes['endColumnIndex']) . (int)$bornes['endRowIndex'];
        return $depart === $arrivee ? 'cellule ' . $depart : 'plage ' . $depart . ':' . $arrivee;
    }

    /** Nombre de cellules d'une plage bornée, null si une dimension est ouverte. */
    public static function compterCellules(array $bornes): ?int
    {
        if ($bornes['startRowIndex'] === null || $bornes['startColumnIndex'] === null) {
            return null;
        }
        return ((int)$bornes['endRowIndex'] - (int)$bornes['startRowIndex'])
            * ((int)$bornes['endColumnIndex'] - (int)$bornes['startColumnIndex']);
    }

    /** Bornes + sheetId → GridRange de l'API (clés nulles retirées). */
    public static function gridRange(array $bornes, int $sheetId): array
    {
        $range = ['sheetId' => $sheetId];
        foreach (['startRowIndex', 'endRowIndex', 'startColumnIndex', 'endColumnIndex'] as $cle) {
            if (($bornes[$cle] ?? null) !== null) {
                $range[$cle] = (int)$bornes[$cle];
            }
        }
        return $range;
    }

    // =====================================================================
    // Style demandé → requête repeatCell
    // =====================================================================

    /**
     * Arguments du tool call → style normalisé. Lève si aucun style n'est
     * demandé (l'appel ne ferait rien) ou si une valeur est invalide.
     * Les couleurs passent par `DocsFormat::parseCouleur` : les deux API
     * emploient le MÊME objet (fractions rgb 0–1) et la palette FR doit rester
     * unique — deux listes divergentes finiraient par ne pas accepter les mêmes
     * noms dans un Doc et dans un Sheet.
     *
     * @return array{gras:?bool,italique:?bool,taille:?int,couleur:?array,fond:?array,alignement:?string,format:?string}
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
            'gras' => $bool($args['gras'] ?? null), 'italique' => $bool($args['italique'] ?? null),
            'taille' => null, 'couleur' => null, 'fond' => null, 'alignement' => null, 'format' => null,
        ];

        $taille = $args['taille'] ?? null;
        if ($taille !== null && $taille !== '') {
            $t = (int)$taille;
            if ($t < 6 || $t > 96) {
                throw new \RuntimeException('Taille de police invalide : ' . $t . ' (6 à 96 points).');
            }
            $style['taille'] = $t;
        }
        foreach ([['couleur_texte', 'couleur'], ['couleur_fond', 'fond']] as [$arg, $cle]) {
            $val = trim((string)($args[$arg] ?? ''));
            if ($val !== '') {
                $style[$cle] = DocsFormat::parseCouleur($val);
            }
        }
        $align = mb_strtolower(trim((string)($args['alignement'] ?? '')));
        if ($align !== '') {
            if (!isset(self::ALIGNEMENTS[$align])) {
                throw new \RuntimeException('Alignement inconnu : ' . $align
                    . ' (attendus : ' . implode(', ', array_keys(self::ALIGNEMENTS)) . ').');
            }
            $style['alignement'] = $align;
        }
        $format = mb_strtolower(trim((string)($args['format'] ?? '')));
        if ($format !== '') {
            if (!isset(self::FORMATS_NOMBRE[$format])) {
                throw new \RuntimeException('Format inconnu : ' . $format
                    . ' (attendus : ' . implode(', ', array_keys(self::FORMATS_NOMBRE)) . ').');
            }
            $style['format'] = $format;
        }

        if (array_filter($style, static fn($v) => $v !== null) === []) {
            throw new \RuntimeException('Aucune mise en forme demandée : précise gras, italique, taille, '
                . 'couleur_texte, couleur_fond, alignement ou format.');
        }
        return $style;
    }

    /**
     * Style + demande de gel, en UN point : geler une ligne d'en-tête SANS
     * autre mise en forme est un appel légitime, alors que `styleDepuisArgs`
     * refuse un style vide. Deux appelants (le handler, qui prépare la carte,
     * et l'exécution après approbation) doivent trancher pareil — d'où cette
     * source unique plutôt que la même condition recopiée.
     *
     * @return array{style:array<string,mixed>,geler:bool}
     */
    public static function demandeDepuisArgs(array $args): array
    {
        $geler = filter_var($args['geler'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            return ['style' => self::styleDepuisArgs($args), 'geler' => $geler];
        } catch (\RuntimeException $e) {
            if ($geler) {
                return ['style' => [], 'geler' => true];
            }
            throw $e;
        }
    }

    /**
     * Style + GridRange → requête `repeatCell`, ou null si le style est vide.
     *
     * `fields` liste les sous-champs AU PLUS PRÉCIS : `repeatCell` remplace ce
     * qu'ils désignent, donc un `userEnteredFormat` global effacerait bordures,
     * alignement et format de nombre déjà en place.
     *
     * @return array<string,mixed>|null
     */
    public static function cellFormatRequest(array $style, array $gridRange): ?array
    {
        $format = [];
        $fields = [];
        $texte  = [];

        if (($style['gras'] ?? null) !== null) {
            $texte['bold'] = (bool)$style['gras'];
            $fields[] = 'userEnteredFormat.textFormat.bold';
        }
        if (($style['italique'] ?? null) !== null) {
            $texte['italic'] = (bool)$style['italique'];
            $fields[] = 'userEnteredFormat.textFormat.italic';
        }
        if (($style['taille'] ?? null) !== null) {
            $texte['fontSize'] = (int)$style['taille'];
            $fields[] = 'userEnteredFormat.textFormat.fontSize';
        }
        if (($style['couleur'] ?? null) !== null) {
            $texte['foregroundColor'] = $style['couleur'];
            $fields[] = 'userEnteredFormat.textFormat.foregroundColor';
        }
        if ($texte !== []) {
            $format['textFormat'] = $texte;
        }
        if (($style['fond'] ?? null) !== null) {
            $format['backgroundColor'] = $style['fond'];
            $fields[] = 'userEnteredFormat.backgroundColor';
        }
        if (($style['alignement'] ?? null) !== null) {
            $format['horizontalAlignment'] = self::ALIGNEMENTS[$style['alignement']];
            $fields[] = 'userEnteredFormat.horizontalAlignment';
        }
        if (($style['format'] ?? null) !== null) {
            $format['numberFormat'] = self::FORMATS_NOMBRE[$style['format']];
            $fields[] = 'userEnteredFormat.numberFormat';
        }
        if ($fields === []) {
            return null;
        }
        return ['repeatCell' => [
            'range' => $gridRange,
            'cell'  => ['userEnteredFormat' => $format],
            'fields' => implode(',', $fields),
        ]];
    }

    /** Résumé humain du style (carte d'approbation et message final). */
    public static function resumeStyles(array $style): string
    {
        $parts = [];
        foreach ([['gras', 'gras'], ['italique', 'italique']] as [$cle, $mot]) {
            if (($style[$cle] ?? null) === true) {
                $parts[] = $mot;
            } elseif (($style[$cle] ?? null) === false) {
                $parts[] = 'retrait du ' . $mot;
            }
        }
        if (($style['taille'] ?? null) !== null)     { $parts[] = 'taille ' . $style['taille'] . ' pt'; }
        if (($style['couleur'] ?? null) !== null)    { $parts[] = 'couleur du texte'; }
        if (($style['fond'] ?? null) !== null)       { $parts[] = 'couleur de fond'; }
        if (($style['alignement'] ?? null) !== null) { $parts[] = 'alignement ' . $style['alignement']; }
        if (($style['format'] ?? null) !== null)     { $parts[] = 'format ' . $style['format']; }
        return implode(', ', $parts);
    }

    // =====================================================================
    // En-tête posé à la création
    // =====================================================================

    /**
     * Requêtes de présentation d'un tableau fraîchement écrit : première ligne
     * en gras sur fond gris, ligne gelée, colonnes ajustées au contenu.
     *
     * C'est le pendant du « Markdown rendu » des Docs : sans ça, MIA produisait
     * des feuilles où l'en-tête ne se distinguait pas des données et où les
     * colonnes tronquaient tout.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function headerRequests(int $sheetId, int $nbColonnes): array
    {
        $nbColonnes = max(1, $nbColonnes);
        return [
            [
                'repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1,
                                'startColumnIndex' => 0, 'endColumnIndex' => $nbColonnes],
                    'cell'  => ['userEnteredFormat' => [
                        'textFormat'      => ['bold' => true],
                        'backgroundColor' => self::HEADER_BG,
                    ]],
                    // Au sous-champ près : un `userEnteredFormat` global
                    // effacerait le format de nombre que l'utilisateur
                    // pourrait déjà avoir posé sur la feuille.
                    'fields' => 'userEnteredFormat.textFormat.bold,userEnteredFormat.backgroundColor',
                ],
            ],
            [
                'updateSheetProperties' => [
                    'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 1]],
                    'fields'     => 'gridProperties.frozenRowCount',
                ],
            ],
            [
                'autoResizeDimensions' => [
                    'dimensions' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS',
                                     'startIndex' => 0, 'endIndex' => $nbColonnes],
                ],
            ],
        ];
    }

    /** Largeur (en colonnes) d'un tableau de valeurs normalisé. */
    public static function largeur(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, is_array($row) ? count($row) : 1);
        }
        return $max;
    }
}
