<?php
declare(strict_types=1);

namespace Bf1\Game;

/**
 * Question bank — SQLite-backed, seeded from server/lib/bank_f1.json.
 *
 * A question is: N answers, exactly one correct, plus an optional `fact` line
 * that the main screen shows during REVEAL — the "did you know" beat that keeps
 * the audience engaged between questions.
 *
 * The JSON file is the source of truth for seed content; SQLite is the runtime
 * store so the console can edit questions without redeploying.
 */
final class QuestionBank
{
    private ?\PDO $pdo = null;

    public function __construct(private string $dbPath)
    {
        $this->migrate();
        $this->seedIfEmpty();
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $this->pdo = new \PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            // WAL matters: the REST API reads this DB while the WS server writes it.
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA busy_timeout=4000');
        }
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS questions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    category   TEXT    NOT NULL DEFAULT 'f1',
    text       TEXT    NOT NULL,
    answers    TEXT    NOT NULL,   -- JSON: [{"i":0,"t":"...","ok":true}]
    correct    INTEGER NOT NULL,
    timeMs     INTEGER NOT NULL DEFAULT 15000,
    fact       TEXT,
    difficulty INTEGER NOT NULL DEFAULT 1,
    active     INTEGER NOT NULL DEFAULT 1,
    used_count INTEGER NOT NULL DEFAULT 0,
    last_used  TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
SQL);

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS history (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id  INTEGER,
    category     TEXT,
    ended_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    winner_slot  TEXT,
    winner_name  TEXT,
    points       INTEGER NOT NULL DEFAULT 0,
    scoreboard   TEXT
);
SQL);
    }

    public function count(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM questions WHERE active=1')->fetchColumn();
    }

    /** Draw a question, favouring the least-recently-used and least-played. */
    public function draw(array $opts = []): ?array
    {
        $category = $opts['category'] ?? null;
        $exclude  = array_values(array_filter((array) ($opts['exclude'] ?? []), 'is_numeric'));

        $sql = 'SELECT id, category, text, answers, correct, timeMs, fact, difficulty
                FROM questions
                WHERE active = 1';
        $params = [];

        if ($category !== null && $category !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($exclude !== []) {
            $in = implode(',', array_fill(0, count($exclude), '?'));
            $sql .= " AND id NOT IN ($in)";
            $params = array_merge($params, $exclude);
        }

        $sql .= ' ORDER BY COALESCE(last_used, \'\') ASC, used_count ASC, RANDOM() ASC LIMIT 1';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function byId(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, category, text, answers, correct, timeMs, fact, difficulty, active
             FROM questions WHERE id = ? AND active = 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    public function markPlayed(int $id): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE questions SET used_count = used_count + 1, last_used = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Console bank editor listing — supports ?q= search and ?active= filter. */
    public function listAll(array $opts = []): array
    {
        $limit  = max(1, min(500, (int) ($opts['limit'] ?? 100)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));
        $search = trim((string) ($opts['q'] ?? ''));
        $active = $opts['active'] ?? null;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = 'text LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if ($active !== null && $active !== 'all') {
            $where[] = 'active = ?';
            $params[] = (int) (bool) $active;
        }

        $sql = 'SELECT id, category, text, answers, correct, timeMs, fact, difficulty,
                       active, used_count, last_used
                FROM questions';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY active DESC, used_count ASC, id ASC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $r): array => $this->hydrate($r), $stmt->fetchAll());
    }

    public function totalCount(array $opts = []): int
    {
        $sql = 'SELECT COUNT(*) FROM questions';
        $params = [];
        if (isset($opts['q']) && trim((string) $opts['q']) !== '') {
            $sql .= " WHERE text LIKE ?";
            $params[] = '%' . trim((string) $opts['q']) . '%';
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Create or update a question. Returns the question id. */
    public function upsert(array $q): int
    {
        $text    = trim((string) ($q['text'] ?? ''));
        $answers = $q['answers'] ?? [];
        $correct = (int) ($q['correct'] ?? -1);

        if ($text === '') {
            throw new \InvalidArgumentException('El texto de la pregunta es obligatorio.');
        }
        if (!is_array($answers) || count($answers) < 2) {
            throw new \InvalidArgumentException('Se requieren al menos 2 respuestas.');
        }
        if ($correct < 0 || $correct >= count($answers)) {
            throw new \InvalidArgumentException('El índice de la respuesta correcta es inválido.');
        }

        $norm = [];
        foreach (array_values($answers) as $idx => $a) {
            $t = is_array($a) ? trim((string) ($a['t'] ?? '')) : trim((string) $a);
            if ($t === '') {
                throw new \InvalidArgumentException("La respuesta #{$idx} está vacía.");
            }
            $norm[] = ['i' => $idx, 't' => $t, 'ok' => $idx === $correct];
        }

        $payload   = json_encode($norm, JSON_UNESCAPED_UNICODE);
        $id        = isset($q['id']) && (int) $q['id'] > 0 ? (int) $q['id'] : 0;
        $category  = trim((string) ($q['category'] ?? 'f1')) ?: 'f1';
        $timeMs    = max(3000, min(60000, (int) ($q['timeMs'] ?? 15000)));
        $fact      = trim((string) ($q['fact'] ?? ''));
        $difficulty = max(1, min(3, (int) ($q['difficulty'] ?? 1)));
        $active    = isset($q['active']) ? (int) (bool) $q['active'] : 1;

        if ($id > 0) {
            $stmt = $this->pdo()->prepare(
                'UPDATE questions
                 SET category=?, text=?, answers=?, correct=?, timeMs=?, fact=?, difficulty=?, active=?
                 WHERE id=?'
            );
            $stmt->execute([$category, $text, $payload, $correct, $timeMs, $fact ?: null, $difficulty, $active, $id]);
            return $id;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO questions (category, text, answers, correct, timeMs, fact, difficulty, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$category, $text, $payload, $correct, $timeMs, $fact ?: null, $difficulty, $active]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE questions SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo()->prepare('DELETE FROM questions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function categories(): array
    {
        $rows = $this->pdo()
            ->query('SELECT category, COUNT(*) AS n FROM questions WHERE active = 1 GROUP BY category ORDER BY category')
            ->fetchAll();

        return array_map(
            static fn(array $r): array => ['slug' => (string) $r['category'], 'total' => (int) $r['n']],
            $rows
        );
    }

    /** Persist a finished round for the post-event leaderboard. */
    public function saveHistory(int $questionId, ?string $category, array $board, ?string $winnerSlot, ?string $winnerName): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO history (question_id, category, winner_slot, winner_name, points, scoreboard)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $questionId,
            $category,
            $winnerSlot,
            $winnerName,
            (int) ($board[$winnerSlot]['points'] ?? 0),
            json_encode($board, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Cross-session win leaderboard. */
    public function leaderboard(int $limit = 10): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT winner_slot, winner_name, COUNT(*) AS wins, SUM(points) AS points, MAX(ended_at) AS last_win
             FROM history
             WHERE winner_slot IS NOT NULL
             GROUP BY winner_slot, winner_name
             ORDER BY wins DESC, points DESC, last_win DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(array $r): array => [
            'slot'     => (string) $r['winner_slot'],
            'name'     => (string) $r['winner_name'],
            'wins'     => (int) $r['wins'],
            'points'   => (int) $r['points'],
            'last_win' => (string) $r['last_win'],
        ], $stmt->fetchAll());
    }

    private function hydrate(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'category'   => (string) $row['category'],
            'text'       => (string) $row['text'],
            'answers'    => json_decode((string) $row['answers'], true) ?: [],
            'correct'    => (int) $row['correct'],
            'timeMs'     => (int) $row['timeMs'],
            'fact'       => $row['fact'] ?? null,
            'difficulty' => (int) $row['difficulty'],
            'active'     => isset($row['active']) ? (int) $row['active'] : 1,
            'used_count' => isset($row['used_count']) ? (int) $row['used_count'] : 0,
            'last_used'  => $row['last_used'] ?? null,
        ];
    }

    /** Load the seed bank from JSON. Kept public so `tools/seed.php` can reuse it. */
    public static function seedFromFile(string $jsonPath): array
    {
        if (!is_file($jsonPath)) {
            return [];
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['questions']) || !is_array($data['questions'])) {
            throw new \RuntimeException("Banco inválido en {$jsonPath}: " . json_last_error_msg());
        }

        return $data['questions'];
    }

    private function seedIfEmpty(): void
    {
        if ($this->count() > 0) {
            return;
        }

        $seed = self::seedFromFile(__DIR__ . '/bank_f1.json');
        if ($seed === []) {
            fwrite(STDERR, "[banco] AVISO: bank_f1.json no encontrado o vacío\n");
            return;
        }

        foreach ($seed as $q) {
            $this->upsert([
                'category'   => $q['category'] ?? 'f1',
                'text'       => $q['text'] ?? '',
                'answers'    => array_map(static fn($t): array => ['t' => (string) $t], $q['answers'] ?? []),
                'correct'    => (int) ($q['ok'] ?? 0),
                'timeMs'     => (int) ($q['timeMs'] ?? 15000),
                'fact'       => $q['fact'] ?? '',
                'difficulty' => (int) ($q['difficulty'] ?? 1),
            ]);
        }

        fwrite(STDERR, "[banco] " . count($seed) . " preguntas sembradas\n");
    }
}