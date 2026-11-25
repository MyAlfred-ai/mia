<?php

declare(strict_types=1);

/**
 * Client WebSocket minimal (RFC 6455), côté serveur, sans bibliothèque —
 * écrit pour UN usage : la lecture à voix haute par l'API Realtime d'OpenAI
 * (cf. RealtimeSpeech), seul moyen de faire parler `gpt-realtime-2.1-mini`,
 * le remplaçant désigné des modèles de `POST /v1/audio/speech`.
 *
 * Ce qu'il fait, et rien de plus : connexion TLS vérifiée, poignée de main
 * HTTP/1.1 avec en-têtes (la clé part dans `Authorization`, jamais dans
 * l'adresse), trames texte masquées vers le serveur, lecture des trames du
 * serveur (fragments recollés, `ping` → `pong`, `close` → fin). Pas
 * d'extension (`permessage-deflate` n'est jamais demandée), pas de proxy, pas
 * de redirection suivie.
 *
 * Les formes de trame et de poignée de main sont PURES (testées sans réseau) ;
 * seules `connect`, `sendText`, `receive` et `close` touchent la socket.
 */
final class WebSocketClient
{
    /** Constante de la RFC 6455 (calcul de `Sec-WebSocket-Accept`). */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT         = 0x1;
    public const OP_BINARY       = 0x2;
    public const OP_CLOSE        = 0x8;
    public const OP_PING         = 0x9;
    public const OP_PONG         = 0xA;

    /** Plafond d'un message reçu (les deltas audio Realtime pèsent quelques dizaines de Ko). */
    public const MAX_MESSAGE_BYTES = 16 * 1024 * 1024;

    /** @var resource */
    private $socket;
    private string $buffer = '';
    private bool $closed = false;

    /** @param resource $socket */
    private function __construct($socket, string $leftover)
    {
        $this->socket = $socket;
        $this->buffer = $leftover;
    }

    // ===================== Formes pures =====================

