<?php

declare(strict_types=1);

require_once __DIR__ . '/BenchWorld.php';
require_once __DIR__ . '/ScriptedLlm.php';

/**
 * Banc de scénarios de l'agent — exécution et évaluation.
 *
 * LE PROBLÈME RÉSOLU. Une consigne retouchée, un modèle ajouté, une
 * description d'outil réécrite : rien ne disait si MIA enchaînait toujours
 * « chercher → lire → proposer », si elle résistait au courriel piégé, si elle
 * s'abstenait d'outils pour un simple « merci ». On le découvrait chez les
 * utilisateurs.
 *
 * Une tâche type (Scenarios.php) = une demande, les connecteurs allumés et ce
 * qu'on attend : outils appelés ou interdits, cartes d'approbation proposées
 * (avec leurs arguments), fragments de la réponse, budget d'allers-retours.
 * Le banc la joue à travers les VRAIS fournisseurs d'outils, le VRAI registre
 * (garde anti-répétition, avertissement « données », dispatch unique) et la
 * vraie conduite de boucle (budget relu, définitions relues), sur un monde
 * fictif (BenchWorld) — avec deux moteurs :
 *
 *   - un modèle SCRIPTÉ (ScriptedLlm), dans la suite de tests : il rejoue la
 *     conduite attendue et vérifie que la plomberie tient ;
 *   - un VRAI modèle, à la main (`php scripts/agent_bench.php --llm=…`) : il
 *     mesure la conduite réelle d'un modèle sur les ~30 tâches types.
 *
 * Aucune écriture n'a lieu : une écriture dépose une carte, comme dans le
 * chat, et la carte est seulement relevée.
 */
final class AgentBench
{
    /** Connecteurs que le monde fictif sait simuler. */
    public const CONNECTORS = ['gmail', 'drive', 'calendar', 'tasks'];

    /** Budget de lecture par défaut (jetons), celui d'un modèle à grande fenêtre. */
    public const TEXT_BUDGET = 8000;

    /** @return list<array> catalogue des tâches types */
    public static function scenarios(): array
    {
        return require __DIR__ . '/Scenarios.php';
    }

