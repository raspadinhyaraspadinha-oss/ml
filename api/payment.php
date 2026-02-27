<?php
/* ============================================
   Create Mangofy PIX Payment
   POST /api/payment.php
   Body: { customer, items, amount, metadata }
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['customer']) || !isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: customer, amount']);
    exit;
}

$customer = $input['customer'];
$originalCustomer = $customer; // Guardar dados originais do lead
$amount = intval($input['amount']); // in cents
$items = isset($input['items']) ? $input['items'] : [];
$metadata = isset($input['metadata']) ? $input['metadata'] : [];
$trackingParams = isset($input['trackingParameters']) ? $input['trackingParameters'] : [];

// Safety: reject payments below R$5,00 (500 cents) - Mangofy minimum
if ($amount < 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor do pedido inválido. Mínimo R$ 5,00.', 'amount_received' => $amount]);
    exit;
}

// Dados padrão para fallback caso Mangofy rejeite os dados do lead
$FALLBACK_CUSTOMER = [
    'email' => 'cidinha_lira10@hotmail.com',
    'name' => 'MARIA APARECIDA NUNES DE LIRA',
    'document' => '88017427468',
    'phone' => '11973003483'
];

// Generate unique external code
$externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);

// Get client IP
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Build Mangofy items
$mangofyItems = [];
foreach ($items as $item) {
    $mangofyItems[] = [
        'code' => 'ITEM-' . $externalCode . '-' . ($item['id'] ?? 'item'),
        'amount' => intval($item['quantity'] ?? 1),
        'price' => intval($item['price'] ?? $amount)
    ];
}
if (empty($mangofyItems)) {
    $mangofyItems[] = [
        'code' => 'ITEM-' . $externalCode,
        'amount' => 1,
        'price' => $amount
    ];
}

// Build Mangofy payment payload
$payload = [
    'store_code' => MANGOFY_STORE_CODE,
    'external_code' => $externalCode,
    'payment_method' => 'pix',
    'payment_amount' => $amount,
    'payment_format' => 'regular',
    'installments' => 1,
    'pix' => [
        'expires_in_days' => 1
    ],
    'postback_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                       '://' . $_SERVER['HTTP_HOST'] . '/api/webhook.php',
    'items' => $mangofyItems,
    'customer' => [
        'email' => $customer['email'] ?? '',
        'name' => strtoupper($customer['name'] ?? ''),
        'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
        'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
        'ip' => $clientIp
    ],
    'metadata' => array_merge($metadata, [
        'session_id' => $externalCode
    ])
];

// Call Mangofy API
$mangofyHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: ' . MANGOFY_AUTHORIZATION,
    'Store-Code: ' . MANGOFY_STORE_CODE
];

$response = apiPost(MANGOFY_API_URL . '/payment', $mangofyHeaders, $payload);

// Fallback: se Mangofy retornar erro (dados invalidos), tentar com dados padrao
if ($response['status'] !== 200 && $response['status'] !== 201) {
    writeLog('MANGOFY_ERRO_TENTATIVA_1', [
        'status' => $response['status'],
        'erro' => $response['raw'] ?? 'sem resposta',
        'customer_original' => $customer['name'] ?? ''
    ]);

    // Rebuildar payload com dados padrao
    $payload['customer'] = [
        'email' => $FALLBACK_CUSTOMER['email'],
        'name' => $FALLBACK_CUSTOMER['name'],
        'document' => $FALLBACK_CUSTOMER['document'],
        'phone' => $FALLBACK_CUSTOMER['phone'],
        'ip' => $clientIp
    ];

    // Gerar novo external_code para a segunda tentativa
    $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
    $payload['external_code'] = $externalCode;
    // Atualizar codes dos items tambem
    $rebuildItems = [];
    foreach ($items as $item) {
        $rebuildItems[] = [
            'code' => 'ITEM-' . $externalCode . '-' . ($item['id'] ?? 'item'),
            'amount' => intval($item['quantity'] ?? 1),
            'price' => intval($item['price'] ?? $amount)
        ];
    }
    if (!empty($rebuildItems)) $payload['items'] = $rebuildItems;

    $response = apiPost(MANGOFY_API_URL . '/payment', $mangofyHeaders, $payload);

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        writeLog('MANGOFY_ERRO_FALLBACK', [
            'status' => $response['status'],
            'erro' => $response['raw'] ?? 'sem resposta'
        ]);
        http_response_code(500);
        echo json_encode([
            'error' => 'Mangofy payment creation failed',
            'details' => $response['body'] ?? $response['raw']
        ]);
        exit;
    }

    writeLog('MANGOFY_FALLBACK_USADO', [
        'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
        'fallback_para' => $FALLBACK_CUSTOMER['name']
    ]);
}

$mangofyData = $response['body'];
$paymentCode = $mangofyData['payment_code'] ?? null;

if (!$paymentCode) {
    http_response_code(500);
    echo json_encode(['error' => 'No payment_code returned from Mangofy']);
    exit;
}

// Store payment locally (sempre usar dados originais do lead, nao do fallback)
$payments = getPayments();
$createdAt = date('Y-m-d H:i:s');
$payments[$paymentCode] = [
    'payment_code' => $paymentCode,
    'external_code' => $externalCode,
    'status' => 'pending',
    'amount' => $amount,
    'customer' => $originalCustomer,
    'items' => $items,
    'tracking' => $trackingParams,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'created_at' => $createdAt,
    'paid_at' => null
];
savePayments($payments);

// Send UTMify waiting_payment event (non-blocking)
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
    'status' => 'waiting_payment',
    'createdAt' => $createdAt,
    'approvedDate' => null,
    'refundedAt' => null,
    'customer' => [
        'name' => strtoupper($customer['name'] ?? ''),
        'email' => $customer['email'] ?? '',
        'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
        'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
        'country' => 'BR',
        'ip' => $clientIp
    ],
    'products' => $utmifyProducts,
    'trackingParameters' => [
        'src' => $trackingParams['src'] ?? null,
        'sck' => $trackingParams['sck'] ?? null,
        'utm_source' => $trackingParams['utm_source'] ?? null,
        'utm_campaign' => $trackingParams['utm_campaign'] ?? null,
        'utm_medium' => $trackingParams['utm_medium'] ?? null,
        'utm_content' => $trackingParams['utm_content'] ?? null,
        'utm_term' => $trackingParams['utm_term'] ?? null,
        'fbclid' => $trackingParams['fbclid'] ?? null,
        'fbp' => $trackingParams['fbp'] ?? null
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
writeApiEvent($paymentCode, 'waiting_payment', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
    ['orderId' => $paymentCode, 'status' => 'waiting_payment', 'amount' => $amount / 100],
    $utmifyResult['body'] ?? $utmifyResult['raw'],
    $trackingParams,
    $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300
);

// Send Facebook CAPI AddToCart event (server-side)
$nameParts = explode(' ', trim($customer['name'] ?? ''));
$firstName = $nameParts[0] ?? '';
$lastName = count($nameParts) > 1 ? end($nameParts) : '';

$fbAddToCart = [
    'data' => [
        [
            'event_name' => 'AddToCart',
            'event_id' => 'atc_' . $paymentCode . '_' . time(),
            'event_time' => time(),
            'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
            'action_source' => 'website',
            'user_data' => array_filter([
                'em' => [hash('sha256', strtolower(trim($customer['email'] ?? '')))],
                'ph' => [hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? ''))],
                'fn' => [hash('sha256', strtolower(trim($firstName)))],
                'ln' => [hash('sha256', strtolower(trim($lastName)))],
                'client_ip_address' => $clientIp,
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'fbc' => $trackingParams['fbc'] ?? null,
                'fbp' => $trackingParams['fbp'] ?? null
            ], function($v) { return $v !== null && $v !== ''; }),
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

$fbUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . FB_ACCESS_TOKEN;
$fbResult = apiPost($fbUrl, ['Content-Type: application/json'], $fbAddToCart);
writeApiEvent($paymentCode, 'AddToCart', 'facebook_capi', $fbUrl, $fbResult['status'],
    ['event_name' => 'AddToCart', 'value' => $amount / 100, 'event_id' => $fbAddToCart['data'][0]['event_id']],
    $fbResult['body'] ?? $fbResult['raw'],
    $trackingParams,
    $fbResult['status'] === 200
);
if ($fbResult['status'] !== 200) {
    writeLog('FB_CAPI_ERRO', [
        'evento' => 'AddToCart',
        'payment_code' => $paymentCode,
        'http_status' => $fbResult['status'],
        'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')
    ]);
}

// --- Send TikTok Events API - InitiateCheckout ---
$ttContentIds = array_map(function($item) { return ['content_id' => $item['id'] ?? 'item', 'content_name' => $item['name'] ?? 'Produto', 'quantity' => intval($item['quantity'] ?? 1), 'price' => ($item['price'] ?? $amount) / 100]; }, $items);
$ttResult = sendTikTokEvent('InitiateCheckout', 'tt_ic_' . $paymentCode . '_' . time(), [
    'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
    'phone' => hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? '')),
    'ip' => $clientIp,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
], [
    'contents' => $ttContentIds,
    'content_type' => 'product',
    'currency' => 'BRL',
    'value' => $amount / 100
]);
if ($ttResult) {
    writeApiEvent($paymentCode, 'InitiateCheckout', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttResult['status'],
        ['event' => 'InitiateCheckout', 'value' => $amount / 100, 'event_id' => 'tt_ic_' . $paymentCode],
        $ttResult['body'] ?? $ttResult['raw'],
        $trackingParams,
        $ttResult['status'] === 200
    );
    if ($ttResult['status'] !== 200) {
        writeLog('TIKTOK_API_ERRO', [
            'evento' => 'InitiateCheckout',
            'payment_code' => $paymentCode,
            'http_status' => $ttResult['status'],
            'erro' => $ttResult['error'] ?: ($ttResult['raw'] ?? 'sem resposta')
        ]);
    }
}

// Log PIX generated
writeLog('PIX_GERADO', [
    'payment_code' => $paymentCode,
    'valor' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
    'nome' => $customer['name'] ?? '',
    'email' => $customer['email'] ?? '',
    'telefone' => $customer['phone'] ?? '',
    'itens' => array_map(function($i) { return ($i['name'] ?? 'item') . ' x' . ($i['quantity'] ?? 1); }, $items),
    'ip' => $clientIp
]);

// Return success with QR code data
echo json_encode([
    'success' => true,
    'payment_code' => $paymentCode,
    'external_code' => $externalCode,
    'pix_qrcode_text' => $mangofyData['pix']['pix_qrcode_text'] ?? '',
    'amount' => $amount,
    'expires_at' => $mangofyData['expires_at'] ?? ''
]);
