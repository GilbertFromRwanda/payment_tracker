<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();


// Get filter parameters
$sectorId = $_GET['sector_id'] ?? null;
$customerId = $_GET['customer_id'] ?? null;
$yearFilter = $_GET['year'] ?? date('Y', strtotime('first day of last month'));
$monthFilter = $_GET['month'] ?? date('n', strtotime('first day of last month'));
$searchQuery = $_GET['search'] ?? '';
$missedOnly = isset($_GET['missed_only']) && $_GET['missed_only'] == '1';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50; // Number of records per page
$offset = ($page - 1) * $limit;


// ── Handle CSV / Excel exports ──
if (!empty($_GET['export']) && $_GET['export'] === 'excel') {

    // Re-run query without pagination to get ALL rows
    $exportResult = getFilteredReportData(
        $pdo,
        $sectorId,
        $searchQuery,
        $customerId,
        null,   // no limit
        0,
        $yearFilter,
        $monthFilter,
        $missedOnly
    );
    $rows = $exportResult['data'];

    // Calculate summary totals for the export
    $exportSummaryJoin = "c.id = p.customer_id";
    $exportSummaryParams = [];
    if ($yearFilter && $monthFilter) {
        $exportSummaryJoin .= " AND p.month_year = ?";
        $exportSummaryParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
    } elseif ($yearFilter) {
        $exportSummaryJoin .= " AND p.month_year LIKE ?";
        $exportSummaryParams[] = $yearFilter . '-%';
    }

    $exportSummaryQuery = "
        SELECT
            COUNT(DISTINCT c.id) as total_customers,
            SUM(c.amount_to_pay) as total_amount_due,
            COUNT(DISTINCT p.id) as total_payments,
            SUM(p.paid_amount) as total_amount_paid,
            COUNT(DISTINCT CASE WHEN p.id IS NULL THEN c.id END) as customers_without_payments,
            AVG(c.amount_to_pay) as avg_amount_per_customer
        FROM customers c
        LEFT JOIN payments p ON {$exportSummaryJoin}
        WHERE 1=1
    ";
    if ($sectorId) {
        $exportSummaryQuery .= " AND c.sector_id = ?";
        $exportSummaryParams[] = $sectorId;
    }
    if (!empty($searchQuery)) {
        $exportSummaryQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
        $searchParam = "%" . $searchQuery . "%";
        $exportSummaryParams[] = $searchParam;
        $exportSummaryParams[] = $searchParam;
        $exportSummaryParams[] = $searchParam;
    }
    if ($missedOnly) {
        if ($yearFilter && $monthFilter) {
            $monthStr = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
            $exportSummaryQuery .= " AND c.id NOT IN (SELECT customer_id FROM payments WHERE month_year = ?)";
            $exportSummaryParams[] = $monthStr;
        } elseif ($yearFilter) {
            $maxMonth = ($yearFilter == date('Y')) ? intval(date('m')) : 12;
            $exportSummaryQuery .= " AND (SELECT COUNT(DISTINCT month_year) FROM payments WHERE customer_id = c.id AND month_year LIKE ?) < ?";
            $exportSummaryParams[] = $yearFilter . '-%';
            $exportSummaryParams[] = $maxMonth;
        }
    }
    $exportSummaryStmt = $pdo->prepare($exportSummaryQuery);
    $exportSummaryStmt->execute($exportSummaryParams);
    $exportSummary = $exportSummaryStmt->fetch();

    $exportCollectionRate = ($exportSummary['total_amount_due'] ?? 0) > 0 ?
        round((($exportSummary['total_amount_paid'] ?? 0) / $exportSummary['total_amount_due']) * 100, 1) : 0;

    $filename = 'payment_report_' . date('Y-m-d');
    $headers_row = ['#', 'Name', 'Phone', 'Occupation', 'Sector', 'Amount Due (RWF)',
                    'Payment Count', 'Payment Rate (%)',
                    'Missing Months', 'Missing Count', 'Last Payment'];

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Payment Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    echo '<body><table border="1">';

    // Title row
    echo '<tr><th colspan="' . count($headers_row) . '" style="background:#1e293b;color:#fff;font-size:14pt;padding:10px;">Payment Report — ' . date('d M Y') . '</th></tr>';

    // Summary cards row
    $summaryStyle = 'style="background:#f8fafc;padding:8px 12px;font-size:10pt;"';
    $summaryValueStyle = 'style="background:#f8fafc;padding:8px 12px;font-size:10pt;font-weight:bold;"';
    echo '<tr><td colspan="' . count($headers_row) . '" style="padding:0;border:none;">';
    echo '<table border="1" width="100%" style="border-collapse:collapse;">';
    echo '<tr>';
    echo '<td ' . $summaryStyle . '>Total Customers</td>';
    echo '<td ' . $summaryValueStyle . '>' . number_format($exportSummary['total_customers'] ?? 0) . '</td>';
    echo '<td ' . $summaryStyle . '>Total Amount Due</td>';
    echo '<td ' . $summaryValueStyle . '>RWF ' . number_format($exportSummary['total_amount_due'] ?? 0, 2) . '</td>';
    echo '<td ' . $summaryStyle . '>Total Collected</td>';
    echo '<td ' . $summaryValueStyle . '>RWF ' . number_format($exportSummary['total_amount_paid'] ?? 0, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td ' . $summaryStyle . '>Total Payments</td>';
    echo '<td ' . $summaryValueStyle . '>' . number_format($exportSummary['total_payments'] ?? 0) . '</td>';
    echo '<td ' . $summaryStyle . '>Avg Amount/Customer</td>';
    echo '<td ' . $summaryValueStyle . '>RWF ' . number_format($exportSummary['avg_amount_per_customer'] ?? 0, 2) . '</td>';
    echo '<td ' . $summaryStyle . '>Collection Rate</td>';
    echo '<td ' . $summaryValueStyle . '>' . $exportCollectionRate . '%</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td ' . $summaryStyle . '>Without Payments</td>';
    echo '<td ' . $summaryValueStyle . '>' . number_format($exportSummary['customers_without_payments'] ?? 0) . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</td></tr>';

    // Empty spacer row
    echo '<tr><td colspan="' . count($headers_row) . '" style="border:none;">&nbsp;</td></tr>';

    // Header row
    echo '<tr>';
    foreach ($headers_row as $h) {
        echo '<th style="background:#334155;color:#fff;font-weight:bold;padding:6px 10px;">' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr>';

    // Data rows
    $totalAmountDueSum = 0;
    $totalPaidCountSum = 0;
    $totalMissingSum = 0;
    foreach ($rows as $i => $c) {
        $rate = floatval($c['payment_rate']);
        $rateColor = $rate >= 80 ? '#dcfce7' : ($rate >= 50 ? '#fef9c3' : '#fee2e2');
        $totalAmountDueSum += floatval($c['amount_to_pay']);
        $totalPaidCountSum += intval($c['paid_count']);
        $totalMissingSum += intval($c['total_missing']);
        echo '<tr>';
        echo '<td>' . ($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($c['name']) . '</td>';
        echo '<td>' . htmlspecialchars($c['phone']) . '</td>';
        echo '<td>' . htmlspecialchars($c['occupation']) . '</td>';
        echo '<td>' . htmlspecialchars($c['sector_name']) . '</td>';
        echo '<td style="text-align:right">' . number_format($c['amount_to_pay']) . '</td>';
        echo '<td style="text-align:center">' . intval($c['paid_count']) . '</td>';
        echo '<td style="background:' . $rateColor . ';text-align:center">' . $c['payment_rate'] . '%</td>';
        echo '<td>' . htmlspecialchars(implode(', ', $c['missing_months'] ?? [])) . '</td>';
        echo '<td style="text-align:center">' . intval($c['total_missing']) . '</td>';
        echo '<td>' . htmlspecialchars($c['last_payment'] ?? 'Never') . '</td>';
        echo '</tr>';
    }

    // Totals row at bottom of table
    $avgRate = count($rows) > 0 ? round(array_sum(array_column($rows, 'payment_rate')) / count($rows), 1) : 0;
    echo '<tr>';
    echo '<td colspan="5" style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;text-align:right;">TOTALS</td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;text-align:right;">RWF ' . number_format($totalAmountDueSum) . '</td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;text-align:center;">' . number_format($totalPaidCountSum) . '</td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;text-align:center;">' . $avgRate . '%</td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;"></td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;text-align:center;">' . number_format($totalMissingSum) . '</td>';
    echo '<td style="background:#1e293b;color:#fff;font-weight:bold;padding:8px 10px;"></td>';
    echo '</tr>';

    echo '</table></body></html>';
    exit;
}

// Get sectors for filter dropdown
$sectors = $pdo->query("SELECT * FROM sectors ORDER BY sector_name")->fetchAll();

// Build base query for report data with search functionality

// Build base query for report data with search functionality
function getFilteredReportData($pdo, $sectorId = null, $searchQuery = '', $customerId = null, $limit = null, $offset = 0, $yearFilter = null, $monthFilter = null, $missedOnly = false) {
    $joinParams = [];
    $joinCondition = "c.id = p.customer_id";

    if ($yearFilter && $monthFilter) {
        $joinCondition .= " AND p.month_year = ?";
        $joinParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
    } elseif ($yearFilter) {
        $joinCondition .= " AND p.month_year LIKE ?";
        $joinParams[] = $yearFilter . '-%';
    }

    $query = "
        SELECT
            c.id as customer_id,
            c.name,
            c.phone,
            c.occupation,
            c.amount_to_pay,
            s.sector_name,
            GROUP_CONCAT(p.month_year ORDER BY p.month_year) as paid_months,
            COUNT(p.month_year) as paid_count,
            MAX(p.payment_date) as last_payment
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        LEFT JOIN payments p ON {$joinCondition}
        WHERE 1=1
    ";

    $params = [];
    $conditions = [];
    
    if ($sectorId) {
        $conditions[] = "c.sector_id = ?";
        $params[] = $sectorId;
    }
    
    if ($customerId) {
        $conditions[] = "c.id = ?";
        $params[] = $customerId;
    }
    
    if (!empty($searchQuery)) {
        $conditions[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
        $searchParam = "%" . $searchQuery . "%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if ($missedOnly) {
        if ($yearFilter && $monthFilter) {
            $monthStr = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
            $conditions[] = "c.id NOT IN (SELECT customer_id FROM payments WHERE month_year = ?)";
            $params[] = $monthStr;
        } elseif ($yearFilter) {
            $maxMonth = ($yearFilter == date('Y')) ? intval(date('m')) : 12;
            $conditions[] = "(SELECT COUNT(DISTINCT month_year) FROM payments WHERE customer_id = c.id AND month_year LIKE ?) < ?";
            $params[] = $yearFilter . '-%';
            $params[] = $maxMonth;
        }
    }

    if (!empty($conditions)) {
        $query .= " AND " . implode(" AND ", $conditions);
    }
    
    $query .= " GROUP BY c.id";
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(DISTINCT c.id) as total FROM customers c LEFT JOIN sectors s ON c.sector_id = s.id WHERE 1=1";
    if (!empty($conditions)) {
        $countQuery .= " AND " . implode(" AND ", $conditions);
    }
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch()['total'];
    
    // Add ordering and pagination to main query
    $query .= " ORDER BY c.name ASC";
    
    if ($limit !== null) {
        // FIX: LIMIT and OFFSET must be integers, not bound parameters
        $query .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(array_merge($joinParams, $params));
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate missing months and payment rate for each customer
    foreach ($data as &$customer) {
        $customer['missing_months'] = getMissingMonths($customer['customer_id'], $yearFilter, $monthFilter);
        $customer['total_missing'] = count($customer['missing_months']);
        $customer['payment_rate'] = $customer['paid_count'] > 0 ? 
            round(($customer['paid_count'] / ($customer['paid_count'] + $customer['total_missing'])) * 100, 1) : 0;
    }
    
    return [
        'data' => $data,
        'total' => $totalCount,
        'page' => isset($_GET['page']) ? intval($_GET['page']) : 1,
        'limit' => $limit,
        'offset' => $offset
    ];
}
// Get paginated report data
$reportResult = getFilteredReportData($pdo, $sectorId, $searchQuery, $customerId, $limit, $offset, $yearFilter, $monthFilter, $missedOnly);
$reportData = $reportResult['data'];
$totalCustomers = $reportResult['total'];
$totalPages = ceil($totalCustomers / $limit);

// Get payment summary statistics
$summaryJoin = "c.id = p.customer_id";
$summaryParams = [];
if ($yearFilter && $monthFilter) {
    $summaryJoin .= " AND p.month_year = ?";
    $summaryParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
} elseif ($yearFilter) {
    $summaryJoin .= " AND p.month_year LIKE ?";
    $summaryParams[] = $yearFilter . '-%';
}

$summaryQuery = "
    SELECT
        COUNT(DISTINCT c.id) as total_customers,
        SUM(c.amount_to_pay) as total_amount_due,
        COUNT(DISTINCT p.id) as total_payments,
        SUM(p.paid_amount) as total_amount_paid,
        COUNT(DISTINCT CASE WHEN p.id IS NULL THEN c.id END) as customers_without_payments,
        AVG(c.amount_to_pay) as avg_amount_per_customer,
        MAX(p.payment_date) as latest_payment_date
    FROM customers c
    LEFT JOIN payments p ON {$summaryJoin}
    WHERE 1=1
";

if ($sectorId) {
    $summaryQuery .= " AND c.sector_id = ?";
    $summaryParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $summaryQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    $summaryParams[] = $searchParam;
    $summaryParams[] = $searchParam;
    $summaryParams[] = $searchParam;
}
if ($missedOnly) {
    if ($yearFilter && $monthFilter) {
        $monthStr = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
        $summaryQuery .= " AND c.id NOT IN (SELECT customer_id FROM payments WHERE month_year = ?)";
        $summaryParams[] = $monthStr;
    } elseif ($yearFilter) {
        $maxMonth = ($yearFilter == date('Y')) ? intval(date('m')) : 12;
        $summaryQuery .= " AND (SELECT COUNT(DISTINCT month_year) FROM payments WHERE customer_id = c.id AND month_year LIKE ?) < ?";
        $summaryParams[] = $yearFilter . '-%';
        $summaryParams[] = $maxMonth;
    }
}

$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($summaryParams);
$summaryStats = $summaryStmt->fetch();

// Get monthly payment trend
$trendQuery = "
    SELECT
        p.month_year,
        COUNT(DISTINCT p.customer_id) as paying_customers,
        COUNT(p.id) as payment_count,
        SUM(p.paid_amount) as monthly_total,
        AVG(p.paid_amount) as avg_payment
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    WHERE 1=1
";

$trendParams = [];
if ($yearFilter && $monthFilter) {
    $trendQuery .= " AND p.month_year = ?";
    $trendParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
} elseif ($yearFilter) {
    $trendQuery .= " AND p.month_year LIKE ?";
    $trendParams[] = $yearFilter . '-%';
}
if ($sectorId) {
    $trendQuery .= " AND c.sector_id = ?";
    $trendParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $trendQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    $trendParams[] = $searchParam;
    $trendParams[] = $searchParam;
    $trendParams[] = $searchParam;
}

$trendQuery .= " GROUP BY p.month_year ORDER BY p.month_year DESC LIMIT 12";
$trendStmt = $pdo->prepare($trendQuery);
$trendStmt->execute($trendParams);
$paymentTrend = $trendStmt->fetchAll();

// Get top customers by payment consistency
$consistencyJoin = "c.id = p.customer_id";
$consistencyParams = [];
if ($yearFilter && $monthFilter) {
    $consistencyJoin .= " AND p.month_year = ?";
    $consistencyParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
} elseif ($yearFilter) {
    $consistencyJoin .= " AND p.month_year LIKE ?";
    $consistencyParams[] = $yearFilter . '-%';
}

$consistencyQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        s.sector_name,
        COUNT(p.id) as months_paid,
        MAX(p.payment_date) as last_payment,
        MIN(p.payment_date) as first_payment,
        SUM(p.paid_amount) as total_paid,
        c.amount_to_pay as monthly_due,
        ROUND(COUNT(p.id) * 100.0 / GREATEST(TIMESTAMPDIFF(MONTH, MIN(p.payment_date), NOW()), 1), 1) as consistency_rate
    FROM customers c
    LEFT JOIN payments p ON {$consistencyJoin}
    JOIN sectors s ON c.sector_id = s.id
    WHERE 1=1
";

if ($sectorId) {
    $consistencyQuery .= " AND c.sector_id = ?";
    $consistencyParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $consistencyQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    $consistencyParams[] = $searchParam;
    $consistencyParams[] = $searchParam;
    $consistencyParams[] = $searchParam;
}

$consistencyQuery .= " GROUP BY c.id HAVING months_paid > 0 ORDER BY consistency_rate DESC LIMIT 10";
$consistencyStmt = $pdo->prepare($consistencyQuery);
$consistencyStmt->execute($consistencyParams);
$consistentCustomers = $consistencyStmt->fetchAll();

// Get customers with most missed payments
$missedJoin = "c.id = p.customer_id";
$missedParams = [];
if ($yearFilter && $monthFilter) {
    $missedJoin .= " AND p.month_year = ?";
    $missedParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
} elseif ($yearFilter) {
    $missedJoin .= " AND p.month_year LIKE ?";
    $missedParams[] = $yearFilter . '-%';
}

$missedQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        s.sector_name,
        c.amount_to_pay,
        COUNT(p.id) as months_paid,
        MAX(p.payment_date) as last_payment,
        DATEDIFF(NOW(), COALESCE(MAX(p.payment_date), c.created_at)) as days_since_last_payment
    FROM customers c
    LEFT JOIN payments p ON {$missedJoin}
    JOIN sectors s ON c.sector_id = s.id
    WHERE 1=1
";

if ($sectorId) {
    $missedQuery .= " AND c.sector_id = ?";
    $missedParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $missedQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    $missedParams[] = $searchParam;
    $missedParams[] = $searchParam;
    $missedParams[] = $searchParam;
}

$missedQuery .= " GROUP BY c.id 
                  HAVING days_since_last_payment > 90 OR months_paid = 0 
                  ORDER BY days_since_last_payment DESC 
                  LIMIT 10";
$missedStmt = $pdo->prepare($missedQuery);
$missedStmt->execute($missedParams);
$customersNeedingAttention = $missedStmt->fetchAll();

// Get all months from first payment to current (filtered by year/month)
if ($yearFilter && $monthFilter) {
    $months = [$yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT)];
} elseif ($yearFilter) {
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthStr = $yearFilter . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        if ($monthStr <= date('Y-m')) {
            $months[] = $monthStr;
        }
    }
} else {
    $firstPayment = $pdo->query("SELECT MIN(payment_date) as first_date FROM payments")->fetch();
    $firstDate = $firstPayment['first_date'] ? date('Y-m', strtotime($firstPayment['first_date'])) : date('Y-m');
    $months = [];
    $current = new DateTime($firstDate . '-01');
    $now = new DateTime();
    while ($current <= $now) {
        $months[] = $current->format('Y-m');
        $current->modify('+1 month');
    }
}

// Calculate monthly coverage
$monthlyCoverage = [];
foreach ($months as $month) {
    $coverageQuery = "
        SELECT COUNT(DISTINCT p.customer_id) as paying_customers,
               (SELECT COUNT(DISTINCT id) FROM customers WHERE 1=1" . 
               ($sectorId ? " AND sector_id = ?" : "") . 
               (!empty($searchQuery) ? " AND (name LIKE ? OR phone LIKE ? OR occupation LIKE ?)" : "") . 
               ") as total_customers
        FROM payments p
        JOIN customers c ON p.customer_id = c.id
        WHERE p.month_year = ?
        " . ($sectorId ? " AND c.sector_id = ?" : "") . 
        (!empty($searchQuery) ? " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)" : "");
    
    $coverageStmt = $pdo->prepare($coverageQuery);
    $coverageParams = [];
    
    if ($sectorId) {
        $coverageParams[] = $sectorId;
    }
    if (!empty($searchQuery)) {
        $searchParam = "%" . $searchQuery . "%";
        $coverageParams[] = $searchParam;
        $coverageParams[] = $searchParam;
        $coverageParams[] = $searchParam;
    }
    
    $coverageParams[] = $month;
    
    if ($sectorId) {
        $coverageParams[] = $sectorId;
    }
    if (!empty($searchQuery)) {
        $searchParam = "%" . $searchQuery . "%";
        $coverageParams[] = $searchParam;
        $coverageParams[] = $searchParam;
        $coverageParams[] = $searchParam;
    }
    
    $coverageStmt->execute($coverageParams);
    $coverage = $coverageStmt->fetch();
    $monthlyCoverage[$month] = $coverage ? 
        round(($coverage['paying_customers'] / max(1, $coverage['total_customers'])) * 100, 1) : 0;
}

// Get current month and previous month for comparison
if ($yearFilter && $monthFilter) {
    $currentMonth = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
    $previousMonth = date('Y-m', strtotime($currentMonth . '-01 -1 month'));
} elseif ($yearFilter) {
    $currentMonth = $yearFilter . '-' . date('m');
    $previousMonth = date('Y-m', strtotime($currentMonth . '-01 -1 month'));
} else {
    $currentMonth = date('Y-m');
    $previousMonth = date('Y-m', strtotime('-1 month'));
}

// Get current month stats
$currentMonthQuery = "
    SELECT COUNT(DISTINCT p.customer_id) as customers, SUM(p.paid_amount) as total
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    WHERE p.month_year = ?
";

$currentMonthParams = [$currentMonth];
if ($sectorId) {
    $currentMonthQuery .= " AND c.sector_id = ?";
    $currentMonthParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $currentMonthQuery .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.occupation LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    $currentMonthParams[] = $searchParam;
    $currentMonthParams[] = $searchParam;
    $currentMonthParams[] = $searchParam;
}

$currentMonthStmt = $pdo->prepare($currentMonthQuery);
$currentMonthStmt->execute($currentMonthParams);
$currentMonthStats = $currentMonthStmt->fetch();

// Get previous month stats
$previousMonthQuery = str_replace($currentMonth, $previousMonth, $currentMonthQuery);
$previousMonthParams = [$previousMonth];
if ($sectorId) {
    $previousMonthParams[] = $sectorId;
}
if (!empty($searchQuery)) {
    $searchParam = "%" . $searchQuery . "%";
    $previousMonthParams[] = $searchParam;
    $previousMonthParams[] = $searchParam;
    $previousMonthParams[] = $searchParam;
}

$previousMonthStmt = $pdo->prepare($previousMonthQuery);
$previousMonthStmt->execute($previousMonthParams);
$previousMonthStats = $previousMonthStmt->fetch();

// Calculate month-over-month change
$currentCustomers = $currentMonthStats['customers'] ?? 0;
$previousCustomers = $previousMonthStats['customers'] ?? 0;
$currentTotal = $currentMonthStats['total'] ?? 0;
$previousTotal = $previousMonthStats['total'] ?? 0;

$customerChange = $previousCustomers > 0 ? 
    round((($currentCustomers - $previousCustomers) / $previousCustomers) * 100, 1) : 
    ($currentCustomers > 0 ? 100 : 0);

$revenueChange = $previousTotal > 0 ? 
    round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1) : 
    ($currentTotal > 0 ? 100 : 0);

// Calculate statistics for the current filtered set
$avgPaymentRate = count($reportData) > 0 ? 
    array_sum(array_column($reportData, 'payment_rate')) / count($reportData) : 0;

$missingCustomersCount = count(array_filter($reportData, fn($c) => !empty($c['missing_months'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Reports - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Search and Filter Section */
        .search-filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .search-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 992px) {
            .search-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .search-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .search-box {
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-results-info {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: #666;
        }
        
        .search-results-info strong {
            color: #333;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 2rem 0;
            gap: 0.5rem;
        }
        
        .pagination-btn {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            color: #333;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .pagination-btn:hover {
            background: #e9ecef;
        }
        
        .pagination-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .pagination-info {
            margin-left: 1rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .summary-card.total::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .summary-card.paid::before { background: linear-gradient(90deg, #28a745, #20c997); }
        .summary-card.due::before { background: linear-gradient(90deg, #fd7e14, #ffc107); }
        .summary-card.missing::before { background: linear-gradient(90deg, #dc3545, #e83e8c); }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0.5rem 0;
            line-height: 1;
        }
        
        .summary-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .summary-detail {
            font-size: 0.8rem;
            color: #888;
            margin-top: 0.5rem;
        }
        
        .summary-change {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            display: inline-block;
            margin-top: 0.5rem;
        }
        
        .change-positive {
            background: #d4edda;
            color: #155724;
        }
        
        .change-negative {
            background: #f8d7da;
            color: #721c24;
        }
        
        .change-neutral {
            background: #e2e3e5;
            color: #383d41;
        }
        
        /* Table Controls */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .table-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-btn-small {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            color: #333;
            text-decoration: none;
            font-size: 0.875rem;
            transition: all 0.3s;
        }
        
        .action-btn-small:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        /* Sortable Table Headers */
        .sortable {
            cursor: pointer;
            user-select: none;
        }
        
        .sortable:hover {
            background: #f8f9fa;
        }
        
        .sortable .sort-icon {
            margin-left: 0.5rem;
            opacity: 0.5;
        }
        
        .sortable.asc .sort-icon::after {
            content: '↑';
        }
        
        .sortable.desc .sort-icon::after {
            content: '↓';
        }
        
        /* Quick Actions in Table */
        .quick-actions {
            display: flex;
            gap: 0.25rem;
        }
        
        .action-icon {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            background: #f8f9fa;
            color: #666;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .action-icon:hover {
            background: #667eea;
            color: white;
        }
        
        /* Loading Overlay for Table */
        .table-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 100;
            display: none;
        }
        
        .table-loading.active {
            display: flex;
        }
        
        /* Export Options */
        .export-options {
            position: relative;
        }
        
        .export-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            min-width: 150px;
            display: none;
            z-index: 1000;
        }
        
        .export-dropdown.show {
            display: block;
        }
        
        .export-option {
            display: block;
            padding: 0.75rem 1rem;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.2s;
        }
        
        .export-option:last-child {
            border-bottom: none;
        }
        
        .export-option:hover {
            background: #f8f9fa;
        }
        
        /* Responsive Table */
        @media (max-width: 768px) {
            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-actions {
                justify-content: center;
            }
        }
        
        /* Quick Filter Chips */
        .filter-chips {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .filter-chip {
            padding: 0.5rem 1rem;
            background: #e9ecef;
            border-radius: 20px;
            font-size: 0.875rem;
            color: #666;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-chip.active {
            background: #667eea;
            color: white;
        }
        
        .filter-chip .remove {
            cursor: pointer;
            font-size: 1.2rem;
            line-height: 1;
        }
        
        /* Customer Details Modal Trigger */
        .customer-details-trigger {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
        }
        
        .customer-details-trigger:hover {
            text-decoration: underline;
        }
        /* ── Export Controls ── */
.btn-export-main {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: #0f172a;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, transform 0.1s;
}
.btn-export-main:hover { background: #1e293b; transform: translateY(-1px); }
.btn-export-main:active { transform: translateY(0); }

.export-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.12);
  min-width: 220px;
  z-index: 1000;
  padding: 6px;
  animation: exportMenuIn 0.15s ease;
}
@keyframes exportMenuIn {
  from { opacity:0; transform: translateY(-6px); }
  to   { opacity:1; transform: translateY(0); }
}

.export-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 7px;
  color: #1e293b;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: background 0.12s;
  cursor: pointer;
}
.export-item:hover { background: #f1f5f9; }
.export-item svg { flex-shrink: 0; margin-top: 2px; }
.export-hint {
  display: block;
  font-size: 11px;
  color: #94a3b8;
  font-weight: 400;
  margin-top: 1px;
}

/* Spinner */
.export-spinner {
  width: 40px; height: 40px;
  border: 4px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  margin: 0 auto;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Print Styles ── */
@media print {
  /* Hide everything but the report */
  header, nav, .sidebar, .filters-panel, .export-controls,
  .pagination, .btn-export-main, #exportMenu, .search-bar,
  .action-col, .no-print { display: none !important; }

  body { background: #fff !important; font-size: 12px; }

  .print-header { display: block !important; }

  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #1e293b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  th, td { border: 1px solid #cbd5e1; padding: 6px 8px; }
  tr:nth-child(even) td { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  /* Force table to show all rows (not just current page) */
  .table-container { overflow: visible !important; }

  a[href]:after { content: none !important; }
  @page { margin: 1.5cm; size: A4 landscape; }
}

/* Hidden by default, shown only during print */
.print-header { display: none; }
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
            <h1>Payment Reports & Tracking</h1>
            
            <!-- Search and Filter Section -->
            <div class="search-filter-section">
                <form method="GET" id="searchForm">
                    <div class="search-grid">
                        <!-- Search Input -->
                        <div class="form-group search-box">
                            <label for="search">Search Customers</label>
                            <div class="search-icon"></div>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="search-input" 
                                   placeholder="Search by name, phone, or occupation..." 
                                   value="<?php echo htmlspecialchars($searchQuery); ?>"
                                   autocomplete="off">
                            <div class="search-results-info">
                                Found <strong><?php echo $totalCustomers; ?></strong> customers 
                                <?php if (!empty($searchQuery)): ?>
                                matching "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Sector Filter -->
                        <div class="form-group">
                            <label for="sector_id">Sector</label>
                            <select id="sector_id" name="sector_id" class="form-control">
                                <option value="">All Sectors</option>
                                <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>" 
                                    <?php echo $sectorId == $sector['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Year Filter -->
                        <div class="form-group">
                            <label for="year">Year</label>
                            <select id="year" name="year" class="form-control">
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>"
                                    <?php echo $yearFilter == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <!-- Month Filter -->
                        <div class="form-group">
                            <label for="month">Month</label>
                            <select id="month" name="month" class="form-control">
                                <option value="">All Months</option>
                                <?php
                                $monthNames = [
                                    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
                                ];
                                foreach ($monthNames as $num => $name): ?>
                                <option value="<?php echo $num; ?>"
                                    <?php echo $monthFilter == $num ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Payment Status Filter -->
                        <div class="form-group">
                            <label for="missed_only">Payment Status</label>
                            <select id="missed_only" name="missed_only" class="form-control">
                                <option value="">All Customers</option>
                                <option value="1" <?php echo $missedOnly ? 'selected' : ''; ?>>Missed Payments Only</option>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <span class="btn-icon">🔍</span> Search
                            </button>
                            <a href="reports.php" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                                <span class="btn-icon">🔄</span> Reset
                            </a>
                        </div>
                    </div>
                    
                    <!-- Hidden fields for pagination -->
                    <input type="hidden" name="page" id="pageInput" value="<?php echo $page; ?>">
                </form>
                
                <!-- Active Filters Display -->
                <?php if ($sectorId || !empty($searchQuery) || $yearFilter || $monthFilter || $missedOnly): ?>
                <div class="filter-chips">
                    <?php if (!empty($searchQuery)): ?>
                    <div class="filter-chip">
                        Search: <?php echo htmlspecialchars($searchQuery); ?>
                        <span class="remove" onclick="removeFilter('search')">×</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($sectorId):
                        $selectedSector = array_filter($sectors, fn($s) => $s['id'] == $sectorId);
                        if (!empty($selectedSector)): ?>
                    <div class="filter-chip">
                        Sector: <?php echo htmlspecialchars(reset($selectedSector)['sector_name']); ?>
                        <span class="remove" onclick="removeFilter('sector_id')">×</span>
                    </div>
                    <?php endif; endif; ?>

                    <div class="filter-chip">
                        Year: <?php echo htmlspecialchars($yearFilter); ?>
                    </div>

                    <?php if ($monthFilter): ?>
                    <div class="filter-chip">
                        Month: <?php echo $monthNames[$monthFilter] ?? $monthFilter; ?>
                        <span class="remove" onclick="removeFilter('month')">×</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($missedOnly): ?>
                    <div class="filter-chip active">
                        Missed Payments Only
                        <span class="remove" onclick="removeFilter('missed_only')">×</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Stats Summary -->
            <div class="summary-grid">
                <div class="summary-card total">
                    <div class="summary-label">Customers Found</div>
                    <div class="summary-value"><?php echo $totalCustomers; ?></div>
                    <div class="summary-detail">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                    </div>
                </div>
                
                <div class="summary-card due">
                    <div class="summary-label">Total Amount Due</div>
                    <div class="summary-value">RWF <?php echo number_format($summaryStats['total_amount_due'] ?? 0, 2); ?></div>
                    <div class="summary-detail">
                        Avg: RWF <?php echo number_format($summaryStats['avg_amount_per_customer'] ?? 0, 2); ?>
                    </div>
                </div>
                
                <div class="summary-card paid">
                    <div class="summary-label">Total Collected</div>
                    <div class="summary-value">RWF <?php echo number_format($summaryStats['total_amount_paid'] ?? 0, 2); ?></div>
                    <div class="summary-detail">
                        <?php echo $summaryStats['total_payments'] ?? 0; ?> payments
                    </div>
                </div>
                
                <div class="summary-card missing">
                    <div class="summary-label">Collection Rate</div>
                    <div class="summary-value">
                        <?php 
                        $collectionRate = ($summaryStats['total_amount_due'] ?? 0) > 0 ? 
                            number_format((($summaryStats['total_amount_paid'] ?? 0) / ($summaryStats['total_amount_due'] ?? 0)) * 100, 1) : 0;
                        echo $collectionRate; ?>%
                    </div>
                    <div class="summary-detail">
                        <?php echo $summaryStats['customers_without_payments'] ?? 0; ?> without payments
                    </div>
                </div>
            </div>
            
            <!-- Table Controls -->
            <div class="table-controls">
                <div>
                    <h2>Customer Payment Details</h2>
                    <div style="color: #666; font-size: 0.9rem;">
                        Showing <?php echo min($limit, count($reportData)); ?> of <?php echo $totalCustomers; ?> customers
                    </div>
                </div>
                
                <div class="table-actions">
                    <!-- Export / Print Controls -->
<div class="export-controls" style="position:relative; display:inline-block;">
  <button class="btn-export-main" id="exportToggle" onclick="toggleExportMenu(event)">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Export / Print
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:4px"><polyline points="6 9 12 15 18 9"/></svg>
  </button>

  <div class="export-menu" id="exportMenu" style="display:none;">
    <!-- <a class="export-item" href="#" onclick="exportData('csv')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Export as CSV
      <span class="export-hint">Spreadsheet compatible</span>
    </a> -->
    <a class="export-item" href="#" onclick="exportData('excel')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#1D6F42"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
      Export report
      <span class="export-hint">Full formatting (.xlsx)</span>
    </a>
    <!-- <hr style="margin:4px 0; border-color:#e5e7eb;">
    <a class="export-item" href="#" onclick="printReport()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Report
      <span class="export-hint">Print-optimized layout</span>
    </a> -->
  </div>
</div>

<!-- Export loading overlay -->
<div id="exportOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center; flex-direction:column; gap:12px;">
  <div style="background:#fff; border-radius:12px; padding:32px 48px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <div class="export-spinner"></div>
    <div style="margin-top:16px; font-weight:600; color:#1e293b;" id="exportOverlayMsg">Preparing export…</div>
    <div style="font-size:12px; color:#64748b; margin-top:4px;">This may take a moment for large datasets</div>
  </div>
</div>
                    <!-- <div class="export-options">
                        <button class="action-btn-small" onclick="toggleExportDropdown()">
                            <span class="btn-icon">📥</span> Export
                        </button>
                        <div class="export-dropdown" id="exportDropdown">
                            <a href="#" class="export-option" onclick="exportToCSV()">Export to CSV</a>
                            <a href="#" class="export-option" onclick="exportToExcel()">Export to Excel</a>
                            <a href="#" class="export-option" onclick="printReport()">Print Report</a>
                        </div>
                    </div> -->
                    
                    <button class="action-btn-small" onclick="refreshTable()">
                        <span class="btn-icon">🔄</span> Refresh
                    </button>
                     
                    
                    <select class="action-btn-small" onchange="changePageSize(this.value)" style="padding: 0.5rem;">
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 per page</option>
                    </select>
                </div>
            </div>
            
            <!-- Detailed Report Table -->
            <div class="report-table" style="position: relative;">
                <div class="table-loading" id="tableLoading">
                    <div class="loading-spinner" style="width: 40px; height: 40px;"></div>
                </div>
                
                <table class="data-table" id="customerTable">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable(0)"># <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(1)">Name <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(2)">Phone <span class="sort-icon"></span></th>
                            <th>Occupation</th>
                            <th class="sortable" onclick="sortTable(4)">Amount Due <span class="sort-icon"></span></th>
                            <th>Sector</th>
                            <th class="sortable" onclick="sortTable(6)">Paid Months <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(7)">Payment Rate <span class="sort-icon"></span></th>
                            <th>Missing Months</th>
                            <th class="sortable" onclick="sortTable(9)">Last Payment <span class="sort-icon"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i=1;
                        foreach ($reportData as $customer): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td>
                                <a href="#" class="customer-details-trigger" 
                                   onclick="showCustomerDetails(<?php echo $customer['customer_id']; ?>)">
                                    <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                            <td><?php echo htmlspecialchars($customer['occupation']); ?></td>
                            <td><strong>RWF <?php echo number_format($customer['amount_to_pay'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($customer['sector_name']); ?></td>
                            <td>
                                <?php if ($customer['paid_count'] > 0): ?>
                                <span style="color: #28a745; font-weight: 500;">
                                    <?php echo $customer['paid_count']; ?> months
                                </span>
                                <?php else: ?>
                                <span style="color: #dc3545;">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div style="flex: 1; height: 6px; background: #e9ecef; border-radius: 3px;">
                                        <div style="width: <?php echo $customer['payment_rate']; ?>%; height: 100%; 
                                                    background: <?php echo $customer['payment_rate'] >= 80 ? '#28a745' : ($customer['payment_rate'] >= 50 ? '#ffc107' : '#dc3545'); ?>; border-radius: 3px;">
                                        </div>
                                    </div>
                                    <span style="font-weight: 500; min-width: 40px;">
                                        <?php echo $customer['payment_rate']; ?>%
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($customer['missing_months'])): ?>
                                <div class="missing-months" style="max-width: 200px; font-size: 0.875rem;">
                                    <?php 
                                    $recentMissing = array_slice($customer['missing_months'], -3);
                                    echo implode(', ', $recentMissing);
                                    if (count($customer['missing_months']) > 3) {
                                        echo '... (' . count($customer['missing_months']) . ')';
                                    }
                                    ?>
                                </div>
                                <?php else: ?>
                                <span style="color: #28a745; font-weight: 500;">✅ All paid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['last_payment']): ?>
                                <?php echo date('M d, Y', strtotime($customer['last_payment'])); ?>
                                <?php else: ?>
                                <span style="color: #dc3545;">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="quick-actions">
                                    <a href="customer_profile.php?customer_id=<?php echo $customer['customer_id']; ?>" 
                                       class="action-icon" title="View Details">👁️</a>
                                    <!-- <a href="#" class="action-icon" title="Send Reminder" 
                                       onclick="sendReminder(<?php echo $customer['customer_id']; ?>)">📧</a> -->
                                    <!-- <a href="#" class="action-icon" title="Add Payment"
                                       onclick="addPayment(<?php echo $customer['customer_id']; ?>)">💰</a> -->
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportData)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 3rem; color: #666;">
                                <div style="font-size: 1.5rem; margin-bottom: 1rem;">📭</div>
                                <div style="font-size: 1.1rem; margin-bottom: 0.5rem;">No customers found</div>
                                <div style="font-size: 0.9rem; color: #888;">
                                    <?php if (!empty($searchQuery)): ?>
                                    Try a different search term or <a href="reports.php">clear filters</a>
                                    <?php else: ?>
                                    <a href="import_customers.php">Import customers</a> to get started
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <!-- First Page -->
                <a href="?<?php echo buildQueryString(['page' => 1]); ?>" 
                   class="pagination-btn <?php echo $page == 1 ? 'disabled' : ''; ?>">
                    « First
                </a>
                
                <!-- Previous Page -->
                <a href="?<?php echo buildQueryString(['page' => max(1, $page - 1)]); ?>" 
                   class="pagination-btn <?php echo $page == 1 ? 'disabled' : ''; ?>">
                    ‹ Prev
                </a>
                
                <!-- Page Numbers -->
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) {
                    echo '<span class="pagination-btn disabled">...</span>';
                }
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <a href="?<?php echo buildQueryString(['page' => $i]); ?>" 
                   class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; 
                
                if ($endPage < $totalPages) {
                    echo '<span class="pagination-btn disabled">...</span>';
                }
                ?>
                
                <!-- Next Page -->
                <a href="?<?php echo buildQueryString(['page' => min($totalPages, $page + 1)]); ?>" 
                   class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                    Next ›
                </a>
                
                <!-- Last Page -->
                <a href="?<?php echo buildQueryString(['page' => $totalPages]); ?>" 
                   class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                    Last »
                </a>
                
                <span class="pagination-info">
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                </span>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Customer Details Modal (to be implemented) -->
    <div id="customerModal" style="display: none;">
        <!-- Modal content will be loaded via AJAX -->
    </div>
    
    <script src="js/script.js"></script>
    <script>
// ── Export menu toggle ──
function toggleExportMenu(e) {
  e.stopPropagation();
  const menu = document.getElementById('exportMenu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', () => {
  document.getElementById('exportMenu').style.display = 'none';
});

// ── Build export URL preserving all current filters ──
function buildExportUrl(format) {
  const params = new URLSearchParams(window.location.search);
  params.set('export', format);
  params.delete('page'); // always export all pages
  return window.location.pathname + '?' + params.toString();
}

// ── CSV / Excel export ──
function exportData(format) {
  document.getElementById('exportMenu').style.display = 'none';
  const overlay = document.getElementById('exportOverlay');
  const msg = document.getElementById('exportOverlayMsg');

  overlay.style.display = 'flex';
  msg.textContent = 'Generating report file…';

  // Create hidden iframe to trigger download without navigating
  const url = buildExportUrl(format);
  const iframe = document.createElement('iframe');
  iframe.style.display = 'none';
  iframe.src = url;
  document.body.appendChild(iframe);

  // Hide overlay after a short delay (download starts in background)
  setTimeout(() => {
    overlay.style.display = 'none';
    document.body.removeChild(iframe);
  }, 3000);

  return false;
}

// ── Print: inject summary header then trigger print ──
function printReport() {
  document.getElementById('exportMenu').style.display = 'none';

  // Build a summary of active filters for the print header
  const urlParams = new URLSearchParams(window.location.search);
  const filterParts = [];
  if (urlParams.get('sector_id'))   filterParts.push('Sector filtered');
  if (urlParams.get('year'))        filterParts.push('Year: ' + urlParams.get('year'));
  if (urlParams.get('month'))       filterParts.push('Month: ' + urlParams.get('month'));
  if (urlParams.get('search'))      filterParts.push('Search: "' + urlParams.get('search') + '"');
  if (urlParams.get('missed_only') == '1') filterParts.push('Missed payments only');

  // Inject or update print header
  let ph = document.querySelector('.print-header');
  if (!ph) {
    ph = document.createElement('div');
    ph.className = 'print-header';
    document.body.prepend(ph);
  }
  ph.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; border-bottom:2px solid #1e293b; padding-bottom:12px;">
      <div>
        <h1 style="margin:0; font-size:20px; color:#1e293b;">Payment Tracker — Report</h1>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">
          Generated: <?php echo date('d M Y, H:i'); ?> &nbsp;|&nbsp;
          ${filterParts.length ? filterParts.join(' &nbsp;|&nbsp; ') : 'All data (no filters)'}
        </div>
      </div>
      <div style="text-align:right; font-size:11px; color:#94a3b8;">
        Total customers shown: <?php echo $totalCustomers; ?>
      </div>
    </div>`;

  window.print();
}
</script>
    <script>
        // Build query string helper
        function buildQueryString(params) {
            const currentParams = new URLSearchParams(window.location.search);
            Object.keys(params).forEach(key => {
                if (params[key] !== undefined && params[key] !== null) {
                    currentParams.set(key, params[key]);
                } else {
                    currentParams.delete(key);
                }
            });
            return currentParams.toString();
        }
        
        // Remove filter
        function removeFilter(filterName) {
            const params = new URLSearchParams(window.location.search);
            params.delete(filterName);
            params.delete('page'); // Reset to first page
            window.location.search = params.toString();
        }
        
        // Export functions
        function exportToCSV() {
            const table = document.getElementById('customerTable');
            let csvContent = "data:text/csv;charset=utf-8,";
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cols = row.querySelectorAll('th, td');
                const rowArray = Array.from(cols).map(col => {
                    let text = col.textContent.trim();
                    // Remove action buttons and icons
                    if (col.querySelector('.quick-actions') || col.querySelector('.action-icon')) {
                        return '';
                    }
                    // Remove progress bar from payment rate
                    if (col.textContent.includes('%')) {
                        const rateSpan = col.querySelector('span');
                        if (rateSpan) text = rateSpan.textContent.trim();
                    }
                    return `"${text}"`;
                }).filter(col => col !== '""'); // Remove empty columns
                csvContent += rowArray.join(",") + "\r\n";
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `payment_report_${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function exportToExcel() {
            // For simplicity, we'll just export as CSV with .xls extension
            exportToCSV(); // You can enhance this with proper Excel export
        }
        
        function printReport() {
            window.print();
        }
        
        // Toggle export dropdown
        function toggleExportDropdown() {
            const dropdown = document.getElementById('exportDropdown');
            dropdown.classList.toggle('show');
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function closeDropdown(e) {
                if (!dropdown.contains(e.target) && !e.target.closest('.export-options')) {
                    dropdown.classList.remove('show');
                    document.removeEventListener('click', closeDropdown);
                }
            });
        }
        
        // Table sorting
        let currentSortColumn = null;
        let sortDirection = 'asc';
        
        function sortTable(columnIndex) {
            const table = document.getElementById('customerTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Remove previous sort indicators
            table.querySelectorAll('th').forEach(th => {
                th.classList.remove('asc', 'desc');
            });
            
            // Set new sort indicator
            const header = table.querySelectorAll('th')[columnIndex];
            if (currentSortColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = columnIndex;
                sortDirection = 'asc';
            }
            
            header.classList.add(sortDirection);
            
            // Sort rows
            rows.sort((a, b) => {
                const aText = a.cells[columnIndex].textContent.trim();
                const bText = b.cells[columnIndex].textContent.trim();
                
                // Handle numeric values
                const aNum = parseFloat(aText.replace(/[^0-9.-]+/g, ''));
                const bNum = parseFloat(bText.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return sortDirection === 'asc' ? aNum - bNum : bNum - aNum;
                }
                
                // Handle dates
                const aDate = Date.parse(aText);
                const bDate = Date.parse(bText);
                if (!isNaN(aDate) && !isNaN(bDate)) {
                    return sortDirection === 'asc' ? aDate - bDate : bDate - aDate;
                }
                
                // Handle text
                return sortDirection === 'asc' 
                    ? aText.localeCompare(bText)
                    : bText.localeCompare(aText);
            });
            
            // Reorder rows
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Change page size
        function changePageSize(size) {
            const params = new URLSearchParams(window.location.search);
            params.set('limit', size);
            params.delete('page'); // Reset to first page
            window.location.search = params.toString();
        }
        
        // Refresh table
        function refreshTable() {
            const loading = document.getElementById('tableLoading');
            loading.classList.add('active');
            
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }
        
        // Show customer details (AJAX)
        function showCustomerDetails(customerId) {
            // Implement AJAX call to load customer details
            // alert('Customer details feature to be implemented for ID: ' + customerId);
            window.location.href="customer_profile.php?id="+customerId;
            // You can implement a modal with customer payment history
        }
        
        // Send reminder
        function sendReminder(customerId) {
            if (confirm('Send payment reminder to this customer?')) {
                // Implement AJAX call to send reminder
                alert('Reminder sent to customer ID: ' + customerId);
            }
        }
        
        // Add payment
        function addPayment(customerId) {
            // Redirect to import payments with pre-filled customer
            window.location.href = `import_payments.php?customer_id=${customerId}`;
        }
        
        // Debounced search
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('searchForm').submit();
            }, 500);
        });
        
        // Auto-focus search input
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search');
            if (searchInput.value === '') {
                searchInput.focus();
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }
            
            // Escape to clear search
            if (e.key === 'Escape' && document.getElementById('search').value) {
                document.getElementById('search').value = '';
                document.getElementById('searchForm').submit();
            }
        });
    </script>
</body>
</html>