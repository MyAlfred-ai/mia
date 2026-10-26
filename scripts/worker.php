<?php

declare(strict_types=1);

/**
 * Worker de générations asynchrones (conteneur `worker` du compose).
 *
 * Boucle : réclame un job en attente (atomique, plusieurs workers peuvent
 * tourner en parallèle — `MIA_WORKERS` du compose, 4 par défaut), l'exécute,
 * recommence. L'ordre et les couloirs sériels sont portés par la file
 * (cf. JobQueue : priority, lane), pas par le nombre de workers. Signale sa
 * présence pour que l'app n'ait pas à déclencher elle-même
 * (`JobQueue::workerAlive()`).
 *
 * Le process se termine volontairement après MAX_JOBS ou MAX_LIFETIME : PHP
 * n'est pas fait pour tourner indéfiniment (fuites de mémoire des extensions).
 * `restart: unless-stopped` le relance immédiatement.
 *
 * Usage : php scripts/worker.php
 */

require __DIR__ . '/_cli_bootstrap.php';

set_time_limit(0);

const IDLE_SLEEP_US  = 700_000;  // 0,7 s entre deux boucles à vide
const HEARTBEAT_SEC  = 15;       // fréquence de la balise « worker vivant »
const MAINTENANCE_SEC = 60;      // fréquence du reaper + purge des events
const MAX_JOBS       = 200;
const MAX_LIFETIME   = 3600;

// Heure de démarrage du PROCESS (recyclage au bout de MAX_LIFETIME). Nom
// distinct de tout le reste : réécrite par le nombre de tâches lancées (0), elle
// faisait sortir le worker dès son premier tour, avant de prendre le moindre
// job — Docker le relançait en boucle et rien ne s'exécutait sans une page
// ouverte (le repli de l'app). Garde : tests/Functional/WorkerLoopTest.php.
$processStartedAt = time();
$jobsDone      = 0;
$lastHeartbeat = 0;
$lastMaintenance = 0;
$lastSchedule  = 0;
$stopping      = false;
$codeOutdated  = false; // version de CE process ≠ version servie par l'app → on ne réclame plus

// Arrêt propre : on finit le job en cours, on ne réclame plus.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$stopping): void { $stopping = true; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT,  $stop);
}

fwrite(STDOUT, "[worker] démarré\n");

// Sonde de sortie réseau. Le worker appelle des API externes (LLM, images,
// Google) : sans accès sortant, TOUS les jobs échouent en « impossible de
// contacter … » — un symptôme trompeur, puisque les tests d'administration
// partent du conteneur web, lui, connecté. Une ligne de log au démarrage rend
// la panne diagnosticable immédiatement. Non bloquant : le réseau peut arriver
// après le worker.
// Plusieurs tentatives : le DNS de Docker n'est pas toujours prêt à la
// milliseconde où le conteneur démarre — une seule sonde crierait au loup.
$probeHost = 'api.openai.com';
$probeOk   = false;
for ($try = 0; $try < 4 && !$probeOk; $try++) {
    if ($try > 0) {
        sleep(2);
    }
    $probeOk = gethostbyname($probeHost) !== $probeHost;
}
if ($probeOk) {
    fwrite(STDOUT, "[worker] accès Internet sortant OK\n");
} else {
    error_log('[worker] AUCUNE résolution DNS pour ' . $probeHost
        . ' — ce conteneur n\'a pas d\'accès Internet sortant. Vérifiez ses réseaux Docker '
        . '(un réseau `internal: true` seul bloque toute sortie).');
    fwrite(STDERR, "[worker] ⚠ pas d'accès Internet sortant — les appels aux fournisseurs échoueront\n");
}

// Parité de configuration app ↔ worker. Depuis que le chat passe par la file,
// le worker exécute le MÊME code métier que le conteneur web : une variable
// oubliée de son seul côté coupe une fonctionnalité entière SANS erreur.
// Cas vécu : sans GOOGLE_CLIENT_ID/SECRET, `GoogleDriveOAuth::isConfigured()`
// renvoyait false ici, les connecteurs Drive/Gmail/Agenda n'étaient jamais
// exposés au LLM et l'assistant répondait qu'il ne pouvait pas les utiliser —
// alors que tout était vert côté web. Une ligne au démarrage suffit à le voir.
// Ces identifiants vivent désormais en BASE (SsoRegistry), donc partagés par
// construction ; le contrôle reste, il couvre le cas « jamais renseignés ».
$configGaps = [];
foreach (['APP_SECRET' => APP_SECRET, 'ENCRYPTION_KEY' => ENCRYPTION_KEY] as $envName => $envValue) {
    if ((string)$envValue === '') {
        $configGaps[] = $envName;
    }
}
if (!GoogleDriveOAuth::isConfigured()) {
    $configGaps[] = 'identifiants OAuth Google (connecteurs Drive, Gmail, Agenda)';
}
if ($configGaps === []) {
    fwrite(STDOUT, "[worker] configuration complète\n");
} else {
    error_log('[worker] configuration INCOMPLÈTE — absent de CE conteneur : ' . implode(', ', $configGaps)
        . '. Les fonctionnalités concernées échoueront en silence dans les jobs. Le bloc `x-app-env` du '
        . 'docker-compose est partagé par `app` et `worker` : vérifiez que le service a bien été recréé.');
    fwrite(STDERR, "[worker] ⚠ configuration incomplète : " . implode(', ', $configGaps) . "\n");
}

