<?php
/**
 * Monitor 503 - Endpoint leve para detectar se o site está engasgando
 *
 * Uso: Acesse /api/monitor-503.php para ver status
 *      Ou via painel: já integrado na aba Analytics
 *
 * Este arquivo é ULTRA LEVE: zero includes, zero file reads pesados
 * Retorna JSON com status do servidor em <1ms
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$status = [
    'ok'        => true,
    'timestamp' => date('c'),
    'server'    => php_uname('n'),
    'php_sapi'  => php_sapi_name(),
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

// Checar se rate limit file está grande (indicador de flood)
$rlFile = sys_get_temp_dir() . '/ml_ratelimit.json';
if (file_exists($rlFile)) {
    $rlData = @json_decode(@file_get_contents($rlFile), true) ?: [];
    $status['rate_limiter'] = [
        'tracked_ips'    => count($rlData),
        'file_size_kb'   => round(filesize($rlFile) / 1024, 1),
    ];
}

// Checar log de bloqueios do cloaker
$blockLog = __DIR__ . '/data/cloaker_blocks.log';
if (file_exists($blockLog)) {
    $size = filesize($blockLog);
    // Contar linhas das últimas horas (ler só o final do arquivo)
    $tail = '';
    if ($size > 0) {
        $fh = fopen($blockLog, 'r');
        fseek($fh, max(0, $size - 8192)); // últimos 8KB
        $tail = fread($fh, 8192);
        fclose($fh);
    }
    $recentLines = array_filter(explode("\n", $tail));
    $lastHour = 0;
    $cutoff = date('Y-m-d H:i:s', time() - 3600);
    foreach ($recentLines as $line) {
        if (substr($line, 0, 19) >= $cutoff) $lastHour++;
    }
    $status['cloaker_blocks'] = [
        'log_size_kb'     => round($size / 1024, 1),
        'blocks_last_hour' => $lastHour,
    ];
}

// Log 503 tracking
$log503 = __DIR__ . '/data/503_events.log';
if (file_exists($log503)) {
    $size = filesize($log503);
    $status['503_log'] = [
        'exists'      => true,
        'size_kb'     => round($size / 1024, 1),
    ];
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
