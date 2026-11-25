<?php

declare(strict_types=1);

/**
 * Mise à jour des prix réels depuis Internet (Administration → Crédits).
 *
 * Job hors conversation : `conversation_id` est NULL. La recherche porte sur
 * des dizaines de services et peut durer plusieurs minutes — bien au-delà de
 * ce qu'une requête PHP doit tenir ouverte.
 *
 * Le job écrit LUI-MÊME la configuration (cf. `runCreditsPriceRefresh`) : la
 * page peut donc être quittée pendant la recherche sans rien perdre, elle
 * n'est plus que spectatrice.
 */
class CreditsRefreshJob implements JobHandler
{
    /** Intervalle entre deux battements pendant un transfert HTTP (secondes). */
    private const PULSE_SEC = 5.0;

    public function run(array $payload, JobContext $ctx): array
    {
        $services = (array)($payload['services'] ?? []);
        if ($services === []) {
            throw new JobErrorException([
                'kind'    => 'bad_request',
                'title'   => 'Mise à jour impossible',
                'message' => 'Aucun service à traiter.',
            ], 'services vides');
        }

        $ctx->setPhase('pricing');

        // Sonde consultée pendant les transferts : elle bat le cœur du job (sans
        // quoi le reaper le croirait mort au bout de 2 minutes et le rejouerait
        // en plein vol) et propage la demande d'arrêt.
        $lastPulse = 0.0;
        $stopped   = false;
        $keepAlive = function () use ($ctx, &$lastPulse, &$stopped): bool {
            if ($stopped) {
                return false;
            }
            $now = microtime(true);
            if ($now - $lastPulse < self::PULSE_SEC) {
                return true;
            }
            $lastPulse = $now;
            $stopped   = $ctx->shouldStop();
            return !$stopped;
        };

        try {
            $result = (new AdminController())->runCreditsPriceRefresh($services, $keepAlive, $ctx->userId() > 0 ? $ctx->userId() : null);
        } catch (\Throwable $e) {
            // Transfert interrompu par la sonde : c'est une annulation, pas une
            // panne — `JobQueue::execute` conclura en `cancelled`.
            if ($stopped || $ctx->shouldStop()) {
                return ['ok' => false, 'done' => true, 'cancelled' => true, 'message' => 'Mise à jour annulée.'];
            }
            if ($e instanceof JobErrorException) {
                throw $e;
            }
            // Sans charge utile, `JobQueue::execute` afficherait « La
            // génération n'a pas abouti. Réessayez. » : aucune cause, et
            // réessayer ne change rien quand c'est l'enregistrement qui bute.
            throw new JobErrorException(self::errorPayload($e), $e->getMessage());
        }

        $ctx->emit($result);

        return $result;
    }

    /**
     * Ce que l'écran dit d'une recherche en échec (pure). L'écran est réservé
     * aux administrateurs des coûts : le message d'une recherche en ligne
     * (« Appel OpenAI impossible (…) », « OpenAI a refusé la requête… ») y est
     * montré tel quel. Une erreur de base de données ou de code, elle, n'a rien
     * à dire à l'écran : elle renvoie aux journaux, qui la portent en entier.
     */
    public static function errorPayload(\Throwable $e): array
    {
        $journaux = t('Le détail est dans Maintenance → Diagnostic → Journaux.');
        $message = match (true) {
            $e instanceof \PDOException => t('Les prix n’ont pas pu être enregistrés.') . ' ' . $journaux,
            $e instanceof \RuntimeException && trim($e->getMessage()) !== ''
                => t('La recherche de prix a échoué : {erreur}', ['erreur' => trim($e->getMessage())]),
            default => t('La recherche de prix a échoué sur une erreur interne.') . ' ' . $journaux,
        };

        return [
            'kind'    => 'generic',
            'title'   => t('Mise à jour des prix impossible'),
            'message' => $message,
        ];
    }
}
