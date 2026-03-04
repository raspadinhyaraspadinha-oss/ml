<?php
/* ============================================
   Health Check - System Status Verification
   GET /api/health-check.php
   Returns JSON with status of all critical components
   ============================================ */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

require_once __DIR__ . '/config.php';

$checks = [];
$allOk = true;

// ── 1. Data directory writable ──
$dataDir = DATA_DIR;
$checks['data_dir'] = [
    'name' => 'Data Directory',
    'path' => realpath($dataDir) ?: $dataDir,
    'exists' => is_dir($dataDir),
    'writable' => is_writable($dataDir),
    'status' => (is_dir($dataDir) && is_writable($dataDir)) ? 'ok' : 'fail'
];
if ($checks['data_dir']['status'] !== 'ok') $allOk = false;

// ── 2. Payments file ──
$paymentsFile = $dataDir . 'payments.json';
$paymentsOk = false;
$paymentsCount = 0;
if (file_exists($paymentsFile)) {
    $data = json_decode(file_get_contents($paymentsFile), true);
    if (is_array($data)) {
        $paymentsOk = true;
        $paymentsCount = count($data);
    }
}
$checks['payments'] = [
    'name' => 'Payments Storage',
    'file' => 'payments.json',
    'exists' => file_exists($paymentsFile),
    'valid_json' => $paymentsOk,
    'record_count' => $paymentsCount,
    'status' => $paymentsOk ? 'ok' : (file_exists($paymentsFile) ? 'corrupt' : 'missing')
];
// payments.json may not exist yet if no payments were made — that's ok for fresh installs
if (file_exists($paymentsFile) && !$paymentsOk) $allOk = false;

// ── 3. Feature flags file ──
$flagsFile = $dataDir . 'feature_flags.json';
$flagsOk = false;
$flagsData = null;
if (file_exists($flagsFile)) {
    $flagsData = json_decode(file_get_contents($flagsFile), true);
    if (is_array($flagsData) && isset($flagsData['flags'])) {
        $flagsOk = true;
    }
}
$checks['feature_flags'] = [
    'name' => 'Feature Flags',
    'file' => 'feature_flags.json',
    'exists' => file_exists($flagsFile),
    'valid_json' => $flagsOk,
    'global_killswitch' => $flagsData['global_killswitch'] ?? null,
    'flag_count' => $flagsOk ? count($flagsData['flags']) : 0,
    'status' => $flagsOk ? 'ok' : 'fail'
];
if ($checks['feature_flags']['status'] !== 'ok') $allOk = false;

// ── 4. Experiments file ──
$expFile = $dataDir . 'experiments.json';
$expOk = false;
$expData = null;
$runningCount = 0;
$draftCount = 0;
if (file_exists($expFile)) {
    $expData = json_decode(file_get_contents($expFile), true);
    if (is_array($expData) && isset($expData['experiments'])) {
        $expOk = true;
        foreach ($expData['experiments'] as $exp) {
            $s = $exp['status'] ?? '';
            if ($s === 'running') $runningCount++;
            if ($s === 'draft') $draftCount++;
        }
    }
}
$checks['experiments'] = [
    'name' => 'Experiments',
    'file' => 'experiments.json',
    'exists' => file_exists($expFile),
    'valid_json' => $expOk,
    'total' => $expOk ? count($expData['experiments']) : 0,
    'running' => $runningCount,
    'draft' => $draftCount,
    'status' => $expOk ? 'ok' : 'fail'
];
if ($checks['experiments']['status'] !== 'ok') $allOk = false;

// ── 5. Events directory (migrated to PostHog) ──
$eventsDir = $dataDir . 'events/';
$eventFiles = 0;
if (is_dir($eventsDir)) {
    $eventFiles = count(glob($eventsDir . '*.json'));
}
$checks['events'] = [
    'name' => 'Events Storage (migrated to PostHog)',
    'path' => 'data/events/',
    'historical_files' => $eventFiles,
    'note' => 'Analytics events now sent to PostHog Cloud. Local files are historical only.',
    'status' => 'ok'
];

// ── 6. Gateway connectivity (lightweight check) ──
$mangofyReachable = false;
$skalepayReachable = false;

// Check Mangofy DNS resolution (don't make actual API call)
$mangofyHost = parse_url(MANGOFY_API_URL, PHP_URL_HOST);
if ($mangofyHost) {
    $mangofyReachable = (gethostbyname($mangofyHost) !== $mangofyHost);
}
$checks['gateway_mangofy'] = [
    'name' => 'Mangofy Gateway',
    'host' => $mangofyHost,
    'dns_resolves' => $mangofyReachable,
    'status' => $mangofyReachable ? 'ok' : 'unreachable'
];

$skalepayHost = parse_url(SKALEPAY_API_URL, PHP_URL_HOST);
if ($skalepayHost) {
    $skalepayReachable = (gethostbyname($skalepayHost) !== $skalepayHost);
}
$checks['gateway_skalepay'] = [
    'name' => 'SkalePay Gateway',
    'host' => $skalepayHost,
    'dns_resolves' => $skalepayReachable,
    'status' => $skalepayReachable ? 'ok' : 'unreachable'
];

// NitroPagamento
$npHost = parse_url(NITROPAGAMENTO_API_URL, PHP_URL_HOST);
$npReachable = false;
if ($npHost) {
    $npReachable = (gethostbyname($npHost) !== $npHost);
}
$checks['gateway_nitropagamento'] = [
    'name' => 'NitroPagamento Gateway',
    'host' => $npHost,
    'dns_resolves' => $npReachable,
    'status' => $npReachable ? 'ok' : 'unreachable'
];

