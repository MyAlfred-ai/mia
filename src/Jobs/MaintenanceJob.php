<?php

declare(strict_types=1);

/**
 * Tâches système hors chat : ingestion d'un document, réindexation d'une base,
 * synchronisation d'un dossier distant, extraction d'un document d'assistant.
 *
 * Ces traitements étaient lancés par `BackgroundJob` (fork POSIX, sinon
 * exécution synchrone dans la requête). Les faire passer par la file leur
 * apporte ce que le fork n'offrait pas : traçabilité (statut, durée, erreur en
 * base), reprise après crash du worker (heartbeat + reaper), déduplication, et
 * surtout un fonctionnement identique là où `exec` est indisponible.
 *
 * Toutes les opérations sont IDEMPOTENTES — d'où leur absence de
 * `JobQueue::NON_IDEMPOTENT` : les rejouer ne facture rien en double.
 */
class MaintenanceJob implements JobHandler
{
    public function run(array $payload, JobContext $ctx): array
    {
        $op = (string)($payload['op'] ?? '');
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Job de maintenance sans cible : ' . $op);
        }

        $ctx->setPhase($op);

        // Battement de cœur pour les opérations LONGUES (une étape par
        // document) : muet plus de 120 s, un job est réputé mort et rejoué.
        // Pour la synchronisation, le verrou de base rendait le rejeu inoffensif
        // mais la file croyait le job fini ; pour la réindexation, rien ne
        // l'empêchait de tourner deux fois en parallèle. `shouldStop()` pose le
        // battement (JobContext le limite à deux par seconde) — son verdict est
        // sans objet ici, ces tâches ne s'annulent pas depuis l'écran.
        $heartbeat = static function () use ($ctx): void {
            $ctx->shouldStop();
        };

        switch ($op) {
            case 'kb_document':
                KnowledgeBaseDocument::processIngestion($id);
                break;
            case 'profile_document':
                ProfileDocument::processExtraction($id);
                break;
            case 'kb_reindex':
                KnowledgeBase::reindex($id, $heartbeat);
                break;
            case 'kb_sync':
                KbSync::syncKb($id, $heartbeat);
                break;
            default:
                throw new \RuntimeException('Opération de maintenance inconnue : ' . $op);
        }

        return ['done' => true, 'op' => $op, 'id' => $id];
    }
}
