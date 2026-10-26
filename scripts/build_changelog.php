<?php

declare(strict_types=1);

/**
 * Recalcule VERSION et republie public/changelog.json.
 *
 * Appelé par scripts/hooks/post-commit après CHAQUE commit. Trois sorties :
 *
 *   1. VERSION            = <VERSION_BASE>.<nombre d'améliorations depuis le
 *                           commit qui a posé cette base>  (inchangé)
 *   2. changelog.nouveautes = les 20 entrées rédigées de docs/NOUVEAUTES.md,
 *                           datées et numérotées depuis l'historique git
 *   3. changelog.commits  = les 30 derniers sujets de commit, réservés au
 *                           détail technique (comptes @myalfred.ai)
 *
 * DÉTERMINISME OBLIGATOIRE. Le hook amende le commit pour y glisser ces
 * fichiers : si deux exécutions successives sur le même historique
 * produisaient des sorties différentes, chaque commit réécrirait la sortie du
 * précédent et le hook amenderait sans fin. D'où l'interdiction absolue d'un
 * horodatage « maintenant » — la seule date écrite est celle d'un commit.
 *
 * DATATION D'UNE ENTRÉE. La date affichée est celle de la CRÉATION de
 * l'entrée : le premier commit où son identifiant apparaît dans le fichier
 * source. Enrichir une nouveauté de la semaine dernière ne la fait donc pas
 * remonter devant celles d'hier — c'est précisément ce qui rend l'enrichissement
 * possible. Une entrée qui porte déjà `date:` et `version:` à la main garde les
 * siennes : les entrées antérieures à la mise en place de ce fichier
 * apparaissent toutes dans le même commit et seraient sinon datées du même jour.
 *
 * Usage :
 *   php scripts/build_changelog.php            écrit les fichiers
 *   php scripts/build_changelog.php --check    n'écrit rien, sort en 1 si obsolète
 *   php scripts/build_changelog.php --verbose  dit ce qui a été retenu
 */

$root = dirname(__DIR__);
require $root . '/src/Changelog/Nouveautes.php';

$check   = in_array('--check', $argv, true);
$verbose = in_array('--verbose', $argv, true);

// ===================== Historique git =====================

/** Exécute une commande git et renvoie sa sortie brute ('' si échec). */
function git(string $root, array $args): string
{
    $cmd = 'git -C ' . escapeshellarg($root);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = @shell_exec($cmd . ' 2>/dev/null');
    return is_string($out) ? $out : '';
}

$base = trim((string) @file_get_contents($root . '/VERSION_BASE')) ?: '1.0';

/**
 * Frontières de base : chaque commit ayant modifié VERSION_BASE, avec la valeur
 * qu'il a posée. Le compteur repart à 0 à chacune — c'est ce qui rend deux
 * versions comparables d'un coup d'œil À BASE ÉGALE.
 */
$bornes = [];
foreach (array_filter(explode("\n", git($root, ['log', '--pretty=%H', 'HEAD', '--', 'VERSION_BASE']))) as $sha) {
    $val = trim(git($root, ['show', $sha . ':VERSION_BASE']));
    if ($val !== '') {
        $bornes[$sha] = $val;
    }
}

$SEP = "\x1f";
$brut = git($root, ['log', '--no-merges', '--date=short', '--pretty=format:%H' . $SEP . '%ad' . $SEP . '%s', 'HEAD']);
$commits = []; // du plus récent au plus ancien
foreach (explode("\n", $brut) as $ligne) {
    $p = explode($SEP, $ligne);
    if (count($p) === 3 && $p[0] !== '') {
        $commits[] = ['sha' => $p[0], 'date' => $p[1], 'sujet' => $p[2]];
    }
}

// Types de commit qui comptent comme « amélioration » (le compteur de VERSION).
$AMELIORATIONS = '/^(feat|fix|refactor|perf|docs)(?:\(([^)]+)\))?:\s*(.+)$/';

/**
 * Parcours du plus ANCIEN au plus récent : version courante après chaque
 * commit. Les commits non comptés (chore, ci, style) héritent de la version en
 * cours — une entrée de nouveautés introduite par l'un d'eux doit tout de même
 * porter un numéro.
 */
$baseCourante = null;
foreach (array_reverse($commits) as $c) {
    if (isset($bornes[$c['sha']])) {
        $baseCourante = $bornes[$c['sha']];
        break;
    }
}
$baseCourante ??= $base;

