<?php

declare(strict_types=1);

/**
 * Opération de fond sur une table de travail, APRÈS l'accord de la personne
 * (carte approuvée → ChatController::approveTableMission) :
 *
 *   - `enrich` : chaque ligne est lue par le modèle, lot par lot, et sa
 *     réponse rangée dans de nouvelles colonnes (WorkTableEnricher::run) ;
 *   - `act`    : chaque action tenue en réserve (une par ligne : brouillon,
 *     tâche) est exécutée par le chemin d'un clic « Approuver »
 *     (ChatController::decideForTable), son issue notée dans la table.
 *
 * Ce qu'une réponse ne peut pas porter — des centaines de lignes, plusieurs
 * minutes — vit ici : le worker mène l'opération, la personne continue à
 * travailler, et le bilan arrive dans la conversation.
 *
 * REPRENABLE, donc hors NON_IDEMPOTENT : chaque lot enrichi, chaque action
 * exécutée est un point de contrôle écrit en base. Un job rejoué par le
 * reaper ne refait rien de ce qui est fait (lignes déjà remplies ; actions
 * qui ne sont plus en réserve) — il continue.
 *
 * Trois garde-fous d'une opération longue : l'arrêt demandé (`shouldStop`,
 * lu à chaque lot et à chaque action — c'est aussi le battement de cœur),
 * le plafond de crédits annoncé sur la carte, et le quota ou le budget,
 * relus à chaque lot. Dans les trois cas, ce qui est fait reste fait et le
 * bilan dit ce qui reste.
 *
 * Étend ChatJob : les actions passent par ChatController (la seule
 * implémentation d'une décision), et la sonde d'arrêt des adaptateurs est
 * branchée — un appel au modèle en cours s'interrompt au clic Stop.
 */
class TableMissionJob extends ChatJob
{
    /** Délai minimal entre deux notes d'avancement (secondes). */
    private const PROGRESS_EVERY = 1.5;
    /** Lignes écrites par point de contrôle pendant une action groupée. */
    private const ACT_CHECKPOINT = 10;

    protected function execute(ChatController $chat, array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $user   = User::findById($ctx->userId());
        if ($convId <= 0 || !$user) {
            throw new JobErrorException([
                'kind'    => 'server',
                'title'   => t('Opération impossible'),
                'message' => t('Cette opération sur une table ne peut pas être menée : conversation ou compte introuvable.'),
            ], 'contexte table_mission incomplet');
        }
        $store = new SqlWorkTableStore();
        $table = $store->findById($convId, (int)($payload['table_id'] ?? 0));
        if ($table === null) {
            return $this->conclude($convId, '⚠️ ' . t('La table visée n’existe plus : l’opération n’a pas été menée.'), false);
        }

        return match ((string)($payload['op'] ?? '')) {
            'enrich' => $this->enrich($store, $table, $user, $payload, $ctx),
            'act'    => $this->act($chat, $store, $table, $user, $payload, $ctx),
            default  => throw new JobErrorException([
                'kind'    => 'server',
                'title'   => t('Opération impossible'),
                'message' => t('Cette opération sur une table n’est pas prise en charge par le serveur (mise à jour incomplète).'),
            ], 'op table_mission inconnue'),
        };
    }

