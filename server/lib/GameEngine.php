<?php
declare(strict_types=1);

namespace Bf1\Game;

use Bf1\Ws\WsServer;

/**
 * The show director.
 *
 * Owns the GameState and turns controller verbs into state transitions plus
 * broadcast events. Everything is driven from the WS event loop's tick.
 *
 * TAP ARBITRATION — the one rule that matters for a touch trivia race:
 * a pad may lock an answer only ONCE per question. The first tap for a slot
 * wins; every later tap from that same slot is ignored (and reported back to
 * that pad so it can buzz/red-flash). Since the server is single-process, the
 * arrival order the engine sees is exactly the wire order — deterministic.
 */
final class GameEngine
{
    private GameState $state;
    private QuestionBank $bank;

    /** Questions already used this run — avoids immediate repeats. */
    private array $usedIds = [];

    /** Slot => ['answer'=>int,'ms'=>int,'at'=>float] — first tap per slot per question. */
    private array $taps = [];

    /** Set by prepare()/question() so the tick can auto-advance phases. */
    private bool $autoAdvance = true;

    private array $broadcastLog = [];

    public function __construct(
        QuestionBank $bank,
        GameState $state,
        private WsServer $ws,
        array $settings = []
    ) {
        $this->bank  = $bank;
        $this->state = $state;

        foreach ($settings as $k => $v) {
            if (array_key_exists($k, $this->state->settings)) {
                $this->state->settings[$k] = is_numeric($v) ? (int) $v : $v;
            }
        }
    }

    public function state(): GameState { return $this->state; }
    public function bank(): QuestionBank { return $this->bank; }

    public function setAutoAdvance(bool $on): void { $this->autoAdvance = $on; }

    // ------------------------------------------------------------------
    // Controller verbs
    // ------------------------------------------------------------------

    /**
     * PREPARE — draw the next question into the `next` slot as a teaser.
     * The main screen shows "¿Qué sigue?" with the category only.
     */
    public function prepare(array $opts = []): array
    {
        $category = $opts['category'] ?? $this->categoryHint();

        $q = isset($opts['questionId']) && (int) $opts['questionId'] > 0
            ? $this->bank->byId((int) $opts['questionId'])
            : $this->bank->draw(['category' => $category, 'exclude' => $this->usedIds]);

        if ($q === null) {
            // Pool exhausted for this category → fall back to the full pool.
            $q = $this->bank->draw(['category' => 'all', 'exclude' => []]);
        }
        if ($q === null) {
            return $this->fail('No hay preguntas en el banco.');
        }

        $this->usedIds[] = $q['id'];
        $this->state->next    = $q;
        $this->state->nextId  = $q['id'];
        $this->state->phase   = GameState::PHASE_INTRO;
        $this->state->deadline = microtime(true) + ($this->state->settings['introMs'] / 1000);

        return $this->commit('prepared', [
            'nextId' => $q['id'],
            'category' => $q['category'],
            'difficulty' => $q['difficulty'],
        ]);
    }

    /**
     * QUESTION — promote `next` (or a freshly drawn question) to the live
     * question, start the timer, and clear the tap table.
     */
    public function question(array $opts = []): array
    {
        $q = $this->state->next;

        if (isset($opts['questionId']) && (int) $opts['questionId'] > 0) {
            $forced = $this->bank->byId((int) $opts['questionId']);
            if ($forced !== null) {
                $q = $forced;
            }
        }
        if ($q === null) {
            return $this->fail('No hay pregunta preparada. Usa "prepare" primero.');
        }

        $this->state->current    = $q;
        $this->state->currentId  = $q['id'];
        $this->state->next       = null;
        $this->state->nextId     = null;
        $this->state->phase      = GameState::PHASE_QUESTION;
        $this->state->startedAt  = microtime(true);

        $ms = (int) ($opts['timeMs'] ?? $q['timeMs'] ?? $this->state->settings['answerMs']);
        $this->state->deadline   = $this->state->startedAt + ($ms / 1000);

        $this->state->questionIndex++;
        $this->state->questionsSeen++;

        // Reset per-question player fields.
        $this->taps = [];
        $this->state->answerBreakdown = [];
        foreach ($this->state->players as $slot => $p) {
            $this->state->players[$slot]['lastTapMs']  = null;
            $this->state->players[$slot]['lastAnswer']  = null;
            $this->state->players[$slot]['lastDelta']   = 0;
            $this->state->players[$slot]['correctIdx']  = null;
        }

        $this->bank->markPlayed($q['id']);

        return $this->commit('question', [
            'questionId' => $q['id'],
            'timeMs'     => $ms,
            'category'   => $q['category'],
        ]);
    }

