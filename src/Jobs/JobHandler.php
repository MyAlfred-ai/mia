<?php

declare(strict_types=1);

/**
 * Traitement d'un type de job asynchrone.
 *
 * Contrat : le handler ne connaît NI `$_POST`, NI la session, NI la connexion
 * HTTP. Tout son contexte vient de `$payload` (sérialisé à l'enfilage) et sa
 * sortie passe par `$ctx`. C'est ce qui lui permet de tourner dans un worker.
 *
 * Le retour est persisté en `result_json` et re-servi tel quel à un client qui
 * s'attache après la fin du job.
 */
interface JobHandler
{
    public function run(array $payload, JobContext $ctx): array;
}
