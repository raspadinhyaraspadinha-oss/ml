<?php
/* ============================================
   Gateway Toggle API
   POST /api/gateway.php  { "gateway": "skalepay" | "mangofy" }
   GET  /api/gateway.php  → returns active gateway
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'active_gateway' => getActiveGateway(),
        'options' => ['mangofy', 'skalepay']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $gw = $input['gateway'] ?? '';

    if (!in_array($gw, ['mangofy', 'skalepay'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid gateway. Must be "mangofy" or "skalepay"']);
        exit;
    }

    setActiveGateway($gw);
    writeLog('GATEWAY_ALTERADO', ['para' => $gw]);

    echo json_encode([
        'success' => true,
        'active_gateway' => $gw
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