    /**
     * TAP — a pad pressed an answer.
     *
     * Rejected silently-but-loudly when: wrong phase, slot already locked this
     * question, unknown slot, or the timer expired.
     */
    public function tap(string $slot, int $answer): array
    {
        if ($this->state->phase !== GameState::PHASE_QUESTION) {
            return $this->failToSlot($slot, 'no_phase', 'La pregunta no está activa.');
        }
        if (!isset($this->state->players[$slot])) {
            return $this->failToSlot($slot, 'bad_slot', "Slot desconocido: {$slot}");
        }
        if ($this->state->current === null) {
            return $this->failToSlot($slot, 'no_question', 'No hay pregunta activa.');
        }
        if (isset($this->taps[$slot])) {
            return $this->failToSlot($slot, 'locked', 'Ya respondiste esta pregunta.');
        }

        $answers = $this->state->current['answers'];
        if (!isset($answers[$answer])) {
            return $this->failToSlot($slot, 'bad_answer', 'Respuesta inválida.');
        }

        $now  = microtime(true);
        $ms   = (int) round(($now - $this->state->startedAt) * 1000);
        $late = $now > $this->state->deadline;

        // Lock it. First tap wins — this is the arbitration rule.
        $this->taps[$slot] = ['answer' => $answer, 'ms' => $ms, 'at' => $now];

        $this->state->players[$slot]['lastTapMs']  = $ms;
        $this->state->players[$slot]['lastAnswer']  = $answer;

        $idx = (int) ($answers[$answer]['i'] ?? $answer);
        $this->state->answerBreakdown[$idx] = ($this->state->answerBreakdown[$idx] ?? 0) + 1;

        if ($late) {
            $this->state->players[$slot]['noAnswer']++;
            $this->state->players[$slot]['wrongInARow']++;
            $this->state->players[$slot]['streak'] = 0;
            return $this->commit('tap_late', [
                'slot'   => $slot,
                'answer' => $answer,
                'ms'     => $ms,
            ], $slot);
        }

        return $this->commit('tap', [
            'slot'   => $slot,
            'answer' => $answer,
            'ms'     => $ms,
        ], $slot);
    }

