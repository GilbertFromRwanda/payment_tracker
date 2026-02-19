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
    $sectorId  = $_POST['sector_id'] ?? 0;
    $monthYear = $_POST['month_year'] ?? '';

    if (empty($sectorId) || empty($monthYear)) {
        $message = "Please select a sector and payment month";
        $messageType = 'error';
    } else {
        if (isset($_FILES['payment_file']) && $_FILES['payment_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = uniqid() . '_' . basename($_FILES['payment_file']['name']);
            $filePath = $uploadDir . $fileName;

            $fileType     = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $allowedTypes = ['xls', 'xlsx', 'csv'];

            if (!in_array($fileType, $allowedTypes)) {
                $message = "Only Excel and CSV files are allowed";
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['payment_file']['tmp_name'], $filePath)) {
                require_once 'vendor/autoload.php';

                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $worksheet   = $spreadsheet->getActiveSheet();

                    $successCount = 0;
                    $errorCount   = 0;
                    $updatedCount = 0;
                    $errors       = [];

                    $stmt = $pdo->prepare("SELECT sector_name FROM sectors WHERE id = ?");
                    $stmt->execute([$sectorId]);
                    $sector     = $stmt->fetch();
                    $sectorName = $sector['sector_name'] ?? 'Unknown Sector';

                    foreach ($worksheet->getRowIterator(2) as $row) {
                        $cellIterator = $row->getCellIterator();
                        $cellIterator->setIterateOnlyExistingCells(false);

                        $data = [];
                        foreach ($cellIterator as $cell) $data[] = $cell->getValue();

                        if (count($data) >= 4 && !empty(trim($data[0] ?? ''))) {
                            try {
                                $name       = trim($data[0]);
                                $phone      = trim($data[1]);
                                $paidAmount = toFloat($data[3]);
                                if ($paidAmount <= 0) throw new \Exception("Invalid payment amount");
                                $stmt = $pdo->prepare("SELECT id,sector_id FROM customers WHERE phone = ? ");
                                $stmt->execute([$phone]);
                                $customer = $stmt->fetch();
                                // if ($customer && $customer['sector_id'] != $sectorId) {
                                //     throw new \Exception("Customer belongs to a different sector");
                                //  $errors[] = "Customer belongs to a different sector";
                                // }

                                if ($customer) {
                                    $chk = $pdo->prepare("SELECT id FROM payments WHERE customer_id = ? AND month_year = ?");
                                    $chk->execute([$customer['id'], $monthYear]);
                                    if ($chk->rowCount() > 0) {
                                        $pdo->prepare("UPDATE payments SET paid_amount = ? WHERE customer_id = ? AND month_year = ?")
                                            ->execute([$paidAmount, $customer['id'], $monthYear]);
                                        $updatedCount++;
                                    } else {
                                        $paymentDate = date('Y-m-t', strtotime($monthYear . '-01'));
                                        $pdo->prepare("INSERT INTO payments (customer_id, month_year, paid_amount, payment_date) VALUES (?, ?, ?, ?)")
                                            ->execute([$customer['id'], $monthYear, $paidAmount, $paymentDate]);
                                    }
                                    $successCount++;
                                } else {
                                    $errorCount++;
                                    $errors[] = "Row " . $row->getRowIndex() . ": Phone '{$phone}' not found in '{$sectorName}'";
                                }
                            } catch (\Exception $e) {
                                $errorCount++;
                                $errors[] = "Row " . $row->getRowIndex() . ": " . $e->getMessage();
                            }
                        }
                    }

                    $message = "Import completed for {$monthYear} in '{$sectorName}' — "
                             . "<strong>{$successCount}</strong> payments processed";
                    if ($updatedCount > 0) $message .= " ({$updatedCount} updated)";
                    if ($errorCount > 0) {
                        $message .= ", <strong>{$errorCount}</strong> failed";
                        $messageType = 'warning';
                        $message .= "<br><br><strong>Errors:</strong><br>" . implode("<br>", array_slice($errors, 0, 5));
                        if (count($errors) > 5) $message .= "<br>… and " . (count($errors) - 5) . " more";
                    } else {
                        $messageType = 'success';
                    }

                    if (file_exists($filePath)) unlink($filePath);

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
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE  => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
            ];
            $message = $errMap[$uploadError] ?? 'Please select a file to upload';
            $messageType = 'error';
        }
    }
}

