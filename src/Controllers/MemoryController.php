<?php

declare(strict_types=1);

/**
 * Réglages → Mémoire (« Ce que MIA sait de vous »).
 *
 * Contrôleur mince :
 *   - la page (index) et ses réglages, en formulaire auto-enregistré
 *     (savePrefs : « Votre contexte ») ;
 *   - les gestes sur les souvenirs, en JSON (ajouter, corriger, retirer,
 *     confirmer ou refuser une proposition, tout effacer) : chaque réponse
 *     rend la liste à jour, et la page se re-rend depuis la source de vérité.
 *
 * Toute règle (thèmes, verrou, plafonds, empreintes) vit dans UserMemory.
 * La page reste ouverte quand l'administrateur a coupé la mémoire : on peut
 * toujours relire et retirer ses souvenirs.
 */
final class MemoryController
{
    public function index(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user    = Session::currentUser();
        $userId  = (int)$user['id'];
        $enabled = UserMemory::isEnabled();
        $seenAt  = isset($user['memory_seen_at']) && $user['memory_seen_at'] !== null ? (string)$user['memory_seen_at'] : null;
        $payload = self::payload($userId, $seenAt);
        $context = (string)($user['ai_user_context'] ?? '');
        $maxLen  = UserPreferences::MAX_CONTEXT_LEN;
        // Questions de MIA : leur rythme se règle ici, s'ils sont proposés.
        $questionsOffered = $enabled && MemoryQuestions::isEnabled();
        $questionFreq     = MemoryQuestions::frequencyOf($user);
        $frequencies      = MemoryQuestions::frequencies();
        $frequencyHints   = MemoryQuestions::frequencyHints();
        // Apprentissage : proposé si l'administrateur le permet (pas aux
        // comptes de démonstration, qui n'apprennent rien d'eux-mêmes).
        $learnOffered = $enabled && MemoryLearner::isEnabled() && !DemoAccount::isDemo($user);
        $learnOn      = (int)($user['memory_learn'] ?? 1) === 1;
        $success = Session::get('settings_success');
        $error   = Session::get('settings_error');
        Session::remove('settings_success');
        Session::remove('settings_error');

        // Ce qui arrivera après cette visite portera la mention « Nouveau ».
        // La page explique l'apprentissage : la visiter vaut information (la
        // carte du chat ne sera pas montrée).
        try {
            UserMemory::markSeen($userId);
            if ($learnOffered) {
                MemoryLearner::markInformed($userId);
            }
        } catch (\Throwable $e) {
            error_log('[Mémoire] visite non notée (user ' . $userId . ') : ' . $e->getMessage());
        }

        require ROOT . '/templates/settings/memory.php';
    }

    /** Formulaire auto-enregistré de la page : « Votre contexte ». */
    public function savePrefs(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $asJson = AjaxResponse::wanted();
        $userId = (int)Session::currentUser()['id'];

        // Champ par champ, seulement s'il est posté : la page peut n'en montrer
        // qu'une partie (le piège maison « champ absent = vide »).
        if (array_key_exists('memory_questions', $_POST)) {
            MemoryQuestions::saveFrequency($userId, (string)$_POST['memory_questions']);
        }
        // « Ce que MIA apprend » : deux boutons radio, postés seulement si la
        // page montrait la section — jamais lus comme un « non » par défaut.
        if (array_key_exists('memory_learn', $_POST)) {
            MemoryLearner::saveLearn($userId, (string)$_POST['memory_learn'] === '1');
        }
        if (array_key_exists('ai_user_context', $_POST)) {
            $context = UserPreferences::sanitizeContext((string)$_POST['ai_user_context']);
            if (mb_strlen($context) > UserPreferences::MAX_CONTEXT_LEN) {
                $this->respondPrefs($asJson, false, t('Le contexte ne peut pas dépasser {n} caractères.', [
                    'n' => (string)UserPreferences::MAX_CONTEXT_LEN,
                ]));
            }
            UserPreferences::save($userId, null, $context);
        }

        AuthMiddleware::refreshUser();
        $this->respondPrefs($asJson, true, t('Mémoire enregistrée.'));
    }

