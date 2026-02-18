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
    // Delete single payment
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
    
    // Redirect back to same page with filters
    $redirectUrl = "deletion.php?year=$year&month=$month" . ($sectorId ? "&sector_id=$sectorId" : "");
    header("Location: $redirectUrl");
    exit();
}

if ($action === 'delete_month' && $year && $month) {
    // Delete all payments for a specific month (and sector if selected)
    $monthYear = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
    
    if ($sectorId) {
        // Delete payments for specific sector and month
        $stmt = $pdo->prepare("
            DELETE p FROM payments p
            JOIN customers c ON p.customer_id = c.id
            WHERE p.month_year = ? AND c.sector_id = ?
        ");
        $stmt->execute([$monthYear, $sectorId]);
    } else {
        // Delete all payments for the month
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
    
    // Redirect back
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

// Add month/year filter
$monthYear = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
$query .= " AND p.month_year = ?";
$params[] = $monthYear;

// Add sector filter
if ($sectorId) {
    $query .= " AND s.id = ?";
    $params[] = $sectorId;
}

// Add ordering
$query .= " ORDER BY c.name ASC";

// Get payments for the selected month
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Get summary statistics for the selected month
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

// Get all customers without payments for this month
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
        SELECT customer_id 
        FROM payments 
        WHERE month_year = ?
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

// Get total customers count for coverage calculation
$totalCustomersQuery = "SELECT COUNT(*) as total FROM customers";
$totalCustomersParams = [];

if ($sectorId) {
    $totalCustomersQuery .= " WHERE sector_id = ?";
    $totalCustomersParams[] = $sectorId;
}

$totalCustomersStmt = $pdo->prepare($totalCustomersQuery);
$totalCustomersStmt->execute($totalCustomersParams);
$totalCustomers = $totalCustomersStmt->fetch()['total'];

// Calculate coverage percentage
$coverage = $totalCustomers > 0 ? round((count($payments) / $totalCustomers) * 100, 1) : 0;

// Get monthly trend for the selected year
$trendQuery = "
    SELECT 
        month_year,
        COUNT(DISTINCT customer_id) as paying_customers,
        SUM(paid_amount) as monthly_total
    FROM payments
    WHERE month_year LIKE ?
    GROUP BY month_year
    ORDER BY month_year ASC
";

$trendStmt = $pdo->prepare($trendQuery);
$trendStmt->execute([$year . '-%']);
$monthlyTrend = $trendStmt->fetchAll();

// Month names for display
$monthNames = [
    '01' => 'January', '02' => 'February', '03' => 'March',
    '04' => 'April', '05' => 'May', '06' => 'June',
    '07' => 'July', '08' => 'August', '09' => 'September',
    '10' => 'October', '11' => 'November', '12' => 'December'
];

// Check for messages
$message = $_SESSION['message'] ?? '';
$messageType = $_SESSION['message_type'] ?? '';
unset($_SESSION['message']);
unset($_SESSION['message_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Management Header */
        .management-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .management-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .month-selector {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .month-selector .form-group {
            margin: 0;
        }
        
        .month-selector select {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            padding: 0.75rem;
            border-radius: 5px;
            font-size: 1rem;
            min-width: 150px;
        }
        
        .month-selector .btn {
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            border: none;
        }
        
        .month-selector .btn:hover {
            background: white;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.total::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .stat-card.amount::before { background: linear-gradient(90deg, #28a745, #20c997); }
        .stat-card.avg::before { background: linear-gradient(90deg, #fd7e14, #ffc107); }
        .stat-card.coverage::before { background: linear-gradient(90deg, #6f42c1, #e83e8c); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 0.5rem 0;
            line-height: 1;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-detail {
            font-size: 0.8rem;
            color: #888;
            margin-top: 0.5rem;
        }
        
        /* Action Bar */
        .action-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            border: none;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
            border: none;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
            border: none;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        /* Payment Table */
        .payment-table-container {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 1.25rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .payment-table th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }
        
        .payment-table td {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .payment-table tr:hover {
            background: #f8f9fa;
        }
        
        .payment-actions {
            display: flex;
            gap: 0.25rem;
        }
        
        .action-btn {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            background: #f8f9fa;
            color: #666;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .action-btn.delete {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-btn.delete:hover {
            background: #f5c6cb;
        }
        
        .action-btn.edit {
            background: #fff3cd;
            color: #856404;
        }
        
        .action-btn.edit:hover {
            background: #ffeaa7;
        }
        
        .action-btn.view {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .action-btn.view:hover {
            background: #bee5eb;
        }
        
        /* Missing Payments Section */
        .missing-payments {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .missing-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
        }
        
        .missing-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .missing-item:last-child {
            border-bottom: none;
        }
        
        .missing-item:hover {
            background: #f8f9fa;
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: 500;
            color: #333;
        }
        
        .customer-details {
            font-size: 0.875rem;
            color: #666;
        }
        
        /* Monthly Trend */
        .trend-container {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .trend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .trend-month {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .trend-month:hover {
            background: white;
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .trend-month.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .trend-month-name {
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .trend-month-amount {
            font-size: 0.75rem;
            color: #666;
        }
        
        .trend-month.active .trend-month-amount {
            color: rgba(255, 255, 255, 0.9);
        }
        
        /* Confirmation Modal */
        .confirmation-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .confirmation-modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            margin-bottom: 1.5rem;
        }
        
        .modal-title {
            font-size: 1.25rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .modal-body {
            margin-bottom: 1.5rem;
            color: #666;
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .month-selector {
                flex-direction: column;
                align-items: stretch;
            }
            
            .month-selector .form-group {
                width: 100%;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-buttons {
                justify-content: center;
            }
            
            .payment-table {
                display: block;
                overflow-x: auto;
            }
            
            .missing-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .trend-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Payment Management</div>
        <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
    </nav>
    
    <div class="container">
        <?php $extraSidebarLinks = []; ?>
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <!-- Management Header -->
            <div class="management-header">
                <div class="header-content">
                    <h1 style="color: white; margin-bottom: 1.5rem;">Payment Management</h1>
                    
                    <form method="GET" class="month-selector">
                        <div class="form-group">
                            <label for="year" style="color: white; display: block; margin-bottom: 0.5rem;">Year</label>
                            <select id="year" name="year" onchange="this.form.submit()">
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="month" style="color: white; display: block; margin-bottom: 0.5rem;">Month</label>
                            <select id="month" name="month" onchange="this.form.submit()">
                                <?php foreach ($monthNames as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $month == $num ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="sector_id" style="color: white; display: block; margin-bottom: 0.5rem;">Sector</label>
                            <select id="sector_id" name="sector_id" onchange="this.form.submit()">
                                <option value="">All Sectors</option>
                                <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>" <?php echo $sectorId == $sector['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="align-self: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span class="btn-icon">🔍</span> Filter
                            </button>
                        </div>
                    </form>
                    
                    <div style="margin-top: 1rem; color: rgba(255, 255, 255, 0.9);">
                        <strong><?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></strong>
                        <?php if ($sectorId): 
                            $selectedSector = array_filter($sectors, fn($s) => $s['id'] == $sectorId);
                            if (!empty($selectedSector)): ?>
                            • <?php echo htmlspecialchars(reset($selectedSector)['sector_name']); ?> Sector
                            <?php endif; endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>" style="margin-bottom: 2rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-label">Payments Recorded</div>
                    <div class="stat-value"><?php echo $summary['total_payments'] ?? 0; ?></div>
                    <div class="stat-detail">
                        <?php echo $summary['total_customers'] ?? 0; ?> customers paid
                    </div>
                </div>
                
                <div class="stat-card amount">
                    <div class="stat-label">Total Amount</div>
                    <div class="stat-value">RWF <?php echo number_format($summary['total_amount'] ?? 0, 2); ?></div>
                    <div class="stat-detail">
                        Average: RWF <?php echo number_format($summary['average_amount'] ?? 0, 2); ?>
                    </div>
                </div>
                
                <div class="stat-card coverage">
                    <div class="stat-label">Payment Coverage</div>
                    <div class="stat-value"><?php echo $coverage; ?>%</div>
                    <div class="stat-detail">
                        <?php echo count($payments); ?> of <?php echo $totalCustomers; ?> customers
                    </div>
                </div>
                
                <div class="stat-card avg">
                    <div class="stat-label">Payment Period</div>
                    <div class="stat-value">
                        <?php if ($summary['first_payment_date']): ?>
                        <?php echo date('M d', strtotime($summary['first_payment_date'])); ?> - 
                        <?php echo date('M d', strtotime($summary['last_payment_date'])); ?>
                        <?php else: ?>
                        No payments
                        <?php endif; ?>
                    </div>
                    <div class="stat-detail">
                        <?php echo $summary['total_payments'] ?? 0; ?> transactions
                    </div>
                </div>
            </div>
            
            <!-- Action Bar -->
            <div class="action-bar">
                <div>
                    <h3 style="margin: 0;">Payment Management Actions</h3>
                    <p style="color: #666; margin: 0.25rem 0 0 0; font-size: 0.9rem;">
                        Manage payments for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?>
                    </p>
                </div>
                
                <div class="action-buttons">
                    <a href="import_payments.php?month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>" 
                       class="btn btn-primary">
                        <span class="btn-icon">💰</span> Add Payments
                    </a>
                    
                    <button onclick="showDeleteMonthModal()" class="btn btn-danger">
                        <span class="btn-icon">🗑️</span> Delete Month
                    </button>
                    
                    <button onclick="exportMonthData()" class="btn btn-info">
                        <span class="btn-icon">📥</span> Export Data
                    </button>
                    
                    <a href="reports.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" 
                       class="btn btn-warning">
                        <span class="btn-icon">📊</span> View Reports
                    </a>
                </div>
            </div>
            
            <!-- Recorded Payments Section -->
            <div class="payment-table-container">
                <div class="section-header">
                    <h2 class="section-title">
                        <span>✅</span> Recorded Payments
                        <span style="font-size: 0.9rem; color: #666; margin-left: 0.5rem;">
                            (<?php echo count($payments); ?> payments)
                        </span>
                    </h2>
                    <div style="color: #666; font-size: 0.9rem;">
                        Total: RWF <?php echo number_format($summary['total_amount'] ?? 0, 2); ?>
                    </div>
                </div>
                
                <?php if (!empty($payments)): ?>
                <div style="overflow-x: auto;">
                    <table class="payment-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Sector</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $index => $payment): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($payment['customer_name']); ?></div>
                                    <div style="font-size: 0.875rem; color: #666;"><?php echo htmlspecialchars($payment['occupation']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($payment['phone']); ?></td>
                                <td><?php echo htmlspecialchars($payment['sector_name']); ?></td>
                                <td style="font-weight: 500; color: #28a745;">
                                    RWF <?php echo number_format($payment['paid_amount'], 2); ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                </td>
                                <td>
                                    <div class="payment-actions">
                                        <a href="customer_profile.php?id=<?php echo $payment['customer_id']; ?>" 
                                           class="action-btn view" title="View Customer">
                                            👁️
                                        </a>
                                        <a href="#" class="action-btn edit" title="Edit Payment">
                                            ✏️
                                        </a>
                                        <button onclick="showDeleteModal(<?php echo $payment['payment_id']; ?>, '<?php echo addslashes($payment['customer_name']); ?>', <?php echo $payment['paid_amount']; ?>)" 
                                                class="action-btn delete" title="Delete Payment">
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">💰</div>
                    <h3 style="margin-bottom: 0.5rem;">No Payments Recorded</h3>
                    <p style="margin-bottom: 1.5rem;">No payments found for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></p>
                    <a href="import_payments.php?month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>" 
                       class="btn btn-primary">
                        Import Payments for this Month
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Missing Payments Section -->
            <?php if (!empty($missingPayments)): ?>
            <div class="missing-payments">
                <div class="section-header">
                    <h2 class="section-title">
                        <span>⚠️</span> Missing Payments
                        <span style="font-size: 0.9rem; color: #666; margin-left: 0.5rem;">
                            (<?php echo count($missingPayments); ?> customers)
                        </span>
                    </h2>
                    <div style="color: #666; font-size: 0.9rem;">
                        Total Due: RWF <?php echo number_format(array_sum(array_column($missingPayments, 'amount_to_pay')), 2); ?>
                    </div>
                </div>
                
                <div class="missing-list">
                    <?php foreach ($missingPayments as $customer): ?>
                    <div class="missing-item">
                        <div class="customer-info">
                            <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                            <div class="customer-details">
                                <?php echo htmlspecialchars($customer['phone']); ?> • 
                                <?php echo htmlspecialchars($customer['occupation']); ?> • 
                                <?php echo htmlspecialchars($customer['sector_name']); ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 500; color: #dc3545;">
                                RWF <?php echo number_format($customer['amount_to_pay'], 2); ?>
                            </div>
                            <a href="import_payments.php?customer_id=<?php echo $customer['id']; ?>&month_year=<?php echo $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT); ?>" 
                               class="btn btn-primary btn-sm" style="margin-top: 0.5rem; padding: 0.25rem 0.75rem;">
                                Add Payment
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Monthly Trend Section -->
            <div class="trend-container">
                <div class="section-header">
                    <h2 class="section-title">
                        <span>📈</span> Monthly Trend for <?php echo $year; ?>
                    </h2>
                    <div style="color: #666; font-size: 0.9rem;">
                        Total for <?php echo $year; ?>: RWF <?php echo number_format(array_sum(array_column($monthlyTrend, 'monthly_total')), 2); ?>
                    </div>
                </div>
                
                <div class="trend-grid">
                    <?php 
                    // Create array of all months for the year
                    $allMonths = [];
                    for ($m = 1; $m <= 12; $m++) {
                        $monthNum = str_pad($m, 2, '0', STR_PAD_LEFT);
                        $monthKey = $year . '-' . $monthNum;
                        
                        // Find this month in the trend data
                        $trendMonth = array_filter($monthlyTrend, fn($t) => $t['month_year'] == $monthKey);
                        $trendMonth = !empty($trendMonth) ? reset($trendMonth) : null;
                        
                        $allMonths[] = [
                            'month' => $monthNum,
                            'name' => $monthNames[$monthNum],
                            'amount' => $trendMonth ? $trendMonth['monthly_total'] : 0,
                            'customers' => $trendMonth ? $trendMonth['paying_customers'] : 0,
                            'active' => $monthNum == $month
                        ];
                    }
                    
                    foreach ($allMonths as $trend): 
                    ?>
                    <a href="deletion.php?year=<?php echo $year; ?>&month=<?php echo $trend['month']; ?>" 
                       class="trend-month <?php echo $trend['active'] ? 'active' : ''; ?>">
                        <div class="trend-month-name"><?php echo $trend['name']; ?></div>
                        <div class="trend-month-amount">
                            <?php if ($trend['amount'] > 0): ?>
                            RWF <?php echo number_format($trend['amount'], 0); ?>
                            <br>
                            <small><?php echo $trend['customers']; ?> customers</small>
                            <?php else: ?>
                            No payments
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Delete Confirmation Modal -->
      <!-- Delete Confirmation Modal -->
    <div class="confirmation-modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Deletion</h3>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this payment?</p>
            </div>
            <div class="modal-actions">
                <button onclick="hideModal()" class="btn btn-secondary">Cancel</button>
                <a id="deleteLink" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
    
    <!-- Delete Month Confirmation Modal -->
    <div class="confirmation-modal" id="deleteMonthModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete All Payments for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?></h3>
            </div>
            <div class="modal-body">
                <p><strong style="color: #dc3545;">⚠️ Warning: This action cannot be undone!</strong></p>
                <p>
                    You are about to delete <strong><?php echo count($payments); ?> payments</strong> 
                    totaling <strong>RWF <?php echo number_format($summary['total_amount'] ?? 0, 2); ?></strong>
                    <?php if ($sectorId): ?>
                    for the <?php 
                        $selectedSector = array_filter($sectors, fn($s) => $s['id'] == $sectorId);
                        if (!empty($selectedSector)) {
                            echo htmlspecialchars(reset($selectedSector)['sector_name']) . ' ';
                        }
                    ?>sector
                    <?php endif; ?>
                    for <?php echo $monthNames[$month] ?? $month; ?> <?php echo $year; ?>.
                </p>
                <p>This will permanently remove all payment records for this month.</p>
            </div>
            <div class="modal-actions">
                <button onclick="hideModal()" class="btn btn-secondary">Cancel</button>
                <a href="deletion.php?action=delete_month&year=<?php echo $year; ?>&month=<?php echo $month; ?>&sector_id=<?php echo $sectorId ?? ''; ?>" 
                   class="btn btn-danger" onclick="showDeleting()">
                    Delete All Payments
                </a>
            </div>
        </div>
    </div>

    <!-- Processing Modal -->
    <div class="confirmation-modal" id="processingModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Processing...</h3>
            </div>
            <div class="modal-body" style="text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">⏳</div>
                <p>Please wait while we process your request...</p>
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 1rem auto;"></div>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        function hideModal() {
            const modals = document.querySelectorAll('.confirmation-modal');
            modals.forEach(modal => modal.classList.remove('active'));
            document.body.style.overflow = 'auto';
        }
        
        function showDeleteModal(paymentId, customerName, amount) {
            const deleteMessage = document.getElementById('deleteMessage');
            const deleteLink = document.getElementById('deleteLink');
            
            deleteMessage.innerHTML = `Are you sure you want to delete the payment of <strong>RWF ${amount.toLocaleString()}</strong> for <strong>${customerName}</strong>?`;
            deleteLink.href = `deletion.php?action=delete&payment_id=${paymentId}&year=<?php echo $year; ?>&month=<?php echo $month; ?>&sector_id=<?php echo $sectorId ?? ''; ?>`;
            
            showModal('deleteModal');
        }
        
        function showDeleteMonthModal() {
            if (<?php echo count($payments); ?> === 0) {
                alert('No payments to delete for this month.');
                return;
            }
            showModal('deleteMonthModal');
        }
        
        function showDeleting() {
            hideModal();
            setTimeout(() => {
                showModal('processingModal');
            }, 100);
        }
        
        // Export functionality
        function exportMonthData() {
            const year = <?php echo $year; ?>;
            const month = <?php echo $month; ?>;
            const sectorId = <?php echo $sectorId ? "'$sectorId'" : 'null'; ?>;
            
            showModal('processingModal');
            
            // Prepare export data
            const exportData = {
                year: year,
                month: month,
                sectorId: sectorId,
                action: 'export'
            };
            
            // Create form for export
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_payments.php';
            form.style.display = 'none';
            
            // Add CSRF token if exists
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
            }
            
            // Add data to form
            for (const key in exportData) {
                if (exportData[key] !== null) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = exportData[key];
                    form.appendChild(input);
                }
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Close modal on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                hideModal();
            }
        });
        
        // Close modal on background click
        document.querySelectorAll('.confirmation-modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    hideModal();
                }
            });
        });
        
        // Show message modal if there's a message
        <?php if ($message): ?>
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                const messageEl = document.querySelector('.message');
                if (messageEl) {
                    messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 300);
        });
        <?php endif; ?>
        
        // Auto-hide success messages after 5 seconds
        document.addEventListener('DOMContentLoaded', () => {
            const successMessages = document.querySelectorAll('.message.success');
            successMessages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    setTimeout(() => message.remove(), 300);
                }, 5000);
            });
        });
        
        // Month navigation shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.altKey) {
                switch(e.key) {
                    case 'ArrowLeft':
                        // Previous month
                        const prevMonth = (parseInt(<?php echo $month; ?>) - 1) || 12;
                        const prevYear = prevMonth === 12 ? <?php echo $year; ?> - 1 : <?php echo $year; ?>;
                        if (prevYear >= 2020) {
                            window.location.href = `deletion.php?year=${prevYear}&month=${String(prevMonth).padStart(2, '0')}<?php echo $sectorId ? "&sector_id=$sectorId" : ""; ?>`;
                        }
                        break;
                    case 'ArrowRight':
                        // Next month
                        const nextMonth = (parseInt(<?php echo $month; ?>) % 12) + 1;
                        const nextYear = nextMonth === 1 ? <?php echo $year; ?> + 1 : <?php echo $year; ?>;
                        if (nextYear <= new Date().getFullYear() + 1) {
                            window.location.href = `deletion.php?year=${nextYear}&month=${String(nextMonth).padStart(2, '0')}<?php echo $sectorId ? "&sector_id=$sectorId" : ""; ?>`;
                        }
                        break;
                    case 'h':
                        // Go home (dashboard)
                        window.location.href = 'dashboard.php';
                        break;
                }
            }
        });
        
        // Add spinner animation style
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            /* Print styles */
            @media print {
                .sidebar, .navbar, .action-bar, .payment-actions, 
                .action-buttons, .confirmation-modal {
                    display: none !important;
                }
                
                .container {
                    display: block;
                }
                
                .main-content {
                    margin: 0;
                    padding: 0;
                }
                
                .management-header {
                    background: white !important;
                    color: black !important;
                    padding: 1rem !important;
                }
                
                .stat-card {
                    break-inside: avoid;
                }
            }
        `;
        document.head.appendChild(style);
        
        // Add print functionality
        function printReport() {
            window.print();
        }
        
        // Add keyboard shortcut for print (Alt + P)
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                printReport();
            }
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Create monthly trend chart if there's data
        document.addEventListener('DOMContentLoaded', () => {
            const trendData = <?php echo json_encode($monthlyTrend); ?>;
            
            if (trendData.length > 0) {
                const ctx = document.createElement('canvas');
                ctx.id = 'trendChart';
                
                const trendContainer = document.querySelector('.trend-container');
                const trendGrid = document.querySelector('.trend-grid');
                
                if (trendContainer && trendGrid) {
                    // Add chart container
                    const chartContainer = document.createElement('div');
                    chartContainer.style.marginTop = '2rem';
                    chartContainer.style.height = '300px';
                    chartContainer.appendChild(ctx);
                    
                    trendContainer.insertBefore(chartContainer, trendGrid);
                    
                    // Prepare data for chart
                    const months = [];
                    const amounts = [];
                    const customers = [];
                    
                    // Create data for all months
                    for (let i = 1; i <= 12; i++) {
                        const monthNum = i.toString().padStart(2, '0');
                        const monthKey = `<?php echo $year; ?>-${monthNum}`;
                        const trendMonth = trendData.find(t => t.month_year === monthKey);
                        
                        months.push(`<?php echo $year; ?>-${monthNum}`);
                        amounts.push(trendMonth ? parseFloat(trendMonth.monthly_total) : 0);
                        customers.push(trendMonth ? parseInt(trendMonth.paying_customers) : 0);
                    }
                    
                    // Create chart
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: months.map(m => {
                                const monthNum = m.split('-')[1];
                                return <?php echo json_encode($monthNames); ?>[monthNum];
                            }),
                            datasets: [
                                {
                                    label: 'Total Amount (RWF)',
                                    data: amounts,
                                    borderColor: '#667eea',
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    fill: true,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Paying Customers',
                                    data: customers,
                                    borderColor: '#20c997',
                                    backgroundColor: 'rgba(32, 201, 151, 0.1)',
                                    fill: true,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Amount (RWF)'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Customers'
                                    },
                                    grid: {
                                        drawOnChartArea: false
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.datasetIndex === 0) {
                                                label += 'RWF ' + context.parsed.y.toLocaleString();
                                            } else {
                                                label += context.parsed.y + ' customers';
                                            }
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
    </script>
</body>
</html>