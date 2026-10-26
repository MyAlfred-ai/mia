<?php

declare(strict_types=1);

/**
 * Recense les littéraux français destinés à l'écran, pour alimenter lang/en.php.
 *
 * ===================== POURQUOI PAS UN grep ==================================
 *
 * Parce qu'il ne peut pas marcher. Le corpus est en français et emploie
 * l'apostrophe TYPOGRAPHIQUE, qui n'est pas un délimiteur de chaîne :
 *
 *     grep -o "'[^']*'" public/assets/js/chat.js
 *     → 'écran (barre d'      ← un morceau de COMMENTAIRE
 *
 * Un extracteur naïf rend donc du commentaire haché, dans un dépôt où la moitié
 * des lignes accentuées SONT des commentaires (convention du projet). D'où trois
 * analyseurs qui comprennent réellement ce qu'ils lisent :
 *
 *   1. PHP  -> token_get_all() : T_COMMENT et T_DOC_COMMENT disparaissent
 *              nativement, et l'on sait de quel APPEL une chaîne est l'argument.
 *   2. HTML -> nœuds texte + liste blanche d'attributs, scripts et styles retirés.
 *   3. JS   -> analyseur de chaînes qui suit l'état (quotes, gabarits,
 *              commentaires, littéraux d'expression régulière).
 *
 * ===================== CE QU'IL NE PEUT PAS DÉCIDER ==========================
 *
 * Un littéral français n'est pas forcément destiné à l'écran. Cet outil PROPOSE,
 * un humain TRANCHE. Il écarte déjà ce qu'il sait faux (journaux, requêtes,
 * en-têtes, clés de réglage), mais il ne peut pas distinguer un message affiché
 * d'un message rendu AU MODÈLE — les deux sont du français dans un tableau
 * `['error' => …]`. Faux positifs attendus : ~20 % dans templates/, ~50 % dans src/.
 *
 * Usage :
 *   php scripts/i18n-scan.php templates/chat/index.php
 *   php scripts/i18n-scan.php --squelette public/assets/js/chat.js
 *   php scripts/i18n-scan.php --csv templates/ > /tmp/lot1.csv
 *
 * N'écrit JAMAIS dans le dépôt : la sortie va sur stdout.
 */

$args = array_slice($argv, 1);
$mode = 'liste';
$cibles = [];

foreach ($args as $a) {
    if ($a === '--squelette') { $mode = 'squelette'; continue; }
    if ($a === '--csv')       { $mode = 'csv';       continue; }
    if (str_starts_with($a, '--')) {
        fwrite(STDERR, "Option inconnue : $a\n");
        exit(2);
    }
    $cibles[] = $a;
}

if ($cibles === []) {
    fwrite(STDERR, "Usage : php scripts/i18n-scan.php [--squelette|--csv] <chemin…>\n");
    exit(2);
}

$racine = dirname(__DIR__);

/** Attributs HTML dont la valeur est LUE par l'utilisateur. */
/**
 * Mots d'interface français d'UN SEUL mot et SANS accent.
 *
 * Hors HTML, le critère de francité repose sur l'accent ou sur deux mots — un
 * bouton « Gras » ou « Copier » y échappe donc, alors qu'il s'affiche. Ce
 * lexique court comble ce trou sans ouvrir la porte à tous les identifiants.
 * Comparaison insensible à la casse.
 */
const MOTS_INTERFACE = [
    'gras', 'italique', 'souligne', 'copier', 'coller', 'couper', 'ouvrir', 'fermer',
    'envoyer', 'annuler', 'valider', 'supprimer', 'modifier', 'ajouter', 'retirer',
    'partager', 'partage', 'telecharger', 'renommer', 'epingler', 'enregistrer',
    'generer', 'creer', 'suivant', 'precedent', 'terminer', 'continuer', 'recommencer',
    'options', 'actions', 'aucune', 'aucun', 'texte', 'image', 'taille', 'police',
    'sujet', 'plan', 'menu', 'code', 'accueil', 'assistant', 'connecteurs', 'outils',
    'reponse', 'duree', 'modele', 'conversation', 'traitement', 'generation',
];

/** Marqueur laissé par masquerPhp() à la place d'un bloc PHP. */
const MARQUE_MASQUE = '{PHP}';

const ATTRS_VISIBLES = ['placeholder', 'title', 'aria-label', 'alt', 'data-tip', 'aria-description'];

