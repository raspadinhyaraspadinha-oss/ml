<?php
/* ============================================================
   PAINEL DE CONTROLE - Dashboard de Analytics
   Arquivo unico e auto-contido (PHP + CSS + JS)
   Compativel com Hostinger (PHP 8.x)
   ============================================================ */

// ── Configuracao ──────────────────────────────────────────────
$DASHBOARD_PASSWORD = 'ml2025'; // Altere aqui a senha do painel
$DATA_DIR = __DIR__ . '/../api/data/';
$ITEMS_PER_PAGE = 20;
$SESSION_NAME = 'painel_auth';
$COOKIE_LIFETIME = 86400; // 24h

// ── Autenticacao ──────────────────────────────────────────────
session_start();

// CSV Export handler (must run before any HTML output)
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_SESSION[$SESSION_NAME]) && $_SESSION[$SESSION_NAME] === true) {
    exportCSV($DATA_DIR, $_GET);
    exit;
}

// Login
if (isset($_POST['dashboard_password'])) {
    if ($_POST['dashboard_password'] === $DASHBOARD_PASSWORD) {
        $_SESSION[$SESSION_NAME] = true;
    } else {
        $loginError = true;
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION[$SESSION_NAME]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$isAuthenticated = isset($_SESSION[$SESSION_NAME]) && $_SESSION[$SESSION_NAME] === true;

// ── Funcoes de Dados ──────────────────────────────────────────
function loadPayments(string $dir): array {
    $file = $dir . 'payments.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function loadPageviews(string $dir): array {
    $file = $dir . 'pageviews.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function loadLog(string $dir, int $limit = 50): array {
    $file = $dir . 'log.txt';
    if (!file_exists($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $lines = array_reverse($lines);
    return array_slice($lines, 0, $limit);
}

function loadApiEvents(string $dir): array {
    $file = $dir . 'api_events.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function loadAnalyticsEvents(string $dir, int $days = 7): array {
    $eventsDir = $dir . 'events/';
    if (!is_dir($eventsDir)) return [];
    $all = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = $eventsDir . 'events_' . $date . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $all = array_merge($all, $data);
            }
        }
    }
    // Sort by server_time desc
    usort($all, function($a, $b) {
        return strcmp($b['server_time'] ?? '', $a['server_time'] ?? '');
    });
    return $all;
}

function formatBRL(int $cents): string {
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function sanitize(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function formatDateBR(?string $dt): string {
    if (!$dt) return '-';
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i');
    } catch (Exception $e) {
        return sanitize($dt);
    }
}

function exportCSV(string $dir, array $params): void {
    $payments = loadPayments($dir);
    $dateFrom = $params['date_from'] ?? null;
    $dateTo = $params['date_to'] ?? null;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendas_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'Codigo', 'Gateway', 'Status', 'Valor', 'Nome', 'Email', 'Telefone', 'Documento',
        'Produtos', 'UTM Source', 'UTM Campaign', 'UTM Medium', 'UTM Content',
        'Criado em', 'Pago em'
    ], ';');

    foreach ($payments as $p) {
        $createdDate = substr($p['created_at'] ?? '', 0, 10);
        if ($dateFrom && $createdDate < $dateFrom) continue;
        if ($dateTo && $createdDate > $dateTo) continue;

        $items = [];
        foreach (($p['items'] ?? []) as $item) {
            $items[] = ($item['name'] ?? 'Produto') . ' x' . ($item['quantity'] ?? 1);
        }

        fputcsv($out, [
            $p['payment_code'] ?? '',
            $p['gateway'] ?? 'mangofy',
            $p['status'] ?? '',
            number_format(($p['amount'] ?? 0) / 100, 2, ',', '.'),
            $p['customer']['name'] ?? '',
            $p['customer']['email'] ?? '',
            $p['customer']['phone'] ?? '',
            $p['customer']['document'] ?? '',
            implode(', ', $items),
            $p['tracking']['utm_source'] ?? '',
            $p['tracking']['utm_campaign'] ?? '',
            $p['tracking']['utm_medium'] ?? '',
            $p['tracking']['utm_content'] ?? '',
            $p['created_at'] ?? '',
            $p['paid_at'] ?? '',
        ], ';');
    }
    fclose($out);
}

// ── Carregar dados se autenticado ─────────────────────────────
$payments = [];
$pageviews = [];
$logLines = [];
$kpis = [];
$dailySales = [];
$dailyRevenue = [];
$trafficSources = [];
$topProducts = [];
$recentSales = [];

