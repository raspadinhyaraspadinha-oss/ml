<?php
/* ============================================
   Mangofy Webhook Handler
   Receives POST callbacks when payment is approved
   ============================================ */

require_once __DIR__ . '/config.php';

// Always return 200 to prevent Mangofy retries
http_response_code(200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['payment_code'])) {
    echo json_encode(['status' => 'ok', 'message' => 'no payment_code']);
    exit;
}

$paymentCode = $input['payment_code'];
$paymentStatus = $input['payment_status'] ?? '';
$approvedAt = $input['approved_at'] ?? date('Y-m-d H:i:s');

// Log all webhook calls
writeLog('WEBHOOK_RECEBIDO', [
    'payment_code' => $paymentCode,
    'status' => $paymentStatus
]);

// ── Handle ERROR statuses (gateway_error, refused, expired, cancelled) ──
$errorStatuses = ['gateway_error', 'refused', 'expired', 'cancelled', 'chargedback', 'error'];
if (in_array($paymentStatus, $errorStatuses)) {
    $payments = getPayments();
    if (isset($payments[$paymentCode]) && $payments[$paymentCode]['status'] === 'pending') {
        $payments[$paymentCode]['status'] = 'failed';
        $payments[$paymentCode]['failed_at'] = date('Y-m-d H:i:s');
        $payments[$paymentCode]['fail_reason'] = $paymentStatus;
        savePayments($payments);

        writeLog('MANGOFY_PAGAMENTO_FALHOU', [
            'payment_code' => $paymentCode,
            'motivo' => $paymentStatus,
            'valor' => 'R$ ' . number_format(($payments[$paymentCode]['amount'] ?? 0) / 100, 2, ',', '.'),
            'nome' => $payments[$paymentCode]['customer']['name'] ?? ''
        ]);

        // Notify UTMify about the failure
        $payment = $payments[$paymentCode];
        $tracking = $payment['tracking'] ?? [];
        $utmifyPayload = [
            'orderId' => $paymentCode,
            'platform' => 'MercadoLivre25Anos',
            'paymentMethod' => 'pix',
            'status' => 'refused',
            'createdAt' => $payment['created_at'] ?? date('Y-m-d H:i:s'),
            'approvedDate' => null,
            'refundedAt' => null,
            'customer' => [
                'name' => strtoupper($payment['customer']['name'] ?? ''),
                'email' => $payment['customer']['email'] ?? '',
                'phone' => preg_replace('/\D/', '', $payment['customer']['phone'] ?? ''),
                'document' => preg_replace('/\D/', '', $payment['customer']['document'] ?? ''),
                'country' => 'BR',
                'ip' => $payment['customer']['ip'] ?? '0.0.0.0'
            ],
            'products' => [['id' => $paymentCode, 'name' => 'Pedido', 'planId' => 'order', 'planName' => 'Pedido', 'quantity' => 1, 'priceInCents' => $payment['amount'] ?? 0]],
            'trackingParameters' => [
                'src' => $tracking['src'] ?? null, 'sck' => $tracking['sck'] ?? null,
                'utm_source' => $tracking['utm_source'] ?? null, 'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null, 'utm_content' => $tracking['utm_content'] ?? null,
                'utm_term' => $tracking['utm_term'] ?? null
            ],
            'commission' => ['totalPriceInCents' => $payment['amount'] ?? 0, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $payment['amount'] ?? 0],
            'isTest' => false
        ];
        $utmifyResult = apiPost(UTMIFY_API_URL, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_API_TOKEN], $utmifyPayload);
        writeApiEvent($paymentCode, 'refused', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
            ['orderId' => $paymentCode, 'status' => 'refused', 'reason' => $paymentStatus],
            $utmifyResult['body'] ?? $utmifyResult['raw'], $tracking,
            $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300);
    }
    echo json_encode(['status' => 'ok', 'message' => 'error status recorded']);
    exit;
}

