<?php
declare(strict_types=1);

/**
 * POST /api/questions.php  → alta/Edición de preguntas
 * POST /api/seed.php       → re-siembra el banco desde bank_f1.json (DESTRUCTIVO)
 *
 * seed.php borra las preguntas SEMBRA y vuelve a insertar el JSON. Úsalo sólo
 * para restaurar el banco por defecto; el editor de la consola es la vía
 * normal para añadir contenido.
 */

require __DIR__ . '/_helpers.php';
require __DIR__ . '/../server/lib/QuestionBank.php';

use Bf1\Game\QuestionBank;

$bank    = new QuestionBank(bf1_db_path());
$jsonPath = dirname(__DIR__) . '/server/lib/bank_f1.json';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail('Usa POST.', 405);
}

$body = json_body();

// Modo seguro por defecto: sólo añade lo que falte, no borra.
$wipe = (bool) ($body['wipe'] ?? false);

$seed = QuestionBank::seedFromFile($jsonPath);
if ($seed === []) {
    fail("No se pudo leer el banco desde {$jsonPath}", 500);
}

$existing = array_column($bank->listAll(['limit' => 500]), 'text');
$seen = array_flip($existing);

$added = 0;
$skipped = 0;

foreach ($seed as $q) {
    $text = (string) ($q['text'] ?? '');
    if ($text === '') {
        continue;
    }

    if ($wipe || !isset($seen[$text])) {
        $bank->upsert([
            'category'   => $q['category'] ?? 'f1',
            'text'       => $text,
            'answers'    => array_map(static fn($t): array => ['t' => (string) $t], $q['answers'] ?? []),
            'correct'    => (int) ($q['ok'] ?? 0),
            'timeMs'     => (int) ($q['timeMs'] ?? 15000),
            'fact'       => $q['fact'] ?? '',
            'difficulty' => (int) ($q['difficulty'] ?? 1),
        ]);
        $added++;
    } else {
        $skipped++;
    }
}

json_out([
    'ok'      => true,
    'added'   => $added,
    'skipped' => $skipped,
    'wipe'    => $wipe,
    'total'   => $bank->count(),
]);