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
 */
final class NativeDeckJob implements JobHandler
{
    public function run(array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $kind   = ($payload['kind'] ?? '') === NativeDeck::KIND_XLSX ? NativeDeck::KIND_XLSX : NativeDeck::KIND_PPTX;
        $xlsx   = $kind === NativeDeck::KIND_XLSX;
        $llm    = (string)($payload['llm'] ?? '');
        $label  = (string)($payload['label'] ?? $llm);
        $title  = (string)($payload['title'] ?? '');
        $name   = (string)($payload['name'] ?? ($xlsx ? 'classeur.xlsx' : 'presentation.pptx'));

        $ctx->setPhase('native_deck');
        $context = $this->conversationContext($convId, (int)($payload['before'] ?? 0), (array)($payload['exclude'] ?? []));
        $tokens  = [];
        try {
            $made   = NativeDeck::make($llm, (string)($payload['source'] ?? ''), $title, $name, static fn (): bool => $ctx->shouldStop(), null, [
                'kind'    => $kind,
                'note'    => (string)($payload['note'] ?? ''),
                'company' => ($payload['company'] ?? true) !== false,
            ] + $context);
            $tokens = $made['tokens'];
        } catch (NativeDeckException $e) {
            $this->bill($llm, $e->tokens(), $ctx);
            if ($e->stopped()) {
                $texte = '🚫 ' . ($xlsx
                    ? t('Réalisation du classeur par {model} arrêtée.', ['model' => $label])
                    : t('Réalisation de la présentation par {model} arrêtée.', ['model' => $label]));
                Message::create($convId, 'assistant', $texte);
                $ctx->emit(['done' => true, 'cancelled' => true, 'content' => $texte]);

                return ['cancelled' => true];
            }
            // Le ⚠️ en conversation est posé par JobQueue::execute (source unique).
            throw new JobErrorException([
                'kind'    => 'generic',
                'title'   => $e->title() !== '' ? $e->title() : ($xlsx ? t('Classeur non réalisé') : t('Présentation non réalisée')),
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
        $texte = '✅ ' . ($xlsx
            ? t('Classeur réalisé par {model} : [{file}]({url}) — conservé {days} jours.', $params)
            : t('Présentation réalisée par {model} : [{file}]({url}) — conservée {days} jours.', $params));
        $msg   = Message::create($convId, 'assistant', $texte);
        $msgId = (int)($msg['id'] ?? 0);
        $usage = $this->bill($llm, $tokens, $ctx, $msgId);

        $done = ['done' => true, 'msg_id' => $msgId, 'content' => $texte, 'credits' => (int)($usage['total_credits'] ?? 0)];
        $ctx->emit($done);

        return $done;
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
