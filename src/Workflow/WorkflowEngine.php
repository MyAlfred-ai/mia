<?php

declare(strict_types=1);

/**
 * Moteur d'enchaînement des workflows.
 *
 * PRINCIPE (celui des tâches programmées) : une étape est un MESSAGE
 * ORDINAIRE. Le moteur écrit la consigne de l'étape dans la conversation de
 * l'exécution et enfile un job `chat` — le même que le bouton Envoyer — avec
 * un bloc `workflow` (WorkflowPrompt) : assistant, bases, connecteurs, trace,
 * facturation et affichage en direct viennent du tour de chat existant.
 *
 * Enchaînement, évènement par évènement, jamais en boucle :
 *  - un job d'étape se termine → JobQueue::finish() appelle onJobFinished()
 *    → la pièce est lue (ArtifactContract) → brique suivante ;
 *  - une carte est tranchée (validation, action à approuver, livraison) →
 *    onCardDecided() ;
 *  - une condition par RÈGLE se calcule sur place ; une QUESTION à MIA part
 *    dans un job `workflow` (WorkflowJob), comme la condition de sortie d'une
 *    boucle « jusqu'à » et la livraison du livrable ;
 *  - une attente, ou un nouvel essai après une panne passagère, reprend à
 *    son heure (`resume_at`, tick()).
 *
 * Boucles : le corps est rejoué tour après tour ; un passage du tour n a la
 * clé « brique#n », sa pièce est rangée sous « piece#n » (WorkflowLoop).
 *
 * Reprise sur erreur : une brique qui échoue sur une panne PASSAGÈRE est
 * réessayée seule (`on_error.retries`, délais croissants) ; ensuite, selon
 * son réglage, l'exécution s'arrête (reprenable : à la brique, en l'ignorant,
 * depuis une brique précédente, avec la dernière version publiée) ou la
 * brique est ignorée et le rail continue.
 *
 * Idempotence : chaque passage d'une brique est une ligne
 * (workflow_run_steps) dont le statut ne change qu'UNE fois
 * (WorkflowRun::transitionStep) — une fin de job rejouée par le reaper ou un
 * double clic ne fait jamais avancer deux fois.
 *
 * Ne lève jamais vers le worker : une erreur interne arrête l'exécution en
 * « échec », reprenable, avec sa cause dans la conversation.
 */
final class WorkflowEngine
{
    /** Créneau programmé rattrapé au plus tard (comme les tâches). */
    public const CATCH_UP_SEC = 43_200;
    public const MAX_PER_TICK = 10;
    public const TICK_SEC = 30;
    /** Suffixe d'une validation implicite : « je relis la pièce de l'étape ». */
    public const REVIEW_SUFFIX = '~review';
    /** Clé (au dossier) des éléments d'une boucle « pour chaque ». */
    public const ITEMS_SUFFIX = '~items';
    public const GATE_TOOL = 'workflow_gate';
    /** Délais entre deux essais automatiques (1er, 2e, 3e nouvel essai). */
    public const BACKOFF_SEC = [60, 300, 900];
    /** Pannes passagères : réessayer a une chance d'aboutir. */
    public const TRANSIENT_KINDS = ['overloaded', 'rate_limit', 'server', 'network', 'timeout', 'empty'];
    /** Une étape dont le job est fini depuis ce délai sans suite est reprise par l'entretien. */
    public const STUCK_SEC = 120;
    /** Exécution « en cours » sans aucune brique active depuis ce délai : interrompue. */
    public const ORPHAN_SEC = 600;
    /** Profondeur maximale d'une chaîne « quand un autre workflow se termine ». */
    public const CHAIN_MAX = 3;

    // ==================================================================
    // Lancement
    // ==================================================================

    /**
     * Lance une exécution : conversation, message de départ, pièces du
     * départ (et de l'évènement), puis la première brique.
     *
     * @param array $values champ → valeur (hors fichiers)
     * @param array $files champ → {name, type, path, extracted}
     * @param ?array $event contenu de la pièce de l'évènement (cf. WorkflowTriggers), null sans évènement
     * @param ?string $ref ce qui a lancé l'exécution (un même évènement ne lance qu'une fois)
     * @return array{run_id: int, conversation_id: int, job_ids: list<int>}|null null si le créneau ou l'évènement est déjà parti
     * @throws \InvalidArgumentException message affichable, traduit
     */
    public static function launch(
        array $workflow, array $user, array $values, array $files, string $trigger, ?\DateTimeImmutable $slot, string $timezone,
        ?array $event = null, ?string $ref = null, ?int $parentRunId = null
    ): ?array {
        if (!Workflow::canUse($user)) {
            throw new \InvalidArgumentException(t('Les workflows ne vous sont pas ouverts.'));
        }
        $def = $workflow['published'];
        if ($def === null) {
            throw new \InvalidArgumentException(t('Publiez le workflow avant de le lancer.'));
        }
        $inputs = self::validateInputs($def, $values, $files);
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            throw new \InvalidArgumentException(CreditQuota::refusal($quota)['message']);
        }

        $userId = (int)$user['id'];
        $tz = ScheduledTask::normalizeTimezone($timezone);
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $runId = WorkflowRun::create((int)$workflow['id'], (int)$workflow['published_version'], $userId, $def, $trigger, $slot, $inputs['public'], $tz, $ref, $parentRunId);
            if ($runId === null) {
                $db->rollBack();
                return null;
            }
            $title = self::asUser($user, static fn(): string => $workflow['name'] . ' — '
                . Format::shortDate(new \DateTimeImmutable('now', Recurrence::zone($tz))));
            $convId = Conversation::createTitled($userId, null, '', $title);
            WorkflowRun::attachConversation($runId, $convId);

            $content = self::asUser($user, static fn(): string => self::launchMessage($workflow, $def, $inputs, $trigger, $event));
            $attached = array_values($inputs['files']);
            if ($event !== null && ($def['start']['event']['kind'] ?? '') === 'file' && !empty($event['path'])) {
                $attached[] = ['name' => (string)$event['name'], 'type' => (string)$event['type'], 'path' => (string)$event['path'], 'extracted' => ''];
            }
            Message::create($convId, 'user', $content, $attached);
            Conversation::touch($convId);

            $pieces = WorkflowSpec::pieces($def);
            if (isset($pieces[WorkflowSpec::START_PIECE])) {
                WorkflowRun::saveArtifact($runId, WorkflowSpec::START_PIECE, WorkflowSpec::START_KEY,
                    $pieces[WorkflowSpec::START_PIECE]['name'], 'fiche', ['fields' => $inputs['fields']]);
            }
            foreach ($inputs['files'] as $key => $file) {
                WorkflowRun::saveArtifact($runId, $key, WorkflowSpec::START_KEY, $pieces[$key]['name'] ?? $key, 'file', [
                    'name' => $file['name'], 'type' => $file['type'], 'path' => $file['path'], 'text' => (string)($file['text'] ?? ''),
                ]);
            }
            if ($event !== null && isset($pieces[WorkflowSpec::EVENT_PIECE])) {
                $ep = $pieces[WorkflowSpec::EVENT_PIECE];
                WorkflowRun::saveArtifact($runId, WorkflowSpec::EVENT_PIECE, WorkflowSpec::START_KEY, $ep['name'], $ep['form'],
                    self::eventContent($ep, $event), $event['provenance'] ?? []);
            }
            $stepId = WorkflowRun::insertStep($runId, WorkflowSpec::START_KEY, WorkflowRun::STEP_DONE);
            WorkflowRun::updateStep($stepId, ['finished' => true]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $jobs = self::advanceFrom($runId, WorkflowSpec::START_KEY, '');
        return ['run_id' => $runId, 'conversation_id' => $convId, 'job_ids' => $jobs];
    }

    /** Contenu de la pièce d'évènement, à la forme de la pièce. */
    private static function eventContent(array $piece, array $event): array
    {
        return match ($piece['form']) {
            'fiche' => [
                'fields'  => array_intersect_key((array)($event['fields'] ?? []), array_flip(array_column($piece['fields'], 'key'))),
                'summary' => mb_substr((string)($event['summary'] ?? ''), 0, 2000),
            ],
            'file' => [
                'name' => (string)($event['name'] ?? ''), 'type' => (string)($event['type'] ?? ''),
                'path' => (string)($event['path'] ?? ''), 'text' => mb_substr((string)($event['text'] ?? ''), 0, 400000),
                'url'  => (string)($event['url'] ?? ''),
            ],
            default => ['text' => mb_substr((string)($event['text'] ?? ''), 0, 400000)],
        };
    }

    /**
     * Valeurs du formulaire de départ, contrôlées : obligatoires, types,
     * choix. `public` = ce qui est gardé avec l'exécution (sans chemins).
     *
     * @return array{fields: array, files: array, public: array}
     * @throws \InvalidArgumentException
     */
    public static function validateInputs(array $def, array $values, array $files): array
    {
        $fields = [];
        $outFiles = [];
        $public = [];
        foreach ($def['start']['fields'] as $f) {
            $key = $f['key'];
            $label = $f['label'] !== '' ? $f['label'] : $key;
            if ($f['type'] === 'file') {
                $file = $files[$key] ?? null;
                if ($file === null) {
                    if ($f['required']) {
                        throw new \InvalidArgumentException(t('Joignez le fichier « {nom} ».', ['nom' => $label]));
                    }
                    continue;
                }
                $outFiles[$key] = $file;
                $public[$key] = (string)$file['name'];
                continue;
            }
            $raw = $values[$key] ?? '';
            $raw = is_scalar($raw) ? trim((string)$raw) : '';
            if ($raw === '' && $f['default'] !== '') {
                $raw = $f['default'];
            }
            if ($raw === '') {
                if ($f['required']) {
                    throw new \InvalidArgumentException(t('Renseignez « {nom} ».', ['nom' => $label]));
                }
                $fields[$key] = null;
                continue;
            }
            $type = WorkflowSpec::ficheTypeOfField($f['type']);
            $value = WorkflowValues::coerce(mb_substr($raw, 0, 8000), $type);
            if ($value === null && $f['type'] !== 'text' && $f['type'] !== 'longtext' && $f['type'] !== 'choice') {
                throw new \InvalidArgumentException(match ($f['type']) {
                    'number' => t('« {nom} » attend un nombre.', ['nom' => $label]),
                    'date'   => t('« {nom} » attend une date.', ['nom' => $label]),
                    'email'  => t('« {nom} » attend une adresse email.', ['nom' => $label]),
                    default  => t('« {nom} » attend « oui » ou « non ».', ['nom' => $label]),
                });
            }
            if ($f['type'] === 'choice' && !in_array($raw, $f['options'], true)) {
                throw new \InvalidArgumentException(t('Choisissez une des options de « {nom} ».', ['nom' => $label]));
            }
            $fields[$key] = $f['type'] === 'choice' ? $raw : $value;
            $public[$key] = $fields[$key];
        }
        return ['fields' => $fields, 'files' => $outFiles, 'public' => $public];
    }

    /**
     * Message de lancement. Pure.
     *
     * Les messages que le moteur écrit AU NOM de la personne (celui-ci et
     * ceux de stepMessage) sont en TEXTE SIMPLE : le chat affiche un message
     * utilisateur tel qu'on l'aurait tapé, sans interpréter le Markdown — des
     * astérisques s'y liraient en clair. Les messages de MIA (rôle assistant :
     * condition, validation, livrable…) en portent, eux.
     */
    public static function launchMessage(array $workflow, array $def, array $inputs, string $trigger, ?array $event): string
    {
        $head = match ($trigger) {
            'schedule' => t('Exécution programmée'),
            'chat'     => t('Lancé depuis une conversation'),
            'email'    => t('Lancé par un email reçu'),
            'file'     => t('Lancé par un nouveau fichier'),
            'webhook'  => t('Lancé par un appel de son adresse'),
            'workflow' => t('Lancé à la fin d’un autre workflow'),
            default    => t('Lancement'),
        };
        $out = '▶ ' . $workflow['name'] . " — {$head}";
        if (trim((string)$def['objective']) !== '') {
            $out .= "\n\n" . trim((string)$def['objective']);
        }
        $lines = [];
        foreach ($def['start']['fields'] as $f) {
            if ($f['type'] === 'file') {
                continue;
            }
            $v = $inputs['fields'][$f['key']] ?? null;
            $lines[] = '- ' . ($f['label'] ?: $f['key']) . ' : ' . WorkflowValues::display($v, WorkflowSpec::ficheTypeOfField($f['type']));
        }
        if ($lines !== []) {
            $out .= "\n\n" . implode("\n", $lines);
        }
        if ($event !== null && trim((string)($event['headline'] ?? '')) !== '') {
            $out .= "\n\n" . str_replace("\n", ' ', mb_substr((string)$event['headline'], 0, 300));
        }
        return $out;
    }

    /**
     * Message d'une étape, d'une action ou d'une création — en texte simple,
     * comme le lancement. C'est aussi la consigne que reçoit le modèle d'une
     * étape (WorkflowPrompt::isolatedHistory). Pure.
     */
    public static function stepMessage(string $kicker, string $title, string $instruction, array $pieces, string $remark = '', string $iterLabel = ''): string
    {
        $text = $kicker . ' — ' . $title . ($iterLabel !== '' ? ' · ' . $iterLabel : '')
            . "\n\n" . WorkflowSpec::renderMentions($instruction, $pieces);
        if ($remark !== '') {
            $text .= "\n\n↩ " . t('Remarque de la relecture : {remarque}', ['remarque' => $remark]);
        }
        return $text;
    }

    // ==================================================================
    // Enchaînement
    // ==================================================================

