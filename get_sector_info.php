<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

if (isset($_GET['sector_id']) && is_numeric($_GET['sector_id'])) {
    $sectorId = $_GET['sector_id'];
    
    // Get sector details
    $stmt = $pdo->prepare("
        SELECT 
            s.sector_name,
            COUNT(DISTINCT c.id) as customer_count,
            COUNT(DISTINCT p.id) as total_payments
        FROM sectors s
        LEFT JOIN customers c ON s.id = c.sector_id
        LEFT JOIN payments p ON c.id = p.customer_id
        WHERE s.id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$sectorId]);
    $sector = $stmt->fetch();
    
    if ($sector) {
        echo json_encode([
            'success' => true,
            'sector_name' => $sector['sector_name'],
            'customer_count' => $sector['customer_count'] ?: 0,
            'total_payments' => $sector['total_payments'] ?: 0
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Sector not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid sector ID']);
}
?>