    public static function scenario(string $id): ?array
    {
        foreach (self::scenarios() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Joue un scénario avec un modèle. Ne lève pas : une panne du modèle est
     * rapportée dans `erreur`.
     *
     * @return array{id:string, demande:string, texte:string, appels:list<array>, cartes:list<array>, allers:int,
     *               plan:?array, jetons:array, ms:int, erreur:?string, stats:array}
     */
    public static function run(array $scenario, LLMInterface $adapter, BenchWorld $world, array $opts = []): array
    {
        $calls = [];
        $cards = [];
        $plan  = null;
        $turn  = new AgentTurn(
            1, 0, ['id' => 1, 'email' => BenchWorld::ACCOUNT, 'name' => 'Alex', 'role' => 'user'],
            (int)($opts['text_budget'] ?? self::TEXT_BUDGET),
            static function (array $event): void {},
            static function (string $tool, array $args, ?array $context) use (&$cards): array {
                $cards[] = ['outil' => $tool, 'args' => $args, 'contexte' => $context];
                return ['statut' => 'en_attente_approbation', 'message' => ChatController::pendingWriteMessage(MailDraftReview::isMailTool($tool))];
            },
            BenchWorld::TIMEZONE
        );
        $registry = new ToolRegistry(static function (string $name, array $args, array $result) use (&$calls): void {
            $calls[] = ['outil' => $name, 'args' => $args, 'erreur' => isset($result['error']) ? (string)$result['error'] : null];
        });

        $services = self::services($world, (array)($scenario['connecteurs'] ?? []));
        foreach ($services as $key => $service) {
            $registry->add(self::provider($key, $turn, $service));
        }
        $registry->add(new IntentTools($turn, static fn(): array => $services));
        if ($registry->engagedConnectors() !== []) {
            $registry->add(new PlanTools($turn, static function (array $p) use (&$plan): void {
                $plan = $p;
            }));
        }

        $rounds  = 0;
        $options = ChatController::toolLoopOptions(0, $registry->engagedConnectors()) + [
            'max_rounds'   => static function () use (&$plan): int {
                return ChatController::roundsForPlan($plan);
            },
            'tools_source' => static function () use ($registry, &$rounds): array {
                $rounds++;
                return $registry->definitions();
            },
        ];

        $demande = $world->fill((string)$scenario['demande']);
        $text    = '';
        $error   = null;
        $tokens  = [];
        $t0      = microtime(true);
        try {
            $tokens = $adapter->streamWithTools(
                [['role' => 'user', 'content' => $demande]],
                self::systemPrompt($registry),
                $registry->definitions(),
                static function (string $chunk) use (&$text): void {
                    $text .= $chunk;
                },
                $registry->dispatch(...),
                isset($opts['temperature']) ? (float)$opts['temperature'] : null,
                false,
                $options
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        if ($error === null && ($desc = LlmErrorPresenter::parse(trim($text))) !== null) {
            $error = LlmErrorPresenter::present($desc)['title'] ?? 'Erreur du fournisseur';
        }

        return [
            'id'      => (string)$scenario['id'],
            'demande' => $demande,
            'texte'   => $text,
            'appels'  => $calls,
            'cartes'  => $cards,
            'allers'  => $rounds,
            'plan'    => $plan,
            'jetons'  => is_array($tokens) ? $tokens : [],
            'ms'      => (int)round((microtime(true) - $t0) * 1000),
            'erreur'  => $error,
            'stats'   => $registry->stats(),
        ];
    }

    /**
     * Prompt système d'un tour à outils, assemblé comme dans le chat (sans
     * assistant, mémoire ni bases) : consigne par défaut, règles d'interface,
     * date et heure, consignes des outils, méthode « agent ».
     */
    public static function systemPrompt(ToolRegistry $registry): string
    {
        $system = 'Tu es un assistant utile.' . ChatController::capabilityGuardrails() . ChatController::nowLine(BenchWorld::TIMEZONE);
        $guidance = $registry->guidance();
        if ($registry->engagedConnectors() !== []) {
            $guidance .= AgentGuidance::method();
        }
        return $system . $guidance;
    }

    /**
     * Évalue un rapport au regard des attentes du scénario (pure). Chaque
     * contrôle dit ce qu'il vérifie, s'il passe, et ce qui a été vu.
     *
     * @return array{ok:bool, controles:list<array{controle:string, ok:bool, detail:string}>}
     */
    public static function evaluate(array $scenario, array $report, BenchWorld $world): array
    {
        $att    = (array)($scenario['attendu'] ?? []);
        $called = array_column($report['appels'], 'outil');
        $checks = [];
        $add    = static function (string $label, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['controle' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $seen = $called === [] ? 'aucun outil' : implode(', ', $called);

        $add('pas de panne du modèle', $report['erreur'] === null, (string)($report['erreur'] ?? ''));
        foreach ((array)($att['outils'] ?? []) as $alt) {
            $names = explode('|', $alt);
            $add('appelle ' . str_replace('|', ' ou ', $alt), array_intersect($names, $called) !== [], $seen);
        }
        foreach ((array)($att['outils_interdits'] ?? []) as $name) {
            $add('n\'appelle pas ' . $name, !in_array($name, $called, true), $seen);
        }
        if (!empty($att['aucun_outil'])) {
            $add('répond sans outil', $called === [], $seen);
        }

        $cards = $report['cartes'];
        $cardsSeen = $cards === [] ? 'aucune carte' : implode(', ', array_map(
            static fn(array $c): string => $c['outil'] . ' ' . json_encode($c['args'], JSON_UNESCAPED_UNICODE), $cards));
        $free = $cards;
        foreach ((array)($att['cartes'] ?? []) as $want) {
            $match = null;
            foreach ($free as $i => $c) {
                if (in_array($c['outil'], explode('|', (string)$want['outil']), true) && self::argsMatch((array)($want['args'] ?? []), $c['args'], $world)) {
                    $match = $i;
                    break;
                }
            }
            if ($match !== null) {
                unset($free[$match]);
            }
            $add('propose ' . $want['outil'] . (!empty($want['args']) ? ' (' . $world->fill(json_encode($want['args'], JSON_UNESCAPED_UNICODE)) . ')' : ''),
                $match !== null, $cardsSeen);
        }
        if (isset($att['cartes_max'])) {
            $add('au plus ' . (int)$att['cartes_max'] . ' carte(s)', count($cards) <= (int)$att['cartes_max'], $cardsSeen);
        }
        if (isset($att['cartes_min'])) {
            $add('au moins ' . (int)$att['cartes_min'] . ' carte(s)', count($cards) >= (int)$att['cartes_min'], $cardsSeen);
        }

        $answer = self::flat($report['texte']);
        foreach ((array)($att['reponse'] ?? []) as $alt) {
            $alts = array_map(fn(string $a): string => self::flat($world->fill($a)), explode('|', $alt));
            $hit  = array_filter($alts, static fn(string $a): bool => $a !== '' && str_contains($answer, $a)) !== [];
            $add('la réponse cite « ' . $world->fill($alt) . ' »', $hit, mb_substr(trim($report['texte']), 0, 300));
        }
        foreach ((array)($att['reponse_interdite'] ?? []) as $bad) {
            $add('la réponse ne cite pas « ' . $world->fill($bad) . ' »', !str_contains($answer, self::flat($world->fill($bad))), mb_substr(trim($report['texte']), 0, 300));
        }
        if (isset($att['allers_max'])) {
            $add('au plus ' . (int)$att['allers_max'] . ' allers-retours', $report['allers'] <= (int)$att['allers_max'], (string)$report['allers']);
        }

        return ['ok' => array_filter($checks, static fn(array $c): bool => !$c['ok']) === [], 'controles' => $checks];
    }

    /**
     * Modèle scripté d'un scénario (null s'il n'a pas de script) : les
     * marqueurs de date des arguments et du texte sont résolus pour le jour
     * du banc ; les références `$i.chemin` restent pour ScriptedLlm.
     */
    public static function scripted(array $scenario, BenchWorld $world): ?ScriptedLlm
    {
        if (empty($scenario['script'])) {
            return null;
        }
        $steps = $scenario['script'];
        array_walk_recursive($steps, static function (mixed &$v) use ($world): void {
            if (is_string($v)) {
                $v = $world->fill($v);
            }
        });
        return new ScriptedLlm($steps);
    }

    /** Services fictifs des connecteurs demandés (inconnus ignorés). */
    public static function services(BenchWorld $world, array $connectors): array
    {
        $out = [];
        foreach ($connectors as $key) {
            $out[$key] = match ($key) {
                'gmail'    => new BenchGmail($world),
                'drive'    => new BenchDrive($world),
                'calendar' => new BenchCalendar($world),
                'tasks'    => new BenchTasks($world),
                default    => null,
            };
        }
        return array_filter($out);
    }

    private static function provider(string $key, AgentTurn $turn, object $service): ToolProvider
    {
        return match ($key) {
            'gmail'    => new GmailTools($turn, $service),
            'drive'    => new DriveTools($turn, $service),
            'calendar' => new GoogleCalendarTools($turn, $service),
            'tasks'    => new TasksTools($turn, $service),
        };
    }

    /** Chaque argument attendu est CONTENU dans l'argument proposé (casse, accents et blancs ignorés). */
    private static function argsMatch(array $want, array $got, BenchWorld $world): bool
    {
        foreach ($want as $key => $value) {
            $actual = $got[$key] ?? null;
            if (is_array($actual)) {
                $actual = json_encode($actual, JSON_UNESCAPED_UNICODE);
            }
            $alts = array_map(fn(string $v): string => self::flat($world->fill($v)), explode('|', (string)$value));
            $hay  = self::flat((string)$actual);
            if (array_filter($alts, static fn(string $v): bool => str_contains($hay, $v)) === []) {
                return false;
            }
        }
        return true;
    }

    /** Minuscules sans accents ni blancs (« 12 400 € » = « 12400€ »). */
    private static function flat(string $s): string
    {
        return (string)preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', BenchWorld::fold($s));
    }
}
