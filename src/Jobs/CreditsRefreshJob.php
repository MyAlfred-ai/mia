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
            throw $e;
        }

        $ctx->emit($result);

        return $result;
    }
}
