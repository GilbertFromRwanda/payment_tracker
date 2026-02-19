<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Get filter parameters
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$sectorId = $_GET['sector_id'] ?? null;
$action = $_GET['action'] ?? '';
$paymentId = $_GET['payment_id'] ?? null;

// Handle actions
if ($action === 'delete' && $paymentId) {
    $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $deleted = $stmt->rowCount();

    if ($deleted) {
        $_SESSION['message'] = "Payment deleted successfully";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Payment not found or could not be deleted";
        $_SESSION['message_type'] = 'error';
    }

    $redirectUrl = "deletion.php?year=$year&month=$month" . ($sectorId ? "&sector_id=$sectorId" : "");
    header("Location: $redirectUrl");
    exit();
}

if ($action === 'delete_month' && $year && $month) {
    $monthYear = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);

    if ($sectorId) {
        $stmt = $pdo->prepare("
            DELETE p FROM payments p
            JOIN customers c ON p.customer_id = c.id
            WHERE p.month_year = ? AND c.sector_id = ?
        ");
        $stmt->execute([$monthYear, $sectorId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM payments WHERE month_year = ?");
        $stmt->execute([$monthYear]);
    }

    $deleted = $stmt->rowCount();

    if ($deleted) {
        $_SESSION['message'] = "$deleted payments deleted for $monthYear";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "No payments found for $monthYear";
        $_SESSION['message_type'] = 'warning';
    }

    $redirectUrl = "deletion.php?year=$year&month=$month" . ($sectorId ? "&sector_id=$sectorId" : "");
    header("Location: $redirectUrl");
    exit();
}

// Get sectors for filter dropdown
$sectors = $pdo->query("SELECT * FROM sectors ORDER BY sector_name")->fetchAll();

// Build query for payments with filters
$query = "
    SELECT
        p.id as payment_id,
        p.month_year,
        p.paid_amount,
        p.payment_date,
        c.id as customer_id,
        c.name as customer_name,
        c.phone,
        c.occupation,
        s.id as sector_id,
        s.sector_name
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    JOIN sectors s ON c.sector_id = s.id
    WHERE 1=1
";

$params = [];
$monthYear = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
$query .= " AND p.month_year = ?";
$params[] = $monthYear;

if ($sectorId) {
    $query .= " AND s.id = ?";
    $params[] = $sectorId;
}

$query .= " ORDER BY c.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Summary statistics
$summaryQuery = "
    SELECT
        COUNT(DISTINCT p.customer_id) as total_customers,
        COUNT(p.id) as total_payments,
        SUM(p.paid_amount) as total_amount,
        AVG(p.paid_amount) as average_amount,
        MIN(p.payment_date) as first_payment_date,
        MAX(p.payment_date) as last_payment_date
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    WHERE p.month_year = ?
";

$summaryParams = [$monthYear];
if ($sectorId) {
    $summaryQuery .= " AND c.sector_id = ?";
    $summaryParams[] = $sectorId;
}

$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($summaryParams);
$summary = $summaryStmt->fetch();

// Customers without payments
$missingQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        c.occupation,
        s.sector_name,
        c.amount_to_pay
    FROM customers c
    JOIN sectors s ON c.sector_id = s.id
    WHERE c.id NOT IN (
        SELECT customer_id FROM payments WHERE month_year = ?
    )
";

$missingParams = [$monthYear];
if ($sectorId) {
    $missingQuery .= " AND c.sector_id = ?";
    $missingParams[] = $sectorId;
}

$missingQuery .= " ORDER BY c.name ASC";
$missingStmt = $pdo->prepare($missingQuery);
$missingStmt->execute($missingParams);
$missingPayments = $missingStmt->fetchAll();

// Total customers for coverage
$totalCustomersQuery = "SELECT COUNT(*) as total FROM customers";
$totalCustomersParams = [];
if ($sectorId) {
    $totalCustomersQuery .= " WHERE sector_id = ?";
    $totalCustomersParams[] = $sectorId;
}

$totalCustomersStmt = $pdo->prepare($totalCustomersQuery);
$totalCustomersStmt->execute($totalCustomersParams);
$totalCustomers = $totalCustomersStmt->fetch()['total'];
$coverage = $totalCustomers > 0 ? round((count($payments) / $totalCustomers) * 100, 1) : 0;

// Monthly trend for the year
$trendStmt = $pdo->prepare("
    SELECT month_year,
           COUNT(DISTINCT customer_id) as paying_customers,
           SUM(paid_amount) as monthly_total
    FROM payments
    WHERE month_year LIKE ?
    GROUP BY month_year
    ORDER BY month_year ASC
");
$trendStmt->execute([$year . '-%']);
$monthlyTrend = $trendStmt->fetchAll();

$monthNames = [
    '01' => 'January',  '02' => 'February', '03' => 'March',
    '04' => 'April',    '05' => 'May',       '06' => 'June',
    '07' => 'July',     '08' => 'August',    '09' => 'September',
    '10' => 'October',  '11' => 'November',  '12' => 'December',
];

$message     = $_SESSION['message'] ?? '';
$messageType = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);

// Resolve selected sector name once
$selectedSectorName = '';
if ($sectorId) {
    $found = array_filter($sectors, fn($s) => $s['id'] == $sectorId);
    if (!empty($found)) $selectedSectorName = reset($found)['sector_name'];
}

$sectorParam = $sectorId ? "&sector_id=$sectorId" : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management — Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── CSS variables ─────────────────────────────────── */
        :root {
            --pm-primary:   #667eea;
            --pm-purple:    #764ba2;
            --pm-success:   #28a745;
            --pm-danger:    #dc3545;
            --pm-warning:   #ffc107;
            --pm-info:      #17a2b8;
            --pm-text:      #333;
            --pm-muted:     #666;
            --pm-border:    #e8e8e8;
            --pm-bg:        #f8f9fa;
            --pm-shadow:    0 2px 12px rgba(0,0,0,0.07);
        }

        /* ── Page header ───────────────────────────────────── */
        .pm-header {
            background: linear-gradient(135deg, var(--pm-primary) 0%, var(--pm-purple) 100%);
            border-radius: 12px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.75rem;
            position: relative;
            overflow: hidden;
        }
        .pm-header::after {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
            pointer-events: none;
        }
        .pm-header-inner { position: relative; z-index: 1; }

        .pm-header-title {
            color: #fff;
            font-size: 1.65rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .pm-header-subtitle {
            color: rgba(255,255,255,0.75);
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }

        /* ── Filter form (inside header) ───────────────────── */
        .pm-filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .pm-filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .pm-filter-group label {
            color: rgba(255,255,255,0.8);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pm-filter-group select {
            background: rgba(255,255,255,0.92);
            border: none;
            border-radius: 7px;
            padding: 0.55rem 0.85rem;
            font-size: 0.9rem;
            color: var(--pm-text);
            min-width: 140px;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .pm-filter-group select:focus {
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.35);
        }
        .pm-filter-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.4);
            color: #fff;
            border-radius: 7px;
            padding: 0.55rem 1.2rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .pm-filter-btn:hover { background: rgba(255,255,255,0.25); }

        /* ── Stats grid ────────────────────────────────────── */
        .pm-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .pm-stat {
            background: #fff;
            border-radius: 10px;
            padding: 1.2rem 1.4rem;
            box-shadow: var(--pm-shadow);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            border-top: 3px solid transparent;
        }
        .pm-stat.c-payments { border-top-color: var(--pm-primary); }
        .pm-stat.c-amount   { border-top-color: var(--pm-success); }
        .pm-stat.c-coverage { border-top-color: #6f42c1; }
        .pm-stat.c-period   { border-top-color: var(--pm-warning); }

        .pm-stat-icon {
            width: 46px; height: 46px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .c-payments .pm-stat-icon { background: #eef0fc; }
        .c-amount   .pm-stat-icon { background: #e8f5e9; }
        .c-coverage .pm-stat-icon { background: #f3e8fd; }
        .c-period   .pm-stat-icon { background: #fff8e1; }

        .pm-stat-body { flex: 1; min-width: 0; }
        .pm-stat-lbl {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--pm-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 0.2rem;
        }
        .pm-stat-val {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--pm-text);
            line-height: 1.1;
            margin-bottom: 0.2rem;
        }
        .pm-stat-val.sm { font-size: 1.25rem; }
        .pm-stat-sub { font-size: 0.8rem; color: var(--pm-muted); }

        /* Coverage progress bar */
        .pm-cov-bar {
            margin-top: 0.55rem;
            height: 5px;
            background: #eee;
            border-radius: 3px;
            overflow: hidden;
        }
        .pm-cov-fill {
            height: 100%;
            background: linear-gradient(90deg, #6f42c1, #e83e8c);
            border-radius: 3px;
            width: 0%;
            transition: width 0.9s ease;
        }

        /* ── Action toolbar ────────────────────────────────── */
        .pm-toolbar {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.75rem;
            box-shadow: var(--pm-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .pm-toolbar-info h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--pm-text);
            margin-bottom: 0.15rem;
        }
        .pm-toolbar-info p {
            font-size: 0.82rem;
            color: var(--pm-muted);
        }
        .pm-toolbar-btns {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        /* Button extensions */
        .btn-info {
            background: var(--pm-info);
            color: #fff;
            border: none;
        }
        .btn-info:hover {
            background: #138496;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(23,162,184,0.3);
        }
        .btn-warning {
            background: var(--pm-warning);
            color: #212529;
            border: none;
        }
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }
        .btn-danger {
            background: var(--pm-danger);
            color: #fff;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220,53,69,0.3);
        }

        /* ── Section card ──────────────────────────────────── */
        .pm-section {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.75rem;
            box-shadow: var(--pm-shadow);
        }
        .pm-sec-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 0.85rem;
            margin-bottom: 1.2rem;
            border-bottom: 1px solid var(--pm-border);
        }
        .pm-sec-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--pm-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pm-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--pm-bg);
            color: var(--pm-muted);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.15rem 0.6rem;
            border-radius: 20px;
            min-width: 24px;
        }
        .pm-sec-meta {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--pm-muted);
        }

        /* ── Payments table ────────────────────────────────── */
        .pm-table-wrap { overflow-x: auto; }
        .pm-table {
            width: 100%;
            border-collapse: collapse;
        }
        .pm-table thead tr { background: var(--pm-primary); }
        .pm-table th {
            color: #fff;
            padding: 0.8rem 1rem;
            text-align: left;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .pm-table th:first-child { border-radius: 6px 0 0 6px; }
        .pm-table th:last-child  { border-radius: 0 6px 6px 0; }
        .pm-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
            color: var(--pm-text);
        }
        .pm-table tr:last-child td { border-bottom: none; }
        .pm-table tbody tr:hover td { background: var(--pm-bg); }
        .pm-row-num { color: var(--pm-muted); font-size: 0.82rem; }
        .pm-cname { font-weight: 600; }
        .pm-csub  { font-size: 0.78rem; color: var(--pm-muted); margin-top: 0.1rem; }
        .pm-amount { font-weight: 700; color: var(--pm-success); }

        /* Row actions */
        .pm-row-actions { display: flex; gap: 0.3rem; }
        .pm-act-btn {
            width: 32px; height: 32px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            text-decoration: none;
            transition: transform 0.15s, background 0.15s;
        }
        .pm-act-btn:hover { transform: translateY(-1px); }
        .pm-act-btn.view   { background: #e3f2fd; color: #1565c0; }
        .pm-act-btn.view:hover   { background: #bbdefb; }
        .pm-act-btn.del    { background: #ffebee; color: #b71c1c; }
        .pm-act-btn.del:hover    { background: #ffcdd2; }

        /* ── Empty state ───────────────────────────────────── */
        .pm-empty {
            text-align: center;
            padding: 3.5rem 2rem;
            color: var(--pm-muted);
        }
        .pm-empty-icon { font-size: 3rem; opacity: 0.4; margin-bottom: 1rem; }
        .pm-empty h3   { color: #555; margin-bottom: 0.4rem; font-size: 1.05rem; }
        .pm-empty p    { font-size: 0.88rem; margin-bottom: 1.5rem; }

        /* ── Missing payments panel ────────────────────────── */
        .pm-missing {
            background: #fff;
            border-radius: 10px;
            margin-bottom: 1.75rem;
            box-shadow: var(--pm-shadow);
            overflow: hidden;
        }
        .pm-missing-banner {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        .pm-missing-banner-l {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .pm-missing-banner-l .icon { font-size: 1.4rem; }
        .pm-missing-banner h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #7a5900;
            margin-bottom: 0.1rem;
        }
        .pm-missing-banner p  { font-size: 0.8rem; color: #997a00; }
        .pm-missing-banner-r  { text-align: right; flex-shrink: 0; }
        .pm-missing-pill {
            display: inline-block;
            background: #ffc107;
            color: #333;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 0.2rem 0.7rem;
            border-radius: 20px;
            margin-bottom: 0.25rem;
        }
        .pm-missing-due {
            font-size: 0.78rem;
            color: #997a00;
            font-weight: 600;
        }
        .pm-missing-list {
            max-height: 320px;
            overflow-y: auto;
        }
        .pm-missing-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1.5rem;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.1s;
        }
        .pm-missing-item:last-child { border-bottom: none; }
        .pm-missing-item:hover { background: #fafafa; }
        .pm-missing-name    { font-weight: 600; font-size: 0.9rem; }
        .pm-missing-details { font-size: 0.78rem; color: var(--pm-muted); margin-top: 0.1rem; }
        .pm-missing-r       { text-align: right; flex-shrink: 0; }
        .pm-missing-amount  { font-weight: 700; color: var(--pm-danger); font-size: 0.92rem; }
        .pm-add-sm {
            display: inline-block;
            margin-top: 0.4rem;
            padding: 0.2rem 0.65rem;
            font-size: 0.78rem;
        }

        /* ── Monthly trend ─────────────────────────────────── */
        .pm-chart-wrap {
            height: 280px;
            margin-bottom: 1.5rem;
            display: none;
        }
        .pm-trend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(108px, 1fr));
            gap: 0.65rem;
        }
        .pm-trend-month {
            background: var(--pm-bg);
            border-radius: 8px;
            padding: 0.8rem 0.6rem;
            text-align: center;
            text-decoration: none;
            border: 2px solid transparent;
            transition: all 0.2s;
            display: block;
        }
        .pm-trend-month:hover {
            background: #fff;
            border-color: var(--pm-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.12);
        }
        .pm-trend-month.active {
            background: var(--pm-primary);
            border-color: var(--pm-primary);
        }
        .pm-trend-name {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--pm-text);
            margin-bottom: 0.25rem;
        }
        .pm-trend-month.active .pm-trend-name { color: #fff; }
        .pm-trend-amt {
            font-size: 0.7rem;
            color: var(--pm-muted);
            line-height: 1.4;
        }
        .pm-trend-month.active .pm-trend-amt { color: rgba(255,255,255,0.8); }
        .pm-trend-month.no-data .pm-trend-amt { color: #bbb; font-style: italic; }

        /* ── Modals ────────────────────────────────────────── */
        .pm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .pm-overlay.active { display: flex; }
        .pm-modal {
            background: #fff;
            border-radius: 14px;
            max-width: 460px;
            width: 90%;
            box-shadow: 0 24px 64px rgba(0,0,0,0.25);
            animation: pmIn 0.2s ease;
            overflow: hidden;
        }
        @keyframes pmIn {
            from { opacity: 0; transform: scale(0.94) translateY(12px); }
            to   { opacity: 1; transform: scale(1)    translateY(0); }
        }
        .pm-modal-icon {
            width: 60px; height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 1.75rem auto 0;
        }
        .pm-modal-icon.danger { background: #ffebee; }
        .pm-modal-body {
            padding: 1rem 2rem 1.5rem;
            text-align: center;
        }
        .pm-modal-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--pm-text);
            margin: 0.75rem 0 0.4rem;
        }
        .pm-modal-text {
            font-size: 0.9rem;
            color: var(--pm-muted);
            line-height: 1.65;
        }
        .pm-modal-warn {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 7px;
            padding: 0.55rem 0.9rem;
            font-size: 0.82rem;
            color: #856404;
            margin: 0.85rem 0 0;
            text-align: left;
        }
        .pm-modal-footer {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            padding: 0 2rem 1.75rem;
        }
        .pm-modal-footer .btn { min-width: 120px; }

        /* Spinner */
        .pm-spinner {
            width: 44px; height: 44px;
            border: 4px solid #f0f0f0;
            border-top-color: var(--pm-primary);
            border-radius: 50%;
            animation: pmSpin 0.75s linear infinite;
            margin: 1.5rem auto 0.75rem;
        }
        @keyframes pmSpin { to { transform: rotate(360deg); } }

        /* ── Responsive ────────────────────────────────────── */
        @media (max-width: 768px) {
            .pm-header { padding: 1.5rem; }
            .pm-filter-row { flex-direction: column; align-items: stretch; }
            .pm-filter-group select { min-width: unset; }
            .pm-toolbar { flex-direction: column; align-items: stretch; }
            .pm-toolbar-btns { justify-content: center; }
            .pm-stats { grid-template-columns: 1fr 1fr; }
            .pm-missing-item { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .pm-missing-r { text-align: left; }
        }
        @media (max-width: 480px) {
            .pm-stats { grid-template-columns: 1fr; }
        }

        /* ── Print ─────────────────────────────────────────── */
        @media print {
            .navbar, .sidebar, .pm-toolbar,
            .pm-row-actions, .pm-overlay,
            .pm-filter-row, .pm-missing-r .pm-add-sm { display: none !important; }
            .container   { display: block; }
            .main-content { padding: 0; }
            .pm-header   { background: #f8f9fa !important; border-radius: 0; }
            .pm-header-title, .pm-header-subtitle { color: #333 !important; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Payment Management</div>
        <a href="#" onclick="history.go(-1); return false;" class="back-btn">&larr; Back</a>
    </nav>

    <div class="container">
        <?php $extraSidebarLinks = []; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <!-- ── Page Header ─────────────────────────────── -->
            <div class="pm-header">
                <div class="pm-header-inner">
                    <h1 class="pm-header-title">Payment Management</h1>
                    <p class="pm-header-subtitle">
                        <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?>
                        <?php if ($selectedSectorName): ?>
                            &mdash; <?php echo htmlspecialchars($selectedSectorName); ?> Sector
                        <?php endif; ?>
                    </p>

                    <form method="GET" class="pm-filter-row">
                        <div class="pm-filter-group">
                            <label for="year">Year</label>
                            <select id="year" name="year" onchange="this.form.submit()">
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="pm-filter-group">
                            <label for="month">Month</label>
                            <select id="month" name="month" onchange="this.form.submit()">
                                <?php foreach ($monthNames as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $month == $num ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pm-filter-group">
                            <label for="sector_id">Sector</label>
                            <select id="sector_id" name="sector_id" onchange="this.form.submit()">
                                <option value="">All Sectors</option>
                                <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>"
                                    <?php echo $sectorId == $sector['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="pm-filter-btn">&#128269; Filter</button>
                    </form>
                </div>
            </div>

            <!-- ── Flash message ───────────────────────────── -->
            <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($messageType); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- ── Stats ───────────────────────────────────── -->
            <div class="pm-stats">
                <div class="pm-stat c-payments">
                    <div class="pm-stat-icon">&#128203;</div>
                    <div class="pm-stat-body">
                        <div class="pm-stat-lbl">Payments Recorded</div>
                        <div class="pm-stat-val"><?php echo $summary['total_payments'] ?? 0; ?></div>
                        <div class="pm-stat-sub"><?php echo $summary['total_customers'] ?? 0; ?> customers paid</div>
                    </div>
                </div>

                <div class="pm-stat c-amount">
                    <div class="pm-stat-icon">&#128176;</div>
                    <div class="pm-stat-body">
                        <div class="pm-stat-lbl">Total Amount</div>
                        <div class="pm-stat-val sm">RWF <?php echo number_format($summary['total_amount'] ?? 0, 0); ?></div>
                        <div class="pm-stat-sub">Avg: RWF <?php echo number_format($summary['average_amount'] ?? 0, 0); ?></div>
                    </div>
                </div>

                <div class="pm-stat c-coverage">
                    <div class="pm-stat-icon">&#128202;</div>
                    <div class="pm-stat-body">
                        <div class="pm-stat-lbl">Coverage</div>
                        <div class="pm-stat-val"><?php echo $coverage; ?>%</div>
                        <div class="pm-stat-sub"><?php echo count($payments); ?> of <?php echo $totalCustomers; ?> customers</div>
                        <div class="pm-cov-bar">
                            <div class="pm-cov-fill" data-coverage="<?php echo $coverage; ?>"></div>
                        </div>
                    </div>
                </div>

                <div class="pm-stat c-period">
                    <div class="pm-stat-icon">&#128197;</div>
                    <div class="pm-stat-body">
                        <div class="pm-stat-lbl">Payment Period</div>
                        <div class="pm-stat-val sm">
                            <?php if ($summary['first_payment_date']): ?>
                                <?php echo date('M d', strtotime($summary['first_payment_date'])); ?>
                                &ndash;
                                <?php echo date('M d', strtotime($summary['last_payment_date'])); ?>
                            <?php else: ?>
                                No payments
                            <?php endif; ?>
                        </div>
                        <div class="pm-stat-sub"><?php echo $summary['total_payments'] ?? 0; ?> transactions</div>
                    </div>
                </div>
            </div>

            <!-- ── Action Toolbar ──────────────────────────── -->
            <div class="pm-toolbar">
                <div class="pm-toolbar-info">
                    <h3>Actions &mdash; <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></h3>
                    <p>Add, export or remove payment records for this period</p>
                </div>
                <div class="pm-toolbar-btns">
                    <a href="add_payment.php?month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>"
                       class="btn btn-primary btn-sm">
                        &#43; Add Payments
                    </a>
                    <button onclick="exportMonthData()" class="btn btn-info btn-sm" style=" display:none">
                        &#8659; Export
                    </button>
                    <a href="reports.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>"
                       class="btn btn-secondary btn-sm">
                        &#128202; Reports
                    </a>
                    <button onclick="showDeleteMonthModal()" class="btn btn-danger btn-sm">
                        &#128465; Delete Month
                    </button>
                </div>
            </div>

            <!-- ── Recorded Payments ───────────────────────── -->
            <div class="pm-section">
                <div class="pm-sec-header">
                    <h2 class="pm-sec-title">
                        &#9989; Recorded Payments
                        <span class="pm-badge"><?php echo count($payments); ?></span>
                    </h2>
                    <span class="pm-sec-meta">
                        Total: RWF <?php echo number_format($summary['total_amount'] ?? 0, 0); ?>
                    </span>
                </div>

                <?php if (!empty($payments)): ?>
                <div class="pm-table-wrap">
                    <table class="pm-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Sector</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $index => $payment): ?>
                            <tr>
                                <td class="pm-row-num"><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="pm-cname"><?php echo htmlspecialchars($payment['customer_name']); ?></div>
                                    <div class="pm-csub"><?php echo htmlspecialchars($payment['occupation']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($payment['phone']); ?></td>
                                <td><?php echo htmlspecialchars($payment['sector_name']); ?></td>
                                <td class="pm-amount">
                                    RWF <?php echo number_format($payment['paid_amount'], 0); ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <div class="pm-row-actions">
                                        <a href="customer_profile.php?id=<?php echo $payment['customer_id']; ?>"
                                           class="pm-act-btn view" title="View Customer">&#128065;</a>
                                        <button
                                            onclick="showDeleteModal(<?php echo $payment['payment_id']; ?>, '<?php echo addslashes($payment['customer_name']); ?>', <?php echo $payment['paid_amount']; ?>)"
                                            class="pm-act-btn del" title="Delete Payment">&#128465;</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="pm-empty">
                    <div class="pm-empty-icon">&#128176;</div>
                    <h3>No Payments Recorded</h3>
                    <p>No payments found for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></p>
                    <a href="add_payment.php?month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>"
                       class="btn btn-primary btn-sm">Add Payments for this Month</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Missing Payments ────────────────────────── -->
            <?php if (!empty($missingPayments)): ?>
            <div class="pm-missing">
                <div class="pm-missing-banner">
                    <div class="pm-missing-banner-l">
                        <span class="icon">&#9888;&#65039;</span>
                        <div>
                            <h3>Missing Payments</h3>
                            <p>Customers with no payment for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></p>
                        </div>
                    </div>
                    <div class="pm-missing-banner-r">
                        <span class="pm-missing-pill"><?php echo count($missingPayments); ?> customers</span>
                        <div class="pm-missing-due">
                            Due: RWF <?php echo number_format(array_sum(array_column($missingPayments, 'amount_to_pay')), 0); ?>
                        </div>
                    </div>
                </div>
                <div class="pm-missing-list">
                    <?php foreach ($missingPayments as $customer): ?>
                    <div class="pm-missing-item">
                        <div>
                            <div class="pm-missing-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                            <div class="pm-missing-details">
                                <?php echo htmlspecialchars($customer['phone']); ?> &bull;
                                <?php echo htmlspecialchars($customer['occupation']); ?> &bull;
                                <?php echo htmlspecialchars($customer['sector_name']); ?>
                            </div>
                        </div>
                        <div class="pm-missing-r">
                            <div class="pm-missing-amount">
                                RWF <?php echo number_format($customer['amount_to_pay'], 0); ?>
                            </div>
                            <a href="add_payment.php?customer_id=<?php echo $customer['id']; ?>&month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>"
                               class="btn btn-primary btn-sm pm-add-sm">Add Payment</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Monthly Trend ───────────────────────────── -->
            <div class="pm-section">
                <div class="pm-sec-header">
                    <h2 class="pm-sec-title">&#128200; Monthly Trend &mdash; <?php echo $year; ?></h2>
                    <span class="pm-sec-meta">
                        Year total: RWF <?php echo number_format(array_sum(array_column($monthlyTrend, 'monthly_total')), 0); ?>
                    </span>
                </div>

                <div class="pm-chart-wrap" id="pmChartWrap">
                    <canvas id="trendChart"></canvas>
                </div>

                <div class="pm-trend-grid">
                    <?php for ($m = 1; $m <= 12; $m++):
                        $num  = str_pad($m, 2, '0', STR_PAD_LEFT);
                        $key  = $year . '-' . $num;
                        $row  = array_values(array_filter($monthlyTrend, fn($t) => $t['month_year'] === $key));
                        $row  = $row[0] ?? null;
                        $hasData = $row && $row['monthly_total'] > 0;
                        $cls  = 'pm-trend-month'
                              . ($num == $month ? ' active' : '')
                              . (!$hasData       ? ' no-data' : '');
                    ?>
                    <a href="deletion.php?year=<?php echo $year; ?>&month=<?php echo $num; ?><?php echo $sectorParam; ?>"
                       class="<?php echo $cls; ?>">
                        <div class="pm-trend-name"><?php echo $monthNames[$num]; ?></div>
                        <div class="pm-trend-amt">
                            <?php if ($hasData): ?>
                                RWF <?php echo number_format($row['monthly_total'], 0); ?><br>
                                <small><?php echo $row['paying_customers']; ?> cust.</small>
                            <?php else: ?>
                                No data
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endfor; ?>
                </div>
            </div>

        </main>
    </div>

    <!-- ── Delete Single Payment Modal ─────────────────────── -->
    <div class="pm-overlay" id="deleteModal">
        <div class="pm-modal">
            <div class="pm-modal-icon danger">&#128465;&#65039;</div>
            <div class="pm-modal-body">
                <h3 class="pm-modal-title">Delete Payment?</h3>
                <p class="pm-modal-text" id="deleteMessage">
                    Are you sure you want to delete this payment?
                </p>
            </div>
            <div class="pm-modal-footer">
                <button onclick="hideModal()" class="btn btn-secondary btn-sm">Cancel</button>
                <a id="deleteLink" class="btn btn-danger btn-sm">Yes, Delete</a>
            </div>
        </div>
    </div>

    <!-- ── Delete Month Modal ──────────────────────────────── -->
    <div class="pm-overlay" id="deleteMonthModal">
        <div class="pm-modal">
            <div class="pm-modal-icon danger">&#9888;&#65039;</div>
            <div class="pm-modal-body">
                <h3 class="pm-modal-title">Delete All Payments?</h3>
                <p class="pm-modal-text">
                    You are about to delete
                    <strong><?php echo count($payments); ?> payments</strong>
                    totaling
                    <strong>RWF <?php echo number_format($summary['total_amount'] ?? 0, 0); ?></strong>
                    for <strong><?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></strong>
                    <?php if ($selectedSectorName): ?>
                        (<?php echo htmlspecialchars($selectedSectorName); ?> Sector)
                    <?php endif; ?>.
                </p>
                <div class="pm-modal-warn">
                    &#9888; This action is permanent and cannot be undone.
                </div>
            </div>
            <div class="pm-modal-footer">
                <button onclick="hideModal()" class="btn btn-secondary btn-sm">Cancel</button>
                <a href="deletion.php?action=delete_month&year=<?php echo $year; ?>&month=<?php echo $month; ?>&sector_id=<?php echo $sectorId ?? ''; ?>"
                   class="btn btn-danger btn-sm"
                   onclick="showProcessing()">Delete All</a>
            </div>
        </div>
    </div>

    <!-- ── Processing Modal ───────────────────────────────── -->
    <div class="pm-overlay" id="processingModal">
        <div class="pm-modal">
            <div class="pm-modal-body">
                <div class="pm-spinner"></div>
                <h3 class="pm-modal-title">Processing&hellip;</h3>
                <p class="pm-modal-text">Please wait while we process your request.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        /* ── Coverage bar ────────────────────────────────── */
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.pm-cov-fill').forEach(el => {
                el.style.width = el.dataset.coverage + '%';
            });
        });

        /* ── Modal helpers ───────────────────────────────── */
        function showModal(id) {
            const el = document.getElementById(id);
            if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
        }
        function hideModal() {
            document.querySelectorAll('.pm-overlay').forEach(el => el.classList.remove('active'));
            document.body.style.overflow = '';
        }
        function showDeleteModal(paymentId, customerName, amount) {
            document.getElementById('deleteMessage').innerHTML =
                `Delete payment of <strong>RWF ${Number(amount).toLocaleString()}</strong> for <strong>${customerName}</strong>?`;
            document.getElementById('deleteLink').href =
                `deletion.php?action=delete&payment_id=${paymentId}&year=<?php echo $year; ?>&month=<?php echo $month; ?>&sector_id=<?php echo $sectorId ?? ''; ?>`;
            showModal('deleteModal');
        }
        function showDeleteMonthModal() {
            if (<?php echo count($payments); ?> === 0) {
                alert('No payments to delete for this month.');
                return;
            }
            showModal('deleteMonthModal');
        }
        function showProcessing() {
            hideModal();
            setTimeout(() => showModal('processingModal'), 100);
        }
        function exportMonthData() {
            showModal('processingModal');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_payments.php';
            form.style.display = 'none';
            function addField(name, value) {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = name; inp.value = value;
                form.appendChild(inp);
            }
            addField('year',   '<?php echo $year; ?>');
            addField('month',  '<?php echo $month; ?>');
            addField('action', 'export');
            <?php if ($sectorId): ?>addField('sectorId', '<?php echo $sectorId; ?>');<?php endif; ?>
            document.body.appendChild(form);
            form.submit();
        }

        /* ── Close on backdrop / ESC ─────────────────────── */
        document.querySelectorAll('.pm-overlay').forEach(el => {
            el.addEventListener('click', e => { if (e.target === el) hideModal(); });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') hideModal();
        });

        /* ── Auto-hide success message ───────────────────── */
        document.addEventListener('DOMContentLoaded', () => {
            const msg = document.querySelector('.message.success');
            if (msg) setTimeout(() => {
                msg.style.transition = 'opacity 0.3s, transform 0.3s';
                msg.style.opacity    = '0';
                msg.style.transform  = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 300);
            }, 5000);
        });

        /* ── Keyboard month navigation (Alt + ← / →) ────── */
        document.addEventListener('keydown', e => {
            if (!e.altKey) return;
            const mo = parseInt('<?php echo $month; ?>');
            const yr = parseInt('<?php echo $year; ?>');
            if (e.key === 'ArrowLeft') {
                const pm = mo === 1 ? 12 : mo - 1, py = mo === 1 ? yr - 1 : yr;
                if (py >= 2020) location.href = `deletion.php?year=${py}&month=${String(pm).padStart(2,'0')}<?php echo $sectorParam; ?>`;
            } else if (e.key === 'ArrowRight') {
                const nm = mo === 12 ? 1 : mo + 1, ny = mo === 12 ? yr + 1 : yr;
                if (ny <= new Date().getFullYear() + 1) location.href = `deletion.php?year=${ny}&month=${String(nm).padStart(2,'0')}<?php echo $sectorParam; ?>`;
            } else if (e.key === 'h') {
                location.href = 'dashboard.php';
            }
        });

        /* ── Trend chart ─────────────────────────────────── */
        document.addEventListener('DOMContentLoaded', () => {
            const trendData = <?php echo json_encode($monthlyTrend); ?>;
            if (!trendData.length) return;

            const wrap = document.getElementById('pmChartWrap');
            const ctx  = document.getElementById('trendChart');
            if (!wrap || !ctx) return;
            wrap.style.display = 'block';

            const monthNamesMap = <?php echo json_encode($monthNames); ?>;
            const labels = [], amounts = [], customers = [];

            for (let i = 1; i <= 12; i++) {
                const num = String(i).padStart(2, '0');
                const row = trendData.find(t => t.month_year === `<?php echo $year; ?>-${num}`);
                labels.push(monthNamesMap[num]);
                amounts.push(row ? parseFloat(row.monthly_total)      : 0);
                customers.push(row ? parseInt(row.paying_customers)   : 0);
            }

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Total (RWF)',
                            data: amounts,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102,126,234,0.08)',
                            fill: true,
                            tension: 0.35,
                            pointBackgroundColor: '#667eea',
                            pointRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Customers',
                            data: customers,
                            borderColor: '#20c997',
                            backgroundColor: 'rgba(32,201,151,0.08)',
                            fill: true,
                            tension: 0.35,
                            pointBackgroundColor: '#20c997',
                            pointRadius: 4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.datasetIndex === 0
                                    ? `Amount: RWF ${ctx.parsed.y.toLocaleString()}`
                                    : `Customers: ${ctx.parsed.y}`
                            }
                        }
                    },
                    scales: {
                        x:  { grid: { display: false } },
                        y:  { position: 'left',  title: { display: true, text: 'Amount (RWF)' } },
                        y1: { position: 'right', title: { display: true, text: 'Customers' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        });
    </script>
</body>
</html>