// Ignore non-actionable statuses (waiting_payment, processing, etc.)
if ($paymentStatus !== 'approved') {
    echo json_encode(['status' => 'ok', 'message' => 'status ignored: ' . $paymentStatus]);
    exit;
}

// ── Process APPROVED payments ──
$payments = getPayments();

if (!isset($payments[$paymentCode])) {
    echo json_encode(['status' => 'ok', 'message' => 'payment not found']);
    exit;
}

$payment = $payments[$paymentCode];

// Skip if already processed
if ($payment['status'] === 'paid') {
    echo json_encode(['status' => 'ok', 'message' => 'already processed']);
    exit;
}

// Mark as paid
$payments[$paymentCode]['status'] = 'paid';
$payments[$paymentCode]['paid_at'] = $approvedAt;
$payments[$paymentCode]['paid_source'] = 'mangofy_webhook';
savePayments($payments);

$customer = $payment['customer'];
$amount = $payment['amount'];
$items = $payment['items'] ?? [];
$tracking = $payment['tracking'] ?? [];

// --- Send UTMify "paid" event ---
$utmifyProducts = [];
foreach ($items as $item) {
    $utmifyProducts[] = [
        'id' => $paymentCode,
        'name' => $item['name'] ?? 'Produto',
        'planId' => $item['id'] ?? 'item',
        'planName' => $item['name'] ?? 'Produto',
        'quantity' => intval($item['quantity'] ?? 1),
        'priceInCents' => intval($item['price'] ?? $amount)
    ];
}
if (empty($utmifyProducts)) {
    $utmifyProducts[] = [
        'id' => $paymentCode,
        'name' => 'Pedido',
        'planId' => 'order',
        'planName' => 'Pedido',
        'quantity' => 1,
        'priceInCents' => $amount
    ];
}

$utmifyPayload = [
    'orderId' => $paymentCode,
    'platform' => 'MercadoLivre25Anos',
    'paymentMethod' => 'pix',
    'status' => 'paid',
    'createdAt' => $payment['created_at'],
    'approvedDate' => $approvedAt,
    'refundedAt' => null,
    'customer' => [
        'name' => strtoupper($customer['name'] ?? ''),
        'email' => $customer['email'] ?? '',
        'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
        'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
        'country' => 'BR',
        'ip' => $customer['ip'] ?? '0.0.0.0'
    ],
    'products' => $utmifyProducts,
    'trackingParameters' => [
        'src' => $tracking['src'] ?? null,
        'sck' => $tracking['sck'] ?? null,
        'utm_source' => $tracking['utm_source'] ?? null,
        'utm_campaign' => $tracking['utm_campaign'] ?? null,
        'utm_medium' => $tracking['utm_medium'] ?? null,
        'utm_content' => $tracking['utm_content'] ?? null,
        'utm_term' => $tracking['utm_term'] ?? null,
        'fbclid' => $tracking['fbclid'] ?? null,
        'fbp' => $tracking['fbp'] ?? null
    ],
    'commission' => [
        'totalPriceInCents' => $amount,
        'gatewayFeeInCents' => 0,
        'userCommissionInCents' => $amount
    ],
    'isTest' => false
];

$utmifyResult = apiPost(
    UTMIFY_API_URL,
    [
        'Content-Type: application/json',
        'x-api-token: ' . UTMIFY_API_TOKEN
    ],
    $utmifyPayload
);
writeApiEvent($paymentCode, 'paid', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
    ['orderId' => $paymentCode, 'status' => 'paid', 'amount' => $amount / 100],
    $utmifyResult['body'] ?? $utmifyResult['raw'],
    $tracking,
    $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300
);

// --- Send Facebook CAPI Purchase event ---
$nameParts = explode(' ', trim($customer['name'] ?? ''));
$firstName = $nameParts[0] ?? '';
$lastName = count($nameParts) > 1 ? end($nameParts) : '';

