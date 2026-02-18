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
    $sectorOption = $_POST['sector_option'] ?? '';
    $sectorName = trim($_POST['sector_name'] ?? '');
    $existingSectorId = $_POST['existing_sector'] ?? 0;
    
    // Determine which sector to use
    if ($sectorOption === 'existing' && $existingSectorId > 0) {
        // Use existing sector
        $sectorId = $existingSectorId;
        
        // Get sector name for display
        $stmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
        $stmt->execute([$sectorId]);
        $sector = $stmt->fetch();
        $sectorName = $sector['sector_name'];
    } elseif ($sectorOption === 'new' && !empty($sectorName)) {
        // Create new sector
        $stmt = $pdo->prepare("INSERT INTO sectors (sector_name) VALUES (?)");
        $stmt->execute([$sectorName]);
        $sectorId = $pdo->lastInsertId();
    } else {
        $message = "Please select or create a sector";
        $messageType = 'error';
    }
    
    if (isset($sectorId) && $sectorId > 0) {
        // Handle file upload
        if (isset($_FILES['customer_file']) && $_FILES['customer_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = uniqid() . '_' . basename($_FILES['customer_file']['name']);
            $filePath = $uploadDir . $fileName;
            
            // Check file type
            $fileType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $allowedTypes = ['xls', 'xlsx', 'csv'];
            
            if (!in_array($fileType, $allowedTypes)) {
                $message = "Only Excel and CSV files are allowed";
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['customer_file']['tmp_name'], $filePath)) {
                require_once 'vendor/autoload.php';
                
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $worksheet = $spreadsheet->getActiveSheet();
                    
                    $successCount = 0;
                    $errorCount = 0;
                    $errors = [];
                    
                    foreach ($worksheet->getRowIterator(2) as $row) {
                        $cellIterator = $row->getCellIterator();
                        $cellIterator->setIterateOnlyExistingCells(false);
                        
                        $data = [];
                        foreach ($cellIterator as $cell) {
                            $data[] = $cell->getValue();
                        }
                        
                        // Check if we have at least 4 columns
                        if (count($data) >= 4 && !empty(trim($data[0]))) {
                            try {
                                $name = trim($data[0]);
                                $phone = trim($data[1]);
                                $occupation = trim($data[2]);
                                $amount = toFloat($data[3]);
                                
                                // Check if customer already exists in this sector
                                $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND sector_id = ?");
                                $checkStmt->execute([$phone, $sectorId]);
                                
                                if ($checkStmt->rowCount() > 0) {
                                    // Update existing customer
                                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, occupation = ?, amount_to_pay = ? WHERE phone = ? AND sector_id = ?");
                                    $stmt->execute([$name, $occupation, $amount, $phone, $sectorId]);
                                } else {
                                    // Insert new customer
                                    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, occupation, amount_to_pay, sector_id) VALUES (?, ?, ?, ?, ?)");
                                    $stmt->execute([$name, $phone, $occupation, $amount, $sectorId]);
                                }
                                
                                $successCount++;
                            } catch (\PDOException $e) {
                                $errorCount++;
                                $errors[] = "Row " . $row->getRowIndex() . ": " . $e->getMessage();
                            }
                        }
                    }
                    
                    $message = "Import completed: {$successCount} records imported successfully to '{$sectorName}'";
                    if ($errorCount > 0) {
                        $message .= ", {$errorCount} records failed";
                        $messageType = 'warning';
                        
                        // Log errors
                        error_log("Customer import errors: " . implode(", ", $errors));
                    } else {
                        $messageType = 'success';
                    }
                    
                    // Clean up uploaded file
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                } catch (\Exception $e) {
                    $message = "Error processing file: " . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = "Error uploading file";
                $messageType = 'error';
            }
        } else {
            $message = "Please select a file to upload";
            $messageType = 'error';
        }
    }
}

