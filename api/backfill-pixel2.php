<?php
/**
 * ============================================
 *  BACKFILL TikTok Events → Pixel 2 (D6G7SLBC77U2V3Q5N7A0)
 * ============================================
 *  Envia os eventos que o pixel 2 perdeu desde 06/03/2026 09:00 BRT.
 *
 *  Eventos:
 *    1. AddPaymentInfo  — TODOS os PIX gerados
 *    2. CompletePayment — TODAS as vendas pagas
 *
 *  SOMENTE envia para pixel 2. Pixel 1 já tem esses eventos.
 *
 *  Uso via SSH:
 *    cd ~/public_html/api       (ou o path do seu site)
 *    php backfill-pixel2.php                    # dry-run
 *    php backfill-pixel2.php --send             # envia tudo
 *    php backfill-pixel2.php --send --paid-only # só vendas
 *
 *  Segurança: Bloqueia acesso via browser (só CLI)
 * ============================================
 */

// Bloqueia acesso via browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'CLI only']);
    exit;
}

// Carrega config (tem apiPost, TIKTOK_ACCESS_TOKEN, etc.)
require_once __DIR__ . '/config.php';

// ─── ARGS ─────────────────────────────────────────────
$args = array_slice($argv, 1);
$DRY_RUN = !in_array('--send', $args);
$PAID_ONLY = in_array('--paid-only', $args);

$PIXEL_2 = TIKTOK_PIXEL_ID_2; // 'D6G7SLBC77U2V3Q5N7A0'
$CUTOFF = '2026-03-06 09:00:00'; // BRT
$DELAY_US = 250000; // 250ms entre requests

echo "============================================\n";
echo "  BACKFILL TikTok Pixel 2: $PIXEL_2\n";
echo "  Cutoff: $CUTOFF BRT\n";
echo "  Mode: " . ($DRY_RUN ? "🔍 DRY RUN (use --send para enviar)" : "🚀 ENVIANDO PARA O TIKTOK") . "\n";
if ($PAID_ONLY) echo "  Filter: Somente vendas (CompletePayment)\n";
echo "============================================\n\n";

// ─── CARREGA PAYMENTS ─────────────────────────────────
echo "Carregando payments.json...\n";
$paymentsFile = DATA_DIR . 'payments.json';
if (!file_exists($paymentsFile)) {
    echo "❌ ERRO: $paymentsFile não encontrado!\n";
    echo "   Verifique o caminho do DATA_DIR em config.php\n";
    exit(1);
}

$raw = file_get_contents($paymentsFile);
$allPayments = json_decode($raw, true);
if (!is_array($allPayments)) {
    echo "❌ ERRO: payments.json inválido!\n";
    exit(1);
}
echo "Total de pagamentos no arquivo: " . count($allPayments) . "\n\n";

// ─── FILTRA A PARTIR DO CUTOFF ────────────────────────
$afterCutoff = [];
$paidPayments = [];

foreach ($allPayments as $code => $p) {
    $created = $p['created_at'] ?? '';
    if ($created >= $CUTOFF) {
        $afterCutoff[] = $p;
        $status = $p['status'] ?? '';
        $paidAt = $p['paid_at'] ?? '';
        if ($status === 'paid' || (!empty($paidAt))) {
            $paidPayments[] = $p;
        }
    }
}

echo "Pagamentos após $CUTOFF: " . count($afterCutoff) . "\n";
echo "  → Pagos (CompletePayment): " . count($paidPayments) . "\n";
echo "  → Total PIX gerado (AddPaymentInfo): " . count($afterCutoff) . "\n\n";

// ─── HELPERS ──────────────────────────────────────────

/**
 * Converte timestamp para Unix. Lida com:
 *   "2026-03-06 10:03:32"          (BRT local)
 *   "2026-03-06T14:12:36.000Z"     (UTC ISO)
 *   "2026-03-07T07:23:16+08:00"    (com timezone)
 */
function parseTs($ts) {
    if (empty($ts)) return time();

    // Formato BRT simples (sem T, sem Z)
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts)) {
        $dt = new DateTime($ts, new DateTimeZone('America/Sao_Paulo'));
        return $dt->getTimestamp();
    }

    // ISO
    $t = strtotime($ts);
    return $t ?: time();
}