    private function enrich(WorkTableStore $store, array $table, array $user, array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $spec   = (array)($payload['spec'] ?? []);
        $llm    = (string)($payload['llm'] ?? '');
        if ($llm === '' || !is_array($spec['new'] ?? null) || !is_array($spec['read'] ?? null) || trim((string)($spec['instruction'] ?? '')) === '') {
            throw new JobErrorException([
                'kind'    => 'server',
                'title'   => t('Opération impossible'),
                'message' => t('Cet enrichissement ne peut pas être mené : sa description est illisible. Redemandez-le.'),
            ], 'spec table_mission illisible');
        }
        $ctx->setPhase('table_enrich');
        $statusMsg = (int)($payload['status_msg_id'] ?? 0);
        $last = 0.0;
        try {
            $result = WorkTableEnricher::run(
                $store,
                $convId,
                (int)$table['id'],
                $spec,
                $llm,
                // Rattachées au message de lancement : jamais comptées avec la
                // réponse d'un autre tour mené pendant l'opération.
                ['user_id' => (int)$user['id'], 'conversation_id' => $convId] + ($statusMsg > 0 ? ['message_id' => $statusMsg] : []),
                [
                    'cap'      => isset($payload['cap']) && is_numeric($payload['cap']) ? (int)$payload['cap'] : null,
                    'exceeded' => static fn(): bool => CreditQuota::isExceeded(CreditQuota::status($user)),
                    'progress' => function (int $done, int $total) use ($ctx, $statusMsg, &$last): bool {
                        $now = microtime(true);
                        if ($now - $last >= self::PROGRESS_EVERY || $done >= $total) {
                            $last = $now;
                            $ctx->setProgress($total > 0 ? (int)floor($done * 100 / $total) : 0);
                            $ctx->emit(['mission' => ['msg_id' => $statusMsg, 'done' => $done, 'total' => $total,
                                'text' => t('Enrichissement : {n} lignes sur {total}…', ['n' => $done, 'total' => $total])]]);
                        }
                        return !$ctx->shouldStop();
                    },
                ]
            );
        } catch (\InvalidArgumentException $e) {
            // La table a changé depuis l'accord (une colonne à lire a disparu).
            return $this->conclude($convId, '⚠️ ' . t('Enrichissement impossible : {raison} Redemandez-le sur la table telle qu’elle est maintenant.', ['raison' => $e->getMessage()]), false);
        }
        // Crédit épuisé, clé refusée : consigné pour l'administration, comme
        // depuis le chat — l'opération, elle, se conclut par son bilan.
        if (is_array($result['error'] ?? null)) {
            ProviderIncident::note($result['error']);
        }
        $text   = WorkTableEnricher::resultMessage($result, $spec, (string)$table['title']);
        $report = is_array($payload['report'] ?? null) ? (array)$payload['report'] : null;
        if ($report !== null && (int)$result['done'] > 0) {
            // Table permanente enrichie sans copie : les valeurs attendent l'accord.
            $text .= ' ' . t('Une carte, ci-dessous, propose de les écrire dans « {table} » : rien n’y est écrit avant votre accord.', ['table' => (string)($report['title'] ?? '')]);
        }
        $out = $this->conclude(
            $convId,
            $text,
            $result['status'] === 'stopped' && $result['reason'] === 'user',
            $payload['ref'] ?? (int)$table['id']
        );
        if ($report !== null && (int)$result['done'] > 0) {
            $card = ChatController::depositTableReport($convId, $user, (int)$table['id'], $report,
                array_values(array_map(static fn(array $n): string => (string)$n['key'], (array)($spec['new'] ?? []))), (int)($out['msg_id'] ?? 0));
            if ($card > 0) {
                $out['card'] = $card;
            }
        }
        return $out;
    }