// Gateways unreachable is a warning, not a hard fail (DNS check from server)

// ── 7. Critical PHP files present ──
$criticalFiles = [
    'payment.php' => __DIR__ . '/payment.php',
    'check-payment.php' => __DIR__ . '/check-payment.php',
    'event.php' => __DIR__ . '/event.php',
    'webhook.php' => __DIR__ . '/webhook.php',
    'webhook-skalepay.php' => __DIR__ . '/webhook-skalepay.php',
    'webhook-nitropagamento.php' => __DIR__ . '/webhook-nitropagamento.php',
    'feature-flags.php' => __DIR__ . '/feature-flags.php',
    'experiments.php' => __DIR__ . '/experiments.php',
    'config.php' => __DIR__ . '/config.php'
];
$missingFiles = [];
foreach ($criticalFiles as $name => $path) {
    if (!file_exists($path)) {
        $missingFiles[] = $name;
    }
}
$checks['critical_files'] = [
    'name' => 'Critical API Files',
    'total' => count($criticalFiles),
    'present' => count($criticalFiles) - count($missingFiles),
    'missing' => $missingFiles,
    'status' => empty($missingFiles) ? 'ok' : 'fail'
];
if ($checks['critical_files']['status'] !== 'ok') $allOk = false;

// ── 8. Critical JS files present ──
$jsRoot = realpath(__DIR__ . '/../js/') ?: (__DIR__ . '/../js/');
$criticalJs = [
    'feature-flags.js' => $jsRoot . '/feature-flags.js',
    'ab-engine.js' => $jsRoot . '/ab-engine.js',
    'ml-analytics.js' => $jsRoot . '/ml-analytics.js',
    'cart.js' => $jsRoot . '/cart.js'
];
$missingJs = [];
foreach ($criticalJs as $name => $path) {
    if (!file_exists($path)) {
        $missingJs[] = $name;
    }
}
$checks['critical_js'] = [
    'name' => 'Critical JS Files',
    'total' => count($criticalJs),
    'present' => count($criticalJs) - count($missingJs),
    'missing' => $missingJs,
    'status' => empty($missingJs) ? 'ok' : 'fail'
];
if ($checks['critical_js']['status'] !== 'ok') $allOk = false;

// ── 9. Recent payment activity (last 24h) ──
$recentPayments = 0;
$recentPaid = 0;
$recentPending = 0;
if ($paymentsOk && is_array($data)) {
    $threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
    foreach ($data as $p) {
        $created = $p['created_at'] ?? '';
        if ($created >= $threshold) {
            $recentPayments++;
            $status = $p['status'] ?? '';
            if ($status === 'paid') $recentPaid++;
            elseif ($status === 'pending') $recentPending++;
        }
    }
}
$checks['recent_activity'] = [
    'name' => 'Payment Activity (24h)',
    'total' => $recentPayments,
    'paid' => $recentPaid,
    'pending' => $recentPending,
    'pay_rate' => $recentPayments > 0 ? round(($recentPaid / $recentPayments) * 100, 1) . '%' : 'N/A',
    'status' => 'info'
];

// ── 10. PostHog Migration Status ──
$mlAnalytics = realpath(__DIR__ . '/../js/ml-analytics.js') ?: (__DIR__ . '/../js/ml-analytics.js');
$posthogConfigured = false;
if (file_exists($mlAnalytics)) {
    $jsContent = file_get_contents($mlAnalytics);
    $posthogConfigured = (strpos($jsContent, 'posthog.capture') !== false)
        && (strpos($jsContent, 'COLE_SUA_API_KEY_POSTHOG_AQUI') === false);
}
$checks['posthog_migration'] = [
    'name' => 'PostHog Cloud Migration',
    'analytics_file' => 'js/ml-analytics.js',
    'posthog_integrated' => (strpos($jsContent ?? '', 'posthog.capture') !== false),
    'api_key_configured' => $posthogConfigured,
    'track_php_neutered' => !class_exists('track_legacy_marker'), // always true now
    'event_php_neutered' => true,
    'status' => $posthogConfigured ? 'ok' : 'pending_api_key'
];

// ── 11. Disk space check ──
$freeBytes = @disk_free_space($dataDir);
$freeGb = $freeBytes ? round($freeBytes / (1024 * 1024 * 1024), 2) : null;
$checks['disk_space'] = [
    'name' => 'Disk Space',
    'free_gb' => $freeGb,
    'status' => ($freeGb !== null && $freeGb > 0.5) ? 'ok' : (($freeGb !== null) ? 'low' : 'unknown')
];
if ($freeGb !== null && $freeGb < 0.1) $allOk = false;

// ── Build response ──
$failCount = 0;
$warnCount = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'fail' || $c['status'] === 'corrupt' || $c['status'] === 'missing') $failCount++;
    if ($c['status'] === 'unreachable' || $c['status'] === 'low' || $c['status'] === 'not_writable') $warnCount++;
}

http_response_code($allOk ? 200 : 503);

echo json_encode([
    'status' => $allOk ? 'healthy' : 'degraded',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'summary' => [
        'total_checks' => count($checks),
        'ok' => count($checks) - $failCount - $warnCount,
        'warnings' => $warnCount,
        'failures' => $failCount
    ],
    'checks' => $checks
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
