<?php
/**
 * PostHog Data Sync - On-demand analytics fetcher
 * POST /api/sync-posthog.php
 * Body: { "days": 7 }
 *
 * Uses HogQL queries to fetch analytics from PostHog Cloud.
 * Caches locally so dashboard renders instantly.
 * Called manually via button click — ZERO server load normally.
 *
 * Requires: PostHog Personal API Key in config.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('{"error":"POST only"}'); }

// Auth: require dashboard session (same key used by painel/index.php)
if (!isset($_SESSION['painel_auth']) || $_SESSION['painel_auth'] !== true) {
    http_response_code(403);
    die('{"error":"Nao autenticado. Faca login no painel primeiro."}');
}

require_once __DIR__ . '/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$days = min(max(intval($input['days'] ?? 7), 1), 30);

$apiKey = POSTHOG_PERSONAL_KEY;
$projectId = POSTHOG_PROJECT_ID;
$host = POSTHOG_API_HOST;

if ($apiKey === 'phx_qJUVLfh0pwUtsLthMTb5Jcp9TO3vXy7WM2EAxAGV08ybWWI' || empty($apiKey)) {
    http_response_code(400);
    die('{"error":"PostHog Personal API Key nao configurada em config.php. Crie uma em PostHog > Settings > Personal API Keys."}');
}

$startTime = microtime(true);

// ── Helper: Run HogQL query ──
function hogql($host, $projectId, $apiKey, $query) {
    $url = "{$host}/api/projects/{$projectId}/query/";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'query' => ['kind' => 'HogQLQuery', 'query' => $query]
        ]),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => "cURL: {$curlError}"];
    }
    if ($httpCode !== 200) {
        $body = json_decode($response, true);
        $msg = $body['detail'] ?? $body['error'] ?? "HTTP {$httpCode}";
        return ['error' => $msg, 'http_code' => $httpCode];
    }
    return json_decode($response, true);
}

// ── Query 1: Analytics events (funnel_view, checkout_step, etc.) ──
$analyticsQuery = <<<HOGQL
SELECT
    event,
    properties.session_id,
    properties.page,
    toIntOrZero(toString(properties.funnel_stage)),
    properties.page_url,
    properties.page_referrer,
    properties.experiment_id,
    properties.variant_id,
    properties.\$ip,
    properties.\$user_agent,
    toString(timestamp),
    properties.client_timestamp,
    properties.utm_source,
    properties.utm_medium,
    properties.utm_campaign,
    properties.utm_content,
    properties.utm_term,
    properties.event_id,
    toString(properties.step),
    properties.step_name,
    properties.product_id,
    properties.product_name,
    toString(properties.value),
    properties.payment_code,
    properties.field,
    properties.message,
    toString(properties.num_items),
    properties.\$referrer,
    properties.\$current_url,
    properties.\$pathname
FROM events
WHERE timestamp >= now() - toIntervalDay({$days})
AND event NOT IN ('\$pageview', '\$pageleave', '\$autocapture', '\$feature_flag_called', '\$rageclick', '\$dead_click', '\$web_vitals')
ORDER BY timestamp DESC
LIMIT 50000
HOGQL;

$analyticsResult = hogql($host, $projectId, $apiKey, $analyticsQuery);

if (isset($analyticsResult['error'])) {
    http_response_code(502);
    die(json_encode(['error' => 'PostHog analytics query failed: ' . $analyticsResult['error']]));
}

// ── Query 2: Pageviews ──
$pageviewsQuery = <<<HOGQL
SELECT
    properties.\$pathname,
    properties.\$referrer,
    properties.utm_source,
    properties.utm_medium,
    properties.utm_campaign,
    properties.utm_content,
    properties.utm_term,
    properties.\$ip,
    properties.\$user_agent,
    toString(timestamp),
    properties.\$geoip_country_code,
    properties.session_id,
    properties.page,
    properties.\$current_url
FROM events
WHERE event = '\$pageview'
AND timestamp >= now() - toIntervalDay({$days})
ORDER BY timestamp DESC
LIMIT 50000
HOGQL;

$pageviewsResult = hogql($host, $projectId, $apiKey, $pageviewsQuery);

if (isset($pageviewsResult['error'])) {
    http_response_code(502);
    die(json_encode(['error' => 'PostHog pageviews query failed: ' . $pageviewsResult['error']]));
}

// ── Map analytics results to dashboard format ──
$analytics = [];
$rows = $analyticsResult['results'] ?? [];
foreach ($rows as $r) {
    // Build data object from event-specific properties
    $data = array_filter([
        'event_id'     => $r[17] ?? null,
        'step'         => $r[18] ?? null,
        'step_name'    => $r[19] ?? null,
        'product_id'   => $r[20] ?? null,
        'product_name' => $r[21] ?? null,
        'value'        => $r[22] ?? null,
        'payment_code' => $r[23] ?? null,
        'field'        => $r[24] ?? null,
        'message'      => $r[25] ?? null,
        'num_items'    => $r[26] ?? null,
    ], function($v) { return $v !== null && $v !== ''; });

    // Build UTMs object
    $utms = array_filter([
        'utm_source'   => $r[12] ?? null,
        'utm_medium'   => $r[13] ?? null,
        'utm_campaign' => $r[14] ?? null,
        'utm_content'  => $r[15] ?? null,
        'utm_term'     => $r[16] ?? null,
    ], function($v) { return $v !== null && $v !== ''; });

    $analytics[] = [
        'event'         => $r[0] ?? '',
        'session_id'    => $r[1] ?? '',
        'page'          => $r[2] ?? ($r[29] ?? ''),  // fallback to $pathname
        'funnel_stage'  => intval($r[3] ?? 0),
        'url'           => $r[4] ?? ($r[28] ?? ''),   // fallback to $current_url
        'referrer'      => $r[5] ?? ($r[27] ?? ''),   // fallback to $referrer
        'data'          => $data ?: new \stdClass(),
        'utms'          => $utms ?: new \stdClass(),
        'experiment_id' => $r[6] ?? '',
        'variant_id'    => $r[7] ?? '',
        'ip'            => $r[8] ?? '',
        'user_agent'    => $r[9] ?? '',
        'server_time'   => $r[10] ?? '',
        'client_time'   => $r[11] ?? '',
    ];
}

// ── Map pageview results to dashboard format ──
$pageviews = [];
$pvRows = $pageviewsResult['results'] ?? [];
foreach ($pvRows as $r) {
    $pageviews[] = [
        'page'         => $r[0] ?? '',
        'referrer'     => $r[1] ?? '',
        'utm_source'   => $r[2] ?? '',
        'utm_medium'   => $r[3] ?? '',
        'utm_campaign' => $r[4] ?? '',
        'utm_content'  => $r[5] ?? '',
        'utm_term'     => $r[6] ?? '',
        'ip'           => $r[7] ?? '',
        'user_agent'   => $r[8] ?? '',
        'timestamp'    => $r[9] ?? '',
        'country'      => $r[10] ?? '',
        'session_id'   => $r[11] ?? '',
        'page_name'    => $r[12] ?? '',
        'url'          => $r[13] ?? '',
    ];
}

// ── Save to cache files ──
$cacheDir = DATA_DIR;
file_put_contents($cacheDir . 'posthog_analytics.json',
    json_encode($analytics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($cacheDir . 'posthog_pageviews.json',
    json_encode($pageviews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

// Save sync metadata
$duration = round((microtime(true) - $startTime) * 1000);
$meta = [
    'last_sync'        => date('c'),
    'days'             => $days,
    'analytics_count'  => count($analytics),
    'pageviews_count'  => count($pageviews),
    'duration_ms'      => $duration,
];
file_put_contents($cacheDir . 'posthog_sync_meta.json',
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

echo json_encode([
    'success'          => true,
    'analytics_count'  => count($analytics),
    'pageviews_count'  => count($pageviews),
    'days'             => $days,
    'duration_ms'      => $duration,
]);
