<?php

declare(strict_types=1);

/**
 * Formules de tableur écrites par un modèle — ce qu'on en garde, et pourquoi.
 *
 * DEUX BESOINS OPPOSÉS. Un budget « avec les totaux calculés » n'a de valeur
 * que si `=SOMME(B2:B9)` arrive dans le classeur comme une FORMULE. Mais le
 * contenu d'un tableau vient parfois d'une page web, d'un email ou d'un
 * document lus par le modèle : une cellule `=HYPERLINK(…)` ou
 * `=cmd|' /C calc'!A0` y est une attaque connue (« injection de formule »),
 * exécutée à l'ouverture du fichier par celui qui l'a téléchargé en confiance.
 *
 * D'où une LISTE BLANCHE, et non une liste noire : une formule n'est conservée
 * que si elle se réduit à des fonctions de calcul connues, des références de
 * cellules de la feuille, des nombres, des chaînes et des opérateurs. Tout le
 * reste — nom inconnu, référence à une autre feuille ou à un autre classeur
 * (`!`, `[`), canal DDE (`|`) — est écrit comme du TEXTE.
 *
 * Le classeur attend des noms de fonction anglais et la virgule comme
 * séparateur d'arguments, quelle que soit la langue d'Excel : `normalize()`
 * traduit donc `=SOMME(A1;A2)` en `=SUM(A1,A2)`.
 *
 * Fonctions pures.
 */
final class SpreadsheetFormula
{
    /** Longueur maximale d'une formule acceptée (caractères). */
    private const MAX_LENGTH = 2000;

    /**
     * Fonctions conservées. Calcul, texte, dates, recherche DANS la feuille.
     * Absentes à dessein : HYPERLINK, WEBSERVICE, FILTERXML, RTD, CALL,
     * INDIRECT, OFFSET, INFO, CELL — tout ce qui sort de la feuille.
     *
     * @var list<string>
     */
    private const FUNCTIONS = [
        'SUM', 'AVERAGE', 'MIN', 'MAX', 'COUNT', 'COUNTA', 'COUNTBLANK', 'PRODUCT', 'MEDIAN', 'MODE',
        'SUMIF', 'SUMIFS', 'COUNTIF', 'COUNTIFS', 'AVERAGEIF', 'AVERAGEIFS', 'SUMPRODUCT',
        'STDEV', 'VAR', 'LARGE', 'SMALL', 'RANK',
        'IF', 'IFS', 'AND', 'OR', 'NOT', 'XOR', 'IFERROR', 'IFNA', 'SWITCH',
        'ROUND', 'ROUNDUP', 'ROUNDDOWN', 'ABS', 'INT', 'MOD', 'POWER', 'SQRT', 'CEILING', 'FLOOR',
        'TRUNC', 'SIGN', 'EXP', 'LN', 'LOG', 'LOG10', 'PI',
        'TODAY', 'NOW', 'YEAR', 'MONTH', 'DAY', 'DATE', 'DATEDIF', 'EDATE', 'EOMONTH', 'WEEKDAY',
        'WEEKNUM', 'DAYS', 'NETWORKDAYS', 'HOUR', 'MINUTE', 'SECOND', 'TIME',
        'CONCATENATE', 'CONCAT', 'TEXTJOIN', 'LEFT', 'RIGHT', 'MID', 'LEN', 'UPPER', 'LOWER', 'PROPER',
        'TRIM', 'TEXT', 'VALUE', 'SUBSTITUTE', 'REPLACE', 'FIND', 'SEARCH', 'REPT',
        'VLOOKUP', 'HLOOKUP', 'XLOOKUP', 'INDEX', 'MATCH', 'CHOOSE', 'ROW', 'COLUMN', 'ROWS', 'COLUMNS',
        'ISBLANK', 'ISNUMBER', 'ISTEXT', 'ISERROR', 'NA',
        'PMT', 'FV', 'PV', 'NPV', 'IRR', 'RATE', 'NPER',
    ];