/**
 * Appels dont le premier argument n'est JAMAIS affiché.
 * `error_log` : lu par personne. `Settings::get`/`prepare`/`header` : des clés.
 */
const APPELS_MUETS = [
    'error_log', 'prepare', 'query', 'exec', 'header', 'json_decode', 'json_encode',
    'preg_match', 'preg_match_all', 'preg_replace', 'preg_split', 'preg_quote',
    // strtotime lit de l'anglais par contrat ('first day of next month') : c'est
    // une expression de l'API PHP, pas une phrase destinée à quelqu'un.
    'strtotime',
    'str_contains', 'str_starts_with', 'str_ends_with', 'explode', 'implode',
    'file_get_contents', 'file_put_contents', 'is_file', 'define', 'getenv', 'putenv',
    'setcookie', 'hash', 'sprintf', 'date', 'number_format', 'htmlspecialchars',
];

/** Méthodes statiques dont le 1er argument est une clé, pas un texte. */
const METHODES_MUETTES = ['get', 'set', 'has', 'remove', 'flash', 'getFlash', 'override'];

/** Fichiers dont le français est du CONTENU RÉDIGÉ ou un protocole, jamais un libellé. */
const FICHIERS_EXCLUS = [
    'src/Assistants/PromptStarters.php',   // bibliothèque de prompts, éditée par l'admin
    'src/Tools/ToolPrompts.php',           // prompts par défaut des outils
    'src/Models/DefaultAssistant.php',     // prompt système de l'assistant d'aide
    'src/Changelog/Nouveautes.php',        // contenu rédigé
];

// Note : LlmErrorPresenter n'est PAS exclu. Ses regex françaises (parse(), un
// protocole interne entre adaptateurs) sont déjà protégées, puisque preg_match
// figure dans APPELS_MUETS ; seuls les messages de present(), eux bel et bien
// affichés, remontent.


/**
 * Un littéral mérite-t-il d'être proposé ?
 * Critère volontairement large (l'humain tranche), mais qui écarte le
 * technique évident : slugs, URLs, sélecteurs, MIME, couleurs, formats.
 */
function estCandidat(string $s, bool $visible = false): bool
{
    $s = trim($s);

    if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 400) {
        return false;
    }
    // Doit contenir un vrai mot.
    if (!preg_match('/\p{L}{2,}/u', $s)) {
        return false;
    }
    // Technique évident.
    // Délimiteur ~ et non # : le motif contient lui-même un # (couleur hexa).
    if (preg_match('~^(https?://|/|\.{1,2}/|data:|mailto:)~', $s)) {
        return false;
    }
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $s)) {
        return false;
    }
    if (preg_match('/^[\d\s.,:+%€$-]+$/u', $s)) {          // nombres, formats
        return false;
    }
    // Reste du masquage : la chaîne est composite (du PHP la complète), elle ne
    // se traduit pas telle quelle — c'est au gabarit d'appeler te() lui-même.
    if (str_contains($s, MARQUE_MASQUE)) {
        return false;
    }
    // Markup stocké dans une chaîne : icônes SVG, fragments d'<img>. C'est de
    // la présentation, jamais du texte lu.
    if (preg_match('/^\s*<[a-zA-Z!\/]/', $s)) {
        return false;
    }

    // Entités HTML seules (&mdash;, &times;, &nbsp;) : de la typographie, pas
    // du texte. Le raccourci « visible » les laisserait passer, et te('&times;')
    // n'aurait aucun sens dans un dictionnaire.
    if (preg_match('/^(?:&[a-zA-Z]+;|&#\d+;|\s)+$/', $s)) {
        return false;
    }

    // Mot d'interface connu (« Gras », « Copier »…) : accepté tel quel, AVANT
    // les filtres de forme — « Gras » serait sinon rejeté comme un nom de classe.
    $sansAccent = strtr(mb_strtolower($s), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'û' => 'u', 'î' => 'i', 'ô' => 'o']);
    if (in_array(rtrim($sansAccent, " \t:.…"), MOTS_INTERFACE, true)) {
        return true;
    }

    // ---- Point de bascule -------------------------------------------------
    // Un nœud texte HTML ou un attribut de la liste blanche est LU PAR
    // L'UTILISATEUR par construction : les filtres de FORME qui suivent ne s'y
    // appliquent pas. « Accueil » ressemble à un nom de classe, « Ouvrir » n'a
    // ni accent ni deux mots — les rejeter ici était le gisement de faux
    // négatifs le plus fourni, et le plus difficile à voir : rien ne signale
    // une chaîne que l'outil n'a jamais proposée.
    if ($visible) {
        return true;
    }

    if (preg_match('/^[a-z0-9_.\-\/]+$/', $s)) {          // slug, route, clé, colonne
        return false;
    }
    if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $s)) {        // NomDeClasse, CONSTANTE
        return false;
    }
    if (preg_match('/^[\w.-]+\/[\w.+-]+$/', $s)) {         // type MIME
        return false;
    }
    if (preg_match('/^[.#][\w-]+/', $s)) {                 // sélecteur CSS
        return false;
    }

    // Ailleurs (littéral PHP ou JS), le critère reste strict : un accent, OU
    // deux mots séparés par une espace, OU un mot accompagné d'un substituant
    // {nom} — ce dernier cas est un faux négatif coûteux : « (erreur {code}) »
    // n'a ni accent ni deux mots au sens de la regex, et resterait pourtant en
    // français sur un écran anglais.
    return (bool) preg_match('/[à-öø-ÿÀ-ÖØ-Þ]/u', $s)
        || (bool) preg_match('/\p{L}{2,}\s+\p{L}{2,}/u', $s)
        || ((bool) preg_match('/\{\w+\}/', $s) && (bool) preg_match('/\p{L}{2,}/u', $s));
}