    /** Valeur attendue de `Sec-WebSocket-Accept` pour une clé donnée. */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /**
     * Requête de poignée de main. `$headers` = lignes « Nom: valeur » ; les
     * retours à la ligne y sont refusés (aucune injection d'en-tête).
     *
     * @param list<string> $headers
     */
    public static function handshakeRequest(string $host, string $path, string $key, array $headers): string
    {
        $lines = [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . $host,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
        ];
        foreach ($headers as $header) {
            if (preg_match('/[\r\n\0]/', $header) || !str_contains($header, ':')) {
                throw new \InvalidArgumentException('En-tête WebSocket invalide.');
            }
            $lines[] = $header;
        }
        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * Lit la réponse à la poignée de main : statut, en-têtes (noms en
     * minuscules), corps éventuel (refus : 401, 403, 429… avec un JSON
     * d'erreur), et si la mise à niveau est valide (101 + clé acceptée).
     *
     * @return array{status:int, headers:array<string,string>, body:string, ok:bool}
     */
    public static function parseHandshake(string $head, string $key, string $body = ''): array
    {
        $lines  = preg_split('/\r\n/', $head) ?: [];
        $status = 0;
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string)($lines[0] ?? ''), $m)) {
            $status = (int)$m[1];
        }
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
        $ok = $status === 101
            && strtolower($headers['upgrade'] ?? '') === 'websocket'
            && ($headers['sec-websocket-accept'] ?? '') === self::acceptKey($key);
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'ok' => $ok];
    }

    /**
     * Encode une trame. Le client DOIT masquer ce qu'il envoie (RFC 6455
     * § 5.3) : `$mask` = 4 octets, tirés au hasard s'il est absent.
     */
    public static function encodeFrame(string $payload, int $opcode = self::OP_TEXT, ?string $mask = null, bool $fin = true): string
    {
        $mask ??= random_bytes(4);
        if (strlen($mask) !== 4) {
            throw new \InvalidArgumentException('Le masque d\'une trame fait 4 octets.');
        }
        $len   = strlen($payload);
        $frame = chr(($fin ? 0x80 : 0x00) | ($opcode & 0x0F));
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        return $frame . $mask . self::xorMask($payload, $mask);
    }

    /**
     * Décode la PREMIÈRE trame complète du tampon, ou null s'il en manque
     * encore une partie. `length` = octets consommés. Une trame masquée (ce
     * que le serveur ne doit pas envoyer) est quand même démasquée.
     *
     * @return array{fin:bool, opcode:int, payload:string, length:int}|null
     */
    public static function decodeFrame(string $buffer): ?array
    {
        $size = strlen($buffer);
        if ($size < 2) {
            return null;
        }
        $b1     = ord($buffer[0]);
        $b2     = ord($buffer[1]);
        $masked = ($b2 & 0x80) !== 0;
        $len    = $b2 & 0x7F;
        $pos    = 2;
        if ($len === 126) {
            if ($size < 4) {
                return null;
            }
            $len = unpack('n', substr($buffer, 2, 2))[1];
            $pos = 4;
        } elseif ($len === 127) {
            if ($size < 10) {
                return null;
            }
            $len = unpack('J', substr($buffer, 2, 8))[1];
            $pos = 10;
        }
        if ($len < 0 || $len > self::MAX_MESSAGE_BYTES) {
            throw new \RuntimeException('Trame WebSocket trop grande.');
        }
        $mask = '';
        if ($masked) {
            if ($size < $pos + 4) {
                return null;
            }
            $mask = substr($buffer, $pos, 4);
            $pos += 4;
        }
        if ($size < $pos + $len) {
            return null;
        }
        $payload = substr($buffer, $pos, $len);
        if ($masked) {
            $payload = self::xorMask($payload, $mask);
        }
        return [
            'fin'     => ($b1 & 0x80) !== 0,
            'opcode'  => $b1 & 0x0F,
            'payload' => $payload,
            'length'  => $pos + $len,
        ];
    }

    private static function xorMask(string $payload, string $mask): string
    {
        $len = strlen($payload);
        if ($len === 0) {
            return '';
        }
        $key = str_repeat($mask, intdiv($len, 4) + 1);
        return $payload ^ substr($key, 0, $len);
    }

    // ===================== Réseau =====================

    /**
     * Ouvre la connexion. Lève une WebSocketHandshakeException portant le
     * statut et le corps quand le serveur refuse la mise à niveau (clé
     * refusée, crédit épuisé, modèle inconnu) — l'appelant les présente.
     *
     * @param list<string> $headers lignes « Nom: valeur »
     */
    public static function connect(string $url, array $headers, float $timeout = 15.0): self
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'wss' || empty($parts['host'])) {
            throw new \InvalidArgumentException('Adresse WebSocket invalide (wss:// attendu).');
        }
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? 443);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
            'peer_name'        => $host,
        ]]);
        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client('tls://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Connexion WebSocket impossible : ' . ($errstr !== '' ? $errstr : 'erreur ' . $errno));
        }
        stream_set_timeout($socket, (int)ceil($timeout));

        $key = base64_encode(random_bytes(16));
        $request = self::handshakeRequest($host . ($port !== 443 ? ':' . $port : ''), $path, $key, $headers);
        if (@fwrite($socket, $request) === false) {
            fclose($socket);
            throw new \RuntimeException('Poignée de main WebSocket impossible à envoyer.');
        }

        // En-têtes de la réponse, puis — refus — son corps (Content-Length).
        $raw      = '';
        $deadline = microtime(true) + $timeout;
        while (!str_contains($raw, "\r\n\r\n")) {
            if (microtime(true) > $deadline || feof($socket)) {
                fclose($socket);
                throw new \RuntimeException('Le serveur WebSocket n\'a pas répondu à la poignée de main.');
            }
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                fclose($socket);
                throw new \RuntimeException('Lecture de la poignée de main WebSocket impossible.');
            }
            $raw .= $chunk;
            if (strlen($raw) > 65536) {
                fclose($socket);
                throw new \RuntimeException('Poignée de main WebSocket démesurée.');
            }
        }
        [$head, $rest] = explode("\r\n\r\n", $raw, 2);
        $answer = self::parseHandshake($head, $key);
        if (!$answer['ok']) {
            $want = (int)($answer['headers']['content-length'] ?? 0);
            $body = $rest;
            while ($want > 0 && strlen($body) < min($want, 65536) && !feof($socket) && microtime(true) < $deadline) {
                $chunk = fread($socket, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            fclose($socket);
            throw new WebSocketHandshakeException($answer['status'], substr($body, 0, 65536));
        }
        return new self($socket, $rest);
    }

    public function sendText(string $payload): void
    {
        $this->write(self::encodeFrame($payload, self::OP_TEXT));
    }

    /**
     * Prochain message du serveur (texte ou binaire, fragments recollés), ou
     * null quand la connexion se ferme. Répond seul aux `ping`. Lève une
     * RuntimeException si rien n'arrive avant `$deadline` (microtime).
     *
     * @return array{opcode:int, payload:string}|null
     */
    public function receive(float $deadline): ?array
    {
        $message = '';
        $opcode  = null;
        while (true) {
            while (($frame = self::decodeFrame($this->buffer)) === null) {
                if ($this->closed) {
                    return null;
                }
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new \RuntimeException('Délai dépassé en attendant le serveur WebSocket.');
                }
                stream_set_timeout($this->socket, (int)max(1, min(10, ceil($left))));
                $chunk = @fread($this->socket, 65536);
                if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                    $this->closed = true;
                    return null;
                }
                $this->buffer .= $chunk;
            }
            $this->buffer = (string)substr($this->buffer, $frame['length']);

            switch ($frame['opcode']) {
                case self::OP_PING:
                    $this->write(self::encodeFrame($frame['payload'], self::OP_PONG));
                    continue 2;
                case self::OP_PONG:
                    continue 2;
                case self::OP_CLOSE:
                    $this->closed = true;
                    return null;
                case self::OP_TEXT:
                case self::OP_BINARY:
                    $opcode  = $frame['opcode'];
                    $message = $frame['payload'];
                    break;
                case self::OP_CONTINUATION:
                    $message .= $frame['payload'];
                    break;
                default:
                    continue 2; // opcode réservé : ignoré
            }
            if (strlen($message) > self::MAX_MESSAGE_BYTES) {
                throw new \RuntimeException('Message WebSocket trop grand.');
            }
            if ($frame['fin'] && $opcode !== null) {
                return ['opcode' => $opcode, 'payload' => $message];
            }
        }
    }

    /** Ferme proprement (trame `close` 1000), sans attendre la réponse. */
    public function close(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        if (!$this->closed) {
            try {
                $this->write(self::encodeFrame(pack('n', 1000), self::OP_CLOSE));
            } catch (\Throwable) {
                // la connexion est déjà perdue : rien à faire
            }
        }
        $this->closed = true;
        @fclose($this->socket);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function write(string $bytes): void
    {
        $total = strlen($bytes);
        $sent  = 0;
        while ($sent < $total) {
            $n = @fwrite($this->socket, substr($bytes, $sent));
            if ($n === false || $n === 0) {
                $this->closed = true;
                throw new \RuntimeException('Envoi WebSocket impossible.');
            }
            $sent += $n;
        }
    }
}

/** Refus de la poignée de main : statut HTTP et corps (JSON d'erreur du fournisseur). */
final class WebSocketHandshakeException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
        parent::__construct('Poignée de main WebSocket refusée (HTTP ' . $status . ').');
    }
}
