<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

$customerId  = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$preMonthYear = (isset($_GET['month_year']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month_year']))
    ? $_GET['month_year'] : date('Y-m');

// Fetch customer (required)
$customer = null;
if ($customerId) {
    $stmt = $pdo->prepare("
        SELECT c.*, s.sector_name
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        WHERE c.id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCustomerId = intval($_POST['customer_id'] ?? 0);
    $monthYear      = trim($_POST['month_year'] ?? '');
    $paidAmount     = floatval(str_replace(',', '', $_POST['paid_amount'] ?? ''));
    $paymentDate    = trim($_POST['payment_date'] ?? date('Y-m-d'));

    // Validate
    if ($postCustomerId <= 0)                          $errors[] = 'Customer is required.';
    if (!preg_match('/^\d{4}-\d{2}$/', $monthYear))   $errors[] = 'Payment month is required.';
    if ($paidAmount <= 0)                              $errors[] = 'Amount must be greater than zero.';
    if (!$paymentDate)                                 $errors[] = 'Payment date is required.';

    if (empty($errors)) {
        // Check for existing payment this month
        $chk = $pdo->prepare("SELECT id FROM payments WHERE customer_id = ? AND month_year = ?");
        $chk->execute([$postCustomerId, $monthYear]);

        if ($chk->fetch()) {
            // Update existing
            $pdo->prepare("UPDATE payments SET paid_amount = ?, payment_date = ? WHERE customer_id = ? AND month_year = ?")
                ->execute([$paidAmount, $paymentDate, $postCustomerId, $monthYear]);
            $msg = 'Payment updated successfully.';
        } else {
            // Insert new
            $pdo->prepare("INSERT INTO payments (customer_id, month_year, paid_amount, payment_date) VALUES (?, ?, ?, ?)")
                ->execute([$postCustomerId, $monthYear, $paidAmount, $paymentDate]);
            $msg = 'Payment recorded successfully.';
        }

        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type']    = 'success';
        header('Location: customer_profile.php?id=' . $postCustomerId);
        exit;
    }

    // Reload customer on error
    if ($postCustomerId > 0 && !$customer) {
        $stmt = $pdo->prepare("SELECT c.*, s.sector_name FROM customers c LEFT JOIN sectors s ON c.sector_id = s.id WHERE c.id = ?");
        $stmt->execute([$postCustomerId]);
        $customer = $stmt->fetch();
        $customerId = $postCustomerId;
    }
}

// All customers for the dropdown (in case no customer pre-selected)
$allCustomers = [];
if(!$customer) {
$allCustomers = $pdo->query("
    SELECT c.id, c.name, c.phone, s.sector_name
    FROM customers c
    LEFT JOIN sectors s ON c.sector_id = s.id
    ORDER BY c.name ASC
")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Payment - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .payment-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.06);
            max-width: 560px;
        }
        .payment-card h2 {
            margin-bottom: 1.5rem;
            color: #333;
            font-size: 1.4rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: .75rem;
        }
        .customer-banner {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .customer-banner .avatar {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,.25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: bold;
            flex-shrink: 0;
        }
        .customer-banner .info .cname {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .customer-banner .info .cmeta {
            font-size: .85rem;
            opacity: .85;
            margin-top: .1rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: .4rem;
            color: #444;
            font-size: .9rem;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: .65rem .9rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .95rem;
            transition: border-color .2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-actions {
            display: flex;
            gap: .75rem;
            margin-top: 1.5rem;
        }
        .error-list {
            background: #f8d7da;
            color: #721c24;
            padding: .75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }
        .error-list ul { margin: .25rem 0 0 1rem; }
        .hint { font-size: .8rem; color: #888; margin-top: .3rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Add Payment</div>
        <a href="<?php echo $customer ? 'customer_profile.php?id=' . $customer['id'] : 'customers.php'; ?>"
           class="back-btn">Back</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="payment-card">
                <h2>Record Payment</h2>

                <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <strong>Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($customer): ?>
                <!-- Customer banner when pre-selected -->
                <div class="customer-banner">
                    <div class="avatar"><?php echo strtoupper(substr($customer['name'], 0, 1)); ?></div>
                    <div class="info">
                        <div class="cname"><?php echo htmlspecialchars($customer['name']); ?></div>
                        <div class="cmeta">
                            <?php echo htmlspecialchars($customer['phone']); ?> &nbsp;&bull;&nbsp;
                            <?php echo htmlspecialchars($customer['sector_name']); ?> &nbsp;&bull;&nbsp;
                            Monthly due: RWF <?php echo number_format($customer['amount_to_pay'], 2); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <?php if ($customer): ?>
                    <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                    <?php else: ?>
                    <div class="form-group">
                        <label for="customer_id">Customer *</label>
                        <select id="customer_id" name="customer_id" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($allCustomers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"
                                <?php echo (isset($_POST['customer_id']) && $_POST['customer_id'] == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                                (<?php echo htmlspecialchars($c['phone']); ?> — <?php echo htmlspecialchars($c['sector_name']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="month_year">Payment Month *</label>
                            <input type="month" id="month_year" name="month_year" required
                                   min="2020-01"
                                   max="<?php echo date('Y-m'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['month_year'] ?? $preMonthYear); ?>">
                        </div>

                        <div class="form-group">
                            <label for="payment_date">Payment Date *</label>
                            <input type="date" id="payment_date" name="payment_date" required
                                   max="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="paid_amount">Amount Paid (RWF) *</label>
                        <input type="number" id="paid_amount" name="paid_amount" required
                               min="0.01" step="0.01"
                               placeholder="<?php echo $customer ? number_format($customer['amount_to_pay'], 2) : '0.00'; ?>"
                               value="<?php echo htmlspecialchars($_POST['paid_amount'] ?? ($customer ? $customer['amount_to_pay'] : '')); ?>">
                        <?php if ($customer && $customer['amount_to_pay'] > 0): ?>
                        <div class="hint">Monthly due is RWF <?php echo number_format($customer['amount_to_pay'], 2); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Payment</button>
                        <a href="<?php echo $customer ? 'customer_profile.php?id=' . $customer['id'] : 'customers.php'; ?>"
                           class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
