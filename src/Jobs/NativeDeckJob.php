<?php

declare(strict_types=1);

/**
 * « Version soignée » : un modèle — celui de la conversation s'il en est
 * capable, sinon celui que MIA ou la personne a choisi — compose lui-même la
 * présentation ou le classeur (NativeDeck), le fichier est rangé 30 jours
 * (NativeDeckStore) et son lien arrive dans la conversation.
 *
 * Le job relit la CONVERSATION avant la carte : la demande de la personne et
 * les fichiers qu'elle y a joints partent avec le texte de la carte
 * (NativeDeck::contextFrom + attachmentFiles) — c'est ce qui fait qu'une photo
 * jointe ou un classeur de chiffres servent au fichier réalisé. Il tourne dans
 * le WORKER : les pièces jointes (storage/uploads) et le modèle de l'entreprise
 * (storage/branding, logos) doivent y être montés.
 *
 * NON IDEMPOTENT (JobQueue::NON_IDEMPOTENT) : un rejeu referait — et
 * refacturerait — la réalisation. Le quota est revérifié au démarrage, comme
 * pour toute génération ; les jetons consommés sont comptés même quand le
 * fichier n'arrive pas (le fournisseur les a facturés).
 *
 * Le battement du job passe par le transfert HTTP (NativeDeck::send) : un
 * appel de plusieurs minutes sans battement serait repris par le reaper.
 *
 * Le message d'attente posé au lancement (`message_id`) porte la carte de
 * réalisation (NativeDeckRun) : le job le RÉÉCRIT à la fin — texte ✅ ou 🚫
 * et état « prête » ou « arrêtée » — au lieu d'en ajouter un second, et le
 * flux apporte la carte toute rendue (`run_html`). Un échec laisse l'état
 * « interrompue » ; le ⚠️ vient de JobQueue::execute, comme pour tout job.
 */
final class NativeDeckJob implements JobHandler
{
    public function run(array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $kind   = NativeDeck::normalizeKind((string)($payload['kind'] ?? ''));
        $llm    = (string)($payload['llm'] ?? '');
        $label  = (string)($payload['label'] ?? $llm);
        $title  = (string)($payload['title'] ?? '');
        $name   = (string)($payload['name'] ?? NativeDeck::filename('', $kind));

        $ctx->setPhase('native_deck');
        $pending = $this->pendingMessage($convId, (int)($payload['message_id'] ?? 0));
        $run     = $pending > 0 ? NativeDeckRun::of((array)Message::findById($pending)) : null;
        $run   ??= NativeDeckRun::start($kind, $llm, $label, $title, 0, time());
        $context = $this->conversationContext($convId, (int)($payload['before'] ?? 0), (array)($payload['exclude'] ?? []));
        $tokens  = [];
        // Plafond fixé au lancement ; un job enfilé avant lui prend celui du tarif.
        $cap = (int)($payload['cap'] ?? 0) ?: (int)NativeDeck::capCredits(NativeDeck::tariffCredits($llm, $kind));
        try {
            $made   = NativeDeck::make($llm, (string)($payload['source'] ?? ''), $title, $name, static fn (): bool => $ctx->shouldStop(), null, [
                'kind'     => $kind,
                'note'     => (string)($payload['note'] ?? ''),
                'company'  => ($payload['company'] ?? true) !== false,
                // Modèle et logo de l'entreprise, chacun au choix de la personne.
                'template' => ($payload['template'] ?? true) !== false,
                'logo'     => ($payload['logo'] ?? true) !== false,
                'cap'      => $cap,
            ] + $context);
            $tokens = $made['tokens'];
        } catch (NativeDeckException $e) {
            $this->bill($llm, $e->tokens(), $ctx);
            if ($e->stopped()) {
                $texte = '🚫 ' . NativeDeck::label($kind, 'stopped', ['model' => $label]);
                $stopped = NativeDeckRun::finish($run, NativeDeckRun::CANCELLED, time());
                $msgId   = $this->writeOutcome($convId, $pending, $texte, $stopped);
                $ctx->emit(['done' => true, 'cancelled' => true, 'content' => $texte, 'msg_id' => $msgId,
                    'run_html' => $this->runHtml($msgId, $pending, $stopped)]);

                return ['cancelled' => true];
            }
            if ($pending > 0) {
                Message::setRun($pending, NativeDeckRun::finish($run, NativeDeckRun::FAILED, time()));
            }
            // Le ⚠️ en conversation est posé par JobQueue::execute (source unique).
            throw new JobErrorException([
                'kind'    => 'generic',
                'title'   => $e->title() !== '' ? $e->title() : NativeDeck::label($kind, 'not_made'),
                'message' => $e->getMessage(),
            ], 'réalisation par le modèle en échec');
        }

        $token  = NativeDeckStore::save($made['bytes'], $made['filename'], (int)$ctx->userId(), $convId, $llm);
        $params = [
            'model' => $label,
            'file'  => $made['filename'],
            'url'   => NativeDeckStore::url($convId, $token),
            'days'  => (string)NativeDeckStore::RETENTION_DAYS,
        ];
        $texte = '✅ ' . NativeDeck::label($kind, 'made', $params)
            . (!empty($made['capped']) ? ' ' . NativeDeck::capped((int)$made['capped']) : '');
        $finished = NativeDeckRun::finish($run, NativeDeckRun::DONE, time(), ['file' => $made['filename'], 'url' => $params['url']]);
        $msgId    = $this->writeOutcome($convId, $pending, $texte, $finished);
        $usage    = $this->bill($llm, $tokens, $ctx, $msgId);
        $credits  = (int)($usage['total_credits'] ?? 0);

        $done = ['done' => true, 'msg_id' => $msgId, 'content' => $texte, 'credits' => $credits,
            'run_html' => $this->runHtml($msgId, $pending, $finished, $credits)];
        $ctx->emit($done);

        return $done;
    }

