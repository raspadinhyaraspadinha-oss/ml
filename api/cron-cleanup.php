<?php
/* ============================================
   Cron: Payment Data Cleanup

   Prevents payments.json from growing indefinitely.
   Moves completed/failed payments older than 7 days
   to payments_archive.json (append-only).

   CRITICAL for scale: Without this, payments.json
   grows ~1MB per 1000 payments. At 10MB+, every
   getPayments()/savePayments() becomes slow.

   Setup cron (daily at 3am):
   0 3 * * * php /home/user/public_html/api/cron-cleanup.php?key=ml2025 >> /dev/null 2>&1

   Manual run: /api/cron-cleanup.php?key=ml2025
   ============================================ */

require_once __DIR__ . '/config.php';

// Auth: same key pattern as cron-skalepay
if (($_GET['key'] ?? '') !== 'ml2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$DAYS_TO_KEEP = 7;
$cutoffTime = time() - ($DAYS_TO_KEEP * 86400);

$payments = getPayments();
$totalBefore = count($payments);

$keep = [];
$archive = [];

foreach ($payments as $code => $payment) {
    $status = $payment['status'] ?? 'pending';

    // Always keep pending payments (regardless of age)
    if ($status === 'pending') {
        $keep[$code] = $payment;
        continue;
    }

    // Keep recent paid/failed (< 7 days old)
    $paidAt = strtotime($payment['paid_at'] ?? $payment['failed_at'] ?? $payment['created_at'] ?? '');
    if ($paidAt && $paidAt > $cutoffTime) {
        $keep[$code] = $payment;
    } else {
        $archive[$code] = $payment;
    }
}

$totalAfter = count($keep);
$archived = count($archive);

// Append to archive file (separate from active payments)
if (!empty($archive)) {
    $archiveFile = DATA_DIR . 'payments_archive.json';
    $existing = [];
    if (file_exists($archiveFile)) {
        $existing = json_decode(file_get_contents($archiveFile), true);
        if (!is_array($existing)) $existing = [];
    }

    foreach ($archive as $code => $payment) {
        $existing[$code] = $payment;
    }

    file_put_contents($archiveFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Save cleaned payments
    savePayments($keep);

    writeLog('CRON_CLEANUP', [
        'antes' => $totalBefore,
        'depois' => $totalAfter,
        'arquivados' => $archived,
        'dias_retidos' => $DAYS_TO_KEEP,
        'payments_json_kb' => round(strlen(json_encode($keep)) / 1024, 1),
        'archive_json_kb' => round(filesize($archiveFile) / 1024, 1)
    ]);
}

echo json_encode([
    'success' => true,
    'before' => $totalBefore,
    'after' => $totalAfter,
    'archived' => $archived,
    'days_kept' => $DAYS_TO_KEEP,
    'message' => $archived > 0
        ? "Archived $archived old payments. Active: $totalAfter"
        : "No old payments to archive. Active: $totalAfter"
]);
