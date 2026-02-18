<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

$message = '';
$messageType = '';
function toFloat($value) {
    $value = str_replace(',', '', $value);
    return floatval(preg_replace('/[^0-9.\-]/', '', $value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sectorId = $_POST['sector_id'] ?? 0;
    $monthYear = $_POST['month_year'] ?? '';

    // Validate inputs
    if (empty($sectorId) || empty($monthYear)) {
        $message = "Please select a sector and payment month";
        $messageType = 'error';
    } else {
        // Handle file upload
        if (isset($_FILES['payment_file']) && $_FILES['payment_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid() . '_' . basename($_FILES['payment_file']['name']);
            $filePath = $uploadDir . $fileName;

            // Check file type
            $fileType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $allowedTypes = ['xls', 'xlsx', 'csv'];

            if (!in_array($fileType, $allowedTypes)) {
                $message = "Only Excel and CSV files are allowed";
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['payment_file']['tmp_name'], $filePath)) {
                require_once 'vendor/autoload.php';

                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $worksheet = $spreadsheet->getActiveSheet();

                    $successCount = 0;
                    $errorCount = 0;
                    $errors = [];
                    $updatedCount = 0;

                    // Get sector name for display
                    $stmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
                    $stmt->execute([$sectorId]);
                    $sector = $stmt->fetch();
                    $sectorName = $sector['sector_name'] ?? 'Unknown Sector';

                    foreach ($worksheet->getRowIterator(2) as $row) {
                        $cellIterator = $row->getCellIterator();
                        $cellIterator->setIterateOnlyExistingCells(false);

                        $data = [];
                        foreach ($cellIterator as $cell) {
                            $data[] = $cell->getValue();
                        }

                        // Check if we have at least 4 columns and row is not empty
                        if (count($data) >= 4 && !empty(trim($data[0] ?? ''))) {
                            try {
                                $name = trim($data[0]);
                                $phone = trim($data[1]);
                                $occupation = trim($data[2] ?? '');
                                $paidAmount = toFloat($data[3]);

                                // Validate amount
                                if ($paidAmount <= 0) {
                                    throw new Exception("Invalid payment amount");
                                }

                                // Find customer by phone and sector
                                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND sector_id = ?");
                                $stmt->execute([$phone, $sectorId]);
                                $customer = $stmt->fetch();

                                if ($customer) {
                                    // Check if payment already exists for this month
                                    $checkStmt = $pdo->prepare("SELECT id FROM payments WHERE customer_id = ? AND month_year = ?");
                                    $checkStmt->execute([$customer['id'], $monthYear]);
                                    if ($checkStmt->rowCount() > 0) {
                                        $stmt = $pdo->prepare("UPDATE payments SET paid_amount = ? WHERE customer_id = ? AND month_year = ?");
                                        $stmt->execute([$paidAmount, $customer['id'], $monthYear]);
                                        $updatedCount++;
                                    } else {
                                        // OR if you want to use the last day of the selected month:
                                        $paymentDate = date('Y-m-t', strtotime($monthYear . '-01'));
                                        $stmt = $pdo->prepare("INSERT INTO payments (customer_id, month_year, paid_amount, payment_date) VALUES (?, ?, ?, ?)");
                                        $stmt->execute([$customer['id'], $monthYear, $paidAmount, $paymentDate]);
                                    }

                                    $successCount++;
                                } else {
                                    $errorCount++;
                                    $errors[] = "Row " . $row->getRowIndex() . ": Customer with phone '{$phone}' not found in '{$sectorName}'";
                                }
                            } catch (Exception $e) {
                                $errorCount++;
                                $errors[] = "Row " . $row->getRowIndex() . ": " . $e->getMessage();
                            }
                        }
                    }

                    // Prepare success message
                    $message = "Import completed for {$monthYear} in '{$sectorName}'<br>";
                    $message .= "<strong>{$successCount}</strong> payments processed successfully";

                    if ($updatedCount > 0) {
                        $message .= " ({$updatedCount} updated)";
                    }

                    if ($errorCount > 0) {
                        $message .= ", <strong>{$errorCount}</strong> records failed";
                        $messageType = 'warning';

                        // Show first 5 errors in message
                        $message .= "<br><br><strong>Some errors:</strong><br>";
                        $message .= implode("<br>", array_slice($errors, 0, 5));
                        if (count($errors) > 5) {
                            $message .= "<br>... and " . (count($errors) - 5) . " more errors";
                        }
                    } else {
                        $messageType = 'success';
                    }

                    // Clean up uploaded file
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                } catch (Exception $e) {
                    $message = "Error processing file: " . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = "Error uploading file. Please try again.";
                $messageType = 'error';
            }
        } else {
            $uploadError = $_FILES['payment_file']['error'] ?? 0;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];

            $message = $errorMessages[$uploadError] ?? 'Please select a file to upload';
            $messageType = 'error';
        }
    }
}

// Get existing sectors
$sectors = $pdo->query("SELECT * FROM sectors ORDER BY sector_name")->fetchAll();

// Get recent payment months for suggestion
$recentMonths = $pdo->query("SELECT DISTINCT month_year FROM payments ORDER BY month_year DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Payments - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .loading-details {
            font-size: 0.9rem;
            color: #666;
        }

        /* Progress bar */
        .progress-container {
            margin-top: 1rem;
            width: 100%;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 10px;
            background: #667eea;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        /* Form enhancements */
        .sector-info {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 5px;
            margin-top: 0.5rem;
            display: none;
        }

        .sector-info.active {
            display: block;
        }

        .month-suggestions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        .month-chip {
            background: #e9ecef;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .month-chip:hover {
            background: #667eea;
            color: white;
        }

        .file-preview {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 5px;
            display: none;
        }

        .file-preview.active {
            display: block;
        }

        .preview-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .file-icon {
            font-size: 2rem;
        }

        .file-details {
            flex: 1;
        }

        .file-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .file-size {
            color: #666;
            font-size: 0.875rem;
        }

        .form-disabled {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Stats preview */
        .stats-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .stat-item {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #666;
        }
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
            <h1>Import Monthly Payments</h1>

            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="import-form" id="importFormContainer">
                <form method="POST" enctype="multipart/form-data" id="paymentForm">
                    <div class="form-group">
                        <label for="sector_id">Select Sector *</label>
                        <select id="sector_id" name="sector_id" required onchange="updateSectorInfo()">
                            <option value="">-- Select Sector --</option>
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>">
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="sector-info" id="sectorInfo">
                            <!-- Sector info will be loaded here -->
                        </div>
                        <?php if (empty($sectors)): ?>
                            <div class="message warning" style="margin-top: 0.5rem;">
                                No sectors found. Please <a href="import_customers.php">import customers</a> or <a href="sectors.php">create sectors</a> first.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="month_year">Payment Month *</label>
                        <input type="month" id="month_year" name="month_year" required
                            min="2020-01" max="<?php echo date('Y-m'); ?>">

                        <?php if (!empty($recentMonths)): ?>
                            <div class="month-suggestions">
                                <small>Recent months:</small>
                                <?php foreach ($recentMonths as $month): ?>
                                    <span class="month-chip" onclick="document.getElementById('month_year').value='<?php echo $month; ?>'">
                                        <?php echo $month; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="payment_file">Excel/CSV File *</label>
                        <input type="file" id="payment_file" name="payment_file"
                            accept=".xls,.xlsx,.csv" required>
                        <small>
                            Supported formats: .xls, .xlsx, .csv<br>
                            Required columns: Name, Phone, Occupation, Paid Amount<br>
                            Max file size: 10MB
                        </small>
                        <div class="file-preview" id="filePreview">
                            <div class="preview-info">
                                <div class="file-icon">📄</div>
                                <div class="file-details">
                                    <div class="file-name" id="fileName"></div>
                                    <div class="file-size" id="fileSize"></div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="clearFile()">Remove</button>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Preview -->
                    <div class="stats-preview" id="statsPreview" style="display: none;">
                        <div class="stat-item">
                            <div class="stat-value" id="customerCount">0</div>
                            <div class="stat-label">Customers in Sector</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="existingPayments">0</div>
                            <div class="stat-label">Existing Payments</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <span class="btn-icon">💰</span>
                                Import Payments
                            </button>
                            <button type="reset" class="btn btn-secondary" onclick="resetForm()">Reset Form</button>
                        </div>
                    </div>
                </form>

                <div class="template-section">
                    <h3>Excel/CSV Template Format</h3>
                    <p>Download the template below and fill in your payment data:</p>

                    <table class="template-table">
                        <thead>
                            <tr>
                                <th>Name *</th>
                                <th>Phone *</th>
                                <th>Occupation</th>
                                <th>Paid Amount *</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>John Doe</td>
                                <td>1234567890</td>
                                <td>Engineer</td>
                                <td>500.00</td>
                            </tr>
                            <tr>
                                <td>Jane Smith</td>
                                <td>0987654321</td>
                                <td>Teacher</td>
                                <td>300.00</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="template-actions">
                        <a href="#" class="btn-download" onclick="downloadPaymentTemplate()">
                            <span class="btn-icon">📄</span>
                            Download Payment Template
                        </a>
                        <a href="reports.php" class="btn-secondary">
                            <span class="btn-icon">📊</span>
                            View Payment Reports
                        </a>
                    </div>

                    <div class="template-notes">
                        <h4>Important Notes:</h4>
                        <ul>
                            <li>First row must contain column headers</li>
                            <li>Phone numbers must match existing customers in the selected sector</li>
                            <li>If a payment already exists for the month, it will be updated</li>
                            <li>Amount must be positive decimal number (e.g., 500.00)</li>
                            <li>Maximum file size: 10MB</li>
                            <li>Processing may take a few moments for large files</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">Importing Payments...</div>
            <div class="loading-details" id="loadingDetails">Please wait while we process your file</div>
            <div class="progress-container">
                <div class="progress-bar" id="progressBar"></div>
            </div>
        </div>
    </div>

    <script>
        // DOM Elements
        const loadingOverlay = document.getElementById('loadingOverlay');
        const loadingDetails = document.getElementById('loadingDetails');
        const progressBar = document.getElementById('progressBar');
        const submitBtn = document.getElementById('submitBtn');
        const importForm = document.getElementById('importFormContainer');
        const paymentForm = document.getElementById('paymentForm');
        const fileInput = document.getElementById('payment_file');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const statsPreview = document.getElementById('statsPreview');
        const customerCountElem = document.getElementById('customerCount');
        const existingPaymentsElem = document.getElementById('existingPayments');

        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // File input change handler
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Show preview
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                filePreview.classList.add('active');

                // Validate file
                validateFile(file);
            } else {
                filePreview.classList.remove('active');
            }
        });

        // Validate file
        function validateFile(file) {
            const maxSize = 10 * 1024 * 1024; // 10MB
            const validExtensions = ['.xls', '.xlsx', '.csv'];
            const fileName = file.name.toLowerCase();

            let isValid = true;
            let errorMessage = '';

            // Check file size
            if (file.size > maxSize) {
                errorMessage = 'File size exceeds 10MB limit';
                isValid = false;
            }

            // Check file extension
            const hasValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
            if (!hasValidExtension) {
                errorMessage = 'Please upload only Excel (.xls, .xlsx) or CSV files';
                isValid = false;
            }

            if (!isValid) {
                alert(errorMessage);
                clearFile();
                return false;
            }

            return true;
        }

        // Clear file input
        function clearFile() {
            fileInput.value = '';
            filePreview.classList.remove('active');
        }

        // Reset form
        function resetForm() {
            clearFile();
            statsPreview.style.display = 'none';
            document.getElementById('sectorInfo').classList.remove('active');
            document.getElementById('sectorInfo').innerHTML = '';
        }

        // Update sector info
        function updateSectorInfo() {
            const sectorId = document.getElementById('sector_id').value;
            const sectorInfo = document.getElementById('sectorInfo');

            if (sectorId) {
                // Show loading in sector info
                sectorInfo.innerHTML = 'Loading sector information...';
                sectorInfo.classList.add('active');

                // Fetch sector details via AJAX
                fetch(`get_sector_info.php?sector_id=${sectorId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            sectorInfo.innerHTML = `
                                <strong>${data.sector_name}</strong><br>
                                <small>${data.customer_count} customers, ${data.total_payments} total payments</small>
                            `;

                            // Update stats preview
                            customerCountElem.textContent = data.customer_count;
                            existingPaymentsElem.textContent = data.total_payments;
                            statsPreview.style.display = 'grid';
                        }
                    })
                    .catch(error => {
                        sectorInfo.innerHTML = '<small style="color:#dc3545">Error loading sector information</small>';
                    });
            } else {
                sectorInfo.classList.remove('active');
                sectorInfo.innerHTML = '';
                statsPreview.style.display = 'none';
            }
        }

        // Download payment template
        function downloadPaymentTemplate() {
            const template = `Name,Phone,Occupation,Paid Amount
John Doe,1234567890,Engineer,500.00
Jane Smith,0987654321,Teacher,300.00
Michael Brown,5551234567,Doctor,700.00
Sarah Johnson,4449876543,Lawyer,600.00`;

            const blob = new Blob([template], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'payment_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Simulate progress for large files
        function simulateProgress() {
            let progress = 0;
            const interval = setInterval(() => {
                progress += Math.random() * 10;
                if (progress > 90) progress = 90; // Cap at 90% until actual completion
                progressBar.style.width = progress + '%';

                // Update loading details
                const details = [
                    "Reading Excel file...",
                    "Validating data...",
                    "Processing payments...",
                    "Updating database...",
                    "Finalizing import..."
                ];
                const detailIndex = Math.floor(progress / 20);
                if (detailIndex < details.length) {
                    loadingDetails.textContent = details[detailIndex];
                }
            }, 500);

            return interval;
        }

        // Form submission handler
        paymentForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate form
            const sectorId = document.getElementById('sector_id').value;
            const monthYear = document.getElementById('month_year').value;
            const file = fileInput.files[0];

            if (!sectorId) {
                alert('Please select a sector');
                return;
            }

            if (!monthYear) {
                alert('Please select a payment month');
                return;
            }

            if (!file) {
                alert('Please select a file to upload');
                return;
            }

            // Validate file
            if (!validateFile(file)) {
                return;
            }

            // Show loading overlay
            loadingOverlay.classList.add('active');
            importForm.classList.add('form-disabled');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-icon">⏳</span> Importing...';

            // Start progress simulation
            const progressInterval = simulateProgress();

            // Create FormData for file upload
            const formData = new FormData(this);

            // Submit form via AJAX for better UX
            fetch('import_payments.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    // Parse the HTML response to extract the message
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const messageDiv = doc.querySelector('.message');

                    // Complete progress bar
                    clearInterval(progressInterval);
                    progressBar.style.width = '100%';
                    loadingDetails.textContent = 'Import complete!';

                    // Wait a moment then reload page
                    setTimeout(() => {
                        loadingOverlay.classList.remove('active');
                        window.location.reload();
                    }, 1500);
                })
                .catch(error => {
                    clearInterval(progressInterval);
                    loadingOverlay.classList.remove('active');
                    importForm.classList.remove('form-disabled');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<span class="btn-icon">💰</span> Import Payments';
                    alert('Error during import: ' + error.message);
                });
        });

        // Initialize date to current month
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const currentMonth = now.toISOString().slice(0, 7);
            document.getElementById('month_year').value = currentMonth;
            document.getElementById('month_year').max = currentMonth;
        });
    </script>
</body>

</html>