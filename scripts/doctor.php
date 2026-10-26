<?php

declare(strict_types=1);

/**
 * Diagnostic en ligne de commande.
 *
 *   php scripts/doctor.php            rapport lisible, couleurs si terminal
 *   php scripts/doctor.php --json     sortie machine (installeur, CI)
 *   php scripts/doctor.php --quick    contrôles locaux seulement, sans réseau
 *
 * Code de sortie : 0 si aucun contrôle en échec, 1 sinon. Les avertissements
 * ne font PAS échouer — sur une installation neuve, « aucune clé API » est
 * l'état normal, pas une panne.
 *
 * Remplace les dix sections de dépannage éparpillées dans la documentation :
 * une seule commande, et chaque ligne en défaut porte son remède.
 */

require __DIR__ . '/_cli_bootstrap.php';

$options  = $argv ?? [];
$enJson   = in_array('--json', $options, true);
$rapide   = in_array('--quick', $options, true);
$couleurs = !$enJson && function_exists('posix_isatty') && @posix_isatty(STDOUT);

$controles = Diagnostics::run(!$rapide);
$global    = Diagnostics::worst($controles);
$echecs    = count(array_filter($controles, static fn (array $c): bool => $c['statut'] === Diagnostics::FAIL));

if ($enJson) {
    echo json_encode([
        'status' => $global,
        'fails'  => $echecs,
        'checks' => $controles,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($echecs > 0 ? 1 : 0);
}

$peindre = static function (string $texte, string $code) use ($couleurs): string {
    return $couleurs ? "\033[" . $code . 'm' . $texte . "\033[0m" : $texte;
};

// Alignement en CARACTÈRES et non en octets : printf('%-42s') compte des
// octets, donc « Migrations de schéma » (un é = 2 octets) se décalait d'une
// colonne. Même raison pour mb_strtoupper : strtoupper() laisse « é » intact
// et produisait « BASE DE DONNéES ».
$aligner = static function (string $texte, int $largeur): string {
    $longueur = mb_strlen($texte);

    return $longueur >= $largeur ? $texte : $texte . str_repeat(' ', $largeur - $longueur);
};

$symboles = [
    Diagnostics::OK   => ['✓', '32'],
    Diagnostics::WARN => ['!', '33'],
    Diagnostics::FAIL => ['✗', '31'],
    Diagnostics::INFO => ['·', '90'],
];

echo PHP_EOL;
echo $peindre('  Diagnostic MIA', '1'), '  —  version ', defined('APP_VERSION') ? APP_VERSION : '?', PHP_EOL;
if ($rapide) {
    echo '  (mode rapide : contrôles locaux seulement)', PHP_EOL;
}
echo PHP_EOL;

foreach (Diagnostics::parCategorie($controles) as $cle => $liste) {
    echo '  ', $peindre(mb_strtoupper(Diagnostics::libelleCategorie($cle)), '1;90'), PHP_EOL;

    foreach ($liste as $c) {
        [$symbole, $code] = $symboles[$c['statut']] ?? $symboles[Diagnostics::INFO];

        echo '  ', $peindre($symbole, $code), '  ',
            $aligner((string) $c['libelle'], 46), ' ',
            $peindre((string) $c['valeur'], '90'), PHP_EOL;

        if ($c['message'] !== '') {
            echo '       ', wordwrap((string) $c['message'], 92, PHP_EOL . '       '), PHP_EOL;
        }
        if ($c['remede'] !== '') {
            echo '       ', $peindre('→ ' . wordwrap((string) $c['remede'], 92, PHP_EOL . '         '), '36'), PHP_EOL;
        }
    }
    echo PHP_EOL;
}

$verdict = match ($global) {
    Diagnostics::FAIL => $peindre('  ✗ ' . $echecs . ' contrôle(s) en échec — l\'installation n\'est pas fonctionnelle.', '31'),
    Diagnostics::WARN => $peindre('  ! Installation fonctionnelle, avec des réserves ci-dessus.', '33'),
    default           => $peindre('  ✓ Installation saine.', '32'),
};

echo $verdict, PHP_EOL, PHP_EOL;

exit($echecs > 0 ? 1 : 0);
