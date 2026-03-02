<?php
/* ============================================
   Experiments API (A/B Testing Engine)
   GET  → returns experiment config (public, cached by JS)
   POST → create/update experiments (password-protected)
   ============================================ */

require_once __DIR__ . '/config.php';

$expFile = DATA_DIR . 'experiments.json';

// Ensure file exists
if (!file_exists($expFile)) {
    file_put_contents($expFile, json_encode(['experiments' => [], 'updated_at' => null], JSON_PRETTY_PRINT), LOCK_EX);
}

// ── GET: Return experiment config ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = json_decode(file_get_contents($expFile), true);
    if (!is_array($data)) $data = ['experiments' => []];

    // For public endpoint: only return running experiments (or all if ?all=1 from dashboard)
    if (!isset($_GET['all'])) {
        $running = [];
        foreach (($data['experiments'] ?? []) as $id => $exp) {
            if (($exp['status'] ?? '') === 'running') {
                $running[$id] = $exp;
            }
        }
        echo json_encode(['experiments' => $running]);
    } else {
        echo json_encode($data);
    }
    exit;
}

// ── POST: Manage experiments ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $password = $input['password'] ?? '';
    if ($password !== 'ml2025') {
        http_response_code(403);
        echo json_encode(['error' => 'Senha incorreta']);
        exit;
    }

    $data = json_decode(file_get_contents($expFile), true);
    if (!is_array($data)) $data = ['experiments' => []];

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create':
            $exp = $input['experiment'] ?? null;
            if (!$exp || !isset($exp['id'])) {
                echo json_encode(['error' => 'Experimento inválido']);
                exit;
            }
            $data['experiments'][$exp['id']] = $exp;
            $data['updated_at'] = date('Y-m-d H:i:s');
            file_put_contents($expFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

            writeLog('EXPERIMENT_CREATED', [
                'id' => $exp['id'],
                'name' => $exp['name'] ?? '',
                'status' => $exp['status'] ?? 'draft'
            ]);

            echo json_encode(['success' => true]);
            break;

        case 'update_status':
            $id = $input['id'] ?? '';
            $status = $input['status'] ?? '';
            if (!isset($data['experiments'][$id])) {
                echo json_encode(['error' => 'Experimento não encontrado']);
                exit;
            }
            $validStatuses = ['draft', 'running', 'paused', 'completed'];
            if (!in_array($status, $validStatuses)) {
                echo json_encode(['error' => 'Status inválido']);
                exit;
            }
            $data['experiments'][$id]['status'] = $status;
            $data['updated_at'] = date('Y-m-d H:i:s');
            file_put_contents($expFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

            writeLog('EXPERIMENT_STATUS_CHANGED', [
                'id' => $id,
                'new_status' => $status
            ]);

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $id = $input['id'] ?? '';
            if (isset($data['experiments'][$id])) {
                unset($data['experiments'][$id]);
                $data['updated_at'] = date('Y-m-d H:i:s');
                file_put_contents($expFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                writeLog('EXPERIMENT_DELETED', ['id' => $id]);
            }
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['error' => 'Ação desconhecida']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
