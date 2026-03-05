<?php
/* ============================================
   Check Payment Status (Multi-Gateway) v2.0
   GET /api/check-payment.php?code=PAYMENT_CODE
   - Mangofy: reads local status (updated by webhook)
   - SkalePay: polls API in real-time, fires tracking on paid
   - NitroPagamento: polls API in real-time

   v2.0 CHANGES:
   - Added 'paid_source' field for forensic tracking
   - Added logging when payment transitions to 'paid'
   - APCu throttle to reduce gateway API polling under load
   ============================================ */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

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
        'paid_at' => $payment['paid_at'],
        'paid_source' => $payment['paid_source'] ?? 'unknown'
    ]);
    exit;
}

// If already failed, return immediately with reason
if ($payment['status'] === 'failed') {
    echo json_encode([
        'status' => 'failed',
        'payment_code' => $paymentCode,
        'fail_reason' => $payment['fail_reason'] ?? 'gateway_error',
        'failed_at' => $payment['failed_at'] ?? null
    ]);
    exit;
}

// ── APCu throttle: don't poll the same payment more than once per 4 seconds ──
// Under 503 conditions, multiple frontend tabs or retries can create a poll storm
// hitting the gateway API too frequently. This throttle reduces API calls.
$gateway = $payment['gateway'] ?? 'mangofy';
$shouldPollGateway = true;

if (function_exists('apcu_enabled') && apcu_enabled()) {
    $throttleKey = 'ml_poll_' . $paymentCode;
    if (apcu_exists($throttleKey)) {
        $shouldPollGateway = false; // Already polled recently — skip API call
    } else {
        apcu_store($throttleKey, 1, 4); // Throttle for 4 seconds
    }
}

// ── SkalePay: poll API for real-time status ──
if ($gateway === 'skalepay' && $payment['status'] === 'pending' && $shouldPollGateway) {
    $gatewayId = $payment['gateway_id'] ?? '';

    if ($gatewayId) {
        $auth = 'Basic ' . base64_encode(SKALEPAY_API_KEY . ':x');
        $pollUrl = SKALEPAY_API_URL . '/transactions/' . $gatewayId;
        $response = apiGet($pollUrl, ['Accept: application/json', 'Authorization: ' . $auth]);

        // Only log on errors (not every 5s poll)
        if ($response['status'] !== 200 || !empty($response['error'])) {
            writeLog('SKALEPAY_POLL_ERRO', [
                'payment_code' => $paymentCode,
                'gateway_id' => $gatewayId,
                'http_status' => $response['status'],
                'error' => $response['error'] ?: 'none'
            ]);
        }

        if ($response['status'] === 200 && isset($response['body']['status'])) {
            $skStatus = $response['body']['status'];

            if ($skStatus === 'paid') {
                $approvedAt = $response['body']['paidAt'] ?? date('Y-m-d H:i:s');

                // Re-read payments to minimize race condition window
                $payments = getPayments();
                if (isset($payments[$paymentCode]) && $payments[$paymentCode]['status'] === 'pending') {
                    $payments[$paymentCode]['status'] = 'paid';
                    $payments[$paymentCode]['paid_at'] = $approvedAt;
                    $payments[$paymentCode]['paid_source'] = 'skalepay_poll';
                    savePayments($payments);

                    // Log payment confirmation (forensic tracking)
                    writeLog('PAYMENT_CONFIRMED', [
                        'payment_code' => $paymentCode,
                        'gateway' => 'skalepay',
                        'source' => 'poll',
                        'gateway_id' => $gatewayId,
                        'approved_at' => $approvedAt,
                        'amount' => $payment['amount'] ?? 0,
                        'gateway_status' => $skStatus
                    ]);

                    fireApprovalTracking($paymentCode, $payments[$paymentCode], $approvedAt);
                }

                echo json_encode([
                    'status' => 'paid',
                    'payment_code' => $paymentCode,
                    'paid_at' => $approvedAt
                ]);
                exit;
            }

            // Detect SkalePay error/refused statuses
            $skErrorStatuses = ['refused', 'error', 'chargedback', 'cancelled', 'expired', 'failed'];
            if (in_array($skStatus, $skErrorStatuses)) {
                $payments[$paymentCode]['status'] = 'failed';
                $payments[$paymentCode]['failed_at'] = date('Y-m-d H:i:s');
                $payments[$paymentCode]['fail_reason'] = $skStatus;
                savePayments($payments);

                writeLog('SKALEPAY_POLL_FALHOU', [
                    'payment_code' => $paymentCode,
                    'gateway_id' => $gatewayId,
                    'motivo' => $skStatus
                ]);

                echo json_encode([
                    'status' => 'failed',
                    'payment_code' => $paymentCode,
                    'fail_reason' => $skStatus
                ]);
                exit;
            }
        }
    }
}