/**
 * Envia evento SOMENTE para pixel 2
 */
function sendToPixel2($eventName, $eventId, $payment, $eventTime) {
    global $PIXEL_2;

    $customer = $payment['customer'] ?? [];
    $tracking = $payment['tracking'] ?? [];
    $amount = $payment['amount'] ?? 0;
    $items = $payment['items'] ?? [];
    $clientIp = $payment['client_ip'] ?? $customer['ip'] ?? '0.0.0.0';
    $phone = preg_replace('/\D/', '', $customer['phone'] ?? '');

    // Build context.user (hashed)
    $contextUser = array_filter([
        'email' => !empty($customer['email']) ? hash('sha256', strtolower(trim($customer['email']))) : null,
        'phone_number' => !empty($phone) ? hash('sha256', '+55' . $phone) : null,
        'external_id' => !empty($payment['payment_code']) ? hash('sha256', $payment['payment_code']) : null,
        'ttclid' => $tracking['ttclid'] ?? null,
    ], function($v) { return $v !== null && $v !== ''; });

    // Build contents
    $contents = [];
    if (!empty($items)) {
        foreach ($items as $item) {
            $contents[] = [
                'content_id' => $item['id'] ?? 'item',
                'content_name' => $item['name'] ?? 'Produto',
                'content_type' => 'product',
                'quantity' => intval($item['quantity'] ?? 1),
                'price' => ($item['price'] ?? $amount) / 100,
            ];
        }
    } else {
        $contents[] = [
            'content_id' => 'item',
            'content_name' => 'Produto',
            'content_type' => 'product',
            'quantity' => 1,
            'price' => $amount / 100,
        ];
    }

    // Properties
    $properties = [
        'contents' => $contents,
        'content_type' => 'product',
        'currency' => 'BRL',
        'value' => $amount / 100,
    ];
    if ($eventName === 'CompletePayment' || $eventName === 'PlaceAnOrder') {
        $properties['order_id'] = $payment['payment_code'];
    }
    if ($eventName === 'AddPaymentInfo') {
        $properties['description'] = 'PIX';
    }

    $payload = [
        'event_source' => 'web',
        'event_source_id' => $PIXEL_2,
        'data' => [[
            'event' => $eventName,
            'event_id' => $eventId,
            'event_time' => $eventTime,
            'context' => [
                'user_agent' => $payment['user_agent'] ?? '',
                'ip' => $clientIp,
                'page' => [
                    'url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'www.aniversariodomes.shop') . '/checkout/',
                    'referrer' => '',
                ],
                'user' => $contextUser,
            ],
            'properties' => $properties,
        ]]
    ];

    return apiPost(
        'https://business-api.tiktok.com/open_api/v1.3/event/track/',
        ['Content-Type: application/json', 'Access-Token: ' . TIKTOK_ACCESS_TOKEN],
        $payload
    );
}

// ─── CONTADORES ───────────────────────────────────────
$stats = [
    'total' => 0,
    'success' => 0,
    'fail' => 0,
    'api' => [
        'AddPaymentInfo' => ['sent' => 0, 'ok' => 0, 'fail' => 0],
        'CompletePayment' => ['sent' => 0, 'ok' => 0, 'fail' => 0],
    ]
];

// ─── FASE 1: AddPaymentInfo ──────────────────────────
if (!$PAID_ONLY) {
    $total = count($afterCutoff);
    echo "━━━ FASE 1: AddPaymentInfo (PIX gerado) ━━━\n";
    echo "Enviando $total eventos...\n\n";

    foreach ($afterCutoff as $i => $p) {
        $code = $p['payment_code'] ?? 'unknown';
        $eventId = 'tt_api_' . $code . '_' . parseTs($p['created_at'] ?? '');
        $eventTime = parseTs($p['created_at'] ?? '');
        $valor = ($p['amount'] ?? 0) / 100;
        $num = $i + 1;

        $stats['total']++;
        $stats['api']['AddPaymentInfo']['sent']++;

        if ($DRY_RUN) {
            echo "[$num/$total] DRY: AddPaymentInfo | $code | R\$ $valor | " . ($p['created_at'] ?? '') . "\n";
            $stats['success']++;
            $stats['api']['AddPaymentInfo']['ok']++;
            continue;
        }

        $result = sendToPixel2('AddPaymentInfo', $eventId, $p, $eventTime);

        if ($result && $result['status'] === 200) {
            $stats['success']++;
            $stats['api']['AddPaymentInfo']['ok']++;
            echo "[$num/$total] ✅ AddPaymentInfo | $code | R\$ $valor\n";
        } else {
            $stats['fail']++;
            $stats['api']['AddPaymentInfo']['fail']++;
            $httpStatus = $result['status'] ?? 'ERR';
            $errBody = is_array($result['body'] ?? null) ? json_encode($result['body']) : ($result['raw'] ?? $result['error'] ?? '');
            echo "[$num/$total] ❌ AddPaymentInfo | $code | HTTP $httpStatus | " . substr($errBody, 0, 120) . "\n";
        }

        usleep($DELAY_US);
    }
    echo "\n";
}