while (!$stopping) {
    $now = time();

    if ($now - $lastHeartbeat >= HEARTBEAT_SEC) {
        try {
            JobQueue::markWorkerAlive();
            // Process long-vivant : recharge les réglages (un `set()` avant le
            // premier `get()` a longtemps poisonné le cache — cf. Settings) et
            // vérifie qu'un déploiement n'a pas laissé ce conteneur sur une
            // image périmée. `execute()` re-rafraîchit au début de chaque job.
            Settings::refresh();
            $outdated = JobQueue::workerOutdated();
            if ($outdated !== $codeOutdated) {
                $codeOutdated = $outdated;
                if ($codeOutdated) {
                    error_log('[worker] version ' . APP_VERSION . ' ≠ version servie par l\'app — '
                        . 'ce worker ne réclame plus de jobs (conteneur sur une image périmée : '
                        . 'recréez-le avec `docker compose build && docker compose up -d`)');
                } else {
                    fwrite(STDOUT, "[worker] version réalignée — reprise des réclamations\n");
                }
            }
        } catch (\Throwable $e) {
            error_log('[worker] heartbeat impossible : ' . $e->getMessage());
        }
        $lastHeartbeat = $now;
    }

    // Corvées périodiques : avec plusieurs workers, un seul les fait à la fois
    // (verrou MySQL non bloquant) — les autres passent et réclament des jobs.
    if ($now - $lastMaintenance >= MAINTENANCE_SEC) {
        try {
            JobQueue::exclusively('mia_worker_maintenance', static function (): void {
                $reaped = JobQueue::reapStale();
                if ($reaped > 0) {
                    error_log('[worker] ' . $reaped . ' job(s) orphelin(s) repris/échoués');
                }
                JobQueue::gcEvents();
                ScheduledTaskRun::maintain(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                WorkflowEngine::maintain();
                // Présentations et classeurs réalisés par le modèle : gardés 30 jours.
                NativeDeckStore::purgeExpired();
            });
        } catch (\Throwable $e) {
            error_log('[worker] entretien impossible : ' . $e->getMessage());
        }
        $lastMaintenance = $now;
    }

    // Tâches programmées : les créneaux échus deviennent des jobs de chat,
    // que ce même worker (ou un autre) réclame à la boucle suivante. Pas
    // depuis un worker périmé : il enfilerait selon des règles d'hier.
    // Le planificateur est sûr à plusieurs (tâche verrouillée, créneau unique) ;
    // le verrou évite seulement que chaque worker refasse la même lecture.
    if (!$codeOutdated && $now - $lastSchedule >= Scheduler::TICK_SEC) {
        try {
            JobQueue::exclusively('mia_worker_scheduler', static function (): void {
                $launched = Scheduler::tick();
                if ($launched > 0) {
                    fwrite(STDOUT, '[worker] ' . $launched . " tâche(s) programmée(s) lancée(s)\n");
                }
                // Workflows programmés : même cadence, même verrou.
                $workflows = WorkflowEngine::tick();
                if ($workflows > 0) {
                    fwrite(STDOUT, '[worker] ' . $workflows . " workflow(s) programmé(s) lancé(s)\n");
                }
            });
        } catch (\Throwable $e) {
            error_log('[worker] tâches programmées : ' . $e->getMessage());
        }
        $lastSchedule = $now;
    }

    if ($jobsDone >= MAX_JOBS || ($now - $processStartedAt) >= MAX_LIFETIME) {
        fwrite(STDOUT, "[worker] recyclage du process (jobs=$jobsDone)\n");
        break;
    }

    if ($codeOutdated) {
        // Les jobs restent en file : l'app les voit (workerAlive() = false sur
        // écart de version) et les dispatche par fork, sur le code courant.
        usleep(IDLE_SLEEP_US);
        continue;
    }

    try {
        $job = JobQueue::claimNext();
    } catch (\Throwable $e) {
        error_log('[worker] réclamation impossible : ' . $e->getMessage());
        $job = null;
    }

    if ($job === null) {
        usleep(IDLE_SLEEP_US);
        continue;
    }

    fwrite(STDOUT, '[worker] job #' . $job['id'] . ' (' . $job['type'] . ")\n");
    JobQueue::execute($job);   // n'émet jamais d'exception : issue persistée en base
    $jobsDone++;
}

fwrite(STDOUT, "[worker] arrêt\n");
