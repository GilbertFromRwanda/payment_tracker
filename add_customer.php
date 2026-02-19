<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Fetch sectors for the dropdown
$sectors = $pdo->query("SELECT id, sector_name FROM sectors ORDER BY sector_name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $amount     = floatval(str_replace(',', '', $_POST['amount_to_pay'] ?? ''));
    $sectorId   = intval($_POST['sector_id'] ?? 0);

    // Validate
    if ($name === '')     $errors[] = 'Full name is required.';
    if ($phone === '')    $errors[] = 'Phone number is required.';
    if ($sectorId === 0) $errors[] = 'Sector is required.';
    if ($amount < 0)     $errors[] = 'Amount must be zero or a positive number.';

    // Check for duplicate phone in same sector
    if (empty($errors)) {
        $dup = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND sector_id = ?");
        $dup->execute([$phone, $sectorId]);
        if ($dup->fetch()) {
            $errors[] = 'A customer with this phone number already exists in the selected sector.';
        }
    }

    if (empty($errors)) {
        $ins = $pdo->prepare("
            INSERT INTO customers (name, phone, occupation, amount_to_pay, sector_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $phone, $occupation, $amount, $sectorId]);
        $newId = $pdo->lastInsertId();

        $_SESSION['flash_message'] = "Customer \"$name\" added successfully.";
        $_SESSION['flash_type']    = 'success';
        header('Location: customers.php?sector_id=' . $sectorId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .add-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.06);
            max-width: 600px;
        }
        .add-card h2 {
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
        .form-group .hint {
            font-size: .8rem;
            color: #888;
            margin-top: .3rem;
        }
        .form-actions {
            display: flex;
            gap: .75rem;
            margin-top: 1.75rem;
        }
        .error-list {
            background: #f8d7da;
            color: #721c24;
            padding: .75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }
        .error-list ul { margin: .3rem 0 0 1.1rem; }
        .required { color: #dc3545; margin-left: 2px; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">Payment Tracker</div>
        <div class="nav-user">Add Customer</div>
        <a href="customers.php" class="back-btn">Back to Customers</a>
    </nav>

    <div class="container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="add-card">
                <h2>Add New Customer</h2>

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

                <?php if (empty($sectors)): ?>
                <div class="message warning" style="padding:.75rem 1rem;background:#fff3cd;color:#856404;border-radius:6px;margin-bottom:1.25rem;">
                    No sectors found. Please <a href="sectors.php">create a sector</a> first before adding customers.
                </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name"
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               required maxlength="200" autofocus
                               placeholder="e.g. John Doe">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number <span class="required">*</span></label>
                        <input type="text" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               required maxlength="50"
                               placeholder="e.g. 0788123456">
                        <div class="hint">Must be unique within the selected sector.</div>
                    </div>

                    <div class="form-group">
                        <label for="occupation">Occupation</label>
                        <input type="text" id="occupation" name="occupation"
                               value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>"
                               maxlength="100"
                               placeholder="e.g. Teacher">
                    </div>

                    <div class="form-group">
                        <label for="amount_to_pay">Monthly Amount Due (RWF) <span class="required">*</span></label>
                        <input type="number" id="amount_to_pay" name="amount_to_pay"
                               value="<?php echo htmlspecialchars($_POST['amount_to_pay'] ?? ''); ?>"
                               min="0" step="0.01" required
                               placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label for="sector_id">Sector <span class="required">*</span></label>
                        <select id="sector_id" name="sector_id" required
                                <?php echo empty($sectors) ? 'disabled' : ''; ?>>
                            <option value="">-- Select Sector --</option>
                            <?php foreach ($sectors as $s): ?>
                            <option value="<?php echo $s['id']; ?>"
                                <?php echo (isset($_POST['sector_id']) && $_POST['sector_id'] == $s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['sector_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"
                                <?php echo empty($sectors) ? 'disabled' : ''; ?>>
                            Save Customer
                        </button>
                        <a href="customers.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