// ── NitroPagamento: poll API for real-time status ──
if ($gateway === 'nitropagamento' && $payment['status'] === 'pending' && $shouldPollGateway) {
    $gatewayId = $payment['gateway_id'] ?? '';

    if ($gatewayId) {
        $auth = 'Basic ' . base64_encode(NITROPAGAMENTO_PK . ':' . NITROPAGAMENTO_SK);
        $pollUrl = NITROPAGAMENTO_API_URL . '/transactions/' . $gatewayId;
        $response = apiGet($pollUrl, ['Accept: application/json', 'Authorization: ' . $auth]);

        if ($response['status'] !== 200 || !empty($response['error'])) {
            writeLog('NITROPAGAMENTO_POLL_ERRO', [
                'payment_code' => $paymentCode,
                'gateway_id' => $gatewayId,
                'http_status' => $response['status'],
                'error' => $response['error'] ?: 'none'
            ]);
        }

        $npData = $response['body']['data'] ?? $response['body'] ?? [];
        $npStatus = $npData['status'] ?? '';

        if ($response['status'] === 200 && $npStatus !== '') {
            if ($npStatus === 'pago' || $npStatus === 'paid') {
                $approvedAt = $npData['paid_at'] ?? date('Y-m-d H:i:s');

                // Re-read payments to minimize race condition window
                $payments = getPayments();
                if (isset($payments[$paymentCode]) && $payments[$paymentCode]['status'] === 'pending') {
                    $payments[$paymentCode]['status'] = 'paid';
                    $payments[$paymentCode]['paid_at'] = $approvedAt;
                    $payments[$paymentCode]['paid_source'] = 'nitropagamento_poll';
                    savePayments($payments);

                    // Log payment confirmation (forensic tracking)
                    writeLog('PAYMENT_CONFIRMED', [
                        'payment_code' => $paymentCode,
                        'gateway' => 'nitropagamento',
                        'source' => 'poll',
                        'gateway_id' => $gatewayId,
                        'approved_at' => $approvedAt,
                        'amount' => $payment['amount'] ?? 0,
                        'gateway_status' => $npStatus
                    ]);

                    fireApprovalTracking($paymentCode, $payments[$paymentCode], $approvedAt);
                }

                echo json_encode([
                    'status' => 'paid',
                    'payment_code' => $paymentCode,
                    'paid_at' => $approvedAt
                ]);
                exit;
            }

            // NitroPagamento error statuses
            $npErrorStatuses = ['falhou', 'failed', 'expirado', 'expired', 'reembolsado', 'refunded', 'cancelled'];
            if (in_array($npStatus, $npErrorStatuses)) {
                $payments[$paymentCode]['status'] = 'failed';
                $payments[$paymentCode]['failed_at'] = date('Y-m-d H:i:s');
                $payments[$paymentCode]['fail_reason'] = $npStatus;
                savePayments($payments);

                writeLog('NITROPAGAMENTO_POLL_FALHOU', [
                    'payment_code' => $paymentCode,
                    'gateway_id' => $gatewayId,
                    'motivo' => $npStatus
                ]);

                echo json_encode([
                    'status' => 'failed',
                    'payment_code' => $paymentCode,
                    'fail_reason' => $npStatus
                ]);
                exit;
            }
        }
    }

    // Check for stale NitroPagamento payments (no webhook/poll after 60min)
    $createdAt = strtotime($payment['created_at'] ?? '');
    $minutesElapsed = $createdAt ? (time() - $createdAt) / 60 : 0;
    if ($minutesElapsed > 60) {
        $payments[$paymentCode]['status'] = 'failed';
        $payments[$paymentCode]['failed_at'] = date('Y-m-d H:i:s');
        $payments[$paymentCode]['fail_reason'] = 'expired_no_payment';
        savePayments($payments);

        writeLog('NITROPAGAMENTO_PIX_EXPIRADO', [
            'payment_code' => $paymentCode,
            'minutos_desde_criacao' => round($minutesElapsed, 1)
        ]);

        echo json_encode([
            'status' => 'failed',
            'payment_code' => $paymentCode,
            'fail_reason' => 'expired_no_payment'
        ]);
        exit;
    }
}

// ── Mangofy: check for stale pending payments (no webhook after 60min) ──
if ($gateway === 'mangofy' && $payment['status'] === 'pending') {
    $createdAt = strtotime($payment['created_at'] ?? '');
    $minutesElapsed = $createdAt ? (time() - $createdAt) / 60 : 0;

    if ($minutesElapsed > 60) {
        $payments[$paymentCode]['status'] = 'failed';
        $payments[$paymentCode]['failed_at'] = date('Y-m-d H:i:s');
        $payments[$paymentCode]['fail_reason'] = 'expired_no_payment';
        savePayments($payments);

        writeLog('MANGOFY_PIX_EXPIRADO', [
            'payment_code' => $paymentCode,
            'minutos_desde_criacao' => round($minutesElapsed, 1)
        ]);

        echo json_encode([
            'status' => 'failed',
            'payment_code' => $paymentCode,
            'fail_reason' => 'expired_no_payment'
        ]);
        exit;
    }
}

// Return current local status — cache 3s to reduce polling frequency
header('Cache-Control: private, max-age=3');
echo json_encode([
    'status' => $payment['status'],
    'payment_code' => $paymentCode,
    'paid_at' => $payment['paid_at'] ?? null
]);
