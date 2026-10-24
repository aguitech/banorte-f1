<?php
declare(strict_types=1);

namespace Bf1\Ws;

/**
 * Single-process, non-forking WebSocket server built on stream_select().
 *
 * WHY SINGLE-PROCESS (no pcntl_fork):
 *   Tap arbitration in a trivia race is order-critical. With one process the
 *   arrival order the engine sees is EXACTLY the wire order — there is no
 *   cross-process race to reason about, and no shared-memory state to corrupt.
 *   For a kiosk with ~6 clients this is more than enough throughput.
 *
 * Usage:
 *   $ws = new WsServer('0.0.0.0', 8787);
 *   $ws->onMessage(function (WsConnection $c, array $msg): void { ... });
 *   $ws->onTick(function (): void { ... });          // ~20 Hz
 *   $ws->run();
 */
final class WsServer
{
    /** @var resource|null */
    private $listen = null;

    /** @var array<string, WsConnection> id => conn */
    private array $clients = [];

    /** @var callable */
    private $onMessage = [];

    /** @var callable */
    private $onTick = [];

    private ?float $tickAcc = null;

    private bool $running = false;

    private float $lastTick = 0.0;

    public function __construct(
        private string $host = '0.0.0.0',
        private int $port = 8787,
        private int $tickHz = 20
    ) {
    }

    public function onMessage(callable $fn): void { $this->onMessage = $fn; }
    public function onTick(callable $fn): void    { $this->onTick = $fn; }

    public function start(): void
    {
        $addr = "tcp://{$this->host}:{$this->port}";
        $ctx  = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $sock = @stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

        if ($sock === false) {
            throw new \RuntimeException("No se pudo abrir {$addr}: {$errstr} ({$errno})");
        }
        stream_set_blocking($sock, false);
        $this->listen = $sock;
        $this->running = true;
        $this->lastTick = microtime(true);

        fwrite(STDOUT, "[ws] escuchando en ws://{$this->host}:{$this->port}\n");
    }

    public function stop(): void { $this->running = false; }

