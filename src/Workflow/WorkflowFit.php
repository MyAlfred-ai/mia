<?php

declare(strict_types=1);

/**
 * Un workflow ajusté à la personne qui va s'en servir, sans rien inventer —
 * un modèle livré qu'elle instancie (WorkflowTemplates::get) comme un
 * workflow que MIA lui prépare dans une conversation (WorkflowDraft) :
 *
 * - un connecteur qu'elle n'a pas cède la place à son équivalent qu'elle a
 *   (Gmail ↔ Outlook, agenda Google ↔ calendrier Outlook, Google Tasks ↔
 *   Microsoft To Do) — partout à la fois : sources, actions, déclencheur,
 *   livraisons ; un équivalent RELIÉ l'emporte sur un connecteur seulement
 *   proposé ;
 * - une source qui ne lui est pas proposée du tout est retirée de l'étape
 *   (elle serait ignorée à l'exécution) ;
 * - une création, une action n8n ou Gamma reçoit le premier outil de sa
 *   nature qui lui est ouvert et en service, quand elle n'en a pas, ou plus ;
 * - une livraison vers un service absent (ni lui, ni son équivalent) est
 *   retirée : elle ne pourrait jamais partir ; une livraison dans une base
 *   reçoit la base quand la personne n'en gère qu'une ;
 * - une brique dans un service absent reste, « À régler » le dit : la
 *   retirer changerait ce que fait le workflow.
 *
 * Ce qui a changé est dit dans `$notes` (des phrases pour la personne).
 * Pure. `$choices` : forme de WorkflowCatalog::choices.
 */
final class WorkflowFit
{
    /** Connecteurs interchangeables : le workflow prend celui que la personne a. */
    public const PARTNERS = [
        'gmail' => 'outlook', 'outlook' => 'gmail',
        'calendar' => 'mscalendar', 'mscalendar' => 'calendar',
        'tasks' => 'mstodo', 'mstodo' => 'tasks',
    ];