// Get existing sectors
$sectors = $pdo->query("SELECT * FROM sectors ORDER BY sector_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Customers - Payment Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .sector-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .sector-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .sector-fields {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .existing-sector-field,
        .new-sector-field {
            display: none;
        }
        
        .existing-sector-field.active,
        .new-sector-field.active {
            display: block;
        }
        
        #existing_sector {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
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
            <h1>Import Customers</h1>
            
            <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <div class="import-form">
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="form-group">
                        <label>Sector Selection *</label>
                        <div class="sector-options">
                            <div class="sector-option">
                                <input type="radio" id="existing_sector_opt" name="sector_option" value="existing" checked>
                                <label for="existing_sector_opt">Select Existing Sector</label>
                            </div>
                            <div class="sector-option">
                                <input type="radio" id="new_sector_opt" name="sector_option" value="new">
                                <label for="new_sector_opt">Create New Sector</label>
                            </div>
                        </div>
                        
                        <div class="sector-fields">
                            <!-- Existing Sector Field -->
                            <div class="existing-sector-field active">
                                <label for="existing_sector">Choose Sector:</label>
                                <select id="existing_sector" name="existing_sector" required>
                                    <option value="">-- Select a Sector --</option>
                                    <?php foreach ($sectors as $sector): ?>
                                    <option value="<?php echo $sector['id']; ?>">
                                        <?php echo htmlspecialchars($sector['sector_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($sectors)): ?>
                                <div class="message warning" style="margin-top: 0.5rem;">
                                    No sectors found. Please create a new sector or go to <a href="sectors.php">Sectors Management</a> to create one.
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- New Sector Field -->
                            <div class="new-sector-field">
                                <label for="sector_name">New Sector Name:</label>
                                <input type="text" id="sector_name" name="sector_name" 
                                       placeholder="Enter new sector name" 
                                       list="sector_suggestions">
                                <datalist id="sector_suggestions">
                                    <?php foreach ($sectors as $sector): ?>
                                    <option value="<?php echo htmlspecialchars($sector['sector_name']); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small>Enter a unique name for the new sector</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_file">Excel/CSV File *</label>
                        <input type="file" id="customer_file" name="customer_file" 
                               accept=".xls,.xlsx,.csv" required>
                        <small>
                            Supported formats: .xls, .xlsx, .csv<br>
                            Required columns: Name, Phone, Occupation, Amount to Pay
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <span class="btn-icon">📥</span>
                                Import Customers
                            </button>
                            <button type="reset" class="btn btn-secondary">Reset Form</button>
                        </div>
                    </div>
                </form>
                
                <div class="template-section">
                    <h3>Excel/CSV Template Format</h3>
                    <p>Download the template below and fill in your customer data:</p>
                    
                    <table class="template-table">
                        <thead>
                            <tr>
                                <th>Name *</th>
                                <th>Phone *</th>
                                <th>Occupation</th>
                                <th>Amount to Pay *</th>
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
                            <tr>
                                <td>Michael Brown</td>
                                <td>5551234567</td>
                                <td>Doctor</td>
                                <td>700.00</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="template-actions">
                        <a href="#" class="btn-download" onclick="downloadTemplate()">
                            <span class="btn-icon">📄</span>
                            Download Template
                        </a>
                        <a href="sectors.php" class="btn-secondary">
                            <span class="btn-icon">🏷️</span>
                            Manage Sectors
                        </a>
                    </div>
                    
                    <div class="template-notes">
                        <h4>Important Notes:</h4>
                        <ul>
                            <li>First row must contain column headers</li>
                            <li>Phone numbers must be unique within the same sector</li>
                            <li>Amount must be in decimal format (e.g., 500.00)</li>
                            <li>If a customer already exists in the selected sector, their information will be updated</li>
                            <li>Maximum file size: 10MB</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Handle sector option toggle
        document.querySelectorAll('input[name="sector_option"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const existingField = document.querySelector('.existing-sector-field');
                const newField = document.querySelector('.new-sector-field');
                
                if (this.value === 'existing') {
                    existingField.classList.add('active');
                    newField.classList.remove('active');
                    document.getElementById('existing_sector').required = true;
                    document.getElementById('sector_name').required = false;
                } else {
                    existingField.classList.remove('active');
                    newField.classList.add('active');
                    document.getElementById('existing_sector').required = false;
                    document.getElementById('sector_name').required = true;
                }
            });
        });
        
        // Download template
        function downloadTemplate() {
            const template = `Name,Phone,Occupation,Amount to Pay
John Doe,1234567890,Engineer,500.00
Jane Smith,0987654321,Teacher,300.00
Michael Brown,5551234567,Doctor,700.00
Sarah Johnson,4449876543,Lawyer,600.00`;

            const blob = new Blob([template], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'customer_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // File validation
        document.getElementById('customer_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Check file size (10MB limit)
                const maxSize = 10 * 1024 * 1024; // 10MB in bytes
                if (file.size > maxSize) {
                    alert('File size exceeds 10MB limit. Please choose a smaller file.');
                    this.value = '';
                    return;
                }
                
                // Check file extension
                const validExtensions = ['.xls', '.xlsx', '.csv'];
                const fileName = file.name.toLowerCase();
                const isValid = validExtensions.some(ext => fileName.endsWith(ext));
                
                if (!isValid) {
                    alert('Please upload only Excel (.xls, .xlsx) or CSV files.');
                    this.value = '';
                }
            }
        });
        
        // Form validation
        document.getElementById('importForm').addEventListener('submit', function(e) {
            const sectorOption = document.querySelector('input[name="sector_option"]:checked').value;
            const existingSector = document.getElementById('existing_sector');
            const newSector = document.getElementById('sector_name');
            const fileInput = document.getElementById('customer_file');
            
            let isValid = true;
            
            // Validate sector selection
            if (sectorOption === 'existing') {
                if (!existingSector.value) {
                    alert('Please select an existing sector');
                    isValid = false;
                }
            } else {
                if (!newSector.value.trim()) {
                    alert('Please enter a new sector name');
                    isValid = false;
                }
            }
            
            // Validate file
            if (!fileInput.value) {
                alert('Please select a file to upload');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            } else {
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<span class="btn-icon">⏳</span> Importing...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>
</html>