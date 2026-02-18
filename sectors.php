<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

// Handle sector actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_sector'])) {
        $sectorName = trim($_POST['sector_name']);
        if (!empty($sectorName)) {
            $stmt = $pdo->prepare("INSERT INTO sectors (sector_name) VALUES (?)");
            $stmt->execute([$sectorName]);
            header('Location: sectors.php');
            exit();
        }
    } elseif (isset($_POST['delete_sector'])) {
        $sectorId = $_POST['sector_id'];
        $stmt = $pdo->prepare("DELETE FROM sectors WHERE id = ?");
        $stmt->execute([$sectorId]);
        header('Location: sectors.php');
        exit();
    }
}

// Get all sectors with customer count
$sectors = $pdo->query("
    SELECT s.*, COUNT(c.id) as customer_count
    FROM sectors s
    LEFT JOIN customers c ON s.id = c.sector_id
    GROUP BY s.id
    ORDER BY s.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sectors - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
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
            <h1>Manage Sectors</h1>
            
            <div class="add-sector-form">
                <h2>Add New Sector</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="sector_name">Sector Name</label>
                        <input type="text" id="sector_name" name="sector_name" required>
                    </div>
                    <button type="submit" name="add_sector" class="btn btn-primary">Add Sector</button>
                </form>
            </div>
            
            <div class="sectors-list">
                <h2>Existing Sectors</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Sector Name</th>
                            <th>Customer Count</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sectors as $sector): ?>
                        <tr>
                            <td><?php echo $sector['id']; ?></td>
                            <td><?php echo htmlspecialchars($sector['sector_name']); ?></td>
                            <td><?php echo $sector['customer_count']; ?></td>
                            <td><?php echo date('Y-m-d', strtotime($sector['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="sector_id" value="<?php echo $sector['id']; ?>">
                                    <button type="submit" name="delete_sector" class="btn btn-danger" 
                                            onclick="return confirm('Delete this sector?')">
                                        Delete
                                    </button>
                                </form>
                            </td>
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