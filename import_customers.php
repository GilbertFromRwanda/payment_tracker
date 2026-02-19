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
        $sectorId = $existingSectorId;
        $stmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
        $stmt->execute([$sectorId]);
        $sector = $stmt->fetch();
        $sectorName = $sector['sector_name'];
    } elseif ($sectorOption === 'new' && !empty($sectorName)) {
        $stmt = $pdo->prepare("INSERT INTO sectors (sector_name) VALUES (?)");
        $stmt->execute([$sectorName]);
        $sectorId = $pdo->lastInsertId();
    } else {
        $message = "Please select or create a sector";
        $messageType = 'error';
    }

    if (isset($sectorId) && $sectorId > 0) {
        if (isset($_FILES['customer_file']) && $_FILES['customer_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid() . '_' . basename($_FILES['customer_file']['name']);
            $filePath = $uploadDir . $fileName;

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

                        if (count($data) >= 4 && !empty(trim($data[0]))) {
                            try {
                                $name = trim($data[0]);
                                $phone = trim($data[1]);
                                $occupation = trim($data[2]);
                                $amount = toFloat($data[3]);

                                $checkStmt = $pdo->prepare("SELECT id,sector_id FROM customers WHERE phone = ?");
                                $checkStmt->execute([$phone]);
                                if ($checkStmt->rowCount() > 0) {
                                    $existingCustomer = $checkStmt->fetch();
                                    // if ($existingCustomer['sector_id'] != $sectorId) {
                                    //     throw new \PDOException("Customer exists in a different sector");
                                    // }
                                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, occupation = ?, amount_to_pay = ?, sector_id = ? WHERE phone = ?");
                                    $stmt->execute([$name, $occupation, $amount, $sectorId, $phone]);
                                } else {
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
                        error_log("Customer import errors: " . implode(", ", $errors));
                    } else {
                        $messageType = 'success';
                    }

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
        /* Page header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        .page-header-icon {
            font-size: 2.5rem;
            background: rgba(255,255,255,.15);
            width: 64px;
            height: 64px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: .25rem;
        }
        .page-header p {
            font-size: .9rem;
            opacity: .85;
            margin: 0;
        }

        /* Two-column layout */
        .import-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 1.5rem;
            align-items: start;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .card-header {
            padding: 1.25rem 1.75rem;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .card-header::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 2px;
            flex-shrink: 0;
        }
        .card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }
        .card-body {
            padding: 1.75rem;
        }

        /* Flash message */
        .flash {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: .9rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            line-height: 1.5;
        }
        .flash.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .flash.error   { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .flash.warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .flash-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }

        /* Sector toggle */
        .sector-toggle {
            display: flex;
            gap: 0;
            background: #f0f0f0;
            border-radius: 8px;
            padding: 4px;
            margin-bottom: 1.25rem;
        }
        .sector-toggle label {
            flex: 1;
            text-align: center;
            padding: .55rem 1rem;
            border-radius: 6px;
            font-size: .875rem;
            font-weight: 600;
            color: #888;
            cursor: pointer;
            transition: all .2s;
            user-select: none;
        }
        .sector-toggle input[type="radio"] { display: none; }
        .sector-toggle input[type="radio"]:checked + label {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }

        /* Sector fields */
        .sector-field {
            display: none;
            animation: fadeIn .25s ease;
        }
        .sector-field.active { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .field-label {
            display: block;
            font-size: .875rem;
            font-weight: 600;
            color: #555;
            margin-bottom: .4rem;
        }
        .field-input {
            width: 100%;
            padding: .7rem 1rem;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: .95rem;
            font-family: inherit;
            transition: border-color .2s, box-shadow .2s;
        }
        .field-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.12);
        }
        .field-hint {
            font-size: .78rem;
            color: #aaa;
            margin-top: .35rem;
        }

        /* Section divider */
        .section-divider {
            border: none;
            border-top: 2px solid #f0f0f0;
            margin: 1.5rem 0;
        }

        /* File upload zone */
        .upload-zone {
            border: 2px dashed #d0d5e8;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            background: #fafbff;
            position: relative;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #667eea;
            background: #f0f2ff;
        }
        .upload-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .upload-icon { font-size: 2rem; margin-bottom: .5rem; }
        .upload-title {
            font-weight: 600;
            color: #333;
            font-size: .95rem;
            margin-bottom: .25rem;
        }
        .upload-subtitle { font-size: .8rem; color: #999; }
        .upload-formats {
            display: flex;
            gap: .4rem;
            justify-content: center;
            margin-top: .75rem;
        }
        .fmt-badge {
            background: #e9ecef;
            color: #666;
            font-size: .72rem;
            font-weight: 700;
            padding: .2rem .5rem;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .file-selected {
            display: none;
            align-items: center;
            gap: .75rem;
            background: #f0f2ff;
            border: 2px solid #667eea;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-top: .75rem;
        }
        .file-selected.active { display: flex; }
        .file-sel-icon { font-size: 1.5rem; }
        .file-sel-name { font-weight: 600; color: #333; font-size: .9rem; }
        .file-sel-size { font-size: .78rem; color: #888; }

        /* Submit button */
        .btn-import {
            width: 100%;
            padding: .85rem;
            border: none;
            border-radius: 9px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .2s, transform .2s;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
        }
        .btn-import:hover {
            opacity: .9;
            transform: translateY(-1px);
        }
        .btn-import:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        /* Template card */
        .template-col { display: flex; flex-direction: column; gap: 1.5rem; }

        /* Column preview table */
        .col-preview {
            display: flex;
            flex-direction: column;
            gap: .4rem;
            margin: 1rem 0;
        }
        .col-row {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .55rem .75rem;
            background: #f8f9fa;
            border-radius: 7px;
        }
        .col-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            font-size: .7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .col-name { font-weight: 600; font-size: .85rem; color: #333; flex: 1; }
        .col-req {
            font-size: .7rem;
            font-weight: 700;
            padding: .15rem .45rem;
            border-radius: 4px;
        }
        .col-req.required { background: #f8d7da; color: #721c24; }
        .col-req.optional { background: #e2e8f0; color: #666; }

        /* Notes */
        .notes-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .notes-list li {
            display: flex;
            gap: .6rem;
            font-size: .83rem;
            color: #666;
            line-height: 1.45;
        }
        .notes-list li::before {
            content: '•';
            color: #667eea;
            font-weight: 700;
            flex-shrink: 0;
            margin-top: .05rem;
        }

        /* Download btn */
        .btn-download {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: .7rem;
            border: 1.5px solid #667eea;
            border-radius: 8px;
            color: #667eea;
            font-weight: 600;
            font-size: .875rem;
            text-decoration: none;
            cursor: pointer;
            background: white;
            transition: all .2s;
            margin-top: 1rem;
        }
        .btn-download:hover {
            background: #667eea;
            color: white;
        }

        /* No sectors warning */
        .no-sectors-warn {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .85rem;
            margin-top: .75rem;
        }
        .no-sectors-warn a { color: #856404; font-weight: 600; }

        @media (max-width: 900px) {
            .import-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .page-header { flex-direction: column; text-align: center; }
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

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-icon">📥</div>
                <div>
                    <h1>Import Customers</h1>
                    <p>Upload an Excel or CSV file to bulk-import customers into a sector</p>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="flash <?php echo htmlspecialchars($messageType); ?>">
                <span class="flash-icon">
                    <?php if ($messageType === 'success') echo '✓';
                          elseif ($messageType === 'error') echo '✕';
                          else echo '⚠'; ?>
                </span>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <div class="import-layout">

                <!-- Import Form -->
                <div class="card">
                    <div class="card-header"><h2>Upload File</h2></div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="importForm">

                            <!-- Sector Toggle -->
                            <label class="field-label">Sector <span style="color:#dc3545;">*</span></label>
                            <div class="sector-toggle">
                                <input type="radio" id="opt_existing" name="sector_option" value="existing" checked>
                                <label for="opt_existing">Use Existing</label>
                                <input type="radio" id="opt_new" name="sector_option" value="new">
                                <label for="opt_new">Create New</label>
                            </div>

                            <!-- Existing sector -->
                            <div class="sector-field active" id="field_existing">
                                <label class="field-label" for="existing_sector">Choose Sector</label>
                                <select id="existing_sector" name="existing_sector" class="field-input" required>
                                    <option value="">-- Select a Sector --</option>
                                    <?php foreach ($sectors as $s): ?>
                                    <option value="<?php echo $s['id']; ?>">
                                        <?php echo htmlspecialchars($s['sector_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($sectors)): ?>
                                <div class="no-sectors-warn">
                                    No sectors found. <a href="sectors.php">Create a sector first</a> or switch to "Create New" above.
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- New sector -->
                            <div class="sector-field" id="field_new">
                                <label class="field-label" for="sector_name">New Sector Name</label>
                                <input type="text" id="sector_name" name="sector_name"
                                       class="field-input"
                                       placeholder="e.g. Kicukiro"
                                       list="sector_suggestions">
                                <datalist id="sector_suggestions">
                                    <?php foreach ($sectors as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['sector_name']); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="field-hint">Must be unique. Will be created on import.</div>
                            </div>

                            <hr class="section-divider">

                            <!-- File Upload -->
                            <label class="field-label">Excel / CSV File <span style="color:#dc3545;">*</span></label>
                            <div class="upload-zone" id="uploadZone">
                                <input type="file" id="customer_file" name="customer_file"
                                       accept=".xls,.xlsx,.csv" required>
                                <div class="upload-icon">📂</div>
                                <div class="upload-title">Click to browse or drag & drop</div>
                                <div class="upload-subtitle">Maximum file size: 10 MB</div>
                                <div class="upload-formats">
                                    <span class="fmt-badge">xls</span>
                                    <span class="fmt-badge">xlsx</span>
                                    <span class="fmt-badge">csv</span>
                                </div>
                            </div>
                            <div class="file-selected" id="fileSelected">
                                <span class="file-sel-icon">📄</span>
                                <div>
                                    <div class="file-sel-name" id="fileName"></div>
                                    <div class="file-sel-size" id="fileSize"></div>
                                </div>
                            </div>

                            <button type="submit" class="btn-import" id="submitBtn">
                                📥 Import Customers
                            </button>

                        </form>
                    </div>
                </div>

                <!-- Right column -->
                <div class="template-col">

                    <!-- Column format -->
                    <div class="card">
                        <div class="card-header"><h2>Required Columns</h2></div>
                        <div class="card-body">
                            <p style="font-size:.85rem;color:#666;margin-bottom:.5rem;">
                                First row must be headers. Columns must be in this order:
                            </p>
                            <div class="col-preview">
                                <div class="col-row">
                                    <div class="col-num">A</div>
                                    <div class="col-name">Name</div>
                                    <span class="col-req required">Required</span>
                                </div>
                                <div class="col-row">
                                    <div class="col-num">B</div>
                                    <div class="col-name">Phone</div>
                                    <span class="col-req required">Required</span>
                                </div>
                                <div class="col-row">
                                    <div class="col-num">C</div>
                                    <div class="col-name">Occupation</div>
                                    <span class="col-req optional">Optional</span>
                                </div>
                                <div class="col-row">
                                    <div class="col-num">D</div>
                                    <div class="col-name">Amount to Pay</div>
                                    <span class="col-req required">Required</span>
                                </div>
                            </div>
                            <a href="#" class="btn-download" onclick="downloadTemplate(); return false;">
                                ⬇ Download CSV Template
                            </a>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="card">
                        <div class="card-header"><h2>Important Notes</h2></div>
                        <div class="card-body">
                            <ul class="notes-list">
                                <li>First row must contain column headers and will be skipped.</li>
                                <li>Phone numbers must be unique within the selected sector.</li>
                                <li>If a customer with the same phone already exists in the sector, their record will be updated.</li>
                                <li>Amount must be a number (e.g. 500 or 500.00).</li>
                                <li>Maximum file size is 10 MB.</li>
                            </ul>
                        </div>
                    </div>

                </div><!-- /template-col -->

            </div><!-- /import-layout -->
        </main>
    </div>

    <script>
        // Sector toggle
        document.querySelectorAll('input[name="sector_option"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const isExisting = this.value === 'existing';
                document.getElementById('field_existing').classList.toggle('active', isExisting);
                document.getElementById('field_new').classList.toggle('active', !isExisting);
                document.getElementById('existing_sector').required = isExisting;
                document.getElementById('sector_name').required = !isExisting;
            });
        });

        // File input
        document.getElementById('customer_file').addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;

            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('File size exceeds 10 MB limit.');
                this.value = '';
                return;
            }

            const valid = ['.xls', '.xlsx', '.csv'].some(ext => file.name.toLowerCase().endsWith(ext));
            if (!valid) {
                alert('Please upload only Excel (.xls, .xlsx) or CSV files.');
                this.value = '';
                return;
            }

            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
            document.getElementById('fileSelected').classList.add('active');
        });

        // Drag & drop style
        const zone = document.getElementById('uploadZone');
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop',      e => { e.preventDefault(); zone.classList.remove('dragover'); });

        // Form submit
        document.getElementById('importForm').addEventListener('submit', function(e) {
            const isExisting = document.querySelector('input[name="sector_option"]:checked').value === 'existing';
            const sector = isExisting ? document.getElementById('existing_sector') : document.getElementById('sector_name');
            const file   = document.getElementById('customer_file');

            if (!sector.value.trim()) {
                alert(isExisting ? 'Please select a sector.' : 'Please enter a new sector name.');
                e.preventDefault(); return;
            }
            if (!file.value) {
                alert('Please select a file to upload.');
                e.preventDefault(); return;
            }

            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '⏳ Importing...';
            btn.disabled = true;
        });

        // Download template
        function downloadTemplate() {
            const csv = `Name,Phone,Occupation,Amount to Pay\nJohn Doe,1234567890,Engineer,500.00\nJane Smith,0987654321,Teacher,300.00\nMichael Brown,5551234567,Doctor,700.00\nSarah Johnson,4449876543,Lawyer,600.00`;
            const blob = new Blob([csv], { type: 'text/csv' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'customer_template.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