/**
 * Extrait les chaînes d'un source PHP (gabarit ou classe).
 * @return list<array{ligne:int, texte:string, ou:string}>
 */
function scannerPhp(string $source): array
{
    // L'HTML est scanné D'UN SEUL TENANT, sur une copie où chaque bloc PHP est
    // masqué. Scanner chaque T_INLINE_HTML séparément séparerait un <script> de
    // son </script> et couperait les balises en deux dès qu'une échappée PHP s'y
    // glisse — l'outil prendrait alors des fragments de balise pour du texte.
    // (Et non : on n'écrit pas la balise fermante PHP dans un commentaire, elle
    //  fermerait le bloc pour de vrai.)
    $out = scannerHtml(masquerPhp($source), 1);

    $jetons = token_get_all($source);
    $n = count($jetons);

    for ($i = 0; $i < $n; $i++) {
        $j = $jetons[$i];

        if (!is_array($j)) {
            continue;
        }

        // --- Littéraux PHP ----------------------------------------------
        if ($j[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $brut = $j[1];
        $quote = $brut[0];
        $texte = substr($brut, 1, -1);
        $texte = $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $texte)
            : stripcslashes($texte);

        // Déjà enveloppé d'un t()/te()/tj() : ce n'est plus du travail à faire,
        // mais c'est exactement ce que le dictionnaire doit contenir. D'où un
        // marquage plutôt qu'un rejet — la liste par défaut montre le RESTE à
        // traiter, --squelette et --csv rendent tout.
        //
        // Et le drapeau vaut « visible » : une fois enveloppé, te('Accueil')
        // est un littéral PHP comme un autre, que le critère strict rejetterait.
        // Il DISPARAÎTRAIT alors du squelette, et la clé n'aurait jamais de
        // traduction — un mot resté français sur un écran anglais, sans que
        // rien ne le signale.
        $enveloppe = dejaEnveloppe($jetons, $i);

        if (!estCandidat($texte, $enveloppe)) {
            continue;
        }
        if (!$enveloppe && contexteMuet($jetons, $i)) {
            continue;
        }

        $out[] = ['ligne' => $j[2], 'texte' => $texte, 'ou' => $enveloppe ? 'traduit' : 'php'];
    }

    return $out;
}

/**
 * Le littéral est-il DANS l'appel d'une fonction de traduction ?
 *
 * On remonte jusqu'à la parenthèse ouvrante ENGLOBANTE, et non au jeton
 * précédent : sinon une branche de ternaire — `te($x ? 'Partagé' : 'Partager')` —
 * ou un argument de plural() passeraient pour non traduits, et la liste de
 * travail restant montrerait éternellement des chaînes déjà faites.
 */
function dejaEnveloppe(array $jetons, int $i): bool
{
    $profondeur = 0;

    for ($k = $i - 1; $k >= 0; $k--) {
        $j = $jetons[$k];

        if ($j === ')') { $profondeur++; continue; }
        if ($j === '(') {
            if ($profondeur > 0) { $profondeur--; continue; }

            // Parenthèse englobante : le jeton d'avant est le nom appelé.
            $n = $k - 1;
            while ($n >= 0 && is_array($jetons[$n]) && $jetons[$n][0] === T_WHITESPACE) {
                $n--;
            }

            return $n >= 0 && is_array($jetons[$n]) && $jetons[$n][0] === T_STRING
                && in_array($jetons[$n][1], ['t', 'te', 'tj', 'plural'], true);
        }
        // Une virgule ou un point-virgule au niveau 0 hors appel : on est sorti.
        if ($profondeur === 0 && $j === ';') {
            return false;
        }
    }

    return false;
}

/**
 * Remplace chaque bloc PHP par un jeton inerte, EN PRÉSERVANT les retours à la
 * ligne : les numéros de ligne rapportés restent ceux du fichier.
 */
function masquerPhp(string $source): string
{
    return (string) preg_replace_callback(
        '/<\?(?:php|=)?.*?(?:\?>|$)/s',
        static fn (array $m): string => '{PHP}' . str_repeat("\n", substr_count($m[0], "\n")),
        $source
    );
}

/**
 * Le littéral est-il le 1er argument d'un appel dont la sortie n'est pas
 * affichée ? C'est là que se joue l'essentiel du tri automatique.
 */
function contexteMuet(array $jetons, int $i): bool
{
    // Remonter : '(' puis le nom de fonction/méthode.
    $k = $i - 1;
    while ($k >= 0 && is_array($jetons[$k]) && $jetons[$k][0] === T_WHITESPACE) {
        $k--;
    }
    if ($k < 0 || $jetons[$k] !== '(') {
        return false;    // pas en 1re position d'un appel
    }
    $k--;
    while ($k >= 0 && is_array($jetons[$k]) && $jetons[$k][0] === T_WHITESPACE) {
        $k--;
    }
    if ($k < 0 || !is_array($jetons[$k]) || $jetons[$k][0] !== T_STRING) {
        return false;
    }
    $nom = $jetons[$k][1];

    if (in_array($nom, APPELS_MUETS, true)) {
        return true;
    }

    // Settings::get('clé'), Session::set('clé') …
    if (in_array($nom, METHODES_MUETTES, true)) {
        $p = $k - 1;
        while ($p >= 0 && is_array($jetons[$p]) && $jetons[$p][0] === T_WHITESPACE) {
            $p--;
        }
        if ($p >= 0 && is_array($jetons[$p])
            && ($jetons[$p][0] === T_DOUBLE_COLON || $jetons[$p][0] === T_OBJECT_OPERATOR)) {
            return true;
        }
    }

    return false;
}

/**
 * Nœuds texte et attributs visibles d'un fragment HTML.
 * Les <script> sont confiés à l'analyseur JS, les <style> jetés.
 * @return list<array{ligne:int, texte:string, ou:string}>
 */
function scannerHtml(string $html, int $ligneBase): array
{
    $out = [];

    // Blocs <script> : analysés comme du JavaScript.
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $bloc) {
            $decalage = substr_count(substr($html, 0, $bloc[1]), "\n");
            foreach (scannerJs($bloc[0], $ligneBase + $decalage) as $t) {
                $t['ou'] = 'js-inline';
                $out[] = $t;
            }
        }
    }

    $net = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    $net = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $net) ?? $net;
    $net = preg_replace('/<!--.*?-->/s', '', $net) ?? $net;

    // Attributs visibles.
    $attrs = implode('|', ATTRS_VISIBLES);
    if (preg_match_all('/\b(' . $attrs . ')\s*=\s*"([^"]*)"/i', $net, $ma, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($ma as $a) {
            $valeur = $a[2][0];
            // Une valeur qui contient du PHP masqué est composite : elle ne se
            // traduit pas telle quelle, c'est au gabarit d'appeler te().
            if (estCandidat($valeur, true) && !str_contains($valeur, MARQUE_MASQUE)) {
                $out[] = [
                    'ligne' => $ligneBase + substr_count(substr($net, 0, $a[0][1]), "\n"),
                    'texte' => trim($valeur),
                    'ou'    => 'attr:' . strtolower($a[1][0]),
                ];
            }
        }
    }

    // Nœuds texte, en suivant la ligne courante.
    $ligne = $ligneBase;
    foreach (preg_split('/(<[^>]*>)/', $net, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $bout) {
        if ($bout === '') {
            continue;
        }
        if ($bout[0] === '<') {
            $ligne += substr_count($bout, "\n");
            continue;
        }
        $texte = trim(preg_replace('/\s+/u', ' ', str_replace(MARQUE_MASQUE, '', $bout)) ?? $bout);
        if ($texte !== '' && estCandidat($texte, true)) {
            $out[] = ['ligne' => $ligne, 'texte' => $texte, 'ou' => 'texte'];
        }
        $ligne += substr_count($bout, "\n");
    }

    return $out;
}

/**
 * Décode les échappements d'une chaîne JavaScript.
 *
 * stripcslashes() NE CONNAÎT PAS \uXXXX : « l\u2019automatisation » en
 * ressortait sous la forme « lu2019automatisation ». La clé extraite ne
 * correspondait alors à AUCUNE chaîne réelle, et sa traduction n'aurait jamais
 * été trouvée à l'exécution — panne invisible, puisque le repli affiche le
 * français.
 */
function decoderJs(string $val): string
{
    $val = preg_replace_callback(
        '/\\\\u\{([0-9a-fA-F]+)\}|\\\\u([0-9a-fA-F]{4})/',
        static fn (array $m): string => mb_chr((int) hexdec(($m[1] ?? '') !== '' ? $m[1] : $m[2]), 'UTF-8'),
        $val
    ) ?? $val;

    return stripcslashes($val);
}

/**
 * Chaînes d'un source JavaScript. Suit l'état pour ne jamais prendre un
 * commentaire — ni une apostrophe française — pour un délimiteur.
 * @return list<array{ligne:int, texte:string, ou:string}>
 */
function scannerJs(string $src, int $ligneBase = 1): array
{
    $out = [];
    $n = strlen($src);
    $ligne = $ligneBase;
    $precedent = '';   // dernier caractère significatif : distingue / division de / regex

    for ($i = 0; $i < $n; $i++) {
        $c = $src[$i];

        if ($c === "\n") { $ligne++; continue; }
        if ($c === ' ' || $c === "\t" || $c === "\r") { continue; }

        // Commentaires.
        if ($c === '/' && $i + 1 < $n) {
            if ($src[$i + 1] === '/') {
                while ($i < $n && $src[$i] !== "\n") { $i++; }
                $ligne++;
                continue;
            }
            if ($src[$i + 1] === '*') {
                $fin = strpos($src, '*/', $i + 2);
                $fin = $fin === false ? $n : $fin + 2;
                $ligne += substr_count(substr($src, $i, $fin - $i), "\n");
                $i = $fin - 1;
                continue;
            }

            // Littéral d'expression régulière. SANS ce cas, un motif contenant
            // une apostrophe — /['"]/ est courant ici — ouvre une fausse chaîne
            // qui avale le code suivant jusqu'au prochain guillemet : l'outil
            // MASQUERAIT alors des libellés réels, ce qui est pire que du bruit.
            if ($precedent === '' || str_contains('(,=:[!&|?{};+-*%~^<>', $precedent)) {
                $j = $i + 1;
                $classe = false;
                while ($j < $n) {
                    if ($src[$j] === '\\') { $j += 2; continue; }
                    if ($src[$j] === '[') { $classe = true; }
                    elseif ($src[$j] === ']') { $classe = false; }
                    elseif ($src[$j] === '/' && !$classe) { break; }
                    elseif ($src[$j] === "\n") { break; }   // pas un regex après tout
                    $j++;
                }
                if ($j < $n && $src[$j] === '/') {
                    $i = $j;
                    $precedent = '/';
                    continue;
                }
            }
        }

        if ($c !== "'" && $c !== '"' && $c !== '`') {
            $precedent = $c;
            continue;
        }

        // Chaîne : avancer jusqu'au délimiteur non échappé.
        $delim = $c;
        $debut = $i + 1;
        $ligneDebut = $ligne;
        $i++;
        $val = '';
        while ($i < $n) {
            if ($src[$i] === '\\') { $val .= $src[$i] . ($src[$i + 1] ?? ''); $i += 2; continue; }
            if ($src[$i] === $delim) { break; }
            if ($src[$i] === "\n") { $ligne++; }
            $val .= $src[$i];
            $i++;
        }

        $texte = decoderJs($val);
        // Un gabarit interpolé n'est pas traduisible tel quel : on le signale.
        $interpole = $delim === '`' && str_contains($val, '${');

        if (estCandidat($texte)) {
            // Déjà enveloppé côté client ? On regarde ce qui précède le
            // guillemet ouvrant. Sans ce test, la liste de travail restant ne
            // décroîtrait jamais et masquerait ce qui reste vraiment à faire.
            $posQuote  = $debut - 1;   // $debut pointe APRÈS le guillemet ouvrant
            // Fenêtre large : le 3e argument de tn() est séparé de son appel par
            // le 2e, qui est une phrase entière. 24 caractères ne suffisaient pas,
            // et les deux formes d'un pluriel restaient comptées « à traiter ».
            $amont     = substr($src, max(0, $posQuote - 200), min(200, $posQuote));
            // Le second motif couvre les 2e et 3e arguments de tn(pluriel) :
            // sans lui, les deux formes d'un pluriel passeraient éternellement
            // pour non traduites (même piège que côté PHP avec les ternaires).
            $enveloppe = (bool) preg_match('/\b(?:t|tn|te|tj)\(\s*$/', $amont)
                || (bool) preg_match('/\btn\([^()]*$/', $amont);

            $out[] = [
                'ligne' => $ligneDebut,
                'texte' => $texte,
                'ou'    => $enveloppe ? 'traduit' : ($interpole ? 'js-gabarit' : 'js'),
            ];
        }
        $precedent = $delim;
    }

    return $out;
}

/** @return list<string> */
function fichiersDe(string $chemin, string $racine): array
{
    $abs = str_starts_with($chemin, '/') ? $chemin : $racine . '/' . $chemin;

    if (is_file($abs)) {
        return [$abs];
    }
    if (!is_dir($abs)) {
        fwrite(STDERR, "Introuvable : $chemin\n");

        return [];
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs));
    foreach ($it as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'js'], true)) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

