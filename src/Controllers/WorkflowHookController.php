<?php

declare(strict_types=1);

/**
 * Adresse d'appel d'un workflow : POST /hooks/workflows/<jeton>.
 *
 * Un autre logiciel (n8n, Zapier, un formulaire, un script) lance le workflow
 * en postant du JSON (ou un formulaire) à cette adresse. Traitée AVANT la
 * session et le contrôle CSRF (public/index.php) : aucun cookie n'est lu, le
 * JETON est le secret — 32 octets aléatoires, retrouvé par son empreinte
 * SHA-256, jamais journalisé. Le workflow s'exécute au nom de son
 * propriétaire, avec ses droits, revérifiés.
 *
 * Réponses : 202 lancé (`run_id`), 200 déjà lancé (même `Idempotency-Key`),
 * 400 corps illisible, 404 adresse inconnue (même réponse pour un jeton faux
 * ou un workflow qui n'écoute pas d'appel : rien à deviner), 409 en pause,
 * 413 trop lourd, 422 champ manquant ou invalide, 429 trop d'appels.
 *
 * Les champs du départ se remplissent par clé ou par nom ; le corps entier
 * devient la pièce « Données reçues » — une DONNÉE pour les étapes, jamais
 * une consigne.
 */
final class WorkflowHookController
{
    public static function handle(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        I18n::use(I18n::instanceDefault());

        if (!Workflow::isEnabled() || !WorkflowTriggers::validHookToken($token)) {
            self::reply(404, ['ok' => false, 'error' => t('Adresse inconnue.')]);
            return;
        }
        $wf = Workflow::findByHookHash(WorkflowTriggers::hookHash($token));
        $def = $wf['published'] ?? null;
        if ($wf === null || ($def['start']['event']['kind'] ?? '') !== 'webhook') {
            self::reply(404, ['ok' => false, 'error' => t('Adresse inconnue.')]);
            return;
        }
        $user = User::findById($wf['user_id']);
        if (!$user || !Workflow::canUse($user)) {
            self::reply(404, ['ok' => false, 'error' => t('Adresse inconnue.')]);
            return;
        }
        I18n::use(I18n::forUser($user));
        if (!$wf['is_active']) {
            self::reply(409, ['ok' => false, 'error' => t('Ce workflow est en pause.')]);
            return;
        }
        if (WorkflowRun::countRecent($wf['id'], 'webhook', 3600) >= WorkflowTriggers::HOOK_PER_HOUR) {
            header('Retry-After: 600');
            self::reply(429, ['ok' => false, 'error' => t('Trop d’appels : {n} par heure au plus.', ['n' => WorkflowTriggers::HOOK_PER_HOUR])]);
            return;
        }

        $raw = (string)file_get_contents('php://input', false, null, 0, WorkflowTriggers::HOOK_MAX_BYTES + 1);
        if (strlen($raw) > WorkflowTriggers::HOOK_MAX_BYTES) {
            self::reply(413, ['ok' => false, 'error' => t('Corps trop lourd : {n} Ko au plus.', ['n' => (int)(WorkflowTriggers::HOOK_MAX_BYTES / 1024)])]);
            return;
        }
        $body = self::parseBody($raw, (string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($body === null) {
            self::reply(400, ['ok' => false, 'error' => t('Corps illisible : envoyez du JSON (un objet) ou un formulaire.')]);
            return;
        }

        $ref = null;
        $idem = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($idem !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $idem)) {
                self::reply(400, ['ok' => false, 'error' => t('En-tête Idempotency-Key invalide.')]);
                return;
            }
            $ref = 'hook:' . $idem;
            $prior = WorkflowRun::findByRef($wf['id'], $ref);
            if ($prior !== null) {
                self::reply(200, ['ok' => true, 'duplicate' => true, 'run_id' => $prior['id']]);
                return;
            }
        }

        $vals = WorkflowTriggers::hookValues($def, $body);
        try {
            $res = WorkflowEngine::launch($wf, $user, $vals['values'], [], 'webhook', null, WorkflowTriggers::timezoneOf($def), [
                'text' => $vals['text'],
                'headline' => t('Appel de l’adresse du workflow'),
                'provenance' => [t('Appel de l’adresse du workflow')],
            ], $ref);
        } catch (\InvalidArgumentException $e) {
            self::reply(422, ['ok' => false, 'error' => $e->getMessage()]);
            return;
        } catch (\Throwable $e) {
            error_log('[Workflows] appel de l’adresse du workflow #' . $wf['id'] . ' : ' . $e->getMessage());
            self::reply(500, ['ok' => false, 'error' => t('Erreur inattendue.')]);
            return;
        }
        if ($res === null) {
            $prior = $ref !== null ? WorkflowRun::findByRef($wf['id'], $ref) : null;
            self::reply(200, ['ok' => true, 'duplicate' => true, 'run_id' => $prior['id'] ?? null]);
            return;
        }
        error_log('[Workflows] workflow #' . $wf['id'] . ' lancé par son adresse (exécution #' . $res['run_id'] . ')');
        http_response_code(202);
        $payload = ['ok' => true, 'run_id' => $res['run_id']];
        $jobs = $res['job_ids'];
        if ($jobs === []) {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }
        JobQueue::respondAndDispatch($payload, array_shift($jobs));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
    }

    /** Corps d'un appel : objet JSON, formulaire, ou texte brut (« texte »). Null si illisible. */
    public static function parseBody(string $raw, string $contentType): ?array
    {
        $ct = strtolower(trim(explode(';', $contentType)[0]));
        if ($ct === 'application/x-www-form-urlencoded' || $ct === 'multipart/form-data') {
            $form = [];
            if ($ct === 'multipart/form-data') {
                $form = $_POST;
            } else {
                parse_str($raw, $form);
            }
            return is_array($form) ? $form : null;
        }
        if (trim($raw) === '') {
            return [];
        }
        if ($ct === 'text/plain') {
            return ['texte' => $raw];
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        if (is_array($decoded) && !array_is_list($decoded)) {
            return $decoded;
        }
        return ['donnees' => $decoded];
    }

    private static function reply(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
