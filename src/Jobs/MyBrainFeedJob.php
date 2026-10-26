<?php

declare(strict_types=1);

/**
 * Suivi d'un dépôt au cerveau MyBrain APRÈS approbation (lot 3).
 *
 * Le `POST /api/v1/nourrir` est parti dans `driveAction`, au clic « Approuver » :
 * ce job ne fait QUE lire le statut du dépôt, toutes les
 * MyBrainFeed::POLL_INTERVAL_SEC secondes, jusqu'au message final persisté
 * (le lien de la proposition dans la revue de MyBrain). Idempotent, donc hors
 * NON_IDEMPOTENT : le reaper peut le rejouer sans redéposer. Exempté du quota
 * (QUOTA_EXEMPT) : aucun crédit MIA en jeu, et bloquer le suivi ferait perdre
 * l'issue d'un dépôt déjà fait.
 *
 * `$ctx->shouldStop()` à CHAQUE itération : c'est lui qui bat le heartbeat, et
 * une analyse en file peut durer plus que STALE_JOB_SEC — sans battement, le
 * reaper reprendrait le job en plein vol.
 */
class MyBrainFeedJob implements JobHandler
{
    /** @var callable|null transport HTTP de substitution (tests) */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function run(array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $kbId   = (int)($payload['kb_id'] ?? 0);
        $job    = trim((string)($payload['depot'] ?? ''));
        $titre  = trim((string)($payload['titre'] ?? '')) !== '' ? (string)$payload['titre'] : t('Texte');
        $base   = trim((string)($payload['base'] ?? '')) !== '' ? (string)$payload['base'] : 'MyBrain';

        if ($kbId <= 0 || $job === '') {
            throw new JobErrorException([
                'kind'    => 'server',
                'title'   => t('Suivi impossible'),
                'message' => t('Le dépôt au cerveau MyBrain ne peut pas être suivi. La proposition peut malgré tout apparaître dans sa revue.'),
            ], 'contexte mybrain_feed incomplet');
        }

        $ctx->setPhase('mybrain_follow');
        $echecs  = 0;
        $elapsed = 0;

        while ($elapsed <= MyBrainFeed::POLL_TIMEOUT_SEC) {
            if ($ctx->shouldStop()) {
                // Arrêt du SUIVI seulement : le dépôt, lui, continue chez MyBrain.
                $texte = '🚫 ' . t("Suivi arrêté pour « {titre} ». L'analyse continue dans le cerveau « {base} » : la proposition apparaîtra dans sa revue.", [
                    'titre' => $titre,
                    'base'  => $base,
                ]);
                Message::create($convId, 'assistant', $texte);
                $ctx->emit(['done' => true, 'cancelled' => true, 'content' => $texte]);
                return ['cancelled' => true];
            }

            try {
                $suivi  = MyBrainFeed::follow($kbId, $job, $this->transport);
                $echecs = 0;
            } catch (MyBrainException $e) {
                if ($e->kind !== MyBrainException::UNREACHABLE || ++$echecs >= MyBrainFeed::MAX_NETWORK_FAILURES) {
                    throw new JobErrorException([
                        'kind'    => 'network',
                        'title'   => t('Suivi interrompu'),
                        'message' => $e->getMessage() . ' ' . t('La proposition peut malgré tout apparaître dans la revue de MyBrain.'),
                    ], 'suivi mybrain en échec');
                }
                $suivi = null;
            } catch (\RuntimeException $e) {
                throw new JobErrorException([
                    'kind'    => 'server',
                    'title'   => t('Suivi impossible'),
                    'message' => $e->getMessage(),
                ], 'connexion mybrain absente');
            }

            if ($suivi !== null && $suivi['statut'] === 'erreur') {
                // Le ⚠️ en conversation est posé par JobQueue::execute (source unique).
                throw new JobErrorException([
                    'kind'    => 'generic',
                    'title'   => t('Texte non analysé'),
                    'message' => t("Le cerveau « {base} » n'a pas pu analyser « {titre} » : {erreur}", [
                        'base'   => $base,
                        'titre'  => $titre,
                        'erreur' => (string)($suivi['erreur'] ?? t('erreur inconnue')),
                    ]),
                ], 'analyse mybrain en échec');
            }
            $texte = $suivi !== null ? MyBrainFeed::resultMessage($suivi, $titre, $base) : null;
            if ($texte !== null) {
                $msg  = Message::create($convId, 'assistant', $texte);
                $done = ['done' => true, 'msg_id' => (int)($msg['id'] ?? 0), 'content' => $texte];
                $ctx->emit($done);
                return $done;
            }

            sleep(MyBrainFeed::POLL_INTERVAL_SEC);
            $elapsed += MyBrainFeed::POLL_INTERVAL_SEC;
        }

        // Toujours en file ou en analyse : ce n'est pas un échec, le dépôt est
        // fait — on rend la main en disant où le retrouver.
        $texte = MyBrainFeed::stillRunningMessage($titre, $base, MyBrainFeed::revueUrl($kbId));
        $msg   = Message::create($convId, 'assistant', $texte);
        $done  = ['done' => true, 'msg_id' => (int)($msg['id'] ?? 0), 'content' => $texte];
        $ctx->emit($done);
        return $done;
    }
}
