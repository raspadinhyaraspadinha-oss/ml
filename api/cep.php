<?php
/* ============================================
   CEP Proxy - Server-side ViaCEP lookup
   Eliminates CORS issues by proxying through our server
   GET /api/cep.php?cep=01001000
   ============================================ */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // Cache 24h — CEPs rarely change
header('Access-Control-Allow-Origin: *');

// Rate limit: máx 5 CEP lookups por segundo por IP (anti-flood)
$cepIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0';
$cepIp = trim(explode(',', $cepIp)[0]);
if (function_exists('apcu_fetch')) {
    $cepKey = 'cep_' . $cepIp;
    $cepCount = apcu_fetch($cepKey);
    if ($cepCount === false) { apcu_store($cepKey, 1, 2); }
    else { apcu_inc($cepKey); if ($cepCount > 10) { http_response_code(429); die('{"erro":true,"message":"Muitas consultas. Aguarde."}'); } }
}

$cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');

if (strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['erro' => true, 'message' => 'CEP deve ter 8 dígitos']);
    exit;
}

// Check local cache first (file-based, 7 days TTL)
$cacheDir = __DIR__ . '/data/cep_cache/';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . $cep . '.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
    echo file_get_contents($cacheFile);
    exit;
}

// Fetch from ViaCEP server-side (no CORS issues)
$url = 'https://viacep.com.br/ws/' . $cep . '/json/';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MLCheckout/1.0)'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    // ViaCEP failed — try BrasilAPI as fallback
    $fallbackUrl = 'https://brasilapi.com.br/api/cep/v2/' . $cep;

    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => $fallbackUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MLCheckout/1.0)'
    ]);

    $fallbackResponse = curl_exec($ch2);
    $fallbackCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($fallbackCode === 200 && $fallbackResponse) {
        $brasilData = json_decode($fallbackResponse, true);
        if ($brasilData && !isset($brasilData['errors'])) {
            // Normalize BrasilAPI response to ViaCEP format
            $normalized = json_encode([
                'cep' => $brasilData['cep'] ?? $cep,
                'logradouro' => $brasilData['street'] ?? '',
                'complemento' => '',
                'bairro' => $brasilData['neighborhood'] ?? '',
                'localidade' => $brasilData['city'] ?? '',
                'uf' => $brasilData['state'] ?? '',
                'source' => 'brasilapi'
            ], JSON_UNESCAPED_UNICODE);

            // Cache the normalized result
            @file_put_contents($cacheFile, $normalized);

            echo $normalized;
            exit;
        }
    }

    // Both APIs failed
    http_response_code(502);
    echo json_encode(['erro' => true, 'message' => 'CEP não encontrado ou serviço indisponível']);
    exit;
}

// ViaCEP succeeded — cache and return
@file_put_contents($cacheFile, $response);
echo $response;
