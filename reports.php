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

    // Build dynamic export title from active filters
    $exportMonthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                         7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
    $exportTitleParts = ['Payment Report'];
    $exportFilenameParts = ['payment_report'];

    if ($yearFilter && $monthFilter) {
        $periodStr = ($exportMonthNames[$monthFilter] ?? $monthFilter) . ' ' . $yearFilter;
        $exportTitleParts[] = $periodStr;
        $exportFilenameParts[] = $yearFilter . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
    } elseif ($yearFilter) {
        $exportTitleParts[] = 'Year ' . $yearFilter;
        $exportFilenameParts[] = $yearFilter;
    }

    if ($sectorId) {
        $exportSectorStmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
        $exportSectorStmt->execute([$sectorId]);
        $exportSectorRow = $exportSectorStmt->fetch();
        if ($exportSectorRow) {
            $exportTitleParts[] = $exportSectorRow['sector_name'];
            $exportFilenameParts[] = preg_replace('/[^a-z0-9]+/i', '_', strtolower($exportSectorRow['sector_name']));
        }
    }

    if (!empty($searchQuery)) {
        $exportTitleParts[] = 'Search: "' . $searchQuery . '"';
    }

    if ($missedOnly) {
        $exportTitleParts[] = 'Missed Payments Only';
        $exportFilenameParts[] = 'missed';
    }

    $exportTitle = implode(' · ', $exportTitleParts) . ' — Generated ' . date('d M Y');
    $filename = implode('_', $exportFilenameParts) . '_' . date('Y-m-d');

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

    // Title row — reflects active filters
    echo '<tr><th colspan="' . count($headers_row) . '" style="background:#1e293b;color:#fff;font-size:14pt;padding:10px;">' . htmlspecialchars($exportTitle) . '</th></tr>';

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
    echo '<td ' . $summaryStyle . '>Avg Amount/Member</td>';
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
    <title><?php
        $htmlTitleParts = ['Payment Reports'];
        if ($yearFilter && $monthFilter) {
            $htmlTitleParts[] = ($monthNames[$monthFilter] ?? $monthFilter) . ' ' . $yearFilter;
        } elseif ($yearFilter) {
            $htmlTitleParts[] = 'Year ' . $yearFilter;
        }
        if ($sectorId) { $sf = array_filter($sectors, fn($x)=>$x['id']==$sectorId); if ($sf) $htmlTitleParts[] = reset($sf)['sector_name']; }
        if ($missedOnly) $htmlTitleParts[] = 'Missed Only';
        echo htmlspecialchars(implode(' — ', $htmlTitleParts));
    ?> - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── Page Header ── */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 28px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header-left h1 {
            margin: 0 0 4px;
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -.5px;
        }
        .page-header-left p {
            margin: 0;
            opacity: .82;
            font-size: .95rem;
        }
        .header-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .hstat {
            background: rgba(255,255,255,.18);
            border-radius: 10px;
            padding: 10px 18px;
            text-align: center;
            min-width: 90px;
        }
        .hstat-val {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1;
        }
        .hstat-lbl {
            font-size: .72rem;
            opacity: .85;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* ── Filter Card ── */
        .filter-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            padding: 22px 24px 18px;
            margin-bottom: 24px;
        }
        .filter-card .filter-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #9ca3af;
            margin-bottom: 14px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }
        @media(max-width:992px){ .filter-grid{ grid-template-columns: 1fr 1fr; } }
        @media(max-width:576px){ .filter-grid{ grid-template-columns: 1fr; } }

        .filter-field label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .filter-field .fi {
            width: 100%;
            padding: 9px 13px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: .9rem;
            color: #1f2937;
            background: #f9fafb;
            transition: border-color .2s, box-shadow .2s;
            box-sizing: border-box;
        }
        .filter-field .fi:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.12);
            background: #fff;
        }
        .search-wrap { position: relative; }
        .search-wrap .si {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: .95rem;
            pointer-events: none;
        }
        .search-wrap .fi { padding-left: 34px; }

        .btn-filter {
            padding: 9px 22px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: opacity .15s, transform .1s;
        }
        .btn-filter:hover { opacity: .9; transform: translateY(-1px); }

        /* Filter chips */
        .chip-row {
            display: flex;
            gap: 8px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 500;
            background: #f3f4f6;
            color: #374151;
        }
        .chip.active { background: #667eea; color: #fff; }
        .chip .chip-x {
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            opacity: .7;
        }
        .chip .chip-x:hover { opacity: 1; }

        /* ── Summary Cards ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media(max-width:900px){ .stats-row{ grid-template-columns: 1fr 1fr; } }
        @media(max-width:480px){ .stats-row{ grid-template-columns: 1fr; } }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            padding: 20px 20px 16px;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            border-radius: 12px 12px 0 0;
        }
        .stat-card.sc-blue::before   { background: linear-gradient(90deg,#667eea,#764ba2); }
        .stat-card.sc-green::before  { background: linear-gradient(90deg,#28a745,#20c997); }
        .stat-card.sc-orange::before { background: linear-gradient(90deg,#fd7e14,#ffc107); }
        .stat-card.sc-red::before    { background: linear-gradient(90deg,#dc3545,#e83e8c); }

        .stat-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .sc-blue  .stat-icon { background: #ede9fe; }
        .sc-green .stat-icon { background: #d1fae5; }
        .sc-orange .stat-icon { background: #fff3cd; }
        .sc-red   .stat-icon { background: #fde8ea; }

        .stat-value {
            font-size: 1.55rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: .8rem;
            color: #6b7280;
            font-weight: 500;
        }
        .stat-sub {
            font-size: .75rem;
            color: #9ca3af;
            margin-top: 6px;
        }
        .stat-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: .72rem;
            font-weight: 600;
            margin-top: 6px;
        }
        .badge-up   { background: #d1fae5; color: #065f46; }
        .badge-down { background: #fde8ea; color: #991b1b; }
        .badge-flat { background: #f3f4f6; color: #374151; }

        /* ── Table Card ── */
        .table-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f3f4f6;
            flex-wrap: wrap;
            gap: 12px;
        }
        .table-card-header h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }
        .table-card-header .tc-sub {
            font-size: .8rem;
            color: #9ca3af;
            margin-top: 2px;
        }
        .tc-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Export button */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, transform .1s;
        }
        .btn-export:hover { background: #1e293b; transform: translateY(-1px); }

        .export-menu {
            position: absolute;
            right: 0; top: calc(100% + 6px);
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
            min-width: 220px;
            z-index: 1000;
            padding: 6px;
            animation: menuIn .15s ease;
        }
        @keyframes menuIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        .export-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 12px; border-radius: 7px;
            color: #1e293b; text-decoration: none; font-size: .85rem;
            font-weight: 500; transition: background .12s; cursor: pointer;
        }
        .export-item:hover { background: #f1f5f9; }
        .export-hint { display:block; font-size:.72rem; color:#94a3b8; font-weight:400; margin-top:1px; }

        .tc-select {
            padding: 7px 10px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: .82rem;
            color: #374151;
            background: #f9fafb;
            cursor: pointer;
        }
        .tc-select:focus { outline: none; border-color: #667eea; }

        .btn-refresh {
            padding: 7px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            color: #374151;
            font-size: .82rem;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-refresh:hover { background: #f3f4f6; }

        /* Table */
        .rpt-table { width: 100%; border-collapse: collapse; }
        .rpt-table thead th {
            background: #f8fafc;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            padding: 11px 14px;
            text-align: left;
            border-bottom: 1px solid #f3f4f6;
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
        }
        .rpt-table thead th:hover { background: #f1f5f9; color: #374151; }
        .rpt-table thead th .sort-icon { margin-left: 4px; opacity: .4; }
        .rpt-table thead th.asc  .sort-icon::after { content:'↑'; opacity:1; }
        .rpt-table thead th.desc .sort-icon::after { content:'↓'; opacity:1; }

        .rpt-table tbody tr {
            border-bottom: 1px solid #f9fafb;
            transition: background .1s;
        }
        .rpt-table tbody tr:hover { background: #fafbff; }
        .rpt-table tbody td { padding: 11px 14px; font-size: .875rem; color: #374151; vertical-align: middle; }

        /* Customer cell */
        .cust-cell { display: flex; align-items: center; gap: 10px; }
        .cust-avatar {
            width: 36px; height: 36px; border-radius: 9px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff; font-size: .85rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; text-transform: uppercase;
        }
        .cust-name {
            font-weight: 600; color: #1f2937; font-size: .875rem;
            text-decoration: none;
        }
        .cust-name:hover { color: #667eea; text-decoration: underline; }
        .cust-phone { font-size: .75rem; color: #9ca3af; margin-top: 1px; }

        /* Payment rate bar */
        .rate-wrap { display: flex; align-items: center; gap: 8px; }
        .rate-bar-bg {
            flex: 1; height: 6px; background: #f3f4f6;
            border-radius: 3px; overflow: hidden; min-width: 60px;
        }
        .rate-bar-fill { height: 100%; border-radius: 3px; transition: width .3s; }
        .rate-label { font-size: .8rem; font-weight: 700; min-width: 38px; text-align: right; }

        /* Paid months badge */
        .paid-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 20px;
            font-size: .78rem; font-weight: 600;
        }
        .paid-badge.pb-good { background: #d1fae5; color: #065f46; }
        .paid-badge.pb-none { background: #fde8ea; color: #991b1b; }

        /* Missing months tags */
        .miss-tags { display: flex; flex-wrap: wrap; gap: 4px; max-width: 180px; }
        .miss-tag {
            padding: 2px 7px; border-radius: 5px;
            background: #fff1f2; color: #be123c;
            font-size: .72rem; font-weight: 600;
        }
        .miss-more {
            padding: 2px 7px; border-radius: 5px;
            background: #fef3c7; color: #92400e;
            font-size: .72rem; font-weight: 600;
        }
        .all-paid { color: #059669; font-weight: 600; font-size: .82rem; }

        /* Last payment */
        .lp-date { font-size: .82rem; color: #374151; }
        .lp-never { color: #dc3545; font-weight: 600; font-size: .82rem; }

        /* Action icon */
        .act-view {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 7px;
            background: #ede9fe; color: #5b21b6;
            font-size: .82rem; text-decoration: none;
            transition: background .15s, transform .1s;
        }
        .act-view:hover { background: #667eea; color: #fff; transform: scale(1.1); }

        /* Empty state */
        .empty-state {
            text-align: center; padding: 56px 24px; color: #9ca3af;
        }
        .empty-state .es-icon { font-size: 2.8rem; margin-bottom: 12px; }
        .empty-state .es-title { font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 4px; }
        .empty-state .es-sub { font-size: .85rem; }

        /* ── Pagination ── */
        .pagination {
            display: flex; align-items: center; justify-content: center;
            gap: 6px; margin: 8px 0 4px; flex-wrap: wrap;
        }
        .pg-btn {
            padding: 6px 12px; border-radius: 7px;
            background: #f9fafb; border: 1.5px solid #e5e7eb;
            color: #374151; text-decoration: none; font-size: .82rem; font-weight: 500;
            transition: all .15s;
        }
        .pg-btn:hover:not(.disabled):not(.active) { background: #f3f4f6; border-color: #d1d5db; }
        .pg-btn.active { background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; border-color:transparent; }
        .pg-btn.disabled { opacity: .4; pointer-events: none; }
        .pg-info { font-size: .8rem; color: #9ca3af; margin-left: 4px; }

        /* Export spinner */
        .export-spinner {
            width:40px; height:40px; border:4px solid #e2e8f0;
            border-top-color:#667eea; border-radius:50%;
            animation: spin .7s linear infinite; margin: 0 auto;
        }
        @keyframes spin { to{ transform:rotate(360deg); } }

        /* Print styles */
        @media print {
            header, nav, .sidebar, .filter-card, .page-header,
            .tc-actions, .pagination, .act-view, .no-print { display:none !important; }
            body { background:#fff !important; font-size:12px; }
            .print-header { display:block !important; }
            .rpt-table { width:100%; border-collapse:collapse; font-size:11px; }
            .rpt-table thead th { background:#1e293b !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .rpt-table th, .rpt-table td { border:1px solid #cbd5e1; padding:6px 8px; }
            @page { margin:1.5cm; size:A4 landscape; }
        }
        .print-header { display: none; }
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

            <?php
            $collectionRate = ($summaryStats['total_amount_due'] ?? 0) > 0
                ? round((($summaryStats['total_amount_paid'] ?? 0) / $summaryStats['total_amount_due']) * 100, 1)
                : 0;
            $monthNames = [
                1=>'January',2=>'February',3=>'March',4=>'April',
                5=>'May',6=>'June',7=>'July',8=>'August',
                9=>'September',10=>'October',11=>'November',12=>'December'
            ];
            $periodLabel = $yearFilter
                ? ($monthFilter ? ($monthNames[$monthFilter] ?? $monthFilter) . ' ' . $yearFilter : 'Year ' . $yearFilter)
                : 'All Time';
            ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1>Payment Reports</h1>
                    <p><?php
                        $headerMeta = ['Period: ' . htmlspecialchars($periodLabel)];
                        if ($sectorId) { $s = array_filter($sectors, fn($x)=>$x['id']==$sectorId); if ($s) $headerMeta[] = htmlspecialchars(reset($s)['sector_name']); }
                        if (!empty($searchQuery)) $headerMeta[] = 'Search: &ldquo;' . htmlspecialchars($searchQuery) . '&rdquo;';
                        if ($missedOnly) $headerMeta[] = 'Missed Payments Only';
                        echo implode(' &middot; ', $headerMeta);
                    ?></p>
                </div>
                <div class="header-stats">
                    <div class="hstat">
                        <div class="hstat-val"><?php echo number_format($summaryStats['total_customers'] ?? 0); ?></div>
                        <div class="hstat-lbl">Customers</div>
                    </div>
                    <div class="hstat">
                        <div class="hstat-val"><?php echo $collectionRate; ?>%</div>
                        <div class="hstat-lbl">Collection</div>
                    </div>
                    <div class="hstat">
                        <div class="hstat-val"><?php
                            $paid = $summaryStats['total_amount_paid'] ?? 0;
                            echo $paid >= 1000000 ? number_format($paid/1000000, 1).'M' : ($paid >= 1000 ? number_format($paid/1000, 0).'K' : number_format($paid));
                        ?></div>
                        <div class="hstat-lbl">Collected RWF</div>
                    </div>
                    <div class="hstat">
                        <div class="hstat-val"><?php echo number_format($summaryStats['customers_without_payments'] ?? 0); ?></div>
                        <div class="hstat-lbl">Not Paid</div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <div class="filter-title">Filter Report</div>
                <form method="GET" id="searchForm">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label for="search">Search</label>
                            <div class="search-wrap">
                                <span class="si">&#128269;</span>
                                <input type="text" id="search" name="search" class="fi"
                                    placeholder="Name, phone, occupation…"
                                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                                    autocomplete="off">
                            </div>
                        </div>
                        <div class="filter-field">
                            <label for="sector_id">Sector</label>
                            <select id="sector_id" name="sector_id" class="fi">
                                <option value="">All Sectors</option>
                                <?php foreach ($sectors as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $sectorId==$s['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($s['sector_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="year">Year</label>
                            <select id="year" name="year" class="fi">
                                <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $yearFilter==$y?'selected':''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="month">Month</label>
                            <select id="month" name="month" class="fi">
                                <option value="">All Months</option>
                                <?php foreach ($monthNames as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $monthFilter==$num?'selected':''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="missed_only">Status</label>
                            <select id="missed_only" name="missed_only" class="fi">
                                <option value="">All Customers</option>
                                <option value="1" <?php echo $missedOnly?'selected':''; ?>>Missed Only</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn-filter">Search</button>
                        </div>
                    </div>
                    <input type="hidden" name="page" id="pageInput" value="<?php echo $page; ?>">
                </form>

                <!-- Active filter chips -->
                <?php if ($sectorId || !empty($searchQuery) || $monthFilter || $missedOnly): ?>
                <div class="chip-row">
                    <?php if (!empty($searchQuery)): ?>
                    <span class="chip">Search: <?php echo htmlspecialchars($searchQuery); ?> <span class="chip-x" onclick="removeFilter('search')">×</span></span>
                    <?php endif; ?>
                    <?php if ($sectorId): $sf=array_filter($sectors,fn($x)=>$x['id']==$sectorId); if($sf): ?>
                    <span class="chip">Sector: <?php echo htmlspecialchars(reset($sf)['sector_name']); ?> <span class="chip-x" onclick="removeFilter('sector_id')">×</span></span>
                    <?php endif; endif; ?>
                    <?php if ($yearFilter): ?>
                    <span class="chip">Year: <?php echo htmlspecialchars($yearFilter); ?></span>
                    <?php endif; ?>
                    <?php if ($monthFilter): ?>
                    <span class="chip">Month: <?php echo $monthNames[$monthFilter]??$monthFilter; ?> <span class="chip-x" onclick="removeFilter('month')">×</span></span>
                    <?php endif; ?>
                    <?php if ($missedOnly): ?>
                    <span class="chip active">Missed Only <span class="chip-x" onclick="removeFilter('missed_only')">×</span></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Summary Stat Cards -->
            <div class="stats-row">
                <div class="stat-card sc-blue">
                    <div class="stat-icon">&#128101;</div>
                    <div class="stat-value"><?php echo number_format($totalCustomers); ?></div>
                    <div class="stat-label">Customers Found</div>
                    <div class="stat-sub">Page <?php echo $page; ?> of <?php echo $totalPages ?: 1; ?></div>
                </div>
                <div class="stat-card sc-orange">
                    <div class="stat-icon">&#128176;</div>
                    <div class="stat-value" style="font-size:1.15rem;">RWF <?php
                        $due = $summaryStats['total_amount_due'] ?? 0;
                        echo $due >= 1000000 ? number_format($due/1000000, 2).'M' : number_format($due);
                    ?></div>
                    <div class="stat-label">Total Amount Due</div>
                    <div class="stat-sub">Avg RWF <?php echo number_format($summaryStats['avg_amount_per_customer'] ?? 0); ?>/customer</div>
                </div>
                <div class="stat-card sc-green">
                    <div class="stat-icon">&#9989;</div>
                    <div class="stat-value" style="font-size:1.15rem;">RWF <?php
                        $coll = $summaryStats['total_amount_paid'] ?? 0;
                        echo $coll >= 1000000 ? number_format($coll/1000000, 2).'M' : number_format($coll);
                    ?></div>
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-sub"><?php echo number_format($summaryStats['total_payments'] ?? 0); ?> payment records</div>
                    <?php
                    $revDir = $revenueChange > 0 ? 'up' : ($revenueChange < 0 ? 'down' : 'flat');
                    $revIcon = $revenueChange > 0 ? '▲' : ($revenueChange < 0 ? '▼' : '—');
                    ?>
                    <span class="stat-badge badge-<?php echo $revDir; ?>"><?php echo $revIcon; ?> <?php echo abs($revenueChange); ?>% vs last month</span>
                </div>
                <div class="stat-card sc-red">
                    <div class="stat-icon">&#128200;</div>
                    <div class="stat-value"><?php echo $collectionRate; ?>%</div>
                    <div class="stat-label">Collection Rate</div>
                    <div class="stat-sub"><?php echo number_format($summaryStats['customers_without_payments'] ?? 0); ?> customers without payment</div>
                    <?php
                    $custDir = $customerChange > 0 ? 'up' : ($customerChange < 0 ? 'down' : 'flat');
                    $custIcon = $customerChange > 0 ? '▲' : ($customerChange < 0 ? '▼' : '—');
                    ?>
                    <span class="stat-badge badge-<?php echo $custDir; ?>"><?php echo $custIcon; ?> <?php echo abs($customerChange); ?>% paying customers</span>
                </div>
            </div>

            <!-- Table Card -->
            <div class="table-card">
                <div class="table-card-header">
                    <div>
                        <h2>Customer Payment Details</h2>
                        <div class="tc-sub">Showing <?php echo min($limit, count($reportData)); ?> of <?php echo $totalCustomers; ?> customers</div>
                    </div>
                    <div class="tc-actions">
                        <!-- Export button -->
                        <div style="position:relative; display:inline-block;">
                            <button class="btn-export" id="exportToggle" onclick="toggleExportMenu(event)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Export
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="export-menu" id="exportMenu" style="display:none;">
                                <a class="export-item" href="#" onclick="exportData('excel'); return false;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1D6F42" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                                    Export to Excel
                                    <span class="export-hint">Full report (.xlsx)</span>
                                </a>
                            </div>
                        </div>
                        <button class="btn-refresh" onclick="refreshTable()">&#8635; Refresh</button>
                        <select class="tc-select" onchange="changePageSize(this.value)">
                            <option value="25" <?php echo $limit==25?'selected':''; ?>>25 / page</option>
                            <option value="50" <?php echo $limit==50?'selected':''; ?>>50 / page</option>
                            <option value="100" <?php echo $limit==100?'selected':''; ?>>100 / page</option>
                            <option value="200" <?php echo $limit==200?'selected':''; ?>>200 / page</option>
                        </select>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="rpt-table" id="customerTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)"># <span class="sort-icon"></span></th>
                                <th onclick="sortTable(1)">Customer <span class="sort-icon"></span></th>
                                <th onclick="sortTable(2)">Occupation <span class="sort-icon"></span></th>
                                <th onclick="sortTable(3)">Sector <span class="sort-icon"></span></th>
                                <th onclick="sortTable(4)">Amount Due <span class="sort-icon"></span></th>
                                <th onclick="sortTable(5)">Paid Months <span class="sort-icon"></span></th>
                                <th onclick="sortTable(6)">Payment Rate <span class="sort-icon"></span></th>
                                <th>Missing Months</th>
                                <th onclick="sortTable(8)">Last Payment <span class="sort-icon"></span></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $i=1; foreach ($reportData as $customer):
                            $rate = floatval($customer['payment_rate']);
                            $rateColor = $rate >= 80 ? '#10b981' : ($rate >= 50 ? '#f59e0b' : '#ef4444');
                            $initials = strtoupper(substr($customer['name'], 0, 1));
                        ?>
                        <tr>
                            <td style="color:#9ca3af; font-size:.8rem;"><?php echo $i++; ?></td>
                            <td>
                                <div class="cust-cell">
                                    <div class="cust-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                    <div>
                                        <a href="customer_profile.php?id=<?php echo $customer['customer_id']; ?>" class="cust-name">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </a>
                                        <div class="cust-phone"><?php echo htmlspecialchars($customer['phone']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="color:#6b7280; font-size:.82rem;"><?php echo htmlspecialchars($customer['occupation']); ?></td>
                            <td style="font-size:.82rem;"><?php echo htmlspecialchars($customer['sector_name']); ?></td>
                            <td><strong style="color:#1f2937;">RWF <?php echo number_format($customer['amount_to_pay']); ?></strong></td>
                            <td>
                                <?php if ($customer['paid_count'] > 0): ?>
                                <span class="paid-badge pb-good"><?php echo $customer['paid_count']; ?> mo</span>
                                <?php else: ?>
                                <span class="paid-badge pb-none">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="rate-wrap">
                                    <div class="rate-bar-bg">
                                        <div class="rate-bar-fill" style="width:<?php echo $rate; ?>%; background:<?php echo $rateColor; ?>;"></div>
                                    </div>
                                    <span class="rate-label" style="color:<?php echo $rateColor; ?>;"><?php echo $rate; ?>%</span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($customer['missing_months'])): ?>
                                <div class="miss-tags">
                                    <?php
                                    $show = array_slice($customer['missing_months'], -3);
                                    foreach ($show as $mm):
                                        $parts = explode('-', $mm);
                                        $label = isset($parts[1]) ? date('M', mktime(0,0,0,intval($parts[1]),1)).' '.$parts[0] : $mm;
                                    ?>
                                    <span class="miss-tag"><?php echo $label; ?></span>
                                    <?php endforeach;
                                    if (count($customer['missing_months']) > 3): ?>
                                    <span class="miss-more">+<?php echo count($customer['missing_months'])-3; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="all-paid">&#10003; All paid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['last_payment']): ?>
                                <span class="lp-date"><?php echo date('d M Y', strtotime($customer['last_payment'])); ?></span>
                                <?php else: ?>
                                <span class="lp-never">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="customer_profile.php?id=<?php echo $customer['customer_id']; ?>" class="act-view" title="View Profile">&#128065;</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportData)): ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <div class="es-icon">&#128466;</div>
                                    <div class="es-title">No customers found</div>
                                    <div class="es-sub">
                                        <?php if (!empty($searchQuery)): ?>
                                        Try a different search term or <a href="reports.php">clear filters</a>
                                        <?php else: ?>
                                        <a href="import_customers.php">Import customers</a> to get started
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination inside card -->
                <?php if ($totalPages > 1): ?>
                <div style="padding: 14px 22px; border-top: 1px solid #f3f4f6;">
                    <div class="pagination">
                        <a href="?<?php echo buildQueryString(['page'=>1]); ?>" class="pg-btn <?php echo $page==1?'disabled':''; ?>">« First</a>
                        <a href="?<?php echo buildQueryString(['page'=>max(1,$page-1)]); ?>" class="pg-btn <?php echo $page==1?'disabled':''; ?>">‹ Prev</a>
                        <?php
                        $sp = max(1,$page-2); $ep = min($totalPages,$page+2);
                        if ($sp>1) echo '<span class="pg-btn disabled">…</span>';
                        for ($i=$sp; $i<=$ep; $i++):
                        ?>
                        <a href="?<?php echo buildQueryString(['page'=>$i]); ?>" class="pg-btn <?php echo $i==$page?'active':''; ?>"><?php echo $i; ?></a>
                        <?php endfor;
                        if ($ep<$totalPages) echo '<span class="pg-btn disabled">…</span>';
                        ?>
                        <a href="?<?php echo buildQueryString(['page'=>min($totalPages,$page+1)]); ?>" class="pg-btn <?php echo $page==$totalPages?'disabled':''; ?>">Next ›</a>
                        <a href="?<?php echo buildQueryString(['page'=>$totalPages]); ?>" class="pg-btn <?php echo $page==$totalPages?'disabled':''; ?>">Last »</a>
                        <span class="pg-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- /.table-card -->

        </main>
    </div>

    <!-- Export loading overlay -->
    <div id="exportOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:14px; padding:36px 52px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div class="export-spinner"></div>
            <div style="margin-top:16px; font-weight:700; color:#1e293b;" id="exportOverlayMsg">Generating report…</div>
            <div style="font-size:.78rem; color:#64748b; margin-top:4px;">This may take a moment for large datasets</div>
        </div>
    </div>

    <script src="js/script.js"></script>
    <script>
    // ── Helpers ──
    function buildQueryString(params) {
        const p = new URLSearchParams(window.location.search);
        Object.keys(params).forEach(k => {
            if (params[k] != null) p.set(k, params[k]); else p.delete(k);
        });
        return p.toString();
    }
    function removeFilter(name) {
        const p = new URLSearchParams(window.location.search);
        p.delete(name); p.delete('page');
        window.location.search = p.toString();
    }
    function changePageSize(size) {
        const p = new URLSearchParams(window.location.search);
        p.set('limit', size); p.delete('page');
        window.location.search = p.toString();
    }
    function refreshTable() {
        window.location.reload();
    }

    // ── Export menu ──
    function toggleExportMenu(e) {
        e.stopPropagation();
        const m = document.getElementById('exportMenu');
        m.style.display = m.style.display === 'none' ? 'block' : 'none';
    }
    document.addEventListener('click', () => {
        const m = document.getElementById('exportMenu');
        if (m) m.style.display = 'none';
    });

    function exportData(format) {
        document.getElementById('exportMenu').style.display = 'none';
        const overlay = document.getElementById('exportOverlay');
        overlay.style.display = 'flex';
        document.getElementById('exportOverlayMsg').textContent = 'Generating report file…';
        const p = new URLSearchParams(window.location.search);
        p.set('export', format); p.delete('page');
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = window.location.pathname + '?' + p.toString();
        document.body.appendChild(iframe);
        setTimeout(() => {
            overlay.style.display = 'none';
            document.body.removeChild(iframe);
        }, 3000);
        return false;
    }

    // ── Table sort ──
    let curSortCol = null, sortDir = 'asc';
    function sortTable(col) {
        const table = document.getElementById('customerTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        table.querySelectorAll('th').forEach(th => th.classList.remove('asc','desc'));
        if (curSortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        else { curSortCol = col; sortDir = 'asc'; }
        table.querySelectorAll('th')[col].classList.add(sortDir);
        rows.sort((a,b) => {
            const at = a.cells[col]?.textContent.trim() ?? '';
            const bt = b.cells[col]?.textContent.trim() ?? '';
            const an = parseFloat(at.replace(/[^0-9.-]+/g,''));
            const bn = parseFloat(bt.replace(/[^0-9.-]+/g,''));
            if (!isNaN(an) && !isNaN(bn)) return sortDir==='asc' ? an-bn : bn-an;
            const ad = Date.parse(at), bd = Date.parse(bt);
            if (!isNaN(ad) && !isNaN(bd)) return sortDir==='asc' ? ad-bd : bd-ad;
            return sortDir==='asc' ? at.localeCompare(bt) : bt.localeCompare(at);
        });
        rows.forEach(r => tbody.appendChild(r));
    }

    // ── Debounced search ──
    let searchTimeout;
    document.getElementById('search').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
    });

    // ── Auto-submit on filter change ──
    ['sector_id','year','month','missed_only'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => document.getElementById('searchForm').submit());
    });

    // ── Keyboard shortcuts ──
    document.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 'f') { e.preventDefault(); document.getElementById('search').focus(); }
        if (e.key === 'Escape' && document.getElementById('search').value) {
            document.getElementById('search').value = '';
            document.getElementById('searchForm').submit();
        }
    });
    </script>
</body>
</html>