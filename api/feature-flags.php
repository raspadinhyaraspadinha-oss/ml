<?php
/* ============================================
   Feature Flags API
   GET  → returns current flags (public, cached by JS)
   POST → update flags (password-protected)
   ============================================ */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

$flagsFile = DATA_DIR . 'feature_flags.json';

// ── GET: Return current flags ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // HTTP cache: 5 minutes (matches JS sessionStorage TTL)
    header('Cache-Control: public, max-age=300');
    $flags = getFeatureFlags();
    echo json_encode($flags);
    exit;
}

// ── POST: Update flags (requires dashboard password) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Simple auth: same password as dashboard
    $password = $input['password'] ?? '';
    if ($password !== 'ml2025') {
        http_response_code(403);
        echo json_encode(['error' => 'Senha incorreta']);
        exit;
    }

    // Load current flags
    $flags = getFeatureFlags();

    // Update global killswitch
    if (isset($input['global_killswitch'])) {
        $flags['global_killswitch'] = (bool)$input['global_killswitch'];
    }

    // Update individual flags
    if (isset($input['flag']) && isset($input['enabled'])) {
        $flagName = $input['flag'];
        if (isset($flags['flags'][$flagName])) {
            $flags['flags'][$flagName]['enabled'] = (bool)$input['enabled'];
        }
    }

    // Bulk update
    if (isset($input['flags']) && is_array($input['flags'])) {
        foreach ($input['flags'] as $name => $val) {
            if (isset($flags['flags'][$name])) {
                $flags['flags'][$name]['enabled'] = (bool)$val;
            }
        }
    }

    $flags['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($flagsFile, json_encode($flags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    writeLog('FEATURE_FLAGS_UPDATED', [
        'killswitch' => $flags['global_killswitch'] ? 'ON' : 'OFF',
        'flags' => json_encode(array_map(function($f) { return $f['enabled'] ? 'ON' : 'OFF'; }, $flags['flags']))
    ]);

    echo json_encode(['success' => true, 'flags' => $flags]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
