<?php
declare(strict_types=1);

namespace Bf1\Ws;

final class WsConnection
{
    /** @var resource */
    public $sock;

    public string $id;
    public string $readBuf = '';

    /** 'main' = green screen, 'touch' = handheld console, 'op' = operator/debug */
    public string $role = 'unknown';

    /** Stable per-connection label shown in the control console. */
    public string $label = '';

    /** Arbitrary slot the client claims on the control screen (A, B, C...). */
    public ?string $slot = null;

    public bool $handshaken = false;
    public bool $wantWrite  = false;

    public float $connectedAt;

    /** Outgoing buffer, drained by the event loop. */
    private string $outBuf = '';

    public string $socketName = '/';

    public string $remote;

    public function __construct($sock, string $id)
    {
        $this->sock         = $sock;
        $this->id           = $id;
        $this->connectedAt  = microtime(true);
        $this->remote       = self::guessRemote($sock);
    }

    public function write(string $bytes): void
    {
        $this->outBuf .= $bytes;
        $this->wantWrite = true;
        // Opportunistic inline write keeps latency low without starving the loop.
        $this->flush();
    }

    public function sendJson(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $this->write(Frame::text($json));
        }
    }

    public function flush(): void
    {
        if ($this->outBuf === '') {
            $this->wantWrite = false;
            return;
        }

        $n = @fwrite($this->sock, $this->outBuf);
        if ($n !== false && $n > 0) {
            $this->outBuf = substr($this->outBuf, $n);
        }

        if ($this->outBuf === '') {
            $this->wantWrite = false;
        } else {
            $this->wantWrite = true;
        }
    }

    private static function guessRemote($sock): string
    {
        $name = stream_socket_get_name($sock, true);
        return $name === false ? 'desconocido' : (string) $name;
    }
}