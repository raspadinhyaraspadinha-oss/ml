<?php
/* ============================================
   SkalePay Webhook Handler
   Receives POST callbacks when SkalePay payment status changes
   URL to register in SkalePay dashboard: https://seusite.com/api/webhook-skalepay.php
   ============================================ */

require_once __DIR__ . '/config.php';

// Always return 200 to prevent SkalePay retries
http_response_code(200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Log every webhook call for debugging
writeLog('SKALEPAY_WEBHOOK_RECEBIDO', [
    'raw_length' => strlen($rawInput),
    'has_json' => $input !== null ? 'SIM' : 'NAO',
    'keys' => is_array($input) ? implode(',', array_keys($input)) : 'N/A',
    'preview' => substr($rawInput, 0, 2000)
]);

if (!$input) {
    echo json_encode(['status' => 'ok', 'message' => 'invalid json']);
    exit;
}

// SkalePay sends: { id, status, amount, ... }
// Possible statuses: paid, refused, refunded, pending, processing, waiting_payment
$skTransactionId = $input['id'] ?? $input['transaction_id'] ?? $input['tid'] ?? '';
$skStatus = $input['status'] ?? $input['current_status'] ?? '';
$skAmount = $input['amount'] ?? $input['paid_amount'] ?? 0;

writeLog('SKALEPAY_WEBHOOK_PARSED', [
    'transaction_id' => $skTransactionId,
    'status' => $skStatus,
    'amount' => $skAmount
]);

if (empty($skTransactionId)) {
    echo json_encode(['status' => 'ok', 'message' => 'no transaction id']);
    exit;
}

// Only process if paid
if ($skStatus !== 'paid') {
    writeLog('SKALEPAY_WEBHOOK_IGNORADO', [
        'motivo' => 'status_nao_e_paid',
        'status_recebido' => $skStatus,
        'transaction_id' => $skTransactionId
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'status not paid: ' . $skStatus]);
    exit;
}

// Find local payment by gateway_id (SkalePay transaction ID)
$payments = getPayments();
$foundCode = null;

foreach ($payments as $code => $payment) {
    if (($payment['gateway'] ?? '') === 'skalepay' && ($payment['gateway_id'] ?? '') == $skTransactionId) {
        $foundCode = $code;
        break;
    }
}

if (!$foundCode) {
    // Try matching by payment_code pattern: sk_{transaction_id}
    $possibleCode = 'sk_' . $skTransactionId;
    if (isset($payments[$possibleCode])) {
        $foundCode = $possibleCode;
    }
}

if (!$foundCode) {
    writeLog('SKALEPAY_WEBHOOK_NAO_ENCONTRADO', [
        'transaction_id' => $skTransactionId,
        'tentativas' => 'gateway_id + sk_prefix'
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'payment not found']);
    exit;
}

$payment = $payments[$foundCode];

// Skip if already processed
if ($payment['status'] === 'paid') {
    writeLog('SKALEPAY_WEBHOOK_JA_PROCESSADO', [
        'payment_code' => $foundCode,
        'paid_at' => $payment['paid_at']
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'already processed']);
    exit;
}

// Mark as paid
$approvedAt = $input['date_updated'] ?? $input['paidAt'] ?? date('Y-m-d H:i:s');
$payments[$foundCode]['status'] = 'paid';
$payments[$foundCode]['paid_at'] = $approvedAt;
savePayments($payments);

writeLog('SKALEPAY_WEBHOOK_APROVADO', [
    'payment_code' => $foundCode,
    'transaction_id' => $skTransactionId,
    'amount' => $payment['amount'],
    'approved_at' => $approvedAt
]);

// ═══════════════════════════════════════════
//  FIRE ALL TRACKING EVENTS ON APPROVAL
// ═══════════════════════════════════════════

$customer = $payment['customer'];
$amount = $payment['amount'];
$items = $payment['items'] ?? [];
$tracking = $payment['tracking'] ?? [];

// ── UTMify "paid" ──
$utmifyProducts = [];
foreach ($items as $item) {
    $utmifyProducts[] = [
        'id' => $foundCode, 'name' => $item['name'] ?? 'Produto',
        'planId' => $item['id'] ?? 'item', 'planName' => $item['name'] ?? 'Produto',
        'quantity' => intval($item['quantity'] ?? 1), 'priceInCents' => intval($item['price'] ?? $amount)
    ];
}
if (empty($utmifyProducts)) {
    $utmifyProducts[] = ['id' => $foundCode, 'name' => 'Pedido', 'planId' => 'order', 'planName' => 'Pedido', 'quantity' => 1, 'priceInCents' => $amount];
}

$utmifyPayload = [
    'orderId' => $foundCode, 'platform' => 'MercadoLivre25Anos', 'paymentMethod' => 'pix',
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
writeApiEvent($foundCode, 'paid', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
    ['orderId' => $foundCode, 'status' => 'paid', 'amount' => $amount / 100, 'gateway' => 'skalepay', 'via' => 'webhook'],
    $utmifyResult['body'] ?? $utmifyResult['raw'], $tracking,
    $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300);

// ── Facebook CAPI Purchase ──
$nameParts = explode(' ', trim($customer['name'] ?? ''));
$firstName = $nameParts[0] ?? '';
$lastName = count($nameParts) > 1 ? end($nameParts) : '';

$fbEventData = ['data' => [[
    'event_name' => 'Purchase', 'event_id' => 'pur_' . $foundCode . '_' . time(),
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
        'content_type' => 'product', 'order_id' => $foundCode
    ]
]]];

$fbUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . FB_ACCESS_TOKEN;
$fbResult = apiPost($fbUrl, ['Content-Type: application/json'], $fbEventData);
writeApiEvent($foundCode, 'Purchase', 'facebook_capi', $fbUrl, $fbResult['status'],
    ['event_name' => 'Purchase', 'value' => $amount / 100, 'event_id' => $fbEventData['data'][0]['event_id'], 'via' => 'webhook'],
    $fbResult['body'] ?? $fbResult['raw'], $tracking, $fbResult['status'] === 200);
if ($fbResult['status'] !== 200) {
    writeLog('FB_CAPI_ERRO', ['evento' => 'Purchase', 'payment_code' => $foundCode, 'via' => 'skalepay_webhook', 'http_status' => $fbResult['status'], 'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')]);
}

