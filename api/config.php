<?php
/* ============================================
   API Configuration
   IMPORTANT: Update these values with your real credentials
   ============================================ */

// Timezone: São Paulo BRT (UTC-3)
date_default_timezone_set('America/Sao_Paulo');

// Mangofy
define('MANGOFY_API_URL', 'https://checkout.mangofy.com.br/api/v1');
define('MANGOFY_AUTHORIZATION', '2d7ec7be4856d113b6dea617d389cb711dlhqysglpgl6h8tiy3jd5lzc6tx2ei');
define('MANGOFY_STORE_CODE', '0d4e1ba5d97eba0bb822b05fae41df4b');

// SkalePay
define('SKALEPAY_API_URL', 'https://api.conta.skalepay.com.br/v1');
define('SKALEPAY_API_KEY', 'sk_live_v2H9kEj5vdo0cTYZivnvDY5GbFQiRu24YFCOZpUv28');

// NitroPagamento
define('NITROPAGAMENTO_API_URL', 'https://api.nitropagamento.app');
define('NITROPAGAMENTO_PK', 'pk_live_XVjoJDuQj9hEkp8LC08b41SFSoGsm7KQ');
define('NITROPAGAMENTO_SK', 'sk_live_VC01aogUUwK1nCPmQPTAcLbBbtEK7pHz');

// UTMify
define('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_API_TOKEN', 'Al1mBzGZMJGVLvPMWEYjKofnD9fpWUvxE4Qn');

// Facebook Conversions API
define('FB_PIXEL_ID', '895868873361776');
define('FB_ACCESS_TOKEN', 'EAAMJ4lbAOXMBQy8xkl2eKvSBDOGbwOdwP0cwJS6CNZBWRKOOkbVtd47IzZCEkVZCLte9nFkR4hEwZCDymU8OPrca47jnprc43IOEG94YJdZC5XZAZCfFQAxFpvo8GZAyJNIi51Xnq2lCxZBoi0uJZBrRmX5MIrZBSq8PNEwNJCIjyiZA9NUxOiP7tEZA1gDKimLjTcQZDZD');
define('FB_API_VERSION', 'v21.0');

// TikTok Events API
define('TIKTOK_PIXEL_ID', 'D6G7SLBC77U2V3Q5N7A0');
define('TIKTOK_ACCESS_TOKEN', '14d9ff5601dbf0386da60a7925b3a38c37d7af5b'); // ← Substitua pelo seu Access Token do TikTok Events API

// Data storage path
define('DATA_DIR', __DIR__ . '/data/');

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

/* Helper: Read feature flags */
function getFeatureFlags() {
    static $flags = null;
    if ($flags !== null) return $flags;

    $file = DATA_DIR . 'feature_flags.json';
    if (!file_exists($file)) {
        $flags = ['global_killswitch' => false, 'flags' => []];
        return $flags;
    }
    $data = json_decode(file_get_contents($file), true);
    $flags = is_array($data) ? $data : ['global_killswitch' => false, 'flags' => []];
    return $flags;
}

/* Helper: Check if a specific feature flag is enabled */
function isFeatureEnabled(string $flagName): bool {
    $flags = getFeatureFlags();
    if ($flags['global_killswitch'] === true) return false;
    if (!isset($flags['flags'][$flagName])) return true; // Unknown flag: enabled by default
    return $flags['flags'][$flagName]['enabled'] !== false;
}

