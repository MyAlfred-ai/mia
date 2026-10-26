<?php

declare(strict_types=1);

/**
 * Quota mensuel de crédits — source unique de « peut-on lancer une opération IA ? ».
 *
 * Extrait de ChatController pour être utilisable hors requête HTTP : les jobs
 * asynchrones re-vérifient le quota au DÉMARRAGE (le temps passé en file a pu
 * le faire basculer), et n'ont ni session ni contrôleur sous la main.
 *
 * Seules les lignes NON exemptées entament le quota (`quota_credits`) : une
 * opération d'exploitation imputée à l'utilisateur (alimentation d'une base
 * qu'il a créée, test d'outil) est journalisée avec son coût mais ne le
 * prive pas de chat. `total_credits` reste disponible pour l'information.
 *
 * Budget IA de l'organisation (AiBudget) : consulté ICI, et nulle part
 * ailleurs. Tous les points qui appliquent le quota (envoi, régénération,
 * créations, dictée, rédaction assistée, démarrage d'un job) appliquent donc
 * le budget sans avoir été touchés. `reason` dit lequel des deux bloque —
 * le quota personnel d'abord : c'est lui qui reste vrai si un administrateur
 * accorde une rallonge. Aucun montant en euros n'entre dans ce statut : il est
 * sérialisé dans le navigateur de chaque utilisateur (window.CREDIT_QUOTA_STATUS),
 * et les utilisateurs ne voient jamais le coût réel.
 */
class CreditQuota
{
    public const REASON_QUOTA  = 'quota';
    public const REASON_BUDGET = 'budget';

    /** Instantané de consommation du mois pour un utilisateur. */
    public static function status(array $user): array
    {
        $snapshot = UsageLog::creditsBreakdownThisMonth((int)$user['id']);
        $used = (int)$snapshot['quota_credits'];
        $quotaK = User::effectiveCreditQuotaK($user);
        $budget = AiBudget::status();

        return self::compose($used, $quotaK, $snapshot, (bool)$budget['blocks'], (string)$budget['resets_on']);
    }

    /**
     * Assemblage pur du statut (testé sans base). Liste de clés FERMÉE : tout
     * ajout part chez chaque utilisateur, d'où l'interdiction des montants €.
     */
    public static function compose(int $used, ?int $quotaK, array $snapshot, bool $budgetBlocks, string $resetsOn): array
    {
        $quotaMax = $quotaK !== null ? $quotaK * 1_000 : null;
        $remaining = $quotaMax !== null ? max(0, $quotaMax - $used) : null;
        if ($quotaMax === null) {
            $percent = 0;
        } elseif ($quotaMax <= 0) {
            $percent = 100;
        } else {
            $percent = min(100, (int)round($used / $quotaMax * 100));
        }
        $quotaExceeded = $quotaMax !== null && $used >= $quotaMax;

        return [
            'used' => $used,
            'quota_k' => $quotaK,
            'quota_max' => $quotaMax,
            'remaining' => $remaining,
            'percent' => $percent,
            'total_credits' => (int)($snapshot['total_credits'] ?? 0),
            'exempt_credits' => (int)($snapshot['exempt_credits'] ?? 0),
            'requests' => (int)($snapshot['requests'] ?? 0),
            'reason' => $quotaExceeded ? self::REASON_QUOTA : ($budgetBlocks ? self::REASON_BUDGET : null),
            'budget_blocked' => $budgetBlocks,
            'resets_on' => $resetsOn,
        ];
    }

    /** Quota illimité (`quota_max === null`) → jamais dépassé, sauf budget de l'organisation atteint. */
    public static function isExceeded(array $status): bool
    {
        if (!empty($status['budget_blocked'])) {
            return true;
        }
        $quotaMax = $status['quota_max'] ?? null;
        if ($quotaMax === null) {
            return false;
        }
        return (int)($status['used'] ?? 0) >= (int)$quotaMax;
    }

    /** « 1er oct. 2026 » : date de reprise lisible (format court de Format::monthLabel). */
    public static function resetLabel(string $resetsOn): string
    {
        $month = substr($resetsOn, 0, 7);
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = date('Y-m', strtotime('first day of next month'));
        }
        return t('1er') . ' ' . Format::monthLabel($month);
    }

    /**
     * Refus présentable (titre + message traduits), selon la raison du blocage.
     * `$inJob` : la génération était en file et n'a pas démarré.
     *
     * @return array{kind:string, title:string, message:string}
     */
    public static function refusal(array $status, bool $inJob = false): array
    {
        $reason = $status['reason'] ?? null;
        if ($reason === null) {
            $reason = !empty($status['budget_blocked']) ? self::REASON_BUDGET : self::REASON_QUOTA;
        }
        if ($reason === self::REASON_BUDGET) {
            $date = self::resetLabel((string)($status['resets_on'] ?? ''));
            return [
                'kind'    => self::REASON_BUDGET,
                'title'   => t('Service IA suspendu'),
                'message' => $inJob
                    ? t('Le budget IA mensuel de votre organisation est atteint : la génération n\'a pas été lancée. Reprise le {date}.', ['date' => $date])
                    : t('Le budget IA mensuel de votre organisation est atteint : le service reprend le {date}.', ['date' => $date]),
            ];
        }
        return [
            'kind'    => self::REASON_QUOTA,
            'title'   => t('Quota de crédits atteint'),
            'message' => $inJob
                ? t('Votre quota mensuel est atteint. La génération n\'a pas été lancée.')
                : t('Votre quota mensuel de crédits est atteint.'),
        ];
    }
}
