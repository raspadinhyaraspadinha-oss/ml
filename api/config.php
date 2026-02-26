<?php
/* ============================================
   API Configuration
   IMPORTANT: Update these values with your real credentials
   ============================================ */

// Mangofy
define('MANGOFY_API_URL', 'https://checkout.mangofy.com.br/api/v1');
define('MANGOFY_AUTHORIZATION', 'SEU_TOKEN_MANGOFY_AQUI');
define('MANGOFY_STORE_CODE', 'SEU_STORE_CODE_AQUI');

// UTMify
define('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_API_TOKEN', 'SEU_TOKEN_UTMIFY_AQUI');

// Facebook Conversions API
define('FB_PIXEL_ID', '25660827120250641');
define('FB_ACCESS_TOKEN', 'SEU_FB_ACCESS_TOKEN_AQUI');
define('FB_API_VERSION', 'v21.0');

// Data storage path
define('DATA_DIR', __DIR__ . '/data/');

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
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
