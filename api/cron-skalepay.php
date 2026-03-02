<?php
/* ============================================
   Cron: SkalePay Pending Payment Checker

   Checks pending SkalePay transactions against the API.
   Lightweight: max 10 transactions per run, only last 2 hours.

   Setup cron (every 5 min):
   */5 * * * * php /home/user/public_html/api/cron-skalepay.php >> /dev/null 2>&1

   Or call via URL with secret:
   GET /api/cron-skalepay.php?key=ml2025
   ============================================ */

require_once __DIR__ . '/config.php';

// Auth: either CLI or secret key
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if ($key !== 'ml2025') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$payments = getPayments();
$auth = 'Basic ' . base64_encode(SKALEPAY_API_KEY . ':x');
$now = time();
$maxAge = 7200; // 2 hours
$maxChecks = 10;
$checked = 0;
$approved = 0;

writeLog('CRON_SKALEPAY_INICIO', ['max_checks' => $maxChecks, 'max_age_min' => $maxAge / 60]);

foreach ($payments as $code => $payment) {
    if ($checked >= $maxChecks) break;

    // Only pending SkalePay transactions
    if ($payment['status'] !== 'pending') continue;
    if (($payment['gateway'] ?? '') !== 'skalepay') continue;

    // Only recent (last 2 hours)
    $createdAt = strtotime($payment['created_at'] ?? '');
    if (!$createdAt || ($now - $createdAt) > $maxAge) continue;

    $gatewayId = $payment['gateway_id'] ?? '';
    if (!$gatewayId) continue;

    $checked++;

    // Poll SkalePay API
    $response = apiGet(
        SKALEPAY_API_URL . '/transactions/' . $gatewayId,
        ['Accept: application/json', 'Authorization: ' . $auth]
    );

    if ($response['status'] === 200 && isset($response['body']['status'])) {
        $skStatus = $response['body']['status'];

        if ($skStatus === 'paid') {
            $approvedAt = $response['body']['paidAt'] ?? date('Y-m-d H:i:s');
            $payments[$code]['status'] = 'paid';
            $payments[$code]['paid_at'] = $approvedAt;
            savePayments($payments);

            // Fire all tracking
            fireApprovalTracking($code, $payments[$code], $approvedAt);

            $approved++;

            writeLog('CRON_SKALEPAY_APROVADO', [
                'payment_code' => $code,
                'gateway_id' => $gatewayId,
                'valor' => 'R$ ' . number_format($payment['amount'] / 100, 2, ',', '.'),
                'nome' => $payment['customer']['name'] ?? '',
                'paidAt' => $approvedAt
            ]);
        }
    }

    // Small delay between requests to be gentle on shared hosting
    usleep(200000); // 200ms
}

$result = [
    'checked' => $checked,
    'approved' => $approved,
    'timestamp' => date('Y-m-d H:i:s')
];

writeLog('CRON_SKALEPAY_FIM', $result);

if (!$isCli) {
    echo json_encode($result);
}
