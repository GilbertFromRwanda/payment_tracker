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
            header('Location: sectors.php?added=1');
            exit();
        }
    } elseif (isset($_POST['delete_sector'])) {
        $sectorId = $_POST['sector_id'];
        $stmt = $pdo->prepare("DELETE FROM sectors WHERE id = ?");
        $stmt->execute([$sectorId]);
        header('Location: sectors.php?deleted=1');
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

$totalSectors   = count($sectors);
$totalCustomers = array_sum(array_column($sectors, 'customer_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sectors - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Page header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-header-left h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: .25rem;
        }
        .page-header-left p {
            font-size: .9rem;
            opacity: .85;
            margin: 0;
        }
        .page-header-stats {
            display: flex;
            gap: 1rem;
        }
        .header-stat {
            text-align: center;
            background: rgba(255,255,255,.15);
            border-radius: 10px;
            padding: .75rem 1.25rem;
            min-width: 90px;
        }
        .header-stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        .header-stat-label {
            font-size: .72rem;
            opacity: .8;
            margin-top: .25rem;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* Two-column layout */
        .sectors-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        /* Add sector card */
        .add-card {
            background: white;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            position: sticky;
            top: 1rem;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1.25rem;
            padding-bottom: .75rem;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .card-title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 2px;
            flex-shrink: 0;
        }
        .add-card label {
            display: block;
            font-size: .875rem;
            font-weight: 600;
            color: #555;
            margin-bottom: .4rem;
        }
        .add-card input[type="text"] {
            width: 100%;
            padding: .7rem 1rem;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: .95rem;
            transition: border-color .2s, box-shadow .2s;
            font-family: inherit;
        }
        .add-card input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.12);
        }
        .add-card .btn-add {
            width: 100%;
            padding: .75rem;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 1rem;
            font-size: .95rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            cursor: pointer;
            transition: opacity .2s, transform .2s;
        }
        .add-card .btn-add:hover {
            opacity: .9;
            transform: translateY(-1px);
        }

        /* Sectors list card */
        .list-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .list-card-header {
            padding: 1.2rem 1.75rem;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .total-badge {
            background: #f0f2ff;
            color: #667eea;
            font-size: .8rem;
            font-weight: 600;
            padding: .25rem .7rem;
            border-radius: 20px;
        }

        /* Sectors table */
        .sectors-table {
            width: 100%;
            border-collapse: collapse;
        }
        .sectors-table thead th {
            background: #f8f9fa;
            color: #999;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: .85rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .sectors-table tbody tr {
            transition: background .15s;
            border-bottom: 1px solid #f5f5f5;
        }
        .sectors-table tbody tr:last-child {
            border-bottom: none;
        }
        .sectors-table tbody tr:hover {
            background: #fafbff;
        }
        .sectors-table td {
            padding: .95rem 1.5rem;
            vertical-align: middle;
        }

        .sector-cell {
            display: flex;
            align-items: center;
            gap: .9rem;
        }
        .sector-avatar {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: 700;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sector-name {
            font-weight: 600;
            color: #333;
            font-size: .95rem;
        }
        .sector-id {
            font-size: .75rem;
            color: #bbb;
            margin-top: .1rem;
        }

        .customers-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            background: #e8f5e9;
            color: #2e7d32;
            font-size: .8rem;
            font-weight: 600;
            padding: .3rem .75rem;
            border-radius: 20px;
            white-space: nowrap;
        }
        .customers-badge.zero {
            background: #f5f5f5;
            color: #aaa;
        }

        .date-text {
            font-size: .875rem;
            color: #999;
        }

        .btn-delete {
            background: none;
            border: 1.5px solid #f0f0f0;
            color: #dc3545;
            padding: .4rem .85rem;
            border-radius: 7px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }
        .btn-delete:hover {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #bbb;
        }
        .empty-state-icon { font-size: 3rem; margin-bottom: 1rem; }
        .empty-state p { font-size: .95rem; }

        /* Flash */
        .flash {
            padding: .85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: .9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .flash.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }

        /* Delete modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 14px;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
            animation: modalIn .2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95) translateY(-8px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #fff3f3;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .modal h3 { font-size: 1.1rem; color: #333; margin-bottom: .5rem; }
        .modal p  { font-size: .88rem; color: #666; margin-bottom: 1.5rem; line-height: 1.6; }
        .modal-actions { display: flex; gap: .75rem; justify-content: flex-end; }
        .btn-modal-cancel {
            padding: .6rem 1.2rem;
            border: 1.5px solid #e0e0e0;
            background: white;
            border-radius: 7px;
            font-size: .875rem;
            cursor: pointer;
            color: #555;
            transition: background .2s;
        }
        .btn-modal-cancel:hover { background: #f5f5f5; }
        .btn-modal-confirm {
            padding: .6rem 1.2rem;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 7px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-modal-confirm:hover { background: #c82333; }

        @media (max-width: 860px) {
            .sectors-layout { grid-template-columns: 1fr; }
            .add-card { position: static; }
        }
        @media (max-width: 600px) {
            .page-header { flex-direction: column; text-align: center; }
            .page-header-stats { justify-content: center; }
            .sectors-table thead th:nth-child(3),
            .sectors-table td:nth-child(3) { display: none; }
        }
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

            <?php if (isset($_GET['added'])): ?>
            <div class="flash success">✓ Sector added successfully.</div>
            <?php elseif (isset($_GET['deleted'])): ?>
            <div class="flash success">✓ Sector deleted successfully.</div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1>Sectors Management</h1>
                    <p>Organize and manage your customer sectors</p>
                </div>
                <div class="page-header-stats">
                    <div class="header-stat">
                        <div class="header-stat-value"><?php echo $totalSectors; ?></div>
                        <div class="header-stat-label">Sectors</div>
                    </div>
                    <div class="header-stat">
                        <div class="header-stat-value"><?php echo $totalCustomers; ?></div>
                        <div class="header-stat-label">Customers</div>
                    </div>
                </div>
            </div>

            <div class="sectors-layout">

                <!-- Add Sector Form -->
                <div class="add-card">
                    <div class="card-title">Add New Sector</div>
                    <form method="POST">
                        <div style="margin-bottom:1rem;">
                            <label for="sector_name">Sector Name</label>
                            <input type="text" id="sector_name" name="sector_name"
                                   placeholder="e.g. Kicukiro"
                                   required maxlength="100" autofocus>
                        </div>
                        <button type="submit" name="add_sector" class="btn-add">+ Add Sector</button>
                    </form>
                </div>

                <!-- Sectors List -->
                <div class="list-card">
                    <div class="list-card-header">
                        <div class="card-title" style="margin:0;padding:0;border:none;">All Sectors</div>
                        <span class="total-badge"><?php echo $totalSectors; ?> total</span>
                    </div>

                    <?php if (empty($sectors)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🏘️</div>
                        <p>No sectors yet.<br>Add your first sector using the form.</p>
                    </div>
                    <?php else: ?>
                    <table class="sectors-table">
                        <thead>
                            <tr>
                                <th>Sector</th>
                                <th>Customers</th>
                                <th>Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sectors as $sector): ?>
                            <tr>
                                <td>
                                    <div class="sector-cell">
                                        <div class="sector-avatar">
                                            <?php echo strtoupper(substr($sector['sector_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="sector-name"><?php echo htmlspecialchars($sector['sector_name']); ?></div>
                                            <div class="sector-id">#<?php echo $sector['id']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="customers-badge <?php echo $sector['customer_count'] == 0 ? 'zero' : ''; ?>">
                                        <?php echo $sector['customer_count']; ?>
                                        <?php echo $sector['customer_count'] == 1 ? 'customer' : 'customers'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="date-text">
                                        <?php echo date('M d, Y', strtotime($sector['created_at'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn-delete"
                                            onclick="confirmDelete(<?php echo $sector['id']; ?>, '<?php echo addslashes(htmlspecialchars($sector['sector_name'])); ?>', <?php echo $sector['customer_count']; ?>)">
                                        🗑 Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-icon">🗑️</div>
            <h3>Delete Sector?</h3>
            <p id="modalMessage"></p>
            <div class="modal-actions">
                <button class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="sector_id" id="deleteSectorId">
                    <button type="submit" name="delete_sector" class="btn-modal-confirm">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(id, name, count) {
            document.getElementById('deleteSectorId').value = id;
            const msg = count > 0
                ? `Deleting <strong>${name}</strong> will unlink <strong>${count}</strong> customer(s) from this sector. The customers themselves will not be deleted.`
                : `Are you sure you want to delete <strong>${name}</strong>? This action cannot be undone.`;
            document.getElementById('modalMessage').innerHTML = msg;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>
