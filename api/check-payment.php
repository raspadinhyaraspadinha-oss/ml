<?php
/* ============================================
   Check Payment Status (Multi-Gateway)
   GET /api/check-payment.php?code=PAYMENT_CODE
   - Mangofy: reads local status (updated by webhook)
   - SkalePay: polls API in real-time, fires tracking on paid
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$paymentCode = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($paymentCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment code']);
    exit;
}

$payments = getPayments();

if (!isset($payments[$paymentCode])) {
    echo json_encode(['status' => 'not_found', 'payment_code' => $paymentCode]);
    exit;
}

$payment = $payments[$paymentCode];

// If already paid, return immediately
if ($payment['status'] === 'paid') {
    echo json_encode([
        'status' => 'paid',
        'payment_code' => $paymentCode,
        'paid_at' => $payment['paid_at']
    ]);
    exit;
}

// ── SkalePay: poll API for real-time status ──
$gateway = $payment['gateway'] ?? 'mangofy';

if ($gateway === 'skalepay' && $payment['status'] === 'pending') {
    $gatewayId = $payment['gateway_id'] ?? '';

    if ($gatewayId) {
        $auth = 'Basic ' . base64_encode(SKALEPAY_API_KEY . ':x');
        $response = apiGet(
            SKALEPAY_API_URL . '/transactions/' . $gatewayId,
            ['Accept: application/json', 'Authorization: ' . $auth]
        );

        if ($response['status'] === 200 && isset($response['body']['status'])) {
            $skStatus = $response['body']['status'];

            if ($skStatus === 'paid') {
                // Mark as paid
                $approvedAt = $response['body']['paidAt'] ?? date('Y-m-d H:i:s');
                $payments[$paymentCode]['status'] = 'paid';
                $payments[$paymentCode]['paid_at'] = $approvedAt;
                savePayments($payments);

                // Fire all tracking events (same as webhook.php)
                fireApprovalTracking($paymentCode, $payments[$paymentCode], $approvedAt);

                echo json_encode([
                    'status' => 'paid',
                    'payment_code' => $paymentCode,
                    'paid_at' => $approvedAt
                ]);
                exit;
            }
        }
    }
}

// Return current local status
echo json_encode([
    'status' => $payment['status'],
    'payment_code' => $paymentCode,
    'paid_at' => $payment['paid_at']
]);

// fireApprovalTracking() is now in config.php (shared)