    /** Passe à la brique qui suit `$fromKey` (clé de passage, « #n » compris). Rend les jobs enfilés. */
    private static function advanceFrom(int $runId, string $fromKey, string $outcome): array
    {
        $run = WorkflowRun::find($runId);
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return [];
        }
        $def = $run['definition'];
        [$from, $iter] = WorkflowLoop::splitIter($fromKey);
        if (str_ends_with($from, self::REVIEW_SUFFIX)) {
            $from = substr($from, 0, -strlen(self::REVIEW_SUFFIX));
        }
        $next = WorkflowSpec::next($def, $from, $outcome);
        if (str_starts_with($next, WorkflowSpec::LOOP_END)) {
            return self::iterationDone($run, substr($next, strlen(WorkflowSpec::LOOP_END)), (int)$iter);
        }
        if (str_starts_with($next, WorkflowSpec::LOOP_SKIP)) {
            // Une condition de la boucle écarte l'élément : fin de son tour.
            return self::iterationDone($run, substr($next, strlen(WorkflowSpec::LOOP_SKIP)), (int)$iter, 's');
        }
        if ($next === WorkflowSpec::STOP && $iter !== null) {
            self::stopByBranch($run, WorkflowLoop::iterKey($from, $iter));
            return [];
        }
        $inBody = (WorkflowSpec::locate($def, $next)['lane'] ?? '') === 'body';
        return self::enter($run, $next, ['from' => $from, 'iter' => $inBody ? $iter : null]);
    }

    /**
     * Entre dans une brique (clé simple ; `iter` = le tour, pour une brique
     * de boucle).
     */
    private static function enter(array $run, string $key, array $opts = []): array
    {
        $iter = isset($opts['iter']) && (int)$opts['iter'] > 0 ? (int)$opts['iter'] : null;
        $passKey = WorkflowLoop::iterKey($key, $iter);
        try {
            if ($key === WorkflowSpec::STOP) {
                self::stopByBranch($run, (string)($opts['from'] ?? ''));
                return [];
            }
            if ($key === WorkflowSpec::END_KEY) {
                return self::deliver($run);
            }
            $node = WorkflowSpec::node($run['definition'], $key);
            if ($node === null) {
                self::fail($run, $passKey, ['kind' => 'generic', 'message' => t('Cette brique n’existe plus dans la version de l’exécution.')]);
                return [];
            }
            WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $passKey);
            $run['status'] = WorkflowRun::RUNNING;
            $run['current_key'] = $passKey;

            return match ($node['type']) {
                WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION, WorkflowSpec::TYPE_CREATE => self::startWork($run, $node, $opts + ['iter' => $iter]),
                WorkflowSpec::TYPE_CONDITION => self::startCondition($run, $node, $opts + ['iter' => $iter]),
                WorkflowSpec::TYPE_ROUTE     => self::startRoute($run, $node),
                WorkflowSpec::TYPE_LOOP      => self::startLoop($run, $node),
                WorkflowSpec::TYPE_GATE      => self::openGate($run, $passKey) ? [] : [],
                WorkflowSpec::TYPE_WAIT      => self::startWait($run, $node, $iter),
                WorkflowSpec::TYPE_NOTIFY    => self::doNotify($run, $node, $iter),
                WorkflowSpec::TYPE_STOP      => self::doStop($run, $node),
            };
        } catch (\Throwable $e) {
            error_log('[Workflows] exécution #' . $run['id'] . ', brique ' . $passKey . ' : ' . $e->getMessage());
            self::fail($run, $passKey, ['kind' => 'generic', 'message' => t('Une erreur interne a arrêté cette brique.')]);
            return [];
        }
    }

    /**
     * Le dossier tel que le voit une brique : au tour n d'une boucle, les
     * pièces du corps (et l'élément en cours) désignent celles de ce tour ;
     * hors boucle, les pièces de tour ne se voient pas.
     */
    private static function artifactsFor(array $run, ?array $loop, ?int $iter): array
    {
        // Les éléments figés d'une boucle (`~items`, des centaines de lignes)
        // ne sont pas une pièce : ils ne sont relus qu'à part (loopItems).
        $all = WorkflowRun::artifacts($run['id'], false);
        if ($loop !== null && $iter !== null) {
            return WorkflowLoop::view($all, WorkflowLoop::bodyPieceKeys($loop), $iter);
        }
        return WorkflowLoop::view($all, [], 0);
    }

    /**
     * Étape ou action : contexte revérifié AU MOMENT T, consigne écrite dans
     * la conversation, job `chat` enfilé. Une création part par startCreate —
     * c'est par ici que passent aussi les renvois d'une relecture et les
     * nouveaux essais.
     *
     * @param array $opts remark, retry_reason, iter, auto (essais automatiques déjà faits)
     */
    private static function startWork(array $run, array $node, array $opts = []): array
    {
        if ($node['type'] === WorkflowSpec::TYPE_CREATE) {
            return self::startCreate($run, $node, $opts);
        }
        $iter = isset($opts['iter']) && (int)$opts['iter'] > 0 ? (int)$opts['iter'] : null;
        $passKey = WorkflowLoop::iterKey($node['key'], $iter);
        $user = User::findById($run['user_id']);
        if (!$user || empty($user['is_active'])) {
            self::fail($run, $passKey, ['kind' => 'generic', 'message' => t('Le compte qui a lancé le workflow n’est plus actif.')]);
            return [];
        }
        // Coupés par l'administrateur (ou rôle retiré) : l'exécution attend,
        // reprenable dès que les workflows sont de nouveau ouverts.
        if (!Workflow::canUse($user)) {
            self::pause($run, $passKey, ['kind' => 'disabled', 'message' => self::asUser($user, static fn(): string => t('Les workflows ne vous sont plus ouverts pour le moment.'))]);
            return [];
        }
        $scope = UserGroup::membershipScopeForUser((int)$user['id']);
        $profile = null;
        $profileId = $node['type'] === WorkflowSpec::TYPE_STEP ? $node['profile_id'] : null;
        if ($profileId !== null) {
            foreach (Profile::accessibleByUser((int)$user['id'], (string)$user['role'], $scope['active_group_ids'], $scope['has_assignments']) as $p) {
                if ((int)$p['id'] === $profileId) {
                    $profile = $p;
                }
            }
            if ($profile === null) {
                self::fail($run, $passKey, ['kind' => 'generic', 'message' => t('L’assistant de cette étape n’est plus accessible : choisissez-en un autre dans l’éditeur, publiez, puis reprenez avec la dernière version.')]);
                return [];
            }
        }
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            self::pause($run, $passKey, CreditQuota::refusal($quota, true));
            return [];
        }
        $llm = (new ChatController())->scheduledTurnLlm($user, $profile, (string)($node['llm'] ?? ''));
        if ($llm === '') {
            self::fail($run, $passKey, ['kind' => 'model', 'message' => t('Aucun modèle n’est disponible pour cette étape.')]);
            return [];
        }

        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $loop = $iter !== null ? WorkflowSpec::loopOf($def, $node['key']) : null;
        $artifacts = self::artifactsFor($run, $loop, $iter);
        $workflow = Workflow::findById($run['workflow_id']);
        $wfName = (string)($workflow['name'] ?? '');
        $convId = (int)$run['conversation_id'];

        $remark = (string)($opts['remark'] ?? '');
        $previous = null;
        $loopNote = $loop !== null ? self::loopNote($run, $loop, (int)$iter, $user) : '';
        if ($node['type'] === WorkflowSpec::TYPE_STEP) {
            $outKey = $node['output']['key'];
            if ($remark !== '' && isset($artifacts[$outKey])) {
                $previous = ['text' => ArtifactContract::render($pieces[$outKey] ?? ['form' => $artifacts[$outKey]['form']], $artifacts[$outKey]['content'])];
            } elseif ($loop !== null && $loop['mode'] === WorkflowSpec::LOOP_UNTIL && $iter > 1) {
                // Boucle « jusqu'à » : l'étape reprend SA version du tour d'avant.
                $prev = WorkflowRun::artifacts($run['id'])[WorkflowLoop::iterKey($outKey, $iter - 1)] ?? null;
                if ($prev !== null) {
                    $previous = ['text' => ArtifactContract::render($pieces[$outKey], $prev['content'])];
                }
            }
        }
        $prompt = WorkflowPrompt::note($wfName, $def, $node, $artifacts, $remark, (string)($opts['retry_reason'] ?? ''), $previous, $loopNote);
        // Filtres de la brique, remplis avec les valeurs du dossier (un
        // expéditeur cité, l'élément en cours d'une boucle) : imposés aux
        // outils du tour (SearchScope), et annoncés au modèle.
        $filters = self::filtersFor($node, $pieces, $artifacts);
        $prompt .= SearchScope::fromFilters($filters)->promptNote();

        // Le message de l'étape : ce que la personne lira dans la conversation.
        $iterLabel = $loop !== null ? self::iterLabel($run, $loop, (int)$iter, $user) : '';
        $content = self::asUser($user, static fn(): string => self::stepMessage(
            $node['type'] === WorkflowSpec::TYPE_ACTION ? t('Action') : t('Étape'),
            (string)$node['title'], (string)$node['instruction'], $pieces, $remark, $iterLabel
        ));
        $files = [];
        foreach (WorkflowSpec::readsOf($def, $node) as $k) {
            // Une image créée plus tôt : jointe telle quelle, le modèle la voit.
            if (($pieces[$k]['form'] ?? '') === 'image' && isset($artifacts[$k])) {
                $path = WorkflowCreations::imagePath('image', $artifacts[$k]['content']);
                if ($path !== null) {
                    $files[] = ['name' => basename($path), 'type' => (string)($artifacts[$k]['content']['mime'] ?? 'image/png'), 'path' => $path, 'extracted' => ''];
                }
                continue;
            }
            // L'élément en cours d'une boucle qui porte un fichier (pièce jointe,
            // fichier d'un dossier) : joint au message, comme un fichier reçu.
            if (!empty($pieces[$k]['item']) && isset($artifacts[$k]) && is_array($artifacts[$k]['content']['file'] ?? null)) {
                $c = $artifacts[$k]['content']['file'];
                if ((string)($c['path'] ?? '') !== '' && WorkflowPrompt::readableUpload((string)$c['path'])) {
                    $native = str_starts_with((string)$c['type'], 'image/') || $c['type'] === 'application/pdf';
                    $files[] = ['name' => (string)$c['name'], 'type' => (string)$c['type'], 'path' => (string)$c['path'], 'extracted' => $native ? '' : (string)($c['text'] ?? '')];
                }
                continue;
            }
            if (($pieces[$k]['form'] ?? '') === 'file' && isset($artifacts[$k])) {
                $c = $artifacts[$k]['content'];
                if ((string)($c['path'] ?? '') === '') {
                    continue; // fichier lu ailleurs (Drive) : son texte est dans le prompt
                }
                $native = str_starts_with((string)$c['type'], 'image/') || $c['type'] === 'application/pdf';
                $files[] = ['name' => $c['name'], 'type' => $c['type'], 'path' => $c['path'], 'extracted' => $native ? '' : (string)$c['text']];
            }
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            Conversation::syncForTask($convId, $profileId, $llm);
            if ($node['type'] === WorkflowSpec::TYPE_STEP) {
                ConversationKnowledgeBase::setForConversation($convId, $node['kb_ids']);
            } else {
                ConversationKnowledgeBase::setForConversation($convId, []);
            }
            $userMsg = Message::create($convId, 'user', $content, $files);
            Conversation::touch($convId);

            $payload = [
                'content'         => $content,
                'llm'             => $llm,
                'connectors_auto' => false,
                'voice_mode'      => false,
                'voice_length'    => '',
                'tz'              => (string)$run['timezone'],
                'needs_title'     => false,
                'user_msg_id'     => (int)$userMsg['id'],
            ];
            $sources = $node['type'] === WorkflowSpec::TYPE_STEP ? $node['sources'] : [];
            if ($node['type'] === WorkflowSpec::TYPE_ACTION) {
                $target = WorkflowSpec::ACTION_TARGETS[$node['target']] ?? '';
                if ($target !== '' && !str_starts_with($target, 'tool:')) {
                    $sources = [$target];
                }
            }
            foreach (Scheduler::ACCESS_FLAGS as $source => $flag) {
                $payload[$flag] = in_array($source, $sources, true);
            }
            $payload['web_access'] = $payload['web_access'] && ConnectorRegistry::isEnabled(ConnectorRegistry::WEB);
            $toolIds = $node['type'] === WorkflowSpec::TYPE_STEP ? $node['tool_ids'] : ($node['tool_id'] !== null ? [$node['tool_id']] : []);
            $payload['workflow'] = [
                'run_id'         => $run['id'],
                'node_key'       => $passKey,
                'prompt'         => $prompt,
                'write'          => $node['write'],
                'auto_send_mail' => !empty($node['auto_send_mail']),
                'tool_ids'       => $toolIds,
                'filters'        => $filters,
                'scheduled'      => !in_array($run['trigger_kind'], ['manual', 'chat'], true),
                // Pièces que l'étape peut insérer par repère dans ce qu'elle
                // écrit (clé réelle au dossier), images citées par la consigne.
                'inserts'        => WorkflowInserts::forPass($def, $node, $artifacts),
            ];

            $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_RUNNING, array_filter([
                'user_msg_id'  => (int)$userMsg['id'],
                'remark'       => $remark !== '' ? $remark : null,
                'retry_reason' => $opts['retry_reason'] ?? null,
                'llm'          => $llm,
                'iter'         => $iter,
                'auto'         => isset($opts['auto']) && (int)$opts['auto'] > 0 ? (int)$opts['auto'] : null,
            ], static fn($v) => $v !== null));
            $jobId = JobQueue::enqueue('chat', (int)$user['id'], $convId, $payload, mb_substr($wfName . ' — ' . $node['title'], 0, 60));
            WorkflowRun::updateStep($stepId, ['job_id' => $jobId, 'message_id' => (int)$userMsg['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return [$jobId];
    }

    /**
     * Création : outil revérifié AU MOMENT T (droits du groupe, arrêt, dernier
     * test), demande écrite dans la conversation, puis le job qu'aurait
     * enfilé le menu « + » du chat (`image`, `html`, `map`) — WorkflowCreations.
     * Renvoyée par une relecture avec une remarque (ou au tour suivant d'une
     * boucle « jusqu'à »), elle repart de SA version précédente.
     *
     * @param array $opts remark, iter, auto (essais automatiques déjà faits)
     */
    private static function startCreate(array $run, array $node, array $opts = []): array
    {
        $iter = isset($opts['iter']) && (int)$opts['iter'] > 0 ? (int)$opts['iter'] : null;
        $passKey = WorkflowLoop::iterKey($node['key'], $iter);
        $user = User::findById($run['user_id']);
        if (!$user || empty($user['is_active'])) {
            self::fail($run, $passKey, ['kind' => 'generic', 'message' => t('Le compte qui a lancé le workflow n’est plus actif.')]);
            return [];
        }
        if (!Workflow::canUse($user)) {
            self::pause($run, $passKey, ['kind' => 'disabled', 'message' => self::asUser($user, static fn(): string => t('Les workflows ne vous sont plus ouverts pour le moment.'))]);
            return [];
        }
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            self::pause($run, $passKey, CreditQuota::refusal($quota, true));
            return [];
        }
        [$tool, $why] = self::asUser($user, static fn(): array => WorkflowCreations::resolveTool($user, $node));
        if ($tool === null) {
            self::fail($run, $passKey, ['kind' => 'generic', 'message' => $why]);
            return [];
        }

        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $loop = $iter !== null ? WorkflowSpec::loopOf($def, $node['key']) : null;
        $artifacts = self::artifactsFor($run, $loop, $iter);
        $remark = (string)($opts['remark'] ?? '');
        $outKey = $node['output']['key'];
        $ownBase = null;
        if ($remark !== '' && isset($artifacts[$outKey])) {
            $ownBase = (string)$artifacts[$outKey]['key'];
        } elseif ($loop !== null && $loop['mode'] === WorkflowSpec::LOOP_UNTIL && $iter > 1
            && isset(WorkflowRun::artifacts($run['id'])[WorkflowLoop::iterKey($outKey, $iter - 1)])) {
            $ownBase = WorkflowLoop::iterKey($outKey, $iter - 1);
        }
        $request = WorkflowCreations::request($def, $node, $artifacts, $remark, $ownBase);

        $iterLabel = $loop !== null ? self::iterLabel($run, $loop, (int)$iter, $user) : '';
        $content = self::asUser($user, static fn(): string => self::stepMessage(
            t('Création'), (string)$node['title'], (string)$node['instruction'], $pieces, $remark, $iterLabel
        ));
        $convId = (int)$run['conversation_id'];
        $workflow = Workflow::findById($run['workflow_id']);
        $wfName = (string)($workflow['name'] ?? '');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Une création se fait sans assistant : la conversation n'en porte plus.
            Conversation::syncForTask($convId, null, '');
            ConversationKnowledgeBase::setForConversation($convId, []);
            $userMsg = Message::create($convId, 'user', $content);
            Conversation::touch($convId);
            $payload = [
                'prompt'      => $request['prompt'],
                'tool_id'     => (int)$tool['id'],
                'user_msg_id' => (int)$userMsg['id'],
                'attachments' => [],
                'tz'          => (string)$run['timezone'],
                // Le contexte, les points de départ : relus dans le dossier par
                // le job (WorkflowCreations), jamais dans la conversation.
                'workflow'    => [
                    'run_id'   => $run['id'],
                    'node_key' => $passKey,
                    'context'  => $request['context'],
                    'sources'  => $request['sources'],
                    'base'     => $request['base'],
                    'format'   => (string)($node['options']['format'] ?? ''),
                ],
            ];
            if ($node['kind'] === 'page') {
                $payload['image_style'] = (string)($node['options']['style'] ?? '');
            }
            $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_RUNNING, array_filter([
                'user_msg_id' => (int)$userMsg['id'],
                'remark'      => $remark !== '' ? $remark : null,
                'tool'        => (string)$tool['name'],
                'iter'        => $iter,
                'auto'        => isset($opts['auto']) && (int)$opts['auto'] > 0 ? (int)$opts['auto'] : null,
            ], static fn($v) => $v !== null));
            $jobId = JobQueue::enqueue(WorkflowCreations::jobType((string)$node['kind']), (int)$user['id'], $convId, $payload, mb_substr($wfName . ' — ' . $node['title'], 0, 60));
            WorkflowRun::updateStep($stepId, ['job_id' => $jobId, 'message_id' => (int)$userMsg['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return [$jobId];
    }

    /**
     * Les filtres qui s'appliquent à une étape ou une action : ceux des
     * connecteurs qu'elle consulte (étape) ou de l'outil où elle agit
     * (action), remplis avec les valeurs du dossier.
     */
    public static function filtersFor(array $node, array $pieces, array $artifacts): array
    {
        if ($node['type'] === WorkflowSpec::TYPE_ACTION) {
            $target = WorkflowSpec::ACTION_TARGETS[$node['target']] ?? '';
            $connectors = $target !== '' && !str_starts_with($target, 'tool:') ? [$target] : [];
            $acts = true;
        } else {
            $connectors = (array)($node['sources'] ?? []);
            $acts = ($node['write'] ?? WorkflowSpec::WRITE_NONE) !== WorkflowSpec::WRITE_NONE;
        }
        $filters = WorkflowFilters::forConnectors((array)($node['filters'] ?? []), $connectors, $acts);
        return WorkflowFilters::fill($filters, $pieces, $artifacts);
    }

    /**
     * Condition : règle calculée sur place, ou question à MIA dans un job.
     * Dans une boucle (`iter`), elle lit le dossier du tour et décide de la
     * suite du tour : continuer, passer à l'élément suivant, arrêter.
     */
    private static function startCondition(array $run, array $node, array $opts = []): array
    {
        $def = $run['definition'];
        $iter = isset($opts['iter']) && (int)$opts['iter'] > 0 ? (int)$opts['iter'] : null;
        $passKey = WorkflowLoop::iterKey($node['key'], $iter);
        $loop = $iter !== null ? WorkflowSpec::loopOf($def, $node['key']) : null;
        if ($node['mode'] === 'rule') {
            $pieces = WorkflowSpec::pieces($def);
            $contents = array_map(static fn(array $a): array => $a['content'], self::artifactsFor($run, $loop, $iter));
            $eval = WorkflowValues::evaluate($node['rule'], $pieces, $contents);
            $outcome = $eval['result'] ? 'yes' : 'no';
            $user = User::findById($run['user_id']);
            $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_RUNNING, $iter !== null ? ['iter' => $iter] : []);
            WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, $outcome, ['lines' => $eval['lines'], 'mode' => 'rule'] + ($iter !== null ? ['iter' => $iter] : []));
            $iterLabel = $loop !== null && $user ? self::iterLabel($run, $loop, $iter, $user) : '';
            self::post($run, self::asUser($user ?: null, static fn(): string => self::conditionMessage($node, $outcome, '', $eval['lines'], $iterLabel)));
            return self::advanceFrom($run['id'], $passKey, $outcome);
        }
        $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_RUNNING, array_filter([
            'mode' => 'question', 'auto' => isset($opts['auto']) && (int)$opts['auto'] > 0 ? (int)$opts['auto'] : null, 'iter' => $iter,
        ], static fn($v) => $v !== null));
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'condition', 'run_id' => $run['id'], 'node_key' => $passKey, 'step_id' => $stepId,
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Condition') . ' — ' . $node['title'], 0, 60));
        WorkflowRun::updateStep($stepId, ['job_id' => $jobId]);
        return [$jobId];
    }

    /**
     * Job `workflow` d'une condition par question (WorkflowJob) : MIA tranche,
     * la raison est écrite dans la conversation, le rail continue.
     *
     * @return array résultat du job
     */
    public static function runConditionJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        $stepId = (int)($payload['step_id'] ?? 0);
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $passKey = (string)($payload['node_key'] ?? '');
        [$baseKey, $iter] = WorkflowLoop::splitIter($passKey);
        $node = WorkflowSpec::node($run['definition'], $baseKey);
        if ($node === null) {
            throw new \RuntimeException('condition introuvable');
        }
        $step = self::stepById($run['id'], $stepId);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return ['skipped' => true];
        }
        $user = User::findById($run['user_id']);
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $loop = $iter !== null ? WorkflowSpec::loopOf($def, $baseKey) : null;
        $artifacts = self::artifactsFor($run, $loop, $iter);
        $evidence = self::evidence(WorkflowSpec::readsOf($def, $node), $pieces, $artifacts);
        $quota = CreditQuota::status($user ?: []);
        if ($user && CreditQuota::isExceeded($quota)) {
            WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'quota');
            self::pause($run, $passKey, CreditQuota::refusal($quota, true));
            return ['paused' => true];
        }
        $iterLabel = $loop !== null && $user ? self::iterLabel($run, $loop, (int)$iter, $user) : '';
        $verdict = WorkflowAi::judge(WorkflowSpec::renderMentions($node['question'], $pieces), (string)$def['objective'], $evidence, [
            'user_id' => $run['user_id'], 'conversation_id' => $run['conversation_id'],
        ]);
        if ($verdict['error'] !== null) {
            $error = ['kind' => (string)($verdict['error']['kind'] ?? 'model'), 'title' => (string)($verdict['error']['title'] ?? ''), 'message' => (string)($verdict['error']['message'] ?? '')];
            foreach (self::onFailure($run, $node, $step, $error, 'error', false) as $jobId) {
                JobQueue::dispatch($jobId, false);
            }
            return ['failed' => true];
        }
        $answer = $verdict['answer'];
        if ($answer === 'unsure' && $node['doubt'] === WorkflowSpec::DOUBT_ASK) {
            if (!WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_WAITING, 'unsure', ['reason' => $verdict['reason'], 'mode' => 'question'])) {
                return ['skipped' => true];
            }
            self::post($run, self::asUser($user ?: null, static fn(): string => self::conditionMessage($node, 'unsure', $verdict['reason'], [], $iterLabel)));
            self::openDoubt($run, $node, $stepId, $verdict['reason'], $passKey);
            return ['waiting' => true];
        }
        $outcome = $answer === 'yes' ? 'yes' : 'no';
        if (!WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, $outcome, ['reason' => $verdict['reason'], 'mode' => 'question', 'unsure' => $answer === 'unsure'] + ($iter !== null ? ['iter' => $iter] : []))) {
            return ['skipped' => true];
        }
        self::post($run, self::asUser($user ?: null, static fn(): string => self::conditionMessage($node, $outcome, $verdict['reason'], [], $iterLabel)));
        foreach (self::advanceFrom($run['id'], $passKey, $outcome) as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return ['outcome' => $outcome];
    }

    /** Pièces données à MIA pour trancher : des DONNÉES, bornées. */
    private static function evidence(array $keys, array $pieces, array $artifacts): string
    {
        $evidence = '';
        foreach ($keys as $k) {
            if (isset($artifacts[$k], $pieces[$k])) {
                $evidence .= "<<< " . $pieces[$k]['name'] . "\n" . ArtifactContract::render($pieces[$k], $artifacts[$k]['content'], 12000) . "\n>>>\n";
            }
        }
        return $evidence;
    }

    private static function conditionMessage(array $node, string $outcome, string $reason, array $lines, string $iterLabel = ''): string
    {
        $verdict = match ($outcome) {
            'yes'   => t('oui'),
            'no'    => t('non'),
            default => t('à trancher par vous'),
        };
        $out = '◇ **' . t('Condition — {titre}', ['titre' => $node['title']]) . '**' . ($iterLabel !== '' ? ' · ' . $iterLabel : '') . ' : ' . $verdict;
        if ($reason !== '') {
            $out .= "\n\n> " . $reason;
        }
        if ($lines !== []) {
            $out .= "\n\n" . implode("\n", array_map(static fn($l) => '- ' . $l, $lines));
        }
        return $out;
    }

    // ==================================================================
    // Décision de MIA (aiguillage)
    // ==================================================================

    /** Décision : MIA lit les pièces, suit les critères et choisit une voie — dans un job. */
    private static function startRoute(array $run, array $node, array $opts = []): array
    {
        $stepId = WorkflowRun::insertStep($run['id'], $node['key'], WorkflowRun::STEP_RUNNING, array_filter([
            'mode' => 'route', 'auto' => isset($opts['auto']) && (int)$opts['auto'] > 0 ? (int)$opts['auto'] : null,
        ], static fn($v) => $v !== null));
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'route', 'run_id' => $run['id'], 'node_key' => $node['key'], 'step_id' => $stepId,
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Décision') . ' — ' . $node['title'], 0, 60));
        WorkflowRun::updateStep($stepId, ['job_id' => $jobId]);
        return [$jobId];
    }

    /**
     * Job `workflow` d'une décision (WorkflowJob) : MIA choisit la voie, sa
     * raison est écrite dans la conversation, le rail continue dans la voie.
     * Sans choix possible : la voie de repli, ou la personne tranche.
     *
     * @return array résultat du job
     */
    public static function runRouteJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        $stepId = (int)($payload['step_id'] ?? 0);
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $node = WorkflowSpec::node($run['definition'], (string)($payload['node_key'] ?? ''));
        if ($node === null || $node['type'] !== WorkflowSpec::TYPE_ROUTE) {
            throw new \RuntimeException('décision introuvable');
        }
        $step = self::stepById($run['id'], $stepId);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return ['skipped' => true];
        }
        $user = User::findById($run['user_id']);
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = self::artifactsFor($run, null, null);
        $quota = CreditQuota::status($user ?: []);
        if ($user && CreditQuota::isExceeded($quota)) {
            WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'quota');
            self::pause($run, $node['key'], CreditQuota::refusal($quota, true));
            return ['paused' => true];
        }
        $routes = array_map(static fn(array $r): array => [
            'key' => $r['key'], 'label' => $r['label'], 'when' => WorkflowSpec::renderMentions((string)$r['when'], $pieces),
        ], $node['routes']);
        $verdict = WorkflowAi::choose(
            WorkflowSpec::renderMentions((string)$node['instruction'], $pieces),
            $routes,
            (string)$def['objective'],
            self::evidence(WorkflowSpec::readsOf($def, $node), $pieces, $artifacts),
            ['user_id' => $run['user_id'], 'conversation_id' => $run['conversation_id']]
        );
        if ($verdict['error'] !== null) {
            $error = ['kind' => (string)($verdict['error']['kind'] ?? 'model'), 'title' => (string)($verdict['error']['title'] ?? ''), 'message' => (string)($verdict['error']['message'] ?? '')];
            foreach (self::onFailure($run, $node, $step, $error, 'error', false) as $jobId) {
                JobQueue::dispatch($jobId, false);
            }
            return ['failed' => true];
        }
        $chosen = $verdict['route'];
        $detail = ['reason' => $verdict['reason'], 'mode' => 'route', 'suggested' => $verdict['closest']];
        if ($chosen === '') {
            if ($node['fallback'] === WorkflowSpec::FALLBACK_ASK) {
                if (!WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_WAITING, 'unsure', $detail)) {
                    return ['skipped' => true];
                }
                self::post($run, self::asUser($user ?: null, static fn(): string => self::routeMessage($node, '', $verdict['reason'], false)));
                self::openRouteDoubt($run, $node, $stepId, $verdict['reason'], $verdict['closest']);
                return ['waiting' => true];
            }
            $chosen = $node['fallback'];
            $detail['fallback'] = true;
        }
        if (!WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, $chosen, $detail)) {
            return ['skipped' => true];
        }
        self::post($run, self::asUser($user ?: null, static fn(): string => self::routeMessage($node, $chosen, $verdict['reason'], !empty($detail['fallback']))));
        foreach (self::advanceFrom($run['id'], $node['key'], $chosen) as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return ['outcome' => $chosen];
    }

    /** Message de la décision dans la conversation : la voie, et pourquoi. */
    private static function routeMessage(array $node, string $chosen, string $reason, bool $fallback): string
    {
        $out = '⑂ **' . t('Décision — {titre}', ['titre' => $node['title']]) . '** : ' . ($chosen === ''
            ? t('à trancher par vous')
            : '« ' . WorkflowSpec::laneLabel($node, $chosen) . ' »' . ($fallback ? ' (' . t('voie de repli : MIA n’a pas su choisir') . ')' : ''));
        if ($reason !== '') {
            $out .= "\n\n> " . $reason;
        }
        return $out;
    }

    /**
     * MIA ne sait pas choisir et la décision demande à la personne : une
     * validation dont la décision tient lieu de choix. La carte du chat
     * prend la voie que MIA juge la plus proche, ou arrête ; la page de
     * l'exécution propose TOUTES les voies.
     */
    private static function openRouteDoubt(array $run, array $node, int $stepId, string $reason, string $closest): void
    {
        $user = User::findById($run['user_id']);
        $convId = (int)$run['conversation_id'];
        $suggested = $closest !== '' && isset(WorkflowSpec::branchesOf($node)[$closest]) ? $closest : (string)($node['routes'][0]['key'] ?? '');
        $preview = self::asUser($user ?: null, static fn(): array => [
            'action'       => t('Choisir la voie du workflow'),
            'fichier'      => $node['title'],
            'apercu'       => $reason,
            'apercu_titre' => t('Ce que MIA a vu'),
            'impacts'      => [
                t('Approuver : prendre la voie « {voie} », celle que MIA juge la plus proche.', ['voie' => WorkflowSpec::laneLabel($node, $suggested)]),
                t('Annuler : arrêter le workflow.'),
                t('Une autre voie ? Choisissez-la depuis la page de l’exécution (Ajuster).'),
            ],
            'icone'        => '/assets/img/workflow.svg',
            'ajuster'      => '/workflows/runs/' . $run['id'],
        ]);
        WorkflowRun::updateStep($stepId, ['detail' => ['reason' => $reason, 'mode' => 'route', 'suggested' => $suggested]]);
        $msg = Message::create($convId, 'assistant', '⏸ ' . self::asUser($user ?: null, static fn(): string => t('MIA hésite : à vous de choisir la voie de « {titre} ».', ['titre' => $node['title']])));
        $actionId = DrivePendingAction::create((int)$run['user_id'], $convId, self::GATE_TOOL, [
            'run_id' => $run['id'], 'node_key' => $node['key'], 'doubt' => true,
        ], $preview);
        DrivePendingAction::extend($actionId, (int)$run['user_id'], WorkflowSpec::GATE_DAYS_DEFAULT * 24 * 60);
        DrivePendingAction::attachToMessage([$actionId], (int)$msg['id'], (int)$run['user_id']);
        WorkflowRun::updateStep($stepId, ['pending_action_id' => $actionId]);
        WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $node['key']);
        WorkflowNotifier::notify($run, WorkflowNotifier::GATE, $node['title']);
    }

    /**
     * La personne a tranché une décision : `route:<clé>` (page de
     * l'exécution), `approved` (la voie suggérée), `stopped` (arrêt).
     */
    private static function routeResolved(array $run, array $step, array $node, string $outcome): array
    {
        $lanes = WorkflowSpec::branchesOf($node);
        if ($outcome === 'stopped') {
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, 'stopped', $step['detail'] + ['decided_by' => 'person'])) {
                return [];
            }
            WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $step['node_key']);
            $user = User::findById($run['user_id']);
            self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté à la décision « {titre} ».', ['titre' => $node['title']])));
            return [];
        }
        $chosen = str_starts_with($outcome, 'route:') ? substr($outcome, 6) : (string)($step['detail']['suggested'] ?? '');
        if (!isset($lanes[$chosen])) {
            $chosen = (string)array_key_first($lanes);
        }
        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, $chosen, $step['detail'] + ['decided_by' => 'person'])) {
            return [];
        }
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $step['node_key']);
        return self::advanceFrom($run['id'], $step['node_key'], $chosen);
    }

    // ==================================================================
    // Boucles
    // ==================================================================

    /**
     * Entrée dans une boucle. « Jusqu'à » : le premier tour. « Pour chaque » :
     * ses éléments — lus sur place (une liste, un tableau du dossier) ou dans
     * un job (un fichier, un connecteur, MIA) —, filtrés, figés au dossier,
     * puis le premier tour (ou la relecture de la liste, si demandée).
     */
    private static function startLoop(array $run, array $loop): array
    {
        $def = $run['definition'];
        $user = User::findById($run['user_id']);
        $stepId = WorkflowRun::insertStep($run['id'], $loop['key'], WorkflowRun::STEP_RUNNING, ['mode' => $loop['mode'], 'i' => 0]);
        if ($loop['mode'] !== WorkflowSpec::LOOP_EACH) {
            self::post($run, self::asUser($user ?: null, static fn(): string => '↻ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : '
                . I18n::plural((int)$loop['max'], 'un tour au plus, jusqu’à ce que la condition soit remplie.', '{n} tours au plus, jusqu’à ce que la condition soit remplie.')));
            return self::startIteration($run, $loop, 1);
        }
        $pieces = WorkflowSpec::pieces($def);
        $source = (array)($loop['source'] ?? []);
        $kind = (string)($source['kind'] ?? '');
        $artifacts = self::artifactsFor($run, null, null);
        $over = $pieces[$loop['over']] ?? null;

        // Boucle d'avant les découpeurs : la liste, le tableau, le champ liste.
        if ($kind === '') {
            $art = $artifacts[$loop['over']] ?? null;
            $res = $over !== null && $art !== null
                ? WorkflowLoop::items($over, $art['content'], (string)$loop['over_field'], WorkflowSpec::LOOP_EACH_MAX)
                : ['items' => [], 'total' => 0];
            $elements = array_map(static fn(string $s): array => ['label' => WorkflowLoop::label($s), 'fields' => [], 'text' => $s], $res['items']);
            return self::prepareElements($run, $loop, $stepId, $elements, [], [], $res['total']);
        }
        if (!LoopSources::needsJob($source, $over)) {
            try {
                $split = LoopRunner::split($def, $loop, $artifacts, $user ?: ['id' => $run['user_id']], self::billing($run));
            } catch (\RuntimeException $e) {
                return self::splitFailed($run, $loop, $stepId, self::loopError($e));
            }
            return self::prepareElements($run, $loop, $stepId, $split['elements'], $split['header'], $split['notes'], count($split['elements']));
        }
        // Un fichier à lire, un connecteur à interroger, MIA à consulter : dans
        // un job, rattaché à la brique de la boucle (une panne s'y reprend).
        self::post($run, self::asUser($user ?: null, static fn(): string => '↻ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : '
            . t('lecture des éléments — {source}.', ['source' => LoopSources::describe($loop, $over)])));
        WorkflowRun::updateStep($stepId, ['detail' => ['mode' => $loop['mode'], 'i' => 0, 'phase' => 'split']]);
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'loop_split', 'run_id' => $run['id'], 'loop_key' => $loop['key'], 'step_id' => $stepId,
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Boucle') . ' — ' . $loop['title'], 0, 60));
        WorkflowRun::updateStep($stepId, ['job_id' => $jobId]);
        return [$jobId];
    }

    /** Facturation d'un appel fait pour une exécution : sa personne, sa conversation. */
    private static function billing(array $run): array
    {
        return ['user_id' => $run['user_id'], 'conversation_id' => $run['conversation_id']];
    }

    /** Une erreur de découpage, présentable. */
    private static function loopError(\Throwable $e): array
    {
        if ($e instanceof LoopSourceException) {
            return ['kind' => (string)($e->error['kind'] ?? 'model'), 'title' => (string)($e->error['title'] ?? ''), 'message' => (string)($e->error['message'] ?? '')];
        }
        return ['kind' => 'generic', 'message' => $e->getMessage()];
    }

    /**
     * La source n'a pas pu être découpée : l'exécution s'arrête sur la boucle,
     * reprenable (la reprise relit la source) — rien n'a été dépensé en tours.
     */
    private static function splitFailed(array $run, array $loop, int $stepId, array $error): array
    {
        WorkflowRun::updateStep($stepId, ['job_id' => null, 'detail' => ['mode' => $loop['mode'], 'i' => 0, 'phase' => 'split']]);
        $user = User::findById($run['user_id']);
        self::fail($run, $loop['key'], [
            'kind' => (string)($error['kind'] ?? 'generic'), 'title' => (string)($error['title'] ?? ''),
            'message' => self::asUser($user ?: null, static fn(): string => t('« {titre} » n’a pas pu lire ses éléments : {cause}', [
                'titre' => $loop['title'], 'cause' => (string)($error['message'] ?? '') !== '' ? (string)$error['message'] : t('cause inconnue'),
            ])),
        ]);
        return [];
    }

    /**
     * Job `workflow` du découpage d'une boucle (fichier, connecteur, MIA) :
     * les éléments sont lus, filtrés et figés, puis le premier tour part.
     */
    public static function runLoopSplitJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $loopKey = (string)($payload['loop_key'] ?? '');
        $loop = WorkflowSpec::node($run['definition'], $loopKey);
        $step = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loop === null || $step === null || $step['status'] !== WorkflowRun::STEP_RUNNING || $step['id'] !== (int)($payload['step_id'] ?? 0)) {
            return ['skipped' => true];
        }
        $user = User::findById($run['user_id']);
        if (($loop['source']['kind'] ?? '') === LoopSources::AI) {
            $quota = CreditQuota::status($user ?: []);
            if ($user && CreditQuota::isExceeded($quota)) {
                WorkflowRun::updateStep($step['id'], ['job_id' => null]);
                self::pause($run, $loopKey, CreditQuota::refusal($quota, true));
                return ['paused' => true];
            }
        }
        try {
            $split = self::asUser($user ?: null, static fn(): array => LoopRunner::split(
                $run['definition'], $loop, self::artifactsFor($run, null, null), $user ?: ['id' => $run['user_id']], self::billing($run)
            ));
        } catch (\RuntimeException $e) {
            self::splitFailed($run, $loop, $step['id'], self::loopError($e));
            return ['failed' => true];
        }
        WorkflowRun::updateStep($step['id'], ['job_id' => null]);
        $jobs = self::prepareElements(WorkflowRun::find($run['id']) ?? $run, $loop, $step['id'], $split['elements'], $split['header'], $split['notes'], count($split['elements']));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return ['elements' => count($split['elements'])];
    }

    /**
     * Les éléments lus : filtrés (règle, doublons, éléments sans nom), bornés
     * au plafond, figés au dossier, annoncés dans la conversation. Puis la
     * relecture de la liste (si demandée) ou le premier tour.
     */
    private static function prepareElements(array $run, array $loop, int $stepId, array $elements, array $header, array $notes, int $found): array
    {
        $pieces = WorkflowSpec::pieces($run['definition']);
        $itemPiece = $pieces[$loop['item']['key']] ?? ['key' => $loop['item']['key'], 'form' => 'text', 'fields' => []];
        $legacy = (string)($loop['source']['kind'] ?? '') === '';
        $filtered = $legacy ? ['kept' => $elements, 'ignored' => ['filter' => 0, 'dup' => 0, 'label' => 0]] : WorkflowLoop::filterElements($loop, $itemPiece, $elements);
        $cap = min((int)$loop['max'], Workflow::loopMax());
        $kept = array_slice($filtered['kept'], 0, max(1, $cap));
        $count = count($kept);
        $over = $pieces[$loop['over']] ?? null;
        WorkflowRun::saveArtifact($run['id'], $loop['key'] . self::ITEMS_SUFFIX, $loop['key'], t('Éléments de la boucle'), 'list', [
            'items'    => array_map(static fn(array $e): string => $legacy ? (string)$e['text'] : (string)$e['label'], $kept),
            'elements' => $legacy ? [] : $kept,
            'header'   => $header,
            'found'    => $found,
            'kept'     => count($filtered['kept']),
            'ignored'  => $filtered['ignored'],
            'notes'    => $notes,
            'source'   => LoopSources::describe($loop, $over),
        ]);
        $step = self::stepById($run['id'], $stepId);
        WorkflowRun::updateStep($stepId, ['detail' => [
            'mode' => $loop['mode'], 'i' => 0, 'count' => $count, 'total' => $legacy ? $found : count($filtered['kept']), 'found' => $found,
            'ignored' => $filtered['ignored'], 'st' => str_repeat('.', $count), 'phase' => 'iter',
        ] + array_intersect_key((array)($step['detail'] ?? []), array_flip(['auto']))]);

        $user = User::findById($run['user_id']);
        $kept_ = count($filtered['kept']);
        self::post($run, self::asUser($user ?: null, static function () use ($loop, $count, $found, $kept_, $filtered, $over, $notes, $legacy): string {
            $head = '↻ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : ';
            if ($count === 0) {
                return $head . ($found === 0
                    ? t('rien à traiter — « {piece} » est vide.', ['piece' => (string)($over['name'] ?? LoopSources::describe($loop, $over))])
                    : t('rien à traiter — aucun des {n} éléments lus ne passe le filtre.', ['n' => $found]));
            }
            $out = $head . I18n::plural($count, '{n} élément à traiter.', '{n} éléments à traiter.');
            $why = [];
            if ($filtered['ignored']['filter'] > 0) {
                $why[] = I18n::plural($filtered['ignored']['filter'], '{n} hors filtre', '{n} hors filtre');
            }
            if ($filtered['ignored']['dup'] > 0) {
                $why[] = I18n::plural($filtered['ignored']['dup'], '{n} doublon', '{n} doublons');
            }
            if ($filtered['ignored']['label'] > 0) {
                $why[] = I18n::plural($filtered['ignored']['label'], '{n} total ou sans nom', '{n} totaux ou sans nom');
            }
            if ($why !== []) {
                $out .= ' ' . t('Sur {n} lus : {motifs}.', ['n' => $found, 'motifs' => implode(', ', $why)]);
            }
            $total = $legacy ? $found : $kept_;
            if ($total > $count) {
                $out .= ' ' . t('Les {n} premiers sur {total} : la boucle en traite {n} au plus.', ['n' => $count, 'total' => $total]);
            }
            foreach ($notes as $note) {
                $out .= ' ' . $note;
            }
            return $out;
        }));
        if ($count === 0) {
            return self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, 0, false);
        }
        if (!empty($loop['confirm'])) {
            self::openLoopGate(WorkflowRun::find($run['id']) ?? $run, $loop, self::LIST_SUFFIX);
            return [];
        }
        return self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, 1);
    }

    /** Les éléments figés d'une boucle « pour chaque ». @return array<string, mixed> */
    private static function loopItems(array $run, array $loop): array
    {
        return (array)(WorkflowRun::artifact($run['id'], $loop['key'] . self::ITEMS_SUFFIX)['content'] ?? []);
    }

    /**
     * Un tour : l'élément en cours rangé au dossier (complété au besoin dans
     * un job : un fichier, le contenu d'un email), puis la première brique.
     */
    private static function startIteration(array $run, array $loop, int $i): array
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return [];
        }
        WorkflowRun::updateStep($step['id'], ['detail' => ['i' => $i, 'phase' => 'iter'] + $step['detail']]);
        if ($loop['mode'] === WorkflowSpec::LOOP_EACH) {
            $items = self::loopItems($run, $loop);
            $legacy = (string)($loop['source']['kind'] ?? '') === '';
            $element = $legacy
                ? ['label' => (string)($items['items'][$i - 1] ?? ''), 'fields' => [], 'text' => (string)($items['items'][$i - 1] ?? '')]
                : (array)($items['elements'][$i - 1] ?? []);
            if (!$legacy && LoopSources::needsFetch((array)$loop['source'])) {
                $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
                    'op' => 'loop_item', 'run_id' => $run['id'], 'loop_key' => $loop['key'], 'step_id' => $step['id'], 'i' => $i,
                    'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
                ], mb_substr(t('Boucle') . ' — ' . $loop['title'], 0, 60));
                WorkflowRun::updateStep($step['id'], ['job_id' => $jobId]);
                return [$jobId];
            }
            self::saveItem($run, $loop, $i, $element);
        }
        return self::enterBody($run, $loop, $i);
    }

    private static function saveItem(array $run, array $loop, int $i, array $element): void
    {
        $shape = LoopSources::itemShape($loop);
        WorkflowRun::saveArtifact($run['id'], WorkflowLoop::iterKey($loop['item']['key'], $i), $loop['key'], $loop['item']['name'], $shape['form'],
            WorkflowLoop::elementContent($shape, $element));
    }

    private static function enterBody(array $run, array $loop, int $i): array
    {
        $first = $loop['body'][0]['key'] ?? null;
        if ($first === null) {
            return self::iterationDone($run, $loop['key'], $i);
        }
        return self::enter(WorkflowRun::find($run['id']) ?? $run, $first, ['iter' => $i]);
    }

    /** Job `workflow` d'un élément à compléter (fichier, email) avant son tour. */
    public static function runLoopItemJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $loopKey = (string)($payload['loop_key'] ?? '');
        $loop = WorkflowSpec::node($run['definition'], $loopKey);
        $i = (int)($payload['i'] ?? 0);
        $step = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loop === null || $step === null || $step['status'] !== WorkflowRun::STEP_RUNNING || (int)($step['detail']['i'] ?? 0) !== $i) {
            return ['skipped' => true];
        }
        WorkflowRun::updateStep($step['id'], ['job_id' => null]);
        $user = User::findById($run['user_id']);
        $element = (array)(self::loopItems($run, $loop)['elements'][$i - 1] ?? []);
        try {
            $element = self::asUser($user ?: null, static fn(): array => LoopRunner::fetch($loop, $element, $user ?: ['id' => $run['user_id']]));
        } catch (\RuntimeException $e) {
            $jobs = self::itemFailed(WorkflowRun::find($run['id']) ?? $run, $loop, $i, ['kind' => 'generic', 'message' => $e->getMessage()]);
            foreach ($jobs as $jobId) {
                JobQueue::dispatch($jobId, false);
            }
            return ['failed' => true];
        }
        self::saveItem($run, $loop, $i, $element);
        foreach (self::enterBody(WorkflowRun::find($run['id']) ?? $run, $loop, $i) as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return ['element' => $i];
    }

    /** Issue d'un élément dans le suivi de la boucle (`st` : un caractère par élément). */
    private static function markItem(array $step, int $i, string $mark, ?string $cause = null): array
    {
        $detail = $step['detail'];
        $st = (string)($detail['st'] ?? '');
        $count = (int)($detail['count'] ?? 0);
        $st = str_pad($st, $count, '.');
        if ($i >= 1 && $i <= strlen($st)) {
            $st[$i - 1] = $mark;
        }
        $detail['st'] = $st;
        $fail = (array)($detail['fail'] ?? []);
        if ($mark === 'f') {
            $fail[(string)$i] = mb_substr((string)$cause, 0, 160);
            $detail['streak'] = (int)($detail['streak'] ?? 0) + 1;
        } else {
            unset($fail[(string)$i]);
            $detail['streak'] = 0;
        }
        $detail['fail'] = $fail;
        unset($detail['paused_at']);
        return $detail;
    }

    /** L'élément suivant à traiter après `$i` (dans l'ordre, ou parmi ceux à refaire), ou 0. */
    private static function nextItem(array $detail, int $i): int
    {
        $only = array_values(array_map('intval', (array)($detail['only'] ?? [])));
        if ($only !== []) {
            foreach ($only as $n) {
                if ($n > $i) {
                    return $n;
                }
            }
            return 0;
        }
        return $i < (int)($detail['count'] ?? 0) ? $i + 1 : 0;
    }

    /**
     * Fin du tour `$i` : tour suivant, condition de sortie, ou fin de la
     * boucle. `$mark` : 'd' l'élément est traité, 's' une condition de la
     * boucle l'a écarté.
     */
    private static function iterationDone(array $run, string $loopKey, int $i, string $mark = 'd'): array
    {
        $loop = WorkflowSpec::node($run['definition'], $loopKey);
        $step = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loop === null || $step === null || $step['status'] !== WorkflowRun::STEP_RUNNING || $i < 1) {
            return [];
        }
        if ($loop['mode'] === WorkflowSpec::LOOP_EACH) {
            $detail = self::markItem($step, $i, $mark);
            WorkflowRun::updateStep($step['id'], ['detail' => $detail]);
            $next = self::nextItem($detail, $i);
            return $next > 0 ? self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, $next) : self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, $i, false);
        }
        $u = $loop['until'];
        if ($u['mode'] === 'rule') {
            $pieces = WorkflowSpec::pieces($run['definition']);
            $contents = array_map(static fn(array $a): array => $a['content'], self::artifactsFor($run, $loop, $i));
            $eval = WorkflowValues::evaluate($u['rule'], $pieces, $contents);
            return self::continueUntil($run, $loop, $i, $eval['result'], '', $eval['lines']);
        }
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'loop_until', 'run_id' => $run['id'], 'loop_key' => $loopKey, 'i' => $i, 'step_id' => $step['id'],
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Boucle') . ' — ' . $loop['title'], 0, 60));
        // Rattaché à la brique de la boucle : une fin de job perdue ou une
        // panne au démarrage (quota) est rattrapée comme pour une étape.
        WorkflowRun::updateStep($step['id'], ['job_id' => $jobId]);
        return [$jobId];
    }

    /**
     * Un élément a échoué (une brique du tour, après ses essais automatiques ;
     * ou son fichier illisible). La boucle le marque en échec et passe au
     * suivant — sauf après ITEM_FAILURE_STREAK échecs d'affilée : elle se met
     * en pause (une clé refusée, un crédit épuisé ne grillent pas 200 tours),
     * reprenable à cet élément.
     */
    private static function itemFailed(array $run, array $loop, int $i, array $error): array
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return [];
        }
        $cause = trim((string)($error['title'] ?? '')) !== '' ? (string)$error['title'] : (string)($error['message'] ?? '');
        $detail = self::markItem($step, $i, 'f', $cause);
        $user = User::findById($run['user_id']);
        $label = WorkflowLoop::label((string)(self::loopItems($run, $loop)['items'][$i - 1] ?? ''), 60);
        $count = (int)($detail['count'] ?? 0);
        if ((int)$detail['streak'] >= WorkflowSpec::ITEM_FAILURE_STREAK) {
            $detail['paused_at'] = $i;
            WorkflowRun::updateStep($step['id'], ['detail' => $detail]);
            self::pause($run, $loop['key'], ['kind' => 'loop_failures', 'message' => self::asUser($user ?: null, static fn(): string => t('{n} éléments de « {titre} » ont échoué d’affilée ({cause}). Réglez la cause : la reprise refait l’élément {i}, puis continue.', [
                'n' => (int)$detail['streak'], 'titre' => $loop['title'], 'cause' => $cause !== '' ? $cause : t('cause inconnue'), 'i' => $i,
            ]))]);
            return [];
        }
        WorkflowRun::updateStep($step['id'], ['detail' => $detail]);
        self::post($run, '⚠️ ' . self::asUser($user ?: null, static fn(): string => t('Élément {i} sur {n} « {nom} » en échec ({cause}) : la boucle passe au suivant.', [
            'i' => $i, 'n' => $count, 'nom' => $label, 'cause' => $cause !== '' ? $cause : t('cause inconnue'),
        ])));
        $next = self::nextItem($detail, $i);
        return $next > 0 ? self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, $next) : self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, $i, false);
    }

    /** Job `workflow` de la condition de sortie d'une boucle « jusqu'à » (question à MIA). */
    public static function runLoopUntilJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $loop = WorkflowSpec::node($run['definition'], (string)($payload['loop_key'] ?? ''));
        $i = (int)($payload['i'] ?? 0);
        $step = WorkflowRun::lastStep($run['id'], (string)($payload['loop_key'] ?? ''));
        if ($loop === null || $step === null || $step['status'] !== WorkflowRun::STEP_RUNNING || (int)($step['detail']['i'] ?? 0) !== $i) {
            return ['skipped' => true];
        }
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = self::artifactsFor($run, $loop, $i);
        $user = User::findById($run['user_id']);
        $quota = CreditQuota::status($user ?: []);
        if ($user && CreditQuota::isExceeded($quota)) {
            self::pause($run, $loop['key'], CreditQuota::refusal($quota, true));
            return ['paused' => true];
        }
        $verdict = WorkflowAi::judge(WorkflowSpec::renderMentions($loop['until']['question'], $pieces), (string)$def['objective'],
            self::evidence(WorkflowSpec::untilReadsOf($def, $loop), $pieces, $artifacts), [
                'user_id' => $run['user_id'], 'conversation_id' => $run['conversation_id'],
            ]);
        if ($verdict['error'] !== null) {
            // Panne passagère : le même tour est réévalué plus tard ; sinon arrêt reprenable.
            $kind = (string)($verdict['error']['kind'] ?? 'model');
            $error = ['kind' => $kind, 'title' => (string)($verdict['error']['title'] ?? ''), 'message' => (string)($verdict['error']['message'] ?? '')];
            self::fail($run, $loop['key'], $error);
            return ['failed' => true];
        }
        // « Incertain » : la condition n'est pas tenue pour remplie — on refait un tour.
        foreach (self::continueUntil($run, $loop, $i, $verdict['answer'] === 'yes', $verdict['reason'], []) as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return ['met' => $verdict['answer'] === 'yes'];
    }

    private static function continueUntil(array $run, array $loop, int $i, bool $met, string $reason, array $lines): array
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return [];
        }
        $rounds = (array)($step['detail']['rounds'] ?? []);
        $rounds[] = ['i' => $i, 'met' => $met, 'reason' => mb_substr($reason !== '' ? $reason : implode(' · ', $lines), 0, 600)];
        WorkflowRun::updateStep($step['id'], ['detail' => ['rounds' => $rounds] + $step['detail']]);
        $user = User::findById($run['user_id']);
        self::post($run, self::asUser($user ?: null, static function () use ($loop, $i, $met, $reason, $lines): string {
            $out = '◇ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** · ' . t('tour {n}', ['n' => $i]) . ' : '
                . ($met ? t('condition remplie, la boucle s’arrête.') : t('condition pas encore remplie.'));
            if ($reason !== '') {
                $out .= "\n\n> " . $reason;
            }
            if ($lines !== []) {
                $out .= "\n\n" . implode("\n", array_map(static fn($l) => '- ' . $l, $lines));
            }
            return $out;
        }));
        if ($met) {
            return self::finishLoop($run, $loop, $i, false);
        }
        if ($i < (int)$loop['max']) {
            return self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, $i + 1);
        }
        if ($loop['exhausted'] === WorkflowSpec::THEN_STOP) {
            WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'exhausted');
            self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Le workflow s’arrête : « {titre} » n’a pas atteint sa condition en {n} tours.', ['titre' => $loop['title'], 'n' => (int)$loop['max']])));
            WorkflowRun::setState($run['id'], WorkflowRun::STOPPED, $loop['key']);
            WorkflowNotifier::notify($run, WorkflowNotifier::STOPPED, $loop['title']);
            return [];
        }
        return self::finishLoop($run, $loop, $i, true);
    }

    /**
     * Fin d'une boucle : les résultats rassemblés en une pièce (une ligne par
     * élément, colonnes d'origine comprises, Statut quand un élément n'a pas
     * été traité), puis la suite du rail. Avec la relecture groupée des
     * actions, celle-ci s'ouvre d'abord.
     */
    private static function finishLoop(array $run, array $loop, int $last, bool $exhausted): array
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
            return [];
        }
        if ($loop['mode'] === WorkflowSpec::LOOP_EACH && !empty($loop['review_actions']) && empty($step['detail']['actions_done'])
            && self::preparedActions($run, $loop) !== []) {
            self::openLoopGate($run, $loop, self::ACTIONS_SUFFIX);
            return [];
        }
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $all = WorkflowRun::artifacts($run['id']);
        $collect = WorkflowSpec::loopCollect($loop);
        $src = $collect !== null ? ($pieces[$collect] ?? null) : null;
        $itemsArt = (array)($all[$loop['key'] . self::ITEMS_SUFFIX]['content'] ?? []);
        $items = (array)($itemsArt['items'] ?? []);
        $elements = (array)($itemsArt['elements'] ?? []);
        $user = User::findById($run['user_id']);
        $each = $loop['mode'] === WorkflowSpec::LOOP_EACH;
        $detail = (array)$step['detail'];
        $st = (string)($detail['st'] ?? '');
        $fail = (array)($detail['fail'] ?? []);
        $n = $each ? (int)($detail['count'] ?? 0) : $last;
        $results = [];
        $tally = ['d' => 0, 'f' => 0, 's' => 0, 'e' => 0];
        // Un tableau a sa colonne Statut : un élément écarté y garde la Fiche déjà
        // remise (« Intérêt : non »). Ailleurs, sa section dit seulement son état.
        $asTable = ($pieces[$loop['output']['key']]['form'] ?? '') === 'table';
        for ($i = 1; $i <= $n; $i++) {
            $content = $collect !== null ? ($all[WorkflowLoop::iterKey($collect, $i)]['content'] ?? null) : null;
            $mark = $each ? ($st[$i - 1] ?? '.') : 'd';
            $status = match ($mark) {
                'f'     => WorkflowLoop::FAILED,
                's'     => WorkflowLoop::SKIPPED,
                '.'     => WorkflowLoop::FAILED,
                default => $content !== null || $collect === null ? WorkflowLoop::DONE : WorkflowLoop::EMPTY,
            };
            $tally[match ($status) { WorkflowLoop::FAILED => 'f', WorkflowLoop::SKIPPED => 's', WorkflowLoop::EMPTY => 'e', default => 'd' }]++;
            $results[] = [
                'item'    => $each ? (string)($items[$i - 1] ?? '') : self::asUser($user ?: null, static fn(): string => t('Tour {n}', ['n' => $i])),
                'content' => match ($status) {
                    WorkflowLoop::FAILED  => null,
                    WorkflowLoop::SKIPPED => $asTable ? $content : null,
                    default               => $content,
                },
                'status'  => $status,
                'note'    => $status === WorkflowLoop::FAILED ? (string)($fail[(string)$i] ?? '') : ($status === WorkflowLoop::EMPTY ? self::asUser($user ?: null, static fn(): string => t('élément ignoré')) : ''),
                'cells'   => (array)($elements[$i - 1]['cells'] ?? []),
            ];
        }
        $out = $pieces[$loop['output']['key']];
        $origin = ($loop['source']['kind'] ?? '') === LoopSources::ROWS ? (array)($itemsArt['header'] ?? []) : [];
        $content = self::asUser($user ?: null, static fn(): array => WorkflowLoop::aggregate(
            $out + ['until' => !$each], $src, $results, $origin
        ));
        WorkflowRun::saveArtifact($run['id'], $out['key'], $loop['key'], $out['name'], $out['form'], $content);
        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, $exhausted ? 'exhausted' : 'done',
            $detail + ['done' => $each ? $tally['d'] : $last, 'skipped' => $tally['e'], 'failed' => $tally['f'], 'discarded' => $tally['s']])) {
            return [];
        }
        self::post($run, self::asUser($user ?: null, static function () use ($loop, $last, $exhausted, $out, $tally, $each): string {
            $out_ = '✓ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : ';
            $out_ .= $each
                ? I18n::plural($tally['d'] + $tally['e'], '{n} élément traité', '{n} éléments traités')
                : ($exhausted
                    ? t('condition non atteinte après {n} tours — la dernière version est gardée', ['n' => $last])
                    : I18n::plural($last, 'condition remplie au premier tour', 'condition remplie au tour {n}'));
            if ($each && $tally['e'] > 0) {
                $out_ .= ' · ' . I18n::plural($tally['e'], '{n} ignoré', '{n} ignorés');
            }
            if ($each && $tally['s'] > 0) {
                $out_ .= ' · ' . I18n::plural($tally['s'], '{n} écarté', '{n} écartés');
            }
            if ($each && $tally['f'] > 0) {
                $out_ .= ' · ' . I18n::plural($tally['f'], '{n} en échec', '{n} en échec');
            }
            $out_ .= ' → « ' . $out['name'] . ' ».';
            if ($each && $tally['f'] > 0) {
                $out_ .= "\n\n" . t('Une fois la cause réglée, « Refaire les éléments en échec » sur la page de l’exécution ne rejoue qu’eux.');
            }
            return $out_;
        }));
        return self::advanceFrom($run['id'], $loop['key'], '');
    }

    // ------------------------------------------------------------------
    // Relectures d'une boucle : la liste des éléments, les actions préparées
    // ------------------------------------------------------------------

    /** Suffixe de la relecture de la liste des éléments (avant le premier tour). */
    public const LIST_SUFFIX = '~list';
    /** Suffixe de la relecture groupée des actions préparées (à la fin). */
    public const ACTIONS_SUFFIX = '~actions';

    /**
     * Actions préparées pendant les tours d'une boucle à relecture groupée et
     * encore en attente : identifiant → tour.
     *
     * @return array<int, int>
     */
    public static function preparedActions(array $run, array $loop): array
    {
        $body = array_flip(array_column($loop['body'], 'key'));
        $out = [];
        foreach (WorkflowRun::steps($run['id']) as $s) {
            [$base, $iter] = WorkflowLoop::splitIter($s['node_key']);
            if ($iter === null || !isset($body[$base]) || $s['outcome'] !== 'prepared') {
                continue;
            }
            foreach ((array)($s['detail']['cards'] ?? []) as $id) {
                $card = DrivePendingAction::statusOf((int)$id);
                if (($card['status'] ?? '') === 'pending') {
                    $out[(int)$id] = (int)$iter;
                }
            }
        }
        return $out;
    }

    /**
     * Une relecture de boucle : la LISTE des éléments avant le premier tour
     * (`~list`), ou les ACTIONS préparées à chaque tour (`~actions`). Une
     * carte dans la conversation (Approuver = tout ; Ajuster = choisir sur la
     * page de l'exécution), une notification ; l'exécution attend.
     */
    private static function openLoopGate(array $run, array $loop, string $suffix): void
    {
        $user = User::findById($run['user_id']);
        $convId = (int)$run['conversation_id'];
        $key = $loop['key'] . $suffix;
        $isList = $suffix === self::LIST_SUFFIX;
        $items = self::loopItems($run, $loop);
        $actions = $isList ? [] : self::preparedActions($run, $loop);
        $n = $isList ? count((array)($items['items'] ?? [])) : count($actions);
        [$content, $preview] = self::asUser($user ?: null, static function () use ($run, $loop, $isList, $items, $actions, $n): array {
            if ($isList) {
                $labels = array_slice((array)($items['items'] ?? []), 0, 25);
                $content = '⏸ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : '
                    . I18n::plural($n, 'relisez l’élément trouvé avant de continuer.', 'relisez les {n} éléments trouvés avant de continuer.')
                    . "\n\n[" . t('Choisir les éléments') . '](/workflows/runs/' . $run['id'] . ')';
                $preview = [
                    'action'       => t('Relire les éléments d’une boucle'),
                    'fichier'      => $loop['title'] . ' · ' . I18n::plural($n, '{n} élément', '{n} éléments'),
                    'apercu'       => implode("\n", array_map(static fn($l): string => '- ' . WorkflowLoop::label((string)$l, 90), $labels)) . ($n > 25 ? "\n…" : ''),
                    'apercu_titre' => t('Éléments trouvés'),
                    'impacts'      => [t('Approuver : tous les éléments sont traités.'), t('Annuler : le workflow s’arrête.'), t('Pour en retirer : « Ajuster ».')],
                    'icone'        => '/assets/img/workflow.svg',
                    'ajuster'      => '/workflows/runs/' . $run['id'],
                ];
                return [$content, $preview];
            }
            $content = '⏸ **' . t('Boucle — {titre}', ['titre' => $loop['title']]) . '** : '
                . I18n::plural($n, 'une action préparée attend votre relecture.', '{n} actions préparées attendent votre relecture.')
                . "\n\n[" . t('Relire les actions') . '](/workflows/runs/' . $run['id'] . ')';
            $lines = [];
            foreach (DrivePendingAction::pendingForConversation((int)$run['conversation_id'], (int)$run['user_id']) as $card) {
                if (isset($actions[$card['id']]) && count($lines) < 10) {
                    $p = (array)$card['preview'];
                    $lines[] = '- ' . trim((string)($p['action'] ?? '') . ' — ' . (string)($p['fichier'] ?? ''), ' —');
                }
            }
            $preview = [
                'action'       => t('Relire les actions préparées par une boucle'),
                'fichier'      => $loop['title'] . ' · ' . I18n::plural($n, '{n} action', '{n} actions'),
                'apercu'       => implode("\n", $lines) . ($n > 10 ? "\n…" : ''),
                'apercu_titre' => t('Actions préparées'),
                'impacts'      => [
                    I18n::plural($n, 'Approuver : l’action est exécutée.', 'Approuver : les {n} actions sont exécutées.'),
                    t('Annuler : aucune n’est exécutée ; le workflow continue.'),
                    t('Pour en choisir : « Ajuster ».'),
                ],
                'icone'        => '/assets/img/workflow.svg',
                'ajuster'      => '/workflows/runs/' . $run['id'],
            ];
            return [$content, $preview];
        });
        $msg = Message::create($convId, 'assistant', $content);
        Conversation::touch($convId);
        Conversation::markUnread($convId);
        $actionId = DrivePendingAction::create((int)$run['user_id'], $convId, self::GATE_TOOL, ['run_id' => $run['id'], 'node_key' => $key], $preview);
        DrivePendingAction::extend($actionId, (int)$run['user_id'], WorkflowSpec::GATE_DAYS_DEFAULT * 24 * 60);
        DrivePendingAction::attachToMessage([$actionId], (int)$msg['id'], (int)$run['user_id']);
        if (!$isList) {
            // Les actions attendent la relecture : elles ne doivent pas expirer avant elle.
            foreach (array_keys($actions) as $id) {
                DrivePendingAction::extend($id, (int)$run['user_id'], (WorkflowSpec::GATE_DAYS_DEFAULT + 1) * 24 * 60);
            }
        }
        // Actions relues : leur tour (`iters`), pour dire à quel élément chacune se rapporte.
        $stepId = WorkflowRun::insertStep($run['id'], $key, WorkflowRun::STEP_WAITING, $isList ? ['count' => $n] : [
            'actions' => array_keys($actions), 'iters' => array_map('intval', $actions),
        ]);
        WorkflowRun::updateStep($stepId, ['pending_action_id' => $actionId, 'message_id' => (int)$msg['id']]);
        WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $key);
        WorkflowNotifier::notify($run, WorkflowNotifier::GATE, $loop['title']);
    }

    /**
     * Issue d'une relecture de boucle. Liste : `approved` (avec `$keep`, les
     * numéros des éléments gardés — tous sans choix) ou `stopped`. Actions :
     * `approved` (avec `$keep`, les identifiants des actions à exécuter —
     * toutes sans choix), `none` (aucune) ou `stopped` (arrêter le workflow).
     */
    public static function loopGateResolved(array $run, array $step, string $outcome, ?array $keep = null, string $mailMode = 'approve'): array
    {
        $isList = str_ends_with($step['node_key'], self::LIST_SUFFIX);
        $loopKey = substr($step['node_key'], 0, -strlen($isList ? self::LIST_SUFFIX : self::ACTIONS_SUFFIX));
        $loop = WorkflowSpec::node($run['definition'], $loopKey);
        if ($loop === null) {
            return [];
        }
        $user = User::findById($run['user_id']);
        if ($outcome === 'stopped') {
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, 'stopped', $step['detail'])) {
                return [];
            }
            WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $step['node_key']);
            self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté à la relecture de « {titre} ».', ['titre' => $loop['title']])));
            return [];
        }
        $loopStep = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loopStep === null || $loopStep['status'] !== WorkflowRun::STEP_RUNNING) {
            return [];
        }
        if ($isList) {
            $items = self::loopItems($run, $loop);
            $count = count((array)($items['items'] ?? []));
            $chosen = $keep === null ? range(1, max(1, $count)) : array_values(array_unique(array_filter(array_map('intval', $keep), static fn(int $i): bool => $i >= 1 && $i <= $count)));
            sort($chosen);
            if ($chosen === [] || $count === 0) {
                if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, 'approved', $step['detail'] + ['kept' => 0])) {
                    return [];
                }
                WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $loopKey);
                WorkflowRun::updateStep($loopStep['id'], ['detail' => ['count' => 0, 'st' => ''] + $loopStep['detail']]);
                return self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, 0, false);
            }
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, 'approved', $step['detail'] + ['kept' => count($chosen)])) {
                return [];
            }
            if (count($chosen) < $count) {
                $pick = static fn(array $list): array => array_values(array_map(static fn(int $i) => $list[$i - 1] ?? null, $chosen));
                $items['items'] = $pick((array)$items['items']);
                if (($items['elements'] ?? []) !== []) {
                    $items['elements'] = $pick((array)$items['elements']);
                }
                $items['unchecked'] = $count - count($chosen);
                WorkflowRun::saveArtifact($run['id'], $loopKey . self::ITEMS_SUFFIX, $loopKey, t('Éléments de la boucle'), 'list', $items);
                WorkflowRun::updateStep($loopStep['id'], ['detail' => ['count' => count($chosen), 'st' => str_repeat('.', count($chosen))] + $loopStep['detail']]);
                self::post($run, self::asUser($user ?: null, static fn(): string => '✅ ' . t('{n} éléments gardés sur {total} : la boucle commence.', ['n' => count($chosen), 'total' => $count])));
            }
            WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $loopKey);
            return self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, 1);
        }

        $pending = self::preparedActions($run, $loop);
        $ids = $outcome === 'none' ? [] : ($keep === null ? array_keys($pending) : array_values(array_intersect(array_keys($pending), array_map('intval', $keep))));
        $refused = array_values(array_diff(array_keys($pending), $ids));
        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, $ids === [] ? 'none' : 'approved',
            $step['detail'] + ['approved' => $ids, 'refused' => $refused, 'mail_mode' => $mailMode === 'send' ? 'send' : 'approve'])) {
            return [];
        }
        foreach ($refused as $id) {
            DrivePendingAction::cancelPending($id, 'Écartée à la relecture groupée.');
        }
        WorkflowRun::updateStep($loopStep['id'], ['detail' => ['actions_done' => true] + $loopStep['detail']]);
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $loopKey);
        if ($ids === []) {
            self::post($run, '🚫 ' . self::asUser($user ?: null, static fn(): string => t('Aucune action de « {titre} » n’est exécutée : le workflow continue.', ['titre' => $loop['title']])));
            return self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, 0, false);
        }
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'loop_actions', 'run_id' => $run['id'], 'loop_key' => $loopKey, 'step_id' => $step['id'], 'ids' => $ids,
            'mail_mode' => $mailMode === 'send' ? 'send' : 'approve',
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Boucle') . ' — ' . $loop['title'], 0, 60));
        WorkflowRun::updateStep($loopStep['id'], ['job_id' => $jobId]);
        return [$jobId];
    }

    /**
     * Job `workflow` de la relecture groupée : chaque action retenue est
     * exécutée par le chemin d'un clic « Approuver » (réservation, droits,
     * connexion revérifiés, issue dans la conversation), puis la boucle se
     * termine.
     */
    public static function runLoopActionsJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $loopKey = (string)($payload['loop_key'] ?? '');
        $loop = WorkflowSpec::node($run['definition'], $loopKey);
        $loopStep = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loop === null || $loopStep === null || $loopStep['status'] !== WorkflowRun::STEP_RUNNING) {
            return ['skipped' => true];
        }
        WorkflowRun::updateStep($loopStep['id'], ['job_id' => null]);
        $user = User::findById($run['user_id']);
        $results = ['done' => 0, 'failed' => 0];
        if ($user) {
            $chat = new ChatController();
            foreach (array_map('intval', (array)($payload['ids'] ?? [])) as $id) {
                $card = DrivePendingAction::statusOf($id);
                if (($card['status'] ?? '') !== 'pending') {
                    continue;
                }
                $mail = MailDraftReview::isMailTool((string)($card['tool'] ?? ''));
                $decision = $mail && ($payload['mail_mode'] ?? '') === 'send' ? 'send' : 'approve';
                $data = self::asUser($user, static fn(): array => $chat->decideForWorkflowBatch((int)$run['conversation_id'], $user, $id, $decision));
                !empty($data['ok']) ? $results['done']++ : $results['failed']++;
            }
        }
        $gate = WorkflowRun::lastStep($run['id'], $loopKey . self::ACTIONS_SUFFIX);
        if ($gate !== null) {
            WorkflowRun::updateStep($gate['id'], ['detail' => $gate['detail'] + ['results' => $results]]);
        }
        self::post($run, '✅ ' . self::asUser($user ?: null, static fn(): string => t('Relecture de « {titre} » : {fait} exécutées, {echec} en échec.', [
            'titre' => $loop['title'], 'fait' => $results['done'], 'echec' => $results['failed'],
        ])));
        foreach (self::finishLoop(WorkflowRun::find($run['id']) ?? $run, $loop, 0, false) as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
        return $results;
    }

    /**
     * « Refaire les éléments en échec » : la boucle repart pour eux seuls —
     * les éléments traités gardent leur pièce —, puis le rail reprend après
     * elle (sa pièce rassemblée et ce qui suit sont refaits). Possible sur
     * une exécution arrêtée, en échec, en pause ou terminée.
     */
    public static function retryFailedItems(array $run, string $loopKey): array
    {
        if (in_array($run['status'], [WorkflowRun::RUNNING, WorkflowRun::WAITING], true)) {
            throw new \InvalidArgumentException(t('Arrêtez d’abord l’exécution en cours.'));
        }
        $def = $run['definition'];
        $loop = WorkflowSpec::node($def, $loopKey);
        $step = WorkflowRun::lastStep($run['id'], $loopKey);
        if ($loop === null || ($loop['type'] ?? '') !== WorkflowSpec::TYPE_LOOP || $loop['mode'] !== WorkflowSpec::LOOP_EACH || $step === null) {
            throw new \InvalidArgumentException(t('Cette boucle n’a pas d’éléments à refaire.'));
        }
        $st = (string)($step['detail']['st'] ?? '');
        $failed = [];
        for ($i = 0; $i < strlen($st); $i++) {
            if ($st[$i] === 'f' || ($st[$i] === '.' && $step['status'] !== WorkflowRun::STEP_DONE)) {
                $failed[] = $i + 1;
            }
        }
        if ($failed === []) {
            throw new \InvalidArgumentException(t('Aucun élément de cette boucle n’est en échec.'));
        }
        // Ce qui suit la boucle sur le rail repart : sa pièce rassemblée et les
        // pièces des briques suivantes sont retirées du dossier.
        $index = null;
        foreach ($def['nodes'] as $i => $n) {
            if ($n['key'] === $loopKey) {
                $index = $i;
            }
        }
        $producers = [];
        foreach (array_slice($def['nodes'], (int)$index + 1) as $n) {
            $producers[$n['key']] = true;
            foreach (WorkflowSpec::branchesOf($n) as $l) {
                foreach ($l['nodes'] as $c) {
                    $producers[$c['key']] = true;
                }
            }
            foreach ($n['body'] ?? [] as $c) {
                $producers[$c['key']] = true;
            }
        }
        foreach (WorkflowRun::artifacts($run['id']) as $artKey => $a) {
            if (isset($producers[$a['node_key']]) || $artKey === $loop['output']['key']) {
                WorkflowRun::deleteArtifact($run['id'], (string)$artKey);
            }
        }
        if (in_array($step['status'], [WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_WAITING], true)) {
            WorkflowRun::transitionStep($step['id'], $step['status'], WorkflowRun::STEP_CANCELLED, 'retried');
        }
        $detail = $step['detail'];
        foreach ($failed as $i) {
            $detail['st'][$i - 1] = '.';
            unset($detail['fail'][(string)$i]);
        }
        $detail['only'] = $failed;
        $detail['streak'] = 0;
        unset($detail['paused_at'], $detail['actions_done'], $detail['done'], $detail['failed'], $detail['skipped'], $detail['discarded']);
        WorkflowRun::insertStep($run['id'], $loopKey, WorkflowRun::STEP_RUNNING, $detail);
        $user = User::findById($run['user_id']);
        self::post($run, '↻ ' . self::asUser($user ?: null, static fn(): string => I18n::plural(count($failed), 'L’élément en échec de « {titre} » repart.', 'Les {n} éléments en échec de « {titre} » repartent.', ['titre' => $loop['title']])));
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $loopKey);
        return self::startIteration(WorkflowRun::find($run['id']) ?? $run, $loop, $failed[0]);
    }

    /** Ce que dit la consigne d'une étape de boucle sur le tour en cours. */
    private static function loopNote(array $run, array $loop, int $i, array $user): string
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($loop['mode'] === WorkflowSpec::LOOP_EACH) {
            $count = (int)($step['detail']['count'] ?? 0);
            return "Cette étape fait partie de la boucle « {$loop['title']} » : elle traite l'élément {$i} sur {$count} — la pièce « "
                . $loop['item']['name'] . " ». Ne traite QUE cet élément.";
        }
        $note = "Cette étape fait partie de la boucle « {$loop['title']} », tour {$i} sur " . (int)$loop['max'] . '.';
        if ($i > 1) {
            $rounds = (array)($step['detail']['rounds'] ?? []);
            $last = end($rounds) ?: [];
            $note .= " Au tour précédent, la condition de sortie n'était pas remplie"
                . (trim((string)($last['reason'] ?? '')) !== '' ? ' : ' . $last['reason'] : '')
                . '. Reprends ta version précédente et améliore-la sur ce point.';
        }
        return $note;
    }

    private static function iterLabel(array $run, array $loop, int $i, array $user): string
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($loop['mode'] !== WorkflowSpec::LOOP_EACH) {
            return self::asUser($user, static fn(): string => t('tour {n} sur {total}', ['n' => $i, 'total' => (int)$loop['max']]));
        }
        $name = WorkflowLoop::label((string)(self::loopItems($run, $loop)['items'][$i - 1] ?? ''), 60);
        return self::asUser($user, static fn(): string => t('élément {n} sur {total}', ['n' => $i, 'total' => (int)($step['detail']['count'] ?? 0)])
            . ($name !== '' && ($loop['source']['kind'] ?? '') !== '' ? ' — ' . $name : ''));
    }

    // ==================================================================
    // Attendre, prévenir
    // ==================================================================

    /** Brique « Attendre » : reprise à son heure (tickTimers). */
    private static function startWait(array $run, array $node, ?int $iter): array
    {
        $passKey = WorkflowLoop::iterKey($node['key'], $iter);
        $user = User::findById($run['user_id']);
        $now = self::now();
        $tz = Recurrence::zone(ScheduledTask::normalizeTimezone((string)$run['timezone']));
        if ($node['mode'] === 'date') {
            $loop = $iter !== null ? WorkflowSpec::loopOf($run['definition'], $node['key']) : null;
            $artifacts = self::artifactsFor($run, $loop, $iter);
            $raw = $artifacts[$node['piece']]['content']['fields'][$node['field']] ?? null;
            $date = is_string($raw) ? WorkflowValues::date($raw) : null;
            if ($date === null) {
                self::fail($run, $passKey, ['kind' => 'generic', 'message' => self::asUser($user ?: null, static fn(): string => t('La date à attendre est vide ou illisible : corrigez la pièce, puis reprenez.'))]);
                return [];
            }
            [$h, $m] = array_map('intval', explode(':', $node['time']));
            $at = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->setTime($h, $m)->modify(($node['offset'] >= 0 ? '+' : '') . (int)$node['offset'] . ' days');
            $resume = $at->setTimezone(new \DateTimeZone('UTC'));
            if ($resume->getTimestamp() - $now->getTimestamp() > WorkflowSpec::WAIT_DATE_MAX_DAYS * 86400) {
                self::fail($run, $passKey, ['kind' => 'generic', 'message' => self::asUser($user ?: null, static fn(): string => t('La date à attendre est à plus de {n} jours : le workflow ne l’attend pas.', ['n' => WorkflowSpec::WAIT_DATE_MAX_DAYS]))]);
                return [];
            }
        } else {
            $resume = $now->modify('+' . ((int)$node['amount'] * WorkflowSpec::WAIT_UNITS[$node['unit']]) . ' seconds');
        }
        if ($resume <= $now) {
            $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_DONE, ['kind' => 'wait', 'past' => true]);
            WorkflowRun::updateStep($stepId, ['finished' => true, 'outcome' => 'resumed']);
            self::post($run, '⏩ ' . self::asUser($user ?: null, static fn(): string => t('« {titre} » : la date est déjà passée, le workflow continue.', ['titre' => $node['title']])));
            return self::advanceFrom($run['id'], $passKey, '');
        }
        $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_WAITING, ['kind' => 'wait', 'resume' => ScheduledTask::sqlUtc($resume)]);
        WorkflowRun::setResumeAt($stepId, $resume);
        WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $passKey);
        $when = Format::shortDateTime($resume->setTimezone($tz));
        self::post($run, '⏳ ' . self::asUser($user ?: null, static fn(): string => t('**{titre}** : le workflow reprend le {quand}.', ['titre' => $node['title'], 'quand' => $when])));
        return [];
    }

    /** Brique « Me prévenir » : un message dans la conversation et une notification. */
    private static function doNotify(array $run, array $node, ?int $iter): array
    {
        $passKey = WorkflowLoop::iterKey($node['key'], $iter);
        $loop = $iter !== null ? WorkflowSpec::loopOf($run['definition'], $node['key']) : null;
        $artifacts = self::artifactsFor($run, $loop, $iter);
        $pieces = WorkflowSpec::pieces($run['definition']);
        $user = User::findById($run['user_id']);
        $text = self::asUser($user ?: null, static fn(): string => WorkflowDeliveries::fill((string)$node['message'], $pieces, $artifacts, false, 300));
        $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_RUNNING);
        self::post($run, '🔔 ' . $text);
        Conversation::markUnread((int)$run['conversation_id']);
        WorkflowNotifier::notify($run, WorkflowNotifier::NOTE, $text, 0, true);
        // Le message envoyé reste dans le passage : l'historique le montre.
        WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'done', ['text' => mb_substr($text, 0, 300)]);
        return self::advanceFrom($run['id'], $passKey, '');
    }

    /**
     * Brique « Arrêter le workflow » : l'exécution se termine là, proprement.
     * Un message (facultatif, il peut citer le dossier) dans la conversation,
     * le statut choisi — « Terminée » ou « Arrêtée » —, la personne prévenue.
     * Ni livrable, ni livraison, ni workflow enchaîné : le rail n'est pas
     * allé au bout. Elle ne vit que dans une voie (jamais dans une boucle).
     */
    private static function doStop(array $run, array $node): array
    {
        $key = $node['key'];
        $stopped = ($node['outcome'] ?? '') === WorkflowSpec::STOP_STOPPED;
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = self::artifactsFor($run, null, null);
        $user = User::findById($run['user_id']);
        $named = self::stopTitle($node);
        [$head, $text] = self::asUser($user ?: null, static function () use ($node, $pieces, $artifacts, $stopped, $named): array {
            $text = trim((string)$node['message']) !== ''
                ? trim(WorkflowDeliveries::fill((string)$node['message'], $pieces, $artifacts, false, 300))
                : '';
            $head = $named === ''
                ? ($stopped ? t('Le workflow s’arrête ici.') : t('Le workflow se termine ici.'))
                : ($stopped
                    ? t('Le workflow s’arrête ici : « {titre} ».', ['titre' => $named])
                    : t('Le workflow se termine ici : « {titre} ».', ['titre' => $named]));
            return [$head, $text];
        });
        $stepId = WorkflowRun::insertStep($run['id'], $key, WorkflowRun::STEP_RUNNING);
        self::post($run, '⏹ ' . $head . ($text !== '' ? "\n\n" . $text : ''));
        WorkflowRun::transitionStep($stepId, WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, $stopped ? WorkflowSpec::STOP_STOPPED : WorkflowSpec::STOP_DONE,
            $text !== '' ? ['reason' => mb_substr($text, 0, 600)] : []);
        WorkflowRun::setState($run['id'], $stopped ? WorkflowRun::STOPPED : WorkflowRun::DONE, $key);
        Conversation::markUnread((int)$run['conversation_id']);
        WorkflowNotifier::notify($run, $stopped ? WorkflowNotifier::STOPPED : WorkflowNotifier::ENDED, $named);
        return [];
    }

    /** Titre d'une brique « Arrêter le workflow » à citer, ou '' s'il n'apprend rien (titre par défaut). */
    public static function stopTitle(array $node): string
    {
        $title = trim((string)($node['title'] ?? ''));
        return $title === '' || $title === 'Arrêter le workflow' || $title === t('Arrêter le workflow') ? '' : $title;
    }

    // ==================================================================
    // Fin d'un job
    // ==================================================================

    /**
     * Fin d'un job (JobQueue::finish et le reaper). Sans effet pour un job qui
     * n'est pas une étape de workflow. Ne lève jamais.
     */
    public static function onJobFinished(int $jobId, string $jobStatus, ?array $result, ?array $error): void
    {
        try {
            $step = WorkflowRun::stepByJob($jobId);
            if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING) {
                return;
            }
            $run = WorkflowRun::find($step['run_id']);
            if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
                return;
            }
            $quotaKinds = [CreditQuota::REASON_QUOTA, CreditQuota::REASON_BUDGET];
            if ($step['node_key'] === WorkflowSpec::END_KEY) {
                // Le job de livraison conclut lui-même ; ici, seulement sa panne.
                if ($jobStatus === 'done') {
                    return;
                }
                $quota = in_array((string)($error['kind'] ?? ''), $quotaKinds, true);
                if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, $quota ? 'quota' : 'error')) {
                    $quota
                        ? self::pause($run, WorkflowSpec::END_KEY, $error)
                        // Le job a déjà écrit sa cause (⚠️) dans la conversation.
                        : self::fail($run, WorkflowSpec::END_KEY, $error ?? ['kind' => 'generic', 'message' => t('La livraison n’a pas pu aboutir.')], $error === null);
                }
                return;
            }
            $node = self::nodeFor($run['definition'], $step['node_key']);
            if ($node === null) {
                return;
            }
            if ($node['type'] === WorkflowSpec::TYPE_LOOP) {
                // Job de la condition de sortie : il fait avancer la boucle
                // lui-même. Ici, son rattachement est retiré, et sa panne
                // arrête l'exécution SANS clore la boucle — la reprise
                // réévalue le même tour (resumeLoop).
                WorkflowRun::updateStep($step['id'], ['job_id' => null]);
                if ($jobStatus === 'cancelled') {
                    if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED, 'cancelled')) {
                        WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $step['node_key']);
                    }
                } elseif ($jobStatus !== 'done') {
                    in_array((string)($error['kind'] ?? ''), $quotaKinds, true)
                        ? self::pause($run, $step['node_key'], $error)
                        : self::fail($run, $step['node_key'], $error ?? ['kind' => 'generic', 'message' => $node['mode'] === WorkflowSpec::LOOP_EACH
                            ? t('La boucle n’a pas pu avancer : reprenez l’exécution.')
                            : t('La condition de sortie de la boucle n’a pas pu être évaluée.')], $error === null);
                }
                return;
            }
            $jobs = self::afterWork($run, $node, $step, $jobStatus, $result, $error);
            foreach ($jobs as $id) {
                JobQueue::dispatch($id, false);
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] fin du job #' . $jobId . ' : ' . $e->getMessage());
        }
    }

    private static function afterWork(array $run, array $node, array $step, string $jobStatus, ?array $result, ?array $error): array
    {
        $passKey = $step['node_key'];
        [, $iter] = WorkflowLoop::splitIter($passKey);
        $quotaKinds = [CreditQuota::REASON_QUOTA, CreditQuota::REASON_BUDGET];
        if (WorkflowSpec::isBranching($node)) {
            // Le job de condition (ou de décision) avance lui-même ; ici,
            // seulement son échec.
            if ($jobStatus !== 'done') {
                $kind = (string)($error['kind'] ?? '');
                if (in_array($kind, $quotaKinds, true)) {
                    if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'quota')) {
                        self::pause($run, $passKey, $error ?? []);
                    }
                } elseif ($jobStatus === 'cancelled') {
                    if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED, 'cancelled')) {
                        WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $passKey);
                    }
                } else {
                    return self::onFailure($run, $node, $step, $error ?? ['kind' => 'generic', 'message' => $node['type'] === WorkflowSpec::TYPE_ROUTE
                        ? t('MIA n’a pas pu choisir la voie.') : t('La condition n’a pas pu être évaluée.')], 'error');
                }
            }
            return [];
        }
        if ($jobStatus === 'cancelled') {
            if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED, 'cancelled')) {
                WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $passKey);
                self::post($run, self::asUser(User::findById($run['user_id']) ?: null, static fn(): string => '⏹ ' . t('Workflow arrêté pendant « {titre} ».', ['titre' => $node['title']])));
            }
            return [];
        }
        if ($jobStatus !== 'done' || !empty($result['error'])) {
            $kind = (string)($error['kind'] ?? 'generic');
            if (in_array($kind, $quotaKinds, true)) {
                if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'quota')) {
                    self::pause($run, $passKey, $error ?? []);
                }
                return [];
            }
            // Un tour qui échoue côté modèle (clé refusée, crédit épuisé…)
            // finit en job « done » portant `error` : la cause présentée est
            // dans le message ⚠️ qu'il a écrit — la reprendre plutôt
            // qu'un « le modèle n'a pas pu répondre » qui ne dit rien.
            $replyId = (int)($result['msg_id'] ?? 0);
            $error ??= self::errorFromReply((string)(($replyId > 0 ? Message::findById($replyId) : null)['content'] ?? ''));
            return self::onFailure($run, $node, $step, $error ?? ['kind' => 'model', 'message' => t('Le modèle n’a pas pu répondre à cette étape.')], 'error');
        }

        $replyId = (int)($result['msg_id'] ?? 0);
        $reply = $replyId > 0 ? Message::findById($replyId) : null;
        $text = (string)($reply['content'] ?? '');
        $user = User::findById($run['user_id']);

        if ($node['type'] === WorkflowSpec::TYPE_STEP) {
            $piece = WorkflowSpec::pieces($run['definition'])[$node['output']['key']];
            $read = ArtifactContract::extract($piece, $text);
            if (!$read['ok']) {
                $alreadyRetried = !empty($step['detail']['retry_reason']);
                if ($alreadyRetried) {
                    return self::onFailure($run, $node, $step, ['kind' => 'malformed', 'message' => self::asUser($user ?: null, static fn(): string => t('La pièce « {nom} » n’a pas la forme attendue, même après une seconde tentative.', ['nom' => $piece['name']]))], 'malformed', false);
                }
                if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'malformed', $step['detail'] + ['reply_id' => $replyId, 'why' => $read['error']])) {
                    return [];
                }
                return self::startWork($run, $node, [
                    'retry_reason' => $read['error'], 'remark' => (string)($step['detail']['remark'] ?? ''),
                    'iter' => $iter, 'auto' => (int)($step['detail']['auto'] ?? 0),
                ]);
            }
            // Une étape qui écrit a appris les repères (`@{clé}`) et peut en
            // mettre dans sa pièce : le dossier garde le contenu REMPLI.
            $content = WorkflowInserts::expandContent($run['definition'], $node, $piece, $read['content'],
                WorkflowRun::artifacts($run['id'], false), $iter);
            WorkflowRun::saveArtifact($run['id'], WorkflowLoop::iterKey($piece['key'], $iter), $node['key'], $piece['name'], $piece['form'], $content,
                self::provenanceOf($reply));
        }
        if ($node['type'] === WorkflowSpec::TYPE_CREATE) {
            // L'image, la page ou la carte : lue dans le résultat du job et le
            // message qu'il a écrit (celui de la création, dans la conversation).
            $piece = WorkflowSpec::pieces($run['definition'])[$node['output']['key']];
            $made = WorkflowCreations::contentFromResult((string)$node['kind'], $result ?? [], $reply, (string)($step['detail']['tool'] ?? ''));
            if ($made === null) {
                return self::onFailure($run, $node, $step, ['kind' => 'empty', 'message' => self::asUser($user ?: null, static fn(): string => t('La création « {titre} » n’a rien produit.', ['titre' => $node['title']]))], 'error', false);
            }
            WorkflowRun::saveArtifact($run['id'], WorkflowLoop::iterKey($piece['key'], $iter), $node['key'], $piece['name'], $piece['form'], $made);
        }

        // Des actions attendent l'accord de la personne : l'étape attend aussi —
        // sauf dans une boucle à relecture groupée : elles s'accumulent et se
        // relisent ensemble à la fin de la boucle, le tour continue.
        $pending = $replyId > 0 ? DrivePendingAction::pendingIdsForMessage($replyId, (int)$run['user_id']) : [];
        $groupLoop = $iter !== null ? WorkflowSpec::loopOf($run['definition'], $node['key']) : null;
        if ($pending !== [] && $groupLoop !== null && !empty($groupLoop['review_actions'])) {
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'prepared', $step['detail'] + ['reply_id' => $replyId, 'cards' => $pending])) {
                return [];
            }
            WorkflowRun::updateStep($step['id'], ['message_id' => $replyId]);
            return self::afterStepDone($run, $node, $iter);
        }
        if ($pending !== []) {
            if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_WAITING, 'cards', $step['detail'] + ['cards' => $pending])) {
                WorkflowRun::updateStep($step['id'], ['message_id' => $replyId]);
                WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $passKey);
                WorkflowNotifier::notify($run, WorkflowNotifier::CARDS, $node['title'], count($pending));
            }
            return [];
        }
        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'done', $step['detail'] + ['reply_id' => $replyId])) {
            return [];
        }
        WorkflowRun::updateStep($step['id'], ['message_id' => $replyId > 0 ? $replyId : null]);
        return self::afterStepDone($run, $node, $iter);
    }

    /** Étape (ou création) terminée : sa relecture si demandée, sinon la suite. */
    private static function afterStepDone(array $run, array $node, ?int $iter = null): array
    {
        if (in_array($node['type'], WorkflowSpec::PRODUCING_TYPES, true) && !empty($node['review'])) {
            $run = WorkflowRun::find($run['id']) ?? $run;
            self::openGate($run, WorkflowLoop::iterKey($node['key'] . self::REVIEW_SUFFIX, $iter));
            return [];
        }
        return self::advanceFrom($run['id'], WorkflowLoop::iterKey($node['key'], $iter), '');
    }

    // ==================================================================
    // Reprise sur erreur
    // ==================================================================

    /**
     * Une brique a échoué. Panne passagère et essais automatiques restants →
     * nouvel essai à son heure ; sinon, selon `on_error.then` : la brique est
     * ignorée (le rail continue sans sa pièce) ou l'exécution s'arrête dessus,
     * reprenable.
     *
     * @param string $why error | malformed
     * @param bool $written la cause est déjà écrite dans la conversation (message ⚠️ du tour ou du job)
     */
    private static function onFailure(array $run, array $node, array $step, array $error, string $why, bool $written = true): array
    {
        $passKey = $step['node_key'];
        [, $iter] = WorkflowLoop::splitIter($passKey);
        $policy = $node['on_error'] ?? ['retries' => 0, 'then' => WorkflowSpec::ON_ERROR_STOP];
        $kind = self::errorKind($error);
        $done = (int)($step['detail']['auto'] ?? 0);
        $user = User::findById($run['user_id']);

        if ($why === 'error' && in_array($kind, self::TRANSIENT_KINDS, true) && $done < (int)$policy['retries']) {
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'error', $step['detail'] + ['error' => $error])) {
                return [];
            }
            $delay = self::BACKOFF_SEC[min($done, count(self::BACKOFF_SEC) - 1)];
            $resume = self::now()->modify('+' . $delay . ' seconds');
            $waitId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_WAITING, array_filter([
                'kind' => 'retry', 'auto' => $done + 1, 'of' => (int)$policy['retries'],
                'resume' => ScheduledTask::sqlUtc($resume),
                'cause' => trim((string)($error['title'] ?? '')) !== '' ? (string)$error['title'] : (string)($error['message'] ?? ''),
                'remark' => (string)($step['detail']['remark'] ?? '') ?: null,
            ], static fn($v) => $v !== null));
            WorkflowRun::setResumeAt($waitId, $resume);
            WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $passKey);
            $cause = trim((string)($error['title'] ?? '')) !== '' ? (string)$error['title'] : (string)($error['message'] ?? '');
            self::post($run, '↻ ' . self::asUser($user ?: null, static fn(): string => t('« {titre} » : {cause} — nouvel essai automatique dans {delai} ({n}/{max}).', [
                'titre' => $node['title'], 'cause' => $cause !== '' ? $cause : t('panne passagère'),
                'delai' => I18n::plural(max(1, intdiv($delay, 60)), '{n} minute', '{n} minutes'),
                'n' => $done + 1, 'max' => (int)$policy['retries'],
            ])));
            return [];
        }

        if (($policy['then'] ?? '') === WorkflowSpec::ON_ERROR_SKIP && !WorkflowSpec::isBranching($node)) {
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'skipped', $step['detail'] + ['error' => $error])) {
                return [];
            }
            $cause = trim((string)($error['title'] ?? '')) !== '' ? (string)$error['title'] : (string)($error['message'] ?? '');
            self::post($run, '⤼ ' . self::asUser($user ?: null, static fn(): string => t('« {titre} » ignorée après une erreur ({cause}) : le workflow continue sans elle.', [
                'titre' => $node['title'], 'cause' => $cause !== '' ? $cause : t('cause inconnue'),
            ])));
            return self::advanceFrom($run['id'], $passKey, '');
        }

        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, $why === 'malformed' ? 'malformed' : 'error', $step['detail'] + ['error' => $error])) {
            return [];
        }
        // Dans une boucle « pour chaque » réglée pour continuer : l'ÉLÉMENT est
        // en échec, pas l'exécution — la boucle passe au suivant.
        $loop = $iter !== null ? WorkflowSpec::loopOf($run['definition'], (string)strtok((string)WorkflowLoop::splitIter($passKey)[0], '~')) : null;
        if ($loop !== null && $loop['mode'] === WorkflowSpec::LOOP_EACH && ($loop['on_item_error'] ?? WorkflowSpec::ITEM_ERROR_CONTINUE) === WorkflowSpec::ITEM_ERROR_CONTINUE) {
            return self::itemFailed(WorkflowRun::find($run['id']) ?? $run, $loop, (int)$iter, $error);
        }
        self::fail($run, $passKey, $error, !$written);
        return [];
    }

    /** Genre d'une panne : celui de l'erreur, sinon retrouvé par son titre présenté. */
    public static function errorKind(array $error): string
    {
        $kind = (string)($error['kind'] ?? '');
        $title = trim((string)($error['title'] ?? ''));
        if (($kind === '' || $kind === 'model') && $title !== '') {
            foreach (LlmErrorPresenter::titleKinds() as $prefix => $k) {
                if (str_starts_with($title, $prefix)) {
                    return $k;
                }
            }
        }
        return $kind !== '' ? $kind : 'generic';
    }

    /** Contenus de tiers lus pendant l'étape (trace), pour la provenance de la pièce. */
    private static function provenanceOf(?array $reply): array
    {
        $actions = json_decode((string)($reply['actions_json'] ?? ''), true);
        if (!is_array($actions)) {
            return [];
        }
        $out = [];
        foreach ($actions as $a) {
            if (!empty($a['l']) && ($a['ok'] ?? true) !== false) {
                $out[] = mb_substr((string)$a['l'] . (!empty($a['d']) ? ' — ' . $a['d'] : ''), 0, 160);
            }
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    // ==================================================================
    // Validations
    // ==================================================================

    /**
     * Brique de validation (ou relecture d'une étape) : un message, une carte
     * d'approbation (outil workflow_gate) qui attend `days` jours, une
     * notification. L'exécution passe « en attente ». `$gateKey` est la clé
     * de passage (« #n » pour une validation de boucle).
     */
    public static function openGate(array $run, string $gateKey): bool
    {
        $def = $run['definition'];
        $gate = self::nodeFor($def, $gateKey);
        if ($gate === null) {
            return false;
        }
        [$baseKey, $iter] = WorkflowLoop::splitIter($gateKey);
        $user = User::findById($run['user_id']);
        $pieces = WorkflowSpec::pieces($def);
        $loop = $iter !== null ? WorkflowSpec::loopOf($def, (string)strtok($baseKey, '~')) : null;
        $artifacts = self::artifactsFor($run, $loop, $iter);
        $reviews = array_values(array_filter((array)$gate['reviews'], static fn($k) => isset($artifacts[$k])));
        $convId = (int)$run['conversation_id'];
        $iterLabel = $loop !== null && $user ? self::iterLabel($run, $loop, (int)$iter, $user) : '';

        [$content, $preview] = self::asUser($user ?: null, static function () use ($run, $gate, $reviews, $pieces, $artifacts, $iterLabel): array {
            $names = array_map(static fn($k) => '« ' . $pieces[$k]['name'] . ' »', $reviews);
            $content = '⏸ **' . t('Validation — {titre}', ['titre' => $gate['title']]) . '**' . ($iterLabel !== '' ? ' · ' . $iterLabel : '') . "\n\n"
                . ($names !== [] ? t('Relisez {pieces} avant la suite.', ['pieces' => implode(', ', $names)]) : t('Votre accord est attendu avant la suite.'))
                // La note cite le dossier : noms des pièces, valeurs des champs.
                . ($gate['note'] !== '' ? "\n\n" . WorkflowDeliveries::fill($gate['note'], $pieces, $artifacts, false) : '')
                . "\n\n[" . t('Ouvrir la relecture') . '](/workflows/runs/' . $run['id'] . ')';
            $first = $reviews[0] ?? null;
            $impacts = [t('Approuver : le workflow continue.'), t('Annuler : le workflow s’arrête.')];
            // Une création (image, page, carte) ne se corrige pas à la main :
            // elle se renvoie avec une remarque.
            $correctable = $gate['can_correct']
                && array_filter($reviews, static fn($k): bool => WorkflowSpec::isCorrectable($pieces[$k])) !== [];
            if ($correctable) {
                $impacts[] = t('Pour corriger la pièce ou renvoyer l’étape avec une remarque : « Ajuster ».');
            } elseif ($gate['can_return']) {
                $impacts[] = t('Pour renvoyer l’étape avec une remarque : « Ajuster ».');
            }
            $preview = [
                'action'       => t('Valider une étape du workflow'),
                'fichier'      => $gate['title'] . ($iterLabel !== '' ? ' · ' . $iterLabel : ''),
                'apercu'       => $first !== null ? ArtifactContract::render($pieces[$first], $artifacts[$first]['content'], 3000) : '',
                'apercu_titre' => $first !== null ? $pieces[$first]['name'] : t('Aperçu'),
                'impacts'      => $impacts,
                'icone'        => '/assets/img/workflow.svg',
                'ajuster'      => '/workflows/runs/' . $run['id'],
            ];
            return [$content, $preview];
        });

        $msg = Message::create($convId, 'assistant', $content);
        Conversation::touch($convId);
        Conversation::markUnread($convId);
        $actionId = DrivePendingAction::create((int)$run['user_id'], $convId, self::GATE_TOOL, [
            'run_id' => $run['id'], 'node_key' => $gateKey,
        ], $preview);
        DrivePendingAction::extend($actionId, (int)$run['user_id'], (int)$gate['days'] * 24 * 60);
        DrivePendingAction::attachToMessage([$actionId], (int)$msg['id'], (int)$run['user_id']);
        $stepId = WorkflowRun::insertStep($run['id'], $gateKey, WorkflowRun::STEP_WAITING, ['reviews' => $reviews]);
        WorkflowRun::updateStep($stepId, ['pending_action_id' => $actionId, 'message_id' => (int)$msg['id']]);
        WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $gateKey);
        WorkflowNotifier::notify($run, WorkflowNotifier::GATE, $gate['title']);
        return true;
    }

    /** « Si MIA hésite → me demander » : une validation dont la décision tient lieu de réponse. */
    private static function openDoubt(array $run, array $node, int $stepId, string $reason, ?string $passKey = null): void
    {
        $passKey ??= $node['key'];
        $user = User::findById($run['user_id']);
        $convId = (int)$run['conversation_id'];
        $preview = self::asUser($user ?: null, static fn(): array => [
            'action'       => t('Trancher une condition du workflow'),
            'fichier'      => $node['question'] !== '' ? WorkflowSpec::renderMentions($node['question'], WorkflowSpec::pieces($run['definition'])) : $node['title'],
            'apercu'       => $reason,
            'apercu_titre' => t('Ce que MIA a vu'),
            'impacts'      => [t('Approuver : la réponse est « oui », le workflow continue sur ce chemin.'), t('Annuler : la réponse est « non ».')],
            'icone'        => '/assets/img/workflow.svg',
            'ajuster'      => '/workflows/runs/' . $run['id'],
        ]);
        $msg = Message::create($convId, 'assistant', '⏸ ' . self::asUser($user ?: null, static fn(): string => t('MIA hésite : à vous de trancher « {titre} ».', ['titre' => $node['title']])));
        $actionId = DrivePendingAction::create((int)$run['user_id'], $convId, self::GATE_TOOL, [
            'run_id' => $run['id'], 'node_key' => $node['key'], 'doubt' => true,
        ], $preview);
        DrivePendingAction::extend($actionId, (int)$run['user_id'], WorkflowSpec::GATE_DAYS_DEFAULT * 24 * 60);
        DrivePendingAction::attachToMessage([$actionId], (int)$msg['id'], (int)$run['user_id']);
        WorkflowRun::updateStep($stepId, ['pending_action_id' => $actionId]);
        WorkflowRun::setState($run['id'], WorkflowRun::WAITING, $node['key']);
        WorkflowNotifier::notify($run, WorkflowNotifier::GATE, $node['title']);
    }

    /**
     * Une carte a été tranchée dans une conversation d'exécution (chat ou page
     * de l'exécution). Validation : décidée par le statut de la carte
     * (exécutée = approuvée, annulée = arrêt) ; carte d'une action ou d'une
     * livraison : la brique reprend quand plus aucune n'attend.
     */
    public static function onCardDecided(int $convId, int $actionId, bool $inline = false): void
    {
        try {
            $run = WorkflowRun::forConversation($convId);
            if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
                return;
            }
            $card = DrivePendingAction::statusOf($actionId);
            if ($card === null || in_array($card['status'], ['pending', 'processing'], true)) {
                return;
            }
            $jobs = [];
            $gateStep = WorkflowRun::stepByAction($actionId);
            if ($gateStep !== null && $gateStep['run_id'] === $run['id'] && $gateStep['status'] === WorkflowRun::STEP_WAITING) {
                $outcome = $card['status'] === 'executed' ? 'approved' : 'stopped';
                $jobs = self::gateResolved($run, $gateStep, $outcome);
            } elseif ($card['message_id'] !== null) {
                foreach (WorkflowRun::steps($run['id']) as $s) {
                    if ($s['status'] === WorkflowRun::STEP_WAITING && $s['message_id'] === $card['message_id']
                        && DrivePendingAction::pendingIdsForMessage((int)$card['message_id'], $run['user_id']) === []) {
                        $jobs = array_merge($jobs, self::resumeAfterCards($run, $s['id']));
                    }
                }
            }
            foreach ($jobs as $id) {
                JobQueue::dispatch($id, $inline);
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] décision de la carte #' . $actionId . ' : ' . $e->getMessage());
        }
    }

    /** Plus aucune carte n'attend une brique (ou le livrable) : la suite. */
    private static function resumeAfterCards(array $run, int $stepId): array
    {
        $s = self::stepById($run['id'], $stepId);
        if ($s === null || !WorkflowRun::transitionStep($s['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, 'done')) {
            return [];
        }
        if ($s['node_key'] === WorkflowSpec::END_KEY) {
            self::finishRun(WorkflowRun::find($run['id']) ?? $run);
            return [];
        }
        $node = self::nodeFor($run['definition'], $s['node_key']);
        if ($node === null) {
            return [];
        }
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $s['node_key']);
        [, $iter] = WorkflowLoop::splitIter($s['node_key']);
        return self::afterStepDone($run, $node, $iter);
    }

    /**
     * Issue d'une validation : approved | stopped | returned (avec remarque).
     * Rend les jobs enfilés.
     */
    public static function gateResolved(array $run, array $step, string $outcome, string $remark = ''): array
    {
        if (str_ends_with($step['node_key'], self::LIST_SUFFIX) || str_ends_with($step['node_key'], self::ACTIONS_SUFFIX)) {
            // Relecture d'une boucle depuis la carte du chat : Approuver = tout,
            // Annuler = arrêter (liste) ou n'en exécuter aucune (actions).
            $isList = str_ends_with($step['node_key'], self::LIST_SUFFIX);
            return self::loopGateResolved($run, $step, $outcome === 'approved' ? 'approved' : ($isList ? 'stopped' : 'none'));
        }
        [$baseKey, $iter] = WorkflowLoop::splitIter($step['node_key']);
        $branching = str_ends_with($baseKey, self::REVIEW_SUFFIX) ? null : WorkflowSpec::node($run['definition'], $baseKey);
        if ($branching !== null && $branching['type'] === WorkflowSpec::TYPE_ROUTE) {
            return self::routeResolved($run, $step, $branching, $outcome);
        }
        $doubt = $branching !== null && $branching['type'] === WorkflowSpec::TYPE_CONDITION;
        if ($doubt) {
            $answer = $outcome === 'approved' ? 'yes' : 'no';
            if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, $answer, $step['detail'] + ['decided_by' => 'person'])) {
                return [];
            }
            WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $step['node_key']);
            return self::advanceFrom($run['id'], $step['node_key'], $answer);
        }

        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, $outcome, $step['detail'] + ($remark !== '' ? ['remark' => $remark] : []))) {
            return [];
        }
        $user = User::findById($run['user_id']);
        if ($outcome === 'stopped') {
            WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, $step['node_key']);
            $gate = self::nodeFor($run['definition'], $step['node_key']);
            self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté à la validation « {titre} ».', ['titre' => (string)($gate['title'] ?? '')])));
            return [];
        }
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $step['node_key']);
        if ($outcome === 'returned') {
            $gate = self::nodeFor($run['definition'], $step['node_key']);
            $target = $gate !== null ? WorkflowSpec::returnTarget($run['definition'], $gate) : null;
            $node = $target !== null ? WorkflowSpec::node($run['definition'], $target) : null;
            if ($node === null) {
                self::fail($run, $step['node_key'], ['kind' => 'generic', 'message' => t('Aucune étape à refaire avant cette validation.')]);
                return [];
            }
            // Dans une boucle, l'étape refaite est celle du même tour.
            $targetIter = (WorkflowSpec::locate($run['definition'], $target)['lane'] ?? '') === 'body' ? $iter : null;
            WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, WorkflowLoop::iterKey($target, $targetIter));
            return self::startWork(WorkflowRun::find($run['id']) ?? $run, $node, ['remark' => $remark, 'iter' => $targetIter]);
        }
        return self::advanceFrom($run['id'], $step['node_key'], '');
    }

    // ==================================================================
    // Fins
    // ==================================================================

    /**
     * Le livrable : son message dans la conversation, puis — s'il y en a —
     * les livraisons, menées dans un job `workflow` (WorkflowDeliveries).
     */
    private static function deliver(array $run): array
    {
        $def = $run['definition'];
        $user = User::findById($run['user_id']);
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = self::artifactsFor($run, null, null);
        $content = self::asUser($user ?: null, static function () use ($run, $def, $pieces, $artifacts): string {
            $out = '🏁 **' . t('Livrable — {titre}', ['titre' => $def['end']['title']]) . '**';
            if ($def['end']['note'] !== '') {
                $out .= "\n\n" . WorkflowDeliveries::fill($def['end']['note'], $pieces, $artifacts, false);
            }
            foreach ($def['end']['pieces'] as $k) {
                if (!isset($artifacts[$k], $pieces[$k])) {
                    continue;
                }
                $p = $pieces[$k];
                $a = $artifacts[$k];
                $out .= "\n\n### " . $p['name'];
                $download = '/workflows/runs/' . $run['id'] . '/pieces/' . rawurlencode($k) . '/download';
                // Image, page, carte : ce qui a été créé, et son fichier.
                if (isset(WorkflowSpec::CREATE_KINDS[$p['form']])) {
                    $out .= "\n\n" . WorkflowCreations::deliverable($p, $a['content'], $download);
                    continue;
                }
                if (in_array($p['form'], ['document', 'file', 'table'], true)) {
                    $out .= "\n" . ArtifactContract::summary($p, $a['content']) . ' — [' . t('Télécharger') . '](' . $download . ')';
                }
                if ($p['form'] !== 'document' && $p['form'] !== 'file') {
                    $out .= "\n\n" . ArtifactContract::render($p, $a['content'], 6000);
                }
            }
            if ($def['end']['deliveries'] !== []) {
                $out .= "\n\n_" . I18n::plural(count($def['end']['deliveries']), 'Livraison en cours…', '{n} livraisons en cours…') . '_';
            }
            return $out;
        });
        $convId = (int)$run['conversation_id'];
        $msg = Message::create($convId, 'assistant', $content);
        Conversation::touch($convId);
        if ($def['end']['deliveries'] === []) {
            $stepId = WorkflowRun::insertStep($run['id'], WorkflowSpec::END_KEY, WorkflowRun::STEP_DONE);
            WorkflowRun::updateStep($stepId, ['finished' => true, 'message_id' => (int)$msg['id']]);
            self::finishRun($run);
            return [];
        }
        return self::startDeliveries($run, (int)$msg['id'], []);
    }

    /** Enfile le job de livraison. `$results` : issues déjà connues (reprise). */
    private static function startDeliveries(array $run, int $messageId, array $results): array
    {
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, WorkflowSpec::END_KEY);
        $stepId = WorkflowRun::insertStep($run['id'], WorkflowSpec::END_KEY, WorkflowRun::STEP_RUNNING, ['deliveries' => $results, 'deliverable_id' => $messageId]);
        $jobId = JobQueue::enqueue('workflow', $run['user_id'], (int)$run['conversation_id'], [
            'op' => 'deliver', 'run_id' => $run['id'], 'step_id' => $stepId,
            'workflow' => ['scheduled' => !in_array($run['trigger_kind'], ['manual', 'chat'], true)],
        ], mb_substr(t('Livraison') . ' — ' . $run['definition']['end']['title'], 0, 60));
        WorkflowRun::updateStep($stepId, ['job_id' => $jobId, 'message_id' => $messageId]);
        return [$jobId];
    }

    /**
     * Job `workflow` de livraison : chaque livraison pas encore faite est
     * menée (WorkflowDeliveries::execute) ; les cartes à approuver font
     * attendre le livrable, une panne l'arrête (reprenable : seules les
     * livraisons en échec sont refaites).
     */
    public static function runDeliverJob(array $payload): array
    {
        $run = WorkflowRun::find((int)($payload['run_id'] ?? 0));
        if ($run === null || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return ['skipped' => true];
        }
        $step = self::stepById($run['id'], (int)($payload['step_id'] ?? 0));
        if ($step === null || $step['status'] !== WorkflowRun::STEP_RUNNING || $step['node_key'] !== WorkflowSpec::END_KEY) {
            return ['skipped' => true];
        }
        $user = User::findById($run['user_id']);
        if (!$user || empty($user['is_active'])) {
            WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'error');
            self::fail($run, WorkflowSpec::END_KEY, ['kind' => 'generic', 'message' => t('Le compte qui a lancé le workflow n’est plus actif.')]);
            return ['failed' => true];
        }
        $messageId = (int)($step['detail']['deliverable_id'] ?? $step['message_id'] ?? 0);
        $results = (array)($step['detail']['deliveries'] ?? []);
        $locale = I18n::locale();
        I18n::use(I18n::forUser($user));
        try {
            foreach ($run['definition']['end']['deliveries'] as $i => $d) {
                $prev = $results[$i]['status'] ?? '';
                if (in_array($prev, ['done', 'card', 'skipped'], true)) {
                    continue;
                }
                $results[$i] = WorkflowDeliveries::execute($run, $user, $d, (int)$i, $messageId);
                WorkflowRun::updateStep($step['id'], ['detail' => ['deliveries' => $results] + $step['detail']]);
            }
        } finally {
            I18n::use($locale);
        }
        $detail = ['deliveries' => $results] + $step['detail'];
        $failed = array_filter($results, static fn($r) => ($r['status'] ?? '') === 'failed');
        $cards = array_filter($results, static fn($r) => ($r['status'] ?? '') === 'card');
        if ($failed !== []) {
            if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_FAILED, 'error', $detail)) {
                $first = reset($failed);
                self::fail($run, WorkflowSpec::END_KEY, [
                    'kind' => 'delivery', 'title' => self::asUser($user, static fn(): string => I18n::plural(count($failed), 'Une livraison n’a pas abouti', '{n} livraisons n’ont pas abouti')),
                    'message' => (string)($first['error'] ?? ''),
                ], false);
            }
            return ['failed' => count($failed)];
        }
        if ($cards !== [] && $messageId > 0 && DrivePendingAction::pendingIdsForMessage($messageId, $run['user_id']) !== []) {
            if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_WAITING, 'cards', $detail)) {
                WorkflowRun::setState($run['id'], WorkflowRun::WAITING, WorkflowSpec::END_KEY);
                WorkflowNotifier::notify($run, WorkflowNotifier::CARDS, (string)$run['definition']['end']['title'], count($cards));
            }
            return ['cards' => count($cards)];
        }
        if (WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_DONE, 'done', $detail)) {
            self::finishRun(WorkflowRun::find($run['id']) ?? $run);
        }
        return ['done' => true];
    }

    /** L'exécution est allée au bout : terminée, la personne prévenue, les workflows qui suivent lancés. */
    private static function finishRun(array $run): void
    {
        WorkflowRun::setState($run['id'], WorkflowRun::DONE, WorkflowSpec::END_KEY);
        Conversation::markUnread((int)$run['conversation_id']);
        WorkflowNotifier::notify($run, WorkflowNotifier::DONE, (string)$run['definition']['end']['title']);
        WorkflowTriggers::afterRunDone(WorkflowRun::find($run['id']) ?? $run);
    }

    private static function stopByBranch(array $run, string $passKey): void
    {
        [$condKey] = WorkflowLoop::splitIter($passKey);
        $node = $condKey !== '' ? WorkflowSpec::node($run['definition'], $condKey) : null;
        $at = $condKey !== '' ? WorkflowSpec::locate($run['definition'], $condKey) : null;
        $cond = $node;
        $lane = '';
        if ($at !== null && ($at['in'] ?? '') === 'lane') {
            $cond = $run['definition']['nodes'][$at['condIndex']];
            $lane = $at['lane'];
        } elseif ($node !== null && WorkflowSpec::isBranching($node)) {
            $last = WorkflowRun::lastStep($run['id'], $passKey);
            $lane = (string)($last['outcome'] ?? '');
        }
        $user = User::findById($run['user_id']);
        self::post($run, self::asUser($user ?: null, static fn(): string => '⏹ ' . t('Le workflow s’arrête ici : voie « {voie} » de « {titre} ».', [
            'voie' => $cond !== null ? WorkflowSpec::laneLabel($cond, $lane) : $lane, 'titre' => (string)($cond['title'] ?? ''),
        ])));
        WorkflowRun::setState($run['id'], WorkflowRun::STOPPED, $condKey);
        WorkflowNotifier::notify($run, WorkflowNotifier::STOPPED, (string)($cond['title'] ?? ''));
    }

    /** Échec d'une brique : l'exécution s'arrête dessus, reprenable. */
    private static function fail(array $run, string $key, array $error, bool $writeMessage = true): void
    {
        $user = User::findById($run['user_id']);
        $message = trim((string)($error['message'] ?? ''));
        $title = trim((string)($error['title'] ?? ''));
        $state = ['kind' => (string)($error['kind'] ?? 'generic'), 'message' => $message];
        if ($title !== '') {
            $state['title'] = $title;
        }
        WorkflowRun::setState($run['id'], WorkflowRun::FAILED, $key, $state);
        if ($writeMessage) {
            self::post($run, '⚠️ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté : {cause}', ['cause' => $message !== '' ? $message : t('cause inconnue')])));
        }
        // La notification part écran verrouillé : le titre suffit quand il existe.
        WorkflowNotifier::notify($run, WorkflowNotifier::FAILED, $title !== '' ? $title : $message);
    }

    /**
     * Cause d'un tour en erreur, relue dans le message ⚠️ qu'il a persisté
     * (forme de LlmErrorPresenter::toPlainText : « ⚠️ Titre », ligne vide,
     * message ; ou « ⚠️ message » seul). Null si le texte n'en est pas un.
     *
     * @return array{kind:string,title:string,message:string}|null
     */
    public static function errorFromReply(string $text): ?array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if (!str_starts_with($text, '⚠')) {
            return null;
        }
        $text = trim((string)preg_replace('/^⚠\x{FE0F}?\s*/u', '', $text));
        if ($text === '') {
            return null;
        }
        $parts = preg_split('/\n\s*\n/u', $text, 2) ?: [$text];
        $title = count($parts) === 2 && !str_contains(trim($parts[0]), "\n") ? trim($parts[0]) : '';
        $message = trim($title !== '' ? $parts[1] : $text);

        return ['kind' => 'model', 'title' => $title, 'message' => $message];
    }

    /** Quota ou budget atteint : l'exécution se met en pause, reprenable. */
    private static function pause(array $run, string $key, array $refusal): void
    {
        $user = User::findById($run['user_id']);
        $message = trim((string)($refusal['message'] ?? ''));
        WorkflowRun::setState($run['id'], WorkflowRun::PAUSED, $key, ['kind' => (string)($refusal['kind'] ?? 'quota'), 'message' => $message]);
        self::post($run, '⏸ ' . self::asUser($user ?: null, static fn(): string => t('Exécution en pause : {cause} Reprenez-la depuis la page de l’exécution.', ['cause' => $message])));
        WorkflowNotifier::notify($run, WorkflowNotifier::PAUSED, $message);
    }

    // ==================================================================
    // Gestes de la personne
    // ==================================================================

    /**
     * Reprendre une exécution en échec ou en pause, à la brique où elle s'est
     * arrêtée. `$latest` : avec la dernière version PUBLIÉE du workflow (la
     * brique doit y exister) — c'est ainsi qu'un réglage corrigé dans
     * l'éditeur sert à l'exécution en cours.
     */
    public static function retry(array $run, bool $latest = false): array
    {
        if (!in_array($run['status'], WorkflowRun::RESUMABLE, true)) {
            throw new \InvalidArgumentException(t('Cette exécution ne peut pas être reprise.'));
        }
        $passKey = (string)$run['current_key'];
        [$key, $iter] = WorkflowLoop::splitIter($passKey);
        if ($latest) {
            $run = self::refreeze($run, $key);
        }
        $user = User::findById($run['user_id']);
        self::post($run, '↻ ' . self::asUser($user ?: null, static fn(): string => $latest
            ? t('Reprise de l’exécution avec la version {v} du workflow.', ['v' => (int)$run['version']])
            : t('Reprise de l’exécution.')));
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $passKey);
        $run = WorkflowRun::find($run['id']) ?? $run;
        if ($key === WorkflowSpec::END_KEY) {
            return self::redeliver($run);
        }
        if (str_ends_with($key, self::LIST_SUFFIX) || str_ends_with($key, self::ACTIONS_SUFFIX)) {
            $loop = WorkflowSpec::node($run['definition'], substr($key, 0, -strlen(str_ends_with($key, self::LIST_SUFFIX) ? self::LIST_SUFFIX : self::ACTIONS_SUFFIX)));
            if ($loop !== null) {
                self::openLoopGate($run, $loop, str_ends_with($key, self::LIST_SUFFIX) ? self::LIST_SUFFIX : self::ACTIONS_SUFFIX);
            }
            return [];
        }
        if (str_ends_with($key, self::REVIEW_SUFFIX) || (WorkflowSpec::node($run['definition'], $key)['type'] ?? '') === WorkflowSpec::TYPE_GATE) {
            self::openGate($run, $passKey);
            return [];
        }
        if ($key === '' || $key === WorkflowSpec::START_KEY) {
            return self::advanceFrom($run['id'], WorkflowSpec::START_KEY, '');
        }
        $node = WorkflowSpec::node($run['definition'], $key);
        if ($node !== null && $node['type'] === WorkflowSpec::TYPE_LOOP) {
            return self::resumeLoop($run, $node);
        }
        // Une étape renvoyée avec une remarque puis tombée en échec repart AVEC
        // sa remarque : la reprise refait le travail demandé, pas l'ancien.
        return self::enter($run, $key, ['iter' => $iter, 'remark' => self::pendingRemark($run['definition'], WorkflowRun::steps($run['id']), $passKey)]);
    }

    /**
     * Une boucle arrêtée reprend où elle en était : la condition de sortie
     * réévaluée (jusqu'à) ; la lecture des éléments refaite ; après une
     * pause pour échecs d'affilée (ou un tour interrompu), l'élément refait
     * puis les suivants (pour chaque).
     */
    private static function resumeLoop(array $run, array $loop): array
    {
        $step = WorkflowRun::lastStep($run['id'], $loop['key']);
        if ($step !== null && $step['status'] === WorkflowRun::STEP_RUNNING) {
            $i = (int)($step['detail']['i'] ?? 0);
            if ($loop['mode'] === WorkflowSpec::LOOP_EACH) {
                if (($step['detail']['phase'] ?? '') === 'split' || !isset($step['detail']['count'])) {
                    WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED, 'retried');
                    return self::startLoop($run, $loop);
                }
                $at = (int)($step['detail']['paused_at'] ?? $i);
                if ($at > 0) {
                    $detail = $step['detail'];
                    if (isset($detail['st'][$at - 1])) {
                        $detail['st'][$at - 1] = '.';
                    }
                    unset($detail['fail'][(string)$at], $detail['paused_at']);
                    $detail['streak'] = 0;
                    WorkflowRun::updateStep($step['id'], ['detail' => $detail]);
                    return self::startIteration($run, $loop, $at);
                }
                return self::finishLoop($run, $loop, 0, false);
            }
            if ($i > 0) {
                return self::iterationDone($run, $loop['key'], $i);
            }
        }
        return self::enter($run, $loop['key']);
    }

    /** Les livraisons en échec sont refaites ; les autres gardent leur issue. */
    private static function redeliver(array $run): array
    {
        $last = WorkflowRun::lastStep($run['id'], WorkflowSpec::END_KEY);
        $results = [];
        foreach ((array)($last['detail']['deliveries'] ?? []) as $i => $r) {
            if (in_array($r['status'] ?? '', ['done', 'card', 'skipped'], true)) {
                $results[$i] = $r;
            }
        }
        $messageId = (int)($last['detail']['deliverable_id'] ?? $last['message_id'] ?? 0);
        if ($messageId <= 0) {
            return self::deliver($run);
        }
        return self::startDeliveries($run, $messageId, $results);
    }

    /**
     * Ignorer la brique en échec et continuer : sans sa pièce (une étape),
     * sans ses livraisons (le livrable). Une condition, une validation ou une
     * boucle ne s'ignorent pas — leur issue décide de la suite.
     */
    public static function skip(array $run): array
    {
        if (!in_array($run['status'], WorkflowRun::RESUMABLE, true)) {
            throw new \InvalidArgumentException(t('Cette exécution n’est pas arrêtée sur une brique.'));
        }
        $passKey = (string)$run['current_key'];
        [$key] = WorkflowLoop::splitIter($passKey);
        $user = User::findById($run['user_id']);
        if ($key === WorkflowSpec::END_KEY) {
            $stepId = WorkflowRun::insertStep($run['id'], WorkflowSpec::END_KEY, WorkflowRun::STEP_DONE, ['skipped_by' => 'person']);
            WorkflowRun::updateStep($stepId, ['finished' => true, 'outcome' => 'skipped']);
            self::post($run, '⤼ ' . self::asUser($user ?: null, static fn(): string => t('Livraisons en échec abandonnées : le workflow est terminé.')));
            self::finishRun($run);
            return [];
        }
        $node = WorkflowSpec::node($run['definition'], $key);
        if ($node === null || !in_array($node['type'], [WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION, WorkflowSpec::TYPE_CREATE, WorkflowSpec::TYPE_WAIT, WorkflowSpec::TYPE_NOTIFY], true)) {
            throw new \InvalidArgumentException(t('Cette brique ne peut pas être ignorée : son issue décide de la suite.'));
        }
        $stepId = WorkflowRun::insertStep($run['id'], $passKey, WorkflowRun::STEP_DONE, ['skipped_by' => 'person']);
        WorkflowRun::updateStep($stepId, ['finished' => true, 'outcome' => 'skipped']);
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $passKey);
        self::post($run, '⤼ ' . self::asUser($user ?: null, static fn(): string => t('« {titre} » ignorée : le workflow continue sans elle.', ['titre' => $node['title']])));
        return self::advanceFrom($run['id'], $passKey, '');
    }

    /**
     * Refaire depuis une brique du rail (ou depuis le départ) : les pièces
     * remises à partir de cette brique sont retirées du dossier, puis elle
     * repart. Possible sur une exécution arrêtée, en échec, en pause ou
     * terminée — jamais pendant qu'elle tourne ou attend une décision.
     */
    public static function restartFrom(array $run, string $key, bool $latest = false): array
    {
        if (in_array($run['status'], [WorkflowRun::RUNNING, WorkflowRun::WAITING], true)) {
            throw new \InvalidArgumentException(t('Arrêtez d’abord l’exécution en cours.'));
        }
        if ($latest) {
            $run = self::refreeze($run, $key);
        }
        $def = $run['definition'];
        $main = $def['nodes'];
        $index = null;
        foreach ($main as $i => $n) {
            if ($n['key'] === $key) {
                $index = $i;
            }
        }
        if ($key !== WorkflowSpec::START_KEY && $index === null) {
            throw new \InvalidArgumentException(t('Choisissez une brique du rail principal.'));
        }
        // Pièces remises à partir de la brique : celles des briques du rail qui
        // suivent, de leurs voies et de leurs corps de boucle (tours compris).
        $producers = [];
        foreach (array_slice($main, $index ?? 0) as $n) {
            $producers[$n['key']] = true;
            foreach (WorkflowSpec::branchesOf($n) as $l) {
                foreach ($l['nodes'] as $c) {
                    $producers[$c['key']] = true;
                }
            }
            foreach ($n['body'] ?? [] as $c) {
                $producers[$c['key']] = true;
            }
        }
        $removed = 0;
        foreach (WorkflowRun::artifacts($run['id']) as $artKey => $a) {
            if (isset($producers[$a['node_key']])) {
                WorkflowRun::deleteArtifact($run['id'], (string)$artKey);
                $removed++;
            }
        }
        $user = User::findById($run['user_id']);
        $title = $key === WorkflowSpec::START_KEY ? t('Départ') : (string)$main[$index]['title'];
        self::post($run, '↻ ' . self::asUser($user ?: null, static fn(): string => t('L’exécution repart de « {titre} ».', ['titre' => $title])
            . ($latest ? ' ' . t('Version {v} du workflow.', ['v' => (int)$run['version']]) : '')));
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $key);
        $run = WorkflowRun::find($run['id']) ?? $run;
        if ($key === WorkflowSpec::START_KEY) {
            return self::advanceFrom($run['id'], WorkflowSpec::START_KEY, '');
        }
        return self::enter($run, $key);
    }

    /** L'exécution passe à la dernière version publiée (la brique `$key` doit y exister). */
    private static function refreeze(array $run, string $key): array
    {
        $wf = Workflow::findById($run['workflow_id']);
        if ($wf === null || $wf['published'] === null) {
            throw new \InvalidArgumentException(t('Le workflow n’a plus de version publiée.'));
        }
        if ((int)$wf['published_version'] === (int)$run['version']) {
            return $run;
        }
        $def = $wf['published'];
        if (!in_array($key, ['', WorkflowSpec::START_KEY, WorkflowSpec::END_KEY], true)
            && self::nodeFor($def, $key) === null) {
            throw new \InvalidArgumentException(t('Cette brique n’existe plus dans la version publiée : reprenez depuis une autre brique.'));
        }
        WorkflowRun::refreeze($run['id'], $def, (int)$wf['published_version']);
        return WorkflowRun::find($run['id']) ?? $run;
    }

    /** « Ne plus attendre » / « Réessayer maintenant » : un minuteur en cours reprend tout de suite. */
    public static function resumeNow(array $run): array
    {
        $step = WorkflowRun::lastStep($run['id'], (string)$run['current_key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_WAITING || empty($step['detail']['resume'])) {
            throw new \InvalidArgumentException(t('Rien n’attend une heure dans cette exécution.'));
        }
        return self::resumeTimer($run, $step);
    }

    /**
     * Remarque de relecture encore à honorer pour la brique $key : celle du
     * dernier renvoi vers elle, tant qu'aucun passage n'a abouti depuis.
     * Passages lus dans l'ordre (WorkflowRun::steps). Les attentes d'un
     * nouvel essai automatique ne comptent pas comme un passage.
     */
    public static function pendingRemark(array $def, array $steps, string $key): string
    {
        [$base, $iter] = WorkflowLoop::splitIter($key);
        $remark = '';
        foreach ($steps as $s) {
            $detail = is_array($s['detail'] ?? null) ? $s['detail'] : [];
            if (($detail['kind'] ?? '') === 'retry') {
                continue;
            }
            if ($s['node_key'] === $key) {
                $remark = $s['status'] === WorkflowRun::STEP_DONE ? '' : (string)($detail['remark'] ?? $remark);
                continue;
            }
            if (($s['outcome'] ?? '') === 'returned') {
                [$gateBase, $gateIter] = WorkflowLoop::splitIter((string)$s['node_key']);
                $gate = self::nodeFor($def, (string)$s['node_key']);
                if ($gate !== null && WorkflowSpec::returnTarget($def, $gate) === $base && $gateIter === $iter) {
                    $remark = (string)($detail['remark'] ?? '');
                }
            }
        }
        return $remark;
    }

    /** Arrêter une exécution : job en cours annulé, carte en attente close. */
    public static function cancel(array $run): void
    {
        if (in_array($run['status'], WorkflowRun::TERMINAL, true)) {
            return;
        }
        foreach (WorkflowRun::steps($run['id']) as $s) {
            if ($s['status'] === WorkflowRun::STEP_RUNNING) {
                if ($s['job_id'] !== null) {
                    JobQueue::requestCancel($s['job_id'], $run['user_id']);
                }
                // Close tout de suite : l'exécution est terminale, la fin du
                // job ne repassera plus par ici (onJobFinished s'arrête) et
                // l'étape resterait « en cours » sur le rail.
                WorkflowRun::transitionStep($s['id'], WorkflowRun::STEP_RUNNING, WorkflowRun::STEP_CANCELLED, 'cancelled');
            }
            if ($s['status'] === WorkflowRun::STEP_WAITING) {
                WorkflowRun::transitionStep($s['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_CANCELLED, 'cancelled');
                if ($s['pending_action_id'] !== null) {
                    DrivePendingAction::abandon($s['pending_action_id'], 'Workflow arrêté.');
                }
                if ($s['message_id'] !== null) {
                    foreach (DrivePendingAction::pendingIdsForMessage($s['message_id'], $run['user_id']) as $actionId) {
                        DrivePendingAction::abandon($actionId, 'Workflow arrêté.');
                    }
                }
            }
        }
        WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, (string)$run['current_key']);
        $user = User::findById($run['user_id']);
        self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté par vous.')));
    }

    /**
     * Entretien (worker, et sans worker JobQueue::maybeMaintain) :
     *  - une exécution qui attend une carte EXPIRÉE ne repartira jamais —
     *    elle est close avec sa cause plutôt que laissée « en attente » ;
     *  - une brique dont le job est fini sans que la suite ait eu lieu (fin
     *    de job perdue) est reprise ;
     *  - une exécution « en cours » sans plus aucune brique active (process
     *    abattu entre deux briques) est arrêtée, reprenable.
     */
    public static function maintain(): int
    {
        $closed = 0;
        try {
            $stmt = Database::getInstance()->query(
                "SELECT s.id, s.run_id, s.node_key, s.pending_action_id, s.message_id
                 FROM workflow_run_steps s JOIN workflow_runs r ON r.id = s.run_id
                 WHERE s.status = 'waiting' AND s.resume_at IS NULL AND r.status = 'waiting' LIMIT 50"
            );
            foreach ($stmt->fetchAll() as $row) {
                $actionId = $row['pending_action_id'] !== null ? (int)$row['pending_action_id'] : 0;
                $expired = false;
                if ($actionId > 0) {
                    $expired = (DrivePendingAction::statusOf($actionId)['status'] ?? '') === 'expired';
                } elseif ($row['message_id'] !== null) {
                    $run = WorkflowRun::find((int)$row['run_id']);
                    if ($run === null || DrivePendingAction::pendingIdsForMessage((int)$row['message_id'], $run['user_id']) !== []) {
                        continue;
                    }
                    if (DrivePendingAction::expiredIdsForMessage((int)$row['message_id'], $run['user_id']) === []) {
                        // Toutes tranchées, mais la suite n'a pas eu lieu (décision
                        // perdue) : elle a lieu maintenant.
                        foreach (self::resumeAfterCards($run, (int)$row['id']) as $jobId) {
                            JobQueue::dispatch($jobId, false);
                        }
                        continue;
                    }
                    $expired = true;
                }
                if (!$expired || !WorkflowRun::transitionStep((int)$row['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_CANCELLED, 'expired')) {
                    continue;
                }
                $run = WorkflowRun::find((int)$row['run_id']);
                if ($run === null) {
                    continue;
                }
                if ($actionId > 0) {
                    DrivePendingAction::abandon($actionId, 'Expirée.');
                }
                WorkflowRun::setState($run['id'], WorkflowRun::CANCELLED, (string)$row['node_key']);
                $user = User::findById($run['user_id']);
                self::post($run, '⏹ ' . self::asUser($user ?: null, static fn(): string => t('Workflow arrêté : la décision attendue n’a pas été prise à temps.')));
                $closed++;
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] entretien : ' . $e->getMessage());
        }
        $closed += self::recoverLostEnds();
        $closed += self::recoverOrphans();
        return $closed;
    }

    /** Une brique en cours dont le job est terminé depuis un moment : sa fin est rejouée. */
    private static function recoverLostEnds(): int
    {
        $n = 0;
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT j.id, j.status, j.result_json, j.error_json
                 FROM workflow_run_steps s
                 JOIN workflow_runs r ON r.id = s.run_id
                 JOIN async_jobs j ON j.id = s.job_id
                 WHERE s.status = 'running' AND r.status IN ('running','waiting')
                   AND j.status IN ('done','failed','cancelled') AND j.finished_at < (NOW() - INTERVAL " . self::STUCK_SEC . " SECOND)
                 LIMIT 20"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $result = $row['result_json'] !== null ? (array)json_decode((string)$row['result_json'], true) : null;
                $error = $row['error_json'] !== null ? (array)json_decode((string)$row['error_json'], true) : null;
                error_log('[Workflows] fin du job #' . (int)$row['id'] . ' rejouée par l’entretien');
                self::onJobFinished((int)$row['id'], (string)$row['status'], $result, $error);
                $n++;
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] reprise des fins de job : ' . $e->getMessage());
        }
        return $n;
    }

    /** Exécution « en cours » sans brique active ni minuteur depuis ORPHAN_SEC : arrêtée, reprenable. */
    private static function recoverOrphans(): int
    {
        $n = 0;
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT r.id FROM workflow_runs r
                 WHERE r.status = 'running' AND r.updated_at < (NOW() - INTERVAL " . self::ORPHAN_SEC . " SECOND)
                   AND NOT EXISTS (SELECT 1 FROM workflow_run_steps s WHERE s.run_id = r.id AND s.status IN ('running','waiting'))
                 LIMIT 20"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $run = WorkflowRun::find((int)$id);
                if ($run === null) {
                    continue;
                }
                self::fail($run, (string)$run['current_key'], [
                    'kind' => 'interrupted', 'title' => self::asUser(User::findById($run['user_id']) ?: null, static fn(): string => t('Exécution interrompue')),
                    'message' => self::asUser(User::findById($run['user_id']) ?: null, static fn(): string => t('Le serveur s’est arrêté entre deux briques. Reprenez l’exécution : elle repart de la brique où elle en était.')),
                ]);
                $n++;
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] exécutions orphelines : ' . $e->getMessage());
        }
        return $n;
    }

    // ==================================================================
    // Départs programmés, minuteurs, surveillances
    // ==================================================================

    /**
     * Le planificateur des workflows : départs programmés échus, minuteurs
     * (attentes, nouveaux essais), surveillances de boîtes mail et de
     * dossiers (WorkflowTriggers). Appelé par le worker (sous verrou) et,
     * sans worker, par JobQueue::maybeMaintain. Rend le nombre d'exécutions
     * lancées.
     */
    public static function tick(?\DateTimeImmutable $now = null, bool $dispatch = true): int
    {
        if (!Workflow::isEnabled()) {
            return 0;
        }
        $now = $now ?? self::now();
        // Rôles relus à chaque tour (worker long-vivant) : un droit retiré
        // dans Personnes → Rôles vaut dès le départ suivant.
        Role::forget();
        $launched = 0;
        foreach (Workflow::dueIds($now, self::MAX_PER_TICK) as $id) {
            $db = Database::getInstance();
            $db->beginTransaction();
            try {
                $wf = Workflow::lockDue($id, $now);
                if ($wf === null) {
                    $db->rollBack();
                    continue;
                }
                $slot = ScheduledTask::fromSqlUtc((string)$wf['schedule_next_at']);
                $next = $wf['published'] !== null ? Workflow::scheduleNext($wf['published'], $now) : null;
                Workflow::setScheduleNext($id, $next);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[Workflows] planification #' . $id . ' : ' . $e->getMessage());
                continue;
            }
            if ($slot === null || $now->getTimestamp() - $slot->getTimestamp() > self::CATCH_UP_SEC) {
                continue;
            }
            $user = User::findById($wf['user_id']);
            if (!$user) {
                continue;
            }
            try {
                $res = self::launch($wf, $user, [], [], 'schedule', $slot, (string)($wf['published']['start']['schedule']['timezone'] ?? ''));
                if ($res !== null) {
                    $launched++;
                    if ($dispatch) {
                        foreach ($res['job_ids'] as $jobId) {
                            JobQueue::dispatch($jobId, false);
                        }
                    }
                }
            } catch (\InvalidArgumentException $e) {
                error_log('[Workflows] exécution programmée de #' . $id . ' non lancée : ' . $e->getMessage());
            } catch (\Throwable $e) {
                error_log('[Workflows] exécution programmée de #' . $id . ' : ' . $e->getMessage());
            }
        }
        self::tickTimers($now, $dispatch);
        WorkflowTriggers::tickWatchers($now, $dispatch);
        return $launched;
    }

    /** Minuteurs échus : fin d'une attente, nouvel essai automatique. */
    public static function tickTimers(\DateTimeImmutable $now, bool $dispatch = true): int
    {
        $n = 0;
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT s.id, s.run_id FROM workflow_run_steps s JOIN workflow_runs r ON r.id = s.run_id
                 WHERE s.status = 'waiting' AND s.resume_at IS NOT NULL AND s.resume_at <= ?
                   AND r.status IN ('running','waiting')
                 ORDER BY s.resume_at ASC LIMIT 20"
            );
            $stmt->execute([ScheduledTask::sqlUtc($now)]);
            foreach ($stmt->fetchAll() as $row) {
                $run = WorkflowRun::find((int)$row['run_id']);
                $step = $run !== null ? self::stepById($run['id'], (int)$row['id']) : null;
                if ($run === null || $step === null) {
                    continue;
                }
                $jobs = self::resumeTimer($run, $step);
                $n++;
                if ($dispatch) {
                    foreach ($jobs as $jobId) {
                        JobQueue::dispatch($jobId, false);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] minuteurs : ' . $e->getMessage());
        }
        return $n;
    }

    private static function resumeTimer(array $run, array $step): array
    {
        $kind = (string)($step['detail']['kind'] ?? '');
        if (!WorkflowRun::transitionStep($step['id'], WorkflowRun::STEP_WAITING, WorkflowRun::STEP_DONE, $kind === 'retry' ? 'retried' : 'resumed')) {
            return [];
        }
        WorkflowRun::setResumeAt($step['id'], null);
        [$key, $iter] = WorkflowLoop::splitIter($step['node_key']);
        WorkflowRun::setState($run['id'], WorkflowRun::RUNNING, $step['node_key']);
        $run = WorkflowRun::find($run['id']) ?? $run;
        if ($kind === 'retry') {
            $node = WorkflowSpec::node($run['definition'], $key);
            if ($node === null) {
                return [];
            }
            $opts = ['iter' => $iter, 'auto' => (int)($step['detail']['auto'] ?? 1), 'remark' => (string)($step['detail']['remark'] ?? '')];
            if ($node['type'] === WorkflowSpec::TYPE_CONDITION) {
                return self::startCondition($run, $node, $opts);
            }
            if ($node['type'] === WorkflowSpec::TYPE_ROUTE) {
                return self::startRoute($run, $node, $opts);
            }
            return self::startWork($run, $node, $opts);
        }
        return self::advanceFrom($run['id'], $step['node_key'], '');
    }

    // ==================================================================
    // Outils
    // ==================================================================

    /**
     * Brique d'une clé de passage — y compris la validation implicite d'une
     * étape relue (`clé~review`), synthétisée depuis l'étape, et le passage
     * d'un tour de boucle (`clé#n`).
     */
    public static function nodeFor(array $def, string $key): ?array
    {
        [$key] = WorkflowLoop::splitIter($key);
        foreach ([self::LIST_SUFFIX => 'list', self::ACTIONS_SUFFIX => 'actions'] as $suffix => $kind) {
            if (str_ends_with($key, $suffix)) {
                $loop = WorkflowSpec::node($def, substr($key, 0, -strlen($suffix)));
                if ($loop === null || $loop['type'] !== WorkflowSpec::TYPE_LOOP) {
                    return null;
                }
                return [
                    'key' => $key, 'type' => WorkflowSpec::TYPE_GATE, 'loop_gate' => $kind, 'loop' => $loop['key'],
                    'title' => $kind === 'list' ? t('Relire les éléments — {titre}', ['titre' => $loop['title']]) : t('Relire les actions — {titre}', ['titre' => $loop['title']]),
                    'reviews' => [], 'returns_to' => '', 'can_correct' => false, 'can_return' => false, 'days' => WorkflowSpec::GATE_DAYS_DEFAULT, 'note' => '',
                ];
            }
        }
        if (str_ends_with($key, self::REVIEW_SUFFIX)) {
            $step = WorkflowSpec::node($def, substr($key, 0, -strlen(self::REVIEW_SUFFIX)));
            if ($step === null || !in_array($step['type'], WorkflowSpec::PRODUCING_TYPES, true)) {
                return null;
            }
            return [
                'key' => $key, 'type' => WorkflowSpec::TYPE_GATE,
                'title' => t('Relecture — {titre}', ['titre' => $step['title']]),
                'reviews' => [$step['output']['key']], 'returns_to' => $step['key'],
                // Une création ne se corrige pas à la main : elle se renvoie avec une remarque.
                'can_correct' => $step['type'] === WorkflowSpec::TYPE_STEP, 'can_return' => true, 'days' => WorkflowSpec::GATE_DAYS_DEFAULT, 'note' => '',
            ];
        }
        return WorkflowSpec::node($def, $key);
    }

    private static function stepById(int $runId, int $stepId): ?array
    {
        foreach (WorkflowRun::steps($runId) as $s) {
            if ($s['id'] === $stepId) {
                return $s;
            }
        }
        return null;
    }

    private static function post(array $run, string $content): void
    {
        $convId = (int)($run['conversation_id'] ?? 0);
        if ($convId <= 0) {
            return;
        }
        Message::create($convId, 'assistant', $content);
        Conversation::touch($convId);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Exécute `$fn` dans la langue de la personne (le worker enchaîne les comptes). */
    public static function asUser(?array $user, callable $fn): mixed
    {
        $locale = I18n::locale();
        I18n::use(I18n::forUser($user));
        try {
            return $fn();
        } finally {
            I18n::use($locale);
        }
    }
}