    /**
     * Message d'attente du lancement, s'il appartient bien à cette
     * conversation (0 sinon : job enfilé avant la carte, ou message supprimé).
     */
    private function pendingMessage(int $convId, int $messageId): int
    {
        if ($messageId <= 0) {
            return 0;
        }
        $row = Message::findById($messageId);

        return $row !== null && (int)($row['conversation_id'] ?? 0) === $convId && ($row['role'] ?? '') === 'assistant'
            ? $messageId : 0;
    }

    /**
     * Issue de la réalisation : le message d'attente est réécrit (texte + état
     * de la carte) ; sans lui, un nouveau message comme avant. Identifiant du
     * message qui porte l'issue.
     *
     * @param array<string, mixed> $run
     */
    private function writeOutcome(int $convId, int $pending, string $texte, array $run): int
    {
        if ($pending > 0) {
            Message::updateContent($pending, $texte);
            Message::setRun($pending, $run);

            return $pending;
        }
        $msg = Message::create($convId, 'assistant', $texte);

        return (int)($msg['id'] ?? 0);
    }

    /**
     * Carte rendue pour le flux, seulement quand le message d'attente la
     * porte (sinon le navigateur ajoute le texte, comme avant).
     *
     * @param array<string, mixed> $run
     */
    private function runHtml(int $msgId, int $pending, array $run, int $credits = 0): string
    {
        if ($pending <= 0 || $msgId !== $pending) {
            return '';
        }
        try {
            return NativeDeckRun::html(NativeDeckRun::view($run, $msgId, time(), $credits));
        } catch (Throwable $e) {
            error_log('[Fichier par le modèle] Carte de réalisation non rendue : ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Demande et pièces jointes de la conversation, avant le message de la
     * carte, sans celles que la personne a décochées dans la fenêtre
     * « Version soignée » (`$exclude`, par leur nom). Best-effort : une
     * lecture en échec n'empêche pas la réalisation (le modèle part alors du
     * seul texte de la carte, comme avant).
     *
     * @param list<string> $exclude
     * @return array{request: string, files: list<array{name: string, mime: string, bytes: string, label: string}>}
     */
    private function conversationContext(int $convId, int $before, array $exclude = []): array
    {
        try {
            $found = NativeDeck::contextFrom(Message::forConversation($convId), $before);

            return ['request' => $found['request'], 'files' => NativeDeck::attachmentFiles(NativeDeck::withoutExcluded($found['attachments'], $exclude))];
        } catch (Throwable $e) {
            error_log('[Fichier par le modèle] Conversation ' . $convId . ' illisible : ' . $e->getMessage());

            return ['request' => '', 'files' => []];
        }
    }

    /** Jetons consommés, au grand livre (source « fichiers réalisés par le modèle »). */
    private function bill(string $llm, array $tokens, JobContext $ctx, int $msgId = 0): array
    {
        if ((int)($tokens['input'] ?? 0) + (int)($tokens['output'] ?? 0) === 0) {
            return [];
        }

        return UsageMeter::llm($llm, $tokens, UsageSource::NATIVE_DECK, [
            'user_id'         => $ctx->userId(),
            'conversation_id' => $ctx->conversationId(),
            'message_id'      => $msgId > 0 ? $msgId : null,
        ]);
    }
}