    /**
     * REVEAL — stop the timer, score every locked tap, broadcast the outcome.
     *
     * Scoring:
     *   correct  → base + speedBonus*(remaining fraction) + streakStep*streak
     *   wrong    → streak resets, no points
     *   no tap   → noAnswer++, streak resets
     */
    public function reveal(): array
    {
        if ($this->state->current === null) {
            return $this->fail('No hay pregunta para revelar.');
        }
        if ($this->state->phase !== GameState::PHASE_REVEAL) {
            $this->state->deadline = microtime(true); // freeze the clock
        }

        $q       = $this->state->current;
        $answers = $q['answers'];
        $correct = null;
        foreach ($answers as $a) {
            if (!empty($a['ok'])) {
                $correct = (int) $a['i'];
                break;
            }
        }

        $s      = $this->state->settings;
        $totalMs = max(1, (int) ($this->state->deadline - $this->state->startedAt) * 1000);
        $elapsed = (int) round((microtime(true) - $this->state->startedAt) * 1000);
        $remainingFrac = max(0.0, min(1.0, 1.0 - ($elapsed / $totalMs)));

        $deltas  = [];
        $correctSlots = [];

        foreach ($this->taps as $slot => $tap) {
            $player = &$this->state->players[$slot];
            $isOk   = ((int) $tap['answer'] === $correct);

            $player['correctIdx'] = $correct;

            if ($isOk) {
                $player['correct']++;
                $player['streak']++;

                $speed = (int) round($s['speedBonus'] * $remainingFrac);
                $streakBonus = $s['streakStep'] * max(0, $player['streak'] - 1);
                $delta = $s['basePoints'] + $speed + $streakBonus;

                $player['points'] += $delta;
                $player['wrongInARow'] = 0;
                $player['best'] = max($player['best'], $player['streak']);

                if ($player['fastestMs'] === null || $tap['ms'] < $player['fastestMs']) {
                    $player['fastestMs']  = $tap['ms'];
                    $player['fastestQid'] = $q['id'];
                }

                $player['lastDelta'] = $delta;
                $deltas[$slot] = $delta;
                $correctSlots[] = $slot;
            } else {
                $player['wrong']++;
                $player['streak'] = 0;
                $player['wrongInARow']++;
                $player['lastDelta'] = 0;
            }
            unset($player);
        }

        // Pads that never touched the pad this round.
        foreach ($this->state->players as $slot => $player) {
            if (!isset($this->taps[$slot])) {
                $this->state->players[$slot]['noAnswer']++;
                $this->state->players[$slot]['streak'] = 0;
                $this->state->players[$slot]['wrongInARow']++;
            }
        }

        $this->state->phase        = GameState::PHASE_REVEAL;
        $this->state->lastRevealAt = microtime(true);

        // Winner of the round = highest scorer among those who got it right.
        $winnerSlot = null;
        $winnerName = null;
        if ($correctSlots !== []) {
            $best = null;
            foreach ($correctSlots as $slot) {
                $p = $this->state->players[$slot];
                if ($best === null
                    || $p['points'] > $this->state->players[$best]['points']
                    || ($p['points'] === $this->state->players[$best]['points'] && $p['lastTapMs'] < $this->state->players[$best]['lastTapMs'])) {
                    $best = $slot;
                }
            }
            $winnerSlot = $best;
            $winnerName = $this->state->players[$best]['name'];
        }

        $this->bank->saveHistory($q['id'], $q['category'], $this->state->players, $winnerSlot, $winnerName);

        return $this->commit('reveal', [
            'questionId'  => $q['id'],
            'correct'     => $correct,
            'correctText' => $answers[$correct]['t'] ?? null,
            'deltas'      => $deltas,
            'correctSlots'=> $correctSlots,
            'winnerSlot'  => $winnerSlot,
            'winnerName'  => $winnerName,
            'breakdown'   => $this->state->answerBreakdown,
        ]);
    }

    /**
     * NEXT — advance to the following question.
     * Draws fresh, moves straight into PHASE_QUESTION (skips the teaser when the
     * operator is in rapid-fire mode).
     */
    public function next(array $opts = []): array
    {
        $q = $this->bank->draw([
            'category' => $opts['category'] ?? 'all',
            'exclude'  => $this->usedIds,
        ]) ?? $this->bank->draw(['category' => 'all']);

        if ($q === null) {
            return $this->fail('El banco de preguntas está vacío.');
        }

        $this->usedIds[] = $q['id'];
        $this->state->next = $q;
        $this->state->nextId = $q['id'];

        return $this->question(['questionId' => $q['id'], 'timeMs' => $q['timeMs']]);
    }

    /** PODIUM — freeze scoring, show final standings. */
    public function podium(): array
    {
        $this->state->phase = GameState::PHASE_PODIUM;
        $this->state->deadline = 0.0;

        return $this->commit('podium', [
            'standings' => $this->standings(),
        ]);
    }

    /** RESET — back to the lobby, scores zeroed. */
    public function reset(array $opts = []): array
    {
        $keepNames = (bool) ($opts['keepNames'] ?? true);

        $names = [];
        if ($keepNames) {
            foreach ($this->state->players as $slot => $p) {
                $names[$slot] = $p['name'];
            }
        }

        $this->state->players    = GameState::defaultRoster();
        if ($keepNames) {
            foreach ($names as $slot => $name) {
                if ($name !== '' && $name !== 'Jugador ' . $slot) {
                    $this->state->players[$slot]['name'] = $name;
                }
            }
        }

        $this->state->current      = null;
        $this->state->currentId    = null;
        $this->state->next         = null;
        $this->state->nextId       = null;
        $this->state->phase        = GameState::PHASE_IDLE;
        $this->state->deadline     = 0.0;
        $this->state->startedAt    = 0.0;
        $this->state->questionIndex = 0;
        $this->state->questionsSeen = 0;
        $this->taps = [];

        return $this->commit('reset', ['roster' => array_column($this->state->players, 'name', 'slot')]);
    }