    /** Broadcast a JSON payload to every connected client. */
    public function broadcast(array $payload, ?string $exceptRole = null): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $frame = Frame::text($json);
        foreach ($this->clients as $c) {
            if ($exceptRole !== null && $c->role === $exceptRole) {
                continue;
            }
            $c->write($frame);
        }
    }

    public function clientCount(): int { return count($this->clients); }

    public function run(): void
    {
        $tickEvery = 1.0 / max(1, $this->tickHz);

        while ($this->running) {
            $read   = [$this->listen];
            $write  = [];
            $except = null;

            foreach ($this->clients as $c) {
                $read[] = $c->sock;
                if ($c->wantWrite) {
                    $write[] = $c->sock;
                }
            }

            $sec  = (int) $tickEvery;
            $usec = (int) (($tickEvery - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);

            if ($ready === false) {
                // Interrupted by a signal — loop and re-check $running.
                continue;
            }

            if ($ready > 0) {
                foreach ($read as $sock) {
                    if ($sock === $this->listen) {
                        $this->acceptClient();
                    } else {
                        $this->handleReadable($sock);
                    }
                }
                foreach ($write as $sock) {
                    $this->findBySock($sock)?->flush();
                }
            }

            $now = microtime(true);
            if ($this->tickAcc === null || ($now - $this->tickAcc) >= $tickEvery) {
                $this->tickAcc = $now;
                if ($this->onTick) {
                    ($this->onTick)();
                }
            }
        }

        $this->shutdown();
    }

    private function acceptClient(): void
    {
        $sock = @stream_socket_accept($this->listen, 0);
        if ($sock === false) {
            return;
        }
        stream_set_blocking($sock, false);

        $conn = new WsConnection($sock, bin2hex(random_bytes(6)));
        $this->clients[$conn->id] = $conn;
        $conn->readBuf = '';

        // Peek the handshake without blocking: read what's already buffered.
        $meta = stream_get_meta_data($sock);
        $this->readHandshake($conn);
    }

    /**
     * Perform the RFC 6455 opening handshake on a freshly accepted socket.
     * Reads until the "\r\n\r\n" terminator or 4 KB, whichever comes first.
     */
    private function readHandshake(WsConnection $conn): void
    {
        $sock = $conn->sock;
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 8192);
            if ($chunk !== false && $chunk !== '') {
                $conn->readBuf .= $chunk;
            }

            $pos = strpos($conn->readBuf, "\r\n\r\n");
            if ($pos !== false) {
                $head = substr($conn->readBuf, 0, $pos);
                $conn->readBuf = substr($conn->readBuf, $pos + 4);

                if ($this->completeHandshake($conn, $head)) {
                    $conn->handshaken = true;
                    ($this->onMessage)($conn, [
                        'type'  => '__hello__',
                        'id'    => $conn->id,
                        'remote'=> $conn->remote,
                    ]);
                    // Any frames that arrived glued after the handshake are already in readBuf.
                    $this->drainFrames($conn);
                }
                return;
            }

            if (feof($sock)) {
                break;
            }
            // Not yet complete — poll briefly for the rest of the request.
            $r = [$sock]; $w = null; $x = null;
            if (@stream_select($r, $w, $x, 0, 200_000) > 0) {
                continue;
            }
            if (feof($sock)) {
                break;
            }
            usleep(20_000);
        }

        // Never completed → drop it silently.
        $this->drop($conn);
    }

    private function completeHandshake(WsConnection $conn, string $head): bool
    {
        $lines  = explode("\r\n", $head);
        $first  = array_shift($lines) ?? '';
        $headers = [];

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        if (!preg_match('#^GET\s+(\S+)\s+HTTP/1\.1#', $first, $m)) {
            return false;
        }
        if (($headers['upgrade'] ?? '') !== 'websocket') {
            $this->httpError($conn, 400, 'Expected WebSocket upgrade');
            return false;
        }
        $key = $headers['sec-websocket-key'] ?? '';
        if ($key === '') {
            $this->httpError($conn, 400, 'Missing Sec-WebSocket-Key');
            return false;
        }
        if (($headers['sec-websocket-version'] ?? '') !== '13') {
            $this->httpError($conn, 426, 'Only RFC 6455 version 13 supported');
            return false;
        }

        // §4.2.2 accept = base64(sha1(key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"))
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $resp = "HTTP/1.1 101 Switching Protocols\r\n"
              . "Upgrade: websocket\r\n"
              . "Connection: Upgrade\r\n"
              . "Sec-WebSocket-Accept: {$accept}\r\n"
              . "X-Powered-By: AGUITECH\r\n"
              . "\r\n";

        @fwrite($conn->sock, $resp);
        $conn->socketName = $m[1];

        return true;
    }

    private function httpError(WsConnection $conn, int $code, string $msg): void
    {
        $reason = $code === 426 ? 'Upgrade Required' : 'Bad Request';
        @fwrite($conn->sock, "HTTP/1.1 {$code} {$reason}\r\nContent-Type: text/plain\r\n"
            . "Content-Length: " . strlen($msg) . "\r\nConnection: close\r\n\r\n{$msg}");
        $this->drop($conn);
    }

    private function handleReadable($sock): void
    {
        $conn = $this->findBySock($sock);
        if ($conn === null) {
            return;
        }

        $chunk = @fread($sock, 65536);
        if ($chunk === false || ($chunk === '' && feof($sock))) {
            $this->drop($conn);
            return;
        }
        if ($chunk === '') {
            return; // no data yet, not EOF
        }

        $conn->readBuf .= $chunk;

        if (!$conn->handshaken) {
            $pos = strpos($conn->readBuf, "\r\n\r\n");
            if ($pos !== false) {
                $head = substr($conn->readBuf, 0, $pos);
                $conn->readBuf = substr($conn->readBuf, $pos + 4);
                if ($this->completeHandshake($conn, $head)) {
                    $conn->handshaken = true;
                    ($this->onMessage)($conn, ['type' => '__hello__', 'id' => $conn->id, 'remote' => $conn->remote]);
                }
            }
            return;
        }

        $this->drainFrames($conn);
    }

    private function drainFrames(WsConnection $conn): void
    {
        while ($conn->readBuf !== '') {
            $frame = Frame::decode($conn->readBuf, $consumed);
            if ($frame === null) {
                return; // partial frame — wait for more bytes
            }

            if (isset($frame['close'])) {
                $conn->write(Frame::close(1009, 'payload too large'));
                $this->drop($conn);
                return;
            }

            $conn->readBuf = substr($conn->readBuf, $consumed);

            switch ($frame['opcode']) {
                case Frame::OP_TEXT:
                    $msg = json_decode($frame['payload'], true);
                    if (is_array($msg)) {
                        ($this->onMessage)($conn, $msg);
                    } else {
                        $conn->sendJson(['type' => 'error', 'error' => 'JSON inválido']);
                    }
                    break;

                case Frame::OP_PING:
                    $conn->write(Frame::encode($frame['payload'], Frame::OP_PONG));
                    break;

                case Frame::OP_CLOSE:
                    $conn->write(Frame::close(1000, 'bye'));
                    $this->drop($conn);
                    return;
            }
        }
    }

    private function findBySock($sock): ?WsConnection
    {
        foreach ($this->clients as $c) {
            if ($c->sock === $sock) {
                return $c;
            }
        }
        return null;
    }

    public function drop(WsConnection $conn): void
    {
        unset($this->clients[$conn->id]);
        @fclose($conn->sock);
        if ($this->onMessage) {
            ($this->onMessage)($conn, ['type' => '__bye__', 'id' => $conn->id]);
        }
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $c) {
            $c->write(Frame::close(1001, 'server shutdown'));
            $c->flush();
            @fclose($c->sock);
        }
        $this->clients = [];
        if ($this->listen) {
            @fclose($this->listen);
            $this->listen = null;
        }
        fwrite(STDOUT, "[ws] cerrado\n");
    }
}