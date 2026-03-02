<?php
/* ============================================
   Retry Failed TikTok Events API
   POST /api/retry-tiktok.php
   Body: { password, event_indices: [0,1,2,...] } or { password, retry_all_failed: true }

   Re-sends failed TikTok events from api_events.json
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Simple password protection
if (($input['password'] ?? '') !== 'ml2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Load API events
$file = DATA_DIR . 'api_events.json';
if (!file_exists($file)) {
    echo json_encode(['error' => 'No API events file', 'retried' => 0]);
    exit;
}

$events = json_decode(file_get_contents($file), true);
if (!is_array($events)) {
    echo json_encode(['error' => 'Invalid events file', 'retried' => 0]);
    exit;
}

// Load payments for user data
$payments = getPayments();

// Find failed TikTok events
$failedIndices = [];
$retryAllFailed = isset($input['retry_all_failed']) && $input['retry_all_failed'] === true;

if ($retryAllFailed) {
    // Find all failed TikTok events
    foreach ($events as $idx => $evt) {
        if ($evt['api'] === 'tiktok_events' && !$evt['success']) {
            $failedIndices[] = $idx;
        }
    }
} elseif (isset($input['event_indices']) && is_array($input['event_indices'])) {
    $failedIndices = array_map('intval', $input['event_indices']);
} else {
    echo json_encode(['error' => 'Provide event_indices or retry_all_failed', 'retried' => 0]);
    exit;
}

$results = [];
$successCount = 0;
$failCount = 0;

foreach ($failedIndices as $idx) {
    if (!isset($events[$idx])) continue;
    $evt = $events[$idx];

    // Only retry TikTok events
    if ($evt['api'] !== 'tiktok_events') continue;

    $paymentCode = $evt['payment_code'] ?? '';
    $eventName = $evt['event'] ?? '';
    $request = $evt['request'] ?? [];

    if (!$paymentCode || !$eventName) {
        $results[] = ['index' => $idx, 'event' => $eventName, 'status' => 'skipped', 'reason' => 'missing data'];
        continue;
    }

    // Rebuild TikTok event from payment data
    $payment = $payments[$paymentCode] ?? null;
    $customer = $payment['customer'] ?? [];
    $tracking = $payment['tracking'] ?? [];
    $amount = $payment['amount'] ?? 0;
    $items = $payment['items'] ?? [];
    $clientIp = $payment['client_ip'] ?? '0.0.0.0';

    // Build user data
    $ttUserData = array_filter([
        'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
        'phone_number' => hash('sha256', '+55' . preg_replace('/\D/', '', $customer['phone'] ?? '')),
        'external_id' => hash('sha256', $paymentCode),
        'ip' => $clientIp,
        'user_agent' => $payment['user_agent'] ?? '',
        'ttclid' => $tracking['ttclid'] ?? null
    ], function($v) { return $v !== null && $v !== ''; });

    // Build contents
    $ttContents = array_map(function($item) use ($amount) {
        return [
            'content_id' => $item['id'] ?? 'item',
            'content_name' => $item['name'] ?? 'Produto',
            'content_type' => 'product',
            'quantity' => intval($item['quantity'] ?? 1),
            'price' => ($item['price'] ?? $amount) / 100
        ];
    }, $items);

    // Build properties based on event type
    $properties = [
        'contents' => $ttContents,
        'content_type' => 'product',
        'currency' => 'BRL',
        'value' => $amount / 100
    ];

    if ($eventName === 'PlaceAnOrder' || $eventName === 'CompletePayment') {
        $properties['order_id'] = $paymentCode;
    }
    if ($eventName === 'AddPaymentInfo') {
        $properties['description'] = 'PIX';
    }

    // Use original event_id with retry suffix to avoid true duplicates
    $originalEventId = $request['event_id'] ?? $request['event'] ?? '';
    $retryEventId = $originalEventId ?: ($eventName . '_retry_' . $paymentCode . '_' . time());

    // Determine event time - use payment creation time if available
    $eventTime = time();
    if ($payment && $eventName === 'CompletePayment' && !empty($payment['paid_at'])) {
        $eventTime = strtotime($payment['paid_at']) ?: time();
    } elseif ($payment && !empty($payment['created_at'])) {
        $eventTime = strtotime($payment['created_at']) ?: time();
    }

    // Send
    $result = sendTikTokEvent($eventName, $retryEventId, $ttUserData, $properties, $eventTime);

    if ($result) {
        $success = $result['status'] === 200;

        // Log the retry
        writeApiEvent(
            $paymentCode,
            $eventName . ' (retry)',
            'tiktok_events',
            'https://business-api.tiktok.com/open_api/v1.3/event/track/',
            $result['status'],
            ['event' => $eventName, 'value' => $amount / 100, 'event_id' => $retryEventId, 'retry' => true],
            $result['body'] ?? $result['raw'],
            $tracking,
            $success
        );

        if ($success) {
            $successCount++;
            // Update original event to mark as retried
            $events[$idx]['retried'] = true;
            $events[$idx]['retry_time'] = date('Y-m-d H:i:s');
        } else {
            $failCount++;
        }

        $results[] = [
            'index' => $idx,
            'event' => $eventName,
            'payment_code' => $paymentCode,
            'status' => $success ? 'success' : 'failed',
            'http_status' => $result['status'],
            'response' => $result['body'] ?? $result['raw']
        ];
    } else {
        $results[] = ['index' => $idx, 'event' => $eventName, 'status' => 'skipped', 'reason' => 'TikTok not configured'];
    }

    // Small delay between requests to avoid rate limiting
    usleep(200000); // 200ms
}

// Save updated events (with retried flags)
file_put_contents($file, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

writeLog('TIKTOK_RETRY', [
    'total_retried' => count($failedIndices),
    'success' => $successCount,
    'failed' => $failCount
]);

echo json_encode([
    'success' => true,
    'retried' => count($results),
    'success_count' => $successCount,
    'fail_count' => $failCount,
    'results' => $results
]);
