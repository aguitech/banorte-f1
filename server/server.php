<?php
declare(strict_types=1);

/**
 * Banorte-F1 · WebSocket show server
 *
 * Single process, single file entrypoint. Owns the game engine and routes
 * incoming client messages to controller verbs.
 *
 * Run:  php server/server.php [--host=0.0.0.0] [--port=8787] [--db=storage/banorte.sqlite]
 */

require __DIR__ . '/lib/Frame.php';
require __DIR__ . '/lib/WsConnection.php';
require __DIR__ . '/lib/WsServer.php';
require __DIR__ . '/lib/GameState.php';
require __DIR__ . '/lib/QuestionBank.php';
require __DIR__ . '/lib/GameEngine.php';

use Bf1\Game\GameEngine;
use Bf1\Game\GameState;
use Bf1\Game\QuestionBank;
use Bf1\Ws\WsConnection;
use Bf1\Ws\WsServer;

// ---------------------------------------------------------------------------
// Config from argv / env
// ---------------------------------------------------------------------------
$opts = getopt('', ['host::', 'port::', 'db::', 'hz::', 'host-only']);

$host    = (string) ($opts['host']    ?? getenv('BF1_HOST') ?: '0.0.0.0');
$port    = (int)    ($opts['port']    ?? getenv('BF1_PORT') ?: 8787);
$dbPath  = (string) ($opts['db']      ?? getenv('BF1_DB')   ?: dirname(__DIR__) . '/storage/banorte.sqlite');
$tickHz  = (int)    ($opts['hz']      ?? getenv('BF1_HZ')   ?: 20);

$bank   = new QuestionBank($dbPath);
$state  = new GameState();
$ws     = new WsServer($host, $port, $tickHz);
$engine = new GameEngine($bank, $state, $ws);

$ws->onMessage(function (WsConnection $c, array $msg) use ($engine, $ws): void {
    // --- connection lifecycle -----------------------------------------
    if ($msg['type'] === '__hello__') {
        $c->label = 'anon';
        $c->sendJson([
            'type'  => 'welcome',
            'id'    => $c->id,
            'bank'  => $engine->bank()->count(),
            'state' => $engine->state()->toConsole(),
        ]);
        return;
    }

    if ($msg['type'] === '__bye__') {
        return;
    }

    $type = (string) ($msg['type'] ?? '');

    // --- role handshake ------------------------------------------------
    if ($type === 'hello') {
        $role = (string) ($msg['role'] ?? 'unknown');
        if (!in_array($role, ['main', 'touch', 'op'], true)) {
            $role = 'unknown';
        }
        $c->role = $role;
        $c->label = substr((string) ($msg['label'] ?? ''), 0, 24);
        $slot = strtoupper(substr((string) ($msg['slot'] ?? ''), 0, 2));
        $c->slot = in_array($slot, ['A','B','C','D','E','F'], true) ? $slot : null;

        $c->sendJson([
            'type'  => 'welcome',
            'id'    => $c->id,
            'role'  => $c->role,
            'bank'  => $engine->bank()->count(),
            'state' => $c->role === 'main'
                        ? $engine->state()->toPublic()
                        : $engine->state()->toConsole(),
        ]);

        // Let the console know a screen came online.
        $ws->broadcast([
            'type'   => 'presence',
            'id'     => $c->id,
            'role'   => $c->role,
            'label'  => $c->label,
            'slot'   => $c->slot,
            'action' => 'join',
        ], 'main');

        return;
    }

    // --- pad taps ------------------------------------------------------
    if ($type === 'tap') {
        $slot   = strtoupper(substr((string) ($msg['slot'] ?? ''), 0, 2));
        $answer = (int) ($msg['answer'] ?? -1);
        $engine->tap($slot, $answer);
        return;
    }

    // --- ping (clock sync) ---------------------------------------------
    if ($type === 'ping') {
        $c->sendJson(['type' => 'pong', 'clientNow' => $msg['t'] ?? null, 'serverNow' => microtime(true)]);
        return;
    }

    // --- everything below is console/operator-only ----------------------
    if ($c->role === 'main' || $c->role === 'touch') {
        // Screens are read-only by design. Ignoring a stray verb is safer
        // than letting a stray tap on a projector-side laptop alter the show.
        if (in_array($type, ['prepare','question','reveal','next','reset','podium','rename','setting','skip','board'], true)) {
            $c->sendJson(['type' => 'error', 'error' => 'Rol sin permisos para esa acción.']);
        }
        return;
    }

    switch ($type) {
        case 'prepare':
            $engine->prepare((array)($msg['opts'] ?? []));
            break;

        case 'question':
            $engine->question((array)($msg['opts'] ?? []));
            break;

        case 'reveal':
            $engine->reveal();
            break;

        case 'next':
            $engine->next((array)($msg['opts'] ?? []));
            break;

        case 'podium':
            $engine->podium();
            break;

        case 'reset':
            $engine->reset((array)($msg['opts'] ?? []));
            break;

        case 'rename':
            $engine->rename((string)($msg['slot'] ?? ''), (string)($msg['name'] ?? ''));
            break;

        case 'setting':
            $engine->setSetting((string)($msg['key'] ?? ''), (int)($msg['value'] ?? 0));
            break;

        case 'skip':
            $engine->skipTimer();
            break;

        case 'standings':
            $c->sendJson(['type' => 'standings', 'items' => $engine->standings()]);
            break;

        case 'board':
            $c->sendJson(['type' => 'leaderboard', 'items' => $engine->bank()->leaderboard(10)]);
            break;

        case 'autopilot':
            $engine->setAutoAdvance((bool)($msg['on'] ?? true));
            break;

        default:
            $c->sendJson(['type' => 'error', 'error' => "Mensaje desconocido: {$type}"]);
    }
});

// Tick drives auto-advance (intro teaser → question, timer → reveal).
$ws->onTick(function () use ($engine): void {
    $engine->tick();
});

// Graceful shutdown on Ctrl-C / SIGTERM.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use ($ws): void {
            fwrite(STDOUT, "\n[ws] señal recibida, cerrando...\n");
            $ws->stop();
        });
    }
}

try {
    $ws->start();
    fwrite(STDOUT, "[banorte-f1] banco: {$bank->count()} preguntas · pantalla principal + consola táctil\n");
    $ws->run();
} catch (Throwable $e) {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
    exit(1);
}