$compteur      = 0;
$versionParSha = [];
$journal       = []; // sujets d'amélioration, du plus ancien au plus récent
foreach (array_reverse($commits) as $c) {
    if (isset($bornes[$c['sha']])) {
        $baseCourante = $bornes[$c['sha']];
        $compteur     = 0;
    }
    if (preg_match($AMELIORATIONS, $c['sujet'], $m) === 1) {
        $compteur++;
        $journal[] = [
            'date'    => $c['date'],
            'type'    => $m[1],
            'scope'   => $m[2] ?? '',
            'subject' => $m[3],
            'version' => $baseCourante . '.' . $compteur,
        ];
    }
    $versionParSha[$c['sha']] = $baseCourante . '.' . $compteur;
}

$version = $base . '.' . $compteur;
$journal = array_slice(array_reverse($journal), 0, Nouveautes::MAX_COMMITS);

// ===================== Nouveautés =====================

/**
 * Contenu du fichier source TEL QU'IL EST COMMITÉ. Le lire depuis le disque
 * publierait, en cas de validation partielle (`git commit <chemin>`), un texte
 * qui n'est dans aucun commit — donc absent du déploiement.
 */
$source = git($root, ['show', 'HEAD:' . Nouveautes::SOURCE]);
if (trim($source) === '') {
    $source = (string) @file_get_contents($root . '/' . Nouveautes::SOURCE);
}

$entrees = Nouveautes::parse($source);
$erreurs = Nouveautes::valider($entrees);
if ($erreurs !== []) {
    // Bloquer le commit pour une faute de frappe dans un texte d'annonce serait
    // disproportionné : on prévient, et l'entrée fautive part sans date plutôt
    // que de faire échouer la livraison.
    fwrite(STDERR, "[nouveautés] " . count($erreurs) . " anomalie(s) dans " . Nouveautes::SOURCE . " :\n");
    foreach ($erreurs as $e) {
        fwrite(STDERR, "  - $e\n");
    }
}

/**
 * Premier commit où chaque identifiant apparaît. On remonte l'historique du
 * fichier source du plus ancien au plus récent et on note la première
 * apparition : un `git show` par commit ayant touché ce fichier, jamais par
 * entrée.
 */
$naissance = []; // id => ['date' => …, 'version' => …]
$dateParSha = array_column($commits, 'date', 'sha');
$shasSource = array_values(array_filter(explode(
    "\n",
    git($root, ['log', '--reverse', '--pretty=%H', 'HEAD', '--', Nouveautes::SOURCE])
)));
foreach ($shasSource as $sha) {
    $contenu = git($root, ['show', $sha . ':' . Nouveautes::SOURCE]);
    if (trim($contenu) === '') {
        continue;
    }
    foreach (Nouveautes::parse($contenu) as $e) {
        $id = (string) ($e['id'] ?? '');
        if ($id !== '' && !isset($naissance[$id])) {
            $naissance[$id] = [
                'date'    => (string) ($dateParSha[$sha] ?? ''),
                'version' => (string) ($versionParSha[$sha] ?? ''),
            ];
        }
    }
}

foreach ($entrees as &$e) {
    $id = (string) ($e['id'] ?? '');
    if (($e['date'] ?? '') === '' && isset($naissance[$id]['date'])) {
        $e['date'] = $naissance[$id]['date'];
    }
    if (($e['version'] ?? '') === '' && isset($naissance[$id]['version'])) {
        $e['version'] = $naissance[$id]['version'];
    }
    if ($verbose) {
        $marque = ($e['date'] ?? '') !== '' ? '✓' : '·';
        fwrite(STDOUT, "  $marque {$e['id']} — {$e['titre']} ({$e['date']} v{$e['version']})\n");
    }
}
unset($e);

$publiees = Nouveautes::publiables($entrees);

// ===================== Écriture =====================

$charge = [
    'version'    => $version,
    'nouveautes' => $publiees,
    'commits'    => $journal,
];
$json = json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "[nouveautés] encodage JSON impossible\n");
    exit(0); // ne jamais faire échouer un commit pour ça
}
$json .= "\n";

$fichierVersion   = $root . '/VERSION';
$fichierChangelog = $root . '/' . Nouveautes::PUBLIE;

$versionActuelle = trim((string) @file_get_contents($fichierVersion));
$jsonActuel      = (string) @file_get_contents($fichierChangelog);

$change = ($versionActuelle !== $version) || ($jsonActuel !== $json);

if ($check) {
    echo $change ? "obsolète\n" : "à jour\n";
    exit($change ? 1 : 0);
}

if ($change) {
    @file_put_contents($fichierVersion, $version . "\n");
    @mkdir(dirname($fichierChangelog), 0775, true);
    @file_put_contents($fichierChangelog, $json);
}

// Lu par le hook : « 1 » → il faut amender le commit.
echo "VERSION=$version\n";
echo 'CHANGED=' . ($change ? '1' : '0') . "\n";
echo 'NOUVEAUTES=' . count($publiees) . '/' . count($entrees) . "\n";
exit(0);
