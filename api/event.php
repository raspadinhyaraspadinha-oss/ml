<?php
/* ============================================
   Internal Analytics Event Recorder
   POST /api/event.php
   Body: { event, session_id, page, funnel_stage, url, referrer, timestamp, data, utms }

   Stores events in data/events/ directory (daily files)
   Max 50,000 events per day file, auto-rotates
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event field']);
    exit;
}

// ── Build event record ──
$record = [
    'event'        => substr($input['event'] ?? '', 0, 100),
    'session_id'   => substr($input['session_id'] ?? '', 0, 100),
    'page'         => substr($input['page'] ?? '', 0, 200),
    'funnel_stage' => intval($input['funnel_stage'] ?? 0),
    'url'          => substr($input['url'] ?? '', 0, 2048),
    'referrer'     => substr($input['referrer'] ?? '', 0, 2048),
    'data'         => is_array($input['data']) ? $input['data'] : [],
    'utms'         => is_array($input['utms']) ? $input['utms'] : [],
    'ip'           => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
    'server_time'  => date('c'),
    'client_time'  => substr($input['timestamp'] ?? '', 0, 50)
];

// ── Write to daily events file ──
$eventsDir = DATA_DIR . 'events/';
if (!is_dir($eventsDir)) {
    mkdir($eventsDir, 0755, true);
}

$today = date('Y-m-d');
$file = $eventsDir . 'events_' . $today . '.json';
$maxRecords = 50000;

$fp = fopen($file, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $contents = '';
    $size = filesize($file);
    if ($size > 0) {
        $contents = fread($fp, $size);
    }

    $data = ($contents !== '' && $contents !== false) ? json_decode($contents, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    // Append
    $data[] = $record;

    // Trim if over limit
    if (count($data) > $maxRecords) {
        $data = array_slice($data, -$maxRecords);
    }

    // Rewrite
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['ok' => true]);
} else {
    if ($fp) fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Could not write event']);
}
