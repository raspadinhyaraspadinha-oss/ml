<?php
/* ============================================
   API Configuration
   IMPORTANT: Update these values with your real credentials
   ============================================ */

// Mangofy
define('MANGOFY_API_URL', 'https://checkout.mangofy.com.br/api/v1');
define('MANGOFY_AUTHORIZATION', '2d7ec7be4856d113b6dea617d389cb711dlhqysglpgl6h8tiy3jd5lzc6tx2ei');
define('MANGOFY_STORE_CODE', '0d4e1ba5d97eba0bb822b05fae41df4b');

// UTMify
define('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_API_TOKEN', 'fKoyOKl8UkZN9Y5r0OMXRaXHEVtcUvps0qdP');

// Facebook Conversions API
define('FB_PIXEL_ID', '895868873361776');
define('FB_ACCESS_TOKEN', 'EAAMJ4lbAOXMBQy8xkl2eKvSBDOGbwOdwP0cwJS6CNZBWRKOOkbVtd47IzZCEkVZCLte9nFkR4hEwZCDymU8OPrca47jnprc43IOEG94YJdZC5XZAZCfFQAxFpvo8GZAyJNIi51Xnq2lCxZBoi0uJZBrRmX5MIrZBSq8PNEwNJCIjyiZA9NUxOiP7tEZA1gDKimLjTcQZDZD');
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
