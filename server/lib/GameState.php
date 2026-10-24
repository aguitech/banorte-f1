<?php
declare(strict_types=1);

namespace Bf1\Game;

/**
 * Single source of truth for the live show state.
 *
 * Every mutation is one of the controller verbs (prepare/question/reveal/next/
 * reset/board) and each one stamps a monotonically increasing `rev` (revision).
 * Screens ignore any message whose `rev` is <= the last one they applied, so an
 * out-of-order or replayed frame can never rewind a screen.
 */
final class GameState
{
    /** Show phases. */
    public const PHASE_IDLE     = 'idle';       // lobby / attract loop
    public const PHASE_INTRO    = 'intro';      // next question teaser
    public const PHASE_QUESTION = 'question';   // answers visible, timer running
    public const PHASE_REVEAL   = 'reveal';     // correct answer highlighted
    public const PHASE_PODIUM   = 'podium';     // final standings

    public string $phase = self::PHASE_IDLE;

    /** @var array|null the live question (stripped of correctness for the main screen) */
    public ?array $current = null;
    public ?int   $currentId = null;

    /** @var array|null teaser for the upcoming question */
    public ?array $next = null;
    public ?int   $nextId = null;

    public float $deadline = 0.0;    // unix ts when the answer timer expires
    public float $startedAt = 0.0;    // unix ts when the question went live

    /** slot => telemetry record */
    public array $players = [];

    /** answer index => how many pads hit it this round (console-only) */
    public array $answerBreakdown = [];

    /** Tunables the console can change mid-show. */
    public array $settings = [
        'answerMs'  => 15000,   // answer window
        'introMs'   => 6000,    // teaser duration
        'fastMs'    => 2500,    // "v fastest" threshold
        'basePoints'=> 1000,    // points for a correct answer
        'speedBonus'=> 500,     // max speed bonus
        'streakStep'=> 250,     // per-streak bonus per question
    ];

    public float $lastRevealAt  = 0.0;
    public int   $questionIndex = 0;  // 0-based position in the current run
    public int   $questionsSeen = 0;

    private int $rev = 0;

    public function __construct()
    {
        $this->players = self::defaultRoster();
    }

    /** Bump and return the revision stamp every outgoing frame carries. */
    public function bump(): int
    {
        return ++$this->rev;
    }

    public function currentRev(): int
    {
        return $this->rev;
    }

    /**
     * Fixed 6-pad roster — the shape of the actual console hardware.
     * Labels are single letters so they're findable by touch in the dark.
     */
    public static function defaultRoster(): array
    {
        $roster = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $slot) {
            $roster[$slot] = [
                'slot'        => $slot,
                'name'        => 'Jugador ' . $slot,
                'points'      => 0,
                'correct'     => 0,
                'wrong'       => 0,
                'noAnswer'    => 0,
                'streak'      => 0,
                'best'        => 0,
                'wrongInARow' => 0,
                'lastTapMs'   => null,
                'fastestMs'   => null,
                'fastestQid'  => null,
                'lastAnswer'  => null,
                'lastDelta'   => 0,
                'correctIdx'  => null,
                'lobby'       => false,
            ];
        }
        return $roster;
    }

    /**
     * Public payload — what the main (broadcast) screen is allowed to know.
     *
     * ANTI-CHEAT RULE: the `ok` flag (which answer is correct) is exposed
     * ONLY once the show has revealed it. During `question` the main screen
     * gets bare answers with no correctness marker, so a player with a phone
     * and devtools cannot pre-read the answer.
     *
     * Once phase is `reveal`/`podium` the flag IS included — the whole point
     * of the reveal beat is that the big screen highlights the right answer.
     * This also fixes the cold-load case: a projector that reboots mid-show
     * re-reads the state and must still know which answer was correct.
     */
    public function toPublic(array $opts = []): array
    {
        $revealed = in_array($this->phase, [self::PHASE_REVEAL, self::PHASE_PODIUM], true);
        $withAnswer = ($opts['with_answer'] ?? false) || $revealed;

        return [
            'rev'           => $this->rev,
            'phase'         => $this->phase,
            'question'      => $this->shapeQuestion($withAnswer),
            'questionId'    => $this->currentId,
            'next'          => $this->shapeNext($withAnswer),
            'nextId'        => $this->nextId,
            'deadline'      => $this->deadline,
            'startedAt'     => $this->startedAt,
            'serverNow'     => microtime(true),
            'msLeft'        => $this->deadline > 0
                                ? max(0, (int) round(($this->deadline - microtime(true)) * 1000))
                                : 0,
            'questionIndex' => $this->questionIndex,
            'questionsSeen' => $this->questionsSeen,
            'lastRevealAt'  => $this->lastRevealAt,
            'players'       => array_values($this->players),
            'settings'      => $this->settings,
        ];
    }

    /** Console-only payload: adds the correct answer + per-round tap tally. */
    public function toConsole(): array
    {
        $p = $this->toPublic(['with_answer' => true]);
        $p['answerBreakdown'] = $this->answerBreakdown;
        return $p;
    }

    private function shapeQuestion(bool $withAnswer): ?array
    {
        if ($this->current === null) {
            return null;
        }
        return $this->shape($this->current, $withAnswer);
    }

    private function shapeNext(bool $withAnswer): ?array
    {
        if ($this->next === null) {
            return null;
        }
        return $this->shape($this->next, $withAnswer);
    }

    /**
     * Normalise a stored question into a wire payload.
     * Without `with_answer`, the correct index never leaves the server.
     */
    private function shape(array $q, bool $withAnswer): array
    {
        $answers = array_map(
            static fn(array $a): array => $withAnswer
                ? ['i' => $a['i'], 't' => $a['t'], 'ok' => (bool) $a['ok']]
                : ['i' => $a['i'], 't' => $a['t']],
            $q['answers']
        );

        return [
            'id'        => $q['id'],
            'category'  => $q['category'],
            'text'      => $q['text'],
            'answers'   => $answers,
            'timeMs'    => $q['timeMs'],
            'fact'      => $q['fact'],
            'difficulty'=> $q['difficulty'],
        ];
    }
}