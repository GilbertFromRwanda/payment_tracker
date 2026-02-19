<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Handle delete customer
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = intval($_GET['id']);
    if ($deleteId > 0) {
        $pdo->prepare("DELETE FROM payments WHERE customer_id = ?")->execute([$deleteId]);
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$deleteId]);
        $_SESSION['flash_message'] = "Customer deleted successfully.";
        $_SESSION['flash_type'] = 'success';
    }
    $back = isset($_GET['sector_id']) ? '?sector_id=' . intval($_GET['sector_id']) : '';
    header("Location: customers.php$back");
    exit;
}

// Filters
$sectorId = isset($_GET['sector_id']) ? intval($_GET['sector_id']) : 0;
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

// ── Handle HTML export ──
if (!empty($_GET['export']) && $_GET['export'] === 'html') {
    // Build WHERE clause with same filters
    $expWhere = "WHERE 1=1";
    $expParams = [];
    if ($sectorId) {
        $expWhere .= " AND c.sector_id = ?";
        $expParams[] = $sectorId;
    }
    if ($search !== '') {
        $expWhere .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
        $sp = "%{$search}%";
        $expParams[] = $sp;
        $expParams[] = $sp;
        $expParams[] = $sp;
    }

    // Fetch ALL matching customers (no pagination)
    $expStmt = $pdo->prepare("
        SELECT c.id, c.name, c.phone, c.occupation, c.amount_to_pay, s.sector_name
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        $expWhere
        ORDER BY c.id DESC
    ");
    $expStmt->execute($expParams);
    $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get sector label for title
    $sectorLabel = 'All Sectors';
    if ($sectorId) {
        $sStmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
        $sStmt->execute([$sectorId]);
        $sectorLabel = $sStmt->fetchColumn() ?: 'All Sectors';
    }

    $grandTotal = 0;
    foreach ($expRows as $r) {
        $grandTotal += floatval($r['amount_to_pay']);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer List &mdash; <?php echo htmlspecialchars($sectorLabel); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; font-size: 13px; padding: 30px; }

        .report-header { text-align: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 3px solid #667eea; }
        .report-header h1 { font-size: 22px; color: #667eea; margin-bottom: 2px; }
        .report-header .subtitle { font-size: 14px; color: #555; font-weight: normal; }
        .report-header .meta { font-size: 11px; color: #999; margin-top: 6px; }

        .summary-boxes { display: flex; justify-content: center; gap: 16px; margin-bottom: 22px; }
        .summary-box { flex: 0 1 200px; text-align: center; padding: 12px 16px; border: 1px solid #e0e0e0; border-radius: 8px; }
        .summary-box .num { font-size: 20px; font-weight: bold; color: #667eea; }
        .summary-box .lbl { font-size: 11px; color: #888; text-transform: uppercase; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        thead th {
            background: #667eea; color: #fff; padding: 10px 12px; text-align: left;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        thead th:first-child { border-radius: 6px 0 0 0; }
        thead th:last-child { border-radius: 0 6px 0 0; text-align: right; }
        tbody td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        tbody tr:nth-child(even) { background: #f9fafb; }
        tbody td:first-child { text-align: center; color: #999; width: 40px; }
        tbody td:last-child { text-align: right; font-weight: 500; }

        .total-row td {
            background: #1e293b; color: #fff; font-weight: bold; padding: 10px 12px;
            border: none;
        }
        .total-row td:first-child { border-radius: 0 0 0 6px; }
        .total-row td:last-child { border-radius: 0 0 6px 0; text-align: right; font-size: 14px; }

        .footer { text-align: center; margin-top: 24px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 11px; color: #aaa; }

        .no-print { text-align: center; margin-top: 20px; }
        .print-btn {
            padding: 10px 32px; background: #667eea; color: #fff; border: none;
            border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;
            transition: background 0.15s;
        }
        .print-btn:hover { background: #5a6fd8; }

        @media print {
            body { padding: 10px; }
            .no-print { display: none !important; }
            thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { margin: 1.2cm; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>Customer List</h1>
        <div class="subtitle"><?php echo htmlspecialchars($sectorLabel); ?></div>
        <div class="meta">
            Generated on <?php echo date('l, d M Y \a\t H:i'); ?>
            <?php if ($search): ?> &nbsp;|&nbsp; Search: "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
        </div>
    </div>

    <div class="summary-boxes">
        <div class="summary-box">
            <div class="num"><?php echo number_format(count($expRows)); ?></div>
            <div class="lbl">Customers</div>
        </div>
        <div class="summary-box">
            <div class="num">RWF <?php echo number_format($grandTotal, 2); ?></div>
            <div class="lbl">Total Amount Due</div>
        </div>
        <div class="summary-box">
            <div class="num">RWF <?php echo count($expRows) > 0 ? number_format($grandTotal / count($expRows), 2) : '0.00'; ?></div>
            <div class="lbl">Average per Customer</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Occupation</th>
                <th>Sector</th>
                <th style="text-align:right;">Amount Due (RWF)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expRows as $i => $r): $amt = floatval($r['amount_to_pay']); ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['phone']); ?></td>
                <td><?php echo htmlspecialchars($r['occupation']); ?></td>
                <td><?php echo htmlspecialchars($r['sector_name']); ?></td>
                <td><?php echo number_format($amt, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="5" style="text-align:right;">TOTAL (<?php echo count($expRows); ?> customers)</td>
                <td style="text-align:right;">RWF <?php echo number_format($grandTotal, 2); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Payment Tracker &mdash; <?php echo count($expRows); ?> customer(s) &mdash; <?php echo htmlspecialchars($sectorLabel); ?>
    </div>

    <div class="no-print">
        <button class="print-btn" onclick="window.print()">Print Report</button>
    </div>
</body>
</html>
<?php
    exit;
}

// ── Handle plain list export (no money info) ──
if (!empty($_GET['export']) && $_GET['export'] === 'list') {
    $expWhere = "WHERE 1=1";
    $expParams = [];
    if ($sectorId) {
        $expWhere .= " AND c.sector_id = ?";
        $expParams[] = $sectorId;
    }
    if ($search !== '') {
        $expWhere .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
        $sp = "%{$search}%";
        $expParams[] = $sp;
        $expParams[] = $sp;
        $expParams[] = $sp;
    }

    $expStmt = $pdo->prepare("
        SELECT  c.id, c.name, c.phone, c.occupation, s.sector_name
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        $expWhere
        ORDER BY c.id DESC
    ");
    $expStmt->execute($expParams);
    $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $sectorLabel = 'All Sectors';
    if ($sectorId) {
        $sStmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
        $sStmt->execute([$sectorId]);
        $sectorLabel = $sStmt->fetchColumn() ?: 'All Sectors';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customers List &mdash; <?php echo htmlspecialchars($sectorLabel); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; font-size: 13px; padding: 30px; }

        .report-header { text-align: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 3px solid #667eea; }
        .report-header h1 { font-size: 22px; color: #667eea; margin-bottom: 2px; }
        .report-header .subtitle { font-size: 14px; color: #555; }
        .report-header .meta { font-size: 11px; color: #999; margin-top: 6px; }

        .total-badge { text-align: center; margin-bottom: 20px; font-size: 14px; color: #555; }
        .total-badge strong { color: #667eea; font-size: 18px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        thead th {
            background: #667eea; color: #fff; padding: 10px 12px; text-align: left;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        thead th:first-child { border-radius: 6px 0 0 0; }
        thead th:last-child { border-radius: 0 6px 0 0; }
        tbody td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        tbody tr:nth-child(even) { background: #f9fafb; }
        tbody td:first-child { text-align: center; color: #999; width: 40px; }

        .total-row td {
            background: #1e293b; color: #fff; font-weight: bold; padding: 10px 12px; border: none;
            border-radius: 0 0 6px 6px;
        }

        .footer { text-align: center; margin-top: 24px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 11px; color: #aaa; }

        .no-print { text-align: center; margin-top: 20px; }
        .print-btn {
            padding: 10px 32px; background: #667eea; color: #fff; border: none;
            border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;
        }
        .print-btn:hover { background: #5a6fd8; }

        @media print {
            body { padding: 10px; }
            .no-print { display: none !important; }
            thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { margin: 1.2cm; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>Customers List</h1>
        <div class="subtitle"><?php echo htmlspecialchars($sectorLabel); ?></div>
        <div class="meta">
            Generated on <?php echo date('l, d M Y \a\t H:i'); ?>
            <?php if ($search): ?> &nbsp;|&nbsp; Search: "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
        </div>
    </div>

    <div class="total-badge">
        <strong><?php echo number_format(count($expRows)); ?></strong> customers
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Occupation</th>
                <th>Sector</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expRows as $i => $r): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['phone']); ?></td>
                <td><?php echo htmlspecialchars($r['occupation']); ?></td>
                <td><?php echo htmlspecialchars($r['sector_name']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="5">Total: <?php echo count($expRows); ?> customers</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Payment Tracker &mdash; Customer Directory &mdash; <?php echo htmlspecialchars($sectorLabel); ?>
    </div>

    <div class="no-print">
        <button class="print-btn" onclick="window.print()">Print List</button>
    </div>
</body>
</html>
<?php
    exit;
}

// Get all sectors for filter tabs
$sectors = $pdo->query("
    SELECT s.id, s.sector_name, COUNT(c.id) as customer_count
    FROM sectors s
    LEFT JOIN customers c ON c.sector_id = s.id
    GROUP BY s.id
    ORDER BY s.sector_name
")->fetchAll();

// Build customer query
$where = "WHERE 1=1";
$params = [];

if ($sectorId) {
    $where .= " AND c.sector_id = ?";
    $params[] = $sectorId;
}
if ($search !== '') {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$totalCustomers = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalCustomers / $limit));

// Fetch customers
$stmt = $pdo->prepare("
    SELECT c.*, s.sector_name
    FROM customers c
    LEFT JOIN sectors s ON c.sector_id = s.id
    $where
    ORDER BY c.id DESC
    LIMIT " . intval($limit) . " OFFSET " . intval($offset) . "
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Total amount for current filter
$sumStmt = $pdo->prepare("SELECT SUM(c.amount_to_pay) FROM customers c $where");
$sumStmt->execute($params);
$totalAmount = $sumStmt->fetchColumn() ?: 0;

// All customers count (no filter)
$allCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Gradient page header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-header-left h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .2rem; }
        .page-header-left p  { font-size: .875rem; opacity: .85; margin: 0; }
        .page-header-stats { display: flex; gap: 1rem; flex-wrap: wrap; }
        .header-stat {
            background: rgba(255,255,255,.15);
            border-radius: 10px;
            padding: .6rem 1.1rem;
            text-align: center;
            min-width: 80px;
        }
        .header-stat-val { font-size: 1.4rem; font-weight: 700; line-height: 1; }
        .header-stat-lbl { font-size: .7rem; opacity: .8; margin-top: .2rem; text-transform: uppercase; letter-spacing: .4px; }

        /* Toolbar */
        .toolbar {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .toolbar-left  { display: flex; gap: .5rem; flex-wrap: wrap; }
        .toolbar-right { display: flex; gap: .5rem; flex-wrap: wrap; margin-left: auto; }

        /* Sector tabs */
        .sector-tabs {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .sector-tab {
            padding: .4rem .9rem;
            background: white;
            border: 1.5px solid #e0e0e0;
            border-radius: 20px;
            text-decoration: none;
            color: #666;
            font-size: .82rem;
            font-weight: 600;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }
        .sector-tab:hover { border-color: #667eea; color: #667eea; background: #f5f6ff; }
        .sector-tab.active { background: #667eea; color: white; border-color: #667eea; }
        .sector-tab .count {
            font-size: .72rem; font-weight: 700;
            background: rgba(0,0,0,.1); padding: .1rem .45rem; border-radius: 10px;
        }
        .sector-tab.active .count { background: rgba(255,255,255,.25); }

        /* Search */
        .search-row {
            display: flex;
            gap: .65rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .search-wrap {
            position: relative;
            flex: 1;
            max-width: 420px;
        }
        .search-wrap input {
            width: 100%;
            padding: .65rem 1rem .65rem 2.4rem;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: .9rem;
            font-family: inherit;
            transition: border-color .2s, box-shadow .2s;
        }
        .search-wrap input:focus {
            outline: none; border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.12);
        }
        .search-icon {
            position: absolute; left: .75rem; top: 50%; transform: translateY(-50%);
            color: #aaa; font-size: .95rem; pointer-events: none;
        }

        /* Summary strip */
        .summary-strip {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.25rem;
            padding: .75rem 1.25rem;
            background: white;
            border-radius: 9px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            font-size: .875rem;
            color: #666;
            flex-wrap: wrap;
            align-items: center;
        }
        .summary-strip strong { color: #333; }
        .strip-divider { width: 1px; height: 18px; background: #eee; }

        /* Flash */
        .flash {
            padding: .85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: .9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .flash.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .flash.error   { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }

        /* Table card */
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .customers-table { width: 100%; border-collapse: collapse; }
        .customers-table thead th {
            background: #f8f9fa;
            color: #999;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: .85rem 1.25rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }
        .customers-table tbody tr {
            border-bottom: 1px solid #f5f5f5;
            transition: background .15s;
        }
        .customers-table tbody tr:last-child { border-bottom: none; }
        .customers-table tbody tr:hover { background: #fafbff; }
        .customers-table td { padding: .85rem 1.25rem; vertical-align: middle; }

        /* Customer cell */
        .cust-cell { display: flex; align-items: center; gap: .8rem; }
        .cust-avatar {
            width: 38px; height: 38px; border-radius: 9px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white; font-weight: 700; font-size: .95rem;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .cust-name {
            font-weight: 600; color: #333; font-size: .9rem;
            text-decoration: none; transition: color .15s;
        }
        .cust-name:hover { color: #667eea; }
        .cust-phone { font-size: .78rem; color: #aaa; margin-top: .1rem; }

        .occupation-text { font-size: .875rem; color: #666; }
        .amount-text { font-weight: 700; color: #333; font-size: .9rem; white-space: nowrap; }

        .badge-sector {
            display: inline-block;
            padding: .25rem .65rem;
            background: #f0f2ff;
            color: #667eea;
            border-radius: 12px;
            font-size: .78rem;
            font-weight: 600;
        }

        /* Action buttons */
        .action-btns { display: flex; gap: .4rem; flex-wrap: nowrap; }
        .act-btn {
            padding: .3rem .7rem;
            border-radius: 6px;
            font-size: .78rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }
        .act-view   { background: #f0f2ff; color: #667eea; }
        .act-view:hover   { background: #667eea; color: white; }
        .act-edit   { background: #fff8e1; color: #e65100; }
        .act-edit:hover   { background: #ffc107; color: #212529; }
        .act-pay    { background: #e8f5e9; color: #2e7d32; }
        .act-pay:hover    { background: #28a745; color: white; }
        .act-delete { background: #fde8e8; color: #dc3545; }
        .act-delete:hover { background: #dc3545; color: white; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: .35rem;
            padding: 1.25rem;
        }
        .pagination a, .pagination span {
            padding: .4rem .8rem;
            border: 1.5px solid #e8e8e8;
            border-radius: 7px;
            text-decoration: none;
            color: #555;
            font-size: .82rem;
            font-weight: 600;
            transition: all .15s;
        }
        .pagination a:hover  { background: #f5f6ff; border-color: #667eea; color: #667eea; }
        .pagination .active  { background: #667eea; color: white; border-color: #667eea; }
        .pagination .disabled { opacity: .35; pointer-events: none; }
        .pagination .pg-info { border: none; color: #aaa; font-weight: 400; }

        /* Empty state */
        .empty-state {
            text-align: center; padding: 4rem 2rem; color: #bbb;
            background: white; border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
        }
        .empty-state-icon { font-size: 3rem; margin-bottom: 1rem; }
        .empty-state h3 { color: #888; margin-bottom: .5rem; }
        .empty-state p  { font-size: .9rem; }

        /* Delete modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            display: none; align-items: center; justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white; border-radius: 14px; padding: 2rem;
            max-width: 400px; width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
            animation: modalIn .2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95) translateY(-8px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-icon {
            width: 52px; height: 52px; border-radius: 50%;
            background: #fff3f3; font-size: 1.4rem;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 1.25rem;
        }
        .modal h3 { font-size: 1.1rem; color: #333; margin-bottom: .5rem; }
        .modal p  { font-size: .875rem; color: #666; margin-bottom: 1.5rem; line-height: 1.6; }
        .modal-actions { display: flex; gap: .75rem; justify-content: flex-end; }
        .btn-modal-cancel {
            padding: .6rem 1.2rem; border: 1.5px solid #e0e0e0; background: white;
            border-radius: 7px; font-size: .875rem; cursor: pointer; color: #555;
            transition: background .2s;
        }
        .btn-modal-cancel:hover { background: #f5f5f5; }
        .btn-modal-confirm {
            padding: .6rem 1.2rem; background: #dc3545; color: white;
            border: none; border-radius: 7px; font-size: .875rem; font-weight: 600;
            cursor: pointer; text-decoration: none; display: inline-block;
            transition: background .2s;
        }
        .btn-modal-confirm:hover { background: #c82333; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; }
            .toolbar-right { margin-left: 0; }
            .customers-table thead th:nth-child(4),
            .customers-table td:nth-child(4) { display: none; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></div>
        <a href="#" onclick="history.go(-1); return false;" class="back-btn">← Back</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1>Members</h1>
                    <p><?php $activeSectors = array_filter($sectors, fn($s) => $s['id'] == $sectorId); echo $sectorId ? htmlspecialchars((reset($activeSectors)['sector_name'] ?? 'Sector') . ' Sector') : 'All Sectors'; ?></p>
                </div>
                <div class="page-header-stats">
                    <div class="header-stat">
                        <div class="header-stat-val"><?php echo number_format($totalCustomers); ?></div>
                        <div class="header-stat-lbl">Members</div>
                    </div>
                    <div class="header-stat">
                        <div class="header-stat-val"><?php echo number_format($totalAmount / 1000, 0); ?>k</div>
                        <div class="header-stat-lbl">Total Due RWF</div>
                    </div>
                </div>
            </div>

            <!-- Flash message -->
            <?php if (!empty($_SESSION['flash_message'])): ?>
            <div class="flash <?php echo htmlspecialchars($_SESSION['flash_type'] ?? 'success'); ?>">
                ✓ <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            </div>
            <?php endif; ?>

            <!-- Sector Filter Tabs -->
            <div class="sector-tabs">
                <a href="customers.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
                   class="sector-tab <?php echo !$sectorId ? 'active' : ''; ?>">
                    All <span class="count"><?php echo $allCount; ?></span>
                </a>
                <?php foreach ($sectors as $s): ?>
                <a href="customers.php?sector_id=<?php echo $s['id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                   class="sector-tab <?php echo $sectorId == $s['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($s['sector_name']); ?>
                    <span class="count"><?php echo $s['customer_count']; ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Search + Actions toolbar -->
            <div class="search-row">
                <form method="GET" id="searchForm" style="display:flex;gap:.65rem;align-items:center;flex:1;">
                    <?php if ($sectorId): ?>
                    <input type="hidden" name="sector_id" value="<?php echo $sectorId; ?>">
                    <?php endif; ?>
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="searchInput" name="search"
                               placeholder="Search name, phone, occupation…"
                               value="<?php echo htmlspecialchars($search); ?>"
                               autocomplete="off">
                    </div>
                    <?php if ($search): ?>
                    <a href="customers.php<?php echo $sectorId ? '?sector_id=' . $sectorId : ''; ?>"
                       class="btn btn-secondary btn-sm">✕ Clear</a>
                    <?php endif; ?>
                </form>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <a href="add_customer.php" class="btn btn-primary btn-sm">+ Add</a>
                    <a href="import_customers.php" class="btn btn-secondary btn-sm">↑ Import</a>
                    <?php if ($totalCustomers > 0): ?>
                    <a href="customers.php?<?php echo http_build_query(array_filter(['sector_id' => $sectorId, 'search' => $search, 'export' => 'html'])); ?>"
                       class="btn btn-secondary btn-sm" target="_blank">⬇ Export</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <span><strong><?php echo number_format($totalCustomers); ?></strong> customers shown</span>
                <div class="strip-divider"></div>
                <span>Total due: <strong>RWF <?php echo number_format($totalAmount, 2); ?></strong></span>
                <?php if ($search): ?>
                <div class="strip-divider"></div>
                <span>Filtered by: <strong>"<?php echo htmlspecialchars($search); ?>"</strong></span>
                <?php endif; ?>
                <?php if ($totalPages > 1): ?>
                <div class="strip-divider"></div>
                <span>Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <?php if (!empty($customers)): ?>
            <div class="table-card">
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Occupation</th>
                            <th>Amount Due</th>
                            <?php if (!$sectorId): ?><th>Sector</th><?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $i => $c): ?>
                        <tr>
                            <td style="color:#bbb;font-size:.8rem;"><?php echo $offset + $i + 1; ?></td>
                            <td>
                                <div class="cust-cell">
                                    <div class="cust-avatar">
                                        <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <a href="customer_profile.php?id=<?php echo $c['id']; ?>" class="cust-name">
                                            <?php echo htmlspecialchars($c['name']); ?>
                                        </a>
                                        <div class="cust-phone"><?php echo htmlspecialchars($c['phone']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="occupation-text"><?php echo htmlspecialchars($c['occupation']) ?: '—'; ?></span></td>
                            <td><span class="amount-text">RWF <?php echo number_format($c['amount_to_pay'], 2); ?></span></td>
                            <?php if (!$sectorId): ?>
                            <td><span class="badge-sector"><?php echo htmlspecialchars($c['sector_name']); ?></span></td>
                            <?php endif; ?>
                            <td>
                                <div class="action-btns">
                                    <a href="customer_profile.php?id=<?php echo $c['id']; ?>" class="act-btn act-view">View</a>
                                    <a href="edit_customer.php?id=<?php echo $c['id']; ?>"    class="act-btn act-edit">Edit</a>
                                    <a href="add_payment.php?customer_id=<?php echo $c['id']; ?>" class="act-btn act-pay">+ Pay</a>
                                    <button class="act-btn act-delete"
                                            onclick="confirmDelete(<?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>')">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1):
                    $qs = [];
                    if ($sectorId) $qs['sector_id'] = $sectorId;
                    if ($search)   $qs['search']    = $search;
                    $buildUrl = function($p) use ($qs) { $qs['page'] = $p; return 'customers.php?' . http_build_query($qs); };
                ?>
                <div class="pagination">
                    <a href="<?php echo $buildUrl(1); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">« First</a>
                    <a href="<?php echo $buildUrl(max(1, $page - 1)); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">‹ Prev</a>

                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <a href="<?php echo $buildUrl($p); ?>" class="<?php echo $p == $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>

                    <a href="<?php echo $buildUrl(min($totalPages, $page + 1)); ?>" class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next ›</a>
                    <a href="<?php echo $buildUrl($totalPages); ?>"                class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Last »</a>
                    <span class="pg-info">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">👥</div>
                <h3>No customers found</h3>
                <?php if ($search): ?>
                <p>No results for "<?php echo htmlspecialchars($search); ?>". Try a different search.</p>
                <?php else: ?>
                <p>Get started by adding or importing customers.</p>
                <div style="display:flex;gap:.75rem;justify-content:center;margin-top:1.25rem;">
                    <a href="add_customer.php" class="btn btn-primary btn-sm">+ Add Customer</a>
                    <a href="import_customers.php" class="btn btn-secondary btn-sm">↑ Import</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Delete modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-icon">🗑️</div>
            <h3>Delete Customer?</h3>
            <p id="modalMsg"></p>
            <div class="modal-actions">
                <button class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
                <a href="#" id="modalConfirmLink" class="btn-modal-confirm">Delete</a>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(id, name) {
            const qs = new URLSearchParams(window.location.search);
            const sectorParam = qs.get('sector_id') ? '&sector_id=' + qs.get('sector_id') : '';
            document.getElementById('modalMsg').innerHTML =
                `Deleting <strong>${name}</strong> will permanently remove all their payment records. This cannot be undone.`;
            document.getElementById('modalConfirmLink').href =
                'customers.php?action=delete&id=' + id + sectorParam;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // Auto-search with debounce
        (function () {
            const input = document.getElementById('searchInput');
            const form  = document.getElementById('searchForm');
            if (!input || !form) return;
            let timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(() => form.submit(), 400);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    input.value = '';
                    clearTimeout(timer);
                    form.submit();
                }
            });
        })();
    </script>
</body>
</html>