    /**
     * Noms français courants → noms du format de fichier. Un modèle qui écrit
     * pour un utilisateur francophone écrit `SOMME`, pas `SUM`.
     *
     * @var array<string, string>
     */
    private const FRENCH = [
        'SOMME' => 'SUM', 'MOYENNE' => 'AVERAGE', 'NB' => 'COUNT', 'NBVAL' => 'COUNTA',
        'NB.VIDE' => 'COUNTBLANK', 'PRODUIT' => 'PRODUCT', 'MEDIANE' => 'MEDIAN', 'MÉDIANE' => 'MEDIAN',
        'SOMME.SI' => 'SUMIF', 'SOMME.SI.ENS' => 'SUMIFS', 'NB.SI' => 'COUNTIF', 'NB.SI.ENS' => 'COUNTIFS',
        'MOYENNE.SI' => 'AVERAGEIF', 'MOYENNE.SI.ENS' => 'AVERAGEIFS', 'SOMMEPROD' => 'SUMPRODUCT',
        'ECARTYPE' => 'STDEV', 'GRANDE.VALEUR' => 'LARGE', 'PETITE.VALEUR' => 'SMALL', 'RANG' => 'RANK',
        'SI' => 'IF', 'SI.CONDITIONS' => 'IFS', 'ET' => 'AND', 'OU' => 'OR', 'NON' => 'NOT',
        'OUX' => 'XOR', 'SIERREUR' => 'IFERROR', 'SI.NON.DISP' => 'IFNA',
        'ARRONDI' => 'ROUND', 'ARRONDI.SUP' => 'ROUNDUP', 'ARRONDI.INF' => 'ROUNDDOWN',
        'ENT' => 'INT', 'PUISSANCE' => 'POWER', 'RACINE' => 'SQRT', 'PLAFOND' => 'CEILING',
        'PLANCHER' => 'FLOOR', 'TRONQUE' => 'TRUNC', 'SIGNE' => 'SIGN',
        'AUJOURDHUI' => 'TODAY', 'MAINTENANT' => 'NOW', 'ANNEE' => 'YEAR', 'ANNÉE' => 'YEAR',
        'MOIS' => 'MONTH', 'JOUR' => 'DAY', 'MOIS.DECALER' => 'EDATE', 'FIN.MOIS' => 'EOMONTH',
        'JOURSEM' => 'WEEKDAY', 'NO.SEMAINE' => 'WEEKNUM', 'JOURS' => 'DAYS',
        'NB.JOURS.OUVRES' => 'NETWORKDAYS', 'HEURE' => 'HOUR', 'SECONDE' => 'SECOND', 'TEMPS' => 'TIME',
        'CONCATENER' => 'CONCATENATE', 'CONCATÉNER' => 'CONCATENATE', 'JOINDRE.TEXTE' => 'TEXTJOIN',
        'GAUCHE' => 'LEFT', 'DROITE' => 'RIGHT', 'STXT' => 'MID', 'NBCAR' => 'LEN',
        'MAJUSCULE' => 'UPPER', 'MINUSCULE' => 'LOWER', 'NOMPROPRE' => 'PROPER',
        'SUPPRESPACE' => 'TRIM', 'TEXTE' => 'TEXT', 'CNUM' => 'VALUE', 'SUBSTITUE' => 'SUBSTITUTE',
        'REMPLACER' => 'REPLACE', 'TROUVE' => 'FIND', 'CHERCHE' => 'SEARCH',
        'RECHERCHEV' => 'VLOOKUP', 'RECHERCHEH' => 'HLOOKUP', 'RECHERCHEX' => 'XLOOKUP',
        'EQUIV' => 'MATCH', 'CHOISIR' => 'CHOOSE', 'LIGNE' => 'ROW', 'COLONNE' => 'COLUMN',
        'LIGNES' => 'ROWS', 'COLONNES' => 'COLUMNS', 'ESTVIDE' => 'ISBLANK', 'ESTNUM' => 'ISNUMBER',
        'ESTTEXTE' => 'ISTEXT', 'ESTERREUR' => 'ISERROR',
        'VPM' => 'PMT', 'VC' => 'FV', 'VA' => 'PV', 'VAN' => 'NPV', 'TRI' => 'IRR', 'TAUX' => 'RATE', 'NPM' => 'NPER',
    ];

    /**
     * Formule normalisée (« = » compris, noms anglais, virgules) si elle est
     * sûre, null sinon — l'appelant écrit alors la cellule comme du texte.
     */
    public static function normalize(string $cell): ?string
    {
        $cell = trim($cell);
        if (strlen($cell) < 2 || $cell[0] !== '=' || strlen($cell) > self::MAX_LENGTH) {
            return null;
        }

        $tokens = self::tokenize(substr($cell, 1));
        if ($tokens === null || $tokens === []) {
            return null;
        }

        $decimals = self::decimalCommas($tokens);
        if (!self::isWellFormed($tokens, $decimals)) {
            return null;
        }

        $out   = '=';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$kind, $value] = $tokens[$i];

            if ($kind === 'op') {
                if ($value === ';') {
                    $value = ',';
                } elseif (isset($decimals[$i])) {
                    $value = '.';
                }
            } elseif ($kind === 'name') {
                // Nom nu : uniquement une colonne entière encadrée par « : » (A:A).
                $rangeSide = ($i > 0 && $tokens[$i - 1] === ['op', ':'])
                    || ($i + 1 < $count && $tokens[$i + 1] === ['op', ':']);
                if (!$rangeSide || preg_match('/^\$?[A-Z]{1,3}$/', $value) !== 1) {
                    return null;
                }
            }

