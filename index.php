<?php
/**
 * CLOAKER v4.0 — ZERO I/O Architecture
 *
 * CRITICAL FIX: Remove ALL file I/O from hot path.
 * Previous version caused LOCK_EX convoy (126 IPs competing for 1 file lock)
 * that pushed load average to 26+ and caused cascading 503 errors.
 *
 * This version:
 * - ZERO file reads on happy path
 * - ZERO file writes on happy path
 * - Bot detection = pure string operations (~0.05ms)
 * - Rate limiting = APCu only (in-memory), SKIP if no APCu
 * - GeoIP = disabled by default ($block_non_br = false)
 * - Total execution: <1ms per request
 * - Capacity: 40 workers × 100 req/s/worker = 4000+ req/s
 */

// ====================== CONFIGURAÇÕES ======================
$offer_url      = 'https://www.aniversariodomes.shop/vsl/';
$white_message  = 'Site em manutenção. Tente novamente em alguns instantes.';
$block_non_br   = false;    // false = libera todos (frouxo). true = bloqueia não-BR
$rate_limit_max = 30;       // máx requests por IP / 60s (SÓ funciona com APCu)
// =============================================================

// ── IP real (zero I/O) ──
$ip = '0.0.0.0';
foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
    if (!empty($_SERVER[$h])) {
        $candidate = trim(explode(',', $_SERVER[$h])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ip = $candidate;
            break;
        }
    }
}
if ($ip === '0.0.0.0') $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Bot detection (pure string ops, ZERO I/O) ──
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Sem UA ou UA muito curta = bot
if (empty($ua) || strlen($ua) < 20) {
    http_response_code(204);
    exit;
}

$ua_lower = strtolower($ua);

// PERMITIR crawlers de ads (essenciais para tracking de conversão)
$is_ad_bot = false;
foreach (['facebookexternalhit','facebot','whatsapp','twitterbot','telegrambot',
          'linkedinbot','pinterestbot','slackbot','discordbot','snapchat'] as $ab) {
    if (strpos($ua_lower, $ab) !== false) { $is_ad_bot = true; break; }
}

if (!$is_ad_bot) {
    // BLOQUEAR bots hostis (resposta 204 = mínima, sem body, sem I/O)
    foreach (['bot','crawl','spider','slurp','ahrefs','semrush','mj12','dotbot',
              'blexbot','petalbot','yandex','bytespider','gptbot','claudebot','ccbot',
              'dataforseo','sogou','baidu','megaindex','ltx71','nuclei','sqlmap',
              'nikto','masscan','zmeu','zgrab','hakai','tsunami','loic','w3af',
              'python-requests','python-urllib','java/','libwww','perl ','ruby/',
              'go-http','httpx','axios','node-fetch','scrapy','phantomjs',
              'headlesschrome','puppeteer','selenium','playwright','httrack',
              'wget','curl/','mechanize','winhttp'] as $bb) {
        if (strpos($ua_lower, $bb) !== false) {
            http_response_code(204);
            exit;
        }
    }

    // UA sem parênteses (browsers reais SEMPRE têm info de plataforma entre parênteses)
    if (strpos($ua, '(') === false) {
        http_response_code(204);
        exit;
    }
}

// ── Rate limiting (APCu ONLY — sem arquivo, sem LOCK_EX, sem lock convoy) ──
if (function_exists('apcu_enabled') && apcu_enabled()) {
    $rlKey = 'rl_' . $ip;
    $rlCount = apcu_fetch($rlKey);
    if ($rlCount === false) {
        apcu_store($rlKey, 1, 60);
    } else {
        apcu_inc($rlKey);
        if ($rlCount > $rate_limit_max) {
            header('HTTP/1.1 429 Too Many Requests');
            header('Retry-After: 60');
            header('Cache-Control: no-store');
            header('Content-Length: 0');
            exit;
        }
    }
}
// SEM APCu = sem rate limiting. Melhor que LOCK_EX convoy que derruba o server.

// ── GeoIP check (só se $block_non_br = true, DESLIGADO por padrão) ──
if ($block_non_br) {
    $country = 'XX';

    // 1) Extensão MaxMind nativa (se Hostinger tiver)
    if (class_exists('MaxMind\\Db\\Reader')) {
        try {
            $r = new \MaxMind\Db\Reader(__DIR__ . '/GeoLite2-Country.mmdb');
            $rec = $r->get($ip);
            $r->close();
            $country = $rec['country']['iso_code'] ?? 'XX';
        } catch (\Throwable $e) {}
    }

    // 2) Fallback: Accept-Language heuristic
    if ($country === 'XX') {
        $lang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if (strpos($lang, 'pt-br') !== false) $country = 'BR';
    }

    // Bloqueia SÓ se certeza que não é BR (XX = incerto = libera)
    if ($country !== 'BR' && $country !== 'XX') {
        http_response_code(204);
        exit;
    }
}

// ── LIBERADO — Redireciona preservando UTMs (ZERO I/O) ──
$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    header('Location: ' . $offer_url . '?' . $query, true, 302);
} else {
    header('Location: ' . $offer_url, true, 302);
}
exit;
