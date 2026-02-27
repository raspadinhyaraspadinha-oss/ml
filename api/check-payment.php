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

// ═══════════════════════════════════════════
//  TRACKING EVENTS ON APPROVAL
// ═══════════════════════════════════════════

function fireApprovalTracking($paymentCode, $payment, $approvedAt) {
    $customer = $payment['customer'];
    $amount = $payment['amount'];
    $items = $payment['items'] ?? [];
    $tracking = $payment['tracking'] ?? [];
    $usedGateway = $payment['gateway'] ?? 'skalepay';

    // ── UTMify "paid" ──
    $utmifyProducts = [];
    foreach ($items as $item) {
        $utmifyProducts[] = [
            'id' => $paymentCode, 'name' => $item['name'] ?? 'Produto',
            'planId' => $item['id'] ?? 'item', 'planName' => $item['name'] ?? 'Produto',
            'quantity' => intval($item['quantity'] ?? 1), 'priceInCents' => intval($item['price'] ?? $amount)
        ];
    }
    if (empty($utmifyProducts)) {
        $utmifyProducts[] = ['id' => $paymentCode, 'name' => 'Pedido', 'planId' => 'order', 'planName' => 'Pedido', 'quantity' => 1, 'priceInCents' => $amount];
    }

    $utmifyPayload = [
        'orderId' => $paymentCode, 'platform' => 'MercadoLivre25Anos', 'paymentMethod' => 'pix',
        'status' => 'paid', 'createdAt' => $payment['created_at'], 'approvedDate' => $approvedAt, 'refundedAt' => null,
        'customer' => [
            'name' => strtoupper($customer['name'] ?? ''), 'email' => $customer['email'] ?? '',
            'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
            'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
            'country' => 'BR', 'ip' => $customer['ip'] ?? '0.0.0.0'
        ],
        'products' => $utmifyProducts,
        'trackingParameters' => [
            'src' => $tracking['src'] ?? null, 'sck' => $tracking['sck'] ?? null,
            'utm_source' => $tracking['utm_source'] ?? null, 'utm_campaign' => $tracking['utm_campaign'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? null, 'utm_content' => $tracking['utm_content'] ?? null,
            'utm_term' => $tracking['utm_term'] ?? null, 'fbclid' => $tracking['fbclid'] ?? null,
            'fbp' => $tracking['fbp'] ?? null
        ],
        'commission' => ['totalPriceInCents' => $amount, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $amount],
        'isTest' => false
    ];

    $utmifyResult = apiPost(UTMIFY_API_URL, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_API_TOKEN], $utmifyPayload);
    writeApiEvent($paymentCode, 'paid', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
        ['orderId' => $paymentCode, 'status' => 'paid', 'amount' => $amount / 100, 'gateway' => $usedGateway],
        $utmifyResult['body'] ?? $utmifyResult['raw'], $tracking,
        $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300);

    // ── Facebook CAPI Purchase ──
    $nameParts = explode(' ', trim($customer['name'] ?? ''));
    $firstName = $nameParts[0] ?? '';
    $lastName = count($nameParts) > 1 ? end($nameParts) : '';

    $fbEventData = ['data' => [[
        'event_name' => 'Purchase', 'event_id' => 'pur_' . $paymentCode . '_' . time(),
        'event_time' => time(), 'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
        'action_source' => 'website',
        'user_data' => array_filter([
            'em' => [hash('sha256', strtolower(trim($customer['email'] ?? '')))],
            'ph' => [hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? ''))],
            'fn' => [hash('sha256', strtolower(trim($firstName)))],
            'ln' => [hash('sha256', strtolower(trim($lastName)))],
            'client_ip_address' => $customer['ip'] ?? '0.0.0.0',
            'client_user_agent' => $payment['user_agent'] ?? '',
            'fbc' => $tracking['fbc'] ?? null, 'fbp' => $tracking['fbp'] ?? null
        ], function($v) { return $v !== null && $v !== ''; }),
        'custom_data' => [
            'currency' => 'BRL', 'value' => $amount / 100,
            'content_ids' => array_map(function($item) { return $item['id'] ?? 'item'; }, $items),
            'content_type' => 'product', 'order_id' => $paymentCode
        ]
    ]]];

    $fbUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . FB_ACCESS_TOKEN;
    $fbResult = apiPost($fbUrl, ['Content-Type: application/json'], $fbEventData);
    writeApiEvent($paymentCode, 'Purchase', 'facebook_capi', $fbUrl, $fbResult['status'],
        ['event_name' => 'Purchase', 'value' => $amount / 100, 'event_id' => $fbEventData['data'][0]['event_id']],
        $fbResult['body'] ?? $fbResult['raw'], $tracking, $fbResult['status'] === 200);
    if ($fbResult['status'] !== 200) {
        writeLog('FB_CAPI_ERRO', ['evento' => 'Purchase', 'payment_code' => $paymentCode, 'http_status' => $fbResult['status'], 'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')]);
    }

    // ── TikTok Events API - CompletePayment ──
    $ttContentIds = array_map(function($item) { return ['content_id' => $item['id'] ?? 'item', 'content_name' => $item['name'] ?? 'Produto', 'quantity' => intval($item['quantity'] ?? 1), 'price' => ($item['price'] ?? $amount) / 100]; }, $items);
    $ttResult = sendTikTokEvent('CompletePayment', 'tt_cp_' . $paymentCode . '_' . time(), [
        'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
        'phone' => hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? '')),
        'ip' => $customer['ip'] ?? '0.0.0.0', 'user_agent' => $payment['user_agent'] ?? ''
    ], ['contents' => $ttContentIds, 'content_type' => 'product', 'currency' => 'BRL', 'value' => $amount / 100, 'order_id' => $paymentCode]);
    if ($ttResult) {
        writeApiEvent($paymentCode, 'CompletePayment', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttResult['status'],
            ['event' => 'CompletePayment', 'value' => $amount / 100, 'event_id' => 'tt_cp_' . $paymentCode],
            $ttResult['body'] ?? $ttResult['raw'], $tracking, $ttResult['status'] === 200);
        if ($ttResult['status'] !== 200) {
            writeLog('TIKTOK_API_ERRO', ['evento' => 'CompletePayment', 'payment_code' => $paymentCode, 'http_status' => $ttResult['status'], 'erro' => $ttResult['error'] ?: ($ttResult['raw'] ?? 'sem resposta')]);
        }
    }

    // Log
    writeLog('PAGAMENTO_APROVADO', [
        'gateway' => $usedGateway, 'payment_code' => $paymentCode,
        'valor' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
        'nome' => $customer['name'] ?? '', 'email' => $customer['email'] ?? '',
        'telefone' => $customer['phone'] ?? '', 'aprovado_em' => $approvedAt
    ]);
}
