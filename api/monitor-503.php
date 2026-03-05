<?php
/**
 * Monitor 503 - Endpoint leve para detectar se o site está engasgando
 *
 * Uso: Acesse /api/monitor-503.php para ver status
 *      Ou via painel: já integrado na aba Analytics
 *
 * Este arquivo é ULTRA LEVE: zero includes, zero file reads pesados
 * Retorna JSON com status do servidor em <1ms
 *
 * v2.0: Updated for .htaccess cloaker (ZERO PHP for landing pages)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$status = [
    'ok'        => true,
    'timestamp' => date('c'),
    'server'    => php_uname('n'),
    'php_sapi'  => php_sapi_name(),
    'architecture' => 'htaccess_redirect_v2', // Landing page = .htaccess 302, NOT PHP
];

// Checar load average (Linux)
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $status['load_avg'] = [
        '1min'  => round($load[0], 2),
        '5min'  => round($load[1], 2),
        '15min' => round($load[2], 2),
    ];
    // Alerta se load > 10 (Hostinger shared tem ~2-4 CPUs)
    if ($load[0] > 10) {
        $status['warning'] = 'HIGH_LOAD';
        $status['ok'] = false;
    }
}

// Checar memória PHP
$status['memory'] = [
    'used_mb'  => round(memory_get_usage(true) / 1048576, 1),
    'peak_mb'  => round(memory_get_peak_usage(true) / 1048576, 1),
    'limit'    => ini_get('memory_limit'),
];

// Checar APCu (in-memory rate limiting)
if (function_exists('apcu_enabled') && apcu_enabled()) {
    $info = apcu_cache_info(true);
    $status['apcu'] = [
        'enabled'     => true,
        'entries'     => $info['num_entries'] ?? 0,
        'memory_mb'   => round(($info['mem_size'] ?? 0) / 1048576, 1),
        'hits'        => $info['num_hits'] ?? 0,
        'misses'      => $info['num_misses'] ?? 0,
    ];
} else {
    $status['apcu'] = ['enabled' => false];
}

// Checar se data directory é gravável (necessário para payments, logs, etc.)
$dataDir = __DIR__ . '/data/';
$status['data_dir'] = [
    'exists'   => is_dir($dataDir),
    'writable' => is_writable($dataDir),
];

// Checar tamanho de payments.json (arquivo mais lido/escrito)
$paymentsFile = $dataDir . 'payments.json';
if (file_exists($paymentsFile)) {
    $status['payments_file'] = [
        'size_kb'   => round(filesize($paymentsFile) / 1024, 1),
        'modified'  => date('c', filemtime($paymentsFile)),
    ];
}

// Checar log.txt (indica se writeLog está escrevendo)
$logFile = $dataDir . 'log.txt';
if (file_exists($logFile)) {
    $status['log_file'] = [
        'size_kb'   => round(filesize($logFile) / 1024, 1),
        'modified'  => date('c', filemtime($logFile)),
    ];
}

// Resumo da arquitetura atual
$status['optimization_notes'] = [
    'landing_page' => '.htaccess 302 redirect (ZERO PHP)',
    'feature_flags' => 'LiteSpeed cached (5 min)',
    'experiments' => 'LiteSpeed cached (10 min)',
    'event_php' => 'neutered (zero I/O)',
    'track_php' => 'neutered (zero I/O)',
    'error_503' => 'JS redirect to VSL (no retry storm)',
];

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
