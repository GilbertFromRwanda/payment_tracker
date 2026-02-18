<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Get statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT c.id) as total_customers,
        COUNT(DISTINCT s.id) as total_sectors,
        SUM(c.amount_to_pay) as total_amount,
        (SELECT COUNT(DISTINCT month_year) FROM payments) as total_payment_months
    FROM customers c
    LEFT JOIN sectors s ON c.sector_id = s.id
");
$stats = $stmt->fetch();

// Get recent payments
$stmt = $pdo->query("
    SELECT p.*, c.name as customer_name, s.sector_name 
    FROM payments p
    JOIN customers c ON p.customer_id = c.id
    JOIN sectors s ON c.sector_id = s.id
    ORDER BY p.payment_date DESC
    LIMIT 10
");
$recentPayments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Welcome, <?php echo $_SESSION['username']; ?></div>
        <a href="?logout" class="logout-btn">Logout</a>
    </nav>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <h1>Dashboard</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Customers</h3>
                    <p class="stat-number"><?php echo $stats['total_customers']; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Sectors</h3>
                    <p class="stat-number"><?php echo $stats['total_sectors']; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Amount</h3>
                    <p class="stat-number">RWF <?php echo number_format($stats['total_amount'], 2); ?></p>
                </div>
                <div class="stat-card">
                    <h3>Payment Months</h3>
                    <p class="stat-number"><?php echo $stats['total_payment_months']; ?></p>
                </div>
            </div>
            
            <div class="recent-section">
                <h2>Recent Payments</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Sector</th>
                            <th>Month</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?php echo $payment['payment_date']; ?></td>
                            <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($payment['sector_name']); ?></td>
                            <td><?php echo $payment['month_year']; ?></td>
                            <td>Frw <?php echo number_format($payment['paid_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>