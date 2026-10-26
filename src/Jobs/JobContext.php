<?php

declare(strict_types=1);

/**
 * Canal de sortie d'un job asynchrone.
 *
 * Remplace `sseEmit()` : au lieu d'écrire dans une connexion HTTP (qui meurt
 * dès que l'utilisateur change de page), le handler écrit dans `job_events`.
 * Le client s'y (ré)attache par SSE avec un curseur `seq`, donc rien n'est
 * perdu entre deux connexions.
 *
 * Les deltas de texte sont AGRÉGÉS (200 ms ou 4 Ko) : un INSERT par token
 * ferait ~30 écritures/s et par job pour aucun gain visible.
 */
class JobContext
{
    private const FLUSH_MS    = 200;
    private const FLUSH_BYTES = 4096;
    private const TICK_MS     = 2000;   // heartbeat + relecture du drapeau d'annulation

    private string $chunkBuffer = '';
    private float  $lastFlush;
    private float  $lastTick    = 0.0;
    private bool   $cancelled   = false;
    private int    $seq;

    public function __construct(
        private int $jobId,
        private int $userId,
        private ?int $conversationId,
        int $lastSeq = 0
    ) {
        $this->seq       = $lastSeq;
        $this->lastFlush = microtime(true);
    }

    public function jobId(): int { return $this->jobId; }
    public function userId(): int { return $this->userId; }
    public function conversationId(): ?int { return $this->conversationId; }

    /**
     * Émet un évènement. La charge utile est EXACTEMENT l'objet que le front
     * lit déjà en SSE ({chunk}, {image_progress}, {done…}) — le client n'a pas
     * à connaître la mécanique de file.
     */
    public function emit(array $event): void
    {
        // Delta de texte : on agrège pour ne pas marteler la base.
        if (array_key_exists('chunk', $event) && count($event) === 1) {
            $this->chunkBuffer .= (string)$event['chunk'];
            $elapsed = (microtime(true) - $this->lastFlush) * 1000;
            if ($elapsed >= self::FLUSH_MS || strlen($this->chunkBuffer) >= self::FLUSH_BYTES) {
                $this->flush();
            }
            $this->tick();
            return;
        }

        $this->flush();          // l'ordre des évènements doit être préservé
        $this->write($event);
        $this->tick();
    }

    /** Vide le tampon de deltas. À appeler avant toute écriture d'un autre type. */
    public function flush(): void
    {
        if ($this->chunkBuffer === '') {
            $this->lastFlush = microtime(true);
            return;
        }
        $buffer = $this->chunkBuffer;
        $this->chunkBuffer = '';
        $this->lastFlush   = microtime(true);
        $this->write(['chunk' => $buffer]);
    }

    public function setPhase(string $phase): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE async_jobs SET phase = ? WHERE id = ?');
        $stmt->execute([mb_substr($phase, 0, 40), $this->jobId]);
        $this->emit(['phase' => $phase]);
    }

    public function setProgress(int $percent): void
    {
        $percent = max(0, min(100, $percent));
        $stmt = Database::getInstance()->prepare('UPDATE async_jobs SET progress = ? WHERE id = ?');
        $stmt->execute([$percent, $this->jobId]);
    }

    /**
     * L'utilisateur a-t-il demandé l'arrêt ? Remplace `connection_aborted()` :
     * un worker CLI n'a aucune connexion à observer.
     */
    public function shouldStop(): bool
    {
        $this->tick(true);
        return $this->cancelled;
    }

    /** Heartbeat + relecture du drapeau d'annulation, au plus une fois par TICK_MS. */
    private function tick(bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && $this->lastTick > 0 && ($now - $this->lastTick) * 1000 < self::TICK_MS) {
            return;
        }
        if ($this->lastTick > 0 && ($now - $this->lastTick) * 1000 < 500) {
            return; // garde-fou : shouldStop() peut être appelé à chaque token
        }
        $this->lastTick = $now;

        $db = Database::getInstance();
        $db->prepare('UPDATE async_jobs SET heartbeat_at = NOW() WHERE id = ?')->execute([$this->jobId]);
        $sel = $db->prepare('SELECT cancel_requested FROM async_jobs WHERE id = ?');
        $sel->execute([$this->jobId]);
        $this->cancelled = ((int)($sel->fetchColumn() ?: 0)) === 1;
    }

    /** Écrit un évènement dans le flux rejouable et avance le curseur. */
    private function write(array $event): void
    {
        $this->seq++;
        $db   = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO job_events (job_id, seq, payload) VALUES (?, ?, ?)');
        $stmt->execute([
            $this->jobId,
            $this->seq,
            json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
        $db->prepare('UPDATE async_jobs SET last_seq = ? WHERE id = ?')->execute([$this->seq, $this->jobId]);
    }
}