    /** Rename one pad — console-only. */
    public function rename(string $slot, string $name): array
    {
        $name = trim($name);
        if (!isset($this->state->players[$slot])) {
            return $this->fail("Slot desconocido: {$slot}");
        }
        if (mb_strlen($name) > 18) {
            $name = mb_substr($name, 0, 18);
        }
        $this->state->players[$slot]['name'] = $name;

        return $this->commit('rename', ['slot' => $slot, 'name' => $name]);
    }

    /** Adjust a tunable setting mid-show. */
    public function setSetting(string $key, int $value): array
    {
        if (!array_key_exists($key, $this->state->settings)) {
            return $this->fail("Ajuste desconocido: {$key}");
        }
        $this->state->settings[$key] = $value;

        return $this->commit('settings', ['key' => $key, 'value' => $value]);
    }

    public function standings(): array
    {
        $rows = array_values($this->state->players);
        usort($rows, static function (array $a, array $b): int {
            return [$b['points'], $b['correct'], -$a['lastTapMs']]
               <=> [$a['points'], $a['correct'], -$b['lastTapMs']];
        });

        $pos = 0;
        foreach ($rows as $i => $r) {
            $rows[$i]['pos'] = ++$pos;
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Tick — auto-advance + heartbeat
    // ------------------------------------------------------------------

    public function tick(): void
    {
        if (!$this->autoAdvance || $this->state->deadline <= 0.0) {
            return;
        }

        $now = microtime(true);

        // INTRO teaser elapsed → promote to the live question automatically.
        if ($this->state->phase === GameState::PHASE_INTRO && $now >= $this->state->deadline) {
            $this->question();
            return;
        }

        // Answer window elapsed → reveal automatically.
        if ($this->state->phase === GameState::PHASE_QUESTION && $now >= $this->state->deadline) {
            $this->reveal();
            return;
        }
    }

    /** Fast-forward the clock — console "skip timer" button. */
    public function skipTimer(): array
    {
        if ($this->state->deadline > 0.0) {
            $this->state->deadline = microtime(true);
        }
        return $this->commit('skip_timer', []);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function categoryHint(): string
    {
        // Keep a multi-category run balanced by cycling the pool.
        $cats = array_column($this->bank->categories(), 'slug');
        if ($cats === []) {
            return 'all';
        }
        return $cats[count($this->usedIds) % count($cats)];
    }

    /**
     * Apply a transition, stamp a revision, and push role-appropriate payloads.
     *
     * The main screen NEVER receives the correct answer until REVEAL, so a
     * curious player with a phone and devtools can't pre-read it.
     */
    private function commit(string $event, array $data, ?string $echoSlot = null): array
    {
        $this->state->bump();

        $public  = $this->state->toPublic();                       // main screen
        $console = $this->state->toConsole();                     // control screen

        $this->ws->broadcast([
            'type'    => 'state',
            'event'   => $event,
            'data'    => $data,
            'state'   => $public,
            'console' => $console,
        ]);

        $this->broadcastLog[] = ['event' => $event, 'at' => microtime(true)];
        if (count($this->broadcastLog) > 200) {
            array_shift($this->broadcastLog);
        }

        return ['ok' => true, 'event' => $event, 'rev' => $this->state->currentRev(), 'data' => $data];
    }

    private function fail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }

    /** Errors routed back to the pad that caused them, so it can buzz. */
    private function failToSlot(string $slot, string $code, string $msg): array
    {
        $this->ws->broadcast([
            'type'  => 'tap_rejected',
            'slot'  => $slot,
            'code'  => $code,
            'error' => $msg,
        ], null);

        return ['ok' => false, 'error' => $msg, 'code' => $code, 'slot' => $slot];
    }
}