if ($isAuthenticated) {
    $payments = loadPayments($DATA_DIR);
    $pageviews = loadPageviews($DATA_DIR);
    $logLines = loadLog($DATA_DIR, 50);
    $apiEvents = loadApiEvents($DATA_DIR);
    $analyticsEvents = loadAnalyticsEvents($DATA_DIR, 14);

    // Gateway config
    $gwFile = $DATA_DIR . 'gateway_config.json';
    $activeGateway = 'mangofy';
    if (file_exists($gwFile)) {
        $gwCfg = json_decode(file_get_contents($gwFile), true);
        $activeGateway = $gwCfg['active_gateway'] ?? 'mangofy';
    }

    // Default date range: last 7 days
    $defaultFrom = date('Y-m-d', strtotime('-7 days'));
    $defaultTo = date('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Controle</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 14px; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #09090b;
            color: #e4e4e7;
            line-height: 1.6;
            min-height: 100vh;
        }
        a { color: #60a5fa; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Accent bar top ── */
        .accent-bar {
            height: 3px;
            background: linear-gradient(90deg, #3b82f6 0%, #8b5cf6 40%, #06b6d4 70%, #10b981 100%);
        }

        /* ── Login ── */
        .login-wrap {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 1rem;
            background: radial-gradient(ellipse at 50% 0%, #1e1b4b33 0%, transparent 60%);
        }
        .login-box {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 16px; padding: 2.5rem; width: 100%; max-width: 400px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .login-box h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: #fff; }
        .login-box p { color: #71717a; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .login-box input[type="password"] {
            width: 100%; padding: 0.75rem 1rem; background: #09090b;
            border: 1px solid #27272a; border-radius: 10px; color: #fff;
            font-size: 1rem; font-family: inherit; margin-bottom: 1rem;
            transition: border-color 0.2s;
        }
        .login-box input[type="password"]:focus {
            outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px #3b82f622;
        }
        .login-box button {
            width: 100%; padding: 0.75rem; background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff; border: none; border-radius: 10px;
            font-size: 1rem; font-weight: 600; cursor: pointer;
            font-family: inherit; transition: opacity 0.2s;
        }
        .login-box button:hover { opacity: 0.9; }
        .login-error {
            background: #7f1d1d44; border: 1px solid #991b1b;
            color: #fca5a5; padding: 0.5rem; border-radius: 8px;
            margin-bottom: 1rem; font-size: 0.85rem;
        }

        /* ── Dashboard Layout ── */
        .dashboard { max-width: 1440px; margin: 0 auto; padding: 1.5rem 1.5rem 3rem; }

        /* Header */
        .header {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;
            padding-bottom: 1rem;
        }
        .header h1 {
            font-size: 1.5rem; font-weight: 800; color: #fff;
            background: linear-gradient(135deg, #fff 60%, #a78bfa);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .header-actions span { font-size: 0.78rem; color: #52525b; }
        .btn {
            padding: 0.5rem 1rem; border-radius: 8px; border: none;
            font-family: inherit; font-size: 0.82rem; font-weight: 500;
            cursor: pointer; transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 0.4rem;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #27272a; color: #d4d4d8; border: 1px solid #3f3f46; }
        .btn-secondary:hover { background: #3f3f46; }
        .btn-danger { background: #450a0a; color: #fca5a5; border: 1px solid #7f1d1d; }
        .btn-danger:hover { background: #7f1d1d; }
        .btn-sm { padding: 0.35rem 0.75rem; font-size: 0.78rem; }

        /* ════════════════════════════════════════════
           GATEWAY SWITCHER - Prominent Card
           ════════════════════════════════════════════ */
        .gw-card {
            background: #18181b;
            border: 1px solid #27272a;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }
        .gw-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
        }
        .gw-card.gw-mangofy::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .gw-card.gw-skalepay::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .gw-card.gw-nitropagamento::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

        .gw-info { display: flex; align-items: center; gap: 1rem; }
        .gw-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; font-weight: 800;
        }
        .gw-mangofy .gw-icon { background: #10b98118; color: #34d399; }
        .gw-skalepay .gw-icon { background: #8b5cf618; color: #a78bfa; }
        .gw-nitropagamento .gw-icon { background: #f59e0b18; color: #fbbf24; }

        .gw-text-label { font-size: 0.72rem; color: #71717a; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
        .gw-text-name { font-size: 1.15rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem; }
        .gw-live-dot {
            width: 8px; height: 8px; border-radius: 50%;
            animation: gwPulse 2s infinite;
        }
        .gw-mangofy .gw-live-dot { background: #34d399; box-shadow: 0 0 8px #34d39966; }
        .gw-skalepay .gw-live-dot { background: #a78bfa; box-shadow: 0 0 8px #a78bfa66; }
        .gw-nitropagamento .gw-live-dot { background: #fbbf24; box-shadow: 0 0 8px #fbbf2466; }
        @keyframes gwPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .gw-toggle-group {
            display: flex; align-items: center; gap: 0.5rem;
            background: #09090b; border: 1px solid #27272a;
            border-radius: 10px; padding: 4px;
        }
        .gw-btn {
            padding: 0.5rem 1.25rem; border-radius: 8px; border: none;
            font-family: inherit; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: all 0.25s; color: #71717a;
            background: transparent; position: relative;
        }
        .gw-btn:hover:not(.gw-active) { color: #d4d4d8; background: #27272a; }
        .gw-btn.gw-active {
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .gw-btn.gw-active-mg { background: #059669; }
        .gw-btn.gw-active-sk { background: #7c3aed; }
        .gw-btn.gw-active-np { background: #d97706; }
        .gw-btn:disabled { opacity: 0.5; cursor: wait; }

        /* Filter Bar */
        .filter-bar {
            display: flex; align-items: center; gap: 0.75rem;
            flex-wrap: wrap; margin-bottom: 1.5rem;
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; padding: 0.85rem 1.25rem;
        }
        .filter-bar label { font-size: 0.82rem; font-weight: 500; color: #a1a1aa; }
        .filter-bar input[type="date"] {
            background: #09090b; border: 1px solid #3f3f46; color: #fff;
            padding: 0.45rem 0.75rem; border-radius: 8px; font-family: inherit;
            font-size: 0.82rem;
        }
        .filter-bar input[type="date"]:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px #3b82f622; }
        .filter-sep { color: #3f3f46; }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
            gap: 0.85rem; margin-bottom: 1.5rem;
        }
        .kpi-card {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; padding: 1.25rem;
            transition: border-color 0.25s, transform 0.25s, box-shadow 0.25s;
            position: relative; overflow: hidden;
        }
        .kpi-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
        }
        .kpi-card:nth-child(1)::before { background: #3b82f6; }
        .kpi-card:nth-child(2)::before { background: #fbbf24; }
        .kpi-card:nth-child(3)::before { background: #34d399; }
        .kpi-card:nth-child(4)::before { background: #a78bfa; }
        .kpi-card:nth-child(5)::before { background: #34d399; }
        .kpi-card:nth-child(6)::before { background: #f472b6; }
        .kpi-card:hover { border-color: #3f3f46; transform: translateY(-2px); box-shadow: 0 8px 25px -5px rgba(0,0,0,0.3); }
        .kpi-label { font-size: 0.75rem; color: #71717a; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; margin-bottom: 0.5rem; }
        .kpi-value { font-size: 1.7rem; font-weight: 700; color: #fff; }
        .kpi-value.green { color: #34d399; }
        .kpi-value.blue { color: #60a5fa; }
        .kpi-value.yellow { color: #fbbf24; }
        .kpi-value.purple { color: #a78bfa; }

        /* Section Titles */
        .section-title {
            font-size: 1.05rem; font-weight: 600; color: #fff;
            margin-bottom: 1rem; margin-top: 2rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .section-title .dot { width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 0.85rem; margin-bottom: 1.5rem;
        }
        .chart-card {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; padding: 1.25rem;
        }
        .chart-card h3 { font-size: 0.9rem; font-weight: 600; color: #d4d4d8; margin-bottom: 1rem; }
        .chart-card canvas { width: 100% !important; height: 220px !important; }

        /* Tables */
        .table-wrap {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; overflow: hidden; margin-bottom: 1.5rem;
        }
        .table-header {
            padding: 1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #27272a;
        }
        .table-header h3 { font-size: 0.9rem; font-weight: 600; color: #d4d4d8; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 0.7rem 1rem; font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: #71717a; background: #111113;
            border-bottom: 1px solid #27272a; text-align: left;
            white-space: nowrap; cursor: pointer; user-select: none;
            transition: color 0.2s;
        }
        thead th:hover { color: #d4d4d8; }
        thead th.sorted-asc::after { content: ' \25B2'; font-size: 0.65rem; }
        thead th.sorted-desc::after { content: ' \25BC'; font-size: 0.65rem; }
        tbody td {
            padding: 0.6rem 1rem; font-size: 0.82rem;
            border-bottom: 1px solid #1c1c1f; white-space: nowrap;
        }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #1c1c1f; }

        /* Status Badges */
        .badge {
            display: inline-block; padding: 0.2rem 0.65rem;
            border-radius: 20px; font-size: 0.7rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .badge-paid { background: #064e3b; color: #34d399; }
        .badge-pending { background: #78350f44; color: #fbbf24; }
        .badge-failed { background: #450a0a44; color: #f87171; }
        .badge-mg { background: #05966920; color: #34d399; }
        .badge-sk { background: #7c3aed20; color: #a78bfa; }
        .badge-np { background: #d9770620; color: #fbbf24; }

        /* Event Log */
        .log-container {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; overflow: hidden; margin-bottom: 1.5rem;
        }
        .log-header {
            padding: 1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #27272a; flex-wrap: wrap; gap: 0.75rem;
        }
        .log-header h3 { font-size: 0.9rem; font-weight: 600; color: #d4d4d8; }
        .log-body { max-height: 400px; overflow-y: auto; padding: 0.5rem 0; }
        .log-line {
            padding: 0.35rem 1.25rem; font-size: 0.75rem;
            font-family: 'Courier New', monospace;
            border-bottom: 1px solid #1c1c1f;
            word-break: break-all; line-height: 1.5;
        }
        .log-line.pix { color: #60a5fa; }
        .log-line.webhook { color: #fbbf24; }
        .log-line.aprovado { color: #34d399; }
        .log-line.skalepay { color: #a78bfa; }
        .log-line.nitropagamento { color: #fbbf24; }
        .log-line.gateway { color: #f472b6; }
        .log-line.erro { color: #f87171; }

        /* Pagination */
        .pagination {
            display: flex; align-items: center; justify-content: center;
            gap: 0.35rem; padding: 1rem;
        }
        .pagination button {
            padding: 0.35rem 0.75rem; border-radius: 6px;
            border: 1px solid #3f3f46; background: #18181b;
            color: #d4d4d8; cursor: pointer; font-family: inherit;
            font-size: 0.78rem; transition: all 0.2s;
        }
        .pagination button:hover { background: #3f3f46; }
        .pagination button.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
        .pagination button:disabled { opacity: 0.3; cursor: not-allowed; }

        /* Settings */
        .settings-bar {
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 2rem;
        }
        .toggle-label {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.82rem; color: #a1a1aa; cursor: pointer;
        }
        .toggle-switch {
            position: relative; width: 40px; height: 22px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: #3f3f46; border-radius: 22px; transition: 0.3s; cursor: pointer;
        }
        .toggle-slider::before {
            content: ''; position: absolute; height: 16px; width: 16px;
            left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
            transition: 0.3s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #3b82f6; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }

        /* Responsive */
        @media (max-width: 768px) {
            html { font-size: 13px; }
            .dashboard { padding: 1rem; }
            .header { flex-direction: column; align-items: flex-start; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .gw-card { flex-direction: column; align-items: stretch; text-align: center; }
            .gw-info { justify-content: center; }
            .gw-toggle-group { justify-content: center; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #09090b; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #52525b; }

        /* Pie chart container */
        .pie-legend { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
        .pie-legend-item { display: flex; align-items: center; gap: 0.3rem; font-size: 0.73rem; color: #a1a1aa; }
        .pie-legend-color { width: 10px; height: 10px; border-radius: 3px; }

        /* No data */
        .no-data { text-align: center; padding: 3rem; color: #52525b; font-size: 0.88rem; }

        /* Log Filters */
        .log-filters { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .log-filter-btn {
            padding: 0.28rem 0.7rem; border-radius: 20px;
            border: 1px solid #3f3f46; background: transparent;
            color: #a1a1aa; font-family: inherit; font-size: 0.73rem;
            font-weight: 500; cursor: pointer; transition: all 0.2s;
        }
        .log-filter-btn:hover { border-color: #60a5fa; color: #60a5fa; }
        .log-filter-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }

        /* API Badges */
        .api-badge {
            display: inline-block; padding: 0.15rem 0.5rem;
            border-radius: 4px; font-size: 0.68rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .api-badge.utmify { background: #1e3a5f; color: #60a5fa; }
        .api-badge.facebook { background: #2e1065; color: #a78bfa; }
        .api-badge.tiktok { background: #4a044e; color: #f0abfc; }
        .api-badge.mangofy { background: #365314; color: #a3e635; }
        .status-dot {
            display: inline-block; width: 8px; height: 8px;
            border-radius: 50%; margin-right: 0.3rem; vertical-align: middle;
        }
        .status-dot.success { background: #34d399; }
        .status-dot.error { background: #f87171; }

        /* Clickable row */
        .clickable-row { cursor: pointer; }
        .clickable-row:hover { background: #1f1f23 !important; }

        /* Detail Button */
        .btn-detail {
            padding: 0.25rem 0.6rem; border-radius: 6px;
            border: 1px solid #3f3f46; background: #18181b;
            color: #60a5fa; font-size: 0.72rem; cursor: pointer;
            font-family: inherit; transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-detail:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }

        /* Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8); backdrop-filter: blur(4px);
            z-index: 1000;
            display: flex; align-items: flex-start; justify-content: center;
            padding: 2rem; overflow-y: auto;
            opacity: 0; pointer-events: none; transition: opacity 0.25s;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-content {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 16px; width: 100%; max-width: 840px;
            margin-top: 1rem; margin-bottom: 2rem;
            animation: modalIn 0.3s ease-out;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        @keyframes modalIn {
            from { transform: translateY(20px) scale(0.98); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.25rem 1.5rem; border-bottom: 1px solid #27272a;
        }
        .modal-header h2 { font-size: 1.1rem; font-weight: 600; color: #fff; }
        .modal-close {
            background: none; border: none; color: #71717a;
            font-size: 1.5rem; cursor: pointer; padding: 0.25rem 0.5rem;
            line-height: 1; transition: color 0.2s; border-radius: 6px;
        }
        .modal-close:hover { color: #fff; background: #27272a; }
        .modal-body { padding: 1.5rem; }

        /* Detail Cards */
        .detail-grid {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 1rem; margin-bottom: 1.5rem;
        }
        @media (max-width: 600px) { .detail-grid { grid-template-columns: 1fr; } }
        .detail-card {
            background: #09090b; border: 1px solid #27272a;
            border-radius: 10px; padding: 1rem;
        }
        .detail-card h4 {
            font-size: 0.75rem; font-weight: 600; color: #71717a;
            text-transform: uppercase; letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        .detail-row {
            display: flex; justify-content: space-between; gap: 1rem;
            padding: 0.3rem 0; font-size: 0.82rem;
        }
        .detail-row .label { color: #a1a1aa; white-space: nowrap; }
        .detail-row .value { color: #fff; font-weight: 500; text-align: right; word-break: break-all; }

        /* API Trail */
        .api-trail-title {
            font-size: 0.95rem; font-weight: 600; color: #fff;
            margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .api-trail-item {
            background: #09090b; border: 1px solid #27272a;
            border-radius: 10px; margin-bottom: 0.75rem; overflow: hidden;
        }
        .api-trail-header {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1rem; cursor: pointer;
            transition: background 0.15s; flex-wrap: wrap;
        }
        .api-trail-header:hover { background: #111113; }
        .api-trail-header .time { font-size: 0.73rem; color: #71717a; min-width: 110px; }
        .api-trail-header .event-name { font-weight: 600; color: #d4d4d8; font-size: 0.82rem; }
        .api-trail-header .http-status {
            margin-left: auto; padding: 0.15rem 0.5rem;
            border-radius: 4px; font-size: 0.7rem; font-weight: 600;
        }
        .api-trail-header .expand-icon { color: #52525b; font-size: 0.73rem; transition: transform 0.2s; }
        .http-ok { background: #064e3b; color: #34d399; }
        .http-err { background: #7f1d1d; color: #fca5a5; }
        .api-trail-body {
            display: none; padding: 0.75rem 1rem;
            border-top: 1px solid #27272a; font-size: 0.78rem;
        }
        .api-trail-body.open { display: block; }
        .response-box {
            background: #050506; border: 1px solid #1c1c1f;
            border-radius: 8px; padding: 0.75rem; margin-top: 0.25rem;
            font-family: 'Courier New', monospace; font-size: 0.72rem;
            color: #a1a1aa; max-height: 200px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-all; line-height: 1.4;
        }
        .response-label {
            font-size: 0.7rem; font-weight: 600; color: #52525b;
            text-transform: uppercase; margin-top: 0.75rem; margin-bottom: 0.2rem;
            letter-spacing: 0.04em;
        }
        .no-api-events { text-align: center; padding: 2rem; color: #52525b; font-size: 0.85rem; }

        /* Mark as Paid button */
        .btn-mark-paid {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.5rem 1.25rem; border-radius: 8px; border: none;
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff; font-family: inherit; font-size: 0.85rem;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
            margin-top: 1rem;
        }
        .btn-mark-paid:hover { opacity: 0.9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }
        .btn-mark-paid:disabled { opacity: 0.5; cursor: wait; transform: none; box-shadow: none; }
        .btn-mark-paid.refire {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }
        .btn-mark-paid.refire:hover { box-shadow: 0 4px 12px rgba(59,130,246,0.3); }

        /* API filter tabs */
        .api-filters { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .api-filter-btn {
            padding: 0.28rem 0.7rem; border-radius: 20px;
            border: 1px solid #3f3f46; background: transparent;
            color: #a1a1aa; font-family: inherit; font-size: 0.73rem;
            font-weight: 500; cursor: pointer; transition: all 0.2s;
        }
        .api-filter-btn:hover { border-color: #60a5fa; color: #60a5fa; }
        .api-filter-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }

        /* Toast notification */
        .toast {
            position: fixed; bottom: 2rem; right: 2rem; z-index: 2000;
            padding: 0.85rem 1.5rem; border-radius: 10px; font-size: 0.88rem; font-weight: 500;
            transform: translateY(100px); opacity: 0;
            transition: all 0.35s ease; pointer-events: none;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4);
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast-success { background: #065f46; color: #34d399; border: 1px solid #10b981; }
        .toast-error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }

        /* ════════════════════════════════════════
           TAB NAVIGATION
           ════════════════════════════════════════ */
        .dash-tabs {
            display: flex; gap: 0.5rem; margin-bottom: 1.5rem;
            border-bottom: 1px solid #27272a; padding-bottom: 0;
        }
        .dash-tab {
            padding: 0.65rem 1.25rem; background: transparent; border: none;
            color: #71717a; font-family: inherit; font-size: 0.88rem; font-weight: 600;
            cursor: pointer; border-bottom: 2px solid transparent;
            transition: all 0.2s; position: relative; bottom: -1px;
        }
        .dash-tab:hover { color: #d4d4d8; }
        .dash-tab.active { color: #fff; border-bottom-color: #3b82f6; }
        .dash-tab-panel { display: none; }
        .dash-tab-panel.active { display: block; }

        /* ════════════════════════════════════════
           ANALYTICS TAB
           ════════════════════════════════════════ */
        .funnel-viz {
            display: flex; align-items: flex-end; gap: 2px;
            padding: 1.5rem 0; justify-content: center;
            flex-wrap: wrap;
        }
        .funnel-bar-wrap {
            display: flex; flex-direction: column; align-items: center;
            min-width: 80px; flex: 1; max-width: 140px;
        }
        .funnel-bar {
            width: 100%; border-radius: 6px 6px 0 0;
            transition: height 0.5s ease;
            min-height: 4px;
        }
        .funnel-label {
            font-size: 0.68rem; color: #a1a1aa; margin-top: 0.4rem;
            text-align: center; font-weight: 500;
        }
        .funnel-count {
            font-size: 0.85rem; color: #fff; font-weight: 700;
            margin-top: 0.15rem; text-align: center;
        }
        .funnel-pct {
            font-size: 0.68rem; color: #71717a; text-align: center;
        }
        .funnel-arrow {
            color: #3f3f46; font-size: 1.2rem; padding-bottom: 2.5rem;
            flex-shrink: 0;
        }

        .analytics-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .analytics-card {
            background: #18181b; border: 1px solid #27272a;
            border-radius: 12px; padding: 1.25rem;
        }
        .analytics-card h3 {
            font-size: 0.85rem; color: #d4d4d8; font-weight: 600;
            margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .analytics-card h3 .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .analytics-stat-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.4rem 0; border-bottom: 1px solid #1f1f23;
        }
        .analytics-stat-row:last-child { border-bottom: none; }
        .analytics-stat-label { font-size: 0.78rem; color: #a1a1aa; }
        .analytics-stat-value { font-size: 0.85rem; color: #fff; font-weight: 600; }
        .analytics-stat-value.good { color: #34d399; }
        .analytics-stat-value.warn { color: #fbbf24; }
        .analytics-stat-value.bad { color: #f87171; }

        .pix-bar-wrap {
            display: flex; align-items: center; gap: 0.5rem;
            margin: 0.3rem 0;
        }
        .pix-bar-label { font-size: 0.72rem; color: #a1a1aa; min-width: 50px; }
        .pix-bar-bg {
            flex: 1; height: 20px; background: #27272a; border-radius: 4px; overflow: hidden;
            position: relative;
        }
        .pix-bar-fill {
            height: 100%; border-radius: 4px; transition: width 0.5s ease;
        }
        .pix-bar-value {
            font-size: 0.72rem; color: #fff; font-weight: 600; min-width: 45px; text-align: right;
        }

        .session-timeline {
            max-height: 400px; overflow-y: auto;
        }
        .session-row {
            display: flex; align-items: flex-start; gap: 0.75rem;
            padding: 0.5rem 0; border-bottom: 1px solid #1f1f23;
            font-size: 0.78rem;
        }
        .session-time { color: #71717a; min-width: 60px; font-variant-numeric: tabular-nums; }
        .session-event {
            padding: 0.15rem 0.5rem; border-radius: 4px; font-weight: 600;
            font-size: 0.72rem; white-space: nowrap;
        }
        .session-event.view { background: #1e3a5f; color: #60a5fa; }
        .session-event.action { background: #1a3a2a; color: #34d399; }
        .session-event.checkout { background: #3b2f00; color: #fbbf24; }
        .session-event.pix { background: #3b1f3b; color: #d8b4fe; }
        .session-event.error { background: #450a0a; color: #fca5a5; }
        .session-page { color: #a1a1aa; flex: 1; }
        .session-data { color: #71717a; font-size: 0.72rem; }

        .form-err-list { max-height: 250px; overflow-y: auto; }
        .form-err-item {
            display: flex; justify-content: space-between; padding: 0.4rem 0;
            border-bottom: 1px solid #1f1f23; font-size: 0.78rem;
        }
        .form-err-field { color: #f87171; font-weight: 600; }
        .form-err-msg { color: #a1a1aa; }
        .form-err-count { color: #fff; font-weight: 700; min-width: 35px; text-align: right; }
    </style>
</head>
<body>
<div class="accent-bar"></div>
<?php if (!$isAuthenticated): ?>
    <!-- ── Login Screen ── -->
    <div class="login-wrap">
        <form class="login-box" method="POST">
            <h1>Painel de Controle</h1>
            <p>Insira a senha para acessar o dashboard</p>
            <?php if (!empty($loginError)): ?>
                <div class="login-error">Senha incorreta. Tente novamente.</div>
            <?php endif; ?>
            <input type="password" name="dashboard_password" placeholder="Senha" autofocus required>
            <button type="submit">Entrar</button>
        </form>
    </div>
<?php else: ?>
    <!-- ── Dashboard ── -->
    <div class="dashboard">
        <!-- Header -->
        <div class="header">
            <h1>Painel de Controle</h1>
            <div class="header-actions">
                <span id="lastUpdated">Atualizado: <?php echo date('d/m/Y H:i:s'); ?></span>
                <button class="btn btn-secondary btn-sm" onclick="location.reload()">Atualizar</button>
                <a href="?logout=1" class="btn btn-danger btn-sm">Sair</a>
            </div>
        </div>

        <!-- ═══════════════════════════════════════
             TAB NAVIGATION
             ═══════════════════════════════════════ -->
        <div class="dash-tabs">
            <button class="dash-tab active" data-tab="vendas" onclick="switchDashTab('vendas')">Vendas</button>
            <button class="dash-tab" data-tab="tiktok" onclick="switchDashTab('tiktok')">TikTok</button>
            <button class="dash-tab" data-tab="analytics" onclick="switchDashTab('analytics')">Analytics / Funil</button>
            <button class="dash-tab" data-tab="cro" onclick="switchDashTab('cro')">CRO / Audit</button>
            <button class="dash-tab" data-tab="experiments" onclick="switchDashTab('experiments')">Experimentos</button>
            <button class="dash-tab" data-tab="flags" onclick="switchDashTab('flags')">⚙ Flags</button>
        </div>

        <!-- ═══════════════════════════════════════
             TAB: VENDAS (existing dashboard)
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel active" id="tab-vendas">

        <!-- ═══════════════════════════════════════
             GATEWAY SWITCHER - Prominent Card
             ═══════════════════════════════════════ -->
        <div class="gw-card gw-<?php echo $activeGateway; ?>" id="gwCard">
            <div class="gw-info">
                <div class="gw-icon" id="gwIcon"><?php echo $activeGateway === 'skalepay' ? 'SK' : ($activeGateway === 'nitropagamento' ? 'NP' : 'MG'); ?></div>
                <div>
                    <div class="gw-text-label">Gateway Ativo</div>
                    <div class="gw-text-name" id="gwName">
                        <span class="gw-live-dot"></span>
                        <?php echo $activeGateway === 'skalepay' ? 'SkalePay' : ($activeGateway === 'nitropagamento' ? 'NitroPagamento' : 'Mangofy'); ?>
                    </div>
                </div>
            </div>
            <div class="gw-toggle-group">
                <button class="gw-btn<?php echo $activeGateway === 'mangofy' ? ' gw-active gw-active-mg' : ''; ?>" data-gw="mangofy" onclick="switchGateway('mangofy')">Mangofy</button>
                <button class="gw-btn<?php echo $activeGateway === 'skalepay' ? ' gw-active gw-active-sk' : ''; ?>" data-gw="skalepay" onclick="switchGateway('skalepay')">SkalePay</button>
                <button class="gw-btn<?php echo $activeGateway === 'nitropagamento' ? ' gw-active gw-active-np' : ''; ?>" data-gw="nitropagamento" onclick="switchGateway('nitropagamento')">NitroPag</button>
            </div>
        </div>

        <!-- Date Filter -->
        <div class="filter-bar">
            <label>Periodo:</label>
            <input type="date" id="dateFrom" value="<?php echo $defaultFrom; ?>">
            <span class="filter-sep">ate</span>
            <input type="date" id="dateTo" value="<?php echo $defaultTo; ?>">
            <button class="btn btn-primary btn-sm" onclick="applyFilters()">Filtrar</button>
            <button class="btn btn-secondary btn-sm" onclick="resetFilters()">Limpar</button>
            <label style="margin-left:0.5rem">Status:</label>
            <select id="statusFilter" style="background:#09090b;border:1px solid #3f3f46;color:#fff;padding:0.4rem 0.6rem;border-radius:8px;font-family:inherit;font-size:0.82rem" onchange="applyFilters()">
                <option value="all">Todos</option>
                <option value="paid">Pagos</option>
                <option value="pending">Pendentes</option>
                <option value="failed">Falhas</option>
            </select>
            <div style="flex:1"></div>
            <button class="btn btn-secondary btn-sm" onclick="exportCSV()">Exportar CSV</button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Total Acessos</div>
                <div class="kpi-value blue" id="kpiAcessos">-</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">PIX Gerados</div>
                <div class="kpi-value yellow" id="kpiPix">-</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Vendas Aprovadas</div>
                <div class="kpi-value green" id="kpiVendas">-</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Taxa de Conversao</div>
                <div class="kpi-value purple" id="kpiCvr">-</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Receita Total</div>
                <div class="kpi-value green" id="kpiReceita">-</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Ticket Medio</div>
                <div class="kpi-value" id="kpiTicket">-</div>
            </div>
            <div class="kpi-card" style="border-top:2px solid #ef4444">
                <div class="kpi-label">Falhas Gateway</div>
                <div class="kpi-value" style="color:#f87171" id="kpiFalhas">0</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="section-title"><span class="dot"></span> Graficos</div>
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Vendas por Dia</h3>
                <canvas id="chartSales"></canvas>
            </div>
            <div class="chart-card">
                <h3>Receita por Dia (R$)</h3>
                <canvas id="chartRevenue"></canvas>
            </div>
            <div class="chart-card">
                <h3>Fontes de Trafego</h3>
                <canvas id="chartTraffic"></canvas>
                <div class="pie-legend" id="pieLegend"></div>
            </div>
        </div>

        <!-- Traffic Sources Table -->
        <div class="section-title"><span class="dot"></span> Fontes de Trafego</div>
        <div class="table-wrap">
            <div class="table-header">
                <h3>Performance por Campanha</h3>
            </div>
            <div class="table-scroll">
                <table id="trafficTable">
                    <thead>
                        <tr>
                            <th data-col="source" data-type="string">Fonte</th>
                            <th data-col="campaign" data-type="string">Campanha</th>
                            <th data-col="medium" data-type="string">Conjunto</th>
                            <th data-col="content" data-type="string">Anuncio</th>
                            <th data-col="views" data-type="number">Acessos</th>
                            <th data-col="pix" data-type="number">PIX Gerados</th>
                            <th data-col="sales" data-type="number">Vendas</th>
                            <th data-col="revenue" data-type="number">Receita</th>
                            <th data-col="cvr" data-type="number">CVR%</th>
                        </tr>
                    </thead>
                    <tbody id="trafficBody"></tbody>
                </table>
            </div>
            <div class="pagination" id="trafficPagination"></div>
        </div>

        <!-- Top Products Table -->
        <div class="section-title"><span class="dot"></span> Produtos</div>
        <div class="table-wrap">
            <div class="table-header">
                <h3>Top Produtos</h3>
            </div>
            <div class="table-scroll">
                <table id="productsTable">
                    <thead>
                        <tr>
                            <th data-col="name" data-type="string">Produto</th>
                            <th data-col="cart" data-type="number">Adicionados</th>
                            <th data-col="sold" data-type="number">Vendidos</th>
                            <th data-col="revenue" data-type="number">Receita</th>
                        </tr>
                    </thead>
                    <tbody id="productsBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Recent Sales Table -->
        <div class="section-title"><span class="dot"></span> Vendas Recentes</div>
        <div class="table-wrap">
            <div class="table-header">
                <h3>Ultimas Transacoes</h3>
            </div>
            <div class="table-scroll">
                <table id="salesTable">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>Produtos</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Gateway</th>
                            <th>Fonte</th>
                            <th>Campanha</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody id="salesBody"></tbody>
                </table>
            </div>
            <div class="pagination" id="salesPagination"></div>
        </div>

        <!-- Event Log -->
        <div class="section-title"><span class="dot"></span> Log de Eventos</div>
        <div class="log-container">
            <div class="log-header">
                <h3>Ultimos 50 eventos</h3>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                    <div class="log-filters">
                        <button class="log-filter-btn active" data-log-filter="all" onclick="filterLog('all')">Todos</button>
                        <button class="log-filter-btn" data-log-filter="pix" onclick="filterLog('pix')">PIX</button>
                        <button class="log-filter-btn" data-log-filter="webhook" onclick="filterLog('webhook')">Webhook</button>
                        <button class="log-filter-btn" data-log-filter="aprovado" onclick="filterLog('aprovado')">Aprovados</button>
                        <button class="log-filter-btn" data-log-filter="skalepay" onclick="filterLog('skalepay')">SkalePay</button>
                        <button class="log-filter-btn" data-log-filter="nitropagamento" onclick="filterLog('nitropagamento')">NitroPag</button>
                        <button class="log-filter-btn" data-log-filter="gateway" onclick="filterLog('gateway')">Gateway</button>
                        <button class="log-filter-btn" data-log-filter="erro" onclick="filterLog('erro')">Erros</button>
                        <button class="log-filter-btn" data-log-filter="api" onclick="filterLog('api')">API</button>
                    </div>
                    <label class="toggle-label">
                        <span>Auto-refresh</span>
                        <div class="toggle-switch">
                            <input type="checkbox" id="toggleLogRefresh">
                            <span class="toggle-slider"></span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="log-body" id="logBody">
                <?php foreach ($logLines as $line): ?>
                    <?php
                        $cls = '';
                        if (strpos($line, 'PIX_GERADO') !== false) $cls = 'pix';
                        elseif (strpos($line, 'WEBHOOK_RECEBIDO') !== false) $cls = 'webhook';
                        elseif (strpos($line, 'PAGAMENTO_APROVADO') !== false) $cls = 'aprovado';
                        elseif (strpos($line, 'SKALEPAY_') !== false) $cls = 'skalepay';
                        elseif (strpos($line, 'NITROPAGAMENTO_') !== false) $cls = 'nitropagamento';
                        elseif (strpos($line, 'GATEWAY_') !== false) $cls = 'gateway';
                        elseif (strpos($line, 'ERRO') !== false || strpos($line, 'FALHOU') !== false) $cls = 'erro';
                    ?>
                    <div class="log-line <?php echo $cls; ?>"><?php echo sanitize($line); ?></div>
                <?php endforeach; ?>
                <?php if (empty($logLines)): ?>
                    <div class="no-data">Nenhum evento registrado</div>
                <?php endif; ?>
                <div id="logNoResult" class="no-data" style="display:none">Nenhum evento para este filtro</div>
            </div>
        </div>

        <!-- API Events / Rastreamento -->
        <div class="section-title"><span class="dot" style="background:#a78bfa"></span> Rastreamento API</div>
        <div class="table-wrap">
            <div class="table-header">
                <h3>Chamadas de API (UTMify, FB CAPI, TikTok)</h3>
                <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
                <button class="btn btn-sm" style="background:#4a044e;color:#f0abfc;border:1px solid #86198f" onclick="retryFailedTikTok()" id="retryTTBtn">&#x21BB; Retry TikTok com erro</button>
                <div class="api-filters">
                    <button class="api-filter-btn active" data-api-filter="all" onclick="filterApiEvents('all')">Todas</button>
                    <button class="api-filter-btn" data-api-filter="utmify" onclick="filterApiEvents('utmify')">UTMify</button>
                    <button class="api-filter-btn" data-api-filter="facebook_capi" onclick="filterApiEvents('facebook_capi')">Facebook</button>
                    <button class="api-filter-btn" data-api-filter="tiktok_events" onclick="filterApiEvents('tiktok_events')">TikTok</button>
                </div>
                </div>
            </div>
            <div class="table-scroll">
                <table id="apiEventsTable">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Payment Code</th>
                            <th>Evento</th>
                            <th>API</th>
                            <th>HTTP</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody id="apiEventsBody"></tbody>
                </table>
            </div>
            <div class="pagination" id="apiEventsPagination"></div>
        </div>

        </div><!-- end tab-vendas -->

        <!-- ═══════════════════════════════════════
             TAB: TIKTOK DASHBOARD
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel" id="tab-tiktok">
            <!-- TikTok Header -->
            <div class="gw-card" style="border-top:2px solid #f0abfc;margin-bottom:1.5rem">
                <div class="gw-info">
                    <div class="gw-icon" style="background:#4a044e22;color:#f0abfc;font-size:1rem;font-weight:800">TT</div>
                    <div>
                        <div class="gw-text-label">TikTok Performance Dashboard</div>
                        <div class="gw-text-name" style="font-size:1rem">
                            <span class="gw-live-dot" style="background:#f0abfc;box-shadow:0 0 8px #f0abfc66"></span>
                            <span id="ttHeaderStats">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                    <button class="btn btn-sm" style="background:#4a044e;color:#f0abfc;border:1px solid #86198f" onclick="retryFailedTikTok()" id="retryTTBtn2">&#x21BB; Retry Eventos com Erro</button>
                </div>
            </div>

            <!-- TikTok KPIs -->
            <div class="kpi-grid" id="ttKpis"></div>

            <!-- TikTok Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>Vendas TikTok por Dia</h3>
                    <canvas id="chartTTSales"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Receita TikTok por Dia (R$)</h3>
                    <canvas id="chartTTRevenue"></canvas>
                </div>
            </div>

            <!-- TikTok Campaigns Table -->
            <div class="section-title"><span class="dot" style="background:#f0abfc"></span> Campanhas TikTok</div>
            <div class="table-wrap">
                <div class="table-header">
                    <h3>Performance por Campanha / Conjunto / Anuncio</h3>
                </div>
                <div class="table-scroll">
                    <table id="ttCampaignTable">
                        <thead>
                            <tr>
                                <th data-col="campaign" data-type="string">Campanha</th>
                                <th data-col="medium" data-type="string">Conjunto</th>
                                <th data-col="content" data-type="string">Anuncio</th>
                                <th data-col="pix" data-type="number">PIX Gerados</th>
                                <th data-col="sales" data-type="number">Vendas</th>
                                <th data-col="revenue" data-type="number">Receita</th>
                                <th data-col="cvr" data-type="number">CVR%</th>
                                <th data-col="ticket" data-type="number">Ticket</th>
                            </tr>
                        </thead>
                        <tbody id="ttCampaignBody"></tbody>
                    </table>
                </div>
                <div class="pagination" id="ttCampaignPagination"></div>
            </div>

            <!-- TikTok API Events Status -->
            <div class="section-title"><span class="dot" style="background:#f0abfc"></span> Status dos Eventos TikTok API</div>
            <div class="analytics-grid">
                <!-- Event delivery stats -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#10b981"></span> Delivery de Eventos</h3>
                    <div id="ttEventDelivery"></div>
                </div>
                <!-- Event types breakdown -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#f0abfc"></span> Eventos por Tipo</h3>
                    <div id="ttEventTypes"></div>
                </div>
            </div>

            <!-- TikTok Sales Table -->
            <div class="section-title"><span class="dot" style="background:#f0abfc"></span> Vendas via TikTok</div>
            <div class="table-wrap">
                <div class="table-header">
                    <h3>Transacoes com origem TikTok</h3>
                    <div class="api-filters">
                        <button class="api-filter-btn active" data-tt-filter="all" onclick="filterTTSales('all')">Todas</button>
                        <button class="api-filter-btn" data-tt-filter="paid" onclick="filterTTSales('paid')">Pagas</button>
                        <button class="api-filter-btn" data-tt-filter="pending" onclick="filterTTSales('pending')">Pendentes</button>
                        <button class="api-filter-btn" data-tt-filter="failed" onclick="filterTTSales('failed')">Falhas</button>
                    </div>
                </div>
                <div class="table-scroll">
                    <table id="ttSalesTable">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Nome</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th>Campanha</th>
                                <th>Conjunto</th>
                                <th>ttclid</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody id="ttSalesBody"></tbody>
                    </table>
                </div>
                <div class="pagination" id="ttSalesPagination"></div>
            </div>

            <!-- Failed TikTok Events -->
            <div class="section-title"><span class="dot" style="background:#f87171"></span> Eventos TikTok com Erro</div>
            <div class="table-wrap">
                <div class="table-header">
                    <h3>Eventos que falharam (para retry)</h3>
                    <button class="btn btn-sm" style="background:#4a044e;color:#f0abfc;border:1px solid #86198f" onclick="retryFailedTikTok()">&#x21BB; Retry Todos</button>
                </div>
                <div class="table-scroll">
                    <table id="ttFailedTable">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Payment Code</th>
                                <th>Evento</th>
                                <th>HTTP</th>
                                <th>Erro</th>
                                <th>Acao</th>
                            </tr>
                        </thead>
                        <tbody id="ttFailedBody"></tbody>
                    </table>
                </div>
            </div>
        </div><!-- end tab-tiktok -->

        <!-- ═══════════════════════════════════════
             TAB: ANALYTICS / FUNIL
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel" id="tab-analytics">
            <!-- Analytics Header with Date Filter + Export -->
            <div class="filter-bar" style="margin-bottom:1rem">
                <label style="font-weight:600;color:#06b6d4">Analytics do Funil</label>
                <div style="flex:1"></div>
                <label>Fonte:</label>
                <select id="analyticsSourceFilter" style="background:#09090b;border:1px solid #3f3f46;color:#fff;padding:0.4rem 0.7rem;border-radius:8px;font-family:inherit;font-size:0.82rem" onchange="renderAnalytics()">
                    <option value="all">Todas</option>
                    <option value="tiktok">TikTok</option>
                    <option value="facebook">Facebook</option>
                    <option value="organic">Organico</option>
                </select>
                <button class="btn btn-sm" style="background:#06468a;color:#38bdf8;border:1px solid #0369a1" onclick="exportAnalyticsCSV()">Exportar CSV</button>
            </div>

            <!-- Main KPIs -->
            <div class="kpi-grid" id="analyticsKpis"></div>

            <!-- Funnel Visualization -->
            <div class="analytics-card" style="margin-bottom:1.5rem">
                <h3><span class="dot" style="background:#3b82f6"></span> Funil de Conversao (Dados combinados: Events + Payments)</h3>
                <div class="funnel-viz" id="funnelViz"></div>
                <div id="funnelConversionRates" style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #27272a;font-size:0.78rem;color:#a1a1aa"></div>
            </div>

            <!-- Row 1: Conversion Analysis -->
            <div class="analytics-grid">
                <!-- Conversion Rates Step-by-Step -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#fbbf24"></span> Taxas de Conversao por Etapa</h3>
                    <div id="conversionRates"></div>
                </div>

                <!-- PIX Diagnostics -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#a78bfa"></span> Diagnostico PIX</h3>
                    <div id="pixDiagnostics"></div>
                </div>

                <!-- Checkout Dropout -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#f87171"></span> Abandono no Checkout</h3>
                    <div id="dropoffAnalysis"></div>
                </div>

                <!-- Top Products -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#34d399"></span> Produtos Mais Vistos</h3>
                    <div id="topProductsViewed"></div>
                </div>
            </div>

            <!-- Row 2: Behavior Analysis -->
            <div class="analytics-grid">
                <!-- Event Volume -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#60a5fa"></span> Volume de Eventos</h3>
                    <div id="eventVolume"></div>
                </div>

                <!-- Source Performance -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#06b6d4"></span> Performance por Fonte</h3>
                    <div id="sourcePerformance"></div>
                </div>

                <!-- Form Errors -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#f87171"></span> Erros de Formulario</h3>
                    <div class="form-err-list" id="formErrors"></div>
                </div>

                <!-- Time Analysis -->
                <div class="analytics-card">
                    <h3><span class="dot" style="background:#fbbf24"></span> Horarios de Pico</h3>
                    <div id="peakHours"></div>
                </div>
            </div>

            <!-- Session Flow Explorer -->
            <div class="section-title"><span class="dot" style="background:#06b6d4"></span> Sessoes Completas (Jornada do Usuario)</div>
            <div class="analytics-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem">
                    <h3 style="margin:0"><span class="dot" style="background:#60a5fa"></span> Ultimas 30 Sessoes</h3>
                    <div class="api-filters">
                        <button class="api-filter-btn active" data-session-filter="all" onclick="filterSessions('all')">Todas</button>
                        <button class="api-filter-btn" data-session-filter="converted" onclick="filterSessions('converted')">Converteram</button>
                        <button class="api-filter-btn" data-session-filter="abandoned" onclick="filterSessions('abandoned')">Abandonaram</button>
                        <button class="api-filter-btn" data-session-filter="bounce" onclick="filterSessions('bounce')">Bounce</button>
                    </div>
                </div>
                <div class="session-timeline" id="sessionTimeline"></div>
            </div>

            <!-- Raw Events Table -->
            <div class="section-title"><span class="dot" style="background:#06b6d4"></span> Eventos Detalhados</div>
            <div class="table-wrap">
                <div class="table-header">
                    <h3>Todos os Eventos de Analytics</h3>
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
                        <div class="api-filters" id="analyticsFilters">
                            <button class="api-filter-btn active" data-analytics-filter="all" onclick="filterAnalytics('all')">Todos</button>
                            <button class="api-filter-btn" data-analytics-filter="funnel_view" onclick="filterAnalytics('funnel_view')">Funil</button>
                            <button class="api-filter-btn" data-analytics-filter="checkout" onclick="filterAnalytics('checkout')">Checkout</button>
                            <button class="api-filter-btn" data-analytics-filter="pix" onclick="filterAnalytics('pix')">PIX</button>
                            <button class="api-filter-btn" data-analytics-filter="error" onclick="filterAnalytics('error')">Erros</button>
                        </div>
                    </div>
                </div>
                <div class="table-scroll">
                    <table id="analyticsTable">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Sessao</th>
                                <th>Evento</th>
                                <th>Pagina</th>
                                <th>Dados</th>
                                <th>UTMs</th>
                            </tr>
                        </thead>
                        <tbody id="analyticsBody"></tbody>
                    </table>
                </div>
                <div class="pagination" id="analyticsPagination"></div>
            </div>
        </div><!-- end tab-analytics -->

        <!-- ═══════════════════════════════════════
             TAB: CRO / AUDIT
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel" id="tab-cro">
            <div class="section-title"><span class="dot" style="background:#f59e0b"></span> Auditoria CRO - Funil de Conversão</div>

            <!-- Funnel Waterfall -->
            <div class="kpi-grid" id="croWaterfall"></div>

            <!-- PIX Health -->
            <div class="section-title" style="margin-top:1.5rem"><span class="dot" style="background:#10b981"></span> Saúde PIX</div>
            <div class="kpi-grid" id="croPixHealth"></div>

            <!-- Repeat Customers -->
            <div class="section-title" style="margin-top:1.5rem"><span class="dot" style="background:#ef4444"></span> Clientes Repetidos (>3 pedidos)</div>
            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Email</th><th>Nome</th><th>Pedidos</th><th>Pagos</th><th>Pendentes</th><th>Total Pendente</th></tr></thead>
                        <tbody id="croRepeatTable"></tbody>
                    </table>
                </div>
            </div>

            <!-- Checkout Drop-off Analysis -->
            <div class="section-title" style="margin-top:1.5rem"><span class="dot" style="background:#8b5cf6"></span> Drop-off por Step do Checkout</div>
            <div class="kpi-grid" id="croCheckoutDrops"></div>
        </div><!-- end tab-cro -->

        <!-- ═══════════════════════════════════════
             TAB: EXPERIMENTS (A/B Testing)
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel" id="tab-experiments">
            <div class="section-title"><span class="dot" style="background:#a78bfa"></span> Motor de Testes A/B</div>

            <!-- Create Experiment Form -->
            <div style="background:#18181b;border:1px solid #27272a;border-radius:12px;padding:1.25rem;margin-bottom:1.5rem">
                <h3 style="color:#fff;font-size:1rem;margin-bottom:1rem">Criar Experimento</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1rem">
                    <div>
                        <label style="font-size:0.78rem;color:#a1a1aa;margin-bottom:0.3rem;display:block">Nome</label>
                        <input type="text" id="expName" placeholder="ex: PIX Timer 15min" style="width:100%;padding:0.5rem 0.75rem;background:#09090b;border:1px solid #3f3f46;border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;color:#a1a1aa;margin-bottom:0.3rem;display:block">Páginas Alvo</label>
                        <input type="text" id="expPages" placeholder="checkout, produto" style="width:100%;padding:0.5rem 0.75rem;background:#09090b;border:1px solid #3f3f46;border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-bottom:1rem">
                    <div>
                        <label style="font-size:0.78rem;color:#a1a1aa;margin-bottom:0.3rem;display:block">Variante A (peso)</label>
                        <input type="number" id="expWeightA" value="50" min="1" max="100" style="width:100%;padding:0.5rem 0.75rem;background:#09090b;border:1px solid #3f3f46;border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;color:#a1a1aa;margin-bottom:0.3rem;display:block">Variante B (peso)</label>
                        <input type="number" id="expWeightB" value="50" min="0" max="100" style="width:100%;padding:0.5rem 0.75rem;background:#09090b;border:1px solid #3f3f46;border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;color:#a1a1aa;margin-bottom:0.3rem;display:block">Métrica Primária</label>
                        <select id="expMetric" style="width:100%;padding:0.5rem 0.75rem;background:#09090b;border:1px solid #3f3f46;border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem">
                            <option value="pix_paid_rate">PIX Paid Rate</option>
                            <option value="checkout_completion">Checkout Completion</option>
                            <option value="add_to_cart_rate">Add to Cart Rate</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="createExperiment()">Criar Experimento (Draft)</button>
            </div>

            <!-- Experiment List -->
            <div class="table-wrap">
                <div class="table-header">
                    <h3>Experimentos</h3>
                    <button class="btn btn-sm btn-secondary" onclick="loadExperiments()">↻ Atualizar</button>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>ID</th><th>Nome</th><th>Status</th><th>Variantes</th><th>Métrica</th><th>Amostras</th><th>Ações</th></tr></thead>
                        <tbody id="experimentsTable"></tbody>
                    </table>
                </div>
            </div>

            <!-- Experiment Results -->
            <div id="experimentResults" style="margin-top:1.5rem"></div>
        </div><!-- end tab-experiments -->

        <!-- ═══════════════════════════════════════
             TAB: FEATURE FLAGS
             ═══════════════════════════════════════ -->
        <div class="dash-tab-panel" id="tab-flags">
            <div class="section-title"><span class="dot" style="background:#f59e0b"></span> Feature Flags & Killswitch</div>

            <!-- Global Killswitch -->
            <div style="background:#450a0a44;border:2px solid #7f1d1d;border-radius:14px;padding:1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between">
                <div>
                    <div style="color:#fca5a5;font-weight:700;font-size:1.1rem">🔴 Killswitch Global</div>
                    <div style="color:#a1a1aa;font-size:0.82rem;margin-top:0.3rem">Desativa TODAS as mudanças novas. Reverte ao código original (control).</div>
                </div>
                <label class="toggle-label" style="margin:0">
                    <div class="toggle-switch">
                        <input type="checkbox" id="flagKillswitch" onchange="toggleKillswitch(this.checked)">
                        <span class="toggle-slider"></span>
                    </div>
                </label>
            </div>

            <!-- Individual Flags -->
            <div id="flagsList" style="display:grid;gap:0.75rem"></div>

            <div style="margin-top:1.5rem;padding:1rem;background:#18181b;border:1px solid #27272a;border-radius:12px">
                <div style="font-size:0.78rem;color:#52525b">Última atualização: <span id="flagsUpdatedAt">—</span></div>
            </div>
        </div><!-- end tab-flags -->

        <!-- Sale Detail Modal -->
        <div class="modal-overlay" id="saleDetailModal" onclick="if(event.target===this)closeSaleDetail()">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Detalhes da Venda</h2>
                    <button class="modal-close" onclick="closeSaleDetail()">&times;</button>
                </div>
                <div class="modal-body" id="saleDetailBody"></div>
            </div>
        </div>

        <!-- Toast Notification -->
        <div class="toast" id="toast"></div>

        <!-- Settings -->
        <div class="settings-bar">
            <label class="toggle-label">
                <div class="toggle-switch">
                    <input type="checkbox" id="toggleAutoRefresh">
                    <span class="toggle-slider"></span>
                </div>
                <span>Auto-refresh (30s)</span>
            </label>
            <span style="color:#27272a">|</span>
            <span style="font-size:0.78rem;color:#52525b;">Dados carregados de: api/data/</span>
        </div>
    </div>

    <!-- ── Dados PHP → JS ── -->
    <script>
    // Transfer PHP data to JS
    const RAW_PAYMENTS = <?php echo json_encode(array_values($payments), JSON_UNESCAPED_UNICODE); ?>;
    const RAW_PAGEVIEWS = <?php echo json_encode($pageviews, JSON_UNESCAPED_UNICODE); ?>;
    const RAW_API_EVENTS = <?php echo json_encode($apiEvents ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const RAW_ANALYTICS = <?php echo json_encode($analyticsEvents ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const ITEMS_PER_PAGE = <?php echo $ITEMS_PER_PAGE; ?>;

    // ── State ──
    let filteredPayments = [];
    let filteredPageviews = [];
    let salesPage = 1;
    let trafficPage = 1;
    let autoRefreshTimer = null;
    let sortState = {};

    // ── Toast ──
    function showToast(msg, type) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast toast-' + (type || 'success') + ' show';
        setTimeout(() => { t.classList.remove('show'); }, 3000);
    }

    // ── Utils ──
    function formatBRL(cents) {
        const val = (cents / 100).toFixed(2).replace('.', ',');
        return 'R$ ' + val.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function formatDateBR(dt) {
        if (!dt) return '-';
        try {
            const d = new Date(dt.replace(' ', 'T'));
            if (isNaN(d)) return dt;
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yy = d.getFullYear();
            const hh = String(d.getHours()).padStart(2, '0');
            const mi = String(d.getMinutes()).padStart(2, '0');
            return `${dd}/${mm}/${yy} ${hh}:${mi}`;
        } catch (e) { return dt; }
    }

    function esc(s) {
        if (!s) return '';
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function getDateStr(dt) {
        if (!dt) return '';
        return dt.substring(0, 10);
    }

    // ── Filtering ──
    function applyFilters() {
        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value;
        const statusFilter = document.getElementById('statusFilter')?.value || 'all';

        filteredPayments = RAW_PAYMENTS.filter(p => {
            const d = getDateStr(p.created_at);
            if (from && d < from) return false;
            if (to && d > to) return false;
            if (statusFilter !== 'all' && p.status !== statusFilter) return false;
            return true;
        });

        filteredPageviews = RAW_PAGEVIEWS.filter(pv => {
            const d = getDateStr(pv.timestamp);
            if (from && d < from) return false;
            if (to && d > to) return false;
            return true;
        });

        salesPage = 1;
        trafficPage = 1;
        renderAll();
    }

    function resetFilters() {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        const sf = document.getElementById('statusFilter');
        if (sf) sf.value = 'all';
        filteredPayments = [...RAW_PAYMENTS];
        filteredPageviews = [...RAW_PAGEVIEWS];
        salesPage = 1;
        trafficPage = 1;
        renderAll();
    }

    // ── KPI Rendering ──
    function renderKPIs() {
        const totalViews = filteredPageviews.length;
        const totalPix = filteredPayments.length;
        const totalPaid = filteredPayments.filter(p => p.status === 'paid').length;
        const totalFailed = filteredPayments.filter(p => p.status === 'failed').length;
        const cvr = totalPix > 0 ? ((totalPaid / totalPix) * 100).toFixed(1) : '0.0';
        const totalRevenue = filteredPayments.filter(p => p.status === 'paid')
            .reduce((s, p) => s + (p.amount || 0), 0);
        const ticket = totalPaid > 0 ? Math.round(totalRevenue / totalPaid) : 0;

        document.getElementById('kpiAcessos').textContent = totalViews.toLocaleString('pt-BR');
        document.getElementById('kpiPix').textContent = totalPix.toLocaleString('pt-BR');
        document.getElementById('kpiVendas').textContent = totalPaid.toLocaleString('pt-BR');
        document.getElementById('kpiCvr').textContent = cvr + '%';
        document.getElementById('kpiReceita').textContent = formatBRL(totalRevenue);
        document.getElementById('kpiTicket').textContent = formatBRL(ticket);

        // Show failed count if any
        const failedEl = document.getElementById('kpiFalhas');
        if (failedEl) failedEl.textContent = totalFailed.toLocaleString('pt-BR');
    }

    // ── Chart: Bar (Sales / Revenue by Day) ──
    function drawBarChart(canvasId, data, color, formatFn) {
        const canvas = document.getElementById(canvasId);
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = 220 * dpr;
        ctx.scale(dpr, dpr);
        const W = rect.width;
        const H = 220;

        ctx.clearRect(0, 0, W, H);

        if (!data.length) {
            ctx.fillStyle = '#52525b';
            ctx.font = '13px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Sem dados para o periodo', W / 2, H / 2);
            return;
        }

        const labels = data.map(d => d.label);
        const values = data.map(d => d.value);
        const maxVal = Math.max(...values, 1);

        const padLeft = 60, padRight = 20, padTop = 20, padBottom = 40;
        const chartW = W - padLeft - padRight;
        const chartH = H - padTop - padBottom;
        const barW = Math.max(Math.min(chartW / labels.length - 4, 40), 8);

        // Grid lines
        ctx.strokeStyle = '#1c1c1f';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padTop + (chartH / 4) * i;
            ctx.beginPath();
            ctx.moveTo(padLeft, y);
            ctx.lineTo(W - padRight, y);
            ctx.stroke();

            const val = maxVal - (maxVal / 4) * i;
            ctx.fillStyle = '#52525b';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(formatFn ? formatFn(val) : Math.round(val).toString(), padLeft - 8, y + 3);
        }

        // Bars
        const step = chartW / labels.length;
        data.forEach((d, i) => {
            const barH = (d.value / maxVal) * chartH;
            const x = padLeft + step * i + (step - barW) / 2;
            const y = padTop + chartH - barH;

            ctx.fillStyle = color;
            ctx.globalAlpha = 0.85;
            ctx.beginPath();
            const r = Math.min(3, barW / 2);
            ctx.moveTo(x, y + r);
            ctx.arcTo(x, y, x + r, y, r);
            ctx.arcTo(x + barW, y, x + barW, y + r, r);
            ctx.lineTo(x + barW, padTop + chartH);
            ctx.lineTo(x, padTop + chartH);
            ctx.closePath();
            ctx.fill();
            ctx.globalAlpha = 1;

            ctx.fillStyle = '#52525b';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'center';
            const lbl = d.label.substring(5);
            ctx.fillText(lbl.replace('-', '/'), padLeft + step * i + step / 2, H - padBottom + 18);
        });
    }

    // ── Chart: Pie (Traffic Sources) ──
    function drawPieChart(canvasId, data) {
        const canvas = document.getElementById(canvasId);
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = 220 * dpr;
        ctx.scale(dpr, dpr);
        const W = rect.width;
        const H = 220;
        ctx.clearRect(0, 0, W, H);

        const legendEl = document.getElementById('pieLegend');
        legendEl.innerHTML = '';

        if (!data.length) {
            ctx.fillStyle = '#52525b';
            ctx.font = '13px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Sem dados para o periodo', W / 2, H / 2);
            return;
        }

        const colors = ['#3b82f6', '#34d399', '#fbbf24', '#a78bfa', '#f87171', '#fb923c', '#38bdf8', '#e879f9', '#6ee7b7', '#fcd34d'];
        const total = data.reduce((s, d) => s + d.value, 0);
        const cx = W / 2, cy = H / 2, r = Math.min(W, H) / 2 - 20;

        let startAngle = -Math.PI / 2;
        data.forEach((d, i) => {
            const slice = (d.value / total) * Math.PI * 2;
            const col = colors[i % colors.length];

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, startAngle, startAngle + slice);
            ctx.closePath();
            ctx.fillStyle = col;
            ctx.fill();

            if (slice > 0.3) {
                const mid = startAngle + slice / 2;
                const lx = cx + Math.cos(mid) * r * 0.6;
                const ly = cy + Math.sin(mid) * r * 0.6;
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 11px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                const pct = ((d.value / total) * 100).toFixed(0);
                ctx.fillText(pct + '%', lx, ly);
            }

            startAngle += slice;

            const item = document.createElement('div');
            item.className = 'pie-legend-item';
            const swatch = document.createElement('span');
            swatch.className = 'pie-legend-color';
            swatch.style.background = col;
            item.appendChild(swatch);
            const pct = ((d.value / total) * 100).toFixed(1);
            item.appendChild(document.createTextNode(esc(d.label || '(direto)') + ' (' + pct + '%)'));
            legendEl.appendChild(item);
        });
    }

    // ── Data Aggregation ──
    function getDailyData() {
        const salesByDay = {};
        const revenueByDay = {};

        filteredPayments.forEach(p => {
            const d = getDateStr(p.created_at);
            if (!d) return;
            if (!salesByDay[d]) { salesByDay[d] = 0; revenueByDay[d] = 0; }
            if (p.status === 'paid') {
                salesByDay[d]++;
                revenueByDay[d] += (p.amount || 0);
            }
        });

        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value || new Date().toISOString().substring(0, 10);
        if (from) {
            let cur = new Date(from + 'T00:00:00');
            const end = new Date(to + 'T00:00:00');
            while (cur <= end) {
                const ds = cur.toISOString().substring(0, 10);
                if (!salesByDay[ds]) salesByDay[ds] = 0;
                if (!revenueByDay[ds]) revenueByDay[ds] = 0;
                cur.setDate(cur.getDate() + 1);
            }
        }

        const days = Object.keys(salesByDay).sort();
        return {
            sales: days.map(d => ({ label: d, value: salesByDay[d] })),
            revenue: days.map(d => ({ label: d, value: revenueByDay[d] / 100 }))
        };
    }

    function getTrafficSourcesPie() {
        const sources = {};
        filteredPageviews.forEach(pv => {
            const src = pv.utm_source || '(direto)';
            sources[src] = (sources[src] || 0) + 1;
        });
        return Object.entries(sources)
            .map(([label, value]) => ({ label, value }))
            .sort((a, b) => b.value - a.value)
            .slice(0, 10);
    }

    function getTrafficTable() {
        const map = {};

        filteredPageviews.forEach(pv => {
            const key = [pv.utm_source || '(direto)', pv.utm_campaign || '-', pv.utm_medium || '-', pv.utm_content || '-'].join('||');
            if (!map[key]) map[key] = { source: pv.utm_source || '(direto)', campaign: pv.utm_campaign || '-', medium: pv.utm_medium || '-', content: pv.utm_content || '-', views: 0, pix: 0, sales: 0, revenue: 0 };
            map[key].views++;
        });

        filteredPayments.forEach(p => {
            const t = p.tracking || {};
            const key = [t.utm_source || '(direto)', t.utm_campaign || '-', t.utm_medium || '-', t.utm_content || '-'].join('||');
            if (!map[key]) map[key] = { source: t.utm_source || '(direto)', campaign: t.utm_campaign || '-', medium: t.utm_medium || '-', content: t.utm_content || '-', views: 0, pix: 0, sales: 0, revenue: 0 };
            map[key].pix++;
            if (p.status === 'paid') {
                map[key].sales++;
                map[key].revenue += (p.amount || 0);
            }
        });

        return Object.values(map).sort((a, b) => b.revenue - a.revenue);
    }

    function getTopProducts() {
        const map = {};
        filteredPayments.forEach(p => {
            (p.items || []).forEach(item => {
                const name = item.name || 'Produto';
                if (!map[name]) map[name] = { name, cart: 0, sold: 0, revenue: 0 };
                map[name].cart += (item.quantity || 1);
                if (p.status === 'paid') {
                    map[name].sold += (item.quantity || 1);
                    map[name].revenue += (item.price || p.amount || 0) * (item.quantity || 1);
                }
            });
        });
        return Object.values(map).sort((a, b) => b.revenue - a.revenue);
    }

    function getRecentSales() {
        return [...filteredPayments].sort((a, b) => {
            const da = a.created_at || '';
            const db = b.created_at || '';
            return db.localeCompare(da);
        });
    }

    // ── Table Rendering ──
    function renderTrafficTable() {
        const data = getTrafficTable();
        const tbody = document.getElementById('trafficBody');
        const TRAFFIC_PER_PAGE = 15;
        const totalPages = Math.max(1, Math.ceil(data.length / TRAFFIC_PER_PAGE));
        if (trafficPage > totalPages) trafficPage = totalPages;
        const start = (trafficPage - 1) * TRAFFIC_PER_PAGE;
        const pageData = data.slice(start, start + TRAFFIC_PER_PAGE);

        if (!pageData.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="no-data">Sem dados</td></tr>';
            document.getElementById('trafficPagination').innerHTML = '';
            return;
        }
        tbody.innerHTML = pageData.map(r => {
            const cvr = r.pix > 0 ? ((r.sales / r.pix) * 100).toFixed(1) : '0.0';
            return `<tr>
                <td>${esc(r.source)}</td>
                <td>${esc(r.campaign)}</td>
                <td>${esc(r.medium)}</td>
                <td>${esc(r.content)}</td>
                <td>${r.views}</td>
                <td>${r.pix}</td>
                <td>${r.sales}</td>
                <td>${formatBRL(r.revenue)}</td>
                <td>${cvr}%</td>
            </tr>`;
        }).join('');

        const pagDiv = document.getElementById('trafficPagination');
        if (totalPages <= 1) { pagDiv.innerHTML = ''; return; }
        let html = '';
        html += `<button ${trafficPage <= 1 ? 'disabled' : ''} onclick="trafficPage=1;renderTrafficTable()">&#171;</button>`;
        html += `<button ${trafficPage <= 1 ? 'disabled' : ''} onclick="trafficPage--;renderTrafficTable()">&#8249;</button>`;
        const maxButtons = 7;
        let startP = Math.max(1, trafficPage - Math.floor(maxButtons / 2));
        let endP = Math.min(totalPages, startP + maxButtons - 1);
        if (endP - startP < maxButtons - 1) startP = Math.max(1, endP - maxButtons + 1);
        for (let i = startP; i <= endP; i++) {
            html += `<button class="${i === trafficPage ? 'active' : ''}" onclick="trafficPage=${i};renderTrafficTable()">${i}</button>`;
        }
        html += `<button ${trafficPage >= totalPages ? 'disabled' : ''} onclick="trafficPage++;renderTrafficTable()">&#8250;</button>`;
        html += `<button ${trafficPage >= totalPages ? 'disabled' : ''} onclick="trafficPage=${totalPages};renderTrafficTable()">&#187;</button>`;
        pagDiv.innerHTML = html;
    }

    function renderProductsTable() {
        const data = getTopProducts();
        const tbody = document.getElementById('productsBody');
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="no-data">Sem dados</td></tr>';
            return;
        }
        tbody.innerHTML = data.map(r => `<tr>
            <td>${esc(r.name)}</td>
            <td>${r.cart}</td>
            <td>${r.sold}</td>
            <td>${formatBRL(r.revenue)}</td>
        </tr>`).join('');
    }

    function renderSalesTable() {
        const data = getRecentSales();
        const totalPages = Math.max(1, Math.ceil(data.length / ITEMS_PER_PAGE));
        if (salesPage > totalPages) salesPage = totalPages;
        const start = (salesPage - 1) * ITEMS_PER_PAGE;
        const pageData = data.slice(start, start + ITEMS_PER_PAGE);

        const tbody = document.getElementById('salesBody');
        if (!pageData.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="no-data">Sem vendas no periodo</td></tr>';
            document.getElementById('salesPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = pageData.map(p => {
            const items = (p.items || []).map(i => esc((i.name || 'Produto') + ' x' + (i.quantity || 1))).join(', ');
            const statusCls = p.status === 'paid' ? 'badge-paid' : p.status === 'failed' ? 'badge-failed' : 'badge-pending';
            const statusLabel = p.status === 'paid' ? 'Pago' : p.status === 'failed' ? 'Falhou' : 'Pendente';
            const gw = (p.gateway || 'mangofy').toLowerCase();
            const gwCls = gw === 'skalepay' ? 'badge-sk' : gw === 'nitropagamento' ? 'badge-np' : 'badge-mg';
            const gwLabel = gw === 'skalepay' ? 'SkalePay' : gw === 'nitropagamento' ? 'NitroPag' : 'Mangofy';
            const t = p.tracking || {};
            const pc = esc(p.payment_code || '');
            return `<tr class="clickable-row" onclick="openSaleDetail('${pc}')">
                <td>${formatDateBR(p.created_at)}</td>
                <td>${esc(p.customer?.name || '-')}</td>
                <td>${esc(p.customer?.email || '-')}</td>
                <td>${esc(p.customer?.phone || '-')}</td>
                <td>${items || '-'}</td>
                <td>${formatBRL(p.amount || 0)}</td>
                <td><span class="badge ${statusCls}">${statusLabel}</span></td>
                <td><span class="badge ${gwCls}">${gwLabel}</span></td>
                <td>${esc(t.utm_source || '-')}</td>
                <td>${esc(t.utm_campaign || '-')}</td>
                <td><button class="btn-detail" onclick="event.stopPropagation();openSaleDetail('${pc}')">Ver</button></td>
            </tr>`;
        }).join('');

        // Pagination
        const pagDiv = document.getElementById('salesPagination');
        if (totalPages <= 1) { pagDiv.innerHTML = ''; return; }

        let html = '';
        html += `<button ${salesPage <= 1 ? 'disabled' : ''} onclick="salesPage=1;renderSalesTable()">&#171;</button>`;
        html += `<button ${salesPage <= 1 ? 'disabled' : ''} onclick="salesPage--;renderSalesTable()">&#8249;</button>`;

        const maxButtons = 7;
        let startP = Math.max(1, salesPage - Math.floor(maxButtons / 2));
        let endP = Math.min(totalPages, startP + maxButtons - 1);
        if (endP - startP < maxButtons - 1) startP = Math.max(1, endP - maxButtons + 1);

        for (let i = startP; i <= endP; i++) {
            html += `<button class="${i === salesPage ? 'active' : ''}" onclick="salesPage=${i};renderSalesTable()">${i}</button>`;
        }

        html += `<button ${salesPage >= totalPages ? 'disabled' : ''} onclick="salesPage++;renderSalesTable()">&#8250;</button>`;
        html += `<button ${salesPage >= totalPages ? 'disabled' : ''} onclick="salesPage=${totalPages};renderSalesTable()">&#187;</button>`;
        pagDiv.innerHTML = html;
    }

    // ── Charts ──
    function renderCharts() {
        const daily = getDailyData();
        drawBarChart('chartSales', daily.sales, '#3b82f6', v => Math.round(v).toString());
        drawBarChart('chartRevenue', daily.revenue, '#34d399', v => 'R$ ' + Math.round(v).toLocaleString('pt-BR'));
        drawPieChart('chartTraffic', getTrafficSourcesPie());
    }

    // ── Render All ──
    function renderAll() {
        renderKPIs();
        renderCharts();
        renderTrafficTable();
        renderProductsTable();
        renderSalesTable();
        renderApiEvents();
    }

    // ── Table Sorting ──
    document.querySelectorAll('table thead th[data-col]').forEach(th => {
        th.addEventListener('click', function() {
            const table = this.closest('table');
            const tbody = table.querySelector('tbody');
            const col = this.dataset.col;
            const type = this.dataset.type || 'string';
            const tableId = table.id;

            if (!sortState[tableId]) sortState[tableId] = {};
            const dir = sortState[tableId][col] === 'asc' ? 'desc' : 'asc';
            sortState[tableId] = {};
            sortState[tableId][col] = dir;

            this.parentElement.querySelectorAll('th').forEach(t => t.classList.remove('sorted-asc', 'sorted-desc'));
            this.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const idx = Array.from(this.parentElement.children).indexOf(this);

            rows.sort((a, b) => {
                let av = a.children[idx]?.textContent?.trim() || '';
                let bv = b.children[idx]?.textContent?.trim() || '';

                if (type === 'number') {
                    av = parseFloat(av.replace(/[R$\s.%]/g, '').replace(',', '.')) || 0;
                    bv = parseFloat(bv.replace(/[R$\s.%]/g, '').replace(',', '.')) || 0;
                    return dir === 'asc' ? av - bv : bv - av;
                }
                return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });

            rows.forEach(r => tbody.appendChild(r));
        });
    });

    // ── CSV Export ──
    function exportCSV() {
        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value;
        let url = '?export=csv';
        if (from) url += '&date_from=' + encodeURIComponent(from);
        if (to) url += '&date_to=' + encodeURIComponent(to);
        window.location.href = url;
    }

    // ── Auto Refresh ──
    document.getElementById('toggleAutoRefresh').addEventListener('change', function() {
        if (this.checked) {
            autoRefreshTimer = setInterval(() => location.reload(), 30000);
        } else {
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    });

    // Log auto-refresh
    let logRefreshTimer = null;
    document.getElementById('toggleLogRefresh').addEventListener('change', function() {
        if (this.checked) {
            logRefreshTimer = setInterval(refreshLog, 15000);
        } else {
            if (logRefreshTimer) clearInterval(logRefreshTimer);
            logRefreshTimer = null;
        }
    });

    function refreshLog() {
        fetch(window.location.pathname + '?get_log=1&_t=' + Date.now())
            .then(r => r.text())
            .then(html => {
                if (html.indexOf('dashboard_password') !== -1) return;
                location.reload();
            })
            .catch(() => {});
    }

    // ── Resize charts on window resize ──
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(renderCharts, 200);
    });

    // ── Log Filtering ──
    let activeLogFilter = 'all';
    function filterLog(type) {
        activeLogFilter = type;
        document.querySelectorAll('.log-filter-btn').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`[data-log-filter="${type}"]`);
        if (btn) btn.classList.add('active');

        const lines = document.querySelectorAll('#logBody .log-line');
        let visible = 0;
        lines.forEach(line => {
            const text = line.textContent;
            let show = false;
            if (type === 'all') show = true;
            else if (type === 'pix' && text.indexOf('PIX_GERADO') !== -1) show = true;
            else if (type === 'webhook' && text.indexOf('WEBHOOK_RECEBIDO') !== -1) show = true;
            else if (type === 'aprovado' && text.indexOf('PAGAMENTO_APROVADO') !== -1) show = true;
            else if (type === 'skalepay' && text.indexOf('SKALEPAY_') !== -1) show = true;
            else if (type === 'nitropagamento' && text.indexOf('NITROPAGAMENTO_') !== -1) show = true;
            else if (type === 'gateway' && (text.indexOf('GATEWAY_') !== -1 || text.indexOf('GATEWAY_CHAIN') !== -1)) show = true;
            else if (type === 'erro' && (text.indexOf('ERRO') !== -1 || text.indexOf('erro') !== -1 || text.indexOf('FALHOU') !== -1)) show = true;
            else if (type === 'api' && (text.indexOf('UTMIFY') !== -1 || text.indexOf('FB_CAPI') !== -1 || text.indexOf('TIKTOK') !== -1 || text.indexOf('MANGOFY') !== -1)) show = true;
            line.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const noResult = document.getElementById('logNoResult');
        if (noResult) noResult.style.display = visible === 0 ? '' : 'none';
    }

    // ── API Events Table ──
    let apiEventsPage = 1;
    let apiFilterType = 'all';

    function filterApiEvents(type) {
        apiFilterType = type;
        apiEventsPage = 1;
        document.querySelectorAll('.api-filter-btn').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`[data-api-filter="${type}"]`);
        if (btn) btn.classList.add('active');
        renderApiEvents();
    }

    function renderApiEvents() {
        const tbody = document.getElementById('apiEventsBody');
        if (!tbody) return;

        let data = [...RAW_API_EVENTS].reverse();

        if (apiFilterType !== 'all') {
            data = data.filter(e => e.api === apiFilterType);
        }

        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value;
        if (from) data = data.filter(e => (e.timestamp || '').substring(0, 10) >= from);
        if (to) data = data.filter(e => (e.timestamp || '').substring(0, 10) <= to);

        const perPage = 15;
        const totalPages = Math.max(1, Math.ceil(data.length / perPage));
        if (apiEventsPage > totalPages) apiEventsPage = totalPages;
        const start = (apiEventsPage - 1) * perPage;
        const pageData = data.slice(start, start + perPage);

        if (!pageData.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="no-data">Nenhum evento de API registrado</td></tr>';
            document.getElementById('apiEventsPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = pageData.map(e => {
            const statusCls = e.success ? 'http-ok' : 'http-err';
            const dotCls = e.success ? 'success' : 'error';
            let apiBadgeCls = 'utmify';
            if (e.api === 'facebook_capi') apiBadgeCls = 'facebook';
            else if (e.api === 'tiktok_events') apiBadgeCls = 'tiktok';
            else if (e.api === 'mangofy') apiBadgeCls = 'mangofy';
            const pc = esc(e.payment_code || '');

            return `<tr class="clickable-row" onclick="openSaleDetail('${pc}')">
                <td>${formatDateBR(e.timestamp)}</td>
                <td style="font-family:monospace;font-size:0.76rem">${pc || '-'}</td>
                <td>${esc(e.event || '-')}</td>
                <td><span class="api-badge ${apiBadgeCls}">${esc(e.api || '-')}</span></td>
                <td><span class="${statusCls}" style="padding:0.15rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:600">${e.http_status || '-'}</span></td>
                <td><span class="status-dot ${dotCls}"></span>${e.success ? 'OK' : 'Erro'}</td>
            </tr>`;
        }).join('');

        const pagDiv = document.getElementById('apiEventsPagination');
        if (totalPages <= 1) { pagDiv.innerHTML = ''; return; }
        let html = '';
        html += `<button ${apiEventsPage<=1?'disabled':''} onclick="apiEventsPage=1;renderApiEvents()">\u00AB</button>`;
        html += `<button ${apiEventsPage<=1?'disabled':''} onclick="apiEventsPage--;renderApiEvents()">\u2039</button>`;
        const maxB = 7;
        let sP = Math.max(1, apiEventsPage - Math.floor(maxB/2));
        let eP = Math.min(totalPages, sP + maxB - 1);
        if (eP - sP < maxB - 1) sP = Math.max(1, eP - maxB + 1);
        for (let i = sP; i <= eP; i++) {
            html += `<button class="${i===apiEventsPage?'active':''}" onclick="apiEventsPage=${i};renderApiEvents()">${i}</button>`;
        }
        html += `<button ${apiEventsPage>=totalPages?'disabled':''} onclick="apiEventsPage++;renderApiEvents()">\u203A</button>`;
        html += `<button ${apiEventsPage>=totalPages?'disabled':''} onclick="apiEventsPage=${totalPages};renderApiEvents()">\u00BB</button>`;
        pagDiv.innerHTML = html;
    }

    // ── Sale Detail Modal ──
    function openSaleDetail(paymentCode) {
        if (!paymentCode) return;

        const payment = RAW_PAYMENTS.find(p => p.payment_code === paymentCode);
        const events = RAW_API_EVENTS.filter(e => e.payment_code === paymentCode)
            .sort((a, b) => (a.timestamp || '').localeCompare(b.timestamp || ''));

        const modal = document.getElementById('saleDetailModal');
        const body = document.getElementById('saleDetailBody');
        let html = '';

        if (payment) {
            const c = payment.customer || {};
            const t = payment.tracking || {};
            const itemsStr = (payment.items || []).map(i => esc((i.name || 'Produto') + ' x' + (i.quantity || 1))).join(', ') || '-';

            html += `<div class="detail-grid">
                <div class="detail-card">
                    <h4>Cliente</h4>
                    <div class="detail-row"><span class="label">Nome</span><span class="value">${esc(c.name || '-')}</span></div>
                    <div class="detail-row"><span class="label">Email</span><span class="value">${esc(c.email || '-')}</span></div>
                    <div class="detail-row"><span class="label">Telefone</span><span class="value">${esc(c.phone || '-')}</span></div>
                    <div class="detail-row"><span class="label">Documento</span><span class="value">${esc(c.document || '-')}</span></div>
                </div>
                <div class="detail-card">
                    <h4>Pagamento</h4>
                    <div class="detail-row"><span class="label">Codigo</span><span class="value" style="font-family:monospace;font-size:0.76rem">${esc(paymentCode)}</span></div>
                    <div class="detail-row"><span class="label">Valor</span><span class="value">${formatBRL(payment.amount || 0)}</span></div>
                    <div class="detail-row"><span class="label">Status</span><span class="value"><span class="badge ${payment.status==='paid'?'badge-paid':payment.status==='failed'?'badge-failed':'badge-pending'}">${payment.status==='paid'?'Pago':payment.status==='failed'?'Falhou'+(payment.fail_reason?' ('+esc(payment.fail_reason)+')':''):'Pendente'}</span></span></div>
                    <div class="detail-row"><span class="label">Gateway</span><span class="value"><span class="badge ${(payment.gateway||'mangofy')==='skalepay'?'badge-sk':(payment.gateway||'mangofy')==='nitropagamento'?'badge-np':'badge-mg'}">${(payment.gateway||'mangofy')==='skalepay'?'SkalePay':(payment.gateway||'mangofy')==='nitropagamento'?'NitroPagamento':'Mangofy'}</span></span></div>
                    <div class="detail-row"><span class="label">Criado</span><span class="value">${formatDateBR(payment.created_at)}</span></div>
                    <div class="detail-row"><span class="label">Pago em</span><span class="value">${formatDateBR(payment.paid_at)}</span></div>
                    <div class="detail-row"><span class="label">Produtos</span><span class="value">${itemsStr}</span></div>
                </div>
            </div>`;

            // Mark as Paid / Re-fire button
            if (payment.status === 'paid') {
                html += `<button class="btn-mark-paid refire" onclick="markAsPaid('${esc(paymentCode)}', true)" id="markPaidBtn">
                    &#x21BB; Reenviar pixels (UTMify, FB, TikTok)
                </button>`;
            } else {
                html += `<button class="btn-mark-paid" onclick="markAsPaid('${esc(paymentCode)}', false)" id="markPaidBtn">
                    &#x2713; Marcar como Pago + Disparar Pixels
                </button>`;
            }

            // UTMs card
            const hasUtms = t.utm_source || t.utm_campaign || t.utm_medium || t.utm_content || t.utm_term || t.fbclid || t.src || t.sck;
            if (hasUtms) {
                html += `<div class="detail-card" style="margin-bottom:1.5rem">
                    <h4>UTMs &amp; Rastreamento</h4>
                    ${t.utm_source ? `<div class="detail-row"><span class="label">Source</span><span class="value">${esc(t.utm_source)}</span></div>` : ''}
                    ${t.utm_campaign ? `<div class="detail-row"><span class="label">Campaign</span><span class="value">${esc(t.utm_campaign)}</span></div>` : ''}
                    ${t.utm_medium ? `<div class="detail-row"><span class="label">Medium</span><span class="value">${esc(t.utm_medium)}</span></div>` : ''}
                    ${t.utm_content ? `<div class="detail-row"><span class="label">Content</span><span class="value">${esc(t.utm_content)}</span></div>` : ''}
                    ${t.utm_term ? `<div class="detail-row"><span class="label">Term</span><span class="value">${esc(t.utm_term)}</span></div>` : ''}
                    ${t.src ? `<div class="detail-row"><span class="label">src</span><span class="value">${esc(t.src)}</span></div>` : ''}
                    ${t.sck ? `<div class="detail-row"><span class="label">sck</span><span class="value">${esc(t.sck)}</span></div>` : ''}
                    ${t.fbclid ? `<div class="detail-row"><span class="label">fbclid</span><span class="value" style="font-family:monospace;font-size:0.73rem;word-break:break-all">${esc(t.fbclid)}</span></div>` : ''}
                    ${t.fbp ? `<div class="detail-row"><span class="label">fbp</span><span class="value" style="font-family:monospace;font-size:0.73rem">${esc(t.fbp)}</span></div>` : ''}
                </div>`;
            }
        } else {
            html += `<div class="detail-card" style="margin-bottom:1.5rem">
                <h4>Pagamento</h4>
                <div class="detail-row"><span class="label">Codigo</span><span class="value" style="font-family:monospace">${esc(paymentCode)}</span></div>
                <p style="color:#71717a;font-size:0.85rem;margin-top:0.5rem">Dados do pagamento nao encontrados localmente.</p>
            </div>`;
        }

        // API Call Trail
        html += `<div class="api-trail-title"><span style="color:#a78bfa">&#9679;</span> Trail de Chamadas API (${events.length})</div>`;

        if (events.length === 0) {
            html += `<div class="no-api-events">Nenhuma chamada de API registrada para este pagamento</div>`;
        } else {
            events.forEach((e, idx) => {
                const statusCls = e.success ? 'http-ok' : 'http-err';
                let apiBadgeCls = 'utmify';
                if (e.api === 'facebook_capi') apiBadgeCls = 'facebook';
                else if (e.api === 'tiktok_events') apiBadgeCls = 'tiktok';
                else if (e.api === 'mangofy') apiBadgeCls = 'mangofy';

                let reqStr = '-';
                try { reqStr = e.request ? JSON.stringify(e.request, null, 2) : '-'; } catch(x) { reqStr = String(e.request); }
                let resStr = '-';
                try { resStr = e.response ? (typeof e.response === 'string' ? e.response : JSON.stringify(e.response, null, 2)) : '-'; } catch(x) { resStr = String(e.response); }

                const utmStr = (e.utms && typeof e.utms === 'object' && Object.keys(e.utms).filter(k => e.utms[k]).length > 0)
                    ? JSON.stringify(e.utms, null, 2) : null;

                html += `<div class="api-trail-item">
                    <div class="api-trail-header" onclick="this.nextElementSibling.classList.toggle('open');this.querySelector('.expand-icon').textContent=this.nextElementSibling.classList.contains('open')?'\\u25B2':'\\u25BC'">
                        <span class="time">${formatDateBR(e.timestamp)}</span>
                        <span class="api-badge ${apiBadgeCls}">${esc(e.api || '-')}</span>
                        <span class="event-name">${esc(e.event || '-')}</span>
                        <span class="http-status ${statusCls}">${e.http_status || '-'}</span>
                        <span class="expand-icon">\u25BC</span>
                    </div>
                    <div class="api-trail-body">
                        <div class="response-label">URL</div>
                        <div class="response-box" style="max-height:60px">${esc(e.url || '-')}</div>
                        <div class="response-label">Request Enviado</div>
                        <div class="response-box">${esc(reqStr)}</div>
                        <div class="response-label">Response Recebido</div>
                        <div class="response-box">${esc(resStr)}</div>
                        ${utmStr ? `<div class="response-label">UTMs Enviados</div><div class="response-box">${esc(utmStr)}</div>` : ''}
                    </div>
                </div>`;
            });
        }

        body.innerHTML = html;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSaleDetail() {
        document.getElementById('saleDetailModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSaleDetail();
    });

    // ── Gateway Switcher ──
    function switchGateway(gw) {
        var gwNames = { mangofy: 'Mangofy', skalepay: 'SkalePay', nitropagamento: 'NitroPagamento' };
        var gwIcons = { mangofy: 'MG', skalepay: 'SK', nitropagamento: 'NP' };
        var gwActiveCls = { mangofy: 'gw-active-mg', skalepay: 'gw-active-sk', nitropagamento: 'gw-active-np' };
        if (!confirm('Trocar gateway ativo para ' + (gwNames[gw] || gw.toUpperCase()) + '?\n\nTodos os novos pagamentos usarao esta gateway.')) return;
        const btns = document.querySelectorAll('.gw-btn');
        btns.forEach(b => { b.classList.remove('gw-active', 'gw-active-mg', 'gw-active-sk', 'gw-active-np'); b.disabled = true; });

        fetch('/api/gateway.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ gateway: gw })
        })
        .then(r => r.json())
        .then(data => {
            btns.forEach(b => { b.disabled = false; });
            if (data.success) {
                btns.forEach(b => {
                    if (b.dataset.gw === gw) {
                        b.classList.add('gw-active', gwActiveCls[gw] || 'gw-active-mg');
                    }
                });
                // Update card visual
                const card = document.getElementById('gwCard');
                card.className = 'gw-card gw-' + gw;
                document.getElementById('gwIcon').textContent = gwIcons[gw] || 'MG';
                document.getElementById('gwName').innerHTML = '<span class="gw-live-dot"></span> ' + (gwNames[gw] || gw);
                showToast('Gateway alterado para ' + (gwNames[gw] || gw), 'success');
            } else {
                showToast('Erro: ' + (data.error || 'falha ao trocar gateway'), 'error');
                location.reload();
            }
        })
        .catch(() => {
            showToast('Erro de rede ao trocar gateway', 'error');
            location.reload();
        });
    }

    // ── Mark as Paid ──
    function markAsPaid(paymentCode, isRefire) {
        const action = isRefire ? 'reenviar todos os pixels' : 'marcar como PAGO e disparar todos os pixels';
        if (!confirm(`Confirma ${action} para:\n${paymentCode}?`)) return;

        const btn = document.getElementById('markPaidBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Processando...'; }

        fetch('/api/mark-paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_code: paymentCode, password: 'ml2025' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(isRefire ? 'Pixels reenviados com sucesso!' : 'Pagamento marcado como pago! Pixels disparados.', 'success');

                // Update local data
                const p = RAW_PAYMENTS.find(p => p.payment_code === paymentCode);
                if (p) {
                    p.status = 'paid';
                    p.paid_at = data.approved_at;
                }

                // Refresh tables
                applyFilters();

                // Refresh modal
                setTimeout(() => openSaleDetail(paymentCode), 500);
            } else {
                showToast('Erro: ' + (data.error || 'falha'), 'error');
                if (btn) { btn.disabled = false; btn.textContent = isRefire ? '↻ Reenviar pixels' : '✓ Marcar como Pago'; }
            }
        })
        .catch(() => {
            showToast('Erro de rede', 'error');
            if (btn) { btn.disabled = false; btn.textContent = isRefire ? '↻ Reenviar pixels' : '✓ Marcar como Pago'; }
        });
    }

    // ════════════════════════════════════════════
    //  TAB SWITCHING
    // ════════════════════════════════════════════
    let analyticsRendered = false;

    let croRendered = false;
    let flagsRendered = false;
    let experimentsRendered = false;

    function switchDashTab(tabId) {
        document.querySelectorAll('.dash-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.dash-tab-panel').forEach(p => p.classList.remove('active'));

        const btn = document.querySelector(`.dash-tab[data-tab="${tabId}"]`);
        const panel = document.getElementById('tab-' + tabId);
        if (btn) btn.classList.add('active');
        if (panel) panel.classList.add('active');

        if (tabId === 'analytics') {
            renderAnalytics();
            analyticsRendered = true;
        }
        if (tabId === 'tiktok' && !ttRendered) {
            renderTikTok();
            ttRendered = true;
        }
        if (tabId === 'cro' && !croRendered) {
            renderCRO();
            croRendered = true;
        }
        if (tabId === 'flags') {
            loadFeatureFlags();
            flagsRendered = true;
        }
        if (tabId === 'experiments') {
            loadExperiments();
            experimentsRendered = true;
        }
    }

    // ════════════════════════════════════════════
    //  ANALYTICS TAB RENDERING (v2 - Deep Funnel)
    // ════════════════════════════════════════════
    let analyticsPage = 1;
    let analyticsFilter = 'all';
    let sessionFilter = 'all';

    // Helper: get filtered analytics based on source dropdown
    function getFilteredAnalytics() {
        const srcFilter = document.getElementById('analyticsSourceFilter')?.value || 'all';
        if (srcFilter === 'all') return RAW_ANALYTICS;
        return RAW_ANALYTICS.filter(e => {
            const src = (e.utms?.utm_source || '').toLowerCase();
            if (srcFilter === 'tiktok') return src.indexOf('tiktok') !== -1;
            if (srcFilter === 'facebook') return src.indexOf('facebook') !== -1 || src.indexOf('fb') !== -1 || src.indexOf('ig') !== -1;
            if (srcFilter === 'organic') return !src || src === '(direto)';
            return true;
        });
    }

    // Helper: get filtered payments based on source
    function getFilteredPaymentsForAnalytics() {
        const srcFilter = document.getElementById('analyticsSourceFilter')?.value || 'all';
        if (srcFilter === 'all') return filteredPayments;
        return filteredPayments.filter(p => {
            const src = (p.tracking?.utm_source || '').toLowerCase();
            if (srcFilter === 'tiktok') return src.indexOf('tiktok') !== -1 || p.tracking?.ttclid;
            if (srcFilter === 'facebook') return src.indexOf('facebook') !== -1 || src.indexOf('fb') !== -1;
            if (srcFilter === 'organic') return !src;
            return true;
        });
    }

    function renderAnalytics() {
        renderAnalyticsKpis();
        renderFunnel();
        renderConversionRates();
        renderPixDiagnostics();
        renderDropoff();
        renderTopProductsViewed();
        renderEventVolume();
        renderSourcePerformance();
        renderFormErrors();
        renderPeakHours();
        renderSessionTimeline();
        renderAnalyticsTable();
    }

    function renderAnalyticsKpis() {
        const container = document.getElementById('analyticsKpis');
        if (!container) return;
        const events = getFilteredAnalytics();
        const payments = getFilteredPaymentsForAnalytics();

        const totalSessions = new Set(events.map(e => e.session_id).filter(Boolean)).size;
        const totalEvents = events.length;
        const funnelViews = events.filter(e => e.event === 'funnel_view');
        const prevslViews = funnelViews.filter(e => (e.page || '').indexOf('prevsl') !== -1).length;
        const checkoutViews = funnelViews.filter(e => (e.page || '').indexOf('checkout') !== -1).length;
        const pixGenerated = payments.length;
        const pixPaid = payments.filter(p => p.status === 'paid');
        const totalRevenue = pixPaid.reduce((s, p) => s + (p.amount || 0), 0);
        const pixRate = pixGenerated > 0 ? ((pixPaid.length / pixGenerated) * 100).toFixed(1) : '0.0';
        const overallCvr = prevslViews > 0 ? ((pixPaid.length / prevslViews) * 100).toFixed(2) : '0.00';
        const viewToCheckout = prevslViews > 0 ? ((checkoutViews / prevslViews) * 100).toFixed(1) : '0.0';
        const formErrors = events.filter(e => e.event === 'form_error').length;

        container.innerHTML = `
            <div class="kpi-card" style="border-top:2px solid #60a5fa"><div class="kpi-label">Sessoes Unicas</div><div class="kpi-value blue">${totalSessions}</div></div>
            <div class="kpi-card" style="border-top:2px solid #a78bfa"><div class="kpi-label">Entrada Funil (prevsl)</div><div class="kpi-value purple">${prevslViews}</div></div>
            <div class="kpi-card" style="border-top:2px solid #fbbf24"><div class="kpi-label">Chegaram Checkout</div><div class="kpi-value yellow">${checkoutViews}</div></div>
            <div class="kpi-card" style="border-top:2px solid #06b6d4"><div class="kpi-label">View→Checkout</div><div class="kpi-value" style="color:#06b6d4">${viewToCheckout}%</div></div>
            <div class="kpi-card" style="border-top:2px solid #34d399"><div class="kpi-label">PIX Pagos</div><div class="kpi-value green">${pixPaid.length} / ${pixGenerated}</div></div>
            <div class="kpi-card" style="border-top:2px solid #10b981"><div class="kpi-label">Receita</div><div class="kpi-value green">${formatBRL(totalRevenue)}</div></div>
            <div class="kpi-card" style="border-top:2px solid ${parseFloat(pixRate) >= 12 ? '#10b981' : '#f87171'}"><div class="kpi-label">PIX→Pago</div><div class="kpi-value" style="color:${parseFloat(pixRate) >= 12 ? '#34d399' : '#f87171'}">${pixRate}%</div></div>
            <div class="kpi-card" style="border-top:2px solid ${parseFloat(overallCvr) >= 1 ? '#10b981' : '#f87171'}"><div class="kpi-label">CVR Total</div><div class="kpi-value" style="color:${parseFloat(overallCvr) >= 1 ? '#34d399' : '#f87171'}">${overallCvr}%</div></div>
        `;
    }

    function renderFunnel() {
        const container = document.getElementById('funnelViz');
        if (!container) return;
        const events = getFilteredAnalytics();
        const payments = getFilteredPaymentsForAnalytics();

        const stages = [
            { key: 'prevsl', label: 'Pre-VSL', color: '#60a5fa' },
            { key: 'vsl', label: 'VSL', color: '#818cf8' },
            { key: 'questionario', label: 'Quiz', color: '#a78bfa' },
            { key: 'roleta', label: 'Roleta', color: '#c084fc' },
            { key: 'recompensas', label: 'Recomp.', color: '#e879f9' },
            { key: 'produto', label: 'Produto', color: '#f472b6' },
            { key: 'checkout', label: 'Checkout', color: '#fbbf24' },
            { key: 'pix', label: 'PIX Gerado', color: '#34d399' },
            { key: 'paid', label: 'Pago', color: '#10b981' }
        ];

        // Count UNIQUE SESSIONS per funnel stage (not raw events)
        const sessionStages = {};
        stages.forEach(s => sessionStages[s.key] = new Set());

        events.forEach(evt => {
            const sid = evt.session_id || '';
            if (evt.event === 'funnel_view') {
                const page = evt.page || '';
                if (page.indexOf('prevsl') !== -1) sessionStages.prevsl.add(sid);
                else if (page === 'vsl' || (page.indexOf('vsl') !== -1 && page.indexOf('prevsl') === -1)) sessionStages.vsl.add(sid);
                else if (page.indexOf('questionario') !== -1) sessionStages.questionario.add(sid);
                else if (page.indexOf('roleta') !== -1) sessionStages.roleta.add(sid);
                else if (page.indexOf('recompensas') !== -1) sessionStages.recompensas.add(sid);
                else if (page.indexOf('produto') !== -1) sessionStages.produto.add(sid);
                else if (page.indexOf('checkout') !== -1) sessionStages.checkout.add(sid);
            } else if (evt.event === 'InitiateCheckout') {
                sessionStages.checkout.add(sid);
            } else if (evt.event === 'GeneratePixCode' || evt.event === 'CopyPixCode') {
                sessionStages.pix.add(sid);
            } else if (evt.event === 'Purchase') {
                sessionStages.paid.add(sid);
            }
        });

        // Use payments data for pix + paid (more reliable)
        const counts = {};
        stages.forEach(s => counts[s.key] = sessionStages[s.key].size);
        // Always use payments for pix/paid counts as they're authoritative
        counts.pix = Math.max(counts.pix, payments.length);
        counts.paid = Math.max(counts.paid, payments.filter(p => p.status === 'paid').length);

        const maxCount = Math.max(...Object.values(counts), 1);
        const maxBarHeight = 160;
        const entryCount = counts[stages[0].key] || 1;

        let html = '';
        stages.forEach((stage, idx) => {
            const count = counts[stage.key];
            const height = Math.max(4, (count / maxCount) * maxBarHeight);
            const pct = ((count / entryCount) * 100).toFixed(1);
            const dropPct = idx > 0 && counts[stages[idx-1].key] > 0
                ? ((1 - count / counts[stages[idx-1].key]) * 100).toFixed(0)
                : '';

            if (idx > 0) {
                html += `<div class="funnel-arrow" style="display:flex;flex-direction:column;align-items:center;padding-bottom:2rem">
                    <span style="font-size:0.65rem;color:${parseInt(dropPct) > 50 ? '#f87171' : '#71717a'};white-space:nowrap">-${dropPct}%</span>
                    <span>&rarr;</span>
                </div>`;
            }
            html += `<div class="funnel-bar-wrap">
                <div class="funnel-bar" style="height:${height}px;background:${stage.color}"></div>
                <div class="funnel-label">${stage.label}</div>
                <div class="funnel-count">${count.toLocaleString('pt-BR')}</div>
                <div class="funnel-pct">${pct}%</div>
            </div>`;
        });
        container.innerHTML = html;

        // Summary line
        const ratesEl = document.getElementById('funnelConversionRates');
        if (ratesEl) {
            ratesEl.innerHTML = `<strong>Resumo:</strong> ${counts.prevsl} entraram &rarr; ${counts.checkout} checkout (${((counts.checkout/entryCount)*100).toFixed(1)}%) &rarr; ${counts.pix} PIX (${((counts.pix/entryCount)*100).toFixed(1)}%) &rarr; ${counts.paid} pagaram (${((counts.paid/entryCount)*100).toFixed(2)}%) &nbsp;|&nbsp; <strong style="color:#34d399">Ticket:</strong> ${formatBRL(payments.filter(p=>p.status==='paid').reduce((s,p)=>s+(p.amount||0),0) / Math.max(counts.paid,1))}`;
        }
    }

    function renderConversionRates() {
        const container = document.getElementById('conversionRates');
        if (!container) return;
        const events = getFilteredAnalytics();
        const payments = getFilteredPaymentsForAnalytics();

        // Build step-by-step conversion with drop-off analysis
        const steps = [
            { name: 'Pre-VSL', count: 0 },
            { name: 'VSL', count: 0 },
            { name: 'Quiz', count: 0 },
            { name: 'Roleta', count: 0 },
            { name: 'Recompensas', count: 0 },
            { name: 'Produto', count: 0 },
            { name: 'Checkout', count: 0 },
            { name: 'PIX Gerado', count: payments.length },
            { name: 'Pago', count: payments.filter(p => p.status === 'paid').length }
        ];

        // Count unique sessions per step
        const sets = steps.map(() => new Set());
        events.forEach(e => {
            const sid = e.session_id || '';
            const page = e.page || '';
            if (e.event === 'funnel_view') {
                if (page.indexOf('prevsl') !== -1) sets[0].add(sid);
                else if (page === 'vsl' || (page.indexOf('vsl') !== -1 && page.indexOf('prevsl') === -1)) sets[1].add(sid);
                else if (page.indexOf('questionario') !== -1) sets[2].add(sid);
                else if (page.indexOf('roleta') !== -1) sets[3].add(sid);
                else if (page.indexOf('recompensas') !== -1) sets[4].add(sid);
                else if (page.indexOf('produto') !== -1) sets[5].add(sid);
                else if (page.indexOf('checkout') !== -1) sets[6].add(sid);
            }
        });
        for (let i = 0; i < 7; i++) steps[i].count = sets[i].size;

        let html = '';
        steps.forEach((step, i) => {
            const prev = i > 0 ? steps[i-1].count : step.count;
            const convRate = prev > 0 ? ((step.count / prev) * 100).toFixed(1) : '—';
            const lost = i > 0 ? Math.max(0, prev - step.count) : 0;
            const isBottleneck = i > 0 && prev > 0 && (step.count / prev) < 0.5;

            html += `<div class="analytics-stat-row" style="${isBottleneck ? 'background:#450a0a22;border-radius:4px;padding:0.4rem 0.5rem;margin:0 -0.5rem' : ''}">
                <span class="analytics-stat-label">${step.name}${isBottleneck ? ' <span style="color:#f87171;font-size:0.65rem">GARGALO</span>' : ''}</span>
                <span class="analytics-stat-value">${step.count} <span style="color:#71717a;font-size:0.68rem">${i > 0 ? convRate + '% | -' + lost : ''}</span></span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function renderPixDiagnostics() {
        const container = document.getElementById('pixDiagnostics');
        if (!container) return;
        const payments = getFilteredPaymentsForAnalytics();
        const total = payments.length;
        const paid = payments.filter(p => p.status === 'paid');
        const pending = payments.filter(p => p.status === 'pending');
        const failed = payments.filter(p => p.status === 'failed');

        const timings = [];
        paid.forEach(p => {
            if (p.created_at && p.paid_at) {
                const diffMin = (new Date(p.paid_at) - new Date(p.created_at)) / 60000;
                if (diffMin > 0 && diffMin < 1440) timings.push(diffMin);
            }
        });
        timings.sort((a, b) => a - b);
        const median = timings.length > 0 ? timings[Math.floor(timings.length / 2)] : 0;
        const under5 = timings.filter(t => t <= 5).length;

        const mgTotal = payments.filter(p => (p.gateway||'mangofy') === 'mangofy').length;
        const mgPaid = payments.filter(p => (p.gateway||'mangofy') === 'mangofy' && p.status === 'paid').length;
        const mgFailed = payments.filter(p => (p.gateway||'mangofy') === 'mangofy' && p.status === 'failed').length;
        const skTotal = payments.filter(p => p.gateway === 'skalepay').length;
        const skPaid = payments.filter(p => p.gateway === 'skalepay' && p.status === 'paid').length;
        const skFailed = payments.filter(p => p.gateway === 'skalepay' && p.status === 'failed').length;
        const npTotal = payments.filter(p => p.gateway === 'nitropagamento').length;
        const npPaid = payments.filter(p => p.gateway === 'nitropagamento' && p.status === 'paid').length;
        const npFailed = payments.filter(p => p.gateway === 'nitropagamento' && p.status === 'failed').length;

        let html = '';
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Total PIX</span><span class="analytics-stat-value">${total}</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Pagos</span><span class="analytics-stat-value good">${paid.length}</span></div>`;
        if (failed.length > 0) {
            html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Falhas Gateway</span><span class="analytics-stat-value bad">${failed.length} (${total>0?((failed.length/total)*100).toFixed(1):0}%)</span></div>`;
        }
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Abandono</span><span class="analytics-stat-value bad">${total > 0 ? ((pending.length/total)*100).toFixed(1) : 0}%</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Tempo mediano</span><span class="analytics-stat-value">${median.toFixed(1)} min</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Pagam <5min</span><span class="analytics-stat-value">${timings.length > 0 ? ((under5/timings.length)*100).toFixed(0) : 0}%</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Mangofy</span><span class="analytics-stat-value">${mgPaid}/${mgTotal} (${mgTotal>0?((mgPaid/mgTotal)*100).toFixed(1):0}%)${mgFailed?' <span style="color:#f87171;font-size:0.65rem">'+mgFailed+' falha(s)</span>':''}</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">SkalePay</span><span class="analytics-stat-value">${skPaid}/${skTotal} (${skTotal>0?((skPaid/skTotal)*100).toFixed(1):0}%)${skFailed?' <span style="color:#f87171;font-size:0.65rem">'+skFailed+' falha(s)</span>':''}</span></div>`;
        if (npTotal > 0) html += `<div class="analytics-stat-row"><span class="analytics-stat-label">NitroPag</span><span class="analytics-stat-value">${npPaid}/${npTotal} (${npTotal>0?((npPaid/npTotal)*100).toFixed(1):0}%)${npFailed?' <span style="color:#f87171;font-size:0.65rem">'+npFailed+' falha(s)</span>':''}</span></div>`;
        container.innerHTML = html;
    }

    function renderDropoff() {
        const container = document.getElementById('dropoffAnalysis');
        if (!container) return;
        const events = getFilteredAnalytics();
        const stepCounts = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
        events.filter(e => e.event === 'checkout_step').forEach(e => {
            const step = e.data?.step;
            if (step && stepCounts[step] !== undefined) stepCounts[step]++;
        });
        const stepNames = { 1: 'Carrinho', 2: 'Dados', 3: 'Endereco', 4: 'Envio', 5: 'Pagamento' };
        const maxStep = Math.max(...Object.values(stepCounts), 1);
        let html = '';
        for (let s = 1; s <= 5; s++) {
            const count = stepCounts[s];
            const pct = maxStep > 0 ? ((count/maxStep)*100).toFixed(0) : 0;
            const dropoff = s > 1 && stepCounts[s-1] > 0 ? ((1-count/stepCounts[s-1])*100).toFixed(0) : '';
            html += `<div class="pix-bar-wrap">
                <span class="pix-bar-label" style="min-width:70px">${s}. ${stepNames[s]}</span>
                <div class="pix-bar-bg"><div class="pix-bar-fill" style="width:${pct}%;background:${parseInt(dropoff)>50?'#f87171':'#fbbf24'}"></div></div>
                <span class="pix-bar-value">${count} ${s>1 && dropoff ? '<span style=\"color:#f87171;font-size:0.65rem\">-'+dropoff+'%</span>' : ''}</span>
            </div>`;
        }
        if (Object.values(stepCounts).every(v => v === 0)) html = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Sem dados de checkout ainda</div>';
        container.innerHTML = html;
    }

    function renderTopProductsViewed() {
        const container = document.getElementById('topProductsViewed');
        if (!container) return;
        const events = getFilteredAnalytics();
        const products = {};
        events.filter(e => e.event === 'funnel_view' && (e.page||'').indexOf('produto') !== -1).forEach(e => {
            const name = (e.page || '').replace('produto:', '') || '?';
            products[name] = (products[name] || 0) + 1;
        });
        events.filter(e => e.event === 'ViewContent').forEach(e => {
            const name = e.data?.content_name || e.page || '?';
            const key = name.replace('produto:', '');
            products[key] = (products[key] || 0) + 1;
        });
        const sorted = Object.entries(products).sort((a,b) => b[1]-a[1]);
        if (!sorted.length) { container.innerHTML = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Sem dados</div>'; return; }
        const maxV = sorted[0][1];
        let html = '';
        sorted.slice(0,10).forEach(([name, count]) => {
            html += `<div class="pix-bar-wrap">
                <span class="pix-bar-label" style="min-width:90px;font-size:0.72rem">${esc(name)}</span>
                <div class="pix-bar-bg"><div class="pix-bar-fill" style="width:${((count/maxV)*100).toFixed(0)}%;background:#f472b6"></div></div>
                <span class="pix-bar-value">${count}</span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function renderEventVolume() {
        const container = document.getElementById('eventVolume');
        if (!container) return;
        const events = getFilteredAnalytics();
        const counts = {};
        events.forEach(e => { counts[e.event] = (counts[e.event]||0)+1; });
        const sorted = Object.entries(counts).sort((a,b) => b[1]-a[1]);
        if (!sorted.length) { container.innerHTML = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Sem eventos</div>'; return; }
        const maxVal = sorted[0][1];
        let html = '';
        sorted.slice(0,12).forEach(([name, count]) => {
            html += `<div class="pix-bar-wrap">
                <span class="pix-bar-label" style="min-width:100px;font-size:0.72rem">${esc(name)}</span>
                <div class="pix-bar-bg"><div class="pix-bar-fill" style="width:${((count/maxVal)*100).toFixed(0)}%;background:#60a5fa"></div></div>
                <span class="pix-bar-value">${count}</span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function renderSourcePerformance() {
        const container = document.getElementById('sourcePerformance');
        if (!container) return;
        const sources = {};
        RAW_ANALYTICS.forEach(e => {
            const src = e.utms?.utm_source || '(direto)';
            if (!sources[src]) sources[src] = { sessions: new Set(), events: 0 };
            sources[src].sessions.add(e.session_id || '');
            sources[src].events++;
        });
        // Add payment data
        RAW_PAYMENTS.forEach(p => {
            const src = p.tracking?.utm_source || '(direto)';
            if (!sources[src]) sources[src] = { sessions: new Set(), events: 0, pix: 0, paid: 0, revenue: 0 };
            sources[src].pix = (sources[src].pix || 0) + 1;
            if (p.status === 'paid') {
                sources[src].paid = (sources[src].paid || 0) + 1;
                sources[src].revenue = (sources[src].revenue || 0) + (p.amount || 0);
            }
        });
        const sorted = Object.entries(sources).sort((a,b) => (b[1].revenue||0)-(a[1].revenue||0));
        if (!sorted.length) { container.innerHTML = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Sem dados</div>'; return; }
        let html = '';
        sorted.slice(0,8).forEach(([src, data]) => {
            const cvr = (data.pix||0) > 0 ? (((data.paid||0)/(data.pix||1))*100).toFixed(1) : '0';
            html += `<div class="analytics-stat-row">
                <span class="analytics-stat-label">${esc(src)} <span style="color:#71717a;font-size:0.65rem">(${data.sessions.size} sess.)</span></span>
                <span class="analytics-stat-value">${data.paid||0} vendas <span style="color:#71717a;font-size:0.68rem">${formatBRL(data.revenue||0)} | ${cvr}%</span></span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function renderFormErrors() {
        const container = document.getElementById('formErrors');
        if (!container) return;
        const events = getFilteredAnalytics();
        const errors = {};
        events.filter(e => e.event === 'form_error').forEach(e => {
            const key = (e.data?.field||'?') + '|' + (e.data?.message||'?');
            errors[key] = (errors[key]||0)+1;
        });
        const sorted = Object.entries(errors).sort((a,b) => b[1]-a[1]);
        if (!sorted.length) { container.innerHTML = '<div style="color:#34d399;font-size:0.82rem;padding:1rem 0">Nenhum erro registrado!</div>'; return; }
        let html = '';
        sorted.slice(0,15).forEach(([key, count]) => {
            const [field, msg] = key.split('|');
            html += `<div class="form-err-item"><span class="form-err-field">${esc(field)}</span><span class="form-err-msg">${esc(msg)}</span><span class="form-err-count">${count}x</span></div>`;
        });
        container.innerHTML = html;
    }

    function renderPeakHours() {
        const container = document.getElementById('peakHours');
        if (!container) return;
        const events = getFilteredAnalytics();
        const hours = {};
        for (let h = 0; h < 24; h++) hours[h] = 0;
        events.forEach(e => {
            if (e.server_time) {
                const h = new Date(e.server_time).getHours();
                if (!isNaN(h)) hours[h]++;
            }
        });
        const maxH = Math.max(...Object.values(hours), 1);
        // Show as mini heatmap bars
        let html = '<div style="display:flex;gap:2px;align-items:flex-end;height:80px;margin-bottom:0.5rem">';
        for (let h = 0; h < 24; h++) {
            const pct = (hours[h] / maxH) * 100;
            const color = pct > 60 ? '#f87171' : pct > 30 ? '#fbbf24' : '#3f3f46';
            html += `<div title="${h}h: ${hours[h]} eventos" style="flex:1;height:${Math.max(2,pct)}%;background:${color};border-radius:2px 2px 0 0"></div>`;
        }
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;font-size:0.62rem;color:#71717a"><span>0h</span><span>6h</span><span>12h</span><span>18h</span><span>23h</span></div>';
        // Top 3 hours
        const topHours = Object.entries(hours).sort((a,b)=>b[1]-a[1]).slice(0,3);
        html += '<div style="margin-top:0.5rem">';
        topHours.forEach(([h, count]) => {
            html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Pico ${h}h</span><span class="analytics-stat-value">${count} eventos</span></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function filterSessions(type) {
        sessionFilter = type;
        document.querySelectorAll('[data-session-filter]').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`[data-session-filter="${type}"]`);
        if (btn) btn.classList.add('active');
        renderSessionTimeline();
    }

    function renderSessionTimeline() {
        const container = document.getElementById('sessionTimeline');
        if (!container) return;
        const events = getFilteredAnalytics();

        const sessions = {};
        events.forEach(e => {
            const sid = e.session_id || 'unknown';
            if (!sessions[sid]) sessions[sid] = [];
            sessions[sid].push(e);
        });

        let sessionList = Object.entries(sessions)
            .map(([sid, evts]) => {
                const sorted = evts.sort((a,b) => (a.server_time||'').localeCompare(b.server_time||''));
                const hasCheckout = evts.some(e => e.event === 'InitiateCheckout' || (e.page||'').indexOf('checkout') !== -1);
                const hasPix = evts.some(e => e.event === 'GeneratePixCode' || e.event === 'CopyPixCode');
                const hasPurchase = evts.some(e => e.event === 'Purchase');
                const isBounce = evts.length <= 1;
                return { sid, events: sorted, lastTime: sorted[sorted.length-1]?.server_time||'', hasCheckout, hasPix, hasPurchase, isBounce };
            })
            .sort((a,b) => b.lastTime.localeCompare(a.lastTime));

        if (sessionFilter === 'converted') sessionList = sessionList.filter(s => s.hasPix || s.hasPurchase);
        else if (sessionFilter === 'abandoned') sessionList = sessionList.filter(s => s.hasCheckout && !s.hasPix);
        else if (sessionFilter === 'bounce') sessionList = sessionList.filter(s => s.isBounce);

        sessionList = sessionList.slice(0, 30);

        if (!sessionList.length) { container.innerHTML = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Nenhuma sessao encontrada</div>'; return; }

        let html = '';
        sessionList.forEach(session => {
            const shortSid = session.sid.replace('ses_','').substring(0,12);
            const badge = session.hasPurchase ? '<span style="color:#10b981;font-size:0.68rem;font-weight:700"> COMPROU</span>'
                : session.hasPix ? '<span style="color:#fbbf24;font-size:0.68rem;font-weight:700"> PIX GERADO</span>'
                : session.hasCheckout ? '<span style="color:#f87171;font-size:0.68rem;font-weight:700"> ABANDONOU CHECKOUT</span>'
                : session.isBounce ? '<span style="color:#71717a;font-size:0.68rem"> BOUNCE</span>' : '';

            const pages = [...new Set(session.events.map(e => e.page).filter(Boolean))];
            const journey = pages.join(' → ');

            html += `<div style="margin-bottom:0.75rem;padding:0.75rem;background:#111114;border-radius:8px;border:1px solid #1f1f23">`;
            html += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem">
                <span style="font-size:0.75rem;color:#60a5fa;font-weight:600">Sessao ${esc(shortSid)} — ${session.events.length} evento(s)${badge}</span>
                <span style="font-size:0.68rem;color:#52525b">${session.lastTime ? new Date(session.lastTime).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}) : ''}</span>
            </div>`;
            html += `<div style="font-size:0.72rem;color:#71717a;margin-bottom:0.5rem">Jornada: ${esc(journey)}</div>`;

            session.events.forEach(evt => {
                const time = evt.server_time ? new Date(evt.server_time).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}) : '';
                let evtClass = 'view';
                if (evt.event === 'form_error') evtClass = 'error';
                else if (evt.event?.indexOf('Pix')!==-1 || evt.event?.indexOf('pix')!==-1) evtClass = 'pix';
                else if (evt.event?.indexOf('checkout')!==-1 || evt.event==='InitiateCheckout' || evt.event==='AddPaymentInfo') evtClass = 'checkout';
                else if (evt.event==='AddToCart' || evt.event==='Purchase') evtClass = 'action';
                const dataStr = evt.data ? Object.entries(evt.data).map(([k,v])=>`${k}=${typeof v==='object'?JSON.stringify(v):v}`).join(', ').substring(0,80) : '';
                html += `<div class="session-row"><span class="session-time">${time}</span><span class="session-event ${evtClass}">${esc(evt.event||'?')}</span><span class="session-page">${esc(evt.page||'')}</span><span class="session-data">${esc(dataStr)}</span></div>`;
            });
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function renderAnalyticsTable() {
        const body = document.getElementById('analyticsBody');
        if (!body) return;
        const events = getFilteredAnalytics();
        let filtered = events;
        if (analyticsFilter !== 'all') {
            filtered = events.filter(e => {
                if (analyticsFilter === 'funnel_view') return e.event==='funnel_view' || e.event==='ViewContent';
                if (analyticsFilter === 'checkout') return e.event?.indexOf('checkout')!==-1 || e.event==='InitiateCheckout' || e.event==='AddPaymentInfo' || e.event==='AddToCart';
                if (analyticsFilter === 'pix') return e.event?.indexOf('Pix')!==-1 || e.event?.indexOf('pix')!==-1 || e.event==='Purchase';
                if (analyticsFilter === 'error') return e.event==='form_error';
                return true;
            });
        }
        const start = (analyticsPage-1)*ITEMS_PER_PAGE;
        const pageItems = filtered.slice(start, start+ITEMS_PER_PAGE);
        body.innerHTML = '';
        pageItems.forEach(evt => {
            const time = evt.server_time ? new Date(evt.server_time).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
            const shortSid = (evt.session_id||'').replace('ses_','').substring(0,10);
            const dataStr = evt.data ? JSON.stringify(evt.data).substring(0,100) : '';
            const utmStr = evt.utms?.utm_source ? `${evt.utms.utm_source}/${evt.utms.utm_campaign||''}` : '';
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${time}</td><td style="font-size:0.72rem;color:#71717a">${esc(shortSid)}</td><td><span style="color:#60a5fa;font-weight:600">${esc(evt.event||'?')}</span></td><td style="font-size:0.78rem">${esc(evt.page||'')}</td><td style="font-size:0.72rem;color:#a1a1aa;max-width:200px;overflow:hidden;text-overflow:ellipsis">${esc(dataStr)}</td><td style="font-size:0.72rem;color:#71717a">${esc(utmStr)}</td>`;
            body.appendChild(tr);
        });
        const totalPages = Math.ceil(filtered.length/ITEMS_PER_PAGE);
        const pagEl = document.getElementById('analyticsPagination');
        if (pagEl) {
            pagEl.innerHTML = '';
            if (totalPages > 1) {
                for (let i=1;i<=Math.min(totalPages,10);i++) {
                    const btn = document.createElement('button');
                    btn.className = (i===analyticsPage?'active':'');
                    btn.textContent = i;
                    btn.onclick = () => { analyticsPage=i; renderAnalyticsTable(); };
                    pagEl.appendChild(btn);
                }
            }
        }
    }

    function filterAnalytics(filter) {
        analyticsFilter = filter;
        analyticsPage = 1;
        document.querySelectorAll('[data-analytics-filter]').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`[data-analytics-filter="${filter}"]`);
        if (btn) btn.classList.add('active');
        renderAnalyticsTable();
    }

    function exportAnalyticsCSV() {
        const events = getFilteredAnalytics();
        if (!events.length) { showToast('Sem eventos para exportar', 'error'); return; }
        let csv = '\ufeff' + 'Data;Sessao;Evento;Pagina;UTM Source;UTM Campaign;UTM Medium;Dados\n';
        events.forEach(e => {
            const time = e.server_time || '';
            const sid = (e.session_id||'').replace('ses_','');
            const dataStr = e.data ? JSON.stringify(e.data).replace(/;/g,',') : '';
            csv += [time, sid, e.event||'', e.page||'', e.utms?.utm_source||'', e.utms?.utm_campaign||'', e.utms?.utm_medium||'', dataStr].join(';') + '\n';
        });
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'analytics_funil_' + new Date().toISOString().substring(0,10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        showToast('CSV exportado com ' + events.length + ' eventos!', 'success');
    }

    // ════════════════════════════════════════════
    //  TIKTOK TAB
    // ════════════════════════════════════════════
    let ttRendered = false;
    let ttSalesPage = 1;
    let ttCampaignPage = 1;
    let ttSalesFilter = 'all';

    function isTikTokPayment(p) {
        const t = p.tracking || {};
        return !!(t.ttclid || (t.utm_source && t.utm_source.toLowerCase().indexOf('tiktok') !== -1) ||
                  (t.src && t.src.toLowerCase().indexOf('tiktok') !== -1));
    }

    function getTikTokPayments() {
        return filteredPayments.filter(isTikTokPayment);
    }

    function renderTikTok() {
        renderTTKpis();
        renderTTCharts();
        renderTTCampaigns();
        renderTTEventDelivery();
        renderTTEventTypes();
        renderTTSales();
        renderTTFailedEvents();
    }

    function renderTTKpis() {
        const container = document.getElementById('ttKpis');
        if (!container) return;

        const ttPayments = getTikTokPayments();
        const allPayments = filteredPayments;
        const ttPix = ttPayments.length;
        const ttPaid = ttPayments.filter(p => p.status === 'paid');
        const ttRevenue = ttPaid.reduce((s, p) => s + (p.amount || 0), 0);
        const ttCvr = ttPix > 0 ? ((ttPaid.length / ttPix) * 100).toFixed(1) : '0.0';
        const ttTicket = ttPaid.length > 0 ? Math.round(ttRevenue / ttPaid.length) : 0;
        const ttShare = allPayments.length > 0 ? ((ttPix / allPayments.length) * 100).toFixed(1) : '0.0';

        // Count ttclid presence
        const withTTClid = ttPayments.filter(p => p.tracking?.ttclid).length;
        const ttclidRate = ttPix > 0 ? ((withTTClid / ttPix) * 100).toFixed(0) : '0';

        // API success rate
        const ttApiEvents = RAW_API_EVENTS.filter(e => e.api === 'tiktok_events');
        const ttApiSuccess = ttApiEvents.filter(e => e.success).length;
        const ttApiRate = ttApiEvents.length > 0 ? ((ttApiSuccess / ttApiEvents.length) * 100).toFixed(0) : '0';

        // Update header
        const header = document.getElementById('ttHeaderStats');
        if (header) header.textContent = `${ttPaid.length} vendas | ${formatBRL(ttRevenue)} receita | ${ttShare}% do trafego`;

        container.innerHTML = `
            <div class="kpi-card" style="border-top:2px solid #f0abfc"><div class="kpi-label">PIX TikTok</div><div class="kpi-value" style="color:#f0abfc">${ttPix}</div></div>
            <div class="kpi-card" style="border-top:2px solid #10b981"><div class="kpi-label">Vendas TikTok</div><div class="kpi-value green">${ttPaid.length}</div></div>
            <div class="kpi-card" style="border-top:2px solid #34d399"><div class="kpi-label">Receita TikTok</div><div class="kpi-value green">${formatBRL(ttRevenue)}</div></div>
            <div class="kpi-card" style="border-top:2px solid #a78bfa"><div class="kpi-label">CVR TikTok</div><div class="kpi-value purple">${ttCvr}%</div></div>
            <div class="kpi-card" style="border-top:2px solid #fbbf24"><div class="kpi-label">Ticket Medio</div><div class="kpi-value yellow">${formatBRL(ttTicket)}</div></div>
            <div class="kpi-card" style="border-top:2px solid #60a5fa"><div class="kpi-label">% do Total</div><div class="kpi-value blue">${ttShare}%</div></div>
            <div class="kpi-card" style="border-top:2px solid #06b6d4"><div class="kpi-label">Com ttclid</div><div class="kpi-value" style="color:#06b6d4">${ttclidRate}% (${withTTClid})</div></div>
            <div class="kpi-card" style="border-top:2px solid ${parseInt(ttApiRate) >= 90 ? '#10b981' : '#f87171'}"><div class="kpi-label">API Success</div><div class="kpi-value" style="color:${parseInt(ttApiRate) >= 90 ? '#34d399' : '#f87171'}">${ttApiRate}%</div></div>
        `;
    }

    function renderTTCharts() {
        const ttPayments = getTikTokPayments();
        const salesByDay = {};
        const revenueByDay = {};

        ttPayments.forEach(p => {
            const d = getDateStr(p.created_at);
            if (!d) return;
            if (!salesByDay[d]) { salesByDay[d] = 0; revenueByDay[d] = 0; }
            if (p.status === 'paid') {
                salesByDay[d]++;
                revenueByDay[d] += (p.amount || 0);
            }
        });

        const from = document.getElementById('dateFrom').value;
        const to = document.getElementById('dateTo').value || new Date().toISOString().substring(0, 10);
        if (from) {
            let cur = new Date(from + 'T00:00:00');
            const end = new Date(to + 'T00:00:00');
            while (cur <= end) {
                const ds = cur.toISOString().substring(0, 10);
                if (!salesByDay[ds]) salesByDay[ds] = 0;
                if (!revenueByDay[ds]) revenueByDay[ds] = 0;
                cur.setDate(cur.getDate() + 1);
            }
        }

        const days = Object.keys(salesByDay).sort();
        const salesData = days.map(d => ({ label: d, value: salesByDay[d] }));
        const revenueData = days.map(d => ({ label: d, value: revenueByDay[d] / 100 }));

        drawBarChart('chartTTSales', salesData, '#f0abfc', v => Math.round(v).toString());
        drawBarChart('chartTTRevenue', revenueData, '#c084fc', v => 'R$ ' + Math.round(v).toLocaleString('pt-BR'));
    }

    function renderTTCampaigns() {
        const ttPayments = getTikTokPayments();
        const map = {};

        ttPayments.forEach(p => {
            const t = p.tracking || {};
            const campaign = t.utm_campaign || '(sem campanha)';
            const medium = t.utm_medium || '-';
            const content = t.utm_content || '-';
            const key = [campaign, medium, content].join('||');

            if (!map[key]) map[key] = { campaign, medium, content, pix: 0, sales: 0, revenue: 0 };
            map[key].pix++;
            if (p.status === 'paid') {
                map[key].sales++;
                map[key].revenue += (p.amount || 0);
            }
        });

        const data = Object.values(map).sort((a, b) => b.revenue - a.revenue);
        const tbody = document.getElementById('ttCampaignBody');
        if (!tbody) return;

        const perPage = 15;
        const totalPages = Math.max(1, Math.ceil(data.length / perPage));
        if (ttCampaignPage > totalPages) ttCampaignPage = totalPages;
        const start = (ttCampaignPage - 1) * perPage;
        const pageData = data.slice(start, start + perPage);

        if (!pageData.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="no-data">Sem dados TikTok</td></tr>';
            document.getElementById('ttCampaignPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = pageData.map(r => {
            const cvr = r.pix > 0 ? ((r.sales / r.pix) * 100).toFixed(1) : '0.0';
            const ticket = r.sales > 0 ? Math.round(r.revenue / r.sales) : 0;
            return `<tr>
                <td>${esc(r.campaign)}</td>
                <td>${esc(r.medium)}</td>
                <td>${esc(r.content)}</td>
                <td>${r.pix}</td>
                <td>${r.sales}</td>
                <td>${formatBRL(r.revenue)}</td>
                <td>${cvr}%</td>
                <td>${formatBRL(ticket)}</td>
            </tr>`;
        }).join('');

        const pagDiv = document.getElementById('ttCampaignPagination');
        if (totalPages <= 1) { pagDiv.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= Math.min(totalPages, 10); i++) {
            html += `<button class="${i===ttCampaignPage?'active':''}" onclick="ttCampaignPage=${i};renderTTCampaigns()">${i}</button>`;
        }
        pagDiv.innerHTML = html;
    }

    function renderTTEventDelivery() {
        const container = document.getElementById('ttEventDelivery');
        if (!container) return;

        const ttEvents = RAW_API_EVENTS.filter(e => e.api === 'tiktok_events');
        const total = ttEvents.length;
        const success = ttEvents.filter(e => e.success).length;
        const failed = total - success;
        const rate = total > 0 ? ((success / total) * 100).toFixed(1) : '0.0';

        // Group by HTTP status
        const statusCounts = {};
        ttEvents.forEach(e => {
            const s = e.http_status || 'N/A';
            statusCounts[s] = (statusCounts[s] || 0) + 1;
        });

        let html = '';
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Total Eventos</span><span class="analytics-stat-value">${total}</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Sucesso</span><span class="analytics-stat-value good">${success}</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Falhas</span><span class="analytics-stat-value ${failed > 0 ? 'bad' : ''}">${failed}</span></div>`;
        html += `<div class="analytics-stat-row"><span class="analytics-stat-label">Taxa Sucesso</span><span class="analytics-stat-value ${parseFloat(rate) >= 90 ? 'good' : 'bad'}">${rate}%</span></div>`;

        // Status breakdown
        html += '<div style="margin-top:0.75rem">';
        Object.entries(statusCounts).sort((a,b) => b[1] - a[1]).forEach(([status, count]) => {
            const pct = total > 0 ? ((count / total) * 100).toFixed(0) : 0;
            const color = status == 200 ? '#10b981' : '#f87171';
            html += `<div class="pix-bar-wrap">
                <span class="pix-bar-label">HTTP ${status}</span>
                <div class="pix-bar-bg"><div class="pix-bar-fill" style="width:${pct}%;background:${color}"></div></div>
                <span class="pix-bar-value">${count}</span>
            </div>`;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    function renderTTEventTypes() {
        const container = document.getElementById('ttEventTypes');
        if (!container) return;

        const ttEvents = RAW_API_EVENTS.filter(e => e.api === 'tiktok_events');
        const byType = {};
        ttEvents.forEach(e => {
            const name = e.event || '?';
            if (!byType[name]) byType[name] = { total: 0, success: 0 };
            byType[name].total++;
            if (e.success) byType[name].success++;
        });

        const sorted = Object.entries(byType).sort((a, b) => b[1].total - a[1].total);

        if (sorted.length === 0) {
            container.innerHTML = '<div style="color:#71717a;font-size:0.82rem;padding:1rem 0">Sem eventos TikTok ainda</div>';
            return;
        }

        let html = '';
        sorted.forEach(([name, data]) => {
            const rate = data.total > 0 ? ((data.success / data.total) * 100).toFixed(0) : 0;
            const color = parseInt(rate) >= 90 ? '#10b981' : parseInt(rate) >= 50 ? '#fbbf24' : '#f87171';
            html += `<div class="analytics-stat-row">
                <span class="analytics-stat-label">${esc(name)}</span>
                <span class="analytics-stat-value">${data.success}/${data.total} <span style="color:${color};font-size:0.72rem">(${rate}%)</span></span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function filterTTSales(type) {
        ttSalesFilter = type;
        ttSalesPage = 1;
        document.querySelectorAll('[data-tt-filter]').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`[data-tt-filter="${type}"]`);
        if (btn) btn.classList.add('active');
        renderTTSales();
    }

    function renderTTSales() {
        let ttPayments = getTikTokPayments();
        if (ttSalesFilter === 'paid') ttPayments = ttPayments.filter(p => p.status === 'paid');
        else if (ttSalesFilter === 'pending') ttPayments = ttPayments.filter(p => p.status === 'pending');
        else if (ttSalesFilter === 'failed') ttPayments = ttPayments.filter(p => p.status === 'failed');

        const data = [...ttPayments].sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''));
        const tbody = document.getElementById('ttSalesBody');
        if (!tbody) return;

        const perPage = 15;
        const totalPages = Math.max(1, Math.ceil(data.length / perPage));
        if (ttSalesPage > totalPages) ttSalesPage = totalPages;
        const start = (ttSalesPage - 1) * perPage;
        const pageData = data.slice(start, start + perPage);

        if (!pageData.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="no-data">Sem vendas TikTok</td></tr>';
            document.getElementById('ttSalesPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = pageData.map(p => {
            const t = p.tracking || {};
            const statusCls = p.status === 'paid' ? 'badge-paid' : p.status === 'failed' ? 'badge-failed' : 'badge-pending';
            const statusLabel = p.status === 'paid' ? 'Pago' : p.status === 'failed' ? 'Falhou' : 'Pendente';
            const pc = esc(p.payment_code || '');
            const ttclid = t.ttclid ? t.ttclid.substring(0, 12) + '...' : '-';

            return `<tr class="clickable-row" onclick="openSaleDetail('${pc}')">
                <td>${formatDateBR(p.created_at)}</td>
                <td>${esc(p.customer?.name || '-')}</td>
                <td>${formatBRL(p.amount || 0)}</td>
                <td><span class="badge ${statusCls}">${statusLabel}</span></td>
                <td>${esc(t.utm_campaign || '-')}</td>
                <td>${esc(t.utm_medium || '-')}</td>
                <td style="font-family:monospace;font-size:0.72rem;color:#f0abfc">${esc(ttclid)}</td>
                <td><button class="btn-detail" onclick="event.stopPropagation();openSaleDetail('${pc}')">Ver</button></td>
            </tr>`;
        }).join('');

        const pagDiv = document.getElementById('ttSalesPagination');
        if (totalPages <= 1) { pagDiv.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= Math.min(totalPages, 10); i++) {
            html += `<button class="${i===ttSalesPage?'active':''}" onclick="ttSalesPage=${i};renderTTSales()">${i}</button>`;
        }
        pagDiv.innerHTML = html;
    }

    function renderTTFailedEvents() {
        const tbody = document.getElementById('ttFailedBody');
        if (!tbody) return;

        const failed = RAW_API_EVENTS.filter(e => e.api === 'tiktok_events' && !e.success && !e.retried)
            .map((e, idx) => ({ ...e, originalIndex: RAW_API_EVENTS.indexOf(e) }))
            .reverse();

        if (!failed.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="no-data" style="color:#34d399">Nenhum evento com erro pendente!</td></tr>';
            return;
        }

        tbody.innerHTML = failed.slice(0, 50).map(e => {
            let errMsg = '-';
            try {
                const resp = typeof e.response === 'string' ? JSON.parse(e.response) : e.response;
                errMsg = resp?.message || resp?.error || JSON.stringify(resp).substring(0, 100);
            } catch(x) {
                errMsg = typeof e.response === 'string' ? e.response.substring(0, 100) : '-';
            }

            return `<tr>
                <td>${formatDateBR(e.timestamp)}</td>
                <td style="font-family:monospace;font-size:0.72rem">${esc(e.payment_code || '-')}</td>
                <td>${esc(e.event || '-')}</td>
                <td><span class="http-err" style="padding:0.15rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:600">${e.http_status || '-'}</span></td>
                <td style="font-size:0.72rem;color:#fca5a5;max-width:200px;overflow:hidden;text-overflow:ellipsis">${esc(errMsg)}</td>
                <td><button class="btn btn-sm" style="background:#4a044e;color:#f0abfc;border:1px solid #86198f;font-size:0.7rem" onclick="event.stopPropagation();retrySingleTikTok(${e.originalIndex})">Retry</button></td>
            </tr>`;
        }).join('');
    }

    // ── Retry TikTok Functions ──
    function retryFailedTikTok() {
        const failed = RAW_API_EVENTS.filter(e => e.api === 'tiktok_events' && !e.success && !e.retried);
        if (failed.length === 0) {
            showToast('Nenhum evento TikTok com erro para reenviar!', 'success');
            return;
        }
        if (!confirm(`Reenviar ${failed.length} evento(s) TikTok com erro?`)) return;

        const btns = document.querySelectorAll('#retryTTBtn, #retryTTBtn2');
        btns.forEach(b => { if (b) { b.disabled = true; b.textContent = 'Reenviando...'; } });

        fetch('/api/retry-tiktok.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: 'ml2025', retry_all_failed: true })
        })
        .then(r => r.json())
        .then(data => {
            btns.forEach(b => { if (b) { b.disabled = false; b.textContent = '\u21BB Retry TikTok com erro'; } });
            if (data.success) {
                showToast(`Retry concluido! ${data.success_count} sucesso, ${data.fail_count} falha(s)`, data.fail_count > 0 ? 'error' : 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast('Erro: ' + (data.error || 'falha no retry'), 'error');
            }
        })
        .catch(() => {
            btns.forEach(b => { if (b) { b.disabled = false; b.textContent = '\u21BB Retry TikTok com erro'; } });
            showToast('Erro de rede', 'error');
        });
    }

    function retrySingleTikTok(eventIndex) {
        if (!confirm('Reenviar este evento TikTok?')) return;

        fetch('/api/retry-tiktok.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: 'ml2025', event_indices: [eventIndex] })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.success_count > 0) {
                showToast('Evento reenviado com sucesso!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                const errMsg = data.results?.[0]?.response?.message || 'Falha no retry';
                showToast('Erro: ' + errMsg, 'error');
            }
        })
        .catch(() => showToast('Erro de rede', 'error'));
    }

    // ════════════════════════════════════════════
    //  FEATURE FLAGS TAB
    // ════════════════════════════════════════════
    function loadFeatureFlags() {
        fetch('../api/feature-flags.php')
        .then(r => r.json())
        .then(data => {
            // Killswitch
            const ks = document.getElementById('flagKillswitch');
            if (ks) ks.checked = data.global_killswitch === true;

            // Updated at
            const updEl = document.getElementById('flagsUpdatedAt');
            if (updEl) updEl.textContent = data.updated_at || 'Nunca';

            // Render flags
            const container = document.getElementById('flagsList');
            if (!container) return;
            container.innerHTML = '';

            const flags = data.flags || {};
            for (const [name, flag] of Object.entries(flags)) {
                const card = document.createElement('div');
                card.style.cssText = 'background:#18181b;border:1px solid #27272a;border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between';
                card.innerHTML = `
                    <div>
                        <div style="color:#fff;font-weight:600;font-size:0.95rem">${esc(name)}</div>
                        <div style="color:#71717a;font-size:0.8rem;margin-top:0.2rem">${esc(flag.description || '')}</div>
                    </div>
                    <label class="toggle-label" style="margin:0">
                        <div class="toggle-switch">
                            <input type="checkbox" ${flag.enabled ? 'checked' : ''} onchange="toggleFlag('${esc(name)}', this.checked)">
                            <span class="toggle-slider"></span>
                        </div>
                    </label>`;
                container.appendChild(card);
            }
        })
        .catch(() => showToast('Erro ao carregar flags', 'error'));
    }

    function toggleFlag(name, enabled) {
        fetch('../api/feature-flags.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: '<?php echo $DASHBOARD_PASSWORD; ?>', flag: name, enabled: enabled })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`Flag "${name}" ${enabled ? 'ativada' : 'desativada'}`);
                const updEl = document.getElementById('flagsUpdatedAt');
                if (updEl) updEl.textContent = data.flags?.updated_at || 'Agora';
            } else {
                showToast('Erro: ' + (data.error || 'falha'), 'error');
            }
        })
        .catch(() => showToast('Erro de rede', 'error'));
    }

    function toggleKillswitch(enabled) {
        fetch('../api/feature-flags.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: '<?php echo $DASHBOARD_PASSWORD; ?>', global_killswitch: enabled })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(enabled ? '🔴 KILLSWITCH ATIVADO - Todas as mudanças desativadas' : '🟢 Killswitch desativado', enabled ? 'error' : 'success');
            } else {
                showToast('Erro: ' + (data.error || 'falha'), 'error');
            }
        })
        .catch(() => showToast('Erro de rede', 'error'));
    }

    // ════════════════════════════════════════════
    //  CRO AUDIT TAB
    // ════════════════════════════════════════════
    function renderCRO() {
        // Funnel waterfall from analytics events
        const events = RAW_ANALYTICS;
        const sessions = {};
        events.forEach(e => {
            const sid = e.session_id || '';
            if (!sessions[sid]) sessions[sid] = { pages: new Set(), events: [] };
            sessions[sid].pages.add(e.page || '');
            sessions[sid].events.push(e);
        });

        // Count sessions by funnel stage
        const funnelStages = [
            { key: 'prevsl', label: 'Pre-Sell', color: '#3b82f6' },
            { key: 'questionario', label: 'Questionário', color: '#8b5cf6' },
            { key: 'roleta', label: 'Roleta', color: '#06b6d4' },
            { key: 'recompensas', label: 'Recompensas', color: '#10b981' },
            { key: 'produto:', label: 'Produto', color: '#f59e0b' },
            { key: 'checkout', label: 'Checkout', color: '#ef4444' }
        ];

        const stageCounts = funnelStages.map(stage => {
            let count = 0;
            Object.values(sessions).forEach(s => {
                const pages = Array.from(s.pages);
                if (pages.some(p => p.indexOf(stage.key) !== -1)) count++;
            });
            return { ...stage, count };
        });

        const waterfallEl = document.getElementById('croWaterfall');
        if (waterfallEl) {
            waterfallEl.innerHTML = stageCounts.map((s, i) => {
                const prev = i > 0 ? stageCounts[i-1].count : s.count;
                const drop = prev > 0 ? ((prev - s.count) / prev * 100).toFixed(1) : 0;
                const dropColor = drop > 40 ? '#ef4444' : drop > 20 ? '#f59e0b' : '#10b981';
                return `<div class="kpi-card">
                    <div class="kpi-label">${esc(s.label)}</div>
                    <div class="kpi-value" style="color:${s.color}">${s.count}</div>
                    ${i > 0 ? `<div style="font-size:0.75rem;color:${dropColor};margin-top:0.3rem">↓ ${drop}% drop</div>` : ''}
                </div>`;
            }).join('');
        }

        // PIX Health Metrics
        const totalPayments = RAW_PAYMENTS.length;
        const paidPayments = RAW_PAYMENTS.filter(p => p.status === 'paid');
        const failedPayments = RAW_PAYMENTS.filter(p => p.status === 'failed');
        const pendingPayments = RAW_PAYMENTS.filter(p => p.status === 'pending');

        const skPayments = RAW_PAYMENTS.filter(p => (p.gateway || '') === 'skalepay');
        const npPayments = RAW_PAYMENTS.filter(p => (p.gateway || '') === 'nitropagamento');
        const mgPayments = RAW_PAYMENTS.filter(p => (p.gateway || '') !== 'skalepay' && (p.gateway || '') !== 'nitropagamento');
        const skPaid = skPayments.filter(p => p.status === 'paid').length;
        const npPaidCro = npPayments.filter(p => p.status === 'paid').length;
        const mgPaid = mgPayments.filter(p => p.status === 'paid').length;
        const skRate = skPayments.length > 0 ? (skPaid / skPayments.length * 100).toFixed(1) : '0.0';
        const npRate = npPayments.length > 0 ? (npPaidCro / npPayments.length * 100).toFixed(1) : '0.0';
        const mgRate = mgPayments.length > 0 ? (mgPaid / mgPayments.length * 100).toFixed(1) : '0.0';

        // Average time to payment
        const times = paidPayments.map(p => {
            const c = new Date(p.created_at);
            const pa = new Date(p.paid_at);
            return (pa - c) / 1000 / 60; // minutes
        }).filter(t => t > 0 && t < 120);
        const avgTime = times.length > 0 ? (times.reduce((a,b) => a+b, 0) / times.length).toFixed(1) : '—';

        const pixHealthEl = document.getElementById('croPixHealth');
        if (pixHealthEl) {
            pixHealthEl.innerHTML = `
                <div class="kpi-card"><div class="kpi-label">PIX Gerados</div><div class="kpi-value">${totalPayments}</div></div>
                <div class="kpi-card"><div class="kpi-label">Taxa Pagamento</div><div class="kpi-value green">${totalPayments > 0 ? (paidPayments.length / totalPayments * 100).toFixed(1) : 0}%</div></div>
                <div class="kpi-card"><div class="kpi-label">SkalePay Rate</div><div class="kpi-value" style="color:#a78bfa">${skRate}% <span style="font-size:0.7rem;color:#71717a">(${skPayments.length})</span></div></div>
                <div class="kpi-card"><div class="kpi-label">Mangofy Rate</div><div class="kpi-value" style="color:#34d399">${mgRate}% <span style="font-size:0.7rem;color:#71717a">(${mgPayments.length})</span></div></div>
                ${npPayments.length > 0 ? '<div class="kpi-card"><div class="kpi-label">NitroPag Rate</div><div class="kpi-value" style="color:#fbbf24">' + npRate + '% <span style="font-size:0.7rem;color:#71717a">(' + npPayments.length + ')</span></div></div>' : ''}
                <div class="kpi-card"><div class="kpi-label">Tempo Médio (min)</div><div class="kpi-value">${avgTime}</div></div>
                <div class="kpi-card"><div class="kpi-label">Falhas Gateway</div><div class="kpi-value" style="color:#f87171">${failedPayments.length}</div></div>
                <div class="kpi-card"><div class="kpi-label">Pendentes</div><div class="kpi-value" style="color:#fbbf24">${pendingPayments.length}</div></div>
                <div class="kpi-card"><div class="kpi-label">R$ Pendente</div><div class="kpi-value" style="color:#fbbf24">${formatBRL(pendingPayments.reduce((s,p) => s + (p.amount||0), 0))}</div></div>
            `;
        }

        // Repeat customers
        const emailCount = {};
        RAW_PAYMENTS.forEach(p => {
            const email = (p.customer?.email || '').toLowerCase();
            if (!email) return;
            if (!emailCount[email]) emailCount[email] = { name: p.customer?.name || '', total: 0, paid: 0, pending: 0, pendingAmount: 0 };
            emailCount[email].total++;
            if (p.status === 'paid') emailCount[email].paid++;
            else if (p.status === 'pending') {
                emailCount[email].pending++;
                emailCount[email].pendingAmount += (p.amount || 0);
            }
        });

        const repeats = Object.entries(emailCount)
            .filter(([_, d]) => d.total > 3)
            .sort((a, b) => b[1].total - a[1].total);

        const repeatEl = document.getElementById('croRepeatTable');
        if (repeatEl) {
            repeatEl.innerHTML = repeats.length === 0
                ? '<tr><td colspan="6" style="text-align:center;color:#71717a;padding:1rem">Nenhum cliente com >3 pedidos</td></tr>'
                : repeats.map(([email, d]) => `<tr>
                    <td>${esc(email)}</td><td>${esc(d.name)}</td>
                    <td>${d.total}</td><td style="color:#10b981">${d.paid}</td>
                    <td style="color:#fbbf24">${d.pending}</td>
                    <td style="color:#fbbf24">${formatBRL(d.pendingAmount)}</td>
                </tr>`).join('');
        }

        // Checkout step drops
        const stepEvents = events.filter(e => e.event === 'checkout_step');
        const stepCounts = {};
        stepEvents.forEach(e => {
            const step = e.data?.step || 0;
            const sid = e.session_id;
            if (!stepCounts[step]) stepCounts[step] = new Set();
            stepCounts[step].add(sid);
        });

        const stepNames = { 1: 'Carrinho', 2: 'Dados', 3: 'Endereço', 4: 'Envio', 5: 'Pagamento' };
        const dropsEl = document.getElementById('croCheckoutDrops');
        if (dropsEl) {
            let html = '';
            for (let s = 1; s <= 5; s++) {
                const count = stepCounts[s] ? stepCounts[s].size : 0;
                const prev = s > 1 ? (stepCounts[s-1] ? stepCounts[s-1].size : 0) : count;
                const drop = prev > 0 ? ((prev - count) / prev * 100).toFixed(1) : 0;
                const dropColor = drop > 40 ? '#ef4444' : drop > 20 ? '#f59e0b' : '#10b981';
                html += `<div class="kpi-card">
                    <div class="kpi-label">Step ${s}: ${stepNames[s]}</div>
                    <div class="kpi-value">${count}</div>
                    ${s > 1 ? `<div style="font-size:0.75rem;color:${dropColor};margin-top:0.3rem">↓ ${drop}% drop</div>` : ''}
                </div>`;
            }
            dropsEl.innerHTML = html;
        }
    }

    // ════════════════════════════════════════════
    //  EXPERIMENTS TAB
    // ════════════════════════════════════════════
    let experimentsData = {};

    function loadExperiments() {
        fetch('../api/experiments.php')
        .then(r => r.json())
        .then(data => {
            experimentsData = data.experiments || {};
            renderExperimentsTable();
        })
        .catch(() => {
            // experiments.php may not exist yet
            document.getElementById('experimentsTable').innerHTML =
                '<tr><td colspan="7" style="text-align:center;color:#71717a;padding:1rem">Nenhum experimento configurado</td></tr>';
        });
    }

    function renderExperimentsTable() {
        const tbody = document.getElementById('experimentsTable');
        if (!tbody) return;

        const exps = Object.entries(experimentsData);
        if (exps.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#71717a;padding:1rem">Nenhum experimento configurado</td></tr>';
            document.getElementById('experimentResults').innerHTML = '';
            return;
        }

        tbody.innerHTML = exps.map(([id, exp]) => {
            const variants = Object.keys(exp.variants || {}).join(', ');
            const statusColors = { draft: '#71717a', running: '#10b981', paused: '#f59e0b', completed: '#3b82f6' };
            const statusColor = statusColors[exp.status] || '#71717a';
            const samples = Object.values(exp.current_sample || {}).reduce((a,b) => a+b, 0);

            let actions = '';
            if (exp.status === 'draft') {
                actions = `<button class="btn btn-sm btn-primary" onclick="updateExpStatus('${esc(id)}','running')">▶ Iniciar</button>`;
            } else if (exp.status === 'running') {
                actions = `<button class="btn btn-sm btn-secondary" onclick="updateExpStatus('${esc(id)}','paused')">⏸ Pausar</button>
                           <button class="btn btn-sm btn-danger" onclick="updateExpStatus('${esc(id)}','completed')">⏹ Encerrar</button>`;
            } else if (exp.status === 'paused') {
                actions = `<button class="btn btn-sm btn-primary" onclick="updateExpStatus('${esc(id)}','running')">▶ Retomar</button>`;
            }

            return `<tr style="cursor:pointer" onclick="renderExperimentResults('${esc(id)}')">
                <td style="font-family:monospace;font-size:0.75rem">${esc(id)}</td>
                <td>${esc(exp.name || '')}</td>
                <td><span style="color:${statusColor};font-weight:600">${esc(exp.status || 'draft')}</span></td>
                <td style="font-size:0.8rem">${esc(variants)}</td>
                <td style="font-size:0.8rem">${esc(exp.metric || exp.metric_primary || '')}</td>
                <td>${samples}</td>
                <td>${actions}</td>
            </tr>`;
        }).join('');

        // Auto-show results for running experiments
        const running = exps.filter(([_, e]) => e.status === 'running');
        if (running.length > 0) {
            renderExperimentResults(running[0][0]);
        } else if (exps.length > 0) {
            renderExperimentResults(exps[0][0]);
        }
    }

    // ── Z-Test for proportions (two-tailed) ──
    function zTestProportions(n1, c1, n2, c2) {
        if (n1 === 0 || n2 === 0) return { z: 0, p: 1, significant: false };
        const p1 = c1 / n1;
        const p2 = c2 / n2;
        const pPool = (c1 + c2) / (n1 + n2);
        const se = Math.sqrt(pPool * (1 - pPool) * (1/n1 + 1/n2));
        if (se === 0) return { z: 0, p: 1, significant: false };
        const z = (p1 - p2) / se;
        // Approximate p-value from z-score (two-tailed)
        const pVal = 2 * (1 - normalCDF(Math.abs(z)));
        return { z: z, p: pVal, significant: pVal < 0.05, p1, p2, lift: p2 !== 0 ? ((p1 - p2) / p2 * 100) : 0 };
    }

    // Standard normal CDF approximation (Abramowitz and Stegun)
    function normalCDF(x) {
        const a1 = 0.254829592, a2 = -0.284496736, a3 = 1.421413741;
        const a4 = -1.453152027, a5 = 1.061405429, p = 0.3275911;
        const sign = x < 0 ? -1 : 1;
        x = Math.abs(x) / Math.sqrt(2);
        const t = 1.0 / (1.0 + p * x);
        const y = 1.0 - (((((a5*t + a4)*t) + a3)*t + a2)*t + a1)*t * Math.exp(-x*x);
        return 0.5 * (1.0 + sign * y);
    }

    function renderExperimentResults(expId) {
        const container = document.getElementById('experimentResults');
        if (!container) return;

        const exp = experimentsData[expId];
        if (!exp) { container.innerHTML = ''; return; }

        const variantNames = Object.keys(exp.variants || {});
        if (variantNames.length === 0) { container.innerHTML = '<div style="color:#71717a;padding:1rem">Sem variantes definidas</div>'; return; }

        // Compute per-variant metrics from payments data
        const variantData = {};
        variantNames.forEach(v => { variantData[v] = { sessions: 0, pixGenerated: 0, pixPaid: 0, revenue: 0 }; });

        // Count events/payments by variant
        RAW_PAYMENTS.forEach(p => {
            const vid = p.variant_id || p.metadata?.variant_id || '';
            const eid = p.experiment_id || p.metadata?.experiment_id || '';
            if (eid !== expId) return;
            if (!variantData[vid]) return;

            variantData[vid].pixGenerated++;
            if (p.status === 'paid') {
                variantData[vid].pixPaid++;
                variantData[vid].revenue += (p.amount || 0);
            }
        });

        // Build results table
        let html = `<div class="card">
            <div class="card-header"><h3>${esc(exp.name || expId)}</h3>
            <span style="font-size:0.8rem;color:#71717a">Métrica: ${esc(exp.metric || exp.metric_primary || 'pix_paid_rate')}</span></div>
            <div class="table-scroll"><table>
                <thead><tr>
                    <th>Variante</th><th>Peso</th><th>PIX Gerado</th><th>PIX Pago</th>
                    <th>Taxa Pgto</th><th>Receita</th><th>Lift vs Control</th><th>Significância</th>
                </tr></thead><tbody>`;

        const controlData = variantData['control'] || variantData[variantNames[0]];
        const controlN = controlData.pixGenerated;
        const controlC = controlData.pixPaid;

        variantNames.forEach(v => {
            const d = variantData[v];
            const weight = exp.variants[v]?.weight || 0;
            const rate = d.pixGenerated > 0 ? (d.pixPaid / d.pixGenerated * 100).toFixed(1) : '—';
            const rateColor = d.pixGenerated > 0 ? (d.pixPaid / d.pixGenerated > (controlC / Math.max(controlN, 1)) ? '#10b981' : '#ef4444') : '#71717a';

            let liftHtml = '—';
            let sigHtml = '<span style="color:#71717a">—</span>';

            if (v !== 'control' && v !== variantNames[0] && d.pixGenerated >= 10 && controlN >= 10) {
                const test = zTestProportions(d.pixGenerated, d.pixPaid, controlN, controlC);
                const liftDir = test.lift > 0 ? '+' : '';
                const liftColor = test.lift > 0 ? '#10b981' : '#ef4444';
                liftHtml = `<span style="color:${liftColor};font-weight:600">${liftDir}${test.lift.toFixed(1)}%</span>`;

                if (test.significant) {
                    const winner = test.p1 > test.p2;
                    sigHtml = `<span style="color:${winner ? '#10b981' : '#ef4444'};font-weight:700">
                        ${winner ? '🏆 Winner' : '📉 Losing'} (p=${test.p.toFixed(4)})</span>`;
                } else {
                    const confidence = ((1 - test.p) * 100).toFixed(0);
                    sigHtml = `<span style="color:#f59e0b">${confidence}% conf. (precisa mais dados)</span>`;
                }
            } else if (v === 'control' || v === variantNames[0]) {
                liftHtml = '<span style="color:#71717a">baseline</span>';
                sigHtml = '<span style="color:#71717a">baseline</span>';
            }

            html += `<tr>
                <td><span style="font-weight:600">${esc(v)}</span></td>
                <td>${weight}%</td>
                <td>${d.pixGenerated}</td>
                <td style="color:#10b981;font-weight:600">${d.pixPaid}</td>
                <td style="color:${rateColor};font-weight:600">${rate}%</td>
                <td>${formatBRL(d.revenue)}</td>
                <td>${liftHtml}</td>
                <td>${sigHtml}</td>
            </tr>`;
        });

        const totalSamples = variantNames.reduce((s, v) => s + variantData[v].pixGenerated, 0);
        const minSample = exp.min_sample || exp.min_sample_size || 200;
        const progress = Math.min(100, Math.round(totalSamples / (minSample * variantNames.length) * 100));

        html += `</tbody></table></div>
            <div style="margin-top:1rem;display:flex;align-items:center;gap:1rem">
                <div style="flex:1;background:#27272a;border-radius:8px;height:8px;overflow:hidden">
                    <div style="width:${progress}%;height:100%;background:${progress >= 100 ? '#10b981' : '#3b82f6'};transition:width 0.3s"></div>
                </div>
                <span style="font-size:0.8rem;color:#a1a1aa">${totalSamples} / ${minSample * variantNames.length} amostras (${progress}%)</span>
            </div>
        </div>`;

        container.innerHTML = html;
    }

    function createExperiment() {
        const name = document.getElementById('expName')?.value?.trim();
        if (!name) { showToast('Informe o nome do experimento', 'error'); return; }

        const pages = (document.getElementById('expPages')?.value || '').split(',').map(s => s.trim()).filter(Boolean);
        const weightA = parseInt(document.getElementById('expWeightA')?.value) || 50;
        const weightB = parseInt(document.getElementById('expWeightB')?.value) || 50;
        const metric = document.getElementById('expMetric')?.value || 'pix_paid_rate';

        const expId = 'exp_' + name.toLowerCase().replace(/[^a-z0-9]+/g, '_').substring(0, 30) + '_' + Date.now().toString(36);

        const experiment = {
            id: expId,
            name: name,
            status: 'draft',
            created_at: new Date().toISOString().substring(0, 10),
            target_pages: pages,
            variants: {
                control: { weight: weightA, config: {} },
                variant_a: { weight: weightB, config: {} }
            },
            metric_primary: metric,
            min_sample_size: 200,
            current_sample: { control: 0, variant_a: 0 }
        };

        fetch('../api/experiments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: '<?php echo $DASHBOARD_PASSWORD; ?>', action: 'create', experiment: experiment })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Experimento criado: ' + name);
                document.getElementById('expName').value = '';
                loadExperiments();
            } else {
                showToast('Erro: ' + (data.error || 'falha'), 'error');
            }
        })
        .catch(() => showToast('Erro de rede', 'error'));
    }

    function updateExpStatus(expId, status) {
        fetch('../api/experiments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: '<?php echo $DASHBOARD_PASSWORD; ?>', action: 'update_status', id: expId, status: status })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`Experimento ${status}`);
                loadExperiments();
            } else {
                showToast('Erro: ' + (data.error || 'falha'), 'error');
            }
        })
        .catch(() => showToast('Erro de rede', 'error'));
    }

    // ── Init ──
    document.addEventListener('DOMContentLoaded', () => {
        filteredPayments = [...RAW_PAYMENTS];
        filteredPageviews = [...RAW_PAGEVIEWS];
        applyFilters();
    });
    </script>
<?php endif; ?>
</body>
</html>