    /** @param list<string> $notes ce qui a été ajusté, ajouté à la liste */
    public static function apply(array $def, array $choices, array &$notes = []): array
    {
        $def = WorkflowSpec::normalize($def);
        $sources = is_array($choices['sources'] ?? null) ? $choices['sources'] : null;
        $score = static function (string $c) use ($sources): int {
            if ($sources === null || !isset($sources[$c])) {
                return 0;
            }
            return empty($sources[$c]['connected']) ? 1 : 2;
        };
        $label = static function (string $c) use ($sources): string {
            $l = (string)($sources[$c]['label'] ?? '');
            return $l !== '' ? $l : (ConnectorRegistry::label($c) !== '' ? t(ConnectorRegistry::label($c)) : $c);
        };

        $swap = [];
        if ($sources !== null) {
            foreach (self::connectorsOf($def) as $c) {
                $partner = self::PARTNERS[$c] ?? null;
                if ($partner !== null && $score($partner) > $score($c)) {
                    $swap[$c] = $partner;
                    $notes[] = t('{de} remplacé par {par} : c’est le service que vous pouvez utiliser.', ['de' => $label($c), 'par' => $label($partner)]);
                }
            }
        }

        // Le premier outil en service de chaque nature (création) et de chaque
        // type (n8n, Gamma) ; l'outil déjà choisi reste s'il est en service.
        $creations = [];
        $creationOk = [];
        foreach ((array)($choices['creations'] ?? []) as $id => $tool) {
            if (($tool['reason'] ?? null) !== null) {
                continue;
            }
            $tid = (int)($tool['id'] ?? $id);
            $creationOk[$tid] = true;
            $kind = (string)($tool['kind'] ?? '');
            if ($kind !== '' && !isset($creations[$kind])) {
                $creations[$kind] = $tid;
            }
        }
        $tools = [];
        $toolOk = [];
        foreach ((array)($choices['tools'] ?? []) as $id => $tool) {
            if (($tool['reason'] ?? null) !== null) {
                continue;
            }
            $tid = (int)($tool['id'] ?? $id);
            $toolOk[$tid] = true;
            $type = (string)($tool['type'] ?? '');
            if ($type !== '' && !isset($tools[$type])) {
                $tools[$type] = $tid;
            }
        }
        $knownCreations = array_key_exists('creations', $choices);
        $knownTools = array_key_exists('tools', $choices);
        $dropped = [];

        $node = static function (array $n) use (&$node, &$dropped, $swap, $sources, $creations, $creationOk, $tools, $toolOk, $knownCreations, $knownTools): array {
            if (isset($n['sources'])) {
                $list = array_values(array_unique(array_map(static fn(string $s): string => $swap[$s] ?? $s, $n['sources'])));
                if ($sources !== null) {
                    foreach ($list as $s) {
                        if (!isset($sources[$s])) {
                            $dropped[$s] = true;
                        }
                    }
                    $list = array_values(array_filter($list, static fn(string $s): bool => isset($sources[$s])));
                }
                $n['sources'] = $list;
            }
            if ($n['type'] === WorkflowSpec::TYPE_ACTION) {
                if (isset($swap[$n['target']])) {
                    $n['target'] = $swap[$n['target']];
                }
                $conn = WorkflowSpec::ACTION_TARGETS[$n['target']] ?? '';
                if ($knownTools && str_starts_with($conn, 'tool:')) {
                    $type = substr($conn, 5);
                    if (($n['tool_id'] === null || !isset($toolOk[$n['tool_id']])) && isset($tools[$type])) {
                        $n['tool_id'] = $tools[$type];
                    }
                }
            }
            if ($n['type'] === WorkflowSpec::TYPE_CREATE && $knownCreations
                && ($n['tool_id'] === null || !isset($creationOk[$n['tool_id']])) && isset($creations[$n['kind']])) {
                $n['tool_id'] = $creations[$n['kind']];
            }
            foreach (['yes', 'no'] as $lane) {
                if (isset($n[$lane]['nodes'])) {
                    $n[$lane]['nodes'] = array_map($node, $n[$lane]['nodes']);
                }
            }
            if (isset($n['routes'])) {
                foreach ($n['routes'] as $i => $r) {
                    $n['routes'][$i]['nodes'] = array_map($node, $r['nodes']);
                }
            }
            if (isset($n['body'])) {
                $n['body'] = array_map($node, $n['body']);
            }
            return $n;
        };
        $def['nodes'] = array_map($node, $def['nodes']);
        foreach (array_keys($dropped) as $s) {
            $notes[] = t('« {nom} » ne vous est pas proposé : les étapes ne le consulteront pas.', ['nom' => $label($s)]);
        }

        $event = $def['start']['event'] ?? null;
        if (is_array($event) && $event['kind'] === 'email' && isset($swap[$event['source']])) {
            $def['start']['event']['source'] = $swap[$event['source']];
        }

        $kbs = (array)($choices['delivery_kbs'] ?? []);
        $deliveries = [];
        foreach ($def['end']['deliveries'] as $d) {
            if ($d['type'] === 'email' && isset($swap[$d['source']])) {
                $d['source'] = $swap[$d['source']];
            }
            $conn = match ($d['type']) { 'email' => $d['source'], 'slack' => 'slack', 'drive' => 'drive', default => null };
            if ($sources !== null && $conn !== null && $score($conn) === 0) {
                $notes[] = t('Livraison par {service} retirée : ce service ne vous est pas proposé.', ['service' => $label($conn)]);
                continue;
            }
            if ($d['type'] === 'kb' && array_key_exists('delivery_kbs', $choices)
                && ($d['kb_id'] === null || !isset($kbs[$d['kb_id']])) && count($kbs) === 1) {
                $d['kb_id'] = (int)array_key_first($kbs);
            }
            $deliveries[] = $d;
        }
        $def['end']['deliveries'] = $deliveries;

        return WorkflowSpec::normalize($def);
    }

    /** Connecteurs employés : sources, actions, déclencheur, livraisons par email. @return list<string> */
    public static function connectorsOf(array $def): array
    {
        $out = [];
        foreach (WorkflowSpec::flatNodes($def) as $n) {
            foreach ((array)($n['sources'] ?? []) as $s) {
                $out[] = (string)$s;
            }
            if ($n['type'] === WorkflowSpec::TYPE_ACTION && $n['target'] !== '') {
                $out[] = (string)$n['target'];
            }
        }
        $event = $def['start']['event'] ?? null;
        if (is_array($event) && $event['kind'] === 'email') {
            $out[] = (string)$event['source'];
        }
        foreach ($def['end']['deliveries'] as $d) {
            if ($d['type'] === 'email') {
                $out[] = (string)$d['source'];
            }
        }
        return array_values(array_unique($out));
    }
}
