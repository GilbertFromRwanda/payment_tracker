<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

$customerId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$customerId) {
    header('Location: customers.php');
    exit;
}

// Fetch customer
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: customers.php');
    exit;
}

// Fetch sectors
$sectors = $pdo->query("SELECT id, sector_name FROM sectors ORDER BY sector_name")->fetchAll();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $amount     = floatval($_POST['amount_to_pay'] ?? 0);
    $sectorId   = intval($_POST['sector_id'] ?? 0);

    if ($name === '')    $errors[] = 'Name is required.';
    if ($phone === '')   $errors[] = 'Phone is required.';
    if ($sectorId === 0) $errors[] = 'Sector is required.';
    if ($amount < 0)     $errors[] = 'Amount must be a positive number.';

    if (empty($errors)) {
        $upd = $pdo->prepare("
            UPDATE customers
            SET name = ?, phone = ?, occupation = ?, amount_to_pay = ?, sector_id = ?
            WHERE id = ?
        ");
        $upd->execute([$name, $phone, $occupation, $amount, $sectorId, $customerId]);

        $_SESSION['flash_message'] = "Customer updated successfully.";
        $_SESSION['flash_type']    = 'success';
        header('Location: customers.php' . ($sectorId ? '?sector_id=' . $sectorId : ''));
        exit;
    }

    // Repopulate with submitted data on error
    $customer['name']          = $name;
    $customer['phone']         = $phone;
    $customer['occupation']    = $occupation;
    $customer['amount_to_pay'] = $amount;
    $customer['sector_id']     = $sectorId;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .edit-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.06);
            max-width: 600px;
        }
        .edit-card h2 {
            margin-bottom: 1.5rem;
            color: #333;
            font-size: 1.4rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: .75rem;
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
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Edit Customer</div>
        <a href="customers.php" class="back-btn">Back to Customers</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="edit-card">
                <h2>Edit Customer</h2>

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

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name"
                               value="<?php echo htmlspecialchars($customer['name']); ?>"
                               required maxlength="200">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone *</label>
                        <input type="text" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($customer['phone']); ?>"
                               required maxlength="50">
                    </div>

                    <div class="form-group">
                        <label for="occupation">Occupation</label>
                        <input type="text" id="occupation" name="occupation"
                               value="<?php echo htmlspecialchars($customer['occupation']); ?>"
                               maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="amount_to_pay">Monthly Amount Due (RWF) *</label>
                        <input type="number" id="amount_to_pay" name="amount_to_pay"
                               value="<?php echo htmlspecialchars($customer['amount_to_pay']); ?>"
                               min="0" step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label for="sector_id">Sector *</label>
                        <select id="sector_id" name="sector_id" required>
                            <option value="">-- Select Sector --</option>
                            <?php foreach ($sectors as $s): ?>
                            <option value="<?php echo $s['id']; ?>"
                                <?php echo $s['id'] == $customer['sector_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['sector_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="customers.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