// ── TikTok Events API - CompletePayment ──
$ttContentIds = array_map(function($item) { return ['content_id' => $item['id'] ?? 'item', 'content_name' => $item['name'] ?? 'Produto', 'quantity' => intval($item['quantity'] ?? 1), 'price' => ($item['price'] ?? $amount) / 100]; }, $items);
$ttResult = sendTikTokEvent('CompletePayment', 'tt_cp_' . $foundCode . '_' . time(), [
    'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
    'phone' => hash('sha256', preg_replace('/\D/', '', $customer['phone'] ?? '')),
    'ip' => $customer['ip'] ?? '0.0.0.0', 'user_agent' => $payment['user_agent'] ?? ''
], ['contents' => $ttContentIds, 'content_type' => 'product', 'currency' => 'BRL', 'value' => $amount / 100, 'order_id' => $foundCode]);
if ($ttResult) {
    writeApiEvent($foundCode, 'CompletePayment', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttResult['status'],
        ['event' => 'CompletePayment', 'value' => $amount / 100, 'event_id' => 'tt_cp_' . $foundCode, 'via' => 'webhook'],
        $ttResult['body'] ?? $ttResult['raw'], $tracking, $ttResult['status'] === 200);
    if ($ttResult['status'] !== 200) {
        writeLog('TIKTOK_API_ERRO', ['evento' => 'CompletePayment', 'payment_code' => $foundCode, 'via' => 'skalepay_webhook', 'http_status' => $ttResult['status'], 'erro' => $ttResult['error'] ?: ($ttResult['raw'] ?? 'sem resposta')]);
    }
}

// Log
writeLog('PAGAMENTO_APROVADO', [
    'gateway' => 'skalepay', 'via' => 'webhook', 'payment_code' => $foundCode,
    'valor' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
    'nome' => $customer['name'] ?? '', 'email' => $customer['email'] ?? '',
    'telefone' => $customer['phone'] ?? '', 'aprovado_em' => $approvedAt
]);

echo json_encode(['status' => 'ok', 'message' => 'payment processed via webhook']);