$sectors      = $pdo->query("SELECT * FROM sectors ORDER BY sector_name")->fetchAll();
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
            font-size: 2.2rem;
            background: rgba(255,255,255,.15);
            width: 64px;
            height: 64px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .page-header h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
        .page-header p  { font-size: .9rem; opacity: .85; margin: 0; }

        /* Two-column layout */
        .import-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 1.5rem;
            align-items: start;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card:last-child { margin-bottom: 0; }
        .card-header {
            padding: 1.2rem 1.75rem;
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
        .card-header h2 { font-size: 1rem; font-weight: 700; color: #333; margin: 0; }
        .card-body { padding: 1.75rem; }

        /* Flash */
        .flash {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: .9rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            line-height: 1.6;
        }
        .flash.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .flash.error   { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .flash.warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .flash-icon { flex-shrink: 0; margin-top: .1rem; }

        /* Field */
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
        .field-group { margin-bottom: 1.25rem; }

        /* Sector info box */
        .sector-info-box {
            display: none;
            margin-top: .65rem;
            background: #f0f2ff;
            border: 1px solid #d0d5f0;
            border-radius: 8px;
            padding: .75rem 1rem;
            animation: fadeIn .2s ease;
        }
        .sector-info-box.active { display: flex; gap: 1.25rem; flex-wrap: wrap; }
        .sib-stat { text-align: center; }
        .sib-val  { font-size: 1.3rem; font-weight: 700; color: #667eea; line-height: 1; }
        .sib-lbl  { font-size: .72rem; color: #888; margin-top: .2rem; text-transform: uppercase; letter-spacing: .4px; }

        /* Month chips */
        .month-chips {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            margin-top: .5rem;
        }
        .month-chip {
            background: #e9ecef;
            color: #555;
            padding: .25rem .7rem;
            border-radius: 20px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            border: none;
        }
        .month-chip:hover { background: #667eea; color: white; }

        /* File upload zone */
        .upload-zone {
            border: 2px dashed #d0d5e8;
            border-radius: 10px;
            padding: 1.75rem;
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
        .upload-icon   { font-size: 1.8rem; margin-bottom: .4rem; }
        .upload-title  { font-weight: 600; color: #333; font-size: .95rem; margin-bottom: .2rem; }
        .upload-sub    { font-size: .8rem; color: #999; }
        .upload-fmts   { display: flex; gap: .4rem; justify-content: center; margin-top: .6rem; }
        .fmt-badge {
            background: #e9ecef;
            color: #666;
            font-size: .7rem;
            font-weight: 700;
            padding: .15rem .45rem;
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
            padding: .85rem 1.1rem;
            margin-top: .65rem;
        }
        .file-selected.active { display: flex; }
        .fs-icon { font-size: 1.4rem; }
        .fs-name { font-weight: 600; color: #333; font-size: .875rem; word-break: break-all; }
        .fs-size { font-size: .75rem; color: #888; }
        .fs-remove {
            margin-left: auto;
            background: none;
            border: 1.5px solid #ddd;
            border-radius: 6px;
            padding: .3rem .6rem;
            font-size: .75rem;
            cursor: pointer;
            color: #888;
            flex-shrink: 0;
        }
        .fs-remove:hover { border-color: #dc3545; color: #dc3545; }

        /* No sectors */
        .no-sectors-warn {
            background: #fff3cd; color: #856404;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .85rem;
            margin-top: .65rem;
        }
        .no-sectors-warn a { color: #856404; font-weight: 600; }

        /* Submit */
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
        .btn-import:hover   { opacity: .9; transform: translateY(-1px); }
        .btn-import:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        /* Right column */
        .right-col { display: flex; flex-direction: column; gap: 0; }

        .col-preview { display: flex; flex-direction: column; gap: .4rem; margin: .75rem 0; }
        .col-row {
            display: flex; align-items: center; gap: .7rem;
            padding: .5rem .75rem; background: #f8f9fa; border-radius: 7px;
        }
        .col-num {
            width: 22px; height: 22px; border-radius: 50%;
            background: #667eea; color: white;
            font-size: .7rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .col-name { font-weight: 600; font-size: .85rem; color: #333; flex: 1; }
        .col-req { font-size: .7rem; font-weight: 700; padding: .15rem .45rem; border-radius: 4px; }
        .col-req.required { background: #f8d7da; color: #721c24; }
        .col-req.optional { background: #e2e8f0; color: #666; }

        .notes-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .5rem; }
        .notes-list li { display: flex; gap: .6rem; font-size: .83rem; color: #666; line-height: 1.45; }
        .notes-list li::before { content: '•'; color: #667eea; font-weight: 700; flex-shrink: 0; }

        .btn-download {
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            width: 100%; padding: .7rem; border: 1.5px solid #667eea; border-radius: 8px;
            color: #667eea; font-weight: 600; font-size: .875rem;
            text-decoration: none; cursor: pointer; background: white;
            transition: all .2s; margin-top: 1rem;
        }
        .btn-download:hover { background: #667eea; color: white; }

        /* Loading overlay */
        .loading-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.55);
            display: none; align-items: center; justify-content: center;
            z-index: 9999;
        }
        .loading-overlay.active { display: flex; }
        .loading-box {
            background: white; border-radius: 14px;
            padding: 2.25rem 2rem; text-align: center;
            max-width: 360px; width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: popIn .25s ease;
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(.93); }
            to   { opacity: 1; transform: scale(1); }
        }
        .spinner {
            width: 52px; height: 52px;
            border: 5px solid #f0f0f0; border-top-color: #667eea;
            border-radius: 50%; animation: spin 1s linear infinite;
            margin: 0 auto 1.25rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-title  { font-size: 1.05rem; font-weight: 700; color: #333; margin-bottom: .35rem; }
        .loading-detail { font-size: .85rem; color: #888; margin-bottom: 1rem; }
        .progress-track { height: 8px; background: #f0f0f0; border-radius: 10px; overflow: hidden; }
        .progress-fill  {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 10px; transition: width .3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) { .import-layout { grid-template-columns: 1fr; } }
        @media (max-width: 600px) { .page-header { flex-direction: column; text-align: center; } }
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
                <div class="page-header-icon">💰</div>
                <div>
                    <h1>Import Monthly Payments</h1>
                    <p>Upload an Excel or CSV file to bulk-record payments for a sector</p>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="flash <?php echo htmlspecialchars($messageType); ?>">
                <span class="flash-icon">
                    <?php if ($messageType === 'success') echo '✓';
                          elseif ($messageType === 'error') echo '✕';
                          else echo '⚠'; ?>
                </span>
                <div><?php echo $message; ?></div>
            </div>
            <?php endif; ?>

            <div class="import-layout">

                <!-- Form -->
                <div>
                    <div class="card">
                        <div class="card-header"><h2>Upload File</h2></div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" id="paymentForm">

                                <!-- Sector -->
                                <div class="field-group">
                                    <label class="field-label" for="sector_id">
                                        Sector <span style="color:#dc3545;">*</span>
                                    </label>
                                    <select id="sector_id" name="sector_id" class="field-input"
                                            required onchange="loadSectorInfo()">
                                        <option value="">-- Select Sector --</option>
                                        <?php foreach ($sectors as $s): ?>
                                        <option value="<?php echo $s['id']; ?>">
                                            <?php echo htmlspecialchars($s['sector_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($sectors)): ?>
                                    <div class="no-sectors-warn">
                                        No sectors found. <a href="import_customers.php">Import customers</a>
                                        or <a href="sectors.php">create sectors</a> first.
                                    </div>
                                    <?php endif; ?>
                                    <div class="sector-info-box" id="sectorInfoBox">
                                        <div class="sib-stat">
                                            <div class="sib-val" id="sibCustomers">—</div>
                                            <div class="sib-lbl">Customers</div>
                                        </div>
                                        <div class="sib-stat">
                                            <div class="sib-val" id="sibPayments">—</div>
                                            <div class="sib-lbl">Total Payments</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Month -->
                                <div class="field-group">
                                    <label class="field-label" for="month_year">
                                        Payment Month <span style="color:#dc3545;">*</span>
                                    </label>
                                    <input type="month" id="month_year" name="month_year"
                                           class="field-input" required
                                           min="2020-01" max="<?php echo date('Y-m'); ?>">
                                    <?php if (!empty($recentMonths)): ?>
                                    <div class="month-chips">
                                        <?php foreach ($recentMonths as $m): ?>
                                        <button type="button" class="month-chip"
                                                onclick="document.getElementById('month_year').value='<?php echo $m; ?>'">
                                            <?php echo date('M Y', strtotime($m . '-01')); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- File -->
                                <div class="field-group">
                                    <label class="field-label">
                                        Excel / CSV File <span style="color:#dc3545;">*</span>
                                    </label>
                                    <div class="upload-zone" id="uploadZone">
                                        <input type="file" id="payment_file" name="payment_file"
                                               accept=".xls,.xlsx,.csv" required>
                                        <div class="upload-icon">📂</div>
                                        <div class="upload-title">Click to browse or drag & drop</div>
                                        <div class="upload-sub">Maximum file size: 10 MB</div>
                                        <div class="upload-fmts">
                                            <span class="fmt-badge">xls</span>
                                            <span class="fmt-badge">xlsx</span>
                                            <span class="fmt-badge">csv</span>
                                        </div>
                                    </div>
                                    <div class="file-selected" id="fileSelected">
                                        <span class="fs-icon">📄</span>
                                        <div>
                                            <div class="fs-name" id="fsName"></div>
                                            <div class="fs-size" id="fsSize"></div>
                                        </div>
                                        <button type="button" class="fs-remove" onclick="clearFile()">✕ Remove</button>
                                    </div>
                                </div>

                                <button type="submit" class="btn-import" id="submitBtn">
                                    💰 Import Payments
                                </button>

                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="right-col">

                    <div class="card">
                        <div class="card-header"><h2>Required Columns</h2></div>
                        <div class="card-body">
                            <p style="font-size:.85rem;color:#666;margin-bottom:.25rem;">
                                First row must be headers. Columns in this order:
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
                                    <div class="col-name">Paid Amount</div>
                                    <span class="col-req required">Required</span>
                                </div>
                            </div>
                            <a href="#" class="btn-download" onclick="downloadTemplate(); return false;">
                                ⬇ Download CSV Template
                            </a>
                        </div>
                    </div>

                    <div class="card" style="margin-top:1.5rem;">
                        <div class="card-header"><h2>Important Notes</h2></div>
                        <div class="card-body">
                            <ul class="notes-list">
                                <li>Phone numbers must match existing customers in the selected sector.</li>
                                <li>If a payment already exists for the selected month it will be updated.</li>
                                <li>Paid amount must be a positive number (e.g. 500.00).</li>
                                <li>Rows with an unrecognised phone number will be skipped and counted as errors.</li>
                                <li>Maximum file size is 10 MB.</li>
                            </ul>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <div class="loading-title">Importing Payments…</div>
            <div class="loading-detail" id="loadingDetail">Please wait while we process your file</div>
            <div class="progress-track">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>
    </div>

    <script>
        // Set month to current on load
        document.addEventListener('DOMContentLoaded', function() {
            const cur = new Date().toISOString().slice(0, 7);
            document.getElementById('month_year').value = cur;
            document.getElementById('month_year').max   = cur;
        });

        // Sector info
        function loadSectorInfo() {
            const id  = document.getElementById('sector_id').value;
            const box = document.getElementById('sectorInfoBox');
            if (!id) { box.classList.remove('active'); return; }

            fetch(`get_sector_info.php?sector_id=${id}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        document.getElementById('sibCustomers').textContent = d.customer_count;
                        document.getElementById('sibPayments').textContent  = d.total_payments;
                        box.classList.add('active');
                    }
                })
                .catch(() => box.classList.remove('active'));
        }

        // File input
        document.getElementById('payment_file').addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                alert('File size exceeds 10 MB limit.');
                this.value = ''; return;
            }
            const valid = ['.xls','.xlsx','.csv'].some(e => file.name.toLowerCase().endsWith(e));
            if (!valid) {
                alert('Please upload only Excel (.xls, .xlsx) or CSV files.');
                this.value = ''; return;
            }

            document.getElementById('fsName').textContent = file.name;
            document.getElementById('fsSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
            document.getElementById('fileSelected').classList.add('active');
        });

        function clearFile() {
            document.getElementById('payment_file').value = '';
            document.getElementById('fileSelected').classList.remove('active');
        }

        // Drag style
        const zone = document.getElementById('uploadZone');
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
        zone.addEventListener('drop',      e  => { e.preventDefault(); zone.classList.remove('dragover'); });

        // Submit with loading overlay
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            const sector = document.getElementById('sector_id').value;
            const month  = document.getElementById('month_year').value;
            const file   = document.getElementById('payment_file').files[0];

            if (!sector) { alert('Please select a sector.'); e.preventDefault(); return; }
            if (!month)  { alert('Please select a payment month.'); e.preventDefault(); return; }
            if (!file)   { alert('Please select a file to upload.'); e.preventDefault(); return; }

            const overlay = document.getElementById('loadingOverlay');
            const detail  = document.getElementById('loadingDetail');
            const fill    = document.getElementById('progressFill');
            const btn     = document.getElementById('submitBtn');

            overlay.classList.add('active');
            btn.disabled = true;
            btn.textContent = '⏳ Importing…';

            const steps = ['Reading file…','Validating data…','Processing payments…','Updating database…','Finalising…'];
            let progress = 0;
            const iv = setInterval(() => {
                progress += Math.random() * 12;
                if (progress > 90) progress = 90;
                fill.style.width = progress + '%';
                detail.textContent = steps[Math.min(Math.floor(progress / 20), steps.length - 1)];
            }, 400);

            // Let the form submit normally; overlay stays until page reloads
            setTimeout(() => clearInterval(iv), 30000);
        });

        // Download template
        function downloadTemplate() {
            const csv = `Name,Phone,Occupation,Paid Amount\nJohn Doe,1234567890,Engineer,500.00\nJane Smith,0987654321,Teacher,300.00\nMichael Brown,5551234567,Doctor,700.00\nSarah Johnson,4449876543,Lawyer,600.00`;
            const blob = new Blob([csv], { type: 'text/csv' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'payment_template.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