    public function add(): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $result = UserMemory::add($userId, (string)($_POST['content'] ?? ''), [
            'source' => UserMemory::SOURCE_MANUAL,
            'theme'  => (string)($_POST['theme'] ?? UserMemory::THEME_DEFAULT),
        ]);
        $this->respondStatus($userId, $result['status']);
    }

    public function update(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $theme  = isset($_POST['theme']) ? (string)$_POST['theme'] : null;
        $scope  = isset($_POST['scope']) ? (string)$_POST['scope'] : null;
        $result = UserMemory::update($userId, (int)$id, (string)($_POST['content'] ?? ''), $theme, true, $scope);
        $this->respondStatus($userId, $result['status']);
    }

    public function delete(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $this->respondStatus($userId, UserMemory::delete($userId, (int)$id) ? 'ok' : 'not_found');
    }

    public function confirm(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $this->respondStatus($userId, UserMemory::confirm($userId, (int)$id)['status']);
    }

    public function reject(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $this->respondStatus($userId, UserMemory::reject($userId, (int)$id) ? 'ok' : 'not_found');
    }

    /**
     * Réponse à une question de MIA (carte du chat). Aucun appel au modèle :
     * l'effet est décidé par la question elle-même (MemoryQuestions::answer).
     */
    public function answer(): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $result = MemoryQuestions::answer(
            $userId,
            (int)($_POST['id'] ?? 0),
            (string)($_POST['choice'] ?? ''),
            (string)($_POST['text'] ?? '')
        );
        $ok = $result['status'] === 'ok';
        AjaxResponse::send([
            'ok'      => $ok,
            'error'   => $ok ? null : MemoryQuestions::message($result),
            'message' => $ok ? MemoryQuestions::message($result) : null,
            'kind'    => $result['saved']['kind'] ?? null,
        ], $ok ? 200 : ($result['status'] === 'not_found' ? 404 : 422));
    }

    /** « Tout effacer » : geste explicite, confirmé par la page. */
    public function clear(): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        if (($_POST['confirm'] ?? '') !== '1') {
            $this->respondStatus($userId, 'unconfirmed');
        }
        UserMemory::clear($userId);
        // Rien de ce qui précède ne sera réappris : chaque repère avance, et
        // les gestes déjà comptés repartent de zéro.
        MemoryLearner::advanceAll($userId);
        MemorySignals::forgetUser($userId);
        $this->respondStatus($userId, 'ok');
    }

    // -----------------------------------------------------------------------
    // Charge utile partagée (page + réponses JSON)
    // -----------------------------------------------------------------------

    /**
     * Souvenirs de la page, thèmes, compteurs et place occupée dans le prompt.
     *
     * @return array<string, mixed>
     */
    public static function payload(int $userId, ?string $seenAt = null): array
    {
        $rows  = UserMemory::forPage($userId);
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::item($row, $seenAt);
        }
        $usage  = UserMemory::promptSelection($rows);
        $counts = ['active' => 0, 'proposed' => 0];
        foreach ($rows as $row) {
            $counts[(string)$row['status'] === UserMemory::STATUS_PROPOSED ? 'proposed' : 'active']++;
        }

        return [
            'memories' => $items,
            'counts'   => $counts,
            'max'      => UserMemory::MAX_ENTRIES,
            'usage'    => [
                'used'    => $usage['used'],
                'budget'  => UserMemory::MAX_PROMPT_CHARS,
                'dropped' => $usage['dropped'],
            ],
        ];
    }

    /**
     * Un souvenir tel que la page l'affiche. « Nouveau » : appris par MIA
     * depuis la dernière visite (rien la toute première fois). `reason` :
     * ce que MIA a remarqué, pour une proposition née d'un geste répété.
     *
     * @return array<string, mixed>
     */
    public static function item(array $row, ?string $seenAt): array
    {
        $origin  = UserMemory::originOf($row);
        $created = (string)($row['created_at'] ?? '');
        $convId  = isset($row['conversation_id']) ? (int)$row['conversation_id'] : 0;

        return [
            'id'           => (int)$row['id'],
            'content'      => (string)$row['content'],
            'theme'        => UserMemory::normalizeTheme((string)($row['theme'] ?? '')),
            'scope'        => UserMemory::normalizeScope((string)($row['scope'] ?? '')),
            'status'       => (string)($row['status'] ?? UserMemory::STATUS_ACTIVE) === UserMemory::STATUS_PROPOSED ? 'proposed' : 'active',
            'reason'       => MemorySignals::reason((string)($row['signal_key'] ?? '')),
            'origin'       => $origin,
            'date'         => Format::dateFr($created),
            'is_new'       => $seenAt !== null && $created !== '' && $created > $seenAt
                              && ($origin === 'appris' || (string)$row['status'] === UserMemory::STATUS_PROPOSED),
            'conversation' => $convId > 0
                ? ['id' => $convId, 'title' => Conversation::displayTitle((string)($row['conversation_title'] ?? ''))]
                : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Réponses
    // -----------------------------------------------------------------------

    private function respondStatus(int $userId, string $status): void
    {
        $error = match ($status) {
            'ok'          => null,
            'empty'       => t('Le souvenir ne peut pas être vide.'),
            'duplicate'   => t('Ce souvenir est déjà enregistré.'),
            'full'        => t('Mémoire pleine ({n} souvenirs). Retirez-en un avant d\'en ajouter.', ['n' => (string)UserMemory::MAX_ENTRIES]),
            'sensitive'   => t('Ce souvenir ressemble à une donnée sensible (mot de passe, code, numéro de carte, IBAN) : il n\'est pas enregistré.'),
            'not_found'   => t('Souvenir introuvable.'),
            'unconfirmed' => t('Confirmez l\'effacement de toute la mémoire.'),
            default       => t('Opération impossible. Réessayez.'),
        };

        AjaxResponse::send(
            ['ok' => $error === null, 'error' => $error] + self::payload($userId),
            $error === null ? 200 : ($status === 'not_found' ? 404 : 422)
        );
    }

    /**
     * Sortie du formulaire auto-enregistré : JSON pour l'auto-save, message de
     * session + redirection sinon (même transport que les autres pages de
     * Réglages, cf. UserSettingsController::respondSettings).
     */
    private function respondPrefs(bool $asJson, bool $success, string $message): void
    {
        if ($asJson) {
            if ($success) {
                AjaxResponse::ok(['message' => $message]);
            }
            AjaxResponse::fail($message);
        }
        Session::set($success ? 'settings_success' : 'settings_error', $message);
        header('Location: /settings/memory');
        exit;
    }
}
