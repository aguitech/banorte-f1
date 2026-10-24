<?php
declare(strict_types=1);

/**
 * GET  /api/questions.php         → listado paginado del banco
 * POST /api/questions.php         → crear pregunta
 * PUT  /api/questions.php?id=N    → editar pregunta
 * DELETE /api/questions.php?id=N  → baja física
 *
 * Also serves ?action=toggle&id=N for a soft activate/deactivate.
 */

require __DIR__ . '/_helpers.php';
require __DIR__ . '/../server/lib/QuestionBank.php';

use Bf1\Game\QuestionBank;

$bank = new QuestionBank(bf1_db_path());
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET': {
            $action = (string) q('action', '');

            if ($action === 'categories') {
                json_out(['ok' => true, 'items' => $bank->categories()]);
            }

            if ($action === 'one') {
                $id = (int) q('id', 0);
                $item = $id > 0 ? $bank->byId($id) : null;
                if ($item === null) {
                    fail('Pregunta no encontrada', 404);
                }
                json_out(['ok' => true, 'item' => $item]);
            }

            $items = $bank->listAll([
                'limit'  => (int) q('limit', 100),
                'offset' => (int) q('offset', 0),
                'q'      => (string) q('q', ''),
                'active' => q('active'),
            ]);
            $total = $bank->totalCount(['q' => (string) q('q', '')]);

            json_out([
                'ok'     => true,
                'total'  => $total,
                'limit'  => (int) q('limit', 100),
                'offset' => (int) q('offset', 0),
                'items'  => $items,
            ]);
        }

        case 'POST': {
            $body = json_body();
            $id = $bank->upsert($body);
            json_out(['ok' => true, 'id' => $id, 'item' => $bank->byId($id)]);
        }

        case 'PUT': {
            $body = json_body();
            $id = (int) ($body['id'] ?? q('id', 0));
            if ($id <= 0) {
                fail('Falta el id de la pregunta.');
            }
            $body['id'] = $id;
            $bank->upsert($body);
            json_out(['ok' => true, 'id' => $id, 'item' => $bank->byId($id)]);
        }

        case 'DELETE': {
            $id = (int) q('id', 0);
            if ($id <= 0) {
                fail('Falta el id de la pregunta.');
            }
            json_out(['ok' => $bank->delete($id), 'id' => $id]);
        }

        default:
            fail('Método no permitido', 405);
    }
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    fail('Error interno: ' . $e->getMessage(), 500);
}