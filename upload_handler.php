<?php
// cooking-todo-app/upload_handler.php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$userId = $_SESSION['user_id'];

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Regex parsing helper for receipt text lines
function parseReceiptText($text) {
    $lines = explode("\n", $text);
    $parsedItems = [];
    
    // Categories lookup helper
    $categories = [
        'vegetables' => ['avocado', 'garlic', 'cucumber', 'tomato', 'broccoli', 'asparagus', 'spinach', 'onion', 'carrot', 'peppers', 'pepper'],
        'proteins' => ['chicken', 'salmon', 'egg', 'eggs', 'tofu', 'beef', 'pork', 'turkey', 'shrimp', 'fish'],
        'dairy' => ['milk', 'cheese', 'feta', 'yogurt', 'butter', 'cream'],
        'grains' => ['oats', 'bread', 'quinoa', 'rice', 'pasta', 'flour', 'cereal', 'noodles'],
        'pantry' => ['oil', 'olive oil', 'sesame oil', 'honey', 'salt', 'pepper', 'sugar', 'sauce', 'soy sauce', 'vinegar', 'almonds', 'walnuts', 'chickpeas', 'beans']
    ];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip obvious header/footer lines
        if (preg_match('/(total|subtotal|tax|change|cash|card|date|receipt|store|welcome|thank)/i', $line)) {
            continue;
        }
        
        $name = '';
        $qty = 1.0;
        $unit = 'pcs';
        $price = 0.00;
        $category = 'Pantry';
        
        // 1. Try to extract price: look for $XX.XX or XX.XX at the end of the line
        if (preg_match('/\$?\s*([0-9]+\.[0-9]{2})/', $line, $priceMatches)) {
            $price = floatval($priceMatches[1]);
            // Remove the price part from line to parse other info
            $line = str_replace($priceMatches[0], '', $line);
        }
        
        // 2. Try to extract quantity and unit
        // Match patterns like "500g", "2.5 kg", "3 pc", "12 pcs", "1 bottle", "200 ml", "1 can"
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(g|kg|ml|l|pcs|pc|slices|can|cans|bottle|tbsp|tsp|oz)\b/i', $line, $qtyUnitMatches)) {
            $qty = floatval($qtyUnitMatches[1]);
            $unit = strtolower($qtyUnitMatches[2]);
            $line = str_replace($qtyUnitMatches[0], '', $line);
        } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s+(?!x\b)/i', $line, $qtyOnlyMatches)) {
            // Match plain starting numbers as quantity
            $qty = floatval($qtyOnlyMatches[1]);
            $line = preg_replace('/^[0-9]+(?:\.[0-9]+)?\s+/', '', $line);
        }
        
        // 3. Clean up the rest as ingredient name
        $name = trim(preg_replace('/[\*\-\+\=\#]/', '', $line));
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $name = strtolower($name);
        
        if (empty($name)) continue;
        
        // 4. Determine category based on keywords
        foreach ($categories as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($name, $kw) !== false) {
                    $category = ucfirst($cat);
                    break 2;
                }
            }
        }
        
        $parsedItems[] = [
            'name' => $name,
            'quantity' => $qty,
            'unit' => $unit,
            'category' => $category,
            'price' => $price,
            'expiry_date' => date('Y-m-d', strtotime('+7 days'))
        ];
    }
    
    return $parsedItems;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Case A: Raw text paste
    if (isset($_POST['receipt_text']) && !empty($_POST['receipt_text'])) {
        $parsed = parseReceiptText($_POST['receipt_text']);
        echo json_encode(['status' => 'success', 'data' => $parsed]);
        exit;
    }
    
    // Case B: File upload
    if (isset($_FILES['receipt_file'])) {
        $file = $_FILES['receipt_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'File upload error code: ' . $file['error']]);
            exit;
        }
        
        $fileName = $file['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // If it's a text file, read and parse
        if ($fileExt === 'txt') {
            $text = file_get_contents($file['tmp_name']);
            $parsed = parseReceiptText($text);
            echo json_encode(['status' => 'success', 'data' => $parsed]);
            exit;
        }
        
        // If it's an image, simulate/mock OCR scanner to provide a robust experience offline
        $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        if (in_array($fileExt, $allowedExts)) {
            $destName = 'receipt_' . time() . '_' . uniqid() . '.' . $fileExt;
            $destPath = $uploadDir . '/' . $destName;
            
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                // Return a convincing OCR mock extraction that matches our recipes!
                // This ensures the demo is highly functional and immediately usable.
                $mockExtracted = [
                    [
                        'name' => 'salmon fillet',
                        'quantity' => 400.0,
                        'unit' => 'g',
                        'category' => 'Proteins',
                        'price' => 19.00,
                        'expiry_date' => date('Y-m-d', strtotime('+3 days'))
                    ],
                    [
                        'name' => 'asparagus',
                        'quantity' => 300.0,
                        'unit' => 'g',
                        'category' => 'Vegetables',
                        'price' => 4.50,
                        'expiry_date' => date('Y-m-d', strtotime('+4 days'))
                    ],
                    [
                        'name' => 'chicken breast',
                        'quantity' => 500.0,
                        'unit' => 'g',
                        'category' => 'Proteins',
                        'price' => 6.20,
                        'expiry_date' => date('Y-m-d', strtotime('+5 days'))
                    ],
                    [
                        'name' => 'quinoa',
                        'quantity' => 500.0,
                        'unit' => 'g',
                        'category' => 'Grains',
                        'price' => 3.80,
                        'expiry_date' => date('Y-m-d', strtotime('+45 days'))
                    ],
                    [
                        'name' => 'broccoli',
                        'quantity' => 200.0,
                        'unit' => 'g',
                        'category' => 'Vegetables',
                        'price' => 2.00,
                        'expiry_date' => date('Y-m-d', strtotime('+5 days'))
                    ],
                    [
                        'name' => 'tofu',
                        'quantity' => 300.0,
                        'unit' => 'g',
                        'category' => 'Proteins',
                        'price' => 2.80,
                        'expiry_date' => date('Y-m-d', strtotime('+6 days'))
                    ],
                    [
                        'name' => 'carrot',
                        'quantity' => 3.0,
                        'unit' => 'pc',
                        'category' => 'Vegetables',
                        'price' => 1.00,
                        'expiry_date' => date('Y-m-d', strtotime('+12 days'))
                    ],
                    [
                        'name' => 'bell pepper',
                        'quantity' => 3.0,
                        'unit' => 'pc',
                        'category' => 'Vegetables',
                        'price' => 2.50,
                        'expiry_date' => date('Y-m-d', strtotime('+8 days'))
                    ],
                    [
                        'name' => 'brown rice',
                        'quantity' => 1000.0,
                        'unit' => 'g',
                        'category' => 'Grains',
                        'price' => 3.00,
                        'expiry_date' => date('Y-m-d', strtotime('+60 days'))
                    ],
                    [
                        'name' => 'soy sauce',
                        'quantity' => 1.0,
                        'unit' => 'pc',
                        'category' => 'Pantry',
                        'price' => 1.80,
                        'expiry_date' => date('Y-m-d', strtotime('+90 days'))
                    ]
                ];
                
                echo json_encode([
                    'status' => 'success',
                    'image_url' => 'uploads/' . $destName,
                    'data' => $mockExtracted,
                    'message' => 'Simulated receipt OCR scan completed successfully.'
                ]);
                exit;
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
                exit;
            }
        }
        
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid file format. Upload a .txt file, or PNG/JPG image.']);
        exit;
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
?>
