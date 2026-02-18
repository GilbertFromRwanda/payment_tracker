<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Get customer ID from URL
$customerId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customerId) {
    header('Location: reports.php');
    exit();
}

// Get customer details
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        s.sector_name,
        (SELECT COUNT(DISTINCT month_year) FROM payments WHERE customer_id = c.id) as total_paid_months,
        (SELECT SUM(paid_amount) FROM payments WHERE customer_id = c.id) as total_paid_amount,
        (SELECT MAX(payment_date) FROM payments WHERE customer_id = c.id) as last_payment_date,
        (SELECT MIN(payment_date) FROM payments WHERE customer_id = c.id) as first_payment_date
    FROM customers c
    LEFT JOIN sectors s ON c.sector_id = s.id
    WHERE c.id = ?
");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: reports.php?error=customer_not_found');
    exit();
}

// Get all payments for this customer
$stmt = $pdo->prepare("
    SELECT 
        *,
        DATE_FORMAT(payment_date, '%Y-%m-%d') as payment_date_formatted,
        DATE_FORMAT(payment_date, '%M %d, %Y') as payment_date_display
    FROM payments 
    WHERE customer_id = ? 
    ORDER BY month_year DESC, payment_date DESC
");
$stmt->execute([$customerId]);
$payments = $stmt->fetchAll();

// Get missing months
$missingMonths = getMissingMonths($customerId);
$totalMissing = count($missingMonths);

// Calculate payment statistics
$totalMonths = 0;
$current = new \DateTime($customer['first_payment_date'] ?: $customer['created_at']);
$now = new \DateTime();
while ($current <= $now) {
    ++$totalMonths;
    $current->modify('+1 month');
}
++$totalMonths;
$paymentRate = $customer['total_paid_months'] > 0 ? 
    round(($customer['total_paid_months'] / $totalMonths) * 100, 1) : 0;

// Calculate monthly average
$monthlyAverage = $customer['total_paid_months'] > 0 ? 
    round($customer['total_paid_amount'] / $customer['total_paid_months'], 2) : 0;

// Get payment trend (last 12 months)
$trendStmt = $pdo->prepare("
    SELECT 
        month_year,
        SUM(paid_amount) as monthly_total,
        COUNT(*) as payments_count
    FROM payments 
    WHERE customer_id = ? 
    GROUP BY month_year 
    ORDER BY month_year DESC 
    LIMIT 12
");
$trendStmt->execute([$customerId]);
$paymentTrend = $trendStmt->fetchAll();

// Get overdue status
$currentMonth = date('Y-m');
$currentMonthPaid = false;
$lastPaymentMonth = '';

if ($customer['last_payment_date']) {
    $lastPaymentMonth = date('Y-m', strtotime($customer['last_payment_date']));
    $currentMonthPaid = ($lastPaymentMonth == $currentMonth);
}

// Calculate days since last payment
$daysSinceLastPayment = $customer['last_payment_date'] ? 
    floor((time() - strtotime($customer['last_payment_date'])) / (60 * 60 * 24)) : null;

// Get payment consistency
$consistencyRate = 0;
if ($customer['first_payment_date'] && $customer['total_paid_months'] > 0) {
    $firstDate = new DateTime($customer['first_payment_date']);
    $lastDate = new DateTime($customer['last_payment_date'] ?: date('Y-m-d'));
    $monthsBetween = ($lastDate->diff($firstDate)->m) + ($lastDate->diff($firstDate)->y * 12);
    $consistencyRate = $monthsBetween > 0 ? 
        round(($customer['total_paid_months'] / $monthsBetween) * 100, 1) : 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($customer['name']); ?> - Payment Profile</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }
        
        .profile-info {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            backdrop-filter: blur(10px);
        }
        
        .profile-details {
            flex: 1;
        }
        
        .profile-name {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .profile-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 0.875rem;
            opacity: 0.8;
        }
        
        .meta-value {
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        /* Stats Grid */
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
        .stat-card.paid::before { background: linear-gradient(90deg, #28a745, #20c997); }
        .stat-card.due::before { background: linear-gradient(90deg, #fd7e14, #ffc107); }
        .stat-card.missing::before { background: linear-gradient(90deg, #dc3545, #e83e8c); }
        
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
        
        /* Payment Status */
        .payment-status {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .status-paid { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-overdue { background: #f8d7da; color: #721c24; }
        
        /* Sections */
        .profile-section {
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
        
        .section-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Payment History Table */
        .payment-table {
            width: 100%;
        }
        
        .payment-row {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.2s;
        }
        
        .payment-row:hover {
            background: #f8f9fa;
        }
        
        .payment-row.header {
            font-weight: bold;
            color: #666;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .payment-month {
            flex: 0 0 150px;
            font-weight: 500;
        }
        
        .payment-date {
            flex: 0 0 150px;
            color: #666;
        }
        
        .payment-amount {
            flex: 0 0 120px;
            text-align: right;
            font-weight: 500;
            color: #28a745;
        }
        
        .payment-actions {
            flex: 1;
            text-align: right;
        }
        
        /* Missing Months */
        .missing-months-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.75rem;
        }
        
        .month-chip {
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 5px;
            text-align: center;
            font-size: 0.875rem;
            color: #666;
            transition: all 0.2s;
        }
        
        .month-chip.missing {
            background: #f8d7da;
            color: #721c24;
            font-weight: 500;
        }
        
        .month-chip.paid {
            background: #d4edda;
            color: #155724;
        }
        
        .month-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        /* Payment Trend */
        .trend-container {
            height: 200px;
            margin-top: 1rem;
            position: relative;
        }
        
        .trend-bar {
            display: flex;
            align-items: flex-end;
            height: 150px;
            gap: 10px;
            padding: 0 1rem;
        }
        
        .trend-month {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
        }
        
        .trend-bar-value {
            background: linear-gradient(to top, #667eea, #764ba2);
            width: 100%;
            border-radius: 4px 4px 0 0;
            transition: height 0.3s ease;
        }
        
        .trend-label {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #666;
            text-align: center;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .action-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .action-card:hover {
            background: white;
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.1);
        }
        
        .action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #667eea;
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #333;
        }
        
        .action-desc {
            font-size: 0.875rem;
            color: #666;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-info {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .payment-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .payment-row > div {
                flex: 1;
                width: 100%;
            }
            
            .payment-amount {
                text-align: left;
            }
            
            .payment-actions {
                text-align: left;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        
        /* Print Styles */
        @media print {
            .section-actions,
            .action-card,
            .btn {
                display: none !important;
            }
            
            .profile-header {
                background: #f8f9fa !important;
                color: #333 !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Customer Profile</div>
        <a href="reports.php" class="back-btn">← Back to Reports</a>
    </nav>
    
    <div class="container">
        <?php $extraSidebarLinks = [['href' => 'reports.php', 'label' => 'Back to Reports', 'active' => true]]; ?>
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-info">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
                    </div>
                    <div class="profile-details">
                        <h1 class="profile-name"><?php echo htmlspecialchars($customer['name']); ?></h1>
                        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 0.5rem;">
                            <span style="background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 20px;">
                                <?php echo htmlspecialchars($customer['sector_name']); ?> Sector
                            </span>
                            <span style="background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 20px;">
                                ID: <?php echo $customer['id']; ?>
                            </span>
                        </div>
                        <div class="profile-meta">
                            <div class="meta-item">
                                <span class="meta-label">Phone</span>
                                <span class="meta-value"><?php echo htmlspecialchars($customer['phone']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Occupation</span>
                                <span class="meta-value"><?php echo htmlspecialchars($customer['occupation']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Monthly Due</span>
                                <span class="meta-value">RWF <?php echo number_format($customer['amount_to_pay'], 2); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Member Since</span>
                                <span class="meta-value"><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-label">Total Paid</div>
                    <div class="stat-value">RWF <?php echo number_format($customer['total_paid_amount'] ?? 0, 2); ?></div>
                    <div class="stat-detail">
                        <?php echo $customer['total_paid_months'] ?? 0; ?> months
                    </div>
                </div>
                
                <div class="stat-card paid">
                    <div class="stat-label">Payment Rate</div>
                    <div class="stat-value"><?php echo $paymentRate; ?>%</div>
                    <div class="stat-detail">
                        <?php echo $customer['total_paid_months'] ?? 0; ?> of <?php echo $totalMonths; ?> months
                    </div>
                </div>
                
                <div class="stat-card due">
                    <div class="stat-label">Monthly Average</div>
                    <div class="stat-value">RWF <?php echo number_format($monthlyAverage, 2); ?></div>
                    <div class="stat-detail">
                        <?php echo $consistencyRate; ?>% consistency
                    </div>
                </div>
                
                <div class="stat-card missing">
                    <div class="stat-label">Missing Payments</div>
                    <div class="stat-value"><?php echo $totalMissing; ?></div>
                    <div class="stat-detail">
                        <?php echo $daysSinceLastPayment ? $daysSinceLastPayment . ' days since last' : 'No payments yet'; ?>
                    </div>
                </div>
            </div>
            
            <!-- Current Status -->
            <div class="payment-status">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin-bottom: 0.5rem;">Current Payment Status</h3>
                        <p style="color: #666; margin-bottom: 0;">
                            <?php echo date('F Y'); ?> - 
                            <?php echo $customer['amount_to_pay'] > 0 ? 'RWF ' . number_format($customer['amount_to_pay'], 2) : 'No amount due'; ?>
                        </p>
                    </div>
                    <div>
                        <?php if ($currentMonthPaid): ?>
                        <span class="status-indicator status-paid">
                            ✅ Paid for <?php echo date('F Y'); ?>
                        </span>
                        <?php elseif ($daysSinceLastPayment && $daysSinceLastPayment > 30): ?>
                        <span class="status-indicator status-overdue">
                            ⚠️ Overdue by <?php echo floor($daysSinceLastPayment / 30); ?> months
                        </span>
                        <?php else: ?>
                        <span class="status-indicator status-pending">
                            ⏳ Payment Pending
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Payment History Section -->
            <div class="profile-section">
                <div class="section-header">
                    <h2 class="section-title">📅 Payment History</h2>
                    <div class="section-actions">
                        <a href="import_payments.php?customer_id=<?php echo $customerId; ?>" class="btn btn-primary btn-sm">
                            💰 Record Payment
                        </a>
                        <button onclick="printSection('payment-history')" class="btn btn-secondary btn-sm">
                            🖨️ Print
                        </button>
                    </div>
                </div>
                
                <div id="payment-history">
                    <?php if (!empty($payments)): ?>
                    <div class="payment-table">
                        <div class="payment-row header">
                            <div class="payment-month">Month</div>
                            <div class="payment-date">Payment Date</div>
                            <div class="payment-amount">Amount Paid</div>
                            <div class="payment-actions">Actions</div>
                        </div>
                        
                        <?php foreach ($payments as $payment): ?>
                        <div class="payment-row">
                            <div class="payment-month">
                                <strong><?php echo date('F Y', strtotime($payment['month_year'] . '-01')); ?></strong>
                            </div>
                            <div class="payment-date">
                                <?php echo $payment['payment_date_display']; ?>
                            </div>
                            <div class="payment-amount">
                                RWF <?php echo number_format($payment['paid_amount'], 2); ?>
                            </div>
                            <div class="payment-actions">
                                <a href="#" class="action-icon" title="Edit" style="font-size: 1rem;">✏️</a>
                                <a href="#" class="action-icon" title="Delete" style="font-size: 1rem;">🗑️</a>
                                <a href="#" class="action-icon" title="Receipt" style="font-size: 1rem;">🧾</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 5px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>Total Payments:</strong> <?php echo count($payments); ?><br>
                                <strong>Total Amount:</strong> RWF <?php echo number_format($customer['total_paid_amount'] ?? 0, 2); ?>
                            </div>
                            <div>
                                <strong>First Payment:</strong> <?php echo $customer['first_payment_date'] ? date('M d, Y', strtotime($customer['first_payment_date'])) : 'Never'; ?><br>
                                <strong>Last Payment:</strong> <?php echo $customer['last_payment_date'] ? date('M d, Y', strtotime($customer['last_payment_date'])) : 'Never'; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">💰</div>
                        <h3 style="margin-bottom: 0.5rem;">No Payment History</h3>
                        <p style="margin-bottom: 1.5rem;">This customer hasn't made any payments yet.</p>
                        <a href="import_payments.php?customer_id=<?php echo $customerId; ?>" class="btn btn-primary">
                            Record First Payment
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Payment Timeline Section -->
            <div class="profile-section">
                <div class="section-header">
                    <h2 class="section-title">📊 Payment Timeline</h2>
                    <div class="section-actions">
                        <select id="timelineFilter" class="btn btn-secondary btn-sm" onchange="filterTimeline(this.value)">
                            <option value="12">Last 12 Months</option>
                            <option value="6">Last 6 Months</option>
                            <option value="24">Last 24 Months</option>
                            <option value="all">All Time</option>
                        </select>
                    </div>
                </div>
                
                <!-- Missing/Paid Months -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="margin-bottom: 1rem;">Monthly Payment Status</h4>
                    <div class="missing-months-grid" id="timelineMonths">
                        <?php
                        // Generate last 12 months
                        $monthsToShow = 12;
                        $currentDate = new DateTime();
                        
                        for ($i = 0; $i < $monthsToShow; $i++) {
                            $month = clone $currentDate;
                            $month->modify("-$i months");
                            $monthYear = $month->format('Y-m');
                            $monthName = $month->format('M Y');
                            
                            // Check if paid
                            $isPaid = false;
                            foreach ($payments as $payment) {
                                if ($payment['month_year'] == $monthYear) {
                                    $isPaid = true;
                                    break;
                                }
                            }
                            
                            $isMissing = in_array($monthYear, $missingMonths);
                            ?>
                            <div class="month-chip <?php echo $isPaid ? 'paid' : ($isMissing ? 'missing' : ''); ?>"
                                 title="<?php echo $monthName . ': ' . ($isPaid ? 'Paid' : ($isMissing ? 'Missing' : 'No data')); ?>">
                                <?php echo $monthName; ?>
                                <?php if ($isPaid): ?>✅<?php elseif ($isMissing): ?>❌<?php endif; ?>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Payment Trend Chart -->
                <?php if (!empty($paymentTrend)): ?>
                <div>
                    <h4 style="margin-bottom: 1rem;">Payment Amount Trend</h4>
                    <div class="trend-container">
                        <div class="trend-bar">
                            <?php foreach (array_reverse($paymentTrend) as $trend): ?>
                            <?php 
                            $maxAmount = max(array_column($paymentTrend, 'monthly_total'));
                            $height = $maxAmount > 0 ? ($trend['monthly_total'] / $maxAmount * 100) : 0;
                            ?>
                            <div class="trend-month">
                                <div class="trend-bar-value" style="height: <?php echo $height; ?>%"
                                     title="<?php echo date('M Y', strtotime($trend['month_year'] . '-01')); ?>: RWF <?php echo number_format($trend['monthly_total'], 2); ?>">
                                </div>
                                <div class="trend-label"><?php echo date('M', strtotime($trend['month_year'] . '-01')); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions -->
            <div class="profile-section" style="display: none;">
                <h2 class="section-title">🚀 Quick Actions</h2>
                <div class="quick-actions">
                    <div class="action-card" onclick="recordPayment()">
                        <div class="action-icon">💰</div>
                        <div class="action-title">Record Payment</div>
                        <div class="action-desc">Add new payment for this customer</div>
                    </div>
                    
                    <div class="action-card" onclick="sendReminder()">
                        <div class="action-icon">📧</div>
                        <div class="action-title">Send Reminder</div>
                        <div class="action-desc">Send payment reminder via SMS/Email</div>
                    </div>
                    
                    <div class="action-card" onclick="updateCustomer()">
                        <div class="action-icon">✏️</div>
                        <div class="action-title">Edit Profile</div>
                        <div class="action-desc">Update customer information</div>
                    </div>
                    
                    <div class="action-card" onclick="generateReport()">
                        <div class="action-icon">📊</div>
                        <div class="action-title">Generate Report</div>
                        <div class="action-desc">Create detailed payment report</div>
                    </div>
                </div>
            </div>
            
            <!-- Customer Notes Section -->
            <div class="profile-section" style="display: none;">
                <div class="section-header">
                    <h2 class="section-title">📝 Notes & Remarks</h2>
                    <div class="section-actions">
                        <button class="btn btn-primary btn-sm" onclick="addNote()">
                            + Add Note
                        </button>
                    </div>
                </div>
                
                <div style="color: #666; font-style: italic;">
                    No notes added yet. Click "Add Note" to add remarks about this customer.
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Quick Actions Functions
        function recordPayment() {
            window.location.href = 'import_payments.php?customer_id=<?php echo $customerId; ?>';
        }
        
        function sendReminder() {
            if (confirm('Send payment reminder to <?php echo addslashes($customer['name']); ?>?')) {
                // Implement AJAX call to send reminder
                alert('Reminder sent to <?php echo addslashes($customer['name']); ?>');
            }
        }
        
        function updateCustomer() {
            // Implement customer edit modal
            alert('Edit customer feature to be implemented');
        }
        
        function generateReport() {
            window.open('customer_report.php?id=<?php echo $customerId; ?>', '_blank');
        }
        
        function addNote() {
            const note = prompt('Enter note for <?php echo addslashes($customer['name']); ?>:');
            if (note) {
                // Implement AJAX to save note
                alert('Note saved: ' + note);
            }
        }
        
        // Print section
        function printSection(sectionId) {
            const payments = <?php echo json_encode($payments); ?>;
            const customerName = <?php echo json_encode($customer['name']); ?>;
            const customerPhone = <?php echo json_encode($customer['phone']); ?>;
            const customerSector = <?php echo json_encode($customer['sector_name']); ?>;
            const customerOccupation = <?php echo json_encode($customer['occupation']); ?>;
            const monthlyDue = <?php echo json_encode(number_format($customer['amount_to_pay'], 2)); ?>;
            const totalPaid = <?php echo json_encode(number_format($customer['total_paid_amount'] ?? 0, 2)); ?>;
            const totalMonths = <?php echo json_encode($customer['total_paid_months'] ?? 0); ?>;
            const firstPayment = <?php echo json_encode($customer['first_payment_date'] ? date('M d, Y', strtotime($customer['first_payment_date'])) : 'Never'); ?>;
            const lastPayment = <?php echo json_encode($customer['last_payment_date'] ? date('M d, Y', strtotime($customer['last_payment_date'])) : 'Never'); ?>;

            // Build table rows from payment data
            let tableRows = '';
            let runningTotal = 0;
            payments.forEach(function(p, i) {
                runningTotal += parseFloat(p.paid_amount);
                const monthDate = new Date(p.month_year + '-01');
                const monthName = monthDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                tableRows += '<tr>' +
                    '<td style="text-align:center;">' + (i + 1) + '</td>' +
                    '<td>' + monthName + '</td>' +
                    '<td>' + p.payment_date_display + '</td>' +
                    '<td style="text-align:right;">RWF ' + parseFloat(p.paid_amount).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>' +
                    '</tr>';
            });

            const printWindow = window.open('', '_blank', 'width=800,height=600');
            printWindow.document.write(`<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Report - ${customerName}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 30px; color: #333; font-size: 13px; }
        .report-header { text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #667eea; }
        .report-header h1 { font-size: 22px; color: #667eea; margin-bottom: 4px; }
        .report-header h2 { font-size: 16px; font-weight: normal; color: #555; margin-bottom: 8px; }
        .report-header .date { font-size: 12px; color: #888; }
        .customer-info { display: flex; justify-content: space-between; margin-bottom: 20px; padding: 12px 15px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #667eea; }
        .customer-info .col { }
        .customer-info .label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
        .customer-info .value { font-weight: 600; margin-bottom: 6px; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 20px; gap: 12px; }
        .summary-box { flex: 1; text-align: center; padding: 10px; border: 1px solid #e0e0e0; border-radius: 6px; }
        .summary-box .num { font-size: 18px; font-weight: bold; color: #667eea; }
        .summary-box .lbl { font-size: 11px; color: #888; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead th { background: #667eea; color: white; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        thead th:first-child { border-radius: 6px 0 0 0; }
        thead th:last-child { border-radius: 0 6px 0 0; text-align: right; }
        tbody td { padding: 9px 12px; border-bottom: 1px solid #eee; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tbody tr:hover { background: #f0f0ff; }
        tbody td:first-child { text-align: center; color: #999; font-size: 12px; }
        tbody td:last-child { text-align: right; font-weight: 500; color: #28a745; }
        .total-row { background: #f8f9fa !important; font-weight: bold; }
        .total-row td { border-top: 2px solid #667eea; padding: 12px; }
        .total-row td:last-child { color: #333; font-size: 14px; }
        .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 11px; color: #999; }
        @media print {
            body { padding: 15px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>Payment History Report</h1>
        <h2>${customerName}</h2>
        <div class="date">Generated on ${new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</div>
    </div>

    <div class="customer-info">
        <div class="col">
            <div class="label">Phone</div>
            <div class="value">${customerPhone}</div>
            <div class="label">Occupation</div>
            <div class="value">${customerOccupation}</div>
        </div>
        <div class="col">
            <div class="label">Sector</div>
            <div class="value">${customerSector}</div>
            <div class="label">Monthly Due</div>
            <div class="value">RWF ${monthlyDue}</div>
        </div>
        <div class="col">
            <div class="label">First Payment</div>
            <div class="value">${firstPayment}</div>
            <div class="label">Last Payment</div>
            <div class="value">${lastPayment}</div>
        </div>
    </div>

    <div class="summary-row">
        <div class="summary-box">
            <div class="num">${totalMonths}</div>
            <div class="lbl">Months Paid</div>
        </div>
        <div class="summary-box">
            <div class="num">${payments.length}</div>
            <div class="lbl">Transactions</div>
        </div>
        <div class="summary-box">
            <div class="num">RWF ${totalPaid}</div>
            <div class="lbl">Total Paid</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Month</th>
                <th>Payment Date</th>
                <th style="text-align:right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            ${tableRows}
            <tr class="total-row">
                <td colspan="3" style="text-align:right;">Grand Total</td>
                <td style="text-align:right;">RWF ${totalPaid}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Payment Tracker &mdash; This report contains ${payments.length} payment record(s) for ${customerName}.
    </div>

    <div class="no-print" style="text-align:center; margin-top:20px;">
        <button onclick="window.print()" style="padding:10px 30px; background:#667eea; color:white; border:none; border-radius:6px; cursor:pointer; font-size:14px;">
            Print Report
        </button>
    </div>
</body>
</html>`);
            printWindow.document.close();
            printWindow.focus();
        }
        
        // Filter timeline
        function filterTimeline(months) {
            // Implement AJAX to load different timeline
            alert('Loading ' + months + ' months of data...');
            // You would typically make an AJAX call here to reload the timeline section
        }
        
        // Export customer data
        function exportCustomerData() {
            const data = {
                customer: <?php echo json_encode($customer); ?>,
                payments: <?php echo json_encode($payments); ?>,
                missingMonths: <?php echo json_encode($missingMonths); ?>
            };
            
            const dataStr = JSON.stringify(data, null, 2);
            const dataUri = 'data:application/json;charset=utf-8,'+ encodeURIComponent(dataStr);
            
            const exportFileDefaultName = 'customer_<?php echo $customerId; ?>_<?php echo date('Y-m-d'); ?>.json';
            
            const linkElement = document.createElement('a');
            linkElement.setAttribute('href', dataUri);
            linkElement.setAttribute('download', exportFileDefaultName);
            linkElement.click();
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printSection('payment-history');
            }
            
            // Ctrl+E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportCustomerData();
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'reports.php';
            }
        });
        
        // Auto-refresh every 2 minutes
        let refreshInterval = setInterval(() => {
            if (!document.hidden && document.hasFocus()) {
                // Only refresh if user is actively viewing the page
                location.reload();
            }
        }, 2 * 60 * 1000);
        
        // Clean up interval
        window.addEventListener('beforeunload', () => {
            clearInterval(refreshInterval);
        });
    </script>
</body>
</html>