$fbEventData = [
    'data' => [
        [
            'event_name' => 'Purchase',
            'event_id' => 'pur_' . $paymentCode . '_' . time(),
            'event_time' => time(),
            'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
            'action_source' => 'website',
            'user_data' => [
                'em' => [hash('sha256', strtolower(trim($customer['email'] ?? '')))],
                'ph' => [hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? ''))],
                'fn' => [hash('sha256', strtolower(trim($firstName)))],
                'ln' => [hash('sha256', strtolower(trim($lastName)))],
                'client_ip_address' => $customer['ip'] ?? '0.0.0.0',
                'client_user_agent' => $payment['user_agent'] ?? '',
                'fbc' => $tracking['fbc'] ?? null,
                'fbp' => $tracking['fbp'] ?? null
            ],
            'custom_data' => [
                'currency' => 'BRL',
                'value' => $amount / 100,
                'content_ids' => array_map(function($item) { return $item['id'] ?? 'item'; }, $items),
                'content_type' => 'product',
                'order_id' => $paymentCode
            ]
        ]
    ]
];

// Remove null values from user_data
$fbEventData['data'][0]['user_data'] = array_filter(
    $fbEventData['data'][0]['user_data'],
    function($v) { return $v !== null && $v !== ''; }
);

$fbUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . FB_ACCESS_TOKEN;

$fbResult = apiPost(
    $fbUrl,
    ['Content-Type: application/json'],
    $fbEventData
);
writeApiEvent($paymentCode, 'Purchase', 'facebook_capi', $fbUrl, $fbResult['status'],
    ['event_name' => 'Purchase', 'value' => $amount / 100, 'event_id' => $fbEventData['data'][0]['event_id']],
    $fbResult['body'] ?? $fbResult['raw'],
    $tracking,
    $fbResult['status'] === 200
);
if ($fbResult['status'] !== 200) {
    writeLog('FB_CAPI_ERRO', [
        'evento' => 'Purchase',
        'payment_code' => $paymentCode,
        'http_status' => $fbResult['status'],
        'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')
    ]);
}

// --- Send TikTok Events API - CompletePayment ---
$ttContentIds = array_map(function($item) { return ['content_id' => $item['id'] ?? 'item', 'content_name' => $item['name'] ?? 'Produto', 'quantity' => intval($item['quantity'] ?? 1), 'price' => ($item['price'] ?? $amount) / 100]; }, $items);
$ttResult = sendTikTokEvent('CompletePayment', 'tt_cp_' . $paymentCode . '_' . time(), [
    'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
    'phone' => hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? '')),
    'ip' => $customer['ip'] ?? '0.0.0.0',
    'user_agent' => $payment['user_agent'] ?? ''
], [
    'contents' => $ttContentIds,
    'content_type' => 'product',
    'currency' => 'BRL',
    'value' => $amount / 100,
    'order_id' => $paymentCode
]);
if ($ttResult) {
    writeApiEvent($paymentCode, 'CompletePayment', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttResult['status'],
        ['event' => 'CompletePayment', 'value' => $amount / 100, 'event_id' => 'tt_cp_' . $paymentCode],
        $ttResult['body'] ?? $ttResult['raw'],
        $tracking,
        $ttResult['status'] === 200
    );
    if ($ttResult['status'] !== 200) {
        writeLog('TIKTOK_API_ERRO', [
            'evento' => 'CompletePayment',
            'payment_code' => $paymentCode,
            'http_status' => $ttResult['status'],
            'erro' => $ttResult['error'] ?: ($ttResult['raw'] ?? 'sem resposta')
        ]);
    }
}

// Log payment approved
writeLog('PAGAMENTO_APROVADO', [
    'payment_code' => $paymentCode,
    'valor' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
    'nome' => $customer['name'] ?? '',
    'email' => $customer['email'] ?? '',
    'telefone' => $customer['phone'] ?? '',
    'aprovado_em' => $approvedAt
]);

echo json_encode(['status' => 'ok', 'message' => 'payment processed']);
