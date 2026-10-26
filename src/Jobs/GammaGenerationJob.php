<?php

declare(strict_types=1);

/**
 * Suivi d'une génération gamma.app APRÈS approbation.
 *
 * Le `POST /generations` (seul acte facturant) est parti dans `driveAction`,
 * au clic « Approuver » : ce job ne fait QUE du `GET` de statut toutes les
 * 5 secondes, jusqu'au lien final persisté en message assistant. Il est donc
 * idempotent (hors NON_IDEMPOTENT : le reaper peut le rejouer sans double
 * facturation) et exempté du quota MIA (QUOTA_EXEMPT : les crédits Gamma sont
 * externes et DÉJÀ consommés — bloquer le suivi ferait perdre un résultat
 * déjà payé).
 *
 * `$ctx->shouldStop()` à CHAQUE itération : c'est lui qui bat le heartbeat, et
 * STALE_JOB_SEC (120 s) est PLUS COURT qu'une génération (1–3 min) — sans
 * battement, le reaper reprendrait le job en plein vol.
 */
class GammaGenerationJob implements JobHandler
{
    /** Pannes réseau transitoires tolérées avant d'abandonner le suivi. */
    private const MAX_NETWORK_FAILURES = 3;

    public function run(array $payload, JobContext $ctx): array
    {
        $convId       = (int)$ctx->conversationId();
        $generationId = trim((string)($payload['generation_id'] ?? ''));
        $titre        = trim((string)($payload['titre'] ?? '')) !== '' ? (string)$payload['titre'] : 'Présentation';
        $tool         = ChatTool::findById((int)($payload['tool_id'] ?? 0));
        $gamma        = GammaService::fromTool($tool);

        // Les droits ont été vérifiés au clic « Approuver » ; ici on ne fait
        // que LIRE un statut, mais sans outil (supprimé, clé retirée) il n'y a
        // plus de clé pour interroger gamma.app.
        if ($generationId === '' || $gamma === null) {
            throw new JobErrorException([
                'kind'    => 'server',
                'title'   => 'Suivi impossible',
                'message' => 'L\'outil Gamma a été supprimé ou sa clé retirée pendant la génération. '
                    . 'La présentation peut malgré tout apparaître dans l\'espace gamma.app du compte.',
            ], 'contexte gamma incomplet');
        }

        $ctx->setPhase('gamma_poll');
        $echecsReseau = 0;
        $elapsed      = 0;

        while ($elapsed <= GammaService::POLL_TIMEOUT_SEC) {
            if ($ctx->shouldStop()) {
                // Arrêt utilisateur : la génération, elle, continue chez Gamma.
                $texte = '🚫 Suivi de la présentation Gamma « ' . $titre . ' » arrêté. La génération continue '
                    . 'côté gamma.app (crédits déjà consommés) — retrouvez-la dans l\'espace Gamma du compte.';
                Message::create($convId, 'assistant', $texte);
                $ctx->emit(['done' => true, 'cancelled' => true, 'content' => $texte]);
                return ['cancelled' => true];
            }

            try {
                $etat = $gamma->getGeneration($generationId);
                $echecsReseau = 0;
            } catch (\RuntimeException $e) {
                // Un GET raté n'est pas un échec de génération : on retente.
                if (++$echecsReseau >= self::MAX_NETWORK_FAILURES) {
                    throw new JobErrorException([
                        'kind'    => 'network',
                        'title'   => 'Suivi interrompu',
                        'message' => $e->getMessage() . ' La présentation peut malgré tout apparaître dans '
                            . 'l\'espace gamma.app du compte dans quelques minutes.',
                    ], 'poll gamma en échec réseau');
                }
                $etat = ['status' => 'pending'];
            }

            if ($etat['status'] === 'completed') {
                $texte = GammaService::summarizeResultMessage($titre, $etat);
                $msg   = Message::create($convId, 'assistant', $texte);
                $msgId = (int)($msg['id'] ?? 0);
                // Compteur d'usage ; 0 crédit MIA (les crédits Gamma sont
                // externes, tracés en meta pour l'audit).
                UsageMeter::toolUse(
                    $tool,
                    ['gamma_credits' => $etat['credits_deducted'], 'generation_id' => $generationId],
                    [
                        'user_id'         => $ctx->userId(),
                        'conversation_id' => $convId,
                        'message_id'      => $msgId > 0 ? $msgId : null,
                    ]
                );
                $done = [
                    'done'       => true,
                    'msg_id'     => $msgId,
                    'content'    => $texte,
                    'gamma_url'  => $etat['gammaUrl'],
                    'export_url' => $etat['exportUrl'],
                ];
                $ctx->emit($done);
                return $done;
            }
            if ($etat['status'] === 'failed') {
                // Le ⚠️ en conversation est posé par JobQueue::execute (source unique).
                throw new JobErrorException([
                    'kind'    => 'generic',
                    'title'   => 'Présentation non générée',
                    'message' => 'gamma.app n\'a pas pu générer « ' . $titre . ' » : ' . $etat['error'] . '.',
                ], 'génération gamma en échec');
            }

            sleep(GammaService::POLL_INTERVAL_SEC);
            $elapsed += GammaService::POLL_INTERVAL_SEC;
        }

        throw new JobErrorException([
            'kind'    => 'generic',
            'title'   => 'Génération trop longue',
            'message' => 'La présentation « ' . $titre . ' » dépasse le délai de suivi. Vérifiez l\'espace '
                . 'gamma.app du compte : elle peut y apparaître dans quelques minutes.',
        ], 'timeout poll gamma');
    }
}
