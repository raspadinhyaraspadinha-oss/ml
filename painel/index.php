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
        'Codigo', 'Status', 'Valor', 'Nome', 'Email', 'Telefone', 'Documento',
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 14px; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0f1117;
            color: #e4e4e7;
            line-height: 1.6;
            min-height: 100vh;
        }
        a { color: #60a5fa; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Login ── */
        .login-wrap {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 1rem;
        }
        .login-box {
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 400px;
            text-align: center;
        }
        .login-box h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: #fff; }
        .login-box p { color: #71717a; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .login-box input[type="password"] {
            width: 100%; padding: 0.75rem 1rem; background: #0f1117;
            border: 1px solid #2a2b35; border-radius: 8px; color: #fff;
            font-size: 1rem; font-family: inherit; margin-bottom: 1rem;
            transition: border-color 0.2s;
        }
        .login-box input[type="password"]:focus {
            outline: none; border-color: #3b82f6;
        }
        .login-box button {
            width: 100%; padding: 0.75rem; background: #3b82f6;
            color: #fff; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer;
            font-family: inherit; transition: background 0.2s;
        }
        .login-box button:hover { background: #2563eb; }
        .login-error {
            background: #7f1d1d44; border: 1px solid #991b1b;
            color: #fca5a5; padding: 0.5rem; border-radius: 6px;
            margin-bottom: 1rem; font-size: 0.85rem;
        }

        /* ── Dashboard Layout ── */
        .dashboard { max-width: 1440px; margin: 0 auto; padding: 1.5rem; }

        /* Header */
        .header {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;
            padding-bottom: 1.5rem; border-bottom: 1px solid #1e1f2a;
        }
        .header h1 { font-size: 1.6rem; font-weight: 700; color: #fff; }
        .header-meta { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .header-meta span { font-size: 0.8rem; color: #71717a; }
        .btn {
            padding: 0.5rem 1rem; border-radius: 6px; border: none;
            font-family: inherit; font-size: 0.85rem; font-weight: 500;
            cursor: pointer; transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 0.4rem;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #27272a; color: #e4e4e7; border: 1px solid #3f3f46; }
        .btn-secondary:hover { background: #3f3f46; }
        .btn-danger { background: #7f1d1d; color: #fca5a5; }
        .btn-danger:hover { background: #991b1b; }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.78rem; }

        /* Filter Bar */
        .filter-bar {
            display: flex; align-items: center; gap: 0.75rem;
            flex-wrap: wrap; margin-bottom: 1.5rem;
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; padding: 1rem 1.25rem;
        }
        .filter-bar label { font-size: 0.85rem; font-weight: 500; color: #a1a1aa; }
        .filter-bar input[type="date"] {
            background: #0f1117; border: 1px solid #3f3f46; color: #fff;
            padding: 0.45rem 0.75rem; border-radius: 6px; font-family: inherit;
            font-size: 0.85rem;
        }
        .filter-bar input[type="date"]:focus { outline: none; border-color: #3b82f6; }
        .filter-sep { color: #3f3f46; }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .kpi-card {
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; padding: 1.25rem;
            transition: border-color 0.2s, transform 0.2s;
        }
        .kpi-card:hover { border-color: #3b82f6; transform: translateY(-2px); }
        .kpi-label { font-size: 0.78rem; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 0.5rem; }
        .kpi-value { font-size: 1.75rem; font-weight: 700; color: #fff; }
        .kpi-value.green { color: #34d399; }
        .kpi-value.blue { color: #60a5fa; }
        .kpi-value.yellow { color: #fbbf24; }
        .kpi-value.purple { color: #a78bfa; }

        /* Section Titles */
        .section-title {
            font-size: 1.1rem; font-weight: 600; color: #fff;
            margin-bottom: 1rem; margin-top: 2rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .section-title .dot { width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .chart-card {
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; padding: 1.25rem;
        }
        .chart-card h3 { font-size: 0.95rem; font-weight: 600; color: #fff; margin-bottom: 1rem; }
        .chart-card canvas { width: 100% !important; height: 220px !important; }

        /* Tables */
        .table-wrap {
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem;
        }
        .table-header {
            padding: 1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #2a2b35;
        }
        .table-header h3 { font-size: 0.95rem; font-weight: 600; color: #fff; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 0.75rem 1rem; font-size: 0.78rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.04em;
            color: #71717a; background: #15161d;
            border-bottom: 1px solid #2a2b35; text-align: left;
            white-space: nowrap; cursor: pointer; user-select: none;
            transition: color 0.2s;
        }
        thead th:hover { color: #e4e4e7; }
        thead th.sorted-asc::after { content: ' \25B2'; font-size: 0.65rem; }
        thead th.sorted-desc::after { content: ' \25BC'; font-size: 0.65rem; }
        tbody td {
            padding: 0.65rem 1rem; font-size: 0.85rem;
            border-bottom: 1px solid #1e1f2a; white-space: nowrap;
        }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #1e1f2a; }

        /* Status Badges */
        .badge {
            display: inline-block; padding: 0.2rem 0.6rem;
            border-radius: 20px; font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .badge-paid { background: #064e3b; color: #34d399; }
        .badge-pending { background: #78350f44; color: #fbbf24; }

        /* Event Log */
        .log-container {
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem;
        }
        .log-header {
            padding: 1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #2a2b35;
        }
        .log-header h3 { font-size: 0.95rem; font-weight: 600; color: #fff; }
        .log-body { max-height: 400px; overflow-y: auto; padding: 0.5rem 0; }
        .log-line {
            padding: 0.4rem 1.25rem; font-size: 0.78rem;
            font-family: 'Courier New', monospace;
            border-bottom: 1px solid #1e1f2a;
            word-break: break-all;
        }
        .log-line.pix { color: #60a5fa; }
        .log-line.webhook { color: #fbbf24; }
        .log-line.aprovado { color: #34d399; }

        /* Pagination */
        .pagination {
            display: flex; align-items: center; justify-content: center;
            gap: 0.4rem; padding: 1rem;
        }
        .pagination button {
            padding: 0.4rem 0.8rem; border-radius: 6px;
            border: 1px solid #3f3f46; background: #1a1b23;
            color: #e4e4e7; cursor: pointer; font-family: inherit;
            font-size: 0.8rem; transition: all 0.2s;
        }
        .pagination button:hover { background: #3f3f46; }
        .pagination button.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; }

        /* Settings */
        .settings-bar {
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
            background: #1a1b23; border: 1px solid #2a2b35;
            border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 2rem;
        }
        .toggle-label {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.85rem; color: #a1a1aa; cursor: pointer;
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
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #0f1117; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #52525b; }

        /* Pie chart container */
        .pie-legend { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
        .pie-legend-item { display: flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; color: #a1a1aa; }
        .pie-legend-color { width: 10px; height: 10px; border-radius: 2px; }

        /* No data */
        .no-data { text-align: center; padding: 3rem; color: #71717a; font-size: 0.9rem; }
    </style>
</head>
<body>
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
            <div class="header-meta">
                <span id="lastUpdated">Atualizado: <?php echo date('d/m/Y H:i:s'); ?></span>
                <button class="btn btn-secondary btn-sm" onclick="location.reload()">Atualizar</button>
                <a href="?logout=1" class="btn btn-danger btn-sm">Sair</a>
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
                            <th>Fonte</th>
                            <th>Campanha</th>
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
                <label class="toggle-label">
                    <span>Auto-refresh log</span>
                    <div class="toggle-switch">
                        <input type="checkbox" id="toggleLogRefresh">
                        <span class="toggle-slider"></span>
                    </div>
                </label>
            </div>
            <div class="log-body" id="logBody">
                <?php foreach ($logLines as $line): ?>
                    <?php
                        $cls = '';
                        if (strpos($line, 'PIX_GERADO') !== false) $cls = 'pix';
                        elseif (strpos($line, 'WEBHOOK_RECEBIDO') !== false) $cls = 'webhook';
                        elseif (strpos($line, 'PAGAMENTO_APROVADO') !== false) $cls = 'aprovado';
                    ?>
                    <div class="log-line <?php echo $cls; ?>"><?php echo sanitize($line); ?></div>
                <?php endforeach; ?>
                <?php if (empty($logLines)): ?>
                    <div class="no-data">Nenhum evento registrado</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings -->
        <div class="settings-bar">
            <label class="toggle-label">
                <div class="toggle-switch">
                    <input type="checkbox" id="toggleAutoRefresh">
                    <span class="toggle-slider"></span>
                </div>
                <span>Auto-refresh (30s)</span>
            </label>
            <span style="color:#52525b">|</span>
            <span style="font-size:0.8rem;color:#71717a;">Dados carregados de: api/data/</span>
        </div>
    </div>

    <!-- ── Dados PHP → JS ── -->
    <script>
    // Transfer PHP data to JS
    const RAW_PAYMENTS = <?php echo json_encode(array_values($payments), JSON_UNESCAPED_UNICODE); ?>;
    const RAW_PAGEVIEWS = <?php echo json_encode($pageviews, JSON_UNESCAPED_UNICODE); ?>;
    const ITEMS_PER_PAGE = <?php echo $ITEMS_PER_PAGE; ?>;

    // ── State ──
    let filteredPayments = [];
    let filteredPageviews = [];
    let salesPage = 1;
    let autoRefreshTimer = null;
    let sortState = {};

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

        filteredPayments = RAW_PAYMENTS.filter(p => {
            const d = getDateStr(p.created_at);
            if (from && d < from) return false;
            if (to && d > to) return false;
            return true;
        });

        filteredPageviews = RAW_PAGEVIEWS.filter(pv => {
            const d = getDateStr(pv.timestamp);
            if (from && d < from) return false;
            if (to && d > to) return false;
            return true;
        });

        salesPage = 1;
        renderAll();
    }

    function resetFilters() {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        filteredPayments = [...RAW_PAYMENTS];
        filteredPageviews = [...RAW_PAGEVIEWS];
        salesPage = 1;
        renderAll();
    }

    // ── KPI Rendering ──
    function renderKPIs() {
        const totalViews = filteredPageviews.length;
        const totalPix = filteredPayments.length;
        const totalPaid = filteredPayments.filter(p => p.status === 'paid').length;
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
            ctx.fillStyle = '#71717a';
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
        ctx.strokeStyle = '#1e1f2a';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padTop + (chartH / 4) * i;
            ctx.beginPath();
            ctx.moveTo(padLeft, y);
            ctx.lineTo(W - padRight, y);
            ctx.stroke();

            // Y labels
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

            // Bar
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

            // X label
            ctx.fillStyle = '#71717a';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'center';
            const lbl = d.label.substring(5); // MM-DD
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
            ctx.fillStyle = '#71717a';
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

            // Label inside slice if big enough
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

            // Legend
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

        // Also include days with 0 sales in range
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

        // Count views by source+campaign+medium+content
        filteredPageviews.forEach(pv => {
            const key = [pv.utm_source || '(direto)', pv.utm_campaign || '-', pv.utm_medium || '-', pv.utm_content || '-'].join('||');
            if (!map[key]) map[key] = { source: pv.utm_source || '(direto)', campaign: pv.utm_campaign || '-', medium: pv.utm_medium || '-', content: pv.utm_content || '-', views: 0, pix: 0, sales: 0, revenue: 0 };
            map[key].views++;
        });

        // Count payments by tracking info
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
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="no-data">Sem dados</td></tr>';
            return;
        }
        tbody.innerHTML = data.map(r => {
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
            tbody.innerHTML = '<tr><td colspan="9" class="no-data">Sem vendas no periodo</td></tr>';
            document.getElementById('salesPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = pageData.map(p => {
            const items = (p.items || []).map(i => esc((i.name || 'Produto') + ' x' + (i.quantity || 1))).join(', ');
            const statusCls = p.status === 'paid' ? 'badge-paid' : 'badge-pending';
            const statusLabel = p.status === 'paid' ? 'Pago' : 'Pendente';
            const t = p.tracking || {};
            return `<tr>
                <td>${formatDateBR(p.created_at)}</td>
                <td>${esc(p.customer?.name || '-')}</td>
                <td>${esc(p.customer?.email || '-')}</td>
                <td>${esc(p.customer?.phone || '-')}</td>
                <td>${items || '-'}</td>
                <td>${formatBRL(p.amount || 0)}</td>
                <td><span class="badge ${statusCls}">${statusLabel}</span></td>
                <td>${esc(t.utm_source || '-')}</td>
                <td>${esc(t.utm_campaign || '-')}</td>
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

            // Remove sorted class from siblings
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
                // If page returns login form, skip
                if (html.indexOf('dashboard_password') !== -1) return;
                // Reload entire page for simplicity
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
