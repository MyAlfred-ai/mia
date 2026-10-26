<?php

declare(strict_types=1);

/**
 * Banc de scénarios de l'agent sur VRAIS modèles — lancé à la main.
 *
 *   php scripts/agent_bench.php --llm=anthropic::claude-opus-5
 *   php scripts/agent_bench.php --llm=anthropic::claude-opus-5,openai::gpt-5.6 --repeat=3 --json=bench.json
 *   php scripts/agent_bench.php --llm=gemini::gemini-3-flash-preview --only=mail-resume,injection-mail --verbose
 *   php scripts/agent_bench.php --dry-run      (conduites scriptées : ni clé, ni coût)
 *   php scripts/agent_bench.php --list
 *
 * Chaque tâche type (tests/Bench/Scenarios.php) est jouée sur le monde fictif
 * d'« Alex » (tests/Bench/BenchWorld.php) à travers les vrais fournisseurs
 * d'outils, le vrai registre et la vraie boucle du modèle. Rien n'est écrit
 * nulle part : une écriture dépose une carte, relevée par le banc. Aucune
 * donnée réelle n'est lue — seule la clé API du modèle est utilisée.
 *
 * COÛT : chaque tâche est un vrai appel au modèle (quelques milliers de
 * jetons, davantage pour les tâches à plusieurs étapes). La consommation est
 * inscrite au grand livre comme un test d'outil (Administration, hors quota,
 * imputée à l'instance) : elle se lit sur la page Usages.
 *
 * Codes de sortie : 0 tout passe, 1 au moins un échec, 2 usage incorrect.
 */

require __DIR__ . '/_cli_bootstrap.php';
require ROOT . '/tests/Bench/AgentBench.php';

$opts = getopt('', ['llm:', 'only:', 'repeat:', 'json:', 'dry-run', 'list', 'verbose', 'temperature:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage : php scripts/agent_bench.php --llm=fournisseur::modèle[,…] | --dry-run | --list\n"
        . "Options : --only=id,id  --repeat=N (1 à 10)  --json=fichier  --verbose  --temperature=0.2\n");
    exit(0);
}

$scenarios = AgentBench::scenarios();
if (isset($opts['list'])) {
    foreach ($scenarios as $s) {
        fwrite(STDOUT, sprintf("%-26s %-12s %s%s\n", $s['id'], implode('+', $s['connecteurs']), $s['titre'], !empty($s['script']) ? '  [scripté]' : ''));
    }
    exit(0);
}
if (isset($opts['only'])) {
    $wanted    = array_filter(array_map('trim', explode(',', (string)$opts['only'])));
    $scenarios = array_values(array_filter($scenarios, static fn(array $s): bool => in_array($s['id'], $wanted, true)));
    $unknown   = array_diff($wanted, array_column($scenarios, 'id'));
    if ($unknown !== []) {
        fwrite(STDERR, 'Tâche(s) inconnue(s) : ' . implode(', ', $unknown) . " (--list pour le catalogue)\n");
        exit(2);
    }
}

$dryRun = isset($opts['dry-run']);
$llms   = $dryRun ? ['scripté'] : array_filter(array_map('trim', explode(',', (string)($opts['llm'] ?? ''))));
if ($llms === []) {
    fwrite(STDERR, "Indiquez --llm=fournisseur::modèle (plusieurs séparés par des virgules), ou --dry-run. --help pour l'aide.\n");
    exit(2);
}
if ($dryRun) {
    $scenarios = array_values(array_filter($scenarios, static fn(array $s): bool => !empty($s['script'])));
}
$repeat  = max(1, min(10, (int)($opts['repeat'] ?? 1)));
$verbose = isset($opts['verbose']);
$world   = new BenchWorld();
$all     = [];
$failed  = false;

fwrite(STDOUT, 'Banc de l\'agent — ' . count($scenarios) . ' tâche(s) × ' . $repeat . ' — monde daté du '
    . BenchWorld::fr($world->today) . "\n");

foreach ($llms as $llm) {
    fwrite(STDOUT, "\n== " . $llm . "\n");
    $sum = ['ok' => 0, 'runs' => 0, 'allers' => 0, 'ms' => 0, 'in' => 0, 'out' => 0];
    foreach ($scenarios as $scenario) {
        for ($i = 1; $i <= $repeat; $i++) {
            $adapter = $dryRun ? AgentBench::scripted($scenario, $world) : LLMFactory::make($llm);
            $report  = AgentBench::run($scenario, $adapter, $world, isset($opts['temperature']) ? ['temperature' => (float)$opts['temperature']] : []);
            $eval    = AgentBench::evaluate($scenario, $report, $world);
            if (!$dryRun) {
                UsageMeter::llm($llm, $report['jetons'], UsageSource::TOOL_TEST, ['user_id' => null]);
            }

            $sum['runs']++;
            $sum['ok']     += $eval['ok'] ? 1 : 0;
            $sum['allers'] += $report['allers'];
            $sum['ms']     += $report['ms'];
            $sum['in']     += (int)($report['jetons']['input'] ?? 0);
            $sum['out']    += (int)($report['jetons']['output'] ?? 0);
            $failed = $failed || !$eval['ok'];

            fwrite(STDOUT, sprintf(
                "%s %-26s allers %d · outils %d · cartes %d · %s s · %s jetons%s\n",
                $eval['ok'] ? '✓' : '✗',
                $scenario['id'] . ($repeat > 1 ? ' #' . $i : ''),
                $report['allers'],
                count($report['appels']),
                count($report['cartes']),
                number_format($report['ms'] / 1000, 1, ',', ' '),
                number_format((int)($report['jetons']['input'] ?? 0) + (int)($report['jetons']['output'] ?? 0), 0, ',', ' '),
                !empty($report['jetons']['rounds_exhausted']) ? ' · budget atteint' : ''
            ));
            foreach ($eval['controles'] as $c) {
                if (!$c['ok']) {
                    fwrite(STDOUT, '    ✗ ' . $c['controle'] . ($c['detail'] !== '' ? ' — vu : ' . mb_substr($c['detail'], 0, 220) : '') . "\n");
                }
            }
            if ($verbose && !$eval['ok']) {
                foreach ($report['appels'] as $a) {
                    fwrite(STDOUT, '      → ' . $a['outil'] . ' ' . json_encode($a['args'], JSON_UNESCAPED_UNICODE)
                        . ($a['erreur'] !== null ? ' ⚠ ' . $a['erreur'] : '') . "\n");
                }
                fwrite(STDOUT, '      « ' . mb_substr(trim($report['texte']), 0, 600) . " »\n");
            }
            $all[] = ['llm' => $llm, 'essai' => $i, 'evaluation' => $eval] + $report;
        }
    }
    fwrite(STDOUT, sprintf(
        "-- %s : %d/%d réussies (%d %%) · %s allers en moyenne · %s s en moyenne · %s jetons d'entrée, %s de sortie\n",
        $llm,
        $sum['ok'],
        $sum['runs'],
        $sum['runs'] > 0 ? (int)round($sum['ok'] * 100 / $sum['runs']) : 0,
        number_format($sum['runs'] > 0 ? $sum['allers'] / $sum['runs'] : 0, 1, ',', ' '),
        number_format($sum['runs'] > 0 ? $sum['ms'] / 1000 / $sum['runs'] : 0, 1, ',', ' '),
        number_format($sum['in'], 0, ',', ' '),
        number_format($sum['out'], 0, ',', ' ')
    ));
}

if (!empty($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
    fwrite(STDOUT, "\nRapport détaillé : " . $opts['json'] . "\n");
}
exit($failed ? 1 : 0);
