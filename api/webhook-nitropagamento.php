<?php
/* ============================================
   NitroPagamento Webhook Handler
   Receives POST callbacks when NitroPagamento payment status changes
   URL to register: https://seusite.com/api/webhook-nitropagamento.php
   Events: transaction.paid, transaction.failed, transaction.expired, transaction.refunded
   ============================================ */

require_once __DIR__ . '/config.php';

// Always return 200 to prevent retries
http_response_code(200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Log every webhook call for debugging
writeLog('NITROPAGAMENTO_WEBHOOK_RECEBIDO', [
    'raw_length' => strlen($rawInput),
    'has_json' => $input !== null ? 'SIM' : 'NAO',
    'keys' => is_array($input) ? implode(',', array_keys($input)) : 'N/A',
    'preview' => substr($rawInput, 0, 2000)
]);

if (!$input) {
    echo json_encode(['status' => 'ok', 'message' => 'invalid json']);
    exit;
}

// NitroPagamento webhook format:
// { event: "transaction.paid", timestamp: "...", data: { transaction_id, amount, status, paid_at, customer } }
$event = $input['event'] ?? '';
$txData = $input['data'] ?? [];

$npTransactionId = $txData['transaction_id'] ?? $txData['id'] ?? '';
$npStatus = $txData['status'] ?? '';
$npAmount = $txData['amount'] ?? 0;

writeLog('NITROPAGAMENTO_WEBHOOK_PARSED', [
    'event' => $event,
    'transaction_id' => $npTransactionId,
    'status' => $npStatus,
    'amount' => $npAmount
]);

if (empty($npTransactionId)) {
    echo json_encode(['status' => 'ok', 'message' => 'no transaction id']);
    exit;
}

// ── Find matching payment ──
$payments = getPayments();
$foundCode = null;

foreach ($payments as $code => $payment) {
    if (($payment['gateway'] ?? '') === 'nitropagamento' && ($payment['gateway_id'] ?? '') == $npTransactionId) {
        $foundCode = $code;
        break;
    }
}

// Try prefix-based lookup
if (!$foundCode) {
    $possibleCode = 'np_' . $npTransactionId;
    if (isset($payments[$possibleCode])) {
        $foundCode = $possibleCode;
    }
}

if (!$foundCode) {
    writeLog('NITROPAGAMENTO_WEBHOOK_NAO_ENCONTRADO', [
        'transaction_id' => $npTransactionId,
        'event' => $event,
        'tentativas' => 'gateway_id + np_prefix'
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'payment not found']);
    exit;
}

$payment = $payments[$foundCode];

// ── Handle ERROR events ──
$errorEvents = ['transaction.failed', 'transaction.expired', 'transaction.refunded'];
$errorStatuses = ['falhou', 'failed', 'expirado', 'expired', 'reembolsado', 'refunded', 'cancelled'];

if (in_array($event, $errorEvents) || in_array($npStatus, $errorStatuses)) {
    if ($payment['status'] === 'pending') {
        $payments[$foundCode]['status'] = 'failed';
        $payments[$foundCode]['failed_at'] = date('Y-m-d H:i:s');
        $payments[$foundCode]['fail_reason'] = $event ?: $npStatus;
        savePayments($payments);

        writeLog('NITROPAGAMENTO_PAGAMENTO_FALHOU', [
            'payment_code' => $foundCode,
            'transaction_id' => $npTransactionId,
            'motivo' => $event ?: $npStatus,
            'valor' => 'R$ ' . number_format(($payment['amount'] ?? 0) / 100, 2, ',', '.')
        ]);

        // Notify UTMify
        $tracking = $payment['tracking'] ?? [];
        $utmifyPayload = [
            'orderId' => $foundCode, 'platform' => 'MercadoLivre25Anos', 'paymentMethod' => 'pix',
            'status' => 'refused', 'createdAt' => $payment['created_at'] ?? date('Y-m-d H:i:s'),
            'approvedDate' => null, 'refundedAt' => null,
            'customer' => ['name' => strtoupper($payment['customer']['name'] ?? ''), 'email' => $payment['customer']['email'] ?? '',
                'phone' => preg_replace('/\D/', '', $payment['customer']['phone'] ?? ''),
                'document' => preg_replace('/\D/', '', $payment['customer']['document'] ?? ''), 'country' => 'BR', 'ip' => $payment['customer']['ip'] ?? '0.0.0.0'],
            'products' => [['id' => $foundCode, 'name' => 'Pedido', 'planId' => 'order', 'planName' => 'Pedido', 'quantity' => 1, 'priceInCents' => $payment['amount'] ?? 0]],
            'trackingParameters' => ['src' => $tracking['src'] ?? null, 'sck' => $tracking['sck'] ?? null,
                'utm_source' => $tracking['utm_source'] ?? null, 'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null, 'utm_content' => $tracking['utm_content'] ?? null, 'utm_term' => $tracking['utm_term'] ?? null],
            'commission' => ['totalPriceInCents' => $payment['amount'] ?? 0, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $payment['amount'] ?? 0],
            'isTest' => false
        ];
        apiPost(UTMIFY_API_URL, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_API_TOKEN], $utmifyPayload);
    }
    echo json_encode(['status' => 'ok', 'message' => 'error status recorded: ' . ($event ?: $npStatus)]);
    exit;
}

// Ignore non-paid statuses
if ($event !== 'transaction.paid' && $npStatus !== 'pago' && $npStatus !== 'paid') {
    writeLog('NITROPAGAMENTO_WEBHOOK_IGNORADO', [
        'motivo' => 'status_nao_e_paid_nem_erro',
        'event' => $event,
        'status_recebido' => $npStatus,
        'transaction_id' => $npTransactionId
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'status ignored: ' . ($event ?: $npStatus)]);
    exit;
}

// ── Process PAID payments ──

// Skip if already processed
if ($payment['status'] === 'paid') {
    writeLog('NITROPAGAMENTO_WEBHOOK_JA_PROCESSADO', [
        'payment_code' => $foundCode,
        'paid_at' => $payment['paid_at']
    ]);
    echo json_encode(['status' => 'ok', 'message' => 'already processed']);
    exit;
}

// Mark as paid
$approvedAt = $txData['paid_at'] ?? $input['timestamp'] ?? date('Y-m-d H:i:s');
$payments[$foundCode]['status'] = 'paid';
$payments[$foundCode]['paid_at'] = $approvedAt;
savePayments($payments);

writeLog('NITROPAGAMENTO_WEBHOOK_APROVADO', [
    'payment_code' => $foundCode,
    'transaction_id' => $npTransactionId,
    'amount' => $payment['amount'],
    'approved_at' => $approvedAt
]);

// ═══════════════════════════════════════════
//  FIRE ALL TRACKING EVENTS ON APPROVAL
// ═══════════════════════════════════════════

// Use shared fireApprovalTracking() from config.php
fireApprovalTracking($foundCode, $payments[$foundCode], $approvedAt);

echo json_encode(['status' => 'ok', 'message' => 'payment processed via webhook']);
