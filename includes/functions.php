<?php
require_once __DIR__ . '/../config/database.php';

function importCustomers($filePath, $sectorId, $sectorName) {
    global $pdo;
    
    require_once 'vendor/autoload.php';
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($worksheet->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        $data = [];
        foreach ($cellIterator as $cell) {
            $data[] = $cell->getValue();
        }
        
        if (count($data) >= 4) {
            try {
                $stmt = $pdo->prepare("INSERT INTO customers (name, phone, occupation, amount_to_pay, sector_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    trim($data[0]),    // name
                    trim($data[1]),    // phone
                    trim($data[2]),    // occupation
                    floatval($data[3]), // amount_to_pay
                    $sectorId
                ]);
                $successCount++;
            } catch (PDOException $e) {
                $errorCount++;
                error_log("Import error: " . $e->getMessage());
            }
        }
    }
    
    return ['success' => $successCount, 'error' => $errorCount];
}

function importPayments($filePath, $sectorId, $monthYear) {
    global $pdo;
    
    require_once 'vendor/autoload.php';
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($worksheet->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        $data = [];
        foreach ($cellIterator as $cell) {
            $data[] = $cell->getValue();
        }
        
        if (count($data) >= 4) {
            try {
                // Find customer by phone and sector
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND sector_id = ?");
                $stmt->execute([trim($data[1]), $sectorId]);
                $customer = $stmt->fetch();
                
                if ($customer) {
                    $stmt = $pdo->prepare("INSERT INTO payments (customer_id, month_year, paid_amount, payment_date) VALUES (?, ?, ?, CURDATE()) ON DUPLICATE KEY UPDATE paid_amount = VALUES(paid_amount)");
                    $stmt->execute([
                        $customer['id'],
                        $monthYear,
                        floatval($data[3])
                    ]);
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (PDOException $e) {
                $errorCount++;
                error_log("Payment import error: " . $e->getMessage());
            }
        }
    }
    
    return ['success' => $successCount, 'error' => $errorCount];
}

function getPaymentReport($sectorId = null, $customerId = null) {
    global $pdo;
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name,
            c.phone,
            c.occupation,
            c.amount_to_pay,
            s.sector_name,
            GROUP_CONCAT(p.month_year ORDER BY p.month_year) as paid_months,
            COUNT(p.month_year) as paid_count,
            MAX(p.payment_date) as last_payment
        FROM customers c
        LEFT JOIN sectors s ON c.sector_id = s.id
        LEFT JOIN payments p ON c.id = p.customer_id
    ";
    
    $params = [];
    $conditions = [];
    
    if ($sectorId) {
        $conditions[] = "c.sector_id = ?";
        $params[] = $sectorId;
    }
    
    if ($customerId) {
        $conditions[] = "c.id = ?";
        $params[] = $customerId;
    }
    
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    
    $query .= " GROUP BY c.id ORDER BY c.name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMissingMonths($customerId, $yearFilter = null, $monthFilter = null) {
    global $pdo;
    // Get customer's first payment month
    $stmt = $pdo->prepare("SELECT MIN(month_year) as first_month FROM payments WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $firstPayment = $stmt->fetch();
    // Determine start date
    if ($yearFilter) {
        $startDate = $yearFilter . '-' . ($monthFilter ? str_pad($monthFilter, 2, '0', STR_PAD_LEFT) : '01') . '-01';
    } elseif ($firstPayment['first_month']) {
        $startDate = $firstPayment['first_month'] . '-01';
    } else {
        $startDate = date('Y-01');
    }
    
    // Get all months from start date to current month (or filtered month)
    $months = [];
    $current = new \DateTime($startDate . '-01');
    
    if ($yearFilter && $monthFilter) {
        // If specific month selected, only show that month
        $endDate = new \DateTime($yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT) . '-01');
    } else {
        $endDate = new \DateTime();
    }
    
    while ($current <= $endDate) {
        $months[] = $current->format('Y-m');
        $current->modify('+1 month');
    }
    
    // Get paid months for customer (with filter if applicable)
    $paidQuery = "SELECT month_year FROM payments WHERE customer_id = ?";
    $paidParams = [$customerId];
    
    if ($yearFilter && $monthFilter) {
        $paidQuery .= " AND month_year = ?";
        $paidParams[] = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
    } elseif ($yearFilter) {
        $paidQuery .= " AND month_year LIKE ?";
        $paidParams[] = $yearFilter . '-%';
    }
    
    $stmt = $pdo->prepare($paidQuery);
    $stmt->execute($paidParams);
    $paidMonths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Find missing months
    $missing = array_diff($months, $paidMonths);
    
    return array_values($missing);
}
// Add this function to includes/functions.php
function buildQueryString($params = []) {
    $query = [];
    foreach ($_GET as $key => $value) {
        if ($key !== 'page' || !isset($params['page'])) {
            $query[$key] = $value;
        }
    }
    $query = array_merge($query, $params);
    return http_build_query($query);
}


?>