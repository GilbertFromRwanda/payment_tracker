<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

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
        SELECT c.name, c.phone, c.occupation, c.amount_to_pay, s.sector_name
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        $expWhere
        ORDER BY c.name ASC
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
        SELECT c.name, c.phone, c.occupation, s.sector_name
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        $expWhere
        ORDER BY c.name ASC
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
    ORDER BY c.name ASC
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
    <title>Customers - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-header h1 { margin: 0; }

        /* Sector filter tabs */
        .sector-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .sector-tab {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            text-decoration: none;
            color: #555;
            font-size: 0.875rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .sector-tab:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        .sector-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .sector-tab .count {
            background: rgba(0,0,0,0.1);
            padding: 0.1rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .sector-tab.active .count {
            background: rgba(255,255,255,0.25);
        }

        /* Search bar */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            align-items: center;
        }
        .search-bar input {
            flex: 1;
            max-width: 400px;
            padding: 0.6rem 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }
        .search-bar input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        /* Summary strip */
        .summary-strip {
            display: flex;
            gap: 2rem;
            margin-bottom: 1.5rem;
            padding: 0.75rem 1.25rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            font-size: 0.9rem;
            color: #555;
            flex-wrap: wrap;
        }
        .summary-strip strong { color: #333; }

        /* Customer table */
        .customers-table { width: 100%; }
        .customers-table th { white-space: nowrap; }
        .customers-table td { vertical-align: middle; }
        .customer-name-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .customer-name-link:hover {
            text-decoration: underline;
        }
        .badge-sector {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            background: #e9ecef;
            border-radius: 12px;
            font-size: 0.8rem;
            color: #555;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.4rem;
            margin: 1.5rem 0;
        }
        .pagination a, .pagination span {
            padding: 0.4rem 0.8rem;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
            font-size: 0.875rem;
        }
        .pagination a:hover { background: #e9ecef; }
        .pagination .active { background: #667eea; color: white; border-color: #667eea; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #888;
        }
        .empty-state .icon { font-size: 2.5rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Welcome, <?php echo $_SESSION['username']; ?></div>
        <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Customers</h1>
                <div style="display:flex; gap:0.5rem;">
                    <?php if ($totalCustomers > 0): ?>
                    <a href="customers.php?<?php echo http_build_query(array_filter(['sector_id' => $sectorId, 'search' => $search, 'export' => 'html'])); ?>"
                       class="btn btn-success btn-sm" target="_blank">Export All Info</a>
                    <a href="customers.php?<?php echo http_build_query(array_filter(['sector_id' => $sectorId, 'search' => $search, 'export' => 'list'])); ?>"
                       class="btn btn-secondary btn-sm" target="_blank">Export Names Only</a>
                    <?php endif; ?>
                    <a href="import_customers.php" class="btn btn-primary btn-sm">Import Customers</a>
                </div>
            </div>

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

            <!-- Search -->
            <form method="GET" class="search-bar">
                <?php if ($sectorId): ?>
                <input type="hidden" name="sector_id" value="<?php echo $sectorId; ?>">
                <?php endif; ?>
                <input type="text" name="search" placeholder="Search by name, phone, or occupation..."
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search): ?>
                <a href="customers.php<?php echo $sectorId ? '?sector_id=' . $sectorId : ''; ?>" class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Summary -->
            <div class="summary-strip">
                <span><strong><?php echo number_format($totalCustomers); ?></strong> customers</span>
                <span>Total due: <strong>RWF <?php echo number_format($totalAmount, 2); ?></strong></span>
                <?php if ($sectorId):
                    $activeSector = array_filter($sectors, fn($s) => $s['id'] == $sectorId);
                    if ($activeSector): ?>
                <span>Sector: <strong><?php echo htmlspecialchars(reset($activeSector)['sector_name']); ?></strong></span>
                <?php endif; endif; ?>
                <?php if ($search): ?>
                <span>Search: <strong>"<?php echo htmlspecialchars($search); ?>"</strong></span>
                <?php endif; ?>
            </div>

            <!-- Customer Table -->
            <?php if (!empty($customers)): ?>
            <table class="data-table customers-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Occupation</th>
                        <th>Amount Due</th>
                        <?php if (!$sectorId): ?><th>Sector</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $i => $c): ?>
                    <tr>
                        <td><?php echo $offset + $i + 1; ?></td>
                        <td>
                            <a href="customer_profile.php?id=<?php echo $c['id']; ?>" class="customer-name-link">
                                <?php echo htmlspecialchars($c['name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($c['phone']); ?></td>
                        <td><?php echo htmlspecialchars($c['occupation']); ?></td>
                        <td><strong>RWF <?php echo number_format($c['amount_to_pay'], 2); ?></strong></td>
                        <?php if (!$sectorId): ?>
                        <td><span class="badge-sector"><?php echo htmlspecialchars($c['sector_name']); ?></span></td>
                        <?php endif; ?>
                        <td>
                            <a href="customer_profile.php?id=<?php echo $c['id']; ?>" class="btn btn-primary btn-sm" title="View Profile">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qs = [];
                if ($sectorId) $qs['sector_id'] = $sectorId;
                if ($search) $qs['search'] = $search;

                $buildUrl = function($p) use ($qs) {
                    $qs['page'] = $p;
                    return 'customers.php?' . http_build_query($qs);
                };
                ?>
                <a href="<?php echo $buildUrl(1); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                <a href="<?php echo $buildUrl(max(1, $page - 1)); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++): ?>
                <a href="<?php echo $buildUrl($p); ?>" class="<?php echo $p == $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>

                <a href="<?php echo $buildUrl(min($totalPages, $page + 1)); ?>" class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next</a>
                <a href="<?php echo $buildUrl($totalPages); ?>" class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Last</a>
                <span style="margin-left: 0.5rem; color: #888; border: none;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>No customers found</h3>
                <?php if ($search): ?>
                <p>No results for "<?php echo htmlspecialchars($search); ?>". Try a different search.</p>
                <?php else: ?>
                <p>Import customers to get started.</p>
                <a href="import_customers.php" class="btn btn-primary" style="margin-top: 1rem;">Import Customers</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
