<?php
/* ============================================
   Create PIX Payment (Multi-Gateway)
   POST /api/payment.php
   Body: { customer, items, amount, metadata }
   Supports: SkalePay / Mangofy with automatic fallback
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
$originalCustomer = $customer;
$amount = intval($input['amount']);
$items = isset($input['items']) ? $input['items'] : [];
$metadata = isset($input['metadata']) ? $input['metadata'] : [];
$trackingParams = isset($input['trackingParameters']) ? $input['trackingParameters'] : [];

// Safety: reject payments below R$5,00 (500 cents)
if ($amount < 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor do pedido inválido. Mínimo R$ 5,00.', 'amount_received' => $amount]);
    exit;
}

// Fallback customer data
$FALLBACK_CUSTOMER = [
    'email' => 'cidinha_lira10@hotmail.com',
    'name' => 'MARIA APARECIDA NUNES DE LIRA',
    'document' => '88017427468',
    'phone' => '11973003483'
];

$externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$activeGateway = getActiveGateway();

// ═══ FASE 3A: PIX Idempotency (server-side) ═══
// If a session_id is provided, check for existing pending payment with same amount
if (isFeatureEnabled('pix_idempotency')) {
    $sessionId = $metadata['session_id'] ?? '';
    if ($sessionId !== '') {
        $existingPayments = getPayments();
        foreach ($existingPayments as $pCode => $pData) {
            if (($pData['status'] ?? '') !== 'pending') continue;
            if (($pData['session_id'] ?? '') !== $sessionId) continue;
            if (($pData['amount'] ?? 0) !== $amount) continue;

            // Check age: reuse only if < 30 minutes old
            $createdTime = strtotime($pData['created_at'] ?? '');
            if ($createdTime && (time() - $createdTime) < 1800) {
                // Found existing pending payment — return it without hitting gateway
                $existingQrcode = $pData['pix_qrcode_text'] ?? '';
                if ($existingQrcode !== '') {
                    writeLog('PIX_IDEMPOTENT_REUSE', [
                        'payment_code' => $pCode,
                        'session_id' => $sessionId,
                        'amount' => $amount,
                        'age_seconds' => time() - $createdTime
                    ]);

                    echo json_encode([
                        'success' => true,
                        'payment_code' => $pCode,
                        'external_code' => $pData['external_code'] ?? $pCode,
                        'pix_qrcode_text' => $existingQrcode,
                        'amount' => $amount,
                        'gateway' => $pData['gateway'] ?? 'unknown',
                        'reused' => true
                    ]);
                    exit;
                }
            }
        }
    }
}

// ═══════════════════════════════════════════
//  GATEWAY FUNCTIONS
// ═══════════════════════════════════════════

function trySkalepay($cust, $amount, $items, $clientIp) {
    $auth = 'Basic ' . base64_encode(SKALEPAY_API_KEY . ':x');

    // IMPORTANT: Gateway must NOT see product names (compliance).
    // Use generic "Pedido" + sequential index only.
    $skItems = [];
    $itemIdx = 1;
    foreach ($items as $item) {
        $skItems[] = [
            'tangible' => false,
            'title' => 'Pedido Item ' . $itemIdx,
            'unitPrice' => intval($item['price'] ?? $amount),
            'quantity' => intval($item['quantity'] ?? 1)
        ];
        $itemIdx++;
    }
    if (empty($skItems)) {
        $skItems[] = ['tangible' => false, 'title' => 'Pedido', 'unitPrice' => $amount, 'quantity' => 1];
    }

    $payload = [
        'customer' => [
            'document' => [
                'number' => preg_replace('/\D/', '', $cust['document'] ?? ''),
                'type' => 'cpf'
            ],
            'name' => $cust['name'] ?? '',
            'email' => $cust['email'] ?? '',
            'phone' => preg_replace('/\D/', '', $cust['phone'] ?? '')
        ],
        'amount' => $amount,
        'paymentMethod' => 'pix',
        'items' => $skItems
    ];

    // ── LOG: Request sendo enviado para SkalePay ──
    writeLog('SKALEPAY_REQUEST', [
        'customer' => ($cust['name'] ?? '') . ' <' . ($cust['email'] ?? '') . '>',
        'amount' => $amount,
        'items' => count($skItems)
    ]);

    $response = apiPost(
        SKALEPAY_API_URL . '/transactions',
        ['Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $auth],
        $payload
    );

    // ── LOG: Response recebido da SkalePay (verbose only on error) ──
    if ($response['status'] !== 200 && $response['status'] !== 201) {
        writeLog('SKALEPAY_RESPONSE', [
            'http_status' => $response['status'],
            'curl_error' => $response['error'] ?: 'nenhum',
            'body_keys' => is_array($response['body']) ? implode(',', array_keys($response['body'])) : 'N/A',
            'raw_response' => substr($response['raw'] ?? '', 0, 1000)
        ]);
    }

    if (($response['status'] === 200 || $response['status'] === 201) &&
        isset($response['body']['id']) && isset($response['body']['pix']['qrcode'])) {

        writeLog('SKALEPAY_SUCESSO', [
            'id' => $response['body']['id'],
            'qrcode_len' => strlen($response['body']['pix']['qrcode']),
            'amount' => $amount
        ]);

        return [
            'gateway' => 'skalepay',
            'gateway_id' => strval($response['body']['id']),
            'payment_code' => 'sk_' . $response['body']['id'],
            'qrcode' => $response['body']['pix']['qrcode'],
            'raw_response' => $response['body']
        ];
    }

    // ── LOG: Diagnóstico detalhado da falha ──
    $failReason = 'desconhecido';
    if (!empty($response['error'])) {
        $failReason = 'curl_error: ' . $response['error'];
    } elseif ($response['status'] === 0) {
        $failReason = 'conexao_falhou (timeout ou DNS)';
    } elseif ($response['status'] >= 500) {
        $failReason = 'servidor_skalepay_erro_' . $response['status'];
    } elseif ($response['status'] >= 400) {
        $failReason = 'requisicao_rejeitada_' . $response['status'];
    } elseif ($response['body'] === null) {
        $failReason = 'resposta_nao_e_json_valido';
    } elseif (!isset($response['body']['id'])) {
        $failReason = 'campo_id_ausente_na_resposta';
    } elseif (!isset($response['body']['pix'])) {
        $failReason = 'objeto_pix_ausente_na_resposta';
    } elseif (!isset($response['body']['pix']['qrcode'])) {
        $failReason = 'campo_pix.qrcode_ausente_na_resposta';
    }

    writeLog('SKALEPAY_FALHOU', [
        'motivo' => $failReason,
        'http_status' => $response['status'],
        'curl_error' => $response['error'] ?: 'nenhum',
        'response_preview' => substr($response['raw'] ?? '', 0, 1000)
    ]);

    return ['error' => true, 'status' => $response['status'], 'raw' => $response['raw'] ?? '', 'fail_reason' => $failReason];
}

function tryMangofy($cust, $amount, $items, $externalCode, $clientIp, $metadata) {
    // IMPORTANT: Gateway must NOT see product names (compliance).
    // Use generic codes only.
    $mangofyItems = [];
    $itemIdx = 1;
    foreach ($items as $item) {
        $mangofyItems[] = [
            'code' => 'ITEM-' . $externalCode . '-' . $itemIdx,
            'amount' => intval($item['quantity'] ?? 1),
            'price' => intval($item['price'] ?? $amount)
        ];
        $itemIdx++;
    }
    if (empty($mangofyItems)) {
        $mangofyItems[] = ['code' => 'ITEM-' . $externalCode, 'amount' => 1, 'price' => $amount];
    }

    $payload = [
        'store_code' => MANGOFY_STORE_CODE,
        'external_code' => $externalCode,
        'payment_method' => 'pix',
        'payment_amount' => $amount,
        'payment_format' => 'regular',
        'installments' => 1,
        'pix' => ['expires_in_days' => 1],
        'postback_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                           '://' . $_SERVER['HTTP_HOST'] . '/api/webhook.php',
        'items' => $mangofyItems,
        'customer' => [
            'email' => $cust['email'] ?? '',
            'name' => strtoupper($cust['name'] ?? ''),
            'document' => preg_replace('/\D/', '', $cust['document'] ?? ''),
            'phone' => preg_replace('/\D/', '', $cust['phone'] ?? ''),
            'ip' => $clientIp
        ],
        'metadata' => array_merge($metadata, ['session_id' => $externalCode])
    ];

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: ' . MANGOFY_AUTHORIZATION,
        'Store-Code: ' . MANGOFY_STORE_CODE
    ];

    $response = apiPost(MANGOFY_API_URL . '/payment', $headers, $payload);

    if (($response['status'] === 200 || $response['status'] === 201) &&
        isset($response['body']['payment_code'])) {
        return [
            'gateway' => 'mangofy',
            'gateway_id' => $response['body']['payment_code'],
            'payment_code' => $response['body']['payment_code'],
            'qrcode' => $response['body']['pix']['pix_qrcode_text'] ?? '',
            'raw_response' => $response['body']
        ];
    }

    return ['error' => true, 'status' => $response['status'], 'raw' => $response['raw'] ?? ''];
}

function tryNitroPagamento($cust, $amount, $items, $externalCode, $clientIp, $metadata) {
    $auth = 'Basic ' . base64_encode(NITROPAGAMENTO_PK . ':' . NITROPAGAMENTO_SK);

    // NitroPagamento expects amount in REAIS (float), not cents
    $amountBRL = $amount / 100;

    // IMPORTANT: Gateway must NOT see product names (compliance).
    $npItems = [];
    $itemIdx = 1;
    foreach ($items as $item) {
        $npItems[] = [
            'title' => 'Pedido Item ' . $itemIdx,
            'unit_price' => intval($item['price'] ?? $amount) / 100,
            'quantity' => intval($item['quantity'] ?? 1)
        ];
        $itemIdx++;
    }
    if (empty($npItems)) {
        $npItems[] = ['title' => 'Pedido', 'unit_price' => $amountBRL, 'quantity' => 1];
    }

    $postbackUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                   '://' . $_SERVER['HTTP_HOST'] . '/api/webhook-nitropagamento.php';

    $payload = [
        'amount' => $amountBRL,
        'payment_method' => 'pix',
        'description' => 'Pedido ' . $externalCode,
        'items' => $npItems,
        'customer' => [
            'name' => $cust['name'] ?? '',
            'email' => $cust['email'] ?? '',
            'document' => preg_replace('/\D/', '', $cust['document'] ?? ''),
            'phone' => preg_replace('/\D/', '', $cust['phone'] ?? '')
        ],
        'metadata' => array_merge($metadata, ['external_code' => $externalCode]),
        'postbackUrl' => $postbackUrl,
        'tracking' => [
            'ip' => $clientIp,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]
    ];

    // ── LOG: Request NitroPagamento ──
    writeLog('NITROPAGAMENTO_REQUEST', [
        'customer' => ($cust['name'] ?? '') . ' <' . ($cust['email'] ?? '') . '>',
        'amount_brl' => $amountBRL,
        'items' => count($npItems)
    ]);

    $response = apiPost(
        NITROPAGAMENTO_API_URL,
        ['Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $auth],
        $payload
    );

    // ── LOG: Response NitroPagamento (verbose only on error) ──
    if ($response['status'] !== 200 && $response['status'] !== 201) {
        writeLog('NITROPAGAMENTO_RESPONSE', [
            'http_status' => $response['status'],
            'curl_error' => $response['error'] ?: 'nenhum',
            'body_keys' => is_array($response['body']) ? implode(',', array_keys($response['body'])) : 'N/A',
            'raw_response' => substr($response['raw'] ?? '', 0, 1000)
        ]);
    }

    // NitroPagamento returns: { success: true, data: { id, pix_code, pix_qr_code, status } }
    $data = $response['body']['data'] ?? $response['body'] ?? [];

    if (($response['status'] === 200 || $response['status'] === 201) &&
        isset($data['id']) && (isset($data['pix_code']) || isset($data['pix_qr_code']))) {

        $npId = $data['id']; // e.g. PXB_xxx
        $pixCode = $data['pix_code'] ?? '';

        writeLog('NITROPAGAMENTO_SUCESSO', [
            'id' => $npId,
            'pix_code_len' => strlen($pixCode),
            'amount_brl' => $amountBRL
        ]);

        return [
            'gateway' => 'nitropagamento',
            'gateway_id' => strval($npId),
            'payment_code' => 'np_' . $npId,
            'qrcode' => $pixCode,
            'raw_response' => $data
        ];
    }

    // ── LOG: Diagnóstico detalhado da falha ──
    $failReason = 'desconhecido';
    if (!empty($response['error'])) {
        $failReason = 'curl_error: ' . $response['error'];
    } elseif ($response['status'] === 0) {
        $failReason = 'conexao_falhou (timeout ou DNS)';
    } elseif ($response['status'] >= 500) {
        $failReason = 'servidor_nitropagamento_erro_' . $response['status'];
    } elseif ($response['status'] >= 400) {
        $failReason = 'requisicao_rejeitada_' . $response['status'];
    } elseif ($response['body'] === null) {
        $failReason = 'resposta_nao_e_json_valido';
    } elseif (!isset($data['id'])) {
        $failReason = 'campo_id_ausente_na_resposta';
    } elseif (!isset($data['pix_code'])) {
        $failReason = 'campo_pix_code_ausente_na_resposta';
    }

    writeLog('NITROPAGAMENTO_FALHOU', [
        'motivo' => $failReason,
        'http_status' => $response['status'],
        'curl_error' => $response['error'] ?: 'nenhum',
        'response_preview' => substr($response['raw'] ?? '', 0, 1000)
    ]);

    return ['error' => true, 'status' => $response['status'], 'raw' => $response['raw'] ?? '', 'fail_reason' => $failReason];
}

// ═══════════════════════════════════════════
//  PAYMENT CREATION WITH FALLBACK CHAIN
// ═══════════════════════════════════════════

$result = null;
$usedFallback = false;

// SkalePay PIX limit: R$ 200,00 (20000 cents)
$SKALEPAY_LIMIT = 20000;

if ($activeGateway === 'skalepay') {
    writeLog('GATEWAY_CHAIN_INICIO', ['gateway' => 'skalepay', 'amount' => $amount, 'limite_skalepay' => $SKALEPAY_LIMIT, 'customer' => $customer['name'] ?? '']);

    // ── Check SkalePay R$200 PIX limit ──
    if ($amount > $SKALEPAY_LIMIT) {
        writeLog('SKALEPAY_SKIP_LIMITE', [
            'motivo' => 'valor_excede_limite_R200',
            'amount_cents' => $amount,
            'amount_brl' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
            'limite_cents' => $SKALEPAY_LIMIT,
            'limite_brl' => 'R$ ' . number_format($SKALEPAY_LIMIT / 100, 2, ',', '.'),
            'redirecionando' => 'mangofy_direto'
        ]);

        // Skip SkalePay entirely → go to Mangofy directly
        $result = tryMangofy($customer, $amount, $items, $externalCode, $clientIp, $metadata);

        if (isset($result['error'])) {
            writeLog('MANGOFY_APOS_SKIP_SKALEPAY_ERRO_1', [
                'http_status' => $result['status'],
                'response_preview' => substr($result['raw'] ?? '', 0, 500)
            ]);

            // Mangofy com fallback customer
            $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
            $result = tryMangofy($FALLBACK_CUSTOMER, $amount, $items, $externalCode, $clientIp, $metadata);

            if (!isset($result['error'])) {
                $usedFallback = true;
                writeLog('MANGOFY_APOS_SKIP_SKALEPAY_FALLBACK_SUCESSO', [
                    'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
                    'payment_code' => $result['payment_code'] ?? ''
                ]);
            } else {
                writeLog('MANGOFY_APOS_SKIP_SKALEPAY_ERRO_2_FINAL', [
                    'http_status' => $result['status'],
                    'response_preview' => substr($result['raw'] ?? '', 0, 500)
                ]);
            }
        } else {
            writeLog('MANGOFY_APOS_SKIP_SKALEPAY_SUCESSO', [
                'motivo' => 'skalepay_limite_R200_excedido',
                'payment_code' => $result['payment_code'] ?? '',
                'amount' => $amount
            ]);
        }
    } else {
        // ── Amount within SkalePay limit → try SkalePay normally ──

        // 1) SkalePay com dados reais
        $result = trySkalepay($customer, $amount, $items, $clientIp);

        if (isset($result['error'])) {
            writeLog('SKALEPAY_ERRO_TENTATIVA_1', [
                'motivo' => $result['fail_reason'] ?? 'desconhecido',
                'http_status' => $result['status'],
                'customer' => ($customer['name'] ?? '') . ' <' . ($customer['email'] ?? '') . '>',
                'response_preview' => substr($result['raw'] ?? '', 0, 500)
            ]);

            // 2) SkalePay com fallback customer
            writeLog('SKALEPAY_TENTATIVA_2_FALLBACK', ['usando' => 'fallback_customer']);
            $result = trySkalepay($FALLBACK_CUSTOMER, $amount, $items, $clientIp);

            if (!isset($result['error'])) {
                $usedFallback = true;
                writeLog('SKALEPAY_FALLBACK_SUCESSO', [
                    'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
                    'payment_id' => $result['gateway_id'] ?? ''
                ]);
            } else {
                writeLog('SKALEPAY_ERRO_TENTATIVA_2', [
                    'motivo' => $result['fail_reason'] ?? 'desconhecido',
                    'http_status' => $result['status'],
                    'response_preview' => substr($result['raw'] ?? '', 0, 500)
                ]);

                // 3) Mangofy como ultima tentativa (dados reais)
                writeLog('MANGOFY_BACKUP_TENTATIVA_1', ['motivo' => 'skalepay_falhou_2x']);
                $result = tryMangofy($customer, $amount, $items, $externalCode, $clientIp, $metadata);

                if (isset($result['error'])) {
                    writeLog('MANGOFY_BACKUP_ERRO_1', [
                        'http_status' => $result['status'],
                        'response_preview' => substr($result['raw'] ?? '', 0, 500)
                    ]);

                    // 4) Mangofy com fallback customer
                    $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
                    $result = tryMangofy($FALLBACK_CUSTOMER, $amount, $items, $externalCode, $clientIp, $metadata);

                    if (!isset($result['error'])) {
                        $usedFallback = true;
                        writeLog('MANGOFY_BACKUP_FALLBACK_SUCESSO', [
                            'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
                            'payment_code' => $result['payment_code'] ?? ''
                        ]);
                    } else {
                        writeLog('MANGOFY_BACKUP_ERRO_2_FINAL', [
                            'http_status' => $result['status'],
                            'response_preview' => substr($result['raw'] ?? '', 0, 500)
                        ]);
                    }
                }
            }
        } else {
            writeLog('SKALEPAY_SUCESSO_DIRETO', [
                'payment_id' => $result['gateway_id'] ?? '',
                'customer' => ($customer['name'] ?? '')
            ]);
        }
    }
} elseif ($activeGateway === 'nitropagamento') {
    // NitroPagamento primary
    writeLog('GATEWAY_CHAIN_INICIO', ['gateway' => 'nitropagamento', 'amount' => $amount, 'customer' => $customer['name'] ?? '']);

    // 1) NitroPagamento com dados reais
    $result = tryNitroPagamento($customer, $amount, $items, $externalCode, $clientIp, $metadata);

    if (isset($result['error'])) {
        writeLog('NITROPAGAMENTO_ERRO_TENTATIVA_1', [
            'motivo' => $result['fail_reason'] ?? 'desconhecido',
            'http_status' => $result['status'],
            'customer' => ($customer['name'] ?? '') . ' <' . ($customer['email'] ?? '') . '>',
            'response_preview' => substr($result['raw'] ?? '', 0, 500)
        ]);

        // 2) NitroPagamento com fallback customer
        writeLog('NITROPAGAMENTO_TENTATIVA_2_FALLBACK', ['usando' => 'fallback_customer']);
        $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
        $result = tryNitroPagamento($FALLBACK_CUSTOMER, $amount, $items, $externalCode, $clientIp, $metadata);

        if (!isset($result['error'])) {
            $usedFallback = true;
            writeLog('NITROPAGAMENTO_FALLBACK_SUCESSO', [
                'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
                'payment_code' => $result['payment_code'] ?? ''
            ]);
        } else {
            writeLog('NITROPAGAMENTO_ERRO_TENTATIVA_2', [
                'motivo' => $result['fail_reason'] ?? 'desconhecido',
                'http_status' => $result['status'],
                'response_preview' => substr($result['raw'] ?? '', 0, 500)
            ]);

            // 3) Mangofy como backup (dados reais)
            writeLog('MANGOFY_BACKUP_APOS_NITRO_TENTATIVA_1', ['motivo' => 'nitropagamento_falhou_2x']);
            $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
            $result = tryMangofy($customer, $amount, $items, $externalCode, $clientIp, $metadata);

            if (isset($result['error'])) {
                writeLog('MANGOFY_BACKUP_APOS_NITRO_ERRO_1', [
                    'http_status' => $result['status'],
                    'response_preview' => substr($result['raw'] ?? '', 0, 500)
                ]);

                // 4) Mangofy com fallback customer
                $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
                $result = tryMangofy($FALLBACK_CUSTOMER, $amount, $items, $externalCode, $clientIp, $metadata);

                if (!isset($result['error'])) {
                    $usedFallback = true;
                    writeLog('MANGOFY_BACKUP_APOS_NITRO_FALLBACK_SUCESSO', [
                        'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? ''),
                        'payment_code' => $result['payment_code'] ?? ''
                    ]);
                } else {
                    writeLog('MANGOFY_BACKUP_APOS_NITRO_ERRO_2_FINAL', [
                        'http_status' => $result['status'],
                        'response_preview' => substr($result['raw'] ?? '', 0, 500)
                    ]);
                }
            }
        }
    } else {
        writeLog('NITROPAGAMENTO_SUCESSO_DIRETO', [
            'payment_code' => $result['payment_code'] ?? '',
            'customer' => ($customer['name'] ?? '')
        ]);
    }
} else {
    // Mangofy primary
    // 1) Mangofy com dados reais
    $result = tryMangofy($customer, $amount, $items, $externalCode, $clientIp, $metadata);

    if (isset($result['error'])) {
        writeLog('MANGOFY_ERRO_TENTATIVA_1', [
            'status' => $result['status'], 'erro' => $result['raw'], 'customer' => $customer['name'] ?? ''
        ]);

        // 2) Mangofy com fallback customer
        $externalCode = 'pay_' . time() . '_' . substr(md5(uniqid()), 0, 6);
        $result = tryMangofy($FALLBACK_CUSTOMER, $amount, $items, $externalCode, $clientIp, $metadata);

        if (!isset($result['error'])) {
            $usedFallback = true;
            writeLog('MANGOFY_FALLBACK_USADO', [
                'customer_original' => ($originalCustomer['name'] ?? '') . ' / ' . ($originalCustomer['email'] ?? '')
            ]);
        }
    }
}

// All attempts failed
if (!$result || isset($result['error'])) {
    writeLog('PAGAMENTO_FALHOU_TOTAL', [
        'gateway_primario' => $activeGateway,
        'amount' => $amount,
        'customer' => ($originalCustomer['name'] ?? '') . ' <' . ($originalCustomer['email'] ?? '') . '>',
        'ultimo_erro' => $result['fail_reason'] ?? ($result['raw'] ?? 'sem detalhes'),
        'ultimo_http' => $result['status'] ?? 0
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Todas as tentativas de pagamento falharam', 'details' => $result['raw'] ?? '']);
    exit;
}

// ═══════════════════════════════════════════
//  STORE PAYMENT & FIRE TRACKING EVENTS
// ═══════════════════════════════════════════

$paymentCode = $result['payment_code'];
$pixQrcodeText = $result['qrcode'];
$usedGateway = $result['gateway'];
$gatewayId = $result['gateway_id'];
$createdAt = date('Y-m-d H:i:s');

// Store payment locally (always use original customer data)
$payments = getPayments();
$payments[$paymentCode] = [
    'payment_code' => $paymentCode,
    'external_code' => $externalCode,
    'gateway' => $usedGateway,
    'gateway_id' => $gatewayId,
    'status' => 'pending',
    'amount' => $amount,
    'customer' => $originalCustomer,
    'items' => $items,
    'tracking' => $trackingParams,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'client_ip' => $clientIp,
    'session_id' => $metadata['session_id'] ?? '',
    'experiment_id' => $metadata['experiment_id'] ?? '',
    'variant_id' => $metadata['variant_id'] ?? '',
    'pix_qrcode_text' => $pixQrcodeText,
    'address' => [
        'cep' => $input['metadata']['cep'] ?? '',
        'cidade' => $input['metadata']['cidade'] ?? '',
        'uf' => $input['metadata']['uf'] ?? '',
        'bairro' => $input['metadata']['bairro'] ?? ''
    ],
    'created_at' => $createdAt,
    'paid_at' => null
];
savePayments($payments);

// ── UTMify waiting_payment ──
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
    'status' => 'waiting_payment', 'createdAt' => $createdAt, 'approvedDate' => null, 'refundedAt' => null,
    'customer' => [
        'name' => strtoupper($customer['name'] ?? ''), 'email' => $customer['email'] ?? '',
        'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ''),
        'document' => preg_replace('/\D/', '', $customer['document'] ?? ''),
        'country' => 'BR', 'ip' => $clientIp
    ],
    'products' => $utmifyProducts,
    'trackingParameters' => [
        'src' => $trackingParams['src'] ?? null, 'sck' => $trackingParams['sck'] ?? null,
        'utm_source' => $trackingParams['utm_source'] ?? null, 'utm_campaign' => $trackingParams['utm_campaign'] ?? null,
        'utm_medium' => $trackingParams['utm_medium'] ?? null, 'utm_content' => $trackingParams['utm_content'] ?? null,
        'utm_term' => $trackingParams['utm_term'] ?? null, 'fbclid' => $trackingParams['fbclid'] ?? null,
        'fbp' => $trackingParams['fbp'] ?? null
    ],
    'commission' => ['totalPriceInCents' => $amount, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $amount],
    'isTest' => false
];

$utmifyResult = apiPost(UTMIFY_API_URL, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_API_TOKEN], $utmifyPayload);
writeApiEvent($paymentCode, 'waiting_payment', 'utmify', UTMIFY_API_URL, $utmifyResult['status'],
    ['orderId' => $paymentCode, 'status' => 'waiting_payment', 'amount' => $amount / 100, 'gateway' => $usedGateway],
    $utmifyResult['body'] ?? $utmifyResult['raw'], $trackingParams,
    $utmifyResult['status'] >= 200 && $utmifyResult['status'] < 300);

// ── Build enriched user_data for FB CAPI (high EMQ) ──
$nameParts = explode(' ', trim($customer['name'] ?? ''));
$firstName = $nameParts[0] ?? '';
$lastName = count($nameParts) > 1 ? end($nameParts) : '';

// Parse address data from metadata for enriched matching
$customerCity = $input['metadata']['cidade'] ?? '';
$customerState = $input['metadata']['uf'] ?? '';
$customerZip = preg_replace('/\D/', '', $input['metadata']['cep'] ?? '');

$fbUserData = array_filter([
    'em' => [hash('sha256', strtolower(trim($customer['email'] ?? '')))],
    'ph' => [hash('sha256', '55' . preg_replace('/\D/', '', $customer['phone'] ?? ''))],
    'fn' => [hash('sha256', strtolower(trim($firstName)))],
    'ln' => [hash('sha256', strtolower(trim($lastName)))],
    'ct' => $customerCity ? [hash('sha256', strtolower(trim($customerCity)))] : null,
    'st' => $customerState ? [hash('sha256', strtolower(trim($customerState)))] : null,
    'zp' => $customerZip ? [hash('sha256', $customerZip)] : null,
    'country' => [hash('sha256', 'br')],
    'external_id' => [hash('sha256', $paymentCode)],
    'client_ip_address' => $clientIp,
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'fbc' => $trackingParams['fbc'] ?? null,
    'fbp' => $trackingParams['fbp'] ?? null
], function($v) { return $v !== null && $v !== '' && $v !== []; });

// ── Build rich contents array ──
$fbContents = [];
foreach ($items as $item) {
    $fbContents[] = [
        'id' => $item['id'] ?? 'item',
        'quantity' => intval($item['quantity'] ?? 1),
        'item_price' => ($item['price'] ?? 0) / 100
    ];
}

// ── Facebook CAPI: AddPaymentInfo (PIX selected, server-side dedup partner) ──
$fbApiEventId = 'api_' . $paymentCode . '_' . time();
$fbAddPaymentInfo = ['data' => [[
    'event_name' => 'AddPaymentInfo',
    'event_id' => $fbApiEventId,
    'event_time' => time(),
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
$fbResult = apiPost($fbUrl, ['Content-Type: application/json'], $fbAddPaymentInfo);
writeApiEvent($paymentCode, 'AddPaymentInfo', 'facebook_capi', $fbUrl, $fbResult['status'],
    ['event_name' => 'AddPaymentInfo', 'value' => $amount / 100, 'event_id' => $fbApiEventId],
    $fbResult['body'] ?? $fbResult['raw'], $trackingParams, $fbResult['status'] === 200);
if ($fbResult['status'] !== 200) {
    writeLog('FB_CAPI_ERRO', ['evento' => 'AddPaymentInfo', 'payment_code' => $paymentCode, 'http_status' => $fbResult['status'], 'erro' => $fbResult['error'] ?: ($fbResult['raw'] ?? 'sem resposta')]);
}

// ── Facebook CAPI: GeneratePixCode (custom event) ──
$fbGpxEventId = 'gpx_' . $paymentCode . '_' . time();
$fbGeneratePix = ['data' => [[
    'event_name' => 'GeneratePixCode',
    'event_id' => $fbGpxEventId,
    'event_time' => time(),
    'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/',
    'action_source' => 'website',
    'user_data' => $fbUserData,
    'custom_data' => [
        'currency' => 'BRL', 'value' => $amount / 100,
        'payment_method' => 'pix', 'order_id' => $paymentCode,
        'gateway' => $usedGateway
    ]
]]];

$fbResult2 = apiPost($fbUrl, ['Content-Type: application/json'], $fbGeneratePix);
writeApiEvent($paymentCode, 'GeneratePixCode', 'facebook_capi', $fbUrl, $fbResult2['status'],
    ['event_name' => 'GeneratePixCode', 'value' => $amount / 100, 'event_id' => $fbGpxEventId],
    $fbResult2['body'] ?? $fbResult2['raw'], $trackingParams, $fbResult2['status'] === 200);

// ── TikTok Events API - AddPaymentInfo + PlaceAnOrder ──
$ttContents = array_map(function($item) {
    return [
        'content_id' => $item['id'] ?? 'item',
        'content_name' => $item['name'] ?? 'Produto',
        'content_type' => 'product',
        'quantity' => intval($item['quantity'] ?? 1),
        'price' => ($item['price'] ?? 0) / 100
    ];
}, $items);

$ttUserData = [
    'email' => hash('sha256', strtolower(trim($customer['email'] ?? ''))),
    'phone_number' => hash('sha256', '+55' . preg_replace('/\D/', '', $customer['phone'] ?? '')),
    'external_id' => hash('sha256', $paymentCode),
    'ip' => $clientIp,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ttclid' => $trackingParams['ttclid'] ?? null
];
$ttUserData = array_filter($ttUserData, function($v) { return $v !== null && $v !== ''; });

// AddPaymentInfo (standard event)
$ttApiResult = sendTikTokEvent('AddPaymentInfo', 'tt_api_' . $paymentCode . '_' . time(), $ttUserData,
    ['contents' => $ttContents, 'content_type' => 'product', 'currency' => 'BRL', 'value' => $amount / 100, 'description' => 'PIX']);
if ($ttApiResult) {
    writeApiEvent($paymentCode, 'AddPaymentInfo', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttApiResult['status'],
        ['event' => 'AddPaymentInfo', 'value' => $amount / 100],
        $ttApiResult['body'] ?? $ttApiResult['raw'], $trackingParams, $ttApiResult['status'] === 200);
}

// PlaceAnOrder (standard event - key for PIX intent signal, fires while user is still on page)
$ttOrderResult = sendTikTokEvent('PlaceAnOrder', 'tt_pao_' . $paymentCode . '_' . time(), $ttUserData,
    ['contents' => $ttContents, 'content_type' => 'product', 'currency' => 'BRL', 'value' => $amount / 100, 'order_id' => $paymentCode]);
if ($ttOrderResult) {
    writeApiEvent($paymentCode, 'PlaceAnOrder', 'tiktok_events', 'https://business-api.tiktok.com/open_api/v1.3/event/track/', $ttOrderResult['status'],
        ['event' => 'PlaceAnOrder', 'value' => $amount / 100],
        $ttOrderResult['body'] ?? $ttOrderResult['raw'], $trackingParams, $ttOrderResult['status'] === 200);
    if ($ttOrderResult['status'] !== 200) {
        writeLog('TIKTOK_API_ERRO', ['evento' => 'PlaceAnOrder', 'payment_code' => $paymentCode, 'http_status' => $ttOrderResult['status'], 'erro' => $ttOrderResult['error'] ?: ($ttOrderResult['raw'] ?? 'sem resposta')]);
    }
}

// Log
writeLog('PIX_GERADO', [
    'gateway' => $usedGateway, 'payment_code' => $paymentCode,
    'valor' => 'R$ ' . number_format($amount / 100, 2, ',', '.'),
    'nome' => $customer['name'] ?? '', 'email' => $customer['email'] ?? '',
    'telefone' => $customer['phone'] ?? '', 'fallback' => $usedFallback ? 'SIM' : 'NAO',
    'itens' => array_map(function($i) { return ($i['name'] ?? 'item') . ' x' . ($i['quantity'] ?? 1); }, $items),
    'ip' => $clientIp
]);

// Return success
echo json_encode([
    'success' => true,
    'payment_code' => $paymentCode,
    'external_code' => $externalCode,
    'pix_qrcode_text' => $pixQrcodeText,
    'amount' => $amount,
    'gateway' => $usedGateway,
    'expires_at' => $result['raw_response']['expires_at'] ?? $result['raw_response']['pix']['expirationDate'] ?? ''
]);
