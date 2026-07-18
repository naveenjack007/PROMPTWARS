<?php
// cooking-todo-app/api/groceries.php

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

try {
    $pdo = getDBConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Fetch all groceries for user
        $stmt = $pdo->prepare("SELECT * FROM groceries WHERE user_id = ? ORDER BY expiry_date ASC, name ASC");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();
        
        echo json_encode(['status' => 'success', 'data' => $items]);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'add') {
            $name = trim(strtolower($data['name'] ?? ''));
            $quantity = floatval($data['quantity'] ?? 0);
            $unit = trim($data['unit'] ?? 'pcs');
            $category = trim($data['category'] ?? 'Pantry');
            $price = floatval($data['price'] ?? 0.0);
            $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
            
            if (empty($name) || $quantity <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Item name and quantity are required.']);
                exit;
            }
            
            // Check if item already exists to merge quantity
            $stmt = $pdo->prepare("SELECT id, quantity, price FROM groceries WHERE user_id = ? AND name = ? AND unit = ?");
            $stmt->execute([$userId, $name, $unit]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $newQty = $existing['quantity'] + $quantity;
                $newPrice = $existing['price'] + $price; // cumulative price
                $updateStmt = $pdo->prepare("UPDATE groceries SET quantity = ?, price = ?, expiry_date = COALESCE(?, expiry_date) WHERE id = ?");
                $updateStmt->execute([$newQty, $newPrice, $expiry_date, $existing['id']]);
                
                echo json_encode(['status' => 'success', 'message' => 'Merged with existing item in pantry.', 'id' => $existing['id']]);
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO groceries (user_id, name, quantity, unit, category, price, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->execute([$userId, $name, $quantity, $unit, $category, $price, $expiry_date]);
                
                echo json_encode(['status' => 'success', 'message' => 'Added to pantry.', 'id' => $pdo->lastInsertId()]);
            }
            exit;
        }
        
        if ($action === 'update') {
            $id = intval($data['id'] ?? 0);
            $name = trim(strtolower($data['name'] ?? ''));
            $quantity = floatval($data['quantity'] ?? 0);
            $unit = trim($data['unit'] ?? '');
            $category = trim($data['category'] ?? '');
            $price = floatval($data['price'] ?? 0.0);
            $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
            
            if ($id <= 0 || empty($name) || $quantity <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid update payload.']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE groceries SET name = ?, quantity = ?, unit = ?, category = ?, price = ?, expiry_date = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $quantity, $unit, $category, $price, $expiry_date, $id, $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'Pantry item updated.']);
            exit;
        }
        
        if ($action === 'delete') {
            $id = intval($data['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid ID.']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM groceries WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'Item removed from pantry.']);
            exit;
        }
        
        if ($action === 'import') {
            $items = $data['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No items provided for import.']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            $checkStmt = $pdo->prepare("SELECT id, quantity, price FROM groceries WHERE user_id = ? AND name = ? AND unit = ?");
            $updateStmt = $pdo->prepare("UPDATE groceries SET quantity = ?, price = ?, expiry_date = COALESCE(?, expiry_date) WHERE id = ?");
            $insertStmt = $pdo->prepare("INSERT INTO groceries (user_id, name, quantity, unit, category, price, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $importedCount = 0;
            foreach ($items as $item) {
                $name = trim(strtolower($item['name'] ?? ''));
                $quantity = floatval($item['quantity'] ?? 0);
                $unit = trim($item['unit'] ?? 'pcs');
                $category = trim($item['category'] ?? 'Pantry');
                $price = floatval($item['price'] ?? 0.0);
                $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : date('Y-m-d', strtotime('+7 days'));
                
                if (empty($name) || $quantity <= 0) {
                    continue;
                }
                
                $checkStmt->execute([$userId, $name, $unit]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    $newQty = $existing['quantity'] + $quantity;
                    $newPrice = $existing['price'] + $price;
                    $updateStmt->execute([$newQty, $newPrice, $expiry_date, $existing['id']]);
                } else {
                    $insertStmt->execute([$userId, $name, $quantity, $unit, $category, $price, $expiry_date]);
                }
                $importedCount++;
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Successfully imported $importedCount items to pantry."]);
            exit;
        }
    }
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid endpoint query.']);
?>