// CORS headers for frontend requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* Helper: Read payments data */
function getPayments() {
    $file = DATA_DIR . 'payments.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/* Helper: Save payments data */
function savePayments($payments) {
    $file = DATA_DIR . 'payments.json';
    file_put_contents($file, json_encode($payments, JSON_PRETTY_PRINT), LOCK_EX);
}

/* Helper: Append to log.txt */
function writeLog($event, $data = []) {
    $logFile = DATA_DIR . 'log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$event]";
    foreach ($data as $key => $val) {
        if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        $line .= " | $key: $val";
    }
    $line .= PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* Helper: Send TikTok Events API event (enriched)
   $eventTime: optional Unix timestamp (default: time())
   Use custom eventTime for delayed events like Purchase after webhook */
function sendTikTokEvent($eventName, $eventId, $userData, $properties = [], $eventTime = null) {
    if (TIKTOK_ACCESS_TOKEN === 'SEU_TIKTOK_ACCESS_TOKEN_AQUI') return null; // Skip if not configured

    // Build context.user (separate ttclid from other fields)
    $ttclid = $userData['ttclid'] ?? null;
    unset($userData['ttclid']);

    $context = [
        'user_agent' => $userData['user_agent'] ?? '',
        'ip' => $userData['ip'] ?? '',
        'page' => [
            'url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
            'referrer' => ''
        ],
        'user' => array_filter([
            'email' => $userData['email'] ?? null,
            'phone_number' => $userData['phone_number'] ?? $userData['phone'] ?? null,
            'external_id' => $userData['external_id'] ?? null,
            'ttclid' => $ttclid
        ], function($v) { return $v !== null && $v !== ''; })
    ];

    $payload = [
        'event_source' => 'web',
        'event_source_id' => TIKTOK_PIXEL_ID,
        'data' => [
            [
                'event' => $eventName,
                'event_id' => $eventId,
                'event_time' => $eventTime ?: time(),
                'context' => $context,
                'properties' => $properties
            ]
        ]
    ];

    return apiPost(
        'https://business-api.tiktok.com/open_api/v1.3/event/track/',
        [
            'Content-Type: application/json',
            'Access-Token: ' . TIKTOK_ACCESS_TOKEN
        ],
        $payload
    );
}

/* Helper: Log API event for dashboard debugging */
function writeApiEvent($paymentCode, $eventName, $api, $url, $httpStatus, $requestSummary, $response, $utms = [], $success = true) {
    $file = DATA_DIR . 'api_events.json';
    $events = [];
    if (file_exists($file)) {
        $events = json_decode(file_get_contents($file), true);
        if (!is_array($events)) $events = [];
    }
    $events[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'payment_code' => $paymentCode,
        'event' => $eventName,
        'api' => $api,
        'url' => $url,
        'http_status' => $httpStatus,
        'request' => $requestSummary,
        'response' => $response,
        'utms' => $utms,
        'success' => $success
    ];
    // Keep last 500 events
    if (count($events) > 500) {
        $events = array_slice($events, -500);
    }
    file_put_contents($file, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* Helper: Get/set active gateway */
function getActiveGateway(): string {
    $file = DATA_DIR . 'gateway_config.json';
    if (file_exists($file)) {
        $cfg = json_decode(file_get_contents($file), true);
        if (isset($cfg['active_gateway'])) return $cfg['active_gateway'];
    }
    return 'mangofy';
}

function setActiveGateway(string $gw): void {
    $file = DATA_DIR . 'gateway_config.json';
    file_put_contents($file, json_encode([
        'active_gateway' => $gw,
        'updated_at' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT), LOCK_EX);
}

/* ============================================
   SHARED: Fire all tracking events on payment approval
   Used by: check-payment.php, cron-skalepay.php, mark-paid.php
   ============================================ */
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

    // ── Facebook CAPI Purchase (enriched for EMQ 8+) ──
    $nameParts = explode(' ', trim($customer['name'] ?? ''));
    $firstName = $nameParts[0] ?? '';
    $lastName = count($nameParts) > 1 ? end($nameParts) : '';
    $address = $payment['address'] ?? [];
    $clientIp = $payment['client_ip'] ?? $customer['ip'] ?? '0.0.0.0';

    // Build enriched user_data for high Event Match Quality
    $fbUserData = array_filter([
        'em' => [hash('sha256', strtolower(trim($customer['email'] ?? '')))],
        'ph' => [hash('sha256', '55' . preg_replace('/\D/', '', $customer['phone'] ?? ''))],
        'fn' => [hash('sha256', strtolower(trim($firstName)))],
        'ln' => [hash('sha256', strtolower(trim($lastName)))],
        'ct' => ($address['cidade'] ?? '') ? [hash('sha256', strtolower(trim($address['cidade'])))] : null,
        'st' => ($address['uf'] ?? '') ? [hash('sha256', strtolower(trim($address['uf'])))] : null,
        'zp' => ($address['cep'] ?? '') ? [hash('sha256', preg_replace('/\D/', '', $address['cep']))] : null,
        'country' => [hash('sha256', 'br')],
        'external_id' => [hash('sha256', $paymentCode)],
        'client_ip_address' => $clientIp,
        'client_user_agent' => $payment['user_agent'] ?? '',
        'fbc' => $tracking['fbc'] ?? null,
        'fbp' => $tracking['fbp'] ?? null
    ], function($v) { return $v !== null && $v !== '' && $v !== []; });

    // Build rich contents array for catalog matching
    $fbContents = [];
    foreach ($items as $item) {
        $fbContents[] = [
            'id' => $item['id'] ?? 'item',
            'quantity' => intval($item['quantity'] ?? 1),
            'item_price' => ($item['price'] ?? 0) / 100
        ];
    }

    // Use payment_code as event_id (natural dedup key for Purchase)
    $fbPurchaseEventId = 'pur_' . $paymentCode;

    $fbEventData = ['data' => [[
        'event_name' => 'Purchase',
        'event_id' => $fbPurchaseEventId,
        'event_time' => strtotime($approvedAt) ?: time(),
        'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
        'action_source' => 'website',
        'user_data' => $fbUserData,
        'custom_data' => [
            'currency' => 'BRL', 'value' => $amount / 100,
            'content_ids' => array_map(function($item) { return $item['id'] ?? 'item'; }, $items),
            'contents' => $fbContents,
            'content_type' => 'product', 'order_id' => $paymentCode,
            'num_items' => count($items)
        ]
    ]]];

    $fbUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . FB_ACCESS_TOKEN;
    $fbResult = apiPost($fbUrl, ['Content-Type: application/json'], $fbEventData);
    writeApiEvent($paymentCode, 'Purchase', 'facebook_capi', $fbUrl, $fbResult['status'],
        ['event_name' => 'Purchase', 'value' => $amount / 100, 'event_id' => $fbPurchaseEventId],
        $fbResult['body'] ?? $fbResult['raw'], $tracking, $fbResult['status'] === 200);
    if ($fbResult['status'] !== 200) {
        writeLog('FB_CAPI_ERRO', ['evento' => 'Purchase', 'payment_code' => $paymentCode, 'http_status' => $fbResult['status'], 'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')]);
    }

    // ── TikTok Events API - CompletePayment (enriched) ──
    $ttContents = array_map(function($item) use ($amount) {
        return [
            'content_id' => $item['id'] ?? 'item',
            'content_name' => $item['name'] ?? 'Produto',
            'content_type' => 'product',
            'quantity' => intval($item['quantity'] ?? 1),
            'price' => ($item['price'] ?? $amount) / 100
        ];
    }, $items);

    $ttUserData = array_filter([
        'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
        'phone_number' => hash('sha256', '+55' . preg_replace('/\D/', '', $customer['phone'] ?? '')),
        'external_id' => hash('sha256', $paymentCode),
        'ip' => $clientIp,
        'user_agent' => $payment['user_agent'] ?? '',
        'ttclid' => $tracking['ttclid'] ?? null
    ], function($v) { return $v !== null && $v !== ''; });

    // MUST match browser event_id ('pur_' + paymentCode) for pixel dedup
    $ttPurchaseEventId = 'pur_' . $paymentCode;
    $ttEventTime = strtotime($approvedAt) ?: time();
    $ttResult = sendTikTokEvent('CompletePayment', $ttPurchaseEventId, $ttUserData,
        ['contents' => $ttContents, 'content_type' => 'product', 'currency' => 'BRL', 'value' => $amount / 100, 'order_id' => $paymentCode],
        $ttEventTime);
    if ($ttResult) {
        writeApiEvent($paymentCode, 'CompletePayment', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttResult['status'],
            ['event' => 'CompletePayment', 'value' => $amount / 100, 'event_id' => $ttPurchaseEventId],
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

/* Helper: cURL GET request */
function apiGet($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

/* Helper: cURL POST request */
function apiPost($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