// ---------------------------------------------------------------------------

$resultats = [];

foreach ($cibles as $cible) {
    foreach (fichiersDe($cible, $racine) as $abs) {
        $rel = ltrim(str_replace($racine, '', $abs), '/');

        if (in_array($rel, FICHIERS_EXCLUS, true) || str_contains($rel, '/vendor/')
            || preg_match('/\.min\.js$/', $rel)) {
            continue;
        }

        $source = (string) file_get_contents($abs);
        $trouves = str_ends_with($abs, '.js') ? scannerJs($source) : scannerPhp($source);

        foreach ($trouves as $t) {
            $resultats[] = $t + ['fichier' => $rel];
        }
    }
}

// Déduplication par texte, en conservant le premier emplacement.
$parTexte = [];
foreach ($resultats as $r) {
    $parTexte[$r['texte']] ??= $r;
}
ksort($parTexte, SORT_STRING);

if ($mode === 'squelette') {
    echo "    // Généré par scripts/i18n-scan.php — À RELIRE (l'outil propose, l'humain tranche).\n";
    foreach ($parTexte as $texte => $r) {
        printf("    %s => '',%s\n", var_export((string) $texte, true), "  // {$r['fichier']}:{$r['ligne']}");
    }
    exit(0);
}

if ($mode === 'csv') {
    $h = fopen('php://output', 'w');
    fputcsv($h, ['texte', 'fichier', 'ligne', 'ou'], ',', '"', '\\');
    foreach ($parTexte as $texte => $r) {
        fputcsv($h, [$texte, $r['fichier'], $r['ligne'], $r['ou']], ',', '"', '\\');
    }
    exit(0);
}

$restants = array_filter($parTexte, static fn (array $r): bool => $r['ou'] !== 'traduit');

foreach ($restants as $texte => $r) {
    printf("%-58s  %s:%d  [%s]\n", mb_strimwidth((string) $texte, 0, 56, '…'), $r['fichier'], $r['ligne'], $r['ou']);
}

fwrite(STDERR, sprintf(
    "\n%d à traiter · %d déjà enveloppés · %d distincts (%d occurrences brutes).\n",
    count($restants),
    count($parTexte) - count($restants),
    count($parTexte),
    count($resultats)
));
