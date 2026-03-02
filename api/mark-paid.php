<?php
/* ============================================
   Mark Payment as Paid (Manual)
   POST /api/mark-paid.php
   Body: { payment_code: "...", password: "ml2025" }

   Used by dashboard to manually approve payments
   and re-fire all tracking pixels.
   ============================================ */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$paymentCode = $input['payment_code'] ?? '';
$password = $input['password'] ?? '';

// Auth check
if ($password !== 'ml2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (empty($paymentCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment_code']);
    exit;
}

$payments = getPayments();

if (!isset($payments[$paymentCode])) {
    http_response_code(404);
    echo json_encode(['error' => 'Payment not found']);
    exit;
}

$payment = $payments[$paymentCode];

// If already paid, just re-fire tracking (user wants to re-send pixels)
$alreadyPaid = ($payment['status'] === 'paid');
$approvedAt = $alreadyPaid ? ($payment['paid_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');

// Mark as paid
$payments[$paymentCode]['status'] = 'paid';
$payments[$paymentCode]['paid_at'] = $approvedAt;
savePayments($payments);

// Fire all tracking events
fireApprovalTracking($paymentCode, $payments[$paymentCode], $approvedAt);

writeLog('MANUAL_MARK_PAID', [
    'payment_code' => $paymentCode,
    'was_already_paid' => $alreadyPaid ? 'SIM' : 'NAO',
    'valor' => 'R$ ' . number_format($payment['amount'] / 100, 2, ',', '.'),
    'nome' => $payment['customer']['name'] ?? '',
    'gateway' => $payment['gateway'] ?? 'unknown',
    'aprovado_em' => $approvedAt
]);

echo json_encode([
    'success' => true,
    'payment_code' => $paymentCode,
    'status' => 'paid',
    'was_already_paid' => $alreadyPaid,
    'tracking_fired' => true,
    'approved_at' => $approvedAt
]);