    private function act(ChatController $chat, WorkTableStore $store, array $table, array $user, array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $tool   = (string)($payload['tool'] ?? '');
        $items  = array_values(array_filter(
            array_map(static fn($it): array => [(int)($it[0] ?? -1), (int)($it[1] ?? 0)], (array)($payload['items'] ?? [])),
            static fn(array $it): bool => $it[0] >= 0 && $it[1] > 0
        ));
        if (!WorkTableActions::isAllowed($tool) || $items === []) {
            throw new JobErrorException([
                'kind'    => 'server',
                'title'   => t('Opération impossible'),
                'message' => t('Cette action groupée ne peut pas être menée : sa description est illisible. Redemandez-la.'),
            ], 'action groupée illisible');
        }
        $ctx->setPhase('table_act');
        $statusMsg = (int)($payload['status_msg_id'] ?? 0);
        $keys    = WorkTableActions::resultKeys($tool);
        $columns = WorkTableActions::withResultColumns((array)$table['columns'], $tool);
        $rows    = $store->rows((int)$table['id']);
        $total   = count($items);
        // La table doit rester telle que l'opération la laisse (empreinte) :
        // remplacée ou transformée par un autre message, ses rangs ne
        // désignent plus les mêmes lignes — on s'arrête sans rien écraser.
        $first = $store->patch($convId, (int)$table['id'], $columns, [], WorkTableOps::stateStamp($table));
        if ($first === null || !empty($first['stale'])) {
            DrivePendingAction::cancelHeld(array_map(static fn(array $it): int => $it[1], $items), (int)$user['id'], 'Table modifiée.');
            return $this->conclude($convId, WorkTableActions::resultMessage($tool, 0, 0, $total, (string)$table['title'], true, true), false);
        }
        $stamp = WorkTableOps::stateStamp($first);

        $done = 0;
        $failed = 0;
        $handled = 0;
        $stopped = false;
        $changed = false;
        $patch = [];
        $last = 0.0;
        $checkpoint = function () use (&$patch, &$stamp, &$changed, $store, $convId, $table, $columns): void {
            if ($patch === [] || $changed) {
                return;
            }
            $written = $store->patch($convId, (int)$table['id'], $columns, $patch, $stamp);
            $patch = [];
            if ($written === null || !empty($written['stale'])) {
                $changed = true;
                return;
            }
            $stamp = WorkTableOps::stateStamp($written);
        };
        foreach ($items as $i => [$position, $actionId]) {
            // Arrêt demandé : aussi le battement de cœur de l'opération.
            if ($ctx->shouldStop() || $changed) {
                $stopped = true;
                break;
            }
            $data = $chat->decideForTable($convId, $user, $actionId);
            $handled++;
            if (!empty($data['skipped'])) {
                // Déjà faite par une opération rejouée (ou écartée) : la
                // table porte déjà son résultat, rien à réécrire.
                if (trim((string)($rows[$position][$keys['status']] ?? '')) === WorkTableActions::STATUS_DONE) {
                    $done++;
                }
                continue;
            }
            $outcome = Scheduler::autoOutcome($data);
            if (isset($rows[$position])) {
                $rows[$position][$keys['status']] = $outcome['ok']
                    ? WorkTableActions::STATUS_DONE
                    : WorkTableActions::STATUS_FAILED . ' : ' . mb_substr(trim((string)$outcome['error']) !== '' ? trim((string)$outcome['error']) : t('raison inconnue'), 0, 300);
                $rows[$position][$keys['link']] = $outcome['ok'] ? (string)$outcome['url'] : '';
                $patch[$position] = $rows[$position];
            }
            $outcome['ok'] ? $done++ : $failed++;
            if (count($patch) >= self::ACT_CHECKPOINT) {
                $checkpoint();
            }
            $now = microtime(true);
            if ($now - $last >= self::PROGRESS_EVERY || $i === $total - 1) {
                $last = $now;
                $ctx->setProgress((int)floor(($i + 1) * 100 / $total));
                $ctx->emit(['mission' => ['msg_id' => $statusMsg, 'done' => $i + 1, 'total' => $total,
                    'text' => t('{n} sur {total} : {actions}…', ['n' => $i + 1, 'total' => $total, 'actions' => WorkTableActions::countLabel($tool, $total)])]]);
            }
        }
        $checkpoint();
        $left = $total - $handled;
        if ($stopped && $left > 0) {
            // Ce qui n'a pas été fait ne le sera pas : aucune action ne reste en réserve.
            DrivePendingAction::cancelHeld(array_map(static fn(array $it): int => $it[1], array_slice($items, $handled)), (int)$user['id'], 'Opération arrêtée.');
        }
        return $this->conclude(
            $convId,
            WorkTableActions::resultMessage($tool, $done, $failed, $left, (string)$table['title'], $stopped, $changed),
            $stopped && !$changed,
            $payload['ref'] ?? (int)$table['id']
        );
    }

    /**
     * Bilan persisté dans la conversation — c'est lui qu'on retrouve au
     * rechargement — et rendu au flux. La table de l'opération est nommée
     * sous lui, avec son bouton « Voir » (WorkTableView) : on vient regarder
     * ce qui a été rempli.
     */
    private function conclude(int $convId, string $text, bool $cancelled, int|string $tableRef = 0): array
    {
        $msg   = Message::create($convId, 'assistant', $text);
        $msgId = (int)($msg['id'] ?? 0);
        Conversation::touch($convId);
        $tables = null;
        // Identifiant d'une table de la conversation, ou « p12 » : la table
        // permanente qu'une opération sans copie a complétée.
        $tableRef = WorkTableView::ref($tableRef);
        if ($tableRef !== 0 && $msgId > 0) {
            Message::setAgent($msgId, ['tables' => [$tableRef]]);
            $tables = ChatController::turnTablesPayload($convId, [$tableRef]);
        }
        return ['done' => true, 'msg_id' => $msgId, 'content' => $text, 'tables' => $tables]
            + ($cancelled ? ['cancelled' => true] : []);
    }
}
