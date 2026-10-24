<?php
declare(strict_types=1);

namespace Bf1\Ws;

/**
 * RFC 6455 frame codec — encode/decode, zero dependencies.
 *
 * Server→client frames are NEVER masked (RFC 6455 §5.1).
 * Client→server frames are ALWAYS masked — we unmask on decode.
 */
final class Frame
{
    public const OP_CONT  = 0x0;
    public const OP_TEXT  = 0x1;
    public const OP_BIN   = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING  = 0x9;
    public const OP_PONG  = 0xA;

    public const MAX_PAYLOAD = 262144; // 256 KB — a trivia tap is ~120 bytes

    /** Build a (unmasked) server frame. */
    public static function encode(string $payload, int $opcode = self::OP_TEXT): string
    {
        $len  = strlen($payload);
        $head = chr(0x80 | $opcode); // FIN=1 always: we never fragment

        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }

        return $head . $payload;
    }

    public static function text(string $payload): string
    {
        return self::encode($payload, self::OP_TEXT);
    }

    /**
     * Try to pull one complete frame off the front of $buf.
     *
     * @return array{opcode:int,fin:bool,payload:string,consumed:int}|null
     *         null  → need more bytes (caller keeps $buf as-is)
     *         ['close'=>...] → protocol violation, caller must drop the peer
     */
    public static function decode(string $buf, ?int &$consumed = null): ?array
    {
        $consumed = 0;
        $n = strlen($buf);
        if ($n < 2) {
            return null;
        }

        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);

        $fin    = (bool)($b0 & 0x80);
        $opcode = $b0 & 0x0F;
        $masked = (bool)($b1 & 0x80);
        $len    = $b1 & 0x7F;

        $offset = 2;

        // --- extended payload length -------------------------------------
        if ($len === 126) {
            if ($n < 4) return null;
            $len = unpack('n', substr($buf, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if ($n < 10) return null;
            // 'J' = unsigned 64-bit big endian
            $len = unpack('J', substr($buf, 2, 8))[1];
            $offset = 10;
        }

        if ($len > self::MAX_PAYLOAD) {
            return ['opcode' => self::OP_CLOSE, 'fin' => true, 'payload' => '', 'close' => 'frame too large'];
        }

        // --- masking key (mandatory from client) --------------------------
        $mask = '';
        if ($masked) {
            if ($n < $offset + 4) return null;
            $mask = substr($buf, $offset, 4);
            $offset += 4;
        }

        if ($n < $offset + $len) {
            return null; // payload still arriving
        }

        $payload = substr($buf, $offset, $len);
        if ($masked && $mask !== '') {
            $payload ^= str_pad($mask, $len, $mask, STR_PAD_RIGHT);
        }

        $consumed = $offset + $len;

        return ['opcode' => $opcode, 'fin' => $fin, 'payload' => $payload];
    }

    /** RFC 6455 §4.2.2 — close frame carries a 2-byte status + UTF-8 reason. */
    public static function close(int $code = 1000, string $reason = ''): string
    {
        return self::encode(pack('n', $code) . $reason, self::OP_CLOSE);
    }
}