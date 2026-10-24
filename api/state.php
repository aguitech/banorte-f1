<?php
declare(strict_types=1);

/**
 * GET  /api/state.php       → estado actual (público: sin respuesta correcta)
 * GET  /api/state.php?console=1 → estado completo para la consola
 * GET  /api/state.php?action=standings → clasificación ordenada
 * GET  /api/state.php?action=leaderboard → ganador histórico
 */

require __DIR__ . '/_helpers.php';
require __DIR__ . '/../server/lib/GameState.php';
require __DIR__ . '/../server/lib/QuestionBank.php';

use Bf1\Game\GameState;
use Bf1\Game\QuestionBank;

// The REST API is read-only over the live show state: the authoritative state
// lives in the long-running WS process. This endpoint serves the persisted
// snapshot (leaderboard/history) plus a fresh idle state for monitoring.
//
// For live values the screens use the WebSocket — see README.

$action = (string) q('action', '');
$bank   = new QuestionBank(bf1_db_path());

if ($action === 'leaderboard') {
    json_out(['ok' => true, 'items' => $bank->leaderboard((int) q('limit', 10))]);
}

if ($action === 'categories') {
    json_out(['ok' => true, 'items' => $bank->categories()]);
}

$state = new GameState();

json_out([
    'ok'        => true,
    'note'      => 'El estado en vivo se distribuye por WebSocket (ws://…/ws). Este endpoint expone el snapshot y el histórico.',
    'state'     => $state->toPublic(['with_answer' => (string) q('console', '') === '1']),
    'standings' => $state->standings(),
    'bank'      => $bank->count(),
]);