// ─── FASE 2: CompletePayment (vendas) ────────────────
$total = count($paidPayments);
echo "━━━ FASE 2: CompletePayment (vendas pagas) ━━━\n";
echo "Enviando $total eventos...\n\n";

foreach ($paidPayments as $i => $p) {
    $code = $p['payment_code'] ?? 'unknown';
    $eventId = 'pur_' . $code; // Match browser event_id for dedup
    $eventTime = parseTs($p['paid_at'] ?? $p['created_at'] ?? '');
    $valor = ($p['amount'] ?? 0) / 100;
    $num = $i + 1;

    $stats['total']++;
    $stats['api']['CompletePayment']['sent']++;

    if ($DRY_RUN) {
        echo "[$num/$total] DRY: CompletePayment | $code | R\$ $valor | " . ($p['paid_at'] ?? '') . "\n";
        $stats['success']++;
        $stats['api']['CompletePayment']['ok']++;
        continue;
    }

    $result = sendToPixel2('CompletePayment', $eventId, $p, $eventTime);

    if ($result && $result['status'] === 200) {
        $stats['success']++;
        $stats['api']['CompletePayment']['ok']++;
        echo "[$num/$total] ✅ CompletePayment | $code | R\$ $valor | " . ($p['paid_at'] ?? '') . "\n";
    } else {
        $stats['fail']++;
        $stats['api']['CompletePayment']['fail']++;
        $httpStatus = $result['status'] ?? 'ERR';
        $errBody = is_array($result['body'] ?? null) ? json_encode($result['body']) : ($result['raw'] ?? $result['error'] ?? '');
        echo "[$num/$total] ❌ CompletePayment | $code | HTTP $httpStatus | " . substr($errBody, 0, 120) . "\n";
    }

    usleep($DELAY_US);
}

// ─── RESUMO ──────────────────────────────────────────
$totalRevenue = 0;
foreach ($paidPayments as $p) {
    $totalRevenue += ($p['amount'] ?? 0) / 100;
}

echo "\n============================================\n";
echo "  RESUMO DO BACKFILL\n";
echo "============================================\n";
echo "  Pixel:           $PIXEL_2\n";
echo "  Modo:            " . ($DRY_RUN ? 'DRY RUN' : 'PRODUÇÃO') . "\n";
echo "  Total eventos:   {$stats['total']}\n";
echo "  ✅ Sucesso:      {$stats['success']}\n";
echo "  ❌ Falha:        {$stats['fail']}\n\n";
if (!$PAID_ONLY) {
    echo "  AddPaymentInfo:  {$stats['api']['AddPaymentInfo']['ok']}/{$stats['api']['AddPaymentInfo']['sent']} OK\n";
}
echo "  CompletePayment: {$stats['api']['CompletePayment']['ok']}/{$stats['api']['CompletePayment']['sent']} OK\n\n";
echo "  💰 Receita total reportada: R\$ " . number_format($totalRevenue, 2, ',', '.') . "\n";
echo "============================================\n";

if ($DRY_RUN) {
    echo "\n⚠️  Nenhum evento foi enviado (dry run).\n";
    echo "   Para enviar de verdade:\n";
    echo "     php backfill-pixel2.php --send\n";
    echo "   Somente vendas:\n";
    echo "     php backfill-pixel2.php --send --paid-only\n";
}