            $out .= $value;
        }

        return $out;
    }

    /**
     * Virgules DÉCIMALES d'une formule (« 1,5 »), par position de jeton.
     *
     * Une virgule entre deux entiers est une décimale dans deux cas : hors de
     * tout appel de fonction (rien à séparer), ou dans une formule écrite à la
     * française — reconnaissable à ses points-virgules entre arguments. Un
     * nombre n'a qu'une virgule : dans « 1,5,2 » la seconde n'en est pas une.
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return array<int, true>
     */
    private static function decimalCommas(array $tokens): array
    {
        $french = in_array(['op', ';'], $tokens, true);
        $found  = [];
        $depth  = 0;
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$kind, $value] = $tokens[$i];
            if ($kind !== 'op') {
                continue;
            }
            if ($value === '(') {
                $depth++;
            } elseif ($value === ')') {
                $depth--;
            } elseif ($value === ','
                && ($french || $depth === 0)
                && $i > 0 && $i + 1 < $count
                && $tokens[$i - 1][0] === 'int' && $tokens[$i + 1][0] === 'int'
                && !isset($found[$i - 2])) {
                $found[$i] = true;
            }
        }

        return $found;
    }

    /**
     * Cellule protégée pour un fichier CSV : préfixée d'une apostrophe quand
     * un tableur l'exécuterait alors que ce n'est pas une formule sûre.
     *
     * Volontairement plus fin que la règle usuelle (« tout ce qui commence par
     * = + - @ ») : elle défigure un tiret de remplissage, un nombre négatif,
     * un numéro « +33 … » ou une mention « @nom », qui ne peuvent rien
     * exécuter. Seuls sont préfixés un `=` qui n'est pas une formule sûre, et
     * un `+`, `-` ou `@` suivi d'un appel de fonction ou d'un canal DDE.
     */
    public static function neutralize(string $cell): string
    {
        $content = ltrim($cell, " \t\r\n");
        if ($content === '') {
            return $cell;
        }

        $first = $content[0];
        if ($first === '=') {
            return self::normalize($content) !== null ? $cell : "'" . $cell;
        }
        if ($first !== '+' && $first !== '-' && $first !== '@') {
            return $cell;
        }
        if (SpreadsheetCell::isNumeric($content, 'fr') || SpreadsheetCell::isNumeric($content, 'en')) {
            return $cell;
        }

        // Ce qu'il faut pour nuire : un canal DDE (« | ») ou un APPEL de
        // fonction — un nom suivi d'une parenthèse. Une parenthèse seule ne
        // suffit pas : « +33 (0)6 12 34 56 78 » est un numéro de téléphone.
        $executable = str_contains($content, '|')
            || preg_match('/[\p{L}_][\p{L}\p{N}_.]*\s*\(/u', $content) === 1;

        return $executable ? "'" . $cell : $cell;
    }

    /**
     * Les jetons forment-ils une expression — des opérandes séparés par des
     * opérateurs, des parenthèses équilibrées ?
     *
     * Une liste blanche de MOTS ne suffit pas : « =- » (un tiret de
     * remplissage), « =A1+ » (une formule coupée) ou « =2 3 » ne contiennent
     * que des jetons admis. Écrits comme formules, ils donnent un classeur
     * qu'Excel déclare endommagé à l'ouverture — pour une cellule qui n'était
     * que du texte. Ce contrôle n'est pas une grammaire d'Excel : il vérifie
     * l'alternance opérande / opérateur, ce qui écarte tout ce qui n'a pas la
     * forme d'un calcul.
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @param array<int, true>                  $decimals Positions des virgules décimales.
     */
    private static function isWellFormed(array $tokens, array $decimals): bool
    {
        $expectOperand = true;    // on attend une valeur (début, après un opérateur, après « ( »)
        $depth         = 0;
        $previous      = null;    // opérateur précédent, ou nature de la valeur précédente
        $count         = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$kind, $value] = $tokens[$i];

            if ($kind !== 'op') {
                // Valeur (nombre, chaîne, référence, booléen, colonne) ou fonction.
                if (!$expectOperand) {
                    return false;                     // « 2 3 », « A1 B2 » : deux valeurs accolées
                }
                // Une fonction attend sa parenthèse : on reste en attente d'opérande.
                $expectOperand = $kind === 'fn';
                $previous      = $kind;
                continue;
            }

            if ($value === '(') {
                if (!$expectOperand) {
                    return false;                     // « A1(B2) » : une valeur n'est pas une fonction
                }
                $depth++;
            } elseif ($value === ')') {
                // Après une valeur, ou juste après « ( » / un séparateur
                // (argument vide : `AUJOURDHUI()`, `SI(A1;1;)`).
                $emptyArgument = $previous === '(' || $previous === ',' || $previous === ';';
                if ($depth === 0 || ($expectOperand && !$emptyArgument)) {
                    return false;
                }
                $depth--;
                $expectOperand = false;
            } elseif ($value === ',' || $value === ';') {
                // Hors de tout appel, il n'y a pas d'argument à séparer : seule
                // la virgule DÉCIMALE (« 1,5 ») a sa place.
                if ($depth === 0 && !isset($decimals[$i])) {
                    return false;
                }
                $expectOperand = true;
            } elseif ($value === '%') {
                if ($expectOperand) {
                    return false;                     // « % » suit une valeur
                }
            } elseif ($expectOperand) {
                if ($value !== '+' && $value !== '-') {
                    return false;                     // seul un signe peut précéder une valeur
                }
            } else {
                $expectOperand = true;                // opérateur binaire
            }
            $previous = $value;
        }

        return !$expectOperand && $depth === 0;
    }

    /**
     * Découpe le corps d'une formule en jetons ; null au premier caractère qui
     * n'a rien à faire dans une formule de calcul.
     *
     * Jetons : ['str', '"…"'], ['int', '12'], ['num', '1.5e3'], ['ref', 'B2'],
     * ['fn', 'SUM'], ['bool', 'TRUE'], ['name', 'A'] (colonne entière),
     * ['op', '+'].
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function tokenize(string $body): ?array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            if (preg_match('/\G\s+/u', $body, $m, 0, $offset) === 1) {
                $offset += strlen($m[0]);
                continue;
            }
            // Chaîne : guillemets doublés pour en contenir un.
            if (preg_match('/\G"(?:[^"]|"")*"/u', $body, $m, 0, $offset) === 1) {
                $tokens[] = ['str', $m[0]];
                $offset  += strlen($m[0]);
                continue;
            }
            // Référence de cellule (A1, $B$12). Pas suivie d'une lettre, d'un
            // chiffre ni d'une parenthèse : « LOG10( » est une fonction.
            if (preg_match('/\G\$?[A-Za-z]{1,3}\$?[0-9]{1,7}(?![A-Za-z0-9_.(])/', $body, $m, 0, $offset) === 1) {
                $tokens[] = ['ref', strtoupper($m[0])];
                $offset  += strlen($m[0]);
                continue;
            }
            // Nombre. La virgule décimale est traitée au réassemblage : ici
            // « 1,5 » donne trois jetons, que le contexte départagera.
            if (preg_match('/\G[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $body, $m, 0, $offset) === 1) {
                $tokens[] = [ctype_digit($m[0]) ? 'int' : 'num', $m[0]];
                $offset  += strlen($m[0]);
                continue;
            }
            // Identifiant : fonction (suivi de « ( »), booléen, ou colonne entière.
            if (preg_match('/\G\$?[\p{L}_][\p{L}\p{N}_.]*/u', $body, $m, 0, $offset) === 1) {
                $word    = $m[0];
                $offset += strlen($word);
                $upper   = mb_strtoupper($word, 'UTF-8');

                $next = $offset;
                while ($next < $length && ($body[$next] === ' ' || $body[$next] === "\t")) {
                    $next++;
                }
                if ($next < $length && $body[$next] === '(') {
                    $name = self::FRENCH[$upper] ?? $upper;
                    if (!in_array($name, self::FUNCTIONS, true)) {
                        return null;
                    }
                    $tokens[] = ['fn', $name];
                    continue;
                }
                if ($upper === 'TRUE' || $upper === 'VRAI') {
                    $tokens[] = ['bool', 'TRUE'];
                    continue;
                }
                if ($upper === 'FALSE' || $upper === 'FAUX') {
                    $tokens[] = ['bool', 'FALSE'];
                    continue;
                }
                $tokens[] = ['name', $upper];
                continue;
            }
            // Opérateurs à deux caractères, puis à un.
            if (preg_match('/\G(?:<>|<=|>=)/', $body, $m, 0, $offset) === 1) {
                $tokens[] = ['op', $m[0]];
                $offset  += 2;
                continue;
            }
            $char = $body[$offset];
            if (strpos('+-*/^&=<>(),;:%', $char) !== false) {
                $tokens[] = ['op', $char];
                $offset++;
                continue;
            }

            return null;
        }

        return $tokens;
    }
}
